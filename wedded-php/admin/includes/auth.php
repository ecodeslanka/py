<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

function admin_logged_in() {
    return !empty($_SESSION['admin_id']);
}

function require_admin_login() {
    if (!admin_logged_in()) {
        redirect(base_url() . '/admin/login.php');
    }
}

function admin_attempt_login($username, $password) {
    $stmt = db()->prepare("SELECT * FROM admin_users WHERE username = :u");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $user['id'];
        $_SESSION['admin_username'] = $user['username'];
        return true;
    }
    return false;
}

function admin_username() {
    return $_SESSION['admin_username'] ?? '';
}

function flash_set($msg, $type = 'success') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}
function flash_get() {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}
