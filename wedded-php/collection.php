<?php
require_once __DIR__ . '/includes/bootstrap.php';

$categories = get_categories();
$activeCat = isset($_GET['category']) ? trim($_GET['category']) : 'all';
$validSlugs = array_column($categories, 'slug');
if ($activeCat !== 'all' && !in_array($activeCat, $validSlugs, true)) {
    $activeCat = 'all';
}
$albums = get_collection_albums($activeCat);

$page_title = 'Collection';
$solid_header = true;
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="container">
    <div class="eyebrow reveal">Selected work</div>
    <h1 class="reveal">The Collection</h1>
  </div>
</section>

<section class="section collection">
  <div class="container">
    <div class="section-head reveal">
      <div><div class="eyebrow">Browse by category</div><h2>Albums</h2></div>
      <div class="filter" aria-label="Portfolio filters">
        <a href="<?= h(base_url()) ?>/collection.php" class="<?= $activeCat === 'all' ? 'active' : '' ?>">All</a>
        <?php foreach ($categories as $cat): ?>
          <a href="<?= h(base_url()) ?>/collection.php?category=<?= h($cat['slug']) ?>" class="<?= $activeCat === $cat['slug'] ? 'active' : '' ?>"><?= h($cat['name']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="gallery">
      <?php if ($albums): foreach ($albums as $album): ?>
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
        <div class="empty-state" style="grid-column:1/-1">
          <?= $activeCat === 'all' ? 'No albums published yet. Add some from the admin panel.' : 'No albums in this category yet.' ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
