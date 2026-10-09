<?php
/**
 * The Wedded — Core Configuration
 * Handles DB connection (SQLite by default), session start, and paths.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('BASE_PATH', dirname(__DIR__));
define('UPLOADS_PATH', BASE_PATH . '/uploads');
define('UPLOADS_URL', base_url() . '/uploads');

/**
 * MySQL connection settings live in includes/db-config.php (not shipped with real
 * credentials — copy includes/db-config.sample.php to db-config.php and fill it in).
 */
$__dbConfigFile = __DIR__ . '/db-config.php';
if (!file_exists($__dbConfigFile)) {
    http_response_code(500);
    die(
        '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Setup required</title>' .
        '<style>body{font-family:Arial,sans-serif;background:#F6EFE6;color:#3E2A1E;max-width:640px;margin:60px auto;line-height:1.6;padding:0 20px}' .
        'code{background:#EADFD0;padding:2px 6px;border-radius:4px}</style></head><body>' .
        '<h2>Database setup required</h2>' .
        '<p>This site needs its MySQL connection details before it can run.</p>' .
        '<ol>' .
        '<li>In <code>includes/</code>, copy <code>db-config.sample.php</code> to a new file named <code>db-config.php</code>.</li>' .
        '<li>Open <code>db-config.php</code> and fill in the database host, name, username and password your hosting provider gave you.</li>' .
        '<li>Create that MySQL database (and user, with all privileges on it) from your host\'s control panel if you haven\'t already.</li>' .
        '<li>Reload this page — the required tables and default content will be created automatically.</li>' .
        '</ol>' .
        '</body></html>'
    );
}
require_once $__dbConfigFile;

/**
 * Detect the site base URL (works whether the app sits at domain root or a subfolder).
 */
function base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    // Strip trailing /admin if this was called from inside admin/
    $script = preg_replace('#/admin(/.*)?$#', '', $script);
    $script = rtrim($script, '/');
    return $scheme . $host . $script;
}

foreach (['hero', 'albums', 'site'] as $dir) {
    $p = UPLOADS_PATH . '/' . $dir;
    if (!is_dir($p)) @mkdir($p, 0775, true);
}

/**
 * Get a shared PDO (MySQL) connection. Creates schema + seed data on first run.
 */
function db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . (defined('DB_PORT') ? DB_PORT : '3306') .
           ';dbname=' . DB_NAME . ';charset=' . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        die(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Database connection error</title>' .
            '<style>body{font-family:Arial,sans-serif;background:#F6EFE6;color:#3E2A1E;max-width:640px;margin:60px auto;line-height:1.6;padding:0 20px}' .
            'code{background:#EADFD0;padding:2px 6px;border-radius:4px}</style></head><body>' .
            '<h2>Could not connect to the database</h2>' .
            '<p>Please double-check the host, database name, username and password in <code>includes/db-config.php</code>, ' .
            'and make sure the database has been created on your MySQL server.</p>' .
            '</body></html>'
        );
    }

    // Check whether the schema is present; if not, install it (first run).
    $check = $pdo->query("SHOW TABLES LIKE 'settings'")->fetchAll();
    if (empty($check)) {
        install_schema($pdo);
    }
    return $pdo;
}

function install_schema(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            skey VARCHAR(191) PRIMARY KEY,
            svalue MEDIUMTEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS admin_users (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(191) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS hero_slides (
            id INT PRIMARY KEY AUTO_INCREMENT,
            image VARCHAR(500) NOT NULL,
            title VARCHAR(500) DEFAULT '',
            subtitle VARCHAR(500) DEFAULT '',
            sort_order INT DEFAULT 0,
            active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS categories (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) UNIQUE NOT NULL,
            description TEXT,
            icon_no VARCHAR(20) DEFAULT '',
            sort_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS albums (
            id INT PRIMARY KEY AUTO_INCREMENT,
            category_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(255) UNIQUE NOT NULL,
            description TEXT,
            cover_image VARCHAR(500) DEFAULT '',
            location VARCHAR(255) DEFAULT '',
            featured TINYINT(1) DEFAULT 0,
            show_in_collection TINYINT(1) DEFAULT 1,
            sort_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (category_id),
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS album_images (
            id INT PRIMARY KEY AUTO_INCREMENT,
            album_id INT NOT NULL,
            image VARCHAR(500) NOT NULL,
            caption VARCHAR(500) DEFAULT '',
            sort_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (album_id),
            FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS inquiries (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255), email VARCHAR(255), phone VARCHAR(100),
            wedding_date VARCHAR(50), location VARCHAR(255), service VARCHAR(255),
            message TEXT,
            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Seed default settings
    $defaults = [
        'site_name'        => 'The Wedded',
        'tagline'          => 'Your story, beautifully remembered.',
        'logo'             => '',
        'logo_link'        => '/',
        'phone'            => '+94 77 123 4567',
        'whatsapp'         => '94771234567',
        'email'            => 'hello@thewedded.example',
        'address'          => 'Colombo, Sri Lanka',
        'facebook_url'     => 'https://www.facebook.com/theweddedphotography/',
        'instagram_url'    => '',
        'tiktok_url'       => '',
        'footer_about'     => 'Wedding photography rooted in emotion, elegance and honest storytelling.',
        'about_title'      => 'Photography for the moments you never want to forget.',
        'about_body'       => "The Wedded is a Colombo-based wedding photography studio dedicated to documenting celebrations with honesty, elegance and emotion. We believe the most meaningful photographs are often found in the moments between the moments — a quiet glance, a nervous smile, a parent's embrace, a laugh shared with friends.\n\nOur approach combines timeless composition, natural emotion and refined editorial storytelling to create photographs that feel beautiful today and meaningful for generations to come.",
        'about_image'      => '',
        'meta_description' => 'The Wedded is a Colombo-based wedding photography studio capturing timeless, emotional and cinematic wedding stories across Sri Lanka and beyond.',
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO settings (skey, svalue) VALUES (:k, :v)");
    foreach ($defaults as $k => $v) {
        $stmt->execute([':k' => $k, ':v' => $v]);
    }

    // Seed default admin user: admin / admin123
    $pdo->prepare("INSERT IGNORE INTO admin_users (username, password_hash) VALUES (:u, :p)")
        ->execute([':u' => 'admin', ':p' => password_hash('admin123', PASSWORD_DEFAULT)]);

    // Seed categories ("What We Capture")
    $cats = [
        ['Weddings', 'weddings', 'From quiet emotional moments to unforgettable celebrations, we document your wedding as it naturally unfolds.'],
        ['Pre-Weddings', 'pre-weddings', 'Relaxed, romantic sessions designed to capture your connection naturally, before the wedding day begins.'],
        ['Engagements', 'engagements', 'Beautifully documenting the beginning of your next chapter with photographs that feel genuine and timeless.'],
        ['Bridal Portraits', 'bridal-portraits', 'Elegant bridal portraits crafted with a refined editorial approach while preserving each bride\'s personality.'],
        ['Couples', 'couples', 'Natural and intimate portraits created around connection, movement and genuine emotion.'],
        ['Destination Weddings', 'destination-weddings', 'Available across Sri Lanka and for destination celebrations, wherever your story takes you.'],
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO categories (name, slug, description, sort_order) VALUES (:n,:s,:d,:o)");
    foreach ($cats as $i => $c) {
        $stmt->execute([':n' => $c[0], ':s' => $c[1], ':d' => $c[2], ':o' => $i]);
    }

    // Seed a sample album + hero slide so the site isn't empty on first run
    $catId = $pdo->query("SELECT id FROM categories WHERE slug='weddings'")->fetchColumn();
    if ($catId) {
        $pdo->prepare("INSERT INTO albums (category_id,title,slug,description,location,featured,show_in_collection,sort_order) VALUES (:c,:t,:s,:d,:l,1,1,0)")
            ->execute([
                ':c' => $catId, ':t' => 'Sarah & Daniel', ':s' => 'sarah-daniel',
                ':d' => 'A joyful celebration in Colombo.', ':l' => 'Colombo, Sri Lanka'
            ]);
    }
    $pdo->prepare("INSERT INTO hero_slides (image,title,subtitle,sort_order,active) VALUES ('', :t, :s, 0, 1)")
        ->execute([':t' => 'Your story,<br>beautifully remembered.', ':s' => 'Timeless wedding photography for couples who want to remember how it felt.']);
}

/** Read one setting value */
function setting($key, $default = '') {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $rows = db()->query("SELECT skey, svalue FROM settings")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $cache[$r['skey']] = $r['svalue'];
    }
    return $cache[$key] ?? $default;
}

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function slugify($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = trim($text, '-');
    $text = @iconv('utf-8', 'ascii//TRANSLIT', $text);
    if ($text === false) $text = preg_replace('~[^-\w]+~', '', $text);
    $text = strtolower($text);
    $text = preg_replace('~[^-a-z0-9]+~', '', $text);
    return $text ?: 'item-' . uniqid();
}

function unique_slug($table, $base, $ignoreId = null) {
    $pdo = db();
    $slug = slugify($base);
    $i = 1;
    while (true) {
        $sql = "SELECT COUNT(*) FROM {$table} WHERE slug = :s";
        if ($ignoreId) $sql .= " AND id != :id";
        $stmt = $pdo->prepare($sql);
        $params = [':s' => $slug];
        if ($ignoreId) $params[':id'] = $ignoreId;
        $stmt->execute($params);
        if ($stmt->fetchColumn() == 0) return $slug;
        $i++;
        $slug = slugify($base) . '-' . $i;
    }
}

function redirect($url) {
    header("Location: {$url}");
    exit;
}

function whatsapp_link($number, $text = '') {
    $number = preg_replace('/[^0-9]/', '', (string)$number);
    $url = "https://wa.me/{$number}";
    if ($text) $url .= '?text=' . rawurlencode($text);
    return $url;
}
