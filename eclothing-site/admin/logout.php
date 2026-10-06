<?php
/** admin/logout.php — destroys the session completely */
require_once __DIR__ . '/../config/config.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
redirect('/admin/login');
