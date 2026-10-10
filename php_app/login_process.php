<?php
// login_process.php – Handle login form submission
include 'config.php';
include 'auth.php';

// Already logged in → go to dashboard
if (isLoggedIn()) {
    header('Location: index.php');
    exit();
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit();
}

// ============================================================
// Constants
// ============================================================
define('MAX_FAILED_ATTEMPTS', 5);       // lock after 5 wrong passwords
define('LOCKOUT_MINUTES',     15);      // locked for 15 minutes
define('REMEMBER_DAYS',       30);

// ============================================================
// Helper – parse User-Agent into browser / OS / device type
// ============================================================
function parseUserAgent($ua) {
    $browser    = 'Unknown Browser';
    $os         = 'Unknown OS';
    $deviceType = 'desktop';

    // ---- Device type ----
    if (preg_match('/Mobile|Android|iPhone|iPod|BlackBerry|IEMobile|Opera Mini/i', $ua)) {
        $deviceType = 'mobile';
    } elseif (preg_match('/iPad|Tablet/i', $ua)) {
        $deviceType = 'tablet';
    }

    // ---- Operating System ----
    if      (preg_match('/Windows NT 10/i', $ua))  $os = 'Windows 10/11';
    elseif  (preg_match('/Windows NT 6\.3/i', $ua)) $os = 'Windows 8.1';
    elseif  (preg_match('/Windows NT 6\.1/i', $ua)) $os = 'Windows 7';
    elseif  (preg_match('/Windows/i', $ua))         $os = 'Windows';
    elseif  (preg_match('/Mac OS X ([\d_]+)/i', $ua, $m)) {
        $os = 'macOS ' . str_replace('_', '.', $m[1]);
    }
    elseif  (preg_match('/Android ([\d\.]+)/i', $ua, $m)) $os = 'Android ' . $m[1];
    elseif  (preg_match('/CPU iPhone OS ([\d_]+)/i', $ua, $m)) {
        $os = 'iOS ' . str_replace('_', '.', $m[1]);
    }
    elseif  (preg_match('/iPad.*OS ([\d_]+)/i', $ua, $m)) {
        $os = 'iPadOS ' . str_replace('_', '.', $m[1]);
    }
    elseif  (preg_match('/Linux/i', $ua))           $os = 'Linux';

    // ---- Browser (order matters – Edge/OPR before Chrome) ----
    if      (preg_match('/Edg\/([\d\.]+)/i', $ua, $m))     $browser = 'Edge '    . $m[1];
    elseif  (preg_match('/OPR\/([\d\.]+)/i', $ua, $m))     $browser = 'Opera '   . $m[1];
    elseif  (preg_match('/CriOS\/([\d\.]+)/i', $ua, $m))   $browser = 'Chrome '  . $m[1] . ' (iOS)';
    elseif  (preg_match('/FxiOS\/([\d\.]+)/i', $ua, $m))   $browser = 'Firefox ' . $m[1] . ' (iOS)';
    elseif  (preg_match('/Chrome\/([\d\.]+)/i', $ua, $m))  $browser = 'Chrome '  . $m[1];
    elseif  (preg_match('/Firefox\/([\d\.]+)/i', $ua, $m)) $browser = 'Firefox ' . $m[1];
    elseif  (preg_match('/Safari\/([\d\.]+)/i', $ua, $m))  $browser = 'Safari '  . $m[1];
    elseif  (preg_match('/MSIE ([\d\.]+)/i', $ua, $m))     $browser = 'IE '      . $m[1];
    elseif  (preg_match('/Trident.*rv:([\d\.]+)/i', $ua, $m)) $browser = 'IE '   . $m[1];

    return compact('browser', 'os', 'deviceType');
}

// ============================================================
// Helper – get real client IP (handles proxies / IPv6)
// ============================================================
function getRealIp() {
    $candidates = [
        'HTTP_CF_CONNECTING_IP',   // Cloudflare
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
    ];

    foreach ($candidates as $key) {
        if (!empty($_SERVER[$key])) {
            // X-Forwarded-For can be comma-separated; take the first
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

// ============================================================
// Collect request data
// ============================================================
$username   = trim($_POST['username'] ?? '');
$password   = $_POST['password']   ?? '';
$remember   = isset($_POST['remember']);
$email_hint = trim($_POST['email'] ?? '');   // optional mail field in form
$ip_address = getRealIp();
$ua         = $_SERVER['HTTP_USER_AGENT'] ?? '';
$uaParsed   = parseUserAgent($ua);
$session_id = session_id();

$error = '';

// ============================================================
// Basic validation
// ============================================================
if (empty($username) || empty($password)) {
    $error = 'Please enter both username and password.';
} else {

    // ============================================================
    // Look up user
    // ============================================================
    $safe_username = mysqli_real_escape_string($conn, $username);

    $sql = "SELECT u.*, r.role_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.username = '$safe_username'
            LIMIT 1";

    $result = mysqli_query($conn, $sql);

    if ($result && mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);

        // ----------------------------------------------------------
        // Check account lockout
        // ----------------------------------------------------------
        if (!empty($user['locked_until']) &&
            strtotime($user['locked_until']) > time()) {

            $remaining = ceil((strtotime($user['locked_until']) - time()) / 60);
            $error = "Account locked after too many failed attempts. "
                   . "Try again in {$remaining} minute(s).";

        } elseif (!password_verify($password, $user['password'])) {

            // ----------------------------------------------------------
            // Wrong password – increment failed attempts
            // ----------------------------------------------------------
            $new_attempts = intval($user['failed_attempts']) + 1;
            $user_id      = intval($user['id']);

            if ($new_attempts >= MAX_FAILED_ATTEMPTS) {
                $locked_until = date('Y-m-d H:i:s',
                                     time() + (LOCKOUT_MINUTES * 60));
                mysqli_query($conn,
                    "UPDATE users
                     SET failed_attempts = $new_attempts,
                         locked_until    = '$locked_until'
                     WHERE id = $user_id");
                $error = "Too many failed attempts. Account locked for "
                       . LOCKOUT_MINUTES . " minutes.";
            } else {
                mysqli_query($conn,
                    "UPDATE users
                     SET failed_attempts = $new_attempts
                     WHERE id = $user_id");
                $left  = MAX_FAILED_ATTEMPTS - $new_attempts;
                $error = "Invalid username or password. "
                       . "{$left} attempt(s) remaining before lockout.";
            }

            // Log failed attempt
            $safe_ip = mysqli_real_escape_string($conn, $ip_address);
            mysqli_query($conn,
                "INSERT INTO login_attempts (username, ip_address, success)
                 VALUES ('$safe_username', '$safe_ip', 0)");

        // ----------------------------------------------------------
        // ✅ ROLE CHECK: block 'sampath' role from logging in
        // Users with role_name = 'sampath' OR no role assigned are handled:
        //   - role_name = 'sampath'  → BLOCKED
        //   - role_name = NULL/empty → ALLOWED (no role restriction)
        // ----------------------------------------------------------
        } elseif (!empty($user['role_name']) && strtolower($user['role_name']) === 'sampath') {

            $error = 'Access denied. You do not have permission to access this system.';

            // Log the denied attempt
            $safe_ip = mysqli_real_escape_string($conn, $ip_address);
            mysqli_query($conn,
                "INSERT INTO login_attempts (username, ip_address, success)
                 VALUES ('$safe_username', '$safe_ip', 0)");

        } elseif ($user['active'] != 1) {
            $error = 'Your account has been deactivated. '
                   . 'Please contact the administrator.';

        } else {
            // ==============================================================
            // SUCCESS
            // ==============================================================
            $user_id = intval($user['id']);

            // Reset failed attempts & lockout
            mysqli_query($conn,
                "UPDATE users
                 SET failed_attempts = 0, locked_until = NULL
                 WHERE id = $user_id");

            // Create PHP session
            setUserSession([
                'id'        => $user['id'],
                'username'  => $user['username'],
                'role_id'   => $user['role_id'],
                'role_name' => $user['role_name'] ?? 'User',
                'active'    => $user['active'],
            ]);
            $session_id = session_id(); // re-read after regenerate

            // ----------------------------------------------------------
            // Remember-me cookie + DB token
            // ----------------------------------------------------------
            if ($remember) {
                $token        = bin2hex(random_bytes(32));
                $hashed_token = hash('sha256', $token);
                $expires      = date('Y-m-d H:i:s',
                                     time() + (REMEMBER_DAYS * 24 * 3600));

                // Delete old tokens for this user (keep table clean)
                mysqli_query($conn,
                    "DELETE FROM remember_tokens WHERE user_id = $user_id");

                mysqli_query($conn,
                    "INSERT INTO remember_tokens (user_id, token, expires_at)
                     VALUES ($user_id, '$hashed_token', '$expires')");

                setcookie('remember_token', $token,
                          time() + (REMEMBER_DAYS * 24 * 3600),
                          '/', '', true, true);   // Secure + HttpOnly
            }

            // ----------------------------------------------------------
            // Log successful login with rich device/browser/IP info
            // ----------------------------------------------------------
            $safe_ip      = mysqli_real_escape_string($conn, $ip_address);
            $safe_ua      = mysqli_real_escape_string($conn, $ua);
            $safe_browser = mysqli_real_escape_string($conn, $uaParsed['browser']);
            $safe_os      = mysqli_real_escape_string($conn, $uaParsed['os']);
            $safe_device  = mysqli_real_escape_string($conn, $uaParsed['deviceType']);
            $safe_email   = mysqli_real_escape_string($conn, $email_hint);
            $safe_sid     = mysqli_real_escape_string($conn, $session_id);

            mysqli_query($conn,
                "INSERT INTO login_logs
                    (user_id, ip_address, user_agent,
                     browser, os, device_type,
                     email, session_id)
                 VALUES
                    ($user_id, '$safe_ip', '$safe_ua',
                     '$safe_browser', '$safe_os', '$safe_device',
                     '$safe_email', '$safe_sid')");

            // Log success in login_attempts too
            mysqli_query($conn,
                "INSERT INTO login_attempts (username, ip_address, success)
                 VALUES ('$safe_username', '$safe_ip', 1)");

            header('Location: index.php');
            exit();
        }

    } else {
        // Username not found – still generic message to avoid enumeration
        $error = 'Invalid username or password.';

        $safe_ip = mysqli_real_escape_string($conn, $ip_address);
        mysqli_query($conn,
            "INSERT INTO login_attempts (username, ip_address, success)
             VALUES ('$safe_username', '$safe_ip', 0)");
    }
}

// Redirect back with error
$_SESSION['login_error'] = $error;
header('Location: login.php');
exit();
?>