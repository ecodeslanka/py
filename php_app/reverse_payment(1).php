<?php
/**
 * reverse_payment.php
 * ─────────────────────────────────────────────────────────────────
 * BEHAVIOUR:
 *   1. Saves a FULL audit snapshot of the payment row AND all cheque
 *      leaf rows into payment_reversals + payment_reversal_cheques
 *      BEFORE touching anything.
 *   2. Hard-DELETEs the row from invoice_payments.
 *      invoice_payment_cheques cascade-deletes automatically via FK.
 *   3. Cleans up cheques master table:
 *      - deducts leaf amount from total_amount
 *      - if total_amount reaches 0 → hard-deletes master cheque row
 *   4. Returns JSON with updated running totals for the invoice.
 * ─────────────────────────────────────────────────────────────────
 */
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
include 'config.php';
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST only']); exit;
}

function esc($c, $v) { return mysqli_real_escape_string($c, trim((string)($v ?? ''))); }

/* ── INPUTS ── */
$payment_id  = intval($_POST['payment_id']   ?? 0);
$reason      = esc($conn, $_POST['reason']       ?? '');
$reversed_by = esc($conn, $_POST['reversed_by']  ?? 'operator');

if (!$payment_id) { echo json_encode(['success'=>false,'error'=>'payment_id required']); exit; }
if (!$reason)     { echo json_encode(['success'=>false,'error'=>'Reason is required']); exit; }

/* ══════════════════════════════════════════════════
   ENSURE AUDIT TABLES EXIST
══════════════════════════════════════════════════ */

/* Main audit table — full snapshot of the invoice_payments row */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS payment_reversals (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  original_payment_id     INT            NOT NULL COMMENT 'invoice_payments.id that was deleted',
  field_summary_id        INT            NOT NULL,
  field_summary_detail_id INT            NOT NULL,
  t_code                  VARCHAR(50)    NULL,
  invoice_num             VARCHAR(100)   NULL,
  payment_method          VARCHAR(20)    NULL,
  payment_date            DATE           NULL,
  amount                  DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  amount_to_bank          DECIMAL(12,2)           DEFAULT 0.00,
  reference_no            VARCHAR(100)   NULL,
  collected_by            VARCHAR(50)    NULL,
  cheque_mode             VARCHAR(50)    NULL,
  remarks                 TEXT           NULL,
  original_created_at     DATETIME       NULL     COMMENT 'When the original payment was recorded',
  reason                  TEXT           NOT NULL  COMMENT 'Why this payment was deleted',
  reversed_by             VARCHAR(100)   NULL,
  reversed_at             TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_opid (original_payment_id),
  INDEX idx_fsid (field_summary_id),
  INDEX idx_det  (field_summary_detail_id),
  INDEX idx_inv  (invoice_num),
  INDEX idx_rat  (reversed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Full audit log of deleted payments'");

/* Cheque snapshot table — one row per cheque leaf that belonged to the deleted payment */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS payment_reversal_cheques (
  id                           INT AUTO_INCREMENT PRIMARY KEY,
  payment_reversal_id          INT            NOT NULL,
  original_payment_id          INT            NOT NULL,
  original_cheque_leaf_id      INT            NULL     COMMENT 'invoice_payment_cheques.id',
  field_summary_id             INT            NULL,
  invoice_num                  VARCHAR(100)   NULL,
  t_code                       VARCHAR(50)    NULL,
  cheque_no                    VARCHAR(100)   NOT NULL,
  cheque_date                  DATE           NULL,
  amount                       DECIMAL(12,2)  DEFAULT 0.00,
  bank_code                    VARCHAR(50)    NULL,
  bank_name                    VARCHAR(150)   NULL,
  branch_code                  VARCHAR(50)    NULL,
  branch_name                  VARCHAR(150)   NULL,
  cheque_status_at_reversal    VARCHAR(30)    NULL     COMMENT 'Status in cheques master at time of deletion',
  FOREIGN KEY (payment_reversal_id)
    REFERENCES payment_reversals(id) ON DELETE CASCADE,
  INDEX idx_rid  (payment_reversal_id),
  INDEX idx_opid (original_payment_id),
  INDEX idx_cno  (cheque_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cheque details snapshot for deleted payments'");

/* ══════════════════════════════════════════════════
   FETCH ORIGINAL PAYMENT ROW
══════════════════════════════════════════════════ */
$pr = mysqli_query($conn, "SELECT * FROM invoice_payments WHERE id = $payment_id LIMIT 1");
if (!$pr || mysqli_num_rows($pr) === 0) {
    echo json_encode(['success'=>false,'error'=>'Payment record not found']); exit;
}
$pay   = mysqli_fetch_assoc($pr);
$fsid  = intval($pay['field_summary_id']);
$detid = intval($pay['field_summary_detail_id']);
$amt   = floatval($pay['amount']);
$meth  = $pay['payment_method'];

/* Fetch cheque leaf rows (with master status) BEFORE we delete anything */
$cheque_leaves = [];
if ($meth === 'cheque') {
    $lr = mysqli_query($conn,
        "SELECT ipc.*, c.status AS master_status
         FROM invoice_payment_cheques ipc
         LEFT JOIN cheques c
           ON  c.cheque_no   = ipc.cheque_no
           AND c.bank_code   = ipc.bank_code
           AND c.branch_code = ipc.branch_code
         WHERE ipc.invoice_payment_id = $payment_id");
    if ($lr) while ($leaf = mysqli_fetch_assoc($lr)) $cheque_leaves[] = $leaf;
}

/* ══════════════════════════════════════════════════
   TRANSACTION:  audit → adjust master → hard DELETE
══════════════════════════════════════════════════ */
mysqli_begin_transaction($conn);
try {

    /* ── STEP 1: Insert full payment snapshot into payment_reversals ── */
    $s_tc      = esc($conn, $pay['t_code']       ?? '');
    $s_inv     = esc($conn, $pay['invoice_num']   ?? '');
    $s_meth    = esc($conn, $meth);
    $s_pdate   = esc($conn, $pay['payment_date']  ?? '');
    $s_tobank  = floatval($pay['amount_to_bank']  ?? 0);
    $s_ref     = esc($conn, $pay['reference_no']  ?? '');
    $s_collby  = esc($conn, $pay['collected_by']  ?? '');
    $s_chqmode = esc($conn, $pay['cheque_mode']   ?? '');
    $s_remarks = esc($conn, $pay['remarks']       ?? '');
    $s_created = esc($conn, $pay['created_at']    ?? '');

    $pdate_sql   = $s_pdate   ? "'$s_pdate'"   : 'NULL';
    $created_sql = $s_created ? "'$s_created'" : 'NULL';

    $ins = "INSERT INTO payment_reversals
      (original_payment_id, field_summary_id, field_summary_detail_id,
       t_code, invoice_num, payment_method, payment_date,
       amount, amount_to_bank, reference_no, collected_by,
       cheque_mode, remarks, original_created_at,
       reason, reversed_by)
    VALUES
      ($payment_id, $fsid, $detid,
       '$s_tc', '$s_inv', '$s_meth', $pdate_sql,
       $amt, $s_tobank, '$s_ref', '$s_collby',
       '$s_chqmode', '$s_remarks', $created_sql,
       '$reason', '$reversed_by')";

    if (!mysqli_query($conn, $ins))
        throw new Exception('Audit log insert failed: ' . mysqli_error($conn));

    $reversal_id = mysqli_insert_id($conn);

    /* ── STEP 2: Save each cheque leaf snapshot ── */
    foreach ($cheque_leaves as $leaf) {
        $l_lid   = intval($leaf['id']);
        $l_fsid  = intval($leaf['field_summary_id'] ?? $fsid);
        $l_inv   = esc($conn, $leaf['invoice_num']  ?? '');
        $l_tc    = esc($conn, $leaf['t_code']       ?? '');
        $l_cno   = esc($conn, $leaf['cheque_no']);
        $l_cdate = esc($conn, $leaf['cheque_date']  ?? '');
        $l_camt  = floatval($leaf['amount']);
        $l_bkc   = esc($conn, $leaf['bank_code']    ?? '');
        $l_bkn   = esc($conn, $leaf['bank_name']    ?? '');
        $l_brc   = esc($conn, $leaf['branch_code']  ?? '');
        $l_brn   = esc($conn, $leaf['branch_name']  ?? '');
        $l_stat  = esc($conn, $leaf['master_status'] ?? 'pending');
        $cdate_sql = $l_cdate ? "'$l_cdate'" : 'NULL';

        mysqli_query($conn, "INSERT INTO payment_reversal_cheques
          (payment_reversal_id, original_payment_id, original_cheque_leaf_id,
           field_summary_id, invoice_num, t_code,
           cheque_no, cheque_date, amount,
           bank_code, bank_name, branch_code, branch_name,
           cheque_status_at_reversal)
        VALUES
          ($reversal_id, $payment_id, $l_lid,
           $l_fsid, '$l_inv', '$l_tc',
           '$l_cno', $cdate_sql, $l_camt,
           '$l_bkc', '$l_bkn', '$l_brc', '$l_brn',
           '$l_stat')");
    }

    /* ── STEP 3: Adjust cheques master table ── */
    foreach ($cheque_leaves as $leaf) {
        $cno  = esc($conn, $leaf['cheque_no']);
        $bkc  = esc($conn, $leaf['bank_code']   ?? '');
        $brc  = esc($conn, $leaf['branch_code'] ?? '');
        $lamt = floatval($leaf['amount']);

        $mr = mysqli_query($conn,
            "SELECT id, total_amount FROM cheques
             WHERE cheque_no='$cno' AND bank_code='$bkc' AND branch_code='$brc'
             LIMIT 1");
        $mx = $mr ? mysqli_fetch_assoc($mr) : null;
        if ($mx) {
            $new_total = max(0, round(floatval($mx['total_amount']) - $lamt, 2));
            if ($new_total <= 0) {
                /* No remaining amount → delete master cheque row entirely */
                mysqli_query($conn, "DELETE FROM cheques WHERE id=" . intval($mx['id']));
            } else {
                mysqli_query($conn,
                    "UPDATE cheques SET total_amount=$new_total, updated_at=NOW()
                     WHERE id=" . intval($mx['id']));
            }
        }
    }

    /* ── STEP 4: Hard-DELETE the payment row
            invoice_payment_cheques cascade-deletes via its FK ── */
    if (!mysqli_query($conn, "DELETE FROM invoice_payments WHERE id = $payment_id"))
        throw new Exception('Payment delete failed: ' . mysqli_error($conn));

    mysqli_commit($conn);

} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit;
}

/* ══════════════════════════════════════════════════
   RECALCULATE RUNNING TOTALS FOR THIS INVOICE
══════════════════════════════════════════════════ */
$inv_r   = mysqli_query($conn,
    "SELECT adjust_net_value FROM field_summary_details WHERE id=$detid LIMIT 1");
$inv_row = $inv_r ? mysqli_fetch_assoc($inv_r) : null;
$inv_amt = round(floatval($inv_row['adjust_net_value'] ?? 0), 2);

$fr = mysqli_query($conn,
    "SELECT ip.id, ip.amount, ip.payment_method
     FROM invoice_payments ip
     WHERE ip.field_summary_detail_id = $detid");
$new_paid = 0.00;
if ($fr) {
    while ($prow = mysqli_fetch_assoc($fr)) {
        if ($prow['payment_method'] === 'cash') {
            $new_paid += floatval($prow['amount']);
        } else {
            $pid   = intval($prow['id']);
            $leafr = mysqli_query($conn,
                "SELECT cheque_no, bank_code, branch_code, amount
                 FROM invoice_payment_cheques
                 WHERE invoice_payment_id = $pid");
            if ($leafr) {
                while ($leaf = mysqli_fetch_assoc($leafr)) {
                    $lcno = esc($conn, $leaf['cheque_no']);
                    $lbk  = esc($conn, $leaf['bank_code']);
                    $lbr  = esc($conn, $leaf['branch_code']);
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
$new_bal  = max(0.00, round($inv_amt - $new_paid, 2));

echo json_encode([
    'success'            => true,
    'deleted_payment_id' => $payment_id,
    'reversal_log_id'    => $reversal_id,
    'amount_deleted'     => $amt,
    'cheques_logged'     => count($cheque_leaves),
    'new_paid'           => $new_paid,
    'new_balance'        => $new_bal,
    'invoice_amt'        => $inv_amt,
    'message'            => 'Payment of Rs. ' . number_format($amt, 2)
                          . ' deleted. Audit log saved (Log #' . $reversal_id . ').',
]);