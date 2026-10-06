<?php
/**
 * category.php — Category landing page  (URL: /category/{slug})
 * Resolves the slug, 404s if it doesn't exist, then hands off to
 * products.php's full listing engine (sidebar category tree, brand/price/
 * condition filters, sort, pagination) pre-filtered to this category —
 * so category.php and products.php can never drift out of sync.
 */
require_once __DIR__ . '/config/config.php';

$slug = $_GET['slug'] ?? '';
if (!preg_match('/^[a-z0-9\-]{1,120}$/', $slug)) {
    http_response_code(404);
    $slug = '';
}

$st = db()->prepare('SELECT id FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1');
$st->execute([$slug]);
$exists = $st->fetch();

if (!$exists) {
    http_response_code(404);
    $pageTitle = 'Category Not Found — ' . site_display_name();
    require __DIR__ . '/includes/header.php';
    echo '<main class="wrap" style="padding:60px 20px;text-align:center">
            <h1 style="font-size:34px">404 — Category not found</h1>
            <p style="color:var(--muted);margin:14px 0 24px">The category you are looking for does not exist or was removed.</p>
            <a class="btn btn-green" href="' . BASE_URL . '/">← Back to Home</a>
          </main>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$_GET['category'] = $slug;
require __DIR__ . '/products.php';
