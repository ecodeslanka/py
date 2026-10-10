<?php
/**
 * update_payment.php
 * Called once per method slice (cash OR cheque) from the edit modal in payments.php.
 *
 * CHEQUE MASTER STRATEGY:
 *   For an existing leaf (leaf_id > 0):
 *     - Find the master `cheques` row by the OLD (cheque_no + bank_code + branch_code).
 *     - UPDATE it in place: cheque_no, cheque_date, amount, total_amount,
 *       bank_code, bank_name, branch_code, branch_name, received_date.
 *     - NEVER touch: status, bill_verified, acc_holder_name, due_date,
 *       images, or any other column not listed above.
 *     - NEVER delete and re-insert the master row — it carries status/images/verified.
 *
 *   For a removed leaf (old id not in incoming list):
 *     - Delete the leaf row from invoice_payment_cheques.
 *     - Reduce total_amount on the master. Hard-delete master ONLY if
 *       total_amount reaches zero AND no other leaves reference it.
 *
 *   For a brand-new leaf (leaf_id == 0):
 *     - Insert leaf row.
 *     - If a master row already exists for (cheque_no, bank_code, branch_code)
 *       → add amount to total_amount only (preserve everything else).
 *     - Otherwise → INSERT fresh master row.
 *
 * Returns: {success, new_amount, new_paid, new_balance, invoice_amt}
 */
include 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST only']); exit;
}

function esc($c, $v) { return mysqli_real_escape_string($c, trim((string)($v ?? ''))); }
function safeDate($v) { $s = trim((string)($v ?? '')); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : date('Y-m-d'); }

/* ══════════════════════════════════════════════════════════════════
   MAIN
══════════════════════════════════════════════════════════════════ */
$payment_id     = intval($_POST['payment_id']    ?? 0);
$submit_method  = trim($_POST['submit_method']   ?? '');
$combined_total = round(abs(floatval($_POST['combined_total'] ?? 0)), 2);

if (!$payment_id) { echo json_encode(['success' => false, 'error' => 'payment_id required']); exit; }
if (!in_array($submit_method, ['cash', 'cheque'])) {
    echo json_encode(['success' => false, 'error' => 'submit_method must be cash or cheque']); exit;
}

/* ── Load original payment ── */
$pr = mysqli_query($conn, "SELECT * FROM invoice_payments WHERE id=$payment_id AND is_reversed=0 LIMIT 1");
if (!$pr || mysqli_num_rows($pr) === 0) {
    echo json_encode(['success' => false, 'error' => 'Payment not found or already reversed']); exit;
}
$orig = mysqli_fetch_assoc($pr);

$orig_pm = $orig['payment_method'];
$detid   = intval($orig['field_summary_detail_id']);
$fsid    = intval($orig['field_summary_id']);
$tcode   = $orig['t_code'];
$invnum  = $orig['invoice_num'];

/* ── Scalar field values ── */
$new_amount  = round(abs(floatval($_POST['amount']         ?? 0)), 2);
$paydate     = safeDate($_POST['payment_date']             ?? '');   /* ← editable now */
$ref         = esc($conn, $_POST['reference_no']           ?? '');
$collby      = esc($conn, $_POST['collected_by']           ?? 'cc');
$chqmode     = esc($conn, $_POST['cheque_mode']            ?? '');
$remarks     = esc($conn, $_POST['remarks']                ?? '');
$amount_bank = round(abs(floatval($_POST['amount_to_bank'] ?? 0)), 2);

/* ── Collector Details (CC/SR toggle from payments.php edit modal) ── */
$delivery_person = esc($conn, $_POST['delivery_person'] ?? '');
$sr_code         = esc($conn, $_POST['sr_code']          ?? '');
$raw_emp_id       = intval($_POST['employee_id'] ?? 0);
$employee_id      = $raw_emp_id > 0 ? $raw_emp_id : null;

/* Validate employee_id exists in employees table (if provided) */
if ($employee_id !== null) {
    $emp_chk = mysqli_query($conn, "SELECT id FROM employees WHERE id = $employee_id LIMIT 1");
    if (!$emp_chk || mysqli_num_rows($emp_chk) === 0) {
        echo json_encode(['success'=>false,'error'=>'Invalid employee_id: employee not found']); exit;
    }
}

/* Collector Details are informational on edit — no hard requirement here.
   (save_payment.php still requires them for brand-new payments, where
   forcing a selection upfront makes sense.) */

if ($new_amount <= 0) { echo json_encode(['success' => false, 'error' => 'Amount must be > 0']); exit; }

/* ── Build incoming cheque list ── */
$new_cheques = [];
if ($submit_method === 'cheque') {
    $chq_total = 0.00;
    if (!empty($_POST['cheques']) && is_array($_POST['cheques'])) {
        foreach ($_POST['cheques'] as $q) {
            $leaf_id = intval($q['leaf_id']        ?? 0);
            $cno     = esc($conn, $q['cheque_no']  ?? '');
            $cdate   = safeDate($q['cheque_date']  ?? '');
            $camt    = round(abs(floatval($q['amount']       ?? 0)), 2);
            $bkcode  = esc($conn, $q['bank_code']  ?? '');
            $bkname  = esc($conn, $q['bank_name']  ?? '');
            $brcode  = esc($conn, $q['branch_code']?? '');
            $brname  = esc($conn, $q['branch_name']?? '');
            if ($cno === '' || $camt <= 0) continue;
            $chq_total += $camt;
            $new_cheques[] = compact('leaf_id','cno','cdate','camt','bkcode','bkname','brcode','brname');
        }
    }
    if ($chq_total > 0) $new_amount = round($chq_total, 2);
}

/* ── Load existing leaves keyed by leaf id ── */
$existing_leaves = [];
$switching_away  = ($orig_pm === 'cheque' && $submit_method === 'cash');

if ($submit_method === 'cheque' || $switching_away) {
    $old_r = mysqli_query($conn,
        "SELECT id, cheque_no, bank_code, branch_code, amount
         FROM invoice_payment_cheques WHERE invoice_payment_id=$payment_id");
    if ($old_r) {
        while ($leaf = mysqli_fetch_assoc($old_r)) {
            $existing_leaves[intval($leaf['id'])] = $leaf;
        }
    }
}

/* ══════════════════════════════════════════════════════════════════
   CHEQUE RECONCILIATION
══════════════════════════════════════════════════════════════════ */
if ($submit_method === 'cheque' || $switching_away) {

    $incoming_ids = array_column(
        array_filter($new_cheques, fn($q) => $q['leaf_id'] > 0),
        'leaf_id'
    );

    /* ── 1. REMOVE leaves that were deleted by the user ── */
    foreach ($existing_leaves as $eid => $leaf) {
        if ($switching_away || !in_array($eid, $incoming_ids)) {

            $e_cno = esc($conn, $leaf['cheque_no']);
            $e_bkc = esc($conn, $leaf['bank_code']);
            $e_brc = esc($conn, $leaf['branch_code']);
            $e_amt = round(floatval($leaf['amount']), 2);

            /* Find master by OLD identity */
            $mr   = mysqli_query($conn,
                "SELECT id, total_amount FROM cheques
                 WHERE cheque_no='$e_cno' AND bank_code='$e_bkc' AND branch_code='$e_brc' LIMIT 1");
            $mrow = $mr ? mysqli_fetch_assoc($mr) : null;
            if ($mrow) {
                $nt = round(floatval($mrow['total_amount']) - $e_amt, 2);
                if ($nt <= 0) {
                    /* No other leaves use this master — safe to delete */
                    mysqli_query($conn, "DELETE FROM cheques WHERE id=" . intval($mrow['id']));
                } else {
                    /* Other leaves still reference this master — only reduce total */
                    mysqli_query($conn,
                        "UPDATE cheques SET total_amount=$nt, updated_at=NOW()
                         WHERE id=" . intval($mrow['id']));
                }
            }

            /* Delete the leaf */
            mysqli_query($conn, "DELETE FROM invoice_payment_cheques WHERE id=$eid");
        }
    }

    /* ── 2. UPDATE existing leaves / INSERT new leaves ── */
    if ($submit_method === 'cheque') {
        foreach ($new_cheques as $q) {
            $leaf_id = $q['leaf_id'];
            $cno     = $q['cno'];     $cdate  = $q['cdate'];
            $camt    = $q['camt'];    $bkcode = $q['bkcode'];
            $bkname  = $q['bkname'];  $brcode = $q['brcode'];  $brname = $q['brname'];

            if ($leaf_id > 0 && isset($existing_leaves[$leaf_id])) {

                /* ══════════════════════════════════════════════════════
                   UPDATE EXISTING LEAF IN PLACE
                   ──────────────────────────────────────────────────────
                   Always find the master by the OLD identity (before any
                   changes), then UPDATE it in place with the new values.
                   This preserves: status, bill_verified, acc_holder_name,
                   due_date, images, and every other column untouched.
                   We NEVER delete and re-insert the master row.
                ══════════════════════════════════════════════════════ */
                $old     = $existing_leaves[$leaf_id];
                $old_cno = esc($conn, $old['cheque_no']);
                $old_bkc = esc($conn, $old['bank_code']);
                $old_brc = esc($conn, $old['branch_code']);
                $old_amt = round(floatval($old['amount']), 2);
                $amt_diff = round($camt - $old_amt, 2);

                /* Find the master row using the OLD cheque identity */
                $mr   = mysqli_query($conn,
                    "SELECT id, total_amount FROM cheques
                     WHERE cheque_no='$old_cno' AND bank_code='$old_bkc' AND branch_code='$old_brc' LIMIT 1");
                $mrow = $mr ? mysqli_fetch_assoc($mr) : null;

                if ($mrow) {
                    /*
                     * UPDATE the master in place — only the editable columns.
                     * cheque_no, bank_code, branch_code CAN change here (user
                     * corrected a wrong number or bank). The row keeps its id,
                     * status, bill_verified, acc_holder_name, due_date, images.
                     */
                    $new_total = round(floatval($mrow['total_amount']) + $amt_diff, 2);
                    $new_total = max(0, $new_total);
                    mysqli_query($conn,
                        "UPDATE cheques SET
                            cheque_no     = '$cno',
                            cheque_date   = '$cdate',
                            amount        = $camt,
                            total_amount  = $new_total,
                            bank_code     = '$bkcode',
                            bank_name     = '$bkname',
                            branch_code   = '$brcode',
                            branch_name   = '$brname',
                            received_date = '$paydate',
                            updated_at    = NOW()
                         WHERE id=" . intval($mrow['id']));
                } else {
                    /*
                     * Master row missing (edge case — data inconsistency).
                     * Insert a fresh master. No status/images to lose here.
                     */
                    mysqli_query($conn,
                        "INSERT INTO cheques
                           (invoice_payment_id, field_summary_id, t_code,
                            cheque_no, cheque_date, amount, total_amount,
                            bank_code, bank_name, branch_code, branch_name,
                            received_date, cheque_mode)
                         VALUES
                           ($payment_id, $fsid, '$tcode',
                            '$cno', '$cdate', $camt, $camt,
                            '$bkcode', '$bkname', '$brcode', '$brname',
                            '$paydate', '$chqmode')");
                }

                /* Update the leaf row with all new values */
                mysqli_query($conn,
                    "UPDATE invoice_payment_cheques SET
                        cheque_no    = '$cno',
                        cheque_date  = '$cdate',
                        amount       = $camt,
                        total_amount = $camt,
                        bank_code    = '$bkcode',
                        bank_name    = '$bkname',
                        branch_code  = '$brcode',
                        branch_name  = '$brname'
                     WHERE id=$leaf_id");

            } else {

                /* ══ INSERT brand new leaf ══ */
                mysqli_query($conn,
                    "INSERT INTO invoice_payment_cheques
                       (invoice_payment_id, field_summary_id, invoice_num, t_code,
                        cheque_no, cheque_date, amount, total_amount,
                        bank_code, bank_name, branch_code, branch_name)
                     VALUES
                       ($payment_id, $fsid, '$invnum', '$tcode',
                        '$cno', '$cdate', $camt, $camt,
                        '$bkcode', '$bkname', '$brcode', '$brname')");

                /* Upsert master — add to existing if present, else insert fresh */
                $cr = mysqli_query($conn,
                    "SELECT id, total_amount FROM cheques
                     WHERE cheque_no='$cno' AND bank_code='$bkcode' AND branch_code='$brcode' LIMIT 1");
                $cx = $cr ? mysqli_fetch_assoc($cr) : null;
                if ($cx) {
                    /* Master exists — only add the new amount, never touch other fields */
                    $nt = round(floatval($cx['total_amount']) + $camt, 2);
                    mysqli_query($conn,
                        "UPDATE cheques SET total_amount=$nt, received_date='$paydate', updated_at=NOW()
                         WHERE id=" . intval($cx['id']));
                } else {
                    /* Completely new cheque — insert fresh master */
                    mysqli_query($conn,
                        "INSERT INTO cheques
                           (invoice_payment_id, field_summary_id, t_code,
                            cheque_no, cheque_date, amount, total_amount,
                            bank_code, bank_name, branch_code, branch_name,
                            received_date, cheque_mode)
                         VALUES
                           ($payment_id, $fsid, '$tcode',
                            '$cno', '$cdate', $camt, $camt,
                            '$bkcode', '$bkname', '$brcode', '$brname',
                            '$paydate', '$chqmode')");
                }
            }
        }
    }
}

/* ══════════════════════════════════════════════════
   UPDATE invoice_payments header row
   ── payment_date is now editable ──
══════════════════════════════════════════════════ */
$pm_upd = mysqli_real_escape_string($conn, $submit_method);
$emp_id_sql = $employee_id !== null ? $employee_id : 'NULL';
if (!mysqli_query($conn,
    "UPDATE invoice_payments SET
        payment_method   = '$pm_upd',
        payment_date     = '$paydate',
        amount           = $new_amount,
        amount_to_bank   = $amount_bank,
        reference_no     = '$ref',
        collected_by     = '$collby',
        delivery_person  = '$delivery_person',
        sr_code          = '$sr_code',
        employee_id      = $emp_id_sql,
        cheque_mode      = '$chqmode',
        remarks          = '$remarks'
     WHERE id=$payment_id AND is_reversed=0")) {
    echo json_encode(['success' => false, 'error' => 'DB update: ' . mysqli_error($conn)]); exit;
}

/* ── Recalculate authoritative paid total ── */
$fr = mysqli_query($conn,
    "SELECT id, amount, payment_method
     FROM invoice_payments
     WHERE field_summary_detail_id=$detid AND is_reversed=0");
$new_paid = 0.00;
if ($fr) {
    while ($prow = mysqli_fetch_assoc($fr)) {
        if ($prow['payment_method'] === 'cash') {
            $new_paid += floatval($prow['amount']);
        } else {
            $pid   = intval($prow['id']);
            $leafr = mysqli_query($conn,
                "SELECT cheque_no, bank_code, branch_code, amount
                 FROM invoice_payment_cheques WHERE invoice_payment_id=$pid");
            if ($leafr) {
                while ($leaf = mysqli_fetch_assoc($leafr)) {
                    $lcno = mysqli_real_escape_string($conn, $leaf['cheque_no']);
                    $lbk  = mysqli_real_escape_string($conn, $leaf['bank_code']);
                    $lbr  = mysqli_real_escape_string($conn, $leaf['branch_code']);
                    $sr   = mysqli_query($conn,
                        "SELECT status FROM cheques
                         WHERE cheque_no='$lcno' AND bank_code='$lbk' AND branch_code='$lbr' LIMIT 1");
                    $srow = $sr ? mysqli_fetch_assoc($sr) : null;
                    if ($srow && strtolower(trim($srow['status'] ?? '')) === 'cleared')
                        $new_paid += floatval($leaf['amount']);
                }
            }
        }
    }
}
$new_paid = round($new_paid, 2);

$ir      = mysqli_query($conn, "SELECT adjust_net_value FROM field_summary_details WHERE id=$detid LIMIT 1");
$irow    = $ir ? mysqli_fetch_assoc($ir) : null;
$inv_amt = $irow ? round(floatval($irow['adjust_net_value']), 2) : 0.00;
$new_bal = max(0.00, round($inv_amt - $new_paid, 2));

/* employee_name is not stored on invoice_payments — derive via join for the response */
$employee_name_resp = '';
if ($employee_id !== null) {
    $emn_r = mysqli_query($conn,
        "SELECT COALESCE(NULLIF(name_with_initials,''),NULLIF(employee_full_name,''),CONCAT('Employee #',id)) AS nm
         FROM employees WHERE id=$employee_id LIMIT 1");
    if ($emn_r && ($emn_row = mysqli_fetch_assoc($emn_r))) $employee_name_resp = $emn_row['nm'];
}

echo json_encode([
    'success'         => true,
    'new_amount'      => $new_amount,
    'new_paid'        => $new_paid,
    'new_balance'     => $new_bal,
    'invoice_amt'     => $inv_amt,
    'collected_by'    => $collby,
    'delivery_person' => $delivery_person,
    'sr_code'         => $sr_code,
    'employee_id'     => $employee_id,
    'employee_name'   => $employee_name_resp,
]);