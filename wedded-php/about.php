<?php
require_once __DIR__ . '/includes/bootstrap.php';
$page_title = 'About Us';
$solid_header = true;
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="container">
    <div class="eyebrow reveal">About <?= h(setting('site_name')) ?></div>
    <h1 class="reveal">Our Story</h1>
  </div>
</section>

<section class="section about" style="background:var(--ivory)">
  <div class="container about-grid">
    <div class="about-image reveal">
      <?php if (setting('about_image')): ?>
        <img src="<?= h(image_url(setting('about_image'))) ?>" alt="<?= h(setting('site_name')) ?>" loading="lazy">
      <?php else: ?>
        <div class="placeholder">Add an about image in the admin panel</div>
      <?php endif; ?>
    </div>
    <div class="about-copy reveal">
      <div class="eyebrow">Who we are</div>
      <h2><?= h(setting('about_title')) ?></h2>
      <p><?= nl2br(h(setting('about_body'))) ?></p>
      <a class="btn" href="<?= h(base_url()) ?>/collection.php">View Our Work</a>
    </div>
  </div>
</section>

<section class="section services">
  <div class="container">
    <div class="section-head reveal">
      <div><div class="eyebrow">What we capture</div><h2>Our Services</h2></div>
      <p>Every wedding is different — here's the full range of moments we love to document.</p>
    </div>
    <div class="service-grid">
      <?php foreach (get_categories() as $i => $cat): ?>
        <a class="service reveal" href="<?= h(base_url()) ?>/collection.php?category=<?= h($cat['slug']) ?>">
          <span class="service-no"><?= str_pad($i + 1, 2, '0', STR_PAD_LEFT) ?> —</span>
          <h3><?= h($cat['name']) ?></h3>
          <p><?= h($cat['description']) ?></p>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section contact">
  <div class="container contact-grid" style="align-items:center">
    <div class="contact-copy reveal">
      <div class="eyebrow">Let's talk</div>
      <h2>Ready to begin?</h2>
      <p>Tell us your wedding date and where it's happening — we'll get back to you with availability.</p>
      <div class="hero-actions" style="margin-top:30px">
        <a class="btn light" href="<?= h(base_url()) ?>/contact.php">Enquire Now</a>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
