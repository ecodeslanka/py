<?php
/**
 * plan_executive_dashboard.php — Analysis → Executive Planning Dashboard (workbook sheet 1, from the system data)
 * Key figures, net sales & active outlets by cycle, operational categories, credit-risk distribution, the open-cycle forecast,
 * the sales-rep summary (by rep code) and an automatic management summary. Excel export.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/plan_lib.php'; require_once __DIR__ . '/plan_model_lib.php';
$M = pm_get($conn, isset($_GET['recalc'])); $S = hs_settings($conn); $rows = $M['rows']; $St = $M['stats'] ?? [];
$months = []; for ($i = 0; $i < $S['months']; $i++) $months[] = hs_add($S['first_cycle'], $i); $N = count($months); $openYm = hs_add($S['first_cycle'], $S['months']); $openRg = hs_range($openYm, $S['start_day']);
$UT = pl_outlet_targets($conn, $rows, $openYm, 'mod'); $T = 0; $r = pl_q($conn, "SELECT amount FROM plan_targets WHERE ym = '$openYm' AND rep = ''"); if ($r && ($x = mysqli_fetch_row($r))) $T = (float)$x[0];
$K = ['base' => 0, 'tgt' => 0, 'gpot' => 0, 'flat' => 0, 'unsup' => 0, 'react' => 0, 'decl' => 0, 'rest' => 0, 'pgo' => 0, 'exp' => 0, 'min' => 0, 'mod' => 0, 'max' => 0, 'pace' => 0, 'todate' => 0, 'bl' => 0, 'buy3' => 0, 'n' => count($rows), 'crit' => 0, 'critExp' => 0, 'nmLostFirst' => 0, 'nmLostLast' => 0, 'nm' => 0];
$cyc = array_fill(0, $N, 0.0); $act = array_fill(0, $N, 0); $cat = []; $band = []; $rep = [];
foreach ($rows as $x) {
    $K['base'] += $x['base']; $K['tgt'] += $x['tgt']; if (in_array($x['cat'][0], ['1', '2'], true)) $K['gpot'] += $x['gpot']; $K['flat'] += $x['base'] * 1.1; $K['unsup'] += max(0, $x['base'] * 1.1 - $x['tgt']);
    $K['react'] += $x['react']; $K['decl'] += $x['decl']; $K['rest'] += $x['rest']; $K['pgo'] += $x['pgo']; $K['exp'] += $x['exp']; foreach (['min', 'mod', 'max', 'todate'] as $q) $K[$q] += $x[$q]; $K['pace'] += $x['pace'] ?? 0;
    $bl = strpos($x['cs'], 'BLOCKED') === 0; if ($bl) $K['bl']++; if (array_sum(array_map('floatval', array_slice($x['v'], -3))) > 0) $K['buy3']++;
    if ($x['prio'][0] === '1') { $K['crit']++; $K['critExp'] += $x['exp']; }
    if ($x['nm']) { $K['nm']++; $K['nmLostFirst'] += (float)($x['v'][0] ?? 0); $K['nmLostLast'] += (float)($x['v'][$N - 1] ?? 0); }
    foreach ($x['v'] as $i => $v) if ($v !== null) { $cyc[$i] += $v; if ($v > 0) $act[$i]++; }
    $c = &$cat[$x['cat']]; if (!$c) $c = [0, 0, 0]; $c[0]++; $c[1] += $x['base']; $c[2] += $x['tgt']; unset($c);
    $bk = $bl ? 'BLOCKED' : $x['band']; $b = &$band[$bk]; if (!$b) $b = [0, 0]; $b[0]++; $b[1] += $x['base']; unset($b);
    $rk = $x['repc']; $p = &$rep[$rk]; if (!$p) $p = ['label' => $x['rep'], 'n' => 0, 'base' => 0, 'tgt' => 0, 'min' => 0, 'mod' => 0, 'max' => 0, 'ut' => 0, 'todate' => 0, 'col' => 0, 'exp' => 0];
    $p['n']++; foreach (['base', 'tgt', 'min', 'mod', 'max', 'todate', 'exp'] as $q) $p[$q] += $x[$q]; $p['ut'] += $UT[$x['c']] ?? 0; if ($x['prio'][0] < '3') $p['col']++; unset($p);
}
ksort($cat); uksort($band, fn($a, $b) => ($a === 'BLOCKED') <=> ($b === 'BLOCKED') ?: strcmp($a, $b)); uasort($rep, fn($a, $b) => strcmp($a['label'], $b['label']));
$upside = $K['react'] + $K['decl'] + $K['rest'] + $K['pgo'];
/* management summary — written from the numbers */
$h1 = array_sum(array_slice($cyc, 0, 4)) / 4; $h2 = array_sum(array_slice($cyc, -4)) / 4; $sum = [];
$sum[] = 'Sales averaged LKR ' . number_format($h1 / 1e6, 1) . 'M per cycle in ' . hs_label($months[0]) . '–' . hs_label($months[3]) . ' and LKR ' . number_format($h2 / 1e6, 1) . 'M in ' . hs_label($months[$N - 4]) . '–' . hs_label($months[$N - 1]) . ' (' . ($h1 > 0 ? ($h2 >= $h1 ? '+' : '') . number_format(($h2 / $h1 - 1) * 100, 1) : '0') . '%). The risk-adjusted recommendation is LKR ' . number_format($K['tgt'] / 1e6, 1) . 'M a month (' . ($K['base'] > 0 ? number_format(($K['tgt'] / $K['base'] - 1) * 100, 1) : 0) . '% vs the baseline).';
if ($K['nm']) $sum[] = number_format($K['nm']) . ' outlet codes not in the Party Master billed LKR ' . number_format($K['nmLostFirst'] / 1e6, 1) . 'M in ' . hs_label($months[0]) . ' and LKR ' . number_format($K['nmLostLast'] / 1e6, 2) . 'M in ' . hs_label($months[$N - 1]) . ' — confirm whether they are delisted or re-coded before counting them.';
$sum[] = 'A flat +10 % target (LKR ' . number_format($K['flat'] / 1e6, 1) . 'M) is LKR ' . number_format($K['unsup'] / 1e6, 1) . 'M above what payment behaviour and history support outlet by outlet.';
$c5 = $cat['5 — Restrict Credit'][0] ?? 0; $sum[] = $c5 ? number_format($c5) . ' outlets are in category 5 (restrict credit: risk above ' . pm_a('seg_restrict') . ').' : 'No outlet is in category 5 — every high-risk score already belongs to a blacklisted (category 6) outlet.';
$g12 = 0; foreach ($rows as $x) if (in_array($x['cat'][0], ['1', '2'], true)) $g12 += $x['tgt'] - $x['base']; $sum[] = 'Growth is concentrated in category 1–2 healthy outlets (LKR ' . number_format($g12 / 1e6, 1) . 'M a month); credit restraint is applied where risk is elevated. ' . number_format($K['crit']) . ' outlets are Critical for collection with LKR ' . number_format($K['critExp'] / 1e6, 1) . 'M visible exposure.';
if ($T > 0) $sum[] = 'Unilever target for ' . hs_label($openYm) . ': LKR ' . number_format($T / 1e6, 1) . 'M — minimum-risk forecast covers ' . number_format($K['min'] / $T * 100, 1) . '%, moderate ' . number_format($K['mod'] / $T * 100, 1) . '%, maximum-risk ' . number_format($K['max'] / $T * 100, 1) . '%.';

$KF = [['Historical baseline (monthly)', $K['base'], 'n'], ['Risk-adjusted recommended sales', $K['tgt'], 'n'], ['Recommended vs baseline', $K['base'] > 0 ? $K['tgt'] / $K['base'] - 1 : 0, 'p'], ['Growth potential (cat 1–2)', $K['gpot'], 'n'], ['Flat +10 % reference', $K['flat'], 'n'],
    ['Unsupported by a flat 10 %', $K['unsup'], 'n'], ['Unverified upside (reactivation, recovery, peer gap, restoration)', $upside, 'n'], ['Unilever target ' . hs_label($openYm), $T ?: null, 'n'], ['Target gap (target − recommended)', $T ? $T - $K['tgt'] : null, 'n'],
    ['Sales, ' . $N . ' cycles (' . hs_range($months[0], $S['start_day'])[0] . ' to ' . hs_range($months[$N - 1], $S['start_day'])[1] . ')', array_sum($cyc), 'n'], ['Outlets in universe', $K['n'], 'i'], ['Outlets buying in the last 3 cycles', $K['buy3'], 'i'],
    ['Currently blacklisted outlets', $K['bl'], 'i'], ['Visible overdue + unsettled cheque exposure', $K['exp'], 'n'], [hs_label($openYm) . ' forecast — minimum risk', $K['min'], 'n'], [hs_label($openYm) . ' forecast — moderate', $K['mod'], 'n'], [hs_label($openYm) . ' forecast — maximum risk', $K['max'], 'n'],
    [hs_label($openYm) . ' pace projection', $K['pace'] ?: null, 'n'], [hs_label($openYm) . ' sales already billed', $K['todate'], 'n']];
if (isset($_GET['export']) && class_exists('ZipArchive')) {
    require_once __DIR__ . '/rbac_xlsx.php'; while (ob_get_level()) ob_end_clean();
    $X = new MiniXlsx(); $a = $X->sheet('Executive Dashboard'); $a->tab = '1F3864'; $FM = ['font' => 'Arial']; $HS = $FM + ['b' => 1, 'color' => 'FFFFFF', 'bg' => '1F3864', 'h' => 'center', 'wrap' => 1, 'border' => 1]; $NUM = '#,##0;\(#,##0\);"-"'; $PCT = '0.0%;\(0.0%\);"-"'; $V = $FM + ['b' => 1, 'sz' => 11, 'color' => '1F3864'];
    $a->set(1, 1, 'YELO — Risk-Adjusted Sales Planning Dashboard', $FM + ['b' => 1, 'sz' => 16, 'color' => '1F3864']); $a->set(2, 1, 'Sales months = company cycles (day ' . $S['start_day'] . ' to day ' . ($S['start_day'] - 1) . '). Built from the system on ' . $M['built_at'] . '.', $FM + ['sz' => 9, 'color' => '595959']);
    $a->set(4, 1, 'KEY FIGURES', $FM + ['b' => 1]); $r = 5; foreach ($KF as [$l, $v, $t]) { $a->set($r, 1, $l, $FM); $a->set($r, 2, $v === null ? '' : ($t === 'i' ? (int)$v : round($v, 4)), $V + ['nfs' => $t === 'p' ? $PCT : $NUM]); $r++; }
    $a->set(4, 4, 'NET SALES & ACTIVE OUTLETS BY CYCLE', $FM + ['b' => 1]); foreach (['Cycle', 'Sales (LKR)', 'Active outlets'] as $i => $h) $a->set(5, 4 + $i, $h, $HS); foreach ($months as $i => $m) { $a->set(6 + $i, 4, hs_label($m), $FM + ['border' => 1]); $a->set(6 + $i, 5, round($cyc[$i], 2), $FM + ['nfs' => $NUM, 'border' => 1]); $a->set(6 + $i, 6, $act[$i], $FM + ['nfs' => '0', 'border' => 1]); }
    $a->condfmt[] = ['E6:E' . (5 + $N), 'FFFFFF', '63BE7B', 95];
    $a->set(4, 8, 'OPERATIONAL CATEGORY', $FM + ['b' => 1]); foreach (['Category', 'Outlets', 'Baseline', 'Recommended', 'Change %'] as $i => $h) $a->set(5, 8 + $i, $h, $HS); $r = 6;
    foreach ($cat as $k => [$n, $b, $g]) { $a->set($r, 8, $k, $FM + ['border' => 1]); $a->set($r, 9, $n, $FM + ['border' => 1, 'nfs' => '0']); $a->set($r, 10, round($b), $FM + ['border' => 1, 'nfs' => $NUM]); $a->set($r, 11, round($g), $FM + ['border' => 1, 'nfs' => $NUM]); $a->set($r, 12, $b > 0 ? $g / $b - 1 : '', $FM + ['border' => 1, 'nfs' => $PCT]); $r++; }
    $r += 2; $a->set($r, 8, 'CREDIT RISK DISTRIBUTION', $FM + ['b' => 1]); $r++; foreach (['Risk band', 'Outlets', 'Baseline'] as $i => $h) $a->set($r, 8 + $i, $h, $HS); $r++;
    foreach ($band as $k => [$n, $b]) { $a->set($r, 8, $k, $FM + ['border' => 1]); $a->set($r, 9, $n, $FM + ['border' => 1, 'nfs' => '0']); $a->set($r, 10, round($b), $FM + ['border' => 1, 'nfs' => $NUM]); $r++; }
    $r = max($r, 26) + 2; $a->set($r, 1, 'MANAGEMENT SUMMARY', $FM + ['b' => 1]); foreach ($sum as $t) $a->set(++$r, 1, '• ' . $t, $FM);
    $b = $X->sheet('Sales Rep Summary'); $b->tab = '1F3864'; $b->set(1, 1, 'Sales rep summary — by rep code', $FM + ['b' => 1, 'sz' => 14, 'color' => '1F3864']);
    foreach (['Sales Rep (code · name)', 'Outlets', 'Baseline', 'Recommended', 'Min-risk', 'Moderate', 'Max-risk', 'Unilever target', 'Sales to date', 'Collect first', 'Exposure'] as $i => $h) $b->set(3, $i + 1, $h, $HS); $r = 4;
    foreach ($rep as $p) { foreach ([$p['label'], $p['n'], $p['base'], $p['tgt'], $p['min'], $p['mod'], $p['max'], $p['ut'], $p['todate'], $p['col'], $p['exp']] as $i => $v) $b->set($r, $i + 1, is_float($v) ? round($v) : $v, $FM + ['border' => 1] + ($i > 1 && $i !== 9 ? ['nfs' => $NUM] : [])); $r++; }
    $b->widths[1] = 40; for ($i = 2; $i <= 11; $i++) $b->widths[$i] = 15; $b->freeze = [3, 1];
    $a->widths[1] = 52; $a->widths[2] = 18; $a->widths[3] = 3; $a->widths[4] = 12; $a->widths[5] = 16; $a->widths[6] = 12; $a->widths[7] = 3; $a->widths[8] = 30; for ($i = 9; $i <= 12; $i++) $a->widths[$i] = 14;
    $tmp = tempnam(sys_get_temp_dir(), 'ed'); $X->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="executive_dashboard_' . date('Ymd_His') . '.xlsx"'); header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
ob_end_flush();
include 'header.php';
echo pl_css();
$m1 = fn($v) => $v === null ? '—' : 'Rs. ' . number_format($v / 1e6, 2) . 'M'; $mx = max($cyc ?: [1]); $mxA = max($act ?: [1]);
$BC = ['A' => '#16a34a', 'B' => '#0ea5e9', 'C' => '#f59e0b', 'D' => '#ea580c', 'E' => '#dc2626', 'BLOCKED' => '#1f2937'];
?>
<div class="pl">
  <div class="hero" style="background:linear-gradient(120deg,#0f172a,#1e3a8a 55%,#2563eb)"><h2><i class="fa-solid fa-gauge-high"></i> Executive Planning Dashboard</h2>
    <p>Risk-adjusted sales planning from the system data: baseline, recommended sales, growth and unverified upside, the open-cycle forecast (<?= hs_label($openYm) ?>: <?= $openRg[0] ?> → <?= $openRg[1] ?>), credit risk and collections, by category and by sales rep (rep code). Built <b><?= pl_h($M['built_at']) ?></b>. <a href="?recalc=1"><i class="fa-solid fa-rotate"></i> Recalculate</a> · <a href="plan_model_assumptions.php"><i class="fa-solid fa-book"></i> Assumptions</a> · <a href="?export=1" data-no-loader><i class="fa-solid fa-file-excel"></i> Export Excel</a></p></div>
  <div class="kp">
    <div class="k k7"><i class="fa-solid fa-scale-balanced"></i><span>Historical baseline / month</span><b><?= $m1($K['base']) ?></b></div>
    <div class="k k3"><i class="fa-solid fa-bullseye"></i><span>Risk-adjusted recommended</span><b><?= $m1($K['tgt']) ?> <small style="font-size:13px">(<?= $K['base'] > 0 ? number_format(($K['tgt'] / $K['base'] - 1) * 100, 1) : 0 ?>%)</small></b></div>
    <div class="k k1"><i class="fa-solid fa-seedling"></i><span>Growth potential (cat 1–2)</span><b><?= $m1($K['gpot']) ?></b></div>
    <div class="k k5"><i class="fa-solid fa-hand-holding-heart"></i><span>Unverified upside</span><b><?= $m1($upside) ?></b></div>
    <div class="k k4"><i class="fa-solid fa-flag"></i><span>Unilever target <?= hs_label($openYm) ?></span><b><?= $T ? $m1($T) : '<a href="plan_target_entry.php" style="color:#fff;font-size:14px">enter target →</a>' ?></b></div>
    <div class="k k6"><i class="fa-solid fa-triangle-exclamation"></i><span>Critical collections · exposure</span><b><?= number_format($K['crit']) ?> · <?= $m1($K['critExp']) ?></b></div></div>
  <div class="grid3">
    <div class="card"><div class="ct"><i class="fa-solid fa-list"></i> Key figures</div><table style="width:100%;font-size:12.5px"><tbody>
      <?php foreach ($KF as [$l, $v, $t]): ?><tr><td class="l" style="text-align:left;white-space:normal;padding:5px 4px"><?= pl_h($l) ?></td><td style="font-weight:800;color:#1e3a8a;padding:5px 4px"><?= $v === null ? '—' : ($t === 'p' ? number_format($v * 100, 1) . '%' : number_format($v)) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <div class="card"><div class="ct"><i class="fa-solid fa-chart-column"></i> Net sales &amp; active outlets by cycle</div>
      <div style="display:flex;align-items:flex-end;gap:5px;height:190px;padding-top:6px"><?php foreach ($months as $i => $m): ?><div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;min-width:0" title="<?= hs_label($m) ?>: Rs. <?= number_format($cyc[$i]) ?> · <?= $act[$i] ?> outlets"><em style="font-style:normal;font-size:9.5px;font-weight:700;color:#334155"><?= number_format($cyc[$i] / 1e6, 0) ?>M</em><div style="width:100%;border-radius:6px 6px 2px 2px;background:linear-gradient(180deg,#60a5fa,#1d4ed8);height:<?= max(2, round($cyc[$i] / $mx * 78)) ?>%"></div><div style="width:60%;height:4px;margin-top:2px;border-radius:2px;background:#f59e0b;opacity:<?= round(0.3 + 0.7 * $act[$i] / $mxA, 2) ?>"></div><small style="font-size:9.5px;color:#64748b;margin-top:2px"><?= hs_label($m) ?></small></div><?php endforeach; ?></div>
      <div style="font-size:11.5px;color:#64748b;margin-top:6px">Bars = sales · orange tick = active outlets (darker = more). Last cycle: <?= number_format($act[$N - 1]) ?> outlets bought.</div></div>
    <div class="card"><div class="ct"><i class="fa-solid fa-shield-halved"></i> Credit risk distribution</div><?php $mb = max(array_column($band, 0) ?: [1]); foreach ($band as $k => [$n, $b]): ?>
      <div class="hb"><span class="lb"><?= pl_h($k) ?></span><span class="tr"><i style="width:<?= max(2, $n / $mb * 100) ?>%;background:<?= $BC[$k === 'BLOCKED' ? 'BLOCKED' : $k[0]] ?>"></i></span><span class="vl"><?= number_format($n) ?></span></div><?php endforeach; ?>
      <div class="ct" style="margin-top:14px"><i class="fa-solid fa-crosshairs"></i> <?= hs_label($openYm) ?> forecast</div>
      <?php $fm = max($K['max'], $T, 1); foreach ([['Sales to date', $K['todate'], '#64748b'], ['Pace projection', $K['pace'], '#0ea5e9'], ['Minimum risk', $K['min'], '#16a34a'], ['Moderate', $K['mod'], '#f59e0b'], ['Maximum risk', $K['max'], '#dc2626']] + ($T ? [5 => ['Unilever target', $T, '#1e3a8a']] : []) as [$l, $v, $c]): ?>
      <div class="hb"><span class="lb"><?= $l ?></span><span class="tr"><i style="width:<?= max(1, $v / $fm * 100) ?>%;background:<?= $c ?>"></i></span><span class="vl"><?= number_format($v / 1e6, 1) ?>M</span></div><?php endforeach; ?></div></div>
  <div class="grid3">
    <div class="card" style="grid-column:span 2"><div class="ct"><i class="fa-solid fa-layer-group"></i> Operational category</div><div class="tw" style="max-height:none"><table style="width:100%"><thead><tr><th class="l">Category</th><th>Outlets</th><th>Baseline</th><th>Recommended</th><th>Change %</th></tr></thead><tbody>
      <?php $ct = [0, 0, 0]; foreach ($cat as $k => [$n, $b, $g]): $ct[0] += $n; $ct[1] += $b; $ct[2] += $g; ?><tr><td class="l"><?= pl_h($k) ?></td><td><?= number_format($n) ?></td><td><?= number_format($b) ?></td><td><b><?= number_format($g) ?></b></td><td style="color:<?= $g >= $b ? '#15803d' : '#b91c1c' ?>;font-weight:700"><?= $b > 0 ? number_format(($g / $b - 1) * 100, 1) . '%' : '—' ?></td></tr><?php endforeach; ?></tbody>
      <tfoot><tr><td class="l">Total</td><td><?= number_format($ct[0]) ?></td><td><?= number_format($ct[1]) ?></td><td><?= number_format($ct[2]) ?></td><td><?= $ct[1] > 0 ? number_format(($ct[2] / $ct[1] - 1) * 100, 1) . '%' : '' ?></td></tr></tfoot></table></div></div>
    <div class="card"><div class="ct"><i class="fa-solid fa-clipboard-list"></i> Management summary</div><ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.6;color:#334155"><?php foreach ($sum as $t): ?><li style="margin-bottom:6px"><?= pl_h($t) ?></li><?php endforeach; ?></ul></div></div>
  <div class="card"><div class="ct"><i class="fa-solid fa-user-tie"></i> Sales rep summary (by rep code) — <a href="plan_sr_action_plans.php">open the SR action plans</a></div><div class="tw" style="max-height:60vh"><table style="width:100%"><thead><tr><th class="l">Sales Rep (code · name)</th><th>Outlets</th><th>Baseline</th><th>Recommended</th><th>Min-risk</th><th>Moderate</th><th>Max-risk</th><th>Unilever target</th><th>Sales to date</th><th>Collect first</th><th>Exposure</th></tr></thead><tbody>
    <?php $rt = array_fill_keys(['n', 'base', 'tgt', 'min', 'mod', 'max', 'ut', 'todate', 'col', 'exp'], 0); foreach ($rep as $p): foreach ($rt as $q => $_) $rt[$q] += $p[$q]; ?><tr><td class="l"><b><?= pl_h($p['label']) ?></b></td><td><?= $p['n'] ?></td><td><?= number_format($p['base']) ?></td><td><b><?= number_format($p['tgt']) ?></b></td><td><?= number_format($p['min']) ?></td><td><?= number_format($p['mod']) ?></td><td><?= number_format($p['max']) ?></td><td><?= $p['ut'] ? number_format($p['ut']) : '<span class="z">—</span>' ?></td><td><?= number_format($p['todate']) ?></td><td><?= $p['col'] ? '<b style="color:#b91c1c">' . $p['col'] . '</b>' : '<span class="z">0</span>' ?></td><td><?= number_format($p['exp']) ?></td></tr><?php endforeach; ?></tbody>
    <tfoot><tr><td class="l">TOTAL</td><?php foreach ($rt as $q => $v): ?><td><?= number_format($v) ?></td><?php endforeach; ?></tr></tfoot></table></div></div>
</div>
<?php include 'footer.php'; ?>
