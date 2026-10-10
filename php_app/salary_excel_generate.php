<?php
/**
 * salary_excel_generate.php
 * ─────────────────────────────────────────────────────────────────────────
 * "Generate Salary Excel" — bulk, one-shot Excel export for a payroll month,
 * across any combination of the 6 salary-sheet categories (SR, CC, ACC, MR, ST, OFF).
 *
 * IMPORTANT — earnings/deductions logic MIRRORS print_payslips.php EXACTLY:
 *   - Every earnings/deductions figure is resolved the SAME way the payslip
 *     resolves it: a "live" value from incentive_entries / sr_fuel_entries /
 *     salary_advances / the employee profile WINS when one exists; otherwise
 *     the saved salary-sheet snapshot column is used. See live_earn_value()
 *     below, copied field-for-field from print_payslips.php's own resolver.
 *   - Total Earnings / Total Deductions / Net Salary are DERIVED by summing
 *     the actual itemized rows shown on this sheet — a separately stored
 *     total column can silently drift from the itemized breakdown, so we
 *     never trust it.
 *   - PAYEE (Income Tax) is included as its own deduction line.
 *   - Salary Advance is always the LIVE figure from salary_advances (or the
 *     employee profile fallback), never the stale saved snapshot column.
 *   - MR's "Placement Compliance" (ps_amt) resolves via the live
 *     incentive_entries lookup, exactly like the payslip does.
 *
 *   - SR's Meal / Travelling / Mobile allowances are the exception to the
 *     "live wins" rule above: for SR specifically these three always come
 *     straight from the SAVED salary-sheet snapshot columns (meal / travel /
 *     mobile), never a live incentive_entries / profile override. This is
 *     intentional — see live_earn_value()'s SR carve-out and
 *     map_category_row() below.
 *   - OFF (Office Staff) has no Meal Allowance at all — it is always
 *     exported as 0 for that category, regardless of anything saved or
 *     live, since Office Staff are not entitled to it.
 *   - CC's Attendance Incentive is surfaced through the shared "Attendance
 *     Allowance" column instead of its own separate detail column, so it
 *     lines up with how every other category's attendance figure is shown.
 *
 * ── MASTER "Salary Sheet" FORMAT (v2 — matches Salary_Yelo_Logistics_May_2026_Checked.xlsx) ──
 *   Sheet 1 "Salary Sheet" now reproduces the company's manually-checked
 *   payroll workbook exactly: one continuous sheet, employees grouped
 *   block-by-block in the fixed order SR → MR → CC → ACC → ST → OFF, each
 *   block followed by its own "Sub Total" row, and a grand "TOTAL" row at
 *   the very end. Column order/headers match that workbook's "Salary Sheet"
 *   tab column-for-column (EPF No, Name, Designation, Employee ID, Date of
 *   Join, Category, Status, Employee Code, Payment Type, Norm Days, Days
 *   Wrkd, Before 7.45, Basic Salary, Total Earnings, Total Deductions, Net
 *   Salary, 12% EPF, 3% ETF, Bonus, Gratuity, Insurance, Total Benefit,
 *   then the ADDITIONS band, then the DEDUCTIONS band, then Month / Payroll
 *   Month / E mail). See $MASTER_COLUMNS / map_master_row() / MASTER_ORDER
 *   below — a few of that workbook's columns (Late Fine, Before 7.45, cash
 *   Bonus paid as an earning) have no corresponding data source anywhere
 *   else in the system yet, so they are always exported as 0; that's called
 *   out inline wherever it happens.
 *
 *   - 12% EPF / 3% ETF (employer contributions) and Total Benefit are always
 *     CALCULATED, never read from a saved column: EPF = 12% of Basic
 *     Adjusted Salary (Basic − No Pay), ETF = 3% of Basic Adjusted Salary,
 *     and Total Benefit = Total Earnings + EPF (12%) + ETF (3%) + Gratuity
 *     + Insurance. See map_category_row() below.
 *   - Welfare Deduction and Welfare Loan Deduction are always shown as their
 *     own separate line items (never merged into a single combined figure),
 *     on both the master sheet and the per-category sheets.
 *
 * ── EMPTY-COLUMN AUTO-HIDE (v3) ──
 *   Any Earnings/Deductions/Incentive-detail column whose value is zero /
 *   blank for EVERY row on a given sheet (master OR per-category) is left
 *   out of that sheet entirely — see filter_empty_columns() below. This
 *   keeps the workbook free of big blank columns for components a category
 *   never uses (e.g. "Locus Admin" for a category with no CC-style admin
 *   incentive). Identity/meta columns (Name, EPF No, Basic Salary, the
 *   Total Earnings/Deductions/Net Salary summary, etc.) are never hidden.
 *   Each sheet is filtered independently against only the rows that will
 *   actually appear on it, so the master sheet and a given category's own
 *   tab can legitimately show a different set of columns.
 *
 * - Pulls the saved salary_sheet_entries (SR/CC/ACC/MR) / st_salary_sheet_entries
 *   (ST) / off_salary_sheet_entries (OFF) rows for the selected payroll_period_id
 *   as its BASE data, then applies the same live overrides print_payslips.php
 *   applies, so the Excel export and the printed payslip always agree.
 * - Automatically EXCLUDES resigned / terminated employees (see
 *   excluded_employee_sql() below) so they never appear in the export.
 * - Produces a single real .xlsx workbook (native ZipArchive writer, no
 *   PhpSpreadsheet / Composer dependency needed) with:
 *      Sheet 1  "Salary Sheet" - everyone, grouped by category with sub-totals
 *      Sheet 2  "Summary"      - per-category counts & totals
 *      Sheet 3+ one sheet per selected category (SR / CC / ACC / MR / ST / OFF)
 * ─────────────────────────────────────────────────────────────────────────
 */

// Guard against any stray output from config.php/header.php (BOM, whitespace,
// warnings, session notices, etc.) corrupting the binary .xlsx stream later.
// We flush/discard this buffer right before sending file headers below.
ob_start();

include 'config.php';

// ── Defensive schema safety-net ──────────────────────────────────────────
// (Idempotent — makes this page work even if some category pages have
// never been opened yet on this server, so column order/visit order can
// never break the export.)
$shared_cols = [
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS exit_date DATE NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS exit_reason TEXT NULL",
];
foreach ($shared_cols as $s) @mysqli_query($conn, $s);

mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS salary_sheet_entries (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    payroll_period_id       INT NOT NULL,
    employee_id             INT NOT NULL,
    employee_code           VARCHAR(50),
    employee_name           VARCHAR(200),
    epf_number              VARCHAR(50),
    date_of_join            DATE,
    status                  VARCHAR(50),
    category_code           VARCHAR(50),
    designation_name        VARCHAR(200),
    company_code            VARCHAR(50),
    branch_code             VARCHAR(50),
    att_count               INT DEFAULT 0,
    norm_days               INT DEFAULT 0,
    att_rate                DECIMAL(6,2) DEFAULT 0,
    no_pay_days             DECIMAL(8,2) DEFAULT 0,
    no_pay_amount           DECIMAL(12,2) DEFAULT 0,
    basic                   DECIMAL(12,2) DEFAULT 0,
    total_earnings          DECIMAL(12,2) DEFAULT 0,
    total_deductions        DECIMAL(12,2) DEFAULT 0,
    net_salary              DECIMAL(12,2) DEFAULT 0,
    sheet_status            VARCHAR(20) DEFAULT 'Pending',
    UNIQUE KEY uq_period_emp (payroll_period_id, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
// Full union of columns ever added by sr/cc/acc/mr_salary.php — safe no-ops if already present.
$sse_cols = [
    "att_allow DECIMAL(12,2) DEFAULT 0", "disc DECIMAL(12,2) DEFAULT 0", "ssv DECIMAL(12,2) DEFAULT 0",
    "ps_amt DECIMAL(12,2) DEFAULT 0", "eco_amt DECIMAL(12,2) DEFAULT 0", "bp_amt DECIMAL(12,2) DEFAULT 0",
    "assort_amt DECIMAL(12,2) DEFAULT 0", "other_incentive DECIMAL(12,2) DEFAULT 0",
    "meal DECIMAL(12,2) DEFAULT 0", "travel DECIMAL(12,2) DEFAULT 0", "fuel DECIMAL(12,2) DEFAULT 0",
    "mobile DECIMAL(12,2) DEFAULT 0", "arrears DECIMAL(12,2) DEFAULT 0",
    "epf_emp DECIMAL(12,2) DEFAULT 0", "welfare_amt DECIMAL(12,2) DEFAULT 0", "w_loan DECIMAL(12,2) DEFAULT 0",
    "w_soc DECIMAL(12,2) DEFAULT 0", "donations DECIMAL(12,2) DEFAULT 0", "sal_adv DECIMAL(12,2) DEFAULT 0",
    "loan_ded DECIMAL(12,2) DEFAULT 0", "credit_r DECIMAL(12,2) DEFAULT 0", "retention DECIMAL(12,2) DEFAULT 0",
    "excess_p DECIMAL(12,2) DEFAULT 0", "epf_er DECIMAL(12,2) DEFAULT 0", "etf_er DECIMAL(12,2) DEFAULT 0",
    "insurance DECIMAL(12,2) DEFAULT 0", "bonus DECIMAL(12,2) DEFAULT 0", "gratuity DECIMAL(12,2) DEFAULT 0",
    "cost_bp DECIMAL(12,2) DEFAULT 0", "total_benefit DECIMAL(12,2) DEFAULT 0",
    "additional_earnings TEXT DEFAULT NULL", "additional_deductions TEXT DEFAULT NULL",
    // CC-specific
    "ccfot_amt DECIMAL(12,2) DEFAULT 0", "credit_mgt_amt DECIMAL(12,2) DEFAULT 0", "payee_chq_amt DECIMAL(12,2) DEFAULT 0",
    "ssv_cc_amt DECIMAL(12,2) DEFAULT 0", "att_inc_amt DECIMAL(12,2) DEFAULT 0", "locus_admin_amt DECIMAL(12,2) DEFAULT 0",
    "cash_short DECIMAL(12,2) DEFAULT 0", "good_short DECIMAL(12,2) DEFAULT 0",
    // ACC-specific
    "ssv_acc_amt DECIMAL(12,2) DEFAULT 0", "punctuality_amt DECIMAL(12,2) DEFAULT 0", "att_allow_amt DECIMAL(12,2) DEFAULT 0",
    // MR-specific
    "plc_70 DECIMAL(12,2) DEFAULT 0", "plc_80 DECIMAL(12,2) DEFAULT 0", "plc_90 DECIMAL(12,2) DEFAULT 0",
    "att_inc_70 DECIMAL(12,2) DEFAULT 0", "att_inc_80 DECIMAL(12,2) DEFAULT 0", "att_inc_90 DECIMAL(12,2) DEFAULT 0",
    "dlink_amount DECIMAL(12,2) DEFAULT 0",
];
foreach ($sse_cols as $c) @mysqli_query($conn, "ALTER TABLE salary_sheet_entries ADD COLUMN IF NOT EXISTS $c");

// ST lives in its own dedicated table (mirrors st_salary.php exactly).
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS st_salary_sheet_entries (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    payroll_period_id       INT NOT NULL,
    employee_id             INT NOT NULL,
    employee_code           VARCHAR(50),
    employee_name           VARCHAR(200),
    epf_number              VARCHAR(50),
    date_of_join            DATE,
    status                  VARCHAR(50),
    category_code           VARCHAR(50),
    designation_name        VARCHAR(200),
    company_code            VARCHAR(50),
    branch_code             VARCHAR(50),
    att_count               INT DEFAULT 0,
    norm_days               INT DEFAULT 0,
    att_rate                DECIMAL(6,2) DEFAULT 0,
    no_pay_days             DECIMAL(8,2) DEFAULT 0,
    no_pay_amount           DECIMAL(12,2) DEFAULT 0,
    att_allow               DECIMAL(12,2) DEFAULT 0,
    att_allow_tier          VARCHAR(10) DEFAULT '',
    basic                   DECIMAL(12,2) DEFAULT 0,
    stores_damage_amt       DECIMAL(12,2) DEFAULT 0,
    rsqm_amt                DECIMAL(12,2) DEFAULT 0,
    punctuality_amt         DECIMAL(12,2) DEFAULT 0,
    loading_unloading_amt   DECIMAL(12,2) DEFAULT 0,
    ccfot_amt               DECIMAL(12,2) DEFAULT 0,
    meal                    DECIMAL(12,2) DEFAULT 0,
    arrears                 DECIMAL(12,2) DEFAULT 0,
    total_earnings          DECIMAL(12,2) DEFAULT 0,
    epf_emp                 DECIMAL(12,2) DEFAULT 0,
    welfare_amt             DECIMAL(12,2) DEFAULT 0,
    w_loan                  DECIMAL(12,2) DEFAULT 0,
    w_soc                   DECIMAL(12,2) DEFAULT 0,
    donations               DECIMAL(12,2) DEFAULT 0,
    sal_adv                 DECIMAL(12,2) DEFAULT 0,
    loan_ded                DECIMAL(12,2) DEFAULT 0,
    credit_r                DECIMAL(12,2) DEFAULT 0,
    retention               DECIMAL(12,2) DEFAULT 0,
    excess_p                DECIMAL(12,2) DEFAULT 0,
    total_deductions        DECIMAL(12,2) DEFAULT 0,
    net_salary              DECIMAL(12,2) DEFAULT 0,
    epf_er                  DECIMAL(12,2) DEFAULT 0,
    etf_er                  DECIMAL(12,2) DEFAULT 0,
    insurance               DECIMAL(12,2) DEFAULT 0,
    bonus                   DECIMAL(12,2) DEFAULT 0,
    gratuity                DECIMAL(12,2) DEFAULT 0,
    total_benefit           DECIMAL(12,2) DEFAULT 0,
    additional_earnings     TEXT DEFAULT NULL,
    additional_deductions   TEXT DEFAULT NULL,
    sheet_status            VARCHAR(20) DEFAULT 'Pending',
    UNIQUE KEY uq_period_emp (payroll_period_id, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// OFF lives in its own dedicated table (mirrors off_salary.php exactly).
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS off_salary_sheet_entries (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    payroll_period_id       INT NOT NULL,
    employee_id             INT NOT NULL,
    employee_code           VARCHAR(50),
    employee_name           VARCHAR(200),
    epf_number              VARCHAR(50),
    date_of_join            DATE,
    status                  VARCHAR(50),
    category_code           VARCHAR(50),
    designation_name        VARCHAR(200),
    company_code            VARCHAR(50),
    branch_code             VARCHAR(50),
    att_count               INT DEFAULT 0,
    norm_days               INT DEFAULT 0,
    att_rate                DECIMAL(6,2) DEFAULT 0,
    no_pay_days             DECIMAL(8,2) DEFAULT 0,
    no_pay_amount           DECIMAL(12,2) DEFAULT 0,
    att_allow               DECIMAL(12,2) DEFAULT 0,
    basic                   DECIMAL(12,2) DEFAULT 0,
    daily_incent            DECIMAL(12,2) DEFAULT 0,
    weekly_incent           DECIMAL(12,2) DEFAULT 0,
    monthly_incent          DECIMAL(12,2) DEFAULT 0,
    punctuality_amt         DECIMAL(12,2) DEFAULT 0,
    meal                    DECIMAL(12,2) DEFAULT 0,
    arrears                 DECIMAL(12,2) DEFAULT 0,
    total_earnings          DECIMAL(12,2) DEFAULT 0,
    epf_emp                 DECIMAL(12,2) DEFAULT 0,
    welfare_amt             DECIMAL(12,2) DEFAULT 0,
    w_loan                  DECIMAL(12,2) DEFAULT 0,
    w_soc                   DECIMAL(12,2) DEFAULT 0,
    donations               DECIMAL(12,2) DEFAULT 0,
    sal_adv                 DECIMAL(12,2) DEFAULT 0,
    loan_ded                DECIMAL(12,2) DEFAULT 0,
    credit_r                DECIMAL(12,2) DEFAULT 0,
    retention               DECIMAL(12,2) DEFAULT 0,
    excess_p                DECIMAL(12,2) DEFAULT 0,
    total_deductions        DECIMAL(12,2) DEFAULT 0,
    net_salary              DECIMAL(12,2) DEFAULT 0,
    epf_er                  DECIMAL(12,2) DEFAULT 0,
    etf_er                  DECIMAL(12,2) DEFAULT 0,
    insurance               DECIMAL(12,2) DEFAULT 0,
    bonus                   DECIMAL(12,2) DEFAULT 0,
    gratuity                DECIMAL(12,2) DEFAULT 0,
    total_benefit           DECIMAL(12,2) DEFAULT 0,
    additional_earnings     TEXT DEFAULT NULL,
    additional_deductions   TEXT DEFAULT NULL,
    sheet_status            VARCHAR(20) DEFAULT 'Pending',
    UNIQUE KEY uq_period_emp (payroll_period_id, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ── Category registry ────────────────────────────────────────────────────
const ALL_CATEGORIES = ['SR', 'CC', 'ACC', 'MR', 'ST', 'OFF'];

// Fixed block order for the master "Salary Sheet" tab — matches the order
// employees are grouped in Salary_Yelo_Logistics_May_2026_Checked.xlsx
// (SR block, then MR, then CC, then ACC, then ST, then OFF), NOT the order
// categories are listed in the on-screen chip picker above (ALL_CATEGORIES).
const MASTER_SHEET_ORDER = ['SR', 'MR', 'CC', 'ACC', 'ST', 'OFF'];

// ── Small helpers ─────────────────────────────────────────────────────────
function sum_additional_json($json) {
    if (empty($json)) return 0.0;
    $arr = json_decode($json, true);
    if (!is_array($arr)) return 0.0;
    $t = 0.0;
    foreach ($arr as $item) $t += floatval($item['amount'] ?? 0);
    return $t;
}

/** Builds a human-readable "desc: amount | desc: amount" breakdown string
 *  from an additional_earnings/additional_deductions JSON blob — same
 *  {desc, amount} row shape every category page's own free-form
 *  earnings/deductions rows use, and the same "desc||'Item'" / 2-decimal
 *  format sr_salary.php's own CSV export already uses for this, so the
 *  wording matches what's already familiar from that export. */
function detail_additional_json($json) {
    if (empty($json)) return '';
    $arr = json_decode($json, true);
    if (!is_array($arr)) return '';
    $parts = [];
    foreach ($arr as $item) {
        $amt = floatval($item['amount'] ?? 0);
        $desc = trim((string)($item['desc'] ?? ''));
        if ($desc === '' && $amt == 0) continue;
        $parts[] = ($desc !== '' ? $desc : 'Item') . ': ' . number_format($amt, 2);
    }
    return implode(' | ', $parts);
}

/** True (as SQL fragment) selects rows whose employee should be EXCLUDED.
 *  IMPORTANT: compares against the START of the payroll period, not the end —
 *  an employee who resigns DURING the month being paid still worked part of
 *  it and is owed a final settlement, so they must still appear in that
 *  month's sheet. They only disappear from the FOLLOWING month onward. */
function excluded_employee_sql($period_start) {
    $ps = mysqli_real_escape_string($GLOBALS['conn'], $period_start);
    return "(
        (e.exit_date IS NOT NULL AND e.exit_date < '$ps')
        OR (e.exit_date IS NULL AND e.status IN ('Resigned','Terminated'))
    )";
}

function period_start_date($period) {
    return sprintf('%04d-%02d-01', (int)$period['year'], (int)$period['month']);
}

function period_end_date($period) {
    return date('Y-m-t', strtotime(period_start_date($period)));
}

function period_label($period) {
    return date('F Y', strtotime(sprintf('%04d-%02d-01', (int)$period['year'], (int)$period['month'])));
}

// =========================================================================
//  LIVE-VALUE RESOLUTION — copied field-for-field from print_payslips.php
//  so the Excel export always agrees with the printed payslip.
// =========================================================================

function inc_lookup($inc_entries, $eid, $pattern) {
    foreach ($inc_entries[$eid] ?? [] as $lbl => $val) if (strpos($lbl, $pattern) !== false) return floatval($val);
    return 0;
}

/** Category-aware live resolver. Only covers fields verified as a SIMPLE
 *  direct lookup (component_label in incentive_entries, or a plain profile
 *  column) in each category's own source — deliberately does NOT attempt to
 *  replicate any tiered/achievement-% incentive formula, since guessing at
 *  that risks a subtly wrong number, which is worse than staying with the
 *  saved figure. Returns null when there's nothing safe to live-override,
 *  so the caller keeps whatever the saved sheet had.
 *  (Identical logic to print_payslips.php::live_earn_value() — this is
 *  exactly what makes MR's "Placement Compliance" resolve correctly here,
 *  since its save handler never persists that figure under any column.)
 *
 *  NOTE — SR carve-out: SR's Meal / Travelling / Mobile are deliberately
 *  NOT included in $reimb['SR'] below (and 'mobile' has no direct[SR] entry
 *  either) — for SR these three always come from the saved sheet snapshot,
 *  never a live override. map_category_row() enforces this explicitly too,
 *  but keeping SR out of the live lookup tables here as well means this
 *  resolver can never accidentally hand back a live figure for them.
 *
 *  NOTE — OFF carve-out: OFF has no Meal Allowance entitlement at all, so
 *  'OFF' is deliberately absent from $reimb below. map_category_row() also
 *  forces OFF's meal to 0 explicitly regardless of what this returns. */
function live_earn_value($cat, $key, $eid, $emp, $inc_entries, $sr_fuel_map) {
    $direct = [
        // category => [field_key => incentive_entries pattern]
        'ACC' => ['ccfot_amt'=>'__incent__ccfot_acc','ssv_acc_amt'=>'__incent__ssv_acc','punctuality_amt'=>'__incent__punctuality_acc','att_allow_amt'=>'__incent__attendance_acc'],
        'CC'  => ['ccfot_amt'=>'__incent__ccfot','credit_mgt_amt'=>'__incent__credit_mgt','payee_chq_amt'=>'__incent__payee_chq','ssv_cc_amt'=>'__incent__ssv_cc','att_inc_amt'=>'__incent__attendance_cc','locus_admin_amt'=>'__incent__locus_admin'],
        'MR'  => ['ps_amt'=>'__incent__placement_compliance','att_allow'=>'__incent__attendance'],
        'SR'  => ['disc'=>'__disc_amount','ssv'=>'__ssv_amount','ps_amt'=>'__incent__total_ps_compliance','eco_amt'=>'__incent__eco_incentive','bp_amt'=>'__incent__bp_incentive','assort_amt'=>'__incent__total_assortment','other_incentive'=>'__other_incentive'],
        'OFF' => ['daily_incent'=>'__incent__incentive_daily','weekly_incent'=>'__incent__incentive_weekly','monthly_incent'=>'__incent__incentive_monthly','punctuality_amt'=>'__incent__punctuality_75'],
        'ST'  => ['stores_damage_amt'=>'__incent__stores_damage','rsqm_amt'=>'__incent__rsqm','punctuality_amt'=>'__incent__punctuality','loading_unloading_amt'=>'__incent__loading_unloading','ccfot_amt'=>'__incent__ccfot_st'],
    ];
    // Meal/travel reimbursements: same "entry overrides profile" pattern in ACC/MR/ST.
    // SR is deliberately excluded here — its meal/travel always come from the
    // saved sheet column instead (see map_category_row()'s SR carve-out).
    // OFF is deliberately excluded too — Office Staff have no Meal Allowance.
    $reimb = [
        'MR' => ['meal'=>['__reimb__meal_reimbursement','reimbursement_meal'], 'travel'=>['__reimb__traveling_reimbursement','reimbursement_traveling']],
        'ST' => ['meal'=>['__reimb__meal_reimbursement','reimbursement_meal']],
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
    if ($cat === 'SR' && $key === 'att_allow') {
        $v = floatval($emp['attendance_allowance'] ?? 0);
        return $v > 0 ? $v : null;
    }
    if ($cat === 'SR' && $key === 'fuel') {
        if (isset($sr_fuel_map[$eid])) return $sr_fuel_map[$eid];
        $v = floatval($emp['reimbursement_fuel'] ?? 0);
        return $v > 0 ? $v : null;
    }
    // CC's Mobile Data Allowance still live-overrides from the employee
    // profile (mobile_reimbursement / reimbursement_mobile) — this is
    // unrelated to the SR carve-out above and was NOT meant to change.
    if ($cat === 'CC' && $key === 'mobile') {
        $v = floatval($emp['mobile_reimbursement'] ?? $emp['reimbursement_mobile'] ?? 0);
        return $v > 0 ? $v : null;
    }
    return null;
}

/** Builds "e.col AS ep_col" (or "0 AS ep_col" if the column doesn't exist on
 *  this install) for every employee-profile field live_earn_value() /
 *  Salary-Advance / PAYEE / Payment Type / Email resolution needs — mirrors
 *  print_payslips.php's own defensive column handling so this page never
 *  fatals on installs missing one of these optional profile columns. */
function employee_profile_select_sql($conn) {
    $wanted = [
        'attendance_allowance', 'reimbursement_fuel', 'mobile_reimbursement', 'reimbursement_mobile',
        'reimbursement_meal', 'reimbursement_traveling', 'payee', 'income_tax', 'salary_advance',
        'payment_type', 'email',
    ];
    $existing = [];
    $res = @mysqli_query($conn, "SHOW COLUMNS FROM employees");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $existing[strtolower($r['Field'])] = true;
    $parts = [];
    foreach ($wanted as $col) {
        $isTextCol = in_array($col, ['payment_type', 'email'], true);
        if (isset($existing[strtolower($col)])) {
            $parts[] = "e.`$col` AS ep_$col";
        } else {
            $parts[] = ($isTextCol ? "'' " : "0 ") . "AS ep_$col";
        }
    }
    return implode(', ', $parts);
}

/** Same source print_payslips.php reads live: the current incentive_entries
 *  table (category-specific incentive components snapshot at save time can
 *  go stale the moment a new entry is logged — this keeps the export current). */
function fetch_incentive_entries($conn, $pid, array $emp_ids) {
    $out = [];
    if (empty($emp_ids)) return $out;
    $ids_str = implode(',', array_map('intval', $emp_ids));
    $res = @mysqli_query($conn, "SELECT employee_id, component_label, amount FROM incentive_entries WHERE payroll_period_id=$pid AND employee_id IN ($ids_str)");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $out[(int)$r['employee_id']][$r['component_label']] = (float)$r['amount'];
    return $out;
}

/** SR's fuel is its own period-based entry table (fuel_rate × fuel_liter) — same as print_payslips.php. */
function fetch_sr_fuel_map($conn, $pid, array $emp_ids) {
    $out = [];
    if (empty($emp_ids)) return $out;
    $ids_str = implode(',', array_map('intval', $emp_ids));
    $res = @mysqli_query($conn, "SELECT employee_id, (fuel_rate * fuel_liter) AS fuel_amount FROM sr_fuel_entries WHERE payroll_period_id=$pid AND employee_id IN ($ids_str)");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $out[(int)$r['employee_id']] = floatval($r['fuel_amount']);
    return $out;
}

/** Salary Advance is ALWAYS the live figure — every category page recomputes
 *  it from salary_advances rather than trusting the saved snapshot, and the
 *  payslip does the same, so this export must too. */
function fetch_advance_map($conn, array $emp_ids, $period_start, $period_end) {
    $out = [];
    if (empty($emp_ids)) return $out;
    $ids_str = implode(',', array_map('intval', $emp_ids));
    $res = @mysqli_query($conn, "SELECT employee_id, SUM(amount) AS total FROM salary_advances
         WHERE status='Approved' AND employee_id IN ($ids_str) AND request_date BETWEEN '$period_start' AND '$period_end'
         GROUP BY employee_id");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $out[(int)$r['employee_id']] = floatval($r['total']);
    return $out;
}

/** Maps one raw DB row (salary_sheet_entries / st_salary_sheet_entries /
 *  off_salary_sheet_entries, joined with the employee profile columns from
 *  employee_profile_select_sql()) into the unified export column set.
 *
 *  Every earnings/deductions figure below is resolved with "live value wins,
 *  else saved sheet value" — the exact same rule print_payslips.php applies
 *  — and Total Earnings / Total Deductions / Net Salary are the SUM of those
 *  resolved rows, never the separately-stored total column. This guarantees
 *  the Excel export always reconciles with what the payslip prints.
 *
 *  Exceptions to "live wins" (see notes at the top of the file and on
 *  live_earn_value()):
 *   - SR's Meal / Travelling / Mobile always come from the saved sheet.
 *   - OFF's Meal Allowance is always 0 (no entitlement).
 *   - CC's Attendance Incentive is surfaced through the "Attendance
 *     Allowance" column rather than its own separate incentive line, so it
 *     lines up with every other category's attendance figure. It is also
 *     still shown under its original "Attendance Incentive" detail column
 *     on CC's own per-category sheet for continuity, but only counted once
 *     towards Total Earnings (via Attendance Allowance).
 *
 *  Total Benefit / EPF / ETF are always CALCULATED here, never read from a
 *  saved column: EPF (12%) and ETF (3%) are 12%/3% of the Basic Adjusted
 *  Salary (Basic − No Pay), and Total Benefit = Total Earnings + EPF (12%)
 *  + ETF (3%) + Gratuity + Insurance. */
function map_category_row($row, $cat, $inc_entries, $sr_fuel_map, $advance_map) {
    $eid = (int)($row['employee_id'] ?? 0);
    $sheetVal = fn($k) => floatval($row[$k] ?? 0);

    $emp = [
        'attendance_allowance'    => floatval($row['ep_attendance_allowance'] ?? 0),
        'reimbursement_fuel'      => floatval($row['ep_reimbursement_fuel'] ?? 0),
        'mobile_reimbursement'    => floatval($row['ep_mobile_reimbursement'] ?? 0),
        'reimbursement_mobile'    => floatval($row['ep_reimbursement_mobile'] ?? 0),
        'reimbursement_meal'      => floatval($row['ep_reimbursement_meal'] ?? 0),
        'reimbursement_traveling' => floatval($row['ep_reimbursement_traveling'] ?? 0),
        'payee'                   => floatval($row['ep_payee'] ?? 0),
        'income_tax'              => floatval($row['ep_income_tax'] ?? 0),
        'salary_advance'          => floatval($row['ep_salary_advance'] ?? 0),
    ];

    /** live override wins, else saved sheet column — same rule as the payslip */
    $earn = function ($key) use ($cat, $eid, $emp, $inc_entries, $sr_fuel_map, $sheetVal) {
        $v = live_earn_value($cat, $key, $eid, $emp, $inc_entries, $sr_fuel_map);
        return $v !== null ? $v : $sheetVal($key);
    };

    // ── Core earnings (generic across every category, same as print_payslips.php's EARN_FIELDS) ──
    $basic   = $sheetVal('basic');
    $no_pay  = $sheetVal('no_pay_amount'); // computed early — needed for Basic Adjusted Salary / EPF / ETF below

    // Basic Adjusted Salary = Basic Salary less No Pay.
    $basic_adjusted = round($basic - $no_pay, 2);

    // SR carve-out: Meal / Travelling / Mobile always come straight from the
    // SAVED salary-sheet snapshot for SR — never a live incentive/profile
    // override — per the site's own confirmed sheet figures.
    // OFF carve-out: Office Staff have no Meal Allowance entitlement at all,
    // so it's always 0 for OFF regardless of anything saved or live.
    $travel  = ($cat === 'SR') ? $sheetVal('travel') : $earn('travel');
    $fuel    = $earn('fuel');
    $mobile  = ($cat === 'SR') ? $sheetVal('mobile') : $earn('mobile');
    $disc    = $earn('disc');
    $meal    = ($cat === 'SR') ? $sheetVal('meal') : (($cat === 'OFF') ? 0.0 : $earn('meal'));
    $arrears = $sheetVal('arrears'); // no live pattern defined for arrears — always the saved figure
    $other_earn = sum_additional_json($row['additional_earnings'] ?? null);

    // Split additional_earnings into up to two ordered slots (item 1 / item 2+)
    // purely for the master sheet's "Other Allowance 1" / "Other Allowance 2"
    // columns — their sum is always exactly $other_earn, so nothing is lost.
    $other_earn_items = [];
    if (!empty($row['additional_earnings'])) {
        $decoded = json_decode($row['additional_earnings'], true);
        if (is_array($decoded)) foreach ($decoded as $it) $other_earn_items[] = floatval($it['amount'] ?? 0);
    }
    $other_earn_item1 = $other_earn_items[0] ?? 0;
    $other_earn_item2 = array_sum(array_slice($other_earn_items, 1));

    // Attendance Allowance: ACC stores it under att_allow_amt; CC's attendance
    // figure is its "Attendance Incentive" (att_inc_amt) surfaced here instead
    // of as a separate incentive-detail line; every other category under
    // att_allow.
    $cc_att_incentive = 0.0;
    if ($cat === 'ACC') {
        $att_allow = $earn('att_allow_amt');
    } elseif ($cat === 'CC') {
        $cc_att_incentive = $earn('att_inc_amt');
        $att_allow = $cc_att_incentive;
    } else {
        $att_allow = $earn('att_allow');
    }

    // ── Category-specific incentive detail (resolved live, exactly like the payslip) ──
    $detail = [];
    switch ($cat) {
        case 'SR':
            $detail = [
                'ssv'             => $earn('ssv'),
                'ps_amt'          => $earn('ps_amt'),
                'eco_amt'         => $earn('eco_amt'),
                'bp_amt'          => $earn('bp_amt'),
                'assort_amt'      => $earn('assort_amt'),
                'other_incentive' => $earn('other_incentive'),
            ];
            break;
        case 'CC':
            // att_inc_amt deliberately excluded from $detail here — it's already
            // counted once via Attendance Allowance ($att_allow) above, and is
            // added back into $out (for display on CC's own sheet) further down
            // without being double-counted towards Total Earnings.
            $detail = [
                'ccfot_amt'       => $earn('ccfot_amt'),
                'credit_mgt_amt'  => $earn('credit_mgt_amt'),
                'payee_chq_amt'   => $earn('payee_chq_amt'),
                'ssv_cc_amt'      => $earn('ssv_cc_amt'),
                'locus_admin_amt' => $earn('locus_admin_amt'),
            ];
            break;
        case 'ACC':
            $detail = [
                'ccfot_amt'       => $earn('ccfot_amt'),
                'ssv_acc_amt'     => $earn('ssv_acc_amt'),
                'punctuality_amt' => $earn('punctuality_amt'),
            ];
            break;
        case 'MR':
            // ps_amt ("Placement Compliance") now resolves correctly via the live
            // incentive_entries lookup — fixes the old unrecoverable-figure bug.
            $detail = [
                'ps_amt'       => $earn('ps_amt'),
                'dlink_amount' => $sheetVal('dlink_amount'), // no live pattern — always the saved figure
            ];
            break;
        case 'ST':
            $detail = [
                'stores_damage_amt'     => $earn('stores_damage_amt'),
                'rsqm_amt'              => $earn('rsqm_amt'),
                'punctuality_amt'       => $earn('punctuality_amt'),
                'loading_unloading_amt' => $earn('loading_unloading_amt'),
                'ccfot_amt'             => $earn('ccfot_amt'),
            ];
            break;
        case 'OFF':
            $detail = [
                'daily_incent'    => $earn('daily_incent'),
                'weekly_incent'   => $earn('weekly_incent'),
                'monthly_incent'  => $earn('monthly_incent'),
                'punctuality_amt' => $earn('punctuality_amt'),
            ];
            break;
    }
    // "Incentive" is the exact sum of this category's named detail components above —
    // not a plug/guess. The per-category sheet's DET columns are those very components,
    // so they always add up to this figure by construction. (For CC, att_inc_amt is
    // NOT part of this sum — see the note above; it's counted via Attendance Allowance.)
    $incentive = array_sum($detail);

    $total_earnings = $basic + $travel + $fuel + $mobile + $disc + $att_allow + $meal + $arrears + $other_earn + $incentive;

    // ── Deductions (generic across every category, same as print_payslips.php's DED_FIELDS) ──
    $good_short = $sheetVal('good_short');
    $cash_short = $sheetVal('cash_short');
    $welfare    = $sheetVal('welfare_amt');
    $w_loan     = $sheetVal('w_loan');
    $loan_ded   = $sheetVal('loan_ded');
    $excess_p   = $sheetVal('excess_p');
    $credit_r   = $sheetVal('credit_r');
    $retention  = $sheetVal('retention');
    $donations  = $sheetVal('donations');
    $w_soc      = $sheetVal('w_soc');
    $epf8       = $sheetVal('epf_emp');
    $other_ded  = sum_additional_json($row['additional_deductions'] ?? null);
    // Details behind the "Other Deductions" total — "desc: amount | desc: amount",
    // same shape/format sr_salary.php's own CSV export already uses for this,
    // so the line items are visible instead of only the lumped total.
    $other_ded_detail = detail_additional_json($row['additional_deductions'] ?? null);

    // Salary Advance: ALWAYS the live figure from salary_advances (falling back to the
    // employee profile's salary_advance column) — never the saved snapshot column,
    // exactly matching print_payslips.php.
    $sal_adv = $advance_map[$eid] ?? $emp['salary_advance'];
    $sal_adv = $sal_adv > 0 ? $sal_adv : 0;

    // PAYEE / Income Tax isn't tracked by the salary sheet builders at all — sourced
    // from the employee profile, exactly like print_payslips.php, so it now shows up
    // in this export too (the old version omitted it entirely).
    $payee = $emp['payee'] ?: $emp['income_tax'];
    $payee = $payee > 0 ? $payee : 0;

    $total_deductions = $sal_adv + $no_pay + $good_short + $cash_short + $welfare + $w_loan + $loan_ded
                       + $excess_p + $credit_r + $retention + $donations + $w_soc + $epf8 + $payee + $other_ded;

    $total_earnings   = round($total_earnings, 2);
    $total_deductions = round($total_deductions, 2);
    $net_salary       = round($total_earnings - $total_deductions, 2);

    // EPF (Employer 12%) / ETF (Employer 3%) are always CALCULATED off the
    // Basic Adjusted Salary — never read from a saved column — per the
    // company's own payroll formula.
    $epf_er = round($basic_adjusted * 0.12, 2);
    $etf_er = round($basic_adjusted * 0.03, 2);
    $gratuity  = $sheetVal('gratuity');
    $insurance = $sheetVal('insurance');
    $bonus     = $sheetVal('bonus');

    // Total Benefit = Total Earnings + EPF (12%) + ETF (3%) + Gratuity + Insurance.
    // Always calculated — never read from a saved "total_benefit" column.
    $total_benefit = round($total_earnings + $epf_er + $etf_er + $gratuity + $insurance, 2);

    $out = [
        'category'         => $cat,
        'company'          => $row['company_code'] ?? '',
        'emp_code'         => $row['employee_code'] ?? '',
        'epf_no'           => $row['epf_number'] ?? '',
        'name'             => $row['employee_name'] ?? '',
        'designation'      => $row['designation_name'] ?? '',
        'status'           => $row['status'] ?? '',
        'sheet_status'     => $row['sheet_status'] ?? 'Pending',
        'norm_days'        => $sheetVal('norm_days'),
        'days_worked'      => $sheetVal('att_count'),
        'att_rate'         => $sheetVal('att_rate'),
        'basic'            => $basic,
        // ── identity/meta fields only needed by the master "Salary Sheet" tab ──
        'employee_id'      => $eid,
        'date_of_join'     => $row['date_of_join'] ?? null,
        'payment_type'     => $row['ep_payment_type'] ?? '',
        'email'            => $row['ep_email'] ?? '',
        // ── ADDITIONS ──
        'travel'           => round($travel, 2),
        'fuel'             => round($fuel, 2),
        'incentive'        => round($incentive, 2),
        'disc'             => round($disc, 2),
        'att_allow'        => round($att_allow, 2),
        'mobile'           => round($mobile, 2),
        'meal'             => round($meal, 2),
        'arrears'          => round($arrears, 2),
        'other_earn'       => round($other_earn, 2),
        'other_earn_item1' => round($other_earn_item1, 2),
        'other_earn_item2' => round($other_earn_item2, 2),
        'total_earnings'   => $total_earnings,
        'total_earnings_2' => $total_earnings,
        // ── DEDUCTIONS ──
        'no_pay'             => round($no_pay, 2),
        'basic_adjusted'     => $basic_adjusted,
        'sal_adv'            => round($sal_adv, 2),
        'good_short'         => round($good_short, 2),
        'cash_short'         => round($cash_short, 2),
        // Welfare Deduction and Welfare Loan Deduction are kept SEPARATE — never
        // merged into a single combined figure, on either the per-category or
        // the master sheet.
        'welfare'            => round($welfare, 2),
        'w_loan'             => round($w_loan, 2),
        'loan_ded'           => round($loan_ded, 2),
        'excess_p'           => round($excess_p, 2),
        'credit_r'           => round($credit_r, 2),
        'retention'          => round($retention, 2),
        'donations'          => round($donations, 2),
        'w_soc'              => round($w_soc, 2),
        'epf8'               => round($epf8, 2),
        'payee'              => round($payee, 2),
        'other_ded'          => round($other_ded, 2),
        'other_ded_detail'   => $other_ded_detail,
        'total_deductions'   => $total_deductions,
        'total_deductions_2' => $total_deductions,
        'net_salary'         => $net_salary,
        // ── Employer cost / benefit — EPF/ETF/Total Benefit are always
        //    calculated (see above); Gratuity/Insurance/Bonus still come
        //    from the saved sheet, since there's no live source for those. ──
        'epf_er'        => $epf_er,
        'etf_er'        => $etf_er,
        'bonus'         => $bonus,
        'gratuity'      => $gratuity,
        'insurance'     => $insurance,
        'total_benefit' => $total_benefit,
    ];
    foreach ($detail as $k => $v) $out[$k] = round($v, 2);
    // CC's Attendance Incentive is still shown under its original detail key
    // on CC's own per-category sheet (for continuity with earlier exports),
    // but it is NOT part of $detail/$incentive above, so it is counted
    // exactly once towards Total Earnings — via Attendance Allowance.
    if ($cat === 'CC') $out['att_inc_amt'] = round($cc_att_incentive, 2);
    // "Special Incentive" on the master sheet is specifically the "other_incentive"
    // component (SR-only today) — kept apart so it never double-counts against
    // the main "Incentive" column. Every other category has none, so it's 0 there.
    $out['other_incentive_component'] = round($detail['other_incentive'] ?? 0, 2);
    return $out;
}

/** Resolves the SQL boolean fragment (on alias `e` = employees) that defines
 *  which employees CURRENTLY belong to a given category — this mirrors,
 *  field-for-field, the exact same category/designation matching logic every
 *  category's own salary page (sr_salary.php, cc_salary.php, acc_salary.php,
 *  mr_salary.php, st_salary.php, off_salary.php) uses to decide who it lists
 *  and saves in the first place: staff_category_id IN (category ids with
 *  this code) OR designation_id IN (designations under those categories).
 *
 *  WHY THIS EXISTS: salary_sheet_entries.category_code is only a SNAPSHOT of
 *  whatever value was on the employee's own linked staff_categories row at
 *  save time. If an employee was matched onto a category page purely via
 *  their DESIGNATION (their own staff_category_id points somewhere else, but
 *  their designation sits under that category), the snapshot ends up holding
 *  their own category's code — not the page's code — and a strict
 *  "category_code = 'SR'" (or even a case/whitespace-tolerant version of it)
 *  can then match zero rows for a category that was, in fact, generated and
 *  saved correctly. Since the underlying staff_categories/designations data
 *  isn't something that can be changed, this re-derives membership fresh
 *  from those tables on every export instead, so it always agrees with
 *  whatever each category page itself currently considers "this category's
 *  employees" — it can never drift out of sync with a stale saved string.
 *  Used as an OR alongside the plain category_code match (never instead of
 *  it), so it only ever ADDS rows a strict match would have missed. */
function category_membership_sql($conn, $cat) {
    $catE = mysqli_real_escape_string($conn, $cat);
    $cat_res = @mysqli_query($conn, "SELECT id FROM staff_categories WHERE UPPER(TRIM(category_code)) = UPPER('$catE') AND active=1");
    $cat_ids = [];
    if ($cat_res) while ($r = mysqli_fetch_assoc($cat_res)) $cat_ids[] = (int)$r['id'];

    $desig_ids = [];
    if (!empty($cat_ids)) {
        $cat_in = implode(',', $cat_ids);
        $desig_res = @mysqli_query($conn, "SELECT id FROM designations WHERE staff_category_id IN ($cat_in) AND active=1");
        if ($desig_res) while ($r = mysqli_fetch_assoc($desig_res)) $desig_ids[] = (int)$r['id'];
    }

    $parts = [];
    if (!empty($cat_ids))   $parts[] = "e.staff_category_id IN (" . implode(',', $cat_ids) . ")";
    if (!empty($desig_ids)) $parts[] = "e.designation_id IN (" . implode(',', $desig_ids) . ")";
    // Belt-and-braces fallback: a direct case-insensitive check against the
    // employee's OWN current category row, in case the tables above ever
    // come back empty for some other reason. Never the sole match path.
    $parts[] = "EXISTS (SELECT 1 FROM staff_categories sc2 WHERE sc2.id = e.staff_category_id AND UPPER(TRIM(sc2.category_code)) = UPPER('$catE'))";

    return "(" . implode(' OR ', $parts) . ")";
}

/** Fetches raw sheet+profile rows for one category (no live overrides applied yet). */
function fetch_category_raw_rows($conn, $pid, $cat, $period_start, $emp_profile_select) {
    $excl_sql = excluded_employee_sql($period_start);
    if ($cat === 'ST' || $cat === 'OFF') {
        $table = $cat === 'ST' ? 'st_salary_sheet_entries' : 'off_salary_sheet_entries';
        $sql = "SELECT sse.*, $emp_profile_select FROM $table sse
                JOIN employees e ON e.id = sse.employee_id
                WHERE sse.payroll_period_id = $pid AND e.active = 1 AND NOT $excl_sql
                ORDER BY sse.company_code, sse.employee_code";
    } else {
        $catE = mysqli_real_escape_string($conn, $cat);
        // Category match is the saved-snapshot column (case/whitespace-
        // tolerant) OR the live, re-derived category/designation membership
        // — see category_membership_sql() above. Matching on EITHER means a
        // row that was saved correctly is never dropped just because its
        // own category_code snapshot happens to differ from what the
        // category's own page currently resolves to.
        $member_sql = category_membership_sql($conn, $cat);
        $sql = "SELECT sse.*, $emp_profile_select FROM salary_sheet_entries sse
                JOIN employees e ON e.id = sse.employee_id
                WHERE sse.payroll_period_id = $pid
                  AND (UPPER(TRIM(sse.category_code)) = UPPER('$catE') OR $member_sql)
                  AND e.active = 1 AND NOT $excl_sql
                ORDER BY sse.company_code, sse.employee_code";
    }
    $res = mysqli_query($conn, $sql);
    $rows = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    return $rows;
}

/** Orchestrates the full fetch for a set of categories: raw rows → gather every
 *  employee id involved → fetch live-value sources ONCE for that whole set →
 *  map every row through map_category_row(). Used by both the live preview and
 *  the actual export so they always agree with each other (and with the payslip). */
function load_export_data($conn, $pid, array $cats, $period_start) {
    $period_end = date('Y-m-t', strtotime($period_start));
    $emp_profile_select = employee_profile_select_sql($conn);

    $raw = [];
    foreach ($cats as $cat) $raw[$cat] = fetch_category_raw_rows($conn, $pid, $cat, $period_start, $emp_profile_select);

    $emp_ids = [];
    foreach ($raw as $rows) foreach ($rows as $r) $emp_ids[(int)$r['employee_id']] = true;
    $emp_ids = array_keys($emp_ids);

    $inc_entries = fetch_incentive_entries($conn, $pid, $emp_ids);
    $sr_fuel_map = fetch_sr_fuel_map($conn, $pid, $emp_ids);
    $advance_map = fetch_advance_map($conn, $emp_ids, $period_start, $period_end);

    $out = [];
    foreach ($cats as $cat) {
        $out[$cat] = [];
        foreach ($raw[$cat] as $r) $out[$cat][] = map_category_row($r, $cat, $inc_entries, $sr_fuel_map, $advance_map);
    }
    return $out;
}

/** Count of rows that exist for the period+category but were excluded for being resigned/terminated. */
function count_excluded($conn, $pid, $cat, $period_start) {
    $excl_sql = excluded_employee_sql($period_start);
    if ($cat === 'ST' || $cat === 'OFF') {
        $table = $cat === 'ST' ? 'st_salary_sheet_entries' : 'off_salary_sheet_entries';
        $sql = "SELECT COUNT(*) c FROM $table sse
                JOIN employees e ON e.id = sse.employee_id
                WHERE sse.payroll_period_id = $pid AND e.active = 1 AND $excl_sql";
    } else {
        $catE = mysqli_real_escape_string($conn, $cat);
        // Same "saved snapshot OR live re-derived membership" match as
        // fetch_category_raw_rows() above, for the same reason.
        $member_sql = category_membership_sql($conn, $cat);
        $sql = "SELECT COUNT(*) c FROM salary_sheet_entries sse
                JOIN employees e ON e.id = sse.employee_id
                WHERE sse.payroll_period_id = $pid
                  AND (UPPER(TRIM(sse.category_code)) = UPPER('$catE') OR $member_sql)
                  AND e.active = 1 AND $excl_sql";
    }
    $r = mysqli_fetch_assoc(mysqli_query($conn, $sql));
    return (int)($r['c'] ?? 0);
}

/** Diagnoses WHY a category shows 0 employees, instead of leaving "0" as a
 *  dead end. Distinguishes between the different things that all look
 *  identical as a bare row count:
 *   1. NOTHING has been saved for this payroll period at all yet (no
 *      category's sheet has ever been generated/saved for it).
 *   2. Sheet data HAS been saved for this period, but none of it matches
 *      this category — either that category's own page was never
 *      generated/saved for this period, or the matching employees'
 *      staff_category_id / designation_id no longer resolves to this
 *      category (see category_membership_sql()).
 *   3. Rows DO match this category for this period, but every one of them
 *      is currently filtered out by e.active = 0, or by the resigned/
 *      terminated exclusion (excluded_employee_sql()) — i.e. their status
 *      changed AFTER the sheet was saved.
 *  Returns null when the category actually has rows (nothing to explain). */
function category_zero_reason($conn, $pid, $cat, $period_start) {
    $catE     = mysqli_real_escape_string($conn, $cat);
    $excl_sql = excluded_employee_sql($period_start);

    if ($cat === 'ST' || $cat === 'OFF') {
        $table = $cat === 'ST' ? 'st_salary_sheet_entries' : 'off_salary_sheet_entries';
        $rAny = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM $table WHERE payroll_period_id=$pid"));
        $any  = (int)($rAny['c'] ?? 0);
        if ($any === 0) {
            return "No $cat salary sheet has been generated/saved for this payroll period yet — open the $cat page, load this period, and save it there first.";
        }
        return "$any row(s) exist for $cat this period, but every one is currently inactive or excluded (resigned/terminated before this month) — check those employees' active status / exit date.";
    }

    // Step 1: has ANYTHING been saved into salary_sheet_entries for this period at all?
    $rTotal   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM salary_sheet_entries WHERE payroll_period_id=$pid"));
    $totalAny = (int)($rTotal['c'] ?? 0);
    if ($totalAny === 0) {
        return "No salary sheet data has been saved for this payroll period at all yet (no category) — generate and save at least the $cat sheet on its own page first.";
    }

    // Step 2: of what IS saved for this period, does anything match this
    // category — either by its saved category_code snapshot, or by the
    // employee's CURRENT staff category/designation (category_membership_sql)?
    $member_sql = category_membership_sql($conn, $cat);
    $rMatch = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM salary_sheet_entries sse
        JOIN employees e ON e.id = sse.employee_id
        WHERE sse.payroll_period_id=$pid AND (UPPER(TRIM(sse.category_code)) = UPPER('$catE') OR $member_sql)"));
    $matched = (int)($rMatch['c'] ?? 0);
    if ($matched === 0) {
        return "Other categories have saved sheets for this period, but none of that data matches $cat. Either the $cat sheet itself hasn't been generated/saved for this period yet, or these employees' Staff Category / Designation assignment doesn't currently resolve to $cat — check the $cat page for this period and the employees' Staff Category / Designation setup. NOTE: the $cat page's own \"sheet saved\" indicator counts ALL rows in salary_sheet_entries for this period with no category filter, so it can show \"saved\" here even when zero $cat rows actually exist (a different category being saved is enough to trigger it) — this message, not that badge, reflects what's actually saved for $cat.";
    }

    // Step 3: rows DO match the category — so they're being filtered out
    // by active/exclusion status.
    return "$matched row(s) matching $cat exist for this period, but every one is currently inactive or excluded (resigned/terminated before this month) — check those employees' active status / exit date.";
}

// =========================================================================
//  MASTER "Salary Sheet" COLUMN SPEC — column-for-column match of
//  Salary_Yelo_Logistics_May_2026_Checked.xlsx's own "Salary Sheet" tab.
//  group: '' none | 'ADD' additions band | 'DED' deductions band
// =========================================================================
$MASTER_COLUMNS = [
    ['key'=>'epf_no',       'label'=>'EPF No',              'group'=>'', 'type'=>'s', 'w'=>9],
    ['key'=>'name',         'label'=>'Name',                'group'=>'', 'type'=>'s', 'w'=>32],
    ['key'=>'designation',  'label'=>'Designation',         'group'=>'', 'type'=>'s', 'w'=>22],
    ['key'=>'emp_id',       'label'=>'Employee ID',         'group'=>'', 'type'=>'n', 'w'=>10],
    ['key'=>'doj',          'label'=>'Date of Join',        'group'=>'', 'type'=>'s', 'w'=>12],
    ['key'=>'category',     'label'=>'Category',            'group'=>'', 'type'=>'s', 'w'=>10],
    ['key'=>'status',       'label'=>'Status',              'group'=>'', 'type'=>'s', 'w'=>10],
    ['key'=>'emp_code',     'label'=>'Employee Code',       'group'=>'', 'type'=>'s', 'w'=>12],
    ['key'=>'norm_days',    'label'=>'Norm Days',           'group'=>'', 'type'=>'n', 'w'=>9],
    ['key'=>'days_worked',  'label'=>'Days Wrkd',           'group'=>'', 'type'=>'n', 'w'=>9],
    ['key'=>'basic',        'label'=>'Basic Salary',        'group'=>'', 'type'=>'n', 'w'=>13],
    ['key'=>'no_pay',       'label'=>'No Pay',              'group'=>'', 'type'=>'n', 'w'=>11],
    ['key'=>'basic_adjusted','label'=>'Basic Adjusted Salary','group'=>'', 'type'=>'n', 'w'=>16],
    ['key'=>'total_earnings',  'label'=>'Total Earnings',   'group'=>'', 'type'=>'n', 'w'=>14],
    ['key'=>'total_deductions','label'=>'Total Deductions', 'group'=>'', 'type'=>'n', 'w'=>14],
    ['key'=>'net_salary',   'label'=>'Net Salary',          'group'=>'', 'type'=>'n', 'w'=>13],
    ['key'=>'epf_er',       'label'=>'12% EPF ',            'group'=>'', 'type'=>'n', 'w'=>11],
    ['key'=>'etf_er',       'label'=>'3% ETF ',             'group'=>'', 'type'=>'n', 'w'=>10],
    ['key'=>'bonus_prov',   'label'=>'Bonus',               'group'=>'', 'type'=>'n', 'w'=>11],
    ['key'=>'gratuity',     'label'=>'Gratuity',            'group'=>'', 'type'=>'n', 'w'=>11],
    ['key'=>'insurance',    'label'=>'Insurance',           'group'=>'', 'type'=>'n', 'w'=>10],
    ['key'=>'total_benefit','label'=>'Total Benefit',       'group'=>'', 'type'=>'n', 'w'=>13],
    // ── ADDITIONS ──
    ['key'=>'travel',            'label'=>'Travelling Allowance', 'group'=>'ADD', 'type'=>'n', 'w'=>14],
    ['key'=>'fuel',              'label'=>'Fuel Allowance',       'group'=>'ADD', 'type'=>'n', 'w'=>12],
    ['key'=>'incentive',         'label'=>'Incentive',            'group'=>'ADD', 'type'=>'n', 'w'=>13],
    ['key'=>'disc',              'label'=>'Discretionary Support','group'=>'ADD', 'type'=>'n', 'w'=>16],
    ['key'=>'att_allow',         'label'=>'Attendance Allowance',  'group'=>'ADD', 'type'=>'n', 'w'=>15],
    ['key'=>'mobile',            'label'=>'Mobile Data Allowance', 'group'=>'ADD', 'type'=>'n', 'w'=>15],
    ['key'=>'meal',              'label'=>'Daily Meal Allowance',  'group'=>'ADD', 'type'=>'n', 'w'=>14],
    ['key'=>'special_incentive', 'label'=>'Special Incentive',    'group'=>'ADD', 'type'=>'n', 'w'=>13],
    ['key'=>'arrears',           'label'=>'Arrears  Salary',      'group'=>'ADD', 'type'=>'n', 'w'=>12],
    ['key'=>'bonus_earn',        'label'=>'Bonus',                'group'=>'ADD', 'type'=>'n', 'w'=>10],
    ['key'=>'other_allow1',      'label'=>'Other Allowance 1',    'group'=>'ADD', 'type'=>'n', 'w'=>13],
    ['key'=>'other_allow2',      'label'=>'Other Allowance 2',    'group'=>'ADD', 'type'=>'n', 'w'=>13],
    ['key'=>'total_earnings_2',  'label'=>'Total Earnings',       'group'=>'ADD', 'type'=>'n', 'w'=>14],
    // ── DEDUCTIONS ──
    ['key'=>'sal_adv',           'label'=>'Salary Advance',           'group'=>'DED', 'type'=>'n', 'w'=>12],
    ['key'=>'late_fine',         'label'=>'Late Fine',                'group'=>'DED', 'type'=>'n', 'w'=>10],
    ['key'=>'good_short',        'label'=>'Goods Short',              'group'=>'DED', 'type'=>'n', 'w'=>11],
    ['key'=>'cash_short',        'label'=>'Cash Short',               'group'=>'DED', 'type'=>'n', 'w'=>11],
    // Welfare Deduction and Welfare Loan Deduction shown SEPARATELY (never merged).
    ['key'=>'welfare',           'label'=>'Welfare Deduction',        'group'=>'DED', 'type'=>'n', 'w'=>13],
    ['key'=>'w_loan',            'label'=>'Welfare Loan Deduction',   'group'=>'DED', 'type'=>'n', 'w'=>15],
    ['key'=>'loan_ded',          'label'=>'Loan Deduction',           'group'=>'DED', 'type'=>'n', 'w'=>12],
    ['key'=>'excess_p',          'label'=>'Excess Payment Deduction', 'group'=>'DED', 'type'=>'n', 'w'=>16],
    ['key'=>'credit_r',          'label'=>'Credit Recovery',          'group'=>'DED', 'type'=>'n', 'w'=>12],
    ['key'=>'other_ded_detail',  'label'=>'Other Deductions Detail',  'group'=>'DED', 'type'=>'s', 'w'=>30],
    ['key'=>'other_ded',         'label'=>'Other Deductions',         'group'=>'DED', 'type'=>'n', 'w'=>13],
    ['key'=>'retention',         'label'=>'Retention',                'group'=>'DED', 'type'=>'n', 'w'=>10],
    ['key'=>'donations',         'label'=>'Donations',                'group'=>'DED', 'type'=>'n', 'w'=>10],
    ['key'=>'w_soc',             'label'=>'Welfare Society',          'group'=>'DED', 'type'=>'n', 'w'=>12],
    ['key'=>'payee',             'label'=>'PAYEE',                    'group'=>'DED', 'type'=>'n', 'w'=>10],
    ['key'=>'epf8',              'label'=>'EPF 8%',                   'group'=>'DED', 'type'=>'n', 'w'=>10],
    ['key'=>'total_deductions_2','label'=>'Total Deductions',         'group'=>'DED', 'type'=>'n', 'w'=>14],
    // ── trailing meta columns ──
    ['key'=>'month',         'label'=>'Month',         'group'=>'', 'type'=>'n', 'w'=>8],
    ['key'=>'payroll_month', 'label'=>'Payroll Month', 'group'=>'', 'type'=>'s', 'w'=>14],
];

/** Maps one map_category_row() result (plus period info) into the master
 *  "Salary Sheet" column set above. Every figure here is either taken
 *  straight from map_category_row()'s already-reconciled output, or is a
 *  simple, documented regrouping of two of its fields — nothing is invented.
 *  Welfare Deduction and Welfare Loan Deduction are kept as separate columns
 *  here too (never merged into a single figure).
 *  A few of the checked workbook's columns (Before 7.45, Late Fine, a
 *  separate cash "Bonus" paid as an earning) have no data source anywhere
 *  in the system yet and are always exported as 0 — flagged inline below. */
function map_master_row($r, $period) {
    return [
        'epf_no'       => $r['epf_no'],
        'name'         => $r['name'],
        'designation'  => $r['designation'],
        'emp_id'       => $r['employee_id'],
        'doj'          => !empty($r['date_of_join']) ? date('Y-m-d', strtotime($r['date_of_join'])) : '',
        'category'     => $r['category'],
        'status'       => $r['status'],
        'emp_code'     => $r['emp_code'],
        'norm_days'    => $r['norm_days'],
        'days_worked'  => $r['days_worked'],
        'basic'        => $r['basic'],
        'no_pay'       => $r['no_pay'],
        'basic_adjusted' => $r['basic_adjusted'],
        'total_earnings'   => $r['total_earnings'],
        'total_deductions' => $r['total_deductions'],
        'net_salary'       => $r['net_salary'],
        'epf_er'       => $r['epf_er'],
        'etf_er'       => $r['etf_er'],
        'bonus_prov'   => $r['bonus'],
        'gratuity'     => $r['gratuity'],
        'insurance'    => $r['insurance'],
        'total_benefit'=> $r['total_benefit'],
        // ADDITIONS
        'travel'            => $r['travel'],
        'fuel'              => $r['fuel'],
        // "Incentive" here excludes the "other_incentive" component, which is
        // broken out separately as "Special Incentive" below — the two always
        // add back up to map_category_row()'s full 'incentive' figure.
        'incentive'         => round($r['incentive'] - ($r['other_incentive_component'] ?? 0), 2),
        'disc'              => $r['disc'],
        'att_allow'         => $r['att_allow'],
        'mobile'            => $r['mobile'],
        'meal'              => $r['meal'],
        'special_incentive' => $r['other_incentive_component'] ?? 0,
        'arrears'           => $r['arrears'],
        'bonus_earn'        => 0, // no distinct "cash bonus paid" data source; employer bonus provision is shown above instead
        'other_allow1'      => $r['other_earn_item1'] ?? 0,
        'other_allow2'      => $r['other_earn_item2'] ?? 0,
        'total_earnings_2'  => $r['total_earnings'],
        // DEDUCTIONS
        'sal_adv'    => $r['sal_adv'],
        'late_fine'  => 0, // no separate late-fine tracking anywhere in the system yet
        'good_short' => $r['good_short'],
        'cash_short' => $r['cash_short'],
        // Welfare Deduction and Welfare Loan Deduction — SEPARATE lines, not combined.
        'welfare'    => $r['welfare'],
        'w_loan'     => $r['w_loan'],
        'loan_ded'   => $r['loan_ded'],
        'excess_p'   => $r['excess_p'],
        'credit_r'   => $r['credit_r'],
        'other_ded_detail' => $r['other_ded_detail'] ?? '',
        'other_ded'  => $r['other_ded'],
        'retention'  => $r['retention'],
        'donations'  => $r['donations'],
        'w_soc'      => $r['w_soc'],
        'payee'      => $r['payee'],
        'epf8'       => $r['epf8'],
        'total_deductions_2' => $r['total_deductions'],
        // meta
        'month'         => (int)$period['month'],
        'payroll_month' => period_label($period),
    ];
}

/** Category display labels used in the "Sub Total" rows are omitted — the
 *  checked workbook's own Sub Total rows carry no category name, just the
 *  words "Sub Total", relying on the block of rows directly above it. */
const MASTER_LABEL_MERGE_COLS = 12; // EPF No .. Basic Adjusted Salary (identity/attendance columns) get merged for Sub Total / TOTAL labels

/** Given a $COLUMNS spec and the actual data rows that will be written under
 *  it on ONE sheet, drops any Earnings/Deductions/Incentive-detail column
 *  ('ADD' / 'DED' / 'DET' group) whose value is zero/blank for EVERY single
 *  row on that sheet — so a component a category never uses (e.g. "Locus
 *  Admin" incentive on a sheet with no CC-style admin bonus) doesn't render
 *  as a big empty column full of zeros.
 *
 *  Identity/meta columns (group === '', e.g. Name, EPF No, Basic Salary,
 *  Total Earnings/Deductions/Net Salary, Month, E mail) are ALWAYS kept
 *  regardless of emptiness — hiding those would break row identification.
 *
 *  Filtering is per-sheet: pass only the rows that will actually be printed
 *  on that specific sheet (a single category's rows for a per-category tab,
 *  or the combined rows for every included category for the master tab),
 *  so the master sheet and each category's own tab can end up showing a
 *  different — but each individually minimal — set of columns.
 *
 *  If $rows is empty (nothing to check against), nothing is dropped: "no
 *  rows yet" is not the same as "always zero", and an empty sheet should
 *  still show its full intended header for clarity. */
function filter_empty_columns(array $COLUMNS, array $rows) {
    if (empty($rows)) return $COLUMNS;
    $out = [];
    foreach ($COLUMNS as $c) {
        if ($c['group'] === '') { $out[] = $c; continue; } // identity/meta — always kept
        $hasValue = false;
        foreach ($rows as $r) {
            $val = $r[$c['key']] ?? null;
            if ($c['type'] === 'n') {
                if (round(floatval($val), 2) != 0.0) { $hasValue = true; break; }
            } else {
                if (trim((string)$val) !== '') { $hasValue = true; break; }
            }
        }
        if ($hasValue) $out[] = $c;
    }
    return $out;
}

// ── Column spec shared by the per-category sheets (Sheet 3+) ────────────
// Layout: identity info, then Basic Salary → No Pay → Basic Adjusted Salary
// right together, then a quick-glance SUMMARY block (Total Earnings / Total
// Deductions / Net Salary / Benefits), THEN the full ADDITIONS breakdown
// ending in its own repeated Total Earnings column, THEN the full
// DEDUCTIONS breakdown ending in its own repeated Total Deductions column.
// The two repeated totals use distinct internal keys (total_earnings_2 /
// total_deductions_2) so the TOTAL row at the bottom of each sheet doesn't
// double-count them; both keys are populated with the same value (SUM of
// the itemized rows, per map_category_row()).
// group: '' none | 'ADD' additions band | 'DED' deductions band
$COLUMNS = [
    ['key'=>'category',     'label'=>'Category',               'group'=>'', 'type'=>'s', 'w'=>10],
    ['key'=>'company',      'label'=>'Company',                'group'=>'', 'type'=>'s', 'w'=>10],
    ['key'=>'emp_code',     'label'=>'Employee Code',          'group'=>'', 'type'=>'s', 'w'=>14],
    ['key'=>'epf_no',       'label'=>'EPF No',                 'group'=>'', 'type'=>'s', 'w'=>10],
    ['key'=>'name',         'label'=>'Name',                   'group'=>'', 'type'=>'s', 'w'=>28],
    ['key'=>'designation',  'label'=>'Designation',            'group'=>'', 'type'=>'s', 'w'=>20],
    ['key'=>'status',       'label'=>'Status',                 'group'=>'', 'type'=>'s', 'w'=>12],
    ['key'=>'sheet_status', 'label'=>'Sheet Status',           'group'=>'', 'type'=>'s', 'w'=>12],
    ['key'=>'norm_days',    'label'=>'Norm Days',              'group'=>'', 'type'=>'n', 'w'=>10],
    ['key'=>'days_worked',  'label'=>'Days Worked',            'group'=>'', 'type'=>'n', 'w'=>11],
    ['key'=>'att_rate',     'label'=>'Att Rate %',             'group'=>'', 'type'=>'n', 'w'=>10],
    ['key'=>'basic',        'label'=>'Basic Salary',           'group'=>'', 'type'=>'n', 'w'=>13],
    ['key'=>'no_pay',       'label'=>'No Pay',                 'group'=>'', 'type'=>'n', 'w'=>11],
    ['key'=>'basic_adjusted','label'=>'Basic Adjusted Salary', 'group'=>'', 'type'=>'n', 'w'=>16],
    // ── SUMMARY (Total Earnings/Deductions/Net/Benefits right after Basic Adjusted Salary) ──
    ['key'=>'total_earnings',  'label'=>'Total Earnings',      'group'=>'', 'type'=>'n', 'w'=>14],
    ['key'=>'total_deductions','label'=>'Total Deductions',    'group'=>'', 'type'=>'n', 'w'=>14],
    ['key'=>'net_salary',      'label'=>'Net Salary',          'group'=>'', 'type'=>'n', 'w'=>13],
    ['key'=>'epf_er',       'label'=>'EPF Employer 12%',       'group'=>'', 'type'=>'n', 'w'=>14],
    ['key'=>'etf_er',       'label'=>'ETF Employer 3%',        'group'=>'', 'type'=>'n', 'w'=>13],
    ['key'=>'bonus',        'label'=>'Bonus Provision',        'group'=>'', 'type'=>'n', 'w'=>13],
    ['key'=>'gratuity',     'label'=>'Gratuity Provision',     'group'=>'', 'type'=>'n', 'w'=>14],
    ['key'=>'insurance',    'label'=>'Insurance',              'group'=>'', 'type'=>'n', 'w'=>11],
    ['key'=>'total_benefit','label'=>'Total Benefit',          'group'=>'', 'type'=>'n', 'w'=>13],
    // ── ADDITIONS breakdown (ends with its own repeated Total Earnings) ──
    ['key'=>'travel',       'label'=>'Travelling Reimb.',      'group'=>'ADD', 'type'=>'n', 'w'=>14],
    ['key'=>'fuel',         'label'=>'Fuel Reimb.',            'group'=>'ADD', 'type'=>'n', 'w'=>12],
    ['key'=>'incentive',    'label'=>'Incentive (Category Total)','group'=>'ADD', 'type'=>'n', 'w'=>17],
    ['key'=>'disc',         'label'=>'Discretionary Support',  'group'=>'ADD', 'type'=>'n', 'w'=>16],
    ['key'=>'att_allow',    'label'=>'Attendance Allowance',   'group'=>'ADD', 'type'=>'n', 'w'=>15],
    ['key'=>'mobile',       'label'=>'Mobile Reimbursement',   'group'=>'ADD', 'type'=>'n', 'w'=>15],
    ['key'=>'meal',         'label'=>'Meal Reimbursement',     'group'=>'ADD', 'type'=>'n', 'w'=>14],
    ['key'=>'arrears',      'label'=>'Arrears Salary',         'group'=>'ADD', 'type'=>'n', 'w'=>12],
    ['key'=>'other_earn',   'label'=>'Other Allowance',        'group'=>'ADD', 'type'=>'n', 'w'=>13],
    ['key'=>'total_earnings_2','label'=>'Total Earnings',      'group'=>'ADD', 'type'=>'n', 'w'=>14],
    // ── DEDUCTIONS breakdown (ends with its own repeated Total Deductions) ──
    ['key'=>'sal_adv',      'label'=>'Salary Advance',         'group'=>'DED', 'type'=>'n', 'w'=>12],
    ['key'=>'good_short',   'label'=>'Goods Short',            'group'=>'DED', 'type'=>'n', 'w'=>11],
    ['key'=>'cash_short',   'label'=>'Cash Short',             'group'=>'DED', 'type'=>'n', 'w'=>11],
    // Welfare Deduction and Welfare Loan Deduction shown SEPARATELY (never merged into one total).
    ['key'=>'welfare',      'label'=>'Welfare Deduction',      'group'=>'DED', 'type'=>'n', 'w'=>13],
    ['key'=>'w_loan',       'label'=>'Welfare Loan Deduction', 'group'=>'DED', 'type'=>'n', 'w'=>15],
    ['key'=>'loan_ded',     'label'=>'Loan Deduction',         'group'=>'DED', 'type'=>'n', 'w'=>12],
    ['key'=>'excess_p',     'label'=>'Excess Payment',         'group'=>'DED', 'type'=>'n', 'w'=>12],
    ['key'=>'credit_r',     'label'=>'Credit Recovery',        'group'=>'DED', 'type'=>'n', 'w'=>12],
    ['key'=>'retention',    'label'=>'Retention',              'group'=>'DED', 'type'=>'n', 'w'=>10],
    ['key'=>'donations',    'label'=>'Donations',              'group'=>'DED', 'type'=>'n', 'w'=>10],
    ['key'=>'w_soc',        'label'=>'Welfare Society',        'group'=>'DED', 'type'=>'n', 'w'=>12],
    ['key'=>'epf8',         'label'=>'EPF Employee',           'group'=>'DED', 'type'=>'n', 'w'=>10],
    ['key'=>'payee',        'label'=>'PAYEE (Income Tax)',     'group'=>'DED', 'type'=>'n', 'w'=>14],
    ['key'=>'other_ded_detail','label'=>'Other Deductions Detail','group'=>'DED', 'type'=>'s', 'w'=>30],
    ['key'=>'other_ded',    'label'=>'Other Deductions',       'group'=>'DED', 'type'=>'n', 'w'=>13],
    ['key'=>'total_deductions_2','label'=>'Total Deductions',  'group'=>'DED', 'type'=>'n', 'w'=>14],
];

// ── Category-specific detail columns (per-category sheets only) ─────────
// These are the exact incentive fields live_earn_value() resolves for that
// category (identical to print_payslips.php's per-category incentive
// components) — their sum equals the 'incentive' column above by
// construction, so nothing is hidden or guessed at.
// NOTE — CC: "Attendance Incentive" (att_inc_amt) is kept here for
// continuity/visibility on CC's own sheet, but it is NOT summed into the
// 'incentive' column above — it's counted once via the Attendance Allowance
// column instead (see map_category_row()).
$CATEGORY_DETAIL_COLUMNS = [
    'SR' => [
        ['key'=>'ssv',             'label'=>'Secondary Sales Value', 'group'=>'DET', 'type'=>'n', 'w'=>15],
        ['key'=>'ps_amt',          'label'=>'PS Compliance',         'group'=>'DET', 'type'=>'n', 'w'=>12],
        ['key'=>'eco_amt',         'label'=>'ECO Incentive',         'group'=>'DET', 'type'=>'n', 'w'=>12],
        ['key'=>'bp_amt',          'label'=>'BP Incentive',          'group'=>'DET', 'type'=>'n', 'w'=>12],
        ['key'=>'assort_amt',      'label'=>'Total Assortment',      'group'=>'DET', 'type'=>'n', 'w'=>13],
        ['key'=>'other_incentive', 'label'=>'Other Incentive',       'group'=>'DET', 'type'=>'n', 'w'=>13],
    ],
    'CC' => [
        ['key'=>'ccfot_amt',       'label'=>'CCFOT Incentive',        'group'=>'DET', 'type'=>'n', 'w'=>13],
        ['key'=>'credit_mgt_amt',  'label'=>'Credit Mgt Incentive',   'group'=>'DET', 'type'=>'n', 'w'=>15],
        ['key'=>'payee_chq_amt',   'label'=>'Payee Chq Incentive',    'group'=>'DET', 'type'=>'n', 'w'=>14],
        ['key'=>'ssv_cc_amt',      'label'=>'SSV Incentive',          'group'=>'DET', 'type'=>'n', 'w'=>12],
        ['key'=>'att_inc_amt',     'label'=>'Attendance Incentive (see Attendance Allowance)', 'group'=>'DET', 'type'=>'n', 'w'=>15],
        ['key'=>'locus_admin_amt', 'label'=>'Locus Admin',            'group'=>'DET', 'type'=>'n', 'w'=>12],
    ],
    'ACC' => [
        ['key'=>'ccfot_amt',       'label'=>'CCFOT Incentive',        'group'=>'DET', 'type'=>'n', 'w'=>13],
        ['key'=>'ssv_acc_amt',     'label'=>'SSV Incentive',          'group'=>'DET', 'type'=>'n', 'w'=>12],
        ['key'=>'punctuality_amt', 'label'=>'Punctuality Incentive',  'group'=>'DET', 'type'=>'n', 'w'=>15],
    ],
    'MR' => [
        // Now resolved live via incentive_entries — previously undocumented/unrecoverable
        // due to a bug in mr_salary.php's own save handler; fixed for this export.
        ['key'=>'ps_amt',          'label'=>'Placement Compliance',   'group'=>'DET', 'type'=>'n', 'w'=>16],
        ['key'=>'dlink_amount',    'label'=>'D-Link Allowance',       'group'=>'DET', 'type'=>'n', 'w'=>13],
    ],
    'ST' => [
        ['key'=>'stores_damage_amt',    'label'=>'Stores Damage Incentive','group'=>'DET', 'type'=>'n', 'w'=>17],
        ['key'=>'rsqm_amt',             'label'=>'RSQM Incentive',         'group'=>'DET', 'type'=>'n', 'w'=>12],
        ['key'=>'punctuality_amt',      'label'=>'Punctuality Incentive',  'group'=>'DET', 'type'=>'n', 'w'=>15],
        ['key'=>'loading_unloading_amt','label'=>'Loading/Unloading',      'group'=>'DET', 'type'=>'n', 'w'=>14],
        ['key'=>'ccfot_amt',            'label'=>'CCFOT Incentive',        'group'=>'DET', 'type'=>'n', 'w'=>13],
    ],
    'OFF' => [
        ['key'=>'daily_incent',    'label'=>'Daily Incentive',        'group'=>'DET', 'type'=>'n', 'w'=>12],
        ['key'=>'weekly_incent',   'label'=>'Weekly Incentive',       'group'=>'DET', 'type'=>'n', 'w'=>13],
        ['key'=>'monthly_incent',  'label'=>'Monthly Incentive',      'group'=>'DET', 'type'=>'n', 'w'=>14],
        ['key'=>'punctuality_amt', 'label'=>'Punctuality Incentive',  'group'=>'DET', 'type'=>'n', 'w'=>15],
    ],
];

// =========================================================================
//  MINI XLSX WRITER — dependency-free .xlsx builder (uses only ZipArchive)
// =========================================================================
class MiniXLSXWriter {
    private $sheets = [];

    const STYLE_DEFAULT   = 0;
    const STYLE_TITLE      = 1; // bold 14
    const STYLE_HEADER     = 2; // bold white on dark fill
    const STYLE_NUM        = 3; // #,##0.00
    const STYLE_NUM_BOLD   = 4; // #,##0.00 bold (totals)
    const STYLE_TEXT_BOLD  = 5; // bold text, no fill (totals label)
    const STYLE_BAND       = 6; // bold text on light fill (ADDITIONS/DEDUCTIONS band)
    const STYLE_SUBTOTAL   = 7; // #,##0.00 bold on faint fill (per-category Sub Total row)
    const STYLE_SUBTOTAL_TEXT = 8; // bold text on faint fill (Sub Total label)

    // ── Master "Salary Sheet" tab styles — reproduce Salary_Yelo_Logistics_
    //    May_2026_Checked.xlsx's own look exactly: Verdana titles, thin-bordered
    //    centered headers (no fill, not bold), dotted-bordered data cells, and
    //    Sub Total/TOTAL rows marked with a thin-top/double-bottom border
    //    (also not bold) rather than a fill color — matching that workbook
    //    cell-for-cell rather than the bold/fill styling used elsewhere in
    //    this file for the per-category sheets. ──
    const STYLE_M_TITLE1      = 9;  // Verdana 16, centered — company name
    const STYLE_M_TITLE2      = 10; // Verdana 10, left — "PAYROLL FOR THE MONTH OF ..."
    const STYLE_M_HEADER      = 11; // Calibri 10, center+distributed, thin border all sides, no fill, not bold
    const STYLE_M_DATA_TEXT   = 12; // Calibri 10, dotted border all sides
    const STYLE_M_DATA_NUM    = 13; // Calibri 10, dotted border all sides, accounting number format
    const STYLE_M_SUBTOTAL_LABEL = 14; // Calibri 10, thin border all sides, centered (Sub Total / TOTAL label)
    const STYLE_M_SUBTOTAL_NUM   = 15; // Calibri 10, thin-top/double-bottom/thin-sides border, accounting number format

    public function addSheet($name) {
        $this->sheets[] = ['name' => $this->sanitizeName($name), 'rows' => [], 'merges' => [], 'widths' => [], 'freeze' => 0];
        return count($this->sheets) - 1;
    }
    private function sanitizeName($name) {
        $name = preg_replace('/[\\\\\/\?\*\[\]:]/', '', $name);
        return function_exists('mb_substr') ? mb_substr($name, 0, 31) : substr($name, 0, 31);
    }
    public function setWidths($idx, array $w)  { $this->sheets[$idx]['widths'] = $w; }
    public function freeze($idx, $rows)        { $this->sheets[$idx]['freeze'] = $rows; }
    /** Freezes both columns (left of $cols) and rows (above $rows) at once —
     *  used by the master "Salary Sheet" tab to keep the identity columns
     *  (EPF No/Name/Designation) AND the header rows in view while scrolling,
     *  matching the checked workbook's own frozen-pane setup. */
    public function freezeAt($idx, $cols, $rows) { $this->sheets[$idx]['freezeCols'] = $cols; $this->sheets[$idx]['freeze'] = $rows; }
    public function merge($idx, $ref)          { $this->sheets[$idx]['merges'][] = $ref; }
    /** $cells = [ [value, 's'|'n', styleId], ... ] */
    public function addRow($idx, array $cells) { $this->sheets[$idx]['rows'][] = $cells; }

    private function colLetter($i) {
        $i++; $s = '';
        while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); }
        return $s;
    }
    private function esc($s) {
        $s = (string)$s;
        // Guard against invalid UTF-8 byte sequences (common with legacy/imported
        // HR data) — these would otherwise produce malformed XML.
        if (function_exists('mb_check_encoding') && !mb_check_encoding($s, 'UTF-8')) {
            $s = function_exists('mb_convert_encoding') ? mb_convert_encoding($s, 'UTF-8', 'UTF-8') : preg_replace('/[\x80-\xFF]/', '', $s);
        }
        // Strip characters that are illegal in XML 1.0 (control chars other than tab/LF/CR).
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    }

    private function sheetXml($sheet) {
        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $out .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        // IMPORTANT: element order is NOT arbitrary — the OOXML CT_Worksheet
        // schema requires sheetViews BEFORE cols BEFORE sheetData BEFORE
        // mergeCells. Getting sheetViews/cols backwards (as an earlier version
        // of this file did) parses fine in lenient readers but makes real
        // Microsoft Excel report every single sheet as needing repair/corrupt,
        // because Excel validates part structure strictly on open.
        if (!empty($sheet['freezeCols']) && $sheet['freeze'] > 0) {
            $fc = $sheet['freezeCols']; $fr = $sheet['freeze'];
            $topLeft = $this->colLetter($fc) . ($fr + 1);
            $out .= '<sheetViews><sheetView workbookViewId="0"><pane xSplit="' . $fc . '" ySplit="' . $fr . '" topLeftCell="' . $topLeft . '" activePane="bottomRight" state="frozen"/></sheetView></sheetViews>';
        } elseif ($sheet['freeze'] > 0) {
            $fr = $sheet['freeze'];
            $out .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $fr . '" topLeftCell="A' . ($fr + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        if (!empty($sheet['widths'])) {
            $out .= '<cols>';
            foreach ($sheet['widths'] as $i => $w) $out .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
            $out .= '</cols>';
        }
        $out .= '<sheetData>';
        foreach ($sheet['rows'] as $rIdx => $row) {
            $rNum = $rIdx + 1;
            $out .= '<row r="' . $rNum . '">';
            foreach ($row as $cIdx => $cell) {
                [$val, $type, $style] = $cell;
                $ref = $this->colLetter($cIdx) . $rNum;
                $sAttr = $style ? ' s="' . $style . '"' : '';
                if ($type === 's') {
                    $out .= '<c r="' . $ref . '"' . $sAttr . ' t="inlineStr"><is><t xml:space="preserve">' . $this->esc($val) . '</t></is></c>';
                } else {
                    $v = is_numeric($val) ? (float)$val : 0.0;
                    // Force a locale-independent "." decimal representation. On hosts where
                    // something upstream has called setlocale(LC_NUMERIC, ...) for a
                    // comma-decimal locale, a bare string cast of a float can silently turn
                    // "51100.00" into "51100,00" — which is invalid numeric XML content and
                    // is exactly the kind of thing Excel's strict parser rejects outright.
                    $vStr = rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
                    if ($vStr === '' || $vStr === '-') $vStr = '0';
                    $out .= '<c r="' . $ref . '"' . $sAttr . '><v>' . $vStr . '</v></c>';
                }
            }
            $out .= '</row>';
        }
        $out .= '</sheetData>';
        if (!empty($sheet['merges'])) {
            $out .= '<mergeCells count="' . count($sheet['merges']) . '">';
            foreach ($sheet['merges'] as $m) $out .= '<mergeCell ref="' . $m . '"/>';
            $out .= '</mergeCells>';
        }
        $out .= '</worksheet>';
        return $out;
    }

    private function stylesXml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2">'
            . '<numFmt numFmtId="164" formatCode="#,##0.00"/>'
            // Accounting format — matches Salary_Yelo_Logistics_May_2026_Checked.xlsx's
            // own number format exactly (parenthesised negatives, dash for zero).
            . '<numFmt numFmtId="165" formatCode="_(* #,##0.00_);_(* \(#,##0.00\);_(* &quot;&quot;_);_(@_)"/>'
            . '</numFmts>'
            . '<fonts count="6">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'                          // 0 default
            . '<font><sz val="11"/><b/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>' // 1 header (per-category sheets)
            . '<font><sz val="14"/><b/><name val="Calibri"/></font>'                      // 2 title (per-category sheets)
            . '<font><sz val="11"/><b/><name val="Calibri"/></font>'                      // 3 bold text (per-category sheets)
            . '<font><sz val="16"/><name val="Verdana"/></font>'                          // 4 master sheet title 1 (company name)
            . '<font><sz val="10"/><name val="Verdana"/></font>'                          // 5 master sheet title 2 (month) / Calibri10-equivalent size marker
            . '</fonts>'
            . '<fills count="5">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1A1A18"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEFEDE6"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF7F6F2"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="4">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'                                         // 0 none
            . '<border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/><diagonal/></border>'   // 1 thin all sides (headers, Sub Total/TOTAL labels)
            . '<border><left style="dotted"/><right style="dotted"/><top style="dotted"/><bottom style="dotted"/><diagonal/></border>' // 2 dotted all sides (data cells)
            . '<border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="double"/><diagonal/></border>' // 3 thin top/sides, double bottom (Sub Total/TOTAL numbers)
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="16">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                        // 0 default
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                          // 1 title
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'            // 2 header
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'                // 3 number
            . '<xf numFmtId="164" fontId="3" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'  // 4 number bold
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                          // 5 text bold
            . '<xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'            // 6 band
            . '<xf numFmtId="164" fontId="3" fillId="4" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>' // 7 subtotal number (per-category sheets)
            . '<xf numFmtId="0" fontId="3" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'            // 8 subtotal label (per-category sheets)
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>' // 9 master title 1
            . '<xf numFmtId="0" fontId="5" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>' // 10 master title 2
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="distributed"/></xf>' // 11 master header
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="2" xfId="0" applyBorder="1"/>'                        // 12 master data text
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="2" xfId="0" applyNumberFormat="1" applyBorder="1"/>' // 13 master data number
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>' // 14 master subtotal/total label
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="3" xfId="0" applyNumberFormat="1" applyBorder="1"/>' // 15 master subtotal/total number
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    public function output($filename) {
        // Discard ANY output buffered so far (from config.php/header.php,
        // stray whitespace/BOM, PHP notices, etc.) — even one leaked byte
        // before the ZIP signature makes Excel report the file as corrupt.
        while (ob_get_level() > 0) { @ob_end_clean(); }
        // Don't let any warning/notice during file-building leak into the
        // binary stream either; we give clear text errors ourselves below.
        @ini_set('display_errors', '0');

        if (!class_exists('ZipArchive')) {
            $this->fail('The PHP "zip" extension is not enabled on this server, so the Excel file cannot be built. Please ask your host to enable php-zip.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        @unlink($tmp); // tempnam() leaves an empty 0-byte file; remove it so CREATE starts fresh
        $zip = new ZipArchive();
        $openResult = $zip->open($tmp, ZipArchive::CREATE);
        if ($openResult !== true) {
            @unlink($tmp);
            $this->fail('Could not create the Excel file (ZipArchive error code ' . $openResult . '). Please try again.');
        }

        $n = count($this->sheets);

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $n; $i++) $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $contentTypes .= '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $sheetsXmlList = '';
        foreach ($this->sheets as $i => $s) $sheetsXmlList .= '<sheet name="' . $this->esc($s['name']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetsXmlList . '</sheets></workbook>';

        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        for ($i = 1; $i <= $n; $i++) $wbRels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        $wbRels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $wbRels .= '</Relationships>';

        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        foreach ($this->sheets as $i => $s) $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheetXml($s));
        $zip->close();

        clearstatcache(true, $tmp);
        if (!file_exists($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);
            $this->fail('The Excel file came out empty when building it. Please try again.');
        }

        while (ob_get_level() > 0) { @ob_end_clean(); } // final safety net
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: max-age=0');
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    private function fail($message) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Excel generation failed: $message";
        exit;
    }
}

/** Writes the band row (ADDITIONS / DEDUCTIONS group labels merged across
 *  their span) + the column header row for a given $COLUMNS spec, and
 *  freezes/widens the sheet accordingly. Shared by both sheet writers below.
 *  $COLUMNS is expected to already be the FINAL (possibly empty-column-
 *  filtered) list for this sheet — this function just lays out whatever
 *  it's given, contiguous groups included. */
function write_band_and_header(MiniXLSXWriter $xl, $sheetIdx, array $COLUMNS, $freezeRows) {
    $ncols = count($COLUMNS);

    $band = [];
    $i = 0;
    while ($i < $ncols) {
        $g = $COLUMNS[$i]['group'];
        if ($g === '') { $band[] = ['', 's', 0]; $i++; continue; }
        $start = $i;
        while ($i < $ncols && $COLUMNS[$i]['group'] === $g) $i++;
        $label = $g === 'ADD' ? 'ADDITIONS' : ($g === 'DED' ? 'DEDUCTIONS' : ($g === 'DET' ? 'INCENTIVE DETAIL (live-resolved, same as the payslip)' : 'EMPLOYER COST / BENEFIT'));
        $band[] = [$label, 's', MiniXLSXWriter::STYLE_BAND];
        for ($k = $start + 1; $k < $i; $k++) $band[] = ['', 's', MiniXLSXWriter::STYLE_BAND];
        if ($i - 1 > $start) $xl->merge($sheetIdx, colRef($start) . '2:' . colRef($i - 1) . '2');
    }
    $xl->addRow($sheetIdx, $band);

    $hdr = [];
    foreach ($COLUMNS as $c) $hdr[] = [$c['label'], 's', MiniXLSXWriter::STYLE_HEADER];
    $xl->addRow($sheetIdx, $hdr);
    $xl->freeze($sheetIdx, $freezeRows);
    $xl->setWidths($sheetIdx, array_column($COLUMNS, 'w'));
}

/** Writes one full "salary sheet" tab (title + band row + header row + data + totals) into the writer.
 *  $COLUMNS should already be the final, per-sheet-filtered column list (see filter_empty_columns()). */
function write_salary_sheet(MiniXLSXWriter $xl, $sheetIdx, $title, array $rows, array $COLUMNS) {
    $ncols = count($COLUMNS);

    // Title row
    $xl->addRow($sheetIdx, [[$title, 's', MiniXLSXWriter::STYLE_TITLE]]);
    $xl->merge($sheetIdx, 'A1:' . colRef($ncols - 1) . '1');

    write_band_and_header($xl, $sheetIdx, $COLUMNS, 3);

    // Data rows
    $totals = array_fill_keys(array_column($COLUMNS, 'key'), 0.0);
    foreach ($rows as $r) {
        $cells = [];
        foreach ($COLUMNS as $c) {
            $val = $r[$c['key']] ?? ($c['type'] === 'n' ? 0 : '');
            $cells[] = [$val, $c['type'], MiniXLSXWriter::STYLE_DEFAULT];
            if ($c['type'] === 'n') $totals[$c['key']] += floatval($val);
        }
        $xl->addRow($sheetIdx, $cells);
    }

    // Totals row
    $totRow = [];
    foreach ($COLUMNS as $idx0 => $c) {
        if ($idx0 === 0) { $totRow[] = ['TOTAL', 's', MiniXLSXWriter::STYLE_TEXT_BOLD]; continue; }
        if ($c['type'] === 'n') $totRow[] = [round($totals[$c['key']], 2), 'n', MiniXLSXWriter::STYLE_NUM_BOLD];
        else $totRow[] = ['', 's', MiniXLSXWriter::STYLE_TEXT_BOLD];
    }
    $xl->addRow($sheetIdx, $totRow);
}

/** Writes the master "Salary Sheet" tab: two title rows ("YELO LOGISTICS" /
 *  "PAYROLL FOR THE MONTH OF ..."), band + header rows, then every selected
 *  category's employees in turn — each block followed by its own "Sub Total"
 *  row — and finally one grand "TOTAL" row. Column layout/order matches
 *  Salary_Yelo_Logistics_May_2026_Checked.xlsx's own "Salary Sheet" tab.
 *  $byCategoryMasterRows = ['SR' => [masterRow, ...], 'MR' => [...], ...]
 *  (already run through map_master_row()); $order controls which category
 *  blocks appear and in what sequence (pass MASTER_SHEET_ORDER filtered down
 *  to the categories actually selected for this export).
 *  $COLUMNS should already be the final, empty-column-filtered list for this
 *  sheet (see filter_empty_columns()) — the identity columns counted by
 *  MASTER_LABEL_MERGE_COLS are never filtered out, so that merge span stays
 *  correct regardless of which ADD/DED columns were dropped. */
function write_master_salary_sheet(MiniXLSXWriter $xl, $sheetIdx, $companyName, $periodLabel, array $byCategoryMasterRows, array $order, array $COLUMNS) {
    $ncols = count($COLUMNS);

    // Title rows — "YELO LOGISTICS" (Verdana 16, centered) then "PAYROLL FOR
    // THE MONTH OF <Month Year>" (Verdana 10, left) — same fonts/sizes/
    // alignment as Salary_Yelo_Logistics_May_2026_Checked.xlsx, each merged
    // across every column.
    $xl->addRow($sheetIdx, [[$companyName, 's', MiniXLSXWriter::STYLE_M_TITLE1]]);
    $xl->merge($sheetIdx, 'A1:' . colRef($ncols - 1) . '1');
    $xl->addRow($sheetIdx, [['PAYROLL FOR THE MONTH OF ' . strtoupper($periodLabel), 's', MiniXLSXWriter::STYLE_M_TITLE2]]);
    $xl->merge($sheetIdx, 'A2:' . colRef($ncols - 1) . '2');

    // Band row (ADDITIONS / DEDUCTIONS group labels) + column header row — both
    // rendered with the master sheet's own header style: Calibri 10, centered +
    // distributed, thin border all sides, NO fill and NOT bold (unlike the
    // per-category sheets' bold/dark-fill header — this matches the checked
    // workbook cell-for-cell instead).
    $band = [];
    $i = 0;
    while ($i < $ncols) {
        $g = $COLUMNS[$i]['group'];
        if ($g === '') { $band[] = ['', 's', 0]; $i++; continue; }
        $start = $i;
        while ($i < $ncols && $COLUMNS[$i]['group'] === $g) $i++;
        $label = $g === 'ADD' ? 'ADDITIONS' : 'DEDUCTIONS';
        $band[] = [$label, 's', MiniXLSXWriter::STYLE_M_HEADER];
        for ($k = $start + 1; $k < $i; $k++) $band[] = ['', 's', MiniXLSXWriter::STYLE_M_HEADER];
        if ($i - 1 > $start) $xl->merge($sheetIdx, colRef($start) . '3:' . colRef($i - 1) . '3');
    }
    $xl->addRow($sheetIdx, $band);

    $hdr = [];
    foreach ($COLUMNS as $c) $hdr[] = [$c['label'], 's', MiniXLSXWriter::STYLE_M_HEADER];
    $xl->addRow($sheetIdx, $hdr);
    $rowNum = 4; // rows 1-2 titles, row 3 band, row 4 header — next row written will be row 5

    // Freeze both the header rows (top 4) AND the identity columns (EPF No,
    // Name, Designation) so both stay in view while scrolling — matching the
    // checked workbook's own frozen-pane setup.
    $xl->freezeAt($sheetIdx, 3, 4);
    $xl->setWidths($sheetIdx, array_column($COLUMNS, 'w'));

    $labelMergeEnd = MASTER_LABEL_MERGE_COLS - 1; // 0-indexed column to merge Sub Total/TOTAL labels through
    $grandTotals = array_fill_keys(array_column($COLUMNS, 'key'), 0.0);

    foreach ($order as $cat) {
        $rows = $byCategoryMasterRows[$cat] ?? [];
        if (empty($rows)) continue; // categories with nothing to show simply don't get a block

        $catTotals = array_fill_keys(array_column($COLUMNS, 'key'), 0.0);
        foreach ($rows as $r) {
            $cells = [];
            foreach ($COLUMNS as $c) {
                $val = $r[$c['key']] ?? ($c['type'] === 'n' ? 0 : '');
                $style = $c['type'] === 'n' ? MiniXLSXWriter::STYLE_M_DATA_NUM : MiniXLSXWriter::STYLE_M_DATA_TEXT;
                $cells[] = [$val, $c['type'], $style];
                if ($c['type'] === 'n') { $catTotals[$c['key']] += floatval($val); $grandTotals[$c['key']] += floatval($val); }
            }
            $xl->addRow($sheetIdx, $cells);
            $rowNum++;
        }

        // Per-category "Sub Total" row — label merged across the identity/attendance
        // columns (EPF No .. Before 7.45), numeric columns summed for this block only.
        // Thin-top/double-bottom border on the numeric cells, exactly like the
        // checked workbook's own Sub Total rows.
        $subRow = [];
        foreach ($COLUMNS as $idx0 => $c) {
            if ($idx0 === 0) { $subRow[] = ['Sub Total', 's', MiniXLSXWriter::STYLE_M_SUBTOTAL_LABEL]; continue; }
            if ($idx0 <= $labelMergeEnd) { $subRow[] = ['', 's', MiniXLSXWriter::STYLE_M_SUBTOTAL_LABEL]; continue; }
            if ($c['type'] === 'n') $subRow[] = [round($catTotals[$c['key']], 2), 'n', MiniXLSXWriter::STYLE_M_SUBTOTAL_NUM];
            else $subRow[] = ['', 's', MiniXLSXWriter::STYLE_M_SUBTOTAL_LABEL];
        }
        $xl->addRow($sheetIdx, $subRow);
        $rowNum++;
        $xl->merge($sheetIdx, colRef(0) . $rowNum . ':' . colRef($labelMergeEnd) . $rowNum);
    }

    // Grand TOTAL row across every category block.
    $totRow = [];
    foreach ($COLUMNS as $idx0 => $c) {
        if ($idx0 === 0) { $totRow[] = ['TOTAL', 's', MiniXLSXWriter::STYLE_M_SUBTOTAL_LABEL]; continue; }
        if ($idx0 <= $labelMergeEnd) { $totRow[] = ['', 's', MiniXLSXWriter::STYLE_M_SUBTOTAL_LABEL]; continue; }
        if ($c['type'] === 'n') $totRow[] = [round($grandTotals[$c['key']], 2), 'n', MiniXLSXWriter::STYLE_M_SUBTOTAL_NUM];
        else $totRow[] = ['', 's', MiniXLSXWriter::STYLE_M_SUBTOTAL_LABEL];
    }
    $xl->addRow($sheetIdx, $totRow);
    $rowNum++;
    $xl->merge($sheetIdx, colRef(0) . $rowNum . ':' . colRef($labelMergeEnd) . $rowNum);
}

function colRef($i) {
    $i++; $s = '';
    while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); }
    return $s;
}

// =========================================================================
//  EXPORT HANDLER — must run before ANY HTML output
// =========================================================================
$action = $_GET['do'] ?? '';
if ($action === 'export') {
    $pid  = intval($_GET['period_id'] ?? 0);
    $cats = isset($_GET['cats']) && is_array($_GET['cats']) ? array_values(array_intersect($_GET['cats'], ALL_CATEGORIES)) : ALL_CATEGORIES;
    $only_confirmed = !empty($_GET['only_confirmed']);
    if (empty($cats)) $cats = ALL_CATEGORIES;

    $period = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM payroll_periods WHERE id=$pid"));
    if (!$pid || !$period) { http_response_code(400); echo 'Invalid payroll period.'; exit; }

    $period_start = period_start_date($period);
    $label        = period_label($period);

    $byCategory = load_export_data($conn, $pid, $cats, $period_start);
    if ($only_confirmed) {
        foreach ($cats as $cat) {
            $byCategory[$cat] = array_values(array_filter($byCategory[$cat], fn($r) => $r['sheet_status'] === 'Confirmed'));
        }
    }

    $xl = new MiniXLSXWriter();

    // Sheet 1: "Salary Sheet" — the master combined sheet, formatted exactly
    // like Salary_Yelo_Logistics_May_2026_Checked.xlsx's own "Salary Sheet"
    // tab: SR → MR → CC → ACC → ST → OFF blocks, each with its own Sub Total
    // row, plus one grand TOTAL row at the end.
    $masterOrder = array_values(array_intersect(MASTER_SHEET_ORDER, $cats));
    $byCategoryMaster = [];
    foreach ($cats as $cat) {
        $byCategoryMaster[$cat] = array_map(fn($r) => map_master_row($r, $period), $byCategory[$cat]);
    }
    // Empty-column auto-hide: check every row across every included category
    // together (this is ONE continuous sheet), then drop any ADD/DED column
    // that's zero/blank everywhere on it. Identity columns are never touched.
    $allMasterRows = [];
    foreach ($byCategoryMaster as $rows) $allMasterRows = array_merge($allMasterRows, $rows);
    $masterColumnsFiltered = filter_empty_columns($MASTER_COLUMNS, $allMasterRows);

    $idxMaster = $xl->addSheet('Salary Sheet');
    write_master_salary_sheet($xl, $idxMaster, 'YELO LOGISTICS', $label, $byCategoryMaster, $masterOrder, $masterColumnsFiltered);

    // Sheet 2: Summary
    $idxSum = $xl->addSheet('Summary');
    $xl->addRow($idxSum, [['YELO LOGISTICS - SALARY SUMMARY - ' . strtoupper($label), 's', MiniXLSXWriter::STYLE_TITLE]]);
    $xl->merge($idxSum, 'A1:F1');
    $xl->addRow($idxSum, [
        ['Category', 's', MiniXLSXWriter::STYLE_HEADER], ['Employees', 's', MiniXLSXWriter::STYLE_HEADER],
        ['Excluded (Resigned)', 's', MiniXLSXWriter::STYLE_HEADER], ['Total Earnings', 's', MiniXLSXWriter::STYLE_HEADER],
        ['Total Deductions', 's', MiniXLSXWriter::STYLE_HEADER], ['Net Salary', 's', MiniXLSXWriter::STYLE_HEADER],
    ]);
    $xl->setWidths($idxSum, [12, 12, 18, 16, 16, 16]);
    $xl->freeze($idxSum, 2);
    $gEarn = $gDed = $gNet = 0.0; $gCount = 0;
    foreach ($cats as $cat) {
        $rows = $byCategory[$cat];
        $earn = array_sum(array_column($rows, 'total_earnings'));
        $ded  = array_sum(array_column($rows, 'total_deductions'));
        $net  = array_sum(array_column($rows, 'net_salary'));
        $excl = count_excluded($conn, $pid, $cat, $period_start);
        $xl->addRow($idxSum, [
            [$cat, 's', 0], [count($rows), 'n', 3], [$excl, 'n', 3],
            [round($earn, 2), 'n', 3], [round($ded, 2), 'n', 3], [round($net, 2), 'n', 3],
        ]);
        $gEarn += $earn; $gDed += $ded; $gNet += $net; $gCount += count($rows);
    }
    $xl->addRow($idxSum, [
        ['TOTAL', 's', MiniXLSXWriter::STYLE_TEXT_BOLD], [$gCount, 'n', MiniXLSXWriter::STYLE_NUM_BOLD], ['', 's', 0],
        [round($gEarn, 2), 'n', MiniXLSXWriter::STYLE_NUM_BOLD], [round($gDed, 2), 'n', MiniXLSXWriter::STYLE_NUM_BOLD],
        [round($gNet, 2), 'n', MiniXLSXWriter::STYLE_NUM_BOLD],
    ]);

    // One sheet per selected category (unchanged detailed per-category breakdown,
    // still useful for the per-category confirm/review workflow). Each of these
    // sheets is filtered INDEPENDENTLY against only its own category's rows, so
    // e.g. "Locus Admin" can disappear from the SR tab (never used there) while
    // still showing on the CC tab (where it's actually used).
    $catLabels = ['SR' => 'Sales Representatives', 'CC' => 'Cash Collectors', 'ACC' => 'Accounts', 'MR' => 'Merchandisers', 'ST' => 'Store Staff', 'OFF' => 'Office Staff'];
    foreach ($cats as $cat) {
        $idx = $xl->addSheet($cat);
        $sheetColumns = array_merge($COLUMNS, $CATEGORY_DETAIL_COLUMNS[$cat] ?? []);
        $sheetColumnsFiltered = filter_empty_columns($sheetColumns, $byCategory[$cat]);
        write_salary_sheet($xl, $idx, 'YELO LOGISTICS - ' . ($catLabels[$cat] ?? $cat) . ' - ' . strtoupper($label), $byCategory[$cat], $sheetColumnsFiltered);
    }

    $fname = 'Salary_' . implode('-', $cats) . '_' . str_replace(' ', '_', $label) . '.xlsx';
    $xl->output($fname);
    exit; // safety net; output() already exits
}

// =========================================================================
//  PAGE (form + live preview) — only reached when $action !== 'export'
// =========================================================================
$all_periods = [];
$res = mysqli_query($conn, "SELECT id, year, month, status FROM payroll_periods ORDER BY year DESC, month DESC");
if ($res) while ($p = mysqli_fetch_assoc($res)) $all_periods[] = $p;

$sel_period_id = intval($_GET['period_id'] ?? 0);
if (!$sel_period_id) {
    foreach ($all_periods as $pp) if (($pp['status'] ?? '') === 'Open') { $sel_period_id = $pp['id']; break; }
    if (!$sel_period_id && !empty($all_periods)) $sel_period_id = $all_periods[0]['id'];
}
$active_period = null;
foreach ($all_periods as $pp) if ($pp['id'] == $sel_period_id) { $active_period = $pp; break; }

$sel_cats = isset($_GET['cats']) && is_array($_GET['cats']) ? array_values(array_intersect($_GET['cats'], ALL_CATEGORIES)) : ALL_CATEGORIES;
if (empty($sel_cats)) $sel_cats = ALL_CATEGORIES;
$only_confirmed = !empty($_GET['only_confirmed']);

$preview = [];
if ($active_period) {
    $period_start = period_start_date($active_period);
    $allData = load_export_data($conn, $sel_period_id, ALL_CATEGORIES, $period_start);
    foreach (ALL_CATEGORIES as $cat) {
        $rows = $allData[$cat];
        if ($only_confirmed) $rows = array_values(array_filter($rows, fn($r) => $r['sheet_status'] === 'Confirmed'));
        $preview[$cat] = [
            'count'   => count($rows),
            'excluded'=> count_excluded($conn, $sel_period_id, $cat, $period_start),
            'earn'    => array_sum(array_column($rows, 'total_earnings')),
            'ded'     => array_sum(array_column($rows, 'total_deductions')),
            'net'     => array_sum(array_column($rows, 'net_salary')),
            // Only computed when the category is actually empty — a few
            // extra small COUNT(*) queries is a fine trade for turning "0"
            // into a specific, actionable reason instead of a dead end.
            'zero_reason' => count($rows) === 0 ? category_zero_reason($conn, $sel_period_id, $cat, $period_start) : null,
        ];
    }
}

function build_qs($overrides = []) {
    $params = $_GET;
    foreach ($overrides as $k => $v) $params[$k] = $v;
    return http_build_query($params);
}

include 'header.php';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Space+Mono:wght@400;700&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<style>
:root{
    --ink:#0a0a0a; --ink2:#444; --ink3:#888; --bg:#f2f1ee; --surface:#fff;
    --border:#dddbd4; --border2:#c8c6bf; --accent:#1a1a18;
    --mono:'Space Mono',monospace; --sans:'DM Sans',sans-serif; --display:'Syne',sans-serif;
    --r:6px; --r-lg:12px; --shadow-sm:0 1px 3px rgba(0,0,0,.06),0 2px 8px rgba(0,0,0,.04);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--sans);background:var(--bg);color:var(--ink);}
.pg-hdr{background:var(--accent);color:#fff;padding:22px 28px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;border-radius:0 0 var(--r-lg) var(--r-lg);margin-bottom:20px;}
.pg-hdr-title{font-family:var(--display);font-size:22px;font-weight:800;letter-spacing:-.2px;display:flex;align-items:center;gap:10px;}
.pg-hdr-sub{font-size:12px;color:#a0a09a;margin-top:3px;}
.wrap{max-width:1180px;margin:0 auto;padding:0 20px 40px;}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:22px 24px;margin-bottom:18px;box-shadow:var(--shadow-sm);}
.card-title{font-family:var(--display);font-size:14px;font-weight:700;margin-bottom:14px;color:var(--ink2);display:flex;align-items:center;gap:8px;}
.form-grid{display:grid;grid-template-columns:1fr 2fr;gap:20px;align-items:start;}
.field label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--ink3);margin-bottom:6px;}
.field select{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--r);font-size:14px;font-family:var(--sans);background:#fff;}
.cat-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;}
.cat-chip{border:1.5px solid var(--border);border-radius:var(--r);padding:10px 8px;text-align:center;cursor:pointer;transition:all .15s;user-select:none;}
.cat-chip:hover{border-color:var(--border2);}
.cat-chip.on{background:var(--accent);border-color:var(--accent);color:#fff;}
.cat-chip input{display:none;}
.cat-chip .code{font-family:var(--mono);font-weight:700;font-size:15px;}
.cat-chip .cnt{font-size:11px;opacity:.75;margin-top:2px;}
.toggle-row{display:flex;align-items:center;gap:8px;margin-top:14px;font-size:13px;color:var(--ink2);}
.actions{display:flex;gap:10px;margin-top:18px;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:var(--r);font-size:13px;font-weight:700;font-family:var(--sans);cursor:pointer;border:none;text-decoration:none;transition:all .15s;}
.btn-dark{background:var(--accent);color:#fff;}.btn-dark:hover{background:#333;}
.btn-green{background:#22c55e;color:#fff;}.btn-green:hover{background:#16a34a;}
table.prev{width:100%;border-collapse:collapse;font-size:13px;}
table.prev th{text-align:left;padding:10px 12px;background:var(--bg);border-bottom:2px solid var(--border2);font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--ink3);}
table.prev td{padding:10px 12px;border-bottom:1px solid var(--border);}
table.prev tr.total td{font-weight:800;border-top:2px solid var(--border2);background:#faf9f6;}
.excl-badge{display:inline-block;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:700;}
.excl-badge.zero{background:#f0fdf4;color:#166534;border-color:#bbf7d0;}
.zero-reason-row td{padding:8px 12px 14px 12px !important;border-bottom:1px solid var(--border);background:#fff7ed;}
.zero-reason{font-size:12px;color:#9a3412;background:#ffedd5;border:1px solid #fdba74;border-radius:8px;padding:8px 12px;line-height:1.5;display:flex;gap:8px;align-items:flex-start;}
.zero-reason i{margin-top:2px;color:#c2410c;}
.note{font-size:12px;color:var(--ink3);margin-top:10px;line-height:1.5;}
</style>

<div class="pg-hdr">
    <div>
        <div class="pg-hdr-title"><i class="fa-solid fa-file-excel"></i> Salary Excel Generation</div>
        <div class="pg-hdr-sub">Generate one combined payroll Excel — all employees, any categories, one click. The main "Salary Sheet" tab now matches the checked master workbook's own layout (SR → MR → CC → ACC → ST → OFF blocks, each with a Sub Total row, plus a grand TOTAL). Figures are computed the same way as the printed payslip. Any earnings/deductions column with no data at all is automatically left out of that sheet. Resigned/terminated employees are always excluded.</div>
    </div>
</div>

<div class="wrap">
    <form method="GET" id="genForm">
        <div class="card">
            <div class="card-title"><i class="fa-solid fa-sliders"></i> Select Month &amp; Categories</div>
            <div class="form-grid">
                <div class="field">
                    <label>Payroll Period</label>
                    <select name="period_id" onchange="document.getElementById('genForm').submit()">
                        <?php foreach ($all_periods as $pp): ?>
                            <option value="<?php echo $pp['id']; ?>" <?php echo $pp['id']==$sel_period_id?'selected':''; ?>>
                                <?php echo date('F Y', strtotime($pp['year'].'-'.$pp['month'].'-01')); ?>
                                <?php echo $pp['status']==='Open' ? ' (Open)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="toggle-row">
                        <input type="checkbox" id="only_confirmed" name="only_confirmed" value="1"
                            <?php echo $only_confirmed?'checked':''; ?> onchange="document.getElementById('genForm').submit()">
                        <label for="only_confirmed" style="margin:0;text-transform:none;font-weight:500;font-size:13px;color:var(--ink2);">Only include Confirmed sheets</label>
                    </div>
                </div>
                <div class="field">
                    <label>Categories to Include</label>
                    <div class="cat-grid">
                        <?php foreach (ALL_CATEGORIES as $cat):
                            $on = in_array($cat, $sel_cats);
                            $cnt = $preview[$cat]['count'] ?? 0;
                        ?>
                        <label class="cat-chip <?php echo $on?'on':''; ?>">
                            <input type="checkbox" name="cats[]" value="<?php echo $cat; ?>" <?php echo $on?'checked':''; ?>
                                onchange="document.getElementById('genForm').submit()">
                            <div class="code"><?php echo $cat; ?></div>
                            <div class="cnt"><?php echo $cnt; ?> emp.</div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="note">Uncheck a category to leave it out of the Excel. A category whose sheet hasn't been generated yet on its own page will simply show 0 employees here.</div>
                </div>
            </div>

            <div class="actions">
                <button type="submit" name="do" value="preview" class="btn btn-dark"><i class="fa-solid fa-arrows-rotate"></i> Refresh Preview</button>
                <button type="submit" name="do" value="export" formtarget="_blank" class="btn btn-green"><i class="fa-solid fa-download"></i> Generate &amp; Download Excel (.xlsx)</button>
            </div>
        </div>
    </form>

    <?php if ($active_period): ?>
    <div class="card">
        <div class="card-title"><i class="fa-solid fa-table-list"></i> Preview — <?php echo period_label($active_period); ?></div>
        <table class="prev">
            <thead>
                <tr>
                    <th>Category</th><th>Employees Included</th><th>Excluded (Resigned/Terminated)</th>
                    <th>Total Earnings</th><th>Total Deductions</th><th>Net Salary</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $gEarn=$gDed=$gNet=0; $gCount=0;
                foreach ($sel_cats as $cat):
                    $p = $preview[$cat];
                    $gEarn += $p['earn']; $gDed += $p['ded']; $gNet += $p['net']; $gCount += $p['count'];
                ?>
                <tr>
                    <td><strong><?php echo $cat; ?></strong></td>
                    <td><?php echo $p['count']; ?></td>
                    <td><span class="excl-badge <?php echo $p['excluded']==0?'zero':''; ?>"><?php echo $p['excluded']; ?> excluded</span></td>
                    <td>Rs. <?php echo number_format($p['earn'],2); ?></td>
                    <td>Rs. <?php echo number_format($p['ded'],2); ?></td>
                    <td>Rs. <?php echo number_format($p['net'],2); ?></td>
                </tr>
                <?php if (!empty($p['zero_reason'])): ?>
                <tr class="zero-reason-row">
                    <td colspan="6">
                        <div class="zero-reason">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <div><strong><?php echo $cat; ?> shows 0 — here's why:</strong> <?php echo htmlspecialchars($p['zero_reason']); ?></div>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
                <tr class="total">
                    <td>TOTAL</td><td><?php echo $gCount; ?></td><td></td>
                    <td>Rs. <?php echo number_format($gEarn,2); ?></td>
                    <td>Rs. <?php echo number_format($gDed,2); ?></td>
                    <td>Rs. <?php echo number_format($gNet,2); ?></td>
                </tr>
            </tbody>
        </table>
        <div class="note">
            "Excluded" employees are those with <code>status = Resigned/Terminated</code> (when no exit date is set) or an
            <code>exit_date</code> that falls <em>before</em> the first day of this payroll month — i.e. they had already
            left before this month began. Employees who resign <em>during</em> this month are still included, since they
            worked part of it and are owed a final settlement; they will drop off starting the following month.
            <br><br>
            Every figure above is computed the same way the printed payslip computes it: a live value from incentive
            entries / fuel entries / salary advances / the employee profile overrides the saved snapshot when one exists
            (except SR's Meal/Travelling/Mobile, which always use the saved sheet figures, and OFF, which never has a
            Meal Allowance), and Total Earnings / Total Deductions / Net Salary are the sum of the itemized rows shown on
            each sheet — not a separately stored total. 12% EPF / 3% ETF (employer) and Total Benefit are always
            calculated from the Basic Adjusted Salary (Total Benefit = Total Earnings + EPF 12% + ETF 3% + Gratuity +
            Insurance). Welfare Deduction and Welfare Loan Deduction are always shown as separate line items, never
            combined. The downloaded workbook's main "Salary Sheet" tab groups everyone into
            SR → MR → CC → ACC → ST → OFF blocks with a Sub Total row after each, exactly like the checked master
            payroll workbook, followed by a grand TOTAL row. Any Earnings/Deductions/Incentive-detail column with no
            data at all on a given sheet (every employee shows 0) is automatically left out of that sheet, so you never
            get a big blank column for a component that category doesn't use.
        </div>
    </div>
    <?php else: ?>
    <div class="card"><div class="note">No payroll periods found. Create one first from the Payroll Periods page.</div></div>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('.cat-chip').forEach(chip=>{
    chip.addEventListener('click', e=>{ /* checkbox submits via onchange above; class toggle handled on reload */ });
});
</script>

<?php include 'footer.php'; ?>