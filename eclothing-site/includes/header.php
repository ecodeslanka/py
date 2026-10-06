<?php
/**
 * includes/header.php — shared site header (ECLOTHING bright fashion theme)
 * Scrolling announcement bar → sticky header (logo · category menu with
 * mega dropdowns · search / account / wishlist / bag) → off-canvas mobile menu.
 * Menu categories come from Admin → Categories (top level = menu tabs,
 * second level = dropdown columns, third level = links under each column).
 * Requires config/config.php to be loaded first. Optional: set $pageTitle before including.
 */
if (!defined('SITE_NAME')) { require_once __DIR__ . '/../config/config.php'; }
$topCategories     = get_menu_categories();   // used by the footer
$megaCategories    = get_category_tree(true); // every active category → menu
$drawerCategories  = get_drawer_categories(); // mobile off-canvas menu
$siteSettings      = get_site_settings();
$mainContact       = get_main_contact_number();
$__customer        = current_customer();
$__waNumber        = get_whatsapp_number();
$__brandName       = $siteSettings['company_name'] ?: SITE_NAME;
$__navBrands       = dewansa_home_brands();
$__navParts        = dewansa_parts_services();
$__self            = basename($_SERVER['PHP_SELF'] ?? '');
$__navCats         = array_slice($megaCategories, 0, 6);
$__announce        = [
    '<i class="fa-solid fa-truck-fast"></i> Free island-wide delivery on orders over Rs. 10,000',
    '<i class="fa-solid fa-arrows-rotate"></i> Easy 7-day size exchange',
    '<i class="fa-solid fa-money-bill-wave"></i> Cash on delivery available',
    '<i class="fa-solid fa-bolt"></i> New arrivals every week',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#330a10">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= e($__brandName) ?>">
<meta name="description" content="<?= e($__brandName) ?> — shop men's, women's and kids' clothing online in Sri Lanka. T-shirts, shirts, polos, denims, dresses and more, with easy size exchanges and island-wide delivery.">
<?php $__favicon = site_favicon_url(); if ($__favicon === '') { $__favicon = BASE_URL . '/assets/images/eclothing-mark.png'; } ?>
<link rel="apple-touch-icon" href="<?= e($__favicon) ?>">
<link rel="icon" href="<?= e($__favicon) ?>">
<title><?= e($pageTitle ?? site_title_text()) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700;800;900&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: time() ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/modern.css?v=<?= @filemtime(__DIR__ . '/../assets/css/modern.css') ?: time() ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/theme-eclothing.css?v=<?= @filemtime(__DIR__ . '/../assets/css/theme-eclothing.css') ?: time() ?>">
</head>
<body class="ec-body">

<!-- ======= ANNOUNCEMENT BAR (scrolling) ======= -->
<div class="ec-announce" role="region" aria-label="Store announcements">
  <div class="ec-announce-track">
    <?php for ($__r = 0; $__r < 2; $__r++): ?>
    <div class="ec-announce-group"<?= $__r ? ' aria-hidden="true"' : '' ?>>
      <?php foreach ($__announce as $__a): ?><span><?= $__a ?></span><?php endforeach; ?>
    </div>
    <?php endfor; ?>
  </div>
</div>

<!-- ======= HEADER ======= -->
<header class="ec-header" id="ecHeader">
  <div class="container ec-header-inner">
    <button type="button" class="ec-icon-btn ec-burger" id="navToggle" aria-label="Open menu"><i class="fa-solid fa-bars-staggered"></i></button>

    <a href="<?= BASE_URL ?>/" class="logo ec-logo" aria-label="<?= e($__brandName) ?> home">
      <img src="<?= e(dewansa_logo_url(false, true)) ?>" alt="<?= e($__brandName) ?>" class="logo-img">
    </a>

    <nav class="ec-nav" aria-label="Main menu">
      <a href="<?= BASE_URL ?>/" class="ec-nav-link<?= $__self === 'index.php' ? ' active' : '' ?>">Home</a>
      <a href="<?= BASE_URL ?>/products?sort=newest" class="ec-nav-link">New In</a>
      <?php foreach ($__navCats as $__mc): $__kids = $__mc['children'] ?? []; ?>
      <div class="ec-nav-item<?= $__kids ? ' has-mega' : '' ?>">
        <a href="<?= BASE_URL ?>/category/<?= e($__mc['slug']) ?>" class="ec-nav-link"><?= e($__mc['name']) ?><?php if ($__kids): ?> <i class="fa-solid fa-chevron-down ec-caret"></i><?php endif; ?></a>
        <?php if ($__kids): ?>
        <div class="ec-mega">
          <div class="container ec-mega-inner">
            <div class="ec-mega-cols">
              <?php foreach (array_slice($__kids, 0, 8) as $__sub): ?>
              <div class="ec-mega-col">
                <a class="ec-mega-head" href="<?= BASE_URL ?>/category/<?= e($__sub['slug']) ?>"><?= e($__sub['name']) ?></a>
                <?php foreach (array_slice($__sub['children'] ?? [], 0, 8) as $__ss): ?>
                <a href="<?= BASE_URL ?>/category/<?= e($__ss['slug']) ?>"><?= e($__ss['name']) ?></a>
                <?php endforeach; ?>
              </div>
              <?php endforeach; ?>
              <div class="ec-mega-col">
                <a class="ec-mega-head ec-mega-all" href="<?= BASE_URL ?>/category/<?= e($__mc['slug']) ?>">Shop all <?= e($__mc['name']) ?> <i class="fa-solid fa-arrow-right"></i></a>
              </div>
            </div>
            <a class="ec-mega-feature" href="<?= BASE_URL ?>/category/<?= e($__mc['slug']) ?>">
              <?php if (!empty($__mc['image'])): ?><img src="<?= BASE_URL . e($__mc['image']) ?>" alt="" loading="lazy"><?php else: ?><span class="ec-mega-feature-ph"><i class="fa-solid fa-shirt"></i></span><?php endif; ?>
              <span class="ec-mega-feature-txt"><b><?= e($__mc['name']) ?></b><small>Shop now <i class="fa-solid fa-arrow-right"></i></small></span>
            </a>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <a href="<?= BASE_URL ?>/#deals" class="ec-nav-link ec-nav-sale">Sale</a>
    </nav>

    <div class="ec-actions head-icons">
      <button type="button" class="ec-icon-btn item" id="ecSearchToggle" aria-label="Search" aria-expanded="false"><i class="fa-solid fa-magnifying-glass"></i></button>
      <a href="<?= BASE_URL ?>/<?= $__customer ? 'dashboard' : 'login' ?>" class="ec-icon-btn item ec-hide-sm" aria-label="Account"><i class="fa-<?= $__customer ? 'solid' : 'regular' ?> fa-user"></i></a>
      <a href="#" class="ec-icon-btn item ec-hide-sm" aria-label="Wishlist"><i class="fa-regular fa-heart"></i></a>
      <a href="#" class="ec-icon-btn item cart-open-trigger" aria-label="Shopping bag"><i class="fa-solid fa-bag-shopping"></i><span class="badge" id="cartCount">0</span></a>
    </div>
  </div>

  <div class="ec-search-panel" id="ecSearchPanel" hidden>
    <div class="container">
      <form class="ec-search" action="<?= BASE_URL ?>/products" method="get" id="headerSearchForm" role="search">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="q" placeholder="Search t-shirts, shirts, denims, dresses…" aria-label="Search products" id="ecSearchInput">
        <select id="headerCategorySelect" aria-label="Category">
          <option value="">All</option>
          <?php foreach ($megaCategories as $tc): ?>
            <option value="<?= e($tc['slug']) ?>"><?= e($tc['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit">Search</button>
      </form>
      <div class="ec-search-tags">
        <span>Popular:</span>
        <a href="<?= BASE_URL ?>/products?q=t-shirt">T-Shirts</a>
        <a href="<?= BASE_URL ?>/products?q=polo">Polos</a>
        <a href="<?= BASE_URL ?>/products?q=denim">Denims</a>
        <a href="<?= BASE_URL ?>/products?q=shirt">Shirts</a>
        <a href="<?= BASE_URL ?>/products?q=dress">Dresses</a>
      </div>
    </div>
  </div>
</header>

<!-- ======= MOBILE OFF-CANVAS MENU ======= -->
<div class="drawer-overlay" id="drawerOverlay"></div>
<aside class="mobile-drawer" id="mobileDrawer" aria-hidden="true">
  <div class="drawer-head">
    <a href="<?= BASE_URL ?>/" class="logo"><img src="<?= e(dewansa_logo_url(false, true)) ?>" alt="<?= e($__brandName) ?>" class="logo-img"></a>
    <button type="button" class="drawer-close" id="drawerClose" aria-label="Close menu"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <form class="ec-drawer-search" action="<?= BASE_URL ?>/products" method="get" role="search">
    <i class="fa-solid fa-magnifying-glass"></i><input type="text" name="q" placeholder="Search products…" aria-label="Search products">
  </form>
  <div class="drawer-cats">
    <a class="drawer-link" href="<?= BASE_URL ?>/products?sort=newest"><i class="fa-solid fa-star"></i> New In</a>
    <?php foreach ($drawerCategories as $cat): $__drawerKids = array_values(array_filter($cat['children'] ?? [], fn($k) => (int)($k['show_in_drawer'] ?? 1) === 1)); ?>
      <?php if ($__drawerKids): ?>
      <details class="drawer-item">
        <summary><?= e($cat['name']) ?><span class="chev"><i class="fa-solid fa-angle-down"></i></span></summary>
        <div class="drawer-sub">
          <a href="<?= BASE_URL ?>/category/<?= e($cat['slug']) ?>"><b>Shop all <?= e($cat['name']) ?></b></a>
          <?php foreach ($__drawerKids as $sub): ?>
            <?php if (!empty($sub['children'])): ?>
            <details class="drawer-item drawer-item-2">
              <summary><?= e($sub['name']) ?><span class="chev"><i class="fa-solid fa-angle-down"></i></span></summary>
              <div class="drawer-sub">
                <?php foreach ($sub['children'] as $ss): ?>
                <a href="<?= BASE_URL ?>/category/<?= e($ss['slug']) ?>"><?= e($ss['name']) ?></a>
                <?php endforeach; ?>
              </div>
            </details>
            <?php else: ?>
            <a href="<?= BASE_URL ?>/category/<?= e($sub['slug']) ?>"><?= e($sub['name']) ?></a>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </details>
      <?php else: ?>
      <a class="drawer-link" href="<?= BASE_URL ?>/category/<?= e($cat['slug']) ?>"><?= e($cat['name']) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
    <a class="drawer-link hot" href="<?= BASE_URL ?>/#deals"><i class="fa-solid fa-tags"></i> Sale</a>

    <?php if ($__navParts): ?>
    <p class="drawer-label">Collections</p>
    <div class="drawer-chips">
      <?php foreach ($__navParts as [$__t, $__d, $__ic, $__q]): ?><a href="<?= e($__q) ?>"><?= e($__t) ?></a><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <p class="drawer-label">Help</p>
    <a class="drawer-link" href="<?= BASE_URL ?>/<?= $__customer ? 'dashboard' : 'login' ?>"><i class="fa-regular fa-user"></i> <?= $__customer ? 'My Account' : 'Login / Register' ?></a>
    <a class="drawer-link" href="<?= BASE_URL ?>/track-order"><i class="fa-solid fa-location-crosshairs"></i> Track Order</a>
    <a class="drawer-link" href="<?= BASE_URL ?>/refund-policy"><i class="fa-solid fa-arrows-rotate"></i> Exchanges &amp; Returns</a>
    <a class="drawer-link" href="<?= BASE_URL ?>/contact-us"><i class="fa-solid fa-headset"></i> Contact Us</a>
    <?php if ($__waNumber): ?><a class="drawer-link" href="https://wa.me/<?= e($__waNumber) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> Chat on WhatsApp</a><?php endif; ?>
  </div>
</aside>

<script>
/* Drawer (hamburger / bottom-bar "Categories"), search panel, sticky header shadow */
(function(){
  var drawer = document.getElementById('mobileDrawer'),
      overlay = document.getElementById('drawerOverlay'),
      closeBtn = document.getElementById('drawerClose'),
      header = document.getElementById('ecHeader'),
      searchBtn = document.getElementById('ecSearchToggle'),
      searchPanel = document.getElementById('ecSearchPanel');
  function open(){ drawer.classList.add('open'); overlay.classList.add('show'); drawer.setAttribute('aria-hidden','false'); document.body.classList.add('no-scroll','drawer-lock'); }
  function close(){ drawer.classList.remove('open'); overlay.classList.remove('show'); drawer.setAttribute('aria-hidden','true'); document.body.classList.remove('no-scroll','drawer-lock'); }
  function toggleSearch(force){
    var show = typeof force === 'boolean' ? force : searchPanel.hidden;
    searchPanel.hidden = !show;
    searchBtn.setAttribute('aria-expanded', show ? 'true' : 'false');
    searchBtn.innerHTML = show ? '<i class="fa-solid fa-xmark"></i>' : '<i class="fa-solid fa-magnifying-glass"></i>';
    if (show) { var i = document.getElementById('ecSearchInput'); if (i) i.focus(); }
  }
  document.addEventListener('click', function(e){
    if (e.target.closest('#navToggle, #appNavCatBtn')) { open(); return; }
    if (e.target.closest('#ecSearchToggle')) { toggleSearch(); return; }
  });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { close(); toggleSearch(false); } });
  if (closeBtn) closeBtn.addEventListener('click', close);
  if (overlay) overlay.addEventListener('click', close);
  var onScroll = function(){ header.classList.toggle('is-scrolled', window.scrollY > 10); };
  window.addEventListener('scroll', onScroll, {passive:true}); onScroll();
})();
/* Brand search boxes: live-filter tiles that carry data-brand-name */
document.addEventListener('input', function(e){
  var inp = e.target.closest('[data-brand-filter]');
  if (!inp) return;
  var box = document.querySelector(inp.getAttribute('data-brand-filter'));
  if (!box) return;
  var q = inp.value.trim().toLowerCase(), shown = 0;
  box.querySelectorAll('[data-brand-name]').forEach(function(el){
    var ok = !q || el.getAttribute('data-brand-name').indexOf(q) !== -1;
    el.hidden = !ok; if (ok) shown++;
  });
  var empty = box.querySelector('.brand-filter-empty');
  if (empty) empty.hidden = shown > 0;
});
/* Category select in the search bar jumps straight to that category */
(function(){
  var form = document.getElementById('headerSearchForm'),
      sel  = document.getElementById('headerCategorySelect');
  if (!form || !sel) return;
  form.addEventListener('submit', function(e){
    var q = form.querySelector('input[name="q"]').value.trim();
    if (!q && sel.value) { e.preventDefault(); window.location.href = '<?= BASE_URL ?>/category/' + sel.value; return; }
    if (q && sel.value) {
      var h = document.createElement('input'); h.type = 'hidden'; h.name = 'category'; h.value = sel.value; form.appendChild(h);
    }
  });
})();
</script>
