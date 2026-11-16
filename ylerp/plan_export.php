<?php
/** plan_export.php — Excel export for the planning reports. The page posts its current (filtered, sorted) rows; this writes the styled workbook. */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
require_once __DIR__ . '/rbac_xlsx.php';
$p = json_decode($_POST['payload'] ?? '', true);
if (!is_array($p) || empty($p['cols']) || !isset($p['rows']) || !class_exists('ZipArchive')) { while (ob_get_level()) ob_end_clean(); http_response_code(400); echo 'Nothing to export.'; exit; }
while (ob_get_level()) ob_end_clean();
$X = new MiniXlsx(); $sh = $X->sheet(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', (string)($p['title'] ?? 'Report')), 0, 31)); $sh->tab = '1F3864'; $sh->landscape = true;
$FM = ['font' => 'Arial']; $NAVY = '1F3864'; $HS = $FM + ['b' => 1, 'color' => 'FFFFFF', 'bg' => $NAVY, 'h' => 'center', 'wrap' => 1, 'border' => 1];
$NF = ['n' => '#,##0;\(#,##0\);"-"', 'm' => '#,##0.00;\(#,##0.00\);"-"', 'p' => '0.0%;\(0.0%\);"-"', 'd' => '0.00', 'i' => '0'];
$sh->set(1, 1, (string)($p['title'] ?? 'Report'), $FM + ['b' => 1, 'sz' => 14, 'color' => $NAVY]);
$sh->set(2, 1, trim((string)($p['sub'] ?? '') . ' Exported ' . date('d M Y H:i') . '.'), $FM + ['sz' => 9, 'color' => '595959']);
$cols = $p['cols']; $n = count($cols);
foreach ($cols as $i => $c) { $sh->set(4, $i + 1, (string)$c['h'], $HS); $sh->widths[$i + 1] = max(6, min(80, (float)($c['w'] ?? 14))); }
$sh->heights[4] = 36; $r = 5;
foreach ($p['rows'] as $row) {
    foreach ($cols as $i => $c) { $v = $row[$i] ?? ''; $t = $c['t'] ?? 's';
        if ($t !== 's' && $v !== '' && is_numeric($v)) $sh->set($r, $i + 1, $t === 'i' ? (int)$v : (float)$v, $FM + ['nfs' => $NF[$t] ?? $NF['n'], 'h' => 'right']);
        else $sh->set($r, $i + 1, is_scalar($v) ? (string)$v : '', $FM + (mb_strlen((string)$v) > 60 ? ['wrap' => 1, 'v' => 'top'] : [])); }
    $r++;
}
$last = max(5, $r - 1);
if ($p['rows']) { $rt = $r + 1; $TB = $FM + ['b' => 1, 'bg' => 'D9E1F2', 'border' => 1, 'color' => $NAVY];
    for ($c = 1; $c <= $n; $c++) $sh->set($rt, $c, '', $TB); $sh->set($rt, 1, 'TOTAL', $TB); if ($n > 1) $sh->set($rt, 2, count($p['rows']) . ' rows', $TB);
    foreach ($cols as $i => $c) if (!empty($c['tot'])) { $L = MiniXlsx::col($i + 1); $sum = 0; foreach ($p['rows'] as $row) $sum += is_numeric($row[$i] ?? '') ? (float)$row[$i] : 0; $sh->set($rt, $i + 1, round($sum, 2), $TB + ['nfs' => $NF[$c['t']] ?? $NF['n'], 'f' => "SUM({$L}5:{$L}$last)"]); } }
$sh->freeze = [4, min(2, $n)]; $sh->filter = 'A4:' . MiniXlsx::col($n) . $last; $sh->titles = ['rows' => '4:4'];
$fn = preg_replace('/[^a-z0-9_]+/i', '_', (string)($p['name'] ?? 'report')) . '_' . date('Ymd_His') . '.xlsx';
$tmp = tempnam(sys_get_temp_dir(), 'pe'); $X->save($tmp);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="' . $fn . '"'); header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
