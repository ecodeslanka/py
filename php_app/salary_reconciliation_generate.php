<?php
/**
 * salary_reconciliation_generate.php
 * ─────────────────────────────────────────────────────────────────────────
 * "Generate Salary Reconciliation Excel" — reproduces the "1-Basic Salary
 * Rec" tab from Salary_Rec.xlsx: a month-over-month reconciliation of Total
 * Basic Salary, built entirely from data already in the payroll system
 * (same tables and same live-value resolution rules as
 * salary_excel_generate.php / print_payslips.php), so this reconciliation
 * always agrees with the salary sheet and the payslip.
 *
 * WHAT THIS PAGE DOES
 *   Given a payroll period (the "current" month), it automatically finds
 *   the prior calendar month's payroll_periods row (the "previous" month)
 *   and reconciles Total Basic Salary between them across whichever of the
 *   6 categories (SR / CC / ACC / MR / ST / OFF) are selected:
 *
 *     Total Basic Salary as of Last Month            <- SUM(basic) for the
 *                                                        previous period
 *     + Recruitment during the month                 <- employees NEW to
 *                                                        this period's sheet
 *                                                        AND whose
 *                                                        employees.date_of_join
 *                                                        falls inside THIS
 *                                                        selected month —
 *                                                        their Join Date is
 *                                                        shown on the row
 *     - Resignation during the month                 <- employees on last
 *                                                        period's sheet whose
 *                                                        employees.exit_date
 *                                                        falls inside THIS
 *                                                        month (their Resign
 *                                                        Date is shown on the
 *                                                        row), plus any who
 *                                                        simply vanished from
 *                                                        this month's sheet
 *                                                        (no exit_date saved —
 *                                                        date column blank)
 *     - NOPAY                                         <- employees present
 *                                                        in BOTH periods
 *                                                        whose current-month
 *                                                        no_pay_amount is
 *                                                        non-zero (shown
 *                                                        negative)
 *     = Total Basic Salary as of <end of this month>  <- always the actual
 *                                                        SUM(basic) for the
 *                                                        current period (see
 *                                                        note below)
 *
 *   "Present" is judged purely by employee_id existing in that category's
 *   saved salary-sheet rows for that period — the same rows
 *   salary_excel_generate.php itself reads, so an employee only shows up
 *   here once their category page has actually been generated/saved for
 *   that month.
 *
 *   RECRUITMENT — DRIVEN BY employees.date_of_join:
 *   An employee only lands in Recruitment if BOTH are true:
 *     (a) they were not on last period's saved salary-sheet rows, AND
 *     (b) their Date of Join (sheet snapshot, falling back to the live
 *         employees.date_of_join column) falls within the selected month
 *         (period_start_date .. period_end_date).
 *   The Join Date is displayed on each Recruitment row. This filters out
 *   employees who merely switched category, or whose sheet simply wasn't
 *   generated last month, so this list is genuinely "people onboarded this
 *   month" — not just "new to the export".
 *
 *   RESIGNATION — DRIVEN BY employees.exit_date:
 *   An employee lands in Resignation if they were on last period's sheet
 *   AND either:
 *     (a) their employees.exit_date falls within the selected month — the
 *         Resign Date is displayed on the row (this catches people who
 *         resigned mid-month even if they still received a partial salary
 *         and therefore still appear on this month's sheet), OR
 *     (b) they no longer appear on this month's sheet at all (fallback for
 *         records where no exit_date was ever saved — date column blank).
 *   The amount shown is their LAST month's basic, negative.
 *   NOTE: so that recently-exited employees (who may already be flagged
 *   inactive / Resigned in the employees table) still show up in last
 *   month's data for this comparison, the PREVIOUS-period query here also
 *   admits rows whose exit_date is on/after that period's start — the
 *   CURRENT-period query is left byte-identical to
 *   salary_excel_generate.php's so the bottom-line totals always match the
 *   export exactly.
 *
 *   Because the date-driven Recruitment/Resignation lists can differ from a
 *   naive "appeared/disappeared on the sheet" diff, the grand total ("Total
 *   Basic Salary as of <date>") is NOT derived by addition — it's always
 *   the real SUM(basic) for the current period's saved rows (ground truth,
 *   always correct). A separate "Reconciliation check" line is shown only
 *   if the addition-based figure (prev + recruitment - resignation - nopay)
 *   would have landed on a different number, so any such employees are
 *   still visible instead of silently vanishing from the math.
 *
 * EXCEL STYLING — PROFESSIONAL / MINIMAL COLOR:
 *   Section titles and column-header rows are plain bold black (no fill).
 *   ONLY the detail data rows carry a status fill: Recruitment rows green,
 *   Resignation rows red, NOPAY rows yellow — genuine Excel cell fills in
 *   the downloaded .xlsx (Excel's own Good/Bad/Neutral palette), mirrored
 *   in the on-screen preview. Everything else (title, totals, Staff Loan
 *   sections) stays in the workbook's normal black/white accounting look.
 *
 * IMPORTANT — DATA-SOURCE GAP: STAFF LOAN
 *   The sample workbook's "Staff Loan" / "Unrecovered Balance" section
 *   needs a running Loan Amount + cumulative Recovered balance per
 *   employee. Nothing in the existing schema tracks that — the salary
 *   sheet tables only carry a single month's w_loan / loan_ded deduction,
 *   not a loan principal or a running recovered total. There is no safe way
 *   to reconstruct "Loan Amount" from data that was never saved, so rather
 *   than guess, this page adds one small new ledger table, `staff_loans`
 *   (employee_id, loan_amount, recovered_amount, status), which is
 *   currently NOT populated by anything else in the system — someone needs
 *   to enter/maintain it (e.g. from a future "Staff Loans" admin page, or
 *   directly in the DB) for the Staff Loan section to show real numbers.
 *   Until then it will legitimately render empty, which is more honest than
 *   fabricating figures. Employee name/category for a loan row is looked
 *   up from whichever period (current, else previous) still has that
 *   employee's salary-sheet row.
 *
 * Everything else below (schema safety-net, excluded_employee_sql,
 * live_earn_value, employee_profile_select_sql, fetch_incentive_entries,
 * fetch_sr_fuel_map, fetch_advance_map, fetch_category_raw_rows,
 * MiniXLSXWriter) is copied from salary_excel_generate.php UNCHANGED
 * except where noted, specifically so this page's "basic" figures can
 * never drift from that export or from the payslip. This file runs as its
 * own independent script/page, so duplicating those functions here causes
 * no name-collision with salary_excel_generate.php.
 * ─────────────────────────────────────────────────────────────────────────
 */

ob_start();
include 'config.php';

// ── Defensive schema safety-net (idempotent) ─────────────────────────────
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
    "ccfot_amt DECIMAL(12,2) DEFAULT 0", "credit_mgt_amt DECIMAL(12,2) DEFAULT 0", "payee_chq_amt DECIMAL(12,2) DEFAULT 0",
    "ssv_cc_amt DECIMAL(12,2) DEFAULT 0", "att_inc_amt DECIMAL(12,2) DEFAULT 0", "locus_admin_amt DECIMAL(12,2) DEFAULT 0",
    "cash_short DECIMAL(12,2) DEFAULT 0", "good_short DECIMAL(12,2) DEFAULT 0",
    "ssv_acc_amt DECIMAL(12,2) DEFAULT 0", "punctuality_amt DECIMAL(12,2) DEFAULT 0", "att_allow_amt DECIMAL(12,2) DEFAULT 0",
    "plc_70 DECIMAL(12,2) DEFAULT 0", "plc_80 DECIMAL(12,2) DEFAULT 0", "plc_90 DECIMAL(12,2) DEFAULT 0",
    "att_inc_70 DECIMAL(12,2) DEFAULT 0", "att_inc_80 DECIMAL(12,2) DEFAULT 0", "att_inc_90 DECIMAL(12,2) DEFAULT 0",
    "dlink_amount DECIMAL(12,2) DEFAULT 0",
];
foreach ($sse_cols as $c) @mysqli_query($conn, "ALTER TABLE salary_sheet_entries ADD COLUMN IF NOT EXISTS $c");

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

// ── NEW: minimal loan ledger — see the data-source-gap note at the top of
//    this file. NOT populated automatically by anything else in the system. ──
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS staff_loans (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    employee_id         INT NOT NULL,
    category_code       VARCHAR(50),
    loan_amount         DECIMAL(12,2) DEFAULT 0,
    recovered_amount    DECIMAL(12,2) DEFAULT 0,
    status              VARCHAR(20) DEFAULT 'Active',
    notes               VARCHAR(255) DEFAULT NULL,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ── Category registry (identical to salary_excel_generate.php) ──────────
const ALL_CATEGORIES = ['SR', 'CC', 'ACC', 'MR', 'ST', 'OFF'];
const MASTER_SHEET_ORDER = ['SR', 'MR', 'CC', 'ACC', 'ST', 'OFF'];

// ── Small helpers (identical to salary_excel_generate.php) ───────────────
function sum_additional_json($json) {
    if (empty($json)) return 0.0;
    $arr = json_decode($json, true);
    if (!is_array($arr)) return 0.0;
    $t = 0.0;
    foreach ($arr as $item) $t += floatval($item['amount'] ?? 0);
    return $t;
}

function excluded_employee_sql($period_start) {
    $ps = mysqli_real_escape_string($GLOBALS['conn'], $period_start);
    return "(
        (e.exit_date IS NOT NULL AND e.exit_date < '$ps')
        OR (e.exit_date IS NULL AND e.status IN ('Resigned','Terminated'))
    )";
}

function period_start_date($period) { return sprintf('%04d-%02d-01', (int)$period['year'], (int)$period['month']); }
function period_end_date($period)   { return date('Y-m-t', strtotime(period_start_date($period))); }
function period_label($period)      { return date('F Y', strtotime(sprintf('%04d-%02d-01', (int)$period['year'], (int)$period['month']))); }

/** 'YYYY-MM-DD' -> '05-Jul-2026' for display; '' when empty/invalid. */
function fmt_recon_date($d) {
    $d = norm_recon_date($d);
    if ($d === '') return '';
    $ts = strtotime($d);
    return $ts ? date('d-M-Y', $ts) : '';
}

/** Normalizes any stored date to a bare 'YYYY-MM-DD' string so comparisons
 *  against period_start/period_end (also 'YYYY-MM-DD') are reliable. Handles
 *  DATETIME values ('2026-07-05 00:00:00'), stray whitespace, and the MySQL
 *  zero-date. Returns '' for anything empty/invalid. Without this, a stored
 *  DATETIME like '2026-07-05 00:00:00' would fail the string comparison
 *  '2026-07-05 00:00:00' <= '2026-07-31' unexpectedly and silently drop the
 *  row from Recruitment/Resignation. */
function norm_recon_date($d) {
    if ($d === null) return '';
    $d = trim((string)$d);
    if ($d === '' || strpos($d, '0000-00-00') === 0) return '';
    // Take just the date portion if a time component is present.
    if (strlen($d) >= 10) $d = substr($d, 0, 10);
    $ts = strtotime($d);
    return $ts ? date('Y-m-d', $ts) : '';
}

// =========================================================================
//  LIVE-VALUE RESOLUTION — copied field-for-field from
//  salary_excel_generate.php / print_payslips.php so "basic" and "no_pay"
//  here always agree with the salary sheet and the payslip.
// =========================================================================
function inc_lookup($inc_entries, $eid, $pattern) {
    foreach ($inc_entries[$eid] ?? [] as $lbl => $val) if (strpos($lbl, $pattern) !== false) return floatval($val);
    return 0;
}

function live_earn_value($cat, $key, $eid, $emp, $inc_entries, $sr_fuel_map) {
    $direct = [
        'ACC' => ['ccfot_amt'=>'__incent__ccfot_acc','ssv_acc_amt'=>'__incent__ssv_acc','punctuality_amt'=>'__incent__punctuality_acc','att_allow_amt'=>'__incent__attendance_acc'],
        'CC'  => ['ccfot_amt'=>'__incent__ccfot','credit_mgt_amt'=>'__incent__credit_mgt','payee_chq_amt'=>'__incent__payee_chq','ssv_cc_amt'=>'__incent__ssv_cc','att_inc_amt'=>'__incent__attendance_cc','locus_admin_amt'=>'__incent__locus_admin'],
        'MR'  => ['ps_amt'=>'__incent__placement_compliance','att_allow'=>'__incent__attendance'],
        'SR'  => ['disc'=>'__disc_amount','ssv'=>'__ssv_amount','ps_amt'=>'__incent__total_ps_compliance','eco_amt'=>'__incent__eco_incentive','bp_amt'=>'__incent__bp_incentive','assort_amt'=>'__incent__total_assortment','other_incentive'=>'__other_incentive','mobile'=>'__reimb__mobile_reimbursement'],
        'OFF' => ['daily_incent'=>'__incent__incentive_daily','weekly_incent'=>'__incent__incentive_weekly','monthly_incent'=>'__incent__incentive_monthly','punctuality_amt'=>'__incent__punctuality_75'],
        'ST'  => ['stores_damage_amt'=>'__incent__stores_damage','rsqm_amt'=>'__incent__rsqm','punctuality_amt'=>'__incent__punctuality','loading_unloading_amt'=>'__incent__loading_unloading','ccfot_amt'=>'__incent__ccfot_st'],
    ];
    $reimb = [
        'MR' => ['meal'=>['__reimb__meal_reimbursement','reimbursement_meal'], 'travel'=>['__reimb__traveling_reimbursement','reimbursement_traveling']],
        'SR' => ['meal'=>['__reimb__reimbursement_meal','reimbursement_meal'], 'travel'=>['__reimb__reimbursement_traveling','reimbursement_traveling']],
        'OFF'=> ['meal'=>['__reimb__meal_reimbursement','reimbursement_meal']],
        'ST' => ['meal'=>['__reimb__meal_reimbursement','reimbursement_meal']],
    ];
    if (isset($direct[$cat][$key])) {
        $v = inc_lookup($inc_entries, $eid, $direct[$cat][$key]);
        if ($v > 0) return $v;
        return null;
    }
    if (isset($reimb[$cat][$key])) {
        [$pattern, $profileCol] = $reimb[$cat][$key];
        $v = inc_lookup($inc_entries, $eid, $pattern);
        if ($v > 0) return $v;
        $v = floatval($emp[$profileCol] ?? 0);
        return $v > 0 ? $v : null;
    }
    if ($cat === 'CC' && $key === 'mobile') {
        $v = floatval($emp['mobile_reimbursement'] ?? $emp['reimbursement_mobile'] ?? 0);
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

function fetch_incentive_entries($conn, $pid, array $emp_ids) {
    $out = [];
    if (empty($emp_ids)) return $out;
    $ids_str = implode(',', array_map('intval', $emp_ids));
    $res = @mysqli_query($conn, "SELECT employee_id, component_label, amount FROM incentive_entries WHERE payroll_period_id=$pid AND employee_id IN ($ids_str)");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $out[(int)$r['employee_id']][$r['component_label']] = (float)$r['amount'];
    return $out;
}

function fetch_sr_fuel_map($conn, $pid, array $emp_ids) {
    $out = [];
    if (empty($emp_ids)) return $out;
    $ids_str = implode(',', array_map('intval', $emp_ids));
    $res = @mysqli_query($conn, "SELECT employee_id, (fuel_rate * fuel_liter) AS fuel_amount FROM sr_fuel_entries WHERE payroll_period_id=$pid AND employee_id IN ($ids_str)");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $out[(int)$r['employee_id']] = floatval($r['fuel_amount']);
    return $out;
}

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

/** Trimmed down from salary_excel_generate.php's map_category_row(): this
 *  reconciliation only needs Basic Salary, the No-Pay deduction, and the
 *  join/resign dates (plus identity fields), so only those live-resolved
 *  figures are computed here — 'basic' has no live-override pattern in any
 *  category (per live_earn_value() above) so it is always the saved sheet
 *  value; no_pay likewise has no live pattern and is always the saved sheet
 *  value. Both therefore already agree with salary_excel_generate.php /
 *  the payslip without needing the rest of that function's earnings/
 *  deductions build-up.
 *  'doj' = Date of Join — the sheet-row snapshot, falling back to the live
 *  employees.date_of_join (aliased emp_doj) when the snapshot is empty —
 *  decides + displays "Recruitment during the month".
 *  'exit_date' = live employees.exit_date (aliased emp_exit_date) —
 *  decides + displays "Resignation during the month". */
function map_recon_row($row, $cat) {
    // Prefer the sheet snapshot's date_of_join, but only if it actually
    // normalizes to a real date (guards against '', NULL, '0000-00-00' and
    // '0000-00-00 00:00:00'); otherwise fall back to the live
    // employees.date_of_join column (aliased emp_doj). Stored raw so the
    // caller (build_basic_salary_reconciliation) normalizes once more before
    // comparing/displaying.
    $snapshot = $row['date_of_join'] ?? null;
    $doj = (norm_recon_date($snapshot) !== '') ? $snapshot : ($row['emp_doj'] ?? null);
    return [
        'employee_id' => (int)($row['employee_id'] ?? 0),
        'emp_code'    => $row['employee_code'] ?? '',
        'epf_no'      => $row['epf_number'] ?? '',
        'name'        => $row['employee_name'] ?? '',
        'category'    => $cat,
        'basic'       => round(floatval($row['basic'] ?? 0), 2),
        'no_pay'      => round(floatval($row['no_pay_amount'] ?? 0), 2),
        'doj'         => $doj,
        'exit_date'   => $row['emp_exit_date'] ?? null,
    ];
}

/** $include_recent_exits: false = byte-identical eligibility to
 *  salary_excel_generate.php (used for the CURRENT period so totals always
 *  match the export). true = ALSO admits rows whose exit_date is on/after
 *  this period's start even if the employee has since been flagged
 *  inactive — used only for the PREVIOUS period, so someone who resigned
 *  during the current month (and may already be deactivated in the
 *  employees table) still shows up in last month's data and can be listed
 *  under "Resignation during the month" with their Resign Date. */
function fetch_category_raw_rows($conn, $pid, $cat, $period_start, $emp_profile_select, $include_recent_exits = false) {
    $excl_sql = excluded_employee_sql($period_start);
    $ps = mysqli_real_escape_string($conn, $period_start);
    $eligible_sql = $include_recent_exits
        ? "((e.active = 1 AND NOT $excl_sql) OR (e.exit_date IS NOT NULL AND e.exit_date >= '$ps'))"
        : "(e.active = 1 AND NOT $excl_sql)";
    $extra_sel = "e.date_of_join AS emp_doj, e.exit_date AS emp_exit_date";
    if ($cat === 'ST' || $cat === 'OFF') {
        $table = $cat === 'ST' ? 'st_salary_sheet_entries' : 'off_salary_sheet_entries';
        $sql = "SELECT sse.*, $extra_sel, $emp_profile_select FROM $table sse
                JOIN employees e ON e.id = sse.employee_id
                WHERE sse.payroll_period_id = $pid AND $eligible_sql
                ORDER BY sse.company_code, sse.employee_code";
    } else {
        $catE = mysqli_real_escape_string($conn, $cat);
        $sql = "SELECT sse.*, $extra_sel, $emp_profile_select FROM salary_sheet_entries sse
                JOIN employees e ON e.id = sse.employee_id
                WHERE sse.payroll_period_id = $pid AND sse.category_code = '$catE'
                  AND $eligible_sql
                ORDER BY sse.company_code, sse.employee_code";
    }
    $res = mysqli_query($conn, $sql);
    $rows = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    return $rows;
}

/** Loads {employee_id => map_recon_row} for one period, across the selected
 *  categories — a flat associative map keyed by employee_id so Recruitment/
 *  Resignation/NOPAY can be found with simple isset() lookups regardless of
 *  which category the employee is filed under. */
function load_recon_period_data($conn, $pid, array $cats, $period_start, $include_recent_exits = false) {
    $emp_profile_select = employee_profile_select_sql($conn);
    $out = [];
    foreach ($cats as $cat) {
        $rows = fetch_category_raw_rows($conn, $pid, $cat, $period_start, $emp_profile_select, $include_recent_exits);
        foreach ($rows as $r) {
            $eid = (int)($r['employee_id'] ?? 0);
            $out[$eid] = map_recon_row($r, $cat);
        }
    }
    return $out;
}

/** Finds the payroll_periods row for the calendar month immediately before
 *  $period (handles December -> January year rollover). Returns null if no
 *  such period has been created yet. */
function find_previous_period($conn, $period) {
    $y = (int)$period['year']; $m = (int)$period['month'];
    $m--; if ($m < 1) { $m = 12; $y--; }
    $res = mysqli_query($conn, "SELECT * FROM payroll_periods WHERE year=$y AND month=$m LIMIT 1");
    return $res ? mysqli_fetch_assoc($res) : null;
}

/** Staff Loan section: pulls every 'Active' row from the new staff_loans
 *  ledger (see the data-source-gap note at the top of this file), and
 *  resolves each loan's display name/category from whichever period still
 *  has that employee's salary-sheet row (current period first, else the
 *  previous period), so a loan for someone who has since resigned still
 *  shows a name instead of just an ID. */
function fetch_staff_loans($conn, array $curData, array $prevData) {
    $out = [];
    $res = @mysqli_query($conn, "SELECT * FROM staff_loans WHERE status='Active' ORDER BY employee_id");
    if (!$res) return $out;
    while ($r = mysqli_fetch_assoc($res)) {
        $eid = (int)$r['employee_id'];
        $ref = $curData[$eid] ?? $prevData[$eid] ?? null;
        $out[] = [
            'employee_id'      => $eid,
            'emp_code'         => $ref['emp_code'] ?? '',
            'name'             => $ref['name'] ?? ('Employee #' . $eid),
            'category'         => $ref['category'] ?? ($r['category_code'] ?? ''),
            'loan_amount'      => round(floatval($r['loan_amount']), 2),
            'recovered_amount' => round(floatval($r['recovered_amount']), 2),
            'balance'          => round(floatval($r['loan_amount']) - floatval($r['recovered_amount']), 2),
        ];
    }
    return $out;
}

/** Core reconciliation: compares $curData vs $prevData (both keyed by
 *  employee_id, from load_recon_period_data()) and returns the four
 *  sections the sample workbook shows. Each Recruitment/Resignation/NOPAY
 *  row now also carries a 'date' field ('' when unknown):
 *    Recruitment -> Join Date (employees.date_of_join / sheet snapshot)
 *    Resignation -> Resign Date (employees.exit_date)
 *    NOPAY       -> always '' (no date applies)
 *
 *  $cur_period_start / $cur_period_end (both 'YYYY-MM-DD', from
 *  period_start_date()/period_end_date() for the CURRENT period) restrict:
 *    - Recruitment: to employees whose Date of Join falls inside the
 *      selected month (see top-of-file note), and
 *    - Resignation: to employees on last month's sheet whose exit_date
 *      falls inside the selected month — even if they still appear on this
 *      month's sheet with a partial salary. Employees who vanished from the
 *      current sheet with NO exit_date saved are still listed as a
 *      fallback (blank date), so nobody silently disappears.
 *  Pass null for both to disable the date filters entirely and fall back
 *  to the plain appeared/disappeared sheet diff (legacy behaviour). */
function build_basic_salary_reconciliation(array $curData, array $prevData, $cur_period_start = null, $cur_period_end = null) {
    $prev_total = 0.0;
    foreach ($prevData as $r) $prev_total += $r['basic'];

    $dates_on = ($cur_period_start !== null && $cur_period_end !== null);

    // Recruitment during the month. An employee counts as a new recruit if
    // EITHER:
    //   (a) their Date of Join falls inside the SELECTED month — this is the
    //       primary rule the user wants ("select month have date of join").
    //       We include them even if a stray row for them also exists on last
    //       month's sheet (e.g. an advance/onboarding row), because the
    //       employee record itself says they joined this month; OR
    //   (b) [fallback] their Date of Join is unknown/missing but they are
    //       genuinely new to this month's sheet (not on last month's), so a
    //       real headcount addition still surfaces (with a blank Join Date).
    // Join Date shown per row.
    $recruitment = [];
    foreach ($curData as $eid => $r) {
        $doj = norm_recon_date($r['doj'] ?? null);
        $joined_this_month = $dates_on && $doj !== '' && $doj >= $cur_period_start && $doj <= $cur_period_end;
        $new_to_sheet      = !isset($prevData[$eid]);

        if ($doj !== '') {
            // Date of Join is known → it is the single source of truth. Only
            // list them if that join date is inside the selected month.
            if (!$joined_this_month) continue;
        } else {
            // Date of Join unknown → fall back to the sheet diff so we don't
            // silently hide a genuine new joiner whose DOJ simply wasn't saved.
            if (!$new_to_sheet) continue;
        }
        $recruitment[] = [
            'emp_code' => $r['emp_code'], 'name' => $r['name'], 'category' => $r['category'],
            'date'     => fmt_recon_date($doj),
            'amount'   => $r['basic'],
        ];
    }

    // Resignation = employees on LAST month's sheet whose exit_date falls
    // inside THIS month (Resign Date shown), plus — as a fallback — anyone
    // who dropped off the current sheet without an exit_date ever being
    // saved (blank date), so legacy records still surface.
    $resignation = [];
    foreach ($prevData as $eid => $r) {
        $exit = norm_recon_date($r['exit_date'] ?? null);
        $exited_this_month = $dates_on && $exit !== '' && $exit >= $cur_period_start && $exit <= $cur_period_end;
        $dropped_off_sheet = !isset($curData[$eid]);
        // Include if EITHER: their exit_date lands in this month (even if they
        // still drew a partial salary and remain on the current sheet), OR they
        // simply no longer appear on this month's sheet at all (regardless of
        // whether an exit_date was ever saved — this is the headcount-drop
        // fallback, so real leavers never show "(none)").
        $include = $exited_this_month || $dropped_off_sheet;
        if (!$include) continue;
        $resignation[] = [
            'emp_code' => $r['emp_code'], 'name' => $r['name'], 'category' => $r['category'],
            'date'     => $exit !== '' ? fmt_recon_date($exit) : '',
            'amount'   => -$r['basic'],
        ];
    }

    $nopay = [];
    foreach ($curData as $eid => $r) {
        if (isset($prevData[$eid]) && abs($r['no_pay']) > 0.004) {
            $nopay[] = [
                'emp_code' => $r['emp_code'], 'name' => $r['name'], 'category' => $r['category'],
                'date'     => '',
                'amount'   => -abs($r['no_pay']),
            ];
        }
    }

    $recruitment_total = array_sum(array_column($recruitment, 'amount'));
    $resignation_total = array_sum(array_column($resignation, 'amount'));
    $nopay_total       = array_sum(array_column($nopay, 'amount'));
    $formula_total     = round($prev_total + $recruitment_total + $resignation_total + $nopay_total, 2);

    // Ground-truth total: the actual SUM(basic) of this period's saved
    // salary-sheet rows. Always shown as "Total Basic Salary as of <date>"
    // so the bottom line never drifts, even though the Recruitment /
    // Resignation lists above are date-driven rather than a raw sheet diff.
    $actual_total = 0.0;
    foreach ($curData as $r) $actual_total += $r['basic'];
    $actual_total = round($actual_total, 2);

    return [
        'prev_total'        => round($prev_total, 2),
        'recruitment'       => $recruitment,
        'resignation'       => $resignation,
        'nopay'             => $nopay,
        'recruitment_total' => round($recruitment_total, 2),
        'resignation_total' => round($resignation_total, 2),
        'nopay_total'       => round($nopay_total, 2),
        'current_total'     => $actual_total,
        'formula_total'     => $formula_total,
        'variance'          => round($actual_total - $formula_total, 2),
    ];
}

// =========================================================================
//  MINI XLSX WRITER — identical structure to salary_excel_generate.php's own
//  writer (so the download always produces a valid, Excel-openable .xlsx),
//  extended here with genuine Excel fill+font "status" styles (green / red
//  / yellow) applied ONLY to the detail data rows — section titles, column
//  headers, totals and everything else stay in the workbook's normal
//  black/white professional look.
// =========================================================================
class MiniXLSXWriter {
    private $sheets = [];
    // Style IDs match Salary_Rec.xlsx's own "1-Basic Salary Rec" tab cell-for-cell:
    // Calibri 11 throughout, accounting number format with no decimals
    // (_(* #,##0_);_(* (#,##0);_(* "-"??_);_(@_) — parenthesised negatives,
    // dash for zero), bold+bordered "Total Basic Salary as of ..." rows
    // (thin top / double bottom), and bold+boxed "Staff Loan" /
    // "Unrecovered Balance" headings (thin top+bottom, left aligned).
    const STYLE_DEFAULT     = 0; // plain text/number, no border
    const STYLE_TITLE       = 1; // bold, centered, thin top+bottom border (main title)
    const STYLE_TEXT_BOLD   = 2; // bold text, no border (section headings, column headers)
    const STYLE_NUM         = 3; // accounting number format, no border
    const STYLE_NUM_BOLD    = 4; // accounting number format, bold, thin-top/double-bottom border (total rows)
    const STYLE_TOTAL_LABEL = 5; // bold text, thin-top/double-bottom border (total row label cell)
    const STYLE_BOXED_BOLD  = 6; // bold text, left aligned, thin top+bottom border (Staff Loan / Unrecovered Balance)
    const STYLE_CENTER      = 7; // plain text, centered (Category / date columns)
    const STYLE_HDR_UL      = 20; // bold text, thin bottom border (column-header rows — clean underline, NO fill)
    const STYLE_HDR_UL_CTR  = 21; // as above, centered (Category / date header cells)

    // ── Color-coded "status" styles (Excel-style Good/Bad/Neutral fills) ──
    // Applied ONLY to detail data rows: Recruitment (green), Resignation
    // (red), NOPAY (yellow). Headers/titles never use these.
    const STYLE_TEXT_GREEN   = 8;  // recruitment — plain text on green fill
    const STYLE_NUM_GREEN    = 9;  // recruitment — accounting number on green fill
    const STYLE_CENTER_GREEN = 10; // recruitment — centered text on green fill

    const STYLE_TEXT_RED   = 12; // resignation — plain text on red fill
    const STYLE_NUM_RED    = 13; // resignation — accounting number on red fill
    const STYLE_CENTER_RED = 14; // resignation — centered text on red fill

    const STYLE_TEXT_YELLOW   = 16; // NOPAY — plain text on yellow fill
    const STYLE_NUM_YELLOW    = 17; // NOPAY — accounting number on yellow fill
    const STYLE_CENTER_YELLOW = 18; // NOPAY — centered text on yellow fill

    public function addSheet($name) {
        $this->sheets[] = ['name' => $this->sanitizeName($name), 'rows' => [], 'merges' => [], 'widths' => [], 'freeze' => 0];
        return count($this->sheets) - 1;
    }
    private function sanitizeName($name) {
        $name = preg_replace('/[\\\\\/\?\*\[\]:]/', '', $name);
        return function_exists('mb_substr') ? mb_substr($name, 0, 31) : substr($name, 0, 31);
    }
    public function setWidths($idx, array $w) { $this->sheets[$idx]['widths'] = $w; }
    public function freeze($idx, $rows)       { $this->sheets[$idx]['freeze'] = $rows; }
    public function merge($idx, $ref)         { $this->sheets[$idx]['merges'][] = $ref; }
    public function addRow($idx, array $cells){ $this->sheets[$idx]['rows'][] = $cells; }

    private function colLetter($i) {
        $i++; $s = '';
        while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); }
        return $s;
    }
    private function esc($s) {
        $s = (string)$s;
        if (function_exists('mb_check_encoding') && !mb_check_encoding($s, 'UTF-8')) {
            $s = function_exists('mb_convert_encoding') ? mb_convert_encoding($s, 'UTF-8', 'UTF-8') : preg_replace('/[\x80-\xFF]/', '', $s);
        }
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    }

    private function sheetXml($sheet) {
        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $out .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($sheet['freeze'] > 0) {
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
            // Accounting format, no decimals, parenthesised negatives, dash for zero —
            // identical to the numFmt actually used in Salary_Rec.xlsx's amount columns.
            . '<numFmts count="1">'
            . '<numFmt numFmtId="164" formatCode="_(* #,##0_);_(* \(#,##0\);_(* &quot;-&quot;??_);_(@_)"/>'
            . '</numFmts>'
            . '<fonts count="8">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'                                   // 0 regular black
            . '<font><sz val="11"/><b/><name val="Calibri"/></font>'                                // 1 bold black
            . '<font><sz val="11"/><color rgb="FF006100"/><name val="Calibri"/></font>'             // 2 regular green
            . '<font><sz val="11"/><b/><color rgb="FF006100"/><name val="Calibri"/></font>'         // 3 bold green (kept for compat)
            . '<font><sz val="11"/><color rgb="FF9C0006"/><name val="Calibri"/></font>'             // 4 regular red
            . '<font><sz val="11"/><b/><color rgb="FF9C0006"/><name val="Calibri"/></font>'         // 5 bold red (kept for compat)
            . '<font><sz val="11"/><color rgb="FF9C6500"/><name val="Calibri"/></font>'             // 6 regular amber (yellow)
            . '<font><sz val="11"/><b/><color rgb="FF9C6500"/><name val="Calibri"/></font>'         // 7 bold amber (kept for compat)
            . '</fonts>'
            . '<fills count="5">'
            . '<fill><patternFill patternType="none"/></fill>'                                                                   // 0 none
            . '<fill><patternFill patternType="gray125"/></fill>'                                                                 // 1 default
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFC6EFCE"/><bgColor indexed="64"/></patternFill></fill>'      // 2 green (Recruitment data rows)
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFC7CE"/><bgColor indexed="64"/></patternFill></fill>'      // 3 red (Resignation data rows)
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFEB9C"/><bgColor indexed="64"/></patternFill></fill>'      // 4 yellow (NOPAY data rows)
            . '</fills>'
            . '<borders count="4">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'                                   // 0 none
            . '<border><left/><right/><top style="thin"/><bottom style="thin"/><diagonal/></border>'          // 1 thin top+bottom (title / boxed headings)
            . '<border><left/><right/><top style="thin"/><bottom style="double"/><diagonal/></border>'        // 2 thin top / double bottom (total rows)
            . '<border><left/><right/><top/><bottom style="thin"/><diagonal/></border>'                       // 3 thin bottom only (column-header underline)
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="22">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                                          // 0 default
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>' // 1 title
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                            // 2 bold text, no border
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'                                                  // 3 accounting number
            . '<xf numFmtId="164" fontId="1" fillId="0" borderId="2" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1"/>'                    // 4 accounting number bold, total border
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="2" xfId="0" applyFont="1" applyBorder="1"/>'                                            // 5 bold text, total border
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left"/></xf>' // 6 bold text, boxed, left aligned
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>'                    // 7 plain text, centered
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'                                              // 8 text green
            . '<xf numFmtId="164" fontId="2" fillId="2" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'                      // 9 num green
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>' // 10 center green
            . '<xf numFmtId="0" fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'                                              // 11 header green (unused — kept so style IDs stay stable)
            . '<xf numFmtId="0" fontId="4" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'                                              // 12 text red
            . '<xf numFmtId="164" fontId="4" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'                      // 13 num red
            . '<xf numFmtId="0" fontId="4" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>' // 14 center red
            . '<xf numFmtId="0" fontId="5" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'                                              // 15 header red (unused — kept so style IDs stay stable)
            . '<xf numFmtId="0" fontId="6" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'                                              // 16 text yellow
            . '<xf numFmtId="164" fontId="6" fillId="4" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'                      // 17 num yellow
            . '<xf numFmtId="0" fontId="6" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>' // 18 center yellow
            . '<xf numFmtId="0" fontId="7" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'                                              // 19 header yellow (unused — kept so style IDs stay stable)
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="3" xfId="0" applyFont="1" applyBorder="1"/>'                                            // 20 bold text, thin bottom border (column headers)
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="3" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>' // 21 bold centered, thin bottom border
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    public function output($filename) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        @ini_set('display_errors', '0');
        if (!class_exists('ZipArchive')) {
            $this->fail('The PHP "zip" extension is not enabled on this server, so the Excel file cannot be built. Please ask your host to enable php-zip.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        @unlink($tmp);
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
        while (ob_get_level() > 0) { @ob_end_clean(); }
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

function colRef($i) {
    $i++; $s = '';
    while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); }
    return $s;
}

/** Writes one "Emp Code / Name / <Date> / Category / Amount" section
 *  (Recruitment / Resignation / NOPAY). Column layout follows the sample
 *  workbook (col B=Emp Code, C=Name, F=Category, G=Amount), with the
 *  previously-blank col D now carrying the Join Date / Resign Date.
 *
 *  PROFESSIONAL STYLING: the section title and column-header row are plain
 *  bold black with a thin underline — NO fill. ONLY the detail data rows
 *  get the status fill ($color = 'green' Recruitment / 'red' Resignation /
 *  'yellow' NOPAY / 'none' plain), so the workbook reads clean with just
 *  the details color-coded.
 *
 *  $dateLabel is the header caption for col D ('Join Date', 'Resign Date',
 *  or '' to leave the column caption blank as in NOPAY). */
function write_recon_section(MiniXLSXWriter $xl, $idx, $sectionTitle, array $rows, $color = 'none', $dateLabel = '') {
    $styles = [
        'green'  => ['text' => MiniXLSXWriter::STYLE_TEXT_GREEN,  'num' => MiniXLSXWriter::STYLE_NUM_GREEN,  'center' => MiniXLSXWriter::STYLE_CENTER_GREEN],
        'red'    => ['text' => MiniXLSXWriter::STYLE_TEXT_RED,    'num' => MiniXLSXWriter::STYLE_NUM_RED,    'center' => MiniXLSXWriter::STYLE_CENTER_RED],
        'yellow' => ['text' => MiniXLSXWriter::STYLE_TEXT_YELLOW, 'num' => MiniXLSXWriter::STYLE_NUM_YELLOW, 'center' => MiniXLSXWriter::STYLE_CENTER_YELLOW],
        'none'   => ['text' => MiniXLSXWriter::STYLE_DEFAULT,     'num' => MiniXLSXWriter::STYLE_NUM,        'center' => MiniXLSXWriter::STYLE_CENTER],
    ];
    $sty = $styles[$color] ?? $styles['none'];

    // Section title — plain bold black, no fill.
    $xl->addRow($idx, [['', 's', 0], [$sectionTitle, 's', MiniXLSXWriter::STYLE_TEXT_BOLD]]);
    // Column headers — plain bold black with a thin underline, no fill.
    $xl->addRow($idx, [
        ['', 's', 0],
        ['Emp Code', 's', MiniXLSXWriter::STYLE_HDR_UL],
        ['Name', 's', MiniXLSXWriter::STYLE_HDR_UL],
        [$dateLabel, 's', MiniXLSXWriter::STYLE_HDR_UL_CTR],
        ['', 's', MiniXLSXWriter::STYLE_HDR_UL],
        ['Category', 's', MiniXLSXWriter::STYLE_HDR_UL_CTR],
        ['Amount', 's', MiniXLSXWriter::STYLE_HDR_UL],
    ]);
    // Data rows — the ONLY colored rows in the section.
    foreach ($rows as $r) {
        $xl->addRow($idx, [
            ['', 's', 0],
            [$r['emp_code'], 's', $sty['text']],
            [$r['name'], 's', $sty['text']],
            [$r['date'] ?? '', 's', $sty['center']],
            ['', 's', $sty['text']],
            [$r['category'], 's', $sty['center']],
            [round($r['amount'], 2), 'n', $sty['num']],
        ]);
    }
    if (empty($rows)) {
        $xl->addRow($idx, [['', 's', 0], ['(none)', 's', 0]]);
    }
    $xl->addRow($idx, [['', 's', 0]]); // spacer
}

// =========================================================================
//  EXPORT HANDLER — must run before ANY HTML output
// =========================================================================
$action = $_GET['do'] ?? '';
if ($action === 'export') {
    $pid  = intval($_GET['period_id'] ?? 0);
    $cats = isset($_GET['cats']) && is_array($_GET['cats']) ? array_values(array_intersect($_GET['cats'], ALL_CATEGORIES)) : ALL_CATEGORIES;
    if (empty($cats)) $cats = ALL_CATEGORIES;

    $period = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM payroll_periods WHERE id=$pid"));
    if (!$pid || !$period) { http_response_code(400); echo 'Invalid payroll period.'; exit; }
    $prevPeriod = find_previous_period($conn, $period);
    if (!$prevPeriod) { http_response_code(400); echo 'No prior month payroll period found to reconcile against — create/generate last month\'s salary sheet first.'; exit; }

    // Current period: eligibility identical to salary_excel_generate.php.
    // Previous period: also admits recently-exited employees (see note in
    // fetch_category_raw_rows) so this month's resignations are visible.
    $curData  = load_recon_period_data($conn, $pid, $cats, period_start_date($period), false);
    $prevData = load_recon_period_data($conn, $prevPeriod['id'], $cats, period_start_date($prevPeriod), true);
    $rec      = build_basic_salary_reconciliation($curData, $prevData, period_start_date($period), period_end_date($period));
    $loans    = fetch_staff_loans($conn, $curData, $prevData);

    $xl = new MiniXLSXWriter();
    $idx = $xl->addSheet('1-Basic Salary Rec');
    // B=Emp Code, C=Name, D=Join/Resign Date, E=(blank, per sample), F=Category, G=Amount
    // Column C widened to fit the longest Sri Lankan names + the long title/total labels
    // (which live in col B but visually spill across B:F). Col G widened for "1,140,000".
    $xl->setWidths($idx, [3, 18, 46, 14, 4, 11, 16]);

    $label = period_label($period);
    // Title row: bold, centered, thin top+bottom border, merged B2:G2 — matches Salary_Rec.xlsx exactly.
    $xl->addRow($idx, [['', 's', 0], ['Yelo Logistic - Salary Reconciliation for the month of ' . $label, 's', MiniXLSXWriter::STYLE_TITLE], ['', 's', MiniXLSXWriter::STYLE_TITLE], ['', 's', MiniXLSXWriter::STYLE_TITLE], ['', 's', MiniXLSXWriter::STYLE_TITLE], ['', 's', MiniXLSXWriter::STYLE_TITLE], ['', 's', MiniXLSXWriter::STYLE_TITLE]]);
    $xl->merge($idx, 'B2:G2');
    $xl->addRow($idx, [['', 's', 0]]);

    // "Total Basic Salary as of Last Month" — bold, thin-top/double-bottom border on
    // BOTH the label and the value cell (matches B4/G4 in the sample).
    $xl->addRow($idx, [['', 's', 0], ['Total Basic Salary as of Last Month', 's', MiniXLSXWriter::STYLE_TOTAL_LABEL], ['', 's', MiniXLSXWriter::STYLE_TOTAL_LABEL], ['', 's', MiniXLSXWriter::STYLE_TOTAL_LABEL], ['', 's', MiniXLSXWriter::STYLE_TOTAL_LABEL], ['', 's', MiniXLSXWriter::STYLE_TOTAL_LABEL], [$rec['prev_total'], 'n', MiniXLSXWriter::STYLE_NUM_BOLD]]);
    $xl->addRow($idx, [['', 's', 0]]);

    // Only the detail data rows inside each section are colored — titles/headers stay plain.
    write_recon_section($xl, $idx, 'Recruitment during the month', $rec['recruitment'], 'green', 'Join Date');
    write_recon_section($xl, $idx, 'Resignation during the month', $rec['resignation'], 'red', 'Resign Date');
    write_recon_section($xl, $idx, 'NOPAY', $rec['nopay'], 'yellow', '');

    $xl->addRow($idx, [['', 's', 0], ['Total Basic Salary as of ' . date('jS F Y', strtotime(period_end_date($period))), 's', MiniXLSXWriter::STYLE_TOTAL_LABEL], ['', 's', MiniXLSXWriter::STYLE_TOTAL_LABEL], ['', 's', MiniXLSXWriter::STYLE_TOTAL_LABEL], ['', 's', MiniXLSXWriter::STYLE_TOTAL_LABEL], ['', 's', MiniXLSXWriter::STYLE_TOTAL_LABEL], [$rec['current_total'], 'n', MiniXLSXWriter::STYLE_NUM_BOLD]]);
    if (abs($rec['variance']) > 0.004) {
        // Only appears if the date-driven Recruitment/Resignation lists cause
        // the addition-based total to differ from the actual current-period
        // sum — makes the discrepancy visible instead of hiding it.
        $xl->addRow($idx, [['', 's', 0], ['Reconciliation check (addition-based total vs. actual, should be 0):', 's', MiniXLSXWriter::STYLE_TEXT_BOLD], ['', 's', 0], ['', 's', 0], ['', 's', 0], ['', 's', 0], [$rec['variance'], 'n', MiniXLSXWriter::STYLE_NUM]]);
    }
    $xl->addRow($idx, [['', 's', 0]]);
    $xl->addRow($idx, [['', 's', 0]]);

    // ── Staff Loan — bold, boxed (thin top+bottom border), left aligned, matching
    //    the sample's B30/B36 heading style exactly. Columns follow the sample:
    //    B=Emp Code, C=Name, D=Category, E=Loan Amount, F=Recovered, G=Balance. ──
    $xl->addRow($idx, [['', 's', 0], ['Staff Loan', 's', MiniXLSXWriter::STYLE_BOXED_BOLD], ['', 's', MiniXLSXWriter::STYLE_BOXED_BOLD], ['', 's', MiniXLSXWriter::STYLE_BOXED_BOLD], ['', 's', MiniXLSXWriter::STYLE_BOXED_BOLD], ['', 's', MiniXLSXWriter::STYLE_BOXED_BOLD], ['', 's', MiniXLSXWriter::STYLE_BOXED_BOLD]]);
    $xl->addRow($idx, [['', 's', 0]]);
    $xl->addRow($idx, [['', 's', 0], ['Emp Code', 's', MiniXLSXWriter::STYLE_HDR_UL], ['Name', 's', MiniXLSXWriter::STYLE_HDR_UL], ['Category', 's', MiniXLSXWriter::STYLE_HDR_UL_CTR], ['Loan Amount', 's', MiniXLSXWriter::STYLE_HDR_UL], ['Recovered', 's', MiniXLSXWriter::STYLE_HDR_UL], ['Balance', 's', MiniXLSXWriter::STYLE_HDR_UL]]);
    foreach ($loans as $l) {
        $xl->addRow($idx, [
            ['', 's', 0], [$l['emp_code'], 's', 0], [$l['name'], 's', 0], [$l['category'], 's', MiniXLSXWriter::STYLE_CENTER],
            [$l['loan_amount'], 'n', MiniXLSXWriter::STYLE_NUM], [$l['recovered_amount'], 'n', MiniXLSXWriter::STYLE_NUM], [$l['balance'], 'n', MiniXLSXWriter::STYLE_NUM],
        ]);
    }
    if (empty($loans)) {
        $xl->addRow($idx, [['', 's', 0], ['(no active loans recorded in staff_loans yet)', 's', 0]]);
    }
    $xl->addRow($idx, [['', 's', 0]]);
    $xl->addRow($idx, [['', 's', 0]]);

    // ── Unrecovered Balance (loans with Balance > 0) — same boxed heading + layout. ──
    $unrecovered = array_values(array_filter($loans, fn($l) => $l['balance'] > 0.004));
    $xl->addRow($idx, [['', 's', 0], ['Unrecovered Balance', 's', MiniXLSXWriter::STYLE_BOXED_BOLD], ['', 's', MiniXLSXWriter::STYLE_BOXED_BOLD], ['', 's', MiniXLSXWriter::STYLE_BOXED_BOLD], ['', 's', MiniXLSXWriter::STYLE_BOXED_BOLD], ['', 's', MiniXLSXWriter::STYLE_BOXED_BOLD], ['', 's', MiniXLSXWriter::STYLE_BOXED_BOLD]]);
    $xl->addRow($idx, [['', 's', 0]]);
    $xl->addRow($idx, [['', 's', 0], ['Emp Code', 's', MiniXLSXWriter::STYLE_HDR_UL], ['Name', 's', MiniXLSXWriter::STYLE_HDR_UL], ['Category', 's', MiniXLSXWriter::STYLE_HDR_UL_CTR], ['Loan Amount', 's', MiniXLSXWriter::STYLE_HDR_UL], ['Recovered', 's', MiniXLSXWriter::STYLE_HDR_UL], ['Balance', 's', MiniXLSXWriter::STYLE_HDR_UL]]);
    foreach ($unrecovered as $l) {
        $xl->addRow($idx, [
            ['', 's', 0], [$l['emp_code'], 's', 0], [$l['name'], 's', 0], [$l['category'], 's', MiniXLSXWriter::STYLE_CENTER],
            [$l['loan_amount'], 'n', MiniXLSXWriter::STYLE_NUM], [$l['recovered_amount'], 'n', MiniXLSXWriter::STYLE_NUM], [$l['balance'], 'n', MiniXLSXWriter::STYLE_NUM],
        ]);
    }
    if (empty($unrecovered)) {
        $xl->addRow($idx, [['', 's', 0], ['(none)', 's', 0]]);
    }

    $fname = 'Salary_Reconciliation_' . str_replace(' ', '_', $label) . '.xlsx';
    $xl->output($fname);
    exit;
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
$prev_period = $active_period ? find_previous_period($conn, $active_period) : null;

$sel_cats = isset($_GET['cats']) && is_array($_GET['cats']) ? array_values(array_intersect($_GET['cats'], ALL_CATEGORIES)) : ALL_CATEGORIES;
if (empty($sel_cats)) $sel_cats = ALL_CATEGORIES;

/** Renders a number the same way the workbook's accounting number format
 *  displays it: thousands separators, no decimals, negatives in parentheses,
 *  a bare dash for exactly zero — purely cosmetic, matches
 *  _(* #,##0_);_(* (#,##0);_(* "-"??_);_(@_) visually. */
function fmt_acc($n) {
    $n = round(floatval($n));
    if ($n == 0) return '-';
    if ($n < 0) return '(' . number_format(abs($n)) . ')';
    return number_format($n);
}

$preview = null;
$curData = [];
$prevData = [];
if ($active_period && $prev_period) {
    $curData  = load_recon_period_data($conn, $sel_period_id, $sel_cats, period_start_date($active_period), false);
    $prevData = load_recon_period_data($conn, $prev_period['id'], $sel_cats, period_start_date($prev_period), true);
    $preview  = build_basic_salary_reconciliation($curData, $prevData, period_start_date($active_period), period_end_date($active_period));
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
.wrap{max-width:1080px;margin:0 auto;padding:0 20px 40px;}
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
.actions{display:flex;gap:10px;margin-top:18px;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:var(--r);font-size:13px;font-weight:700;font-family:var(--sans);cursor:pointer;border:none;text-decoration:none;transition:all .15s;}
.btn-dark{background:var(--accent);color:#fff;}.btn-dark:hover{background:#333;}
.btn-green{background:#22c55e;color:#fff;}.btn-green:hover{background:#16a34a;}
.note{font-size:12px;color:var(--ink3);margin-top:10px;line-height:1.5;}
.warn{background:#fef3c7;border:1px solid #fde68a;color:#92400e;border-radius:var(--r);padding:12px 14px;font-size:13px;margin-bottom:16px;}

/* ── Spreadsheet-style preview — visually mirrors the downloaded workbook:
     plain bold black section titles + underlined header rows, and ONLY
     the detail data rows carrying the green / red / yellow status fills. ── */
.sheet{background:#fff;border:1px solid var(--border2);border-radius:8px;padding:26px 30px;font-size:13px;color:#111;overflow-x:auto;}
.sheet-title{font-weight:700;text-align:center;font-size:14px;padding:8px 0;border-top:1px solid #333;border-bottom:1px solid #333;margin-bottom:18px;}
.sheet-total-row{display:flex;justify-content:space-between;font-weight:700;padding:8px 4px;border-top:1px solid #333;border-bottom:3px double #333;margin:10px 0 18px;}
.sheet-variance{font-size:11.5px;color:#9c0006;text-align:right;margin:-14px 0 18px;font-weight:600;}
.sheet-section{margin:20px 0 6px;font-weight:700;}
.sheet-section.boxed{border-top:1px solid #333;border-bottom:1px solid #333;padding:6px 4px;margin-top:26px;}
table.sheet-tbl{width:100%;border-collapse:collapse;margin-bottom:6px;}
table.sheet-tbl th{text-align:left;font-weight:700;padding:4px 8px;font-size:12.5px;border-bottom:1px solid #333;}
table.sheet-tbl th.blank{border-bottom:none;}
table.sheet-tbl td{padding:4px 8px;font-size:12.5px;}
table.sheet-tbl td.num,table.sheet-tbl th.num{text-align:right;font-variant-numeric:tabular-nums;}
table.sheet-tbl td.ctr,table.sheet-tbl th.ctr{text-align:center;}
table.sheet-tbl tr.empty td{color:var(--ink3);font-style:italic;}
tr.data-row.sec-green td{background:#c6efce;color:#006100;}
tr.data-row.sec-red td{background:#ffc7ce;color:#9c0006;}
tr.data-row.sec-yellow td{background:#ffeb9c;color:#9c6500;}
.legend{display:flex;gap:16px;flex-wrap:wrap;margin:10px 0 4px;font-size:11.5px;font-weight:600;}
.legend span{display:inline-flex;align-items:center;gap:6px;}
.legend i{width:12px;height:12px;border-radius:3px;display:inline-block;}
.legend .lg-green i{background:#c6efce;border:1px solid #006100;}
.legend .lg-red i{background:#ffc7ce;border:1px solid #9c0006;}
.legend .lg-yellow i{background:#ffeb9c;border:1px solid #9c6500;}
</style>

<div class="pg-hdr">
    <div>
        <div class="pg-hdr-title"><i class="fa-solid fa-scale-balanced"></i> Salary Reconciliation</div>
        <div class="pg-hdr-sub">Total Basic Salary reconciliation vs. the previous payroll month — Recruitment (by Join Date), Resignation (by Resign Date), NOPAY, and Staff Loan, built from the same live-resolved data as the Salary Sheet export.</div>
    </div>
</div>

<div class="wrap">
    <form method="GET" id="genForm">
        <div class="card">
            <div class="card-title"><i class="fa-solid fa-sliders"></i> Select Month &amp; Categories</div>
            <div class="form-grid">
                <div class="field">
                    <label>Payroll Period (current month)</label>
                    <select name="period_id" onchange="document.getElementById('genForm').submit()">
                        <?php foreach ($all_periods as $pp): ?>
                            <option value="<?php echo $pp['id']; ?>" <?php echo $pp['id']==$sel_period_id?'selected':''; ?>>
                                <?php echo date('F Y', strtotime($pp['year'].'-'.$pp['month'].'-01')); ?>
                                <?php echo $pp['status']==='Open' ? ' (Open)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="note">
                        Compared against
                        <?php echo $prev_period ? '<strong>'.date('F Y', strtotime($prev_period['year'].'-'.$prev_period['month'].'-01')).'</strong>' : '<strong>no prior period found</strong>'; ?>
                        automatically. Recruitment lists employees whose <strong>Date of Join</strong> falls inside
                        <?php echo $active_period ? '<strong>'.period_label($active_period).'</strong>' : 'the selected month'; ?>;
                        Resignation lists employees whose <strong>Exit Date</strong> falls inside it — both dates are shown on the rows.
                    </div>
                </div>
                <div class="field">
                    <label>Categories to Include</label>
                    <div class="cat-grid">
                        <?php foreach (ALL_CATEGORIES as $cat): $on = in_array($cat, $sel_cats); ?>
                        <label class="cat-chip <?php echo $on?'on':''; ?>">
                            <input type="checkbox" name="cats[]" value="<?php echo $cat; ?>" <?php echo $on?'checked':''; ?>
                                onchange="document.getElementById('genForm').submit()">
                            <div class="code"><?php echo $cat; ?></div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="actions">
                <button type="submit" name="do" value="preview" class="btn btn-dark"><i class="fa-solid fa-arrows-rotate"></i> Refresh Preview</button>
                <button type="submit" name="do" value="export" formtarget="_blank" class="btn btn-green" <?php echo $prev_period?'':'disabled'; ?>><i class="fa-solid fa-download"></i> Generate &amp; Download Excel (.xlsx)</button>
            </div>
        </div>
    </form>

    <?php if (!$prev_period && $active_period): ?>
        <div class="warn"><i class="fa-solid fa-triangle-exclamation"></i> No payroll period exists for the month before <?php echo period_label($active_period); ?> yet, so there's nothing to reconcile against. Create/generate last month's salary sheet first.</div>
    <?php endif; ?>

    <?php
    // Loans are also needed for the preview (the export handler builds these
    // independently, so the on-screen preview and the downloaded file always
    // show the same thing).
    $preview_loans = [];
    if ($preview) $preview_loans = fetch_staff_loans($conn, $curData, $prevData);
    $preview_unrecovered = array_values(array_filter($preview_loans, fn($l) => $l['balance'] > 0.004));

    /** $color: '' (plain), 'green' (Recruitment), 'red' (Resignation), 'yellow' (NOPAY).
     *  Matches the downloaded .xlsx: header row plain bold + underline, ONLY the
     *  data rows carry the status fill. $dateLabel captions the date column
     *  ('Join Date' / 'Resign Date' / '' for none). */
    function render_recon_section_html($title, $rows, $color = '', $dateLabel = '') {
        $cls = $color ? ' sec-' . $color : '';
        echo '<div class="sheet-section">' . htmlspecialchars($title) . '</div>';
        echo '<table class="sheet-tbl"><thead><tr>'
            . '<th style="width:14%">Emp Code</th>'
            . '<th style="width:32%">Name</th>'
            . '<th class="ctr" style="width:13%">' . htmlspecialchars($dateLabel) . '</th>'
            . '<th class="blank" style="width:5%"></th>'
            . '<th class="ctr" style="width:10%">Category</th>'
            . '<th class="num" style="width:14%">Amount</th>'
            . '</tr></thead><tbody>';
        if (empty($rows)) {
            echo '<tr class="empty"><td colspan="6">(none)</td></tr>';
        } else {
            foreach ($rows as $r) {
                echo '<tr class="data-row' . $cls . '">'
                    . '<td>' . htmlspecialchars($r['emp_code']) . '</td>'
                    . '<td>' . htmlspecialchars($r['name']) . '</td>'
                    . '<td class="ctr">' . htmlspecialchars($r['date'] ?? '') . '</td>'
                    . '<td></td>'
                    . '<td class="ctr">' . htmlspecialchars($r['category']) . '</td>'
                    . '<td class="num">' . fmt_acc($r['amount']) . '</td>'
                    . '</tr>';
            }
        }
        echo '</tbody></table>';
    }

    function render_loans_section_html($title, $loans) {
        echo '<div class="sheet-section boxed">' . htmlspecialchars($title) . '</div>';
        echo '<table class="sheet-tbl"><thead><tr><th style="width:16%">Emp Code</th><th style="width:34%">Name</th><th class="ctr" style="width:10%">Category</th><th class="num" style="width:13%">Loan Amount</th><th class="num" style="width:13%">Recovered</th><th class="num" style="width:14%">Balance</th></tr></thead><tbody>';
        if (empty($loans)) {
            echo '<tr class="empty"><td colspan="6">(none)</td></tr>';
        } else {
            foreach ($loans as $l) {
                echo '<tr><td>' . htmlspecialchars($l['emp_code']) . '</td><td>' . htmlspecialchars($l['name']) . '</td><td class="ctr">' . htmlspecialchars($l['category']) . '</td><td class="num">' . fmt_acc($l['loan_amount']) . '</td><td class="num">' . fmt_acc($l['recovered_amount']) . '</td><td class="num">' . fmt_acc($l['balance']) . '</td></tr>';
            }
        }
        echo '</tbody></table>';
    }
    ?>

    <?php if ($preview): ?>
    <div class="card">
        <div class="card-title"><i class="fa-solid fa-table-list"></i> Preview — matches the downloaded workbook's "1-Basic Salary Rec" tab</div>

        <div class="legend">
            <span class="lg-green"><i></i> Recruitment (Join Date this month)</span>
            <span class="lg-red"><i></i> Resignation (Resign Date this month)</span>
            <span class="lg-yellow"><i></i> NOPAY</span>
        </div>

        <div class="sheet">
            <div class="sheet-title">Yelo Logistic - Salary Reconciliation for the month of <?php echo period_label($active_period); ?></div>

            <div class="sheet-total-row"><span>Total Basic Salary as of Last Month</span><span><?php echo fmt_acc($preview['prev_total']); ?></span></div>

            <?php render_recon_section_html('Recruitment during the month', $preview['recruitment'], 'green', 'Join Date'); ?>
            <?php render_recon_section_html('Resignation during the month', $preview['resignation'], 'red', 'Resign Date'); ?>
            <?php render_recon_section_html('NOPAY', $preview['nopay'], 'yellow', ''); ?>

            <div class="sheet-total-row"><span>Total Basic Salary as of <?php echo date('jS F Y', strtotime(period_end_date($active_period))); ?></span><span><?php echo fmt_acc($preview['current_total']); ?></span></div>
            <?php if (abs($preview['variance']) > 0.004): ?>
                <div class="sheet-variance">Reconciliation check (addition-based total vs. actual): <?php echo fmt_acc($preview['variance']); ?> — the date-driven Recruitment/Resignation lists don't fully explain the sheet-level movement for <?php echo period_label($active_period); ?> (e.g. a category switch, a mid-month resigner still drawing a partial salary, or a joiner whose Date of Join is outside this month).</div>
            <?php endif; ?>

            <?php render_loans_section_html('Staff Loan', $preview_loans); ?>
            <?php render_loans_section_html('Unrecovered Balance', $preview_unrecovered); ?>
        </div>
        <div class="note">
            <strong>Recruitment</strong> (green rows) = employees new to this month's salary sheet whose
            <strong>Date of Join</strong> (from the employee record) falls within <?php echo period_label($active_period); ?> —
            the Join Date is shown on each row. <strong>Resignation</strong> (red rows) = employees on last month's
            sheet whose <strong>Exit Date</strong> falls within this month — the Resign Date is shown on each row —
            plus anyone who dropped off this month's sheet without an exit date ever being saved (date left blank).
            The amount is last month's basic, in parentheses. <strong>NOPAY</strong> (yellow rows) = employees present
            in both months whose current no-pay deduction is non-zero (in parentheses).
            In the downloaded .xlsx, <strong>only these detail rows are color-filled</strong> — section titles, column
            headers and totals stay in the workbook's normal black/white professional look, exactly as in this preview.
            <br><br>
            All figures use the exact same saved salary-sheet rows and live-resolution rules as the Salary Sheet
            export, so this preview always ties back to it. The bottom total is always the actual current-month
            Basic Salary sum, so it never drifts even though Recruitment/Resignation are date-driven; if the simple
            addition (Last Month + Recruitment − Resignation − NOPAY) wouldn't land on the same number, a
            "Reconciliation check" line appears above so the gap is visible rather than hidden.
            <br><br>
            <strong>Staff Loan</strong> / <strong>Unrecovered Balance</strong> come from a new <code>staff_loans</code>
            ledger table added by this page (employee, loan amount, recovered-to-date). Nothing else in the system
            populates it yet — until loans are entered there, these sections will legitimately show as empty rather
            than guessed-at numbers.
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>