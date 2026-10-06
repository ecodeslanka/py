<?php
/**
 * ============================================================
 *  ECLOTHING — Global Configuration  (config/config.php)
 *  Include this file first on every page.
 * ============================================================
 */

declare(strict_types=1);

/* ---------- Environment ---------- */
define('ENVIRONMENT', 'production');          // 'development' | 'production'

if (ENVIRONMENT === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');           // never leak errors to visitors
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

/* ---------- Site settings ---------- */
define('SITE_NAME', 'ECLOTHING');
define('BASE_URL',  '');                      // e.g. 'https://emax.lk' — leave '' for relative links

/* ---------- Database (MySQL via PDO) ---------- */
define('DB_HOST', 'localhost');
define('DB_NAME', 'ecodazeh_dewansa');
define('DB_USER', 'ecodazeh_yelo');                    // ⚠ use a limited-privilege user in production
define('DB_PASS', 'SupunKadu@123');
define('DB_CHARSET', 'utf8mb4');

/* ---------- Security constants ---------- */
define('MAX_LOGIN_ATTEMPTS', 5);              // per IP
define('LOCKOUT_MINUTES',    15);             // lockout window
define('SESSION_LIFETIME',   1800);           // 30 min idle timeout
define('SESSION_NAME',       'EMAXSESSID');

/* ---------- Hardened session ---------- */
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), // true when on HTTPS
        'httponly' => true,                   // JS cannot read the cookie
        'samesite' => 'Lax',                  // CSRF mitigation
    ]);
    ini_set('session.use_strict_mode', '1');  // reject uninitialised session IDs
    ini_set('session.use_only_cookies', '1');
    session_start();
}

/* Idle-timeout enforcement */
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > SESSION_LIFETIME) {
    $_SESSION = [];
    session_destroy();
    session_start();
}
$_SESSION['LAST_ACTIVITY'] = time();

/* ---------- Security headers ---------- */
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

/* ---------- PDO connection (singleton) ---------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,   // real prepared statements
            ]);
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Database connection error. Please try again later.');
        }
    }
    return $pdo;
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/store_content.php';
