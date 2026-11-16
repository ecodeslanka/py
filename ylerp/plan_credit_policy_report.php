<?php
/**
 * plan_credit_policy_report.php — Analysis → Credit Policy (by channel) — the "Credit Policy" tab of the monthly credit meeting, from the system.
 *   per sub channel (secondary channel) grouped into channel groups: outlet count, AVG sale (average monthly sales in the chosen period),
 *   AVG total of the group, business contribution, ALLOCATED CREDIT EXPOSURE (= total AVG sale ÷ 30 × exposure days, shared by the group's
 *   allocation %), credit contribution and the maximum credit period.  Plus, from the live data: credit outlets, open credit, overdue,
 *   utilisation of the allocation, average policy days, outlets above the maximum period, and open-credit ageing (7 / 14 / 21 / 30 / >30 / >4 months).
 *   Period filter: last 3 / 6 / 12 cycles, year, half-year, quarter or one sales month (cycles day 26 → 25), from the data in the system.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
@set_time_limit(0);
require_once __DIR__ . '/plan_lib.php'; require_once __DIR__ . '/plan_model_lib.php'; @include_once __DIR__ . '/report_extras_lib.php';
$e = fn($v) => mysqli_real_escape_string($conn, (string)$v);
pl_q($conn, "CREATE TABLE IF NOT EXISTS plan_credit_policy_cfg (sub_channel VARCHAR(150) PRIMARY KEY, grp VARCHAR(60) NOT NULL, max_period VARCHAR(20), sort_no INT DEFAULT 0, updated_by VARCHAR(100), updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
pl_q($conn, "CREATE TABLE IF NOT EXISTS plan_credit_group_cfg (grp VARCHAR(60) PRIMARY KEY, alloc_pct DECIMAL(8,4) NULL, sort_no INT DEFAULT 0, updated_by VARCHAR(100), updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
/* defaults = the credit-meeting workbook (Credit Policy tab) */
$DEF_SUB = ['Book shops and Hardware' => ['OOH', '14-21'], 'Estate outlets' => ['OOH', '14-30'], 'Institutions Forces' => ['OOH', '14-30'], 'Out of home Others' => ['OOH', '14-30'], 'Textile Standard' => ['OOH', '14-30'],
    'Cosmetic Standard Large' => ['DC', '21-30'], 'Cosmetic Standard Small' => ['DC', '21-30'], 'Pharmacy General Small' => ['DC', '21-30'], 'ETUP LARGE' => ['ETUP', '07-14'], 'ETUP LITE' => ['ETUP', '07-14'], 'ETUP MEDIUM' => ['ETUP', '07-14'],
    'ETUP SMALL' => ['ETUP', '07-14'], 'Family Grocery Large' => ['FG', '07-21'], 'Family Grocery Small' => ['FG', '07-21'], 'INDEPENDENT SUPER MARKER LARGE' => ['D-Mart', '21-30'], 'INDEPENDENT SUPER MARKER MEDIUM' => ['Self Service', '21-30'],
    'INDEPENDENT SUPER MARKER SMALL' => ['Self Service', '21-30'], 'Saubhagya Entrepreneurs' => ['Saubhagya', '7'], 'Wholesale Dominant' => ['WS', '21-30'], 'Wholesale Hybrid' => ['WS', '21-30']];
$DEF_GRP = ['OOH' => 2, 'DC' => 9, 'ETUP' => 15, 'FG' => 25, 'D-Mart' => 20, 'Self Service' => 15, 'Saubhagya' => null, 'WS' => 13.79, 'Other' => null];
$r = pl_q($conn, "SELECT COUNT(*) FROM plan_credit_group_cfg"); if ($r && !(int)mysqli_fetch_row($r)[0]) {
    $i = 0; foreach ($DEF_GRP as $g => $p) pl_q($conn, "INSERT INTO plan_credit_group_cfg (grp, alloc_pct, sort_no) VALUES ('{$e($g)}', " . ($p === null ? 'NULL' : $p) . ", " . ($i++) . ")");
    pl_q($conn, "INSERT INTO plan_credit_group_cfg (grp, alloc_pct, sort_no) VALUES ('__days__', 16, 999)");
    $i = 0; foreach ($DEF_SUB as $s => [$g, $mp]) pl_q($conn, "INSERT IGNORE INTO plan_credit_policy_cfg (sub_channel, grp, max_period, sort_no) VALUES ('{$e($s)}', '{$e($g)}', '{$e($mp)}', " . ($i++) . ")");
}
/* ── save settings ── */
if (($_POST['action'] ?? '') === 'save_cfg') {
    if (!pl_can_edit()) pl_json(['ok' => false, 'msg' => 'You do not have the Edit right on this page.']);
    $d = json_decode($_POST['cfg'] ?? '{}', true); $u = $e(pl_user());
    if (isset($d['days']) && is_numeric($d['days'])) pl_q($conn, "REPLACE INTO plan_credit_group_cfg (grp, alloc_pct, sort_no, updated_by) VALUES ('__days__', " . max(1, min(120, (float)$d['days'])) . ", 999, '$u')");
    foreach ((array)($d['groups'] ?? []) as $i => $g) { $n = trim((string)($g['grp'] ?? '')); if ($n === '' || $n === '__days__') continue; $p = trim((string)($g['pct'] ?? ''));
        pl_q($conn, "REPLACE INTO plan_credit_group_cfg (grp, alloc_pct, sort_no, updated_by) VALUES ('{$e($n)}', " . ($p === '' || !is_numeric($p) ? 'NULL' : round((float)$p, 4)) . ", " . (int)$i . ", '$u')"); }
    foreach ((array)($d['subs'] ?? []) as $i => $s) { $n = trim((string)($s['sub'] ?? '')); if ($n === '') continue;
        pl_q($conn, "REPLACE INTO plan_credit_policy_cfg (sub_channel, grp, max_period, sort_no, updated_by) VALUES ('{$e($n)}', '{$e(trim((string)($s['grp'] ?? 'Other')) ?: 'Other')}', '{$e(trim((string)($s['mp'] ?? '')))}', " . (int)$i . ", '$u')"); }
    pl_json(['ok' => true, 'msg' => 'Credit policy settings saved.']);
}
$GRP = []; $DAYS = 16; $r = pl_q($conn, "SELECT grp, alloc_pct FROM plan_credit_group_cfg ORDER BY sort_no, grp"); while ($r && ($x = mysqli_fetch_assoc($r))) { if ($x['grp'] === '__days__') $DAYS = (float)$x['alloc_pct']; else $GRP[$x['grp']] = $x['alloc_pct'] === null ? null : (float)$x['alloc_pct']; }
$SUB = []; $r = pl_q($conn, "SELECT sub_channel, grp, max_period FROM plan_credit_policy_cfg ORDER BY sort_no, sub_channel"); while ($r && ($x = mysqli_fetch_assoc($r))) $SUB[$x['sub_channel']] = [$x['grp'], $x['max_period']];
if (!$GRP) { $GRP = $DEF_GRP; foreach ($DEF_SUB as $s => $v) $SUB[$s] = $v; }
if (!array_key_exists('Other', $GRP)) $GRP['Other'] = null;

/* ── periods available in the data ── */
$S = hs_settings($conn); $sd = (int)$S['start_day']; $cy = fn($d) => $sd <= 1 ? date('Y-m', strtotime($d)) : ((int)date('j', strtotime($d)) >= $sd ? date('Y-m', strtotime(date('Y-m-01', strtotime($d)) . ' +1 month')) : date('Y-m', strtotime($d)));
$minD = null; $maxD = null;
foreach (["SELECT MIN(bill_date), MAX(bill_date) FROM analysis_sec_invoices", "SELECT MIN(COALESCE(bill_date, delivery_date)), MAX(COALESCE(bill_date, delivery_date)) FROM secondary_invoice_import_details WHERE t_code <> ''"] as $q) {
    $r = pl_q($conn, $q); if ($r && ($x = mysqli_fetch_row($r))) { if ($x[0] && (!$minD || $x[0] < $minD)) $minD = $x[0]; if ($x[1] && (!$maxD || $x[1] > $maxD)) $maxD = $x[1]; } }
$cycles = []; if ($minD && $maxD) { $c = $cy($minD); $last = $cy($maxD); $g = 0; while ($c <= $last && $g++ < 120) { $cycles[] = $c; $c = hs_add($c, 1); } }
$openCy = $maxD ? $cy($maxD) : null; $openComplete = $maxD && $maxD >= hs_range($openCy, $sd)[1];
$complete = array_values(array_filter($cycles, fn($c) => $c !== $openCy || $openComplete));
$years = array_values(array_unique(array_map(fn($c) => substr($c, 0, 4), $cycles))); rsort($years);
/* ── chosen period ── */
$pt = $_GET['pt'] ?? 'last3'; $py = $_GET['py'] ?? ($years[0] ?? date('Y')); $ph = $_GET['ph'] ?? '2'; $pq = $_GET['pq'] ?? '4'; $pm = $_GET['pm'] ?? (end($complete) ?: '');
if ($pt === 'year') $sel = array_values(array_filter($cycles, fn($c) => substr($c, 0, 4) === $py));
elseif ($pt === 'half') $sel = array_values(array_filter($cycles, fn($c) => substr($c, 0, 4) === $py && ((int)substr($c, 5) <= 6) === ($ph === '1')));
elseif ($pt === 'quarter') $sel = array_values(array_filter($cycles, fn($c) => substr($c, 0, 4) === $py && (int)ceil((int)substr($c, 5) / 3) === (int)$pq));
elseif ($pt === 'month') $sel = in_array($pm, $cycles, true) ? [$pm] : [];
else { $n = ['last3' => 3, 'last6' => 6, 'last12' => 12][$pt] ?? 3; $sel = array_slice($complete, -$n); }
$from = $sel ? hs_range($sel[0], $sd)[0] : null; $to = $sel ? min(hs_range(end($sel), $sd)[1], $maxD) : null;
$nCyc = 0.0; foreach ($sel as $c) { $rg = hs_range($c, $sd); $len = (strtotime($rg[1]) - strtotime($rg[0])) / 86400 + 1; $nCyc += $maxD < $rg[1] ? max(0, (strtotime($maxD) - strtotime($rg[0])) / 86400 + 1) / $len : 1; }
$plabel = !$sel ? 'no data' : (count($sel) === 1 ? hs_label($sel[0]) : hs_label($sel[0]) . ' – ' . hs_label(end($sel))) . ' (' . $from . ' → ' . $to . ($nCyc < count($sel) ? ', open cycle to date' : '') . ')';

/* ── sales per outlet in the period (same sources and value rule as Historical Sales Trends) ── */
$fin = $S['basis'] === 'billvalue' ? '(bill_value + total_discount)' : '(total_discount + final_bill_amount)'; $sales = []; $co = $S['cutover'];
if ($from && $from < $co) { $hi = min($to, date('Y-m-d', strtotime($co . ' -1 day')));
    $r = pl_q($conn, "SELECT UPPER(TRIM(outlet_hul_code)) AS c, SUM($fin) AS v FROM analysis_sec_invoices WHERE bill_date BETWEEN '{$e($from)}' AND '{$e($hi)}' AND outlet_hul_code <> '' GROUP BY c"); while ($r && ($x = mysqli_fetch_assoc($r))) $sales[$x['c']] = ($sales[$x['c']] ?? 0) + (float)$x['v']; }
if ($to && $to >= $co) { $lo = max($from, $co); $dc = "COALESCE(bill_date, delivery_date)";
    $r = pl_q($conn, "SELECT UPPER(TRIM(t_code)) AS c, SUM(v) AS v FROM (SELECT t_code, MAX($fin) AS v FROM secondary_invoice_import_details WHERE t_code <> '' AND $dc BETWEEN '{$e($lo)}' AND '{$e($to)}' GROUP BY t_code, bill_no, $dc) z GROUP BY c"); while ($r && ($x = mysqli_fetch_assoc($r))) $sales[$x['c']] = ($sales[$x['c']] ?? 0) + (float)$x['v']; }
/* customers: channel, pay mode, policy days */
$cust = []; $r = pl_q($conn, "SELECT UPPER(TRIM(t_code)) AS c, shop_name AS nm, COALESCE(channel,'') AS ch, LOWER(TRIM(COALESCE(payment_mode,''))) AS pm, COALESCE(credit_days,0) AS d FROM customers"); while ($r && ($x = mysqli_fetch_assoc($r))) $cust[$x['c']] = $x;
$chOf = fn($c) => isset($cust[$c]) && $cust[$c]['ch'] !== '' ? $cust[$c]['ch'] : 'Unclassified';
/* live credit: open bills with age (days since delivery) from the system */
$ages = []; $openC = []; $ovd = []; $OB = [];   // OB = per outlet: open, overdue, age buckets, bills, oldest age
if (function_exists('rx_inv_parts')) { $P = rx_inv_parts($conn, date('Y-m-d'));
    $q = pl_q($conn, "SELECT fsd.t_code, fs.delivery_date, {$P['balance']} AS bal FROM field_summary_details fsd INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id {$P['joins']} WHERE fsd.updated = 1 AND fs.delivery_date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH) AND fs.delivery_date <= CURDATE()");
    while ($q && ($x = mysqli_fetch_assoc($q))) { $b = (float)$x['bal']; if ($b <= 0.005) continue; $c = strtoupper(trim($x['t_code'])); $age = (int)floor((time() - strtotime($x['delivery_date'])) / 86400);
        $k = $age <= 7 ? 0 : ($age <= 14 ? 1 : ($age <= 21 ? 2 : ($age <= 30 ? 3 : ($age <= 120 ? 4 : 5)))); $ch = $chOf($c); $ages[$ch][$k] = ($ages[$ch][$k] ?? 0) + $b; $o = &$OB[$c]; if (!$o) $o = ['open' => 0, 'ovd' => 0, 'ages' => array_fill(0, 6, 0), 'bills' => 0, 'old' => 0]; $o['open'] += $b; $o['ages'][$k] += $b; $o['bills']++; if ($age > $o['old']) $o['old'] = $age; if ($age > (int)($cust[$c]['d'] ?? 0)) $o['ovd'] += $b; unset($o); $openC[$ch] = ($openC[$ch] ?? 0) + $b;
        $d = (int)($cust[$c]['d'] ?? 0); if ($age > $d) $ovd[$ch] = ($ovd[$ch] ?? 0) + $b; } }
/* policy facts per sub channel (customers) */
$pol = []; foreach ($cust as $c => $x) { $ch = $x['ch'] !== '' ? $x['ch'] : 'Unclassified'; $p = &$pol[$ch]; if (!$p) $p = ['n' => 0, 'credit' => 0, 'days' => 0, 'over' => 0];
    $p['n']++; if (in_array($x['pm'], ['credit', 'cheque'], true)) { $p['credit']++; $p['days'] += (int)$x['d'];
        $mp = $SUB[$ch][1] ?? ''; $mx = preg_match('/(\d+)\s*$/', (string)$mp, $mm) ? (int)$mm[1] : null; if ($mx !== null && (int)$x['d'] > $mx) $p['over']++; } unset($p); }
/* violations per channel from the planning model (if built) */
$viol = []; $Mx = pm_get($conn, false); $MO = []; foreach ($Mx['rows'] as $x) { $MO[$x['c']] = ['n' => $x['n'], 'rep' => $x['rep'], 'b' => $x['b'], 'viol' => (int)$x['viol'], 'risk' => $x['risk'], 'band' => $x['band'], 'cs' => $x['cs'], 'cl' => $x['cl']]; $ch = strpos($x['ch'], 'Unclassified') === 0 ? 'Unclassified' : $x['ch']; $viol[$ch] = ($viol[$ch] ?? 0) + (int)$x['viol']; }

/* ── build the table ── */
$rows = []; $subs = array_unique(array_merge(array_keys($SUB), array_map($chOf, array_keys($sales)), array_keys($openC)));
foreach ($subs as $s) { $tot = 0; $n = 0; foreach ($sales as $c => $v) if ($chOf($c) === $s && $v > 0) { $tot += $v; $n++; }
    $g = $SUB[$s][0] ?? 'Other'; if (!array_key_exists($g, $GRP)) $GRP[$g] = null;
    if ($n === 0 && empty($openC[$s]) && !isset($SUB[$s])) continue;
    $rows[] = ['grp' => $g, 'sub' => $s, 'n' => $n, 'avg' => $nCyc > 0 ? $tot / $nCyc : 0, 'mp' => $SUB[$s][1] ?? '', 'credit' => $pol[$s]['credit'] ?? 0, 'pdays' => !empty($pol[$s]['credit']) ? $pol[$s]['days'] / $pol[$s]['credit'] : null,
        'over' => $pol[$s]['over'] ?? 0, 'open' => $openC[$s] ?? 0, 'ovd' => $ovd[$s] ?? 0, 'ages' => $ages[$s] ?? [], 'viol' => $viol[$s] ?? 0]; }
$order = array_flip(array_keys($GRP)); $subOrder = array_flip(array_keys($SUB));
usort($rows, fn($a, $b) => [$order[$a['grp']] ?? 99, $subOrder[$a['sub']] ?? 999, $a['sub']] <=> [$order[$b['grp']] ?? 99, $subOrder[$b['sub']] ?? 999, $b['sub']]);
$totAvg = array_sum(array_column($rows, 'avg')); $budget = $totAvg / 30 * $DAYS; $G = [];
foreach ($rows as $x) { $g = &$G[$x['grp']]; if (!$g) $g = ['n' => 0, 'avg' => 0, 'open' => 0, 'ovd' => 0, 'credit' => 0, 'over' => 0, 'viol' => 0, 'ages' => array_fill(0, 6, 0), 'subs' => 0];
    $g['subs']++; foreach (['n', 'avg', 'open', 'ovd', 'credit', 'over', 'viol'] as $k) $g[$k] += $x[$k]; foreach ($x['ages'] as $i => $v) $g['ages'][$i] += $v; unset($g); }
foreach ($G as $k => &$g) { $g['bc'] = $totAvg > 0 ? $g['avg'] / $totAvg : 0; $g['pct'] = $GRP[$k] ?? null; $g['alloc'] = $budget * ($g['pct'] === null ? $g['bc'] : $g['pct'] / 100); $g['util'] = $g['alloc'] > 0 ? $g['open'] / $g['alloc'] : null; } unset($g);
$allocT = array_sum(array_column($G, 'alloc')); foreach ($G as &$g) $g['cc'] = $allocT > 0 ? $g['alloc'] / $allocT : 0; unset($g);
$T = ['n' => array_sum(array_column($rows, 'n')), 'open' => array_sum(array_column($rows, 'open')), 'ovd' => array_sum(array_column($rows, 'ovd')), 'credit' => array_sum(array_column($rows, 'credit')), 'over' => array_sum(array_column($rows, 'over')), 'viol' => array_sum(array_column($rows, 'viol')), 'ages' => array_fill(0, 6, 0)];
foreach ($G as $g) foreach ($g['ages'] as $i => $v) $T['ages'][$i] += $v;
$AG = ['0–7 days', '8–14 days', '15–21 days', '22–30 days', '31 days – 4 months', 'Over 4 months'];

/* ── drill-down: the outlets behind any number in the table ── */
if (($_GET['ajax'] ?? '') === 'drill') {
    $sub = (string)($_GET['sub'] ?? ''); $grp = (string)($_GET['grp'] ?? ''); $colk = (string)($_GET['col'] ?? 'n');
    $subsIn = $sub !== '' ? [$sub] : array_values(array_map(fn($x) => $x['sub'], array_filter($rows, fn($x) => $grp === '' || $x['grp'] === $grp)));
    $in = array_flip($subsIn); $codes = [];
    foreach ($sales as $c => $v) if (isset($in[$chOf($c)])) $codes[$c] = 1; foreach ($OB as $c => $v) if (isset($in[$chOf($c)])) $codes[$c] = 1;
    foreach ($cust as $c => $x) if (isset($in[$chOf($c)]) && in_array($x['pm'], ['credit', 'cheque'], true)) $codes[$c] = 1;
    $out = [];
    foreach (array_keys($codes) as $c) { $cu = $cust[$c] ?? null; $mo = $MO[$c] ?? []; $ob = $OB[$c] ?? ['open' => 0, 'ovd' => 0, 'ages' => array_fill(0, 6, 0), 'bills' => 0, 'old' => 0];
        $ch = $chOf($c); $mp = $SUB[$ch][1] ?? ''; $mx = preg_match('/(\d+)\s*$/', (string)$mp, $mm) ? (int)$mm[1] : null; $pm = $cu['pm'] ?? ''; $d = (int)($cu['d'] ?? 0); $credit = in_array($pm, ['credit', 'cheque'], true);
        $out[] = ['c' => $c, 'n' => ($cu['nm'] ?? '') ?: ($mo['n'] ?? ''), 'rep' => $mo['rep'] ?? '', 'b' => $mo['b'] ?? '', 'sub' => $ch, 'grp' => $SUB[$ch][0] ?? 'Other', 'pay' => $pm === '' ? '—' : ucfirst($pm), 'days' => $credit ? $d : null, 'mp' => $mp,
            'over' => ($credit && $mx !== null && $d > $mx) ? 1 : 0, 'sales' => round($sales[$c] ?? 0, 2), 'avg' => $nCyc > 0 ? round(($sales[$c] ?? 0) / $nCyc, 2) : 0, 'open' => round($ob['open'], 2), 'ovd' => round($ob['ovd'], 2), 'bills' => $ob['bills'], 'old' => $ob['old'],
            'a0' => round($ob['ages'][0], 2), 'a1' => round($ob['ages'][1], 2), 'a2' => round($ob['ages'][2], 2), 'a3' => round($ob['ages'][3], 2), 'a4' => round($ob['ages'][4], 2), 'a5' => round($ob['ages'][5], 2),
            'viol' => $mo['viol'] ?? 0, 'risk' => $mo['risk'] ?? null, 'band' => $mo['band'] ?? '', 'cs' => $mo['cs'] ?? '', 'cl' => $mo['cl'] ?? 0, 'credit' => $credit ? 1 : 0]; }
    $flt = ['n' => fn($x) => $x['sales'] > 0, 'avg' => fn($x) => $x['sales'] > 0, 'gavg' => fn($x) => $x['sales'] > 0, 'bc' => fn($x) => $x['sales'] > 0, 'credit' => fn($x) => $x['credit'] === 1, 'pdays' => fn($x) => $x['credit'] === 1,
        'over' => fn($x) => $x['over'] === 1, 'open' => fn($x) => $x['open'] > 0, 'util' => fn($x) => $x['open'] > 0, 'age' => fn($x) => $x['open'] > 0, 'ovd' => fn($x) => $x['ovd'] > 0, 'viol' => fn($x) => $x['viol'] > 0];
    $sortK = ['n' => 'avg', 'avg' => 'avg', 'gavg' => 'avg', 'bc' => 'avg', 'credit' => 'open', 'pdays' => 'days', 'over' => 'days', 'open' => 'open', 'util' => 'open', 'age' => 'open', 'ovd' => 'ovd', 'viol' => 'viol'][$colk] ?? 'avg';
    if (isset($flt[$colk])) $out = array_values(array_filter($out, $flt[$colk]));
    usort($out, fn($a, $b) => ($b[$sortK] ?? 0) <=> ($a[$sortK] ?? 0));
    pl_json(['ok' => true, 'rows' => $out, 'scope' => $sub !== '' ? $sub : ($grp !== '' ? $grp . ' (all sub channels)' : 'All channels'), 'period' => $plabel, 'col' => $colk]);
}
/* ── Excel: the meeting layout (merged group cells, live formulas) + live credit columns + ageing sheet ── */
if (isset($_GET['export']) && class_exists('ZipArchive')) {
    require_once __DIR__ . '/rbac_xlsx.php'; while (ob_get_level()) ob_end_clean();
    $X = new MiniXlsx(); $a = $X->sheet('Credit Policy'); $a->tab = '1F3864'; $a->landscape = true; $FM = ['font' => 'Arial', 'border' => 1]; $NUM = '#,##0;\(#,##0\);"-"'; $PCT = '0.0%;\(0.0%\);"-"';
    $HS = ['font' => 'Arial', 'b' => 1, 'color' => 'FFFFFF', 'bg' => '1F3864', 'h' => 'center', 'wrap' => 1, 'border' => 1];
    $a->set(1, 1, 'Credit Policy — sales period ' . $plabel, ['font' => 'Arial', 'b' => 1, 'sz' => 14, 'color' => '1F3864']);
    $a->set(2, 1, 'Allocated credit exposure (G3) = total AVG sale ÷ 30 × ' . $DAYS . ' days; each channel group gets its allocation % (blank = its business contribution).', ['font' => 'Arial', 'sz' => 9, 'color' => '595959']);
    $first = 5; $last = $first + count($rows) - 1; $tr = $last + 1;
    $a->set(3, 6, 'Exposure budget', ['font' => 'Arial', 'b' => 1, 'h' => 'right']); $a->set(3, 7, round($budget, 2), ['font' => 'Arial', 'b' => 1, 'nfs' => $NUM, 'f' => "D$tr/30*$DAYS", 'bg' => 'FFF2CC']);
    foreach (['Outlet counts', 'Sub Channel', 'Channel', 'AVG Sale', 'AVG Total', 'Business contributions', 'Allocated credit Exposure', 'Credit Contribution', 'Maximum credit period', 'Credit outlets', 'Avg policy days', 'Above max period', 'Open credit (now)', 'Overdue (past policy days)', 'Utilisation of allocation', 'Policy violations'] as $i => $h) $a->set(4, $i + 1, $h, $HS);
    $a->heights[4] = 40; $r = $first; $gStart = [];
    foreach ($rows as $x) { if (!isset($gStart[$x['grp']])) $gStart[$x['grp']] = $r; $a->set($r, 1, $x['n'], $FM + ['nfs' => '0']); $a->set($r, 2, $x['sub'], $FM + ['b' => 1]); $a->set($r, 3, $x['grp'], $FM + ['b' => 1, 'bg' => '00B0F0', 'h' => 'center']);
        $a->set($r, 4, round($x['avg'], 2), $FM + ['nfs' => $NUM]); $a->set($r, 9, $x['mp'], $FM + ['h' => 'center']); $a->set($r, 10, $x['credit'], $FM + ['nfs' => '0']); $a->set($r, 11, $x['pdays'] === null ? '' : round($x['pdays'], 1), $FM + ['nfs' => '0.0']);
        $a->set($r, 12, $x['over'], $FM + ['nfs' => '0']); $a->set($r, 13, round($x['open'], 2), $FM + ['nfs' => $NUM]); $a->set($r, 14, round($x['ovd'], 2), $FM + ['nfs' => $NUM]); $a->set($r, 16, $x['viol'], $FM + ['nfs' => '0']); $r++; }
    foreach ($gStart as $g => $s0) { $s1 = $s0 + $G[$g]['subs'] - 1; $pct = $G[$g]['pct'];
        $a->set($s0, 5, round($G[$g]['avg'], 2), $FM + ['nfs' => $NUM, 'v' => 'center', 'f' => "SUM(D$s0:D$s1)"]); $a->set($s0, 6, $G[$g]['bc'], $FM + ['nfs' => $PCT, 'v' => 'center', 'f' => "E$s0/D\$$tr"]);
        $a->set($s0, 7, round($G[$g]['alloc'], 2), $FM + ['nfs' => $NUM, 'v' => 'center', 'f' => $pct === null ? "\$G\$3*F$s0" : "\$G\$3*" . round($pct / 100, 6)]); $a->set($s0, 8, $G[$g]['cc'], $FM + ['nfs' => $PCT, 'v' => 'center', 'f' => "G$s0/G\$$tr"]);
        $a->set($s0, 15, $G[$g]['util'] === null ? '' : round($G[$g]['util'], 4), $FM + ['nfs' => $PCT, 'v' => 'center', 'f' => "IFERROR(SUM(M$s0:M$s1)/G$s0,\"\")"]);
        if ($s1 > $s0) foreach ([5, 6, 7, 8, 15] as $c) { for ($k = $s0 + 1; $k <= $s1; $k++) $a->set($k, $c, '', $FM); $a->merge($s0, $c, $s1, $c); } }
    $TB = ['font' => 'Arial', 'b' => 1, 'bg' => 'D9E1F2', 'border' => 1, 'color' => '1F3864'];
    for ($c = 1; $c <= 16; $c++) $a->set($tr, $c, '', $TB);
    $tv = [1 => $T['n'], 4 => $totAvg, 5 => $totAvg, 6 => 1, 7 => $allocT, 8 => 1, 10 => $T['credit'], 12 => $T['over'], 13 => $T['open'], 14 => $T['ovd'], 16 => $T['viol']];   // cached values (shown even before Excel recalculates)
    foreach ([1 => ['0', 'A'], 4 => [$NUM, 'D'], 5 => [$NUM, 'E'], 6 => [$PCT, 'F'], 7 => [$NUM, 'G'], 8 => [$PCT, 'H'], 10 => ['0', 'J'], 12 => ['0', 'L'], 13 => [$NUM, 'M'], 14 => [$NUM, 'N'], 16 => ['0', 'P']] as $c => [$nf, $L]) $a->set($tr, $c, round($tv[$c], 4), $TB + ['nfs' => $nf, 'f' => "SUM({$L}$first:{$L}$last)"]);
    $a->set($tr, 2, 'TOTAL', $TB);
    $a->set($tr, 15, $allocT > 0 ? $T['open'] / $allocT : '', $TB + ['nfs' => $PCT, 'f' => "IFERROR(M$tr/G$tr,\"\")"]);
    foreach ([9, 30, 13, 15, 15, 12, 16, 12, 12, 10, 10, 10, 15, 15, 12, 10] as $i => $w) $a->widths[$i + 1] = $w; $a->freeze = [4, 3];
    $b = $X->sheet('Credit Ageing by Channel'); $b->tab = '1F3864'; $b->set(1, 1, 'Open credit by age (days since delivery) — channel group and sub channel, ' . date('d M Y'), ['font' => 'Arial', 'b' => 1, 'sz' => 14, 'color' => '1F3864']);
    foreach (array_merge(['Channel', 'Sub Channel', 'Outlets (period)', 'Credit outlets'], $AG, ['Total open credit']) as $i => $h) $b->set(3, $i + 1, $h, $HS); $r = 4;
    foreach ($rows as $x) { $b->set($r, 1, $x['grp'], $FM); $b->set($r, 2, $x['sub'], $FM); $b->set($r, 3, $x['n'], $FM + ['nfs' => '0']); $b->set($r, 4, $x['credit'], $FM + ['nfs' => '0']);
        for ($i = 0; $i < 6; $i++) $b->set($r, 5 + $i, round($x['ages'][$i] ?? 0, 2), $FM + ['nfs' => $NUM]); $b->set($r, 11, round($x['open'], 2), $FM + ['nfs' => $NUM, 'b' => 1, 'f' => 'SUM(E' . $r . ':J' . $r . ')']); $r++; }
    for ($c = 1; $c <= 11; $c++) $b->set($r, $c, '', $TB); $b->set($r, 1, 'TOTAL', $TB); $bt = array_merge([$T['n'], $T['credit']], $T['ages'], [$T['open']]); foreach (['C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'] as $i => $L) $b->set($r, 3 + $i, round($bt[$i], 2), $TB + ['nfs' => $i < 2 ? '0' : $NUM, 'f' => "SUM({$L}4:{$L}" . ($r - 1) . ")"]);
    $b->widths[1] = 14; $b->widths[2] = 30; for ($i = 3; $i <= 11; $i++) $b->widths[$i] = 15; $b->freeze = [3, 2]; $b->condfmt[] = ['I4:J' . ($r - 1), 'FFFFFF', 'F87171', 95];
    $tmp = tempnam(sys_get_temp_dir(), 'cp'); $X->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="credit_policy_' . date('Ymd_His') . '.xlsx"'); header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
ob_end_flush();
include 'header.php';
echo pl_css();
$can = pl_can_edit(); $f0 = fn($v) => number_format((float)$v); $fp = fn($v) => $v === null ? '<span class="z">—</span>' : number_format($v * 100, 1) . '%';
$gc = ['OOH' => '#8b5cf6', 'DC' => '#ec4899', 'ETUP' => '#0ea5e9', 'FG' => '#10b981', 'D-Mart' => '#f59e0b', 'Self Service' => '#ef4444', 'Saubhagya' => '#14b8a6', 'WS' => '#6366f1', 'Other' => '#64748b'];
$col = fn($g) => $gc[$g] ?? '#475569';
$qs = fn($a) => '?' . http_build_query(array_merge(['pt' => $pt, 'py' => $py, 'ph' => $ph, 'pq' => $pq, 'pm' => $pm], $a));
?>
<style>
.cp table.cpt{border-collapse:separate;border-spacing:0;width:100%;font-size:12.5px}.cp .cpt th{background:linear-gradient(180deg,#1e3a8a,#1e2f6e);color:#fff;padding:9px 8px;font-size:11px;text-align:right;position:sticky;top:0;z-index:2;white-space:normal;vertical-align:bottom}.cp .cpt th.l{text-align:left}.cp .cpt th.x{background:linear-gradient(180deg,#0f766e,#115e59)}
.cp .cpt td{padding:6px 8px;border-bottom:1px solid #eef2f7;text-align:right;font-variant-numeric:tabular-nums}.cp .cpt td.l{text-align:left}.cp .cpt td.g{vertical-align:middle;text-align:center;font-weight:800;color:#fff;border-bottom:3px solid #fff}.cp .cpt td.m{vertical-align:middle;background:#f8fafc;border-left:1px solid #e2e8f0;font-weight:700}
.cp .cpt tr.gs td{border-top:2px solid #cbd5e1}.cp .cpt tfoot td{background:linear-gradient(180deg,#fde68a,#fcd34d);font-weight:800;color:#451a03}
.cp .ut{display:inline-block;min-width:70px}.cp .ut i{display:block;height:7px;border-radius:99px;margin-top:3px}.cp .pill{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:800;background:#eef2ff;color:#3730a3}
.cp .pb{display:flex;gap:6px;flex-wrap:wrap}.cp .pb a{padding:7px 12px;border-radius:99px;border:1.5px solid #c7d2fe;color:#1e3a8a;text-decoration:none;font-size:12.5px;font-weight:700;background:#fff}.cp .pb a.on{background:#1e3a8a;color:#fff;border-color:#1e3a8a}
#drM .bd td{white-space:nowrap}.cp td.dr{cursor:pointer}.cp td.dr:hover{box-shadow:inset 0 0 0 2px #6366f1;background-color:#eef2ff!important}.cp td.g.dr:hover,.cp td.m.dr:hover{filter:brightness(1.05)}
.cp .age{display:flex;height:18px;border-radius:6px;overflow:hidden;min-width:180px}.cp .age i{display:block;height:100%}
</style>
<div class="pl cp">
  <div class="hero" style="background:linear-gradient(120deg,#0c4a6e,#0369a1 50%,#0ea5e9)"><h2><i class="fa-solid fa-scale-balanced"></i> Credit Policy — by Channel</h2>
    <p>The credit-meeting <b>Credit Policy</b> table from the system: outlet counts and <b>AVG sale</b> per sub channel for the period you choose, the channel-group <b>business contribution</b>, the <b>allocated credit exposure</b> (total AVG sale ÷ 30 × <b><?= $DAYS ?></b> days, shared by each group's allocation %), credit contribution and the <b>maximum credit period</b> — next to the live credit position: credit outlets, open credit, overdue, utilisation of the allocation, average policy days, outlets above the maximum period and violations. <b>Click any number</b> to see the outlets behind it. Period: <b><?= pl_h($plabel) ?></b>.</p></div>
  <form class="card" method="get"><div class="row" style="align-items:flex-end">
    <div class="fld"><label>Period</label><select name="pt" onchange="this.form.submit()"><?php foreach (['last3' => 'Last 3 cycles', 'last6' => 'Last 6 cycles', 'last12' => 'Last 12 cycles', 'year' => 'Year', 'half' => 'Half-year', 'quarter' => 'Quarter', 'month' => 'Month (sales cycle)'] as $k => $l): ?><option value="<?= $k ?>" <?= $pt === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <?php if (in_array($pt, ['year', 'half', 'quarter'], true)): ?><div class="fld"><label>Year</label><select name="py" onchange="this.form.submit()"><?php foreach ($years as $y): ?><option <?= $py === $y ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <?php if ($pt === 'half'): ?><div class="fld"><label>Half</label><select name="ph" onchange="this.form.submit()"><option value="1" <?= $ph === '1' ? 'selected' : '' ?>>H1 (Jan–Jun cycles)</option><option value="2" <?= $ph === '2' ? 'selected' : '' ?>>H2 (Jul–Dec cycles)</option></select></div><?php endif; ?>
    <?php if ($pt === 'quarter'): ?><div class="fld"><label>Quarter</label><select name="pq" onchange="this.form.submit()"><?php for ($i = 1; $i <= 4; $i++): ?><option value="<?= $i ?>" <?= (int)$pq === $i ? 'selected' : '' ?>>Q<?= $i ?></option><?php endfor; ?></select></div><?php endif; ?>
    <?php if ($pt === 'month'): ?><div class="fld"><label>Sales month</label><select name="pm" onchange="this.form.submit()"><?php foreach (array_reverse($cycles) as $c): $rg = hs_range($c, $sd); ?><option value="<?= $c ?>" <?= $pm === $c ? 'selected' : '' ?>><?= hs_label($c) ?> (<?= $rg[0] ?> → <?= $rg[1] ?>)<?= $c === $openCy && !$openComplete ? ' · to date' : '' ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div style="margin-left:auto;display:flex;gap:8px"><?php if ($can): ?><button class="btn o" type="button" id="cfgBtn"><i class="fa-solid fa-gear"></i> Policy settings</button><?php endif; ?><a class="btn g" data-no-loader href="<?= pl_h($qs(['export' => 1])) ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a></div></div>
    <div style="font-size:12px;color:#64748b;margin-top:8px">Data in the system: <?= $minD ? pl_h($minD) . ' → ' . pl_h($maxD) : 'no sales imported yet' ?> · <?= count($cycles) ?> sales cycles · AVG sale = period sales ÷ <?= number_format($nCyc, 2) ?> cycle(s).</div></form>
  <div class="kp">
    <div class="k k1"><i class="fa-solid fa-store"></i><span>Outlets buying (period)</span><b><?= $f0($T['n']) ?></b></div>
    <div class="k k2"><i class="fa-solid fa-chart-line"></i><span>AVG sale / month</span><b><?= $f0($totAvg) ?></b></div>
    <div class="k k4"><i class="fa-solid fa-wallet"></i><span>Credit exposure budget (<?= $DAYS ?> days)</span><b><?= $f0($budget) ?></b></div>
    <div class="k k6"><i class="fa-solid fa-file-invoice-dollar"></i><span>Open credit now</span><b><?= $f0($T['open']) ?> <small style="font-size:12px">(<?= $budget > 0 ? number_format($T['open'] / $budget * 100, 0) : 0 ?>% of budget)</small></b></div>
    <div class="k k5"><i class="fa-solid fa-hourglass-end"></i><span>Overdue (past policy days)</span><b><?= $f0($T['ovd']) ?></b></div>
    <div class="k k7"><i class="fa-solid fa-triangle-exclamation"></i><span>Credit outlets above max period</span><b><?= $f0($T['over']) ?> / <?= $f0($T['credit']) ?></b></div></div>
  <div class="grid3">
    <div class="card" style="grid-column:span 2"><div class="ct"><i class="fa-solid fa-chart-bar"></i> Business contribution vs credit contribution vs credit actually used</div>
      <?php foreach ($G as $g => $x): $used = $T['open'] > 0 ? $x['open'] / $T['open'] : 0; ?><div style="display:grid;grid-template-columns:110px 1fr 140px;gap:10px;align-items:center;margin:7px 0;font-size:12px">
        <b style="color:<?= $col($g) ?>"><?= pl_h($g) ?></b><div>
        <div style="display:flex;align-items:center;gap:6px"><div style="height:9px;border-radius:99px;background:<?= $col($g) ?>;width:<?= max(0.5, $x['bc'] * 100 * 2.2) ?>%"></div><small><?= number_format($x['bc'] * 100, 1) ?>% sales</small></div>
        <div style="display:flex;align-items:center;gap:6px;margin-top:2px"><div style="height:9px;border-radius:99px;background:repeating-linear-gradient(45deg,<?= $col($g) ?>,<?= $col($g) ?> 4px,#fff 4px,#fff 6px);border:1px solid <?= $col($g) ?>;width:<?= max(0.5, $x['cc'] * 100 * 2.2) ?>%"></div><small><?= number_format($x['cc'] * 100, 1) ?>% allocated</small></div>
        <div style="display:flex;align-items:center;gap:6px;margin-top:2px"><div style="height:9px;border-radius:99px;background:#334155;width:<?= max(0.5, $used * 100 * 2.2) ?>%"></div><small><?= number_format($used * 100, 1) ?>% of open credit</small></div></div>
        <div style="text-align:right"><?php $u = $x['util']; $c = $u === null ? '#94a3b8' : ($u > 1 ? '#dc2626' : ($u > 0.8 ? '#f59e0b' : '#16a34a')); ?><span class="pill" style="background:<?= $c ?>22;color:<?= $c ?>">uses <?= $u === null ? '—' : number_format($u * 100, 0) . '%' ?> of allocation</span></div></div><?php endforeach; ?></div>
    <div class="card"><div class="ct"><i class="fa-solid fa-hourglass-half"></i> Open credit by age (all channels)</div>
      <?php $agc = ['#16a34a', '#84cc16', '#f59e0b', '#f97316', '#dc2626', '#7f1d1d']; $at = array_sum($T['ages']) ?: 1; foreach ($AG as $i => $l): ?><div class="hb"><span class="lb" style="width:130px"><?= $l ?></span><span class="tr"><i style="width:<?= max(1, $T['ages'][$i] / $at * 100) ?>%;background:<?= $agc[$i] ?>"></i></span><span class="vl"><?= $f0($T['ages'][$i]) ?></span></div><?php endforeach; ?>
      <div style="font-size:11.5px;color:#64748b;margin-top:6px">Age = days since delivery of each unpaid bill (today <?= date('d M Y') ?>).</div></div></div>
  <div class="tw" style="max-height:none"><table class="cpt"><thead><tr>
    <th class="l" style="width:92px">Channel</th><th class="l">Sub Channel</th><th>Outlet counts</th><th>AVG Sale</th><th>AVG Total</th><th>Business contribution</th><th>Allocated credit exposure</th><th>Credit contribution</th><th>Maximum credit period</th>
    <th class="x">Credit outlets</th><th class="x">Avg policy days</th><th class="x">Above max period</th><th class="x">Open credit (now)</th><th class="x">Overdue</th><th class="x">Utilisation of allocation</th><th class="x" style="min-width:190px">Open credit by age</th><th class="x">Violations</th></tr></thead><tbody>
  <?php $prev = null; foreach ($rows as $x): $g = $x['grp']; $first = $g !== $prev; $prev = $g; $gx = $G[$g]; $span = $gx['subs']; $ds = 'data-sub="' . pl_h($x['sub']) . '"'; $dg = 'data-grp="' . pl_h($g) . '"'; $dc = fn($k) => ' data-col="' . $k . '"'; ?>
    <tr class="<?= $first ? 'gs' : '' ?>" <?= $ds ?>><?php if ($first): ?><td class="g dr" rowspan="<?= $span ?>" <?= $dg ?><?= $dc('n') ?> style="background:<?= $col($g) ?>"><?= pl_h($g) ?><div style="font-size:10.5px;font-weight:600;opacity:.9"><?= $gx['pct'] === null ? 'auto %' : number_format($gx['pct'], 2) . ' %' ?></div></td><?php endif; ?>
      <td class="l dr"<?= $dc('n') ?>><b><?= pl_h($x['sub']) ?></b></td><td class="dr"<?= $dc('n') ?>><?= $f0($x['n']) ?></td><td class="dr"<?= $dc('avg') ?>><?= $f0($x['avg']) ?></td>
      <?php if ($first): $u = $gx['util']; $uc = $u === null ? '#94a3b8' : ($u > 1 ? '#dc2626' : ($u > 0.8 ? '#f59e0b' : '#16a34a')); ?><td class="m dr" rowspan="<?= $span ?>" <?= $dg ?><?= $dc('gavg') ?>><?= $f0($gx['avg']) ?></td><td class="m dr" rowspan="<?= $span ?>" <?= $dg ?><?= $dc('bc') ?>><?= $fp($gx['bc']) ?></td><td class="m dr" rowspan="<?= $span ?>" <?= $dg ?><?= $dc('open') ?> style="color:#1e3a8a"><?= $f0($gx['alloc']) ?></td><td class="m dr" rowspan="<?= $span ?>" <?= $dg ?><?= $dc('open') ?>><?= $fp($gx['cc']) ?></td><?php endif; ?>
      <td class="dr"<?= $dc('credit') ?> style="text-align:center"><span class="pill"><?= pl_h($x['mp'] !== '' ? $x['mp'] : '—') ?></span></td>
      <td class="dr"<?= $dc('credit') ?>><?= $f0($x['credit']) ?></td><td class="dr"<?= $dc('pdays') ?>><?= $x['pdays'] === null ? '<span class="z">—</span>' : number_format($x['pdays'], 1) ?></td><td class="dr"<?= $dc('over') ?>><?= $x['over'] ? '<b style="color:#b91c1c">' . $x['over'] . '</b>' : '<span class="z">0</span>' ?></td><td class="dr"<?= $dc('open') ?>><b><?= $f0($x['open']) ?></b></td><td class="dr"<?= $dc('ovd') ?> style="color:<?= $x['ovd'] > 0 ? '#b91c1c' : '#94a3b8' ?>"><?= $f0($x['ovd']) ?></td>
      <?php if ($first): ?><td class="m dr" rowspan="<?= $span ?>" <?= $dg ?><?= $dc('util') ?>><span class="ut"><?= $u === null ? '—' : number_format($u * 100, 0) . '%' ?><i style="background:<?= $uc ?>;width:<?= $u === null ? 0 : min(100, $u * 100) ?>%"></i></span></td><?php endif; ?>
      <td class="dr"<?= $dc('age') ?>><?php $sa = array_sum($x['ages']) ?: 0; if ($sa > 0): ?><div class="age" title="<?= pl_h(implode(' · ', array_map(fn($i) => $AG[$i] . ': ' . number_format($x['ages'][$i] ?? 0), range(0, 5)))) ?>"><?php for ($i = 0; $i < 6; $i++): ?><i style="width:<?= ($x['ages'][$i] ?? 0) / $sa * 100 ?>%;background:<?= $agc[$i] ?>"></i><?php endfor; ?></div><?php else: ?><span class="z">—</span><?php endif; ?></td>
      <td class="dr"<?= $dc('viol') ?>><?= $x['viol'] ?: '<span class="z">0</span>' ?></td></tr>
  <?php endforeach; ?></tbody>
  <tfoot><tr><td class="l" colspan="2">TOTAL <small style="font-weight:600;opacity:.75">· click a total for every outlet</small></td><td class="dr" data-grp="" data-col="n"><?= $f0($T['n']) ?></td><td><?= $f0($totAvg) ?></td><td><?= $f0($totAvg) ?></td><td>100%</td><td><?= $f0($allocT) ?></td><td>100%</td><td></td><td class="dr" data-grp="" data-col="credit"><?= $f0($T['credit']) ?></td><td></td><td class="dr" data-grp="" data-col="over"><?= $f0($T['over']) ?></td><td class="dr" data-grp="" data-col="open"><?= $f0($T['open']) ?></td><td class="dr" data-grp="" data-col="ovd"><?= $f0($T['ovd']) ?></td><td><?= $allocT > 0 ? number_format($T['open'] / $allocT * 100, 0) . '%' : '' ?></td><td></td><td><?= $f0($T['viol']) ?></td></tr></tfoot></table></div>
</div>
<div class="plm pl" id="drM" style="padding:0"><div class="bx" style="max-width:1400px;height:92vh"><div class="hd" style="background:linear-gradient(120deg,#0c4a6e,#0369a1 55%,#0ea5e9)"><div><b id="drT" style="font-size:18px">Outlets</b><div id="drS" style="font-size:12.5px;opacity:.85"></div></div><button type="button" data-close>&times;</button></div>
  <div class="bd" style="padding:12px 16px;display:flex;flex-direction:column;gap:10px;flex:1;overflow:hidden"><div class="kp" id="drK" style="margin:0"></div>
    <div class="row" style="gap:8px"><input id="drQ" type="search" placeholder="Search HUL, customer, rep, beat" style="padding:8px 10px;border:1.5px solid #cbd5e1;border-radius:10px;min-width:260px"><select id="drF" style="padding:8px 10px;border:1.5px solid #cbd5e1;border-radius:10px"><option value="">All outlets in scope</option><option value="sales">Bought in the period</option><option value="credit">Credit / cheque outlets</option><option value="open">With open credit</option><option value="ovd">Overdue</option><option value="over">Policy days above max</option><option value="viol">With violations</option><option value="old">Unpaid bills over 30 days</option></select><span id="drN" style="font-size:12.5px;color:#64748b"></span><button class="btn g" id="drX" type="button" style="margin-left:auto"><i class="fa-solid fa-file-excel"></i> Export these outlets</button></div>
    <div class="tw" id="drW" style="flex:1;max-height:none;overflow:auto"><table><thead id="drH"></thead><tbody id="drB"></tbody><tfoot id="drFt"></tfoot></table></div></div></div></div>
<?php if ($can): ?>
<div class="plm" id="cfgM"><div class="bx" style="max-width:980px"><div class="hd" style="background:linear-gradient(120deg,#0c4a6e,#0369a1)"><div><b style="font-size:18px">Credit policy settings</b><div style="font-size:12.5px;opacity:.85">Channel groups, allocation %, sub-channel group and maximum credit period</div></div><button type="button" data-close>&times;</button></div>
  <div class="bd fm"><div class="two"><div><label>Credit exposure days (budget = total AVG sale ÷ 30 × days)</label><input id="cDays" type="number" min="1" max="120" step="1" value="<?= $DAYS ?>"></div><div></div></div>
    <h4 style="margin:14px 0 6px">Channel groups — allocation % of the budget (blank = the group's business contribution)</h4><table class="rk"><thead><tr><th>Group</th><th style="text-align:right">Allocation %</th></tr></thead><tbody id="cG">
    <?php foreach ($GRP as $g => $p): ?><tr><td><input class="cg" value="<?= pl_h($g) ?>"></td><td><input class="cp2" type="number" step="0.01" min="0" max="100" value="<?= $p === null ? '' : $p ?>" placeholder="auto"></td></tr><?php endforeach; ?></tbody></table>
    <button type="button" class="btn o" id="cAddG" style="margin-top:6px">+ Add group</button>
    <h4 style="margin:14px 0 6px">Sub channels</h4><table class="rk"><thead><tr><th>Sub channel (secondary channel)</th><th>Group</th><th>Maximum credit period</th></tr></thead><tbody id="cS">
    <?php $allSubs = array_unique(array_merge(array_keys($SUB), array_column($rows, 'sub'))); foreach ($allSubs as $s): ?><tr><td><input class="cs" value="<?= pl_h($s) ?>" readonly style="background:#f8fafc"></td><td><input class="csg" list="grpList" value="<?= pl_h($SUB[$s][0] ?? 'Other') ?>"></td><td><input class="csm" value="<?= pl_h($SUB[$s][1] ?? '') ?>" placeholder="e.g. 07-14"></td></tr><?php endforeach; ?></tbody></table>
    <datalist id="grpList"><?php foreach (array_keys($GRP) as $g): ?><option value="<?= pl_h($g) ?>"><?php endforeach; ?></datalist></div>
  <div class="ft"><span class="msg" id="cMsg"></span><button class="b o" data-close>Cancel</button><button class="b" id="cSave"><i class="fa-solid fa-floppy-disk"></i> Save settings</button></div></div></div>
<?php endif; ?>
<?= pl_js() ?>
<script>
(function(){ var $=function(i){return document.getElementById(i);}, esc=plEsc, D=plModal('drM'), ALL=[], CUR=[], SORT={k:'avg',dir:-1}, META={};
  var AGL=['0–7 d','8–14 d','15–21 d','22–30 d','31 d–4 m','> 4 m'], AGC=['#16a34a','#84cc16','#f59e0b','#f97316','#dc2626','#7f1d1d'];
  var COLS=[['c','HUL','s'],['n','Customer','s'],['rep','Sales rep','s'],['b','Beat','s'],['sub','Sub channel','s'],['pay','Pay','s'],['days','Policy days','n'],['mp','Max period','s'],['sales','Period sales','n'],['avg','AVG sale / month','n'],['open','Open credit','n'],['ovd','Overdue','n'],['bills','Open bills','n'],['old','Oldest unpaid (days)','n'],['a0',AGL[0],'n'],['a1',AGL[1],'n'],['a2',AGL[2],'n'],['a3',AGL[3],'n'],['a4',AGL[4],'n'],['a5',AGL[5],'n'],['viol','Violations','n'],['risk','Risk','n'],['cl','Credit limit','n']];
  var TOT={sales:1,avg:1,open:1,ovd:1,bills:1,a0:1,a1:1,a2:1,a3:1,a4:1,a5:1,viol:1,cl:1};
  var TITLES={n:'Outlets that bought',avg:'AVG sale by outlet',gavg:'AVG sale by outlet',bc:'AVG sale by outlet',credit:'Credit / cheque outlets',pdays:'Policy days of credit outlets',over:'Credit outlets above the maximum credit period',open:'Outlets with open credit',util:'Open credit vs allocation',age:'Open credit by age',ovd:'Overdue outlets (past their policy days)',viol:'Outlets with policy violations'};
  function cell(x,c){ var v=x[c[0]];
    if(c[0]==='c') return '<td class="l"><b style="color:#1e3a8a">'+esc(v)+'</b></td>'; if(c[2]==='s') return '<td class="l">'+(c[0]==='pay'?plPm(v==='—'?'':v):esc(v==null?'':v))+'</td>';
    if(c[0]==='days') return '<td>'+(v==null?'<span class="z">—</span>':(x.over?'<b style="color:#b91c1c" title="above the maximum credit period">'+v+' ▲</b>':v))+'</td>';
    if(c[0]==='risk') return '<td>'+(v==null?'<span class="z">—</span>':plMeter(v))+'</td>';
    if(c[0]==='old') return '<td style="color:'+(v>30?'#b91c1c':'#334155')+';font-weight:'+(v>30?800:400)+'">'+(v||'<span class="z">—</span>')+'</td>';
    if(/^a[0-5]$/.test(c[0])) return '<td style="'+(v>0?'background:'+AGC[+c[0][1]]+'22':'')+'">'+(v>0?plF0(v):'<span class="z">—</span>')+'</td>';
    if(c[0]==='ovd') return '<td style="color:'+(v>0?'#b91c1c':'#94a3b8')+'">'+plF0(v)+'</td>';
    return '<td>'+(v?plF0(v):'<span class="z">0</span>')+'</td>'; }
  function draw(){ var q=$('drQ').value.toLowerCase(), f=$('drF').value;
    CUR=ALL.filter(function(x){ if(f==='sales'&&!(x.sales>0)) return false; if(f==='credit'&&!x.credit) return false; if(f==='open'&&!(x.open>0)) return false; if(f==='ovd'&&!(x.ovd>0)) return false; if(f==='over'&&!x.over) return false; if(f==='viol'&&!(x.viol>0)) return false; if(f==='old'&&!(x.old>30)) return false;
      return !q||(x.c+' '+x.n+' '+x.rep+' '+x.b).toLowerCase().indexOf(q)>=0; });
    CUR.sort(function(a,b){ var k=SORT.k,x=a[k],y=b[k]; if(x==null) x=-1e18; if(y==null) y=-1e18; return (typeof x==='number'&&typeof y==='number'?x-y:String(x).localeCompare(String(y)))*SORT.dir; });
    $('drH').innerHTML='<tr>'+COLS.map(function(c){ return '<th class="'+(c[2]==='s'?'l':'')+'" data-k="'+c[0]+'" style="cursor:pointer">'+c[1]+(SORT.k===c[0]?(SORT.dir>0?' ▲':' ▼'):'')+'</th>'; }).join('')+'</tr>';
    $('drB').innerHTML=CUR.slice(0,3000).map(function(x){ return '<tr>'+COLS.map(function(c){return cell(x,c);}).join('')+'</tr>'; }).join('')||'<tr><td class="l" colspan="'+COLS.length+'" style="padding:24px;color:#94a3b8;text-align:center">No outlets.</td></tr>';
    var t={}; CUR.forEach(function(x){ Object.keys(TOT).forEach(function(k){ t[k]=(t[k]||0)+(+x[k]||0); }); });
    $('drFt').innerHTML='<tr>'+COLS.map(function(c,i){ return i===0?'<td class="l">TOTAL</td>':(i===1?'<td class="l">'+plF0(CUR.length)+' outlets</td>':'<td>'+(TOT[c[0]]?plF0(t[c[0]]):'')+'</td>'); }).join('')+'</tr>';
    $('drN').textContent=CUR.length>3000?'showing the first 3,000 of '+plF0(CUR.length)+' — export for all':'';
    var cr=CUR.filter(function(x){return x.credit;}).length, ov=CUR.filter(function(x){return x.over;}).length;
    $('drK').innerHTML=plKpi([['Outlets',plF0(CUR.length),'fa-store',1],['AVG sale / month',plF0(t.avg||0),'fa-chart-line',2],['Credit outlets',plF0(cr)+(ov?' · '+ov+' above max':''),'fa-credit-card',7],['Open credit',plF0(t.open||0),'fa-file-invoice-dollar',4],['Overdue',plF0(t.ovd||0),'fa-hourglass-end',6],['Over 30 days (31 d+)',plF0((t.a4||0)+(t.a5||0)),'fa-triangle-exclamation',5]]); }
  $('drH').addEventListener('click',function(e){ var th=e.target.closest('th[data-k]'); if(!th) return; SORT.dir=(SORT.k===th.dataset.k)?-SORT.dir:-1; SORT.k=th.dataset.k; draw(); });
  $('drQ').oninput=function(){ clearTimeout(window.__dq); window.__dq=setTimeout(draw,150); }; $('drF').onchange=draw;
  $('drX').onclick=function(){ var body={title:'Credit Policy — '+META.scope,sub:(TITLES[META.col]||'Outlets')+' · period '+META.period+'.',name:'credit_policy_outlets',cols:COLS.map(function(c){ return {h:c[1],t:c[2]==='s'?'s':(c[0]==='days'||c[0]==='bills'||c[0]==='old'||c[0]==='viol'?'i':'n'),w:c[2]==='s'?(c[0]==='n'?30:20):13,tot:!!TOT[c[0]]}; }),rows:CUR.map(function(x){ return COLS.map(function(c){ var v=x[c[0]]; return v==null?'':v; }); })};
    var f=document.createElement('form'); f.method='POST'; f.action='plan_export.php'; var i=document.createElement('input'); i.type='hidden'; i.name='payload'; i.value=JSON.stringify(body); f.appendChild(i); document.body.appendChild(f); f.submit(); f.remove(); };
  document.querySelector('.cp table.cpt').addEventListener('click',function(e){ var td=e.target.closest('td.dr'); if(!td) return; var tr=td.closest('tr'), col=td.dataset.col||'n';
    var sub=td.hasAttribute('data-grp')?'':(tr.dataset.sub||''), grp=td.hasAttribute('data-grp')?td.dataset.grp:'';
    $('drT').textContent=(TITLES[col]||'Outlets')+' — '+(sub||grp||'All channels'); $('drS').textContent='Loading…'; $('drB').innerHTML=''; $('drK').innerHTML=''; $('drQ').value=''; $('drF').value=''; D.open();
    SORT={k:{n:'avg',avg:'avg',gavg:'avg',bc:'avg',credit:'open',pdays:'days',over:'days',open:'open',util:'open',age:'old',ovd:'ovd',viol:'viol'}[col]||'avg',dir:-1};
    fetch(location.pathname+location.search+(location.search?'&':'?')+'ajax=drill&col='+encodeURIComponent(col)+'&sub='+encodeURIComponent(sub)+'&grp='+encodeURIComponent(grp),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
      if(!j.ok) throw new Error(j.msg||'Could not load'); ALL=j.rows; META=j; $('drS').textContent=j.scope+' · '+j.period+' · click a column title to sort'; draw(); }).catch(function(er){ $('drS').textContent=er.message; }); });
})();
</script>
<script>
(function(){ var $=function(i){return document.getElementById(i);}; if(!$('cfgM')) return; var M=plModal('cfgM');
  $('cfgBtn').onclick=function(){ M.open(); };
  $('cAddG').onclick=function(){ $('cG').insertAdjacentHTML('beforeend','<tr><td><input class="cg" placeholder="Group name"></td><td><input class="cp2" type="number" step="0.01" min="0" max="100" placeholder="auto"></td></tr>'); };
  $('cSave').onclick=function(){ var d={days:$('cDays').value,groups:[],subs:[]};
    document.querySelectorAll('#cG tr').forEach(function(tr){ d.groups.push({grp:tr.querySelector('.cg').value,pct:tr.querySelector('.cp2').value}); });
    document.querySelectorAll('#cS tr').forEach(function(tr){ d.subs.push({sub:tr.querySelector('.cs').value,grp:tr.querySelector('.csg').value,mp:tr.querySelector('.csm').value}); });
    $('cMsg').textContent='Saving…'; plPost('plan_credit_policy_report.php',{action:'save_cfg',cfg:JSON.stringify(d)}).then(function(j){ $('cMsg').style.color=j.ok?'#15803d':'#b91c1c'; $('cMsg').textContent=j.msg; if(j.ok) setTimeout(function(){ location.reload(); },700); }).catch(function(e){ $('cMsg').style.color='#b91c1c'; $('cMsg').textContent=e.message; }); };
})();
</script>
<?php include 'footer.php'; ?>
