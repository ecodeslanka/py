<?php
/**
 * scheme_master_report.php — Scheme Master Report: the HUL Scheme Master and TOT Scheme Master imports in ONE table
 * (activity code · description · start / end date · MOC). Filters: master, MOC, date range (schemes running in the range),
 * status (running / upcoming / expired today), search, upload file. Export Excel.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
require_once __DIR__ . '/scheme_master_lib.php';     // creates the tables when missing
function smr_ok($d) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d); }
$e = fn($v) => mysqli_real_escape_string($GLOBALS['conn'], (string)$v);
$today = date('Y-m-d');

$f_master = in_array($_GET['master'] ?? '', ['HUL', 'TOT'], true) ? $_GET['master'] : '';
$f_moc = trim($_GET['moc'] ?? ''); $f_q = trim($_GET['q'] ?? ''); $f_file = (int)($_GET['upload'] ?? 0);
$f_from = smr_ok($_GET['date_from'] ?? '') ? $_GET['date_from'] : ''; $f_to = smr_ok($_GET['date_to'] ?? '') ? $_GET['date_to'] : '';
if ($f_from && $f_to && $f_to < $f_from) [$f_from, $f_to] = [$f_to, $f_from];
$f_status = in_array($_GET['status'] ?? '', ['running', 'upcoming', 'expired'], true) ? $_GET['status'] : '';
$sort = in_array($_GET['sort'] ?? '', ['start_desc', 'start_asc', 'code', 'moc'], true) ? $_GET['sort'] : 'start_desc';

$w = ['1=1'];
if ($f_master !== '') $w[] = "i.master_type = '$f_master'";
if ($f_moc !== '')    $w[] = "i.moc_ref = '{$e($f_moc)}'";
if ($f_file)          $w[] = "i.upload_id = $f_file";
if ($f_q !== '')      { $q = $e($f_q); $w[] = "(i.activity_code LIKE '%$q%' OR i.activity_desc LIKE '%$q%')"; }
if ($f_from)          $w[] = "(i.date_to IS NULL OR i.date_to >= '$f_from')";      // running at some time inside the range
if ($f_to)            $w[] = "(i.date_from IS NULL OR i.date_from <= '$f_to')";
if ($f_status === 'running')  $w[] = "i.date_from <= '$today' AND i.date_to >= '$today'";
if ($f_status === 'upcoming') $w[] = "i.date_from > '$today'";
if ($f_status === 'expired')  $w[] = "i.date_to < '$today'";
$order = ['start_desc' => 'i.date_from DESC, i.activity_code', 'start_asc' => 'i.date_from ASC, i.activity_code', 'code' => 'i.activity_code, i.master_type', 'moc' => 'i.moc_ref DESC, i.master_type, i.activity_code'][$sort];
$items = []; $r = mysqli_query($conn, "SELECT i.*, u.file_name, u.created_at AS imported_at FROM scheme_master_items i LEFT JOIN scheme_master_uploads u ON u.id = i.upload_id WHERE " . implode(' AND ', $w) . " ORDER BY $order LIMIT 20000");
while ($r && ($x = mysqli_fetch_assoc($r))) $items[] = $x;
$mocs = []; $r = mysqli_query($conn, "SELECT DISTINCT moc_ref FROM scheme_master_items WHERE moc_ref <> '' ORDER BY moc_ref DESC"); while ($r && ($x = mysqli_fetch_row($r))) $mocs[] = $x[0];
$uploads = []; $r = mysqli_query($conn, "SELECT id, master_type, file_name, moc_ref FROM scheme_master_uploads ORDER BY id DESC LIMIT 200"); while ($r && ($x = mysqli_fetch_assoc($r))) $uploads[] = $x;
function smr_status($x, $today) { if (!$x['date_from'] && !$x['date_to']) return ''; if ($x['date_to'] && $x['date_to'] < $today) return 'Expired'; if ($x['date_from'] && $x['date_from'] > $today) return 'Upcoming'; return 'Running'; }
function smr_days($x) { return ($x['date_from'] && $x['date_to']) ? (int)((strtotime($x['date_to']) - strtotime($x['date_from'])) / 86400) + 1 : ''; }
$cnt = ['HUL' => 0, 'TOT' => 0, 'run' => 0]; foreach ($items as $x) { $cnt[$x['master_type']]++; if (smr_status($x, $today) === 'Running') $cnt['run']++; }

/* ═════════ Excel export ═════════ */
if (isset($_GET['export']) && class_exists('ZipArchive')) {
    require_once __DIR__ . '/rbac_xlsx.php';
    while (ob_get_level()) ob_end_clean();
    $X = new MiniXlsx(); $sh = $X->sheet('Scheme Master');
    $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '1E3A8A', 'h' => 'center', 'border' => 1, 'wrap' => 1];
    $sh->set(1, 1, 'Scheme Master Report (HUL + TOT)', ['b' => 1, 'sz' => 15]);
    $act = array_filter([$f_master ? 'Master: ' . $f_master : '', $f_moc !== '' ? 'MOC ' . $f_moc : '', ($f_from || $f_to) ? 'Running ' . ($f_from ?: '…') . ' → ' . ($f_to ?: '…') : '', $f_status ? 'Status: ' . $f_status : '', $f_q !== '' ? 'Search: ' . $f_q : '']);
    $sh->set(2, 1, $act ? 'Filters — ' . implode(' · ', $act) : 'All records', ['i' => 1, 'color' => '6B7280']);
    foreach (['#', 'Master', 'Activity Code', 'Activity Desc.', 'Date From / ACT START DATE', 'Date To / ACT END DATE', 'Days', 'Status', 'MOC', 'File', 'Imported At'] as $i => $h) $sh->set(4, $i + 1, $h, $H);
    $r = 5; foreach ($items as $n => $x) {
        foreach ([$n + 1, $x['master_type'], $x['activity_code'], $x['activity_desc'], (string)$x['date_from'], (string)$x['date_to'], smr_days($x), smr_status($x, $today), $x['moc_ref'], $x['file_name'], $x['imported_at']] as $i => $v) $sh->set($r, $i + 1, $v === null ? '' : $v, ['border' => 1]);
        $r++;
    }
    foreach ([5, 8, 16, 46, 16, 16, 7, 11, 10, 36, 18] as $i => $wd) $sh->widths[$i + 1] = $wd;
    $sh->freeze = [4, 3]; $sh->filter = 'A4:K' . max(5, $r - 1);
    $tmp = tempnam(sys_get_temp_dir(), 'sm'); $X->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="scheme_master_report_' . date('Ymd_His') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
ob_end_flush();
include 'header.php';
$d = fn($v) => $v ? date('d M Y', strtotime($v)) : '—';
?>
<style>
.sr{max-width:1600px;margin:0 auto;padding:16px;font-family:'Inter',system-ui,sans-serif;color:#111827}.sr h2{margin:0 0 3px;font-size:22px}.sr .sub{color:#6b7280;font-size:13px;margin:0 0 14px}
.sr .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:14px}
.sr .btn{padding:8px 16px;border:none;border-radius:8px;background:#1e3a8a;color:#fff;font:600 13px inherit;cursor:pointer;text-decoration:none;display:inline-flex;gap:6px;align-items:center}.sr .btn.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe}.sr .btn.g{background:#16a34a}
.sr .row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.sr .fld{display:flex;flex-direction:column;gap:3px}.sr .fld label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase}
.sr input,.sr select{padding:8px 10px;border:1.5px solid #d1d5db;border-radius:8px;font:inherit;font-size:13px}
.sr .kp{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:12px}.sr .k{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px}.sr .k span{font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:700;display:block}.sr .k b{font-size:20px}
.sr .tw{overflow:auto;border:1px solid #e5e7eb;border-radius:10px;background:#fff;max-height:72vh}.sr table{width:100%;border-collapse:collapse;font-size:12.5px}.sr th{background:#1e3a8a;color:#fff;padding:8px 9px;text-align:left;position:sticky;top:0;white-space:nowrap}
.sr td{padding:7px 9px;border-bottom:1px solid #f1f5f9}.sr td.r,.sr th.r{text-align:right}.sr tr:hover td{background:#f8fafc}
.sr .b{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700}.sr .b.hul{background:#dbeafe;color:#1e40af}.sr .b.tot{background:#ede9fe;color:#5b21b6}.sr .b.run{background:#dcfce7;color:#166534}.sr .b.up{background:#fef3c7;color:#92400e}.sr .b.exp{background:#f3f4f6;color:#6b7280}
</style>
<div class="sr">
  <h2><i class="fa-solid fa-table-list" style="color:#1e3a8a"></i> Scheme Master Report</h2>
  <p class="sub">The <b>HUL Scheme Master</b> and <b>TOT Scheme Master</b> imports in one table — one row per activity code. Import the files on the HUL / TOT Scheme Master Import pages.</p>
  <div class="card"><form method="get" class="row">
    <div class="fld"><label>Master</label><select name="master"><option value="">HUL + TOT</option><option value="HUL" <?= $f_master === 'HUL' ? 'selected' : '' ?>>HUL</option><option value="TOT" <?= $f_master === 'TOT' ? 'selected' : '' ?>>TOT</option></select></div>
    <div class="fld"><label>MOC</label><select name="moc"><option value="">All</option><?php foreach ($mocs as $m): ?><option <?= $f_moc === $m ? 'selected' : '' ?>><?= sm_h($m) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Running from</label><input type="date" name="date_from" value="<?= sm_h($f_from) ?>"></div>
    <div class="fld"><label>Running to</label><input type="date" name="date_to" value="<?= sm_h($f_to) ?>"></div>
    <div class="fld"><label>Status today</label><select name="status"><option value="">All</option><?php foreach (['running' => 'Running', 'upcoming' => 'Upcoming', 'expired' => 'Expired'] as $k => $l): ?><option value="<?= $k ?>" <?= $f_status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Upload file</label><select name="upload"><option value="">All files</option><?php foreach ($uploads as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $f_file === (int)$u['id'] ? 'selected' : '' ?>><?= sm_h($u['master_type'] . ' · ' . $u['file_name']) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Sort</label><select name="sort"><?php foreach (['start_desc' => 'Latest start first', 'start_asc' => 'Oldest start first', 'code' => 'Activity code', 'moc' => 'MOC'] as $k => $l): ?><option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Search</label><input type="search" name="q" value="<?= sm_h($f_q) ?>" placeholder="activity code or description"></div>
    <button class="btn" type="submit"><i class="fa-solid fa-filter"></i> Filter</button><a class="btn o" href="scheme_master_report.php">Clear</a>
    <a class="btn g" href="?<?= sm_h(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a></form></div>
  <div class="kp"><div class="k"><span>Activity codes shown</span><b><?= number_format(count($items)) ?></b></div><div class="k"><span>HUL</span><b style="color:#1e40af"><?= number_format($cnt['HUL']) ?></b></div><div class="k"><span>TOT</span><b style="color:#5b21b6"><?= number_format($cnt['TOT']) ?></b></div><div class="k"><span>Running today</span><b style="color:#16a34a"><?= number_format($cnt['run']) ?></b></div></div>
  <div class="tw"><table><thead><tr><th>#</th><th>Master</th><th>Activity Code</th><th>Activity Desc.</th><th>Start date</th><th>End date</th><th class="r">Days</th><th>Status</th><th>MOC</th><th>File</th></tr></thead><tbody>
  <?php foreach ($items as $n => $x): $st = smr_status($x, $today); ?>
    <tr><td><?= $n + 1 ?></td><td><span class="b <?= strtolower($x['master_type']) ?>"><?= sm_h($x['master_type']) ?></span></td><td><b><?= sm_h($x['activity_code']) ?></b></td><td><?= sm_h($x['activity_desc']) ?></td><td style="white-space:nowrap"><?= $d($x['date_from']) ?></td><td style="white-space:nowrap"><?= $d($x['date_to']) ?></td><td class="r"><?= smr_days($x) ?></td>
      <td><?php if ($st): ?><span class="b <?= $st === 'Running' ? 'run' : ($st === 'Upcoming' ? 'up' : 'exp') ?>"><?= $st ?></span><?php endif; ?></td><td><?= sm_h($x['moc_ref']) ?></td><td style="font-size:11.5px;color:#6b7280"><?= sm_h($x['file_name']) ?></td></tr>
  <?php endforeach; if (!$items) echo '<tr><td colspan="10" style="text-align:center;color:#9ca3af;padding:22px">No records — import the HUL / TOT Scheme Master files, or change the filters.</td></tr>'; ?></tbody></table></div>
</div>
<?php include 'footer.php'; ?>
