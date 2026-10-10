<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();

include 'config.php';
include 'header.php';

/* ─────────────────────────────────────────────
   CUSTOM MONTH RANGE HELPER
   May  = Apr 26 → May 25
   June = May 26 → Jun 25
   etc.
   ───────────────────────────────────────────── */
function getCustomMonthRange(string $ym): array {
    // $ym = "YYYY-MM"
    list($year, $month) = explode('-', $ym);
    $year  = (int)$year;
    $month = (int)$month;

    // Start: 26th of previous month
    $prev_year  = $month === 1 ? $year - 1 : $year;
    $prev_month = $month === 1 ? 12 : $month - 1;
    $date_from  = sprintf('%04d-%02d-26', $prev_year, $prev_month);

    // End: 25th of current month
    $date_to = sprintf('%04d-%02d-25', $year, $month);

    // Human label  e.g.  "Apr 26 – May 25, 2026"
    $label = date('M j', strtotime($date_from)) . ' – ' . date('M j, Y', strtotime($date_to));

    return [$date_from, $date_to, $label];
}

/* ── FILTERS ── */
$filter_month     = isset($_GET['month'])     ? $_GET['month']     : date('Y-m');
$filter_route     = isset($_GET['route'])     ? trim($_GET['route'])     : '';
$filter_rep       = isset($_GET['rep'])       ? trim($_GET['rep'])       : '';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to   = isset($_GET['date_to'])   ? $_GET['date_to']   : '';

/* ── DATE RANGE RESOLUTION ── */
$active_range_label = '';   // shown in notice banner
$using_custom_month = false;

$date_conditions = [];

if ($filter_date_from || $filter_date_to) {
    // Explicit date range overrides month
    if ($filter_date_from)
        $date_conditions[] = "ls.delivery_date >= '" . mysqli_real_escape_string($conn, $filter_date_from) . "'";
    if ($filter_date_to)
        $date_conditions[] = "ls.delivery_date <= '" . mysqli_real_escape_string($conn, $filter_date_to) . "'";
    $active_range_label = ($filter_date_from ?: '…') . ' to ' . ($filter_date_to ?: '…');
} elseif ($filter_month) {
    // Custom month range: 26th prev → 25th current
    [$cm_from, $cm_to, $cm_label] = getCustomMonthRange($filter_month);
    $date_conditions[] = "ls.delivery_date BETWEEN '" . mysqli_real_escape_string($conn, $cm_from) . "' AND '" . mysqli_real_escape_string($conn, $cm_to) . "'";
    $active_range_label  = $cm_label;
    $using_custom_month  = true;
    // Keep for filter display
    $filter_date_from_display = $cm_from;
    $filter_date_to_display   = $cm_to;
}

$where_date = $date_conditions ? 'AND ' . implode(' AND ', $date_conditions) : '';

$route_condition = $filter_route !== ''
    ? "AND ls.route_code = '" . mysqli_real_escape_string($conn, $filter_route) . "'"
    : '';

$rep_condition = $filter_rep !== ''
    ? "AND ls.sales_person_code = '" . mysqli_real_escape_string($conn, $filter_rep) . "'"
    : '';

/* ── LOOKUP DATA (run in parallel-ish order) ── */
$routes_result = mysqli_query($conn,
    "SELECT DISTINCT route_code, route_name FROM routes ORDER BY route_name");

$reps_rows = [];
$reps_q = mysqli_query($conn,
    "SELECT DISTINCT sales_person_code AS rep_code
     FROM loading_summary_import_details
     WHERE status='imported' AND sales_person_code IS NOT NULL AND sales_person_code!=''
     ORDER BY sales_person_code");
if ($reps_q) while ($rr = mysqli_fetch_assoc($reps_q)) $reps_rows[] = $rr;

/* ── MAIN QUERY ── */
$sql = "
SELECT
    ls.delivery_date,
    ls.route_code,
    COALESCE(r.route_name, ls.route_code)                AS route_name,
    ls.sales_person_code                                  AS rep_code,
    COUNT(DISTINCT ls.bill_no)                            AS pre_sec_count,
    SUM(ls.final_bill_amount)                             AS pre_sec_value,
    SUM(CASE WHEN si.bill_no IS NULL THEN 1 ELSE 0 END)  AS canceled_count,
    COALESCE(SUM(si.final_bill_amount), 0)                AS sec_value_total
FROM loading_summary_import_details ls
LEFT JOIN routes r ON ls.route_code = r.route_code
LEFT JOIN secondary_invoice_import_details si
    ON  si.bill_no       = ls.bill_no
    AND si.delivery_date = ls.delivery_date
    AND si.status        = 'imported'
    AND ls.status        = 'imported'
WHERE ls.status = 'imported'
    {$where_date}
    {$route_condition}
    {$rep_condition}
GROUP BY ls.delivery_date, ls.route_code, r.route_name, ls.sales_person_code
ORDER BY ls.delivery_date ASC, ls.sales_person_code ASC, route_name ASC
";

$result      = mysqli_query($conn, $sql);
$query_error = $result ? '' : mysqli_error($conn);

/* ── BUILD ROWS ── */
$rows = [];
$cum_pre_count = $cum_canceled = $cum_pre_value = $cum_sec_value = 0;

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $pre_count   = (int)$row['pre_sec_count'];
        $canceled    = (int)$row['canceled_count'];
        $balance     = $pre_count - $canceled;
        $pre_value   = (float)$row['pre_sec_value'];
        $sec_value   = (float)$row['sec_value_total'];
        $balance_pct = $pre_count > 0 ? ($balance / $pre_count) * 100 : 0;
        $value_pct   = $pre_value > 0 ? ($sec_value / $pre_value) * 100 : 0;

        $cum_pre_count += $pre_count;
        $cum_canceled  += $canceled;
        $cum_pre_value += $pre_value;
        $cum_sec_value += $sec_value;

        $rows[] = [
            'delivery_date'   => $row['delivery_date'],
            'route_code'      => $row['route_code'],
            'route_name'      => $row['route_name'] ?: $row['route_code'],
            'rep_code'        => $row['rep_code'],
            'pre_sec_count'   => $pre_count,
            'pre_sec_value'   => $pre_value,
            'canceled_count'  => $canceled,
            'balance_count'   => $balance,
            'balance_pct'     => $balance_pct,
            'value_pct'       => $value_pct,
            'sec_value_total' => $sec_value,
            'value_diff'      => $pre_value - $sec_value,
            'cum_pre_count'   => $cum_pre_count,
            'cum_canceled'    => $cum_canceled,
            'cum_pre_value'   => $cum_pre_value,
            'cum_sec_value'   => $cum_sec_value,
            'cum_balance'     => $cum_pre_count - $cum_canceled,
        ];
    }
    mysqli_free_result($result);
}

/* ── GRAND TOTALS ── */
$grand_pre_count   = $cum_pre_count;
$grand_canceled    = $cum_canceled;
$grand_balance     = $grand_pre_count - $grand_canceled;
$grand_pre_value   = $cum_pre_value;
$grand_sec_value   = $cum_sec_value;
$grand_balance_pct = $grand_pre_count > 0 ? ($grand_balance / $grand_pre_count) * 100 : 0;
$grand_value_pct   = $grand_pre_value > 0 ? ($grand_sec_value / $grand_pre_value) * 100 : 0;

/* ── REP GROUPING (for JS export) ── */
$rep_grouped = [];
foreach ($rows as $r) {
    $k = $r['rep_code'];
    if (!isset($rep_grouped[$k])) $rep_grouped[$k] = ['rep_code' => $r['rep_code'], 'rows' => []];
    $rep_grouped[$k]['rows'][] = $r;
}

$rep_grouped_json = json_encode(array_values($rep_grouped), JSON_NUMERIC_CHECK);
$all_rows_json    = json_encode($rows, JSON_NUMERIC_CHECK);
$filter_label     = $filter_month ?: ($filter_date_from . ' to ' . $filter_date_to);
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet">
<style>
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:16px;color:#1f2937;display:flex;align-items:center;gap:8px}
.filter-bar{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:20px}
.filter-group{display:flex;flex-direction:column;gap:4px}
.filter-group label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px}
.filter-input{padding:8px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;color:#333;background:#fff;min-width:150px}
.filter-input:focus{outline:none;border-color:#000}
.filter-group .select2-container{min-width:180px}
.filter-group .select2-container--default .select2-selection--single{height:38px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;display:flex;align-items:center}
.filter-group .select2-container--default .select2-selection--single .select2-selection__rendered{line-height:38px;padding-left:12px;color:#333}
.filter-group .select2-container--default .select2-selection--single .select2-selection__arrow{height:36px;right:6px}
.filter-group .select2-container--default.select2-container--focus .select2-selection--single,
.filter-group .select2-container--default.select2-container--open  .select2-selection--single{border-color:#000}
.select2-dropdown{border:1px solid #e5e5e5;border-radius:6px;font-size:13px;box-shadow:0 4px 12px rgba(0,0,0,.08)}
.select2-container--default .select2-search--dropdown .select2-search__field{border:1px solid #e5e5e5;border-radius:4px;padding:6px 10px;font-size:13px}
.select2-container--default .select2-results__option--highlighted[aria-selected]{background:#000;color:#fff}
.select2-container--default .select2-selection--single .select2-selection__clear{margin-right:20px;font-size:16px;color:#9ca3af;line-height:36px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}
.btn-primary{background:#000;color:#fff}.btn-primary:hover{background:#333}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover{background:#e5e5e5}
.btn-success{background:#16a34a;color:#fff}.btn-success:hover{background:#15803d}
.btn-teal{background:#0d9488;color:#fff}.btn-teal:hover{background:#0f766e}
.btn-sm{padding:6px 14px;font-size:12px}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px}
.kpi-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:16px;position:relative;overflow:hidden}
.kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.kpi-blue::before{background:#3b82f6}.kpi-red::before{background:#ef4444}
.kpi-green::before{background:#22c55e}.kpi-purple::before{background:#8b5cf6}
.kpi-orange::before{background:#f97316}.kpi-teal::before{background:#14b8a6}
.kpi-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.kpi-value{font-size:22px;font-weight:700;color:#1f2937}
.kpi-sub{font-size:11px;color:#9ca3af;margin-top:3px}
.alert-error{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:8px;padding:16px 20px;margin-bottom:20px;font-size:13px}
/* ── Date range notice ── */
.date-range-notice{display:flex;align-items:center;gap:10px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 18px;margin-bottom:16px;font-size:13px;color:#1e40af}
.date-range-notice .drn-icon{font-size:16px;flex-shrink:0}
.date-range-notice strong{font-weight:700}
.date-range-notice .drn-badge{background:#1d4ed8;color:#fff;border-radius:4px;padding:2px 10px;font-size:12px;font-weight:700;margin-left:4px}
.table-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch}
.report-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1350px;table-layout:fixed}
.report-table thead tr:first-child th{background:#1f2937;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:10px 8px;text-align:center;border:1px solid #374151}
.report-table thead tr:last-child th{background:#374151;color:#d1d5db;font-size:10px;font-weight:600;padding:7px 8px;text-align:center;border:1px solid #4b5563}
.report-table tbody tr{border-bottom:1px solid #f0f0f0}
.report-table tbody tr:hover{background:#f8fafc}
.report-table td{padding:9px 8px;color:#374151;text-align:center;border:1px solid #f0f0f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.report-table td.left{text-align:left}
.report-table tfoot tr{background:#f1f5f9;font-weight:700}
.report-table tfoot td{padding:10px 8px;border:1px solid #e2e8f0;font-size:12px;color:#1f2937}
.col-pre{background:#eff6ff}.col-cancel{background:#fef2f2}
.col-balance{background:#f0fdf4}.col-running{background:#faf5ff}
.col-value{background:#fff7ed}.col-vpct{background:#f0fdfa}
.th-pre{background:#1d4ed8 !important;border-color:#1e40af !important}
.th-cancel{background:#dc2626 !important;border-color:#b91c1c !important}
.th-balance{background:#16a34a !important;border-color:#15803d !important}
.th-running{background:#7c3aed !important;border-color:#6d28d9 !important}
.th-value{background:#ea580c !important;border-color:#c2410c !important}
.th-vpct{background:#0d9488 !important;border-color:#0f766e !important}
.pct-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700}
.pct-high{background:#dcfce7;color:#166534}.pct-mid{background:#fef9c3;color:#92400e}
.pct-low{background:#fee2e2;color:#991b1b}
.zero-row td{color:#d1d5db !important}
.rep-active-badge{display:inline-flex;align-items:center;gap:6px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600;margin-left:8px}
/* Loading overlay */
#page-loader{position:fixed;inset:0;background:rgba(255,255,255,.75);z-index:9999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px)}
#page-loader .ld-box{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:24px 36px;text-align:center;box-shadow:0 8px 24px rgba(0,0,0,.1)}
#page-loader .ld-spinner{width:36px;height:36px;border:3px solid #e5e5e5;border-top-color:#3b82f6;border-radius:50%;animation:spin .7s linear infinite;margin:0 auto 12px}
@keyframes spin{to{transform:rotate(360deg)}}
#page-loader p{font-size:13px;color:#6b7280;margin:0}
@media print{.filter-bar,.btn,#page-loader,.date-range-notice{display:none}.content-card{border:none;padding:0}.report-table{font-size:10px}}
</style>

<!-- Page-load spinner (hidden after DOM ready) -->
<div id="page-loader">
    <div class="ld-box">
        <div class="ld-spinner"></div>
        <p>Loading report…</p>
    </div>
</div>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-chart-bar" style="color:#3b82f6"></i>
                CCF Report
                <?php if ($filter_rep !== ''): ?>
                <span class="rep-active-badge">
                    <i class="fa-solid fa-user"></i>
                    <?php echo htmlspecialchars($filter_rep); ?>
                </span>
                <?php endif; ?>
            </h2>
            <p class="page-subtitle">Pre-Secondary vs Secondary Invoices Analysis</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button onclick="window.print()" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-print"></i> Print
            </button>
            <button onclick="exportExcelFull()" class="btn btn-success btn-sm">
                <i class="fa-solid fa-file-excel"></i> Export Excel
            </button>
            <button onclick="exportExcelRepWise()" class="btn btn-teal btn-sm">
                <i class="fa-solid fa-layer-group"></i> Export Rep-wise Excel
            </button>
        </div>
    </div>
</div>

<?php if ($query_error): ?>
<div class="alert-error"><strong>Database error:</strong> <?php echo htmlspecialchars($query_error); ?></div>
<?php endif; ?>

<div class="content-card">
    <form method="GET" action="">
        <div class="filter-bar">
            <div class="filter-group">
                <label>Month</label>
                <input type="month" name="month" id="inp-month" class="filter-input"
                       value="<?php echo htmlspecialchars($filter_month); ?>">
            </div>
            <div class="filter-group">
                <label>From Date</label>
                <input type="date" name="date_from" id="inp-date-from" class="filter-input"
                       value="<?php echo htmlspecialchars($filter_date_from); ?>">
            </div>
            <div class="filter-group">
                <label>To Date</label>
                <input type="date" name="date_to" id="inp-date-to" class="filter-input"
                       value="<?php echo htmlspecialchars($filter_date_to); ?>">
            </div>
            <div class="filter-group">
                <label>Route</label>
                <select name="route" id="filter-route">
                    <option value="">All Routes</option>
                    <?php if ($routes_result) while ($rw = mysqli_fetch_assoc($routes_result)): ?>
                        <option value="<?php echo htmlspecialchars($rw['route_code']); ?>"
                            <?php echo ($filter_route === $rw['route_code']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($rw['route_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Rep Code</label>
                <select name="rep" id="filter-rep">
                    <option value="">All Reps</option>
                    <?php foreach ($reps_rows as $rr): ?>
                        <option value="<?php echo htmlspecialchars($rr['rep_code']); ?>"
                            <?php echo ($filter_rep === $rr['rep_code']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($rr['rep_code']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>&nbsp;</label>
                <div style="display:flex;gap:8px">
                    <button type="submit" class="btn btn-primary" id="btn-apply">
                        <i class="fa-solid fa-filter"></i> Apply
                    </button>
                    <a href="loading_summary_report.php" class="btn btn-secondary">
                        <i class="fa-solid fa-rotate-left"></i> Reset
                    </a>
                </div>
            </div>
        </div>

        <!-- ── ACTIVE DATE RANGE NOTICE ── -->
        <?php if ($active_range_label): ?>
        <div class="date-range-notice">
            <span class="drn-icon"><i class="fa-solid fa-calendar-range"></i></span>
            <div>
                <?php if ($using_custom_month): ?>
                    Month <strong><?php echo date('F Y', strtotime($filter_month . '-01')); ?></strong>
                    covers&nbsp;
                    <span class="drn-badge"><?php echo htmlspecialchars($active_range_label); ?></span>
                    &nbsp;<span style="color:#6b7280;font-size:12px">(26th of previous month → 25th of selected month)</span>
                <?php else: ?>
                    Showing custom range:
                    <span class="drn-badge"><?php echo htmlspecialchars($active_range_label); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- KPI Cards -->
<div class="kpi-grid">
    <div class="kpi-card kpi-blue">
        <div class="kpi-label">Pre Secondary Invoices</div>
        <div class="kpi-value"><?php echo number_format($grand_pre_count); ?></div>
        <div class="kpi-sub">Total bill count</div>
    </div>
    <div class="kpi-card kpi-red">
        <div class="kpi-label">Canceled Bills</div>
        <div class="kpi-value"><?php echo number_format($grand_canceled); ?></div>
        <div class="kpi-sub">Not in secondary invoices</div>
    </div>
    <div class="kpi-card kpi-green">
        <div class="kpi-label">Balance Bills</div>
        <div class="kpi-value"><?php echo number_format($grand_balance); ?></div>
        <div class="kpi-sub">Pre Secondary – Canceled</div>
    </div>
    <div class="kpi-card kpi-orange">
        <div class="kpi-label">Pre Secondary Value</div>
        <div class="kpi-value"><?php echo number_format($grand_pre_value, 0); ?></div>
        <div class="kpi-sub">Total loading value</div>
    </div>
    <div class="kpi-card kpi-purple">
        <div class="kpi-label">Secondary Invoice Value</div>
        <div class="kpi-value"><?php echo number_format($grand_sec_value, 0); ?></div>
        <div class="kpi-sub">Confirmed invoice value</div>
    </div>
    <div class="kpi-card <?php echo $grand_balance_pct>=90?'kpi-green':($grand_balance_pct>=70?'kpi-orange':'kpi-red'); ?>">
        <div class="kpi-label">Balance Bill %</div>
        <div class="kpi-value"><?php echo number_format($grand_balance_pct,1); ?>%</div>
        <div class="kpi-sub">(Balance / Pre Secondary) × 100</div>
    </div>
    <div class="kpi-card kpi-teal">
        <div class="kpi-label">Invoice Value %</div>
        <div class="kpi-value"><?php echo number_format($grand_value_pct,1); ?>%</div>
        <div class="kpi-sub">(Sec Value / Pre Value) × 100</div>
    </div>
</div>

<?php if (empty($filter_rep) && count($rows) > 0):
    $rep_summary = [];
    foreach ($rows as $r) {
        $k = $r['rep_code'];
        if (!isset($rep_summary[$k])) $rep_summary[$k] = ['rep_code'=>$r['rep_code'],'pre'=>0,'canceled'=>0,'pre_val'=>0,'sec_val'=>0];
        $rep_summary[$k]['pre']      += $r['pre_sec_count'];
        $rep_summary[$k]['canceled'] += $r['canceled_count'];
        $rep_summary[$k]['pre_val']  += $r['pre_sec_value'];
        $rep_summary[$k]['sec_val']  += $r['sec_value_total'];
    }
    ksort($rep_summary);
?>
<div class="content-card">
    <h3 class="card-title"><i class="fa-solid fa-users" style="color:#6366f1"></i> Rep-wise Summary</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px">
        <?php foreach ($rep_summary as $rs):
            $bal    = $rs['pre'] - $rs['canceled'];
            $bpct   = $rs['pre'] > 0 ? ($bal / $rs['pre']) * 100 : 0;
            $vpct   = $rs['pre_val'] > 0 ? ($rs['sec_val'] / $rs['pre_val']) * 100 : 0;
            $bcolor = $bpct >= 90 ? '#16a34a' : ($bpct >= 70 ? '#d97706' : '#dc2626');
            $vcolor = $vpct >= 90 ? '#16a34a' : ($vpct >= 70 ? '#d97706' : '#dc2626');
            // Build filter URL preserving date range params
            $furl = '?month=' . urlencode($filter_month)
                  . '&rep=' . urlencode($rs['rep_code'])
                  . ($filter_date_from ? '&date_from=' . urlencode($filter_date_from) : '')
                  . ($filter_date_to   ? '&date_to='   . urlencode($filter_date_to)   : '');
        ?>
        <div style="border:1px solid #e5e5e5;border-radius:8px;padding:14px;background:#fafafa;border-top:3px solid <?php echo $bcolor; ?>">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                <div style="font-weight:700;font-size:15px;color:#6366f1;font-family:monospace"><?php echo htmlspecialchars($rs['rep_code']); ?></div>
                <a href="<?php echo $furl; ?>"
                   style="font-size:11px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:4px;padding:3px 8px;text-decoration:none">
                    <i class="fa-solid fa-filter"></i> Filter
                </a>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:12px">
                <div style="background:#fff;border-radius:4px;padding:6px;border:1px solid #f0f0f0;text-align:center">
                    <div style="color:#6b7280;font-size:10px">Pre Sec</div>
                    <div style="font-weight:700;color:#1d4ed8"><?php echo number_format($rs['pre']); ?></div>
                </div>
                <div style="background:#fff;border-radius:4px;padding:6px;border:1px solid #f0f0f0;text-align:center">
                    <div style="color:#6b7280;font-size:10px">Canceled</div>
                    <div style="font-weight:700;color:#dc2626"><?php echo number_format($rs['canceled']); ?></div>
                </div>
                <div style="background:#fff;border-radius:4px;padding:6px;border:1px solid #f0f0f0;text-align:center">
                    <div style="color:#6b7280;font-size:10px">Balance %</div>
                    <div style="font-weight:700;color:<?php echo $bcolor; ?>"><?php echo number_format($bpct,1); ?>%</div>
                </div>
                <div style="background:#fff;border-radius:4px;padding:6px;border:1px solid #f0f0f0;text-align:center">
                    <div style="color:#6b7280;font-size:10px">Value %</div>
                    <div style="font-weight:700;color:<?php echo $vcolor; ?>"><?php echo number_format($vpct,1); ?>%</div>
                </div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:11px;margin-top:8px;color:#6b7280">
                <span>Pre: <strong style="color:#ea580c"><?php echo number_format($rs['pre_val'],0); ?></strong></span>
                <span>Sec: <strong style="color:#0d9488"><?php echo number_format($rs['sec_val'],0); ?></strong></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="content-card">
    <h3 class="card-title">
        <i class="fa-solid fa-table" style="color:#3b82f6"></i>
        Detailed Report
        <span style="font-size:12px;font-weight:400;color:#9ca3af;margin-left:8px"><?php echo count($rows); ?> row(s)</span>
    </h3>
    <?php if ($query_error): ?>
        <div class="alert-error">Query failed — see error above.</div>
    <?php elseif (empty($rows)): ?>
        <div style="text-align:center;padding:40px;color:#9ca3af">
            <i class="fa-solid fa-inbox" style="font-size:32px;margin-bottom:12px;display:block"></i>
            No data found for the selected filters.
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="report-table">
            <thead>
                <tr>
                    <th rowspan="2" style="width:100px">Delivery Date</th>
                    <th rowspan="2" style="width:80px">Rep Code</th>
                    <th rowspan="2" style="width:130px">Route</th>
                    <th colspan="2" class="th-pre">Pre Secondary Invoices</th>
                    <th colspan="2" class="th-cancel">Canceled Bills<br><small style="font-weight:400;font-size:9px">(Not in Secondary)</small></th>
                    <th colspan="2" class="th-balance">Balance Bills<br><small style="font-weight:400;font-size:9px">(Pre – Canceled)</small></th>
                    <th colspan="3" class="th-running">Running Totals</th>
                    <th colspan="4" class="th-value">Values</th>
                    <th colspan="1" class="th-vpct">Value %<br><small style="font-weight:400;font-size:9px">(Sec/Pre×100)</small></th>
                </tr>
                <tr>
                    <th class="th-pre">Count</th>
                    <th class="th-pre">Total Value</th>
                    <th class="th-cancel">Count</th>
                    <th class="th-cancel">Running</th>
                    <th class="th-balance">Count</th>
                    <th class="th-balance">% <small>(Bal/Pre×100)</small></th>
                    <th class="th-running">Cum Pre Count</th>
                    <th class="th-running">Cum Balance</th>
                    <th class="th-running">Cum Pre Value</th>
                    <th class="th-value">Pre Value</th>
                    <th class="th-value">Sec Value Total</th>
                    <th class="th-value">Pre–Sec Diff</th>
                    <th class="th-value">Cum Sec Value</th>
                    <th class="th-vpct">Sec/Pre Value %</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row):
                $is_zero  = ($row['pre_sec_count'] == 0);
                $pct      = $row['balance_pct'];
                $pct_cls  = $pct >= 90 ? 'pct-high' : ($pct >= 70 ? 'pct-mid' : 'pct-low');
                $vpct     = $row['value_pct'];
                $vpct_cls = $vpct >= 90 ? 'pct-high' : ($vpct >= 70 ? 'pct-mid' : 'pct-low');
            ?>
            <tr class="<?php echo $is_zero ? 'zero-row' : ''; ?>">
                <td class="left"><?php echo date('M d, Y', strtotime($row['delivery_date'])); ?></td>
                <td style="font-family:monospace;font-weight:700;color:#6366f1"><?php echo htmlspecialchars($row['rep_code']); ?></td>
                <td class="left"><?php echo htmlspecialchars($row['route_name']); ?></td>
                <td class="col-pre"><?php echo number_format($row['pre_sec_count']); ?></td>
                <td class="col-pre"><?php echo number_format($row['pre_sec_value'], 2); ?></td>
                <td class="col-cancel" style="color:<?php echo $row['canceled_count']>0?'#dc2626':'#16a34a'; ?>;font-weight:600"><?php echo number_format($row['canceled_count']); ?></td>
                <td class="col-cancel"><?php echo number_format($row['cum_canceled']); ?></td>
                <td class="col-balance" style="font-weight:600;color:#15803d"><?php echo number_format($row['balance_count']); ?></td>
                <td class="col-balance">
                    <?php if (!$is_zero): ?>
                        <span class="pct-badge <?php echo $pct_cls; ?>"><?php echo number_format($pct,1); ?>%</span>
                    <?php else: echo '—'; endif; ?>
                </td>
                <td class="col-running"><?php echo number_format($row['cum_pre_count']); ?></td>
                <td class="col-running"><?php echo number_format($row['cum_balance']); ?></td>
                <td class="col-running"><?php echo number_format($row['cum_pre_value'], 2); ?></td>
                <td class="col-value"><?php echo number_format($row['pre_sec_value'], 2); ?></td>
                <td class="col-value" style="font-weight:600"><?php echo number_format($row['sec_value_total'], 2); ?></td>
                <td class="col-value" style="color:<?php echo $row['value_diff']>0?'#dc2626':'#16a34a'; ?>;font-weight:600"><?php echo number_format($row['value_diff'], 2); ?></td>
                <td class="col-value"><?php echo number_format($row['cum_sec_value'], 2); ?></td>
                <td class="col-vpct">
                    <?php if (!$is_zero && $row['pre_sec_value'] > 0): ?>
                        <span class="pct-badge <?php echo $vpct_cls; ?>"><?php echo number_format($vpct,1); ?>%</span>
                    <?php else: echo '—'; endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="left"><strong>GRAND TOTAL</strong></td>
                    <td class="col-pre"><strong><?php echo number_format($grand_pre_count); ?></strong></td>
                    <td class="col-pre"><strong><?php echo number_format($grand_pre_value, 2); ?></strong></td>
                    <td class="col-cancel" style="color:#dc2626"><strong><?php echo number_format($grand_canceled); ?></strong></td>
                    <td class="col-cancel">—</td>
                    <td class="col-balance" style="color:#15803d"><strong><?php echo number_format($grand_balance); ?></strong></td>
                    <td class="col-balance">
                        <span class="pct-badge <?php echo $grand_balance_pct>=90?'pct-high':($grand_balance_pct>=70?'pct-mid':'pct-low'); ?>">
                            <?php echo number_format($grand_balance_pct,1); ?>%
                        </span>
                    </td>
                    <td class="col-running"><strong><?php echo number_format($grand_pre_count); ?></strong></td>
                    <td class="col-running"><strong><?php echo number_format($grand_balance); ?></strong></td>
                    <td class="col-running"><strong><?php echo number_format($grand_pre_value, 2); ?></strong></td>
                    <td class="col-value"><strong><?php echo number_format($grand_pre_value, 2); ?></strong></td>
                    <td class="col-value"><strong><?php echo number_format($grand_sec_value, 2); ?></strong></td>
                    <td class="col-value" style="color:<?php echo ($grand_pre_value-$grand_sec_value)>0?'#dc2626':'#16a34a'; ?>">
                        <strong><?php echo number_format($grand_pre_value - $grand_sec_value, 2); ?></strong>
                    </td>
                    <td class="col-value"><strong><?php echo number_format($grand_sec_value, 2); ?></strong></td>
                    <td class="col-vpct">
                        <span class="pct-badge <?php echo $grand_value_pct>=90?'pct-high':($grand_value_pct>=70?'pct-mid':'pct-low'); ?>">
                            <?php echo number_format($grand_value_pct,1); ?>%
                        </span>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── JS: deferred to end of body for faster perceived load ── -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script>
/* ── Remove loader as soon as DOM is ready ── */
document.addEventListener('DOMContentLoaded', function () {
    var loader = document.getElementById('page-loader');
    if (loader) loader.style.display = 'none';

    /* ── Select2 init (waits for jQuery + Select2 to be available) ── */
    function tryInitSelect2() {
        if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') {
            return setTimeout(tryInitSelect2, 50);
        }
        var base = { width: '100%', allowClear: true, minimumResultsForSearch: 0 };
        jQuery('#filter-route').select2(jQuery.extend({}, base, { placeholder: 'All Routes' }));
        jQuery('#filter-rep').select2(jQuery.extend({}, base, { placeholder: 'All Reps' }));
    }
    tryInitSelect2();

    /* ── Clear date_from / date_to when month changes, and vice-versa ── */
    var inpMonth = document.getElementById('inp-month');
    var inpFrom  = document.getElementById('inp-date-from');
    var inpTo    = document.getElementById('inp-date-to');
    if (inpMonth) {
        inpMonth.addEventListener('change', function () {
            if (this.value) { inpFrom.value = ''; inpTo.value = ''; }
        });
    }
    if (inpFrom || inpTo) {
        function clearMonth() { if (inpMonth) inpMonth.value = ''; }
        if (inpFrom) inpFrom.addEventListener('change', clearMonth);
        if (inpTo)   inpTo.addEventListener('change', clearMonth);
    }

    /* ── Show loader on Apply ── */
    var btnApply = document.getElementById('btn-apply');
    if (btnApply) {
        btnApply.closest('form').addEventListener('submit', function () {
            var loader = document.getElementById('page-loader');
            if (loader) loader.style.display = 'flex';
        });
    }
});

/* ── Excel export data ── */
const ALL_ROWS    = <?php echo $all_rows_json; ?>;
const REP_GROUPED = <?php echo $rep_grouped_json; ?>;
const FILTER_LBL  = <?php echo json_encode($filter_label); ?>;

const HEADERS = [
    'Delivery Date','Rep Code','Route',
    'Pre Sec Count','Pre Sec Value',
    'Canceled Count','Running Canceled',
    'Balance Count','Balance %',
    'Cum Pre Count','Cum Balance','Cum Pre Value',
    'Pre Value','Sec Value Total','Pre-Sec Diff','Cum Sec Value',
    'Sec/Pre Value %'
];
const SUMM_HEADERS = [
    'Rep Code','Pre Count','Canceled','Balance','Balance %','Pre Value','Sec Value','Value %'
];

function pct(a, b) { return b > 0 ? ((a / b) * 100).toFixed(1) + '%' : '—'; }

function rowToArr(r) {
    return [
        r.delivery_date, r.rep_code, r.route_name,
        r.pre_sec_count, +r.pre_sec_value,
        r.canceled_count, r.cum_canceled,
        r.balance_count, pct(r.balance_count, r.pre_sec_count),
        r.cum_pre_count, r.cum_balance, +r.cum_pre_value,
        +r.pre_sec_value, +r.sec_value_total, +r.value_diff, +r.cum_sec_value,
        pct(r.sec_value_total, r.pre_sec_value)
    ];
}

function grandRow(rows) {
    const pre    = rows.reduce((s, r) => s + r.pre_sec_count, 0);
    const cncl   = rows.reduce((s, r) => s + r.canceled_count, 0);
    const bal    = pre - cncl;
    const preVal = rows.reduce((s, r) => s + +r.pre_sec_value, 0);
    const secVal = rows.reduce((s, r) => s + +r.sec_value_total, 0);
    return ['GRAND TOTAL', '', '', pre, preVal, cncl, '', bal, pct(bal, pre), pre, bal, preVal, preVal, secVal, preVal - secVal, secVal, pct(secVal, preVal)];
}

function makeSheet(rows, title) {
    const data = [];
    data.push([title]);
    data.push(['Filter: ' + FILTER_LBL]);
    data.push([]);
    data.push(HEADERS);
    rows.forEach(r => data.push(rowToArr(r)));
    data.push(grandRow(rows));
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = HEADERS.map((_, i) => ({ wch: i < 3 ? 18 : 14 }));
    ws['!merges'] = [
        { s: { r: 0, c: 0 }, e: { r: 0, c: HEADERS.length - 1 } },
        { s: { r: 1, c: 0 }, e: { r: 1, c: HEADERS.length - 1 } }
    ];
    return ws;
}

function makeSummarySheet() {
    const data = [];
    data.push(['CCF Report — Rep-wise Summary']);
    data.push(['Filter: ' + FILTER_LBL]);
    data.push([]);
    data.push(SUMM_HEADERS);
    REP_GROUPED.forEach(grp => {
        const rows = grp.rows;
        const pre    = rows.reduce((s, r) => s + r.pre_sec_count, 0);
        const cncl   = rows.reduce((s, r) => s + r.canceled_count, 0);
        const bal    = pre - cncl;
        const preVal = rows.reduce((s, r) => s + +r.pre_sec_value, 0);
        const secVal = rows.reduce((s, r) => s + +r.sec_value_total, 0);
        data.push([grp.rep_code, pre, cncl, bal, pct(bal, pre), preVal, secVal, pct(secVal, preVal)]);
    });
    const all  = REP_GROUPED.flatMap(g => g.rows);
    const gPre = all.reduce((s, r) => s + r.pre_sec_count, 0);
    const gCncl= all.reduce((s, r) => s + r.canceled_count, 0);
    const gBal = gPre - gCncl;
    const gPV  = all.reduce((s, r) => s + +r.pre_sec_value, 0);
    const gSV  = all.reduce((s, r) => s + +r.sec_value_total, 0);
    data.push(['TOTAL', gPre, gCncl, gBal, pct(gBal, gPre), gPV, gSV, pct(gSV, gPV)]);
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = SUMM_HEADERS.map(() => ({ wch: 14 }));
    ws['!merges'] = [
        { s: { r: 0, c: 0 }, e: { r: 0, c: SUMM_HEADERS.length - 1 } },
        { s: { r: 1, c: 0 }, e: { r: 1, c: SUMM_HEADERS.length - 1 } }
    ];
    return ws;
}

function exportExcelFull() {
    if (!ALL_ROWS || ALL_ROWS.length === 0) { alert('No data.'); return; }
    if (typeof XLSX === 'undefined') { alert('Excel library still loading, please wait a moment.'); return; }
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, makeSheet(ALL_ROWS, 'CCF Report — All'), 'All Data');
    XLSX.writeFile(wb, 'CCF_Report_' + FILTER_LBL + '.xlsx');
}

function exportExcelRepWise() {
    if (!REP_GROUPED || REP_GROUPED.length === 0) { alert('No data.'); return; }
    if (typeof XLSX === 'undefined') { alert('Excel library still loading, please wait a moment.'); return; }
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, makeSummarySheet(), 'Summary');
    REP_GROUPED.forEach(grp => {
        const name = grp.rep_code.replace(/[\\\/\?\*\[\]:]/g, '_').substring(0, 31);
        XLSX.utils.book_append_sheet(wb, makeSheet(grp.rows, 'CCF — ' + grp.rep_code), name);
    });
    XLSX.writeFile(wb, 'CCF_RepWise_' + FILTER_LBL + '.xlsx');
}
</script>

<?php
ob_end_flush();
include 'footer.php';
?>