<?php
include 'config.php';

/* ── Ensure credit_notes table exists ── */
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
$chknd = mysqli_query($conn,"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credit_notes' AND COLUMN_NAME='note_date' LIMIT 1");
if($chknd && mysqli_num_rows($chknd)===0) mysqli_query($conn,"ALTER TABLE credit_notes ADD COLUMN `note_date` DATE NOT NULL DEFAULT (CURDATE()) AFTER reason");

/* ── AJAX: Payment History ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'payment_history' && isset($_GET['detail_id'])) {
    header('Content-Type: application/json');
    $detail_id  = intval($_GET['detail_id']);
    $ajax_as_at = trim($_GET['as_at_date'] ?? '');

    $info_sql = "SELECT fsd.invoice_num, fsd.adjust_net_value AS net_value,
                        COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS customer_name,
                        fsd.t_code, fs.delivery_date, fs.route AS route_code, fs.sr_code
                 FROM field_summary_details fsd
                 INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
                 LEFT JOIN customers c ON c.t_code = fsd.t_code
                 WHERE fsd.id = $detail_id LIMIT 1";
    $info_res = mysqli_query($conn, $info_sql);
    $info = $info_res ? mysqli_fetch_assoc($info_res) : null;

    $ajax_date_clause = '';
    if ($ajax_as_at) {
        $ajax_as_at_esc  = mysqli_real_escape_string($conn, $ajax_as_at);
        $ajax_date_clause = "AND ip.payment_date <= '$ajax_as_at_esc'";
    }

    $pay_sql = "SELECT ip.id, ip.payment_method, ip.payment_date, ip.amount, ip.amount_to_bank,
                       ip.reference_no, ip.collected_by, ip.cheque_mode, ip.remarks, ip.payment_source, ip.created_at,
                       DATE_FORMAT(ip.payment_date,'%d %b %Y') AS pay_date_fmt,
                       DATE_FORMAT(ip.created_at,'%d %b %Y %H:%i') AS created_fmt,
                       ipc.cheque_no, ipc.cheque_date, ipc.bank_name, ipc.branch_name,
                       COALESCE(ch.status,'pending') AS cheque_status
                FROM invoice_payments ip
                LEFT JOIN invoice_payment_cheques ipc ON ipc.invoice_payment_id = ip.id
                LEFT JOIN cheques ch ON ch.cheque_no=ipc.cheque_no AND ch.bank_code=ipc.bank_code AND ch.branch_code=ipc.branch_code
                WHERE ip.field_summary_detail_id = $detail_id AND ip.is_reversed = 0 $ajax_date_clause
                ORDER BY ip.payment_date DESC, ip.id DESC";
    $pay_res = mysqli_query($conn, $pay_sql);
    $payments = [];
    $total_paid = 0;
    if ($pay_res) {
        while ($p = mysqli_fetch_assoc($pay_res)) {
            $payments[] = $p;
            if ($p['payment_method'] === 'cash') $total_paid += floatval($p['amount']);
            elseif (strtolower(trim($p['cheque_status'] ?? '')) === 'cleared') $total_paid += floatval($p['amount']);
        }
    }

    $cn_total = 0;
    $cn_res = mysqli_query($conn,"SELECT COALESCE(SUM(amount),0) AS t FROM credit_notes WHERE field_summary_detail_id=$detail_id AND is_deleted=0");
    if($cn_res) $cn_total = floatval(mysqli_fetch_assoc($cn_res)['t']);

    echo json_encode([
        'success'    => true,
        'info'       => $info,
        'payments'   => $payments,
        'total_paid' => $total_paid,
        'total_cn'   => $cn_total,
        'balance'    => $info ? floatval($info['net_value']) - $total_paid - $cn_total : 0,
        'as_at_date' => $ajax_as_at
    ]);
    exit;
}

/* ══════════════════════════════════════════════════════════════
   PRINT VIEW
══════════════════════════════════════════════════════════════ */
if (isset($_GET['printview'])) {
    $f_route = trim($_GET['route']         ?? '');
    $f_sr    = trim($_GET['sr_code']       ?? '');
    $f_date  = trim($_GET['delivery_date'] ?? '');
    $f_as_at = trim($_GET['as_at_date']    ?? '');

    $pv_where = ["fsd.updated = 1"];
    if ($f_route) $pv_where[] = "fs.route = '"         . mysqli_real_escape_string($conn,$f_route) . "'";
    if ($f_sr)    $pv_where[] = "fs.sr_code = '"       . mysqli_real_escape_string($conn,$f_sr)    . "'";
    if ($f_date)  $pv_where[] = "fs.delivery_date = '" . mysqli_real_escape_string($conn,$f_date)  . "'";
    if ($f_as_at) $pv_where[] = "fs.delivery_date <= '". mysqli_real_escape_string($conn,$f_as_at) . "'";
    $pv_where_sql = implode(' AND ', $pv_where);

    $pv_pay_date_filter = $f_as_at
        ? "WHERE is_reversed = 0 AND payment_date <= '" . mysqli_real_escape_string($conn,$f_as_at) . "'"
        : "WHERE is_reversed = 0";

    $pv_sql = "
    SELECT fsd.id AS detail_id, fs.route AS route_code,
           COALESCE(r.route_name, fs.route) AS route_name,
           fs.sr_code, fs.delivery_date,
           DATEDIFF(" . ($f_as_at ? "'" . mysqli_real_escape_string($conn,$f_as_at) . "'" : "CURDATE()") . ", fs.delivery_date) AS aging_days,
           fsd.t_code,
           COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS customer_name,
           fsd.invoice_num,
           fsd.adjust_net_value AS net_value,
           COALESCE(pay.total_paid,0)   AS paid,
           COALESCE(pay.cash_paid,0)    AS cash_paid,
           COALESCE(pay.cheque_paid,0)  AS cheque_paid,
           COALESCE(cn.total_cn,0)      AS total_cn,
           (fsd.adjust_net_value - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) AS balance,
           CASE WHEN cr.detail_id IS NOT NULL THEN 1 ELSE 0 END AS is_special
    FROM field_summary_details fsd
    INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
    LEFT  JOIN routes r   ON r.route_code = fs.route
    LEFT  JOIN customers c ON c.t_code = fsd.t_code
    LEFT  JOIN (
        SELECT field_summary_detail_id,
               SUM(amount) AS total_paid,
               SUM(CASE WHEN payment_method='cash'   THEN amount ELSE 0 END) AS cash_paid,
               SUM(CASE WHEN payment_method='cheque' THEN amount ELSE 0 END) AS cheque_paid
        FROM   invoice_payments $pv_pay_date_filter
        GROUP  BY field_summary_detail_id
    ) pay ON pay.field_summary_detail_id = fsd.id
    LEFT  JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_cn
        FROM   credit_notes WHERE is_deleted=0
        GROUP  BY field_summary_detail_id
    ) cn ON cn.field_summary_detail_id = fsd.id
    LEFT  JOIN (SELECT field_summary_detail_id AS detail_id FROM credit_requests GROUP BY field_summary_detail_id) cr ON cr.detail_id = fsd.id
    WHERE $pv_where_sql AND (fsd.adjust_net_value - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) > 0
    ORDER BY aging_days DESC, balance DESC, fs.delivery_date ASC, fs.route, fs.sr_code, fsd.invoice_num";

    $pv_res  = mysqli_query($conn, $pv_sql);
    $pv_rows = [];
    $pv_net = $pv_paid = $pv_bal = $pv_cash = $pv_cheque = $pv_cn = 0;
    $pv_special = $pv_normal = 0;
    if ($pv_res) {
        while ($pv_r = mysqli_fetch_assoc($pv_res)) {
            $pv_rows[] = $pv_r;
            $pv_net    += floatval($pv_r['net_value']);
            $pv_paid   += floatval($pv_r['paid']);
            $pv_cash   += floatval($pv_r['cash_paid']);
            $pv_cheque += floatval($pv_r['cheque_paid']);
            $pv_cn     += floatval($pv_r['total_cn']);
            $pv_bal    += floatval($pv_r['balance']);
            if ($pv_r['is_special']) $pv_special++; else $pv_normal++;
        }
    }
    $pv_count = count($pv_rows);
    function pvAgingCls($d){ if($d>=90)return'aging-red'; if($d>=60)return'aging-orange'; if($d>=30)return'aging-yellow'; return'aging-green'; }
    function pvAgingLbl($d){ if($d>=90)return'Critical'; if($d>=60)return'Overdue'; if($d>=30)return'Warning'; return'Fresh'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Credit Bill Summary &mdash; Print</title>
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
table{width:100%;border-collapse:collapse;font-size:8.5px;table-layout:fixed;}
thead th{background:#1e1b4b;color:#fff;padding:5px 4px;font-size:8px;font-weight:700;border:1px solid #334155;white-space:nowrap;text-align:left;}
thead th.tc{text-align:center;}thead th.tr{text-align:right;}
tbody tr{border-bottom:1px solid #e5e5e5;page-break-inside:avoid;}
tbody tr.normal-row td{background:#fff;}
tbody tr.special-row td{background:#fefce8;}
td{padding:4px 4px;border:1px solid #e8e8e8;vertical-align:middle;word-break:break-word;color:#111;}
td.tc{text-align:center;}td.tr{text-align:right;}
tfoot tr{page-break-inside:avoid;}
tfoot td{background:#1e1b4b !important;color:#fff !important;padding:6px 4px;font-size:9px;font-weight:900;border:1px solid #334155;}
tfoot td.tr{text-align:right;}
th:nth-child(1){width:18px;}th:nth-child(2){width:42px;}th:nth-child(3){width:34px;}
th:nth-child(4){width:72px;}th:nth-child(5){width:96px;}th:nth-child(6){width:66px;}
th:nth-child(7){width:50px;}th:nth-child(8){width:28px;}th:nth-child(9){width:54px;}
th:nth-child(10){width:48px;}th:nth-child(11){width:48px;}th:nth-child(12){width:48px;}th:nth-child(13){width:52px;}
.special-badge{color:#854d0e;font-weight:700;font-size:7.5px;}
.sr-code{color:#5b21b6;font-weight:700;}.t-code{color:#1e40af;font-weight:700;font-family:monospace;}
.inv-num{font-family:monospace;font-weight:700;color:#1e1b4b;font-size:8px;}
.balance{color:#dc2626;font-weight:700;}.paid-val{color:#16a34a;font-weight:700;}.cheque-val{color:#2563eb;font-weight:700;}.cn-val{color:#ea580c;font-weight:700;}
.date-val{font-size:8px;}.aging-val{font-weight:700;font-size:8.5px;}
.aging-green{color:#16a34a;}.aging-yellow{color:#d97706;}.aging-orange{color:#ea580c;}.aging-red{color:#dc2626;}
.rn{color:#9ca3af;font-size:8px;text-align:center;}.route-name{font-size:7.5px;color:#6b7280;}
@media screen{body{background:#e2e8f0;}.page-preview{background:#fff;width:297mm;min-height:210mm;margin:16px auto;padding:8mm;box-shadow:0 4px 24px rgba(0,0,0,.18);border-radius:4px;}}
@media print{
    .print-btn-bar{display:none !important;}
    .page-preview{margin:0 !important;padding:0 !important;box-shadow:none !important;width:auto !important;}
    body{background:#fff !important;}
    tbody tr.special-row td{background:#fefce8 !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    tfoot td{background:#1e1b4b !important;color:#fff !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    thead th{background:#1e1b4b !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}
</style>
</head>
<body>
<div class="print-btn-bar">
    <h1>&#128438; Credit Bill Summary &mdash; Print Preview</h1>
    <div class="btns">
        <button class="pbtn pbtn-print" onclick="window.print()">&#128438;&nbsp;Print / Save PDF</button>
        <button class="pbtn pbtn-close" onclick="window.close()">&#10005;&nbsp;Close</button>
    </div>
</div>
<div class="page-preview">
<div class="rpt-header">
    <div class="rpt-header-title">Credit Bill Summary Report</div>
    <div class="rpt-meta">
        <?php if($f_route): ?><span><strong>Route:</strong> <?php echo htmlspecialchars($f_route); ?></span><?php endif; ?>
        <?php if($f_sr):    ?><span><strong>SR Code:</strong> <?php echo htmlspecialchars($f_sr); ?></span><?php endif; ?>
        <?php if($f_date):  ?><span><strong>Delivery Date:</strong> <?php echo date('d M Y',strtotime($f_date)); ?></span><?php endif; ?>
        <?php if($f_as_at): ?><span><strong>As At:</strong> <?php echo date('d M Y',strtotime($f_as_at)); ?></span><?php endif; ?>
        <?php if(!$f_route && !$f_sr && !$f_date && !$f_as_at): ?><span><strong>Scope:</strong> All Records</span><?php endif; ?>
        <span><strong>Printed:</strong> <?php echo date('d M Y, H:i'); ?></span>
    </div>
    <div class="rpt-summary">
        <div class="rpt-sum-box"><span class="lbl">Invoices</span><span class="val"><?php echo $pv_count; ?></span></div>
        <div class="rpt-sum-box"><span class="lbl">Special</span><span class="val"><?php echo $pv_special; ?></span></div>
        <div class="rpt-sum-box"><span class="lbl">Normal</span><span class="val"><?php echo $pv_normal; ?></span></div>
        <div class="rpt-sum-box"><span class="lbl">Net Value</span><span class="val">Rs.&nbsp;<?php echo number_format($pv_net,2); ?></span></div>
        <div class="rpt-sum-box green"><span class="lbl">Cash Paid</span><span class="val">Rs.&nbsp;<?php echo number_format($pv_cash,2); ?></span></div>
        <div class="rpt-sum-box blue"><span class="lbl">Cheque Paid</span><span class="val">Rs.&nbsp;<?php echo number_format($pv_cheque,2); ?></span></div>
        <?php if($pv_cn > 0): ?>
        <div class="rpt-sum-box orange"><span class="lbl">Credit Notes</span><span class="val">Rs.&nbsp;<?php echo number_format($pv_cn,2); ?></span></div>
        <?php endif; ?>
        <div class="rpt-sum-box red"><span class="lbl">Total Balance</span><span class="val">Rs.&nbsp;<?php echo number_format($pv_bal,2); ?></span></div>
        <?php if($f_as_at): ?>
        <div class="as-at-notice">&#9200; Payments counted up to <?php echo date('d M Y',strtotime($f_as_at)); ?> only</div>
        <?php endif; ?>
    </div>
</div>
<table>
    <thead>
        <tr>
            <th class="tc">No</th><th>T Code</th><th class="tc">SR</th><th>Route</th>
            <th>Customer</th><th>Invoice No.</th><th class="tc">Del. Date</th>
            <th class="tc">Aging</th>
            <th class="tr">Net Value</th>
            <th class="tr">Cash<?php echo $f_as_at?' (as at)':''; ?></th>
            <th class="tr">Cheque<?php echo $f_as_at?' (as at)':''; ?></th>
            <th class="tr" style="color:#fed7aa;">Credit Notes</th>
            <th class="tr">Balance<?php echo $f_as_at?' (as at)':''; ?></th>
        </tr>
    </thead>
    <tbody>
    <?php $pvn=1; foreach($pv_rows as $pv_r):
        $pvNet    = floatval($pv_r['net_value']);
        $pvCash   = floatval($pv_r['cash_paid']);
        $pvCheque = floatval($pv_r['cheque_paid']);
        $pvCN     = floatval($pv_r['total_cn']);
        $pvBal    = floatval($pv_r['balance']);
        $pvAging  = max(0, intval($pv_r['aging_days']));
        $pvDelf   = $pv_r['delivery_date'] ? date('d M Y', strtotime($pv_r['delivery_date'])) : '&mdash;';
        $pvRowCls = $pv_r['is_special'] ? 'special-row' : 'normal-row';
        $pvAgCls  = pvAgingCls($pvAging);
        $pvAgLbl  = pvAgingLbl($pvAging);
    ?>
    <tr class="<?php echo $pvRowCls; ?>">
        <td class="rn"><?php echo $pvn++; ?></td>
        <td><span class="t-code"><?php echo htmlspecialchars($pv_r['t_code']); ?></span></td>
        <td class="tc"><span class="sr-code"><?php echo htmlspecialchars($pv_r['sr_code']); ?></span></td>
        <td><div style="font-weight:700;font-size:8.5px;"><?php echo htmlspecialchars($pv_r['route_code']); ?></div><div class="route-name"><?php echo htmlspecialchars($pv_r['route_name']); ?></div></td>
        <td style="font-size:8.5px;"><?php echo htmlspecialchars($pv_r['customer_name']); ?><?php if($pv_r['is_special']): ?><br><span class="special-badge">&#9733; Special</span><?php endif; ?></td>
        <td><span class="inv-num"><?php echo htmlspecialchars($pv_r['invoice_num']); ?></span></td>
        <td class="tc date-val"><?php echo $pvDelf; ?></td>
        <td class="tc"><span class="aging-val <?php echo $pvAgCls; ?>" title="<?php echo $pvAgLbl; ?>"><?php echo $pvAging; ?>d</span></td>
        <td class="tr"><?php echo number_format($pvNet,2); ?></td>
        <td class="tr <?php echo $pvCash>0?'paid-val':''; ?>"><?php echo $pvCash>0 ? number_format($pvCash,2) : '&mdash;'; ?></td>
        <td class="tr <?php echo $pvCheque>0?'cheque-val':''; ?>"><?php echo $pvCheque>0 ? number_format($pvCheque,2) : '&mdash;'; ?></td>
        <td class="tr <?php echo $pvCN>0?'cn-val':''; ?>"><?php echo $pvCN>0 ? number_format($pvCN,2) : '&mdash;'; ?></td>
        <td class="tr balance">Rs.&nbsp;<?php echo number_format($pvBal,2); ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="8" style="text-align:right;font-size:8px;letter-spacing:.03em;opacity:.85;">
                TOTAL &mdash; <?php echo $pv_count; ?> invoice<?php echo $pv_count!=1?'s':''; ?>
                &nbsp;(<?php echo $pv_special; ?> special, <?php echo $pv_normal; ?> normal)
                <?php if($f_as_at): ?>&nbsp;&mdash; Payments as at <?php echo date('d M Y',strtotime($f_as_at)); ?><?php endif; ?>
            </td>
            <td class="tr">Rs.&nbsp;<?php echo number_format($pv_net,2); ?></td>
            <td class="tr">Rs.&nbsp;<?php echo number_format($pv_cash,2); ?></td>
            <td class="tr">Rs.&nbsp;<?php echo number_format($pv_cheque,2); ?></td>
            <td class="tr">Rs.&nbsp;<?php echo number_format($pv_cn,2); ?></td>
            <td class="tr">Rs.&nbsp;<?php echo number_format($pv_bal,2); ?></td>
        </tr>
    </tfoot>
</table>
</div>
<script>window.addEventListener('load',function(){window.print();});</script>
</body>
</html>
<?php
    exit;
}

include 'header.php';

/* ── FILTER OPTIONS ── */
$routes_res = mysqli_query($conn,"SELECT route_code, route_name FROM routes WHERE active=1 ORDER BY route_name");
$all_routes = [];
while ($r = mysqli_fetch_assoc($routes_res)) $all_routes[] = $r;

$sr_res = mysqli_query($conn,"SELECT DISTINCT sr_code FROM field_summary ORDER BY sr_code");
$all_sr = [];
while ($r = mysqli_fetch_assoc($sr_res)) $all_sr[] = $r['sr_code'];

/* ── READ FILTERS ── */
$f_route = trim($_GET['route']         ?? '');
$f_sr    = trim($_GET['sr_code']       ?? '');
$f_date  = trim($_GET['delivery_date'] ?? '');
$f_as_at = trim($_GET['as_at_date']    ?? '');

/* ── QUERY ── */
$rows = [];
$t_net = $t_paid = $t_balance = $t_cash = $t_cheque = $t_cn = 0;
$t_special = $t_normal = $total_count = 0;

$where = ["fsd.updated = 1"];
if ($f_route) $where[] = "fs.route = '"         . mysqli_real_escape_string($conn,$f_route) . "'";
if ($f_sr)    $where[] = "fs.sr_code = '"       . mysqli_real_escape_string($conn,$f_sr)    . "'";
if ($f_date)  $where[] = "fs.delivery_date = '" . mysqli_real_escape_string($conn,$f_date)  . "'";
if ($f_as_at) $where[] = "fs.delivery_date <= '". mysqli_real_escape_string($conn,$f_as_at) . "'";
$where_sql = implode(' AND ',$where);

$pay_date_filter = $f_as_at
    ? "WHERE is_reversed = 0 AND payment_date <= '" . mysqli_real_escape_string($conn,$f_as_at) . "'"
    : "WHERE is_reversed = 0";

$aging_base = $f_as_at
    ? "'" . mysqli_real_escape_string($conn,$f_as_at) . "'"
    : "CURDATE()";

/* ensure bill_verified column exists */
$cv = mysqli_query($conn,"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credit_requests' AND COLUMN_NAME='bill_verified' LIMIT 1");
if($cv && mysqli_num_rows($cv)===0) mysqli_query($conn,"ALTER TABLE credit_requests ADD COLUMN `bill_verified` TINYINT(1) NOT NULL DEFAULT 0");

$sql = "
SELECT
    fsd.id                                                                              AS detail_id,
    fs.id                                                                               AS fs_id,
    fs.field_summary_code,
    fs.route                                                                            AS route_code,
    fs.delivery_date,
    DATEDIFF($aging_base, fs.delivery_date)                                            AS aging_days,
    COALESCE(r.route_name, fs.route)                                                    AS route_name,
    fs.sr_code,
    fsd.t_code,
    COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code)                    AS customer_name,
    fsd.invoice_num,
    fsd.adjust_net_value                                                                AS net_value,
    COALESCE(pay.total_paid,  0)                                                        AS paid,
    COALESCE(pay.cash_paid,   0)                                                        AS cash_paid,
    COALESCE(pay.cheque_paid, 0)                                                        AS cheque_paid,
    COALESCE(cn.total_cn,     0)                                                        AS total_cn,
    (fsd.adjust_net_value - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0))      AS balance,
    CASE WHEN cr.detail_id IS NOT NULL THEN 1 ELSE 0 END                               AS is_special,
    COALESCE(cr.cr_count, 0)                                                            AS cr_count,
    COALESCE(cr.verified_count, 0)                                                      AS verified_count,
    COALESCE(cr.cr_count,0) - COALESCE(cr.verified_count,0)                            AS unverified_count,
    COALESCE(bi.bill_count, 0)                                                          AS bill_count,
    fsd.bill_verified                                                                   AS bill_verified
FROM field_summary_details fsd
INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
LEFT  JOIN routes    r   ON r.route_code  = fs.route
LEFT  JOIN customers c   ON c.t_code      = fsd.t_code
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
LEFT  JOIN (
    SELECT field_summary_detail_id AS detail_id,
           COUNT(*) AS cr_count,
           SUM(COALESCE(bill_verified,0)) AS verified_count
    FROM   credit_requests
    GROUP  BY field_summary_detail_id
) cr ON cr.detail_id = fsd.id
LEFT  JOIN (
    SELECT field_summary_detail_id, COUNT(*) AS bill_count
    FROM   credit_bill_images GROUP BY field_summary_detail_id
) bi ON bi.field_summary_detail_id = fsd.id
WHERE $where_sql
  AND (fsd.adjust_net_value - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) > 0
ORDER BY aging_days DESC, balance DESC, fs.delivery_date ASC, fs.route, fs.sr_code, fsd.invoice_num
";

$result = mysqli_query($conn, $sql);
if (!$result) {
    $sql2 = str_replace(
        "    LEFT  JOIN (\n        SELECT field_summary_detail_id, COUNT(*) AS bill_count\n        FROM   credit_bill_images GROUP BY field_summary_detail_id\n    ) bi ON bi.field_summary_detail_id = fsd.id",
        "    -- credit_bill_images not yet created", $sql);
    $sql2 = str_replace("    COALESCE(bi.bill_count, 0)                                                          AS bill_count","    0 AS bill_count",$sql2);
    $result = mysqli_query($conn, $sql2);
}
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[]    = $row;
        $t_net     += floatval($row['net_value']);
        $t_paid    += floatval($row['paid']);
        $t_cash    += floatval($row['cash_paid']);
        $t_cheque  += floatval($row['cheque_paid']);
        $t_cn      += floatval($row['total_cn']);
        $t_balance += floatval($row['balance']);
        if ($row['is_special']) $t_special++; else $t_normal++;
    }
}
$total_count = count($rows);
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
*{box-sizing:border-box;}
.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:18px 20px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.filter-title{font-size:13px;font-weight:700;color:#374151;margin-bottom:14px;display:flex;align-items:center;gap:6px;}
.filter-grid{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;}
.filter-inputs{display:grid;grid-template-columns:1fr 1fr 180px 180px;gap:12px;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.fg input,.fg select{border:1px solid #e5e5e5;border-radius:7px;padding:8px 11px;font-size:13px;font-family:'Inter',sans-serif;color:#1f2937;width:100%;transition:border .2s;}
.fg input:focus,.fg select:focus{outline:none;border-color:#6366f1;}

.search-bar-wrap{display:flex;align-items:center;gap:10px;background:#fff;border:1.5px solid #6366f1;border-radius:10px;padding:8px 14px;margin-bottom:16px;box-shadow:0 2px 10px rgba(99,102,241,.1);}
.search-bar-wrap i{color:#6366f1;font-size:15px;flex-shrink:0;}
#liveSearch{border:none;outline:none;flex:1;font-size:14px;font-family:'Inter',sans-serif;color:#1f2937;background:transparent;}
#liveSearch::placeholder{color:#9ca3af;}
#searchClear{background:none;border:none;color:#9ca3af;cursor:pointer;font-size:14px;padding:0;line-height:1;display:none;}
#searchClear:hover{color:#dc2626;}
#searchMatchCount{font-size:11px;font-weight:700;color:#6366f1;white-space:nowrap;flex-shrink:0;background:#ede9fe;padding:2px 10px;border-radius:20px;}

.as-at-banner{display:flex;align-items:center;gap:8px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:8px 14px;margin-bottom:14px;font-size:12px;font-weight:600;color:#92400e;}

.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;text-decoration:none;transition:all .2s;white-space:nowrap;}
.btn-primary{background:#6366f1;color:#fff;}.btn-primary:hover{background:#4f46e5;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-success{background:#16a34a;color:#fff;}.btn-success:hover{background:#15803d;}
.btn-bill{background:#0f172a;color:#fff;padding:5px 10px;font-size:11px;border-radius:6px;gap:4px;}.btn-bill:hover{background:#1e293b;}
.btn-history{background:#0e7490;color:#fff;padding:5px 10px;font-size:11px;border-radius:6px;gap:4px;}.btn-history:hover{background:#0c6080;}
.btn-cn{background:#c2410c;color:#fff;padding:5px 10px;font-size:11px;border-radius:6px;gap:4px;}.btn-cn:hover{background:#9a3412;}
.btn-cn.has-notes{background:#ea580c;box-shadow:0 0 0 2px rgba(234,88,12,.3);}
.btn-sm{padding:5px 11px;font-size:11px;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}

/* STAT CARDS — 7 cards */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:14px;margin-bottom:20px;}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px;}
.stat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.stat-value{font-size:18px;font-weight:800;color:#1f2937;}
.stat-value.red{color:#dc2626;}.stat-value.green{color:#16a34a;}.stat-value.blue{color:#2563eb;}.stat-value.amber{color:#d97706;}.stat-value.orange{color:#ea580c;}

.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;}
.pill-violet{background:#ede9fe;color:#5b21b6;}.pill-blue{background:#dbeafe;color:#1e40af;}.pill-green{background:#dcfce7;color:#166534;}
.legend{display:flex;align-items:center;gap:14px;font-size:11px;font-weight:600;color:#6b7280;}
.legend-item{display:flex;align-items:center;gap:5px;}
.ldot{width:11px;height:11px;border-radius:3px;flex-shrink:0;}
.ldot-y{background:#fef08a;border:1px solid #facc15;}.ldot-w{background:#fff;border:1px solid #d1d5db;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead th{padding:9px 8px;text-align:left;font-weight:700;font-size:11px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);}
.data-table thead th:last-child{border-right:none;}
.data-table thead th.tr{text-align:right;}.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr.normal-row td{background:#fff;}.data-table tbody tr.normal-row:hover td{background:#f9fafb;}
.data-table tbody tr.special-row td{background:#fefce8;}.data-table tbody tr.special-row:hover td{background:#fef9c3;}
.data-table tbody tr.row-hidden{display:none !important;}
.data-table tbody td mark{background:#fef08a;color:#111;border-radius:2px;padding:0 1px;}
.data-table td{padding:7px 8px;color:#374151;vertical-align:middle;}
.tr{text-align:right;}.tc{text-align:center;}
.data-table tfoot td{padding:10px 8px;font-weight:800;font-size:13px;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155;}
.data-table tfoot td.tr{text-align:right;}
.no-results-row{display:none;}
.no-results-row td{text-align:center;padding:30px;color:#9ca3af;font-size:13px;font-style:italic;}
.sr-pill{background:#ede9fe;color:#5b21b6;padding:2px 7px;border-radius:9px;font-size:11px;font-weight:700;}
.special-badge{background:#fef08a;color:#854d0e;border:1px solid #fde047;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.balance-amt{font-weight:700;color:#dc2626;}
.verified-badge{background:#dcfce7;color:#166534;border:1px solid #86efac;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.unverified-badge{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.bill-count-badge{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.date-badge{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;white-space:nowrap;}
.cn-badge{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;white-space:nowrap;}

.aging-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;border:1px solid;}
.aging-green {background:#f0fdf4;color:#16a34a;border-color:#bbf7d0;}
.aging-yellow{background:#fefce8;color:#d97706;border-color:#fde68a;}
.aging-orange{background:#fff7ed;color:#ea580c;border-color:#fed7aa;}
.aging-red   {background:#fef2f2;color:#dc2626;border-color:#fecaca;}

.inv-link{font-family:monospace;font-size:12px;font-weight:700;color:#4338ca;cursor:pointer;text-decoration:none;border-bottom:1px dashed #a5b4fc;padding-bottom:1px;transition:all .2s;}
.inv-link:hover{color:#6366f1;border-bottom-color:#6366f1;background:#ede9fe;border-radius:3px;padding:1px 4px;margin:-1px -4px;}

/* ── BALANCE BREAKDOWN in table cell ── */
.bal-breakdown{font-size:9.5px;color:#ea580c;font-weight:600;margin-top:2px;display:flex;align-items:center;gap:3px;}

/* ═══════════════════════════════════════
   PAYMENT HISTORY MODAL
═══════════════════════════════════════ */
#payHistoryModal{display:none;position:fixed;inset:0;z-index:99998;background:rgba(10,14,26,.85);align-items:center;justify-content:center;padding:20px;}
#payHistoryModal.open{display:flex;}
.ph-modal{background:#fff;border-radius:14px;width:100%;max-width:720px;max-height:85vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.4);animation:phSlideUp .25s ease-out;}
@keyframes phSlideUp{from{opacity:0;transform:translateY(30px);}to{opacity:1;transform:translateY(0);}}
.ph-header{background:linear-gradient(135deg,#1e1b4b,#312e81);color:#fff;padding:16px 20px;display:flex;align-items:center;gap:12px;border-bottom:2px solid #4338ca;flex-shrink:0;}
.ph-header-icon{width:40px;height:40px;background:rgba(255,255,255,.12);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.ph-header-text{flex:1;}.ph-header-text h3{margin:0;font-size:15px;font-weight:800;}.ph-header-text p{margin:2px 0 0;font-size:11px;color:#c7d2fe;font-weight:500;}
.ph-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:34px;height:34px;border-radius:8px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:background .2s;}
.ph-close:hover{background:rgba(220,38,38,.8);}
.ph-body{padding:18px 20px;overflow-y:auto;flex:1;}
.ph-summary{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:18px;}
.ph-sum-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;text-align:center;}
.ph-sum-card .ph-sum-label{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.ph-sum-card .ph-sum-val{font-size:16px;font-weight:800;}
.ph-sum-val.text-net{color:#1f2937;}.ph-sum-val.text-paid{color:#16a34a;}.ph-sum-val.text-cn{color:#ea580c;}.ph-sum-val.text-bal{color:#dc2626;}
.ph-as-at-note{display:flex;align-items:center;gap:7px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:7px 12px;margin-bottom:14px;font-size:11px;font-weight:600;color:#92400e;}
.ph-table{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:4px;}
.ph-table thead th{padding:9px 10px;text-align:left;font-weight:700;font-size:11px;color:#64748b;background:#f1f5f9;border-bottom:2px solid #e2e8f0;text-transform:uppercase;letter-spacing:.04em;}
.ph-table thead th.tr{text-align:right;}.ph-table thead th.tc{text-align:center;}
.ph-table tbody td{padding:10px 10px;border-bottom:1px solid #f1f5f9;color:#374151;}
.ph-table tbody tr:hover td{background:#f8fafc;}
.ph-table tbody td.tr{text-align:right;}.ph-table tbody td.tc{text-align:center;}
.ph-table tfoot td{padding:10px 10px;font-weight:800;font-size:13px;background:#f8fafc;border-top:2px solid #e2e8f0;color:#1f2937;}
.ph-table tfoot td.tr{text-align:right;}
.ph-pay-method{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:9px;font-size:10px;font-weight:700;}
.ph-method-cash{background:#dcfce7;color:#166534;}.ph-method-cheque{background:#dbeafe;color:#1e40af;}.ph-method-bank{background:#fef3c7;color:#92400e;}.ph-method-other{background:#f3f4f6;color:#374151;}
.ph-cheque-status{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.ph-status-pending{background:#fef3c7;color:#92400e;}.ph-status-cleared{background:#dcfce7;color:#166534;}.ph-status-bounced{background:#fef2f2;color:#dc2626;}
.ph-empty{text-align:center;padding:40px 20px;color:#94a3b8;}
.ph-empty i{font-size:36px;display:block;margin-bottom:10px;opacity:.4;}
.ph-loading{text-align:center;padding:40px;color:#6366f1;font-size:14px;}

/* ═══════════════════════════════════════
   CREDIT NOTE MODAL
═══════════════════════════════════════ */
#cnModal{display:none;position:fixed;inset:0;z-index:99999;background:rgba(10,14,26,.88);align-items:center;justify-content:center;padding:20px;}
#cnModal.open{display:flex;}
.cn-modal{background:#fff;border-radius:14px;width:100%;max-width:640px;max-height:88vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.45);animation:phSlideUp .25s ease-out;}
.cn-header{background:linear-gradient(135deg,#7c2d12,#c2410c);color:#fff;padding:16px 20px;display:flex;align-items:center;gap:12px;border-bottom:2px solid #ea580c;flex-shrink:0;}
.cn-header-icon{width:40px;height:40px;background:rgba(255,255,255,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.cn-header-text{flex:1;}.cn-header-text h3{margin:0;font-size:15px;font-weight:800;}.cn-header-text p{margin:2px 0 0;font-size:11px;color:#fed7aa;font-weight:500;}
.cn-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:34px;height:34px;border-radius:8px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:background .2s;}
.cn-close:hover{background:rgba(220,38,38,.8);}
.cn-body{padding:20px;overflow-y:auto;flex:1;}
.cn-summary{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:18px;}
.cn-sum-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;text-align:center;}
.cn-sum-card .cn-sum-label{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.cn-sum-card .cn-sum-val{font-size:16px;font-weight:800;}
.cn-sum-val.cn-net{color:#1f2937;}.cn-sum-val.cn-paid{color:#16a34a;}.cn-sum-val.cn-cn{color:#ea580c;}.cn-sum-val.cn-bal{color:#dc2626;}
/* CN list */
.cn-list{margin-bottom:18px;}
.cn-list-title{font-size:12px;font-weight:700;color:#374151;margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.cn-item{background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:12px 14px;margin-bottom:8px;display:flex;align-items:flex-start;gap:12px;}
.cn-item-icon{width:36px;height:36px;background:#ea580c;border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;flex-shrink:0;}
.cn-item-body{flex:1;}
.cn-item-top{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;}
.cn-item-amount{font-size:15px;font-weight:800;color:#c2410c;}
.cn-item-date{font-size:11px;font-weight:600;color:#92400e;background:#fef3c7;padding:2px 8px;border-radius:12px;}
.cn-item-reason{font-size:12px;color:#78350f;font-style:italic;}
.cn-item-created{font-size:10px;color:#d97706;margin-top:3px;}
.cn-item-del{background:none;border:none;color:#d97706;cursor:pointer;padding:4px;border-radius:6px;transition:all .2s;flex-shrink:0;align-self:center;}
.cn-item-del:hover{background:#fef2f2;color:#dc2626;}
.cn-empty{text-align:center;padding:28px 20px;color:#94a3b8;border:2px dashed #e5e7eb;border-radius:10px;margin-bottom:18px;}
.cn-empty i{font-size:32px;display:block;margin-bottom:8px;opacity:.4;}
/* Add form */
.cn-add-box{background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px 18px;}
.cn-add-title{font-size:12px;font-weight:700;color:#374151;margin-bottom:14px;display:flex;align-items:center;gap:7px;text-transform:uppercase;letter-spacing:.04em;}
.cn-form-row{display:grid;grid-template-columns:140px 1fr 150px;gap:10px;margin-bottom:10px;}
.cn-fg{display:flex;flex-direction:column;gap:5px;}
.cn-fg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;}
.cn-fg input,.cn-fg textarea{border:1px solid #d1d5db;border-radius:7px;padding:8px 11px;font-size:13px;font-family:'Inter',sans-serif;color:#1f2937;width:100%;transition:border .2s;background:#fff;}
.cn-fg input:focus,.cn-fg textarea:focus{outline:none;border-color:#ea580c;box-shadow:0 0 0 3px rgba(234,88,12,.1);}
.cn-fg textarea{resize:vertical;min-height:38px;}
.btn-add-cn{background:#c2410c;color:#fff;border:none;border-radius:7px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s;width:100%;justify-content:center;}
.btn-add-cn:hover{background:#9a3412;}
.btn-add-cn:disabled{opacity:.6;cursor:not-allowed;}

/* ═══════════════════════════════════════
   FULLSCREEN BILL MODAL (unchanged)
═══════════════════════════════════════ */
#billModal{display:none;position:fixed !important;top:0 !important;left:0 !important;width:100vw !important;height:100vh !important;z-index:100000 !important;background:rgba(10,14,26,.97) !important;margin:0 !important;padding:0 !important;border:none !important;overflow:hidden !important;flex-direction:column;}
#billModal.open{display:flex !important;}
body.modal-open{overflow:hidden !important;}
.bill-modal{background:#1e2535;width:100%;flex:1;display:flex;flex-direction:column;overflow:hidden;min-height:0;}
#billModalClose{position:fixed;top:12px;right:14px;z-index:100001;background:#dc2626;border:none;color:#fff;width:40px;height:40px;border-radius:50%;cursor:pointer;font-size:20px;font-weight:700;display:none;align-items:center;justify-content:center;box-shadow:0 4px 20px rgba(220,38,38,.7);transition:all .2s;line-height:1;}
#billModal.open ~ #billModalClose,.cn-close.show{display:flex !important;}
#billModalClose.show{display:flex !important;}
#billModalClose:hover{background:#b91c1c;transform:scale(1.1);}
.bm-header{background:#0f172a;color:#fff;padding:10px 60px 10px 20px;display:flex;align-items:center;flex-shrink:0;gap:12px;border-bottom:2px solid #334155;min-height:52px;}
.bm-header-info{display:flex;align-items:center;gap:12px;flex-wrap:wrap;flex:1;}
.bm-title{font-size:15px;font-weight:800;letter-spacing:.02em;}.bm-subtitle{font-size:11px;color:#94a3b8;}
.bm-body{display:grid;grid-template-columns:1fr 1.15fr 1fr;flex:1;overflow:hidden;min-height:0;height:calc(100vh - 52px);}
.bm-panel{padding:14px 16px;overflow-y:auto;border-right:1px solid #2d3748;display:flex;flex-direction:column;min-height:0;}
.bm-panel:last-child{border-right:none;}
.bm-panel-title{font-size:11px;font-weight:800;color:#e2e8f0;margin-bottom:10px;display:flex;align-items:center;gap:7px;padding-bottom:8px;border-bottom:2px solid #334155;text-transform:uppercase;letter-spacing:.06em;flex-shrink:0;}
.emg-banner{background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border-radius:8px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;gap:10px;font-size:12px;font-weight:700;}
.emg-banner .cr-count{background:rgba(255,255,255,.25);padding:2px 10px;border-radius:20px;font-size:11px;margin-left:auto;}
.upload-zone{border:2px dashed #475569;border-radius:10px;background:#1a2235;padding:18px;text-align:center;cursor:pointer;transition:all .2s;margin-bottom:12px;}
.upload-zone:hover,.upload-zone.drag{border-color:#6366f1;background:#1e1b3a;}
.upload-zone i{font-size:26px;color:#64748b;display:block;margin-bottom:8px;}
.upload-zone p{font-size:12px;color:#94a3b8;margin:0;}
.upload-zone input[type=file]{display:none;}
.bill-display-area{background:#111827;border-radius:10px;border:1px solid #334155;overflow:hidden;position:relative;margin-bottom:12px;min-height:320px;display:flex;align-items:center;justify-content:center;}
.bill-display-area img{width:100%;height:auto;max-height:420px;object-fit:contain;display:block;cursor:pointer;}
.bill-display-area .no-bill-msg{color:#475569;text-align:center;padding:40px 20px;}
.bill-display-area .no-bill-msg i{font-size:40px;display:block;margin-bottom:10px;opacity:.4;}
.bill-thumbs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;}
.bill-thumb-item{width:54px;height:54px;border-radius:6px;overflow:hidden;cursor:pointer;border:2px solid transparent;transition:border-color .2s;flex-shrink:0;position:relative;}
.bill-thumb-item.active-thumb{border-color:#6366f1;}
.bill-thumb-item img{width:100%;height:100%;object-fit:cover;}
.bill-thumb-del{position:absolute;top:2px;right:2px;background:rgba(220,38,38,.9);color:#fff;border:none;border-radius:3px;width:16px;height:16px;cursor:pointer;font-size:8px;display:flex;align-items:center;justify-content:center;}
.bill-images-grid{display:none;}.bill-img-card{display:none;}.bill-img-del{display:none;}
.cust-info-card{background:#fff;border-radius:12px;padding:16px;margin-bottom:14px;box-shadow:0 2px 12px rgba(0,0,0,.3);}
.cust-info-card .cust-name{font-size:18px;font-weight:800;color:#0f172a;margin-bottom:4px;}
.cust-info-card .cust-meta{font-size:12px;color:#64748b;margin-bottom:10px;}
.cust-info-card .cust-meta strong{color:#374151;}
.cust-balance-badge{display:inline-flex;align-items:center;gap:5px;background:#f0fdf4;color:#16a34a;border:2px solid #86efac;padding:5px 14px;border-radius:20px;font-size:13px;font-weight:800;}
.sig-section{background:#fff;border-radius:10px;overflow:hidden;margin-bottom:14px;box-shadow:0 2px 8px rgba(0,0,0,.2);}
.sig-section-label{background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:8px 14px;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;display:flex;align-items:center;gap:6px;}
.sig-section img{width:100%;max-height:130px;object-fit:contain;padding:12px;display:block;}
.sig-section .no-img{padding:28px 10px;color:#cbd5e1;font-size:12px;text-align:center;}
.zoom-wrap{position:relative;overflow:hidden;width:100%;background:#f8fafc;cursor:zoom-in;height:160px;display:flex;align-items:center;justify-content:center;}
.zoom-wrap.panning{cursor:grabbing;}
.zoom-wrap img.zoomable{display:block;max-width:100%;max-height:140px;width:auto;height:auto;object-fit:contain;transform-origin:0 0;transition:transform 0.04s ease;user-select:none;pointer-events:none;padding:8px;}
.zoom-hint{position:absolute;bottom:5px;right:7px;font-size:10px;color:#94a3b8;background:rgba(255,255,255,.85);padding:2px 6px;border-radius:4px;pointer-events:none;font-weight:600;}
.truck-area{background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:12px;text-align:center;margin-bottom:14px;color:#94a3b8;}
.truck-area i{font-size:32px;margin-bottom:4px;display:block;}.truck-area span{font-size:11px;font-weight:600;}
.cr-actions{display:flex;gap:6px;flex-wrap:wrap;}
.verify-btn{padding:7px 16px;border:none;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s;flex:1;justify-content:center;}
.verify-btn.btn-verify{background:#14532d;color:#86efac;border:1.5px solid #166534;}
.verify-btn.btn-verify:hover,.verify-btn.active-v{background:#16a34a!important;color:#fff!important;border-color:#16a34a!important;}
.verify-btn.btn-unverify{background:#450a0a;color:#fca5a5;border:1.5px solid #dc2626;}
.verify-btn.btn-unverify:hover,.verify-btn.active-u{background:#dc2626!important;color:#fff!important;border-color:#dc2626!important;}
.common-verify-box{background:#0f1a2a;border:1.5px solid #334155;border-radius:10px;padding:14px;margin-top:auto;padding-top:14px;flex-shrink:0;}
.common-verify-box .cv-label{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.cv-status{font-size:13px;font-weight:700;margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.cv-status.is-verified{color:#4ade80;}.cv-status.is-unverified{color:#f87171;}.cv-status.is-pending{color:#94a3b8;}
.spcr-title{font-size:18px;font-weight:900;color:#f1f5f9;text-align:center;margin-bottom:14px;letter-spacing:.01em;line-height:1.3;}
.spcr-doc-display{background:#111827;border-radius:10px;border:1px solid #334155;overflow:hidden;position:relative;min-height:300px;display:flex;align-items:center;justify-content:center;}
.spcr-doc-display img{width:100%;height:auto;max-height:420px;object-fit:contain;display:block;cursor:pointer;}
.spcr-doc-display .no-doc{color:#475569;text-align:center;padding:40px 20px;}
.spcr-doc-display .no-doc i{font-size:40px;display:block;margin-bottom:10px;opacity:.4;}
.lightbox{display:none;position:fixed !important;inset:0 !important;z-index:2147483647 !important;background:rgba(0,0,0,.97) !important;align-items:center;justify-content:center;}
.lightbox.open{display:flex !important;}
.lightbox img{max-width:90vw;max-height:90vh;border-radius:8px;object-fit:contain;box-shadow:0 8px 40px rgba(0,0,0,.6);}
.lightbox-close{position:fixed !important;top:16px;right:20px;color:#fff;font-size:28px;cursor:pointer;background:rgba(220,38,38,.9);border:none;width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;z-index:2147483647 !important;line-height:1;}
.bm-loading{display:flex;align-items:center;justify-content:center;flex:1;color:#94a3b8;font-size:14px;gap:10px;}
.state-box{text-align:center;padding:70px 20px;color:#9ca3af;}
.state-box i{font-size:44px;display:block;margin-bottom:14px;opacity:.35;}

.select2-container .select2-selection--single{height:37px !important;border:1px solid #e5e5e5 !important;border-radius:7px !important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:35px !important;padding-left:11px !important;color:#1f2937;font-size:13px;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:35px !important;}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:#6366f1 !important;}
.select2-dropdown{border:1px solid #e5e5e5 !important;border-radius:7px !important;box-shadow:0 4px 20px rgba(0,0,0,.1) !important;font-size:13px;}
.select2-results__option--highlighted{background:#6366f1 !important;}

@media(max-width:900px){
    .filter-inputs{grid-template-columns:1fr 1fr;}
    .stat-grid{grid-template-columns:repeat(2,1fr);}
    .ph-summary{grid-template-columns:1fr 1fr;}
    .cn-summary{grid-template-columns:1fr 1fr;}
    .cn-form-row{grid-template-columns:1fr;}
    .bm-body{grid-template-columns:1fr;overflow-y:auto;}
    .bm-panel{border-right:none;border-bottom:1px solid #2d3748;}
}

@media print{
    @page{margin:8mm 8mm 10mm 8mm;size:A4 landscape;}
    *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;box-sizing:border-box;}
    .no-print,.filter-card,.search-bar-wrap,.as-at-banner,.table-toolbar,.stat-grid,#billModal,#billModalClose,#payHistoryModal,#cnModal,.lightbox,nav,header,footer{display:none !important;}
    html,body{margin:0 !important;padding:0 !important;background:#fff !important;font-family:Arial,Helvetica,sans-serif !important;font-size:10px !important;color:#000 !important;}
    .print-header{display:block !important;border-bottom:2px solid #1e1b4b;padding-bottom:6px;margin-bottom:8px;}
    .print-header-title{font-size:15px;font-weight:900;color:#1e1b4b;}
    .print-header-meta{display:flex;gap:4px;margin-top:4px;flex-wrap:wrap;font-size:9px;color:#555;}
    .print-header-meta span{font-weight:700;color:#1e1b4b;}
    .table-card{border:none !important;box-shadow:none !important;border-radius:0 !important;margin:0 !important;padding:0 !important;}
    .dt-wrap{overflow:visible !important;}
    .data-table{width:100% !important;border-collapse:collapse !important;font-size:8px !important;table-layout:fixed;}
    .data-table thead th{background:#1e1b4b !important;color:#fff !important;padding:5px 3px !important;font-size:7.5px !important;font-weight:700 !important;border:1px solid #334155 !important;white-space:nowrap;}
    .data-table tbody tr{border-bottom:1px solid #e5e5e5 !important;page-break-inside:avoid;}
    .data-table tbody tr.normal-row td{background:#fff !important;}
    .data-table tbody tr.special-row td{background:#fefce8 !important;}
    .data-table tbody tr.row-hidden{display:none !important;}
    .data-table td{padding:3px 3px !important;font-size:8px !important;border:1px solid #e5e5e5 !important;color:#000 !important;vertical-align:middle !important;word-break:break-word;}
    .data-table tfoot tr{page-break-inside:avoid;}
    .data-table tfoot td{background:#1e1b4b !important;color:#fff !important;padding:5px 3px !important;font-size:8.5px !important;font-weight:900 !important;border:1px solid #334155 !important;}
    .data-table tfoot td.tr{text-align:right !important;}
    .sr-pill,.special-badge,.date-badge,.aging-badge,.cn-badge,.verified-badge,.unverified-badge,.bill-count-badge{background:transparent !important;border:none !important;padding:0 !important;font-size:7.5px !important;color:#000 !important;border-radius:0 !important;display:inline !important;}
    .special-badge{color:#854d0e !important;font-weight:700 !important;}
    .aging-green{color:#16a34a !important;}.aging-yellow{color:#d97706 !important;}.aging-orange{color:#ea580c !important;}.aging-red{color:#dc2626 !important;}
    .inv-link{color:#1e1b4b !important;text-decoration:none !important;border-bottom:none !important;font-size:8px !important;}
    .balance-amt{color:#dc2626 !important;font-weight:700 !important;}
    .bal-breakdown{display:none !important;}
    .btn-bill,.btn-history,.btn-cn,.btn{display:none !important;}
    .no-results-row{display:none !important;}
    /* 14 columns */
    .data-table thead th:nth-child(1){width:18px;}.data-table thead th:nth-child(2){width:40px;}.data-table thead th:nth-child(3){width:32px;}
    .data-table thead th:nth-child(4){width:70px;}.data-table thead th:nth-child(5){width:90px;}.data-table thead th:nth-child(6){width:62px;}
    .data-table thead th:nth-child(7){width:46px;}.data-table thead th:nth-child(8){width:26px;}.data-table thead th:nth-child(9){width:50px;}
    .data-table thead th:nth-child(10){width:44px;}.data-table thead th:nth-child(11){width:44px;}.data-table thead th:nth-child(12){width:44px;}
    .data-table thead th:nth-child(13){width:50px;}.data-table thead th:nth-child(14){width:36px;}
}
</style>

<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;" class="no-print">
    <div>
        <h2 class="page-title"><i class="fa-solid fa-file-invoice-dollar"></i> Credit Bill Summary</h2>
        <p class="page-subtitle">
            <span style="color:#d97706;font-weight:700;">■</span> Yellow = Special Credit &nbsp;|&nbsp;
            <span style="color:#9ca3af;font-weight:600;">■</span> White = Normal Credit &nbsp;|&nbsp;
            <span style="font-weight:700;">Aging:</span>
            <span class="aging-badge aging-green" style="font-size:10px;padding:1px 7px;">0–29d Fresh</span>
            <span class="aging-badge aging-yellow" style="font-size:10px;padding:1px 7px;">30–59d Warning</span>
            <span class="aging-badge aging-orange" style="font-size:10px;padding:1px 7px;">60–89d Overdue</span>
            <span class="aging-badge aging-red" style="font-size:10px;padding:1px 7px;">90d+ Critical</span>
        </p>
    </div>
    <?php if($total_count > 0): ?>
    <div style="display:flex;gap:8px;" class="no-print">
        <button onclick="openPrintView()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</button>
        <button onclick="exportCSV()" class="btn btn-success btn-sm"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
    </div>
    <?php endif; ?>
</div>

<!-- FILTERS -->
<div class="filter-card no-print">
    <div class="filter-title"><i class="fa-solid fa-filter"></i> Filter Credit Bills</div>
    <form method="GET" id="filterForm">
        <div class="filter-grid">
            <div class="filter-inputs">
                <div class="fg">
                    <label><i class="fa-solid fa-route"></i> Route</label>
                    <select name="route" id="routeSelect" style="width:100%;">
                        <option value="">— All Routes —</option>
                        <?php foreach($all_routes as $rt): ?>
                        <option value="<?php echo htmlspecialchars($rt['route_code']); ?>" <?php echo $f_route===$rt['route_code']?'selected':''; ?>>
                            <?php echo htmlspecialchars($rt['route_code'].' — '.$rt['route_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label><i class="fa-solid fa-id-badge"></i> SR Code</label>
                    <select name="sr_code" id="srSelect" style="width:100%;">
                        <option value="">— All SR Codes —</option>
                        <?php foreach($all_sr as $sr): ?>
                        <option value="<?php echo htmlspecialchars($sr); ?>" <?php echo $f_sr===$sr?'selected':''; ?>>
                            <?php echo htmlspecialchars($sr); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label><i class="fa-solid fa-calendar-day"></i> Delivery Date</label>
                    <input type="date" name="delivery_date" value="<?php echo htmlspecialchars($f_date); ?>">
                </div>
                <div class="fg">
                    <label><i class="fa-solid fa-calendar-check"></i> As At Date</label>
                    <input type="date" name="as_at_date" value="<?php echo htmlspecialchars($f_as_at); ?>" title="Show balances as of this date">
                </div>
            </div>
            <div class="fg" style="flex-direction:row;gap:8px;align-items:flex-end;">
                <button type="submit" class="btn btn-primary" id="searchBtn" style="flex:1;">
                    <i class="fa-solid fa-magnifying-glass"></i> Filter
                </button>
                <a href="credit_bill_summary.php" class="btn btn-secondary" title="Clear All"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </div>
    </form>
</div>

<?php if(empty($rows)): ?>
<div class="table-card">
    <div class="state-box"><i class="fa-solid fa-inbox"></i><p>No outstanding invoices found.</p></div>
</div>
<?php else: ?>

<?php if($f_as_at): ?>
<div class="as-at-banner no-print">
    <i class="fa-solid fa-clock-rotate-left"></i>
    <strong>As At View:</strong> Showing outstanding balances as of <strong><?php echo date('d M Y', strtotime($f_as_at)); ?></strong>.
    Payments received after this date are excluded from Cash / Cheque / Balance figures.
</div>
<?php endif; ?>

<!-- STAT CARDS — 7 cards -->
<div class="stat-grid" id="statCards">
    <div class="stat-card"><div class="stat-label">Total Invoices</div><div class="stat-value blue" id="sc-count"><?php echo $total_count; ?></div></div>
    <div class="stat-card"><div class="stat-label">Net Value</div><div class="stat-value" id="sc-net">Rs. <?php echo number_format($t_net,2); ?></div></div>
    <div class="stat-card">
        <div class="stat-label">Cash Paid<?php echo $f_as_at?' (as at '.date('d M Y',strtotime($f_as_at)).')':''; ?></div>
        <div class="stat-value green" id="sc-cash">Rs. <?php echo number_format($t_cash,2); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Cheque Paid<?php echo $f_as_at?' (as at '.date('d M Y',strtotime($f_as_at)).')':''; ?></div>
        <div class="stat-value blue" id="sc-cheque">Rs. <?php echo number_format($t_cheque,2); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Credit Notes</div>
        <div class="stat-value orange" id="sc-cn">Rs. <?php echo number_format($t_cn,2); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Balance<?php echo $f_as_at?' (as at '.date('d M Y',strtotime($f_as_at)).')':''; ?></div>
        <div class="stat-value red" id="sc-balance">Rs. <?php echo number_format($t_balance,2); ?></div>
    </div>
    <div class="stat-card"><div class="stat-label">Special / Normal</div><div class="stat-value amber" id="sc-type"><?php echo $t_special; ?> <span style="color:#9ca3af;font-size:13px;">/</span> <span style="color:#475569;"><?php echo $t_normal; ?></span></div></div>
</div>

<!-- LIVE SEARCH BAR -->
<div class="search-bar-wrap no-print">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input type="text" id="liveSearch" placeholder="Search by T Code, customer, invoice, route, SR code, date…" autocomplete="off">
    <button id="searchClear" onclick="clearLiveSearch()" title="Clear search"><i class="fa-solid fa-xmark"></i></button>
    <span id="searchMatchCount" style="display:none;"></span>
</div>

<!-- PRINT-ONLY REPORT HEADER -->
<div class="print-header" style="display:none;">
    <div class="print-header-title">Credit Bill Summary Report</div>
    <div class="print-header-meta">
        <?php if($f_route): ?><span>Route:</span> <?php echo htmlspecialchars($f_route); ?> &nbsp;<?php endif; ?>
        <?php if($f_sr):    ?><span>SR Code:</span> <?php echo htmlspecialchars($f_sr); ?> &nbsp;<?php endif; ?>
        <?php if($f_date):  ?><span>Del. Date:</span> <?php echo date('d M Y',strtotime($f_date)); ?> &nbsp;<?php endif; ?>
        <?php if($f_as_at): ?><span>As At:</span> <?php echo date('d M Y',strtotime($f_as_at)); ?> &nbsp;<?php endif; ?>
        <?php if(!$f_route && !$f_sr && !$f_date && !$f_as_at): ?><span>Scope:</span> All Records &nbsp;<?php endif; ?>
        &nbsp;|&nbsp; <span>Total:</span> <?php echo $total_count; ?> invoices
        &nbsp;|&nbsp; <span>Net:</span> Rs.&nbsp;<?php echo number_format($t_net,2); ?>
        &nbsp;|&nbsp; <span>Cash:</span> Rs.&nbsp;<?php echo number_format($t_cash,2); ?>
        &nbsp;|&nbsp; <span>Cheque:</span> Rs.&nbsp;<?php echo number_format($t_cheque,2); ?>
        &nbsp;|&nbsp; <span>CN:</span> Rs.&nbsp;<?php echo number_format($t_cn,2); ?>
        &nbsp;|&nbsp; <span>Balance:</span> Rs.&nbsp;<?php echo number_format($t_balance,2); ?>
        &nbsp;|&nbsp; <span>Printed:</span> <?php echo date('d M Y H:i'); ?>
    </div>
</div>

<!-- MAIN TABLE -->
<div class="table-card">
    <div class="table-toolbar no-print">
        <div class="tbl-title">
            <i class="fa-solid fa-table"></i> Credit Bill Summary
            <span class="pill pill-violet" id="visibleCount"><?php echo $total_count; ?> rows</span>
            <?php if($f_route): ?><span class="pill pill-blue">Route: <?php echo htmlspecialchars($f_route); ?></span><?php endif; ?>
            <?php if($f_sr): ?><span class="pill pill-violet">SR: <?php echo htmlspecialchars($f_sr); ?></span><?php endif; ?>
            <?php if($f_date): ?><span class="pill pill-green"><?php echo date('d M Y',strtotime($f_date)); ?></span><?php endif; ?>
            <?php if($f_as_at): ?><span class="pill" style="background:#fef3c7;color:#92400e;"><i class="fa-solid fa-clock" style="font-size:9px;"></i> As at: <?php echo date('d M Y',strtotime($f_as_at)); ?></span><?php endif; ?>
            <?php if(!$f_route && !$f_sr && !$f_date && !$f_as_at): ?><span class="pill" style="background:#fef9c3;color:#854d0e;">All Records</span><?php endif; ?>
        </div>
        <div class="legend">
            <div class="legend-item"><div class="ldot ldot-y"></div> Special Credit</div>
            <div class="legend-item"><div class="ldot ldot-w"></div> Normal Credit</div>
        </div>
    </div>
    <div class="dt-wrap">
    <table class="data-table" id="mainTable">
        <thead>
            <tr>
                <th style="width:32px;">No</th>
                <th>T Code</th>
                <th class="tc">Rep Code</th>
                <th>Route</th>
                <th>Customer</th>
                <th>Invoice Number</th>
                <th class="tc">Del. Date</th>
                <th class="tc">Aging<?php echo $f_as_at?'<br><span style="font-size:8px;font-weight:400;opacity:.7;">('.date('d M Y',strtotime($f_as_at)).')</span>':''; ?></th>
                <th class="tr">Net Value</th>
                <th class="tr" style="color:#86efac;">Cash Paid<?php echo $f_as_at?'<br><span style="font-size:8px;font-weight:400;opacity:.7;">(as at)</span>':''; ?></th>
                <th class="tr" style="color:#93c5fd;">Cheque Paid<?php echo $f_as_at?'<br><span style="font-size:8px;font-weight:400;opacity:.7;">(as at)</span>':''; ?></th>
                <th class="tr" style="color:#fdba74;">Credit Notes</th>
                <th class="tr">Balance<?php echo $f_as_at?'<br><span style="font-size:8px;font-weight:400;opacity:.7;">(as at)</span>':''; ?></th>
                <th class="tc" style="min-width:165px;">Actions</th>
            </tr>
        </thead>
        <tbody id="mainTbody">
        <?php $rn=1; foreach($rows as $row):
            $is_special  = intval($row['is_special']);
            $row_class   = $is_special ? 'special-row' : 'normal-row';
            $net         = floatval($row['net_value']);
            $paid        = floatval($row['paid']);
            $cash_paid   = floatval($row['cash_paid']);
            $cheque_paid = floatval($row['cheque_paid']);
            $total_cn    = floatval($row['total_cn']);
            $balance     = floatval($row['balance']);
            $bill_count  = intval($row['bill_count']);
            $bill_verified = isset($row['bill_verified']) ? $row['bill_verified'] : null;
            $del_date_raw= $row['delivery_date'] ?? '';
            $del_date_fmt= $del_date_raw ? date('d M Y', strtotime($del_date_raw)) : '—';
            $aging_days  = isset($row['aging_days']) ? max(0, intval($row['aging_days'])) : 0;

            if ($aging_days >= 90)     { $ag_class='aging-red';    $ag_icon='fa-fire';                $ag_label=$aging_days.'d'; $ag_title='Critical — overdue 90+ days'; }
            elseif ($aging_days >= 60) { $ag_class='aging-orange'; $ag_icon='fa-triangle-exclamation';$ag_label=$aging_days.'d'; $ag_title='Overdue — 60–89 days'; }
            elseif ($aging_days >= 30) { $ag_class='aging-yellow'; $ag_icon='fa-clock';               $ag_label=$aging_days.'d'; $ag_title='Warning — 30–59 days'; }
            else                       { $ag_class='aging-green';  $ag_icon='fa-circle-check';        $ag_label=$aging_days.'d'; $ag_title='Fresh — under 30 days'; }
        ?>
        <tr class="<?php echo $row_class; ?>" id="trow-<?php echo $row['detail_id']; ?>"
            data-net="<?php echo $net; ?>"
            data-paid="<?php echo $paid; ?>"
            data-cash="<?php echo $cash_paid; ?>"
            data-cheque="<?php echo $cheque_paid; ?>"
            data-cn="<?php echo $total_cn; ?>"
            data-balance="<?php echo $balance; ?>"
            data-date="<?php echo htmlspecialchars($del_date_raw); ?>"
            data-aging="<?php echo $aging_days; ?>"
            data-special="<?php echo $is_special; ?>"
            data-search="<?php echo strtolower(htmlspecialchars(
                $row['t_code'].' '.$row['customer_name'].' '.$row['invoice_num'].' '.
                $row['route_code'].' '.$row['route_name'].' '.$row['sr_code'].' '.$del_date_fmt
            )); ?>">

            <td class="row-num" style="color:#9ca3af;font-size:11px;font-weight:600;"><?php echo $rn++; ?></td>
            <td><span style="font-family:monospace;font-size:11.5px;font-weight:700;color:#4338ca;"><?php echo htmlspecialchars($row['t_code']); ?></span></td>
            <td class="tc"><span class="sr-pill"><?php echo htmlspecialchars($row['sr_code']); ?></span></td>
            <td>
                <div style="font-size:12px;font-weight:700;color:#1f2937;"><?php echo htmlspecialchars($row['route_code']); ?></div>
                <div style="font-size:10px;color:#6b7280;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($row['route_name']); ?>"><?php echo htmlspecialchars($row['route_name']); ?></div>
            </td>
            <td>
                <div style="font-weight:600;color:#111827;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($row['customer_name']); ?>"><?php echo htmlspecialchars($row['customer_name']); ?></div>
                <?php if($is_special): ?><div style="margin-top:3px;"><span class="special-badge"><i class="fa-solid fa-star" style="font-size:8px;"></i> Special</span></div><?php endif; ?>
            </td>
            <td>
                <a class="inv-link" href="javascript:void(0)"
                   onclick="openPayHistory(<?php echo $row['detail_id']; ?>, '<?php echo addslashes(htmlspecialchars($row['invoice_num'])); ?>', '<?php echo addslashes(htmlspecialchars($row['customer_name'])); ?>')"
                   title="Click to view payment history">
                    <i class="fa-solid fa-receipt" style="font-size:10px;opacity:.6;margin-right:2px;"></i>
                    <?php echo htmlspecialchars($row['invoice_num']); ?>
                </a>
            </td>
            <td class="tc">
                <span class="date-badge"><i class="fa-solid fa-calendar-day" style="font-size:9px;"></i> <?php echo htmlspecialchars($del_date_fmt); ?></span>
            </td>
            <td class="tc">
                <span class="aging-badge <?php echo $ag_class; ?>" title="<?php echo $ag_title; ?>">
                    <i class="fa-solid <?php echo $ag_icon; ?>" style="font-size:9px;"></i> <?php echo $ag_label; ?>
                </span>
            </td>
            <td class="tr" style="font-weight:500;"><?php echo number_format($net,2); ?></td>
            <td class="tr" style="color:#16a34a;font-weight:700;">
                <?php echo $cash_paid > 0 ? number_format($cash_paid,2) : '<span style="color:#d1d5db;font-weight:400;">—</span>'; ?>
            </td>
            <td class="tr" style="color:#2563eb;font-weight:700;">
                <?php echo $cheque_paid > 0 ? number_format($cheque_paid,2) : '<span style="color:#d1d5db;font-weight:400;">—</span>'; ?>
            </td>
            <!-- CREDIT NOTES COLUMN -->
            <td class="tr" id="cn-cell-<?php echo $row['detail_id']; ?>" style="color:#ea580c;font-weight:700;">
                <?php if($total_cn > 0): ?>
                    <span class="cn-cell-val"><?php echo number_format($total_cn,2); ?></span>
                <?php else: ?>
                    <span style="color:#d1d5db;font-weight:400;" class="cn-cell-val-zero">—</span>
                <?php endif; ?>
            </td>
            <!-- BALANCE COLUMN -->
            <td class="tr" id="bal-cell-<?php echo $row['detail_id']; ?>">
                <span class="balance-amt">Rs. <?php echo number_format($balance,2); ?></span>
                <?php if($total_cn > 0): ?>
                <div class="bal-breakdown">
                    <i class="fa-solid fa-file-minus" style="font-size:8px;"></i> CN: -<?php echo number_format($total_cn,2); ?>
                </div>
                <?php endif; ?>
            </td>
            <!-- ACTIONS COLUMN -->
            <td class="tc" id="bill-cell-<?php echo $row['detail_id']; ?>">
                <div style="display:flex;flex-direction:column;align-items:center;gap:4px;">
                    <div style="display:flex;gap:4px;flex-wrap:wrap;justify-content:center;" class="no-print">
                        <button class="btn btn-bill"
                            onclick="openBillModal(<?php echo $row['detail_id']; ?>, '<?php echo addslashes(htmlspecialchars($row['invoice_num'])); ?>', '<?php echo addslashes(htmlspecialchars($row['customer_name'])); ?>')"
                            title="View / upload credit bill">
                            <i class="fa-solid fa-file-image"></i> Bill
                        </button>
                        <button class="btn btn-history"
                            onclick="openPayHistory(<?php echo $row['detail_id']; ?>, '<?php echo addslashes(htmlspecialchars($row['invoice_num'])); ?>', '<?php echo addslashes(htmlspecialchars($row['customer_name'])); ?>')"
                            title="View payment history">
                            <i class="fa-solid fa-clock-rotate-left"></i> History
                        </button>
                        <button class="btn btn-cn <?php echo $total_cn > 0 ? 'has-notes' : ''; ?>"
                            id="cnbtn-<?php echo $row['detail_id']; ?>"
                            onclick="openCNModal(<?php echo $row['detail_id']; ?>, '<?php echo addslashes(htmlspecialchars($row['invoice_num'])); ?>', '<?php echo addslashes(htmlspecialchars($row['customer_name'])); ?>', <?php echo $net; ?>, <?php echo $paid; ?>)"
                            title="View / Add credit notes">
                            <i class="fa-solid fa-file-minus"></i> CN<?php echo $total_cn > 0 ? ' ('.number_format($total_cn,0).')' : ''; ?>
                        </button>
                    </div>
                    <?php if($bill_count > 0): ?>
                        <span class="bill-count-badge"><i class="fa-solid fa-images"></i> <?php echo $bill_count; ?></span>
                    <?php endif; ?>
                    <?php if($bill_verified === '1' || $bill_verified === 1): ?>
                        <span id="vstatus-<?php echo $row['detail_id']; ?>" class="verified-badge"><i class="fa-solid fa-circle-check"></i> Verified</span>
                    <?php elseif($bill_verified === '0' || $bill_verified === 0): ?>
                        <span id="vstatus-<?php echo $row['detail_id']; ?>" class="unverified-badge"><i class="fa-solid fa-clock"></i> Not Verified</span>
                    <?php else: ?>
                        <span id="vstatus-<?php echo $row['detail_id']; ?>"></span>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <tr class="no-results-row" id="noResultsRow">
            <td colspan="14"><i class="fa-solid fa-search" style="margin-right:6px;"></i>No rows match your search.</td>
        </tr>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6" style="text-align:right;font-size:11px;opacity:.8;" id="foot-label">
                    TOTAL — <?php echo $total_count; ?> invoices (<?php echo $t_special; ?> special, <?php echo $t_normal; ?> normal)
                    <?php if($f_as_at): ?> &mdash; payments as at <?php echo date('d M Y',strtotime($f_as_at)); ?><?php endif; ?>
                </td>
                <td></td>
                <td></td>
                <td class="tr" id="foot-net">Rs. <?php echo number_format($t_net,2); ?></td>
                <td class="tr" id="foot-cash" style="color:#86efac;">Rs. <?php echo number_format($t_cash,2); ?></td>
                <td class="tr" id="foot-cheque" style="color:#93c5fd;">Rs. <?php echo number_format($t_cheque,2); ?></td>
                <td class="tr" id="foot-cn" style="color:#fdba74;">Rs. <?php echo number_format($t_cn,2); ?></td>
                <td class="tr" id="foot-balance">Rs. <?php echo number_format($t_balance,2); ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- ═══ PAYMENT HISTORY MODAL ═══ -->
<div id="payHistoryModal" onclick="if(event.target===this)closePayHistory()">
    <div class="ph-modal">
        <div class="ph-header">
            <div class="ph-header-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <div class="ph-header-text">
                <h3 id="ph-title">Payment History</h3>
                <p id="ph-subtitle">Loading...</p>
            </div>
            <button class="ph-close" onclick="closePayHistory()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="ph-body" id="ph-body">
            <div class="ph-loading"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading payment history...</div>
        </div>
    </div>
</div>

<!-- ═══ CREDIT NOTE MODAL ═══ -->
<div id="cnModal" onclick="if(event.target===this)closeCNModal()">
    <div class="cn-modal">
        <div class="cn-header">
            <div class="cn-header-icon"><i class="fa-solid fa-file-minus"></i></div>
            <div class="cn-header-text">
                <h3 id="cn-title">Credit Notes</h3>
                <p id="cn-subtitle">Loading...</p>
            </div>
            <button class="cn-close" onclick="closeCNModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="cn-body" id="cn-body">
            <div class="ph-loading"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading credit notes...</div>
        </div>
    </div>
</div>

<!-- ═══ FULLSCREEN BILL MODAL ═══ -->
<div id="billModal">
    <div class="bill-modal">
        <div class="bm-header">
            <div class="bm-header-info">
                <div>
                    <div class="bm-title"><i class="fa-solid fa-file-image"></i> Credit Bill Verification</div>
                    <div class="bm-subtitle" id="bm-sub">Loading...</div>
                </div>
                <div id="bm-badges" style="display:flex;gap:6px;flex-wrap:wrap;margin-left:10px;"></div>
            </div>
        </div>
        <div class="bm-body" id="bm-body">
            <div class="bm-loading"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading...</div>
        </div>
    </div>
</div>
<button id="billModalClose" onclick="closeBillModal()" title="Close (Esc)">&#x2715;</button>

<!-- Image lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
    <button class="lightbox-close" onclick="closeLightbox()"><i class="fa-solid fa-xmark"></i></button>
    <img src="" id="lightbox-img" alt="Preview">
</div>

<script>
const PAGE_AS_AT = <?php echo $f_as_at ? json_encode($f_as_at) : 'null'; ?>;

$(function(){
    $('#routeSelect').select2({placeholder:'— All Routes —',allowClear:true,width:'100%'});
    $('#srSelect').select2({placeholder:'— All SR Codes —',allowClear:true,width:'100%'});
});
document.getElementById('filterForm')?.addEventListener('submit',function(){
    const btn=document.getElementById('searchBtn');
    btn.disabled=true;
    btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Loading...';
});

/* ═══════════════════════════════════════
   LIVE SEARCH
═══════════════════════════════════════ */
(function(){
    const input      = document.getElementById('liveSearch');
    const clearBtn   = document.getElementById('searchClear');
    const matchBadge = document.getElementById('searchMatchCount');
    const visCount   = document.getElementById('visibleCount');
    const noResultsRow = document.getElementById('noResultsRow');
    if(!input) return;

    const allRows = Array.from(document.querySelectorAll('#mainTbody tr[data-search]'));

    const totalNet     = <?php echo $t_net; ?>;
    const totalCash    = <?php echo $t_cash; ?>;
    const totalCheque  = <?php echo $t_cheque; ?>;
    const totalCn      = <?php echo $t_cn; ?>;
    const totalBalance = <?php echo $t_balance; ?>;
    const totalCount   = <?php echo $total_count; ?>;
    const totalSpecial = <?php echo $t_special; ?>;
    const totalNormal  = <?php echo $t_normal; ?>;

    function fmtMoney(v){ return 'Rs. '+v.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }

    function runSearch(){
        const q = input.value.trim().toLowerCase();
        clearBtn.style.display = q ? 'block' : 'none';

        if(!q){
            allRows.forEach((tr,i)=>{ tr.classList.remove('row-hidden'); tr.querySelector('.row-num').textContent=i+1; });
            if(noResultsRow) noResultsRow.style.display='none';
            if(matchBadge) matchBadge.style.display='none';
            if(visCount) visCount.textContent = totalCount+' rows';
            resetFooter(); resetStatCards();
            return;
        }

        let shown=0,net=0,cash=0,cheque=0,cn=0,balance=0,special=0,normal=0,rn=1;
        allRows.forEach(tr=>{
            const hay   = tr.dataset.search||'';
            const match = hay.includes(q);
            tr.classList.toggle('row-hidden',!match);
            if(match){
                tr.querySelector('.row-num').textContent=rn++;
                net     += parseFloat(tr.dataset.net)    ||0;
                cash    += parseFloat(tr.dataset.cash)   ||0;
                cheque  += parseFloat(tr.dataset.cheque) ||0;
                cn      += parseFloat(tr.dataset.cn)     ||0;
                balance += parseFloat(tr.dataset.balance)||0;
                if(parseInt(tr.dataset.special)) special++; else normal++;
                shown++;
            }
        });

        if(noResultsRow) noResultsRow.style.display = shown?'none':'table-row';
        if(matchBadge){ matchBadge.style.display='inline-flex'; matchBadge.textContent=shown+' match'+(shown!==1?'es':''); }
        if(visCount) visCount.textContent=shown+' rows';

        const fl=document.getElementById('foot-label');
        const fn=document.getElementById('foot-net');
        const fc=document.getElementById('foot-cash');
        const fq=document.getElementById('foot-cheque');
        const fcn=document.getElementById('foot-cn');
        const fb=document.getElementById('foot-balance');
        if(fl)  fl.textContent='FILTERED — '+shown+' invoices ('+special+' special, '+normal+' normal)';
        if(fn)  fn.textContent=fmtMoney(net);
        if(fc)  fc.textContent=fmtMoney(cash);
        if(fq)  fq.textContent=fmtMoney(cheque);
        if(fcn) fcn.textContent=fmtMoney(cn);
        if(fb)  fb.textContent=fmtMoney(balance);

        const sc  =document.getElementById('sc-count');
        const sn  =document.getElementById('sc-net');
        const sca =document.getElementById('sc-cash');
        const sq  =document.getElementById('sc-cheque');
        const scn =document.getElementById('sc-cn');
        const sb  =document.getElementById('sc-balance');
        const st  =document.getElementById('sc-type');
        if(sc)  sc.textContent =shown;
        if(sn)  sn.textContent =fmtMoney(net);
        if(sca) sca.textContent=fmtMoney(cash);
        if(sq)  sq.textContent =fmtMoney(cheque);
        if(scn) scn.textContent=fmtMoney(cn);
        if(sb)  sb.textContent =fmtMoney(balance);
        if(st)  st.innerHTML   =special+' <span style="color:#9ca3af;font-size:13px;">/</span> <span style="color:#475569;">'+normal+'</span>';
    }

    function resetFooter(){
        const fl=document.getElementById('foot-label');
        const fn=document.getElementById('foot-net');
        const fc=document.getElementById('foot-cash');
        const fq=document.getElementById('foot-cheque');
        const fcn=document.getElementById('foot-cn');
        const fb=document.getElementById('foot-balance');
        if(fl) fl.textContent='TOTAL — '+totalCount+' invoices ('+totalSpecial+' special, '+totalNormal+' normal)';
        if(fn) fn.textContent=fmtMoney(totalNet);
        if(fc) fc.textContent=fmtMoney(totalCash);
        if(fq) fq.textContent=fmtMoney(totalCheque);
        if(fcn) fcn.textContent=fmtMoney(totalCn);
        if(fb) fb.textContent=fmtMoney(totalBalance);
    }

    function resetStatCards(){
        const sc  =document.getElementById('sc-count');
        const sn  =document.getElementById('sc-net');
        const sca =document.getElementById('sc-cash');
        const sq  =document.getElementById('sc-cheque');
        const scn =document.getElementById('sc-cn');
        const sb  =document.getElementById('sc-balance');
        const st  =document.getElementById('sc-type');
        if(sc)  sc.textContent =totalCount;
        if(sn)  sn.textContent =fmtMoney(totalNet);
        if(sca) sca.textContent=fmtMoney(totalCash);
        if(sq)  sq.textContent =fmtMoney(totalCheque);
        if(scn) scn.textContent=fmtMoney(totalCn);
        if(sb)  sb.textContent =fmtMoney(totalBalance);
        if(st)  st.innerHTML   =totalSpecial+' <span style="color:#9ca3af;font-size:13px;">/</span> <span style="color:#475569;">'+totalNormal+'</span>';
    }

    let _t;
    input.addEventListener('input',()=>{ clearTimeout(_t); _t=setTimeout(runSearch,120); });
    input.addEventListener('keydown',e=>{ if(e.key==='Escape') clearLiveSearch(); });
    window.clearLiveSearch = function(){ input.value=''; clearBtn.style.display='none'; runSearch(); input.focus(); };
})();

/* ═══════════════════════════════════════
   PAYMENT HISTORY MODAL
═══════════════════════════════════════ */
function openPayHistory(detailId, invNum, custName) {
    const modal = document.getElementById('payHistoryModal');
    document.getElementById('ph-title').textContent = 'Payment History';
    document.getElementById('ph-subtitle').textContent = invNum+' — '+custName;
    document.getElementById('ph-body').innerHTML = '<div class="ph-loading"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading payment history...</div>';
    modal.classList.add('open');
    let url = 'credit_bill_summary.php?ajax=payment_history&detail_id='+detailId;
    if(PAGE_AS_AT) url += '&as_at_date='+encodeURIComponent(PAGE_AS_AT);
    fetch(url).then(r=>r.json()).then(data=>{
        if(!data.success){ document.getElementById('ph-body').innerHTML='<div class="ph-empty"><i class="fa-solid fa-circle-exclamation"></i><div>'+(data.error||'Failed to load')+'</div></div>'; return; }
        renderPayHistory(data, invNum, custName);
    }).catch(()=>{
        document.getElementById('ph-body').innerHTML='<div class="ph-empty"><i class="fa-solid fa-circle-exclamation"></i><div>Network error.</div></div>';
    });
}

function renderPayHistory(data, invNum, custName) {
    const info      = data.info||{};
    const payments  = data.payments||[];
    const netVal    = parseFloat(info.net_value||0);
    const totalPaid = parseFloat(data.total_paid||0);
    const totalCN   = parseFloat(data.total_cn||0);
    const balance   = parseFloat(data.balance||0);
    const asAtDate  = data.as_at_date||null;

    function fmtM(v){ return 'Rs. '+parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    function fmtDateLabel(iso){ if(!iso)return''; const d=new Date(iso+'T00:00:00'); return d.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}); }

    let html = '';
    if(asAtDate) html += `<div class="ph-as-at-note"><i class="fa-solid fa-clock-rotate-left"></i><span>Showing payments up to <strong>${fmtDateLabel(asAtDate)}</strong> only.</span></div>`;

    html += `<div class="ph-summary">
        <div class="ph-sum-card"><div class="ph-sum-label">Net Value</div><div class="ph-sum-val text-net">${fmtM(netVal)}</div></div>
        <div class="ph-sum-card"><div class="ph-sum-label">Paid (Cash+Cleared)</div><div class="ph-sum-val text-paid">${fmtM(totalPaid)}</div></div>
        <div class="ph-sum-card"><div class="ph-sum-label">Credit Notes</div><div class="ph-sum-val text-cn">${fmtM(totalCN)}</div></div>
        <div class="ph-sum-card" style="border-color:${balance>0?'#fecaca':'#86efac'};"><div class="ph-sum-label">Balance</div><div class="ph-sum-val text-bal">${fmtM(balance)}</div></div>
    </div>`;

    html += `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;font-size:11px;">
        <span style="background:#ede9fe;color:#5b21b6;padding:3px 10px;border-radius:20px;font-weight:700;"><i class="fa-solid fa-receipt"></i> ${invNum}</span>`;
    if(info.t_code)        html += `<span style="background:#f3f4f6;color:#374151;padding:3px 10px;border-radius:20px;font-weight:600;">${info.t_code}</span>`;
    if(info.route_code)    html += `<span style="background:#dbeafe;color:#1e40af;padding:3px 10px;border-radius:20px;font-weight:600;"><i class="fa-solid fa-route"></i> ${info.route_code}</span>`;
    if(info.delivery_date) html += `<span style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:20px;font-weight:600;"><i class="fa-solid fa-calendar-day"></i> ${fmtDateLabel(info.delivery_date)}</span>`;
    if(asAtDate)           html += `<span style="background:#fef9c3;color:#854d0e;border:1px solid #fde047;padding:3px 10px;border-radius:20px;font-weight:700;"><i class="fa-solid fa-clock"></i> As at: ${fmtDateLabel(asAtDate)}</span>`;
    html += `</div>`;

    if(payments.length === 0){
        html += `<div class="ph-empty"><i class="fa-solid fa-money-bill-wave"></i><div style="font-size:14px;font-weight:600;color:#6b7280;">No payments recorded${asAtDate?' up to '+fmtDateLabel(asAtDate):''}</div></div>`;
    } else {
        html += `<div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
            <i class="fa-solid fa-list"></i> Payment Records
            <span style="background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;">${payments.length}</span>
        </div>
        <table class="ph-table"><thead><tr>
            <th style="width:28px;">#</th><th>Date</th><th>Method</th><th>Reference / Cheque</th>
            <th>Bank / Branch</th><th class="tc">Status</th><th class="tr">Amount</th><th>Recorded</th>
        </tr></thead><tbody>`;

        let grandTotal = 0;
        payments.forEach((p,i)=>{
            const amt = parseFloat(p.amount||0); grandTotal += amt;
            const method = (p.payment_method||'other').toLowerCase();
            let mClass='ph-method-other',mLabel=method.charAt(0).toUpperCase()+method.slice(1),mIcon='fa-money-bill';
            if(method==='cash'){mClass='ph-method-cash';mIcon='fa-money-bill';}
            else if(method==='cheque'||method==='check'){mClass='ph-method-cheque';mLabel='Cheque';mIcon='fa-money-check';}
            else if(['bank','bank_transfer','transfer'].includes(method)){mClass='ph-method-bank';mLabel='Bank Transfer';mIcon='fa-building-columns';}
            const ref = p.reference_no||p.cheque_no||'—';
            const bankBranch = (p.bank_name||p.branch_name)?[p.bank_name,p.branch_name].filter(Boolean).join(' / '):'—';
            let statusHtml = '';
            if(method==='cheque'||method==='check'){
                const st=(p.cheque_status||'pending').toLowerCase();
                let stC='ph-status-pending',stI='fa-clock',stL='Pending';
                if(st==='cleared'){stC='ph-status-cleared';stI='fa-circle-check';stL='Cleared';}
                else if(st==='bounced'||st==='returned'){stC='ph-status-bounced';stI='fa-circle-xmark';stL='Bounced';}
                statusHtml = `<span class="ph-cheque-status ${stC}"><i class="fa-solid ${stI}"></i> ${stL}</span>`;
            } else {
                statusHtml = `<span class="ph-cheque-status ph-status-cleared"><i class="fa-solid fa-circle-check"></i> Received</span>`;
            }
            const remarksAttr = p.remarks?` title="${p.remarks.replace(/"/g,'&quot;')}"` :'';
            html += `<tr${remarksAttr}>
                <td style="color:#9ca3af;font-size:11px;">${i+1}</td>
                <td><strong>${p.pay_date_fmt||p.payment_date||'—'}</strong></td>
                <td><span class="ph-pay-method ${mClass}"><i class="fa-solid ${mIcon}"></i> ${mLabel}</span></td>
                <td style="font-size:11px;font-family:monospace;font-weight:600;">${ref}</td>
                <td style="font-size:10px;color:#6b7280;">${bankBranch}</td>
                <td class="tc">${statusHtml}</td>
                <td class="tr" style="font-weight:700;color:#16a34a;">${fmtM(amt)}</td>
                <td style="font-size:10px;color:#9ca3af;">${p.created_fmt||'—'}</td>
            </tr>`;
        });
        html += `</tbody><tfoot><tr>
            <td colspan="6" style="text-align:right;font-size:11px;color:#64748b;font-weight:700;">TOTAL (all listed entries)</td>
            <td class="tr" style="font-weight:800;">${fmtM(grandTotal)}</td><td></td>
        </tr></tfoot></table>`;
        if(grandTotal!==totalPaid) html += `<div style="margin-top:10px;padding:8px 12px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;font-size:11px;color:#92400e;display:flex;align-items:center;gap:6px;"><i class="fa-solid fa-circle-info"></i> Paid total (${fmtM(totalPaid)}) includes cash and cleared cheques only. Pending cheques are listed but not counted until cleared.</div>`;
    }
    document.getElementById('ph-body').innerHTML = html;
}

function closePayHistory(){ document.getElementById('payHistoryModal').classList.remove('open'); }

/* ═══════════════════════════════════════
   CREDIT NOTE MODAL
═══════════════════════════════════════ */
let _cnDetailId = null, _cnNetValue = 0, _cnPaidValue = 0, _cnInvNum = '', _cnCustName = '';

function openCNModal(detailId, invNum, custName, netValue, paidValue) {
    _cnDetailId  = detailId;
    _cnNetValue  = parseFloat(netValue)||0;
    _cnPaidValue = parseFloat(paidValue)||0;
    _cnInvNum    = invNum;
    _cnCustName  = custName;

    document.getElementById('cn-title').textContent    = 'Credit Notes';
    document.getElementById('cn-subtitle').textContent = invNum+' — '+custName;
    document.getElementById('cn-body').innerHTML = '<div class="ph-loading"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading...</div>';
    document.getElementById('cnModal').classList.add('open');

    fetch('save_credit_note.php?action=list&detail_id='+detailId)
    .then(r=>r.json())
    .then(data=>{
        if(!data.success){ document.getElementById('cn-body').innerHTML='<div class="ph-empty"><i class="fa-solid fa-circle-exclamation"></i><div>'+(data.error||'Load failed')+'</div></div>'; return; }
        renderCNModal(data.notes||[], parseFloat(data.total_cn||0));
    })
    .catch(()=>{ document.getElementById('cn-body').innerHTML='<div class="ph-empty"><i class="fa-solid fa-circle-exclamation"></i><div>Network error.</div></div>'; });
}

function renderCNModal(notes, totalCN) {
    function fmtM(v){ return 'Rs. '+parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    const balance = _cnNetValue - _cnPaidValue - totalCN;

    let html = `<div class="cn-summary">
        <div class="cn-sum-card"><div class="cn-sum-label">Net Value</div><div class="cn-sum-val cn-net">${fmtM(_cnNetValue)}</div></div>
        <div class="cn-sum-card"><div class="cn-sum-label">Total Paid</div><div class="cn-sum-val cn-paid">${fmtM(_cnPaidValue)}</div></div>
        <div class="cn-sum-card"><div class="cn-sum-label">Credit Notes</div><div class="cn-sum-val cn-cn" id="cn-modal-total">${fmtM(totalCN)}</div></div>
        <div class="cn-sum-card" style="border-color:${balance>0?'#fecaca':'#bbf7d0'};"><div class="cn-sum-label">Balance</div><div class="cn-sum-val cn-bal" id="cn-modal-balance">${fmtM(balance)}</div></div>
    </div>`;

    html += `<div class="cn-list" id="cnListContainer">`;
    html += `<div class="cn-list-title"><i class="fa-solid fa-file-minus"></i> Credit Notes <span style="background:#fff7ed;color:#c2410c;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;" id="cn-count-badge">${notes.length}</span></div>`;

    if(notes.length === 0){
        html += `<div class="cn-empty" id="cnEmptyMsg"><i class="fa-solid fa-file-circle-minus"></i><div style="font-size:13px;font-weight:600;color:#6b7280;">No credit notes yet</div><div style="font-size:11px;color:#9ca3af;margin-top:4px;">Add a credit note below to deduct from the balance.</div></div>`;
    } else {
        html += `<div id="cnItemsWrap">`;
        notes.forEach(n=>{
            html += buildCNItem(n);
        });
        html += `</div>`;
    }
    html += `</div>`;

    html += `<div class="cn-add-box">
        <div class="cn-add-title"><i class="fa-solid fa-plus-circle"></i> Add New Credit Note</div>
        <div class="cn-form-row">
            <div class="cn-fg">
                <label>Amount (Rs.)</label>
                <input type="number" id="cnAmount" placeholder="0.00" min="0.01" step="0.01">
            </div>
            <div class="cn-fg">
                <label>Reason</label>
                <textarea id="cnReason" placeholder="Enter reason for credit note…" rows="2"></textarea>
            </div>
            <div class="cn-fg">
                <label>Date</label>
                <input type="date" id="cnDate" value="${new Date().toISOString().split('T')[0]}">
            </div>
        </div>
        <button class="btn-add-cn" id="cnAddBtn" onclick="addCreditNote()">
            <i class="fa-solid fa-plus"></i> Add Credit Note
        </button>
    </div>`;

    document.getElementById('cn-body').innerHTML = html;
}

function buildCNItem(n){
    const amt = parseFloat(n.amount||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    const reason = n.reason ? n.reason : '<em style="color:#d97706;">No reason given</em>';
    return `<div class="cn-item" id="cn-item-${n.id}">
        <div class="cn-item-icon"><i class="fa-solid fa-file-minus"></i></div>
        <div class="cn-item-body">
            <div class="cn-item-top">
                <span class="cn-item-amount">-Rs. ${amt}</span>
                <span class="cn-item-date"><i class="fa-solid fa-calendar-day" style="font-size:9px;"></i> ${n.note_date_fmt||n.note_date||'—'}</span>
            </div>
            <div class="cn-item-reason">${reason}</div>
            <div class="cn-item-created"><i class="fa-regular fa-clock" style="font-size:9px;"></i> Added: ${n.created_fmt||n.created_at||'—'}</div>
        </div>
        <button class="cn-item-del" onclick="deleteCreditNote(${n.id},${n.amount})" title="Delete this credit note">
            <i class="fa-solid fa-trash"></i>
        </button>
    </div>`;
}

function addCreditNote(){
    const amtInput  = document.getElementById('cnAmount');
    const reaInput  = document.getElementById('cnReason');
    const dateInput = document.getElementById('cnDate');
    const addBtn    = document.getElementById('cnAddBtn');

    const amount = parseFloat(amtInput.value)||0;
    const reason = reaInput.value.trim();
    const date   = dateInput.value;

    if(amount <= 0){ showToast('Please enter a valid amount','error'); amtInput.focus(); return; }
    if(!date)      { showToast('Please select a date','error'); dateInput.focus(); return; }

    addBtn.disabled = true;
    addBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding...';

    const fd = new FormData();
    fd.append('action','add');
    fd.append('detail_id',_cnDetailId);
    fd.append('amount',amount);
    fd.append('reason',reason);
    fd.append('note_date',date);

    fetch('save_credit_note.php',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(data=>{
        addBtn.disabled = false;
        addBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Add Credit Note';
        if(!data.success){ showToast(data.error||'Failed to add credit note','error'); return; }

        // Build new item HTML and prepend to list
        const now = new Date();
        const createdFmt = now.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})+' '+now.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'});
        const newNote = {
            id:           data.id,
            amount:       data.amount,
            reason:       data.reason,
            note_date:    data.note_date,
            note_date_fmt:data.note_date_fmt,
            created_fmt:  createdFmt
        };

        // Remove empty state
        const emptyMsg = document.getElementById('cnEmptyMsg');
        if(emptyMsg) emptyMsg.remove();

        // Ensure items wrap exists
        let wrap = document.getElementById('cnItemsWrap');
        if(!wrap){
            wrap = document.createElement('div');
            wrap.id = 'cnItemsWrap';
            document.getElementById('cnListContainer').appendChild(wrap);
        }
        wrap.insertAdjacentHTML('afterbegin', buildCNItem(newNote));

        // Update count badge
        const badge = document.getElementById('cn-count-badge');
        if(badge) badge.textContent = (parseInt(badge.textContent)||0)+1;

        // Update summary cards
        updateCNModalSummary(data.total_cn);

        // Update main table row
        updateTableRowCN(_cnDetailId, data.total_cn);

        // Reset form
        amtInput.value  = '';
        reaInput.value  = '';
        dateInput.value = new Date().toISOString().split('T')[0];

        showToast('Credit note added — Rs. '+parseFloat(data.amount).toLocaleString('en-US',{minimumFractionDigits:2}),'success');
    })
    .catch(()=>{
        addBtn.disabled = false;
        addBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Add Credit Note';
        showToast('Network error','error');
    });
}

function deleteCreditNote(cnId, cnAmount){
    if(!confirm('Delete this credit note of Rs. '+parseFloat(cnAmount).toLocaleString('en-US',{minimumFractionDigits:2})+'?\n\nThis will add back this amount to the outstanding balance.')) return;

    const fd = new FormData();
    fd.append('action','delete');
    fd.append('id',cnId);

    fetch('save_credit_note.php',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(data=>{
        if(!data.success){ showToast(data.error||'Delete failed','error'); return; }

        const item = document.getElementById('cn-item-'+cnId);
        if(item) item.remove();

        // Check if list is now empty
        const wrap = document.getElementById('cnItemsWrap');
        if(wrap && wrap.children.length === 0){
            wrap.remove();
            const listContainer = document.getElementById('cnListContainer');
            if(listContainer) listContainer.insertAdjacentHTML('beforeend',
                `<div class="cn-empty" id="cnEmptyMsg"><i class="fa-solid fa-file-circle-minus"></i><div style="font-size:13px;font-weight:600;color:#6b7280;">No credit notes</div></div>`);
        }

        // Update count badge
        const badge = document.getElementById('cn-count-badge');
        if(badge) badge.textContent = Math.max(0,(parseInt(badge.textContent)||0)-1);

        // Update summary cards
        updateCNModalSummary(data.total_cn);

        // Update main table row
        updateTableRowCN(_cnDetailId, data.total_cn);

        showToast('Credit note deleted','success');
    })
    .catch(()=>showToast('Network error','error'));
}

function updateCNModalSummary(totalCN){
    function fmtM(v){ return 'Rs. '+parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    const newBalance = _cnNetValue - _cnPaidValue - parseFloat(totalCN||0);
    const totalEl  = document.getElementById('cn-modal-total');
    const balEl    = document.getElementById('cn-modal-balance');
    if(totalEl) totalEl.textContent = fmtM(totalCN);
    if(balEl){
        balEl.textContent = fmtM(newBalance);
        balEl.closest('.cn-sum-card').style.borderColor = newBalance > 0 ? '#fecaca' : '#bbf7d0';
    }
}

/* Update a table row's CN column, balance column, and CN button after modal changes */
function updateTableRowCN(detailId, newTotalCN){
    const cn  = parseFloat(newTotalCN||0);
    const tr  = document.getElementById('trow-'+detailId);
    if(!tr) return;

    const net    = parseFloat(tr.dataset.net  ||0);
    const paid   = parseFloat(tr.dataset.paid ||0);
    const cash   = parseFloat(tr.dataset.cash ||0);
    const cheque = parseFloat(tr.dataset.cheque||0);
    const newBal = net - paid - cn;

    // Update data attributes
    tr.dataset.cn      = cn;
    tr.dataset.balance = newBal;

    // Update CN column cell
    const cnCell = document.getElementById('cn-cell-'+detailId);
    if(cnCell){
        if(cn > 0){
            cnCell.innerHTML = `<span class="cn-cell-val">${cn.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</span>`;
            cnCell.style.color='#ea580c'; cnCell.style.fontWeight='700';
        } else {
            cnCell.innerHTML = `<span style="color:#d1d5db;font-weight:400;" class="cn-cell-val-zero">—</span>`;
        }
    }

    // Update balance cell
    const balCell = document.getElementById('bal-cell-'+detailId);
    if(balCell){
        let html = `<span class="balance-amt">Rs. ${newBal.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</span>`;
        if(cn > 0) html += `<div class="bal-breakdown"><i class="fa-solid fa-file-minus" style="font-size:8px;"></i> CN: -${cn.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</div>`;
        balCell.innerHTML = html;
    }

    // Update CN button label
    const cnBtn = document.getElementById('cnbtn-'+detailId);
    if(cnBtn){
        cnBtn.innerHTML = `<i class="fa-solid fa-file-minus"></i> CN${cn>0?' ('+cn.toLocaleString('en-US',{maximumFractionDigits:0})+')':''}`;
        cnBtn.classList.toggle('has-notes', cn>0);
    }

    // Hide row if new balance <= 0
    if(newBal <= 0) tr.classList.add('row-hidden');

    // Update footer totals (recalculate from all visible rows)
    recalcFooterTotals();
}

function recalcFooterTotals(){
    function fmtM(v){ return 'Rs. '+parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    let net=0,cash=0,cheque=0,cn=0,balance=0,count=0,special=0,normal=0;
    document.querySelectorAll('#mainTbody tr[data-search]:not(.row-hidden)').forEach(tr=>{
        net     += parseFloat(tr.dataset.net    ||0);
        cash    += parseFloat(tr.dataset.cash   ||0);
        cheque  += parseFloat(tr.dataset.cheque ||0);
        cn      += parseFloat(tr.dataset.cn     ||0);
        balance += parseFloat(tr.dataset.balance||0);
        if(parseInt(tr.dataset.special)) special++; else normal++;
        count++;
    });
    const fn=document.getElementById('foot-net');
    const fc=document.getElementById('foot-cash');
    const fq=document.getElementById('foot-cheque');
    const fcn=document.getElementById('foot-cn');
    const fb=document.getElementById('foot-balance');
    const fl=document.getElementById('foot-label');
    const vc=document.getElementById('visibleCount');
    if(fn)  fn.textContent  = fmtM(net);
    if(fc)  fc.textContent  = fmtM(cash);
    if(fq)  fq.textContent  = fmtM(cheque);
    if(fcn) fcn.textContent = fmtM(cn);
    if(fb)  fb.textContent  = fmtM(balance);
    if(fl)  fl.textContent  = 'TOTAL — '+count+' invoices ('+special+' special, '+normal+' normal)';
    if(vc)  vc.textContent  = count+' rows';

    // Stat cards
    const sc =document.getElementById('sc-count');
    const sn =document.getElementById('sc-net');
    const sca=document.getElementById('sc-cash');
    const sq =document.getElementById('sc-cheque');
    const scn=document.getElementById('sc-cn');
    const sb =document.getElementById('sc-balance');
    const st =document.getElementById('sc-type');
    if(sc)  sc.textContent  = count;
    if(sn)  sn.textContent  = fmtM(net);
    if(sca) sca.textContent = fmtM(cash);
    if(sq)  sq.textContent  = fmtM(cheque);
    if(scn) scn.textContent = fmtM(cn);
    if(sb)  sb.textContent  = fmtM(balance);
    if(st)  st.innerHTML    = special+' <span style="color:#9ca3af;font-size:13px;">/</span> <span style="color:#475569;">'+normal+'</span>';
}

function closeCNModal(){ document.getElementById('cnModal').classList.remove('open'); }

/* ═══════════════════════════════════════
   KEYBOARD CLOSE
═══════════════════════════════════════ */
document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){
        if(document.getElementById('cnModal').classList.contains('open'))          { closeCNModal(); }
        else if(document.getElementById('payHistoryModal').classList.contains('open')){ closePayHistory(); }
        else { closeBillModal(); closeLightbox(); }
    }
});

/* ═══════════════════════════════════════
   BILL MODAL (unchanged logic)
═══════════════════════════════════════ */
let _detailId=null, _modalData=null;

function openBillModal(detailId, invNum, custName) {
    const modal=document.getElementById('billModal');
    const closeBtn=document.getElementById('billModalClose');
    if(modal.parentElement!==document.body) document.body.appendChild(modal);
    if(closeBtn&&closeBtn.parentElement!==document.body) document.body.appendChild(closeBtn);
    _detailId=detailId;
    document.getElementById('bm-sub').textContent=invNum+' — '+custName;
    document.getElementById('bm-badges').innerHTML='';
    document.getElementById('bm-body').innerHTML='<div class="bm-loading"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading...</div>';
    modal.classList.add('open');
    if(closeBtn) closeBtn.classList.add('show');
    document.body.classList.add('modal-open');
    loadModalData(detailId);
}

function closeBillModal(){
    const modal=document.getElementById('billModal');
    const closeBtn=document.getElementById('billModalClose');
    modal.classList.remove('open');
    if(closeBtn) closeBtn.classList.remove('show');
    document.body.classList.remove('modal-open');
    _detailId=null; _modalData=null;
}

function loadModalData(detailId){
    fetch('save_credit_bill_verify.php?action=load&detail_id='+detailId)
    .then(r=>r.json())
    .then(data=>{ if(!data.success){showBodyError(data.error||'Load failed');return;} _modalData=data; renderModal(data); })
    .catch(()=>showBodyError('Network error'));
}

function showBodyError(msg){
    document.getElementById('bm-body').innerHTML=`<div class="bm-loading" style="color:#dc2626;"><i class="fa-solid fa-circle-exclamation"></i> ${msg}</div>`;
}

function renderModal(data){
    const d=data.detail, crs=data.credit_requests||[], imgs=data.bill_images||[];
    const isEmg=crs.length>0, crCount=crs.length;
    const balance=parseFloat(d.adjust_net_value||0)-parseFloat(d.total_paid||0);
    const detailVerified=(d.bill_verified!==null&&d.bill_verified!==undefined&&d.bill_verified!=='')?parseInt(d.bill_verified):null;

    let badges='';
    if(isEmg) badges+=`<span style="background:#dc2626;color:#fff;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;"><i class="fa-solid fa-bolt"></i> Emergency — ${crCount} request${crCount>1?'s':''}</span>`;
    badges+=`<span style="background:rgba(255,255,255,.15);color:#fff;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;">${d.t_code}</span>`;
    if(d.delivery_date){
        const df=new Date(d.delivery_date+'T00:00:00');
        const formatted=df.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
        badges+=`<span style="background:#1d4ed8;color:#fff;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;"><i class="fa-solid fa-calendar-day"></i> ${formatted}</span>`;
        const agD=Math.max(0,Math.floor((Date.now()-df.getTime())/86400000));
        let agBg,agColor;
        if(agD>=90){agBg='#fef2f2';agColor='#dc2626';}else if(agD>=60){agBg='#fff7ed';agColor='#ea580c';}else if(agD>=30){agBg='#fefce8';agColor='#d97706';}else{agBg='#f0fdf4';agColor='#16a34a';}
        badges+=`<span style="background:${agBg};color:${agColor};border:1px solid ${agColor}30;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;"><i class="fa-solid fa-hourglass-half"></i> ${agD}d aged</span>`;
    }
    document.getElementById('bm-badges').innerHTML=badges;

    let p1=`<div class="bm-panel-title"><i class="fa-solid fa-upload"></i> Credit Bill Upload</div>`;
    const firstImg=imgs.length>0?imgs[0]:null;
    p1+=`<div class="bill-display-area" id="billDisplayArea">`;
    if(firstImg) p1+=`<img src="${firstImg.file_path}" alt="Bill" id="billMainImg" onclick="openLightbox('${firstImg.file_path}')">`;
    else p1+=`<div class="no-bill-msg"><i class="fa-solid fa-file-image"></i><div style="font-size:13px;margin-top:8px;">No bill uploaded yet</div></div>`;
    p1+=`</div>`;
    if(imgs.length>0){
        p1+=`<div class="bill-thumbs" id="billThumbsRow">`;
        imgs.forEach((img,idx)=>{
            p1+=`<div class="bill-thumb-item ${idx===0?'active-thumb':''}" id="billthumb-${img.id}" onclick="switchBillImg('${img.file_path}',${img.id})"><img src="${img.file_path}" alt="Bill"><button class="bill-thumb-del" onclick="event.stopPropagation();deleteBillImage(${img.id})" title="Delete"><i class="fa-solid fa-xmark"></i></button></div>`;
        });
        p1+=`</div>`;
    } else { p1+=`<div id="billThumbsRow" class="bill-thumbs"></div>`; }
    p1+=`<div class="bill-images-grid" id="billImagesGrid"></div>`;
    p1+=`<div class="upload-zone" id="uploadZone" onclick="document.getElementById('billFileInput').click();"
         ondragover="event.preventDefault();this.classList.add('drag');" ondragleave="this.classList.remove('drag');" ondrop="handleDrop(event)">
        <i class="fa-solid fa-cloud-arrow-up"></i><p><strong>Click to upload</strong> or drag &amp; drop</p>
        <p style="font-size:11px;margin-top:3px;color:#64748b;">JPEG, PNG, WEBP — max 10 MB</p>
        <input type="file" id="billFileInput" accept="image/*" onchange="uploadBillImage(this)">
    </div>
    <div id="uploadProgress" style="display:none;background:#1e2d3a;border-radius:8px;padding:10px 14px;margin-bottom:10px;font-size:13px;color:#6366f1;"><i class="fa-solid fa-spinner fa-spin"></i> Uploading...</div>`;
    const vIsVerified=detailVerified===1, vIsUnverified=detailVerified===0;
    const vStatusHtml=vIsVerified?`<span class="cv-status is-verified"><i class="fa-solid fa-circle-check"></i> Verified</span>`:vIsUnverified?`<span class="cv-status is-unverified"><i class="fa-solid fa-circle-xmark"></i> Not Verified</span>`:`<span class="cv-status is-pending"><i class="fa-regular fa-clock"></i> Pending verification</span>`;
    p1+=`<div class="common-verify-box" id="commonVerifyBox"><div class="cv-label"><i class="fa-solid fa-shield-check"></i> Bill Verification</div><div id="cvStatusDisplay">${vStatusHtml}</div><div class="cr-actions" style="margin-top:8px;"><button class="verify-btn btn-verify ${vIsVerified?'active-v':''}" id="vbtn-main" onclick="setDetailVerify(1)"><i class="fa-solid fa-circle-check"></i> Verified</button><button class="verify-btn btn-unverify ${vIsUnverified?'active-u':''}" id="uvbtn-main" onclick="setDetailVerify(0)"><i class="fa-solid fa-circle-xmark"></i> Not Verified</button></div></div>`;

    const sig=d.customer_signature||'', seal=d.customer_seal||'';
    let p2=`<div class="bm-panel-title"><i class="fa-solid fa-user-shield"></i> Customer Verification</div>`;
    if(isEmg) p2+=`<div class="emg-banner"><i class="fa-solid fa-bolt fa-lg"></i><span>Emergency Credit — This customer has credit requests</span><span class="cr-count">${crCount} request${crCount>1?'s':''}</span></div>`;
    p2+=`<div class="cust-info-card"><div class="cust-name">${d.display_name||d.customer_name||d.t_code}</div><div class="cust-meta">${d.t_code} &nbsp;|&nbsp; Invoice: <strong>${d.invoice_num}</strong></div><div style="margin-top:8px;"><span class="cust-balance-badge">Balance: Rs. ${balance.toLocaleString('en-US',{minimumFractionDigits:2})}</span></div></div>`;
    p2+=`<div class="sig-section"><div class="sig-section-label"><i class="fa-solid fa-pen-fancy"></i> Customer Signature</div>${sig?`<div class="zoom-wrap" data-src="${sig}" onwheel="zoomAt(event,this)" onmousedown="panStart(event,this)" onmousemove="panMove(event,this)" onmouseup="panEnd(this)" onmouseleave="panEnd(this)"><img class="zoomable" src="${sig}" alt="Signature" data-scale="1" data-ox="0" data-oy="0"><span class="zoom-hint">scroll to zoom · drag to pan</span></div>`:'<div class="no-img" style="padding:22px;color:#94a3b8;font-size:12px;text-align:center;"><i class="fa-solid fa-ban" style="font-size:22px;display:block;margin-bottom:6px;opacity:.4;"></i>No signature on file</div>'}</div>`;
    p2+=`<div class="truck-area"><i class="fa-solid fa-truck"></i><span>Delivery Confirmation</span></div>`;
    p2+=`<div class="sig-section"><div class="sig-section-label"><i class="fa-solid fa-stamp"></i> Customer Seal</div>${seal?`<div class="zoom-wrap" data-src="${seal}" onwheel="zoomAt(event,this)" onmousedown="panStart(event,this)" onmousemove="panMove(event,this)" onmouseup="panEnd(this)" onmouseleave="panEnd(this)"><img class="zoomable" src="${seal}" alt="Seal" data-scale="1" data-ox="0" data-oy="0"><span class="zoom-hint">scroll to zoom · drag to pan</span></div>`:'<div class="no-img" style="padding:22px;color:#94a3b8;font-size:12px;text-align:center;"><i class="fa-solid fa-ban" style="font-size:22px;display:block;margin-bottom:6px;opacity:.4;"></i>No seal on file</div>'}</div>`;

    let p3=`<div class="spcr-title">Special Credit<br>Request Document</div>`;
    if(isEmg){
        const allCrDocs=[];
        crs.forEach(cr=>{ if(cr.doc_paths) cr.doc_paths.split('||').filter(p=>p).forEach(p=>allCrDocs.push(p)); });
        if(allCrDocs.length>0){
            p3+=`<div class="spcr-doc-display" id="spcrDocDisplay"><img src="${allCrDocs[0]}" alt="Credit Request Document" onclick="openLightbox('${allCrDocs[0]}')"></div>`;
            if(allCrDocs.length>1){
                p3+=`<div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin:10px 0 6px;"><i class="fa-solid fa-bolt"></i> All Documents (${allCrDocs.length})</div><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:8px;">`;
                allCrDocs.forEach(p=>{ p3+=`<div style="background:#111827;border:1px solid #334155;border-radius:6px;overflow:hidden;cursor:pointer;" onclick="openLightbox('${p}')"><img src="${p}" style="width:100%;height:75px;object-fit:cover;display:block;"></div>`; });
                p3+=`</div>`;
            }
        } else { p3+=`<div class="spcr-doc-display" id="spcrDocDisplay"><div class="no-doc"><i class="fa-solid fa-file-circle-question"></i><div style="font-size:12px;margin-top:8px;">No emergency credit documents attached.</div></div></div>`; }
    } else { p3+=`<div class="spcr-doc-display" id="spcrDocDisplay"><div class="no-doc"><i class="fa-solid fa-file-circle-question"></i><div style="font-size:12px;margin-top:8px;">No special credit request for this bill.</div></div></div>`; }

    document.getElementById('bm-body').innerHTML=`<div class="bm-panel">${p1}</div><div class="bm-panel">${p2}</div><div class="bm-panel">${p3}</div>`;
}

function handleDrop(e){e.preventDefault();document.getElementById('uploadZone').classList.remove('drag');const file=e.dataTransfer.files[0];if(file)doUpload(file);}
function uploadBillImage(input){if(input.files[0])doUpload(input.files[0]);}
function doUpload(file){
    const prog=document.getElementById('uploadProgress'); prog.style.display='block';
    const fd=new FormData(); fd.append('action','upload_bill'); fd.append('detail_id',_detailId); fd.append('bill_image',file);
    fetch('save_credit_bill_verify.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        prog.style.display='none';
        if(data.success){
            const da=document.getElementById('billDisplayArea'); if(da) da.innerHTML=`<img src="${data.file_path}" alt="Bill" id="billMainImg" onclick="openLightbox('${data.file_path}')">`;
            const sd=document.getElementById('spcrDocDisplay'); if(sd) sd.innerHTML=`<img src="${data.file_path}" alt="Credit Document" onclick="openLightbox('${data.file_path}')">`;
            const tr=document.getElementById('billThumbsRow');
            if(tr){ tr.querySelectorAll('.bill-thumb-item').forEach(el=>el.classList.remove('active-thumb')); const td=document.createElement('div'); td.className='bill-thumb-item active-thumb'; td.id='billthumb-'+data.image_id; td.onclick=()=>switchBillImg(data.file_path,data.image_id); td.innerHTML=`<img src="${data.file_path}" alt="Bill"><button class="bill-thumb-del" onclick="event.stopPropagation();deleteBillImage(${data.image_id})"><i class="fa-solid fa-xmark"></i></button>`; tr.prepend(td); }
            updateTableBillCount(1); showToast('Bill image uploaded successfully','success');
        } else showToast(data.error||'Upload failed','error');
    }).catch(()=>{prog.style.display='none';showToast('Network error','error');});
}
function switchBillImg(src,imgId){ const mi=document.getElementById('billMainImg'); if(mi){mi.src=src;mi.onclick=()=>openLightbox(src);} document.querySelectorAll('.bill-thumb-item').forEach(el=>el.classList.remove('active-thumb')); const t=document.getElementById('billthumb-'+imgId); if(t)t.classList.add('active-thumb'); }
function deleteBillImage(imgId){
    if(!confirm('Delete this bill image?'))return;
    const fd=new FormData(); fd.append('action','delete_bill_image'); fd.append('image_id',imgId);
    fetch('save_credit_bill_verify.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        if(data.success){
            const thumb=document.getElementById('billthumb-'+imgId); if(thumb)thumb.remove();
            const nt=document.querySelector('.bill-thumb-item'); const da=document.getElementById('billDisplayArea'); const sd=document.getElementById('spcrDocDisplay');
            if(nt){ nt.classList.add('active-thumb'); const ns=nt.querySelector('img')?.src||''; if(da)da.innerHTML=`<img src="${ns}" alt="Bill" id="billMainImg" onclick="openLightbox('${ns}')">`; if(sd)sd.innerHTML=`<img src="${ns}" alt="Credit Document" onclick="openLightbox('${ns}')">`;
            } else { if(da)da.innerHTML='<div class="no-bill-msg"><i class="fa-solid fa-file-image"></i><div style="font-size:13px;margin-top:8px;">No bill uploaded yet</div></div>'; if(sd)sd.innerHTML='<div class="no-doc"><i class="fa-solid fa-file-circle-question"></i><div style="font-size:12px;margin-top:8px;">No document available yet.</div></div>'; }
            updateTableBillCount(-1); showToast('Image deleted','success');
        } else showToast(data.error||'Delete failed','error');
    });
}
function updateTableBillCount(delta){ const cell=document.getElementById('bill-cell-'+_detailId); if(!cell)return; let badge=cell.querySelector('.bill-count-badge'); if(!badge){badge=document.createElement('span');badge.className='bill-count-badge';badge.innerHTML='<i class="fa-solid fa-images"></i> 0';cell.querySelector('div').appendChild(badge);} const cur=parseInt(badge.textContent.trim())||0; badge.innerHTML=`<i class="fa-solid fa-images"></i> ${Math.max(0,cur+delta)}`; }
function setDetailVerify(verified){
    const fd=new FormData(); fd.append('action','set_detail_verify'); fd.append('detail_id',_detailId); fd.append('verified',verified);
    fetch('save_credit_bill_verify.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        if(data.success){
            const vbtn=document.getElementById('vbtn-main'),uvbtn=document.getElementById('uvbtn-main'),disp=document.getElementById('cvStatusDisplay');
            if(verified===1){vbtn?.classList.add('active-v');uvbtn?.classList.remove('active-u');if(disp)disp.innerHTML='<span class="cv-status is-verified"><i class="fa-solid fa-circle-check"></i> Verified</span>';showToast('Bill marked as Verified ✓','success');}
            else{uvbtn?.classList.add('active-u');vbtn?.classList.remove('active-v');if(disp)disp.innerHTML='<span class="cv-status is-unverified"><i class="fa-solid fa-circle-xmark"></i> Not Verified</span>';showToast('Bill marked as Not Verified','error');}
            updateTableVerifyBadge(verified);
        } else showToast(data.error||'Update failed','error');
    });
}
function updateTableVerifyBadge(status){ const vs=document.getElementById('vstatus-'+_detailId); if(!vs)return; if(status===1){vs.className='verified-badge';vs.innerHTML='<i class="fa-solid fa-circle-check"></i> Verified';}else{vs.className='unverified-badge';vs.innerHTML='<i class="fa-solid fa-clock"></i> Not Verified';} }

function openLightbox(src){ const lb=document.getElementById('lightbox'); document.body.appendChild(lb); lb.style.cssText='display:flex !important;position:fixed !important;inset:0 !important;z-index:2147483647 !important;background:rgba(0,0,0,.97) !important;align-items:center !important;justify-content:center !important;'; document.getElementById('lightbox-img').src=src; }
function closeLightbox(){ const lb=document.getElementById('lightbox'); lb.style.cssText=''; lb.classList.remove('open'); document.getElementById('lightbox-img').src=''; }

function zoomAt(e,wrap){e.preventDefault();const img=wrap.querySelector('img.zoomable');let sc=parseFloat(img.dataset.scale)||1,ox=parseFloat(img.dataset.ox)||0,oy=parseFloat(img.dataset.oy)||0;const rect=wrap.getBoundingClientRect(),mx=e.clientX-rect.left,my=e.clientY-rect.top,factor=e.deltaY<0?1.10:0.91,ns=Math.min(Math.max(sc*factor,1),4);let nox=mx-(mx-ox)*(ns/sc),noy=my-(my-oy)*(ns/sc);if(ns<=1){nox=0;noy=0;}if(nox>0)nox=0;if(noy>0)noy=0;const mox=rect.width*(1-ns),moy=rect.height*(1-ns);if(nox<mox)nox=mox;if(noy<moy)noy=moy;img.dataset.scale=ns;img.dataset.ox=nox;img.dataset.oy=noy;img.style.transformOrigin='0 0';img.style.transform=`translate(${nox}px,${noy}px) scale(${ns})`;wrap.style.cursor=ns>1?'grab':'zoom-in';wrap.onclick=ns>1?null:()=>openLightbox(wrap.dataset.src);}
let _panActive=false,_panSx=0,_panSy=0,_panOx=0,_panOy=0,_panWrap=null;
function panStart(e,wrap){const img=wrap.querySelector('img.zoomable');const sc=parseFloat(img.dataset.scale)||1;if(sc<=1){openLightbox(wrap.dataset.src);return;}e.preventDefault();_panActive=true;_panSx=e.clientX;_panSy=e.clientY;_panOx=parseFloat(img.dataset.ox)||0;_panOy=parseFloat(img.dataset.oy)||0;_panWrap=wrap;wrap.classList.add('panning');}
function panMove(e,wrap){if(!_panActive||_panWrap!==wrap)return;const img=wrap.querySelector('img.zoomable');const rect=wrap.getBoundingClientRect();const sc=parseFloat(img.dataset.scale)||1;let ox=_panOx+(e.clientX-_panSx),oy=_panOy+(e.clientY-_panSy);if(ox>0)ox=0;if(oy>0)oy=0;const mx=rect.width*(1-sc),my=rect.height*(1-sc);if(ox<mx)ox=mx;if(oy<my)oy=my;img.dataset.ox=ox;img.dataset.oy=oy;img.style.transform=`translate(${ox}px,${oy}px) scale(${sc})`;}
function panEnd(wrap){if(_panWrap===wrap){_panActive=false;_panWrap=null;}wrap.classList.remove('panning');}

/* ═══════════════════════════════════════
   TOAST
═══════════════════════════════════════ */
function showToast(msg,type='success'){
    let t=document.getElementById('__toast');
    if(!t){t=document.createElement('div');t.id='__toast';t.style='position:fixed;bottom:24px;right:24px;z-index:99999;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.2);transform:translateY(100px);transition:transform .3s;display:flex;align-items:center;gap:8px;';document.body.appendChild(t);}
    t.style.background=type==='success'?'#166534':'#dc2626';t.style.color='#fff';
    t.textContent=msg;t.style.transform='translateY(0)';
    clearTimeout(t._timer);t._timer=setTimeout(()=>{t.style.transform='translateY(100px)';},3500);
}

/* ═══════════════════════════════════════
   PRINT VIEW
═══════════════════════════════════════ */
function openPrintView(){
    const params=new URLSearchParams(window.location.search);
    params.set('printview','1');
    window.open('credit_bill_summary.php?'+params.toString(),'_blank');
}

/* ═══════════════════════════════════════
   CSV EXPORT
═══════════════════════════════════════ */
function exportCSV(){
    const rows=document.querySelectorAll('#mainTbody tr[data-search]:not(.row-hidden)');
    if(!rows.length){alert('No data to export.');return;}
    const asAtLabel=PAGE_AS_AT?' (as at '+PAGE_AS_AT+')':'';
    const headers=['No','T Code','SR Code','Route Code','Route Name','Customer','Invoice Number','Del. Date','Aging (Days)','Net Value','Cash Paid'+asAtLabel,'Cheque Paid'+asAtLabel,'Credit Notes','Balance'+asAtLabel,'Type'];
    const lines=[headers.join(',')];
    rows.forEach((tr,i)=>{
        const cells=tr.querySelectorAll('td');
        const esc=v=>'"'+(v||'').replace(/"/g,'""').replace(/\s+/g,' ').trim()+'"';
        const isSpecial=tr.classList.contains('special-row')?'Special':'Normal';
        const routeDivs=cells[3]?.querySelectorAll('div');
        lines.push([i+1,
            esc(cells[1]?.textContent),esc(cells[2]?.textContent),
            esc(routeDivs?.[0]?.textContent),esc(routeDivs?.[1]?.textContent),
            esc(cells[4]?.querySelector('div')?.textContent||cells[4]?.textContent),
            esc(cells[5]?.textContent),
            esc(tr.dataset.date||''),
            parseInt(tr.dataset.aging||0),
            parseFloat(tr.dataset.net||0).toFixed(2),
            parseFloat(tr.dataset.cash||0).toFixed(2),
            parseFloat(tr.dataset.cheque||0).toFixed(2),
            parseFloat(tr.dataset.cn||0).toFixed(2),
            parseFloat(tr.dataset.balance||0).toFixed(2),
            isSpecial
        ].join(','));
    });
    const blob=new Blob([lines.join('\n')],{type:'text/csv'});
    const url=URL.createObjectURL(blob);
    const a=document.createElement('a');a.href=url;
    a.download='credit_bill_summary_<?php echo date("Ymd_Hi"); ?>.csv';
    a.click();URL.revokeObjectURL(url);
}
</script>

<?php include 'footer.php'; ?>