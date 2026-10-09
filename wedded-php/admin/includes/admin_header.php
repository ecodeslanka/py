<?php
/** Expects $admin_page_title to be set. Requires auth already checked by caller. */
$__nav = [
    'index.php'        => ['Dashboard', 'dashboard'],
    'hero.php'         => ['Hero Slider', 'image'],
    'categories.php'   => ['Categories', 'grid'],
    'albums.php'       => ['Albums', 'album'],
    'inquiries.php'    => ['Inquiries', 'mail'],
    'settings.php'     => ['Settings', 'gear'],
];
$__current = basename($_SERVER['SCRIPT_NAME']);
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= h($admin_page_title ?? 'Admin') ?> | <?= h(setting('site_name', 'The Wedded')) ?> Admin</title>
<link rel="stylesheet" href="<?= h(base_url()) ?>/admin/assets/admin.css">
</head>
<body>
<div class="admin-shell">
  <button class="admin-menu-toggle" id="adminMenuToggle" aria-label="Open menu" aria-expanded="false">&#9776;</button>
  <aside class="admin-sidebar" id="adminSidebar">
    <div class="admin-brand">
      <?php $logo = setting('logo'); ?>
      <?php if ($logo): ?><img src="<?= h(image_url($logo)) ?>" alt="Logo"><?php endif; ?>
      <span><?= h(setting('site_name', 'The Wedded')) ?></span>
    </div>
    <nav class="admin-nav">
      <?php foreach ($__nav as $file => $meta): ?>
        <a href="<?= h($file) ?>" class="<?= $__current === $file ? 'active' : '' ?>"><?= h($meta[0]) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="admin-sidebar-foot">
      <a href="<?= h(base_url()) ?>/index.php" target="_blank">&larr; View site</a>
      <a href="logout.php">Log out (<?= h(admin_username()) ?>)</a>
    </div>
  </aside>
  <div class="admin-overlay" id="adminOverlay"></div>

  <main class="admin-main">
    <header class="admin-topbar">
      <h1><?= h($admin_page_title ?? 'Admin') ?></h1>
    </header>
    <div class="admin-content">
      <?php if ($flash): ?>
        <div class="admin-flash <?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
      <?php endif; ?>
