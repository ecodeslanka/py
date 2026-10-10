<?php
/**
 * print_market_credit.php
 * Standalone B&W print page for Market Credit Report
 * Accepts same GET params as market_credit_report.php
 * Opens, renders, and auto-triggers print dialog
 */

include 'config.php';

/* ── Filter inputs (same as main report) ── */
$f_route      = trim($_GET['route']          ?? '');
$f_sr         = trim($_GET['sr_code']        ?? '');
$f_tcode      = trim($_GET['t_code']         ?? '');
$f_del_from   = trim($_GET['del_from']       ?? '');
$f_del_to     = trim($_GET['del_to']         ?? '');
$f_as_at      = trim($_GET['as_at_date']     ?? '');
$f_status_bal = trim($_GET['balance_status'] ?? '');
$f_min_bal    = trim($_GET['min_balance']    ?? '');
$f_max_bal    = trim($_GET['max_balance']    ?? '');

/* ── Aging base date ── */
$aging_base_sql = $f_as_at
    ? "'" . mysqli_real_escape_string($conn, $f_as_at) . "'"
    : "CURDATE()";

/* ── WHERE clause ── */
$where = ["fsd.updated = 1"];
if ($f_route)    $where[] = "fs.route = '"          . mysqli_real_escape_string($conn,$f_route)    . "'";
if ($f_sr)       $where[] = "fs.sr_code = '"        . mysqli_real_escape_string($conn,$f_sr)       . "'";
if ($f_tcode)    $where[] = "fsd.t_code LIKE '%"    . mysqli_real_escape_string($conn,$f_tcode)    . "%'";
if ($f_del_from) $where[] = "fs.delivery_date >= '" . mysqli_real_escape_string($conn,$f_del_from) . "'";
if ($f_del_to)   $where[] = "fs.delivery_date <= '" . mysqli_real_escape_string($conn,$f_del_to)   . "'";
if ($f_as_at)    $where[] = "fs.delivery_date <= '" . mysqli_real_escape_string($conn,$f_as_at)    . "'";
$where_sql = implode(' AND ', $where);

/* ── Credit policy column detection ── */
$credit_policy_col = 'NULL';
$cp = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'credit_days'");
if ($cp && mysqli_num_rows($cp) > 0) $credit_policy_col = 'c.credit_days';
else {
    $cp2 = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'credit_period'");
    if ($cp2 && mysqli_num_rows($cp2) > 0) $credit_policy_col = 'c.credit_period';
    else {
        $cp3 = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'credit_limit_days'");
        if ($cp3 && mysqli_num_rows($cp3) > 0) $credit_policy_col = 'c.credit_limit_days';
    }
}

/* ── Main query ── */
$sql = "
SELECT
    fsd.t_code,
    fs.sr_code,
    fs.route                                                                        AS route_code,
    COALESCE(r.route_name, fs.route)                                                AS route_name,
    fs.delivery_date,
    DATEDIFF($aging_base_sql, fs.delivery_date)                                    AS aging_days,
    COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code)                AS customer_name,
    fsd.invoice_num,
    COALESCE(siid.final_bill_amount, fsd.adjust_net_value)                          AS ikea_value,
    COALESCE(pay.total_paid, 0)                                                     AS total_paid,
    COALESCE(cn.total_cn, 0)                                                        AS total_cn,
    (COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
        - COALESCE(pay.total_paid, 0)
        - COALESCE(cn.total_cn, 0))                                                AS overdue_balance,
    COALESCE(chq.in_hand_total, 0)                                                  AS chqs_in_hand,
    COALESCE(chq.returned_total, 0)                                                 AS crn_total,
    (COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
        - COALESCE(pay.total_paid, 0)
        - COALESCE(cn.total_cn, 0)
        + COALESCE(chq.in_hand_total, 0))                                         AS market_credit,
    COALESCE($credit_policy_col, 0)                                                AS credit_policy_days,
    (DATEDIFF($aging_base_sql, fs.delivery_date) - COALESCE($credit_policy_col,0)) AS overdue_days,
    CASE WHEN cr.detail_id IS NOT NULL THEN 1 ELSE 0 END                           AS is_special
FROM field_summary_details fsd
INNER JOIN field_summary fs      ON fs.id = fsd.field_summary_id
LEFT  JOIN routes r              ON r.route_code = fs.route
LEFT  JOIN customers c           ON c.t_code = fsd.t_code
LEFT  JOIN (
    SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
    FROM secondary_invoice_import_details GROUP BY bill_no
) siid ON siid.bill_no = fsd.invoice_num
LEFT  JOIN (
    SELECT ip.field_summary_detail_id, SUM(ip.amount) AS total_paid
    FROM invoice_payments ip
    WHERE ip.is_reversed = 0
    " . ($f_as_at ? "AND ip.payment_date <= '" . mysqli_real_escape_string($conn,$f_as_at) . "'" : "") . "
      AND NOT EXISTS (
          SELECT 1 FROM invoice_payment_cheques ipc2
          INNER JOIN cheques ch2
              ON ch2.cheque_no=ipc2.cheque_no AND ch2.bank_code=ipc2.bank_code AND ch2.branch_code=ipc2.branch_code
          WHERE ipc2.invoice_payment_id=ip.id AND LOWER(TRIM(ch2.status)) IN ('returned','bounced')
      )
    GROUP BY ip.field_summary_detail_id
) pay ON pay.field_summary_detail_id = fsd.id
LEFT  JOIN (
    SELECT field_summary_detail_id, SUM(amount) AS total_cn
    FROM credit_notes WHERE is_deleted=0 GROUP BY field_summary_detail_id
) cn ON cn.field_summary_detail_id = fsd.id
LEFT  JOIN (
    SELECT ip2.field_summary_detail_id,
        SUM(CASE WHEN LOWER(TRIM(ch.status)) IN ('pending','to_be_bank','deposited','sent_back') THEN ch.total_amount ELSE 0 END) AS in_hand_total,
        SUM(CASE WHEN LOWER(TRIM(ch.status)) IN ('returned','bounced') THEN ch.total_amount ELSE 0 END) AS returned_total
    FROM cheques ch
    INNER JOIN invoice_payments ip2 ON ip2.id=ch.invoice_payment_id
    WHERE ip2.is_reversed=0
    GROUP BY ip2.field_summary_detail_id
) chq ON chq.field_summary_detail_id = fsd.id
LEFT  JOIN (
    SELECT field_summary_detail_id AS detail_id FROM credit_requests GROUP BY field_summary_detail_id
) cr ON cr.detail_id = fsd.id
WHERE $where_sql
  AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) > 0
ORDER BY fsd.t_code, fs.sr_code, fs.delivery_date ASC, fsd.invoice_num
";

$result = mysqli_query($conn, $sql);
$rows = [];
if ($result) while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;

/* ── Post-query filters ── */
if ($f_min_bal !== '') $rows = array_filter($rows, fn($r) => floatval($r['overdue_balance']) >= floatval($f_min_bal));
if ($f_max_bal !== '') $rows = array_filter($rows, fn($r) => floatval($r['overdue_balance']) <= floatval($f_max_bal));
if ($f_status_bal === 'overdue') $rows = array_filter($rows, fn($r) => intval($r['overdue_days']) > 0);
if ($f_status_bal === 'current') $rows = array_filter($rows, fn($r) => intval($r['overdue_days']) <= 0);
$rows = array_values($rows);

/* ── Group by T-Code ── */
$grouped = [];
foreach ($rows as $row) {
    $key = $row['t_code'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            't_code'        => $row['t_code'],
            'sr_code'       => $row['sr_code'],
            'route_code'    => $row['route_code'],
            'route_name'    => $row['route_name'],
            'customer_name' => $row['customer_name'],
            'invoices'      => [],
        ];
    }
    $grouped[$key]['invoices'][] = $row;
}

/* ── Grand totals ── */
$g_ikea = $g_bal = $g_in_hand = $g_crn = $g_mc = 0;
foreach ($rows as $r) {
    $g_ikea   += floatval($r['ikea_value']);
    $g_bal    += floatval($r['overdue_balance']);
    $g_in_hand += floatval($r['chqs_in_hand']);
    $g_crn    += floatval($r['crn_total']);
    $g_mc     += floatval($r['market_credit']);
}
$total_invoices  = count($rows);
$total_customers = count($grouped);

/* ── Helpers ── */
function fmt($v) { return number_format(floatval($v), 2); }
function fmtDate($d) { return $d ? date('d M Y', strtotime($d)) : '—'; }

/* ── Filter label for header ── */
$meta = [];
if ($f_route)    $meta[] = 'Route: ' . $f_route;
if ($f_sr)       $meta[] = 'SR: ' . $f_sr;
if ($f_tcode)    $meta[] = 'T-Code: ' . $f_tcode;
if ($f_del_from) $meta[] = 'From: ' . date('d M Y', strtotime($f_del_from));
if ($f_del_to)   $meta[] = 'To: ' . date('d M Y', strtotime($f_del_to));
if ($f_as_at)    $meta[] = 'As At: ' . date('d M Y', strtotime($f_as_at));
if (!$meta)      $meta[] = 'All Records';
$meta_str = implode('  |  ', $meta);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Market Credit Report — Print</title>
<style>
/* ══ PAGE SETUP ══ */
@page {
    size: A3 landscape;
    margin: 10mm 8mm 12mm 8mm;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: Arial, sans-serif;
    font-size: 8pt;
    color: #000;
    background: #fff;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* ══ TOP TOOLBAR (screen only, hidden on print) ══ */
.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 16px;
    background: #1e1b4b;
    color: #fff;
    font-size: 13px;
    gap: 12px;
}
.toolbar-title { font-weight: 800; font-size: 15px; }
.toolbar-meta  { font-size: 11px; opacity: .75; margin-top: 2px; }
.btn-group { display: flex; gap: 8px; }
.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border: none; border-radius: 7px;
    font-size: 12px; font-weight: 700; cursor: pointer;
    font-family: Arial, sans-serif; text-decoration: none;
}
.btn-print  { background: #4f46e5; color: #fff; }
.btn-close  { background: #374151; color: #fff; }
.btn-print:hover { background: #4338ca; }
.btn-close:hover { background: #1f2937; }

@media print {
    .toolbar { display: none !important; }
}

/* ══ REPORT WRAPPER ══ */
.report-wrap { padding: 6mm 0 0 0; }

/* ══ REPORT HEADER ══ */
.rpt-header {
    border-bottom: 2pt solid #000;
    padding-bottom: 5pt;
    margin-bottom: 6pt;
}
.rpt-header-title {
    font-size: 15pt;
    font-weight: 900;
    letter-spacing: .02em;
}
.rpt-header-meta {
    font-size: 7.5pt;
    color: #444;
    margin-top: 3pt;
}
.rpt-header-totals {
    display: flex;
    gap: 0;
    margin-top: 5pt;
    border-top: 1pt solid #aaa;
    padding-top: 4pt;
}
.pt-item {
    flex: 1;
    border-right: 1pt solid #ccc;
    padding: 0 8pt 0 0;
    margin-right: 8pt;
}
.pt-item:last-child { border-right: none; margin-right: 0; }
.pt-label {
    font-size: 6pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #555;
    display: block;
}
.pt-val {
    font-size: 9pt;
    font-weight: 900;
    color: #000;
    display: block;
    margin-top: 1pt;
}

/* ══ TABLE ══ */
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 7.5pt;
    table-layout: fixed;
}

/* Column widths */
col.c-no      { width: 20pt; }
col.c-tcode   { width: 55pt; }
col.c-sr      { width: 30pt; }
col.c-cust    { width: 110pt; }
col.c-inv     { width: 72pt; }
col.c-date    { width: 52pt; }
col.c-ikea    { width: 58pt; }
col.c-bal     { width: 62pt; }
col.c-inhand  { width: 56pt; }
col.c-crn     { width: 50pt; }
col.c-mc      { width: 62pt; }
col.c-outdays { width: 44pt; }
col.c-policy  { width: 38pt; }
col.c-ovdays  { width: 44pt; }

/* Header */
thead th {
    background: #000;
    color: #fff;
    padding: 4pt 3pt;
    font-size: 6.5pt;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .04em;
    border: 1pt solid #000;
    text-align: left;
    line-height: 1.3;
}
thead th.tr { text-align: right; }
thead th.tc { text-align: center; }

/* Invoice rows */
tr.inv-row td {
    padding: 3pt 3pt;
    border-bottom: 0.4pt solid #ccc;
    vertical-align: top;
    word-break: break-word;
    line-height: 1.3;
}
tr.inv-row:nth-child(even) td { background: #f5f5f5; }

/* Customer subtotal — white bg, black border top/bottom, bold */
tr.cust-sub td {
    background: #fff;
    color: #000;
    font-weight: 900;
    font-size: 7.5pt;
    padding: 3pt 3pt;
    border-top: 1.5pt solid #000;
    border-bottom: 1.5pt solid #000;
}
tr.cust-sub td.sub-label {
    font-style: italic;
    text-align: right;
    padding-right: 6pt;
}
tbody tr.cust-sub { page-break-after: avoid; break-after: avoid; }

/* Grand total */
tfoot td {
    background: #222;
    color: #fff;
    font-weight: 900;
    font-size: 8.5pt;
    padding: 5pt 3pt;
    border-top: 2pt solid #000;
}

/* Alignment */
.tr { text-align: right; }
.tc { text-align: center; }
.mono { font-family: 'Courier New', monospace; font-size: 7pt; }

/* Footer note */
.print-footer {
    margin-top: 8pt;
    border-top: 1pt solid #ccc;
    padding-top: 4pt;
    font-size: 6.5pt;
    color: #666;
    display: flex;
    justify-content: space-between;
}
</style>
</head>
<body>

<!-- SCREEN TOOLBAR -->
<div class="toolbar">
    <div>
        <div class="toolbar-title">&#128438; Market Credit Report — Print Preview</div>
        <div class="toolbar-meta"><?= htmlspecialchars($meta_str) ?> &nbsp;|&nbsp; <?= $total_customers ?> customers &nbsp;|&nbsp; <?= $total_invoices ?> invoices</div>
    </div>
    <div class="btn-group">
        <button class="btn btn-print" onclick="window.print()">&#128438; Print / Save PDF</button>
        <button class="btn btn-close" onclick="window.close()">&#x2715; Close</button>
    </div>
</div>

<div class="report-wrap">

<?php if (empty($rows)): ?>
<p style="padding:20pt;font-size:11pt;color:#666;">No outstanding invoices found for the selected filters.</p>
<?php else: ?>

<!-- REPORT HEADER -->
<div class="rpt-header">
    <div class="rpt-header-title">Market Credit Report</div>
    <div class="rpt-header-meta">
        <?= htmlspecialchars($meta_str) ?>
        &nbsp;&nbsp;|&nbsp;&nbsp;Printed: <?= date('d M Y  H:i') ?>
    </div>
    <div class="rpt-header-totals">
        <div class="pt-item"><span class="pt-label">Customers</span><span class="pt-val"><?= $total_customers ?></span></div>
        <div class="pt-item"><span class="pt-label">Invoices</span><span class="pt-val"><?= $total_invoices ?></span></div>
        <div class="pt-item"><span class="pt-label">Ikea Value</span><span class="pt-val">Rs. <?= fmt($g_ikea) ?></span></div>
        <div class="pt-item"><span class="pt-label">Overdue Balance</span><span class="pt-val">Rs. <?= fmt($g_bal) ?></span></div>
        <div class="pt-item"><span class="pt-label">Cheques In Hand</span><span class="pt-val">Rs. <?= fmt($g_in_hand) ?></span></div>
        <div class="pt-item"><span class="pt-label">CRN (Returned)</span><span class="pt-val">Rs. <?= fmt($g_crn) ?></span></div>
        <div class="pt-item"><span class="pt-label">Market Credit</span><span class="pt-val">Rs. <?= fmt($g_mc) ?></span></div>
    </div>
</div>

<!-- TABLE -->
<table>
    <colgroup>
        <col class="c-no">
        <col class="c-tcode">
        <col class="c-sr">
        <col class="c-cust">
        <col class="c-inv">
        <col class="c-date">
        <col class="c-ikea">
        <col class="c-bal">
        <col class="c-inhand">
        <col class="c-crn">
        <col class="c-mc">
        <col class="c-outdays">
        <col class="c-policy">
        <col class="c-ovdays">
    </colgroup>
    <thead>
        <tr>
            <th class="tc">#</th>
            <th>T-Code</th>
            <th class="tc">SR</th>
            <th>Customer / Route</th>
            <th>Invoice No.</th>
            <th class="tc">Del. Date</th>
            <th class="tr">Ikea Value</th>
            <th class="tr">Overdue Bal.</th>
            <th class="tr">Chqs In Hand</th>
            <th class="tr">CRN</th>
            <th class="tr">Market Credit</th>
            <th class="tc">Outstnd. Days</th>
            <th class="tc">Policy</th>
            <th class="tc">Overdue Days</th>
        </tr>
    </thead>
    <tbody>
    <?php
    $rn = 0;
    foreach ($grouped as $tcode => $grp):
        $invs = $grp['invoices'];
        $st_ikea = $st_bal = $st_inhand = $st_crn = $st_mc = 0;
        foreach ($invs as $inv) {
            $st_ikea   += floatval($inv['ikea_value']);
            $st_bal    += floatval($inv['overdue_balance']);
            $st_inhand += floatval($inv['chqs_in_hand']);
            $st_crn    += floatval($inv['crn_total']);
            $st_mc     += floatval($inv['market_credit']);
        }
    ?>

    <!-- INVOICES -->
    <?php foreach ($invs as $inv):
        $rn++;
        $aging    = max(0, intval($inv['aging_days']));
        $policy   = intval($inv['credit_policy_days']);
        $ovd      = intval($inv['overdue_days']);
    ?>
    <tr class="inv-row">
        <td class="tc" style="color:#666;"><?= $rn ?></td>
        <td class="mono"><?= htmlspecialchars($inv['t_code']) ?></td>
        <td class="tc"><?= htmlspecialchars($inv['sr_code']) ?></td>
        <td>
            <?= htmlspecialchars($inv['customer_name']) ?>
            <?php if ($inv['is_special']): ?> <span style="font-size:6pt;border:0.5pt solid #000;padding:0 2pt;">[Special]</span><?php endif; ?>
            <br><span style="font-size:6.5pt;color:#555;"><?= htmlspecialchars($grp['route_code'].' — '.$grp['route_name']) ?></span>
        </td>
        <td class="mono"><?= htmlspecialchars($inv['invoice_num']) ?></td>
        <td class="tc"><?= fmtDate($inv['delivery_date']) ?></td>
        <td class="tr"><?= fmt($inv['ikea_value']) ?></td>
        <td class="tr" style="font-weight:700;"><?= fmt($inv['overdue_balance']) ?></td>
        <td class="tr"><?= floatval($inv['chqs_in_hand'])>0 ? fmt($inv['chqs_in_hand']) : '—' ?></td>
        <td class="tr"><?= floatval($inv['crn_total'])>0 ? fmt($inv['crn_total']) : '—' ?></td>
        <td class="tr" style="font-weight:900;"><?= fmt($inv['market_credit']) ?></td>
        <td class="tc"><?= $aging ?>d</td>
        <td class="tc"><?= $policy > 0 ? $policy.'d' : '—' ?></td>
        <td class="tc" style="font-weight:<?= $ovd>0?'900':'400' ?>;">
            <?php if ($ovd > 0): ?>
                +<?= $ovd ?>d
            <?php elseif ($policy > 0): ?>
                <span style="color:#555;">-<?= abs($ovd) ?>d</span>
            <?php else: ?>
                —
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>

    <!-- CUSTOMER SUBTOTAL -->
    <tr class="cust-sub">
        <td colspan="6" class="sub-label">
            <?= htmlspecialchars($grp['t_code']) ?> — <?= htmlspecialchars($grp['customer_name']) ?>
            <span style="font-weight:400;font-size:6.5pt;">&nbsp;(<?= count($invs) ?> invoice<?= count($invs)!==1?'s':'' ?>)</span>
        </td>
        <td class="tr"><?= fmt($st_ikea) ?></td>
        <td class="tr"><?= fmt($st_bal) ?></td>
        <td class="tr"><?= $st_inhand>0 ? fmt($st_inhand) : '—' ?></td>
        <td class="tr"><?= $st_crn>0 ? fmt($st_crn) : '—' ?></td>
        <td class="tr"><?= fmt($st_mc) ?></td>
        <td colspan="3"></td>
    </tr>

    <?php endforeach; ?>
    </tbody>

    <!-- GRAND TOTAL -->
    <tfoot>
        <tr>
            <td colspan="6" style="text-align:right;font-size:7.5pt;opacity:.8;padding-right:6pt;">
                GRAND TOTAL — <?= $total_invoices ?> invoices / <?= $total_customers ?> customers
                <?php if ($f_as_at): ?> — as at <?= date('d M Y',strtotime($f_as_at)) ?><?php endif; ?>
            </td>
            <td class="tr">Rs. <?= fmt($g_ikea) ?></td>
            <td class="tr">Rs. <?= fmt($g_bal) ?></td>
            <td class="tr">Rs. <?= fmt($g_in_hand) ?></td>
            <td class="tr">Rs. <?= fmt($g_crn) ?></td>
            <td class="tr">Rs. <?= fmt($g_mc) ?></td>
            <td colspan="3"></td>
        </tr>
    </tfoot>
</table>

<!-- PRINT FOOTER -->
<div class="print-footer">
    <span>Market Credit Report &mdash; <?= htmlspecialchars($meta_str) ?></span>
    <span>Generated: <?= date('d M Y H:i') ?></span>
</div>

<?php endif; ?>
</div><!-- /report-wrap -->

<script>
// Auto-trigger print dialog when page finishes loading
window.addEventListener('load', function () {
    window.print();
});
</script>
</body>
</html>