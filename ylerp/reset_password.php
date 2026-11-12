<?php
// reset_password.php — opened from the e-mailed link; sets a new password and unlocks the account
include 'config.php';
include 'auth.php';
require_once __DIR__ . '/pw_reset_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
pr_ensure();

$token = $_POST['token'] ?? ($_GET['token'] ?? '');
$u = pr_find_valid($token);
$err = ''; $done = false;

if ($u && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = $_POST['password'] ?? ''; $p2 = $_POST['password2'] ?? '';
    if (strlen($p1) < 8)                         $err = 'The password must be at least 8 characters.';
    elseif (!preg_match('/[A-Za-z]/', $p1) || !preg_match('/\d/', $p1)) $err = 'Use at least one letter and one number.';
    elseif ($p1 !== $p2)                         $err = 'The two passwords do not match.';
    else {
        $uid = (int)$u['id'];
        mysqli_query($conn, "UPDATE users SET password = '" . mysqli_real_escape_string($conn, password_hash($p1, PASSWORD_DEFAULT)) . "', failed_attempts = 0, locked_until = NULL, lock_reason = NULL WHERE id = $uid");
        mysqli_query($conn, "UPDATE password_resets SET used_at = NOW() WHERE user_id = $uid AND used_at IS NULL");
        @mysqli_query($conn, "DELETE FROM remember_tokens WHERE user_id = $uid");
        al_write($uid, $u['username'], '', 'reset_password', 'password_reset_done', 'password changed with the e-mailed link');
        $done = true;
    }
}
if ($done) {
    pr_page('Password changed', '<h1>Password changed</h1><div class="al ok"><i class="fa-solid fa-circle-check"></i> Your password was updated and the account is unlocked. You can sign in now.</div><a class="btn" style="display:block;text-align:center;text-decoration:none" href="login.php">Go to login</a>');
} elseif (!$u) {
    pr_page('Link expired', '<h1>Link not valid</h1><div class="al er"><i class="fa-solid fa-circle-exclamation"></i> This reset link has expired or was already used.</div><a class="btn" style="display:block;text-align:center;text-decoration:none" href="forgot_password.php">Request a new link</a>');
} else {
    pr_page('Set new password', '<h1>Set a new password</h1><p class="sub">Account: <b>' . htmlspecialchars($u['username']) . '</b></p>'
        . ($err ? '<div class="al er"><i class="fa-solid fa-circle-exclamation"></i> ' . htmlspecialchars($err) . '</div>' : '')
        . '<form method="post" autocomplete="off"><input type="hidden" name="token" value="' . htmlspecialchars($token) . '">
           <label>New password</label><div class="pw"><input class="in" type="password" name="password" id="p1" minlength="8" required autofocus><button type="button" onclick="t(\'p1\',this)"><i class="fa-solid fa-eye"></i></button></div>
           <label>Retype new password</label><div class="pw"><input class="in" type="password" name="password2" id="p2" minlength="8" required><button type="button" onclick="t(\'p2\',this)"><i class="fa-solid fa-eye"></i></button></div>
           <button class="btn" type="submit"><i class="fa-solid fa-key"></i> Change password</button></form>
           <script>function t(i,b){var e=document.getElementById(i);e.type=e.type==="password"?"text":"password";b.firstChild.className=e.type==="password"?"fa-solid fa-eye":"fa-solid fa-eye-slash"}</script>');
}
