<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
date_default_timezone_set(TIMEZONE);
define('ROOT', dirname(__DIR__));

// base URL path (works in a sub-folder too)
$docroot = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
$root = str_replace('\\', '/', ROOT);
define('BASE', ($docroot && strpos($root, $docroot) === 0) ? substr($root, strlen($docroot)) : '');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
session_name('tlms');
session_start();
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function db(): PDO {
    static $pdo;
    if (!$pdo) {
        try {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        } catch (Throwable $e) {
            http_response_code(500);
            exit('Database connection failed. Check inc/config.php and run install.');
        }
    }
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function row(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $p = ''): string { return BASE . '/' . ltrim($p, '/'); }
function redirect(string $p): never { header('Location: ' . (preg_match('~^https?://~', $p) ? $p : url($p))); exit; }
function money($n): string { return 'Rs. ' . number_format((float)$n, 2); }

// ---- settings ----
function settings(?array $override = null): array {
    static $c;
    if ($override !== null) { $c = $override; return $c; }
    if ($c === null) {
        $c = [];
        try { foreach (rows('SELECT k,v FROM settings') as $r) $c[$r['k']] = $r['v']; } catch (Throwable $e) {}
    }
    return $c;
}
function setting(string $k, string $d = ''): string { $s = settings(); return isset($s[$k]) && $s[$k] !== '' ? (string)$s[$k] : $d; }
function set_setting(string $k, string $v): void {
    q('INSERT INTO settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$k, $v]);
    $c = settings(); $c[$k] = $v; settings($c);
}

// ---- flash + csrf ----
function flash(?string $msg = null, string $type = 'ok') {
    if ($msg !== null) { $_SESSION['flash'][] = [$type, $msg]; return; }
    $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f;
}
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(24)); }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf()) . '">'; }
function check_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf(), (string)($_POST['_csrf'] ?? ''))) {
        http_response_code(419); exit('Session expired. Go back, refresh and try again.');
    }
}
function is_post(): bool { if ($_SERVER['REQUEST_METHOD'] === 'POST') { check_csrf(); return true; } return false; }
function post(string $k, string $d = ''): string { return trim((string)($_POST[$k] ?? $d)); }

// ---- auth ----
function me(): ?array {
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) { $u = row('SELECT * FROM users WHERE id=? AND active=1', [$_SESSION['uid']]); if (!$u) unset($_SESSION['uid']); }
    }
    return $u;
}
function login_user(int $id): void { session_regenerate_id(true); $_SESSION['uid'] = $id; }
function require_login(): array { $u = me(); if (!$u) { flash('Please log in first.', 'err'); redirect('login'); } return $u; }
function require_admin(): array { $u = me(); if (!$u || $u['role'] !== 'admin') { redirect('login'); } return $u; }

// ---- mobile numbers (Sri Lanka) ----
function norm_mobile(string $m): ?string {      // returns 9 digits 7XXXXXXXX
    $m = preg_replace('/\D+/', '', $m);
    if (strlen($m) === 11 && str_starts_with($m, '94')) $m = substr($m, 2);
    elseif (strlen($m) === 10 && $m[0] === '0') $m = substr($m, 1);
    return preg_match('/^7\d{8}$/', $m) ? $m : null;
}
function show_mobile(string $m): string { return '0' . $m; }

// ---- encryption for stored links ----
function enc(string $plain): string {
    $key = hash('sha256', APP_KEY, true); $iv = random_bytes(16);
    $c = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    $mac = hash_hmac('sha256', $iv . $c, $key, true);
    return base64_encode($iv . $mac . $c);
}
function dec(?string $b): string {
    if (!$b) return '';
    $raw = base64_decode($b, true); if ($raw === false || strlen($raw) < 49) return '';
    $key = hash('sha256', APP_KEY, true);
    $iv = substr($raw, 0, 16); $mac = substr($raw, 16, 32); $c = substr($raw, 48);
    if (!hash_equals(hash_hmac('sha256', $iv . $c, $key, true), $mac)) return '';
    return (string)openssl_decrypt($c, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
}

// ---- uploads ----
function save_upload(string $field, string $dir, array $exts, int $maxMb = 10): ?array {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed (error ' . $f['error'] . ').');
    if ($f['size'] > $maxMb * 1048576) throw new RuntimeException("File too large (max {$maxMb}MB).");
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $exts, true)) throw new RuntimeException('File type not allowed. Allowed: ' . implode(', ', $exts));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) && !@getimagesize($f['tmp_name'])) throw new RuntimeException('Not a valid image.');
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    $path = ROOT . "/uploads/$dir"; if (!is_dir($path)) mkdir($path, 0755, true);
    if (!move_uploaded_file($f['tmp_name'], "$path/$name")) throw new RuntimeException('Could not save file.');
    return ['name' => $name, 'orig' => mb_substr(basename($f['name']), 0, 200)];
}
function del_upload(?string $dir, ?string $name): void { if ($name) @unlink(ROOT . "/uploads/$dir/" . basename($name)); }
function img(?string $n, string $fallback = 'assets/img/lab-bg.webp'): string { return $n ? url('uploads/images/' . $n) : url($fallback); }
const IMG_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

// ---- misc ----
function month_label(string $my): string { $t = DateTime::createFromFormat('Y-m-d', $my . '-01'); return $t ? $t->format('F Y') : $my; }
function youtube_embed(string $link): ?string {
    if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|embed/|live/|shorts/))([A-Za-z0-9_-]{11})~', $link, $m))
        return 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0&modestbranding=1';
    return null;
}
function lesson_students(int $lid): int { return (int)val('SELECT COUNT(*) FROM enrollments WHERE lesson_id=?', [$lid]); }
function is_enrolled(int $uid, int $lid): bool { return (bool)val('SELECT 1 FROM enrollments WHERE user_id=? AND lesson_id=?', [$uid, $lid]); }
function throttle_ok(string $mobile): bool {
    q('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 15 MINUTE)');
    return (int)val('SELECT COUNT(*) FROM login_attempts WHERE ip=? OR mobile=?', [$_SERVER['REMOTE_ADDR'] ?? '', $mobile]) < 8;
}
function fail_attempt(string $mobile): void { q('INSERT INTO login_attempts(ip,mobile) VALUES(?,?)', [$_SERVER['REMOTE_ADDR'] ?? '', $mobile]); }

// ---- auto-upgrade database when code is newer than schema ----
try {
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php' && (int)setting('schema_v', '1') < 2 && val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'")) {
        require __DIR__ . '/migrate.php'; run_migrations();
    }
} catch (Throwable $e) { /* not installed yet */ }
