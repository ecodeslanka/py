<?php
include 'config.php';

/* ── Filters ── */
$f_route     = trim($_GET['route']          ?? '');
$f_sr        = trim($_GET['sr_code']        ?? '');
$f_date      = trim($_GET['delivery_date']  ?? '');
$f_as_at     = trim($_GET['as_at_date']     ?? date('Y-m-d'));
$f_type      = trim($_GET['credit_type']    ?? '');

/* Multi-select age ranges — sent as age_range[] array */
$f_age_ranges = [];
if (!empty($_GET['age_range'])) {
    $raw = is_array($_GET['age_range']) ? $_GET['age_range'] : [$_GET['age_range']];
    $valid_buckets = ['1-7','8-14','15-21','22-28','29-35','35+'];
    foreach ($raw as $v) {
        $v = trim($v);
        if (in_array($v, $valid_buckets)) $f_age_ranges[] = $v;
    }
}

$as_at_ts      = strtotime($f_as_at);
$as_at_display = date('d M Y', $as_at_ts);
$as_at_esc     = mysqli_real_escape_string($conn, $f_as_at);

/* ── WHERE ── */
$where = ["fsd.updated = 1"];
if ($f_route) $where[] = "fs.route = '"         . mysqli_real_escape_string($conn, $f_route) . "'";
if ($f_sr)    $where[] = "fs.sr_code = '"       . mysqli_real_escape_string($conn, $f_sr)    . "'";
if ($f_date)  $where[] = "fs.delivery_date = '" . mysqli_real_escape_string($conn, $f_date)  . "'";
$where[] = "fs.delivery_date <= '$as_at_esc'";
if ($f_type === 'special') $where[] = "cr.detail_id IS NOT NULL";
if ($f_type === 'normal')  $where[] = "cr.detail_id IS NULL";
$where_sql = implode(' AND ', $where);

/* ── Payment date filter ── */
$pay_date_filter = "WHERE is_reversed = 0 AND payment_date <= '$as_at_esc'";

/* ── Aging bucket helper ── */
function agingBucket($days) {
    if ($days <= 7)  return '1-7';
    if ($days <= 14) return '8-14';
    if ($days <= 21) return '15-21';
    if ($days <= 28) return '22-28';
    if ($days <= 35) return '29-35';
    return '35+';
}

/* ── Main Query ── */
$sql = "
SELECT
    fsd.id                                                                              AS detail_id,
    fs.route                                                                            AS route_code,
    COALESCE(r.route_name, fs.route)                                                    AS route_name,
    fs.sr_code,
    fs.delivery_date,
    fsd.t_code,
    COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code)                    AS customer_name,
    fsd.invoice_num,
    COALESCE(siid.final_bill_amount, fsd.adjust_net_value)                             AS net_value,
    COALESCE(pay.cash_paid,   0)                                                        AS cash_paid,
    COALESCE(pay.cheque_paid, 0)                                                        AS cheque_paid,
    COALESCE(pay.total_paid,  0)                                                        AS total_paid,
    COALESCE(cn.total_cn,     0)                                                        AS total_cn,
    (COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
        - COALESCE(pay.total_paid, 0)
        - COALESCE(cn.total_cn, 0))                                                     AS balance,
    CASE WHEN cr.detail_id IS NOT NULL THEN 1 ELSE 0 END                               AS is_special,
    COALESCE(c.credit_days, 0)                                                          AS credit_days
FROM field_summary_details fsd
INNER JOIN field_summary fs  ON fs.id        = fsd.field_summary_id
LEFT  JOIN routes r          ON r.route_code = fs.route
LEFT  JOIN customers c       ON c.t_code     = fsd.t_code
LEFT  JOIN (
    SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
    FROM secondary_invoice_import_details GROUP BY bill_no
) siid ON siid.bill_no = fsd.invoice_num
LEFT  JOIN (
    SELECT field_summary_detail_id,
           SUM(amount)                                                             AS total_paid,
           SUM(CASE WHEN payment_method = 'cash'             THEN amount ELSE 0 END) AS cash_paid,
           SUM(CASE WHEN payment_method IN ('cheque','check') THEN amount ELSE 0 END) AS cheque_paid
    FROM   invoice_payments
    $pay_date_filter
    GROUP  BY field_summary_detail_id
) pay ON pay.field_summary_detail_id = fsd.id
LEFT  JOIN (
    SELECT field_summary_detail_id, SUM(amount) AS total_cn
    FROM   credit_notes WHERE is_deleted = 0
    GROUP  BY field_summary_detail_id
) cn ON cn.field_summary_detail_id = fsd.id
LEFT  JOIN (
    SELECT field_summary_detail_id AS detail_id
    FROM   credit_requests GROUP BY field_summary_detail_id
) cr ON cr.detail_id = fsd.id
WHERE $where_sql
  AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
       - COALESCE(pay.total_paid, 0)
       - COALESCE(cn.total_cn, 0)) > 0
ORDER BY fs.delivery_date ASC, fs.route, fs.sr_code, fsd.invoice_num
";

$result = mysqli_query($conn, $sql);
$rows   = [];
$t_net = $t_cash = $t_cheque = $t_cn = $t_balance = 0;
$t_special = $t_normal = 0;
$t_policy_overdue = 0;

/* Aging buckets */
$buckets = [
    '1-7'   => ['count' => 0, 'balance' => 0, 'label' => '1–7 Days'],
    '8-14'  => ['count' => 0, 'balance' => 0, 'label' => '8–14 Days'],
    '15-21' => ['count' => 0, 'balance' => 0, 'label' => '15–21 Days'],
    '22-28' => ['count' => 0, 'balance' => 0, 'label' => '22–28 Days'],
    '29-35' => ['count' => 0, 'balance' => 0, 'label' => '29–35 Days'],
    '35+'   => ['count' => 0, 'balance' => 0, 'label' => '35+ Days'],
];

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $del_ts     = $row['delivery_date'] ? strtotime($row['delivery_date']) : $as_at_ts;
        $aging_days = max(0, (int)floor(($as_at_ts - $del_ts) / 86400));
        $row['aging_days'] = $aging_days;

        $credit_days_val     = intval($row['credit_days']);
        $due_ts              = $del_ts + ($credit_days_val * 86400);
        $row['due_date']     = date('Y-m-d', $due_ts);
        $row['policy_aging'] = (int)floor(($as_at_ts - $due_ts) / 86400);

        $bucket = agingBucket($aging_days);
        $row['bucket'] = $bucket;

        /* Multi-select age range filter */
        if (!empty($f_age_ranges) && !in_array($bucket, $f_age_ranges)) continue;

        $rows[]     = $row;
        $t_net     += floatval($row['net_value']);
        $t_cash    += floatval($row['cash_paid']);
        $t_cheque  += floatval($row['cheque_paid']);
        $t_cn      += floatval($row['total_cn']);
        $t_balance += floatval($row['balance']);
        if ($row['is_special']) $t_special++; else $t_normal++;
        if ($row['policy_aging'] > 0) $t_policy_overdue++;

        $buckets[$bucket]['count']++;
        $buckets[$bucket]['balance'] += floatval($row['balance']);
    }
}
$total = count($rows);

/* ── Helpers ── */
function a($v)  { return number_format(floatval($v), 2); }
function t5($s) { return substr(trim($s), -5); }

/* ── Filter line ── */
$fl = [];
if ($f_route)              $fl[] = 'Route: '     . htmlspecialchars($f_route);
if ($f_sr)                 $fl[] = 'SR: '        . htmlspecialchars($f_sr);
if ($f_date)               $fl[] = 'Delivery: '  . date('d M Y', strtotime($f_date));
if ($f_type)               $fl[] = 'Type: '      . ucfirst($f_type);
if (!empty($f_age_ranges)) $fl[] = 'Age Range: ' . implode(', ', array_map('htmlspecialchars', $f_age_ranges)) . ' Days';
$fl[] = 'As At: ' . $as_at_display;
if (count($fl) === 1)      $fl[] = 'All Records';
$filter_str = implode('  |  ', $fl);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Credit Aging Report</title>
<style>

/* ════════════════════════════════════════
   GLOBAL
════════════════════════════════════════ */
*, *::before, *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
    font-family: Tahoma, Geneva, Verdana, sans-serif;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* ════════════════════════════════════════
   PAGE
════════════════════════════════════════ */
@page {
    size: A4 landscape;
    margin: 10mm 8mm 10mm 8mm;
}

html, body {
    font-size: 7.5pt;
    color: #000;
    background: #fff;
}

/* ════════════════════════════════════════
   SCREEN PREVIEW
════════════════════════════════════════ */
@media screen {
    body { background: #aaa; }

    .toolbar {
        position: sticky;
        top: 0;
        z-index: 99;
        background: #1a1a1a;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 9px 22px;
        font-size: 12px;
        border-bottom: 2px solid #000;
        gap: 12px;
    }
    .toolbar strong { font-size: 13px; }
    .tbtn {
        padding: 6px 18px;
        border: 1px solid #555;
        background: #333;
        color: #fff;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        font-family: Tahoma, sans-serif;
        transition: all .15s;
    }
    .tbtn:hover { background: #fff; color: #000; border-color: #000; }
    .tbtn-close { background: transparent; color: #666; border-color: #333; }
    .tbtn-close:hover { background: #cc0000; color: #fff; border-color: #cc0000; }
    .tbtn-wrap { display: flex; gap: 8px; }

    .page-wrap {
        width: 297mm;
        background: #fff;
        margin: 22px auto 50px;
        padding: 10mm 8mm;
        box-shadow: 0 6px 32px rgba(0,0,0,.35);
    }
}

/* ════════════════════════════════════════
   PRINT OVERRIDES
════════════════════════════════════════ */
@media print {
    .toolbar   { display: none !important; }
    .page-wrap { margin: 0; padding: 0; box-shadow: none; width: auto; }
    body       { background: #fff !important; }
    thead { display: table-header-group; }
    tfoot { display: table-footer-group; }
}

/* ════════════════════════════════════════
   REPORT HEADER
════════════════════════════════════════ */
.rpt-head {
    border-top: 3px solid #000;
    border-bottom: 1.5px solid #000;
    padding: 5pt 0 5pt;
    margin-bottom: 6pt;
}
.rpt-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10pt;
}
.rpt-title     { font-size: 15pt; font-weight: 700; line-height: 1.1; }
.rpt-title-sub { font-size: 7pt; color: #444; margin-top: 2pt; }
.rpt-right     { text-align: right; font-size: 7pt; color: #333; line-height: 1.8; white-space: nowrap; }
.rpt-right b   { color: #000; }
.rpt-filters   { margin-top: 4pt; font-size: 7pt; color: #333; line-height: 1.6; }
.rpt-filters b { color: #000; }

/* ════════════════════════════════════════
   AGING BUCKETS SUMMARY
════════════════════════════════════════ */
table.buckets-tbl {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 5pt;
    table-layout: fixed;
}
table.buckets-tbl td {
    border: 1px solid #000;
    padding: 3pt 5pt;
    vertical-align: top;
}
.b-lbl   { display: block; font-size: 6pt; text-transform: uppercase; letter-spacing: .08em; color: #555; margin-bottom: 1pt; }
.b-count { display: block; font-size: 8.5pt; font-weight: 700; color: #000; }
.b-bal   { display: block; font-size: 6.5pt; font-weight: 600; color: #333; white-space: nowrap; }

/* Bucket accent bars */
.b-bar { height: 3pt; border-radius: 2pt; margin-bottom: 3pt; }
.b-1-7   .b-bar { background: #2563eb; }
.b-8-14  .b-bar { background: #16a34a; }
.b-15-21 .b-bar { background: #ca8a04; }
.b-22-28 .b-bar { background: #ea580c; }
.b-29-35 .b-bar { background: #dc2626; }
.b-35p   .b-bar { background: #9333ea; }

/* ════════════════════════════════════════
   SUMMARY TOTALS BOX
════════════════════════════════════════ */
table.summary-tbl {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 7pt;
    table-layout: fixed;
}
table.summary-tbl td {
    border: 1px solid #000;
    padding: 3pt 5pt;
    vertical-align: top;
}
.s-lbl { display: block; font-size: 6pt; text-transform: uppercase; letter-spacing: .08em; color: #555; margin-bottom: 1pt; }
.s-val { display: block; font-size: 8.5pt; font-weight: 700; color: #000; white-space: nowrap; }

/* ════════════════════════════════════════
   MAIN DATA TABLE
════════════════════════════════════════ */
table.dt {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    font-size: 7pt;
}

col.c-no  { width: 10pt; }
col.c-tc  { width: 22pt; }
col.c-sr  { width: 28pt; }
col.c-cu  { width: 82pt; }
col.c-iv  { width: 46pt; }
col.c-dt  { width: 28pt; }
col.c-cd  { width: 20pt; }
col.c-dd  { width: 30pt; }
col.c-ag  { width: 24pt; }
col.c-pa  { width: 30pt; }
col.c-ty  { width: 26pt; }
col.c-vl  { width: 44pt; }
col.c-ca  { width: 44pt; }
col.c-cq  { width: 44pt; }
col.c-cn  { width: 36pt; }
col.c-bl  { width: 44pt; }

/* ── thead ── */
thead tr th {
    border: 1px solid #000;
    padding: 3pt 2.5pt;
    font-size: 6.5pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .03em;
    vertical-align: bottom;
    background: #000;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
}
thead tr th.r { text-align: right; }
thead tr th.c { text-align: center; }
thead tr th.l { text-align: left; }

thead tr th .th-note {
    display: block;
    font-size: 5pt;
    font-weight: 400;
    opacity: .65;
    text-transform: none;
    letter-spacing: 0;
    margin-top: 1pt;
}

thead tr th.th-policy {
    background: #7c3aed;
    color: #fff;
}

/* ── tbody ── */
tbody tr { page-break-inside: avoid; }

tbody td {
    border: 1px solid #999;
    padding: 2pt 2.5pt;
    vertical-align: middle;
    color: #000;
    overflow: hidden;
    line-height: 1.25;
}
tbody td.r { text-align: right; }
tbody td.c { text-align: center; }

td.num {
    white-space: nowrap;
    overflow: hidden;
    text-align: right;
    font-size: 7pt;
}
td.num-bold {
    white-space: nowrap;
    overflow: hidden;
    text-align: right;
    font-size: 7pt;
    font-weight: 700;
}

.age-chip {
    display: inline-block;
    padding: 0.5pt 3pt;
    border-radius: 3pt;
    font-size: 6.5pt;
    font-weight: 700;
    white-space: nowrap;
}
.age-1-7   { background: #dbeafe; color: #1d4ed8; }
.age-8-14  { background: #dcfce7; color: #15803d; }
.age-15-21 { background: #fefce8; color: #a16207; }
.age-22-28 { background: #fff7ed; color: #c2410c; }
.age-29-35 { background: #fef2f2; color: #b91c1c; }
.age-35p   { background: #fdf4ff; color: #7e22ce; }

.pa-ok   { display:inline-block; padding:0.5pt 3pt; border-radius:3pt; font-size:6.5pt; font-weight:700; background:#dcfce7; color:#15803d; white-space:nowrap; }
.pa-warn { display:inline-block; padding:0.5pt 3pt; border-radius:3pt; font-size:6.5pt; font-weight:700; background:#fefce8; color:#a16207; white-space:nowrap; }
.pa-crit { display:inline-block; padding:0.5pt 3pt; border-radius:3pt; font-size:6.5pt; font-weight:700; background:#581c87; color:#fff;    white-space:nowrap; }

.due-ok   { display:inline-block; padding:0.5pt 3pt; border-radius:3pt; font-size:6.5pt; font-weight:700; background:#dcfce7; color:#15803d; white-space:nowrap; }
.due-over { display:inline-block; padding:0.5pt 3pt; border-radius:3pt; font-size:6.5pt; font-weight:700; background:#fef2f2; color:#b91c1c; white-space:nowrap; }

.sp-mark {
    font-size: 5.5pt;
    font-weight: 700;
    border: 0.8px solid #000;
    padding: 0 1.5pt;
    display: inline-block;
    letter-spacing: .04em;
    line-height: 1.4;
}
.sp-mark-special { background: #fef08a; border-color: #ca8a04; color: #854d0e; }
.sp-mark-normal  { background: #f1f5f9; border-color: #94a3b8; color: #475569; }

.tc-cell  { font-size: 6.5pt; font-weight: 700; }
.sr-cell  { font-size: 6.5pt; font-weight: 700; }
.inv-cell { font-size: 6.5pt; font-weight: 700; white-space: nowrap; overflow: hidden; display: block; }
.dt-cell  { font-size: 6.5pt; white-space: nowrap; }
.cd-cell  { font-size: 6.5pt; font-weight: 700; color: #6366f1; }

.cname { font-weight: 700; font-size: 7pt; overflow: hidden; }
.rname { font-size: 6pt; color: #555; margin-top: 1pt; overflow: hidden; }

.dash { color: #bbb; }

/* ── tfoot ── */
tfoot tr td {
    border: 1px solid #000;
    padding: 3pt 2.5pt;
    font-size: 7pt;
    font-weight: 700;
    background: #000;
    color: #fff;
    white-space: nowrap;
}
tfoot tr td.r { text-align: right; }
tfoot tr td.l { text-align: left; }

/* ════════════════════════════════════════
   FOOTER NOTE
════════════════════════════════════════ */
.foot-note {
    margin-top: 5pt;
    border-top: 0.8px solid #bbb;
    padding-top: 3pt;
    font-size: 6pt;
    color: #666;
    display: flex;
    justify-content: space-between;
    gap: 8pt;
    line-height: 1.6;
}

/* ════════════════════════════════════════
   EMPTY STATE
════════════════════════════════════════ */
.empty {
    text-align: center;
    padding: 40pt;
    font-size: 10pt;
    color: #777;
    border: 1px dashed #bbb;
    margin-top: 10pt;
}
</style>
</head>
<body>

<!-- ── SCREEN TOOLBAR ── -->
<div class="toolbar">
    <strong>Credit Aging Report &mdash; Print Preview &nbsp;|&nbsp; As At: <?php echo $as_at_display; ?></strong>
    <div class="tbtn-wrap">
        <button class="tbtn" onclick="window.print()">Print / Save PDF</button>
        <button class="tbtn tbtn-close" onclick="window.close()">Close</button>
    </div>
</div>

<!-- ── PAGE ── -->
<div class="page-wrap">

    <!-- REPORT HEADER -->
    <div class="rpt-head">
        <div class="rpt-top">
            <div>
                <div class="rpt-title">Credit Aging Report</div>
                <div class="rpt-title-sub">
                    Outstanding Invoice Balances &mdash; As At <?php echo $as_at_display; ?>
                    &nbsp;&nbsp;|&nbsp;&nbsp;
                    Cash always counted &middot; Cheque: all recorded &middot; Credit notes deducted &middot; Policy = per customer credit days
                </div>
            </div>
            <div class="rpt-right">
                <b>Printed:</b> <?php echo date('d M Y, H:i'); ?><br>
                <b>Total Invoices:</b> <?php echo $total; ?><br>
                <b>Special / Normal:</b> <?php echo $t_special; ?> / <?php echo $t_normal; ?><br>
                <b>Policy Overdue:</b> <?php echo $t_policy_overdue; ?> inv.
            </div>
        </div>
        <div class="rpt-filters">
            <b>Filters:</b> <?php echo $filter_str; ?>
            &nbsp;&nbsp;<b>Note:</b> Payments &amp; balances counted up to <?php echo $as_at_display; ?> only.
            &nbsp;&nbsp;<b>Due Date</b> = Delivery Date + Customer Credit Policy Days.
            &nbsp;&nbsp;<b>Policy Aging</b>: negative = days remaining within policy &middot; positive = days past due date.
        </div>
    </div>

    <!-- AGING DISTRIBUTION -->
    <table class="buckets-tbl">
        <tr>
            <?php
            $bmap = [
                '1-7'   => 'b-1-7',
                '8-14'  => 'b-8-14',
                '15-21' => 'b-15-21',
                '22-28' => 'b-22-28',
                '29-35' => 'b-29-35',
                '35+'   => 'b-35p',
            ];
            foreach ($buckets as $key => $b):
                $cls       = $bmap[$key];
                $is_active = in_array($key, $f_age_ranges); // multi-select aware
            ?>
            <td style="width:16.66%;<?php echo $is_active ? 'background:#f5f5f5;' : ''; ?>">
                <div class="<?php echo $cls; ?>">
                    <div class="b-bar"></div>
                    <span class="b-lbl"><?php echo $b['label']; ?><?php echo $is_active ? ' ▲' : ''; ?></span>
                    <span class="b-count"><?php echo $b['count']; ?> inv.</span>
                    <span class="b-bal">Rs. <?php echo a($b['balance']); ?></span>
                </div>
            </td>
            <?php endforeach; ?>
        </tr>
    </table>

    <!-- SUMMARY TOTALS -->
    <table class="summary-tbl">
        <tr>
            <td style="width:8%;">
                <span class="s-lbl">Invoices</span>
                <span class="s-val"><?php echo $total; ?></span>
            </td>
            <td style="width:9%;">
                <span class="s-lbl">Policy Overdue</span>
                <span class="s-val" style="color:#7c3aed;"><?php echo $t_policy_overdue; ?></span>
            </td>
            <td style="width:17%;">
                <span class="s-lbl">Ikea Value (Rs.)</span>
                <span class="s-val"><?php echo a($t_net); ?></span>
            </td>
            <td style="width:15%;">
                <span class="s-lbl">Cash Paid (Rs.) *</span>
                <span class="s-val"><?php echo a($t_cash); ?></span>
            </td>
            <td style="width:15%;">
                <span class="s-lbl">Cheque Paid (Rs.) *</span>
                <span class="s-val"><?php echo a($t_cheque); ?></span>
            </td>
            <?php if ($t_cn > 0): ?>
            <td style="width:15%;">
                <span class="s-lbl">Credit Notes (Rs.)</span>
                <span class="s-val"><?php echo a($t_cn); ?></span>
            </td>
            <?php endif; ?>
            <td>
                <span class="s-lbl">Balance (Rs.) *</span>
                <span class="s-val"><?php echo a($t_balance); ?></span>
            </td>
        </tr>
    </table>

    <!-- DATA TABLE -->
    <?php if (empty($rows)): ?>
    <div class="empty">No outstanding invoices found for the selected filters.</div>
    <?php else: ?>

    <table class="dt">
        <colgroup>
            <col class="c-no">
            <col class="c-tc">
            <col class="c-sr">
            <col class="c-cu">
            <col class="c-iv">
            <col class="c-dt">
            <col class="c-cd">
            <col class="c-dd">
            <col class="c-ag">
            <col class="c-pa">
            <col class="c-ty">
            <col class="c-vl">
            <col class="c-ca">
            <col class="c-cq">
            <col class="c-cn">
            <col class="c-bl">
        </colgroup>

        <thead>
            <tr>
                <th class="c">#</th>
                <th class="l">T Code</th>
                <th class="c">SR</th>
                <th class="l">Customer / Route</th>
                <th class="l">Invoice No.</th>
                <th class="c">Del. Date</th>
                <th class="c">Cr.<br>Days</th>
                <th class="c">Due Date</th>
                <th class="c">Age<span class="th-note">as at</span></th>
                <th class="c th-policy">Policy<br>Aging<span class="th-note">past due</span></th>
                <th class="c">Type</th>
                <th class="r">Ikea Value</th>
                <th class="r">Cash *<span class="th-note">as at</span></th>
                <th class="r">Cheque *<span class="th-note">as at</span></th>
                <th class="r">C/Note</th>
                <th class="r">Balance *<span class="th-note">as at</span></th>
            </tr>
        </thead>

        <tfoot>
            <tr>
                <td colspan="11" class="l">
                    TOTAL &mdash;
                    <?php echo $total; ?> invoice<?php echo $total != 1 ? 's' : ''; ?>
                    &nbsp;(<?php echo $t_special; ?> special, <?php echo $t_normal; ?> normal)
                    &nbsp;&mdash;&nbsp; Policy overdue: <?php echo $t_policy_overdue; ?> inv.
                    &nbsp;&mdash;&nbsp; * as at <?php echo $as_at_display; ?>
                </td>
                <td class="r"><?php echo a($t_net); ?></td>
                <td class="r"><?php echo a($t_cash); ?></td>
                <td class="r"><?php echo a($t_cheque); ?></td>
                <td class="r"><?php echo a($t_cn); ?></td>
                <td class="r"><?php echo a($t_balance); ?></td>
            </tr>
        </tfoot>

        <tbody>
        <?php
        $age_cls_map = [
            '1-7'   => 'age-1-7',
            '8-14'  => 'age-8-14',
            '15-21' => 'age-15-21',
            '22-28' => 'age-22-28',
            '29-35' => 'age-29-35',
            '35+'   => 'age-35p',
        ];

        $n = 1;
        foreach ($rows as $r):
            $aging        = intval($r['aging_days']);
            $policy_aging = intval($r['policy_aging']);
            $bucket       = $r['bucket'];
            $is_overdue   = ($policy_aging > 0);
            $credit_days_v = intval($r['credit_days']);

            $delf    = $r['delivery_date'] ? date('d M y', strtotime($r['delivery_date'])) : '&mdash;';
            $due_fmt = date('d M y', strtotime($r['due_date']));

            $net  = floatval($r['net_value']);
            $cash = floatval($r['cash_paid']);
            $cheq = floatval($r['cheque_paid']);
            $cn   = floatval($r['total_cn']);
            $bal  = floatval($r['balance']);
            $sp   = intval($r['is_special']);
            $tc   = t5($r['t_code']);

            $age_cls = $age_cls_map[$bucket] ?? 'age-35p';

            if (!$is_overdue) {
                $pa_cls   = 'pa-ok';
                $pa_label = $policy_aging;
            } elseif ($policy_aging <= 7) {
                $pa_cls   = 'pa-warn';
                $pa_label = '+' . $policy_aging;
            } else {
                $pa_cls   = 'pa-crit';
                $pa_label = '+' . $policy_aging;
            }

            $due_chip_cls = $is_overdue ? 'due-over' : 'due-ok';
        ?>
        <tr>
            <td class="c" style="font-size:6pt;color:#999;"><?php echo $n++; ?></td>
            <td><span class="tc-cell"><?php echo htmlspecialchars($tc); ?></span></td>
            <td class="c"><span class="sr-cell"><?php echo htmlspecialchars($r['sr_code']); ?></span></td>
            <td>
                <div class="cname"><?php echo htmlspecialchars($r['customer_name']); ?></div>
                <div class="rname"><?php echo htmlspecialchars($r['route_name']); ?></div>
            </td>
            <td style="white-space:nowrap;overflow:hidden;">
                <span class="inv-cell"><?php echo htmlspecialchars($r['invoice_num']); ?></span>
            </td>
            <td class="c"><span class="dt-cell"><?php echo $delf; ?></span></td>
            <td class="c">
                <?php if ($credit_days_v > 0): ?>
                    <span class="cd-cell"><?php echo $credit_days_v; ?></span>
                <?php else: ?>
                    <span class="dash">&mdash;</span>
                <?php endif; ?>
            </td>
            <td class="c">
                <span class="<?php echo $due_chip_cls; ?>"><?php echo $due_fmt; ?></span>
            </td>
            <td class="c">
                <span class="age-chip <?php echo $age_cls; ?>"><?php echo $aging; ?></span>
            </td>
            <td class="c">
                <span class="<?php echo $pa_cls; ?>"><?php echo $pa_label; ?></span>
            </td>
            <td class="c">
                <?php if ($sp): ?>
                    <span class="sp-mark sp-mark-special">&#9733; Special</span>
                <?php else: ?>
                    <span class="sp-mark sp-mark-normal">Normal</span>
                <?php endif; ?>
            </td>
            <td class="num"><?php echo a($net); ?></td>
            <td class="num"><?php echo $cash > 0 ? a($cash) : '<span class="dash">&mdash;</span>'; ?></td>
            <td class="num"><?php echo $cheq > 0 ? a($cheq) : '<span class="dash">&mdash;</span>'; ?></td>
            <td class="num"><?php echo $cn   > 0 ? a($cn)   : '<span class="dash">&mdash;</span>'; ?></td>
            <td class="num-bold"><?php echo a($bal); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>

    </table>
    <?php endif; ?>

    <!-- FOOTER NOTE -->
    <div class="foot-note">
        <span>
            All amounts in Sri Lankan Rupees (Rs.)
            &nbsp;|&nbsp; Aging buckets: 1–7 &middot; 8–14 &middot; 15–21 &middot; 22–28 &middot; 29–35 &middot; 35+
            &nbsp;|&nbsp; &#9733; Special = credit request on file
            &nbsp;|&nbsp; T Code: last 5 digits shown
            &nbsp;|&nbsp; * Payments counted up to <?php echo $as_at_display; ?> only
            &nbsp;|&nbsp; <b>Policy Aging:</b> negative = days remaining within policy &middot; positive (amber) = 1–7 overdue &middot; positive (purple) = 8+ overdue
            &nbsp;|&nbsp; <b>Due Date</b> = Delivery Date + Customer Credit Days
            &nbsp;|&nbsp; <b>Cr. Days</b> blank = no credit policy set
        </span>
        <span style="white-space:nowrap;">Generated: <?php echo date('d M Y H:i'); ?></span>
    </div>

</div><!-- /page-wrap -->
</body>
</html>