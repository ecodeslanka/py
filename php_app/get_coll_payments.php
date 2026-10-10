<?php
include 'config.php';
header('Content-Type: application/json');

$sr       = trim($_GET['sr_code']      ?? '');
$date     = trim($_GET['date']         ?? '');
$coll_by  = strtolower(trim($_GET['collected_by'] ?? '')); // cc | sr | '' (all)
$source   = trim($_GET['source']       ?? '');             // invoice|credit|rtn_chq|rtn_chgs|sent_back|''

if (!$sr || !$date) {
    echo json_encode(['success' => false, 'message' => 'Missing params']);
    exit;
}

$sr_esc  = mysqli_real_escape_string($conn, $sr);
$dt_esc  = mysqli_real_escape_string($conn, $date);

/* ── collected_by filter ── */
$coll_cnd = '';
if ($coll_by === 'cc') {
    $coll_cnd = "AND LOWER(TRIM(COALESCE(ip.collected_by,''))) = 'cc'";
} elseif ($coll_by === 'sr') {
    $coll_cnd = "AND LOWER(TRIM(COALESCE(ip.collected_by,''))) != 'cc'";
}

/* ── payment_source filter ── */
$src_cnd = '';
switch ($source) {
    case 'invoice':   $src_cnd = "AND COALESCE(ip.payment_source,'invoice') = 'invoice'"; break;
    case 'credit':    $src_cnd = "AND ip.payment_source IN ('credit_sales','credit_sale')"; break;
    case 'rtn_chq':   $src_cnd = "AND ip.payment_source = 'return_cheque_settlement'"; break;
    case 'rtn_chgs':  $src_cnd = "AND ip.payment_source = 'return_charge_settlement'"; break;
    case 'sent_back': $src_cnd = "AND ip.payment_source = 'sentback_cheque_settlement'"; break;
}

$sql = "
    SELECT ip.id          AS payment_id,
           ip.invoice_num,
           ip.t_code,
           fsd.customer_name,
           fsd.route,
           fsd.adjust_net_value AS invoice_value,
           ip.amount,
           ip.payment_date,
           ip.payment_source,
           ip.collected_by,
           ip.reference_no,
           ip.remarks,
           ip.created_at
    FROM invoice_payments ip
    INNER JOIN field_summary fs ON fs.id = ip.field_summary_id
    LEFT  JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
    WHERE fs.sr_code     = '$sr_esc'
      AND fs.delivery_date = '$dt_esc'
      AND ip.payment_method = 'cash'
      AND ip.payment_date   = '$dt_esc'
      AND ip.is_reversed    = 0
      $coll_cnd
      $src_cnd
    ORDER BY ip.invoice_num ASC, ip.id ASC";

$res      = mysqli_query($conn, $sql);
$payments = [];
$total    = 0.0;

if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $payments[] = $r;
        $total += floatval($r['amount']);
    }
}

echo json_encode([
    'success'  => true,
    'payments' => $payments,
    'total'    => $total,
    'count'    => count($payments),
]);
