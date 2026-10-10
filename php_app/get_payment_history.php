<?php
/**
 * get_payment_history.php
 * Standalone AJAX endpoint — returns payment history for one invoice detail.
 */
include 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

$did = intval($_GET['detail_id'] ?? 0);
if (!$did) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

/* invoice info */
$info = null;
$ir = mysqli_query($conn,
    "SELECT fsd.invoice_num, fsd.adjust_net_value AS net_value,
            fs.route AS route_code, fs.delivery_date, fsd.t_code
     FROM field_summary_details fsd
     INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
     WHERE fsd.id = " . $did . " LIMIT 1");
if ($ir) $info = mysqli_fetch_assoc($ir);

/* payments */
$payments = [];
$pr = mysqli_query($conn,
    "SELECT payment_method, payment_date, amount, reference_no, cheque_no, created_at
     FROM invoice_payments
     WHERE field_summary_detail_id = " . $did . "
     ORDER BY payment_date ASC, id ASC");
if ($pr) {
    while ($p = mysqli_fetch_assoc($pr)) {
        $p['pay_date_fmt'] = $p['payment_date'] ? date('d M Y', strtotime($p['payment_date'])) : '—';
        $p['created_fmt']  = $p['created_at']   ? date('d M Y H:i', strtotime($p['created_at'])) : '—';
        $payments[] = $p;
    }
}

$totalPaid = array_sum(array_column($payments, 'amount'));
$netVal    = floatval($info['net_value'] ?? 0);
$balance   = max(0, $netVal - $totalPaid);

echo json_encode([
    'success'    => true,
    'info'       => $info,
    'payments'   => $payments,
    'total_paid' => $totalPaid,
    'balance'    => $balance,
]);
exit;
