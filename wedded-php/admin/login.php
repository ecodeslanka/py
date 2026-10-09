<?php
require_once __DIR__ . '/includes/auth.php';

if (admin_logged_in()) {
    redirect(base_url() . '/admin/index.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if (admin_attempt_login($u, $p)) {
        redirect(base_url() . '/admin/index.php');
    }
    $error = 'Incorrect username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Admin Login | <?= h(setting('site_name', 'The Wedded')) ?></title>
<link rel="stylesheet" href="<?= h(base_url()) ?>/admin/assets/admin.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <h1><?= h(setting('site_name', 'The Wedded')) ?></h1>
    <p>Admin Panel Login</p>
    <?php if ($error): ?><div class="admin-flash error"><?= h($error) ?></div><?php endif; ?>
    <form method="post" action="login.php">
      <?= csrf_field() ?>
      <label for="username">Username</label>
      <input type="text" id="username" name="username" required autofocus value="<?= h($_POST['username'] ?? '') ?>">
      <div style="margin-top:14px"></div>
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required>
      <button class="btn" type="submit">Log In</button>
    </form>
  </div>
</div>
</body>
</html>
