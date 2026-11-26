<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
if ($u = me()) redirect($u['role'] === 'admin' ? 'admin/' : 'dashboard');
if (is_post()) {
    $mob = norm_mobile(post('mobile')); $pw = (string)($_POST['password'] ?? '');
    $key = $mob ?: preg_replace('/\W/', '', post('mobile'));
    if (!throttle_ok($key)) flash('Too many failed attempts. Try again in 15 minutes.', 'err');
    else {
        $usr = $mob ? row('SELECT * FROM users WHERE mobile=? AND active=1', [$mob]) : null;
        if ($usr && password_verify($pw, $usr['password_hash'])) {
            login_user((int)$usr['id']);
            if (password_needs_rehash($usr['password_hash'], PASSWORD_DEFAULT)) q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $usr['id']]);
            redirect($usr['role'] === 'admin' ? 'admin/' : 'dashboard');
        }
        fail_attempt($key); flash('Wrong mobile number or password.', 'err');
    }
}
head_html('Login'); site_nav(); ?>
<main class="band appmain"><div class="authwrap"><?php flash_html(); ?>
<div class="card"><h3><i data-lucide="log-in"></i>Login</h3>
<form method="post"><?= csrf_field() ?>
  <?php field('Mobile number', 'mobile', post('mobile'), ['req' => 1, 'type' => 'tel', 'attr' => 'inputmode="tel" placeholder="07XXXXXXXX" autocomplete="username"']); ?>
  <?php field('Password', 'password', '', ['req' => 1, 'type' => 'password', 'attr' => 'autocomplete="current-password"']); ?>
  <button class="btn btn-r btn-block">Login</button>
  <p class="muted small" style="text-align:center;margin-top:14px">New student? <a href="<?= url('register') ?>" style="color:var(--r);font-weight:700">Create account</a><br>Forgot password? Contact the teacher on WhatsApp.</p>
</form></div></div></main><?php foot_html();
