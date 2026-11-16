<?php
/**
 * plan_channel_performance.php — Analysis → Channel Performance (workbook sheets 9 and 15)
 * By secondary channel (with its primary channel): outlets, active, baseline, recommended, growth, medians, trend, volatility, bills, risk mix,
 * blocked, develop and under-performers; plus the number of outlets that bought in every sales cycle. Excel export with both tables.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/plan_lib.php'; require_once __DIR__ . '/plan_model_lib.php';
$M = pm_get($conn, isset($_GET['recalc'])); $S = hs_settings($conn);
$months = []; for ($i = 0; $i < $S['months']; $i++) $months[] = hs_add($S['first_cycle'], $i); $N = count($months); $openLbl = hs_label(hs_add($S['first_cycle'], $S['months'])) . ' to date';
$C = []; $tot = ['n' => 0, 'act' => 0, 'base' => 0, 'tgt' => 0, 'a3' => 0, 'p9' => 0, 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'BL' => 0, 'dev' => 0, 'under' => 0, 'cyc' => array_fill(0, $N + 1, 0), 'any' => 0];
foreach ($M['rows'] as $x) {
    $k = $x['ch']; $c = &$C[$k]; if (!$c) $c = ['ch' => $k, 'pch' => [], 'n' => 0, 'act' => 0, 'base' => 0, 'baseAct' => 0, 'tgt' => 0, 'a3' => 0, 'p9' => 0, 'meds' => [], 'cvs' => [], 'bills' => [], 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'BL' => 0, 'dev' => 0, 'rows' => [], 'cyc' => array_fill(0, $N + 1, 0), 'any' => 0];
    $v = $x['v']; $obs = count(array_filter($v, fn($z) => $z !== null)); $act = array_sum(array_map('floatval', array_slice($v, -3))) > 0;
    $c['n']++; $c['pch'][$x['pch']] = ($c['pch'][$x['pch']] ?? 0) + 1; if ($act) { $c['act']++; $c['baseAct'] += $x['base']; }
    $c['base'] += $x['base']; $c['tgt'] += $x['tgt']; $c['a3'] += $x['a3']; $c['p9'] += $x['prior9'];
    if ($obs >= 6) { $c['meds'][] = $x['base']; if ($x['cv'] !== null) $c['cvs'][] = $x['cv']; $c['bills'][] = $x['b12']; }
    if (strpos($x['cs'], 'BLOCKED') === 0) $c['BL']++; else $c[$x['band'][0] === 'E' ? 'D' : $x['band'][0]]++;
    if ($x['cat'][0] === '1') $c['dev']++; $c['rows'][] = [$x['base'], $x['risk'], strpos($x['cs'], 'BLOCKED') === 0];
    $any = false; foreach ($v as $i => $z) if ($z !== null && $z > 0) { $c['cyc'][$i]++; $any = true; } if ($x['todate'] > 0) $c['cyc'][$N]++; if ($any) $c['any']++;
    unset($c);
}
$R = [];
foreach ($C as $k => $c) {
    arsort($c['pch']); $med = $c['meds'] ? pm_median($c['meds']) : 0; $under = 0; foreach ($c['rows'] as [$b, $r, $bl]) if (!$bl && $r <= 20 && $med > 0 && $b < 0.75 * $med) $under++;
    $R[] = ['ch' => $k, 'pch' => (string)array_key_first($c['pch']), 'n' => $c['n'], 'act' => $c['act'], 'base' => $c['base'], 'avgAct' => $c['act'] ? $c['baseAct'] / $c['act'] : 0, 'tgt' => $c['tgt'], 'gr' => $c['base'] > 0 ? $c['tgt'] / $c['base'] - 1 : null,
        'med' => $med, 'trend' => $c['p9'] > 0 ? $c['a3'] / $c['p9'] - 1 : null, 'cv' => $c['cvs'] ? pm_median($c['cvs']) : null, 'bills' => $c['bills'] ? pm_median($c['bills']) : null, 'A' => $c['A'], 'B' => $c['B'], 'C' => $c['C'], 'D' => $c['D'], 'BL' => $c['BL'], 'dev' => $c['dev'], 'under' => $under,
        'note' => count($c['meds']) < 10 ? 'Indicative (< 10 peers)' : '', 'cyc' => $c['cyc'], 'any' => $c['any']];
    foreach (['n', 'act', 'base', 'tgt', 'A', 'B', 'C', 'D', 'BL', 'dev', 'under', 'any'] as $q) $tot[$q] += end($R)[$q]; $tot['a3'] += $c['a3']; $tot['p9'] += $c['p9']; foreach ($c['cyc'] as $i => $z) $tot['cyc'][$i] += $z;
}
usort($R, fn($a, $b) => $b['base'] <=> $a['base']);
$hd1 = ['Secondary Channel', 'Primary Channel', 'Outlets', 'Active (last 3 cycles)', 'Baseline Total', 'Avg Baseline (active)', 'Recommended Total', 'Risk-Adj Growth %', 'Median Baseline', 'Trend 3M vs prior 9M', 'Median Volatility CV', 'Median Bills / Month', 'Risk A', 'Risk B', 'Risk C', 'Risk D/E', 'Blocked', 'Develop (Cat 1)', 'Underperformers (>25% below median, risk A)', 'Peer Note'];
if (isset($_GET['export']) && class_exists('ZipArchive')) {
    require_once __DIR__ . '/rbac_xlsx.php'; while (ob_get_level()) ob_end_clean();
    $X = new MiniXlsx(); $FM = ['font' => 'Arial']; $HS = $FM + ['b' => 1, 'color' => 'FFFFFF', 'bg' => '1F3864', 'h' => 'center', 'wrap' => 1, 'border' => 1]; $NUM = '#,##0;\(#,##0\);"-"'; $PCT = '0.0%;\(0.0%\);"-"'; $TB = $FM + ['b' => 1, 'bg' => 'D9E1F2', 'border' => 1, 'color' => '1F3864'];
    $a = $X->sheet('Channel Performance'); $a->tab = '1F3864'; $a->set(1, 1, 'Channel Performance Analysis — by Secondary and Primary Channel', $FM + ['b' => 1, 'sz' => 14, 'color' => '1F3864']); $a->set(2, 1, 'Medians, trend and volatility use outlets with 6+ observed months. Small peer groups (< 10) are indicative only. Built ' . $M['built_at'] . '.', $FM + ['sz' => 9, 'color' => '595959']);
    foreach ($hd1 as $i => $h) $a->set(4, $i + 1, $h, $HS); $a->heights[4] = 42; $r = 5;
    foreach ($R as $x) { $vals = [$x['ch'], $x['pch'], $x['n'], $x['act'], round($x['base'], 2), round($x['avgAct'], 2), round($x['tgt'], 2), $x['gr'], round($x['med'], 2), $x['trend'], $x['cv'], $x['bills'], $x['A'], $x['B'], $x['C'], $x['D'], $x['BL'], $x['dev'], $x['under'], $x['note']];
        foreach ($vals as $i => $v) $a->set($r, $i + 1, $v === null ? '' : $v, $FM + (in_array($i, [4, 5, 6, 8], true) ? ['nfs' => $NUM] : (in_array($i, [7, 9], true) ? ['nfs' => $PCT] : (in_array($i, [10, 11], true) ? ['nfs' => '0.00'] : [])))); $r++; }
    $a->set($r + 1, 1, 'TOTAL', $TB); foreach ([3 => 'n', 4 => 'act', 5 => 'base', 7 => 'tgt', 13 => 'A', 14 => 'B', 15 => 'C', 16 => 'D', 17 => 'BL', 18 => 'dev', 19 => 'under'] as $ci => $q) $a->set($r + 1, $ci, round($tot[$q], 2), $TB + ['nfs' => $NUM]);
    foreach ([26, 22, 9, 10, 15, 13, 15, 10, 13, 11, 10, 10, 8, 8, 8, 8, 8, 9, 14, 22] as $i => $w) $a->widths[$i + 1] = $w; $a->freeze = [4, 1];
    $b = $X->sheet('Active Outlets by Channel'); $b->tab = '1F3864'; $b->set(1, 1, 'Number of outlets that bought (sales > 0) — by Secondary Channel and sales cycle', $FM + ['b' => 1, 'sz' => 14, 'color' => '1F3864']);
    $hd2 = array_merge(['Secondary Channel', 'Primary Channel'], array_map('hs_label', $months), [$openLbl, 'Any cycle (' . $N . ')', 'Avg per cycle']); foreach ($hd2 as $i => $h) $b->set(4, $i + 1, $h, $HS); $r = 5;
    foreach ($R as $x) { $b->set($r, 1, $x['ch'], $FM); $b->set($r, 2, $x['pch'], $FM); foreach ($x['cyc'] as $i => $z) $b->set($r, 3 + $i, $z, $FM + ['nfs' => '0']); $b->set($r, 4 + $N, $x['any'], $FM + ['nfs' => '0']); $b->set($r, 5 + $N, round(array_sum(array_slice($x['cyc'], 0, $N)) / $N, 2), $FM + ['nfs' => '0.00']); $r++; }
    $b->set($r + 1, 1, 'TOTAL', $TB); foreach ($tot['cyc'] as $i => $z) $b->set($r + 1, 3 + $i, $z, $TB + ['nfs' => '0']); $b->set($r + 1, 4 + $N, $tot['any'], $TB + ['nfs' => '0']); $b->widths[1] = 26; $b->widths[2] = 22; $b->freeze = [4, 1];
    $tmp = tempnam(sys_get_temp_dir(), 'cp'); $X->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="channel_performance_' . date('Ymd_His') . '.xlsx"'); header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
ob_end_flush();
include 'header.php';
echo pl_css();
$f0 = fn($v) => $v === null ? '<span class="z">—</span>' : number_format($v); $fp = fn($v) => $v === null ? '<span class="z">—</span>' : '<span style="color:' . ($v < 0 ? '#b91c1c' : '#15803d') . ';font-weight:700">' . ($v > 0 ? '+' : '') . number_format($v * 100, 1) . '%</span>';
$maxB = max(array_column($R, 'base') ?: [1]);
?>
<div class="pl">
  <div class="hero" style="background:linear-gradient(120deg,#083344,#0e7490 55%,#22d3ee)"><h2><i class="fa-solid fa-sitemap"></i> Channel Performance</h2>
    <p>By <b>secondary channel</b> (with its primary channel): size, activity, baseline vs recommended, medians, 3-month trend against the prior months, sales volatility (CV), bills per month, the risk mix and how many outlets are blocked, ready to develop, or under-performing (&gt; 25 % below the channel median with low risk). Medians use outlets with 6+ observed months; groups under 10 outlets are indicative. Data from the system on <b><?= pl_h($M['built_at']) ?></b>. <a href="?recalc=1"><i class="fa-solid fa-rotate"></i> Recalculate</a></p></div>
  <div class="kp"><?= '' ?><div class="k k1"><i class="fa-solid fa-sitemap"></i><span>Channels</span><b><?= count($R) ?></b></div><div class="k k2"><i class="fa-solid fa-store"></i><span>Outlets / active</span><b><?= number_format($tot['n']) ?> / <?= number_format($tot['act']) ?></b></div><div class="k k7"><i class="fa-solid fa-scale-balanced"></i><span>Baseline</span><b><?= number_format($tot['base']) ?></b></div><div class="k k3"><i class="fa-solid fa-bullseye"></i><span>Recommended</span><b><?= number_format($tot['tgt']) ?></b></div><div class="k k4"><i class="fa-solid fa-arrow-trend-up"></i><span>3M vs prior 9M</span><b><?= $tot['p9'] > 0 ? number_format(($tot['a3'] / $tot['p9'] - 1) * 100, 1) . '%' : '—' ?></b></div><div class="k k5"><i class="fa-solid fa-seedling"></i><span>Develop (Cat 1) / under-performers</span><b><?= number_format($tot['dev']) ?> / <?= number_format($tot['under']) ?></b></div></div>
  <div class="card"><div class="row" style="justify-content:space-between"><div class="ct" style="margin:0"><i class="fa-solid fa-table"></i> Channel performance</div><a class="btn g" data-no-loader href="?export=1"><i class="fa-solid fa-file-excel"></i> Export Excel (both tables)</a></div>
  <div class="tw" style="margin-top:10px;max-height:none"><table><thead><tr><?php foreach ($hd1 as $i => $h): ?><th class="<?= $i < 2 || $i === 19 ? 'l' : '' ?>"><?= pl_h($h) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($R as $x): ?><tr><td class="l"><b><?= pl_h($x['ch']) ?></b><div style="height:5px;background:#e0f2fe;border-radius:99px;margin-top:3px;width:160px"><i style="display:block;height:100%;border-radius:99px;background:#0891b2;width:<?= round($x['base'] / $maxB * 100) ?>%"></i></div></td><td class="l"><?= pl_h($x['pch']) ?></td><td><?= $x['n'] ?></td><td><?= $x['act'] ?></td><td><?= $f0($x['base']) ?></td><td><?= $f0($x['avgAct']) ?></td><td><b><?= $f0($x['tgt']) ?></b></td><td><?= $fp($x['gr']) ?></td><td><?= $f0($x['med']) ?></td><td><?= $fp($x['trend']) ?></td><td><?= $x['cv'] === null ? '—' : number_format($x['cv'], 2) ?></td><td><?= $x['bills'] === null ? '—' : number_format($x['bills'], 2) ?></td>
    <td><?= $x['A'] ?></td><td><?= $x['B'] ?></td><td><?= $x['C'] ?></td><td><?= $x['D'] ?: '<span class="z">0</span>' ?></td><td><?= $x['BL'] ? '<b style="color:#b91c1c">' . $x['BL'] . '</b>' : '<span class="z">0</span>' ?></td><td><?= $x['dev'] ? '<b style="color:#047857">' . $x['dev'] . '</b>' : '<span class="z">0</span>' ?></td><td><?= $x['under'] ?: '<span class="z">0</span>' ?></td><td class="l" style="font-size:11.5px;color:#64748b"><?= pl_h($x['note']) ?></td></tr><?php endforeach; ?></tbody>
  <tfoot><tr><td class="l">TOTAL</td><td></td><td><?= number_format($tot['n']) ?></td><td><?= number_format($tot['act']) ?></td><td><?= number_format($tot['base']) ?></td><td></td><td><?= number_format($tot['tgt']) ?></td><td><?= $tot['base'] > 0 ? number_format(($tot['tgt'] / $tot['base'] - 1) * 100, 1) . '%' : '' ?></td><td></td><td></td><td></td><td></td><td><?= $tot['A'] ?></td><td><?= $tot['B'] ?></td><td><?= $tot['C'] ?></td><td><?= $tot['D'] ?></td><td><?= $tot['BL'] ?></td><td><?= $tot['dev'] ?></td><td><?= $tot['under'] ?></td><td></td></tr></tfoot></table></div></div>
  <div class="card"><div class="ct"><i class="fa-solid fa-users"></i> Outlets that bought — by channel and sales cycle</div>
  <div class="tw" style="max-height:none"><table><thead><tr><th class="l">Secondary Channel</th><th class="l">Primary Channel</th><?php foreach ($months as $m): ?><th><?= hs_label($m) ?></th><?php endforeach; ?><th style="background:#64748b"><?= pl_h($openLbl) ?></th><th>Any cycle</th><th>Avg / cycle</th></tr></thead><tbody>
  <?php foreach ($R as $x): $mx = max(1, max(array_slice($x['cyc'], 0, $N))); ?><tr><td class="l"><b><?= pl_h($x['ch']) ?></b></td><td class="l"><?= pl_h($x['pch']) ?></td><?php foreach (array_slice($x['cyc'], 0, $N) as $z): ?><td style="background:rgba(8,145,178,<?= round(0.06 + $z / $mx * 0.3, 2) ?>)"><?= $z ?></td><?php endforeach; ?><td style="color:#64748b"><?= $x['cyc'][$N] ?></td><td><b><?= $x['any'] ?></b></td><td><?= number_format(array_sum(array_slice($x['cyc'], 0, $N)) / $N, 1) ?></td></tr><?php endforeach; ?></tbody>
  <tfoot><tr><td class="l">TOTAL</td><td></td><?php foreach ($tot['cyc'] as $z): ?><td><?= number_format($z) ?></td><?php endforeach; ?><td><?= number_format($tot['any']) ?></td><td><?= number_format(array_sum(array_slice($tot['cyc'], 0, $N)) / $N, 1) ?></td></tr></tfoot></table></div></div>
</div>
<?php include 'footer.php'; ?>
