<?php
include 'config.php';

// ── Handle AJAX bulk save ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_save'])) {
    $updates  = $_POST['employees'] ?? [];
    $updated  = 0;
    $errors   = [];

    foreach ($updates as $emp_id => $fields) {
        $emp_id = intval($emp_id);
        $sets   = [];

        $money_cols = [
            'insurance_amount', 'welfare_amount',
            'attendance_allowance',
            'reimbursement_meal', 'reimbursement_traveling', 'reimbursement_other'
        ];

        foreach ($money_cols as $col) {
            if (array_key_exists($col, $fields)) {
                $val = trim($fields[$col]);
                $sets[] = "$col = " . ($val === '' ? "NULL" : floatval($val));
            }
        }

        if (empty($sets)) continue;

        $sql = "UPDATE employees SET " . implode(', ', $sets) . " WHERE id = $emp_id";
        if (mysqli_query($conn, $sql)) {
            $updated++;
        } else {
            $errors[] = "Employee $emp_id: " . mysqli_error($conn);
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'updated' => $updated, 'errors' => $errors]);
    exit;
}

// ── Filters ───────────────────────────────────────────────────
$filter_company      = !empty($_GET['filter_company'])      ? intval($_GET['filter_company'])                                  : '';
$filter_branch       = !empty($_GET['filter_branch'])       ? intval($_GET['filter_branch'])                                   : '';
$filter_designation  = !empty($_GET['filter_designation'])  ? intval($_GET['filter_designation'])                              : '';
$filter_staff_cat    = !empty($_GET['filter_staff_cat'])    ? intval($_GET['filter_staff_cat'])                                : '';
$filter_status       = !empty($_GET['filter_status'])       ? mysqli_real_escape_string($conn, $_GET['filter_status'])         : '';

$where = [];
if ($filter_company)     $where[] = "e.company_id = $filter_company";
if ($filter_branch)      $where[] = "e.branch_id = $filter_branch";
if ($filter_designation) $where[] = "e.designation_id = $filter_designation";
if ($filter_staff_cat)   $where[] = "e.staff_category_id = $filter_staff_cat";
if ($filter_status)      $where[] = "e.status = '$filter_status'";
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Alter table to add new columns if missing ─────────────────
$alter_cols = [
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS attendance_allowance    DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS reimbursement_meal      DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS reimbursement_traveling DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS reimbursement_other     DECIMAL(12,2) NULL",
];
foreach ($alter_cols as $sql) mysqli_query($conn, $sql);

// ── Fetch employees ───────────────────────────────────────────
$sql = "SELECT e.id, e.employee_id, e.employee_full_name, e.status,
               e.insurance_amount, e.welfare_amount,
               e.attendance_allowance,
               e.reimbursement_meal, e.reimbursement_traveling, e.reimbursement_other,
               c.company_code, c.company_name,
               b.branch_name,
               d.designation_name,
               sc.category_name
        FROM employees e
        LEFT JOIN companies     c  ON e.company_id       = c.id
        LEFT JOIN branches      b  ON e.branch_id        = b.id
        LEFT JOIN designations  d  ON e.designation_id   = d.id
        LEFT JOIN staff_categories sc ON e.staff_category_id = sc.id
        $where_sql
        ORDER BY e.employee_id";
$result    = mysqli_query($conn, $sql);
$employees = [];
while ($row = mysqli_fetch_assoc($result)) $employees[] = $row;

// ── Dropdown data ─────────────────────────────────────────────
$companies   = mysqli_query($conn, "SELECT id, company_code, company_name FROM companies WHERE active=1 ORDER BY company_name");
$designations= mysqli_query($conn, "SELECT id, designation_name FROM designations WHERE active=1 ORDER BY designation_name");
$staff_cats  = mysqli_query($conn, "SELECT id, category_name FROM staff_categories WHERE active=1 ORDER BY category_name");

include 'header.php';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
</head>

<style>
/* ═══════════════════════════════════
   VARIABLES & RESET
═══════════════════════════════════ */
:root {
    --bg:        #f4f4f0;
    --surface:   #ffffff;
    --border:    #e0dfd8;
    --border2:   #cccac0;
    --ink:       #1a1a18;
    --ink2:      #5a5a54;
    --ink3:      #9a9a90;
    --accent:    #1a1a18;
    --green:     #0f7240;
    --green-bg:  #e8f5ee;
    --amber:     #b85c00;
    --amber-bg:  #fff3e0;
    --red:       #c0392b;
    --red-bg:    #fdecea;
    --blue:      #1a4fa0;
    --blue-bg:   #e8f0fc;
    --mono:      'IBM Plex Mono', monospace;
    --sans:      'IBM Plex Sans', sans-serif;
    --radius:    8px;
    --radius-lg: 14px;
    --shadow:    0 1px 3px rgba(0,0,0,.08), 0 4px 12px rgba(0,0,0,.04);
}

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body { font-family: var(--sans); background: var(--bg); color: var(--ink); }

/* ═══════════════════════════════════
   PAGE HEADER
═══════════════════════════════════ */
.bsu-header {
    background: var(--ink);
    color: #fff;
    padding: 24px 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 28px;
    border-radius: 0 0 var(--radius-lg) var(--radius-lg);
}
.bsu-header-left { display: flex; flex-direction: column; gap: 4px; }
.bsu-title { font-size: 22px; font-weight: 700; letter-spacing: -.3px; }
.bsu-subtitle { font-size: 13px; color: #a0a09a; }
.bsu-header-right { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

.btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 10px 20px; border-radius: var(--radius);
    font-size: 13px; font-weight: 600; font-family: var(--sans);
    cursor: pointer; transition: all .18s; border: none; text-decoration: none;
}
.btn-white   { background: #fff; color: var(--ink); }
.btn-white:hover { background: #f0f0ea; }
.btn-green   { background: #22c55e; color: #fff; }
.btn-green:hover { background: #16a34a; }
.btn-outline { background: transparent; color: #fff; border: 1.5px solid rgba(255,255,255,.3); }
.btn-outline:hover { background: rgba(255,255,255,.08); }
.btn-sm      { padding: 7px 14px; font-size: 12px; }
.btn-dark    { background: var(--ink); color: #fff; }
.btn-dark:hover { background: #333; }
.btn-ghost   { background: var(--bg); color: var(--ink2); border: 1px solid var(--border); }
.btn-ghost:hover { background: var(--border); }

/* ═══════════════════════════════════
   FILTERS CARD
═══════════════════════════════════ */
.filters-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    margin: 0 32px 20px;
    overflow: hidden;
    box-shadow: var(--shadow);
}
.filters-toggle {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 20px; cursor: pointer; user-select: none;
    font-size: 13px; font-weight: 600; color: var(--ink2);
}
.filters-toggle:hover { background: var(--bg); }
.filters-toggle .chevron { transition: transform .25s; font-size: 11px; }
.filters-toggle.open .chevron { transform: rotate(180deg); }
.filters-badge {
    background: var(--ink); color: #fff;
    border-radius: 20px; padding: 1px 8px; font-size: 11px; font-weight: 700;
}

.filters-body { display: none; padding: 0 20px 20px; border-top: 1px solid var(--border); }
.filters-body.open { display: block; }

.filter-grid {
    display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px;
    margin-top: 16px;
}
.filter-group label {
    display: block; font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .6px; color: var(--ink3); margin-bottom: 6px;
}
.filter-select, .filter-input-f {
    width: 100%; padding: 9px 12px; border: 1px solid var(--border);
    border-radius: var(--radius); font-size: 13px; font-family: var(--sans);
    background: #fff; color: var(--ink); transition: border-color .15s;
}
.filter-select:focus, .filter-input-f:focus {
    outline: none; border-color: var(--ink); box-shadow: 0 0 0 3px rgba(0,0,0,.06);
}
.filter-actions { display: flex; gap: 10px; margin-top: 16px; }

/* ═══════════════════════════════════
   STATS BAR
═══════════════════════════════════ */
.stats-bar {
    display: flex; gap: 12px; margin: 0 32px 20px; flex-wrap: wrap;
}
.stat-pill {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 30px; padding: 7px 16px;
    display: flex; align-items: center; gap: 8px;
    font-size: 13px; font-weight: 500; color: var(--ink2);
    box-shadow: var(--shadow);
}
.stat-pill strong { color: var(--ink); font-weight: 700; }
.stat-pill .dot { width:8px;height:8px;border-radius:50%;flex-shrink:0; }
.dot-green { background: #22c55e; }
.dot-amber { background: #f59e0b; }

/* ═══════════════════════════════════
   COMMON FILL BAR
═══════════════════════════════════ */
.fill-bar {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    margin: 0 32px 16px;
    box-shadow: var(--shadow);
    overflow: hidden;
}
.fill-bar-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 20px; background: #fafaf8; border-bottom: 1px solid var(--border);
}
.fill-bar-title { font-size: 13px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 8px; }
.fill-bar-hint  { font-size: 11px; color: var(--ink3); }

.fill-bar-body  { padding: 16px 20px; }
.fill-sections  { display: flex; flex-direction: column; gap: 14px; }

/* Section within fill bar */
.fill-section-label {
    font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .7px;
    color: var(--ink3); margin-bottom: 10px;
    display: flex; align-items: center; gap: 6px;
}
.fill-section-label::after {
    content: ''; flex: 1; height: 1px; background: var(--border);
}
.fill-cols { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
.fill-col  { display: flex; flex-direction: column; gap: 5px; }
.fill-col label { font-size: 11px; font-weight: 600; color: var(--ink2); }
.fill-col-input-wrap { display: flex; align-items: center; border: 1.5px solid var(--border); border-radius: 7px; overflow: hidden; transition: border-color .15s; }
.fill-col-input-wrap:focus-within { border-color: var(--ink); box-shadow: 0 0 0 3px rgba(0,0,0,.06); }
.fill-prefix { padding: 0 10px; font-size: 11px; font-weight: 700; color: var(--ink3); background: var(--bg); height: 36px; display: flex; align-items: center; border-right: 1px solid var(--border); white-space: nowrap; font-family: var(--mono); }
.fill-input  { border: none; padding: 0 10px; height: 36px; font-size: 13px; font-family: var(--mono); color: var(--ink); width: 130px; background: #fff; }
.fill-input:focus { outline: none; }
.fill-apply-btn { padding: 8px 14px; border: none; border-radius: 6px; background: var(--ink); color: #fff; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; font-family: var(--sans); transition: background .15s; }
.fill-apply-btn:hover { background: #333; }
.fill-apply-btn.col-green { background: var(--green); }
.fill-apply-btn.col-green:hover { background: #0a5c33; }

/* ═══════════════════════════════════
   TABLE CARD
═══════════════════════════════════ */
.table-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    margin: 0 32px 32px;
    box-shadow: var(--shadow);
    overflow: hidden;
}
.table-card-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid var(--border);
    background: #fafaf8; flex-wrap: wrap; gap: 10px;
}
.table-card-title { font-size: 14px; font-weight: 700; color: var(--ink); }
.table-card-actions { display: flex; gap: 8px; align-items: center; }

/* Select all checkbox area */
.select-all-wrap { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--ink2); font-weight: 500; }
.select-info { font-size: 12px; color: var(--ink3); }
#selectedCount { color: var(--ink); font-weight: 700; }

/* ═══════════════════════════════════
   TABLE ITSELF
═══════════════════════════════════ */
.bsu-table-wrap { overflow-x: auto; }
.bsu-table {
    width: 100%; border-collapse: collapse;
    font-size: 13px; min-width: 1400px;
}
.bsu-table thead th {
    padding: 10px 12px; text-align: left;
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .5px; color: var(--ink3);
    background: #fafaf8; border-bottom: 2px solid var(--border);
    position: sticky; top: 0; white-space: nowrap;
}
.bsu-table thead th.col-group-allowance { background: #f0faf5; color: var(--green); }
.bsu-table thead th.col-group-reimb     { background: #f0f5ff; color: var(--blue); }
.bsu-table thead th.col-group-header    {
    text-align: center; font-size: 11px; padding: 6px 12px;
    border-bottom: 1px solid var(--border);
}
.bsu-table thead .col-group-header.g-allowance { background: #e8f5ee; color: var(--green); border-bottom-color: #b2dfcc; }
.bsu-table thead .col-group-header.g-reimb     { background: #e8f0fc; color: var(--blue);  border-bottom-color: #b8cef0; }
.bsu-table thead .col-group-header.g-fixed     { background: #fafaf8; }

.bsu-table tbody tr { border-bottom: 1px solid #f2f2ee; transition: background .12s; }
.bsu-table tbody tr:hover { background: #f9f9f6; }
.bsu-table tbody tr.row-selected { background: #fffbeb; }
.bsu-table tbody td { padding: 10px 12px; vertical-align: middle; }

/* Sticky first cols */
.bsu-table .col-check { width: 42px; }
.bsu-table .col-empid { font-family: var(--mono); font-size: 12px; font-weight: 600; color: var(--ink); white-space: nowrap; }
.bsu-table .col-name  { font-weight: 600; white-space: nowrap; max-width: 180px; overflow: hidden; text-overflow: ellipsis; }
.bsu-table .col-meta  { font-size: 11px; color: var(--ink3); }

/* Money inputs in table */
.money-cell { padding: 6px 12px; }
.money-wrap {
    display: flex; align-items: center;
    border: 1.5px solid var(--border); border-radius: 7px;
    overflow: hidden; transition: border-color .15s; background: #fff;
    min-width: 120px;
}
.money-wrap:focus-within { border-color: var(--ink); box-shadow: 0 0 0 2px rgba(0,0,0,.06); }
.money-wrap.col-allow:focus-within { border-color: var(--green); box-shadow: 0 0 0 2px rgba(15,114,64,.1); }
.money-wrap.col-reimb:focus-within  { border-color: var(--blue);  box-shadow: 0 0 0 2px rgba(26,79,160,.1); }
.money-sym { padding: 0 7px; font-size: 10px; font-weight: 700; color: var(--ink3); background: var(--bg); height: 34px; display: flex; align-items: center; border-right: 1px solid var(--border); font-family: var(--mono); }
.money-input {
    border: none; padding: 0 8px; height: 34px;
    font-size: 12px; font-family: var(--mono); color: var(--ink);
    width: 90px; background: #fff;
}
.money-input:focus { outline: none; }
.money-input::placeholder { color: #ccc; }

/* Column group coloring */
.col-insurance td, .col-welfare td { /* base */ }
td.col-allow-cell { background: rgba(15,114,64,.03); }
td.col-reimb-cell { background: rgba(26,79,160,.03); }

/* Status badge */
.status-badge {
    display: inline-block; padding: 2px 9px; border-radius: 20px;
    font-size: 11px; font-weight: 600;
}
.s-probation  { background: #fef3c7; color: #92400e; }
.s-permanent  { background: #dcfce7; color: #15803d; }
.s-resigned   { background: #f3f4f6; color: #6b7280; }
.s-terminated { background: #fee2e2; color: #991b1b; }

/* Designation + category badge */
.desg-badge {
    display: inline-block; padding: 2px 8px; border-radius: 4px;
    font-size: 10px; font-weight: 600; background: var(--blue-bg); color: var(--blue);
    white-space: nowrap;
}
.cat-badge {
    display: inline-block; padding: 2px 8px; border-radius: 4px;
    font-size: 10px; font-weight: 600; background: var(--amber-bg); color: var(--amber);
    white-space: nowrap; margin-top: 3px;
}

/* ═══════════════════════════════════
   EMPTY STATE
═══════════════════════════════════ */
.empty-state {
    text-align: center; padding: 64px 32px;
    color: var(--ink3);
}
.empty-state i { font-size: 40px; margin-bottom: 14px; display: block; }
.empty-state p { font-size: 14px; }

/* ═══════════════════════════════════
   TOAST
═══════════════════════════════════ */
#toast {
    position: fixed; bottom: 32px; right: 32px; z-index: 9999;
    background: var(--ink); color: #fff;
    padding: 14px 22px; border-radius: 10px;
    font-size: 14px; font-weight: 600; font-family: var(--sans);
    display: flex; align-items: center; gap: 10px;
    box-shadow: 0 8px 32px rgba(0,0,0,.22);
    transform: translateY(80px); opacity: 0;
    transition: all .35s cubic-bezier(.4,0,.2,1);
    pointer-events: none;
}
#toast.show { transform: translateY(0); opacity: 1; }
#toast.toast-success { background: #15803d; }
#toast.toast-error   { background: #c0392b; }

/* ═══════════════════════════════════
   SAVING OVERLAY
═══════════════════════════════════ */
#savingOverlay {
    display: none; position: fixed; inset: 0; z-index: 9998;
    background: rgba(0,0,0,.35); align-items: center; justify-content: center;
}
#savingOverlay.show { display: flex; }
.saving-box {
    background: #fff; border-radius: 14px; padding: 32px 40px;
    text-align: center; box-shadow: 0 16px 48px rgba(0,0,0,.2);
}
.saving-spinner {
    width: 40px; height: 40px; border: 3px solid #e5e5e5;
    border-top-color: var(--ink); border-radius: 50%;
    animation: spin .7s linear infinite; margin: 0 auto 14px;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ═══════════════════════════════════
   RESPONSIVE
═══════════════════════════════════ */
@media (max-width: 900px) {
    .filters-card, .stats-bar, .fill-bar, .table-card { margin-left: 16px; margin-right: 16px; }
    .bsu-header { padding: 18px 16px; }
    .filter-grid { grid-template-columns: repeat(2,1fr); }
}
@media (max-width: 640px) {
    .filter-grid { grid-template-columns: 1fr; }
    .fill-cols { flex-direction: column; }
    .fill-input { width: 100%; }
}

/* checkbox style */
input[type="checkbox"] {
    width: 16px; height: 16px; accent-color: var(--ink);
    cursor: pointer;
}
</style>

<!-- ═══════════════════════════════════════
     PAGE HEADER
═══════════════════════════════════════ -->
<div class="bsu-header">
    <div class="bsu-header-left">
        <div class="bsu-title"><i class="fa-solid fa-coins"></i> &nbsp;Bulk Salary Update</div>
        <div class="bsu-subtitle">Update allowances &amp; reimbursements for multiple employees at once</div>
    </div>
    <div class="bsu-header-right">
        <a href="employees.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <button class="btn btn-white" onclick="saveAll()"><i class="fa-solid fa-floppy-disk"></i> Save All Changes</button>
        <button class="btn btn-green" onclick="saveSelected()"><i class="fa-solid fa-check"></i> Save Selected</button>
    </div>
</div>

<!-- ═══════════════════════════════════════
     FILTERS
═══════════════════════════════════════ -->
<div class="filters-card">
    <div class="filters-toggle" id="filtersToggle" onclick="toggleFilters()">
        <div style="display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-sliders"></i>
            <span>Filters</span>
            <?php
            $active_f = array_filter([$filter_company,$filter_branch,$filter_designation,$filter_staff_cat,$filter_status]);
            if (count($active_f)): ?>
            <span class="filters-badge"><?php echo count($active_f); ?></span>
            <?php endif; ?>
        </div>
        <i class="fa-solid fa-chevron-down chevron"></i>
    </div>
    <div class="filters-body <?php echo count($active_f) ? 'open' : ''; ?>" id="filtersBody">
        <form method="GET" action="">
            <div class="filter-grid">
                <!-- Company -->
                <div class="filter-group">
                    <label>Company</label>
                    <select name="filter_company" class="filter-select" onchange="loadBranches(this.value)">
                        <option value="">All Companies</option>
                        <?php mysqli_data_seek($companies, 0); while ($c = mysqli_fetch_assoc($companies)): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $filter_company==$c['id']?'selected':''; ?>>
                            <?php echo htmlspecialchars($c['company_code'].' - '.$c['company_name']); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <!-- Branch -->
                <div class="filter-group">
                    <label>Branch</label>
                    <select name="filter_branch" id="filter_branch" class="filter-select">
                        <option value="">All Branches</option>
                    </select>
                </div>
                <!-- Designation -->
                <div class="filter-group">
                    <label>Designation</label>
                    <select name="filter_designation" class="filter-select">
                        <option value="">All Designations</option>
                        <?php mysqli_data_seek($designations, 0); while ($d = mysqli_fetch_assoc($designations)): ?>
                        <option value="<?php echo $d['id']; ?>" <?php echo $filter_designation==$d['id']?'selected':''; ?>>
                            <?php echo htmlspecialchars($d['designation_name']); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <!-- Staff Category -->
                <div class="filter-group">
                    <label>Staff Category</label>
                    <select name="filter_staff_cat" class="filter-select">
                        <option value="">All Categories</option>
                        <?php mysqli_data_seek($staff_cats, 0); while ($sc = mysqli_fetch_assoc($staff_cats)): ?>
                        <option value="<?php echo $sc['id']; ?>" <?php echo $filter_staff_cat==$sc['id']?'selected':''; ?>>
                            <?php echo htmlspecialchars($sc['category_name']); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <!-- Status -->
                <div class="filter-group">
                    <label>Status</label>
                    <select name="filter_status" class="filter-select">
                        <option value="">All Status</option>
                        <option value="Probation"  <?php echo $filter_status=='Probation'?'selected':'';  ?>>Probation</option>
                        <option value="Permanent"  <?php echo $filter_status=='Permanent'?'selected':'';  ?>>Permanent</option>
                        <option value="Resigned"   <?php echo $filter_status=='Resigned'?'selected':'';   ?>>Resigned</option>
                        <option value="Terminated" <?php echo $filter_status=='Terminated'?'selected':''; ?>>Terminated</option>
                    </select>
                </div>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-dark btn-sm"><i class="fa-solid fa-filter"></i> Apply</button>
                <a href="bulk_salary_update.php" class="btn btn-ghost btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════
     STATS BAR
═══════════════════════════════════════ -->
<div class="stats-bar">
    <div class="stat-pill">
        <span class="dot dot-green"></span>
        <span><strong><?php echo count($employees); ?></strong> employees loaded</span>
    </div>
    <div class="stat-pill" id="selectedPill" style="display:none;">
        <span class="dot dot-amber"></span>
        <span><strong id="selectedStatCount">0</strong> selected</span>
    </div>
</div>

<!-- ═══════════════════════════════════════
     COMMON FILL BAR
═══════════════════════════════════════ -->
<div class="fill-bar">
    <div class="fill-bar-header">
        <div>
            <div class="fill-bar-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Fill Common Amount</div>
            <div class="fill-bar-hint">Enter a value and click Apply to fill that column for all visible / selected employees</div>
        </div>
    </div>
    <div class="fill-bar-body">
        <div class="fill-sections">

            <!-- Fixed amounts -->
            <div>
                <div class="fill-section-label">Fixed Amounts</div>
                <div class="fill-cols">
                    <?php
                    $fixed_fills = [
                        ['col'=>'insurance_amount',   'label'=>'Insurance',   'cls'=>''],
                        ['col'=>'welfare_amount',      'label'=>'Welfare',     'cls'=>''],
                    ];
                    foreach ($fixed_fills as $ff): ?>
                    <div class="fill-col">
                        <label><?php echo $ff['label']; ?></label>
                        <div class="fill-col-input-wrap">
                            <span class="fill-prefix">LKR</span>
                            <input type="number" class="fill-input" id="fill_<?php echo $ff['col']; ?>" placeholder="0.00" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="fill-col" style="justify-content:flex-end;">
                        <button class="fill-apply-btn" onclick="applyCommon('<?php echo $ff['col']; ?>')">Apply to All</button>
                    </div>
                    <div style="width:20px;"></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Attendance Allowance -->
            <div>
                <div class="fill-section-label"><i class="fa-solid fa-calendar-check" style="color:var(--green);font-size:11px;"></i> Attendance Allowance</div>
                <div class="fill-cols">
                    <div class="fill-col">
                        <label>Attendance Allowance</label>
                        <div class="fill-col-input-wrap">
                            <span class="fill-prefix">LKR</span>
                            <input type="number" class="fill-input" id="fill_attendance_allowance" placeholder="0.00" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="fill-col" style="justify-content:flex-end;">
                        <button class="fill-apply-btn col-green" onclick="applyCommon('attendance_allowance')">Apply to All</button>
                    </div>
                </div>
            </div>

            <!-- Reimbursements -->
            <div>
                <div class="fill-section-label"><i class="fa-solid fa-receipt" style="color:var(--blue);font-size:11px;"></i> Reimbursements</div>
                <div class="fill-cols">
                    <?php
                    $reimb_fills = [
                        ['col'=>'reimbursement_meal',       'label'=>'Meal'],
                        ['col'=>'reimbursement_traveling',  'label'=>'Traveling'],
                        ['col'=>'reimbursement_other',      'label'=>'Other'],
                    ];
                    foreach ($reimb_fills as $rf): ?>
                    <div class="fill-col">
                        <label><?php echo $rf['label']; ?></label>
                        <div class="fill-col-input-wrap">
                            <span class="fill-prefix">LKR</span>
                            <input type="number" class="fill-input" id="fill_<?php echo $rf['col']; ?>" placeholder="0.00" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="fill-col" style="justify-content:flex-end;">
                        <button class="fill-apply-btn" style="background:var(--blue);" onclick="applyCommon('<?php echo $rf['col']; ?>')">Apply</button>
                    </div>
                    <div style="width:10px;"></div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════
     TABLE
═══════════════════════════════════════ -->
<div class="table-card">
    <div class="table-card-header">
        <div class="table-card-title">
            Employee Salary Details
            <span style="font-weight:400;color:var(--ink3);font-size:12px;margin-left:8px;"><?php echo count($employees); ?> records</span>
        </div>
        <div class="table-card-actions">
            <div class="select-all-wrap">
                <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this.checked)" title="Select all">
                <label for="selectAll" style="cursor:pointer;">Select All</label>
                <span class="select-info">(<span id="selectedCount">0</span> selected)</span>
            </div>
        </div>
    </div>

    <div class="bsu-table-wrap">
        <?php if (empty($employees)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-users-slash"></i>
            <p>No employees found. Try adjusting your filters.</p>
        </div>
        <?php else: ?>
        <table class="bsu-table" id="bsuTable">
            <thead>
                <!-- Group row -->
                <tr>
                    <th colspan="5" class="col-group-header g-fixed">Employee Info</th>
                    <th colspan="2" class="col-group-header g-fixed">Fixed Amounts</th>
                    <th colspan="1" class="col-group-header g-allowance">Allowance</th>
                    <th colspan="3" class="col-group-header g-reimb">Reimbursements</th>
                </tr>
                <!-- Column headers -->
                <tr>
                    <th class="col-check"></th>
                    <th>Emp ID</th>
                    <th>Full Name</th>
                    <th>Designation / Category</th>
                    <th>Status</th>
                    <th>Insurance</th>
                    <th>Welfare</th>
                    <th class="col-group-allowance">Attendance Allowance</th>
                    <th class="col-group-reimb">Meal</th>
                    <th class="col-group-reimb">Traveling</th>
                    <th class="col-group-reimb">Other</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($employees as $emp):
                $sc = '';
                switch($emp['status']) {
                    case 'Probation':  $sc='s-probation';  break;
                    case 'Permanent':  $sc='s-permanent';  break;
                    case 'Resigned':   $sc='s-resigned';   break;
                    case 'Terminated': $sc='s-terminated'; break;
                }
                $fv = fn($v) => $v !== null && $v !== '' ? number_format((float)$v, 2, '.', '') : '';
            ?>
            <tr class="emp-row" data-id="<?php echo $emp['id']; ?>">
                <td class="col-check">
                    <input type="checkbox" class="row-check" value="<?php echo $emp['id']; ?>" onchange="updateSelectedCount()">
                </td>
                <td class="col-empid"><?php echo htmlspecialchars($emp['employee_id']); ?></td>
                <td class="col-name">
                    <?php echo htmlspecialchars($emp['employee_full_name']); ?>
                    <?php if ($emp['company_code']): ?>
                    <div class="col-meta"><?php echo htmlspecialchars($emp['company_code']); ?><?php echo $emp['branch_name'] ? ' · '.htmlspecialchars($emp['branch_name']) : ''; ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($emp['designation_name']): ?>
                    <span class="desg-badge"><?php echo htmlspecialchars($emp['designation_name']); ?></span>
                    <?php endif; ?>
                    <?php if ($emp['category_name']): ?>
                    <br><span class="cat-badge"><?php echo htmlspecialchars($emp['category_name']); ?></span>
                    <?php endif; ?>
                </td>
                <td><span class="status-badge <?php echo $sc; ?>"><?php echo $emp['status']; ?></span></td>

                <!-- Insurance -->
                <td class="money-cell">
                    <div class="money-wrap">
                        <span class="money-sym">LKR</span>
                        <input type="number" class="money-input" data-col="insurance_amount" data-id="<?php echo $emp['id']; ?>"
                               value="<?php echo $fv($emp['insurance_amount']); ?>" placeholder="—" step="0.01" min="0">
                    </div>
                </td>
                <!-- Welfare -->
                <td class="money-cell">
                    <div class="money-wrap">
                        <span class="money-sym">LKR</span>
                        <input type="number" class="money-input" data-col="welfare_amount" data-id="<?php echo $emp['id']; ?>"
                               value="<?php echo $fv($emp['welfare_amount']); ?>" placeholder="—" step="0.01" min="0">
                    </div>
                </td>
                <!-- Attendance Allowance -->
                <td class="money-cell col-allow-cell">
                    <div class="money-wrap col-allow">
                        <span class="money-sym">LKR</span>
                        <input type="number" class="money-input" data-col="attendance_allowance" data-id="<?php echo $emp['id']; ?>"
                               value="<?php echo $fv($emp['attendance_allowance']); ?>" placeholder="—" step="0.01" min="0">
                    </div>
                </td>
                <!-- Meal -->
                <td class="money-cell col-reimb-cell">
                    <div class="money-wrap col-reimb">
                        <span class="money-sym">LKR</span>
                        <input type="number" class="money-input" data-col="reimbursement_meal" data-id="<?php echo $emp['id']; ?>"
                               value="<?php echo $fv($emp['reimbursement_meal']); ?>" placeholder="—" step="0.01" min="0">
                    </div>
                </td>
                <!-- Traveling -->
                <td class="money-cell col-reimb-cell">
                    <div class="money-wrap col-reimb">
                        <span class="money-sym">LKR</span>
                        <input type="number" class="money-input" data-col="reimbursement_traveling" data-id="<?php echo $emp['id']; ?>"
                               value="<?php echo $fv($emp['reimbursement_traveling']); ?>" placeholder="—" step="0.01" min="0">
                    </div>
                </td>
                <!-- Other -->
                <td class="money-cell col-reimb-cell">
                    <div class="money-wrap col-reimb">
                        <span class="money-sym">LKR</span>
                        <input type="number" class="money-input" data-col="reimbursement_other" data-id="<?php echo $emp['id']; ?>"
                               value="<?php echo $fv($emp['reimbursement_other']); ?>" placeholder="—" step="0.01" min="0">
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Toast -->
<div id="toast"></div>

<!-- Saving overlay -->
<div id="savingOverlay">
    <div class="saving-box">
        <div class="saving-spinner"></div>
        <div style="font-size:15px;font-weight:700;color:var(--ink);">Saving changes…</div>
        <div style="font-size:12px;color:var(--ink3);margin-top:4px;">Please wait</div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
/* ── Filters toggle ────────────────────────── */
function toggleFilters() {
    const body   = document.getElementById('filtersBody');
    const toggle = document.getElementById('filtersToggle');
    const open   = body.classList.toggle('open');
    toggle.classList.toggle('open', open);
}

/* ── Branch loader ─────────────────────────── */
function loadBranches(companyId) {
    const sel = document.getElementById('filter_branch');
    if (!sel) return;
    sel.innerHTML = '<option value="">All Branches</option>';
    if (!companyId) return;
    fetch('get_branches.php?company_id=' + companyId)
        .then(r => r.json())
        .then(data => data.forEach(b => {
            const o = new Option(b.branch_code + ' - ' + b.branch_name, b.id);
            sel.appendChild(o);
        }));
}
<?php if ($filter_company): ?>
loadBranches(<?php echo $filter_company; ?>);
setTimeout(() => { const s = document.getElementById('filter_branch'); if (s) s.value = '<?php echo $filter_branch; ?>'; }, 600);
<?php endif; ?>

/* ── Select / deselect ─────────────────────── */
function toggleSelectAll(checked) {
    document.querySelectorAll('.row-check').forEach(cb => {
        cb.checked = checked;
        cb.closest('tr').classList.toggle('row-selected', checked);
    });
    updateSelectedCount();
}

function updateSelectedCount() {
    const n = document.querySelectorAll('.row-check:checked').length;
    document.getElementById('selectedCount').textContent = n;
    document.getElementById('selectedStatCount').textContent = n;
    document.getElementById('selectedPill').style.display = n > 0 ? 'flex' : 'none';

    // Sync select-all checkbox state
    const total = document.querySelectorAll('.row-check').length;
    const all   = document.getElementById('selectAll');
    if (all) {
        all.indeterminate = n > 0 && n < total;
        all.checked       = n === total && total > 0;
    }
    document.querySelectorAll('.row-check').forEach(cb => {
        cb.closest('tr').classList.toggle('row-selected', cb.checked);
    });
}

/* ── Apply common amount to column ─────────── */
function applyCommon(col) {
    const fillInput = document.getElementById('fill_' + col);
    const val = fillInput ? fillInput.value.trim() : '';
    if (val === '') { showToast('Enter a value first', 'error'); return; }

    // Apply to selected rows if any, else all visible rows
    const selected = document.querySelectorAll('.row-check:checked');
    const targets  = selected.length > 0
        ? [...selected].map(cb => cb.closest('tr'))
        : [...document.querySelectorAll('.emp-row')].filter(r => r.style.display !== 'none');

    let count = 0;
    targets.forEach(row => {
        const input = row.querySelector(`input[data-col="${col}"]`);
        if (input) { input.value = parseFloat(val).toFixed(2); count++; }
    });

    showToast(`Applied LKR ${parseFloat(val).toLocaleString('en-US', {minimumFractionDigits:2})} to ${count} employee${count!==1?'s':''}`, 'success');
}

/* ── Collect table data ─────────────────────── */
function collectData(onlySelected) {
    const data = {};
    const rows = onlySelected
        ? [...document.querySelectorAll('.row-check:checked')].map(cb => cb.closest('tr'))
        : document.querySelectorAll('.emp-row');

    rows.forEach(row => {
        const id = row.getAttribute('data-id');
        if (!id) return;
        data[id] = {};
        row.querySelectorAll('.money-input').forEach(inp => {
            data[id][inp.getAttribute('data-col')] = inp.value;
        });
    });
    return data;
}

/* ── Save all rows ──────────────────────────── */
function saveAll() {
    if (!confirm('Save changes for ALL ' + document.querySelectorAll('.emp-row').length + ' employees?')) return;
    doSave(collectData(false));
}

/* ── Save selected rows ─────────────────────── */
function saveSelected() {
    const selected = document.querySelectorAll('.row-check:checked');
    if (!selected.length) { showToast('Select at least one employee', 'error'); return; }
    if (!confirm('Save changes for ' + selected.length + ' selected employee(s)?')) return;
    doSave(collectData(true));
}

/* ── AJAX save ──────────────────────────────── */
function doSave(employees) {
    document.getElementById('savingOverlay').classList.add('show');

    const formData = new FormData();
    formData.append('bulk_save', '1');
    for (const [empId, fields] of Object.entries(employees)) {
        for (const [col, val] of Object.entries(fields)) {
            formData.append(`employees[${empId}][${col}]`, val);
        }
    }

    fetch('bulk_salary_update.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            document.getElementById('savingOverlay').classList.remove('show');
            if (res.success) {
                const msg = `Saved ${res.updated} employee${res.updated!==1?'s':''}` +
                            (res.errors.length ? ` (${res.errors.length} error${res.errors.length!==1?'s':''})` : '');
                showToast(msg, res.errors.length ? 'error' : 'success');
            } else {
                showToast('Save failed. Please try again.', 'error');
            }
        })
        .catch(() => {
            document.getElementById('savingOverlay').classList.remove('show');
            showToast('Network error. Please try again.', 'error');
        });
}

/* ── Toast ──────────────────────────────────── */
let toastTimer;
function showToast(msg, type) {
    const t = document.getElementById('toast');
    clearTimeout(toastTimer);
    t.innerHTML = `<i class="fa-solid fa-${type==='success'?'circle-check':'circle-exclamation'}"></i> ${msg}`;
    t.className = 'show ' + (type === 'success' ? 'toast-success' : 'toast-error');
    toastTimer = setTimeout(() => { t.className = ''; }, 3500);
}

/* ── Keyboard shortcut: Ctrl+S ──────────────── */
document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        saveAll();
    }
});
</script>

<?php include 'footer.php'; ?>
