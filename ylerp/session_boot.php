<?php
/**
 * session_boot.php — keeps people signed in.
 * Before: PHP's default session (~24 min server clean-up, cookie gone when the browser closes) plus a
 *         30 minute idle check in auth.php logged users out while they were still working.
 * Now:    30-day sliding session. A user is signed out only when they press Logout (or an administrator
 *         locks / deactivates the account). Every request pushes the expiry forward again.
 * Safe to include many times; must run before session_start().
 */
if (!defined('YMS_SESSION_DAYS')) define('YMS_SESSION_DAYS', 30);

function yms_session_boot() {
    static $done = false;
    if ($done || PHP_SAPI === 'cli') return;
    $done = true;
    $life = YMS_SESSION_DAYS * 86400;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @ini_set('session.gc_maxlifetime', (string)$life);
        @ini_set('session.gc_probability', '1');
        @ini_set('session.gc_divisor', '100');
        @ini_set('session.use_strict_mode', '1');
        // NOTE: the session save folder is deliberately left at the server default. Some pages call session_start()
        // BEFORE they include config.php; a custom folder would make them read a different (empty) session = not logged in.
        @session_set_cookie_params(['lifetime' => $life, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        @session_start();
    }
    // slide the cookie forward on every request so an active (or idle-but-open) browser never expires
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        @setcookie(session_name(), session_id(), ['expires' => time() + $life, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    }
}
yms_session_boot();
