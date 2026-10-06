<?php
/**
 * includes/functions.php — shared helpers (security + categories)
 */

declare(strict_types=1);

require_once __DIR__ . '/SmtpMailer.php';

/* ---------- Output escaping (XSS protection) ---------- */
function e(?string $str): string
{
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------- CSRF protection ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
        http_response_code(403);
        exit('Invalid security token. Please go back and try again.');
    }
}

/* ---------- Redirect helper ---------- */
function redirect(string $path): void
{
    header('Location: ' . BASE_URL . $path);
    exit;
}

/* ---------- Slug generator ---------- */
function make_slug(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-') ?: 'item-' . bin2hex(random_bytes(3));
}

/* ---------- Categories: fetch full 3-level tree ---------- */
/** Adds the optional category image column for pre-existing installs (self-migrating). */
function ensure_categories_table(): void
{
    static $done = false;
    if ($done) { return; }
    if (!db_has_column('categories', 'image')) {
        try {
            db()->exec("ALTER TABLE categories ADD COLUMN image VARCHAR(255) DEFAULT NULL AFTER icon");
        } catch (\Throwable $e) {
            error_log("categories migration for 'image' failed: " . $e->getMessage());
        }
    }
    /* Controls whether a top-level category shows in the header nav bar / mobile drawer. */
    if (!db_has_column('categories', 'show_in_menu')) {
        try {
            db()->exec("ALTER TABLE categories ADD COLUMN show_in_menu TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active");
        } catch (\Throwable $e) {
            error_log("categories migration for 'show_in_menu' failed: " . $e->getMessage());
        }
    }
    /* Controls whether a top-level category shows in the homepage "Shop by Category" grid. */
    if (!db_has_column('categories', 'show_on_home')) {
        try {
            db()->exec("ALTER TABLE categories ADD COLUMN show_on_home TINYINT(1) NOT NULL DEFAULT 1 AFTER show_in_menu");
        } catch (\Throwable $e) {
            error_log("categories migration for 'show_on_home' failed: " . $e->getMessage());
        }
    }
    /* Controls whether a top-level category shows in the "Browse Categories" mobile/off-canvas drawer —
       kept independent from show_in_menu (the top nav bar) so each can list a different set of categories. */
    if (!db_has_column('categories', 'show_in_drawer')) {
        try {
            db()->exec("ALTER TABLE categories ADD COLUMN show_in_drawer TINYINT(1) NOT NULL DEFAULT 1 AFTER show_on_home");
        } catch (\Throwable $e) {
            error_log("categories migration for 'show_in_drawer' failed: " . $e->getMessage());
        }
    }
    $done = true;
}

function get_category_tree(bool $activeOnly = true): array
{
    ensure_categories_table();
    $sql = 'SELECT id, parent_id, name, slug, icon, image, level, sort_order, is_active, show_in_menu, show_on_home, show_in_drawer
            FROM categories'
         . ($activeOnly ? ' WHERE is_active = 1' : '')
         . ' ORDER BY sort_order ASC, name ASC';

    $rows = db()->query($sql)->fetchAll();

    $byId = [];
    foreach ($rows as $r) {
        $r['children'] = [];
        $byId[$r['id']] = $r;
    }
    $tree = [];
    foreach ($byId as $id => &$node) {
        if ($node['parent_id'] !== null && isset($byId[$node['parent_id']])) {
            $byId[$node['parent_id']]['children'][] = &$node;
        } else {
            $tree[] = &$node;
        }
    }
    unset($node);
    return $tree;
}

/**
 * Top-level, active categories flagged "Show in Top Menu" (Admin → Categories),
 * with their full child tree attached — used to build the header nav bar (desktop).
 * Falls back to all active top-level categories if none have been explicitly flagged
 * (keeps existing installs working before anyone touches the new checkboxes).
 */
function get_menu_categories(): array
{
    $tree = get_category_tree(true);
    $filtered = array_values(array_filter($tree, fn($c) => (int)($c['show_in_menu'] ?? 1) === 1));
    return $filtered ?: $tree;
}

/**
 * Top-level, active categories flagged "Show in Browse Categories Drawer" (Admin → Categories),
 * with their full child tree attached — used for the green "BROWSE CATEGORIES" button's
 * mobile/off-canvas menu. Kept independent from get_menu_categories() (the top nav bar) so each
 * can list a different set of categories. Falls back to all active top-level categories if none
 * have been explicitly flagged.
 */
function get_drawer_categories(): array
{
    $tree = get_category_tree(true);
    $filtered = array_values(array_filter($tree, fn($c) => (int)($c['show_in_drawer'] ?? 1) === 1));
    return $filtered ?: $tree;
}

/**
 * Top-level, active categories flagged "Show on Homepage" (Admin → Categories),
 * for the homepage "Shop by Category" grid. Falls back to all active top-level
 * categories (existing behaviour) if none have been explicitly flagged.
 */
function get_homepage_categories(int $limit = 12): array
{
    $tree = get_category_tree(true);
    $filtered = array_values(array_filter($tree, fn($c) => (int)($c['show_on_home'] ?? 1) === 1));
    $out = $filtered ?: $tree;
    return array_slice($out, 0, $limit);
}

function get_categories_by_level(int $level): array
{
    $st = db()->prepare('SELECT c.*, p.name AS parent_name
                         FROM categories c
                         LEFT JOIN categories p ON p.id = c.parent_id
                         WHERE c.level = ?
                         ORDER BY c.sort_order, c.name');
    $st->execute([$level]);
    return $st->fetchAll();
}

/* ---------- Categories: flatten a tree into an indented list (for <select> dropdowns) ---------- */
function flatten_categories_for_select(array $tree, int $depth = 0): array
{
    $out = [];
    foreach ($tree as $node) {
        $children = $node['children'] ?? [];
        unset($node['children']);
        $node['depth'] = $depth;
        $out[] = $node;
        if ($children) {
            $out = array_merge($out, flatten_categories_for_select($children, $depth + 1));
        }
    }
    return $out;
}

/* ---------- Categories: get one row by id ---------- */
function get_category(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM categories WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/* ---------- Categories: collect all descendant ids (used to block circular re-parenting) ---------- */
function get_category_descendant_ids(int $id): array
{
    $ids  = [];
    $rows = db()->query('SELECT id, parent_id FROM categories')->fetchAll();
    $byParent = [];
    foreach ($rows as $r) {
        $byParent[(int)$r['parent_id']][] = (int)$r['id'];
    }
    $stack = $byParent[$id] ?? [];
    while ($stack) {
        $cur = array_pop($stack);
        $ids[] = $cur;
        if (!empty($byParent[$cur])) {
            foreach ($byParent[$cur] as $child) {
                $stack[] = $child;
            }
        }
    }
    return $ids;
}

/* ---------- Categories: how many levels deep is the deepest descendant (0 = leaf, no children) ---------- */
function get_category_max_relative_depth(int $id): int
{
    $rows = db()->query('SELECT id, parent_id FROM categories')->fetchAll();
    $byParent = [];
    foreach ($rows as $r) {
        $byParent[(int)$r['parent_id']][] = (int)$r['id'];
    }
    $maxDepth = 0;
    $queue = [[$id, 0]];
    while ($queue) {
        [$cur, $depth] = array_shift($queue);
        $maxDepth = max($maxDepth, $depth);
        foreach ($byParent[$cur] ?? [] as $child) {
            $queue[] = [$child, $depth + 1];
        }
    }
    return $maxDepth;
}

/* ---------- Categories: after a parent change, recompute levels for a node and every descendant ---------- */
function recompute_category_subtree_levels(int $id, int $newLevel): void
{
    db()->prepare('UPDATE categories SET level = ? WHERE id = ?')->execute([$newLevel, $id]);
    $st = db()->prepare('SELECT id FROM categories WHERE parent_id = ?');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $child) {
        recompute_category_subtree_levels((int)$child['id'], $newLevel + 1);
    }
}

/* ---------- Unique slug helper (optionally excluding one row, for edits) ---------- */
function make_unique_slug(string $name, ?int $excludeId = null): string
{
    $slug = $base = make_slug($name);
    $i = 1;
    $sql = 'SELECT 1 FROM categories WHERE slug = ?' . ($excludeId ? ' AND id != ?' : '');
    $chk = db()->prepare($sql);
    while (true) {
        $params = $excludeId ? [$slug, $excludeId] : [$slug];
        $chk->execute($params);
        if (!$chk->fetch()) break;
        $slug = $base . '-' . (++$i);
    }
    return $slug;
}

/* ---------- Products ---------- */
function get_all_products(): array
{
    $st = db()->query(
        'SELECT p.*, c.name AS category_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         ORDER BY p.sort_order, p.name'
    );
    return $st->fetchAll();
}

function get_product(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM products WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/* ---------- Variation Options (global, reusable attribute library) ---------- */
function get_all_variation_options(bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM variation_options' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name';
    return db()->query($sql)->fetchAll();
}

function get_variation_option(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM variation_options WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function get_variation_option_values(int $optionId): array
{
    $st = db()->prepare('SELECT * FROM variation_option_values WHERE option_id = ? ORDER BY sort_order, value');
    $st->execute([$optionId]);
    return $st->fetchAll();
}

function get_variation_option_value(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM variation_option_values WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/* ---------- Site settings (logo, footer logo, company info) ---------- */
function get_site_settings(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }

    db()->exec("
    CREATE TABLE IF NOT EXISTS site_settings (
      id             TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
      site_logo      VARCHAR(255) DEFAULT NULL,
      footer_logo    VARCHAR(255) DEFAULT NULL,
      company_name   VARCHAR(150) NOT NULL DEFAULT '',
      address        TEXT,
      facebook_url   VARCHAR(255) DEFAULT NULL,
      linkedin_url   VARCHAR(255) DEFAULT NULL,
      youtube_url    VARCHAR(255) DEFAULT NULL,
      twitter_url    VARCHAR(255) DEFAULT NULL,
      instagram_url  VARCHAR(255) DEFAULT NULL,
      tiktok_url     VARCHAR(255) DEFAULT NULL,
      enable_koko    TINYINT(1) NOT NULL DEFAULT 0,
      enable_mintpay TINYINT(1) NOT NULL DEFAULT 0,
      enable_payzy   TINYINT(1) NOT NULL DEFAULT 0,
      show_categories  TINYINT(1) NOT NULL DEFAULT 1,
      show_flash_sale  TINYINT(1) NOT NULL DEFAULT 1,
      show_top_selling TINYINT(1) NOT NULL DEFAULT 1,
      show_brands      TINYINT(1) NOT NULL DEFAULT 1,
      updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    db()->exec("INSERT IGNORE INTO site_settings (id, company_name) VALUES (1, 'ECLOTHING')");
    foreach (['show_categories', 'show_flash_sale', 'show_top_selling', 'show_brands', 'show_happy_customers'] as $col) {
        if (!db_has_column('site_settings', $col)) {
            db()->exec("ALTER TABLE site_settings ADD COLUMN $col TINYINT(1) NOT NULL DEFAULT 1");
        }
    }
    /* Google Sign-In settings (Admin → Site Configuration) */
    if (!db_has_column('site_settings', 'enable_google_signin')) {
        db()->exec("ALTER TABLE site_settings ADD COLUMN enable_google_signin TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!db_has_column('site_settings', 'google_client_id')) {
        db()->exec("ALTER TABLE site_settings ADD COLUMN google_client_id VARCHAR(255) DEFAULT NULL");
    }
    if (!db_has_column('site_settings', 'google_client_secret')) {
        db()->exec("ALTER TABLE site_settings ADD COLUMN google_client_secret VARCHAR(255) DEFAULT NULL");
    }
    /* Admin login security + admin panel URL (Admin -> Admin Users) */
    if (!db_has_column('site_settings', 'admin_otp_enabled')) {
        db()->exec("ALTER TABLE site_settings ADD COLUMN admin_otp_enabled TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!db_has_column('site_settings', 'admin_url_path')) {
        db()->exec("ALTER TABLE site_settings ADD COLUMN admin_url_path VARCHAR(50) NOT NULL DEFAULT 'admin'");
    }
    /* Delivery charges (Admin -> Delivery Charges) */
    if (!db_has_column('site_settings', 'default_delivery_charge')) {
        db()->exec("ALTER TABLE site_settings ADD COLUMN default_delivery_charge DECIMAL(10,2) NOT NULL DEFAULT 0");
    }
    /* Genie Business Connect payment gateway (Admin -> Payment Settings) */
    $genieColumns = [
        'genie_enabled'      => "TINYINT(1) NOT NULL DEFAULT 0",
        'genie_environment'  => "VARCHAR(20) NOT NULL DEFAULT 'sandbox'", // 'sandbox' | 'production'
        'genie_app_id'       => "VARCHAR(255) DEFAULT NULL",
        'genie_api_key'      => "VARCHAR(255) DEFAULT NULL",
        'genie_provider'     => "VARCHAR(30) DEFAULT NULL",              // optional: card, bcmc, etc — blank = let customer pick on Genie's page
        'genie_valid_hours'  => "SMALLINT UNSIGNED NOT NULL DEFAULT 24",
        'genie_base_url'     => "VARCHAR(255) DEFAULT NULL",             // override only if Genie gives you a different host for your account
    ];
    foreach ($genieColumns as $col => $def) {
        if (!db_has_column('site_settings', $col)) {
            db()->exec("ALTER TABLE site_settings ADD COLUMN $col $def");
        }
    }
    /* KOKO (Paykoko / Daraz BNPL) payment gateway (Admin -> Payment Settings) */
    $kokoColumns = [
        'koko_enabled'         => "TINYINT(1) NOT NULL DEFAULT 0",
        'koko_environment'     => "VARCHAR(20) NOT NULL DEFAULT 'sandbox'", // 'sandbox' | 'production'
        'koko_merchant_id'     => "VARCHAR(255) DEFAULT NULL",
        'koko_api_key'         => "VARCHAR(255) DEFAULT NULL",
        'koko_private_key'     => "TEXT DEFAULT NULL", // merchant's RSA private key (PEM) — signs outgoing orderCreate requests
        'koko_public_key'      => "TEXT DEFAULT NULL", // KOKO's RSA public key (PEM) — verifies incoming callback signatures
        'koko_callback_secret' => "VARCHAR(255) DEFAULT NULL",
        'koko_base_url'        => "VARCHAR(255) DEFAULT NULL", // override only if KOKO gives you a different host for your account
        // The exact "plugin name" KOKO's Merchant Success team registered against your Merchant
        // ID / API Key when they issued them (KOKO's backend rejects orderCreate with
        // "merchantPluginDetail.notExists" if this doesn't match exactly what's on file for your
        // account). KOKO's own docs/sample code use "customapi" for direct/custom integrations
        // like this one — that's the default, but confirm with KOKO if unsure.
        'koko_plugin_name'      => "VARCHAR(60) NOT NULL DEFAULT 'customapi'",
    ];
    foreach ($kokoColumns as $col => $def) {
        if (!db_has_column('site_settings', $col)) {
            db()->exec("ALTER TABLE site_settings ADD COLUMN $col $def");
        }
    }
    /* WhatsApp contact number (Admin -> Site Configuration) — used for the "Chat on WhatsApp" header
       button and the per-item WhatsApp buttons on the homepage category boxes. Kept separate from the
       phone hotline numbers since a store may want a different number to handle WhatsApp orders. */
    if (!db_has_column('site_settings', 'whatsapp_number')) {
        db()->exec("ALTER TABLE site_settings ADD COLUMN whatsapp_number VARCHAR(40) DEFAULT NULL");
    }
    /* Header logo width in px (Admin -> Site Configuration) — 0 means "use the theme default". */
    if (!db_has_column('site_settings', 'header_logo_width')) {
        db()->exec("ALTER TABLE site_settings ADD COLUMN header_logo_width SMALLINT UNSIGNED NOT NULL DEFAULT 0");
    }
    /* Browser tab title + favicon (Admin -> Site Configuration -> General).
       site_title: full <title> text shown in the browser tab / search results — falls back to
       "<Company Name> — Sri Lanka's Trusted Online Sofa Store" when left blank.
       favicon: small icon shown in the browser tab — falls back to the Site Logo when not set,
       so the store always has *some* favicon (never a blank/default browser icon). */
    if (!db_has_column('site_settings', 'site_title')) {
        db()->exec("ALTER TABLE site_settings ADD COLUMN site_title VARCHAR(255) DEFAULT NULL");
    }
    if (!db_has_column('site_settings', 'favicon')) {
        db()->exec("ALTER TABLE site_settings ADD COLUMN favicon VARCHAR(255) DEFAULT NULL");
    }
    /* Editable footer text (Admin -> Site Configuration -> Footer Text).
       footer_tagline: the short line under the footer logo.
       footer_copyright_text: the bottom bar copyright line — use {year} and {company} as placeholders.
       footer_credit_text / footer_credit_url: the small "Powered by ..." credit line, defaults to
       ECODES IT Solutions (the site's developer) but can be edited/removed by the store owner. */
    if (!db_has_column('site_settings', 'footer_tagline')) {
        db()->exec("ALTER TABLE site_settings ADD COLUMN footer_tagline VARCHAR(255) DEFAULT NULL");
    }
    if (!db_has_column('site_settings', 'footer_copyright_text')) {
        db()->exec("ALTER TABLE site_settings ADD COLUMN footer_copyright_text VARCHAR(255) DEFAULT NULL");
    }
    if (!db_has_column('site_settings', 'footer_credit_text')) {
        db()->exec("ALTER TABLE site_settings ADD COLUMN footer_credit_text VARCHAR(255) NOT NULL DEFAULT 'Powered by ECODES IT SOLUTIONS'");
    }
    if (!db_has_column('site_settings', 'footer_credit_url')) {
        db()->exec("ALTER TABLE site_settings ADD COLUMN footer_credit_url VARCHAR(255) NOT NULL DEFAULT 'https://ecodes.lk'");
    }
    /* How many items/tiles each homepage panel shows (Admin -> Site Configuration ->
       Homepage Sections). Kept separate from the show_* on/off toggles so a store owner
       can turn a section on but also control how full/short it looks. */
    if (!db_has_column('site_settings', 'home_categories_count')) {
        db()->exec("ALTER TABLE site_settings ADD COLUMN home_categories_count SMALLINT UNSIGNED NOT NULL DEFAULT 12");
    }
    if (!db_has_column('site_settings', 'home_flash_sale_count')) {
        db()->exec("ALTER TABLE site_settings ADD COLUMN home_flash_sale_count SMALLINT UNSIGNED NOT NULL DEFAULT 5");
    }
    if (!db_has_column('site_settings', 'home_top_selling_count')) {
        db()->exec("ALTER TABLE site_settings ADD COLUMN home_top_selling_count SMALLINT UNSIGNED NOT NULL DEFAULT 5");
    }

    $row = db()->query('SELECT * FROM site_settings WHERE id = 1')->fetch();
    $cache = $row ?: [
        'site_logo' => null, 'footer_logo' => null, 'company_name' => '', 'address' => '',
        'facebook_url' => null, 'linkedin_url' => null, 'youtube_url' => null,
        'twitter_url' => null, 'instagram_url' => null, 'tiktok_url' => null,
        'enable_koko' => 0, 'enable_mintpay' => 0, 'enable_payzy' => 0,
        'show_categories' => 1, 'show_flash_sale' => 1, 'show_top_selling' => 1, 'show_brands' => 1,
        'show_happy_customers' => 1,
        'enable_google_signin' => 0, 'google_client_id' => null, 'google_client_secret' => null,
        'admin_otp_enabled' => 0, 'admin_url_path' => 'admin', 'default_delivery_charge' => 0,
        'genie_enabled' => 0, 'genie_environment' => 'sandbox', 'genie_app_id' => null,
        'genie_api_key' => null, 'genie_provider' => null, 'genie_valid_hours' => 24, 'genie_base_url' => null,
        'koko_enabled' => 0, 'koko_environment' => 'sandbox', 'koko_merchant_id' => null,
        'koko_api_key' => null, 'koko_private_key' => null, 'koko_public_key' => null,
        'koko_callback_secret' => null, 'koko_base_url' => null, 'koko_plugin_name' => 'customapi',
        'whatsapp_number' => null,
        'header_logo_width' => 0,
        'site_title' => null, 'favicon' => null,
        'footer_tagline' => null, 'footer_copyright_text' => null,
        'footer_credit_text' => 'Powered by ECODES IT SOLUTIONS', 'footer_credit_url' => 'https://ecodes.lk',
        'home_categories_count' => 12, 'home_flash_sale_count' => 5, 'home_top_selling_count' => 5,
    ];
    return $cache;
}

/**
 * The browser tab / <title> text (Admin -> Site Configuration -> General -> Website Title).
 * Falls back to the existing "<Company Name> — Sri Lanka's Trusted Online Sofa Store" wording
 * when no custom title has been set, so nothing changes for stores that haven't touched it yet.
 */
function site_title_text(): string
{
    $t = trim((string)(get_site_settings()['site_title'] ?? ''));
    return $t !== '' ? $t : site_display_name() . " — Men's & Women's Clothing Online | Island-wide Delivery";
}

/**
 * The favicon URL (Admin -> Site Configuration -> General -> Favicon). Falls back to the Site
 * Logo so a favicon always shows even if the store owner never uploads a dedicated one.
 * Returns '' if neither a favicon nor a site logo has been uploaded.
 */
function site_favicon_url(): string
{
    $s = get_site_settings();
    $path = !empty($s['favicon']) ? $s['favicon'] : ($s['site_logo'] ?? null);
    return $path ? BASE_URL . $path : '';
}

/**
 * Renders the footer's small "Powered by ..." credit line as safe HTML, or '' if the store owner
 * has cleared the text in Admin -> Site Configuration. Defaults to ECODES IT Solutions.
 */
function footer_credit_html(): string
{
    $s = get_site_settings();
    $text = trim((string)($s['footer_credit_text'] ?? ''));
    if ($text === '') { return ''; }
    $url = trim((string)($s['footer_credit_url'] ?? ''));
    if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
        return '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">' . e($text) . '</a>';
    }
    return e($text);
}

/**
 * The footer tagline (under the footer logo). Falls back to the original built-in wording so
 * nothing changes until the store owner edits it in Admin -> Site Configuration -> Footer Text.
 */
function footer_tagline_text(): string
{
    $t = trim((string)(get_site_settings()['footer_tagline'] ?? ''));
    return $t !== '' ? $t : 'Everyday clothing that fits right — T-shirts, shirts, denims, dresses and more, with easy size exchanges and island-wide delivery.';
}

/**
 * The footer bottom-bar copyright line. Supports {year} and {company} placeholders so the store
 * owner can customise the wording while still keeping it accurate over time. Falls back to the
 * original built-in wording when left blank.
 */
function footer_copyright_text(): string
{
    $s = get_site_settings();
    $tpl = trim((string)($s['footer_copyright_text'] ?? ''));
    if ($tpl === '') { $tpl = '© {year} {company}. All rights reserved.'; }
    $tpl = str_replace('{year}', date('Y'), $tpl);
    $tpl = str_replace('{company}', $s['company_name'] ?: SITE_NAME, $tpl);
    return $tpl;
}

/**
 * The name to show the visitor — in the browser tab title, page headings, emails, etc.
 * Always prefers the DB-configured Company Name (Admin -> Site Configuration), so the
 * page loading/tab title reflects whatever the store owner has set there; only falls
 * back to the SITE_NAME constant baked into config.php if no company name is set yet.
 */
function site_display_name(): string
{
    $companyName = trim((string)(get_site_settings()['company_name'] ?? ''));
    return $companyName !== '' ? $companyName : SITE_NAME;
}

/**
 * The WhatsApp contact number (Admin -> Site Configuration -> WhatsApp Number), digits only with the
 * country code (e.g. "94771234567" — ready to drop into a wa.me link). Falls back to the main contact
 * number if no dedicated WhatsApp number has been set. Returns '' if neither is configured.
 */
function get_whatsapp_number(): string
{
    $settings = get_site_settings();
    $raw = trim((string)($settings['whatsapp_number'] ?? ''));
    if ($raw === '') {
        $main = get_main_contact_number();
        $raw  = $main['phone'] ?? '';
    }
    $digits = preg_replace('/[^\d]/', '', $raw);
    if ($digits === '' || $digits === null) { return ''; }
    if (substr($digits, 0, 1) === '0') { $digits = '94' . substr($digits, 1); } // local 0XX -> country code 94 (Sri Lanka)
    return $digits;
}

/** Builds a wa.me link with an optional pre-filled message. Returns '' if no WhatsApp number is configured. */
function build_whatsapp_link(string $message = ''): string
{
    $number = get_whatsapp_number();
    if ($number === '') { return ''; }
    return 'https://wa.me/' . $number . ($message !== '' ? '?text=' . rawurlencode($message) : '');
}

/* ---------- Contact numbers (admin/site_configuration.php) — public helpers ---------- */
function ensure_contact_numbers_table(): void
{
    db()->exec("
    CREATE TABLE IF NOT EXISTS site_contact_numbers (
      id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      phone       VARCHAR(40)  NOT NULL,
      description VARCHAR(150) DEFAULT NULL,
      is_main     TINYINT(1) NOT NULL DEFAULT 0,
      sort_order  INT NOT NULL DEFAULT 0,
      created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    if (!db_has_column('site_contact_numbers', 'is_main')) {
        db()->exec('ALTER TABLE site_contact_numbers ADD COLUMN is_main TINYINT(1) NOT NULL DEFAULT 0 AFTER description');
    }
}

/** The number shown in the header hotline + footer. Prefers the one marked "Main", else the first by sort order. */
function get_main_contact_number(): ?array
{
    ensure_contact_numbers_table();
    $row = db()->query('SELECT * FROM site_contact_numbers ORDER BY is_main DESC, sort_order ASC, id ASC LIMIT 1')->fetch();
    return $row ?: null;
}

/** All contact numbers (Admin -> Site Configuration), main first — used in the footer and the Contact Us page. */
function get_all_contact_numbers(): array
{
    ensure_contact_numbers_table();
    return db()->query('SELECT * FROM site_contact_numbers ORDER BY is_main DESC, sort_order ASC, id ASC')->fetchAll();
}

/* ---------- Emails (admin/site_configuration.php) — public helpers ---------- */
function ensure_site_emails_table(): void
{
    db()->exec("
    CREATE TABLE IF NOT EXISTS site_emails (
      id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      email       VARCHAR(150) NOT NULL,
      description VARCHAR(150) DEFAULT NULL,
      sort_order  INT NOT NULL DEFAULT 0,
      created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** All published email addresses — used in the footer and the Contact Us page. */
function get_all_site_emails(): array
{
    ensure_site_emails_table();
    return db()->query('SELECT * FROM site_emails ORDER BY sort_order ASC, id ASC')->fetchAll();
}

/* ---------- Branches / locations (admin/site_configuration.php) — public helpers ---------- */
function ensure_site_branches_table(): void
{
    db()->exec("
    CREATE TABLE IF NOT EXISTS site_branches (
      id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name        VARCHAR(150) NOT NULL,
      address     TEXT NOT NULL,
      details     VARCHAR(255) DEFAULT NULL,
      is_main     TINYINT(1) NOT NULL DEFAULT 0,
      sort_order  INT NOT NULL DEFAULT 0,
      created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** All branches/locations, main first — used on the Contact Us page. */
function get_all_branches(): array
{
    ensure_site_branches_table();
    return db()->query('SELECT * FROM site_branches ORDER BY is_main DESC, sort_order ASC, id ASC')->fetchAll();
}

/* ---------- About Us page content (Admin -> About Us) ---------- */
function ensure_about_us_table(): void
{
    db()->exec("
    CREATE TABLE IF NOT EXISTS about_us (
      id            TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
      heading       VARCHAR(200) DEFAULT NULL,
      subheading    VARCHAR(300) DEFAULT NULL,
      banner_image  VARCHAR(255) DEFAULT NULL,
      content       LONGTEXT,
      updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    db()->exec("INSERT IGNORE INTO about_us (id, heading, subheading, content) VALUES
      (1, 'About Us', 'Designed For Your Comfort', '<p>Tell your customers who you are, what you sell, and why they should trust you. Edit this from Admin &rarr; About Us.</p>')");

    db()->exec("
    CREATE TABLE IF NOT EXISTS about_us_images (
      id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      image       VARCHAR(255) NOT NULL,
      caption     VARCHAR(150) DEFAULT NULL,
      sort_order  INT NOT NULL DEFAULT 0,
      created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** The single About Us content row (heading/subheading/banner/rich content). */
function get_about_us(): array
{
    ensure_about_us_table();
    $row = db()->query('SELECT * FROM about_us WHERE id = 1')->fetch();
    return $row ?: ['id' => 1, 'heading' => 'About Us', 'subheading' => '', 'banner_image' => null, 'content' => ''];
}

/** Gallery images shown on the About Us page, in admin-configured order. */
function get_about_us_images(): array
{
    ensure_about_us_table();
    return db()->query('SELECT * FROM about_us_images ORDER BY sort_order ASC, id ASC')->fetchAll();
}

/* ---------- Contact Us page — inbound message storage + admin notification email ---------- */
function ensure_contact_messages_table(): void
{
    db()->exec("
    CREATE TABLE IF NOT EXISTS contact_messages (
      id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name        VARCHAR(150) NOT NULL,
      email       VARCHAR(150) NOT NULL,
      phone       VARCHAR(40)  DEFAULT NULL,
      subject     VARCHAR(200) DEFAULT NULL,
      message     TEXT NOT NULL,
      is_read     TINYINT(1) NOT NULL DEFAULT 0,
      created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Sends a best-effort notification email (to Admin -> Email Settings -> "Admin Notification Email",
 * falling back to the first address in Admin -> Site Configuration -> Emails) when a visitor submits
 * the Contact Us form. Never throws — a mail failure must not stop the message from being saved.
 */
function send_contact_notification_email(array $msg): bool
{
    try {
        $emailSettings = get_email_settings();
        $siteSettings  = get_site_settings();
        $companyName   = $siteSettings['company_name'] ?: SITE_NAME;

        $to = $emailSettings['admin_notification_email'] ?? '';
        if ($to === '') {
            $emails = get_all_site_emails();
            $to = $emails[0]['email'] ?? '';
        }
        if ($to === '') { return false; }

        $subject = 'New Contact Us message — ' . ($msg['subject'] !== '' ? $msg['subject'] : $companyName);
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.6;color:#1c2333">'
              . '<h2 style="margin:0 0 14px">New message from the Contact Us page</h2>'
              . '<p><strong>Name:</strong> ' . e($msg['name']) . '</p>'
              . '<p><strong>Email:</strong> ' . e($msg['email']) . '</p>'
              . ($msg['phone'] !== '' ? '<p><strong>Phone:</strong> ' . e($msg['phone']) . '</p>' : '')
              . ($msg['subject'] !== '' ? '<p><strong>Subject:</strong> ' . e($msg['subject']) . '</p>' : '')
              . '<p><strong>Message:</strong></p><p>' . nl2br(e($msg['message'])) . '</p>'
              . '</div>';

        $fromEmail = $emailSettings['from_email'] ?: ('no-reply@' . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'example.com'));
        $fromName  = $emailSettings['from_name'] ?: $companyName;

        if (!empty($emailSettings['smtp_host'])) {
            $mailer = new SmtpMailer(
                (string)$emailSettings['smtp_host'],
                (int)($emailSettings['smtp_port'] ?: 587),
                (string)($emailSettings['smtp_username'] ?? ''),
                (string)($emailSettings['smtp_password'] ?? ''),
                (string)($emailSettings['smtp_encryption'] ?? 'tls')
            );
            return $mailer->send($fromEmail, $fromName, $to, $subject, $html);
        }

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= 'From: ' . mb_encode_mimeheader($fromName) . ' <' . $fromEmail . ">\r\n";
        $headers .= 'Reply-To: ' . $msg['email'] . "\r\n";
        return (bool)@mail($to, mb_encode_mimeheader($subject), $html, $headers);
    } catch (\Throwable $e) {
        error_log('Contact Us notification email failed: ' . $e->getMessage());
        return false;
    }
}

/* ---------- lightweight column-existence check (shared) ---------- */
function db_has_column(string $table, string $column): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}

/* ---------- Brands ---------- */
function ensure_brands_table(): void
{
    static $done = false;
    if ($done) { return; }
    db()->exec("
    CREATE TABLE IF NOT EXISTS brands (
      id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name       VARCHAR(120) NOT NULL,
      image      VARCHAR(255) DEFAULT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_brand_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    /* Brands double as the "Shop by Brand" list on the home page + menu.
       show_on_home = appears in Shop by Brand, sort_order = display order,
       tagline = hover text (e.g. "Premium cotton basics"). */
    try {
        if (!db_has_column('brands', 'show_on_home')) {
            db()->exec("ALTER TABLE brands ADD COLUMN show_on_home TINYINT(1) NOT NULL DEFAULT 1 AFTER image");
            /* first run of this migration: make sure every default brand exists */
            if (function_exists('dewansa_vehicle_models')) {
                $ins = db()->prepare('INSERT IGNORE INTO brands (name, show_on_home) VALUES (?, 1)');
                foreach (dewansa_vehicle_models() as $m) { $ins->execute([$m]); }
            }
        }
        if (!db_has_column('brands', 'sort_order')) {
            db()->exec("ALTER TABLE brands ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER show_on_home");
            if (function_exists('dewansa_vehicle_models')) {
                $up = db()->prepare('UPDATE brands SET sort_order = ? WHERE name = ?');
                foreach (dewansa_vehicle_models() as $i => $m) { $up->execute([$i + 1, $m]); }
            }
        }
        if (!db_has_column('brands', 'tagline')) {
            db()->exec("ALTER TABLE brands ADD COLUMN tagline VARCHAR(160) DEFAULT NULL AFTER sort_order");
        }
    } catch (\Throwable $e) {
        error_log('brands migration failed: ' . $e->getMessage());
    }
    $done = true;
}

/** Brands for the homepage. With an image by default (logo strip); pass false to include text-only brands too. */
function get_brands(bool $onlyWithImage = true, int $limit = 12): array
{
    ensure_brands_table();
    $sql = 'SELECT * FROM brands' . ($onlyWithImage ? ' WHERE image IS NOT NULL AND image != \'\'' : '') . ' ORDER BY sort_order ASC, name ASC LIMIT ' . (int)$limit;
    return db()->query($sql)->fetchAll();
}

/* ---------- Items (admin/items.php) — homepage helpers ---------- */
function ensure_items_table(): void
{
    ensure_brands_table();
    db()->exec("
    CREATE TABLE IF NOT EXISTS items (
      id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      sku            VARCHAR(60)  NOT NULL,
      name           VARCHAR(300) NOT NULL,
      category_id    INT UNSIGNED DEFAULT NULL,
      brand_id       INT UNSIGNED DEFAULT NULL,
      description    TEXT,
      condition_type ENUM('new','old') NOT NULL DEFAULT 'new',
      warranty       VARCHAR(150) DEFAULT NULL,
      stock_status   ENUM('in_stock','out_of_stock','pre_order') NOT NULL DEFAULT 'in_stock',
      stock_qty      INT NOT NULL DEFAULT 0,
      cost_price     DECIMAL(12,2) NOT NULL DEFAULT 0,
      selling_price  DECIMAL(12,2) NOT NULL DEFAULT 0,
      special_price  DECIMAL(12,2) DEFAULT NULL,
      has_variations TINYINT(1) NOT NULL DEFAULT 0,
      created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_sku (sku)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    db()->exec("
    CREATE TABLE IF NOT EXISTS item_images (
      id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      item_id     INT UNSIGNED NOT NULL,
      image_path  VARCHAR(255) NOT NULL,
      is_feature  TINYINT(1) NOT NULL DEFAULT 0,
      sort_order  INT NOT NULL DEFAULT 0,
      created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_item_images_item_fn FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    if (!db_has_column('items', 'is_flash_sale')) {
        db()->exec('ALTER TABLE items ADD COLUMN is_flash_sale TINYINT(1) NOT NULL DEFAULT 0 AFTER has_variations');
    }
    if (!db_has_column('items', 'is_top_item')) {
        db()->exec('ALTER TABLE items ADD COLUMN is_top_item TINYINT(1) NOT NULL DEFAULT 0 AFTER is_flash_sale');
    }
}

/** Shared SELECT used by both Flash Sale and Top Items homepage sections. */
function get_homepage_items_base_sql(): string
{
    return "SELECT i.*,
              (SELECT image_path FROM item_images WHERE item_id = i.id AND is_feature = 1 LIMIT 1) AS feature_image,
              c.name AS category_name, c.slug AS category_slug,
              b.name AS brand_name, b.image AS brand_image
            FROM items i
            LEFT JOIN categories c ON c.id = i.category_id
            LEFT JOIN brands b ON b.id = i.brand_id
            WHERE i.stock_status != 'out_of_stock'";
}

/** Items flagged "Show in Flash Sale" from admin/items.php, newest first. */
function get_flash_sale_items(int $limit = 5): array
{
    ensure_items_table();
    $st = db()->prepare(get_homepage_items_base_sql() . ' AND i.is_flash_sale = 1 AND i.special_price IS NOT NULL ORDER BY i.updated_at DESC LIMIT ' . (int)$limit);
    $st->execute();
    return $st->fetchAll();
}

/** Items flagged "Show in Top Selling" from admin/items.php, newest first. */
function get_top_selling_items(int $limit = 5): array
{
    ensure_items_table();
    $st = db()->prepare(get_homepage_items_base_sql() . ' AND i.is_top_item = 1 ORDER BY i.updated_at DESC LIMIT ' . (int)$limit);
    $st->execute();
    return $st->fetchAll();
}

/** Computes display price + discount % for a card, given an items-table row (or joined row). */
function item_price_info(array $item): array
{
    $selling = (float)($item['selling_price'] ?? 0);
    $special = isset($item['special_price']) && $item['special_price'] !== null ? (float)$item['special_price'] : null;
    $discount = ($special !== null && $selling > 0) ? (int)round((1 - ($special / $selling)) * 100) : null;
    return [
        'old_price'   => $special !== null ? $selling : null,
        'price'       => $special !== null ? $special : $selling,
        'discount'    => $discount,
        'save_amount' => ($special !== null) ? ($selling - $special) : null,
    ];
}

/**
 * Subtle "pay in installments" teaser lines (KOKO / Mintpay / Payzy) shown under
 * an item's price on product cards and the item detail page — controlled by
 * Admin -> Site Configuration -> Payment Gateways toggles (enable_koko /
 * enable_mintpay / enable_payzy). These are display-only badges; the real KOKO
 * "Buy Now, Pay Later" checkout integration is configured separately under
 * Admin -> Payment Settings and is unaffected by these toggles.
 *
 * @param float  $price   The price the customer would actually pay (after any discount).
 * @param string $variant 'detail' for the item preview page (fuller layout),
 *                        anything else ('card') for compact grid/listing cards.
 */
/**
 * KOKO's official hosted logo — used on the "or 3 X Rs... with KOKO" instalment
 * lines whenever KOKO is enabled (Admin -> Site Configuration -> Payment
 * Gateways). Pointing at KOKO's own CDN avoids bundling/self-hosting a logo
 * file that can go missing or fall out of date.
 */
function koko_logo_url(): string
{
    return 'https://paykoko.com/img/logo1.7ff549c0.png';
}
function koko_effective_price(array $item, float $fallbackPrice): float
{
    $kp = isset($item['koko_price']) ? (float)$item['koko_price'] : 0.0;
    return $kp > 0 ? $kp : $fallbackPrice;
}

/**
 * The raw KOKO Price set on an item (Admin -> Items -> "KOKO Price (optional)"), or null if the
 * admin hasn't set one. Unlike koko_effective_price() (which falls back to the normal price and
 * is used for actual checkout/charging), this is used purely to decide whether the KOKO
 * instalment teaser should appear on the product page at all — it only shows for items the store
 * owner has explicitly given a KOKO Price to.
 */
function koko_item_teaser_price(array $item): ?float
{
    $kp = isset($item['koko_price']) ? (float)$item['koko_price'] : 0.0;
    return $kp > 0 ? $kp : null;
}

/**
 * Subtle "pay in installments" teaser lines (KOKO / Mintpay / Payzy) shown under
 * an item's price on product cards and the item detail page — controlled by
 * Admin -> Site Configuration -> Payment Gateways toggles (enable_koko /
 * enable_mintpay / enable_payzy). These are display-only badges; the real KOKO
 * "Buy Now, Pay Later" checkout integration is configured separately under
 * Admin -> Payment Settings and is unaffected by these toggles.
 *
 * @param float      $price     The price the customer would actually pay (after any discount).
 * @param string     $variant   'detail' for the item preview page (fuller layout),
 *                              anything else ('card') for compact grid/listing cards.
 * @param float|null $kokoPrice The item's KOKO Price (Admin -> Items -> "KOKO Price (optional)"),
 *                              passed RAW (null/0 if the admin hasn't set one for this item). The
 *                              KOKO instalment line only ever appears when this is a real,
 *                              positive value AND KOKO is enabled — items without a KOKO Price set
 *                              don't show a KOKO line at all (Mintpay/PayZy are unaffected by this
 *                              and still key off the site-wide toggle alone).
 */
function payment_plan_html(float $price, string $variant = 'card', ?float $kokoPrice = null): string
{
    if ($price <= 0) { return ''; }

    $s = get_site_settings();
    $rows = '';

    if (!empty($s['enable_koko']) && $kokoPrice !== null && $kokoPrice > 0) {
        // Matches KOKO's own official WooCommerce plugin (Paykoko v2.0.11)
        // wording/markup: "or 3 X Rs <amount> with [KOKO logo]" — split into
        // 3 monthly instalments. Only the per-instalment amount is shown here
        // (never a "total"), same as KOKO's own plugin.
        $amt = number_format($kokoPrice / 3, 2);
        $logo = '<img src="' . e(koko_logo_url()) . '" alt="KOKO" class="pp-koko-logo">';
        $rows .= '<p class="pp-line pp-line-koko">or 3 X Rs. ' . $amt . ' with ' . $logo
            . '<span class="pp-info" tabindex="0" role="button" aria-label="What is KOKO?" title="KOKO — Buy Now, Pay Later. Split this purchase into 3 interest-free instalments.">'
            . '<i class="fa-solid fa-circle-info"></i></span></p>';
    }
    if (!empty($s['enable_mintpay'])) {
        $amt = number_format($price / 3, 2);
        $rows .= '<p class="pp-line pp-line-mintpay">or 3 X Rs. ' . $amt . ' with <b class="pp-mintpay">Mintpay</b></p>';
    }
    if (!empty($s['enable_payzy'])) {
        $amt = number_format($price / 4, 2);
        $rows .= '<p class="pp-line pp-line-payzy">or up to 4 x Rs. ' . $amt . ' with <b class="pp-payzy">PayZy</b></p>';
    }
    if ($rows === '') { return ''; }

    $cls = $variant === 'detail' ? 'pay-plan' : 'pay-plan pay-plan-mini';
    return '<div class="' . $cls . '">' . $rows . '</div>';
}

/** A single item for the detail page, joined with category + brand. Null if not found or unavailable. */
function get_item_detail(int $id): ?array
{
    ensure_items_table();
    $st = db()->prepare("SELECT i.*,
              c.id AS cat_id, c.name AS category_name, c.slug AS category_slug, c.parent_id AS cat_parent_id,
              b.name AS brand_name, b.image AS brand_image
            FROM items i
            LEFT JOIN categories c ON c.id = i.category_id
            LEFT JOIN brands b ON b.id = i.brand_id
            WHERE i.id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** All gallery images for an item, feature image first. */
function get_item_images(int $itemId): array
{
    ensure_items_table();
    $st = db()->prepare('SELECT * FROM item_images WHERE item_id = ? ORDER BY is_feature DESC, sort_order ASC, id ASC');
    $st->execute([$itemId]);
    return $st->fetchAll();
}

/** Other items in the same category, for the "You may also like" strip. */
function get_related_items(?int $categoryId, int $excludeId, int $limit = 5): array
{
    ensure_items_table();
    if (!$categoryId) { return []; }
    $st = db()->prepare(get_homepage_items_base_sql() . ' AND i.category_id = ? AND i.id != ? ORDER BY i.updated_at DESC LIMIT ' . (int)$limit);
    $st->execute([$categoryId, $excludeId]);
    return $st->fetchAll();
}

/** Breadcrumb chain (root → leaf) for a category id, walking parent_id up. */
function get_category_breadcrumb(?int $categoryId): array
{
    if (!$categoryId) { return []; }
    $chain = [];
    $guard = 0;
    $st = db()->prepare('SELECT id, name, slug, parent_id FROM categories WHERE id = ? LIMIT 1');
    $current = $categoryId;
    while ($current && $guard < 5) {
        $st->execute([$current]);
        $row = $st->fetch();
        if (!$row) { break; }
        array_unshift($chain, $row);
        $current = $row['parent_id'];
        $guard++;
    }
    return $chain;
}

/* ============================================================
 * Products listing / search (products.php + category.php).
 * ============================================================ */

/**
 * Builds the shared WHERE clause + bound params for the products listing,
 * used by both get_items_filtered() (the page of results) and
 * count_items_filtered() (the total, for pagination) so the two can never
 * drift out of sync.
 *
 * Recognised $filters keys (all optional):
 *   q             string   — searched against name, SKU, description
 *   category_ids  int[]    — matches items whose category_id is any of these
 *   brand_ids     int[]    — matches items whose brand_id is any of these
 *   min_price     float
 *   max_price     float
 *   condition     'new'|'old'
 *   in_stock_only bool     — true: in_stock only. false/absent: hide out_of_stock (matches homepage behaviour)
 */
function build_items_where(array $filters): array
{
    $where  = ['1=1'];
    $params = [];

    if (!empty($filters['q'])) {
        $like = '%' . $filters['q'] . '%';
        $where[] = '(i.name LIKE ? OR i.sku LIKE ? OR i.description LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    if (!empty($filters['category_ids'])) {
        $ids = array_values(array_filter(array_map('intval', $filters['category_ids'])));
        if ($ids) {
            $where[] = 'i.category_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            foreach ($ids as $id) { $params[] = $id; }
        }
    }
    if (!empty($filters['brand_ids'])) {
        $ids = array_values(array_filter(array_map('intval', $filters['brand_ids'])));
        if ($ids) {
            $where[] = 'i.brand_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            foreach ($ids as $id) { $params[] = $id; }
        }
    }
    if (isset($filters['min_price']) && $filters['min_price'] !== '') {
        $where[] = 'COALESCE(i.special_price, i.selling_price) >= ?';
        $params[] = (float)$filters['min_price'];
    }
    if (isset($filters['max_price']) && $filters['max_price'] !== '') {
        $where[] = 'COALESCE(i.special_price, i.selling_price) <= ?';
        $params[] = (float)$filters['max_price'];
    }
    if (!empty($filters['condition']) && in_array($filters['condition'], ['new', 'old'], true)) {
        $where[] = 'i.condition_type = ?';
        $params[] = $filters['condition'];
    }
    /* clothing: only items that have the chosen size(s) / colour(s) IN STOCK */
    $fSizes  = array_values(array_filter(array_map(fn($v) => mb_substr(trim((string)$v), 0, 40), (array)($filters['sizes'] ?? []))));
    $fColors = array_values(array_filter(array_map(fn($v) => mb_substr(trim((string)$v), 0, 60), (array)($filters['colors'] ?? []))));
    if ($fSizes || $fColors) {
        ensure_variation_attr_columns();
        $sub = 'SELECT 1 FROM item_variations v WHERE v.item_id = i.id AND v.stock_qty > 0';
        if ($fSizes)  { $sub .= ' AND v.size_label IN (' . implode(',', array_fill(0, count($fSizes), '?')) . ')';  array_push($params, ...$fSizes); }
        if ($fColors) { $sub .= ' AND v.color_name IN (' . implode(',', array_fill(0, count($fColors), '?')) . ')'; array_push($params, ...$fColors); }
        $where[] = "EXISTS ($sub)";
    }
    if (!empty($filters['in_stock_only'])) {
        $where[] = "i.stock_status = 'in_stock'";
    } else {
        $where[] = "i.stock_status != 'out_of_stock'";
    }

    return [implode(' AND ', $where), $params];
}

/** Sort key → ORDER BY clause, for both get_items_filtered() and any caller building its own query. */
function items_sort_order_sql(string $sort): string
{
    switch ($sort) {
        case 'price_asc':  return 'COALESCE(i.special_price, i.selling_price) ASC';
        case 'price_desc': return 'COALESCE(i.special_price, i.selling_price) DESC';
        case 'name_asc':   return 'i.name ASC';
        case 'name_desc':  return 'i.name DESC';
        default:           return 'i.created_at DESC'; // 'newest'
    }
}

/** One page of products matching $filters — see build_items_where() for recognised keys, plus 'sort', 'limit', 'offset'. */
function get_items_filtered(array $filters): array
{
    ensure_items_table();
    [$whereSql, $params] = build_items_where($filters);
    $orderSql = items_sort_order_sql($filters['sort'] ?? 'newest');
    $limit    = max(1, min(60, (int)($filters['limit'] ?? 24)));
    $offset   = max(0, (int)($filters['offset'] ?? 0));

    $sql = "SELECT i.*,
              (SELECT image_path FROM item_images WHERE item_id = i.id AND is_feature = 1 LIMIT 1) AS feature_image,
              c.name AS category_name, c.slug AS category_slug,
              b.name AS brand_name, b.image AS brand_image
            FROM items i
            LEFT JOIN categories c ON c.id = i.category_id
            LEFT JOIN brands b ON b.id = i.brand_id
            WHERE $whereSql
            ORDER BY $orderSql
            LIMIT $limit OFFSET $offset";
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Total count of products matching $filters (ignoring 'limit'/'offset') — for pagination. */
function count_items_filtered(array $filters): int
{
    ensure_items_table();
    [$whereSql, $params] = build_items_where($filters);
    $st = db()->prepare("SELECT COUNT(*) FROM items i WHERE $whereSql");
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/**
 * All category ids that should match when browsing category $id — itself
 * plus every descendant — so a parent category page also shows products
 * filed directly under its sub-categories.
 */
function get_category_ids_with_descendants(int $id): array
{
    return array_merge([$id], get_category_descendant_ids($id));
}

/* ============================================================
 * Homepage hero slideshow (Admin → Home Slides).
 * ============================================================ */
function ensure_home_slides_table(): void
{
    db()->exec("
    CREATE TABLE IF NOT EXISTS home_slides (
      id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      eyebrow       VARCHAR(150) DEFAULT NULL,
      heading       VARCHAR(300) NOT NULL,
      description   VARCHAR(500) DEFAULT NULL,
      button_text   VARCHAR(80) DEFAULT NULL,
      button_link   VARCHAR(255) DEFAULT NULL,
      button2_text  VARCHAR(80) DEFAULT NULL,
      button2_link  VARCHAR(255) DEFAULT NULL,
      image         VARCHAR(255) DEFAULT NULL,
      is_active     TINYINT(1) NOT NULL DEFAULT 1,
      sort_order    INT NOT NULL DEFAULT 0,
      created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** Slides for the homepage hero — active only by default, in admin-chosen order. */
function get_home_slides(bool $activeOnly = true): array
{
    ensure_home_slides_table();
    $sql = 'SELECT * FROM home_slides' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order ASC, id ASC';
    return db()->query($sql)->fetchAll();
}

/* ============================================================
 * Homepage "Happy Customers" strip (Admin → Happy Customers).
 * Each entry = one customer photo + their comment, shown as a
 * 4-per-row image grid on the homepage; clicking a photo opens
 * a popup with the full comment and customer name.
 * ============================================================ */
function ensure_happy_customers_table(): void
{
    db()->exec("
    CREATE TABLE IF NOT EXISTS happy_customers (
      id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      customer_name VARCHAR(150) NOT NULL,
      comment       VARCHAR(1000) NOT NULL,
      image         VARCHAR(255) DEFAULT NULL,
      is_active     TINYINT(1) NOT NULL DEFAULT 1,
      sort_order    INT NOT NULL DEFAULT 0,
      created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** Happy-customer entries for the homepage strip — active only by default, in admin-chosen order. */
function get_happy_customers(bool $activeOnly = true): array
{
    ensure_happy_customers_table();
    $sql = 'SELECT * FROM happy_customers' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order ASC, id ASC';
    return db()->query($sql)->fetchAll();
}

/* ---------- Login throttling ---------- */
function client_ip_bin(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return inet_pton($ip) ?: inet_pton('0.0.0.0');
}

function too_many_attempts(): bool
{
    $st = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip_address = ? AND attempted_at > (NOW() - INTERVAL ' . (int)LOCKOUT_MINUTES . ' MINUTE)'
    );
    $st->execute([client_ip_bin()]);
    return (int)$st->fetchColumn() >= MAX_LOGIN_ATTEMPTS;
}

function record_attempt(string $username): void
{
    $st = db()->prepare('INSERT INTO login_attempts (ip_address, username) VALUES (?, ?)');
    $st->execute([client_ip_bin(), mb_substr($username, 0, 50)]);
}

function clear_attempts(): void
{
    $st = db()->prepare('DELETE FROM login_attempts WHERE ip_address = ?');
    $st->execute([client_ip_bin()]);
}

/* ---------- Flash messages ---------- */
function flash(string $key, ?string $msg = null): ?string
{
    if ($msg !== null) {
        $_SESSION['flash'][$key] = $msg;
        return null;
    }
    $val = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $val;
}

/* ============================================================
   CUSTOMER ACCOUNTS — sign up / sign in / mobile OTP / Google
   ============================================================ */

/** Creates (or upgrades) the customers + customer_otps tables. Safe to call on every request. */
function ensure_customer_tables(): void
{
    db()->exec("
    CREATE TABLE IF NOT EXISTS customers (
      id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      first_name          VARCHAR(80)  NOT NULL,
      last_name           VARCHAR(80)  NOT NULL,
      email               VARCHAR(150) NOT NULL UNIQUE,
      mobile              VARCHAR(20)  NOT NULL UNIQUE,
      password_hash       VARCHAR(255) DEFAULT NULL,
      google_id           VARCHAR(64)  DEFAULT NULL UNIQUE,
      avatar              VARCHAR(255) DEFAULT NULL,
      mobile_verified     TINYINT(1)   NOT NULL DEFAULT 0,
      agreed_terms        TINYINT(1)   NOT NULL DEFAULT 0,
      marketing_opt_in    TINYINT(1)   NOT NULL DEFAULT 0,
      welcome_email_sent  TINYINT(1)   NOT NULL DEFAULT 0,
      is_active           TINYINT(1)   NOT NULL DEFAULT 1,
      last_login          DATETIME     DEFAULT NULL,
      created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_cust_email (email),
      INDEX idx_cust_mobile (mobile)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Address / contact details added after the initial release — self-migrate
    // existing installs without touching any saved data. Each ALTER runs in its
    // own try/catch so a privilege issue on one column can't fatal the page.
    $customerMigrations = [
        'address_line1' => "ALTER TABLE customers ADD COLUMN address_line1 VARCHAR(255) DEFAULT NULL AFTER avatar",
        'address_line2' => "ALTER TABLE customers ADD COLUMN address_line2 VARCHAR(255) DEFAULT NULL AFTER address_line1",
        'province_id'   => "ALTER TABLE customers ADD COLUMN province_id SMALLINT UNSIGNED DEFAULT NULL AFTER address_line2",
        'city_id'       => "ALTER TABLE customers ADD COLUMN city_id INT UNSIGNED DEFAULT NULL AFTER province_id",
        'postal_code'   => "ALTER TABLE customers ADD COLUMN postal_code VARCHAR(10) DEFAULT NULL AFTER city_id",
    ];
    foreach ($customerMigrations as $column => $sql) {
        if (!db_has_column('customers', $column)) {
            try {
                db()->exec($sql);
            } catch (\Throwable $e) {
                error_log("customers migration for '$column' failed: " . $e->getMessage());
            }
        }
    }

    db()->exec("
    CREATE TABLE IF NOT EXISTS customer_otps (
      id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      mobile      VARCHAR(20)  NOT NULL,
      code_hash   VARCHAR(255) NOT NULL,
      attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
      expires_at  DATETIME     NOT NULL,
      verified_at DATETIME     DEFAULT NULL,
      created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_otp_mobile (mobile)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/* ============================================================
 * Sri Lanka locations: Provinces → Districts → Cities.
 * Used for the Province/City select boxes on the customer profile
 * and checkout pages. Self-migrating + auto-seeded on first use from
 * the CSV files bundled in includes/seed/ (re-uploadable any time
 * from Admin → Locations).
 * ============================================================ */
function ensure_location_tables(): void
{
    static $done = false;
    if ($done) { return; }

    db()->exec("
    CREATE TABLE IF NOT EXISTS provinces (
      id       SMALLINT UNSIGNED PRIMARY KEY,
      name_en  VARCHAR(100) NOT NULL,
      name_si  VARCHAR(150) DEFAULT NULL,
      name_ta  VARCHAR(150) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    db()->exec("
    CREATE TABLE IF NOT EXISTS districts (
      id           SMALLINT UNSIGNED PRIMARY KEY,
      province_id  SMALLINT UNSIGNED NOT NULL,
      name_en      VARCHAR(100) NOT NULL,
      name_si      VARCHAR(150) DEFAULT NULL,
      name_ta      VARCHAR(150) DEFAULT NULL,
      INDEX idx_district_province (province_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    db()->exec("
    CREATE TABLE IF NOT EXISTS cities (
      id           INT UNSIGNED PRIMARY KEY,
      district_id  SMALLINT UNSIGNED NOT NULL,
      name_en      VARCHAR(150) NOT NULL,
      name_si      VARCHAR(200) DEFAULT NULL,
      name_ta      VARCHAR(200) DEFAULT NULL,
      postcode     VARCHAR(10) DEFAULT NULL,
      latitude     DECIMAL(10,7) DEFAULT NULL,
      longitude    DECIMAL(10,7) DEFAULT NULL,
      INDEX idx_city_district (district_id),
      INDEX idx_city_name (name_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Mark this ensure-call as done *before* auto-seeding below — import_locations_csv()
    // also calls ensure_location_tables(), and without this the two functions would
    // call each other forever on a brand-new install (tables just created, count is
    // still 0 inside the nested call too).
    $done = true;

    // First run ever: auto-seed from the bundled CSVs so the feature works
    // out of the box. Admin → Locations can re-upload updated files later.
    $count = (int)db()->query('SELECT COUNT(*) FROM provinces')->fetchColumn();
    if ($count === 0) {
        $seedDir = __DIR__ . '/seed';
        try {
            import_locations_csv(
                $seedDir . '/provinces.csv',
                $seedDir . '/districts.csv',
                $seedDir . '/cities.csv'
            );
        } catch (\Throwable $e) {
            error_log('Location auto-seed failed: ' . $e->getMessage());
        }
    }
}

/** Reads a "NULL"/" NULL"/'' cell from a CSV row as a real PHP null. */
function csv_null(?string $val): ?string
{
    $val = trim((string)$val);
    return ($val === '' || strcasecmp($val, 'NULL') === 0) ? null : $val;
}

/**
 * Replaces the provinces / districts / cities tables from three CSV files —
 * used both for the first-run auto-seed and for Admin → Locations uploads.
 * Any of the three paths can be null to leave that table untouched.
 * Expected headers (case/spacing-insensitive on the id columns):
 *   provinces.csv → provinces_id, name_en, name_si, name_ta
 *   districts.csv → district id, province_id, name_en, name_si, name_ta
 *   cities.csv    → city id, district_id, name_en, name_si, name_ta,
 *                   sub_name_en, sub_name_si, sub_name_ta, postcode, latitude, longitude
 * Returns ['provinces'=>n, 'districts'=>n, 'cities'=>n] rows imported.
 */
function import_locations_csv(?string $provincesFile, ?string $districtsFile, ?string $citiesFile): array
{
    ensure_location_tables();
    $counts = ['provinces' => 0, 'districts' => 0, 'cities' => 0];
    $pdo = db();

    if ($provincesFile && is_readable($provincesFile)) {
        $fh = fopen($provincesFile, 'r');
        fgetcsv($fh); // header
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM provinces');
            $ins = $pdo->prepare('INSERT INTO provinces (id, name_en, name_si, name_ta) VALUES (?,?,?,?)');
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) < 2 || $row[0] === '' || $row[0] === null) { continue; }
                $ins->execute([(int)$row[0], trim((string)($row[1] ?? '')), csv_null($row[2] ?? null), csv_null($row[3] ?? null)]);
                $counts['provinces']++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fclose($fh);
            throw $e;
        }
        fclose($fh);
    }

    if ($districtsFile && is_readable($districtsFile)) {
        $fh = fopen($districtsFile, 'r');
        fgetcsv($fh);
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM districts');
            $ins = $pdo->prepare('INSERT INTO districts (id, province_id, name_en, name_si, name_ta) VALUES (?,?,?,?,?)');
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) < 3 || $row[0] === '' || $row[0] === null) { continue; }
                $ins->execute([(int)$row[0], (int)$row[1], trim((string)($row[2] ?? '')), csv_null($row[3] ?? null), csv_null($row[4] ?? null)]);
                $counts['districts']++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fclose($fh);
            throw $e;
        }
        fclose($fh);
    }

    if ($citiesFile && is_readable($citiesFile)) {
        $fh = fopen($citiesFile, 'r');
        fgetcsv($fh);
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM cities');
            $ins = $pdo->prepare('INSERT INTO cities (id, district_id, name_en, name_si, name_ta, postcode, latitude, longitude) VALUES (?,?,?,?,?,?,?,?)');
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) < 3 || $row[0] === '' || $row[0] === null) { continue; }
                $ins->execute([
                    (int)$row[0], (int)$row[1], trim((string)($row[2] ?? '')),
                    csv_null($row[3] ?? null), csv_null($row[4] ?? null),
                    csv_null($row[8] ?? null),
                    csv_null($row[9] ?? null) !== null ? (float)$row[9] : null,
                    csv_null($row[10] ?? null) !== null ? (float)$row[10] : null,
                ]);
                $counts['cities']++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fclose($fh);
            throw $e;
        }
        fclose($fh);
    }

    return $counts;
}

/** All provinces, alphabetical — for the Province <select>. */
function get_provinces(): array
{
    ensure_location_tables();
    return db()->query('SELECT * FROM provinces ORDER BY name_en')->fetchAll();
}

/** Cities belonging to a given province (via its districts), alphabetical — for the cascading City <select>. */
function get_cities_by_province(int $provinceId): array
{
    ensure_location_tables();
    $st = db()->prepare('SELECT c.* FROM cities c
        INNER JOIN districts d ON d.id = c.district_id
        WHERE d.province_id = ?
        ORDER BY c.name_en');
    $st->execute([$provinceId]);
    return $st->fetchAll();
}

/** One city by id, with its district/province names joined in — for display + prefilling forms. */
function get_city(int $cityId): ?array
{
    ensure_location_tables();
    $st = db()->prepare('SELECT c.*, d.province_id, d.name_en AS district_name, p.name_en AS province_name
        FROM cities c
        INNER JOIN districts d ON d.id = c.district_id
        INNER JOIN provinces p ON p.id = d.province_id
        WHERE c.id = ? LIMIT 1');
    $st->execute([$cityId]);
    $row = $st->fetch();
    return $row ?: null;
}

/* ============================================================
 * Delivery charges (Admin → Delivery Charges): a default flat rate,
 * optionally overridden per-city via CSV upload.
 * ============================================================ */
function ensure_delivery_charges_table(): void
{
    db()->exec("
    CREATE TABLE IF NOT EXISTS delivery_charges (
      city_id         INT UNSIGNED PRIMARY KEY,
      delivery_charge DECIMAL(10,2) NOT NULL DEFAULT 0,
      updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** The delivery charge for a given city — its own rate if set, else the site-wide default. Null city id → default. */
function get_delivery_charge_for_city(?int $cityId): float
{
    ensure_delivery_charges_table();
    $siteSettings = get_site_settings();
    $default = (float)($siteSettings['default_delivery_charge'] ?? 0);

    if (!$cityId) { return $default; }

    $st = db()->prepare('SELECT delivery_charge FROM delivery_charges WHERE city_id = ? LIMIT 1');
    $st->execute([$cityId]);
    $charge = $st->fetchColumn();
    return $charge !== false ? (float)$charge : $default;
}

/** All cities with their current delivery charge (custom rate if set, else the default) — used for the downloadable template and the settings list. */
function get_delivery_charges_for_export(): array
{
    ensure_location_tables();
    ensure_delivery_charges_table();
    $default = (float)(get_site_settings()['default_delivery_charge'] ?? 0);

    $rows = db()->query("SELECT c.id AS city_id, c.name_en AS city_name, d.name_en AS district_name, p.name_en AS province_name,
                                 dc.delivery_charge
                          FROM cities c
                          INNER JOIN districts d ON d.id = c.district_id
                          INNER JOIN provinces p ON p.id = d.province_id
                          LEFT JOIN delivery_charges dc ON dc.city_id = c.id
                          ORDER BY p.name_en, d.name_en, c.name_en")->fetchAll();

    foreach ($rows as &$r) {
        $r['delivery_charge'] = $r['delivery_charge'] !== null ? (float)$r['delivery_charge'] : $default;
    }
    unset($r);
    return $rows;
}

/**
 * Bulk-sets per-city delivery charges from an uploaded CSV (columns: city_id, delivery_charge —
 * extra columns such as city_name are ignored, so the downloadable template can be re-uploaded as-is).
 * Rows with a charge that matches the current default are removed from the override table (no need
 * to store a redundant explicit override). Returns the number of rows processed.
 */
function import_delivery_charges_csv(string $filePath): int
{
    ensure_delivery_charges_table();
    if (!is_readable($filePath)) {
        throw new \RuntimeException('Could not read the uploaded file.');
    }

    $fh = fopen($filePath, 'r');
    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        throw new \RuntimeException('The file appears to be empty.');
    }
    $header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);
    $cityIdCol = array_search('city_id', $header, true);
    $chargeCol = array_search('delivery_charge', $header, true);
    if ($cityIdCol === false || $chargeCol === false) {
        fclose($fh);
        throw new \RuntimeException('The CSV must have "city_id" and "delivery_charge" column headers.');
    }

    $pdo = db();
    $count = 0;
    $pdo->beginTransaction();
    try {
        $upsert = $pdo->prepare('INSERT INTO delivery_charges (city_id, delivery_charge) VALUES (?, ?)
                                  ON DUPLICATE KEY UPDATE delivery_charge = VALUES(delivery_charge)');
        while (($row = fgetcsv($fh)) !== false) {
            if (!isset($row[$cityIdCol]) || $row[$cityIdCol] === '') { continue; }
            $cityId = (int)$row[$cityIdCol];
            $charge = isset($row[$chargeCol]) && $row[$chargeCol] !== '' ? (float)$row[$chargeCol] : 0.0;
            if ($cityId <= 0) { continue; }
            $upsert->execute([$cityId, $charge]);
            $count++;
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        fclose($fh);
        throw $e;
    }
    fclose($fh);
    return $count;
}

/** Row counts for the three location tables — shown on Admin → Locations. */
function get_location_counts(): array
{
    ensure_location_tables();
    return [
        'provinces' => (int)db()->query('SELECT COUNT(*) FROM provinces')->fetchColumn(),
        'districts' => (int)db()->query('SELECT COUNT(*) FROM districts')->fetchColumn(),
        'cities'    => (int)db()->query('SELECT COUNT(*) FROM cities')->fetchColumn(),
    ];
}

/** Email/SMS/Google settings used by registration + login (self-migrating). */
function get_email_settings(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }

    db()->exec("
    CREATE TABLE IF NOT EXISTS email_settings (
      id                       TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
      smtp_host                VARCHAR(150) DEFAULT NULL,
      smtp_port                SMALLINT UNSIGNED DEFAULT 587,
      smtp_username            VARCHAR(150) DEFAULT NULL,
      smtp_password            VARCHAR(255) DEFAULT NULL,
      smtp_encryption          ENUM('none','ssl','tls') NOT NULL DEFAULT 'tls',
      from_email               VARCHAR(150) DEFAULT NULL,
      from_name                VARCHAR(150) DEFAULT NULL,
      send_welcome_email       TINYINT(1) NOT NULL DEFAULT 1,
      welcome_email_subject    VARCHAR(200) DEFAULT 'Welcome to {{company_name}}!',
      welcome_email_body       TEXT,
      admin_notification_email VARCHAR(150) DEFAULT NULL,
      notify_admin_on_order    TINYINT(1) NOT NULL DEFAULT 1,
      sms_gateway_url          VARCHAR(255) DEFAULT NULL,
      sms_gateway_api_key      VARCHAR(255) DEFAULT NULL,
      sms_sender_id            VARCHAR(30)  DEFAULT NULL,
      require_mobile_verify    TINYINT(1) NOT NULL DEFAULT 1,
      updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Existing installs created before these columns existed — add them without touching any saved data.
    // Wrapped defensively: some hosts restrict ALTER privileges for the app's DB user, and a hard
    // failure here must not take down every page that calls get_email_settings().
    $migrations = [
        'admin_notification_email' => "ALTER TABLE email_settings ADD COLUMN admin_notification_email VARCHAR(150) DEFAULT NULL AFTER welcome_email_body",
        'notify_admin_on_order'    => "ALTER TABLE email_settings ADD COLUMN notify_admin_on_order TINYINT(1) NOT NULL DEFAULT 1 AFTER admin_notification_email",
        'sms_enabled'              => "ALTER TABLE email_settings ADD COLUMN sms_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER sms_gateway_url",
        'sms_user_id'              => "ALTER TABLE email_settings ADD COLUMN sms_user_id VARCHAR(60) DEFAULT NULL AFTER sms_enabled",
        'order_sms_enabled'        => "ALTER TABLE email_settings ADD COLUMN order_sms_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER sms_sender_id",
        'order_sms_template'       => "ALTER TABLE email_settings ADD COLUMN order_sms_template TEXT DEFAULT NULL AFTER order_sms_enabled",
    ];
    foreach ($migrations as $column => $sql) {
        if (!db_has_column('email_settings', $column)) {
            try {
                db()->exec($sql);
            } catch (\Throwable $e) {
                error_log("email_settings migration for '$column' failed: " . $e->getMessage());
            }
        }
    }

    $defaultBody = "<p>Hi {{first_name}},</p>"
        . "<p>Thanks for creating an account with <strong>{{company_name}}</strong> — we're glad to have you!</p>"
        . "<p>You can now sign in any time to track orders, save favourites and check out faster.</p>"
        . "<p style=\"margin-top:24px\">Happy shopping!<br>The {{company_name}} Team</p>";
    db()->prepare('INSERT IGNORE INTO email_settings (id, from_name, welcome_email_body) VALUES (1, ?, ?)')
        ->execute([SITE_NAME, $defaultBody]);

    $row = db()->query('SELECT * FROM email_settings WHERE id = 1')->fetch();
    $cache = $row ?: [];
    return $cache;
}

/** Sri Lankan mobile numbers: accepts 07XXXXXXXX, +947XXXXXXXX or 947XXXXXXXX → normalises to +947XXXXXXXX. Returns null if invalid. */
function normalize_lk_mobile(string $mobile): ?string
{
    $digits = preg_replace('/\D/', '', $mobile);
    if ($digits === null) { return null; }

    if (preg_match('/^0(7\d{8})$/', $digits, $m)) {           // 07XXXXXXXX
        return '+94' . $m[1];
    }
    if (preg_match('/^94(7\d{8})$/', $digits, $m)) {           // 947XXXXXXXX
        return '+94' . $m[1];
    }
    if (preg_match('/^7\d{8}$/', $digits)) {                   // 7XXXXXXXX
        return '+94' . $digits;
    }
    return null;
}

/** The SMSLenz.lk "Send SMS" endpoint (https://smslenz.lk/developers/api). */
const SMSLENZ_API_URL = 'https://smslenz.lk/api/send-sms';
const SMSLENZ_ACCOUNT_STATUS_URL = 'https://smslenz.lk/api/account-status';

/**
 * Sends an SMS through SMSLenz.lk (https://smslenz.lk), configured in
 * Admin → SMS Settings. Does nothing (and returns false) if the master
 * "Enable SMS" switch is off, or the User ID / API Key / Sender ID aren't
 * all filled in yet — the message is written to the error log instead so
 * OTP codes etc. are still visible during setup/testing.
 */
function send_sms(string $mobile, string $message): bool
{
    $settings = get_email_settings();

    if (empty($settings['sms_enabled'])) {
        error_log("SMS is turned off in Admin -> SMS Settings — message for $mobile was: $message");
        return false;
    }
    if (!sms_is_configured()) {
        error_log("SMSLenz not fully configured (User ID / API Key / Sender ID) — message for $mobile was: $message");
        return false;
    }

    try {
        $ch = curl_init(SMSLENZ_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'user_id'   => $settings['sms_user_id'],
                'api_key'   => $settings['sms_gateway_api_key'],
                'sender_id' => $settings['sms_sender_id'],
                'contact'   => $mobile, // must be +947XXXXXXXX — normalize_lk_mobile() already returns this format
                'message'   => $message,
            ]),
        ]);
        $raw = curl_exec($ch);
        $curlOk = curl_errno($ch) === 0;
        curl_close($ch);

        if (!$curlOk || $raw === false) {
            error_log('SMSLenz send failed: could not reach ' . SMSLENZ_API_URL);
            return false;
        }
        $data = json_decode($raw, true);
        if (empty($data['success'])) {
            error_log('SMSLenz send failed for ' . $mobile . ': ' . ($data['message'] ?? $raw));
            return false;
        }
        return true;
    } catch (\Throwable $e) {
        error_log('SMS send failed: ' . $e->getMessage());
        return false;
    }
}

/** True once SMS is switched on AND the SMSLenz User ID / API Key / Sender ID are all filled in. */
function sms_is_configured(): bool
{
    $settings = get_email_settings();
    return !empty($settings['sms_enabled'])
        && !empty($settings['sms_user_id'])
        && !empty($settings['sms_gateway_api_key'])
        && !empty($settings['sms_sender_id']);
}

/**
 * Calls SMSLenz.lk's "Account Status" endpoint to check the connected
 * account's credit balance — used by the "Check Balance" button in
 * Admin → SMS Settings. Returns null if not configured or unreachable.
 */
function sms_account_status(): ?array
{
    $settings = get_email_settings();
    if (empty($settings['sms_user_id']) || empty($settings['sms_gateway_api_key'])) {
        return null;
    }
    try {
        $ch = curl_init(SMSLENZ_ACCOUNT_STATUS_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'user_id' => $settings['sms_user_id'],
                'api_key' => $settings['sms_gateway_api_key'],
            ]),
        ]);
        $raw = curl_exec($ch);
        $ok  = curl_errno($ch) === 0;
        curl_close($ch);
        if (!$ok || !$raw) { return null; }

        $data = json_decode($raw, true);
        if (empty($data['success'])) { return null; }
        return $data['data'] ?? null;
    } catch (\Throwable $e) {
        error_log('SMSLenz account-status check failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Texts the customer that placed an order (Admin → SMS Settings → "Send an
 * SMS to the customer for every new order"). Silently does nothing if SMS
 * or this specific notification is turned off, or the order has no usable
 * Sri Lankan mobile number. Called right after an order is created.
 */
function send_new_order_sms(array $order): bool
{
    $settings = get_email_settings();
    if (empty($settings['sms_enabled']) || empty($settings['order_sms_enabled'])) {
        return false;
    }

    $mobile = normalize_lk_mobile((string)($order['phone'] ?? ''));
    if (!$mobile) { return false; }

    $siteSettings = get_site_settings();
    $companyName  = $siteSettings['company_name'] ?: SITE_NAME;
    $firstName    = trim(explode(' ', trim((string)$order['full_name']))[0] ?? '');

    $template = $settings['order_sms_template'] ?: default_order_sms_template();
    $message  = strtr($template, [
        '{{first_name}}'   => $firstName,
        '{{order_no}}'     => (string)$order['order_no'],
        '{{subtotal}}'     => number_format((float)$order['subtotal'], 2),
        '{{company_name}}' => $companyName,
    ]);

    return send_sms($mobile, $message);
}

/** Default message template shown/used the first time (before an admin customizes it in SMS Settings). */
function default_order_sms_template(): string
{
    return "Hi {{first_name}}, thank you for your order {{order_no}} (Rs. {{subtotal}}) from {{company_name}}. We'll text you again once it ships!";
}

/** Generates + stores (hashed) a 6-digit OTP for a mobile number, throttled to 1 per 60s. Returns null if throttled. */
function generate_and_send_otp(string $mobile): ?string
{
    ensure_customer_tables();

    $recent = db()->prepare("SELECT COUNT(*) FROM customer_otps WHERE mobile = ? AND created_at > (NOW() - INTERVAL 60 SECOND)");
    $recent->execute([$mobile]);
    if ((int)$recent->fetchColumn() > 0) {
        return null; // throttled — ask user to wait
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    db()->prepare('INSERT INTO customer_otps (mobile, code_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))')
        ->execute([$mobile, password_hash($code, PASSWORD_DEFAULT)]);

    $sent = send_sms($mobile, "Your " . SITE_NAME . " verification code is $code. It expires in 5 minutes.");
    if (!$sent) {
        error_log("[DEV] OTP for $mobile is $code (SMS gateway not configured)");
    }
    return $code; // caller decides whether to reveal this (only in ENVIRONMENT=development)
}

/** Verifies a submitted OTP code for a mobile number. */
function verify_otp(string $mobile, string $code): bool
{
    ensure_customer_tables();
    $st = db()->prepare('SELECT id, code_hash, attempts FROM customer_otps
                          WHERE mobile = ? AND expires_at > NOW() AND verified_at IS NULL
                          ORDER BY id DESC LIMIT 1');
    $st->execute([$mobile]);
    $row = $st->fetch();
    if (!$row) { return false; }

    if ((int)$row['attempts'] >= 5) { return false; } // too many tries on this code

    if (password_verify($code, $row['code_hash'])) {
        db()->prepare('UPDATE customer_otps SET verified_at = NOW() WHERE id = ?')->execute([$row['id']]);
        return true;
    }
    db()->prepare('UPDATE customer_otps SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
    return false;
}

/** Was this mobile number OTP-verified in the last 30 minutes? (checked again at registration time) */
function mobile_recently_verified(string $mobile): bool
{
    $st = db()->prepare("SELECT COUNT(*) FROM customer_otps
                          WHERE mobile = ? AND verified_at IS NOT NULL
                          AND verified_at > (NOW() - INTERVAL 30 MINUTE)");
    $st->execute([$mobile]);
    return (int)$st->fetchColumn() > 0;
}

/* ============================================================
 * Admin login 2FA — email OTP (Admin → Admin Users → Login Security).
 * Off by default; mirrors the customer mobile-OTP pattern above but
 * sends a 6-digit code to the admin's email instead of by SMS.
 * ============================================================ */
function ensure_admin_otps_table(): void
{
    db()->exec("
    CREATE TABLE IF NOT EXISTS admin_otps (
      id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      admin_id    INT UNSIGNED NOT NULL,
      code_hash   VARCHAR(255) NOT NULL,
      attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
      expires_at  DATETIME NOT NULL,
      verified_at DATETIME DEFAULT NULL,
      created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_admin_otp_admin (admin_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** How long an admin must wait between OTP resend requests. */
const ADMIN_OTP_RESEND_COOLDOWN = 30;

/** Seconds remaining before this admin can request another OTP (0 if they can request one now). */
function admin_otp_resend_cooldown_remaining(int $adminId): int
{
    ensure_admin_otps_table();
    $st = db()->prepare('SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age FROM admin_otps
                          WHERE admin_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$adminId]);
    $age = $st->fetchColumn();
    if ($age === false) { return 0; }
    $remaining = ADMIN_OTP_RESEND_COOLDOWN - (int)$age;
    return max(0, $remaining);
}

/** Generates a 6-digit code, emails it to the admin, and stores its hash. Returns null if throttled (see ADMIN_OTP_RESEND_COOLDOWN). */
function generate_and_send_admin_otp(int $adminId, string $email): ?string
{
    ensure_admin_otps_table();

    $recent = db()->prepare('SELECT COUNT(*) FROM admin_otps WHERE admin_id = ? AND created_at > (NOW() - INTERVAL ' . ADMIN_OTP_RESEND_COOLDOWN . ' SECOND)');
    $recent->execute([$adminId]);
    if ((int)$recent->fetchColumn() > 0) {
        return null; // throttled — ask the admin to wait before resending
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    db()->prepare('INSERT INTO admin_otps (admin_id, code_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))')
        ->execute([$adminId, password_hash($code, PASSWORD_DEFAULT)]);

    $emailSettings = get_email_settings();
    $siteSettings  = get_site_settings();
    $companyName   = $siteSettings['company_name'] ?: SITE_NAME;
    $fromEmail     = $emailSettings['from_email'] ?: ('no-reply@' . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'example.com'));
    $fromName      = $emailSettings['from_name'] ?: $companyName;

    $html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#faf6f6;padding:30px 0">'
          . '<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;box-shadow:0 6px 20px rgba(0,0,0,.08);text-align:center">'
          . '<h2 style="color:#c0181c;margin-bottom:6px">' . e($companyName) . ' Admin Login</h2>'
          . '<p style="color:#555;font-size:14px;margin-bottom:24px">Use this code to finish signing in to the control panel. It expires in 10 minutes.</p>'
          . '<div style="font-size:34px;font-weight:800;letter-spacing:8px;color:#1d1418;background:#faf1f1;border-radius:10px;padding:16px 0;margin-bottom:20px">' . e($code) . '</div>'
          . '<p style="color:#999;font-size:12.5px">If you did not try to sign in, you can safely ignore this email.</p>'
          . '</div></body></html>';

    $sent = send_via_configured_relay($emailSettings, $fromEmail, $fromName, $email, 'Your ' . $companyName . ' admin login code', $html);
    if (!$sent) {
        error_log("Admin login OTP email to $email failed (admin_id=$adminId) — code was: $code");
    }
    return $code;
}

/** Verifies a submitted admin login OTP code. */
function verify_admin_otp(int $adminId, string $code): bool
{
    ensure_admin_otps_table();
    $st = db()->prepare('SELECT id, code_hash, attempts FROM admin_otps
                          WHERE admin_id = ? AND expires_at > NOW() AND verified_at IS NULL
                          ORDER BY id DESC LIMIT 1');
    $st->execute([$adminId]);
    $row = $st->fetch();
    if (!$row) { return false; }

    if ((int)$row['attempts'] >= 5) { return false; } // too many tries on this code

    if (password_verify($code, $row['code_hash'])) {
        db()->prepare('UPDATE admin_otps SET verified_at = NOW() WHERE id = ?')->execute([$row['id']]);
        return true;
    }
    db()->prepare('UPDATE admin_otps SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
    return false;
}

/**
 * Adds/updates (or removes) a custom alias so the admin panel is also reachable
 * at /{customPath}/... in addition to the default /admin/... — a lightweight
 * obscurity measure, not an access-control replacement, so the original /admin
 * path is deliberately left working too (this avoids ever locking an admin out
 * over a typo). Rewrites only the clearly-marked block in .htaccess; everything
 * else in the file is left untouched.
 * Returns ['ok' => bool, 'message' => string].
 */
function update_admin_htaccess_alias(string $customPath): array
{
    $customPath = strtolower(trim($customPath, '/'));
    if ($customPath === '' || $customPath === 'admin') {
        $customPath = 'admin'; // "reset to default" — no alias needed
    } elseif (!preg_match('/^[a-z0-9\-]{3,50}$/', $customPath)) {
        return ['ok' => false, 'message' => 'Use only lowercase letters, numbers and hyphens (3–50 characters).'];
    }
    $reserved = ['assets', 'config', 'includes', 'category', 'products', 'checkout', 'cart', 'login', 'register', 'logout', 'dashboard', 'profile'];
    if (in_array($customPath, $reserved, true)) {
        return ['ok' => false, 'message' => "\"$customPath\" is a reserved path already used elsewhere on the site — choose another."];
    }

    $htaccessPath = __DIR__ . '/../.htaccess';
    if (!is_file($htaccessPath) || !is_readable($htaccessPath)) {
        return ['ok' => false, 'message' => '.htaccess not found or not readable.'];
    }
    $content = file_get_contents($htaccessPath);
    if ($content === false) {
        return ['ok' => false, 'message' => 'Could not read .htaccess.'];
    }

    $startMarker = '# BEGIN CUSTOM ADMIN PATH (auto-managed — do not edit by hand)';
    $endMarker   = '# END CUSTOM ADMIN PATH';

    $block = $startMarker . "\n";
    if ($customPath !== 'admin') {
        $block .= 'RewriteRule ^' . $customPath . '(/.*|)$ admin$1 [L]' . "\n";
    } else {
        $block .= "# (no custom path set — the default /admin URL is the only one in use)\n";
    }
    $block .= $endMarker;

    if (strpos($content, $startMarker) !== false) {
        $pattern = '/' . preg_quote($startMarker, '/') . '.*?' . preg_quote($endMarker, '/') . '/s';
        $newContent = preg_replace($pattern, $block, $content, 1);
    } else {
        // First time setting this up — insert right after "RewriteEngine On" (a stable, always-present anchor).
        $anchor = 'RewriteEngine On';
        $pos = strpos($content, $anchor);
        if ($pos === false) {
            return ['ok' => false, 'message' => '.htaccess is missing the expected "RewriteEngine On" line — could not add the alias safely.'];
        }
        $insertAt = $pos + strlen($anchor);
        $newContent = substr($content, 0, $insertAt) . "\n\n" . $block . "\n" . substr($content, $insertAt);
    }

    if (@file_put_contents($htaccessPath, $newContent) === false) {
        return ['ok' => false, 'message' => 'Could not write to .htaccess — check file permissions for the web server user.'];
    }

    return ['ok' => true, 'message' => $customPath === 'admin'
        ? 'Reset — the admin panel is reachable only at the default /admin URL again.'
        : "Saved — the admin panel is now also reachable at /$customPath (the original /admin URL still works too, as a safety net)."];
}

/** Absolute site URL (scheme + host), needed for images embedded in outgoing emails. */
function site_absolute_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

/**
 * Holds the human-readable reason the most recent send_welcome_email() call
 * failed (populated on failure, cleared at the start of each call). Read this
 * right after calling send_welcome_email() if you need to show the admin why
 * a send didn't go through.
 */
$GLOBALS['__last_mail_error'] = '';

function last_mail_error(): string
{
    return $GLOBALS['__last_mail_error'] ?? '';
}

/**
 * Sends the branded welcome email (company name + logo) after a customer registers.
 * Uses the SMTP / template settings from Admin → Email Settings. When a SMTP host is
 * configured there, mail is sent through that relay (with STARTTLS/SSL + AUTH LOGIN as
 * needed); otherwise it falls back to PHP's native mail() (fine for many shared hosts).
 */
function send_welcome_email(array $customer): bool
{
    $GLOBALS['__last_mail_error'] = '';

    $emailSettings = get_email_settings();
    if (empty($emailSettings['send_welcome_email'])) {
        $GLOBALS['__last_mail_error'] = 'Welcome emails are turned off in Admin → Email Settings.';
        return false;
    }

    $siteSettings = get_site_settings();
    $companyName  = $siteSettings['company_name'] ?: SITE_NAME;
    $logoUrl      = !empty($siteSettings['site_logo'])
        ? site_absolute_url() . $siteSettings['site_logo']
        : null;

    $replace = [
        '{{first_name}}'    => e($customer['first_name']),
        '{{last_name}}'     => e($customer['last_name']),
        '{{company_name}}'  => e($companyName),
    ];

    $subject = strtr($emailSettings['welcome_email_subject'] ?: 'Welcome to {{company_name}}!', $replace);
    $bodyHtml = strtr($emailSettings['welcome_email_body'] ?: '', $replace);

    $logoHtml = $logoUrl
        ? '<img src="' . e($logoUrl) . '" alt="' . e($companyName) . '" style="max-width:180px;margin-bottom:20px">'
        : '<h2 style="color:#c0181c;margin-bottom:20px">' . e($companyName) . '</h2>';

    $html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#faf6f6;padding:30px 0">'
          . '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;box-shadow:0 6px 20px rgba(0,0,0,.08)">'
          . '<div style="text-align:center">' . $logoHtml . '</div>'
          . '<div style="color:#1d1418;font-size:15px;line-height:1.6">' . $bodyHtml . '</div>'
          . '<hr style="border:none;border-top:1px solid #eadfe1;margin:28px 0 14px">'
          . '<p style="font-size:12px;color:#7a6c70;text-align:center">' . e($companyName) . ' &middot; ' . e(site_absolute_url()) . '</p>'
          . '</div></body></html>';

    $fromEmail = $emailSettings['from_email'] ?: ('no-reply@' . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'example.com'));
    $fromName  = $emailSettings['from_name'] ?: $companyName;

    $ok = false;

    if (!empty($emailSettings['smtp_host'])) {
        // A relay is configured — actually use it instead of ignoring it.
        try {
            $mailer = new SmtpMailer(
                (string)$emailSettings['smtp_host'],
                (int)($emailSettings['smtp_port'] ?: 587),
                (string)($emailSettings['smtp_username'] ?? ''),
                (string)($emailSettings['smtp_password'] ?? ''),
                (string)($emailSettings['smtp_encryption'] ?? 'tls')
            );
            $ok = $mailer->send($fromEmail, $fromName, $customer['email'], $subject, $html);
            if (!$ok) {
                $GLOBALS['__last_mail_error'] = $mailer->lastError ?: 'Unknown SMTP error.';
                error_log('Welcome email (SMTP) failed: ' . $GLOBALS['__last_mail_error']);
            }
        } catch (\Throwable $e) {
            $GLOBALS['__last_mail_error'] = $e->getMessage();
            error_log('Welcome email (SMTP) failed: ' . $e->getMessage());
            $ok = false;
        }
    } else {
        // No relay configured — fall back to the server's native mail() function.
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= 'From: ' . mb_encode_mimeheader($fromName) . ' <' . $fromEmail . ">\r\n";

        try {
            $ok = @mail($customer['email'], mb_encode_mimeheader($subject), $html, $headers);
            if (!$ok) {
                $lastErr = error_get_last();
                $GLOBALS['__last_mail_error'] = 'Server mail() function failed'
                    . (!empty($lastErr['message']) ? ': ' . $lastErr['message'] : ' — no SMTP relay is configured and the server\'s built-in mail() is unavailable or unconfigured (common on shared hosts). Add SMTP Host / Username / Password above to fix this.');
            }
        } catch (\Throwable $e) {
            error_log('Welcome email failed: ' . $e->getMessage());
            $GLOBALS['__last_mail_error'] = $e->getMessage();
            $ok = false;
        }
    }

    if ($ok) {
        db()->prepare('UPDATE customers SET welcome_email_sent = 1 WHERE id = ?')->execute([$customer['id']]);
    }
    return $ok;
}

/** Verifies a Google "credential" (ID token) from Google Identity Services and returns its payload, or null if invalid. */
function verify_google_id_token(string $idToken): ?array
{
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
    $ctx = stream_context_create(['http' => ['timeout' => 8]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) { return null; }

    $payload = json_decode($res, true);
    if (!is_array($payload) || empty($payload['sub']) || empty($payload['email'])) { return null; }

    $settings = get_site_settings();
    if (!empty($settings['google_client_id']) && ($payload['aud'] ?? '') !== $settings['google_client_id']) {
        return null; // token was issued for a different app
    }
    return $payload;
}

/** The logged-in customer's row, or null. */
function current_customer(): ?array
{
    if (empty($_SESSION['customer_id'])) { return null; }
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $st = db()->prepare('SELECT * FROM customers WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$_SESSION['customer_id']]);
    $cache = $st->fetch() ?: null;
    return $cache;
}

/* =====================================================================
 *  Item variations
 * ===================================================================== */

/** All variations for an item (e.g. "Red - 64GB"), in display order. */
function get_item_variations(int $itemId): array
{
    ensure_items_table();
    ensure_variation_attr_columns();
    $st = db()->prepare('SELECT * FROM item_variations WHERE item_id = ? ORDER BY sort_order ASC, id ASC');
    $st->execute([$itemId]);
    return $st->fetchAll();
}

/** One variation row, scoped to its parent item. */
function get_item_variation(int $itemId, int $variationId): ?array
{
    ensure_items_table();
    $st = db()->prepare('SELECT * FROM item_variations WHERE id = ? AND item_id = ? LIMIT 1');
    $st->execute([$variationId, $itemId]);
    return $st->fetch() ?: null;
}


/* =====================================================================
 *  Clothing variations — Size → Colour matrix with per-combination stock
 *  Each item_variations row is one combination, e.g. size "M" + colour
 *  "Black" (#111111) with its own stock / price / image. Either part may be
 *  blank: size-only items (no colours) and colour-only items both work.
 *  Rows with neither size nor colour fall back to the old flat option list.
 * ===================================================================== */

/** Adds size_label / color_name / color_hex to item_variations (safe to call often). */
function ensure_variation_attr_columns(): void
{
    static $done = false;
    if ($done) { return; }
    try {
        db()->exec("
        CREATE TABLE IF NOT EXISTS item_variations (
          id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          item_id        INT UNSIGNED NOT NULL,
          variation_name VARCHAR(150) NOT NULL,
          size_label     VARCHAR(40)  DEFAULT NULL,
          color_name     VARCHAR(60)  DEFAULT NULL,
          color_hex      VARCHAR(9)   DEFAULT NULL,
          image_path     VARCHAR(255) DEFAULT NULL,
          cost_price     DECIMAL(12,2) DEFAULT NULL,
          selling_price  DECIMAL(12,2) DEFAULT NULL,
          special_price  DECIMAL(12,2) DEFAULT NULL,
          stock_qty      INT NOT NULL DEFAULT 0,
          sort_order     INT NOT NULL DEFAULT 0,
          created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_item_variations_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $cols = [
            'size_label' => "VARCHAR(40) DEFAULT NULL AFTER variation_name",
            'color_name' => "VARCHAR(60) DEFAULT NULL AFTER size_label",
            'color_hex'  => "VARCHAR(9) DEFAULT NULL AFTER color_name",
        ];
        foreach ($cols as $col => $def) {
            if (!db_has_column('item_variations', $col)) {
                db()->exec("ALTER TABLE item_variations ADD COLUMN $col $def");
            }
        }
    } catch (\Throwable $e) {
        error_log('ensure_variation_attr_columns: ' . $e->getMessage());
    }
    $done = true;
}

/** The photo uploaded on any size of this colour (so every size of "Black" shows the black photo). */
function variation_color_image(int $itemId, string $color): ?string
{
    try {
        $st = db()->prepare("SELECT image_path FROM item_variations WHERE item_id = ? AND color_name = ? AND image_path IS NOT NULL AND image_path <> '' ORDER BY sort_order, id LIMIT 1");
        $st->execute([$itemId, $color]);
        return $st->fetchColumn() ?: null;
    } catch (\Throwable $e) { return null; }
}

/** Sort key so sizes always show in a natural order: XS < S < M < L < XL … then 28 < 30 < 32 … */
function clothing_size_sort_key(string $size): string
{
    $s = strtoupper(trim($size));
    $order = ['FREE', 'FREE SIZE', 'ONE SIZE', 'XXXS', '3XS', 'XXS', '2XS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '2XL', 'XXXL', '3XL', '4XL', '5XL', '6XL'];
    $i = array_search($s, $order, true);
    if ($i !== false) { return 'A' . str_pad((string)$i, 3, '0', STR_PAD_LEFT); }
    if (preg_match('/^(\d+(?:\.\d+)?)/', $s, $m)) { return 'B' . str_pad(number_format((float)$m[1], 2, '.', ''), 10, '0', STR_PAD_LEFT) . $s; }
    return 'C' . $s;
}

/** A safe CSS colour from the admin value ('#1a2b3c', '#abc'), or '' if invalid. */
function clean_color_hex(?string $hex): string
{
    $hex = trim((string)$hex);
    return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $hex) ? strtolower($hex) : '';
}

/** Builds the "M / Black" style display name for a size/colour combination. */
function build_variation_name(string $size, string $color): string
{
    return trim($size . ($size !== '' && $color !== '' ? ' / ' : '') . $color);
}

/**
 * Size → colour structure for the product page picker.
 * Returns ['mode' => 'matrix'|'list', 'sizes' => [...], 'colors' => [...], 'has_sizes' => bool, 'has_colors' => bool, 'total_stock' => int]
 *   sizes:  [ ['label'=>'M', 'stock'=>7, 'options'=>[ ['id','color','hex','stock','price','old_price','discount','save','image'], … ] ], … ]
 *   colors: [ ['name'=>'Black', 'hex'=>'#111111', 'stock'=>12, 'image'=>…], … ] (unique, in first-seen order)
 */
function item_variation_matrix(array $item, array $variations): array
{
    $isMatrix = false;
    foreach ($variations as $v) {
        if (trim((string)($v['size_label'] ?? '')) !== '' || trim((string)($v['color_name'] ?? '')) !== '') { $isMatrix = true; break; }
    }
    $out = ['mode' => $isMatrix ? 'matrix' : 'list', 'sizes' => [], 'colors' => [], 'has_sizes' => false, 'has_colors' => false, 'total_stock' => 0];
    if (!$isMatrix) {
        foreach ($variations as $v) { $out['total_stock'] += max(0, (int)$v['stock_qty']); }
        return $out;
    }
    $sizes = [];
    foreach ($variations as $v) {
        $size  = trim((string)($v['size_label'] ?? ''));
        $color = trim((string)($v['color_name'] ?? ''));
        $hex   = clean_color_hex($v['color_hex'] ?? '');
        $eff   = variation_effective($item, $v);
        $stock = max(0, (int)$v['stock_qty']);
        if ($size !== '')  { $out['has_sizes'] = true; }
        if ($color !== '') { $out['has_colors'] = true; }
        $key = mb_strtoupper($size);
        if (!isset($sizes[$key])) { $sizes[$key] = ['label' => $size, 'stock' => 0, 'options' => []]; }
        $sizes[$key]['stock'] += $stock;
        $sizes[$key]['options'][] = [
            'id' => (int)$v['id'], 'color' => $color, 'hex' => $hex, 'stock' => $stock,
            'price' => $eff['price'], 'old_price' => $eff['old_price'], 'discount' => $eff['discount'],
            'save' => $eff['save_amount'], 'image' => $eff['image'] ? BASE_URL . $eff['image'] : '',
        ];
        $out['total_stock'] += $stock;
        if ($color !== '') {
            $ck = mb_strtolower($color);
            if (!isset($out['colors'][$ck])) { $out['colors'][$ck] = ['name' => $color, 'hex' => $hex, 'stock' => 0, 'image' => '']; }
            $out['colors'][$ck]['stock'] += $stock;
            if ($out['colors'][$ck]['hex'] === '' && $hex !== '') { $out['colors'][$ck]['hex'] = $hex; }
            if ($out['colors'][$ck]['image'] === '' && $eff['image']) { $out['colors'][$ck]['image'] = BASE_URL . $eff['image']; }
        }
    }
    uasort($sizes, fn($a, $b) => strcmp(clothing_size_sort_key($a['label']), clothing_size_sort_key($b['label'])));
    $out['sizes']  = array_values($sizes);
    $out['colors'] = array_values($out['colors']);
    return $out;
}

/**
 * Compact size/colour availability for many items at once (product cards).
 * Returns [item_id => ['sizes' => ['S'=>3,'M'=>0,…], 'colors' => [['name','hex','stock'],…], 'total' => int]]
 */
function get_items_variation_summary(array $itemIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
    if (!$ids) { return []; }
    try {
        ensure_variation_attr_columns();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT item_id, size_label, color_name, color_hex, stock_qty FROM item_variations WHERE item_id IN ($in) ORDER BY sort_order ASC, id ASC");
        $st->execute($ids);
        $rows = $st->fetchAll();
    } catch (\Throwable $e) {
        error_log('get_items_variation_summary: ' . $e->getMessage());
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['item_id'];
        $out[$id] = $out[$id] ?? ['sizes' => [], 'colors' => [], 'total' => 0];
        $stock = max(0, (int)$r['stock_qty']);
        $out[$id]['total'] += $stock;
        $size = trim((string)$r['size_label']);
        if ($size !== '') { $out[$id]['sizes'][$size] = ($out[$id]['sizes'][$size] ?? 0) + $stock; }
        $color = trim((string)$r['color_name']);
        if ($color !== '') {
            $ck = mb_strtolower($color);
            if (!isset($out[$id]['colors'][$ck])) { $out[$id]['colors'][$ck] = ['name' => $color, 'hex' => clean_color_hex($r['color_hex']), 'stock' => 0]; }
            $out[$id]['colors'][$ck]['stock'] += $stock;
        }
    }
    foreach ($out as &$o) {
        uksort($o['sizes'], fn($a, $b) => strcmp(clothing_size_sort_key((string)$a), clothing_size_sort_key((string)$b)));
        $o['colors'] = array_values($o['colors']);
    }
    unset($o);
    return $out;
}

/**
 * Product-card extras: colour dots + size chips (crossed out when that size is sold out).
 * $summary is one entry from get_items_variation_summary() (or null).
 */
function product_card_variants_html(array $item, ?array $summary): string
{
    if (!$summary || (empty($summary['sizes']) && empty($summary['colors']))) { return ''; }
    $url  = BASE_URL . '/item.php?id=' . (int)$item['id'];
    $html = '<div class="pc-variants">';
    if (!empty($summary['colors'])) {
        $html .= '<div class="pc-swatches" aria-label="Colours">';
        foreach (array_slice($summary['colors'], 0, 5) as $c) {
            $style = $c['hex'] !== '' ? ' style="--sw:' . e($c['hex']) . '"' : '';
            $html .= '<span class="pc-swatch' . ($c['stock'] <= 0 ? ' is-out' : '') . '"' . $style . ' title="' . e($c['name'] . ($c['stock'] <= 0 ? ' — sold out' : '')) . '"></span>';
        }
        if (count($summary['colors']) > 5) { $html .= '<span class="pc-more">+' . (count($summary['colors']) - 5) . '</span>'; }
        $html .= '</div>';
    }
    if (!empty($summary['sizes'])) {
        $html .= '<div class="pc-sizes" aria-label="Sizes">';
        foreach ($summary['sizes'] as $size => $stock) {
            $size = (string)$size;
            if ($stock > 0) {
                $html .= '<a class="pc-size" href="' . e($url . '&size=' . rawurlencode($size)) . '">' . e($size) . '</a>';
            } else {
                $html .= '<span class="pc-size is-out" title="Sold out">' . e($size) . '</span>';
            }
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}

/**
 * Price/stock a variation actually sells at — falls back to the parent item's
 * own price/stock for any field the variation left blank (matches the admin
 * hint: "Leave a variation's image or price blank to fall back to the item's
 * base image / pricing above.").
 */
function variation_effective(array $item, array $variation): array
{
    $selling = ($variation['selling_price'] !== null && $variation['selling_price'] !== '')
        ? (float)$variation['selling_price'] : (float)$item['selling_price'];
    $special = ($variation['special_price'] !== null && $variation['special_price'] !== '')
        ? (float)$variation['special_price']
        : ((isset($item['special_price']) && $item['special_price'] !== null) ? (float)$item['special_price'] : null);
    $discount = ($special !== null && $selling > 0) ? (int)round((1 - ($special / $selling)) * 100) : null;

    return [
        'price'       => $special !== null ? $special : $selling,
        'old_price'   => $special !== null ? $selling : null,
        'discount'    => $discount,
        'save_amount' => $special !== null ? ($selling - $special) : null,
        'stock_qty'   => (int)$variation['stock_qty'],
        'image'       => $variation['image_path'] ?: null,
    ];
}

/* =====================================================================
 *  Shopping cart (session-based — no login required)
 *  Each line is keyed "{item_id}_{variation_id|0}" and stores only
 *  {item_id, variation_id, qty}; price/name/image/stock are always looked
 *  up fresh from the DB when the cart is read, so it can never go stale.
 * ===================================================================== */

function cart_line_key(int $itemId, ?int $variationId): string
{
    return $itemId . '_' . ($variationId ?: 0);
}

/** Adds (or increments) one line. Returns ['ok'=>bool, 'error'?=>string, ...cart_summary()]. */
function cart_add(int $itemId, ?int $variationId, int $qty = 1): array
{
    $qty = max(1, min(99, $qty));

    $item = get_item_detail($itemId);
    if (!$item) {
        return ['ok' => false, 'error' => 'That item could not be found.'];
    }

    if (($item['stock_status'] ?? 'in_stock') === 'out_of_stock') {
        return ['ok' => false, 'error' => 'Sorry, "' . $item['name'] . '" is currently out of stock.'];
    }

    if (!empty($item['has_variations'])) {
        if (!$variationId) {
            return ['ok' => false, 'error' => 'Please choose an option before adding this to your cart.'];
        }
        $variation = get_item_variation($itemId, $variationId);
        if (!$variation) {
            return ['ok' => false, 'error' => 'That option is no longer available.'];
        }
    } else {
        $variationId = null;
        $variation   = null;
    }

    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    $key = cart_line_key($itemId, $variationId);
    $existingQty = (int)($_SESSION['cart'][$key]['qty'] ?? 0);

    /* per size/colour stock — never let the cart hold more than is available */
    if ($variation) {
        $avail = max(0, (int)$variation['stock_qty']);
        $label = $variation['variation_name'] ?: 'this option';
        if ($avail <= 0) {
            return ['ok' => false, 'error' => 'Sorry, ' . $label . ' is sold out.'];
        }
        if ($existingQty + $qty > $avail) {
            return ['ok' => false, 'error' => 'Only ' . $avail . ' left in ' . $label . ($existingQty ? ' (you already have ' . $existingQty . ' in your cart).' : '.')];
        }
    }
    $_SESSION['cart'][$key] = [
        'item_id'      => $itemId,
        'variation_id' => $variationId,
        'qty'          => min(99, $existingQty + $qty),
    ];

    return ['ok' => true] + cart_summary();
}

/** Sets a line's quantity; 0 removes it. */
function cart_update_qty(string $key, int $qty): array
{
    if (empty($_SESSION['cart'][$key])) {
        return ['ok' => false, 'error' => 'That item is not in your cart.'] + cart_summary();
    }
    $qty = max(0, min(99, $qty));
    $line = $_SESSION['cart'][$key];
    if ($qty > 0 && !empty($line['variation_id'])) {
        $variation = get_item_variation((int)$line['item_id'], (int)$line['variation_id']);
        $avail = $variation ? max(0, (int)$variation['stock_qty']) : 0;
        if ($qty > $avail) {
            if ($avail <= 0) { unset($_SESSION['cart'][$key]); return ['ok' => false, 'error' => 'Sorry, that size / colour just sold out.'] + cart_summary(); }
            $_SESSION['cart'][$key]['qty'] = $avail;
            return ['ok' => false, 'error' => 'Only ' . $avail . ' left in ' . ($variation['variation_name'] ?: 'this option') . '.'] + cart_summary();
        }
    }
    if ($qty === 0) {
        unset($_SESSION['cart'][$key]);
    } else {
        $_SESSION['cart'][$key]['qty'] = $qty;
    }
    return ['ok' => true] + cart_summary();
}

/** Removes one line entirely. */
function cart_remove(string $key): array
{
    unset($_SESSION['cart'][$key]);
    return ['ok' => true] + cart_summary();
}

/** Empties the cart. */
function cart_clear(): array
{
    $_SESSION['cart'] = [];
    return ['ok' => true] + cart_summary();
}

/** Enriches the raw session lines with live name/price/image/stock and totals. */
function cart_summary(): array
{
    $lines    = [];
    $count    = 0;
    $subtotal = 0.0;
    $kokoSubtotal = 0.0;

    if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
        foreach ($_SESSION['cart'] as $key => $line) {
            $item = get_item_detail((int)($line['item_id'] ?? 0));
            if (!$item) { continue; } // item was deleted since it was added

            $qty   = (int)($line['qty'] ?? 0);
            if ($qty < 1) { continue; }

            $label = $item['name'];
            $image = null;
            $images = get_item_images((int)$item['id']);
            if ($images) { $image = $images[0]['image_path']; }

            $priceInfo   = item_price_info($item);
            $variationId = !empty($line['variation_id']) ? (int)$line['variation_id'] : null;

            if ($variationId) {
                $variation = get_item_variation((int)$item['id'], $variationId);
                if (!$variation) { continue; } // variation was deleted since it was added
                $eff = variation_effective($item, $variation);
                $priceInfo = ['price' => $eff['price'], 'old_price' => $eff['old_price']];
                $label .= ' — ' . $variation['variation_name'];
                if (!$eff['image'] && trim((string)($variation['color_name'] ?? '')) !== '') {
                    $eff['image'] = variation_color_image((int)$item['id'], (string)$variation['color_name']);
                }
                if ($eff['image']) { $image = $eff['image']; }
            }

            $lineTotal = $priceInfo['price'] * $qty;
            $count    += $qty;
            $subtotal += $lineTotal;

            // KOKO Price (Admin -> Items) — a flat, per-item override used only when the
            // customer pays via KOKO; falls back to the regular price when not set.
            $kokoPrice     = koko_effective_price($item, (float)$priceInfo['price']);
            $kokoLineTotal = $kokoPrice * $qty;
            $kokoSubtotal += $kokoLineTotal;

            $lines[] = [
                'key'          => $key,
                'item_id'      => $item['id'],
                'variation_id' => $variationId,
                'name'         => $label,
                'image'        => $image,
                'price'        => $priceInfo['price'],
                'qty'          => $qty,
                'line_total'   => $lineTotal,
                'koko_price'      => $kokoPrice,
                'koko_line_total' => $kokoLineTotal,
                'url'          => BASE_URL . '/item.php?id=' . $item['id'],
            ];
        }
    }

    return ['items' => $lines, 'count' => $count, 'subtotal' => $subtotal, 'koko_subtotal' => $kokoSubtotal];
}

/* =====================================================================
 *  Orders / Checkout
 * ===================================================================== */

/** Creates (or upgrades) the orders + order_items tables. Safe to call on every request. */
function ensure_orders_table(): void
{
    ensure_items_table();
    ensure_customer_tables();
    db()->exec("
    CREATE TABLE IF NOT EXISTS orders (
      id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_no                 VARCHAR(20)  NOT NULL UNIQUE,
      customer_id              INT UNSIGNED DEFAULT NULL,
      full_name                VARCHAR(150) NOT NULL,
      email                    VARCHAR(150) NOT NULL,
      phone                    VARCHAR(30)  NOT NULL,
      address                  TEXT NOT NULL,
      city                     VARCHAR(100) NOT NULL,
      notes                    TEXT DEFAULT NULL,
      payment_method           VARCHAR(30)  NOT NULL DEFAULT 'cod',
      subtotal                 DECIMAL(12,2) NOT NULL DEFAULT 0,
      status                   ENUM('pending','confirmed','processing','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
      confirmation_email_sent  TINYINT(1) NOT NULL DEFAULT 0,
      created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_order_no (order_no),
      INDEX idx_order_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    if (!db_has_column('orders', 'province')) {
        try {
            db()->exec("ALTER TABLE orders ADD COLUMN province VARCHAR(50) DEFAULT NULL AFTER city");
        } catch (\Throwable $e) {
            error_log("orders migration for 'province' failed: " . $e->getMessage());
        }
    }
    if (!db_has_column('orders', 'delivery_charge')) {
        try {
            db()->exec("ALTER TABLE orders ADD COLUMN delivery_charge DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subtotal");
        } catch (\Throwable $e) {
            error_log("orders migration for 'delivery_charge' failed: " . $e->getMessage());
        }
    }
    /* Online payment gateway (Genie Business Connect) tracking */
    foreach ([
        'payment_status'    => "VARCHAR(20) NOT NULL DEFAULT 'unpaid' AFTER payment_method",   // unpaid | pending | paid | failed
        'payment_provider'  => "VARCHAR(30) DEFAULT NULL AFTER payment_status",
        'payment_txn_id'    => "VARCHAR(100) DEFAULT NULL AFTER payment_provider",
        'payment_url'       => "TEXT DEFAULT NULL AFTER payment_txn_id",
        'payment_paid_at'   => "TIMESTAMP NULL DEFAULT NULL AFTER payment_url",
    ] as $col => $def) {
        if (!db_has_column('orders', $col)) {
            try {
                db()->exec("ALTER TABLE orders ADD COLUMN $col $def");
            } catch (\Throwable $e) {
                error_log("orders migration for '$col' failed: " . $e->getMessage());
            }
        }
    }
    /* Courier / shipment tracking — current snapshot lives on the order row,
       the full history (one row per status change) lives in order_status_history. */
    foreach ([
        'courier_name' => "VARCHAR(100) DEFAULT NULL AFTER payment_paid_at",
        'tracking_no'  => "VARCHAR(100) DEFAULT NULL AFTER courier_name",
        'shipped_at'   => "DATE NULL DEFAULT NULL AFTER tracking_no",
    ] as $col => $def) {
        if (!db_has_column('orders', $col)) {
            try {
                db()->exec("ALTER TABLE orders ADD COLUMN $col $def");
            } catch (\Throwable $e) {
                error_log("orders migration for '$col' failed: " . $e->getMessage());
            }
        }
    }
    db()->exec("
    CREATE TABLE IF NOT EXISTS order_items (
      id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id     INT UNSIGNED NOT NULL,
      item_id      INT UNSIGNED DEFAULT NULL,
      variation_id INT UNSIGNED DEFAULT NULL,
      name         VARCHAR(300) NOT NULL,
      image_path   VARCHAR(255) DEFAULT NULL,
      price        DECIMAL(12,2) NOT NULL,
      qty          INT NOT NULL,
      line_total   DECIMAL(12,2) NOT NULL,
      CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    /* One row per status change — lets the admin back-date a status ("update date")
       and, for shipped/couriered orders, records the courier + tracking number that
       applied at that point in time. Powers both the admin timeline and the public
       track-order page. */
    db()->exec("
    CREATE TABLE IF NOT EXISTS order_status_history (
      id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id     INT UNSIGNED NOT NULL,
      status       ENUM('pending','confirmed','processing','shipped','delivered','cancelled') NOT NULL,
      status_date  DATE NOT NULL,
      courier_name VARCHAR(100) DEFAULT NULL,
      tracking_no  VARCHAR(100) DEFAULT NULL,
      note         VARCHAR(255) DEFAULT NULL,
      created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_order_status_history_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
      INDEX idx_osh_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    /* Manual payment records (cash / bank transfer / cheque / etc. entered by
       an admin) — one row per payment received. orders.payment_status is a
       rollup recomputed from the sum of these rows (see
       recompute_order_payment_status()), except while a payment gateway
       (e.g. Genie) owns the order — those update payment_status directly and
       automatically via their webhook, never through this table. */
    db()->exec("
    CREATE TABLE IF NOT EXISTS order_payments (
      id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id      INT UNSIGNED NOT NULL,
      method        VARCHAR(30) NOT NULL DEFAULT 'cash',
      amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
      reference_no  VARCHAR(100) DEFAULT NULL,
      paid_date     DATE NOT NULL,
      note          VARCHAR(255) DEFAULT NULL,
      proof_path    VARCHAR(255) DEFAULT NULL,
      recorded_by   VARCHAR(100) DEFAULT NULL,
      created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_order_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
      INDEX idx_op_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** A short, unguessable order reference, e.g. ECL9F3A2B1C. */
function generate_order_no(): string
{
    do {
        $no = 'ECL' . strtoupper(bin2hex(random_bytes(4)));
        $st = db()->prepare('SELECT id FROM orders WHERE order_no = ? LIMIT 1');
        $st->execute([$no]);
    } while ($st->fetch());
    return $no;
}

/**
 * Validates the checkout form, then creates an order (+ line items) snapshotted
 * from the current session cart. Does NOT clear the cart or send any email —
 * the caller (checkout.php) does both once this returns ok.
 */
function create_order(array $fields): array
{
    ensure_orders_table();

    $cart = cart_summary();
    if (empty($cart['items'])) {
        return ['ok' => false, 'error' => 'Your cart is empty.'];
    }

    $fullName = trim((string)($fields['full_name'] ?? ''));
    $email    = trim((string)($fields['email'] ?? ''));
    $phone    = trim((string)($fields['phone'] ?? ''));
    $address  = trim((string)($fields['address'] ?? ''));
    $province = trim((string)($fields['province'] ?? ''));
    $city     = trim((string)($fields['city'] ?? ''));
    $cityId   = (int)($fields['city_id'] ?? 0) ?: null;
    $notes    = trim((string)($fields['notes'] ?? ''));
    $payment  = (string)($fields['payment_method'] ?? 'cod');

    if ($fullName === '' || $address === '' || $province === '' || $city === '') {
        return ['ok' => false, 'error' => 'Please fill in your name, address, province and city.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Please enter a valid email address.'];
    }
    if (strlen(preg_replace('/\D/', '', $phone)) < 9) {
        return ['ok' => false, 'error' => 'Please enter a valid phone number.'];
    }

    $allowedPayments = ['cod', 'bank_transfer', 'koko', 'frimi', 'card', 'genie'];
    if (!in_array($payment, $allowedPayments, true)) { $payment = 'cod'; }

    // If paying via KOKO, charge the KOKO Price (Admin -> Items) for every line instead of
    // the regular price — this is what actually gets signed and sent to KOKO's orderCreate
    // endpoint, so the amount KOKO collects always matches what was shown on the product
    // pages/checkout summary when KOKO was selected.
    $useKoko        = $payment === 'koko';
    $orderSubtotal  = $useKoko ? $cart['koko_subtotal'] : $cart['subtotal'];

    // Delivery charge is always computed here, server-side, from the resolved city id —
    // never trusted from the client — so it can't be tampered with in the browser.
    $deliveryCharge = get_delivery_charge_for_city($cityId);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $orderNo  = generate_order_no();
        $customer = current_customer();

        $pdo->prepare('INSERT INTO orders (order_no, customer_id, full_name, email, phone, address, city, province, notes, payment_method, subtotal, delivery_charge, status)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'pending\')')
            ->execute([
                $orderNo, $customer['id'] ?? null, $fullName, $email, $phone, $address, $city, $province ?: null, $notes ?: null,
                $payment, $orderSubtotal, $deliveryCharge,
            ]);
        $orderId = (int)$pdo->lastInsertId();

        /* lock + check size/colour stock, then take it off — stops two customers buying the last one */
        $lockStock = $pdo->prepare('SELECT stock_qty, variation_name FROM item_variations WHERE id = ? AND item_id = ? FOR UPDATE');
        $takeStock = $pdo->prepare('UPDATE item_variations SET stock_qty = stock_qty - ? WHERE id = ? AND item_id = ?');
        foreach ($cart['items'] as $line) {
            if (empty($line['variation_id'])) { continue; }
            $lockStock->execute([(int)$line['variation_id'], (int)$line['item_id']]);
            $row = $lockStock->fetch();
            if (!$row || (int)$row['stock_qty'] < (int)$line['qty']) {
                $pdo->rollBack();
                $left = $row ? max(0, (int)$row['stock_qty']) : 0;
                return ['ok' => false, 'error' => $left > 0
                    ? 'Only ' . $left . ' left of "' . $line['name'] . '" — please lower the quantity in your cart.'
                    : '"' . $line['name'] . '" just sold out — please remove it from your cart.'];
            }
            $takeStock->execute([(int)$line['qty'], (int)$line['variation_id'], (int)$line['item_id']]);
        }

        $ins = $pdo->prepare('INSERT INTO order_items (order_id, item_id, variation_id, name, image_path, price, qty, line_total)
                               VALUES (?,?,?,?,?,?,?,?)');
        foreach ($cart['items'] as $line) {
            $ins->execute([
                $orderId, $line['item_id'], $line['variation_id'], $line['name'], $line['image'],
                $useKoko ? $line['koko_price'] : $line['price'], $line['qty'],
                $useKoko ? $line['koko_line_total'] : $line['line_total'],
            ]);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        error_log('create_order failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Something went wrong placing your order. Please try again.'];
    }

    return ['ok' => true, 'order_no' => $orderNo, 'order_id' => $orderId];
}

/** One order + its line items. Pass $email to scope guest lookups so order numbers alone can't be browsed. */
function get_order_by_no(string $orderNo, ?string $email = null): ?array
{
    ensure_orders_table();
    $sql    = 'SELECT * FROM orders WHERE order_no = ?';
    $params = [$orderNo];
    if ($email !== null) {
        $sql     .= ' AND email = ?';
        $params[] = $email;
    }
    $st = db()->prepare($sql . ' LIMIT 1');
    $st->execute($params);
    $order = $st->fetch();
    if (!$order) { return null; }

    $st2 = db()->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
    $st2->execute([$order['id']]);
    $order['items'] = $st2->fetchAll();
    return $order;
}

/** All orders placed by a logged-in customer, newest first — used by the customer dashboard. */
function get_customer_orders(int $customerId, int $limit = 50): array
{
    ensure_orders_table();
    $st = db()->prepare('SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT ' . max(1, min(200, $limit)));
    $st->execute([$customerId]);
    return $st->fetchAll();
}

/** One order (+ items) by its numeric id — used by the admin order detail view. */
function get_order_by_id(int $orderId): ?array
{
    ensure_orders_table();
    $st = db()->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $st->execute([$orderId]);
    $order = $st->fetch();
    if (!$order) { return null; }

    $st2 = db()->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
    $st2->execute([$order['id']]);
    $order['items'] = $st2->fetchAll();
    return $order;
}

/** All valid order statuses, in their natural progression order. */
function order_statuses(): array
{
    return ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];
}

/**
 * Orders for the admin list, newest first. Optionally filtered by status and/or
 * a free-text search across order no / customer name / email / phone.
 */
function get_orders_admin(?string $status = null, string $search = '', int $limit = 200): array
{
    ensure_orders_table();
    $sql    = 'SELECT * FROM orders WHERE 1=1';
    $params = [];

    if ($status && in_array($status, order_statuses(), true)) {
        $sql     .= ' AND status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $sql .= ' AND (order_no LIKE ? OR full_name LIKE ? OR email LIKE ? OR phone LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY created_at DESC LIMIT ' . max(1, min(500, $limit));

    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Order counts per status, for the admin filter tabs. */
function get_order_status_counts(): array
{
    ensure_orders_table();
    $counts = array_fill_keys(order_statuses(), 0);
    $counts['all'] = 0;
    $rows = db()->query('SELECT status, COUNT(*) c FROM orders GROUP BY status')->fetchAll();
    foreach ($rows as $r) {
        $counts[$r['status']] = (int)$r['c'];
        $counts['all']       += (int)$r['c'];
    }
    return $counts;
}

/** Updates an order's status (admin action). Returns false if the status isn't recognised. */
function update_order_status(int $orderId, string $status): bool
{
    if (!in_array($status, order_statuses(), true)) { return false; }
    ensure_orders_table();
    $prev = order_current_status($orderId);
    $st = db()->prepare('UPDATE orders SET status = ? WHERE id = ?');
    $ok = $st->execute([$status, $orderId]);
    if ($ok) { order_sync_stock_on_status_change(db(), $orderId, $prev, $status); }
    return $ok;
}

/** Current status of one order ('' if not found). */
function order_current_status(int $orderId): string
{
    $st = db()->prepare('SELECT status FROM orders WHERE id = ?');
    $st->execute([$orderId]);
    return (string)($st->fetchColumn() ?: '');
}

/**
 * Size/colour stock follows cancellations: cancelling an order puts its
 * variation quantities back on the shelf; un-cancelling takes them off again.
 */
function order_sync_stock_on_status_change(\PDO $pdo, int $orderId, string $from, string $to): void
{
    if ($from === $to || ($from !== 'cancelled' && $to !== 'cancelled')) { return; }
    $sign = $to === 'cancelled' ? 1 : -1;
    try {
        $st = $pdo->prepare('SELECT item_id, variation_id, qty FROM order_items WHERE order_id = ? AND variation_id IS NOT NULL');
        $st->execute([$orderId]);
        $up = $pdo->prepare('UPDATE item_variations SET stock_qty = GREATEST(0, stock_qty + ?) WHERE id = ? AND item_id = ?');
        foreach ($st->fetchAll() as $r) {
            $up->execute([$sign * (int)$r['qty'], (int)$r['variation_id'], (int)$r['item_id']]);
        }
    } catch (\Throwable $e) {
        error_log('order_sync_stock_on_status_change: ' . $e->getMessage());
    }
}

/** Human-readable labels for each order status, in their natural progression order. */
function order_status_labels(): array
{
    return [
        'pending'    => 'Pending',
        'confirmed'  => 'Confirmed',
        'processing' => 'Processing',
        'shipped'    => 'Shipped',
        'delivered'  => 'Delivered',
        'cancelled'  => 'Cancelled',
    ];
}

/**
 * Full admin status update: changes the order's status, records it in
 * order_status_history (with an admin-chosen date, so past updates can be
 * logged retroactively), and — when courier details are supplied (typically
 * when moving to "shipped") — stamps them onto the order as the current
 * tracking snapshot. Returns false if the status or date is invalid.
 */
function update_order_status_full(
    int $orderId,
    string $status,
    string $statusDate,
    ?string $courierName = null,
    ?string $trackingNo = null,
    ?string $note = null
): bool {
    if (!in_array($status, order_statuses(), true)) { return false; }
    $d = \DateTime::createFromFormat('Y-m-d', $statusDate);
    if (!$d || $d->format('Y-m-d') !== $statusDate) { $statusDate = date('Y-m-d'); }

    ensure_orders_table();

    $courierName = ($courierName !== null && trim($courierName) !== '') ? trim($courierName) : null;
    $trackingNo  = ($trackingNo  !== null && trim($trackingNo)  !== '') ? trim($trackingNo)  : null;
    $note        = ($note        !== null && trim($note)        !== '') ? trim($note)        : null;

    $pdo = db();
    $prevStatus = order_current_status($orderId);
    $pdo->beginTransaction();
    try {
        $sql    = 'UPDATE orders SET status = ?';
        $params = [$status];
        if ($courierName !== null) { $sql .= ', courier_name = ?'; $params[] = $courierName; }
        if ($trackingNo  !== null) { $sql .= ', tracking_no = ?';  $params[] = $trackingNo; }
        if ($status === 'shipped') { $sql .= ', shipped_at = ?';   $params[] = $statusDate; }
        $sql     .= ' WHERE id = ?';
        $params[] = $orderId;
        $pdo->prepare($sql)->execute($params);

        $pdo->prepare('INSERT INTO order_status_history (order_id, status, status_date, courier_name, tracking_no, note)
                        VALUES (?,?,?,?,?,?)')
            ->execute([$orderId, $status, $statusDate, $courierName, $trackingNo, $note]);

        order_sync_stock_on_status_change($pdo, $orderId, $prevStatus, $status);

        $pdo->commit();
        return true;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        error_log('update_order_status_full failed: ' . $e->getMessage());
        return false;
    }
}

/** Full status timeline for one order, oldest first — used by the admin order view and the public tracking page. */
function get_order_status_history(int $orderId): array
{
    ensure_orders_table();
    $st = db()->prepare('SELECT * FROM order_status_history WHERE order_id = ? ORDER BY status_date ASC, id ASC');
    $st->execute([$orderId]);
    return $st->fetchAll();
}

/** Deletes an order (line items + status history + payments cascade via FK). Returns false if the order doesn't exist. */
function delete_order(int $orderId): bool
{
    ensure_orders_table();
    $st = db()->prepare('SELECT id FROM orders WHERE id = ?');
    $st->execute([$orderId]);
    if (!$st->fetch()) { return false; }
    return db()->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
}

/* =====================================================================
 *  Payment status / manual payments
 * ===================================================================== */

/** Human-readable labels for orders.payment_status. */
function payment_status_labels(): array
{
    return [
        'unpaid'  => 'Unpaid',
        'pending' => 'Payment Pending',
        'partial' => 'Partially Paid',
        'paid'    => 'Paid',
        'failed'  => 'Payment Failed',
    ];
}

/** Human-readable labels for payment method / type (checkout methods + manual-entry-only methods). */
function payment_method_labels(): array
{
    return [
        'cod'           => 'Cash on Delivery',
        'cash'          => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'cheque'        => 'Cheque',
        'koko'          => 'KOKO',
        'frimi'         => 'FRIMI',
        'card'          => 'Card',
        'genie'         => 'Card (Genie Business)',
        'other'         => 'Other',
    ];
}

/** Order total (subtotal + delivery charge) — what a payment is measured against. */
function order_grand_total(array $order): float
{
    return (float)$order['subtotal'] + (float)($order['delivery_charge'] ?? 0);
}

/** All manual payments recorded against an order, oldest first. */
function get_order_payments(int $orderId): array
{
    ensure_orders_table();
    $st = db()->prepare('SELECT * FROM order_payments WHERE order_id = ? ORDER BY paid_date ASC, id ASC');
    $st->execute([$orderId]);
    return $st->fetchAll();
}

/**
 * Recomputes orders.payment_status from the sum of its order_payments rows,
 * UNLESS the order is owned by an automatic payment gateway (payment_provider
 * set, e.g. 'genie') — those are only ever updated by their own webhook, so a
 * manually-recorded note never overrides a gateway's live status.
 */
function recompute_order_payment_status(int $orderId): void
{
    $st = db()->prepare('SELECT * FROM orders WHERE id = ?');
    $st->execute([$orderId]);
    $order = $st->fetch();
    if (!$order) { return; }
    if (!empty($order['payment_provider'])) { return; } // gateway-owned — leave alone

    $st2 = db()->prepare('SELECT COALESCE(SUM(amount),0) s FROM order_payments WHERE order_id = ?');
    $st2->execute([$orderId]);
    $paid  = (float)$st2->fetch()['s'];
    $total = order_grand_total($order);

    if ($paid <= 0) {
        $status = 'unpaid';
    } elseif ($paid + 0.01 >= $total) {
        $status = 'paid';
    } else {
        $status = 'partial';
    }

    $paidAtSql = $status === 'paid' ? 'NOW()' : 'payment_paid_at';
    db()->prepare("UPDATE orders SET payment_status = ?, payment_paid_at = $paidAtSql WHERE id = ?")
        ->execute([$status, $orderId]);
}

/**
 * Records a manually-entered payment (cash / bank transfer / cheque / etc.)
 * against an order and rolls up orders.payment_status from the total
 * recorded so far. Returns the new payment id, or false on failure.
 */
function add_order_payment(
    int $orderId,
    string $method,
    float $amount,
    ?string $referenceNo,
    string $paidDate,
    ?string $note,
    ?string $proofPath,
    ?string $recordedBy
) {
    ensure_orders_table();
    if ($amount <= 0) { return false; }
    $d = \DateTime::createFromFormat('Y-m-d', $paidDate);
    if (!$d || $d->format('Y-m-d') !== $paidDate) { $paidDate = date('Y-m-d'); }

    $ok = db()->prepare('INSERT INTO order_payments (order_id, method, amount, reference_no, paid_date, note, proof_path, recorded_by)
                          VALUES (?,?,?,?,?,?,?,?)')
        ->execute([
            $orderId, $method, $amount, $referenceNo ?: null, $paidDate, $note ?: null, $proofPath ?: null, $recordedBy ?: null,
        ]);
    if (!$ok) { return false; }
    $id = (int)db()->lastInsertId();
    recompute_order_payment_status($orderId);
    return $id;
}

/** Deletes one manually-recorded payment (and its uploaded proof file, if any), then rolls up payment_status again. */
function delete_order_payment(int $paymentId): bool
{
    $st = db()->prepare('SELECT * FROM order_payments WHERE id = ?');
    $st->execute([$paymentId]);
    $payment = $st->fetch();
    if (!$payment) { return false; }

    $ok = db()->prepare('DELETE FROM order_payments WHERE id = ?')->execute([$paymentId]);
    if ($ok) {
        if (!empty($payment['proof_path'])) {
            $f = __DIR__ . '/../' . ltrim((string)$payment['proof_path'], '/');
            if (is_file($f)) { @unlink($f); }
        }
        recompute_order_payment_status((int)$payment['order_id']);
    }
    return $ok;
}

/**
 * Validates + saves an uploaded payment proof (image or PDF) to
 * /assets/uploads/payments. Returns [path_or_null, error_or_null] — mirrors
 * the upload-helper pattern used elsewhere in the admin (e.g. brands.php).
 */
function process_payment_proof_upload(string $field): array
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Payment proof upload failed — please try again.'];
    }
    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) {
        return [null, 'Payment proof is too large (max 5MB).'];
    }
    $allowedExt = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
    $ext = strtolower(pathinfo((string)$_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!isset($allowedExt[$ext])) {
        return [null, 'Payment proof must be a JPG, PNG, WEBP or PDF.'];
    }
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($_FILES[$field]['tmp_name']);
        if ($mime && !in_array($mime, $allowedExt, true)) {
            return [null, 'That file does not look like a valid image or PDF.'];
        }
    }
    $dir = __DIR__ . '/../assets/uploads/payments';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "php_flag engine off\n<FilesMatch \"\\.php$\">\nRequire all denied\n</FilesMatch>\n"); }

    $filename = 'pay_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $filename)) {
        return [null, 'Could not save the payment proof.'];
    }
    return ['/assets/uploads/payments/' . $filename, null];
}

/** Emails the customer a full order confirmation (items, totals, delivery details), using Admin → Email Settings. */
function send_order_confirmation_email(array $order): bool
{
    $GLOBALS['__last_mail_error'] = '';

    $emailSettings = get_email_settings();
    $siteSettings  = get_site_settings();
    $companyName   = $siteSettings['company_name'] ?: SITE_NAME;

    $rows = '';
    foreach ($order['items'] as $it) {
        $rows .= '<tr>'
            . '<td style="padding:10px 8px;border-bottom:1px solid #eee">' . htmlspecialchars($it['name']) . '</td>'
            . '<td style="padding:10px 8px;border-bottom:1px solid #eee;text-align:center">' . (int)$it['qty'] . '</td>'
            . '<td style="padding:10px 8px;border-bottom:1px solid #eee;text-align:right">Rs. ' . number_format((float)$it['price'], 2) . '</td>'
            . '<td style="padding:10px 8px;border-bottom:1px solid #eee;text-align:right">Rs. ' . number_format((float)$it['line_total'], 2) . '</td>'
            . '</tr>';
    }

    $paymentLabels = [
        'cod' => 'Cash on Delivery', 'bank_transfer' => 'Bank Transfer',
        'koko' => 'KOKO', 'frimi' => 'FRIMI', 'card' => 'Card', 'genie' => 'Card (Genie Business)',
    ];
    $firstName = trim(explode(' ', trim((string)$order['full_name']))[0] ?? '');
    $logoUrl   = !empty($siteSettings['site_logo']) ? site_absolute_url() . $siteSettings['site_logo'] : null;
    $headerHtml = $logoUrl
        ? '<img src="' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($companyName) . '" style="max-height:44px">'
        : '<h1 style="color:#fff;font-size:20px;margin:0">' . htmlspecialchars($companyName) . '</h1>';
    $trackUrl = site_absolute_url() . BASE_URL . '/track-order?order_no=' . urlencode((string)$order['order_no']) . '&email=' . urlencode((string)$order['email']);

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto">'
        . '<div style="background:#7a0d0d;padding:20px 24px">' . $headerHtml . '</div>'
        . '<div style="padding:24px">'
        . '<h2 style="font-size:18px;margin:0 0 6px">Thanks for your order' . ($firstName ? ', ' . htmlspecialchars($firstName) : '') . '!</h2>'
        . '<p style="color:#666;margin:0 0 20px">Order <strong>' . htmlspecialchars((string)$order['order_no']) . '</strong> has been received and is now <strong>pending confirmation</strong>.</p>'
        . '<table style="width:100%;border-collapse:collapse;margin-bottom:20px"><thead><tr style="background:#f7f1f1">'
        . '<th style="padding:10px 8px;text-align:left">Item</th><th style="padding:10px 8px">Qty</th><th style="padding:10px 8px;text-align:right">Price</th><th style="padding:10px 8px;text-align:right">Total</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>'
        . '<p style="text-align:right;font-size:16px;font-weight:bold;margin:0 0 24px">Subtotal: Rs. ' . number_format((float)$order['subtotal'], 2) . '</p>'
        . '<h3 style="font-size:14px;margin:0 0 6px">Delivery Details</h3>'
        . '<p style="color:#444;margin:0 0 4px">' . nl2br(htmlspecialchars((string)$order['address'])) . ', ' . htmlspecialchars((string)$order['city']) . '</p>'
        . '<p style="color:#444;margin:0 0 4px">Phone: ' . htmlspecialchars((string)$order['phone']) . '</p>'
        . '<p style="color:#444;margin:0 0 20px">Payment Method: ' . htmlspecialchars($paymentLabels[$order['payment_method']] ?? (string)$order['payment_method']) . '</p>'
        . '<p style="text-align:center;margin:0 0 24px"><a href="' . htmlspecialchars($trackUrl) . '" style="background:#7a0d0d;color:#fff;text-decoration:none;padding:12px 26px;border-radius:6px;font-weight:bold;font-size:14px;display:inline-block">Track Your Order</a></p>'
        . '<p style="color:#888;font-size:12px;margin-top:30px">If you have any questions about your order, just reply to this email.</p>'
        . '</div></div>';

    $subject   = 'Order Confirmed — ' . $order['order_no'] . ' — ' . $companyName;
    $fromEmail = $emailSettings['from_email'] ?: ('no-reply@' . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'example.com'));
    $fromName  = $emailSettings['from_name'] ?: $companyName;

    $ok = send_via_configured_relay($emailSettings, $fromEmail, $fromName, (string)$order['email'], $subject, $html);

    if ($ok) {
        db()->prepare('UPDATE orders SET confirmation_email_sent = 1 WHERE id = ?')->execute([$order['id']]);
    }
    return $ok;
}

/**
 * Emails the customer once their Genie Business payment actually clears
 * (called from genie_apply_webhook() the moment payment_status flips to 'paid').
 * The sender name / header always comes from Admin -> Site Configuration ->
 * Company Name (and the uploaded logo) — never the SITE_NAME fallback baked
 * into config.php — so the email matches whatever the store owner configured
 * in the admin panel, not a leftover default from the codebase.
 */
function send_payment_confirmed_email(array $order): bool
{
    $GLOBALS['__last_mail_error'] = '';

    $emailSettings = get_email_settings();
    $siteSettings  = get_site_settings();
    // Company Name as configured in Admin -> Site Configuration (DB-backed).
    // SITE_NAME (defined in config/config.php) is only used if the admin has
    // never set a company name at all.
    $companyName = trim((string)($siteSettings['company_name'] ?? '')) !== ''
        ? $siteSettings['company_name']
        : SITE_NAME;

    $logoUrl    = !empty($siteSettings['site_logo']) ? site_absolute_url() . $siteSettings['site_logo'] : null;
    $headerHtml = $logoUrl
        ? '<img src="' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($companyName) . '" style="max-height:44px">'
        : '<h1 style="color:#fff;font-size:20px;margin:0">' . htmlspecialchars($companyName) . '</h1>';

    $amount = (float)$order['subtotal'] + (float)($order['delivery_charge'] ?? 0);
    $firstName = trim(explode(' ', trim((string)$order['full_name']))[0] ?? '');

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto">'
        . '<div style="background:#0a7a3d;padding:20px 24px">' . $headerHtml . '</div>'
        . '<div style="padding:24px">'
        . '<h2 style="font-size:18px;margin:0 0 6px">Payment received' . ($firstName ? ', ' . htmlspecialchars($firstName) : '') . '!</h2>'
        . '<p style="color:#666;margin:0 0 20px">We\'ve received your card payment of <strong>Rs. ' . number_format($amount, 2) . '</strong> for order <strong>' . htmlspecialchars((string)$order['order_no']) . '</strong> via Genie Business. Your order is now confirmed.</p>'
        . '<p style="color:#888;font-size:12px;margin-top:30px">If you have any questions about your order, just reply to this email.</p>'
        . '</div></div>';

    $subject   = 'Payment Received — ' . $order['order_no'] . ' — ' . $companyName;
    $fromEmail = $emailSettings['from_email'] ?: ('no-reply@' . preg_replace('/^www\\./', '', $_SERVER['HTTP_HOST'] ?? 'example.com'));
    // "From" display name: the admin's explicit Email Settings -> From Name if
    // they set one, otherwise the DB Company Name resolved above (never a
    // hardcoded file default).
    $fromName  = trim((string)($emailSettings['from_name'] ?? '')) !== '' ? $emailSettings['from_name'] : $companyName;

    return send_via_configured_relay($emailSettings, $fromEmail, $fromName, (string)$order['email'], $subject, $html);
}

/**
 * Sends one HTML email through the SMTP relay configured in Admin → Email
 * Settings (falling back to the server's mail() function if none is set).
 * Shared by the customer order-confirmation and admin order-notification emails.
 */
function send_via_configured_relay(array $emailSettings, string $fromEmail, string $fromName, string $toEmail, string $subject, string $html): bool
{
    $GLOBALS['__last_mail_error'] = '';
    $ok = false;

    if (!empty($emailSettings['smtp_host'])) {
        try {
            $mailer = new SmtpMailer(
                (string)$emailSettings['smtp_host'],
                (int)($emailSettings['smtp_port'] ?: 587),
                (string)($emailSettings['smtp_username'] ?? ''),
                (string)($emailSettings['smtp_password'] ?? ''),
                (string)($emailSettings['smtp_encryption'] ?? 'tls')
            );
            $ok = $mailer->send($fromEmail, $fromName, $toEmail, $subject, $html);
            if (!$ok) {
                $GLOBALS['__last_mail_error'] = $mailer->lastError ?: 'Unknown SMTP error.';
                error_log('Email to ' . $toEmail . ' failed: ' . $GLOBALS['__last_mail_error']);
            }
        } catch (\Throwable $e) {
            $GLOBALS['__last_mail_error'] = $e->getMessage();
            error_log('Email to ' . $toEmail . ' failed: ' . $e->getMessage());
        }
    } else {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= 'From: ' . mb_encode_mimeheader($fromName) . ' <' . $fromEmail . ">\r\n";
        $ok = @mail($toEmail, mb_encode_mimeheader($subject), $html, $headers);
        if (!$ok) {
            $GLOBALS['__last_mail_error'] = 'Server mail() function failed — no SMTP relay is configured in Admin → Email Settings.';
        }
    }

    return $ok;
}

/**
 * Emails the store's admin/notification address (Admin → Email Settings → "Admin
 * Notification Email") that a new order has come in, with the same order details.
 * Silently does nothing if no admin email is set, or the setting is turned off.
 */
function send_admin_order_notification_email(array $order): bool
{
    $emailSettings = get_email_settings();
    if (empty($emailSettings['notify_admin_on_order']) || empty($emailSettings['admin_notification_email'])) {
        return false;
    }

    $siteSettings = get_site_settings();
    $companyName  = $siteSettings['company_name'] ?: SITE_NAME;

    $rows = '';
    foreach ($order['items'] as $it) {
        $rows .= '<tr>'
            . '<td style="padding:10px 8px;border-bottom:1px solid #eee">' . htmlspecialchars($it['name']) . '</td>'
            . '<td style="padding:10px 8px;border-bottom:1px solid #eee;text-align:center">' . (int)$it['qty'] . '</td>'
            . '<td style="padding:10px 8px;border-bottom:1px solid #eee;text-align:right">Rs. ' . number_format((float)$it['line_total'], 2) . '</td>'
            . '</tr>';
    }
    $paymentLabels = [
        'cod' => 'Cash on Delivery', 'bank_transfer' => 'Bank Transfer',
        'koko' => 'KOKO', 'frimi' => 'FRIMI', 'card' => 'Card', 'genie' => 'Card (Genie Business)',
    ];
    $adminOrdersUrl = BASE_URL . '/admin/orders.php?order=' . urlencode((string)$order['order_no']);
    $logoUrl    = !empty($siteSettings['site_logo']) ? site_absolute_url() . $siteSettings['site_logo'] : null;
    $headerHtml = $logoUrl
        ? '<img src="' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($companyName) . '" style="max-height:40px">'
        : '<h1 style="color:#fff;font-size:18px;margin:0">' . htmlspecialchars($companyName) . ' — New Order</h1>';

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto">'
        . '<div style="background:#111;padding:20px 24px">' . $headerHtml . '</div>'
        . '<div style="padding:24px">'
        . '<h2 style="font-size:17px;margin:0 0 6px">A new order just came in</h2>'
        . '<p style="color:#666;margin:0 0 20px">Order <strong>' . htmlspecialchars((string)$order['order_no']) . '</strong> — Rs. ' . number_format((float)$order['subtotal'], 2) . '</p>'
        . '<table style="width:100%;border-collapse:collapse;margin-bottom:20px"><thead><tr style="background:#f2f2f2">'
        . '<th style="padding:10px 8px;text-align:left">Item</th><th style="padding:10px 8px">Qty</th><th style="padding:10px 8px;text-align:right">Total</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>'
        . '<h3 style="font-size:14px;margin:0 0 6px">Customer</h3>'
        . '<p style="color:#444;margin:0 0 4px">' . htmlspecialchars((string)$order['full_name']) . ' — ' . htmlspecialchars((string)$order['email']) . ' — ' . htmlspecialchars((string)$order['phone']) . '</p>'
        . '<p style="color:#444;margin:0 0 20px">' . nl2br(htmlspecialchars((string)$order['address'])) . ', ' . htmlspecialchars((string)$order['city']) . ' · ' . htmlspecialchars($paymentLabels[$order['payment_method']] ?? (string)$order['payment_method']) . '</p>'
        . '<p style="margin-top:24px"><a href="' . e($adminOrdersUrl) . '" style="background:#9e1616;color:#fff;text-decoration:none;padding:11px 22px;border-radius:6px;font-weight:bold;display:inline-block">View Order in Admin Panel</a></p>'
        . '</div></div>';

    $subject   = 'New Order ' . $order['order_no'] . ' — Rs. ' . number_format((float)$order['subtotal'], 2);
    $fromEmail = $emailSettings['from_email'] ?: ('no-reply@' . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'example.com'));
    $fromName  = $emailSettings['from_name'] ?: $companyName;

    return send_via_configured_relay($emailSettings, $fromEmail, $fromName, (string)$emailSettings['admin_notification_email'], $subject, $html);
}

/**
 * Builds a Genie Business Connect "create transaction" request body, adding the
 * fields their API requires beyond the plain order data: apiVersion, appVersion,
 * signMethod, and a sha1 signature of amount+currency+apiKey. Matches
 * GenieBusinessConnect\Transactions::create() / Client::post() /
 * Crypt\Signature::sign() in Genie's official PHP SDK exactly.
 */
function genie_build_payload(array $cfg, int $amountCents, string $currency, array $extra = []): array
{
    $payload = array_merge($extra, [
        'amount'   => $amountCents,
        'currency' => $currency,
    ]);

    if (isset($payload['validForHours'])) {
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $now->modify('+' . (int)$payload['validForHours'] . ' hours');
        $payload['expires'] = $now->format('Y-m-d\TH:i:s.u\Z');
        unset($payload['validForHours']);
    }

    $payload['apiVersion'] = '2.0';
    $payload['appVersion'] = 'geniebiz-connect-php'; // must match Genie's own SDK identifier
    $payload['signMethod'] = 'sha1';
    $payload['signature']  = sha1('amount=' . $amountCents . '&currency=' . $currency . '&apiKey=' . $cfg['api_key']);

    return $payload;
}

/** POSTs a built payload to Genie's /transactions endpoint and returns the raw diagnostic result. */
function genie_post_transaction(array $cfg, array $payload): array
{
    $url = $cfg['base_url'] . '/transactions';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: ' . $cfg['api_key'], // Genie expects the raw API key here, not "Bearer <key>"
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $curlErr   = curl_error($ch);
    curl_close($ch);

    return [
        'url_tried'    => $url,
        'http_code'    => $httpCode,
        'curl_errno'   => $curlErrno,
        'curl_error'   => $curlErr,
        'raw_response' => $response === false ? null : $response,
        'data'         => $response !== false ? json_decode((string)$response, true) : null,
    ];
}

/**
 * Fires a small, throwaway transaction-create request at Genie Business Connect
 * using whatever is currently saved in Admin -> Payment Settings, and returns the
 * full diagnostic result (HTTP status, raw response, curl error if any). Used by
 * the "Test Connection" button so credential/endpoint problems are visible
 * immediately instead of only surfacing during a real customer checkout.
 */
function genie_test_connection(): array
{
    $cfg = genie_get_config();
    if (!$cfg['enabled']) {
        return ['ok' => false, 'error' => 'Genie Business is turned off. Enable it above first.'];
    }
    if ($cfg['api_key'] === '') {
        return ['ok' => false, 'error' => 'API Key is missing — that is the only credential Genie actually requires.'];
    }

    $payload = genie_build_payload($cfg, 1000, 'LKR', [ // Rs. 10.00 — smallest sensible test amount, no real charge happens here
        'localId'       => 'TEST-' . date('YmdHis'),
        'validForHours' => 1,
        'webhook'       => genie_webhook_url(),
        'redirectUrl'   => site_absolute_url() . BASE_URL . '/',
    ]);
    if ($cfg['provider']) { $payload['provider'] = $cfg['provider']; }

    $result = genie_post_transaction($cfg, $payload);

    // Echo back the exact body we sent, with the key masked, so a mismatch with
    // Genie's expectations can be spotted without guessing.
    $sent = $payload;
    $maskedKey = strlen($cfg['api_key']) > 8
        ? substr($cfg['api_key'], 0, 4) . str_repeat('*', 8) . substr($cfg['api_key'], -4)
        : '****';

    return [
        'ok'           => $result['raw_response'] !== null && $result['http_code'] >= 200 && $result['http_code'] < 300,
        'url_tried'    => $result['url_tried'],
        'http_code'    => $result['http_code'],
        'curl_errno'   => $result['curl_errno'],
        'curl_error'   => $result['curl_error'],
        'raw_response' => $result['raw_response'],
        'sent_body'    => json_encode($sent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        'sent_auth'    => 'Authorization: ' . $maskedKey,
        'environment'  => $cfg['environment'],
    ];
}

/* ============================================================
 *  Genie Business Connect — payment gateway integration
 *  Docs: https://geniebusiness.stoplight.io/docs/genie-business-connect
 *  Configured under Admin -> Payment Settings. Uses the "Transactions"
 *  endpoint: a transaction is created server-side, the customer is
 *  redirected to the hosted payment URL Genie returns, and Genie calls
 *  our webhook (payment_webhook.php) when the payment finishes.
 * ============================================================ */

/** Reads the Genie Business Connect settings saved in Admin -> Payment Settings. */
function genie_get_config(): array
{
    $s = get_site_settings();
    $env      = ($s['genie_environment'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';
    $baseUrl  = trim((string)($s['genie_base_url'] ?? ''));
    if ($baseUrl === '') {
        // Official Genie Business Connect endpoints (confirmed from Genie's published
        // PHP SDK — github.com/geniebiz/php-plugin — src/Client.php). Note the required
        // "/public/" path segment. Override under Admin -> Payment Settings only if
        // Genie's support team gives you a different host for your account.
        $baseUrl = $env === 'production'
            ? 'https://api.geniebiz.lk/public'
            : 'https://api.uat.geniebiz.lk/public';
    }
    $baseUrl = rtrim($baseUrl, '/');

    /* Genie's API lives under a required "/public" path segment (confirmed in their
       official SDK: PRODUCTION_ENDPOINT = https://api.geniebiz.lk/public/). A base
       URL saved without it — easy to do via the override field — produces a 403
       "Forbidden" on every call, so repair it here rather than failing silently. */
    $parsedPath = trim((string)parse_url($baseUrl, PHP_URL_PATH), '/');
    if ($parsedPath === '') {
        $baseUrl .= '/public';
    }

    return [
        'enabled'     => !empty($s['genie_enabled']),
        'environment' => $env,
        'app_id'      => (string)($s['genie_app_id'] ?? ''),
        'api_key'     => (string)($s['genie_api_key'] ?? ''),
        'provider'    => trim((string)($s['genie_provider'] ?? '')) ?: null,
        'valid_hours' => max(1, min(2160, (int)($s['genie_valid_hours'] ?? 24))),
        'base_url'    => $baseUrl,
    ];
}

function genie_is_configured(): bool
{
    $c = genie_get_config();
    // Only the API key matters: Genie's own SDK stores the App ID but never sends
    // it, so requiring it here would hide the payment option for merchants who
    // only have a key.
    return $c['enabled'] && $c['api_key'] !== '';
}

/** Where Genie should send the customer back to after they finish paying (or cancel). */
function genie_return_url(string $orderNo): string
{
    return site_absolute_url() . BASE_URL . '/checkout.php?order=' . urlencode($orderNo) . '&genie=1';
}

/** Where Genie should POST the async payment-status webhook. */
function genie_webhook_url(): string
{
    return site_absolute_url() . BASE_URL . '/payment_webhook.php';
}

/**
 * Creates a Genie Business Connect transaction for an order and returns the hosted
 * payment page URL to redirect the customer to.
 * Returns ['ok'=>true,'url'=>...,'transaction_id'=>...] or ['ok'=>false,'error'=>...].
 */
function genie_create_transaction(array $order): array
{
    $cfg = genie_get_config();
    if (!$cfg['enabled']) {
        return ['ok' => false, 'error' => 'Genie Business is not enabled in Admin -> Payment Settings.'];
    }
    if ($cfg['api_key'] === '') {
        return ['ok' => false, 'error' => 'Genie Business API Key is not set in Admin -> Payment Settings.'];
    }

    $amount = (float)$order['subtotal'] + (float)($order['delivery_charge'] ?? 0);
    $payload = genie_build_payload($cfg, (int)round($amount * 100), 'LKR', [
        'localId'       => (string)$order['order_no'],
        'validForHours' => $cfg['valid_hours'],
        'webhook'       => genie_webhook_url(),
        'redirectUrl'   => genie_return_url((string)$order['order_no']),
    ]);
    if ($cfg['provider']) {
        $payload['provider'] = $cfg['provider'];
    }

    $result = genie_post_transaction($cfg, $payload);

    if ($result['raw_response'] === null) {
        error_log('Genie Business transaction create failed (curl): ' . $result['curl_error']);
        return ['ok' => false, 'error' => 'Could not reach the Genie Business payment gateway. Please try again or choose another payment method.'];
    }

    $data = $result['data'];
    $payUrl = $data['url']
        ?? $data['data']['url']
        ?? $data['paymentUrl']
        ?? $data['redirectUrl']
        ?? null;
    $txnId  = $data['id']
        ?? $data['transactionId']
        ?? $data['data']['id']
        ?? '';

    if ($result['http_code'] >= 200 && $result['http_code'] < 300 && $payUrl) {
        return [
            'ok'             => true,
            'url'            => (string)$payUrl,
            'transaction_id' => (string)$txnId,
        ];
    }

    // 2xx but no usable URL — surface the raw body so the cause is visible
    // instead of silently falling through to "awaiting payment".
    if ($result['http_code'] >= 200 && $result['http_code'] < 300) {
        error_log('Genie Business returned success but no payment URL: ' . (string)$result['raw_response']);
        return [
            'ok'    => false,
            'error' => 'Genie accepted the request but returned no payment link. Raw response: ' . substr((string)$result['raw_response'], 0, 300),
        ];
    }

    error_log('Genie Business transaction create failed (HTTP ' . $result['http_code'] . '): ' . (string)$result['raw_response']);
    return [
        'ok'    => false,
        'error' => $data['message'] ?? $data['error'] ?? 'The payment gateway rejected the request (HTTP ' . $result['http_code'] . ').',
    ];
}

/**
 * Handles an incoming Genie Business webhook payload (already json_decode()'d),
 * matches it to an order via localId/order_no, and updates payment_status +
 * order status. Called from payment_webhook.php.
 */
function genie_apply_webhook(array $payload): bool
{
    ensure_orders_table();

    $localId = (string)($payload['localId'] ?? $payload['data']['localId'] ?? '');
    $status  = strtolower((string)($payload['status'] ?? $payload['data']['status'] ?? ''));
    $txnId   = (string)($payload['id'] ?? $payload['transactionId'] ?? $payload['data']['id'] ?? '');

    if ($localId === '') {
        error_log('Genie webhook: missing localId in payload: ' . json_encode($payload));
        return false;
    }

    $order = get_order_by_no($localId);
    if (!$order) {
        error_log('Genie webhook: no matching order for localId ' . $localId);
        return false;
    }

    /* If Genie included a signature, verify it against our API key (same sha1
       formula as their Crypt\Verify class) before trusting the payload. */
    $sig = (string)($payload['signature'] ?? $payload['data']['signature'] ?? '');
    if ($sig !== '') {
        $cfg = genie_get_config();
        $amount = (int)($payload['amount'] ?? $payload['data']['amount'] ?? 0);
        $currency = (string)($payload['currency'] ?? $payload['data']['currency'] ?? 'LKR');
        $expected = sha1('amount=' . $amount . '&currency=' . $currency . '&apiKey=' . $cfg['api_key']);
        if (!hash_equals($expected, $sig)) {
            error_log('Genie webhook: signature mismatch for order ' . $localId);
            return false;
        }
    }

    $paid = in_array($status, ['completed', 'success', 'succeeded', 'paid'], true);
    $failed = in_array($status, ['failed', 'cancelled', 'canceled', 'expired'], true);

    $paymentStatus = $paid ? 'paid' : ($failed ? 'failed' : 'pending');

    $wasAlreadyPaid = ($order['payment_status'] ?? 'unpaid') === 'paid';

    $st = db()->prepare('UPDATE orders SET payment_status = ?, payment_provider = \'genie\', payment_txn_id = ?, payment_paid_at = ' . ($paid ? 'NOW()' : 'payment_paid_at') . ' WHERE id = ?');
    $st->execute([$paymentStatus, $txnId ?: null, $order['id']]);

    if ($paid && $order['status'] === 'pending') {
        update_order_status((int)$order['id'], 'confirmed');
    }
    if ($paid && !$wasAlreadyPaid) {
        // Re-fetch so the email reflects the just-updated payment_status.
        $freshOrder = get_order_by_no($order['order_no']);
        if ($freshOrder) { send_payment_confirmed_email($freshOrder); }
    }

    return true;
}

/* ============================================================
 *  KOKO (Paykoko / Daraz BNPL) — "Buy Now, Pay Later" gateway integration
 *  Configured under Admin -> Payment Settings. Request/response contract
 *  (orderCreate fields, RSA-SHA256 signing, callback params) matches KOKO's
 *  official WooCommerce plugin (Paykoko, v2.0.11) exactly, so credentials
 *  issued by KOKO for that plugin work here unchanged.
 *
 *  Flow:
 *   1. koko_build_redirect_form() builds an auto-submitting HTML form that
 *      POSTs the signed order to KOKO's orderCreate endpoint — the customer's
 *      browser is sent there directly (KOKO has no JSON "create transaction"
 *      API; it is a hosted-page POST redirect).
 *   2. The customer completes / cancels payment on KOKO's hosted page.
 *   3. KOKO redirects the browser back to koko_callback.php (our
 *      _returnUrl/_responseUrl) with orderId, trnId, status, desc and a
 *      signature; koko_apply_backend_response() verifies it with KOKO's public key
 *      and updates the order.
 * ============================================================ */

/**
 * Normalizes a pasted RSA PEM key so common copy/paste mistakes don't break
 * signing/verification. Handles the most frequent real-world causes of
 * "does not look like a valid RSA PEM key" even when the key IS the right
 * one:
 *   - Literal two-character "\n" (and "\r\n") sequences left over from
 *     pasting a key straight out of a JSON response or a .env file, where
 *     the newlines were escaped as *text* rather than actual line breaks.
 *   - Real CRLF/CR line endings (common when copying from Windows/Notepad).
 *   - The base64 body ending up all on one line (no line breaks at all) —
 *     re-wrapped into standard 64-char PEM lines.
 *   - Stray leading/trailing whitespace on each line.
 * Returns the key unchanged if it doesn't look like PEM at all (no BEGIN/END
 * markers), so the caller's own validity check still correctly rejects junk.
 */
function koko_normalize_pem(string $key): string
{
    $key = trim($key);
    if ($key === '') { return ''; }

    // Literal "\n" / "\r\n" as two/three plain characters (backslash + n),
    // e.g. pasted straight out of a JSON string or .env file — turn them
    // into real newlines. Then normalize real CRLF/CR to LF.
    $key = str_replace(["\\r\\n", "\\n"], "\n", $key);
    $key = str_replace(["\r\n", "\r"], "\n", $key);

    if (!preg_match('/-----BEGIN\s+([A-Z0-9 ]+?)\s*-----(.*?)-----END\s+\1\s*-----/s', $key, $m)) {
        return $key; // not PEM-shaped at all — let koko_*_key_looks_valid() reject it
    }
    $label = trim($m[1]);
    $body  = preg_replace('/\s+/', '', $m[2]); // strip ALL whitespace from the base64 body
    if ($body === '') { return $key; }

    $wrapped = chunk_split($body, 64, "\n"); // standard PEM line length
    return "-----BEGIN {$label}-----\n" . $wrapped . "-----END {$label}-----\n";
}

/** True if $privateKeyPem parses as a usable RSA private key (structurally valid PEM). */
function koko_private_key_looks_valid(string $privateKeyPem): bool
{
    $privateKeyPem = koko_normalize_pem($privateKeyPem);
    if (trim($privateKeyPem) === '') { return false; }
    $pkeyid = @openssl_pkey_get_private($privateKeyPem);
    if (!$pkeyid) { return false; }
    $details = openssl_pkey_get_details($pkeyid);
    return $details !== false && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA;
}

/** True if $publicKeyPem parses as a usable RSA public key (structurally valid PEM). */
function koko_public_key_looks_valid(string $publicKeyPem): bool
{
    $publicKeyPem = koko_normalize_pem($publicKeyPem);
    if (trim($publicKeyPem) === '') { return false; }
    $pubKeyId = @openssl_pkey_get_public($publicKeyPem);
    if (!$pubKeyId) { return false; }
    $details = openssl_pkey_get_details($pubKeyId);
    return $details !== false && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA;
}

/** Reads the KOKO settings saved in Admin -> Payment Settings. */
function koko_get_config(): array
{
    $s   = get_site_settings();
    $env = in_array($s['koko_environment'] ?? 'sandbox', ['sandbox', 'qa', 'production'], true)
        ? $s['koko_environment'] : 'sandbox';
    $baseUrl  = trim((string)($s['koko_base_url'] ?? ''));
    if ($baseUrl === '') {
        // Per KOKO's official API Documentation (Developer Preview v1.06):
        // Dev: devapi.paykoko.com, QA: qaapi.paykoko.com, PROD: prodapi.paykoko.com,
        // all under /api/merchants/orderCreate.
        $hosts   = ['sandbox' => 'devapi.paykoko.com', 'qa' => 'qaapi.paykoko.com', 'production' => 'prodapi.paykoko.com'];
        $baseUrl = 'https://' . $hosts[$env] . '/api/merchants/orderCreate';
    }

    return [
        'enabled'         => !empty($s['koko_enabled']),
        'environment'     => $env,
        'merchant_id'     => (string)($s['koko_merchant_id'] ?? ''),
        'api_key'         => (string)($s['koko_api_key'] ?? ''),
        // Normalized here (not just at save time) so a key that was saved before this
        // fix — or pasted with escaped "\n"s some other way — self-heals on every read
        // without the store owner needing to re-paste and re-save it.
        'private_key'     => koko_normalize_pem((string)($s['koko_private_key'] ?? '')),
        'public_key'      => koko_normalize_pem((string)($s['koko_public_key'] ?? '')),
        'callback_secret' => (string)($s['koko_callback_secret'] ?? ''),
        'plugin_name'     => trim((string)($s['koko_plugin_name'] ?? '')) !== '' ? trim((string)$s['koko_plugin_name']) : 'customapi',
        'order_url'       => $baseUrl,
    ];
}

function koko_is_configured(): bool
{
    $c = koko_get_config();
    return $c['enabled'] && $c['merchant_id'] !== '' && $c['api_key'] !== '' && $c['private_key'] !== '';
}

/** Where KOKO should send the customer's browser back to (return, response, and cancel URL are all the same, matching the official plugin). */
function koko_callback_url(): string
{
    return site_absolute_url() . BASE_URL . '/koko_callback.php';
}

/**
 * Signs $dataString with the merchant's RSA private key (SHA-256), returning
 * base64 — or null if the key is missing/invalid. Same primitive KOKO's own
 * plugin uses (openssl_sign / OPENSSL_ALGO_SHA256).
 */
function koko_sign(string $dataString, string $privateKeyPem): ?string
{
    $privateKeyPem = koko_normalize_pem($privateKeyPem);
    if (trim($privateKeyPem) === '') { return null; }
    $pkeyid = openssl_pkey_get_private($privateKeyPem);
    if (!$pkeyid) { return null; }
    $ok = openssl_sign($dataString, $signature, $pkeyid, OPENSSL_ALGO_SHA256);
    return $ok ? base64_encode($signature) : null;
}

/** Verifies a base64 signature against $dataString using KOKO's RSA public key. Returns true only on a confirmed match. */
function koko_verify(string $dataString, string $signatureB64, string $publicKeyPem): bool
{
    $publicKeyPem = koko_normalize_pem($publicKeyPem);
    if (trim($publicKeyPem) === '' || trim($signatureB64) === '') { return false; }
    $pubKeyId = openssl_pkey_get_public($publicKeyPem);
    if (!$pubKeyId) { return false; }
    $signature = base64_decode($signatureB64);
    $result = openssl_verify($dataString, $signature, $pubKeyId, OPENSSL_ALGO_SHA256);
    return $result === 1;
}

/**
 * Builds the auto-submitting HTML form that sends the customer's browser to
 * KOKO's hosted payment page for this order. Returns ['ok'=>true,'html'=>...]
 * or ['ok'=>false,'error'=>...] — never throws, so checkout.php can always
 * fall back to "order placed, payment pending" messaging.
 */
function koko_build_redirect_form(array $order): array
{
    $cfg = koko_get_config();
    if (!$cfg['enabled'])            { return ['ok' => false, 'error' => 'KOKO is not enabled in Admin -> Payment Settings.']; }
    if ($cfg['merchant_id'] === '')  { return ['ok' => false, 'error' => 'KOKO Merchant ID is not set in Admin -> Payment Settings.']; }
    if ($cfg['api_key'] === '')      { return ['ok' => false, 'error' => 'KOKO API Key is not set in Admin -> Payment Settings.']; }
    if ($cfg['private_key'] === '')  { return ['ok' => false, 'error' => 'KOKO Private Key is not set in Admin -> Payment Settings.']; }

    $redirectUrl = koko_callback_url();
    $amount      = number_format((float)$order['subtotal'] + (float)($order['delivery_charge'] ?? 0), 2, '.', '');
    $currency    = 'LKR';
    // Must exactly match the plugin name KOKO's Merchant Success team registered for your
    // Merchant ID / API Key — sending the wrong value here is what causes KOKO to reject the
    // order with "merchantPluginDetail.notExists". Configurable in Admin -> Payment Settings
    // (defaults to "customapi", matching KOKO's own docs/sample code for custom integrations).
    $pluginName  = $cfg['plugin_name'];
    $pluginVersion = defined('SITE_VERSION') ? SITE_VERSION : '1.0.1';
    $reference   = $cfg['merchant_id'] . random_int(111, 999) . '-' . $order['order_no'];
    $nameParts   = explode(' ', trim((string)$order['full_name']), 2);
    $firstName   = $nameParts[0] ?? '';
    $lastName    = $nameParts[1] ?? '';
    $email       = (string)$order['email'];
    $mobile      = (string)$order['phone'];
    $description = (count($order['items'] ?? []) > 1) ? (count($order['items']) . ' products') : '1 Product';

    // Same concatenation order as KOKO's own Paykoko plugin's dataString.
    $dataString = $cfg['merchant_id'] . $amount . $currency . $pluginName . $pluginVersion
        . $redirectUrl . $redirectUrl . $order['order_no'] . $reference
        . $firstName . $lastName . $email . $description . $cfg['api_key'] . $redirectUrl;

    $signature = koko_sign($dataString, $cfg['private_key']);
    if ($signature === null) {
        error_log('KOKO: failed to sign order ' . $order['order_no'] . ' — check the Private Key in Admin -> Payment Settings is a valid PEM RSA key.');
        return ['ok' => false, 'error' => 'Could not sign the KOKO payment request — check the Private Key saved in Admin -> Payment Settings.'];
    }

    $fields = [
        '_mId'           => $cfg['merchant_id'],
        'api_key'        => $cfg['api_key'],
        '_returnUrl'     => $redirectUrl,
        '_responseUrl'   => $redirectUrl,
        '_cancelUrl'     => $redirectUrl,
        '_currency'      => $currency,
        '_amount'        => $amount,
        '_reference'     => $reference,
        '_pluginName'    => $pluginName,
        '_pluginVersion' => $pluginVersion,
        '_orderId'       => $order['order_no'],
        '_firstName'     => $firstName,
        '_lastName'      => $lastName,
        '_email'         => $email,
        '_mobileNo'      => $mobile,
        '_description'   => $description,
        'dataString'     => $dataString,
        'signature'      => $signature,
    ];

    $inputs = '';
    foreach ($fields as $k => $v) {
        $inputs .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">' . "\n";
    }

    $html = '<form action="' . e($cfg['order_url']) . '" method="post" id="kokoRedirectForm">' . "\n" . $inputs
        . '<noscript><button type="submit">Continue to KOKO</button></noscript>' . "\n"
        . '</form>' . "\n"
        . '<script>document.getElementById("kokoRedirectForm").submit();</script>';

    return ['ok' => true, 'html' => $html];
}

/**
 * Verifies and applies KOKO's signed *backend* response (the server-to-server
 * HTTP POST KOKO's backend sends to `_responseUrl` — never the customer's own
 * browser). Per KOKO's official API docs this is the ONLY one of KOKO's three
 * callback URLs (_returnUrl / _cancelUrl / _responseUrl) that carries a
 * `signature`, so it is the only one this app ever trusts to mark an order
 * "paid". The signed value is exactly `orderId + trnId + status` — KOKO's
 * docs explicitly do NOT include `desc` in it (unlike some third-party sample
 * code that happened to work only because its test `desc` was empty).
 * Returns the fresh order on a verified, trusted call; null otherwise (the
 * order is left completely untouched if verification fails).
 */
function koko_apply_backend_response(array $params): ?array
{
    ensure_orders_table();
    $cfg = koko_get_config();

    $orderNo = (string)($params['orderId'] ?? '');
    $trnId   = (string)($params['trnId'] ?? '');
    $status  = (string)($params['status'] ?? '');
    $sig     = (string)($params['signature'] ?? '');

    if ($orderNo === '' || $sig === '') {
        error_log('KOKO backend response: missing orderId/signature: ' . json_encode($params));
        return null;
    }

    $order = get_order_by_no($orderNo);
    if (!$order) {
        error_log('KOKO backend response: no matching order for orderId ' . $orderNo);
        return null;
    }

    if (!empty($cfg['callback_secret'])) {
        $provided = (string)($params['secret'] ?? ($_SERVER['HTTP_X_KOKO_SECRET'] ?? ''));
        if (!hash_equals($cfg['callback_secret'], $provided)) {
            error_log('KOKO backend response: shared secret mismatch for order ' . $orderNo);
            return null;
        }
    }

    if ($cfg['public_key'] === '') {
        error_log('KOKO backend response: no KOKO Public Key saved in Admin -> Payment Settings — cannot verify order ' . $orderNo . ', ignoring.');
        return null;
    }

    // Per KOKO's API docs ("Response Url (_responseUrl)" section): the signed
    // string is orderId + trnId + status — desc is NOT part of it.
    $dataString = $orderNo . $trnId . $status;
    if (!koko_verify($dataString, $sig, $cfg['public_key'])) {
        error_log('KOKO backend response: signature verification FAILED for order ' . $orderNo . ' — response ignored, order left untouched. Check the KOKO Public Key saved in Admin -> Payment Settings.');
        return null;
    }

    $statusUpper    = strtoupper($status);
    $paid           = $statusUpper === 'SUCCESS';
    $failed         = in_array($statusUpper, ['FAILURE', 'FAILED', 'CANCELED', 'CANCELLED', 'DECLINED', 'ERROR'], true);
    $paymentStatus  = $paid ? 'paid' : ($failed ? 'failed' : 'pending');
    $wasAlreadyPaid = ($order['payment_status'] ?? 'unpaid') === 'paid';

    $st = db()->prepare('UPDATE orders SET payment_status = ?, payment_provider = \'koko\', payment_txn_id = ?, payment_paid_at = ' . ($paid ? 'NOW()' : 'payment_paid_at') . ' WHERE id = ?');
    $st->execute([$paymentStatus, $trnId ?: null, $order['id']]);

    if ($paid && $order['status'] === 'pending') {
        update_order_status((int)$order['id'], 'confirmed');
    }

    $freshOrder = get_order_by_no($orderNo) ?: $order;
    if ($paid && !$wasAlreadyPaid) {
        send_payment_confirmed_email($freshOrder);
    }

    return $freshOrder;
}

/**
 * Handles the customer's own browser landing on `_returnUrl` (payment
 * finished, status SUCCESS|FAILURE) or `_cancelUrl` (status CANCELED). Per
 * KOKO's docs neither of these carries a `signature` — only `orderId`,
 * `trnId` and `status` — so this function NEVER marks an order "paid" from
 * them (that only ever happens in koko_apply_backend_response() above, whose
 * signed call typically lands at essentially the same moment). An explicit
 * "cancelled" is safe to record directly here since there's no incentive to
 * forge it (worst case: your own unpaid order shows as cancelled, which
 * costs nothing and can always be corrected). Returns the order's current,
 * fresh state either way so the caller can show an accurate message.
 */
function koko_handle_browser_return(array $params): ?array
{
    ensure_orders_table();
    $orderNo = (string)($params['orderId'] ?? '');
    if ($orderNo === '') { return null; }

    $order = get_order_by_no($orderNo);
    if (!$order) { return null; }

    $statusUpper = strtoupper((string)($params['status'] ?? ''));
    if (in_array($statusUpper, ['CANCELED', 'CANCELLED'], true) && ($order['payment_status'] ?? '') !== 'paid') {
        db()->prepare("UPDATE orders SET payment_status = 'failed', payment_provider = 'koko' WHERE id = ?")
            ->execute([$order['id']]);
        $order = get_order_by_no($orderNo) ?: $order;
    }

    return $order;
}

/* ============================================================
   Bulk delete helpers (used by admin/items.php, admin/items_import.php
   and admin/categories.php)
   ============================================================ */

/** Removes an uploaded file given its site-relative path (e.g. /assets/uploads/items/x.png). */
function delete_site_file(?string $path): void
{
    if (!$path) return;
    $f = __DIR__ . '/../' . ltrim($path, '/');
    if (is_file($f)) { @unlink($f); }
}

/**
 * Deletes the given items together with their images + variations (rows and files).
 * Returns the number of items removed.
 */
function delete_items_by_ids(array $ids): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (!$ids) return 0;
    $deleted = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        $inQ = implode(',', array_fill(0, count($chunk), '?'));

        $st = db()->prepare("SELECT image_path FROM item_images WHERE item_id IN ($inQ)");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) { delete_site_file($p); }

        try {
            $st = db()->prepare("SELECT image_path FROM item_variations WHERE item_id IN ($inQ) AND image_path IS NOT NULL");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) { delete_site_file($p); }
            db()->prepare("DELETE FROM item_variations WHERE item_id IN ($inQ)")->execute($chunk);
        } catch (Throwable $e) { /* variations table may not exist yet */ }

        db()->prepare("DELETE FROM item_images WHERE item_id IN ($inQ)")->execute($chunk);
        $del = db()->prepare("DELETE FROM items WHERE id IN ($inQ)");
        $del->execute($chunk);
        $deleted += $del->rowCount();
    }
    return $deleted;
}

/**
 * Deletes the given categories AND all of their sub-categories.
 * Category images are removed from disk and items that pointed at a deleted
 * category are set to "No category" (items themselves are kept).
 * Returns the number of category rows removed.
 */
function delete_categories_by_ids(array $ids): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (!$ids) return 0;

    // Expand to every descendant (tree map built once).
    $byParent = [];
    foreach (db()->query('SELECT id, parent_id FROM categories')->fetchAll() as $r) {
        $byParent[(int)$r['parent_id']][] = (int)$r['id'];
    }
    $all = [];
    $stack = $ids;
    while ($stack) {
        $cur = array_pop($stack);
        if (isset($all[$cur])) continue;
        $all[$cur] = true;
        foreach ($byParent[$cur] ?? [] as $child) { $stack[] = $child; }
    }
    $all = array_keys($all);

    foreach (array_chunk($all, 500) as $chunk) {
        $inQ = implode(',', array_fill(0, count($chunk), '?'));
        $st = db()->prepare("SELECT image FROM categories WHERE id IN ($inQ) AND image IS NOT NULL AND image <> ''");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) { delete_site_file($p); }
        try {
            db()->prepare("UPDATE items SET category_id = NULL WHERE category_id IN ($inQ)")->execute($chunk);
        } catch (Throwable $e) { /* items table may not exist yet */ }
        db()->prepare("DELETE FROM categories WHERE id IN ($inQ)")->execute($chunk);
    }
    return count($all);
}


/* =====================================================================
 *  ECLOTHING product cards (home, shop, related) — one shared renderer
 * ===================================================================== */

/** Sizes + colours that are in stock somewhere (for the shop filter sidebar). */
function get_filter_size_color_options(): array
{
    try {
        ensure_variation_attr_columns();
        $sizes = db()->query("SELECT DISTINCT v.size_label FROM item_variations v JOIN items i ON i.id = v.item_id
                               WHERE v.stock_qty > 0 AND v.size_label IS NOT NULL AND v.size_label <> '' AND i.stock_status != 'out_of_stock'")->fetchAll(\PDO::FETCH_COLUMN);
        usort($sizes, fn($a, $b) => strcmp(clothing_size_sort_key($a), clothing_size_sort_key($b)));
        $colors = db()->query("SELECT v.color_name, MAX(v.color_hex) AS color_hex FROM item_variations v JOIN items i ON i.id = v.item_id
                                WHERE v.stock_qty > 0 AND v.color_name IS NOT NULL AND v.color_name <> '' AND i.stock_status != 'out_of_stock'
                                GROUP BY v.color_name ORDER BY v.color_name")->fetchAll();
        return ['sizes' => $sizes, 'colors' => $colors];
    } catch (\Throwable $e) {
        return ['sizes' => [], 'colors' => []];
    }
}

/** Second photo of each item (shown when the card is hovered). [item_id => path] */
function get_items_hover_images(array $itemIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
    if (!$ids) { return []; }
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT item_id, image_path FROM item_images WHERE item_id IN ($in) AND is_feature = 0 ORDER BY sort_order ASC, id ASC");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll() as $r) { $out[(int)$r['item_id']] = $out[(int)$r['item_id']] ?? $r['image_path']; }
        return $out;
    } catch (\Throwable $e) { return []; }
}

/** Renders one fashion product card. */
function render_product_card(array $it, ?array $summary = null, ?string $hoverImage = null): void
{
    $p    = item_price_info($it);
    $url  = BASE_URL . '/item.php?id=' . (int)$it['id'];
    $hasV = !empty($it['has_variations']);
    $soldOut = ($it['stock_status'] ?? '') === 'out_of_stock' || ($hasV && $summary && $summary['total'] <= 0);
    $isNew = !empty($it['created_at']) && strtotime((string)$it['created_at']) > strtotime('-21 days');
    ?>
    <article class="prod-card ec-card<?= $soldOut ? ' is-soldout' : '' ?>">
      <div class="prod-img ec-card-img">
        <a href="<?= e($url) ?>" aria-label="<?= e($it['name']) ?>" class="ec-card-link">
          <?php if (!empty($it['feature_image'])): ?>
            <img class="thumb" src="<?= BASE_URL . e($it['feature_image']) ?>" alt="<?= e($it['name']) ?>" loading="lazy">
            <?php if ($hoverImage): ?><img class="thumb thumb-hover" src="<?= BASE_URL . e($hoverImage) ?>" alt="" loading="lazy" aria-hidden="true"><?php endif; ?>
          <?php else: ?>
            <span class="thumb-placeholder"><i class="fa-solid fa-shirt"></i></span>
          <?php endif; ?>
        </a>
        <div class="ec-badges">
          <?php if ($soldOut): ?><span class="ec-badge ec-badge-out">Sold Out</span>
          <?php elseif ($p['discount']): ?><span class="ec-badge ec-badge-sale">-<?= (int)$p['discount'] ?>%</span><?php endif; ?>
          <?php if ($isNew && !$soldOut): ?><span class="ec-badge ec-badge-new">New</span><?php endif; ?>
        </div>
        <button type="button" class="prod-wish" aria-label="Add to wishlist"><i class="fa-regular fa-heart"></i></button>
        <?php if (!$soldOut): ?>
          <?php if ($hasV && $summary && !empty($summary['sizes'])): ?>
          <div class="ec-quick">
            <span class="ec-quick-label">Quick add · pick size</span>
            <div class="ec-quick-sizes">
              <?php foreach ($summary['sizes'] as $sz => $st): $sz = (string)$sz; ?>
                <?php if ($st > 0): ?><a href="<?= e($url . '&size=' . rawurlencode($sz)) ?>"><?= e($sz) ?></a><?php else: ?><span class="is-out" title="Sold out"><?= e($sz) ?></span><?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>
          <?php else: ?>
          <div class="ec-quick ec-quick-btn">
            <button type="button" class="cart" data-item-id="<?= (int)$it['id'] ?>" data-has-variations="<?= $hasV ? '1' : '0' ?>"><i class="fa-solid fa-bag-shopping"></i> <?= $hasV ? 'Choose options' : 'Add to bag' ?></button>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="prod-info ec-card-info">
        <?php if (!empty($it['category_name']) || !empty($it['brand_name'])): ?><span class="ec-card-cat"><?= e($it['brand_name'] ?: $it['category_name']) ?></span><?php endif; ?>
        <h3><a href="<?= e($url) ?>"><?= e($it['name']) ?></a></h3>
        <div class="price-row">
          <span class="price-now">Rs. <?= number_format($p['price'], 0) ?></span>
          <?php if ($p['old_price']): ?><span class="price-old">Rs. <?= number_format($p['old_price'], 0) ?></span><?php endif; ?>
        </div>
        <?= payment_plan_html($p['price'], 'card', koko_item_teaser_price($it)) ?>
        <?= $summary ? product_card_variants_html($it, ['sizes' => [], 'colors' => $summary['colors'], 'total' => $summary['total']]) : '' ?>
      </div>
    </article>
    <?php
}

/** Renders a list of cards with their size/colour data + hover photos fetched in 2 queries. */
function render_product_cards(array $items): void
{
    $ids     = array_map(fn($i) => (int)$i['id'], $items);
    $summary = get_items_variation_summary($ids);
    $hover   = get_items_hover_images($ids);
    foreach ($items as $it) {
        render_product_card($it, $summary[(int)$it['id']] ?? null, $hover[(int)$it['id']] ?? null);
    }
}
