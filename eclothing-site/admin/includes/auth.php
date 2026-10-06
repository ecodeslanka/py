<?php
/**
 * admin/includes/auth.php — protects every admin page.
 * Include at the very top of each admin page (after config).
 */
require_once __DIR__ . '/../../config/config.php';

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_fingerprint'])) {
    redirect('/admin/login');
}

/* Bind session to browser fingerprint (mitigates session hijacking) */
$fingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|emax-salt');
if (!hash_equals($_SESSION['admin_fingerprint'], $fingerprint)) {
    $_SESSION = [];
    session_destroy();
    redirect('/admin/login');
}

/* Periodic session ID rotation */
if (!isset($_SESSION['regen_at']) || time() > $_SESSION['regen_at']) {
    session_regenerate_id(true);
    $_SESSION['regen_at'] = time() + 300; // every 5 min
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';
