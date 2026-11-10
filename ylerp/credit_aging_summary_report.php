<?php
/**
 * credit_aging_summary_report.php — CREDIT AGING SUMMARY REPORT
 * ─────────────────────────────────────────────────────────────────────
 * Runs "as at" any date. Shows the outstanding CREDIT BILLS count and value, split by how the
 * customer pays, and — for each set — how many bills are beyond their credit policy:
 *
 *   1. Credit customers            → credit policy EXCEEDED      (customers.payment_mode = credit)
 *   2. Cheque customers (only)     → cheque policy EXCEEDED      (payment_mode = cheque, NOT "cheques will delay")
 *   3. Cheques-will-delay customers→ cheques-will-delay EXCEEDED (customers.cheques_will_delay = 1)
 *   4. Cash customers              → NORMAL (no policy-exceed test, shown as normal)
 *   5. Pay mode not set            → policy EXCEEDED test, listed separately so nothing is missing
 *
 * Customer wise: every customer is listed with their set, credit policy days, bills, value and what exceeded the policy.
 * A bill is "exceeded" when  as-at date − (delivery date + the customer's credit policy days) > 0
 * — exactly the "Policy Aging" of the Credit Aging Report. Balances use the same rule as the Credit Aging
 * Report / Invoice List (rx_inv_parts), counted as at the date chosen.
 * Excel export: ?export=1 (same filters).   "View bills" opens the Credit Aging Report for that set.
 * ─────────────────────────────────────────────────────────────────────
 */
ob_start();
include 'config.php';
include_once __DIR__ . '/report_extras_lib.php';
rx_ensure($conn);

function cas_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function cas_money($v) { return number_format((float)$v, 2); }
function cas_valid_date($d) { if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$d, $m)) return false; return checkdate((int)$m[2], (int)$m[3], (int)$m[1]); }

/* ── filters ── */
$f_as_at = cas_valid_date($_GET['as_at_date'] ?? '') ? $_GET['as_at_date'] : date('Y-m-d');
$f_route = trim($_GET['route'] ?? '');
$f_sr    = trim($_GET['sr_code'] ?? '');
$as_at_ts = strtotime($f_as_at);
$HAS_CWD = rx_has_col($conn, 'customers', 'cheques_will_delay');

/* ── sets ── */
$GROUPS = [
    'credit' => ['label' => 'Credit customers',          'exceed' => 'Credit policy exceeded',         'icon' => 'fa-credit-card',     'color' => '#2563eb', 'bg' => '#eff6ff', 'view' => 'pay_mode=credit', 'test' => true],
    'cheque' => ['label' => 'Cheque customers (only)',   'exceed' => 'Cheque policy exceeded',         'icon' => 'fa-money-check',     'color' => '#7c3aed', 'bg' => '#f5f3ff', 'view' => 'pay_mode=cheque', 'test' => true],
    'cwd'    => ['label' => 'Cheques will delay',        'exceed' => 'Cheques-will-delay exceeded',    'icon' => 'fa-hourglass-half',  'color' => '#d97706', 'bg' => '#fffbeb', 'view' => 'pay_mode=cwd',    'test' => true],
    'cash'   => ['label' => 'Cash customers',            'exceed' => 'Normal (no policy test)',        'icon' => 'fa-money-bill-wave', 'color' => '#15803d', 'bg' => '#f0fdf4', 'view' => 'pay_mode=cash',   'test' => false],
    'none'   => ['label' => 'Pay mode not set',          'exceed' => 'Policy exceeded',                'icon' => 'fa-circle-question', 'color' => '#6b7280', 'bg' => '#f3f4f6', 'view' => 'pay_mode=none',   'test' => true],
];
$S = [];
foreach ($GROUPS as $k => $g) $S[$k] = ['n' => 0, 'v' => 0.0, 'ok_n' => 0, 'ok_v' => 0.0, 'ex_n' => 0, 'ex_v' => 0.0, 'customers' => [], 'ex_customers' => []];
$CUST = [];   // t_code => customer row (customer-wise list)

/* ── data: every outstanding credit bill as at the date ── */
$where = ["fsd.updated = 1", "fs.delivery_date <= '" . mysqli_real_escape_string($conn, $f_as_at) . "'"];
if ($f_route !== '') $where[] = "fs.route = '" . mysqli_real_escape_string($conn, $f_route) . "'";
if ($f_sr !== '')    $where[] = "fs.sr_code = '" . mysqli_real_escape_string($conn, $f_sr) . "'";
$P = rx_inv_parts($conn, $f_as_at);
$sql = "
SELECT fs.delivery_date, fs.route, fs.sr_code, fsd.t_code, COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS customer_name, {$P['balance']} AS balance,
       COALESCE(c.credit_days, 0) AS credit_days,
       LOWER(TRIM(COALESCE(c.payment_mode, ''))) AS payment_mode,
       " . ($HAS_CWD ? "COALESCE(c.cheques_will_delay,0)" : "0") . " AS cwd
FROM field_summary_details fsd
INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
LEFT  JOIN customers c ON c.t_code = fsd.t_code
{$P['joins']}
WHERE " . implode(' AND ', $where) . "
  AND {$P['balance']} > 0";
$res = mysqli_query($conn, $sql);
if ($res) while ($r = mysqli_fetch_assoc($res)) {
    $bal = (float)$r['balance'];
    $mode = $r['payment_mode'];
    if ((int)$r['cwd'] === 1)           $k = 'cwd';
    elseif ($mode === 'credit')         $k = 'credit';
    elseif ($mode === 'cheque')         $k = 'cheque';
    elseif ($mode === 'cash')           $k = 'cash';
    else                                $k = 'none';
    $del = $r['delivery_date'] ? strtotime($r['delivery_date']) : $as_at_ts;
    $over = (int)floor(($as_at_ts - ($del + (int)$r['credit_days'] * 86400)) / 86400);   // days past the policy due date
    $s = &$S[$k];
    $tc = (string)$r['t_code'];
    if (!isset($CUST[$tc])) $CUST[$tc] = ['t_code' => $tc, 'name' => $r['customer_name'], 'set' => $k, 'days' => (int)$r['credit_days'], 'route' => $r['route'], 'sr' => $r['sr_code'],
                                          'n' => 0, 'v' => 0.0, 'ex_n' => 0, 'ex_v' => 0.0, 'max_over' => 0, 'oldest' => $r['delivery_date']];
    $cu = &$CUST[$tc];
    $cu['n']++; $cu['v'] += $bal;
    if ($r['delivery_date'] && ($cu['oldest'] === null || $r['delivery_date'] < $cu['oldest'])) $cu['oldest'] = $r['delivery_date'];
    $s['n']++; $s['v'] += $bal; $s['customers'][$tc] = 1;
    if ($GROUPS[$k]['test'] && $over > 0) {
        $s['ex_n']++; $s['ex_v'] += $bal; $s['ex_customers'][$tc] = 1;
        $cu['ex_n']++; $cu['ex_v'] += $bal; if ($over > $cu['max_over']) $cu['max_over'] = $over;
    } else { $s['ok_n']++; $s['ok_v'] += $bal; }
    unset($s, $cu);
}
$T = ['n' => 0, 'v' => 0.0, 'ok_n' => 0, 'ok_v' => 0.0, 'ex_n' => 0, 'ex_v' => 0.0];
foreach ($S as $s) foreach (['n', 'v', 'ok_n', 'ok_v', 'ex_n', 'ex_v'] as $f) $T[$f] += $s[$f];
/* customer list: exceeded customers first (largest exceeded value), then the rest by value */
uasort($CUST, function ($a, $b) {
    if (($a['ex_v'] > 0) !== ($b['ex_v'] > 0)) return ($a['ex_v'] > 0) ? -1 : 1;
    return $b['ex_v'] <=> $a['ex_v'] ?: $b['v'] <=> $a['v'];
});
$f_set = $_GET['set'] ?? '';
$f_cust_q = trim($_GET['q'] ?? '');
$f_only_ex = ($_GET['only_exceeded'] ?? '') === '1';
$CUST_VIEW = array_filter($CUST, function ($c) use ($f_set, $f_cust_q, $f_only_ex) {
    if ($f_set !== '' && $c['set'] !== $f_set) return false;
    if ($f_only_ex && $c['ex_n'] === 0) return false;
    if ($f_cust_q !== '' && stripos($c['t_code'] . ' ' . $c['name'], $f_cust_q) === false) return false;
    return true;
});

/* ═════════════ Excel export ═════════════ */
if (isset($_GET['export'])) {
    require_once __DIR__ . '/rbac_xlsx.php';
    if (!class_exists('ZipArchive')) { http_response_code(500); exit('The PHP zip extension is required for Excel export.'); }
    $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '134E4A', 'h' => 'center', 'border' => 1, 'wrap' => 1];
    $X = new MiniXlsx();

    /* Sheet 1 — summary by set */
    $sh = $X->sheet('Summary');
    $sh->set(1, 1, 'Credit Aging Summary Report — as at ' . date('d M Y', $as_at_ts), ['b' => 1, 'sz' => 16, 'color' => 'FFFFFF', 'bg' => 'B91C1C', 'indent' => 1]);
    for ($c = 2; $c <= 9; $c++) $sh->set(1, $c, '', ['bg' => 'B91C1C']);
    $sh->merge(1, 1, 1, 9); $sh->heights[1] = 30;
    $sh->set(2, 1, 'Outstanding credit bills · exceeded = past (delivery date + customer credit policy days) · cash customers are shown as normal'
        . ($f_route !== '' ? ' · Route ' . $f_route : '') . ($f_sr !== '' ? ' · SR ' . $f_sr : ''), ['i' => 1, 'color' => '6B7280']);
    $sh->merge(2, 1, 2, 9);
    foreach (['Set', 'Policy test', 'Customers', 'Customers exceeded', 'Credit bills', 'Value (Rs.)', 'Normal bills', 'Normal value (Rs.)', 'Exceeded bills', 'Exceeded value (Rs.)'] as $i => $h) $sh->set(4, $i + 1, $h, $H);
    $r = 5;
    $line = function ($label, $test, $cn, $cex, $s, $bold = false, $bg = null, $na = false) use ($sh, &$r) {
        $st = ['border' => 1] + ($bold ? ['b' => 1] : []) + ($bg ? ['bg' => $bg] : []);
        $sh->set($r, 1, $label, $st + ['b' => 1]); $sh->set($r, 2, $test, $st);
        $sh->set($r, 3, $cn, $st + ['h' => 'center']); $sh->set($r, 4, $na ? '' : $cex, $st + ['h' => 'center']);
        foreach ([$s['n'], round($s['v'], 2), $s['ok_n'], round($s['ok_v'], 2), $na ? '' : $s['ex_n'], $na ? '' : round($s['ex_v'], 2)] as $i => $v) $sh->set($r, 5 + $i, $v, $st + ['h' => 'right']);
        $r++;
    };
    $allc = []; $allex = [];
    foreach ($GROUPS as $k => $g) { $line($g['label'], $g['exceed'], count($S[$k]['customers']), count($S[$k]['ex_customers']), $S[$k], false, null, !$g['test']); $allc += $S[$k]['customers']; $allex += $S[$k]['ex_customers']; }
    $line('TOTAL', '', count($allc), count($allex), $T, true, 'FDE68A');
    foreach ([30, 30, 11, 13, 11, 18, 11, 18, 11, 18] as $i => $w) $sh->widths[$i + 1] = $w;
    $sh->freeze = [4, 0];

    /* Sheet 2 — customer wise */
    $cs = $X->sheet('Customers');
    foreach (['T code', 'Customer', 'Set', 'Policy status', 'Credit policy days', 'Route', 'SR', 'Oldest bill', 'Bills', 'Value (Rs.)', 'Exceeded bills', 'Exceeded value (Rs.)', 'Max days over policy'] as $i => $h) $cs->set(1, $i + 1, $h, $H);
    $r = 2;
    foreach ($CUST_VIEW as $c) {
        $g = $GROUPS[$c['set']]; $ex = $g['test'] && $c['ex_n'] > 0;
        $bg = $ex ? ['bg' => 'FEF2F2'] : [];
        $status = !$g['test'] ? 'Normal (cash)' : ($ex ? $g['exceed'] : 'Within policy');
        $vals = [$c['t_code'], $c['name'], $g['label'], $status, $c['days'], $c['route'], $c['sr'], $c['oldest'] ? date('d M Y', strtotime($c['oldest'])) : '', $c['n'], round($c['v'], 2),
                 $g['test'] ? $c['ex_n'] : '', $g['test'] ? round($c['ex_v'], 2) : '', $g['test'] ? ($c['max_over'] ?: '') : ''];
        foreach ($vals as $i => $v) $cs->set($r, $i + 1, $v, ['border' => 1] + $bg + ($i === 3 ? ['b' => 1, 'color' => $ex ? 'B91C1C' : '15803D'] : []) + ($i >= 8 ? ['h' => 'right'] : []));
        $r++;
    }
    foreach ([12, 36, 26, 28, 12, 14, 10, 13, 8, 16, 10, 18, 12] as $i => $w) $cs->widths[$i + 1] = $w;
    $cs->freeze = [1, 2]; $cs->filter = 'A1:M' . max(2, $r - 1);

    $tmp = tempnam(sys_get_temp_dir(), 'cs'); $X->save($tmp);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="credit_aging_summary_' . $f_as_at . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp); @unlink($tmp); exit;
}

/* ── filter options ── */
$all_routes = []; $rr = mysqli_query($conn, "SELECT route_code, route_name FROM routes WHERE active=1 ORDER BY route_name");
while ($rr && ($x = mysqli_fetch_assoc($rr))) $all_routes[] = $x;
$all_sr = []; $sq = mysqli_query($conn, "SELECT DISTINCT sr_code FROM field_summary WHERE sr_code IS NOT NULL AND sr_code <> '' ORDER BY sr_code");
while ($sq && ($x = mysqli_fetch_row($sq))) $all_sr[] = $x[0];
$qs = $_GET; unset($qs['export']);
$exp_url = 'credit_aging_summary_report.php?' . http_build_query($qs + ['as_at_date' => $f_as_at]) . '&export=1';
$set_url = function ($set) use ($qs, $f_as_at) { $q = $qs + ['as_at_date' => $f_as_at]; unset($q['set']); if ($set !== '') $q['set'] = $set; return 'credit_aging_summary_report.php?' . http_build_query($q) . '#customers'; };
$pct = fn($a, $b) => $b > 0 ? round($a / $b * 100, 1) : 0;
$ac = []; foreach ($S as $s) $ac += $s['customers'];
$ex_c = []; foreach ($S as $s) $ex_c += $s['ex_customers'];
include 'header.php';
?>
<style>
.cas { --ink:#111827; --muted:#6b7280; --line:#e5e7eb; font-family:'Inter',system-ui,sans-serif; font-size:13px; color:var(--ink); background:#f4f5f8; padding:22px 26px 48px; min-height:100%; }
.cas *, .cas *::before, .cas *::after { box-sizing:border-box; }
.cas h1 { margin:0; font-size:22px; font-weight:800; display:flex; align-items:center; gap:10px; flex-wrap:wrap; } .cas h1 .ic { width:40px; height:40px; border-radius:10px; background:linear-gradient(135deg,#e63946,#b91c1c); color:#fff; display:flex; align-items:center; justify-content:center; font-size:16px; }
.cas .sub { margin:6px 0 16px; color:var(--muted); max-width:110ch; }
.cas .asat { background:#111827; color:#fff; border-radius:20px; padding:3px 12px; font-size:11px; font-weight:700; }
.cas .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:14px 16px; margin-bottom:14px; }
.cas form.flt { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; } .cas .fld { display:flex; flex-direction:column; gap:4px; }
.cas .fld label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
.cas input[type=date], .cas input[type=search], .cas select { height:36px; padding:0 10px; border:1px solid #d1d5db; border-radius:8px; font:inherit; background:#fff; color:var(--ink); min-width:150px; }
.cas .btn { display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 16px; border-radius:8px; border:1px solid transparent; background:#111827; color:#fff; font:inherit; font-weight:700; cursor:pointer; text-decoration:none; }
.cas .btn.light { background:#fff; color:var(--ink); border-color:#d1d5db; } .cas .btn.xl { background:#14532d; } .cas .btn.pr { background:#1e3a5f; }
.cas .kpis { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-bottom:14px; }
.cas .kpi { background:#fff; border:1px solid var(--line); border-radius:12px; padding:14px 16px; position:relative; overflow:hidden; }
.cas .kpi::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:var(--c,#111827); }
.cas .kpi .l { font-size:10.5px; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; }
.cas .kpi .n { font-size:26px; font-weight:800; line-height:1.15; margin-top:3px; } .cas .kpi .v { font-size:14px; font-weight:700; color:#374151; font-variant-numeric:tabular-nums; } .cas .kpi .p { font-size:11px; color:var(--muted); }
.cas .wrap { overflow-x:auto; } .cas table { width:100%; border-collapse:collapse; }
.cas th { padding:9px 10px; background:#134e4a; color:#fff; font-size:11px; font-weight:700; text-align:center; border:1px solid #0f3f3b; white-space:nowrap; }
.cas th.l { text-align:left; } .cas th.sub2 { background:#1f6f69; font-size:10.5px; }
.cas th.ex { background:#b91c1c; border-color:#991b1b; } .cas th.ex2 { background:#dc2626; font-size:10.5px; border-color:#991b1b; } .cas th.ok { background:#15803d; border-color:#166534; } .cas th.ok2 { background:#16a34a; font-size:10.5px; border-color:#166534; }
.cas td { padding:10px; border:1px solid #eef0f3; text-align:right; font-variant-numeric:tabular-nums; vertical-align:middle; } .cas td.l { text-align:left; } .cas td.c { text-align:center; }
.cas .gname { display:flex; align-items:center; gap:10px; font-weight:800; } .cas .gname i { width:30px; height:30px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:13px; flex-shrink:0; }
.cas .ptest { display:inline-block; padding:2px 10px; border-radius:99px; font-size:11px; font-weight:700; border:1px solid; white-space:nowrap; }
.cas .ptest.ex { color:#b91c1c; background:#fef2f2; border-color:#fecaca; } .cas .ptest.normal { color:#15803d; background:#f0fdf4; border-color:#bbf7d0; }
.cas td.exv { background:#fff5f5; font-weight:700; color:#b91c1c; } .cas td.okv { background:#f6fef9; color:#15803d; font-weight:600; } .cas td.na { color:#9ca3af; text-align:center; }
.cas tr.tot td { background:#fef3c7; font-weight:800; border-top:2px solid #f59e0b; }
.cas h2 { margin:6px 0 10px; font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.cas .tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px; } .cas .tabs a { padding:6px 14px; border-radius:99px; border:1px solid var(--line); background:#fff; color:#374151; font-weight:700; font-size:12px; text-decoration:none; }
.cas .tabs a.on { background:#111827; border-color:#111827; color:#fff; } .cas .tabs a .cnt { opacity:.7; font-weight:600; margin-left:4px; }
.cas .cust tbody tr:hover td { background:#fafafb; } .cas .cust tr.exrow td { background:#fff8f8; }
.cas .cust .nm { font-weight:700; text-align:left; } .cas .cust .tc { font-size:11px; color:var(--muted); display:block; font-weight:600; }
.cas .st { display:inline-block; padding:2px 10px; border-radius:99px; font-size:11px; font-weight:800; border:1px solid; white-space:nowrap; }
.cas .st.bad { color:#b91c1c; background:#fef2f2; border-color:#fecaca; } .cas .st.good { color:#15803d; background:#f0fdf4; border-color:#bbf7d0; }
.cas .setb { display:inline-flex; align-items:center; gap:6px; font-weight:700; font-size:12px; }
.cas .view a { font-size:11px; font-weight:700; color:#1d4ed8; text-decoration:none; white-space:nowrap; } .cas .view a:hover { text-decoration:underline; }
.cas .note { color:var(--muted); font-size:12px; margin-top:8px; line-height:1.6; }
@media (max-width:1000px) { .cas .kpis { grid-template-columns:1fr 1fr; } .cas { padding:16px 12px 40px; } }
@media print { .cas form.flt, .cas .noprint, .main-header, #sidebar, .sidebar { display:none !important; } .cas { padding:0; background:#fff; } }
</style>

<div class="cas">
    <h1><span class="ic"><i class="fa-solid fa-chart-pie"></i></span> Credit Aging Summary Report <span class="asat"><i class="fa-regular fa-calendar"></i> As at <?= cas_h(date('d M Y', $as_at_ts)) ?></span></h1>
    <p class="sub">Outstanding <b>credit bills</b> (count and value) as at the chosen date, by the way each customer pays. A bill is <b style="color:#b91c1c">exceeded</b> when it is past its delivery date + the customer's credit policy days.
        <b>Credit</b>, <b>Cheque (only)</b> and <b>Cheques will delay</b> customers show their policy exceeded; <b style="color:#15803d">Cash</b> customers are shown as normal. The customer list below shows each customer's own status.</p>

    <form class="card flt" method="get" action="credit_aging_summary_report.php">
        <div class="fld"><label for="asAt">As at date</label><input type="date" id="asAt" name="as_at_date" value="<?= cas_h($f_as_at) ?>" required></div>
        <div class="fld"><label for="rt">Route</label><select id="rt" name="route"><option value="">All routes</option>
            <?php foreach ($all_routes as $r): ?><option value="<?= cas_h($r['route_code']) ?>"<?= $f_route === $r['route_code'] ? ' selected' : '' ?>><?= cas_h($r['route_name']) ?></option><?php endforeach; ?></select></div>
        <div class="fld"><label for="sr">SR code</label><select id="sr" name="sr_code"><option value="">All SR</option>
            <?php foreach ($all_sr as $c): ?><option value="<?= cas_h($c) ?>"<?= $f_sr === $c ? ' selected' : '' ?>><?= cas_h($c) ?></option><?php endforeach; ?></select></div>
        <input type="hidden" name="set" value="<?= cas_h($f_set) ?>">
        <button class="btn" type="submit"><i class="fa-solid fa-play"></i> Run report</button>
        <a class="btn light" href="credit_aging_summary_report.php">Reset</a>
        <a class="btn xl" href="<?= cas_h($exp_url) ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
        <button class="btn pr noprint" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </form>

    <section class="kpis">
        <div class="kpi" style="--c:#111827"><div class="l">All credit bills</div><div class="n"><?= number_format($T['n']) ?></div><div class="v">Rs. <?= cas_money($T['v']) ?></div></div>
        <div class="kpi" style="--c:#15803d"><div class="l">Normal / within policy</div><div class="n" style="color:#15803d"><?= number_format($T['ok_n']) ?></div><div class="v">Rs. <?= cas_money($T['ok_v']) ?></div><div class="p"><?= $pct($T['ok_v'], $T['v']) ?>% of value</div></div>
        <div class="kpi" style="--c:#b91c1c"><div class="l">Policy exceeded</div><div class="n" style="color:#b91c1c"><?= number_format($T['ex_n']) ?></div><div class="v">Rs. <?= cas_money($T['ex_v']) ?></div><div class="p"><?= $pct($T['ex_v'], $T['v']) ?>% of value</div></div>
        <div class="kpi" style="--c:#2563eb"><div class="l">Customers with bills</div><div class="n"><?= number_format(count($ac)) ?></div><div class="v">as at <?= cas_h(date('d M Y', $as_at_ts)) ?></div></div>
        <div class="kpi" style="--c:#dc2626"><div class="l">Customers exceeded</div><div class="n" style="color:#dc2626"><?= number_format(count($ex_c)) ?></div><div class="v">of <?= number_format(count($ac)) ?> customers</div></div>
    </section>

    <div class="card wrap">
    <table>
        <thead>
            <tr>
                <th class="l" rowspan="2">Set</th><th rowspan="2">Policy test</th><th rowspan="2">Customers</th><th rowspan="2">Customers exceeded</th>
                <th colspan="2">All credit bills</th><th colspan="2" class="ok">Normal / within policy</th><th colspan="2" class="ex">Policy exceeded</th><th rowspan="2" class="noprint">Customers</th>
            </tr>
            <tr><th class="sub2">Bills</th><th class="sub2">Value (Rs.)</th><th class="ok2">Bills</th><th class="ok2">Value (Rs.)</th><th class="ex2">Bills</th><th class="ex2">Value (Rs.)</th></tr>
        </thead>
        <tbody>
        <?php foreach ($GROUPS as $k => $g): $s = $S[$k]; ?>
            <tr>
                <td class="l"><div class="gname"><i class="fa-solid <?= $g['icon'] ?>" style="background:<?= $g['bg'] ?>;color:<?= $g['color'] ?>"></i><?= cas_h($g['label']) ?></div></td>
                <td class="c"><span class="ptest <?= $g['test'] ? 'ex' : 'normal' ?>"><?= cas_h($g['exceed']) ?></span></td>
                <td class="c"><?= number_format(count($s['customers'])) ?></td>
                <td class="c <?= $g['test'] && $s['ex_customers'] ? 'exv' : 'na' ?>"><?= $g['test'] ? number_format(count($s['ex_customers'])) : '–' ?></td>
                <td><?= number_format($s['n']) ?></td><td><?= cas_money($s['v']) ?></td>
                <td class="okv"><?= number_format($s['ok_n']) ?></td><td class="okv"><?= cas_money($s['ok_v']) ?></td>
                <?php if ($g['test']): ?><td class="exv"><?= number_format($s['ex_n']) ?></td><td class="exv"><?= cas_money($s['ex_v']) ?></td>
                <?php else: ?><td class="na" colspan="2">Normal</td><?php endif; ?>
                <td class="c view noprint"><a href="<?= cas_h($set_url($k)) ?>">Show customers ↓</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="tot">
            <td class="l">TOTAL</td><td></td><td class="c"><?= number_format(count($ac)) ?></td><td class="c"><?= number_format(count($ex_c)) ?></td>
            <td><?= number_format($T['n']) ?></td><td><?= cas_money($T['v']) ?></td><td><?= number_format($T['ok_n']) ?></td><td><?= cas_money($T['ok_v']) ?></td>
            <td><?= number_format($T['ex_n']) ?></td><td><?= cas_money($T['ex_v']) ?></td><td class="noprint"></td>
        </tr></tfoot>
    </table>
    </div>

    <h2 id="customers"><i class="fa-solid fa-users" style="color:#0f766e"></i> Customer wise — policy status</h2>
    <div class="tabs noprint">
        <a href="<?= cas_h($set_url('')) ?>" class="<?= $f_set === '' ? 'on' : '' ?>">All sets<span class="cnt"><?= count($CUST) ?></span></a>
        <?php foreach ($GROUPS as $k => $g): $n = count(array_filter($CUST, fn($c) => $c['set'] === $k)); ?>
            <a href="<?= cas_h($set_url($k)) ?>" class="<?= $f_set === $k ? 'on' : '' ?>"><?= cas_h($g['label']) ?><span class="cnt"><?= $n ?></span></a>
        <?php endforeach; ?>
    </div>
    <form class="card flt noprint" method="get" action="credit_aging_summary_report.php#customers" style="padding:10px 14px">
        <input type="hidden" name="as_at_date" value="<?= cas_h($f_as_at) ?>"><input type="hidden" name="route" value="<?= cas_h($f_route) ?>"><input type="hidden" name="sr_code" value="<?= cas_h($f_sr) ?>"><input type="hidden" name="set" value="<?= cas_h($f_set) ?>">
        <div class="fld"><label for="cq">Customer</label><input type="search" id="cq" name="q" value="<?= cas_h($f_cust_q) ?>" placeholder="T code or name"></div>
        <label style="display:flex;gap:6px;align-items:center;font-weight:700;height:36px"><input type="checkbox" name="only_exceeded" value="1" <?= $f_only_ex ? 'checked' : '' ?> style="min-width:0;height:auto"> Only customers who exceeded</label>
        <button class="btn" type="submit">Filter</button>
    </form>
    <div class="card wrap">
    <table class="cust">
        <thead><tr><th>#</th><th class="l">Customer</th><th class="l">Set</th><th>Policy status</th><th>Policy days</th><th>Route</th><th>SR</th><th>Oldest bill</th><th>Bills</th><th>Value (Rs.)</th><th>Exceeded bills</th><th>Exceeded value (Rs.)</th><th>Max days over</th></tr></thead>
        <tbody>
        <?php $i = 0; foreach ($CUST_VIEW as $c): $i++; $g = $GROUPS[$c['set']]; $ex = $g['test'] && $c['ex_n'] > 0; ?>
            <tr class="<?= $ex ? 'exrow' : '' ?>">
                <td class="c"><?= $i ?></td>
                <td class="nm"><?= cas_h($c['name']) ?><span class="tc"><?= cas_h($c['t_code']) ?></span></td>
                <td class="l"><span class="setb"><i class="fa-solid <?= $g['icon'] ?>" style="color:<?= $g['color'] ?>"></i><?= cas_h($g['label']) ?></span></td>
                <td class="c"><?php if (!$g['test']): ?><span class="st good">Normal</span><?php elseif ($ex): ?><span class="st bad"><?= cas_h($g['exceed']) ?></span><?php else: ?><span class="st good">Within policy</span><?php endif; ?></td>
                <td class="c"><?= (int)$c['days'] ?>d</td><td class="c"><?= cas_h($c['route']) ?></td><td class="c"><?= cas_h($c['sr']) ?></td>
                <td class="c"><?= $c['oldest'] ? cas_h(date('d M Y', strtotime($c['oldest']))) : '—' ?></td>
                <td><?= number_format($c['n']) ?></td><td><?= cas_money($c['v']) ?></td>
                <td class="<?= $ex ? 'exv' : 'na' ?>"><?= $g['test'] ? number_format($c['ex_n']) : '–' ?></td>
                <td class="<?= $ex ? 'exv' : 'na' ?>"><?= $g['test'] ? cas_money($c['ex_v']) : '–' ?></td>
                <td class="<?= $ex ? 'exv' : 'na' ?>"><?= $ex ? (int)$c['max_over'] . 'd' : '–' ?></td>
            </tr>
        <?php endforeach; if ($i === 0): ?><tr><td colspan="13" class="na" style="padding:22px">No customers match.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
    <p class="note">Exceeded = as-at date − (delivery date + customer credit policy days) &gt; 0, the same “Policy Aging” as the Credit Aging Report. A customer is “exceeded” when at least one of their bills is. “Cheques will delay” customers are counted only in their own set. Customers whose pay mode is not set are listed separately so every outstanding bill is counted once. For the bills of a customer open the Credit Aging Report with the same as-at date.</p>
</div>
<?php include 'footer.php'; ?>
