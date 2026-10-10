<?php
// ── Yelo Group HMS — Attendance Summary Report ───────────────────────────────

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/attendance_error.log');

if (file_exists(__DIR__ . '/config.php')) {
    include __DIR__ . '/config.php';
} else {
    $host = '127.0.0.1';
    $db   = 'u645685294_ylerp';
    $user = 'your_db_user';
    $pass = 'your_db_pass';
    $conn = mysqli_connect($host, $user, $pass, $db);
}

if (!$conn || mysqli_connect_errno()) {
    die('<h3 style="color:red;font-family:sans-serif;padding:20px;">Database connection failed: ' . htmlspecialchars(mysqli_connect_error()) . '</h3>');
}
mysqli_set_charset($conn, 'utf8mb4');

// ── FILTERS ──────────────────────────────────────────────────────────────────
$cur_year  = (int)date('Y');
$cur_month = (int)date('n');

$sel_year  = isset($_GET['year'])   ? intval($_GET['year'])  : $cur_year;
$sel_month = isset($_GET['month'])  ? intval($_GET['month']) : $cur_month;

$f_company  = isset($_GET['f_company'])  ? intval($_GET['f_company'])  : 0;
$f_branch   = isset($_GET['f_branch'])   ? intval($_GET['f_branch'])   : 0;
$f_desig    = isset($_GET['f_desig'])    ? intval($_GET['f_desig'])    : 0;
$f_staffcat = isset($_GET['f_staffcat']) ? intval($_GET['f_staffcat']) : 0;
$f_status   = isset($_GET['f_status'])   ? mysqli_real_escape_string($conn, $_GET['f_status'])  : '';
$f_search   = isset($_GET['f_search'])   ? mysqli_real_escape_string($conn, trim($_GET['f_search'])) : '';

if ($sel_year < 2000 || $sel_year > 2100) $sel_year = $cur_year;
if ($sel_month < 1 || $sel_month > 12)    $sel_month = $cur_month;

$from_date = sprintf('%04d-%02d-01', $sel_year, $sel_month);
$to_date   = date('Y-m-t', strtotime($from_date));

// ── MONTH CALENDAR DAYS ───────────────────────────────────────────────────────
$month_total_days = (int)date('t', strtotime($from_date));

// ── GET PAYROLL PERIOD (for banner info only — NOT used for norm_days) ────────
$period_row = null;
$pp_res = mysqli_query($conn,
    "SELECT * FROM payroll_periods WHERE year=$sel_year AND month=$sel_month LIMIT 1"
);
if ($pp_res && mysqli_num_rows($pp_res) > 0) {
    $period_row = mysqli_fetch_assoc($pp_res);
}

// ── GET ALL ACTIVE SPECIAL HOLIDAYS IN THIS MONTH ────────────────────────────
// We collect every holiday date + which SH row it belongs to
// so we can later resolve per-employee applicability.

$sh_res = mysqli_query($conn,
    "SELECT * FROM special_holidays
     WHERE active = 1
       AND date_from <= '$to_date'
       AND date_to   >= '$from_date'"
);
$sh_rows = [];
if ($sh_res) {
    while ($sh = mysqli_fetch_assoc($sh_res)) $sh_rows[] = $sh;
}

// Build date → [sh_row, ...] map (only dates within the selected month)
// A date can be covered by multiple overlapping SH entries; we deduplicate
// per employee (count each date only once even if multiple SHs cover it).
$sh_date_map = []; // date => [ sh_row, ... ]
foreach ($sh_rows as $sh) {
    $start = new DateTime(max($sh['date_from'], $from_date));
    $end   = new DateTime(min($sh['date_to'],   $to_date));
    $end->modify('+1 day');
    foreach (new DatePeriod($start, new DateInterval('P1D'), $end) as $dt) {
        $d = $dt->format('Y-m-d');
        if (!isset($sh_date_map[$d])) $sh_date_map[$d] = [];
        $sh_date_map[$d][] = $sh;
    }
}

// Helper: count distinct holiday dates applicable to a specific employee
// Returns array of applicable dates (as keys) for easy merging & counting.
function getApplicableHolidayDates(array $sh_date_map, $emp_id, $staff_category_id, $designation_id): array {
    $applicable_dates = [];
    foreach ($sh_date_map as $date => $shs) {
        foreach ($shs as $sh) {
            $applies = false;
            if ($sh['target_type'] === 'all') {
                $applies = true;
            } elseif (!empty($sh['target_ids'])) {
                $ids = json_decode($sh['target_ids'], true) ?? [];
                if ($sh['target_type'] === 'employee'       && in_array((int)$emp_id,             $ids)) $applies = true;
                if ($sh['target_type'] === 'staff_category' && in_array((int)$staff_category_id,  $ids)) $applies = true;
                if ($sh['target_type'] === 'designation'    && in_array((int)$designation_id,     $ids)) $applies = true;
            }
            if ($applies) {
                $applicable_dates[$date] = true; // deduplicate by date
                break; // no need to check other SHs for the same date
            }
        }
    }
    return $applicable_dates;
}

// ── BUILD EMPLOYEE WHERE CLAUSE ───────────────────────────────────────────────
$emp_where = ["e.active = 1"];
if ($f_company)  $emp_where[] = "e.company_id = $f_company";
if ($f_branch)   $emp_where[] = "e.branch_id = $f_branch";
if ($f_desig)    $emp_where[] = "e.designation_id = $f_desig";
if ($f_staffcat) $emp_where[] = "e.staff_category_id = $f_staffcat";
if ($f_status)   $emp_where[] = "e.status = '$f_status'";
if ($f_search)   $emp_where[] = "(e.employee_id LIKE '%$f_search%' OR e.employee_full_name LIKE '%$f_search%' OR e.name_with_initials LIKE '%$f_search%')";

$emp_where_sql = 'WHERE ' . implode(' AND ', $emp_where);

// ── FETCH EMPLOYEES ───────────────────────────────────────────────────────────
$emp_sql = "SELECT e.id, e.employee_id, e.employee_full_name, e.name_with_initials,
                   e.epf_number, e.status,
                   d.designation_name,
                   sc.category_name AS staff_category_name, sc.category_code,
                   c.company_name, c.company_code,
                   b.branch_name,
                   e.staff_category_id, e.designation_id, e.company_id, e.branch_id
            FROM employees e
            LEFT JOIN designations d ON e.designation_id = d.id
            LEFT JOIN staff_categories sc ON e.staff_category_id = sc.id
            LEFT JOIN companies c ON e.company_id = c.id
            LEFT JOIN branches b ON e.branch_id = b.id
            $emp_where_sql
            ORDER BY e.employee_id ASC";

$emp_res = mysqli_query($conn, $emp_sql);
if (!$emp_res) {
    die('<h3 style="color:red;padding:20px;">Employee query failed: ' . htmlspecialchars(mysqli_error($conn)) . '</h3>');
}
$employees = [];
while ($e = mysqli_fetch_assoc($emp_res)) $employees[] = $e;

// ── FETCH ALL ATTENDANCE FOR THIS MONTH (batch) ───────────────────────────────
$emp_ids_list = implode(',', array_map(fn($e) => intval($e['id']), $employees)) ?: '0';

$att_map = []; // [employee_id] => [date => 1]
$att_res = mysqli_query($conn,
    "SELECT employee_id, att_date FROM attendance
     WHERE employee_id IN ($emp_ids_list)
       AND att_date BETWEEN '$from_date' AND '$to_date'"
);
if ($att_res) {
    while ($a = mysqli_fetch_assoc($att_res)) {
        $att_map[$a['employee_id']][$a['att_date']] = 1;
    }
}

// ── FETCH ALL APPROVED LEAVES FOR THIS MONTH (batch) ─────────────────────────
$leave_map = [];
$leave_res = mysqli_query($conn,
    "SELECT employee_id, leave_type, days_count FROM leave_applications
     WHERE employee_id IN ($emp_ids_list)
       AND status = 'Approved'
       AND (
           (start_date BETWEEN '$from_date' AND '$to_date')
           OR (end_date BETWEEN '$from_date' AND '$to_date')
           OR (start_date <= '$from_date' AND end_date >= '$to_date')
       )"
);
if ($leave_res) {
    while ($lv = mysqli_fetch_assoc($leave_res)) {
        $eid = $lv['employee_id'];
        $lt  = $lv['leave_type'];
        if (!isset($leave_map[$eid])) {
            $leave_map[$eid] = ['Annual Leave'=>0,'Casual Leave'=>0,'Medical Leave'=>0,'Public Holiday Leave'=>0];
        }
        $leave_map[$eid][$lt] = ($leave_map[$eid][$lt] ?? 0) + $lv['days_count'];
    }
}

// ── COMPUTE PER-EMPLOYEE SUMMARY ──────────────────────────────────────────────
$month_names = ['January','February','March','April','May','June',
                'July','August','September','October','November','December'];

$summary = [];
$total_att      = 0;
$total_leave    = 0;
$total_nopay    = 0;
$total_earned   = 0;
$total_holidays = 0; // sum of per-employee holiday counts (for totals row)

foreach ($employees as $emp) {
    $eid       = $emp['id'];
    $att_dates = $att_map[$eid] ?? [];
    $att_count = count($att_dates);

    $lv_data    = $leave_map[$eid] ?? ['Annual Leave'=>0,'Casual Leave'=>0,'Medical Leave'=>0,'Public Holiday Leave'=>0];
    $lv_annual  = (int)($lv_data['Annual Leave']  ?? 0);
    $lv_casual  = (int)($lv_data['Casual Leave']  ?? 0);
    $lv_medical = (int)($lv_data['Medical Leave'] ?? 0);
    $lv_total   = $lv_annual + $lv_casual + $lv_medical;

    // ── Per-employee holiday count for this month ──
    $applicable_holiday_dates = getApplicableHolidayDates(
        $sh_date_map,
        $eid,
        $emp['staff_category_id'] ?? 0,
        $emp['designation_id']    ?? 0
    );
    $holiday_count = count($applicable_holiday_dates);

    // ── Norm Days = Month Calendar Days − Employee's Applicable Holidays ──
    $norm_days = $month_total_days - $holiday_count;

    // ── Leave Earned: worked on a day that is a holiday for this employee ──
    $leave_earned = 0;
    foreach ($att_dates as $att_date => $_) {
        if (isset($applicable_holiday_dates[$att_date])) {
            $leave_earned++;
        }
    }

    // ── No Pay Days = Norm Days − Attendance − Total Leave ──
    $no_pay = max(0, $norm_days - $att_count - $lv_total);

    $total_att      += $att_count;
    $total_leave    += $lv_total;
    $total_nopay    += $no_pay;
    $total_earned   += $leave_earned;
    $total_holidays += $holiday_count;

    $summary[] = [
        'id'            => $eid,
        'employee_id'   => $emp['employee_id'],
        'full_name'     => $emp['employee_full_name'],
        'initials'      => $emp['name_with_initials'] ?? '',
        'epf'           => $emp['epf_number'] ?? '—',
        'designation'   => $emp['designation_name'] ?? '—',
        'staff_cat'     => $emp['staff_category_name'] ?? '—',
        'cat_code'      => $emp['category_code'] ?? '',
        'company'       => ($emp['company_code'] ?? '') . ($emp['company_name'] ? ' '.$emp['company_name'] : ''),
        'branch'        => $emp['branch_name'] ?? '—',
        'status'        => $emp['status'],
        'month_days'    => $month_total_days,
        'holiday_count' => $holiday_count,
        'norm_days'     => $norm_days,
        'att_count'     => $att_count,
        'lv_annual'     => $lv_annual,
        'lv_casual'     => $lv_casual,
        'lv_medical'    => $lv_medical,
        'lv_total'      => $lv_total,
        'no_pay'        => $no_pay,
        'leave_earned'  => $leave_earned,
    ];
}

// ── FILTER DROPDOWNS DATA ─────────────────────────────────────────────────────
$companies_r = mysqli_query($conn, "SELECT id, company_code, company_name FROM companies WHERE active=1 ORDER BY company_name");
$branches_r  = mysqli_query($conn, "SELECT id, branch_name FROM branches ORDER BY branch_name");
$desigs_r    = mysqli_query($conn, "SELECT id, designation_name FROM designations ORDER BY designation_name");
$staffcats_r = mysqli_query($conn, "SELECT id, category_code, category_name FROM staff_categories WHERE active=1 ORDER BY category_name");

// ── COUNT ACTIVE FILTERS ──────────────────────────────────────────────────────
$active_filter_count = (int)($f_company > 0) + (int)($f_branch > 0) + (int)($f_desig > 0)
                     + (int)($f_staffcat > 0) + (int)(!empty($f_status)) + (int)(!empty($f_search));

if (file_exists(__DIR__ . '/header.php')) {
    include __DIR__ . '/header.php';
} else {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Attendance Summary Report</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    </head><body style="font-family:sans-serif;background:#f3f4f6;padding:20px;">';
}
?>

<style>
/* ══════════════════════════════════════════════════════
   ATTENDANCE SUMMARY REPORT — STYLES
══════════════════════════════════════════════════════ */
:root {
    --ink:          #0d1117;
    --ink-2:        #374151;
    --ink-3:        #6b7280;
    --border:       #e5e7eb;
    --border-2:     #f0f0f0;
    --surface:      #ffffff;
    --surface-2:    #f8fafc;
    --surface-3:    #f3f4f6;
    --blue:         #2563eb;
    --blue-light:   #eff6ff;
    --green:        #16a34a;
    --green-light:  #dcfce7;
    --amber:        #d97706;
    --amber-light:  #fef3c7;
    --red:          #dc2626;
    --red-light:    #fee2e2;
    --purple:       #7c3aed;
    --purple-light: #f3e8ff;
    --teal:         #0d9488;
    --teal-light:   #f0fdfa;
    --orange:       #ea580c;
    --orange-light: #fff7ed;
    --accent:       #1d4ed8;
    --radius:       10px;
    --radius-sm:    7px;
}

*, *::before, *::after { box-sizing: border-box; }

/* PAGE HEADER */
.rpt-page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 20px;
    padding-bottom: 18px;
    border-bottom: 2px solid var(--border);
}
.rpt-headline {
    font-size: 22px;
    font-weight: 800;
    color: var(--ink);
    margin: 0 0 3px;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -.3px;
}
.rpt-headline-icon {
    width: 38px; height: 38px;
    background: var(--blue);
    color: #fff;
    border-radius: 9px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
    flex-shrink: 0;
}
.rpt-subtitle {
    font-size: 13px;
    color: var(--ink-3);
    margin: 0;
    padding-left: 2px;
}
.rpt-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

/* PERIOD SELECTOR CARD */
.period-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 20px;
    margin-bottom: 14px;
    display: flex;
    gap: 16px;
    align-items: flex-end;
    flex-wrap: wrap;
}
.period-field { display: flex; flex-direction: column; gap: 5px; }
.period-label {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: var(--ink-3);
}
.period-select {
    height: 40px;
    padding: 0 12px;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-family: inherit;
    color: var(--ink);
    background: var(--surface);
    cursor: pointer;
    min-width: 120px;
    appearance: auto;
}
.period-select:focus {
    outline: none;
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}

/* PAYROLL PERIOD BANNER */
.period-banner {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 18px;
    border-radius: var(--radius);
    margin-bottom: 14px;
    font-size: 13px;
    flex-wrap: wrap;
}
.period-banner.has-period {
    background: var(--teal-light);
    border: 1px solid #99f6e4;
    color: #0f766e;
}
.period-banner.no-period {
    background: var(--surface-3);
    border: 1px solid var(--border);
    color: var(--ink-3);
}
.period-banner i { font-size: 18px; flex-shrink: 0; }
.period-banner strong { font-size: 14px; }
.pb-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    background: rgba(255,255,255,.6);
    border: 1px solid rgba(0,0,0,.08);
}

/* FILTER SECTION */
.filter-bar {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 14px;
    overflow: hidden;
}
.filter-bar-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 18px;
    cursor: pointer;
    user-select: none;
    border-bottom: 1px solid transparent;
    transition: border-color .2s;
}
.filter-bar-header.expanded { border-color: var(--border-2); }
.filter-toggle-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 700;
    color: var(--ink-2);
    background: none;
    border: none;
    cursor: pointer;
    font-family: inherit;
}
.filter-toggle-btn i { color: var(--ink-3); font-size: 12px; }
.filter-badge-count {
    background: var(--blue);
    color: #fff;
    border-radius: 20px;
    padding: 1px 8px;
    font-size: 10px;
    font-weight: 800;
}
.filter-body { padding: 16px 18px 18px; display: none; }
.filter-body.open { display: block; }
.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 16px;
}
.filter-group { display: flex; flex-direction: column; gap: 5px; }
.filter-label-sm {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: var(--ink-3);
}
.filter-input {
    height: 38px;
    padding: 0 10px;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-family: inherit;
    color: var(--ink);
    background: var(--surface);
    width: 100%;
}
.filter-input:focus {
    outline: none;
    border-color: var(--blue);
    box-shadow: 0 0 0 2px rgba(37,99,235,.1);
}
.filter-search-wrap { position: relative; }
.filter-search-icon {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--ink-3);
    font-size: 12px;
    pointer-events: none;
}
.filter-search-input {
    padding-left: 30px;
    height: 38px;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-family: inherit;
    color: var(--ink);
    background: var(--surface);
    width: 100%;
}
.filter-search-input:focus {
    outline: none;
    border-color: var(--blue);
    box-shadow: 0 0 0 2px rgba(37,99,235,.1);
}
.filter-actions-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

/* SUMMARY STAT CARDS */
.stat-strip {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}
.stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 14px 16px;
    position: relative;
    overflow: hidden;
}
.stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
}
.stat-card.sc-total::before   { background: var(--blue); }
.stat-card.sc-mdays::before   { background: #64748b; }
.stat-card.sc-hols::before    { background: var(--orange); }
.stat-card.sc-norm::before    { background: var(--teal); }
.stat-card.sc-att::before     { background: var(--green); }
.stat-card.sc-leave::before   { background: var(--amber); }
.stat-card.sc-nopay::before   { background: var(--red); }
.stat-card.sc-earned::before  { background: var(--purple); }

.stat-number {
    font-size: 28px;
    font-weight: 900;
    line-height: 1;
    margin-bottom: 4px;
    letter-spacing: -1px;
}
.stat-card.sc-total  .stat-number { color: var(--blue); }
.stat-card.sc-mdays  .stat-number { color: #64748b; }
.stat-card.sc-hols   .stat-number { color: var(--orange); }
.stat-card.sc-norm   .stat-number { color: var(--teal); }
.stat-card.sc-att    .stat-number { color: var(--green); }
.stat-card.sc-leave  .stat-number { color: var(--amber); }
.stat-card.sc-nopay  .stat-number { color: var(--red); }
.stat-card.sc-earned .stat-number { color: var(--purple); }

.stat-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--ink-3);
    text-transform: uppercase;
    letter-spacing: .4px;
}
.stat-icon {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 28px;
    opacity: .07;
}

/* MAIN TABLE CARD */
.report-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    box-shadow: 0 1px 6px rgba(0,0,0,.05);
}
.report-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    border-bottom: 2px solid var(--border-2);
    background: var(--surface-2);
    flex-wrap: wrap;
    gap: 10px;
}
.rch-left { display: flex; align-items: center; gap: 10px; }
.rch-icon {
    width: 34px; height: 34px;
    background: var(--blue);
    color: #fff;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px;
}
.rch-title { font-size: 14px; font-weight: 800; color: var(--ink); margin: 0; }
.rch-sub   { font-size: 11px; color: var(--ink-3); margin: 1px 0 0; }
.rch-count {
    background: var(--surface-3);
    border: 1px solid var(--border);
    color: var(--ink-3);
    padding: 3px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}

/* TABLE */
.tbl-wrap { overflow-x: auto; }
.att-summary-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    min-width: 1180px;
}
.att-summary-table thead {
    position: sticky;
    top: 0;
    z-index: 2;
}
.att-summary-table thead tr:first-child th {
    background: var(--ink);
    color: #fff;
    padding: 10px 12px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    white-space: nowrap;
    border-right: 1px solid rgba(255,255,255,.06);
}
.att-summary-table thead tr:first-child th:last-child { border-right: none; }

/* Column group headers */
.tbl-grp-identity { background: #1f2937 !important; }
.tbl-grp-mdays    { background: #475569 !important; }
.tbl-grp-holidays { background: #c2410c !important; }
.tbl-grp-norm     { background: #0f766e !important; }
.tbl-grp-att      { background: #15803d !important; }
.tbl-grp-leave    { background: #b45309 !important; }
.tbl-grp-nopay    { background: #b91c1c !important; }
.tbl-grp-earned   { background: #6d28d9 !important; }

.att-summary-table tbody tr {
    border-bottom: 1px solid var(--border-2);
    transition: background .12s;
}
.att-summary-table tbody tr:hover { background: #f0f6ff; }
.att-summary-table tbody tr:last-child { border-bottom: none; }
.att-summary-table td {
    padding: 9px 12px;
    vertical-align: middle;
    color: var(--ink);
}

/* Column separators */
.col-sep { border-left: 2px solid var(--border) !important; }

/* Footer totals row */
.tbl-totals td {
    background: var(--ink) !important;
    color: #fff !important;
    font-weight: 800;
    font-size: 12px;
    padding: 10px 12px;
    border-bottom: none !important;
}
.tbl-totals .totals-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .4px;
    opacity: .7;
}

/* Cell badges */
.emp-id-chip {
    font-size: 11px;
    font-weight: 800;
    color: var(--blue);
    font-family: 'SFMono-Regular', Consolas, monospace;
    letter-spacing: .3px;
}
.emp-name-main { font-weight: 700; color: var(--ink); font-size: 12px; line-height: 1.3; }
.emp-name-sub  { font-size: 10px; color: var(--ink-3); }
.epf-chip {
    font-size: 10px;
    font-weight: 700;
    color: #15803d;
    background: #dcfce7;
    padding: 1px 7px;
    border-radius: 4px;
    font-family: monospace;
}
.epf-missing { color: var(--ink-3); font-size: 11px; font-style: italic; }

.desig-chip {
    display: inline-block;
    padding: 2px 8px;
    background: var(--blue-light);
    color: var(--accent);
    border-radius: 5px;
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
}
.cat-chip {
    display: inline-block;
    padding: 2px 8px;
    background: var(--purple-light);
    color: var(--purple);
    border-radius: 5px;
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
}

.num-cell {
    text-align: center;
    font-weight: 800;
    font-size: 14px;
}
.num-zero  { color: #d1d5db; font-weight: 400; font-size: 12px; }

.mdays-num   { color: #475569; }
.hols-num    { color: var(--orange); }
.hols-zero   { color: #d1d5db !important; font-weight: 400; font-size: 12px; }
.att-num     { color: var(--green); }
.leave-num   { color: var(--amber); }
.nopay-num   { color: var(--red); }
.earned-num  { color: var(--purple); }
.norm-num    { color: var(--teal); }

/* Holiday count tooltip chip */
.hol-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: var(--orange-light);
    color: var(--orange);
    border: 1px solid #fed7aa;
    padding: 2px 8px;
    border-radius: 5px;
    font-size: 11px;
    font-weight: 800;
    cursor: help;
}
.hol-chip i { font-size: 9px; }

.status-chip {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
}
.status-Probation  { background: #fef3c7; color: #92400e; }
.status-Permanent  { background: #dcfce7; color: #166634; }
.status-Resigned   { background: #f3f4f6; color: #6b7280; }
.status-Terminated { background: #fee2e2; color: #991b1b; }

.no-pay-highlight { background: #fff5f5 !important; }
.no-pay-highlight:hover { background: #fee2e2 !important; }

/* BUTTONS */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 16px;
    border: none;
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all .18s;
    text-decoration: none;
    font-family: inherit;
    white-space: nowrap;
}
.btn-primary { background: var(--blue);  color: #fff; } .btn-primary:hover { background: #1d4ed8; }
.btn-success { background: var(--green); color: #fff; } .btn-success:hover { background: #15803d; }
.btn-light   { background: var(--surface-3); color: var(--ink-2); border: 1px solid var(--border); } .btn-light:hover { background: var(--border); }
.btn-print   { background: var(--ink);   color: #fff; } .btn-print:hover  { background: var(--ink-2); }
.btn-sm { padding: 6px 12px; font-size: 11px; }

/* EMPTY STATE */
.empty-rpt { text-align: center; padding: 60px 20px; color: var(--ink-3); }
.empty-rpt i { font-size: 48px; color: var(--border); margin-bottom: 14px; display: block; }
.empty-rpt h3 { font-size: 16px; font-weight: 700; color: var(--ink-2); margin-bottom: 6px; }
.empty-rpt p  { font-size: 13px; max-width: 360px; margin: 0 auto; }

/* PRINT */
@media print {
    body { background: #fff !important; }
    .no-print { display: none !important; }
    .print-only-header { display: block !important; }
    .report-card { box-shadow: none; border: 1px solid #ccc; }
    .att-summary-table { font-size: 10px; }
    .att-summary-table td, .att-summary-table th { padding: 5px 8px; }
    .rpt-page-header { border-bottom: 2px solid #000; }
    @page { size: A3 landscape; margin: 15mm; }
}

/* RESPONSIVE */
@media (max-width: 768px) {
    .stat-strip { grid-template-columns: repeat(2, 1fr); }
    .period-card { flex-direction: column; }
    .filter-grid { grid-template-columns: 1fr 1fr; }
    .rpt-page-header { flex-direction: column; }
    .rpt-actions { width: 100%; }
    .report-card-header { flex-direction: column; }
}
@media (max-width: 480px) {
    .stat-strip { grid-template-columns: 1fr; }
    .filter-grid { grid-template-columns: 1fr; }
}
</style>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE HEADER
══════════════════════════════════════════════════════════════════ -->
<div class="rpt-page-header no-print">
    <div class="rpt-title-block">
        <h2 class="rpt-headline">
            <span class="rpt-headline-icon"><i class="fa-solid fa-chart-bar"></i></span>
            Attendance Summary Report
        </h2>
        <p class="rpt-subtitle">
            <?php echo $month_names[$sel_month - 1] . ' ' . $sel_year; ?> &nbsp;·&nbsp;
            <?php echo count($summary); ?> employee<?php echo count($summary) != 1 ? 's' : ''; ?> found
            &nbsp;·&nbsp; <?php echo $month_total_days; ?> calendar days
        </p>
    </div>
    <div class="rpt-actions">
        <a href="attendance_report.php" class="btn btn-light">
            <i class="fa-solid fa-arrow-left"></i> Back
        </a>
        <button onclick="window.print()" class="btn btn-print">
            <i class="fa-solid fa-print"></i> Print / PDF
        </button>
        <button onclick="exportCSV()" class="btn btn-success">
            <i class="fa-solid fa-file-csv"></i> Export CSV
        </button>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     PERIOD SELECTOR
══════════════════════════════════════════════════════════════════ -->
<div class="no-print">
<form method="GET" id="reportForm">
    <div class="period-card">
        <div class="period-field">
            <label class="period-label">Month</label>
            <select name="month" id="selMonth" class="period-select" onchange="autoSubmit()">
                <?php
                $mns = ['January','February','March','April','May','June',
                        'July','August','September','October','November','December'];
                foreach ($mns as $mi => $mn):
                    $sel = ($mi + 1 === $sel_month) ? 'selected' : '';
                ?>
                <option value="<?php echo $mi + 1; ?>" <?php echo $sel; ?>><?php echo $mn; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="period-field">
            <label class="period-label">Year</label>
            <select name="year" id="selYear" class="period-select" onchange="autoSubmit()">
                <?php for ($y = $cur_year - 3; $y <= $cur_year + 2; $y++): ?>
                <option value="<?php echo $y; ?>" <?php echo $y === $sel_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <!-- preserve filters on period change -->
        <input type="hidden" name="f_company"  value="<?php echo $f_company; ?>">
        <input type="hidden" name="f_branch"   value="<?php echo $f_branch; ?>">
        <input type="hidden" name="f_desig"    value="<?php echo $f_desig; ?>">
        <input type="hidden" name="f_staffcat" value="<?php echo $f_staffcat; ?>">
        <input type="hidden" name="f_status"   value="<?php echo htmlspecialchars($f_status); ?>">
        <input type="hidden" name="f_search"   value="<?php echo htmlspecialchars($f_search); ?>">
        <div class="period-field" style="margin-left:auto;justify-content:flex-end;padding-bottom:2px;">
            <span style="font-size:11px;font-weight:700;color:var(--ink-3);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;">Month Days</span>
            <span style="font-size:22px;font-weight:900;color:#475569;letter-spacing:-1px;"><?php echo $month_total_days; ?></span>
        </div>
    </div>
</form>

<!-- ═══════════════════════════════════════════════════════════════
     PAYROLL PERIOD BANNER
══════════════════════════════════════════════════════════════════ -->
<?php if ($period_row): ?>
<div class="period-banner has-period">
    <i class="fa-solid fa-circle-check"></i>
    <div style="flex:1;">
        <strong>Payroll Period: <?php echo $month_names[$sel_month-1].' '.$sel_year; ?></strong>
        &nbsp;—&nbsp;
        <?php echo date('d M Y', strtotime($period_row['open_date'])); ?>
        &nbsp;→&nbsp;
        <?php echo date('d M Y', strtotime($period_row['close_date'])); ?>
    </div>
    <span class="pb-pill" style="<?php echo $period_row['status']==='Locked'?'background:#f3f4f6;color:#374151;':''; ?>">
        <?php echo $period_row['status'] === 'Locked' ? '🔒 Locked' : '🔓 Open'; ?>
    </span>
</div>
<?php else: ?>
<div class="period-banner no-period">
    <i class="fa-solid fa-calendar-xmark"></i>
    <div>
        <strong>No Payroll Period found for <?php echo $month_names[$sel_month-1].' '.$sel_year; ?></strong>
        <a href="payroll_months.php" style="color:var(--blue);font-weight:700;margin-left:8px;">Create Period →</a>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     FILTER BAR
══════════════════════════════════════════════════════════════════ -->
<div class="filter-bar" id="filterBar">
    <div class="filter-bar-header <?php echo $active_filter_count ? 'expanded' : ''; ?>" onclick="toggleFilters()">
        <button class="filter-toggle-btn" type="button">
            <i class="fa-solid fa-sliders"></i>
            Filters
            <?php if ($active_filter_count): ?>
            <span class="filter-badge-count"><?php echo $active_filter_count; ?></span>
            <?php endif; ?>
        </button>
        <i class="fa-solid fa-chevron-down" id="filterChevron"
           style="color:var(--ink-3);font-size:12px;transition:transform .2s;<?php echo $active_filter_count ? 'transform:rotate(180deg)' : ''; ?>"></i>
    </div>
    <div class="filter-body <?php echo $active_filter_count ? 'open' : ''; ?>" id="filterBody">
        <form method="GET">
            <input type="hidden" name="year"  value="<?php echo $sel_year; ?>">
            <input type="hidden" name="month" value="<?php echo $sel_month; ?>">
            <div class="filter-grid">
                <div class="filter-group">
                    <label class="filter-label-sm">Search</label>
                    <div class="filter-search-wrap">
                        <i class="fa-solid fa-magnifying-glass filter-search-icon"></i>
                        <input type="text" name="f_search" class="filter-search-input"
                               placeholder="Name, Employee ID…"
                               value="<?php echo htmlspecialchars($f_search); ?>">
                    </div>
                </div>
                <div class="filter-group">
                    <label class="filter-label-sm">Company</label>
                    <select name="f_company" class="filter-input">
                        <option value="">All Companies</option>
                        <?php if ($companies_r) while ($c = mysqli_fetch_assoc($companies_r)): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $f_company == $c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['company_code'].' — '.$c['company_name']); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label-sm">Branch</label>
                    <select name="f_branch" class="filter-input">
                        <option value="">All Branches</option>
                        <?php if ($branches_r) while ($br = mysqli_fetch_assoc($branches_r)): ?>
                        <option value="<?php echo $br['id']; ?>" <?php echo $f_branch == $br['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($br['branch_name']); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label-sm">Designation</label>
                    <select name="f_desig" class="filter-input">
                        <option value="">All Designations</option>
                        <?php if ($desigs_r) while ($dg = mysqli_fetch_assoc($desigs_r)): ?>
                        <option value="<?php echo $dg['id']; ?>" <?php echo $f_desig == $dg['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dg['designation_name']); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label-sm">Staff Category</label>
                    <select name="f_staffcat" class="filter-input">
                        <option value="">All Categories</option>
                        <?php if ($staffcats_r) while ($sc = mysqli_fetch_assoc($staffcats_r)): ?>
                        <option value="<?php echo $sc['id']; ?>" <?php echo $f_staffcat == $sc['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(($sc['category_code'] ? $sc['category_code'].' — ' : '').$sc['category_name']); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label-sm">Status</label>
                    <select name="f_status" class="filter-input">
                        <option value="">All Statuses</option>
                        <option value="Probation"  <?php echo $f_status==='Probation' ?'selected':''; ?>>Probation</option>
                        <option value="Permanent"  <?php echo $f_status==='Permanent' ?'selected':''; ?>>Permanent</option>
                        <option value="Resigned"   <?php echo $f_status==='Resigned'  ?'selected':''; ?>>Resigned</option>
                        <option value="Terminated" <?php echo $f_status==='Terminated'?'selected':''; ?>>Terminated</option>
                    </select>
                </div>
            </div>
            <div class="filter-actions-row">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Apply Filters</button>
                <a href="?year=<?php echo $sel_year; ?>&month=<?php echo $sel_month; ?>" class="btn btn-light"><i class="fa-solid fa-xmark"></i> Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     SUMMARY STAT CARDS
══════════════════════════════════════════════════════════════════ -->
<?php
// For stat cards: average norm days (may differ per employee, show range or avg)
$norm_days_values = array_column($summary, 'norm_days');
$norm_min  = !empty($norm_days_values) ? min($norm_days_values) : 0;
$norm_max  = !empty($norm_days_values) ? max($norm_days_values) : 0;
$norm_display = ($norm_min === $norm_max) ? $norm_max : $norm_min . '–' . $norm_max;
?>
<div class="stat-strip">
    <div class="stat-card sc-total">
        <div class="stat-number"><?php echo count($summary); ?></div>
        <div class="stat-label">Employees</div>
        <i class="fa-solid fa-users stat-icon"></i>
    </div>
    <div class="stat-card sc-mdays">
        <div class="stat-number"><?php echo $month_total_days; ?></div>
        <div class="stat-label">Month Days</div>
        <i class="fa-solid fa-calendar stat-icon"></i>
    </div>
    <div class="stat-card sc-hols">
        <div class="stat-number"><?php echo !empty($summary) ? $norm_min === $norm_max ? ($month_total_days - $norm_max) : '~' : '0'; ?></div>
        <div class="stat-label">Holidays This Month</div>
        <i class="fa-solid fa-umbrella-beach stat-icon"></i>
    </div>
    <div class="stat-card sc-norm">
        <div class="stat-number" style="font-size:<?php echo strlen((string)$norm_display) > 4 ? '20px' : '28px'; ?>;">
            <?php echo $norm_display ?: '—'; ?>
        </div>
        <div class="stat-label">Norm Working Days</div>
        <i class="fa-solid fa-calendar-check stat-icon"></i>
    </div>
    <div class="stat-card sc-att">
        <div class="stat-number"><?php echo $total_att; ?></div>
        <div class="stat-label">Total Attendance</div>
        <i class="fa-solid fa-calendar-day stat-icon"></i>
    </div>
    <div class="stat-card sc-leave">
        <div class="stat-number"><?php echo $total_leave; ?></div>
        <div class="stat-label">Total Leave Days</div>
        <i class="fa-solid fa-umbrella-beach stat-icon"></i>
    </div>
    <div class="stat-card sc-nopay">
        <div class="stat-number"><?php echo $total_nopay; ?></div>
        <div class="stat-label">Total No Pay Days</div>
        <i class="fa-solid fa-ban stat-icon"></i>
    </div>
    <div class="stat-card sc-earned">
        <div class="stat-number"><?php echo $total_earned; ?></div>
        <div class="stat-label">Leave Earned</div>
        <i class="fa-solid fa-star stat-icon"></i>
    </div>
</div>

</div><!-- /.no-print -->

<!-- ═══════════════════════════════════════════════════════════════
     PRINT HEADER
══════════════════════════════════════════════════════════════════ -->
<div style="display:none;" class="print-only-header" id="printHeader">
    <div style="text-align:center;margin-bottom:14px;padding-bottom:12px;border-bottom:2px solid #000;">
        <div style="font-size:20px;font-weight:900;letter-spacing:-.5px;">Yelo Group HMS</div>
        <div style="font-size:15px;font-weight:700;margin:3px 0;">Attendance Summary Report</div>
        <div style="font-size:12px;color:#555;">
            <?php echo $month_names[$sel_month-1].' '.$sel_year; ?>
            &nbsp;·&nbsp; Month Days: <?php echo $month_total_days; ?>
            &nbsp;·&nbsp; Generated: <?php echo date('d M Y H:i'); ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     REPORT TABLE
══════════════════════════════════════════════════════════════════ -->
<div class="report-card">
    <div class="report-card-header no-print">
        <div class="rch-left">
            <div class="rch-icon"><i class="fa-solid fa-table"></i></div>
            <div>
                <div class="rch-title">
                    Attendance Summary — <?php echo $month_names[$sel_month-1].' '.$sel_year; ?>
                </div>
                <div class="rch-sub">
                    <?php echo date('d M Y', strtotime($from_date)); ?> –
                    <?php echo date('d M Y', strtotime($to_date)); ?>
                    &nbsp;·&nbsp; <?php echo $month_total_days; ?> calendar days
                    &nbsp;·&nbsp; Norm Days = Month Days − Holidays
                </div>
            </div>
        </div>
        <span class="rch-count"><?php echo count($summary); ?> record<?php echo count($summary) != 1 ? 's' : ''; ?></span>
    </div>

    <div class="tbl-wrap">
        <?php if (empty($summary)): ?>
        <div class="empty-rpt">
            <i class="fa-solid fa-user-slash"></i>
            <h3>No Employees Found</h3>
            <p>No employees match the current filters. Adjust your filters and try again.</p>
        </div>
        <?php else: ?>
        <table class="att-summary-table" id="summaryTable">
            <thead>
                <tr>
                    <!-- Identity -->
                    <th class="tbl-grp-identity" style="width:32px;">#</th>
                    <th class="tbl-grp-identity">Employee</th>
                    <th class="tbl-grp-identity">EPF No.</th>
                    <th class="tbl-grp-identity">Designation</th>
                    <th class="tbl-grp-identity">Staff Category</th>
                    <!-- Month Days -->
                    <th class="tbl-grp-mdays col-sep" style="text-align:center;">Month<br>Days</th>
                    <!-- Holidays -->
                    <th class="tbl-grp-holidays col-sep" style="text-align:center;">Holidays<br>(Applied)</th>
                    <!-- Norm Working Days -->
                    <th class="tbl-grp-norm col-sep" style="text-align:center;">Norm<br>Days</th>
                    <!-- Attendance -->
                    <th class="tbl-grp-att col-sep" style="text-align:center;">Att.<br>Days</th>
                    <!-- Leave -->
                    <th class="tbl-grp-leave col-sep" style="text-align:center;">Annual<br>Leave</th>
                    <th class="tbl-grp-leave"          style="text-align:center;">Casual<br>Leave</th>
                    <th class="tbl-grp-leave"          style="text-align:center;">Medical<br>Leave</th>
                    <th class="tbl-grp-leave"          style="text-align:center;font-weight:900;">Total<br>Leave</th>
                    <!-- No Pay -->
                    <th class="tbl-grp-nopay col-sep"  style="text-align:center;">No Pay<br>Days</th>
                    <!-- Leave Earned -->
                    <th class="tbl-grp-earned col-sep" style="text-align:center;">Leave<br>Earned</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($summary as $i => $s):
                $has_nopay = $s['no_pay'] > 0;
            ?>
            <tr <?php echo $has_nopay ? 'class="no-pay-highlight"' : ''; ?>>
                <!-- # -->
                <td style="color:var(--ink-3);font-size:11px;text-align:center;"><?php echo $i + 1; ?></td>

                <!-- Employee -->
                <td>
                    <div class="emp-id-chip"><?php echo htmlspecialchars($s['employee_id']); ?></div>
                    <div class="emp-name-main"><?php echo htmlspecialchars($s['full_name']); ?></div>
                    <?php if ($s['initials'] && $s['initials'] !== $s['full_name']): ?>
                    <div class="emp-name-sub"><?php echo htmlspecialchars($s['initials']); ?></div>
                    <?php endif; ?>
                    <span class="status-chip status-<?php echo htmlspecialchars($s['status']); ?>"><?php echo $s['status']; ?></span>
                </td>

                <!-- EPF -->
                <td>
                    <?php if ($s['epf'] && $s['epf'] !== '—'): ?>
                    <span class="epf-chip"><?php echo htmlspecialchars($s['epf']); ?></span>
                    <?php else: ?>
                    <span class="epf-missing">—</span>
                    <?php endif; ?>
                </td>

                <!-- Designation -->
                <td>
                    <?php if ($s['designation'] !== '—'): ?>
                    <span class="desig-chip"><?php echo htmlspecialchars($s['designation']); ?></span>
                    <?php else: ?>
                    <span style="color:var(--border);">—</span>
                    <?php endif; ?>
                </td>

                <!-- Staff Category -->
                <td>
                    <?php if ($s['staff_cat'] !== '—'): ?>
                    <span class="cat-chip">
                        <?php echo htmlspecialchars(($s['cat_code'] ? $s['cat_code'].' · ' : '').$s['staff_cat']); ?>
                    </span>
                    <?php else: ?>
                    <span style="color:var(--border);">—</span>
                    <?php endif; ?>
                </td>

                <!-- Month Days -->
                <td class="num-cell col-sep mdays-num">
                    <?php echo $s['month_days']; ?>
                </td>

                <!-- Holidays Applied -->
                <td class="num-cell col-sep">
                    <?php if ($s['holiday_count'] > 0): ?>
                    <span class="hol-chip" title="<?php echo $s['holiday_count']; ?> special holiday day(s) applied to this employee">
                        <i class="fa-solid fa-umbrella-beach"></i>
                        <?php echo $s['holiday_count']; ?>
                    </span>
                    <?php else: ?>
                    <span class="hols-zero">—</span>
                    <?php endif; ?>
                </td>

                <!-- Norm Working Days (calculated) -->
                <td class="num-cell col-sep norm-num" style="font-size:16px;">
                    <?php echo $s['norm_days']; ?>
                </td>

                <!-- Attendance -->
                <td class="num-cell col-sep <?php echo $s['att_count'] > 0 ? 'att-num' : 'num-zero'; ?>">
                    <?php echo $s['att_count']; ?>
                </td>

                <!-- Annual Leave -->
                <td class="num-cell col-sep <?php echo $s['lv_annual'] > 0 ? 'leave-num' : 'num-zero'; ?>">
                    <?php echo $s['lv_annual'] > 0 ? $s['lv_annual'] : '—'; ?>
                </td>

                <!-- Casual Leave -->
                <td class="num-cell <?php echo $s['lv_casual'] > 0 ? 'leave-num' : 'num-zero'; ?>">
                    <?php echo $s['lv_casual'] > 0 ? $s['lv_casual'] : '—'; ?>
                </td>

                <!-- Medical Leave -->
                <td class="num-cell <?php echo $s['lv_medical'] > 0 ? 'leave-num' : 'num-zero'; ?>">
                    <?php echo $s['lv_medical'] > 0 ? $s['lv_medical'] : '—'; ?>
                </td>

                <!-- Total Leave -->
                <td class="num-cell" style="font-weight:900;<?php echo $s['lv_total'] > 0 ? 'color:var(--amber);font-size:15px;' : 'color:var(--border);'; ?>">
                    <?php echo $s['lv_total'] > 0 ? $s['lv_total'] : '0'; ?>
                </td>

                <!-- No Pay Days -->
                <td class="num-cell col-sep">
                    <?php if ($s['no_pay'] === 0): ?>
                    <span style="color:var(--green);font-size:16px;" title="No unpaid days">✓</span>
                    <?php else: ?>
                    <span style="color:var(--red);font-weight:900;font-size:15px;"><?php echo $s['no_pay']; ?></span>
                    <?php endif; ?>
                </td>

                <!-- Leave Earned -->
                <td class="num-cell col-sep <?php echo $s['leave_earned'] > 0 ? 'earned-num' : 'num-zero'; ?>">
                    <?php if ($s['leave_earned'] > 0): ?>
                    <span title="Worked on <?php echo $s['leave_earned']; ?> special holiday(s)">
                        <?php echo $s['leave_earned']; ?> <i class="fa-solid fa-star" style="font-size:9px;"></i>
                    </span>
                    <?php else: ?>
                    —
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>

            <!-- Totals Row -->
            <tfoot>
                <tr class="tbl-totals">
                    <td colspan="5" class="totals-label" style="text-align:right;padding-right:16px;">
                        <i class="fa-solid fa-sigma" style="margin-right:6px;"></i>
                        TOTALS (<?php echo count($summary); ?> employees)
                    </td>
                    <!-- Month Days -->
                    <td class="num-cell col-sep" style="color:#94a3b8;">
                        <?php echo $month_total_days; ?>
                    </td>
                    <!-- Holidays (show range or single value) -->
                    <td class="num-cell col-sep" style="color:#fb923c;">
                        <?php echo $norm_min === $norm_max
                            ? ($month_total_days - $norm_max)
                            : ($month_total_days - $norm_max).'–'.($month_total_days - $norm_min); ?>
                    </td>
                    <!-- Norm Days -->
                    <td class="num-cell col-sep" style="color:#5eead4;">
                        <?php echo $norm_display ?: '—'; ?>
                    </td>
                    <!-- Attendance -->
                    <td class="num-cell col-sep" style="color:#86efac;">
                        <?php echo $total_att; ?>
                    </td>
                    <!-- Annual Leave -->
                    <td class="num-cell col-sep" style="color:#fcd34d;">
                        <?php echo array_sum(array_column($summary, 'lv_annual')); ?>
                    </td>
                    <!-- Casual Leave -->
                    <td class="num-cell" style="color:#fcd34d;">
                        <?php echo array_sum(array_column($summary, 'lv_casual')); ?>
                    </td>
                    <!-- Medical Leave -->
                    <td class="num-cell" style="color:#fcd34d;">
                        <?php echo array_sum(array_column($summary, 'lv_medical')); ?>
                    </td>
                    <!-- Total Leave -->
                    <td class="num-cell" style="color:#fbbf24;font-weight:900;font-size:15px;">
                        <?php echo $total_leave; ?>
                    </td>
                    <!-- No Pay -->
                    <td class="num-cell col-sep" style="color:#fca5a5;">
                        <?php echo $total_nopay; ?>
                    </td>
                    <!-- Leave Earned -->
                    <td class="num-cell col-sep" style="color:#c4b5fd;">
                        <?php echo $total_earned; ?>
                    </td>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($summary)): ?>
<!-- FORMULA LEGEND -->
<div class="no-print" style="margin-top:14px;background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;font-size:12px;color:var(--ink-3);display:flex;gap:20px;flex-wrap:wrap;align-items:center;">
    <div style="display:flex;align-items:center;gap:6px;font-weight:700;color:var(--ink-2);">
        <i class="fa-solid fa-circle-info" style="color:var(--blue);"></i> Formula
    </div>
    <!-- Norm Days formula -->
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span style="background:var(--teal-light);color:var(--teal);padding:3px 10px;border-radius:5px;font-weight:800;">Norm Days</span>
        <span>=</span>
        <span style="background:#f1f5f9;color:#475569;padding:3px 10px;border-radius:5px;font-weight:700;">Month Days</span>
        <span>−</span>
        <span style="background:var(--orange-light);color:var(--orange);padding:3px 10px;border-radius:5px;font-weight:700;">Holidays (Applied to Employee)</span>
    </div>
    <div style="color:#d1d5db;">|</div>
    <!-- No Pay formula -->
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span style="background:var(--red-light);color:var(--red);padding:3px 10px;border-radius:5px;font-weight:800;">No Pay Days</span>
        <span>=</span>
        <span style="background:var(--teal-light);color:var(--teal);padding:3px 10px;border-radius:5px;font-weight:700;">Norm Days</span>
        <span>−</span>
        <span style="background:var(--green-light);color:var(--green);padding:3px 10px;border-radius:5px;font-weight:700;">Attendance</span>
        <span>−</span>
        <span style="background:var(--amber-light);color:var(--amber);padding:3px 10px;border-radius:5px;font-weight:700;">Total Leave</span>
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:6px;">
        <i class="fa-solid fa-star" style="color:var(--purple);font-size:10px;"></i>
        <span>Leave Earned = Days worked on a special holiday applicable to this employee</span>
    </div>
</div>
<?php endif; ?>

<script>
function toggleFilters() {
    const body    = document.getElementById('filterBody');
    const header  = document.querySelector('.filter-bar-header');
    const chevron = document.getElementById('filterChevron');
    const open    = body.classList.toggle('open');
    header.classList.toggle('expanded', open);
    chevron.style.transform = open ? 'rotate(180deg)' : '';
}

function autoSubmit() {
    document.getElementById('reportForm').submit();
}

function exportCSV() {
    const table = document.getElementById('summaryTable');
    if (!table) return;
    let csv = [];
    table.querySelectorAll('tr').forEach(row => {
        const rowData = [];
        row.querySelectorAll('th, td').forEach(cell => {
            let txt = cell.innerText.replace(/\n/g, ' ').replace(/,/g, ' ').trim();
            rowData.push('"' + txt + '"');
        });
        csv.push(rowData.join(','));
    });
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'attendance_summary_<?php echo $sel_year.'_'.str_pad($sel_month,2,'0',STR_PAD_LEFT); ?>.csv';
    a.click();
    URL.revokeObjectURL(url);
}

<?php if ($active_filter_count > 0): ?>
document.querySelector('.filter-bar-header').classList.add('expanded');
document.getElementById('filterBody').classList.add('open');
document.getElementById('filterChevron').style.transform = 'rotate(180deg)';
<?php endif; ?>
</script>

<?php
if (file_exists(__DIR__ . '/footer.php')) {
    include __DIR__ . '/footer.php';
} else {
    echo '</body></html>';
}
?>