<?php
/**
 * fc_verify.php — FC Verify (Cheque verify check / uploads)
 * ─────────────────────────────────────────────────────────────────────
 *  • Pick a RECEIVED DATE range → the cheques are loaded ONCE (all of them, up to 3000).
 *    Received date = COALESCE(cheques.received_date, invoice_payments.payment_date), same as the Cheque Register.
 *  • Shows verified / not verified cheques and whether their scans (front / back) are uploaded.
 *  • Click any cheque → LARGE modal with the cheque images, details and a scrollable list of every cheque in the
 *    range on the left. Previous / Next (or ← → keys, or click another cheque in the list) moves to the next one
 *    without reloading.
 *  • Mark Verified / Not Verified inside the modal (needs the Edit right; saved immediately + written to cheque_logs).
 *  • Export Excel of the loaded range (needs only View).
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include_once 'config.php';
ob_end_clean();
include_once 'auth.php';
if (session_status() === PHP_SESSION_NONE) @session_start();

function fcv_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fcv_valid_date($d) { if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$d, $m)) return false; return checkdate((int)$m[2], (int)$m[3], (int)$m[1]); }
function fcv_user() {
    return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ?? $_SESSION['full_name'] ?? $_SESSION['email']
        ?? (isset($_SESSION['user_id']) ? 'User #' . $_SESSION['user_id'] : 'system');
}
function fcv_can_edit() { return function_exists('rbac_can') ? rbac_can('edit', 'fc_verify') : true; }

foreach (['cheque_front_image' => 'VARCHAR(500) NULL', 'cheque_back_image' => 'VARCHAR(500) NULL', 'verified' => 'TINYINT(1) NOT NULL DEFAULT 0'] as $col => $def) {
    $c = mysqli_query($conn, "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cheques' AND COLUMN_NAME='$col' LIMIT 1");
    if ($c && mysqli_num_rows($c) === 0) mysqli_query($conn, "ALTER TABLE cheques ADD COLUMN `$col` $def");
}
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
    id INT AUTO_INCREMENT PRIMARY KEY, cheque_id INT NOT NULL, action VARCHAR(100) NOT NULL,
    old_value TEXT, new_value TEXT, note TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(100) DEFAULT 'system',
    INDEX (cheque_id), INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── shared loader: same filters for the screen and the Excel export ── */
function fcv_load($conn, $from, $to, $verify, $scan, $status, $q) {
    $recv = "COALESCE(ch.received_date, ip.payment_date)";
    $where = ["$recv BETWEEN '" . mysqli_real_escape_string($conn, $from) . "' AND '" . mysqli_real_escape_string($conn, $to) . "'"];
    $hasF = "(ch.cheque_front_image IS NOT NULL AND ch.cheque_front_image <> '')";
    $hasB = "(ch.cheque_back_image IS NOT NULL AND ch.cheque_back_image <> '')";
    if ($verify === 'yes') $where[] = "COALESCE(ch.verified,0) = 1";
    if ($verify === 'no')  $where[] = "COALESCE(ch.verified,0) = 0";
    if ($scan === 'yes')   $where[] = "($hasF OR $hasB)";
    if ($scan === 'no')    $where[] = "(NOT $hasF AND NOT $hasB)";
    if ($status !== '')    $where[] = "ch.status = '" . mysqli_real_escape_string($conn, $status) . "'";
    if ($q !== '') {
        $e = mysqli_real_escape_string($conn, $q);
        $where[] = "(ch.cheque_no LIKE '%$e%' OR ch.t_code LIKE '%$e%' OR ch.bank_code LIKE '%$e%' OR ch.bank_name LIKE '%$e%'
                     OR fsd.customer_name LIKE '%$e%' OR c.shop_name LIKE '%$e%' OR fs.sr_code LIKE '%$e%')";
    }
    $sql = "SELECT ch.id, ch.cheque_no, ch.cheque_date, COALESCE(ch.total_amount, ch.amount, 0) AS amount,
                   ch.bank_code, ch.bank_name, ch.branch_code, ch.branch_name, ch.status, ch.t_code, ch.cheque_mode,
                   $recv AS received_date, fs.sr_code, fs.delivery_date,
                   COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, NULLIF(ch.t_code,''),
                            CASE WHEN ch.sampath IS NOT NULL AND ch.sampath <> '' THEN CONCAT('Sampath Payment #', COALESCE(ch.sampath_payment_id,0)) END) AS customer_name,
                   (ch.sampath IS NOT NULL AND ch.sampath <> '') AS is_sampath, ch.sampath_description,
                   COALESCE(ch.verified,0) AS verified, ch.cheque_front_image, ch.cheque_back_image
            FROM cheques ch
            LEFT JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id
            LEFT JOIN field_summary fs ON fs.id = ip.field_summary_id
            LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
            LEFT JOIN customers c ON c.t_code = ch.t_code
            WHERE " . implode(' AND ', $where) . "
            ORDER BY $recv ASC, ch.cheque_date ASC, ch.cheque_no ASC
            LIMIT 3000";
    $rows = [];
    $res = mysqli_query($conn, $sql);
    if ($res) while ($r = mysqli_fetch_assoc($res)) {
        $r['id'] = (int)$r['id']; $r['amount'] = (float)$r['amount']; $r['verified'] = (int)$r['verified']; $r['is_sampath'] = (int)$r['is_sampath'];
        $r['front'] = trim((string)$r['cheque_front_image']); $r['back'] = trim((string)$r['cheque_back_image']);
        unset($r['cheque_front_image'], $r['cheque_back_image']);
        $rows[] = $r;
    }
    return $rows;
}
function fcv_filters() {
    $today = date('Y-m-d');
    $from = fcv_valid_date($_GET['date_from'] ?? '') ? $_GET['date_from'] : date('Y-m-01');
    $to   = fcv_valid_date($_GET['date_to'] ?? '')   ? $_GET['date_to']   : $today;
    if ($from > $to) { $t = $from; $from = $to; $to = $t; }
    $verify = in_array($_GET['verify'] ?? 'yes', ['yes', 'no', 'all'], true) ? ($_GET['verify'] ?? 'yes') : 'yes';
    $scan   = in_array($_GET['scan'] ?? 'all', ['all', 'yes', 'no'], true) ? ($_GET['scan'] ?? 'all') : 'all';
    $status = is_string($_GET['status'] ?? '') ? trim($_GET['status'] ?? '') : '';
    $q      = substr(is_string($_GET['q'] ?? '') ? trim($_GET['q'] ?? '') : '', 0, 100);
    return [$from, $to, $verify, $scan, $status, $q];
}

/* ═══════════════ AJAX ═══════════════ */
if (($_GET['ajax'] ?? '') === 'load') {                      // loads the whole range ONCE
    header('Content-Type: application/json');
    [$from, $to, $verify, $scan, $status, $q] = fcv_filters();
    $rows = fcv_load($conn, $from, $to, $verify, $scan, $status, $q);
    echo json_encode(['success' => true, 'rows' => $rows, 'can_edit' => fcv_can_edit(), 'capped' => count($rows) >= 3000]);
    exit;
}
if (($_POST['action'] ?? '') === 'update_verify') {          // 'update_*' → needs the Edit right
    header('Content-Type: application/json');
    if (!fcv_can_edit()) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Permission blocked: you cannot edit records here.']); exit; }
    $cid = intval($_POST['cheque_id'] ?? 0);
    $val = ($_POST['value'] ?? '') === '1' ? 1 : 0;
    if (!$cid) { echo json_encode(['success' => false, 'error' => 'Invalid cheque.']); exit; }
    $o = mysqli_query($conn, "SELECT COALESCE(verified,0) FROM cheques WHERE id = $cid LIMIT 1");
    $old = $o ? (int)(mysqli_fetch_row($o)[0] ?? 0) : 0;
    if (!mysqli_query($conn, "UPDATE cheques SET verified = $val WHERE id = $cid")) { echo json_encode(['success' => false, 'error' => mysqli_error($conn)]); exit; }
    $cu = mysqli_real_escape_string($conn, fcv_user());
    mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id, action, old_value, new_value, note, created_by)
                         VALUES ($cid, 'verified', '$old', '$val', 'Marked " . ($val ? 'Verified' : 'Not Verified') . " (FC Verify)', '$cu')");
    echo json_encode(['success' => true, 'value' => $val]);
    exit;
}

/* ═══════════════ EXCEL EXPORT ═══════════════ */
if (isset($_GET['export'])) {
    require_once __DIR__ . '/rbac_xlsx.php';
    if (!class_exists('ZipArchive')) { http_response_code(500); exit('The PHP zip extension is required for Excel export.'); }
    [$from, $to, $verify, $scan, $status, $q] = fcv_filters();
    $rows = fcv_load($conn, $from, $to, $verify, $scan, $status, $q);
    $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '134E4A', 'h' => 'center', 'border' => 1, 'wrap' => 1];
    $X = new MiniXlsx();
    $s = $X->sheet('FC Verify');
    $s->set(1, 1, 'FC Verify — cheques received ' . date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to)), ['b' => 1, 'sz' => 16, 'color' => 'FFFFFF', 'bg' => '0F766E', 'indent' => 1]);
    for ($c = 2; $c <= 13; $c++) $s->set(1, $c, '', ['bg' => '0F766E']);
    $s->merge(1, 1, 1, 13); $s->heights[1] = 30;
    $nv = count(array_filter($rows, fn($r) => $r['verified'])); $nu = count(array_filter($rows, fn($r) => $r['front'] !== '' || $r['back'] !== ''));
    $s->set(2, 1, 'Cheques: ' . count($rows) . '   ·   Verified: ' . $nv . '   ·   Not verified: ' . (count($rows) - $nv) . '   ·   Scan uploaded: ' . $nu . '   ·   Not uploaded: ' . (count($rows) - $nu)
        . '   ·   Total Rs. ' . number_format(array_sum(array_column($rows, 'amount')), 2) . '   ·   Generated ' . date('d M Y H:i') . ' by ' . fcv_user(), ['i' => 1, 'color' => '6B7280']);
    $s->merge(2, 1, 2, 13);
    foreach (['#', 'Received date', 'Cheque no', 'Cheque date', 'Customer', 'T code', 'SR', 'Bank', 'Amount', 'Status', 'Verified', 'Front scan', 'Back scan'] as $i => $h) $s->set(4, $i + 1, $h, $H);
    $r = 5; $n = 1;
    foreach ($rows as $x) {
        $bg = ($r % 2) ? ['bg' => 'F3F4F6'] : [];
        $vals = [$n++, $x['received_date'] ? date('d M Y', strtotime($x['received_date'])) : '', $x['cheque_no'], ($x['cheque_date'] && $x['cheque_date'] !== '0000-00-00') ? date('d M Y', strtotime($x['cheque_date'])) : '',
                 (string)$x['customer_name'], (string)$x['t_code'], (string)$x['sr_code'], trim($x['bank_code'] . ' ' . $x['bank_name']), (float)$x['amount'], ucwords(str_replace('_', ' ', (string)$x['status']))];
        foreach ($vals as $i => $v) $s->set($r, $i + 1, is_float($v) ? $v : (string)$v, ['border' => 1] + $bg + ($i === 8 ? ['h' => 'right'] : []));
        $s->set($r, 11, $x['verified'] ? 'Verified' : 'Not verified', ['border' => 1, 'h' => 'center', 'b' => 1, 'color' => $x['verified'] ? '15803D' : 'B91C1C'] + $bg);
        $s->set($r, 12, $x['front'] !== '' ? 'Uploaded' : 'Not uploaded', ['border' => 1, 'h' => 'center', 'color' => $x['front'] !== '' ? '15803D' : 'B91C1C'] + $bg);
        $s->set($r, 13, $x['back'] !== '' ? 'Uploaded' : 'Not uploaded', ['border' => 1, 'h' => 'center', 'color' => $x['back'] !== '' ? '15803D' : 'B91C1C'] + $bg);
        $r++;
    }
    foreach ([5, 14, 14, 14, 34, 10, 10, 26, 16, 14, 14, 14, 14] as $i => $w) $s->widths[$i + 1] = $w;
    $s->freeze = [4, 0]; $s->filter = 'A4:M' . max(5, $r - 1);
    $tmp = tempnam(sys_get_temp_dir(), 'fv'); $X->save($tmp);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="fc_verify_' . $from . '_to_' . $to . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp); @unlink($tmp); exit;
}

/* ═══════════════ PAGE ═══════════════ */
[$f_from, $f_to, $f_verify, $f_scan, $f_status, $f_q] = fcv_filters();
$statuses = [];
$sr = mysqli_query($conn, "SELECT DISTINCT status FROM cheques WHERE status IS NOT NULL AND status <> '' ORDER BY status");
while ($sr && ($x = mysqli_fetch_row($sr))) $statuses[] = $x[0];
$can_edit = fcv_can_edit();
$today = date('Y-m-d');
$quick = ['Today' => [$today, $today], 'Last 7 days' => [date('Y-m-d', strtotime('-6 days')), $today], 'This month' => [date('Y-m-01'), $today],
          'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))]];
include 'header.php';
?>
<style>
.fcv, .fcv *, .fcv *::before, .fcv *::after { box-sizing: border-box; }
.fcv { --ink:#111827; --muted:#6b7280; --line:#e5e7eb; --green:#157f3d; --red:#c81e1e; --teal:#0f766e;
       font-family:'Inter',system-ui,sans-serif; font-size:13px; line-height:1.45; color:var(--ink); background:#f4f5f8; padding:22px 26px 48px; min-height:100%; }
.fcv h1 { margin:0; font-size:21px; font-weight:700; } .fcv .sub { margin:4px 0 16px; color:var(--muted); max-width:95ch; } .fcv .muted { color:var(--muted); font-size:12px; }
.fcv .card { background:#fff; border:1px solid var(--line); border-radius:10px; padding:14px 16px; margin-bottom:14px; }
.fcv form.flt { display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px; }
.fcv .fld { display:flex; flex-direction:column; gap:4px; } .fcv .fld label { font-size:12px; font-weight:600; color:var(--muted); }
.fcv input[type=date], .fcv input[type=search], .fcv select { height:36px; padding:0 10px; border:1px solid #d1d5db; border-radius:7px; font:inherit; color:var(--ink); background:#fff; }
.fcv input[type=search] { width:220px; max-width:100%; } .fcv select { min-width:130px; }
.fcv .btn { display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 16px; border:1px solid transparent; border-radius:7px; background:#111827; color:#fff; font:inherit; font-weight:600; cursor:pointer; text-decoration:none; }
.fcv .btn.light { background:#fff; color:var(--ink); border-color:#d1d5db; } .fcv .btn.xl { background:#fff; color:#15803d; border-color:#86efac; } .fcv .btn.teal { background:var(--teal); }
.fcv .chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:12px; padding-top:12px; border-top:1px solid var(--line); }
.fcv .chips a { padding:4px 11px; border:1px solid var(--line); border-radius:999px; font-size:12px; text-decoration:none; color:var(--muted); background:#fff; cursor:pointer; }
.fcv .chips a.on { background:#111827; border-color:#111827; color:#fff; }
.fcv .kpis { display:grid; grid-template-columns:repeat(6,1fr); gap:10px; margin-bottom:14px; }
.fcv .kpi { background:#fff; border:1px solid var(--line); border-radius:10px; padding:11px 13px; } .fcv .kpi .l { font-size:11px; font-weight:600; color:var(--muted); }
.fcv .kpi .v { font-size:20px; font-weight:700; margin-top:2px; font-variant-numeric:tabular-nums; }
.fcv .kpi.g .v { color:var(--green); } .fcv .kpi.r .v { color:var(--red); }
.fcv .wrap { overflow-x:auto; } .fcv table.t { width:100%; min-width:1000px; border-collapse:collapse; }
.fcv .t th { padding:9px 10px; text-align:left; font-size:11.5px; font-weight:700; color:var(--muted); background:#f9fafb; border-bottom:1px solid var(--line); white-space:nowrap; position:sticky; top:0; }
.fcv .t td { padding:9px 10px; border-bottom:1px solid #f0f1f4; vertical-align:middle; } .fcv .t td.num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
.fcv .t tbody tr { cursor:pointer; } .fcv .t tbody tr:hover td { background:#f0fdfa; }
.fcv .strong { font-weight:600; }
.fcv .badge { display:inline-flex; align-items:center; padding:2px 9px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; border:1px solid; }
.fcv .badge.g { background:#f0fdf4; color:#16a34a; border-color:#bbf7d0; } .fcv .badge.r { background:#fef2f2; color:#dc2626; border-color:#fecaca; }
.fcv .badge.a { background:#fffbeb; color:#b45309; border-color:#fde68a; } .fcv .badge.s { background:#f3f4f6; color:#374151; border-color:#e5e7eb; text-transform:capitalize; }
.fcv .badge.y { background:#fef9c3; color:#854d0e; border-color:#fde047; }
.fcv .empty { padding:34px 16px; text-align:center; color:var(--muted); }
/* ── large modal (moved to <body> by the script so it always sits above the site header / sidebar) ── */
.fcm { display:none; position:fixed; inset:0; z-index:2147483000; background:rgba(15,23,42,.82); backdrop-filter:blur(3px); padding:1.2vh 1vw; font-family:'Inter',system-ui,sans-serif; font-size:13px; color:#111827; }
.fcm.open { display:block; }
.fcm *, .fcm *::before, .fcm *::after { box-sizing:border-box; }
.fcm .box { background:#fff; border-radius:16px; width:100%; height:100%; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 24px 80px rgba(0,0,0,.55); }
.fcm .top { display:flex; align-items:center; gap:12px; padding:12px 18px; background:linear-gradient(90deg,#0f766e,#14b8a6); color:#fff; flex-shrink:0; }
.fcm .top .ttl { font-size:18px; font-weight:800; letter-spacing:.2px; } .fcm .top .cnt { background:rgba(255,255,255,.22); border-radius:999px; padding:3px 14px; font-weight:800; font-size:13px; }
.fcm .top .sp { flex:1; }
.fcm .nb { height:36px; padding:0 16px; border:1px solid rgba(255,255,255,.5); border-radius:9px; background:rgba(255,255,255,.15); color:#fff; font:inherit; font-weight:700; cursor:pointer; }
.fcm .nb:hover:not(:disabled) { background:rgba(255,255,255,.3); } .fcm .nb:disabled { opacity:.4; cursor:default; }
.fcm .x { width:36px; height:36px; border-radius:50%; border:none; background:#dc2626; color:#fff; font-size:20px; line-height:1; cursor:pointer; }
.fcm .x:hover { background:#b91c1c; }
.fcm .body { flex:1; min-height:0; display:grid; grid-template-columns:230px minmax(0,1fr) 290px; }
.fcm .lst { border-right:1px solid #e5e7eb; overflow-y:auto; background:#f8fafc; }
.fcm .li { padding:10px 12px; border-bottom:1px solid #eef0f3; cursor:pointer; display:flex; gap:9px; align-items:center; }
.fcm .li:hover { background:#f0fdfa; } .fcm .li.on { background:#ccfbf1; box-shadow:inset 4px 0 0 #0f766e; }
.fcm .li .n { font-weight:700; font-size:12.5px; } .fcm .li .s { font-size:11px; color:#6b7280; } .fcm .li .d { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.fcm .imgs { min-height:0; padding:12px; background:#0f172a; display:grid; grid-template-rows:minmax(0,1fr) minmax(0,1fr); gap:12px; }
.fcm .pane { background:#111827; border:1px solid #334155; border-radius:12px; display:flex; flex-direction:column; min-height:0; overflow:hidden; }
.fcm .pane .ph { display:flex; align-items:center; gap:6px; padding:6px 10px; color:#e2e8f0; background:#1e293b; border-bottom:1px solid #334155; flex-shrink:0; }
.fcm .pane .ph .lb { font-size:11px; font-weight:800; letter-spacing:.1em; text-transform:uppercase; margin-right:auto; }
.fcm .pane .ph .st { font-size:10.5px; font-weight:700; padding:1px 8px; border-radius:99px; } .fcm .pane .ph .st.ok { background:#14532d; color:#bbf7d0; } .fcm .pane .ph .st.no { background:#7f1d1d; color:#fecaca; }
.fcm .pane .ph button { border:1px solid #475569; background:#334155; color:#f1f5f9; border-radius:6px; font-size:12px; font-weight:700; min-width:30px; height:26px; padding:0 8px; cursor:pointer; }
.fcm .pane .ph button:hover { background:#475569; }
.fcm .pane .pv { flex:1; min-height:0; overflow:auto; display:flex; align-items:center; justify-content:center; padding:6px; }
.fcm .pane .pv img { width:100%; height:100%; object-fit:contain; transition:transform .15s; cursor:zoom-in; display:block; border-radius:4px; background:#fff; }
.fcm .pane .pv img.zm { width:auto; height:auto; max-width:none; max-height:none; object-fit:initial; }
.fcm .pane .none { color:#94a3b8; text-align:center; font-weight:600; } .fcm .pane .none i { font-size:42px; display:block; margin-bottom:8px; color:#475569; }
.fcm .det { border-left:1px solid #e5e7eb; overflow-y:auto; padding:16px 18px; background:#fff; }
.fcm .det .amt { font-size:28px; font-weight:800; font-variant-numeric:tabular-nums; margin-bottom:6px; color:#0f172a; }
.fcm .det dl { margin:10px 0 0; } .fcm .det dt { font-size:10.5px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; margin-top:11px; }
.fcm .det dd { margin:1px 0 0; font-weight:600; word-break:break-word; }
.fcm .badge { display:inline-flex; align-items:center; padding:2px 10px; border-radius:999px; font-size:11px; font-weight:700; border:1px solid; }
.fcm .badge.g { background:#f0fdf4; color:#16a34a; border-color:#bbf7d0; } .fcm .badge.r { background:#fef2f2; color:#dc2626; border-color:#fecaca; } .fcm .badge.y { background:#fef9c3; color:#854d0e; border-color:#fde047; }
.fcm .foot { display:flex; align-items:center; gap:10px; padding:11px 18px; border-top:1px solid #e5e7eb; background:#f8fafc; flex-shrink:0; }
.fcm .vb { height:40px; padding:0 22px; border-radius:10px; font:inherit; font-weight:800; cursor:pointer; border:1px solid; }
.fcm .vb.v { background:#16a34a; border-color:#16a34a; color:#fff; } .fcm .vb.v:hover:not(:disabled) { background:#15803d; }
.fcm .vb.nv { background:#fff; border-color:#fecaca; color:#b91c1c; } .fcm .vb.nv:hover:not(:disabled) { background:#fef2f2; }
.fcm .vb:disabled { opacity:.45; cursor:not-allowed; } .fcm .msg { font-size:12.5px; font-weight:700; } .fcm .msg.ok { color:#157f3d; } .fcm .msg.err { color:#c81e1e; }
.fcm .hint { margin-left:auto; font-size:11.5px; color:#6b7280; }
.fcm .zoomov { display:none; position:fixed; inset:0; z-index:2147483100; background:rgba(0,0,0,.94); align-items:center; justify-content:center; cursor:zoom-out; }
.fcm .zoomov.open { display:flex; } .fcm .zoomov img { max-width:96vw; max-height:96vh; border-radius:6px; background:#fff; }
@media (max-width:1100px) { .fcm .body { grid-template-columns:1fr; grid-template-rows:120px minmax(0,1fr) auto; } .fcm .lst { border-right:none; border-bottom:1px solid #e5e7eb; display:flex; overflow-x:auto; overflow-y:hidden; } .fcm .li { min-width:190px; } .fcm .det { border-left:none; border-top:1px solid #e5e7eb; max-height:30vh; } .fcm .hint { display:none; } .fcv .kpis { grid-template-columns:repeat(3,1fr); } }
@media (max-width:560px) { .fcv { padding:16px 12px 40px; } .fcv .kpis { grid-template-columns:1fr 1fr; } }
</style>

<div class="fcv" id="fcvApp">
    <h1>FC Verify <span class="muted" style="font-weight:500">— cheque verify &amp; upload check</span></h1>
    <p class="sub">Choose the <b>received date range</b>. The cheques in that range load <b>once</b>; click any cheque to open the large viewer and move to the next / previous cheque (←  → keys) to check its front and back scans and mark it Verified.</p>

    <section class="card" aria-label="Filters">
        <form class="flt" id="fcvForm" method="get" action="fc_verify.php">
            <div class="fld"><label for="fcFrom">Received date from</label><input type="date" id="fcFrom" name="date_from" value="<?= fcv_h($f_from) ?>" required></div>
            <div class="fld"><label for="fcTo">Received date to</label><input type="date" id="fcTo" name="date_to" value="<?= fcv_h($f_to) ?>" required></div>
            <div class="fld"><label for="fcVer">Show</label><select id="fcVer" name="verify">
                <?php foreach (['yes' => 'Verified cheques', 'no' => 'Not verified', 'all' => 'All cheques'] as $k => $l): ?><option value="<?= $k ?>"<?= $f_verify === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="fld"><label for="fcScan">Scan</label><select id="fcScan" name="scan">
                <?php foreach (['all' => 'All', 'yes' => 'Uploaded', 'no' => 'Not uploaded'] as $k => $l): ?><option value="<?= $k ?>"<?= $f_scan === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="fld"><label for="fcSt">Cheque status</label><select id="fcSt" name="status"><option value="">All</option>
                <?php foreach ($statuses as $s): ?><option value="<?= fcv_h($s) ?>"<?= $f_status === $s ? ' selected' : '' ?>><?= fcv_h(ucwords(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?></select></div>
            <div class="fld"><label for="fcQ">Search</label><input type="search" id="fcQ" name="q" value="<?= fcv_h($f_q) ?>" placeholder="Cheque no, customer, T code, bank" maxlength="100"></div>
            <button type="submit" class="btn teal"><i class="fa-solid fa-magnifying-glass"></i> Load cheques</button>
            <a class="btn xl" id="fcvExport" href="#"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
        </form>
        <div class="chips" id="fcvChips">
            <?php foreach ($quick as $label => $range): ?><a data-from="<?= $range[0] ?>" data-to="<?= $range[1] ?>"><?= fcv_h($label) ?></a><?php endforeach; ?>
        </div>
    </section>

    <section class="kpis">
        <div class="kpi"><div class="l">Cheques loaded</div><div class="v" id="kTot">0</div></div>
        <div class="kpi g"><div class="l">Verified</div><div class="v" id="kVer">0</div></div>
        <div class="kpi r"><div class="l">Not verified</div><div class="v" id="kNver">0</div></div>
        <div class="kpi g"><div class="l">Scan uploaded</div><div class="v" id="kUp">0</div></div>
        <div class="kpi r"><div class="l">Not uploaded</div><div class="v" id="kNo">0</div></div>
        <div class="kpi"><div class="l">Total amount</div><div class="v" id="kAmt" style="font-size:16px">Rs. 0.00</div></div>
    </section>

    <div class="card wrap" id="fcvListCard">
        <div class="empty" id="fcvEmpty">Loading cheques…</div>
        <table class="t" id="fcvTable" style="display:none">
            <thead><tr><th>#</th><th>Received</th><th>Cheque No</th><th>Cheque Date</th><th>Customer</th><th>Bank</th><th class="num">Amount</th><th>Status</th><th>Front</th><th>Back</th><th>Verified</th></tr></thead>
            <tbody id="fcvBody"></tbody>
        </table>
    </div>
    <p class="muted" id="fcvNote"></p>

    <!-- LARGE viewer -->
    <div class="fcm" id="fcm" role="dialog" aria-modal="true" aria-label="Cheque viewer">
        <div class="box">
            <div class="top">
                <span class="ttl" id="mTitle">Cheque</span><span class="cnt" id="mCnt">1 / 1</span><span class="sp"></span>
                <button class="nb" id="mPrev" type="button">◀ Previous</button>
                <button class="nb" id="mNext" type="button">Next ▶</button>
                <button class="x" id="mClose" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="body">
                <div class="lst" id="mList"></div>
                <div class="imgs">
                    <div class="pane"><div class="ph"><span class="lb">Front</span><span class="st no" id="stFront">Missing</span><button type="button" data-zoom="front" data-d="-1" title="Zoom out">−</button><button type="button" data-zoom="front" data-d="1" title="Zoom in">+</button><button type="button" data-zoom="front" data-d="0" title="Fit">Fit</button><button type="button" data-rot="front" title="Rotate">⟳</button></div><div class="pv" id="pvFront"></div></div>
                    <div class="pane"><div class="ph"><span class="lb">Back</span><span class="st no" id="stBack">Missing</span><button type="button" data-zoom="back" data-d="-1" title="Zoom out">−</button><button type="button" data-zoom="back" data-d="1" title="Zoom in">+</button><button type="button" data-zoom="back" data-d="0" title="Fit">Fit</button><button type="button" data-rot="back" title="Rotate">⟳</button></div><div class="pv" id="pvBack"></div></div>
                </div>
                <div class="det" id="mDet"></div>
            </div>
            <div class="foot">
                <button class="vb v" id="mVer" type="button">✔ Verified</button>
                <button class="vb nv" id="mNver" type="button">✖ Not verified</button>
                <span class="msg" id="mMsg"></span>
                <span class="hint">← → previous / next · click an image to zoom · Esc closes</span>
            </div>
        </div>
        <div class="zoomov" id="zoomOv"><img id="zoomImg" alt=""></div>
    </div>
</div>

<script>
(function(){
  var CAN_EDIT = <?= $can_edit ? 'true' : 'false' ?>;
  var rows = [], cur = -1, rot = {front:0, back:0}, zm = {front:1, back:1};
  var $ = function(id){ return document.getElementById(id); };
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function money(v){ return Number(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function fdate(d){ if(!d||d==='0000-00-00') return '—'; var t=new Date(d.replace(/-/g,'/')); return isNaN(t)?d:t.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}); }
  function qs(){ return new URLSearchParams(new FormData($('fcvForm'))).toString(); }

  /* ── load the date range ONCE ── */
  function load(){
    $('fcvEmpty').style.display='block'; $('fcvEmpty').textContent='Loading cheques…'; $('fcvTable').style.display='none';
    fetch('fc_verify.php?ajax=load&'+qs(),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(res){
      if(!res||!res.success){ $('fcvEmpty').textContent='Could not load cheques.'; return; }
      rows=res.rows||[]; renderList(); kpis();
      $('fcvNote').textContent = rows.length + ' cheque' + (rows.length===1?'':'s') + ' loaded' + (res.capped?' — showing the first 3,000, narrow the dates':'');
    }).catch(function(){ $('fcvEmpty').textContent='Connection problem — try again.'; });
    $('fcvExport').href='fc_verify.php?export=1&'+qs();
  }
  function kpis(){
    var v=0,u=0,a=0; rows.forEach(function(r){ if(r.verified) v++; if(r.front||r.back) u++; a+=r.amount; });
    $('kTot').textContent=rows.length; $('kVer').textContent=v; $('kNver').textContent=rows.length-v; $('kUp').textContent=u; $('kNo').textContent=rows.length-u; $('kAmt').textContent='Rs. '+money(a);
  }
  function scanBadge(p){ return p?'<span class="badge g">Uploaded</span>':'<span class="badge r">Missing</span>'; }
  function renderList(){
    var b=$('fcvBody'); b.innerHTML='';
    if(!rows.length){ $('fcvTable').style.display='none'; $('fcvEmpty').style.display='block'; $('fcvEmpty').textContent='No cheques found for this received date range and filter.'; return; }
    $('fcvEmpty').style.display='none'; $('fcvTable').style.display='table';
    var h='';
    rows.forEach(function(r,i){
      h+='<tr data-i="'+i+'"><td>'+(i+1)+'</td><td>'+fdate(r.received_date)+'</td><td><span class="strong">'+esc(r.cheque_no)+'</span>'+(r.is_sampath?' <span class="badge y">Sampath</span>':'')+'</td>'
        +'<td>'+fdate(r.cheque_date)+'</td><td>'+esc(r.customer_name||'—')+(r.t_code?'<br><span class="muted">'+esc(r.t_code)+'</span>':'')+'</td>'
        +'<td>'+esc((r.bank_code||'')+' '+(r.bank_name||''))+'</td><td class="num">Rs. '+money(r.amount)+'</td><td><span class="badge s">'+esc(String(r.status||'').replace(/_/g,' '))+'</span></td>'
        +'<td data-c="f">'+scanBadge(r.front)+'</td><td data-c="b">'+scanBadge(r.back)+'</td><td data-c="v">'+(r.verified?'<span class="badge g">Verified</span>':'<span class="badge r">Not verified</span>')+'</td></tr>';
    });
    b.innerHTML=h;
  }
  $('fcvBody').addEventListener('click',function(e){ var tr=e.target.closest('tr'); if(tr) openAt(parseInt(tr.dataset.i,10)); });

  /* ── large viewer ── */
  function buildModalList(){
    var h=''; rows.forEach(function(r,i){
      h+='<div class="li" data-i="'+i+'"><span class="d" style="background:'+(r.verified?'#16a34a':'#dc2626')+'"></span><div><div class="n">'+esc(r.cheque_no)+' · Rs. '+money(r.amount)+'</div><div class="s">'+esc(r.customer_name||'—')+' · '+fdate(r.received_date)+'</div></div></div>';
    });
    $('mList').innerHTML=h;
  }
  $('mList').addEventListener('click',function(e){ var li=e.target.closest('.li'); if(li) show(parseInt(li.dataset.i,10)); });
  function pane(id, side, path){
    var el=$(id); rot[side]=0; zm[side]=1;
    var st=$(side==='front'?'stFront':'stBack'); st.textContent=path?'Uploaded':'Missing'; st.className='st '+(path?'ok':'no');
    el.innerHTML = path ? '<img src="'+esc(path)+'" alt="'+side+'" data-side="'+side+'">' : '<div class="none"><i class="fa-regular fa-image"></i>No '+side+' scan uploaded</div>';
  }
  function show(i){
    if(i<0||i>=rows.length) return;
    cur=i; var r=rows[i];
    $('mTitle').textContent='Cheque '+(r.cheque_no||'');
    $('mCnt').textContent=(i+1)+' / '+rows.length;
    $('mPrev').disabled=(i===0); $('mNext').disabled=(i===rows.length-1);
    pane('pvFront','front',r.front); pane('pvBack','back',r.back);
    $('mDet').innerHTML='<div class="amt">Rs. '+money(r.amount)+'</div>'
      +(r.verified?'<span class="badge g">Verified</span>':'<span class="badge r">Not verified</span>')+' '+(r.is_sampath?'<span class="badge y">Sampath</span>':'')
      +'<dl><dt>Cheque no</dt><dd>'+esc(r.cheque_no)+'</dd><dt>Cheque date</dt><dd>'+fdate(r.cheque_date)+'</dd><dt>Received date</dt><dd>'+fdate(r.received_date)+'</dd>'
      +'<dt>Customer</dt><dd>'+esc(r.customer_name||'—')+'</dd><dt>T code</dt><dd>'+esc(r.t_code||'—')+'</dd><dt>SR code</dt><dd>'+esc(r.sr_code||'—')+'</dd>'
      +'<dt>Bank</dt><dd>'+esc(((r.bank_code||'')+' '+(r.bank_name||'')).trim()||'—')+'</dd><dt>Branch</dt><dd>'+esc(((r.branch_code||'')+' '+(r.branch_name||'')).trim()||'—')+'</dd>'
      +'<dt>Status</dt><dd style="text-transform:capitalize">'+esc(String(r.status||'—').replace(/_/g,' '))+'</dd>'
      +(r.cheque_mode?'<dt>Mode</dt><dd>'+esc(r.cheque_mode)+'</dd>':'')+(r.sampath_description?'<dt>Sampath description</dt><dd>'+esc(r.sampath_description)+'</dd>':'')
      +'<dt>Scans</dt><dd>Front: '+(r.front?'Uploaded':'Missing')+' · Back: '+(r.back?'Uploaded':'Missing')+'</dd></dl>';
    var cb=$('mVer'), nb=$('mNver'); cb.disabled=nb.disabled=!CAN_EDIT; cb.title=nb.title=CAN_EDIT?'':'Permission blocked';
    $('mMsg').textContent=''; $('mMsg').className='msg';
    [].forEach.call($('mList').children,function(li){ li.classList.toggle('on', parseInt(li.dataset.i,10)===i); });
    var on=$('mList').querySelector('.li.on'); if(on) on.scrollIntoView({block:'nearest'});
  }
  function openAt(i){ buildModalList(); $('fcm').classList.add('open'); document.body.style.overflow='hidden'; show(i); }
  function closeM(){ $('fcm').classList.remove('open'); $('zoomOv').classList.remove('open'); document.body.style.overflow=''; }
  $('mClose').onclick=closeM;
  $('fcm').addEventListener('mousedown',function(e){ if(e.target===$('fcm')) closeM(); });
  $('mPrev').onclick=function(){ show(cur-1); }; $('mNext').onclick=function(){ show(cur+1); };
  document.addEventListener('keydown',function(e){
    if(!$('fcm').classList.contains('open')) return;
    if(e.key==='Escape'){ if($('zoomOv').classList.contains('open')) $('zoomOv').classList.remove('open'); else closeM(); }
    else if(e.key==='ArrowLeft'){ e.preventDefault(); show(cur-1); } else if(e.key==='ArrowRight'){ e.preventDefault(); show(cur+1); }
  });
  function paneImg(s){ return document.querySelector('#pv'+(s==='front'?'Front':'Back')+' img'); }
  [].forEach.call(document.querySelectorAll('[data-rot]'),function(b){ b.onclick=function(){ var s=b.dataset.rot; rot[s]=(rot[s]+90)%360; var im=paneImg(s); if(im) im.style.transform='rotate('+rot[s]+'deg)'+(zm[s]>1?' scale(1)':''); }; });
  [].forEach.call(document.querySelectorAll('[data-zoom]'),function(b){ b.onclick=function(){
    var s=b.dataset.zoom, d=parseInt(b.dataset.d,10), im=paneImg(s); if(!im) return;
    zm[s]=d===0?1:Math.max(1,Math.min(4,zm[s]+d*0.5));
    if(zm[s]===1){ im.classList.remove('zm'); im.style.width=''; im.style.height=''; }
    else { im.classList.add('zm'); im.style.width=(zm[s]*100)+'%'; im.style.height='auto'; }
  }; });
  $('fcm').addEventListener('click',function(e){ var im=e.target.closest('.pv img'); if(im){ $('zoomImg').src=im.src; $('zoomOv').classList.add('open'); } });
  $('zoomOv').onclick=function(){ $('zoomOv').classList.remove('open'); };

  /* ── mark verified / not verified (Edit right) ── */
  function setVerify(val){
    if(cur<0||!CAN_EDIT) return; var r=rows[cur], fd=new FormData();
    fd.append('action','update_verify'); fd.append('cheque_id',r.id); fd.append('value',val);
    $('mMsg').className='msg'; $('mMsg').textContent='Saving…';
    fetch('fc_verify.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(x){return x.json().catch(function(){return null;});}).then(function(res){
      if(!res||!res.success){ $('mMsg').className='msg err'; $('mMsg').textContent=(res&&res.error)||'Could not save.'; return; }
      r.verified=val; $('mMsg').className='msg ok'; $('mMsg').textContent=val?'Marked verified':'Marked not verified';
      var tr=document.querySelector('#fcvBody tr[data-i="'+cur+'"] [data-c="v"]'); if(tr) tr.innerHTML=val?'<span class="badge g">Verified</span>':'<span class="badge r">Not verified</span>';
      var d=$('mList').querySelector('.li[data-i="'+cur+'"] .d'); if(d) d.style.background=val?'#16a34a':'#dc2626';
      kpis(); var keep=cur; show(keep); $('mMsg').className='msg ok'; $('mMsg').textContent=val?'Marked verified':'Marked not verified';
    }).catch(function(){ $('mMsg').className='msg err'; $('mMsg').textContent='Connection problem — try again.'; });
  }
  $('mVer').onclick=function(){ setVerify(1); }; $('mNver').onclick=function(){ setVerify(0); };

  /* ── form / quick ranges ── */
  $('fcvForm').addEventListener('submit',function(e){ e.preventDefault(); history.replaceState(null,'','fc_verify.php?'+qs()); load(); });
  [].forEach.call(document.querySelectorAll('#fcvChips a'),function(a){ a.onclick=function(){ $('fcFrom').value=a.dataset.from; $('fcTo').value=a.dataset.to; $('fcvForm').dispatchEvent(new Event('submit',{cancelable:true})); }; });
  document.body.appendChild($('fcm'));   // out of the page wrapper: always above the site header & sidebar
  load();
})();
</script>

<?php include 'footer.php'; ?>
