<?php
function head_html(string $title, string $body_class = ''): void {
    $s = setting('site_name', SITE_NAME);
    ?><!DOCTYPE html>
<html lang="en" data-mode="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#ff2a40">
<title><?= e($title) ?> · <?= e($s) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Noto+Sans+Sinhala:wght@500;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>?v=1">
<script>try{var m=localStorage.getItem('mode');if(m)document.documentElement.dataset.mode=m}catch(e){}</script>
<script src="https://unpkg.com/lucide@0.460.0/dist/umd/lucide.min.js" defer></script>
</head>
<body class="<?= e($body_class) ?>">
<?php }

function foot_html(): void { ?>
<script src="<?= url('assets/js/app.js') ?>?v=1" defer></script>
</body></html>
<?php }

function flash_html(): void {
    foreach (flash() as [$t, $m]) echo '<div class="alert ' . ($t === 'err' ? 'err' : 'ok') . '" role="alert">' . e($m) . '</div>';
}

function site_nav(): void {
    $u = me(); $s = setting('site_name', SITE_NAME); ?>
<nav class="topnav"><div class="wrap">
  <a class="logo" href="<?= url() ?>"><b>TW</b><span><?= e($s) ?><small>Genuine Chemistry</small></span></a>
  <div class="links">
    <a href="<?= url() ?>#home">Home</a><a href="<?= url() ?>#batches">Batches</a><a href="<?= url() ?>#about">About</a><a href="<?= url() ?>#contact">Contact</a>
    <?php if ($u): ?>
      <a href="<?= url($u['role'] === 'admin' ? 'admin/' : 'dashboard') ?>"><?= $u['role'] === 'admin' ? 'Admin panel' : 'My classes' ?></a>
    <?php endif; ?>
  </div>
  <button class="mode" id="mode" type="button" aria-label="Toggle dark mode"><i data-lucide="moon" class="sun"></i><i data-lucide="sun" class="moon"></i></button>
  <?php if ($u): ?><a class="btn btn-o nav-cta" href="<?= url('logout') ?>"><i data-lucide="log-out"></i>Logout</a>
  <?php else: ?><a class="btn btn-o nav-cta" href="<?= url('login') ?>"><i data-lucide="log-in"></i>Login</a><a class="btn btn-r nav-cta" href="<?= url('register') ?>"><i data-lucide="user-plus"></i>Join now</a><?php endif; ?>
</div></nav>
<?php }

function student_head(string $title, string $active = ''): array {
    $u = require_login();
    if ($u['role'] === 'admin') redirect('admin/');
    head_html($title, 'app'); site_nav(); ?>
<main class="band appmain"><div class="wrap">
<?php flash_html(); return $u; }
function student_foot(): void { ?></div></main><?php foot_html(); }

function admin_head(string $title, string $active = ''): array {
    $u = require_admin();
    head_html($title, 'adm');
    $nav = [
        'index' => ['', 'layout-dashboard', 'Dashboard'], 'batches' => ['batches', 'layers', 'Batches'],
        'grades' => ['grades', 'graduation-cap', 'Grades'], 'lessons' => ['lessons', 'book-open', 'Classes'],
        'payments' => ['payments', 'wallet', 'Payments'], 'students' => ['students', 'users', 'Students'],
        'users' => ['users', 'shield', 'Admin users'], 'settings' => ['settings', 'settings', 'Site settings'],
    ]; ?>
<div class="admshell">
<aside class="side" id="side">
  <a class="logo" href="<?= url('admin/') ?>"><b>TW</b><span>Admin<small><?= e(setting('site_name', SITE_NAME)) ?></small></span></a>
  <nav>
  <?php foreach ($nav as $k => [$p, $ic, $lb]):
    $pend = $k === 'payments' ? (int)val("SELECT COUNT(*) FROM payments WHERE status='pending'") : 0; ?>
    <a href="<?= url('admin/' . $p) ?>" class="<?= $active === $k ? 'on' : '' ?>"><i data-lucide="<?= $ic ?>"></i><?= $lb ?><?php if ($pend): ?><em><?= $pend ?></em><?php endif; ?></a>
  <?php endforeach; ?>
  </nav>
  <div class="sidefoot">
    <a href="<?= url() ?>" target="_blank"><i data-lucide="external-link"></i>View site</a>
    <a href="<?= url('logout') ?>"><i data-lucide="log-out"></i>Logout (<?= e($u['name']) ?>)</a>
    <button class="mode" id="mode" type="button" aria-label="Toggle dark mode"><i data-lucide="moon" class="sun"></i><i data-lucide="sun" class="moon"></i></button>
  </div>
</aside>
<div class="admmain">
  <header class="admtop"><button class="burger" id="burger" aria-label="Menu"><i data-lucide="menu"></i></button><h1><?= e($title) ?></h1></header>
  <div class="admbody"><?php flash_html(); return $u; }
function admin_foot(): void { ?></div></div></div><?php foot_html(); }

function field(string $label, string $name, $value = '', array $o = []): void {
    $type = $o['type'] ?? 'text'; $req = !empty($o['req']) ? 'required' : ''; $extra = $o['attr'] ?? '';
    echo '<label class="f">' . e($label) . (!empty($o['req']) ? ' <i class="rq">*</i>' : '') . '<span class="hint">' . e($o['hint'] ?? '') . '</span>';
    if ($type === 'textarea') echo '<textarea name="' . e($name) . '" rows="' . ($o['rows'] ?? 4) . '" ' . $req . ' ' . $extra . '>' . e($value) . '</textarea>';
    elseif ($type === 'select') {
        echo '<select name="' . e($name) . '" ' . $req . ' ' . $extra . '>';
        foreach ($o['options'] as $k => $v) echo '<option value="' . e($k) . '"' . ((string)$k === (string)$value ? ' selected' : '') . '>' . e($v) . '</option>';
        echo '</select>';
    } else echo '<input type="' . e($type) . '" name="' . e($name) . '" value="' . e($value) . '" ' . $req . ' ' . $extra . '>';
    echo '</label>';
}
