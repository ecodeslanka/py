<?php
include 'config.php';

/* ── Ensure credit_notes table exists (same as credit_bill_summary2.php) ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `credit_notes` (
    `id`                       INT AUTO_INCREMENT PRIMARY KEY,
    `field_summary_detail_id`  INT NOT NULL,
    `amount`                   DECIMAL(12,2) NOT NULL,
    `reason`                   TEXT,
    `note_date`                DATE NOT NULL,
    `created_at`               DATETIME DEFAULT CURRENT_TIMESTAMP,
    `is_deleted`               TINYINT(1) NOT NULL DEFAULT 0,
    INDEX `idx_fsd_id` (`field_summary_detail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Ensure to_be_delivery column exists ── */
$chktbd = mysqli_query($conn, "SHOW COLUMNS FROM field_summary_details LIKE 'to_be_delivery'");
if ($chktbd && mysqli_num_rows($chktbd) === 0)
    mysqli_query($conn, "ALTER TABLE field_summary_details ADD COLUMN to_be_delivery TINYINT(1) NOT NULL DEFAULT 0");

/* ── Ensure bill_verified column exists ── */
$cv = mysqli_query($conn,"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credit_requests' AND COLUMN_NAME='bill_verified' LIMIT 1");
if($cv && mysqli_num_rows($cv)===0) mysqli_query($conn,"ALTER TABLE credit_requests ADD COLUMN `bill_verified` TINYINT(1) NOT NULL DEFAULT 0");

/* ══════════════════════════════════════════════════════════════
   SHARED DATA FETCH  (used by both screen view & print view)
══════════════════════════════════════════════════════════════ */
function rcAgingCls($d){ if($d>=90)return'aging-red'; if($d>=60)return'aging-orange'; if($d>=30)return'aging-yellow'; return'aging-green'; }
function rcAgingLbl($d){ if($d>=90)return'Critical'; if($d>=60)return'Overdue'; if($d>=30)return'Warning'; return'Fresh'; }

function rc_fetch_data($conn) {
    $f_route   = trim($_GET['route']          ?? '');
    $f_as_at   = trim($_GET['as_at_date']     ?? '');
    $f_tbd     = trim($_GET['to_be_delivery'] ?? ''); // '', '1', '0'
    $f_amt_min = trim($_GET['amount_min']     ?? '');
    $f_amt_max = trim($_GET['amount_max']     ?? '');

    $where = ["fsd.updated = 1"];
    if ($f_route) $where[] = "fs.route = '" . mysqli_real_escape_string($conn,$f_route) . "'";
    if ($f_as_at) $where[] = "fs.delivery_date <= '" . mysqli_real_escape_string($conn,$f_as_at) . "'";
    if ($f_tbd === '1') $where[] = "fsd.to_be_delivery = 1";
    if ($f_tbd === '0') $where[] = "fsd.to_be_delivery = 0";
    $where_sql = implode(' AND ', $where);

    $pay_date_filter = $f_as_at
        ? "WHERE is_reversed = 0 AND payment_date <= '" . mysqli_real_escape_string($conn,$f_as_at) . "'"
        : "WHERE is_reversed = 0";

    $aging_base = $f_as_at
        ? "'" . mysqli_real_escape_string($conn,$f_as_at) . "'"
        : "CURDATE()";

    $amt_filter = '';
    if ($f_amt_min !== '') $amt_filter .= " AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) >= " . floatval($f_amt_min);
    if ($f_amt_max !== '') $amt_filter .= " AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) <= " . floatval($f_amt_max);

    $sql = "
    SELECT
        fsd.id AS detail_id,
        fs.route AS route_code,
        COALESCE(r.route_name, fs.route) AS route_name,
        fs.sr_code, fs.delivery_date,
        DATEDIFF($aging_base, fs.delivery_date) AS aging_days,
        fsd.t_code,
        COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS customer_name,
        fsd.invoice_num,
        COALESCE(fsd.to_be_delivery,0) AS to_be_delivery,
        COALESCE(siid.final_bill_amount, fsd.adjust_net_value) AS net_value,
        COALESCE(pay.total_paid,0)   AS paid,
        COALESCE(pay.cash_paid,0)    AS cash_paid,
        COALESCE(pay.cheque_paid,0)  AS cheque_paid,
        COALESCE(cn.total_cn,0)      AS total_cn,
        (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) AS balance,
        CASE WHEN cr.detail_id IS NOT NULL THEN 1 ELSE 0 END AS is_special,
        fsd.bill_verified AS bill_verified
    FROM field_summary_details fsd
    INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
    LEFT  JOIN routes r ON r.route_code = fs.route
    LEFT  JOIN customers c ON c.t_code = fsd.t_code
    LEFT  JOIN (
        SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
        FROM secondary_invoice_import_details GROUP BY bill_no
    ) siid ON siid.bill_no = fsd.invoice_num
    LEFT  JOIN (
        SELECT field_summary_detail_id,
               SUM(amount) AS total_paid,
               SUM(CASE WHEN payment_method='cash'   THEN amount ELSE 0 END) AS cash_paid,
               SUM(CASE WHEN payment_method='cheque' THEN amount ELSE 0 END) AS cheque_paid
        FROM   invoice_payments $pay_date_filter
        GROUP  BY field_summary_detail_id
    ) pay ON pay.field_summary_detail_id = fsd.id
    LEFT  JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_cn
        FROM   credit_notes WHERE is_deleted=0
        GROUP  BY field_summary_detail_id
    ) cn ON cn.field_summary_detail_id = fsd.id
    LEFT  JOIN (SELECT field_summary_detail_id AS detail_id FROM credit_requests GROUP BY field_summary_detail_id) cr ON cr.detail_id = fsd.id
    WHERE $where_sql
      AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) > 0
      $amt_filter
    ORDER BY fs.route, fs.sr_code, fsd.t_code, aging_days DESC";

    $result = mysqli_query($conn, $sql);
    $rows = [];
    if ($result) { while ($row = mysqli_fetch_assoc($result)) $rows[] = $row; }

    /* ── Aggregate: routes[route_code] => [ ..totals.., customers[t_code] => [..totals..] ] ── */
    $routes = [];
    $grand = ['bills'=>0,'customers'=>[],'net'=>0,'cash'=>0,'cheque'=>0,'cn'=>0,'balance'=>0,'special'=>0,'unverified'=>0,'max_aging'=>0];

    foreach ($rows as $row) {
        $rc = $row['route_code'];
        if (!isset($routes[$rc])) {
            $routes[$rc] = [
                'route_code' => $rc, 'route_name' => $row['route_name'],
                'sr_codes' => [], 'customers' => [],
                'bills'=>0,'net'=>0,'cash'=>0,'cheque'=>0,'cn'=>0,'balance'=>0,
                'special'=>0,'unverified'=>0,'max_aging'=>0
            ];
        }
        $R = &$routes[$rc];
        $R['sr_codes'][$row['sr_code']] = true;
        $R['bills']++;
        $R['net']     += floatval($row['net_value']);
        $R['cash']    += floatval($row['cash_paid']);
        $R['cheque']  += floatval($row['cheque_paid']);
        $R['cn']      += floatval($row['total_cn']);
        $R['balance'] += floatval($row['balance']);
        if ($row['is_special']) $R['special']++;
        $bv = $row['bill_verified'];
        if ($bv === null || $bv === '' || intval($bv) === 0) $R['unverified']++;
        $aging = max(0, intval($row['aging_days']));
        if ($aging > $R['max_aging']) $R['max_aging'] = $aging;

        $tc = $row['t_code'];
        if (!isset($R['customers'][$tc])) {
            $R['customers'][$tc] = [
                't_code'=>$tc, 'customer_name'=>$row['customer_name'],
                'bills'=>0,'net'=>0,'cash'=>0,'cheque'=>0,'cn'=>0,'balance'=>0,
                'special'=>0,'unverified'=>0,'max_aging'=>0
            ];
        }
        $C = &$R['customers'][$tc];
        $C['bills']++;
        $C['net']     += floatval($row['net_value']);
        $C['cash']    += floatval($row['cash_paid']);
        $C['cheque']  += floatval($row['cheque_paid']);
        $C['cn']      += floatval($row['total_cn']);
        $C['balance'] += floatval($row['balance']);
        if ($row['is_special']) $C['special']++;
        if ($bv === null || $bv === '' || intval($bv) === 0) $C['unverified']++;
        if ($aging > $C['max_aging']) $C['max_aging'] = $aging;
        unset($R, $C);

        $grand['bills']++;
        $grand['customers'][$rc.'|'.$tc] = true;
        $grand['net']     += floatval($row['net_value']);
        $grand['cash']    += floatval($row['cash_paid']);
        $grand['cheque']  += floatval($row['cheque_paid']);
        $grand['cn']      += floatval($row['total_cn']);
        $grand['balance'] += floatval($row['balance']);
        if ($row['is_special']) $grand['special']++;
        if ($bv === null || $bv === '' || intval($bv) === 0) $grand['unverified']++;
        if ($aging > $grand['max_aging']) $grand['max_aging'] = $aging;
    }
    $grand['customer_count'] = count($grand['customers']);
    $grand['route_count']    = count($routes);

    /* sort routes by outstanding balance desc, customers within by balance desc */
    uasort($routes, fn($a,$b) => $b['balance'] <=> $a['balance']);
    foreach ($routes as &$R) { uasort($R['customers'], fn($a,$b) => $b['balance'] <=> $a['balance']); }
    unset($R);

    return [
        'routes'   => $routes,
        'grand'    => $grand,
        'filters'  => ['route'=>$f_route,'as_at'=>$f_as_at,'tbd'=>$f_tbd,'amt_min'=>$f_amt_min,'amt_max'=>$f_amt_max]
    ];
}

/* ══════════════════════════════════════════════════════════════
   PRINT VIEW
══════════════════════════════════════════════════════════════ */
if (isset($_GET['printview'])) {
    $D = rc_fetch_data($conn);
    $routes = $D['routes']; $grand = $D['grand']; $F = $D['filters'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Route Wise Credit Summary &mdash; Print</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
@page{size:A4 landscape;margin:8mm 8mm 10mm 8mm;}
body{font-family:Arial,Helvetica,sans-serif;font-size:9px;color:#000;background:#fff;}
.print-btn-bar{display:flex;align-items:center;justify-content:space-between;background:#1e1b4b;color:#fff;padding:10px 18px;gap:10px;position:sticky;top:0;z-index:99;}
.print-btn-bar h1{font-size:14px;font-weight:800;}
.btns{display:flex;gap:8px;}
.pbtn{display:inline-flex;align-items:center;gap:6px;padding:7px 18px;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;font-family:Arial,sans-serif;}
.pbtn-print{background:#6366f1;color:#fff;}.pbtn-print:hover{background:#4f46e5;}
.pbtn-close{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);}.pbtn-close:hover{background:rgba(220,38,38,.8);}
.rpt-header{border-bottom:2.5px solid #1e1b4b;padding:10px 0 8px;margin-bottom:10px;}
.rpt-header-title{font-size:16px;font-weight:900;color:#1e1b4b;margin-bottom:4px;}
.rpt-meta{display:flex;flex-wrap:wrap;gap:0 18px;font-size:8.5px;color:#444;}
.rpt-meta strong{color:#1e1b4b;font-weight:800;}
.rpt-summary{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;}
.rpt-sum-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:5px;padding:4px 12px;font-size:8.5px;display:inline-flex;align-items:center;gap:5px;}
.rpt-sum-box .lbl{font-weight:600;color:#6b7280;font-size:8px;text-transform:uppercase;}
.rpt-sum-box .val{color:#1e1b4b;font-size:10px;font-weight:900;}
.rpt-sum-box.red .val{color:#dc2626;}.rpt-sum-box.green .val{color:#16a34a;}.rpt-sum-box.blue .val{color:#2563eb;}.rpt-sum-box.orange .val{color:#ea580c;}
.as-at-notice{background:#fef3c7;border:1px solid #fde68a;border-radius:5px;padding:4px 12px;font-size:8px;color:#92400e;font-weight:700;display:inline-flex;align-items:center;gap:5px;}

.route-block{margin-bottom:10px;page-break-inside:avoid;}
.route-hdr{background:#1e1b4b;color:#fff;padding:5px 8px;font-size:10px;font-weight:800;display:flex;justify-content:space-between;align-items:center;border-radius:3px 3px 0 0;}
.route-hdr .rh-figs{display:flex;gap:14px;font-size:8.5px;font-weight:700;}
.route-hdr .rh-figs span b{color:#fde68a;}
table{width:100%;border-collapse:collapse;font-size:8.5px;table-layout:fixed;}
thead th{background:#eef2ff;color:#1e1b4b;padding:4px;font-size:8px;font-weight:700;border:1px solid #c7d2fe;white-space:nowrap;text-align:left;}
thead th.tc{text-align:center;}thead th.tr{text-align:right;}
tbody tr{border-bottom:1px solid #e5e5e5;}
tbody tr.special-row td{background:#fefce8;}
td{padding:3px 4px;border:1px solid #e8e8e8;vertical-align:middle;word-break:break-word;color:#111;}
td.tc{text-align:center;}td.tr{text-align:right;}
tfoot.rtotal td{background:#eef2ff !important;color:#1e1b4b !important;font-weight:900;border:1px solid #c7d2fe;padding:4px;}
.grand-foot td{background:#1e1b4b !important;color:#fff !important;padding:6px 4px;font-size:9.5px;font-weight:900;border:1px solid #334155;}
th:nth-child(1){width:24px;}th:nth-child(2){width:80px;}th:nth-child(3){width:150px;}
th:nth-child(4){width:36px;}th:nth-child(5){width:36px;}th:nth-child(6){width:80px;}
th:nth-child(7){width:70px;}th:nth-child(8){width:70px;}th:nth-child(9){width:60px;}th:nth-child(10){width:80px;}
.balance{color:#dc2626;font-weight:700;}.paid-val{color:#16a34a;font-weight:700;}.cheque-val{color:#2563eb;font-weight:700;}.cn-val{color:#ea580c;font-weight:700;}
.aging-val{font-weight:700;font-size:8.5px;}
.aging-green{color:#16a34a;}.aging-yellow{color:#d97706;}.aging-orange{color:#ea580c;}.aging-red{color:#dc2626;}
.t-code{color:#1e40af;font-weight:700;font-family:monospace;}
@media screen{body{background:#e2e8f0;}.page-preview{background:#fff;width:297mm;min-height:210mm;margin:16px auto;padding:8mm;box-shadow:0 4px 24px rgba(0,0,0,.18);border-radius:4px;}}
@media print{
    .print-btn-bar{display:none !important;}
    .page-preview{margin:0 !important;padding:0 !important;box-shadow:none !important;width:auto !important;}
    body{background:#fff !important;}
    .route-hdr{background:#1e1b4b !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    thead th{background:#eef2ff !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    tbody tr.special-row td{background:#fefce8 !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    tfoot.rtotal td{background:#eef2ff !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .grand-foot td{background:#1e1b4b !important;color:#fff !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}
</style>
</head>
<body>
<div class="print-btn-bar">
    <h1>&#128506; Route Wise Credit Summary &mdash; Print Preview</h1>
    <div class="btns">
        <button class="pbtn pbtn-print" onclick="window.print()">&#128438;&nbsp;Print / Save PDF</button>
        <button class="pbtn pbtn-close" onclick="window.close()">&#10005;&nbsp;Close</button>
    </div>
</div>
<div class="page-preview">
<div class="rpt-header">
    <div class="rpt-header-title">Route Wise Customer Credit Bill &amp; Outstanding Summary</div>
    <div class="rpt-meta">
        <?php if($F['route']): ?><span><strong>Route:</strong> <?php echo htmlspecialchars($F['route']); ?></span><?php endif; ?>
        <?php if($F['as_at']): ?><span><strong>As At:</strong> <?php echo date('d M Y',strtotime($F['as_at'])); ?></span><?php endif; ?>
        <?php if($F['tbd'] === '1'): ?><span><strong>To Be Delivery:</strong> Yes Only</span><?php endif; ?>
        <?php if($F['tbd'] === '0'): ?><span><strong>To Be Delivery:</strong> No Only</span><?php endif; ?>
        <?php if($F['amt_min'] !== ''): ?><span><strong>Balance Min:</strong> Rs. <?php echo number_format(floatval($F['amt_min']),2); ?></span><?php endif; ?>
        <?php if($F['amt_max'] !== ''): ?><span><strong>Balance Max:</strong> Rs. <?php echo number_format(floatval($F['amt_max']),2); ?></span><?php endif; ?>
        <?php if(!$F['route'] && !$F['as_at'] && $F['tbd']==='' && $F['amt_min']==='' && $F['amt_max']===''): ?><span><strong>Scope:</strong> All Records</span><?php endif; ?>
        <span><strong>Printed:</strong> <?php echo date('d M Y, H:i'); ?></span>
    </div>
    <div class="rpt-summary">
        <div class="rpt-sum-box"><span class="lbl">Routes</span><span class="val"><?php echo $grand['route_count']; ?></span></div>
        <div class="rpt-sum-box"><span class="lbl">Customers</span><span class="val"><?php echo $grand['customer_count']; ?></span></div>
        <div class="rpt-sum-box"><span class="lbl">Bills</span><span class="val"><?php echo $grand['bills']; ?></span></div>
        <div class="rpt-sum-box"><span class="lbl">Ikea Value</span><span class="val">Rs.&nbsp;<?php echo number_format($grand['net'],2); ?></span></div>
        <div class="rpt-sum-box green"><span class="lbl">Cash Paid</span><span class="val">Rs.&nbsp;<?php echo number_format($grand['cash'],2); ?></span></div>
        <div class="rpt-sum-box blue"><span class="lbl">Cheque Paid</span><span class="val">Rs.&nbsp;<?php echo number_format($grand['cheque'],2); ?></span></div>
        <div class="rpt-sum-box orange"><span class="lbl">Credit Notes</span><span class="val">Rs.&nbsp;<?php echo number_format($grand['cn'],2); ?></span></div>
        <div class="rpt-sum-box red"><span class="lbl">Total Balance</span><span class="val">Rs.&nbsp;<?php echo number_format($grand['balance'],2); ?></span></div>
        <?php if($F['as_at']): ?><div class="as-at-notice">&#9200; Payments counted up to <?php echo date('d M Y',strtotime($F['as_at'])); ?> only</div><?php endif; ?>
    </div>
</div>

<?php if (empty($routes)): ?>
    <p style="padding:20px;color:#777;">No outstanding records found for the selected filters.</p>
<?php else: $rno=1; foreach ($routes as $R):
    $srList = implode(', ', array_keys($R['sr_codes']));
    $rAgCls = rcAgingCls($R['max_aging']); $rAgLbl = rcAgingLbl($R['max_aging']);
?>
<div class="route-block">
    <div class="route-hdr">
        <span><?php echo $rno++; ?>. <?php echo htmlspecialchars($R['route_code'].' — '.$R['route_name']); ?> &nbsp;<span style="font-weight:500;color:#c7d2fe;">(SR: <?php echo htmlspecialchars($srList); ?>)</span></span>
        <span class="rh-figs">
            <span>Customers: <b><?php echo count($R['customers']); ?></b></span>
            <span>Bills: <b><?php echo $R['bills']; ?></b></span>
            <span>Special: <b><?php echo $R['special']; ?></b></span>
            <span>Unverified: <b><?php echo $R['unverified']; ?></b></span>
            <span>Oldest: <b><?php echo $R['max_aging']; ?>d (<?php echo $rAgLbl; ?>)</b></span>
            <span>Balance: <b>Rs. <?php echo number_format($R['balance'],2); ?></b></span>
        </span>
    </div>
    <table>
        <thead>
            <tr>
                <th class="tc">No</th><th>T Code</th><th>Customer</th>
                <th class="tc">Bills</th><th class="tc">Special</th>
                <th class="tr">Ikea Value</th><th class="tr">Cash</th><th class="tr">Cheque</th>
                <th class="tr">Credit Notes</th><th class="tr">Balance</th>
            </tr>
        </thead>
        <tbody>
        <?php $cno=1; foreach ($R['customers'] as $C): ?>
        <tr class="<?php echo $C['special']>0?'special-row':''; ?>">
            <td class="tc"><?php echo $cno++; ?></td>
            <td><span class="t-code"><?php echo htmlspecialchars($C['t_code']); ?></span></td>
            <td><?php echo htmlspecialchars($C['customer_name']); ?></td>
            <td class="tc"><?php echo $C['bills']; ?></td>
            <td class="tc"><?php echo $C['special'] ?: '—'; ?></td>
            <td class="tr"><?php echo number_format($C['net'],2); ?></td>
            <td class="tr <?php echo $C['cash']>0?'paid-val':''; ?>"><?php echo $C['cash']>0?number_format($C['cash'],2):'&mdash;'; ?></td>
            <td class="tr <?php echo $C['cheque']>0?'cheque-val':''; ?>"><?php echo $C['cheque']>0?number_format($C['cheque'],2):'&mdash;'; ?></td>
            <td class="tr <?php echo $C['cn']>0?'cn-val':''; ?>"><?php echo $C['cn']>0?number_format($C['cn'],2):'&mdash;'; ?></td>
            <td class="tr balance">Rs.&nbsp;<?php echo number_format($C['balance'],2); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="rtotal">
            <tr>
                <td colspan="5" style="text-align:right;">Route Total (<?php echo count($R['customers']); ?> customers, <?php echo $R['bills']; ?> bills)</td>
                <td class="tr"><?php echo number_format($R['net'],2); ?></td>
                <td class="tr"><?php echo number_format($R['cash'],2); ?></td>
                <td class="tr"><?php echo number_format($R['cheque'],2); ?></td>
                <td class="tr"><?php echo number_format($R['cn'],2); ?></td>
                <td class="tr">Rs.&nbsp;<?php echo number_format($R['balance'],2); ?></td>
            </tr>
        </tfoot>
    </table>
</div>
<?php endforeach; ?>

<table style="margin-top:6px;">
    <tfoot>
        <tr class="grand-foot">
            <td style="text-align:right;">GRAND TOTAL &mdash; <?php echo $grand['route_count']; ?> routes, <?php echo $grand['customer_count']; ?> customers, <?php echo $grand['bills']; ?> bills</td>
            <td class="tr" style="width:100px;">Rs.&nbsp;<?php echo number_format($grand['net'],2); ?></td>
            <td class="tr" style="width:100px;">Rs.&nbsp;<?php echo number_format($grand['cash'],2); ?></td>
            <td class="tr" style="width:100px;">Rs.&nbsp;<?php echo number_format($grand['cheque'],2); ?></td>
            <td class="tr" style="width:100px;">Rs.&nbsp;<?php echo number_format($grand['cn'],2); ?></td>
            <td class="tr" style="width:120px;">Rs.&nbsp;<?php echo number_format($grand['balance'],2); ?></td>
        </tr>
    </tfoot>
</table>
<?php endif; ?>
</div>
<script>window.addEventListener('load',function(){window.print();});</script>
</body>
</html>
<?php
    exit;
}

/* ══════════════════════════════════════════════════════════════
   SCREEN VIEW
══════════════════════════════════════════════════════════════ */
include 'header.php';

$routes_res = mysqli_query($conn,"SELECT route_code, route_name FROM routes WHERE active=1 ORDER BY route_name");
$all_routes = [];
while ($r = mysqli_fetch_assoc($routes_res)) $all_routes[] = $r;

$D = rc_fetch_data($conn);
$routes = $D['routes']; $grand = $D['grand']; $F = $D['filters'];
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
*{box-sizing:border-box;}
.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:18px 20px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.filter-title{font-size:13px;font-weight:700;color:#374151;margin-bottom:14px;display:flex;align-items:center;gap:6px;}
.filter-grid{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;}
.filter-inputs{display:grid;grid-template-columns:1fr 150px 120px 120px 150px;gap:12px;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.fg input,.fg select{border:1px solid #e5e5e5;border-radius:7px;padding:8px 11px;font-size:13px;font-family:'Inter',sans-serif;color:#1f2937;width:100%;transition:border .2s;}
.fg input:focus,.fg select:focus{outline:none;border-color:#6366f1;}
.as-at-banner{display:flex;align-items:center;gap:8px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:8px 14px;margin-bottom:14px;font-size:12px;font-weight:600;color:#92400e;}
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;text-decoration:none;transition:all .2s;white-space:nowrap;}
.btn-primary{background:#6366f1;color:#fff;}.btn-primary:hover{background:#4f46e5;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-success{background:#16a34a;color:#fff;}.btn-success:hover{background:#15803d;}
.btn-sm{padding:5px 11px;font-size:11px;}

.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:14px;margin-bottom:20px;}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px;}
.stat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.stat-value{font-size:18px;font-weight:800;color:#1f2937;}
.stat-value.red{color:#dc2626;}.stat-value.green{color:#16a34a;}.stat-value.blue{color:#2563eb;}.stat-value.amber{color:#d97706;}.stat-value.orange{color:#ea580c;}.stat-value.violet{color:#7c3aed;}

.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);margin-bottom:14px;}
.route-hd{display:flex;align-items:center;justify-content:space-between;background:#1e1b4b;color:#fff;padding:12px 16px;cursor:pointer;gap:10px;flex-wrap:wrap;}
.route-hd:hover{background:#272257;}
.route-hd-left{display:flex;align-items:center;gap:10px;}
.route-hd .rc-toggle{width:22px;height:22px;border-radius:6px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:11px;transition:transform .2s;flex-shrink:0;}
.route-hd.open .rc-toggle{transform:rotate(90deg);}
.route-hd .rc-title{font-size:14px;font-weight:800;}
.route-hd .rc-sr{font-size:10.5px;color:#c7d2fe;font-weight:500;}
.route-hd-figs{display:flex;gap:16px;flex-wrap:wrap;font-size:11px;font-weight:700;color:#e0e7ff;}
.route-hd-figs .rf-lbl{color:#a5b4fc;font-weight:600;text-transform:uppercase;font-size:9px;margin-right:3px;}
.route-hd-figs .rf-bal{color:#fecaca;font-size:13px;}

.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead th{padding:8px;text-align:left;font-weight:700;font-size:11px;color:#4338ca;background:#eef2ff;white-space:nowrap;}
.data-table thead th.tr{text-align:right;}.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr:hover td{background:#f9fafb;}
.data-table tbody tr.special-row td{background:#fefce8;}
.data-table tbody tr.special-row:hover td{background:#fef9c3;}
.data-table td{padding:8px;}
.data-table td.tr{text-align:right;}.data-table td.tc{text-align:center;}
.data-table tfoot td{padding:9px 8px;font-weight:800;font-size:12.5px;background:#eef2ff;color:#1e1b4b;border-top:2px solid #c7d2fe;}
.data-table tfoot td.tr{text-align:right;}

.t-code{font-family:monospace;font-size:11.5px;font-weight:700;color:#4338ca;}
.balance-amt{font-weight:700;color:#dc2626;}
.special-badge{background:#fef08a;color:#854d0e;border:1px solid #fde047;display:inline-flex;align-items:center;gap:3px;padding:1px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.unv-badge{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;display:inline-flex;align-items:center;gap:3px;padding:1px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.aging-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;border:1px solid;}
.aging-green {background:#f0fdf4;color:#16a34a;border-color:#bbf7d0;}
.aging-yellow{background:#fefce8;color:#d97706;border-color:#fde68a;}
.aging-orange{background:#fff7ed;color:#ea580c;border-color:#fed7aa;}
.aging-red   {background:#fef2f2;color:#dc2626;border-color:#fecaca;}
.route-body{display:none;}
.route-body.open{display:block;}
.view-link{font-size:11px;color:#4338ca;text-decoration:none;font-weight:700;border-bottom:1px dashed #a5b4fc;}
.view-link:hover{color:#6366f1;}
</style>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;" class="no-print">
    <div>
        <h2 class="page-title"><i class="fa-solid fa-route"></i> Route Wise Credit Bill &amp; Outstanding Summary</h2>
        <p class="page-subtitle">
            <span style="color:#d97706;font-weight:700;">■</span> Yellow = has special credit &nbsp;|&nbsp;
            <span style="font-weight:700;">Aging:</span>
            <span class="aging-badge aging-green" style="font-size:10px;padding:1px 7px;">0–29d Fresh</span>
            <span class="aging-badge aging-yellow" style="font-size:10px;padding:1px 7px;">30–59d Warning</span>
            <span class="aging-badge aging-orange" style="font-size:10px;padding:1px 7px;">60–89d Overdue</span>
            <span class="aging-badge aging-red" style="font-size:10px;padding:1px 7px;">90d+ Critical</span>
        </p>
    </div>
    <?php if(!empty($routes)): ?>
    <div style="display:flex;gap:8px;" class="no-print">
        <button onclick="toggleAllRoutes()" class="btn btn-secondary btn-sm" id="expandAllBtn"><i class="fa-solid fa-up-right-and-down-left-from-center"></i> Expand All</button>
        <button onclick="openPrintView()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print Report</button>
        <button onclick="exportCSV()" class="btn btn-success btn-sm"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
    </div>
    <?php endif; ?>
</div>

<!-- FILTERS -->
<div class="filter-card no-print">
    <div class="filter-title"><i class="fa-solid fa-filter"></i> Filter</div>
    <form method="GET" id="filterForm">
        <div class="filter-grid">
            <div class="filter-inputs">
                <div class="fg">
                    <label><i class="fa-solid fa-route"></i> Route</label>
                    <select name="route" id="routeSelect" style="width:100%;">
                        <option value="">— All Routes —</option>
                        <?php foreach($all_routes as $rt): ?>
                        <option value="<?php echo htmlspecialchars($rt['route_code']); ?>" <?php echo $F['route']===$rt['route_code']?'selected':''; ?>>
                            <?php echo htmlspecialchars($rt['route_code'].' — '.$rt['route_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label><i class="fa-solid fa-calendar-check"></i> As At Date</label>
                    <input type="date" name="as_at_date" value="<?php echo htmlspecialchars($F['as_at']); ?>" title="Show balances as of this date">
                </div>
                <div class="fg">
                    <label><i class="fa-solid fa-coins"></i> Balance Min</label>
                    <input type="number" name="amount_min" step="0.01" min="0" placeholder="Min" value="<?php echo htmlspecialchars($F['amt_min']); ?>">
                </div>
                <div class="fg">
                    <label><i class="fa-solid fa-coins"></i> Balance Max</label>
                    <input type="number" name="amount_max" step="0.01" min="0" placeholder="Max" value="<?php echo htmlspecialchars($F['amt_max']); ?>">
                </div>
                <div class="fg">
                    <label><i class="fa-solid fa-truck"></i> To Be Delivery</label>
                    <select name="to_be_delivery">
                        <option value=""  <?php echo $F['tbd']===''  ? 'selected':''; ?>>— All —</option>
                        <option value="1" <?php echo $F['tbd']==='1' ? 'selected':''; ?>>&#128666; Yes — Pending Delivery</option>
                        <option value="0" <?php echo $F['tbd']==='0' ? 'selected':''; ?>>&#10003; No — Not Pending</option>
                    </select>
                </div>
            </div>
            <div class="fg" style="flex-direction:row;gap:8px;align-items:flex-end;">
                <button type="submit" class="btn btn-primary" style="flex:1;"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
                <a href="route_wise_credit_summary.php" class="btn btn-secondary" title="Clear All"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </div>
    </form>
</div>

<?php if (empty($routes)): ?>
<div class="table-card"><div style="text-align:center;padding:70px 20px;color:#9ca3af;"><i class="fa-solid fa-inbox" style="font-size:44px;display:block;margin-bottom:14px;opacity:.35;"></i><p>No outstanding invoices found.</p></div></div>
<?php else: ?>

<?php if($F['as_at']): ?>
<div class="as-at-banner no-print">
    <i class="fa-solid fa-clock-rotate-left"></i>
    <strong>As At View:</strong> Showing outstanding balances as of <strong><?php echo date('d M Y', strtotime($F['as_at'])); ?></strong>. Payments after this date are excluded.
</div>
<?php endif; ?>

<!-- GRAND TOTAL STAT CARDS -->
<div class="stat-grid">
    <div class="stat-card"><div class="stat-label">Routes</div><div class="stat-value violet"><?php echo $grand['route_count']; ?></div></div>
    <div class="stat-card"><div class="stat-label">Customers</div><div class="stat-value blue"><?php echo $grand['customer_count']; ?></div></div>
    <div class="stat-card"><div class="stat-label">Total Bills</div><div class="stat-value"><?php echo $grand['bills']; ?></div></div>
    <div class="stat-card"><div class="stat-label">Ikea Value</div><div class="stat-value">Rs. <?php echo number_format($grand['net'],2); ?></div></div>
    <div class="stat-card"><div class="stat-label">Cash Paid<?php echo $F['as_at']?' (as at)':''; ?></div><div class="stat-value green">Rs. <?php echo number_format($grand['cash'],2); ?></div></div>
    <div class="stat-card"><div class="stat-label">Cheque Paid<?php echo $F['as_at']?' (as at)':''; ?></div><div class="stat-value blue">Rs. <?php echo number_format($grand['cheque'],2); ?></div></div>
    <div class="stat-card"><div class="stat-label">Credit Notes</div><div class="stat-value orange">Rs. <?php echo number_format($grand['cn'],2); ?></div></div>
    <div class="stat-card"><div class="stat-label">Total Balance<?php echo $F['as_at']?' (as at)':''; ?></div><div class="stat-value red">Rs. <?php echo number_format($grand['balance'],2); ?></div></div>
    <div class="stat-card"><div class="stat-label">Unverified Bills</div><div class="stat-value amber"><?php echo $grand['unverified']; ?></div></div>
</div>

<!-- ROUTE BLOCKS -->
<div id="routeBlocks">
<?php $rn=1; foreach ($routes as $R):
    $srList = implode(', ', array_keys($R['sr_codes']));
    $ragCls = rcAgingCls($R['max_aging']); $ragLbl = rcAgingLbl($R['max_aging']);
    $rid = 'r-'.preg_replace('/[^a-zA-Z0-9_]/','_',$R['route_code']);
    $viewUrl = 'credit_bill_summary2.php?route='.urlencode($R['route_code']).($F['as_at']?'&as_at_date='.urlencode($F['as_at']):'');
?>
<div class="table-card">
    <div class="route-hd" onclick="toggleRoute('<?php echo $rid; ?>')" id="hd-<?php echo $rid; ?>">
        <div class="route-hd-left">
            <div class="rc-toggle"><i class="fa-solid fa-chevron-right"></i></div>
            <div>
                <div class="rc-title"><?php echo $rn++; ?>. <?php echo htmlspecialchars($R['route_code'].' — '.$R['route_name']); ?></div>
                <div class="rc-sr">SR: <?php echo htmlspecialchars($srList); ?> &nbsp;•&nbsp; <?php echo count($R['customers']); ?> customer<?php echo count($R['customers'])!=1?'s':''; ?></div>
            </div>
        </div>
        <div class="route-hd-figs">
            <span><span class="rf-lbl">Bills</span><?php echo $R['bills']; ?></span>
            <span><span class="rf-lbl">Special</span><?php echo $R['special']; ?></span>
            <span><span class="rf-lbl">Unverified</span><?php echo $R['unverified']; ?></span>
            <span><span class="aging-badge <?php echo $ragCls; ?>" style="font-size:10px;padding:2px 8px;"><?php echo $R['max_aging']; ?>d <?php echo $ragLbl; ?></span></span>
            <span class="rf-bal"><span class="rf-lbl">Balance</span>Rs. <?php echo number_format($R['balance'],2); ?></span>
            <a href="<?php echo htmlspecialchars($viewUrl); ?>" target="_blank" class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;" onclick="event.stopPropagation();" title="View invoice-level detail"><i class="fa-solid fa-up-right-from-square"></i></a>
        </div>
    </div>
    <div class="route-body" id="body-<?php echo $rid; ?>">
        <div class="dt-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:32px;">No</th><th>T Code</th><th>Customer</th>
                    <th class="tc">Bills</th><th class="tc">Special</th><th class="tc">Oldest</th>
                    <th class="tr">Ikea Value</th><th class="tr">Cash</th><th class="tr">Cheque</th>
                    <th class="tr">Credit Notes</th><th class="tr">Balance</th><th class="tc">Details</th>
                </tr>
            </thead>
            <tbody>
            <?php $cn=1; foreach ($R['customers'] as $C):
                $cagCls = rcAgingCls($C['max_aging']); $cagLbl = rcAgingLbl($C['max_aging']);
            ?>
            <tr class="<?php echo $C['special']>0?'special-row':''; ?>">
                <td style="color:#9ca3af;font-size:11px;"><?php echo $cn++; ?></td>
                <td><span class="t-code"><?php echo htmlspecialchars($C['t_code']); ?></span></td>
                <td>
                    <?php echo htmlspecialchars($C['customer_name']); ?>
                    <?php if($C['special']>0): ?><br><span class="special-badge"><i class="fa-solid fa-star" style="font-size:8px;"></i> Special ×<?php echo $C['special']; ?></span><?php endif; ?>
                    <?php if($C['unverified']>0): ?> <span class="unv-badge"><i class="fa-solid fa-clock" style="font-size:8px;"></i> <?php echo $C['unverified']; ?> unverified</span><?php endif; ?>
                </td>
                <td class="tc"><?php echo $C['bills']; ?></td>
                <td class="tc"><?php echo $C['special'] ?: '—'; ?></td>
                <td class="tc"><span class="aging-badge <?php echo $cagCls; ?>" style="font-size:10px;padding:2px 7px;"><?php echo $C['max_aging']; ?>d</span></td>
                <td class="tr"><?php echo number_format($C['net'],2); ?></td>
                <td class="tr" style="color:#16a34a;font-weight:700;"><?php echo $C['cash']>0?number_format($C['cash'],2):'<span style="color:#d1d5db;font-weight:400;">—</span>'; ?></td>
                <td class="tr" style="color:#2563eb;font-weight:700;"><?php echo $C['cheque']>0?number_format($C['cheque'],2):'<span style="color:#d1d5db;font-weight:400;">—</span>'; ?></td>
                <td class="tr" style="color:#ea580c;font-weight:700;"><?php echo $C['cn']>0?number_format($C['cn'],2):'<span style="color:#d1d5db;font-weight:400;">—</span>'; ?></td>
                <td class="tr"><span class="balance-amt">Rs. <?php echo number_format($C['balance'],2); ?></span></td>
                <td class="tc"><a class="view-link" target="_blank" href="credit_bill_summary2.php?route=<?php echo urlencode($R['route_code']); ?><?php echo $F['as_at']?'&as_at_date='.urlencode($F['as_at']):''; ?>">View</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="6" style="text-align:right;">Route Total (<?php echo count($R['customers']); ?> customers, <?php echo $R['bills']; ?> bills)</td>
                    <td class="tr"><?php echo number_format($R['net'],2); ?></td>
                    <td class="tr"><?php echo number_format($R['cash'],2); ?></td>
                    <td class="tr"><?php echo number_format($R['cheque'],2); ?></td>
                    <td class="tr"><?php echo number_format($R['cn'],2); ?></td>
                    <td class="tr">Rs. <?php echo number_format($R['balance'],2); ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<script>
$(function(){ $('#routeSelect').select2({placeholder:'— All Routes —',allowClear:true,width:'100%'}); });

function toggleRoute(rid){
    const hd = document.getElementById('hd-'+rid);
    const bd = document.getElementById('body-'+rid);
    if(!hd||!bd) return;
    const opening = !bd.classList.contains('open');
    bd.classList.toggle('open', opening);
    hd.classList.toggle('open', opening);
}

let _allOpen = false;
function toggleAllRoutes(){
    _allOpen = !_allOpen;
    document.querySelectorAll('.route-body').forEach(el=>el.classList.toggle('open', _allOpen));
    document.querySelectorAll('.route-hd').forEach(el=>el.classList.toggle('open', _allOpen));
    const btn = document.getElementById('expandAllBtn');
    if(btn) btn.innerHTML = _allOpen
        ? '<i class="fa-solid fa-down-left-and-up-right-to-center"></i> Collapse All'
        : '<i class="fa-solid fa-up-right-and-down-left-from-center"></i> Expand All';
}

function openPrintView(){
    const params = new URLSearchParams(window.location.search);
    params.set('printview','1');
    window.open('route_wise_credit_summary.php?'+params.toString(),'_blank');
}

function exportCSV(){
    const lines = [['Route Code','Route Name','SR Codes','T Code','Customer','Bills','Special','Unverified','Oldest Aging (d)','Ikea Value','Cash Paid','Cheque Paid','Credit Notes','Balance'].join(',')];
    const esc = v => '"' + String(v||'').replace(/"/g,'""').trim() + '"';
    <?php foreach ($routes as $R):
        $srList = implode(' / ', array_keys($R['sr_codes']));
        foreach ($R['customers'] as $C): ?>
    lines.push([
        <?php echo json_encode($R['route_code']); ?>, <?php echo json_encode($R['route_name']); ?>, esc(<?php echo json_encode($srList); ?>),
        <?php echo json_encode($C['t_code']); ?>, esc(<?php echo json_encode($C['customer_name']); ?>),
        <?php echo $C['bills']; ?>, <?php echo $C['special']; ?>, <?php echo $C['unverified']; ?>, <?php echo $C['max_aging']; ?>,
        <?php echo number_format($C['net'],2,'.',''); ?>, <?php echo number_format($C['cash'],2,'.',''); ?>,
        <?php echo number_format($C['cheque'],2,'.',''); ?>, <?php echo number_format($C['cn'],2,'.',''); ?>, <?php echo number_format($C['balance'],2,'.',''); ?>
    ].join(','));
    <?php endforeach; endforeach; ?>
    const blob = new Blob([lines.join('\n')], {type:'text/csv'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url;
    a.download = 'route_wise_credit_summary_<?php echo date("Ymd_Hi"); ?>.csv';
    a.click(); URL.revokeObjectURL(url);
}
</script>

<?php include 'footer.php'; ?>
