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
/** number format showing the amount and the bill count in brackets: 123,456 (15); distinct counts are capped so the workbook stays within Excel's number-format limit */
function hr_nf($n, array &$seen) { $seen[$n] = 1; return '#,##0" (' . (int)$n . ')";\(#,##0\)" (' . (int)$n . ')";"-"'; }
function hr_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function hr_n($v) { return $v === null ? '' : number_format((float)$v, 2); }
function hr_p($v) { return $v === null ? '' : number_format($v * 100, 1) . '%'; }

/* ── inline edit of a customer's pay mode / policy (credit) days / credit limit ── */
if (($_POST['action'] ?? '') === 'update_customer') {
    while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    if (function_exists('rbac_can') && !rbac_can('edit')) { echo json_encode(['ok' => false, 'msg' => 'You do not have the Edit right on this page.']); exit; }
    $code = strtoupper(trim((string)($_POST['code'] ?? ''))); if ($code === '') { echo json_encode(['ok' => false, 'msg' => 'No customer code.']); exit; }
    $cols = []; $cr = mysqli_query($conn, "SHOW COLUMNS FROM customers"); while ($cr && ($cx = mysqli_fetch_assoc($cr))) $cols[$cx['Field']] = 1;
    if (!isset($cols['credit_limit'])) { mysqli_query($conn, "ALTER TABLE customers ADD COLUMN credit_limit DECIMAL(15,2) NULL"); }
    $set = []; $out = [];
    if (isset($_POST['payment_mode'])) { $v = strtolower(trim((string)$_POST['payment_mode'])); if (!in_array($v, ['cash', 'credit', 'cheque', ''], true)) { echo json_encode(['ok' => false, 'msg' => 'Pay mode must be cash, credit or cheque.']); exit; } $set[] = "payment_mode = " . ($v === '' ? 'NULL' : "'" . mysqli_real_escape_string($conn, $v) . "'"); $out['pr'] = $v; $out['pm'] = ucfirst($v); }
    if (isset($_POST['credit_days'])) { $v = trim((string)$_POST['credit_days']); if (!ctype_digit($v) || (int)$v > 365) { echo json_encode(['ok' => false, 'msg' => 'Policy days must be a whole number from 0 to 365.']); exit; } $set[] = 'credit_days = ' . (int)$v; $out['d'] = (int)$v; }
    if (isset($_POST['credit_limit'])) { $n = str_replace(',', '', trim((string)$_POST['credit_limit'])); if ($n === '') $n = '0'; if (!is_numeric($n) || (float)$n < 0) { echo json_encode(['ok' => false, 'msg' => 'Credit limit must be a number (0 or more).']); exit; } $set[] = 'credit_limit = ' . round((float)$n, 2); $out['cl'] = round((float)$n, 2); }
    if (!$set) { echo json_encode(['ok' => false, 'msg' => 'Nothing to save.']); exit; }
    $ok = mysqli_query($conn, "UPDATE customers SET " . implode(', ', $set) . " WHERE UPPER(TRIM(t_code)) = '" . mysqli_real_escape_string($conn, $code) . "'");
    echo json_encode(['ok' => (bool)$ok, 'msg' => $ok ? 'Saved' : 'Could not save.'] + $out); exit;
}
$canEdit = function_exists('rbac_can') ? (bool)rbac_can('edit') : true;
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
    /* Workbook in the style of the planning model: 1 Dashboard · 2 Historical Sales Trends (one row per outlet, live formulas, total row) · 3 Assumptions.
       Arial, navy 1F3864 headers, hidden gridlines, navy tab colours, frozen panes, green colour scale on the months, landscape print set-up. */
    require_once __DIR__ . '/rbac_xlsx.php'; while (ob_get_level()) ob_end_clean();
    $X = new MiniXlsx(); $db = $X->sheet('1 Dashboard'); $sh = $X->sheet('2 Historical Sales Trends'); $as = $X->sheet('3 Assumptions');
    foreach ([$db, $sh, $as] as $w) { $w->tab = '1F3864'; $w->landscape = true; }
    $T = "'2 Historical Sales Trends'!";
    $N = count($months); $c1 = 9; $cl = 8 + $N; $cx = 9 + $N; $cs = 10 + $N; $cst = $cs + 12;            // first month · last month · to-date · first stat · Status column
    $L = fn($i) => MiniXlsx::col($i);
    $FM = ['font' => 'Arial']; $NUM = '#,##0;\(#,##0\);"-"'; $PCT = '0.0%;\(0.0%\);"-"'; $NAVY = '1F3864';
    $basisTxt = $S['basis'] === 'billvalue' ? 'Bill Value + Total Discount' : 'Total Discount + Final Bill Amount (to be collected)';
    $rg0 = hs_range($months[0], $S['start_day']); $rgN = hs_range(end($months), $S['start_day']);
    $cyc = 'day ' . $S['start_day'] . ' of the previous month to day ' . max(1, $S['start_day'] - 1);
    $n = count($rows); $r1 = 5; $rL = $r1 + max(0, $n - 1); $rT = $rL + 2;                           // first data row · last data row · TOTAL row (one blank row between, as in the model)
    $HS = $FM + ['b' => 1, 'color' => 'FFFFFF', 'bg' => $NAVY, 'h' => 'center', 'wrap' => 1, 'border' => 1];
    $rg = fn($a, $b, $r) => $L($a) . $r . ':' . $L($b) . $r;
    $statusOf = fn($x) => hr_status($x)[0];

    /* ───── sheet 2 ───── */
    $sh->set(1, 1, 'Historical Sales Trends — sales = ' . $basisTxt . ', per company cycle (' . $cyc . '), LKR', $FM + ['b' => 1, 'sz' => 14, 'color' => $NAVY]);
    $sh->set(2, 1, 'Cycle columns: ' . hs_label($months[0]) . ' = ' . date('d-M-y', strtotime($rg0[0])) . '→' . date('d-M-y', strtotime($rg0[1])) . ' … ' . hs_label(end($months)) . ' = ' . date('d-M-y', strtotime($rgN[0])) . '→' . date('d-M-y', strtotime($rgN[1])) . '. Grey header = cycle still open, excluded from averages. Source: Analysis secondary import before ' . $S['cutover'] . ', our secondary import from that date. Blank = before first purchase (not observed); 0 = no purchase. Averages, baseline and status are live formulas (weights on sheet 3).', $FM + ['sz' => 9, 'color' => '595959']);
    $heads = ['HUL', 'Customer_Name', 'Sales_SP_Name', 'Beat', 'Secondary_Channel', 'Customer_Pay_Mode', 'Policy_Days', 'Credit_Limit']; foreach ($months as $m) $heads[] = hs_label($m);
    $heads[] = hs_label($D['extra']) . ' to date (' . date('d M', strtotime($exRange[0])) . '–' . ($D['last_date'] ? date('d M', strtotime($D['last_date'])) : '…') . ')';
    $m3 = hs_label($months[$N - 3]) . '–' . hs_label($months[$N - 1]);
    foreach (['Avg ' . $N . 'M', 'Avg 6M', 'Avg 3M', 'Latest Month', 'Highest Month', 'Lowest Month', 'Active Months', 'Volatility CV', 'MoM Growth', 'Baseline (LKR)', 'Rolling 3M (' . $m3 . ')', 'Trend vs prior ' . ($N - 3) . 'M', 'Status'] as $h) $heads[] = $h;
    foreach ($heads as $i => $h) $sh->set(4, $i + 1, $h, $i + 1 === $cx ? array_merge($HS, ['bg' => '7F7F7F']) : $HS);
    $sh->heights[4] = 42;
    $nfSeen = []; $tot12 = array_fill_keys($months, 0.0); $sumA = [0, 0, 0, 0, 0, 0]; $r = $r1;
    foreach ($rows as $x) {
        $c = 1; foreach ([$x['code'], $x['name'], $x['sp'], $x['beat'], $x['channel'], $x['pm']] as $v) $sh->set($r, $c++, $v, $FM); $sh->set($r, $c++, $x['days'] === null ? '' : (int)$x['days'], $FM + ['h' => 'center', 'nfs' => '0']); $sh->set($r, $c++, $x['climit'] === null ? '' : (float)$x['climit'], $FM + ['nfs' => $NUM]);
        foreach ($months as $m) { $v = $x['v'][$m]; $bn = (int)($x['n'][$m] ?? 0); $sh->set($r, $c++, $v === null ? '' : $v, $FM + ['nfs' => ($v !== null && (float)$v != 0 && $bn > 0 && (isset($nfSeen[$bn]) || count($nfSeen) < 200)) ? hr_nf($bn, $nfSeen) : $NUM]); }
        $bn = (int)$x['extra_n']; $sh->set($r, $c++, $x['extra'] ?: '', $FM + ['nfs' => ($x['extra'] && $bn > 0 && (isset($nfSeen[$bn]) || count($nfSeen) < 200)) ? hr_nf($bn, $nfSeen) : $NUM]);
        $all = $rg($c1, $cl, $r); $l6 = $rg($cl - 5, $cl, $r); $l3 = $rg($cl - 2, $cl, $r); $old = $rg($c1, $cl - 3, $r);
        $A = "'3 Assumptions'!\$C\$5*" . $L($cs) . $r . "+'3 Assumptions'!\$C\$6*" . $L($cs + 1) . $r . "+'3 Assumptions'!\$C\$7*" . $L($cs + 2) . $r . "+'3 Assumptions'!\$C\$8*" . $L($cs + 3) . $r;
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
        $sh->set($r, $cst,     $statusOf($x), $FM + ['h' => 'center', 'f' => 'IF(' . $L($cs + 10) . $r . '<=0,"Dormant",IF(' . $L($cs + 6) . $r . '<3,"New",IF(N(' . $L($cs + 11) . $r . ')>0.1,"Growing",IF(AND(' . $L($cs + 11) . $r . '<>"",N(' . $L($cs + 11) . $r . ')<-0.1),"Declining","Stable"))))']);
        foreach ($months as $m) $tot12[$m] += (float)($x['v'][$m] ?? 0);
        $sumA[0] += $x['a12']; $sumA[1] += $x['a6']; $sumA[2] += $x['a3']; $sumA[3] += $x['latest']; $sumA[4] += $x['base']; $sumA[5] += $x['r3'];
        $r++;
    }
    $TB = $FM + ['b' => 1, 'bg' => 'D9E1F2', 'border' => 1, 'nfs' => $NUM, 'color' => $NAVY];
    if ($n) {
        for ($c = 1; $c <= $cst; $c++) $sh->set($rT, $c, '', $TB);
        $sh->set($rT, 1, 'TOTAL', $TB); $sh->set($rT, 2, $n . ' outlets', $TB);
        $c = $c1; foreach ($months as $m) { $sh->set($rT, $c, round($tot12[$m], 2), $TB + ['f' => 'SUM(' . $L($c) . $r1 . ':' . $L($c) . $rL . ')']); $c++; }
        $sh->set($rT, $cx, round($ex, 2), $TB + ['f' => 'SUM(' . $L($cx) . $r1 . ':' . $L($cx) . $rL . ')']);
        foreach ([0, 1, 2, 3] as $k) $sh->set($rT, $cs + $k, round($sumA[$k], 2), $TB + ['f' => 'SUM(' . $L($cs + $k) . $r1 . ':' . $L($cs + $k) . $rL . ')']);
        $sh->set($rT, $cs + 9, round($sumA[4], 2), $TB + ['f' => 'SUM(' . $L($cs + 9) . $r1 . ':' . $L($cs + 9) . $rL . ')']); $sh->set($rT, $cs + 10, round($sumA[5], 2), $TB + ['f' => 'SUM(' . $L($cs + 10) . $r1 . ':' . $L($cs + 10) . $rL . ')']);
        $sh->condfmt[] = [$L($c1) . $r1 . ':' . $L($cl) . $rL, 'FFFFFF', '63BE7B', 95];
        $sh->filter = 'A4:' . $L($cst) . $rL;
    }
    foreach ([18, 28, 24, 22, 22, 15, 11, 14] as $i => $wd) $sh->widths[$i + 1] = $wd; for ($i = $c1; $i <= $cl; $i++) $sh->widths[$i] = 14; $sh->widths[$cx] = 27;
    foreach ([11, 11, 11, 11, 11.5, 11, 11, 11, 10, 13, 18, 15.3, 12] as $i => $wd) $sh->widths[$cs + $i] = $wd;
    $sh->freeze = [4, 2]; $sh->titles = ['rows' => '4:4', 'cols' => 'A:B'];

    /* ───── sheet 1: dashboard (cross-sheet formulas, green = link to sheet 2, like the model) ───── */
    $link = $FM + ['color' => '008000', 'nfs' => $NUM]; $KV = $FM + ['b' => 1, 'sz' => 11, 'color' => $NAVY, 'nfs' => $NUM]; $KVP = $KV; $KVP['nfs'] = $PCT;
    $db->set(1, 1, 'Historical Sales Dashboard — ' . hs_label($months[0]) . ' to ' . hs_label(end($months)), $FM + ['b' => 1, 'sz' => 16, 'color' => $NAVY]);
    $db->set(2, 1, 'Sales months = company cycles (' . $cyc . '). Sales = ' . $basisTxt . '. Bills before ' . $S['cutover'] . ': Analysis secondary import; from that date: our secondary import. Every figure is a live formula on sheet 2.', $FM + ['sz' => 9, 'color' => '595959']);
    $db->set(4, 1, 'KEY FIGURES', $FM + ['b' => 1]); $db->set(4, 4, 'NET SALES & ACTIVE OUTLETS BY CYCLE', $FM + ['b' => 1]); $db->set(4, 9, 'OUTLET STATUS', $FM + ['b' => 1]);
    $mc = $L($c1) . $rT . ':' . $L($cl) . $rT; $lastC = $L($cl); $prevC = $L($cl - 1);
    $sumTot = array_sum($tot12); $lat = $tot12[end($months)] ?? 0; $prv = $tot12[$months[$N - 2]] ?? 0; $act = 0; foreach ($rows as $x) if (($x['v'][end($months)] ?? 0) > 0) $act++;
    $kf = [['Outlets in report', $n, "COUNTA({$T}\$A\$$r1:\$A\$$rL)", '#,##0'],
           ['Sales, ' . $N . ' cycles (' . date('d-M-y', strtotime($rg0[0])) . ' to ' . date('d-M-y', strtotime($rgN[1])) . ')', round($sumTot, 2), "SUM({$T}$mc)", $NUM],
           ['Average sales per cycle', round($sumTot / max(1, $N), 2), "B6/$N", $NUM],
           ['Latest cycle (' . hs_label(end($months)) . ')', round($lat, 2), "{$T}{$lastC}$rT", $NUM],
           ['Latest vs previous cycle', $prv > 0 ? round($lat / $prv - 1, 4) : '', "IFERROR(B8/{$T}{$prevC}$rT-1,\"\")", $PCT],
           ['Highest cycle', round(max($tot12 ?: [0]), 2), "MAX({$T}$mc)", $NUM], ['Lowest cycle', round(min($tot12 ?: [0]), 2), "MIN({$T}$mc)", $NUM],
           ['Outlets that bought in ' . hs_label(end($months)), $act, "COUNTIF({$T}{$lastC}\$$r1:\${$lastC}\$$rL,\">0\")", '#,##0'],
           ['Monthly baseline (weighted)', round($sumA[4], 2), "{$T}" . $L($cs + 9) . $rT, $NUM],
           [hs_label($D['extra']) . ' to date', round($ex, 2), "{$T}{$L($cx)}$rT", $NUM]];
    foreach ($kf as $i => [$lab, $val, $f, $nf]) { $db->set(5 + $i, 1, $lab, $FM); $db->set(5 + $i, 2, $val, $FM + ['b' => 1, 'sz' => 11, 'color' => $NAVY, 'nfs' => $nf, 'f' => $f]); }
    foreach (['Cycle', 'Sales (LKR)', 'Active Outlets', 'vs previous'] as $i => $h) $db->set(5, 4 + $i, $h, $HS);
    $row = 6; $prevV = null;
    foreach ($months as $i => $m) { $cc = $L($c1 + $i);
        $actN = 0; foreach ($rows as $x) if (($x['v'][$m] ?? 0) > 0) $actN++;
        $db->set($row, 4, hs_label($m), $FM + ['border' => 1]); $db->set($row, 5, round($tot12[$m], 2), $link + ['border' => 1, 'f' => "{$T}{$cc}$rT"]);
        $db->set($row, 6, $actN, $FM + ['border' => 1, 'nfs' => '#,##0', 'f' => "COUNTIF({$T}{$cc}\$$r1:\${$cc}\$$rL,\">0\")"]);
        $db->set($row, 7, $prevV > 0 ? round($tot12[$m] / $prevV - 1, 4) : '', $FM + ['border' => 1, 'nfs' => $PCT, 'f' => $i ? 'IFERROR(E' . $row . '/E' . ($row - 1) . '-1,"")' : '""']);
        $prevV = $tot12[$m]; $row++; }
    $db->set($row, 4, hs_label($D['extra']) . ' (to date)', $FM + ['border' => 1, 'color' => '7F7F7F', 'i' => 1]); $db->set($row, 5, round($ex, 2), $link + ['border' => 1, 'color' => '7F7F7F', 'f' => "{$T}{$L($cx)}$rT"]);
    $db->set($row, 6, '', $FM + ['border' => 1]); $db->set($row, 7, '', $FM + ['border' => 1]);
    $db->set($row + 1, 4, 'Total ' . $N . ' cycles', $TB); $db->set($row + 1, 5, round($sumTot, 2), $TB + ['f' => 'SUM(E6:E' . ($row - 1) . ')']); $db->set($row + 1, 6, '', $TB); $db->set($row + 1, 7, '', $TB);
    $db->condfmt[] = ['E6:E' . ($row - 1), 'FFFFFF', '63BE7B', 95];
    /* status + channel tables */
    $stat = ['Growing' => [0, 0], 'Stable' => [0, 0], 'Declining' => [0, 0], 'New' => [0, 0], 'Dormant' => [0, 0]]; $chn = [];
    foreach ($rows as $x) { $k = $statusOf($x); $stat[$k][0]++; $stat[$k][1] += $x['r3']; $chn[$x['channel']][0] = ($chn[$x['channel']][0] ?? 0) + 1; $chn[$x['channel']][1] = ($chn[$x['channel']][1] ?? 0) + $x['r3']; $chn[$x['channel']][2] = ($chn[$x['channel']][2] ?? 0) + $x['base']; }
    $sc = $L($cst); $r3c = $L($cs + 10); $bc = $L($cs + 9); $chc = $L(5);
    foreach (['Status', 'Outlets', 'Rolling 3M', 'Share of 3M'] as $i => $h) $db->set(5, 9 + $i, $h, $HS);
    $rr = 6; $sum3 = array_sum(array_column($stat, 1));
    foreach ($stat as $k => [$cn, $s3]) { $db->set($rr, 9, $k, $FM + ['border' => 1]); $db->set($rr, 10, $cn, $FM + ['border' => 1, 'nfs' => '#,##0', 'f' => "COUNTIF({$T}\${$sc}\$$r1:\${$sc}\$$rL,I$rr)"]);
        $db->set($rr, 11, round($s3, 2), $FM + ['border' => 1, 'nfs' => $NUM, 'f' => "SUMIF({$T}\${$sc}\$$r1:\${$sc}\$$rL,I$rr,{$T}\${$r3c}\$$r1:\${$r3c}\$$rL)"]);
        $db->set($rr, 12, $sum3 > 0 ? round($s3 / $sum3, 4) : '', $FM + ['border' => 1, 'nfs' => $PCT, 'f' => "IFERROR(K$rr/K\$" . (6 + count($stat)) . ',"")']); $rr++; }
    $db->set($rr, 9, 'Total', $TB); $db->set($rr, 10, $n, $TB + ['nfs' => '#,##0', 'f' => 'SUM(J6:J' . ($rr - 1) . ')']); $db->set($rr, 11, round($sum3, 2), $TB + ['f' => 'SUM(K6:K' . ($rr - 1) . ')']); $db->set($rr, 12, '', $TB);
    $cr = $rr + 3; $db->set($cr - 1, 9, 'SECONDARY CHANNEL', $FM + ['b' => 1]);
    foreach (['Channel', 'Outlets', 'Rolling 3M', 'Baseline'] as $i => $h) $db->set($cr, 9 + $i, $h, $HS);
    uasort($chn, fn($a, $b) => $b[1] <=> $a[1]); $rr = $cr + 1;
    foreach ($chn as $name => [$cn, $s3, $bs]) { $db->set($rr, 9, $name, $FM + ['border' => 1]); $db->set($rr, 10, $cn, $FM + ['border' => 1, 'nfs' => '#,##0', 'f' => "COUNTIF({$T}\${$chc}\$$r1:\${$chc}\$$rL,I$rr)"]);
        $db->set($rr, 11, round($s3, 2), $FM + ['border' => 1, 'nfs' => $NUM, 'f' => "SUMIF({$T}\${$chc}\$$r1:\${$chc}\$$rL,I$rr,{$T}\${$r3c}\$$r1:\${$r3c}\$$rL)"]);
        $db->set($rr, 12, round($bs, 2), $FM + ['border' => 1, 'nfs' => $NUM, 'f' => "SUMIF({$T}\${$chc}\$$r1:\${$chc}\$$rL,I$rr,{$T}\${$bc}\$$r1:\${$bc}\$$rL)"]); $rr++; }
    foreach ([46, 18, 3, 26, 16, 15, 12, 3, 34, 11, 16, 14] as $i => $wd) $db->widths[$i + 1] = $wd; $db->heights[5] = 24;

    /* ───── sheet 3: assumptions ───── */
    $BL = $FM + ['color' => '0000FF', 'bg' => 'FFF2CC', 'nfs' => '0.00', 'border' => 1];
    $as->set(1, 1, 'Assumptions & Methodology', $FM + ['b' => 1, 'sz' => 14, 'color' => $NAVY]); $as->set(2, 1, 'Blue on yellow = editable. Changing the weights recalculates the Baseline column on sheet 2 and the Dashboard.', $FM + ['sz' => 9, 'color' => '595959']);
    $as->set(4, 1, 'FORECAST WEIGHTS', $FM + ['b' => 1, 'color' => $NAVY]);
    foreach ([['12-month average weight', $S['w12']], ['6-month average weight', $S['w6']], ['3-month average weight', $S['w3']], ['Latest month weight', $S['wl']]] as $i => [$lab, $w]) { $as->set(5 + $i, 1, $lab, $FM); $as->set(5 + $i, 3, (float)$w, $BL); }
    $as->set(9, 1, 'Check: weights sum', $FM + ['b' => 1]); $as->set(9, 3, round($S['w12'] + $S['w6'] + $S['w3'] + $S['wl'], 4), $FM + ['b' => 1, 'nfs' => '0.00', 'f' => 'SUM(C5:C8)', 'border' => 1]);
    $as->set(11, 1, 'DEFINITIONS', $FM + ['b' => 1, 'color' => $NAVY]);
    foreach (['SALES of a bill = ' . $basisTxt . '.', 'SALES MONTH = company cycle: month M runs from ' . $cyc . ' (e.g. ' . hs_label($months[0]) . ' = ' . $rg0[0] . ' to ' . $rg0[1] . ').',
              'SOURCES: bills before ' . $S['cutover'] . ' — Analysis > Import Secondary Invoices (Invoice Wise Sales); from ' . $S['cutover'] . ' — our own secondary invoice import.',
              'Each month cell shows the sales amount with the number of bills in brackets, e.g. 123,456 (15); the cell value is the amount only, so every formula still works.', 'Months before an outlet\'s first purchase are blank (not observed) and excluded from averages; zero months after the first purchase count as zero.',
              'BASELINE = 12-month avg × w12 + 6-month avg × w6 + 3-month avg × w3 + latest month × wL.',
              'STATUS: Dormant = no purchase in the last 3 cycles · New = fewer than 3 active months · Growing = last-3M average more than 10% above the earlier months · Declining = more than 10% below · otherwise Stable.',
              'The open cycle (to date) is shown separately and is not part of any average.'] as $i => $t) $as->set(12 + $i, 1, $t, $FM);
    $as->widths[1] = 52; $as->widths[2] = 4; $as->widths[3] = 12;
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
$fa = ['a12' => 0, 'a6' => 0, 'a3' => 0, 'latest' => 0, 'base' => 0, 'r3' => 0]; foreach ($rows as $x) foreach ($fa as $k => $_) $fa[$k] += $x[$k];
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
.hs select.ed,.hs input.ed{padding:3px 6px;border:1.5px solid #e2e8f0;border-radius:8px;font:inherit;font-size:12px;background:#fff;color:#0f172a;transition:.15s}.hs select.ed:hover,.hs input.ed:hover{border-color:#a5b4fc}.hs .ed.ok{border-color:#22c55e!important;background:#f0fdf4!important}.hs .ed.bad{border-color:#ef4444!important;background:#fef2f2!important}.hs .ed.sv{opacity:.55}.hs td.m{border-radius:0}.hs .bc{font-size:10.5px;opacity:.7;font-weight:600}.hs .pm{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;background:#f1f5f9;color:#334155}.hs .pm-credit{background:#ede9fe;color:#5b21b6}.hs .pm-cash{background:#dcfce7;color:#166534}.hs .pm-cheque{background:#fef3c7;color:#92400e}.hs td.z0{color:#cbd5e1}.hs td.nb{background:repeating-linear-gradient(135deg,#fff,#fff 5px,#f1f5f9 5px,#f1f5f9 10px)!important}.hs td.ex{background:#f1f5f9!important;color:#64748b;font-style:italic}.hs .z{color:#cbd5e1}.hs svg.sp{display:block;margin:auto}
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
  <div class="tw" id="tw"><table id="tb"><thead><tr><th class="l s1">HUL</th><th class="l s2">Customer Name</th><th class="l">Sales SP Name</th><th class="l">Beat</th><th class="l">Secondary Channel</th><th class="l">Pay Mode</th><th>Policy Days</th><th>Credit Limit</th><th class="st2" style="text-align:center">Status</th><th class="st2" style="text-align:center">Trend</th>
    <?php foreach ($months as $m): $rg = hs_range($m, $S['start_day']); ?><th title="<?= hr_h($rg[0] . ' → ' . $rg[1]) ?>"><?= hr_h(hs_label($m)) ?></th><?php endforeach; ?><th class="g" title="<?= hr_h($exRange[0]) ?> → <?= hr_h($lastTxt) ?>"><?= hr_h($exLbl) ?></th>
    <th>Avg <?= count($months) ?>M</th><th>Avg 6M</th><th>Avg 3M</th><th>Latest</th><th>Highest</th><th>Lowest</th><th>Active</th><th>CV</th><th>MoM</th><th>Baseline</th><th>Rolling 3M</th><th>vs prior</th></tr></thead><tbody id="tbody"></tbody><?php if ($rows): ?><tfoot><tr><td class="l s1" style="z-index:4">TOTAL</td><td class="l s2" style="z-index:4"><?= number_format(count($rows)) ?> outlets</td><td colspan="8"></td><?php foreach ($months as $m): ?><td><?= number_format($tot[$m] ?? 0, 0) ?></td><?php endforeach; ?><td><?= number_format($ex, 0) ?></td><td><?= number_format($fa['a12'], 0) ?></td><td><?= number_format($fa['a6'], 0) ?></td><td><?= number_format($fa['a3'], 0) ?></td><td><?= number_format($fa['latest'], 0) ?></td><td colspan="5"></td><td><?= number_format($fa['base'], 0) ?></td><td><?= number_format($fa['r3'], 0) ?></td><td></td></tr></tfoot><?php endif; ?></table></div>
</div>
<?php
$JR = []; foreach ($rows as $x) {
    $vv = []; $kk = []; foreach ($months as $m) { $vv[] = $x['v'][$m] === null ? null : round($x['v'][$m], 2); $kk[] = (int)($x['n'][$m] ?? 0); }
    $JR[] = ['c' => $x['code'], 'n' => $x['name'], 'sp' => $x['sp'], 'b' => $x['beat'], 'ch' => $x['channel'], 'pm' => $x['pm'], 'pr' => $x['pmraw'], 'd' => $x['days'] === null ? null : (int)$x['days'], 'cl' => $x['climit'] === null ? null : round($x['climit'], 2), 'ic' => $x['in_cust'] ? 1 : 0,
             'v' => $vv, 'k' => $kk, 'ex' => round($x['extra'], 2), 'exn' => (int)$x['extra_n'], 'a12' => round($x['a12'], 2), 'a6' => round($x['a6'], 2), 'a3' => round($x['a3'], 2), 'l' => round($x['latest'], 2), 'hi' => round($x['hi'], 2), 'lo' => round($x['lo'], 2),
             'ac' => (int)$x['active'], 'cv' => $x['cv'] === null ? null : round($x['cv'], 3), 'mo' => $x['mom'] === null ? null : round($x['mom'], 4), 'ba' => round($x['base'], 2), 'r3' => round($x['r3'], 2), 'tr' => $x['trend'] === null ? null : round($x['trend'], 4)];
}
?>
<div class="edm" id="edm"><div class="edbx"><div class="edhd"><div><b id="edT">Customer</b><div id="edS" style="font-size:12px;opacity:.8"></div></div><button type="button" id="edX" class="edx">&times;</button></div>
  <div class="edbd">
    <label>Customer pay mode</label><select id="edPm"><option value="">— not set —</option><option value="cash">Cash</option><option value="credit">Credit</option><option value="cheque">Cheque</option></select>
    <label>Policy days (credit days)</label><input id="edDays" type="number" min="0" max="365" step="1">
    <label>Credit limit (Rs.)</label><input id="edCl" type="number" min="0" step="0.01">
    <div id="edMsg" style="min-height:18px;font-size:12.5px;margin-top:8px"></div>
  </div>
  <div class="edft"><button type="button" class="btn o" id="edC">Cancel</button><button type="button" class="btn" id="edSv"><i class="fa-solid fa-floppy-disk"></i> Save</button></div></div></div>
<div id="edToast" style="position:fixed;right:18px;bottom:18px;background:#0f172a;color:#fff;padding:10px 16px;border-radius:10px;font:600 13px 'Inter',system-ui,sans-serif;display:none;z-index:2147483100"></div>
<style>
.edm{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;z-index:2147483000;padding:14px;font-family:'Inter',system-ui,sans-serif}.edm.open{display:flex}
.edbx{background:#fff;border-radius:16px;width:100%;max-width:420px;overflow:hidden;box-shadow:0 24px 60px -20px rgba(15,23,42,.6)}.edhd{background:linear-gradient(120deg,#0f172a,#1e3a8a 60%,#6366f1);color:#fff;padding:14px 18px;display:flex;gap:10px;align-items:flex-start;justify-content:space-between}.edx{background:none;border:none;color:#fff;font-size:24px;line-height:1;cursor:pointer}
.edbd{padding:16px 18px}.edbd label{display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin:10px 0 4px}.edbd select,.edbd input{width:100%;padding:10px 12px;border:1.5px solid #cbd5e1;border-radius:10px;font:inherit;font-size:14px;box-sizing:border-box}.edbd select:focus,.edbd input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.18)}
.edft{padding:12px 18px;background:#f8fafc;display:flex;gap:10px;justify-content:flex-end}.edft .btn{padding:9px 18px;border:none;border-radius:10px;background:linear-gradient(135deg,#1e3a8a,#4f46e5);color:#fff;font:600 13px 'Inter',system-ui,sans-serif;cursor:pointer;display:inline-flex;gap:6px;align-items:center}.edft .btn.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe}.edft .btn[disabled]{opacity:.6}.hs td.ec{cursor:pointer}.hs td.ec:hover{background:#eef2ff!important;box-shadow:inset 0 0 0 1.5px #a5b4fc}.hs td.ec .pen{opacity:0;margin-left:4px;font-size:10px;color:#6366f1}.hs td.ec:hover .pen{opacity:1}
.hs .vs td{padding:0!important;border:0!important;background:none!important;box-shadow:none!important}.hs tr.r{height:34px}.hs tr.r td{height:34px;box-sizing:border-box}.hs tr.r.ev td{background-color:#fbfcff}
</style>
<script>
(function(){
  var ROWS=<?= json_encode($JR, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?>, MAXH=<?= json_encode(round($heatMax, 2)) ?>, NM=<?= count($months) ?>, CANEDIT=<?= $canEdit ? 'true' : 'false' ?>;
  var RH=34, BUF=14, tw=document.getElementById('tw'), tb=document.getElementById('tbody'), COLS=<?= 10 + count($months) + 13 ?>;
  var PAL=['#6366f1','#0ea5e9','#10b981','#f59e0b','#ec4899','#8b5cf6','#14b8a6','#ef4444'];
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
  function f0(v){ return Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g,','); }
  function f2(v){ return (Math.round(v*100)/100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,','); }
  function hash(t){ var h=0; for(var i=0;i<t.length;i++) h=(h*31+t.charCodeAt(i))|0; return Math.abs(h); }
  function dash(){ return '<span class="z">—</span>'; }
  function pill(v){ if(v==null) return dash(); var c=v>0.005?'up':(v<-0.005?'dn':'fl'); return '<span class="pl '+c+'">'+(v>0.005?'▲':(v<-0.005?'▼':'■'))+' '+(Math.abs(v)*100).toFixed(1)+'%</span>'; }
  function status(x){ if(x.r3<=0) return ['Dormant','dm']; if(x.ac<3) return ['New','nw']; if(x.tr!=null&&x.tr>0.10) return ['Growing','gr']; if(x.tr!=null&&x.tr<-0.10) return ['Declining','dc']; return ['Stable','st']; }
  function spark(vals){ var max=0,i; for(i=0;i<vals.length;i++) if(vals[i]>max) max=vals[i]; if(max<=0) return dash(); var w=92,h=26,p=[],lx=0,ly=0,n=vals.length;
    for(i=0;i<n;i++){ var v=vals[i]; if(v!=null){ var x=(i*w/Math.max(1,n-1)).toFixed(1), y=(h-3-(v/max)*(h-6)).toFixed(1); p.push(x+','+y); lx=x; ly=y; } }
    if(p.length<2) return '<span class="z">new</span>';
    return '<svg class="sp" width="'+w+'" height="'+h+'" viewBox="0 0 '+w+' '+h+'"><polyline fill="none" stroke="#6366f1" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" points="'+p.join(' ')+'"/><circle cx="'+lx+'" cy="'+ly+'" r="2.6" fill="#f43f5e"/></svg>'; }
  function rowHtml(x,i){
    var st=status(x), h='<tr class="r'+(i%2?' ev':'')+'" data-i="'+i+'"><td class="l s1">'+esc(x.c)+'</td><td class="l s2" title="'+esc(x.n)+'">'+esc(x.n)+'</td><td class="l">'+esc(x.sp)+'</td><td class="l">'+esc(x.b)+'</td>';
    var col=PAL[hash(x.ch)%PAL.length]; h+='<td class="l"><span class="chip" style="--c:'+col+'">'+esc(x.ch)+'</span></td>';
    var ec=(CANEDIT&&x.ic)?' ec" data-i="'+i+'" title="Click to edit':'';
    h+='<td class="l'+ec+'">'+(x.pm?'<span class="pm pm-'+esc(x.pm.toLowerCase())+'">'+esc(x.pm)+'</span>':dash())+(ec?'<i class="fa-solid fa-pen pen"></i>':'')+'</td>';
    h+='<td class="'+ec.replace('l ','')+'" style="text-align:center">'+(x.d>0?'<b>'+x.d+'</b>':dash())+(ec?'<i class="fa-solid fa-pen pen"></i>':'')+'</td>';
    h+='<td class="'+ec.replace('l ','')+'">'+(x.cl?f2(x.cl):dash())+(ec?'<i class="fa-solid fa-pen pen"></i>':'')+'</td>';
    h+='<td style="text-align:center"><span class="stt '+st[1]+'">'+st[0]+'</span></td><td>'+spark(x.v)+'</td>';
    for(var m=0;m<NM;m++){ var v=x.v[m]; if(v==null) h+='<td class="m nb"></td>'; else if(v<=0) h+='<td class="m z0">–</td>'; else { var a=Math.min(1,v/MAXH); h+='<td class="m" style="background-color:rgba(34,197,94,'+(0.10+a*0.55).toFixed(2)+');color:'+(a>0.75?'#052e16;font-weight:700':'#14532d')+'">'+f0(v)+' <span class="bc">('+x.k[m]+')</span></td>'; } }
    h+='<td class="ex">'+(x.ex?f0(x.ex)+' <span class="bc">('+x.exn+')</span>':'')+'</td>';
    h+='<td>'+f0(x.a12)+'</td><td>'+f0(x.a6)+'</td><td>'+f0(x.a3)+'</td><td>'+f0(x.l)+'</td><td>'+f0(x.hi)+'</td><td>'+f0(x.lo)+'</td><td>'+x.ac+'</td><td>'+(x.cv==null?dash():x.cv.toFixed(2))+'</td><td>'+pill(x.mo)+'</td><td><span class="base">'+f0(x.ba)+'</span></td><td>'+f0(x.r3)+'</td><td>'+pill(x.tr)+'</td></tr>';
    return h; }
  var lo=-1,hi=-1,ticking=false;
  function draw(force){ var n=ROWS.length; if(!n){ tb.innerHTML='<tr><td class="l" colspan="'+COLS+'" style="padding:26px;color:#9ca3af;text-align:center">No outlets for these filters.</td></tr>'; return; }
    var top=tw.scrollTop, vh=tw.clientHeight, s=Math.max(0,Math.floor(top/RH)-BUF), e=Math.min(n,Math.ceil((top+vh)/RH)+BUF);
    if(!force&&s===lo&&e===hi) return; lo=s; hi=e; var parts=[]; if(s>0) parts.push('<tr class="vs" style="height:'+(s*RH)+'px"><td colspan="'+COLS+'"></td></tr>');
    for(var i=s;i<e;i++) parts.push(rowHtml(ROWS[i],i)); if(e<n) parts.push('<tr class="vs" style="height:'+((n-e)*RH)+'px"><td colspan="'+COLS+'"></td></tr>'); tb.innerHTML=parts.join(''); }
  tw.addEventListener('scroll',function(){ if(ticking) return; ticking=true; requestAnimationFrame(function(){ ticking=false; draw(false); }); },{passive:true});
  window.addEventListener('resize',function(){ draw(true); });
  tw.style.height='74vh'; tw.style.maxHeight='none'; draw(true);
  /* ───── edit modal ───── */
  var M=document.getElementById('edm'), cur=-1, $=function(i){return document.getElementById(i);}; document.body.appendChild(M);
  var tt=$('edToast'), tm=null; function toast(m,bad){ tt.textContent=m; tt.style.background=bad?'#b91c1c':'#0f172a'; tt.style.display='block'; clearTimeout(tm); tm=setTimeout(function(){tt.style.display='none';},2600); }
  function openM(i){ var x=ROWS[i]; cur=i; $('edT').textContent=x.n||x.c; $('edS').textContent=x.c+' · '+x.ch; $('edPm').value=x.pr||''; $('edDays').value=x.d==null?'':x.d; $('edCl').value=x.cl==null?'':x.cl; $('edMsg').textContent=''; M.classList.add('open'); setTimeout(function(){ $('edPm').focus(); },30); }
  function closeM(){ M.classList.remove('open'); cur=-1; }
  tb.addEventListener('click',function(e){ var c=e.target.closest('td.ec'); if(c) openM(+c.dataset.i); });
  $('edX').onclick=closeM; $('edC').onclick=closeM; M.addEventListener('click',function(e){ if(e.target===M) closeM(); }); document.addEventListener('keydown',function(e){ if(e.key==='Escape'&&cur>=0) closeM(); if(e.key==='Enter'&&cur>=0&&e.target.tagName==='INPUT') $('edSv').click(); });
  $('edSv').onclick=function(){ if(cur<0) return; var x=ROWS[cur], fd=new FormData(); fd.append('action','update_customer'); fd.append('code',x.c); fd.append('payment_mode',$('edPm').value); fd.append('credit_days',$('edDays').value===''?'0':$('edDays').value); fd.append('credit_limit',$('edCl').value===''?'0':$('edCl').value);
    var b=$('edSv'); b.disabled=true; $('edMsg').style.color='#475569'; $('edMsg').textContent='Saving…';
    fetch('historical_sales_trend_report.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.text();}).then(function(t){ var j; try{j=JSON.parse(t);}catch(er){ throw new Error('Not saved — you may not have the Edit right on this page.'); }
      if(!j.ok) throw new Error(j.msg||'Could not save'); x.pr=j.pr; x.pm=j.pm; x.d=j.d; x.cl=j.cl; b.disabled=false; closeM(); draw(true); toast('Saved '+x.c);
    }).catch(function(er){ b.disabled=false; $('edMsg').style.color='#b91c1c'; $('edMsg').textContent=er.message; }); };
})();
</script>
<?php include 'footer.php'; ?>
