<?php
/**
 * unilever_ledger_report.php — ledger (statement) of one line of the dashboard's Unilever Receivable Summary
 * (Primary Sales Return, Damaged Claim, Muthupalasa BP Claims, SSCL & VAT, Drivers & Loyalty, Promoters Salary, SR Incentive, Others).
 * Debit = receivable / paid-out · Credit = received · Balance = running Debit − Credit · closing balance at the top, newest first.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
require_once __DIR__ . '/unilever_ledger_lib.php';
function ulr_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ulr_ok($d) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d); }
function ulr_d($v) { return $v ? date('d M Y', strtotime($v)) : '—'; }
function ulr_m($v, $dash = true) { if ($v === null || $v === '') return $dash ? '' : '0.00'; return $v < 0 ? '(' . number_format(abs($v), 2) . ')' : number_format($v, 2); }
$SRC = ul_sources(); $key = $_GET['src'] ?? '';
if (!isset($SRC[$key])) { while (ob_get_level()) ob_end_clean(); http_response_code(404); exit('Unknown ledger.'); }
$S = $SRC[$key];
if (!rbac_can('view', $S['page'])) rbac_deny('view', $S['page']);       // same right as the page the figures come from
$f_from = ulr_ok($_GET['date_from'] ?? '') ? $_GET['date_from'] : ''; $f_to = ulr_ok($_GET['date_to'] ?? '') ? $_GET['date_to'] : '';
if ($f_from && $f_to && $f_to < $f_from) [$f_from, $f_to] = [$f_to, $f_from];
$f_asc = !empty($_GET['oldest_first']);
$csv = fn($v) => array_values(array_filter(array_map('intval', is_array($v) ? $v : preg_split('/[,\s]+/', (string)$v, -1, PREG_SPLIT_NO_EMPTY))));
$f_years = $csv($_GET['years'] ?? ''); $f_months = $csv($_GET['months'] ?? '');
$L = ul_compute($conn, $key, ['from' => $f_from, 'to' => $f_to, 'years' => $f_years, 'months' => $f_months]);
$stmt = $L['stmt']; $open = $L['open']; $closing = $L['closing']; $tdr = $L['debit']; $tcr = $L['credit'];
if (!$f_asc) $stmt = array_reverse($stmt);

if (isset($_GET['export']) && class_exists('ZipArchive')) {
    require_once __DIR__ . '/rbac_xlsx.php'; while (ob_get_level()) ob_end_clean();
    $X = new MiniXlsx(); $sh = $X->sheet(mb_substr($S['label'], 0, 28)); $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '1E3A8A', 'h' => 'center', 'border' => 1, 'wrap' => 1];
    $sh->set(1, 1, $S['label'] . ' — ledger', ['b' => 1, 'sz' => 15]); $sh->set(2, 1, 'Closing balance', ['b' => 1]); $sh->set(2, 2, $closing, ['b' => 1, 'nf' => 4, 'h' => 'right']);
    foreach (['Date', 'Type', 'Reference', 'Description', 'Debit', 'Credit', 'Balance'] as $i => $h) $sh->set(4, $i + 1, $h, $H);
    $r = 5; if ($f_from) { $sh->set($r, 4, 'Opening balance', ['i' => 1, 'border' => 1]); $sh->set($r, 7, round($open, 2), ['border' => 1, 'nf' => 4, 'h' => 'right']); $r++; }
    foreach ($stmt as $en) { foreach ([ulr_d($en['date']) === '—' ? '' : ulr_d($en['date']), $en['type'], $en['ref'], $en['desc'], $en['debit'] ?: '', $en['credit'] ?: '', $en['bal']] as $i => $v) $sh->set($r, $i + 1, $v, ['border' => 1] + ($i >= 4 ? ['h' => 'right', 'nf' => 4] : [])); $r++; }
    $tb = ['b' => 1, 'border' => 1, 'bg' => 'FDE68A', 'h' => 'right', 'nf' => 4]; $sh->set($r, 4, 'TOTAL', ['b' => 1, 'border' => 1, 'bg' => 'FDE68A']); $sh->set($r, 5, round($tdr, 2), $tb); $sh->set($r, 6, round($tcr, 2), $tb); $sh->set($r, 7, $closing, $tb);
    foreach ([13, 18, 34, 60, 15, 15, 16] as $i => $wd) $sh->widths[$i + 1] = $wd; $sh->freeze = [4, 0];
    $tmp = tempnam(sys_get_temp_dir(), 'ul'); $X->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="ledger_' . $key . '_' . date('Ymd_His') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
ob_end_flush();
include 'header.php';
$active = array_filter([$f_from || $f_to ? 'Date: ' . ($f_from ? ulr_d($f_from) : '…') . ' → ' . ($f_to ? ulr_d($f_to) : '…') : '', $f_years ? 'Year ' . implode(', ', $f_years) : '', $f_months ? 'Month ' . implode(', ', array_map(fn($m) => date('M', mktime(0, 0, 0, $m, 1)), $f_months)) : '', $f_asc ? 'Oldest first' : '']);
?>
<style>
.ulr{max-width:none;width:100%;margin:0;padding:12px;font-family:'Inter',system-ui,sans-serif;color:#111827}.ulr h2{margin:0 0 3px;font-size:22px}.ulr .sub{color:#6b7280;font-size:13px;margin:0 0 14px}
.ulr .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;margin-bottom:14px}.ulr .row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.ulr .fld{display:flex;flex-direction:column;gap:3px}.ulr .fld label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase}
.ulr input,.ulr select{padding:8px 10px;border:1.5px solid #d1d5db;border-radius:8px;font:inherit;font-size:13px}.ulr .btn{padding:8px 16px;border:none;border-radius:8px;background:#1e3a8a;color:#fff;font:600 13px inherit;cursor:pointer;text-decoration:none;display:inline-flex;gap:6px;align-items:center}.ulr .btn.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe}
.ulr .chip{display:inline-block;margin:6px 6px 0 0;font-size:11.5px;font-weight:600;color:#1e3a8a;background:#eff6ff;border:1px solid #bfdbfe;border-radius:99px;padding:2px 10px}.ulr .ftog{cursor:pointer;display:flex;align-items:center;gap:10px;user-select:none}.ulr .fbody.collapsed{display:none}
.ulr .hero{background:linear-gradient(120deg,#0f172a,#1e3a8a 60%,#0e7490);color:#fff;border-radius:14px;padding:18px 22px;margin-bottom:14px;display:flex;gap:26px;flex-wrap:wrap;align-items:center}.ulr .hero small{display:block;font-size:11px;letter-spacing:.07em;text-transform:uppercase;opacity:.75;font-weight:700}.ulr .hero b{font-size:30px;font-variant-numeric:tabular-nums}.ulr .hero .s b{font-size:19px}
.ulr .tw{overflow:auto;border:1px solid #e5e7eb;border-radius:10px;background:#fff;max-height:72vh}.ulr table{width:100%;border-collapse:collapse;font-size:13px}.ulr th{background:#1e3a8a;color:#fff;padding:9px 10px;text-align:left;position:sticky;top:0;white-space:nowrap}.ulr td{padding:8px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top}.ulr .r{text-align:right;font-variant-numeric:tabular-nums}.ulr tr:hover td{background:#f8fafc}
.ulr tr.first td{background:#eef2ff;font-weight:700}.ulr .dr{color:#b91c1c;font-weight:700}.ulr .cr{color:#15803d;font-weight:700}.ulr .bl{font-weight:800}.ulr .ty{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;background:#e0e7ff;color:#3730a3;white-space:nowrap}.ulr .ty.k{background:#dcfce7;color:#166534}
.ulr tfoot td{background:#fde68a;font-weight:800;position:sticky;bottom:0}
</style>
<div class="ulr">
  <h2><i class="fa-solid <?= ulr_h($S['icon']) ?>" style="color:#1e3a8a"></i> <?= ulr_h($S['label']) ?> — Ledger</h2>
  <p class="sub"><b>Debit</b> = <?= ulr_h($S['dr']) ?> · <b>Credit</b> = <?= ulr_h($S['cr']) ?> · <b>Balance</b> = Debit − Credit (positive = Unilever still owes us). The last (closing) balance is at the top.
     <a href="<?= ulr_h($S['page']) ?>.php" style="margin-left:8px;font-weight:700;color:#1e3a8a"><i class="fa-solid fa-up-right-from-square"></i> Open the <?= ulr_h($S['label']) ?> page</a></p>

  <form class="card" method="get"><input type="hidden" name="src" value="<?= ulr_h($key) ?>">
    <div class="ftog" id="ulTog"><b><i class="fa-solid fa-sliders"></i> Filters</b><?php foreach ($active as $c): ?><span class="chip"><?= ulr_h($c) ?></span><?php endforeach; if (!$active): ?><span style="color:#9ca3af;font-size:12.5px">none — click to open</span><?php endif; ?><i class="fa-solid fa-chevron-down" style="margin-left:auto"></i></div>
    <div class="fbody <?= $active ? 'collapsed' : '' ?>" id="ulBody" style="margin-top:12px"><div class="row">
      <div class="fld"><label>Date from</label><input type="date" name="date_from" value="<?= ulr_h($f_from) ?>"></div>
      <div class="fld"><label>Date to / as at</label><input type="date" name="date_to" value="<?= ulr_h($f_to) ?>"></div>
      <?php if ($f_years): ?><input type="hidden" name="years" value="<?= ulr_h(implode(',', $f_years)) ?>"><?php endif; ?><?php if ($f_months): ?><input type="hidden" name="months" value="<?= ulr_h(implode(',', $f_months)) ?>"><?php endif; ?>
      <label style="display:flex;gap:6px;align-items:center;font-size:13px;padding-bottom:8px"><input type="checkbox" name="oldest_first" value="1" <?= $f_asc ? 'checked' : '' ?>> Oldest first</label>
      <button class="btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Show</button><a class="btn o" href="unilever_ledger_report.php?src=<?= ulr_h($key) ?>">Reset</a>
      <a class="btn o" data-no-loader href="?<?= ulr_h(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a></div></div></form>

  <div class="hero"><div><small>Closing balance (last balance)</small><b style="color:<?= $closing > 0.5 ? '#fca5a5' : ($closing < -0.5 ? '#86efac' : '#fff') ?>">Rs. <?= ulr_m($closing, false) ?></b></div>
    <div class="s"><small>Total debit</small><b>Rs. <?= number_format($tdr, 2) ?></b></div><div class="s"><small>Total credit</small><b>Rs. <?= number_format($tcr, 2) ?></b></div>
    <?php if ($f_from): ?><div class="s"><small>Opening balance</small><b>Rs. <?= ulr_m($open, false) ?></b></div><?php endif; ?></div>

  <div class="tw"><table><thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Description</th><th class="r">Debit</th><th class="r">Credit</th><th class="r">Balance</th></tr></thead><tbody>
  <?php foreach ($stmt as $i => $en): ?><tr class="<?= $i === 0 && !$f_asc ? 'first' : '' ?>"><td style="white-space:nowrap"><?= ulr_d($en['date']) ?></td><td><span class="ty <?= $en['credit'] ? 'k' : '' ?>"><?= ulr_h($en['type']) ?></span></td>
    <td style="font-size:12px;color:#374151;max-width:260px"><?= ulr_h($en['ref']) ?></td><td style="max-width:520px"><?= ulr_h($en['desc']) ?></td>
    <td class="r dr"><?= $en['debit'] ? number_format($en['debit'], 2) : '' ?></td><td class="r cr"><?= $en['credit'] ? number_format($en['credit'], 2) : '' ?></td><td class="r bl"><?= ulr_m($en['bal'], false) ?></td></tr>
  <?php endforeach; if (!$stmt): ?><tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:28px">No entries for these filters.</td></tr><?php endif; ?></tbody>
  <?php if ($stmt): ?><tfoot><tr><td colspan="4">TOTAL</td><td class="r"><?= number_format($tdr, 2) ?></td><td class="r"><?= number_format($tcr, 2) ?></td><td class="r"><?= ulr_m($closing, false) ?></td></tr></tfoot><?php endif; ?></table></div>
</div>
<script>document.getElementById('ulTog').addEventListener('click',function(){ document.getElementById('ulBody').classList.toggle('collapsed'); });</script>
<?php include 'footer.php'; ?>
