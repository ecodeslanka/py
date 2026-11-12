<?php
// forgot_password.php — user types username or e-mail, a reset link is e-mailed
include 'config.php';
include 'auth.php';
require_once __DIR__ . '/pw_reset_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
pr_ensure();

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['fp_csrf'] ?? '', $_POST['csrf'] ?? '')) { $err = 'Session expired. Please try again.'; }
    else {
        $who = trim($_POST['who'] ?? '');
        // same answer whether or not the account exists, so nobody can probe for user names
        $msg = 'If that account has an e-mail address on file, a password reset link has been sent. It is valid for ' . max(10, (int)(ml_get_all()['reset_minutes'] ?: 60)) . ' minutes.';
        if ($who !== '') {
            $e = mysqli_real_escape_string($conn, $who);
            $r = mysqli_query($conn, "SELECT id, username, email, active, locked_until FROM users WHERE (username = '$e' OR email = '$e') LIMIT 1");
            $u = $r ? mysqli_fetch_assoc($r) : null;
            if ($u && $u['active'] && filter_var($u['email'], FILTER_VALIDATE_EMAIL) && !al_is_admin_lock($u['locked_until'])) {
                $recent = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM password_resets WHERE user_id = " . (int)$u['id'] . " AND created_at > (NOW() - INTERVAL 1 HOUR)"))[0];
                if ($recent < 3) {
                    $token = bin2hex(random_bytes(32));
                    $mins  = max(10, (int)(ml_get_all()['reset_minutes'] ?: 60));
                    mysqli_query($conn, "INSERT INTO password_resets (user_id, token_hash, expires_at, ip) VALUES (" . (int)$u['id'] . ",'" . hash('sha256', $token) . "', (NOW() + INTERVAL $mins MINUTE), '" . mysqli_real_escape_string($conn, al_ip()) . "')");
                    $link = ml_base_url() . 'reset_password.php?token=' . $token;
                    $body = '<p>Hello <b>' . htmlspecialchars($u['username']) . '</b>,</p><p>We received a request to reset your password. Click the button below to choose a new one. The link works for ' . $mins . ' minutes and only once.</p>'
                          . '<p style="margin:22px 0"><a href="' . htmlspecialchars($link) . '" style="background:#4f46e5;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600">Reset my password</a></p>'
                          . '<p style="font-size:12px;color:#6b7280">Or copy this link: ' . htmlspecialchars($link) . '</p><p style="font-size:12px;color:#6b7280">If you did not ask for this, ignore this e-mail — your password stays as it is.</p>';
                    $mailerr = '';
                    $sent = ml_send($u['email'], 'Reset your password', ml_layout('Password reset', $body), $mailerr);
                    al_write((int)$u['id'], $u['username'], '', 'forgot_password', $sent ? 'password_reset_sent' : 'password_reset_failed', $sent ? 'reset link e-mailed' : substr($mailerr, 0, 300));
                }
            } elseif ($u) {
                al_write((int)$u['id'], $u['username'], '', 'forgot_password', 'password_reset_failed', 'no e-mail on file, inactive or locked');
            }
        }
    }
}
$_SESSION['fp_csrf'] = bin2hex(random_bytes(16));
$inner = '<h1>Forgot password?</h1><p class="sub">Enter your username or e-mail address. We will e-mail you a link to set a new password.</p>'
    . ($err ? '<div class="al er"><i class="fa-solid fa-circle-exclamation"></i> ' . htmlspecialchars($err) . '</div>' : '')
    . ($msg ? '<div class="al ok"><i class="fa-solid fa-circle-check"></i> ' . htmlspecialchars($msg) . '</div>' : '')
    . '<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="' . $_SESSION['fp_csrf'] . '">
       <label for="who">Username or e-mail</label><input class="in" id="who" name="who" required autofocus>
       <button class="btn" type="submit"><i class="fa-solid fa-paper-plane"></i> Send reset link</button></form>
       <a class="back" href="login.php"><i class="fa-solid fa-arrow-left"></i> Back to login</a>';
pr_page('Forgot password', $inner);
