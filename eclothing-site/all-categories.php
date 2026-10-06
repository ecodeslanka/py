<?php
/**
 * all-categories.php — All Categories  (URL: /all-categories, mapped by .htaccess)
 * A dedicated directory page listing every active category with its full image and
 * its sub-categories shown underneath (unlike the homepage grid, which only shows
 * top-level boxes per Admin -> Categories -> Homepage Categories selection). Useful
 * as a full "browse everything" page linked from the top menu / footer.
 */
require_once __DIR__ . '/config/config.php';

$allCategories = get_category_tree(true); // every active top-level category + its full sub-tree

$pageTitle = 'All Categories — ' . site_display_name();

require __DIR__ . '/includes/header.php';
?>

<nav class="item-breadcrumb wrap">
  <a href="<?= BASE_URL ?>/">Home</a> <span>/</span> <span class="current">All Categories</span>
</nav>

<section class="allcat-hero">
  <div class="wrap">
    <h1>All Categories</h1>
    <p>Browse everything we sell, organized by category.</p>
  </div>
</section>

<section class="wrap allcat-wrap">
  <?php if ($allCategories): ?>
  <div class="allcat-grid">
    <?php foreach ($allCategories as $topCat): $__kids = $topCat['children'] ?? []; ?>
    <div class="allcat-block">
      <a href="<?= BASE_URL ?>/category/<?= e($topCat['slug']) ?>" class="allcat-card">
        <?php if (!empty($topCat['image'])): ?>
          <img class="thumb" src="<?= BASE_URL . e($topCat['image']) ?>" alt="<?= e($topCat['name']) ?>">
        <?php else: ?>
          <span class="thumb-placeholder"><?= $topCat['icon'] ? e($topCat['icon']) : '<i class="fa-solid fa-shirt"></i>' ?></span>
        <?php endif; ?>
        <div class="allcat-label"><span class="dot"><i class="fa-solid fa-tag"></i></span><span><?= e($topCat['name']) ?></span></div>
      </a>

      <?php if ($__kids): ?>
      <div class="allcat-sub-list">
        <?php foreach ($__kids as $subCat): ?>
        <div class="allcat-sub-row">
          <a href="<?= BASE_URL ?>/category/<?= e($subCat['slug']) ?>" class="allcat-sub-pill">
            <?php if (!empty($subCat['image'])): ?>
              <img src="<?= BASE_URL . e($subCat['image']) ?>" alt="<?= e($subCat['name']) ?>">
            <?php else: ?>
              <span class="allcat-sub-ic"><?= $subCat['icon'] ? e($subCat['icon']) : '<i class="fa-solid fa-shirt"></i>' ?></span>
            <?php endif; ?>
            <span><?= e($subCat['name']) ?></span>
          </a>
          <?php $__subWaLink = build_whatsapp_link("Hi, I'm interested in {$topCat['name']} - {$subCat['name']}. Could you share more details?"); ?>
          <?php if ($__subWaLink): ?>
          <a class="allcat-sub-wa" href="<?= e($__subWaLink) ?>" target="_blank" rel="noopener" aria-label="Ask about <?= e($subCat['name']) ?> on WhatsApp" title="Ask on WhatsApp">
            <i class="fa-brands fa-whatsapp"></i>
          </a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="products-empty">
    <i class="fa-solid fa-folder-open"></i>
    <h3>No categories yet</h3>
    <p>Categories added in Admin &rarr; Categories will appear here.</p>
  </div>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
