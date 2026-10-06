<?php
/**
 * includes/store_content.php — ECLOTHING storefront content
 *
 * Everything here is managed from the admin panel:
 *   • Shop by Brand    → Admin → Brands            (name, logo, hover text, order)
 *   • Collections      → Admin → Home Collections  (title, text, IMAGE, icon, link, order, featured, show/hide)
 * The arrays below are only the first-install defaults that get copied into the database once.
 */

/** Bundled ECLOTHING logo (used in header / footer / drawer / admin sidebar). */
function dewansa_logo_url(bool $white = false, bool $compact = false): string
{
    $file = 'eclothing-logo' . ($compact ? '-compact' : '') . ($white ? '-white' : '') . '.png';
    return BASE_URL . '/assets/images/' . $file;
}

/** Default brands — seeded into the brands table on first run. */
function dewansa_vehicle_models(): array
{
    return ['ECLOTHING Originals', 'ECLOTHING Basics', 'ECLOTHING Active'];
}

/** Service promises: [title, text, icon]. */
function dewansa_promises(): array
{
    return [
        ['Island-wide Delivery',  'Free over Rs. 10,000 · 2–4 days',   'fa-solid fa-truck-fast'],
        ['7-Day Size Exchange',   'Wrong size? Swap it, no stress',    'fa-solid fa-arrows-rotate'],
        ['Cash on Delivery',      'Pay when it reaches your door',      'fa-solid fa-money-bill-wave'],
        ['Secure Payments',       'Card, bank transfer & KOKO',         'fa-solid fa-lock'],
    ];
}

/* ==========================================================================
   SHOP BY BRAND (brands table)
   ========================================================================== */

/** Public URL for a brand's products (the Products page brand filter). */
function brand_shop_url(int $brandId, string $q = ''): string
{
    return BASE_URL . '/products?brand[]=' . $brandId . ($q !== '' ? '&q=' . rawurlencode($q) : '');
}

/**
 * Brands flagged "Show in Shop by Brand" (Admin → Brands), in admin sort order,
 * each with: id, name, image, tagline, item_count, url.
 */
function dewansa_home_brands(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    try {
        ensure_brands_table();
        ensure_items_table();
        $rows = db()->query("SELECT b.*,
                    (SELECT COUNT(*) FROM items i WHERE i.brand_id = b.id AND i.stock_status != 'out_of_stock') AS item_count
                FROM brands b
                WHERE b.show_on_home = 1
                ORDER BY b.sort_order ASC, b.name ASC")->fetchAll();
    } catch (\Throwable $e) {
        error_log('dewansa_home_brands: ' . $e->getMessage());
        $rows = [];
    }
    foreach ($rows as &$r) {
        $r['image']      = $r['image'] ?: null;
        $r['tagline']    = $r['tagline'] ?? '';
        $r['item_count'] = (int)($r['item_count'] ?? 0);
        $r['url']        = brand_shop_url((int)$r['id']);
    }
    unset($r);
    return $cache = $rows;
}

/** Hover text for a brand tile: admin tagline, else "Shop <name> · N items". */
function brand_hover_text(array $b): string
{
    $t = trim((string)($b['tagline'] ?? ''));
    $base = $t !== '' ? $t : 'Shop ' . $b['name'];
    $n = (int)($b['item_count'] ?? 0);
    return $base . ($n > 0 ? ' · ' . $n . ' item' . ($n === 1 ? '' : 's') : '');
}

/* ==========================================================================
   COLLECTIONS (home_services table — Admin → Parts & Services)
   ========================================================================== */

/** Default collections: [title, description, icon, link(keyword or URL)]. */
function dewansa_default_services(): array
{
    return [
        ['Men',          'T-shirts, shirts, polos, denims & more', 'fa-solid fa-person',        '/products?category=men'],
        ['Women',        'Tops, dresses, blouses & bottoms',       'fa-solid fa-person-dress',  '/products?category=women'],
        ['New Arrivals', 'Fresh drops every week',                 'fa-solid fa-star',          '/products?sort=newest'],
        ['Kids',         'Comfy everyday wear for little ones',    'fa-solid fa-child-reaching','/products?category=kids'],
    ];
}

function ensure_home_services_table(): void
{
    static $done = false;
    if ($done) { return; }
    db()->exec("
    CREATE TABLE IF NOT EXISTS home_services (
      id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      title       VARCHAR(120) NOT NULL,
      description VARCHAR(255) DEFAULT NULL,
      icon        VARCHAR(80)  NOT NULL DEFAULT 'fa-solid fa-shirt',
      link        VARCHAR(255) DEFAULT NULL,
      image       VARCHAR(255) DEFAULT NULL,
      sort_order  INT NOT NULL DEFAULT 0,
      is_featured TINYINT(1) NOT NULL DEFAULT 0,
      is_active   TINYINT(1) NOT NULL DEFAULT 1,
      created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    /* banner image for each collection tile (Admin → Home Collections) */
    if (!db_has_column('home_services', 'image')) {
        db()->exec("ALTER TABLE home_services ADD COLUMN image VARCHAR(255) DEFAULT NULL AFTER link");
    }
    /* seed defaults once (only when the table is brand new / empty) */
    if ((int)db()->query('SELECT COUNT(*) FROM home_services')->fetchColumn() === 0) {
        $ins = db()->prepare('INSERT INTO home_services (title, description, icon, link, sort_order, is_featured) VALUES (?, ?, ?, ?, ?, ?)');
        foreach (dewansa_default_services() as $i => [$t, $d, $ic, $l]) {
            $ins->execute([$t, $d, $ic, $l, $i + 1, $i === 0 ? 1 : 0]);
        }
    }
    $done = true;
}

/**
 * Where a service card links to. Admin "Link" field accepts:
 *   • a search word  (e.g. "ECU")                → /products?q=ECU
 *   • a site path    (e.g. "/category/sensors")  → used as-is
 *   • a full URL     (e.g. "https://…")          → used as-is
 */
function service_link_url(?string $link): string
{
    $link = trim((string)$link);
    if ($link === '') { return BASE_URL . '/products'; }
    if (preg_match('~^https?://~i', $link)) { return $link; }
    if ($link[0] === '/' || $link[0] === '#') { return BASE_URL . $link; }
    return BASE_URL . '/products?q=' . rawurlencode($link);
}

/** Active collections, as [title, description, icon, url, is_featured, image] (admin order). */
function dewansa_parts_services(bool $activeOnly = true): array
{
    static $cache = [];
    if (isset($cache[$activeOnly])) { return $cache[$activeOnly]; }
    try {
        ensure_home_services_table();
        $rows = db()->query('SELECT * FROM home_services' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order ASC, id ASC')->fetchAll();
    } catch (\Throwable $e) {
        error_log('dewansa_parts_services: ' . $e->getMessage());
        $rows = [];
        foreach (dewansa_default_services() as $i => [$t, $d, $ic, $l]) {
            $rows[] = ['title' => $t, 'description' => $d, 'icon' => $ic, 'link' => $l, 'is_featured' => $i === 0 ? 1 : 0];
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [$r['title'], (string)$r['description'], $r['icon'] ?: 'fa-solid fa-shirt', service_link_url($r['link']), (int)$r['is_featured'], $r['image'] ?? null];
    }
    return $cache[$activeOnly] = $out;
}


/** Newest products (home "New Arrivals"). */
function get_new_arrival_items(int $limit = 8): array
{
    try {
        ensure_items_table();
        $st = db()->prepare(get_homepage_items_base_sql() . ' ORDER BY i.created_at DESC, i.id DESC LIMIT ' . max(1, min(24, $limit)));
        $st->execute();
        return $st->fetchAll();
    } catch (\Throwable $e) {
        error_log('get_new_arrival_items: ' . $e->getMessage());
        return [];
    }
}

/**
 * Saves an uploaded image from $_FILES[$field] into /assets/uploads/$subdir.
 * Returns [path|null, error|null]; keeps $existingPath when nothing was uploaded.
 */
function save_uploaded_site_image(string $field, ?string $existingPath, string $subdir, int $maxMb = 4): array
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) { return [$existingPath, null]; }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) { return [$existingPath, 'Image upload failed — please try again.']; }
    if ($_FILES[$field]['size'] > $maxMb * 1024 * 1024) { return [$existingPath, "Image is too large (max {$maxMb}MB)."]; }
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    $ext = strtolower(pathinfo((string)$_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) { return [$existingPath, 'Image must be a JPG, PNG, WEBP or GIF.']; }
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($_FILES[$field]['tmp_name']);
        if ($mime && !in_array($mime, $allowed, true)) { return [$existingPath, 'That file does not look like a valid image.']; }
    }
    $subdir = preg_replace('/[^a-z0-9_-]/i', '', $subdir) ?: 'misc';
    $dir = __DIR__ . '/../assets/uploads/' . $subdir;
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "php_flag engine off\n<FilesMatch \"\\.php$\">\nRequire all denied\n</FilesMatch>\n"); }
    $filename = $subdir . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $filename)) { return [$existingPath, 'Could not save the image.']; }
    if ($existingPath) { $old = __DIR__ . '/../' . ltrim($existingPath, '/'); if (is_file($old)) { @unlink($old); } }
    return ['/assets/uploads/' . $subdir . '/' . $filename, null];
}
