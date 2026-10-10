<?php
/**
 * salary_reconciliation_yearly.php
 * ─────────────────────────────────────────────────────────────────────────
 * "Salary Reconciliation — All Months" — the multi-month version of
 * salary_reconciliation.php. Reproduces the company's manually-maintained
 * Salary_Rec.xlsx "2- Total Salary Rec" tab layout AND its exact visual
 * style (title band, section-header bands, team-block bands, Total Earning
 * / Net Salary row shading, fonts, number formats), but with ONE COLUMN PER
 * PAYROLL PERIOD (every row in payroll_periods, oldest → newest) instead of
 * a single month, plus a running "Total" column on the right — exactly
 * like the original workbook's JAN/HC, FEB/HC ... Total column pairs.
 *
 * Every figure is pulled live from the database (salary_sheet_entries /
 * st_salary_sheet_entries / off_salary_sheet_entries, incentive_entries,
 * sr_fuel_entries, salary_advances, employees, secondary_invoice_import_details)
 * using the IDENTICAL data-resolution rules as salary_reconciliation.php /
 * print_payslips.php / the main "Generate Salary Excel" export, so all of
 * these always agree. Nothing here is a saved/static snapshot — every load
 * hits the live DB.
 *
 * Two distinct figures both involve secondary sales, matching the original
 * workbook exactly — they are NOT the same number and must not be confused:
 *   - "Secondary Sales Total" (Earnings section, no HC): the COMPANY-WIDE
 *     total month's secondary sales — SUM of final_bill_amount across all
 *     imported secondary invoices whose bill_date falls in that payroll
 *     month. Matches the original workbook's row 5.
 *   - "Secondary Sales Value" (Combined Metrics section): the Sales Team's
 *     own SSV figure (the same 'ssv' field already summed inside the Sales
 *     Team category block), surfaced again as a rollup row — matches the
 *     original workbook's row 27, which is a `SUMIF` over the team blocks,
 *     not a separate DB query.
 * Neither is computed per-employee-against-target; there is no "Target"
 * row anywhere in this report.
 *
 * HC = Head Count (number of employees in that category/period).
 *
 * This page is self-contained (does not include salary_reconciliation.php
 * or salary_excel_generate.php, to avoid function-name collisions) but
 * shares the identical data-layer logic byte-for-byte.
 * ─────────────────────────────────────────────────────────────────────────
 */

ob_start();
include 'config.php';

// ── Category registry ────────────────────────────────────────────────────
const ALL_CATEGORIES = ['SR', 'CC', 'ACC', 'MR', 'ST', 'OFF'];
const RECON_ORDER     = ['SR', 'MR', 'CC', 'ACC', 'ST', 'OFF']; // display order on this page/sheet
const RECON_LABELS    = ['SR'=>'Sales Team','MR'=>'Merchandising Team','CC'=>'Cash Collectors','ACC'=>'Assistant Cash Collectors','ST'=>'Stores Team','OFF'=>'Office Team'];

// =========================================================================
//  ORIGINAL WORKBOOK PALETTE — resolved from Salary_Rec.xlsx's theme (Office
//  theme, accent colors tinted lighter by Excel's "Blue/Green/Orange/Gray,
//  Lighter 80%" and "Blue, Lighter 60%" swatches). Kept as named constants
//  so the HTML page and the Excel export always use the exact same colors.
// =========================================================================
const CLR_TITLE_BAND   = 'FBE5D6'; // title row + column-header row (accent2 tint .8, peach/orange)
const CLR_SECTION_BAND = 'DEEBF7'; // Earnings/Deduction section headers + Secondary Sales Value band (accent1 tint .8, light blue)
const CLR_TEAM_BAND    = 'E2EFDA'; // per-team block header band (accent6 tint .8, light green)
const CLR_SUBTOTAL_BG  = 'EDEDED'; // Total Earning / Total row shading (accent3 tint .8, light gray)
const CLR_NET_BG       = 'B4C7E7'; // Net Salary row shading (accent5 tint .6, medium blue)

// =========================================================================
//  DATA LAYER — identical logic to salary_reconciliation.php, so every
//  page that shows reconciliation figures always agrees with each other.
// =========================================================================

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
function period_short_label($period){ return date('M Y', strtotime(sprintf('%04d-%02d-01', (int)$period['year'], (int)$period['month']))); }

/** Company-wide total secondary sales for one payroll month — a flat
 *  monthly total (SUM of final_bill_amount for imported invoices whose
 *  bill_date falls within the month), NOT computed per employee and NOT
 *  measured against any target. There is no "Target" row/value anywhere
 *  in this report. */
function fetch_secondary_sales_value($conn, $period_start, $period_end) {
    $ps = mysqli_real_escape_string($conn, $period_start);
    $pe = mysqli_real_escape_string($conn, $period_end);
    $res = @mysqli_query($conn, "SELECT COALESCE(SUM(final_bill_amount),0) AS total
        FROM secondary_invoice_import_details
        WHERE status = 'imported' AND bill_date BETWEEN '$ps' AND '$pe'");
    if ($res && ($r = mysqli_fetch_assoc($res))) return floatval($r['total']);
    return 0.0;
}

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

    $earn = function ($key) use ($cat, $eid, $emp, $inc_entries, $sr_fuel_map, $sheetVal) {
        $v = live_earn_value($cat, $key, $eid, $emp, $inc_entries, $sr_fuel_map);
        return $v !== null ? $v : $sheetVal($key);
    };

    $basic   = $sheetVal('basic');
    $travel  = $earn('travel');
    $fuel    = $earn('fuel');
    $mobile  = $earn('mobile');
    $disc    = $earn('disc');
    $meal    = $earn('meal');
    $arrears = $sheetVal('arrears');
    $other_earn = sum_additional_json($row['additional_earnings'] ?? null);

    $att_allow = $cat === 'ACC' ? $earn('att_allow_amt') : $earn('att_allow');

    $detail = [];
    switch ($cat) {
        case 'SR':
            $detail = ['ssv'=>$earn('ssv'), 'ps_amt'=>$earn('ps_amt'), 'eco_amt'=>$earn('eco_amt'), 'bp_amt'=>$earn('bp_amt'), 'assort_amt'=>$earn('assort_amt'), 'other_incentive'=>$earn('other_incentive')];
            break;
        case 'CC':
            $detail = ['ccfot_amt'=>$earn('ccfot_amt'), 'credit_mgt_amt'=>$earn('credit_mgt_amt'), 'payee_chq_amt'=>$earn('payee_chq_amt'), 'ssv_cc_amt'=>$earn('ssv_cc_amt'), 'att_inc_amt'=>$earn('att_inc_amt'), 'locus_admin_amt'=>$earn('locus_admin_amt')];
            break;
        case 'ACC':
            $detail = ['ccfot_amt'=>$earn('ccfot_amt'), 'ssv_acc_amt'=>$earn('ssv_acc_amt'), 'punctuality_amt'=>$earn('punctuality_amt')];
            break;
        case 'MR':
            $detail = ['ps_amt'=>$earn('ps_amt'), 'dlink_amount'=>$sheetVal('dlink_amount')];
            break;
        case 'ST':
            $detail = ['stores_damage_amt'=>$earn('stores_damage_amt'), 'rsqm_amt'=>$earn('rsqm_amt'), 'punctuality_amt'=>$earn('punctuality_amt'), 'loading_unloading_amt'=>$earn('loading_unloading_amt'), 'ccfot_amt'=>$earn('ccfot_amt')];
            break;
        case 'OFF':
            $detail = ['daily_incent'=>$earn('daily_incent'), 'weekly_incent'=>$earn('weekly_incent'), 'monthly_incent'=>$earn('monthly_incent'), 'punctuality_amt'=>$earn('punctuality_amt')];
            break;
    }
    $incentive = array_sum($detail);
    $total_earnings = $basic + $travel + $fuel + $mobile + $disc + $att_allow + $meal + $arrears + $other_earn + $incentive;

    $no_pay     = $sheetVal('no_pay_amount');
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

    $sal_adv = $advance_map[$eid] ?? $emp['salary_advance'];
    $sal_adv = $sal_adv > 0 ? $sal_adv : 0;

    $payee = $emp['payee'] ?: $emp['income_tax'];
    $payee = $payee > 0 ? $payee : 0;

    $total_deductions = $sal_adv + $no_pay + $good_short + $cash_short + $welfare + $w_loan + $loan_ded
                       + $excess_p + $credit_r + $retention + $donations + $w_soc + $epf8 + $payee + $other_ded;

    $total_earnings   = round($total_earnings, 2);
    $total_deductions = round($total_deductions, 2);
    $net_salary       = round($total_earnings - $total_deductions, 2);

    $out = [
        'category'       => $cat,
        'employee_id'    => $eid,
        'emp_code'       => $row['employee_code'] ?? '',
        'name'           => $row['employee_name'] ?? '',
        'basic'          => $basic,
        'travel'         => round($travel, 2),
        'fuel'           => round($fuel, 2),
        'incentive'      => round($incentive, 2),
        'disc'           => round($disc, 2),
        'att_allow'      => round($att_allow, 2),
        'mobile'         => round($mobile, 2),
        'meal'           => round($meal, 2),
        'arrears'        => round($arrears, 2),
        'other_earn'     => round($other_earn, 2),
        'total_earnings' => $total_earnings,
        'sal_adv'        => round($sal_adv, 2),
        'no_pay'         => round($no_pay, 2),
        'good_short'     => round($good_short, 2),
        'cash_short'     => round($cash_short, 2),
        'welfare'        => round($welfare, 2),
        'w_loan'         => round($w_loan, 2),
        'loan_ded'       => round($loan_ded, 2),
        'excess_p'       => round($excess_p, 2),
        'credit_r'       => round($credit_r, 2),
        'retention'      => round($retention, 2),
        'donations'      => round($donations, 2),
        'w_soc'          => round($w_soc, 2),
        'epf8'           => round($epf8, 2),
        'payee'          => round($payee, 2),
        'other_ded'      => round($other_ded, 2),
        'total_deductions' => $total_deductions,
        'net_salary'       => $net_salary,
    ];
    foreach ($detail as $k => $v) $out[$k] = round($v, 2);
    return $out;
}

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
        $sql = "SELECT sse.*, $emp_profile_select FROM salary_sheet_entries sse
                JOIN employees e ON e.id = sse.employee_id
                WHERE sse.payroll_period_id = $pid AND sse.category_code = '$catE'
                  AND e.active = 1 AND NOT $excl_sql
                ORDER BY sse.company_code, sse.employee_code";
    }
    $res = mysqli_query($conn, $sql);
    $rows = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    return $rows;
}

function load_recon_data($conn, $pid, array $cats, $period_start) {
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

// ── Category-specific named incentive lines (identical set/order to the
//    main Salary Excel export's per-category DET columns) ────────────────
$CATEGORY_DETAIL_COLUMNS = [
    'SR' => [
        ['key'=>'ssv',             'label'=>'Secondary Sales Value'],
        ['key'=>'ps_amt',          'label'=>'PS Compliance'],
        ['key'=>'eco_amt',         'label'=>'ECO Incentive'],
        ['key'=>'bp_amt',          'label'=>'BP Incentive'],
        ['key'=>'assort_amt',      'label'=>'Total Assortment'],
        ['key'=>'other_incentive', 'label'=>'Other Incentive'],
    ],
    'CC' => [
        ['key'=>'ccfot_amt',       'label'=>'CCFOT Incentive'],
        ['key'=>'credit_mgt_amt',  'label'=>'Credit Mgt Incentive'],
        ['key'=>'payee_chq_amt',   'label'=>'Payee Chq Incentive'],
        ['key'=>'ssv_cc_amt',      'label'=>'SSV Incentive'],
        ['key'=>'att_inc_amt',     'label'=>'Attendance Incentive'],
        ['key'=>'locus_admin_amt', 'label'=>'Locus Admin'],
    ],
    'ACC' => [
        ['key'=>'ccfot_amt',       'label'=>'CCFOT Incentive'],
        ['key'=>'ssv_acc_amt',     'label'=>'SSV Incentive'],
        ['key'=>'punctuality_amt', 'label'=>'Punctuality Incentive'],
    ],
    'MR' => [
        ['key'=>'ps_amt',          'label'=>'Placement Compliance'],
        ['key'=>'dlink_amount',    'label'=>'D-Link Allowance'],
    ],
    'ST' => [
        ['key'=>'stores_damage_amt',     'label'=>'Stores Damage Incentive'],
        ['key'=>'rsqm_amt',              'label'=>'RSQM Incentive'],
        ['key'=>'punctuality_amt',       'label'=>'Punctuality Incentive'],
        ['key'=>'loading_unloading_amt', 'label'=>'Loading/Unloading'],
        ['key'=>'ccfot_amt',             'label'=>'CCFOT Incentive'],
    ],
    'OFF' => [
        ['key'=>'daily_incent',    'label'=>'Daily Incentive'],
        ['key'=>'weekly_incent',   'label'=>'Weekly Incentive'],
        ['key'=>'monthly_incent',  'label'=>'Monthly Incentive'],
        ['key'=>'punctuality_amt', 'label'=>'Punctuality Incentive'],
    ],
];

const RECON_DED_TYPES = ['sal_adv'=>'Salary Advance', 'no_pay'=>'No Pay', 'good_short'=>'Goods Short', 'cash_short'=>'Cash Short', 'credit_r'=>'Credit Recovery'];

function recon_sum(array $rows, $key) { $t = 0.0; foreach ($rows as $r) $t += floatval($r[$key] ?? 0); return round($t, 2); }
function recon_hc(array $rows, $key)  { $c = 0; foreach ($rows as $r) if (round(floatval($r[$key] ?? 0), 2) != 0.0) $c++; return $c; }

// ── "Combined Metrics Rollup" section — matches the original workbook's
//    unlabeled block at rows 27-37 exactly. Every row there is a SUMIF
//    across ALL team blocks matching by label name (e.g. row 27
//    "Secondary Sales Value" = SUMIF(everywhere that label appears) =
//    just the Sales Team's own SSV line, since only SR has that label;
//    "Other Incentive/Allowances" sums across every team since every
//    team block has that label). This is a pure rollup of numbers we
//    already computed per block — it needs no separate DB query. ────────
const ROLLUP_METRIC_LABELS = [
    'Secondary Sales Value', 'Perfect Store Compliance', 'Max The Mix',
    'BU Level Achivment', 'Perfect Salesman Compliance', 'Travelling Expenses',
    'Value Target Incentive', 'Secondary CCFOT Incentive', 'Core Job Incentive',
    'Other Incentive/Allowances',
];

/** Given the finished $blocks array for one period, sums $label across
 *  every block that contains it (a plain-PHP equivalent of the original
 *  workbook's `=SUMIF($B$40:$B$147,$B$27,K40:K147)` formula). */
function rollup_metric(array $blocks, $label) {
    $t = 0.0;
    foreach ($blocks as $b) {
        foreach ($b['earn_lines'] as $l) if ($l['label'] === $label) $t += $l['value'];
    }
    return round($t, 2);
}

/** Builds the full reconciliation dataset (overview + per-category blocks)
 *  for ONE payroll period — in a plain array shape. Same as
 *  salary_reconciliation.php's build_reconciliation(), plus:
 *   - 'secondary_sales_total': the company-wide "Secondary Sales Total"
 *     (matches the original row 5 — a genuinely separate DB total, pulled
 *     from secondary_invoice_import_details.final_bill_amount).
 *   - 'rollup_lines': the "Combined Metrics Rollup" section (matches rows
 *     27-37 — cross-team SUMIF rollups computed from $blocks, no new
 *     DB query needed). */
function build_reconciliation($conn, array $byCategory, array $CATEGORY_DETAIL_COLUMNS, $period_start, $period_end) {
    $order = array_values(array_intersect(RECON_ORDER, array_keys($byCategory)));
    $earnByCat = []; $totalEarn = 0.0; $totalHC = 0; $totalBasic = 0.0;
    foreach ($order as $cat) {
        $rows = $byCategory[$cat];
        $e = recon_sum($rows, 'total_earnings');
        $earnByCat[$cat] = ['label' => RECON_LABELS[$cat], 'earnings' => $e, 'hc' => count($rows)];
        $totalEarn += $e; $totalBasic += recon_sum($rows, 'basic'); $totalHC += count($rows);
    }
    $epfEtf = round($totalBasic * 0.15, 2);

    $allRows = [];
    foreach ($order as $cat) $allRows = array_merge($allRows, $byCategory[$cat]);
    $dedLines = []; $namedDedTotal = 0.0;
    foreach (RECON_DED_TYPES as $key => $lbl) {
        $amt = recon_sum($allRows, $key); $hc = recon_hc($allRows, $key);
        $dedLines[] = ['label' => $lbl, 'amount' => $amt, 'hc' => $hc];
        $namedDedTotal += $amt;
    }
    $totalDed = recon_sum($allRows, 'total_deductions');
    $otherDed = round($totalDed - $namedDedTotal, 2);

    // Company-wide monthly "Secondary Sales Total" (matches original row 5 —
    // a genuinely separate DB total, NOT per-employee, NOT the same figure
    // as the per-team SSV rollup below).
    $secondarySalesTotal = fetch_secondary_sales_value($conn, $period_start, $period_end);

    $blocks = [];
    foreach ($order as $cat) {
        $rows = $byCategory[$cat];
        $basic = recon_sum($rows, 'basic');
        $attAllow = recon_sum($rows, 'att_allow');
        $earnLines = [
            ['label' => 'Basic Salary', 'value' => $basic],
            ['label' => 'Attendance & Allowance', 'value' => $attAllow],
        ];
        $namedEarn = $basic + $attAllow;
        foreach (($CATEGORY_DETAIL_COLUMNS[$cat] ?? []) as $dc) {
            $v = recon_sum($rows, $dc['key']);
            $earnLines[] = ['label' => $dc['label'], 'value' => $v];
            $namedEarn += $v;
        }
        $totalEarnCat = recon_sum($rows, 'total_earnings');
        $otherEarnCat = round($totalEarnCat - $namedEarn, 2);
        $earnLines[] = ['label' => 'Other Incentive/Allowances', 'value' => $otherEarnCat];

        $catDedLines = []; $namedDedCat = 0.0;
        foreach (RECON_DED_TYPES as $key => $lbl) {
            $v = recon_sum($rows, $key);
            $catDedLines[] = ['label' => $lbl, 'value' => $v];
            $namedDedCat += $v;
        }
        $totalDedCat = recon_sum($rows, 'total_deductions');
        $catDedLines[] = ['label' => 'Other Deduction', 'value' => round($totalDedCat - $namedDedCat, 2)];

        $blocks[] = [
            'cat' => $cat, 'label' => RECON_LABELS[$cat], 'headcount' => count($rows),
            'earn_lines' => $earnLines, 'total_earning' => $totalEarnCat,
            'ded_lines' => $catDedLines, 'total_deduction' => $totalDedCat,
            'net_salary' => recon_sum($rows, 'net_salary'),
        ];
    }

    // Combined Metrics Rollup (matches original rows 27-37): for each named
    // metric label, sum it across every team block that has it.
    $rollupLines = [];
    $rollupTotal = 0.0;
    foreach (ROLLUP_METRIC_LABELS as $label) {
        $v = rollup_metric($blocks, $label);
        $rollupLines[] = ['label' => $label, 'value' => $v];
        $rollupTotal += $v;
    }
    $rollupTotal = round($rollupTotal, 2);

    return [
        'earn_by_cat'  => $earnByCat,
        'total_earn'   => $totalEarn,
        'total_hc'     => $totalHC,
        'epf_etf'      => $epfEtf,
        'grand_earn'   => round($totalEarn + $epfEtf, 2),
        'ded_lines'    => $dedLines,
        'other_ded'    => $otherDed,
        'total_ded'    => $totalDed,
        'net_salary'   => round($totalEarn - $totalDed, 2),
        'blocks'       => $blocks,
        'secondary_sales_total' => $secondarySalesTotal,
        'rollup_lines' => $rollupLines,
        'rollup_total' => $rollupTotal,
    ];
}

// =========================================================================
//  MULTI-MONTH ROW MODEL — turns build_reconciliation() results for every
//  payroll period into "Description | month1 | HC | month2 | HC | ... |
//  Total" rows, matching the Excel "2- Total Salary Rec" tab layout.
// =========================================================================

/** A payroll period counts as "empty" (nothing actually saved for that
 *  month) when it has zero employees across every category AND zero
 *  secondary sales total — i.e. the payroll_periods row exists but no real
 *  data was ever entered for it. Such months are hidden from this report
 *  entirely (no blank columns). */
function recon_is_empty(array $recon) {
    return $recon['total_hc'] == 0 && floatval($recon['secondary_sales_total']) == 0.0;
}

/** Pulls a numeric value for ($label,$key) out of one period's $recon array,
 *  for the Overview (earn-by-category + deduction) rows. */
function overview_value($recon, $rowdef) {
    $key = $rowdef['key'];
    if ($key === '__epf_etf__')   return [$recon['epf_etf'], null];
    if ($key === '__total_earn__') return [$recon['grand_earn'], $recon['total_hc']];
    if ($key === '__other_ded__') return [$recon['other_ded'], null];
    if ($key === '__total_ded__') return [$recon['total_ded'], null];
    if ($key === '__net_salary__') return [$recon['net_salary'], null];
    if ($key === '__secondary_sales_total__') return [$recon['secondary_sales_total'], null];
    if ($key === '__rollup_total__') return [$recon['rollup_total'], null];
    if (isset($recon['earn_by_cat'][$key])) {
        $c = $recon['earn_by_cat'][$key];
        return [$c['earnings'], $c['hc']];
    }
    foreach ($recon['ded_lines'] as $d) {
        if ($d['label'] === $rowdef['label']) return [$d['amount'], $d['hc']];
    }
    foreach ($recon['rollup_lines'] as $r) {
        if ($r['label'] === $rowdef['label']) return [$r['value'], null];
    }
    return [0.0, null];
}

/** Pulls a numeric value out of one period's category $block (for the
 *  per-category detail sections). */
function block_value($block, $label) {
    if ($label === 'Total Earning')   return $block['total_earning'];
    if ($label === 'Total Deduction') return $block['total_deduction'];
    if ($label === 'Net Salary')      return $block['net_salary'];
    foreach ($block['earn_lines'] as $l) if ($l['label'] === $label) return $l['value'];
    foreach ($block['ded_lines'] as $l) if ($l['label'] === $label) return $l['value'];
    return 0.0;
}

// =========================================================================
//  MINI XLSX WRITER — same dependency-free builder used by
//  salary_reconciliation.php / salary_excel_generate.php, extended with
//  the exact fill colors resolved from the original Salary_Rec.xlsx theme.
// =========================================================================
class MiniXLSXWriter {
    private $sheets = [];
    // Style indexes — matched 1:1 to the original workbook's visual bands.
    const STYLE_DEFAULT        = 0;
    const STYLE_TITLE          = 1;  // title row: bold 12pt, peach band
    const STYLE_COLHEADER      = 2;  // Description/Month/HC header row: bold, peach band, centered
    const STYLE_COLHEADER_L    = 3;  // same but left-aligned (Description cell)
    const STYLE_SECTION        = 4;  // Earnings/Deduction section header + SSV band: bold, light-blue band
    const STYLE_TEAM_BAND      = 5;  // per-team block header band: bold, light-green band
    const STYLE_NUM            = 6;  // plain numeric data cell, centered
    const STYLE_NUM_L          = 7;  // plain numeric data cell, left-aligned (for HC-style small ints)
    const STYLE_TEXT           = 8;  // plain text cell
    const STYLE_TOTAL_TEXT     = 9;  // bold text, light-gray band (Total / Total Earning rows)
    const STYLE_TOTAL_NUM      = 10; // bold numeric, light-gray band, top+double border
    const STYLE_NET_TEXT       = 11; // bold text, medium-blue band (Net Salary rows)
    const STYLE_NET_NUM        = 12; // bold numeric, medium-blue band

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
        // Fills: 0=none, 1=gray125(reserved), 2=title/header peach, 3=section blue,
        //        4=team green, 5=total gray. (Net band uses font weight only, no fill,
        //        to keep the style table simple and reliable across viewers.)
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00;(#,##0.00);&quot;-&quot;"/></numFmts>'
            . '<fonts count="5">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><sz val="12"/><b/><name val="Calibri"/></font>'
            . '<font><sz val="11"/><b/><name val="Calibri"/></font>'
            . '<font><sz val="10"/><name val="Calibri"/></font>'
            . '<font><sz val="10"/><b/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="7">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF' . CLR_TITLE_BAND . '"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF' . CLR_SECTION_BAND . '"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF' . CLR_TEAM_BAND . '"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF' . CLR_SUBTOTAL_BG . '"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF' . CLR_NET_BG . '"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="3">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left/><right/><top style="thin"><color indexed="64"/></top><bottom style="double"><color auto="1"/></bottom><diagonal/></border>'
            . '<border><left/><right/><top style="dashed"><color auto="1"/></top><bottom style="dashed"><color auto="1"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="13">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="3" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="164" fontId="3" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="4" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="4" fillId="5" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="0" fontId="4" fillId="6" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="4" fillId="6" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    public function output($filename) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        @ini_set('display_errors', '0');
        if (!class_exists('ZipArchive')) $this->fail('The PHP "zip" extension is not enabled on this server.');

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        @unlink($tmp);
        $zip = new ZipArchive();
        $openResult = $zip->open($tmp, ZipArchive::CREATE);
        if ($openResult !== true) { @unlink($tmp); $this->fail('Could not create the Excel file (code ' . $openResult . ').'); }

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
        foreach ($this->sheets as $i => $s) {
            $sheetsXmlList .= '<sheet name="' . $this->esc($s['name']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
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
        if (!file_exists($tmp) || filesize($tmp) === 0) { @unlink($tmp); $this->fail('The Excel file came out empty.'); }

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

/** Writes the multi-month "2- Total Salary Rec"-style sheet: one column
 *  pair (Amount + HC where applicable) per payroll period, plus a Total
 *  column on the right — built from an array of build_reconciliation()
 *  results keyed by period id, in the same order as $periods. Styled to
 *  match the original workbook's bands exactly. */
function write_yearly_reconciliation_sheet(MiniXLSXWriter $xl, $sheetIdx, array $periods, array $recons, array $CATEGORY_DETAIL_COLUMNS) {
    $nP = count($periods);

    $xl->addRow($sheetIdx, [['YELO GROUP - SALARY RECONCILIATION - ALL MONTHS', 's', MiniXLSXWriter::STYLE_TITLE]]);
    $xl->merge($sheetIdx, 'A1:C1');
    $xl->addRow($sheetIdx, []);

    // ── Header row: Description | (Month, HC) x N | Total ────────────────
    $hdr = [['Description', 's', MiniXLSXWriter::STYLE_COLHEADER_L]];
    foreach ($periods as $p) {
        $hdr[] = [period_short_label($p), 's', MiniXLSXWriter::STYLE_COLHEADER];
        $hdr[] = ['HC', 's', MiniXLSXWriter::STYLE_COLHEADER];
    }
    $hdr[] = ['Total', 's', MiniXLSXWriter::STYLE_COLHEADER];
    $xl->addRow($sheetIdx, $hdr);

    // ── Secondary Sales Total (matches original row 5 — company-wide grand
    //    total, sits ABOVE the Earnings-by-category rows, no HC) ───────────
    $cells = [['Secondary Sales Total', 's', MiniXLSXWriter::STYLE_TEXT]];
    $rowTotal = 0.0;
    foreach ($periods as $p) {
        $r = $recons[$p['id']]; $rowTotal += $r['secondary_sales_total'];
        $cells[] = [$r['secondary_sales_total'], 'n', MiniXLSXWriter::STYLE_NUM];
        $cells[] = ['', 's', MiniXLSXWriter::STYLE_TEXT];
    }
    $cells[] = [$rowTotal, 'n', MiniXLSXWriter::STYLE_NUM];
    $xl->addRow($sheetIdx, $cells);

    // ── Earnings by category ──────────────────────────────────────────────
    $overview_earn_rows = [];
    foreach (RECON_ORDER as $cat) $overview_earn_rows[] = ['label' => RECON_LABELS[$cat], 'key' => $cat];
    $overview_earn_rows[] = ['label' => 'EPF & ETF (15% of Basic)', 'key' => '__epf_etf__'];

    foreach ($overview_earn_rows as $rowdef) {
        $cells = [[$rowdef['label'], 's', MiniXLSXWriter::STYLE_TEXT]];
        $rowTotal = 0.0;
        foreach ($periods as $p) {
            [$val, $hc] = overview_value($recons[$p['id']], $rowdef);
            $rowTotal += $val;
            $cells[] = [$val, 'n', MiniXLSXWriter::STYLE_NUM];
            $cells[] = [$hc === null ? '' : $hc, $hc === null ? 's' : 'n', MiniXLSXWriter::STYLE_NUM];
        }
        $cells[] = [$rowTotal, 'n', MiniXLSXWriter::STYLE_NUM];
        $xl->addRow($sheetIdx, $cells);
    }
    // Total row
    $cells = [['Total', 's', MiniXLSXWriter::STYLE_TOTAL_TEXT]];
    $grandTotal = 0.0;
    foreach ($periods as $p) {
        $r = $recons[$p['id']];
        $grandTotal += $r['grand_earn'];
        $cells[] = [$r['grand_earn'], 'n', MiniXLSXWriter::STYLE_TOTAL_NUM];
        $cells[] = [$r['total_hc'], 'n', MiniXLSXWriter::STYLE_TOTAL_NUM];
    }
    $cells[] = [$grandTotal, 'n', MiniXLSXWriter::STYLE_TOTAL_NUM];
    $xl->addRow($sheetIdx, $cells);
    $xl->addRow($sheetIdx, []);

    // ── Deduction summary ──────────────────────────────────────────────────
    $hdr = [['Deduction', 's', MiniXLSXWriter::STYLE_COLHEADER_L]];
    foreach ($periods as $p) {
        $hdr[] = [period_short_label($p), 's', MiniXLSXWriter::STYLE_COLHEADER];
        $hdr[] = ['HC', 's', MiniXLSXWriter::STYLE_COLHEADER];
    }
    $hdr[] = ['Total', 's', MiniXLSXWriter::STYLE_COLHEADER];
    $xl->addRow($sheetIdx, $hdr);

    foreach (RECON_DED_TYPES as $lbl) {
        $rowdef = ['label' => $lbl, 'key' => $lbl];
        $cells = [[$lbl, 's', MiniXLSXWriter::STYLE_TEXT]];
        $rowTotal = 0.0;
        foreach ($periods as $p) {
            [$val, $hc] = overview_value($recons[$p['id']], $rowdef);
            $rowTotal += $val;
            $cells[] = [$val, 'n', MiniXLSXWriter::STYLE_NUM];
            $cells[] = [$hc === null ? '' : $hc, $hc === null ? 's' : 'n', MiniXLSXWriter::STYLE_NUM];
        }
        $cells[] = [$rowTotal, 'n', MiniXLSXWriter::STYLE_NUM];
        $xl->addRow($sheetIdx, $cells);
    }
    // Other Deduction
    $cells = [['Other Deduction', 's', MiniXLSXWriter::STYLE_TEXT]];
    $rowTotal = 0.0;
    foreach ($periods as $p) {
        $r = $recons[$p['id']]; $rowTotal += $r['other_ded'];
        $cells[] = [$r['other_ded'], 'n', MiniXLSXWriter::STYLE_NUM];
        $cells[] = ['', 's', MiniXLSXWriter::STYLE_TEXT];
    }
    $cells[] = [$rowTotal, 'n', MiniXLSXWriter::STYLE_NUM];
    $xl->addRow($sheetIdx, $cells);
    // Total Deduction
    $cells = [['Total Deduction', 's', MiniXLSXWriter::STYLE_TOTAL_TEXT]];
    $rowTotal = 0.0;
    foreach ($periods as $p) {
        $r = $recons[$p['id']]; $rowTotal += $r['total_ded'];
        $cells[] = [$r['total_ded'], 'n', MiniXLSXWriter::STYLE_TOTAL_NUM];
        $cells[] = ['', 's', MiniXLSXWriter::STYLE_TOTAL_TEXT];
    }
    $cells[] = [$rowTotal, 'n', MiniXLSXWriter::STYLE_TOTAL_NUM];
    $xl->addRow($sheetIdx, $cells);
    $xl->addRow($sheetIdx, []);
    // Net Salary (all categories)
    $cells = [['Net Salary (All Categories)', 's', MiniXLSXWriter::STYLE_NET_TEXT]];
    $rowTotal = 0.0;
    foreach ($periods as $p) {
        $r = $recons[$p['id']]; $rowTotal += $r['net_salary'];
        $cells[] = [$r['net_salary'], 'n', MiniXLSXWriter::STYLE_NET_NUM];
        $cells[] = ['', 's', MiniXLSXWriter::STYLE_NET_TEXT];
    }
    $cells[] = [$rowTotal, 'n', MiniXLSXWriter::STYLE_NET_NUM];
    $xl->addRow($sheetIdx, $cells);
    $xl->addRow($sheetIdx, []);
    $xl->addRow($sheetIdx, []);

    // ── Combined Metrics Rollup (matches original rows 27-37 exactly — an
    //    unlabeled block whose every row is a cross-team SUMIF: "Secondary
    //    Sales Value" here is the SR block's own SSV line surfaced again,
    //    "Other Incentive/Allowances" sums that label across all six teams,
    //    etc. Computed purely from $blocks, no separate DB query.) ────────
    foreach ($recons[$periods[0]['id']]['rollup_lines'] as $i => $seed) {
        $label = $seed['label'];
        $cells = [[$label, 's', MiniXLSXWriter::STYLE_TEXT]];
        $rowTotal = 0.0;
        foreach ($periods as $p) {
            [$val, $hc] = overview_value($recons[$p['id']], ['label' => $label, 'key' => $label]);
            $rowTotal += $val;
            $cells[] = [$val, 'n', MiniXLSXWriter::STYLE_NUM];
            $cells[] = ['', 's', MiniXLSXWriter::STYLE_TEXT];
        }
        $cells[] = [$rowTotal, 'n', MiniXLSXWriter::STYLE_NUM];
        $xl->addRow($sheetIdx, $cells);
    }
    // Rollup Total row
    $cells = [['Total', 's', MiniXLSXWriter::STYLE_TOTAL_TEXT]];
    $rowTotal = 0.0;
    foreach ($periods as $p) {
        $r = $recons[$p['id']]; $rowTotal += $r['rollup_total'];
        $cells[] = [$r['rollup_total'], 'n', MiniXLSXWriter::STYLE_TOTAL_NUM];
        $cells[] = ['', 's', MiniXLSXWriter::STYLE_TOTAL_TEXT];
    }
    $cells[] = [$rowTotal, 'n', MiniXLSXWriter::STYLE_TOTAL_NUM];
    $xl->addRow($sheetIdx, $cells);
    $xl->addRow($sheetIdx, []);
    $xl->addRow($sheetIdx, []);

    // ── Per-category detail blocks ─────────────────────────────────────────
    foreach (RECON_ORDER as $cat) {
        $earnLabels = []; $dedLabels = [];
        foreach ($periods as $p) {
            foreach ($recons[$p['id']]['blocks'] as $b) {
                if ($b['cat'] !== $cat) continue;
                foreach ($b['earn_lines'] as $l) if (!in_array($l['label'], $earnLabels, true)) $earnLabels[] = $l['label'];
                foreach ($b['ded_lines']  as $l) if (!in_array($l['label'], $dedLabels, true))  $dedLabels[]  = $l['label'];
            }
        }
        $catBlocks = [];
        foreach ($periods as $p) {
            $found = null;
            foreach ($recons[$p['id']]['blocks'] as $b) if ($b['cat'] === $cat) { $found = $b; break; }
            $catBlocks[$p['id']] = $found;
        }

        // Section band header spans the width of the table
        $bandRow = [[RECON_LABELS[$cat], 's', MiniXLSXWriter::STYLE_TEAM_BAND]];
        for ($i = 0; $i < ($nP * 2) + 1; $i++) $bandRow[] = ['', 's', MiniXLSXWriter::STYLE_TEAM_BAND];
        $xl->addRow($sheetIdx, $bandRow);

        // No of Employee
        $cells = [['No of Employee', 's', MiniXLSXWriter::STYLE_TEXT]];
        $hcTotal = 0;
        foreach ($periods as $p) {
            $b = $catBlocks[$p['id']]; $hc = $b ? $b['headcount'] : 0; $hcTotal += $hc;
            $cells[] = [$hc, 'n', MiniXLSXWriter::STYLE_NUM];
            $cells[] = ['', 's', MiniXLSXWriter::STYLE_TEXT];
        }
        $cells[] = [$hcTotal, 'n', MiniXLSXWriter::STYLE_NUM];
        $xl->addRow($sheetIdx, $cells);
        $xl->addRow($sheetIdx, []);

        foreach ($earnLabels as $label) {
            $cells = [[$label, 's', MiniXLSXWriter::STYLE_TEXT]];
            $rowTotal = 0.0;
            foreach ($periods as $p) {
                $b = $catBlocks[$p['id']]; $v = $b ? block_value($b, $label) : 0.0; $rowTotal += $v;
                $cells[] = [$v, 'n', MiniXLSXWriter::STYLE_NUM];
                $cells[] = ['', 's', MiniXLSXWriter::STYLE_TEXT];
            }
            $cells[] = [$rowTotal, 'n', MiniXLSXWriter::STYLE_NUM];
            $xl->addRow($sheetIdx, $cells);
        }
        // Total Earning
        $cells = [['Total Earning', 's', MiniXLSXWriter::STYLE_TOTAL_TEXT]];
        $rowTotal = 0.0;
        foreach ($periods as $p) {
            $b = $catBlocks[$p['id']]; $v = $b ? $b['total_earning'] : 0.0; $rowTotal += $v;
            $cells[] = [$v, 'n', MiniXLSXWriter::STYLE_TOTAL_NUM];
            $cells[] = ['', 's', MiniXLSXWriter::STYLE_TOTAL_TEXT];
        }
        $cells[] = [$rowTotal, 'n', MiniXLSXWriter::STYLE_TOTAL_NUM];
        $xl->addRow($sheetIdx, $cells);
        $xl->addRow($sheetIdx, []);

        foreach ($dedLabels as $label) {
            $cells = [[$label, 's', MiniXLSXWriter::STYLE_TEXT]];
            $rowTotal = 0.0;
            foreach ($periods as $p) {
                $b = $catBlocks[$p['id']]; $v = $b ? block_value($b, $label) : 0.0; $rowTotal += $v;
                $cells[] = [$v, 'n', MiniXLSXWriter::STYLE_NUM];
                $cells[] = ['', 's', MiniXLSXWriter::STYLE_TEXT];
            }
            $cells[] = [$rowTotal, 'n', MiniXLSXWriter::STYLE_NUM];
            $xl->addRow($sheetIdx, $cells);
        }
        // Total Deduction
        $cells = [['Total Deduction', 's', MiniXLSXWriter::STYLE_TOTAL_TEXT]];
        $rowTotal = 0.0;
        foreach ($periods as $p) {
            $b = $catBlocks[$p['id']]; $v = $b ? $b['total_deduction'] : 0.0; $rowTotal += $v;
            $cells[] = [$v, 'n', MiniXLSXWriter::STYLE_TOTAL_NUM];
            $cells[] = ['', 's', MiniXLSXWriter::STYLE_TOTAL_TEXT];
        }
        $cells[] = [$rowTotal, 'n', MiniXLSXWriter::STYLE_TOTAL_NUM];
        $xl->addRow($sheetIdx, $cells);
        // Net Salary
        $cells = [['Net Salary', 's', MiniXLSXWriter::STYLE_NET_TEXT]];
        $rowTotal = 0.0;
        foreach ($periods as $p) {
            $b = $catBlocks[$p['id']]; $v = $b ? $b['net_salary'] : 0.0; $rowTotal += $v;
            $cells[] = [$v, 'n', MiniXLSXWriter::STYLE_NET_NUM];
            $cells[] = ['', 's', MiniXLSXWriter::STYLE_NET_TEXT];
        }
        $cells[] = [$rowTotal, 'n', MiniXLSXWriter::STYLE_NET_NUM];
        $xl->addRow($sheetIdx, $cells);
        $xl->addRow($sheetIdx, []);
        $xl->addRow($sheetIdx, []);
    }

    $widths = [32];
    foreach ($periods as $p) { $widths[] = 16; $widths[] = 8; }
    $widths[] = 16;
    $xl->setWidths($sheetIdx, $widths);
    $xl->freeze($sheetIdx, 3);
}

// =========================================================================
//  EXPORT HANDLER — must run before ANY HTML output
// =========================================================================
$action = $_GET['do'] ?? '';
if ($action === 'export') {
    $periods = [];
    $res = mysqli_query($conn, "SELECT * FROM payroll_periods ORDER BY year ASC, month ASC");
    if ($res) while ($p = mysqli_fetch_assoc($res)) $periods[] = $p;

    if (empty($periods)) { http_response_code(400); echo 'No payroll periods found.'; exit; }

    $recons = [];
    foreach ($periods as $p) {
        $period_start = period_start_date($p);
        $period_end   = period_end_date($p);
        $byCategory   = load_recon_data($conn, (int)$p['id'], RECON_ORDER, $period_start);
        $recons[$p['id']] = build_reconciliation($conn, $byCategory, $CATEGORY_DETAIL_COLUMNS, $period_start, $period_end);
    }

    // Hide any month with nothing actually saved (no employees, no sales) —
    // only real, saved months appear as columns.
    $periods = array_values(array_filter($periods, fn($p) => !recon_is_empty($recons[$p['id']])));

    if (empty($periods)) { http_response_code(400); echo 'No payroll periods with saved data found.'; exit; }

    $xl = new MiniXLSXWriter();
    $idx = $xl->addSheet('2- Total Salary Rec');
    write_yearly_reconciliation_sheet($xl, $idx, $periods, $recons, $CATEGORY_DETAIL_COLUMNS);

    $xl->output('Salary_Reconciliation_All_Months.xlsx');
    exit;
}

include 'header.php';

// ── Load every payroll period, oldest → newest ───────────────────────────
$periods = [];
$res = mysqli_query($conn, "SELECT * FROM payroll_periods ORDER BY year ASC, month ASC");
if ($res) while ($p = mysqli_fetch_assoc($res)) $periods[] = $p;

// ── Build a reconciliation dataset for every period ──────────────────────
$recons = []; // period id => build_reconciliation() result
foreach ($periods as $p) {
    $period_start = period_start_date($p);
    $period_end   = period_end_date($p);
    $byCategory   = load_recon_data($conn, (int)$p['id'], RECON_ORDER, $period_start);
    $recons[$p['id']] = build_reconciliation($conn, $byCategory, $CATEGORY_DETAIL_COLUMNS, $period_start, $period_end);
}

// Hide any month with nothing actually saved (no employees, no sales) —
// only real, saved months appear as columns in this report.
$periods = array_values(array_filter($periods, fn($p) => !recon_is_empty($recons[$p['id']])));

// ── Overview row definitions (Earnings by category + EPF/ETF + Total, then
//    Deduction lines + Other Deduction + Total Deduction + Net Salary) ────
$overview_earn_rows = [];
foreach (RECON_ORDER as $cat) $overview_earn_rows[] = ['label' => RECON_LABELS[$cat], 'key' => $cat];
$overview_earn_rows[] = ['label' => 'EPF & ETF (15% of Basic)', 'key' => '__epf_etf__'];

$overview_ded_rows = [];
foreach (RECON_DED_TYPES as $lbl) $overview_ded_rows[] = ['label' => $lbl, 'key' => $lbl];

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<style>
:root{
    --bg:#f2f1ee; --surface:#fff; --border:#c9c9c9;
    --title-band:#<?php echo CLR_TITLE_BAND; ?>;
    --section-band:#<?php echo CLR_SECTION_BAND; ?>;
    --team-band:#<?php echo CLR_TEAM_BAND; ?>;
    --subtotal-bg:#<?php echo CLR_SUBTOTAL_BG; ?>;
    --net-bg:#<?php echo CLR_NET_BG; ?>;
    --sans:'DM Sans','Calibri',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--sans);background:var(--bg);color:#000;}
.pg-hdr{background:#1a1a18;color:#fff;padding:22px 28px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;border-radius:0 0 8px 8px;margin-bottom:20px;}
.pg-hdr-title{font-size:22px;font-weight:800;letter-spacing:-.2px;display:flex;align-items:center;gap:10px;}
.pg-hdr-sub{font-size:12px;color:#a0a09a;margin-top:3px;max-width:760px;}
.wrap{max-width:100%;margin:0 auto;padding:0 20px 40px;}
.card{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:18px 20px;margin-bottom:18px;}
.card-title-bar{background:var(--title-band);border:1px solid var(--border);border-radius:6px 6px 0 0;padding:10px 16px;font-weight:700;font-size:14px;text-align:center;}
.actions{display:flex;gap:10px;margin-top:2px;margin-bottom:16px;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:6px;font-size:13px;font-weight:700;font-family:var(--sans);cursor:pointer;border:none;text-decoration:none;transition:all .15s;}
.btn-dark{background:#1a1a18;color:#fff;}.btn-dark:hover{background:#333;}
.btn-green{background:#22c55e;color:#fff;}.btn-green:hover{background:#16a34a;}
.table-scroll{overflow-x:auto;}
table.grid{border-collapse:collapse;font-size:12.5px;white-space:nowrap;width:100%;}
table.grid th,table.grid td{padding:6px 10px;border:1px dashed #bbb;}
table.grid thead th{background:var(--title-band);font-weight:700;font-size:11.5px;position:sticky;top:0;border:1px dashed #bbb;}
table.grid td.desc,table.grid th.desc{text-align:left;position:sticky;left:0;background:var(--surface);z-index:1;min-width:230px;}
table.grid thead th.desc{background:var(--title-band);z-index:2;}
table.grid td.num,table.grid th.num{text-align:center;font-family:'Calibri',sans-serif;}
table.grid td.hc,table.grid th.hc{text-align:center;font-family:'Calibri',sans-serif;font-size:11.5px;}
table.grid tr.section td{font-weight:700;background:var(--section-band);position:sticky;left:0;}
table.grid tr.team td{font-weight:700;background:var(--team-band);position:sticky;left:0;}
table.grid tr.total td{font-weight:700;border-top:1px solid #666;background:var(--subtotal-bg);}
table.grid tr.total td.desc{background:var(--subtotal-bg);}
table.grid tr.net td{font-weight:700;background:var(--net-bg);}
table.grid tr.net td.desc{background:var(--net-bg);}
.block-title{font-weight:700;font-size:13px;margin:22px 0 0;}
.note{font-size:12px;color:#666;margin-top:10px;line-height:1.5;}
</style>

<div class="pg-hdr">
    <div>
        <div class="pg-hdr-title">Salary Reconciliation — All Months</div>
        <div class="pg-hdr-sub">Live database report — every payroll period recorded so far, one column per month, styled to match Salary_Rec.xlsx's "2- Total Salary Rec" tab exactly (same section bands and colors). Nothing here is a saved snapshot; every value is pulled fresh from the database each time this page loads. HC = Head Count (employee count). "Secondary Sales Total" (Earnings section) is the company-wide monthly sales total; "Secondary Sales Value" (Combined Metrics section) is the Sales Team's own figure rolled up — these are two different numbers, matching the original workbook exactly. Neither is per-employee or measured against a target.</div>
    </div>
</div>

<div class="wrap">
    <div class="actions">
        <a href="salary_reconciliation_yearly.php?do=export" class="btn btn-green" target="_blank">Download as Excel (.xlsx)</a>
        <a href="salary_reconciliation.php" class="btn btn-dark">Single-Month View</a>
    </div>

    <?php if (empty($periods)): ?>
    <div class="card"><div class="note">No payroll periods with saved data found. Create a payroll period and enter data first from the Payroll Periods page.</div></div>
    <?php else: ?>

    <div class="card">
        <div class="card-title-bar">Earnings by Category — All Months</div>
        <div class="table-scroll">
        <table class="grid">
            <thead>
                <tr>
                    <th class="desc">Description</th>
                    <?php foreach ($periods as $p): ?>
                        <th class="num"><?php echo htmlspecialchars(period_short_label($p)); ?></th>
                        <th class="hc">HC</th>
                    <?php endforeach; ?>
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                <tr class="section">
                    <td class="desc">Secondary Sales Total</td>
                    <?php $rowTotal = 0.0; foreach ($periods as $p):
                        $r = $recons[$p['id']]; $rowTotal += $r['secondary_sales_total']; ?>
                        <td class="num">Rs. <?php echo number_format($r['secondary_sales_total'], 2); ?></td>
                        <td class="hc"></td>
                    <?php endforeach; ?>
                    <td class="num">Rs. <?php echo number_format($rowTotal, 2); ?></td>
                </tr>
                <?php foreach ($overview_earn_rows as $rowdef):
                    $rowTotal = 0.0; ?>
                <tr>
                    <td class="desc"><?php echo htmlspecialchars($rowdef['label']); ?></td>
                    <?php foreach ($periods as $p):
                        [$val, $hc] = overview_value($recons[$p['id']], $rowdef);
                        $rowTotal += $val; ?>
                        <td class="num">Rs. <?php echo number_format($val, 2); ?></td>
                        <td class="hc"><?php echo $hc === null ? '' : $hc; ?></td>
                    <?php endforeach; ?>
                    <td class="num">Rs. <?php echo number_format($rowTotal, 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total">
                    <td class="desc">Total</td>
                    <?php $grandTotal = 0.0; foreach ($periods as $p):
                        $r = $recons[$p['id']]; $grandTotal += $r['grand_earn']; ?>
                        <td class="num">Rs. <?php echo number_format($r['grand_earn'], 2); ?></td>
                        <td class="hc"><?php echo $r['total_hc']; ?></td>
                    <?php endforeach; ?>
                    <td class="num">Rs. <?php echo number_format($grandTotal, 2); ?></td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card">
        <div class="card-title-bar">Deduction Summary — All Months</div>
        <div class="table-scroll">
        <table class="grid">
            <thead>
                <tr>
                    <th class="desc">Description</th>
                    <?php foreach ($periods as $p): ?>
                        <th class="num"><?php echo htmlspecialchars(period_short_label($p)); ?></th>
                        <th class="hc">HC</th>
                    <?php endforeach; ?>
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($overview_ded_rows as $rowdef):
                    $rowTotal = 0.0; ?>
                <tr>
                    <td class="desc"><?php echo htmlspecialchars($rowdef['label']); ?></td>
                    <?php foreach ($periods as $p):
                        [$val, $hc] = overview_value($recons[$p['id']], $rowdef);
                        $rowTotal += $val; ?>
                        <td class="num">Rs. <?php echo number_format($val, 2); ?></td>
                        <td class="hc"><?php echo $hc === null ? '' : $hc; ?></td>
                    <?php endforeach; ?>
                    <td class="num">Rs. <?php echo number_format($rowTotal, 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <td class="desc">Other Deduction</td>
                    <?php $rowTotal = 0.0; foreach ($periods as $p):
                        $r = $recons[$p['id']]; $rowTotal += $r['other_ded']; ?>
                        <td class="num">Rs. <?php echo number_format($r['other_ded'], 2); ?></td>
                        <td class="hc"></td>
                    <?php endforeach; ?>
                    <td class="num">Rs. <?php echo number_format($rowTotal, 2); ?></td>
                </tr>
                <tr class="total">
                    <td class="desc">Total Deduction</td>
                    <?php $rowTotal = 0.0; foreach ($periods as $p):
                        $r = $recons[$p['id']]; $rowTotal += $r['total_ded']; ?>
                        <td class="num">Rs. <?php echo number_format($r['total_ded'], 2); ?></td>
                        <td class="hc"></td>
                    <?php endforeach; ?>
                    <td class="num">Rs. <?php echo number_format($rowTotal, 2); ?></td>
                </tr>
                <tr class="net">
                    <td class="desc">Net Salary (All Categories)</td>
                    <?php $rowTotal = 0.0; foreach ($periods as $p):
                        $r = $recons[$p['id']]; $rowTotal += $r['net_salary']; ?>
                        <td class="num">Rs. <?php echo number_format($r['net_salary'], 2); ?></td>
                        <td class="hc"></td>
                    <?php endforeach; ?>
                    <td class="num">Rs. <?php echo number_format($rowTotal, 2); ?></td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card">
        <div class="card-title-bar">Combined Metrics — All Months</div>
        <div class="table-scroll">
        <table class="grid">
            <thead>
                <tr>
                    <th class="desc">Description</th>
                    <?php foreach ($periods as $p): ?>
                        <th class="num"><?php echo htmlspecialchars(period_short_label($p)); ?></th>
                    <?php endforeach; ?>
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recons[$periods[0]['id']]['rollup_lines'] as $seed):
                    $label = $seed['label'];
                    $rowTotal = 0.0; ?>
                <tr>
                    <td class="desc"><?php echo htmlspecialchars($label); ?></td>
                    <?php foreach ($periods as $p):
                        [$val, ] = overview_value($recons[$p['id']], ['label' => $label, 'key' => $label]);
                        $rowTotal += $val; ?>
                        <td class="num">Rs. <?php echo number_format($val, 2); ?></td>
                    <?php endforeach; ?>
                    <td class="num">Rs. <?php echo number_format($rowTotal, 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total">
                    <td class="desc">Total</td>
                    <?php $grandTotal = 0.0; foreach ($periods as $p):
                        $r = $recons[$p['id']]; $grandTotal += $r['rollup_total']; ?>
                        <td class="num">Rs. <?php echo number_format($r['rollup_total'], 2); ?></td>
                    <?php endforeach; ?>
                    <td class="num">Rs. <?php echo number_format($grandTotal, 2); ?></td>
                </tr>
            </tbody>
        </table>
        </div>
        <div class="note">This section mirrors the original workbook's unlabeled rollup block: each row sums that named metric across every team block it appears in (e.g. "Secondary Sales Value" is the Sales Team's own SSV figure; "Other Incentive/Allowances" sums across all six teams). It is a pure rollup of the Category Detail figures below — not a separate calculation.</div>
    </div>

    <div class="card">
        <div class="card-title-bar">Category Detail — All Months</div>
        <?php foreach (RECON_ORDER as $cat):
            // Union of earn/ded line labels across every period's block for this category,
            // in the order they first appear (categories can gain/lose named columns over time).
            $earnLabels = []; $dedLabels = [];
            foreach ($periods as $p) {
                foreach ($recons[$p['id']]['blocks'] as $b) {
                    if ($b['cat'] !== $cat) continue;
                    foreach ($b['earn_lines'] as $l) if (!in_array($l['label'], $earnLabels, true)) $earnLabels[] = $l['label'];
                    foreach ($b['ded_lines']  as $l) if (!in_array($l['label'], $dedLabels, true))  $dedLabels[]  = $l['label'];
                }
            }
            // Pull each period's block for this category (may be missing if no employees that month)
            $catBlocks = [];
            foreach ($periods as $p) {
                $found = null;
                foreach ($recons[$p['id']]['blocks'] as $b) if ($b['cat'] === $cat) { $found = $b; break; }
                $catBlocks[$p['id']] = $found;
            }
        ?>
        <div class="block-title"><?php echo htmlspecialchars(RECON_LABELS[$cat]); ?></div>
        <div class="table-scroll">
        <table class="grid">
            <thead>
                <tr class="team">
                    <td class="desc"><?php echo htmlspecialchars(RECON_LABELS[$cat]); ?></td>
                    <?php foreach ($periods as $p): ?>
                        <td class="num"><?php echo htmlspecialchars(period_short_label($p)); ?></td>
                    <?php endforeach; ?>
                    <td class="num">Total</td>
                </tr>
                <tr>
                    <td class="desc">No of Employee</td>
                    <?php $hcTotal = 0; foreach ($periods as $p):
                        $b = $catBlocks[$p['id']]; $hc = $b ? $b['headcount'] : 0; $hcTotal += $hc; ?>
                        <td class="num"><?php echo $hc; ?></td>
                    <?php endforeach; ?>
                    <td class="num"><?php echo $hcTotal; ?></td>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($earnLabels as $label):
                    $rowTotal = 0.0; ?>
                <tr>
                    <td class="desc"><?php echo htmlspecialchars($label); ?></td>
                    <?php foreach ($periods as $p):
                        $b = $catBlocks[$p['id']];
                        $v = $b ? block_value($b, $label) : 0.0;
                        $rowTotal += $v; ?>
                        <td class="num">Rs. <?php echo number_format($v, 2); ?></td>
                    <?php endforeach; ?>
                    <td class="num">Rs. <?php echo number_format($rowTotal, 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total">
                    <td class="desc">Total Earning</td>
                    <?php $rowTotal = 0.0; foreach ($periods as $p):
                        $b = $catBlocks[$p['id']]; $v = $b ? $b['total_earning'] : 0.0; $rowTotal += $v; ?>
                        <td class="num">Rs. <?php echo number_format($v, 2); ?></td>
                    <?php endforeach; ?>
                    <td class="num">Rs. <?php echo number_format($rowTotal, 2); ?></td>
                </tr>
                <?php foreach ($dedLabels as $label):
                    $rowTotal = 0.0; ?>
                <tr>
                    <td class="desc"><?php echo htmlspecialchars($label); ?></td>
                    <?php foreach ($periods as $p):
                        $b = $catBlocks[$p['id']];
                        $v = $b ? block_value($b, $label) : 0.0;
                        $rowTotal += $v; ?>
                        <td class="num">Rs. <?php echo number_format($v, 2); ?></td>
                    <?php endforeach; ?>
                    <td class="num">Rs. <?php echo number_format($rowTotal, 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total">
                    <td class="desc">Total Deduction</td>
                    <?php $rowTotal = 0.0; foreach ($periods as $p):
                        $b = $catBlocks[$p['id']]; $v = $b ? $b['total_deduction'] : 0.0; $rowTotal += $v; ?>
                        <td class="num">Rs. <?php echo number_format($v, 2); ?></td>
                    <?php endforeach; ?>
                    <td class="num">Rs. <?php echo number_format($rowTotal, 2); ?></td>
                </tr>
                <tr class="net">
                    <td class="desc">Net Salary</td>
                    <?php $rowTotal = 0.0; foreach ($periods as $p):
                        $b = $catBlocks[$p['id']]; $v = $b ? $b['net_salary'] : 0.0; $rowTotal += $v; ?>
                        <td class="num">Rs. <?php echo number_format($v, 2); ?></td>
                    <?php endforeach; ?>
                    <td class="num">Rs. <?php echo number_format($rowTotal, 2); ?></td>
                </tr>
            </tbody>
        </table>
        </div>
        <?php endforeach; ?>
        <div class="note">
            "Other Incentive/Allowances" and "Other Deduction" are always the remainder — Total Earning/Deduction minus
            every named line above it — never a hand-picked column list, so nothing can fall out of the total.
            Every column above is a live payroll period pulled straight from the database at page-load time.
        </div>
    </div>

    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>