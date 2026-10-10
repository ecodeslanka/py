<?php
/**
 * sr_fuel_entry.php  —  SR Fuel Entry page
 *
 * CHANGES
 * ───────
 * • New AJAX action  ajax=load_last_month_km
 *   Accepts period_id + category_id, resolves the PREVIOUS payroll period,
 *   and returns a map of  employee_id => per_week_km  from sr_fuel_entries.
 *   The JS layer uses this to pre-fill any row whose Per Week KM is empty.
 * • Rows pre-filled from last month show a "↩ last month" badge so the
 *   user knows at a glance which values were carried forward.
 * • Fixed stray  x  typo on $period_id line.
 * • Fuel Liters auto-calculated as Per Month KM ÷ 45 when Per Week KM changes.
 * • Fuel KM Divisor (was hardcoded 45) is now user-editable from a control-bar
 *   input box. Changing it live-recalculates Fuel Liters for every row.
 * • NEW: Per Month KM is no longer hardcoded as Per Week KM × 4. There is now
 *   a "Weeks / Month" control (#weekMultiplier) with a quick-pick dropdown
 *   of common values (4, 4.33, 4.345, 4.35) plus a free-text override.
 *   Changing it live-recalculates Per Month KM (and therefore Fuel Liters)
 *   for every row, the same way the Fuel KM Divisor control works.
 */

ob_start();
include 'config.php';

// ── HANDLE AJAX ────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    // ── Load employees for a period + category ──────────────────────────
    if ($_GET['ajax'] === 'load_employees') {
        $period_id   = intval($_GET['period_id']   ?? 0);
        $category_id = intval($_GET['category_id'] ?? 0);

        if (!$period_id || !$category_id) {
            echo json_encode(['error' => 'Missing period or category', 'employees' => [], 'period' => null]);
            exit;
        }

        $period_res = mysqli_query($conn, "SELECT * FROM payroll_periods WHERE id = $period_id LIMIT 1");
        if (!$period_res) {
            echo json_encode(['error' => 'Period query: ' . mysqli_error($conn), 'employees' => [], 'period' => null]);
            exit;
        }
        $period = mysqli_fetch_assoc($period_res);
        if (!$period) {
            echo json_encode(['error' => 'Period not found', 'employees' => [], 'period' => null]);
            exit;
        }

        $desig_filter = '';
        $cat_filter   = '';

        $col_check = mysqli_query($conn, "SHOW COLUMNS FROM designations LIKE 'staff_category_id'");
        if ($col_check && mysqli_num_rows($col_check) > 0) {
            $desig_filter = "OR d.staff_category_id = $category_id";
        }

        $emp_col_check = mysqli_query($conn, "SHOW COLUMNS FROM employees LIKE 'staff_category_id'");
        if ($emp_col_check && mysqli_num_rows($emp_col_check) > 0) {
            $cat_filter = "OR e.staff_category_id = $category_id";
        }

        $where_cat = '';
        if ($desig_filter || $cat_filter) {
            $where_cat = "AND (1=0 $desig_filter $cat_filter)";
        }

        $sql = "SELECT
                    e.id,
                    e.employee_id   AS emp_code,
                    e.employee_full_name AS full_name,
                    e.tr_code,
                    c.company_code,
                    b.branch_name,
                    d.designation_name,
                    fe.id           AS entry_id,
                    fe.fuel_rate,
                    fe.fuel_liter,
                    fe.per_week_km,
                    fe.per_month_km,
                    fe.notes
                FROM employees e
                LEFT JOIN companies    c  ON c.id = e.company_id
                LEFT JOIN branches     b  ON b.id = e.branch_id
                LEFT JOIN designations d  ON d.id = e.designation_id
                LEFT JOIN sr_fuel_entries fe
                       ON fe.employee_id = e.id
                      AND fe.payroll_period_id = $period_id
                WHERE e.active = 1
                $where_cat
                ORDER BY e.tr_code IS NULL, e.tr_code, e.employee_full_name";

        $res = mysqli_query($conn, $sql);
        if (!$res) {
            echo json_encode(['error' => 'Employee query: ' . mysqli_error($conn), 'employees' => [], 'period' => $period]);
            exit;
        }

        $emps = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $emps[] = $r;
        }

        echo json_encode(['employees' => $emps, 'period' => $period]);
        exit;
    }

    // ── NEW: Load last month's Per Week KM for each employee ────────────
    if ($_GET['ajax'] === 'load_last_month_km') {
        $period_id   = intval($_GET['period_id']   ?? 0);
        $category_id = intval($_GET['category_id'] ?? 0);

        if (!$period_id || !$category_id) {
            echo json_encode(['error' => 'Missing period or category', 'km_map' => []]);
            exit;
        }

        // Get the current period's year+month
        $pr = mysqli_query($conn, "SELECT year, month FROM payroll_periods WHERE id = $period_id LIMIT 1");
        if (!$pr) {
            echo json_encode(['error' => 'Period query: ' . mysqli_error($conn), 'km_map' => []]);
            exit;
        }
        $pd = mysqli_fetch_assoc($pr);
        if (!$pd) {
            echo json_encode(['error' => 'Period not found', 'km_map' => []]);
            exit;
        }

        // Calculate previous month's year + month
        $cur_year  = intval($pd['year']);
        $cur_month = intval($pd['month']);
        if ($cur_month === 1) {
            $prev_year  = $cur_year - 1;
            $prev_month = 12;
        } else {
            $prev_year  = $cur_year;
            $prev_month = $cur_month - 1;
        }

        // Find the previous payroll period id (match by year+month or closest before)
        $prev_period_res = mysqli_query($conn,
            "SELECT id FROM payroll_periods
             WHERE year = $prev_year AND month = $prev_month
             LIMIT 1");

        $prev_period_id = 0;
        if ($prev_period_res && mysqli_num_rows($prev_period_res) > 0) {
            $prev_pd = mysqli_fetch_assoc($prev_period_res);
            $prev_period_id = intval($prev_pd['id']);
        }

        // Fallback: get the period just before the current period_id
        if (!$prev_period_id) {
            $fallback_res = mysqli_query($conn,
                "SELECT id FROM payroll_periods
                 WHERE (year < $cur_year OR (year = $cur_year AND month < $cur_month))
                 ORDER BY year DESC, month DESC
                 LIMIT 1");
            if ($fallback_res && mysqli_num_rows($fallback_res) > 0) {
                $fb = mysqli_fetch_assoc($fallback_res);
                $prev_period_id = intval($fb['id']);
            }
        }

        if (!$prev_period_id) {
            // No previous period exists
            echo json_encode(['km_map' => [], 'prev_period_id' => 0,
                              'message' => 'No previous period found']);
            exit;
        }

        // Fetch per_week_km from the previous period for this category
        $km_res = mysqli_query($conn,
            "SELECT fe.employee_id, fe.per_week_km
             FROM sr_fuel_entries fe
             WHERE fe.payroll_period_id = $prev_period_id
               AND fe.staff_category_id = $category_id
               AND fe.per_week_km > 0");

        if (!$km_res) {
            echo json_encode(['error' => 'KM query: ' . mysqli_error($conn), 'km_map' => []]);
            exit;
        }

        $km_map = [];
        while ($r = mysqli_fetch_assoc($km_res)) {
            $km_map[intval($r['employee_id'])] = floatval($r['per_week_km']);
        }

        // Get the previous period label for display
        $prev_label_res = mysqli_query($conn,
            "SELECT year, month FROM payroll_periods WHERE id = $prev_period_id LIMIT 1");
        $prev_label = '';
        if ($prev_label_res) {
            $pl = mysqli_fetch_assoc($prev_label_res);
            if ($pl) {
                $mn = ['','January','February','March','April','May','June',
                       'July','August','September','October','November','December'];
                $prev_label = ($mn[intval($pl['month'])] ?? '') . ' ' . $pl['year'];
            }
        }

        echo json_encode([
            'km_map'         => $km_map,
            'prev_period_id' => $prev_period_id,
            'prev_label'     => $prev_label,
            'count'          => count($km_map)
        ]);
        exit;
    }

    echo json_encode(['error' => 'Unknown ajax action']);
    exit;
}

// ── HANDLE SAVE (POST) ────────────────────────────────────────────────────
$save_msg = ''; $save_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'save_fuel') {

    $period_id   = intval($_POST['period_id']   ?? 0);
    $category_id = intval($_POST['category_id'] ?? 0);
    $saved = 0; $errors = 0;

    if ($period_id && $category_id && isset($_POST['rows']) && is_array($_POST['rows'])) {
        foreach ($_POST['rows'] as $row) {
            $emp_id    = intval($row['employee_id'] ?? 0);
            if ($emp_id <= 0) continue;

            $fuel_rate = floatval($row['fuel_rate']    ?? 0);
            $fuel_ltr  = floatval($row['fuel_liter']   ?? 0);
            $per_wk_km = floatval($row['per_week_km']  ?? 0);
            $per_mo_km = floatval($row['per_month_km'] ?? 0);
            $notes     = mysqli_real_escape_string($conn, $row['notes'] ?? '');

            $pr = mysqli_query($conn,
                "SELECT year, month FROM payroll_periods WHERE id = $period_id LIMIT 1");
            $pd = $pr ? mysqli_fetch_assoc($pr) : null;
            $yr = intval($pd['year']  ?? ($row['year']  ?? date('Y')));
            $mo = intval($pd['month'] ?? ($row['month'] ?? date('n')));

            $er = mysqli_query($conn,
                "SELECT employee_id AS sr_code FROM employees WHERE id = $emp_id LIMIT 1");
            $ed = $er ? mysqli_fetch_assoc($er) : null;
            $sr_code = mysqli_real_escape_string($conn, $ed['sr_code'] ?? '');

            $exists_res = mysqli_query($conn,
                "SELECT id FROM sr_fuel_entries
                 WHERE payroll_period_id = $period_id AND employee_id = $emp_id
                 LIMIT 1");
            $exists = $exists_res ? mysqli_fetch_assoc($exists_res) : null;

            if ($exists) {
                $upd = "UPDATE sr_fuel_entries SET
                            fuel_rate       = $fuel_rate,
                            fuel_liter      = $fuel_ltr,
                            per_week_km     = $per_wk_km,
                            per_month_km    = $per_mo_km,
                            notes           = '$notes',
                            staff_category_id = $category_id,
                            updated_at      = NOW()
                        WHERE id = {$exists['id']}";
                $ok = mysqli_query($conn, $upd);
            } else {
                $ins = "INSERT INTO sr_fuel_entries
                            (payroll_period_id, year, month, employee_id, sr_code,
                             staff_category_id, fuel_rate, fuel_liter,
                             per_week_km, per_month_km, notes)
                        VALUES
                            ($period_id, $yr, $mo, $emp_id, '$sr_code',
                             $category_id, $fuel_rate, $fuel_ltr,
                             $per_wk_km, $per_mo_km, '$notes')";
                $ok = mysqli_query($conn, $ins);
            }

            if ($ok) $saved++;
            else      $errors++;
        }
    }

    $save_msg  = "Saved $saved row(s) successfully." . ($errors ? " $errors error(s)." : '');
    $save_type = $errors ? 'warning' : 'success';
}

// ── PAGE DATA ─────────────────────────────────────────────────────────────
$periods_res = mysqli_query($conn,
    "SELECT id, year, month, open_date, close_date, status
     FROM payroll_periods
     ORDER BY year DESC, month DESC");
$periods = [];
if ($periods_res) {
    while ($r = mysqli_fetch_assoc($periods_res)) $periods[] = $r;
}

$default_period_id = 0;
foreach ($periods as $p) {
    if ($p['status'] === 'Open') { $default_period_id = $p['id']; break; }
}
if (!$default_period_id && $periods) {
    $default_period_id = $periods[0]['id'];
}

$cats_res = mysqli_query($conn,
    "SELECT id, category_code, category_name
     FROM staff_categories
     WHERE active = 1
     ORDER BY category_name");
$categories = [];
if ($cats_res) {
    while ($r = mysqli_fetch_assoc($cats_res)) $categories[] = $r;
}

$default_category_id = 0;
foreach ($categories as $cat) {
    if (strtoupper($cat['category_code']) === 'SR') {
        $default_category_id = $cat['id'];
        break;
    }
}

$month_names = ['','January','February','March','April','May','June',
                'July','August','September','October','November','December'];

ob_end_flush();
include 'header.php';
?>

<!-- ════════════════════════════════════════════════════════════
     PAGE HEADER
════════════════════════════════════════════════════════════ -->
<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title" style="display:flex;align-items:center;gap:10px;">
                <i class="fa-solid fa-gas-pump" style="color:#f59e0b;"></i>
                SR Fuel Entry
            </h2>
            <p class="page-subtitle">Enter fuel details per SR employee — adjust rate and KM per row</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <div id="saveStatusBar" style="display:none;" class="fe-save-status"></div>
            <button id="btnSaveAll" onclick="saveAllRows()">
                <i class="fa-solid fa-floppy-disk"></i> Save All Entries
            </button>
        </div>
    </div>
</div>

<?php if ($save_msg): ?>
<div class="fe-alert fe-alert-<?php echo $save_type; ?>" style="margin-bottom:14px;">
    <i class="fa-solid fa-<?php echo $save_type==='success'?'circle-check':'triangle-exclamation'; ?>"></i>
    <?php echo htmlspecialchars($save_msg); ?>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════
     CONTROL BAR — Period + Category + Bulk Rate
════════════════════════════════════════════════════════════ -->
<div class="fe-control-bar">

    <!-- Payroll Period -->
    <div class="fe-ctrl-group">
        <label class="fe-ctrl-label"><i class="fa-solid fa-calendar-check"></i> Payroll Period</label>
        <select id="selPeriod" class="fe-select fe-select-period">
            <option value="">— Select Period —</option>
            <?php foreach ($periods as $p):
                $mon    = $month_names[$p['month']];
                $locked = $p['status'] === 'Locked';
                $sel    = $p['id'] == $default_period_id ? 'selected' : '';
            ?>
            <option value="<?php echo $p['id']; ?>"
                    data-year="<?php echo $p['year']; ?>"
                    data-month="<?php echo $p['month']; ?>"
                    data-status="<?php echo $p['status']; ?>"
                    <?php echo $sel; ?>>
                <?php echo "$mon {$p['year']}"; ?>
                <?php echo $locked ? ' 🔒' : ' 🔓'; ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Staff Category -->
    <div class="fe-ctrl-group">
        <label class="fe-ctrl-label"><i class="fa-solid fa-layer-group"></i> Staff Category</label>
        <select id="selCategory" class="fe-select">
            <option value="">— Select Category —</option>
            <?php foreach ($categories as $cat):
                $sel = $cat['id'] == $default_category_id ? 'selected' : '';
            ?>
            <option value="<?php echo $cat['id']; ?>"
                    data-code="<?php echo htmlspecialchars($cat['category_code']); ?>"
                    <?php echo $sel; ?>>
                <?php echo htmlspecialchars($cat['category_code'].' — '.$cat['category_name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Bulk Fuel Rate -->
    <div class="fe-ctrl-group">
        <label class="fe-ctrl-label"><i class="fa-solid fa-tag"></i> Bulk Fuel Rate (Rs.)</label>
        <div style="display:flex;gap:6px;align-items:center;">
            <input type="number" id="bulkRate" class="fe-input-num fe-input-rate"
                   placeholder="398" min="0" step="0.01" value="398"
                   style="width:110px;">
            <button class="fe-btn fe-btn-rate" onclick="applyBulkRate()" title="Apply this rate to all rows">
                <i class="fa-solid fa-bolt"></i> Apply All
            </button>
        </div>
    </div>

    <!-- Weeks per Month (drives Per Month KM = Per Week KM × this) -->
    <div class="fe-ctrl-group">
        <label class="fe-ctrl-label"><i class="fa-solid fa-calendar-week"></i> Weeks / Month</label>
        <div style="display:flex;gap:6px;align-items:center;">
            <select id="weekMultiplierPreset" class="fe-select fe-select-weekpreset" onchange="applyWeekPreset(this.value)">
                <option value="4">4 (Standard, 28 days)</option>
                <option value="4.33">4.33 (52 wks ÷ 12)</option>
                <option value="4.345">4.345 (365 ÷ 7 ÷ 12)</option>
                <option value="4.35">4.35 (rounded avg)</option>
                <option value="custom">Custom…</option>
            </select>
            <input type="number" id="weekMultiplier" class="fe-input-num fe-input-weekmult"
                   placeholder="4" min="0.01" step="0.01" value="4"
                   style="width:80px;" onchange="onWeekMultiplierInputChange()">
            <button class="fe-btn fe-btn-weekmult" onclick="onWeekMultiplierChange()" title="Recalculate all Per Month KM using this multiplier">
                <i class="fa-solid fa-rotate-right"></i> Recalc
            </button>
        </div>
    </div>

    <!-- Fuel KM Divisor -->
    <div class="fe-ctrl-group">
        <label class="fe-ctrl-label"><i class="fa-solid fa-divide"></i> Fuel KM Divisor</label>
        <div style="display:flex;gap:6px;align-items:center;">
            <input type="number" id="fuelDivisor" class="fe-input-num fe-input-divisor"
                   placeholder="45" min="0.01" step="0.1" value="45"
                   style="width:90px;" onchange="onDivisorChange()">
            <button class="fe-btn fe-btn-divisor" onclick="onDivisorChange()" title="Recalculate all Fuel Liters using this divisor">
                <i class="fa-solid fa-rotate-right"></i> Recalc
            </button>
        </div>
    </div>

    <!-- Load Button -->
    <div class="fe-ctrl-group" style="justify-content:flex-end;">
        <label class="fe-ctrl-label" style="visibility:hidden;">Load</label>
        <button class="fe-btn fe-btn-load" onclick="onControlChange()">
            <i class="fa-solid fa-rotate"></i> Load Table
        </button>
    </div>

</div>

<!-- ════════════════════════════════════════════════════════════
     LAST MONTH KM NOTICE BANNER
════════════════════════════════════════════════════════════ -->
<div id="lastMonthBanner" style="display:none;" class="fe-lastmonth-banner">
    <i class="fa-solid fa-clock-rotate-left"></i>
    <span id="lastMonthBannerText"></span>
    <button class="fe-btn-banner-clear" onclick="clearLastMonthPrefill()" title="Clear pre-filled values">
        <i class="fa-solid fa-xmark"></i> Clear pre-filled
    </button>
</div>

<!-- ════════════════════════════════════════════════════════════
     SUMMARY STRIP
════════════════════════════════════════════════════════════ -->
<div id="summaryStrip" style="display:none;" class="fe-summary-strip">
    <div class="fe-sum-item">
        <span class="fe-sum-label">Employees</span>
        <span class="fe-sum-val" id="sumEmpCount">0</span>
    </div>
    <div class="fe-sum-divider"></div>
    <div class="fe-sum-item">
        <span class="fe-sum-label">Total Fuel Liters</span>
        <span class="fe-sum-val" id="sumTotalLiters">0.000</span>
    </div>
    <div class="fe-sum-divider"></div>
    <div class="fe-sum-item">
        <span class="fe-sum-label">Total Fuel Amount</span>
        <span class="fe-sum-val fe-sum-amount" id="sumTotalAmount">Rs. 0.00</span>
    </div>
    <div class="fe-sum-divider"></div>
    <div class="fe-sum-item">
        <span class="fe-sum-label">Period Status</span>
        <span class="fe-sum-val" id="sumPeriodStatus">—</span>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
        <button class="fe-btn fe-btn-clear" onclick="clearAllLiters()" title="Clear all liter values">
            <i class="fa-solid fa-eraser"></i> Clear Liters
        </button>
        <button class="fe-btn fe-btn-export" onclick="exportCSV()" title="Export to CSV">
            <i class="fa-solid fa-file-csv"></i> Export
        </button>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     LOADING / EMPTY STATES + TABLE
════════════════════════════════════════════════════════════ -->
<div class="fe-content-wrap">

<div id="stateLoading" class="fe-state" style="display:none;">
    <div class="fe-spinner"></div>
    <p id="stateLoadingText">Loading employees…</p>
</div>

<div id="stateEmpty" class="fe-state">
    <div style="font-size:48px;margin-bottom:12px;">⛽</div>
    <p style="font-size:15px;font-weight:600;color:#374151;margin:0 0 6px;">No data loaded</p>
    <p style="font-size:13px;color:#6b7280;margin:0;">Select a <strong>Payroll Period</strong> and <strong>Staff Category</strong>, then click <strong>Load Table</strong>.</p>
</div>

<div id="stateNoEmp" class="fe-state" style="display:none;">
    <div style="font-size:48px;margin-bottom:12px;">👥</div>
    <p style="font-size:15px;font-weight:600;color:#374151;margin:0 0 6px;">No employees found</p>
    <p style="font-size:13px;color:#6b7280;margin:0;">No employees mapped to this category for the selected period.</p>
</div>

<!-- ════════════════════════════════════════════════════════════
     FUEL ENTRY TABLE
════════════════════════════════════════════════════════════ -->
<form id="fuelForm" method="POST" onsubmit="return false;">
    <input type="hidden" name="action"       value="save_fuel">
    <input type="hidden" id="hPeriodId"    name="period_id"   value="">
    <input type="hidden" id="hCategoryId"  name="category_id" value="">

    <div id="tableWrap" class="fe-table-wrap" style="display:none;">
        <div class="fe-table-header-bar">
            <div style="display:flex;align-items:center;gap:10px;">
                <span class="fe-th-label" id="tableHeadLabel">SR Fuel Entries</span>
                <span class="fe-period-badge" id="tablePeriodBadge"></span>
                <span class="fe-locked-badge" id="tableLockedBadge" style="display:none;">
                    <i class="fa-solid fa-lock"></i> Period Locked
                </span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <div class="fe-search-wrap">
                    <i class="fa-solid fa-magnifying-glass" style="color:#aaa;font-size:12px;"></i>
                    <input type="text" id="tableSearch" placeholder="Search name, SR code…"
                           class="fe-search-in" oninput="filterTable(this.value)">
                </div>
            </div>
        </div>

        <div class="fe-tbl-responsive">
            <table class="fe-table" id="fuelTable">
                <thead>
                    <tr>
                        <th style="width:36px;text-align:center;">#</th>
                        <th style="width:110px;">SR Code</th>
                        <th>Employee Name</th>
                        <th style="width:75px;">Company</th>
                        <th style="width:110px;">Designation</th>
                        <th style="width:105px;text-align:right;">Per Wk KM</th>
                        <th style="width:105px;text-align:right;">Per Mo KM</th>
                        <th style="width:130px;text-align:right;">Fuel Rate (Rs.)</th>
                        <th style="width:120px;text-align:right;">Fuel Liters</th>
                        <th style="width:140px;text-align:right;" class="fe-col-amount">Amount (Rs.)</th>
                        <th style="width:120px;">Notes</th>
                    </tr>
                </thead>
                <tbody id="fuelTableBody"></tbody>
                <tfoot>
                    <tr class="fe-tfoot-total">
                        <td colspan="8" style="text-align:right;font-weight:700;padding:12px 16px;color:#9ca3af;font-size:12px;letter-spacing:.5px;">TOTAL</td>
                        <td style="text-align:right;padding:12px 16px;font-weight:800;color:#f9fafb;" id="footTotalLiters">0.000</td>
                        <td style="text-align:right;padding:12px 16px;font-weight:800;color:#fbbf24;font-size:14px;" id="footTotalAmount">Rs. 0.00</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</form>

</div><!-- /.fe-content-wrap -->

<!-- ════════════════════════════════════════════════════════════
     STYLES
════════════════════════════════════════════════════════════ -->
<style>
.fe-control-bar {
    display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;
    background:#fff;border:1px solid #e5e7eb;border-radius:12px;
    padding:16px 20px;margin-bottom:14px;
    box-shadow:0 1px 4px rgba(0,0,0,.04);
}
.fe-ctrl-group{display:flex;flex-direction:column;gap:6px;}
.fe-ctrl-label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:5px;}
.fe-select{padding:9px 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;background:#fff;color:#111;cursor:pointer;transition:border-color .2s;min-width:200px;}
.fe-select-period{min-width:220px;}
.fe-select-weekpreset{min-width:150px;}
.fe-select:focus{outline:none;border-color:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.1);}
.fe-input-num{padding:9px 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;background:#fff;color:#111;text-align:right;font-weight:700;transition:border-color .2s;}
.fe-input-num:focus{outline:none;border-color:#f59e0b;}
.fe-input-rate{border-color:#fde68a;background:#fffbeb;}
.fe-input-divisor{border-color:#bfdbfe;background:#eff6ff;color:#1e40af;}
.fe-input-divisor:focus{border-color:#3b82f6;}
.fe-input-weekmult{border-color:#c7d2fe;background:#eef2ff;color:#3730a3;}
.fe-input-weekmult:focus{border-color:#6366f1;}
.fe-btn{display:inline-flex;align-items:center;gap:5px;padding:9px 13px;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;transition:all .18s;font-family:inherit;white-space:nowrap;}
.fe-btn-rate{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.fe-btn-rate:hover{background:#f59e0b;color:#fff;border-color:#f59e0b;}
.fe-btn-divisor{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;}
.fe-btn-divisor:hover{background:#3b82f6;color:#fff;border-color:#3b82f6;}
.fe-btn-weekmult{background:#e0e7ff;color:#3730a3;border:1px solid #c7d2fe;}
.fe-btn-weekmult:hover{background:#6366f1;color:#fff;border-color:#6366f1;}
.fe-btn-load{background:#18181b;color:#fff;border:1px solid #374151;font-size:13px;padding:10px 20px;}
.fe-btn-load:hover{background:#374151;}
.fe-btn-clear{background:#f9fafb;color:#6b7280;border:1px solid #e5e7eb;}
.fe-btn-clear:hover{background:#e5e7eb;color:#374151;}
.fe-btn-export{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.fe-btn-export:hover{background:#22c55e;color:#fff;border-color:#22c55e;}

/* Last Month Banner */
.fe-lastmonth-banner{
    display:flex;align-items:center;gap:10px;
    background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;
    padding:10px 16px;margin-bottom:12px;
    font-size:13px;font-weight:600;color:#1e40af;
}
.fe-lastmonth-banner i{font-size:14px;color:#3b82f6;flex-shrink:0;}
.fe-btn-banner-clear{
    margin-left:auto;background:#fff;border:1px solid #bfdbfe;color:#3b82f6;
    border-radius:6px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;
    display:inline-flex;align-items:center;gap:4px;transition:all .15s;
}
.fe-btn-banner-clear:hover{background:#dbeafe;border-color:#3b82f6;}

/* Last month prefill badge on the KM cell */
.fe-lastmonth-badge{
    display:inline-block;
    font-size:9px;font-weight:700;color:#3b82f6;
    background:#dbeafe;border:1px solid #bfdbfe;
    border-radius:3px;padding:0px 4px;
    margin-left:4px;vertical-align:middle;letter-spacing:.3px;
    white-space:nowrap;
}

.fe-summary-strip{display:flex;align-items:center;background:#18181b;border-radius:10px;padding:12px 20px;margin-bottom:14px;color:#fff;flex-wrap:wrap;gap:8px;}
.fe-sum-item{display:flex;flex-direction:column;gap:2px;padding:0 16px;}
.fe-sum-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;}
.fe-sum-val{font-size:18px;font-weight:800;color:#f9fafb;}
.fe-sum-amount{color:#fbbf24;}
.fe-sum-divider{width:1px;height:36px;background:#374151;flex-shrink:0;}
.fe-content-wrap{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.06);}
.fe-state{text-align:center;padding:70px 20px;}
.fe-state p{font-size:14px;margin:10px 0 0;}
.fe-spinner{width:36px;height:36px;border:3px solid #f3f4f6;border-top-color:#f59e0b;border-radius:50%;animation:spin .7s linear infinite;margin:0 auto 12px;}
@keyframes spin{to{transform:rotate(360deg);}}
.fe-table-wrap{background:#fff;overflow:hidden;}
.fe-table-header-bar{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#fafafa;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;gap:10px;}
.fe-th-label{font-size:14px;font-weight:800;color:#111;}
.fe-period-badge{background:#f59e0b;color:#fff;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.fe-locked-badge{background:#f3f4f6;color:#6b7280;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:5px;}
.fe-search-wrap{display:flex;align-items:center;gap:8px;background:#fff;border:1.5px solid #e5e7eb;border-radius:8px;padding:7px 12px;}
.fe-search-in{border:none;outline:none;font-size:13px;font-family:inherit;width:200px;color:#111;}
.fe-tbl-responsive{overflow-x:auto;}
.fe-table{width:100%;border-collapse:collapse;font-size:13px;}
.fe-table thead tr{background:#18181b;}
.fe-table thead th{padding:11px 14px;color:#d1d5db;font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;border-right:1px solid #2a2a2e;white-space:nowrap;}
.fe-table thead th:last-child{border-right:none;}
.fe-col-amount{color:#fbbf24 !important;}
.fe-table tbody tr{border-bottom:1px solid #f3f4f6;transition:background .12s;}
.fe-table tbody tr:hover{background:#fffbeb;}
.fe-table tbody tr.has-data{background:#fefce8;}
.fe-table tbody tr.has-data:hover{background:#fef3c7;}
.fe-table tbody tr.fe-hidden{display:none;}
/* Row pre-filled from last month */
.fe-table tbody tr.from-lastmonth{background:#f0f9ff;}
.fe-table tbody tr.from-lastmonth:hover{background:#e0f2fe;}
.fe-table td{padding:8px 12px;color:#111;vertical-align:middle;}
.fe-td-input{padding:6px 10px;border:1.5px solid #e5e7eb;border-radius:7px;font-size:13px;font-weight:700;text-align:right;font-family:inherit;width:100%;box-sizing:border-box;transition:border-color .15s,background .15s;}
.fe-td-input:focus{outline:none;}
.fe-td-rate{max-width:115px;background:#fffbeb;border-color:#fde68a;color:#92400e;}
.fe-td-rate:focus{border-color:#f59e0b;background:#fef3c7;}
.fe-td-km{max-width:90px;background:#f8fafc;border-color:#e2e8f0;color:#374151;}
.fe-td-km:focus{border-color:#94a3b8;background:#fff;}
.fe-td-km.has-val{background:#f0f9ff;border-color:#7dd3fc;color:#0369a1;}
.fe-td-km-month{background:#f8fafc !important;border-style:dashed !important;cursor:default;color:#0369a1;}
/* KM input pre-filled from last month */
.fe-td-km.prefilled-lastmonth{background:#dbeafe !important;border-color:#60a5fa !important;color:#1e40af !important;}
.fe-td-liter{max-width:105px;background:#eff6ff;border-color:#bfdbfe;color:#1e40af;}
.fe-td-liter:focus{border-color:#3b82f6;background:#dbeafe;}
.fe-td-liter.has-val{background:#fffbeb;border-color:#f59e0b;color:#92400e;}
.fe-td-notes{padding:5px 9px;border:1.5px solid #f3f4f6;border-radius:6px;font-size:12px;font-family:inherit;width:100%;max-width:120px;color:#6b7280;box-sizing:border-box;}
.fe-td-notes:focus{outline:none;border-color:#d1d5db;}
.fe-amount-cell{text-align:right;font-weight:800;font-size:13px;color:#b45309;font-variant-numeric:tabular-nums;}
.fe-amount-cell.zero{color:#d1d5db;}
.fe-srcode{background:#f4f4f5;color:#3f3f46;padding:3px 9px;border-radius:5px;font-size:11px;font-weight:700;font-family:monospace;letter-spacing:.5px;}
.fe-desg{background:#dbeafe;color:#1e40af;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:600;}
.fe-tfoot-total{background:#18181b;}
.fe-tfoot-total td{border-top:2px solid #374151;}
.fe-alert{display:flex;align-items:center;gap:9px;padding:11px 14px;border-radius:8px;font-size:13px;margin-bottom:14px;}
.fe-alert-success{background:#dcfce7;border:1px solid #bbf7d0;color:#166534;}
.fe-alert-warning{background:#fef3c7;border:1px solid #fde68a;color:#92400e;}
.fe-save-status{font-size:12px;font-weight:600;padding:6px 12px;border-radius:7px;background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
#btnSaveAll {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 18px;
    background: transparent;
    color: #3b82f6;
    border: 1.5px solid #93c5fd;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    transition: background .18s, color .18s, border-color .18s;
    letter-spacing: .2px;
}
#btnSaveAll:hover:not(:disabled) {
    background: #eff6ff;
    border-color: #3b82f6;
    color: #1d4ed8;
}
#btnSaveAll:disabled {
    opacity: .5;
    cursor: not-allowed;
}
@media(max-width:900px){.fe-control-bar{gap:10px;}.fe-select{min-width:160px;}.fe-search-in{width:140px;}}
@media(max-width:600px){.fe-control-bar{flex-direction:column;align-items:stretch;}.fe-summary-strip{gap:12px;}.fe-sum-divider{display:none;}}
</style>

<!-- ════════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════════ -->
<script>
const MONTH_NAMES = <?php echo json_encode($month_names); ?>;

// Divisor used for auto-calculating fuel liters from per-month KM.
// This used to be a hardcoded constant; it is now user-editable via the
// "Fuel KM Divisor" input in the control bar (#fuelDivisor). The variable
// below just holds the last-known value as a fallback / cache — always
// prefer calling getDivisor() so the live input value is respected.
let FUEL_KM_DIVISOR = 45;

// Weeks-per-month multiplier used for auto-calculating Per Month KM from
// Per Week KM. This used to be hardcoded as × 4; it is now user-editable
// via the "Weeks / Month" control (#weekMultiplier), with a quick-pick
// dropdown (#weekMultiplierPreset) for common values. Always prefer
// calling getWeekMultiplier() so the live input value is respected.
let WEEK_MULTIPLIER = 4;

let currentPeriod    = null;
let currentRows      = [];
let lastMonthKmMap   = {};   // employee_id => per_week_km from previous period
let prefillRowIdxSet = new Set(); // row indices that were pre-filled from last month

/* ── Read the current Fuel KM Divisor from the control-bar input ── */
function getDivisor() {
    var divInp = document.getElementById('fuelDivisor');
    var val = divInp ? parseFloat(divInp.value) : NaN;
    if (!val || val <= 0) {
        val = FUEL_KM_DIVISOR || 45;
    }
    FUEL_KM_DIVISOR = val;
    return val;
}

/* ── Read the current Weeks/Month multiplier from the control-bar input ── */
function getWeekMultiplier() {
    var mInp = document.getElementById('weekMultiplier');
    var val = mInp ? parseFloat(mInp.value) : NaN;
    if (!val || val <= 0) {
        val = WEEK_MULTIPLIER || 4;
    }
    WEEK_MULTIPLIER = val;
    return val;
}

/* ── Quick-pick dropdown for common weeks/month values ── */
function applyWeekPreset(val) {
    if (val === 'custom') {
        document.getElementById('weekMultiplier').focus();
        document.getElementById('weekMultiplier').select();
        return;
    }
    var num = parseFloat(val);
    if (!num || num <= 0) return;
    document.getElementById('weekMultiplier').value = num;
    onWeekMultiplierChange();
}

/* ── Manual edit of the Weeks/Month input: sync preset dropdown ── */
function onWeekMultiplierInputChange() {
    var mInp   = document.getElementById('weekMultiplier');
    var preset = document.getElementById('weekMultiplierPreset');
    var val    = parseFloat(mInp.value);
    if (!val || val <= 0) {
        alert('Enter a valid weeks/month value (greater than 0).');
        mInp.value = (WEEK_MULTIPLIER || 4);
        return;
    }
    var matched = false;
    for (var i = 0; i < preset.options.length; i++) {
        if (preset.options[i].value === String(val)) {
            preset.value = preset.options[i].value;
            matched = true;
            break;
        }
    }
    if (!matched) preset.value = 'custom';
    onWeekMultiplierChange();
}

/* ── Weeks/Month changed / Recalc button clicked: re-derive Per Month KM
      (and therefore Fuel Liters) for every row from its Per Week KM ── */
function onWeekMultiplierChange() {
    var mInp = document.getElementById('weekMultiplier');
    var val  = parseFloat(mInp.value);
    if (!val || val <= 0) {
        alert('Enter a valid weeks/month value (greater than 0).');
        mInp.value = (WEEK_MULTIPLIER || 4);
        return;
    }
    WEEK_MULTIPLIER = val;
    var divisor = getDivisor();

    document.querySelectorAll('input.fe-td-km[data-km="week"]').forEach(function(wkInp) {
        var idx  = parseInt(wkInp.getAttribute('data-row'));
        var wkKm = parseFloat(wkInp.value) || 0;
        var moInp = document.querySelector('input.fe-td-km[data-km="month"][data-row="' + idx + '"]');
        if (!moInp) return;
        var moKm = wkKm > 0 ? wkKm * val : 0;
        moInp.value = moKm > 0 ? moKm.toFixed(1) : '';
        moInp.classList.toggle('has-val', moKm > 0);

        var literInp = document.querySelector('input.fe-td-liter[data-row="' + idx + '"]');
        if (literInp) {
            var liters = moKm > 0 ? moKm / divisor : 0;
            literInp.value = liters > 0 ? liters.toFixed(3) : '';
            literInp.classList.toggle('has-val', liters > 0);
            var tr = literInp.closest('tr');
            if (tr) tr.classList.toggle('has-data', liters > 0);
        }
        recalcRow(idx);
    });

    updateTotals();
}

/* ── Divisor changed / Recalc button clicked: re-derive Fuel Liters
      for every row from its current Per Month KM ── */
function onDivisorChange() {
    var divInp = document.getElementById('fuelDivisor');
    var val = parseFloat(divInp.value);
    if (!val || val <= 0) {
        alert('Enter a valid divisor (greater than 0).');
        divInp.value = (FUEL_KM_DIVISOR || 45);
        return;
    }
    FUEL_KM_DIVISOR = val;

    document.querySelectorAll('input.fe-td-km[data-km="month"]').forEach(function(moInp) {
        var idx = parseInt(moInp.getAttribute('data-row'));
        var moKm = parseFloat(moInp.value) || 0;
        var literInp = document.querySelector('input.fe-td-liter[data-row="' + idx + '"]');
        if (!literInp) return;
        var liters = moKm > 0 ? moKm / val : 0;
        literInp.value = liters > 0 ? liters.toFixed(3) : '';
        literInp.classList.toggle('has-val', liters > 0);
        var tr = literInp.closest('tr');
        if (tr) tr.classList.toggle('has-data', liters > 0);
        recalcRow(idx);
    });

    updateTotals();
}

/* ── On Load Button click ── */
function onControlChange() {
    var periodId   = document.getElementById('selPeriod').value;
    var categoryId = document.getElementById('selCategory').value;
    if (!periodId || !categoryId) {
        alert('Please select both a Payroll Period and a Staff Category first.');
        return;
    }
    loadEmployees(periodId, categoryId);
}

/* ── Step 1: Load Employees via AJAX ── */
function loadEmployees(periodId, categoryId) {
    showState('loading');
    document.getElementById('stateLoadingText').textContent = 'Loading employees…';
    document.getElementById('lastMonthBanner').style.display = 'none';
    lastMonthKmMap   = {};
    prefillRowIdxSet = new Set();

    fetch('sr_fuel_entry.php?ajax=load_employees'
        + '&period_id='   + encodeURIComponent(periodId)
        + '&category_id=' + encodeURIComponent(categoryId))
    .then(function(res) { return res.text(); })
    .then(function(text) {
        var data;
        try { data = JSON.parse(text); }
        catch (e) {
            showState('empty');
            document.getElementById('stateEmpty').innerHTML =
                '<div style="font-size:40px;margin-bottom:12px;">⚠️</div>' +
                '<p style="font-size:15px;font-weight:600;color:#991b1b;margin:0 0 6px;">JSON Parse Error</p>' +
                '<pre style="font-size:11px;color:#6b7280;text-align:left;max-height:200px;overflow:auto;background:#f9fafb;padding:10px;border-radius:6px;">'
                + escHtml(text.substring(0, 2000)) + '</pre>';
            return;
        }

        if (data.error) {
            showState('empty');
            document.getElementById('stateEmpty').innerHTML =
                '<div style="font-size:40px;margin-bottom:12px;">⚠️</div>' +
                '<p style="font-size:15px;font-weight:600;color:#991b1b;margin:0 0 6px;">Database Error</p>' +
                '<p style="font-size:12px;color:#6b7280;margin:0;font-family:monospace;">' + escHtml(data.error) + '</p>';
            return;
        }

        currentPeriod = data.period;
        currentRows   = data.employees;

        if (!data.employees || !data.employees.length) {
            showState('noEmp');
            return;
        }

        // ── Step 2: Fetch last month's KM, then render ──
        document.getElementById('stateLoadingText').textContent = 'Loading last month KM data…';

        fetch('sr_fuel_entry.php?ajax=load_last_month_km'
            + '&period_id='   + encodeURIComponent(periodId)
            + '&category_id=' + encodeURIComponent(categoryId))
        .then(function(r2) { return r2.text(); })
        .then(function(t2) {
            var km_data;
            try { km_data = JSON.parse(t2); } catch(e) { km_data = {km_map:{}}; }
            if (km_data.km_map && typeof km_data.km_map === 'object') {
                lastMonthKmMap = km_data.km_map;
            }
            renderTable(data.employees, data.period, km_data);
            showState('table');
        })
        .catch(function() {
            // If last-month fetch fails, still render without pre-fill
            renderTable(data.employees, data.period, null);
            showState('table');
        });
    })
    .catch(function(err) {
        showState('empty');
        document.getElementById('stateEmpty').innerHTML =
            '<div style="font-size:40px;margin-bottom:12px;">⚠️</div>' +
            '<p style="font-size:15px;font-weight:600;color:#991b1b;margin:0 0 6px;">Network Error</p>' +
            '<p style="font-size:12px;color:#6b7280;margin:0;">' + escHtml(err.message) + '</p>';
    });
}

/* ── Render Table ── */
function renderTable(emps, period, km_data) {
    var mon    = MONTH_NAMES[period.month] || '';
    var locked = period.status === 'Locked';

    document.getElementById('hPeriodId').value   = period.id;
    document.getElementById('hCategoryId').value = document.getElementById('selCategory').value;
    document.getElementById('tablePeriodBadge').textContent   = mon + ' ' + period.year;
    document.getElementById('tableLockedBadge').style.display = locked ? 'inline-flex' : 'none';
    document.getElementById('tableHeadLabel').textContent     = 'SR Fuel Entries';

    var statusEl = document.getElementById('sumPeriodStatus');
    statusEl.textContent = period.status;
    statusEl.style.color = locked ? '#9ca3af' : '#22c55e';
    document.getElementById('sumEmpCount').textContent     = emps.length;
    document.getElementById('summaryStrip').style.display  = 'flex';
    document.getElementById('btnSaveAll').disabled         = false;

    prefillRowIdxSet = new Set();
    var prefillCount = 0;
    var prevLabel = (km_data && km_data.prev_label) ? km_data.prev_label : 'last month';
    var divisor    = getDivisor();
    var weekMult   = getWeekMultiplier();

    var tbody = document.getElementById('fuelTableBody');
    tbody.innerHTML = '';

    emps.forEach(function(e, idx) {
        var empId   = parseInt(e.id);
        var rate    = parseFloat(e.fuel_rate)    || 0;
        var liters  = parseFloat(e.fuel_liter)   || 0;
        var perWkKm = parseFloat(e.per_week_km)  || 0;

        // ── Pre-fill per_week_km from last month if this period has no saved value ──
        var fromLastMonth = false;
        if (perWkKm === 0 && lastMonthKmMap[empId] && lastMonthKmMap[empId] > 0) {
            perWkKm       = parseFloat(lastMonthKmMap[empId]);
            fromLastMonth = true;
            prefillCount++;
            prefillRowIdxSet.add(idx);
        }

        var perMoKm = perWkKm * weekMult;

        // Auto-calculate liters if not saved
        if (liters === 0 && perMoKm > 0) {
            liters = perMoKm / divisor;
        }

        var amount  = liters * rate;
        var hasData = liters > 0;

        var row = document.createElement('tr');
        row.className = (hasData ? 'has-data' : '') + (fromLastMonth ? ' from-lastmonth' : '');
        row.setAttribute('data-search',
            (e.emp_code  || '').toLowerCase() + ' ' +
            (e.full_name || '').toLowerCase() + ' ' +
            (e.tr_code   || '').toLowerCase());
        row.setAttribute('data-emp-id',      e.id);
        row.setAttribute('data-year',        period.year);
        row.setAttribute('data-month',       period.month);
        row.setAttribute('data-from-lastmonth', fromLastMonth ? '1' : '0');

        // Per Week KM input — blue-highlighted if from last month
        var wkKmExtraClass = fromLastMonth ? ' prefilled-lastmonth' : '';
        var lastMonthBadge = '';

        row.innerHTML =
            '<td class="fe-rownum">' + (idx + 1) + '</td>' +
            '<td><span class="fe-srcode">' + escHtml(e.emp_code) + '</span></td>' +
            '<td style="font-weight:600;color:#111;">' +
                escHtml(e.full_name) +
                (e.tr_code ? '<div style="font-size:10px;color:#9ca3af;margin-top:1px;">TR: ' + escHtml(e.tr_code) + '</div>' : '') +
            '</td>' +
            '<td style="font-size:11px;color:#6b7280;font-weight:600;">' + escHtml(e.company_code || '—') + '</td>' +
            '<td>' + (e.designation_name
                ? '<span class="fe-desg">' + escHtml(e.designation_name) + '</span>'
                : '<span style="color:#ddd">—</span>') + '</td>' +

            // Per Week KM
            '<td style="padding:6px 10px;">' +
                '<div style="display:flex;align-items:center;gap:4px;">' +
                '<input type="number"' +
                '  class="fe-td-input fe-td-km' + (perWkKm > 0 ? ' has-val' : '') + wkKmExtraClass + '"' +
                '  value="' + (perWkKm > 0 ? perWkKm.toFixed(1) : '') + '"' +
                '  placeholder="" min="0" step="0.1"' +
                '  oninput="onWkKmChange(this,' + idx + ')"' +
                '  onkeydown="onEnterDown(event,this)"' +
                '  data-row="' + idx + '" data-km="week">' +
                lastMonthBadge +
                '</div>' +
            '</td>' +

            // Per Month KM (readonly — auto = perWkKm × current Weeks/Month multiplier)
            '<td style="padding:6px 10px;">' +
                '<input type="number"' +
                '  class="fe-td-input fe-td-km fe-td-km-month' + (perMoKm > 0 ? ' has-val' : '') + '"' +
                '  value="' + (perMoKm > 0 ? perMoKm.toFixed(1) : '') + '"' +
                '  placeholder="" readonly tabindex="-1"' +
                '  data-row="' + idx + '" data-km="month">' +
            '</td>' +

            // Fuel Rate
            '<td style="padding:6px 10px;">' +
                '<input type="number"' +
                '  class="fe-td-input fe-td-rate"' +
                '  value="' + (rate > 0 ? rate.toFixed(2) : '') + '"' +
                '  placeholder="" min="0" step="0.01"' +
                '  oninput="onRateChange(this,' + idx + ')"' +
                '  onkeydown="onEnterDown(event,this)"' +
                '  data-row="' + idx + '">' +
            '</td>' +

            // Fuel Liters (auto-filled; user can override)
            '<td style="padding:6px 10px;">' +
                '<input type="number"' +
                '  class="fe-td-input fe-td-liter' + (liters > 0 ? ' has-val' : '') + '"' +
                '  value="' + (liters > 0 ? liters.toFixed(3) : '') + '"' +
                '  placeholder="" min="0" step="0.001"' +
                '  oninput="onLiterChange(this,' + idx + ')"' +
                '  onkeydown="onEnterDown(event,this)"' +
                '  data-row="' + idx + '">' +
            '</td>' +

            // Amount
            '<td class="fe-amount-cell ' + (amount === 0 ? 'zero' : '') + '" id="amtCell_' + idx + '">' +
                (amount > 0 ? 'Rs. ' + numFmt(amount) : '—') +
            '</td>' +

            // Notes
            '<td style="padding:6px 10px;">' +
                '<input type="text" class="fe-td-notes"' +
                '  value="' + escHtml(e.notes || '') + '"' +
                '  placeholder="" onkeydown="onEnterDown(event,this)"' +
                '  data-row="' + idx + '">' +
            '</td>';

        tbody.appendChild(row);
    });

    // Show banner if any rows were pre-filled
    if (prefillCount > 0) {
        var banner = document.getElementById('lastMonthBanner');
        document.getElementById('lastMonthBannerText').textContent =
            prefillCount + ' employee' + (prefillCount > 1 ? 's' : '') +
            ' had no KM entered for this period — Per Week KM pre-filled from ' +
            prevLabel + '. Review and save to confirm.';
        banner.style.display = 'flex';
    } else {
        document.getElementById('lastMonthBanner').style.display = 'none';
    }

    updateTotals();
}

/* ── Clear last-month pre-filled values ── */
function clearLastMonthPrefill() {
    if (!confirm('Clear all KM values that were pre-filled from last month?')) return;
    prefillRowIdxSet.forEach(function(idx) {
        var wkInp = document.querySelector('input.fe-td-km[data-km="week"][data-row="' + idx + '"]');
        var moInp = document.querySelector('input.fe-td-km[data-km="month"][data-row="' + idx + '"]');
        var litInp= document.querySelector('input.fe-td-liter[data-row="' + idx + '"]');
        if (wkInp) {
            wkInp.value = '';
            wkInp.classList.remove('has-val','prefilled-lastmonth');
        }
        if (moInp) { moInp.value = ''; moInp.classList.remove('has-val'); }
        if (litInp){ litInp.value = ''; litInp.classList.remove('has-val'); }
        // Remove badge
        var badge = document.getElementById('lmBadge_' + idx);
        if (badge) badge.remove();
        // Remove row highlight
        var tr = document.querySelector('#fuelTableBody tr[data-emp-id]');
        document.querySelectorAll('#fuelTableBody tr').forEach(function(r) {
            if (parseInt(r.getAttribute('data-emp-id')) && r.classList.contains('from-lastmonth')) {
                var ri = r.querySelector('input.fe-td-km[data-row="' + idx + '"]');
                if (ri) r.classList.remove('from-lastmonth');
            }
        });
        recalcRow(idx);
    });
    prefillRowIdxSet = new Set();
    document.getElementById('lastMonthBanner').style.display = 'none';
    updateTotals();
}

/* ── Per Week KM → auto Per Month KM (× current Weeks/Month multiplier)
      → auto Fuel Liters (÷ current Fuel KM Divisor) ── */
function onWkKmChange(inp, idx) {
    var val = parseFloat(inp.value) || 0;
    inp.classList.toggle('has-val', val > 0);
    // Once user edits, remove last-month styling
    inp.classList.remove('prefilled-lastmonth');
    var badge = document.getElementById('lmBadge_' + idx);
    if (badge) badge.remove();
    prefillRowIdxSet.delete(idx);

    // Update Per Month KM (= Per Week KM × current Weeks/Month multiplier)
    var moInp = document.querySelector('input.fe-td-km[data-km="month"][data-row="' + idx + '"]');
    if (moInp) {
        var moKm = val * getWeekMultiplier();
        moInp.value = val > 0 ? moKm.toFixed(1) : '';
        moInp.classList.toggle('has-val', val > 0);

        // Auto-fill Fuel Liters = Per Month KM ÷ current Fuel KM Divisor
        var literInp = document.querySelector('input.fe-td-liter[data-row="' + idx + '"]');
        if (literInp) {
            var liters = moKm > 0 ? moKm / getDivisor() : 0;
            literInp.value = liters > 0 ? liters.toFixed(3) : '';
            literInp.classList.toggle('has-val', liters > 0);
            inp.closest('tr').classList.toggle('has-data', liters > 0);
            recalcRow(idx);
            updateTotals();
        }
    }
}

/* ── Enter → move to same column next row ── */
function onEnterDown(e, inp) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    var currentIdx  = parseInt(inp.getAttribute('data-row'));
    var currentKm   = inp.getAttribute('data-km');
    var currentClass = null;
    ['fe-td-rate','fe-td-liter','fe-td-km','fe-td-notes'].forEach(function(c) {
        if (inp.classList.contains(c)) currentClass = c;
    });
    var nextInp = null;
    if (currentClass === 'fe-td-km' && currentKm) {
        nextInp = document.querySelector(
            'input.fe-td-km[data-km="' + currentKm + '"][data-row="' + (currentIdx + 1) + '"]');
    } else if (currentClass) {
        nextInp = document.querySelector(
            'input.' + currentClass + '[data-row="' + (currentIdx + 1) + '"]');
    }
    if (nextInp && !nextInp.closest('tr').classList.contains('fe-hidden')) {
        nextInp.focus();
        nextInp.select();
    }
}

function onRateChange(inp, idx) { recalcRow(idx); updateTotals(); }

function onLiterChange(inp, idx) {
    var liter = parseFloat(inp.value) || 0;
    inp.classList.toggle('has-val', liter > 0);
    inp.closest('tr').classList.toggle('has-data', liter > 0);
    recalcRow(idx);
    updateTotals();
}

function recalcRow(idx) {
    var rateInp  = document.querySelector('input.fe-td-rate[data-row="'  + idx + '"]');
    var literInp = document.querySelector('input.fe-td-liter[data-row="' + idx + '"]');
    var amtCell  = document.getElementById('amtCell_' + idx);
    if (!rateInp || !literInp || !amtCell) return;
    var amount = (parseFloat(rateInp.value) || 0) * (parseFloat(literInp.value) || 0);
    amtCell.textContent = amount > 0 ? 'Rs. ' + numFmt(amount) : '—';
    amtCell.className   = 'fe-amount-cell' + (amount === 0 ? ' zero' : '');
}

function updateTotals() {
    var totalL = 0, totalA = 0;
    document.querySelectorAll('input.fe-td-liter').forEach(function(inp, idx) {
        var rateInp = document.querySelector('input.fe-td-rate[data-row="' + idx + '"]');
        var liters  = parseFloat(inp.value)       || 0;
        var rate    = parseFloat(rateInp ? rateInp.value : 0) || 0;
        totalL += liters;
        totalA += liters * rate;
    });
    document.getElementById('footTotalLiters').textContent = totalL.toFixed(3);
    document.getElementById('footTotalAmount').textContent = 'Rs. ' + numFmt(totalA);
    document.getElementById('sumTotalLiters').textContent  = totalL.toFixed(3);
    document.getElementById('sumTotalAmount').textContent  = 'Rs. ' + numFmt(totalA);
}

/* ── Bulk Rate Apply ── */
function applyBulkRate() {
    var rate = parseFloat(document.getElementById('bulkRate').value);
    if (!rate || rate <= 0) { alert('Enter a valid fuel rate.'); return; }
    document.querySelectorAll('input.fe-td-rate').forEach(function(inp) {
        inp.value = rate.toFixed(2);
        recalcRow(parseInt(inp.getAttribute('data-row')));
    });
    updateTotals();
}

/* ── Clear Liters ── */
function clearAllLiters() {
    if (!confirm('Clear all liter values?')) return;
    document.querySelectorAll('input.fe-td-liter').forEach(function(inp) {
        inp.value = '';
        inp.classList.remove('has-val');
        inp.closest('tr').classList.remove('has-data');
        recalcRow(parseInt(inp.getAttribute('data-row')));
    });
    updateTotals();
}

/* ── Search / Filter ── */
function filterTable(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('#fuelTableBody tr').forEach(function(row) {
        var s = row.getAttribute('data-search') || '';
        row.classList.toggle('fe-hidden', q.length > 0 && !s.includes(q));
    });
}

/* ── Save All ── */
function saveAllRows() {
    var periodId   = document.getElementById('hPeriodId').value;
    var categoryId = document.getElementById('hCategoryId').value;
    if (!periodId || !categoryId) { alert('No period/category loaded. Please click Load Table first.'); return; }

    var rows = [];
    document.querySelectorAll('#fuelTableBody tr').forEach(function(tr) {
        var empId   = tr.getAttribute('data-emp-id');
        var year    = tr.getAttribute('data-year');
        var month   = tr.getAttribute('data-month');
        var rate    = (tr.querySelector('input.fe-td-rate')  || {}).value  || '0';
        var liter   = (tr.querySelector('input.fe-td-liter') || {}).value  || '0';
        var perWkKm = (tr.querySelector('input.fe-td-km[data-km="week"]')  || {}).value || '0';
        var perMoKm = (tr.querySelector('input.fe-td-km[data-km="month"]') || {}).value || '0';
        var notes   = (tr.querySelector('input.fe-td-notes') || {}).value  || '';
        if (empId) rows.push({employee_id:empId, year:year, month:month,
            fuel_rate:rate, fuel_liter:liter,
            per_week_km:perWkKm, per_month_km:perMoKm, notes:notes});
    });

    if (!rows.length) { alert('No rows to save.'); return; }

    var btn = document.getElementById('btnSaveAll');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    btn.style.opacity = '0.6';

    var fd = new FormData();
    fd.append('action',      'save_fuel');
    fd.append('period_id',   periodId);
    fd.append('category_id', categoryId);
    rows.forEach(function(r, i) {
        Object.keys(r).forEach(function(k) { fd.append('rows[' + i + '][' + k + ']', r[k]); });
    });

    fetch('sr_fuel_entry.php', {method:'POST', body:fd})
    .then(function(res) { return res.text(); })
    .then(function() {
        // After save, clear all last-month pre-fill markers since values are now saved
        prefillRowIdxSet = new Set();
        document.querySelectorAll('.prefilled-lastmonth').forEach(function(inp) {
            inp.classList.remove('prefilled-lastmonth');
        });
        document.querySelectorAll('.fe-lastmonth-badge').forEach(function(b) { b.remove(); });
        document.getElementById('lastMonthBanner').style.display = 'none';

        var sb = document.getElementById('saveStatusBar');
        sb.textContent   = '✓ Saved ' + rows.length + ' row(s)';
        sb.style.display = 'block';
        setTimeout(function() { sb.style.display = 'none'; }, 4000);
        btn.disabled  = false;
        btn.style.opacity = '1';
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save All Entries';
    });
}

/* ── Export CSV ── */
function exportCSV() {
    var period = document.getElementById('tablePeriodBadge').textContent;
    var csv = 'SR Code,Employee Name,Company,Designation,Per Wk KM,Per Mo KM,Fuel Rate,Fuel Liters,Amount,Notes\n';
    document.querySelectorAll('#fuelTableBody tr:not(.fe-hidden)').forEach(function(tr) {
        var tds    = tr.querySelectorAll('td');
        var srCode = (tr.querySelector('.fe-srcode') || {}).textContent || '';
        var name   = tds[2] ? (tds[2].childNodes[0] || {}).textContent || '' : '';
        var comp   = tds[3] ? tds[3].textContent : '';
        var desg   = (tr.querySelector('.fe-desg')   || {}).textContent || '';
        var wkKm   = (tr.querySelector('input.fe-td-km[data-km="week"]')  || {}).value || '';
        var moKm   = (tr.querySelector('input.fe-td-km[data-km="month"]') || {}).value || '';
        var rate   = (tr.querySelector('input.fe-td-rate')  || {}).value  || '';
        var liter  = (tr.querySelector('input.fe-td-liter') || {}).value  || '';
        var amt    = tds[9] ? tds[9].textContent.replace('Rs. ','') : '';
        var notes  = (tr.querySelector('input.fe-td-notes') || {}).value  || '';
        csv += '"'+srCode.trim()+'","'+name.trim()+'","'+comp.trim()+'","'+desg.trim()+'","'+
               wkKm+'","'+moKm+'","'+rate+'","'+liter+'","'+amt.trim()+'","'+notes+'"\n';
    });
    var blob = new Blob([csv], {type:'text/csv'});
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href     = url;
    a.download = 'sr_fuel_' + period.replace(' ','_') + '.csv';
    a.click();
    URL.revokeObjectURL(url);
}

/* ── UI State Manager ── */
function showState(state) {
    document.getElementById('stateEmpty').style.display   = state === 'empty'   ? 'block' : 'none';
    document.getElementById('stateLoading').style.display = state === 'loading' ? 'block' : 'none';
    document.getElementById('stateNoEmp').style.display   = state === 'noEmp'   ? 'block' : 'none';
    document.getElementById('tableWrap').style.display    = state === 'table'   ? 'block' : 'none';
    if (state !== 'table') document.getElementById('summaryStrip').style.display = 'none';
}

/* ── Helpers ── */
function escHtml(s) {
    return String(s || '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function numFmt(n) {
    return Number(n).toLocaleString('en-LK', {minimumFractionDigits:2, maximumFractionDigits:2});
}
</script>

<?php include 'footer.php'; ?>