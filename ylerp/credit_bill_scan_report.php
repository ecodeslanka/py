<?php
/**
 * credit_bill_scan_report.php — Credit Bill FC Verify (was: Credit Bill Upload Report)
 * ─────────────────────────────────────────────────────────────────────
 *  Same way of working as FC Verify for cheques (fc_verify.php):
 *  • Pick a DELIVERY DATE range → the credit bills of that range load ONCE (up to 3000).
 *    Credit bill = field_summary_details (updated = 1) — by default only the ones still outstanding
 *    (Ikea / adjusted value − payments − credit notes > 0, as Credit Bill Summary), or every bill.
 *  • Shows whether the bill scan is uploaded (credit_bill_images) and whether it is verified.
 *  • Click a bill → LARGE viewer with the invoice scan(s), zoom / rotate, Previous / Next (← → keys) and the
 *    customer's PAY MODE stamped large on the invoice.
 *  • Verified / Not verified saves field_summary_details.bill_verified — the same flag the Credit Bill Summary
 *    Verify window uses (1 = verified, 0 = not verified, empty = pending) — and is logged in credit_bill_verify_logs.
 *    Needs the Edit right on this page.
 *  • Export Excel of the loaded range.
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include_once 'config.php';
ob_end_clean();
include_once 'auth.php';
if (session_status() === PHP_SESSION_NONE) @session_start();
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);

function cbv_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function cbv_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function cbv_valid_date($d) { if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$d, $m)) return false; return checkdate((int)$m[2], (int)$m[3], (int)$m[1]); }
function cbv_user() { return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? (isset($_SESSION['user_id']) ? 'User #' . $_SESSION['user_id'] : 'system'); }
function cbv_can_edit() { return function_exists('rbac_can') ? rbac_can('edit', 'credit_bill_scan_report') : true; }
function cbv_has($conn, $t) { $r = cbv_q($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'"); return $r && mysqli_num_rows($r) > 0; }
function cbv_col($conn, $t, $c) { $r = cbv_q($conn, "SHOW COLUMNS FROM `$t` LIKE '" . mysqli_real_escape_string($conn, $c) . "'"); return $r && mysqli_num_rows($r) > 0; }

/* defensive schema (the same tables / column save_credit_bill_verify.php uses) */
cbv_q($conn, "CREATE TABLE IF NOT EXISTS credit_bill_images (
  id INT AUTO_INCREMENT PRIMARY KEY, field_summary_detail_id INT NOT NULL, credit_request_id INT NULL,
  original_name VARCHAR(255) NOT NULL, stored_name VARCHAR(255) NOT NULL, file_path VARCHAR(500) NOT NULL,
  file_type VARCHAR(100) NULL, file_size INT NULL, uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_det (field_summary_detail_id), INDEX idx_cr (credit_request_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if (!cbv_col($conn, 'field_summary_details', 'bill_verified')) cbv_q($conn, "ALTER TABLE field_summary_details ADD COLUMN `bill_verified` TINYINT(1) NULL DEFAULT NULL");
cbv_q($conn, "CREATE TABLE IF NOT EXISTS credit_bill_verify_logs (
  id INT AUTO_INCREMENT PRIMARY KEY, detail_id INT NOT NULL, old_value VARCHAR(10) NULL, new_value VARCHAR(10) NULL,
  note VARCHAR(255) NULL, created_by VARCHAR(100) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX (detail_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── shared loader (screen + Excel) ── */
function cbv_load($conn, $from, $to, $verify, $scan, $scope, $pmode, $q) {
    $hasLs = cbv_has($conn, 'loading_summary_import_details'); $hasSi = cbv_has($conn, 'secondary_invoice_import_details'); $hasCn = cbv_has($conn, 'credit_notes');
    $cwd = cbv_col($conn, 'customers', 'cheques_will_delay');
    $net = $hasSi ? 'COALESCE(siid.f, fsd.adjust_net_value, fsd.net_value, 0)' : 'COALESCE(fsd.adjust_net_value, fsd.net_value, 0)';
    $bal = "($net - COALESCE(pay.t,0) - " . ($hasCn ? 'COALESCE(cn.t,0)' : '0') . ")";
    $sr  = $hasLs ? 'COALESCE(ls.sp, fs.sr_code)' : 'fs.sr_code'; $rt = $hasLs ? 'COALESCE(ls.rc, fs.route)' : 'fs.route';
    $w = ["fsd.updated = 1", "fs.delivery_date BETWEEN '" . mysqli_real_escape_string($conn, $from) . "' AND '" . mysqli_real_escape_string($conn, $to) . "'"];
    if ($scope !== 'all') $w[] = "$bal > 0.004";
    if ($verify === 'yes') $w[] = "fsd.bill_verified = 1";
    if ($verify === 'no')  $w[] = "fsd.bill_verified = 0";
    if ($verify === 'pending') $w[] = "fsd.bill_verified IS NULL";
    if ($verify === 'notyes') $w[] = "COALESCE(fsd.bill_verified,0) = 0";
    if ($scan === 'yes') $w[] = "img.n > 0";
    if ($scan === 'no')  $w[] = "COALESCE(img.n,0) = 0";
    $pm = "LOWER(TRIM(COALESCE(c.payment_mode,'')))";
    if ($pmode === 'cwd') $w[] = $cwd ? 'COALESCE(c.cheques_will_delay,0) = 1' : '1=0';
    elseif ($pmode === 'none') $w[] = "$pm = ''";
    elseif (in_array($pmode, ['credit', 'cheque', 'cash'], true)) $w[] = "$pm = '$pmode'";
    if ($q !== '') { $e = mysqli_real_escape_string($conn, $q);
        $w[] = "(fsd.invoice_num LIKE '%$e%' OR fsd.t_code LIKE '%$e%' OR fsd.customer_name LIKE '%$e%' OR c.shop_name LIKE '%$e%' OR $sr LIKE '%$e%' OR $rt LIKE '%$e%')"; }
    $sql = "SELECT fsd.id, fsd.invoice_num, fsd.t_code, fs.delivery_date, $sr AS sr_code, $rt AS route,
                   COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS customer_name,
                   $net AS net, COALESCE(pay.t,0) AS paid, " . ($hasCn ? 'COALESCE(cn.t,0)' : '0') . " AS cn, $bal AS balance,
                   fsd.bill_verified AS verified, COALESCE(img.n,0) AS n_img,
                   $pm AS pay_mode, COALESCE(c.credit_days,0) AS credit_days, " . ($cwd ? 'COALESCE(c.cheques_will_delay,0)' : '0') . " AS cwd,
                   DATEDIFF(CURDATE(), fs.delivery_date) AS age
            FROM field_summary_details fsd
            INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
            LEFT JOIN customers c ON c.t_code = fsd.t_code
            " . ($hasSi ? "LEFT JOIN (SELECT bill_no, MAX(final_bill_amount) AS f FROM secondary_invoice_import_details GROUP BY bill_no) siid ON siid.bill_no = fsd.invoice_num" : '') . "
            " . ($hasLs ? "LEFT JOIN (SELECT bill_no, MIN(NULLIF(sales_person_code,'')) AS sp, MIN(NULLIF(route_code,'')) AS rc FROM loading_summary_import_details WHERE status IN ('imported','cancelled') GROUP BY bill_no) ls ON ls.bill_no = fsd.invoice_num" : '') . "
            LEFT JOIN (SELECT field_summary_detail_id, SUM(amount) AS t FROM invoice_payments WHERE is_reversed = 0 GROUP BY field_summary_detail_id) pay ON pay.field_summary_detail_id = fsd.id
            " . ($hasCn ? "LEFT JOIN (SELECT field_summary_detail_id, SUM(amount) AS t FROM credit_notes WHERE is_deleted = 0 GROUP BY field_summary_detail_id) cn ON cn.field_summary_detail_id = fsd.id" : '') . "
            LEFT JOIN (SELECT field_summary_detail_id, COUNT(*) AS n FROM credit_bill_images GROUP BY field_summary_detail_id) img ON img.field_summary_detail_id = fsd.id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY fs.delivery_date ASC, $rt, fsd.invoice_num LIMIT 3000";
    $rows = []; $ids = [];
    $r = cbv_q($conn, $sql);
    while ($r && ($x = mysqli_fetch_assoc($r))) {
        foreach (['net', 'paid', 'cn', 'balance'] as $k) $x[$k] = round((float)$x[$k], 2);
        $x['id'] = (int)$x['id']; $x['n_img'] = (int)$x['n_img']; $x['credit_days'] = (int)$x['credit_days']; $x['cwd'] = (int)$x['cwd']; $x['age'] = (int)$x['age'];
        $x['verified'] = $x['verified'] === null ? null : (int)$x['verified']; $x['imgs'] = [];
        $rows[$x['id']] = $x; if ($x['n_img']) $ids[] = $x['id'];
    }
    foreach (array_chunk($ids, 800) as $part) {
        $r = cbv_q($conn, "SELECT field_summary_detail_id AS d, file_path, original_name, uploaded_at FROM credit_bill_images WHERE field_summary_detail_id IN (" . implode(',', $part) . ") ORDER BY id");
        while ($r && ($x = mysqli_fetch_assoc($r))) $rows[(int)$x['d']]['imgs'][] = ['p' => $x['file_path'], 'n' => $x['original_name'], 'at' => $x['uploaded_at']];
    }
    return array_values($rows);
}
function cbv_filters() {
    $today = date('Y-m-d');
    $from = cbv_valid_date($_GET['date_from'] ?? '') ? $_GET['date_from'] : date('Y-m-01');
    $to   = cbv_valid_date($_GET['date_to'] ?? '')   ? $_GET['date_to']   : $today;
    if ($from > $to) [$from, $to] = [$to, $from];
    $verify = in_array($_GET['verify'] ?? 'all', ['all', 'yes', 'no', 'pending', 'notyes'], true) ? ($_GET['verify'] ?? 'all') : 'all';
    $scan   = in_array($_GET['scan'] ?? 'all', ['all', 'yes', 'no'], true) ? ($_GET['scan'] ?? 'all') : 'all';
    $scope  = ($_GET['scope'] ?? 'open') === 'all' ? 'all' : 'open';
    $pmode  = in_array($_GET['pay_mode'] ?? '', ['credit', 'cheque', 'cash', 'cwd', 'none'], true) ? $_GET['pay_mode'] : '';
    $q      = substr(is_string($_GET['q'] ?? '') ? trim($_GET['q']) : '', 0, 100);
    return [$from, $to, $verify, $scan, $scope, $pmode, $q];
}

/* ═══════════════ AJAX ═══════════════ */
if (($_GET['ajax'] ?? '') === 'load') {
    while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json');
    [$from, $to, $verify, $scan, $scope, $pmode, $q] = cbv_filters();
    $rows = cbv_load($conn, $from, $to, $verify, $scan, $scope, $pmode, $q);
    echo json_encode(['success' => true, 'rows' => $rows, 'can_edit' => cbv_can_edit(), 'capped' => count($rows) >= 3000], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
if (($_POST['action'] ?? '') === 'update_verify') {
    while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json');
    if (!cbv_can_edit()) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Permission blocked: you cannot edit records here.']); exit; }
    $id = intval($_POST['detail_id'] ?? 0); $v = (string)($_POST['value'] ?? '');
    if (!$id || !in_array($v, ['1', '0', ''], true)) { echo json_encode(['success' => false, 'error' => 'Invalid bill.']); exit; }
    $o = cbv_q($conn, "SELECT bill_verified FROM field_summary_details WHERE id = $id LIMIT 1"); $old = $o ? (mysqli_fetch_row($o)[0] ?? null) : null;
    if (!cbv_q($conn, "UPDATE field_summary_details SET bill_verified = " . ($v === '' ? 'NULL' : (int)$v) . " WHERE id = $id")) { echo json_encode(['success' => false, 'error' => 'Could not save.']); exit; }
    $u = mysqli_real_escape_string($conn, cbv_user()); $lab = $v === '1' ? 'Verified' : ($v === '0' ? 'Not verified' : 'Pending');
    cbv_q($conn, "INSERT INTO credit_bill_verify_logs (detail_id, old_value, new_value, note, created_by) VALUES ($id, " . ($old === null ? 'NULL' : "'" . (int)$old . "'") . ", " . ($v === '' ? 'NULL' : "'$v'") . ", 'Marked $lab (Credit Bill FC Verify)', '$u')");
    echo json_encode(['success' => true, 'value' => $v === '' ? null : (int)$v]); exit;
}

/* ═══════════════ EXCEL ═══════════════ */
if (isset($_GET['export'])) {
    require_once __DIR__ . '/rbac_xlsx.php';
    if (!class_exists('ZipArchive')) { http_response_code(500); exit('The PHP zip extension is required for Excel export.'); }
    [$from, $to, $verify, $scan, $scope, $pmode, $q] = cbv_filters();
    $rows = cbv_load($conn, $from, $to, $verify, $scan, $scope, $pmode, $q);
    $X = new MiniXlsx(); $s = $X->sheet('Credit Bill FC Verify');
    $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '1E3A8A', 'h' => 'center', 'border' => 1, 'wrap' => 1];
    $s->set(1, 1, 'Credit Bill FC Verify — delivered ' . date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to)) . ($scope === 'all' ? ' (all bills)' : ' (outstanding bills)'), ['b' => 1, 'sz' => 15]);
    $nv = count(array_filter($rows, fn($r) => $r['verified'] === 1)); $nu = count(array_filter($rows, fn($r) => $r['n_img'] > 0));
    $s->set(2, 1, 'Bills: ' . count($rows) . ' · Verified: ' . $nv . ' · Scan uploaded: ' . $nu . ' · Balance Rs. ' . number_format(array_sum(array_column($rows, 'balance')), 2) . ' · ' . date('d M Y H:i') . ' by ' . cbv_user(), ['i' => 1, 'color' => '6B7280']);
    foreach (['#', 'Delivery date', 'Invoice no', 'Customer', 'T code', 'Route', 'SR', 'Pay mode', 'Credit days', 'Net value', 'Paid', 'Credit notes', 'Balance', 'Scans', 'Verified'] as $i => $h) $s->set(4, $i + 1, $h, $H);
    $PM = ['credit' => 'Credit', 'cheque' => 'Cheque', 'cash' => 'Cash', '' => 'Not set'];
    $r = 5; foreach ($rows as $n => $x) {
        $v = [$n + 1, $x['delivery_date'], $x['invoice_num'], $x['customer_name'], $x['t_code'], $x['route'], $x['sr_code'], ($x['cwd'] ? 'Cheques will delay' : ($PM[$x['pay_mode']] ?? ucfirst($x['pay_mode']))), $x['credit_days'], $x['net'], $x['paid'], $x['cn'], $x['balance'], $x['n_img'] ? $x['n_img'] . ' uploaded' : 'Not uploaded',
              $x['verified'] === 1 ? 'Verified' : ($x['verified'] === 0 ? 'Not verified' : 'Pending')];
        foreach ($v as $i => $val) $s->set($r, $i + 1, $val ?? '', ['border' => 1] + (in_array($i, [9, 10, 11, 12], true) ? ['h' => 'right', 'nf' => 4] : []) + ($i === 14 ? ['b' => 1, 'color' => $x['verified'] === 1 ? '15803D' : ($x['verified'] === 0 ? 'B91C1C' : 'B45309')] : []));
        $r++; }
    foreach ([5, 12, 14, 30, 10, 10, 8, 16, 10, 14, 14, 14, 14, 14, 13] as $i => $w) $s->widths[$i + 1] = $w; $s->freeze = [4, 0]; $s->filter = 'A4:O' . max(5, $r - 1);
    $tmp = tempnam(sys_get_temp_dir(), 'cbv'); $X->save($tmp); while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="credit_bill_fc_verify_' . $from . '_to_' . $to . '.xlsx"');
    header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}

/* ═══════════════ PAGE ═══════════════ */
[$f_from, $f_to, $f_verify, $f_scan, $f_scope, $f_pmode, $f_q] = cbv_filters();
$can_edit = cbv_can_edit();
$today = date('Y-m-d');
$quick = ['Today' => [$today, $today], 'Yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))], 'Last 7 days' => [date('Y-m-d', strtotime('-6 days')), $today],
          'This month' => [date('Y-m-01'), $today], 'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))]];
include 'header.php';
?>
<style>
.cbv, .cbv *, .cbv *::before, .cbv *::after { box-sizing:border-box; }
.cbv { --ink:#111827; --muted:#6b7280; --line:#e5e7eb; --blue:#1e40af; font-family:'Inter',system-ui,sans-serif; font-size:13px; line-height:1.45; color:var(--ink); background:#f4f5f8; padding:22px 26px 48px; }
.cbv h1 { margin:0; font-size:21px; font-weight:700; } .cbv .sub { margin:4px 0 16px; color:var(--muted); max-width:100ch; } .cbv .muted { color:var(--muted); font-size:12px; }
.cbv .card { background:#fff; border:1px solid var(--line); border-radius:10px; padding:14px 16px; margin-bottom:14px; }
.cbv form.flt { display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px; } .cbv .fld { display:flex; flex-direction:column; gap:4px; } .cbv .fld label { font-size:12px; font-weight:600; color:var(--muted); }
.cbv input[type=date], .cbv input[type=search], .cbv select { height:36px; padding:0 10px; border:1px solid #d1d5db; border-radius:7px; font:inherit; color:var(--ink); background:#fff; }
.cbv input[type=search] { width:230px; max-width:100%; }
.cbv .btn { display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 16px; border:1px solid transparent; border-radius:7px; background:var(--blue); color:#fff; font:inherit; font-weight:600; cursor:pointer; text-decoration:none; }
.cbv .btn.xl { background:#fff; color:#15803d; border-color:#86efac; }
.cbv .chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:12px; padding-top:12px; border-top:1px solid var(--line); }
.cbv .chips a { padding:4px 11px; border:1px solid var(--line); border-radius:999px; font-size:12px; color:var(--muted); background:#fff; cursor:pointer; }
.cbv .kpis { display:grid; grid-template-columns:repeat(6,1fr); gap:10px; margin-bottom:14px; }
.cbv .kpi { background:#fff; border:1px solid var(--line); border-radius:10px; padding:11px 13px; } .cbv .kpi .l { font-size:11px; font-weight:600; color:var(--muted); } .cbv .kpi .v { font-size:20px; font-weight:700; margin-top:2px; }
.cbv .kpi.g .v { color:#157f3d; } .cbv .kpi.r .v { color:#c81e1e; } .cbv .kpi.a .v { color:#b45309; }
.cbv .wrap { overflow-x:auto; } .cbv table.t { width:100%; min-width:1050px; border-collapse:collapse; }
.cbv .t th { padding:9px 10px; text-align:left; font-size:11.5px; font-weight:700; color:var(--muted); background:#f9fafb; border-bottom:1px solid var(--line); white-space:nowrap; }
.cbv .t td { padding:9px 10px; border-bottom:1px solid #f0f1f4; vertical-align:middle; } .cbv .t td.num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
.cbv .t tbody tr { cursor:pointer; } .cbv .t tbody tr:hover td { background:#eff6ff; }
.cbv .badge, .cbm .badge { display:inline-flex; align-items:center; padding:2px 9px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; border:1px solid; }
.badge.g { background:#f0fdf4; color:#16a34a; border-color:#bbf7d0; } .badge.r { background:#fef2f2; color:#dc2626; border-color:#fecaca; } .badge.a { background:#fffbeb; color:#b45309; border-color:#fde68a; } .badge.b { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; } .badge.p { background:#faf5ff; color:#7e22ce; border-color:#e9d5ff; } .badge.s { background:#f3f4f6; color:#374151; border-color:#e5e7eb; }
.cbv .empty { padding:34px 16px; text-align:center; color:var(--muted); }
/* large viewer */
.cbm { display:none; position:fixed; inset:0; z-index:2147483000; background:rgba(15,23,42,.85); backdrop-filter:blur(3px); padding:1.2vh 1vw; font-family:'Inter',system-ui,sans-serif; font-size:13px; color:#111827; }
.cbm.open { display:block; } .cbm *, .cbm *::before, .cbm *::after { box-sizing:border-box; }
.cbm .box { background:#fff; border-radius:16px; width:100%; height:100%; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 24px 80px rgba(0,0,0,.55); }
.cbm .top { display:flex; align-items:center; gap:12px; padding:12px 18px; background:linear-gradient(90deg,#1e3a8a,#2563eb); color:#fff; flex-shrink:0; }
.cbm .top .ttl { font-size:18px; font-weight:800; } .cbm .top .cnt { background:rgba(255,255,255,.22); border-radius:999px; padding:3px 14px; font-weight:800; } .cbm .top .sp { flex:1; }
.cbm .nb { height:36px; padding:0 16px; border:1px solid rgba(255,255,255,.5); border-radius:9px; background:rgba(255,255,255,.15); color:#fff; font:inherit; font-weight:700; cursor:pointer; } .cbm .nb:disabled { opacity:.4; cursor:default; }
.cbm .x { width:36px; height:36px; border-radius:50%; border:none; background:#dc2626; color:#fff; font-size:20px; cursor:pointer; }
.cbm .body { flex:1; min-height:0; display:grid; grid-template-columns:240px minmax(0,1fr) 300px; }
.cbm .lst { border-right:1px solid #e5e7eb; overflow-y:auto; background:#f8fafc; }
.cbm .li { padding:10px 12px; border-bottom:1px solid #eef0f3; cursor:pointer; display:flex; gap:9px; align-items:center; } .cbm .li:hover { background:#eff6ff; } .cbm .li.on { background:#dbeafe; box-shadow:inset 4px 0 0 #1e40af; }
.cbm .li .n { font-weight:700; font-size:12.5px; } .cbm .li .s { font-size:11px; color:#6b7280; } .cbm .li .d { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.cbm .imgs { min-height:0; padding:12px; background:#0f172a; display:flex; flex-direction:column; gap:10px; }
.cbm .ph { display:flex; align-items:center; gap:6px; color:#e2e8f0; flex-shrink:0; }
.cbm .ph .lb { font-size:11px; font-weight:800; letter-spacing:.1em; text-transform:uppercase; margin-right:auto; }
.cbm .ph button { border:1px solid #475569; background:#334155; color:#f1f5f9; border-radius:6px; font-size:12px; font-weight:700; min-width:30px; height:28px; padding:0 9px; cursor:pointer; }
.cbm .pv { flex:1; min-height:0; overflow:auto; display:flex; align-items:center; justify-content:center; padding:6px; position:relative; background:#111827; border:1px solid #334155; border-radius:12px; }
.cbm .pv img { width:100%; height:100%; object-fit:contain; cursor:zoom-in; display:block; border-radius:4px; background:#fff; transition:transform .15s; } .cbm .pv img.zm { width:auto; height:auto; max-width:none; max-height:none; object-fit:initial; }
.cbm .pv iframe { width:100%; height:100%; border:0; background:#fff; border-radius:4px; }
.cbm .none { color:#94a3b8; text-align:center; font-weight:600; } .cbm .none i { font-size:46px; display:block; margin-bottom:8px; color:#475569; }
.cbm .thumbs { display:flex; gap:8px; overflow-x:auto; flex-shrink:0; } .cbm .thumbs img, .cbm .thumbs span { width:64px; height:48px; object-fit:cover; border-radius:6px; border:2px solid transparent; cursor:pointer; background:#fff; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:800; }
.cbm .thumbs .on { border-color:#60a5fa; }
/* the customer's pay mode stamped large on the invoice */
.cbm .stamp { position:absolute; top:16px; left:50%; transform:translateX(-50%) rotate(-4deg); z-index:3; pointer-events:none; padding:8px 28px; border:4px solid; border-radius:12px; font-size:clamp(22px,2.8vw,40px); font-weight:900; letter-spacing:.08em; text-transform:uppercase; white-space:nowrap; background:rgba(255,255,255,.9); box-shadow:0 6px 18px rgba(0,0,0,.35); text-align:center; }
.cbm .stamp small { display:block; font-size:.36em; letter-spacing:.12em; opacity:.85; margin-top:-2px; }
.cbm .stamp.p-credit { color:#1d4ed8; border-color:#1d4ed8; } .cbm .stamp.p-cheque { color:#7e22ce; border-color:#7e22ce; } .cbm .stamp.p-cash { color:#b91c1c; border-color:#b91c1c; } .cbm .stamp.p-cwd { color:#c2410c; border-color:#c2410c; } .cbm .stamp.p-none { color:#475569; border-color:#94a3b8; font-size:clamp(15px,1.7vw,22px); }
.cbm .det { border-left:1px solid #e5e7eb; overflow-y:auto; padding:16px 18px; } .cbm .det .amt { font-size:28px; font-weight:800; color:#0f172a; } .cbm .det .amt small { font-size:12px; color:#6b7280; font-weight:600; display:block; }
.cbm .det dt { font-size:10.5px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; margin-top:11px; } .cbm .det dd { margin:1px 0 0; font-weight:600; word-break:break-word; }
.cbm .foot { display:flex; align-items:center; gap:10px; padding:11px 18px; border-top:1px solid #e5e7eb; background:#f8fafc; flex-shrink:0; flex-wrap:wrap; }
.cbm .vb { height:40px; padding:0 22px; border-radius:10px; font:inherit; font-weight:800; cursor:pointer; border:1px solid; } .cbm .vb.v { background:#16a34a; border-color:#16a34a; color:#fff; } .cbm .vb.nv { background:#fff; border-color:#fecaca; color:#b91c1c; } .cbm .vb.pd { background:#fff; border-color:#d1d5db; color:#374151; }
.cbm .vb:disabled { opacity:.45; cursor:not-allowed; } .cbm .msg { font-size:12.5px; font-weight:700; } .cbm .msg.ok { color:#157f3d; } .cbm .msg.err { color:#c81e1e; }
.cbm .hint { margin-left:auto; font-size:11.5px; color:#6b7280; } .cbm label.auto { font-size:12px; color:#374151; display:flex; gap:6px; align-items:center; cursor:pointer; }
.cbm .zoomov { display:none; position:fixed; inset:0; z-index:2147483100; background:rgba(0,0,0,.94); align-items:center; justify-content:center; cursor:zoom-out; } .cbm .zoomov.open { display:flex; } .cbm .zoomov img { max-width:96vw; max-height:96vh; background:#fff; border-radius:6px; }
@media (max-width:1100px) { .cbm .body { grid-template-columns:1fr; grid-template-rows:110px minmax(0,1fr) auto; } .cbm .lst { display:flex; overflow-x:auto; border-right:none; border-bottom:1px solid #e5e7eb; } .cbm .li { min-width:200px; } .cbm .det { border-left:none; border-top:1px solid #e5e7eb; max-height:30vh; } .cbm .hint { display:none; } .cbv .kpis { grid-template-columns:repeat(3,1fr); } }
@media (max-width:560px) { .cbv { padding:16px 12px 40px; } .cbv .kpis { grid-template-columns:1fr 1fr; } }
</style>

<div class="cbv">
    <h1>Credit Bill FC Verify <span class="muted" style="font-weight:500">— invoice scan verify &amp; upload check</span></h1>
    <p class="sub">Choose the <b>delivery date range</b>. The credit bills of that range load <b>once</b>; click a bill to open the large viewer, check the invoice scan (the customer's <b>pay mode</b> is stamped on it), mark it Verified and move to the next bill (← → keys).</p>

    <section class="card">
        <form class="flt" id="cbvForm" method="get" action="credit_bill_scan_report.php">
            <div class="fld"><label for="cFrom">Delivery date from</label><input type="date" id="cFrom" name="date_from" value="<?= cbv_h($f_from) ?>" required></div>
            <div class="fld"><label for="cTo">Delivery date to</label><input type="date" id="cTo" name="date_to" value="<?= cbv_h($f_to) ?>" required></div>
            <div class="fld"><label>Bills</label><select name="scope"><option value="open"<?= $f_scope === 'open' ? ' selected' : '' ?>>Outstanding only</option><option value="all"<?= $f_scope === 'all' ? ' selected' : '' ?>>All credit bills</option></select></div>
            <div class="fld"><label>Show</label><select name="verify"><?php foreach (['all' => 'All', 'notyes' => 'Not verified + pending', 'pending' => 'Pending', 'no' => 'Not verified', 'yes' => 'Verified'] as $k => $l): ?><option value="<?= $k ?>"<?= $f_verify === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="fld"><label>Scan</label><select name="scan"><?php foreach (['all' => 'All', 'yes' => 'Uploaded', 'no' => 'Not uploaded'] as $k => $l): ?><option value="<?= $k ?>"<?= $f_scan === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="fld"><label>Pay mode</label><select name="pay_mode"><?php foreach (['' => 'All', 'credit' => 'Credit', 'cheque' => 'Cheque', 'cash' => 'Cash', 'cwd' => 'Cheques will delay', 'none' => 'Not set'] as $k => $l): ?><option value="<?= $k ?>"<?= $f_pmode === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="fld"><label>Search</label><input type="search" name="q" value="<?= cbv_h($f_q) ?>" placeholder="Invoice, customer, T code, route, SR" maxlength="100"></div>
            <button type="submit" class="btn"><i class="fa-solid fa-magnifying-glass"></i> Load bills</button>
            <a class="btn xl" id="cbvExport" href="#" data-no-loader><i class="fa-solid fa-file-excel"></i> Export Excel</a>
        </form>
        <div class="chips" id="cbvChips"><?php foreach ($quick as $l => $r): ?><a data-from="<?= $r[0] ?>" data-to="<?= $r[1] ?>"><?= cbv_h($l) ?></a><?php endforeach; ?></div>
    </section>

    <section class="kpis">
        <div class="kpi"><div class="l">Bills loaded</div><div class="v" id="kTot">0</div></div>
        <div class="kpi g"><div class="l">Verified</div><div class="v" id="kVer">0</div></div>
        <div class="kpi r"><div class="l">Not verified</div><div class="v" id="kNver">0</div></div>
        <div class="kpi a"><div class="l">Pending</div><div class="v" id="kPen">0</div></div>
        <div class="kpi r"><div class="l">Scan not uploaded</div><div class="v" id="kNo">0</div></div>
        <div class="kpi"><div class="l">Balance</div><div class="v" id="kBal" style="font-size:16px">Rs. 0.00</div></div>
    </section>

    <div class="card wrap">
        <div class="empty" id="cbvEmpty">Loading bills…</div>
        <table class="t" id="cbvTable" style="display:none">
            <thead><tr><th>#</th><th>Delivery</th><th>Invoice</th><th>Customer</th><th>Route · SR</th><th>Pay mode</th><th class="num">Net value</th><th class="num">Balance</th><th>Scan</th><th>Verified</th></tr></thead>
            <tbody id="cbvBody"></tbody>
        </table>
    </div>
    <p class="muted" id="cbvNote"></p>
</div>

<div class="cbm" id="cbm" role="dialog" aria-modal="true" aria-label="Credit bill viewer">
    <div class="box">
        <div class="top"><span class="ttl" id="mTitle">Invoice</span><span class="cnt" id="mCnt">1 / 1</span><span class="sp"></span>
            <button class="nb" id="mPrev" type="button">◀ Previous</button><button class="nb" id="mNext" type="button">Next ▶</button><button class="x" id="mClose" type="button" aria-label="Close">&times;</button></div>
        <div class="body">
            <div class="lst" id="mList"></div>
            <div class="imgs">
                <div class="ph"><span class="lb" id="mImgLb">Invoice scan</span><button type="button" data-z="-1" title="Zoom out">−</button><button type="button" data-z="1" title="Zoom in">+</button><button type="button" data-z="0" title="Fit">Fit</button><button type="button" id="mRot" title="Rotate">⟳</button><a id="mOpen" href="#" target="_blank" data-no-loader style="color:#93c5fd;font-size:12px;font-weight:700;margin-left:6px">Open file ↗</a></div>
                <div class="pv" id="mPv"></div>
                <div class="thumbs" id="mThumbs"></div>
            </div>
            <div class="det" id="mDet"></div>
        </div>
        <div class="foot">
            <button class="vb v" id="mVer" type="button">✔ Verified</button>
            <button class="vb nv" id="mNver" type="button">✖ Not verified</button>
            <button class="vb pd" id="mPend" type="button" title="Back to pending (not checked)">Pending</button>
            <label class="auto"><input type="checkbox" id="mAuto" checked> go to the next bill after marking</label>
            <span class="msg" id="mMsg"></span>
            <span class="hint">← → previous / next · V = verified · N = not verified · click the scan to zoom · Esc closes</span>
        </div>
    </div>
    <div class="zoomov" id="zoomOv"><img id="zoomImg" alt=""></div>
</div>

<script>
(function(){
  var CAN_EDIT = <?= $can_edit ? 'true' : 'false' ?>;
  var rows=[], cur=-1, img=0, rot=0, zm=1;
  var $=function(i){ return document.getElementById(i); };
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function money(v){ return Number(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function fdate(d){ if(!d||d==='0000-00-00') return '—'; var t=new Date(d.replace(/-/g,'/')); return isNaN(t)?d:t.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}); }
  function qs(){ return new URLSearchParams(new FormData($('cbvForm'))).toString(); }
  /* customer pay mode: [label, stamp class, badge class] */
  function pm(r){ if(r.cwd) return ['Cheques will delay','p-cwd','a'];
    return {credit:['Credit','p-credit','b'],cheque:['Cheque','p-cheque','p'],cash:['Cash','p-cash','r']}[r.pay_mode]||(r.pay_mode?[r.pay_mode,'p-none','s']:['Pay mode not set','p-none','s']); }
  function vb(v){ return v===1?'<span class="badge g">Verified</span>':(v===0?'<span class="badge r">Not verified</span>':'<span class="badge a">Pending</span>'); }
  function vcol(v){ return v===1?'#16a34a':(v===0?'#dc2626':'#f59e0b'); }
  function isPdf(p){ return /\.pdf($|\?)/i.test(p||''); }

  function load(){
    $('cbvEmpty').style.display='block'; $('cbvEmpty').textContent='Loading bills…'; $('cbvTable').style.display='none';
    fetch('credit_bill_scan_report.php?ajax=load&'+qs(),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(res){
      if(!res||!res.success){ $('cbvEmpty').textContent='Could not load the bills.'; return; }
      rows=res.rows||[]; renderList(); kpis();
      $('cbvNote').textContent=rows.length+' bill'+(rows.length===1?'':'s')+' loaded'+(res.capped?' — showing the first 3,000, narrow the dates':'');
    }).catch(function(){ $('cbvEmpty').textContent='Connection problem — try again.'; });
    $('cbvExport').href='credit_bill_scan_report.php?export=1&'+qs();
  }
  function kpis(){ var v=0,n=0,p=0,u=0,b=0; rows.forEach(function(r){ if(r.verified===1) v++; else if(r.verified===0) n++; else p++; if(!r.n_img) u++; b+=r.balance; });
    $('kTot').textContent=rows.length; $('kVer').textContent=v; $('kNver').textContent=n; $('kPen').textContent=p; $('kNo').textContent=u; $('kBal').textContent='Rs. '+money(b); }
  function renderList(){
    if(!rows.length){ $('cbvTable').style.display='none'; $('cbvEmpty').style.display='block'; $('cbvEmpty').textContent='No credit bills for this delivery date range and filter.'; return; }
    $('cbvEmpty').style.display='none'; $('cbvTable').style.display='table';
    $('cbvBody').innerHTML=rows.map(function(r,i){ var m=pm(r);
      return '<tr data-i="'+i+'"><td>'+(i+1)+'</td><td>'+fdate(r.delivery_date)+'<div class="muted">'+r.age+' days</div></td><td><b>'+esc(r.invoice_num)+'</b></td><td>'+esc(r.customer_name||'—')+'<div class="muted">'+esc(r.t_code||'')+'</div></td>'
        +'<td>'+esc(r.route||'—')+'<div class="muted">'+esc(r.sr_code||'')+'</div></td><td><span class="badge '+m[2]+'">'+esc(m[0])+'</span>'+(r.credit_days?'<div class="muted">'+r.credit_days+' days</div>':'')+'</td>'
        +'<td class="num">'+money(r.net)+'</td><td class="num"><b>'+money(r.balance)+'</b></td><td>'+(r.n_img?'<span class="badge g">'+r.n_img+' uploaded</span>':'<span class="badge r">Missing</span>')+'</td><td data-c="v">'+vb(r.verified)+'</td></tr>'; }).join('');
  }
  $('cbvBody').addEventListener('click',function(e){ var tr=e.target.closest('tr'); if(tr) openAt(parseInt(tr.dataset.i,10)); });

  /* ── viewer ── */
  function buildModalList(){ $('mList').innerHTML=rows.map(function(r,i){ return '<div class="li" data-i="'+i+'"><span class="d" style="background:'+vcol(r.verified)+'"></span><div><div class="n">'+esc(r.invoice_num)+' · Rs. '+money(r.balance)+'</div><div class="s">'+esc(r.customer_name||'—')+' · '+fdate(r.delivery_date)+(r.n_img?'':' · <b style="color:#dc2626">no scan</b>')+'</div></div></div>'; }).join(''); }
  $('mList').addEventListener('click',function(e){ var li=e.target.closest('.li'); if(li) show(parseInt(li.dataset.i,10)); });
  function drawImg(){
    var r=rows[cur], L=r.imgs||[], m=pm(r); rot=0; zm=1;
    var stamp='<div class="stamp '+m[1]+'" title="Pay mode saved for this customer">'+esc(m[0])+'<small>'+(r.credit_days?'pay mode · '+r.credit_days+' credit days':'customer pay mode')+'</small></div>';
    if(!L.length){ $('mPv').innerHTML=stamp+'<div class="none"><i class="fa-regular fa-image"></i>No invoice scan uploaded<br><small>Upload it on Credit Bill Summary → Verify</small></div>'; $('mThumbs').innerHTML=''; $('mOpen').style.display='none'; $('mImgLb').textContent='Invoice scan'; return; }
    if(img>=L.length) img=0; var p=L[img].p;
    $('mPv').innerHTML=stamp+(isPdf(p)?'<iframe src="'+esc(p)+'"></iframe>':'<img src="'+esc(p)+'" alt="Invoice scan">');
    $('mOpen').href=p; $('mOpen').style.display=''; $('mImgLb').textContent='Invoice scan '+(img+1)+' / '+L.length;
    $('mThumbs').innerHTML=L.length>1?L.map(function(x,i){ return isPdf(x.p)?'<span data-t="'+i+'" class="'+(i===img?'on':'')+'">PDF</span>':'<img data-t="'+i+'" src="'+esc(x.p)+'" class="'+(i===img?'on':'')+'" alt="">'; }).join(''):'';
  }
  $('mThumbs').addEventListener('click',function(e){ var t=e.target.closest('[data-t]'); if(t){ img=parseInt(t.dataset.t,10); drawImg(); } });
  function show(i){
    if(i<0||i>=rows.length) return; cur=i; img=0; var r=rows[i], m=pm(r);
    $('mTitle').textContent='Invoice '+(r.invoice_num||''); $('mCnt').textContent=(i+1)+' / '+rows.length; $('mPrev').disabled=i===0; $('mNext').disabled=i===rows.length-1;
    drawImg();
    $('mDet').innerHTML='<div class="amt">Rs. '+money(r.balance)+'<small>balance · net Rs. '+money(r.net)+'</small></div>'+vb(r.verified)+' <span class="badge '+m[2]+'">'+esc(m[0])+'</span>'
      +'<dl><dt>Invoice no</dt><dd>'+esc(r.invoice_num)+'</dd><dt>Delivery date</dt><dd>'+fdate(r.delivery_date)+' · '+r.age+' days ago</dd><dt>Customer</dt><dd>'+esc(r.customer_name||'—')+'</dd><dt>T code</dt><dd>'+esc(r.t_code||'—')+'</dd>'
      +'<dt>Pay mode</dt><dd>'+esc(m[0])+(r.credit_days?' · '+r.credit_days+' credit days':'')+'</dd><dt>Route · SR</dt><dd>'+esc(r.route||'—')+' · '+esc(r.sr_code||'—')+'</dd>'
      +'<dt>Net value</dt><dd>Rs. '+money(r.net)+'</dd><dt>Paid</dt><dd>Rs. '+money(r.paid)+'</dd><dt>Credit notes</dt><dd>Rs. '+money(r.cn)+'</dd><dt>Balance</dt><dd>Rs. '+money(r.balance)+'</dd>'
      +'<dt>Scans</dt><dd>'+(r.n_img?r.n_img+' uploaded'+(r.imgs[0]&&r.imgs[0].at?' · '+esc(r.imgs[0].at):''):'Not uploaded')+'</dd></dl>';
    $('mVer').disabled=$('mNver').disabled=$('mPend').disabled=!CAN_EDIT; $('mMsg').textContent=''; $('mMsg').className='msg';
    [].forEach.call($('mList').children,function(li){ li.classList.toggle('on',parseInt(li.dataset.i,10)===i); }); var on=$('mList').querySelector('.li.on'); if(on) on.scrollIntoView({block:'nearest'});
  }
  function openAt(i){ buildModalList(); $('cbm').classList.add('open'); document.body.style.overflow='hidden'; show(i); }
  function closeM(){ $('cbm').classList.remove('open'); $('zoomOv').classList.remove('open'); document.body.style.overflow=''; }
  $('mClose').onclick=closeM; $('cbm').addEventListener('mousedown',function(e){ if(e.target===$('cbm')) closeM(); });
  $('mPrev').onclick=function(){ show(cur-1); }; $('mNext').onclick=function(){ show(cur+1); };
  document.addEventListener('keydown',function(e){ if(!$('cbm').classList.contains('open')) return;
    if(e.key==='Escape'){ if($('zoomOv').classList.contains('open')) $('zoomOv').classList.remove('open'); else closeM(); }
    else if(e.key==='ArrowLeft'){ e.preventDefault(); show(cur-1); } else if(e.key==='ArrowRight'){ e.preventDefault(); show(cur+1); }
    else if((e.key==='v'||e.key==='V')&&CAN_EDIT){ setVerify('1'); } else if((e.key==='n'||e.key==='N')&&CAN_EDIT){ setVerify('0'); } });
  function pImg(){ return document.querySelector('#mPv img'); }
  $('mRot').onclick=function(){ var im=pImg(); if(!im) return; rot=(rot+90)%360; im.style.transform='rotate('+rot+'deg)'; };
  [].forEach.call(document.querySelectorAll('[data-z]'),function(b){ b.onclick=function(){ var im=pImg(), d=parseInt(b.dataset.z,10); if(!im) return; zm=d===0?1:Math.max(1,Math.min(4,zm+d*0.5));
    if(zm===1){ im.classList.remove('zm'); im.style.width=''; im.style.height=''; } else { im.classList.add('zm'); im.style.width=(zm*100)+'%'; im.style.height='auto'; } }; });
  $('mPv').addEventListener('click',function(e){ var im=e.target.closest('img'); if(im){ $('zoomImg').src=im.src; $('zoomOv').classList.add('open'); } });
  $('zoomOv').onclick=function(){ $('zoomOv').classList.remove('open'); };

  function setVerify(val){
    if(cur<0||!CAN_EDIT) return; var r=rows[cur], at=cur, fd=new FormData(); fd.append('action','update_verify'); fd.append('detail_id',r.id); fd.append('value',val);
    $('mMsg').className='msg'; $('mMsg').textContent='Saving…';
    fetch('credit_bill_scan_report.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(x){ return x.json().catch(function(){ return null; }); }).then(function(res){
      if(!res||!res.success){ $('mMsg').className='msg err'; $('mMsg').textContent=(res&&res.error)||'Could not save.'; return; }
      r.verified=res.value; kpis();
      var td=document.querySelector('#cbvBody tr[data-i="'+at+'"] [data-c="v"]'); if(td) td.innerHTML=vb(r.verified);
      var d=$('mList').querySelector('.li[data-i="'+at+'"] .d'); if(d) d.style.background=vcol(r.verified);
      var txt=r.verified===1?'Marked verified':(r.verified===0?'Marked not verified':'Back to pending');
      if($('mAuto').checked&&at<rows.length-1&&val!==''){ show(at+1); } else show(at);
      $('mMsg').className='msg ok'; $('mMsg').textContent=txt+(cur!==at?' — '+rows[at].invoice_num:'');
    }).catch(function(){ $('mMsg').className='msg err'; $('mMsg').textContent='Connection problem — try again.'; });
  }
  $('mVer').onclick=function(){ setVerify('1'); }; $('mNver').onclick=function(){ setVerify('0'); }; $('mPend').onclick=function(){ setVerify(''); };

  $('cbvForm').addEventListener('submit',function(e){ e.preventDefault(); history.replaceState(null,'','credit_bill_scan_report.php?'+qs()); load(); });
  [].forEach.call(document.querySelectorAll('#cbvChips a'),function(a){ a.onclick=function(){ $('cFrom').value=a.dataset.from; $('cTo').value=a.dataset.to; $('cbvForm').dispatchEvent(new Event('submit',{cancelable:true})); }; });
  document.body.appendChild($('cbm'));   // above the site header / sidebar
  load();
})();
</script>
<?php include 'footer.php'; ?>
