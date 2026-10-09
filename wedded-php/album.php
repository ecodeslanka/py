<?php
require_once __DIR__ . '/includes/bootstrap.php';

$slug = trim($_GET['slug'] ?? '');
$album = $slug ? get_album_by_slug($slug) : null;

if (!$album) {
    http_response_code(404);
    include __DIR__ . '/404.php';
    exit;
}

$images = get_album_images($album['id']);

$page_title = $album['title'];
$page_description = $album['description'] ?: $page_title;
$solid_header = true;
include __DIR__ . '/includes/header.php';
?>

<section class="album-hero">
  <div class="container">
    <a class="album-back" href="<?= h(base_url()) ?>/collection.php">&larr; Back to Collection</a><br>
    <div class="eyebrow reveal"><?= h($album['category_name']) ?><?= $album['location'] ? ' · ' . h($album['location']) : '' ?></div>
    <h1 class="serif reveal"><?= h($album['title']) ?></h1>
    <?php if ($album['description']): ?><p class="reveal"><?= h($album['description']) ?></p><?php endif; ?>
  </div>
</section>

<section class="section" style="background:var(--ivory)">
  <div class="container">
    <?php if ($images): ?>
      <div class="album-grid">
        <?php foreach ($images as $img): ?>
          <figure class="reveal">
            <a href="#" data-lightbox="<?= h(image_url($img['image'])) ?>" data-alt="<?= h($img['caption'] ?: $album['title']) ?>">
              <img src="<?= h(image_url($img['image'])) ?>" alt="<?= h($img['caption'] ?: $album['title']) ?>" loading="lazy">
            </a>
          </figure>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state">No images added to this album yet.</div>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
