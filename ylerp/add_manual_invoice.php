<?php
/**
 * add_manual_invoice.php — add an invoice by hand to a field summary (Edit Field Summary → "Add Invoice Manually").
 * The invoice becomes a normal field_summary_details row (payments, credit, credit notes, reports work the same),
 * marked is_manual = 1 with who / when.
 *   GET  ?lookup=T-CODE            → customer name / route of a T-Code
 *   GET  ?check=INV&fsid=ID        → is this invoice number already used (this or another field summary)?
 *   POST field_summary_id, invoice_num, t_code, customer_name, net_value, ikea_value, remarks
 */
ob_start();
include 'config.php';
ob_end_clean();
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
if (session_status() === PHP_SESSION_NONE) @session_start();
header('Content-Type: application/json; charset=utf-8');

function mi_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function mi_e($conn, $v) { return mysqli_real_escape_string($conn, (string)$v); }
function mi_out($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function mi_ensure($conn) {
    foreach (['is_manual' => "TINYINT(1) NOT NULL DEFAULT 0", 'manual_by' => "VARCHAR(100) NULL", 'manual_at' => "DATETIME NULL", 'manual_remarks' => "VARCHAR(255) NULL"] as $c => $d) {
        $r = mi_q($conn, "SHOW COLUMNS FROM field_summary_details LIKE '$c'");
        if ($r && mysqli_num_rows($r) === 0) mi_q($conn, "ALTER TABLE field_summary_details ADD COLUMN `$c` $d");
    }
}
function mi_user() { return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'system'; }
/* the same invoice number in field summaries (any) */
function mi_used($conn, $inv) {
    $o = []; $r = mi_q($conn, "SELECT d.id, d.field_summary_id, fs.field_summary_code, fs.delivery_date FROM field_summary_details d LEFT JOIN field_summary fs ON fs.id = d.field_summary_id
                               WHERE TRIM(d.invoice_num) = '" . mi_e($conn, trim($inv)) . "' LIMIT 5");
    while ($r && ($x = mysqli_fetch_assoc($r))) $o[] = $x;
    return $o;
}

if (isset($_GET['lookup'])) {
    $t = trim((string)$_GET['lookup']); if ($t === '') mi_out(['ok' => false]);
    $r = mi_q($conn, "SELECT t_code, shop_name, COALESCE(route,'') AS route FROM customers WHERE t_code = '" . mi_e($conn, $t) . "' LIMIT 1");
    if (!$r) $r = mi_q($conn, "SELECT t_code, shop_name, '' AS route FROM customers WHERE t_code = '" . mi_e($conn, $t) . "' LIMIT 1");
    $x = $r ? mysqli_fetch_assoc($r) : null;
    mi_out($x ? ['ok' => true, 't_code' => $x['t_code'], 'name' => $x['shop_name'], 'route' => $x['route']] : ['ok' => false, 'msg' => 'T-Code not found in customers']);
}
if (isset($_GET['check'])) {
    $u = mi_used($conn, (string)$_GET['check']);
    mi_out(['ok' => true, 'used' => array_map(function ($x) { return ['fsid' => (int)$x['field_summary_id'], 'code' => $x['field_summary_code'], 'date' => $x['delivery_date']]; }, $u)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') mi_out(['ok' => false, 'msg' => 'Invalid request.']);
if (function_exists('rbac_can') && !rbac_can('edit', 'field_summary_list')) mi_out(['ok' => false, 'msg' => 'Permission blocked: your role cannot edit field summaries.']);

$fsid = (int)($_POST['field_summary_id'] ?? 0);
$inv  = trim((string)($_POST['invoice_num'] ?? ''));
$tc   = trim((string)($_POST['t_code'] ?? ''));
$cust = trim((string)($_POST['customer_name'] ?? ''));
$net  = round((float)str_replace(',', '', (string)($_POST['net_value'] ?? 0)), 2);
$ikRaw = trim((string)($_POST['ikea_value'] ?? ''));
$ikea = $ikRaw === '' ? $net : round((float)str_replace(',', '', $ikRaw), 2);
$rem  = trim(mb_substr((string)($_POST['remarks'] ?? ''), 0, 255));

$r = mi_q($conn, "SELECT id, route FROM field_summary WHERE id = $fsid"); $fs = $r ? mysqli_fetch_assoc($r) : null;
if (!$fs) mi_out(['ok' => false, 'msg' => 'Field summary not found.']);
if (is_file(__DIR__ . '/fs_verify_lib.php')) {
    include_once __DIR__ . '/fs_verify_lib.php';
    if (function_exists('fsv_info') && fsv_info($conn, $fsid)) mi_out(['ok' => false, 'msg' => 'This field summary is Verified — undo the verify first.']);
}
if ($inv === '') mi_out(['ok' => false, 'msg' => 'Enter the invoice number.']);
if ($tc === '')  mi_out(['ok' => false, 'msg' => 'Enter the customer T-Code.']);
if ($net <= 0)   mi_out(['ok' => false, 'msg' => 'Enter the invoice amount.']);
foreach (mi_used($conn, $inv) as $u) {
    mi_out(['ok' => false, 'msg' => 'Invoice ' . $inv . ' is already in field summary ' . ($u['field_summary_code'] ?: ('#' . $u['field_summary_id'])) . ($u['delivery_date'] ? ' (' . $u['delivery_date'] . ')' : '') . '.']);
}
if ($cust === '') { $r = mi_q($conn, "SELECT shop_name FROM customers WHERE t_code = '" . mi_e($conn, $tc) . "' LIMIT 1"); if ($r && ($x = mysqli_fetch_row($r))) $cust = (string)$x[0]; }

mi_ensure($conn);
$by = mi_user(); $now = date('Y-m-d H:i:s');
$ok = mi_q($conn, "INSERT INTO field_summary_details
        (field_summary_id, invoice_num, t_code, customer_name, route,
         net_value, scheme_discount, promotion_discount, tot_dis, market_return, damage_adjustment, cancel_value,
         adjust_net_value, ikea_value, short_excess, payment_status, is_manual, manual_by, manual_at, manual_remarks)
    VALUES ($fsid, '" . mi_e($conn, $inv) . "', '" . mi_e($conn, $tc) . "', '" . mi_e($conn, $cust) . "', '" . mi_e($conn, $fs['route']) . "',
         $net, 0, 0, 0, 0, 0, 0,
         $net, $ikea, " . round($net - $ikea, 2) . ", 'Pending', 1, '" . mi_e($conn, $by) . "', '$now', " . ($rem !== '' ? "'" . mi_e($conn, $rem) . "'" : 'NULL') . ")");
if (!$ok) mi_out(['ok' => false, 'msg' => 'Could not save: ' . mysqli_error($conn)]);
$id = (int)mysqli_insert_id($conn);
mi_out(['ok' => true, 'id' => $id, 'msg' => 'Invoice ' . $inv . ' added to the field summary.']);
