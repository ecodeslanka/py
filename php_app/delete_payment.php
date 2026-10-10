<?php
/**
 * delete_payment.php
 * Deletes an invoice payment and adjusts related tables:
 * - Removes invoice_payment_cheques leaves
 * - Subtracts from cheques master total_amount (deletes master row if total reaches 0)
 * - Removes the invoice_payments row
 * Returns: {success, new_paid, new_balance}
 */
include 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'error'=>'POST only']); exit;
}

$payment_id = intval($_POST['payment_id'] ?? 0);
if (!$payment_id) { echo json_encode(['success'=>false,'error'=>'payment_id required']); exit; }

/* ── Load payment record ── */
$pr = mysqli_query($conn,"SELECT * FROM invoice_payments WHERE id=$payment_id LIMIT 1");
if (!$pr || mysqli_num_rows($pr) === 0) { echo json_encode(['success'=>false,'error'=>'Payment not found']); exit; }
$pay  = mysqli_fetch_assoc($pr);
$pm   = $pay['payment_method'];
$detid = intval($pay['field_summary_detail_id']);

/* ── For cheque: subtract from cheques master ── */
if ($pm === 'cheque') {
    $lr = mysqli_query($conn,
        "SELECT cheque_no, bank_code, branch_code, amount
         FROM invoice_payment_cheques WHERE invoice_payment_id=$payment_id");
    if ($lr) {
        while ($leaf = mysqli_fetch_assoc($lr)) {
            $cno = mysqli_real_escape_string($conn, $leaf['cheque_no']);
            $bk  = mysqli_real_escape_string($conn, $leaf['bank_code']);
            $br  = mysqli_real_escape_string($conn, $leaf['branch_code']);
            $oa  = round(floatval($leaf['amount']), 2);

            $mr  = mysqli_query($conn,
                "SELECT id, total_amount FROM cheques
                 WHERE cheque_no='$cno' AND bank_code='$bk' AND branch_code='$br' LIMIT 1");
            $mrow = $mr ? mysqli_fetch_assoc($mr) : null;
            if ($mrow) {
                $nt = round(floatval($mrow['total_amount']) - $oa, 2);
                if ($nt <= 0) {
                    mysqli_query($conn,"DELETE FROM cheques WHERE id=".intval($mrow['id']));
                } else {
                    mysqli_query($conn,"UPDATE cheques SET total_amount=$nt, updated_at=NOW() WHERE id=".intval($mrow['id']));
                }
            }
        }
    }
    /* Remove cheque leaf rows */
    mysqli_query($conn,"DELETE FROM invoice_payment_cheques WHERE invoice_payment_id=$payment_id");
}

/* ── Delete the payment row ── */
if (!mysqli_query($conn,"DELETE FROM invoice_payments WHERE id=$payment_id")) {
    echo json_encode(['success'=>false,'error'=>'DB delete: '.mysqli_error($conn)]); exit;
}

/* ── Recalculate paid total for the detail row ── */
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
            if ($leafr) while ($leaf = mysqli_fetch_assoc($leafr)) {
                $lcno = mysqli_real_escape_string($conn, $leaf['cheque_no']);
                $lbk  = mysqli_real_escape_string($conn, $leaf['bank_code']);
                $lbr  = mysqli_real_escape_string($conn, $leaf['branch_code']);
                $sr   = mysqli_query($conn,
                    "SELECT status FROM cheques
                     WHERE cheque_no='$lcno' AND bank_code='$lbk' AND branch_code='$lbr' LIMIT 1");
                $srow = $sr ? mysqli_fetch_assoc($sr) : null;
                if ($srow && strtolower(trim($srow['status']??'')) === 'cleared') {
                    $new_paid += floatval($leaf['amount']);
                }
            }
        }
    }
}
$new_paid = round($new_paid, 2);

/* Invoice amount */
$ir  = mysqli_query($conn,"SELECT adjust_net_value FROM field_summary_details WHERE id=$detid LIMIT 1");
$irow = $ir ? mysqli_fetch_assoc($ir) : null;
$inv  = $irow ? round(floatval($irow['adjust_net_value']), 2) : 0.00;
$bal  = max(0.00, round($inv - $new_paid, 2));

/* If no remaining payments, un-lock the detail row */
$remaining_pays = mysqli_query($conn,"SELECT COUNT(*) AS c FROM invoice_payments WHERE field_summary_detail_id=$detid");
$rpc = $remaining_pays ? intval(mysqli_fetch_assoc($remaining_pays)['c']) : 0;
if ($rpc === 0) {
    mysqli_query($conn,"UPDATE field_summary_details SET `updated`=0 WHERE id=$detid");
}

echo json_encode([
    'success'     => true,
    'new_paid'    => $new_paid,
    'new_balance' => $bal,
    'invoice_amt' => $inv,
]);
