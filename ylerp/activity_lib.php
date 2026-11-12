<?php
/**
 * activity_lib.php — Activity Log: who opened which page / did what, with time, IP and device.
 * al_request() is called from rbac_guard() on every request; al_event() records login / lock / mail events.
 */
if (!function_exists('al_ensure')) {

function al_ensure() {
    global $conn;
    static $done = false; if ($done) return; $done = true;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS activity_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL, username VARCHAR(100) NULL, role_name VARCHAR(100) NULL,
        page VARCHAR(120) NOT NULL DEFAULT '', action VARCHAR(30) NOT NULL DEFAULT 'view',
        method VARCHAR(8) NULL, detail VARCHAR(600) NULL, ip VARCHAR(45) NULL, device VARCHAR(160) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_al_time (created_at), INDEX idx_al_user (user_id, created_at), INDEX idx_al_page (page, created_at), INDEX idx_al_action (action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['email' => "VARCHAR(150) NULL", 'failed_attempts' => 'INT NOT NULL DEFAULT 0', 'locked_until' => 'DATETIME NULL', 'lock_reason' => 'VARCHAR(200) NULL'] as $c => $def) {
        $r = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '$c'");
        if ($r && !mysqli_num_rows($r)) @mysqli_query($conn, "ALTER TABLE users ADD COLUMN `$c` $def");
    }
}
function al_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k)
        if (!empty($_SERVER[$k])) { $ip = trim(explode(',', $_SERVER[$k])[0]); if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip; }
    return '';
}
function al_device() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $b = preg_match('/Edg\/[\d.]+/', $ua, $m) ? 'Edge' : (preg_match('/OPR\//', $ua) ? 'Opera' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Browser'))));
    $o = preg_match('/Windows/', $ua) ? 'Windows' : (preg_match('/Android/', $ua) ? 'Android' : (preg_match('/iPhone|iPad/', $ua) ? 'iOS' : (preg_match('/Mac OS/', $ua) ? 'macOS' : (preg_match('/Linux/', $ua) ? 'Linux' : ''))));
    return trim($b . ' · ' . $o . (preg_match('/Mobile|Android|iPhone/i', $ua) ? ' · mobile' : ''), ' ·');
}
/** keep the request readable but never store passwords / tokens */
function al_detail() {
    $skip = '/pass|pwd|token|csrf|secret|otp|pin/i';
    $out = [];
    foreach (($_GET + $_POST) as $k => $v) {
        if (!is_string($k) || preg_match($skip, $k)) continue;
        if (is_array($v)) $v = '[' . count($v) . ' items]';
        $v = (string)$v; if (strlen($v) > 60) $v = substr($v, 0, 57) . '...';
        $out[] = $k . '=' . $v;
        if (count($out) >= 12) break;
    }
    return substr(implode(' & ', $out), 0, 590);
}
function al_write($uid, $uname, $role, $page, $action, $detail = '') {
    global $conn;
    al_ensure();
    $f = fn($x) => mysqli_real_escape_string($conn, (string)$x);
    mysqli_query($conn, "INSERT INTO activity_log (user_id, username, role_name, page, action, method, detail, ip, device) VALUES ("
        . ($uid ? (int)$uid : 'NULL') . ",'" . $f($uname) . "','" . $f($role) . "','" . $f(substr($page, 0, 120)) . "','" . $f($action) . "','"
        . $f($_SERVER['REQUEST_METHOD'] ?? '') . "','" . $f(substr($detail, 0, 600)) . "','" . $f(al_ip()) . "','" . $f(substr(al_device(), 0, 160)) . "')");
    if (mt_rand(1, 400) === 1) @mysqli_query($conn, "DELETE FROM activity_log WHERE created_at < (NOW() - INTERVAL 180 DAY)");
}
/** login / logout / lock / unlock / password events */
function al_event($action, $detail = '', $uid = null, $uname = null) {
    if ($uid === null) { $uid = $_SESSION['user_id'] ?? null; $uname = $_SESSION['username'] ?? ''; }
    al_write($uid, $uname, $_SESSION['role_name'] ?? '', rbac_current_page(), $action, $detail);
}
/** called for every request of a signed-in user */
function al_request() {
    global $conn;
    if (empty($_SESSION['user_id']) || !isset($conn)) return;
    $page = rbac_current_page();
    if (in_array($page, ['online_users_api', 'heartbeat', 'ping', 'keepalive', 'notifications_api', 'activity_log'], true)) return;
    $act = rbac_detect_action();
    if ($act === 'create_or_edit') $act = 'save';
    $xrw = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    $isAjax = $xrw === 'xmlhttprequest' || (isset($_GET['ajax']) && $_GET['ajax'] !== '');
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($isAjax && $act === 'view' && !$isPost) return;                 // list / search calls are noise
    $req = array_keys($_GET + $_POST);
    if ($act === 'view' && preg_grep('/^(export|download|excel|csv|pdf)/i', array_map('strval', $req))) $act = 'export';
    elseif ($act === 'view' && preg_grep('/^print/i', array_map('strval', $req))) $act = 'print';
    al_write((int)$_SESSION['user_id'], $_SESSION['username'] ?? '', $_SESSION['role_name'] ?? '', $page, $act, al_detail());
}

/** Locked by an administrator (no automatic expiry) */
function al_is_admin_lock($locked_until) { return $locked_until && strtotime($locked_until) >= strtotime('2090-01-01'); }
define('AL_ADMIN_LOCK', '2099-12-31 23:59:59');

/** sign a user out at once when an administrator locked or deactivated the account */
function al_enforce_account() {
    global $conn;
    if (empty($_SESSION['user_id'])) return;
    $r = @mysqli_query($conn, "SELECT active, locked_until FROM users WHERE id = " . (int)$_SESSION['user_id'] . " LIMIT 1");
    $u = $r ? mysqli_fetch_assoc($r) : null;
    if (!$u) return;
    if (al_is_admin_lock($u['locked_until']) || (string)$u['active'] === '0') {
        $msg = al_is_admin_lock($u['locked_until']) ? 'Your account was locked by an administrator.' : 'Your account has been deactivated.';
        $_SESSION = []; @session_destroy(); @session_start(); $_SESSION['login_error'] = $msg;
        if (!headers_sent()) { if (function_exists('rbac_is_ajax') && rbac_is_ajax()) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => $msg]); } else header('Location: login.php'); }
        exit;
    }
}
}
