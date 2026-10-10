<?php
// ── Yelo Group HMS — Monthly Attendance Roster — B&W PRINT (standalone) ───────
// Sister to attendance_monthly_roster.php. No header.php/footer.php, no filter
// bar — reads the same GET params and renders a pure black & white printable
// version (auto-opens the print dialog). Opened via the "Print B&W" button.

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

// ── FILTERS (from query string, same params as the interactive page) ─────────
$cur_year  = (int)date('Y');
$cur_month = (int)date('n');

$sel_year  = isset($_GET['year'])  ? intval($_GET['year'])  : $cur_year;
$sel_month = isset($_GET['month']) ? intval($_GET['month']) : $cur_month;

$f_company  = isset($_GET['f_company'])  ? intval($_GET['f_company'])  : 0;
$f_branch   = isset($_GET['f_branch'])   ? intval($_GET['f_branch'])   : 0;
$f_desig    = isset($_GET['f_desig'])    ? intval($_GET['f_desig'])    : 0;
$f_staffcat = isset($_GET['f_staffcat']) ? intval($_GET['f_staffcat']) : 0;
$f_search   = isset($_GET['f_search'])   ? mysqli_real_escape_string($conn, trim($_GET['f_search'])) : '';
$week_off   = isset($_GET['week_off']) && $_GET['week_off'] !== '' ? intval($_GET['week_off']) : 0;

if ($sel_year < 2000 || $sel_year > 2100) $sel_year = $cur_year;
if ($sel_month < 1 || $sel_month > 12)    $sel_month = $cur_month;

$from_date     = sprintf('%04d-%02d-01', $sel_year, $sel_month);
$to_date       = date('Y-m-t', strtotime($from_date));
$days_in_month = (int)date('t', strtotime($from_date));
$month_label   = strtoupper(date('M', strtotime($from_date))) . ' - ' . $sel_year;

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

$doj_select  = $doj_col  ? "e.$doj_col AS doj"    : "NULL AS doj";
$exit_select = $exit_col ? "e.$exit_col AS exitd" : "NULL AS exitd";

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
$leave_day_map = [];
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

$sh_date_map = [];
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
$grid          = [];
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

// ── GROUP ROWS ─────────────────────────────────────────────────────────────
$groups = [];
foreach ($employees as $emp) {
    $key = strtoupper(trim(($emp['staff_category_name'] ?? 'UNASSIGNED') . ' - ' . ($emp['designation_name'] ?? 'UNASSIGNED')));
    $groups[$key][] = $emp;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Attendance Roster - <?php echo htmlspecialchars($month_label); ?> (B&amp;W Print)</title>
<style>
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Segoe UI',Arial,sans-serif;background:#fff;color:#000;margin:0;padding:18px 22px;}
.bw-brandbar{text-align:center;margin-bottom:8px;}
.bw-brand-title{font-size:20px;font-weight:900;letter-spacing:3px;color:#000;}
.bw-brand-sub{font-size:10px;letter-spacing:4px;color:#000;margin-top:2px;}
.bw-report-title{text-align:center;font-size:13px;font-weight:800;letter-spacing:1px;border:1.5px solid #000;padding:6px 0;margin:12px 0 14px;}

table.bw-table{border-collapse:collapse;width:100%;font-size:9.5px;}
table.bw-table th,table.bw-table td{border:1px solid #000;padding:3px 4px;text-align:center;white-space:nowrap;color:#000;background:#fff;}
table.bw-table thead th{font-weight:800;text-transform:uppercase;font-size:9px;}
table.bw-table thead th.bw-daynum{font-size:10px;}
table.bw-table thead th.bw-dayname{font-size:8px;font-weight:600;}
table.bw-table .bw-th-left{text-align:left;}
table.bw-table td.bw-empid{font-weight:800;text-align:left;font-family:monospace;}
table.bw-table td.bw-fullname{text-align:left;font-weight:700;max-width:170px;white-space:normal;}
table.bw-table td.bw-desig{text-align:left;font-size:9px;max-width:150px;white-space:normal;}
table.bw-table td.bw-doj{font-size:9px;}
table.bw-table td.bw-sig{min-width:60px;}
tr.bw-group-row td{background:#e6e6e6 !important;font-weight:900;text-align:left;font-size:10px;letter-spacing:.5px;padding:5px 8px;}
td.bw-code-strong{font-weight:900;}

.bw-footer{display:flex;gap:26px;margin-top:22px;flex-wrap:wrap;}
.bw-sign-grid{flex:2;display:grid;grid-template-columns:repeat(4,1fr);gap:16px;min-width:520px;}
.bw-sign-block{font-size:11px;font-weight:800;color:#000;}
.bw-sign-block div{font-weight:600;margin-top:4px;font-size:10px;}
.bw-legend{flex:1;min-width:250px;}
table.bw-legend-table{border-collapse:collapse;width:100%;font-size:9.5px;}
table.bw-legend-table th,table.bw-legend-table td{border:1px solid #000;padding:3px 6px;text-align:left;color:#000;background:#fff;}
table.bw-legend-table th{font-weight:800;text-transform:uppercase;font-size:9px;}
table.bw-legend-table td.bw-lg-num{text-align:right;font-weight:700;}

.bw-toolbar{text-align:right;margin-bottom:10px;}
.bw-toolbar button{padding:7px 14px;font-size:12px;font-weight:700;border:1px solid #000;background:#fff;color:#000;border-radius:4px;cursor:pointer;}

@media print{
    .bw-toolbar{display:none !important;}
    body{padding:0;}
    @page{size:A4 landscape;margin:9mm;}
}
</style>
</head>
<body>
    <div class="bw-toolbar"><button onclick="window.print()">Print</button></div>

    <div class="bw-brandbar">
        <div class="bw-brand-title"><?php echo htmlspecialchars($company_title); ?></div>
        <div class="bw-brand-sub"><?php echo htmlspecialchars($company_subtitle); ?></div>
    </div>
    <div class="bw-report-title">ATTENDANCE FOR THE MONTH OF <?php echo htmlspecialchars($month_label); ?></div>

    <table class="bw-table">
        <thead>
            <tr>
                <th rowspan="2" class="bw-th-left" style="min-width:65px;">Emp ID</th>
                <th rowspan="2" class="bw-th-left" style="min-width:140px;">Full Name</th>
                <th rowspan="2" class="bw-th-left" style="min-width:120px;">Designation</th>
                <th rowspan="2" style="min-width:65px;">DOJ</th>
                <?php for ($d=1;$d<=$days_in_month;$d++):
                    $wname = date('D', mktime(0,0,0,$sel_month,$d,$sel_year));
                ?>
                <th style="min-width:26px;">
                    <div class="bw-daynum"><?php echo str_pad($d,2,'0',STR_PAD_LEFT); ?></div>
                    <div class="bw-dayname"><?php echo strtoupper($wname); ?></div>
                </th>
                <?php endfor; ?>
                <th rowspan="2" style="min-width:60px;">Signature</th>
            </tr>
            <tr></tr>
        </thead>
        <tbody>
        <?php if (empty($employees)): ?>
            <tr><td colspan="<?php echo 5+$days_in_month; ?>" style="padding:20px;">No employees match the selected filters.</td></tr>
        <?php else: ?>
            <?php foreach ($groups as $group_name => $group_emps): ?>
            <tr class="bw-group-row"><td colspan="<?php echo 5+$days_in_month; ?>"><?php echo htmlspecialchars($group_name); ?></td></tr>
            <?php foreach ($group_emps as $emp):
                $eid = $emp['id'];
                $doj_disp = $emp['doj'] ? date('d-M-y', strtotime($emp['doj'])) : '—';
            ?>
                <tr>
                    <td class="bw-empid"><?php echo htmlspecialchars($emp['employee_id']); ?></td>
                    <td class="bw-fullname"><?php echo htmlspecialchars($emp['employee_full_name']); ?></td>
                    <td class="bw-desig"><?php echo htmlspecialchars($emp['designation_name']); ?></td>
                    <td class="bw-doj"><?php echo $doj_disp; ?></td>
                    <?php for ($d=1;$d<=$days_in_month;$d++):
                        $code = $grid[$eid][$d] ?? '';
                        $strong = in_array($code, ['P','WOD','WPH','NL'], true) ? ' bw-code-strong' : '';
                    ?>
                    <td class="<?php echo trim($strong); ?>"><?php echo htmlspecialchars($code); ?></td>
                    <?php endfor; ?>
                    <td class="bw-sig"></td>
                </tr>
            <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="bw-footer">
        <div class="bw-sign-grid">
            <div class="bw-sign-block">PREPARED BY :<div>DESIGNATION :</div><div>DATE :</div></div>
            <div class="bw-sign-block">APPROVED BY :<div>DESIGNATION :</div><div>DATE :</div></div>
            <div class="bw-sign-block">HUMAN RESOURCES APPROVAL :<div>DESIGNATION :</div><div>DATE :</div></div>
            <div class="bw-sign-block">FINANCE APPROVAL :<div>DESIGNATION :</div><div>DATE :</div></div>
        </div>
        <div class="bw-legend">
            <table class="bw-legend-table">
                <thead><tr><th>Leave Type</th><th>Code</th><th style="text-align:right;">Total Days</th></tr></thead>
                <tbody>
                <?php foreach ($STATUS_LABELS as $code => $label): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($label); ?></td>
                        <td><?php echo htmlspecialchars($code ?: '—'); ?></td>
                        <td class="bw-lg-num"><?php echo (int)($legend_counts[$code] ?? 0); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<script>
window.onload = function(){ setTimeout(function(){ window.print(); }, 300); };
</script>
</body>
</html>
