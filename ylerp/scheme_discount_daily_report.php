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

/* scheme code key: same code whether it is typed 41388823, ' 41388823 ' or 41388823.0 (case ignored) — defined first: the pop-up requests below use it */
function sd_norm($c) { return strtoupper(preg_replace('/\.0+$/', '', trim((string)$c))); }

/* bill-wise rows of one scheme code (Bill Wise Scheme Analysis import = bws_items) for the pop-up */
if (($_GET['ajax'] ?? '') === 'bws_rows') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $code = trim($_GET['code'] ?? ''); $out = ['success' => true, 'rows' => [], 'total' => 0, 'count' => 0, 'free_value' => 0];
    $chk = mysqli_query($conn, "SHOW TABLES LIKE 'bws_items'");
    if ($code === '' || !$chk || !mysqli_num_rows($chk)) { echo json_encode(['success' => false, 'message' => 'No Bill Wise Scheme data.']); exit; }
    $c = mysqli_real_escape_string($conn, sd_norm($code));
    $q = mysqli_query($conn, "SELECT bill_date, bill_no, COALESCE(NULLIF(hul_code,''), party_code) AS outlet, party_name, beat_name, salesman_code, product_name, sold_qty, free_qty, free_value, sch_disc, gross_sales
                              FROM bws_items WHERE UPPER(TRIM(TRAILING '.0' FROM TRIM(scheme_no))) = '$c' ORDER BY bill_date, bill_no LIMIT 5000");
    while ($q && ($x = mysqli_fetch_assoc($q))) { $out['rows'][] = $x; $out['total'] += (float)$x['sch_disc']; $out['free_value'] += (float)$x['free_value']; }
    $out['count'] = count($out['rows']); $out['total'] = round($out['total'], 2); $out['free_value'] = round($out['free_value'], 2);
    echo json_encode($out); exit;
}
/**
 * Claim certificate rows (claim_cert_items) tallied to scheme codes.
 * 1) the part of the description before the first "-" is the scheme no (e.g. "41398595-B27181224-Buy Rexona 80g ...");
 * 2) if that does not tally with a scheme code, any other word / number anywhere in the description line
 *    (e.g. "B27181224-41398595 BuyRexona80g&GetDis") that equals a scheme code is used.
 * $codes = normalised scheme codes wanted. Returns code => ['n'=>count,'act'=>sum,'rows'=>[...]] (rows only when $withRows).
 */
function sd_cc_tally($conn, array $codes, $withRows = false) {
    $out = [];
    $chk = mysqli_query($conn, "SHOW TABLES LIKE 'claim_cert_items'");
    if (!$codes || !$chk || !mysqli_num_rows($chk)) return $out;
    $set = array_flip($codes);
    $q = mysqli_query($conn, "SELECT tax_invoice_no, invoice_date, banking_date, customer_code, customer_name, claim_type, entity, claim_description, actual_amount, vat_amount, total_amount
                              FROM claim_cert_items WHERE claim_description IS NOT NULL AND claim_description <> '' ORDER BY invoice_date, tax_invoice_no");
    while ($q && ($x = mysqli_fetch_assoc($q))) {
        $d = (string)$x['claim_description'];
        $hit = null;
        $first = sd_norm(explode('-', $d, 2)[0]);
        if ($first !== '' && isset($set[$first])) $hit = $first;
        else foreach (preg_split('/[^A-Za-z0-9]+/', $d, -1, PREG_SPLIT_NO_EMPTY) as $t) {
            $t = sd_norm($t);
            if (isset($set[$t])) { $hit = $t; break; }
        }
        if ($hit === null) continue;
        if (!isset($out[$hit])) $out[$hit] = ['n' => 0, 'act' => 0.0, 'vat' => 0.0, 'rows' => []];
        $out[$hit]['n']++; $out[$hit]['act'] += (float)$x['actual_amount']; $out[$hit]['vat'] += (float)$x['vat_amount'];
        if ($withRows && count($out[$hit]['rows']) < 5000) $out[$hit]['rows'][] = $x;
    }
    return $out;
}
/* claim certificate rows (customer_claim.php import = claim_cert_items) whose description carries this scheme no (first part, else anywhere in the line), e.g. "41440127-B418050926-Ponds FW 100g ETE 20" */
if (($_GET['ajax'] ?? '') === 'claim_rows') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $code = trim($_GET['code'] ?? ''); $out = ['success' => true, 'rows' => [], 'actual' => 0, 'vat' => 0, 'count' => 0];
    $chk = mysqli_query($conn, "SHOW TABLES LIKE 'claim_cert_items'");
    if ($code === '' || !$chk || !mysqli_num_rows($chk)) { echo json_encode(['success' => false, 'message' => 'No Customer Claim Certificate data.']); exit; }
    $t = sd_cc_tally($conn, [sd_norm($code)], true)[sd_norm($code)] ?? null;
    if ($t) { $out['rows'] = $t['rows']; $out['actual'] = $t['act']; $out['vat'] = $t['vat']; }
    $out['count'] = count($out['rows']); $out['actual'] = round($out['actual'], 2); $out['vat'] = round($out['vat'], 2);
    echo json_encode($out); exit;
}
/** rounding crumbs (under Rs. 5 either way, e.g. 0.01 or (1.70)) count as 0 — change SD_ZERO_LIMIT to move the limit */
if (!defined('SD_ZERO_LIMIT')) define('SD_ZERO_LIMIT', 5);
function sd_zero($v) { return abs((float)$v) < SD_ZERO_LIMIT ? 0.0 : (float)$v; }
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
    $w = ['1=1', "UPPER(TRIM(i.activity_type)) <> 'DAMAGE'"];       // DAMAGE rows are not part of this report
    /* "schemes running in the date range": start <= to AND end >= from (a single day = from and to the same) */
    if ($f_to)   $w[] = "(i.start_date IS NULL OR i.start_date <= '{$e($f_to)}')";
    if ($f_from) $w[] = "(i.end_date IS NULL OR i.end_date >= '{$e($f_from)}')";
    if ($f_moc !== '')  $w[] = "i.moc_ref = '{$e($f_moc)}'";
    if ($f_type !== '') $w[] = "i.activity_type = '{$e($f_type)}'";
    if ($f_cmoc !== '') $w[] = "i.claim_moc_ref = '{$e($f_cmoc)}'";
    if ($f_claim === 'with')    $w[] = "COALESCE(i.claim_amount,0) > 0";
    if ($f_claim === 'without') $w[] = "COALESCE(i.claim_amount,0) = 0";
    if ($f_q !== '') { $q = $e($f_q); $w[] = "(i.activity_code LIKE '%$q%' OR i.activity_desc LIKE '%$q%')"; }
    $r = mysqli_query($conn, "SELECT i.* FROM scheme_status_items i WHERE " . implode(' AND ', $w) . " ORDER BY i.start_date DESC, i.moc_ref DESC, i.activity_type, i.activity_code");
    while ($r && ($x = mysqli_fetch_assoc($r))) $items[] = $x;
    $dist = function ($col, $ord) use ($conn) { $o = []; $r = mysqli_query($conn, "SELECT DISTINCT $col AS v FROM scheme_status_items WHERE $col IS NOT NULL AND $col <> '' AND UPPER(TRIM(activity_type)) <> 'DAMAGE' ORDER BY $col $ord"); while ($r && ($x = mysqli_fetch_assoc($r))) $o[] = $x['v']; return $o; };
    $mocs = $dist('moc_ref', 'DESC'); $types = $dist('activity_type', 'ASC'); $cmocs = $dist('claim_moc_ref', 'DESC');
}
/* total scheme discount per scheme code from the Bill Wise Scheme Analysis rows (the same code has many bill rows) */
$bws = [];
$cb = mysqli_query($conn, "SHOW TABLES LIKE 'bws_items'");
if ($items && $cb && mysqli_num_rows($cb)) {
    $codes = array_values(array_unique(array_map(fn($x) => "'" . mysqli_real_escape_string($conn, sd_norm($x['activity_code'])) . "'", $items)));
    $q = mysqli_query($conn, "SELECT UPPER(TRIM(TRAILING '.0' FROM TRIM(scheme_no))) AS sn, COUNT(*) AS n, COALESCE(SUM(sch_disc),0) AS sd, COALESCE(SUM(free_value),0) AS fv FROM bws_items WHERE UPPER(TRIM(TRAILING '.0' FROM TRIM(scheme_no))) IN (" . implode(',', $codes) . ") GROUP BY UPPER(TRIM(TRAILING '.0' FROM TRIM(scheme_no)))");
    while ($q && ($x = mysqli_fetch_assoc($q))) $bws[$x['sn']] = ['n' => (int)$x['n'], 'sd' => round((float)$x['sd'] + (float)$x['fv'], 2), 'disc' => (float)$x['sd'], 'fv' => (float)$x['fv']];     // scheme discount + free value (where the bill wise data has one)
}
/* claim certificates (Customer Claim Certificate Import): scheme no = part before the first "-", else any scheme no found in the description (sd_cc_tally) — feeds Claim Certificate Actual, Balance, totals, monthly summary and the Excel export */
$cc = [];
$cq = mysqli_query($conn, "SHOW TABLES LIKE 'claim_cert_items'");
if ($items && $cq && mysqli_num_rows($cq)) {
    foreach (sd_cc_tally($conn, array_values(array_unique(array_map(fn($x) => sd_norm($x['activity_code']), $items)))) as $k => $t) $cc[$k] = ['n' => $t['n'], 'act' => $t['act']];
}
foreach ($items as &$__x) {
    $k = sd_norm($__x['activity_code']); $b = $bws[$k] ?? null; $c2 = $cc[$k] ?? null;
    $__x['bws_n'] = $b ? $b['n'] : 0; $__x['bws_sd'] = $b ? $b['sd'] : null;
    $__x['cc_n'] = $c2 ? $c2['n'] : 0; $__x['cc_act'] = $c2 ? $c2['act'] : null;
    $has_claim = $__x['claim_amount'] !== null;
    $__x['diff'] = ($has_claim || $b) ? sd_zero(round(($has_claim ? (float)$__x['claim_amount'] : 0) - ($b ? $b['sd'] : 0), 2)) : null;     // Claim Amount − scheme discount as per invoice
    $__x['balance'] = ($has_claim || $c2) ? sd_zero(max(0, round(($has_claim ? (float)$__x['claim_amount'] : 0) - ($c2 ? $c2['act'] : 0), 2))) : null;     // negative balance → 0; rounding crumbs (under Rs. 5, e.g. 0.01 or 1.70) → 0     // Claim Amount − claim certificate actual amount
} unset($__x);
$tot = array_sum(array_map(fn($x) => (float)$x['claim_amount'], $items));
$tot_bws = array_sum(array_map(fn($x) => (float)$x['bws_sd'], $items));
$tot_cc  = array_sum(array_map(fn($x) => (float)$x['cc_act'], $items));
$tot_bal = array_sum(array_map(fn($x) => (float)$x['balance'], $items));
$tot_diff = array_sum(array_map(fn($x) => (float)$x['diff'], $items));
/* monthly summary: the same figures, grouped by MOC Ref (newest month first) */
function sd_moc_key($m) { return preg_match('~^(\d{1,2})/(\d{4})$~', trim((string)$m), $mm) ? (int)($mm[2] . str_pad($mm[1], 2, '0', STR_PAD_LEFT)) : 0; }
$monthly = [];
foreach ($items as $x) {
    $m = trim((string)$x['moc_ref']) !== '' ? trim((string)$x['moc_ref']) : '—';
    if (!isset($monthly[$m])) $monthly[$m] = ['n' => 0, 'claim' => 0.0, 'bws' => 0.0, 'diff' => 0.0, 'cc' => 0.0, 'bal' => 0.0];
    $monthly[$m]['n']++; $monthly[$m]['claim'] += (float)$x['claim_amount']; $monthly[$m]['bws'] += (float)$x['bws_sd'];
    $monthly[$m]['diff'] += (float)$x['diff']; $monthly[$m]['cc'] += (float)$x['cc_act']; $monthly[$m]['bal'] += (float)$x['balance'];
}
uksort($monthly, fn($a, $b) => sd_moc_key($b) <=> sd_moc_key($a) ?: strcmp($b, $a));

/* Excel */
if (isset($_GET['export']) && class_exists('ZipArchive')) {
    require_once __DIR__ . '/rbac_xlsx.php';
    while (ob_get_level()) ob_end_clean();
    $X = new MiniXlsx(); $sh = $X->sheet('Scheme Discount Daily');
    $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '1E3A8A', 'h' => 'center', 'border' => 1, 'wrap' => 1];
    $sh->set(1, 1, 'Scheme Discount Daily Report' . ($f_from || $f_to ? ' — ' . ($f_from ?: '…') . ' to ' . ($f_to ?: '…') : ''), ['b' => 1, 'sz' => 15]);
    foreach (['#', 'Activity Type', 'Scheme Activity Code', 'Scheme Activity Description', 'MOC Ref', 'Start Date', 'End Date', 'Claim MOC Ref', 'Claim Amount', 'Scheme Discount as per Invoice', 'Difference (Claim - Invoice)', 'Claim Certificate Actual Amount', 'Balance'] as $i => $h) $sh->set(3, $i + 1, $h, $H);
    $r = 4; foreach ($items as $n => $x) {
        foreach ([$n + 1, $x['activity_type'], $x['activity_code'], $x['activity_desc'], $x['moc_ref'], sd_d($x['start_date']) === '—' ? '' : sd_d($x['start_date']), sd_d($x['end_date']) === '—' ? '' : sd_d($x['end_date']), $x['claim_moc_ref'], $x['claim_amount'] === null ? '' : (float)$x['claim_amount'], $x['bws_sd'] === null ? '' : round((float)$x['bws_sd'], 2), $x['diff'] === null ? '' : $x['diff'], $x['cc_act'] === null ? '' : round((float)$x['cc_act'], 2), $x['balance'] === null ? '' : $x['balance']] as $i => $v)
            $sh->set($r, $i + 1, $v, ['border' => 1] + (in_array($i, [8, 9, 10, 11, 12], true) ? ['h' => 'right', 'nf' => 4] : []));
        $r++;
    }
    $sh->set($r, 8, 'TOTAL', ['b' => 1, 'border' => 1, 'bg' => 'FDE68A']); $sh->set($r, 9, round($tot, 2), ['b' => 1, 'border' => 1, 'bg' => 'FDE68A', 'h' => 'right', 'nf' => 4]); $sh->set($r, 10, round($tot_bws, 2), ['b' => 1, 'border' => 1, 'bg' => 'FDE68A', 'h' => 'right', 'nf' => 4]); $sh->set($r, 11, round($tot_diff, 2), ['b' => 1, 'border' => 1, 'bg' => 'FDE68A', 'h' => 'right', 'nf' => 4]); $sh->set($r, 12, round($tot_cc, 2), ['b' => 1, 'border' => 1, 'bg' => 'FDE68A', 'h' => 'right', 'nf' => 4]); $sh->set($r, 13, round($tot_bal, 2), ['b' => 1, 'border' => 1, 'bg' => 'FDE68A', 'h' => 'right', 'nf' => 4]);
    foreach ([5, 14, 18, 40, 10, 13, 13, 13, 16, 20, 18, 20, 16] as $i => $wd) $sh->widths[$i + 1] = $wd;
    $sh->freeze = [3, 0];
    $ms = $X->sheet('Monthly summary');
    $ms->set(1, 1, 'Scheme Discount — monthly summary by MOC Ref', ['b' => 1, 'sz' => 15]);
    foreach (['MOC Ref', 'Schemes', 'Claim Amount', 'Scheme Discount as per Invoice', 'Difference (Claim - Invoice)', 'Claim Certificate Actual Amount', 'Balance'] as $i => $h) $ms->set(3, $i + 1, $h, $H);
    $mr = 4; foreach ($monthly as $mk => $m) { foreach ([$mk, $m['n'], round($m['claim'], 2), round($m['bws'], 2), round($m['diff'], 2), round($m['cc'], 2), round($m['bal'], 2)] as $i => $v) $ms->set($mr, $i + 1, $v, ['border' => 1] + ($i >= 2 ? ['h' => 'right', 'nf' => 4] : [])); $mr++; }
    foreach ([['TOTAL', 'b'], [array_sum(array_column($monthly, 'n')), 'b'], [round($tot, 2), 'n'], [round($tot_bws, 2), 'n'], [round($tot_diff, 2), 'n'], [round($tot_cc, 2), 'n'], [round($tot_bal, 2), 'n']] as $i => [$v, $t]) $ms->set($mr, $i + 1, $v, ['b' => 1, 'border' => 1, 'bg' => 'FDE68A'] + ($i >= 2 ? ['h' => 'right', 'nf' => 4] : []));
    foreach ([14, 10, 16, 22, 22, 24, 16] as $i => $wd) $ms->widths[$i + 1] = $wd;
    if (($_GET['sheet'] ?? '') === 'summary') array_shift($X->sheets);      // only the Monthly summary sheet
    $tmp = tempnam(sys_get_temp_dir(), 'sd'); $X->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="scheme_discount_daily_' . date('Ymd_His') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
ob_end_flush();
include 'header.php';
$active = array_filter([$f_from || $f_to ? 'Date: ' . ($f_from ? sd_d($f_from) : '…') . ' → ' . ($f_to ? sd_d($f_to) : '…') : '', $f_moc !== '' ? 'MOC ' . $f_moc : '', $f_type !== '' ? $f_type : '', $f_cmoc !== '' ? 'Claim MOC ' . $f_cmoc : '', $f_claim === 'with' ? 'With claim' : ($f_claim === 'without' ? 'Without claim' : ''), $f_q !== '' ? '“' . $f_q . '”' : '']);
?>
<style>
.sdr{max-width:none;width:100%;margin:0;padding:10px 12px;font-family:'Inter',system-ui,sans-serif;color:#111827}.sdr h2{margin:0 0 3px;font-size:22px}.sdr .sub{color:#6b7280;font-size:13px;margin:0 0 14px}
.sdr .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;margin-bottom:14px}.sdr .row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.sdr .fld{display:flex;flex-direction:column;gap:3px}.sdr .fld label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase}
.sdr input,.sdr select{padding:8px 10px;border:1.5px solid #d1d5db;border-radius:8px;font:inherit;font-size:13px}.sdr .btn{padding:8px 16px;border:none;border-radius:8px;background:#1e3a8a;color:#fff;font:600 13px inherit;cursor:pointer;text-decoration:none;display:inline-flex;gap:6px;align-items:center}.sdr .btn.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe}
.sdr .chip{display:inline-block;margin:6px 6px 0 0;font-size:11.5px;font-weight:600;color:#1e3a8a;background:#eff6ff;border:1px solid #bfdbfe;border-radius:99px;padding:2px 10px}
.sdr .kp{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-bottom:12px}.sdr .k{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px}.sdr .k span{font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:700}.sdr .k b{display:block;font-size:19px;margin-top:2px;font-variant-numeric:tabular-nums}
.sdr .tw{overflow:auto;border:1px solid #e5e7eb;border-radius:10px;background:#fff;max-height:72vh}.sdr table{width:100%;border-collapse:collapse;font-size:13px}.sdr th{background:#1e3a8a;color:#fff;padding:9px 10px;text-align:left;position:sticky;top:0;white-space:nowrap}.sdr td{padding:8px 10px;border-bottom:1px solid #f1f5f9}.sdr .r{text-align:right;font-variant-numeric:tabular-nums}.sdr tr:hover td{background:#f8fafc}
.sdr tfoot td{background:#fde68a;font-weight:800;position:sticky;bottom:0}.sdr .ty{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;background:#ede9fe;color:#6d28d9}.sdr .ty.dmg{background:#fee2e2;color:#b91c1c}
.sdr .bal{display:inline-block;min-width:92px;text-align:right;padding:3px 10px;border-radius:8px;font-weight:800}.sdr .bal.pos{background:#fee2e2;color:#b91c1c}.sdr .bal.neg{background:#dcfce7;color:#15803d}.sdr .bal.zero{background:#f1f5f9;color:#64748b}.sdr a.bwsl,.sdr a.ccl{color:#1d4ed8;text-decoration:none}.sdr a.ccl{color:#15803d}.sdr a:hover b{text-decoration:underline}.sdr small{color:#6b7280;font-weight:500}
.sdr .sdtabs{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:10px}.sdr .sdtabs button{padding:8px 16px;border:1.5px solid #c7d2fe;background:#fff;color:#1e3a8a;border-radius:9px;font:600 13px inherit;cursor:pointer}.sdr .sdtabs button.on{background:#1e3a8a;color:#fff;border-color:#1e3a8a}.sdr tr.mocr{cursor:pointer}.sdr tr.mocr:hover td{background:#eef2ff}
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
      <a class="btn o" data-no-loader href="?<?= sd_h(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a></div></div>
  </form>

  <div class="kp"><div class="k"><span>Schemes</span><b><?= number_format(count($items)) ?></b></div><div class="k"><span>Total claim amount</span><b style="color:#7c3aed">Rs. <?= number_format($tot, 2) ?></b></div>
    <div class="k"><span>Scheme discount as per invoice</span><b style="color:#1d4ed8">Rs. <?= number_format($tot_bws, 2) ?></b></div><div class="k"><span>Claim certificate (actual)</span><b style="color:#15803d">Rs. <?= number_format($tot_cc, 2) ?></b></div><div class="k"><span>Balance</span><b style="color:<?= $tot_bal > 0.5 ? '#b91c1c' : ($tot_bal < -0.5 ? '#15803d' : '#6b7280') ?>">Rs. <?= $tot_bal < 0 ? '(' . number_format(abs($tot_bal), 2) . ')' : number_format($tot_bal, 2) ?></b></div><div class="k"><span>With claim</span><b><?= number_format(count(array_filter($items, fn($x) => (float)$x['claim_amount'] > 0))) ?></b></div></div>

  <div class="sdtabs"><button type="button" data-v="det" class="on"><i class="fa-solid fa-table-list"></i> Detailed</button><button type="button" data-v="sum"><i class="fa-solid fa-calendar-days"></i> Monthly summary (by MOC Ref)</button>
    <a class="btn o" id="sdSumXls" data-no-loader href="?<?= sd_h(http_build_query(array_merge($_GET, ['export' => 1, 'sheet' => 'summary']))) ?>" style="display:none;padding:7px 14px;border:1.5px solid #c7d2fe;border-radius:9px;color:#1e3a8a;text-decoration:none;font:600 13px inherit;background:#fff"><i class="fa-solid fa-file-excel"></i> Export monthly summary</a>
    <span id="sdMocBar" style="display:none;margin-left:auto;font-size:12.5px"><span class="chip" style="margin:0">MOC <b id="sdMocTxt"></b></span> <a href="#" id="sdMocClr" style="color:#1e3a8a;font-weight:700">show all MOC refs ✕</a></span></div>

  <div id="sdSum" style="display:none"><div class="tw"><table><thead><tr><th>MOC Ref</th><th class="r">Schemes</th><th class="r">Claim Amount</th><th class="r">Scheme Discount<br>as per Invoice</th><th class="r">Difference<br><span style="font-weight:500;font-size:10px">Claim Amount − invoice</span></th><th class="r">Claim Certificate<br>Actual Amount</th><th class="r">Balance</th></tr></thead><tbody>
  <?php foreach ($monthly as $mk => $m): $dc = $m['diff'] > 0.5 ? 'pos' : ($m['diff'] < -0.5 ? 'neg' : 'zero'); $bc = $m['bal'] > 0.5 ? 'pos' : ($m['bal'] < -0.5 ? 'neg' : 'zero'); ?>
    <tr class="mocr" data-moc="<?= sd_h($mk === '—' ? '' : $mk) ?>" title="Click to see the detailed rows of this MOC Ref"><td><b><?= sd_h($mk) ?></b> <i class="fa-solid fa-chevron-right" style="font-size:10px;color:#9ca3af"></i></td><td class="r"><?= number_format($m['n']) ?></td><td class="r"><b><?= number_format($m['claim'], 2) ?></b></td><td class="r"><?= number_format($m['bws'], 2) ?></td>
      <td class="r"><span class="bal <?= $dc ?>"><?= $m['diff'] < 0 ? '(' . number_format(abs($m['diff']), 2) . ')' : number_format($m['diff'], 2) ?></span></td><td class="r"><?= number_format($m['cc'], 2) ?></td><td class="r"><span class="bal <?= $bc ?>"><?= $m['bal'] < 0 ? '(' . number_format(abs($m['bal']), 2) . ')' : number_format($m['bal'], 2) ?></span></td></tr>
  <?php endforeach; if (!$monthly): ?><tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:28px">No schemes for these filters.</td></tr><?php endif; ?></tbody>
  <?php if ($monthly): ?><tfoot><tr><td>TOTAL</td><td class="r"><?= number_format(array_sum(array_column($monthly, 'n'))) ?></td><td class="r"><?= number_format($tot, 2) ?></td><td class="r"><?= number_format($tot_bws, 2) ?></td><td class="r"><?= $tot_diff < 0 ? '(' . number_format(abs($tot_diff), 2) . ')' : number_format($tot_diff, 2) ?></td><td class="r"><?= number_format($tot_cc, 2) ?></td><td class="r"><?= $tot_bal < 0 ? '(' . number_format(abs($tot_bal), 2) . ')' : number_format($tot_bal, 2) ?></td></tr></tfoot><?php endif; ?></table></div></div>

  <div id="sdDetail"><div class="tw"><table><thead><tr><th>#</th><th>Type</th><th>Scheme Activity Code</th><th>Scheme Activity Description</th><th>MOC Ref</th><th>Start Date</th><th>End Date</th><th class="r">Claim Amount</th><th class="r" title="Scheme discount + free value of every Bill Wise Scheme Analysis row with this scheme code — click to see the rows">Scheme Discount<br>as per Invoice<br><span style="font-weight:500;font-size:10px">click to open invoice rows</span></th><th class="r" title="Claim Amount minus Scheme Discount as per Invoice">Difference<br><span style="font-weight:500;font-size:10px">Claim Amount − invoice</span></th><th class="r" title="Actual amount (without VAT) of the Customer Claim Certificate rows whose claim description starts with this scheme no — click to see them">Claim Certificate<br>Actual Amount<br><span style="font-weight:500;font-size:10px">click to open claim rows</span></th><th class="r">Balance<br><span style="font-weight:500;font-size:10px">Claim Amount − actual amount</span></th></tr></thead><tbody>
  <?php foreach ($items as $n => $x): ?><tr data-moc="<?= sd_h(trim((string)$x['moc_ref'])) ?>" data-claim="<?= (float)$x['claim_amount'] ?>" data-bws="<?= (float)$x['bws_sd'] ?>" data-diff="<?= (float)$x['diff'] ?>" data-cc="<?= (float)$x['cc_act'] ?>" data-bal="<?= (float)$x['balance'] ?>"><td class="sdn"><?= $n + 1 ?></td><td><span class="ty <?= $x['activity_type'] === 'DAMAGE' ? 'dmg' : '' ?>"><?= sd_h($x['activity_type']) ?></span></td><td><b><?= sd_h($x['activity_code']) ?></b></td><td><?= sd_h($x['activity_desc']) ?></td><td><?= sd_h($x['moc_ref']) ?></td><td><?= sd_d($x['start_date']) ?></td><td><?= sd_d($x['end_date']) ?></td><td class="r"><b><?= $x['claim_amount'] === null ? '—' : number_format((float)$x['claim_amount'], 2) ?></b></td>
    <td class="r"><?php if ($x['bws_sd'] !== null): ?><a href="#" class="bwsl" data-code="<?= sd_h(trim($x['activity_code'])) ?>" data-desc="<?= sd_h($x['activity_desc']) ?>" title="Show the <?= (int)$x['bws_n'] ?> bill wise rows"><b><?= number_format($x['bws_sd'], 2) ?></b></a><?php else: ?><span style="color:#cbd5e1" title="No Bill Wise Scheme Analysis rows for this code">—</span><?php endif; ?></td>
    <td class="r"><?php if ($x['diff'] !== null): $dc = $x['diff'] > 0.5 ? 'pos' : ($x['diff'] < -0.5 ? 'neg' : 'zero'); ?><span class="bal <?= $dc ?>"><?= $x['diff'] < 0 ? '(' . number_format(abs($x['diff']), 2) . ')' : number_format($x['diff'], 2) ?></span><?php else: ?><span style="color:#cbd5e1">—</span><?php endif; ?></td>
    <td class="r"><?php if ($x['cc_act'] !== null): ?><a href="#" class="ccl" data-code="<?= sd_h(trim($x['activity_code'])) ?>" data-desc="<?= sd_h($x['activity_desc']) ?>" title="Show the <?= (int)$x['cc_n'] ?> claim certificate rows"><b><?= number_format($x['cc_act'], 2) ?></b></a><?php else: ?><span style="color:#cbd5e1" title="No claim certificate rows with this scheme no in the description">—</span><?php endif; ?></td>
    <td class="r"><?php if ($x['balance'] !== null): $bc = $x['balance'] > 0.5 ? 'pos' : ($x['balance'] < -0.5 ? 'neg' : 'zero'); ?><span class="bal <?= $bc ?>"><?= $x['balance'] < 0 ? '(' . number_format(abs($x['balance']), 2) . ')' : number_format($x['balance'], 2) ?></span><?php else: ?><span style="color:#cbd5e1">—</span><?php endif; ?></td></tr>
  <?php endforeach; if (!$items): ?><tr><td colspan="12" style="text-align:center;color:#9ca3af;padding:28px">No schemes for these filters.</td></tr><?php endif; ?></tbody>
  <?php if ($items): ?><tfoot><tr><td colspan="7" id="sdFN">TOTAL (<?= number_format(count($items)) ?> schemes)</td><td class="r" id="sdFC"><?= number_format($tot, 2) ?></td><td class="r" id="sdFB"><?= number_format($tot_bws, 2) ?></td><td class="r" id="sdFD"><?= $tot_diff < 0 ? '(' . number_format(abs($tot_diff), 2) . ')' : number_format($tot_diff, 2) ?></td><td class="r" id="sdFA"><?= number_format($tot_cc, 2) ?></td><td class="r" id="sdFL"><?= $tot_bal < 0 ? '(' . number_format(abs($tot_bal), 2) . ')' : number_format($tot_bal, 2) ?></td></tr></tfoot><?php endif; ?></table></div></div>
</div>
<div id="bwsM" style="position:fixed;inset:0;background:rgba(15,23,42,.6);display:none;align-items:center;justify-content:center;z-index:2147483000;padding:14px;font-family:'Inter',system-ui,sans-serif">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:1400px;height:92vh;display:flex;flex-direction:column;overflow:hidden">
    <div style="padding:14px 18px;background:linear-gradient(120deg,#0f172a,#1e3a8a);color:#fff;display:flex;gap:12px;align-items:center"><div><div style="font-weight:800;font-size:16px" id="bwsT">Bill wise rows</div><div style="font-size:12px;opacity:.8" id="bwsS"></div></div>
      <button type="button" id="bwsX" style="margin-left:auto;width:34px;height:34px;border-radius:50%;border:none;background:#dc2626;color:#fff;font-size:16px;cursor:pointer">✕</button></div>
    <div id="bwsK" style="padding:10px 18px;display:flex;gap:18px;flex-wrap:wrap;font-size:13px"></div>
    <div style="flex:1;min-height:0;overflow:auto;padding:0 18px 14px"><table style="width:100%;border-collapse:collapse;font-size:12.5px"><thead><tr id="bwsH"></tr></thead><tbody id="bwsB"></tbody></table></div>
  </div></div>
<script>
(function(){
  var m=document.getElementById('bwsM'); document.body.appendChild(m);
  var esc=function(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }, money=function(v){ return Number(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); };
  var TH='background:#1e3a8a;color:#fff;padding:8px 9px;text-align:left;position:sticky;top:0;white-space:nowrap', TD='padding:7px 9px;border-bottom:1px solid #f1f5f9;white-space:nowrap';
  function close(){ m.style.display='none'; document.body.style.overflow=''; }
  document.getElementById('bwsX').addEventListener('click',close); m.addEventListener('click',function(e){ if(e.target===m) close(); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'&&m.style.display==='flex') close(); });
  var $=function(i){ return document.getElementById(i); };
  function table(heads,rows,numFrom,cells){ $('bwsH').innerHTML=heads.map(function(h,i){ return '<th style="'+TH+(i>=numFrom?';text-align:right':'')+'">'+h+'</th>'; }).join('');
    $('bwsB').innerHTML=rows.map(function(r,i){ return '<tr><td style="'+TD+'">'+(i+1)+'</td>'+cells(r)+'</tr>'; }).join('')||'<tr><td colspan="'+heads.length+'" style="padding:24px;text-align:center;color:#9ca3af">No rows</td></tr>'; }
  var tdt=function(v){ return '<td style="'+TD+'">'+esc(v)+'</td>'; }, tdn=function(v,b){ return '<td style="'+TD+';text-align:right;font-variant-numeric:tabular-nums'+(b?';font-weight:700':'')+'">'+money(v)+'</td>'; };
  document.addEventListener('click',function(e){ var a=e.target.closest('.bwsl,.ccl'); if(!a) return; e.preventDefault(); var claim=a.classList.contains('ccl');
    $('bwsT').textContent='Scheme '+a.dataset.code+(claim?' — claim certificate rows (Customer Claim Certificate Import)':' — scheme discount as per invoice (bill wise rows)'); $('bwsS').textContent=a.dataset.desc||''; $('bwsK').innerHTML='Loading…'; $('bwsB').innerHTML=''; $('bwsH').innerHTML='';
    m.style.display='flex'; document.body.style.overflow='hidden';
    fetch('scheme_discount_daily_report.php?ajax='+(claim?'claim_rows':'bws_rows')+'&code='+encodeURIComponent(a.dataset.code),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      if(!d.success){ $('bwsK').innerHTML='<span style="color:#b91c1c">'+esc(d.message||'Could not load')+'</span>'; return; }
      var cap=d.count>=5000?'<span style="color:#b45309">first 5,000 rows shown</span>':'';
      if(claim){
        $('bwsK').innerHTML='<span><b>'+d.count+'</b> rows</span><span>Actual amount <b style="color:#15803d">Rs. '+money(d.actual)+'</b></span><span>VAT <b>Rs. '+money(d.vat)+'</b></span>'+cap;
        table(['#','Tax invoice no','Invoice date','Banking date','Customer','Claim type','Entity','Claim description','Actual amount','VAT','Total'],d.rows,8,function(r){ return tdt(r.tax_invoice_no)+tdt(r.invoice_date)+tdt(r.banking_date)+tdt((r.customer_code||'')+' '+(r.customer_name||''))+tdt(r.claim_type)+tdt(r.entity)+tdt(r.claim_description)+tdn(r.actual_amount,true)+tdn(r.vat_amount)+tdn(r.total_amount); });
      } else {
        $('bwsK').innerHTML='<span><b>'+d.count+'</b> rows</span><span>Scheme discount <b>Rs. '+money(d.total)+'</b></span><span>+ Free value <b>Rs. '+money(d.free_value)+'</b></span><span>= Scheme discount as per invoice <b style="color:#1d4ed8">Rs. '+money(d.total+d.free_value)+'</b></span>'+cap;
        table(['#','Bill date','Bill no','Outlet code','Outlet name','Beat','Salesman','Product','Sold qty','Free qty','Free value','Scheme discount','Gross sales'],d.rows,8,function(r){ return tdt(r.bill_date)+tdt(r.bill_no)+tdt(r.outlet)+tdt(r.party_name)+tdt(r.beat_name)+tdt(r.salesman_code)+tdt(r.product_name)+tdn(r.sold_qty)+tdn(r.free_qty)+tdn(r.free_value)+tdn(r.sch_disc,true)+tdn(r.gross_sales); });
      }
    }).catch(function(){ $('bwsK').innerHTML='<span style="color:#b91c1c">Connection problem.</span>'; }); });
})();
</script>
<script>
(function(){
  var $=function(i){ return document.getElementById(i); }, KEY='sd_view_v1', moc=null;
  var money=function(v){ return Number(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }, neg=function(v){ return v<0?'('+money(Math.abs(v))+')':money(v); };
  function view(v){ $('sdSumXls').style.display=v==='sum'?'inline-flex':'none'; $('sdSum').style.display=v==='sum'?'block':'none'; $('sdDetail').style.display=v==='det'?'block':'none'; [].forEach.call(document.querySelectorAll('.sdtabs button'),function(b){ b.classList.toggle('on',b.dataset.v===v); }); try{ localStorage.setItem(KEY,v); }catch(e){} }
  function filt(m){ moc=m; var rows=document.querySelectorAll('#sdDetail tbody tr[data-moc]'), t={c:0,b:0,d:0,a:0,l:0}, n=0;
    [].forEach.call(rows,function(r){ var show=m===null||r.dataset.moc===m; r.style.display=show?'':'none'; if(show){ n++; r.querySelector('.sdn').textContent=n; t.c+=+r.dataset.claim; t.b+=+r.dataset.bws; t.d+=+r.dataset.diff; t.a+=+r.dataset.cc; t.l+=+r.dataset.bal; } });
    if($('sdFN')){ $('sdFN').textContent='TOTAL ('+n+' scheme'+(n===1?'':'s')+(m!==null?' · MOC '+(m||'—'):'')+')'; $('sdFC').textContent=money(t.c); $('sdFB').textContent=money(t.b); $('sdFD').textContent=neg(t.d); $('sdFA').textContent=money(t.a); $('sdFL').textContent=neg(t.l); }
    $('sdMocBar').style.display=m===null?'none':'inline'; if(m!==null) $('sdMocTxt').textContent=m||'—'; }
  [].forEach.call(document.querySelectorAll('.sdtabs button'),function(b){ b.addEventListener('click',function(){ view(b.dataset.v); }); });
  document.querySelectorAll('tr.mocr').forEach(function(r){ r.addEventListener('click',function(){ filt(r.dataset.moc); view('det'); window.scrollTo({top:0,behavior:'smooth'}); }); });
  $('sdMocClr').addEventListener('click',function(e){ e.preventDefault(); filt(null); });
  var v0='det'; try{ v0=localStorage.getItem(KEY)||'det'; }catch(e){} view(v0==='sum'?'sum':'det');
})();
document.getElementById('sdTog').addEventListener('click',function(){ document.getElementById('sdBody').classList.toggle('collapsed'); });</script>
<?php include 'footer.php'; ?>
