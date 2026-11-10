<?php
/**
 * cheque_scan_report.php
 * ─────────────────────────────────────────────────────────────────────
 * CHEQUE SCAN & VERIFICATION REPORT
 * (like credit_bill_scan_report.php, but for cheques)
 *
 * SCOPE
 *   Every cheque in `cheques`, loaded by RECEIVED DATE
 *   (COALESCE(cheques.received_date, invoice_payments.payment_date) — the same
 *   "received date" the Cheque Register filters on). Sampath Super Payment
 *   cheques (no invoice_payments row) are included.
 *
 * UPLOADED?
 *   Yes when the cheque has a front and / or back image
 *   (cheques.cheque_front_image / cheque_back_image — the same columns the
 *   Cheque Register "Verify" modal and Bulk Cheque Verify fill).
 *   The scans are shown as thumbnails inline; click for full size.
 *
 * VERIFIED?
 *   cheques.verified: 1 = Verified, 0 = Not Verified.
 *
 * ACTIONS (saved immediately, written to cheque_logs)
 *   • Upload Front / Upload Back scan
 *   • Mark Verified / Not Verified
 *   Needs the Edit right on this page (View-only roles see the report only).
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');

ob_start();
include_once 'config.php';
ob_end_clean();
include_once 'auth.php';
if (session_status() === PHP_SESSION_NONE) @session_start();

function csr_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csr_get($k, $d = '') { return isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : $d; }
function csr_valid_date($d) { if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false; return checkdate((int)$m[2], (int)$m[3], (int)$m[1]); }
function csr_money($v) { return number_format((float)$v, 2); }
function csr_date($d, $fmt = 'd M Y') { $t = ($d && $d !== '0000-00-00') ? strtotime((string)$d) : false; return $t ? date($fmt, $t) : '—'; }
function csr_user() {
    return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ?? $_SESSION['full_name'] ?? $_SESSION['email']
        ?? (isset($_SESSION['user_id']) ? 'User #' . $_SESSION['user_id'] : 'system');
}
function csr_can_edit() { return function_exists('rbac_can') ? rbac_can('edit', 'cheque_scan_report') : true; }

/* defensive schema */
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

/* ═══════════════════════════ AJAX (names map to the Edit right in rbac) ═══════════════════════════ */

/* update_scan_image: cheque_id, side=front|back, image */
if (($_POST['action'] ?? '') === 'update_scan_image') {
    header('Content-Type: application/json');
    if (!csr_can_edit()) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Permission blocked: you cannot edit records here.']); exit; }
    $cid  = intval($_POST['cheque_id'] ?? 0);
    $side = ($_POST['side'] ?? '') === 'back' ? 'back' : 'front';
    if (!$cid || empty($_FILES['image']['name']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) { echo json_encode(['success' => false, 'error' => 'No image received.']); exit; }
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) { echo json_encode(['success' => false, 'error' => 'Only JPG, PNG, WEBP or GIF images are allowed.']); exit; }
    if ($_FILES['image']['size'] > 15 * 1024 * 1024) { echo json_encode(['success' => false, 'error' => 'Image is larger than 15 MB.']); exit; }
    $dir = 'uploads/cheques/';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $path = $dir . $side . '_' . $cid . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $path)) { echo json_encode(['success' => false, 'error' => 'Could not save the file.']); exit; }
    $col = $side === 'back' ? 'cheque_back_image' : 'cheque_front_image';
    $pe  = mysqli_real_escape_string($conn, $path);
    if (!mysqli_query($conn, "UPDATE cheques SET `$col` = '$pe' WHERE id = $cid")) { echo json_encode(['success' => false, 'error' => mysqli_error($conn)]); exit; }
    $cu = mysqli_real_escape_string($conn, csr_user());
    mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id, action, new_value, note, created_by) VALUES ($cid, 'scan_uploaded', '$pe', 'Cheque $side scan uploaded (Cheque Scan Report)', '$cu')");
    echo json_encode(['success' => true, 'file_path' => $path, 'side' => $side]);
    exit;
}

/* update_verify: cheque_id, value=1|0 */
if (($_POST['action'] ?? '') === 'update_verify') {
    header('Content-Type: application/json');
    if (!csr_can_edit()) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Permission blocked: you cannot edit records here.']); exit; }
    $cid = intval($_POST['cheque_id'] ?? 0);
    $val = ($_POST['value'] ?? '') === '1' ? 1 : 0;
    if (!$cid) { echo json_encode(['success' => false, 'error' => 'Invalid cheque.']); exit; }
    $o = mysqli_query($conn, "SELECT COALESCE(verified,0) FROM cheques WHERE id = $cid LIMIT 1");
    $old = $o ? (int)(mysqli_fetch_row($o)[0] ?? 0) : 0;
    if (!mysqli_query($conn, "UPDATE cheques SET verified = $val WHERE id = $cid")) { echo json_encode(['success' => false, 'error' => mysqli_error($conn)]); exit; }
    $cu = mysqli_real_escape_string($conn, csr_user());
    mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id, action, old_value, new_value, note, created_by)
                         VALUES ($cid, 'verified', '$old', '$val', 'Marked " . ($val ? 'Verified' : 'Not Verified') . " (Cheque Scan Report)', '$cu')");
    echo json_encode(['success' => true, 'value' => $val]);
    exit;
}

/* ═══════════════════════════ FILTERS ═══════════════════════════ */
$today  = date('Y-m-d');
$f_from = csr_valid_date(csr_get('date_from')) ? csr_get('date_from') : date('Y-m-01');
$f_to   = csr_valid_date(csr_get('date_to'))   ? csr_get('date_to')   : $today;
if ($f_from > $f_to) { $t = $f_from; $f_from = $f_to; $f_to = $t; }
$f_scan   = csr_get('scan', 'all');   if (!in_array($f_scan, ['all', 'yes', 'no', 'partial'], true)) $f_scan = 'all';
$f_verify = csr_get('verify', 'all'); if (!in_array($f_verify, ['all', 'yes', 'no'], true)) $f_verify = 'all';
$f_status = csr_get('status', '');
$f_type   = csr_get('type', 'all');   if (!in_array($f_type, ['all', 'sampath', 'normal'], true)) $f_type = 'all';
$f_q      = substr(trim(csr_get('q')), 0, 100);

function csr_url($over = []) {
    global $f_from, $f_to, $f_scan, $f_verify, $f_status, $f_type, $f_q;
    $p = array_merge(['date_from' => $f_from, 'date_to' => $f_to, 'scan' => $f_scan, 'verify' => $f_verify, 'status' => $f_status, 'type' => $f_type, 'q' => $f_q], $over);
    foreach (['scan', 'verify', 'type'] as $k) if ($p[$k] === 'all') unset($p[$k]);
    foreach (['status', 'q'] as $k) if ($p[$k] === '') unset($p[$k]);
    return basename(__FILE__) . '?' . http_build_query($p);
}

/* ═══════════════════════════ QUERY ═══════════════════════════ */
$recv = "COALESCE(ch.received_date, ip.payment_date)";
$where = ["$recv BETWEEN '" . mysqli_real_escape_string($conn, $f_from) . "' AND '" . mysqli_real_escape_string($conn, $f_to) . "'"];
$hasF = "(ch.cheque_front_image IS NOT NULL AND ch.cheque_front_image <> '')";
$hasB = "(ch.cheque_back_image IS NOT NULL AND ch.cheque_back_image <> '')";
if ($f_scan === 'yes')     $where[] = "($hasF OR $hasB)";
if ($f_scan === 'no')      $where[] = "(NOT $hasF AND NOT $hasB)";
if ($f_scan === 'partial') $where[] = "(($hasF AND NOT $hasB) OR (NOT $hasF AND $hasB))";
if ($f_verify === 'yes')   $where[] = "COALESCE(ch.verified,0) = 1";
if ($f_verify === 'no')    $where[] = "COALESCE(ch.verified,0) = 0";
if ($f_status !== '')      $where[] = "ch.status = '" . mysqli_real_escape_string($conn, $f_status) . "'";
if ($f_type === 'sampath') $where[] = "(ch.sampath IS NOT NULL AND ch.sampath <> '')";
if ($f_type === 'normal')  $where[] = "(ch.sampath IS NULL OR ch.sampath = '')";
if ($f_q !== '') {
    $q = mysqli_real_escape_string($conn, $f_q);
    $where[] = "(ch.cheque_no LIKE '%$q%' OR ch.t_code LIKE '%$q%' OR ch.bank_code LIKE '%$q%' OR ch.bank_name LIKE '%$q%'
                 OR fsd.customer_name LIKE '%$q%' OR c.shop_name LIKE '%$q%' OR fs.sr_code LIKE '%$q%')";
}
$sql = "
SELECT ch.id, ch.cheque_no, ch.cheque_date, COALESCE(ch.total_amount, ch.amount, 0) AS amount,
       ch.bank_code, ch.bank_name, ch.branch_name, ch.status, ch.t_code,
       $recv AS received_date, fs.sr_code,
       COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, NULLIF(ch.t_code,''),
                CASE WHEN ch.sampath IS NOT NULL AND ch.sampath <> '' THEN CONCAT('Sampath Payment #', COALESCE(ch.sampath_payment_id,0)) END) AS customer_name,
       (ch.sampath IS NOT NULL AND ch.sampath <> '') AS is_sampath,
       COALESCE(ch.verified,0) AS verified,
       ch.cheque_front_image, ch.cheque_back_image
FROM cheques ch
LEFT JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id
LEFT JOIN field_summary fs ON fs.id = ip.field_summary_id
LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
LEFT JOIN customers c ON c.t_code = ch.t_code
WHERE " . implode(' AND ', $where) . "
ORDER BY $recv DESC, ch.cheque_date ASC, ch.cheque_no ASC
LIMIT 5000";
$res = mysqli_query($conn, $sql);
$rows = []; $c_total = $c_up = $c_no = $c_part = $c_ver = $c_nver = 0; $c_amt = 0.0;
if ($res) while ($r = mysqli_fetch_assoc($res)) {
    $rows[] = $r; $c_total++; $c_amt += (float)$r['amount'];
    $f = trim((string)$r['cheque_front_image']) !== ''; $b = trim((string)$r['cheque_back_image']) !== '';
    if ($f || $b) $c_up++; else $c_no++;
    if ($f xor $b) $c_part++;
    if ((int)$r['verified'] === 1) $c_ver++; else $c_nver++;
}
$statuses = [];
$sr = mysqli_query($conn, "SELECT DISTINCT status FROM cheques WHERE status IS NOT NULL AND status <> '' ORDER BY status");
while ($sr && ($x = mysqli_fetch_row($sr))) $statuses[] = $x[0];

$quick = [
    'Today'       => [$today, $today],
    'Last 7 days' => [date('Y-m-d', strtotime('-6 days')), $today],
    'This month'  => [date('Y-m-01'), $today],
    'Last month'  => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
];
$can_edit = csr_can_edit();
include 'header.php';
?>
<style>
.csr, .csr *, .csr *::before, .csr *::after { box-sizing: border-box; }
.csr { --ink:#111827; --muted:#6b7280; --line:#e5e7eb; --red:#c81e1e; --green:#157f3d; --amber:#92400e;
       font-family:'Inter',system-ui,sans-serif; font-size:13px; line-height:1.45; color:var(--ink); background:#f4f5f8; padding:22px 26px 48px; min-height:100%; }
.csr h1 { margin:0; font-size:21px; font-weight:700; }
.csr .sub { margin:4px 0 16px; color:var(--muted); max-width:90ch; }
.csr .muted { color:var(--muted); font-size:12px; }
.csr .card { background:#fff; border:1px solid var(--line); border-radius:10px; padding:14px 16px; margin-bottom:14px; }
.csr form.flt { display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px; }
.csr .fld { display:flex; flex-direction:column; gap:4px; }
.csr .fld label { font-size:12px; font-weight:600; color:var(--muted); }
.csr input[type=date], .csr input[type=search], .csr select { height:36px; padding:0 10px; border:1px solid #d1d5db; border-radius:7px; font:inherit; color:var(--ink); background:#fff; }
.csr input[type=search] { width:240px; max-width:100%; } .csr select { min-width:140px; }
.csr .btn { display:inline-flex; align-items:center; height:36px; padding:0 16px; border:1px solid transparent; border-radius:7px; background:#111827; color:#fff; font:inherit; font-weight:600; cursor:pointer; text-decoration:none; }
.csr .btn.light { background:#fff; color:var(--ink); border-color:#d1d5db; }
.csr .chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:12px; padding-top:12px; border-top:1px solid var(--line); }
.csr .chips a { padding:4px 11px; border:1px solid var(--line); border-radius:999px; font-size:12px; text-decoration:none; color:var(--muted); background:#fff; }
.csr .chips a.on { background:#111827; border-color:#111827; color:#fff; }
.csr .kpis { display:grid; grid-template-columns:repeat(7,1fr); gap:10px; margin-bottom:14px; }
.csr .kpi { background:#fff; border:1px solid var(--line); border-radius:10px; padding:11px 13px; margin:0; }
.csr .kpi .l { font-size:11px; font-weight:600; color:var(--muted); }
.csr .kpi .v { font-size:20px; font-weight:700; line-height:1.2; margin-top:2px; font-variant-numeric:tabular-nums; }
.csr .kpi.up .v, .csr .kpi.ver .v { color:var(--green); } .csr .kpi.notup .v, .csr .kpi.notver .v { color:var(--red); } .csr .kpi.part .v { color:var(--amber); }
.csr .wrap { overflow-x:auto; }
.csr table { width:100%; min-width:1250px; border-collapse:collapse; }
.csr th { padding:9px 10px; text-align:left; font-size:11.5px; font-weight:700; color:var(--muted); background:#f9fafb; border-bottom:1px solid var(--line); white-space:nowrap; }
.csr td { padding:9px 10px; border-bottom:1px solid #f0f1f4; vertical-align:top; }
.csr td.num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
.csr tr:hover td { background:#fafafb; } .csr .strong { font-weight:600; }
.csr .badge { display:inline-flex; align-items:center; gap:4px; padding:2px 9px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; border:1px solid; }
.csr .badge.up, .csr .badge.ver { background:#f0fdf4; color:#16a34a; border-color:#bbf7d0; }
.csr .badge.notup, .csr .badge.notver { background:#fef2f2; color:#dc2626; border-color:#fecaca; }
.csr .badge.part { background:#fffbeb; color:#b45309; border-color:#fde68a; }
.csr .badge.st { background:#f3f4f6; color:#374151; border-color:#e5e7eb; text-transform:capitalize; }
.csr .badge.samp { background:#fef9c3; color:#854d0e; border-color:#fde047; }
.csr .thumbs { display:flex; gap:6px; }
.csr .thumb { width:54px; text-align:center; font-size:9.5px; color:var(--muted); }
.csr .thumb .im { width:54px; height:44px; border-radius:6px; overflow:hidden; border:1px solid var(--line); cursor:zoom-in; background:#f9fafb; display:flex; align-items:center; justify-content:center; color:#d1d5db; font-size:16px; }
.csr .thumb .im img { width:100%; height:100%; object-fit:cover; display:block; }
.csr .actions { display:flex; flex-direction:column; gap:5px; min-width:150px; }
.csr .arow { display:flex; gap:5px; }
.csr .abtn { flex:1; display:inline-flex; align-items:center; justify-content:center; gap:4px; height:27px; border-radius:6px; font-size:11px; font-weight:700; cursor:pointer; border:1px solid #d1d5db; background:#fff; color:#374151; }
.csr .abtn.v { color:#15803d; border-color:#bbf7d0; } .csr .abtn.nv { color:#b91c1c; border-color:#fecaca; }
.csr .abtn:disabled, .csr .abtn.off { opacity:.5; cursor:not-allowed; }
.csr .stt { display:block; min-height:14px; font-size:10.5px; } .csr .stt.ok { color:var(--green); } .csr .stt.err { color:var(--red); font-weight:600; }
.csr .empty { padding:34px 16px; text-align:center; color:var(--muted); }
.csr .lightbox { display:none; position:fixed; inset:0; z-index:5000; background:rgba(0,0,0,.9); align-items:center; justify-content:center; padding:20px; }
.csr .lightbox.open { display:flex; } .csr .lightbox img { max-width:92vw; max-height:92vh; border-radius:6px; }
.csr .lightbox .x { position:fixed; top:16px; right:20px; background:rgba(220,38,38,.9); color:#fff; border:none; width:38px; height:38px; border-radius:50%; font-size:18px; cursor:pointer; }
@media (max-width:1100px) { .csr .kpis { grid-template-columns:repeat(4,1fr); } }
@media (max-width:560px) { .csr { padding:16px 12px 40px; } .csr .kpis { grid-template-columns:1fr 1fr; } .csr input[type=search] { width:100%; } }
</style>

<div class="csr" id="csrApp" data-can-edit="<?= $can_edit ? 1 : 0 ?>">
    <h1>Cheque Scan &amp; Verification Report</h1>
    <p class="sub">Cheques loaded by <b>received date</b>. Shows whether each cheque's scan (front / back) has been uploaded, whether it is verified, and the scans themselves — inline. Upload and Verified / Not Verified save immediately and show in the Cheque Register too.</p>

    <section class="card" aria-label="Filters">
        <form class="flt" method="get" action="<?= csr_h(basename(__FILE__)) ?>">
            <div class="fld"><label for="csrFrom">Received date from</label><input type="date" id="csrFrom" name="date_from" value="<?= csr_h($f_from) ?>" required></div>
            <div class="fld"><label for="csrTo">Received date to</label><input type="date" id="csrTo" name="date_to" value="<?= csr_h($f_to) ?>" required></div>
            <div class="fld"><label for="csrScan">Scan</label><select id="csrScan" name="scan">
                <?php foreach (['all' => 'All', 'yes' => 'Uploaded', 'no' => 'Not uploaded', 'partial' => 'Only front or back'] as $k => $l): ?><option value="<?= $k ?>"<?= $f_scan === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="fld"><label for="csrVer">Verified</label><select id="csrVer" name="verify">
                <?php foreach (['all' => 'All', 'yes' => 'Verified', 'no' => 'Not verified'] as $k => $l): ?><option value="<?= $k ?>"<?= $f_verify === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="fld"><label for="csrSt">Cheque status</label><select id="csrSt" name="status"><option value="">All</option>
                <?php foreach ($statuses as $s): ?><option value="<?= csr_h($s) ?>"<?= $f_status === $s ? ' selected' : '' ?>><?= csr_h(ucwords(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?></select></div>
            <div class="fld"><label for="csrType">Type</label><select id="csrType" name="type">
                <?php foreach (['all' => 'All', 'normal' => 'Normal', 'sampath' => 'Sampath'] as $k => $l): ?><option value="<?= $k ?>"<?= $f_type === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="fld"><label for="csrQ">Search</label><input type="search" id="csrQ" name="q" value="<?= csr_h($f_q) ?>" placeholder="Cheque no, customer, T code, bank, SR" maxlength="100"></div>
            <button type="submit" class="btn">Apply filters</button>
            <a class="btn light" href="<?= csr_h(basename(__FILE__)) ?>">Reset</a>
        </form>
        <div class="chips" aria-label="Quick received date ranges">
            <?php foreach ($quick as $label => $range): ?>
                <a href="<?= csr_h(csr_url(['date_from' => $range[0], 'date_to' => $range[1]])) ?>" class="<?= ($f_from === $range[0] && $f_to === $range[1]) ? 'on' : '' ?>"><?= csr_h($label) ?></a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="kpis" aria-label="Summary">
        <div class="kpi"><div class="l">Total cheques</div><div class="v"><?= number_format($c_total) ?></div></div>
        <div class="kpi up"><div class="l">Uploaded</div><div class="v" id="kUp"><?= number_format($c_up) ?></div></div>
        <div class="kpi notup"><div class="l">Not uploaded</div><div class="v" id="kNo"><?= number_format($c_no) ?></div></div>
        <div class="kpi part"><div class="l">Front or back only</div><div class="v"><?= number_format($c_part) ?></div></div>
        <div class="kpi ver"><div class="l">Verified</div><div class="v" id="kVer"><?= number_format($c_ver) ?></div></div>
        <div class="kpi notver"><div class="l">Not verified</div><div class="v" id="kNver"><?= number_format($c_nver) ?></div></div>
        <div class="kpi"><div class="l">Total amount</div><div class="v" style="font-size:16px">Rs. <?= csr_money($c_amt) ?></div></div>
    </section>

    <?php if (!$rows): ?>
        <div class="card empty"><strong>No cheques found.</strong><p>Try other received dates or clear the Scan / Verified filters.</p></div>
    <?php else: ?>
    <div class="card wrap">
    <table>
        <thead><tr>
            <th>Received</th><th>Cheque No</th><th>Cheque Date</th><th>Customer</th><th>Bank</th><th class="num">Amount</th>
            <th>Status</th><th>Scan</th><th>Verified</th><th>Scans</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $id = (int)$r['id'];
            $f = trim((string)$r['cheque_front_image']); $b = trim((string)$r['cheque_back_image']);
            $both = ($f !== '' && $b !== ''); $any = ($f !== '' || $b !== '');
            $isV = (int)$r['verified'] === 1; ?>
            <tr data-id="<?= $id ?>">
                <td><?= csr_h(csr_date($r['received_date'])) ?></td>
                <td><span class="strong"><?= csr_h($r['cheque_no']) ?></span><?php if ((int)$r['is_sampath']): ?><br><span class="badge samp">Sampath</span><?php endif; ?></td>
                <td><?= csr_h(csr_date($r['cheque_date'])) ?></td>
                <td><?= csr_h($r['customer_name'] ?: '—') ?><?php if ($r['t_code']): ?><br><span class="muted"><?= csr_h($r['t_code']) ?><?= $r['sr_code'] ? ' · ' . csr_h($r['sr_code']) : '' ?></span><?php endif; ?></td>
                <td><?= csr_h(trim($r['bank_code'] . ' ' . $r['bank_name'])) ?></td>
                <td class="num">Rs. <?= csr_money($r['amount']) ?></td>
                <td><span class="badge st"><?= csr_h(str_replace('_', ' ', (string)$r['status'])) ?></span></td>
                <td class="c-scan"><?= $both ? '<span class="badge up">Uploaded</span>' : ($any ? '<span class="badge part">' . ($f !== '' ? 'Front only' : 'Back only') . '</span>' : '<span class="badge notup">Not uploaded</span>') ?></td>
                <td class="c-ver"><?= $isV ? '<span class="badge ver">Verified</span>' : '<span class="badge notver">Not verified</span>' ?></td>
                <td>
                    <div class="thumbs">
                        <?php foreach (['front' => $f, 'back' => $b] as $side => $p): ?>
                        <div class="thumb" data-side="<?= $side ?>">
                            <div class="im"<?= $p !== '' ? ' onclick="csrLb(this.querySelector(\'img\').src)"' : '' ?>><?= $p !== '' ? '<img src="' . csr_h($p) . '" loading="lazy" alt="">' : '<i class="fa-regular fa-image"></i>' ?></div>
                            <?= ucfirst($side) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </td>
                <td>
                    <div class="actions">
                        <div class="arow">
                            <label class="abtn<?= $can_edit ? '' : ' off' ?>">Front<input type="file" accept="image/*" style="display:none" <?= $can_edit ? '' : 'disabled' ?> onchange="csrUpload(<?= $id ?>,'front',this)"></label>
                            <label class="abtn<?= $can_edit ? '' : ' off' ?>">Back<input type="file" accept="image/*" style="display:none" <?= $can_edit ? '' : 'disabled' ?> onchange="csrUpload(<?= $id ?>,'back',this)"></label>
                        </div>
                        <div class="arow">
                            <button class="abtn v" type="button" <?= $can_edit ? '' : 'disabled title="Permission blocked"' ?> onclick="csrVerify(<?= $id ?>,1)">Verified</button>
                            <button class="abtn nv" type="button" <?= $can_edit ? '' : 'disabled title="Permission blocked"' ?> onclick="csrVerify(<?= $id ?>,0)">Not verified</button>
                        </div>
                        <span class="stt" aria-live="polite"></span>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted"><?= number_format($c_total) ?> cheques · Total Rs. <?= csr_money($c_amt) ?><?= $c_total >= 5000 ? ' · showing the first 5,000 — narrow the dates' : '' ?></p>
    <?php endif; ?>

    <div class="lightbox" id="csrLb" onclick="if(event.target===this)csrLbClose()">
        <button class="x" onclick="csrLbClose()">&times;</button><img id="csrLbImg" src="" alt="Scan">
    </div>
</div>

<script>
function csrLb(src){ document.getElementById('csrLbImg').src=src; document.getElementById('csrLb').classList.add('open'); }
function csrLbClose(){ document.getElementById('csrLb').classList.remove('open'); document.getElementById('csrLbImg').src=''; }
document.addEventListener('keydown', function(e){ if(e.key==='Escape') csrLbClose(); });
function csrRow(id){ return document.querySelector('tr[data-id="'+id+'"]'); }
function csrMsg(row, cls, text){
    var s=row.querySelector('.stt'); s.className='stt '+cls; s.textContent=text;
    if(cls==='ok'){ clearTimeout(s._t); s._t=setTimeout(function(){ s.textContent=''; },2500); }
}
function csrBump(id, v){ var e=document.getElementById(id); if(e) e.textContent=(parseInt(e.textContent.replace(/,/g,''))+v).toLocaleString(); }
function csrPost(fd){
    return fetch('cheque_scan_report.php',{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(r){ return r.json().catch(function(){ return null; }); });
}
function csrUpload(id, side, input){
    var f=input.files[0]; if(!f) return;
    var row=csrRow(id), fd=new FormData();
    fd.append('action','update_scan_image'); fd.append('cheque_id',id); fd.append('side',side); fd.append('image',f);
    csrMsg(row,'','Uploading…');
    csrPost(fd).then(function(res){
        input.value='';
        if(!res||!res.success){ csrMsg(row,'err',(res&&res.error)||'Upload failed.'); return; }
        var im=row.querySelector('.thumb[data-side="'+side+'"] .im');
        im.innerHTML='<img src="'+res.file_path+'" alt="">'; im.onclick=function(){ csrLb(res.file_path); };
        var n=row.querySelectorAll('.thumb .im img').length;
        var wasNone=row.querySelector('.c-scan .notup')!==null;
        row.querySelector('.c-scan').innerHTML = n>=2 ? '<span class="badge up">Uploaded</span>' : '<span class="badge part">'+(side==='front'?'Front only':'Back only')+'</span>';
        if(wasNone){ csrBump('kUp',1); csrBump('kNo',-1); }
        csrMsg(row,'ok','Uploaded');
    }).catch(function(){ input.value=''; csrMsg(row,'err','Connection problem — try again.'); });
}
function csrVerify(id, val){
    var row=csrRow(id), fd=new FormData();
    fd.append('action','update_verify'); fd.append('cheque_id',id); fd.append('value',val);
    csrMsg(row,'','Saving…');
    csrPost(fd).then(function(res){
        if(!res||!res.success){ csrMsg(row,'err',(res&&res.error)||'Could not save.'); return; }
        var was=row.querySelector('.c-ver .ver')!==null;
        row.querySelector('.c-ver').innerHTML = val ? '<span class="badge ver">Verified</span>' : '<span class="badge notver">Not verified</span>';
        if(val&&!was){ csrBump('kVer',1); csrBump('kNver',-1); } if(!val&&was){ csrBump('kVer',-1); csrBump('kNver',1); }
        csrMsg(row,'ok',val?'Marked verified':'Marked not verified');
    }).catch(function(){ csrMsg(row,'err','Connection problem — try again.'); });
}
</script>

<?php include 'footer.php'; ?>
