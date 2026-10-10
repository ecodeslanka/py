<?php
// ── Yelo Group HMS — Monthly Attendance Grid Report ─────────────────────────
// Renders a printable "one row per employee, one column per day" attendance
// sheet in the same layout style as the resort-style sample report supplied,
// but wired to this system's own tables: employees, staff_categories,
// designations, leave_applications, public_holidays / bank_holidays, and the
// fingerprint-import attendance_system.attendance_records table.
//
// A new attendance_monthly_codes table lets HR type/override any day code
// directly on the grid (e.g. DO, WOD, WPH, R&R1, NL, W) since those are
// business-specific and can't all be derived automatically.
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
// PHP 8.1+ mysqli defaults to throwing exceptions on DB errors (e.g. access
// denied to a database this DB user doesn't have grants on). This file needs
// to probe for tables/DBs that may or may not exist/be reachable, so we turn
// that off and handle errors the classic way (return value + mysqli_error()).
mysqli_report(MYSQLI_REPORT_OFF);
if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';

// ── Helper: safe column add (SHOW COLUMNS pattern used across this codebase) ─
function ensureColumn($conn, $table, $col, $def) {
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
    if ($chk && mysqli_num_rows($chk) === 0) {
        @mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$col` $def");
    }
}
function tableExists($conn, $table) {
    // Supports both "table" (current DB) and "db.table" (cross-database) forms.
    // Wrapped in try/catch: if this DB user has no grant on the other database
    // (common on shared hosting), mysqli may still throw even with
    // MYSQLI_REPORT_OFF in some driver versions — treat that as "not found"
    // rather than fatal-erroring the whole page.
    try {
        if (strpos($table, '.') !== false) {
            [$db, $tbl] = explode('.', $table, 2);
            $r = @mysqli_query($conn, "SHOW TABLES FROM `$db` LIKE '$tbl'");
            return $r && mysqli_num_rows($r) > 0;
        }
        $r = @mysqli_query($conn, "SHOW TABLES LIKE '$table'");
        return $r && mysqli_num_rows($r) > 0;
    } catch (\Throwable $e) {
        return false;
    }
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

// ── Auto-migrations ──────────────────────────────────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS attendance_monthly_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    att_date DATE NOT NULL,
    code VARCHAR(10) NOT NULL,
    remark VARCHAR(255) NULL,
    updated_by VARCHAR(100) NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_emp_date (employee_id, att_date),
    KEY idx_date (att_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

ensureColumn($conn, 'employees', 'weekly_off_day', "TINYINT(1) NULL COMMENT '0=Sun 1=Mon ... 6=Sat'");

// Leave type -> short grid code (extend as needed)
$LEAVE_CODE = [
    'Annual Leave'         => 'AL',
    'Casual Leave'         => 'CL',
    'Medical Leave'        => 'SL',
    'Public Holiday Leave' => 'PH',
];

// Codes an HR user can pick when overriding a cell
$CODE_OPTIONS = ['P','A','DO','WOD','WPH','AL','CL','SL','PH','H','W','R&R1','NL',''];

$company_name = 'YELO GROUP';
if (isset($_SESSION['hms_company']) && tableExists($conn, 'companies')) {
    $cc = mysqli_query($conn, "SELECT company_name FROM companies WHERE company_code = '" . mysqli_real_escape_string($conn, $_SESSION['hms_company']) . "' LIMIT 1");
    if ($cc && $row = mysqli_fetch_assoc($cc)) $company_name = $row['company_name'];
}

// ── Compute the display code for one employee on one date ──────────────────
function computeCode($conn, $emp, $dateStr, $LEAVE_CODE, $attTableRef) {
    // NOTE: manual overrides are checked first in resolveCode() below via the
    // bulk-loaded $manual_map, before this function is ever called.

    // 1. Before joining date -> blank
    if (!empty($emp['date_of_join']) && $dateStr < $emp['date_of_join']) return '';

    // 3. Fingerprint / biometric attendance present -> Present
    if ($attTableRef) {
        $code = mysqli_real_escape_string($conn, $emp['employee_id']);
        try {
            $r = @mysqli_query($conn, "SELECT 1 FROM $attTableRef
                                       WHERE person_id = '$code' AND attendance_date = '$dateStr' LIMIT 1");
            if ($r && mysqli_num_rows($r) > 0) return 'P';
        } catch (\Throwable $e) {
            // DB user lacks access to this table on this environment — skip
            // the biometric check silently rather than fatal-erroring.
        }
    }

    // 4. Approved leave covering this date
    $lv = @mysqli_query($conn, "SELECT leave_type FROM leave_applications
                                WHERE employee_id = {$emp['id']} AND status = 'Approved'
                                AND '$dateStr' BETWEEN start_date AND end_date LIMIT 1");
    if ($lv && $row = mysqli_fetch_assoc($lv)) {
        return $LEAVE_CODE[$row['leave_type']] ?? 'AL';
    }

    // 5. Public / bank holiday
    static $holidaySet = null;
    if ($holidaySet === null) {
        $holidaySet = [];
        foreach (['public_holidays', 'bank_holidays'] as $t) {
            if (tableExists($conn, $t)) {
                $hr = mysqli_query($conn, "SELECT holiday_date FROM `$t` WHERE active = 1");
                while ($hr && $hh = mysqli_fetch_assoc($hr)) $holidaySet[$hh['holiday_date']] = true;
            }
        }
    }
    if (isset($holidaySet[$dateStr])) return 'H';

    // 6. Weekly off day (default Sunday = 0 if not configured per-employee)
    $weekday = (int)date('w', strtotime($dateStr)); // 0=Sun...6=Sat
    $off = $emp['weekly_off_day'] !== null ? (int)$emp['weekly_off_day'] : 0;
    if ($weekday === $off) return 'WOD';

    // 7. No data
    return '';
}

// ── AJAX: save a manual cell override ───────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'save_code') {
    ob_clean();
    header('Content-Type: application/json');
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $att_date    = mysqli_real_escape_string($conn, $_POST['att_date'] ?? '');
    $code        = mysqli_real_escape_string($conn, trim($_POST['code'] ?? ''));
    $remark      = mysqli_real_escape_string($conn, trim($_POST['remark'] ?? ''));
    if (!$employee_id || !$att_date) { echo json_encode(['ok' => false, 'msg' => 'Missing employee/date']); exit; }

    if ($code === '') {
        mysqli_query($conn, "DELETE FROM attendance_monthly_codes WHERE employee_id=$employee_id AND att_date='$att_date'");
    } else {
        mysqli_query($conn, "INSERT INTO attendance_monthly_codes (employee_id, att_date, code, remark, updated_by)
                              VALUES ($employee_id, '$att_date', '$code', " . ($remark !== '' ? "'$remark'" : "NULL") . ", 'hms_user')
                              ON DUPLICATE KEY UPDATE code = VALUES(code), remark = VALUES(remark), updated_by = VALUES(updated_by)");
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── Filters ──────────────────────────────────────────────────────────────────
$month           = max(1, min(12, intval($_GET['month'] ?? date('n'))));
$year            = intval($_GET['year'] ?? date('Y'));
$filter_category = intval($_GET['staff_category_id'] ?? 0);
$filter_search   = trim($_GET['search'] ?? '');

$days_in_month = (int)date('t', strtotime("$year-$month-01"));

// Figure out where the fingerprint attendance_records table actually lives.
// On some hosts (e.g. shared hosting) the DB user only has grants on its own
// database, so attendance_system.* is unreachable even if that DB exists.
$attTableRef = null;
if (tableExists($conn, 'attendance_records')) {
    $attTableRef = 'attendance_records'; // same database as everything else
} elseif (tableExists($conn, 'attendance_system.attendance_records')) {
    $attTableRef = 'attendance_system.attendance_records';
}

// ── Pull employees (active only), grouped by staff category ────────────────
$where = ["(e.status IS NULL OR e.status NOT IN ('Resigned','Terminated'))"];
if ($filter_category > 0) $where[] = "e.staff_category_id = $filter_category";
if ($filter_search !== '') {
    $s = mysqli_real_escape_string($conn, $filter_search);
    $where[] = "(e.employee_id LIKE '%$s%' OR e.employee_full_name LIKE '%$s%')";
}
$where_sql = implode(' AND ', $where);

$emp_sql = "SELECT e.id, e.employee_id, e.employee_full_name, e.date_of_join, e.weekly_off_day,
                   d.designation_name,
                   COALESCE(sc.category_name, 'Unassigned') AS category_name,
                   COALESCE(sc.category_code, 'GEN')        AS category_code
            FROM employees e
            LEFT JOIN designations d ON e.designation_id = d.id
            LEFT JOIN staff_categories sc ON e.staff_category_id = sc.id
            WHERE $where_sql
            ORDER BY category_name, e.employee_full_name";
$emp_res = mysqli_query($conn, $emp_sql);
$groups = [];
while ($row = mysqli_fetch_assoc($emp_res)) {
    $groups[$row['category_name']][] = $row;
}

// Manual override map (bulk-loaded once, avoids per-cell query)
$manual_map = [];
$mr = mysqli_query($conn, "SELECT employee_id, att_date, code FROM attendance_monthly_codes
                            WHERE att_date BETWEEN '$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01' AND '$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-$days_in_month'");
while ($mr && $row = mysqli_fetch_assoc($mr)) {
    $manual_map[$row['employee_id'] . '|' . $row['att_date']] = $row['code'];
}

// Override computeCode's static cache with the bulk-loaded manual map
function resolveCode($conn, $emp, $dateStr, $LEAVE_CODE, $attTableRef, $manual_map) {
    $k = $emp['id'] . '|' . $dateStr;
    if (isset($manual_map[$k])) return $manual_map[$k];
    return computeCode($conn, $emp, $dateStr, $LEAVE_CODE, $attTableRef);
}

// Categories for filter dropdown
$cat_options = [];
$cr = mysqli_query($conn, "SELECT id, category_name FROM staff_categories WHERE active = 1 ORDER BY category_name");
while ($cr && $row = mysqli_fetch_assoc($cr)) $cat_options[] = $row;

$month_names = ['', 'JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
$weekday_abbr = ['SUN','MON','TUE','WED','THU','FRI','SAT'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Monthly Attendance Report</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 20px; color: #111; background: #f4f5f7; }
  .toolbar {
    background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 14px 18px;
    margin-bottom: 16px; display: flex; gap: 12px; flex-wrap: wrap; align-items: end;
  }
  .toolbar label { display: block; font-size: 11px; color: #555; margin-bottom: 3px; font-weight: 600; }
  .toolbar select, .toolbar input[type=text] {
    padding: 6px 8px; border: 1px solid #ccc; border-radius: 5px; font-size: 13px;
  }
  .toolbar button {
    padding: 8px 16px; border: none; border-radius: 5px; background: #1f2937; color: #fff;
    font-size: 13px; cursor: pointer;
  }
  .toolbar button.secondary { background: #6b7280; }
  .sheet {
    background: #fff; border: 1px solid #ddd; padding: 20px; overflow-x: auto;
  }
  .header { text-align: center; margin-bottom: 4px; font-family: Georgia, serif; }
  .header .brand { font-size: 22px; letter-spacing: 3px; }
  .title {
    text-align: center; font-size: 14px; font-weight: bold; letter-spacing: 1px;
    margin: 6px 0 12px;
  }
  table { border-collapse: collapse; width: 100%; font-size: 10px; }
  th, td { border: 1px solid #000; text-align: center; padding: 2px 3px; white-space: nowrap; }
  th.col-empid, td.col-empid { width: 55px; }
  th.col-name, td.col-name { width: 150px; white-space: normal; text-align: left; }
  th.col-desig, td.col-desig { width: 120px; white-space: normal; text-align: left; }
  th.col-doj, td.col-doj { width: 60px; }
  th.day-col, td.day-col { width: 24px; }
  th.col-sig, td.col-sig { width: 55px; }
  .day-num { font-weight: bold; }
  .day-name { font-size: 8px; font-weight: normal; }
  .dept-row td { text-align: left; font-weight: bold; background: #f2f2f2; padding: 3px 6px; }
  td.day-col { cursor: pointer; }
  td.day-col:hover { background: #fff7d6; }
  td.day-col.weekend { background: #fafafa; }
  .cell-editor {
    position: absolute; z-index: 50; background: #fff; border: 1px solid #333; border-radius: 4px;
    box-shadow: 0 4px 14px rgba(0,0,0,.2); padding: 6px; display: none;
  }
  .cell-editor select { font-size: 12px; padding: 3px; }
  @media print {
    body { background: #fff; padding: 0; }
    .toolbar { display: none; }
    .sheet { border: none; padding: 0; }
    @page { size: A3 landscape; margin: 10mm; }
  }
</style>
</head>
<body>

  <form class="toolbar" method="get" id="filterForm">
    <div>
      <label>Month</label>
      <select name="month">
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>><?= $month_names[$m] ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div>
      <label>Year</label>
      <select name="year">
        <?php for ($y = date('Y') - 2; $y <= date('Y') + 2; $y++): ?>
          <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div>
      <label>Staff Category</label>
      <select name="staff_category_id">
        <option value="0">All Categories</option>
        <?php foreach ($cat_options as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $c['id'] == $filter_category ? 'selected' : '' ?>><?= h($c['category_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Search</label>
      <input type="text" name="search" value="<?= h($filter_search) ?>" placeholder="Emp ID or name">
    </div>
    <div><button type="submit">Apply</button></div>
    <div><button type="button" class="secondary" onclick="window.print()">Print</button></div>
    <div><button type="button" class="secondary" onclick="exportCSV()">Export CSV</button></div>
  </form>

  <div class="sheet">
    <div class="header"><div class="brand"><?= h($company_name) ?></div></div>
    <div class="title">ATTENDANCE FOR THE MONTH OF <?= $month_names[$month] ?> - <?= $year ?></div>

    <table id="attTable">
      <thead>
        <tr>
          <th class="col-empid" rowspan="2">Emp ID</th>
          <th class="col-name" rowspan="2">Full Name</th>
          <th class="col-desig" rowspan="2">Designation</th>
          <th class="col-doj" rowspan="2">DOJ</th>
          <?php for ($d = 1; $d <= $days_in_month; $d++): ?>
            <th class="day-col day-num"><?= str_pad($d, 2, '0', STR_PAD_LEFT) ?></th>
          <?php endfor; ?>
          <th class="col-sig" rowspan="2">Signature</th>
        </tr>
        <tr>
          <?php for ($d = 1; $d <= $days_in_month; $d++):
              $wd = (int)date('w', strtotime("$year-$month-$d")); ?>
            <th class="day-col day-name"><?= $weekday_abbr[$wd] ?></th>
          <?php endfor; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($groups)): ?>
          <tr><td colspan="<?= 5 + $days_in_month ?>">No employees found for the selected filters.</td></tr>
        <?php endif; ?>
        <?php foreach ($groups as $category_name => $emps): ?>
          <tr class="dept-row"><td colspan="<?= 5 + $days_in_month ?>"><?= h(strtoupper($category_name)) ?></td></tr>
          <?php foreach ($emps as $emp): ?>
            <tr>
              <td class="col-empid"><?= h($emp['employee_id']) ?></td>
              <td class="col-name"><?= h($emp['employee_full_name']) ?></td>
              <td class="col-desig"><?= h($emp['designation_name'] ?? '') ?></td>
              <td class="col-doj"><?= $emp['date_of_join'] ? date('d-M-y', strtotime($emp['date_of_join'])) : '' ?></td>
              <?php for ($d = 1; $d <= $days_in_month; $d++):
                  $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
                  $code = resolveCode($conn, $emp, $dateStr, $LEAVE_CODE, $attTableRef, $manual_map);
                  $wd = (int)date('w', strtotime($dateStr));
                  $weekendClass = ($wd === 0 || $wd === 6) ? ' weekend' : '';
              ?>
                <td class="day-col<?= $weekendClass ?>" data-emp="<?= $emp['id'] ?>" data-date="<?= $dateStr ?>" onclick="editCell(this)"><?= h($code) ?></td>
              <?php endfor; ?>
              <td class="col-sig"></td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="cell-editor" id="cellEditor">
    <select id="cellCodeSelect">
      <?php foreach ($CODE_OPTIONS as $opt): ?>
        <option value="<?= h($opt) ?>"><?= $opt === '' ? '(blank)' : h($opt) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="button" onclick="saveCell()">Save</button>
    <button type="button" onclick="closeEditor()">Cancel</button>
  </div>

<script>
let _activeCell = null;
const SELF_URL = window.location.pathname;

function editCell(td) {
  _activeCell = td;
  const editor = document.getElementById('cellEditor');
  const rect = td.getBoundingClientRect();
  editor.style.left = (window.scrollX + rect.left) + 'px';
  editor.style.top  = (window.scrollY + rect.bottom + 4) + 'px';
  editor.style.display = 'block';
  document.getElementById('cellCodeSelect').value = td.textContent.trim();
}
function closeEditor() {
  document.getElementById('cellEditor').style.display = 'none';
  _activeCell = null;
}
function saveCell() {
  if (!_activeCell) return;
  const code = document.getElementById('cellCodeSelect').value;
  const emp  = _activeCell.getAttribute('data-emp');
  const date = _activeCell.getAttribute('data-date');
  fetch(SELF_URL + '?ajax=save_code', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'employee_id=' + encodeURIComponent(emp) + '&att_date=' + encodeURIComponent(date) + '&code=' + encodeURIComponent(code)
  }).then(r => r.json()).then(d => {
    if (d.ok) { _activeCell.textContent = code; closeEditor(); }
    else { alert(d.msg || 'Save failed'); }
  });
}
document.addEventListener('click', function(e) {
  const editor = document.getElementById('cellEditor');
  if (editor.style.display === 'block' && !editor.contains(e.target) && e.target.className.indexOf('day-col') === -1) {
    closeEditor();
  }
});

function exportCSV() {
  const table = document.getElementById('attTable');
  let csv = [];
  for (const row of table.rows) {
    const cells = Array.from(row.cells).map(c => '"' + c.textContent.trim().replace(/"/g,'""') + '"');
    csv.push(cells.join(','));
  }
  const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'attendance_<?= $month_names[$month] ?>_<?= $year ?>.csv';
  a.click();
}
</script>

</body>
</html>
<?php ob_end_flush(); ?>