<?php
/**
 * market_credit_report.php
 * Outstanding shown per-invoice (not summed per customer)
 */

include 'config.php';

$f_route     = trim($_GET['route']      ?? '');
$f_sr        = trim($_GET['sr_code']    ?? '');
$f_tcode     = trim($_GET['t_code']     ?? '');
$f_as_at     = trim($_GET['as_at_date'] ?? '');
$f_hide_zero = isset($_GET['hide_zero']) ? intval($_GET['hide_zero']) : 1;

/* ═══════════════════════════════════════════════════
   FILTER DROPDOWNS
═══════════════════════════════════════════════════ */
$routes_res = mysqli_query($conn, "SELECT route_code, route_name FROM routes WHERE active=1 ORDER BY route_name");
$all_routes = [];
while ($r = mysqli_fetch_assoc($routes_res)) $all_routes[] = $r;

$sr_res = mysqli_query($conn, "SELECT DISTINCT sr_code FROM field_summary ORDER BY sr_code");
$all_sr = [];
while ($r = mysqli_fetch_assoc($sr_res)) $all_sr[] = $r['sr_code'];

/* ═══════════════════════════════════════════════════
   FETCH DATA
═══════════════════════════════════════════════════ */

$pay_date_cond = $f_as_at
    ? "AND ip.payment_date <= '" . mysqli_real_escape_string($conn, $f_as_at) . "'"
    : "";

/* ── 1. OUTSTANDING — per invoice row ── */
$out_where = ["fsd.updated = 1"];
if ($f_route) $out_where[] = "fs.route = '"       . mysqli_real_escape_string($conn, $f_route) . "'";
if ($f_sr)    $out_where[] = "fs.sr_code = '"     . mysqli_real_escape_string($conn, $f_sr)    . "'";
if ($f_tcode) $out_where[] = "fsd.t_code LIKE '%" . mysqli_real_escape_string($conn, $f_tcode) . "%'";
if ($f_as_at) $out_where[] = "fs.delivery_date <= '" . mysqli_real_escape_string($conn, $f_as_at) . "'";
$out_where_sql = implode(' AND ', $out_where);

$sql_outstanding = "
SELECT
    fsd.id                           AS detail_id,
    fsd.t_code,
    fsd.invoice_num,
    fs.delivery_date,
    fs.sr_code,
    fs.route                         AS route_code,
    COALESCE(r.route_name, fs.route) AS route_name,
    COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS customer_name,
    GREATEST(
        COALESCE(siid.final_bill_amount,
                 COALESCE(fsd.adjust_net_value, fsd.net_value), 0)
        - COALESCE(pay.total_paid, 0)
        - COALESCE(cn.total_cn,   0),
    0) AS outstanding
FROM field_summary_details fsd
INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
LEFT JOIN routes r    ON r.route_code = fs.route
LEFT JOIN customers c ON c.t_code    = fsd.t_code
LEFT JOIN (
    SELECT bill_no, delivery_date,
           MAX(final_bill_amount) AS final_bill_amount
    FROM   secondary_invoice_import_details
    WHERE  status = 'imported'
    GROUP  BY bill_no, delivery_date
) siid ON siid.bill_no       = fsd.invoice_num
       AND siid.delivery_date = fs.delivery_date
LEFT JOIN (
    SELECT ip.field_summary_detail_id,
           SUM(ip.amount) AS total_paid
    FROM   invoice_payments ip
    WHERE  ip.is_reversed = 0
    $pay_date_cond
    GROUP  BY ip.field_summary_detail_id
) pay ON pay.field_summary_detail_id = fsd.id
LEFT JOIN (
    SELECT field_summary_detail_id,
           SUM(amount) AS total_cn
    FROM   credit_notes
    WHERE  is_deleted = 0
    GROUP  BY field_summary_detail_id
) cn ON cn.field_summary_detail_id = fsd.id
WHERE $out_where_sql
HAVING outstanding > 0.01
ORDER BY fsd.t_code, fs.delivery_date
";

/* outstanding_by_tcode: keyed by t_code → array of invoice rows */
$outstanding_by_tcode = [];
$res_out = mysqli_query($conn, $sql_outstanding);
if ($res_out) {
    while ($row = mysqli_fetch_assoc($res_out)) {
        $tc = $row['t_code'];
        if (!isset($outstanding_by_tcode[$tc])) {
            $outstanding_by_tcode[$tc] = [
                'customer_name' => $row['customer_name'],
                'sr_code'       => $row['sr_code'],
                'route_code'    => $row['route_code'],
                'route_name'    => $row['route_name'],
                'invoices'      => [],
                'total'         => 0,
            ];
        }
        $amt = floatval($row['outstanding']);
        $outstanding_by_tcode[$tc]['invoices'][] = [
            'invoice_num'   => $row['invoice_num'],
            'delivery_date' => $row['delivery_date'],
            'outstanding'   => $amt,
        ];
        $outstanding_by_tcode[$tc]['total'] += $amt;
    }
}

/* ── 2. CHEQUES IN HAND ── */
$chq_where   = ["ch.status IN ('pending','to_be_bank','deposited','sent_back')"];
$chq_join_sr = '';

if ($f_tcode) $chq_where[] = "ch.t_code LIKE '%" . mysqli_real_escape_string($conn, $f_tcode) . "%'";
if ($f_route || $f_sr) {
    $chq_join_sr = "INNER JOIN invoice_payments ip2 ON ip2.id = ch.invoice_payment_id
                    INNER JOIN field_summary fs2    ON fs2.id = ip2.field_summary_id";
    if ($f_route) $chq_where[] = "fs2.route = '"   . mysqli_real_escape_string($conn, $f_route) . "'";
    if ($f_sr)    $chq_where[] = "fs2.sr_code = '" . mysqli_real_escape_string($conn, $f_sr)    . "'";
}
$chq_where_sql = implode(' AND ', $chq_where);

$sql_cih = "
SELECT
    ch.t_code,
    COALESCE(NULLIF(c.shop_name,''), ch.t_code) AS customer_name,
    ch.status,
    ch.total_amount,
    COALESCE(ch.sb_settlement_amount, 0) AS sb_paid,
    COALESCE(ch.sb_settled, 0)           AS sb_settled
FROM cheques ch
LEFT JOIN customers c ON c.t_code = ch.t_code
$chq_join_sr
WHERE $chq_where_sql
";

$cih_by_tcode = [];
$res_cih = mysqli_query($conn, $sql_cih);
if ($res_cih) {
    while ($row = mysqli_fetch_assoc($res_cih)) {
        $tc  = $row['t_code'];
        $st  = strtolower(trim($row['status']));
        $amt = max(0, floatval($row['total_amount']));

        if ($st === 'sent_back') {
            if (intval($row['sb_settled'])) continue;
            $amt = max(0, $amt - floatval($row['sb_paid']));
            if ($amt <= 0.01) continue;
        }

        if (!isset($cih_by_tcode[$tc])) {
            $cih_by_tcode[$tc] = [
                'customer_name' => $row['customer_name'] ?: $tc,
                'total'         => 0,
                'counts'        => ['pending'=>0,'to_be_bank'=>0,'deposited'=>0,'sent_back'=>0],
            ];
        }
        $cih_by_tcode[$tc]['total'] += $amt;
        if (isset($cih_by_tcode[$tc]['counts'][$st]))
            $cih_by_tcode[$tc]['counts'][$st]++;
    }
}

/* ── 3. RETURNED CHEQUES ── */
$ret_where   = ["(ch.status = 'returned' OR ch.status = 'bounced')"];
$ret_join_sr = '';

if ($f_tcode) $ret_where[] = "ch.t_code LIKE '%" . mysqli_real_escape_string($conn, $f_tcode) . "%'";
if ($f_route || $f_sr) {
    $ret_join_sr = "INNER JOIN invoice_payments ip3 ON ip3.id = ch.invoice_payment_id
                    INNER JOIN field_summary fs3    ON fs3.id = ip3.field_summary_id";
    if ($f_route) $ret_where[] = "fs3.route = '"   . mysqli_real_escape_string($conn, $f_route) . "'";
    if ($f_sr)    $ret_where[] = "fs3.sr_code = '" . mysqli_real_escape_string($conn, $f_sr)    . "'";
}
$ret_where_sql = implode(' AND ', $ret_where);

$sql_ret = "
SELECT
    ch.t_code,
    COALESCE(NULLIF(c.shop_name,''), ch.t_code) AS customer_name,
    ch.total_amount,
    COALESCE(ch.settlement_amount, 0) AS rtn_paid,
    COALESCE(ch.return_settled,    0) AS rtn_settled
FROM cheques ch
LEFT JOIN customers c ON c.t_code = ch.t_code
$ret_join_sr
WHERE $ret_where_sql
";

$ret_by_tcode = [];
$res_ret = mysqli_query($conn, $sql_ret);
if ($res_ret) {
    while ($row = mysqli_fetch_assoc($res_ret)) {
        $tc = $row['t_code'];
        if (intval($row['rtn_settled'])) continue;
        $bal = max(0, floatval($row['total_amount']) - floatval($row['rtn_paid']));
        if ($bal <= 0.01) continue;

        if (!isset($ret_by_tcode[$tc])) {
            $ret_by_tcode[$tc] = [
                'customer_name' => $row['customer_name'] ?: $tc,
                'total'         => 0,
                'count'         => 0,
            ];
        }
        $ret_by_tcode[$tc]['total'] += $bal;
        $ret_by_tcode[$tc]['count']++;
    }
}

/* ── 4. MERGE ── */
$all_tcodes = array_unique(array_merge(
    array_keys($outstanding_by_tcode),
    array_keys($cih_by_tcode),
    array_keys($ret_by_tcode)
));

$report_rows = [];
foreach ($all_tcodes as $tc) {
    $out_data = $outstanding_by_tcode[$tc] ?? null;
    $cih_data = $cih_by_tcode[$tc]        ?? null;
    $ret_data = $ret_by_tcode[$tc]         ?? null;

    $outstanding      = max(0, $out_data ? $out_data['total']  : 0);
    $cheques_in_hand  = max(0, $cih_data ? $cih_data['total']  : 0);
    $returned_cheques = max(0, $ret_data ? $ret_data['total']   : 0);
    $market_credit    = $outstanding + $cheques_in_hand + $returned_cheques;

    if ($f_hide_zero && $market_credit <= 0.01) continue;

    $cust_name  = $out_data['customer_name']
               ?? $cih_data['customer_name']
               ?? $ret_data['customer_name']
               ?? $tc;
    $sr_code    = $out_data['sr_code']    ?? '';
    $route_code = $out_data['route_code'] ?? '';
    $route_name = $out_data['route_name'] ?? '';

    $report_rows[] = [
        't_code'          => $tc,
        'customer_name'   => $cust_name,
        'sr_code'         => $sr_code,
        'route_code'      => $route_code,
        'route_name'      => $route_name,
        'outstanding'     => $outstanding,
        'invoices'        => $out_data['invoices'] ?? [],   // ← per-invoice list
        'cheques_in_hand' => $cheques_in_hand,
        'returned_cheques'=> $returned_cheques,
        'market_credit'   => $market_credit,
        'cih_counts'      => $cih_data['counts'] ?? ['pending'=>0,'to_be_bank'=>0,'deposited'=>0,'sent_back'=>0],
        'ret_count'       => $ret_data['count']  ?? 0,
    ];
}

usort($report_rows, fn($a,$b) => $b['market_credit'] <=> $a['market_credit']);

$t_outstanding     = array_sum(array_column($report_rows, 'outstanding'));
$t_cheques_in_hand = array_sum(array_column($report_rows, 'cheques_in_hand'));
$t_returned        = array_sum(array_column($report_rows, 'returned_cheques'));
$t_market_credit   = array_sum(array_column($report_rows, 'market_credit'));
$t_count           = count($report_rows);

/* ═══════════════════════════════════════════════════
   PRINT VIEW
═══════════════════════════════════════════════════ */
if (isset($_GET['printview'])) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Market Credit Report — Print</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
@page{size:A4 landscape;margin:8mm 8mm 10mm 8mm;}
body{font-family:Arial,Helvetica,sans-serif;font-size:9px;color:#000;background:#fff;}
.no-print{display:flex;align-items:center;justify-content:space-between;background:#1e1b4b;color:#fff;padding:10px 18px;position:sticky;top:0;z-index:99;}
.no-print h1{font-size:14px;font-weight:800;}
.btns{display:flex;gap:8px;}
.pbtn{display:inline-flex;align-items:center;gap:6px;padding:7px 18px;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;font-family:Arial,sans-serif;}
.pbtn-print{background:#6366f1;color:#fff;}
.pbtn-close{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);}
.rpt-header{border-bottom:2.5px solid #1e1b4b;padding:8px 0 6px;margin-bottom:8px;}
.rpt-header-title{font-size:15px;font-weight:900;color:#1e1b4b;}
.rpt-meta{display:flex;flex-wrap:wrap;gap:0 16px;font-size:8px;color:#444;margin-top:4px;}
.rpt-meta strong{color:#1e1b4b;}
.rpt-summary{display:flex;gap:8px;flex-wrap:wrap;margin-top:7px;}
.rpt-sum-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:5px;padding:4px 12px;font-size:8px;display:inline-flex;align-items:center;gap:5px;}
.rpt-sum-box .lbl{font-weight:600;color:#6b7280;font-size:7.5px;text-transform:uppercase;}
.rpt-sum-box .val{color:#1e1b4b;font-size:10px;font-weight:900;}
.rpt-sum-box.red .val{color:#dc2626;}.rpt-sum-box.blue .val{color:#2563eb;}.rpt-sum-box.violet .val{color:#7c3aed;}.rpt-sum-box.amber .val{color:#d97706;}
table{width:100%;border-collapse:collapse;font-size:8.5px;}
thead th{background:#1e1b4b;color:#fff;padding:5px 4px;font-size:7.5px;font-weight:700;border:1px solid #334155;white-space:nowrap;text-align:left;}
thead th.tr{text-align:right;}thead th.tc{text-align:center;}
tbody tr{border-bottom:1px solid #e5e5e5;page-break-inside:avoid;}
tbody td{padding:4px 4px;border:1px solid #e8e8e8;vertical-align:middle;color:#111;}
tbody td.tr{text-align:right;}tbody td.tc{text-align:center;}
tfoot td{background:#1e1b4b !important;color:#fff !important;padding:6px 4px;font-size:9px;font-weight:900;border:1px solid #334155;}
tfoot td.tr{text-align:right;}
/* customer header row */
.cust-hdr td{background:#eef2ff !important;font-weight:800;border-top:2px solid #6366f1 !important;}
/* invoice sub-row */
.inv-row td{background:#f9fafb !important;font-size:8px;color:#374151;}
.inv-row td:first-child{padding-left:14px;}
.mc-high{background:#fff1f2;}.mc-med{background:#fffbeb;}.mc-low{background:#f0fdf4;}
.mc-val-high{color:#dc2626;font-weight:800;}.mc-val-med{color:#d97706;font-weight:700;}.mc-val-low{color:#16a34a;font-weight:600;}
@media print{
    .no-print{display:none !important;}
    tfoot td{background:#1e1b4b !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    thead th{background:#1e1b4b !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .cust-hdr td{background:#eef2ff !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .inv-row td{background:#f9fafb !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .mc-high{background:#fff1f2 !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .mc-med{background:#fffbeb !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .mc-low{background:#f0fdf4 !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}
</style>
</head>
<body>
<div class="no-print">
    <h1>&#128202; Market Credit Report — Print Preview</h1>
    <div class="btns">
        <button class="pbtn pbtn-print" onclick="window.print()">&#128438; Print / PDF</button>
        <button class="pbtn pbtn-close" onclick="window.close()">&#10005; Close</button>
    </div>
</div>
<div style="padding:8mm;">
<div class="rpt-header">
    <div class="rpt-header-title">Market Credit Report</div>
    <div class="rpt-meta">
        <?php if($f_route): ?><span><strong>Route:</strong> <?=htmlspecialchars($f_route)?></span><?php endif; ?>
        <?php if($f_sr):    ?><span><strong>SR Code:</strong> <?=htmlspecialchars($f_sr)?></span><?php endif; ?>
        <?php if($f_tcode): ?><span><strong>T-Code:</strong> <?=htmlspecialchars($f_tcode)?></span><?php endif; ?>
        <?php if($f_as_at): ?><span><strong>As At:</strong> <?=date('d M Y',strtotime($f_as_at))?></span><?php endif; ?>
        <span><strong>Printed:</strong> <?=date('d M Y, H:i')?></span>
        <span><strong>Customers:</strong> <?=$t_count?></span>
    </div>
    <div class="rpt-summary">
        <div class="rpt-sum-box"><span class="lbl">Customers</span><span class="val"><?=$t_count?></span></div>
        <div class="rpt-sum-box amber"><span class="lbl">Outstanding</span><span class="val">Rs.&nbsp;<?=number_format($t_outstanding,2)?></span></div>
        <div class="rpt-sum-box blue"><span class="lbl">Cheques in Hand</span><span class="val">Rs.&nbsp;<?=number_format($t_cheques_in_hand,2)?></span></div>
        <div class="rpt-sum-box violet"><span class="lbl">Returned</span><span class="val">Rs.&nbsp;<?=number_format($t_returned,2)?></span></div>
        <div class="rpt-sum-box red"><span class="lbl">Market Credit</span><span class="val">Rs.&nbsp;<?=number_format($t_market_credit,2)?></span></div>
    </div>
</div>
<table>
<thead>
<tr>
    <th style="width:20px;">No</th>
    <th style="width:48px;">T-Code</th>
    <th>Customer / Invoice</th>
    <th class="tc" style="width:32px;">SR</th>
    <th class="tc" style="width:44px;">Route</th>
    <th class="tr" style="width:70px;">Outstanding<?=$f_as_at?' (as at)':''?></th>
    <th class="tr" style="width:70px;">Cheques in Hand</th>
    <th class="tr" style="width:60px;">Returned</th>
    <th class="tr" style="width:75px;">Market Credit</th>
</tr>
</thead>
<tbody>
<?php $rn=1; foreach($report_rows as $row):
    $mc      = $row['market_credit'];
    $row_cls = $mc >= 100000 ? 'mc-high' : ($mc >= 25000 ? 'mc-med' : 'mc-low');
    $mc_cls  = $mc >= 100000 ? 'mc-val-high' : ($mc >= 25000 ? 'mc-val-med' : 'mc-val-low');
    $invoices = $row['invoices'];
    $inv_count = count($invoices);
?>
<!-- Customer header row -->
<tr class="cust-hdr <?=$row_cls?>">
    <td class="tc" style="font-size:8px;color:#6366f1;"><?=$rn++?></td>
    <td style="font-family:monospace;font-weight:800;color:#4338ca;font-size:8.5px;"><?=htmlspecialchars($row['t_code'])?></td>
    <td style="font-weight:800;font-size:8.5px;">
        <?=htmlspecialchars($row['customer_name'])?>
        <?php if($inv_count > 1): ?>
        <span style="font-size:7px;font-weight:600;color:#6366f1;margin-left:4px;">(<?=$inv_count?> invoices)</span>
        <?php endif; ?>
    </td>
    <td class="tc" style="color:#5b21b6;font-weight:700;"><?=htmlspecialchars($row['sr_code'])?></td>
    <td class="tc" style="font-size:8px;"><?=htmlspecialchars($row['route_code'])?></td>
    <td class="tr" style="color:#d97706;font-weight:800;"><?=$row['outstanding']>0?'Rs.&nbsp;'.number_format($row['outstanding'],2):'—'?></td>
    <td class="tr" style="color:#2563eb;font-weight:700;"><?=$row['cheques_in_hand']>0?'Rs.&nbsp;'.number_format($row['cheques_in_hand'],2):'—'?></td>
    <td class="tr" style="color:#7c3aed;font-weight:700;"><?=$row['returned_cheques']>0?'Rs.&nbsp;'.number_format($row['returned_cheques'],2):'—'?></td>
    <td class="tr <?=$mc_cls?>">Rs.&nbsp;<?=number_format($mc,2)?></td>
</tr>
<?php if($inv_count > 1): ?>
<!-- Per-invoice sub-rows (only shown when multiple invoices) -->
<?php foreach($invoices as $inv): ?>
<tr class="inv-row">
    <td></td>
    <td></td>
    <td style="padding-left:18px;font-size:8px;">
        &#8627; <span style="font-family:monospace;font-weight:700;color:#4338ca;"><?=htmlspecialchars($inv['invoice_num'])?></span>
        <?php if($inv['delivery_date']): ?>
        <span style="color:#94a3b8;margin-left:5px;"><?=date('d M Y',strtotime($inv['delivery_date']))?></span>
        <?php endif; ?>
    </td>
    <td></td>
    <td></td>
    <td class="tr" style="color:#d97706;font-weight:600;font-size:8px;">Rs.&nbsp;<?=number_format($inv['outstanding'],2)?></td>
    <td></td>
    <td></td>
    <td></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr>
    <td colspan="5" style="text-align:right;font-size:7.5px;opacity:.8;">TOTAL — <?=$t_count?> customers</td>
    <td class="tr">Rs.&nbsp;<?=number_format($t_outstanding,2)?></td>
    <td class="tr">Rs.&nbsp;<?=number_format($t_cheques_in_hand,2)?></td>
    <td class="tr">Rs.&nbsp;<?=number_format($t_returned,2)?></td>
    <td class="tr">Rs.&nbsp;<?=number_format($t_market_credit,2)?></td>
</tr>
</tfoot>
</table>
</div>
<script>window.addEventListener('load',()=>window.print());</script>
</body>
</html>
<?php
    exit;
}

/* ═══════════════════════════════════════════════════
   NORMAL PAGE
═══════════════════════════════════════════════════ */
include 'header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
*{box-sizing:border-box;}
.mcr-page-header{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px;margin-bottom:22px;}
.mcr-page-title{font-size:24px;font-weight:900;color:#0f172a;margin:0 0 4px;letter-spacing:-.5px;}
.mcr-page-subtitle{font-size:13px;color:#64748b;margin:0;}
.mcr-header-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.mcr-filter-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px 20px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.mcr-filter-title{font-size:12px;font-weight:800;color:#374151;text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px;display:flex;align-items:center;gap:7px;}
.mcr-filter-grid{display:grid;grid-template-columns:1fr 1fr 160px 160px 180px auto;gap:12px;align-items:end;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:10px;font-weight:800;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;}
.fg input,.fg select{border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 12px;font-size:13px;font-family:inherit;color:#1f2937;width:100%;transition:border .2s;outline:none;background:#fafafa;}
.fg input:focus,.fg select:focus{border-color:#6366f1;background:#fff;box-shadow:0 0 0 3px rgba(99,102,241,.08);}
.mcr-filter-actions{display:flex;gap:8px;align-items:flex-end;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s;white-space:nowrap;}
.btn-primary{background:#6366f1;color:#fff;}.btn-primary:hover{background:#4f46e5;}
.btn-secondary{background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0;}.btn-secondary:hover{background:#e2e8f0;}
.btn-success{background:#16a34a;color:#fff;}.btn-success:hover{background:#15803d;}
.btn-sm{padding:7px 14px;font-size:12px;}
.btn-print{background:#1e1b4b;color:#fff;}.btn-print:hover{background:#312e81;}
.mcr-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:22px;}
.mcr-stat{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px 18px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.mcr-stat-label{font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;display:flex;align-items:center;gap:5px;}
.mcr-stat-value{font-size:20px;font-weight:900;color:#0f172a;line-height:1;}
.mcr-stat-sub{font-size:10px;color:#94a3b8;margin-top:4px;font-weight:600;}
.mcr-stat.s-market{border-color:#ef4444;background:linear-gradient(135deg,#fff5f5,#fff);}
.mcr-stat.s-market .mcr-stat-value{color:#dc2626;}
.mcr-stat.s-out{border-color:#f59e0b;background:linear-gradient(135deg,#fffbeb,#fff);}
.mcr-stat.s-out .mcr-stat-value{color:#d97706;}
.mcr-stat.s-cih{border-color:#3b82f6;background:linear-gradient(135deg,#eff6ff,#fff);}
.mcr-stat.s-cih .mcr-stat-value{color:#2563eb;}
.mcr-stat.s-ret{border-color:#8b5cf6;background:linear-gradient(135deg,#f5f3ff,#fff);}
.mcr-stat.s-ret .mcr-stat-value{color:#7c3aed;}
.mcr-stat.s-count{border-color:#06b6d4;background:linear-gradient(135deg,#ecfeff,#fff);}
.mcr-stat.s-count .mcr-stat-value{color:#0891b2;}
.mcr-table-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.mcr-toolbar{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:10px;}
.mcr-toolbar-left{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.mcr-toolbar-title{font-size:14px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:8px;}
.pill{padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.pill-blue{background:#dbeafe;color:#1e40af;}
.pill-violet{background:#ede9fe;color:#5b21b6;}
.mcr-search-wrap{position:relative;display:flex;align-items:center;}
.mcr-search-wrap i.si{position:absolute;left:10px;color:#94a3b8;font-size:12px;pointer-events:none;}
.mcr-search-wrap input{border:1.5px solid #e2e8f0;border-radius:8px;padding:7px 12px 7px 32px;font-size:12.5px;font-family:inherit;color:#1f2937;width:280px;transition:all .2s;outline:none;background:#fafafa;}
.mcr-search-wrap input:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.08);width:320px;background:#fff;}
.dt-wrap{overflow-x:auto;max-height:72vh;overflow-y:auto;}
.mcr-table{width:100%;border-collapse:collapse;font-size:12.5px;min-width:900px;}
.mcr-table thead th{padding:10px;text-align:left;font-weight:800;font-size:10px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);position:sticky;top:0;z-index:10;text-transform:uppercase;letter-spacing:.05em;}
.mcr-table thead th:last-child{border-right:none;}
.mcr-table thead th.tr{text-align:right;}.mcr-table thead th.tc{text-align:center;}

/* customer row */
.mcr-table tbody tr.cust-row{border-top:2px solid #e0e7ff;cursor:pointer;}
.mcr-table tbody tr.cust-row td{background:#f5f3ff;font-weight:700;transition:background .1s;}
.mcr-table tbody tr.cust-row:hover td{background:#ede9fe !important;}
.mcr-table tbody tr.cust-row.mc-row-high td{background:#fff1f2;}
.mcr-table tbody tr.cust-row.mc-row-med  td{background:#fffbeb;}
.mcr-table tbody tr.cust-row.mc-row-low  td{background:#f0fdf4;}
.mcr-table tbody tr.cust-row.mc-row-high:hover td{background:#fee2e2 !important;}
.mcr-table tbody tr.cust-row.mc-row-med:hover  td{background:#fef3c7 !important;}
.mcr-table tbody tr.cust-row.mc-row-low:hover  td{background:#dcfce7 !important;}

/* invoice sub-rows */
.mcr-table tbody tr.inv-row td{background:#fafafa;font-size:11.5px;color:#475569;border-top:1px dashed #e5e7eb;}
.mcr-table tbody tr.inv-row:last-of-type td{border-bottom:2px solid #e0e7ff;}
.mcr-table tbody tr.inv-row.inv-hidden{display:none;}

.mcr-table tbody tr.row-hidden{display:none !important;}
.mcr-table tbody td{padding:9px 10px;vertical-align:middle;}
.mcr-table tbody td.tr{text-align:right;}.mcr-table tbody td.tc{text-align:center;}
.mcr-table tfoot td{padding:10px;font-weight:900;font-size:13px;background:#0f172a;color:#e2e8f0;border-top:2px solid #1e293b;position:sticky;bottom:0;}
.mcr-table tfoot td.tr{text-align:right;}

.tcode-pill{font-family:'Courier New',monospace;font-size:11.5px;font-weight:800;color:#4338ca;}
.sr-badge{background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700;}
.route-badge{background:#eff6ff;color:#1d4ed8;padding:2px 8px;border-radius:7px;font-size:11px;font-weight:600;}
.cust-name{font-weight:700;color:#0f172a;font-size:12.5px;}
.cust-sub{font-size:10px;color:#94a3b8;margin-top:2px;}
.toggle-icon{font-size:10px;color:#6366f1;margin-left:5px;transition:transform .2s;display:inline-block;}
.toggle-icon.open{transform:rotate(90deg);}
.inv-num-badge{font-family:'Courier New',monospace;font-size:11px;font-weight:700;color:#4338ca;background:#eef2ff;padding:1px 7px;border-radius:5px;}
.inv-date-badge{font-size:10px;color:#94a3b8;margin-left:5px;}
.amt-out{color:#d97706;font-weight:700;}
.amt-cih{color:#2563eb;font-weight:700;}
.amt-ret{color:#7c3aed;font-weight:700;}
.amt-zero{color:#d1d5db;font-weight:400;}
.amt-mc{font-weight:900;font-size:13px;}
.mc-high{color:#dc2626;}.mc-med{color:#d97706;}.mc-low{color:#16a34a;}
.cih-breakdown{display:flex;gap:4px;flex-wrap:wrap;margin-top:4px;}
.cih-tag{font-size:9px;font-weight:700;padding:1px 6px;border-radius:5px;white-space:nowrap;}
.cih-tag-p{background:#fef3c7;color:#92400e;}
.cih-tag-t{background:#e0f2fe;color:#0369a1;}
.cih-tag-d{background:#dbeafe;color:#1e40af;}
.cih-tag-s{background:#fdf4ff;color:#7e22ce;}
.mc-bar-wrap{height:4px;background:#f1f5f9;border-radius:2px;margin-top:5px;overflow:hidden;}
.mc-bar{height:100%;border-radius:2px;}
.inv-count-pill{background:#eef2ff;color:#4338ca;border-radius:12px;padding:1px 8px;font-size:10px;font-weight:700;margin-left:6px;}
#mcr-toast{position:fixed;bottom:24px;right:24px;z-index:99999;padding:12px 22px;border-radius:10px;font-size:13px;font-weight:700;box-shadow:0 6px 24px rgba(0,0,0,.2);color:#fff;transform:translateY(80px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none;}
#mcr-toast.show{transform:translateY(0);opacity:1;}
.mcr-empty{text-align:center;padding:80px 20px;color:#94a3b8;}
.mcr-empty i{font-size:52px;display:block;margin-bottom:16px;opacity:.3;}
.mcr-empty p{font-size:15px;font-weight:600;}
.select2-container .select2-selection--single{height:38px !important;border:1.5px solid #e2e8f0 !important;border-radius:8px !important;background:#fafafa !important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:36px !important;padding-left:12px !important;font-size:13px !important;font-family:inherit !important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:36px !important;}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:#6366f1 !important;background:#fff !important;}
.select2-dropdown{border:1.5px solid #e2e8f0 !important;border-radius:10px !important;box-shadow:0 8px 30px rgba(0,0,0,.12) !important;font-size:13px !important;z-index:10000000 !important;}
.select2-results__option--highlighted{background:#6366f1 !important;}
@media(max-width:1100px){.mcr-filter-grid{grid-template-columns:1fr 1fr 1fr 1fr;}.mcr-stats{grid-template-columns:repeat(3,1fr);}}
@media(max-width:700px){.mcr-filter-grid{grid-template-columns:1fr 1fr;}.mcr-stats{grid-template-columns:1fr 1fr;}}
@media print{
    @page{margin:8mm;size:A4 landscape;}
    .no-print,.mcr-filter-card,.mcr-header-actions,.mcr-toolbar,#mcr-toast{display:none !important;}
    *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;}
    .mcr-table thead th{background:#1e1b4b !important;}
    .mcr-table tfoot td{background:#0f172a !important;}
    .dt-wrap{max-height:none !important;overflow:visible !important;}
    .inv-row.inv-hidden{display:table-row !important;}
}
</style>

<!-- PAGE HEADER -->
<div class="mcr-page-header">
    <div>
        <h2 class="mcr-page-title">
            <i class="fa-solid fa-chart-pie" style="color:#6366f1;"></i> Market Credit Report
        </h2>
        <p class="mcr-page-subtitle">
            <strong>Market Credit</strong> = Outstanding Invoices + Cheques in Hand + Returned Cheques (unsettled)
            &nbsp;&mdash;&nbsp; <i class="fa-solid fa-hand-pointer" style="font-size:11px;color:#6366f1;"></i> Click a customer row to expand/collapse their invoices
        </p>
    </div>
    <div class="mcr-header-actions no-print">
        <button class="btn btn-print btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        <button class="btn btn-print btn-sm" onclick="openPrintView()" style="background:#312e81;"><i class="fa-solid fa-file-pdf"></i> Print View</button>
        <button class="btn btn-success btn-sm" onclick="exportCSV()"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
    </div>
</div>

<!-- FILTERS -->
<div class="mcr-filter-card no-print">
    <div class="mcr-filter-title"><i class="fa-solid fa-sliders"></i> Filters</div>
    <form method="GET">
        <div class="mcr-filter-grid">
            <div class="fg">
                <label><i class="fa-solid fa-route"></i> Route</label>
                <select name="route" id="fRoute" style="width:100%;">
                    <option value="">— All Routes —</option>
                    <?php foreach($all_routes as $rt): ?>
                    <option value="<?=htmlspecialchars($rt['route_code'])?>" <?=$f_route===$rt['route_code']?'selected':''?>>
                        <?=htmlspecialchars($rt['route_code'].' — '.$rt['route_name'])?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label><i class="fa-solid fa-id-badge"></i> SR Code</label>
                <select name="sr_code" id="fSR" style="width:100%;">
                    <option value="">— All SR Codes —</option>
                    <?php foreach($all_sr as $sr): ?>
                    <option value="<?=htmlspecialchars($sr)?>" <?=$f_sr===$sr?'selected':''?>><?=htmlspecialchars($sr)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label><i class="fa-solid fa-user-tag"></i> T-Code</label>
                <input type="text" name="t_code" value="<?=htmlspecialchars($f_tcode)?>" placeholder="Search T-Code…">
            </div>
            <div class="fg">
                <label><i class="fa-solid fa-calendar-check"></i> As At Date</label>
                <input type="date" name="as_at_date" value="<?=htmlspecialchars($f_as_at)?>">
            </div>
            <div class="fg">
                <label><i class="fa-solid fa-eye"></i> Show Zero Rows</label>
                <select name="hide_zero">
                    <option value="1" <?=$f_hide_zero?'selected':''?>>Hide Zero Market Credit</option>
                    <option value="0" <?=!$f_hide_zero?'selected':''?>>Show All</option>
                </select>
            </div>
            <div class="mcr-filter-actions">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
                <a href="market_credit_report.php" class="btn btn-secondary" title="Clear"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </div>
    </form>
</div>

<!-- STAT CARDS -->
<div class="mcr-stats">
    <div class="mcr-stat s-count">
        <div class="mcr-stat-label"><i class="fa-solid fa-users"></i> Customers</div>
        <div class="mcr-stat-value" id="sc-count"><?=$t_count?></div>
        <div class="mcr-stat-sub">in report</div>
    </div>
    <div class="mcr-stat s-out">
        <div class="mcr-stat-label"><i class="fa-solid fa-file-invoice-dollar"></i> Outstanding</div>
        <div class="mcr-stat-value" id="sc-out">Rs.&nbsp;<?=number_format($t_outstanding,0)?></div>
        <div class="mcr-stat-sub">Unpaid invoice balances<?=$f_as_at?' (as at '.date('d M Y',strtotime($f_as_at)).')':''?></div>
    </div>
    <div class="mcr-stat s-cih">
        <div class="mcr-stat-label"><i class="fa-solid fa-money-check"></i> Cheques in Hand</div>
        <div class="mcr-stat-value" id="sc-cih">Rs.&nbsp;<?=number_format($t_cheques_in_hand,0)?></div>
        <div class="mcr-stat-sub">Pending + To Be Bank + Deposited + Sent Back</div>
    </div>
    <div class="mcr-stat s-ret">
        <div class="mcr-stat-label"><i class="fa-solid fa-circle-xmark"></i> Returned Cheques</div>
        <div class="mcr-stat-value" id="sc-ret">Rs.&nbsp;<?=number_format($t_returned,0)?></div>
        <div class="mcr-stat-sub">Unsettled returned / bounced</div>
    </div>
    <div class="mcr-stat s-market">
        <div class="mcr-stat-label"><i class="fa-solid fa-circle-dollar-to-slot"></i> Total Market Credit</div>
        <div class="mcr-stat-value" id="sc-mc">Rs.&nbsp;<?=number_format($t_market_credit,0)?></div>
        <div class="mcr-stat-sub">Outstanding + CIH + Returned</div>
    </div>
</div>

<?php if(empty($report_rows)): ?>
<div class="mcr-table-card">
    <div class="mcr-empty"><i class="fa-solid fa-inbox"></i><p>No data found.</p><small>Try adjusting your filters.</small></div>
</div>
<?php else: ?>

<?php if($f_as_at): ?>
<div style="display:flex;align-items:center;gap:8px;background:#fef3c7;border:1.5px solid #fde68a;border-radius:8px;padding:8px 14px;margin-bottom:14px;font-size:12px;font-weight:600;color:#92400e;" class="no-print">
    <i class="fa-solid fa-clock-rotate-left"></i>
    <strong>As At View:</strong> Outstanding balances as of <strong><?=date('d M Y',strtotime($f_as_at))?></strong>.
</div>
<?php endif; ?>

<!-- TABLE -->
<div class="mcr-table-card">
    <div class="mcr-toolbar no-print">
        <div class="mcr-toolbar-left">
            <div class="mcr-toolbar-title">
                <i class="fa-solid fa-table"></i> Market Credit Report
                <span class="pill pill-blue" id="vis-count-badge"><?=$t_count?> customers</span>
            </div>
            <?php if($f_route): ?><span class="pill pill-violet">Route: <?=htmlspecialchars($f_route)?></span><?php endif; ?>
            <?php if($f_sr):    ?><span class="pill pill-violet">SR: <?=htmlspecialchars($f_sr)?></span><?php endif; ?>
            <?php if($f_as_at): ?><span class="pill" style="background:#fef3c7;color:#92400e;">As At: <?=date('d M Y',strtotime($f_as_at))?></span><?php endif; ?>
            <button class="btn btn-secondary btn-sm" onclick="expandAll()" style="font-size:11px;padding:4px 10px;"><i class="fa-solid fa-angles-down"></i> Expand All</button>
            <button class="btn btn-secondary btn-sm" onclick="collapseAll()" style="font-size:11px;padding:4px 10px;"><i class="fa-solid fa-angles-up"></i> Collapse All</button>
        </div>
        <div class="mcr-search-wrap">
            <i class="fa-solid fa-magnifying-glass si"></i>
            <input type="text" id="mcrSearch" placeholder="Search T-Code, customer, SR, route…" autocomplete="off">
        </div>
    </div>
    <div class="dt-wrap">
    <table class="mcr-table" id="mcrTable">
        <thead>
        <tr>
            <th style="width:32px;">No</th>
            <th style="width:54px;">T-Code</th>
            <th>Customer / Invoice</th>
            <th class="tc" style="width:44px;">SR</th>
            <th class="tc" style="width:54px;">Route</th>
            <th class="tr" style="width:150px;">Outstanding<?=$f_as_at?'<br><span style="font-size:8px;font-weight:400;opacity:.6;">(as at)</span>':''?></th>
            <th class="tr" style="width:140px;">Cheques in Hand</th>
            <th class="tr" style="width:110px;">Returned</th>
            <th class="tr" style="width:140px;">Market Credit</th>
        </tr>
        </thead>
        <tbody id="mcrTbody">
        <?php
        $max_mc = $report_rows[0]['market_credit'] ?? 1;
        if($max_mc <= 0) $max_mc = 1;
        $rn = 1;
        foreach($report_rows as $row):
            $mc           = $row['market_credit'];
            $mc_cls       = $mc >= 100000 ? 'mc-high' : ($mc >= 25000 ? 'mc-med' : 'mc-low');
            $row_bg_cls   = $mc >= 100000 ? 'mc-row-high' : ($mc >= 25000 ? 'mc-row-med' : 'mc-row-low');
            $mc_bar_color = $mc >= 100000 ? '#ef4444' : ($mc >= 25000 ? '#f59e0b' : '#22c55e');
            $mc_bar_pct   = min(100, round($mc / $max_mc * 100));
            $cnts         = $row['cih_counts'];
            $invoices     = $row['invoices'];
            $inv_count    = count($invoices);
            $has_multi    = $inv_count > 1;

            $cih_html = '';
            if($row['cheques_in_hand'] > 0){
                if($cnts['pending']    > 0) $cih_html .= '<span class="cih-tag cih-tag-p">P:'.$cnts['pending'].'</span>';
                if($cnts['to_be_bank'] > 0) $cih_html .= '<span class="cih-tag cih-tag-t">TBB:'.$cnts['to_be_bank'].'</span>';
                if($cnts['deposited']  > 0) $cih_html .= '<span class="cih-tag cih-tag-d">Dep:'.$cnts['deposited'].'</span>';
                if($cnts['sent_back']  > 0) $cih_html .= '<span class="cih-tag cih-tag-s">SB:'.$cnts['sent_back'].'</span>';
            }
            $search_str = strtolower(
                $row['t_code'].' '.$row['customer_name'].' '.
                $row['sr_code'].' '.$row['route_code'].' '.$row['route_name']
            );
            $tc_safe = htmlspecialchars($row['t_code']);
        ?>
        <!-- Customer row -->
        <tr class="cust-row <?=$row_bg_cls?>"
            data-search="<?=htmlspecialchars($search_str)?>"
            data-mc="<?=$mc?>"
            data-out="<?=$row['outstanding']?>"
            data-cih="<?=$row['cheques_in_hand']?>"
            data-ret="<?=$row['returned_cheques']?>"
            data-tc="<?=$tc_safe?>"
            data-has-multi="<?=$has_multi?1:0?>"
            onclick="toggleInvoices('<?=$tc_safe?>', this)">
            <td class="tc" style="color:#94a3b8;font-size:11px;font-weight:600;" data-rn><?=$rn++?></td>
            <td><span class="tcode-pill"><?=$tc_safe?></span></td>
            <td>
                <div class="cust-name">
                    <?=htmlspecialchars($row['customer_name'])?>
                    <?php if($has_multi): ?>
                    <span class="inv-count-pill"><?=$inv_count?> invoices</span>
                    <span class="toggle-icon" id="ti-<?=$tc_safe?>">&#9658;</span>
                    <?php elseif($inv_count === 1): ?>
                    <span style="font-size:10px;color:#94a3b8;margin-left:6px;font-family:monospace;"><?=htmlspecialchars($invoices[0]['invoice_num']??'')?></span>
                    <?php endif; ?>
                </div>
                <?php if($row['route_name'] && $row['route_name'] !== $row['route_code']): ?>
                <div class="cust-sub"><?=htmlspecialchars($row['route_name'])?></div>
                <?php endif; ?>
            </td>
            <td class="tc"><?=$row['sr_code']?'<span class="sr-badge">'.htmlspecialchars($row['sr_code']).'</span>':''?></td>
            <td class="tc"><?=$row['route_code']?'<span class="route-badge">'.htmlspecialchars($row['route_code']).'</span>':''?></td>
            <td class="tr">
                <?php if($row['outstanding'] > 0): ?>
                <span class="amt-out">Rs.&nbsp;<?=number_format($row['outstanding'],2)?></span>
                <?php else: ?><span class="amt-zero">—</span><?php endif; ?>
            </td>
            <td class="tr">
                <?php if($row['cheques_in_hand'] > 0): ?>
                <span class="amt-cih">Rs.&nbsp;<?=number_format($row['cheques_in_hand'],2)?></span>
                <?php if($cih_html): ?><div class="cih-breakdown"><?=$cih_html?></div><?php endif; ?>
                <?php else: ?><span class="amt-zero">—</span><?php endif; ?>
            </td>
            <td class="tr">
                <?php if($row['returned_cheques'] > 0): ?>
                <span class="amt-ret">Rs.&nbsp;<?=number_format($row['returned_cheques'],2)?></span>
                <?php if($row['ret_count'] > 0): ?>
                <div style="font-size:9px;color:#7c3aed;margin-top:2px;"><i class="fa-solid fa-circle-xmark" style="font-size:8px;"></i> <?=$row['ret_count']?> cheque<?=$row['ret_count']>1?'s':''?></div>
                <?php endif; ?>
                <?php else: ?><span class="amt-zero">—</span><?php endif; ?>
            </td>
            <td class="tr">
                <span class="amt-mc <?=$mc_cls?>">Rs.&nbsp;<?=number_format($mc,2)?></span>
                <div class="mc-bar-wrap"><div class="mc-bar" style="width:<?=$mc_bar_pct?>%;background:<?=$mc_bar_color?>;"></div></div>
            </td>
        </tr>
        <?php if($has_multi): ?>
        <!-- Invoice sub-rows (hidden by default, shown on click) -->
        <?php foreach($invoices as $idx => $inv): ?>
        <tr class="inv-row inv-hidden" data-parent-tc="<?=$tc_safe?>">
            <td></td>
            <td></td>
            <td style="padding-left:28px;">
                <span style="color:#c7d2fe;margin-right:4px;">&#8627;</span>
                <span class="inv-num-badge"><?=htmlspecialchars($inv['invoice_num'])?></span>
                <?php if($inv['delivery_date']): ?>
                <span class="inv-date-badge"><i class="fa-solid fa-calendar-day" style="font-size:9px;"></i> <?=date('d M Y',strtotime($inv['delivery_date']))?></span>
                <?php endif; ?>
            </td>
            <td></td>
            <td></td>
            <td class="tr">
                <span class="amt-out" style="font-size:12px;">Rs.&nbsp;<?=number_format($inv['outstanding'],2)?></span>
            </td>
            <td></td>
            <td></td>
            <td></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr>
            <td colspan="5" style="text-align:right;font-size:10px;opacity:.75;" id="foot-label">
                TOTAL — <?=$t_count?> customers
            </td>
            <td class="tr" id="foot-out">Rs.&nbsp;<?=number_format($t_outstanding,2)?></td>
            <td class="tr" id="foot-cih">Rs.&nbsp;<?=number_format($t_cheques_in_hand,2)?></td>
            <td class="tr" id="foot-ret">Rs.&nbsp;<?=number_format($t_returned,2)?></td>
            <td class="tr" id="foot-mc">Rs.&nbsp;<?=number_format($t_market_credit,2)?></td>
        </tr>
        </tfoot>
    </table>
    </div>
</div>
<?php endif; ?>

<div id="mcr-toast"></div>

<script>
$(function(){
    $('#fRoute').select2({placeholder:'— All Routes —',allowClear:true,width:'100%'});
    $('#fSR').select2({placeholder:'— All SR Codes —',allowClear:true,width:'100%'});
});

/* ── Toggle invoice sub-rows ── */
function toggleInvoices(tc, custRow) {
    if(!custRow.dataset.hasMulti || custRow.dataset.hasMulti === '0') return;
    const icon = document.getElementById('ti-' + tc);
    const subRows = document.querySelectorAll(`.inv-row[data-parent-tc="${CSS.escape(tc)}"]`);
    const isOpen = icon && icon.classList.contains('open');
    subRows.forEach(r => r.classList.toggle('inv-hidden', isOpen));
    if(icon) icon.classList.toggle('open', !isOpen);
}

function expandAll() {
    document.querySelectorAll('.inv-row').forEach(r => r.classList.remove('inv-hidden'));
    document.querySelectorAll('.toggle-icon').forEach(i => i.classList.add('open'));
}

function collapseAll() {
    document.querySelectorAll('.inv-row').forEach(r => r.classList.add('inv-hidden'));
    document.querySelectorAll('.toggle-icon').forEach(i => i.classList.remove('open'));
}

/* ── Live search ── */
(function(){
    const input   = document.getElementById('mcrSearch');
    if(!input) return;
    const custRows = Array.from(document.querySelectorAll('#mcrTbody tr.cust-row'));
    function fmtM(v){ return 'Rs.\u00a0'+parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    input.addEventListener('input', function(){
        const q = this.value.trim().toLowerCase();
        let count=0,out=0,cih=0,ret=0,mc=0,rn=1;
        custRows.forEach(tr=>{
            const match = !q || (tr.dataset.search||'').includes(q);
            tr.classList.toggle('row-hidden', !match);
            // also hide/show associated inv-rows
            const tc = tr.dataset.tc || '';
            if(tc) {
                document.querySelectorAll(`.inv-row[data-parent-tc="${CSS.escape(tc)}"]`)
                    .forEach(r => r.classList.toggle('row-hidden', !match));
            }
            if(match){
                tr.querySelector('[data-rn]').textContent = rn++;
                count++;
                out += parseFloat(tr.dataset.out||0);
                cih += parseFloat(tr.dataset.cih||0);
                ret += parseFloat(tr.dataset.ret||0);
                mc  += parseFloat(tr.dataset.mc||0);
            }
        });
        const badge = document.getElementById('vis-count-badge');
        if(badge) badge.textContent = count+' customers';
        const sc = document.getElementById('sc-count');   if(sc)  sc.textContent  = count;
        const so = document.getElementById('sc-out');     if(so)  so.textContent  = fmtM(out);
        const sc2= document.getElementById('sc-cih');     if(sc2) sc2.textContent = fmtM(cih);
        const sr = document.getElementById('sc-ret');     if(sr)  sr.textContent  = fmtM(ret);
        const sm = document.getElementById('sc-mc');      if(sm)  sm.textContent  = fmtM(mc);
        const fl = document.getElementById('foot-label'); if(fl)  fl.textContent  = 'TOTAL — '+count+' customers';
        const fo = document.getElementById('foot-out');   if(fo)  fo.textContent  = fmtM(out);
        const fc = document.getElementById('foot-cih');   if(fc)  fc.textContent  = fmtM(cih);
        const fr = document.getElementById('foot-ret');   if(fr)  fr.textContent  = fmtM(ret);
        const fm = document.getElementById('foot-mc');    if(fm)  fm.textContent  = fmtM(mc);
    });
})();

function openPrintView(){
    const params = new URLSearchParams(window.location.search);
    params.set('printview','1');
    window.open('market_credit_report.php?'+params.toString(),'_blank');
}

function exportCSV(){
    const rows = document.querySelectorAll('#mcrTbody tr.cust-row:not(.row-hidden)');
    if(!rows.length){ alert('No data to export.'); return; }
    const e = v => '"'+(v||'').toString().replace(/"/g,'""').replace(/\s+/g,' ').trim()+'"';
    const headers = ['No','T-Code','Customer Name','SR Code','Route','Outstanding','Invoices (detail)','Cheques in Hand','Returned Cheques','Market Credit'];
    const lines   = [headers.join(',')];
    rows.forEach((tr,i)=>{
        const cells = tr.querySelectorAll('td');
        const tc = tr.dataset.tc || '';
        // build invoice detail string
        const invRows = document.querySelectorAll(`.inv-row[data-parent-tc="${CSS.escape(tc)}"]`);
        let invDetail = '';
        if(invRows.length > 0){
            const parts = [];
            invRows.forEach(ir => {
                const badge = ir.querySelector('.inv-num-badge');
                const dateEl = ir.querySelector('.inv-date-badge');
                const amtEl = ir.querySelector('.amt-out');
                if(badge) parts.push((badge.textContent.trim())+(dateEl?' '+dateEl.textContent.trim():'')+(amtEl?' Rs.'+amtEl.textContent.replace(/Rs\.?\s*/,'').trim():''));
            });
            invDetail = parts.join(' | ');
        }
        lines.push([
            i+1,
            e(cells[1]?.textContent),
            e(cells[2]?.querySelector('.cust-name')?.childNodes[0]?.textContent.trim()||cells[2]?.textContent),
            e(cells[3]?.textContent),
            e(cells[4]?.textContent),
            parseFloat(tr.dataset.out||0).toFixed(2),
            e(invDetail),
            parseFloat(tr.dataset.cih||0).toFixed(2),
            parseFloat(tr.dataset.ret||0).toFixed(2),
            parseFloat(tr.dataset.mc||0).toFixed(2),
        ].join(','));
    });
    const blob = new Blob([lines.join('\n')],{type:'text/csv'});
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href=url; a.download='market_credit_report_<?=date("Ymd_Hi")?>.csv'; a.click();
    URL.revokeObjectURL(url);
}

function showToast(msg,type='success'){
    const t=document.getElementById('mcr-toast');
    t.style.background=type==='success'?'#166834':'#dc2626';
    t.textContent=msg; t.classList.add('show');
    setTimeout(()=>t.classList.remove('show'),3200);
}
</script>

<?php include 'footer.php'; ?>