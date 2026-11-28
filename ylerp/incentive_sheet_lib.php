<?php
/**
 * incentive_sheet_lib.php — the Incentives Sheet (cc_acc_incentives.php) definitions and its table, shared with the Live Salary Sheet.
 *
 *   incentive_sheet_entries : one row per payroll month / employee / column (comp_key of the sheet):
 *       section (SR / MR / CC / ACC / ST / OFF), pct (the % used for the tier), actual, gm, amount (earned or entered amount),
 *       amount_manual (1 = an amount typed in, not worked out from a %), source ('sheet' = saved on the Incentives Sheet,
 *       'live' = a reimbursement worked out by the sheet, 'old' = copied from incentive_entries), old_ids.
 *   Every month is filled from the old incentive_entries table (rows copied in on load, never over an entry saved on the sheet);
 *   an amount without its % gets the % of the tier that pays that amount, so the boxes are filled.
 *   Editing saves here: before ISL_CUTOVER (November 2026) only here; from ISL_CUTOVER also to the old rows the salary sheets read.
 */
include_once __DIR__ . '/payroll_month_employees_lib.php';
if (!defined('ISL_CUTOVER')) define('ISL_CUTOVER', '2026-11');

if (!function_exists('ci_q')) {
function ci_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function ci_has($conn, $t) { $r = ci_q($conn, "SHOW TABLES LIKE '$t'"); return $r && mysqli_num_rows($r) > 0; }

/* sections: code → [title, staff category codes, components, meal column ('rate' = config meal × attendance rate | 'flat' = fixed meal | 'none'), save amounts?]
   component: [key, label, tiers [threshold => cfg column], mode, options]
     mode  'gm'  Actual + GM % inputs        'act' Actual % input        'att' live attendance %
           'dmg' damage % (LOWER is better)  'amt' an amount input        'live' a live amount (read only)
     options  iach  label of the % row (default __iach__<key>)        amt   label of the amount row (default __incent__<key>)
              thr   sr_incentive_rates key: the designation's own threshold for a single-tier incentive
              fb    'amt' mode: the employee field used when the cell is empty (what the salary sheet pays then)
              src   'live' mode: fuel | dlink | reimb;  field / calc / save for reimbursements (calc: att | att22 | flat | profile)
   save amounts: the SR / ST / OFF salary sheets read the earned amount; MR / CC / ACC resolve the tier from the % themselves */
function isl_sections() {
    static $S = null; if ($S !== null) return $S;
    $SSV_SR = []; foreach ([90, 95, 96, 97, 98, 99, 100, 103] as $t) $SSV_SR[$t] = 'ssvr_' . $t;
    return $S = [
    'SR' => ['Sales Team', ['SR'], [
        ['disc', 'Discretionary Support', [], 'amt', ['amt' => '__disc_amount', 'fb' => 'discretionary_support', 'cfg' => 'discretionary_support']],
        ['ssv_sr', 'Secondary Sales Value', $SSV_SR, 'act', ['iach' => '__sec_achievement', 'amt' => '__ssv_amount']],
        ['total_ps_compliance', 'PS Compliance', [80 => 'total_ps_compliance'], 'act', ['thr' => 'total_ps_compliance']],
        ['eco_incentive', 'ECO', [95 => 'eco_incentive'], 'act', ['thr' => 'eco_incentive']],
        ['bp_incentive', 'BP', [90 => 'bp_incentive'], 'act', ['thr' => 'bp_incentive']],
        ['total_assortment', 'Total Assortment', [100 => 'total_assortment'], 'act', ['thr' => 'total_assortment']],
        ['ete', 'ETE Incentive', [], 'amt', ['amt' => '__other_incentive']],
        ['meal_sr', 'Meal', [], 'live', ['src' => 'reimb', 'field' => 'meal_reimbursement', 'pf' => 'reimbursement_meal', 'calc' => 'att', 'save' => '__reimb__meal_reimbursement']],
        ['travel_sr', 'Travelling', [], 'live', ['src' => 'reimb', 'field' => 'traveling_reimbursement', 'pf' => 'reimbursement_traveling', 'calc' => 'att22', 'save' => '__reimb__traveling_reimbursement']],
        ['fuel_sr', 'Fuel', [], 'live', ['src' => 'fuel']],
        ['mobile_sr', 'Mobile / Data', [], 'live', ['src' => 'reimb', 'field' => 'mobile_reimbursement', 'pf' => 'reimbursement_mobile', 'calc' => 'flat', 'save' => '__reimb__mobile_reimbursement']],
    ], 'none', true],
    'MR' => ['Merchandising Team', ['MR'], [
        ['placement_compliance', 'Placement Compliance', [70 => 'placement_compliance_70', 80 => 'placement_compliance_80', 90 => 'placement_compliance_90'], 'act'],
        ['attendance_mr', 'Attendance', [70 => 'attendance_70', 80 => 'attendance_80', 90 => 'attendance_90'], 'att', ['amt' => '__incent__attendance']],
        ['meal_mr', 'Meal', [], 'live', ['src' => 'reimb', 'field' => 'meal_reimbursement', 'pf' => 'reimbursement_meal', 'calc' => 'profile']],
        ['travel_mr', 'Travelling', [], 'live', ['src' => 'reimb', 'field' => 'traveling_reimbursement', 'pf' => 'reimbursement_traveling', 'calc' => 'profile']],
        ['dlink', 'D-Link', [], 'live', ['src' => 'dlink']],
    ], 'none', false],
    'CC' => ['Cash Collectors', ['CC', 'SACC'], [
        ['ccfot', 'CCFOT', [90 => 'ccfot_90', 91 => 'ccfot_91', 92 => 'ccfot_92', 93 => 'ccfot_93', 94 => 'ccfot_94', 95 => 'ccfot_95'], 'gm'],
        ['credit_mgt', 'Credit Mgt', [85 => 'credit_mgt_85', 90 => 'credit_mgt_90', 95 => 'credit_mgt_95'], 'gm'],
        ['payee_chq', 'Payee Chq', [80 => 'payee_chq_80'], 'gm'],
        ['ssv_cc', 'Secondary Sales Value', [90 => 'ssv_90', 95 => 'ssv_95'], 'act'],
        ['attendance_cc', 'Attendance', [85 => 'attendance_85', 90 => 'attendance_90'], 'att'],
        ['locus_admin', 'Locus Admin', [100 => 'locus_admin'], 'act'],
    ], 'rate', false],
    'ACC' => ['Assistant Cash Collectors', ['ACC'], [
        ['ssv_acc', 'Secondary Sales Value', [90 => 'ssv_90', 95 => 'ssv_95'], 'act'],
        ['ccfot_acc', 'CCFOT', [90 => 'ccfot_90', 91 => 'ccfot_91', 92 => 'ccfot_92', 93 => 'ccfot_93', 94 => 'ccfot_94', 95 => 'ccfot_95'], 'gm'],
        ['punctuality_acc', 'Punctuality', [70 => 'punctuality_70', 80 => 'punctuality_80'], 'act'],
        ['attendance_acc', 'Attendance', [85 => 'attendance_85', 90 => 'attendance_90'], 'att'],
    ], 'rate', false],
    'ST' => ['Back Office Stores Team', ['ST'], [
        ['stores_damage', 'Stores Damage', ['0.007' => 'stores_damage_lo', '0.020' => 'stores_damage_hi'], 'dmg'],
        ['rsqm', 'RSQM Score', [60 => 'rsqm_60', 70 => 'rsqm_70'], 'act'],
        ['punctuality', 'Punctuality', [75 => 'punctuality_75', 85 => 'punctuality_85'], 'act'],
        ['loading_unloading', 'L/Unloading', [90 => 'loading_unloading'], 'act'],
        ['ccfot_st', 'CCFOT', [90 => 'ccfot_st'], 'act'],
        ['attendance_st', 'Attendance', [85 => 'attendance_85', 90 => 'attendance_90'], 'att'],
    ], 'rate', true],
    'OFF' => ['Back Office Team', ['OFF'], [
        ['incentive_daily_90', 'Daily', [90 => 'incentive_daily_90'], 'act'],
        ['incentive_weekly_90', 'Weekly', [90 => 'incentive_weekly_90'], 'act'],
        ['incentive_monthly_90', 'Monthly', [90 => 'incentive_monthly_90'], 'act'],
        ['punctuality_75', 'Punctuality', [75 => 'punctuality_75'], 'act'],
        ['attendance_off', 'Attendance', [85 => 'attendance_85', 90 => 'attendance_90'], 'att'],
    ], 'flat', true],
];
}
function ci_opt($c, $k, $d = null) { return $c[4][$k] ?? $d; }
function ci_lbl_pct($c) { return ci_opt($c, 'iach', '__iach__' . $c[0]); }
function ci_lbl_amt($c) { return ci_opt($c, 'amt', '__incent__' . $c[0]); }
function ci_is_pct($c) { return in_array($c[3], ['gm', 'act', 'att', 'dmg'], true); }
/* the tiers of a component for one designation (its own threshold from sr_incentive_rates for SR single incentives) */
function ci_tiers($c, $cfg) {
    $k = ci_opt($c, 'thr');
    if ($k && count($c[2]) === 1 && isset($cfg['_thr'][$k])) return [(string)$cfg['_thr'][$k] => reset($c[2])];
    return $c[2];
}
/* the amount a % earns: highest tier reached (rate > 0); damage: the lowest limit not exceeded */
function ci_amount($mode, $tiers, $cfg, $p) {
    if ($p === null) return 0.0;
    $hit = 0.0;
    if ($mode === 'dmg') { $lim = $tiers; uksort($lim, function ($a, $b) { return (float)$b <=> (float)$a; }); foreach ($lim as $t => $f) if ($p <= (float)$t + 1e-9 && (float)($cfg[$f] ?? 0) > 0) $hit = (float)$cfg[$f]; return $hit; }
    foreach ($tiers as $t => $f) if ($p >= (float)$t - 1e-9 && (float)($cfg[$f] ?? 0) > 0) $hit = (float)$cfg[$f];
    return $hit;
}

/* configuration by designation: sr_salary_config + SR secondary sales tiers (ssvr_<pct>) + SR incentive thresholds (_thr) */
function ci_cfgs($conn) {
    static $c = null; if ($c !== null) return $c; $c = [];
    $q = ci_q($conn, "SELECT * FROM sr_salary_config"); while ($q && ($x = mysqli_fetch_assoc($q))) $c[(int)$x['designation_id']] = $x;
    $q = ci_q($conn, "SELECT designation_id, rate_label, amount FROM sr_secondary_sales_rates");
    while ($q && ($x = mysqli_fetch_assoc($q))) { $t = (float)str_replace('%', '', (string)$x['rate_label']); if ($t > 0) $c[(int)$x['designation_id']]['ssvr_' . rtrim(rtrim(number_format($t, 2, '.', ''), '0'), '.')] = $x['amount']; }
    $q = ci_q($conn, "SELECT designation_id, incent_key, rate_value FROM sr_incentive_rates");
    while ($q && ($x = mysqli_fetch_assoc($q))) { $t = (float)str_replace('%', '', (string)$x['rate_value']); if ($t > 0) $c[(int)$x['designation_id']]['_thr'][$x['incent_key']] = $t; }
    return $c;
}

/* ── employees of a section in the payroll month, as they were then (payroll_month_employees_lib.php):
      leavers of the month stay in it, promotions after the month are undone, same category rule as the salary sheets ── */
include_once __DIR__ . '/payroll_month_employees_lib.php';
function ci_employees($conn, $codes, $period) {
    static $all = [];
    $from = sprintf('%04d-%02d-01', (int)$period['year'], (int)$period['month']); $to = date('Y-m-t', strtotime($from));
    if (!isset($all[$from])) $all[$from] = pme_employees($conn, $from, $to);
    return pme_filter_categories($conn, $all[$from], $codes);
}
/* incentive type to file new entries under (the one allowed for the category), else 0 */
function ci_type_id($conn, $codes) {
    if (!ci_has($conn, 'incentive_types')) return 0;
    $in = implode(',', array_map(function ($c) { return "'" . $c . "'"; }, $codes));
    $r = ci_q($conn, "SELECT id FROM staff_categories WHERE category_code IN ($in)");
    while ($r && ($x = mysqli_fetch_row($r))) {
        $q = ci_q($conn, "SELECT id FROM incentive_types WHERE FIND_IN_SET(" . (int)$x[0] . ", REPLACE(COALESCE(allowed_category_ids,''),' ','')) ORDER BY id LIMIT 1");
        if ($q && ($t = mysqli_fetch_row($q))) return (int)$t[0];
    }
    return 0;
}

/* ── live data of the month: configuration, attendance, holidays, leave, saved entries, SR fuel, MR D-Link ── */
function ci_ctx($conn, $period, $pid) {
    $from = sprintf('%04d-%02d-01', (int)$period['year'], (int)$period['month']); $to = date('Y-m-t', strtotime($from));
    $x_ = ['from' => $from, 'to' => $to, 'mdays' => (int)date('t', strtotime($from)), 'cfgs' => [], 'shd' => [], 'att' => [], 'leave' => [], 'inc' => [], 'fuel' => [], 'dlink' => []];
    $x_['cfgs'] = ci_cfgs($conn);
    $q = ci_q($conn, "SELECT * FROM special_holidays WHERE active = 1 AND date_from <= '$to' AND date_to >= '$from'");
    while ($q && ($sh = mysqli_fetch_assoc($q))) for ($d = max($sh['date_from'], $from); $d <= min($sh['date_to'], $to); $d = date('Y-m-d', strtotime($d . ' +1 day'))) $x_['shd'][$d][] = $sh;
    $q = ci_q($conn, "SELECT employee_id, COUNT(*) FROM attendance WHERE att_date BETWEEN '$from' AND '$to' GROUP BY employee_id"); while ($q && ($x = mysqli_fetch_row($q))) $x_['att'][(int)$x[0]] = (int)$x[1];
    $q = ci_q($conn, "SELECT employee_id, SUM(days_count) FROM leave_applications WHERE status = 'Approved' AND (start_date BETWEEN '$from' AND '$to' OR end_date BETWEEN '$from' AND '$to' OR (start_date <= '$from' AND end_date >= '$to')) GROUP BY employee_id");
    while ($q && ($x = mysqli_fetch_row($q))) $x_['leave'][(int)$x[0]] = (float)$x[1];
    $x_['inc'] = isl_labels($conn, $pid);                                                                  /* saved entries: incentive_sheet_entries */
    $q = ci_q($conn, "SELECT employee_id, SUM(fuel_rate * fuel_liter) FROM sr_fuel_entries WHERE payroll_period_id = $pid GROUP BY employee_id"); while ($q && ($x = mysqli_fetch_row($q))) $x_['fuel'][(int)$x[0]] = (float)$x[1];
    $q = ci_q($conn, "SELECT id, actual_per_day_rate FROM dlink_budget_entries WHERE year = " . (int)$period['year'] . " AND month = " . (int)$period['month'] . " LIMIT 1");
    if ($q && ($dl = mysqli_fetch_assoc($q))) {
        $q = ci_q($conn, "SELECT employee_db_id, attended_days FROM dlink_employee_days WHERE entry_id = " . (int)$dl['id'] . " AND employee_db_id > 0");
        while ($q && ($x = mysqli_fetch_assoc($q))) $x_['dlink'][(int)$x['employee_db_id']] = round((float)$x['attended_days'] * (float)$dl['actual_per_day_rate'], 2);
    }
    return $x_;
}
/* days / attendance rate / no pay of one employee */
function ci_emp_calc($ctx, $e) {
    $eid = (int)$e['id']; $did = (int)($e['designation_id'] ?? 0); $hol = 0;
    foreach ($ctx['shd'] as $list) foreach ($list as $sh) {
        $ok = ($sh['target_type'] ?? '') === 'all'; $ids = json_decode((string)($sh['target_ids'] ?? ''), true) ?: [];
        if (!$ok && $sh['target_type'] === 'employee') $ok = in_array($eid, $ids);
        if (!$ok && $sh['target_type'] === 'staff_category') $ok = in_array((int)($e['staff_category_id'] ?? 0), $ids);
        if (!$ok && $sh['target_type'] === 'designation') $ok = in_array($did, $ids);
        if ($ok) { $hol++; break; }
    }
    $norm = max(1, $ctx['mdays'] - $hol); $w = $ctx['att'][$eid] ?? 0;
    return ['norm' => $norm, 'w' => $w, 'rate' => round($w / $norm * 100, 2), 'npd' => max(0, $norm - $w - ($ctx['leave'][$eid] ?? 0))];
}
/* an incentive_entries row written by this page (recalculated, not someone's entry) */
function ci_mine($row) { $n = json_decode((string)($row['notes'] ?? ''), true); return is_array($n) && ($n['src'] ?? '') === 'cc_acc_incentives'; }
/* a live amount: [value, saved in Incentive Entry?] */
function ci_live($ctx, $c, $e, $cfg, $calc) {
    $eid = (int)$e['id']; $src = ci_opt($c, 'src');
    if ($src === 'fuel') return [$ctx['fuel'][$eid] ?? (float)($e['reimbursement_fuel'] ?? 0), false];
    if ($src === 'dlink') return [$ctx['dlink'][$eid] ?? 0.0, false];
    $base = (float)(($cfg[ci_opt($c, 'field')] ?? null) ?: ($e[ci_opt($c, 'pf')] ?? 0));
    $lbl = '__reimb__' . ci_opt($c, 'field'); $saved = $ctx['inc'][$eid][$lbl] ?? null;
    $how = ci_opt($c, 'calc');
    if ($saved && ci_mine($saved)) $saved = null;          /* saved by this page: worked out again from live attendance */
    if ($how === 'profile') return [$saved && (float)$saved['amount'] > 0 ? (float)$saved['amount'] : (float)($e[ci_opt($c, 'pf')] ?? 0), $saved && (float)$saved['amount'] > 0];   /* MR sheet: saved, else profile */
    if ($saved) return [(float)$saved['amount'], true];
    $share = min(100, $calc['rate']) / 100;
    if ($how === 'flat') return [$base, false];
    if ($how === 'att22') return [$calc['w'] > 22 ? $base : round($base * $share, 2), false];
    return [round($base * $share, 2), false];
}

/* ════════ incentive_sheet_entries ════════ */
function isl_ensure($conn) {
    static $done = false; if ($done) return; $done = true;
    ci_q($conn, "CREATE TABLE IF NOT EXISTS incentive_sheet_entries (
        id INT AUTO_INCREMENT PRIMARY KEY, payroll_period_id INT NOT NULL, employee_id INT NOT NULL, section VARCHAR(10) NOT NULL, comp_key VARCHAR(100) NOT NULL,
        pct DECIMAL(12,5) NULL, actual DECIMAL(12,5) NULL, gm DECIMAL(12,5) NULL, amount DECIMAL(12,2) NULL, amount_manual TINYINT(1) NOT NULL DEFAULT 0,
        source VARCHAR(10) NOT NULL DEFAULT 'sheet', old_ids VARCHAR(255) NULL, updated_by VARCHAR(100) NULL, updated_at DATETIME NULL,
        UNIQUE KEY uq_period_emp_comp (payroll_period_id, employee_id, comp_key), INDEX idx_period_section (payroll_period_id, section)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function isl_period_ym($period) { return sprintf('%04d-%02d', (int)$period['year'], (int)$period['month']); }
function isl_is_old($period) { return isl_period_ym($period) < ISL_CUTOVER; }          /* before the cutover: edits are kept in the new table only */
/* old label → [comp_key, kind] of a section */
function isl_label_map($sk) {
    $sec = isl_sections()[$sk]; $m = [];
    foreach ($sec[2] as $c) {
        if (in_array($c[3], ['gm', 'act', 'dmg'], true)) { $m[ci_lbl_pct($c)] = [$c[0], 'pct']; $m[ci_lbl_amt($c)] = [$c[0], 'amt']; }
        elseif ($c[3] === 'att') { if (ci_opt($c, 'amt')) $m[ci_lbl_amt($c)] = [$c[0], 'amt']; else $m['__incent__' . $c[0]] = [$c[0], 'amt']; }
        elseif ($c[3] === 'amt') $m[ci_lbl_amt($c)] = [$c[0], 'amt'];
        elseif ($c[3] === 'live' && ci_opt($c, 'field')) $m['__reimb__' . ci_opt($c, 'field')] = [$c[0], 'reimb'];
    }
    if ($sec[3] !== 'none') $m['__reimb__meal_reimbursement'] = ['_meal', 'reimb'];
    return $m;
}
function isl_comp($sk, $key) { foreach (isl_sections()[$sk][2] ?? [] as $c) if ($c[0] === $key) return $c; return null; }
/* the section of each employee in the month (staff category as it was then) */
function isl_emp_sections($conn, $period) {
    $o = [];
    foreach (isl_sections() as $sk => $sec) foreach (ci_employees($conn, $sec[1], $period) as $eid => $e) if (!isset($o[$eid])) $o[$eid] = [$sk, $e];
    return $o;
}
/* incentive_entries of a month → rows of incentive_sheet_entries ([eid|key] => row) */
function isl_from_old($conn, $period) {
    $pid = (int)$period['id']; $es = isl_emp_sections($conn, $period); $cfgs = ci_cfgs($conn); $maps = []; $rows = [];
    foreach (array_keys(isl_sections()) as $sk) $maps[$sk] = isl_label_map($sk);
    $q = ci_q($conn, "SELECT id, employee_id, component_label, amount, notes FROM incentive_entries WHERE payroll_period_id = $pid ORDER BY id");
    while ($q && ($x = mysqli_fetch_assoc($q))) {
        $eid = (int)$x['employee_id']; $lbl = (string)$x['component_label'];
        $sk = $es[$eid][0] ?? ''; $hit = $sk !== '' ? ($maps[$sk][$lbl] ?? null) : null;
        if (!$hit) foreach ($maps as $k => $m) if (isset($m[$lbl])) { if ($sk === '') $sk = $k; $hit = $m[$lbl]; break; }   /* not in a section this month */
        if ($sk === '') $sk = 'OTH';
        [$key, $kind] = $hit ?: [$lbl, 'raw'];
        $k = $eid . '|' . $key;
        $r = $rows[$k] ?? ['employee_id' => $eid, 'section' => $sk, 'comp_key' => $key, 'pct' => null, 'actual' => null, 'gm' => null, 'amount' => null, 'amount_manual' => 0, 'source' => 'old', 'old_ids' => []];
        $r['old_ids'][] = (int)$x['id']; $nt = json_decode((string)$x['notes'], true) ?: [];
        if ($kind === 'pct') { $r['pct'] = (float)$x['amount']; $r['actual'] = isset($nt['act']) ? $nt['act'] : (float)$x['amount']; $r['gm'] = $nt['gm'] ?? null; }
        elseif ($kind === 'reimb') { $r['amount'] = (float)$x['amount']; $r['amount_manual'] = ($nt['src'] ?? '') === 'cc_acc_incentives' ? 0 : 1; if (!$r['amount_manual']) $r['source'] = 'live'; }
        else { $r['amount'] = (float)$x['amount']; $r['amount_manual'] = 1; }
        $rows[$k] = $r;
    }
    /* fill the boxes: the amount a % earns (only the % was there), or the % of the tier that pays the amount (only the amount was there) */
    foreach ($rows as $k => &$r) {
        $c = isl_comp($r['section'], $r['comp_key']); if (!$c || !in_array($c[3], ['gm', 'act', 'dmg'], true)) continue;
        $e = $es[$r['employee_id']][1] ?? null; $did = (int)($e['designation_id'] ?? 0);
        if (!$e) { $qq = ci_q($conn, "SELECT designation_id FROM employees WHERE id = " . (int)$r['employee_id']); $did = $qq && ($y = mysqli_fetch_row($qq)) ? (int)$y[0] : 0; }
        $cfg = $cfgs[$did] ?? []; $tiers = ci_tiers($c, $cfg);
        if ($r['pct'] !== null && $r['amount'] === null) { $r['amount'] = ci_amount($c[3], $tiers, $cfg, (float)$r['pct']); continue; }
        if ($r['pct'] === null && $r['amount'] !== null && (float)$r['amount'] > 0) {
            foreach ($tiers as $t => $f) if (abs((float)($cfg[$f] ?? 0) - (float)$r['amount']) < 0.01) { $r['pct'] = (float)$t; $r['actual'] = (float)$t; break; }
            if ($r['pct'] !== null && abs(ci_amount($c[3], $tiers, $cfg, $r['pct']) - (float)$r['amount']) < 0.01) $r['amount_manual'] = 0;   /* the tier pays exactly that */
        }
    }
    unset($r);
    return $rows;
}
/* bring the old table into incentive_sheet_entries for a month:
   before ISL_CUTOVER the old table is the source (the month is rebuilt); from ISL_CUTOVER old rows are added / refreshed,
   never over an entry saved (or cleared) on the sheet */
function isl_sync($conn, $period) {
    isl_ensure($conn); $pid = (int)$period['id'];
    $rows = isl_from_old($conn, $period);
    $cur = []; $q = ci_q($conn, "SELECT id, employee_id, comp_key, source FROM incentive_sheet_entries WHERE payroll_period_id = $pid");
    while ($q && ($x = mysqli_fetch_assoc($q))) $cur[(int)$x['employee_id'] . '|' . $x['comp_key']] = $x;
    $e = function ($v) use ($conn) { return $v === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, (string)$v) . "'"; };
    foreach ($rows as $k => $r) {
        $c = $cur[$k] ?? null;
        if ($c && $c['source'] !== 'old') continue;                                           /* saved on the sheet: kept */
        ci_q($conn, "INSERT INTO incentive_sheet_entries (payroll_period_id, employee_id, section, comp_key, pct, actual, gm, amount, amount_manual, source, old_ids, updated_by, updated_at)
            VALUES ($pid, {$r['employee_id']}, " . $e($r['section']) . ", " . $e($r['comp_key']) . ", " . $e($r['pct']) . ", " . $e($r['actual']) . ", " . $e($r['gm']) . ", " . $e($r['amount']) . ", {$r['amount_manual']}, " . $e($r['source']) . ", " . $e(implode(',', $r['old_ids'])) . ", 'incentive_entries', NOW())
            ON DUPLICATE KEY UPDATE section = VALUES(section), pct = VALUES(pct), actual = VALUES(actual), gm = VALUES(gm), amount = VALUES(amount), amount_manual = VALUES(amount_manual),
                                    source = VALUES(source), old_ids = VALUES(old_ids), updated_by = VALUES(updated_by), updated_at = NOW()");
    }
    /* rows copied before whose old entry is gone */
    foreach ($cur as $k => $c) if (!isset($rows[$k]) && $c['source'] === 'old') ci_q($conn, "DELETE FROM incentive_sheet_entries WHERE id = " . (int)$c['id']);
}
/* the rows of a month: [employee_id][comp_key] => row */
function isl_rows($conn, $pid) {
    isl_ensure($conn); $o = [];
    $q = ci_q($conn, "SELECT * FROM incentive_sheet_entries WHERE payroll_period_id = " . (int)$pid);
    while ($q && ($x = mysqli_fetch_assoc($q))) $o[(int)$x['employee_id']][$x['comp_key']] = $x;
    return $o;
}
/* the rows of a month as the old labels the sheet reads ([employee_id][label] => ['amount', 'notes']) */
function isl_labels($conn, $pid) {
    $o = [];
    foreach (isl_rows($conn, $pid) as $eid => $list) foreach ($list as $key => $r) {
        $sk = $r['section']; $c = isl_comp($sk, $key);
        $mine = in_array($r['source'], ['sheet', 'live'], true) ? 'cc_acc_incentives' : '';
        if ($key === '_meal') { $o[$eid]['__reimb__meal_reimbursement'] = ['amount' => $r['amount'], 'notes' => json_encode(['src' => $mine])]; continue; }
        if (!$c) { $o[$eid][$key] = ['amount' => $r['amount'], 'notes' => null]; continue; }
        if (in_array($c[3], ['gm', 'act', 'dmg'], true)) {
            if ($r['pct'] !== null) $o[$eid][ci_lbl_pct($c)] = ['amount' => $r['pct'], 'notes' => json_encode(['act' => $r['actual'] === null ? null : (float)$r['actual'], 'gm' => $r['gm'] === null ? null : (float)$r['gm'], 'src' => $mine])];
            if ((int)$r['amount_manual'] === 1 && $r['amount'] !== null) $o[$eid][ci_lbl_amt($c)] = ['amount' => $r['amount'], 'notes' => null];
        } elseif ($c[3] === 'live') { if ($r['amount'] !== null) $o[$eid]['__reimb__' . ci_opt($c, 'field')] = ['amount' => $r['amount'], 'notes' => json_encode(['src' => $mine])]; }
        elseif ($r['amount'] !== null) $o[$eid][$c[3] === 'att' ? (ci_opt($c, 'amt') ?: '__incent__' . $key) : ci_lbl_amt($c)] = ['amount' => $r['amount'], 'notes' => null];
    }
    return $o;
}
/* save one column of an employee (null pct and amount = remove; $keepEmpty: keep it as an empty row, so an old value is not copied in again) */
function isl_put($conn, $pid, $eid, $sk, $key, $pct, $actual, $gm, $amount, $manual, $source, $by, $keepEmpty = false) {
    isl_ensure($conn);
    $e = function ($v) use ($conn) { return $v === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, (string)$v) . "'"; };
    if ($pct === null && $amount === null && !$keepEmpty) return ci_q($conn, "DELETE FROM incentive_sheet_entries WHERE payroll_period_id = $pid AND employee_id = $eid AND comp_key = " . $e($key));
    return ci_q($conn, "INSERT INTO incentive_sheet_entries (payroll_period_id, employee_id, section, comp_key, pct, actual, gm, amount, amount_manual, source, updated_by, updated_at)
        VALUES ($pid, $eid, " . $e($sk) . ", " . $e($key) . ", " . $e($pct) . ", " . $e($actual) . ", " . $e($gm) . ", " . $e($amount) . ", " . (int)$manual . ", " . $e($source) . ", " . $e($by) . ", NOW())
        ON DUPLICATE KEY UPDATE section = VALUES(section), pct = VALUES(pct), actual = VALUES(actual), gm = VALUES(gm), amount = VALUES(amount), amount_manual = VALUES(amount_manual),
                                source = VALUES(source), old_ids = NULL, updated_by = VALUES(updated_by), updated_at = NOW()");
}
/* the incentive amount of every column of an employee for the Live Salary Sheet: [comp_key => amount] (attendance / live columns excluded) */
function isl_incentives($sk, $rows, $e) {
    $o = [];
    foreach (isl_sections()[$sk][2] ?? [] as $c) {
        if (in_array($c[3], ['att', 'live'], true)) continue;
        $r = $rows[$c[0]] ?? null;
        if ($c[3] === 'amt') { $v = $r && $r['amount'] !== null ? (float)$r['amount'] : (float)($e[ci_opt($c, 'fb', '')] ?? 0); }
        else $v = $r && $r['amount'] !== null ? (float)$r['amount'] : 0.0;
        if ($v != 0) $o[$c[0]] = ['label' => $c[1], 'amount' => $v];
    }
    return $o;
}
}
