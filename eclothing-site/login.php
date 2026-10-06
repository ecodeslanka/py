<?php
/**
 * login.php — Customer sign-in page (email or mobile + password, or Google).
 */
require_once __DIR__ . '/config/config.php';
ensure_customer_tables();

if (!empty($_SESSION['customer_id'])) {
    redirect('/');
}

$siteSettings = get_site_settings();
$error        = '';
$oldLogin     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $oldLogin = trim((string)($_POST['login'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $mobileNormalized = normalize_lk_mobile($oldLogin);
    $st = db()->prepare('SELECT * FROM customers WHERE (email = ? OR mobile = ?) AND is_active = 1 LIMIT 1');
    $st->execute([$oldLogin, $mobileNormalized ?: '']);
    $customer = $st->fetch();

    $hash = $customer['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinva';
    if ($customer && $customer['password_hash'] && password_verify($password, $hash)) {
        session_regenerate_id(true);
        $_SESSION['customer_id']   = (int)$customer['id'];
        $_SESSION['customer_name'] = $customer['first_name'];
        db()->prepare('UPDATE customers SET last_login = NOW() WHERE id = ?')->execute([$customer['id']]);
        redirect('/dashboard');
    }
    $error = 'Invalid email/mobile or password.';
}

$pageTitle = 'Sign In';
require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/auth.css">

<div class="auth-wrap">
  <div class="auth-card">
    <h1>Sign in</h1>
    <p class="auth-sub">Welcome back! Sign in to continue shopping.</p>

    <?php if ($error): ?><div class="alert alert-error">⚠ <?= e($error) ?></div><?php endif; ?>
    <?php if ($msg = flash('customer_success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>

    <form method="post" action="" autocomplete="off">
      <?= csrf_field() ?>
      <div class="auth-field">
        <label for="login">Email or Mobile Number</label>
        <input type="text" id="login" name="login" required value="<?= e($oldLogin) ?>" autofocus>
      </div>
      <div class="auth-field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
      </div>
      <button type="submit" class="auth-submit">Sign In</button>
    </form>

    <?php if (!empty($siteSettings['enable_google_signin']) && !empty($siteSettings['google_client_id'])): ?>
    <div class="auth-divider">OR</div>
    <div class="google-btn-wrap">
      <div id="g_id_onload"
           data-client_id="<?= e($siteSettings['google_client_id']) ?>"
           data-callback="handleGoogleCredential"
           data-auto_prompt="false">
      </div>
      <div class="g_id_signin" data-type="standard" data-size="large" data-theme="outline"
           data-text="signin_with" data-shape="pill" data-logo_alignment="left"></div>
    </div>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script>
    function handleGoogleCredential(response) {
      fetch('<?= BASE_URL ?>/google-auth', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ credential: response.credential, csrf_token: '<?= e(csrf_token()) ?>' })
      }).then(r => r.json()).then(data => {
        if (data.success) { window.location.href = data.new_account ? '<?= BASE_URL ?>/profile?welcome=1' : '<?= BASE_URL ?>/dashboard'; }
        else { alert(data.message || 'Google sign-in failed. Please try again.'); }
      }).catch(() => alert('Google sign-in failed. Please try again.'));
    }
    </script>
    <?php endif; ?>

    <p class="auth-foot">Don't have an account? <a href="<?= BASE_URL ?>/register">Sign up</a></p>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
