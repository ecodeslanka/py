<?php
// ── Yelo Group HMS — Monthly Attendance Roster ────────────────────────────────
// Day-by-day attendance grid (P / W / WOD / H / WPH / AL / CL / SL / PH / NL)
// Modeled after an external hotel attendance-sheet format, re-branded for
// Yelo Group and wired to existing tables: employees, attendance,
// leave_applications, special_holidays, designations, staff_categories,
// companies, branches.
//
// ASSUMPTIONS (no exact source in the uploaded files for these — adjust if wrong):
//   1. Weekly off day defaults to Sunday (date('w')==0). Changeable via the
//      "Week Off Day" filter dropdown on screen (does not persist per-employee).
//   2. DOJ / exit-date column names are auto-detected via SHOW COLUMNS against
//      a candidate list; if none exist, the DOJ column just renders blank and
//      no employee is excluded on that basis.
//   3. leave_applications.leave_type values are mapped to short codes:
//      Annual Leave->AL, Casual Leave->CL, Medical Leave->SL,
//      Public Holiday Leave->PH. special_holidays produces H (not worked) /
//      WPH (worked on that holiday).
//   4. "No Pay" (NL) = normal working day with no attendance row and no
//      approved leave covering it.

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

// ── SCHEMA SAFETY: detect DOJ / exit-date columns on employees ────────────────
$emp_cols = [];
$cols_res = mysqli_query($conn, "SHOW COLUMNS FROM employees");
if ($cols_res) while ($c = mysqli_fetch_assoc($cols_res)) $emp_cols[] = $c['Field'];

$doj_col = null;
foreach (['date_of_join', 'date_of_joining', 'doj', 'joining_date', 'date_joined', 'join_date'] as $cand) {
    if (in_array($cand, $emp_cols, true)) { $doj_col = $cand; break; }
}
$exit_col = null;
foreach (['exit_date', 'resignation_date', 'termination_date', 'leaving_date'] as $cand) {
    if (in_array($cand, $emp_cols, true)) { $exit_col = $cand; break; }
}

// ── FILTERS ─────────────────────────────────────────────────────────────────
$cur_year  = (int)date('Y');
$cur_month = (int)date('n');

$sel_year  = isset($_GET['year'])  ? intval($_GET['year'])  : $cur_year;
$sel_month = isset($_GET['month']) ? intval($_GET['month']) : $cur_month;

$f_company  = isset($_GET['f_company'])  ? intval($_GET['f_company'])  : 0;
$f_branch   = isset($_GET['f_branch'])   ? intval($_GET['f_branch'])   : 0;
$f_desig    = isset($_GET['f_desig'])    ? intval($_GET['f_desig'])    : 0;
$f_staffcat = isset($_GET['f_staffcat']) ? intval($_GET['f_staffcat']) : 0;
$f_search   = isset($_GET['f_search'])   ? mysqli_real_escape_string($conn, trim($_GET['f_search'])) : '';
$week_off   = isset($_GET['week_off']) && $_GET['week_off'] !== '' ? intval($_GET['week_off']) : 0; // PHP date('w'): 0=Sun..6=Sat

if ($sel_year < 2000 || $sel_year > 2100) $sel_year = $cur_year;
if ($sel_month < 1 || $sel_month > 12)    $sel_month = $cur_month;

$from_date     = sprintf('%04d-%02d-01', $sel_year, $sel_month);
$to_date       = date('Y-m-t', strtotime($from_date));
$days_in_month = (int)date('t', strtotime($from_date));
$month_label   = strtoupper(date('M', strtotime($from_date))) . ' - ' . $sel_year;
$month_full    = date('F', strtotime($from_date));

// ── COMPANY BRANDING ───────────────────────────────────────────────────────
$company_title    = 'YELO GROUP';
$company_subtitle = 'DISTRIBUTION  ·  LOGISTICS';
if ($f_company) {
    $cr = mysqli_query($conn, "SELECT company_name FROM companies WHERE id=" . intval($f_company));
    if ($cr && $row = mysqli_fetch_assoc($cr)) $company_title = strtoupper($row['company_name']);
}

// ── EMPLOYEE WHERE ──────────────────────────────────────────────────────────
$emp_where = ["e.active = 1"];
if ($f_company)  $emp_where[] = "e.company_id = $f_company";
if ($f_branch)   $emp_where[] = "e.branch_id = $f_branch";
if ($f_desig)    $emp_where[] = "e.designation_id = $f_desig";
if ($f_staffcat) $emp_where[] = "e.staff_category_id = $f_staffcat";
if ($f_search)   $emp_where[] = "(e.employee_id LIKE '%$f_search%' OR e.employee_full_name LIKE '%$f_search%')";
$emp_where_sql = 'WHERE ' . implode(' AND ', $emp_where);

$doj_select   = $doj_col   ? "e.$doj_col AS doj"     : "NULL AS doj";
$exit_select  = $exit_col  ? "e.$exit_col AS exitd"  : "NULL AS exitd";

$emp_sql = "SELECT e.id, e.employee_id, e.employee_full_name, $doj_select, $exit_select,
                   COALESCE(d.designation_name,'Unassigned')   AS designation_name,
                   COALESCE(sc.category_name,'Unassigned')     AS staff_category_name,
                   d.id  AS designation_id_v,
                   sc.id AS staff_category_id_v
            FROM employees e
            LEFT JOIN designations d     ON e.designation_id    = d.id
            LEFT JOIN staff_categories sc ON e.staff_category_id = sc.id
            $emp_where_sql
            ORDER BY sc.category_name, d.designation_name, e.employee_full_name";
$emp_res = mysqli_query($conn, $emp_sql);
if (!$emp_res) die('<h3 style="color:red;padding:20px;">Employee query failed: ' . htmlspecialchars(mysqli_error($conn)) . '</h3>');
$employees = [];
while ($e = mysqli_fetch_assoc($emp_res)) $employees[] = $e;
$emp_ids_list = implode(',', array_map(fn($e) => intval($e['id']), $employees)) ?: '0';

// ── ATTENDANCE PRESENCE MAP ─────────────────────────────────────────────────
$att_map = [];
$att_res = mysqli_query($conn, "SELECT employee_id, att_date FROM attendance
    WHERE employee_id IN ($emp_ids_list) AND att_date BETWEEN '$from_date' AND '$to_date'");
if ($att_res) while ($a = mysqli_fetch_assoc($att_res)) $att_map[$a['employee_id']][$a['att_date']] = 1;

// ── APPROVED LEAVE, EXPANDED TO PER-DAY CODES ───────────────────────────────
$LEAVE_CODE_MAP = [
    'Annual Leave'         => 'AL',
    'Casual Leave'         => 'CL',
    'Medical Leave'        => 'SL',
    'Public Holiday Leave' => 'PH',
];
$leave_day_map = []; // [emp_id][Y-m-d] = code
$lv_res = mysqli_query($conn, "SELECT employee_id, leave_type, start_date, end_date FROM leave_applications
    WHERE employee_id IN ($emp_ids_list) AND status = 'Approved'
      AND start_date <= '$to_date' AND end_date >= '$from_date'");
if ($lv_res) {
    while ($lv = mysqli_fetch_assoc($lv_res)) {
        $code = $LEAVE_CODE_MAP[$lv['leave_type']] ?? 'AL';
        $s  = new DateTime(max($lv['start_date'], $from_date));
        $en = new DateTime(min($lv['end_date'],   $to_date));
        $en->modify('+1 day');
        foreach (new DatePeriod($s, new DateInterval('P1D'), $en) as $dt) {
            $leave_day_map[$lv['employee_id']][$dt->format('Y-m-d')] = $code;
        }
    }
}

// ── SPECIAL HOLIDAYS, EXPANDED TO PER-DAY, TARGET-AWARE ─────────────────────
$sh_res = mysqli_query($conn, "SELECT * FROM special_holidays
    WHERE active = 1 AND date_from <= '$to_date' AND date_to >= '$from_date'");
$sh_rows = [];
if ($sh_res) while ($sh = mysqli_fetch_assoc($sh_res)) $sh_rows[] = $sh;

$sh_date_map = []; // [Y-m-d] = [special_holidays rows...]
foreach ($sh_rows as $sh) {
    $s  = new DateTime(max($sh['date_from'], $from_date));
    $en = new DateTime(min($sh['date_to'],   $to_date));
    $en->modify('+1 day');
    foreach (new DatePeriod($s, new DateInterval('P1D'), $en) as $dt) {
        $sh_date_map[$dt->format('Y-m-d')][] = $sh;
    }
}
function shAppliesTo(array $shs, $eid, $staffCatId, $desigId): bool {
    foreach ($shs as $sh) {
        if ($sh['target_type'] === 'all') return true;
        if (!empty($sh['target_ids'])) {
            $ids = json_decode($sh['target_ids'], true) ?: [];
            if ($sh['target_type'] === 'employee'       && in_array((int)$eid, $ids))        return true;
            if ($sh['target_type'] === 'staff_category' && in_array((int)$staffCatId, $ids)) return true;
            if ($sh['target_type'] === 'designation'    && in_array((int)$desigId, $ids))    return true;
        }
    }
    return false;
}

// ── BUILD DAY-STATUS GRID ────────────────────────────────────────────────────
$STATUS_LABELS = [
    ''    => 'Not Employed / Pre-Join / Post-Exit',
    'P'   => 'Present',
    'W'   => 'Week-Off',
    'WOD' => 'Worked On Day Off',
    'H'   => 'Holiday',
    'WPH' => 'Worked On Public Holiday',
    'AL'  => 'Annual Leave',
    'CL'  => 'Casual Leave',
    'SL'  => 'Sick / Medical Leave',
    'PH'  => 'Public Holiday Leave',
    'NL'  => 'No Pay',
];
$grid          = []; // [emp_id][day] = code
$legend_counts = array_fill_keys(array_keys($STATUS_LABELS), 0);

foreach ($employees as $emp) {
    $eid   = $emp['id'];
    $doj   = $emp['doj']   ? date('Y-m-d', strtotime($emp['doj']))   : null;
    $exitd = $emp['exitd'] ? date('Y-m-d', strtotime($emp['exitd'])) : null;

    for ($d = 1; $d <= $days_in_month; $d++) {
        $date = sprintf('%04d-%02d-%02d', $sel_year, $sel_month, $d);
        $code = '';

        if (($doj && $date < $doj) || ($exitd && $date > $exitd)) {
            $code = '';
        } elseif (isset($leave_day_map[$eid][$date])) {
            $code = $leave_day_map[$eid][$date];
        } elseif (isset($sh_date_map[$date]) && shAppliesTo($sh_date_map[$date], $eid, $emp['staff_category_id_v'], $emp['designation_id_v'])) {
            $code = isset($att_map[$eid][$date]) ? 'WPH' : 'H';
        } elseif ((int)date('w', strtotime($date)) === $week_off) {
            $code = isset($att_map[$eid][$date]) ? 'WOD' : 'W';
        } else {
            $code = isset($att_map[$eid][$date]) ? 'P' : 'NL';
        }

        $grid[$eid][$d] = $code;
        $legend_counts[$code]++;
    }
}

// ── GROUP ROWS (mirrors the sample sheet's department/section header bands) ──
$groups = [];
foreach ($employees as $emp) {
    $key = strtoupper(trim(($emp['staff_category_name'] ?? 'UNASSIGNED') . ' - ' . ($emp['designation_name'] ?? 'UNASSIGNED')));
    $groups[$key][] = $emp;
}

// ── EXPORT PAYLOAD (consumed by the client-side styled Excel export) ─────────
$export_groups = [];
foreach ($groups as $group_name => $group_emps) {
    $g_emps = [];
    foreach ($group_emps as $emp) {
        $eid = $emp['id'];
        $codes = [];
        for ($d = 1; $d <= $days_in_month; $d++) $codes[] = $grid[$eid][$d] ?? '';
        $g_emps[] = [
            'employee_id'  => $emp['employee_id'],
            'full_name'    => $emp['employee_full_name'],
            'designation'  => $emp['designation_name'],
            'doj'          => $emp['doj'] ? date('d-M-y', strtotime($emp['doj'])) : '',
            'codes'        => $codes,
        ];
    }
    $export_groups[] = ['name' => $group_name, 'employees' => $g_emps];
}
$export_legend = [];
foreach ($STATUS_LABELS as $code => $label) {
    $export_legend[] = ['code' => $code, 'label' => $label, 'count' => (int)($legend_counts[$code] ?? 0)];
}
$export_days = [];
for ($d = 1; $d <= $days_in_month; $d++) {
    $export_days[] = ['num' => str_pad($d,2,'0',STR_PAD_LEFT), 'wname' => strtoupper(date('D', mktime(0,0,0,$sel_month,$d,$sel_year)))];
}
$export_payload = [
    'company_title'    => $company_title,
    'company_subtitle' => $company_subtitle,
    'month_label'      => $month_label,
    'days'             => $export_days,
    'groups'           => $export_groups,
    'legend'           => $export_legend,
];

// ── FILTER DROPDOWN DATA ─────────────────────────────────────────────────────
$companies_r = mysqli_query($conn, "SELECT id, company_name FROM companies WHERE active=1 ORDER BY company_name");
$branches_r  = mysqli_query($conn, "SELECT id, branch_name FROM branches ORDER BY branch_name");
$desigs_r    = mysqli_query($conn, "SELECT id, designation_name FROM designations ORDER BY designation_name");
$staffcats_r = mysqli_query($conn, "SELECT id, category_name FROM staff_categories WHERE active=1 ORDER BY category_name");

$active_filter_count = (int)($f_company>0) + (int)($f_branch>0) + (int)($f_desig>0) + (int)($f_staffcat>0) + (int)(!empty($f_search));

if (file_exists(__DIR__ . '/header.php')) include __DIR__ . '/header.php';
else echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Monthly Attendance Roster</title><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"></head><body style="font-family:sans-serif;background:#f3f4f6;padding:20px;">';
?>
<style>
:root{
    --ink:#0d1117;--ink-2:#374151;--ink-3:#6b7280;
    --border:#e5e7eb;--border-2:#f0f0f0;
    --surface:#ffffff;--surface-2:#f8fafc;--surface-3:#f3f4f6;
    --blue:#2563eb;--blue-light:#eff6ff;
    --radius:10px;--radius-sm:7px;
}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Segoe UI',Arial,sans-serif;}

.no-print{}
@media print{ .no-print{display:none !important;} }

/* ── FILTER BAR ─────────────────────────────────────────────────────────── */
.mar-filterbar{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:14px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.mar-field{display:flex;flex-direction:column;gap:5px;}
.mar-label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink-3);}
.mar-input,.mar-select{height:38px;padding:0 10px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:12px;font-family:inherit;color:var(--ink);background:var(--surface);min-width:150px;}
.mar-input:focus,.mar-select:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 2px rgba(37,99,235,.1);}
.mar-btn{height:38px;padding:0 16px;border:none;border-radius:var(--radius-sm);font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:6px;}
.mar-btn-primary{background:var(--blue);color:#fff;}
.mar-btn-light{background:var(--surface-3);color:var(--ink-2);border:1.5px solid var(--border);}
.mar-btn-light:hover{background:#eef2f7;}

/* ── SHEET / PRINT AREA ─────────────────────────────────────────────────── */
.mar-sheet{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:26px 28px;overflow-x:auto;}
.mar-brandbar{text-align:center;margin-bottom:10px;}
.mar-brand-title{font-size:20px;font-weight:900;letter-spacing:3px;color:var(--ink);}
.mar-brand-sub{font-size:10px;letter-spacing:4px;color:var(--ink-3);margin-top:2px;}
.mar-report-title{text-align:center;font-size:13px;font-weight:800;letter-spacing:1px;background:var(--ink);color:#fff;padding:6px 0;border-radius:5px;margin:14px 0 16px;}

table.mar-table{border-collapse:collapse;width:100%;min-width:1400px;font-size:10px;}
table.mar-table th,table.mar-table td{border:1px solid #94a3b8;padding:3px 5px;text-align:center;white-space:nowrap;}
table.mar-table thead th{background:var(--ink);color:#fff;font-size:9px;font-weight:800;text-transform:uppercase;}
table.mar-table thead th.mar-daynum{font-size:10px;}
table.mar-table thead th.mar-dayname{font-size:8px;font-weight:600;color:#cbd5e1;}
table.mar-table .mar-th-left{text-align:left;}
table.mar-table td.mar-empid{font-weight:800;color:var(--blue);text-align:left;font-family:monospace;}
table.mar-table td.mar-fullname{text-align:left;font-weight:700;max-width:170px;white-space:normal;}
table.mar-table td.mar-desig{text-align:left;font-size:9px;color:var(--ink-2);max-width:150px;white-space:normal;}
table.mar-table td.mar-doj{font-size:9px;color:var(--ink-3);}
table.mar-table td.mar-sig{min-width:70px;}

tr.mar-group-row td{background:#e2e8f0;font-weight:900;text-align:left;font-size:10px;letter-spacing:.5px;padding:5px 8px;}

td.code-P  {background:#dcfce7;color:#166534;font-weight:700;}
td.code-W  {background:#f1f5f9;color:#475569;font-weight:700;}
td.code-WOD{background:#dbeafe;color:#1d4ed8;font-weight:700;}
td.code-H  {background:#fef3c7;color:#92400e;font-weight:700;}
td.code-WPH{background:#fde68a;color:#78350f;font-weight:800;}
td.code-AL {background:#fee2e2;color:#991b1b;font-weight:700;}
td.code-CL {background:#fce7f3;color:#9d174d;font-weight:700;}
td.code-SL {background:#ede9fe;color:#5b21b6;font-weight:700;}
td.code-PH {background:#ffedd5;color:#9a3412;font-weight:700;}
td.code-NL {background:#fecaca;color:#7f1d1d;font-weight:900;}

/* ── FOOTER: signatures + legend ────────────────────────────────────────── */
.mar-footer{display:flex;gap:30px;margin-top:26px;flex-wrap:wrap;}
.mar-sign-grid{flex:2;display:grid;grid-template-columns:repeat(4,1fr);gap:18px;min-width:520px;}
.mar-sign-block{font-size:11px;font-weight:800;color:var(--ink);}
.mar-sign-block div{font-weight:600;color:var(--ink-2);margin-top:4px;font-size:10px;}
.mar-legend{flex:1;min-width:260px;}
table.mar-legend-table{border-collapse:collapse;width:100%;font-size:10px;}
table.mar-legend-table th,table.mar-legend-table td{border:1px solid #cbd5e1;padding:3px 7px;text-align:left;}
table.mar-legend-table th{background:#1f2937;color:#fff;font-size:9px;text-transform:uppercase;}
table.mar-legend-table td.mar-lg-num{text-align:right;font-weight:700;}

@media print{
    body{background:#fff;}
    .mar-sheet{border:none;padding:0;}
    @page{size:A4 landscape;margin:10mm;}
}
</style>

<div class="no-print" style="margin-bottom:14px;">
    <h2 style="font-size:20px;font-weight:800;color:var(--ink);margin:0 0 4px;"><i class="fa-solid fa-calendar-check" style="color:var(--blue);margin-right:8px;"></i>Monthly Attendance Roster</h2>
    <p style="font-size:12px;color:var(--ink-3);margin:0;">Day-by-day attendance / leave grid for printing &amp; sign-off, grouped by staff category &amp; designation.</p>
</div>

<form id="marFilterForm" method="GET" class="mar-filterbar no-print">
    <div class="mar-field">
        <label class="mar-label">Month</label>
        <select name="month" class="mar-select" onchange="document.getElementById('marFilterForm').submit()">
            <?php for ($m=1;$m<=12;$m++): ?>
                <option value="<?php echo $m; ?>" <?php echo $m==$sel_month?'selected':''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="mar-field">
        <label class="mar-label">Year</label>
        <select name="year" class="mar-select" onchange="document.getElementById('marFilterForm').submit()">
            <?php for ($y=$cur_year-3;$y<=$cur_year+1;$y++): ?>
                <option value="<?php echo $y; ?>" <?php echo $y==$sel_year?'selected':''; ?>><?php echo $y; ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="mar-field">
        <label class="mar-label">Company</label>
        <select name="f_company" class="mar-select" onchange="document.getElementById('marFilterForm').submit()">
            <option value="0">All Companies</option>
            <?php if ($companies_r) while ($c = mysqli_fetch_assoc($companies_r)): ?>
                <option value="<?php echo $c['id']; ?>" <?php echo $f_company==$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['company_name']); ?></option>
            <?php endwhile; ?>
        </select>
    </div>
    <div class="mar-field">
        <label class="mar-label">Branch</label>
        <select name="f_branch" class="mar-select" onchange="document.getElementById('marFilterForm').submit()">
            <option value="0">All Branches</option>
            <?php if ($branches_r) while ($b = mysqli_fetch_assoc($branches_r)): ?>
                <option value="<?php echo $b['id']; ?>" <?php echo $f_branch==$b['id']?'selected':''; ?>><?php echo htmlspecialchars($b['branch_name']); ?></option>
            <?php endwhile; ?>
        </select>
    </div>
    <div class="mar-field">
        <label class="mar-label">Staff Category</label>
        <select name="f_staffcat" class="mar-select" onchange="document.getElementById('marFilterForm').submit()">
            <option value="0">All Categories</option>
            <?php if ($staffcats_r) while ($sc = mysqli_fetch_assoc($staffcats_r)): ?>
                <option value="<?php echo $sc['id']; ?>" <?php echo $f_staffcat==$sc['id']?'selected':''; ?>><?php echo htmlspecialchars($sc['category_name']); ?></option>
            <?php endwhile; ?>
        </select>
    </div>
    <div class="mar-field">
        <label class="mar-label">Designation</label>
        <select name="f_desig" class="mar-select" onchange="document.getElementById('marFilterForm').submit()">
            <option value="0">All Designations</option>
            <?php if ($desigs_r) while ($d = mysqli_fetch_assoc($desigs_r)): ?>
                <option value="<?php echo $d['id']; ?>" <?php echo $f_desig==$d['id']?'selected':''; ?>><?php echo htmlspecialchars($d['designation_name']); ?></option>
            <?php endwhile; ?>
        </select>
    </div>
    <div class="mar-field">
        <label class="mar-label">Week Off Day</label>
        <select name="week_off" class="mar-select" onchange="document.getElementById('marFilterForm').submit()">
            <?php $wd_names=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            foreach ($wd_names as $wi=>$wn): ?>
                <option value="<?php echo $wi; ?>" <?php echo $week_off==$wi?'selected':''; ?>><?php echo $wn; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mar-field" style="flex:1;min-width:160px;">
        <label class="mar-label">Search</label>
        <input type="text" name="f_search" class="mar-input" style="width:100%;" placeholder="Emp ID or name" value="<?php echo htmlspecialchars($f_search); ?>">
    </div>
    <div class="mar-field">
        <button type="submit" class="mar-btn mar-btn-primary"><i class="fa-solid fa-filter"></i> Apply</button>
    </div>
    <div class="mar-field">
        <button type="button" class="mar-btn mar-btn-light" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>
    <div class="mar-field">
        <button type="button" class="mar-btn mar-btn-light" onclick="openBWPrint()"><i class="fa-solid fa-file"></i> Print B&amp;W</button>
    </div>
    <div class="mar-field">
        <button type="button" class="mar-btn mar-btn-light" id="marExportBtn" onclick="exportRosterExcel()"><i class="fa-solid fa-file-excel" style="color:#16a34a;"></i> Export Excel</button>
    </div>
</form>
<script src="https://cdn.jsdelivr.net/npm/xlsx-js-style@1.2.0/dist/xlsx.bundle.js"></script>
<script>
function openBWPrint() {
    var f = document.getElementById('marFilterForm');
    var params = new URLSearchParams(new FormData(f)).toString();
    window.open('attendance_monthly_roster_print_bw.php?' + params, '_blank');
}

const ROSTER_XLS = <?php echo json_encode($export_payload, JSON_UNESCAPED_UNICODE); ?>;

// Fill/font colours mirror the on-screen code-XXX cell classes
const ROSTER_CODE_STYLE = {
    ''   : { fill:'FFFFFF', font:'9CA3AF' },
    'P'  : { fill:'DCFCE7', font:'166534', bold:true },
    'W'  : { fill:'F1F5F9', font:'475569', bold:true },
    'WOD': { fill:'DBEAFE', font:'1D4ED8', bold:true },
    'H'  : { fill:'FEF3C7', font:'92400E', bold:true },
    'WPH': { fill:'FDE68A', font:'78350F', bold:true },
    'AL' : { fill:'FEE2E2', font:'991B1B', bold:true },
    'CL' : { fill:'FCE7F3', font:'9D174D', bold:true },
    'SL' : { fill:'EDE9FE', font:'5B21B6', bold:true },
    'PH' : { fill:'FFEDD5', font:'9A3412', bold:true },
    'NL' : { fill:'FECACA', font:'7F1D1D', bold:true },
};

function rxThin(color) { return { style:'thin', color:{ rgb: color || 'CBD5E1' } }; }
function rxBorderAll(color) { return { top: rxThin(color), bottom: rxThin(color), left: rxThin(color), right: rxThin(color) }; }

function exportRosterExcel() {
    if (typeof XLSX === 'undefined') { alert('Excel export library failed to load.'); return; }
    const data = ROSTER_XLS;
    const fixedCols = 4;          // Emp ID, Full Name, Designation, DOJ
    const dayCols   = data.days.length;
    const totalCols = fixedCols + dayCols + 1; // +Signature
    const lastColIdx = totalCols - 1;

    const aoa = [];
    const merges = [];
    const rowStyles = []; // parallel per-row array of per-cell style overrides (sparse)
    const rowHeights = [];

    function pushRow(cells, heightPt) {
        aoa.push(cells);
        rowHeights.push(heightPt || null);
        return aoa.length - 1; // row index
    }

    // ── Brand header ──────────────────────────────────────────────────────
    let r = pushRow([data.company_title, ...Array(lastColIdx).fill('')], 26);
    merges.push({ s:{r:r,c:0}, e:{r:r,c:lastColIdx} });

    r = pushRow([data.company_subtitle, ...Array(lastColIdx).fill('')], 14);
    merges.push({ s:{r:r,c:0}, e:{r:r,c:lastColIdx} });

    r = pushRow(Array(totalCols).fill(''), 6); // spacer

    r = pushRow(['ATTENDANCE FOR THE MONTH OF ' + data.month_label, ...Array(lastColIdx).fill('')], 20);
    const titleRow = r;
    merges.push({ s:{r:r,c:0}, e:{r:r,c:lastColIdx} });

    r = pushRow(Array(totalCols).fill(''), 6); // spacer

    // ── Table header row ─────────────────────────────────────────────────
    const headerRow = pushRow([
        'Emp ID', 'Full Name', 'Designation', 'DOJ',
        ...data.days.map(d => d.num + '\n' + d.wname),
        'Signature'
    ], 26);

    // ── Group + employee rows ───────────────────────────────────────────
    const groupRowIdx = [];
    const codeCellMap = {}; // "r,c" -> code, used to style after sheet is built

    data.groups.forEach(function(g) {
        const gr = pushRow([g.name, ...Array(lastColIdx).fill('')], 16);
        merges.push({ s:{r:gr,c:0}, e:{r:gr,c:lastColIdx} });
        groupRowIdx.push(gr);

        g.employees.forEach(function(emp) {
            const rowCells = [emp.employee_id, emp.full_name, emp.designation, emp.doj, ...emp.codes, ''];
            const er = pushRow(rowCells, 15);
            emp.codes.forEach(function(code, i) {
                codeCellMap[er + ',' + (fixedCols + i)] = code;
            });
        });
    });

    // ── Footer: signatures ───────────────────────────────────────────────
    pushRow(Array(totalCols).fill(''), 8);
    const sigLabels = ['PREPARED BY :', 'APPROVED BY :', 'HUMAN RESOURCES APPROVAL :', 'FINANCE APPROVAL :'];
    const sigRow1 = pushRow([sigLabels[0], '', sigLabels[1], '', sigLabels[2], '', sigLabels[3], ...Array(Math.max(0,totalCols-7)).fill('')], 16);
    const sigRow2 = pushRow(['Designation:', '', 'Designation:', '', 'Designation:', '', 'Designation:', ...Array(Math.max(0,totalCols-7)).fill('')], 14);
    const sigRow3 = pushRow(['Date:', '', 'Date:', '', 'Date:', '', 'Date:', ...Array(Math.max(0,totalCols-7)).fill('')], 14);

    pushRow(Array(totalCols).fill(''), 8);

    // ── Legend table ─────────────────────────────────────────────────────
    const legendHeaderRow = pushRow(['Leave Type', 'Code', 'Total Days', ...Array(Math.max(0,totalCols-3)).fill('')], 16);
    const legendStartRow = aoa.length;
    data.legend.forEach(function(lg) {
        pushRow([lg.label, lg.code || '—', lg.count, ...Array(Math.max(0,totalCols-3)).fill('')], 14);
    });
    const legendEndRow = aoa.length - 1;

    // ── Build worksheet ──────────────────────────────────────────────────
    const ws = XLSX.utils.aoa_to_sheet(aoa);
    ws['!merges'] = merges;
    ws['!rows'] = rowHeights.map(h => h ? { hpt: h } : {});

    const colWidths = [ { wch:12 }, { wch:24 }, { wch:20 }, { wch:11 } ];
    for (let i = 0; i < dayCols; i++) colWidths.push({ wch: 4.2 });
    colWidths.push({ wch: 12 });
    ws['!cols'] = colWidths;

    ws['!freeze'] = { xSplit: fixedCols, ySplit: headerRow + 1 };
    ws['!panes'] = [{ xSplit: fixedCols, ySplit: headerRow + 1, topLeftCell: XLSX.utils.encode_cell({r:headerRow+1,c:fixedCols}), activePane: 'bottomRight', state: 'frozen' }];

    function setStyle(rr, cc, style) {
        const addr = XLSX.utils.encode_cell({ r: rr, c: cc });
        if (!ws[addr]) ws[addr] = { t:'s', v:'' };
        ws[addr].s = style;
    }

    // Brand title
    setStyle(0, 0, { font:{ bold:true, sz:18, color:{rgb:'0D1117'} }, alignment:{ horizontal:'center', vertical:'center' } });
    setStyle(1, 0, { font:{ italic:true, sz:10, color:{rgb:'6B7280'} }, alignment:{ horizontal:'center' } });
    setStyle(titleRow, 0, { font:{ bold:true, sz:12, color:{rgb:'FFFFFF'} }, fill:{ fgColor:{rgb:'0D1117'} }, alignment:{ horizontal:'center', vertical:'center' } });

    // Table header
    for (let c = 0; c < totalCols; c++) {
        setStyle(headerRow, c, {
            font:{ bold:true, sz:9, color:{rgb:'FFFFFF'} },
            fill:{ fgColor:{rgb:'1F2937'} },
            alignment:{ horizontal:'center', vertical:'center', wrapText:true },
            border: rxBorderAll('0D1117'),
        });
    }

    // Group rows
    groupRowIdx.forEach(function(gr) {
        setStyle(gr, 0, {
            font:{ bold:true, sz:10, color:{rgb:'1F2937'} },
            fill:{ fgColor:{rgb:'E2E8F0'} },
            alignment:{ horizontal:'left', vertical:'center' },
            border: rxBorderAll('94A3B8'),
        });
    });

    // Employee data rows: base border + text styling for the first (headerRow+1) .. before footer spacer
    const firstEmpRow = headerRow + 1;
    for (let rr = firstEmpRow; rr < sigRow1 - 1; rr++) {
        if (groupRowIdx.indexOf(rr) !== -1) continue; // already styled
        for (let c = 0; c < totalCols; c++) {
            let style = { border: rxBorderAll('CBD5E1'), alignment:{ horizontal:'center', vertical:'center' } };
            if (c === 0) style.font = { bold:true, sz:9, color:{rgb:'2563EB'} };
            if (c === 1) { style.font = { bold:true, sz:9 }; style.alignment = { horizontal:'left', vertical:'center' }; }
            if (c === 2) { style.font = { sz:8, color:{rgb:'374151'} }; style.alignment = { horizontal:'left', vertical:'center' }; }
            if (c === 3) style.font = { sz:8, color:{rgb:'6B7280'} };
            if (c >= fixedCols && c < fixedCols + dayCols) {
                const code = codeCellMap[rr + ',' + c] || '';
                const cs = ROSTER_CODE_STYLE[code] || ROSTER_CODE_STYLE[''];
                style.font = { bold: !!cs.bold, sz:9, color:{ rgb: cs.font } };
                style.fill = { fgColor: { rgb: cs.fill } };
            }
            setStyle(rr, c, style);
        }
    }

    // Signature rows
    [sigRow1, sigRow2, sigRow3].forEach(function(rr, idx) {
        [0,2,4,6].forEach(function(c) {
            setStyle(rr, c, { font:{ bold: idx===0, sz: idx===0?10:9, color:{rgb:'0D1117'} } });
        });
    });

    // Legend
    for (let c = 0; c < 3; c++) {
        setStyle(legendHeaderRow, c, { font:{ bold:true, sz:9, color:{rgb:'FFFFFF'} }, fill:{ fgColor:{rgb:'1F2937'} }, border: rxBorderAll('0D1117') });
    }
    for (let rr = legendStartRow; rr <= legendEndRow; rr++) {
        for (let c = 0; c < 3; c++) {
            setStyle(rr, c, {
                font:{ sz:9 },
                border: rxBorderAll('CBD5E1'),
                alignment:{ horizontal: c===2 ? 'right' : 'left' },
            });
        }
    }

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Attendance Roster');
    const fname = 'attendance_roster_' + '<?php echo $sel_year . "_" . str_pad($sel_month,2,"0",STR_PAD_LEFT); ?>' + '.xlsx';
    XLSX.writeFile(wb, fname);
}
</script>

<div class="mar-sheet" id="marSheet">
    <div class="mar-brandbar">
        <div class="mar-brand-title"><?php echo htmlspecialchars($company_title); ?></div>
        <div class="mar-brand-sub"><?php echo htmlspecialchars($company_subtitle); ?></div>
    </div>
    <div class="mar-report-title">ATTENDANCE FOR THE MONTH OF <?php echo htmlspecialchars($month_label); ?></div>

    <table class="mar-table">
        <thead>
            <tr>
                <th rowspan="2" class="mar-th-left" style="min-width:70px;">Emp ID</th>
                <th rowspan="2" class="mar-th-left" style="min-width:150px;">Full Name</th>
                <th rowspan="2" class="mar-th-left" style="min-width:130px;">Designation</th>
                <th rowspan="2" style="min-width:70px;">DOJ</th>
                <?php for ($d=1;$d<=$days_in_month;$d++):
                    $wname = date('D', mktime(0,0,0,$sel_month,$d,$sel_year));
                ?>
                <th style="min-width:30px;">
                    <div class="mar-daynum"><?php echo str_pad($d,2,'0',STR_PAD_LEFT); ?></div>
                    <div class="mar-dayname"><?php echo strtoupper($wname); ?></div>
                </th>
                <?php endfor; ?>
                <th rowspan="2" style="min-width:70px;">Signature</th>
            </tr>
            <tr></tr>
        </thead>
        <tbody>
        <?php if (empty($employees)): ?>
            <tr><td colspan="<?php echo 5+$days_in_month; ?>" style="padding:20px;color:#94a3b8;">No employees match the selected filters.</td></tr>
        <?php else: ?>
            <?php foreach ($groups as $group_name => $group_emps): ?>
            <tr class="mar-group-row"><td colspan="<?php echo 5+$days_in_month; ?>"><?php echo htmlspecialchars($group_name); ?></td></tr>
            <?php foreach ($group_emps as $emp):
                $eid = $emp['id'];
                $doj_disp = $emp['doj'] ? date('d-M-y', strtotime($emp['doj'])) : '—';
            ?>
                <tr>
                    <td class="mar-empid"><?php echo htmlspecialchars($emp['employee_id']); ?></td>
                    <td class="mar-fullname"><?php echo htmlspecialchars($emp['employee_full_name']); ?></td>
                    <td class="mar-desig"><?php echo htmlspecialchars($emp['designation_name']); ?></td>
                    <td class="mar-doj"><?php echo $doj_disp; ?></td>
                    <?php for ($d=1;$d<=$days_in_month;$d++):
                        $code = $grid[$eid][$d] ?? '';
                    ?>
                    <td class="code-<?php echo $code ?: 'blank'; ?>"><?php echo htmlspecialchars($code); ?></td>
                    <?php endfor; ?>
                    <td class="mar-sig"></td>
                </tr>
            <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="mar-footer">
        <div class="mar-sign-grid">
            <div class="mar-sign-block">PREPARED BY :<div>DESIGNATION :</div><div>DATE :</div></div>
            <div class="mar-sign-block">APPROVED BY :<div>DESIGNATION :</div><div>DATE :</div></div>
            <div class="mar-sign-block">HUMAN RESOURCES APPROVAL :<div>DESIGNATION :</div><div>DATE :</div></div>
            <div class="mar-sign-block">FINANCE APPROVAL :<div>DESIGNATION :</div><div>DATE :</div></div>
        </div>
        <div class="mar-legend">
            <table class="mar-legend-table">
                <thead><tr><th>Leave Type</th><th>Code</th><th style="text-align:right;">Total Days</th></tr></thead>
                <tbody>
                <?php foreach ($STATUS_LABELS as $code => $label): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($label); ?></td>
                        <td><?php echo htmlspecialchars($code ?: '—'); ?></td>
                        <td class="mar-lg-num"><?php echo (int)($legend_counts[$code] ?? 0); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (file_exists(__DIR__ . '/footer.php')) include __DIR__ . '/footer.php'; else echo '</body></html>'; ?>