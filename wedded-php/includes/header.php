<?php
/**
 * Expects (optional) $page_title, $page_description, $solid_header (bool) to be set before include.
 */
$__site_name = setting('site_name', 'The Wedded');
$__logo = setting('logo', '');
$__logo_link = setting('logo_link', '/') ?: '/';
$__title = isset($page_title) ? $page_title . ' | ' . $__site_name : $__site_name . ' | Wedding Photography';
$__desc = $page_description ?? setting('meta_description', '');
$__solid = !empty($solid_header);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="description" content="<?= h($__desc) ?>">
  <meta name="robots" content="index, follow">
  <meta name="theme-color" content="#3E2A1E">
  <meta property="og:title" content="<?= h($__title) ?>">
  <meta property="og:description" content="<?= h($__desc) ?>">
  <meta property="og:type" content="website">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=Montserrat:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= h(base_url()) ?>/assets/css/style.css">
  <title><?= h($__title) ?></title>
</head>
<body>
<header class="site-header<?= $__solid ? ' solid' : '' ?>" id="header">
  <div class="container nav">
    <a class="logo" href="<?= h($__logo_link) ?>" aria-label="<?= h($__site_name) ?> home">
      <?php if ($__logo): ?>
        <img src="<?= h(image_url($__logo)) ?>" alt="<?= h($__site_name) ?>">
      <?php else: ?>
        <span class="logo-text serif"><?= h($__site_name) ?></span>
      <?php endif; ?>
    </a>
    <nav class="desktop-nav" aria-label="Primary navigation">
      <a href="<?= h(base_url()) ?>/index.php"<?= nav_active('index.php') ?>>Home</a>
      <a href="<?= h(base_url()) ?>/about.php"<?= nav_active('about.php') ?>>About</a>
      <a href="<?= h(base_url()) ?>/collection.php"<?= nav_active('collection.php') ?>>Collection</a>
      <a href="<?= h(base_url()) ?>/index.php#stories">Stories</a>
      <a href="<?= h(base_url()) ?>/contact.php"<?= nav_active('contact.php') ?>>Contact</a>
      <a class="btn light header-cta" href="<?= h(base_url()) ?>/contact.php">Check Your Date</a>
    </nav>
    <button class="menu-toggle" id="menuToggle" aria-label="Open menu" aria-expanded="false">
      <span></span><span></span>
    </button>
  </div>
</header>

<div class="mobile-menu" id="mobileMenu" aria-hidden="true">
  <nav aria-label="Mobile navigation">
    <a href="<?= h(base_url()) ?>/index.php">Home</a>
    <a href="<?= h(base_url()) ?>/about.php">About</a>
    <a href="<?= h(base_url()) ?>/collection.php">Collection</a>
    <a href="<?= h(base_url()) ?>/index.php#stories">Stories</a>
    <a href="<?= h(base_url()) ?>/contact.php">Contact</a>
    <a class="btn light" href="<?= h(base_url()) ?>/contact.php">Check Your Date</a>
  </nav>
</div>
<main>
