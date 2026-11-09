<?php
/**
 * customer_invoice_average_report.php
 * ─────────────────────────────────────────────────────────────────────
 * CREDIT LIMIT VIOLATION REPORT  (by customer T Code)
 *
 * Invoice value = the Ikea value of the invoice (one value per bill + delivery date),
 * else the adjusted / net value — the SAME rule as the Invoice List and the Customer Ledger.
 * Only invoices with a value above 0 are counted.
 *
 * For the selected month M (default = this month):
 *   BILL VALUE
 *     Last 6 Months Average : (Ikea total of M-6 .. M-1) / 6   + invoice count
 *     Last 3 Months Average : (Ikea total of M-3 .. M-1) / 3   + invoice count
 *     Last month (M-1)      : total + invoice count            (e.g. in November → October)
 *     Current month (M)     : total + invoice count            (1st of M up to today)
 *   BILL COUNT  (value ÷ bill count = average bill value)
 *     Last 6 months, Last 3 months, Last month, Current month
 *
 * Abnormal movement (row colour): the selected comparison moves more than the threshold %
 *   DROP     below the average by threshold % or more
 *   SPIKE    above the average by threshold % or more
 *   STOPPED  had an average but nothing in the compared month
 *   NEW      no invoices in the last 6 months, but has invoices now
 * Current month is compared at its PACE (total ÷ days passed × days in month), so a month
 * that has just started is not shown as a drop.
 *
 * Click a customer → every invoice of the 7 months, grouped by month, with links to the invoice.
 * Filters (instant): search, route, status, comparison, threshold, 6M / 3M average range,
 * minimum 6M invoice count. Export: CSV (opens in Excel) of the filtered rows.
 *
 * Popup: moved to <body> on load, so the site header can no longer cover it.
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');
include 'config.php';
if (file_exists(__DIR__ . '/auth.php')) include_once __DIR__ . '/auth.php';

function cia_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function cia_q($conn, $sql) { try { return @mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function cia_get($k, $d = '') { return (isset($_GET[$k]) && is_string($_GET[$k])) ? trim($_GET[$k]) : $d; }

/* ── the periods for a month ── */
function cia_periods($ym) {
    if (!preg_match('/^\d{4}-\d{2}$/', $ym) || strtotime($ym . '-01') === false) $ym = date('Y-m');
    if ($ym > date('Y-m')) $ym = date('Y-m');
    $m0     = $ym . '-01';
    $isCur  = ($ym === date('Y-m'));
    $mEnd   = date('Y-m-t', strtotime($m0));
    $cutoff = $isCur ? date('Y-m-d') : $mEnd;
    $months = [];                                   /* M-6 .. M-1 */
    for ($i = 6; $i >= 1; $i--) $months[] = date('Y-m', strtotime("$m0 -$i month"));
    return [
        'ym'       => $ym,
        'cur'      => $ym,
        'prev'     => $months[5],
        'last3'    => array_slice($months, 3),
        'last6'    => $months,
        'from'     => $months[0] . '-01',
        'to'       => $cutoff,
        'days_in'  => (int)date('t', strtotime($m0)),
        'days_gone'=> (int)date('j', strtotime($cutoff)),
        'is_cur'   => $isCur,
    ];
}

/* invoice value expression + join (Ikea value per bill + delivery date, else adjusted / net value) */
function cia_value_sql($conn, $from, $to) {
    $f = mysqli_real_escape_string($conn, $from);
    $t = mysqli_real_escape_string($conn, $to);
    $join = "LEFT JOIN (SELECT bill_no, delivery_date, MAX(final_bill_amount) AS ikea
                        FROM secondary_invoice_import_details
                        WHERE status = 'imported' AND delivery_date BETWEEN '$f' AND '$t'
                        GROUP BY bill_no, delivery_date) ik
                   ON ik.bill_no = fsd.invoice_num AND ik.delivery_date = fs.delivery_date";
    $val  = "COALESCE(NULLIF(ik.ikea,0), fsd.adjust_net_value, fsd.net_value, 0)";
    return [$join, $val];
}

$P = cia_periods(cia_get('month', date('Y-m')));

/* ════════════════════════════════════════
   AJAX: invoices of one customer
════════════════════════════════════════ */
if (cia_get('ajax') === 'invoices') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $tc = mysqli_real_escape_string($conn, cia_get('tc'));
    if ($tc === '') { echo json_encode(['error' => 'No customer']); exit; }
    [$join, $val] = cia_value_sql($conn, $P['from'], $P['to']);
    $sql = "SELECT fsd.id, fsd.invoice_num, fs.delivery_date, fs.sr_code, fs.field_summary_code,
                   COALESCE(rt.route_name, fsd.route, fs.route) AS route_name,
                   COALESCE(ik.ikea,0) AS ikea, COALESCE(fsd.adjust_net_value, fsd.net_value, 0) AS net, $val AS val
            FROM field_summary_details fsd
            JOIN field_summary fs ON fs.id = fsd.field_summary_id
            LEFT JOIN routes rt ON rt.route_code = COALESCE(NULLIF(fsd.route,''), fs.route)
            $join
            WHERE fsd.t_code = '$tc' AND fs.delivery_date BETWEEN '{$P['from']}' AND '{$P['to']}'
            ORDER BY fs.delivery_date DESC, fsd.invoice_num";
    $r = cia_q($conn, $sql);
    if (!$r) { echo json_encode(['error' => 'Could not read the invoices: ' . mysqli_error($conn)]); exit; }
    $rows = [];
    while ($x = mysqli_fetch_assoc($r)) {
        $rows[] = ['id' => (int)$x['id'], 'inv' => $x['invoice_num'], 'date' => $x['delivery_date'], 'sr' => $x['sr_code'],
                   'fs' => $x['field_summary_code'], 'route' => $x['route_name'], 'ikea' => round((float)$x['ikea'], 2),
                   'net' => round((float)$x['net'], 2), 'val' => round((float)$x['val'], 2)];
    }
    echo json_encode(['invoices' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ════════════════════════════════════════
   MAIN DATA — one row per customer per month
════════════════════════════════════════ */
@set_time_limit(300);
$t0 = microtime(true);
$error = '';
[$join, $val] = cia_value_sql($conn, $P['from'], $P['to']);
$sql = "SELECT t_code, ym, COUNT(*) AS cnt, SUM(v) AS tot FROM (
            SELECT TRIM(fsd.t_code) AS t_code, DATE_FORMAT(fs.delivery_date,'%Y-%m') AS ym, $val AS v
            FROM field_summary_details fsd
            JOIN field_summary fs ON fs.id = fsd.field_summary_id
            $join
            WHERE fs.delivery_date BETWEEN '{$P['from']}' AND '{$P['to']}' AND COALESCE(fsd.t_code,'') <> ''
        ) x WHERE v > 0.005 GROUP BY t_code, ym";
$res = cia_q($conn, $sql);
$byTc = [];
if (!$res) {
    $error = 'Could not read the invoices: ' . mysqli_error($conn);
} else {
    while ($x = mysqli_fetch_row($res)) {
        $byTc[$x[0]][$x[1]] = [(int)$x[2], (float)$x[3]];
    }
    mysqli_free_result($res);
}

/* customer names + routes */
$names = []; $croute = []; $paymode = [];
if ($byTc) {
    foreach (array_chunk(array_keys($byTc), 1000) as $part) {
        $in = implode(',', array_map(function ($t) use ($conn) { return "'" . mysqli_real_escape_string($conn, $t) . "'"; }, $part));
        $r = cia_q($conn, "SELECT t_code, shop_name, payment_mode FROM customers WHERE t_code IN ($in)");
        if ($r) while ($x = mysqli_fetch_row($r)) {
            if (trim((string)$x[1]) !== '') $names[trim($x[0])] = $x[1];
            $paymode[trim($x[0])] = strtolower(trim((string)$x[2]));
        }
        /* latest invoice of the period → name (if not a customer record) and route */
        $r = cia_q($conn, "SELECT TRIM(fsd.t_code), fsd.customer_name, COALESCE(NULLIF(fsd.route,''), fs.route)
                           FROM field_summary_details fsd JOIN field_summary fs ON fs.id = fsd.field_summary_id
                           JOIN (SELECT MAX(fsd2.id) AS mid FROM field_summary_details fsd2
                                 JOIN field_summary fs2 ON fs2.id = fsd2.field_summary_id
                                 WHERE fsd2.t_code IN ($in) AND fs2.delivery_date BETWEEN '{$P['from']}' AND '{$P['to']}'
                                 GROUP BY fsd2.t_code) z ON z.mid = fsd.id");
        if ($r) while ($x = mysqli_fetch_row($r)) {
            if (!isset($names[$x[0]]) && trim((string)$x[1]) !== '') $names[$x[0]] = $x[1];
            $croute[$x[0]] = (string)$x[2];
        }
    }
}
$routes = [];
$rr = cia_q($conn, "SELECT route_code, route_name FROM routes ORDER BY route_name");
if ($rr) while ($x = mysqli_fetch_row($rr)) $routes[$x[0]] = $x[1];

/* rows */
$rows = [];
foreach ($byTc as $tc => $m) {
    $g = function ($ym) use ($m) { return $m[$ym] ?? [0, 0.0]; };
    $c6 = 0; $t6 = 0; foreach ($P['last6'] as $ym) { [$c, $t] = $g($ym); $c6 += $c; $t6 += $t; }
    $c3 = 0; $t3 = 0; foreach ($P['last3'] as $ym) { [$c, $t] = $g($ym); $c3 += $c; $t3 += $t; }
    [$cp, $tp] = $g($P['prev']);
    [$cc, $tc_] = $g($P['cur']);
    $spark = [];
    foreach ($P['last6'] as $ym) $spark[] = round($g($ym)[1], 2);
    $spark[] = round($tc_, 2);
    $rc = $croute[$tc] ?? '';
    $rows[] = [
        'tc' => $tc, 'name' => (string)($names[$tc] ?? ''), 'pay' => (string)($paymode[$tc] ?? ''), 'route' => $rc, 'route_name' => $routes[$rc] ?? $rc,
        'a6' => round($t6 / 6, 2), 'c6' => $c6, 't6' => round($t6, 2),
        'a3' => round($t3 / 3, 2), 'c3' => $c3, 't3' => round($t3, 2),
        'tp' => round($tp, 2), 'cp' => $cp,
        'tc_' => round($tc_, 2), 'cc' => $cc,
        'm' => $spark,
    ];
}
usort($rows, function ($a, $b) { return $b['a6'] <=> $a['a6']; });
$secs = round(microtime(true) - $t0, 1);

$monthLbl = function ($ym) { return date('M Y', strtotime($ym . '-01')); };
$monthShort = function ($ym) { return date('M', strtotime($ym . '-01')); };
$META = [
    'cur' => $monthLbl($P['cur']), 'prev' => $monthLbl($P['prev']),
    'r6'  => $monthShort($P['last6'][0]) . ' – ' . $monthLbl($P['last6'][5]),
    'r3'  => $monthShort($P['last3'][0]) . ' – ' . $monthLbl($P['last3'][2]),
    'months' => array_map($monthShort, array_merge($P['last6'], [$P['cur']])),
    'yms' => array_merge($P['last6'], [$P['cur']]),
    'days_in' => $P['days_in'], 'days_gone' => $P['days_gone'], 'is_cur' => $P['is_cur'],
    'to' => date('d M Y', strtotime($P['to'])),
];

$page_title = 'Credit Limit Violation Report';
include 'header.php';
?>
<style>
.cia{font-family:'Inter',system-ui,sans-serif;color:#111827;}
.cia h1{font-size:21px;margin:0 0 3px;display:flex;align-items:center;gap:9px;} .cia h1 i{color:#dc2626;}
.cia .sub{color:#6b7280;font-size:12.5px;margin:0 0 14px;line-height:1.55;}
.cia .card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;margin-bottom:14px;}
.cia .flt{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px 12px;align-items:end;}
.cia .flt label{display:block;font-size:11px;font-weight:700;color:#4b5563;margin-bottom:4px;text-transform:uppercase;letter-spacing:.02em;}
.cia .flt input,.cia .flt select{width:100%;box-sizing:border-box;padding:7px 9px;border:1px solid #d1d5db;border-radius:7px;font:inherit;font-size:13px;background:#fff;}
.cia .flt .pair{display:flex;gap:6px;} .cia .flt .pair input{min-width:0;}
.cia .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:7px;border:1px solid #d1d5db;background:#fff;color:#111827;font:inherit;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap;}
.cia .btn.pri{background:#2563eb;border-color:#2563eb;color:#fff;} .cia .btn.grn{background:#16a34a;border-color:#16a34a;color:#fff;}
.cia .btn:hover{filter:brightness(.96);}
.cia .err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:8px 12px;font-size:12.5px;margin-bottom:12px;}
.cia .chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;}
.cia .chip{display:inline-flex;align-items:center;gap:7px;border:1px solid #e5e7eb;background:#fff;border-radius:20px;padding:6px 13px;font-size:12.5px;font-weight:600;cursor:pointer;color:#374151;}
.cia .chip b{font-variant-numeric:tabular-nums;}
.cia .chip .dot{width:10px;height:10px;border-radius:50%;}
.cia .chip.on{border-color:#111827;box-shadow:0 0 0 1px #111827 inset;}
.cia .tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;}
.cia .tools .meta{margin-left:auto;font-size:12px;color:#6b7280;}
.cia .tw{overflow:auto;border:1px solid #e5e7eb;border-radius:10px;background:#fff;max-height:72vh;}
.cia table.main{width:100%;border-collapse:separate;border-spacing:0;font-size:12.5px;--h1:30px;}
.cia table.main th{background:#f8fafc;color:#475569;font-size:10.5px;text-transform:uppercase;letter-spacing:.03em;text-align:left;padding:8px 9px;border-bottom:1px solid #e5e7eb;position:sticky;top:0;z-index:2;cursor:pointer;user-select:none;vertical-align:bottom;line-height:1.35;}
.cia table.main thead tr.h2 th{top:var(--h1);z-index:1;}
.cia table.main th.grp{text-align:center;cursor:default;font-size:11px;font-weight:800;letter-spacing:.06em;vertical-align:middle;border-left:2px solid #cbd5e1;border-right:2px solid #cbd5e1;}
.cia table.main th.grp.val{background:#eff6ff;color:#1d4ed8;}
.cia table.main th.grp.bc{background:#f0fdf4;color:#15803d;}
/* balanced column widths */
.cia table.main{min-width:1500px;}
.cia table.main th.r,.cia table.main td.r{width:112px;min-width:112px;}
.cia table.main th[data-k="tc"]{width:80px;}
.cia table.main th[data-k="name"]{min-width:190px;}
.cia table.main th[data-k="mv"]{width:100px;min-width:100px;}
.cia table.main th[data-k="st"]{width:80px;}
.cia table.main thead th{vertical-align:middle;}
.cia table.main th.r small{text-align:right;}
/* values bold (the small bill/invoice count under them stays normal) */
.cia table.main tbody td.r{font-weight:700;color:#0f172a;}
.cia table.main tbody td.r .cnt{font-weight:500;}
.cia table.main th.gl,.cia table.main td.gl{border-left:2px solid #cbd5e1;}
.cia table.main th.gr,.cia table.main td.gr{border-right:2px solid #cbd5e1;}
.cia table.main th small{display:block;text-transform:none;letter-spacing:0;font-weight:500;color:#94a3b8;font-size:10.5px;}
.cia table.main th.sorted{color:#1d4ed8;}
.cia table.main td{padding:8px 9px;border-bottom:1px solid #e2e8f0;vertical-align:middle;}
/* grid lines */
.cia table.main th,.cia table.main td{border-right:1px solid #e2e8f0;}
.cia table.main th{border-bottom:1px solid #cbd5e1;}
.cia table.main tr > :last-child{border-right:none;}
.cia table.main th.gl,.cia table.main td.gl{border-left:2px solid #94a3b8;}
.cia table.main th.gr,.cia table.main td.gr{border-right:2px solid #94a3b8;}
.cia table.main th.grp{border-left:2px solid #94a3b8;border-right:2px solid #94a3b8;}
.cia table.main tbody tr:nth-child(even):not([class*="s-drop"]):not([class*="s-stop"]):not([class*="s-spike"]):not([class*="s-new"]) td{background:#fafbfc;}
.cia table.main tfoot td{position:sticky;bottom:0;background:#f1f5f9;font-weight:800;border-top:2px solid #cbd5e1;}
.cia .r{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;}
.cia table.main th.r{text-align:right;}
.cia .cnt{display:block;font-size:10.5px;color:#6b7280;font-weight:500;}
.cia .tc{display:inline-block;font-family:'Courier New',monospace;font-weight:700;font-size:11.5px;background:#eef2ff;color:#3730a3;border-radius:5px;padding:1px 6px;}
.cia .rt{font-size:10.5px;color:#6b7280;}
.cia .pm{display:inline-block;font-size:10.5px;font-weight:800;border-radius:9px;padding:2px 9px;white-space:nowrap;text-transform:capitalize;}
.cia .pm.cash{background:#dcfce7;color:#166534;} .cia .pm.credit{background:#fee2e2;color:#991b1b;} .cia .pm.cheque{background:#dbeafe;color:#1d4ed8;} .cia .pm.none{background:#f1f5f9;color:#94a3b8;}
.cia tr.cust{cursor:pointer;}
.cia tr.cust:hover td{filter:brightness(.97);}
.cia tr.s-drop td{background:#fee2e2;} .cia tr.s-stop td{background:#fecaca;}
.cia tr.s-spike td{background:#fef3c7;} .cia tr.s-new td{background:#dbeafe;}
.cia tr.s-drop td:first-child{box-shadow:inset 4px 0 #dc2626;} .cia tr.s-stop td:first-child{box-shadow:inset 4px 0 #7f1d1d;}
.cia tr.s-spike td:first-child{box-shadow:inset 4px 0 #d97706;} .cia tr.s-new td:first-child{box-shadow:inset 4px 0 #2563eb;}
.cia .mv{display:inline-block;font-weight:800;font-size:11px;border-radius:9px;padding:1px 7px;white-space:nowrap;}
.cia .mv.up{background:#fef3c7;color:#92400e;} .cia .mv.dn{background:#fee2e2;color:#991b1b;} .cia .mv.ok{background:#f1f5f9;color:#475569;}
.cia .st{display:inline-block;font-size:10px;font-weight:800;border-radius:9px;padding:2px 8px;letter-spacing:.03em;color:#fff;white-space:nowrap;}
.cia .st.drop{background:#dc2626;} .cia .st.stop{background:#7f1d1d;} .cia .st.spike{background:#d97706;} .cia .st.new{background:#2563eb;} .cia .st.ok{background:#94a3b8;}
.cia .muted{color:#9ca3af;}
.cia svg.spk{display:block;}
.cia .empty{padding:40px 16px;text-align:center;color:#6b7280;}
/* invoices popup — moved to <body> by JS so the header can't cover it */
.cia-bg{position:fixed;inset:0;z-index:2147483647;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;padding:14px;box-sizing:border-box;}
.cia-bg.open{display:flex;}
body.cia-lock{overflow:hidden;}
.cia-box{background:#fff;color:#111827;border-radius:12px;width:100%;max-width:1100px;max-height:94vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 90px rgba(0,0,0,.35);font-family:'Inter',system-ui,sans-serif;text-align:left;}
.cia-head{display:flex;align-items:flex-start;gap:12px;padding:12px 16px;border-bottom:1px solid #e5e7eb;}
.cia-head .t{font-weight:800;font-size:15px;} .cia-head .s{font-size:12px;color:#6b7280;margin-top:2px;}
.cia-head .x{margin-left:auto;display:flex;gap:6px;}
.cia-head .x a,.cia-head .x button{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:32px;padding:0 10px;border:1px solid #d1d5db;border-radius:7px;background:#fff;color:#374151;cursor:pointer;text-decoration:none;font:inherit;font-size:12px;font-weight:600;}
.cia-body{overflow:auto;padding:12px 16px 16px;}
.cia-sum{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-bottom:12px;}
.cia-sum div{border:1px solid #e5e7eb;border-radius:8px;padding:6px 8px;font-size:11px;color:#6b7280;cursor:pointer;}
.cia-sum div b{display:block;font-size:13px;color:#111827;font-variant-numeric:tabular-nums;}
.cia-sum div.hi{border-color:#2563eb;background:#eff6ff;}
.cia-sum div.dn b{color:#b91c1c;} .cia-sum div.up b{color:#b45309;}
.cia-m{border:1px solid #e5e7eb;border-radius:9px;margin-bottom:10px;overflow:hidden;}
.cia-m h4{margin:0;padding:7px 12px;background:#f8fafc;font-size:12.5px;display:flex;gap:14px;flex-wrap:wrap;align-items:center;}
.cia-m h4 .grow{flex:1;}
.cia-m table{width:100%;border-collapse:collapse;font-size:12px;}
.cia-m th{text-align:left;font-size:10.5px;text-transform:uppercase;color:#6b7280;padding:5px 12px;border-bottom:1px solid #f1f5f9;}
.cia-m td{padding:5px 12px;border-bottom:1px solid #f8fafc;}
.cia-m tr.big td{background:#fffbeb;} .cia-m tr.small td{background:#f1f5f9;}
.cia-box .mv{display:inline-block;font-weight:800;font-size:11px;border-radius:9px;padding:1px 7px;white-space:nowrap;}
.cia-box .mv.up{background:#fef3c7;color:#92400e;} .cia-box .mv.dn{background:#fee2e2;color:#991b1b;} .cia-box .mv.ok{background:#f1f5f9;color:#475569;}
.cia-box .muted{color:#9ca3af;} .cia-m td.r,.cia-m th.r{text-align:right;font-variant-numeric:tabular-nums;}
.cia-m a{color:#1d4ed8;font-weight:700;text-decoration:none;font-family:'Courier New',monospace;}
/* info button + help window */
.cia h1 .btn.info{margin-left:auto;background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;font-size:12.5px;}
.cia h1 .btn.info i{color:#1d4ed8;}
.cia-help{max-width:900px;}
.cia-help .lang{display:inline-flex;border:1px solid #d1d5db;border-radius:7px;overflow:hidden;}
.cia-help .lang button{border:0;border-radius:0;height:32px;padding:0 12px;background:#fff;color:#374151;font:inherit;font-size:12px;font-weight:700;cursor:pointer;}
.cia-help .lang button.on{background:#2563eb;color:#fff;}
.cia-help .hb{font-size:13px;line-height:1.65;color:#1f2937;}
.cia-help .hb[data-lang="si"]{font-family:'Noto Sans Sinhala','Iskoola Pota','Inter',system-ui,sans-serif;font-size:13.5px;line-height:1.8;}
.cia-help h3{font-size:14px;margin:16px 0 6px;color:#0f172a;display:flex;align-items:center;gap:8px;}
.cia-help h3 .n{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#2563eb;color:#fff;font-size:11.5px;}
.cia-help p{margin:4px 0 8px;} .cia-help ul{margin:4px 0 8px;padding-left:20px;} .cia-help li{margin:2px 0;}
.cia-help .intro{background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:10px 13px;}
.cia-help .formula{background:#0f172a;color:#e2e8f0;border-radius:8px;padding:9px 13px;font-family:'Courier New',monospace;font-size:12.5px;margin:6px 0 8px;overflow-x:auto;white-space:nowrap;}
.cia-help .ex{background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:8px 12px;margin:6px 0 10px;font-size:12.5px;}
.cia-help table{width:100%;border-collapse:collapse;font-size:12.5px;margin:6px 0 10px;}
.cia-help th,.cia-help td{border:1px solid #e2e8f0;padding:6px 9px;text-align:left;vertical-align:top;}
.cia-help th{background:#f8fafc;font-size:11px;text-transform:uppercase;color:#475569;}
.cia-help .st{display:inline-block;font-size:10px;font-weight:800;border-radius:9px;padding:2px 8px;letter-spacing:.03em;color:#fff;white-space:nowrap;}
.cia-help .st.drop{background:#dc2626;} .cia-help .st.stop{background:#7f1d1d;} .cia-help .st.spike{background:#d97706;} .cia-help .st.new{background:#2563eb;} .cia-help .st.ok{background:#94a3b8;}
.cia-help .sw{display:inline-block;width:14px;height:14px;border-radius:3px;vertical-align:-2px;margin-right:4px;border:1px solid rgba(0,0,0,.08);}
@media print{.cia .card.filters,.cia .tools,.cia .chips{display:none!important;} .cia .tw{max-height:none;overflow:visible;} .cia table.main th,.cia table.main tfoot td{position:static;}
  .cia tr td,.cia table.main th{-webkit-print-color-adjust:exact;print-color-adjust:exact;} .cia-bg{display:none!important;}}
@media (max-width:760px){.cia-sum{grid-template-columns:repeat(4,1fr);}}
</style>

<div class="cia">
  <h1><i class="fa-solid fa-triangle-exclamation"></i> Credit Limit Violation Report
    <button class="btn info" type="button" id="btnInfo" title="How this report works"><i class="fa-solid fa-circle-info"></i> How it works / විස්තරය</button></h1>
  <p class="sub"><b>What this report does:</b> it compares every customer's billing with that customer's own normal level and
     highlights the ones that moved abnormally — a sudden jump in billing (possible credit limit risk), a sharp drop, a customer who stopped,
     or a new customer. Click <b>How it works</b> for the full explanation in English and Sinhala.<br>
     Invoice value per customer (T Code) — the <b>Ikea value</b> of each invoice, or the adjusted / net value when there is no Ikea value
     (same rule as the Invoice List). Averages are for the full months before <b><?php echo cia_h($META['cur']); ?></b>:
     6 months = <b><?php echo cia_h($META['r6']); ?></b> ÷ 6, 3 months = <b><?php echo cia_h($META['r3']); ?></b> ÷ 3.
     <b>Bill Count</b> columns show the value ÷ number of bills (average bill value) for each period.
     Current month counted up to <b><?php echo cia_h($META['to']); ?></b><?php if ($P['is_cur']) echo ' (day ' . $P['days_gone'] . ' of ' . $P['days_in'] . ' — compared at its pace)'; ?>.
     <b>Coloured rows</b> moved abnormally against the average. Click a customer to see the invoices.</p>

  <?php if ($error): ?><div class="err"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo cia_h($error); ?></div><?php endif; ?>

  <form class="card filters" method="get" id="ciaForm">
    <div class="flt">
      <div><label>Report month</label><input type="month" name="month" value="<?php echo cia_h($P['ym']); ?>" max="<?php echo date('Y-m'); ?>"></div>
      <div><label>Search</label><input type="text" id="fQ" placeholder="T Code / customer name"></div>
      <div><label>Route</label>
        <select id="fRoute"><option value="">All routes</option>
          <?php $used = array_unique(array_column($rows, 'route')); sort($used);
          foreach ($used as $rc) if ($rc !== '') echo '<option value="' . cia_h($rc) . '">' . cia_h($routes[$rc] ?? $rc) . '</option>'; ?>
        </select></div>
      <div><label>Pay mode</label>
        <select id="fPay"><option value="">All pay modes</option>
          <option value="cash">Cash</option><option value="credit">Credit</option><option value="cheque">Cheque</option>
          <option value="none">Not set</option></select></div>
      <div><label>Compare</label>
        <select id="fBasis">
          <option value="prev6">Last month vs 6M average</option>
          <option value="prev3">Last month vs 3M average</option>
          <option value="cur6">Current month (pace) vs 6M avg</option>
          <option value="cur3">Current month (pace) vs 3M avg</option>
          <option value="bill">Current bill value vs 6M bill value</option>
          <option value="any">Any of the above</option>
        </select></div>
      <div><label>Abnormal if moved ≥ %</label><input type="number" id="fTh" value="30" min="1" max="500" step="1"></div>
      <div><label>6M average (Rs.)</label><div class="pair"><input type="number" id="f6min" placeholder="min" min="0"><input type="number" id="f6max" placeholder="max" min="0"></div></div>
      <div><label>3M average (Rs.)</label><div class="pair"><input type="number" id="f3min" placeholder="min" min="0"><input type="number" id="f3max" placeholder="max" min="0"></div></div>
      <div><label>Min. invoices (6M)</label><input type="number" id="fC6" placeholder="0" min="0"></div>
      <div style="display:flex;gap:8px;"><button class="btn pri" type="submit"><i class="fa-solid fa-rotate"></i> Load month</button>
        <button class="btn" type="button" id="fReset" title="Clear filters"><i class="fa-solid fa-eraser"></i></button></div>
    </div>
  </form>

  <div class="chips" id="chips"></div>

  <div class="tools">
    <button class="btn grn" type="button" id="btnCsv"><i class="fa-solid fa-file-excel"></i> Export Excel (CSV)</button>
    <button class="btn" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    <span class="meta" id="meta"></span>
  </div>

  <div class="tw">
    <table class="main" id="tbl">
      <thead>
        <tr class="h1">
          <th data-k="tc" rowspan="2">T Code</th>
          <th data-k="name" rowspan="2">Customer Name</th>
          <th data-k="pay" rowspan="2">Pay Mode</th>
          <th class="grp val" colspan="4">Bill Value</th>
          <th class="grp bc" colspan="4">Bill Count<small>value ÷ bill count</small></th>
          <th data-k="mv" class="r" rowspan="2">Movement<small id="mvLbl"></small></th>
          <th data-k="st" rowspan="2">Status</th>
          <th style="cursor:default;" rowspan="2">Trend<small>6M + current</small></th>
        </tr>
        <tr class="h2">
          <th data-k="a6" class="r gl">Last 6 Months<small>÷ 6 · <?php echo cia_h($META['r6']); ?></small></th>
          <th data-k="a3" class="r">Last 3 Months<small>÷ 3 · <?php echo cia_h($META['r3']); ?></small></th>
          <th data-k="tp" class="r">Last Month<small><?php echo cia_h($META['prev']); ?></small></th>
          <th data-k="tc_" class="r gr">Current Month<small><?php echo cia_h($META['cur']); ?></small></th>
          <th data-k="v6" class="r gl">Last 6 Months<small>value / bill count</small></th>
          <th data-k="v3" class="r">Last 3 Months<small>value / bill count</small></th>
          <th data-k="vp" class="r">Last Month<small>value / bill count</small></th>
          <th data-k="vc" class="r gr">Current Month<small>value / bill count</small></th>
        </tr>
      </thead>
      <tbody id="tb"></tbody>
      <tfoot id="tf"></tfoot>
    </table>
  </div>
</div>

<div class="cia-bg" id="pop">
  <div class="cia-box">
    <div class="cia-head">
      <div><div class="t" id="popT"></div><div class="s" id="popS"></div></div>
      <div class="x">
        <a id="popInv" target="_blank" title="Open in Invoice List"><i class="fa-solid fa-file-invoice"></i> Invoice List</a>
        <a id="popLed" target="_blank" title="Open Customer Ledger"><i class="fa-solid fa-book"></i> Ledger</a>
        <button type="button" id="popX" title="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>
    <div class="cia-body" id="popB"></div>
  </div>
</div>

<!-- ═════════ HELP / INFO WINDOW ═════════ -->
<div class="cia-bg" id="help">
  <div class="cia-box cia-help">
    <div class="cia-head">
      <div><div class="t"><i class="fa-solid fa-circle-info" style="color:#2563eb"></i> <span id="helpT">How this report works</span></div>
        <div class="s" id="helpS">Credit Limit Violation Report — guide</div></div>
      <div class="x">
        <div class="lang"><button type="button" data-l="en" class="on">English</button><button type="button" data-l="si">සිංහල</button></div>
        <button type="button" id="helpX" title="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>
    <div class="cia-body">

      <!-- ───────── ENGLISH ───────── -->
      <div class="hb" data-lang="en">
        <div class="intro"><b>Introduction.</b> This report checks the billing of every customer (T Code) against that customer's <b>own normal level</b>
          and highlights the customers whose billing moved abnormally. A shop that suddenly buys far more than usual may be going beyond a safe credit level;
          a shop that buys far less, or stops, may be lost to a competitor or have a problem. These customers are coloured so they can be checked quickly.</div>

        <h3><span class="n">1</span> Where the values come from</h3>
        <ul>
          <li><b>Invoice value</b> = the <b>Ikea value</b> of the invoice (final bill amount from the imported secondary invoice, matched by bill number + delivery date).</li>
          <li>If there is no Ikea value, the <b>adjusted net value</b> is used; if that is also empty, the <b>net value</b>. This is the same rule as the Invoice List and the Customer Ledger.</li>
          <li>Invoices with a value of 0 are <b>not counted</b>.</li>
          <li>Values are grouped by customer <b>T Code</b> and by the <b>delivery month</b>.</li>
        </ul>

        <h3><span class="n">2</span> The periods</h3>
        <p>When the report month is <b>November 2026</b>:</p>
        <table>
          <tr><th>Period</th><th>Months used</th></tr>
          <tr><td>Last 6 Months</td><td>May – October (6 full months)</td></tr>
          <tr><td>Last 3 Months</td><td>August – October (3 full months)</td></tr>
          <tr><td>Last Month</td><td>October</td></tr>
          <tr><td>Current Month</td><td>1 November up to today</td></tr>
        </table>
        <p>Pick another month in <b>Report month</b> and press <b>Load month</b> to see an earlier month.</p>

        <h3><span class="n">3</span> The columns</h3>
        <table>
          <tr><th>Group</th><th>Column</th><th>How it is calculated</th></tr>
          <tr><td rowspan="4"><b>Bill Value</b></td><td>Last 6 Months</td><td>Total of the 6 months ÷ 6 = average per month</td></tr>
          <tr><td>Last 3 Months</td><td>Total of the 3 months ÷ 3 = average per month</td></tr>
          <tr><td>Last Month</td><td>Total value of last month</td></tr>
          <tr><td>Current Month</td><td>Total value of this month so far</td></tr>
          <tr><td rowspan="4"><b>Bill Count</b></td><td>Last 6 Months</td><td>6-month value ÷ number of bills = average value of one bill</td></tr>
          <tr><td>Last 3 Months</td><td>3-month value ÷ number of bills</td></tr>
          <tr><td>Last Month</td><td>Last month value ÷ number of bills</td></tr>
          <tr><td>Current Month</td><td>This month's value ÷ number of bills</td></tr>
        </table>
        <p>The small grey number under each value is the number of invoices / bills in that period.</p>

        <h3><span class="n">4</span> How the Movement % is worked out</h3>
        <div class="formula">Movement % = (Compared value − Average) ÷ Average × 100</div>
        <p>The <b>Compare</b> filter decides which two values are compared:</p>
        <table>
          <tr><th>Compare option</th><th>Compared value</th><th>Against</th></tr>
          <tr><td>Last month vs 6M average</td><td>Last month total</td><td>Last 6 months average</td></tr>
          <tr><td>Last month vs 3M average</td><td>Last month total</td><td>Last 3 months average</td></tr>
          <tr><td>Current month (pace) vs 6M avg</td><td>Current month at its pace</td><td>Last 6 months average</td></tr>
          <tr><td>Current month (pace) vs 3M avg</td><td>Current month at its pace</td><td>Last 3 months average</td></tr>
          <tr><td>Current bill value vs 6M bill value</td><td>Current month value per bill</td><td>6 months value per bill</td></tr>
          <tr><td>Any of the above</td><td colspan="2">All five are checked and the <b>largest</b> movement (up or down) is shown</td></tr>
        </table>
        <p><b>Pace</b> — the current month is not finished, so it is scaled up to a full month:</p>
        <div class="formula">Pace = Current month total ÷ days passed × days in the month</div>
        <div class="ex"><b>Example 1 (Drop):</b> 6M average = Rs. 100,000. Last month = Rs. 60,000.<br>
          (60,000 − 100,000) ÷ 100,000 × 100 = <b>−40%</b> → more than 30% down → <span class="st drop">DROP</span></div>
        <div class="ex"><b>Example 2 (Spike, using pace):</b> Today is 9 November (30-day month). Current month so far = Rs. 45,000.<br>
          Pace = 45,000 ÷ 9 × 30 = Rs. 150,000. 6M average = Rs. 100,000 → (150,000 − 100,000) ÷ 100,000 × 100 = <b>+50%</b> → <span class="st spike">SPIKE</span></div>
        <p>The <b>Abnormal if moved ≥ %</b> box is the threshold (default <b>30%</b>). Lower it to catch smaller changes, raise it to see only big ones.</p>

        <h3><span class="n">5</span> What each status means</h3>
        <table>
          <tr><th>Status</th><th>Row colour</th><th>Meaning</th><th>What to check</th></tr>
          <tr><td><span class="st spike">SPIKE</span></td><td><span class="sw" style="background:#fef3c7"></span>Amber</td>
            <td>Billing went <b>up</b> by the threshold % or more against the average — the customer is buying much more than normal.</td>
            <td>Credit limit, outstanding balance, cheque returns, and whether the large order is approved.</td></tr>
          <tr><td><span class="st drop">DROP</span></td><td><span class="sw" style="background:#fee2e2"></span>Light red</td>
            <td>Billing went <b>down</b> by the threshold % or more — the customer is buying much less than normal.</td>
            <td>Competitor activity, stock / delivery problems, complaints, unpaid bills blocking new orders.</td></tr>
          <tr><td><span class="st stop">STOPPED</span></td><td><span class="sw" style="background:#fecaca"></span>Dark red</td>
            <td>The customer had an average but has <b>no bills</b> in the compared month (−100%).</td>
            <td>Is the shop closed, moved, lost to a competitor, or blocked for credit?</td></tr>
          <tr><td><span class="st new">NEW</span></td><td><span class="sw" style="background:#dbeafe"></span>Blue</td>
            <td><b>No bills</b> in the last 6 months, but has bills last month or this month — a new or returning customer.</td>
            <td>Customer registration, credit approval and credit limit.</td></tr>
          <tr><td><span class="st ok">NORMAL</span></td><td><span class="sw" style="background:#fff"></span>White</td>
            <td>The movement is within the threshold, or there is no average to compare with.</td><td>Nothing needed.</td></tr>
        </table>
        <p>The <b>chips</b> above the table (All, Abnormal, Drop, Stopped, Spike, New, Normal) show how many customers have each status. Click a chip to show only those.</p>

        <h3><span class="n">6</span> Trend line</h3>
        <p>The small chart shows the monthly value for the last 6 months plus the current month. The dashed line is the 6-month average,
          so you can see at a glance whether the customer is above or below normal.</p>

        <h3><span class="n">7</span> Customer details (click a row)</h3>
        <ul>
          <li>Shows every invoice of the 7 months, grouped by month, with a link to open each invoice.</li>
          <li><b>Month boxes:</b> red = 30% or more below the 6M average, amber = 30% or more above (current month at its pace).</li>
          <li><b>Invoice rows:</b> amber = the bill is 2× or more the customer's average bill (an unusually large bill), grey = 0.4× or less (an unusually small bill).</li>
          <li><b>Invoice List</b> and <b>Ledger</b> buttons open that customer's invoice list and ledger.</li>
        </ul>

        <h3><span class="n">8</span> Tips</h3>
        <ul>
          <li>Use <b>Min. invoices (6M)</b> to hide very small or occasional customers.</li>
          <li>Use the <b>6M / 3M average</b> min–max boxes to focus on big customers.</li>
          <li>Click any column header to sort. <b>Export Excel (CSV)</b> exports exactly the rows you see.</li>
        </ul>
      </div>

      <!-- ───────── SINHALA ───────── -->
      <div class="hb" data-lang="si" style="display:none">
        <div class="intro"><b>හැඳින්වීම.</b> මෙම වාර්තාව සෑම පාරිභෝගිකයෙකුගේම (T Code) බිල්පත් ප්‍රමාණය, එම පාරිභෝගිකයාගේම
          <b>සාමාන්‍ය මට්ටම</b> සමඟ සසඳා, අසාමාන්‍ය ලෙස වෙනස් වූ පාරිභෝගිකයින් වෙන් කර පෙන්වයි. සාමාන්‍යයට වඩා හදිසියේ ඉතා වැඩියෙන්
          මිලදී ගන්නා කඩයක් ආරක්ෂිත ණය සීමාව ඉක්මවා යා හැක; ඉතා අඩුවෙන් මිලදී ගන්නා හෝ මිලදී ගැනීම නැවැත්වූ කඩයක් තරඟකරුවෙකු
          වෙත ගොස් හෝ යම් ගැටලුවක් ඇති විය හැක. ඉක්මනින් පරීක්ෂා කිරීම සඳහා එවැනි පාරිභෝගිකයින් වර්ණ ගන්වා ඇත.</div>

        <h3><span class="n">1</span> වටිනාකම් ලැබෙන ආකාරය</h3>
        <ul>
          <li><b>ඉන්වොයිසියේ වටිනාකම</b> = ඉන්වොයිසියේ <b>Ikea වටිනාකම</b> (බිල් අංකය සහ බෙදාහැරීමේ දිනය අනුව ගැළපෙන, ආනයනය කළ secondary invoice එකේ final bill amount).</li>
          <li>Ikea වටිනාකමක් නොමැති නම් <b>adjusted net value</b> ද, එයත් නොමැති නම් <b>net value</b> ද භාවිත වේ. මෙය Invoice List සහ Customer Ledger හි භාවිත වන නීතියමයි.</li>
          <li>වටිනාකම 0 වන ඉන්වොයිසි <b>ගණන් නොගනී</b>.</li>
          <li>වටිනාකම් පාරිභෝගික <b>T Code</b> සහ <b>බෙදාහැරීමේ (delivery) මාසය</b> අනුව එකතු කෙරේ.</li>
        </ul>

        <h3><span class="n">2</span> කාල පරාසයන්</h3>
        <p>වාර්තා මාසය <b>2026 නොවැම්බර්</b> නම්:</p>
        <table>
          <tr><th>කාලය</th><th>භාවිත වන මාස</th></tr>
          <tr><td>පසුගිය මාස 6 (Last 6 Months)</td><td>මැයි – ඔක්තෝබර් (සම්පූර්ණ මාස 6)</td></tr>
          <tr><td>පසුගිය මාස 3 (Last 3 Months)</td><td>අගෝස්තු – ඔක්තෝබර් (සම්පූර්ණ මාස 3)</td></tr>
          <tr><td>පසුගිය මාසය (Last Month)</td><td>ඔක්තෝබර්</td></tr>
          <tr><td>වත්මන් මාසය (Current Month)</td><td>නොවැම්බර් 1 සිට අද දක්වා</td></tr>
        </table>
        <p>පෙර මාසයක් බැලීමට <b>Report month</b> හි වෙනත් මාසයක් තෝරා <b>Load month</b> ඔබන්න.</p>

        <h3><span class="n">3</span> තීරු (Columns)</h3>
        <table>
          <tr><th>කාණ්ඩය</th><th>තීරුව</th><th>ගණනය කරන ආකාරය</th></tr>
          <tr><td rowspan="4"><b>Bill Value</b></td><td>Last 6 Months</td><td>මාස 6 හි මුළු එකතුව ÷ 6 = මාසයක සාමාන්‍යය</td></tr>
          <tr><td>Last 3 Months</td><td>මාස 3 හි මුළු එකතුව ÷ 3 = මාසයක සාමාන්‍යය</td></tr>
          <tr><td>Last Month</td><td>පසුගිය මාසයේ මුළු වටිනාකම</td></tr>
          <tr><td>Current Month</td><td>මේ මාසයේ මෙතෙක් මුළු වටිනාකම</td></tr>
          <tr><td rowspan="4"><b>Bill Count</b></td><td>Last 6 Months</td><td>මාස 6 වටිනාකම ÷ බිල් ගණන = එක් බිලක සාමාන්‍ය වටිනාකම</td></tr>
          <tr><td>Last 3 Months</td><td>මාස 3 වටිනාකම ÷ බිල් ගණන</td></tr>
          <tr><td>Last Month</td><td>පසුගිය මාසයේ වටිනාකම ÷ බිල් ගණන</td></tr>
          <tr><td>Current Month</td><td>මේ මාසයේ වටිනාකම ÷ බිල් ගණන</td></tr>
        </table>
        <p>එක් එක් වටිනාකමට යටින් ඇති කුඩා අළු පැහැති අංකය, එම කාලයේ ඉන්වොයිසි / බිල් ගණනයි.</p>

        <h3><span class="n">4</span> Movement % ගණනය කරන ආකාරය</h3>
        <div class="formula">Movement % = (සසඳන වටිනාකම − සාමාන්‍යය) ÷ සාමාන්‍යය × 100</div>
        <p>සසඳන්නේ කුමන වටිනාකම් දෙකද යන්න <b>Compare</b> පෙරහන (filter) මගින් තීරණය වේ:</p>
        <table>
          <tr><th>Compare තේරීම</th><th>සසඳන වටිනාකම</th><th>සසඳන්නේ</th></tr>
          <tr><td>Last month vs 6M average</td><td>පසුගිය මාසයේ එකතුව</td><td>මාස 6 සාමාන්‍යය</td></tr>
          <tr><td>Last month vs 3M average</td><td>පසුගිය මාසයේ එකතුව</td><td>මාස 3 සාමාන්‍යය</td></tr>
          <tr><td>Current month (pace) vs 6M avg</td><td>වත්මන් මාසය, එහි වේගය (pace) අනුව</td><td>මාස 6 සාමාන්‍යය</td></tr>
          <tr><td>Current month (pace) vs 3M avg</td><td>වත්මන් මාසය, එහි වේගය (pace) අනුව</td><td>මාස 3 සාමාන්‍යය</td></tr>
          <tr><td>Current bill value vs 6M bill value</td><td>මේ මාසයේ එක් බිලක වටිනාකම</td><td>මාස 6 එක් බිලක වටිනාකම</td></tr>
          <tr><td>Any of the above</td><td colspan="2">ඉහත පහම පරීක්ෂා කර, <b>විශාලතම</b> වෙනස්වීම (ඉහළ හෝ පහළ) පෙන්වයි</td></tr>
        </table>
        <p><b>Pace (වේගය)</b> — වත්මන් මාසය තවම අවසන් නැති නිසා, එය සම්පූර්ණ මාසයකට ගණනය කෙරේ:</p>
        <div class="formula">Pace = වත්මන් මාසයේ එකතුව ÷ ගතවූ දින ගණන × මාසයේ දින ගණන</div>
        <div class="ex"><b>උදාහරණය 1 (Drop):</b> මාස 6 සාමාන්‍යය = රු. 100,000. පසුගිය මාසය = රු. 60,000.<br>
          (60,000 − 100,000) ÷ 100,000 × 100 = <b>−40%</b> → 30% ට වඩා අඩු වී ඇත → <span class="st drop">DROP</span></div>
        <div class="ex"><b>උදාහරණය 2 (Spike, pace භාවිතයෙන්):</b> අද නොවැම්බර් 9 (දින 30 මාසයක්). මේ මාසයේ මෙතෙක් = රු. 45,000.<br>
          Pace = 45,000 ÷ 9 × 30 = රු. 150,000. මාස 6 සාමාන්‍යය = රු. 100,000 → (150,000 − 100,000) ÷ 100,000 × 100 = <b>+50%</b> → <span class="st spike">SPIKE</span></div>
        <p><b>Abnormal if moved ≥ %</b> කොටුව යනු සීමාවයි (threshold — පෙරනිමිය <b>30%</b>). කුඩා වෙනස්වීම් ද හඳුනා ගැනීමට එය අඩු කරන්න;
          විශාල වෙනස්වීම් පමණක් බැලීමට එය වැඩි කරන්න.</p>

        <h3><span class="n">5</span> එක් එක් තත්ත්වයේ (Status) තේරුම</h3>
        <table>
          <tr><th>තත්ත්වය</th><th>පේළියේ වර්ණය</th><th>තේරුම</th><th>පරීක්ෂා කළ යුතු දේ</th></tr>
          <tr><td><span class="st spike">SPIKE</span></td><td><span class="sw" style="background:#fef3c7"></span>කහ / තැඹිලි</td>
            <td>සාමාන්‍යයට සාපේක්ෂව බිල්පත් සීමාව % හෝ ඊට වැඩියෙන් <b>ඉහළ</b> ගොස් ඇත — පාරිභෝගිකයා සාමාන්‍යයට වඩා බොහෝ වැඩියෙන් මිලදී ගනී.</td>
            <td>ණය සීමාව, හිඟ මුදල් (outstanding), චෙක්පත් ආපසු පැමිණීම, සහ විශාල ඇණවුම අනුමත කර ඇත්ද යන්න.</td></tr>
          <tr><td><span class="st drop">DROP</span></td><td><span class="sw" style="background:#fee2e2"></span>ලා රතු</td>
            <td>බිල්පත් සීමාව % හෝ ඊට වැඩියෙන් <b>පහළ</b> ගොස් ඇත — පාරිභෝගිකයා සාමාන්‍යයට වඩා බොහෝ අඩුවෙන් මිලදී ගනී.</td>
            <td>තරඟකරුවන්ගේ ක්‍රියාකාරකම්, තොග / බෙදාහැරීමේ ගැටලු, පැමිණිලි, නොගෙවූ බිල් නිසා නව ඇණවුම් නැවතී ඇත්ද යන්න.</td></tr>
          <tr><td><span class="st stop">STOPPED</span></td><td><span class="sw" style="background:#fecaca"></span>තද රතු</td>
            <td>පාරිභෝගිකයාට සාමාන්‍යයක් තිබුණත්, සසඳන මාසයේ <b>කිසිදු බිලක් නැත</b> (−100%).</td>
            <td>කඩය වසා ඇත්ද, වෙනත් තැනකට ගොස් ඇත්ද, තරඟකරුවෙකු වෙත ගොස් ඇත්ද, නැතහොත් ණය නිසා අවහිර කර ඇත්ද?</td></tr>
          <tr><td><span class="st new">NEW</span></td><td><span class="sw" style="background:#dbeafe"></span>නිල්</td>
            <td>පසුගිය මාස 6 තුළ <b>බිල් නොමැති</b> නමුත්, පසුගිය මාසයේ හෝ මේ මාසයේ බිල් ඇත — නව හෝ නැවත පැමිණි පාරිභෝගිකයෙක්.</td>
            <td>පාරිභෝගික ලියාපදිංචිය, ණය අනුමැතිය සහ ණය සීමාව.</td></tr>
          <tr><td><span class="st ok">NORMAL</span></td><td><span class="sw" style="background:#fff"></span>සුදු</td>
            <td>වෙනස්වීම සීමාව තුළ ඇත, නැතහොත් සසඳීමට සාමාන්‍යයක් නොමැත.</td><td>කිසිවක් අවශ්‍ය නැත.</td></tr>
        </table>
        <p>වගුවට ඉහළින් ඇති <b>chips</b> (All, Abnormal, Drop, Stopped, Spike, New, Normal) එක් එක් තත්ත්වයේ පාරිභෝගිකයින් ගණන පෙන්වයි.
          එම පාරිභෝගිකයින් පමණක් බැලීමට chip එකක් ක්ලික් කරන්න.</p>

        <h3><span class="n">6</span> ප්‍රවණතා රේඛාව (Trend)</h3>
        <p>කුඩා ප්‍රස්තාරය පසුගිය මාස 6 සහ වත්මන් මාසයේ මාසික වටිනාකම පෙන්වයි. ඉරි සහිත රේඛාව මාස 6 සාමාන්‍යයයි;
          පාරිභෝගිකයා සාමාන්‍යයට ඉහළින්ද පහළින්ද යන්න එක බැල්මකින් දැක ගත හැක.</p>

        <h3><span class="n">7</span> පාරිභෝගික විස්තර (පේළියක් ක්ලික් කරන්න)</h3>
        <ul>
          <li>මාස 7 හි සියලුම ඉන්වොයිසි, මාසය අනුව කාණ්ඩ කර, එක් එක් ඉන්වොයිසිය විවෘත කිරීමට සබැඳියක් සමඟ පෙන්වයි.</li>
          <li><b>මාස කොටු:</b> රතු = මාස 6 සාමාන්‍යයට වඩා 30% හෝ ඊට වැඩියෙන් අඩුයි, කහ = 30% හෝ ඊට වැඩියෙන් වැඩියි (වත්මන් මාසය එහි pace අනුව).</li>
          <li><b>ඉන්වොයිසි පේළි:</b> කහ = පාරිභෝගිකයාගේ සාමාන්‍ය බිල මෙන් 2 ගුණයක් හෝ ඊට වැඩි (අසාමාන්‍ය ලෙස විශාල බිලක්), අළු = 0.4 ගුණයක් හෝ ඊට අඩු (අසාමාන්‍ය ලෙස කුඩා බිලක්).</li>
          <li><b>Invoice List</b> සහ <b>Ledger</b> බොත්තම් මගින් එම පාරිභෝගිකයාගේ ඉන්වොයිසි ලැයිස්තුව සහ ලෙජරය විවෘත වේ.</li>
        </ul>

        <h3><span class="n">8</span> උපදෙස්</h3>
        <ul>
          <li>ඉතා කුඩා හෝ ඉඳහිට මිලදී ගන්නා පාරිභෝගිකයින් සැඟවීමට <b>Min. invoices (6M)</b> භාවිත කරන්න.</li>
          <li>විශාල පාරිභෝගිකයින් කෙරෙහි අවධානය යොමු කිරීමට <b>6M / 3M average</b> min–max කොටු භාවිත කරන්න.</li>
          <li>පිළිවෙළට සැකසීමට ඕනෑම තීරු ශීර්ෂයක් ක්ලික් කරන්න. <b>Export Excel (CSV)</b> මගින් ඔබ දකින පේළි පමණක් export වේ.</li>
        </ul>
      </div>

    </div>
  </div>
</div>

<script>
(function () {
  const ROWS = <?php echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  const META = <?php echo json_encode($META, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  const MONTH = <?php echo json_encode($P['ym']); ?>;
  const SECS = <?php echo json_encode($secs); ?>;
  const SELF = location.pathname;   /* this page — works even if the file is renamed */
  const $ = id => document.getElementById(id);
  const fmt = v => Number(v || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const pace = META.is_cur ? META.days_in / Math.max(1, META.days_gone) : 1;
  const STATUS = {
    drop:  {l: 'DROP',    c: '#dc2626', t: 'Dropped below the average'},
    stop:  {l: 'STOPPED', c: '#7f1d1d', t: 'Had an average, nothing now'},
    spike: {l: 'SPIKE',   c: '#d97706', t: 'Jumped above the average'},
    'new': {l: 'NEW',     c: '#2563eb', t: 'No invoices in the last 6 months'},
    ok:    {l: 'NORMAL',  c: '#94a3b8', t: 'Within the threshold'}
  };
  const BASIS_LBL = {prev6: 'last mo vs 6M', prev3: 'last mo vs 3M', cur6: 'cur. pace vs 6M', cur3: 'cur. pace vs 3M', bill: 'bill value vs 6M', any: 'largest move'};
  let sortK = 'a6', sortDir = -1, stFilter = '';

  /* bill value / bill count for every period (fixed per row) */
  ROWS.forEach(r => {
    r.v6 = r.c6 ? r.t6 / r.c6 : 0;
    r.v3 = r.c3 ? r.t3 / r.c3 : 0;
    r.vp = r.cp ? r.tp / r.cp : 0;
    r.vc = r.cc ? r.tc_ / r.cc : 0;
  });

  /* one comparison → {pct, base, now} ; pct null when there is no base */
  function cmp(r, b) {
    let now, base;
    if (b === 'prev6') { now = r.tp; base = r.a6; }
    else if (b === 'prev3') { now = r.tp; base = r.a3; }
    else if (b === 'cur6') { now = r.tc_ * pace; base = r.a6; }
    else if (b === 'cur3') { now = r.tc_ * pace; base = r.a3; }
    else { /* bill value */
      base = r.v6; now = r.vc;
      if (!r.cc && base > 0) return {pct: null, base, now: 0, none: true};
    }
    if (base <= 0.005) return {pct: null, base: 0, now};
    return {pct: (now - base) / base * 100, base, now};
  }
  function evaluate(r, basis, th) {
    const list = basis === 'any' ? ['prev6', 'prev3', 'cur6', 'cur3', 'bill'] : [basis];
    let best = null;
    for (const b of list) {
      const c = cmp(r, b);
      if (c.pct === null) continue;
      if (!best || Math.abs(c.pct) > Math.abs(best.pct)) best = c;
    }
    r.mv = best ? best.pct : null;
    if (r.t6 <= 0.005) r.st = (r.tp > 0 || r.tc_ > 0) ? 'new' : 'ok';
    else if (best === null) r.st = 'ok';
    else if (best.pct <= -99.99) r.st = 'stop';
    else if (best.pct <= -th) r.st = 'drop';
    else if (best.pct >= th) r.st = 'spike';
    else r.st = 'ok';
  }
  function num(id) { const v = $(id).value.trim(); return v === '' ? null : parseFloat(v); }

  function spark(vals) {
    const w = 84, h = 22, n = vals.length, mx = Math.max(...vals, 1);
    const pts = vals.map((v, i) => [(i * (w - 4) / (n - 1)) + 2, h - 2 - (v / mx) * (h - 4)]);
    const avg = vals.slice(0, 6).reduce((a, b) => a + b, 0) / 6, ay = h - 2 - (avg / mx) * (h - 4);
    const last = pts[n - 1];
    return `<svg class="spk" width="${w}" height="${h}"><line x1="2" x2="${w - 2}" y1="${ay}" y2="${ay}" stroke="#cbd5e1" stroke-dasharray="2 2"/>` +
           `<polyline fill="none" stroke="#2563eb" stroke-width="1.6" points="${pts.map(p => p.join(',')).join(' ')}"/>` +
           `<circle cx="${last[0]}" cy="${last[1]}" r="2.6" fill="#2563eb"/></svg>`;
  }

  function filtered() {
    const q = $('fQ').value.trim().toLowerCase(), rt = $('fRoute').value, pm = $('fPay').value, basis = $('fBasis').value;
    const th = Math.max(1, parseFloat($('fTh').value) || 30);
    const a6min = num('f6min'), a6max = num('f6max'), a3min = num('f3min'), a3max = num('f3max'), c6 = num('fC6');
    const counts = {all: 0, abn: 0, drop: 0, stop: 0, spike: 0, 'new': 0, ok: 0};
    const out = [];
    for (const r of ROWS) {
      evaluate(r, basis, th);
      if (q && !(r.tc.toLowerCase().includes(q) || r.name.toLowerCase().includes(q))) continue;
      if (rt && r.route !== rt) continue;
      if (pm && (r.pay || 'none') !== pm) continue;
      if (a6min !== null && r.a6 < a6min) continue;
      if (a6max !== null && r.a6 > a6max) continue;
      if (a3min !== null && r.a3 < a3min) continue;
      if (a3max !== null && r.a3 > a3max) continue;
      if (c6 !== null && r.c6 < c6) continue;
      counts.all++; counts[r.st]++; if (r.st !== 'ok') counts.abn++;
      if (stFilter === 'abn' ? r.st === 'ok' : (stFilter && r.st !== stFilter)) continue;
      out.push(r);
    }
    out.sort((a, b) => {
      let x = a[sortK], y = b[sortK];
      if (sortK === 'mv') { x = x === null ? -1e9 * sortDir : x; y = y === null ? -1e9 * sortDir : y; }
      if (typeof x === 'string') return x.localeCompare(y) * sortDir;
      return ((x || 0) - (y || 0)) * sortDir;
    });
    return {out, counts};
  }

  /* value / count cell */
  const vcCell = (v, c, cls) => `<td class="r${cls ? ' ' + cls : ''}">${c ? fmt(v) + `<span class="cnt">${c.toLocaleString()} bills</span>` : '<span class="muted">—</span>'}</td>`;

  function render() {
    const {out, counts} = filtered();
    const chipDefs = [['', 'All', '#111827', counts.all], ['abn', 'Abnormal', '#dc2626', counts.abn], ['drop', 'Drop', STATUS.drop.c, counts.drop],
                      ['stop', 'Stopped', STATUS.stop.c, counts.stop], ['spike', 'Spike', STATUS.spike.c, counts.spike],
                      ['new', 'New', STATUS['new'].c, counts['new']], ['ok', 'Normal', STATUS.ok.c, counts.ok]];
    $('chips').innerHTML = chipDefs.map(([k, l, c, n]) =>
      `<span class="chip${stFilter === k ? ' on' : ''}" data-st="${k}"><span class="dot" style="background:${c}"></span>${l} <b>${n.toLocaleString()}</b></span>`).join('');
    $('mvLbl').textContent = BASIS_LBL[$('fBasis').value];
    document.querySelectorAll('#tbl th[data-k]').forEach(th => th.classList.toggle('sorted', th.dataset.k === sortK));

    const T = {a6: 0, t6: 0, c6: 0, a3: 0, t3: 0, c3: 0, tp: 0, cp: 0, tc: 0, cc: 0};
    const html = [];
    for (const r of out) {
      T.a6 += r.a6; T.t6 += r.t6; T.c6 += r.c6; T.a3 += r.a3; T.t3 += r.t3; T.c3 += r.c3;
      T.tp += r.tp; T.cp += r.cp; T.tc += r.tc_; T.cc += r.cc;
      const mv = r.mv === null ? '<span class="muted">—</span>'
        : `<span class="mv ${r.st === 'ok' ? 'ok' : (r.mv > 0 ? 'up' : 'dn')}">${r.mv > 0 ? '▲ +' : (r.mv < 0 ? '▼ ' : '')}${r.mv.toFixed(1)}%</span>`;
      const S = STATUS[r.st];
      html.push(`<tr class="cust s-${r.st}" data-tc="${esc(r.tc)}" title="${esc(S.t)} — click to see the invoices">
        <td><span class="tc">${esc(r.tc)}</span></td>
        <td><div style="font-weight:600">${esc(r.name) || '<span class="muted">—</span>'}</div>${r.route_name ? `<div class="rt"><i class="fa-solid fa-route"></i> ${esc(r.route_name)}</div>` : ''}</td>
        <td><span class="pm ${esc(r.pay || 'none')}">${esc(r.pay || 'not set')}</span></td>
        <td class="r gl">${fmt(r.a6)}<span class="cnt">${r.c6} invoices</span></td>
        <td class="r">${fmt(r.a3)}<span class="cnt">${r.c3} invoices</span></td>
        <td class="r">${fmt(r.tp)}<span class="cnt">${r.cp} bills</span></td>
        <td class="r gr">${fmt(r.tc_)}<span class="cnt">${r.cc} bills</span></td>
        ${vcCell(r.v6, r.c6, 'gl')}${vcCell(r.v3, r.c3, '')}${vcCell(r.vp, r.cp, '')}${vcCell(r.vc, r.cc, 'gr')}
        <td class="r">${mv}</td>
        <td><span class="st ${r.st}">${S.l}</span></td>
        <td>${spark(r.m)}</td></tr>`);
    }
    $('tb').innerHTML = html.length ? html.join('') : '<tr><td colspan="14" class="empty">No customers match these filters.</td></tr>';
    $('tf').innerHTML = out.length ? `<tr><td colspan="3">TOTAL · ${out.length.toLocaleString()} customers</td>
      <td class="r gl">${fmt(T.a6)}<span class="cnt">${T.c6.toLocaleString()} invoices</span></td>
      <td class="r">${fmt(T.a3)}<span class="cnt">${T.c3.toLocaleString()} invoices</span></td>
      <td class="r">${fmt(T.tp)}<span class="cnt">${T.cp.toLocaleString()} bills</span></td>
      <td class="r gr">${fmt(T.tc)}<span class="cnt">${T.cc.toLocaleString()} bills</span></td>
      ${vcCell(T.c6 ? T.t6 / T.c6 : 0, T.c6, 'gl')}${vcCell(T.c3 ? T.t3 / T.c3 : 0, T.c3, '')}
      ${vcCell(T.cp ? T.tp / T.cp : 0, T.cp, '')}${vcCell(T.cc ? T.tc / T.cc : 0, T.cc, 'gr')}
      <td colspan="3"></td></tr>` : '';
    $('meta').textContent = `${out.length.toLocaleString()} of ${ROWS.length.toLocaleString()} customers · loaded in ${SECS}s`;
    window.__ciaOut = out;
  }

  /* second header row sits just under the first one when scrolling */
  function fixHeader() {
    const h1 = document.querySelector('#tbl thead tr.h1 th.grp');
    if (h1) $('tbl').style.setProperty('--h1', h1.offsetHeight + 'px');
  }
  window.addEventListener('resize', fixHeader);

  /* ── events ── */
  ['fQ', 'f6min', 'f6max', 'f3min', 'f3max', 'fC6', 'fTh'].forEach(id => $(id).addEventListener('input', render));
  ['fRoute', 'fBasis', 'fPay'].forEach(id => $(id).addEventListener('change', render));
  $('chips').addEventListener('click', e => { const c = e.target.closest('.chip'); if (!c) return; stFilter = c.dataset.st; render(); });
  document.querySelectorAll('#tbl th[data-k]').forEach(th => th.addEventListener('click', () => {
    const k = th.dataset.k;
    if (sortK === k) sortDir = -sortDir; else { sortK = k; sortDir = (k === 'tc' || k === 'name' || k === 'st') ? 1 : -1; }
    render();
  }));
  $('fReset').addEventListener('click', () => {
    ['fQ', 'f6min', 'f6max', 'f3min', 'f3max', 'fC6'].forEach(id => $(id).value = '');
    $('fRoute').value = ''; $('fPay').value = ''; $('fBasis').value = 'prev6'; $('fTh').value = 30; stFilter = ''; render();
  });

  /* ── CSV export (filtered rows) ── */
  $('btnCsv').addEventListener('click', () => {
    const q = v => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
    const n = v => (Math.round((v || 0) * 100) / 100).toFixed(2);
    const lines = [[`Credit Limit Violation Report - ${META.cur}`].map(q).join(','),
      ['', '', '', '', 'BILL VALUE', '', '', '', '', '', '', '', 'BILL COUNT (value / bill count)', '', '', '', '', ''].map(q).join(','),
      ['T CODE', 'Customer Name', 'Route', 'Pay Mode',
       `Last 6 Months (${META.r6})`, '6M Invoice Count', `Last 3 Months (${META.r3})`, '3M Invoice Count',
       `Last Month (${META.prev})`, 'Last Month Bill Count', `Current Month (${META.cur})`, 'Current Month Bill Count',
       'Last 6 Months Value/Count', 'Last 3 Months Value/Count', 'Last Month Value/Count', 'Current Month Value/Count',
       `Movement % (${BASIS_LBL[$('fBasis').value]})`, 'Status'].map(q).join(',')];
    for (const r of (window.__ciaOut || []))
      lines.push([q(r.tc), q(r.name), q(r.route_name), q(r.pay || 'not set'), n(r.a6), r.c6, n(r.a3), r.c3, n(r.tp), r.cp, n(r.tc_), r.cc,
                  r.c6 ? n(r.v6) : '', r.c3 ? n(r.v3) : '', r.cp ? n(r.vp) : '', r.cc ? n(r.vc) : '',
                  r.mv === null ? '' : r.mv.toFixed(1), q(STATUS[r.st].l)].join(','));
    const blob = new Blob(['\uFEFF' + lines.join('\r\n')], {type: 'text/csv;charset=utf-8'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = `credit_limit_violation_${MONTH}.csv`;
    document.body.appendChild(a); a.click(); a.remove();
  });

  /* ── invoices popup ──
     Moved to <body> so it is not trapped inside the header/content wrapper's
     stacking context (which made the header cover the popup and its loader). */
  const pop = $('pop');
  document.body.appendChild(pop);
  function openPop()  { pop.classList.add('open');    document.body.classList.add('cia-lock'); }
  function closePop() { pop.classList.remove('open'); document.body.classList.remove('cia-lock'); }
  $('popX').addEventListener('click', closePop);
  pop.addEventListener('click', e => { if (e.target === pop) closePop(); });
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (help.classList.contains('open')) closeHelp(); else if (pop.classList.contains('open')) closePop();
  });

  /* ── help / info window (English + Sinhala) ── */
  const help = $('help');
  document.body.appendChild(help);
  const HELP_TXT = {en: ['How this report works', 'Credit Limit Violation Report — guide'],
                    si: ['මෙම වාර්තාව ක්‍රියා කරන ආකාරය', 'Credit Limit Violation Report — මාර්ගෝපදේශය']};
  function setLang(l) {
    help.querySelectorAll('.hb').forEach(d => d.style.display = d.dataset.lang === l ? '' : 'none');
    help.querySelectorAll('.lang button').forEach(b => b.classList.toggle('on', b.dataset.l === l));
    $('helpT').textContent = HELP_TXT[l][0]; $('helpS').textContent = HELP_TXT[l][1];
    try { localStorage.setItem('cia_help_lang', l); } catch (e) {}
  }
  function openHelp()  { help.classList.add('open'); document.body.classList.add('cia-lock'); help.querySelector('.cia-body').scrollTop = 0; }
  function closeHelp() { help.classList.remove('open'); if (!pop.classList.contains('open')) document.body.classList.remove('cia-lock'); }
  $('btnInfo').addEventListener('click', openHelp);
  $('helpX').addEventListener('click', closeHelp);
  help.addEventListener('click', e => { if (e.target === help) closeHelp(); });
  help.querySelectorAll('.lang button').forEach(b => b.addEventListener('click', () => setLang(b.dataset.l)));
  try { const l = localStorage.getItem('cia_help_lang'); if (l === 'si' || l === 'en') setLang(l); } catch (e) {}

  $('tb').addEventListener('click', e => {
    const tr = e.target.closest('tr.cust'); if (!tr) return;
    const r = ROWS.find(x => x.tc === tr.dataset.tc); if (!r) return;
    $('popT').innerHTML = `<span class="tc" style="font-family:'Courier New',monospace;background:#eef2ff;color:#3730a3;border-radius:5px;padding:1px 6px;">${esc(r.tc)}</span> ${esc(r.name)}
      <span class="st ${r.st}" style="display:inline-block;font-size:10px;font-weight:800;border-radius:9px;padding:2px 8px;color:#fff;background:${STATUS[r.st].c}">${STATUS[r.st].l}</span>`;
    $('popS').textContent = `6M avg Rs. ${fmt(r.a6)} (${r.c6} inv.) · 3M avg Rs. ${fmt(r.a3)} (${r.c3} inv.) · avg bill 6M Rs. ${fmt(r.v6)} / 3M Rs. ${fmt(r.v3)}` + (r.route_name ? ` · ${r.route_name}` : '') + ` · Pay mode: ${r.pay || 'not set'}`;
    $('popInv').href = 'invoices.php?customer=' + encodeURIComponent(r.tc);
    $('popLed').href = 'customer_ledger.php?t_code=' + encodeURIComponent(r.tc);
    $('popB').innerHTML = '<div style="padding:30px;text-align:center;color:#6b7280"><i class="fa-solid fa-spinner fa-spin"></i> Loading invoices…</div>';
    openPop();
    fetch(SELF + '?ajax=invoices&month=' + encodeURIComponent(MONTH) + '&tc=' + encodeURIComponent(r.tc), {credentials: 'same-origin'})
      .then(x => x.json())
      .then(d => {
        if (d.error) { $('popB').innerHTML = `<div class="err" style="background:#fef2f2;color:#991b1b;padding:10px;border-radius:8px">${esc(d.error)}</div>`; return; }
        showInvoices(r, d.invoices || []);
      })
      .catch(err => { $('popB').innerHTML = `<div style="padding:20px;color:#b91c1c">Could not load the invoices (${esc(err.message)}).</div>`; });
  });

  function showInvoices(r, list) {
    const avgBill = r.v6;
    const byM = {};
    META.yms.forEach(ym => byM[ym] = []);
    list.forEach(i => { const ym = i.date.slice(0, 7); (byM[ym] = byM[ym] || []).push(i); });
    /* month boxes */
    let sum = '<div class="cia-sum">';
    META.yms.forEach((ym, k) => {
      const L = byM[ym].filter(i => i.val > 0.005), tot = L.reduce((a, i) => a + i.val, 0);
      const cmpTo = k === 6 ? tot * pace : tot;
      const cls = r.a6 > 0 ? (cmpTo <= r.a6 * 0.7 ? 'dn' : (cmpTo >= r.a6 * 1.3 ? 'up' : '')) : '';
      sum += `<div class="${cls}${k >= 5 ? ' hi' : ''}" data-go="${ym}">${META.months[k]}${k === 6 ? ' (now)' : ''}<b>${fmt(tot)}</b>${L.length} bills</div>`;
    });
    sum += '</div>';
    let html = sum;
    [...META.yms].reverse().forEach(ym => {
      const L = byM[ym]; if (!L.length) return;
      const P = L.filter(i => i.val > 0.005), tot = P.reduce((a, i) => a + i.val, 0);
      const lbl = new Date(ym + '-01T00:00:00').toLocaleDateString('en-GB', {month: 'long', year: 'numeric'});
      html += `<div class="cia-m" id="m-${ym}"><h4><span>${lbl}</span><span class="grow"></span>
        <span>${P.length} bills</span><span>Total <b>Rs. ${fmt(tot)}</b></span><span>Avg bill <b>Rs. ${fmt(P.length ? tot / P.length : 0)}</b></span></h4>
        <table><thead><tr><th>Invoice</th><th>Delivery date</th><th>SR</th><th>Field summary</th><th>Route</th>
        <th class="r">Ikea value</th><th class="r">Net value</th><th class="r">Invoice value</th><th class="r">vs avg bill</th></tr></thead><tbody>`;
      L.forEach(i => {
        const ratio = avgBill > 0 && i.val > 0.005 ? i.val / avgBill : null;
        const cls = ratio === null ? '' : (ratio >= 2 ? 'big' : (ratio <= 0.4 ? 'small' : ''));
        const rTxt = ratio === null ? (i.val > 0.005 ? '' : '<span class="muted">not counted</span>')
          : `<span class="mv ${ratio >= 2 ? 'up' : (ratio <= 0.4 ? 'dn' : 'ok')}" style="display:inline-block;font-weight:800;font-size:11px;border-radius:9px;padding:1px 7px">${ratio.toFixed(1)}×</span>`;
        html += `<tr class="${cls}"><td><a href="view_invoice.php?id=${i.id}" target="_blank">${esc(i.inv)}</a></td>
          <td>${esc(i.date)}</td><td>${esc(i.sr)}</td><td>${esc(i.fs)}</td><td>${esc(i.route)}</td>
          <td class="r">${i.ikea ? fmt(i.ikea) : '<span class="muted">—</span>'}</td><td class="r">${fmt(i.net)}</td>
          <td class="r"><b>${fmt(i.val)}</b></td><td class="r">${rTxt}</td></tr>`;
      });
      html += '</tbody></table></div>';
    });
    if (!list.length) html += '<div style="padding:24px;text-align:center;color:#6b7280">No invoices in these months.</div>';
    html += `<div style="font-size:11.5px;color:#6b7280;margin-top:6px">Month boxes: red = 30% or more below the 6M average, amber = 30% or more above
      (current month at its pace). Invoice rows: amber = 2× or more the customer's 6M average bill, grey = 0.4× or less.</div>`;
    $('popB').innerHTML = html;
    $('popB').querySelectorAll('[data-go]').forEach(b => b.addEventListener('click', () => {
      const t = document.getElementById('m-' + b.dataset.go); if (t) t.scrollIntoView({behavior: 'smooth', block: 'start'});
    }));
  }

  render();
  fixHeader();
})();
</script>
<?php include 'footer.php'; ?>