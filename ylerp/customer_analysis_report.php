<?php
/**
 * customer_analysis_report.php — ANALYZE THE CUSTOMERS
 * ─────────────────────────────────────────────────────────────────────
 * Upload an Excel (NEW SR | Outlet code | Outlet Name) and every outlet is analysed against the system data:
 *   • found in the system? (outlet code = customers.t_code, or the Sampath outlet code)
 *   • BLACKLISTED customer (with reason)             • pay mode (cash / credit / cheque)
 *   • "Cheques will delay" customer                  • TOTAL OUTSTANDING (credit bills still unpaid today)
 *   • RETURNED cheques (count, value, still unsettled)
 *   • CREDIT policy (credit customers) / CHEQUE policy (cheque customers) VIOLATED and HOW MANY TIMES
 *       a bill violates the policy when it was paid after, or is still unpaid past,
 *       delivery date + the customer's credit policy days.
 *   • HISTORY per customer (every bill with its due date / paid date / days over, every returned cheque)
 * The analysis can be SAVED (named), re-opened later and DELETED.   Excel export of the shown analysis.
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
include_once __DIR__ . '/report_extras_lib.php';
rx_ensure($conn);
function ca_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ca_user() {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ?? $_SESSION['full_name'] ?? $_SESSION['email'] ?? 'user';
}

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS customer_analysis_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_name VARCHAR(200) NOT NULL,
    file_name VARCHAR(255) NULL,
    total_rows INT NOT NULL DEFAULT 0,
    as_at DATE NULL,
    created_by VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data LONGTEXT NOT NULL,
    INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ═══════════════════════════ AJAX ═══════════════════════════ */
$ajax = $_POST['action'] ?? ($_GET['ajax'] ?? '');
if ($ajax !== '') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(300);

    /* ---- saved report list ---- */
    if ($ajax === 'list') {
        $o = [];
        $r = mysqli_query($conn, "SELECT id, report_name, file_name, total_rows, as_at, created_by, created_at FROM customer_analysis_reports ORDER BY id DESC LIMIT 200");
        while ($r && ($x = mysqli_fetch_assoc($r))) $o[] = $x;
        echo json_encode(['success' => true, 'reports' => $o]); exit;
    }
    /* ---- open a saved report ---- */
    if ($ajax === 'load') {
        $id = intval($_GET['id'] ?? 0);
        $r = mysqli_query($conn, "SELECT report_name, file_name, as_at, created_by, created_at, data FROM customer_analysis_reports WHERE id = $id LIMIT 1");
        $x = $r ? mysqli_fetch_assoc($r) : null;
        if (!$x) { echo json_encode(['success' => false, 'message' => 'Report not found.']); exit; }
        $json = @gzuncompress(base64_decode($x['data']));
        $rows = $json !== false ? json_decode($json, true) : null;
        if (!is_array($rows)) { echo json_encode(['success' => false, 'message' => 'Saved data could not be read.']); exit; }
        echo json_encode(['success' => true, 'name' => $x['report_name'], 'file' => $x['file_name'], 'as_at' => $x['as_at'], 'by' => $x['created_by'], 'at' => $x['created_at'], 'rows' => $rows]); exit;
    }
    /* ---- save (needs Add right: action name starts with "save_") ---- */
    if ($ajax === 'save_report') {
        $name = trim($_POST['name'] ?? '');
        $rows = json_decode($_POST['rows'] ?? '', true);
        if ($name === '' || !is_array($rows) || !$rows) { echo json_encode(['success' => false, 'message' => 'Give the report a name and analyse a file first.']); exit; }
        $blob = base64_encode(gzcompress(json_encode($rows, JSON_UNESCAPED_UNICODE), 6));
        $n = mysqli_real_escape_string($conn, mb_substr($name, 0, 200));
        $f = mysqli_real_escape_string($conn, mb_substr(trim($_POST['file'] ?? ''), 0, 255));
        $u = mysqli_real_escape_string($conn, ca_user());
        $a = date('Y-m-d');
        $ok = mysqli_query($conn, "INSERT INTO customer_analysis_reports (report_name, file_name, total_rows, as_at, created_by, data) VALUES ('$n', '$f', " . count($rows) . ", '$a', '$u', '" . mysqli_real_escape_string($conn, $blob) . "')");
        echo json_encode($ok ? ['success' => true, 'id' => mysqli_insert_id($conn)] : ['success' => false, 'message' => 'Could not save: ' . mysqli_error($conn)]); exit;
    }
    /* ---- delete (needs Delete right: action name starts with "delete_") ---- */
    if ($ajax === 'delete_report') {
        $id = intval($_POST['id'] ?? 0);
        $ok = $id > 0 && mysqli_query($conn, "DELETE FROM customer_analysis_reports WHERE id = $id");
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? '' : 'Could not delete.']); exit;
    }
    /* ---- analyse the uploaded rows ---- */
    if ($ajax === 'analyze') {
        $in = json_decode($_POST['rows'] ?? '', true);
        if (!is_array($in) || !$in) { echo json_encode(['success' => false, 'message' => 'No rows received.']); exit; }
        $today = date('Y-m-d'); $today_ts = strtotime($today);
        $rows = []; $codes = [];
        foreach ($in as $r) {
            $code = trim((string)($r['code'] ?? ''));
            if ($code === '') continue;
            $rows[] = ['sr' => trim((string)($r['sr'] ?? '')), 'code' => $code, 'file_name' => trim((string)($r['name'] ?? ''))];
            $codes[$code] = true;
        }
        if (!$rows) { echo json_encode(['success' => false, 'message' => 'No outlet codes found in the file.']); exit; }
        $list = "'" . implode("','", array_map(fn($c) => mysqli_real_escape_string($conn, $c), array_keys($codes))) . "'";

        /* system customers (by t_code, or the Sampath outlet code) */
        $hasCwd = rx_has_col($conn, 'customers', 'cheques_will_delay');
        $hasBl  = rx_has_col($conn, 'customers', 'blacklisted');
        $hasSp  = rx_has_col($conn, 'customers', 'special_credit_policy_days');
        $hasSo  = rx_has_col($conn, 'customers', 'sampath_outlet_code');
        $cust = []; $by_alt = [];
        $q = mysqli_query($conn, "SELECT c.t_code, c.shop_name, LOWER(TRIM(COALESCE(c.payment_mode,''))) AS pm, COALESCE(c.credit_days,0) AS credit_days, COALESCE(c.credit_limit,0) AS credit_limit,
                    " . ($hasCwd ? "COALESCE(c.cheques_will_delay,0)" : "0") . " AS cwd,
                    " . ($hasBl ? "COALESCE(c.blacklisted,0)" : "0") . " AS bl, " . ($hasBl ? "c.blacklist_reason" : "NULL") . " AS bl_reason, " . ($hasBl ? "c.blacklist_date" : "NULL") . " AS bl_date,
                    " . ($hasSp ? "c.special_credit_policy_days" : "NULL") . " AS special_days,
                    " . ($hasSo ? "c.sampath_outlet_code" : "NULL") . " AS alt_code
                  FROM customers c WHERE c.t_code IN ($list)" . ($hasSo ? " OR c.sampath_outlet_code IN ($list)" : ""));
        while ($q && ($x = mysqli_fetch_assoc($q))) {
            $cust[$x['t_code']] = $x;
            if (!empty($x['alt_code'])) $by_alt[$x['alt_code']] = $x['t_code'];
        }
        $tcode_of = function ($code) use ($cust, $by_alt) { return isset($cust[$code]) ? $code : ($by_alt[$code] ?? null); };
        $tcodes = []; foreach ($codes as $c => $_) { $t = $tcode_of($c); if ($t !== null) $tcodes[$t] = true; }
        $tlist = $tcodes ? "'" . implode("','", array_map(fn($c) => mysqli_real_escape_string($conn, $c), array_keys($tcodes))) . "'" : "''";

        /* every credit bill of those customers: balance today + the last payment date */
        $P = rx_inv_parts($conn, $today);
        $bills = [];   // t_code => [bill…]
        $q = mysqli_query($conn, "
            SELECT fsd.t_code, fsd.invoice_num, fs.delivery_date, {$P['net']} AS net, {$P['balance']} AS balance, lpd.lp AS last_paid
            FROM field_summary_details fsd
            INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
            {$P['joins']}
            LEFT JOIN (SELECT field_summary_detail_id AS did, MAX(payment_date) AS lp FROM invoice_payments WHERE is_reversed = 0 GROUP BY field_summary_detail_id) lpd ON lpd.did = fsd.id
            WHERE fsd.updated = 1 AND fsd.t_code IN ($tlist) AND fs.delivery_date <= '$today'
            ORDER BY fs.delivery_date DESC");
        while ($q && ($b = mysqli_fetch_assoc($q))) $bills[$b['t_code']][] = $b;

        /* returned / sent-back cheques */
        $chq = [];
        $q = mysqli_query($conn, "SELECT ch.t_code, ch.cheque_no, ch.cheque_date, ch.bank_code, ch.status, COALESCE(ch.total_amount, ch.amount, 0) AS amount,
                    COALESCE(ch.return_settled,0) AS ret_settled, COALESCE(ch.sb_settled,0) AS sb_settled
                  FROM cheques ch WHERE ch.t_code IN ($tlist) AND ch.status IN ('returned','sent_back') ORDER BY ch.cheque_date DESC");
        while ($q && ($c = mysqli_fetch_assoc($q))) $chq[$c['t_code']][] = $c;

        $out = [];
        foreach ($rows as $r) {
            $t = $tcode_of($r['code']);
            $row = ['sr' => $r['sr'], 'code' => $r['code'], 'file_name' => $r['file_name'], 'found' => $t !== null, 't_code' => $t];
            if ($t === null) { $out[] = $row; continue; }
            $c = $cust[$t]; $days = (int)$c['credit_days']; $pm = $c['pm'];
            $policy = ($pm === 'cheque' || (int)$c['cwd'] === 1) ? 'cheque' : ($pm === 'credit' ? 'credit' : 'none');
            $outstanding = 0.0; $out_n = 0; $viol = 0; $max_over = 0; $cur_over = 0; $hist = [];
            foreach ($bills[$t] ?? [] as $b) {
                $bal = (float)$b['balance']; $del = strtotime($b['delivery_date']);
                $due = $del + $days * 86400;
                $open = $bal > 0.005;
                if ($open) { $outstanding += $bal; $out_n++; }
                $ref = $open ? $today_ts : ($b['last_paid'] ? strtotime($b['last_paid']) : null);
                $over = ($ref !== null) ? (int)floor(($ref - $due) / 86400) : 0;
                $violated = ($policy !== 'none') && $over > 0;
                if ($violated) { $viol++; if ($over > $max_over) $max_over = $over; if ($open) $cur_over++; }
                if (count($hist) < 300) $hist[] = ['inv' => (string)$b['invoice_num'], 'del' => $b['delivery_date'], 'due' => date('Y-m-d', $due), 'paid' => $open ? null : $b['last_paid'],
                                                   'net' => round((float)$b['net'], 2), 'bal' => round($bal, 2), 'over' => $over, 'v' => $violated ? 1 : 0, 'open' => $open ? 1 : 0];
            }
            $rc = []; $ret_n = 0; $ret_amt = 0.0; $ret_open = 0.0;
            foreach ($chq[$t] ?? [] as $k) {
                $settled = ($k['status'] === 'sent_back') ? (int)$k['sb_settled'] : (int)$k['ret_settled'];
                $ret_n++; $ret_amt += (float)$k['amount']; if (!$settled) $ret_open += (float)$k['amount'];
                $rc[] = ['no' => (string)$k['cheque_no'], 'date' => $k['cheque_date'], 'bank' => (string)$k['bank_code'], 'status' => $k['status'], 'amt' => round((float)$k['amount'], 2), 'settled' => $settled];
            }
            $row += ['name' => $c['shop_name'], 'pm' => $pm, 'days' => $days, 'special' => $c['special_days'], 'cwd' => (int)$c['cwd'], 'bl' => (int)$c['bl'],
                     'bl_reason' => $c['bl_reason'], 'bl_date' => $c['bl_date'], 'policy' => $policy, 'outstanding' => round($outstanding, 2), 'out_n' => $out_n,
                     'ret_n' => $ret_n, 'ret_amt' => round($ret_amt, 2), 'ret_open' => round($ret_open, 2),
                     'viol' => $viol, 'max_over' => $max_over, 'cur_over' => $cur_over, 'total_bills' => count($bills[$t] ?? []), 'bills' => $hist, 'cheques' => $rc];
            $out[] = $row;
        }
        echo json_encode(['success' => true, 'rows' => $out, 'as_at' => $today]); exit;
    }
    echo json_encode(['success' => false, 'message' => 'Unknown action.']); exit;
}
ob_end_flush();
include 'header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<style>
.ca, .ca *, .ca *::before, .ca *::after { box-sizing:border-box; }
.ca { --ink:#111827; --muted:#6b7280; --line:#e5e7eb; font-family:'Inter',system-ui,sans-serif; font-size:13px; color:var(--ink); background:#f4f5f8; padding:22px 26px 48px; min-height:100%; }
.ca h1 { margin:0; font-size:22px; font-weight:800; display:flex; align-items:center; gap:10px; } .ca h1 .ic { width:40px; height:40px; border-radius:10px; background:linear-gradient(135deg,#0f766e,#134e4a); color:#fff; display:flex; align-items:center; justify-content:center; font-size:16px; }
.ca .sub { margin:6px 0 16px; color:var(--muted); max-width:115ch; }
.ca .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:14px 16px; margin-bottom:14px; }
.ca .row { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; } .ca .fld { display:flex; flex-direction:column; gap:4px; }
.ca .fld label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
.ca input[type=text], .ca input[type=search], .ca input[type=file] { height:36px; padding:6px 10px; border:1px solid #d1d5db; border-radius:8px; font:inherit; background:#fff; min-width:240px; }
.ca .btn { display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 16px; border-radius:8px; border:1px solid transparent; background:#111827; color:#fff; font:inherit; font-weight:700; cursor:pointer; text-decoration:none; }
.ca .btn.teal { background:#0f766e; } .ca .btn.light { background:#fff; color:var(--ink); border-color:#d1d5db; } .ca .btn.xl { background:#14532d; } .ca .btn.red { background:#fff; color:#b91c1c; border-color:#fecaca; } .ca .btn:disabled { opacity:.45; cursor:not-allowed; }
.ca .kpis { display:grid; grid-template-columns:repeat(8,1fr); gap:10px; margin-bottom:12px; }
.ca .kpi { background:#fff; border:1px solid var(--line); border-radius:12px; padding:11px 13px; position:relative; overflow:hidden; cursor:pointer; }
.ca .kpi::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:var(--c,#111827); } .ca .kpi.on { outline:2px solid var(--c,#111827); }
.ca .kpi .l { font-size:10px; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; } .ca .kpi .n { font-size:21px; font-weight:800; margin-top:2px; font-variant-numeric:tabular-nums; } .ca .kpi .v { font-size:11.5px; color:#374151; font-weight:600; }
.ca .wrap { overflow:auto; max-height:70vh; } .ca table.t { width:100%; border-collapse:collapse; min-width:1250px; }
.ca .t th { position:sticky; top:0; z-index:2; padding:9px 10px; background:#134e4a; color:#fff; font-size:11px; font-weight:700; text-align:center; white-space:nowrap; border:1px solid #0f3f3b; }
.ca .t td { padding:8px 10px; border:1px solid #eef0f3; text-align:center; vertical-align:middle; font-variant-numeric:tabular-nums; } .ca .t td.l { text-align:left; } .ca .t td.r { text-align:right; }
.ca .t tbody tr:hover td { background:#f0fdfa; } .ca .t tr.bl td { background:#fef2f2; } .ca .t tr.nf td { background:#f9fafb; color:#9ca3af; }
.ca .b { display:inline-block; padding:2px 9px; border-radius:99px; font-size:11px; font-weight:800; border:1px solid; white-space:nowrap; }
.ca .b.red { color:#b91c1c; background:#fef2f2; border-color:#fecaca; } .ca .b.green { color:#15803d; background:#f0fdf4; border-color:#bbf7d0; } .ca .b.amber { color:#b45309; background:#fffbeb; border-color:#fde68a; }
.ca .b.blue { color:#1d4ed8; background:#eff6ff; border-color:#bfdbfe; } .ca .b.purple { color:#6d28d9; background:#f5f3ff; border-color:#ddd6fe; } .ca .b.grey { color:#6b7280; background:#f3f4f6; border-color:#e5e7eb; }
.ca .tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px; } .ca .tabs button { padding:6px 14px; border-radius:99px; border:1px solid var(--line); background:#fff; font:inherit; font-weight:700; font-size:12px; cursor:pointer; color:#374151; } .ca .tabs button.on { background:#111827; border-color:#111827; color:#fff; }
.ca .saved { display:grid; grid-template-columns:repeat(auto-fill,minmax(290px,1fr)); gap:10px; } .ca .sv { border:1px solid var(--line); border-radius:10px; padding:10px 12px; background:#fafafa; } .ca .sv b { display:block; font-size:13.5px; } .ca .sv small { color:var(--muted); display:block; margin:2px 0 8px; } .ca .sv .a { display:flex; gap:6px; }
.ca .sv .btn { height:30px; padding:0 12px; font-size:12px; }
.ca .empty { padding:28px; text-align:center; color:var(--muted); }
@media (max-width:1100px) { .ca .kpis { grid-template-columns:repeat(4,1fr); } } @media (max-width:560px) { .ca { padding:16px 12px 40px; } .ca .kpis { grid-template-columns:1fr 1fr; } }
</style>

<div class="ca" id="ca">
    <h1><span class="ic"><i class="fa-solid fa-magnifying-glass-chart"></i></span> Analyze the Customers</h1>
    <p class="sub">Upload the Excel (<b>NEW SR · Outlet code · Outlet Name</b>). Every outlet is checked against the system: <b>blacklisted</b>, <b>pay mode</b>, <b>cheques will delay</b>, <b>total outstanding</b>, <b>returned cheques</b>, and whether the <b>credit / cheque policy</b> was violated — and how many times. Open a row for its history. Save the analysis to open it again later.</p>

    <div class="card">
        <div class="row">
            <div class="fld"><label for="caFile">Excel file</label><input type="file" id="caFile" accept=".xlsx,.xls"></div>
            <button class="btn teal" id="caRun" type="button" disabled><i class="fa-solid fa-play"></i> Analyze</button>
            <span id="caStatus" class="muted" style="color:#6b7280"></span>
        </div>
    </div>

    <div id="caResult" style="display:none">
        <div class="kpis" id="caKpis"></div>
        <div class="card">
            <div class="row" style="margin-bottom:10px">
                <div class="fld"><label for="caQ">Search</label><input type="search" id="caQ" placeholder="outlet code, name or SR"></div>
                <div class="fld"><label for="caName">Report name</label><input type="text" id="caName" placeholder="e.g. SMN outlets — Nov 2026"></div>
                <button class="btn teal" id="caSave" type="button"><i class="fa-solid fa-floppy-disk"></i> Save report</button>
                <button class="btn xl" id="caXls" type="button"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
                <span id="caTitle" style="margin-left:auto;font-weight:700;color:#134e4a"></span>
            </div>
            <div class="tabs" id="caTabs"></div>
            <div class="wrap"><table class="t"><thead><tr>
                <th>#</th><th>NEW SR</th><th>Outlet code</th><th class="l">Outlet name</th><th>Blacklisted</th><th>Pay mode</th><th>Cheques will delay</th>
                <th>Total outstanding (Rs.)</th><th>Returned cheques</th><th>Policy</th><th>Violated</th><th>History</th></tr></thead>
                <tbody id="caBody"></tbody></table></div>
            <div class="empty" id="caEmpty" style="display:none">No customers match.</div>
        </div>
    </div>

    <div class="card">
        <div class="row" style="margin-bottom:10px"><b style="font-size:15px"><i class="fa-solid fa-folder-open" style="color:#0f766e"></i> Saved reports</b></div>
        <div class="saved" id="caSaved"><div class="muted">Loading…</div></div>
    </div>
</div>

<!-- history viewer: moved to <body> by the script so it always sits above the site header / sidebar -->
<div class="cam" id="cam" role="dialog" aria-modal="true" aria-label="Customer history">
    <div class="box">
        <div class="top"><div><div class="ttl" id="hName">Customer</div><div class="sb" id="hSub"></div></div><span class="sp"></span>
            <button class="nb" id="hPrev" type="button">◀ Previous</button><button class="nb" id="hNext" type="button">Next ▶</button>
            <button class="x" id="hClose" type="button" aria-label="Close">&times;</button></div>
        <div class="sum" id="hSum"></div>
        <div class="tabs2"><button type="button" class="on" data-t="bills">Bills &amp; policy history</button><button type="button" data-t="chq">Returned cheques</button></div>
        <div class="tw" id="hBody"></div>
    </div>
</div>
<style>
.cam { display:none; position:fixed; inset:0; z-index:2147483000; background:rgba(15,23,42,.8); backdrop-filter:blur(3px); padding:2vh 2vw; font-family:'Inter',system-ui,sans-serif; font-size:13px; color:#111827; }
.cam.open { display:block; } .cam *, .cam *::before, .cam *::after { box-sizing:border-box; }
.cam .box { background:#fff; border-radius:16px; width:100%; max-width:1200px; height:100%; margin:0 auto; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 24px 80px rgba(0,0,0,.55); }
.cam .top { display:flex; align-items:center; gap:12px; padding:14px 20px; background:linear-gradient(90deg,#134e4a,#0f766e); color:#fff; flex-shrink:0; } .cam .ttl { font-size:18px; font-weight:800; } .cam .sb { font-size:12px; opacity:.85; margin-top:2px; } .cam .sp { flex:1; }
.cam .nb { height:36px; padding:0 16px; border:1px solid rgba(255,255,255,.5); border-radius:9px; background:rgba(255,255,255,.15); color:#fff; font:inherit; font-weight:700; cursor:pointer; } .cam .nb:disabled { opacity:.4; cursor:default; }
.cam .x { width:36px; height:36px; border-radius:50%; border:none; background:#dc2626; color:#fff; font-size:20px; cursor:pointer; line-height:1; }
.cam .sum { display:grid; grid-template-columns:repeat(6,1fr); gap:10px; padding:14px 20px; background:#f8fafc; border-bottom:1px solid #e5e7eb; flex-shrink:0; }
.cam .sum > div { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:9px 12px; } .cam .sum .l { font-size:10px; font-weight:800; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; } .cam .sum .v { font-size:16px; font-weight:800; margin-top:2px; font-variant-numeric:tabular-nums; }
.cam .tabs2 { display:flex; gap:6px; padding:10px 20px; border-bottom:1px solid #e5e7eb; flex-shrink:0; } .cam .tabs2 button { padding:6px 14px; border-radius:99px; border:1px solid #e5e7eb; background:#fff; font:inherit; font-weight:700; font-size:12px; cursor:pointer; } .cam .tabs2 button.on { background:#111827; border-color:#111827; color:#fff; }
.cam .tw { flex:1; min-height:0; overflow:auto; } .cam table { width:100%; border-collapse:collapse; } .cam th { position:sticky; top:0; padding:9px 12px; background:#134e4a; color:#fff; font-size:11px; font-weight:700; text-align:center; white-space:nowrap; }
.cam td { padding:8px 12px; border-bottom:1px solid #eef0f3; text-align:center; white-space:nowrap; font-variant-numeric:tabular-nums; } .cam td.l { text-align:left; font-weight:700; } .cam td.r { text-align:right; font-weight:700; } .cam tr.v td { background:#fff5f5; }
.cam .b { display:inline-block; padding:2px 9px; border-radius:99px; font-size:11px; font-weight:800; border:1px solid; } .cam .b.red { color:#b91c1c; background:#fef2f2; border-color:#fecaca; } .cam .b.green { color:#15803d; background:#f0fdf4; border-color:#bbf7d0; } .cam .b.amber { color:#b45309; background:#fffbeb; border-color:#fde68a; }
.cam .none { padding:30px; text-align:center; color:#6b7280; }
@media (max-width:760px) { .cam { padding:0; } .cam .box { border-radius:0; } .cam .sum { grid-template-columns:1fr 1fr; } }
</style>

<script>
(function(){
  var ROWS = [], VIEW = [], TAB = 'all', CUR = -1, HT = 'bills', FILE = '', LOADED = '';
  var $ = function(id){ return document.getElementById(id); };
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function money(v){ return Number(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function fd(d){ if(!d) return '—'; var t=new Date(String(d).replace(/-/g,'/')); return isNaN(t)?d:t.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}); }
  function post(action, data){ var fd=new FormData(); fd.append('action',action); Object.keys(data||{}).forEach(function(k){ fd.append(k,data[k]); });
    return fetch('customer_analysis_report.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){ return r.json().catch(function(){ return {success:false,message:'Server error (blocked or invalid response).'}; }); }); }

  /* ── 1. choose + read the Excel ── */
  var parsed = [];
  $('caFile').addEventListener('change', function(){
    var f=this.files[0]; parsed=[]; $('caRun').disabled=true; if(!f){ $('caStatus').textContent=''; return; }
    FILE=f.name; var rd=new FileReader();
    rd.onload=function(e){
      try{
        var wb=XLSX.read(e.target.result,{type:'array'}), ws=wb.Sheets[wb.SheetNames[0]], a=XLSX.utils.sheet_to_json(ws,{header:1,defval:''}), hi=0, iSr=0, iCode=1, iName=2;
        for(var i=0;i<Math.min(a.length,10);i++){ var low=a[i].map(function(x){ return String(x).toLowerCase().trim(); });
          var c=low.findIndex(function(x){ return x.indexOf('outlet code')>-1 || x==='t code' || x==='t-code' || x==='tcode'; });
          if(c>-1){ hi=i; iCode=c; var s=low.findIndex(function(x){ return x.indexOf('sr')>-1; }); if(s>-1) iSr=s; var n=low.findIndex(function(x){ return x.indexOf('name')>-1; }); if(n>-1) iName=n; break; } }
        for(var j=hi+1;j<a.length;j++){ var code=String(a[j][iCode]||'').trim(); if(code) parsed.push({sr:String(a[j][iSr]||'').trim(),code:code,name:String(a[j][iName]||'').trim()}); }
        $('caStatus').textContent=parsed.length+' outlets found in "'+f.name+'".'; $('caRun').disabled=!parsed.length;
      }catch(err){ $('caStatus').textContent='Could not read the file: '+err.message; }
    };
    rd.readAsArrayBuffer(f);
  });
  $('caRun').addEventListener('click', function(){
    var b=this; b.disabled=true; $('caStatus').textContent='Analyzing '+parsed.length+' outlets… (this can take a minute)';
    post('analyze',{rows:JSON.stringify(parsed)}).then(function(res){
      b.disabled=false;
      if(!res.success){ $('caStatus').textContent=res.message||'Analysis failed.'; return; }
      ROWS=res.rows; LOADED=''; $('caName').value=(FILE||'Analysis').replace(/\.xlsx?$/i,'')+' — '+new Date().toLocaleDateString('en-GB'); $('caTitle').textContent='Analysis as at '+fd(res.as_at);
      $('caStatus').textContent='Done — '+ROWS.length+' outlets analysed.'; show();
    }).catch(function(){ b.disabled=false; $('caStatus').textContent='Connection problem — try again.'; });
  });

  /* ── 2. results ── */
  var TABS=[['all','All'],['bl','Blacklisted'],['cwd','Cheques will delay'],['ret','Returned cheques'],['viol','Policy violated'],['out','Outstanding'],['nf','Not in system']];
  function test(r,t){ if(t==='all') return true; if(t==='nf') return !r.found; if(!r.found) return false;
    return t==='bl'?r.bl:(t==='cwd'?r.cwd:(t==='ret'?r.ret_n>0:(t==='viol'?r.viol>0:(t==='out'?r.outstanding>0:true)))); }
  function show(){ $('caResult').style.display='block'; kpis(); tabs(); draw(); }
  function kpis(){
    var f=ROWS.filter(function(r){return r.found;}), sum=function(k){ return f.reduce(function(a,r){ return a+(r[k]||0); },0); };
    var K=[['all','Outlets in file',ROWS.length,'','#111827'],['nf','Not in system',ROWS.length-f.length,'','#6b7280'],['bl','Blacklisted',f.filter(function(r){return r.bl;}).length,'','#b91c1c'],
      ['cwd','Cheques will delay',f.filter(function(r){return r.cwd;}).length,'','#d97706'],['out','Outstanding',f.filter(function(r){return r.outstanding>0;}).length,'Rs. '+money(sum('outstanding')),'#2563eb'],
      ['ret','Returned cheques',f.filter(function(r){return r.ret_n>0;}).length,sum('ret_n')+' chq · Rs. '+money(sum('ret_amt')),'#7c3aed'],
      ['viol','Policy violated',f.filter(function(r){return r.viol>0;}).length,sum('viol')+' violations','#dc2626'],['all','Found in system',f.length,'','#15803d']];
    $('caKpis').innerHTML=K.map(function(k){ return '<div class="kpi '+(TAB===k[0]&&k[1]!=='Found in system'?'on':'')+'" style="--c:'+k[4]+'" data-t="'+k[0]+'"><div class="l">'+k[1]+'</div><div class="n" style="color:'+k[4]+'">'+k[2]+'</div><div class="v">'+k[3]+'&nbsp;</div></div>'; }).join('');
  }
  $('caKpis').addEventListener('click',function(e){ var k=e.target.closest('.kpi'); if(k){ TAB=k.dataset.t; tabs(); kpis(); draw(); } });
  function tabs(){ $('caTabs').innerHTML=TABS.map(function(t){ var n=ROWS.filter(function(r){return test(r,t[0]);}).length; return '<button type="button" data-t="'+t[0]+'" class="'+(TAB===t[0]?'on':'')+'">'+t[1]+' <span style="opacity:.7">'+n+'</span></button>'; }).join(''); }
  $('caTabs').addEventListener('click',function(e){ var b=e.target.closest('button'); if(b){ TAB=b.dataset.t; tabs(); kpis(); draw(); } });
  $('caQ').addEventListener('input',draw);
  var PM={cash:['green','Cash'],credit:['amber','Credit'],cheque:['purple','Cheque']};
  function draw(){
    var q=$('caQ').value.trim().toLowerCase(); VIEW=[];
    var h=''; ROWS.forEach(function(r,i){
      if(!test(r,TAB)) return; if(q && (r.code+' '+(r.name||'')+' '+r.file_name+' '+r.sr).toLowerCase().indexOf(q)<0) return; VIEW.push(i);
      if(!r.found){ h+='<tr class="nf"><td>'+VIEW.length+'</td><td>'+esc(r.sr)+'</td><td>'+esc(r.code)+'</td><td class="l">'+esc(r.file_name)+'</td><td colspan="8"><span class="b grey">Not found in the system</span></td></tr>'; return; }
      var pm=PM[r.pm]||['grey',r.pm||'—'], pol=r.policy==='cheque'?'Cheque policy':(r.policy==='credit'?'Credit policy':'—');
      h+='<tr class="'+(r.bl?'bl':'')+'" data-i="'+i+'"><td>'+VIEW.length+'</td><td>'+esc(r.sr)+'</td><td>'+esc(r.code)+'</td><td class="l"><b>'+esc(r.name||r.file_name)+'</b>'+(r.name&&r.file_name&&r.name.toLowerCase()!==r.file_name.toLowerCase()?'<br><small style="color:#9ca3af">file: '+esc(r.file_name)+'</small>':'')+'</td>'
       +'<td>'+(r.bl?'<span class="b red" title="'+esc(r.bl_reason||'')+'">BLACKLISTED</span>'+(r.bl_reason?'<br><small style="color:#b91c1c">'+esc(String(r.bl_reason).slice(0,40))+'</small>':''):'<span style="color:#d1d5db">—</span>')+'</td>'
       +'<td><span class="b '+pm[0]+'">'+esc(pm[1])+'</span>'+(r.pm==='credit'||r.pm==='cheque'?'<br><small style="color:#6b7280">'+r.days+' days</small>':'')+'</td>'
       +'<td>'+(r.cwd?'<span class="b amber">CHEQUES WILL DELAY</span>':'<span style="color:#d1d5db">—</span>')+'</td>'
       +'<td class="r" style="'+(r.outstanding>0?'color:#1d4ed8;font-weight:800':'color:#9ca3af')+'">'+(r.outstanding>0?money(r.outstanding)+'<br><small style="font-weight:600;color:#6b7280">'+r.out_n+' bill'+(r.out_n>1?'s':'')+'</small>':'0.00')+'</td>'
       +'<td>'+(r.ret_n>0?'<span class="b red">'+r.ret_n+' returned</span><br><small style="color:#b91c1c;font-weight:700">Rs. '+money(r.ret_amt)+(r.ret_open>0?' · open '+money(r.ret_open):'')+'</small>':'<span style="color:#d1d5db">—</span>')+'</td>'
       +'<td>'+pol+'</td>'
       +'<td>'+(r.policy==='none'?'<span style="color:#d1d5db">n/a</span>':(r.viol>0?'<span class="b red">VIOLATED × '+r.viol+'</span><br><small style="color:#b91c1c">max +'+r.max_over+'d'+(r.cur_over?' · '+r.cur_over+' overdue now':'')+'</small>':'<span class="b green">No violation</span>'))+'</td>'
       +'<td><button class="btn light" style="height:30px;padding:0 12px" data-h="'+i+'"><i class="fa-solid fa-clock-rotate-left"></i> View</button></td></tr>';
    });
    $('caBody').innerHTML=h; $('caEmpty').style.display=VIEW.length?'none':'block';
  }
  $('caBody').addEventListener('click',function(e){ var b=e.target.closest('[data-h]'); if(b) openH(parseInt(b.dataset.h,10)); });

  /* ── 3. history modal ── */
  function foundList(){ return VIEW.filter(function(i){ return ROWS[i].found; }); }
  function openH(i){ $('cam').classList.add('open'); document.body.style.overflow='hidden'; HT='bills'; showH(i); }
  function showH(i){
    CUR=i; var r=ROWS[i], list=foundList(), pos=list.indexOf(i);
    $('hName').textContent=(r.name||r.file_name)+' ('+r.t_code+')';
    $('hSub').textContent='SR '+(r.sr||'—')+' · '+(PM[r.pm]?PM[r.pm][1]:'—')+(r.pm==='credit'||r.pm==='cheque'?' · policy '+r.days+' days':'')+(r.special?' · special: '+r.special:'')+(r.bl?' · BLACKLISTED'+(r.bl_reason?': '+r.bl_reason:''):'')+(r.cwd?' · cheques will delay':'');
    $('hPrev').disabled=pos<=0; $('hNext').disabled=pos>=list.length-1;
    $('hSum').innerHTML='<div><div class="l">Total outstanding</div><div class="v" style="color:#1d4ed8">Rs. '+money(r.outstanding)+'</div></div><div><div class="l">Unpaid bills</div><div class="v">'+r.out_n+'</div></div>'
      +'<div><div class="l">Policy violated</div><div class="v" style="color:'+(r.viol?'#b91c1c':'#15803d')+'">'+(r.policy==='none'?'n/a':(r.viol?r.viol+' time'+(r.viol>1?'s':''):'Never'))+'</div></div>'
      +'<div><div class="l">Longest over policy</div><div class="v">'+(r.max_over?'+'+r.max_over+' days':'—')+'</div></div>'
      +'<div><div class="l">Returned cheques</div><div class="v" style="color:'+(r.ret_n?'#b91c1c':'#111827')+'">'+r.ret_n+' · Rs. '+money(r.ret_amt)+'</div></div>'
      +'<div><div class="l">Total bills</div><div class="v">'+r.total_bills+(r.total_bills>r.bills.length?' <small style="font-size:11px;color:#6b7280">(latest '+r.bills.length+' shown)</small>':'')+'</div></div>';
    [].forEach.call(document.querySelectorAll('.cam .tabs2 button'),function(b){ b.classList.toggle('on',b.dataset.t===HT); });
    var h='';
    if(HT==='bills'){
      h='<table><thead><tr><th>#</th><th class="l">Invoice</th><th>Delivery date</th><th>Policy due date</th><th>Paid on</th><th>Days over policy</th><th>Policy</th><th>Net (Rs.)</th><th>Balance (Rs.)</th></tr></thead><tbody>';
      r.bills.forEach(function(b,k){ var st=r.policy==='none'?'<span class="b green">n/a</span>':(b.v?'<span class="b red">VIOLATED</span>':'<span class="b green">Within policy</span>');
        h+='<tr class="'+(b.v?'v':'')+'"><td>'+(k+1)+'</td><td class="l">'+esc(b.inv||'—')+'</td><td>'+fd(b.del)+'</td><td>'+(r.policy==='none'?'—':fd(b.due))+'</td><td>'+(b.open?'<span class="b amber">Unpaid</span>':fd(b.paid))+'</td>'
          +'<td style="font-weight:800;color:'+(b.v?'#b91c1c':'#6b7280')+'">'+(r.policy==='none'?'—':(b.over>0?'+'+b.over+'d':b.over+'d'))+'</td><td>'+st+'</td><td class="r">'+money(b.net)+'</td><td class="r" style="color:'+(b.open?'#1d4ed8':'#9ca3af')+'">'+money(b.bal)+'</td></tr>'; });
      h+='</tbody></table>'; if(!r.bills.length) h='<div class="none">No credit bills for this customer.</div>';
    } else {
      if(!r.cheques.length) h='<div class="none">No returned or sent-back cheques.</div>';
      else { h='<table><thead><tr><th>#</th><th class="l">Cheque no</th><th>Cheque date</th><th>Bank</th><th>Status</th><th>Settled</th><th>Amount (Rs.)</th></tr></thead><tbody>';
        r.cheques.forEach(function(c,k){ h+='<tr class="'+(c.settled?'':'v')+'"><td>'+(k+1)+'</td><td class="l">'+esc(c.no)+'</td><td>'+fd(c.date)+'</td><td>'+esc(c.bank)+'</td><td><span class="b amber">'+esc(String(c.status).replace('_',' '))+'</span></td><td>'+(c.settled?'<span class="b green">Settled</span>':'<span class="b red">Open</span>')+'</td><td class="r">'+money(c.amt)+'</td></tr>'; });
        h+='</tbody></table>'; }
    }
    $('hBody').innerHTML=h;
  }
  [].forEach.call(document.querySelectorAll('.cam .tabs2 button'),function(b){ b.onclick=function(){ HT=b.dataset.t; showH(CUR); }; });
  function step(d){ var l=foundList(), p=l.indexOf(CUR)+d; if(p>=0&&p<l.length) showH(l[p]); }
  $('hPrev').onclick=function(){ step(-1); }; $('hNext').onclick=function(){ step(1); };
  function closeH(){ $('cam').classList.remove('open'); document.body.style.overflow=''; }
  $('hClose').onclick=closeH; $('cam').addEventListener('mousedown',function(e){ if(e.target===$('cam')) closeH(); });
  document.addEventListener('keydown',function(e){ if(!$('cam').classList.contains('open')) return; if(e.key==='Escape') closeH(); else if(e.key==='ArrowLeft'){ e.preventDefault(); step(-1); } else if(e.key==='ArrowRight'){ e.preventDefault(); step(1); } });

  /* ── 4. save / open / delete ── */
  function loadSaved(){
    fetch('customer_analysis_report.php?ajax=list',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(res){
      var l=(res&&res.reports)||[]; if(!l.length){ $('caSaved').innerHTML='<div class="muted" style="color:#6b7280">No saved reports yet. Analyze a file and press “Save report”.</div>'; return; }
      $('caSaved').innerHTML=l.map(function(s){ return '<div class="sv"><b>'+esc(s.report_name)+'</b><small>'+s.total_rows+' outlets · '+esc(s.created_by||'')+' · '+fd(String(s.created_at).slice(0,10))+(s.file_name?'<br>'+esc(s.file_name):'')+'</small>'
        +'<div class="a"><button class="btn teal" data-open="'+s.id+'"><i class="fa-solid fa-folder-open"></i> Open</button><button class="btn red" data-del="'+s.id+'" data-nm="'+esc(s.report_name)+'"><i class="fa-solid fa-trash"></i> Delete</button></div></div>'; }).join('');
    }).catch(function(){ $('caSaved').innerHTML='<div class="muted">Could not load saved reports.</div>'; });
  }
  $('caSaved').addEventListener('click',function(e){
    var o=e.target.closest('[data-open]'), d=e.target.closest('[data-del]');
    if(o){ fetch('customer_analysis_report.php?ajax=load&id='+o.dataset.open,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(res){
        if(!res.success){ alert(res.message||'Could not open.'); return; } ROWS=res.rows; TAB='all'; LOADED=res.name; FILE=res.file||''; $('caName').value=res.name;
        $('caTitle').textContent='Saved report: '+res.name+' · analysed '+fd(res.as_at)+' · by '+(res.by||''); show(); window.scrollTo({top:0,behavior:'smooth'}); }); }
    if(d){ if(!confirm('Delete the saved report "'+d.dataset.nm+'"? This cannot be undone.')) return;
      post('delete_report',{id:d.dataset.del}).then(function(res){ if(res.success) loadSaved(); else alert(res.message||'Could not delete.'); }); }
  });
  $('caSave').addEventListener('click',function(){
    var n=$('caName').value.trim(); if(!n||!ROWS.length){ alert('Enter a report name first.'); return; }
    var b=this; b.disabled=true;
    post('save_report',{name:n,file:FILE,rows:JSON.stringify(ROWS)}).then(function(res){ b.disabled=false; if(res.success){ $('caStatus').textContent='Report saved.'; loadSaved(); } else alert(res.message||'Could not save.'); })
      .catch(function(){ b.disabled=false; alert('Connection problem — try again.'); });
  });

  /* ── 5. Excel export of what is analysed ── */
  $('caXls').addEventListener('click',function(){
    if(!ROWS.length) return;
    var A=[['NEW SR','Outlet code','Outlet name (file)','System name','In system','Blacklisted','Blacklist reason','Pay mode','Credit days','Cheques will delay','Total outstanding','Unpaid bills','Returned cheques','Returned amount','Returned still open','Policy','Policy violated (times)','Longest over policy (days)','Overdue now (bills)']];
    var B=[['Outlet code','Customer','Invoice','Delivery date','Policy due date','Paid on','Days over policy','Violated','Net','Balance']];
    ROWS.forEach(function(r){ if(!r.found){ A.push([r.sr,r.code,r.file_name,'','No']); return; }
      A.push([r.sr,r.code,r.file_name,r.name,'Yes',r.bl?'YES':'',r.bl_reason||'',r.pm,r.days,r.cwd?'YES':'',r.outstanding,r.out_n,r.ret_n,r.ret_amt,r.ret_open,r.policy==='cheque'?'Cheque policy':(r.policy==='credit'?'Credit policy':'n/a'),r.policy==='none'?'':r.viol,r.max_over||'',r.cur_over||'']);
      r.bills.forEach(function(b){ if(b.v) B.push([r.code,r.name,b.inv,b.del,b.due,b.paid||'UNPAID',b.over,'YES',b.net,b.bal]); }); });
    var wb=XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb,XLSX.utils.aoa_to_sheet(A),'Customer analysis'); XLSX.utils.book_append_sheet(wb,XLSX.utils.aoa_to_sheet(B),'Violated bills');
    XLSX.writeFile(wb,'customer_analysis_'+new Date().toISOString().slice(0,10)+'.xlsx');
  });

  document.body.appendChild($('cam'));      // out of the page wrapper: always above the site header & sidebar
  loadSaved();
})();
</script>
<?php include 'footer.php'; ?>
