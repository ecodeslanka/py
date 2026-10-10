<?php
include 'config.php';
include 'header.php';
mysqli_report(MYSQLI_REPORT_OFF);

/* =========================================================================
   DAILY REPORT STATUS — upload/import tracking dashboard
   =========================================================================
   Shows, per category, whether the day's data was Uploaded / is Pending /
   is Overdue / Not Required, for each date in the selected range.

   HOW TO TUNE THIS PAGE
   ----------------------
   Everything category-specific lives in $CATEGORIES below. Each category
   has one or more "checks" — a check is just a SQL query that returns the
   DISTINCT dates (as Y-m-d) on which that source has data. If ANY check
   under a category has data for a date, that whole row-cell is "Uploaded".

   Table/column choices below were mapped from the existing codebase
   (grep'd from each import script). A few are best-effort guesses — the
   ones most likely to need a tweak are marked with // VERIFY.
   ========================================================================= */

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES); }
}

$GRACE_DAYS_DEFAULT = 1; // days after which a missing date flips from Pending -> Overdue

$CATEGORIES = [
    [
        'key'   => 'hr',
        'icon'  => 'fa-solid fa-users',
        'color' => '#f43f5e',
        'bg'    => '#fde8ec',
        'title' => 'Human Resources',
        'sub'   => 'Attendance Import',
        'off_days' => [0], // Sunday not required
        'grace' => 1,
        'checks' => [
            "SELECT DISTINCT att_date AS d FROM attendance WHERE att_date BETWEEN '{from}' AND '{to}'",
        ],
    ],
    [
        'key'   => 'vehicle',
        'icon'  => 'fa-solid fa-truck',
        'color' => '#65a30d',
        'bg'    => '#ecfccb',
        'title' => 'Vehicle Tracking',
        'sub'   => 'Position, Parking Reports',
        'off_days' => [],
        'grace' => 1,
        'checks' => [
            "SELECT DISTINCT DATE(date_from) AS d FROM vt_position_imports WHERE date_from BETWEEN '{from}' AND '{to} 23:59:59'",
            "SELECT DISTINCT DATE(date_from) AS d FROM vt_park_imports WHERE date_from BETWEEN '{from}' AND '{to}'",
        ],
    ],
    [
        'key'   => 'transactions',
        'icon'  => 'fa-solid fa-file-invoice-dollar',
        'color' => '#d97706',
        'bg'    => '#fef3c7',
        'title' => 'Daily Transaction Manage',
        'sub'   => 'Primary/Secondary, Unloading, Bank Statements',
        'off_days' => [],
        'grace' => 1,
        'checks' => [
            "SELECT DISTINCT invoice_date AS d FROM primary_invoices WHERE invoice_date BETWEEN '{from}' AND '{to}'", // VERIFY
            "SELECT DISTINCT delivery_date AS d FROM secondary_invoice_imports WHERE delivery_date BETWEEN '{from}' AND '{to}'",
            "SELECT DISTINCT delivery_date AS d FROM unloading_summary_imports WHERE delivery_date BETWEEN '{from}' AND '{to}'",
            "SELECT DISTINCT statement_date AS d FROM bank_statement_uploads WHERE statement_date BETWEEN '{from}' AND '{to}'",
        ],
    ],
    [
        'key'   => 'inventory',
        'icon'  => 'fa-solid fa-boxes-stacked',
        'color' => '#059669',
        'bg'    => '#d1fae5',
        'title' => 'Inventory',
        'sub'   => 'Current, Primary, Secondary Stock',
        'off_days' => [],
        'grace' => 1,
        'checks' => [
            "SELECT DISTINCT DATE(created_at) AS d FROM current_stock_uploads WHERE created_at BETWEEN '{from}' AND '{to} 23:59:59'", // VERIFY column name
            "SELECT DISTINCT DATE(imported_at) AS d FROM stock_uploads WHERE imported_at BETWEEN '{from}' AND '{to} 23:59:59'",
        ],
    ],
    [
        'key'   => 'credit',
        'icon'  => 'fa-solid fa-file-shield',
        'color' => '#dc2626',
        'bg'    => '#fee2e2',
        'title' => 'Credit Bill Management',
        'sub'   => 'AI Scan & Credit Requests',
        'off_days' => [],
        'grace' => 2,
        'checks' => [
            "SELECT DISTINCT issue_date AS d FROM credit_bill_issues WHERE issue_date BETWEEN '{from}' AND '{to}'",
        ],
    ],
    [
        'key'   => 'cheques',
        'icon'  => 'fa-solid fa-money-check',
        'color' => '#2563eb',
        'bg'    => '#dbeafe',
        'title' => 'Cheques',
        'sub'   => 'Cheque Import & Reconciliation',
        'off_days' => [],
        'grace' => 1,
        'checks' => [
            "SELECT DISTINCT statement_date AS d FROM cheque_recon_uploads WHERE statement_date BETWEEN '{from}' AND '{to}'",
        ],
    ],
    [
        'key'   => 'customer_ledger',
        'icon'  => 'fa-solid fa-clipboard-list',
        'color' => '#7c3aed',
        'bg'    => '#ede9fe',
        'title' => 'Customer Ledger Import',
        'sub'   => 'Returns & Unilever Ledger',
        'off_days' => [],
        'grace' => 1,
        'checks' => [
            "SELECT DISTINCT bill_date AS d FROM purchase_return_imports WHERE bill_date BETWEEN '{from}' AND '{to}'",
            "SELECT DISTINCT DATE(created_at) AS d FROM unilever_reconcile_log WHERE created_at BETWEEN '{from}' AND '{to} 23:59:59'", // VERIFY column name
        ],
    ],
    [
        'key'   => 'vendor_ledger',
        'icon'  => 'fa-solid fa-file-signature',
        'color' => '#7c3aed',
        'bg'    => '#ede9fe',
        'title' => 'Vendor Ledger Import',
        'sub'   => 'Cert. & Scheme Analysis',
        'off_days' => [0], // Sunday not required
        'grace' => 1,
        'checks' => [
            "SELECT DISTINCT DATE(updated_at) AS d FROM credit_policy_import_history WHERE updated_at BETWEEN '{from}' AND '{to} 23:59:59'",
            "SELECT DISTINCT DATE(imported_at) AS d FROM scheme_discount_imports WHERE imported_at BETWEEN '{from}' AND '{to} 23:59:59'",
        ],
    ],
];

/* ── Filters ─────────────────────────────────────────────────────────── */
$MAX_RANGE_DAYS = 90; // hard cap so the grid/query set can't explode

$days           = isset($_GET['days']) ? max(1, min($MAX_RANGE_DAYS, intval($_GET['days']))) : 15;
$filter_cat_key = isset($_GET['cat']) ? trim($_GET['cat']) : '';
$req_from       = isset($_GET['from']) ? trim($_GET['from']) : '';
$req_to         = isset($_GET['to'])   ? trim($_GET['to'])   : '';

$today = new DateTime(date('Y-m-d'));
$use_custom_range = false;

if ($req_from !== '' && $req_to !== '' && strtotime($req_from) && strtotime($req_to)) {
    $range_start = new DateTime($req_from);
    $range_end   = new DateTime($req_to);

    // Swap if entered backwards
    if ($range_start > $range_end) {
        $tmp = $range_start; $range_start = $range_end; $range_end = $tmp;
    }
    // Don't let the grid run past today
    if ($range_end > $today) $range_end = clone $today;

    // Cap the span so the page/queries stay fast
    $span_days = (int) $range_start->diff($range_end)->days + 1;
    if ($span_days > $MAX_RANGE_DAYS) {
        $range_start = (clone $range_end)->modify('-' . ($MAX_RANGE_DAYS - 1) . ' days');
    }
    $use_custom_range = true;
} else {
    $range_end   = $today;
    $range_start = (clone $today)->modify('-' . ($days - 1) . ' days');
}

$from_str = $range_start->format('Y-m-d');
$to_str   = $range_end->format('Y-m-d');

/* Date columns, newest first (matches header direction request-wise, but
   the design shows oldest->newest left-to-right, so we build ascending) */
$date_list = [];
$cursor = clone $range_start;
while ($cursor <= $range_end) {
    $date_list[] = $cursor->format('Y-m-d');
    $cursor->modify('+1 day');
}

/* ── Public holidays (extra "Not Required" days) ────────────────────── */
$holiday_dates = [];
$hres = mysqli_query($conn, "SELECT holiday_date FROM public_holidays WHERE holiday_date BETWEEN '$from_str' AND '$to_str'");
if ($hres) {
    while ($hr = mysqli_fetch_row($hres)) {
        $holiday_dates[date('Y-m-d', strtotime($hr[0]))] = true;
    }
}

/* ── Run checks per category, build uploaded-date sets ──────────────── */
function drs_run_checks($conn, $checks, $from, $to) {
    $found = [];
    foreach ($checks as $sqlTemplate) {
        $sql = str_replace(['{from}', '{to}'], [$from, $to], $sqlTemplate);
        $res = @mysqli_query($conn, $sql);
        if (!$res) continue; // table might not exist yet on this install — skip silently
        while ($row = mysqli_fetch_row($res)) {
            if (!$row[0]) continue;
            $found[date('Y-m-d', strtotime($row[0]))] = true;
        }
    }
    return $found;
}

$matrix = []; // [cat_key][date] = 'uploaded'|'pending'|'overdue'|'not_required'
$today_str = $today->format('Y-m-d');

foreach ($CATEGORIES as $cat) {
    if ($filter_cat_key !== '' && $filter_cat_key !== $cat['key']) continue;

    $uploaded_dates = drs_run_checks($conn, $cat['checks'], $from_str, $to_str);
    $grace = isset($cat['grace']) ? intval($cat['grace']) : $GRACE_DAYS_DEFAULT;

    foreach ($date_list as $d) {
        $dow = (int) date('w', strtotime($d));

        if (isset($uploaded_dates[$d])) {
            $status = 'uploaded';
        } elseif (in_array($dow, $cat['off_days'], true) || isset($holiday_dates[$d])) {
            $status = 'not_required';
        } else {
            $days_ago = (strtotime($today_str) - strtotime($d)) / 86400;
            if ($days_ago <= $grace) {
                $status = 'pending';
            } else {
                $status = 'overdue';
            }
        }
        $matrix[$cat['key']][$d] = $status;
    }
}

/* ── Summary counts for the header chips ─────────────────────────────── */
$counts = ['uploaded' => 0, 'pending' => 0, 'overdue' => 0, 'not_required' => 0];
foreach ($matrix as $catRow) {
    foreach ($catRow as $st) $counts[$st]++;
}
?>

<div class="page-header">
    <h2 class="page-title">
        <i class="fa-solid fa-chart-simple"></i> Daily Report Status
    </h2>
    <p class="page-subtitle">Track which category's reports have been uploaded each day</p>
</div>

<div class="drs-card">

    <div class="drs-toolbar">
        <div class="drs-toolbar-left">
            <div class="drs-title-block">
                <span class="drs-title">Daily Report Status</span>
                <span class="drs-range"><?php echo date('d M', strtotime($from_str)) . ' – ' . date('d M Y', strtotime($to_str)); ?></span>
            </div>
        </div>

        <form method="get" class="drs-toolbar-right" id="drsFilterForm">
            <select name="cat" class="drs-select" onchange="document.getElementById('drsFilterForm').submit()">
                <option value="">All Categories</option>
                <?php foreach ($CATEGORIES as $cat): ?>
                <option value="<?php echo h($cat['key']); ?>" <?php echo $filter_cat_key === $cat['key'] ? 'selected' : ''; ?>>
                    <?php echo h($cat['title']); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="days" id="drsDaysSelect" class="drs-select" <?php echo $use_custom_range ? 'disabled' : ''; ?> onchange="drsClearRange();document.getElementById('drsFilterForm').submit()">
                <?php foreach ([7, 15, 30, 60, 90] as $opt): ?>
                <option value="<?php echo $opt; ?>" <?php echo (!$use_custom_range && $days === $opt) ? 'selected' : ''; ?>>Last <?php echo $opt; ?> days</option>
                <?php endforeach; ?>
            </select>

            <span class="drs-range-sep">or</span>

            <input type="date" name="from" id="drsFrom" class="drs-date" value="<?php echo $use_custom_range ? h($from_str) : ''; ?>" max="<?php echo h($today->format('Y-m-d')); ?>">
            <span class="drs-range-dash">–</span>
            <input type="date" name="to" id="drsTo" class="drs-date" value="<?php echo $use_custom_range ? h($to_str) : ''; ?>" max="<?php echo h($today->format('Y-m-d')); ?>">

            <button type="submit" class="drs-apply-btn"><i class="fa-solid fa-filter"></i> Apply</button>

            <?php if ($use_custom_range): ?>
            <a href="daily_report_status.php<?php echo $filter_cat_key !== '' ? '?cat=' . urlencode($filter_cat_key) : ''; ?>" class="drs-clear-btn" title="Clear date range"><i class="fa-solid fa-xmark"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <div class="drs-legend">
        <span class="drs-legend-item"><span class="drs-dot drs-dot-ok"><i class="fa-solid fa-check"></i></span> Uploaded <span class="drs-legend-count"><?php echo $counts['uploaded']; ?></span></span>
        <span class="drs-legend-item"><span class="drs-dot drs-dot-pending"><i class="fa-solid fa-clock"></i></span> Pending <span class="drs-legend-count"><?php echo $counts['pending']; ?></span></span>
        <span class="drs-legend-item"><span class="drs-dot drs-dot-overdue"><i class="fa-solid fa-xmark"></i></span> Overdue <span class="drs-legend-count"><?php echo $counts['overdue']; ?></span></span>
        <span class="drs-legend-item"><span class="drs-dot drs-dot-off"><i class="fa-solid fa-minus"></i></span> Not Required <span class="drs-legend-count"><?php echo $counts['not_required']; ?></span></span>
    </div>

    <div class="drs-table-wrap">
        <table class="drs-table">
            <thead>
                <tr>
                    <th class="drs-th-num">#</th>
                    <th class="drs-th-cat">Report / Category</th>
                    <?php foreach ($date_list as $d):
                        $dt = new DateTime($d);
                        $isToday = $d === $today_str;
                    ?>
                    <th class="drs-th-date <?php echo $isToday ? 'drs-th-today' : ''; ?>">
                        <span class="drs-th-day"><?php echo $dt->format('d'); ?></span>
                        <span class="drs-th-dow"><?php echo $dt->format('D'); ?></span>
                    </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php $n = 0; foreach ($CATEGORIES as $cat):
                    if ($filter_cat_key !== '' && $filter_cat_key !== $cat['key']) continue;
                    $n++;
                ?>
                <tr>
                    <td class="drs-td-num"><?php echo $n; ?></td>
                    <td class="drs-td-cat">
                        <span class="drs-cat-icon" style="background:<?php echo $cat['bg']; ?>;color:<?php echo $cat['color']; ?>;">
                            <i class="<?php echo $cat['icon']; ?>"></i>
                        </span>
                        <span class="drs-cat-text">
                            <strong><?php echo h($cat['title']); ?></strong>
                            <small><?php echo h($cat['sub']); ?></small>
                        </span>
                    </td>
                    <?php foreach ($date_list as $d):
                        $status = $matrix[$cat['key']][$d] ?? 'not_required';
                        $isToday = $d === $today_str;
                        $cls = [
                            'uploaded'     => 'drs-dot-ok',
                            'pending'      => 'drs-dot-pending',
                            'overdue'      => 'drs-dot-overdue',
                            'not_required' => 'drs-dot-off',
                        ][$status];
                        $ico = [
                            'uploaded'     => 'fa-check',
                            'pending'      => 'fa-clock',
                            'overdue'      => 'fa-xmark',
                            'not_required' => 'fa-minus',
                        ][$status];
                        $label = [
                            'uploaded'     => 'Uploaded',
                            'pending'      => 'Pending',
                            'overdue'      => 'Overdue',
                            'not_required' => 'Not Required',
                        ][$status];
                    ?>
                    <td class="drs-td-cell <?php echo $isToday ? 'drs-td-today' : ''; ?>">
                        <span class="drs-dot <?php echo $cls; ?>" title="<?php echo h($cat['title'] . ' — ' . date('d M Y', strtotime($d)) . ': ' . $label); ?>">
                            <i class="fa-solid <?php echo $ico; ?>"></i>
                        </span>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>

                <?php if ($n === 0): ?>
                <tr>
                    <td colspan="<?php echo 2 + count($date_list); ?>" class="drs-empty">
                        <i class="fa-solid fa-inbox"></i>
                        <p>No category matched the filter.</p>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.drs-card { background:#fff; border:1px solid #e5e5e5; border-radius:12px; padding:20px 22px; }

.drs-toolbar { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:14px; margin-bottom:14px; }
.drs-title-block { display:flex; flex-direction:column; }
.drs-title { font-size:18px; font-weight:700; color:#111827; }
.drs-range { font-size:13px; color:#6b7280; margin-top:2px; }
.drs-toolbar-right { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.drs-select { padding:9px 14px; border:1px solid #e5e5e5; border-radius:8px; font-size:13px; font-family:'Inter',sans-serif; background:#fff; color:#374151; cursor:pointer; }
.drs-select:focus { outline:none; border-color:#111827; }
.drs-select:disabled { background:#f5f5f5; color:#b0b3b9; cursor:not-allowed; }
.drs-range-sep { font-size:12px; color:#9ca3af; }
.drs-range-dash { font-size:12px; color:#9ca3af; }
.drs-date { padding:8px 10px; border:1px solid #e5e5e5; border-radius:8px; font-size:13px; font-family:'Inter',sans-serif; background:#fff; color:#374151; }
.drs-date:focus { outline:none; border-color:#111827; }
.drs-apply-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 16px; border:none; border-radius:8px; background:#111827; color:#fff; font-size:13px; font-weight:600; cursor:pointer; font-family:'Inter',sans-serif; }
.drs-apply-btn:hover { background:#000; }
.drs-clear-btn { display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:8px; border:1px solid #e5e5e5; color:#6b7280; text-decoration:none; }
.drs-clear-btn:hover { background:#fef2f2; color:#dc2626; border-color:#fecaca; }

.drs-legend { display:flex; gap:22px; flex-wrap:wrap; padding:12px 0 16px; border-bottom:1px solid #f0f0f0; margin-bottom:14px; }
.drs-legend-item { display:flex; align-items:center; gap:8px; font-size:13px; color:#374151; font-weight:500; }
.drs-legend-count { color:#9ca3af; font-weight:600; font-size:12px; }

.drs-dot { width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; color:#fff; font-size:11px; flex-shrink:0; }
.drs-dot-ok { background:#22c55e; }
.drs-dot-pending { background:#f59e0b; }
.drs-dot-overdue { background:#ef4444; }
.drs-dot-off { background:#d1d5db; color:#9ca3af; }

.drs-table-wrap { overflow-x:auto; }
.drs-table { width:100%; border-collapse:collapse; min-width:900px; }
.drs-table thead th { padding:10px 8px; text-align:center; font-size:12px; color:#6b7280; font-weight:600; border-bottom:1px solid #eee; white-space:nowrap; }
.drs-th-num { width:36px; }
.drs-th-cat { text-align:left !important; min-width:230px; }
.drs-th-date { min-width:56px; }
.drs-th-day { display:block; font-size:14px; font-weight:700; color:#1f2937; }
.drs-th-dow { display:block; font-size:11px; color:#9ca3af; text-transform:uppercase; }
.drs-th-today .drs-th-day, .drs-th-today .drs-th-dow { color:#2563eb; }

.drs-table tbody tr { border-bottom:1px solid #f5f5f5; }
.drs-table tbody tr:hover { background:#fafafa; }
.drs-td-num { text-align:center; color:#9ca3af; font-size:13px; padding:14px 8px; }
.drs-td-cat { display:flex; align-items:center; gap:12px; padding:14px 8px; }
.drs-cat-icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0; }
.drs-cat-text { display:flex; flex-direction:column; }
.drs-cat-text strong { font-size:13.5px; color:#111827; }
.drs-cat-text small { font-size:11.5px; color:#9ca3af; }
.drs-td-cell { text-align:center; padding:14px 8px; }
.drs-td-today { background:#eff6ff44; }

.drs-empty { text-align:center; padding:50px 20px !important; color:#9ca3af; }
.drs-empty i { font-size:38px; display:block; margin-bottom:10px; color:#e5e7eb; }

@media (max-width: 768px) {
    .drs-toolbar { flex-direction:column; }
    .drs-toolbar-right { width:100%; }
    .drs-select, .drs-date { flex:1; min-width:110px; }
    .drs-range-sep { display:none; }
}
</style>

<script>
function drsClearRange() {
    var f = document.getElementById('drsFrom');
    var t = document.getElementById('drsTo');
    if (f) f.value = '';
    if (t) t.value = '';
}
// If the user starts picking custom dates, stop the quick-select from
// silently overriding them on the next change event.
(function(){
    var daysSel = document.getElementById('drsDaysSelect');
    var from = document.getElementById('drsFrom');
    var to = document.getElementById('drsTo');
    [from, to].forEach(function(el){
        if (!el) return;
        el.addEventListener('change', function(){
            if (daysSel) daysSel.disabled = true;
        });
    });
})();
</script>

<?php include 'footer.php'; ?>