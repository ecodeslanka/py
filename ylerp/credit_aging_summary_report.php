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
$BUCKETS = ['1-7' => '1–7 days', '8-14' => '8–14 days', '15-21' => '15–21 days', '22-28' => '22–28 days', '29-35' => '29–35 days', '35+' => '35+ days'];
function cas_bucket($d) { return $d <= 7 ? '1-7' : ($d <= 14 ? '8-14' : ($d <= 21 ? '15-21' : ($d <= 28 ? '22-28' : ($d <= 35 ? '29-35' : '35+')))); }

$S = [];
foreach ($GROUPS as $k => $g) {
    $S[$k] = ['n' => 0, 'v' => 0.0, 'ok_n' => 0, 'ok_v' => 0.0, 'ex_n' => 0, 'ex_v' => 0.0, 'customers' => [], 'b' => []];
    foreach ($BUCKETS as $bk => $_) $S[$k]['b'][$bk] = ['n' => 0, 'v' => 0.0];
}

/* ── data: every outstanding credit bill as at the date ── */
$where = ["fsd.updated = 1", "fs.delivery_date <= '" . mysqli_real_escape_string($conn, $f_as_at) . "'"];
if ($f_route !== '') $where[] = "fs.route = '" . mysqli_real_escape_string($conn, $f_route) . "'";
if ($f_sr !== '')    $where[] = "fs.sr_code = '" . mysqli_real_escape_string($conn, $f_sr) . "'";
$P = rx_inv_parts($conn, $f_as_at);
$sql = "
SELECT fs.delivery_date, fsd.t_code, {$P['balance']} AS balance,
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
    $s['n']++; $s['v'] += $bal; $s['customers'][$r['t_code']] = 1;
    if ($GROUPS[$k]['test'] && $over > 0) {
        $s['ex_n']++; $s['ex_v'] += $bal;
        $bk = cas_bucket($over); $s['b'][$bk]['n']++; $s['b'][$bk]['v'] += $bal;
    } else { $s['ok_n']++; $s['ok_v'] += $bal; }
    unset($s);
}
$T = ['n' => 0, 'v' => 0.0, 'ok_n' => 0, 'ok_v' => 0.0, 'ex_n' => 0, 'ex_v' => 0.0];
foreach ($S as $s) foreach (['n', 'v', 'ok_n', 'ok_v', 'ex_n', 'ex_v'] as $f) $T[$f] += $s[$f];

/* ═════════════ Excel export ═════════════ */
if (isset($_GET['export'])) {
    require_once __DIR__ . '/rbac_xlsx.php';
    if (!class_exists('ZipArchive')) { http_response_code(500); exit('The PHP zip extension is required for Excel export.'); }
    $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '134E4A', 'h' => 'center', 'border' => 1, 'wrap' => 1];
    $X = new MiniXlsx(); $sh = $X->sheet('Credit Aging Summary');
    $cols = 4 + 2 + 2 + 2 + count($BUCKETS) * 2;
    $sh->set(1, 1, 'Credit Aging Summary Report — as at ' . date('d M Y', $as_at_ts), ['b' => 1, 'sz' => 16, 'color' => 'FFFFFF', 'bg' => 'B91C1C', 'indent' => 1]);
    for ($c = 2; $c <= $cols; $c++) $sh->set(1, $c, '', ['bg' => 'B91C1C']);
    $sh->merge(1, 1, 1, $cols); $sh->heights[1] = 30;
    $sh->set(2, 1, 'Outstanding credit bills as at the date · exceeded = days past (delivery date + customer credit policy days) · cash customers are shown as normal'
        . ($f_route !== '' ? ' · Route ' . $f_route : '') . ($f_sr !== '' ? ' · SR ' . $f_sr : ''), ['i' => 1, 'color' => '6B7280']);
    $sh->merge(2, 1, 2, $cols);
    $r1 = 4; $r2 = 5;
    foreach ([[1, 'Set', 2], [3, 'Policy test', 1], [4, 'Customers', 1]] as [$c0, $t, $span]) {     // tall headers: rows 4-5
        $sh->set($r1, $c0, $t, $H); $sh->set($r2, $c0, '', $H);
        for ($k = 1; $k < $span; $k++) { $sh->set($r1, $c0 + $k, '', $H); $sh->set($r2, $c0 + $k, '', $H); }
        $sh->merge($r1, $c0, $r2, $c0 + $span - 1);
    }
    $c = 5;
    $groupsHdr = [['All credit bills', '134E4A'], ['Normal / within policy', '15803D'], ['Policy exceeded', 'B91C1C']];
    foreach ($BUCKETS as $bl) $groupsHdr[] = ['Exceeded by ' . $bl, 'B91C1C'];
    foreach ($groupsHdr as [$t, $bgc]) {
        $st = ['b' => 1, 'color' => 'FFFFFF', 'bg' => $bgc, 'h' => 'center', 'border' => 1];
        $sh->set($r1, $c, $t, $st); $sh->set($r1, $c + 1, '', $st); $sh->merge($r1, $c, $r1, $c + 1);
        $sh->set($r2, $c, 'Bills', $st); $sh->set($r2, $c + 1, 'Value (Rs.)', $st);
        $c += 2;
    }
    $r = 6;
    $rowOut = function ($label, $test, $cust, $s, $bold = false, $bg = null) use ($sh, &$r, $BUCKETS, $GROUPS) {
        $st = ['border' => 1] + ($bold ? ['b' => 1] : []) + ($bg ? ['bg' => $bg] : []);
        $sh->set($r, 1, $label, $st + ['b' => 1]); $sh->set($r, 2, '', $st); $sh->merge($r, 1, $r, 2);
        $sh->set($r, 3, $test, $st); $sh->set($r, 4, $cust, $st + ['h' => 'center']);
        $vals = [$s['n'], $s['v'], $s['ok_n'], $s['ok_v'], $s['ex_n'], $s['ex_v']];
        foreach ($s['b'] ?? [] as $b) { $vals[] = $b['n']; $vals[] = $b['v']; }
        foreach ($vals as $i => $v) $sh->set($r, 5 + $i, is_float($v) ? round($v, 2) : $v, $st + ['h' => 'right']);
        $r++;
    };
    foreach ($GROUPS as $k => $g) {
        $s = $S[$k];
        if (!$g['test']) { $s['ex_n'] = ''; $s['ex_v'] = ''; $s['b'] = []; foreach ($BUCKETS as $bk => $_) $s['b'][$bk] = ['n' => '', 'v' => '']; }
        $rowOut($g['label'], $g['exceed'], count($S[$k]['customers']), $s);
    }
    $tb = []; foreach ($BUCKETS as $bk => $_) { $tb[$bk] = ['n' => 0, 'v' => 0.0]; foreach ($S as $k => $s) { $tb[$bk]['n'] += $s['b'][$bk]['n']; $tb[$bk]['v'] += $s['b'][$bk]['v']; } }
    $allc = []; foreach ($S as $s) $allc += $s['customers'];
    $rowOut('TOTAL', '', count($allc), $T + ['b' => $tb], true, 'FDE68A');
    foreach ([30, 4, 28, 11, 9, 16, 9, 16, 9, 16] as $i => $w) $sh->widths[$i + 1] = $w;
    for ($c = 11; $c <= $cols; $c++) $sh->widths[$c] = ($c % 2) ? 15 : 8;
    $sh->freeze = [5, 2];
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
$pct = fn($a, $b) => $b > 0 ? round($a / $b * 100, 1) : 0;
include 'header.php';
?>
<style>
.cas { --ink:#111827; --muted:#6b7280; --line:#e5e7eb; font-family:'Inter',system-ui,sans-serif; font-size:13px; color:var(--ink); background:#f4f5f8; padding:22px 26px 48px; min-height:100%; }
.cas *, .cas *::before, .cas *::after { box-sizing:border-box; }
.cas h1 { margin:0; font-size:22px; font-weight:800; display:flex; align-items:center; gap:10px; } .cas h1 .ic { width:40px; height:40px; border-radius:10px; background:linear-gradient(135deg,#e63946,#b91c1c); color:#fff; display:flex; align-items:center; justify-content:center; font-size:16px; }
.cas .sub { margin:6px 0 16px; color:var(--muted); max-width:110ch; }
.cas .asat { background:#111827; color:#fff; border-radius:20px; padding:3px 12px; font-size:11px; font-weight:700; margin-left:6px; }
.cas .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:14px 16px; margin-bottom:14px; }
.cas form.flt { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; } .cas .fld { display:flex; flex-direction:column; gap:4px; }
.cas .fld label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
.cas input[type=date], .cas select { height:36px; padding:0 10px; border:1px solid #d1d5db; border-radius:8px; font:inherit; background:#fff; color:var(--ink); min-width:150px; }
.cas .btn { display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 16px; border-radius:8px; border:1px solid transparent; background:#111827; color:#fff; font:inherit; font-weight:700; cursor:pointer; text-decoration:none; }
.cas .btn.light { background:#fff; color:var(--ink); border-color:#d1d5db; } .cas .btn.xl { background:#14532d; } .cas .btn.pr { background:#1e3a5f; }
.cas .kpis { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:14px; }
.cas .kpi { background:#fff; border:1px solid var(--line); border-radius:12px; padding:14px 16px; position:relative; overflow:hidden; }
.cas .kpi::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:var(--c,#111827); }
.cas .kpi .l { font-size:10.5px; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; }
.cas .kpi .n { font-size:26px; font-weight:800; line-height:1.15; margin-top:3px; } .cas .kpi .v { font-size:14px; font-weight:700; color:#374151; font-variant-numeric:tabular-nums; } .cas .kpi .p { font-size:11px; color:var(--muted); }
.cas .wrap { overflow-x:auto; } .cas table { width:100%; border-collapse:collapse; min-width:1180px; }
.cas th { padding:9px 10px; background:#134e4a; color:#fff; font-size:11px; font-weight:700; text-align:center; border:1px solid #0f3f3b; white-space:nowrap; }
.cas th.l { text-align:left; } .cas th.sub2 { background:#1f6f69; font-size:10.5px; }
.cas th.ex { background:#b91c1c; border-color:#991b1b; } .cas th.ex2 { background:#dc2626; font-size:10.5px; border-color:#991b1b; } .cas th.ok { background:#15803d; border-color:#166534; } .cas th.ok2 { background:#16a34a; font-size:10.5px; border-color:#166534; }
.cas td { padding:10px; border:1px solid #eef0f3; text-align:right; font-variant-numeric:tabular-nums; vertical-align:middle; } .cas td.l { text-align:left; } .cas td.c { text-align:center; }
.cas .gname { display:flex; align-items:center; gap:10px; font-weight:800; } .cas .gname i { width:30px; height:30px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:13px; flex-shrink:0; }
.cas .ptest { display:inline-block; padding:2px 10px; border-radius:99px; font-size:11px; font-weight:700; border:1px solid; white-space:nowrap; }
.cas .ptest.ex { color:#b91c1c; background:#fef2f2; border-color:#fecaca; } .cas .ptest.normal { color:#15803d; background:#f0fdf4; border-color:#bbf7d0; }
.cas td.exv { background:#fff5f5; font-weight:700; color:#b91c1c; } .cas td.okv { background:#f6fef9; color:#15803d; font-weight:600; }
.cas td.na { color:#9ca3af; text-align:center; }
.cas tr.tot td { background:#fef3c7; font-weight:800; border-top:2px solid #f59e0b; }
.cas .view a { font-size:11px; font-weight:700; color:#1d4ed8; text-decoration:none; white-space:nowrap; } .cas .view a:hover { text-decoration:underline; }
.cas .note { color:var(--muted); font-size:12px; margin-top:8px; line-height:1.6; }
@media (max-width:900px) { .cas .kpis { grid-template-columns:1fr 1fr; } .cas { padding:16px 12px 40px; } }
@media print { .cas form.flt, .cas .noprint, .main-header, #sidebar, .sidebar { display:none !important; } .cas { padding:0; background:#fff; } }
</style>

<div class="cas">
    <h1><span class="ic"><i class="fa-solid fa-chart-pie"></i></span> Credit Aging Summary Report <span class="asat"><i class="fa-regular fa-calendar"></i> As at <?= cas_h(date('d M Y', $as_at_ts)) ?></span></h1>
    <p class="sub">Outstanding <b>credit bills</b> (count and value) as at the chosen date, by how the customer pays. A bill is <b style="color:#b91c1c">exceeded</b> when it is past its delivery date + the customer's credit policy days.
        <b>Credit</b>, <b>Cheque (only)</b> and <b>Cheques will delay</b> customers show their policy exceeded; <b style="color:#15803d">Cash</b> customers are shown as normal.</p>

    <form class="card flt" method="get" action="credit_aging_summary_report.php">
        <div class="fld"><label for="asAt">As at date</label><input type="date" id="asAt" name="as_at_date" value="<?= cas_h($f_as_at) ?>" required></div>
        <div class="fld"><label for="rt">Route</label><select id="rt" name="route"><option value="">All routes</option>
            <?php foreach ($all_routes as $r): ?><option value="<?= cas_h($r['route_code']) ?>"<?= $f_route === $r['route_code'] ? ' selected' : '' ?>><?= cas_h($r['route_name']) ?></option><?php endforeach; ?></select></div>
        <div class="fld"><label for="sr">SR code</label><select id="sr" name="sr_code"><option value="">All SR</option>
            <?php foreach ($all_sr as $c): ?><option value="<?= cas_h($c) ?>"<?= $f_sr === $c ? ' selected' : '' ?>><?= cas_h($c) ?></option><?php endforeach; ?></select></div>
        <button class="btn" type="submit"><i class="fa-solid fa-play"></i> Run report</button>
        <a class="btn light" href="credit_aging_summary_report.php">Reset</a>
        <a class="btn xl" href="<?= cas_h($exp_url) ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
        <button class="btn pr noprint" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </form>

    <section class="kpis">
        <div class="kpi" style="--c:#111827"><div class="l">All credit bills</div><div class="n"><?= number_format($T['n']) ?></div><div class="v">Rs. <?= cas_money($T['v']) ?></div></div>
        <div class="kpi" style="--c:#15803d"><div class="l">Normal / within policy</div><div class="n" style="color:#15803d"><?= number_format($T['ok_n']) ?></div><div class="v">Rs. <?= cas_money($T['ok_v']) ?></div><div class="p"><?= $pct($T['ok_v'], $T['v']) ?>% of value</div></div>
        <div class="kpi" style="--c:#b91c1c"><div class="l">Policy exceeded</div><div class="n" style="color:#b91c1c"><?= number_format($T['ex_n']) ?></div><div class="v">Rs. <?= cas_money($T['ex_v']) ?></div><div class="p"><?= $pct($T['ex_v'], $T['v']) ?>% of value</div></div>
        <div class="kpi" style="--c:#2563eb"><div class="l">Customers with bills</div><?php $ac = []; foreach ($S as $s) $ac += $s['customers']; ?><div class="n"><?= number_format(count($ac)) ?></div><div class="v">as at <?= cas_h(date('d M Y', $as_at_ts)) ?></div></div>
    </section>

    <div class="card wrap">
    <table>
        <thead>
            <tr>
                <th class="l" rowspan="2">Set</th><th rowspan="2">Policy test</th><th rowspan="2">Customers</th>
                <th colspan="2">All credit bills</th>
                <th colspan="2" class="ok">Normal / within policy</th>
                <th colspan="2" class="ex">Policy exceeded</th>
                <?php foreach ($BUCKETS as $bl): ?><th colspan="2" class="ex">Exceeded by <?= cas_h($bl) ?></th><?php endforeach; ?>
                <th rowspan="2" class="noprint">Bills</th>
            </tr>
            <tr>
                <th class="sub2">Bills</th><th class="sub2">Value (Rs.)</th>
                <th class="ok2">Bills</th><th class="ok2">Value (Rs.)</th>
                <th class="ex2">Bills</th><th class="ex2">Value (Rs.)</th>
                <?php foreach ($BUCKETS as $_): ?><th class="ex2">Bills</th><th class="ex2">Value (Rs.)</th><?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($GROUPS as $k => $g): $s = $S[$k]; ?>
            <tr>
                <td class="l"><div class="gname"><i class="fa-solid <?= $g['icon'] ?>" style="background:<?= $g['bg'] ?>;color:<?= $g['color'] ?>"></i><?= cas_h($g['label']) ?></div></td>
                <td class="c"><span class="ptest <?= $g['test'] ? 'ex' : 'normal' ?>"><?= cas_h($g['exceed']) ?></span></td>
                <td class="c"><?= number_format(count($s['customers'])) ?></td>
                <td><?= number_format($s['n']) ?></td><td><?= cas_money($s['v']) ?></td>
                <td class="okv"><?= number_format($s['ok_n']) ?></td><td class="okv"><?= cas_money($s['ok_v']) ?></td>
                <?php if ($g['test']): ?>
                    <td class="exv"><?= number_format($s['ex_n']) ?></td><td class="exv"><?= cas_money($s['ex_v']) ?></td>
                    <?php foreach ($BUCKETS as $bk => $_): $b = $s['b'][$bk]; ?>
                        <td class="<?= $b['n'] ? 'exv' : 'na' ?>"><?= $b['n'] ? number_format($b['n']) : '–' ?></td><td class="<?= $b['n'] ? 'exv' : 'na' ?>"><?= $b['n'] ? cas_money($b['v']) : '–' ?></td>
                    <?php endforeach; ?>
                <?php else: ?>
                    <td class="na" colspan="<?= 2 + count($BUCKETS) * 2 ?>">Normal — cash customers are not tested against the credit policy</td>
                <?php endif; ?>
                <td class="c view noprint"><a href="credit_aging_report.php?as_at_date=<?= cas_h($f_as_at) ?>&amp;<?= $g['view'] ?><?= $f_route !== '' ? '&amp;route=' . urlencode($f_route) : '' ?><?= $f_sr !== '' ? '&amp;sr_code=' . urlencode($f_sr) : '' ?>">View bills →</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="tot">
                <td class="l">TOTAL</td><td></td><td class="c"><?= number_format(count($ac)) ?></td>
                <td><?= number_format($T['n']) ?></td><td><?= cas_money($T['v']) ?></td>
                <td><?= number_format($T['ok_n']) ?></td><td><?= cas_money($T['ok_v']) ?></td>
                <td><?= number_format($T['ex_n']) ?></td><td><?= cas_money($T['ex_v']) ?></td>
                <?php foreach ($BUCKETS as $bk => $_): $bn = 0; $bv = 0; foreach ($S as $s) { $bn += $s['b'][$bk]['n']; $bv += $s['b'][$bk]['v']; } ?>
                    <td><?= $bn ? number_format($bn) : '–' ?></td><td><?= $bn ? cas_money($bv) : '–' ?></td>
                <?php endforeach; ?>
                <td class="noprint"></td>
            </tr>
        </tfoot>
    </table>
    </div>
    <p class="note">Exceeded = as-at date − (delivery date + customer credit policy days) &gt; 0, the same "Policy Aging" as the Credit Aging Report. “Cheques will delay” customers are counted only in their own row (not under Cheque customers). Customers whose pay mode is not set are listed separately so every outstanding bill is counted once.</p>
</div>
<?php include 'footer.php'; ?>
