<?php
/**
 * admin/login.php — default admin login page
 * Security: PDO prepared statements, password_verify, CSRF token,
 * per-IP throttling, session regeneration, generic error messages.
 * Optional second step: email OTP (Admin → Admin Users → Login Security),
 * off by default.
 */
require_once __DIR__ . '/../config/config.php';

/* Already logged in? */
if (!empty($_SESSION['admin_id'])) {
    redirect('/admin/index');
}

$siteSettings = get_site_settings();
$error   = '';
$notice  = '';
$otpStep = !empty($_SESSION['admin_otp_pending_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($otpStep && $action === 'cancel_otp') {
        unset($_SESSION['admin_otp_pending_id']);
        redirect('/admin/login');
    }

    if ($otpStep && $action === 'resend_otp') {
        $adminId = (int)$_SESSION['admin_otp_pending_id'];
        $st = db()->prepare('SELECT email FROM admins WHERE id = ? LIMIT 1');
        $st->execute([$adminId]);
        $email = $st->fetchColumn();
        if ($email && generate_and_send_admin_otp($adminId, $email) !== null) {
            $notice = "A new code has been sent to $email.";
        } else {
            $wait = admin_otp_resend_cooldown_remaining($adminId);
            $error = $wait > 0
                ? "Please wait {$wait}s before requesting another code."
                : 'Please wait a moment before requesting another code.';
        }
    } elseif ($otpStep && $action === 'verify_otp') {
        $code    = trim((string)($_POST['otp_code'] ?? ''));
        $adminId = (int)$_SESSION['admin_otp_pending_id'];

        if (!preg_match('/^\d{6}$/', $code)) {
            $error = 'Enter the 6-digit code sent to your email.';
        } elseif (verify_admin_otp($adminId, $code)) {
            $st = db()->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
            $st->execute([$adminId]);
            $admin = $st->fetch();
            if ($admin && (int)$admin['is_active'] === 1) {
                unset($_SESSION['admin_otp_pending_id']);
                clear_attempts();
                session_regenerate_id(true);
                $_SESSION['admin_id']          = (int)$admin['id'];
                $_SESSION['admin_name']        = $admin['full_name'] ?: $admin['username'];
                $_SESSION['admin_fingerprint'] = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|emax-salt');
                $_SESSION['regen_at']          = time() + 300;
                db()->prepare('UPDATE admins SET last_login = NOW() WHERE id = ?')->execute([$admin['id']]);
                redirect('/admin/index');
            }
            $error = 'This account is no longer active.';
        } else {
            $error = 'That code is invalid or has expired.';
        }
    } elseif (!$otpStep) {
        /* ---------- Step 1: username + password ---------- */
        if (too_many_attempts()) {
            $error = 'Too many failed attempts. Please try again in ' . LOCKOUT_MINUTES . ' minutes.';
        } else {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');

            $ok = false;
            $admin = null;
            if ($username !== '' && $password !== '' && mb_strlen($username) <= 50) {
                $st = db()->prepare('SELECT id, username, email, full_name, password_hash, is_active
                                     FROM admins WHERE username = ? LIMIT 1');
                $st->execute([$username]);
                $admin = $st->fetch();

                /* password_verify runs even for unknown users (timing-safe) */
                $hash = $admin['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinva';
                if (password_verify($password, $hash) && $admin && (int)$admin['is_active'] === 1) {
                    $ok = true;
                }
            }

            if ($ok) {
                clear_attempts();

                if (!empty($siteSettings['admin_otp_enabled']) && !empty($admin['email'])) {
                    // 2FA is on — hold off on granting access until the emailed code is verified.
                    $_SESSION['admin_otp_pending_id'] = (int)$admin['id'];
                    generate_and_send_admin_otp((int)$admin['id'], $admin['email']);
                    redirect('/admin/login');
                }

                session_regenerate_id(true);                       // prevent session fixation
                $_SESSION['admin_id']          = (int)$admin['id'];
                $_SESSION['admin_name']        = $admin['full_name'] ?: $admin['username'];
                $_SESSION['admin_fingerprint'] = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|emax-salt');
                $_SESSION['regen_at']          = time() + 300;

                db()->prepare('UPDATE admins SET last_login = NOW() WHERE id = ?')
                    ->execute([$admin['id']]);

                redirect('/admin/index');
            }

            record_attempt($username);
            $error = 'Invalid username or password.';              // generic — no user enumeration
        }
    }

    $otpStep = !empty($_SESSION['admin_otp_pending_id']); // re-check in case it just changed above
}

$otpEmailMasked = '';
$otpResendRemaining = 0;
if ($otpStep) {
    $st = db()->prepare('SELECT email FROM admins WHERE id = ? LIMIT 1');
    $st->execute([(int)$_SESSION['admin_otp_pending_id']]);
    $rawEmail = (string)$st->fetchColumn();
    if ($rawEmail && strpos($rawEmail, '@') !== false) {
        [$local, $domain] = explode('@', $rawEmail, 2);
        $otpEmailMasked = mb_substr($local, 0, 2) . str_repeat('•', max(1, mb_strlen($local) - 2)) . '@' . $domain;
    }
    $otpResendRemaining = admin_otp_resend_cooldown_remaining((int)$_SESSION['admin_otp_pending_id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login — <?= e(site_display_name()) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800;900&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/admin.css">
</head>
<body class="login-body">
  <div class="login-card">
    <div class="login-logo">
    <?php $__loginSettings = get_site_settings(); $__loginLogo = $__loginSettings['site_logo'] ?? null; ?>
    <?php if ($__loginLogo): ?>
      <img src="<?= BASE_URL . e($__loginLogo) ?>" alt="<?= e($__loginSettings['company_name'] ?: SITE_NAME) ?>" style="width:200px;max-height:80px;object-fit:contain">
    <?php else: ?>
      <img src="<?= BASE_URL ?>/assets/images/logo.png" style="width:200px;">
    <?php endif; ?>
    </div>

    <?php if ($otpStep): ?>
      <h1>Enter Login Code</h1>
      <p class="login-sub">We've sent a 6-digit code to <?= e($otpEmailMasked ?: 'your email') ?>. It expires in 10 minutes.</p>

      <?php if ($error): ?><div class="alert alert-error">⚠ <?= e($error) ?></div><?php endif; ?>
      <?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>
      <p class="login-sub" style="font-size:13px">Code not arriving? Check your spam folder, or wait a moment and use Resend below.</p>

      <form method="post" action="" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify_otp">
        <label>Verification Code
          <input type="text" name="otp_code" inputmode="numeric" maxlength="6" placeholder="••••••" required autofocus>
        </label>
        <button type="submit" class="btn-primary">🔐 Verify &amp; Sign In</button>
      </form>
      <form method="post" action="" style="margin-top:10px" id="resendForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="resend_otp">
        <button type="submit" class="back-link" id="resendBtn" style="background:none;border:none;cursor:pointer;font:inherit">Resend Code</button>
      </form>
      <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel_otp">
        <button type="submit" class="back-link" style="background:none;border:none;cursor:pointer;font:inherit">← Use a different account</button>
      </form>

      <script>
      (function () {
        var remaining = <?= (int)$otpResendRemaining ?>;
        var btn = document.getElementById('resendBtn');
        if (!btn || remaining <= 0) return;
        var originalText = btn.textContent;
        btn.disabled = true;
        var timer = setInterval(function () {
          remaining--;
          if (remaining <= 0) {
            clearInterval(timer);
            btn.disabled = false;
            btn.textContent = originalText;
          } else {
            btn.textContent = 'Resend Code (' + remaining + 's)';
          }
        }, 1000);
        btn.textContent = 'Resend Code (' + remaining + 's)';
      })();
      </script>

    <?php else: ?>
      <h1>Sign in to Admin</h1>
      <p class="login-sub">Enter your credentials to manage the store.</p>

      <?php if ($error): ?>
        <div class="alert alert-error">⚠ <?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" action="" autocomplete="off">
        <?= csrf_field() ?>
        <label>Username
          <input type="text" name="username" required maxlength="50" autofocus autocomplete="username">
        </label>
        <label>Password
          <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button type="submit" class="btn-primary">🔐 Login</button>
      </form>

      <a class="back-link" href="<?= BASE_URL ?>/">← Back to store</a>
    <?php endif; ?>
  </div>
</body>
</html>
