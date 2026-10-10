<?php
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$session_user = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';

$msg = ''; $msg_type = '';

// ── PAYROLL PERIODS (all, not just open) ───────────────────────────────────
$month_names = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

$all_periods = [];
$per_res = mysqli_query($conn, "SELECT * FROM payroll_periods ORDER BY year DESC, month DESC");
while ($p = mysqli_fetch_assoc($per_res)) $all_periods[] = $p;

// Default selected period = current month/year, fallback to latest
$current_month = (int)date('n');
$current_year  = (int)date('Y');
$default_period = null;
foreach ($all_periods as $p) {
    if ((int)$p['month'] === $current_month && (int)$p['year'] === $current_year) {
        $default_period = $p;
        break;
    }
}
if (!$default_period) $default_period = $all_periods[0] ?? null;

// ── HANDLE BULK SUBMIT ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_submit'])) {
    $payroll_period_id = intval($_POST['bulk_payroll_period_id']);
    $request_date      = mysqli_real_escape_string($conn, $_POST['bulk_request_date']);
    $rows              = $_POST['rows'] ?? [];

    $errors = [];
    if (!$request_date)      $errors[] = "Request date is required.";
    if (!$payroll_period_id) $errors[] = "Please select a payroll period.";

    // Verify period exists in DB regardless of its status (locked or open both allowed)
    if ($payroll_period_id) {
        $pv_res = mysqli_query($conn, "SELECT id FROM payroll_periods WHERE id = $payroll_period_id LIMIT 1");
        if (!$pv_res || mysqli_num_rows($pv_res) === 0) {
            $errors[] = "Selected payroll period not found in database.";
        }
    }

    $saved = 0; $failed = 0;
    if (empty($errors)) {
        $created_by = mysqli_real_escape_string($conn, $session_user);
        foreach ($rows as $row) {
            $emp_id = intval($row['employee_id'] ?? 0);
            $amount = floatval($row['amount'] ?? 0);
            $reason = mysqli_real_escape_string($conn, trim($row['reason'] ?? ''));
            if ($emp_id <= 0 || $amount <= 0) continue;
            $sql = "INSERT INTO salary_advances (employee_id, request_date, amount, reason, payroll_period_id, status, created_by) "
                 . "VALUES ($emp_id, '$request_date', $amount, '$reason', $payroll_period_id, 'Approved', '$created_by')";
            if (mysqli_query($conn, $sql)) {
                $saved++;
            } else {
                $failed++;
                if ($failed === 1) $errors[] = "DB error on save: " . mysqli_error($conn);
            }
        }
        if ($saved > 0) {
            header("Location: salary_advance.php?bulk_added=$saved"); exit;
        } else {
            if (empty($errors)) $errors[] = "No valid rows to save. Please enter at least one amount.";
        }
    }
    if (!empty($errors)) { $msg = implode('<br>', $errors); $msg_type = 'danger'; }
}

// ── LOAD EMPLOYEES (alphabetical) ──────────────────────────────────────────
$employees = [];
$emp_res = mysqli_query($conn,
    "SELECT e.id, e.employee_id AS emp_code, e.employee_full_name, e.basic_salary,
            d.designation_name, sc.category_name AS staff_category_name, sc.id AS staff_category_id,
            d.id AS designation_id
     FROM employees e
     LEFT JOIN designations d ON e.designation_id = d.id
     LEFT JOIN staff_categories sc ON e.staff_category_id = sc.id
     WHERE e.status IN ('Permanent','Probation')
     ORDER BY e.employee_full_name ASC"
);
while ($e = mysqli_fetch_assoc($emp_res)) $employees[] = $e;

// ── LOAD EXISTING ADVANCES FOR DEFAULT PERIOD ──────────────────────────────
// Build map: employee_id => total approved/pending advances for the selected period
$existing_advances = [];
$sel_period_id = $default_period ? intval($default_period['id']) : 0;
if ($sel_period_id) {
    $ea_res = mysqli_query($conn,
        "SELECT employee_id, SUM(amount) AS total_amount
         FROM salary_advances
         WHERE payroll_period_id = $sel_period_id
           AND status IN ('Approved','Pending')
         GROUP BY employee_id"
    );
    while ($ea = mysqli_fetch_assoc($ea_res)) {
        $existing_advances[intval($ea['employee_id'])] = floatval($ea['total_amount']);
    }
}

// ── FILTER DATA FOR DROPDOWNS ──────────────────────────────────────────────
$designations = [];
$des_res = mysqli_query($conn, "SELECT id, designation_name FROM designations ORDER BY designation_name");
while ($d = mysqli_fetch_assoc($des_res)) $designations[] = $d;

$staff_cats = [];
$sc_res = mysqli_query($conn, "SELECT id, category_name FROM staff_categories WHERE active=1 ORDER BY category_name");
while ($s = mysqli_fetch_assoc($sc_res)) $staff_cats[] = $s;

// Build JSON for JS
$emp_json = [];
foreach ($employees as $e) {
    $emp_json[] = [
        'id'            => $e['id'],
        'code'          => $e['emp_code'],
        'name'          => $e['employee_full_name'],
        'basic'         => floatval($e['basic_salary']),
        'designation'   => $e['designation_name'] ?? '',
        'designation_id'=> $e['designation_id'] ?? '',
        'category'      => $e['staff_category_name'] ?? '',
        'category_id'   => $e['staff_category_id'] ?? '',
        'existing'      => $existing_advances[$e['id']] ?? 0,
    ];
}

include 'header.php';
?>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- SELECT2 -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- PAGE HEADER -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="bsa-header">
    <div class="bsa-header-left">
        <div class="bsa-header-icon"><i class="fa-solid fa-layer-group"></i></div>
        <div>
            <h2 class="bsa-title">Bulk Salary Advance Request</h2>
            <p class="bsa-sub">Enter advances for multiple employees in one submission</p>
        </div>
    </div>
    <div class="bsa-header-actions">
        <a href="add_salary_advance.php" class="bsa-btn bsa-btn-outline">
            <i class="fa-solid fa-user"></i> Single Request
        </a>
        <a href="salary_advance.php" class="bsa-btn bsa-btn-outline">
            <i class="fa-solid fa-arrow-left"></i> Back to List
        </a>
    </div>
</div>

<?php if ($msg): ?>
<div class="bsa-alert bsa-alert-<?php echo $msg_type; ?>">
    <i class="fa-solid fa-circle-xmark"></i>
    <div><?php echo $msg; ?></div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- GLOBAL SETTINGS BAR -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="bsa-settings-bar">
    <div class="bsa-settings-grid">
        <!-- Request Date -->
        <div class="bsa-field-group">
            <label class="bsa-field-label"><i class="fa-solid fa-calendar-days"></i> Request Date <span class="req">*</span></label>
            <input type="date" id="bulkDate" name="bulk_request_date"
                   class="bsa-field-input" value="<?php echo date('Y-m-d'); ?>" required>
        </div>

        <!-- Payroll Period -->
        <div class="bsa-field-group">
            <label class="bsa-field-label"><i class="fa-solid fa-calendar-check"></i> Payroll Period <span class="req">*</span></label>
            <select id="bulkPeriod" name="bulk_payroll_period_id" class="bsa-field-input" required>
                <option value="">— Select Period —</option>
                <?php foreach ($all_periods as $p):
                    $label = $month_names[$p['month']] . ' ' . $p['year'];
                    $status_tag = $p['status'] === 'Open' ? ' (Open)' : ' (Locked)';
                    $is_default = $default_period && $p['id'] == $default_period['id'];
                ?>
                <option value="<?php echo $p['id']; ?>"
                        data-status="<?php echo $p['status']; ?>"
                        <?php echo $is_default ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label . $status_tag); ?>
                </option>
                <?php endforeach; ?>
            </select>

        </div>

        <!-- Summary -->
        <div class="bsa-summary-box">
            <div class="bsa-summary-item">
                <span class="bsa-summary-val" id="summaryCount">0</span>
                <span class="bsa-summary-lbl">Employees</span>
            </div>
            <div class="bsa-summary-divider"></div>
            <div class="bsa-summary-item">
                <span class="bsa-summary-val" id="summaryTotal">LKR 0.00</span>
                <span class="bsa-summary-lbl">Total Advance</span>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- FILTERS BAR -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="bsa-filters-wrap">
    <div class="bsa-filters-row">
        <div class="bsa-filter-group">
            <label class="bsa-filter-label">Search Name / Code</label>
            <div class="bsa-search-wrap">
                <i class="fa-solid fa-magnifying-glass bsa-search-icon"></i>
                <input type="text" id="filterName" class="bsa-filter-input" placeholder="Name or employee code…" oninput="applyFilters()">
            </div>
        </div>

        <div class="bsa-filter-group">
            <label class="bsa-filter-label">Designation</label>
            <select id="filterDesignation" class="bsa-filter-input" onchange="applyFilters()">
                <option value="">All Designations</option>
                <?php foreach ($designations as $d): ?>
                <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['designation_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="bsa-filter-group">
            <label class="bsa-filter-label">Staff Category</label>
            <select id="filterCategory" class="bsa-filter-input" onchange="applyFilters()">
                <option value="">All Categories</option>
                <?php foreach ($staff_cats as $s): ?>
                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['category_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="bsa-filter-group">
            <label class="bsa-filter-label">Show</label>
            <select id="filterShow" class="bsa-filter-input" onchange="applyFilters()">
                <option value="all">All Employees</option>
                <option value="filled">With Amount</option>
                <option value="empty">Without Amount</option>
            </select>
        </div>

        <button class="bsa-btn bsa-btn-ghost bsa-filter-clear" onclick="clearFilters()">
            <i class="fa-solid fa-xmark"></i> Clear
        </button>
    </div>
    <div class="bsa-filter-info" id="filterInfo">
        Showing all <strong><?php echo count($employees); ?></strong> employees
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- BULK TABLE -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<form method="POST" id="bulkForm">
    <input type="hidden" name="bulk_submit" value="1">
    <input type="hidden" name="bulk_request_date" id="hiddenDate">
    <input type="hidden" name="bulk_payroll_period_id" id="hiddenPeriod">

    <div class="bsa-table-wrap">
        <table class="bsa-table" id="bulkTable">
            <thead>
                <tr>
                    <th class="col-no">#</th>
                    <th class="col-code">Code</th>
                    <th class="col-name">Employee Name</th>
                    <th class="col-desig">Designation</th>
                    <th class="col-cat">Category</th>
                    <th class="col-basic">Basic Salary</th>
                    <th class="col-existing">Already Requested</th>
                    <th class="col-amount">Advance Amount <span class="req">*</span></th>
                    <th class="col-pct">% of Basic</th>
                    <th class="col-reason">Reason / Notes</th>
                </tr>
            </thead>
            <tbody id="bulkTbody">
                <?php foreach ($employees as $i => $e):
                    $basic = floatval($e['basic_salary']);
                    $basic_fmt = number_format($basic, 2);
                ?>
                <tr class="bsa-row"
                    data-emp-id="<?php echo $e['id']; ?>"
                    data-basic="<?php echo $basic; ?>"
                    data-name="<?php echo strtolower(htmlspecialchars($e['employee_full_name'])); ?>"
                    data-code="<?php echo strtolower(htmlspecialchars($e['emp_code'])); ?>"
                    data-designation-id="<?php echo $e['designation_id'] ?? ''; ?>"
                    data-category-id="<?php echo $e['staff_category_id'] ?? ''; ?>"
                    data-row="<?php echo $i; ?>"
                    data-existing="<?php echo $existing_advances[$e['id']] ?? 0; ?>">

                    <!-- Hidden fields for submission -->
                    <input type="hidden" name="rows[<?php echo $i; ?>][employee_id]" value="<?php echo $e['id']; ?>">

                    <td class="col-no bsa-seq"><?php echo $i + 1; ?></td>
                    <td class="col-code">
                        <span class="bsa-emp-code"><?php echo htmlspecialchars($e['emp_code']); ?></span>
                    </td>
                    <td class="col-name">
                        <span class="bsa-emp-name"><?php echo htmlspecialchars($e['employee_full_name']); ?></span>
                    </td>
                    <td class="col-desig">
                        <?php if ($e['designation_name']): ?>
                        <span class="bsa-badge bsa-badge-blue"><?php echo htmlspecialchars($e['designation_name']); ?></span>
                        <?php else: ?><span class="bsa-na">—</span><?php endif; ?>
                    </td>
                    <td class="col-cat">
                        <?php if ($e['staff_category_name']): ?>
                        <span class="bsa-badge bsa-badge-purple"><?php echo htmlspecialchars($e['staff_category_name']); ?></span>
                        <?php else: ?><span class="bsa-na">—</span><?php endif; ?>
                    </td>
                    <td class="col-basic">
                        <span class="bsa-basic-amt">
                            <?php if ($basic > 0): ?>
                            <span class="bsa-lkr">LKR</span> <?php echo $basic_fmt; ?>
                            <?php else: ?><span class="bsa-na">—</span>
                            <?php endif; ?>
                        </span>
                    </td>
                    <td class="col-existing" id="existCell_<?php echo $i; ?>">
                        <?php
                        $emp_existing = $existing_advances[$e['id']] ?? 0;
                        if ($emp_existing > 0):
                        ?>
                        <span class="bsa-existing-amt">
                            <span class="bsa-lkr">LKR</span> <?php echo number_format($emp_existing, 2); ?>
                        </span>
                        <?php else: ?>
                        <span class="bsa-na bsa-exist-none">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-amount">
                        <div class="bsa-amount-wrap">
                            <span class="bsa-lkr-prefix">LKR</span>
                            <input type="number"
                                   name="rows[<?php echo $i; ?>][amount]"
                                   class="bsa-amount-input"
                                   min="0" step="0.01"
                                   placeholder="0.00"
                                   data-row="<?php echo $i; ?>"
                                   data-basic="<?php echo $basic; ?>"
                                   oninput="onAmountChange(this)"
                                   onkeydown="handleEnterKey(event, this)">
                        </div>
                    </td>
                    <td class="col-pct" id="pctCell_<?php echo $i; ?>">
                        <span class="bsa-pct-placeholder">—</span>
                    </td>
                    <td class="col-reason">
                        <input type="text"
                               name="rows[<?php echo $i; ?>][reason]"
                               class="bsa-reason-input"
                               placeholder="Optional…"
                               onkeydown="handleEnterKey(event, this)">
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div id="noRowsMsg" style="display:none;text-align:center;padding:48px;color:#9ca3af;font-size:13px;">
            <i class="fa-solid fa-filter" style="font-size:24px;display:block;margin-bottom:8px;"></i>
            No employees match the current filters.
        </div>
    </div>

    <!-- ── SUBMIT FOOTER ── -->
    <div class="bsa-footer">
        <div class="bsa-footer-summary">
            <div class="bsa-footer-stat">
                <span class="bsa-footer-val" id="footerCount">0</span>
                <span class="bsa-footer-lbl">entries with amounts</span>
            </div>
            <div class="bsa-footer-stat">
                <span class="bsa-footer-val" id="footerTotal">LKR 0.00</span>
                <span class="bsa-footer-lbl">total advance</span>
            </div>
        </div>
        <div class="bsa-footer-actions">
            <button type="button" class="bsa-btn bsa-btn-ghost" onclick="clearAllAmounts()">
                <i class="fa-solid fa-eraser"></i> Clear Amounts
            </button>
            <a href="salary_advance.php" class="bsa-btn bsa-btn-outline">Cancel</a>
            <button type="button" class="bsa-btn bsa-btn-primary" onclick="submitBulk()">
                <i class="fa-solid fa-paper-plane"></i> Submit Bulk Request
            </button>
        </div>
    </div>
</form>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- CONFIRM MODAL -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div id="confirmModal" class="bsa-modal-overlay" style="display:none;">
    <div class="bsa-modal">
        <div class="bsa-modal-header">
            <i class="fa-solid fa-paper-plane" style="color:#3b82f6;font-size:20px;"></i>
            <h3>Confirm Bulk Submission</h3>
        </div>
        <div class="bsa-modal-body" id="confirmBody">
            <!-- filled by JS -->
        </div>
        <div class="bsa-modal-footer">
            <button class="bsa-btn bsa-btn-ghost" onclick="closeModal()">Cancel</button>
            <button class="bsa-btn bsa-btn-primary" onclick="confirmSubmit()">
                <i class="fa-solid fa-check"></i> Confirm &amp; Save
            </button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- CSS -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<style>
/* ── Reset ── */
*, *::before, *::after { box-sizing: border-box; }

/* ── Page header ── */
.bsa-header {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 14px; margin-bottom: 20px;
}
.bsa-header-left  { display: flex; align-items: center; gap: 14px; }
.bsa-header-icon  {
    width: 46px; height: 46px; background: #eff6ff; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    color: #3b82f6; font-size: 20px; flex-shrink: 0;
}
.bsa-title        { font-size: 20px; font-weight: 800; color: #111; margin: 0 0 3px; }
.bsa-sub          { font-size: 12px; color: #6b7280; margin: 0; }
.bsa-header-actions { display: flex; gap: 8px; }
.req              { color: #ef4444; }

/* ── Alert ── */
.bsa-alert {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 16px;
}
.bsa-alert-danger { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; }

/* ── Settings bar ── */
.bsa-settings-bar {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 16px 20px; margin-bottom: 14px;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.bsa-settings-grid {
    display: grid; grid-template-columns: 220px 260px 1fr; gap: 16px; align-items: end;
}
.bsa-field-group  { display: flex; flex-direction: column; }
.bsa-field-label  {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .4px; color: #6b7280; margin-bottom: 6px;
    display: flex; align-items: center; gap: 5px;
}
.bsa-field-input  {
    padding: 9px 12px; border: 1.5px solid #e5e7eb; border-radius: 8px;
    font-size: 13px; font-family: inherit; color: #111; background: #fff;
    transition: border-color .15s;
}
.bsa-field-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.1); }


/* ── Summary box ── */
.bsa-summary-box {
    display: flex; align-items: center; justify-content: flex-end; gap: 20px;
    background: #f8faff; border: 1px solid #dbeafe; border-radius: 10px;
    padding: 12px 20px;
}
.bsa-summary-item { display: flex; flex-direction: column; align-items: center; }
.bsa-summary-val  { font-size: 18px; font-weight: 800; color: #1d4ed8; line-height: 1.2; }
.bsa-summary-lbl  { font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: .3px; }
.bsa-summary-divider { width: 1px; height: 36px; background: #dbeafe; }

/* ── Filters bar ── */
.bsa-filters-wrap {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 14px 20px; margin-bottom: 14px;
}
.bsa-filters-row  { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.bsa-filter-group { display: flex; flex-direction: column; }
.bsa-filter-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: #9ca3af; margin-bottom: 4px; }
.bsa-filter-input {
    padding: 8px 12px; border: 1.5px solid #e5e7eb; border-radius: 7px;
    font-size: 12px; font-family: inherit; color: #111; background: #fff;
    min-width: 150px;
}
.bsa-filter-input:focus { outline: none; border-color: #3b82f6; }
.bsa-search-wrap  { position: relative; }
.bsa-search-icon  { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 12px; pointer-events: none; }
.bsa-search-wrap .bsa-filter-input { padding-left: 30px; }
.bsa-filter-clear { align-self: flex-end; }
.bsa-filter-info  { margin-top: 10px; font-size: 12px; color: #6b7280; }

/* ── Table ── */
.bsa-table-wrap {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
    overflow: hidden; margin-bottom: 0;
    box-shadow: 0 2px 12px rgba(0,0,0,.05);
}
.bsa-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.bsa-table thead tr { background: #f9fafb; border-bottom: 2px solid #e5e7eb; }
.bsa-table th {
    padding: 11px 12px; text-align: left; font-size: 10.5px;
    font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #6b7280;
    white-space: nowrap;
}
.bsa-table td { padding: 8px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
.bsa-row:last-child td { border-bottom: none; }
.bsa-row:hover { background: #fafbff; }
.bsa-row.row-has-amount { background: #f0fdf4; }
.bsa-row.row-has-amount:hover { background: #dcfce7; }
.bsa-row.row-hidden { display: none; }

/* Column widths */
.col-no     { width: 40px; }
.col-code   { width: 90px; }
.col-name   { width: 200px; }
.col-desig  { width: 140px; }
.col-cat    { width: 110px; }
.col-basic    { width: 120px; text-align: right; }
.col-existing { width: 130px; text-align: right; }
.col-amount { width: 160px; }
.col-pct    { width: 100px; text-align: center; }
.col-reason { min-width: 160px; }

.bsa-seq      { font-size: 11px; color: #9ca3af; font-weight: 600; }
.bsa-emp-code { font-size: 12px; font-weight: 700; color: #6b7280; font-family: monospace; }
.bsa-emp-name { font-weight: 600; color: #111; }
.bsa-basic-amt  { font-size: 13px; font-weight: 700; color: #374151; }
.bsa-existing-amt { font-size: 13px; font-weight: 700; color: #b45309; }
.bsa-exist-none   { font-size: 13px; color: #d1d5db; }
.bsa-lkr      { font-size: 10px; color: #9ca3af; margin-right: 2px; }
.bsa-na       { color: #d1d5db; }

/* Badges */
.bsa-badge        { display: inline-block; padding: 2px 8px; border-radius: 5px; font-size: 10.5px; font-weight: 600; }
.bsa-badge-blue   { background: #dbeafe; color: #1e40af; }
.bsa-badge-purple { background: #ede9fe; color: #5b21b6; }

/* Amount input */
.bsa-amount-wrap  { position: relative; display: flex; align-items: center; }
.bsa-lkr-prefix   { position: absolute; left: 10px; font-size: 10px; font-weight: 700; color: #9ca3af; pointer-events: none; z-index: 1; }
.bsa-amount-input {
    width: 100%; padding: 8px 10px 8px 38px;
    border: 1.5px solid #e5e7eb; border-radius: 8px;
    font-size: 13px; font-family: inherit; font-weight: 600; color: #111;
    transition: border-color .15s, box-shadow .15s;
    background: #fff;
}
.bsa-amount-input:focus {
    outline: none; border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,.1);
}
.bsa-amount-input.has-val { border-color: #22c55e; background: #f0fdf4; }
.bsa-amount-input.over-75 { border-color: #ef4444; background: #fff8f8; }

/* Reason input */
.bsa-reason-input {
    width: 100%; padding: 8px 10px;
    border: 1.5px solid #e5e7eb; border-radius: 8px;
    font-size: 12px; font-family: inherit; color: #111;
    transition: border-color .15s;
}
.bsa-reason-input:focus { outline: none; border-color: #3b82f6; }

/* PCT cell */
.bsa-pct-pill {
    display: inline-flex; align-items: center; justify-content: center;
    padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700;
    min-width: 56px;
}
.bsa-pct-low  { background: #dcfce7; color: #15803d; }
.bsa-pct-mid  { background: #fef3c7; color: #92400e; }
.bsa-pct-high { background: #fee2e2; color: #991b1b; }
.bsa-pct-placeholder { color: #d1d5db; font-size: 12px; }

/* ── Footer ── */
.bsa-footer {
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap;
    gap: 14px; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 16px 20px; margin-top: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,.05);
}
.bsa-footer-summary { display: flex; gap: 28px; }
.bsa-footer-stat    { display: flex; flex-direction: column; }
.bsa-footer-val     { font-size: 18px; font-weight: 800; color: #111; }
.bsa-footer-lbl     { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: .3px; }
.bsa-footer-actions { display: flex; gap: 10px; align-items: center; }

/* ── Buttons ── */
.bsa-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 18px; border-radius: 8px; font-size: 13px;
    font-weight: 600; cursor: pointer; border: none; transition: all .18s;
    text-decoration: none; font-family: inherit; white-space: nowrap;
}
.bsa-btn-primary { background: #1d4ed8; color: #fff; }
.bsa-btn-primary:hover { background: #1e40af; }
.bsa-btn-outline { background: #fff; color: #374151; border: 1.5px solid #e5e7eb; }
.bsa-btn-outline:hover { background: #f9fafb; border-color: #d1d5db; }
.bsa-btn-ghost   { background: transparent; color: #6b7280; border: 1.5px solid #e5e7eb; }
.bsa-btn-ghost:hover { background: #f3f4f6; color: #111; }

/* ── Modal ── */
.bsa-modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 9999;
    display: flex; align-items: center; justify-content: center; padding: 20px;
}
.bsa-modal {
    background: #fff; border-radius: 14px; max-width: 500px; width: 100%;
    box-shadow: 0 20px 60px rgba(0,0,0,.2); overflow: hidden;
}
.bsa-modal-header {
    display: flex; align-items: center; gap: 12px;
    padding: 18px 22px; border-bottom: 1px solid #f0f0f0;
}
.bsa-modal-header h3 { margin: 0; font-size: 16px; font-weight: 700; color: #111; }
.bsa-modal-body { padding: 18px 22px; }
.bsa-modal-footer {
    display: flex; justify-content: flex-end; gap: 10px;
    padding: 14px 22px; border-top: 1px solid #f0f0f0; background: #fafafa;
}

.bsa-confirm-row { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid #f3f4f6; font-size: 13px; }
.bsa-confirm-row:last-child { border-bottom: none; font-weight: 700; color: #111; }
.bsa-confirm-lbl { color: #6b7280; }
.bsa-confirm-val { font-weight: 600; color: #111; }

/* ── Responsive ── */
.bsa-table-outer { overflow-x: auto; }
@media (max-width: 900px) {
    .bsa-settings-grid { grid-template-columns: 1fr 1fr; }
    .bsa-summary-box   { grid-column: 1 / -1; justify-content: flex-start; }
    .bsa-filters-row   { flex-direction: column; align-items: stretch; }
    .bsa-filter-input  { min-width: unset; }
}
@media (max-width: 600px) {
    .bsa-settings-grid { grid-template-columns: 1fr; }
    .bsa-footer        { flex-direction: column; align-items: stretch; }
    .bsa-footer-actions { flex-direction: column; }
    .bsa-btn           { justify-content: center; }
}
</style>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- JAVASCRIPT -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<script>
const EMP_DATA = <?php echo json_encode($emp_json, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;


function onAmountChange(input) {
    const row   = input.closest('tr.bsa-row');
    const basic = parseFloat(input.dataset.basic) || 0;
    const amt   = parseFloat(input.value) || 0;
    const idx   = input.dataset.row;

    // Style input
    input.classList.toggle('has-val', amt > 0 && (basic === 0 || amt / basic < 0.75));
    input.classList.toggle('over-75', amt > 0 && basic > 0 && amt / basic >= 0.75);

    // Row highlight
    row.classList.toggle('row-has-amount', amt > 0);

    // PCT cell
    const pctCell = document.getElementById('pctCell_' + idx);
    if (!pctCell) return;
    if (amt <= 0 || basic <= 0) {
        pctCell.innerHTML = '<span class="bsa-pct-placeholder">—</span>';
    } else {
        const pct = (amt / basic) * 100;
        const cls = pct >= 75 ? 'bsa-pct-high' : pct >= 50 ? 'bsa-pct-mid' : 'bsa-pct-low';
        pctCell.innerHTML = `<span class="bsa-pct-pill ${cls}">${pct.toFixed(1)}%</span>`;
    }

    updateSummary();
}

// ── Summary ────────────────────────────────────────────────────────────────
function updateSummary() {
    let count = 0, total = 0;
    document.querySelectorAll('.bsa-amount-input').forEach(inp => {
        const v = parseFloat(inp.value) || 0;
        if (v > 0) { count++; total += v; }
    });
    const fmt = total.toLocaleString('en-US', { minimumFractionDigits: 2 });
    document.getElementById('summaryCount').textContent  = count;
    document.getElementById('summaryTotal').textContent  = 'LKR ' + fmt;
    document.getElementById('footerCount').textContent   = count;
    document.getElementById('footerTotal').textContent   = 'LKR ' + fmt;
}

// ── Enter key navigation: go to next amount input ──────────────────────────
function handleEnterKey(e, input) {
    if (e.key !== 'Enter') return;
    e.preventDefault();

    // Collect all visible amount inputs in order
    const allAmounts = [...document.querySelectorAll('.bsa-amount-input, .bsa-reason-input')]
        .filter(el => el.closest('tr') && !el.closest('tr').classList.contains('row-hidden'));

    const idx = allAmounts.indexOf(input);
    if (idx >= 0 && idx < allAmounts.length - 1) {
        allAmounts[idx + 1].focus();
        allAmounts[idx + 1].select?.();
    }
}

// ── Filters ────────────────────────────────────────────────────────────────
function applyFilters() {
    const nameQ  = document.getElementById('filterName').value.trim().toLowerCase();
    const desigF = document.getElementById('filterDesignation').value;
    const catF   = document.getElementById('filterCategory').value;
    const showF  = document.getElementById('filterShow').value;

    let visible = 0;
    document.querySelectorAll('.bsa-row').forEach(row => {
        const name   = row.dataset.name || '';
        const code   = row.dataset.code || '';
        const desig  = row.dataset.designationId || '';
        const cat    = row.dataset.categoryId || '';
        const amt    = parseFloat(row.querySelector('.bsa-amount-input')?.value || 0);

        const matchName  = !nameQ  || name.includes(nameQ) || code.includes(nameQ);
        const matchDesig = !desigF || desig === desigF;
        const matchCat   = !catF   || cat === catF;
        const matchShow  = showF === 'all'
                        || (showF === 'filled' && amt > 0)
                        || (showF === 'empty'  && amt <= 0);

        const show = matchName && matchDesig && matchCat && matchShow;
        row.classList.toggle('row-hidden', !show);
        if (show) visible++;
    });

    const total = document.querySelectorAll('.bsa-row').length;
    const noMsg = document.getElementById('noRowsMsg');
    noMsg.style.display = visible === 0 ? 'block' : 'none';

    const info = document.getElementById('filterInfo');
    if (nameQ || desigF || catF || showF !== 'all') {
        info.innerHTML = `Showing <strong>${visible}</strong> of <strong>${total}</strong> employees`;
    } else {
        info.innerHTML = `Showing all <strong>${total}</strong> employees`;
    }

    // Re-number visible rows
    let seq = 1;
    document.querySelectorAll('.bsa-row:not(.row-hidden) .bsa-seq').forEach(s => { s.textContent = seq++; });
}

function clearFilters() {
    document.getElementById('filterName').value = '';
    document.getElementById('filterDesignation').value = '';
    document.getElementById('filterCategory').value = '';
    document.getElementById('filterShow').value = 'all';
    applyFilters();
}

// ── Clear amounts ──────────────────────────────────────────────────────────
function clearAllAmounts() {
    if (!confirm('Clear all entered amounts?')) return;
    document.querySelectorAll('.bsa-amount-input').forEach(inp => {
        inp.value = '';
        inp.classList.remove('has-val','over-75');
        inp.closest('tr').classList.remove('row-has-amount');
    });
    document.querySelectorAll('[id^="pctCell_"]').forEach(cell => {
        cell.innerHTML = '<span class="bsa-pct-placeholder">—</span>';
    });
    document.querySelectorAll('.bsa-reason-input').forEach(inp => inp.value = '');
    updateSummary();
}

// ── Submit ─────────────────────────────────────────────────────────────────
function submitBulk() {
    const periodSel = document.getElementById('bulkPeriod');
    const date      = document.getElementById('bulkDate').value;
    const periodId  = periodSel.value;
    const periodTxt = periodSel.options[periodSel.selectedIndex]?.text || '';

    if (!date)     { alert('Please select a request date.'); return; }
    if (!periodId) { alert('Please select a payroll period.'); return; }

    let count = 0, total = 0, rows_info = [];
    document.querySelectorAll('.bsa-amount-input').forEach(inp => {
        const v = parseFloat(inp.value) || 0;
        if (v > 0) {
            count++; total += v;
            const tr = inp.closest('tr');
            rows_info.push({
                name: tr.querySelector('.bsa-emp-name')?.textContent || '',
                amt: v
            });
        }
    });

    if (count === 0) { alert('Please enter at least one advance amount.'); return; }

    // Build modal
    const fmt = (n) => n.toLocaleString('en-US', { minimumFractionDigits: 2 });
    const monthYear = periodTxt.replace(' (Open)','').replace(' (Locked)','');

    let detailHtml = `
        <div class="bsa-confirm-row"><span class="bsa-confirm-lbl">Request Date</span><span class="bsa-confirm-val">${date}</span></div>
        <div class="bsa-confirm-row"><span class="bsa-confirm-lbl">Payroll Period</span><span class="bsa-confirm-val">${monthYear}</span></div>
        <div class="bsa-confirm-row"><span class="bsa-confirm-lbl">Employees</span><span class="bsa-confirm-val">${count}</span></div>`;

    // Show first 5 entries
    rows_info.slice(0, 5).forEach(r => {
        detailHtml += `<div class="bsa-confirm-row"><span class="bsa-confirm-lbl" style="padding-left:10px;">${r.name}</span><span class="bsa-confirm-val">LKR ${fmt(r.amt)}</span></div>`;
    });
    if (rows_info.length > 5) {
        detailHtml += `<div style="font-size:12px;color:#9ca3af;padding:4px 0;">…and ${rows_info.length - 5} more</div>`;
    }
    detailHtml += `<div class="bsa-confirm-row"><span class="bsa-confirm-lbl">Total Advance</span><span class="bsa-confirm-val" style="color:#1d4ed8;font-size:15px;">LKR ${fmt(total)}</span></div>`;

    document.getElementById('confirmBody').innerHTML = detailHtml;
    document.getElementById('confirmModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('confirmModal').style.display = 'none';
}

function confirmSubmit() {
    // Copy global fields to hidden inputs in form
    document.getElementById('hiddenDate').value   = document.getElementById('bulkDate').value;
    document.getElementById('hiddenPeriod').value = document.getElementById('bulkPeriod').value;
    document.getElementById('bulkForm').submit();
}

// ── Refresh existing advances when period changes ─────────────────────────
document.getElementById('bulkPeriod').addEventListener('change', function () {
    const periodId = this.value;
    if (!periodId) return;

    // Show loading state
    document.querySelectorAll('[id^="existCell_"]').forEach(cell => {
        cell.innerHTML = '<span style="color:#d1d5db;font-size:11px;">…</span>';
    });

    fetch('get_period_advances.php?period_id=' + periodId)
        .then(r => r.json())
        .then(data => {
            // data = { employee_id: total_amount, ... }
            document.querySelectorAll('.bsa-row').forEach(row => {
                const empId = row.dataset.empId;
                const idx   = row.dataset.row;
                const cell  = document.getElementById('existCell_' + idx);
                if (!cell) return;
                const amt = data[empId] || 0;
                row.dataset.existing = amt;
                if (amt > 0) {
                    cell.innerHTML = '<span class="bsa-existing-amt"><span class="bsa-lkr">LKR</span> ' + amt.toLocaleString('en-US',{minimumFractionDigits:2}) + '</span>';
                } else {
                    cell.innerHTML = '<span class="bsa-na bsa-exist-none">—</span>';
                }
            });
        })
        .catch(() => {
            document.querySelectorAll('[id^="existCell_"]').forEach(cell => {
                cell.innerHTML = '<span class="bsa-na">—</span>';
            });
        });
});

// ── Init ───────────────────────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', function() {
    updateSummary();
    // Focus first amount input
    const first = document.querySelector('.bsa-amount-input');
    if (first) first.focus();
});
</script>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<?php include 'footer.php'; ?>