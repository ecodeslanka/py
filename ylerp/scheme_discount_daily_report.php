<?php
/**
 * scheme_discount_daily_report.php — Scheme Discount Daily Report (Vendor Ledger)
 * Built from the uploaded "Current Scheme Status Report" (scheme_status_items):
 * Scheme Activity Code · Description · MOC Ref · Start Date · End Date · Claim Amount.
 * Filters: day / date range (schemes running on those days), MOC Ref, Activity type, Claim MOC Ref, search.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
$has = mysqli_query($conn, "SHOW TABLES LIKE 'scheme_status_items'");
$ready = $has && mysqli_num_rows($has) > 0;

function sd_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function sd_d($v) { return $v ? date('d M Y', strtotime($v)) : '—'; }
function sd_ok($d) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d); }

$f_from = sd_ok($_GET['date_from'] ?? '') ? $_GET['date_from'] : '';
$f_to   = sd_ok($_GET['date_to'] ?? '') ? $_GET['date_to'] : '';
if ($f_from && $f_to && $f_to < $f_from) [$f_from, $f_to] = [$f_to, $f_from];
$f_moc   = trim($_GET['moc'] ?? '');
$f_type  = trim($_GET['type'] ?? '');
$f_cmoc  = trim($_GET['claim_moc'] ?? '');
$f_q     = trim($_GET['q'] ?? '');
$f_claim = in_array($_GET['claim'] ?? '', ['', 'with', 'without'], true) ? ($_GET['claim'] ?? '') : '';

$items = []; $mocs = $types = $cmocs = [];
if ($ready) {
    $e = fn($v) => mysqli_real_escape_string($conn, $v);
    $w = ['1=1'];
    /* "schemes running in the date range": start <= to AND end >= from (a single day = from and to the same) */
    if ($f_to)   $w[] = "(i.start_date IS NULL OR i.start_date <= '{$e($f_to)}')";
    if ($f_from) $w[] = "(i.end_date IS NULL OR i.end_date >= '{$e($f_from)}')";
    if ($f_moc !== '')  $w[] = "i.moc_ref = '{$e($f_moc)}'";
    if ($f_type !== '') $w[] = "i.activity_type = '{$e($f_type)}'";
    if ($f_cmoc !== '') $w[] = "i.claim_moc_ref = '{$e($f_cmoc)}'";
    if ($f_claim === 'with')    $w[] = "COALESCE(i.claim_amount,0) > 0";
    if ($f_claim === 'without') $w[] = "COALESCE(i.claim_amount,0) = 0";
    if ($f_q !== '') { $q = $e($f_q); $w[] = "(i.activity_code LIKE '%$q%' OR i.activity_desc LIKE '%$q%')"; }
    $r = mysqli_query($conn, "SELECT i.* FROM scheme_status_items i WHERE " . implode(' AND ', $w) . " ORDER BY i.start_date ASC, i.activity_type, i.activity_code");
    while ($r && ($x = mysqli_fetch_assoc($r))) $items[] = $x;
    $dist = function ($col, $ord) use ($conn) { $o = []; $r = mysqli_query($conn, "SELECT DISTINCT $col AS v FROM scheme_status_items WHERE $col IS NOT NULL AND $col <> '' ORDER BY $col $ord"); while ($r && ($x = mysqli_fetch_assoc($r))) $o[] = $x['v']; return $o; };
    $mocs = $dist('moc_ref', 'DESC'); $types = $dist('activity_type', 'ASC'); $cmocs = $dist('claim_moc_ref', 'DESC');
}
$tot = array_sum(array_map(fn($x) => (float)$x['claim_amount'], $items));

/* Excel */
if (isset($_GET['export']) && class_exists('ZipArchive')) {
    require_once __DIR__ . '/rbac_xlsx.php';
    while (ob_get_level()) ob_end_clean();
    $X = new MiniXlsx(); $sh = $X->sheet('Scheme Discount Daily');
    $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '1E3A8A', 'h' => 'center', 'border' => 1, 'wrap' => 1];
    $sh->set(1, 1, 'Scheme Discount Daily Report' . ($f_from || $f_to ? ' — ' . ($f_from ?: '…') . ' to ' . ($f_to ?: '…') : ''), ['b' => 1, 'sz' => 15]);
    foreach (['#', 'Activity Type', 'Scheme Activity Code', 'Scheme Activity Description', 'MOC Ref', 'Start Date', 'End Date', 'Claim MOC Ref', 'Claim Amount'] as $i => $h) $sh->set(3, $i + 1, $h, $H);
    $r = 4; foreach ($items as $n => $x) {
        foreach ([$n + 1, $x['activity_type'], $x['activity_code'], $x['activity_desc'], $x['moc_ref'], sd_d($x['start_date']) === '—' ? '' : sd_d($x['start_date']), sd_d($x['end_date']) === '—' ? '' : sd_d($x['end_date']), $x['claim_moc_ref'], $x['claim_amount'] === null ? '' : (float)$x['claim_amount']] as $i => $v)
            $sh->set($r, $i + 1, $v, ['border' => 1] + ($i === 8 ? ['h' => 'right', 'nf' => 4] : []));
        $r++;
    }
    $sh->set($r, 8, 'TOTAL', ['b' => 1, 'border' => 1, 'bg' => 'FDE68A']); $sh->set($r, 9, round($tot, 2), ['b' => 1, 'border' => 1, 'bg' => 'FDE68A', 'h' => 'right', 'nf' => 4]);
    foreach ([5, 14, 18, 40, 10, 13, 13, 13, 16] as $i => $wd) $sh->widths[$i + 1] = $wd;
    $sh->freeze = [3, 0]; $tmp = tempnam(sys_get_temp_dir(), 'sd'); $X->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="scheme_discount_daily_' . date('Ymd_His') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
ob_end_flush();
include 'header.php';
$active = array_filter([$f_from || $f_to ? 'Date: ' . ($f_from ? sd_d($f_from) : '…') . ' → ' . ($f_to ? sd_d($f_to) : '…') : '', $f_moc !== '' ? 'MOC ' . $f_moc : '', $f_type !== '' ? $f_type : '', $f_cmoc !== '' ? 'Claim MOC ' . $f_cmoc : '', $f_claim === 'with' ? 'With claim' : ($f_claim === 'without' ? 'Without claim' : ''), $f_q !== '' ? '“' . $f_q . '”' : '']);
?>
<style>
.sdr{max-width:1500px;margin:0 auto;padding:16px;font-family:'Inter',system-ui,sans-serif;color:#111827}.sdr h2{margin:0 0 3px;font-size:22px}.sdr .sub{color:#6b7280;font-size:13px;margin:0 0 14px}
.sdr .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;margin-bottom:14px}.sdr .row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.sdr .fld{display:flex;flex-direction:column;gap:3px}.sdr .fld label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase}
.sdr input,.sdr select{padding:8px 10px;border:1.5px solid #d1d5db;border-radius:8px;font:inherit;font-size:13px}.sdr .btn{padding:8px 16px;border:none;border-radius:8px;background:#1e3a8a;color:#fff;font:600 13px inherit;cursor:pointer;text-decoration:none;display:inline-flex;gap:6px;align-items:center}.sdr .btn.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe}
.sdr .chip{display:inline-block;margin:6px 6px 0 0;font-size:11.5px;font-weight:600;color:#1e3a8a;background:#eff6ff;border:1px solid #bfdbfe;border-radius:99px;padding:2px 10px}
.sdr .kp{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-bottom:12px}.sdr .k{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px}.sdr .k span{font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:700}.sdr .k b{display:block;font-size:19px;margin-top:2px;font-variant-numeric:tabular-nums}
.sdr .tw{overflow:auto;border:1px solid #e5e7eb;border-radius:10px;background:#fff;max-height:72vh}.sdr table{width:100%;border-collapse:collapse;font-size:13px}.sdr th{background:#1e3a8a;color:#fff;padding:9px 10px;text-align:left;position:sticky;top:0;white-space:nowrap}.sdr td{padding:8px 10px;border-bottom:1px solid #f1f5f9}.sdr .r{text-align:right;font-variant-numeric:tabular-nums}.sdr tr:hover td{background:#f8fafc}
.sdr tfoot td{background:#fde68a;font-weight:800;position:sticky;bottom:0}.sdr .ty{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;background:#ede9fe;color:#6d28d9}.sdr .ty.dmg{background:#fee2e2;color:#b91c1c}
.sdr .fbody.collapsed{display:none}.sdr .ftog{cursor:pointer;display:flex;align-items:center;gap:10px;user-select:none}
</style>
<div class="sdr">
  <h2><i class="fa-solid fa-calendar-day" style="color:#1e3a8a"></i> Scheme Discount Daily Report</h2>
  <p class="sub">From the uploaded <b>Current Scheme Status Report</b> (Vendor Ledger → Import). Pick a day or a date range to see the schemes running in it.</p>
  <?php if (!$ready): ?><div class="card" style="color:#92400e;background:#fffbeb">No scheme status data yet — upload the Excel first in <a href="scheme_status_report.php">Current Scheme Status Import</a>.</div><?php endif; ?>

  <form class="card" method="get" id="sdForm">
    <div class="ftog" id="sdTog"><b><i class="fa-solid fa-sliders"></i> Filters</b><?php foreach ($active as $c): ?><span class="chip"><?= sd_h($c) ?></span><?php endforeach; if (!$active): ?><span style="color:#9ca3af;font-size:12.5px">none — click to open</span><?php endif; ?><i class="fa-solid fa-chevron-down" style="margin-left:auto"></i></div>
    <div class="fbody <?= $active ? 'collapsed' : '' ?>" id="sdBody" style="margin-top:12px"><div class="row">
      <div class="fld"><label>Date from</label><input type="date" name="date_from" value="<?= sd_h($f_from) ?>"></div>
      <div class="fld"><label>Date to</label><input type="date" name="date_to" value="<?= sd_h($f_to) ?>"></div>
      <div class="fld"><label>MOC Ref</label><select name="moc"><option value="">All</option><?php foreach ($mocs as $m): ?><option <?= $f_moc === $m ? 'selected' : '' ?>><?= sd_h($m) ?></option><?php endforeach; ?></select></div>
      <div class="fld"><label>Activity type</label><select name="type"><option value="">All</option><?php foreach ($types as $t): ?><option <?= $f_type === $t ? 'selected' : '' ?>><?= sd_h($t) ?></option><?php endforeach; ?></select></div>
      <div class="fld"><label>Claim MOC Ref</label><select name="claim_moc"><option value="">All</option><?php foreach ($cmocs as $m): ?><option <?= $f_cmoc === $m ? 'selected' : '' ?>><?= sd_h($m) ?></option><?php endforeach; ?></select></div>
      <div class="fld"><label>Claim</label><select name="claim"><option value="">All</option><option value="with" <?= $f_claim === 'with' ? 'selected' : '' ?>>With claim amount</option><option value="without" <?= $f_claim === 'without' ? 'selected' : '' ?>>Without claim</option></select></div>
      <div class="fld"><label>Search</label><input type="search" name="q" value="<?= sd_h($f_q) ?>" placeholder="activity code or description"></div>
      <button class="btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Show</button><a class="btn o" href="scheme_discount_daily_report.php">Reset</a>
      <a class="btn o" href="?<?= sd_h(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a></div></div>
  </form>

  <div class="kp"><div class="k"><span>Schemes</span><b><?= number_format(count($items)) ?></b></div><div class="k"><span>Total claim amount</span><b style="color:#7c3aed">Rs. <?= number_format($tot, 2) ?></b></div>
    <div class="k"><span>With claim</span><b><?= number_format(count(array_filter($items, fn($x) => (float)$x['claim_amount'] > 0))) ?></b></div></div>

  <div class="tw"><table><thead><tr><th>#</th><th>Type</th><th>Scheme Activity Code</th><th>Scheme Activity Description</th><th>MOC Ref</th><th>Start Date</th><th>End Date</th><th class="r">Claim Amount</th></tr></thead><tbody>
  <?php foreach ($items as $n => $x): ?><tr><td><?= $n + 1 ?></td><td><span class="ty <?= $x['activity_type'] === 'DAMAGE' ? 'dmg' : '' ?>"><?= sd_h($x['activity_type']) ?></span></td><td><b><?= sd_h($x['activity_code']) ?></b></td><td><?= sd_h($x['activity_desc']) ?></td><td><?= sd_h($x['moc_ref']) ?></td><td><?= sd_d($x['start_date']) ?></td><td><?= sd_d($x['end_date']) ?></td><td class="r"><b><?= $x['claim_amount'] === null ? '—' : number_format((float)$x['claim_amount'], 2) ?></b></td></tr>
  <?php endforeach; if (!$items): ?><tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:28px">No schemes for these filters.</td></tr><?php endif; ?></tbody>
  <?php if ($items): ?><tfoot><tr><td colspan="7">TOTAL (<?= number_format(count($items)) ?> schemes)</td><td class="r"><?= number_format($tot, 2) ?></td></tr></tfoot><?php endif; ?></table></div>
</div>
<script>document.getElementById('sdTog').addEventListener('click',function(){ document.getElementById('sdBody').classList.toggle('collapsed'); });</script>
<?php include 'footer.php'; ?>
