<?php
/**
 * live_salary_sheet.php — Live Salary Sheet: every employee's salary for a payroll month in one full-screen table,
 * with the columns of salary_excel_generate.php, worked out from LIVE data (not the saved salary sheets):
 *   attendance + special holidays (norm days), employee profile (basic, no pay days, welfare, donations, retention …),
 *   approved salary advances of the month, approved loans (monthly instalment), EPF / ETF settings,
 *   reimbursements saved for the month in Incentive Entry (else the profile), SR fuel entries.
 * Incentives come from the Incentives Sheet table (incentive_sheet_entries, incentive_sheet_lib.php) by staff category;
 * before November 2026 that table is filled from the old Incentive Entries.
 */
include 'config.php';
@include_once __DIR__ . '/auth.php';
if (function_exists('requireLogin')) requireLogin();
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
date_default_timezone_set('Asia/Colombo');

function ls_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function ls_cols($conn, $t) { static $c = []; if (!isset($c[$t])) { $c[$t] = []; $r = ls_q($conn, "SHOW COLUMNS FROM `$t`"); while ($r && ($x = mysqli_fetch_assoc($r))) $c[$t][$x['Field']] = 1; } return $c[$t]; }
function ls_has_table($conn, $t) { $r = ls_q($conn, "SHOW TABLES LIKE '$t'"); return $r && mysqli_num_rows($r) > 0; }
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

const LS_CATS = ['SR' => 'Sales Representatives', 'MR' => 'Merchandisers', 'CC' => 'Cash Collectors', 'ACC' => 'Assistant Cash Collectors', 'ST' => 'Store Staff', 'OFF' => 'Office Staff', 'OTH' => 'Other Staff (no salary sheet category)'];
const LS_CAT_ALIAS = ['SACC' => 'CC'];          /* the CC salary sheet also pays SACC */

/* ── payroll months ── */
$periods = []; $r = ls_q($conn, "SELECT id, year, month, status FROM payroll_periods ORDER BY year DESC, month DESC");
while ($r && ($x = mysqli_fetch_assoc($r))) $periods[] = $x;
$pid = (int)($_GET['period_id'] ?? 0);
if (!$pid && $periods) { foreach ($periods as $p) if (strtolower((string)$p['status']) === 'open') { $pid = (int)$p['id']; break; } if (!$pid) $pid = (int)$periods[0]['id']; }
$period = null; foreach ($periods as $p) if ((int)$p['id'] === $pid) $period = $p;
$MON = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

$rows = []; $warn = [];
if ($period) {
    $from = sprintf('%04d-%02d-01', (int)$period['year'], (int)$period['month']); $to = date('Y-m-t', strtotime($from)); $mdays = (int)date('t', strtotime($from));
    $ec = ls_cols($conn, 'employees');
    $col = function ($c, $alias = 'e') use ($ec) { return isset($ec[$c]) ? "$alias.`$c`" : 'NULL'; };

    /* employees of the month as they were then (payroll_month_employees_lib.php): leavers of the month stay in it,
       promotions after the month are undone (designation / category / code of the month), joiners from their join date */
    include_once __DIR__ . '/payroll_month_employees_lib.php';
    $scCode = []; $q = ls_q($conn, "SELECT id, category_code FROM staff_categories"); while ($q && ($x = mysqli_fetch_row($q))) $scCode[(int)$x[0]] = $x[1];
    $dCat = []; $q = ls_q($conn, "SELECT id, staff_category_id FROM designations"); while ($q && ($x = mysqli_fetch_row($q))) $dCat[(int)$x[0]] = (int)$x[1];
    $emps = [];
    foreach (pme_employees($conn, $from, $to) as $eid => $e) {
        $cat = '';
        foreach ([$scCode[(int)($e['staff_category_id'] ?? 0)] ?? '', $scCode[$dCat[(int)($e['designation_id'] ?? 0)] ?? 0] ?? ''] as $c) { $c = strtoupper(trim((string)$c)); $c = LS_CAT_ALIAS[$c] ?? $c; if (isset(LS_CATS[$c])) { $cat = $c; break; } }
        if ($cat === '') $cat = 'OTH';                                                   /* every employee of the month is listed */
        $e['cat'] = $cat; $emps[(int)$eid] = $e;
    }
    $ids = $emps ? implode(',', array_keys($emps)) : '0';

    /* attendance + special holidays → norm days / days worked */
    $att = []; $q = ls_q($conn, "SELECT employee_id, COUNT(*) c FROM attendance WHERE att_date BETWEEN '$from' AND '$to' AND employee_id IN ($ids) GROUP BY employee_id");
    while ($q && ($x = mysqli_fetch_row($q))) $att[(int)$x[0]] = (int)$x[1];
    $shd = []; $q = ls_q($conn, "SELECT * FROM special_holidays WHERE active = 1 AND date_from <= '$to' AND date_to >= '$from'");
    while ($q && ($sh = mysqli_fetch_assoc($q))) {
        for ($d = max($sh['date_from'], $from); $d <= min($sh['date_to'], $to); $d = date('Y-m-d', strtotime($d . ' +1 day'))) $shd[$d][] = $sh;
    }
    $holidays = function ($e) use ($shd) {
        $n = 0;
        foreach ($shd as $list) foreach ($list as $sh) {
            $ok = ($sh['target_type'] ?? '') === 'all'; $tids = json_decode((string)($sh['target_ids'] ?? ''), true) ?: [];
            if (!$ok && ($sh['target_type'] ?? '') === 'employee') $ok = in_array((int)$e['id'], $tids);
            if (!$ok && ($sh['target_type'] ?? '') === 'staff_category') $ok = in_array((int)($e['staff_category_id'] ?? 0), $tids);
            if (!$ok && ($sh['target_type'] ?? '') === 'designation') $ok = in_array((int)($e['designation_id'] ?? 0), $tids);
            if ($ok) { $n++; break; }
        }
        return $n;
    };

    /* EPF / ETF */
    $ep = ls_has_table($conn, 'epf_etf_settings') ? mysqli_fetch_assoc(ls_q($conn, "SELECT * FROM epf_etf_settings LIMIT 1") ?: null) : null;
    $epfE = $ep ? (float)$ep['epf_employee'] : 8; $epfR = $ep ? (float)$ep['epf_employer'] : 12; $etfR = $ep ? (float)$ep['etf_employer'] : 3;

    /* salary configuration by designation (basic for ST, attendance allowance tiers) */
    $cfg = [];
    if (ls_has_table($conn, 'sr_salary_config')) { $q = ls_q($conn, "SELECT * FROM sr_salary_config"); while ($q && ($x = mysqli_fetch_assoc($q))) $cfg[(int)$x['designation_id']] = $x; }

    /* approved salary advances of the month */
    $adv = []; $q = ls_q($conn, "SELECT employee_id, SUM(amount) FROM salary_advances WHERE status = 'Approved' AND employee_id IN ($ids) AND request_date BETWEEN '$from' AND '$to' GROUP BY employee_id");
    while ($q && ($x = mysqli_fetch_row($q))) $adv[(int)$x[0]] = (float)$x[1];

    /* approved loans: this month's instalment (never more than the balance) */
    $loan = [];
    if (ls_has_table($conn, 'loan_requests')) {
        $q = ls_q($conn, "SELECT lr.employee_id, lr.loan_amount, lr.total_repayment, lr.monthly_instalment,
                             COALESCE((SELECT SUM(lp.total_paid) FROM loan_payments lp WHERE lp.loan_request_id = lr.id), 0) paid
                          FROM loan_requests lr WHERE lr.status = 'Approved' AND lr.employee_id IN ($ids)");
        while ($q && ($x = mysqli_fetch_assoc($q))) {
            $bal = round(((float)$x['total_repayment'] ?: (float)$x['loan_amount']) - (float)$x['paid'], 2); if ($bal <= 0) continue;
            $loan[(int)$x['employee_id']] = ($loan[(int)$x['employee_id']] ?? 0) + min((float)$x['monthly_instalment'], $bal);
        }
    }

    /* incentives and reimbursements of the month: incentive_sheet_entries (the old incentive_entries are brought in first) */
    include_once __DIR__ . '/incentive_sheet_lib.php';
    isl_sync($conn, $period);
    $isl = isl_rows($conn, $pid);
    $rmbKeys = ['meal' => ['meal_sr', 'meal_mr', '_meal'], 'travel' => ['travel_sr', 'travel_mr'], 'mobile' => ['mobile_sr']];
    $rmb = function ($eid, $pat, $profile) use ($isl, $rmbKeys) {
        foreach ($rmbKeys[$pat] as $k) if (isset($isl[$eid][$k]) && $isl[$eid][$k]['amount'] !== null) return (float)$isl[$eid][$k]['amount'];
        return (float)$profile;
    };
    /* what the old salary sheets saved for the month (salary_sheet_entries): manual overrides and Additional Earnings / Deductions rows */
    $sse = [];
    $q = ls_q($conn, "SELECT * FROM salary_sheet_entries WHERE payroll_period_id = $pid AND employee_id IN ($ids)");
    while ($q && ($x = mysqli_fetch_assoc($q))) $sse[(int)$x['employee_id']] = $x;
    $addRows = function ($json) { $o = []; foreach ((json_decode((string)$json, true) ?: []) as $r) { $a = round((float)($r['amount'] ?? 0), 2); if ($a != 0) $o[] = ['label' => trim((string)($r['desc'] ?? '')) ?: 'Other', 'amount' => $a]; } return $o; };
    $fuel = [];
    if (ls_has_table($conn, 'sr_fuel_entries')) { $q = ls_q($conn, "SELECT employee_id, SUM(fuel_rate * fuel_liter) FROM sr_fuel_entries WHERE payroll_period_id = $pid AND employee_id IN ($ids) GROUP BY employee_id"); while ($q && ($x = mysqli_fetch_row($q))) $fuel[(int)$x[0]] = (float)$x[1]; }

    $f = function ($e, $k) { return (float)($e[$k] ?? 0); };
    foreach ($emps as $eid => $e) {
        $cat = $e['cat']; $c = $cfg[(int)($e['designation_id'] ?? 0)] ?? null;
        $hol = $holidays($e); $norm = max(1, $mdays - $hol); $worked = $att[$eid] ?? 0; $rate = round($worked / $norm * 100, 2);
        $basic = $f($e, 'basic_salary'); if ($cat === 'ST' && $c && (float)($c['basic_salary'] ?? 0) > 0) $basic = (float)$c['basic_salary'];
        if ($basic <= 0 && $c) $basic = (float)($c['basic_salary'] ?? 0);
        $no_pay_days = $f($e, 'no_pay'); $no_pay = $basic > 0 ? round($basic / $norm * $no_pay_days, 2) : 0;
        /* attendance allowance: the Salary Configuration tiers of the designation (≥90% / ≥85%), else the employee profile */
        $att_allow = 0.0;
        if ($c && ((float)($c['attendance_90'] ?? 0) > 0 || (float)($c['attendance_85'] ?? 0) > 0)) $att_allow = $rate >= 90 ? (float)($c['attendance_90'] ?? 0) : ($rate >= 85 ? (float)($c['attendance_85'] ?? 0) : 0);
        else $att_allow = $f($e, 'attendance_allowance');
        $meal   = $cat === 'OFF' ? 0.0 : $rmb($eid, 'meal', $e['reimbursement_meal'] ?? 0);
        $travel = in_array($cat, ['SR', 'MR'], true) ? $rmb($eid, 'travel', $e['reimbursement_traveling'] ?? 0) : 0.0;
        $mobile = in_array($cat, ['SR', 'CC'], true) ? $rmb($eid, 'mobile', ($e['reimbursement_mobile'] ?? 0) ?: ($e['mobile_reimbursement'] ?? 0)) : 0.0;
        $fuelA  = $cat === 'SR' ? ($fuel[$eid] ?? $f($e, 'reimbursement_fuel')) : 0.0;
        /* saved salary sheet of the month: its overrides replace the profile values, its additional rows are added */
        $sv = $sse[$eid] ?? []; $ov = function ($k, $live) use ($sv) { return isset($sv[$k]) && $sv[$k] !== null && $sv[$k] !== '' ? (float)$sv[$k] : $live; };
        $fromSheet = [];
        foreach (['override_arrears' => 'arrears', 'override_excess' => 'excess_p', 'override_credit_r' => 'credit_r', 'override_cash_short' => 'cash_short', 'override_good_short' => 'good_short'] as $ok => $ck)
            if (isset($sv[$ok]) && $sv[$ok] !== null && $sv[$ok] !== '') $fromSheet[$ck] = 1;
        $addE = $addRows($sv['additional_earnings'] ?? ''); $addD = $addRows($sv['additional_deductions'] ?? '');
        $addEarn = round(array_sum(array_column($addE, 'amount')), 2); $addDed = round(array_sum(array_column($addD, 'amount')), 2);
        $arrears = $ov('override_arrears', $f($e, 'arrears_salary'));
        /* incentives of the employee's staff category (Incentives Sheet columns; attendance / reimbursements are their own columns here) */
        $incList = $cat === 'OTH' ? [] : isl_incentives($cat, $isl[$eid] ?? [], $e);
        $incent = round(array_sum(array_column($incList, 'amount')), 2);
        $earn = round($basic + $incent + $att_allow + $meal + $travel + $mobile + $fuelA + $arrears + $addEarn, 2);

        $epf8 = round($basic * $epfE / 100, 2);
        $d = ['epf8' => $epf8, 'welfare' => $f($e, 'welfare_amount'), 'w_loan' => $f($e, 'welfare_loan_deduction'), 'w_soc' => $f($e, 'welfare_society'),
              'donations' => $f($e, 'donations'), 'sal_adv' => $adv[$eid] ?? $f($e, 'salary_advance'), 'loan_ded' => ($loan[$eid] ?? 0) > 0 ? round($loan[$eid], 2) : $f($e, 'loan_deduction'),
              'credit_r' => $ov('override_credit_r', $f($e, 'credit_recovery')), 'cash_short' => in_array($cat, ['CC', 'ACC'], true) ? $ov('override_cash_short', $f($e, 'cash_short')) : 0.0,
              'good_short' => in_array($cat, ['CC', 'ACC'], true) ? $ov('override_good_short', $f($e, 'good_short')) : 0.0, 'no_pay' => $no_pay, 'retention' => $f($e, 'retention'),
              'excess_p' => $ov('override_excess', $f($e, 'excess_payment')), 'payee' => $f($e, 'payee') ?: $f($e, 'income_tax'), 'add_ded' => $addDed];
        $ded = round(array_sum($d), 2);
        $epfR_ = round($basic * $epfR / 100, 2); $etf = round($basic * $etfR / 100, 2); $bonus = $basic > 0 ? round($basic / 12, 2) : 0; $grat = $basic > 0 ? round($basic / 24, 2) : 0; $ins = $f($e, 'insurance_amount');
        /* details shown when the name is clicked (the fields the employee record has) */
        $det = [];
        foreach (['employee_full_name' => 'Full Name', 'name_with_initials' => 'Name with Initials', 'employee_id' => 'Employee Code', 'epf_number' => 'EPF No', 'nic_number' => 'NIC',
                  'date_of_birth' => 'Date of Birth', 'gender' => 'Gender', 'mobile_number' => 'Mobile', 'mobile' => 'Mobile', 'contact_number' => 'Contact No', 'phone' => 'Phone', 'email' => 'Email',
                  'address' => 'Address', 'date_of_join' => 'Date of Join', 'status' => 'Status', 'bank_code' => 'Bank', 'bank_branch_code' => 'Bank Branch', 'account_number' => 'Account No'] as $k => $lb)
            if (isset($e[$k]) && trim((string)$e[$k]) !== '' && !isset($det[$lb])) $det[$lb] = (string)$e[$k];
        $det = ['Designation' => $e['designation_name'] ?? '', 'Category' => $cat . ' — ' . LS_CATS[$cat]] + $det;
        if (!empty($e['pme_note'])) $det['This month'] = $e['pme_note'];
        $rows[] = ['cat' => $cat, 'code' => $e['employee_id'] ?? '', 'epf_no' => $e['epf_number'] ?? ($e['epf_no'] ?? ''), 'name' => $e['employee_full_name'] ?? ($e['name_with_initials'] ?? ''),
            'initials' => trim((string)($e['name_with_initials'] ?? '')) !== '' ? trim($e['name_with_initials']) : ($e['employee_full_name'] ?? ''), 'det' => $det, 'eid' => (int)$eid,
            'desig' => $e['designation_name'] ?? '', 'status' => $e['status'] ?? '', 'note' => $e['pme_note'] ?? '', 'left' => $e['pme_exit'] ?? null, 'doj' => $e['date_of_join'] ?? '', 'norm' => $norm, 'worked' => $worked, 'rate' => $rate,
            'basic' => $basic, 'no_pay_days' => $no_pay_days, 'basic_adj' => round($basic - $no_pay, 2),
            'incentive' => $incent, 'inc_list' => $incList, 'add_e' => $addE, 'add_d' => $addD, 'add_earn' => $addEarn, 'from_sheet' => $fromSheet, 'att_allow' => $att_allow, 'meal' => $meal, 'travel' => $travel, 'mobile' => $mobile, 'fuel' => $fuelA, 'arrears' => $arrears, 'earn' => $earn]
            + $d + ['ded' => $ded, 'net' => round($earn - $ded, 2), 'epf12' => $epfR_, 'etf3' => $etf, 'bonus' => $bonus, 'gratuity' => $grat, 'insurance' => $ins,
            'benefit' => round($earn + $epfR_ + $etf + $bonus + $grat + $ins, 2)];
    }
    $order = array_flip(array_keys(LS_CATS));
    usort($rows, function ($a, $b) use ($order) { return ($order[$a['cat']] <=> $order[$b['cat']]) ?: strcasecmp($a['name'], $b['name']); });
}

/* column layout: key, label, group (''=info, A=additions, D=deductions, S=summary, B=benefits), number? */
$COLS = [
    ['code', 'Emp Code', '', 0], ['epf_no', 'EPF No', '', 0], ['name', 'Name', '', 0], ['desig', 'Designation', '', 0], ['status', 'Status', '', 0], ['doj', 'Date of Join', '', 0],
    ['norm', 'Norm Days', '', 1], ['worked', 'Days Worked', '', 1], ['rate', 'Att %', '', 1], ['basic', 'Basic Salary', '', 2], ['no_pay_days', 'No Pay Days', '', 1], ['basic_adj', 'Basic Adjusted', '', 2],
    ['incentive', 'Incentive', 'A', 2], ['att_allow', 'Attendance Allowance', 'A', 2], ['meal', 'Meal Reimb.', 'A', 2], ['travel', 'Travelling Reimb.', 'A', 2], ['mobile', 'Mobile Reimb.', 'A', 2], ['fuel', 'Fuel Reimb.', 'A', 2], ['arrears', 'Arrears', 'A', 2], ['add_earn', 'Other Additions', 'A', 2], ['earn', 'Total Earnings', 'A', 2],
    ['epf8', 'EPF 8%', 'D', 2], ['welfare', 'Welfare', 'D', 2], ['w_loan', 'Welfare Loan', 'D', 2], ['w_soc', 'Welfare Society', 'D', 2], ['donations', 'Donations', 'D', 2], ['sal_adv', 'Salary Advance', 'D', 2], ['loan_ded', 'Loan Deduction', 'D', 2],
    ['credit_r', 'Credit Recovery', 'D', 2], ['cash_short', 'Cash Short', 'D', 2], ['good_short', 'Good Short', 'D', 2], ['no_pay', 'No Pay', 'D', 2], ['retention', 'Retention', 'D', 2], ['excess_p', 'Excess Payment', 'D', 2], ['payee', 'PAYEE', 'D', 2], ['add_ded', 'Other Deductions', 'D', 2], ['ded', 'Total Deductions', 'D', 2],
    ['net', 'Net Salary', 'S', 2], ['epf12', 'EPF 12%', 'B', 2], ['etf3', 'ETF 3%', 'B', 2], ['bonus', 'Bonus Prov.', 'B', 2], ['gratuity', 'Gratuity Prov.', 'B', 2], ['insurance', 'Insurance', 'B', 2], ['benefit', 'Total Benefit', 'B', 2],
];
$SUMK = array_values(array_map(function ($c) { return $c[0]; }, array_filter($COLS, function ($c) { return $c[3] === 2; })));
$num = function ($v, $dp = 2) { if ($v === null) return ''; return abs($v) < 0.005 ? '-' : ($v < 0 ? '(' . number_format(-$v, $dp) . ')' : number_format($v, $dp)); };
$tot = array_fill_keys($SUMK, 0.0); $byCat = [];
foreach ($rows as $x) { foreach ($SUMK as $k) { $tot[$k] += $x[$k]; $byCat[$x['cat']][$k] = ($byCat[$x['cat']][$k] ?? 0) + $x[$k]; } $byCat[$x['cat']]['n'] = ($byCat[$x['cat']]['n'] ?? 0) + 1; }
$totRow = function ($label, $sums, $cls, $cat = '') use ($COLS, $num, $h) {
    $o = '<tr class="' . $cls . '"' . ($cat !== '' ? ' data-cat="' . $cat . '"' : '') . '><td class="sticky"></td><td colspan="9">' . $h($label) . '</td>';
    foreach (array_slice($COLS, 9) as $c) {
        if ($c[0] === 'net') continue;
        $o .= '<td class="n">' . ($c[3] === 2 ? $num($sums[$c[0]] ?? 0) : '') . '</td>';
        if ($c[0] === 'ded') $o .= '<td class="n net">' . $num($sums['net'] ?? 0) . '</td>';
    }
    return $o . '</tr>';
};
$back = (!empty($_SERVER['HTTP_REFERER']) && strpos((string)$_SERVER['HTTP_REFERER'], 'live_salary_sheet.php') === false) ? $_SERVER['HTTP_REFERER'] : 'salary_excel_generate.php';
$plabel = $period ? $MON[(int)$period['month']] . ' ' . $period['year'] : '';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Live Salary Sheet<?= $plabel ? ' — ' . $h($plabel) : '' ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{ --ob-navy:#1F497D; --ob-acc:#4F81BD; --ob-pale:#DCE6F1; --ob-line:#0070C0; --ink:#1d1f22; --grid:#c9c9c4; --win:#ecebe4; --neg:#b3261e; --f: Tahoma, Verdana, "Segoe UI", Geneva, sans-serif; }
html,body{height:100%} *{box-sizing:border-box}
body{margin:0;background:linear-gradient(160deg,#8a8d93,#6f7278);font:11px/1.3 var(--f);color:var(--ink);padding:8px 10px}
.win{height:100%;display:flex;flex-direction:column;background:var(--win);border:1px solid #4a4c52;border-radius:7px 7px 3px 3px;box-shadow:0 6px 22px rgba(0,0,0,.35);overflow:hidden}
.tb{flex:none;display:flex;align-items:center;gap:8px;padding:5px 8px;background:linear-gradient(#e9f0f8,#DCE6F1 45%,#B8CCE4);border-bottom:2px solid var(--ob-line)}
.tb h1{margin:0;font-size:13px;color:var(--ob-navy);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.btn{font:bold 11px var(--f);height:24px;padding:0 11px;border:1px solid #385d8a;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;color:#fff;text-decoration:none;
  background:linear-gradient(#86a9db,var(--ob-acc) 55%,#3d6cad);box-shadow:0 2px 3px rgba(0,0,0,.3),inset 0 1px 0 rgba(255,255,255,.4);text-shadow:0 -1px 0 rgba(0,0,0,.25)}
.btn:hover{background:linear-gradient(#9dbbe6,#5f8fcb 55%,#4877b8)} .btn.pri{background:linear-gradient(#5b87c6,#2E5A8E 55%,var(--ob-navy));border-color:#16365e}
.bar{flex:none;display:flex;flex-wrap:wrap;align-items:center;gap:6px;padding:5px 8px;background:#f6f5f0;border-bottom:1px solid #a7a9ad}
.bar label{font-weight:bold;color:#55585e}
.bar select,.bar input{font:11px var(--f);height:22px;border:1px solid #8e909a;border-radius:2px;padding:0 4px;background:#fff}
.chip{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border:1px solid var(--ob-line);border-radius:10px;background:#fff;color:var(--ob-navy);font-weight:bold;cursor:pointer;user-select:none}
.chip input{margin:0} .chip.off{opacity:.45}
.sp{flex:1}
.kpis{flex:none;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:6px;padding:6px 8px}
.kpi{border:1px solid #a7a9ad;background:#f6f5f0;padding:4px 8px}
.kpi .k{font-weight:bold;color:#55585e;font-size:10px;text-transform:uppercase}
.kpi .v{font-size:15px;font-weight:bold;font-variant-numeric:tabular-nums;color:var(--ob-navy);margin-top:1px}
.title{flex:none;margin:0 8px;background:linear-gradient(#e9f0f8,#DCE6F1 50%,#B8CCE4);border:1px solid var(--ob-line);color:var(--ob-navy);font-weight:bold;font-size:13px;text-align:center;padding:3px;letter-spacing:.06em}
.scroll{flex:1;min-height:0;overflow:auto;margin:4px 8px 8px;border:1px solid #a7a9ad;background:#fff}
table{border-collapse:collapse;font-variant-numeric:tabular-nums;font-size:10.5px;width:auto}
th,td{border:1px solid var(--grid);padding:2px 4px;white-space:nowrap;height:18px}
/* compact columns: the column titles wrap, so small number columns stay narrow */
thead tr:nth-child(2) th{white-space:normal;line-height:1.1;min-width:34px;max-width:66px;vertical-align:middle;font-size:10px;padding:2px 3px}
td.nm a{color:var(--ob-navy);text-decoration:none;border-bottom:1px dotted var(--ob-acc)} td.nm a:hover{color:#0070C0;border-bottom-style:solid}
.mdl{position:fixed;inset:0;background:rgba(30,32,36,.45);display:none;align-items:center;justify-content:center;z-index:8;padding:12px}.mdl.on{display:flex}
.mbox{width:min(820px,100%);max-height:92vh;display:flex;flex-direction:column;background:var(--win);border:1px solid #4a4c52;border-radius:7px;box-shadow:0 10px 30px rgba(0,0,0,.4);overflow:hidden}
.mbody{overflow:auto;padding:8px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.mcard{border:1px solid #a7a9ad;background:#fff} .mcard h3{margin:0;padding:3px 8px;font-size:11px;color:#fff;background:linear-gradient(#3a64a0,var(--ob-navy))}
.mcard.gA h3{background:linear-gradient(#4fa36a,#2e7d46)} .mcard.gD h3{background:linear-gradient(#df8b89,#c0504d 55%,#a33f3c)} .mcard.gB h3{background:linear-gradient(#9b7fd0,#6b4fa8)}
.mcard table{width:100%} .mcard td{height:19px;white-space:normal} .mcard td.k{color:#55585e;width:44%} .mcard tr.t td{font-weight:bold;background:#f6f5f0}
.mnet{grid-column:1/-1;display:flex;justify-content:space-between;align-items:center;padding:6px 10px;border:1px solid #d9b300;background:#fff3c4;font-weight:bold;font-size:13px;color:#3a2c00}
@media (max-width:700px){.mbody{grid-template-columns:1fr}}
thead th{position:sticky;z-index:2;background:linear-gradient(#3a64a0,var(--ob-navy));color:#fff;border-color:#173a63;font-weight:bold;text-align:center}
thead tr:first-child th{top:0} thead tr:nth-child(2) th{top:20px}
th.gA{background:linear-gradient(#4fa36a,#2e7d46)} th.gD{background:linear-gradient(#df8b89,#c0504d 55%,#a33f3c)} th.gS{background:linear-gradient(#ffd966,#f0b400);color:#3a2c00} th.gB{background:linear-gradient(#9b7fd0,#6b4fa8)}
td.n{text-align:right} td.neg{color:var(--neg)} td.emp{color:#b9bac2;text-align:center;background:#fafafa}
td.sticky,th.sticky{position:sticky;left:0;z-index:1;background:#fff} thead th.sticky{z-index:3}
td.b{font-weight:bold} td.sv{color:#7c3aed;font-weight:bold} td.lft{color:var(--neg);font-weight:bold} .mv{display:inline-block;padding:0 4px;border-radius:6px;background:#e9f0f8;border:1px solid var(--ob-line);color:var(--ob-navy);font-size:9.5px} td.net{font-weight:bold;background:#fff8d6} td.tE{background:#e9f7ec;font-weight:bold} td.tD{background:#fdecea;font-weight:bold}
tr.grp td{background:var(--ob-pale);color:var(--ob-navy);font-weight:bold;font-size:11px}
tr.sub td{background:#eef2f7;font-weight:bold} tr.grand td{background:#fff3c4;font-weight:bold;border-top:2px solid #d9b300}
tbody tr.r:hover td{background:#fff1a8}
.note{flex:none;padding:3px 10px 6px;color:#55585e;font-size:10.5px}
.msg{margin:6px 8px 0;padding:4px 8px;border:1px solid #f1aeb5;background:#fdecea;color:var(--neg);font-weight:bold}
@media (max-width:900px){.kpis{grid-template-columns:repeat(2,minmax(0,1fr))} body{padding:4px}}
@media print{body{background:#fff;padding:0}.win{border:0;box-shadow:none;height:auto}.bar,.tb .btn,.kpis,.note{display:none}.scroll{overflow:visible;border:0}thead th{position:static}}
</style></head><body>
<div class="win">
  <div class="tb">
    <a href="<?= $h($back) ?>" class="btn" title="Back"><i class="fa-solid fa-arrow-left"></i> Back</a>
    <h1><i class="fa-solid fa-table"></i> Live Salary Sheet<?= $plabel ? ' — ' . $h($plabel) : '' ?></h1>
    <button class="btn" onclick="lsExport()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
    <button class="btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
  </div>
  <form class="bar" method="get" id="lsForm">
    <label>Payroll Month</label>
    <select name="period_id" onchange="this.form.submit()">
      <?php foreach ($periods as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === $pid ? ' selected' : '' ?>><?= $h($MON[(int)$p['month']] . ' ' . $p['year']) ?><?= $p['status'] ? ' (' . $h($p['status']) . ')' : '' ?></option><?php endforeach; ?>
    </select>
    <?php foreach (LS_CATS as $k => $lbl): ?><label class="chip" title="<?= $h($lbl) ?>"><input type="checkbox" class="lsCat" value="<?= $k ?>" checked onchange="lsApply()"> <?= $k ?> <span style="font-weight:normal">(<?= (int)($byCat[$k]['n'] ?? 0) ?>)</span></label><?php endforeach; ?>
    <input type="search" id="lsQ" placeholder="Search name / code / designation…" oninput="lsApply()" style="width:220px">
    <span class="sp"></span>
    <span style="color:#55585e"><b><?= count($rows) ?></b> employee(s) · live data <?= $h(date('d M Y H:i')) ?></span>
  </form>
  <?php foreach ($warn as $w): ?><div class="msg"><?= $h($w) ?></div><?php endforeach; ?>
  <div class="kpis">
    <div class="kpi"><div class="k">Employees</div><div class="v" id="kN"><?= count($rows) ?></div></div>
    <div class="kpi"><div class="k">Basic Salary</div><div class="v" id="kB"><?= $num($tot['basic'] ?? 0) ?></div></div>
    <div class="kpi"><div class="k">Total Earnings</div><div class="v" id="kE"><?= $num($tot['earn'] ?? 0) ?></div></div>
    <div class="kpi"><div class="k">Total Deductions</div><div class="v" id="kD" style="color:var(--neg)"><?= $num($tot['ded'] ?? 0) ?></div></div>
    <div class="kpi"><div class="k">Net Salary</div><div class="v" id="kNet"><?= $num($tot['net'] ?? 0) ?></div></div>
    <div class="kpi"><div class="k">Total Benefit</div><div class="v" id="kBen"><?= $num($tot['benefit'] ?? 0) ?></div></div>
  </div>
  <div class="title">SALARY SHEET — <?= $h(strtoupper($plabel ?: 'NO PAYROLL MONTH')) ?> (LIVE)</div>
  <div class="scroll">
    <table id="lsTbl">
      <thead>
        <tr><th rowspan="2" class="sticky">#</th><th colspan="12">Employee &amp; Attendance</th><th colspan="9" class="gA">Additions</th><th colspan="16" class="gD">Deductions</th><th rowspan="2" class="gS">Net Salary</th><th colspan="6" class="gB">Employer Benefits</th></tr>
        <tr><?php foreach ($COLS as $c) if ($c[0] !== 'net') echo '<th class="' . ($c[2] ? 'g' . $c[2] : '') . '">' . $h($c[1]) . '</th>'; ?></tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="<?= count($COLS) + 1 ?>" style="text-align:center;padding:20px;color:#8e909a"><?= $period ? 'No employees found for this payroll month.' : 'No payroll month found.' ?></td></tr><?php endif; ?>
      <?php $last = null; $i = 0; foreach ($rows as $x):
        if ($x['cat'] !== $last):
          if ($last !== null): $s = $byCat[$last]; ?>
            <?= $totRow('Sub Total — ' . LS_CATS[$last] . ' (' . (int)$s['n'] . ')', $s, 'sub', $last) ?>
          <?php endif; $last = $x['cat']; ?>
          <tr class="grp" data-cat="<?= $x['cat'] ?>"><td class="sticky"></td><td colspan="<?= count($COLS) ?>"><?= $h($x['cat'] . ' — ' . LS_CATS[$x['cat']]) ?></td></tr>
        <?php endif; $i++; ?>
        <tr class="r" data-cat="<?= $x['cat'] ?>" data-s="<?= $h(mb_strtolower($x['name'] . ' ' . $x['initials'] . ' ' . $x['code'] . ' ' . $x['desig'] . ' ' . $x['epf_no'])) ?>">
          <td class="sticky" style="color:#8e909a"><?= $i ?></td>
          <?php foreach ($COLS as $c) {
              if ($c[0] === 'net') continue;
              $v = $x[$c[0]];
              if ($c[0] === 'incentive') { $tt = implode("\n", array_map(function ($r) use ($num) { return $r['label'] . ': ' . $num($r['amount']); }, $x['inc_list'])); echo '<td class="n"' . ($tt !== '' ? ' title="' . $h($tt) . '"' : '') . '>' . $num($v) . '</td>'; continue; }
              if ($c[0] === 'status' && $x['left']) { echo '<td class="lft" title="' . $h($x['note']) . '">' . $h($v) . ' · left ' . $h(date('d M', strtotime($x['left']))) . '</td>'; continue; }
              if ($c[0] === 'desig' && $x['note'] !== '' && stripos($x['note'], 'moted') !== false) { echo '<td title="' . $h($x['note']) . '">' . $h($v) . ' <span class="mv">' . (stripos($x['note'], 'after this month') !== false ? 'before move' : 'moved') . '</span></td>'; continue; }
              if ($c[0] === 'name') { echo '<td class="b nm" data-full="' . $h($x['name']) . '"><a href="#" onclick="lsShow(' . ($i - 1) . ');return false" title="' . $h($x['name']) . ' — click for full details">' . $h($x['initials']) . '</a></td>'; continue; }
              if ($c[3] === 0) { echo '<td' . ($c[0] === 'name' ? ' class="b"' : '') . '>' . $h($c[0] === 'doj' && $v ? date('Y-m-d', strtotime($v)) : $v) . '</td>'; continue; }
              $cls = 'n' . (is_numeric($v) && $v < 0 ? ' neg' : '') . ($c[0] === 'earn' ? ' tE' : '') . ($c[0] === 'ded' ? ' tD' : '') . (isset($x['from_sheet'][$c[0]]) || (in_array($c[0], ['add_earn', 'add_ded'], true) && $v != 0) ? ' sv' : '');
              $tt = $c[0] === 'add_earn' ? $x['add_e'] : ($c[0] === 'add_ded' ? $x['add_d'] : null);
              $ttl = $tt ? implode("\n", array_map(function ($r) use ($num) { return $r['label'] . ': ' . $num($r['amount']); }, $tt)) : (isset($x['from_sheet'][$c[0]]) ? 'From the saved salary sheet of the month' : '');
              echo '<td class="' . $cls . '"' . ($ttl !== '' ? ' title="' . $h($ttl) . '"' : '') . '>' . ($c[3] === 1 ? ($v + 0) : $num($v)) . '</td>';
              if ($c[0] === 'ded') echo '<td class="n net' . ($x['net'] < 0 ? ' neg' : '') . '">' . $num($x['net']) . '</td>';
          } ?>
        </tr>
      <?php endforeach;
      if ($last !== null): $s = $byCat[$last]; ?>
            <?= $totRow('Sub Total — ' . LS_CATS[$last] . ' (' . (int)$s['n'] . ')', $s, 'sub', $last) ?>
      <?php endif; ?>
      </tbody>
      <?php if ($rows): ?><tfoot><?= $totRow('GRAND TOTAL (' . count($rows) . ')', $tot, 'grand') ?></tfoot><?php endif; ?>
    </table>
  </div>
  <div class="note">Live figures for the chosen payroll month. From the salary sheet saved for the month: the values changed there (Arrears, Excess Payment, Credit Recovery, Cash / Good Short) and its Additional Earnings / Deductions rows (<span style="color:#7c3aed;font-weight:bold">purple</span>, hover for the rows); everything else is live. Employees as they were in that month: an employee who resigned in the month is in it (red "left"), a promotion takes the month's designation (tag on the designation). <b>Incentive</b> = the Incentives Sheet amounts of the employee's staff category (hover for the columns; before November 2026 from the old Incentive Entries). Norm days = days in the month − special holidays; days worked = attendance; No Pay = basic ÷ norm days × the employee's no-pay days. Attendance allowance from the Salary Configuration tiers (≥90% / ≥85%), else the employee profile. Reimbursements saved for the month in Incentive Entry, else the employee profile; SR fuel from SR Fuel Entry. Salary advance = approved advances of the month; loan = this month's instalment of approved loans. EPF / ETF from the EPF settings.</div>
</div>
<div class="mdl" id="lsM" onclick="if(event.target===this)lsHide()">
  <div class="mbox">
    <div class="tb"><h1 id="lsMT"></h1><button class="btn" onclick="lsHide()"><i class="fa-solid fa-xmark"></i> Close</button></div>
    <div class="mbody" id="lsMB"></div>
  </div>
</div>
<script>
<?php
  $jsCols = array_map(function ($c) { return ['k' => $c[0], 'l' => $c[1], 'g' => $c[2], 't' => $c[3]]; }, $COLS);
  $jsRows = array_map(function ($x) { $v = []; foreach ($GLOBALS['COLS'] as $c) $v[$c[0]] = $x[$c[0]] ?? null; return ['n' => $x['name'], 'i' => $x['initials'], 'd' => $x['det'], 'v' => $v, 'inc' => array_values($x['inc_list']), 'ae' => $x['add_e'], 'ad' => $x['add_d']]; }, $rows);
?>
var LS_COLS = <?= json_encode($jsCols, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>, LS_ROWS = <?= json_encode($jsRows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
function money(v){ v = parseFloat(v) || 0; return Math.abs(v) < 0.005 ? '-' : (v < 0 ? '(' + (-v).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ')' : v.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})); }
/* full details of an employee row */
function lsShow(i){
  var r = LS_ROWS[i]; if (!r) return;
  document.getElementById('lsMT').innerHTML = '<i class="fa-solid fa-id-card"></i> ' + esc(r.n) + (r.i && r.i !== r.n ? ' <span style="font-weight:normal;color:#55585e">(' + esc(r.i) + ')</span>' : '');
  var card = function(title, cls, lines){ return '<div class="mcard ' + cls + '"><h3>' + title + '</h3><table>' + lines.join('') + '</table></div>'; };
  var line = function(k, v, t){ return '<tr' + (t ? ' class="t"' : '') + '><td class="k">' + esc(k) + '</td><td' + (typeof v === 'number' ? ' class="n"' : '') + '>' + (typeof v === 'number' ? money(v) : esc(v)) + '</td></tr>'; };
  var det = Object.keys(r.d).map(function(k){ return line(k, r.d[k]); });
  var att = ['norm', 'worked', 'rate', 'no_pay_days'].map(function(k){ var c = LS_COLS.filter(function(x){ return x.k === k; })[0]; return line(c.l, (r.v[k] == null ? '' : r.v[k]) + (k === 'rate' ? '%' : '')); });
  att.push(line('Basic Salary', r.v.basic)); att.push(line('Basic Adjusted', r.v.basic_adj, 1));
  var grp = function(g, skip){ return LS_COLS.filter(function(c){ return c.g === g && c.t === 2; }).map(function(c){
      var sub = c.k === 'add_earn' ? r.ae : (c.k === 'add_ded' ? r.ad : null);
      if (sub && sub.length) return line(c.l, r.v[c.k]) + sub.map(function(x){ return '<tr><td class="k" style="padding-left:14px">· ' + esc(x.label) + '</td><td class="n">' + money(x.amount) + '</td></tr>'; }).join('');
      if (c.k === 'incentive' && r.inc && r.inc.length) return line(c.l, r.v[c.k]) + r.inc.map(function(x){ return '<tr><td class="k" style="padding-left:14px">· ' + esc(x.label) + '</td><td class="n">' + money(x.amount) + '</td></tr>'; }).join('');
      if (c.k === skip) return line(c.l, r.v[c.k], 1);
      return (parseFloat(r.v[c.k]) || 0) === 0 ? '' : line(c.l, r.v[c.k]); }); };
  document.getElementById('lsMB').innerHTML = card('Employee', '', det) + card('Attendance & Basic', '', att)
    + card('Additions', 'gA', grp('A', 'earn')) + card('Deductions', 'gD', grp('D', 'ded'))
    + '<div class="mnet"><span>Net Salary</span><span>' + money(r.v.net) + '</span></div>'
    + card('Employer Benefits', 'gB', grp('B', 'benefit'));
  document.getElementById('lsM').classList.add('on');
}
function lsHide(){ document.getElementById('lsM').classList.remove('on'); }
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') lsHide(); });
function lsApply(){
  var cats = [].filter.call(document.querySelectorAll('.lsCat'), function(c){ c.parentElement.classList.toggle('off', !c.checked); return c.checked; }).map(function(c){ return c.value; });
  var q = (document.getElementById('lsQ').value || '').trim().toLowerCase();
  document.querySelectorAll('#lsTbl tbody tr[data-cat]').forEach(function(tr){
    var on = cats.indexOf(tr.dataset.cat) >= 0;
    if (on && q && tr.classList.contains('r')) on = tr.dataset.s.indexOf(q) >= 0;
    if (on && q && !tr.classList.contains('r')) on = false;          /* group / sub total rows hidden while searching */
    tr.style.display = on ? '' : 'none';
  });
}
function lsExport(){
  var go = function(){
    var t = document.getElementById('lsTbl').cloneNode(true);
    t.querySelectorAll('tr').forEach(function(tr){ if (tr.style.display === 'none') tr.remove(); });
    t.querySelectorAll('td.nm').forEach(function(td){ td.textContent = td.dataset.full || td.textContent; });   /* full name in the Excel */
    var wb = XLSX.utils.table_to_book(t, {sheet: 'Live Salary', raw: false});
    XLSX.writeFile(wb, 'Live_Salary_<?= $h(preg_replace('/\W+/', '_', $plabel)) ?>.xlsx');
  };
  if (window.XLSX) return go();
  var s = document.createElement('script'); s.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js'; s.onload = go; s.onerror = function(){ alert('Could not load the Excel library (internet needed).'); }; document.head.appendChild(s);
}
</script>
</body></html>
