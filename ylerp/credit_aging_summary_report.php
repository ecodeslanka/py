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
 *   4. Cash customers              → EVERY outstanding bill is a policy exceed (a cash customer should owe nothing)
 *
 * Click a set row → modal with that set's invoices and details.
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
    'cash'   => ['label' => 'Cash customers',            'exceed' => 'Cash policy exceeded',           'icon' => 'fa-money-bill-wave', 'color' => '#15803d', 'bg' => '#f0fdf4', 'view' => 'pay_mode=cash',   'test' => true],
];
$S = [];
foreach ($GROUPS as $k => $g) $S[$k] = ['n' => 0, 'v' => 0.0, 'ok_n' => 0, 'ok_v' => 0.0, 'ex_n' => 0, 'ex_v' => 0.0, 'customers' => [], 'ex_customers' => [],
    'cx_c' => 0, 'cx_n' => 0, 'cx_v' => 0.0,     /* customers EXCEEDED   : customers, their bills, their value */
    'nx_c' => 0, 'nx_n' => 0, 'nx_v' => 0.0];    /* customers NOT exceeded: customers, their bills, their value */
$CUST = [];   // t_code => customer row (customer-wise list)

/* ── data: every outstanding credit bill as at the date ── */
$where = ["fsd.updated = 1", "fs.delivery_date <= '" . mysqli_real_escape_string($conn, $f_as_at) . "'"];
if ($f_route !== '') $where[] = "fs.route = '" . mysqli_real_escape_string($conn, $f_route) . "'";
if ($f_sr !== '')    $where[] = "fs.sr_code = '" . mysqli_real_escape_string($conn, $f_sr) . "'";
$P = rx_inv_parts($conn, $f_as_at);
$sql = "
SELECT fs.delivery_date, fsd.invoice_num, fs.route, fs.sr_code, fsd.t_code, COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS customer_name, {$P['balance']} AS balance,
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
    else                                continue;      // pay mode not set → not part of this report
    if ($k === 'cheque' || $k === 'cwd') continue;     // the CHEQUE side comes from the cheque aging (below), not from the bills
    $del = $r['delivery_date'] ? strtotime($r['delivery_date']) : $as_at_ts;
    $over = (int)floor(($as_at_ts - ($del + (int)$r['credit_days'] * 86400)) / 86400);   // days past the policy due date
    $tc = (string)$r['t_code'];
    if (!isset($CUST[$tc])) $CUST[$tc] = ['t_code' => $tc, 'name' => $r['customer_name'], 'set' => $k, 'days' => (int)$r['credit_days'], 'route' => $r['route'], 'sr' => $r['sr_code'],
                                          'n' => 0, 'v' => 0.0, 'ex_n' => 0, 'ex_v' => 0.0, 'max_over' => 0, 'oldest' => $r['delivery_date'], 'bills' => []];
    $cu = &$CUST[$tc];
    /* Cash customers should not owe anything: EVERY outstanding bill of a cash customer is a policy exceed (whatever its age). */
    if ($k === 'cash') $over = max($over, (int)floor(($as_at_ts - $del) / 86400));        // days since delivery
    $exceeded = ($k === 'cash') ? true : ($GROUPS[$k]['test'] && $over > 0);
    $cu['n']++; $cu['v'] += $bal;
    if ($r['delivery_date'] && ($cu['oldest'] === null || $r['delivery_date'] < $cu['oldest'])) $cu['oldest'] = $r['delivery_date'];
    if ($exceeded) { $cu['ex_n']++; $cu['ex_v'] += $bal; if ($over > $cu['max_over']) $cu['max_over'] = $over; }
    $cu['bills'][] = ['inv' => (string)$r['invoice_num'], 'del' => $r['delivery_date'], 'due' => date('Y-m-d', $del + (int)$r['credit_days'] * 86400),
                      'bal' => round($bal, 2), 'over' => $over, 'ex' => $exceeded ? 1 : 0,
                      'route' => (string)$r['route'], 'sr' => (string)$r['sr_code'], 'days' => (int)$r['credit_days']];
    unset($cu);
}

/* ── CHEQUE side — taken from the Cheque Aging Report ──
   Unsettled cheques of cheque customers (cleared and fully-settled returned / sent-back cheques are left out).
   Cheque age = cheque date − delivery date;  CHEQUE POLICY EXCEEDED when the age is more than the customer's policy days
   (the same rule the Cheque Aging Report paints red).  The credit side above stays bill / credit-policy based. */
$cw = ["fs.delivery_date IS NOT NULL", "fs.delivery_date != '0000-00-00'", "fs.delivery_date <= '" . mysqli_real_escape_string($conn, $f_as_at) . "'",
       "ch.status != 'cleared'", "NOT (ch.status = 'sent_back' AND COALESCE(ch.sb_settled,0) = 1)", "NOT (ch.status = 'returned' AND COALESCE(ch.return_settled,0) = 1)",
       "LOWER(TRIM(COALESCE(c.payment_mode,''))) = 'cheque'"];
if ($f_route !== '') $cw[] = "fs.route = '" . mysqli_real_escape_string($conn, $f_route) . "'";
if ($f_sr !== '')    $cw[] = "fs.sr_code = '" . mysqli_real_escape_string($conn, $f_sr) . "'";
$cres = mysqli_query($conn, "
SELECT ch.cheque_no, ch.cheque_date, ch.total_amount, ch.status, ch.bank_code, ch.t_code, fs.delivery_date, fs.route, fs.sr_code,
       COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) AS customer_name, COALESCE(c.credit_days, 0) AS credit_days,
       " . ($HAS_CWD ? "COALESCE(c.cheques_will_delay,0)" : "0") . " AS cwd,
       COALESCE(ch.sb_settlement_amount,0) AS sb_paid, COALESCE(ch.settlement_amount,0) AS ret_paid,
       DATEDIFF(ch.cheque_date, fs.delivery_date) AS age_days
FROM cheques ch
INNER JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id
INNER JOIN field_summary fs ON fs.id = ip.field_summary_id
LEFT  JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
LEFT  JOIN customers c ON c.t_code = ch.t_code
WHERE " . implode(' AND ', $cw));
if ($cres) while ($r = mysqli_fetch_assoc($cres)) {
    $face = (float)$r['total_amount']; $st = strtolower(trim((string)$r['status']));
    $amt = ($st === 'sent_back') ? max(0, $face - (float)$r['sb_paid']) : (($st === 'returned') ? max(0, $face - (float)$r['ret_paid']) : $face);
    if ($amt <= 0.004) continue;
    $k = ((int)$r['cwd'] === 1) ? 'cwd' : 'cheque';
    if (!isset($GROUPS[$k])) continue;      // the cheque / cheques-will-delay sets are not shown in this report
    $days = (int)$r['credit_days']; $age = ($r['age_days'] !== null) ? (int)$r['age_days'] : 0;
    $exceeded = ($days > 0 && $age > $days);
    $over = $age - $days;                                              // days the cheque date is beyond the policy
    $del = $r['delivery_date'] ? strtotime($r['delivery_date']) : $as_at_ts;
    $tc = (string)$r['t_code'];
    if (!isset($CUST[$tc])) $CUST[$tc] = ['t_code' => $tc, 'name' => $r['customer_name'], 'set' => $k, 'days' => $days, 'route' => $r['route'], 'sr' => $r['sr_code'],
                                          'n' => 0, 'v' => 0.0, 'ex_n' => 0, 'ex_v' => 0.0, 'max_over' => 0, 'oldest' => $r['delivery_date'], 'bills' => []];
    $cu = &$CUST[$tc];
    $cu['n']++; $cu['v'] += $amt;
    if ($r['delivery_date'] && ($cu['oldest'] === null || $r['delivery_date'] < $cu['oldest'])) $cu['oldest'] = $r['delivery_date'];
    if ($exceeded) { $cu['ex_n']++; $cu['ex_v'] += $amt; if ($over > $cu['max_over']) $cu['max_over'] = $over; }
    $cu['bills'][] = ['inv' => (string)$r['cheque_no'], 'del' => $r['delivery_date'], 'due' => date('Y-m-d', $del + $days * 86400), 'bal' => round($amt, 2), 'over' => $over, 'ex' => $exceeded ? 1 : 0,
                      'route' => (string)$r['route'], 'sr' => (string)$r['sr_code'], 'days' => $days,
                      'chq' => 1, 'cdate' => $r['cheque_date'], 'age' => $age, 'bank' => (string)$r['bank_code'], 'cst' => $st];
    unset($cu);
}

/* ── exceeded filter (applies to the summary, the customer list and the Excel) ──
   all  = every customer
   with = customers who have AT LEAST ONE bill past policy
   only = customers whose bills are ALL past policy
   none = customers with NO bill past policy (within policy / normal) */
$f_ex = in_array($_GET['exceed'] ?? 'all', ['all', 'with', 'only', 'none'], true) ? ($_GET['exceed'] ?? 'all') : 'all';
$CUST = array_filter($CUST, function ($c) use ($f_ex) {
    if ($f_ex === 'with') return $c['ex_n'] > 0;
    if ($f_ex === 'only') return $c['ex_n'] > 0 && $c['ex_n'] === $c['n'];
    if ($f_ex === 'none') return $c['ex_n'] === 0;
    return true;
});
/* customer list: exceeded customers first (largest exceeded value), then the rest by value */
uasort($CUST, function ($a, $b) {
    if (($a['ex_v'] > 0) !== ($b['ex_v'] > 0)) return ($a['ex_v'] > 0) ? -1 : 1;
    return $b['ex_v'] <=> $a['ex_v'] ?: $b['v'] <=> $a['v'];
});
/* summary by set, built from the customers that passed the filter */
foreach ($CUST as $tc => $c) {
    $s = &$S[$c['set']];
    $s['n'] += $c['n']; $s['v'] += $c['v']; $s['customers'][$tc] = 1;
    $s['ex_n'] += $c['ex_n']; $s['ex_v'] += $c['ex_v']; $s['ok_n'] += $c['n'] - $c['ex_n']; $s['ok_v'] += $c['v'] - $c['ex_v'];
    if ($c['ex_n'] > 0) { $s['ex_customers'][$tc] = 1; $s['cx_c']++; $s['cx_n'] += $c['n']; $s['cx_v'] += $c['v']; }
    else                { $s['nx_c']++; $s['nx_n'] += $c['n']; $s['nx_v'] += $c['v']; }
    unset($s);
}
/* invoice rows per set (for the modal and the Excel "Invoices" sheet) */
$SETBILLS = [];
foreach ($CUST as $tc => $c) foreach ($c['bills'] as $b) $SETBILLS[$c['set']][] = ['t' => $tc, 'n' => $c['name']] + $b;
$T = ['n' => 0, 'v' => 0.0, 'ok_n' => 0, 'ok_v' => 0.0, 'ex_n' => 0, 'ex_v' => 0.0, 'cx_c' => 0, 'cx_n' => 0, 'cx_v' => 0.0, 'nx_c' => 0, 'nx_n' => 0, 'nx_v' => 0.0];
foreach ($S as $s) foreach (['n', 'v', 'ok_n', 'ok_v', 'ex_n', 'ex_v', 'cx_c', 'cx_n', 'cx_v', 'nx_c', 'nx_n', 'nx_v'] as $f) $T[$f] += $s[$f];
/* ═════════════ Excel export ═════════════ */
if (isset($_GET['export'])) {
    require_once __DIR__ . '/rbac_xlsx.php';
    if (!class_exists('ZipArchive')) { http_response_code(500); exit('The PHP zip extension is required for Excel export.'); }
    $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '134E4A', 'h' => 'center', 'border' => 1, 'wrap' => 1];
    $X = new MiniXlsx();

    /* Sheet 1 — summary by set */
    $sh = $X->sheet('Summary');
    $sh->set(1, 1, 'Credit Aging Summary Report — as at ' . date('d M Y', $as_at_ts), ['b' => 1, 'sz' => 16, 'color' => 'FFFFFF', 'bg' => 'B91C1C', 'indent' => 1]);
    for ($c = 2; $c <= 14; $c++) $sh->set(1, $c, '', ['bg' => 'B91C1C']);
    $sh->merge(1, 1, 1, 14); $sh->heights[1] = 30;
    $sh->set(2, 1, 'Outstanding credit bills · exceeded = past (delivery date + customer credit policy days) '
        . ($f_route !== '' ? ' · Route ' . $f_route : '') . ($f_sr !== '' ? ' · SR ' . $f_sr : ''), ['i' => 1, 'color' => '6B7280']);
    $sh->merge(2, 1, 2, 14);
    foreach (['Set', 'Policy test', 'Customers', 'Exceeded customers', 'Exceeded customers: bills', 'Exceeded customers: value (Rs.)', 'Non-exceeded customers', 'Non-exceeded customers: bills', 'Non-exceeded customers: value (Rs.)', 'Credit bills', 'Value (Rs.)', 'Normal bills', 'Normal value (Rs.)', 'Exceeded bills', 'Exceeded value (Rs.)'] as $i => $h) $sh->set(4, $i + 1, $h, $H);
    $r = 5;
    $line = function ($label, $test, $cn, $cex, $s, $bold = false, $bg = null, $na = false) use ($sh, &$r) {
        $st = ['border' => 1] + ($bold ? ['b' => 1] : []) + ($bg ? ['bg' => $bg] : []);
        $sh->set($r, 1, $label, $st + ['b' => 1]); $sh->set($r, 2, $test, $st);
        $sh->set($r, 3, $cn, $st + ['h' => 'center']);
        $vals = [$s['cx_c'], $s['cx_n'], round($s['cx_v'], 2), $s['nx_c'], $s['nx_n'], round($s['nx_v'], 2),
                 $s['n'], round($s['v'], 2), $s['ok_n'], round($s['ok_v'], 2), $s['ex_n'], round($s['ex_v'], 2)];
        foreach ($vals as $i => $v) $sh->set($r, 4 + $i, $v, $st + ['h' => 'right']);
        $r++;
    };
    $allc = []; $allex = [];
    foreach ($GROUPS as $k => $g) { $line($g['label'], $g['exceed'], count($S[$k]['customers']), count($S[$k]['ex_customers']), $S[$k], false, null, !$g['test']); $allc += $S[$k]['customers']; $allex += $S[$k]['ex_customers']; }
    $line('TOTAL', '', count($allc), count($allex), $T, true, 'FDE68A');
    foreach ([30, 30, 11, 13, 13, 20, 14, 14, 20, 11, 18, 11, 18, 11, 18] as $i => $w) $sh->widths[$i + 1] = $w;
    $sh->freeze = [4, 0];

    /* Sheet 2 — invoice wise (every bill, with its set and status) */
    $bs = $X->sheet('Invoices');
    foreach (['Set', 'T code', 'Customer', 'Invoice / Cheque no', 'Route', 'SR', 'Delivery date', 'Policy days', 'Policy due date', 'Days over policy', 'Status', 'Balance / Cheque amount (Rs.)', 'Cheque date', 'Cheque age (days)', 'Bank', 'Cheque status'] as $i => $h) $bs->set(1, $i + 1, $h, $H);
    $r = 2;
    foreach ($SETBILLS as $k => $list) foreach ($list as $b) {
        $ex = (bool)$b['ex']; $bg = $ex ? ['bg' => 'FEF2F2'] : [];
        $vals = [$GROUPS[$k]['label'], $b['t'], $b['n'], $b['inv'], $b['route'], $b['sr'], $b['del'] ? date('d M Y', strtotime($b['del'])) : '', $b['days'],
                 $GROUPS[$k]['test'] ? date('d M Y', strtotime($b['due'])) : '', $GROUPS[$k]['test'] ? $b['over'] : '', !$GROUPS[$k]['test'] ? 'Normal' : ($ex ? 'Exceeded' : 'Within policy'), $b['bal'],
                 !empty($b['chq']) && $b['cdate'] ? date('d M Y', strtotime($b['cdate'])) : '', !empty($b['chq']) ? $b['age'] : '', !empty($b['chq']) ? $b['bank'] : '', !empty($b['chq']) ? ucwords(str_replace('_', ' ', (string)$b['cst'])) : ''];
        foreach ($vals as $i => $v) $bs->set($r, $i + 1, $v, ['border' => 1] + $bg + ($i === 10 ? ['b' => 1, 'color' => $ex ? 'B91C1C' : '15803D', 'h' => 'center'] : []) + ($i === 11 ? ['h' => 'right'] : []));
        $r++;
    }
    foreach ([26, 11, 34, 18, 12, 10, 14, 11, 15, 14, 14, 20, 14, 12, 10, 16] as $i => $w) $bs->widths[$i + 1] = $w;
    $bs->freeze = [1, 0]; $bs->filter = 'A1:P' . max(2, $r - 1);

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
$ac = []; foreach ($S as $s) $ac += $s['customers'];
$ex_c = []; foreach ($S as $s) $ex_c += $s['ex_customers'];
/* data for the modal: one entry per set */
$MODAL = [];
foreach ($GROUPS as $k => $g) $MODAL[] = ['k' => $k, 'g' => ['label' => $g['label'], 'exceed' => $g['exceed'], 'test' => $g['test'], 'color' => $g['color'], 'chq' => !empty($g['unit'])], 's' => [
    'n' => $S[$k]['n'], 'v' => round($S[$k]['v'], 2), 'ex_n' => $S[$k]['ex_n'], 'ex_v' => round($S[$k]['ex_v'], 2), 'cust' => count($S[$k]['customers'])], 'bills' => $SETBILLS[$k] ?? []];
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
.cas input[type=date], .cas select { height:36px; padding:0 10px; border:1px solid #d1d5db; border-radius:8px; font:inherit; background:#fff; color:var(--ink); min-width:150px; }
.cas .btn { display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 16px; border-radius:8px; border:1px solid transparent; background:#111827; color:#fff; font:inherit; font-weight:700; cursor:pointer; text-decoration:none; }
.cas .btn.light { background:#fff; color:var(--ink); border-color:#d1d5db; } .cas .btn.xl { background:#14532d; } .cas .btn.pr { background:#1e3a5f; }
.cas .kpis { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-bottom:14px; }
.cas .kpi { background:#fff; border:1px solid var(--line); border-radius:12px; padding:14px 16px; position:relative; overflow:hidden; }
.cas .kpi::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:var(--c,#111827); }
.cas .kpi .l { font-size:10.5px; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; }
.cas .kpi .n { font-size:26px; font-weight:800; line-height:1.15; margin-top:3px; } .cas .kpi .v { font-size:14px; font-weight:700; color:#374151; font-variant-numeric:tabular-nums; } .cas .kpi .p { font-size:11px; color:var(--muted); }
.cas .wrap { overflow-x:auto; } .cas table.sumt { width:100%; border-collapse:collapse; }
.cas .sumt th { padding:9px 10px; background:#134e4a; color:#fff; font-size:11px; font-weight:700; text-align:center; border:1px solid #0f3f3b; white-space:nowrap; }
.cas .sumt th.l { text-align:left; } .cas .sumt th.sub2 { background:#1f6f69; font-size:10.5px; }
.cas .sumt th.ex { background:#b91c1c; border-color:#991b1b; } .cas .sumt th.ex2 { background:#dc2626; font-size:10.5px; border-color:#991b1b; } .cas .sumt th.ok { background:#15803d; border-color:#166534; } .cas .sumt th.ok2 { background:#16a34a; font-size:10.5px; border-color:#166534; }
.cas .sumt td { padding:12px 10px; border:1px solid #eef0f3; text-align:right; font-variant-numeric:tabular-nums; vertical-align:middle; } .cas .sumt td.l { text-align:left; } .cas .sumt td.c { text-align:center; }
.cas .sumt tbody tr.clk { cursor:pointer; } .cas .sumt tbody tr.clk:hover td { background:#eef6ff; }
.cas .gname { display:flex; align-items:center; gap:10px; font-weight:800; } .cas .gname i { width:30px; height:30px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:13px; flex-shrink:0; }
.cas .ptest { display:inline-block; padding:2px 10px; border-radius:99px; font-size:11px; font-weight:700; border:1px solid; white-space:nowrap; }
.cas .ptest.ex { color:#b91c1c; background:#fef2f2; border-color:#fecaca; } .cas .ptest.normal { color:#15803d; background:#f0fdf4; border-color:#bbf7d0; }
.cas .sumt td.exv { background:#fff5f5; font-weight:700; color:#b91c1c; } .cas .sumt td.okv { background:#f6fef9; color:#15803d; font-weight:600; } .cas .sumt td.na { color:#9ca3af; text-align:center; }
.cas .sumt tr.tot td { background:#fef3c7; font-weight:800; border-top:2px solid #f59e0b; }
.cas .hintrow { color:var(--muted); font-size:12px; margin:2px 0 10px; display:flex; align-items:center; gap:6px; }
.cas .note { color:var(--muted); font-size:12px; margin-top:8px; line-height:1.6; }
@media (max-width:1000px) { .cas .kpis { grid-template-columns:1fr 1fr; } .cas { padding:16px 12px 40px; } }
@media print { .cas form.flt, .cas .noprint, .main-header, #sidebar, .sidebar { display:none !important; } .cas { padding:0; background:#fff; } }
</style>

<div class="cas">
    <h1><span class="ic"><i class="fa-solid fa-chart-pie"></i></span> Credit Aging Summary Report <span class="asat"><i class="fa-regular fa-calendar"></i> As at <?= cas_h(date('d M Y', $as_at_ts)) ?></span></h1>
    <p class="sub">Outstanding <b>credit bills</b> (count and value) as at the chosen date, by the way each customer pays. A bill is <b style="color:#b91c1c">exceeded</b> when it is past its delivery date + the customer's credit policy days.
        <b>Credit</b>, <b>Cheque (only)</b>, <b>Cheques will delay</b> and <b>Cash</b> customers each show how much exceeded their policy.</p>

    <form class="card flt" method="get" action="credit_aging_summary_report.php">
        <div class="fld"><label for="asAt">As at date</label><input type="date" id="asAt" name="as_at_date" value="<?= cas_h($f_as_at) ?>" required></div>
        <div class="fld"><label for="rt">Route</label><select id="rt" name="route"><option value="">All routes</option>
            <?php foreach ($all_routes as $r): ?><option value="<?= cas_h($r['route_code']) ?>"<?= $f_route === $r['route_code'] ? ' selected' : '' ?>><?= cas_h($r['route_name']) ?></option><?php endforeach; ?></select></div>
        <div class="fld"><label for="sr">SR code</label><select id="sr" name="sr_code"><option value="">All SR</option>
            <?php foreach ($all_sr as $c): ?><option value="<?= cas_h($c) ?>"<?= $f_sr === $c ? ' selected' : '' ?>><?= cas_h($c) ?></option><?php endforeach; ?></select></div>
        <div class="fld"><label for="ex">Show customers</label><select id="ex" name="exceed" style="min-width:230px">
            <?php foreach (['all' => 'All customers', 'with' => 'With exceeded (any bill past policy)', 'only' => 'Only exceeded (all bills past policy)', 'none' => 'Not exceeded (within policy)'] as $k => $l): ?><option value="<?= $k ?>"<?= $f_ex === $k ? ' selected' : '' ?>><?= cas_h($l) ?></option><?php endforeach; ?></select></div>
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

    <div class="hintrow noprint"><i class="fa-solid fa-hand-pointer"></i> Click a row to see its invoices and details.</div>
    <div class="card wrap">
    <table class="sumt">
        <thead>
            <tr>
                <th class="l" rowspan="2">Set</th><th rowspan="2">Policy test</th><th rowspan="2">Customers</th>
                <th colspan="3" class="ex">Customers exceeded</th><th colspan="3" class="ok">Customers not exceeded</th>
                <th colspan="2">All credit bills</th><th colspan="2" class="ok">Normal / within policy</th><th colspan="2" class="ex">Policy exceeded</th>
            </tr>
            <tr><th class="ex2">Customers</th><th class="ex2">Bills / Chq</th><th class="ex2">Value (Rs.)</th><th class="ok2">Customers</th><th class="ok2">Bills / Chq</th><th class="ok2">Value (Rs.)</th>
                <th class="sub2">Bills / Chq</th><th class="sub2">Value (Rs.)</th><th class="ok2">Bills / Chq</th><th class="ok2">Value (Rs.)</th><th class="ex2">Bills / Chq</th><th class="ex2">Value (Rs.)</th></tr>
        </thead>
        <tbody>
        <?php $mi = 0; foreach ($GROUPS as $k => $g): $s = $S[$k]; ?>
            <tr class="clk" data-i="<?= $mi++ ?>" title="Click to see the invoices">
                <td class="l"><div class="gname"><i class="fa-solid <?= $g['icon'] ?>" style="background:<?= $g['bg'] ?>;color:<?= $g['color'] ?>"></i><span><?= cas_h($g['label']) ?><?php if (!empty($g['unit'])): ?><small style="display:block;font-weight:600;color:#7c3aed;font-size:10.5px">by cheque aging · cheques</small><?php else: ?><small style="display:block;font-weight:600;color:#6b7280;font-size:10.5px">by bills · credit policy</small><?php endif; ?></span></div></td>
                <td class="c"><span class="ptest <?= $g['test'] ? 'ex' : 'normal' ?>"><?= cas_h($g['exceed']) ?></span></td>
                <td class="c"><?= number_format(count($s['customers'])) ?></td>
                <td class="c <?= $s['cx_c'] ? 'exv' : 'na' ?>"><?= number_format($s['cx_c']) ?></td><td class="<?= $s['cx_c'] ? 'exv' : 'na' ?>"><?= number_format($s['cx_n']) ?></td><td class="<?= $s['cx_c'] ? 'exv' : 'na' ?>"><?= cas_money($s['cx_v']) ?></td>
                <td class="c <?= $s['nx_c'] ? 'okv' : 'na' ?>"><?= number_format($s['nx_c']) ?></td><td class="<?= $s['nx_c'] ? 'okv' : 'na' ?>"><?= number_format($s['nx_n']) ?></td><td class="<?= $s['nx_c'] ? 'okv' : 'na' ?>"><?= cas_money($s['nx_v']) ?></td>
                <td><?= number_format($s['n']) ?></td><td><?= cas_money($s['v']) ?></td>
                <td class="okv"><?= number_format($s['ok_n']) ?></td><td class="okv"><?= cas_money($s['ok_v']) ?></td>
                <?php if ($g['test']): ?><td class="exv"><?= number_format($s['ex_n']) ?></td><td class="exv"><?= cas_money($s['ex_v']) ?></td>
                <?php else: ?><td class="na" colspan="2">Normal</td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="tot">
            <td class="l">TOTAL</td><td></td><td class="c"><?= number_format(count($ac)) ?></td>
            <td class="c"><?= number_format($T['cx_c']) ?></td><td><?= number_format($T['cx_n']) ?></td><td><?= cas_money($T['cx_v']) ?></td>
            <td class="c"><?= number_format($T['nx_c']) ?></td><td><?= number_format($T['nx_n']) ?></td><td><?= cas_money($T['nx_v']) ?></td>
            <td><?= number_format($T['n']) ?></td><td><?= cas_money($T['v']) ?></td><td><?= number_format($T['ok_n']) ?></td><td><?= cas_money($T['ok_v']) ?></td>
            <td><?= number_format($T['ex_n']) ?></td><td><?= cas_money($T['ex_v']) ?></td>
        </tr></tfoot>
    </table>
    </div>
    <p class="note">Exceeded = as-at date − (delivery date + customer credit policy days) &gt; 0, the same “Policy Aging” as the Credit Aging Report. Cash customers: every outstanding bill is a policy exceed. Cheque and “Cheques will delay” customers are not part of this report (see the Cheque Aging Report). Customers with no pay mode set are not part of this report.</p>
</div>

<!-- invoices viewer: moved to <body> by the script so it always sits above the site header / sidebar -->
<div class="casm" id="casm" role="dialog" aria-modal="true" aria-label="Invoices">
    <div class="box">
        <div class="top">
            <div><div class="ttl" id="mName">Set</div><div class="sb" id="mSub"></div></div>
            <span class="cnt" id="mCnt"></span><span class="sp"></span>
            <button class="nb" id="mPrev" type="button">◀ Previous</button><button class="nb" id="mNext" type="button">Next ▶</button>
            <button class="x" id="mClose" type="button" aria-label="Close">&times;</button>
        </div>
        <div class="sum" id="mSum"></div>
        <div class="bar">
            <div class="tabs" id="mTabs"><button type="button" data-f="all" class="on">All</button><button type="button" data-f="ex">Exceeded</button><button type="button" data-f="ok">Within policy</button></div>
            <input type="search" id="mQ" placeholder="Search customer, T code, invoice or cheque no">
            <span class="hint" id="mShown"></span>
        </div>
        <div class="tw"><table><thead id="mHead"></thead><tbody id="mBills"></tbody></table></div>
        <div class="foot"><a id="mOpen" class="btn" target="_blank" rel="noopener"><i class="fa-solid fa-up-right-from-square"></i> Open in Credit Aging Report</a><span class="hint">← → previous / next set · Esc closes</span></div>
    </div>
</div>
<style>
.casm { display:none; position:fixed; inset:0; z-index:2147483000; background:rgba(15,23,42,.8); backdrop-filter:blur(3px); padding:2vh 2vw; font-family:'Inter',system-ui,sans-serif; font-size:13px; color:#111827; }
.casm.open { display:block; } .casm *, .casm *::before, .casm *::after { box-sizing:border-box; }
.casm .box { background:#fff; border-radius:16px; width:100%; max-width:1300px; height:100%; margin:0 auto; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 24px 80px rgba(0,0,0,.55); }
.casm .top { display:flex; align-items:center; gap:12px; padding:14px 20px; background:linear-gradient(90deg,#134e4a,#0f766e); color:#fff; flex-shrink:0; }
.casm .ttl { font-size:18px; font-weight:800; } .casm .sb { font-size:12px; opacity:.85; margin-top:2px; } .casm .cnt { background:rgba(255,255,255,.2); border-radius:99px; padding:3px 14px; font-weight:800; font-size:12.5px; } .casm .sp { flex:1; }
.casm .nb { height:36px; padding:0 16px; border:1px solid rgba(255,255,255,.5); border-radius:9px; background:rgba(255,255,255,.15); color:#fff; font:inherit; font-weight:700; cursor:pointer; } .casm .nb:hover:not(:disabled) { background:rgba(255,255,255,.3); } .casm .nb:disabled { opacity:.4; cursor:default; }
.casm .x { width:36px; height:36px; border-radius:50%; border:none; background:#dc2626; color:#fff; font-size:20px; cursor:pointer; line-height:1; }
.casm .sum { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; padding:14px 20px; background:#f8fafc; border-bottom:1px solid #e5e7eb; flex-shrink:0; }
.casm .sum > div { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:9px 12px; } .casm .sum .l { font-size:10px; font-weight:800; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; } .casm .sum .v { font-size:17px; font-weight:800; margin-top:2px; font-variant-numeric:tabular-nums; }
.casm .bar { display:flex; align-items:center; gap:12px; padding:10px 20px; border-bottom:1px solid #e5e7eb; flex-shrink:0; flex-wrap:wrap; }
.casm .tabs { display:flex; gap:6px; } .casm .tabs button { padding:6px 14px; border-radius:99px; border:1px solid #e5e7eb; background:#fff; font:inherit; font-weight:700; font-size:12px; cursor:pointer; color:#374151; } .casm .tabs button.on { background:#111827; border-color:#111827; color:#fff; }
.casm .bar input { height:34px; padding:0 12px; border:1px solid #d1d5db; border-radius:8px; font:inherit; min-width:260px; }
.casm .tw { flex:1; min-height:0; overflow:auto; } .casm table { width:100%; border-collapse:collapse; }
.casm th { position:sticky; top:0; padding:9px 12px; background:#134e4a; color:#fff; font-size:11px; font-weight:700; text-align:center; white-space:nowrap; } .casm th.l { text-align:left; }
.casm td { padding:8px 12px; border-bottom:1px solid #eef0f3; text-align:center; font-variant-numeric:tabular-nums; white-space:nowrap; } .casm td.l { text-align:left; } .casm td.nm { text-align:left; font-weight:700; white-space:normal; min-width:200px; } .casm td.nm small { display:block; color:#6b7280; font-weight:600; } .casm td.r { text-align:right; font-weight:700; }
.casm tr.ex td { background:#fff5f5; } .casm .st { display:inline-block; padding:2px 10px; border-radius:99px; font-size:11px; font-weight:800; border:1px solid; } .casm .st.bad { color:#b91c1c; background:#fef2f2; border-color:#fecaca; } .casm .st.good { color:#15803d; background:#f0fdf4; border-color:#bbf7d0; }
.casm .foot { display:flex; align-items:center; gap:12px; padding:12px 20px; border-top:1px solid #e5e7eb; background:#f8fafc; flex-shrink:0; } .casm .foot .btn { display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 16px; border-radius:8px; background:#111827; color:#fff; font-weight:700; text-decoration:none; }
.casm .hint { margin-left:auto; color:#6b7280; font-size:11.5px; }
@media (max-width:760px) { .casm { padding:0; } .casm .box { border-radius:0; } .casm .sum { grid-template-columns:1fr 1fr; } .casm .hint { display:none; } .casm .bar input { min-width:0; flex:1; } }
</style>
<script>
(function(){
  var DATA = <?= json_encode($MODAL, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var AS_AT = <?= json_encode($f_as_at) ?>, ROUTE = <?= json_encode($f_route) ?>, SR = <?= json_encode($f_sr) ?>, cur = -1, filt = 'all';
  var $ = function(id){ return document.getElementById(id); };
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function money(v){ return Number(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function fd(d){ if(!d) return '—'; var t=new Date(String(d).replace(/-/g,'/')); return isNaN(t)?d:t.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}); }
  function rows(){
    var d=DATA[cur], q=$('mQ').value.trim().toLowerCase();
    return d.bills.filter(function(b){
      if(filt==='ex' && !b.ex) return false; if(filt==='ok' && b.ex) return false;
      return !q || (b.n+' '+b.t+' '+b.inv).toLowerCase().indexOf(q)>-1;
    }).sort(function(a,b){ return (b.over||0)-(a.over||0); });
  }
  function draw(){
    var d=DATA[cur], g=d.g, list=rows(), h='', tot=0, chq=!!g.chq;
    $('mHead').innerHTML = chq
      ? '<tr><th>#</th><th class="l">Customer</th><th class="l">Cheque no</th><th>Bank</th><th>Chq status</th><th>Route</th><th>SR</th><th>Delivery date</th><th>Cheque date</th><th>Age (days)</th><th>Policy days</th><th>Days over policy</th><th>Policy</th><th>Amount (Rs.)</th></tr>'
      : '<tr><th>#</th><th class="l">Customer</th><th class="l">Invoice</th><th>Route</th><th>SR</th><th>Delivery date</th><th>Policy days</th><th>Due date</th><th>Days over</th><th>Status</th><th>Balance (Rs.)</th></tr>';
    list.forEach(function(b,k){ tot+=b.bal;
      var nm='<td class="nm">'+esc(b.n)+'<small>'+esc(b.t)+'</small></td>', stc='<td><span class="st '+(b.ex?'bad':'good')+'">'+(!g.test?'Normal':(b.ex?'Exceeded':'Within policy'))+'</span></td>';
      if(chq){
        h+='<tr class="'+(b.ex?'ex':'')+'"><td>'+(k+1)+'</td>'+nm+'<td class="l"><b>'+esc(b.inv||'—')+'</b></td><td>'+esc(b.bank||'')+'</td><td style="text-transform:capitalize">'+esc(String(b.cst||'').replace('_',' '))+'</td><td>'+esc(b.route||'')+'</td><td>'+esc(b.sr||'')+'</td>'
          +'<td>'+fd(b.del)+'</td><td>'+fd(b.cdate)+'</td><td style="font-weight:700">'+(b.age==null?'—':b.age+'d')+'</td><td>'+(b.days>0?b.days+'d':'—')+'</td>'
          +'<td style="font-weight:800;color:'+(b.ex?'#b91c1c':'#6b7280')+'">'+(b.days>0?(b.over>0?'+'+b.over+'d':b.over+'d'):'—')+'</td>'+stc.replace('Within policy',b.days>0?'Within policy':'No policy')+'<td class="r">'+money(b.bal)+'</td></tr>';
      } else {
        h+='<tr class="'+(b.ex?'ex':'')+'"><td>'+(k+1)+'</td>'+nm+'<td class="l"><b>'+esc(b.inv||'—')+'</b></td><td>'+esc(b.route||'')+'</td><td>'+esc(b.sr||'')+'</td>'
          +'<td>'+fd(b.del)+'</td><td>'+(g.test?b.days+'d':'—')+'</td><td>'+(g.test?fd(b.due):'—')+'</td>'
          +'<td style="font-weight:800;color:'+(b.ex?'#b91c1c':'#6b7280')+'">'+(g.test?(b.over>0?'+'+b.over+'d':b.over+'d'):'—')+'</td>'+stc+'<td class="r">'+money(b.bal)+'</td></tr>';
      }
    });
    $('mBills').innerHTML=h||'<tr><td colspan="14" style="padding:28px;color:#6b7280">No '+(chq?'cheques':'invoices')+'</td></tr>';
    $('mShown').textContent=list.length+' '+(chq?'cheque':'invoice')+(list.length===1?'':'s')+' · Rs. '+money(tot);
  }
  function show(i){
    if(i<0||i>=DATA.length) return; cur=i; var d=DATA[i], g=d.g, s=d.s;
    $('mName').textContent=g.label+(g.chq?' — cheques (cheque aging)':' — invoices'); $('mSub').textContent=g.exceed+' · as at '+fd(AS_AT)+(ROUTE?' · route '+ROUTE:'')+(SR?' · SR '+SR:'');
    $('mCnt').textContent=(i+1)+' / '+DATA.length; $('mPrev').disabled=(i===0); $('mNext').disabled=(i===DATA.length-1);
    $('mSum').innerHTML='<div><div class="l">Customers</div><div class="v">'+s.cust+'</div></div><div><div class="l">'+(g.chq?'Unsettled cheques':'Credit bills')+'</div><div class="v">'+s.n+'</div></div>'
      +'<div><div class="l">Total value</div><div class="v">Rs. '+money(s.v)+'</div></div>'
      +'<div><div class="l">'+(g.test?(g.chq?'Exceeded cheques':'Exceeded bills'):'Status')+'</div><div class="v" style="color:#b91c1c">'+(g.test?s.ex_n:'<span style="color:#15803d">Normal</span>')+'</div></div>'
      +'<div><div class="l">'+(g.test?'Exceeded value':'Policy test')+'</div><div class="v" style="color:#b91c1c">'+(g.test?'Rs. '+money(s.ex_v):'<span style="color:#15803d;font-size:13px">not tested (cash)</span>')+'</div></div>';
    [].forEach.call($('mTabs').children,function(b){ b.style.display=(!g.test&&b.dataset.f!=='all')?'none':''; });
    filt='all'; [].forEach.call($('mTabs').children,function(b){ b.classList.toggle('on',b.dataset.f==='all'); });
    $('mQ').value='';
    var pm={credit:'credit',cheque:'cheque',cwd:'cwd',cash:'cash'}[d.k]||'';
    if(g.chq){ $('mOpen').href='cheque_aging_report.php?as_at='+encodeURIComponent(AS_AT)+'&pay_mode=cheque'+(SR?'&sr_code='+encodeURIComponent(SR):''); $('mOpen').innerHTML='<i class="fa-solid fa-up-right-from-square"></i> Open in Cheque Aging Report'; }
    else { $('mOpen').href='credit_aging_report.php?as_at_date='+encodeURIComponent(AS_AT)+'&pay_mode='+pm+(ROUTE?'&route='+encodeURIComponent(ROUTE):'')+(SR?'&sr_code='+encodeURIComponent(SR):''); $('mOpen').innerHTML='<i class="fa-solid fa-up-right-from-square"></i> Open in Credit Aging Report'; }
    draw();
  }
  function openAt(i){ $('casm').classList.add('open'); document.body.style.overflow='hidden'; show(i); }
  function closeM(){ $('casm').classList.remove('open'); document.body.style.overflow=''; }
  $('mClose').onclick=closeM; $('mPrev').onclick=function(){ show(cur-1); }; $('mNext').onclick=function(){ show(cur+1); };
  $('mTabs').addEventListener('click',function(e){ var b=e.target.closest('button'); if(!b) return; filt=b.dataset.f; [].forEach.call($('mTabs').children,function(x){ x.classList.toggle('on',x===b); }); draw(); });
  $('mQ').addEventListener('input',draw);
  $('casm').addEventListener('mousedown',function(e){ if(e.target===$('casm')) closeM(); });
  document.addEventListener('keydown',function(e){ if(!$('casm').classList.contains('open')) return;
    if(e.key==='Escape') closeM(); else if(document.activeElement===$('mQ')) return;
    else if(e.key==='ArrowLeft'){ e.preventDefault(); show(cur-1); } else if(e.key==='ArrowRight'){ e.preventDefault(); show(cur+1); } });
  [].forEach.call(document.querySelectorAll('.sumt tbody tr.clk'),function(tr){ tr.addEventListener('click',function(){ openAt(parseInt(tr.dataset.i,10)); }); });
  document.body.appendChild($('casm'));     // out of the page wrapper: always above the site header & sidebar
})();
</script>
<?php include 'footer.php'; ?>
