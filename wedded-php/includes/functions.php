<?php
/**
 * The Wedded — Shared helper functions used by both the public site and admin panel.
 */

/** ---------- CSRF ---------- */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}
function csrf_check() {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        die('Invalid or expired form submission. Please go back and try again.');
    }
}

/** ---------- File uploads ---------- */
/**
 * Handle an image upload from $_FILES[$field]. Returns relative path (e.g. "albums/xyz.jpg") or null.
 */
function handle_image_upload($field, $subdir) {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Upload error code: ' . $file['error']);
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset($allowed[$mime])) {
        throw new Exception('Only JPG, PNG, WEBP or GIF images are allowed.');
    }
    if ($file['size'] > 12 * 1024 * 1024) {
        throw new Exception('Image is larger than the 12MB limit.');
    }
    $ext = $allowed[$mime];
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destDir = UPLOADS_PATH . '/' . $subdir;
    if (!is_dir($destDir)) mkdir($destDir, 0775, true);
    $dest = $destDir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new Exception('Could not save the uploaded file.');
    }
    return $subdir . '/' . $name;
}

/** Build a public URL for an uploaded image path, with a graceful fallback placeholder. */
function image_url($relPath, $fallback = null) {
    if ($relPath) {
        return UPLOADS_URL . '/' . ltrim($relPath, '/');
    }
    return $fallback;
}

/** Delete an uploaded file (best-effort) given its relative path. */
function delete_upload($relPath) {
    if (!$relPath) return;
    $full = UPLOADS_PATH . '/' . ltrim($relPath, '/');
    if (is_file($full)) @unlink($full);
}

/** ---------- Data access: front-end ---------- */
function get_active_hero_slides() {
    return db()->query("SELECT * FROM hero_slides WHERE active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
}
function get_categories() {
    return db()->query("SELECT * FROM categories ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
}
function get_category_by_slug($slug) {
    $stmt = db()->prepare("SELECT * FROM categories WHERE slug = :s");
    $stmt->execute([':s' => $slug]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function get_category($id) {
    $stmt = db()->prepare("SELECT * FROM categories WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function get_collection_albums($categorySlug = null) {
    $sql = "SELECT a.*, c.name AS category_name, c.slug AS category_slug
            FROM albums a JOIN categories c ON c.id = a.category_id
            WHERE a.show_in_collection = 1";
    $params = [];
    if ($categorySlug && $categorySlug !== 'all') {
        $sql .= " AND c.slug = :slug";
        $params[':slug'] = $categorySlug;
    }
    $sql .= " ORDER BY a.sort_order ASC, a.id DESC";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function get_featured_albums($limit = 3) {
    $stmt = db()->prepare("SELECT a.*, c.name AS category_name, c.slug AS category_slug
                            FROM albums a JOIN categories c ON c.id = a.category_id
                            WHERE a.featured = 1
                            ORDER BY a.sort_order ASC, a.id DESC LIMIT :lim");
    $stmt->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function get_album_by_slug($slug) {
    $stmt = db()->prepare("SELECT a.*, c.name AS category_name, c.slug AS category_slug
                            FROM albums a JOIN categories c ON c.id = a.category_id
                            WHERE a.slug = :s");
    $stmt->execute([':s' => $slug]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function get_album($id) {
    $stmt = db()->prepare("SELECT * FROM albums WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function get_albums_by_category($categoryId) {
    $stmt = db()->prepare("SELECT * FROM albums WHERE category_id = :c ORDER BY sort_order ASC, id DESC");
    $stmt->execute([':c' => $categoryId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function get_album_images($albumId) {
    $stmt = db()->prepare("SELECT * FROM album_images WHERE album_id = :a ORDER BY sort_order ASC, id ASC");
    $stmt->execute([':a' => $albumId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function count_album_images($albumId) {
    $stmt = db()->prepare("SELECT COUNT(*) FROM album_images WHERE album_id = :a");
    $stmt->execute([':a' => $albumId]);
    return (int)$stmt->fetchColumn();
}

/** ---------- Misc ---------- */
function time_ago($datetime) {
    $ts = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 2592000) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $ts);
}

function current_path() {
    return parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
}
function nav_active($file) {
    return (basename(current_path()) === $file) ? ' aria-current="page" class="active"' : '';
}
