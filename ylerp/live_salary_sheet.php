<?php
/**
 * live_salary_sheet.php — Live Salary Sheet: every employee's salary for a payroll month in one full-screen table,
 * with the columns of salary_excel_generate.php, worked out from LIVE data (not the saved salary sheets):
 *   attendance + special holidays (norm days), employee profile (basic, no pay days, welfare, donations, retention …),
 *   approved salary advances of the month, approved loans (monthly instalment), EPF / ETF settings,
 *   reimbursements saved for the month in Incentive Entry (else the profile), SR fuel entries.
 * Incentives are left EMPTY (not counted).
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

const LS_CATS = ['SR' => 'Sales Representatives', 'MR' => 'Merchandisers', 'CC' => 'Cash Collectors', 'ACC' => 'Assistant Cash Collectors', 'ST' => 'Store Staff', 'OFF' => 'Office Staff'];
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
        if ($cat === '') continue;
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

    /* reimbursements saved for the month in Incentive Entry (incentives themselves are NOT used) */
    $reimb = [];
    if (ls_has_table($conn, 'incentive_entries')) {
        $q = ls_q($conn, "SELECT employee_id, component_label, amount FROM incentive_entries WHERE payroll_period_id = $pid AND employee_id IN ($ids) AND component_label LIKE '\\_\\_reimb\\_\\_%'");
        while ($q && ($x = mysqli_fetch_assoc($q))) $reimb[(int)$x['employee_id']][$x['component_label']] = (float)$x['amount'];
    }
    $rmb = function ($eid, $pat, $profile) use ($reimb) {
        foreach ($reimb[$eid] ?? [] as $lbl => $v) if (strpos($lbl, $pat) !== false) return $v;
        return (float)$profile;
    };
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
        $arrears = $f($e, 'arrears_salary');
        $earn = round($basic + $att_allow + $meal + $travel + $mobile + $fuelA + $arrears, 2);

        $epf8 = round($basic * $epfE / 100, 2);
        $d = ['epf8' => $epf8, 'welfare' => $f($e, 'welfare_amount'), 'w_loan' => $f($e, 'welfare_loan_deduction'), 'w_soc' => $f($e, 'welfare_society'),
              'donations' => $f($e, 'donations'), 'sal_adv' => $adv[$eid] ?? $f($e, 'salary_advance'), 'loan_ded' => ($loan[$eid] ?? 0) > 0 ? round($loan[$eid], 2) : $f($e, 'loan_deduction'),
              'credit_r' => $f($e, 'credit_recovery'), 'cash_short' => in_array($cat, ['CC', 'ACC'], true) ? $f($e, 'cash_short') : 0.0,
              'good_short' => in_array($cat, ['CC', 'ACC'], true) ? $f($e, 'good_short') : 0.0, 'no_pay' => $no_pay, 'retention' => $f($e, 'retention'),
              'excess_p' => $f($e, 'excess_payment'), 'payee' => $f($e, 'payee') ?: $f($e, 'income_tax')];
        $ded = round(array_sum($d), 2);
        $epfR_ = round($basic * $epfR / 100, 2); $etf = round($basic * $etfR / 100, 2); $bonus = $basic > 0 ? round($basic / 12, 2) : 0; $grat = $basic > 0 ? round($basic / 24, 2) : 0; $ins = $f($e, 'insurance_amount');
        $rows[] = ['cat' => $cat, 'code' => $e['employee_id'] ?? '', 'epf_no' => $e['epf_number'] ?? ($e['epf_no'] ?? ''), 'name' => $e['employee_full_name'] ?? ($e['name_with_initials'] ?? ''),
            'desig' => $e['designation_name'] ?? '', 'status' => $e['status'] ?? '', 'note' => $e['pme_note'] ?? '', 'left' => $e['pme_exit'] ?? null, 'doj' => $e['date_of_join'] ?? '', 'norm' => $norm, 'worked' => $worked, 'rate' => $rate,
            'basic' => $basic, 'no_pay_days' => $no_pay_days, 'basic_adj' => round($basic - $no_pay, 2),
            'incentive' => null, 'att_allow' => $att_allow, 'meal' => $meal, 'travel' => $travel, 'mobile' => $mobile, 'fuel' => $fuelA, 'arrears' => $arrears, 'earn' => $earn]
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
    ['incentive', 'Incentive', 'A', 2], ['att_allow', 'Attendance Allowance', 'A', 2], ['meal', 'Meal Reimb.', 'A', 2], ['travel', 'Travelling Reimb.', 'A', 2], ['mobile', 'Mobile Reimb.', 'A', 2], ['fuel', 'Fuel Reimb.', 'A', 2], ['arrears', 'Arrears', 'A', 2], ['earn', 'Total Earnings', 'A', 2],
    ['epf8', 'EPF 8%', 'D', 2], ['welfare', 'Welfare', 'D', 2], ['w_loan', 'Welfare Loan', 'D', 2], ['w_soc', 'Welfare Society', 'D', 2], ['donations', 'Donations', 'D', 2], ['sal_adv', 'Salary Advance', 'D', 2], ['loan_ded', 'Loan Deduction', 'D', 2],
    ['credit_r', 'Credit Recovery', 'D', 2], ['cash_short', 'Cash Short', 'D', 2], ['good_short', 'Good Short', 'D', 2], ['no_pay', 'No Pay', 'D', 2], ['retention', 'Retention', 'D', 2], ['excess_p', 'Excess Payment', 'D', 2], ['payee', 'PAYEE', 'D', 2], ['ded', 'Total Deductions', 'D', 2],
    ['net', 'Net Salary', 'S', 2], ['epf12', 'EPF 12%', 'B', 2], ['etf3', 'ETF 3%', 'B', 2], ['bonus', 'Bonus Prov.', 'B', 2], ['gratuity', 'Gratuity Prov.', 'B', 2], ['insurance', 'Insurance', 'B', 2], ['benefit', 'Total Benefit', 'B', 2],
];
$SUMK = array_values(array_map(function ($c) { return $c[0]; }, array_filter($COLS, function ($c) { return $c[3] === 2 && $c[0] !== 'incentive'; })));
$num = function ($v, $dp = 2) { if ($v === null) return ''; return abs($v) < 0.005 ? '-' : ($v < 0 ? '(' . number_format(-$v, $dp) . ')' : number_format($v, $dp)); };
$tot = array_fill_keys($SUMK, 0.0); $byCat = [];
foreach ($rows as $x) { foreach ($SUMK as $k) { $tot[$k] += $x[$k]; $byCat[$x['cat']][$k] = ($byCat[$x['cat']][$k] ?? 0) + $x[$k]; } $byCat[$x['cat']]['n'] = ($byCat[$x['cat']]['n'] ?? 0) + 1; }
$totRow = function ($label, $sums, $cls, $cat = '') use ($COLS, $num, $h) {
    $o = '<tr class="' . $cls . '"' . ($cat !== '' ? ' data-cat="' . $cat . '"' : '') . '><td class="sticky"></td><td colspan="9">' . $h($label) . '</td>';
    foreach (array_slice($COLS, 9) as $c) {
        if ($c[0] === 'net') continue;
        $o .= '<td class="n">' . ($c[3] === 2 && $c[0] !== 'incentive' ? $num($sums[$c[0]] ?? 0) : '') . '</td>';
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
table{border-collapse:collapse;font-variant-numeric:tabular-nums;font-size:10.5px;min-width:100%}
th,td{border:1px solid var(--grid);padding:2px 5px;white-space:nowrap;height:18px}
thead th{position:sticky;z-index:2;background:linear-gradient(#3a64a0,var(--ob-navy));color:#fff;border-color:#173a63;font-weight:bold;text-align:center}
thead tr:first-child th{top:0} thead tr:nth-child(2) th{top:20px}
th.gA{background:linear-gradient(#4fa36a,#2e7d46)} th.gD{background:linear-gradient(#df8b89,#c0504d 55%,#a33f3c)} th.gS{background:linear-gradient(#ffd966,#f0b400);color:#3a2c00} th.gB{background:linear-gradient(#9b7fd0,#6b4fa8)}
td.n{text-align:right} td.neg{color:var(--neg)} td.emp{color:#b9bac2;text-align:center;background:#fafafa}
td.sticky,th.sticky{position:sticky;left:0;z-index:1;background:#fff} thead th.sticky{z-index:3}
td.b{font-weight:bold} td.lft{color:var(--neg);font-weight:bold} .mv{display:inline-block;padding:0 4px;border-radius:6px;background:#e9f0f8;border:1px solid var(--ob-line);color:var(--ob-navy);font-size:9.5px} td.net{font-weight:bold;background:#fff8d6} td.tE{background:#e9f7ec;font-weight:bold} td.tD{background:#fdecea;font-weight:bold}
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
        <tr><th rowspan="2" class="sticky">#</th><th colspan="12">Employee &amp; Attendance</th><th colspan="8" class="gA">Additions</th><th colspan="15" class="gD">Deductions</th><th rowspan="2" class="gS">Net Salary</th><th colspan="6" class="gB">Employer Benefits</th></tr>
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
        <tr class="r" data-cat="<?= $x['cat'] ?>" data-s="<?= $h(mb_strtolower($x['name'] . ' ' . $x['code'] . ' ' . $x['desig'] . ' ' . $x['epf_no'])) ?>">
          <td class="sticky" style="color:#8e909a"><?= $i ?></td>
          <?php foreach ($COLS as $c) {
              if ($c[0] === 'net') continue;
              $v = $x[$c[0]];
              if ($c[0] === 'incentive') { echo '<td class="emp" title="Incentives are not included in the live sheet"></td>'; continue; }
              if ($c[0] === 'status' && $x['left']) { echo '<td class="lft" title="' . $h($x['note']) . '">' . $h($v) . ' · left ' . $h(date('d M', strtotime($x['left']))) . '</td>'; continue; }
              if ($c[0] === 'desig' && $x['note'] !== '' && stripos($x['note'], 'moted') !== false) { echo '<td title="' . $h($x['note']) . '">' . $h($v) . ' <span class="mv">' . (stripos($x['note'], 'after this month') !== false ? 'before move' : 'moved') . '</span></td>'; continue; }
              if ($c[3] === 0) { echo '<td' . ($c[0] === 'name' ? ' class="b"' : '') . '>' . $h($c[0] === 'doj' && $v ? date('Y-m-d', strtotime($v)) : $v) . '</td>'; continue; }
              $cls = 'n' . (is_numeric($v) && $v < 0 ? ' neg' : '') . ($c[0] === 'earn' ? ' tE' : '') . ($c[0] === 'ded' ? ' tD' : '');
              echo '<td class="' . $cls . '">' . ($c[3] === 1 ? ($v + 0) : $num($v)) . '</td>';
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
  <div class="note">Live figures for the chosen payroll month — not the saved salary sheets. Employees as they were in that month: an employee who resigned in the month is in it (red "left"), a promotion takes the month's designation (tag on the designation). <b>Incentives are left empty.</b> Norm days = days in the month − special holidays; days worked = attendance; No Pay = basic ÷ norm days × the employee's no-pay days. Attendance allowance from the Salary Configuration tiers (≥90% / ≥85%), else the employee profile. Reimbursements saved for the month in Incentive Entry, else the employee profile; SR fuel from SR Fuel Entry. Salary advance = approved advances of the month; loan = this month's instalment of approved loans. EPF / ETF from the EPF settings.</div>
</div>
<script>
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
    var wb = XLSX.utils.table_to_book(t, {sheet: 'Live Salary', raw: false});
    XLSX.writeFile(wb, 'Live_Salary_<?= $h(preg_replace('/\W+/', '_', $plabel)) ?>.xlsx');
  };
  if (window.XLSX) return go();
  var s = document.createElement('script'); s.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js'; s.onload = go; s.onerror = function(){ alert('Could not load the Excel library (internet needed).'); }; document.head.appendChild(s);
}
</script>
</body></html>
