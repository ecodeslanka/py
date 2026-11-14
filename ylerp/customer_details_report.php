<?php
/**
 * customer_details_report.php — CUSTOMER DETAILS (per T-Code)
 *   T-Code · Customer Pay Mode · Channel · Chq Will Delay (yes / no) · Chq Returns (times) · Chq Sent Back (times)
 *   · No. of times of Credit-Policy Violation · Bill Cancel (amount (count)) · Average Bill Count Per Month
 * Click a row → the detailed report (violating bills, returned / sent-back cheques, cancelled bills, bills per month).
 * Export Excel: a styled workbook — Summary + Policy Violations + Cheques + Cancelled Bills + Bills per Month.
 * Same rules as "Analyze the Customers": credit / cheque policy is violated by a bill paid after (or still unpaid past) delivery date + credit days.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
include_once __DIR__ . '/report_extras_lib.php';
rx_ensure($conn);
if ((isset($_GET['ajax']) || isset($_GET['export'])) && session_status() === PHP_SESSION_ACTIVE) session_write_close();   // long data requests do not block the user's other tabs
/** query that never throws (a missing table / column gives false, not an HTTP 500) */
function cd_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { $GLOBALS['CD_ERR'] = $e->getMessage(); return false; } }
function cd_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Build the report for the customers matching $o (t_code = one customer, pm, channel, q, cwd, issues). $detail adds the row lists. */
function cd_build($conn, array $o, $detail = false) {
    $today = date('Y-m-d'); $today_ts = strtotime($today);
    $e = fn($v) => mysqli_real_escape_string($conn, (string)$v);
    $hasCwd = rx_has_col($conn, 'customers', 'cheques_will_delay');
    $hasCh  = rx_has_col($conn, 'customers', 'channel');
    $btgTbl = cd_q($conn, "SHOW TABLES LIKE 'customer_btg'"); $hasBtg = $btgTbl && mysqli_num_rows($btgTbl) > 0;
    $chExpr = $hasBtg ? ($hasCh ? "COALESCE(NULLIF(b.channel,''), NULLIF(c.channel,''), '')" : "COALESCE(b.channel,'')") : ($hasCh ? "COALESCE(c.channel,'')" : "''");
    $w = ['1=1'];
    if (($o['t_code'] ?? '') !== '') $w[] = "c.t_code = '{$e($o['t_code'])}'";
    if (($o['pm'] ?? '') !== '')     $w[] = "LOWER(TRIM(COALESCE(c.payment_mode,''))) = '{$e(strtolower($o['pm']))}'";
    if (($o['q'] ?? '') !== '')      { $q = $e($o['q']); $w[] = "(c.t_code LIKE '%$q%' OR c.shop_name LIKE '%$q%')"; }
    if ($hasCwd && ($o['cwd'] ?? '') === 'yes') $w[] = "COALESCE(c.cheques_will_delay,0) = 1";
    if ($hasCwd && ($o['cwd'] ?? '') === 'no')  $w[] = "COALESCE(c.cheques_will_delay,0) = 0";
    $cust = [];
    $q = cd_q($conn, "SELECT c.t_code, c.shop_name, LOWER(TRIM(COALESCE(c.payment_mode,''))) AS pm, COALESCE(c.credit_days,0) AS credit_days,
                " . ($hasCwd ? "COALESCE(c.cheques_will_delay,0)" : "0") . " AS cwd, $chExpr AS channel
              FROM customers c " . ($hasBtg ? "LEFT JOIN customer_btg b ON CONVERT(b.t_code USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(c.t_code USING utf8mb4) COLLATE utf8mb4_general_ci " : "") . "WHERE " . implode(' AND ', $w) . " ORDER BY c.t_code LIMIT 20000");
    while ($q && ($x = mysqli_fetch_assoc($q))) { if (($o['channel'] ?? '') !== '' && strcasecmp($x['channel'], $o['channel']) !== 0) continue; $cust[$x['t_code']] = $x; }
    if (!$cust) return ['rows' => [], 'viol' => [], 'chq' => [], 'canc' => [], 'month' => [], 'months' => []];
    $tlist = "'" . implode("','", array_map($e, array_keys($cust))) . "'";

    /* bills: balance today + last payment date */
    $P = rx_inv_parts($conn, $today);
    $bills = [];
    $q = cd_q($conn, "
        SELECT fsd.t_code, fsd.invoice_num, fs.delivery_date, {$P['net']} AS net, {$P['balance']} AS balance, lpd.lp AS last_paid
        FROM field_summary_details fsd INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id {$P['joins']}
        LEFT JOIN (SELECT field_summary_detail_id AS did, MAX(payment_date) AS lp FROM invoice_payments WHERE is_reversed = 0 GROUP BY field_summary_detail_id) lpd ON lpd.did = fsd.id
        WHERE fsd.updated = 1 AND fsd.t_code IN ($tlist) AND fs.delivery_date <= '$today' ORDER BY fs.delivery_date DESC");
    while ($q && ($b = mysqli_fetch_assoc($q))) $bills[$b['t_code']][] = $b;

    /* returned / sent-back cheques */
    $chq = [];
    $q = cd_q($conn, "SELECT ch.t_code, ch.cheque_no, ch.cheque_date, ch.bank_code, ch.status, COALESCE(ch.total_amount, ch.amount, 0) AS amount,
                COALESCE(ch.return_settled,0) AS ret_settled, COALESCE(ch.sb_settled,0) AS sb_settled
              FROM cheques ch WHERE ch.t_code IN ($tlist) AND ch.status IN ('returned','sent_back') ORDER BY ch.cheque_date DESC");
    while ($q && ($c = mysqli_fetch_assoc($q))) $chq[$c['t_code']][] = $c;

    /* cancelled bills */
    $canc = [];
    if (rx_has_col($conn, 'field_summary_details', 'cancel_value')) {
        $q = cd_q($conn, "SELECT fsd.t_code, fsd.invoice_num, fs.delivery_date, COALESCE(fsd.cancel_value,0) AS v FROM field_summary_details fsd
                                  INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id WHERE fsd.t_code IN ($tlist) AND COALESCE(fsd.cancel_value,0) > 0 ORDER BY fs.delivery_date DESC");
        while ($q && ($x = mysqli_fetch_assoc($q))) $canc[$x['t_code']][] = $x;
    }

    /* bills per month: last 12 months; the average = last 6 COMPLETE months ÷ 6 */
    $months = []; for ($i = 12; $i >= 0; $i--) $months[] = date('Y-m', strtotime("first day of -$i month"));
    $m0 = $months[0] . '-01'; $month = [];
    $q = cd_q($conn, "SELECT fsd.t_code, DATE_FORMAT(fs.delivery_date, '%Y-%m') AS ym, COUNT(*) AS n FROM field_summary_details fsd
                              INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id WHERE fsd.updated = 1 AND fsd.t_code IN ($tlist) AND fs.delivery_date >= '$m0' AND fs.delivery_date <= '$today' GROUP BY fsd.t_code, ym");
    while ($q && ($x = mysqli_fetch_assoc($q))) $month[$x['t_code']][$x['ym']] = (int)$x['n'];
    $last6 = array_slice($months, 6, 6);   // the 6 complete months before the current one

    $rows = []; $viol = []; $chqL = []; $cancL = [];
    foreach ($cust as $t => $c) {
        $days = (int)$c['credit_days']; $pm = $c['pm'];
        $policy = ($pm === 'cheque' || (int)$c['cwd'] === 1) ? 'cheque' : ($pm === 'credit' ? 'credit' : 'none');
        $nv = 0; $maxo = 0;
        foreach ($bills[$t] ?? [] as $b) {
            $bal = (float)$b['balance']; $del = strtotime($b['delivery_date']); $due = $del + $days * 86400; $open = $bal > 0.005;
            $ref = $open ? $today_ts : ($b['last_paid'] ? strtotime($b['last_paid']) : null);
            $over = ($ref !== null) ? (int)floor(($ref - $due) / 86400) : 0;
            if ($policy !== 'none' && $over > 0) { $nv++; if ($over > $maxo) $maxo = $over;
                if ($detail) $viol[] = ['t_code' => $t, 'shop' => $c['shop_name'], 'inv' => (string)$b['invoice_num'], 'del' => $b['delivery_date'], 'due' => date('Y-m-d', $due), 'paid' => $open ? '' : (string)$b['last_paid'], 'net' => round((float)$b['net'], 2), 'bal' => round($bal, 2), 'over' => $over, 'open' => $open ? 'Open' : 'Paid late', 'policy' => $policy, 'days' => $days]; }
        }
        $rt = 0; $sb = 0; $rto = 0; $sbo = 0;   // totals and how many are still NOT settled
        foreach ($chq[$t] ?? [] as $k) {
            $isSet = ($k['status'] === 'sent_back') ? (int)$k['sb_settled'] : (int)$k['ret_settled'];
            if ($k['status'] === 'sent_back') { $sb++; if (!$isSet) $sbo++; } else { $rt++; if (!$isSet) $rto++; }
            if ($detail) $chqL[] = ['t_code' => $t, 'shop' => $c['shop_name'], 'no' => (string)$k['cheque_no'], 'date' => $k['cheque_date'], 'bank' => (string)$k['bank_code'], 'status' => $k['status'] === 'sent_back' ? 'Sent back' : 'Returned', 'amt' => round((float)$k['amount'], 2), 'settled' => ($k['status'] === 'sent_back' ? (int)$k['sb_settled'] : (int)$k['ret_settled']) ? 'Settled' : 'Open'];
        }
        $cn = 0; $cv = 0.0;
        foreach ($canc[$t] ?? [] as $k) { $cn++; $cv += (float)$k['v']; if ($detail) $cancL[] = ['t_code' => $t, 'shop' => $c['shop_name'], 'inv' => (string)$k['invoice_num'], 'del' => $k['delivery_date'], 'v' => round((float)$k['v'], 2)]; }
        $sum6 = 0; foreach ($last6 as $ym) $sum6 += $month[$t][$ym] ?? 0;
        $row = ['t_code' => $t, 'shop' => $c['shop_name'], 'pm' => $pm === '' ? '—' : ucfirst($pm), 'channel' => $c['channel'], 'cwd' => (int)$c['cwd'] ? 'Yes' : 'No', 'rt' => $rt, 'rto' => $rto, 'sb' => $sb, 'sbo' => $sbo,
                'viol' => $nv, 'policy' => $policy, 'max_over' => $maxo, 'cn' => $cn, 'cv' => round($cv, 2), 'avg' => round($sum6 / 6, 1), 'bills' => count($bills[$t] ?? [])];
        $iss = $o['issues'] ?? '';
        if ($iss === 'cwd' && $row['cwd'] !== 'Yes') continue; if ($iss === 'ret' && ($rt + $sb) === 0) continue; if ($iss === 'retopen' && ($rto + $sbo) === 0) continue; if ($iss === 'viol' && $nv === 0) continue; if ($iss === 'canc' && $cn === 0) continue;
        if ($iss === 'any' && $row['cwd'] !== 'Yes' && ($rt + $sb) === 0 && $nv === 0 && $cn === 0) continue;
        $rows[] = $row;
    }
    $keep = array_flip(array_column($rows, 't_code'));
    $f = fn($a) => array_values(array_filter($a, fn($x) => isset($keep[$x['t_code']])));
    $mm = []; foreach ($rows as $r) { $mm[$r['t_code']] = array_map(fn($ym) => $month[$r['t_code']][$ym] ?? 0, $months); }
    return ['rows' => $rows, 'viol' => $f($viol), 'chq' => $f($chqL), 'canc' => $f($cancL), 'month' => $mm, 'months' => $months];
}

/* ═════════ detail pop-up (one customer) ═════════ */
if (($_GET['ajax'] ?? '') === 'detail') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $t = trim($_GET['t_code'] ?? '');
    try { $D = $t !== '' ? cd_build($conn, ['t_code' => $t], true) : null; } catch (Throwable $ex) { echo json_encode(['success' => false, 'message' => 'Error: ' . $ex->getMessage()]); exit; }
    if (!$D || !$D['rows']) { echo json_encode(['success' => false, 'message' => 'Customer not found.']); exit; }
    echo json_encode(['success' => true, 'row' => $D['rows'][0], 'viol' => $D['viol'], 'chq' => $D['chq'], 'canc' => $D['canc'], 'months' => $D['months'], 'month' => $D['month'][$t] ?? []], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit;
}

$F = ['pm' => trim($_GET['pm'] ?? ''), 'channel' => trim($_GET['channel'] ?? ''), 'q' => trim($_GET['q'] ?? ''), 'cwd' => in_array($_GET['cwd'] ?? '', ['yes', 'no'], true) ? $_GET['cwd'] : '',
      'issues' => in_array($_GET['issues'] ?? '', ['any', 'cwd', 'ret', 'retopen', 'viol', 'canc'], true) ? $_GET['issues'] : ''];
try { $D = cd_build($conn, $F, isset($_GET['export'])); } catch (Throwable $ex) { $D = ['rows' => [], 'viol' => [], 'chq' => [], 'canc' => [], 'month' => [], 'months' => []]; $CD_ERR = $ex->getMessage(); }
$rows = $D['rows'];
$channels = []; $cr = cd_q($conn, "SELECT DISTINCT channel FROM customer_btg WHERE channel IS NOT NULL AND channel <> '' ORDER BY channel"); while ($cr && ($x = mysqli_fetch_row($cr))) $channels[] = $x[0];

/* ═════════ Excel export ═════════ */
if (isset($_GET['export']) && class_exists('ZipArchive')) {
    require_once __DIR__ . '/rbac_xlsx.php';
    while (ob_get_level()) ob_end_clean();
    $X = new MiniXlsx();
    $BAN = ['b' => 1, 'sz' => 18, 'color' => 'FFFFFF', 'bg' => '1E3A8A', 'h' => 'left']; $SUB = ['i' => 1, 'color' => '6B7280'];
    $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '1E3A8A', 'h' => 'center', 'border' => 1, 'wrap' => 1];
    $B = ['border' => 1]; $BR = ['border' => 1, 'h' => 'right', 'nf' => 4]; $BC = ['border' => 1, 'h' => 'center'];
    $act = array_filter([$F['pm'] !== '' ? 'Pay mode: ' . $F['pm'] : '', $F['channel'] !== '' ? 'Channel: ' . $F['channel'] : '', $F['cwd'] !== '' ? 'Chq will delay: ' . $F['cwd'] : '', $F['issues'] !== '' ? 'Only: ' . $F['issues'] : '', $F['q'] !== '' ? 'Search: ' . $F['q'] : '']);
    $stamp = 'Generated ' . date('d M Y H:i') . ' · ' . ($act ? implode(' · ', $act) : 'all customers');
    /* 1. Summary */
    $sh = $X->sheet('Summary');
    $sh->set(1, 1, 'Customer Details Report', $BAN); for ($c = 2; $c <= 14; $c++) $sh->set(1, $c, '', $BAN); $sh->merge(1, 1, 1, 14); $sh->set(2, 1, $stamp, $SUB);
    $k = ['Customers' => count($rows), 'Cheques will delay' => count(array_filter($rows, fn($r) => $r['cwd'] === 'Yes')), 'Returned cheques' => array_sum(array_column($rows, 'rt')), 'Sent back cheques' => array_sum(array_column($rows, 'sb')),
          'Policy violations' => array_sum(array_column($rows, 'viol')), 'Cancelled bills' => array_sum(array_column($rows, 'cn')), 'Cancelled value (Rs.)' => round(array_sum(array_column($rows, 'cv')), 2)];
    $ci = 1; foreach ($k as $lab => $v) { $sh->set(4, $ci, $lab, ['b' => 1, 'sz' => 9, 'color' => '6B7280', 'bg' => 'EEF2FF', 'h' => 'center', 'wrap' => 1, 'border' => 1]); $sh->set(5, $ci, $v, ['b' => 1, 'sz' => 14, 'color' => '1E3A8A', 'h' => 'center', 'border' => 1] + (is_float($v) ? ['nf' => 4] : [])); $ci++; }
    foreach (['T-Code', 'Shop', 'Customer Pay Mode', 'Channel', 'Chq Will Delay', 'Chq Returns', 'Chq Sent Back', 'No of Times of Credit Policy Violation', 'Bill Cancel — Amount (Count)', 'Average Bill Count Per Month', 'Returns — Not Settled', 'Returns — Settled', 'Sent Back — Not Settled', 'Sent Back — Settled'] as $i => $h) $sh->set(7, $i + 1, $h, $H);
    $r = 8; foreach ($rows as $x) {
        $sh->set($r, 1, $x['t_code'], $B); $sh->set($r, 2, $x['shop'], $B); $sh->set($r, 3, $x['pm'], $BC); $sh->set($r, 4, $x['channel'], $BC);
        $sh->set($r, 5, $x['cwd'], $BC + ($x['cwd'] === 'Yes' ? ['b' => 1, 'color' => 'B45309', 'bg' => 'FEF3C7'] : []));
        $sh->set($r, 6, $x['rt'], $BC + ($x['rt'] ? ['b' => 1, 'color' => '6D28D9', 'bg' => 'EDE9FE'] : [])); $sh->set($r, 7, $x['sb'], $BC + ($x['sb'] ? ['b' => 1, 'color' => '1D4ED8', 'bg' => 'DBEAFE'] : []));
        $sh->set($r, 8, $x['policy'] === 'none' ? 'n/a' : $x['viol'], $BC + ($x['viol'] ? ['b' => 1, 'color' => 'B91C1C', 'bg' => 'FEE2E2'] : []));
        $sh->set($r, 9, $x['cn'] ? number_format($x['cv'], 2) . ' (' . $x['cn'] . ')' : '—', $BC + ($x['cn'] ? ['color' => 'B91C1C'] : [])); $sh->set($r, 10, $x['avg'], $BC);
        $sh->set($r, 11, $x['rto'], $BC + ($x['rto'] ? ['b' => 1, 'color' => 'B91C1C', 'bg' => 'FEE2E2'] : [])); $sh->set($r, 12, $x['rt'] - $x['rto'], $BC + ($x['rt'] - $x['rto'] ? ['color' => '15803D'] : []));
        $sh->set($r, 13, $x['sbo'], $BC + ($x['sbo'] ? ['b' => 1, 'color' => 'B91C1C', 'bg' => 'FEE2E2'] : [])); $sh->set($r, 14, $x['sb'] - $x['sbo'], $BC + ($x['sb'] - $x['sbo'] ? ['color' => '15803D'] : [])); $r++;
    }
    foreach ([14, 34, 16, 18, 12, 12, 12, 20, 24, 16, 13, 13, 13, 13] as $i => $wd) $sh->widths[$i + 1] = $wd;
    $sh->freeze = [7, 1]; $sh->filter = 'A7:N' . max(8, $r - 1);
    /* 2. Policy violations */
    $s2 = $X->sheet('Policy Violations'); $s2->set(1, 1, 'Credit / Cheque policy violations — one row per bill', ['b' => 1, 'sz' => 14]);
    foreach (['T-Code', 'Shop', 'Invoice', 'Policy', 'Credit days', 'Delivery date', 'Due date', 'Paid date', 'Bill value', 'Balance', 'Days over', 'Status'] as $i => $h) $s2->set(3, $i + 1, $h, $H);
    $r = 4; foreach ($D['viol'] as $x) { foreach ([$x['t_code'], $x['shop'], $x['inv'], ucfirst($x['policy']), $x['days'], $x['del'], $x['due'], $x['paid'], $x['net'], $x['bal'], $x['over'], $x['open']] as $i => $v) $s2->set($r, $i + 1, $v, in_array($i, [8, 9], true) ? $BR : ($i === 10 ? ['border' => 1, 'h' => 'center', 'b' => 1, 'color' => 'B91C1C'] : $B)); $r++; }
    foreach ([14, 32, 14, 10, 11, 13, 13, 13, 14, 14, 10, 12] as $i => $wd) $s2->widths[$i + 1] = $wd; $s2->freeze = [3, 2]; $s2->filter = 'A3:L' . max(4, $r - 1);
    /* 3. Cheques */
    $s3 = $X->sheet('Cheques'); $s3->set(1, 1, 'Returned and sent-back cheques', ['b' => 1, 'sz' => 14]);
    foreach (['T-Code', 'Shop', 'Cheque no', 'Cheque date', 'Bank', 'Type', 'Amount', 'Settlement'] as $i => $h) $s3->set(3, $i + 1, $h, $H);
    $r = 4; foreach ($D['chq'] as $x) { foreach ([$x['t_code'], $x['shop'], $x['no'], $x['date'], $x['bank'], $x['status'], $x['amt'], $x['settled']] as $i => $v) $s3->set($r, $i + 1, $v, $i === 6 ? $BR : ($i === 5 ? $BC + ['b' => 1, 'color' => $x['status'] === 'Returned' ? '6D28D9' : '1D4ED8'] : ($i === 7 ? $BC + ['color' => $x['settled'] === 'Open' ? 'B91C1C' : '15803D'] : $B))); $r++; }
    foreach ([14, 32, 14, 13, 10, 12, 14, 12] as $i => $wd) $s3->widths[$i + 1] = $wd; $s3->freeze = [3, 2]; $s3->filter = 'A3:H' . max(4, $r - 1);
    /* 4. Cancelled bills */
    $s4 = $X->sheet('Cancelled Bills'); $s4->set(1, 1, 'Cancelled bills', ['b' => 1, 'sz' => 14]);
    foreach (['T-Code', 'Shop', 'Invoice', 'Delivery date', 'Cancelled value'] as $i => $h) $s4->set(3, $i + 1, $h, $H);
    $r = 4; foreach ($D['canc'] as $x) { foreach ([$x['t_code'], $x['shop'], $x['inv'], $x['del'], $x['v']] as $i => $v) $s4->set($r, $i + 1, $v, $i === 4 ? $BR : $B); $r++; }
    foreach ([14, 32, 14, 13, 16] as $i => $wd) $s4->widths[$i + 1] = $wd; $s4->freeze = [3, 2]; $s4->filter = 'A3:E' . max(4, $r - 1);
    /* 5. Bills per month */
    $s5 = $X->sheet('Bills per Month'); $s5->set(1, 1, 'Number of bills per month (last 13 months)', ['b' => 1, 'sz' => 14]);
    $s5->set(3, 1, 'T-Code', $H); $s5->set(3, 2, 'Shop', $H); foreach ($D['months'] as $i => $ym) $s5->set(3, $i + 3, date('M y', strtotime($ym . '-01')), $H);
    $r = 4; foreach ($rows as $x) { $s5->set($r, 1, $x['t_code'], $B); $s5->set($r, 2, $x['shop'], $B); foreach ($D['month'][$x['t_code']] as $i => $n) $s5->set($r, $i + 3, $n, $BC + ($n === 0 ? ['color' => 'D1D5DB'] : [])); $r++; }
    $s5->widths[1] = 14; $s5->widths[2] = 32; for ($i = 0; $i < count($D['months']); $i++) $s5->widths[$i + 3] = 9; $s5->freeze = [3, 2];
    $tmp = tempnam(sys_get_temp_dir(), 'cd'); $X->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="customer_details_' . date('Ymd_His') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
ob_end_flush();
include 'header.php';
$cnt = fn($f) => count(array_filter($rows, $f));
?>
<style>
.cdr{max-width:1600px;margin:0 auto;padding:16px;font-family:'Inter',system-ui,sans-serif;color:#111827}.cdr h2{margin:0 0 3px;font-size:22px}.cdr .sub{color:#6b7280;font-size:13px;margin:0 0 14px}
.cdr .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:14px}
.cdr .btn{padding:8px 16px;border:none;border-radius:8px;background:#1e3a8a;color:#fff;font:600 13px inherit;cursor:pointer;text-decoration:none;display:inline-flex;gap:6px;align-items:center}.cdr .btn.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe}.cdr .btn.g{background:#16a34a}
.cdr .row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.cdr .fld{display:flex;flex-direction:column;gap:3px}.cdr .fld label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase}
.cdr input,.cdr select{padding:8px 10px;border:1.5px solid #d1d5db;border-radius:8px;font:inherit;font-size:13px}
.cdr .kp{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:12px}.cdr .k{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px}.cdr .k span{font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:700;display:block}.cdr .k b{font-size:20px}
.cdr .tw{overflow:auto;border:1px solid #e5e7eb;border-radius:10px;background:#fff;max-height:72vh}.cdr table{width:100%;border-collapse:collapse;font-size:12.5px}.cdr th{background:#1e3a8a;color:#fff;padding:9px 9px;text-align:center;position:sticky;top:0;white-space:nowrap}
.cdr td{padding:7px 9px;border-bottom:1px solid #f1f5f9;text-align:center}.cdr td.l,.cdr th.l{text-align:left}.cdr tbody tr{cursor:pointer}.cdr tr:hover td{background:#eef2ff}
.cdr .b{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700}.cdr .b.y{background:#fef3c7;color:#92400e}.cdr .b.n{background:#f3f4f6;color:#6b7280}.cdr .b.rt{background:#ede9fe;color:#6d28d9}.cdr .b.sb{background:#dbeafe;color:#1d4ed8}.cdr .b.v{background:#fee2e2;color:#b91c1c}.cdr .b.c{background:#fff1f2;color:#be123c}
.cdr .st{font-size:10.5px;line-height:1.3;margin-top:2px}.cdr .st .o{display:block;color:#b91c1c;font-weight:700}.cdr .st .s{display:block;color:#15803d}
.cdm.cdr{max-width:none;margin:0;padding:14px}.cdm{position:fixed;inset:0;background:rgba(15,23,42,.6);display:none;align-items:center;justify-content:center;z-index:2147483000;padding:14px}.cdm.open{display:flex}
.cdm .bx{background:#fff;border-radius:14px;width:100%;max-width:1200px;height:92vh;display:flex;flex-direction:column;overflow:hidden}.cdm .hd{padding:14px 18px;background:linear-gradient(120deg,#0f172a,#1e3a8a);color:#fff;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.cdm .bd{padding:14px 18px;overflow:auto;flex:1}.cdm h4{margin:16px 0 6px;font-size:14px}.cdm .tw{max-height:300px}
</style>
<div class="cdr">
  <h2><i class="fa-solid fa-address-card" style="color:#1e3a8a"></i> Customer Details</h2>
  <p class="sub">Per T-Code: pay mode, channel, cheques-will-delay, returned and sent-back cheques, credit-policy violations, cancelled bills and the average bills per month. <b>Click a row</b> for the detailed report. Policy rule: a bill violates the policy when it is paid after, or still unpaid past, delivery date + the customer's credit days. <a href="customer_analysis_report.php">Open "Analyze the Customers" →</a></p>
  <?php if (!empty($CD_ERR)): ?><div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:9px;padding:10px 14px;margin-bottom:12px;font-size:13px"><b>Some data could not be read:</b> <?= cd_h($CD_ERR) ?></div><?php endif; ?>
  <div class="card"><form method="get" class="row">
    <div class="fld"><label>Pay mode</label><select name="pm"><option value="">All</option><?php foreach (['cash' => 'Cash', 'credit' => 'Credit', 'cheque' => 'Cheque'] as $k => $l): ?><option value="<?= $k ?>" <?= $F['pm'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Channel</label><select name="channel"><option value="">All</option><?php foreach ($channels as $c): ?><option <?= $F['channel'] === $c ? 'selected' : '' ?>><?= cd_h($c) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Chq will delay</label><select name="cwd"><option value="">All</option><option value="yes" <?= $F['cwd'] === 'yes' ? 'selected' : '' ?>>Yes</option><option value="no" <?= $F['cwd'] === 'no' ? 'selected' : '' ?>>No</option></select></div>
    <div class="fld"><label>Show only</label><select name="issues"><option value="">All customers</option><?php foreach (['any' => 'Any issue', 'cwd' => 'Cheques will delay', 'ret' => 'Returned / sent-back cheques', 'retopen' => 'Returned / sent-back NOT settled', 'viol' => 'Policy violated', 'canc' => 'With cancelled bills'] as $k => $l): ?><option value="<?= $k ?>" <?= $F['issues'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Search</label><input type="search" name="q" value="<?= cd_h($F['q']) ?>" placeholder="T-Code or shop name"></div>
    <button class="btn" type="submit"><i class="fa-solid fa-filter"></i> Filter</button><a class="btn o" href="customer_details_report.php">Clear</a>
    <a class="btn g" href="?<?= cd_h(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a></form></div>
  <div class="kp"><div class="k"><span>Customers</span><b><?= number_format(count($rows)) ?></b></div><div class="k"><span>Cheques will delay</span><b style="color:#b45309"><?= $cnt(fn($r) => $r['cwd'] === 'Yes') ?></b></div><div class="k"><span>Returned cheques</span><b style="color:#6d28d9"><?= number_format(array_sum(array_column($rows, 'rt'))) ?></b><small style="display:block;color:#b91c1c;font-weight:700"><?= number_format(array_sum(array_column($rows, 'rto'))) ?> not settled</small></div><div class="k"><span>Sent back cheques</span><b style="color:#1d4ed8"><?= number_format(array_sum(array_column($rows, 'sb'))) ?></b><small style="display:block;color:#b91c1c;font-weight:700"><?= number_format(array_sum(array_column($rows, 'sbo'))) ?> not settled</small></div><div class="k"><span>Policy violations</span><b style="color:#b91c1c"><?= number_format(array_sum(array_column($rows, 'viol'))) ?></b></div><div class="k"><span>Cancelled value</span><b style="color:#be123c">Rs. <?= number_format(array_sum(array_column($rows, 'cv')), 2) ?></b></div></div>
  <div class="tw"><table><thead><tr><th class="l">T-Code</th><th class="l">Shop</th><th>Customer Pay Mode</th><th>Channel</th><th>Chq Will Delay</th><th>Chq Returns</th><th>Chq Sent Back</th><th>No of Times of Credit Policy Violation</th><th>Bill Cancel<br><small style="font-weight:500">amount (count)</small></th><th>Average Bill Count Per Month</th></tr></thead><tbody>
  <?php foreach ($rows as $x): ?>
    <tr data-t="<?= cd_h($x['t_code']) ?>"><td class="l"><b><?= cd_h($x['t_code']) ?></b></td><td class="l"><?= cd_h($x['shop']) ?></td><td><?= cd_h($x['pm']) ?></td><td><?= cd_h($x['channel'] ?: '—') ?></td>
      <td><span class="b <?= $x['cwd'] === 'Yes' ? 'y' : 'n' ?>"><?= $x['cwd'] ?></span></td>
      <td><?= $x['rt'] ? '<span class="b rt">' . $x['rt'] . '</span><div class="st">' . ($x['rto'] ? '<span class="o">' . $x['rto'] . ' not settled</span>' : '') . ($x['rt'] - $x['rto'] ? '<span class="s">' . ($x['rt'] - $x['rto']) . ' settled</span>' : '') . '</div>' : '<span style="color:#d1d5db">0</span>' ?></td><td><?= $x['sb'] ? '<span class="b sb">' . $x['sb'] . '</span><div class="st">' . ($x['sbo'] ? '<span class="o">' . $x['sbo'] . ' not settled</span>' : '') . ($x['sb'] - $x['sbo'] ? '<span class="s">' . ($x['sb'] - $x['sbo']) . ' settled</span>' : '') . '</div>' : '<span style="color:#d1d5db">0</span>' ?></td>
      <td><?= $x['policy'] === 'none' ? '<span style="color:#d1d5db">n/a</span>' : ($x['viol'] ? '<span class="b v">' . $x['viol'] . ' time' . ($x['viol'] > 1 ? 's' : '') . '</span>' : '<span style="color:#d1d5db">0</span>') ?></td>
      <td><?= $x['cn'] ? '<span class="b c">' . number_format($x['cv'], 2) . ' (' . $x['cn'] . ')</span>' : '<span style="color:#d1d5db">—</span>' ?></td><td><b><?= $x['avg'] ?></b></td></tr>
  <?php endforeach; if (!$rows) echo '<tr><td colspan="10" style="padding:22px;color:#9ca3af">No customers match.</td></tr>'; ?></tbody></table></div>
</div>
<div class="cdm cdr" id="cdM"><div class="bx"><div class="hd"><div><div style="font-weight:800;font-size:16px" id="cdT">Customer</div><div id="cdS" style="opacity:.8;font-size:12.5px"></div></div><button class="btn o" id="cdX" type="button" style="margin-left:auto">Close</button></div><div class="bd" id="cdB"></div></div></div>
<script>
(function(){
  var $=function(i){return document.getElementById(i);}, esc=function(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}, m2=function(v){return Number(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});};
  document.body.appendChild($('cdM'));
  function tbl(head,rows){ return '<div class="tw"><table><thead><tr>'+head.map(function(h){return '<th>'+h+'</th>';}).join('')+'</tr></thead><tbody>'+(rows.length?rows.join(''):'<tr><td colspan="'+head.length+'" style="padding:14px;color:#9ca3af">None</td></tr>')+'</tbody></table></div>'; }
  function close(){ $('cdM').classList.remove('open'); document.body.style.overflow=''; }
  $('cdX').addEventListener('click',close); document.addEventListener('keydown',function(e){ if(e.key==='Escape') close(); }); $('cdM').addEventListener('click',function(e){ if(e.target===this) close(); });
  document.addEventListener('click',function(e){ var tr=e.target.closest('tr[data-t]'); if(!tr) return; var t=tr.dataset.t;
    $('cdT').textContent=t; $('cdS').textContent='Loading…'; $('cdB').innerHTML=''; $('cdM').classList.add('open'); document.body.style.overflow='hidden';
    fetch('customer_details_report.php?ajax=detail&t_code='+encodeURIComponent(t),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      if(!d.success){ $('cdS').textContent=''; $('cdB').innerHTML='<p style="color:#b91c1c">'+esc(d.message||'Could not load')+'</p>'; return; }
      var r=d.row; $('cdT').textContent=r.t_code+' — '+r.shop; $('cdS').textContent='Pay mode '+r.pm+' · Channel '+(r.channel||'—')+' · Cheques will delay: '+r.cwd+' · Policy '+r.policy;
      var h='<div class="kp" style="margin-top:0"><div class="k"><span>Returned cheques</span><b style="color:#6d28d9">'+r.rt+'</b><small style="display:block;font-weight:700"><span style="color:#b91c1c">'+r.rto+' not settled</span> · <span style="color:#15803d">'+(r.rt-r.rto)+' settled</span></small></div><div class="k"><span>Sent back</span><b style="color:#1d4ed8">'+r.sb+'</b><small style="display:block;font-weight:700"><span style="color:#b91c1c">'+r.sbo+' not settled</span> · <span style="color:#15803d">'+(r.sb-r.sbo)+' settled</span></small></div><div class="k"><span>Policy violations</span><b style="color:#b91c1c">'+(r.policy==='none'?'n/a':r.viol)+'</b></div><div class="k"><span>Worst delay</span><b>'+(r.max_over?'+'+r.max_over+' d':'—')+'</b></div><div class="k"><span>Cancelled</span><b style="color:#be123c">'+m2(r.cv)+' ('+r.cn+')</b></div><div class="k"><span>Avg bills / month</span><b>'+r.avg+'</b></div></div>';
      h+='<h4>Policy violations — bills</h4>'+tbl(['Invoice','Delivery','Due','Paid','Bill value','Balance','Days over','Status'],d.viol.map(function(x){ return '<tr><td>'+esc(x.inv)+'</td><td>'+esc(x.del)+'</td><td>'+esc(x.due)+'</td><td>'+esc(x.paid||'—')+'</td><td>'+m2(x.net)+'</td><td>'+m2(x.bal)+'</td><td><b style="color:#b91c1c">+'+x.over+'</b></td><td>'+esc(x.open)+'</td></tr>'; }));
      h+='<h4>Returned / sent-back cheques</h4>'+tbl(['Cheque no','Date','Bank','Type','Amount','Settlement'],d.chq.map(function(x){ return '<tr><td>'+esc(x.no)+'</td><td>'+esc(x.date)+'</td><td>'+esc(x.bank)+'</td><td>'+esc(x.status)+'</td><td>'+m2(x.amt)+'</td><td>'+esc(x.settled)+'</td></tr>'; }));
      h+='<h4>Cancelled bills</h4>'+tbl(['Invoice','Delivery','Cancelled value'],d.canc.map(function(x){ return '<tr><td>'+esc(x.inv)+'</td><td>'+esc(x.del)+'</td><td>'+m2(x.v)+'</td></tr>'; }));
      h+='<h4>Bills per month</h4>'+tbl(d.months.map(function(ym){ return ym; }),['<tr>'+d.months.map(function(ym,i){ var n=d.month[i]||0; return '<td>'+(n?'<b>'+n+'</b>':'<span style="color:#d1d5db">0</span>')+'</td>'; }).join('')+'</tr>']);
      $('cdB').innerHTML=h; }).catch(function(){ $('cdS').textContent=''; $('cdB').innerHTML='<p style="color:#b91c1c">Could not load the detail.</p>'; });
  });
})();
</script>
<?php include 'footer.php'; ?>
