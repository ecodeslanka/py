<?php
/**
 * about-us.php — About Us  (URL: /about-us, mapped by .htaccess)
 * Renders the content managed in Admin -> About Us: heading, subheading,
 * banner image, rich-text content, and an optional image gallery.
 */
require_once __DIR__ . '/config/config.php';

$about       = get_about_us();
$aboutImages = get_about_us_images();
$siteSettings = get_site_settings();

$pageTitle = ($about['heading'] ?: 'About Us') . ' — ' . (site_display_name());

require __DIR__ . '/includes/header.php';
?>

<nav class="item-breadcrumb wrap">
  <a href="<?= BASE_URL ?>/">Home</a> <span>/</span> <span class="current">About Us</span>
</nav>

<section class="about-hero">
  <?php if (!empty($about['banner_image'])): ?>
    <img class="about-hero-bg" src="<?= BASE_URL . e($about['banner_image']) ?>" alt="<?= e($about['heading']) ?>">
    <div class="about-hero-overlay"></div>
  <?php endif; ?>
  <div class="wrap about-hero-inner <?= empty($about['banner_image']) ? 'no-banner' : '' ?>">
    <h1><?= e($about['heading'] ?: 'About Us') ?></h1>
    <?php if (!empty($about['subheading'])): ?>
      <p><?= e($about['subheading']) ?></p>
    <?php endif; ?>
  </div>
</section>

<section class="wrap about-content">
  <div class="about-content-inner">
    <?= $about['content'] ?? '' ?>
  </div>
</section>

<?php if (!empty($siteSettings['company_name']) || $mainContact || !empty($siteSettings['address'])): ?>
<section class="wrap about-highlights">
  <div class="about-highlight-grid">
    <div class="about-highlight-card">
      <i class="fa-solid fa-truck-fast"></i>
      <h4>Islandwide Delivery</h4>
      <p>Fast, reliable delivery to every corner of Sri Lanka.</p>
    </div>
    <div class="about-highlight-card">
      <i class="fa-solid fa-briefcase"></i>
      <h4>Cash on Delivery</h4>
      <p>Pay conveniently when your order arrives at your door.</p>
    </div>
    <div class="about-highlight-card">
      <i class="fa-solid fa-award"></i>
      <h4>Best in Quality</h4>
      <p>Premium products, carefully sourced and quality checked.</p>
    </div>
    <div class="about-highlight-card">
      <i class="fa-solid fa-shield-halved"></i>
      <h4>Trusted After Sales</h4>
      <p>We stand behind every product with dependable support.</p>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($aboutImages): ?>
<section class="wrap about-gallery">
  <h2>Gallery</h2>
  <div class="about-gallery-grid">
    <?php foreach ($aboutImages as $img): ?>
    <figure class="about-gallery-item">
      <img src="<?= BASE_URL . e($img['image']) ?>" alt="<?= e($img['caption'] ?: $about['heading']) ?>">
      <?php if ($img['caption']): ?><figcaption><?= e($img['caption']) ?></figcaption><?php endif; ?>
    </figure>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="about-cta">
  <div class="wrap about-cta-inner">
    <div>
      <h3>Ready to find your next piece?</h3>
      <p>Browse our full catalog or get in touch — we're happy to help.</p>
    </div>
    <div class="about-cta-actions">
      <a href="<?= BASE_URL ?>/products" class="btn btn-green"><i class="fa-solid fa-bag-shopping"></i> Shop Now</a>
      <a href="<?= BASE_URL ?>/contact-us" class="btn btn-navy">Contact Us</a>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
