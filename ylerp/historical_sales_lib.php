<?php
/**
 * historical_sales_lib.php — shared by Historical Sales Trends and its Settings page.
 * SALES per outlet (HUL code = T-Code) per company cycle ("sales month"):
 *   • bills BEFORE the cut-over date  → Analysis ▸ Import Secondary Invoices   (analysis_sec_invoices, LeverEDGE Invoice Wise Sales)
 *   • bills FROM the cut-over date    → our own Secondary Invoice import       (secondary_invoice_import_details)
 *   • sales value of a bill = Total Discount + Final Bill Amount (to be collected)      [setting: or Bill Value + Total Discount]
 *   • cycle: with start day 26, "Nov-25" = 26-Oct-2025 … 25-Nov-2025 (settings page can change it).
 */
function hs_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function hs_has($conn, $t) { $r = hs_q($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'"); return $r && mysqli_num_rows($r) > 0; }

function hs_defaults() { return ['start_day' => 26, 'first_cycle' => '2025-11', 'months' => 12, 'cutover' => '2026-10-26', 'basis' => 'final', 'w12' => 0.20, 'w6' => 0.30, 'w3' => 0.35, 'wl' => 0.15]; }
function hs_settings($conn) {
    hs_q($conn, "CREATE TABLE IF NOT EXISTS analysis_sales_settings (id TINYINT PRIMARY KEY, start_day TINYINT NOT NULL DEFAULT 26, first_cycle CHAR(7) NOT NULL DEFAULT '2025-11', months TINYINT NOT NULL DEFAULT 12,
        cutover DATE NULL, basis VARCHAR(10) NOT NULL DEFAULT 'final', w12 DECIMAL(5,3) DEFAULT 0.200, w6 DECIMAL(5,3) DEFAULT 0.300, w3 DECIMAL(5,3) DEFAULT 0.350, wl DECIMAL(5,3) DEFAULT 0.150,
        updated_by VARCHAR(100), updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $s = hs_defaults(); $r = hs_q($conn, "SELECT * FROM analysis_sales_settings WHERE id = 1");
    if ($r && ($x = mysqli_fetch_assoc($r))) { foreach (['start_day', 'months'] as $k) $s[$k] = (int)$x[$k]; foreach (['w12', 'w6', 'w3', 'wl'] as $k) $s[$k] = (float)$x[$k]; $s['first_cycle'] = $x['first_cycle']; $s['cutover'] = $x['cutover'] ?: $s['cutover']; $s['basis'] = $x['basis']; $s['updated_by'] = $x['updated_by']; $s['updated_at'] = $x['updated_at']; }
    return $s;
}
function hs_save($conn, array $in, $user) {
    $d = hs_defaults(); $e = fn($v) => mysqli_real_escape_string($conn, (string)$v);
    $sd = max(1, min(28, (int)($in['start_day'] ?? $d['start_day'])));
    $fc = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($in['first_cycle'] ?? '')) ? $in['first_cycle'] : $d['first_cycle'];
    $mo = max(3, min(24, (int)($in['months'] ?? $d['months'])));
    $co = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['cutover'] ?? '')) ? $in['cutover'] : $d['cutover'];
    $ba = ($in['basis'] ?? '') === 'billvalue' ? 'billvalue' : 'final';
    $w = []; foreach (['w12', 'w6', 'w3', 'wl'] as $k) $w[$k] = max(0, min(1, (float)($in[$k] ?? $d[$k])));
    hs_q($conn, "REPLACE INTO analysis_sales_settings (id, start_day, first_cycle, months, cutover, basis, w12, w6, w3, wl, updated_by) VALUES (1, $sd, '$fc', $mo, '$co', '$ba', {$w['w12']}, {$w['w6']}, {$w['w3']}, {$w['wl']}, '{$e($user)}')");
}
/** 'YYYY-MM' + n months */
function hs_add($ym, $n) { $t = strtotime($ym . '-01 +' . $n . ' month'); return date('Y-m', $t); }
function hs_label($ym) { return date('M-y', strtotime($ym . '-01')); }
/** [from, to] dates of cycle $ym for start day $sd */
function hs_range($ym, $sd) {
    if ($sd <= 1) return [$ym . '-01', date('Y-m-t', strtotime($ym . '-01'))];
    return [date('Y-m', strtotime($ym . '-01 -1 month')) . '-' . sprintf('%02d', $sd), $ym . '-' . sprintf('%02d', $sd - 1)];
}
/** SQL: 'YYYY-MM' cycle label of a date expression */
function hs_cyc_sql($col, $sd) { return $sd <= 1 ? "DATE_FORMAT($col,'%Y-%m')" : "DATE_FORMAT(DATE_ADD($col, INTERVAL (CASE WHEN DAY($col) >= $sd THEN 1 ELSE 0 END) MONTH),'%Y-%m')"; }

/** whole report: ['months'=>[ym..], 'extra'=>ym|null, 'rows'=>[...], 'tot'=>[ym=>sum], 'range'=>[from,to], 'src'=>[...], 'last_date'=>...] */
function hs_build($conn, array $S) {
    $sd = $S['start_day']; $months = []; for ($i = 0; $i < $S['months']; $i++) $months[] = hs_add($S['first_cycle'], $i);
    $extraYm = hs_add($S['first_cycle'], $S['months']); $firstFrom = hs_range($months[0], $sd)[0];
    $e = fn($v) => mysqli_real_escape_string($conn, (string)$v);
    $fin = $S['basis'] === 'billvalue' ? '(bill_value + total_discount)' : '(total_discount + final_bill_amount)';
    $cells = []; $cnts = []; $info = []; $src = ['analysis' => 0, 'system' => 0]; $lastDate = null; $spName = [];
    $cy = hs_cyc_sql('bill_date', $sd);
    $maxYm = $extraYm;
    /* 1) Analysis import — bills before the cut-over */
    if (hs_has($conn, 'analysis_sec_invoices')) {
        $r = hs_q($conn, "SELECT UPPER(TRIM(outlet_hul_code)) AS code, $cy AS cyc, SUM($fin) AS v, COUNT(*) AS n, MAX(bill_date) AS ld FROM analysis_sec_invoices
                          WHERE bill_date >= '{$e($firstFrom)}' AND bill_date < '{$e($S['cutover'])}' AND outlet_hul_code <> '' GROUP BY code, cyc");
        while ($r && ($x = mysqli_fetch_assoc($r))) { $cells[$x['code']][$x['cyc']] = ($cells[$x['code']][$x['cyc']] ?? 0) + (float)$x['v']; $cnts[$x['code']][$x['cyc']] = ($cnts[$x['code']][$x['cyc']] ?? 0) + (int)$x['n']; $src['analysis'] += (int)$x['n']; if ($x['ld'] > $lastDate) $lastDate = $x['ld']; }
        $r = hs_q($conn, "SELECT UPPER(TRIM(t.outlet_hul_code)) AS code, t.party_name, t.salesperson_code, t.salesperson_name, t.beat FROM analysis_sec_invoices t JOIN (SELECT MAX(id) AS id FROM analysis_sec_invoices WHERE outlet_hul_code <> '' GROUP BY UPPER(TRIM(outlet_hul_code))) m ON m.id = t.id");
        while ($r && ($x = mysqli_fetch_assoc($r))) { $info[$x['code']] = ['name' => trim($x['party_name']), 'sp' => trim($x['salesperson_name']), 'beat' => trim($x['beat'])]; if ($x['salesperson_code'] !== '') $spName[strtoupper(trim($x['salesperson_code']))] = trim($x['salesperson_name']); }
    }
    /* 2) our own secondary import — bills from the cut-over date */
    if (hs_has($conn, 'secondary_invoice_import_details')) {
        $dc = "COALESCE(bill_date, delivery_date)"; $cy2 = hs_cyc_sql($dc, $sd);
        $r = hs_q($conn, "SELECT UPPER(TRIM(t_code)) AS code, $cy2 AS cyc, SUM(v) AS v, COUNT(*) AS n, MAX(d) AS ld FROM (
                              SELECT t_code, bill_no, $dc AS d, MAX(" . ($S['basis'] === 'billvalue' ? '(bill_value + total_discount)' : '(total_discount + final_bill_amount)') . ") AS v, MAX(bill_date) AS bill_date, MAX(delivery_date) AS delivery_date
                              FROM secondary_invoice_import_details WHERE t_code <> '' AND $dc >= '{$e($S['cutover'])}' GROUP BY t_code, bill_no, $dc) z
                          GROUP BY code, cyc");
        while ($r && ($x = mysqli_fetch_assoc($r))) { $cells[$x['code']][$x['cyc']] = ($cells[$x['code']][$x['cyc']] ?? 0) + (float)$x['v']; $cnts[$x['code']][$x['cyc']] = ($cnts[$x['code']][$x['cyc']] ?? 0) + (int)$x['n']; $src['system'] += (int)$x['n']; if ($x['ld'] > $lastDate) $lastDate = $x['ld']; }
        $r = hs_q($conn, "SELECT UPPER(TRIM(t.t_code)) AS code, t.customer_name, t.party_name, t.sales_person_code, t.route_name FROM secondary_invoice_import_details t JOIN (SELECT MAX(id) AS id FROM secondary_invoice_import_details WHERE t_code <> '' GROUP BY UPPER(TRIM(t_code))) m ON m.id = t.id");
        while ($r && ($x = mysqli_fetch_assoc($r))) { $c = $x['code']; $nm = trim($x['customer_name'] ?: $x['party_name']); $sp = $spName[strtoupper(trim($x['sales_person_code']))] ?? trim($x['sales_person_code']);
            $info[$c] = ['name' => $nm !== '' ? $nm : ($info[$c]['name'] ?? ''), 'sp' => $sp !== '' ? $sp : ($info[$c]['sp'] ?? ''), 'beat' => trim($x['route_name']) !== '' ? trim($x['route_name']) : ($info[$c]['beat'] ?? '')]; }
    }
    /* customer master: name + secondary channel */
    $ch = []; if (hs_has($conn, 'customers')) { $r = hs_q($conn, "SELECT UPPER(TRIM(t_code)) AS code, shop_name, COALESCE(channel,'') AS ch, LOWER(TRIM(COALESCE(payment_mode,''))) AS pm, COALESCE(credit_days,0) AS days FROM customers"); while ($r && ($x = mysqli_fetch_assoc($r))) $ch[$x['code']] = $x; }
    $rows = []; $tot = []; $W = [$S['w12'], $S['w6'], $S['w3'], $S['wl']];
    $avg = fn($a) => $a ? array_sum($a) / count($a) : 0;
    foreach ($cells as $code => $byc) {
        $first = null; foreach ($months as $i => $m) if (($byc[$m] ?? 0) != 0) { $first = $i; break; }
        $vals = []; foreach ($months as $i => $m) $vals[$m] = ($first !== null && $i >= $first) ? round($byc[$m] ?? 0, 2) : null;
        $obs = array_values(array_filter($vals, fn($v) => $v !== null));
        $last = array_slice($obs, -1); $l3 = array_slice($obs, -3); $l6 = array_slice($obs, -6);
        $a12 = $avg($obs); $a6 = $avg($l6); $a3 = $avg($l3); $lat = (float)($vals[end($months)] ?? 0);
        $n = count($obs); $mean = $a12; $cv = null; if ($n > 1 && $mean > 0) { $ss = 0; foreach ($obs as $v) $ss += ($v - $mean) ** 2; $cv = sqrt($ss / ($n - 1)) / $mean; }
        $prev = $vals[$months[count($months) - 2]] ?? null; $mom = ($prev !== null && $prev > 0) ? $lat / $prev - 1 : null;
        $oldObs = array_values(array_filter(array_slice($vals, 0, count($months) - 3, true), fn($v) => $v !== null)); $a9 = $avg($oldObs);
        $ex = round($byc[$extraYm] ?? 0, 2);
        $inf = $info[$code] ?? []; $c = $ch[$code] ?? null;
        $rows[] = ['code' => $code, 'name' => ($c['shop_name'] ?? '') !== '' ? $c['shop_name'] : ($inf['name'] ?? ''), 'sp' => $inf['sp'] ?? '', 'beat' => $inf['beat'] ?? '',
            'channel' => $c ? ($c['ch'] !== '' ? $c['ch'] : 'Unclassified') : 'Unclassified (not in Customers)', 'v' => $vals, 'n' => $cnts[$code] ?? [], 'pm' => $c ? ($c['pm'] !== '' ? ucfirst($c['pm']) : '') : '', 'days' => $c ? (int)$c['days'] : null, 'extra' => $ex, 'extra_n' => $cnts[$code][$extraYm] ?? 0,
            'a12' => $a12, 'a6' => $a6, 'a3' => $a3, 'latest' => $lat, 'hi' => $obs ? max($obs) : 0, 'lo' => $obs ? min($obs) : 0, 'active' => count(array_filter($obs, fn($v) => $v > 0)),
            'cv' => $cv, 'mom' => $mom, 'base' => max(0, $W[0] * $a12 + $W[1] * $a6 + $W[2] * $a3 + $W[3] * $lat), 'r3' => array_sum($l3), 'trend' => $a9 > 0 ? $a3 / $a9 - 1 : null];
        foreach ($months as $m) $tot[$m] = ($tot[$m] ?? 0) + ($vals[$m] ?? 0); $tot['extra'] = ($tot['extra'] ?? 0) + $ex;
    }
    usort($rows, fn($a, $b) => $b['a3'] <=> $a['a3']);
    return ['months' => $months, 'extra' => $extraYm, 'rows' => $rows, 'tot' => $tot, 'src' => $src, 'last_date' => $lastDate, 'range' => [hs_range($months[0], $sd)[0], hs_range($months[count($months) - 1], $sd)[1]]];
}
