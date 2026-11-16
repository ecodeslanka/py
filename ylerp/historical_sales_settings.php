<?php
/** historical_sales_settings.php — Analysis → Sales Range Settings (cycle, first month, cut-over date, sales value rule, forecast weights) */
date_default_timezone_set('Asia/Colombo');
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/historical_sales_lib.php';
function hz_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') { hs_save($conn, $_POST, $_SESSION['username'] ?? ($_SESSION['user_name'] ?? 'system')); $saved = true; }
$S = hs_settings($conn); $months = []; for ($i = 0; $i < $S['months']; $i++) $months[] = hs_add($S['first_cycle'], $i);
include 'header.php';
?>
<style>
.hz{max-width:900px;margin:0 auto;padding:16px;font-family:'Inter',system-ui,sans-serif;color:#111827}.hz h2{margin:0 0 3px;font-size:22px}.hz .sub{color:#6b7280;font-size:13px;margin:0 0 14px}
.hz .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 18px;margin-bottom:14px}.hz .g{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}.hz label{display:block;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:4px}
.hz input,.hz select{width:100%;padding:9px 10px;border:1.5px solid #d1d5db;border-radius:8px;font:inherit;font-size:14px}.hz small{color:#6b7280;font-size:12px;display:block;margin-top:3px}.hz .btn{padding:9px 20px;border:none;border-radius:8px;background:#1e3a8a;color:#fff;font:600 14px inherit;cursor:pointer}
.hz .ok{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;padding:10px 14px;border-radius:8px;margin-bottom:12px}.hz table{width:100%;border-collapse:collapse;font-size:13px}.hz td,.hz th{padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:left}.hz th{background:#eef2ff;color:#1e3a8a}
</style>
<div class="hz">
  <h2><i class="fa-solid fa-sliders" style="color:#1e3a8a"></i> Sales Range Settings</h2>
  <p class="sub">Rules used by <a href="historical_sales_trend_report.php">Historical Sales Trends</a>.</p>
  <?php if ($saved): ?><div class="ok"><i class="fa-solid fa-circle-check"></i> Saved. The report now uses these settings.</div><?php endif; ?>
  <form method="post" class="card">
    <div class="g">
      <div><label>Cycle start day</label><input type="number" name="start_day" min="1" max="28" value="<?= (int)$S['start_day'] ?>"><small>A sales month runs from this day of the previous month to the day before it (26 → "Nov-25" = 26 Oct 2025 – 25 Nov 2025). Use 1 for calendar months.</small></div>
      <div><label>First sales month</label><input type="month" name="first_cycle" value="<?= hz_h($S['first_cycle']) ?>"><small>The first column of the report (e.g. 2025-11).</small></div>
      <div><label>Number of months</label><input type="number" name="months" min="3" max="24" value="<?= (int)$S['months'] ?>"><small>Complete cycles shown (12 = Nov-25 … Oct-26). The next cycle is shown as "to date".</small></div>
      <div><label>Our own secondary import — from bill date</label><input type="date" name="cutover" value="<?= hz_h($S['cutover']) ?>"><small>Bills before this date come from Analysis ▸ Import Secondary Invoices; bills from this date from our system's secondary invoices.</small></div>
      <div><label>Sales value of a bill</label><select name="basis"><option value="final" <?= $S['basis'] === 'final' ? 'selected' : '' ?>>Total Discount + Final Bill Amount (to be collected)</option><option value="billvalue" <?= $S['basis'] === 'billvalue' ? 'selected' : '' ?>>Bill Value + Total Discount</option></select></div>
    </div>
    <h4 style="margin:18px 0 8px">Baseline weights</h4>
    <div class="g"><div><label>12-month average</label><input type="number" step="0.01" min="0" max="1" name="w12" value="<?= $S['w12'] ?>"></div><div><label>6-month average</label><input type="number" step="0.01" min="0" max="1" name="w6" value="<?= $S['w6'] ?>"></div><div><label>3-month average</label><input type="number" step="0.01" min="0" max="1" name="w3" value="<?= $S['w3'] ?>"></div><div><label>Latest month</label><input type="number" step="0.01" min="0" max="1" name="wl" value="<?= $S['wl'] ?>"></div></div>
    <small>Baseline = weighted sum of the four averages (the weights should add up to 1.00; now <?= number_format($S['w12'] + $S['w6'] + $S['w3'] + $S['wl'], 2) ?>).</small>
    <div style="margin-top:16px"><button class="btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save settings</button><?php if (!empty($S['updated_at'])): ?> <span style="color:#6b7280;font-size:12px;margin-left:10px">Last saved <?= hz_h($S['updated_at']) ?> by <?= hz_h($S['updated_by']) ?></span><?php endif; ?></div>
  </form>
  <div class="card"><b>Sales months with these settings</b><table><thead><tr><th>Month</th><th>From</th><th>To</th></tr></thead><tbody>
  <?php foreach ($months as $m): $rg = hs_range($m, $S['start_day']); ?><tr><td><b><?= hz_h(hs_label($m)) ?></b></td><td><?= hz_h($rg[0]) ?></td><td><?= hz_h($rg[1]) ?></td></tr><?php endforeach; ?></tbody></table></div>
</div>
<?php include 'footer.php'; ?>
