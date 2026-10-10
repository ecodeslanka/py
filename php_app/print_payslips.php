<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'config.php';

// ── Defensively ensure every column this page reads actually exists ───────
// Each category script (acc/cc/mr/sr/off/st_salary.php) only adds its own
// incentive columns to the sheet tables when THAT script runs, and — like
// this page previously did too — they all rely on "ADD COLUMN IF NOT
// EXISTS", which is a MariaDB-only extension and is NOT valid syntax on
// standard MySQL. On plain MySQL every one of those statements silently
// fails (they're wrapped in @ error suppression), so only whichever
// category's base CREATE TABLE happened to run first ever gets its columns
// created — every other category's fields quietly vanish from anything
// that reads them, exactly like a saved-but-missing field disappearing
// from the payslip. Fixed here with a portable existence check that works
// on any MySQL/MariaDB version instead of relying on that syntax.
function ensure_columns($conn, $tbl, $cols) {
    $existing = [];
    $res = @mysqli_query($conn, "SHOW COLUMNS FROM `$tbl`");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $existing[strtolower($r['Field'])] = true;
    foreach ($cols as $col => $def) {
        if (!isset($existing[strtolower($col)])) {
            @mysqli_query($conn, "ALTER TABLE `$tbl` ADD COLUMN `$col` $def");
        }
    }
}

$money_def = 'DECIMAL(12,2) DEFAULT 0';
$money_cols = [
    'basic','ccfot_amt','ssv_acc_amt','ssv_cc_amt','ssv','credit_mgt_amt','payee_chq_amt',
    'att_inc_amt','locus_admin_amt','punctuality_amt','att_allow_amt','att_allow','disc',
    'ps_amt','eco_amt','bp_amt','assort_amt','other_incentive','daily_incent','weekly_incent',
    'monthly_incent','stores_damage_amt','rsqm_amt','loading_unloading_amt','meal','travel',
    'fuel','mobile','dlink_amount','arrears','sal_adv','loan_ded','welfare_amt','w_loan','w_soc',
    'donations','epf_emp','no_pay_amount','credit_r','cash_short','good_short','retention',
    'excess_p','total_earnings','total_deductions','net_salary','epf_er','etf_er','bonus','gratuity',
];
$all_cols = [];
foreach ($money_cols as $c) $all_cols[$c] = $money_def;
$all_cols += [
    'employee_code'          => 'VARCHAR(50)',
    'employee_name'          => 'VARCHAR(200)',
    'epf_number'              => 'VARCHAR(50)',
    'status'                  => 'VARCHAR(50)',
    'category_code'           => 'VARCHAR(50)',
    'designation_name'        => 'VARCHAR(200)',
    'company_code'            => 'VARCHAR(50)',
    'branch_code'             => 'VARCHAR(50)',
    'att_count'               => 'INT DEFAULT 0',
    'norm_days'               => 'INT DEFAULT 0',
    'holidays'                => 'INT DEFAULT 0',
    'no_pay_days'             => 'DECIMAL(8,2) DEFAULT 0',
    'additional_earnings'     => 'TEXT DEFAULT NULL',
    'additional_deductions'   => 'TEXT DEFAULT NULL',
    'sheet_status'            => "VARCHAR(20) DEFAULT 'Pending'",
];
foreach (['salary_sheet_entries','off_salary_sheet_entries','st_salary_sheet_entries'] as $tbl) {
    // Minimal table shape in case a category page has never been opened at all.
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `$tbl` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        payroll_period_id INT NOT NULL,
        employee_id INT NOT NULL,
        UNIQUE KEY uq_period_emp (payroll_period_id, employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    ensure_columns($conn, $tbl, $all_cols);
}

$all_periods = [];
$pp_res = mysqli_query($conn, "SELECT id, year, month, status FROM payroll_periods ORDER BY year DESC, month DESC");
if ($pp_res) while ($p = mysqli_fetch_assoc($pp_res)) $all_periods[] = $p;

$month_names = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

$cats = [];
$cat_res = mysqli_query($conn, "SELECT id, category_code, category_name FROM staff_categories WHERE active=1 ORDER BY category_name");
if ($cat_res) while ($c = mysqli_fetch_assoc($cat_res)) $cats[] = $c;

$epf_settings      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM epf_etf_settings LIMIT 1"));
$epf_employer_rate = $epf_settings ? floatval($epf_settings['epf_employer']) : 12;
$epf_employee_rate = $epf_settings ? floatval($epf_settings['epf_employee']) : 8;
$etf_employer_rate = $epf_settings ? floatval($epf_settings['etf_employer']) : 3;

$sel_period_id = isset($_GET['period_id']) ? intval($_GET['period_id']) : 0;
$sel_cat_id    = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
$sel_emp_id    = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;

if (!$sel_period_id && !empty($all_periods)) {
    foreach ($all_periods as $pp) { if (($pp['status'] ?? '') === 'Open') { $sel_period_id = $pp['id']; break; } }
    if (!$sel_period_id) $sel_period_id = $all_periods[0]['id'];
}
$active_period = null;
foreach ($all_periods as $pp) { if ($pp['id'] == $sel_period_id) { $active_period = $pp; break; } }

// ── Employee-wise filter dropdown list ──────────────────────────────────────
// Scoped to the currently selected staff category (if any) so the employee
// list stays relevant/short when a category is already chosen, and resets
// to the full active roster when "All Categories" is selected.
$emp_list = [];
$el_where = "active=1";
if ($sel_cat_id) $el_where .= " AND staff_category_id=" . intval($sel_cat_id);
$el_res = mysqli_query($conn, "SELECT id, employee_id, employee_full_name FROM employees WHERE $el_where ORDER BY employee_full_name");
if ($el_res) while ($el = mysqli_fetch_assoc($el_res)) $emp_list[] = $el;
// If a previously selected employee no longer belongs to the newly chosen
// category, drop the stale filter instead of silently returning zero rows.
if ($sel_emp_id && !in_array($sel_emp_id, array_column($emp_list, 'id'))) $sel_emp_id = 0;

$att_count_map = []; $leave_map = []; $sh_date_map = []; $month_total_days = 30;

if ($active_period) {
    $pp_year  = $active_period['year'];
    $pp_month = $active_period['month'];
    $from_date = sprintf('%04d-%02d-01', $pp_year, $pp_month);
    $to_date   = date('Y-m-t', strtotime($from_date));
    $month_total_days = (int)date('t', strtotime($from_date));

    $sh_res = mysqli_query($conn, "SELECT * FROM special_holidays WHERE active=1 AND date_from<='$to_date' AND date_to>='$from_date'");
    if ($sh_res) while ($sh = mysqli_fetch_assoc($sh_res)) {
        $start = new DateTime(max($sh['date_from'], $from_date));
        $end   = new DateTime(min($sh['date_to'], $to_date)); $end->modify('+1 day');
        foreach (new DatePeriod($start, new DateInterval('P1D'), $end) as $dt) {
            $d = $dt->format('Y-m-d');
            if (!isset($sh_date_map[$d])) $sh_date_map[$d] = [];
            $sh_date_map[$d][] = $sh;
        }
    }
    $att_res = mysqli_query($conn, "SELECT employee_id, COUNT(*) AS cnt FROM attendance WHERE att_date BETWEEN '$from_date' AND '$to_date' GROUP BY employee_id");
    if ($att_res) while ($r = mysqli_fetch_assoc($att_res)) $att_count_map[(int)$r['employee_id']] = (int)$r['cnt'];

    $leave_res = mysqli_query($conn, "SELECT employee_id, SUM(days_count) AS total_leave FROM leave_applications
         WHERE status='Approved' AND (start_date BETWEEN '$from_date' AND '$to_date'
               OR end_date BETWEEN '$from_date' AND '$to_date'
               OR (start_date<='$from_date' AND end_date>='$to_date'))
         GROUP BY employee_id");
    if ($leave_res) while ($lv = mysqli_fetch_assoc($leave_res)) $leave_map[(int)$lv['employee_id']] = (float)$lv['total_leave'];
}

function calc_att_ps($emp, $sh_date_map, $att_count_map, $month_total_days, $leave_map) {
    $eid = (int)$emp['id']; $att_count = $att_count_map[$eid] ?? 0; $holidays = 0;
    foreach ($sh_date_map as $date => $shs) {
        foreach ($shs as $sh) {
            $applies = false;
            if ($sh['target_type'] === 'all') { $applies = true; }
            elseif (!empty($sh['target_ids'])) {
                $ids = json_decode($sh['target_ids'], true) ?? [];
                if ($sh['target_type'] === 'employee'       && in_array($eid, $ids)) $applies = true;
                if ($sh['target_type'] === 'staff_category' && in_array((int)($emp['staff_category_id'] ?? 0), $ids)) $applies = true;
                if ($sh['target_type'] === 'designation'    && in_array((int)($emp['designation_id'] ?? 0), $ids)) $applies = true;
            }
            if ($applies) { $holidays++; break; }
        }
    }
    $norm_days   = max(1, $month_total_days - $holidays);
    $no_pay_days = max(0, $norm_days - $att_count - ($leave_map[$eid] ?? 0));
    // 'rate' added — identical formula to cc_salary.php's calc_att_cc(),
    // needed to fully replicate CC's live Attendance Incentive tier
    // resolution (see cc_live_attendance_incentive() below) instead of
    // depending on whatever was last saved into the sheet.
    $rate = round(($att_count / $norm_days) * 100, 2);
    return ['att' => $att_count, 'norm' => $norm_days, 'holidays' => $holidays, 'no_pay_days' => $no_pay_days, 'rate' => $rate];
}

$where_parts = ["e.active=1"];
if ($sel_cat_id) $where_parts[] = "e.staff_category_id=$sel_cat_id";
if ($sel_emp_id) $where_parts[] = "e.id=$sel_emp_id";

$emp_sql = "SELECT e.*, c.company_name, c.company_code, b.branch_name, b.branch_code,
               d.designation_name, sc.category_name, sc.category_code,
               cfg.attendance_85, cfg.attendance_90
            FROM employees e
            LEFT JOIN companies        c   ON e.company_id        = c.id
            LEFT JOIN branches         b   ON e.branch_id         = b.id
            LEFT JOIN designations     d   ON e.designation_id    = d.id
            LEFT JOIN staff_categories sc  ON e.staff_category_id = sc.id
            LEFT JOIN sr_salary_config cfg ON cfg.designation_id  = d.id
            WHERE " . implode(' AND ', $where_parts) . " ORDER BY c.company_code, e.employee_id";
$emp_res = mysqli_query($conn, $emp_sql);
$employees = [];
if ($emp_res) while ($r = mysqli_fetch_assoc($emp_res)) $employees[] = $r;

// ── Fetch saved salary sheet data ───────────────────────────────────────────
// ACC / CC / MR / SR share the "salary_sheet_entries" table (keyed by category_code).
// OFF and ST each have their own dedicated table.
$sheet_map = [];
if ($sel_period_id && !empty($employees)) {
    $ids_shared = []; $ids_off = []; $ids_st = [];
    foreach ($employees as $e) {
        $cc = strtoupper(trim($e['category_code'] ?? ''));
        if ($cc === 'OFF')      $ids_off[]     = (int)$e['id'];
        elseif ($cc === 'ST')   $ids_st[]      = (int)$e['id'];
        else                    $ids_shared[]  = (int)$e['id']; // ACC, CC, MR, SR (and any unknown/default)
    }

    if (!empty($ids_shared)) {
        $ids_str = implode(',', $ids_shared);
        $res = mysqli_query($conn, "SELECT * FROM salary_sheet_entries WHERE payroll_period_id=$sel_period_id AND employee_id IN ($ids_str)");
        if ($res) while ($sr = mysqli_fetch_assoc($res)) $sheet_map[(int)$sr['employee_id']] = $sr;
    }
    if (!empty($ids_off)) {
        $ids_str = implode(',', $ids_off);
        $res = @mysqli_query($conn, "SELECT * FROM off_salary_sheet_entries WHERE payroll_period_id=$sel_period_id AND employee_id IN ($ids_str)");
        if ($res) while ($sr = mysqli_fetch_assoc($res)) $sheet_map[(int)$sr['employee_id']] = $sr;
    }
    if (!empty($ids_st)) {
        $ids_str = implode(',', $ids_st);
        $res = @mysqli_query($conn, "SELECT * FROM st_salary_sheet_entries WHERE payroll_period_id=$sel_period_id AND employee_id IN ($ids_str)");
        if ($res) while ($sr = mysqli_fetch_assoc($res)) $sheet_map[(int)$sr['employee_id']] = $sr;
    }
}

// ── Master field → label maps, covering every earnings/deductions column ───
// used across all six salary sheet builders (acc/cc/mr/sr/off/st_salary.php).
// isset() checks below mean each category simply "picks up" whichever of
// these columns actually exist/are populated in its own sheet table.
$EARN_FIELDS = [
    'basic'                  => 'Basic Salary',
    'ccfot_amt'               => 'CCFOT Incentive',
    'ssv_acc_amt'             => 'SSV Incentive',
    'ssv_cc_amt'              => 'SSV Incentive',
    'ssv'                     => 'Secondary Sales Value',
    'credit_mgt_amt'          => 'Credit Mgt Incentive',
    'payee_chq_amt'           => 'Payee Chq Incentive',
    'att_inc_amt'             => 'Attendance Incentive',
    'locus_admin_amt'         => 'Locus Admin',
    'punctuality_amt'         => 'Punctuality Incentive',
    'att_allow_amt'           => 'Attendance Allowance',
    'att_allow'               => 'Attendance Allowance',
    'disc'                    => 'Discretionary Support',
    'ps_amt'                  => 'PS Compliance',
    'eco_amt'                 => 'ECO Incentive',
    'bp_amt'                  => 'BP Incentive',
    'assort_amt'              => 'Total Assortment',
    'other_incentive'         => 'Other Incentive',
    'daily_incent'            => 'Daily Incentive',
    'weekly_incent'           => 'Weekly Incentive',
    'monthly_incent'          => 'Monthly Incentive',
    'stores_damage_amt'       => 'Stores Damage Incentive',
    'rsqm_amt'                => 'RSQM Incentive',
    'loading_unloading_amt'   => 'Loading / Unloading',
    'meal'                    => 'Meal Reimbursement',
    'travel'                  => 'Travelling Reimb.',
    'fuel'                    => 'Fuel Reimb.',
    'mobile'                  => 'Mobile Reimbursement',
    'dlink_amount'            => 'D-Link Allowance',
    'arrears'                 => 'Arrears Salary',
];
$DED_FIELDS = [
    'sal_adv'       => 'Salary Advance',
    'loan_ded'      => 'Loan Deduction',
    'welfare_amt'   => 'Welfare',
    'w_loan'        => 'Welfare Loan Ded.',
    'w_soc'         => 'Welfare Society',
    'donations'     => 'Donations',
    'epf_emp'       => 'EPF Employee', // % appended dynamically below
    'no_pay_amount' => 'No Pay Deduction',
    'credit_r'      => 'Credit Recovery',
    'cash_short'    => 'Cash Short',
    'good_short'    => 'Good Short',
    'retention'     => 'Retention',
    'excess_p'      => 'Excess Payment',
];

$category_name_map = [];
foreach ($cats as $c) $category_name_map[$c['category_code']] = $c['category_name'];

$company_name_map = [];
$co_res = mysqli_query($conn, "SELECT company_code, company_name FROM companies");
if ($co_res) while ($co = mysqli_fetch_assoc($co_res)) $company_name_map[$co['company_code']] = $co['company_name'];

$loan_map = [];
if (!empty($employees) && $active_period) {
    $emp_ids_str = implode(',', array_map(fn($e) => (int)$e['id'], $employees));
    $pp_first_day = sprintf('%04d-%02d-01', $active_period['year'], $active_period['month']);
    $loan_res = mysqli_query($conn, "SELECT lr.employee_id, SUM(lr.monthly_instalment) AS total_inst
         FROM loan_requests lr WHERE lr.employee_id IN ($emp_ids_str) AND lr.status='Approved'
           AND DATE_FORMAT(COALESCE(lr.approved_at, lr.request_date),'%Y-%m') < DATE_FORMAT('$pp_first_day','%Y-%m')
         GROUP BY lr.employee_id");
    if ($loan_res) while ($ln = mysqli_fetch_assoc($loan_res)) $loan_map[(int)$ln['employee_id']] = floatval($ln['total_inst']);
}

$advance_map = [];
if (!empty($employees) && $active_period) {
    $emp_ids_str = implode(',', array_map(fn($e) => (int)$e['id'], $employees));
    $adv_from = sprintf('%04d-%02d-01', $active_period['year'], $active_period['month']);
    $adv_to   = date('Y-m-t', strtotime($adv_from));
    $adv_res = mysqli_query($conn, "SELECT employee_id, SUM(amount) AS total FROM salary_advances
         WHERE status='Approved' AND employee_id IN ($emp_ids_str) AND request_date BETWEEN '$adv_from' AND '$adv_to'
         GROUP BY employee_id");
    if ($adv_res) while ($ar = mysqli_fetch_assoc($adv_res)) $advance_map[(int)$ar['employee_id']] = floatval($ar['total']);
}

$dlink_map = [];
if ($active_period && !empty($employees)) {
    $dl_entry = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id, actual_per_day_rate FROM dlink_budget_entries WHERE year={$active_period['year']} AND month={$active_period['month']} LIMIT 1"));
    if ($dl_entry) {
        $dl_entry_id = intval($dl_entry['id']); $dl_per_day_rate = floatval($dl_entry['actual_per_day_rate']);
        $dl_days_res = mysqli_query($conn, "SELECT employee_db_id, attended_days FROM dlink_employee_days WHERE entry_id=$dl_entry_id AND employee_db_id IS NOT NULL AND employee_db_id>0");
        if ($dl_days_res) while ($dlr = mysqli_fetch_assoc($dl_days_res))
            $dlink_map[(int)$dlr['employee_db_id']] = round(floatval($dlr['attended_days']) * $dl_per_day_rate, 2);
    }
}

// ── Live incentive_entries (the same source every category page reads live) ─
// Every category script treats "incentive_entries" as the current truth for
// incentive/reimbursement amounts and only ever SNAPSHOTS them into the sheet
// at save time — so a saved sheet can go stale the moment a new entry is
// logged. Pull the same table here so the payslip stays in sync automatically.
$inc_entries = [];
if ($sel_period_id && !empty($employees)) {
    $ids_str = implode(',', array_map(fn($e) => (int)$e['id'], $employees));
    $ie_res  = @mysqli_query($conn, "SELECT employee_id, component_label, amount FROM incentive_entries WHERE payroll_period_id=$sel_period_id AND employee_id IN ($ids_str)");
    if ($ie_res) while ($ie = mysqli_fetch_assoc($ie_res)) $inc_entries[(int)$ie['employee_id']][$ie['component_label']] = (float)$ie['amount'];
}
// SR's fuel is its own period-based entry table (fuel_rate × fuel_liter).
$sr_fuel_map = [];
if ($sel_period_id && !empty($employees)) {
    $ids_str = implode(',', array_map(fn($e) => (int)$e['id'], $employees));
    $fr_res  = @mysqli_query($conn, "SELECT employee_id, (fuel_rate * fuel_liter) AS fuel_amount FROM sr_fuel_entries WHERE payroll_period_id=$sel_period_id AND employee_id IN ($ids_str)");
    if ($fr_res) while ($fr = mysqli_fetch_assoc($fr_res)) $sr_fuel_map[(int)$fr['employee_id']] = floatval($fr['fuel_amount']);
}

function inc_lookup($inc_entries, $eid, $pattern) {
    foreach ($inc_entries[$eid] ?? [] as $lbl => $val) if (strpos($lbl, $pattern) !== false) return floatval($val);
    return 0;
}

// Category-aware live resolver. Only covers fields verified as a SIMPLE
// direct lookup (component_label in incentive_entries, or a plain profile
// column) in each category's own source — deliberately does NOT attempt to
// replicate any tiered/achievement-% incentive formula, since guessing at
// that risks a subtly wrong number, which is worse than staying with the
// saved figure. Returns null when there's nothing safe to live-override,
// so the caller keeps whatever the saved sheet had.
//
// NOTE — SR carve-out: SR's Meal / Travelling / Mobile are deliberately NOT
// in $reimb['SR'] (and 'mobile' has no direct['SR'] entry either) — for SR
// these three always come from the saved sheet snapshot, never a live
// override. The earnings loop below enforces this explicitly too, but
// keeping SR out of these lookup tables means this resolver itself can
// never hand back a live figure for them — same convention as the Salary
// Sheet Excel export.
//
// NOTE — OFF carve-out: OFF has no Meal Allowance entitlement at all, so
// 'OFF' is deliberately absent from $reimb below too. The earnings loop
// also forces OFF's meal to 0 explicitly regardless of what this returns.
function live_earn_value($cat, $key, $eid, $emp, $inc_entries, $sr_fuel_map) {
    $direct = [
        // category => [field_key => incentive_entries pattern]
        'ACC' => ['ccfot_amt'=>'__incent__ccfot_acc','ssv_acc_amt'=>'__incent__ssv_acc','punctuality_amt'=>'__incent__punctuality_acc','att_allow_amt'=>'__incent__attendance_acc'],
        // NOTE: CC's 'att_inc_amt' is deliberately NOT here — it's fully
        // recomputed by cc_live_attendance_incentive() instead (see above),
        // which the earnings loop calls directly before reaching this
        // generic resolver.
        'CC'  => ['ccfot_amt'=>'__incent__ccfot','credit_mgt_amt'=>'__incent__credit_mgt','payee_chq_amt'=>'__incent__payee_chq','ssv_cc_amt'=>'__incent__ssv_cc','locus_admin_amt'=>'__incent__locus_admin'],
        'MR'  => ['ps_amt'=>'__incent__placement_compliance','att_allow'=>'__incent__attendance'],
        // NOTE: SR's 'disc' is deliberately NOT here — it has its own extra
        // profile-column fallback (see the dedicated branch below), unlike
        // the other SR fields which are incentive-entries-only.
        'SR'  => ['ssv'=>'__ssv_amount','ps_amt'=>'__incent__total_ps_compliance','eco_amt'=>'__incent__eco_incentive','bp_amt'=>'__incent__bp_incentive','assort_amt'=>'__incent__total_assortment','other_incentive'=>'__other_incentive'],
        'OFF' => ['daily_incent'=>'__incent__incentive_daily','weekly_incent'=>'__incent__incentive_weekly','monthly_incent'=>'__incent__incentive_monthly','punctuality_amt'=>'__incent__punctuality_75'],
        'ST'  => ['stores_damage_amt'=>'__incent__stores_damage','rsqm_amt'=>'__incent__rsqm','punctuality_amt'=>'__incent__punctuality','loading_unloading_amt'=>'__incent__loading_unloading','ccfot_amt'=>'__incent__ccfot_st'],
    ];
    // Meal/travel reimbursements: same "entry overrides profile" pattern in
    // MR only — this is MR's own verified fallback (a plain profile
    // column, no config table involved). SR is deliberately excluded — its
    // meal/travel/mobile always come from the saved sheet column instead
    // (see the earnings loop). OFF is excluded too — no Meal Allowance.
    // ST and CC's meal/mobile are handled by their own dedicated branches
    // below instead of this generic table, since their real fallback paths
    // involve a sr_salary_config table this page doesn't join — a plain
    // profile-column fallback here would be a wrong guess for them.
    $reimb = [
        'MR' => ['meal'=>['__reimb__meal_reimbursement','reimbursement_meal'], 'travel'=>['__reimb__traveling_reimbursement','reimbursement_traveling']],
    ];

    if (isset($direct[$cat][$key])) {
        $v = inc_lookup($inc_entries, $eid, $direct[$cat][$key]);
        if ($v > 0) return $v;
        return null; // no direct entry — don't guess a tiered fallback, keep saved value
    }
    if (isset($reimb[$cat][$key])) {
        [$pattern, $profileCol] = $reimb[$cat][$key];
        $v = inc_lookup($inc_entries, $eid, $pattern);
        if ($v > 0) return $v;
        $v = floatval($emp[$profileCol] ?? 0);
        return $v > 0 ? $v : null;
    }
    // SR's Discretionary Support: incentive_entries override wins, else the
    // employee's own 'discretionary_support' profile column — a genuine,
    // verified simple fallback in sr_salary.php's own code (unlike SR's
    // meal/travel/mobile, which have no fallback and stay saved-sheet-only).
    if ($cat === 'SR' && $key === 'disc') {
        $v = inc_lookup($inc_entries, $eid, '__disc_amount');
        if ($v > 0) return $v;
        $v = floatval($emp['discretionary_support'] ?? 0);
        return $v > 0 ? $v : null;
    }
    // ST's Meal: incentive_entries direct override only (verified pattern
    // '__reimb__meal_reimbursement') — ST's real fallback when there's no
    // override is `config_meal × (attendance_rate/100)`, which involves a
    // sr_salary_config table this page doesn't join, so no profile-column
    // fallback is attempted here; returning null correctly defers to the
    // saved sheet figure instead of guessing from a raw column.
    if ($cat === 'ST' && $key === 'meal') {
        $v = inc_lookup($inc_entries, $eid, '__reimb__meal_reimbursement');
        return $v > 0 ? $v : null;
    }
    if ($cat === 'SR' && $key === 'att_allow') {
        $v = floatval($emp['attendance_allowance'] ?? 0);
        return $v > 0 ? $v : null;
    }
    if ($cat === 'SR' && $key === 'fuel') {
        if (isset($sr_fuel_map[$eid])) return $sr_fuel_map[$eid];
        $v = floatval($emp['reimbursement_fuel'] ?? 0);
        return $v > 0 ? $v : null;
    }
    return null;
}

/** Full live replica of cc_salary.php's own cc_resolve_attendance() —
 *  unlike every other field above (which deliberately stops at "no direct
 *  incentive_entries override, defer to the saved figure" to avoid
 *  guessing at a tiered/config formula), CC's Attendance Incentive is
 *  fully recomputed here on purpose, so it never depends on the sheet
 *  having been freshly re-saved after an employee's attendance changed.
 *  Priority, exactly matching cc_salary.php:
 *    1. Manual override in incentive_entries ('__incent__attendance_cc').
 *    2. Automatic: this employee's LIVE attendance rate for the period
 *       (calc_att_ps()'s 'rate', same formula as cc_salary.php's own
 *       calc_att_cc()) checked against this DESIGNATION's attendance_90 /
 *       attendance_85 threshold amounts (sr_salary_config, joined onto
 *       $emp as 'attendance_90'/'attendance_85' — see $emp_sql above).
 *    3. A manually-entered achievement % ('__iach__attendance_cc') as a
 *       legacy fallback path, same as cc_salary.php.
 *  Returns a float (0 is a valid, final "genuinely earned nothing this
 *  period" answer here — NOT deferred to the saved sheet — since this is a
 *  full recomputation, not a partial live-check). */
function cc_live_attendance_incentive($inc_entries, $eid, $emp, $att_rate) {
    $earned = $inc_entries[$eid]['__incent__attendance_cc'] ?? 0;
    if ($earned > 0) return floatval($earned);

    $rate = floatval($att_rate);
    if ($rate <= 0) {
        $rate = floatval($inc_entries[$eid]['__iach__attendance_cc'] ?? 0);
    }
    if ($rate <= 0) return 0.0;

    foreach ([[90, 'attendance_90'], [85, 'attendance_85']] as [$t, $f]) {
        if ($rate >= $t && floatval($emp[$f] ?? 0) > 0) return floatval($emp[$f]);
    }
    return 0.0;
}

$payslips = [];
$missing_sheet = []; // employees with no saved salary sheet for this period — excluded, not guessed at
foreach ($employees as $emp) {
    $eid   = (int)$emp['id'];
    $sheet = $sheet_map[$eid] ?? null;

    if (!$sheet) {
        // No saved sheet for this employee/period. Category-specific incentive
        // math (CCFOT, SSV, Punctuality, Credit Mgt, Locus Admin, ECO/BP/
        // Assortment, PS, Discretionary, Daily/Weekly/Monthly, Stores Damage,
        // RSQM, Loading/Unloading, etc.) lives only in each category's own
        // salary sheet screen — it can't be safely re-guessed here, so we
        // don't print a payslip with incomplete numbers. Flag it instead.
        $missing_sheet[] = $emp;
        continue;
    }

    $earn_rows = []; // list of [label, amount]
    $ded_rows  = [];
    $cat = strtoupper(trim($sheet['category_code'] ?? $emp['category_code'] ?? ''));

    // Live attendance rate for this employee/period — same formula
    // cc_salary.php's own calc_att_cc() uses. Computed for every employee
    // (cheap, pure arithmetic over already-fetched maps) but only actually
    // consulted for CC's Attendance Incentive below.
    $live_att = $active_period
        ? calc_att_ps($emp, $sh_date_map, $att_count_map, $month_total_days, $leave_map)
        : ['rate' => 0];

    // ── Earnings: live value (where safely resolvable) wins, else saved ────
    // Same category-specific carve-outs used by the Salary Sheet Excel
    // export, so both always agree:
    //   - OFF has no Meal Allowance at all.
    //   - SR's Meal / Travelling / Mobile always come from the saved sheet.
    // NOTE: CC's Attendance Incentive is FULLY recomputed live (not just
    // "live override if present, else saved") — see
    // cc_live_attendance_incentive() above — so it never depends on the CC
    // sheet having been freshly re-saved after this employee's attendance
    // changed. Shown under its own real label ("Attendance Incentive"),
    // matching cc_salary.php's own screen, JS, and print view — it never
    // calls this "Attendance Allowance" anywhere.
    foreach ($EARN_FIELDS as $key => $label) {
        if ($cat === 'OFF' && $key === 'meal') continue;

        if ($cat === 'SR' && ($key === 'meal' || $key === 'travel' || $key === 'mobile')) {
            if (isset($sheet[$key])) {
                $val = floatval($sheet[$key]);
                if ($val > 0) $earn_rows[] = [$label, $val];
            }
            continue;
        }

        if ($cat === 'CC' && $key === 'att_inc_amt') {
            $val = cc_live_attendance_incentive($inc_entries, $eid, $emp, $live_att['rate']);
            if ($val > 0) $earn_rows[] = [$label, $val];
            continue;
        }

        $live = live_earn_value($cat, $key, $eid, $emp, $inc_entries, $sr_fuel_map);
        if ($live !== null) {
            if ($live > 0) $earn_rows[] = [$label, $live];
        } elseif (isset($sheet[$key])) {
            $val = floatval($sheet[$key]);
            if ($val > 0) $earn_rows[] = [$label, $val];
        }
    }
    $add_earn = !empty($sheet['additional_earnings']) ? (json_decode($sheet['additional_earnings'], true) ?: []) : [];
    foreach ($add_earn as $ae) {
        $amt = floatval($ae['amount'] ?? 0);
        if ($amt > 0) $earn_rows[] = [htmlspecialchars($ae['desc'] ?? 'Additional Earning'), $amt];
    }

    // ── Deductions: same generic pickup ─────────────────────────────────────
    // NOTE: 'sal_adv' is deliberately skipped here and handled below — every
    // one of the six salary sheet screens always LIVE-recomputes Salary
    // Advance from the salary_advances table rather than trusting whatever
    // was saved into the sheet snapshot, so the payslip does the same to
    // avoid showing a stale/incorrect figure.
    // NOTE: 'welfare_amt' (Welfare Deduction) and 'w_loan' (Welfare Loan
    // Deduction) are kept as their own separate rows here — never merged
    // into one combined figure — matching the Salary Sheet Excel export.
    foreach ($DED_FIELDS as $key => $label) {
        if ($key === 'sal_adv') continue;
        if (isset($sheet[$key])) {
            $val = floatval($sheet[$key]);
            if ($val > 0) {
                if ($key === 'epf_emp') $label = 'EPF Employee ('.$epf_employee_rate.'%)';
                $ded_rows[] = [$label, $val];
            }
        }
    }
    $live_sal_adv = $advance_map[$eid] ?? floatval($emp['salary_advance'] ?? 0);
    if ($live_sal_adv > 0) $ded_rows[] = ['Salary Advance', $live_sal_adv];
    $add_ded = !empty($sheet['additional_deductions']) ? (json_decode($sheet['additional_deductions'], true) ?: []) : [];
    foreach ($add_ded as $ad) {
        $amt = floatval($ad['amount'] ?? 0);
        if ($amt > 0) $ded_rows[] = [htmlspecialchars($ad['desc'] ?? 'Additional Deduction'), $amt];
    }

    // PAYEE / income tax isn't tracked by the salary sheet builders, so
    // fold it in here as its own deduction row.
    $payee = floatval($emp['payee'] ?? $emp['income_tax'] ?? 0);
    if ($payee > 0) $ded_rows[] = ['PAYEE (Income Tax)', $payee];

    $norm_days   = (int)($sheet['norm_days'] ?? 0);
    $att_days    = (int)($sheet['att_count'] ?? 0);
    $holidays    = (int)($sheet['holidays'] ?? 0);
    $no_pay_days = floatval($sheet['no_pay_days'] ?? 0);
    $epf_emp     = floatval($sheet['epf_emp'] ?? 0);
    $bonus       = floatval($sheet['bonus'] ?? 0);
    $gratuity    = floatval($sheet['gratuity'] ?? 0);

    // Basic Adjusted Salary = Basic Salary less No Pay — same definition
    // used by the Salary Sheet Excel export.
    $basic          = floatval($sheet['basic'] ?? 0);
    $no_pay_amount  = floatval($sheet['no_pay_amount'] ?? 0);
    $basic_adjusted = round($basic - $no_pay_amount, 2);

    // EPF (Employer) / ETF (Employer) are always CALCULATED off the Basic
    // Adjusted Salary using the site's current EPF/ETF settings — never
    // read from the saved sheet's epf_er/etf_er columns — matching the
    // Salary Sheet Excel export's formula exactly (just using the live
    // configured rates here instead of a hardcoded 12%/3%).
    $epf_er = round($basic_adjusted * ($epf_employer_rate / 100), 2);
    $etf_er = round($basic_adjusted * ($etf_employer_rate / 100), 2);

    // Total Earnings / Total Deductions / Net Salary are derived directly
    // from the rows actually shown above — NOT from a separately-stored
    // total column. A stored total can silently drift from the itemized
    // breakdown (e.g. a field that's part of the total but never got saved
    // as its own line item), which is exactly the kind of mismatch that
    // makes a payslip look wrong even though the underlying total was
    // "correct". Deriving from the visible rows guarantees the total on
    // screen always equals the sum of what's printed above it.
    $total_earnings = array_sum(array_column($earn_rows, 1));
    $total_ded      = array_sum(array_column($ded_rows, 1));
    $net_salary     = $total_earnings - $total_ded;

    // Employee info AS OF that payroll period — pulled from the sheet's own
    // saved snapshot rather than today's live employee record, so a later
    // transfer/promotion/rename doesn't rewrite historical payslips.
    $snap = [
        'employee_code'    => $sheet['employee_code']    ?: ($emp['employee_id'] ?? ''),
        'employee_name'    => $sheet['employee_name']    ?: ($emp['employee_full_name'] ?? ''),
        'epf_number'       => $sheet['epf_number']       ?: ($emp['epf_number'] ?? ''),
        'designation_name' => $sheet['designation_name'] ?: ($emp['designation_name'] ?? ''),
        'status'           => $sheet['status']           ?: ($emp['status'] ?? ''),
        'category_code'    => $sheet['category_code']    ?: ($emp['category_code'] ?? ''),
        'category_name'    => $category_name_map[$sheet['category_code'] ?? ''] ?? ($emp['category_name'] ?? ''),
        'company_name'     => $company_name_map[$sheet['company_code'] ?? ''] ?? ($emp['company_name'] ?? 'YELO LOGISTICS'),
    ];

    $payslips[] = compact('emp','snap','earn_rows','ded_rows','epf_emp','epf_er','etf_er',
        'total_earnings','total_ded','net_salary','norm_days','att_days','holidays','no_pay_days','bonus','gratuity','basic_adjusted');
}

// ── BUILD PAYSLIP HTML ROWS (reusable for both preview and print window) ──
function build_payslip_rows($ps, $epf_employee_rate, $epf_employer_rate, $etf_employer_rate, $month_names, $active_period) {
    $emp          = $ps['emp'];
    $snap         = $ps['snap'];
    $eid          = (int)$emp['id'];
    $co_name      = htmlspecialchars($snap['company_name'] ?: 'YELO LOGISTICS');
    $period_label = $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : '—';
    $emp_no       = htmlspecialchars($snap['employee_code'] ?: $eid);
    $emp_name     = htmlspecialchars($snap['employee_name']);
    $dept         = htmlspecialchars($snap['category_name']);
    $desig        = htmlspecialchars($snap['designation_name']);
    $epf_no       = htmlspecialchars($snap['epf_number']);
    $before745    = max(0, $ps['norm_days'] - $ps['att_days']);

    $earn_rows = $ps['earn_rows'];
    $ded_rows  = $ps['ded_rows'];

    $max_rows = max(count($earn_rows), count($ded_rows), 1);
    while (count($earn_rows) < $max_rows) $earn_rows[] = ['', 0];
    while (count($ded_rows)  < $max_rows) $ded_rows[]  = ['', 0];

    ob_start();
    ?>
    <div class="ps-co-name"><?php echo $co_name; ?></div>
    <div class="ps-title">Payslip for the Month of <?php echo $period_label; ?></div>

    <table class="ps-info-table">
    <tr>
        <td class="lbl">Employee No</td><td class="val"><?php echo $emp_no; ?></td>
        <td class="lbl">Norm Days</td><td class="val"><?php echo $ps['norm_days']; ?></td>
    </tr><tr>
        <td class="lbl">Employee Name</td><td class="val"><?php echo $emp_name; ?></td>
        <td class="lbl">Days Worked</td><td class="val"><?php echo $ps['att_days']; ?></td>
    </tr><tr>
        <td class="lbl">Department</td><td class="val"><?php echo $dept; ?></td>
        <td class="lbl">Before 7.45 Days</td><td class="val"><?php echo $before745; ?></td>
    </tr><tr>
        <td class="lbl">Designation</td><td class="val"><?php echo $desig; ?></td>
        <td class="lbl">Salary Period</td><td class="val"><?php echo $active_period ? $month_names[$active_period['month']] : '—'; ?></td>
    </tr>
    <?php if ($epf_no): ?>
    <tr>
        <td class="lbl">EPF No.</td><td class="val"><?php echo $epf_no; ?></td>
        <td class="lbl">No Pay Days</td><td class="val"><?php echo number_format($ps['no_pay_days'],1); ?></td>
    </tr>
    <?php endif; ?>
    </table>

    <table class="ps-main-table">
    <thead><tr>
        <th class="el">Earnings</th><th class="ev">LKR</th>
        <th class="dl">Deductions</th><th class="dv">LKR</th>
    </tr></thead>
    <tbody>
    <?php for ($i = 0; $i < $max_rows; $i++):
        $el = $earn_rows[$i][0] ?? ''; $ev = floatval($earn_rows[$i][1] ?? 0);
        $dl = $ded_rows[$i][0]  ?? ''; $dv = floatval($ded_rows[$i][1]  ?? 0);
    ?>
    <tr>
        <td class="el"><?php echo $el; ?></td>
        <td class="ev"><?php echo $ev > 0 ? number_format($ev,2) : ($el !== '' ? '0.00' : ''); ?></td>
        <td class="dl"><?php echo $dl; ?></td>
        <td class="dv"><?php echo $dv > 0 ? number_format($dv,2) : ($dl !== '' ? '0.00' : ''); ?></td>
    </tr>
    <?php endfor; ?>
    <tr class="tot">
        <td class="el">Total Earnings</td>
        <td class="ev"><?php echo number_format($ps['total_earnings'],2); ?></td>
        <td class="dl">Total Deductions</td>
        <td class="dv"><?php echo number_format($ps['total_ded'],2); ?></td>
    </tr>
    <tr class="net">
        <td class="el">Net Salary</td>
        <td class="ev" colspan="3" style="text-align:right;"><?php echo number_format($ps['net_salary'],2); ?></td>
    </tr>
    </tbody>
    </table>

    <table class="ps-epf-table">
    <tr><td class="el">EPF Contribution — Employer</td><td class="er"><?php echo $epf_employer_rate; ?>%</td><td class="ev"><?php echo number_format($ps['epf_er'],2); ?></td></tr>
    <tr><td class="el">EPF Contribution — Employee</td><td class="er"><?php echo $epf_employee_rate; ?>%</td><td class="ev"><?php echo number_format($ps['epf_emp'],2); ?></td></tr>
    <tr><td class="el">ETF Contribution — Employer</td><td class="er"><?php echo $etf_employer_rate; ?>%</td><td class="ev"><?php echo number_format($ps['etf_er'],2); ?></td></tr>
    </table>

    <div class="ps-sign-row">
        <div class="ps-sign-box">Employee Signature</div>
        <div class="ps-sign-box">Authorized Signature</div>
    </div>
    <div class="ps-foot">This is a computer-generated payslip. — <?php echo $co_name; ?></div>
    <?php
    return ob_get_clean();
}

include 'header.php';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:#f2f1ee;color:#0a0a0a;}

/* ── CONTROL PANEL ── */
.ctrl-panel{background:#2e1065;color:#fff;padding:20px 28px;border-radius:0 0 12px 12px;margin-bottom:20px;display:flex;align-items:flex-end;gap:18px;flex-wrap:wrap;}
.ctrl-panel-title{font-size:19px;font-weight:800;display:flex;align-items:center;gap:10px;flex-basis:100%;margin-bottom:2px;}
.ctrl-panel-sub{font-size:11px;color:#c4b5fd;flex-basis:100%;margin-bottom:6px;}
.ctrl-group{display:flex;flex-direction:column;gap:4px;}
.ctrl-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#c4b5fd;}
.ctrl-select{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;border-radius:8px;padding:8px 13px;font-size:13px;font-weight:600;cursor:pointer;outline:none;min-width:210px;transition:background .15s ease,border-color .15s ease;}
.ctrl-select option{background:#2e1065;color:#fff;}
/* Minor polish: visible hover/focus states so the three selects don't feel static */
.ctrl-select:hover{background:rgba(255,255,255,.18);border-color:rgba(255,255,255,.35);}
.ctrl-select:focus{background:rgba(255,255,255,.20);border-color:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.25);}
.btn-print{background:#fff;color:#2e1065;border:none;border-radius:8px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;margin-left:auto;}
.btn-print:hover{background:#e0d7f7;}
.count-badge{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);color:#e0d7ff;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;}
.no-data{background:#fff;border-radius:12px;padding:60px 20px;text-align:center;color:#888;font-size:14px;margin:20px;}
.preview-notice{background:#fff;border-radius:10px;padding:12px 20px;margin-bottom:14px;font-size:13px;color:#555;display:flex;align-items:center;gap:10px;border-left:4px solid #2e1065;}

/* ── SELECT2 (Employee filter) — themed to match the dark ctrl-panel ── */
.ctrl-group.emp-filter{min-width:230px;}
.ctrl-group.emp-filter .select2-container{width:100% !important;}
.select2-container--default .select2-selection--single{
    background:rgba(255,255,255,.12);
    border:1px solid rgba(255,255,255,.2);
    border-radius:8px;
    height:36px;
    padding:1px 2px;
    transition:background .15s ease,border-color .15s ease;
}
.select2-container--default .select2-selection--single:hover{background:rgba(255,255,255,.18);border-color:rgba(255,255,255,.35);}
.select2-container--default.select2-container--open .select2-selection--single,
.select2-container--default.select2-container--focus .select2-selection--single{
    background:rgba(255,255,255,.20);
    border-color:#fff;
    box-shadow:0 0 0 2px rgba(255,255,255,.25);
}
.select2-container--default .select2-selection--single .select2-selection__rendered{
    color:#fff;
    font-size:13px;
    font-weight:600;
    line-height:34px;
    padding-left:11px;
}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:34px;right:6px;}
.select2-container--default .select2-selection--single .select2-selection__arrow b{border-color:#c4b5fd transparent transparent transparent;}
.select2-container--default.select2-container--open .select2-selection--single .select2-selection__arrow b{border-color:transparent transparent #c4b5fd transparent;}
.select2-dropdown{background:#2e1065;border:1px solid rgba(255,255,255,.25);border-radius:8px;overflow:hidden;}
.select2-search--dropdown{padding:8px;background:#2e1065;}
.select2-search--dropdown .select2-search__field{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);border-radius:6px;color:#fff;padding:6px 9px;outline:none;}
.select2-search--dropdown .select2-search__field::placeholder{color:#c4b5fd;}
.select2-results__option{color:#e9e5f5;font-size:13px;padding:7px 11px;}
.select2-container--default .select2-results__option--highlighted[aria-selected]{background:#4c1d95;color:#fff;}
.select2-container--default .select2-results__option[aria-selected=true]{background:rgba(255,255,255,.10);}
.select2-results__message{color:#c4b5fd;}

/* ── SCREEN PREVIEW WRAPPER ── */
.payslip-wrapper{
    background:#fff;
    border:1px solid #ccc;
    border-radius:6px;
    max-width:700px;
    margin:0 auto 28px;
    overflow:hidden;
    box-shadow:0 2px 12px rgba(0,0,0,.10);
}

/* ── PAYSLIP INTERNALS (screen preview only) ── */
.ps{font-family:'Times New Roman',Times,serif;font-size:9.5pt;color:#000;padding:10pt 14pt;line-height:1.25;}
.ps-co-name{text-align:center;font-size:13pt;font-weight:bold;margin-bottom:1pt;letter-spacing:.3px;}
.ps-title{text-align:center;font-size:10.5pt;font-weight:bold;text-decoration:underline;margin-bottom:7pt;text-transform:uppercase;letter-spacing:.3px;}
.ps-info-table{width:100%;border-collapse:collapse;margin-bottom:6pt;border:1px solid #000;}
.ps-info-table td{padding:2.5pt 5pt;font-size:9pt;border:1px solid #000;vertical-align:middle;}
.ps-info-table td.lbl{font-weight:bold;width:19%;white-space:nowrap;}
.ps-info-table td.val{width:31%;}
.ps-main-table{width:100%;border-collapse:collapse;margin-bottom:6pt;border:1px solid #000;}
.ps-main-table th{font-size:9.5pt;font-weight:bold;text-align:center;padding:3.5pt 6pt;border:1px solid #000;background:#ebebeb;}
.ps-main-table td{font-size:9pt;padding:2.5pt 6pt;border:1px solid #000;vertical-align:middle;}
.ps-main-table td.el{width:36%;}
.ps-main-table td.ev{width:14%;text-align:right;white-space:nowrap;}
.ps-main-table td.dl{width:36%;}
.ps-main-table td.dv{width:14%;text-align:right;white-space:nowrap;}
.ps-main-table tr.tot td{font-weight:bold;border-top:1.5px solid #000;background:#f5f5f5;}
.ps-main-table tr.net td{font-weight:bold;font-size:10pt;background:#f0f0f0;}
.ps-epf-table{width:52%;border-collapse:collapse;margin-bottom:6pt;border:1px solid #000;}
.ps-epf-table td{font-size:9pt;padding:2.5pt 6pt;border:1px solid #000;vertical-align:middle;}
.ps-epf-table td.el{width:60%;}
.ps-epf-table td.er{width:12%;text-align:center;font-weight:bold;}
.ps-epf-table td.ev{width:28%;text-align:right;}
.ps-sign-row{display:flex;justify-content:space-between;margin-top:14pt;font-size:9pt;}
.ps-sign-box{text-align:center;border-top:1px solid #000;padding-top:3pt;width:38%;}
.ps-foot{font-size:7.5pt;color:#444;text-align:center;margin-top:7pt;border-top:1px dashed #999;padding-top:3pt;font-style:italic;}
</style>
</head>
<body>

<!-- ── CONTROL PANEL ── -->
<div class="ctrl-panel">
    <div class="ctrl-panel-title"><i class="fa-solid fa-print"></i> Payslip Print</div>
    <div class="ctrl-panel-sub">Select payroll period, staff category and (optionally) a single employee, then click Print.</div>
    <form method="GET" action="" style="display:contents;">
        <div class="ctrl-group">
            <span class="ctrl-label">Payroll Period</span>
            <select name="period_id" class="ctrl-select" onchange="this.form.submit()">
                <?php foreach ($all_periods as $pp): ?>
                <option value="<?php echo $pp['id']; ?>" <?php echo $sel_period_id==$pp['id']?'selected':''; ?>>
                    <?php echo $month_names[$pp['month']].' '.$pp['year'];
                    echo ($pp['status']!=='Open')?' ('.$pp['status'].')':''; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ctrl-group">
            <span class="ctrl-label">Staff Category</span>
            <select name="category_id" class="ctrl-select" onchange="this.form.submit()">
                <option value="0">All Categories</option>
                <?php foreach ($cats as $cat): ?>
                <option value="<?php echo $cat['id']; ?>" <?php echo $sel_cat_id==$cat['id']?'selected':''; ?>>
                    <?php echo htmlspecialchars($cat['category_code'].' — '.$cat['category_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ctrl-group emp-filter">
            <span class="ctrl-label">Employee</span>
            <select name="employee_id" id="employeeSelect" class="ctrl-select" onchange="this.form.submit()">
                <option value="0">All Employees<?php echo $sel_cat_id ? ' (in category)' : ''; ?></option>
                <?php foreach ($emp_list as $el): ?>
                <option value="<?php echo $el['id']; ?>" <?php echo $sel_emp_id==$el['id']?'selected':''; ?>>
                    <?php echo htmlspecialchars(($el['employee_id'] ?? '').' — '.($el['employee_full_name'] ?? '')); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if (!empty($payslips)): ?>
        <span class="count-badge"><i class="fa-solid fa-users"></i> <?php echo count($payslips); ?> employee<?php echo count($payslips)!==1?'s':''; ?></span>
        <?php endif; ?>
        <button type="button" class="btn-print" onclick="openPrintPage()"><i class="fa-solid fa-print"></i> Print All Payslips</button>
    </form>
</div>

<?php if (empty($payslips) && empty($missing_sheet)): ?>
<div class="no-data">
    <i class="fa-solid fa-file-invoice" style="font-size:36px;display:block;margin-bottom:10px;opacity:.3;"></i>
    No employees found for the selected period, category and employee filter.
</div>
<?php else: ?>

<?php if (!empty($missing_sheet)): ?>
<div class="preview-notice" style="border-left-color:#b45309;background:#fffbeb;">
    <i class="fa-solid fa-triangle-exclamation" style="color:#b45309;"></i>
    <div>
        <strong><?php echo count($missing_sheet); ?></strong> employee<?php echo count($missing_sheet)!==1?'s':''; ?>
        excluded — no saved salary sheet for
        <strong><?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : '—'; ?></strong>
        yet. Generate and save their salary sheet in the relevant category screen first, then reprint.
        <div style="margin-top:4px;font-size:11px;color:#92400e;">
            <?php echo htmlspecialchars(implode(', ', array_map(fn($e) => ($e['employee_id'] ?? '').' — '.($e['employee_full_name'] ?? ''), $missing_sheet))); ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($payslips)): ?>
<div class="preview-notice">
    <i class="fa-solid fa-eye" style="color:#2e1065;"></i>
    Preview — <strong><?php echo count($payslips); ?></strong> payslip<?php echo count($payslips)!==1?'s':''; ?> for
    <strong><?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : '—'; ?></strong><?php
        if ($sel_emp_id) {
            $picked = null;
            foreach ($emp_list as $el) { if ($el['id']==$sel_emp_id) { $picked = $el; break; } }
            if ($picked) echo ' — <strong>'.htmlspecialchars(($picked['employee_id'] ?? '').' — '.($picked['employee_full_name'] ?? '')).'</strong>';
        }
    ?>,
    built from each employee's saved salary sheet.
    Click <strong>Print All Payslips</strong> to open the print window.
    <span style="background:#dcfce7;color:#166534;border-radius:10px;padding:2px 9px;font-size:11px;font-weight:700;margin-left:4px;"><i class="fa-solid fa-circle-check"></i> Saved sheet data</span>
</div>
<?php endif; ?>

<!-- ── SCREEN PREVIEW ── -->
<?php foreach ($payslips as $ps): ?>
<div class="payslip-wrapper">
    <div class="ps">
        <?php echo build_payslip_rows($ps, $epf_employee_rate, $epf_employer_rate, $etf_employer_rate, $month_names, $active_period); ?>
    </div>
</div>
<?php endforeach; ?>

<!-- ── PRINT DATA (hidden, picked up by JS) ── -->
<div id="printData" style="display:none;">
<?php foreach ($payslips as $ps): ?>
<div class="ps-item">
    <?php echo build_payslip_rows($ps, $epf_employee_rate, $epf_employer_rate, $etf_employer_rate, $month_names, $active_period); ?>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php include 'footer.php'; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>
<script>
// Searchable Employee filter — plain <select> becomes hard to scan once the
// roster is more than a handful of names, so it's upgraded to Select2. The
// underlying <select name="employee_id"> element (and its onchange="this.
// form.submit()") is untouched, so the GET filter logic above needs no
// changes — Select2 just adds a search box and fires the same native
// 'change' event when a person is picked.
jQuery(function ($) {
    $('#employeeSelect').select2({
        placeholder: 'Search employee…',
        allowClear: false,
        width: '100%',
        minimumResultsForSearch: 0, // always show the search box, even for short lists
        dropdownAutoWidth: false
    });
});

function openPrintPage() {
    var items = document.querySelectorAll('#printData .ps-item');
    if (!items.length) { alert('No payslips to print.'); return; }

    var slipCSS = `
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #fff; font-family: 'Times New Roman', Times, serif; }

        .ps-page {
            width: 210mm;
            min-height: 297mm;
            padding: 14mm 16mm;
            display: flex;
            flex-direction: column;
            page-break-after: always;
            break-after: page;
        }
        .ps-page:last-child {
            page-break-after: avoid;
            break-after: avoid;
        }

        .ps-co-name  { text-align:center; font-size:14pt; font-weight:bold; margin-bottom:4pt; letter-spacing:.3px; }
        .ps-title    { text-align:center; font-size:11pt; font-weight:bold; text-decoration:underline; margin-bottom:10pt; text-transform:uppercase; letter-spacing:.3px; }

        .ps-info-table { width:100%; border-collapse:collapse; margin-bottom:10pt; border:1px solid #000; }
        .ps-info-table td { padding:4pt 6pt; font-size:10pt; border:1px solid #000; vertical-align:middle; }
        .ps-info-table td.lbl { font-weight:bold; width:19%; white-space:nowrap; }
        .ps-info-table td.val { width:31%; }

        .ps-main-table { width:100%; border-collapse:collapse; margin-bottom:10pt; border:1px solid #000; }
        .ps-main-table th { font-size:10.5pt; font-weight:bold; text-align:center; padding:5pt 6pt; border:1px solid #000; background:#ebebeb; }
        .ps-main-table td { font-size:10pt; padding:4pt 6pt; border:1px solid #000; vertical-align:middle; }
        .ps-main-table td.el { width:36%; }
        .ps-main-table td.ev { width:14%; text-align:right; white-space:nowrap; }
        .ps-main-table td.dl { width:36%; }
        .ps-main-table td.dv { width:14%; text-align:right; white-space:nowrap; }
        .ps-main-table tr.tot td { font-weight:bold; border-top:2px solid #000; background:#f5f5f5; }
        .ps-main-table tr.net td { font-weight:bold; font-size:11.5pt; background:#f0f0f0; }

        .ps-epf-table { width:55%; border-collapse:collapse; margin-bottom:10pt; border:1px solid #000; }
        .ps-epf-table td { font-size:10pt; padding:4pt 6pt; border:1px solid #000; vertical-align:middle; }
        .ps-epf-table td.el { width:60%; }
        .ps-epf-table td.er { width:12%; text-align:center; font-weight:bold; }
        .ps-epf-table td.ev { width:28%; text-align:right; }

        .ps-sign-row  { display:flex; justify-content:space-between; margin-top:30pt; font-size:10pt; }
        .ps-sign-box  { text-align:center; border-top:1px solid #000; padding-top:4pt; width:38%; }

        .ps-foot { font-size:8pt; color:#444; text-align:center; margin-top:12pt; border-top:1px dashed #999; padding-top:4pt; font-style:italic; }

        @page { size: A4 portrait; margin: 0; }
        @media print {
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    `;

    var body = '';
    items.forEach(function(item) {
        body += '<div class="ps-page">' + item.innerHTML + '</div>';
    });

    var fullHTML = '<!DOCTYPE html><html><head>'
        + '<meta charset="UTF-8">'
        + '<title>Payslips</title>'
        + '<style>' + slipCSS + '</style>'
        + '</head><body>'
        + body
        + '</body></html>';

    var w = window.open('', '_blank', 'width=900,height=700');
    w.document.open();
    w.document.write(fullHTML);
    w.document.close();
    w.onload = function() {
        w.focus();
        w.print();
    };
}
</script>

</body>
</html>