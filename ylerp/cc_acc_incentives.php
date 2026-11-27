<?php
/**
 * cc_acc_incentives.php — CC & ACC Incentives sheet for a payroll month (like the "CC_ACC incentives" Excel).
 *   Employees      : live from the employees table (CC / SACC → Cash Collectors, ACC → Assistant Cash Collectors), grouped by designation.
 *   Rates          : from Salary Configuration (sr_salary_config) of each designation — shown on the designation row.
 *   Actual / GM    : EMPTY inputs, entered by hand for the month (GM = the amended % used for the tier; empty → Actual is used).
 *   Attendance     : live (attendance ÷ norm days, special holidays deducted); Meal = configured meal × attendance rate.
 *   Saving writes the achievement % to incentive_entries as __iach__<key> (the CC / ACC salary sheets read these), Actual & GM kept in notes.
 */
include 'config.php';
@include_once __DIR__ . '/auth.php';
if (function_exists('requireLogin')) requireLogin();
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
date_default_timezone_set('Asia/Colombo');

function ci_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function ci_has($conn, $t) { $r = ci_q($conn, "SHOW TABLES LIKE '$t'"); return $r && mysqli_num_rows($r) > 0; }
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

/* sections: code → [title, staff category codes, components]
   component: key (incentive_entries __iach__<key>), label, tiers [% => cfg column], mode: 'gm' (Actual + GM inputs) | 'act' (Actual input) | 'att' (live attendance) */
$SECTIONS = [
    'CC' => ['Cash Collectors', ['CC', 'SACC'], [
        ['ccfot', 'CCFOT', [90 => 'ccfot_90', 91 => 'ccfot_91', 92 => 'ccfot_92', 93 => 'ccfot_93', 94 => 'ccfot_94', 95 => 'ccfot_95'], 'gm'],
        ['credit_mgt', 'Credit Mgt', [85 => 'credit_mgt_85', 90 => 'credit_mgt_90', 95 => 'credit_mgt_95'], 'gm'],
        ['payee_chq', 'Payee Chq', [80 => 'payee_chq_80'], 'gm'],
        ['ssv_cc', 'Secondary Sales Value', [90 => 'ssv_90', 95 => 'ssv_95'], 'act'],
        ['attendance_cc', 'Attendance', [85 => 'attendance_85', 90 => 'attendance_90'], 'att'],
        ['locus_admin', 'Locus Admin', [100 => 'locus_admin'], 'act'],
    ]],
    'ACC' => ['Assistant Cash Collectors', ['ACC'], [
        ['ssv_acc', 'Secondary Sales Value', [90 => 'ssv_90', 95 => 'ssv_95'], 'act'],
        ['ccfot_acc', 'CCFOT', [90 => 'ccfot_90', 91 => 'ccfot_91', 92 => 'ccfot_92', 93 => 'ccfot_93', 94 => 'ccfot_94', 95 => 'ccfot_95'], 'gm'],
        ['punctuality_acc', 'Punctuality', [70 => 'punctuality_70', 80 => 'punctuality_80'], 'act'],
        ['attendance_acc', 'Attendance', [85 => 'attendance_85', 90 => 'attendance_90'], 'att'],
    ]],
];

/* ── payroll months ── */
$periods = []; $r = ci_q($conn, "SELECT id, year, month, status FROM payroll_periods ORDER BY year DESC, month DESC");
while ($r && ($x = mysqli_fetch_assoc($r))) $periods[] = $x;
$pid = (int)($_REQUEST['period_id'] ?? 0);
if (!$pid && $periods) { foreach ($periods as $p) if (strtolower((string)$p['status']) === 'open') { $pid = (int)$p['id']; break; } if (!$pid) $pid = (int)$periods[0]['id']; }
$period = null; foreach ($periods as $p) if ((int)$p['id'] === $pid) $period = $p;
$MON = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/* ── employees of a section (same rule as the salary sheets: staff category, or a designation of that category; active) ── */
function ci_employees($conn, $codes) {
    $in = implode(',', array_map(function ($c) { return "'" . $c . "'"; }, $codes));
    $cats = []; $r = ci_q($conn, "SELECT id FROM staff_categories WHERE category_code IN ($in)"); while ($r && ($x = mysqli_fetch_row($r))) $cats[] = (int)$x[0];
    if (!$cats) return [];
    $ci = implode(',', $cats); $w = ["e.staff_category_id IN ($ci)"];
    $ds = []; $r = ci_q($conn, "SELECT id FROM designations WHERE staff_category_id IN ($ci)"); while ($r && ($x = mysqli_fetch_row($r))) $ds[] = (int)$x[0];
    if ($ds) $w[] = 'e.designation_id IN (' . implode(',', $ds) . ')';
    $act = ci_q($conn, "SHOW COLUMNS FROM employees LIKE 'active'"); $act = $act && mysqli_num_rows($act) ? ' AND COALESCE(e.active,1) = 1' : '';
    $o = []; $r = ci_q($conn, "SELECT e.*, d.designation_name, d.designation_code, cfg.id AS cfg_id
        FROM employees e LEFT JOIN designations d ON d.id = e.designation_id LEFT JOIN sr_salary_config cfg ON cfg.designation_id = e.designation_id
        WHERE (" . implode(' OR ', $w) . ")$act AND COALESCE(e.status,'') NOT IN ('Resigned','Terminated') ORDER BY e.employee_full_name");
    while ($r && ($x = mysqli_fetch_assoc($r))) $o[(int)$x['id']] = $x;
    return $o;
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
$pct = function ($v) { $v = trim(str_replace('%', '', (string)$v)); if ($v === '' || !is_numeric($v)) return null; $v = (float)$v; if ($v > 0 && $v <= 1) $v *= 100; return round($v, 2); };

/* ── SAVE (AJAX) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('rbac_can') && !rbac_can('edit', 'cc_acc_incentives')) { echo json_encode(['ok' => false, 'msg' => 'Permission blocked for your role.']); exit; }
    if (!$period) { echo json_encode(['ok' => false, 'msg' => 'Choose a payroll month.']); exit; }
    if (strtolower((string)$period['status']) === 'closed') { echo json_encode(['ok' => false, 'msg' => 'This payroll month is closed.']); exit; }
    $data = json_decode((string)($_POST['data'] ?? ''), true); if (!is_array($data)) { echo json_encode(['ok' => false, 'msg' => 'Nothing to save.']); exit; }
    $uid = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
    $n = 0; $err = '';
    $run = function ($sql) use ($conn, &$err) { $ok = ci_q($conn, $sql); if (!$ok && $err === '') $err = mysqli_error($conn) ?: 'database error'; return $ok; };
    foreach ($SECTIONS as $sk => $sec) {
        $emps = ci_employees($conn, $sec[1]); $type = ci_type_id($conn, $sec[1]);
        $keys = []; foreach ($sec[2] as $c) if ($c[3] !== 'att') $keys[$c[0]] = 1;
        foreach (($data[$sk] ?? []) as $eid => $vals) {
            $eid = (int)$eid; if (!isset($emps[$eid]) || !is_array($vals)) continue;
            foreach ($vals as $key => $v) {
                if (!isset($keys[$key]) || !is_array($v)) continue;
                $act = $pct($v['act'] ?? ''); $gm = $pct($v['gm'] ?? '');
                $label = '__iach__' . $key; $use = $gm !== null ? $gm : $act;
                $ex = []; $q = ci_q($conn, "SELECT id FROM incentive_entries WHERE employee_id = $eid AND payroll_period_id = $pid AND component_label = '$label' ORDER BY id");
                while ($q && ($x = mysqli_fetch_row($q))) $ex[] = (int)$x[0];
                if ($use === null) { if ($ex) { $run("DELETE FROM incentive_entries WHERE id IN (" . implode(',', $ex) . ")"); $n++; } continue; }
                $notes = mysqli_real_escape_string($conn, json_encode(['act' => $act, 'gm' => $gm, 'src' => 'cc_acc_incentives']));
                if ($ex) {
                    $keep = array_shift($ex);
                    $run("UPDATE incentive_entries SET amount = '$use', notes = '$notes', updated_at = NOW() WHERE id = $keep");
                    if ($ex) $run("DELETE FROM incentive_entries WHERE id IN (" . implode(',', $ex) . ")");
                } else {
                    $run("INSERT INTO incentive_entries (incentive_type_id, employee_id, payroll_period_id, component_label, amount, notes" . ($uid ? ', entered_by' : '') . ")
                                 VALUES ($type, $eid, $pid, '$label', '$use', '$notes'" . ($uid ? ", $uid" : '') . ")");
                }
                $n++;
            }
        }
    }
    if ($err !== '') { echo json_encode(['ok' => false, 'msg' => 'Could not save: ' . $err]); exit; }
    echo json_encode(['ok' => true, 'msg' => "Saved ($n change" . ($n === 1 ? '' : 's') . ').']); exit;
}

/* ── build the sheet ── */
$out = [];
if ($period) {
    $from = sprintf('%04d-%02d-01', (int)$period['year'], (int)$period['month']); $to = date('Y-m-t', strtotime($from)); $mdays = (int)date('t', strtotime($from));
    $cfgs = []; $q = ci_q($conn, "SELECT * FROM sr_salary_config"); while ($q && ($x = mysqli_fetch_assoc($q))) $cfgs[(int)$x['designation_id']] = $x;
    $shd = []; $q = ci_q($conn, "SELECT * FROM special_holidays WHERE active = 1 AND date_from <= '$to' AND date_to >= '$from'");
    while ($q && ($sh = mysqli_fetch_assoc($q))) for ($d = max($sh['date_from'], $from); $d <= min($sh['date_to'], $to); $d = date('Y-m-d', strtotime($d . ' +1 day'))) $shd[$d][] = $sh;
    $att = []; $q = ci_q($conn, "SELECT employee_id, COUNT(*) FROM attendance WHERE att_date BETWEEN '$from' AND '$to' GROUP BY employee_id"); while ($q && ($x = mysqli_fetch_row($q))) $att[(int)$x[0]] = (int)$x[1];
    $leave = []; $q = ci_q($conn, "SELECT employee_id, SUM(days_count) FROM leave_applications WHERE status = 'Approved' AND (start_date BETWEEN '$from' AND '$to' OR end_date BETWEEN '$from' AND '$to' OR (start_date <= '$from' AND end_date >= '$to')) GROUP BY employee_id");
    while ($q && ($x = mysqli_fetch_row($q))) $leave[(int)$x[0]] = (float)$x[1];
    $inc = []; $q = ci_q($conn, "SELECT employee_id, component_label, amount, notes FROM incentive_entries WHERE payroll_period_id = $pid AND (component_label LIKE '\\_\\_iach\\_\\_%' OR component_label LIKE '\\_\\_incent\\_\\_%') ORDER BY id");
    while ($q && ($x = mysqli_fetch_assoc($q))) $inc[(int)$x['employee_id']][$x['component_label']] = $x;

    foreach ($SECTIONS as $sk => $sec) {
        $emps = ci_employees($conn, $sec[1]);
        /* tier columns: the tiers any designation of this section has a rate for (all when none is configured) */
        $comps = [];
        foreach ($sec[2] as $c) {
            $used = [];
            foreach ($c[2] as $t => $f) foreach ($emps as $e) if ((float)($cfgs[(int)$e['designation_id']][$f] ?? 0) > 0) { $used[$t] = $f; break; }
            $c[2] = $used ?: $c[2]; ksort($c[2]); $comps[] = $c;
        }
        $groups = [];
        foreach ($emps as $eid => $e) {
            $did = (int)($e['designation_id'] ?? 0); $cfg = $cfgs[$did] ?? [];
            $hol = 0;
            foreach ($shd as $list) foreach ($list as $sh) {
                $ok = ($sh['target_type'] ?? '') === 'all'; $ids = json_decode((string)($sh['target_ids'] ?? ''), true) ?: [];
                if (!$ok && $sh['target_type'] === 'employee') $ok = in_array($eid, $ids);
                if (!$ok && $sh['target_type'] === 'staff_category') $ok = in_array((int)($e['staff_category_id'] ?? 0), $ids);
                if (!$ok && $sh['target_type'] === 'designation') $ok = in_array($did, $ids);
                if ($ok) { $hol++; break; }
            }
            $norm = max(1, $mdays - $hol); $w = $att[$eid] ?? 0; $rate = round($w / $norm * 100, 2);
            $npd = max(0, $norm - $w - ($leave[$eid] ?? 0)); $basic = (float)($e['basic_salary'] ?? 0);
            $vals = [];
            foreach ($comps as $c) {
                $row = $inc[$eid]['__iach__' . $c[0]] ?? null; $n = $row ? (json_decode((string)$row['notes'], true) ?: []) : [];
                $act = $row ? ($n['act'] ?? (float)$row['amount']) : null; $gm = $n['gm'] ?? null;
                $fixed = (float)($inc[$eid]['__incent__' . $c[0]]['amount'] ?? 0);
                $vals[$c[0]] = ['act' => $act, 'gm' => $gm, 'fixed' => $fixed > 0 ? $fixed : null];
            }
            if (!isset($groups[$did])) $groups[$did] = ['name' => $e['designation_name'] ?: 'No designation', 'code' => $e['designation_code'] ?? '', 'cfg' => $cfg, 'emps' => []];
            $groups[$did]['emps'][] = ['id' => $eid, 'name' => $e['employee_full_name'] ?? '', 'code' => $e['employee_id'] ?? '', 'days' => $w, 'norm' => $norm, 'rate' => $rate,
                'npd' => $npd, 'basic' => $basic, 'basic_adj' => $basic > 0 ? round($basic - $basic / $norm * $npd, 2) : 0,
                'meal_cfg' => (float)(($cfg['meal_reimbursement'] ?? null) ?: ($e['reimbursement_meal'] ?? 0)), 'v' => $vals];
        }
        /* designations by rank: the lowest CCFOT 90% rate first (as in the Excel), then name */
        uasort($groups, function ($a, $b) { return ((float)($a['cfg']['ccfot_90'] ?? 0) <=> (float)($b['cfg']['ccfot_90'] ?? 0)) ?: strcasecmp($a['name'], $b['name']); });
        $out[$sk] = ['title' => $sec[0], 'comps' => $comps, 'groups' => array_values($groups)];
    }
}
$plabel = $period ? $MON[(int)$period['month']] . ' ' . $period['year'] : '';
$closed = $period && strtolower((string)$period['status']) === 'closed';
$back = (!empty($_SERVER['HTTP_REFERER']) && strpos((string)$_SERVER['HTTP_REFERER'], 'cc_acc_incentives.php') === false) ? $_SERVER['HTTP_REFERER'] : 'cc_salary.php';
$nf = function ($v) { return $v === null || $v === '' ? '' : (abs((float)$v) < 0.005 ? '-' : number_format((float)$v, (float)$v == (int)$v ? 0 : 2)); };
$pv = function ($v) { return $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); };
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>CC &amp; ACC Incentives<?= $plabel ? ' — ' . $h($plabel) : '' ?></title>
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
.btn:hover{background:linear-gradient(#9dbbe6,#5f8fcb 55%,#4877b8)} .btn.pri{background:linear-gradient(#5b87c6,#2E5A8E 55%,var(--ob-navy));border-color:#16365e} .btn:disabled{opacity:.5;cursor:default}
.bar{flex:none;display:flex;flex-wrap:wrap;align-items:center;gap:6px;padding:5px 8px;background:#f6f5f0;border-bottom:1px solid #a7a9ad}
.bar label{font-weight:bold;color:#55585e}
.bar select,.bar input[type=search]{font:11px var(--f);height:22px;border:1px solid #8e909a;border-radius:2px;padding:0 4px;background:#fff}
.tabs{display:flex;gap:4px} .tab{padding:3px 12px;border:1px solid var(--ob-line);border-radius:10px;background:#fff;color:var(--ob-navy);font-weight:bold;cursor:pointer}
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
th.g0{background:linear-gradient(#4fa36a,#2e7d46)} th.g1{background:linear-gradient(#9b7fd0,#6b4fa8)} th.gT{background:linear-gradient(#ffd966,#f0b400);color:#3a2c00}
td.n{text-align:right} td.c{text-align:center} td.b{font-weight:bold}
td.sticky,th.sticky{position:sticky;left:0;z-index:1;background:#fff} thead th.sticky{z-index:3;background:linear-gradient(#3a64a0,var(--ob-navy))}
tr.rate td{background:var(--ob-pale);color:var(--ob-navy);font-weight:bold} tr.rate td.sticky{background:var(--ob-pale)}
tr.rate td.lbl{color:#55585e;font-weight:normal;font-style:italic}
td.tier{color:#b9bac2;text-align:right} td.tier.hit{background:#e9f7ec;color:#1e6b34;font-weight:bold}
td.tot{background:#fff8d6;font-weight:bold;text-align:right} td.live{background:#f3f6fa;text-align:right}
td.in{padding:0;background:#fffef5} td.in input{width:54px;height:19px;border:0;background:transparent;font:11px var(--f);text-align:right;padding:0 4px;outline:none}
td.in input:focus{background:#fff3b0;box-shadow:inset 0 0 0 1px var(--ob-line)} td.in.gm{background:#fff4e5}
td.fx{background:#fdecea !important;color:var(--neg) !important}
tr.emp:hover td{background:#fff1a8} tr.emp:hover td.in{background:#fff1a8}
tr.grand td{background:#fff3c4;font-weight:bold;border-top:2px solid #d9b300;text-align:right}
.note{flex:none;padding:3px 10px 6px;color:#55585e;font-size:10.5px}
.toast{position:fixed;right:18px;bottom:18px;padding:8px 14px;border-radius:6px;color:#fff;font-weight:bold;display:none;z-index:9;box-shadow:0 4px 12px rgba(0,0,0,.3)}
@media (max-width:900px){.kpis{grid-template-columns:repeat(2,minmax(0,1fr))} body{padding:4px}}
@media print{body{background:#fff;padding:0}.win{border:0;box-shadow:none;height:auto}.bar,.tb .btn,.kpis,.note{display:none}.scroll{overflow:visible;border:0}thead th{position:static} td.in input{width:40px}}
</style></head><body>
<div class="win">
  <div class="tb">
    <a href="<?= $h($back) ?>" class="btn" title="Back"><i class="fa-solid fa-arrow-left"></i> Back</a>
    <h1><i class="fa-solid fa-coins"></i> CC &amp; ACC Incentives<?= $plabel ? ' — ' . $h($plabel) : '' ?></h1>
    <span class="dirty" id="ciDirty"><i class="fa-solid fa-circle-exclamation"></i> Unsaved changes</span>
    <button class="btn pri" id="ciSave" onclick="ciSave()"<?= (!$period || $closed) ? ' disabled' : '' ?>><i class="fa-solid fa-floppy-disk"></i> Save</button>
    <button class="btn" onclick="ciExport()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
    <button class="btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
  </div>
  <form class="bar" method="get">
    <label>Payroll Month</label>
    <select name="period_id" onchange="if(!ciLeave()){this.value='<?= $pid ?>';return;} this.form.submit()">
      <?php foreach ($periods as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === $pid ? ' selected' : '' ?>><?= $h($MON[(int)$p['month']] . ' ' . $p['year']) ?><?= $p['status'] ? ' (' . $h($p['status']) . ')' : '' ?></option><?php endforeach; ?>
    </select>
    <div class="tabs"><?php $first = true; foreach ($out as $sk => $s): $cnt = 0; foreach ($s['groups'] as $g) $cnt += count($g['emps']); ?><span class="tab<?= $first ? ' on' : '' ?>" data-sec="<?= $sk ?>" onclick="ciTab('<?= $sk ?>')"><?= $sk ?> — <?= $h($s['title']) ?> (<?= $cnt ?>)</span><?php $first = false; endforeach; ?></div>
    <input type="search" id="ciQ" placeholder="Search name / code…" oninput="ciFilter()" style="width:200px">
    <span class="sp"></span>
    <?php if ($closed): ?><span style="color:var(--neg);font-weight:bold"><i class="fa-solid fa-lock"></i> Closed month — view only</span><?php endif; ?>
  </form>
  <div class="kpis">
    <div class="kpi"><div class="k">Employees</div><div class="v" id="kN">0</div></div>
    <div class="kpi"><div class="k">Total Incentives</div><div class="v" id="kI">0</div></div>
    <div class="kpi"><div class="k">Meal</div><div class="v" id="kM">0</div></div>
    <div class="kpi"><div class="k">Basic (after No Pay)</div><div class="v" id="kB">0</div></div>
    <div class="kpi"><div class="k">Basic + Incentives</div><div class="v" id="kG">0</div></div>
  </div>
  <div class="title" id="ciTitle">INCENTIVES — <?= $h(strtoupper($plabel ?: 'NO PAYROLL MONTH')) ?></div>
  <?php if (!$period): ?><div class="scroll" style="padding:20px;text-align:center;color:#8e909a">No payroll month found.</div><?php endif; ?>
  <?php $first = true; foreach ($out as $sk => $s): ?>
  <div class="scroll sec" data-sec="<?= $sk ?>"<?= $first ? '' : ' style="display:none"' ?>>
    <table id="ciTbl_<?= $sk ?>">
      <thead>
        <tr><th rowspan="2" class="sticky">Name</th><th rowspan="2">Designation</th><th rowspan="2">Code</th>
          <?php foreach ($s['comps'] as $i => $c): $span = count($c[2]) + ($c[3] === 'gm' ? 2 : 1); ?><th colspan="<?= $span ?>" class="g<?= $i % 2 ?>"><?= $h($c[1]) ?></th><?php endforeach; ?>
          <th rowspan="2" class="g<?= count($s['comps']) % 2 ?>">Meal</th><th rowspan="2" class="gT">Total</th><th rowspan="2">Days Worked</th><th rowspan="2">No Pay Days</th><th rowspan="2">Basic</th><th rowspan="2" class="gT">Basic + Incentives</th></tr>
        <tr><?php foreach ($s['comps'] as $i => $c): ?><th class="g<?= $i % 2 ?>">Actual</th><?php if ($c[3] === 'gm'): ?><th class="g<?= $i % 2 ?>">GM</th><?php endif; foreach ($c[2] as $t => $f): ?><th class="g<?= $i % 2 ?>"><?= $t ?>%</th><?php endforeach; endforeach; ?></tr>
      </thead>
      <tbody>
      <?php if (!$s['groups']): ?><tr><td colspan="99" style="text-align:center;padding:20px;color:#8e909a">No employees in this category.</td></tr><?php endif; ?>
      <?php foreach ($s['groups'] as $g): $cfg = $g['cfg']; ?>
        <tr class="rate"><td class="sticky"><?= $h($g['name']) ?></td><td><?= $h($g['name']) ?></td><td><?= $h($g['code']) ?></td>
          <?php foreach ($s['comps'] as $c): ?><td class="lbl">rate</td><?php if ($c[3] === 'gm'): ?><td class="lbl"></td><?php endif; foreach ($c[2] as $t => $f): ?><td class="n"><?= $nf($cfg[$f] ?? null) ?></td><?php endforeach; endforeach; ?>
          <td class="n" title="Meal reimbursement × attendance rate"><?= $nf($cfg['meal_reimbursement'] ?? null) ?></td><td></td><td></td><td></td><td></td><td></td></tr>
        <?php foreach ($g['emps'] as $e): ?>
        <tr class="emp" data-id="<?= $e['id'] ?>" data-s="<?= $h(mb_strtolower($e['name'] . ' ' . $e['code'] . ' ' . $g['name'])) ?>" data-rate="<?= $e['rate'] ?>" data-meal="<?= $e['meal_cfg'] ?>" data-basic="<?= $e['basic_adj'] ?>">
          <td class="sticky b"><?= $h($e['name']) ?></td><td><?= $h($g['name']) ?></td><td><?= $h($e['code']) ?></td>
          <?php foreach ($s['comps'] as $c): $v = $e['v'][$c[0]]; $rates = []; foreach ($c[2] as $t => $f) $rates[$t] = (float)($cfg[$f] ?? 0); ?>
            <?php if ($c[3] === 'att'): ?><td class="live" title="Live: <?= $e['days'] ?> of <?= $e['norm'] ?> days"><?= $pv($e['rate']) ?>%</td>
            <?php else: ?><td class="in"><input class="ia" data-k="<?= $c[0] ?>" value="<?= $h($pv($v['act'])) ?>" placeholder="" inputmode="decimal"<?= $closed ? ' disabled' : '' ?>></td><?php endif; ?>
            <?php if ($c[3] === 'gm'): ?><td class="in gm"><input class="ig" data-k="<?= $c[0] ?>" value="<?= $h($pv($v['gm'])) ?>" inputmode="decimal"<?= $closed ? ' disabled' : '' ?>></td><?php endif; ?>
            <?php foreach ($c[2] as $t => $f): ?><td class="tier" data-k="<?= $c[0] ?>" data-t="<?= $t ?>" data-r="<?= $rates[$t] ?>"<?= $v['fixed'] !== null ? ' data-fixed="' . $v['fixed'] . '"' : '' ?>></td><?php endforeach; ?>
          <?php endforeach; ?>
          <td class="live meal"></td><td class="tot"></td><td class="c"><?= $e['days'] ?> / <?= $e['norm'] ?></td><td class="c"><?= $pv($e['npd']) ?></td><td class="n"><?= $nf($e['basic_adj']) ?></td><td class="tot gross"></td>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
      <?php if ($s['groups']): ?><tfoot><tr class="grand"><td class="sticky" style="text-align:left">TOTAL</td><td colspan="2"></td>
        <?php foreach ($s['comps'] as $c): ?><td></td><?php if ($c[3] === 'gm'): ?><td></td><?php endif; foreach ($c[2] as $t => $f): ?><td class="ft" data-k="<?= $c[0] ?>" data-t="<?= $t ?>"></td><?php endforeach; endforeach; ?>
        <td class="ft-meal"></td><td class="ft-tot"></td><td></td><td></td><td class="ft-basic"></td><td class="ft-gross"></td></tr></tfoot><?php endif; ?>
    </table>
  </div>
  <?php $first = false; endforeach; ?>
  <div class="note">Employees and attendance are live for the payroll month; rates come from <b>Salary Configuration</b> of each designation (the blue rows). Type <b>Actual</b> achievement % (e.g. 90.6 or 0.906); <b>GM</b> is the amended % used for the tier — leave it empty to use Actual. The amount falls in the highest tier reached. Attendance is live (days worked ÷ norm days); Meal = configured meal × attendance rate. <b>Save</b> stores the % in Incentive Entries, so the CC / ACC salary sheets use it. <span style="color:var(--neg)">Red</span> = a fixed amount already entered in Incentive Entry overrides the tier.</div>
</div>
<div class="toast" id="ciToast"></div>
<script>
var CI_PERIOD = <?= (int)$pid ?>, ciDirty = false;
function num(v){ v = String(v == null ? '' : v).replace(/[%,\s]/g, ''); if (v === '' || isNaN(v)) return null; v = parseFloat(v); if (v > 0 && v <= 1) v *= 100; return v; }
function fmt(v){ return Math.abs(v) < 0.005 ? '-' : v.toLocaleString('en-US', {minimumFractionDigits: v % 1 ? 2 : 0, maximumFractionDigits: 2}); }
function ciRow(tr){
  var rate = parseFloat(tr.dataset.rate) || 0, total = 0, earned = {};
  var keys = {}; tr.querySelectorAll('td.tier').forEach(function(td){ (keys[td.dataset.k] = keys[td.dataset.k] || []).push(td); });
  Object.keys(keys).forEach(function(k){
    var a = tr.querySelector('input.ia[data-k="' + k + '"]'), g = tr.querySelector('input.ig[data-k="' + k + '"]');
    var p = a ? num(g && g.value.trim() !== '' ? g.value : a.value) : rate;
    var tds = keys[k], hit = null;
    tds.forEach(function(td){ td.classList.remove('hit', 'fx'); td.textContent = ''; td.dataset.v = 0; });
    var fixed = tds[0].dataset.fixed ? parseFloat(tds[0].dataset.fixed) : 0;
    if (fixed > 0) { tds[0].classList.add('hit', 'fx'); tds[0].textContent = fmt(fixed); tds[0].dataset.v = fixed; tds[0].title = 'Fixed amount from Incentive Entry'; total += fixed; return; }
    if (p !== null) tds.forEach(function(td){ var t = parseFloat(td.dataset.t), r = parseFloat(td.dataset.r) || 0; if (p >= t - 1e-9 && r > 0) hit = td; });
    if (hit) { hit.classList.add('hit'); hit.textContent = fmt(parseFloat(hit.dataset.r)); hit.dataset.v = hit.dataset.r; total += parseFloat(hit.dataset.r); }
  });
  var meal = Math.round((parseFloat(tr.dataset.meal) || 0) * rate) / 100;
  tr.querySelector('td.meal').textContent = fmt(meal); tr.querySelector('td.meal').dataset.v = meal;
  total += meal; tr.dataset.total = total; tr.dataset.mealv = meal;
  tr.querySelector('td.tot').textContent = fmt(total);
  tr.querySelector('td.gross').textContent = fmt(total + (parseFloat(tr.dataset.basic) || 0));
}
function ciTotals(){
  var sec = document.querySelector('.sec:not([style*="none"])'); if (!sec) return;
  var rows = [].filter.call(sec.querySelectorAll('tr.emp'), function(tr){ return tr.style.display !== 'none'; });
  var s = {n: rows.length, tot: 0, meal: 0, basic: 0};
  sec.querySelectorAll('td.ft').forEach(function(f){ var v = 0; rows.forEach(function(tr){ var td = tr.querySelector('td.tier[data-k="' + f.dataset.k + '"][data-t="' + f.dataset.t + '"]'); v += parseFloat(td && td.dataset.v) || 0; }); f.textContent = fmt(v); });
  rows.forEach(function(tr){ s.tot += parseFloat(tr.dataset.total) || 0; s.meal += parseFloat(tr.dataset.mealv) || 0; s.basic += parseFloat(tr.dataset.basic) || 0; });
  var q = function(c){ return sec.querySelector(c); };
  if (q('.ft-meal')) { q('.ft-meal').textContent = fmt(s.meal); q('.ft-tot').textContent = fmt(s.tot); q('.ft-basic').textContent = fmt(s.basic); q('.ft-gross').textContent = fmt(s.tot + s.basic); }
  document.getElementById('kN').textContent = s.n; document.getElementById('kI').textContent = fmt(s.tot - s.meal); document.getElementById('kM').textContent = fmt(s.meal);
  document.getElementById('kB').textContent = fmt(s.basic); document.getElementById('kG').textContent = fmt(s.tot + s.basic);
}
function ciTab(k){
  document.querySelectorAll('.tab').forEach(function(t){ t.classList.toggle('on', t.dataset.sec === k); });
  document.querySelectorAll('.sec').forEach(function(s){ s.style.display = s.dataset.sec === k ? '' : 'none'; });
  ciFilter();
}
function ciFilter(){
  var q = (document.getElementById('ciQ').value || '').trim().toLowerCase();
  document.querySelectorAll('.sec tbody tr').forEach(function(tr){ if (tr.classList.contains('emp')) tr.style.display = !q || tr.dataset.s.indexOf(q) >= 0 ? '' : 'none'; else tr.style.display = q ? 'none' : ''; });
  ciTotals();
}
function ciLeave(){ return !ciDirty || confirm('You have unsaved changes. Leave without saving?'); }
document.addEventListener('input', function(e){ var i = e.target; if (!i.matches || !i.matches('td.in input')) return; ciDirty = true; document.getElementById('ciDirty').classList.add('on'); ciRow(i.closest('tr')); ciTotals(); });
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
      tr.querySelectorAll('input.ia').forEach(function(a){ var g = tr.querySelector('input.ig[data-k="' + a.dataset.k + '"]'); o[a.dataset.k] = {act: a.value.trim(), gm: g ? g.value.trim() : ''}; });
      data[sk][tr.dataset.id] = o;
    });
  });
  var b = document.getElementById('ciSave'); b.disabled = true;
  var fd = new FormData(); fd.append('action', 'save'); fd.append('period_id', CI_PERIOD); fd.append('data', JSON.stringify(data));
  fetch(location.pathname, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function(r){ return r.json(); })
    .then(function(j){ toast(j.msg || (j.ok ? 'Saved.' : 'Could not save.'), j.ok); if (j.ok) { ciDirty = false; document.getElementById('ciDirty').classList.remove('on'); } })
    .catch(function(){ toast('Could not save (network / session).', false); })
    .then(function(){ b.disabled = false; });
}
function ciExport(){
  var go = function(){
    var wb = XLSX.utils.book_new();
    document.querySelectorAll('.sec table').forEach(function(t){
      var c = t.cloneNode(true);
      c.querySelectorAll('td.in').forEach(function(td){ var i = td.querySelector('input'); td.textContent = i ? i.value : ''; });
      XLSX.utils.book_append_sheet(wb, XLSX.utils.table_to_sheet(c, {raw: false}), t.id.replace('ciTbl_', ''));
    });
    XLSX.writeFile(wb, 'CC_ACC_Incentives_<?= $h(preg_replace('/\W+/', '_', $plabel)) ?>.xlsx');
  };
  if (window.XLSX) return go();
  var s = document.createElement('script'); s.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js'; s.onload = go; s.onerror = function(){ alert('Could not load the Excel library (internet needed).'); }; document.head.appendChild(s);
}
document.querySelectorAll('tr.emp').forEach(ciRow); ciTotals();
</script>
</body></html>
