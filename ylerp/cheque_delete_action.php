<?php
/**
 * cheque_delete_action.php — delete one cheque from the Cheque Register (View cheque → Delete)
 * POST: cheque_id, reason   →   JSON {success, message}
 * The full cheque row is copied to cheques_deleted first, and a 'deleted' line goes to cheque_logs.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
header('Content-Type: application/json');

function cdel_out($ok, $msg, $extra = []) { echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra)); exit; }
function cdel_table($conn, $t) { $r = @mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'"); return $r && mysqli_num_rows($r) > 0; }
function cdel_count($conn, $t, $where) { if (!cdel_table($conn, $t)) return 0; $r = @mysqli_query($conn, "SELECT COUNT(*) c FROM `$t` WHERE $where"); return ($r && $x = mysqli_fetch_assoc($r)) ? (int)$x['c'] : 0; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cdel_out(false, 'Use the Delete button on the cheque.');
if (empty($_SESSION['user_id']) && empty($_SESSION['username'])) cdel_out(false, 'Your login has ended. Log in again.');

/* permission: the role needs Delete on Cheque Register and the "Delete cheque" button */
if (file_exists(__DIR__ . '/rbac.php')) {
    require_once __DIR__ . '/rbac.php';
    if (function_exists('rbac_can') && !rbac_can('delete', 'cheques')) cdel_out(false, 'Permission blocked: your role has no Delete right on Cheque Register.');
    if (function_exists('rbac_btn_require')) rbac_btn_require('chq_delete', 'cheques', 'Delete cheque');
}

$cid    = intval($_POST['cheque_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
if (!$cid) cdel_out(false, 'No cheque selected.');
if ($reason === '') cdel_out(false, 'Give a reason for deleting the cheque.');

$r = mysqli_query($conn, "SELECT * FROM cheques WHERE id = $cid LIMIT 1");
if (!$r) cdel_out(false, 'Could not read the cheque: ' . mysqli_error($conn));
$chq = mysqli_fetch_assoc($r);
if (!$chq) cdel_out(false, 'This cheque is not there any more (maybe already deleted).');

/* any status can be deleted; settlement payments / return charges of the cheque are kept and noted in the log */
$links = [];
foreach (['cheque_settlement_payments' => 'settlement payments', 'cheque_sb_settlement_payments' => 'sent-back settlement payments', 'cheque_return_charges' => 'return charges'] as $t => $lbl) {
    $n = cdel_count($conn, $t, "cheque_id = $cid");
    if ($n) $links[] = "$n $lbl";
}
if ($links) $reason .= ' [status was ' . ($chq['status'] ?? '') . '; kept: ' . implode(', ', $links) . ']';

$user = $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? $_SESSION['name'] ?? (isset($_SESSION['user_id']) ? 'User #' . $_SESSION['user_id'] : 'system');
$u  = mysqli_real_escape_string($conn, $user);
$re = mysqli_real_escape_string($conn, $reason);
$js = mysqli_real_escape_string($conn, json_encode($chq, JSON_UNESCAPED_UNICODE));
$no = mysqli_real_escape_string($conn, (string)($chq['cheque_no'] ?? ''));
$am = (float)($chq['total_amount'] ?? $chq['amount'] ?? 0);

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheques_deleted (
    id INT AUTO_INCREMENT PRIMARY KEY, cheque_id INT NOT NULL, cheque_no VARCHAR(100), amount DECIMAL(15,2) DEFAULT 0,
    row_json LONGTEXT, reason TEXT, deleted_by VARCHAR(150), deleted_at DATETIME DEFAULT CURRENT_TIMESTAMP, KEY (cheque_id)
)");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
    id INT AUTO_INCREMENT PRIMARY KEY, cheque_id INT NOT NULL, action VARCHAR(50), old_value VARCHAR(255), new_value VARCHAR(255),
    note TEXT, created_by VARCHAR(150), created_at DATETIME DEFAULT CURRENT_TIMESTAMP, KEY (cheque_id)
)");

mysqli_begin_transaction($conn);
$ok = mysqli_query($conn, "INSERT INTO cheques_deleted (cheque_id, cheque_no, amount, row_json, reason, deleted_by) VALUES ($cid, '$no', $am, '$js', '$re', '$u')");
if ($ok) $ok = mysqli_query($conn, "DELETE FROM cheques WHERE id = $cid LIMIT 1");
if (!$ok) { $e = mysqli_error($conn); mysqli_rollback($conn); cdel_out(false, 'Could not delete the cheque: ' . $e); }
mysqli_commit($conn);

@mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id, action, old_value, new_value, note, created_by) VALUES ($cid, 'deleted', '" . mysqli_real_escape_string($conn, (string)($chq['status'] ?? '')) . "', 'deleted', 'Cheque $no deleted. Reason: $re', '$u')");
if (cdel_table($conn, 'cheque_fc_verify')) @mysqli_query($conn, "DELETE FROM cheque_fc_verify WHERE cheque_id = $cid");

cdel_out(true, 'Cheque ' . $chq['cheque_no'] . ' deleted. A copy is kept in cheques_deleted.', ['cheque_id' => $cid]);
