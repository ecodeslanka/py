<?php
/**
 * index.php — Home page (ECLOTHING bright fashion theme)
 * Hero slider (Admin → Home Slides) → service strip → shop by category (category
 * images) → new arrivals → collection image tiles (Admin → Home Collections) →
 * sale → best sellers → exchange banner → brands → reviews → WhatsApp CTA.
 * Product cards show colour swatches + size availability from each item's
 * size/colour stock (Admin → Items → Variations).
 */
require_once __DIR__ . '/config/config.php';
$pageTitle = site_display_name() . " — Men's, Women's & Kids' Clothing Online in Sri Lanka";

$siteSettings   = get_site_settings();
$homeSlides     = get_home_slides();
$flashItems     = !empty($siteSettings['show_flash_sale'])  ? get_flash_sale_items(max(1, (int)($siteSettings['home_flash_sale_count'] ?? 8)))  : [];
$topItems       = !empty($siteSettings['show_top_selling']) ? get_top_selling_items(max(1, (int)($siteSettings['home_top_selling_count'] ?? 8))) : [];
$newItems       = get_new_arrival_items(8);
$happyCustomers = !empty($siteSettings['show_happy_customers']) ? get_happy_customers() : [];
$homeBrands     = (!isset($siteSettings['show_brands']) || !empty($siteSettings['show_brands'])) ? dewansa_home_brands() : [];
$collections    = dewansa_parts_services();
$promises       = dewansa_promises();
$waLink         = build_whatsapp_link("Hi ECLOTHING, I need help with sizes / an order.");

/* "Shop by category" = product types (2nd-level categories like T-Shirts, Shirts, Dresses).
   Falls back to the top-level categories when there are no sub-categories yet. */
$homeCategories = [];
if (!empty($siteSettings['show_categories'])) {
    $__limit = max(1, (int)($siteSettings['home_categories_count'] ?? 12));
    foreach (get_category_tree(true) as $__top) {
        foreach ($__top['children'] ?? [] as $__c) {
            if ((int)($__c['show_on_home'] ?? 1) !== 1) { continue; }
            $__c['parent_name'] = $__top['name'];
            $homeCategories[] = $__c;
        }
    }
    if (!$homeCategories) { $homeCategories = get_homepage_categories($__limit); }
    $homeCategories = array_slice($homeCategories, 0, $__limit);
}
/* hero collage (used only when no Home Slides are uploaded): newest product photos */
$heroShots = array_values(array_filter(array_column($newItems ?: $topItems, 'feature_image')));

require __DIR__ . '/includes/header.php';

/** Section heading helper. */
function ec_head(string $kicker, string $title, ?string $link = null, string $linkText = 'View all'): void
{
    ?>
    <div class="dx-head ec-head">
      <div>
        <span class="dx-kicker"><?= e($kicker) ?></span>
        <h2><?= e($title) ?></h2>
      </div>
      <?php if ($link): ?><a class="dx-link" href="<?= e($link) ?>"><?= e($linkText) ?> <i class="fa-solid fa-arrow-right"></i></a><?php endif; ?>
    </div>
    <?php
}
?>

<!-- ======= HERO ======= -->
<?php if ($homeSlides): ?>
<section class="ec-hero-slider hero" aria-label="Featured">
  <?php $__i = 0; foreach ($homeSlides as $sl): $__i++; ?>
  <div class="hero-slide <?= $__i === 1 ? 'active' : '' ?>">
    <?php if ($sl['image']): ?><img class="hero-bg" src="<?= BASE_URL . e($sl['image']) ?>" alt="<?= e($sl['heading'] ?? '') ?>"<?= $__i > 1 ? ' loading="lazy"' : '' ?>><?php endif; ?>
    <?php if (trim((string)($sl['heading'] ?? '')) !== '' || !empty($sl['button_text'])): ?>
    <div class="ec-slide-copy container">
      <div class="ec-slide-box">
        <?php if (!empty($sl['eyebrow'])): ?><span class="ec-eyebrow"><?= e($sl['eyebrow']) ?></span><?php endif; ?>
        <?php if (trim((string)($sl['heading'] ?? '')) !== ''): ?><h2><?= e($sl['heading']) ?></h2><?php endif; ?>
        <?php if (!empty($sl['description'])): ?><p><?= e($sl['description']) ?></p><?php endif; ?>
        <div class="ec-btn-row">
          <?php if (!empty($sl['button_text'])): ?><a class="ec-btn ec-btn-dark" href="<?= e($sl['button_link'] ?: BASE_URL . '/products') ?>"><?= e($sl['button_text']) ?></a><?php endif; ?>
          <?php if (!empty($sl['button2_text'])): ?><a class="ec-btn ec-btn-light" href="<?= e($sl['button2_link'] ?: BASE_URL . '/products') ?>"><?= e($sl['button2_text']) ?></a><?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if (count($homeSlides) > 1): ?>
  <button type="button" class="hero-arrow left" aria-label="Previous slide"><i class="fa-solid fa-chevron-left"></i></button>
  <button type="button" class="hero-arrow right" aria-label="Next slide"><i class="fa-solid fa-chevron-right"></i></button>
  <div class="hero-dots">
    <?php for ($i = 0; $i < count($homeSlides); $i++): ?><button class="<?= $i === 0 ? 'active' : '' ?>" aria-label="Slide <?= $i + 1 ?>"></button><?php endfor; ?>
  </div>
  <?php endif; ?>
</section>
<?php else: ?>
<section class="ec-hero">
  <div class="container ec-hero-inner">
    <div class="ec-hero-copy">
      <span class="ec-eyebrow">New season · <?= date('Y') ?></span>
      <h1>Everyday wear,<br><span>made to fit you.</span></h1>
      <p>Premium cotton tees, crisp shirts, polos, denims and dresses — in every size, in every colour. Easy 7-day size exchange and island-wide delivery.</p>
      <div class="ec-btn-row">
        <a href="<?= BASE_URL ?>/products?sort=newest" class="ec-btn ec-btn-dark">Shop new arrivals <i class="fa-solid fa-arrow-right"></i></a>
        <a href="<?= BASE_URL ?>/#deals" class="ec-btn ec-btn-light">View sale</a>
      </div>
      <ul class="ec-hero-stats">
        <li><b>XS–3XL</b><span>Size range</span></li>
        <li><b>7 days</b><span>Size exchange</span></li>
        <li><b>COD</b><span>Island-wide</span></li>
      </ul>
    </div>
    <div class="ec-hero-art" aria-hidden="true">
      <?php if (count($heroShots) >= 2): ?>
        <div class="ec-hero-collage">
          <?php foreach (array_slice($heroShots, 0, 3) as $__k => $__img): ?>
          <span class="ec-hero-shot ec-hero-shot-<?= $__k + 1 ?>"><img src="<?= BASE_URL . e($__img) ?>" alt=""></span>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="ec-hero-tees">
          <?php foreach ([['#b8963f', 'ec-tee-1'], ['#330a10', 'ec-tee-2'], ['#ffffff', 'ec-tee-3']] as [$__c, $__cls]): ?>
          <svg class="ec-tee <?= $__cls ?>" viewBox="0 0 200 200"><path d="M70 22 44 30 12 58l20 30 18-11v101h100V77l18 11 20-30-32-28-26-8c-4 14-16 22-30 22S74 36 70 22z" fill="<?= $__c ?>" stroke="rgba(0,0,0,.12)" stroke-width="2"/></svg>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <span class="ec-hero-badge"><b>-30%</b><small>selected styles</small></span>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ======= SERVICE STRIP ======= -->
<section class="ec-usp">
  <div class="container ec-usp-inner">
    <?php foreach ($promises as [$t, $d, $ic]): ?>
    <div class="ec-usp-item">
      <i class="<?= $ic ?>"></i>
      <div><b><?= e($t) ?></b><span><?= e($d) ?></span></div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ======= SHOP BY CATEGORY (Admin → Categories → image) ======= -->
<?php if ($homeCategories): ?>
<section class="container dx-section" id="categories">
  <?php ec_head('Top categories', 'Shop by category', BASE_URL . '/all-categories', 'All categories'); ?>
  <div class="ec-cats">
    <?php foreach ($homeCategories as $hc): ?>
    <a href="<?= BASE_URL ?>/category/<?= e($hc['slug']) ?>" class="ec-cat">
      <span class="ec-cat-img">
        <?php if (!empty($hc['image'])): ?>
          <img src="<?= BASE_URL . e($hc['image']) ?>" alt="<?= e($hc['name']) ?>" loading="lazy">
        <?php else: ?>
          <span class="ec-cat-ph"><?= $hc['icon'] ? e($hc['icon']) : '<i class="fa-solid fa-shirt"></i>' ?></span>
        <?php endif; ?>
      </span>
      <span class="ec-cat-name"><?= e($hc['name']) ?></span>
      <?php if (!empty($hc['parent_name'])): ?><small><?= e($hc['parent_name']) ?></small><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- ======= NEW ARRIVALS ======= -->
<?php if ($newItems): ?>
<section class="container dx-section" id="new">
  <?php ec_head('Just dropped', 'New arrivals', BASE_URL . '/products?sort=newest', 'Shop all new'); ?>
  <div class="prod-grid ec-grid"><?php render_product_cards($newItems); ?></div>
</section>
<?php endif; ?>

<!-- ======= COLLECTIONS (Admin → Home Collections, with images) ======= -->
<?php if ($collections): ?>
<section class="container dx-section" id="parts">
  <?php ec_head('Collections', 'Shop the collections'); ?>
  <?php
    /* 4-column mosaic: a Featured tile is 2×2, others 1×1; widen the last tile if a gap would be left */
    $__cells = 0; foreach ($collections as $__c) { $__cells += !empty($__c[4]) ? 4 : 1; }
    $__wideLast = in_array($__cells % 4, [2, 3], true);
    $__n = count($collections);
  ?>
  <div class="ec-collections">
    <?php foreach ($collections as $__ci => [$t, $d, $ic, $q, $feat, $img]): ?>
    <a href="<?= e($q) ?>" class="ec-coll<?= $feat ? ' is-feature' : '' ?><?= (!$feat && $__wideLast && $__ci === $__n - 1) ? ' is-wide' : '' ?><?= $img ? '' : ' no-img' ?>">
      <?php if ($img): ?><img src="<?= BASE_URL . e($img) ?>" alt="<?= e($t) ?>" loading="lazy"><?php else: ?><span class="ec-coll-ic"><i class="<?= $ic ?>"></i></span><?php endif; ?>
      <span class="ec-coll-txt">
        <b><?= e($t) ?></b>
        <?php if ($d !== ''): ?><small><?= e($d) ?></small><?php endif; ?>
        <span class="ec-coll-go">Shop now <i class="fa-solid fa-arrow-right"></i></span>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- ======= SALE ======= -->
<?php if ($flashItems): ?>
<section class="container dx-section" id="deals">
  <div class="ec-sale-head">
    <div>
      <span class="dx-kicker dx-hot"><i class="fa-solid fa-bolt"></i> Limited time</span>
      <h2>On sale now</h2>
    </div>
    <a class="dx-link" href="<?= BASE_URL ?>/products">Shop the sale <i class="fa-solid fa-arrow-right"></i></a>
  </div>
  <div class="prod-grid ec-grid"><?php render_product_cards($flashItems); ?></div>
</section>
<?php endif; ?>

<!-- ======= BEST SELLERS ======= -->
<?php if ($topItems): ?>
<section class="container dx-section" id="best">
  <?php ec_head('Most loved', 'Best sellers', BASE_URL . '/products'); ?>
  <div class="prod-grid ec-grid"><?php render_product_cards($topItems); ?></div>
</section>
<?php endif; ?>

<!-- ======= EXCHANGE / FIT BANNER ======= -->
<section class="container dx-section">
  <div class="ec-fit-band">
    <div class="ec-fit-copy">
      <span class="dx-kicker light">Fit promise</span>
      <h2>Wrong size? Exchange it in 7 days.</h2>
      <p>Every product page shows live stock for each size and colour, plus a size guide. If it still doesn't fit, send it back unworn and we'll swap it.</p>
      <div class="ec-btn-row">
        <a href="<?= BASE_URL ?>/refund-policy" class="ec-btn ec-btn-white">Exchange policy</a>
        <?php if ($waLink): ?><a href="<?= e($waLink) ?>" target="_blank" rel="noopener" class="ec-btn ec-btn-outline"><i class="fa-brands fa-whatsapp"></i> Ask about sizes</a><?php endif; ?>
      </div>
    </div>
    <div class="ec-fit-sizes" aria-hidden="true">
      <?php foreach (['XS', 'S', 'M', 'L', 'XL', 'XXL'] as $__k => $__sz): ?><span class="<?= $__k === 2 ? 'on' : '' ?><?= $__k === 5 ? ' out' : '' ?>"><?= $__sz ?></span><?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ======= BRANDS (Admin → Brands) ======= -->
<?php $__brandsWithLogo = array_values(array_filter($homeBrands, fn($b) => !empty($b['image']))); ?>
<?php if ($__brandsWithLogo): ?>
<section class="container dx-section" id="brands">
  <?php ec_head('Our labels', 'Shop by brand'); ?>
  <div class="ec-brands">
    <?php foreach ($__brandsWithLogo as $b): ?>
    <a href="<?= e($b['url']) ?>" class="ec-brand" title="<?= e(brand_hover_text($b)) ?>"><img src="<?= BASE_URL . e($b['image']) ?>" alt="<?= e($b['name']) ?>" loading="lazy"></a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- ======= HAPPY CUSTOMERS ======= -->
<?php if ($happyCustomers): ?>
<section class="happy-customers container dx-section" id="happy-customers">
  <div class="dx-head">
    <div>
      <span class="dx-kicker">Reviews</span>
      <h2>Loved by our customers</h2>
    </div>
  </div>
  <div class="hc-grid">
    <?php foreach ($happyCustomers as $hcu): ?>
    <button type="button" class="hc-card" onclick='openHCComment(<?= htmlspecialchars(json_encode([
        "name"    => $hcu["customer_name"],
        "comment" => $hcu["comment"],
        "image"   => $hcu["image"],
      ]), ENT_QUOTES, "UTF-8") ?>)'>
      <?php if ($hcu['image']): ?>
        <img class="thumb" src="<?= BASE_URL . e($hcu['image']) ?>" alt="<?= e($hcu['customer_name']) ?>" loading="lazy">
      <?php else: ?>
        <span class="thumb-placeholder">🙂</span>
      <?php endif; ?>
      <span class="hc-name"><?= e($hcu['customer_name']) ?></span>
      <span class="hc-comment-preview">&ldquo;<?= e(mb_strimwidth($hcu['comment'], 0, 80, '…')) ?>&rdquo;</span>
    </button>
    <?php endforeach; ?>
  </div>
</section>

<div id="hcCommentOverlay" class="hc-modal-overlay" onclick="if(event.target===this) closeHCComment()">
  <div class="hc-modal-box">
    <button type="button" class="hc-modal-close" onclick="closeHCComment()" aria-label="Close">&times;</button>
    <img id="hcCommentImage" src="" alt="">
    <h4 id="hcCommentName"></h4>
    <p id="hcCommentText"></p>
  </div>
</div>
<script>
function openHCComment(c){
  document.getElementById('hcCommentImage').src = c.image ? '<?= BASE_URL ?>' + c.image : '';
  document.getElementById('hcCommentImage').style.display = c.image ? 'block' : 'none';
  document.getElementById('hcCommentName').textContent = c.name || '';
  document.getElementById('hcCommentText').textContent = c.comment || '';
  document.getElementById('hcCommentOverlay').classList.add('show');
}
function closeHCComment(){ document.getElementById('hcCommentOverlay').classList.remove('show'); }
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeHCComment(); });
</script>
<?php endif; ?>

<!-- ======= HELP CTA ======= -->
<section class="container dx-section ec-cta-section">
  <div class="dx-cta ec-cta">
    <div>
      <h2>Need help choosing a size?</h2>
      <p>Send us your height and usual size on WhatsApp — we'll tell you exactly which size to order.</p>
    </div>
    <div class="ec-btn-row">
      <?php if ($waLink): ?><a href="<?= e($waLink) ?>" target="_blank" rel="noopener" class="ec-btn ec-btn-white"><i class="fa-brands fa-whatsapp"></i> Chat on WhatsApp</a><?php endif; ?>
      <a href="<?= BASE_URL ?>/contact-us" class="ec-btn ec-btn-outline">Contact us</a>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
