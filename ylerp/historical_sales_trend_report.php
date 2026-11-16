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
    /* same layout as the planning model's "3 Historical Sales Trends" sheet: title · note · header (row 4) · one row per outlet · live formulas · colour scale on the months · Assumptions sheet for the baseline weights */
    require_once __DIR__ . '/rbac_xlsx.php'; while (ob_get_level()) ob_end_clean();
    $X = new MiniXlsx(); $sh = $X->sheet('Historical Sales Trends'); $as = $X->sheet('Assumptions');
    $N = count($months); $c1 = 6; $cl = 5 + $N; $cx = 6 + $N; $cs = 7 + $N;                      // first month col, last month col, to-date col, first stat col
    $L = fn($i) => MiniXlsx::col($i);
    $FM = ['font' => 'Arial']; $NUM = '#,##0;\(#,##0\);"-"'; $PCT = '0.0%;\(0.0%\);"-"';
    $basisTxt = $S['basis'] === 'billvalue' ? 'Bill Value + Total Discount' : 'Total Discount + Final Bill Amount (to be collected)';
    $rg0 = hs_range($months[0], $S['start_day']); $rgN = hs_range(end($months), $S['start_day']);
    $sh->set(1, 1, 'Historical Sales Trends — sales = ' . $basisTxt . ', per company cycle (day ' . $S['start_day'] . ' of the previous month to day ' . max(1, $S['start_day'] - 1) . '), LKR', $FM + ['b' => 1, 'sz' => 14, 'color' => '1F3864']);
    $sh->set(2, 1, 'Cycle columns: ' . hs_label($months[0]) . ' = ' . date('d-M-y', strtotime($rg0[0])) . '→' . date('d-M-y', strtotime($rg0[1])) . ' … ' . hs_label(end($months)) . ' = ' . date('d-M-y', strtotime($rgN[0])) . '→' . date('d-M-y', strtotime($rgN[1])) . '. Grey header = cycle still open, excluded from averages. Source: Analysis secondary import before ' . $S['cutover'] . ', our secondary import from that date. Blank = before first purchase (not observed); 0 = no purchase. Averages and baseline are live formulas using the weights on the Assumptions sheet.', $FM + ['sz' => 9, 'color' => '595959']);
    $HS = $FM + ['b' => 1, 'color' => 'FFFFFF', 'bg' => '1F3864', 'h' => 'center', 'wrap' => 1];
    $heads = ['HUL', 'Customer_Name', 'Sales_SP_Name', 'Beat', 'Secondary_Channel']; foreach ($months as $m) $heads[] = hs_label($m);
    $heads[] = hs_label($D['extra']) . ' to date (' . date('d M', strtotime($exRange[0])) . '–' . ($D['last_date'] ? date('d M', strtotime($D['last_date'])) : '…') . ')';
    $m3 = hs_label($months[$N - 3]) . '–' . hs_label($months[$N - 1]);
    foreach (['Avg ' . $N . 'M', 'Avg 6M', 'Avg 3M', 'Latest Month', 'Highest Month', 'Lowest Month', 'Active Months', 'Volatility CV', 'MoM Growth', 'Baseline (LKR)', 'Rolling 3M (' . $m3 . ')', 'Trend vs prior ' . ($N - 3) . 'M'] as $h) $heads[] = $h;
    foreach ($heads as $i => $h) $sh->set(4, $i + 1, $h, $i + 1 === $cx ? array_merge($HS, ['bg' => '7F7F7F']) : $HS);
    $sh->heights[4] = 42;
    $rg = fn($a, $b, $r) => $L($a) . $r . ':' . $L($b) . $r;
    $r = 5;
    foreach ($rows as $x) {
        $c = 1; foreach ([$x['code'], $x['name'], $x['sp'], $x['beat'], $x['channel']] as $v) $sh->set($r, $c++, $v, $FM);
        foreach ($months as $m) { $v = $x['v'][$m]; $sh->set($r, $c++, $v === null ? '' : $v, $FM + ['nfs' => $NUM]); }
        $sh->set($r, $c++, $x['extra'] ?: '', $FM + ['nfs' => $NUM]);
        $all = $rg($c1, $cl, $r); $l6 = $rg($cl - 5, $cl, $r); $l3 = $rg($cl - 2, $cl, $r); $old = $rg($c1, $cl - 3, $r);
        $A = "Assumptions!\$C\$5*" . $L($cs) . $r . "+Assumptions!\$C\$6*" . $L($cs + 1) . $r . "+Assumptions!\$C\$7*" . $L($cs + 2) . $r . "+Assumptions!\$C\$8*" . $L($cs + 3) . $r;
        $sh->set($r, $cs,      round($x['a12'], 2), $FM + ['nfs' => $NUM, 'f' => "IFERROR(AVERAGE($all),0)"]);
        $sh->set($r, $cs + 1,  round($x['a6'], 2),  $FM + ['nfs' => $NUM, 'f' => "IFERROR(AVERAGE($l6),0)"]);
        $sh->set($r, $cs + 2,  round($x['a3'], 2),  $FM + ['nfs' => $NUM, 'f' => "IFERROR(AVERAGE($l3),0)"]);
        $sh->set($r, $cs + 3,  round($x['latest'], 2), $FM + ['nfs' => $NUM, 'f' => 'N(' . $L($cl) . $r . ')']);
        $sh->set($r, $cs + 4,  round($x['hi'], 2),  $FM + ['nfs' => $NUM, 'f' => "IF(COUNT($all)=0,0,MAX($all))"]);
        $sh->set($r, $cs + 5,  round($x['lo'], 2),  $FM + ['nfs' => $NUM, 'f' => "IF(COUNT($all)=0,0,MIN($all))"]);
        $sh->set($r, $cs + 6,  (int)$x['active'],   $FM + ['nfs' => '0', 'f' => "COUNTIF($all,\">0\")"]);
        $sh->set($r, $cs + 7,  $x['cv'] === null ? '' : round($x['cv'], 4), $FM + ['nfs' => '0.00', 'f' => "IFERROR(STDEV($all)/AVERAGE($all),\"\")"]);
        $sh->set($r, $cs + 8,  $x['mom'] === null ? '' : round($x['mom'], 4), $FM + ['nfs' => $PCT, 'f' => 'IF(N(' . $L($cl - 1) . $r . ')>0,N(' . $L($cl) . $r . ')/' . $L($cl - 1) . $r . '-1,"")']);
        $sh->set($r, $cs + 9,  round($x['base'], 2), $FM + ['b' => 1, 'nfs' => $NUM, 'f' => "MAX(0,$A)"]);
        $sh->set($r, $cs + 10, round($x['r3'], 2),  $FM + ['nfs' => $NUM, 'f' => "SUM($l3)"]);
        $sh->set($r, $cs + 11, $x['trend'] === null ? '' : round($x['trend'], 4), $FM + ['nfs' => $PCT, 'f' => 'IFERROR(' . $L($cs + 2) . $r . "/AVERAGE($old)-1,\"\")"]);
        $r++;
    }
    foreach ([18, 28, 24, 22, 22] as $i => $wd) $sh->widths[$i + 1] = $wd; for ($i = $c1; $i <= $cl; $i++) $sh->widths[$i] = 11; $sh->widths[$cx] = 27;
    foreach ([11, 11, 11, 11, 11.5, 11, 11, 11, 10, 13, 18, 15.3] as $i => $wd) $sh->widths[$cs + $i] = $wd;
    $sh->freeze = [4, 2]; $sh->filter = 'A4:' . $L(count($heads)) . max(5, $r - 1);
    if ($r > 5) $sh->condfmt[] = [$L($c1) . '5:' . $L($cl) . ($r - 1), 'FFFFFF', '63BE7B', 95];
    /* Assumptions sheet — the weights the Baseline formulas read (C5:C8) */
    $BL = $FM + ['color' => '0000FF', 'bg' => 'FFF2CC', 'nfs' => '0.00'];
    $as->set(1, 1, 'Assumptions & Methodology', $FM + ['b' => 1, 'sz' => 14, 'color' => '1F3864']); $as->set(2, 1, 'Blue = editable. Changing the weights recalculates the Baseline column on the Historical Sales Trends sheet.', $FM + ['sz' => 9, 'color' => '595959']);
    $as->set(4, 1, 'FORECAST WEIGHTS', $FM + ['b' => 1, 'color' => '1F3864']);
    foreach ([['12-month average weight', $S['w12']], ['6-month average weight', $S['w6']], ['3-month average weight', $S['w3']], ['Latest month weight', $S['wl']]] as $i => [$lab, $w]) { $as->set(5 + $i, 1, $lab, $FM); $as->set(5 + $i, 3, (float)$w, $BL); }
    $as->set(9, 1, 'Check: weights sum', $FM); $as->set(9, 3, round($S['w12'] + $S['w6'] + $S['w3'] + $S['wl'], 4), $FM + ['nfs' => '0.00', 'f' => 'SUM(C5:C8)']);
    $as->set(11, 1, 'SALES DEFINITION', $FM + ['b' => 1, 'color' => '1F3864']); $as->set(12, 1, 'Sales of a bill = ' . $basisTxt . '.', $FM);
    $as->set(13, 1, 'SALES MONTH = company cycle: month M runs from day ' . $S['start_day'] . ' of the previous month to day ' . max(1, $S['start_day'] - 1) . ' of M.', $FM);
    $as->set(14, 1, 'SOURCES: bills before ' . $S['cutover'] . ' — Analysis > Import Secondary Invoices (Invoice Wise Sales); from ' . $S['cutover'] . ' — our own secondary invoice import.', $FM);
    $as->set(15, 1, 'Months before an outlet\'s first purchase are blank (not observed) and excluded from averages; zero months after the first purchase count as zero.', $FM);
    $as->widths[1] = 34; $as->widths[2] = 4; $as->widths[3] = 12;
    $tmp = tempnam(sys_get_temp_dir(), 'hs'); $X->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="historical_sales_trends_' . date('Ymd_His') . '.xlsx"'); header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
/* ── on-screen helpers: heat-map scale, sparkline, status chip ── */
$allv = []; foreach ($rows as $x) foreach ($months as $m) if (($x['v'][$m] ?? 0) > 0) $allv[] = $x['v'][$m];
sort($allv); $heatMax = $allv ? $allv[(int)floor(count($allv) * 0.92)] : 1; if ($heatMax <= 0) $heatMax = 1;
function hr_spark(array $vals, array $months) {
    $pts = []; $max = 0; foreach ($months as $m) $max = max($max, (float)($vals[$m] ?? 0)); if ($max <= 0) return '<span class="z">—</span>';
    $n = count($months); $w = 92; $h = 26; $i = 0; $path = []; $last = null;
    foreach ($months as $m) { $v = $vals[$m]; if ($v !== null) { $x = round($i * $w / max(1, $n - 1), 1); $y = round($h - 3 - ($v / $max) * ($h - 6), 1); $path[] = $x . ',' . $y; $last = [$x, $y]; } $i++; }
    if (count($path) < 2) return '<span class="z">new</span>';
    return '<svg class="sp" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '"><polyline fill="none" stroke="#6366f1" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" points="' . implode(' ', $path) . '"/><circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="2.6" fill="#f43f5e"/></svg>';
}
function hr_status($x) {
    if ($x['r3'] <= 0) return ['Dormant', 'dm']; if ($x['active'] < 3) return ['New', 'nw'];
    if ($x['trend'] !== null && $x['trend'] > 0.10) return ['Growing', 'gr']; if ($x['trend'] !== null && $x['trend'] < -0.10) return ['Declining', 'dc']; return ['Stable', 'st'];
}
function hr_pill($v) { if ($v === null) return '<span class="z">—</span>'; $c = $v > 0.005 ? 'up' : ($v < -0.005 ? 'dn' : 'fl'); return '<span class="pl ' . $c . '">' . ($v > 0.005 ? '▲' : ($v < -0.005 ? '▼' : '■')) . ' ' . number_format(abs($v) * 100, 1) . '%</span>'; }
function hr_chip($t) { $pal = ['#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', '#14b8a6', '#ef4444']; $c = $pal[crc32($t) % count($pal)]; return '<span class="chip" style="--c:' . $c . '">' . hr_h($t) . '</span>'; }
$maxTot = max(1, max(array_map(fn($m) => $tot[$m] ?? 0, $months)));
ob_end_flush();
include 'header.php';
?>
<style>
.hs{padding:16px;font-family:'Inter',system-ui,sans-serif;color:#0f172a}
.hs .hero{background:linear-gradient(120deg,#0f172a 0%,#1e3a8a 55%,#6366f1 100%);color:#fff;border-radius:16px;padding:20px 24px;margin-bottom:14px;position:relative;overflow:hidden;box-shadow:0 10px 30px -12px rgba(30,58,138,.6)}
.hs .hero:after{content:"";position:absolute;right:-60px;top:-60px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.18),transparent 70%)}
.hs .hero h2{margin:0 0 4px;font-size:24px;font-weight:800;letter-spacing:-.3px}.hs .hero p{margin:0;font-size:13px;opacity:.85;max-width:1100px;line-height:1.55}.hs .hero a{color:#c7d2fe}
.hs .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px 16px;margin-bottom:14px}.hs .row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.hs .fld{display:flex;flex-direction:column;gap:3px}.hs .fld label{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
.hs input,.hs select{padding:8px 10px;border:1.5px solid #cbd5e1;border-radius:10px;font:inherit;font-size:13px;max-width:220px;background:#fff}.hs input:focus,.hs select:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.18)}
.hs .btn{padding:9px 16px;border:none;border-radius:10px;background:linear-gradient(135deg,#1e3a8a,#4f46e5);color:#fff;font:600 13px inherit;cursor:pointer;text-decoration:none;display:inline-flex;gap:6px;align-items:center;box-shadow:0 4px 12px -4px rgba(79,70,229,.6)}.hs .btn.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe;box-shadow:none}.hs .btn.g{background:linear-gradient(135deg,#059669,#16a34a)}
.hs .kp{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr));gap:12px;margin-bottom:14px}.hs .k{border-radius:14px;padding:12px 16px;color:#fff;position:relative;overflow:hidden}.hs .k i{position:absolute;right:12px;top:10px;font-size:26px;opacity:.28}.hs .k span{font-size:11px;text-transform:uppercase;font-weight:700;display:block;opacity:.85;letter-spacing:.4px}.hs .k b{font-size:21px;white-space:nowrap}
.hs .k1{background:linear-gradient(135deg,#4f46e5,#7c3aed)}.hs .k2{background:linear-gradient(135deg,#0ea5e9,#2563eb)}.hs .k3{background:linear-gradient(135deg,#10b981,#059669)}.hs .k4{background:linear-gradient(135deg,#f59e0b,#ea580c)}.hs .k5{background:linear-gradient(135deg,#ec4899,#be185d)}
.hs .bars{display:flex;align-items:flex-end;gap:8px;height:120px;padding-top:6px}.hs .bar{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;min-width:0}.hs .bar div{width:100%;border-radius:8px 8px 3px 3px;background:linear-gradient(180deg,#818cf8,#4f46e5);min-height:3px;transition:.2s}.hs .bar:hover div{filter:brightness(1.15)}.hs .bar small{font-size:10.5px;color:#64748b;margin-top:4px;white-space:nowrap}.hs .bar em{font-style:normal;font-size:10px;color:#334155;font-weight:700;margin-bottom:2px;white-space:nowrap}.hs .bar.cur div{background:repeating-linear-gradient(45deg,#cbd5e1,#cbd5e1 4px,#e2e8f0 4px,#e2e8f0 8px)}
.hs .tw{overflow:auto;border:1px solid #e2e8f0;border-radius:14px;background:#fff;max-height:74vh;box-shadow:0 8px 24px -14px rgba(15,23,42,.35)}.hs table{border-collapse:separate;border-spacing:0;font-size:12.5px;width:max-content;min-width:100%}
.hs th{background:linear-gradient(180deg,#1e3a8a,#1e2f6e);color:#fff;padding:10px 10px;text-align:right;position:sticky;top:0;z-index:2;white-space:nowrap;font-size:11.5px;letter-spacing:.3px}.hs th.l,.hs td.l{text-align:left}.hs th.g{background:#64748b}.hs th.st2{background:linear-gradient(180deg,#4f46e5,#4338ca)}
.hs td{padding:5px 9px;border-bottom:1px solid #f1f5f9;text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.hs tbody tr:nth-child(even) td{background-color:#fbfcff}
.hs td.s1,.hs th.s1{position:sticky;left:0;z-index:3;background:#fff;min-width:150px;font-weight:700;color:#1e3a8a}.hs th.s1{background:#1e3a8a;color:#fff}.hs td.s2,.hs th.s2{position:sticky;left:150px;z-index:3;background:#fff;min-width:210px;max-width:210px;overflow:hidden;text-overflow:ellipsis;box-shadow:6px 0 8px -6px rgba(15,23,42,.25)}.hs th.s2{background:#1e3a8a}.hs tbody tr:nth-child(even) td.s1,.hs tbody tr:nth-child(even) td.s2{background:#fbfcff}.hs tbody tr:hover td{background:#eef2ff!important}
.hs td.m{border-radius:0}.hs td.z0{color:#cbd5e1}.hs td.nb{background:repeating-linear-gradient(135deg,#fff,#fff 5px,#f1f5f9 5px,#f1f5f9 10px)!important}.hs td.ex{background:#f1f5f9!important;color:#64748b;font-style:italic}.hs .z{color:#cbd5e1}.hs svg.sp{display:block;margin:auto}
.hs .chip{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;color:var(--c);background:color-mix(in srgb,var(--c) 14%,#fff);border:1px solid color-mix(in srgb,var(--c) 35%,#fff)}
.hs .stt{display:inline-block;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:800;letter-spacing:.3px}.hs .stt.gr{background:#dcfce7;color:#166534}.hs .stt.st{background:#e0e7ff;color:#3730a3}.hs .stt.dc{background:#ffedd5;color:#9a3412}.hs .stt.dm{background:#fee2e2;color:#991b1b}.hs .stt.nw{background:#cffafe;color:#155e75}
.hs .pl{display:inline-block;padding:1px 8px;border-radius:99px;font-size:11.5px;font-weight:700}.hs .pl.up{background:#dcfce7;color:#166534}.hs .pl.dn{background:#fee2e2;color:#991b1b}.hs .pl.fl{background:#f1f5f9;color:#475569}
.hs .base{display:inline-block;padding:2px 10px;border-radius:8px;background:linear-gradient(135deg,#fef3c7,#fde68a);color:#78350f;font-weight:800}
.hs tfoot td{background:linear-gradient(180deg,#fde68a,#fcd34d);font-weight:800;position:sticky;bottom:0;color:#451a03}
</style>
<div class="hs">
  <div class="hero"><h2><i class="fa-solid fa-chart-line"></i> Historical Sales Trends</h2>
    <p>Sales per outlet (HUL code) per company cycle: <b><?= hr_h(hs_label($months[0])) ?></b> = <?= hr_h(hs_range($months[0], $S['start_day'])[0]) ?> → <?= hr_h(hs_range($months[0], $S['start_day'])[1]) ?> … <b><?= hr_h(hs_label(end($months))) ?></b> = <?= hr_h(hs_range(end($months), $S['start_day'])[0]) ?> → <?= hr_h(hs_range(end($months), $S['start_day'])[1]) ?>. Bills before <b><?= hr_h($S['cutover']) ?></b> come from the Analysis secondary import, from that date from our own secondary import. Sales value = <b><?= $S['basis'] === 'billvalue' ? 'Bill Value + Total Discount' : 'Total Discount + Final Bill Amount (to be collected)' ?></b>. <a href="historical_sales_settings.php"><i class="fa-solid fa-sliders"></i> Edit the sales range</a></p></div>
  <?php if (!$D['rows']): ?><div class="card" style="color:#92400e;background:#fffbeb">No sales found for this range yet — import the Invoice Wise Sales file (Analysis → Import Secondary Invoices) and/or our secondary invoices, or check the <a href="historical_sales_settings.php">settings</a>.</div><?php endif; ?>
  <form method="get" class="card"><div class="row">
    <div class="fld"><label>Search</label><input type="search" name="q" value="<?= hr_h($f_q) ?>" placeholder="HUL, customer, SP, beat"></div>
    <div class="fld"><label>Channel</label><select name="channel"><option value="">All</option><?php foreach ($chs as $c => $_): ?><option <?= $f_ch === $c ? 'selected' : '' ?>><?= hr_h($c) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Sales person</label><select name="sp"><option value="">All</option><?php foreach ($sps as $c => $_): ?><option <?= $f_sp === $c ? 'selected' : '' ?>><?= hr_h($c) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Beat</label><select name="beat"><option value="">All</option><?php foreach ($beats as $c => $_): ?><option <?= $f_beat === $c ? 'selected' : '' ?>><?= hr_h($c) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Show</label><select name="show"><option value="">All outlets</option><option value="active" <?= $f_show === 'active' ? 'selected' : '' ?>>Bought in last 3 cycles</option><option value="dormant" <?= $f_show === 'dormant' ? 'selected' : '' ?>>No purchase in last 3 cycles</option></select></div>
    <button class="btn" type="submit"><i class="fa-solid fa-filter"></i> Show</button><a class="btn o" href="historical_sales_trend_report.php">Reset</a>
    <a class="btn g" data-no-loader href="?<?= hr_h(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a></div></form>
  <div class="kp"><div class="k k1"><i class="fa-solid fa-store"></i><span>Outlets</span><b><?= number_format(count($rows)) ?></b></div><div class="k k2"><i class="fa-solid fa-sack-dollar"></i><span>Total sales (<?= count($months) ?> cycles)</span><b>Rs. <?= number_format($grand, 2) ?></b></div><div class="k k3"><i class="fa-solid fa-calendar-check"></i><span>Latest cycle <?= hr_h(hs_label(end($months))) ?></span><b>Rs. <?= number_format($tot[end($months)] ?? 0, 2) ?></b></div><div class="k k4"><i class="fa-solid fa-hourglass-half"></i><span><?= hr_h($exLbl) ?> (<?= hr_h(date('d M', strtotime($exRange[0]))) ?> – <?= hr_h($lastTxt) ?>)</span><b>Rs. <?= number_format($ex, 2) ?></b></div><div class="k k5"><i class="fa-solid fa-file-invoice"></i><span>Bills used</span><b style="font-size:15px"><?= number_format($D['src']['analysis']) ?> analysis · <?= number_format($D['src']['system']) ?> system</b></div></div>
  <?php if ($rows): ?><div class="card"><div style="font-weight:800;margin-bottom:2px"><i class="fa-solid fa-chart-column" style="color:#4f46e5"></i> Sales by cycle</div><div class="bars">
    <?php foreach ($months as $m): $v = $tot[$m] ?? 0; ?><div class="bar" title="<?= hr_h(hs_label($m) . ': ' . number_format($v, 2)) ?>"><em><?= number_format($v / 1000000, 1) ?>M</em><div style="height:<?= max(2, round($v / $maxTot * 88)) ?>%"></div><small><?= hr_h(hs_label($m)) ?></small></div><?php endforeach; ?>
    <div class="bar cur" title="<?= hr_h($exLbl . ': ' . number_format($ex, 2)) ?>"><em><?= number_format($ex / 1000000, 1) ?>M</em><div style="height:<?= max(2, round($ex / $maxTot * 88)) ?>%"></div><small>to date</small></div></div></div><?php endif; ?>
  <div class="tw"><table><thead><tr><th class="l s1">HUL</th><th class="l s2">Customer Name</th><th class="l">Sales SP Name</th><th class="l">Beat</th><th class="l">Secondary Channel</th><th class="st2" style="text-align:center">Status</th><th class="st2" style="text-align:center">Trend</th>
    <?php foreach ($months as $m): $rg = hs_range($m, $S['start_day']); ?><th title="<?= hr_h($rg[0] . ' → ' . $rg[1]) ?>"><?= hr_h(hs_label($m)) ?></th><?php endforeach; ?><th class="g" title="<?= hr_h($exRange[0]) ?> → <?= hr_h($lastTxt) ?>"><?= hr_h($exLbl) ?></th>
    <th>Avg <?= count($months) ?>M</th><th>Avg 6M</th><th>Avg 3M</th><th>Latest</th><th>Highest</th><th>Lowest</th><th>Active</th><th>CV</th><th>MoM</th><th>Baseline</th><th>Rolling 3M</th><th>vs prior</th></tr></thead><tbody>
  <?php foreach ($rows as $x): [$stT, $stC] = hr_status($x); ?><tr><td class="l s1"><?= hr_h($x['code']) ?></td><td class="l s2" title="<?= hr_h($x['name']) ?>"><?= hr_h($x['name']) ?></td><td class="l"><?= hr_h($x['sp']) ?></td><td class="l"><?= hr_h($x['beat']) ?></td><td class="l"><?= hr_chip($x['channel']) ?></td>
    <td style="text-align:center"><span class="stt <?= $stC ?>"><?= $stT ?></span></td><td><?= hr_spark($x['v'], $months) ?></td>
    <?php foreach ($months as $m): $v = $x['v'][$m]; if ($v === null) echo '<td class="m nb"></td>'; elseif ($v <= 0) echo '<td class="m z0">–</td>'; else { $a = min(1, $v / $heatMax); echo '<td class="m" style="background-color:rgba(34,197,94,' . round(0.10 + $a * 0.55, 2) . ');color:' . ($a > 0.75 ? '#052e16;font-weight:700' : '#14532d') . '">' . number_format($v, 0) . '</td>'; } endforeach; ?><td class="ex"><?= $x['extra'] ? number_format($x['extra'], 0) : '' ?></td>
    <td><?= number_format($x['a12'], 0) ?></td><td><?= number_format($x['a6'], 0) ?></td><td><?= number_format($x['a3'], 0) ?></td><td><?= number_format($x['latest'], 0) ?></td><td><?= number_format($x['hi'], 0) ?></td><td><?= number_format($x['lo'], 0) ?></td><td><?= (int)$x['active'] ?></td><td><?= $x['cv'] === null ? '<span class="z">—</span>' : number_format($x['cv'], 2) ?></td>
    <td><?= hr_pill($x['mom']) ?></td><td><span class="base"><?= number_format($x['base'], 0) ?></span></td><td><?= number_format($x['r3'], 0) ?></td><td><?= hr_pill($x['trend']) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td class="l" colspan="<?= 7 + count($months) + 13 ?>" style="padding:26px;color:#9ca3af;text-align:center">No outlets for these filters.</td></tr><?php endif; ?></tbody>
  <?php if ($rows): ?><tfoot><tr><td class="l s1" style="z-index:4">TOTAL</td><td class="l s2" style="z-index:4"><?= number_format(count($rows)) ?> outlets</td><td colspan="5"></td><?php foreach ($months as $m): ?><td><?= number_format($tot[$m] ?? 0, 0) ?></td><?php endforeach; ?><td><?= number_format($ex, 0) ?></td><td colspan="12"></td></tr></tfoot><?php endif; ?></table></div>
</div>
<?php include 'footer.php'; ?>
