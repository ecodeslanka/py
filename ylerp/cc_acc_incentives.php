<?php
/**
 * cc_acc_incentives.php — Incentives Sheet for a payroll month (like the CC/ACC, Back Office, Sales and Merchandising incentive Excels).
 * One tab per staff category: SR, MR, CC, ACC, ST, OFF.
 *   Employees   : live from the employees table (same category rule as the salary sheets), grouped by designation.
 *   Rates       : from Salary Configuration (sr_salary_config, SR secondary-sales rates / incentive thresholds) of each designation — the blue rows.
 *   Inputs      : EMPTY cells entered by hand for the month — Actual / GM achievement %, or an amount (Discretionary Support, ETE incentive).
 *   Live        : attendance (days worked ÷ norm days), meal / travelling / mobile reimbursements, SR fuel, MR D-Link.
 *   Save        : writes to incentive_entries with the labels the salary sheets read (__iach__ / __incent__ / __sec_achievement / __ssv_amount /
 *                 __disc_amount / __other_incentive / __reimb__…), Actual & GM kept in notes. Only changed cells are written.
 */
include 'config.php';
@include_once __DIR__ . '/auth.php';
if (function_exists('requireLogin')) requireLogin();
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
date_default_timezone_set('Asia/Colombo');

include_once __DIR__ . '/incentive_sheet_lib.php';
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$SECTIONS = isl_sections();

/* ── payroll months ── */
$periods = []; $r = ci_q($conn, "SELECT id, year, month, status FROM payroll_periods ORDER BY year DESC, month DESC");
while ($r && ($x = mysqli_fetch_assoc($r))) $periods[] = $x;
$pid = (int)($_REQUEST['period_id'] ?? 0);
if (!$pid && $periods) { foreach ($periods as $p) if (strtolower((string)$p['status']) === 'open') { $pid = (int)$p['id']; break; } if (!$pid) $pid = (int)$periods[0]['id']; }
$period = null; foreach ($periods as $p) if ((int)$p['id'] === $pid) $period = $p;
$MON = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

$pct = function ($v, $mode = '') { $v = trim(str_replace(['%', ','], '', (string)$v)); if ($v === '' || !is_numeric($v)) return null; $v = (float)$v;
    if ($mode === 'amt') return round($v, 2);
    if ($mode === 'dmg') { if ($v > 0 && $v < 0.001) $v *= 100; return round($v, 5); }       /* damage typed as % (0.007), a fraction (0.00007) is converted */
    if ($v > 0 && $v <= 2) $v *= 100; return round($v, 2); };   /* a fraction (0.906, 1.0497) → % */

/* ── Payroll Header links: each column of a tab can be linked to a Payroll Header (payroll_headers.php) ──
   incentive_sheet_headers: section, comp_key ('_meal' = the Meal column), header_id */
if (is_file(__DIR__ . '/payroll_headers_lib.php')) include_once __DIR__ . '/payroll_headers_lib.php';
function ci_links_ensure($conn) {
    if (function_exists('ph_ensure')) ph_ensure($conn);
    ci_q($conn, "CREATE TABLE IF NOT EXISTS incentive_sheet_headers (section VARCHAR(10) NOT NULL, comp_key VARCHAR(60) NOT NULL, header_id INT NOT NULL,
        updated_by VARCHAR(100) NULL, updated_at DATETIME NULL, PRIMARY KEY (section, comp_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
/* the salary component (payroll_headers.component_key) each column matches — used to suggest the link */
const CI_PH_KEY = ['ccfot' => 'ccfot', 'ccfot_acc' => 'ccfot', 'ccfot_st' => 'ccfot', 'credit_mgt' => 'credit_mgt', 'payee_chq' => 'payee_chq',
    'ssv_cc' => 'ssv', 'ssv_acc' => 'ssv', 'ssv_sr' => 'ssv', 'attendance_cc' => 'att_inc', 'attendance_mr' => 'att_inc', 'attendance_acc' => 'att_allow',
    'attendance_st' => 'att_allow', 'attendance_off' => 'att_allow', 'locus_admin' => 'locus_admin', 'punctuality_acc' => 'punctuality', 'punctuality' => 'punctuality',
    'punctuality_75' => 'punctuality', 'disc' => 'disc', 'total_ps_compliance' => 'ps', 'eco_incentive' => 'eco', 'bp_incentive' => 'bp', 'total_assortment' => 'assort',
    'ete' => 'other_inc', 'meal_sr' => 'meal', 'meal_mr' => 'meal', '_meal' => 'meal', 'travel_sr' => 'travel', 'travel_mr' => 'travel', 'fuel_sr' => 'fuel',
    'mobile_sr' => 'mobile', 'placement_compliance' => 'plc', 'dlink' => 'dlink', 'incentive_daily_90' => 'daily_inc', 'incentive_weekly_90' => 'weekly_inc',
    'incentive_monthly_90' => 'monthly_inc', 'stores_damage' => 'stores_dmg', 'rsqm' => 'rsqm', 'loading_unloading' => 'load_unload'];
/* the columns of a tab that can be linked: key => label */
function ci_link_cols($sec) {
    $o = []; foreach ($sec[2] as $c) $o[$c[0]] = $c[1];
    if ($sec[3] !== 'none') $o['_meal'] = 'Meal';
    return $o;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'link_save') {
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('rbac_can') && !rbac_can('edit', 'cc_acc_incentives')) { echo json_encode(['ok' => false, 'msg' => 'Permission blocked for your role.']); exit; }
    ci_links_ensure($conn);
    $in = json_decode((string)($_POST['links'] ?? ''), true); if (!is_array($in)) { echo json_encode(['ok' => false, 'msg' => 'Nothing to save.']); exit; }
    $ok_ids = []; $r = ci_q($conn, "SELECT id FROM payroll_headers"); while ($r && ($x = mysqli_fetch_row($r))) $ok_ids[(int)$x[0]] = 1;
    $by = mysqli_real_escape_string($conn, (string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'system'));
    $n = 0;
    foreach ($SECTIONS as $sk => $sec) foreach (ci_link_cols($sec) as $key => $lbl) {
        if (!isset($in[$sk]) || !array_key_exists($key, $in[$sk])) continue;
        $hid = (int)$in[$sk][$key]; $ke = mysqli_real_escape_string($conn, $key);
        if ($hid > 0 && isset($ok_ids[$hid])) ci_q($conn, "REPLACE INTO incentive_sheet_headers (section, comp_key, header_id, updated_by, updated_at) VALUES ('$sk', '$ke', $hid, '$by', NOW())");
        else ci_q($conn, "DELETE FROM incentive_sheet_headers WHERE section = '$sk' AND comp_key = '$ke'");
        $n++;
    }
    echo json_encode(['ok' => true, 'msg' => 'Header links saved.']); exit;
}

/* ── SAVE (AJAX) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('rbac_can') && !rbac_can('edit', 'cc_acc_incentives')) { echo json_encode(['ok' => false, 'msg' => 'Permission blocked for your role.']); exit; }
    if (!$period) { echo json_encode(['ok' => false, 'msg' => 'Choose a payroll month.']); exit; }
    if (strtolower((string)$period['status']) === 'closed' && !isl_is_old($period)) { echo json_encode(['ok' => false, 'msg' => 'This payroll month is closed.']); exit; }   /* old months: only the new table changes */
    $data = json_decode((string)($_POST['data'] ?? ''), true); if (!is_array($data)) { echo json_encode(['ok' => false, 'msg' => 'Nothing to save.']); exit; }
    $uid = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
    $n = 0; $err = '';
    $run = function ($sql) use ($conn, &$err) { $ok = ci_q($conn, $sql); if (!$ok && $err === '') $err = mysqli_error($conn) ?: 'database error'; return $ok; };
    $by = (string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'system');
    $oldM = isl_is_old($period);                     /* before the cutover: saved in incentive_sheet_entries only (the old rows are left as they were) */
    isl_sync($conn, $period);
    $ctx = ci_ctx($conn, $period, $pid);
    /* one row per (employee, month, label): update the first, drop duplicates, insert when missing, delete when $val is null */
    $put = function ($eid, $label, $val, $notes, $type) use ($conn, $pid, $uid, $run, $oldM) {
        if ($oldM) return;
        $label = mysqli_real_escape_string($conn, $label);
        $ex = []; $q = ci_q($conn, "SELECT id FROM incentive_entries WHERE employee_id = $eid AND payroll_period_id = $pid AND component_label = '$label' ORDER BY id");
        while ($q && ($x = mysqli_fetch_row($q))) $ex[] = (int)$x[0];
        if ($val === null) { if ($ex) $run("DELETE FROM incentive_entries WHERE id IN (" . implode(',', $ex) . ")"); return; }
        $nt = $notes === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $notes) . "'";
        if ($ex) {
            $keep = array_shift($ex);
            $run("UPDATE incentive_entries SET amount = '$val', notes = $nt, updated_at = NOW() WHERE id = $keep");
            if ($ex) $run("DELETE FROM incentive_entries WHERE id IN (" . implode(',', $ex) . ")");
        } else {
            $run("INSERT INTO incentive_entries (incentive_type_id, employee_id, payroll_period_id, component_label, amount, notes" . ($uid ? ', entered_by' : '') . ")
                  VALUES ($type, $eid, $pid, '$label', '$val', $nt" . ($uid ? ", $uid" : '') . ")");
        }
    };
    foreach ($SECTIONS as $sk => $sec) {
        if (empty($data[$sk]) || !is_array($data[$sk])) continue;
        $emps = ci_employees($conn, $sec[1], $period); $type = ci_type_id($conn, $sec[1]);
        $comps = []; foreach ($sec[2] as $c) if (!in_array($c[3], ['att', 'live'], true)) $comps[$c[0]] = $c;
        foreach ($data[$sk] as $eid => $vals) {
            $eid = (int)$eid; if (!isset($emps[$eid]) || !is_array($vals)) continue;
            $e = $emps[$eid]; $cfg = $ctx['cfgs'][(int)($e['designation_id'] ?? 0)] ?? [];
            foreach ($vals as $key => $v) {
                if (!isset($comps[$key]) || !is_array($v)) continue;
                $c = $comps[$key];
                /* incentive_sheet_entries (the sheet's table) + the old incentive_entries rows the salary sheets read */
                if ($c[3] === 'amt') {
                    $amt = $pct($v['act'] ?? '', 'amt');
                    if (!isl_put($conn, $pid, $eid, $sk, $key, null, null, null, $amt, 1, 'sheet', $by, true) && $err === '') $err = mysqli_error($conn);
                    $put($eid, ci_lbl_amt($c), $amt, null, $type); $n++; continue;
                }
                $act = $pct($v['act'] ?? '', $c[3]); $gm = $pct($v['gm'] ?? '', $c[3]); $use = $gm !== null ? $gm : $act;
                $earn = $use === null ? null : ci_amount($c[3], ci_tiers($c, $cfg), $cfg, $use);
                if (!isl_put($conn, $pid, $eid, $sk, $key, $use, $act, $gm, $earn, 0, 'sheet', $by, true) && $err === '') $err = mysqli_error($conn);
                $put($eid, ci_lbl_pct($c), $use, json_encode(['act' => $act, 'gm' => $gm, 'src' => 'cc_acc_incentives']), $type);
                if ($sec[4] || ci_opt($c, 'save_amt')) $put($eid, ci_lbl_amt($c), $earn, null, $type);   /* the salary sheet reads the amount */
                $n++;
            }
            /* reimbursements the salary sheet reads only from Incentive Entry (SR): saved from live data (kept up to date by this page) */
            $calc = ci_emp_calc($ctx, $e);
            foreach ($sec[2] as $c) {
                if ($c[3] !== 'live' || !ci_opt($c, 'save')) continue;
                $old = $ctx['inc'][$eid][ci_opt($c, 'save')] ?? null;
                if ($old && !ci_mine($old)) continue;                                 /* entered in Incentive Entry: left as it is */
                $lv = ci_live($ctx, $c, $e, $cfg, $calc)[0];
                isl_put($conn, $pid, $eid, $sk, $c[0], null, null, null, $lv, 0, 'live', $by);
                $put($eid, ci_opt($c, 'save'), $lv, json_encode(['src' => 'cc_acc_incentives', 'live' => 1]), $type);
            }
        }
    }
    if ($err !== '') { echo json_encode(['ok' => false, 'msg' => 'Could not save: ' . $err]); exit; }
    echo json_encode(['ok' => true, 'msg' => "Saved ($n change" . ($n === 1 ? '' : 's') . ').']); exit;
}

/* ── build the sheet ── */
$out = [];
if ($period) {
    isl_sync($conn, $period);                                                     /* old incentive_entries → incentive_sheet_entries */
    $ctx = ci_ctx($conn, $period, $pid); $cfgs = $ctx['cfgs']; $inc = $ctx['inc'];
    foreach ($SECTIONS as $sk => $sec) {
        $emps = ci_employees($conn, $sec[1], $period);
        /* tier columns: the tiers any designation of this section has a rate for (all when none is configured) */
        $comps = [];
        foreach ($sec[2] as $c) {
            $used = [];
            foreach ($c[2] as $t => $f) foreach ($emps as $e) if ((float)($cfgs[(int)$e['designation_id']][$f] ?? 0) > 0) { $used[$t] = $f; break; }
            $c[2] = $used ?: $c[2]; uksort($c[2], function ($a, $b) { return (float)$a <=> (float)$b; }); $comps[] = $c;
        }
        $groups = [];
        foreach ($emps as $eid => $e) {
            $did = (int)($e['designation_id'] ?? 0); $cfg = $cfgs[$did] ?? [];
            $calc = ci_emp_calc($ctx, $e);
            $basic = (float)($e['basic_salary'] ?? 0);
            if ($sk === 'ST' && (float)($cfg['basic_salary'] ?? 0) > 0) $basic = (float)$cfg['basic_salary'];
            $vals = [];
            foreach ($comps as $c) {
                if ($c[3] === 'live') { [$lv, $sv] = ci_live($ctx, $c, $e, $cfg, $calc); $vals[$c[0]] = ['live' => $lv, 'saved' => $sv]; continue; }
                $fixedRow = $inc[$eid][ci_lbl_amt($c)] ?? null;
                if ($c[3] === 'amt') { $vals[$c[0]] = ['amt' => $fixedRow ? (float)$fixedRow['amount'] : null, 'fb' => (float)($e[ci_opt($c, 'fb', '')] ?? 0)]; continue; }
                $row = $inc[$eid][ci_lbl_pct($c)] ?? null; $nt = $row ? (json_decode((string)$row['notes'], true) ?: []) : [];
                $act = $row ? ($nt['act'] ?? (float)$row['amount']) : null; $gm = $nt['gm'] ?? null;
                /* an amount entered in Incentive Entry without a % here: the salary sheet uses that amount (shown red) */
                $fixed = $fixedRow ? (float)$fixedRow['amount'] : 0;
                if (($sec[4] || ci_opt($c, 'save_amt')) && $row) $fixed = 0;
                $vals[$c[0]] = ['act' => $act, 'gm' => $gm, 'fixed' => $fixed > 0 ? $fixed : null, 'tiers' => array_keys(ci_tiers($c, $cfg))];
            }
            if (!isset($groups[$did])) $groups[$did] = ['name' => $e['designation_name'] ?: 'No designation', 'code' => $e['designation_code'] ?? '', 'cfg' => $cfg, 'emps' => []];
            $groups[$did]['emps'][] = ['id' => $eid, 'name' => $e['employee_full_name'] ?? '', 'code' => $e['employee_id'] ?? '', 'note' => $e['pme_note'] ?? '', 'left' => $e['pme_exit'] ?? null, 'days' => $calc['w'], 'norm' => $calc['norm'], 'rate' => $calc['rate'],
                'npd' => $calc['npd'], 'basic' => $basic, 'basic_adj' => $basic > 0 ? round($basic - $basic / $calc['norm'] * $calc['npd'], 2) : 0,
                'meal_cfg' => (float)(($cfg['meal_reimbursement'] ?? null) ?: ($e['reimbursement_meal'] ?? 0)), 'v' => $vals,
                /* meal saved for the month in Incentive Entry is used as it is (ST / OFF sheets do the same) */
                'meal_fixed' => ($sec[3] === 'flat' || $sk === 'ST') && isset($inc[$eid]['__reimb__meal_reimbursement']) ? (float)$inc[$eid]['__reimb__meal_reimbursement']['amount'] : null];
        }
        /* designations by rank: the lowest CCFOT 90% rate / basic first (as in the Excels), then name */
        uasort($groups, function ($a, $b) { return ((float)($a['cfg']['ccfot_90'] ?? 0) <=> (float)($b['cfg']['ccfot_90'] ?? 0)) ?: ((float)($a['cfg']['basic_salary'] ?? 0) <=> (float)($b['cfg']['basic_salary'] ?? 0)) ?: strcasecmp($a['name'], $b['name']); });
        $out[$sk] = ['title' => $sec[0], 'comps' => $comps, 'groups' => array_values($groups), 'meal' => $sec[3], 'sa' => $sec[4]];
    }
}
/* payroll headers (additions) and the saved links */
ci_links_ensure($conn);
$PH = []; $r = ci_q($conn, "SELECT id, name, component_key, active FROM payroll_headers WHERE header_type = 'addition' ORDER BY active DESC, sort_order, name");
while ($r && ($x = mysqli_fetch_assoc($r))) { $x['cats'] = []; $PH[(int)$x['id']] = $x; }
$r = ci_q($conn, "SELECT header_id, category_code FROM payroll_header_categories"); while ($r && ($x = mysqli_fetch_row($r))) if (isset($PH[(int)$x[0]])) $PH[(int)$x[0]]['cats'][] = $x[1];
$LINKS = []; $r = ci_q($conn, "SELECT section, comp_key, header_id FROM incentive_sheet_headers"); while ($r && ($x = mysqli_fetch_row($r))) if (isset($PH[(int)$x[2]])) $LINKS[$x[0]][$x[1]] = (int)$x[2];
/* suggested header of a column: same salary component ticked for the category, else same component, else same name */
$ci_suggest = function ($sk, $key, $label) use ($PH, $SECTIONS) {
    $ck = CI_PH_KEY[$key] ?? ''; $cats = $SECTIONS[$sk][1]; $best = 0;
    foreach ($PH as $id => $x) if ($ck !== '' && $x['component_key'] === $ck && array_intersect($cats, $x['cats'])) return $id;
    foreach ($PH as $id => $x) if ($ck !== '' && $x['component_key'] === $ck) { $best = $id; break; }
    if (!$best) foreach ($PH as $id => $x) if (strcasecmp(trim($x['name']), trim($label)) === 0) { $best = $id; break; }
    return $best;
};
/* the header text of a column: the linked Payroll Header's name */
$ci_head = function ($sk, $key, $label) use ($LINKS, $PH, $h) {
    $id = $LINKS[$sk][$key] ?? 0;
    if (!$id) return $h($label);
    return '<span title="Linked to Payroll Header: ' . $h($PH[$id]['name']) . ($label !== $PH[$id]['name'] ? ' (' . $h($label) . ')' : '') . '">' . $h($PH[$id]['name']) . ' <i class="fa-solid fa-link" style="font-size:9px;opacity:.75"></i></span>';
};
$plabel = $period ? $MON[(int)$period['month']] . ' ' . $period['year'] : '';
$oldMonth = $period && isl_is_old($period);                                 /* before the cutover: from the old Incentive Entries, view only */
$closed = $period && strtolower((string)$period['status']) === 'closed' && !$oldMonth;
$back = (!empty($_SERVER['HTTP_REFERER']) && strpos((string)$_SERVER['HTTP_REFERER'], 'cc_acc_incentives.php') === false) ? $_SERVER['HTTP_REFERER'] : 'sr_salary.php';
$nf = function ($v) { return $v === null || $v === '' ? '' : (abs((float)$v) < 0.005 ? '-' : number_format((float)$v, (float)$v == (int)$v ? 0 : 2)); };
$pv = function ($v) { return $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); };
$dv = function ($v) { return $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float)$v, 5, '.', ''), '0'), '.'); };
$tl = function ($c, $t) { $t = (string)$t; if (strpos($t, '.') !== false) $t = rtrim(rtrim($t, '0'), '.'); return ($c[3] === 'dmg' ? '≤' : '') . $t . '%'; };
$dis = $closed ? ' disabled' : '';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Incentives Sheet<?= $plabel ? ' — ' . $h($plabel) : '' ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<style>
:root{ --ob-navy:#1F497D; --ob-acc:#4F81BD; --ob-pale:#DCE6F1; --ob-line:#0070C0; --ink:#1d1f22; --grid:#c9c9c4; --win:#ecebe4; --neg:#b3261e; --f: Tahoma, Verdana, "Segoe UI", Geneva, sans-serif; }
html,body{height:100%} *{box-sizing:border-box}
body{margin:0;background:linear-gradient(160deg,#8a8d93,#6f7278);font:11px/1.3 var(--f);color:var(--ink);padding:8px 10px}
.win{height:100%;display:flex;flex-direction:column;background:var(--win);border:1px solid #4a4c52;border-radius:7px 7px 3px 3px;box-shadow:0 6px 22px rgba(0,0,0,.35);overflow:hidden}
.tb{flex:none;display:flex;align-items:center;gap:8px;padding:5px 8px;background:linear-gradient(#e9f0f8,#DCE6F1 45%,#B8CCE4);border-bottom:2px solid var(--ob-line)}
.tb h1{margin:0;font-size:13px;color:var(--ob-navy);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.btn{font:bold 11px var(--f);height:24px;padding:0 11px;border:1px solid #385d8a;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;color:#fff;text-decoration:none;
  background:linear-gradient(#86a9db,var(--ob-acc) 55%,#3d6cad);box-shadow:0 2px 3px rgba(0,0,0,.3),inset 0 1px 0 rgba(255,255,255,.4);text-shadow:0 -1px 0 rgba(0,0,0,.25)}
.btn:hover{background:linear-gradient(#9dbbe6,#5f8fcb 55%,#4877b8)} .btn.pri{background:linear-gradient(#5b87c6,#2E5A8E 55%,var(--ob-navy));border-color:#16365e} .btn:disabled{opacity:.5;cursor:default}
.bar{flex:none;display:flex;flex-wrap:wrap;align-items:center;gap:6px;padding:5px 8px;background:#f6f5f0;border-bottom:1px solid #a7a9ad}
.bar label{font-weight:bold;color:#55585e}
.bar select,.bar input[type=search]{font:11px var(--f);height:22px;border:1px solid #8e909a;border-radius:2px;padding:0 4px;background:#fff}
.tabs{display:flex;gap:4px;flex-wrap:wrap} .tab{padding:3px 12px;border:1px solid var(--ob-line);border-radius:10px;background:#fff;color:var(--ob-navy);font-weight:bold;cursor:pointer}
.tab.on{background:linear-gradient(#5b87c6,var(--ob-navy));color:#fff}
.sp{flex:1} .dirty{color:#b45309;font-weight:bold;display:none} .dirty.on{display:inline}
.kpis{flex:none;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:6px;padding:6px 8px}
.kpi{border:1px solid #a7a9ad;background:#f6f5f0;padding:4px 8px} .kpi .k{font-weight:bold;color:#55585e;font-size:10px;text-transform:uppercase}
.kpi .v{font-size:15px;font-weight:bold;font-variant-numeric:tabular-nums;color:var(--ob-navy);margin-top:1px}
.title{flex:none;margin:0 8px;background:linear-gradient(#e9f0f8,#DCE6F1 50%,#B8CCE4);border:1px solid var(--ob-line);color:var(--ob-navy);font-weight:bold;font-size:13px;text-align:center;padding:3px;letter-spacing:.06em}
.scroll{flex:1;min-height:0;overflow:auto;margin:4px 8px 8px;border:1px solid #a7a9ad;background:#fff}
table{border-collapse:collapse;font-variant-numeric:tabular-nums;font-size:10.5px;min-width:100%}
th,td{border:1px solid var(--grid);padding:2px 4px;white-space:nowrap;height:20px}
thead th{position:sticky;z-index:2;background:linear-gradient(#3a64a0,var(--ob-navy));color:#fff;border-color:#173a63;font-weight:bold;text-align:center}
thead tr:first-child th{top:0} thead tr:nth-child(2) th{top:21px}
th.g0{background:linear-gradient(#4fa36a,#2e7d46)} th.g1{background:linear-gradient(#9b7fd0,#6b4fa8)} th.gR{background:linear-gradient(#5aa9c9,#2b7a9c)} th.gT{background:linear-gradient(#ffd966,#f0b400);color:#3a2c00}
td.n{text-align:right} td.c{text-align:center} td.b{font-weight:bold}
td.sticky,th.sticky{position:sticky;left:0;z-index:1;background:#fff} thead th.sticky{z-index:3;background:linear-gradient(#3a64a0,var(--ob-navy))}
tr.rate td{background:var(--ob-pale);color:var(--ob-navy);font-weight:bold} tr.rate td.sticky{background:var(--ob-pale)}
tr.rate td.lbl{color:#55585e;font-weight:normal;font-style:italic}
td.tier{color:#b9bac2;text-align:right} td.tier.hit{background:#e9f7ec;color:#1e6b34;font-weight:bold}
td.tot{background:#fff8d6;font-weight:bold;text-align:right} td.live,td.lv{background:#f3f6fa;text-align:right} td.lv.sv{color:#1e6b34}
td.in{padding:0;background:#fffef5} td.in input{width:54px;height:19px;border:0;background:transparent;font:11px var(--f);text-align:right;padding:0 4px;outline:none}
td.in.amt input{width:70px} td.in input::placeholder{color:#8e909a}
td.in input:focus{background:#fff3b0;box-shadow:inset 0 0 0 1px var(--ob-line)} td.in.gm{background:#fff4e5}
td.fx{background:#fdecea !important;color:var(--neg) !important}
tr.emp:hover td{background:#fff1a8} tr.emp:hover td.in{background:#fff1a8}
tr.grand td{background:#fff3c4;font-weight:bold;border-top:2px solid #d9b300;text-align:right}
.note{flex:none;padding:3px 10px 6px;color:#55585e;font-size:10.5px}
.mdl{position:fixed;inset:0;background:rgba(30,32,36,.45);display:none;align-items:center;justify-content:center;z-index:8;padding:12px}.mdl.on{display:flex}
.mbox{width:min(760px,100%);max-height:92vh;display:flex;flex-direction:column;background:var(--win);border:1px solid #4a4c52;border-radius:7px;box-shadow:0 10px 30px rgba(0,0,0,.4);overflow:hidden}
.mscroll{overflow:auto;flex:1;min-height:0;background:#fff;margin:6px 8px;border:1px solid #a7a9ad} .ltbl{width:100%} .ltbl td{height:26px} .ltbl select{width:100%;font:11px var(--f);height:22px;border:1px solid #8e909a;border-radius:2px}
.ltbl select.chg{background:#fff3b0}
/* Select2 — compact, page style */
.select2-container{font:11px var(--f)}
.select2-container--default .select2-selection--single{height:22px;border:1px solid #8e909a;border-radius:2px;background:#fff}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:20px;padding-left:5px;padding-right:18px;color:var(--ink)}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:20px;width:16px}
.select2-container--default.select2-container--focus .select2-selection--single,.select2-container--default.select2-container--open .select2-selection--single{border-color:var(--ob-line)}
.select2-dropdown{font:11px var(--f);border-color:var(--ob-line);border-radius:2px;z-index:20}
.select2-search--dropdown{padding:3px} .select2-search--dropdown .select2-search__field{font:11px var(--f);height:22px;padding:1px 4px;border-color:#8e909a}
.select2-results__option{padding:3px 6px} .select2-results>.select2-results__options{max-height:240px}
.select2-container--default .select2-results__group{padding:3px 6px;color:var(--ob-navy);background:var(--ob-pale);font-weight:bold}
.select2-container--default .select2-results__option .select2-results__option{padding-left:14px}
.select2-container--default .select2-results__option--highlighted.select2-results__option--selectable{background:var(--ob-acc);color:#fff}
.select2-container--default .select2-results__option--selected{background:#e9f0f8}
.select2-container.chg .select2-selection--single{background:#fff3b0}
.lft{display:inline-block;padding:0 4px;border-radius:6px;background:#fdecea;border:1px solid #f1aeb5;color:var(--neg);font-size:9.5px;font-weight:bold}
.mv{display:inline-block;padding:0 4px;border-radius:6px;background:#e9f0f8;border:1px solid var(--ob-line);color:var(--ob-navy);font-size:9.5px;font-weight:normal}
.toast{position:fixed;right:18px;bottom:18px;padding:8px 14px;border-radius:6px;color:#fff;font-weight:bold;display:none;z-index:9;box-shadow:0 4px 12px rgba(0,0,0,.3)}
@media (max-width:900px){.kpis{grid-template-columns:repeat(2,minmax(0,1fr))} body{padding:4px}}
@media print{body{background:#fff;padding:0}.win{border:0;box-shadow:none;height:auto}.bar,.tb .btn,.kpis,.note{display:none}.scroll{overflow:visible;border:0}thead th{position:static} td.in input{width:40px}}
</style></head><body>
<div class="win">
  <div class="tb">
    <a href="<?= $h($back) ?>" class="btn" title="Back"><i class="fa-solid fa-arrow-left"></i> Back</a>
    <h1><i class="fa-solid fa-coins"></i> Incentives Sheet<?= $plabel ? ' — ' . $h($plabel) : '' ?></h1>
    <span class="dirty" id="ciDirty"><i class="fa-solid fa-circle-exclamation"></i> Unsaved changes</span>
    <button class="btn pri" id="ciSave" onclick="ciSave()"<?= (!$period || $closed) ? ' disabled' : '' ?>><i class="fa-solid fa-floppy-disk"></i> Save</button>
    <button class="btn" onclick="ciLinks()" title="Link the columns to Payroll Headers"><i class="fa-solid fa-link"></i> Link Headers</button>
    <button class="btn" onclick="ciExport()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
    <button class="btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
  </div>
  <form class="bar" method="get">
    <label>Payroll Month</label>
    <select name="period_id" onchange="if(!ciLeave()){this.value='<?= $pid ?>';if(window.jQuery&&jQuery(this).data('select2'))jQuery(this).trigger('change.select2');return;} this.form.submit()">
      <?php foreach ($periods as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === $pid ? ' selected' : '' ?>><?= $h($MON[(int)$p['month']] . ' ' . $p['year']) ?><?= $p['status'] ? ' (' . $h($p['status']) . ')' : '' ?></option><?php endforeach; ?>
    </select>
    <div class="tabs"><?php $first = true; foreach ($out as $sk => $s): $cnt = 0; foreach ($s['groups'] as $g) $cnt += count($g['emps']); ?><span class="tab<?= $first ? ' on' : '' ?>" data-sec="<?= $sk ?>" onclick="ciTab('<?= $sk ?>')" title="<?= $h($s['title']) ?>"><?= $sk ?> — <?= $h($s['title']) ?> (<?= $cnt ?>)</span><?php $first = false; endforeach; ?></div>
    <input type="search" id="ciQ" placeholder="Search name / code…" oninput="ciFilter()" style="width:200px">
    <span class="sp"></span>
    <?php if ($oldMonth): ?><span style="color:#b45309;font-weight:bold"><i class="fa-solid fa-clock-rotate-left"></i> Filled from the old Incentive Entries — changes are saved to the new table only</span>
    <?php elseif ($closed): ?><span style="color:var(--neg);font-weight:bold"><i class="fa-solid fa-lock"></i> Closed month — view only</span><?php endif; ?>
  </form>
  <div class="kpis">
    <div class="kpi"><div class="k">Employees</div><div class="v" id="kN">0</div></div>
    <div class="kpi"><div class="k">Total Incentives</div><div class="v" id="kI">0</div></div>
    <div class="kpi"><div class="k">Reimbursements</div><div class="v" id="kM">0</div></div>
    <div class="kpi"><div class="k">Basic (after No Pay)</div><div class="v" id="kB">0</div></div>
    <div class="kpi"><div class="k">Basic + Incentives</div><div class="v" id="kG">0</div></div>
  </div>
  <div class="title" id="ciTitle">INCENTIVES — <?= $h(strtoupper($plabel ?: 'NO PAYROLL MONTH')) ?></div>
  <?php if (!$period): ?><div class="scroll" style="padding:20px;text-align:center;color:#8e909a">No payroll month found.</div><?php endif; ?>
  <?php $first = true; foreach ($out as $sk => $s):
    /* column plan: every money column gets an index (data-col) for the TOTAL row */
    $col = 0; $plan = [];
    foreach ($s['comps'] as $i => $c) {
        if ($c[3] === 'amt' || $c[3] === 'live') { $plan[$i] = ['money' => [$col++]]; continue; }
        $m = []; foreach ($c[2] as $t => $f) $m[] = $col++; $plan[$i] = ['money' => $m];
    }
    $mealCol = $s['meal'] !== 'none' ? $col++ : null;
    /* Incentive Total: after the incentive columns, before the reimbursement (live) amount columns */
    $firstLive = count($s['comps']); foreach ($s['comps'] as $i => $c) if ($c[3] === 'live') { $firstLive = $i; break; }
    $nComp = count($s['comps']); ?>
  <div class="scroll sec" data-sec="<?= $sk ?>"<?= $first ? '' : ' style="display:none"' ?>>
    <table id="ciTbl_<?= $sk ?>" data-sa="<?= $s['sa'] ? 1 : 0 ?>">
      <thead>
        <tr><th rowspan="2" class="sticky">Name</th><th rowspan="2">Designation</th><th rowspan="2">Code</th>
          <?php foreach ($s['comps'] as $i => $c): $g = $c[3] === 'live' ? 'gR' : 'g' . ($i % 2);
            if ($i === $firstLive) echo '<th rowspan="2" class="gT">Incentive Total</th>';
            $span = ci_is_pct($c) ? count($c[2]) + ($c[3] === 'gm' ? 2 : 1) : 1; ?><th colspan="<?= $span ?>" class="<?= $g ?>"><?= $ci_head($sk, $c[0], $c[1]) ?></th><?php endforeach; if ($firstLive === $nComp) echo '<th rowspan="2" class="gT">Incentive Total</th>'; ?>
          <?php if ($mealCol !== null): ?><th rowspan="2" class="gR"><?= $ci_head($sk, '_meal', 'Meal') ?></th><?php endif; ?><th rowspan="2" class="gT" title="Incentive Total + reimbursements">Total + Reimb.</th><th rowspan="2">Days Worked</th><th rowspan="2">No Pay Days</th><th rowspan="2">Basic</th><th rowspan="2" class="gT">Basic + Incentives</th></tr>
        <tr><?php foreach ($s['comps'] as $i => $c): $g = $c[3] === 'live' ? 'gR' : 'g' . ($i % 2);
            if ($c[3] === 'amt'): ?><th class="<?= $g ?>">Amount</th><?php elseif ($c[3] === 'live'): ?><th class="<?= $g ?>">Live</th><?php else: ?>
            <th class="<?= $g ?>">Actual</th><?php if ($c[3] === 'gm'): ?><th class="<?= $g ?>">GM</th><?php endif; foreach ($c[2] as $t => $f): ?><th class="<?= $g ?>"><?= $tl($c, $t) ?></th><?php endforeach; endif; endforeach; ?></tr>
      </thead>
      <tbody>
      <?php if (!$s['groups']): ?><tr><td colspan="99" style="text-align:center;padding:20px;color:#8e909a">No employees in this category.</td></tr><?php endif; ?>
      <?php foreach ($s['groups'] as $g): $cfg = $g['cfg']; ?>
        <tr class="rate"><td class="sticky"><?= $h($g['name']) ?></td><td><?= $h($g['name']) ?></td><td><?= $h($g['code']) ?></td>
          <?php foreach ($s['comps'] as $i => $c):
            if ($i === $firstLive) echo '<td></td>';
            if ($c[3] === 'amt') { echo '<td class="n">' . $nf(ci_opt($c, 'cfg') ? ($cfg[ci_opt($c, 'cfg')] ?? null) : null) . '</td>'; continue; }
            if ($c[3] === 'live') { echo '<td class="n">' . $nf(ci_opt($c, 'field') ? ($cfg[ci_opt($c, 'field')] ?? null) : null) . '</td>'; continue; }
            $own = array_keys(ci_tiers($c, $cfg)); ?>
            <td class="lbl"><?= count($c[2]) === 1 && (string)$own[0] !== (string)array_keys($c[2])[0] ? 'at ' . $h($tl($c, $own[0])) : 'rate' ?></td><?php if ($c[3] === 'gm'): ?><td class="lbl"></td><?php endif; foreach ($c[2] as $t => $f): ?><td class="n"><?= $nf($cfg[$f] ?? null) ?></td><?php endforeach; endforeach; if ($firstLive === $nComp) echo '<td></td>'; ?>
          <?php if ($mealCol !== null): ?><td class="n" title="<?= $s['meal'] === 'flat' ? 'Meal reimbursement (fixed)' : 'Meal reimbursement × attendance rate' ?>"><?= $nf($cfg['meal_reimbursement'] ?? null) ?></td><?php endif; ?><td></td><td></td><td></td><td></td><td></td></tr>
        <?php foreach ($g['emps'] as $e): ?>
        <tr class="emp" data-id="<?= $e['id'] ?>" data-s="<?= $h(mb_strtolower($e['name'] . ' ' . $e['code'] . ' ' . $g['name'])) ?>" data-rate="<?= $e['rate'] ?>" data-meal="<?= $e['meal_cfg'] ?>" data-mealmode="<?= $s['meal'] ?>" data-mealfix="<?= $e['meal_fixed'] === null ? '' : $e['meal_fixed'] ?>" data-basic="<?= $e['basic_adj'] ?>">
          <td class="sticky b"<?= $e['note'] !== '' ? ' title="' . $h($e['note']) . '"' : '' ?>><?= $h($e['name']) ?><?php if ($e['left']): ?> <span class="lft">left <?= $h(date('d M', strtotime($e['left']))) ?></span><?php elseif ($e['note'] !== ''): ?> <span class="mv"><?= stripos($e['note'], 'after this month') !== false ? 'before move' : 'moved' ?></span><?php endif; ?></td><td><?= $h($g['name']) ?></td><td><?= $h($e['code']) ?></td>
          <?php foreach ($s['comps'] as $i => $c): $v = $e['v'][$c[0]]; $mc = $plan[$i]['money'];
            if ($i === $firstLive) echo '<td class="tot itot"></td>';
            if ($c[3] === 'live') { echo '<td class="lv' . ($v['saved'] ? ' sv' : '') . '" data-col="' . $mc[0] . '" data-kind="r" data-v="' . $v['live'] . '" title="' . ($v['saved'] ? 'Saved in Incentive Entry' : 'Live') . '">' . $nf($v['live']) . '</td>'; continue; }
            if ($c[3] === 'amt') { echo '<td class="in amt" data-col="' . $mc[0] . '" data-kind="i"><input class="iamt" data-k="' . $c[0] . '" data-m="amt" data-fb="' . $v['fb'] . '" value="' . $h($v['amt'] === null ? '' : $pv($v['amt'])) . '" placeholder="' . $h($v['fb'] > 0 ? $nf($v['fb']) : '') . '" title="' . ($v['fb'] > 0 ? 'Empty = ' . $nf($v['fb']) . ' from the employee profile' : '') . '" inputmode="decimal"' . $dis . '></td>'; continue; }
            $tiers = $v['tiers']; $k = 0; ?>
            <?php if ($c[3] === 'att'): ?><td class="live" title="Live: <?= $e['days'] ?> of <?= $e['norm'] ?> days"><?= $pv($e['rate']) ?>%</td>
            <?php else: ?><td class="in"><input class="ia" data-k="<?= $c[0] ?>" data-m="<?= $c[3] ?>" value="<?= $h($c[3] === 'dmg' ? $dv($v['act']) : $pv($v['act'])) ?>" inputmode="decimal"<?= $dis ?>></td><?php endif; ?>
            <?php if ($c[3] === 'gm'): ?><td class="in gm"><input class="ig" data-k="<?= $c[0] ?>" value="<?= $h($pv($v['gm'])) ?>" inputmode="decimal"<?= $dis ?>></td><?php endif; ?>
            <?php foreach ($c[2] as $t => $f): $tt = count($c[2]) === 1 ? $tiers[0] : $t; ?><td class="tier" data-k="<?= $c[0] ?>" data-m="<?= $c[3] ?>" data-t="<?= $tt ?>" data-r="<?= (float)($cfg[$f] ?? 0) ?>" data-col="<?= $mc[$k++] ?>" data-kind="i"<?= $v['fixed'] !== null ? ' data-fixed="' . $v['fixed'] . '"' : '' ?>></td><?php endforeach; ?>
          <?php endforeach; if ($firstLive === $nComp) echo '<td class="tot itot"></td>'; ?>
          <?php if ($mealCol !== null): ?><td class="live meal" data-col="<?= $mealCol ?>" data-kind="r"></td><?php endif; ?><td class="tot"></td><td class="c"><?= $e['days'] ?> / <?= $e['norm'] ?></td><td class="c"><?= $pv($e['npd']) ?></td><td class="n"><?= $nf($e['basic_adj']) ?></td><td class="tot gross"></td>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
      <?php if ($s['groups']): ?><tfoot><tr class="grand"><td class="sticky" style="text-align:left">TOTAL</td><td colspan="2"></td>
        <?php foreach ($s['comps'] as $i => $c): $mc = $plan[$i]['money'];
          if ($i === $firstLive) echo '<td class="ft-itot"></td>';
          if (!ci_is_pct($c)) { echo '<td class="ft" data-col="' . $mc[0] . '"></td>'; continue; } ?>
          <td></td><?php if ($c[3] === 'gm'): ?><td></td><?php endif; foreach ($mc as $m): ?><td class="ft" data-col="<?= $m ?>"></td><?php endforeach; endforeach; if ($firstLive === $nComp) echo '<td class="ft-itot"></td>'; ?>
        <?php if ($mealCol !== null): ?><td class="ft" data-col="<?= $mealCol ?>"></td><?php endif; ?><td class="ft-tot"></td><td></td><td></td><td class="ft-basic"></td><td class="ft-gross"></td></tr></tfoot><?php endif; ?>
    </table>
  </div>
  <?php $first = false; endforeach; ?>
  <div class="note">Employees and attendance are live for the payroll month, as they were in that month (resigned in the month = still shown, tag "left"; promoted after the month = the month's designation, tag "before move"); rates come from <b>Salary Configuration</b> of each designation (the blue rows). Type <b>Actual</b> achievement % (e.g. 90.6 or 0.906); <b>GM</b> is the amended % used for the tier — leave it empty to use Actual. The amount falls in the highest tier reached; <b>Stores Damage</b> is the damage % (e.g. 0.007) — the lower the better. Amount cells (Discretionary Support, ETE) left empty use the employee profile value shown in grey. <b>Live</b> (blue) columns: attendance, meal / travelling / mobile, SR fuel and MR D-Link — green = already saved in Incentive Entry. <b>Save</b> stores the entries in Incentive Entries, so the salary sheets use them (SR meal / travelling / mobile are saved too, from live attendance). <span style="color:var(--neg)">Red</span> = an amount already entered in Incentive Entry is used by the salary sheet.</div>
</div>
<div class="mdl" id="ciLinkM" onclick="if(event.target===this)ciLinksClose()">
  <div class="mbox">
    <div class="tb"><h1><i class="fa-solid fa-link"></i> Link columns to Payroll Headers</h1><button class="btn" onclick="ciLinksClose()"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="bar">
      <div class="tabs"><?php $first = true; foreach ($SECTIONS as $sk => $sec): ?><span class="tab ltab<?= $first ? ' on' : '' ?>" data-sec="<?= $sk ?>" onclick="ciLinkTab('<?= $sk ?>')"><?= $sk ?></span><?php $first = false; endforeach; ?></div>
      <span class="sp"></span>
      <button class="btn" onclick="ciLinkAuto()" title="Fill the empty ones with the suggested header"><i class="fa-solid fa-wand-magic-sparkles"></i> Auto-link</button>
      <a class="btn" href="payroll_headers.php" target="_blank"><i class="fa-solid fa-list-check"></i> Payroll Headers</a>
    </div>
    <div class="mscroll">
    <?php $first = true; foreach ($SECTIONS as $sk => $sec): ?>
      <table class="ltbl" data-sec="<?= $sk ?>"<?= $first ? '' : ' style="display:none"' ?>>
        <thead><tr><th style="width:34%">Column (<?= $h($sec[0]) ?>)</th><th>Payroll Header</th><th style="width:16%">Suggested</th></tr></thead>
        <tbody>
        <?php foreach (ci_link_cols($sec) as $key => $lbl): $cur = $LINKS[$sk][$key] ?? 0; $sug = $ci_suggest($sk, $key, $lbl); ?>
          <tr><td class="b"><?= $h($lbl) ?></td>
            <td><select class="lsel" data-sec="<?= $sk ?>" data-k="<?= $h($key) ?>" data-cur="<?= $cur ?>" data-sug="<?= $sug ?>">
              <option value="0">— not linked —</option>
              <?php $mine = []; $other = []; foreach ($PH as $id => $x) { if (array_intersect($sec[1], $x['cats'])) $mine[$id] = $x; else $other[$id] = $x; }
              foreach (['Ticked for ' . implode(' / ', $sec[1]) => $mine, 'Other additions' => $other] as $gl => $list): if (!$list) continue; ?>
                <optgroup label="<?= $h($gl) ?>"><?php foreach ($list as $id => $x): ?><option value="<?= $id ?>"<?= $id === $cur ? ' selected' : '' ?>><?= $h($x['name'] . ((int)$x['active'] ? '' : ' (inactive)')) ?></option><?php endforeach; ?></optgroup>
              <?php endforeach; ?>
            </select></td>
            <td style="color:#55585e"><?= $sug ? $h($PH[$sug]['name']) : '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php $first = false; endforeach; ?>
    <?php if (!$PH): ?><div style="padding:14px;color:var(--neg);font-weight:bold">No Payroll Headers (additions) yet — create them in Payroll Headers (Load Salary Components).</div><?php endif; ?>
    </div>
    <div class="bar" style="border-top:1px solid #a7a9ad;border-bottom:0"><span style="color:#55585e">The linked header's name is shown as the column title.</span><span class="sp"></span><button class="btn" onclick="ciLinksClose()">Cancel</button><button class="btn pri" onclick="ciLinkSave()"><i class="fa-solid fa-floppy-disk"></i> Save Links</button></div>
  </div>
</div>
<div class="toast" id="ciToast"></div>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
var CI_PERIOD = <?= (int)$pid ?>, ciDirty = false;
function num(v, m){ v = String(v == null ? '' : v).replace(/[%,\s]/g, ''); if (v === '' || isNaN(v)) return null; v = parseFloat(v);
  if (m === 'amt') return v;
  if (m === 'dmg') return (v > 0 && v < 0.001) ? v * 100 : v;   /* damage % typed as 0.007; a fraction 0.00007 is converted */
  if (v > 0 && v <= 2) v *= 100; return v; }
function fmt(v){ return Math.abs(v) < 0.005 ? '-' : v.toLocaleString('en-US', {minimumFractionDigits: v % 1 ? 2 : 0, maximumFractionDigits: 2}); }
function ciRow(tr){
  var rate = parseFloat(tr.dataset.rate) || 0, inc = 0, reimb = 0, sa = tr.closest('table').dataset.sa === '1';
  var keys = {}; tr.querySelectorAll('td.tier').forEach(function(td){ (keys[td.dataset.k] = keys[td.dataset.k] || []).push(td); });
  Object.keys(keys).forEach(function(k){
    var tds = keys[k], mode = tds[0].dataset.m, hit = null;
    var a = tr.querySelector('input.ia[data-k="' + k + '"]'), g = tr.querySelector('input.ig[data-k="' + k + '"]');
    var p = a ? num(g && g.value.trim() !== '' ? g.value : a.value, mode) : rate;
    tds.forEach(function(td){ td.classList.remove('hit', 'fx'); td.textContent = ''; td.dataset.v = 0; });
    var fixed = tds[0].dataset.fixed ? parseFloat(tds[0].dataset.fixed) : 0;
    if (fixed > 0 && a && (a.dataset.d || (g && g.dataset.d)) && sa) fixed = 0;   /* saving the % here replaces that amount */
    if (fixed > 0) { tds[0].classList.add('hit', 'fx'); tds[0].textContent = fmt(fixed); tds[0].dataset.v = fixed; tds[0].title = 'Amount entered in Incentive Entry'; inc += fixed; return; }
    if (p !== null && mode === 'dmg') { for (var x = 0; x < tds.length; x++) { if (p <= parseFloat(tds[x].dataset.t) + 1e-9 && (parseFloat(tds[x].dataset.r) || 0) > 0) { hit = tds[x]; break; } } }   /* lower damage is better */
    else if (p !== null) tds.forEach(function(td){ var t = parseFloat(td.dataset.t), r = parseFloat(td.dataset.r) || 0; if (p >= t - 1e-9 && r > 0) hit = td; });
    if (hit) { hit.classList.add('hit'); hit.textContent = fmt(parseFloat(hit.dataset.r)); hit.dataset.v = hit.dataset.r; inc += parseFloat(hit.dataset.r); }
  });
  tr.querySelectorAll('td.in.amt').forEach(function(td){ var i = td.querySelector('input'), v = i.value.trim() === '' ? parseFloat(i.dataset.fb) || 0 : num(i.value, 'amt') || 0; td.dataset.v = v; inc += v; });
  tr.querySelectorAll('td.lv').forEach(function(td){ reimb += parseFloat(td.dataset.v) || 0; });
  var mt = tr.querySelector('td.meal');
  if (mt) {
    var meal = tr.dataset.mealfix !== '' ? parseFloat(tr.dataset.mealfix) || 0 : (tr.dataset.mealmode === 'flat' ? parseFloat(tr.dataset.meal) || 0 : Math.round((parseFloat(tr.dataset.meal) || 0) * Math.min(rate, 100)) / 100);
    mt.textContent = fmt(meal); mt.dataset.v = meal; reimb += meal;
  }
  tr.dataset.inc = inc; tr.dataset.reimb = reimb;
  var it = tr.querySelector('td.itot'); if (it) it.textContent = fmt(inc);                 /* incentives only */
  tr.querySelector('td.tot:not(.itot):not(.gross)').textContent = fmt(inc + reimb);
  tr.querySelector('td.gross').textContent = fmt(inc + reimb + (parseFloat(tr.dataset.basic) || 0));
}
function ciTotals(){
  var sec = document.querySelector('.sec:not([style*="none"])'); if (!sec) return;
  var rows = [].filter.call(sec.querySelectorAll('tr.emp'), function(tr){ return tr.style.display !== 'none'; });
  var s = {n: rows.length, inc: 0, reimb: 0, basic: 0};
  sec.querySelectorAll('td.ft').forEach(function(f){ var v = 0; rows.forEach(function(tr){ var td = tr.querySelector('td[data-col="' + f.dataset.col + '"]'); v += parseFloat(td && td.dataset.v) || 0; }); f.textContent = fmt(v); });
  rows.forEach(function(tr){ s.inc += parseFloat(tr.dataset.inc) || 0; s.reimb += parseFloat(tr.dataset.reimb) || 0; s.basic += parseFloat(tr.dataset.basic) || 0; });
  var q = function(c){ return sec.querySelector(c); };
  if (q('.ft-itot')) q('.ft-itot').textContent = fmt(s.inc);
  if (q('.ft-tot')) { q('.ft-tot').textContent = fmt(s.inc + s.reimb); q('.ft-basic').textContent = fmt(s.basic); q('.ft-gross').textContent = fmt(s.inc + s.reimb + s.basic); }
  document.getElementById('kN').textContent = s.n; document.getElementById('kI').textContent = fmt(s.inc); document.getElementById('kM').textContent = fmt(s.reimb);
  document.getElementById('kB').textContent = fmt(s.basic); document.getElementById('kG').textContent = fmt(s.inc + s.reimb + s.basic);
}
function ciLinks(){ document.getElementById('ciLinkM').classList.add('on'); var on = document.querySelector('.tab.on:not(.ltab)'); if (on) ciLinkTab(on.dataset.sec); }
function ciLinksClose(){ document.getElementById('ciLinkM').classList.remove('on'); }
function ciLinkTab(k){
  document.querySelectorAll('.ltab').forEach(function(t){ t.classList.toggle('on', t.dataset.sec === k); });
  document.querySelectorAll('.ltbl').forEach(function(t){ t.style.display = t.dataset.sec === k ? '' : 'none'; });
}
function ciLinkMark(s){ var c = s.value !== s.dataset.cur; s.classList.toggle('chg', c); if (s.nextElementSibling && s.nextElementSibling.classList.contains('select2')) s.nextElementSibling.classList.toggle('chg', c); }
document.addEventListener('change', function(e){ if (e.target.classList && e.target.classList.contains('lsel')) ciLinkMark(e.target); });
function ciLinkAuto(){ var n = 0; document.querySelectorAll('.lsel').forEach(function(s){ if (s.value === '0' && s.dataset.sug !== '0') { s.value = s.dataset.sug; if (window.jQuery && jQuery(s).data('select2')) jQuery(s).trigger('change.select2'); ciLinkMark(s); n++; } }); toast(n ? n + ' column(s) linked — Save Links to keep.' : 'Nothing to auto-link.', true); }
function ciLinkSave(){
  var links = {};
  document.querySelectorAll('.lsel').forEach(function(s){ if (s.value !== s.dataset.cur) { (links[s.dataset.sec] = links[s.dataset.sec] || {})[s.dataset.k] = parseInt(s.value, 10) || 0; } });
  if (!Object.keys(links).length) { ciLinksClose(); return; }
  var fd = new FormData(); fd.append('action', 'link_save'); fd.append('links', JSON.stringify(links));
  fetch(location.pathname, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function(r){ return r.json(); })
    .then(function(j){ toast(j.msg, j.ok); if (j.ok) { if (ciDirty && !confirm('Header links saved. Reload now? Unsaved sheet entries will be lost.')) return; ciDirty = false; location.reload(); } })
    .catch(function(){ toast('Could not save the links.', false); });
}
function ciTab(k){
  document.querySelectorAll('.tab:not(.ltab)').forEach(function(t){ t.classList.toggle('on', t.dataset.sec === k); });
  document.querySelectorAll('.sec').forEach(function(s){ s.style.display = s.dataset.sec === k ? '' : 'none'; });
  ciFilter();
}
function ciFilter(){
  var q = (document.getElementById('ciQ').value || '').trim().toLowerCase();
  document.querySelectorAll('.sec tbody tr').forEach(function(tr){ if (tr.classList.contains('emp')) tr.style.display = !q || tr.dataset.s.indexOf(q) >= 0 ? '' : 'none'; else tr.style.display = q ? 'none' : ''; });
  ciTotals();
}
function ciLeave(){ return !ciDirty || confirm('You have unsaved changes. Leave without saving?'); }
document.addEventListener('input', function(e){ var i = e.target; if (!i.matches || !i.matches('td.in input')) return; i.dataset.d = 1; ciDirty = true; document.getElementById('ciDirty').classList.add('on'); ciRow(i.closest('tr')); ciTotals(); });
document.addEventListener('keydown', function(e){ /* Enter / arrows move like Excel */
  var i = e.target; if (!i.matches || !i.matches('td.in input')) return;
  var k = e.key; if (['Enter', 'ArrowDown', 'ArrowUp'].indexOf(k) < 0) return; e.preventDefault();
  var td = i.closest('td'), idx = [].indexOf.call(td.parentElement.children, td), tr = td.parentElement;
  do { tr = (k === 'ArrowUp' || (k === 'Enter' && e.shiftKey)) ? tr.previousElementSibling : tr.nextElementSibling; } while (tr && (!tr.classList.contains('emp') || tr.style.display === 'none'));
  if (tr && tr.children[idx]) { var n = tr.children[idx].querySelector('input'); if (n) { n.focus(); n.select(); } }
});
window.addEventListener('beforeunload', function(e){ if (ciDirty) { e.preventDefault(); e.returnValue = ''; } });
document.querySelectorAll('a.btn[title="Back"]').forEach(function(a){ a.addEventListener('click', function(e){ if (!ciLeave()) e.preventDefault(); else ciDirty = false; }); });
function toast(msg, ok){ var t = document.getElementById('ciToast'); t.textContent = msg; t.style.background = ok ? '#2e7d46' : '#b3261e'; t.style.display = 'block'; clearTimeout(t._h); t._h = setTimeout(function(){ t.style.display = 'none'; }, 3500); }
function ciSave(){
  var data = {};
  document.querySelectorAll('.sec').forEach(function(sec){
    var sk = sec.dataset.sec; data[sk] = {};
    sec.querySelectorAll('tr.emp').forEach(function(tr){
      var o = {};
      tr.querySelectorAll('input.ia').forEach(function(a){ var g = tr.querySelector('input.ig[data-k="' + a.dataset.k + '"]');
        if (a.dataset.d || (g && g.dataset.d)) o[a.dataset.k] = {act: a.value.trim(), gm: g ? g.value.trim() : ''}; });   /* only what was changed */
      tr.querySelectorAll('input.iamt').forEach(function(a){ if (a.dataset.d) o[a.dataset.k] = {act: a.value.trim()}; });
      if (Object.keys(o).length) data[sk][tr.dataset.id] = o;
    });
  });
  var b = document.getElementById('ciSave'); b.disabled = true;
  var fd = new FormData(); fd.append('action', 'save'); fd.append('period_id', CI_PERIOD); fd.append('data', JSON.stringify(data));
  fetch(location.pathname, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function(r){ return r.json(); })
    .then(function(j){ toast(j.msg || (j.ok ? 'Saved.' : 'Could not save.'), j.ok); if (j.ok) { ciDirty = false; document.getElementById('ciDirty').classList.remove('on'); document.querySelectorAll('td.in input').forEach(function(i){ delete i.dataset.d; }); } })
    .catch(function(){ toast('Could not save (network / session).', false); })
    .then(function(){ b.disabled = false; });
}
function ciExport(){
  var go = function(){
    var wb = XLSX.utils.book_new();
    document.querySelectorAll('.sec table').forEach(function(t){
      var c = t.cloneNode(true);
      c.querySelectorAll('td.in').forEach(function(td){ var i = td.querySelector('input'); td.textContent = i ? (i.value || (td.classList.contains('amt') ? i.placeholder : '')) : ''; });
      XLSX.utils.book_append_sheet(wb, XLSX.utils.table_to_sheet(c, {raw: false}), t.id.replace('ciTbl_', ''));
    });
    XLSX.writeFile(wb, 'Incentives_<?= $h(preg_replace('/\W+/', '_', $plabel)) ?>.xlsx');
  };
  if (window.XLSX) return go();
  var s = document.createElement('script'); s.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js'; s.onload = go; s.onerror = function(){ alert('Could not load the Excel library (internet needed).'); }; document.head.appendChild(s);
}
document.querySelectorAll('tr.emp').forEach(ciRow); ciTotals();
/* Select2 on the select boxes (plain selects stay when the library cannot load) */
if (window.jQuery && jQuery.fn.select2) {
  jQuery('select[name=period_id]').select2({width: '190px', minimumResultsForSearch: 8});
  jQuery('.lsel').select2({width: '100%', dropdownParent: jQuery('#ciLinkM')})
    .on('change', function(){ ciLinkMark(this); });
}
</script>
</body></html>
