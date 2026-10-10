<?php
// role_helpers.php - shared helpers for the role create / edit / delete pages.
// Permissions are stored per PAGE (module_name = page file name without ".php")
// in the existing `permissions` table, so auth.php hasPermission($page, 'access|create|edit|delete') works unchanged.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
requireLogin();

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS roles (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS permissions (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    role_id INT(11) NOT NULL,
    module_name VARCHAR(100) NOT NULL,
    can_access TINYINT(1) DEFAULT 0,
    can_create TINYINT(1) DEFAULT 0,
    can_edit   TINYINT(1) DEFAULT 0,
    can_delete TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    UNIQUE KEY unique_role_module (role_id, module_name)
)");

/** Gate: needs the 'role' page permission. Bootstrap: if nobody has it yet, any logged-in user may enter. */
function roleRequire($action) {
    global $conn;
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM permissions WHERE module_name='role' AND can_access=1"));
    if ((int)$r['c'] === 0) return;
    if (!hasPermission('role', 'access') || ($action !== 'access' && !hasPermission('role', $action))) {
        http_response_code(403);
        die('You do not have permission to ' . htmlspecialchars($action) . ' roles.');
    }
}

function roleCan($action) {
    global $conn;
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM permissions WHERE module_name='role' AND can_access=1"));
    return (int)$r['c'] === 0 || hasPermission('role', $action);
}

function csrfToken() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrfCheck() {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        die('Invalid or expired form token. Go back and try again.');
    }
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Catalogue as tree: [section][main][sub or ''][] = ['key'=>, 'label'=>] */
function menuTree() {
    $tree = [];
    foreach (require __DIR__ . '/menu_catalog.php' as [$sec, $main, $sub, $key, $label]) {
        $tree[$sec][$main][$sub ?? ''][] = ['key' => $key, 'label' => $label];
    }
    return $tree;
}
function allPageKeys() {
    $k = [];
    foreach (require __DIR__ . '/menu_catalog.php' as $row) $k[$row[3]] = true;
    return array_keys($k);
}

function loadRolePerms($role_id) {
    global $conn;
    $p = [];
    $res = mysqli_query($conn, "SELECT * FROM permissions WHERE role_id = " . (int)$role_id);
    while ($r = mysqli_fetch_assoc($res)) $p[$r['module_name']] = $r;
    return $p;
}

/** Save posted perm[page][access|create|edit|delete] for every catalogue page. */
function saveRolePerms($role_id, $posted) {
    global $conn;
    $role_id = (int)$role_id;
    foreach (allPageKeys() as $key) {
        $a = isset($posted[$key]['access']) ? 1 : 0;
        // add/edit/delete only make sense with view access
        $c = $a && isset($posted[$key]['create']) ? 1 : 0;
        $e = $a && isset($posted[$key]['edit'])   ? 1 : 0;
        $d = $a && isset($posted[$key]['delete']) ? 1 : 0;
        $mk = mysqli_real_escape_string($conn, $key);
        mysqli_query($conn, "INSERT INTO permissions (role_id, module_name, can_access, can_create, can_edit, can_delete)
            VALUES ($role_id, '$mk', $a, $c, $e, $d)
            ON DUPLICATE KEY UPDATE can_access=$a, can_create=$c, can_edit=$e, can_delete=$d");
    }
}
