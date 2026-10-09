<?php
require_once __DIR__ . '/includes/bootstrap.php';

$slides = get_active_hero_slides();
$categories = get_categories();
$featured = get_featured_albums(3);
$collectionSample = get_collection_albums();
$collectionSample = array_slice($collectionSample, 0, 8);

$page_title = 'Home';
$solid_header = false;
include __DIR__ . '/includes/header.php';
?>

<section class="hero" id="home">
  <div class="hero-media">
    <?php if ($slides): ?>
      <?php foreach ($slides as $idx => $slide): ?>
        <img class="hero-slide" src="<?= h(image_url($slide['image'], 'https://images.unsplash.com/photo-1519741497674-611481863552?q=80&w=2400&auto=format&fit=crop')) ?>"
             alt="" <?= $idx === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
      <?php endforeach; ?>
    <?php else: ?>
      <img src="https://images.unsplash.com/photo-1519741497674-611481863552?q=80&w=2400&auto=format&fit=crop" alt="Wedding couple" fetchpriority="high">
    <?php endif; ?>
  </div>
  <div class="container hero-content reveal">
    <div class="eyebrow"><?= h(setting('site_name')) ?> · <?= h(setting('address')) ?></div>
    <h1><?= $slides ? $slides[0]['title'] : 'Your story,<br>beautifully remembered.' ?></h1>
    <p><?= h($slides ? $slides[0]['subtitle'] : setting('tagline')) ?></p>
    <div class="hero-actions">
      <a class="btn light" href="<?= h(base_url()) ?>/contact.php">Check Your Date</a>
      <a class="btn light" href="<?= h(base_url()) ?>/collection.php">View Our Work</a>
    </div>
  </div>
  <?php if (count($slides) > 1): ?>
    <div class="hero-dots" id="heroDots"></div>
  <?php endif; ?>
  <div class="hero-scroll">Scroll to explore</div>
</section>

<section class="intro">
  <div class="container intro-grid">
    <div class="eyebrow reveal">Wedding stories · 01</div>
    <div class="intro-copy reveal">
      <h2 class="serif">Stories of love,<br>beautifully told.</h2>
      <p><?= h(setting('site_name')) ?> is a premium wedding photography brand focused on capturing authentic emotions, elegant portraits, intimate moments and timeless wedding stories.</p>
      <a class="btn light" href="<?= h(base_url()) ?>/about.php">Discover <?= h(setting('site_name')) ?></a>
    </div>
  </div>
</section>

<section class="section services" id="services">
  <div class="container">
    <div class="section-head reveal">
      <div><div class="eyebrow">What we capture</div><h2>Our Services</h2></div>
      <p>From the quiet moments before the ceremony to the celebration at night, every frame is crafted to feel honest, refined and enduring.</p>
    </div>
    <div class="service-grid">
      <?php foreach ($categories as $i => $cat): ?>
        <a class="service reveal" href="<?= h(base_url()) ?>/collection.php?category=<?= h($cat['slug']) ?>">
          <span class="service-no"><?= str_pad($i + 1, 2, '0', STR_PAD_LEFT) ?> —</span>
          <h3><?= h($cat['name']) ?></h3>
          <p><?= h($cat['description']) ?></p>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section collection" id="collection">
  <div class="container">
    <div class="section-head reveal">
      <div><div class="eyebrow">Selected work</div><h2>The Collection</h2></div>
      <a class="btn dark" href="<?= h(base_url()) ?>/collection.php">View Full Collection</a>
    </div>
    <div class="gallery">
      <?php if ($collectionSample): foreach ($collectionSample as $album): ?>
        <article class="gallery-item reveal">
          <a href="<?= h(base_url()) ?>/album.php?slug=<?= h($album['slug']) ?>" style="display:block;height:100%">
            <?php if ($album['cover_image']): ?>
              <img src="<?= h(image_url($album['cover_image'])) ?>" alt="<?= h($album['title']) ?>" loading="lazy">
            <?php else: ?>
              <div class="placeholder">No cover image yet</div>
            <?php endif; ?>
            <div class="gallery-caption"><strong><?= h($album['title']) ?></strong><span><?= h($album['category_name']) ?></span></div>
          </a>
        </article>
      <?php endforeach; else: ?>
        <div class="empty-state" style="grid-column:1/-1">Albums will appear here once added from the admin panel.</div>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="section stories" id="stories">
  <div class="container">
    <div class="section-head reveal">
      <div><div class="eyebrow">Real stories, thoughtfully documented</div><h2>Featured Stories</h2></div>
      <p>A closer look at some of the celebrations we've had the honor of capturing.</p>
    </div>
    <div class="story-grid">
      <?php if ($featured): foreach ($featured as $album): ?>
        <a class="story-card reveal" href="<?= h(base_url()) ?>/album.php?slug=<?= h($album['slug']) ?>">
          <?php if ($album['cover_image']): ?>
            <img src="<?= h(image_url($album['cover_image'])) ?>" alt="<?= h($album['title']) ?>" loading="lazy">
          <?php else: ?>
            <div class="placeholder" style="aspect-ratio:4/5">No cover image yet</div>
          <?php endif; ?>
          <div class="story-card-info">
            <h3><?= h($album['title']) ?></h3>
            <p><?= h($album['location'] ?: $album['category_name']) ?></p>
          </div>
        </a>
      <?php endforeach; else: ?>
        <div class="empty-state" style="grid-column:1/-1">Mark an album as "Featured" in the admin panel to show it here.</div>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="section about" id="about" style="padding:100px 0">
  <div class="container about-grid">
    <div class="about-image reveal">
      <?php if (setting('about_image')): ?>
        <img src="<?= h(image_url(setting('about_image'))) ?>" alt="<?= h(setting('site_name')) ?>" loading="lazy">
      <?php else: ?>
        <div class="placeholder">Add an about image in the admin panel</div>
      <?php endif; ?>
    </div>
    <div class="about-copy reveal">
      <div class="eyebrow">About <?= h(setting('site_name')) ?></div>
      <h2><?= h(setting('about_title')) ?></h2>
      <p><?= nl2br(h(mb_substr(setting('about_body'), 0, 260))) ?>&hellip;</p>
      <a class="btn" href="<?= h(base_url()) ?>/about.php">Our Story</a>
    </div>
  </div>
</section>

<section class="section contact" id="contact">
  <div class="container contact-grid" style="align-items:center">
    <div class="contact-copy reveal">
      <div class="eyebrow">Start your story</div>
      <h2>Let's create something timeless.</h2>
      <p>Planning your wedding? We'd love to hear your story, learn about your plans and see how we can be part of your day.</p>
      <div class="hero-actions" style="margin-top:30px">
        <a class="btn light" href="<?= h(base_url()) ?>/contact.php">Enquire Now</a>
        <?php if (setting('whatsapp')): ?>
          <a class="btn light" href="<?= h(whatsapp_link(setting('whatsapp'))) ?>" target="_blank" rel="noopener noreferrer">WhatsApp Us</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
