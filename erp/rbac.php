<?php
/**
 * rbac.php — Page-based Role & Permission system for YMS
 * ------------------------------------------------------------------
 *  • The permission tree is built automatically from the sidebar in
 *    header.php  (Section → Main Menu → Sub Menu → Page).
 *    Add a page to the sidebar and it shows up on the Role page.
 *  • Each page has 4 rights: View / Add / Edit / Delete,
 *    stored in the existing `permissions` table
 *    (module_name = page file name without ".php").
 *  • A role with "Full Access" bypasses every check (Super Admin).
 *  • rbac_guard() runs from config.php and blocks direct URL access
 *    and obvious add / edit / delete requests the role is not allowed.
 *
 *  Helpers you can use in any page:
 *      rbac_can('view'|'create'|'edit'|'delete', 'page_name' = current)
 *      canView(), canCreate(), canEdit(), canDelete()
 *      rbac_require('delete')          // stop the request with 403
 *  In HTML:  <button data-perm="create">  is hidden when not allowed.
 * ------------------------------------------------------------------
 */

if (defined('RBAC_LOADED')) return;
define('RBAC_LOADED', true);

/* ================================================================
 *  SETTINGS
 * ================================================================ */

// Pages every logged-in user may open, whatever the role.
$GLOBALS['RBAC_ALWAYS_ALLOWED'] = ['index', 'login', 'logout', 'login_process', 'profile', 'help',
                                    'session_extend', 'access_denied'];

// Users with NO role assigned: true = full access (old behaviour), false = dashboard only.
if (!defined('RBAC_NO_ROLE_FULL_ACCESS')) define('RBAC_NO_ROLE_FULL_ACCESS', false);

/*
 * Pages that are NOT in the sidebar but belong to a menu page.
 * 'child_page' => ['parent_page', 'right']   right = view | create | edit | delete
 * Pages not in the sidebar and not listed here are only login-protected.
 */
$GLOBALS['RBAC_RELATED_PAGES'] = [
    // Users / roles
    'role_form'            => ['role', 'view'],       // add / edit right is checked inside the page
    'user_permissons'      => ['role', 'edit'],
    'get_role'             => ['role', 'view'],
    'get_user'             => ['users', 'view'],
    // Employees
    'add_employee'         => ['employees', 'create'],
    'edit_employee'        => ['employees', 'edit'],
    'delete_employee'      => ['employees', 'delete'],
    'view_employee'        => ['employees', 'view'],
    'role_add'             => ['role', 'create'],
    'role_edit'            => ['role', 'edit'],
    'role_delete'          => ['role', 'delete'],
    'role_list'            => ['role', 'view'],
    // Customers
    'add_customer'         => ['customers', 'create'],
    'quick_add_customer'   => ['customers', 'create'],
    'edit_customer'        => ['customers', 'edit'],
    'approve_customer'     => ['customers', 'edit'],
    // Loans / advances
    'add_loan'             => ['loans', 'create'],
    'edit_loan'            => ['loans', 'edit'],
    'add_salary_advance'   => ['salary_advance', 'create'],
    'edit_salary_advance'  => ['salary_advance', 'edit'],
    'bulk_salary_advance'  => ['salary_advance', 'create'],
    // Leave
    'leave_add'            => ['leave_list', 'create'],
    'leave_application'    => ['leave_list', 'create'],
    'leave_edit'           => ['leave_list', 'edit'],
    // Field summary
    'edit_field_summary'   => ['field_summary_list', 'edit'],
    'update_field_summary' => ['field_summary_list', 'edit'],
    'view_field_summary'   => ['field_summary_list', 'view'],
    'delete_field_summary' => ['field_summary_list', 'delete'],
    'delete_field_summary_details' => ['field_summary_list', 'delete'],
    // Invoices / payments
    'view_invoice'         => ['invoices', 'view'],
    'delete_invoice'       => ['invoices', 'delete'],
    'delete_payment'       => ['payments', 'delete'],
    'update_payment'       => ['payments', 'edit'],
    'view_payments'        => ['payments', 'view'],
    // Cheques
    'cheque_edit'          => ['cheques', 'edit'],
    'update_cheque_details'=> ['cheques', 'edit'],
    // Vehicles
    'view_vehicle'         => ['vehicles', 'view'],
    'vehicle_schedule_view'=> ['vehicle_schedule', 'view'],
    // Cash float
    'cash_float_create'      => ['cash_float', 'create'],
    'cash_float_add_expense' => ['cash_float', 'create'],
    'cash_float_add_payment' => ['cash_float', 'create'],
];

/* ================================================================
 *  SCHEMA  (safe to call many times — only runs on role pages)
 * ================================================================ */
function rbac_q($sql) {
    global $conn;
    try { return mysqli_query($conn, $sql); }
    catch (Throwable $e) { return false; }
}

function rbac_column_exists($table, $column) {
    global $conn;
    $t = mysqli_real_escape_string($conn, $table);
    $c = mysqli_real_escape_string($conn, $column);
    $r = rbac_q("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $r && mysqli_num_rows($r) > 0;
}

function rbac_ensure_schema() {
    global $conn;

    rbac_q("CREATE TABLE IF NOT EXISTS roles (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        role_name VARCHAR(100) NOT NULL UNIQUE,
        description TEXT NULL,
        active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    rbac_q("CREATE TABLE IF NOT EXISTS permissions (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        role_id INT(11) NOT NULL,
        module_name VARCHAR(150) NOT NULL,
        can_access TINYINT(1) DEFAULT 0,
        can_create TINYINT(1) DEFAULT 0,
        can_edit   TINYINT(1) DEFAULT 0,
        can_delete TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_role_module (role_id, module_name),
        CONSTRAINT fk_perm_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $first_install = !rbac_column_exists('roles', 'full_access');
    if ($first_install) {
        rbac_q("ALTER TABLE roles ADD COLUMN full_access TINYINT(1) NOT NULL DEFAULT 0 AFTER description");
    }
    if (!rbac_column_exists('roles', 'created_by')) {
        rbac_q("ALTER TABLE roles ADD COLUMN created_by INT(11) NULL, ADD COLUMN updated_by INT(11) NULL");
    }
    // old pages created module_name as VARCHAR(50)
    rbac_q("ALTER TABLE permissions MODIFY module_name VARCHAR(150) NOT NULL");

    rbac_q("CREATE TABLE IF NOT EXISTS role_audit_log (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        role_id INT(11) NULL,
        role_name VARCHAR(100) NULL,
        action VARCHAR(30) NOT NULL,
        details TEXT NULL,
        user_id INT(11) NULL,
        username VARCHAR(100) NULL,
        ip_address VARCHAR(45) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_role (role_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // per-button rights (only rows that differ from the page-level default are stored)
    rbac_q("CREATE TABLE IF NOT EXISTS role_page_buttons (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        role_id INT(11) NOT NULL,
        page VARCHAR(150) NOT NULL,
        btn_key VARCHAR(80) NOT NULL,
        allowed TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_role_page_btn (role_id, page, btn_key),
        CONSTRAINT fk_rpb_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── Lock-out protection on first install ─────────────────────
    if ($first_install) {
        // Any role whose name contains "admin" becomes Full Access
        rbac_q("UPDATE roles SET full_access = 1 WHERE LOWER(role_name) LIKE '%admin%'");

        // The person running the install keeps full access
        if (!empty($_SESSION['user_id'])) {
            $uid = intval($_SESSION['user_id']);
            $u = rbac_q("SELECT role_id FROM users WHERE id = $uid");
            $u = $u ? mysqli_fetch_assoc($u) : null;
            if ($u && $u['role_id']) {
                rbac_q("UPDATE roles SET full_access = 1 WHERE id = " . intval($u['role_id']));
            } else {
                rbac_q("INSERT IGNORE INTO roles (role_name, description, full_access, active)
                        VALUES ('Super Admin', 'Full access to every page', 1, 1)");
                $r = mysqli_fetch_assoc(rbac_q("SELECT id FROM roles WHERE role_name='Super Admin'"));
                if ($r) rbac_q("UPDATE users SET role_id = " . intval($r['id']) . " WHERE id = $uid");
            }
        }
    }
}

/* ================================================================
 *  MENU TREE  (parsed from header.php sidebar, cached)
 * ================================================================ */

/**
 * Returns:
 * [ ['title'=>'Main Menu','icon'=>'fa-grip','menus'=>[
 *       ['type'=>'page','label'=>'Dashboard','page'=>'index','icon'=>'...'],
 *       ['type'=>'menu','label'=>'Master','icon'=>'...','items'=>[
 *            ['type'=>'page', ...],
 *            ['type'=>'group','label'=>'Leave & Attendance','icon'=>'...','items'=>[ pages… ]],
 *       ]],
 * ]] ]
 */
/* ---------- small file cache (keeps every request fast) ----------
 * The folder scans (pages not in the sidebar, tagged buttons, the final tree) are
 * stored for RBAC_CACHE_TTL seconds and are dropped when header.php / rbac.php change.
 * The Role page always rebuilds them, so a new page or button shows up there at once.   */
if (!defined('RBAC_CACHE_TTL')) define('RBAC_CACHE_TTL', 900);
function rbac_cache_file($name) { return rtrim(sys_get_temp_dir(), '/\\') . '/yms_rbac_' . $name . '_' . md5(__DIR__) . '.json'; }
function rbac_cache_ver() {
    static $v = null;
    if ($v === null) $v = @filemtime(__DIR__ . '/header.php') . '-' . @filemtime(__FILE__);
    return $v;
}
function rbac_cache_read($name) {
    $f = rbac_cache_file($name);
    if (!is_file($f) || time() - (int)@filemtime($f) > RBAC_CACHE_TTL) return null;
    $c = json_decode((string)@file_get_contents($f), true);
    return (is_array($c) && ($c['v'] ?? '') === rbac_cache_ver() && array_key_exists('d', $c)) ? $c['d'] : null;
}
function rbac_cache_write($name, $data) { @file_put_contents(rbac_cache_file($name), json_encode(['v' => rbac_cache_ver(), 'd' => $data])); }
function rbac_cache_clear() { foreach (['extra', 'btns', 'tree'] as $n) @unlink(rbac_cache_file($n)); }

function rbac_sidebar_tree() {
    static $tree = null;
    if ($tree !== null) return $tree;

    $header = __DIR__ . '/header.php';
    if (!is_file($header)) return $tree = [];

    $cache = rtrim(sys_get_temp_dir(), '/\\') . '/yms_rbac_side_' . md5(__DIR__) . '.json';
    $mtime = filemtime($header) . '-' . filemtime(__FILE__);   // re-parse when header.php or rbac.php changes
    if (is_file($cache)) {
        $c = json_decode((string)@file_get_contents($cache), true);
        if (is_array($c) && ($c['mtime'] ?? 0) === $mtime && isset($c['tree'])) return $tree = $c['tree'];
    }

    $src = file_get_contents($header);
    $s = stripos($src, '<aside class="sidebar"');
    $e = stripos($src, '</aside>', $s ?: 0);
    if ($s === false || $e === false) return $tree = [];
    $html = substr($src, $s, $e - $s + 8);
    $html = preg_replace('/<\?php.*?\?>/s', '', $html);      // drop PHP blocks
    $html = preg_replace('/<\?=.*?\?>/s', '', $html);

    $dom = rbac_dom_load($html);
    $xp  = new DOMXPath($dom);
    $tree = [];

    foreach ($xp->query('//div[contains(concat(" ",normalize-space(@class)," ")," menu-section ")]') as $sec) {
        $titleNode = $xp->query('.//div[contains(@class,"menu-title")]', $sec)->item(0);
        $section = [
            'title' => $titleNode ? rbac_clean_text($titleNode->textContent) : 'Menu',
            'icon'  => $titleNode ? rbac_icon($xp, $titleNode) : '',
            'menus' => [],
        ];
        $children = rbac_element_children($sec);
        for ($i = 0; $i < count($children); $i++) {
            $el = $children[$i];
            if (rbac_has_class($el, 'menu-title')) continue;

            if ($el->nodeName === 'a' && ($p = rbac_href_page($el->getAttribute('href')))) {
                $section['menus'][] = ['type'=>'page','label'=>rbac_link_label($el),'page'=>$p,'icon'=>rbac_icon($xp,$el)];
                continue;
            }
            $nextEl = $children[$i + 1] ?? null;
            if (rbac_has_class($el, 'has-submenu') || (rbac_has_class($el, 'menu-item') && $nextEl && rbac_has_class($nextEl, 'submenu'))) {
                $labelNode = $xp->query('.//div[contains(@class,"menu-item-content")]/span[not(contains(@class,"menu-icon"))]', $el)->item(0);
                $menu = [
                    'type'  => 'menu',
                    'label' => $labelNode ? rbac_clean_text($labelNode->textContent) : rbac_clean_text($el->textContent),
                    'icon'  => rbac_icon($xp, $el),
                    'items' => [],
                ];
                $next = $children[$i + 1] ?? null;
                if ($next && rbac_has_class($next, 'submenu')) {
                    $menu['items'] = rbac_parse_submenu($xp, $next);
                    $i++;
                }
                if ($menu['items']) $section['menus'][] = $menu;
            }
        }
        // pages every user may open (Dashboard…) need no permission row
        $skip = $GLOBALS['RBAC_ALWAYS_ALLOWED'];
        $keep = fn($p) => $p['type'] !== 'page' || !in_array($p['page'], $skip, true);
        $section['menus'] = array_values(array_filter($section['menus'], $keep));
        foreach ($section['menus'] as &$m) {
            if ($m['type'] !== 'menu') continue;
            $m['items'] = array_values(array_filter($m['items'], $keep));
            foreach ($m['items'] as &$it) if ($it['type'] === 'group') $it['items'] = array_values(array_filter($it['items'], $keep));
            unset($it);
            $m['items'] = array_values(array_filter($m['items'], fn($it) => $it['type'] === 'page' || $it['items']));
        }
        unset($m);
        $section['menus'] = array_values(array_filter($section['menus'], fn($m) => $m['type'] === 'page' || $m['items']));
        if ($section['menus']) $tree[] = $section;
    }

    @file_put_contents($cache, json_encode(['mtime' => $mtime, 'tree' => $tree]));
    return $tree;
}

function rbac_parse_submenu($xp, $submenu) {
    $items = [];
    $group = null;                       // current flat heading group (div.submenu-group)
    $kids  = rbac_element_children($submenu);
    for ($i = 0; $i < count($kids); $i++) {
        $el = $kids[$i];

        if (rbac_has_class($el, 'submenu-group')) {           // small heading
            if ($group && $group['items']) $items[] = $group;
            $group = ['type'=>'group','label'=>rbac_clean_text($el->textContent),'icon'=>rbac_icon($xp,$el),'items'=>[]];
            continue;
        }
        if (rbac_has_class($el, 'has-submenu')) {               // nested sub menu
            if ($group && $group['items']) { $items[] = $group; }
            $group = null;
            $span = $xp->query('.//span/span', $el)->item(0) ?: $xp->query('.//span', $el)->item(0);
            $g = ['type'=>'group','label'=>rbac_clean_text($span ? $span->textContent : $el->textContent),
                  'icon'=>rbac_icon($xp,$el),'items'=>[]];
            $next = $kids[$i + 1] ?? null;
            if ($next && rbac_has_class($next, 'submenu')) {
                foreach (rbac_parse_submenu($xp, $next) as $sub) {
                    if ($sub['type'] === 'page') $g['items'][] = $sub;
                    else foreach ($sub['items'] as $pp) $g['items'][] = $pp;   // flatten deeper levels
                }
                $i++;
            }
            if ($g['items']) $items[] = $g;
            continue;
        }
        if ($el->nodeName === 'a' && ($p = rbac_href_page($el->getAttribute('href')))) {
            $page = ['type'=>'page','label'=>rbac_link_label($el),'page'=>$p,'icon'=>rbac_icon($xp,$el)];
            if ($group) $group['items'][] = $page; else $items[] = $page;
            continue;
        }
        // wrappers (e.g. <div> around links) — dig inside
        if ($el->nodeName === 'div' && !rbac_has_class($el, 'submenu')) {
            foreach ($xp->query('.//a[@href]', $el) as $a) {
                if ($p = rbac_href_page($a->getAttribute('href'))) {
                    $page = ['type'=>'page','label'=>rbac_link_label($a),'page'=>$p,'icon'=>rbac_icon($xp,$a)];
                    if ($group) $group['items'][] = $page; else $items[] = $page;
                }
            }
        }
    }
    if ($group && $group['items']) $items[] = $group;
    return $items;
}

/** Flat list for a tree: page => ['label'=>..,'section'=>..,'menu'=>..,'group'=>..] (first occurrence wins) */
function rbac_flatten_tree($tree) {
    $flat = [];
    foreach ($tree as $sec) {
        foreach ($sec['menus'] as $m) {
            if ($m['type'] === 'page') { $flat += [$m['page'] => ['label'=>$m['label'],'section'=>$sec['title'],'menu'=>$m['label'],'group'=>'']]; continue; }
            foreach ($m['items'] as $it) {
                if ($it['type'] === 'page') { $flat += [$it['page'] => ['label'=>$it['label'],'section'=>$sec['title'],'menu'=>$m['label'],'group'=>'']]; }
                else foreach ($it['items'] as $p) $flat += [$p['page'] => ['label'=>$p['label'],'section'=>$sec['title'],'menu'=>$m['label'],'group'=>$it['label']]];
            }
        }
    }
    return $flat;
}

/** Pages that are in the header.php sidebar only */
function rbac_sidebar_pages() {
    static $flat = null;
    if ($flat === null) $flat = rbac_flatten_tree(rbac_sidebar_tree());
    return $flat;
}

/** Every page that has its own permission row: sidebar pages + pages not in the sidebar */
function rbac_menu_pages() {
    static $flat = null;
    if ($flat === null) $flat = rbac_flatten_tree(rbac_menu_tree());
    return $flat;
}

/**
 * The tree shown on the Role page: the sidebar PLUS an extra section
 * "Other Pages (not in sidebar)" holding every report / page that exists in the
 * folder but has no sidebar link (reports opened from other pages, import pages,
 * edit / delete pages, history pages…), so each one can be allowed or blocked.
 */
function rbac_menu_tree() {
    static $tree = null;
    if ($tree !== null) return $tree;
    if (($c = rbac_cache_read('tree')) !== null) return $tree = $c;
    $tree = rbac_sidebar_tree();
    $extra = rbac_unlisted_pages();
    if (!$extra) return $tree;

    // 1. pages that belong to a sidebar page → shown right under it, in the same menu / sub menu
    $byParent = []; $loose = [];
    foreach ($extra as $page => $info) {
        if (!empty($info['parent'])) $byParent[$info['parent']][$page] = $info; else $loose[$page] = $info;
    }
    $placed = [];
    $kids = function ($parent) use (&$byParent, &$placed) {
        if (empty($byParent[$parent]) || isset($placed[$parent])) return [];
        $placed[$parent] = true;
        $list = [];
        foreach ($byParent[$parent] as $pg => $in) {
            $list[] = ['type'=>'page','label'=>'↳ ' . $in['label'],'page'=>$pg,'icon'=>'fa-arrow-turn-down','related'=>$parent];
        }
        usort($list, fn($x, $y) => strcasecmp($x['label'], $y['label']));
        return $list;
    };
    $withKids = function ($items) use ($kids) {
        $out = [];
        foreach ($items as $it) { $out[] = $it; if ($it['type'] === 'page') foreach ($kids($it['page']) as $k) $out[] = $k; }
        return $out;
    };
    foreach ($tree as &$sec) {
        foreach ($sec['menus'] as &$m) {
            if ($m['type'] !== 'menu') continue;
            foreach ($m['items'] as &$it) if ($it['type'] === 'group') $it['items'] = $withKids($it['items']);
            unset($it);
            $m['items'] = $withKids($m['items']);
        }
        unset($m);
        $sec['menus'] = $withKids($sec['menus']);
    }
    unset($sec);
    foreach ($byParent as $par => $list) {            // parent not found in the tree → treat as loose
        if (!isset($placed[$par])) foreach ($list as $pg => $in) $loose[$pg] = $in;
    }

    // 2. pages with no obvious parent → one extra section
    $groups = [];
    $gicons = ['Reports'=>'fa-chart-line', 'History & Logs'=>'fa-clock-rotate-left', 'Imports & Uploads'=>'fa-file-import',
               'Add / Edit / Delete Pages'=>'fa-pen-to-square', 'Detail / View Pages'=>'fa-eye', 'Other Pages'=>'fa-file'];
    foreach ($loose as $page => $info) {
        $g = $info['group'];
        $groups[$g][] = ['type'=>'page','label'=>$info['label'],'page'=>$page,'icon'=>$gicons[$g] ?? 'fa-file'];
    }
    $menus = [];
    foreach (array_keys($gicons) as $g) {
        if (empty($groups[$g])) continue;
        $items = $groups[$g];
        usort($items, fn($x, $y) => strcasecmp($x['label'], $y['label']));
        $menus[] = ['type'=>'menu','label'=>$g,'icon'=>$gicons[$g],'items'=>$items];
    }
    if ($menus) $tree[] = ['title'=>'Other Pages (not linked to a menu)', 'icon'=>'fa-folder-tree', 'menus'=>$menus];
    rbac_cache_write('tree', $tree);
    return $tree;
}

/* ---------- pages that are not in the sidebar ---------- */

/** Files that are not real pages (includes, ajax / api / print helpers, copies) — login only, no own permission row */
function rbac_is_helper_page($p) {
    static $exact = ['access_denied','auth','config','default','footer','functions','header','index','login','login_process','logout',
        'privacy_policy','rbac','session_extend','simplexlsx','xlsx_lite','crc_helper','vt_functions','permission_matrix',
        'test_gemini_key','chk_ak','upload_signature','online_presence','search_recreate_targets','risk_reason_lookup'];
    if (in_array($p, $exact, true)) return true;
    if (preg_match('/\(\d+\)$/', $p)) return true;                                            // "page(1)" backup copies
    if (preg_match('/^(get|save|process|validate|api|test)_/', $p)) return true;               // data / save endpoints
    if (preg_match('/_(ajax|api|lib|helper|handler|proxy|receiver|cron|callback|diag|widget|common|mailer|data|download|guard)$/', $p)) return true;
    if (preg_match('/(^|_)print(_|$)|^printfs|_print_bw$|^slip_wise_print/', $p)) return true; // print views belong to the page that opens them
    if (preg_match('/^(hikvision|google_drive|face_recognition|fingerprint)/', $p)) return true;
    return false;
}

/** Action prefixes / suffixes → the right needed on the parent page */
function rbac_action_right($p) {
    static $pre = ['delete_'=>'delete','remove_'=>'delete','edit_'=>'edit','update_'=>'edit','approve_'=>'edit','add_'=>'create','quick_add_'=>'create'];
    foreach ($pre as $k => $r) if (strpos($p, $k) === 0) return [$k, $r];
    if (preg_match('/_(edit|add|delete)$/', $p, $m)) return ['_' . $m[1], ['edit'=>'edit','add'=>'create','delete'=>'delete'][$m[1]]];
    return null;
}

/** "delete_field_summary_details" → ['field_summary_list','delete'] when such a sidebar page exists */
function rbac_infer_related($p) {
    $a = rbac_action_right($p);
    if (!$a) return null;
    [$mark, $right] = $a;
    $stem = ($mark[0] === '_') ? substr($p, 0, -strlen($mark)) : substr($p, strlen($mark));
    $side = rbac_sidebar_pages();
    $tok  = explode('_', $stem);
    for ($n = count($tok); $n >= 1; $n--) {
        $base = implode('_', array_slice($tok, 0, $n));
        foreach ([$base, $base . 's', rtrim($base, 's'), $base . '_list', $base . '_report', $base . '_summary'] as $c) {
            if ($c !== $p && isset($side[$c])) return [$c, $right];
        }
    }
    return null;
}

/** page => ['label'=>, 'group'=>] for pages with no sidebar link and no parent page (cached by file list) */
function rbac_unlisted_pages() {
    static $out = null;
    if ($out !== null) return $out;
    if (($c = rbac_cache_read('extra')) !== null) return $out = $c;
    $files = glob(__DIR__ . '/*.php') ?: [];
    $names = [];
    foreach ($files as $f) $names[] = strtolower(basename($f, '.php'));
    sort($names);
    $side = rbac_sidebar_pages();
    $skip = $GLOBALS['RBAC_ALWAYS_ALLOWED'];
    $out = [];
    // text of every sidebar page, used to see which page opens / calls which
    $sideSrc = [];
    foreach (array_keys($side) as $sp) {
        $f = __DIR__ . '/' . $sp . '.php';
        $sideSrc[$sp] = is_file($f) ? (string)@file_get_contents($f) : '';
    }
    $lead = function ($a, $b) {                       // number of leading "_" words two page names share
        $x = explode('_', $a); $y = explode('_', $b); $n = 0;
        while ($n < count($x) && $n < count($y) && $x[$n] === $y[$n]) $n++;
        return $n;
    };
    foreach ($names as $p) {
        if (isset($side[$p]) || in_array($p, $skip, true) || isset($GLOBALS['RBAC_RELATED_PAGES'][$p])) continue;
        if (rbac_is_helper_page($p)) continue;
        if (rbac_infer_related($p)) continue;
        // a nice label: $page_title / <title> / file name
        $label = '';
        $src = @file_get_contents(__DIR__ . '/' . $p . '.php', false, null, 0, 6000);
        if ($src !== false) {
            if (preg_match('/\$page_title\s*=\s*[\'"]([^\'"$<]{3,80})[\'"]/', $src, $m)) $label = trim($m[1]);
            elseif (preg_match('/<title>([^<$?]{3,80})<\/title>/i', $src, $m)) $label = trim($m[1]);
        }
        if ($label === '') $label = ucwords(str_replace('_', ' ', $p));
        if (rbac_action_right($p))                                    $g = 'Add / Edit / Delete Pages';
        elseif (strpos($p, 'report') !== false)                       $g = 'Reports';
        elseif (preg_match('/history|_logs?$|^logs?_/', $p))          $g = 'History & Logs';
        elseif (preg_match('/import|upload/', $p))                    $g = 'Imports & Uploads';
        elseif (preg_match('/^view_|_view$/', $p))                    $g = 'Detail / View Pages';
        else                                                          $g = 'Other Pages';
        // parent: the sidebar page that links to / calls this page; else the one with the most similar name
        $parent = ''; $best = 0;
        foreach ($sideSrc as $sp => $txt) {
            if ($txt !== '' && preg_match('/[\'"\/=\s(]' . preg_quote($p, '/') . '\.php/', $txt)) {
                $score = 100 + $lead($sp, $p);
                if ($score > $best) { $best = $score; $parent = $sp; }
            }
        }
        if ($parent === '') {
            foreach ($side as $sp => $_) {
                $n = $lead($sp, $p);
                if ($n >= 2 && $n > $best) { $best = $n; $parent = $sp; }
            }
        }
        $out[$p] = ['label' => $label, 'group' => $g, 'parent' => $parent];
    }
    rbac_cache_write('extra', $out);
    return $out;
}

/* ---------- DOM helpers ---------- */
function rbac_dom_load($html) {
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $dom->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $dom;
}
function rbac_element_children($node) {
    $out = [];
    foreach ($node->childNodes as $c) if ($c->nodeType === XML_ELEMENT_NODE) $out[] = $c;
    return $out;
}
function rbac_has_class($el, $cls) {
    return $el instanceof DOMElement && preg_match('/(^|\s)' . preg_quote($cls, '/') . '(\s|$)/', $el->getAttribute('class'));
}
function rbac_clean_text($t) { return trim(preg_replace('/\s+/u', ' ', html_entity_decode($t, ENT_QUOTES, 'UTF-8'))); }
function rbac_link_label($a) {
    $spans = $a->getElementsByTagName('span');
    $t = $spans->length ? $spans->item($spans->length - 1)->textContent : $a->textContent;
    return rbac_clean_text($t);
}
function rbac_icon($xp, $el) {
    $i = $xp->query('.//i[contains(@class,"fa-") and not(contains(@class,"submenu-toggle")) and not(contains(@class,"chevron"))]', $el)->item(0);
    if (!$i) return '';
    preg_match_all('/fa-[a-z0-9-]+/', $i->getAttribute('class'), $m);
    $icons = array_diff($m[0], ['fa-solid', 'fa-regular', 'fa-brands']);
    return $icons ? reset($icons) : '';
}
/** "company.php?x=1" → "company";  "#", "javascript:" → null */
function rbac_href_page($href) {
    $href = trim((string)$href);
    if ($href === '' || $href[0] === '#' || stripos($href, 'javascript:') === 0 || preg_match('#^https?://#i', $href)) return null;
    $path = parse_url($href, PHP_URL_PATH);
    if (!$path || !preg_match('/([A-Za-z0-9_\-\(\)]+)\.php$/', $path, $m)) return null;
    return strtolower($m[1]);
}

/* ================================================================
 *  CURRENT USER & PERMISSIONS
 * ================================================================ */
function rbac_installed() {
    static $ok = null;
    if ($ok === null) $ok = rbac_column_exists('roles', 'full_access');
    return $ok;
}

/** ['role_id'=>, 'role_name'=>, 'full'=>bool, 'perms'=>[page=>['view'=>1,...]]] */
function rbac_context() {
    static $ctx = null, $for_user = null;
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) @session_start();
    $uid_now = $_SESSION['user_id'] ?? null;
    if ($ctx !== null && $for_user === $uid_now) return $ctx;
    $for_user = $uid_now;
    $ctx = ['role_id' => null, 'role_name' => null, 'full' => false, 'perms' => [], 'btns' => [], 'enforced' => false];

    if (empty($_SESSION['user_id'])) return $ctx;
    if (!rbac_installed()) { $ctx['full'] = true; return $ctx; }     // not installed yet → no enforcement
    $ctx['enforced'] = true;

    $uid = intval($_SESSION['user_id']);
    $r = rbac_q("SELECT u.role_id, r.role_name, r.full_access, r.active AS role_active
                 FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = $uid LIMIT 1");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    if (!$row) return $ctx;

    if (!$row['role_id'] || $row['role_name'] === null) {          // no role assigned
        $ctx['full'] = RBAC_NO_ROLE_FULL_ACCESS;
        return $ctx;
    }
    $ctx['role_id']   = intval($row['role_id']);
    $ctx['role_name'] = $row['role_name'];
    if (!$row['role_active']) return $ctx;                          // inactive role → nothing
    if ($row['full_access']) { $ctx['full'] = true; return $ctx; }

    $res = rbac_q("SELECT module_name, can_access, can_create, can_edit, can_delete
                   FROM permissions WHERE role_id = " . $ctx['role_id']);
    while ($res && ($p = mysqli_fetch_assoc($res))) {
        $ctx['perms'][strtolower($p['module_name'])] = [
            'view'   => (int)$p['can_access'],
            'create' => (int)$p['can_create'],
            'edit'   => (int)$p['can_edit'],
            'delete' => (int)$p['can_delete'],
        ];
    }
    $res = rbac_q("SELECT page, btn_key, allowed FROM role_page_buttons WHERE role_id = " . $ctx['role_id']);
    while ($res && ($b = mysqli_fetch_assoc($res))) {
        $ctx['btns'][strtolower($b['page'])][$b['btn_key']] = (int)$b['allowed'];
    }
    return $ctx;
}

function rbac_current_page() {
    $f = basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    return strtolower(preg_replace('/\.php$/i', '', $f));
}

/** Resolve a page to the menu page whose rights apply + the minimum right needed to open it */
function rbac_resolve($page) {
    $page = strtolower($page);
    $menu = rbac_menu_pages();
    if (isset($menu[$page])) return [$page, 'view'];
    if (isset($GLOBALS['RBAC_RELATED_PAGES'][$page])) return $GLOBALS['RBAC_RELATED_PAGES'][$page];
    if ($inf = rbac_infer_related($page)) return $inf;   // delete_x / edit_x / add_x → page x
    return [null, null];   // unprotected (only login)
}

/**
 * rbac_can('edit')                 current page
 * rbac_can('delete', 'customers')  a given page
 * $action: view|access|create|add|edit|update|delete
 */
function rbac_can($action = 'view', $page = null) {
    $map = ['access'=>'view','add'=>'create','new'=>'create','update'=>'edit'];
    $action = $map[$action] ?? $action;
    $page = strtolower(preg_replace('/\.php$/i', '', $page ?? rbac_current_page()));

    $ctx = rbac_context();
    if (empty($_SESSION['user_id'])) return false;
    if ($ctx['full']) return true;
    if (in_array($page, $GLOBALS['RBAC_ALWAYS_ALLOWED'], true)) return true;

    [$target, $need] = rbac_resolve($page);
    if ($target === null) return true;                // not in menu / not mapped → login only
    if ($target !== $page && $action === 'view') {
        $action = $need;                              // e.g. opening add_employee needs employees.create
    }
    $p = $ctx['perms'][$target] ?? null;
    if ((!$p || empty($p['view'])) && ($par = (rbac_unlisted_pages()[$target]['parent'] ?? ''))) {
        $p = $ctx['perms'][$par] ?? null;             // a page opened from a menu page (form, history, view…) with no setting of its own → rights of that menu page
    }
    if (!$p || empty($p['view'])) return false;       // no view right = nothing on that page
    return !empty($p[$action]);
}
function canView($page = null)   { return rbac_can('view', $page); }
function canCreate($page = null) { return rbac_can('create', $page); }
function canEdit($page = null)   { return rbac_can('edit', $page); }
function canDelete($page = null) { return rbac_can('delete', $page); }

/** Stop the request when the right is missing. */
function rbac_require($action = 'view', $page = null) {
    if (!rbac_can($action, $page)) rbac_deny($action, $page ?? rbac_current_page());
}

/* ================================================================
 *  BUTTON PERMISSIONS
 *  Tag a button / link in any page:
 *      <button data-btn="export_excel" data-btn-label="Export Excel">            (needs only View)
 *      <button data-btn="add_invoice" data-btn-label="Add Invoice" data-perm="create">
 *  The tag makes it appear under that page on the Role page, where each button
 *  can be switched on / off for a role.  data-perm (create|edit|delete) is the
 *  default: a role that has that page right gets the button until it is switched off.
 *  The button is hidden automatically; protect the server side with
 *      rbac_require_btn('export_excel');      // or  if (rbac_can_btn('export_excel')) …
 * ================================================================ */

/** page => [ key => ['label'=>, 'right'=>view|create|edit|delete] ]  (cached; rescanned when a file changes) */
function rbac_all_buttons() {
    static $all = null;
    if ($all !== null) return $all;
    if (($c = rbac_cache_read('btns')) !== null) return $all = $c;
    $files = glob(__DIR__ . '/*.php') ?: [];
    $all = [];
    foreach ($files as $f) {
        $src = (string)@file_get_contents($f);
        if (strpos($src, 'data-btn=') === false) continue;
        $page = strtolower(basename($f, '.php'));
        if (!preg_match_all('/<[a-z]+\b[^>]*\bdata-btn="([a-z0-9_]+)"[^>]*>/i', $src, $m, PREG_SET_ORDER)) continue;
        foreach ($m as $tag) {
            $key = $tag[1];
            if (isset($all[$page][$key])) continue;
            $label = preg_match('/data-btn-label="([^"]+)"/', $tag[0], $l) ? $l[1] : ucwords(str_replace('_', ' ', $key));
            $right = preg_match('/data-perm="[^"]*\b(create|edit|delete)\b/', $tag[0], $r) ? $r[1] : 'view';
            $all[$page][$key] = ['label' => $label, 'right' => $right];
        }
    }
    rbac_cache_write('btns', $all);
    return $all;
}
function rbac_buttons_for($page) { $a = rbac_all_buttons(); return $a[strtolower($page)] ?? []; }

/** May the current user use this button?  (page view right is always needed) */
function rbac_can_btn($key, $page = null) {
    $page = strtolower(preg_replace('/\.php$/i', '', $page ?? rbac_current_page()));
    if (empty($_SESSION['user_id'])) return false;
    $ctx = rbac_context();
    if ($ctx['full']) return true;
    if (in_array($page, $GLOBALS['RBAC_ALWAYS_ALLOWED'], true)) return true;
    [$target] = rbac_resolve($page);
    if ($target === null) return true;                        // page not under permission control
    if (!rbac_can('view', $page)) return false;
    if (isset($ctx['btns'][$target][$key])) return (bool)$ctx['btns'][$target][$key];   // explicit setting of the role
    $def = rbac_buttons_for($target)[$key]['right'] ?? 'view';                          // otherwise the page-level right
    return $def === 'view' ? true : rbac_can($def, $target);
}
function rbac_require_btn($key, $page = null) {
    if (!rbac_can_btn($key, $page)) {
        $p = $page ?? rbac_current_page();
        $lbl = rbac_buttons_for($p)[$key]['label'] ?? $key;
        $GLOBALS['RBAC_BTN_DENIED'] = $lbl;
        rbac_deny('btn:' . $lbl, $p);
    }
}
/** CSS that hides the buttons of the current page this user may not use */
function rbac_btn_css() {
    if (empty($_SESSION['user_id'])) return '';
    $css = '';
    try {
        foreach (array_keys(rbac_buttons_for(rbac_current_page())) as $k) {
            if (!rbac_can_btn($k)) $css .= '[data-btn="' . $k . '"]{display:none!important}';
        }
    } catch (Throwable $e) { return ''; }
    return $css;
}

/* ================================================================
 *  GUARD  (called from config.php on every request)
 * ================================================================ */

/** Guess what the request is trying to do. Returns view|create|edit|delete|create_or_edit */
function rbac_detect_action() {
    $req = $_GET + $_POST;
    foreach (['delete', 'delete_id', 'del', 'del_id', 'remove', 'remove_id', 'trash'] as $k) {
        if (isset($req[$k]) && $req[$k] !== '') return 'delete';
    }
    $act = strtolower((string)($req['action'] ?? $req['act'] ?? $req['op'] ?? ''));
    if ($act !== '') {
        if (preg_match('/^(get|fetch|load|list|search|lookup|view|print|export|download|preview|validate|template|check|test)/', $act)) return 'view';
        if (preg_match('/delete|remove|trash|destroy|purge/', $act)) return 'delete';
        if (preg_match('/^(save)/', $act)) return 'create_or_edit';
        if (preg_match('/^(add|create|insert|new|upload|import|quick_add|store|generate|process)|_(add|create)$/', $act)) return 'create';
        if (preg_match('/edit|update|modify|toggle|approve|reject|settle|reverse|lock|unlock|bulk|rename|change|assign/', $act)) return 'edit';
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        foreach (['update', 'edit', 'edit_id', 'update_id'] as $k) if (isset($_POST[$k])) return 'edit';
        foreach (['add', 'create', 'insert', 'save', 'submit'] as $k) if (isset($_POST[$k])) return 'create_or_edit';
    }
    return 'view';
}

function rbac_guard() {
    if (PHP_SAPI === 'cli') return;
    $page = rbac_current_page();
    if (in_array($page, $GLOBALS['RBAC_ALWAYS_ALLOWED'], true)) return;
    [$target] = rbac_resolve($page);
    if ($target === null) return;                         // not a menu / related page

    if (session_status() === PHP_SESSION_NONE) @session_start();
    if (empty($_SESSION['user_id'])) return;              // page's own requireLogin() handles it
    $ctx = rbac_context();
    if ($ctx['full']) return;

    if (!rbac_can('view', $page)) rbac_deny(rbac_resolve($page)[1] ?: 'view', $page);

    $act = rbac_detect_action();
    if ($act === 'create_or_edit') {
        if (!rbac_can('create', $page) && !rbac_can('edit', $page)) rbac_deny('create', $page);
    } elseif ($act !== 'view' && !rbac_can($act, $page)) {
        rbac_deny($act, $page);
    }
}

function rbac_is_ajax() {
    $xrw = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    $acc = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    return $xrw === 'xmlhttprequest' || strpos($acc, 'application/json') !== false
        || (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_REQUEST['action']) && strpos($acc, 'text/html') === false);
}

function rbac_deny($action, $page) {
    $labels = ['view'=>'view', 'create'=>'add records on', 'edit'=>'edit records on', 'delete'=>'delete records on'];
    $pages  = rbac_menu_pages();
    [$target] = rbac_resolve($page);
    $pname  = $pages[$target ?? $page]['label'] ?? ucwords(str_replace('_', ' ', $page));
    $msg    = strpos($action, 'btn:') === 0
        ? "You do not have permission to use \"" . substr($action, 4) . "\" on \"$pname\"."
        : "You do not have permission to " . ($labels[$action] ?? $action) . " \"$pname\".";

    if (!headers_sent()) http_response_code(403);
    if (rbac_is_ajax()) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['success' => false, 'status' => 'error', 'error' => $msg, 'message' => $msg, 'msg' => $msg]);
        exit;
    }
    $GLOBALS['RBAC_DENIED_MESSAGE'] = $msg;
    $GLOBALS['RBAC_DENIED_ACTION']  = $action;
    if (!defined('RBAC_RENDERING_DENIED')) {
        define('RBAC_RENDERING_DENIED', true);
        if (ob_get_level()) { while (ob_get_level()) ob_end_clean(); }
        include __DIR__ . '/access_denied.php';
    }
    exit;
}

/* ================================================================
 *  SIDEBAR FILTER  (header.php wraps the <aside> in ob_start)
 * ================================================================ */
function rbac_filter_sidebar($html) {
    $ctx = rbac_context();
    if ($ctx['full'] || trim($html) === '') return $html;

    $dom = rbac_dom_load($html);
    $xp  = new DOMXPath($dom);

    // 1. remove links the role may not view
    foreach (iterator_to_array($xp->query('//div[contains(@class,"menu-section")]//a[@href]')) as $a) {
        $p = rbac_href_page($a->getAttribute('href'));
        if ($p && !in_array($p, $GLOBALS['RBAC_ALWAYS_ALLOWED'], true) && !rbac_can('view', $p)) {
            $a->parentNode->removeChild($a);
        }
    }
    // 2. remove empty sub-menus (+ their toggle), deepest first, until stable
    do {
        $changed = false;
        foreach (iterator_to_array($xp->query('//div[contains(concat(" ",normalize-space(@class)," ")," submenu ")]')) as $sm) {
            if ($xp->query('.//a[@href]', $sm)->length === 0) {
                $prev = $sm->previousSibling;
                while ($prev && $prev->nodeType !== XML_ELEMENT_NODE) $prev = $prev->previousSibling;
                if ($prev && (rbac_has_class($prev, 'has-submenu') || rbac_has_class($prev, 'menu-item'))
                    && $prev->nodeName !== 'a') $prev->parentNode->removeChild($prev);
                $sm->parentNode->removeChild($sm);
                $changed = true;
            }
        }
    } while ($changed);
    // 3. remove group headings with no links after them
    foreach (iterator_to_array($xp->query('//div[contains(@class,"submenu-group")]')) as $g) {
        $n = $g->nextSibling; $has = false;
        while ($n) {
            if ($n->nodeType === XML_ELEMENT_NODE) {
                if (rbac_has_class($n, 'submenu-group')) break;
                if ($n->nodeName === 'a' || $xp->query('.//a[@href]', $n)->length) { $has = true; break; }
            }
            $n = $n->nextSibling;
        }
        if (!$has) $g->parentNode->removeChild($g);
    }
    // 4. remove sections with no links
    foreach (iterator_to_array($xp->query('//div[contains(@class,"menu-section")]')) as $sec) {
        if ($xp->query('.//a[@href]', $sec)->length === 0) $sec->parentNode->removeChild($sec);
    }

    $out = '';
    $body = $dom->getElementsByTagName('body')->item(0);
    foreach ($body->childNodes as $c) $out .= $dom->saveHTML($c);
    return $out;
}

/** <body> classes + JS object so pages can hide buttons: <button data-perm="delete"> */
function rbac_body_classes() {
    $c = [];
    foreach (['create', 'edit', 'delete'] as $a) if (!rbac_can($a)) $c[] = "rbac-no-$a";
    return implode(' ', $c);
}
function rbac_js() {
    $ctx = rbac_context();
    return json_encode([
        'page' => rbac_current_page(),
        'role' => $ctx['role_name'],
        'full' => (bool)$ctx['full'],
        'can'  => ['view'=>rbac_can('view'),'create'=>rbac_can('create'),'edit'=>rbac_can('edit'),'delete'=>rbac_can('delete')],
    ]);
}

/* ================================================================
 *  CSRF + AUDIT helpers for the role pages
 * ================================================================ */
function rbac_csrf_token() {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    if (empty($_SESSION['rbac_csrf'])) $_SESSION['rbac_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['rbac_csrf'];
}
function rbac_csrf_check() {
    $t = $_POST['csrf'] ?? '';
    return is_string($t) && !empty($_SESSION['rbac_csrf']) && hash_equals($_SESSION['rbac_csrf'], $t);
}
function rbac_audit($role_id, $role_name, $action, $details = '') {
    global $conn;
    $rid = $role_id ? intval($role_id) : 'NULL';
    $rn  = mysqli_real_escape_string($conn, (string)$role_name);
    $ac  = mysqli_real_escape_string($conn, $action);
    $dt  = mysqli_real_escape_string($conn, $details);
    $uid = !empty($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 'NULL';
    $un  = mysqli_real_escape_string($conn, (string)($_SESSION['username'] ?? ''));
    $ip  = mysqli_real_escape_string($conn, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    rbac_q("INSERT INTO role_audit_log (role_id, role_name, action, details, user_id, username, ip_address)
            VALUES ($rid, '$rn', '$ac', '$dt', $uid, '$un', '$ip')");
}
