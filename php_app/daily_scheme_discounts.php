<?php
/**
 * daily_scheme_discounts.php
 * Daily Scheme Discounts Report
 * Columns:
 *   Bill Date | Scheme Discount | TOT Disc | RS Discount (display only) | Total Discount
 *   | Bill Wise Scheme Analysis | VAT 18% | Total (BWS + VAT) | Diff
 *
 * Bill Wise Scheme Analysis  = SUM(bws_items.sch_disc) grouped by bill_date
 * VAT 18%                    = Bill Wise Scheme Analysis × 18 / 100
 * Total                      = Bill Wise Scheme Analysis + VAT 18%
 * Diff                       = Total Discount (secondary) − Total (BWS+VAT)
 * RS Discount                = display only, not included in any calculation
 */
include 'config.php';
include 'header.php';

/* ── Filters ─────────────────────────────────────────────────────────────── */
$date_from       = isset($_GET['date_from'])  ? trim($_GET['date_from'])  : '';
$date_to         = isset($_GET['date_to'])    ? trim($_GET['date_to'])    : '';
$route_filter    = isset($_GET['route_name']) ? trim($_GET['route_name']) : '';
$salesman_filter = isset($_GET['salesman'])   ? trim($_GET['salesman'])   : '';

// Default: current month
if (empty($date_from) && empty($date_to)) {
    $date_from = date('Y-m-01');
    $date_to   = date('Y-m-d');
}

/* ── WHERE for secondary_invoice_import_details ─────────────────────────── */
$sec_parts = ["sid.status = 'imported'"];
if (!empty($date_from))       $sec_parts[] = "sid.bill_date >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
if (!empty($date_to))         $sec_parts[] = "sid.bill_date <= '" . mysqli_real_escape_string($conn, $date_to)   . "'";
if (!empty($route_filter))    $sec_parts[] = "sid.route_name = '"  . mysqli_real_escape_string($conn, $route_filter)    . "'";
if (!empty($salesman_filter)) $sec_parts[] = "sid.sales_person_code = '" . mysqli_real_escape_string($conn, $salesman_filter) . "'";
$sec_where = implode(' AND ', $sec_parts);

/* ── WHERE for bws_items (Bill Wise Scheme Analysis) ────────────────────── */
$bws_parts = [];
if (!empty($date_from)) $bws_parts[] = "bi.bill_date >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
if (!empty($date_to))   $bws_parts[] = "bi.bill_date <= '" . mysqli_real_escape_string($conn, $date_to)   . "'";
$bws_where = !empty($bws_parts) ? 'WHERE ' . implode(' AND ', $bws_parts) : '';

/* ── Main query ──────────────────────────────────────────────────────────── */
$sql = "
    SELECT
        sid.bill_date,
        SUM(sid.scheme_disc)                   AS scheme_disc_total,
        SUM(sid.tot_disc)                       AS tot_disc_total,
        SUM(sid.rs_discount)                    AS rs_discount_total,
        SUM(sid.scheme_disc + sid.tot_disc)     AS total_discount,
        COALESCE(bws.bws_sch_disc, 0)           AS bws_sch_disc
    FROM secondary_invoice_import_details sid
    LEFT JOIN (
        SELECT
            bi.bill_date,
            SUM(bi.sch_disc) AS bws_sch_disc
        FROM bws_items bi
        $bws_where
        GROUP BY bi.bill_date
    ) bws ON sid.bill_date = bws.bill_date
    WHERE $sec_where
    GROUP BY sid.bill_date
    ORDER BY sid.bill_date ASC
";

$result = mysqli_query($conn, $sql);

/* ── Dropdown options ────────────────────────────────────────────────────── */
$routes_res  = mysqli_query($conn, "SELECT DISTINCT route_name FROM secondary_invoice_import_details WHERE route_name IS NOT NULL AND route_name != '' ORDER BY route_name");
$salesman_res = mysqli_query($conn, "SELECT DISTINCT sales_person_code FROM secondary_invoice_import_details WHERE sales_person_code IS NOT NULL AND sales_person_code != '' ORDER BY sales_person_code");

/* ── Build rows & grand totals ───────────────────────────────────────────── */
$rows = [];
$grand_scheme_disc  = 0;
$grand_tot_disc     = 0;
$grand_rs_discount  = 0;   // display-only grand total
$grand_total_disc   = 0;
$grand_bws          = 0;
$grand_vat          = 0;
$grand_bws_total    = 0;
$grand_diff         = 0;

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $bws_sch   = (float)$row['bws_sch_disc'];
        $vat       = round($bws_sch * 18 / 100, 2);
        $bws_total = round($bws_sch + $vat, 2);
        $diff      = round((float)$row['total_discount'] - $bws_total, 2);

        $row['vat']       = $vat;
        $row['bws_total'] = $bws_total;
        $row['diff']      = $diff;

        $grand_scheme_disc += (float)$row['scheme_disc_total'];
        $grand_tot_disc    += (float)$row['tot_disc_total'];
        $grand_rs_discount += (float)$row['rs_discount_total'];  // display-only, no calc impact
        $grand_total_disc  += (float)$row['total_discount'];
        $grand_bws         += $bws_sch;
        $grand_vat         += $vat;
        $grand_bws_total   += $bws_total;
        $grand_diff        += $diff;

        $rows[] = $row;
    }
}
$grand_diff = round($grand_diff, 2);
?>

<style>
/* ── Page layout ── */
.rpt-header  { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:24px; flex-wrap:wrap; gap:16px; }
.rpt-title   { font-size:22px; font-weight:700; color:#1f2937; display:flex; align-items:center; gap:10px; }
.rpt-subtitle{ font-size:13px; color:#6b7280; margin-top:4px; }

/* ── Filter card ── */
.filter-card { background:#fff; border:1px solid #e5e5e5; border-radius:10px; padding:20px; margin-bottom:20px; }
.filter-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:14px; align-items:end; }
.filter-group label { display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:5px; }
.filter-group input,
.filter-group select {
    width:100%; padding:9px 12px; border:1px solid #d1d5db; border-radius:6px;
    font-size:13px; color:#1f2937; background:#fff; box-sizing:border-box;
    font-family:inherit; outline:none; transition:border .2s;
}
.filter-group input:focus, .filter-group select:focus { border-color:#7c3aed; }
.btn { display:inline-flex; align-items:center; gap:6px; padding:9px 18px; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; transition:all .2s; text-decoration:none; font-family:inherit; }
.btn-primary   { background:#7c3aed; color:#fff; }
.btn-primary:hover { background:#6d28d9; }
.btn-secondary { background:#f3f4f6; color:#374151; border:1px solid #d1d5db; }
.btn-secondary:hover { background:#e5e7eb; }
.btn-success   { background:#16a34a; color:#fff; }
.btn-success:hover { background:#15803d; }

/* ── Summary boxes ── */
.summary-strip { display:flex; gap:14px; margin-bottom:20px; flex-wrap:wrap; }
.sum-box { flex:1; min-width:150px; background:#fff; border:1px solid #e5e5e5; border-radius:10px; padding:14px 18px; }
.sum-box .lbl { font-size:11px; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px; }
.sum-box .val { font-size:20px; font-weight:700; color:#1f2937; }
.sum-box.purple .val { color:#7c3aed; }
.sum-box.green  .val { color:#16a34a; }
.sum-box.red    .val { color:#dc2626; }
.sum-box.amber  .val { color:#d97706; }
.sum-box.blue   .val { color:#2563eb; }
.sum-box.slate  .val { color:#475569; }

/* ── Table ── */
.content-card  { background:#fff; border:1px solid #e5e5e5; border-radius:10px; padding:20px; margin-bottom:20px; }
.card-title    { font-size:15px; font-weight:700; color:#1f2937; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.table-responsive { overflow-x:auto; }
.data-table    { width:100%; border-collapse:collapse; font-size:13px; min-width:1060px; }
.data-table thead tr            { background:#7c3aed; }
.data-table thead tr.sub-head   { background:#6d28d9; }
.data-table thead tr.bws-head   { background:#1d4ed8; }
.data-table th  { padding:11px 14px; text-align:center; color:#fff; font-size:11px; font-weight:700; white-space:nowrap; border-right:1px solid rgba(255,255,255,.15); }
.data-table th:last-child { border-right:none; }
.data-table th.left { text-align:left; }
.data-table tbody tr { border-bottom:1px solid #f0f0f0; transition:background .15s; }
.data-table tbody tr:hover { background:#faf5ff; }
.data-table td  { padding:11px 14px; color:#1f2937; white-space:nowrap; border-right:1px solid #f3f4f6; }
.data-table td:last-child { border-right:none; }
.data-table td.num { text-align:right; font-variant-numeric:tabular-nums; }
.data-table tfoot tr { background:#f9fafb; border-top:2px solid #7c3aed; }
.data-table tfoot td { padding:12px 14px; font-weight:700; color:#1f2937; }

/* RS Discount column — display-only indicator */
.col-rs-disc { background:#f8fafc; color:#475569 !important; font-style:italic; }
th.col-rs-disc-hd { background:#5b21b6 !important; }   /* slightly different shade to mark it info-only */

/* Variance / diff colouring */
.diff-positive { color:#16a34a; font-weight:700; }
.diff-negative { color:#dc2626; font-weight:700; }
.diff-zero     { color:#6b7280; font-weight:600; }

/* Empty state */
.empty-state { text-align:center; padding:60px 20px; color:#9ca3af; }
.empty-state i { font-size:40px; margin-bottom:12px; display:block; }

/* Print */
@media print {
    .filter-card, .btn, .rpt-actions { display:none !important; }
    .data-table thead tr       { background:#7c3aed !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .data-table thead tr.bws-head { background:#1d4ed8 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    th.col-rs-disc-hd          { background:#5b21b6 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
}
</style>

<!-- Page header -->
<div class="rpt-header">
    <div>
        <div class="rpt-title">
            <i class="fa-solid fa-chart-bar" style="color:#7c3aed;"></i>
            Daily Scheme Discounts
        </div>
        <div class="rpt-subtitle">
            Secondary invoice discounts vs. Bill Wise Scheme Analysis, grouped by Bill Date
        </div>
    </div>
    <div class="rpt-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
        <button class="btn btn-secondary" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Print
        </button>
        <button class="btn btn-success" onclick="exportCSV()">
            <i class="fa-solid fa-file-csv"></i> Export CSV
        </button>
    </div>
</div>

<!-- Filters -->
<div class="filter-card">
    <form method="GET" action="">
        <div class="filter-grid">
            <div class="filter-group">
                <label><i class="fa-solid fa-calendar-days"></i> Bill Date From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="filter-group">
                <label><i class="fa-solid fa-calendar-days"></i> Bill Date To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="filter-group">
                <label><i class="fa-solid fa-road"></i> Route</label>
                <select name="route_name">
                    <option value="">— All Routes —</option>
                    <?php if ($routes_res): while ($r = mysqli_fetch_assoc($routes_res)): ?>
                    <option value="<?php echo htmlspecialchars($r['route_name']); ?>"
                        <?php echo ($route_filter === $r['route_name']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($r['route_name']); ?>
                    </option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fa-solid fa-user-tie"></i> Salesman Code</label>
                <select name="salesman">
                    <option value="">— All Salesmen —</option>
                    <?php if ($salesman_res): while ($s = mysqli_fetch_assoc($salesman_res)): ?>
                    <option value="<?php echo htmlspecialchars($s['sales_person_code']); ?>"
                        <?php echo ($salesman_filter === $s['sales_person_code']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($s['sales_person_code']); ?>
                    </option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div class="filter-group" style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary" style="flex:1;">
                    <i class="fa-solid fa-magnifying-glass"></i> Apply
                </button>
                <a href="daily_scheme_discounts.php" class="btn btn-secondary">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            </div>
        </div>
    </form>
</div>

<?php if (!empty($rows)): ?>

<!-- Summary strip -->
<div class="summary-strip">
    <div class="sum-box">
        <div class="lbl">Bill Days</div>
        <div class="val"><?php echo count($rows); ?></div>
    </div>
    <div class="sum-box purple">
        <div class="lbl">Total Discount (Sec.)</div>
        <div class="val"><?php echo number_format($grand_total_disc, 2); ?></div>
    </div>
    <div class="sum-box slate">
        <div class="lbl">RS Discount (Info)</div>
        <div class="val"><?php echo number_format($grand_rs_discount, 2); ?></div>
    </div>
    <div class="sum-box blue">
        <div class="lbl">Bill Wise Scheme Analysis</div>
        <div class="val"><?php echo number_format($grand_bws, 2); ?></div>
    </div>
    <div class="sum-box amber">
        <div class="lbl">VAT 18%</div>
        <div class="val"><?php echo number_format($grand_vat, 2); ?></div>
    </div>
    <div class="sum-box green">
        <div class="lbl">BWS + VAT Total</div>
        <div class="val"><?php echo number_format($grand_bws_total, 2); ?></div>
    </div>
    <div class="sum-box <?php echo $grand_diff >= 0 ? 'green' : 'red'; ?>">
        <div class="lbl">Grand Diff</div>
        <div class="val"><?php echo ($grand_diff >= 0 ? '+' : '') . number_format($grand_diff, 2); ?></div>
    </div>
</div>

<?php endif; ?>

<!-- Report table -->
<div class="content-card">
    <div class="card-title">
        <i class="fa-solid fa-table" style="color:#7c3aed;"></i>
        Report — <?php echo date('d M Y', strtotime($date_from)); ?> to <?php echo date('d M Y', strtotime($date_to)); ?>
        <?php if (!empty($rows)): ?>
        <span style="margin-left:auto;font-size:12px;font-weight:400;color:#6b7280;"><?php echo count($rows); ?> day(s)</span>
        <?php endif; ?>
    </div>

    <div class="table-responsive">
        <table class="data-table" id="reportTable">
            <thead>
                <!-- Row 1: group spans -->
                <tr>
                    <th class="left" rowspan="2" style="vertical-align:middle;">#</th>
                    <th class="left" rowspan="2" style="vertical-align:middle;">Bill Date</th>

                    <!-- Secondary Invoice group (purple) — now 4 sub-cols -->
                    <th colspan="4" style="border-bottom:1px solid rgba(255,255,255,.3);">Secondary Invoice</th>

                    <!-- Bill Wise Scheme Analysis group (blue) -->
                    <th colspan="3" style="background:#1d4ed8;border-bottom:1px solid rgba(255,255,255,.3);">Bill Wise Scheme Analysis</th>

                    <!-- Diff (standalone) -->
                    <th rowspan="2" style="vertical-align:middle;background:#4c1d95;">Diff</th>
                </tr>
                <!-- Row 2: sub-headers -->
                <tr class="sub-head">
                    <!-- Secondary sub-cols -->
                    <th>Scheme Disc</th>
                    <th>TOT Disc</th>
                    <!-- RS Discount: display-only, visually distinguished -->
                    <th class="col-rs-disc-hd" title="Display only — not used in any calculation">
                        RS Discount <span style="font-size:10px;opacity:.8;">(info)</span>
                    </th>
                    <th>Total Discount</th>
                    <!-- BWS sub-cols (blue shade) -->
                    <th style="background:#1e40af;">Sch Disc (BWS)</th>
                    <th style="background:#1e40af;">VAT 18%</th>
                    <th style="background:#1e40af;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="10">
                        <div class="empty-state">
                            <i class="fa-solid fa-inbox"></i>
                            No records found for the selected filters.
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($rows as $i => $row): ?>
                <?php
                    $diff_class = 'diff-zero';
                    if ($row['diff'] > 0.005)  $diff_class = 'diff-positive';
                    if ($row['diff'] < -0.005) $diff_class = 'diff-negative';
                    $diff_sign  = $row['diff'] > 0 ? '+' : '';
                ?>
                <tr>
                    <td style="color:#9ca3af;font-size:12px;"><?php echo $i + 1; ?></td>
                    <td style="font-weight:600;color:#1f2937;">
                        <?php echo date('d M Y', strtotime($row['bill_date'])); ?>
                        <div style="font-size:11px;color:#9ca3af;font-weight:400;"><?php echo date('l', strtotime($row['bill_date'])); ?></div>
                    </td>

                    <!-- Secondary Invoice columns -->
                    <td class="num"><?php echo number_format($row['scheme_disc_total'], 2); ?></td>
                    <td class="num"><?php echo number_format($row['tot_disc_total'], 2); ?></td>

                    <!-- RS Discount — display only, no calculation involvement -->
                    <td class="num col-rs-disc"><?php echo number_format($row['rs_discount_total'], 2); ?></td>

                    <td class="num" style="font-weight:600;"><?php echo number_format($row['total_discount'], 2); ?></td>

                    <!-- Bill Wise Scheme Analysis columns -->
                    <td class="num" style="color:#1d4ed8;"><?php echo number_format($row['bws_sch_disc'], 2); ?></td>
                    <td class="num" style="color:#d97706;"><?php echo number_format($row['vat'], 2); ?></td>
                    <td class="num" style="font-weight:600;color:#1d4ed8;"><?php echo number_format($row['bws_total'], 2); ?></td>

                    <!-- Diff -->
                    <td class="num <?php echo $diff_class; ?>">
                        <?php echo $diff_sign . number_format($row['diff'], 2); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot>
                <tr>
                    <td colspan="2" style="color:#7c3aed;">TOTAL</td>
                    <td class="num"><?php echo number_format($grand_scheme_disc, 2); ?></td>
                    <td class="num"><?php echo number_format($grand_tot_disc, 2); ?></td>
                    <!-- RS Discount grand total — display only -->
                    <td class="num col-rs-disc"><?php echo number_format($grand_rs_discount, 2); ?></td>
                    <td class="num"><?php echo number_format($grand_total_disc, 2); ?></td>
                    <td class="num" style="color:#1d4ed8;"><?php echo number_format($grand_bws, 2); ?></td>
                    <td class="num" style="color:#d97706;"><?php echo number_format($grand_vat, 2); ?></td>
                    <td class="num" style="color:#1d4ed8;"><?php echo number_format($grand_bws_total, 2); ?></td>
                    <td class="num <?php echo $grand_diff >= 0 ? 'diff-positive' : 'diff-negative'; ?>">
                        <?php echo ($grand_diff >= 0 ? '+' : '') . number_format($grand_diff, 2); ?>
                    </td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<script>
function exportCSV() {
    const rows = [];
    rows.push([
        '#', 'Bill Date',
        'Scheme Disc (Sec.)', 'TOT Disc (Sec.)', 'RS Discount (Info)', 'Total Discount (Sec.)',
        'Sch Disc (BWS)', 'VAT 18%', 'Total (BWS+VAT)',
        'Diff'
    ].join(','));

    const tbody = document.querySelector('#reportTable tbody');
    tbody.querySelectorAll('tr').forEach(tr => {
        const cells = tr.querySelectorAll('td');
        if (cells.length < 10) return;
        const rowData = [];
        cells.forEach((td, i) => {
            let txt = td.innerText.trim().replace(/\n.*/,'');
            if (txt.includes(',') || txt.includes('"')) txt = '"' + txt.replace(/"/g,'""') + '"';
            rowData.push(txt);
        });
        rows.push(rowData.join(','));
    });

    <?php if (!empty($rows)): ?>
    rows.push([
        'TOTAL', '',
        '<?php echo number_format($grand_scheme_disc, 2); ?>',
        '<?php echo number_format($grand_tot_disc, 2); ?>',
        '<?php echo number_format($grand_rs_discount, 2); ?>',
        '<?php echo number_format($grand_total_disc, 2); ?>',
        '<?php echo number_format($grand_bws, 2); ?>',
        '<?php echo number_format($grand_vat, 2); ?>',
        '<?php echo number_format($grand_bws_total, 2); ?>',
        '<?php echo number_format($grand_diff, 2); ?>'
    ].join(','));
    <?php endif; ?>

    const csv  = rows.join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'daily_scheme_discounts_<?php echo $date_from; ?>_to_<?php echo $date_to; ?>.csv';
    a.click();
    URL.revokeObjectURL(url);
}
</script>

<?php include 'footer.php'; ?>