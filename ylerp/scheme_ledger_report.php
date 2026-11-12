<?php
/**
 * scheme_ledger_report.php — Scheme Ledger (Vendor Ledger)
 * A statement per scheme: the CLAIM AMOUNT of the uploaded Current Scheme Status Report is the DEBIT,
 * the CLAIM CERTIFICATE ACTUAL AMOUNT (Customer Claim Certificate Import, scheme no = claim description before the first "-") is the CREDIT.
 * Balance = running total of Debit − Credit.  The closing (last) balance is shown at the top; newest entries first.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
function sl_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function sl_norm($c) { return strtoupper(preg_replace('/\.0+$/', '', trim((string)$c))); }
function sl_ok($d) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d); }
function sl_d($v) { return $v ? date('d M Y', strtotime($v)) : '—'; }
function sl_m($v, $dash = true) { if ($v === null || $v === '') return $dash ? '' : '0.00'; return $v < 0 ? '(' . number_format(abs($v), 2) . ')' : number_format($v, 2); }
$q = fn($t) => ($r = mysqli_query($GLOBALS['conn'], "SHOW TABLES LIKE '$t'")) && mysqli_num_rows($r) > 0;
$has_items = $q('scheme_status_items'); $has_cc = $q('claim_cert_items');
$e = fn($v) => mysqli_real_escape_string($GLOBALS['conn'], (string)$v);

$f_from = sl_ok($_GET['date_from'] ?? '') ? $_GET['date_from'] : '';
$f_to   = sl_ok($_GET['date_to'] ?? '') ? $_GET['date_to'] : '';
if ($f_from && $f_to && $f_to < $f_from) [$f_from, $f_to] = [$f_to, $f_from];
$f_moc = trim($_GET['moc'] ?? ''); $f_q = trim($_GET['q'] ?? ''); $f_asc = !empty($_GET['oldest_first']);

/* schemes that take part (never DAMAGE) */
$schemes = []; $mocs = [];
if ($has_items) {
    $w = ["UPPER(TRIM(activity_type)) <> 'DAMAGE'"];
    if ($f_moc !== '') $w[] = "moc_ref = '{$e($f_moc)}'";
    if ($f_q !== '') { $s = $e($f_q); $w[] = "(activity_code LIKE '%$s%' OR activity_desc LIKE '%$s%')"; }
    $r = mysqli_query($conn, "SELECT * FROM scheme_status_items WHERE " . implode(' AND ', $w));
    while ($r && ($x = mysqli_fetch_assoc($r))) { $k = sl_norm($x['activity_code']); $schemes[$k]['rows'][] = $x; if (!isset($schemes[$k]['desc'])) $schemes[$k]['desc'] = $x['activity_desc']; }
    $r = mysqli_query($conn, "SELECT DISTINCT moc_ref FROM scheme_status_items WHERE moc_ref <> '' AND UPPER(TRIM(activity_type)) <> 'DAMAGE' ORDER BY moc_ref DESC"); while ($r && ($x = mysqli_fetch_assoc($r))) $mocs[] = $x['moc_ref'];
}
/* entries */
$entries = [];
foreach ($schemes as $k => $sc) foreach ($sc['rows'] as $x) {
    if ($x['claim_amount'] === null || (float)$x['claim_amount'] <= 0) continue;
    $date = $x['claim_date'] ?: ($x['end_date'] ?: $x['start_date']);
    $entries[] = ['date' => $date ?: '', 'scheme' => trim($x['activity_code']), 'desc' => $sc['desc'], 'type' => 'Claim', 'ref' => $x['claim_moc_ref'] !== '' && $x['claim_moc_ref'] !== null ? 'Claim MOC ' . $x['claim_moc_ref'] : 'MOC ' . $x['moc_ref'], 'debit' => (float)$x['claim_amount'], 'credit' => 0.0, 'o' => 1];
}
if ($has_cc && $schemes) {
    $codes = array_map(fn($k) => "'" . $e($k) . "'", array_keys($schemes));
    $r = mysqli_query($conn, "SELECT tax_invoice_no, invoice_date, banking_date, imported_at, customer_code, claim_description, actual_amount,
                                     UPPER(TRIM(TRAILING '.0' FROM TRIM(SUBSTRING_INDEX(claim_description, '-', 1)))) AS sn
                              FROM claim_cert_items WHERE claim_description IS NOT NULL AND claim_description <> '' AND claim_description NOT LIKE '-%'
                                AND UPPER(TRIM(TRAILING '.0' FROM TRIM(SUBSTRING_INDEX(claim_description, '-', 1)))) IN (" . implode(',', $codes) . ") AND COALESCE(actual_amount,0) <> 0");
    while ($r && ($x = mysqli_fetch_assoc($r))) {
        $sn = $x['sn']; $date = $x['invoice_date'] ?: ($x['banking_date'] ?: substr((string)$x['imported_at'], 0, 10));
        $entries[] = ['date' => $date ?: '', 'scheme' => $sn, 'desc' => $schemes[$sn]['desc'] ?? '', 'type' => 'Claim certificate', 'ref' => 'Tax inv ' . $x['tax_invoice_no'] . ' · ' . $x['claim_description'], 'debit' => 0.0, 'credit' => (float)$x['actual_amount'], 'o' => 2];
    }
}
/* statement: oldest → newest with a running balance, opening balance before the "from" date */
usort($entries, fn($a, $b) => strcmp($a['date'], $b['date']) ?: $a['o'] <=> $b['o'] ?: strcmp($a['scheme'], $b['scheme']));
$open = 0.0; $stmt = []; $bal = 0.0; $tdr = 0.0; $tcr = 0.0;
foreach ($entries as $en) {
    if ($f_from && $en['date'] !== '' && $en['date'] < $f_from) { $open += $en['debit'] - $en['credit']; continue; }
    if ($f_to && $en['date'] !== '' && $en['date'] > $f_to) continue;
    $stmt[] = $en;
}
$bal = $open; foreach ($stmt as &$en) { $bal += $en['debit'] - $en['credit']; $en['bal'] = round($bal, 2); $tdr += $en['debit']; $tcr += $en['credit']; } unset($en);
$closing = round($bal, 2);
if (!$f_asc) $stmt = array_reverse($stmt);      // newest first: the last balance is the first line

/* Excel */
if (isset($_GET['export']) && class_exists('ZipArchive')) {
    require_once __DIR__ . '/rbac_xlsx.php'; while (ob_get_level()) ob_end_clean();
    $X = new MiniXlsx(); $sh = $X->sheet('Scheme Ledger'); $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '1E3A8A', 'h' => 'center', 'border' => 1, 'wrap' => 1];
    $sh->set(1, 1, 'Scheme Ledger — statement', ['b' => 1, 'sz' => 15]);
    $sh->set(2, 1, 'Closing balance', ['b' => 1]); $sh->set(2, 2, $closing, ['b' => 1, 'nf' => 4, 'h' => 'right']); $sh->set(2, 4, 'Debit = claim amount · Credit = claim certificate actual amount', ['i' => 1, 'color' => '6B7280']);
    foreach (['Date', 'Scheme No', 'Description', 'Type', 'Reference', 'Debit', 'Credit', 'Balance'] as $i => $h) $sh->set(4, $i + 1, $h, $H);
    $r = 5;
    if ($f_from) { $sh->set($r, 3, 'Opening balance', ['i' => 1, 'border' => 1]); $sh->set($r, 8, round($open, 2), ['border' => 1, 'nf' => 4, 'h' => 'right']); $r++; }
    foreach ($stmt as $en) { foreach ([sl_d($en['date']) === '—' ? '' : sl_d($en['date']), $en['scheme'], $en['desc'], $en['type'], $en['ref'], $en['debit'] ?: '', $en['credit'] ?: '', $en['bal']] as $i => $v) $sh->set($r, $i + 1, $v, ['border' => 1] + ($i >= 5 ? ['h' => 'right', 'nf' => 4] : [])); $r++; }
    $tb = ['b' => 1, 'border' => 1, 'bg' => 'FDE68A', 'h' => 'right', 'nf' => 4]; $sh->set($r, 5, 'TOTAL', ['b' => 1, 'border' => 1, 'bg' => 'FDE68A']); $sh->set($r, 6, round($tdr, 2), $tb); $sh->set($r, 7, round($tcr, 2), $tb); $sh->set($r, 8, $closing, $tb);
    foreach ([13, 14, 40, 17, 52, 15, 15, 16] as $i => $wd) $sh->widths[$i + 1] = $wd; $sh->freeze = [4, 0];
    $tmp = tempnam(sys_get_temp_dir(), 'sl'); $X->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="scheme_ledger_' . date('Ymd_His') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
ob_end_flush();
include 'header.php';
$active = array_filter([$f_from || $f_to ? 'Date: ' . ($f_from ? sl_d($f_from) : '…') . ' → ' . ($f_to ? sl_d($f_to) : '…') : '', $f_moc !== '' ? 'MOC ' . $f_moc : '', $f_q !== '' ? '“' . $f_q . '”' : '', $f_asc ? 'Oldest first' : '']);
?>
<style>
.slr{max-width:1500px;margin:0 auto;padding:16px;font-family:'Inter',system-ui,sans-serif;color:#111827}.slr h2{margin:0 0 3px;font-size:22px}.slr .sub{color:#6b7280;font-size:13px;margin:0 0 14px}
.slr .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;margin-bottom:14px}.slr .row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.slr .fld{display:flex;flex-direction:column;gap:3px}.slr .fld label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase}
.slr input,.slr select{padding:8px 10px;border:1.5px solid #d1d5db;border-radius:8px;font:inherit;font-size:13px}.slr .btn{padding:8px 16px;border:none;border-radius:8px;background:#1e3a8a;color:#fff;font:600 13px inherit;cursor:pointer;text-decoration:none;display:inline-flex;gap:6px;align-items:center}.slr .btn.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe}
.slr .chip{display:inline-block;margin:6px 6px 0 0;font-size:11.5px;font-weight:600;color:#1e3a8a;background:#eff6ff;border:1px solid #bfdbfe;border-radius:99px;padding:2px 10px}.slr .ftog{cursor:pointer;display:flex;align-items:center;gap:10px;user-select:none}.slr .fbody.collapsed{display:none}
.slr .hero{background:linear-gradient(120deg,#0f172a,#1e3a8a 60%,#0e7490);color:#fff;border-radius:14px;padding:18px 22px;margin-bottom:14px;display:flex;gap:26px;flex-wrap:wrap;align-items:center}.slr .hero small{display:block;font-size:11px;letter-spacing:.07em;text-transform:uppercase;opacity:.75;font-weight:700}.slr .hero b{font-size:30px;font-variant-numeric:tabular-nums}.slr .hero .s b{font-size:19px}
.slr .tw{overflow:auto;border:1px solid #e5e7eb;border-radius:10px;background:#fff;max-height:72vh}.slr table{width:100%;border-collapse:collapse;font-size:13px}.slr th{background:#1e3a8a;color:#fff;padding:9px 10px;text-align:left;position:sticky;top:0;white-space:nowrap}.slr td{padding:8px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top}.slr .r{text-align:right;font-variant-numeric:tabular-nums}.slr tr:hover td{background:#f8fafc}
.slr tr.first td{background:#eef2ff;font-weight:700}.slr .dr{color:#b91c1c;font-weight:700}.slr .cr{color:#15803d;font-weight:700}.slr .bl{font-weight:800}.slr .ty{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700}.slr .ty.c{background:#fee2e2;color:#b91c1c}.slr .ty.k{background:#dcfce7;color:#166534}
.slr tfoot td{background:#fde68a;font-weight:800;position:sticky;bottom:0}
</style>
<div class="slr">
  <h2><i class="fa-solid fa-book-open" style="color:#1e3a8a"></i> Scheme Ledger</h2>
  <p class="sub">Statement per scheme: <b>Debit</b> = Claim Amount (Current Scheme Status upload) · <b>Credit</b> = Claim Certificate Actual Amount (Customer Claim Certificate Import) · <b>Balance</b> = Debit − Credit. The last (closing) balance is at the top.</p>
  <?php if (!$has_items): ?><div class="card" style="color:#92400e;background:#fffbeb">No scheme data yet — import the Excel in <a href="scheme_status_report.php">Current Scheme Status Import</a>.</div><?php endif; ?>

  <form class="card" method="get"><div class="ftog" id="slTog"><b><i class="fa-solid fa-sliders"></i> Filters</b><?php foreach ($active as $c): ?><span class="chip"><?= sl_h($c) ?></span><?php endforeach; if (!$active): ?><span style="color:#9ca3af;font-size:12.5px">none — click to open</span><?php endif; ?><i class="fa-solid fa-chevron-down" style="margin-left:auto"></i></div>
    <div class="fbody <?= $active ? 'collapsed' : '' ?>" id="slBody" style="margin-top:12px"><div class="row">
      <div class="fld"><label>Date from</label><input type="date" name="date_from" value="<?= sl_h($f_from) ?>"></div>
      <div class="fld"><label>Date to</label><input type="date" name="date_to" value="<?= sl_h($f_to) ?>"></div>
      <div class="fld"><label>MOC Ref</label><select name="moc"><option value="">All</option><?php foreach ($mocs as $m): ?><option <?= $f_moc === $m ? 'selected' : '' ?>><?= sl_h($m) ?></option><?php endforeach; ?></select></div>
      <div class="fld"><label>Scheme no / description</label><input type="search" name="q" value="<?= sl_h($f_q) ?>" placeholder="scheme no or description"></div>
      <label style="display:flex;gap:6px;align-items:center;font-size:13px;padding-bottom:8px"><input type="checkbox" name="oldest_first" value="1" <?= $f_asc ? 'checked' : '' ?>> Oldest first</label>
      <button class="btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Show</button><a class="btn o" href="scheme_ledger_report.php">Reset</a>
      <a class="btn o" data-no-loader href="?<?= sl_h(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a></div></div></form>

  <div class="hero"><div><small>Closing balance (last balance)</small><b style="color:<?= $closing > 0.5 ? '#fca5a5' : ($closing < -0.5 ? '#86efac' : '#fff') ?>">Rs. <?= sl_m($closing, false) ?></b></div>
    <div class="s"><small>Total debit (claims)</small><b>Rs. <?= number_format($tdr, 2) ?></b></div><div class="s"><small>Total credit (claim certificates)</small><b>Rs. <?= number_format($tcr, 2) ?></b></div>
    <?php if ($f_from): ?><div class="s"><small>Opening balance</small><b>Rs. <?= sl_m($open, false) ?></b></div><?php endif; ?><div class="s"><small>Schemes</small><b><?= number_format(count($schemes)) ?></b></div></div>

  <div class="tw"><table><thead><tr><th>Date</th><th>Scheme No</th><th>Description</th><th>Type</th><th>Reference</th><th class="r">Debit</th><th class="r">Credit</th><th class="r">Balance</th></tr></thead><tbody>
  <?php foreach ($stmt as $i => $en): ?><tr class="<?= $i === 0 && !$f_asc ? 'first' : '' ?>"><td style="white-space:nowrap"><?= sl_d($en['date']) ?></td><td><b><?= sl_h($en['scheme']) ?></b></td><td><?= sl_h($en['desc']) ?></td>
    <td><span class="ty <?= $en['type'] === 'Claim' ? 'c' : 'k' ?>"><?= sl_h($en['type']) ?></span></td><td style="font-size:12px;color:#4b5563;max-width:340px"><?= sl_h($en['ref']) ?></td>
    <td class="r dr"><?= $en['debit'] ? number_format($en['debit'], 2) : '' ?></td><td class="r cr"><?= $en['credit'] ? number_format($en['credit'], 2) : '' ?></td><td class="r bl"><?= sl_m($en['bal'], false) ?></td></tr>
  <?php endforeach; if (!$stmt): ?><tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:28px">No entries for these filters.</td></tr><?php endif; ?></tbody>
  <?php if ($stmt): ?><tfoot><tr><td colspan="5">TOTAL</td><td class="r"><?= number_format($tdr, 2) ?></td><td class="r"><?= number_format($tcr, 2) ?></td><td class="r"><?= sl_m($closing, false) ?></td></tr></tfoot><?php endif; ?></table></div>
</div>
<script>document.getElementById('slTog').addEventListener('click',function(){ document.getElementById('slBody').classList.toggle('collapsed'); });</script>
<?php include 'footer.php'; ?>
