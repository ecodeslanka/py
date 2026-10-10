<?php
/**
 * salary_reconciliation.php
 * ─────────────────────────────────────────────────────────────────────────
 * "Salary Reconciliation" — standalone page + Excel export that reproduces
 * the company's manually-maintained Salary_Rec.xlsx "2- Total Salary Rec"
 * tab, for one payroll month at a time:
 *   - Overview: Earnings by category (SR/MR/CC/ACC/Stores/Office) + HC,
 *     EPF & ETF (15% of Basic), grand Total; Deduction summary by type
 *     (Amount + headcount affected) + Total Deduction; Net Salary.
 *   - One detail block per category: headcount, Basic Salary, Attendance
 *     & Allowance, that category's own named incentive components (the
 *     exact same fields shown on its per-category tab in the main Salary
 *     Excel export), an "Other Incentive/Allowances" catch-all, Total
 *     Earning, itemised deductions, Total Deduction, Net Salary.
 *
 * Every figure is resolved the SAME way print_payslips.php / the main
 * "Generate Salary Excel" export resolve it: a live value from
 * incentive_entries / sr_fuel_entries / salary_advances / the employee
 * profile wins when one exists, otherwise the saved salary-sheet snapshot
 * column is used, and Total Earnings/Deductions/Net Salary are DERIVED by
 * summing the itemized rows — never a separately stored total column.
 * "Other Incentive/Allowances" and "Other Deduction" here are always the
 * REMAINDER (Total Earning/Deduction minus every named line above it),
 * never a hand-picked list — so nothing can silently drop out of the
 * total the way a hard-coded external-file formula reference could in the
 * original manually-built workbook.
 *
 * This page is self-contained (does not include salary_excel_generate.php,
 * to avoid function-name collisions between the two pages) but shares the
 * identical data-layer logic with it byte-for-byte, so the two exports
 * always agree.
 * ─────────────────────────────────────────────────────────────────────────
 */

ob_start();
include 'config.php';

// ── Category registry ────────────────────────────────────────────────────
const ALL_CATEGORIES = ['SR', 'CC', 'ACC', 'MR', 'ST', 'OFF'];
const RECON_ORDER     = ['SR', 'MR', 'CC', 'ACC', 'ST', 'OFF']; // display order on this page/sheet
const RECON_LABELS    = ['SR'=>'Sales Team','MR'=>'Merchandising Team','CC'=>'Cash Collectors','ACC'=>'Assistant Cash Collectors','ST'=>'Stores Team','OFF'=>'Office Team'];

// =========================================================================
//  DATA LAYER — identical logic to salary_excel_generate.php, so this page
//  and the main Salary Excel export always reconcile with each other.
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
function period_label($period)      { return date('F Y', strtotime(sprintf('%04d-%02d-01', (int)$period['year'], (int)$period['month']))); }

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

/** Builds the full reconciliation dataset (overview + per-category blocks)
 *  in a plain array shape — used identically by both the HTML preview and
 *  the Excel export, so they can never disagree. */
function build_reconciliation(array $byCategory, array $CATEGORY_DETAIL_COLUMNS) {
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
    ];
}

// =========================================================================
//  MINI XLSX WRITER — same dependency-free builder used by the main export
// =========================================================================
class MiniXLSXWriter {
    private $sheets = [];
    const STYLE_DEFAULT   = 0;
    const STYLE_TITLE     = 1;
    const STYLE_HEADER    = 2;
    const STYLE_NUM       = 3;
    const STYLE_NUM_BOLD  = 4;
    const STYLE_TEXT_BOLD = 5;
    const STYLE_BAND      = 6;
    const STYLE_SUBTOTAL      = 7;
    const STYLE_SUBTOTAL_TEXT = 8;

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
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            . '<fonts count="4">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><sz val="11"/><b/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><sz val="14"/><b/><name val="Calibri"/></font>'
            . '<font><sz val="11"/><b/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="5">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1A1A18"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEFEDE6"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF7F6F2"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="9">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="164" fontId="3" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="3" fillId="4" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
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

/** Writes the "2- Total Salary Rec"-style sheet from a build_reconciliation() result. */
function write_reconciliation_sheet(MiniXLSXWriter $xl, $sheetIdx, $period, array $recon) {
    $label = period_label($period);

    $xl->addRow($sheetIdx, [['YELO LOGISTICS - SALARY RECONCILIATION - ' . strtoupper($label), 's', MiniXLSXWriter::STYLE_TITLE]]);
    $xl->merge($sheetIdx, 'A1:C1');
    $xl->addRow($sheetIdx, []);

    $xl->addRow($sheetIdx, [['Description', 's', MiniXLSXWriter::STYLE_HEADER], [$label, 's', MiniXLSXWriter::STYLE_HEADER], ['HC', 's', MiniXLSXWriter::STYLE_HEADER]]);
    foreach ($recon['earn_by_cat'] as $c) {
        $xl->addRow($sheetIdx, [[$c['label'], 's', 0], [$c['earnings'], 'n', MiniXLSXWriter::STYLE_NUM], [$c['hc'], 'n', MiniXLSXWriter::STYLE_NUM]]);
    }
    $xl->addRow($sheetIdx, [['EPF & ETF (15% of Basic)', 's', 0], [$recon['epf_etf'], 'n', MiniXLSXWriter::STYLE_NUM], ['', 's', 0]]);
    $xl->addRow($sheetIdx, [['Total', 's', MiniXLSXWriter::STYLE_TEXT_BOLD], [$recon['grand_earn'], 'n', MiniXLSXWriter::STYLE_NUM_BOLD], [$recon['total_hc'], 'n', MiniXLSXWriter::STYLE_NUM_BOLD]]);
    $xl->addRow($sheetIdx, []);

    $xl->addRow($sheetIdx, [['Deduction', 's', MiniXLSXWriter::STYLE_HEADER], ['Amount', 's', MiniXLSXWriter::STYLE_HEADER], ['HC', 's', MiniXLSXWriter::STYLE_HEADER]]);
    foreach ($recon['ded_lines'] as $d) {
        $xl->addRow($sheetIdx, [[$d['label'], 's', 0], [$d['amount'], 'n', MiniXLSXWriter::STYLE_NUM], [$d['hc'], 'n', MiniXLSXWriter::STYLE_NUM]]);
    }
    $xl->addRow($sheetIdx, [['Other Deduction', 's', 0], [$recon['other_ded'], 'n', MiniXLSXWriter::STYLE_NUM], ['', 's', 0]]);
    $xl->addRow($sheetIdx, [['Total Deduction', 's', MiniXLSXWriter::STYLE_TEXT_BOLD], [$recon['total_ded'], 'n', MiniXLSXWriter::STYLE_NUM_BOLD], ['', 's', 0]]);
    $xl->addRow($sheetIdx, []);
    $xl->addRow($sheetIdx, [['Net Salary (All Categories)', 's', MiniXLSXWriter::STYLE_TEXT_BOLD], [$recon['net_salary'], 'n', MiniXLSXWriter::STYLE_NUM_BOLD], ['', 's', 0]]);
    $xl->addRow($sheetIdx, []);
    $xl->addRow($sheetIdx, []);

    foreach ($recon['blocks'] as $b) {
        $xl->addRow($sheetIdx, [[$b['label'], 's', MiniXLSXWriter::STYLE_BAND], ['', 's', MiniXLSXWriter::STYLE_BAND], ['', 's', MiniXLSXWriter::STYLE_BAND]]);
        $xl->addRow($sheetIdx, [['No of Employee', 's', 0], ['', 's', 0], [$b['headcount'], 'n', MiniXLSXWriter::STYLE_NUM]]);
        $xl->addRow($sheetIdx, []);
        foreach ($b['earn_lines'] as $l) {
            $xl->addRow($sheetIdx, [[$l['label'], 's', 0], [$l['value'], 'n', MiniXLSXWriter::STYLE_NUM], ['', 's', 0]]);
        }
        $xl->addRow($sheetIdx, [['Total Earning', 's', MiniXLSXWriter::STYLE_TEXT_BOLD], [$b['total_earning'], 'n', MiniXLSXWriter::STYLE_NUM_BOLD], ['', 's', 0]]);
        $xl->addRow($sheetIdx, []);
        foreach ($b['ded_lines'] as $l) {
            $xl->addRow($sheetIdx, [[$l['label'], 's', 0], [$l['value'], 'n', MiniXLSXWriter::STYLE_NUM], ['', 's', 0]]);
        }
        $xl->addRow($sheetIdx, [['Total Deduction', 's', MiniXLSXWriter::STYLE_TEXT_BOLD], [$b['total_deduction'], 'n', MiniXLSXWriter::STYLE_NUM_BOLD], ['', 's', 0]]);
        $xl->addRow($sheetIdx, [['Net Salary', 's', MiniXLSXWriter::STYLE_SUBTOTAL_TEXT], [$b['net_salary'], 'n', MiniXLSXWriter::STYLE_SUBTOTAL], ['', 's', MiniXLSXWriter::STYLE_SUBTOTAL_TEXT]]);
        $xl->addRow($sheetIdx, []);
        $xl->addRow($sheetIdx, []);
    }

    $xl->setWidths($sheetIdx, [32, 18, 10]);
    $xl->freeze($sheetIdx, 3);
}

// =========================================================================
//  EXPORT HANDLER — must run before ANY HTML output
// =========================================================================
$action = $_GET['do'] ?? '';
if ($action === 'export') {
    $pid = intval($_GET['period_id'] ?? 0);
    $period = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM payroll_periods WHERE id=$pid"));
    if (!$pid || !$period) { http_response_code(400); echo 'Invalid payroll period.'; exit; }

    $period_start = period_start_date($period);
    $byCategory = load_recon_data($conn, $pid, RECON_ORDER, $period_start);
    $recon = build_reconciliation($byCategory, $CATEGORY_DETAIL_COLUMNS);

    $xl = new MiniXLSXWriter();
    $idx = $xl->addSheet('2- Total Salary Rec');
    write_reconciliation_sheet($xl, $idx, $period, $recon);

    $fname = 'Salary_Reconciliation_' . str_replace(' ', '_', period_label($period)) . '.xlsx';
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

$recon = null;
if ($active_period) {
    $period_start = period_start_date($active_period);
    $byCategory = load_recon_data($conn, $sel_period_id, RECON_ORDER, $period_start);
    $recon = build_reconciliation($byCategory, $CATEGORY_DETAIL_COLUMNS);
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
.wrap{max-width:1100px;margin:0 auto;padding:0 20px 40px;}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:22px 24px;margin-bottom:18px;box-shadow:var(--shadow-sm);}
.card-title{font-family:var(--display);font-size:14px;font-weight:700;margin-bottom:14px;color:var(--ink2);display:flex;align-items:center;gap:8px;}
.field label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--ink3);margin-bottom:6px;}
.field select{width:260px;padding:10px 12px;border:1px solid var(--border);border-radius:var(--r);font-size:14px;font-family:var(--sans);background:#fff;}
.actions{display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:var(--r);font-size:13px;font-weight:700;font-family:var(--sans);cursor:pointer;border:none;text-decoration:none;transition:all .15s;}
.btn-dark{background:var(--accent);color:#fff;}.btn-dark:hover{background:#333;}
.btn-green{background:#22c55e;color:#fff;}.btn-green:hover{background:#16a34a;}
table.prev{width:100%;border-collapse:collapse;font-size:13px;}
table.prev th{text-align:left;padding:9px 12px;background:var(--bg);border-bottom:2px solid var(--border2);font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--ink3);}
table.prev td{padding:8px 12px;border-bottom:1px solid var(--border);}
table.prev tr.total td{font-weight:800;border-top:2px solid var(--border2);background:#faf9f6;}
table.prev td.num,table.prev th.num{text-align:right;font-family:var(--mono);}
.block{border:1px solid var(--border);border-radius:var(--r);margin-bottom:14px;overflow:hidden;}
.block-hdr{background:var(--accent);color:#fff;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;font-family:var(--display);font-weight:700;font-size:13px;}
.block-hdr .hc{font-family:var(--mono);font-weight:400;font-size:12px;opacity:.8;}
.block table.prev th,.block table.prev td{padding:7px 12px;}
.net-row td{background:#eef7ee;font-weight:800;}
</style>

<div class="pg-hdr">
    <div>
        <div class="pg-hdr-title"><i class="fa-solid fa-scale-balanced"></i> Salary Reconciliation</div>
        <div class="pg-hdr-sub">Same layout as Salary_Rec.xlsx's "2- Total Salary Rec" tab — earnings by category, deduction summary, and a per-category breakdown down to Net Salary. Figures use the same live-resolution rules as the printed payslip / Generate Salary Excel export, so this always agrees with them.</div>
    </div>
</div>

<div class="wrap">
    <form method="GET" id="genForm">
        <div class="card">
            <div class="card-title"><i class="fa-solid fa-sliders"></i> Payroll Period</div>
            <div class="field">
                <label>Month</label>
                <select name="period_id" onchange="document.getElementById('genForm').submit()">
                    <?php foreach ($all_periods as $pp): ?>
                        <option value="<?php echo $pp['id']; ?>" <?php echo $pp['id']==$sel_period_id?'selected':''; ?>>
                            <?php echo date('F Y', strtotime($pp['year'].'-'.$pp['month'].'-01')); ?>
                            <?php echo $pp['status']==='Open' ? ' (Open)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="actions">
                <button type="submit" name="do" value="preview" class="btn btn-dark"><i class="fa-solid fa-arrows-rotate"></i> Refresh Preview</button>
                <button type="submit" name="do" value="export" formtarget="_blank" class="btn btn-green"><i class="fa-solid fa-download"></i> Generate &amp; Download Reconciliation (.xlsx)</button>
            </div>
        </div>
    </form>

    <?php if ($recon): ?>
    <div class="card">
        <div class="card-title"><i class="fa-solid fa-table-list"></i> Earnings by Category — <?php echo period_label($active_period); ?></div>
        <table class="prev">
            <thead><tr><th>Description</th><th class="num">Amount</th><th class="num">HC</th></tr></thead>
            <tbody>
                <?php foreach ($recon['earn_by_cat'] as $c): ?>
                <tr><td><?php echo htmlspecialchars($c['label']); ?></td><td class="num">Rs. <?php echo number_format($c['earnings'],2); ?></td><td class="num"><?php echo $c['hc']; ?></td></tr>
                <?php endforeach; ?>
                <tr><td>EPF &amp; ETF (15% of Basic)</td><td class="num">Rs. <?php echo number_format($recon['epf_etf'],2); ?></td><td class="num"></td></tr>
                <tr class="total"><td>Total</td><td class="num">Rs. <?php echo number_format($recon['grand_earn'],2); ?></td><td class="num"><?php echo $recon['total_hc']; ?></td></tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <div class="card-title"><i class="fa-solid fa-minus"></i> Deduction Summary</div>
        <table class="prev">
            <thead><tr><th>Description</th><th class="num">Amount</th><th class="num">HC</th></tr></thead>
            <tbody>
                <?php foreach ($recon['ded_lines'] as $d): ?>
                <tr><td><?php echo htmlspecialchars($d['label']); ?></td><td class="num">Rs. <?php echo number_format($d['amount'],2); ?></td><td class="num"><?php echo $d['hc']; ?></td></tr>
                <?php endforeach; ?>
                <tr><td>Other Deduction</td><td class="num">Rs. <?php echo number_format($recon['other_ded'],2); ?></td><td class="num"></td></tr>
                <tr class="total"><td>Total Deduction</td><td class="num">Rs. <?php echo number_format($recon['total_ded'],2); ?></td><td class="num"></td></tr>
                <tr class="total"><td>Net Salary (All Categories)</td><td class="num">Rs. <?php echo number_format($recon['net_salary'],2); ?></td><td class="num"></td></tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <div class="card-title"><i class="fa-solid fa-layer-group"></i> Category Detail</div>
        <?php foreach ($recon['blocks'] as $b): ?>
        <div class="block">
            <div class="block-hdr"><span><?php echo htmlspecialchars($b['label']); ?></span><span class="hc"><?php echo $b['headcount']; ?> employees</span></div>
            <table class="prev">
                <tbody>
                    <?php foreach ($b['earn_lines'] as $l): ?>
                    <tr><td><?php echo htmlspecialchars($l['label']); ?></td><td class="num">Rs. <?php echo number_format($l['value'],2); ?></td></tr>
                    <?php endforeach; ?>
                    <tr class="total"><td>Total Earning</td><td class="num">Rs. <?php echo number_format($b['total_earning'],2); ?></td></tr>
                    <?php foreach ($b['ded_lines'] as $l): ?>
                    <tr><td><?php echo htmlspecialchars($l['label']); ?></td><td class="num">Rs. <?php echo number_format($l['value'],2); ?></td></tr>
                    <?php endforeach; ?>
                    <tr class="total"><td>Total Deduction</td><td class="num">Rs. <?php echo number_format($b['total_deduction'],2); ?></td></tr>
                    <tr class="net-row"><td>Net Salary</td><td class="num">Rs. <?php echo number_format($b['net_salary'],2); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
        <div class="note" style="font-size:12px;color:var(--ink3);margin-top:4px;line-height:1.5;">
            "Other Incentive/Allowances" and "Other Deduction" are always the remainder — Total Earning/Deduction minus
            every named line above it — never a hand-picked column list, so nothing can fall out of the total.
        </div>
    </div>
    <?php else: ?>
    <div class="card"><div class="note">No payroll periods found. Create one first from the Payroll Periods page.</div></div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
