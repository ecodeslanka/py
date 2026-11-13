<?php
/**
 * activity_lib.php — Activity Log: who opened which page / did what, with time, IP and device.
 * al_request() is called from rbac_guard() on every request; al_event() records login / lock / mail events.
 */
if (!function_exists('al_ensure')) {

function al_ensure() {
    global $conn;
    static $done = false; if ($done) return; $done = true;
    /* the tables / columns only need checking once in a while, not on every single request */
    $__flag = rtrim(sys_get_temp_dir(), '/\\') . '/yms_al_ok_' . md5(__DIR__) . '.flag';
    if (is_file($__flag) && (time() - filemtime($__flag)) < 3600) return;
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
    @file_put_contents($__flag, date('c'));
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

/* ───────── meaningful description of a log row ("Deleted record #12 on Users", "Opened Credit Aging Report — from 01 Nov 2026, route R1") ───────── */
function al_page_label($page) {
    static $labels = null;
    if ($labels === null) {
        $labels = [];
        if (function_exists('rbac_menu_pages')) foreach (rbac_menu_pages() as $k => $info) $labels[strtolower($k)] = $info['label'] ?? '';
    }
    $p = strtolower((string)$page);
    if ($p === 'index') return 'Dashboard';
    if (!empty($labels[$p])) return $labels[$p];
    return ucwords(trim(str_replace(['_', '-'], ' ', $p)));
}
function al_nice_date($v) { $t = strtotime($v); return $t ? date('d M Y', $t) : $v; }
/** "date_from=2026-11-01 & route=R1" → parts */
function al_params($detail) {
    $o = [];
    foreach (explode(' & ', (string)$detail) as $kv) { if ($kv === '' || strpos($kv, '=') === false) continue; [$k, $v] = explode('=', $kv, 2); if ($v !== '') $o[trim($k)] = $v; }
    return $o;
}
function al_filters_text($pm) {
    $skip = '/^(id|ids|action|act|op|do|mode|task|ajax|page|pg|per_page|sort|dir|_|t|nocache|csrf|t_code|tc|code|customer|scheme_no|delete|del|remove)$|^(export|print)(_.*)?$/i';
    $names = ['date_from' => 'from', 'from' => 'from', 'date_to' => 'to', 'to' => 'to', 'as_at_date' => 'as at', 'as_at' => 'as at', 'delivery_date' => 'delivery date', 'search' => 'search', 'q' => 'search', 'route' => 'route', 'sr_code' => 'SR', 'sr' => 'SR', 'pay_mode' => 'pay mode', 'moc' => 'MOC', 'type' => 'type'];
    $o = [];
    foreach ($pm as $k => $v) {
        if (preg_match($skip, $k) || preg_match('/(^|_)id$/i', $k)) continue;
        $lbl = $names[strtolower($k)] ?? strtolower(str_replace('_', ' ', $k));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $v = al_nice_date($v);
        $o[] = ($lbl === 'search' ? 'search “' . $v . '”' : $lbl . ' ' . $v);
        if (count($o) >= 6) break;
    }
    return implode(', ', $o);
}
function al_describe($r) {
    $a = (string)($r['action'] ?? 'view'); $page = al_page_label($r['page'] ?? ''); $d = (string)($r['detail'] ?? ''); $pm = al_params($d);
    $id = ''; foreach ($pm as $k => $v) if ((preg_match('/(^|_)id$/i', $k) && !preg_match('/^(upload|import)_id$/i', $k)) || preg_match('/^(delete|del|remove)(_.*)?$/i', $k) && ctype_digit($v)) { $id = $v; break; }
    $subj = ''; foreach (['t_code', 'tc', 'code', 'customer', 'scheme_no'] as $k) if (isset($pm[$k])) { $subj = $pm[$k]; break; }
    $act = ''; foreach (['action', 'ajax', 'act', 'do', 'op', 'mode', 'task'] as $k) if (isset($pm[$k])) { $act = trim(str_replace('_', ' ', $pm[$k])); break; }
    $fil = al_filters_text($pm); $rec = $id !== '' ? ' (record #' . $id . ')' : '';
    if ($subj !== '') $act = trim($act . ' for ' . $subj);
    switch ($a) {
        case 'view':    return 'Opened ' . $page . ($act !== '' ? ' — ' . $act : '') . ($fil !== '' ? ' — ' . $fil : '');
        case 'create':  return 'Added a new record on ' . $page . ($act !== '' ? ' — ' . $act : '') . $rec;
        case 'save':    return 'Saved ' . ($id !== '' ? 'record #' . $id : 'a record') . ' on ' . $page . ($act !== '' ? ' — ' . $act : '');
        case 'edit':    return 'Edited ' . ($id !== '' ? 'record #' . $id : 'a record') . ' on ' . $page . ($act !== '' ? ' — ' . $act : '');
        case 'delete':  return 'Deleted ' . ($id !== '' ? 'record #' . $id : 'a record') . ' on ' . $page . ($act !== '' ? ' — ' . $act : '');
        case 'export':  return 'Exported ' . $page . ' to Excel' . ($fil !== '' ? ' — ' . $fil : '');
        case 'print':   return 'Printed ' . $page . ($fil !== '' ? ' — ' . $fil : '');
        case 'blocked': return 'Blocked — no permission to ' . (preg_match('/tried to (\w+)/', $d, $m) ? $m[1] : 'use') . ' on ' . $page;
        case 'login':   return 'Signed in' . ($d !== '' ? ' (' . $d . ')' : '');
        case 'logout':  return 'Signed out';
        case 'login_failed':  return 'Failed sign-in — ' . ($d !== '' ? $d : 'wrong password');
        case 'login_blocked': return 'Sign-in refused — the account is locked';
        case 'user_locked':   return 'Locked a user account' . (preg_match('/locked (.+)$/', $d, $m) ? ': ' . $m[1] : '');
        case 'user_unlocked': return 'Unlocked a user account' . (preg_match('/unlocked (.+)$/', $d, $m) ? ': ' . $m[1] : '');
        case 'user_auto_locked': return 'Account locked automatically — ' . $d;
        case 'password_reset_sent':   return 'Password reset e-mail sent';
        case 'password_reset_failed': return 'Password reset e-mail could not be sent — ' . $d;
        case 'password_reset_done':   return 'Password changed with the e-mailed link';
        case 'email_settings_saved':  return 'Saved the e-mail (SMTP) settings — ' . $d;
    }
    return ucfirst(str_replace('_', ' ', $a)) . ' on ' . $page . ($d !== '' ? ' — ' . $d : '');
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
