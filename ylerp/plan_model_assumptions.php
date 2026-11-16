<?php
/**
 * plan_model_assumptions.php — Analysis → Model Assumptions & Methodology
 * The workbook's sheet 13 as a live page: every weight, scale, band and rule threshold the planning model uses is editable here (stored in
 * plan_assumptions; the model rebuilds on save). Also: pace share, forecast back-test, risk outcome check, category summary and the optional
 * SKU-count import for the "fewer SKUs than the channel median" headroom rule.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
@set_time_limit(0);
require_once __DIR__ . '/plan_lib.php'; require_once __DIR__ . '/plan_model_lib.php'; require_once __DIR__ . '/analysis_xlsx_lib.php';
$e = fn($v) => mysqli_real_escape_string($conn, (string)$v);
pm_load_assumptions($conn);
pl_q($conn, "CREATE TABLE IF NOT EXISTS plan_sku_counts (t_code VARCHAR(60) PRIMARY KEY, sku_count INT NOT NULL, loaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* field list: key => [label, group, type (pct = shown ×100, num, days), hint] */
$F = [
 'w_viol' => ['Credit policy violations', 'Risk score weights', 'pct', ''], 'w_chq' => ['Cheque returns', 'Risk score weights', 'pct', ''], 'w_bl' => ['Blacklisting status', 'Risk score weights', 'pct', ''],
 'w_late' => ['Payment delays & overdue', 'Risk score weights', 'pct', ''], 'w_sb' => ['Cheques sent back', 'Risk score weights', 'pct', ''], 'w_canc' => ['Bill cancellation', 'Risk score weights', 'pct', ''], 'w_irr' => ['Billing irregularity', 'Risk score weights', 'pct', ''],
 'viol_n' => ['Violations for a full score', 'Sub-score scales', 'num', '0.5 × min(violations ÷ this, 1)'], 'viol_days' => ['Average days late for a full score', 'Sub-score scales', 'days', '0.3 × min(avg days over ÷ this, 1)'],
 'viol_recent' => ['"Recent violation" window', 'Sub-score scales', 'days', '+0.2 if a violation in this many days'], 'chq_n' => ['Returned cheques for a full score', 'Sub-score scales', 'num', '0.6 × min(returns ÷ this, 1)'],
 'chq_recent' => ['"Recent return" window', 'Sub-score scales', 'days', '+0.15 if a return in this many days'], 'bl_perm' => ['Blacklist: permanent / not serviced', 'Sub-score scales', 'pct', ''], 'bl_temp' => ['Blacklist: temporary block', 'Sub-score scales', 'pct', ''],
 'bl_cod' => ['Blacklist: block cash / cheque on delivery', 'Sub-score scales', 'pct', ''], 'late_days' => ['Open days over for a full score', 'Sub-score scales', 'days', '0.5 × min(max open days over ÷ this, 1)'],
 'sb_n' => ['Sent-back cheques for a full score', 'Sub-score scales', 'num', '0.7 × min(sent back ÷ this, 1)'], 'canc_full' => ['Cancel rate for a full score', 'Sub-score scales', 'pct', 'min(cancel rate ÷ this, 1)'],
 'band_b' => ['Band B (Moderate) from', 'Risk bands', 'num', 'score'], 'band_c' => ['Band C (Elevated) from', 'Risk bands', 'num', 'score'], 'band_d' => ['Band D (High) from', 'Risk bands', 'num', 'score'], 'band_e' => ['Band E (Critical) from', 'Risk bands', 'num', 'score'],
 'seg_restrict' => ['Cat 5 Restrict credit: risk above', 'Segmentation', 'num', ''], 'seg_decline' => ['Cat 7 Declining: 3M avg below this share of prior months', 'Segmentation', 'pct', ''],
 'seg_control' => ['Cat 4 Control & collect: risk above', 'Segmentation', 'num', 'also the moderate-growth risk ceiling'], 'seg_od_days' => ['Cat 4: open invoice more than … days overdue', 'Segmentation', 'days', ''],
 'seg_rep_n' => ['Repeat violator: violations at least', 'Segmentation', 'num', ''], 'seg_rep_days' => ['Repeat violator: average days late at least', 'Segmentation', 'days', ''],
 'seg_min_obs' => ['Cat 8 New: fewer than … observed months', 'Segmentation', 'num', ''], 'seg_hist' => ['Cat 1–2: at least … months of history', 'Segmentation', 'num', ''],
 'seg_active6' => ['Cat 1–2: active in at least … of the last 6 months', 'Segmentation', 'num', ''], 'seg_stable' => ['Cat 1–2: 3M avg at least this share of 6M avg', 'Segmentation', 'pct', ''],
 'seg_dev_risk' => ['Cat 1 Develop aggressively: risk at most', 'Segmentation', 'num', ''], 'seg_head' => ['Cat 1 headroom: channel median above baseline by more than', 'Segmentation', 'pct', ''],
 'seg_sku' => ['Cat 1 headroom: or at least … fewer SKUs than channel median', 'Segmentation', 'num', 'needs SKU counts (below)'], 'seg_trend' => ['Cat 1: trend not worse than (per month)', 'Segmentation', 'pct', ''],
 'gr_base' => ['Cat 1 growth: base', 'Growth & targets', 'pct', 'growth = base + gap factor × gap'], 'gr_gap' => ['Cat 1 growth: gap factor', 'Growth & targets', 'pct', ''], 'gr_min' => ['Cat 1 growth: minimum', 'Growth & targets', 'pct', ''], 'gr_max' => ['Cat 1 growth: maximum', 'Growth & targets', 'pct', ''],
 'gr_mod_low' => ['Cat 2 growth (risk ≤ cat-1 risk)', 'Growth & targets', 'pct', ''], 'gr_mod' => ['Cat 2 growth (higher risk)', 'Growth & targets', 'pct', ''], 'restrict_pct' => ['Cat 5 credit outlets: share of run-rate', 'Growth & targets', 'pct', ''],
 'react_pct' => ['Dormant reactivation: share of prior active-month avg', 'Growth & targets', 'pct', ''], 'recover_pct' => ['Declining recovery: up to this share of prior months', 'Growth & targets', 'pct', ''],
 'sc_uplift' => ['Max-risk uplift on baseline', 'Scenarios', 'pct', 'baseline + this (block lifted, healthy outlets)'], 'sc_peer' => ['Peer-gap share', 'Scenarios', 'pct', 'share of (channel median − target)'],
 'sc_mod_share' => ['Moderate = min + this share of (max − min)', 'Scenarios', 'pct', ''], 'sc_mod_risk' => ['Moderate applies to outlets with risk at most', 'Scenarios', 'num', ''],
 'pace_share' => ['Pace: share of cycle sales billed by today (0 = automatic)', 'Scenarios', 'pct', 'override the automatic value below'],
];
$act = $_POST['action'] ?? '';
if ($act !== '') {
    if (!pl_can_edit()) pl_json(['ok' => false, 'msg' => 'You do not have the Edit right on this page.']);
    if ($act === 'save') {
        $in = (array)json_decode($_POST['vals'] ?? '{}', true); $u = $e(pl_user()); $n = 0;
        foreach ($F as $k => $f) { if (!isset($in[$k]) || !is_numeric($in[$k])) continue; $v = (float)$in[$k]; if ($f[2] === 'pct') $v /= 100;
            pl_q($conn, "REPLACE INTO plan_assumptions (k, v, updated_by) VALUES ('{$e($k)}', $v, '$u')"); $n++; }
        if (isset($in['fw']) && is_array($in['fw'])) { $S = hs_settings($conn); foreach (['w12', 'w6', 'w3', 'wl'] as $i => $k) if (isset($in['fw'][$i]) && is_numeric($in['fw'][$i])) $S[$k] = (float)$in['fw'][$i] / 100; hs_save($conn, $S, pl_user()); }
        pl_json(['ok' => true, 'msg' => "Saved $n assumptions — the planning reports recalculate on their next load."]);
    }
    if ($act === 'reset') { pl_q($conn, "DELETE FROM plan_assumptions"); pl_json(['ok' => true, 'msg' => 'All assumptions are back to the workbook defaults.']); }
    if ($act === 'sku_clear') { pl_q($conn, "DELETE FROM plan_sku_counts"); pl_json(['ok' => true, 'msg' => 'SKU counts cleared.']); }
    if ($act === 'sku_upload') {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) pl_json(['ok' => false, 'msg' => 'Upload failed.']);
        $f = $_FILES['file']; $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)); $rows = [];
        if ($ext === 'csv') { $h = fopen($f['tmp_name'], 'r'); while (($r = fgetcsv($h)) !== false) $rows[] = $r; fclose($h); }
        elseif ($ext === 'xlsx') { $sh = as_first_sheet($f['tmp_name']); $ss = as_shared_strings($f['tmp_name']); as_each_row($f['tmp_name'], $sh[1], $ss, function ($rn, $c) use (&$rows) { $rows[] = $c; return true; }); }
        else pl_json(['ok' => false, 'msg' => 'Upload a .csv or .xlsx with columns: HUL code, SKU count.']);
        $ci = null; $cs = null; $n = 0; $vals = [];
        foreach ($rows as $r) { if ($ci === null) { foreach ($r as $i => $v) { $k = preg_replace('/[^a-z]/', '', strtolower((string)$v)); if (in_array($k, ['hul', 'hulcode', 'tcode', 'outlethulcode', 'outletcode'], true)) $ci = $i; if (strpos($k, 'sku') !== false) $cs = $i; } if ($ci !== null && $cs !== null) continue; $ci = null; $cs = null; continue; }
            $c = strtoupper(trim((string)($r[$ci] ?? ''))); $v = trim((string)($r[$cs] ?? '')); if ($c === '' || !is_numeric($v)) continue; $vals[] = "('{$e($c)}', " . (int)$v . ")"; $n++;
            if (count($vals) >= 500) { pl_q($conn, "REPLACE INTO plan_sku_counts (t_code, sku_count) VALUES " . implode(',', $vals)); $vals = []; } }
        if ($vals) pl_q($conn, "REPLACE INTO plan_sku_counts (t_code, sku_count) VALUES " . implode(',', $vals));
        pl_json($n ? ['ok' => true, 'msg' => "Loaded SKU counts for $n outlets."] : ['ok' => false, 'msg' => 'No rows found — the file needs a header row with "HUL" and "SKU" columns.']);
    }
    pl_json(['ok' => false, 'msg' => 'Unknown action.']);
}
$M = pm_get($conn, isset($_GET['recalc'])); $St = $M['stats'] ?? []; $S = hs_settings($conn); $A = pm_load_assumptions($conn); $D = pm_defaults();
$skuN = 0; $r = pl_q($conn, "SELECT COUNT(*) FROM plan_sku_counts"); if ($r) $skuN = (int)mysqli_fetch_row($r)[0];
$chg = []; $r = pl_q($conn, "SELECT k, updated_by, updated_at FROM plan_assumptions ORDER BY updated_at DESC LIMIT 1"); $last = $r ? mysqli_fetch_assoc($r) : null;
ob_end_flush();
include 'header.php';
echo pl_css();
$can = pl_can_edit(); $fmt = fn($k) => $F[$k][2] === 'pct' ? round($A[$k] * 100, 3) : $A[$k];
$groups = []; foreach ($F as $k => $f) $groups[$f[1]][$k] = $f;
$band = fn($s) => pm_band($s);
?>
<style>
.ma .sec{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px 18px;margin-bottom:14px}.ma h3{margin:0 0 4px;font-size:16px;color:#1e3a8a;display:flex;gap:8px;align-items:center}.ma .note{font-size:12.5px;color:#64748b;margin:0 0 10px;line-height:1.55}
.ma table{width:100%;border-collapse:collapse;font-size:13px}.ma th{background:#eef2ff;color:#1e3a8a;text-align:left;padding:7px 10px;font-size:11.5px;text-transform:uppercase;letter-spacing:.3px}.ma td{padding:7px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top;text-align:left;white-space:normal}.ma th{text-align:left}.ma td.n,.ma th.n{text-align:right;font-variant-numeric:tabular-nums}
.ma input.av{width:110px;text-align:right;padding:6px 8px;border:1.5px solid #93c5fd;background:#eff6ff;color:#1d4ed8;font-weight:700;border-radius:8px;font-family:inherit}.ma input.av.ch{border-color:#f59e0b;background:#fffbeb;color:#92400e}.ma .def{font-size:11.5px;color:#94a3b8}
.ma .ok{color:#15803d;font-weight:700}.ma .bad{color:#b91c1c;font-weight:700}.ma .rule{line-height:1.6}.ma .rule b{color:#1e3a8a}.ma .bar{position:sticky;top:0;z-index:20;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:10px 14px;margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;box-shadow:0 6px 18px -12px rgba(15,23,42,.4)}
</style>
<div class="pl ma">
  <div class="hero" style="background:linear-gradient(120deg,#0f172a,#334155 55%,#64748b)"><h2><i class="fa-solid fa-book"></i> Model Assumptions &amp; Methodology</h2>
    <p>Every number the planning model uses — risk weights, scales, bands, segmentation thresholds, growth, scenarios. <b>Blue = editable</b>; amber = changed from the workbook default. Saving rebuilds Development Opportunities, Restricted &amp; High-Risk, Target Scenarios, Credit Policy, Billing Frequency and Credit Limits. The rules were checked against the planning workbook (3,768 outlets): <b>risk score 100 %, category 100 %, growth 100 %, recommended target 99.7 %, min / max scenario 99.7 % / 99.9 %</b> identical. Model built <b><?= pl_h($M['built_at']) ?></b>.</p></div>
  <div class="bar"><?php if ($can): ?><button class="btn" id="mSave"><i class="fa-solid fa-floppy-disk"></i> Save assumptions</button><button class="btn o" id="mReset"><i class="fa-solid fa-rotate-left"></i> Reset to workbook defaults</button><?php else: ?><span style="color:#b45309">Edit right needed to change assumptions.</span><?php endif; ?>
    <a class="btn o" href="?recalc=1"><i class="fa-solid fa-rotate"></i> Recalculate now</a><span id="mMsg" style="font-size:13px"></span><span style="margin-left:auto;font-size:12px;color:#64748b"><?= $last ? 'Last change ' . pl_h($last['updated_at']) . ' by ' . pl_h($last['updated_by']) : 'All values are the workbook defaults.' ?></span></div>

  <div class="sec"><h3><i class="fa-solid fa-chart-line"></i> Forecast weights (baseline)</h3><p class="note">Baseline = 12-month avg × w12 + 6-month avg × w6 + 3-month avg × w3 + latest month × wL. Same weights as Sales Range Settings. The back-test below shows which mix forecast best on your data.</p>
    <table><thead><tr><th>Weight</th><th class="n">Value (%)</th><th>Default</th></tr></thead><tbody>
    <?php foreach ([['12-month average', 'w12', 20], ['6-month average', 'w6', 30], ['3-month average', 'w3', 35], ['Latest month', 'wl', 15]] as $i => [$lab, $k, $d]): ?><tr><td><?= $lab ?></td><td class="n"><input class="av fw" data-i="<?= $i ?>" data-def="<?= $d ?>" type="number" step="0.5" value="<?= round($S[$k] * 100, 2) ?>"<?= $can ? '' : ' disabled' ?>></td><td class="def"><?= $d ?>%</td></tr><?php endforeach; ?>
    <tr><td><b>Check: weights sum</b></td><td class="n" id="fwSum"></td><td></td></tr></tbody></table></div>

  <?php foreach ($groups as $g => $items): ?><div class="sec"><h3><i class="fa-solid fa-sliders"></i> <?= pl_h($g) ?></h3>
    <?php if ($g === 'Risk score weights'): ?><p class="note">Risk score (0–100) = 100 × Σ sub-score (0–1) × weight. Sub-scores: violations 0.5·min(n/<?= $A['viol_n'] ?>,1)+0.3·min(days/<?= $A['viol_days'] ?>,1)+0.2·recent · cheques 0.6·min(n/<?= $A['chq_n'] ?>,1)+0.25·unsettled+0.15·recent · overdue 0.5·min(days/<?= $A['late_days'] ?>,1)+0.3·min(overdue ÷ 3M sales,1)+0.2·"cheques will delay" · sent back 0.7·min(n/<?= $A['sb_n'] ?>,1)+0.3·unsettled · cancellation min(rate/<?= round($A['canc_full'] * 100) ?>%,1) · irregularity min(bill-count CV,1). Cash outlets score 0 on violations (not applicable, not "good").</p><?php endif; ?>
    <?php if ($g === 'Risk bands'): ?><p class="note">A Low — eligible for development · B Moderate — normal sales with monitoring · C Elevated — controlled sales and collections · D High — restrict additional credit · E Critical — recovery / management review. A blacklisted outlet is always category 6 whatever its score.</p><?php endif; ?>
    <?php if ($g === 'Segmentation'): ?><p class="note rule">Applied in this order, first match wins: <b>6 Blocked</b> (blacklisted) → <b>5 Restrict credit</b> (risk &gt; <?= $A['seg_restrict'] ?>) → <b>7 Dormant / reactivation</b> (no purchase in the last 3 cycles or the open one, or 3M avg &lt; <?= round($A['seg_decline'] * 100) ?>% of prior months) → <b>4 Control &amp; collect</b> (risk &gt; <?= $A['seg_control'] ?>, open invoice &gt; <?= $A['seg_od_days'] ?> days overdue, unsettled cheques, or repeat violator <?= $A['seg_rep_n'] ?>+ / <?= $A['seg_rep_days'] ?>+ days) → <b>8 New</b> (&lt; <?= $A['seg_min_obs'] ?> months, or first purchase in the last 3 cycles) → <b>1 Develop aggressively</b> (risk ≤ <?= $A['seg_dev_risk'] ?>, steady, headroom, trend ≥ <?= round($A['seg_trend'] * 100) ?>%/month) → <b>2 Develop moderately</b> (risk ≤ <?= $A['seg_control'] ?>, <?= $A['seg_hist'] ?>+ months, active <?= $A['seg_active6'] ?>/6, 3M ≥ <?= round($A['seg_stable'] * 100) ?>% of 6M) → <b>3 Maintain</b>.</p><?php endif; ?>
    <?php if ($g === 'Growth & targets'): ?><p class="note">Recommended target = MAX(0, ROUND(MIN(baseline + baseline × growth, capacity), −2)); categories 7 and 8 use the capacity. Capacity: cat 1–3 baseline + growth · cat 4 MIN(baseline, 3M avg) · cat 5 credit outlets <?= round($A['restrict_pct'] * 100) ?>% of that · cat 6 cash/cheque-on-delivery MIN(baseline, 3M avg), other blocks 0 · cat 7 dormant 0, declining 3M avg · cat 8 MIN(baseline, active-month avg).</p><?php endif; ?>
    <table><thead><tr><th>Assumption</th><th class="n">Value</th><th>Workbook default</th><th>How it is used</th></tr></thead><tbody>
    <?php foreach ($items as $k => $f): $isP = $f[2] === 'pct'; ?><tr><td><?= pl_h($f[0]) ?></td><td class="n"><input class="av" data-k="<?= $k ?>" data-def="<?= $isP ? round($D[$k] * 100, 3) : $D[$k] ?>" type="number" step="<?= $isP ? '0.5' : '1' ?>" value="<?= $fmt($k) ?>"<?= $can ? '' : ' disabled' ?>> <?= $isP ? '%' : ($f[2] === 'days' ? 'days' : '') ?></td><td class="def"><?= $isP ? round($D[$k] * 100, 3) . '%' : $D[$k] ?></td><td style="font-size:12px;color:#64748b"><?= pl_h($f[3]) ?></td></tr><?php endforeach; ?>
    <?php if ($g === 'Risk score weights'): ?><tr><td><b>Check: weights sum</b></td><td class="n" id="rwSum"></td><td></td><td></td></tr><?php endif; ?>
    </tbody></table></div><?php endforeach; ?>

  <?php $pc = $St['pace'] ?? null; if ($pc): ?><div class="sec"><h3><i class="fa-solid fa-gauge"></i> Pace — share of a cycle's sales billed in its first <?= (int)$pc['days'] ?> days</h3>
    <p class="note">The open cycle has run <?= (int)$pc['days'] ?> of <?= (int)$pc['len'] ?> days. Pace projection (Target Scenarios) = sales to date ÷ this share. Automatic value = average of the last 6 complete cycles<?= $A['pace_share'] > 0 ? ' — <b>overridden</b> with ' . round($A['pace_share'] * 100, 1) . '%' : '' ?>.</p>
    <table><thead><tr><th>Cycle</th><th class="n">Share in first <?= (int)$pc['days'] ?> days</th></tr></thead><tbody><?php foreach ($pc['by'] as $m => $v): ?><tr><td><?= hs_label($m) ?></td><td class="n"><?= round($v * 100, 1) ?>%</td></tr><?php endforeach; ?>
    <tr><td><b>Average (automatic)</b></td><td class="n"><b><?= $pc['auto'] === null ? '—' : round($pc['auto'] * 100, 1) . '%' ?></b></td></tr></tbody></table></div><?php endif; ?>

  <?php $bt = $St['backtest'] ?? null; if ($bt): $bm = array_keys($bt['actual']); ?><div class="sec"><h3><i class="fa-solid fa-flask"></i> Forecast back-test</h3>
    <p class="note">Each of the last cycles is forecast from the months before it only, by six methods, and compared with what was actually sold (Party Master outlets). <b>Outlet WAPE</b> = Σ|forecast − actual| ÷ Σ actual (lower is better). <b>Company bias</b> = Σ forecast ÷ Σ actual − 1 (+ = over-forecast).</p>
    <div style="overflow:auto"><table><thead><tr><th>Method</th><th class="n">Outlet WAPE</th><th class="n">Company bias</th><?php foreach ($bm as $m) echo '<th class="n">Fcst ' . hs_label($m) . '</th>'; ?></tr></thead><tbody>
    <?php $best = null; foreach ($bt['rows'] as $x) if ($x['wape'] !== null && ($best === null || $x['wape'] < $best)) $best = $x['wape'];
    foreach ($bt['rows'] as $x): ?><tr><td><?= $x['wape'] === $best ? '<b class="ok">' . pl_h($x['method']) . ' ★</b>' : pl_h($x['method']) ?></td><td class="n"><?= $x['wape'] === null ? '—' : round($x['wape'] * 100, 1) . '%' ?></td><td class="n"><?= $x['bias'] === null ? '—' : round($x['bias'] * 100, 1) . '%' ?></td><?php foreach ($bm as $m) echo '<td class="n">' . number_format($x['by'][$m] ?? 0) . '</td>'; ?></tr><?php endforeach; ?>
    <tr><td><b>Actual</b></td><td></td><td></td><?php foreach ($bm as $m) echo '<td class="n"><b>' . number_format($bt['actual'][$m]) . '</b></td>'; ?></tr></tbody></table></div></div><?php endif; ?>

  <?php $oc = $St['outcome'] ?? null; if ($oc): ?><div class="sec"><h3><i class="fa-solid fa-magnifying-glass-chart"></i> Risk model outcome check</h3>
    <p class="note">Credit / cheque outlets: payment incidents (policy violations, returned / sent-back cheques) before <?= pl_h($oc['split']) ?> against any incident after it. If past incidents predict repeat incidents, the risk score is pointing the right way.</p>
    <table><thead><tr><th>Incidents before <?= pl_h($oc['split']) ?></th><th class="n">Outlets</th><th class="n">Had an incident after</th><th class="n">Repeat rate</th></tr></thead><tbody>
    <?php foreach ($oc['rows'] as $k => [$n, $a]): ?><tr><td><?= $k ?></td><td class="n"><?= number_format($n) ?></td><td class="n"><?= number_format($a) ?></td><td class="n"><b><?= $n ? round($a / $n * 100, 1) . '%' : '—' ?></b></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>

  <?php if (!empty($St['cats'])): ?><div class="sec"><h3><i class="fa-solid fa-layer-group"></i> Operational categories (current model run)</h3><table><thead><tr><th>Category</th><th class="n">Outlets</th><th class="n">Baseline</th><th class="n">Recommended</th><th class="n">Change</th></tr></thead><tbody>
    <?php $t = [0, 0, 0]; foreach ($St['cats'] as $c => [$n, $b, $g]): $t[0] += $n; $t[1] += $b; $t[2] += $g; ?><tr><td><?= pl_h($c) ?></td><td class="n"><?= number_format($n) ?></td><td class="n"><?= number_format($b) ?></td><td class="n"><?= number_format($g) ?></td><td class="n"><?= $b > 0 ? round(($g / $b - 1) * 100, 1) . '%' : '' ?></td></tr><?php endforeach; ?>
    <tr><td><b>Total</b></td><td class="n"><b><?= number_format($t[0]) ?></b></td><td class="n"><b><?= number_format($t[1]) ?></b></td><td class="n"><b><?= number_format($t[2]) ?></b></td><td class="n"><b><?= $t[1] > 0 ? round(($t[2] / $t[1] - 1) * 100, 1) . '%' : '' ?></b></td></tr></tbody></table></div><?php endif; ?>

  <div class="sec"><h3><i class="fa-solid fa-boxes-stacked"></i> SKU counts (optional)</h3><p class="note">The workbook also finds headroom when an outlet buys <?= $A['seg_sku'] ?>+ fewer SKUs than its channel median (from the Invoice Wise Sales <b>Details</b> report). Our system has no SKU lines, so upload a .csv / .xlsx with columns <b>HUL</b> and <b>SKU count</b> to switch this rule on. Loaded now: <b><?= number_format($skuN) ?></b> outlets.</p>
    <?php if ($can): ?><div class="row"><input type="file" id="skuF" accept=".csv,.xlsx"><button class="btn o" id="skuU"><i class="fa-solid fa-upload"></i> Upload SKU counts</button><button class="btn o" id="skuC">Clear</button><span id="skuM" style="font-size:12.5px"></span></div><?php endif; ?></div>

  <div class="sec"><h3><i class="fa-solid fa-circle-info"></i> Definitions, sources and governance</h3><ul class="note" style="margin:0;padding-left:18px">
    <li><b>Sales</b> of a bill = <?= $S['basis'] === 'billvalue' ? 'Bill Value + Total Discount' : 'Total Discount + Final Bill Amount (to be collected)' ?> (Sales Range Settings).</li>
    <li><b>Sales month</b> = company cycle: day <?= (int)$S['start_day'] ?> of the previous month to day <?= (int)$S['start_day'] - 1 ?>. <?= (int)$S['months'] ?> complete cycles from <?= hs_label($S['first_cycle']) ?> drive every average; the open cycle is shown separately (pace) and never averaged.</li>
    <li><b>Sources</b>: bills before <?= pl_h($S['cutover']) ?> from Analysis ▸ Import Secondary Invoices; from that date our own secondary invoices. Customers (pay mode, policy days, cheques-will-delay, blacklist, credit limit), bills &amp; payments (violations, open overdue), cheques (returned / sent back, settled) and cancelled bills come from the system.</li>
    <li>Months before an outlet's first purchase are blank (not observed) and excluded from averages; zero months after it count as zero.</li>
    <li><b>Channel median</b> = median baseline of Party Master outlets with sales in the same secondary channel; outlets not in Party Master ("Unclassified") have no peer median.</li>
    <li><b>Credit capacity</b> is a model ceiling from the outlet's own history; Credit Limit Management compares it with the limit stored on the customer.</li>
    <li><b>Approval</b>: any change to credit limits, payment terms, block status or reinstatement needs management approval — the model recommends, it does not authorise.</li></ul></div>
</div>
<?= pl_js() ?>
<script>
(function(){ var $=function(i){return document.getElementById(i);};
  function mark(){ document.querySelectorAll('input.av').forEach(function(el){ el.classList.toggle('ch', String(+el.value)!==String(+el.dataset.def)); });
    var fs=[].reduce.call(document.querySelectorAll('input.fw'),function(a,el){return a+(+el.value||0);},0); $('fwSum').innerHTML='<span class="'+(Math.abs(fs-100)<0.01?'ok':'bad')+'">'+fs.toFixed(1)+'%</span>';
    var rs=['w_viol','w_chq','w_bl','w_late','w_sb','w_canc','w_irr'].reduce(function(a,k){ var el=document.querySelector('input[data-k="'+k+'"]'); return a+(+el.value||0); },0); $('rwSum').innerHTML='<span class="'+(Math.abs(rs-100)<0.01?'ok':'bad')+'">'+rs.toFixed(1)+'%</span>'; }
  document.addEventListener('input',function(e){ if(e.target.classList.contains('av')) mark(); }); mark();
  var msg=function(t,ok){ $('mMsg').style.color=ok?'#15803d':'#b91c1c'; $('mMsg').textContent=t; };
  if($('mSave')) $('mSave').onclick=function(){ var v={}; document.querySelectorAll('input.av[data-k]').forEach(function(el){ v[el.dataset.k]=el.value; }); v.fw=[].map.call(document.querySelectorAll('input.fw'),function(el){return el.value;});
    msg('Saving…',true); plPost('plan_model_assumptions.php',{action:'save',vals:JSON.stringify(v)}).then(function(j){ msg(j.msg,j.ok); if(j.ok) setTimeout(function(){ location.href='?recalc=1'; },900); }).catch(function(e){ msg(e.message,false); }); };
  if($('mReset')) $('mReset').onclick=function(){ if(!confirm('Put every assumption back to the workbook default?')) return; plPost('plan_model_assumptions.php',{action:'reset'}).then(function(j){ msg(j.msg,j.ok); if(j.ok) setTimeout(function(){ location.href='?recalc=1'; },900); }); };
  if($('skuU')) $('skuU').onclick=function(){ var f=$('skuF').files[0]; if(!f) return; var fd=new FormData(); fd.append('action','sku_upload'); fd.append('file',f); $('skuM').textContent='Uploading…';
    fetch('plan_model_assumptions.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){ $('skuM').style.color=j.ok?'#15803d':'#b91c1c'; $('skuM').textContent=j.msg; }).catch(function(){ $('skuM').textContent='Upload failed.'; }); };
  if($('skuC')) $('skuC').onclick=function(){ if(!confirm('Clear all SKU counts?')) return; plPost('plan_model_assumptions.php',{action:'sku_clear'}).then(function(j){ $('skuM').textContent=j.msg; }); };
})();
</script>
<?php include 'footer.php'; ?>
