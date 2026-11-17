<?php
/**
 * index.php — YMS company dashboard (home page)
 * Live figures from the company database: sales (secondary invoices; the analysis import before the cut-over), deliveries (field summary),
 * collections (invoice payments), market credit (outstanding bills, ageing, overdue), cheques in hand / returned / due, customers and staff.
 * Every block is shown only when the role may open the page it comes from. Figures are cached 5 minutes (Refresh skips the cache).
 * The Unilever receivable summary that used to be here is now Vendor Ledger → Vendor Ledger Dashboard (vendor_dashboard.php).
 */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'dash') {
    ob_start(); @ini_set('display_errors', '0');
    $out = function ($a) { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit; };
    register_shutdown_function(function () use ($out) { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) $out(['success' => false, 'message' => $e['message']]); });
    include 'config.php';
    if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
    if (empty($_SESSION['user_id'])) $out(['success' => false, 'message' => 'Please log in.']);
    date_default_timezone_set('Asia/Colombo'); @set_time_limit(180);
    $can = function (array $pages) { if (!function_exists('rbac_can')) return true; foreach ($pages as $p) if (rbac_can('view', $p)) return true; return false; };
    $P = [
        'sales'    => $can(['historical_sales_trend_report', 'invoice_wise_sales_history', 'customer_analysis_report', 'plan_executive_dashboard']),
        'deliver'  => $can(['field_summary_list', 'invoices']),
        'collect'  => $can(['payments', 'invoices', 'field_summary_list']),
        'credit'   => $can(['market_credit_report', 'credit_aging_report', 'credit_aging_summary_report']),
        'cheques'  => $can(['cheques', 'cheque_reconciliation', 'market_credit_report']),
        'people'   => $can(['employees']),
        'vendor'   => $can(['scheme_ledger_report', 'scheme_discount_daily_report', 'customer_claim_report', 'sscl_vat']),
    ];
    $ck = rtrim(sys_get_temp_dir(), '/\\') . '/yms_home_' . md5(__DIR__ . json_encode($P) . date('Y-m-d')) . '.json';
    session_write_close();   // the queries take a moment: do not hold this user's other pages
    if (empty($_GET['refresh']) && is_file($ck) && time() - filemtime($ck) < 300 && ($c = json_decode((string)@file_get_contents($ck), true))) $out($c + ['cached' => true]);

    $q = function ($sql) use ($conn) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } };
    $all = function ($sql) use ($q) { $r = $q($sql); $o = []; while ($r && ($x = mysqli_fetch_assoc($r))) $o[] = $x; return $o; };
    $one = function ($sql) use ($q) { $r = $q($sql); $x = $r ? mysqli_fetch_row($r) : null; return $x ? $x[0] : null; };
    $has = function ($t) use ($q) { static $m = []; if (!isset($m[$t])) { $r = $q("SHOW TABLES LIKE '$t'"); $m[$t] = $r && mysqli_num_rows($r) > 0; } return $m[$t]; };
    $T = date('Y-m-d'); $D = ['as_at' => date('d M Y, h:i A'), 'perm' => $P];

    /* sales cycle (Historical Sales Trends settings: cycle start day, cut-over, value basis) */
    $sd = 26; $co = null; $fin = '(total_discount + final_bill_amount)';
    if (is_file(__DIR__ . '/historical_sales_lib.php')) { require_once __DIR__ . '/historical_sales_lib.php';
        if (function_exists('hs_settings')) { $S = hs_settings($conn); $sd = (int)$S['start_day']; $co = $S['cutover'] ?: null; if (($S['basis'] ?? '') === 'billvalue') $fin = '(bill_value + total_discount)'; } }
    $cyc = function ($d) use ($sd) { $t = strtotime($d); return ($sd > 1 && (int)date('j', $t) >= $sd) ? date('Y-m', strtotime(date('Y-m-01', $t) . ' +1 month')) : date('Y-m', $t); };
    $rng = function ($ym) use ($sd) { if ($sd <= 1) return [$ym . '-01', date('Y-m-t', strtotime($ym . '-01'))]; $p = date('Y-m', strtotime($ym . '-01 -1 month')); return [$p . '-' . sprintf('%02d', $sd), $ym . '-' . sprintf('%02d', $sd - 1)]; };
    $cur = $cyc($T); [$c0, $c1] = $rng($cur); $prev = date('Y-m', strtotime($cur . '-01 -1 month')); [$p0] = $rng($prev);
    $elapsed = (int)((strtotime($T) - strtotime($c0)) / 86400);                       // days into the cycle → same point of the previous cycle
    $pTo = date('Y-m-d', strtotime($p0 . " +$elapsed day"));
    $D['cycle'] = ['label' => date('M-y', strtotime($cur . '-01')), 'from' => $c0, 'to' => $c1, 'prev' => date('M-y', strtotime($prev . '-01'))];

    try {
    /* ── SALES ── */
    if ($P['sales']) {
        $dc = 'COALESCE(bill_date, delivery_date)';
        $sec = fn($a, $b, $sel, $grp) => $has('secondary_invoice_import_details') ? "SELECT $sel FROM (SELECT $dc AS d, UPPER(TRIM(t_code)) AS c, MAX(sales_person_code) AS sp, MAX(COALESCE(NULLIF(customer_name,''), party_name)) AS nm, MAX($fin) AS v
                 FROM secondary_invoice_import_details WHERE t_code <> '' AND COALESCE(status,'') <> 'failed' AND $dc BETWEEN '$a' AND '$b' GROUP BY $dc, t_code, bill_no) z $grp" : null;
        $ana = fn($a, $b, $sel, $grp) => $has('analysis_sec_invoices') ? "SELECT $sel FROM (SELECT bill_date AS d, UPPER(TRIM(outlet_hul_code)) AS c, salesperson_code AS sp, party_name AS nm, $fin AS v FROM analysis_sec_invoices WHERE outlet_hul_code <> '' AND bill_date BETWEEN '$a' AND '$b') z $grp" : null;
        /* the analysis import is used before the cut-over date, the system import from it (as in Historical Sales Trends) */
        if (!$has('analysis_sec_invoices')) $co = null;      // no analysis import: the system import is used for every date
        $src = function ($a, $b, $sel, $grp) use ($sec, $ana, $co) { $o = [];
            if ($co && $a < $co) { $s = $ana($a, min($b, date('Y-m-d', strtotime($co . ' -1 day'))), $sel, $grp); if ($s) $o[] = $s; }
            $s = $sec($co && $a < $co ? max($a, $co) : $a, $b, $sel, $grp); if ($s && (!$co || $b >= $co)) $o[] = $s; return $o; };
        $sum = function ($a, $b) use ($src, $one) { $t = 0; foreach ($src($a, $b, 'COALESCE(SUM(v),0), COUNT(*), COUNT(DISTINCT c)', '') as $s) { $t += (float)$one($s); } return $t; };
        $agg = function ($a, $b, $sel, $grp) use ($src, $all) { $o = []; foreach ($src($a, $b, $sel, $grp) as $s) foreach ($all($s) as $x) $o[] = $x; return $o; };
        $cnt = function ($a, $b) use ($agg) { $n = 0; $c = []; foreach ($agg($a, $b, 'c, COUNT(*) AS n', 'GROUP BY c') as $x) { $n += (int)$x['n']; $c[$x['c']] = 1; } return [$n, count($c)]; };
        $mtd = $sum($c0, $T); $pmtd = $sum($p0, $pTo); [$bills, $outlets] = $cnt($c0, $T);
        $D['sales'] = ['cycle' => round($mtd, 2), 'prev_same' => round($pmtd, 2), 'today' => round($sum($T, $T), 2), 'bills' => $bills, 'outlets' => $outlets,
                       'avg_day' => $elapsed >= 0 ? round($mtd / ($elapsed + 1), 2) : 0];
        /* 12 cycles */
        $cy = []; for ($i = 11; $i >= 0; $i--) { $ym = date('Y-m', strtotime($cur . "-01 -$i month")); [$a, $b] = $rng($ym); $cy[] = ['k' => date('M-y', strtotime($ym . '-01')), 'v' => round($sum($a, min($b, $T)), 2)]; }
        $D['sales']['cycles'] = $cy;
        /* daily, last 30 days */
        $d30 = date('Y-m-d', strtotime('-29 day')); $dm = [];
        foreach ($agg($d30, $T, 'd, SUM(v) AS v', 'GROUP BY d') as $x) $dm[$x['d']] = ($dm[$x['d']] ?? 0) + (float)$x['v'];
        $D['daily_sales'] = $dm;
        /* top customers, reps, channels (this cycle) */
        $tc = []; foreach ($agg($c0, $T, 'c, MAX(nm) AS nm, SUM(v) AS v', 'GROUP BY c') as $x) { $tc[$x['c']]['v'] = ($tc[$x['c']]['v'] ?? 0) + (float)$x['v']; $tc[$x['c']]['nm'] = $x['nm']; }
        $names = []; $chan = [];
        if ($tc && $has('customers')) { $in = "'" . implode("','", array_map(fn($c) => mysqli_real_escape_string($conn, $c), array_keys($tc))) . "'";
            foreach ($all("SELECT UPPER(TRIM(t_code)) AS c, shop_name, COALESCE(NULLIF(channel,''), 'Unclassified') AS ch FROM customers WHERE UPPER(TRIM(t_code)) IN ($in)") as $x) { $names[$x['c']] = $x['shop_name']; $chan[$x['c']] = $x['ch']; } }
        $ch = []; foreach ($tc as $c => $x) { $k = $chan[$c] ?? 'Unclassified'; $ch[$k] = ($ch[$k] ?? 0) + $x['v']; } arsort($ch);
        uasort($tc, fn($a, $b) => $b['v'] <=> $a['v']);
        $D['top_customers'] = array_map(fn($c, $x) => ['c' => $c, 'n' => $names[$c] ?? ($x['nm'] ?: $c), 'v' => round($x['v'], 2)], array_slice(array_keys($tc), 0, 8), array_slice(array_values($tc), 0, 8));
        $top = array_slice($ch, 0, 7, true); $rest = array_sum(array_slice($ch, 7)); if ($rest > 0) $top['Other channels'] = $rest;
        $D['channels'] = array_map(fn($k, $v) => ['k' => $k, 'v' => round($v, 2)], array_keys($top), array_values($top));
        $sp = []; foreach ($agg($c0, $T, "COALESCE(NULLIF(sp,''),'—') AS sp, SUM(v) AS v, COUNT(DISTINCT c) AS o", 'GROUP BY sp') as $x) { $k = $x['sp']; $sp[$k]['v'] = ($sp[$k]['v'] ?? 0) + (float)$x['v']; $sp[$k]['o'] = ($sp[$k]['o'] ?? 0) + (int)$x['o']; }
        uasort($sp, fn($a, $b) => $b['v'] <=> $a['v']); $spn = [];
        if ($sp && $has('analysis_sec_invoices')) foreach ($all("SELECT salesperson_code AS c, MAX(salesperson_name) AS n FROM analysis_sec_invoices WHERE salesperson_code <> '' GROUP BY salesperson_code") as $x) $spn[$x['c']] = $x['n'];
        $D['top_reps'] = []; foreach (array_slice($sp, 0, 8, true) as $k => $x) $D['top_reps'][] = ['c' => $k, 'n' => $spn[$k] ?? '', 'v' => round($x['v'], 2), 'o' => $x['o']];
    }

    try {
    } catch (Throwable $__e) { $D['warn'][] = 'Sales: ' . $__e->getMessage(); }   // one block failing does not stop the others
    /* ── DELIVERIES (field summary) ── */
    if ($P['deliver'] && $has('field_summary_details')) {
        $dv = function ($a, $b) use ($all) { $x = $all("SELECT COUNT(*) AS n, COALESCE(SUM(COALESCE(fsd.adjust_net_value, fsd.net_value, 0)),0) AS v, COUNT(DISTINCT fs.id) AS fs, COUNT(DISTINCT fs.route) AS rt
                FROM field_summary_details fsd INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id WHERE fs.delivery_date BETWEEN '$a' AND '$b'"); return $x[0] ?? ['n' => 0, 'v' => 0, 'fs' => 0, 'rt' => 0]; };
        $D['deliver'] = ['today' => $dv($T, $T), 'cycle' => $dv($c0, $T)];
    }

    try {
    } catch (Throwable $__e) { $D['warn'][] = 'Deliveries: ' . $__e->getMessage(); }   // one block failing does not stop the others
    /* ── COLLECTIONS (invoice payments) ── */
    if ($P['collect'] && $has('invoice_payments')) {
        $D['collect'] = ['today' => (float)$one("SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE is_reversed = 0 AND payment_date = '$T'"),
                         'cycle' => (float)$one("SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE is_reversed = 0 AND payment_date BETWEEN '$c0' AND '$T'"),
                         'prev_same' => (float)$one("SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE is_reversed = 0 AND payment_date BETWEEN '$p0' AND '$pTo'"),
                         'by_method' => $all("SELECT COALESCE(NULLIF(LOWER(payment_method),''),'other') AS k, ROUND(SUM(amount),2) AS v FROM invoice_payments WHERE is_reversed = 0 AND payment_date BETWEEN '$c0' AND '$T' GROUP BY k ORDER BY v DESC")];
        $d30 = date('Y-m-d', strtotime('-29 day')); $dm = [];
        foreach ($all("SELECT payment_date AS d, SUM(amount) AS v FROM invoice_payments WHERE is_reversed = 0 AND payment_date BETWEEN '$d30' AND '$T' GROUP BY payment_date") as $x) $dm[$x['d']] = (float)$x['v'];
        $D['daily_collect'] = $dm;
    }

    try {
    } catch (Throwable $__e) { $D['warn'][] = 'Collections: ' . $__e->getMessage(); }   // one block failing does not stop the others
    /* ── MARKET CREDIT: outstanding bills (Market Credit Report formula), ageing by days since delivery, overdue past the customer's policy days ── */
    if ($P['credit'] && $has('field_summary_details')) {
        $siid = $has('secondary_invoice_import_details') ? "LEFT JOIN (SELECT bill_no, delivery_date, MAX(final_bill_amount) AS f FROM secondary_invoice_import_details WHERE status = 'imported' GROUP BY bill_no, delivery_date) siid ON siid.bill_no = fsd.invoice_num AND siid.delivery_date = fs.delivery_date" : '';
        $cn = $has('credit_notes') ? "LEFT JOIN (SELECT field_summary_detail_id, SUM(amount) AS t FROM credit_notes WHERE is_deleted = 0 GROUP BY field_summary_detail_id) cn ON cn.field_summary_detail_id = fsd.id" : '';
        $rows = $all("SELECT fs.delivery_date AS d, UPPER(TRIM(fsd.t_code)) AS c, COALESCE(cu.credit_days, 0) AS cd,
                GREATEST(COALESCE(" . ($siid ? 'siid.f, ' : '') . "COALESCE(fsd.adjust_net_value, fsd.net_value), 0) - COALESCE(pay.t, 0) - " . ($cn ? 'COALESCE(cn.t, 0)' : '0') . ", 0) AS b
            FROM field_summary_details fsd INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id LEFT JOIN customers cu ON cu.t_code = fsd.t_code $siid
            LEFT JOIN (SELECT field_summary_detail_id, SUM(amount) AS t FROM invoice_payments WHERE is_reversed = 0 GROUP BY field_summary_detail_id) pay ON pay.field_summary_detail_id = fsd.id $cn
            WHERE fsd.updated = 1 HAVING b > 0.01");
        $ag = ['0–7 d' => 0, '8–14 d' => 0, '15–30 d' => 0, '31–60 d' => 0, '61–90 d' => 0, '90+ d' => 0]; $tot = 0; $ovd = 0; $cust = []; $now = strtotime($T);
        foreach ($rows as $x) { $b = (float)$x['b']; $age = (int)(($now - strtotime($x['d'])) / 86400); $tot += $b; $cust[$x['c']] = 1; if ($age > (int)$x['cd']) $ovd += $b;
            $ag[$age <= 7 ? '0–7 d' : ($age <= 14 ? '8–14 d' : ($age <= 30 ? '15–30 d' : ($age <= 60 ? '31–60 d' : ($age <= 90 ? '61–90 d' : '90+ d'))))] += $b; }
        $D['credit'] = ['outstanding' => round($tot, 2), 'overdue' => round($ovd, 2), 'customers' => count($cust), 'bills' => count($rows), 'ageing' => array_map(fn($k, $v) => ['k' => $k, 'v' => round($v, 2)], array_keys($ag), array_values($ag))];
    }

    try {
    } catch (Throwable $__e) { $D['warn'][] = 'Market credit: ' . $__e->getMessage(); }   // one block failing does not stop the others
    /* ── CHEQUES (same statuses as the Market Credit Report; Sampath cheques are not market credit) ── */
    if ($P['cheques'] && $has('cheques')) {
        $cols = []; foreach ($all("SHOW COLUMNS FROM cheques") as $x) $cols[$x['Field']] = 1;
        $sp = isset($cols['sampath']) ? "AND TRIM(COALESCE(sampath,'')) <> 'Sampath Cheque'" : '';
        $sb = isset($cols['sb_settled']) ? "(status = 'sent_back' AND COALESCE(sb_settled,0) = 0)" : "status = 'sent_back'";
        $cih = $all("SELECT COUNT(*) AS n, COALESCE(SUM(" . (isset($cols['sb_settlement_amount']) ? "CASE WHEN status = 'sent_back' THEN GREATEST(total_amount - COALESCE(sb_settlement_amount,0),0) ELSE total_amount END" : 'total_amount') . "),0) AS v
                     FROM cheques WHERE (status IN ('pending','to_be_bank','deposited') OR $sb) $sp")[0] ?? ['n' => 0, 'v' => 0];
        $ret = $all("SELECT COUNT(*) AS n, COALESCE(SUM(GREATEST(total_amount - " . (isset($cols['settlement_amount']) ? 'COALESCE(settlement_amount,0)' : '0') . ",0)),0) AS v FROM cheques
                     WHERE status IN ('returned','bounced') " . (isset($cols['return_settled']) ? 'AND COALESCE(return_settled,0) = 0' : '') . " $sp")[0] ?? ['n' => 0, 'v' => 0];
        $wk = date('Y-m-d', strtotime('+7 day'));
        $due = $all("SELECT ch.cheque_no, ch.cheque_date, ch.total_amount AS v, ch.bank_code, ch.t_code, COALESCE(cu.shop_name, ch.t_code) AS nm FROM cheques ch LEFT JOIN customers cu ON cu.t_code = ch.t_code
                     WHERE ch.status IN ('pending','to_be_bank') AND ch.cheque_date BETWEEN '$T' AND '$wk' " . str_replace('sampath', 'ch.sampath', $sp) . " ORDER BY ch.cheque_date, ch.total_amount DESC LIMIT 8");
        $D['cheques'] = ['in_hand' => $cih, 'returned' => $ret, 'due' => $due,
                         'due_total' => (float)$one("SELECT COALESCE(SUM(total_amount),0) FROM cheques WHERE status IN ('pending','to_be_bank') AND cheque_date BETWEEN '$T' AND '$wk' $sp")];
    }

    try {
    } catch (Throwable $__e) { $D['warn'][] = 'Cheques: ' . $__e->getMessage(); }   // one block failing does not stop the others
    /* ── CUSTOMERS & PEOPLE ── */
    if ($P['sales'] && $has('customers')) {
        $D['customers'] = ['total' => (int)$one("SELECT COUNT(*) FROM customers WHERE COALESCE(active,1) = 1"),
                           'new' => (int)$one("SELECT COUNT(*) FROM customers WHERE created_at >= '$c0'"),
                           'credit' => (int)$one("SELECT COUNT(*) FROM customers WHERE COALESCE(active,1) = 1 AND LOWER(payment_mode) IN ('credit','cheque')")];
        $a90 = date('Y-m-d', strtotime('-89 day'));
        if ($has('secondary_invoice_import_details')) $D['customers']['active90'] = (int)$one("SELECT COUNT(DISTINCT UPPER(TRIM(t_code))) FROM secondary_invoice_import_details WHERE t_code <> '' AND COALESCE(bill_date, delivery_date) >= '$a90'");
    }
    if ($P['people'] && $has('employees')) {
        $c = []; foreach ($all("SHOW COLUMNS FROM employees") as $x) $c[$x['Field']] = 1;
        $D['people'] = ['employees' => (int)$one("SELECT COUNT(*) FROM employees" . (isset($c['active']) ? ' WHERE active = 1' : (isset($c['status']) ? " WHERE status IN ('Permanent','Probation')" : '')))];
    }
    } catch (Throwable $__e) { $D['warn'][] = 'Customers / staff: ' . $__e->getMessage(); }   // one block failing does not stop the others
    $D['success'] = true;
    @file_put_contents($ck, json_encode($D, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    $out($D);
}
include 'header.php';
$__h = (int)date('G'); $__greet = $__h < 12 ? 'Good morning' : ($__h < 17 ? 'Good afternoon' : 'Good evening');
$__name = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? ($_SESSION['user_name'] ?? ''));
?>
<style>
.ydb{--ink:#0f172a;--mut:#64748b;--line:#e2e8f0;--c1:#4f46e5;--c2:#0ea5e9;--c3:#10b981;--c4:#f59e0b;--c5:#ef4444;--c6:#8b5cf6;--c7:#14b8a6;--c8:#ec4899;font-family:'Inter',system-ui,sans-serif;color:var(--ink);padding:4px 2px 10px}
.ydb .hero{position:relative;overflow:hidden;border-radius:20px;padding:24px 28px;color:#fff;background:radial-gradient(1200px 300px at 85% -40%,rgba(255,255,255,.18),transparent 60%),linear-gradient(120deg,#1e1b4b 0%,#312e81 38%,#4f46e5 70%,#0ea5e9 100%);box-shadow:0 18px 40px -18px rgba(49,46,129,.7);margin-bottom:18px}
.ydb .hero:after{content:'';position:absolute;right:-60px;bottom:-90px;width:300px;height:300px;border-radius:50%;background:rgba(255,255,255,.07)}
.ydb .hero h1{margin:0;font-size:24px;font-weight:800;letter-spacing:-.3px}.ydb .hero p{margin:6px 0 0;opacity:.85;font-size:13.5px}
.ydb .hero .hr{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px;position:relative;z-index:1}
.ydb .chipw{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22);border-radius:99px;padding:6px 13px;font-size:12.5px;font-weight:600;backdrop-filter:blur(4px)}
.ydb .rbtn{margin-left:auto;background:#fff;color:#312e81;border:none;border-radius:11px;padding:9px 16px;font-weight:800;font-size:13px;cursor:pointer;display:inline-flex;gap:7px;align-items:center}
.ydb .grid{display:grid;gap:14px}.ydb .g4{grid-template-columns:repeat(auto-fit,minmax(min(100%,215px),1fr))}.ydb .g2{grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr))}.ydb .g3{grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr))}
.ydb .kpi{position:relative;background:#fff;border:1px solid var(--line);border-radius:16px;padding:15px 16px 14px;box-shadow:0 6px 18px -12px rgba(15,23,42,.25);overflow:hidden;min-height:118px}
.ydb .kpi:before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--k,#4f46e5)}
.ydb .kpi .ic{position:absolute;right:14px;top:14px;width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;background:color-mix(in srgb,var(--k,#4f46e5) 13%,#fff);color:var(--k,#4f46e5);font-size:16px}
.ydb .kpi .lb{font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:var(--mut)}
.ydb .kpi .vl{font-size:23px;font-weight:800;margin-top:6px;letter-spacing:-.4px;font-variant-numeric:tabular-nums}
.ydb .kpi .sb{font-size:12px;color:var(--mut);margin-top:5px;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.ydb .up{color:#059669;font-weight:800}.ydb .dn{color:#dc2626;font-weight:800}
.ydb .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 18px;box-shadow:0 6px 18px -12px rgba(15,23,42,.25);min-width:0}
.ydb .ct{display:flex;align-items:center;gap:8px;font-weight:800;font-size:14px;margin-bottom:10px}.ydb .ct small{font-weight:600;color:var(--mut);font-size:12px}.ydb .ct a{margin-left:auto;font-size:12px;color:#4f46e5;text-decoration:none;font-weight:700}
.ydb .sec{display:flex;align-items:center;gap:10px;margin:22px 0 10px;font-weight:800;font-size:15px;color:#1e1b4b}.ydb .sec:after{content:'';flex:1;height:1px;background:var(--line)}
.ydb svg text{font-family:'Inter',system-ui,sans-serif}
.ydb .hb{display:grid;grid-template-columns:minmax(0,1.3fr) 2fr auto;gap:10px;align-items:center;font-size:12.5px;margin:7px 0}
.ydb .hb .nm{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ydb .hb .tr{height:10px;border-radius:99px;background:#f1f5f9;overflow:hidden}.ydb .hb .tr i{display:block;height:100%;border-radius:99px}
.ydb .hb b{font-variant-numeric:tabular-nums}
.ydb .lg{display:flex;flex-wrap:wrap;gap:6px 14px;font-size:12px;color:#334155}.ydb .lg span{display:inline-flex;gap:6px;align-items:center}.ydb .lg i{width:10px;height:10px;border-radius:3px;display:inline-block}
.ydb table.lt{width:100%;border-collapse:collapse;font-size:12.5px}.ydb table.lt td{padding:7px 4px;border-bottom:1px solid #f1f5f9}.ydb table.lt td.r{text-align:right;font-variant-numeric:tabular-nums;font-weight:700}
.ydb .sk{background:linear-gradient(90deg,#f1f5f9,#e2e8f0,#f1f5f9);background-size:200% 100%;animation:yk 1.2s infinite;border-radius:8px;height:14px;margin:8px 0}@keyframes yk{to{background-position:-200% 0}}
.ydb .ql{display:flex;flex-wrap:wrap;gap:8px}.ydb .ql a{display:inline-flex;gap:7px;align-items:center;padding:9px 13px;border-radius:12px;border:1px solid var(--line);background:#fff;text-decoration:none;color:#1e1b4b;font-weight:700;font-size:12.5px}.ydb .ql a:hover{border-color:#a5b4fc;background:#eef2ff}
.ydb .empty{color:#94a3b8;font-size:12.5px;padding:14px 0;text-align:center}
.ydb .err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px;padding:12px 14px;font-size:13px}
</style>
<div class="ydb">
  <div class="hero">
    <h1><?= htmlspecialchars($__greet) ?><?= $__name !== '' ? ', ' . htmlspecialchars($__name) : '' ?> 👋</h1>
    <p>YELO GROUP OF COMPANIES — live business overview from the system</p>
    <div class="hr"><span class="chipw"><i class="fa-regular fa-calendar"></i> <?= date('l, d F Y') ?></span><span class="chipw" id="dbCyc">Sales cycle …</span><span class="chipw" id="dbAt">Loading…</span>
      <button class="rbtn" type="button" id="dbRef"><i class="fa-solid fa-rotate"></i> Refresh</button></div>
  </div>
  <?php if (function_exists('ql_home_section')) ql_home_section(); /* ⚡ my quick shortcuts */ ?>
  <div id="dbBody"><div class="grid g4"><?php for ($i = 0; $i < 8; $i++): ?><div class="kpi"><div class="sk" style="width:50%"></div><div class="sk" style="width:80%;height:24px"></div><div class="sk" style="width:60%"></div></div><?php endfor; ?></div></div>
</div>
<script>
(function(){
  var $=function(i){return document.getElementById(i);}, PAL=['#4f46e5','#0ea5e9','#10b981','#f59e0b','#ef4444','#8b5cf6','#14b8a6','#ec4899','#64748b'];
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
  function n0(v){ return Math.round(+v||0).toLocaleString('en-US'); }
  function rs(v){ v=+v||0; var a=Math.abs(v); return (v<0?'-':'')+(a>=1e7?(a/1e6).toFixed(1)+' M':(a>=1e5?(a/1e5).toFixed(2)+' Lk':n0(a))); }
  function pct(a,b){ if(!b) return ''; var p=(a-b)/b*100; return '<span class="'+(p>=0?'up':'dn')+'">'+(p>=0?'▲ ':'▼ ')+Math.abs(p).toFixed(1)+'%</span>'; }
  function kpi(lb,vl,sb,ic,col,href){ return '<'+(href?'a href="'+href+'" style="text-decoration:none;color:inherit"':'div')+' class="kpi" style="--k:'+col+'"><div class="ic"><i class="fa-solid '+ic+'"></i></div><div class="lb">'+lb+'</div><div class="vl">'+vl+'</div><div class="sb">'+(sb||'&nbsp;')+'</div></'+(href?'a':'div')+'>'; }
  /* ── small SVG charts (no outside library needed) ── */
  function bars(series,opt){ opt=opt||{}; var W=opt.w||640,H=opt.h||220,L=46,B=26,T=12,R=10, ks=series[0].data.map(function(d){return d.k;}), n=ks.length; if(!n) return '<div class="empty">No data</div>';
    var mx=0; series.forEach(function(s){ s.data.forEach(function(d){ mx=Math.max(mx,+d.v||0); }); }); mx=mx||1; var step=Math.pow(10,Math.floor(Math.log10(mx))), top=Math.ceil(mx/step)*step;
    var cw=(W-L-R)/n, y=function(v){ return T+(H-T-B)*(1-v/top); }, g='';
    for(var i=0;i<=4;i++){ var v=top*i/4, yy=y(v); g+='<line x1="'+L+'" x2="'+(W-R)+'" y1="'+yy+'" y2="'+yy+'" stroke="#eef2f7"/><text x="'+(L-6)+'" y="'+(yy+4)+'" font-size="10" text-anchor="end" fill="#94a3b8">'+rs(v)+'</text>'; }
    var bs=series.filter(function(s){return s.type!=='line';}), bw=Math.max(3,(cw*0.72)/Math.max(1,bs.length));
    bs.forEach(function(s,si){ s.data.forEach(function(d,i){ var v=+d.v||0, x=L+i*cw+(cw-bw*bs.length)/2+si*bw, yy=y(v); g+='<rect x="'+x.toFixed(1)+'" y="'+yy.toFixed(1)+'" width="'+(bw-1).toFixed(1)+'" height="'+Math.max(0,H-B-yy).toFixed(1)+'" rx="3" fill="'+s.c+'" opacity="'+(s.op||1)+'"><title>'+esc(d.k)+' · '+esc(s.n)+': '+n0(v)+'</title></rect>'; }); });
    series.filter(function(s){return s.type==='line';}).forEach(function(s){ var p=s.data.map(function(d,i){ return (L+i*cw+cw/2).toFixed(1)+','+y(+d.v||0).toFixed(1); }); g+='<polyline points="'+p.join(' ')+'" fill="none" stroke="'+s.c+'" stroke-width="2.5" stroke-linejoin="round"/>'+s.data.map(function(d,i){ return '<circle cx="'+(L+i*cw+cw/2).toFixed(1)+'" cy="'+y(+d.v||0).toFixed(1)+'" r="3" fill="#fff" stroke="'+s.c+'" stroke-width="2"><title>'+esc(d.k)+' · '+esc(s.n)+': '+n0(d.v)+'</title></circle>'; }).join(''); });
    var ev=Math.ceil(n/(opt.lbl||12)); ks.forEach(function(k,i){ if(i%ev===0||i===n-1) g+='<text x="'+(L+i*cw+cw/2).toFixed(1)+'" y="'+(H-8)+'" font-size="10" text-anchor="middle" fill="#64748b">'+esc(k)+'</text>'; });
    return '<svg viewBox="0 0 '+W+' '+H+'" width="100%" role="img">'+g+'</svg>'+(series.length>1?'<div class="lg">'+series.map(function(s){return '<span><i style="background:'+s.c+'"></i>'+esc(s.n)+'</span>';}).join('')+'</div>':''); }
  function donut(items,center){ var t=items.reduce(function(a,b){return a+(+b.v||0);},0); if(!t) return '<div class="empty">No data</div>'; var a0=-Math.PI/2, g='', R=78, r=50, cx=100, cy=100;
    items.forEach(function(it,i){ var f=(+it.v||0)/t; if(f<=0) return; var a1=a0+f*Math.PI*2-(f>=.999?.0001:0), lg=f>.5?1:0, p=function(a,rr){ return (cx+rr*Math.cos(a)).toFixed(2)+','+(cy+rr*Math.sin(a)).toFixed(2); };
      g+='<path d="M'+p(a0,R)+' A'+R+','+R+' 0 '+lg+' 1 '+p(a1,R)+' L'+p(a1,r)+' A'+r+','+r+' 0 '+lg+' 0 '+p(a0,r)+' Z" fill="'+PAL[i%PAL.length]+'"><title>'+esc(it.k)+': '+n0(it.v)+' ('+(f*100).toFixed(1)+'%)</title></path>'; a0=a1; });
    return '<div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap"><svg viewBox="0 0 200 200" width="180" height="180">'+g+'<text x="100" y="96" text-anchor="middle" font-size="11" fill="#64748b">'+esc(center||'Total')+'</text><text x="100" y="114" text-anchor="middle" font-size="15" font-weight="800" fill="#0f172a">'+rs(t)+'</text></svg><div class="lg" style="flex-direction:column;flex:1;min-width:160px">'
      +items.map(function(it,i){ return '<span style="justify-content:space-between;width:100%"><span><i style="background:'+PAL[i%PAL.length]+'"></i> '+esc(it.k)+'</span><b>'+((+it.v||0)/t*100).toFixed(1)+'%</b></span>'; }).join('')+'</div></div>'; }
  function hbars(items,col,sub){ if(!items.length) return '<div class="empty">No data</div>'; var mx=Math.max.apply(null,items.map(function(x){return +x.v||0;}))||1;
    return items.map(function(x,i){ return '<div class="hb"><span class="nm" title="'+esc(x.t||x.n)+'"><b style="color:#94a3b8;margin-right:5px">'+(i+1)+'</b>'+esc(x.n)+(sub?'<small style="color:#94a3b8"> '+esc(sub(x))+'</small>':'')+'</span><span class="tr"><i style="width:'+Math.max(2,(+x.v||0)/mx*100)+'%;background:'+(typeof col==='function'?col(i):col)+'"></i></span><b>'+rs(x.v)+'</b></div>'; }).join(''); }
  function days30(){ var o=[]; for(var i=29;i>=0;i--){ var d=new Date(); d.setDate(d.getDate()-i); var k=d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); o.push(k); } return o; }
  function render(d){
    var h='', k=[], P=d.perm||{};
    $('dbCyc').innerHTML='<i class="fa-solid fa-arrows-rotate"></i> Sales cycle '+esc(d.cycle.label)+' · '+esc(d.cycle.from)+' → '+esc(d.cycle.to);
    $('dbAt').innerHTML='<i class="fa-regular fa-clock"></i> '+(d.cached?'Updated ':'Live · ')+esc(d.as_at);
    if(d.sales){ var s=d.sales; k.push(kpi('Sales this cycle','Rs. '+rs(s.cycle),pct(s.cycle,s.prev_same)+' vs '+esc(d.cycle.prev)+' same days','fa-chart-line','#4f46e5','historical_sales_trend_report.php'));
      k.push(kpi('Sales today','Rs. '+rs(s.today),'Avg / day this cycle Rs. '+rs(s.avg_day),'fa-cash-register','#0ea5e9')); k.push(kpi('Bills · outlets billed',n0(s.bills)+' <small style="font-size:14px;color:#64748b">· '+n0(s.outlets)+'</small>','this cycle','fa-receipt','#8b5cf6')); }
    if(d.collect){ var c=d.collect; k.push(kpi('Collections this cycle','Rs. '+rs(c.cycle),pct(c.cycle,c.prev_same)+' · today Rs. '+rs(c.today),'fa-hand-holding-dollar','#10b981')); }
    if(d.credit){ var cr=d.credit; k.push(kpi('Market credit (bills)','Rs. '+rs(cr.outstanding),n0(cr.customers)+' customers · '+n0(cr.bills)+' bills','fa-file-invoice-dollar','#f59e0b','market_credit_report.php'));
      k.push(kpi('Credit overdue','Rs. '+rs(cr.overdue),(cr.outstanding?(cr.overdue/cr.outstanding*100).toFixed(1):0)+'% of outstanding · past policy days','fa-hourglass-end','#ef4444','credit_aging_report.php')); }
    if(d.cheques){ var q=d.cheques; k.push(kpi('Cheques in hand','Rs. '+rs(q.in_hand.v),n0(q.in_hand.n)+' cheques · due 7 days Rs. '+rs(q.due_total),'fa-money-check','#14b8a6'));
      k.push(kpi('Returned cheques','Rs. '+rs(q.returned.v),n0(q.returned.n)+' not settled','fa-rotate-left','#ec4899')); }
    if(d.deliver){ var dv=d.deliver; k.push(kpi('Deliveries today',n0(dv.today.n)+' bills','Rs. '+rs(dv.today.v)+' · '+n0(dv.today.rt)+' routes','fa-truck-fast','#0284c7')); }
    if(d.customers){ var cu=d.customers; k.push(kpi('Customers',n0(cu.total),(cu.active90!=null?n0(cu.active90)+' bought in 90 days · ':'')+n0(cu.new)+' new this cycle','fa-store','#6366f1','customers.php')); }
    if(d.people){ k.push(kpi('Employees',n0(d.people.employees),'active','fa-users','#64748b','employees.php')); }
    h+='<div class="grid g4">'+(k.join('')||'<div class="card">No figures for your role yet.</div>')+'</div>';
    if(d.daily_sales||d.daily_collect){ var ds=days30(), lab=function(x){ return x.slice(8)+'/'+x.slice(5,7); }, ser=[];
      if(d.daily_sales) ser.push({n:'Sales',c:'#4f46e5',data:ds.map(function(x){return {k:lab(x),v:d.daily_sales[x]||0};})});
      if(d.daily_collect) ser.push({n:'Collections',c:'#10b981',type:'line',data:ds.map(function(x){return {k:lab(x),v:d.daily_collect[x]||0};})});
      h+='<div class="sec"><i class="fa-solid fa-wave-square"></i> Last 30 days</div><div class="card"><div class="ct">Daily sales'+(d.daily_collect?' &amp; collections':'')+' <small>Rs.</small></div>'+bars(ser,{w:1280,h:250,lbl:15})+'</div>'; }
    if(d.sales){ h+='<div class="sec"><i class="fa-solid fa-chart-column"></i> Sales</div><div class="grid g2">'
      +'<div class="card"><div class="ct">Sales by cycle <small>last 12 cycles</small><a href="historical_sales_trend_report.php">Trends →</a></div>'+bars([{n:'Sales',c:'#6366f1',data:d.sales.cycles}],{h:230})+'</div>'
      +'<div class="card"><div class="ct">Sales by channel <small>this cycle</small><a href="plan_channel_performance.php">Channels →</a></div>'+donut(d.channels||[],'This cycle')+'</div>'
      +'<div class="card"><div class="ct">Top customers <small>this cycle</small></div>'+hbars((d.top_customers||[]).map(function(x){return {n:x.n,t:x.c+' · '+x.n,v:x.v};}),function(i){return PAL[i%PAL.length];})+'</div>'
      +'<div class="card"><div class="ct">Top sales reps <small>this cycle</small><a href="plan_sr_action_plans.php">SR plans →</a></div>'+hbars((d.top_reps||[]).map(function(x){return {n:x.c+(x.n?' · '+x.n:''),v:x.v,o:x.o};}),'linear-gradient(90deg,#0ea5e9,#4f46e5)',function(x){return x.o+' outlets';})+'</div></div>'; }
    if(d.credit||d.cheques||d.collect){ h+='<div class="sec"><i class="fa-solid fa-scale-balanced"></i> Credit &amp; collections</div><div class="grid g3">';
      if(d.credit){ var ac=['#10b981','#84cc16','#f59e0b','#f97316','#ef4444','#7f1d1d'], at=d.credit.ageing.reduce(function(a,b){return a+b.v;},0)||1;
        h+='<div class="card"><div class="ct">Outstanding by age <small>days since delivery</small><a href="credit_aging_report.php">Ageing →</a></div><div style="display:flex;height:16px;border-radius:8px;overflow:hidden;margin:6px 0 12px">'+d.credit.ageing.map(function(a,i){ return '<i title="'+esc(a.k)+': '+n0(a.v)+'" style="width:'+(a.v/at*100)+'%;background:'+ac[i]+'"></i>'; }).join('')+'</div>'
          +hbars(d.credit.ageing.map(function(a){return {n:a.k,v:a.v};}),function(i){return ac[i];})+'</div>'; }
      if(d.collect){ h+='<div class="card"><div class="ct">Collections by method <small>this cycle</small></div>'+donut((d.collect.by_method||[]).map(function(x){return {k:x.k.charAt(0).toUpperCase()+x.k.slice(1),v:+x.v};}),'Collected')+'</div>'; }
      if(d.cheques){ h+='<div class="card"><div class="ct">Cheques due in 7 days <small>Rs. '+rs(d.cheques.due_total)+'</small></div>'+(d.cheques.due.length?'<table class="lt">'+d.cheques.due.map(function(x){ return '<tr><td><b>'+esc((x.cheque_date||'').slice(8)+'/'+(x.cheque_date||'').slice(5,7))+'</b></td><td>'+esc(x.nm)+'<div style="color:#94a3b8;font-size:11px">'+esc(x.cheque_no)+' · '+esc(x.bank_code||'')+'</div></td><td class="r">'+n0(x.v)+'</td></tr>'; }).join('')+'</table>':'<div class="empty">No cheques due this week</div>')+'</div>'; }
      h+='</div>'; }
    var L=[['vendor_dashboard.php','fa-gauge-high','Vendor Ledger Dashboard',P.vendor],['customer_claim_report.php','fa-file-circle-check','Customer Claim Report',P.vendor],['market_credit_report.php','fa-store','Market Credit',P.credit],['credit_aging_report.php','fa-hourglass-half','Credit Ageing',P.credit],['historical_sales_trend_report.php','fa-chart-line','Sales Trends',P.sales],['plan_executive_dashboard.php','fa-chess','Planning Dashboard',P.sales],['plan_credit_policy_report.php','fa-scale-balanced','Credit Policy',P.credit]];
    var ql=L.filter(function(x){return x[3];}); if(ql.length) h+='<div class="sec"><i class="fa-solid fa-bolt"></i> Quick links</div><div class="ql">'+ql.map(function(x){return '<a href="'+x[0]+'"><i class="fa-solid '+x[1]+'" style="color:#4f46e5"></i> '+x[2]+'</a>';}).join('')+'</div>';
    if(d.warn&&d.warn.length) h='<div class="err" style="margin-bottom:12px;background:#fffbeb;border-color:#fde68a;color:#92400e"><i class="fa-solid fa-circle-info"></i> Some figures could not be read: '+d.warn.map(esc).join(' · ')+'</div>'+h;
    $('dbBody').innerHTML=h; }
  function load(refresh){ $('dbAt').textContent='Loading…'; var ctl=window.AbortController?new AbortController():null, tm=setTimeout(function(){ if(ctl) ctl.abort(); },90000);
    fetch('index.php?ajax=dash'+(refresh?'&refresh=1':''),{credentials:'same-origin',signal:ctl?ctl.signal:undefined}).then(function(r){ return r.text().then(function(t){ clearTimeout(tm);
        try{ return JSON.parse(t); }catch(e){ var m=t.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim(); throw new Error(r.status>=400?'the server answered HTTP '+r.status+(m?' — '+m.slice(0,300):''):(m?m.slice(0,300):'empty answer (you may need to log in again)')); } }); }).then(function(d){ if(!d.success) throw new Error(d.message||'Could not load'); render(d); })
      .catch(function(e){ clearTimeout(tm); $('dbBody').innerHTML='<div class="err"><i class="fa-solid fa-triangle-exclamation"></i> The dashboard figures could not be loaded: '+esc(e.name==='AbortError'?'it took longer than 90 seconds.':e.message)+' <button type="button" onclick="document.getElementById(\'dbRef\').click()" style="margin-left:8px;border:1px solid #fecaca;background:#fff;color:#991b1b;border-radius:8px;padding:4px 10px;font-weight:700;cursor:pointer">Retry</button></div>'; $('dbAt').textContent='—'; }); }
  $('dbRef').onclick=function(){ load(true); }; load(false);
})();
</script>
<?php include 'footer.php'; ?>
