<?php
/**
 * historical_sales_trend_report.php — Analysis → Historical Sales Trends
 * Outlet (HUL code) × company-cycle sales, averages, baseline and trend. Rules + cycle in Sales Range Settings. Export Excel.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
@set_time_limit(0); @ini_set('memory_limit', '1024M');
require_once __DIR__ . '/historical_sales_lib.php';
function hr_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function hr_n($v) { return $v === null ? '' : number_format((float)$v, 2); }
function hr_p($v) { return $v === null ? '' : number_format($v * 100, 1) . '%'; }

$S = hs_settings($conn); $D = hs_build($conn, $S); $months = $D['months'];
$f_q = trim($_GET['q'] ?? ''); $f_ch = trim($_GET['channel'] ?? ''); $f_sp = trim($_GET['sp'] ?? ''); $f_beat = trim($_GET['beat'] ?? ''); $f_show = in_array($_GET['show'] ?? '', ['active', 'dormant'], true) ? $_GET['show'] : '';
$chs = $sps = $beats = []; foreach ($D['rows'] as $x) { $chs[$x['channel']] = 1; if ($x['sp'] !== '') $sps[$x['sp']] = 1; if ($x['beat'] !== '') $beats[$x['beat']] = 1; } ksort($chs); ksort($sps); ksort($beats);
$rows = []; $tot = []; $ex = 0.0;
foreach ($D['rows'] as $x) {
    if ($f_ch !== '' && $x['channel'] !== $f_ch) continue; if ($f_sp !== '' && $x['sp'] !== $f_sp) continue; if ($f_beat !== '' && $x['beat'] !== $f_beat) continue;
    if ($f_q !== '' && stripos($x['code'] . ' ' . $x['name'] . ' ' . $x['sp'] . ' ' . $x['beat'], $f_q) === false) continue;
    if ($f_show === 'active' && $x['r3'] <= 0) continue; if ($f_show === 'dormant' && $x['r3'] > 0) continue;
    $rows[] = $x; foreach ($months as $m) $tot[$m] = ($tot[$m] ?? 0) + ($x['v'][$m] ?? 0); $ex += $x['extra'];
}
$grand = array_sum($tot);
$lastTxt = $D['last_date'] ? date('d M Y', strtotime($D['last_date'])) : '—';
$exRange = hs_range($D['extra'], $S['start_day']); $exLbl = hs_label($D['extra']) . ' to date';
$hdr = array_merge(['HUL', 'Customer Name', 'Sales SP Name', 'Beat', 'Secondary Channel'], array_map('hs_label', $months), [$exLbl], ['Avg 12M', 'Avg 6M', 'Avg 3M', 'Latest Month', 'Highest Month', 'Lowest Month', 'Active Months', 'Volatility CV', 'MoM Growth', 'Baseline (LKR)', 'Rolling 3M', 'Trend vs prior months']);

if (isset($_GET['export']) && class_exists('ZipArchive')) {
    require_once __DIR__ . '/rbac_xlsx.php'; while (ob_get_level()) ob_end_clean();
    $X = new MiniXlsx(); $sh = $X->sheet('Historical Sales Trends');
    $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '1E3A8A', 'h' => 'center', 'border' => 1, 'wrap' => 1]; $HG = ['b' => 1, 'color' => '374151', 'bg' => 'E5E7EB', 'h' => 'center', 'border' => 1, 'wrap' => 1];
    $sh->set(1, 1, 'Historical Sales Trends — sales = ' . ($S['basis'] === 'billvalue' ? 'Bill Value + Total Discount' : 'Total Discount + Final Bill Amount') . ', per company cycle, LKR', ['b' => 1, 'sz' => 14]);
    $sh->set(2, 1, 'Cycle: ' . hs_label($months[0]) . ' = ' . hs_range($months[0], $S['start_day'])[0] . ' → ' . hs_range($months[0], $S['start_day'])[1] . ' … ' . hs_label(end($months)) . ' = ' . hs_range(end($months), $S['start_day'])[0] . ' → ' . hs_range(end($months), $S['start_day'])[1] . '. Bills before ' . $S['cutover'] . ' from the Analysis secondary import, from that date from our secondary import. Blank = before the first purchase.', ['i' => 1, 'color' => '6B7280']);
    foreach ($hdr as $i => $h) $sh->set(4, $i + 1, $h, $i === 5 + count($months) ? $HG : $H);
    $r = 5; foreach ($rows as $x) {
        $c = 1; foreach ([$x['code'], $x['name'], $x['sp'], $x['beat'], $x['channel']] as $v) $sh->set($r, $c++, $v, ['border' => 1]);
        foreach ($months as $m) { $v = $x['v'][$m]; $sh->set($r, $c++, $v === null ? '' : $v, ['border' => 1, 'h' => 'right', 'nf' => 4]); }
        $sh->set($r, $c++, $x['extra'], ['border' => 1, 'h' => 'right', 'nf' => 4, 'bg' => 'F3F4F6']);
        foreach ([$x['a12'], $x['a6'], $x['a3'], $x['latest'], $x['hi'], $x['lo']] as $v) $sh->set($r, $c++, round($v, 2), ['border' => 1, 'h' => 'right', 'nf' => 4]);
        $sh->set($r, $c++, $x['active'], ['border' => 1, 'h' => 'center']); $sh->set($r, $c++, $x['cv'] === null ? '' : round($x['cv'], 3), ['border' => 1, 'h' => 'right']); $sh->set($r, $c++, $x['mom'] === null ? '' : round($x['mom'], 3), ['border' => 1, 'h' => 'right', 'nf' => 10]);
        $sh->set($r, $c++, round($x['base'], 2), ['border' => 1, 'h' => 'right', 'nf' => 4, 'b' => 1]); $sh->set($r, $c++, round($x['r3'], 2), ['border' => 1, 'h' => 'right', 'nf' => 4]); $sh->set($r, $c++, $x['trend'] === null ? '' : round($x['trend'], 3), ['border' => 1, 'h' => 'right', 'nf' => 10]);
        $r++;
    }
    $tb = ['b' => 1, 'border' => 1, 'bg' => 'FDE68A', 'h' => 'right', 'nf' => 4]; $sh->set($r, 1, 'TOTAL', ['b' => 1, 'border' => 1, 'bg' => 'FDE68A']); $c = 6; foreach ($months as $m) $sh->set($r, $c++, round($tot[$m] ?? 0, 2), $tb); $sh->set($r, $c, round($ex, 2), $tb);
    $sh->widths[1] = 20; $sh->widths[2] = 32; $sh->widths[3] = 28; $sh->widths[4] = 24; $sh->widths[5] = 24; for ($i = 6; $i <= count($hdr); $i++) $sh->widths[$i] = 14;
    $sh->freeze = [4, 2]; $last = count($hdr); $col = ''; $n = $last; while ($n > 0) { $col = chr(65 + ($n - 1) % 26) . $col; $n = intdiv($n - 1, 26); } $sh->filter = 'A4:' . $col . max(5, $r - 1);
    $tmp = tempnam(sys_get_temp_dir(), 'hs'); $X->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="historical_sales_trends_' . date('Ymd_His') . '.xlsx"'); header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
ob_end_flush();
include 'header.php';
?>
<style>
.hs{padding:16px;font-family:'Inter',system-ui,sans-serif;color:#111827}.hs h2{margin:0 0 3px;font-size:22px}.hs .sub{color:#6b7280;font-size:13px;margin:0 0 14px}
.hs .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;margin-bottom:14px}.hs .row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.hs .fld{display:flex;flex-direction:column;gap:3px}.hs .fld label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase}
.hs input,.hs select{padding:8px 10px;border:1.5px solid #d1d5db;border-radius:8px;font:inherit;font-size:13px;max-width:220px}.hs .btn{padding:8px 16px;border:none;border-radius:8px;background:#1e3a8a;color:#fff;font:600 13px inherit;cursor:pointer;text-decoration:none;display:inline-flex;gap:6px;align-items:center}.hs .btn.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe}.hs .btn.g{background:#16a34a}
.hs .kp{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:10px;margin-bottom:12px}.hs .k{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px}.hs .k span{font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:700;display:block}.hs .k b{font-size:19px;white-space:nowrap}
.hs .tw{overflow:auto;border:1px solid #e5e7eb;border-radius:10px;background:#fff;max-height:74vh}.hs table{border-collapse:separate;border-spacing:0;font-size:12.5px;width:max-content;min-width:100%}.hs th{background:#1e3a8a;color:#fff;padding:8px 10px;text-align:right;position:sticky;top:0;z-index:2;white-space:nowrap}.hs th.l,.hs td.l{text-align:left}.hs th.g{background:#6b7280}.hs td{padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.hs td.s1,.hs th.s1{position:sticky;left:0;z-index:3;background:#fff;min-width:150px}.hs th.s1{background:#1e3a8a}.hs td.s2,.hs th.s2{position:sticky;left:150px;z-index:3;background:#fff;min-width:210px;max-width:210px;overflow:hidden;text-overflow:ellipsis}.hs th.s2{background:#1e3a8a}.hs tr:hover td{background:#eef2ff}.hs tr:hover td.s1,.hs tr:hover td.s2{background:#eef2ff}
.hs td.ex{background:#f3f4f6;color:#6b7280}.hs td.z{color:#d1d5db}.hs tfoot td{background:#fde68a;font-weight:800;position:sticky;bottom:0}.hs .neg{color:#b91c1c}.hs .pos{color:#15803d}
</style>
<div class="hs">
  <h2><i class="fa-solid fa-chart-line" style="color:#1e3a8a"></i> Historical Sales Trends</h2>
  <p class="sub">Sales per outlet (HUL code) per company cycle: <b><?= hr_h(hs_label($months[0])) ?> = <?= hr_h(hs_range($months[0], $S['start_day'])[0]) ?> → <?= hr_h(hs_range($months[0], $S['start_day'])[1]) ?></b> … <b><?= hr_h(hs_label(end($months))) ?> = <?= hr_h(hs_range(end($months), $S['start_day'])[0]) ?> → <?= hr_h(hs_range(end($months), $S['start_day'])[1]) ?></b>. Bills before <b><?= hr_h($S['cutover']) ?></b> come from the Analysis secondary import, bills from that date from our own secondary import. Sales value = <b><?= $S['basis'] === 'billvalue' ? 'Bill Value + Total Discount' : 'Total Discount + Final Bill Amount (to be collected)' ?></b>. Blank = before the first purchase. <a href="historical_sales_settings.php">Edit the sales range / rules</a>.</p>
  <?php if (!$D['rows']): ?><div class="card" style="color:#92400e;background:#fffbeb">No sales found for this range yet — import the Invoice Wise Sales file (Analysis → Import Secondary Invoices) and/or our secondary invoices, or check the <a href="historical_sales_settings.php">settings</a>.</div><?php endif; ?>
  <form method="get" class="card"><div class="row">
    <div class="fld"><label>Search</label><input type="search" name="q" value="<?= hr_h($f_q) ?>" placeholder="HUL, customer, SP, beat"></div>
    <div class="fld"><label>Channel</label><select name="channel"><option value="">All</option><?php foreach ($chs as $c => $_): ?><option <?= $f_ch === $c ? 'selected' : '' ?>><?= hr_h($c) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Sales person</label><select name="sp"><option value="">All</option><?php foreach ($sps as $c => $_): ?><option <?= $f_sp === $c ? 'selected' : '' ?>><?= hr_h($c) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Beat</label><select name="beat"><option value="">All</option><?php foreach ($beats as $c => $_): ?><option <?= $f_beat === $c ? 'selected' : '' ?>><?= hr_h($c) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Show</label><select name="show"><option value="">All outlets</option><option value="active" <?= $f_show === 'active' ? 'selected' : '' ?>>Bought in last 3 cycles</option><option value="dormant" <?= $f_show === 'dormant' ? 'selected' : '' ?>>No purchase in last 3 cycles</option></select></div>
    <button class="btn" type="submit"><i class="fa-solid fa-filter"></i> Show</button><a class="btn o" href="historical_sales_trend_report.php">Reset</a>
    <a class="btn g" data-no-loader href="?<?= hr_h(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a></div></form>
  <div class="kp"><div class="k"><span>Outlets</span><b><?= number_format(count($rows)) ?></b></div><div class="k"><span>Total sales (<?= count($months) ?> cycles)</span><b>Rs. <?= number_format($grand, 2) ?></b></div><div class="k"><span>Latest cycle <?= hr_h(hs_label(end($months))) ?></span><b>Rs. <?= number_format($tot[end($months)] ?? 0, 2) ?></b></div><div class="k"><span><?= hr_h($exLbl) ?> (<?= hr_h(date('d M', strtotime($exRange[0]))) ?> – <?= hr_h($lastTxt) ?>)</span><b>Rs. <?= number_format($ex, 2) ?></b></div><div class="k"><span>Bills used</span><b style="font-size:14px"><?= number_format($D['src']['analysis']) ?> analysis · <?= number_format($D['src']['system']) ?> system</b></div></div>
  <div class="tw"><table><thead><tr><th class="l s1">HUL</th><th class="l s2">Customer Name</th><th class="l">Sales SP Name</th><th class="l">Beat</th><th class="l">Secondary Channel</th>
    <?php foreach ($months as $m): $rg = hs_range($m, $S['start_day']); ?><th title="<?= hr_h($rg[0] . ' → ' . $rg[1]) ?>"><?= hr_h(hs_label($m)) ?></th><?php endforeach; ?><th class="g" title="<?= hr_h($exRange[0]) ?> → <?= hr_h($lastTxt) ?>"><?= hr_h($exLbl) ?></th>
    <th>Avg 12M</th><th>Avg 6M</th><th>Avg 3M</th><th>Latest</th><th>Highest</th><th>Lowest</th><th>Active</th><th>CV</th><th>MoM</th><th>Baseline</th><th>Rolling 3M</th><th>Trend</th></tr></thead><tbody>
  <?php foreach ($rows as $x): ?><tr><td class="l s1"><b><?= hr_h($x['code']) ?></b></td><td class="l s2" title="<?= hr_h($x['name']) ?>"><?= hr_h($x['name']) ?></td><td class="l"><?= hr_h($x['sp']) ?></td><td class="l"><?= hr_h($x['beat']) ?></td><td class="l"><?= hr_h($x['channel']) ?></td>
    <?php foreach ($months as $m): $v = $x['v'][$m]; ?><td class="<?= $v === null || $v == 0 ? 'z' : '' ?>"><?= $v === null ? '' : number_format($v, 2) ?></td><?php endforeach; ?><td class="ex"><?= $x['extra'] ? number_format($x['extra'], 2) : '' ?></td>
    <td><?= number_format($x['a12'], 2) ?></td><td><?= number_format($x['a6'], 2) ?></td><td><?= number_format($x['a3'], 2) ?></td><td><?= number_format($x['latest'], 2) ?></td><td><?= number_format($x['hi'], 2) ?></td><td><?= number_format($x['lo'], 2) ?></td><td><?= (int)$x['active'] ?></td><td><?= $x['cv'] === null ? '' : number_format($x['cv'], 2) ?></td>
    <td class="<?= $x['mom'] !== null && $x['mom'] < 0 ? 'neg' : 'pos' ?>"><?= hr_p($x['mom']) ?></td><td><b><?= number_format($x['base'], 2) ?></b></td><td><?= number_format($x['r3'], 2) ?></td><td class="<?= $x['trend'] !== null && $x['trend'] < 0 ? 'neg' : 'pos' ?>"><?= hr_p($x['trend']) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td class="l" colspan="<?= 5 + count($months) + 13 ?>" style="padding:26px;color:#9ca3af;text-align:center">No outlets for these filters.</td></tr><?php endif; ?></tbody>
  <?php if ($rows): ?><tfoot><tr><td class="l s1" style="z-index:4">TOTAL</td><td class="l s2" style="z-index:4"><?= number_format(count($rows)) ?> outlets</td><td></td><td></td><td></td><?php foreach ($months as $m): ?><td><?= number_format($tot[$m] ?? 0, 2) ?></td><?php endforeach; ?><td><?= number_format($ex, 2) ?></td><td colspan="12"></td></tr></tfoot><?php endif; ?></table></div>
</div>
<?php include 'footer.php'; ?>
