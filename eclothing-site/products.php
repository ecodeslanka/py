<?php
/**
 * products.php — Products / Search page.
 *   /products                    → all products
 *   /products?q=keyword          → search
 *   /products?category=slug      → filter by category (+ its sub-categories)
 *   /products?brand=1&brand=2    → filter by one or more brands
 *   /products?min_price=&max_price=&size[]=M&color[]=Black&sort=&page=
 */
require_once __DIR__ . '/config/config.php';
ensure_items_table();

$q          = trim((string)($_GET['q'] ?? ''));
$categorySlug = trim((string)($_GET['category'] ?? ''));
$brandIds   = array_map('intval', (array)($_GET['brand'] ?? []));
$minPrice   = $_GET['min_price'] ?? '';
$maxPrice   = $_GET['max_price'] ?? '';
$condition  = in_array($_GET['condition'] ?? '', ['new', 'old'], true) ? $_GET['condition'] : '';
$inStockOnly = !empty($_GET['in_stock']);
$sizeSel    = array_values(array_filter(array_map('strval', (array)($_GET['size'] ?? []))));
$colorSel   = array_values(array_filter(array_map('strval', (array)($_GET['color'] ?? []))));
$sort       = $_GET['sort'] ?? 'newest';
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 24;

$activeCategory = null;
$categoryIds    = [];
if ($categorySlug !== '') {
    $st = db()->prepare('SELECT * FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1');
    $st->execute([$categorySlug]);
    $activeCategory = $st->fetch();
    if ($activeCategory) {
        $categoryIds = get_category_ids_with_descendants((int)$activeCategory['id']);
    }
}

$filters = [
    'q'             => $q,
    'category_ids'  => $categoryIds,
    'brand_ids'     => $brandIds,
    'min_price'     => $minPrice,
    'max_price'     => $maxPrice,
    'condition'     => $condition,
    'in_stock_only' => $inStockOnly,
    'sizes'         => $sizeSel,
    'colors'        => $colorSel,
    'sort'          => $sort,
    'limit'         => $perPage,
    'offset'        => ($page - 1) * $perPage,
];

$items      = get_items_filtered($filters);
$totalCount = count_items_filtered($filters);
$totalPages = max(1, (int)ceil($totalCount / $perPage));

if ($page > $totalPages && $totalPages >= 1) {
    $page = $totalPages;
    $filters['offset'] = ($page - 1) * $perPage;
    $items = get_items_filtered($filters);
}

$categoryTree = get_category_tree();
$brands       = get_brands(false, 200);
$scOptions    = get_filter_size_color_options();

$pageTitle = $q !== '' ? 'Search: ' . $q . ' — ' . site_display_name()
    : ($activeCategory ? $activeCategory['name'] . ' — ' . site_display_name() : 'All Products — ' . site_display_name());
require __DIR__ . '/includes/header.php';

/** Rebuilds the current query string with one param overridden — used for sort links + pagination. */
function products_url(array $override = []): string
{
    $params = array_merge($_GET, $override);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null || (is_array($v) && !$v)) { unset($params[$k]); }
    }
    return BASE_URL . '/products' . ($params ? '?' . http_build_query($params) : '');
}
?>
<main class="wrap">

  <nav class="item-breadcrumb" aria-label="Breadcrumb">
    <a href="<?= BASE_URL ?>/">Home</a><span>/</span>
    <span class="current"><?= $activeCategory ? e($activeCategory['name']) : ($q !== '' ? 'Search Results' : 'All Products') ?></span>
  </nav>

  <div class="products-head">
    <h1><?= $activeCategory ? e($activeCategory['name']) : ($q !== '' ? 'Search results for "' . e($q) . '"' : 'All Products') ?></h1>
    <p class="products-count"><?= (int)$totalCount ?> product<?= $totalCount === 1 ? '' : 's' ?> found</p>
  </div>

  <div class="products-layout">

    <!-- ===== FILTER SIDEBAR ===== -->
    <div class="filters-backdrop" id="filtersBackdrop"></div>
    <aside class="filters-sidebar" id="filtersSidebar">
      <div class="filters-head">
        <h3>Filters</h3>
        <button type="button" class="filters-close" id="filtersClose" aria-label="Close filters"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <a href="<?= BASE_URL ?>/products" class="filters-clear" style="display:block;margin-bottom:14px">Clear All</a>

      <form method="get" action="<?= BASE_URL ?>/products" id="filtersForm">
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <?php if ($categorySlug !== ''): ?><input type="hidden" name="category" value="<?= e($categorySlug) ?>"><?php endif; ?>
        <input type="hidden" name="sort" value="<?= e($sort) ?>">

        <div class="filter-block">
          <h4>Category</h4>
          <input type="text" id="categorySearchBox" class="filter-cat-search" placeholder="Search categories…" autocomplete="off">
          <ul class="filter-cat-tree" id="filterCatTree">
            <li>
              <a href="<?= products_url(['category' => null]) ?>" class="<?= !$activeCategory ? 'on' : '' ?>">All Categories</a>
            </li>
            <?php foreach ($categoryTree as $cat): ?>
            <li>
              <a href="<?= BASE_URL ?>/products?category=<?= e($cat['slug']) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
                 class="<?= $activeCategory && $activeCategory['id'] == $cat['id'] ? 'on' : '' ?>">
                <?= $cat['icon'] ? e($cat['icon']) . ' ' : '' ?><?= e($cat['name']) ?>
              </a>
              <?php if (!empty($cat['children'])): ?>
              <ul>
                <?php foreach ($cat['children'] as $sub): ?>
                <li>
                  <a href="<?= BASE_URL ?>/products?category=<?= e($sub['slug']) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
                     class="<?= $activeCategory && $activeCategory['id'] == $sub['id'] ? 'on' : '' ?>"><?= e($sub['name']) ?></a>
                </li>
                <?php endforeach; ?>
              </ul>
              <?php endif; ?>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <?php if ($brands): ?>
        <div class="filter-block">
          <h4>Brand</h4>
          <div class="filter-checklist">
            <?php foreach ($brands as $b): ?>
            <label class="filter-check">
              <input type="checkbox" name="brand[]" value="<?= (int)$b['id'] ?>" <?= in_array((int)$b['id'], $brandIds, true) ? 'checked' : '' ?>>
              <?= e($b['name']) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="filter-block">
          <h4>Price Range (Rs.)</h4>
          <div class="filter-price-row">
            <input type="number" name="min_price" placeholder="Min" min="0" value="<?= e((string)$minPrice) ?>">
            <span>—</span>
            <input type="number" name="max_price" placeholder="Max" min="0" value="<?= e((string)$maxPrice) ?>">
          </div>
        </div>

        <?php if ($scOptions['sizes']): ?>
        <div class="filter-block">
          <h4>Size</h4>
          <div class="ec-filter-sizes">
            <?php foreach ($scOptions['sizes'] as $__sz): ?>
            <label class="ec-fsize"><input type="checkbox" name="size[]" value="<?= e($__sz) ?>" <?= in_array($__sz, $sizeSel, true) ? 'checked' : '' ?>><span><?= e($__sz) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($scOptions['colors']): ?>
        <div class="filter-block">
          <h4>Colour</h4>
          <div class="ec-filter-colors">
            <?php foreach ($scOptions['colors'] as $__c): $__hex = clean_color_hex($__c['color_hex']); ?>
            <label class="ec-fcolor" title="<?= e($__c['color_name']) ?>"><input type="checkbox" name="color[]" value="<?= e($__c['color_name']) ?>" <?= in_array($__c['color_name'], $colorSel, true) ? 'checked' : '' ?>><span class="ec-fcolor-dot"<?= $__hex ? ' style="--sw:' . e($__hex) . '"' : '' ?>></span><span class="ec-fcolor-name"><?= e($__c['color_name']) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="filter-block">
          <label class="filter-check"><input type="checkbox" name="in_stock" value="1" <?= $inStockOnly ? 'checked' : '' ?>> In Stock Only</label>
        </div>

        <button type="submit" class="item-cta" style="width:100%;justify-content:center;display:flex">Apply Filters</button>
      </form>
    </aside>

    <!-- ===== RESULTS ===== -->
    <div class="products-main">

      <div class="products-toolbar">
        <button type="button" class="filters-toggle" id="filtersToggle"><i class="fa-solid fa-sliders"></i> Filters</button>
        <div class="products-sort">
          <label for="sortSelect">Sort by</label>
          <select id="sortSelect" onchange="location.href=this.value">
            <?php
            $sortOptions = [
                'newest'     => 'Newest',
                'price_asc'  => 'Price: Low to High',
                'price_desc' => 'Price: High to Low',
                'name_asc'   => 'Name: A–Z',
                'name_desc'  => 'Name: Z–A',
            ];
            foreach ($sortOptions as $val => $label): ?>
              <option value="<?= products_url(['sort' => $val, 'page' => null]) ?>" <?= $sort === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <?php if (!$items): ?>
        <div class="products-empty">
          <i class="fa-solid fa-magnifying-glass"></i>
          <h3>No products found</h3>
          <p>Try adjusting your filters or search for something else.</p>
          <a href="<?= BASE_URL ?>/products" class="item-cta">View All Products</a>
        </div>
      <?php else: ?>
        <section class="prod-grid ec-shop-grid">
          <?php render_product_cards($items); ?>
        </section>

        <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Pagination">
          <?php if ($page > 1): ?><a href="<?= products_url(['page' => $page - 1]) ?>"><i class="fa-solid fa-chevron-left"></i></a><?php endif; ?>
          <?php
          $startP = max(1, $page - 2);
          $endP   = min($totalPages, $page + 2);
          for ($p = $startP; $p <= $endP; $p++): ?>
            <a href="<?= products_url(['page' => $p]) ?>" class="<?= $p === $page ? 'on' : '' ?>"><?= $p ?></a>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?><a href="<?= products_url(['page' => $page + 1]) ?>"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
        </nav>
        <?php endif; ?>
      <?php endif; ?>

    </div>
  </div>
</main>

<script>
document.getElementById('filtersToggle')?.addEventListener('click', function () {
  document.getElementById('filtersSidebar').classList.add('show');
  document.getElementById('filtersBackdrop').classList.add('show');
  document.body.classList.add('drawer-lock');
});
function closeFiltersSidebar() {
  document.getElementById('filtersSidebar').classList.remove('show');
  document.getElementById('filtersBackdrop').classList.remove('show');
  document.body.classList.remove('drawer-lock');
}
document.getElementById('filtersClose')?.addEventListener('click', closeFiltersSidebar);
document.getElementById('filtersBackdrop')?.addEventListener('click', closeFiltersSidebar);

/* Live category search — filters the sidebar tree as you type, no page reload. */
(function () {
  const box = document.getElementById('categorySearchBox');
  const tree = document.getElementById('filterCatTree');
  if (!box || !tree) return;

  // Top-level <li> items are direct children of the tree; each may contain a nested <ul> of sub-categories.
  // The first item is the "All Categories" reset link — always keep it visible.
  const topItems = Array.from(tree.children).slice(1);

  box.addEventListener('input', function () {
    const term = box.value.trim().toLowerCase();

    topItems.forEach(function (li) {
      const link = li.querySelector(':scope > a');
      const subLinks = Array.from(li.querySelectorAll('ul a'));
      const topMatches = !term || (link && link.textContent.toLowerCase().includes(term));
      const subMatches = subLinks.filter(a => !term || a.textContent.toLowerCase().includes(term));

      subLinks.forEach(a => { a.parentElement.style.display = (!term || topMatches || subMatches.includes(a)) ? '' : 'none'; });
      li.style.display = (topMatches || subMatches.length > 0 || !term) ? '' : 'none';
    });
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
