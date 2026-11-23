<?php
/**
 * save_payment.php
 * Handles REAL payments only (cash OR cheque).
 * Emergency credit is NOT handled here — use save_emergency_credit.php instead.
 *
 * Cash  → row in invoice_payments (payment_method='cash')
 * Cheque→ row in invoice_payments (payment_method='cheque')
 *          + one row per cheque leaf in invoice_payment_cheques
 *            (upsert on cheque_no: duplicate → adds to total_amount)
 *
 * Returns: {success, new_paid, new_balance, invoice_amt, amount_saved, payment_id, overpayment}
 *
 * v3 OVERPAYMENTS (Payment Settings → "Allow overpayment", payment_settings.allow_overpayment):
 *   - What is still owed = the Invoice List balance of the invoice (report_extras_lib.php
 *     rx_invoice_due: Ikea value + return charges ± adjustments − payments + returned cheques − credit notes).
 *   - A payment bigger than that is an overpayment. Not allowed → refused with the amount
 *     ("Overpayment of Rs. X is not allowed"). Allowed → saved, and the reply has overpayment = X.
 *   - Returned / sent-back cheque settlements: checked against what is still to be settled on
 *     THAT cheque (cheque − settled − credit notes); return charge settlements against the charge.
 *   - Cheque settlements are always shared over the cheque's own invoices, in the cheque's order,
 *     continuing where the last settlement stopped (also when the cheque paid one invoice only).
 */
include 'config.php';
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'error'=>'POST only']); exit;
}

/* ═══════════ ENSURE TABLES ═══════════ */
mysqli_query($conn,"CREATE TABLE IF NOT EXISTS invoice_payments (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  field_summary_id        INT           NOT NULL,
  field_summary_detail_id INT           NOT NULL,
  t_code                  VARCHAR(50)   NULL,
  invoice_num             VARCHAR(100)  NULL,
  payment_method          VARCHAR(20)   NOT NULL DEFAULT 'cash',
  payment_date            DATE          NULL,
  amount                  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount_to_bank          DECIMAL(12,2) DEFAULT 0.00,
  reference_no            VARCHAR(100)  NULL,
  collected_by            VARCHAR(50)   NULL,
  cheque_mode             VARCHAR(50)   NULL,
  remarks                 TEXT          NULL,
  payment_source          VARCHAR(30)   NOT NULL DEFAULT 'invoice',
  delivery_person         VARCHAR(150)  NULL,
  employee_id             INT           NULL,
  sr_code                 VARCHAR(50)   NULL,
  created_at              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  is_reversed             TINYINT(1)    NOT NULL DEFAULT 0,
  reversed_at             DATETIME      NULL,
  INDEX idx_fs  (field_summary_id),
  INDEX idx_det (field_summary_detail_id),
  INDEX idx_inv (invoice_num),
  INDEX idx_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Ensure payment_source column exists (for existing installs) ── */
$_ps_chk = mysqli_query($conn, "SHOW COLUMNS FROM invoice_payments LIKE 'payment_source'");
if (!$_ps_chk || mysqli_num_rows($_ps_chk) === 0) {
    mysqli_query($conn, "ALTER TABLE invoice_payments ADD COLUMN payment_source VARCHAR(30) NOT NULL DEFAULT 'invoice' AFTER remarks");
}

/* ── Ensure delivery_person column exists (for existing installs) ── */
$_dp_chk = mysqli_query($conn, "SHOW COLUMNS FROM invoice_payments LIKE 'delivery_person'");
if (!$_dp_chk || mysqli_num_rows($_dp_chk) === 0) {
    mysqli_query($conn, "ALTER TABLE invoice_payments ADD COLUMN delivery_person VARCHAR(150) NULL AFTER payment_source");
}

/* ── Ensure employee_id column exists (for existing installs) ── */
$_eid_chk = mysqli_query($conn, "SHOW COLUMNS FROM invoice_payments LIKE 'employee_id'");
if (!$_eid_chk || mysqli_num_rows($_eid_chk) === 0) {
    mysqli_query($conn, "ALTER TABLE invoice_payments ADD COLUMN employee_id INT NULL AFTER delivery_person, ADD INDEX idx_emp (employee_id)");
}

/* ── Ensure sr_code column exists (collector type: SR) ── */
$_src_chk = mysqli_query($conn, "SHOW COLUMNS FROM invoice_payments LIKE 'sr_code'");
if (!$_src_chk || mysqli_num_rows($_src_chk) === 0) {
    mysqli_query($conn, "ALTER TABLE invoice_payments ADD COLUMN sr_code VARCHAR(50) NULL AFTER employee_id");
}

/* invoice_payment_cheques — original table name, one row per physical cheque leaf */
mysqli_query($conn,"CREATE TABLE IF NOT EXISTS invoice_payment_cheques (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  invoice_payment_id INT           NOT NULL,
  field_summary_id   INT           NULL,
  invoice_num        VARCHAR(100)  NULL,
  t_code             VARCHAR(50)   NULL,
  cheque_no          VARCHAR(100)  NOT NULL,
  cheque_date        DATE          NULL,
  amount             DECIMAL(12,2) DEFAULT 0.00,
  total_amount       DECIMAL(12,2) DEFAULT 0.00,
  bank_code          VARCHAR(50)   NULL,
  bank_name          VARCHAR(150)  NULL,
  branch_code        VARCHAR(50)   NULL,
  branch_name        VARCHAR(150)  NULL,
  status             VARCHAR(30)   DEFAULT 'pending',
  created_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  is_reversed        TINYINT(1)    NOT NULL DEFAULT 0,
  INDEX idx_pid (invoice_payment_id),
  INDEX idx_cno (cheque_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* cheques — master cheque register
   ONE row per (cheque_no + bank_code + branch_code) across ALL invoices.
   total_amount accumulates every time the same physical cheque is used again.
   FK → invoice_payments.id of the FIRST payment that introduced this cheque. */
mysqli_query($conn,"CREATE TABLE IF NOT EXISTS cheques (
  id                 INT           AUTO_INCREMENT PRIMARY KEY,
  invoice_payment_id INT           NOT NULL,
  field_summary_id   INT           NULL,
  t_code             VARCHAR(50)   NULL,
  cheque_no          VARCHAR(100)  NOT NULL,
  cheque_date        DATE          NULL,
  amount             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_amount       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  bank_code          VARCHAR(50)   NULL,
  bank_name          VARCHAR(150)  NULL,
  branch_code        VARCHAR(50)   NULL,
  branch_name        VARCHAR(150)  NULL,
  status             VARCHAR(30)   NOT NULL DEFAULT 'pending',
  created_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  received_date      DATE          NULL,
  due_date           DATE          NULL,
  bulk_deposit       VARCHAR(200)  NULL,
  acc_holder_name    VARCHAR(200)  NULL,
  bulk_flag          INT           NULL,
  UNIQUE KEY uq_cheque_bank_branch (cheque_no, bank_code, branch_code),
  INDEX idx_pid  (invoice_payment_id),
  INDEX idx_cno  (cheque_no),
  INDEX idx_bank (bank_code, branch_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ═══════════ HELPERS ═══════════ */
function esc($c,$v){ return mysqli_real_escape_string($c, trim((string)($v??''))); }
function safeDate($v){ $s=trim((string)($v??'')); return preg_match('/^\d{4}-\d{2}-\d{2}$/',$s)?$s:date('Y-m-d'); }

/* ═══════════ INPUTS ═══════════ */
$fsid    = intval($_POST['field_summary_id']             ?? 0);
$detid   = intval($_POST['field_summary_detail_id']      ?? 0);
$tcode   = esc($conn, $_POST['t_code']                  ?? '');
$invnum  = esc($conn, $_POST['invoice_num']             ?? '');
$method  = in_array($_POST['payment_method']??'',['cash','cheque'],true)
           ? $_POST['payment_method'] : 'cash';
$paydate = safeDate($_POST['payment_date']              ?? '');
$amount  = round(abs(floatval($_POST['amount']          ?? 0)), 2);
$tobank  = round(abs(floatval($_POST['amount_to_bank']  ?? 0)), 2);
$ref     = esc($conn, $_POST['reference_no']            ?? '');
$collby  = esc($conn, $_POST['collected_by']            ?? 'cc');
$chqmode = esc($conn, $_POST['cheque_mode']             ?? '');
$remarks = esc($conn, $_POST['remarks']                 ?? '');
$paysrc  = in_array($_POST['payment_source']??'',['invoice','return_cheque_settlement','return_charge_settlement','sentback_cheque_settlement','credit_sale','credit_sales'],true)
           ? $_POST['payment_source'] : 'invoice';
$has_emg_flag    = intval($_POST['has_emergency_credit']  ?? 0);
$delivery_person = esc($conn, $_POST['delivery_person']  ?? '');
$sr_code         = esc($conn, $_POST['sr_code']          ?? '');

/* employee_id — must be a positive integer referencing employees.id, or NULL */
$raw_emp_id = intval($_POST['employee_id'] ?? 0);
$employee_id = $raw_emp_id > 0 ? $raw_emp_id : null;

/* Validate employee_id exists in employees table (if provided) */
if ($employee_id !== null) {
    $emp_chk = mysqli_query($conn, "SELECT id FROM employees WHERE id = $employee_id LIMIT 1");
    if (!$emp_chk || mysqli_num_rows($emp_chk) === 0) {
        echo json_encode(['success'=>false,'error'=>'Invalid employee_id: employee not found']); exit;
    }
}

/* combined_total: sum of cash + cheque when both are submitted together.
   Lets the partial-payment check evaluate the full intended payment, not just
   this single method slice. Falls back to $amount if not supplied. */
$combined_total = round(abs(floatval($_POST['combined_total'] ?? 0)), 2);

/* verified field summary → no payments from the edit page */
if (($_POST['from_page'] ?? '') === 'edit_field_summary') { include_once 'fs_verify_lib.php'; fsv_guard($conn, [$fsid], [$detid]); }

/* ═══════════ VALIDATE ═══════════ */
if (!$fsid || !$detid) {
    echo json_encode(['success'=>false,'error'=>'field_summary_id or detail_id missing']); exit;
}
if ($amount <= 0) {
    echo json_encode(['success'=>false,'error'=>'Amount must be > 0']); exit;
}

/* ═══════════ WHAT IS STILL OWED (same as the Invoice List balance) ═══════════ */
include_once __DIR__ . '/report_extras_lib.php';
rx_ensure($conn);
$ALLOW_OVERPAY = rx_allow_overpay($conn);
$due_before = rx_invoice_due($conn, $detid);          /* negative = already overpaid */
$remaining  = max(0.00, $due_before);
/* invoice amount for the reply: Ikea value (else net) + return charges ± adjustments */
$_P = rx_inv_parts($conn, null, "= $detid");
$_r = rx_q($conn, "SELECT {$_P['net']} + {$_P['charge']} + {$_P['adj']} FROM field_summary_details fsd LEFT JOIN field_summary fs ON fs.id = fsd.field_summary_id {$_P['joins']} WHERE fsd.id = $detid LIMIT 1");
$inv_amt = ($_r && ($_x = mysqli_fetch_row($_r))) ? round((float)$_x[0], 2) : 0.0;
/* Edit Field Summary pays against the invoice's FINAL B.V (adjust_net_value), not the Ikea value: it sends balance_basis=final_bv.
   The same balance is kept (return charges ± adjustments − payments …), only the invoice value it starts from is the Final B.V.
   Every other page keeps the Invoice List balance (Ikea value). */
$fbv_shift = 0.0;
if (($_POST['balance_basis'] ?? '') === 'final_bv') {
    $_r2 = rx_q($conn, "SELECT {$_P['net']}, COALESCE(fsd.adjust_net_value, 0) FROM field_summary_details fsd LEFT JOIN field_summary fs ON fs.id = fsd.field_summary_id {$_P['joins']} WHERE fsd.id = $detid LIMIT 1");
    if ($_r2 && ($_y = mysqli_fetch_row($_r2))) $fbv_shift = round((float)$_y[1] - (float)$_y[0], 2);
    $due_before = round($due_before + $fbv_shift, 2);
    $remaining  = max(0.00, $due_before);
    $inv_amt    = round($inv_amt + $fbv_shift, 2);
}

/* ═══════════ OVERPAYMENT CHECK ═══════════
   cash + cheque together: the page sends cash first (with combined_total = cash + cheque),
   so the cash call checks the whole round; the cheque call then sees the balance already
   reduced by the cash and checks only its own amount. */
$settle_cid = intval($_POST['settle_cheque_id'] ?? 0);
$overpayment = 0.0; $op_against = 'invoice';
if (in_array($paysrc, ['return_cheque_settlement', 'sentback_cheque_settlement'], true) && $settle_cid > 0) {
    /* settlement of a returned / sent-back cheque: against what is still to be settled on THAT cheque */
    $chq_due = rx_cheque_due($conn, $settle_cid, $paysrc === 'sentback_cheque_settlement' ? 'sb' : 'return');
    $chq_due = round($chq_due - round(abs(floatval($_POST['settle_already'] ?? 0)), 2), 2);
    $overpayment = round($amount - max(0, $chq_due), 2); $op_against = 'cheque'; $op_due = max(0, $chq_due);
} elseif ($paysrc === 'return_charge_settlement' && intval($_POST['rc_id'] ?? 0) > 0) {
    $rc_due = rx_rc_due($conn, intval($_POST['rc_id']));
    $overpayment = round($amount - max(0, $rc_due), 2); $op_against = 'charge'; $op_due = max(0, $rc_due);
} elseif (!in_array($paysrc, ['return_cheque_settlement', 'sentback_cheque_settlement', 'return_charge_settlement'], true)) {
    $incoming = ($method === 'cash' && $combined_total > $amount) ? $combined_total : $amount;
    $overpayment = round($incoming - $remaining, 2); $op_due = $remaining;
}
if ($overpayment < 0.01) $overpayment = 0.0;
if ($overpayment > 0 && !$ALLOW_OVERPAY) {
    $what = ['invoice' => 'the balance of this invoice', 'cheque' => 'what is still to be settled on this cheque', 'charge' => 'what is still owed on this return charge'][$op_against];
    echo json_encode(['success' => false, 'overpayment' => $overpayment, 'balance' => $op_due, 'overpay_blocked' => true,
                      'error' => 'Overpayment of Rs. ' . number_format($overpayment, 2) . ' is not allowed — ' . $what . ' is Rs. ' . number_format($op_due, 2)
                               . '. Reduce the amount (or turn on "Allow overpayment" in Payment Settings).']);
    exit;
}

/* ═══════════ NON-CREDIT CUSTOMER PARTIAL PAYMENT CHECK ═══════════
   Rule: If the customer is NOT a credit customer (payment_mode != 'credit')
         AND this payment does not cover the full remaining balance (partial),
         AND no Emergency Credit exists in DB AND none is being submitted now
         → block the save.
   IMPORTANT: When cash + cheque are submitted together, $combined_total holds
   the real total the customer is paying. We compare that against $remaining
   so neither the cash slice nor the cheque slice incorrectly triggers the block.
═══════════════════════════════════════════════════════════════════ */
$effective_payment = $combined_total > 0 ? $combined_total : $amount;
/* credit_sales / settlement pages: partial payment is always allowed — skip block */
$_bypass_sources = ['credit_sales','credit_sale','return_cheque_settlement','return_charge_settlement','sentback_cheque_settlement'];
if (!in_array($paysrc, $_bypass_sources) && $effective_payment < $remaining) {
    $cm_r    = mysqli_query($conn,
        "SELECT c.payment_mode
         FROM field_summary_details d
         LEFT JOIN customers c ON c.t_code = d.t_code
         WHERE d.id = $detid LIMIT 1");
    $cm_row  = $cm_r ? mysqli_fetch_assoc($cm_r) : null;
    $cust_pm = strtolower(trim($cm_row['payment_mode'] ?? ''));

    if ($cust_pm !== 'credit') {
        /* Allow if: emergency credit already in DB, OR flag sent with this request */
        $ecr     = mysqli_query($conn,
            "SELECT id FROM credit_requests
             WHERE field_summary_detail_id = $detid LIMIT 1");
        $has_emg = ($ecr && mysqli_fetch_assoc($ecr)) ? true : ($has_emg_flag === 1);

        if (!$has_emg) {
            echo json_encode([
                'success' => false,
                'error'   => 'Cannot process a partial payment for a non-credit customer. '
                           . 'Please enable Emergency Credit for the remaining balance, then save.'
            ]);
            exit;
        }
    }
}

/* ═══════════ SPLIT A CHEQUE SETTLEMENT LIKE THE CHEQUE WAS SPLIT ═══════════
   A returned / sent-back cheque settlement is shared between the SAME invoices the
   cheque was paying, each up to the SAME amount it had on the cheque (in the cheque's
   own order). Settlement lines already saved for this cheque fill the first part of
   that order, so every new payment continues where the last one stopped.
   e.g. cheque 69,940 = 11,635 (A) + 43,530 (B) + 14,775 (C)
        pay 55,225 → A 11,635 + B 43,530 + C 60;  then pay 14,715 → C 14,715.
   Needs settle_cheque_id from the settlement page; without it → old behaviour. */
$parts = [['fsid' => $fsid, 'detid' => $detid, 'tcode' => $tcode, 'invnum' => $invnum, 'amount' => $amount]];
if ($settle_cid > 0 && in_array($paysrc, ['return_cheque_settlement', 'sentback_cheque_settlement'], true)) {
    $cq = mysqli_query($conn, "SELECT cheque_no, bank_code, branch_code FROM cheques WHERE id = $settle_cid LIMIT 1");
    $ch = $cq ? mysqli_fetch_assoc($cq) : null;
    if ($ch) {
        $cno = esc($conn, $ch['cheque_no']); $bk = esc($conn, $ch['bank_code']); $br = esc($conn, $ch['branch_code']);
        $_rv = mysqli_query($conn, "SHOW COLUMNS FROM invoice_payments LIKE 'is_reversed'");
        $rev = ($_rv && mysqli_num_rows($_rv)) ? 'AND ip.is_reversed = 0' : '';
        $lr = mysqli_query($conn, "
            SELECT ip.field_summary_id, ip.field_summary_detail_id,
                   COALESCE(NULLIF(ip.t_code,''), ipc.t_code) AS t_code,
                   COALESCE(NULLIF(fsd.invoice_num,''), NULLIF(ipc.invoice_num,''), ip.invoice_num) AS invoice_num,
                   SUM(ipc.amount) AS share, MIN(ipc.id) AS ord
            FROM invoice_payment_cheques ipc
            JOIN invoice_payments ip ON ip.id = ipc.invoice_payment_id $rev
            LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
            WHERE ipc.cheque_no = '$cno' AND ipc.bank_code = '$bk' AND ipc.branch_code = '$br'
              AND ip.payment_source NOT IN ('return_cheque_settlement','sentback_cheque_settlement','return_charge_settlement')
              AND ip.field_summary_detail_id > 0
            GROUP BY ip.field_summary_detail_id
            ORDER BY ord");
        $links = [];
        if ($lr) while ($l = mysqli_fetch_assoc($lr)) if ((float)$l['share'] > 0) $links[] = $l;
        if (count($links) >= 1) {   /* also a cheque that paid ONE invoice: the settlement goes back to that invoice */
            $reg_t = $paysrc === 'return_cheque_settlement' ? 'cheque_settlement_payments' : 'cheque_sb_settlement_payments';
            $done = 0.0;
            $rr = @mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) FROM $reg_t WHERE cheque_id = $settle_cid");
            if ($rr) $done = (float)mysqli_fetch_row($rr)[0];
            /* cash + cheque saved together: the cash part is posted but its settlement line
               may not be saved yet when the cheque part arrives → count it too */
            $done += round(abs(floatval($_POST['settle_already'] ?? 0)), 2);
            $from = $done; $to = $done + $amount; $cum = 0.0; $parts = [];
            foreach ($links as $l) {
                $lo = $cum; $hi = $cum + (float)$l['share']; $cum = $hi;
                $take = round(min($hi, $to) - max($lo, $from), 2);
                if ($take > 0.009) $parts[] = ['fsid' => (int)$l['field_summary_id'], 'detid' => (int)$l['field_summary_detail_id'],
                                               'tcode' => esc($conn, $l['t_code']), 'invnum' => esc($conn, $l['invoice_num']), 'amount' => $take];
            }
            $left = round($amount - array_sum(array_column($parts, 'amount')), 2);
            if ($left > 0.009) {   /* more than the cheque → rest on the last invoice it reaches (or the cheque's first one) */
                if ($parts) $parts[count($parts) - 1]['amount'] = round($parts[count($parts) - 1]['amount'] + $left, 2);
                else $parts[] = ['fsid' => (int)$links[0]['field_summary_id'], 'detid' => (int)$links[0]['field_summary_detail_id'],
                                 'tcode' => esc($conn, $links[0]['t_code']), 'invnum' => esc($conn, $links[0]['invoice_num']), 'amount' => $left];
            }
        }
    }
}

/* ═══════════ INSERT PAYMENT ROW(S) ═══════════ */
$emp_id_sql = $employee_id !== null ? $employee_id : 'NULL';
$payment_ids = [];
foreach ($parts as $k => $pt) {
    $sql = "INSERT INTO invoice_payments
      (field_summary_id, field_summary_detail_id, t_code, invoice_num,
       payment_method, payment_date, amount, amount_to_bank,
       reference_no, collected_by, cheque_mode, remarks, payment_source, delivery_person, employee_id, sr_code)
      VALUES
      ({$pt['fsid']}, {$pt['detid']}, '{$pt['tcode']}', '{$pt['invnum']}',
       '$method', '$paydate', {$pt['amount']}, " . ($k === 0 ? $tobank : 0) . ",
       '$ref', '$collby', '$chqmode', '$remarks', '$paysrc', '$delivery_person', $emp_id_sql, '$sr_code')";
    if (!mysqli_query($conn,$sql)) {
        echo json_encode(['success'=>false,'error'=>'DB insert: '.mysqli_error($conn)]); exit;
    }
    $payment_ids[$k] = mysqli_insert_id($conn);
    if ($k > 0) mysqli_query($conn, "UPDATE field_summary_details SET `updated`=1 WHERE id=" . (int)$pt['detid']);
}
$payment_id = $payment_ids[0];

/* ═══════════════════════════════════════════════════════════════
   CHEQUE ROWS
   ─ invoice_payment_cheques : a row per invoice payment the leaf pays
                               (a leaf is shared over the split parts in order)
   ─ cheques (master)        : one row per cheque_no+bank+branch;
                               INSERT on first use, UPDATE total_amount on repeat
═══════════════════════════════════════════════════════════════ */
if ($method === 'cheque' && !empty($_POST['cheques']) && is_array($_POST['cheques'])) {
    $room = array_column($parts, 'amount');   /* what each part can still take from the leaves */
    $pi = 0;
    foreach ($_POST['cheques'] as $q) {
        $cno    = esc($conn, $q['cheque_no']   ?? '');
        $cdate  = safeDate($q['cheque_date']   ?? '');
        $camt   = round(abs(floatval($q['amount']??0)), 2);
        $bkcode = esc($conn, $q['bank_code']   ?? '');
        $bkname = esc($conn, $q['bank_name']   ?? '');
        $brcode = esc($conn, $q['branch_code'] ?? '');
        $brname = esc($conn, $q['branch_name'] ?? '');
        if ($cno === '' || $camt <= 0) continue;

        /* ── 1. invoice_payment_cheques: one row per part this leaf pays ── */
        $leaf_left = $camt;
        while ($leaf_left > 0.009) {
            $last = $pi >= count($parts) - 1;
            $piece = $last ? $leaf_left : round(min($leaf_left, $room[$pi]), 2);
            if ($piece > 0.009) {
                $pt = $parts[$pi];
                mysqli_query($conn,
                    "INSERT INTO invoice_payment_cheques
                       (invoice_payment_id, field_summary_id, invoice_num, t_code,
                        cheque_no, cheque_date, amount, total_amount,
                        bank_code, bank_name, branch_code, branch_name)
                     VALUES
                       ({$payment_ids[$pi]}, {$pt['fsid']}, '{$pt['invnum']}', '{$pt['tcode']}',
                        '$cno', '$cdate', $piece, $piece,
                        '$bkcode', '$bkname', '$brcode', '$brname')");
                $room[$pi] = round($room[$pi] - $piece, 2); $leaf_left = round($leaf_left - $piece, 2);
            }
            if (!$last && $room[$pi] <= 0.009) $pi++;
            elseif ($last) break;
        }

        /* ── 2. cheques master: upsert on cheque_no + bank_code + branch_code ── */
        $cr = mysqli_query($conn,
            "SELECT id, total_amount
             FROM cheques
             WHERE cheque_no   = '$cno'
               AND bank_code   = '$bkcode'
               AND branch_code = '$brcode'
             LIMIT 1");
        $cx = $cr ? mysqli_fetch_assoc($cr) : null;

        if ($cx) {
            $new_total = round(floatval($cx['total_amount']) + $camt, 2);
            mysqli_query($conn,
                "UPDATE cheques SET
                    total_amount = $new_total,
                    amount       = $camt,
                    cheque_date  = '$cdate',
                    bank_name    = '$bkname',
                    branch_name  = '$brname',
                    updated_at   = NOW()
                 WHERE id = " . intval($cx['id']));
        } else {
            mysqli_query($conn,
                "INSERT INTO cheques
                   (invoice_payment_id, field_summary_id, t_code,
                    cheque_no, cheque_date, amount, total_amount,
                    bank_code, bank_name, branch_code, branch_name, received_date, cheque_mode)
                 VALUES
                   ($payment_id, $fsid, '$tcode',
                    '$cno', '$cdate', $camt, $camt,
                    '$bkcode', '$bkname', '$brcode', '$brname', '$paydate', '$chqmode')");
        }
    }
}

/* ═══════════════════════════════════════════════════════════════
   RETURN CHARGE SETTLEMENT MIRROR
   When this payment settles a cheque return charge, also record it in
   rc_settlement_payments (the return charges settlement table), including
   the delivery person / SR rep — same as return_charges.php does.
   Runs ONLY when payment_source = 'return_charge_settlement' AND an rc_id
   is supplied; otherwise this whole block is skipped and nothing else
   (normal invoice payments) is affected.
═══════════════════════════════════════════════════════════════ */
$rc_id = intval($_POST['rc_id'] ?? 0);
if ($paysrc === 'return_charge_settlement' && $rc_id > 0) {

    /* ensure return charges settlement table */
    mysqli_query($conn,"CREATE TABLE IF NOT EXISTS rc_settlement_payments (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        rc_id          INT NOT NULL,
        payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
        payment_date   DATE NULL,
        amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        reference_no   VARCHAR(100) DEFAULT NULL,
        remarks        TEXT DEFAULT NULL,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by     VARCHAR(100) DEFAULT 'system',
        INDEX idx_rcsp_rid (rc_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* ensure cheque-detail + collector/rep columns exist */
    foreach ([
        'cheque_no'       => "VARCHAR(100) DEFAULT NULL",
        'bank_name'       => "VARCHAR(100) DEFAULT NULL",
        'bank_code'       => "VARCHAR(50)  DEFAULT NULL",
        'branch_name'     => "VARCHAR(100) DEFAULT NULL",
        'cheque_date'     => "DATE NULL",
        'collected_by'    => "VARCHAR(20)  DEFAULT NULL",
        'delivery_person' => "VARCHAR(150) DEFAULT NULL",
        'sr_code'         => "VARCHAR(50)  DEFAULT NULL",
        'employee_id'     => "INT          DEFAULT NULL",
    ] as $_rcol => $_rdef) {
        $_rchk = mysqli_query($conn, "SHOW COLUMNS FROM rc_settlement_payments LIKE '$_rcol'");
        if (!$_rchk || mysqli_num_rows($_rchk) === 0)
            mysqli_query($conn, "ALTER TABLE rc_settlement_payments ADD COLUMN $_rcol $_rdef");
    }
    unset($_rcol,$_rdef,$_rchk);

    /* ensure settlement state columns on cheque_return_charges */
    foreach (['rc_settled'=>'TINYINT(1) DEFAULT 0','rc_settlement_amount'=>'DECIMAL(12,2) DEFAULT 0.00','rc_settlement_date'=>'DATE NULL','rc_settlement_note'=>'TEXT NULL'] as $_sc=>$_sd) {
        $_scr = mysqli_query($conn,"SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cheque_return_charges' AND COLUMN_NAME='$_sc'");
        if (!$_scr || (int)mysqli_fetch_assoc($_scr)['n']===0) mysqli_query($conn,"ALTER TABLE cheque_return_charges ADD COLUMN $_sc $_sd");
    }
    unset($_sc,$_sd,$_scr);

    /* current user label (no session_start needed; reads session if already active) */
    $_sess = $_SESSION ?? [];
    $rc_cu = mysqli_real_escape_string($conn,
        $_sess['username'] ?? $_sess['user_name'] ?? $_sess['name']
        ?? $_sess['full_name'] ?? $_sess['email'] ?? 'system');

    /* collector / rep SQL values (source vars already escaped above) */
    $rc_collby_sql = $collby          !== '' ? "'$collby'"          : 'NULL';
    $rc_dp_sql     = $delivery_person !== '' ? "'$delivery_person'" : 'NULL';
    $rc_sr_sql     = $sr_code         !== '' ? "'$sr_code'"         : 'NULL';
    $rc_emp_sql    = ($employee_id !== null && $employee_id > 0) ? $employee_id : 'NULL';

    if ($method === 'cheque' && !empty($_POST['cheques']) && is_array($_POST['cheques'])) {
        /* one settlement row per cheque leaf */
        foreach ($_POST['cheques'] as $q) {
            $rc_cno  = esc($conn, $q['cheque_no']   ?? '');
            $rc_cdt  = safeDate($q['cheque_date']   ?? '');
            $rc_camt = round(abs(floatval($q['amount'] ?? 0)), 2);
            $rc_bkn  = esc($conn, $q['bank_name']   ?? '');
            $rc_bkc  = esc($conn, $q['bank_code']   ?? '');
            $rc_brn  = esc($conn, $q['branch_name'] ?? '');
            if ($rc_cno === '' || $rc_camt <= 0) continue;

            $rc_cno_sql = "'$rc_cno'";
            $rc_bkn_sql = $rc_bkn !== '' ? "'$rc_bkn'" : 'NULL';
            $rc_bkc_sql = $rc_bkc !== '' ? "'$rc_bkc'" : 'NULL';
            $rc_brn_sql = $rc_brn !== '' ? "'$rc_brn'" : 'NULL';

            mysqli_query($conn,"INSERT INTO rc_settlement_payments
                (rc_id, payment_method, payment_date, amount, reference_no, remarks,
                 cheque_no, bank_name, bank_code, branch_name, cheque_date,
                 collected_by, delivery_person, sr_code, employee_id, created_by)
                VALUES
                ($rc_id, 'cheque', '$paydate', $rc_camt, '$ref', '$remarks',
                 $rc_cno_sql, $rc_bkn_sql, $rc_bkc_sql, $rc_brn_sql, '$rc_cdt',
                 $rc_collby_sql, $rc_dp_sql, $rc_sr_sql, $rc_emp_sql, '$rc_cu')");
        }
    } else {
        /* cash settlement row */
        mysqli_query($conn,"INSERT INTO rc_settlement_payments
            (rc_id, payment_method, payment_date, amount, reference_no, remarks,
             collected_by, delivery_person, sr_code, employee_id, created_by)
            VALUES
            ($rc_id, '$method', '$paydate', $amount, '$ref', '$remarks',
             $rc_collby_sql, $rc_dp_sql, $rc_sr_sql, $rc_emp_sql, '$rc_cu')");
    }

    /* recalc settled status for this return charge */
    $rc_tr     = mysqli_query($conn,"SELECT COALESCE(SUM(amount),0) AS tot FROM rc_settlement_payments WHERE rc_id=$rc_id");
    $rc_total  = $rc_tr ? (float)mysqli_fetch_assoc($rc_tr)['tot'] : 0;
    $rc_cr     = mysqli_query($conn,"SELECT return_charge FROM cheque_return_charges WHERE id=$rc_id LIMIT 1");
    $rc_charge = $rc_cr ? (float)mysqli_fetch_assoc($rc_cr)['return_charge'] : 0;
    $rc_fully  = ($rc_total >= $rc_charge && $rc_charge > 0) ? 1 : 0;
    mysqli_query($conn,"UPDATE cheque_return_charges
        SET rc_settlement_amount=$rc_total, rc_settled=$rc_fully, rc_settlement_date='$paydate'
        WHERE id=$rc_id");
}

/* ═══════════ RETURN AUTHORITATIVE TOTALS ═══════════ */
/* Cash always counts. Cheque only counts if status='cleared' in cheques master table
   matched by cheque_no + bank_code + branch_code. */
$fr = mysqli_query($conn,
    "SELECT ip.id, ip.amount, ip.payment_method
     FROM invoice_payments ip WHERE ip.field_summary_detail_id=$detid");
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
                         WHERE cheque_no='$lcno' AND bank_code='$lbk' AND branch_code='$lbr'
                         LIMIT 1");
                    $srow = $sr ? mysqli_fetch_assoc($sr) : null;
                    if ($srow && strtolower(trim($srow['status'] ?? '')) === 'cleared') {
                        $new_paid += floatval($leaf['amount']);
                    }
                }
            }
        }
    }
}
$new_paid = round($new_paid, 2);
$due_after = rx_invoice_due($conn, $detid);   /* Invoice List balance after this payment (negative = overpaid) */
$due_after = round($due_after + $fbv_shift, 2);   /* Final B.V basis when asked (Edit Field Summary) */
$new_bal   = max(0.00, $due_after);

/* Mark detail row as updated */
mysqli_query($conn, "UPDATE field_summary_details SET `updated`=1 WHERE id=$detid");

echo json_encode([
    'success'         => true,
    'payment_id'      => $payment_id,
    'amount_saved'    => $amount,
    'new_paid'        => $new_paid,
    'new_balance'     => $new_bal,
    'invoice_amt'     => $inv_amt,
    'balance_signed'  => $due_after,
    'overpayment'     => $overpayment,              /* > 0 only when overpayments are allowed */
    'parts'           => array_map(function ($p) { return ['detail_id' => $p['detid'], 'invoice' => stripslashes($p['invnum']), 'amount' => $p['amount']]; }, $parts),
    'delivery_person' => $_POST['delivery_person'] ?? '',
    'employee_id'     => $employee_id,
    'sr_code'         => $_POST['sr_code'] ?? '',
]);