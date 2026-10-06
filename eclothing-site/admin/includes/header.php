<?php
/**
 * admin/includes/header.php — control panel layout header + sidebar menu
 * Set $pageTitle and $active ('dashboard'|'categories'|'subcategories'|'subsub') before including.
 */
if (empty($_SESSION['admin_id'])) { redirect('/admin/login'); }
$active = $active ?? '';
$__adminSiteSettings = get_site_settings();
$__sidebarLogo = $__adminSiteSettings['footer_logo'] ?? $__adminSiteSettings['site_logo'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'Control Panel') ?> — <?= e(site_display_name()) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800;900&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/admin.css?v=<?= @filemtime(__DIR__ . '/../../assets/css/admin.css') ?: time() ?>">
</head>
<body>
<div class="admin-shell">

  <!-- ===== SIDEBAR MENU ===== -->
  <aside class="sidebar">
    <div class="login-logo">
      <?php if ($__sidebarLogo): ?>
        <img src="<?= BASE_URL . e($__sidebarLogo) ?>" alt="<?= e($__adminSiteSettings['company_name'] ?: SITE_NAME) ?>" style="width:150px;max-height:60px;object-fit:contain">
      <?php else: ?>
        <img src="<?= e(dewansa_logo_url(true)) ?>" alt="<?= e(site_display_name()) ?>" style="width:150px;">
      <?php endif; ?>
    </div>

    <div class="side-title">Main</div>
    <nav class="side-nav">
      <a href="<?= BASE_URL ?>/admin/index" class="<?= $active === 'dashboard' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-gauge-high"></i></span> Dashboard
      </a>
    </nav>

    <div class="side-title">Sales</div>
    <nav class="side-nav">
      <a href="<?= BASE_URL ?>/admin/orders" class="<?= $active === 'orders' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-receipt"></i></span> Orders
      </a>
    </nav>

    <div class="side-title">Catalog</div>
    <nav class="side-nav">
      <a href="<?= BASE_URL ?>/admin/categories" class="<?= $active === 'categories' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-folder-tree"></i></span> Categories
      </a>
      <a href="<?= BASE_URL ?>/admin/menu_categories" class="<?= $active === 'menu_categories' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-bars"></i></span> Menu Categories
      </a>
      <a href="<?= BASE_URL ?>/admin/items" class="<?= $active === 'items' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-tags"></i></span> Items
      </a>
      <a href="<?= BASE_URL ?>/admin/items_import" class="<?= $active === 'items_import' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-file-import"></i></span> Import Items
      </a>
      <a href="<?= BASE_URL ?>/admin/brands" class="<?= $active === 'brands' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-tag"></i></span> Brands
      </a>
   
      <a href="<?= BASE_URL ?>/admin/variations" class="<?= $active === 'variations' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-sliders"></i></span> Variation Options
      </a>
    </nav>

    <div class="side-title">Store</div>
    <nav class="side-nav">
      <a href="<?= BASE_URL ?>/" target="_blank"><span class="ico"><i class="fa-solid fa-store"></i></span> View Store</a>
      <a href="<?= BASE_URL ?>/admin/parts_services" class="<?= $active === 'parts_services' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-images"></i></span> Home Collections
      </a>
      <a href="<?= BASE_URL ?>/admin/home_slides" class="<?= $active === 'home_slides' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-images"></i></span> Home Slides
      </a>
      <a href="<?= BASE_URL ?>/admin/about_us" class="<?= $active === 'about_us' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-circle-info"></i></span> About Us
      </a>
      <a href="<?= BASE_URL ?>/admin/happy_customers" class="<?= $active === 'happy_customers' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-face-smile"></i></span> Happy Customers
      </a>
    </nav>

    <div class="side-title">Settings</div>
    <nav class="side-nav">
      <a href="<?= BASE_URL ?>/admin/site_configuration" class="<?= $active === 'site_configuration' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-gear"></i></span> Site Configuration
      </a>
      <a href="<?= BASE_URL ?>/admin/payment_settings" class="<?= $active === 'payment_settings' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-credit-card"></i></span> Payment Settings
      </a>
      <a href="<?= BASE_URL ?>/admin/email_settings" class="<?= $active === 'email_settings' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-envelope"></i></span> Email Settings
      </a>
      <a href="<?= BASE_URL ?>/admin/sms_settings" class="<?= $active === 'sms_settings' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-comment-sms"></i></span> SMS Settings
      </a>
      <a href="<?= BASE_URL ?>/admin/locations" class="<?= $active === 'locations' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-map-location-dot"></i></span> Locations
      </a>
      <a href="<?= BASE_URL ?>/admin/delivery_charges" class="<?= $active === 'delivery_charges' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-truck"></i></span> Delivery Charges
      </a>
      <a href="<?= BASE_URL ?>/admin/admin_users" class="<?= $active === 'admin_users' ? 'on' : '' ?>">
        <span class="ico"><i class="fa-solid fa-user-shield"></i></span> Admin Users
      </a>
    </nav>

    <div class="side-foot">
      Logged in as <a href="#"><?= e($adminName ?? 'Admin') ?></a>
    </div>
  </aside>

  <!-- ===== MAIN AREA ===== -->
  <div class="main-area">
    <div class="admin-topbar">
      <h2><?= e($pageTitle ?? 'Control Panel') ?></h2>
      <div class="admin-user">
        <a class="logout-btn" href="<?= BASE_URL ?>/" target="_blank" rel="noopener" style="background:#eef5ff;color:#141414" title="Open the website in a new tab"><i class="fa-solid fa-store"></i> View Website</a>
        <div class="avatar"><?= e(mb_strtoupper(mb_substr($adminName ?? 'A', 0, 1))) ?></div>
        <span><?= e($adminName ?? 'Admin') ?></span>
        <a class="logout-btn" href="<?= BASE_URL ?>/admin/logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
      </div>
    </div>
    <div class="content">
