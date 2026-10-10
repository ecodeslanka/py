
<?php
/**
 * dlink_budget_entry.php  —  D-Link Budget Entry Page
 *
 * ACTUAL DB schema (after migration):
 *
 *   dlink_budget_entries:
 *     id, payroll_period_id (NOT NULL), year, month,
 *     actual_working_days, per_day_rate, bicycle_count,
 *     dlink_budget  (= actual_working_days * per_day_rate * bicycle_count),
 *     total_working_days, additional_total,
 *     total_budget  (= dlink_budget + additional_total),
 *     actual_per_day_rate (= total_budget / total_working_days),
 *     created_at, updated_at
 *
 *   dlink_additional_budgets:
 *     id, entry_id, sort_order, description, amount,
 *     attachment, original_name, created_at
 *
 *   dlink_employee_days:
 *     id, entry_id,
 *     employee_db_id   ← NEW: references employees.id  (the numeric PK)
 *     employee_id,     ← employee CODE  e.g. "EMP-001"
 *     employee_name,
 *     attended_days,
 *     created_at
 *
 * CHANGES vs original:
 *   - motorcycle_allowance  → bicycle_count  (column renamed via migration)
 *   - Attendance is entered MANUALLY in the modal (no auto-load from attendance table)
 *   - Per-employee days saved to dlink_employee_days
 *   - payroll_period_id is always looked up; save is blocked if not found
 *   - employee_db_id (employees.id) AND employee_id (employee_code) are both saved
 *   - employee_db_id & employee_id fields are read-only for pre-loaded MR rows
 *   - ── NEW ── When no entry exists for selected month, the modal pre-fills
 *               Actual Working Days, Per Day Rate, and Bicycle Count from the
 *               most recently saved entry (last month convenience pre-fill).
 *
 * NOTE: If your dlink_employee_days table does not yet have the employee_db_id column,
 *       run this migration first:
 *         ALTER TABLE dlink_employee_days
 *           ADD COLUMN employee_db_id INT NULL DEFAULT NULL
 *           AFTER entry_id;
 */

ob_start();
include 'config.php';

// ── AUTO-MIGRATION: add employee_db_id column if it doesn't exist yet ──
(function() use ($conn) {
    $db_res = $conn->query("SELECT DATABASE() AS db");
    $db_name = $db_res ? $db_res->fetch_assoc()['db'] : null;
    if (!$db_name) return;

    $col_check = $conn->query(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = '" . $conn->real_escape_string($db_name) . "'
           AND TABLE_NAME   = 'dlink_employee_days'
           AND COLUMN_NAME  = 'employee_db_id'
         LIMIT 1"
    );

    if ($col_check && $col_check->num_rows === 0) {
        $conn->query(
            "ALTER TABLE dlink_employee_days
             ADD COLUMN employee_db_id INT NULL DEFAULT NULL
             AFTER entry_id"
        );
        $conn->query(
            "ALTER TABLE dlink_employee_days
             ADD INDEX idx_emp_db_id (employee_db_id)"
        );
        $conn->errno;
    }
})();

// ── CONSTANTS ──────────────────────────────────────────────────────────
define('DLINK_UPLOAD_DIR', __DIR__ . '/uploads/dlink/');
if (!is_dir(DLINK_UPLOAD_DIR)) mkdir(DLINK_UPLOAD_DIR, 0755, true);

$month_names = ['','January','February','March','April','May','June',
                'July','August','September','October','November','December'];

// ── HELPER: get payroll_period_id  ────────────────────────────────────
function getPayrollPeriodId($conn, $yr, $mo) {
    $r = $conn->query("SELECT id FROM payroll_periods
                       WHERE year=$yr AND month=$mo LIMIT 1");
    if ($r && $r->num_rows) return intval($r->fetch_assoc()['id']);

    $date = sprintf('%04d-%02d-01', $yr, $mo);
    $r = $conn->query("SELECT id FROM payroll_periods
                       WHERE '$date' BETWEEN period_start AND period_end LIMIT 1");
    if ($r && $r->num_rows) return intval($r->fetch_assoc()['id']);

    $ym = sprintf('%04d-%02d', $yr, $mo);
    $r  = $conn->query("SELECT id FROM payroll_periods
                        WHERE DATE_FORMAT(period_date,'%Y-%m')='$ym' LIMIT 1");
    if ($r && $r->num_rows) return intval($r->fetch_assoc()['id']);

    return 0;
}

// ── AJAX: load entry data ──────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_entry') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $yr = intval($_GET['year']  ?? 0);
    $mo = intval($_GET['month'] ?? 0);
    if (!$yr || !$mo) {
        echo json_encode(['entry'=>null,'additionals'=>[],'employee_days'=>[]]);
        exit;
    }
    $res   = $conn->query("SELECT * FROM dlink_budget_entries
                           WHERE year=$yr AND month=$mo LIMIT 1");
    $entry = ($res && $res->num_rows) ? $res->fetch_assoc() : null;
    $addls = [];
    $edays = [];
    if ($entry) {
        $eid = intval($entry['id']);
        $ar  = $conn->query("SELECT * FROM dlink_additional_budgets
                             WHERE entry_id=$eid ORDER BY sort_order, id");
        if ($ar) while ($row = $ar->fetch_assoc()) $addls[] = $row;
        $dr  = $conn->query("SELECT * FROM dlink_employee_days
                             WHERE entry_id=$eid ORDER BY id");
        if ($dr) while ($row = $dr->fetch_assoc()) $edays[] = $row;
    }
    echo json_encode(['entry'=>$entry,'additionals'=>$addls,'employee_days'=>$edays]);
    exit;
}

// ── AJAX: delete entry ─────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'delete_dlink') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $yr = intval($_POST['year']  ?? 0);
    $mo = intval($_POST['month'] ?? 0);
    if (!$yr || !$mo) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid period']);
        exit;
    }
    $er = $conn->query("SELECT id FROM dlink_budget_entries
                        WHERE year=$yr AND month=$mo LIMIT 1");
    if (!$er || !$er->num_rows) {
        echo json_encode(['ok'=>false,'msg'=>'Entry not found']);
        exit;
    }
    $eid = intval($er->fetch_assoc()['id']);

    $ar  = $conn->query("SELECT attachment FROM dlink_additional_budgets
                         WHERE entry_id=$eid AND attachment IS NOT NULL");
    $del_files = [];
    if ($ar) while ($row = $ar->fetch_assoc()) $del_files[] = $row['attachment'];

    $ok = $conn->query("DELETE FROM dlink_budget_entries WHERE id=$eid");

    foreach ($del_files as $f) {
        $p = DLINK_UPLOAD_DIR . $f;
        if (file_exists($p)) @unlink($p);
    }

    echo json_encode(['ok'=>(bool)$ok, 'msg'=> $ok ? 'Deleted' : $conn->error]);
    exit;
}

// ── POST: save budget entry ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_dlink') {

    $yr          = intval($_POST['year']           ?? 0);
    $mo          = intval($_POST['month']          ?? 0);
    $act_days    = floatval($_POST['actual_days']  ?? 0);
    $per_day     = floatval($_POST['per_day_rate'] ?? 0);
    $bicycle     = floatval($_POST['bicycle_count']?? 0);

    $dlink_budget = $act_days * $per_day * $bicycle;

    $post_emp_db_ids = $_POST['emp_db_id'] ?? [];
    $post_emp_ids    = $_POST['emp_id']    ?? [];
    $post_emp_names  = $_POST['emp_name']  ?? [];
    $post_emp_days   = $_POST['emp_days']  ?? [];

    $total_working_days = 0;
    $emp_rows = [];
    foreach ($post_emp_ids as $i => $raw_eid) {
        $clean_eid    = $conn->real_escape_string(trim($raw_eid));
        $clean_name   = $conn->real_escape_string(trim($post_emp_names[$i] ?? ''));
        $d_val        = max(0, intval($post_emp_days[$i] ?? 0));
        $clean_db_id  = intval($post_emp_db_ids[$i] ?? 0);

        if ($clean_eid === '' && $clean_name === '') continue;

        $total_working_days += $d_val;
        $emp_rows[] = [
            'db_id' => $clean_db_id,
            'eid'   => $clean_eid,
            'name'  => $clean_name,
            'days'  => $d_val,
        ];
    }

    $addl_descs   = $_POST['addl_desc']          ?? [];
    $addl_amts    = $_POST['addl_amt']            ?? [];
    $addl_ex_file = $_POST['addl_existing_file']  ?? [];
    $addl_ex_name = $_POST['addl_existing_name']  ?? [];
    $addl_files   = $_FILES['addl_file']          ?? [];

    $additional_total = 0;
    $addl_rows        = [];

    foreach ($addl_descs as $i => $desc) {
        $d = $conn->real_escape_string(trim($desc));
        $a = floatval($addl_amts[$i] ?? 0);
        if ($d === '' && $a == 0) continue;

        $stored = $orig = null;
        if (!empty($addl_files['name'][$i]) && $addl_files['error'][$i] === UPLOAD_ERR_OK) {
            $o   = basename($addl_files['name'][$i]);
            $ext = strtolower(pathinfo($o, PATHINFO_EXTENSION));
            if (in_array($ext,['pdf','jpg','jpeg','png','xlsx','docx','xls','doc'])
                && $addl_files['size'][$i] <= 10*1024*1024) {
                $stored = 'dlink_'.time().'_'.$i.'_'.preg_replace('/[^a-zA-Z0-9._-]/','_',$o);
                $orig   = $o;
                move_uploaded_file($addl_files['tmp_name'][$i], DLINK_UPLOAD_DIR.$stored);
            }
        } elseif (!empty($addl_ex_file[$i])) {
            $stored = $conn->real_escape_string($addl_ex_file[$i]);
            $orig   = $conn->real_escape_string($addl_ex_name[$i] ?? $addl_ex_file[$i]);
        }

        $additional_total += $a;
        $addl_rows[]       = [
            'desc'     => $d,
            'amt'      => $a,
            'stored'   => $stored ? $conn->real_escape_string($stored) : null,
            'original' => $orig   ? $conn->real_escape_string($orig)   : null,
        ];
    }

    $total_budget   = $dlink_budget + $additional_total;
    $actual_per_day = $total_working_days > 0
                      ? $total_budget / $total_working_days : 0;

    $payroll_period_id = getPayrollPeriodId($conn, $yr, $mo);
    if (!$payroll_period_id) {
        $save_error = "No payroll period found for "
                    . $month_names[$mo] . " $yr. "
                    . "Please create the payroll period first.";
        goto render_page;
    }

    $ex       = $conn->query("SELECT id FROM dlink_budget_entries
                               WHERE year=$yr AND month=$mo LIMIT 1");
    $existing = ($ex && $ex->num_rows) ? $ex->fetch_assoc() : null;

    $conn->begin_transaction();
    try {
        $entry_id = 0;

        if ($existing) {
            $entry_id = intval($existing['id']);
            $conn->query("UPDATE dlink_budget_entries SET
                payroll_period_id   = $payroll_period_id,
                actual_working_days = $act_days,
                per_day_rate        = $per_day,
                bicycle_count       = $bicycle,
                dlink_budget        = $dlink_budget,
                additional_total    = $additional_total,
                total_budget        = $total_budget,
                total_working_days  = $total_working_days,
                actual_per_day_rate = $actual_per_day,
                updated_at          = NOW()
                WHERE id = $entry_id");
            if ($conn->error) throw new Exception('UPDATE failed: '.$conn->error);

            $conn->query("DELETE FROM dlink_additional_budgets WHERE entry_id=$entry_id");
            if ($conn->error) throw new Exception('Delete additionals failed: '.$conn->error);
            $conn->query("DELETE FROM dlink_employee_days WHERE entry_id=$entry_id");
            if ($conn->error) throw new Exception('Delete employee days failed: '.$conn->error);

        } else {
            $conn->query("INSERT INTO dlink_budget_entries
                (payroll_period_id, year, month,
                 actual_working_days, per_day_rate, bicycle_count,
                 dlink_budget, additional_total, total_budget,
                 total_working_days, actual_per_day_rate)
                VALUES
                ($payroll_period_id, $yr, $mo,
                 $act_days, $per_day, $bicycle,
                 $dlink_budget, $additional_total, $total_budget,
                 $total_working_days, $actual_per_day)");
            if ($conn->error) throw new Exception('INSERT failed: '.$conn->error);
            $entry_id = $conn->insert_id;
        }

        foreach ($emp_rows as $er) {
            $db_id_val = $er['db_id'] > 0 ? $er['db_id'] : 'NULL';
            $conn->query("INSERT INTO dlink_employee_days
                (entry_id, employee_db_id, employee_id, employee_name, attended_days)
                VALUES (
                    $entry_id,
                    $db_id_val,
                    '{$er['eid']}',
                    '{$er['name']}',
                    {$er['days']}
                )");
            if ($conn->error) throw new Exception('Employee days insert failed: '.$conn->error);
        }

        foreach ($addl_rows as $idx => $ar) {
            $st = $ar['stored']   ? "'{$ar['stored']}'"   : 'NULL';
            $or = $ar['original'] ? "'{$ar['original']}'" : 'NULL';
            $conn->query("INSERT INTO dlink_additional_budgets
                (entry_id, sort_order, description, amount, attachment, original_name)
                VALUES ($entry_id, $idx, '{$ar['desc']}', {$ar['amt']}, $st, $or)");
            if ($conn->error) throw new Exception('Additional insert failed: '.$conn->error);
        }

        $conn->commit();
        $pm = sprintf('%04d-%02d', $yr, $mo);
        header("Location: dlink_budget_entry.php?pmonth=$pm&saved=1");
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        $save_error = $e->getMessage();
    }
}

// ── Resolve selected period ────────────────────────────────────────────
render_page:
$pmonth_raw = trim($_GET['pmonth'] ?? '');
$sel_year   = 0;
$sel_month  = 0;
if (preg_match('/^(\d{4})-(\d{2})$/', $pmonth_raw, $m)) {
    $sel_year  = intval($m[1]);
    $sel_month = intval($m[2]);
}
if (!$sel_year || !$sel_month) {
    $sel_year   = (int)date('Y');
    $sel_month  = (int)date('n');
    $pmonth_raw = sprintf('%04d-%02d', $sel_year, $sel_month);
}

// ── MR staff list ─────────────────────────────────────────────────────
$mr_cat_res = $conn->query("SELECT id FROM staff_categories
    WHERE UPPER(category_code)='MR'
       OR UPPER(category_name) LIKE '%MR%' LIMIT 1");
$mr_cat_id  = 0;
if ($mr_cat_res && $mr_cat_res->num_rows) {
    $mr_cat_id = intval($mr_cat_res->fetch_assoc()['id']);
}

$mr_employees = [];
if ($mr_cat_id) {
    $er2 = $conn->query("SELECT id, employee_id, employee_full_name
                         FROM employees
                         WHERE staff_category_id=$mr_cat_id AND active=1
                         ORDER BY employee_full_name");
    if ($er2) while ($row = $er2->fetch_assoc()) $mr_employees[] = $row;
}

// ── Load existing entry ───────────────────────────────────────────────
$entry          = null;
$addl_items     = [];
$saved_emp_days = [];

$er3 = $conn->query("SELECT * FROM dlink_budget_entries
                     WHERE year=$sel_year AND month=$sel_month LIMIT 1");
if ($er3 && $er3->num_rows) {
    $entry = $er3->fetch_assoc();
    $eid   = intval($entry['id']);

    $ar3 = $conn->query("SELECT * FROM dlink_additional_budgets
                         WHERE entry_id=$eid ORDER BY sort_order, id");
    if ($ar3) while ($row = $ar3->fetch_assoc()) $addl_items[] = $row;

    $dr3 = $conn->query("SELECT * FROM dlink_employee_days
                         WHERE entry_id=$eid ORDER BY id");
    if ($dr3) while ($row = $dr3->fetch_assoc()) {
        $saved_emp_days[$row['employee_id']] = [
            'db_id' => intval($row['employee_db_id'] ?? 0),
            'days'  => intval($row['attended_days']),
            'name'  => $row['employee_name'],
        ];
    }
}

// ── ─────────────────────────────────────────────────────────────────────
// NEW: When no entry exists for the selected month, load the most recently
//      saved entry's base fields so the modal can pre-fill them as a
//      convenient starting point for the new entry.
// ── ─────────────────────────────────────────────────────────────────────
$last_entry_prefill = null;
if (!$entry) {
    $lr = $conn->query(
        "SELECT actual_working_days, per_day_rate, bicycle_count
         FROM dlink_budget_entries
         ORDER BY year DESC, month DESC
         LIMIT 1"
    );
    if ($lr && $lr->num_rows) {
        $last_entry_prefill = $lr->fetch_assoc();
    }
}

// ── Merge MR list with saved days ─────────────────────────────────────
$total_working_days = 0;
$display_employees  = [];

foreach ($mr_employees as $emp) {
    $saved = $saved_emp_days[$emp['employee_id']] ?? null;
    $days  = $saved ? $saved['days'] : 0;
    $total_working_days += $days;
    $display_employees[] = [
        'db_id' => intval($emp['id']),
        'eid'   => $emp['employee_id'],
        'name'  => $emp['employee_full_name'],
        'days'  => $days,
    ];
}

foreach ($saved_emp_days as $seid => $sdata) {
    $found = false;
    foreach ($display_employees as $de) {
        if ($de['eid'] === $seid) { $found = true; break; }
    }
    if (!$found) {
        $display_employees[] = [
            'db_id' => $sdata['db_id'],
            'eid'   => $seid,
            'name'  => $sdata['name'],
            'days'  => $sdata['days'],
        ];
        $total_working_days += $sdata['days'];
    }
}

// ── Convenience vars from saved entry ────────────────────────────────
$saved_act_days   = $entry ? floatval($entry['actual_working_days'])  : 0;
$saved_per_day    = $entry ? floatval($entry['per_day_rate'])          : 0;
$saved_bicycle    = $entry ? floatval($entry['bicycle_count'])         : 0;
$saved_dlink      = $entry ? floatval($entry['dlink_budget'])          : 0;
$saved_addl_total = $entry ? floatval($entry['additional_total'])      : 0;
$saved_total      = $entry ? floatval($entry['total_budget'])          : 0;
$saved_tot_days   = $entry ? floatval($entry['total_working_days'])    : 0;
$saved_per_day_r  = $entry ? floatval($entry['actual_per_day_rate'])   : 0;

$value_per_day = $saved_per_day_r;

// ── Period options dropdown ───────────────────────────────────────────
$period_options = [];
for ($y = $sel_year - 1; $y <= $sel_year + 1; $y++) {
    for ($mo_i = 1; $mo_i <= 12; $mo_i++) {
        $period_options[] = [
            'label' => $month_names[$mo_i].' '.$y,
            'val'   => sprintf('%04d-%02d', $y, $mo_i),
        ];
    }
}

ob_end_flush();
include 'header.php';

$saved_flash   = isset($_GET['saved'])   && $_GET['saved']   === '1';
$deleted_flash = isset($_GET['deleted']) && $_GET['deleted'] === '1';
?>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!--  PAGE HEADER                                                        -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title" style="display:flex;align-items:center;gap:10px;">
                <i class="fa-solid fa-wallet" style="color:#f59e0b;"></i>
                D-Link Budget Entry
            </h2>
            <p class="page-subtitle">Select payroll month, enter MR staff attendance manually, and manage budget</p>
        </div>
    </div>
</div>

<?php if ($saved_flash): ?>
<div class="dl-alert dl-alert-success">
    <i class="fa-solid fa-circle-check"></i>
    D-Link Budget entry saved successfully for
    <strong><?php echo htmlspecialchars($month_names[$sel_month].' '.$sel_year); ?></strong>.
</div>
<?php endif; ?>

<?php if ($deleted_flash): ?>
<div class="dl-alert dl-alert-success">
    <i class="fa-solid fa-circle-check"></i>
    D-Link Budget entry deleted successfully.
</div>
<?php endif; ?>

<?php if (!empty($save_error)): ?>
<div class="dl-alert dl-alert-danger">
    <i class="fa-solid fa-circle-exclamation"></i>
    <?php echo htmlspecialchars($save_error); ?>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!--  PERIOD SELECTOR BAR                                               -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<form method="GET" action="" id="periodForm" class="dl-period-bar">
    <div class="dl-period-group">
        <label class="dl-period-label"><i class="fa-solid fa-calendar-check"></i> Payroll Month</label>
        <select name="pmonth" id="selPmonth" class="dl-select" onchange="this.form.submit()">
            <?php foreach ($period_options as $po): ?>
            <option value="<?php echo $po['val']; ?>"
                <?php echo $po['val']===$pmonth_raw ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($po['label']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="dl-period-group">
        <label class="dl-period-label" style="visibility:hidden;">x</label>
        <button type="submit" class="dl-btn dl-btn-load">
            <i class="fa-solid fa-rotate"></i> Load
        </button>
    </div>
    <div class="dl-period-badge-wrap">
        <span class="dl-period-pill">
            <i class="fa-solid fa-calendar-days"></i>
            <?php echo htmlspecialchars($month_names[$sel_month].' '.$sel_year); ?>
        </span>
        <span class="<?php echo $entry ? 'dl-pill-saved' : 'dl-pill-new'; ?>">
            <?php if ($entry): ?>
            <i class="fa-solid fa-circle-check"></i> Saved
            <?php else: ?>
            <i class="fa-solid fa-plus"></i> No Entry Yet
            <?php endif; ?>
        </span>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
        <?php if ($entry): ?>
        <button type="button" class="dl-btn dl-btn-danger" onclick="dlConfirmDelete()">
            <i class="fa-solid fa-trash-can"></i> Delete Entry
        </button>
        <?php endif; ?>
        <button type="button" class="dl-btn dl-btn-primary" onclick="openBudgetModal()">
            <i class="fa-solid <?php echo $entry ? 'fa-pen-to-square' : 'fa-plus'; ?>"></i>
            <?php echo $entry ? 'Edit Entry' : 'New Budget Entry'; ?>
        </button>
    </div>
</form>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!--  SUMMARY CARDS (shown only when entry exists)                       -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<?php if ($entry):
    $cards = [
        ['Actual Working Days',  number_format($saved_act_days,1).' days', 'neutral','fa-calendar-days'],
        ['Per Day Rate',         'Rs. '.number_format($saved_per_day,2),   'neutral','fa-tag'],
        ['Bicycle Count',        number_format($saved_bicycle,0),          'neutral','fa-bicycle'],
        ['D-Link Budget',        'Rs. '.number_format($saved_dlink,2),     'amber',  'fa-wallet'],
        ['Additional Budget',    'Rs. '.number_format($saved_addl_total,2),'blue',   'fa-circle-plus'],
        ['Total Budget',         'Rs. '.number_format($saved_total,2),     'green',  'fa-coins'],
        ['Total Working Days',   number_format($saved_tot_days,1).' days', 'neutral','fa-users'],
        ['Actual Per Day Rate',  'Rs. '.number_format($saved_per_day_r,2), 'amber',  'fa-percent'],
    ];
?>
<div class="dl-summary-cards">
    <?php foreach ($cards as $c): ?>
    <div class="dl-sum-card dl-sum-<?php echo $c[2]; ?>">
        <div class="dl-sum-icon"><i class="fa-solid <?php echo $c[3]; ?>"></i></div>
        <div>
            <div class="dl-sum-label"><?php echo $c[0]; ?></div>
            <div class="dl-sum-val"><?php echo htmlspecialchars($c[1]); ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!--  MR EMPLOYEE TABLE                                                  -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="dl-card">
    <div class="dl-card-header">
        <div>
            <span class="dl-card-title">
                <i class="fa-solid fa-users" style="color:#f59e0b;"></i>
                MR Staff Attendance —
                <?php echo htmlspecialchars($month_names[$sel_month].' '.$sel_year); ?>
            </span>
            <span class="dl-card-sub">
                <?php echo count($display_employees); ?> employees
                <?php if ($entry): ?>
                &bull; Total <?php echo number_format($total_working_days); ?> days
                <?php else: ?>
                &bull; <em>Attendance entered via Budget Entry modal</em>
                <?php endif; ?>
            </span>
        </div>
        <?php if ($entry): ?>
        <div style="display:flex;gap:12px;align-items:center;">
            <div class="dl-mini-stat">
                <span>Total Working Days</span>
                <strong><?php echo number_format($saved_tot_days,1); ?></strong>
            </div>
            <div class="dl-mini-stat dl-mini-amber">
                <span>Actual Per Day Rate</span>
                <strong>Rs. <?php echo number_format($saved_per_day_r,2); ?></strong>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="dl-table-wrap">
        <table class="dl-table">
            <thead>
                <tr>
                    <th style="width:44px;">#</th>
                    <th style="width:60px;">DB ID</th>
                    <th style="width:120px;">Employee Code</th>
                    <th>Employee</th>
                    <th style="text-align:center;width:80px;">Days</th>
                    <th style="text-align:right;width:160px;">Per Day Rate (Rs.)</th>
                    <th style="text-align:right;width:160px;">Total (Rs.)</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($display_employees)): ?>
                <tr>
                    <td colspan="7" class="dl-empty-row">
                        <i class="fa-solid fa-users-slash"></i>
                        No MR category employees found
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($display_employees as $i => $emp):
                    $days     = $emp['days'];
                    $row_tot  = ($days > 0 && $entry) ? $days * $value_per_day : 0;
                    $has_days = $days > 0;
                ?>
                <tr class="<?php echo $has_days ? 'dl-row-active' : 'dl-row-zero'; ?>">
                    <td class="dl-td-num"><?php echo $i+1; ?></td>
                    <td class="dl-td-dbid">
                        <?php if ($emp['db_id'] > 0): ?>
                        <span class="dl-dbid-badge"><?php echo $emp['db_id']; ?></span>
                        <?php else: ?>
                        <span class="dl-dash">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="dl-td-eid">
                        <span class="dl-eid-badge"><?php echo htmlspecialchars($emp['eid']); ?></span>
                    </td>
                    <td class="dl-td-name">
                        <?php echo htmlspecialchars($emp['name']); ?>
                    </td>
                    <td class="dl-td-center">
                        <?php if ($entry): ?>
                            <?php if ($has_days): ?>
                            <span class="dl-days-badge"><?php echo $days; ?></span>
                            <?php else: ?>
                            <span class="dl-dash">0</span>
                            <?php endif; ?>
                        <?php else: ?>
                        <span class="dl-dash">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="dl-td-right">
                        <?php echo $entry
                            ? number_format($value_per_day,2)
                            : '<span class="dl-dash">—</span>'; ?>
                    </td>
                    <td class="dl-td-right dl-td-total">
                        <?php if ($has_days && $entry): ?>
                        <?php echo number_format($row_tot,0); ?>
                        <?php else: ?>
                        <span class="dl-dash">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="dl-tfoot-row">
                    <td colspan="4" class="dl-tfoot-label">TOTAL</td>
                    <td class="dl-tfoot-center">
                        <?php echo $entry ? number_format($total_working_days) : '—'; ?>
                    </td>
                    <td class="dl-tfoot-right"></td>
                    <td class="dl-tfoot-right dl-tfoot-total">
                        <?php if ($entry):
                            $grand = 0;
                            foreach ($display_employees as $emp) {
                                if ($emp['days'] > 0) $grand += $emp['days'] * $value_per_day;
                            }
                            echo number_format($grand,0);
                        else: echo '—'; endif; ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Additional budget lines display -->
    <?php if ($entry): ?>
    <div class="dl-addl-section">
        <div class="dl-addl-header">
            <span class="dl-addl-title">
                <i class="fa-solid fa-list-ul"></i> Additional Budget Lines
            </span>
            <button type="button" class="dl-btn dl-btn-sm dl-btn-amber" onclick="openBudgetModal()">
                <i class="fa-solid fa-pen-to-square"></i> Edit
            </button>
        </div>
        <?php if (!empty($addl_items)): ?>
        <table class="dl-addl-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="text-align:right;width:160px;">Amount (Rs.)</th>
                    <th style="width:200px;">Attachment</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($addl_items as $addl): ?>
                <tr>
                    <td><?php echo htmlspecialchars($addl['description']); ?></td>
                    <td style="text-align:right;font-weight:700;">
                        <?php echo number_format($addl['amount'],2); ?>
                    </td>
                    <td>
                        <?php if ($addl['attachment']): ?>
                        <a href="uploads/dlink/<?php echo rawurlencode($addl['attachment']); ?>"
                           target="_blank" class="dl-attach-link">
                            <i class="fa-solid fa-paperclip"></i>
                            <?php echo htmlspecialchars($addl['original_name'] ?? $addl['attachment']); ?>
                        </a>
                        <?php else: ?>
                        <span class="dl-dash">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td style="text-align:right;font-size:11px;font-weight:700;color:#6b7280;
                               letter-spacing:.5px;">TOTAL ADDITIONAL</td>
                    <td style="text-align:right;font-weight:800;color:#1e40af;">
                        Rs. <?php echo number_format($saved_addl_total,2); ?>
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        <?php else: ?>
        <p style="color:#9ca3af;font-size:13px;margin:0;padding:12px 0;">
            No additional budget lines saved.
        </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!--  DELETE CONFIRMATION MODAL                                          -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="dl-modal-overlay" id="dlDeleteOverlay">
<div class="dl-modal" style="max-width:440px;">
    <div class="dl-modal-header">
        <div class="dl-modal-title" style="color:#991b1b;">
            <i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;"></i>
            Delete Entry
        </div>
        <button class="dl-modal-close" onclick="closeDeleteModal()">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
    <div class="dl-modal-body">
        <p style="font-size:14px;color:#374151;margin:0 0 8px;">
            Are you sure you want to delete the D-Link Budget entry for
            <strong><?php echo htmlspecialchars($month_names[$sel_month].' '.$sel_year); ?></strong>?
        </p>
        <p style="font-size:13px;color:#6b7280;margin:0;">
            This permanently removes the entry, all attendance records, and all
            additional budget lines. Attached files will also be deleted.
            This cannot be undone.
        </p>
    </div>
    <div class="dl-modal-footer">
        <button type="button" class="dl-btn dl-btn-ghost" onclick="closeDeleteModal()">
            <i class="fa-solid fa-xmark"></i> Cancel
        </button>
        <button type="button" class="dl-btn dl-btn-danger"
                id="dlBtnConfirmDelete" onclick="dlExecuteDelete()">
            <i class="fa-solid fa-trash-can"></i> Yes, Delete
        </button>
    </div>
</div>
</div>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!--  BUDGET ENTRY MODAL                                                 -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="dl-modal-overlay" id="dlModalOverlay" onclick="onOverlayClick(event)">
<div class="dl-modal" id="dlModal">

    <div class="dl-modal-header">
        <div class="dl-modal-title">
            <i class="fa-solid fa-wallet" style="color:#f59e0b;"></i>
            D-Link Budget Entry
            <span class="dl-modal-period-tag">
                <?php echo htmlspecialchars($month_names[$sel_month].' '.$sel_year); ?>
            </span>
        </div>
        <button class="dl-modal-close" onclick="closeBudgetModal()">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <div class="dl-modal-body">
    <form id="dlBudgetForm" method="POST" enctype="multipart/form-data" action="">
        <input type="hidden" name="action" value="save_dlink">
        <input type="hidden" name="year"   value="<?php echo $sel_year; ?>">
        <input type="hidden" name="month"  value="<?php echo $sel_month; ?>">

        <!-- Base fields -->
        <div class="dl-form-divider">
            <i class="fa-solid fa-sliders" style="color:#f59e0b;"></i> Base Information
        </div>
        <div class="dl-form-grid">
            <div class="dl-form-group">
                <label class="dl-form-label">
                    <i class="fa-solid fa-calendar-days"></i> Actual Working Days
                </label>
                <input type="number" id="mActualDays" name="actual_days"
                       class="dl-form-input" placeholder="e.g. 22"
                       min="0" step="0.5" oninput="dlRecalc()" required value="">
            </div>
            <div class="dl-form-group">
                <label class="dl-form-label">
                    <i class="fa-solid fa-tag"></i> Per Day Rate (Rs.)
                </label>
                <input type="number" id="mPerDayRate" name="per_day_rate"
                       class="dl-form-input" placeholder="e.g. 150"
                       min="0" step="0.01" oninput="dlRecalc()" required value="">
            </div>
            <div class="dl-form-group">
                <label class="dl-form-label">
                    <i class="fa-solid fa-bicycle"></i> Bicycle Count
                </label>
                <input type="number" id="mBicycleCount" name="bicycle_count"
                       class="dl-form-input" placeholder="e.g. 5"
                       min="0" step="1" oninput="dlRecalc()" required value="">
                <small style="color:#6b7280;font-size:11px;margin-top:3px;">
                    D-Link Budget = Actual Days × Per Day Rate × Bicycle Count
                </small>
            </div>
            <div class="dl-form-group">
                <label class="dl-form-label">
                    <i class="fa-solid fa-calculator" style="color:#f59e0b;"></i>
                    D-Link Budget (auto)
                </label>
                <input type="text" id="mDlinkBudget"
                       class="dl-form-input dl-auto-field" readonly
                       placeholder="Days × Per Day Rate × Bicycle Count">
            </div>
            <div class="dl-form-group">
                <label class="dl-form-label">
                    <i class="fa-solid fa-users"></i> Total Working Days (auto)
                </label>
                <input type="text" id="mTotalWorkDaysDisplay"
                       class="dl-form-input dl-auto-field" readonly
                       placeholder="Sum of attendance below">
                <input type="hidden" id="mTotalWorkDays" name="total_work_days">
                <small style="color:#6b7280;font-size:11px;margin-top:3px;">
                    Auto-summed from the attendance table below
                </small>
            </div>
            <div class="dl-form-group">
                <label class="dl-form-label">
                    <i class="fa-solid fa-percent" style="color:#f59e0b;"></i>
                    Actual Per Day Rate (auto)
                </label>
                <input type="text" id="mActualPerDay"
                       class="dl-form-input dl-amber-field" readonly
                       placeholder="Total Budget ÷ Total Days">
            </div>
            <div class="dl-form-group dl-span2">
                <label class="dl-form-label">
                    <i class="fa-solid fa-coins" style="color:#22c55e;"></i>
                    Total Budget (auto)
                </label>
                <input type="text" id="mTotalBudget"
                       class="dl-form-input dl-total-field" readonly
                       placeholder="D-Link Budget + Additional">
            </div>
        </div>

        <!-- Manual attendance table -->
        <div class="dl-form-divider">
            <i class="fa-solid fa-calendar-check" style="color:#f59e0b;"></i>
            Manual Attendance Entry
        </div>
        <div class="dl-att-section">
            <div class="dl-att-head">
                <span class="dl-att-title">
                    <i class="fa-solid fa-users"></i> MR Staff — Days Attended
                </span>
                <button type="button" class="dl-btn dl-btn-sm dl-btn-amber"
                        onclick="dlAddEmpRow()">
                    <i class="fa-solid fa-user-plus"></i> Add Row
                </button>
            </div>
            <div class="dl-att-table-wrap">
                <table class="dl-att-table">
                    <thead>
                        <tr>
                            <th style="width:34px;">#</th>
                            <th style="width:60px;">DB ID</th>
                            <th style="width:110px;">Emp. Code</th>
                            <th>Employee Name</th>
                            <th style="width:100px;text-align:center;">Days</th>
                            <th style="width:34px;"></th>
                        </tr>
                    </thead>
                    <tbody id="dlAttBody"></tbody>
                    <tfoot>
                        <tr class="dl-att-tfoot">
                            <td colspan="4"
                                style="text-align:right;padding:8px 10px;
                                       font-size:11px;font-weight:700;
                                       color:#9ca3af;letter-spacing:.5px;">
                                TOTAL DAYS
                            </td>
                            <td id="dlAttTotalDays"
                                style="text-align:center;padding:8px 10px;
                                       font-weight:900;color:#fbbf24;font-size:15px;">
                                0
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Additional budgets -->
        <div class="dl-form-divider">
            <i class="fa-solid fa-plus-circle" style="color:#f59e0b;"></i>
            Additional Budgets
        </div>
        <div class="dl-addl-form-section">
            <div class="dl-addl-form-head">
                <span class="dl-addl-form-title">
                    <i class="fa-solid fa-list-ul"></i> Additional Budget Lines
                </span>
                <button type="button" class="dl-btn dl-btn-sm dl-btn-amber"
                        onclick="dlAddAdditionalRow()">
                    <i class="fa-solid fa-plus"></i> Add Line
                </button>
            </div>
            <div id="dlAddlRows"></div>
            <div class="dl-addl-total-row">
                <span class="dl-addl-total-label">Total Additional</span>
                <span class="dl-addl-total-val" id="dlAddlTotal">Rs. 0.00</span>
            </div>
        </div>

        <div class="dl-form-info">
            <i class="fa-solid fa-circle-info" style="flex-shrink:0;margin-top:2px;"></i>
            <span>
                <strong>D-Link Budget</strong> = Actual Days × Per Day Rate × Bicycle Count
                &nbsp;|&nbsp;
                <strong>Total Budget</strong> = D-Link Budget + Additional
                &nbsp;|&nbsp;
                <strong>Actual Per Day Rate</strong> = Total Budget ÷ Total Working Days
            </span>
        </div>
    </form>
    </div>

    <div class="dl-modal-footer">
        <button type="button" class="dl-btn dl-btn-ghost" onclick="closeBudgetModal()">
            <i class="fa-solid fa-xmark"></i> Cancel
        </button>
        <button type="button" class="dl-btn dl-btn-success"
                id="dlBtnSave" onclick="dlSave()">
            <i class="fa-solid fa-floppy-disk"></i> Save Entry
        </button>
    </div>
</div>
</div>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!--  STYLES                                                             -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<style>
/* ── Layout ── */
.dl-period-bar{display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 20px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.dl-period-group{display:flex;flex-direction:column;gap:5px;}
.dl-period-label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:5px;}
.dl-select{padding:9px 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;background:#fff;color:#111;cursor:pointer;min-width:200px;transition:border-color .2s;}
.dl-select:focus{outline:none;border-color:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.1);}
.dl-period-badge-wrap{display:flex;align-items:center;gap:8px;padding-bottom:2px;}
.dl-period-pill{display:inline-flex;align-items:center;gap:6px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;}
.dl-pill-saved{display:inline-flex;align-items:center;gap:5px;background:#dcfce7;color:#166534;border:1px solid #bbf7d0;border-radius:20px;padding:4px 12px;font-size:11px;font-weight:700;}
.dl-pill-new{display:inline-flex;align-items:center;gap:5px;background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;border-radius:20px;padding:4px 12px;font-size:11px;font-weight:700;}

/* ── Buttons ── */
.dl-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;transition:all .18s;font-family:inherit;white-space:nowrap;}
.dl-btn:disabled{opacity:.6;cursor:not-allowed;}
.dl-btn-sm{padding:6px 12px;font-size:12px;}
.dl-btn-load{background:#18181b;color:#fff;border:1px solid #374151;}
.dl-btn-load:hover{background:#374151;}
.dl-btn-primary{background:#f59e0b;color:#fff;}
.dl-btn-primary:hover{background:#d97706;transform:translateY(-1px);box-shadow:0 4px 12px rgba(245,158,11,.35);}
.dl-btn-amber{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.dl-btn-amber:hover{background:#fde68a;}
.dl-btn-ghost{background:#f9fafb;color:#6b7280;border:1px solid #e5e7eb;}
.dl-btn-ghost:hover{background:#e5e7eb;}
.dl-btn-success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
.dl-btn-success:hover{background:#22c55e;color:#fff;border-color:#22c55e;}
.dl-btn-danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.dl-btn-danger:hover{background:#ef4444;color:#fff;border-color:#ef4444;}

/* ── Alerts ── */
.dl-alert{display:flex;align-items:center;gap:9px;padding:11px 16px;border-radius:9px;font-size:13px;margin-bottom:14px;}
.dl-alert-success{background:#dcfce7;border:1px solid #bbf7d0;color:#166534;}
.dl-alert-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}

/* ── Summary cards ── */
.dl-summary-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px;}
.dl-sum-card{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.dl-sum-icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.dl-sum-neutral .dl-sum-icon{background:#f3f4f6;color:#6b7280;}
.dl-sum-amber   .dl-sum-icon{background:#fef3c7;color:#d97706;}
.dl-sum-green   .dl-sum-icon{background:#dcfce7;color:#16a34a;}
.dl-sum-blue    .dl-sum-icon{background:#dbeafe;color:#2563eb;}
.dl-sum-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#6b7280;margin-bottom:3px;}
.dl-sum-val{font-size:16px;font-weight:800;color:#111;line-height:1.2;}
.dl-sum-amber .dl-sum-val{color:#d97706;}
.dl-sum-green .dl-sum-val{color:#15803d;}
.dl-sum-blue  .dl-sum-val{color:#1d4ed8;}

/* ── Card ── */
.dl-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.05);margin-bottom:16px;}
.dl-card-header{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;background:#fafafa;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;gap:10px;}
.dl-card-title{font-size:14px;font-weight:800;color:#111;display:flex;align-items:center;gap:7px;}
.dl-card-sub{font-size:12px;color:#6b7280;margin-left:3px;}
.dl-mini-stat{display:flex;flex-direction:column;align-items:flex-end;gap:2px;padding:6px 12px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;}
.dl-mini-stat span{font-size:10px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
.dl-mini-stat strong{font-size:15px;font-weight:800;color:#111;}
.dl-mini-amber strong{color:#d97706;}

/* ── Main table ── */
.dl-table-wrap{overflow-x:auto;}
.dl-table{width:100%;border-collapse:collapse;font-size:13px;}
.dl-table thead tr{background:#1c1c1e;}
.dl-table thead th{padding:10px 14px;color:#e5e7eb;font-size:10px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;white-space:nowrap;}
.dl-table tbody tr{border-bottom:1px solid #f3f4f6;transition:background .12s;}
.dl-table tbody tr:hover{background:#fffbeb;}
.dl-table td{padding:11px 14px;color:#111;}
.dl-row-active{background:#fefce8;}
.dl-row-zero{background:#fff;}
.dl-td-num{color:#9ca3af;font-size:12px;font-weight:600;text-align:center;}
.dl-td-dbid{white-space:nowrap;text-align:center;}
.dl-dbid-badge{display:inline-block;background:#ede9fe;color:#5b21b6;border:1px solid #ddd6fe;border-radius:6px;padding:2px 7px;font-size:11px;font-weight:800;font-family:monospace;}
.dl-td-eid{white-space:nowrap;}
.dl-eid-badge{display:inline-block;background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700;font-family:monospace;}
.dl-td-name{font-weight:600;}
.dl-td-center{text-align:center;}
.dl-td-right{text-align:right;font-weight:600;font-variant-numeric:tabular-nums;}
.dl-td-total{color:#b45309;font-weight:700;}
.dl-days-badge{display:inline-block;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:800;}
.dl-dash{color:#d1d5db;font-weight:400;}
.dl-empty-row{text-align:center;padding:40px;color:#9ca3af;font-size:13px;}
.dl-empty-row i{display:block;font-size:28px;margin-bottom:8px;color:#d1d5db;}
.dl-tfoot-row{background:#1c1c1e!important;}
.dl-tfoot-label{padding:11px 14px;color:#9ca3af;font-size:11px;font-weight:700;letter-spacing:.5px;text-align:right!important;}
.dl-tfoot-center{text-align:center;padding:11px 14px;font-weight:900;color:#fbbf24;font-size:15px;}
.dl-tfoot-right{text-align:right;padding:11px 14px;color:#d1d5db;font-size:12px;}
.dl-tfoot-total{color:#fbbf24!important;font-weight:900;font-size:14px;}

/* ── Additional lines (display) ── */
.dl-addl-section{padding:16px 20px;border-top:2px solid #f3f4f6;}
.dl-addl-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
.dl-addl-title{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#374151;display:flex;align-items:center;gap:6px;}
.dl-addl-table{width:100%;border-collapse:collapse;font-size:13px;}
.dl-addl-table thead tr{background:#f9fafb;border-bottom:1px solid #e5e7eb;}
.dl-addl-table th{padding:9px 14px;color:#6b7280;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;}
.dl-addl-table tbody tr{border-bottom:1px solid #f3f4f6;}
.dl-addl-table td{padding:9px 14px;color:#111;}
.dl-addl-table tfoot td{padding:9px 14px;font-size:11px;border-top:1px solid #e5e7eb;}
.dl-attach-link{display:inline-flex;align-items:center;gap:5px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:5px;padding:2px 8px;font-size:11px;font-weight:600;text-decoration:none;}
.dl-attach-link:hover{background:#fde68a;}

/* ── Modal ── */
.dl-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(3px);}
.dl-modal-overlay.open{display:flex;animation:dlFadeIn .2s ease;}
@keyframes dlFadeIn{from{opacity:0}to{opacity:1}}
.dl-modal{background:#fff;border-radius:16px;width:100%;max-width:900px;max-height:92vh;overflow-y:auto;box-shadow:0 8px 40px rgba(0,0,0,.18);animation:dlSlideUp .25s ease;}
@keyframes dlSlideUp{from{transform:translateY(28px);opacity:0}to{transform:translateY(0);opacity:1}}
.dl-modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 24px 14px;border-bottom:1px solid #e5e7eb;position:sticky;top:0;background:#fff;z-index:10;border-radius:16px 16px 0 0;}
.dl-modal-title{display:flex;align-items:center;gap:9px;font-size:16px;font-weight:800;color:#111;}
.dl-modal-period-tag{font-size:12px;font-weight:600;color:#6b7280;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:3px 10px;margin-left:4px;}
.dl-modal-close{width:32px;height:32px;border-radius:8px;border:1px solid #e5e7eb;background:#f9fafb;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#6b7280;transition:all .15s;font-size:14px;}
.dl-modal-close:hover{background:#fef2f2;color:#991b1b;border-color:#fecaca;}
.dl-modal-body{padding:18px 24px;}
.dl-modal-footer{display:flex;align-items:center;justify-content:flex-end;gap:10px;padding:12px 24px 18px;border-top:1px solid #e5e7eb;position:sticky;bottom:0;background:#fff;border-radius:0 0 16px 16px;}

/* ── Form ── */
.dl-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 20px;margin-bottom:18px;}
.dl-form-group{display:flex;flex-direction:column;gap:5px;}
.dl-span2{grid-column:span 2;}
.dl-form-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;display:flex;align-items:center;gap:5px;}
.dl-form-input{padding:9px 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;color:#111;font-weight:600;transition:border-color .2s,box-shadow .2s;background:#fff;width:100%;box-sizing:border-box;}
.dl-form-input:focus{outline:none;border-color:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.1);}
.dl-auto-field{background:#eff6ff!important;border-color:#bfdbfe!important;color:#1e40af!important;}
.dl-amber-field{background:#fffbeb!important;border-color:#fde68a!important;color:#92400e!important;font-weight:800!important;}
.dl-total-field{background:#1c1c1e!important;color:#fbbf24!important;border-color:#3f3f46!important;font-size:15px!important;font-weight:900!important;}
.dl-form-divider{display:flex;align-items:center;gap:10px;margin:4px 0 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;}
.dl-form-divider::before,.dl-form-divider::after{content:'';flex:1;height:1px;background:#e5e7eb;}
.dl-form-info{display:flex;gap:9px;padding:10px 14px;border-radius:8px;font-size:12px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;margin-top:12px;}

/* ── Prefill hint banner ── */
.dl-prefill-hint{display:flex;align-items:center;gap:9px;padding:9px 14px;border-radius:8px;font-size:12px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;margin-bottom:14px;}
.dl-prefill-hint i{flex-shrink:0;}

/* ── Attendance table (inside modal) ── */
.dl-att-section{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;margin-bottom:18px;}
.dl-att-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
.dl-att-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#111;display:flex;align-items:center;gap:6px;}
.dl-att-table-wrap{overflow-x:auto;border-radius:8px;border:1px solid #e5e7eb;background:#fff;}
.dl-att-table{width:100%;border-collapse:collapse;font-size:13px;}
.dl-att-table thead tr{background:#1c1c1e;}
.dl-att-table thead th{padding:9px 10px;color:#e5e7eb;font-size:10px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;white-space:nowrap;}
.dl-att-table tbody tr{border-bottom:1px solid #f3f4f6;}
.dl-att-table tbody tr:hover{background:#fffbeb;}
.dl-att-table td{padding:6px 8px;}
.dl-att-tfoot{background:#1c1c1e!important;}
.dl-att-input{padding:6px 9px;border:1.5px solid #e5e7eb;border-radius:6px;font-size:13px;font-family:inherit;color:#111;font-weight:600;background:#fff;width:100%;box-sizing:border-box;transition:border-color .2s;}
.dl-att-input:focus{outline:none;border-color:#f59e0b;box-shadow:0 0 0 2px rgba(245,158,11,.1);}
.dl-att-days-input{text-align:center;}
.dl-att-input.dl-att-readonly{background:#f3f4f6!important;color:#374151!important;border-color:#e5e7eb!important;font-family:monospace;font-weight:700;cursor:default;}
.dl-att-dbid-cell{text-align:center;vertical-align:middle;}
.dl-att-dbid-pill{display:inline-block;background:#ede9fe;color:#5b21b6;border:1px solid #ddd6fe;border-radius:5px;padding:2px 7px;font-size:11px;font-weight:800;font-family:monospace;white-space:nowrap;}
.dl-att-dbid-none{color:#d1d5db;font-size:12px;}
.dl-att-num{color:#9ca3af;font-size:12px;font-weight:600;text-align:center;width:34px;}
.dl-att-btn-rm{width:28px;height:28px;border-radius:6px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:11px;transition:all .15s;}
.dl-att-btn-rm:hover{background:#ef4444;color:#fff;border-color:#ef4444;}

/* ── Additional form rows ── */
.dl-addl-form-section{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;margin-bottom:14px;}
.dl-addl-form-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
.dl-addl-form-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#111;display:flex;align-items:center;gap:6px;}
.dl-addl-row{display:grid;grid-template-columns:1fr 160px 34px;gap:8px;align-items:center;margin-bottom:6px;animation:dlRowIn .2s ease;}
@keyframes dlRowIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
.dl-attach-row{margin-bottom:10px;}
.dl-attach-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border:1.5px dashed #e5e7eb;border-radius:7px;background:#fff;color:#6b7280;font-size:11px;font-weight:600;cursor:pointer;transition:all .15s;font-family:inherit;width:100%;justify-content:center;box-sizing:border-box;}
.dl-attach-btn:hover{border-color:#f59e0b;color:#92400e;background:#fffbeb;}
.dl-attach-btn.has-file{border-style:solid;border-color:#bbf7d0;color:#166534;background:#dcfce7;}
.dl-btn-remove{width:32px;height:32px;border-radius:7px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:12px;transition:all .15s;flex-shrink:0;}
.dl-btn-remove:hover{background:#ef4444;color:#fff;border-color:#ef4444;}
.dl-addl-total-row{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:10px;padding-top:10px;border-top:1px solid #e5e7eb;}
.dl-addl-total-label{font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;}
.dl-addl-total-val{font-size:16px;font-weight:800;color:#1e40af;min-width:120px;text-align:right;}

@media(max-width:640px){
    .dl-form-grid{grid-template-columns:1fr;}
    .dl-span2{grid-column:span 1;}
    .dl-modal{max-height:98vh;}
    .dl-period-bar{flex-direction:column;}
    .dl-summary-cards{grid-template-columns:1fr 1fr;}
}
</style>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!--  JAVASCRIPT                                                         -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<script>
// ── PHP → JS data ────────────────────────────────────────────────────────
var dlSelYear  = <?php echo $sel_year; ?>;
var dlSelMonth = <?php echo $sel_month; ?>;

// Current month's saved entry (null if not yet saved)
var dlSavedEntry = <?php echo $entry ? json_encode([
    'actual_working_days' => floatval($entry['actual_working_days']),
    'per_day_rate'         => floatval($entry['per_day_rate']),
    'bicycle_count'        => floatval($entry['bicycle_count']),
], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) : 'null'; ?>;

// ── NEW: last saved entry prefill (used when dlSavedEntry is null) ──────
// Contains actual_working_days, per_day_rate, bicycle_count from the
// most recently saved entry across all months, so the user doesn't have
// to retype the same base values every month.
var dlLastEntry = <?php echo $last_entry_prefill ? json_encode([
    'actual_working_days' => floatval($last_entry_prefill['actual_working_days']),
    'per_day_rate'         => floatval($last_entry_prefill['per_day_rate']),
    'bicycle_count'        => floatval($last_entry_prefill['bicycle_count']),
], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) : 'null'; ?>;

var mrEmployees = <?php echo json_encode(
    array_map(fn($e) => [
        'db_id'      => intval($e['db_id']),
        'eid'        => $e['eid'],
        'name'       => $e['name'],
        'days'       => $e['days'],
        'fromMrList' => true,
    ], $display_employees),
    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;

var existingAddl = <?php echo json_encode($addl_items,
    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;

var dlEmpRowCount  = 0;
var dlAddlRowCount = 0;

// ── Modal open/close ────────────────────────────────────────────────────
function openBudgetModal() {
    document.getElementById('dlAttBody').innerHTML  = '';
    document.getElementById('dlAddlRows').innerHTML = '';
    dlEmpRowCount  = 0;
    dlAddlRowCount = 0;

    // ── Pre-fill base fields ──────────────────────────────────────────
    // Priority: current saved entry → last entry prefill → blank
    var prefill = dlSavedEntry || dlLastEntry;

    if (prefill) {
        document.getElementById('mActualDays').value   = prefill.actual_working_days;
        document.getElementById('mPerDayRate').value   = prefill.per_day_rate;
        document.getElementById('mBicycleCount').value = prefill.bicycle_count;

        // Show a subtle hint banner when values come from a previous month
        var hintEl = document.getElementById('dlPrefillHint');
        if (!dlSavedEntry && dlLastEntry) {
            if (hintEl) hintEl.style.display = 'flex';
        } else {
            if (hintEl) hintEl.style.display = 'none';
        }
    } else {
        document.getElementById('mActualDays').value   = '';
        document.getElementById('mPerDayRate').value   = '';
        document.getElementById('mBicycleCount').value = '';
        var hintEl = document.getElementById('dlPrefillHint');
        if (hintEl) hintEl.style.display = 'none';
    }

    // Attendance rows
    if (mrEmployees && mrEmployees.length > 0) {
        mrEmployees.forEach(function(emp) {
            dlAddEmpRow(emp.db_id, emp.eid, emp.name, emp.days || 0, emp.fromMrList);
        });
    } else {
        dlAddEmpRow(0, '', '', 0, false);
    }

    // Additional rows
    if (existingAddl && existingAddl.length > 0) {
        existingAddl.forEach(function(r) {
            dlAddAdditionalRow(r.description, r.amount,
                               r.original_name || '', r.attachment || '');
        });
    } else {
        dlAddAdditionalRow();
    }

    document.getElementById('dlModalOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
    dlRecalc();
}

function closeBudgetModal() {
    document.getElementById('dlModalOverlay').classList.remove('open');
    document.body.style.overflow = '';
    var btn = document.getElementById('dlBtnSave');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Entry';
}

function onOverlayClick(e) {
    if (e.target === document.getElementById('dlModalOverlay')) closeBudgetModal();
}

// ── Delete modal ────────────────────────────────────────────────────────
function dlConfirmDelete() {
    document.getElementById('dlDeleteOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeDeleteModal() {
    document.getElementById('dlDeleteOverlay').classList.remove('open');
    document.body.style.overflow = '';
    var btn = document.getElementById('dlBtnConfirmDelete');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-trash-can"></i> Yes, Delete'; }
}
function dlExecuteDelete() {
    var btn = document.getElementById('dlBtnConfirmDelete');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';

    var fd = new FormData();
    fd.append('action','delete_dlink');
    fd.append('year',  dlSelYear);
    fd.append('month', dlSelMonth);

    fetch('dlink_budget_entry.php', {method:'POST',body:fd})
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.ok) {
                var pm = String(dlSelYear).padStart(4,'0')
                       + '-' + String(dlSelMonth).padStart(2,'0');
                window.location.href = 'dlink_budget_entry.php?pmonth='+pm+'&deleted=1';
            } else {
                closeDeleteModal();
                showFlash('Delete failed: '+(data.msg||'Unknown error'),'danger');
            }
        })
        .catch(function(){ closeDeleteModal(); showFlash('Network error.','danger'); });
}

// ── Add attendance row ───────────────────────────────────────────────────
function dlAddEmpRow(dbId, empCode, empName, days, fromMrList) {
    dbId       = parseInt(dbId)    || 0;
    empCode    = empCode  != null  ? String(empCode)  : '';
    empName    = empName  != null  ? String(empName)  : '';
    days       = parseInt(days)    || 0;
    fromMrList = fromMrList === true;

    dlEmpRowCount++;
    var n = dlEmpRowCount;

    var dbIdCell;
    if (fromMrList) {
        dbIdCell =
            '<td class="dl-att-dbid-cell">' +
            '<input type="hidden" name="emp_db_id[]" value="' + dbId + '">' +
            (dbId > 0
                ? '<span class="dl-att-dbid-pill">' + dbId + '</span>'
                : '<span class="dl-att-dbid-none">—</span>') +
            '</td>';
    } else {
        dbIdCell =
            '<td>' +
            '<input type="number" name="emp_db_id[]" class="dl-att-input" ' +
            'placeholder="DB ID" min="0" step="1" style="width:70px;text-align:center;" ' +
            'value="' + (dbId > 0 ? dbId : '') + '">' +
            '</td>';
    }

    var codeAttrs = fromMrList
        ? 'readonly class="dl-att-input dl-att-readonly" tabindex="-1"'
        : 'class="dl-att-input" placeholder="EMP-001"';

    var nameExtra = fromMrList
        ? ' readonly style="background:#f9fafb;color:#374151;cursor:default;"'
        : '';

    var tr = document.createElement('tr');
    tr.id  = 'dlEmpRow_' + n;
    tr.innerHTML =
        '<td class="dl-att-num">' + n + '</td>' +
        dbIdCell +
        '<td><input type="text" name="emp_id[]" ' + codeAttrs +
             ' value="' + escH(empCode) + '"></td>' +
        '<td><input type="text" name="emp_name[]" class="dl-att-input"' +
             ' placeholder="Full Name" value="' + escH(empName) + '"' + nameExtra + '></td>' +
        '<td><input type="number" name="emp_days[]"' +
             ' class="dl-att-input dl-att-days-input dl-att-days-field"' +
             ' placeholder="0" min="0" max="31" step="1"' +
             ' value="' + days + '" oninput="dlRecalc()"></td>' +
        '<td><button type="button" class="dl-att-btn-rm"' +
             ' onclick="dlRemoveEmpRow(' + n + ')" title="Remove">' +
             '<i class="fa-solid fa-trash-can"></i></button></td>';

    document.getElementById('dlAttBody').appendChild(tr);
    dlRecalc();
}

function dlRemoveEmpRow(n) {
    var el = document.getElementById('dlEmpRow_'+n);
    if (el) el.remove();
    dlRecalc();
}

// ── Add additional budget row ────────────────────────────────────────────
function dlAddAdditionalRow(desc, amt, displayName, existingFile) {
    desc         = desc         || '';
    amt          = amt          != null ? amt : '';
    displayName  = displayName  || '';
    existingFile = existingFile || '';
    dlAddlRowCount++;
    var id = dlAddlRowCount;

    var rowDiv = document.createElement('div');
    rowDiv.className = 'dl-addl-row';
    rowDiv.id = 'dlRow_'+id;
    rowDiv.innerHTML =
        '<input type="text" name="addl_desc[]" class="dl-form-input dl-addl-desc"' +
        ' placeholder="Description" value="' + escH(desc) + '">' +
        '<input type="number" name="addl_amt[]" class="dl-form-input dl-addl-amt"' +
        ' placeholder="Amount (Rs.)" min="0" step="0.01"' +
        ' value="' + escH(String(amt)) + '" oninput="dlRecalc()">' +
        '<button type="button" class="dl-btn-remove"' +
        ' onclick="dlRemoveAdditionalRow('+id+')" title="Remove">' +
        '<i class="fa-solid fa-trash-can"></i></button>';
    document.getElementById('dlAddlRows').appendChild(rowDiv);

    var attachDiv = document.createElement('div');
    attachDiv.className = 'dl-attach-row';
    attachDiv.id = 'dlAttach_'+id;
    var hasFile = existingFile !== '';
    attachDiv.innerHTML =
        '<input type="hidden" name="addl_existing_file[]"' +
        ' id="dlExFile_'+id+'" value="'+escH(existingFile)+'">' +
        '<input type="hidden" name="addl_existing_name[]"' +
        ' id="dlExName_'+id+'" value="'+escH(displayName)+'">' +
        '<input type="file" name="addl_file[]" id="dlFile_'+id+'"' +
        ' style="display:none;" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.docx,.xls,.doc"' +
        ' onchange="dlFileSelect('+id+',this)">' +
        '<button type="button" class="dl-attach-btn'+(hasFile?' has-file':'')+'"' +
        ' id="dlAttachBtn_'+id+'"' +
        ' onclick="document.getElementById(\'dlFile_'+id+'\').click()">' +
        '<i class="fa-solid fa-paperclip"></i> '+
        (hasFile ? escH(displayName||existingFile) : 'Attach File (optional)') +
        '</button>';
    document.getElementById('dlAddlRows').appendChild(attachDiv);
    dlRecalc();
}

function dlRemoveAdditionalRow(id) {
    var el = document.getElementById('dlRow_'+id);
    var at = document.getElementById('dlAttach_'+id);
    if (el) el.remove();
    if (at) at.remove();
    dlRecalc();
}

function dlFileSelect(id, inp) {
    if (!inp.files || !inp.files[0]) return;
    document.getElementById('dlExFile_'+id).value = '';
    document.getElementById('dlExName_'+id).value = '';
    var btn = document.getElementById('dlAttachBtn_'+id);
    if (btn) {
        btn.className = 'dl-attach-btn has-file';
        btn.innerHTML = '<i class="fa-solid fa-paperclip"></i> ' + escH(inp.files[0].name);
    }
}

// ── Recalculate all auto fields ──────────────────────────────────────────
function dlRecalc() {
    var days    = parseFloat(document.getElementById('mActualDays').value)    || 0;
    var rate    = parseFloat(document.getElementById('mPerDayRate').value)     || 0;
    var bicycle = parseFloat(document.getElementById('mBicycleCount').value)   || 0;

    var dlink = days * rate * bicycle;
    document.getElementById('mDlinkBudget').value = dlink > 0 ? 'Rs. '+fmt(dlink) : '';

    var totalDays = 0;
    document.querySelectorAll('.dl-att-days-field').forEach(function(inp) {
        totalDays += parseInt(inp.value) || 0;
    });
    document.getElementById('mTotalWorkDays').value        = totalDays;
    document.getElementById('mTotalWorkDaysDisplay').value = totalDays + ' days';
    document.getElementById('dlAttTotalDays').textContent  = totalDays;

    var addlTotal = 0;
    document.querySelectorAll('.dl-addl-amt').forEach(function(inp) {
        addlTotal += parseFloat(inp.value) || 0;
    });
    document.getElementById('dlAddlTotal').textContent = 'Rs. '+fmt(addlTotal);

    var totalBudget = dlink + addlTotal;
    document.getElementById('mTotalBudget').value =
        totalBudget > 0 ? 'Rs. '+fmt(totalBudget) : '';

    var apd = totalDays > 0 ? totalBudget / totalDays : 0;
    document.getElementById('mActualPerDay').value = apd > 0 ? 'Rs. '+fmt(apd) : '';
}

// ── Save ─────────────────────────────────────────────────────────────────
function dlSave() {
    var days    = parseFloat(document.getElementById('mActualDays').value)    || 0;
    var rate    = parseFloat(document.getElementById('mPerDayRate').value)     || 0;
    var bicycle = parseFloat(document.getElementById('mBicycleCount').value)   || 0;

    if (!days || !rate || !bicycle) {
        alert('Please fill in Actual Working Days, Per Day Rate, and Bicycle Count.');
        return;
    }

    var btn = document.getElementById('dlBtnSave');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    document.getElementById('dlBudgetForm').submit();
}

// ── Helpers ──────────────────────────────────────────────────────────────
function fmt(n) {
    return Number(n||0).toLocaleString('en-LK',
           {minimumFractionDigits:2,maximumFractionDigits:2});
}
function escH(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;')
                        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function showFlash(msg, type) {
    var old = document.getElementById('dlDynAlert');
    if (old) old.remove();
    var el = document.createElement('div');
    el.id = 'dlDynAlert';
    el.className = 'dl-alert dl-alert-'+(type||'success');
    el.innerHTML = '<i class="fa-solid fa-'+(type==='danger'?'circle-exclamation':'circle-check')+'"></i> '+msg;
    var bar = document.getElementById('periodForm');
    bar.parentNode.insertBefore(el, bar);
    setTimeout(function(){ if(el.parentNode) el.remove(); }, 5000);
}

// Auto-reopen modal on server-side validation failure
<?php if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_dlink'): ?>
window.addEventListener('DOMContentLoaded', function(){ openBudgetModal(); });
<?php endif; ?>
</script>

<!-- ── Prefill hint banner (hidden by default, shown by JS) ── -->
<div id="dlPrefillHint" class="dl-prefill-hint" style="display:none;margin:0 24px 14px;">
    <i class="fa-solid fa-clock-rotate-left"></i>
    <span>
        <strong>Pre-filled from last saved entry.</strong>
        Actual Working Days, Per Day Rate, and Bicycle Count have been loaded from
        the most recent month's entry for your convenience. Adjust as needed.
    </span>
</div>

<?php include 'footer.php'; ?>

