<?php
/**
 * get_row_payments.php
 * Returns all payments for a given field_summary_detail_id as JSON.
 * Used by the inline "View Payments" button on edit_field_summary.php.
 */
include 'config.php';
header('Content-Type: application/json');

$detail_id = intval($_GET['detail_id'] ?? 0);
if (!$detail_id) {
    echo json_encode(['success' => false, 'error' => 'Missing detail_id']);
    exit;
}

$payments = [];

/* ── Main payments ── */
$sql = "SELECT ip.id, ip.payment_method, ip.payment_date, ip.amount,
               ip.amount_to_bank, ip.reference_no, ip.collected_by,
               ip.cheque_mode, ip.remarks, ip.payment_source,
               ip.created_at, ip.is_reversed
        FROM invoice_payments ip
        WHERE ip.field_summary_detail_id = $detail_id
        ORDER BY ip.created_at ASC";

$res = mysqli_query($conn, $sql);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $pay = $row;
        $pay['cheques'] = [];

        /* If cheque payment, fetch cheque leaves */
        if ($row['payment_method'] === 'cheque') {
            $pid = intval($row['id']);
            $cq  = mysqli_query($conn,
                "SELECT ipc.cheque_no, ipc.cheque_date, ipc.amount,
                        ipc.bank_code, ipc.bank_name, ipc.branch_code, ipc.branch_name,
                        COALESCE(ch.status, 'pending') AS cheque_status
                 FROM invoice_payment_cheques ipc
                 LEFT JOIN cheques ch
                   ON ch.cheque_no   = ipc.cheque_no
                  AND ch.bank_code   = ipc.bank_code
                  AND ch.branch_code = ipc.branch_code
                 WHERE ipc.invoice_payment_id = $pid
                 ORDER BY ipc.id ASC");
            if ($cq) {
                while ($cr = mysqli_fetch_assoc($cq)) {
                    $pay['cheques'][] = $cr;
                }
            }
        }

        $payments[] = $pay;
    }
}

/* ── Emergency credit requests ── */
$ecr = [];
$eq  = mysqli_query($conn,
    "SELECT id, reason, created_at
     FROM credit_requests
     WHERE field_summary_detail_id = $detail_id
     ORDER BY created_at ASC");
if ($eq) {
    while ($er = mysqli_fetch_assoc($eq)) {
        $ecr[] = $er;
    }
}

echo json_encode([
    'success'          => true,
    'payments'         => $payments,
    'emergency_credits' => $ecr,
    'count'            => count($payments),
]);
