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
    'role_export'          => ['role', 'view'],
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
    // ── Pages that are not in the sidebar but write / delete data: they follow their menu page ──
    'attendance_monthly_report' => ['attendance_monthly_roster', 'view'],
    'attendance_batches'   => ['attendance_import', 'view'],
    'backup_helper'        => ['backup_settings', 'edit'],
    'api_reconcile'        => ['unilever_reconcile', 'edit'],
    'api_reconcile_unloading' => ['reconcile_unloading', 'edit'],
    'api_unloading_data'   => ['unloading_import_history', 'view'],
    'cheque_recon_history' => ['cheque_reconciliation', 'view'],
    'credit_bill_upload_history' => ['bulk_credit_bill_upload', 'view'],
    'emergency_credit_bill_upload_history' => ['emergency_credit_bill_upload', 'view'],
    'customer_claim_ajax'  => ['customer_claim', 'edit'],
    'delete_fs_update'     => ['field_summary_list', 'delete'],
    'delete_se_charge'     => ['fs_se', 'delete'],
    'difference_fix'       => ['difference_fix_history', 'edit'],
    'epf_report'           => ['epf_batches', 'view'],
    'etf_report'           => ['etf_batches', 'view'],
    'field_summary_scan_api' => ['field_summary_list', 'edit'],
    'import_fsum'          => ['import_history', 'create'],
    'import_unloading'     => ['unloading_import_history', 'create'],
    'process_unloading_import' => ['unloading_import_history', 'create'],
    'process_reimport_unloading' => ['unloading_import_history', 'edit'],
    'unloading_reimport_logs' => ['unloading_import_history', 'view'],
    'import_scheme_discount' => ['scheme_discount_receivable', 'create'],
    'scheme_import_history' => ['scheme_discount_receivable', 'view'],
    'invoice_wise_sales_upload' => ['invoice_wise_sales_history', 'create'],
    'primary_invoice_wise_sales_upload' => ['primary_invoice_wise_sales_history', 'create'],
    'process_secondary_import' => ['invoice_wise_sales_history', 'create'],
    'process_secondary_import_multidate' => ['invoice_wise_sales_history', 'create'],
    'secondary_import_multi' => ['invoice_wise_sales_history', 'create'],
    'ushop_items_import'   => ['ushop_items_import_history', 'create'],
    'invoices_data'        => ['invoices', 'view'],
    'outlet_day_transfer'  => ['outlet_service_map', 'edit'],
    'primary_sales_return' => ['purchase_return_summary_report', 'view'],
    'process_credit_bills' => ['bulk_credit_bill_upload', 'create'],
    'public_holidays'      => ['bank_holidays', 'view'],
    'save_cash_dp_pay'     => ['cc_cash_deposit_dp', 'edit'],
    'save_cash_shortage_report' => ['cash_shortage_employee_report', 'edit'],
    'save_credit_bill_issue' => ['credit_bill_issue', 'edit'],
    'save_credit_bill_verify' => ['credit_bill_issue', 'edit'],
    'save_scheme_settlement' => ['daily_scheme_discounts', 'edit'],
    'scheme_settlement_ajax' => ['daily_scheme_discounts', 'edit'],
    'save_sentback_issue'  => ['sentback_cheques', 'edit'],
    'save_unloading_pay'   => ['reconcile_unloading', 'edit'],
    'sscl_vat_new'         => ['sscl_vat', 'view'],
    'dal_cheque_ack'       => ['cheque_acknowledgments', 'view'],
    'dl_cheque_ack_new'    => ['cheque_acknowledgments', 'view'],
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
 * The tree shown on the Role page = the header.php sidebar ONLY
 * (Section -> Main menu -> Sub menu -> Page). Pages that are not in the sidebar
 * (imports, reports opened from other pages, add / edit / delete pages ...) get NO
 * permission row of their own; they follow their menu page through
 * $RBAC_RELATED_PAGES / rbac_infer_related(), or are login-only.
 */
function rbac_menu_tree() {
    return rbac_sidebar_tree();
}

/** Delete the cached sidebar / page lists so they are rebuilt from header.php on the next request */
function rbac_cache_clear() {
    $dir = rtrim(sys_get_temp_dir(), '/\\');
    foreach (['yms_rbac_side_', 'yms_rbac_extra_', 'yms_rbac_btn_'] as $pre) @unlink($dir . '/' . $pre . md5(__DIR__) . '.json');
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
    static $pre = ['delete_'=>'delete','remove_'=>'delete','edit_'=>'edit','update_'=>'edit','approve_'=>'edit','reverse_'=>'edit',
                   'mark_'=>'edit','link_'=>'edit','unlink_'=>'edit','settle_'=>'edit','bulk_'=>'edit','move_'=>'edit',
                   'add_'=>'create','quick_add_'=>'create','generate_'=>'create','genarate_'=>'create'];
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
    $files = glob(__DIR__ . '/*.php') ?: [];
    $names = [];
    foreach ($files as $f) $names[] = strtolower(basename($f, '.php'));
    sort($names);
    $sig = md5(implode('|', $names) . '|' . implode(',', array_keys(rbac_sidebar_pages())) . '|' . filemtime(__FILE__));
    $cache = rtrim(sys_get_temp_dir(), '/\\') . '/yms_rbac_extra_' . md5(__DIR__) . '.json';
    if (is_file($cache)) {
        $c = json_decode((string)@file_get_contents($cache), true);
        if (is_array($c) && ($c['sig'] ?? '') === $sig) return $out = $c['pages'];
    }
    $side = rbac_sidebar_pages();
    $skip = $GLOBALS['RBAC_ALWAYS_ALLOWED'];
    $out = [];
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
        $out[$p] = ['label' => $label, 'group' => $g];
    }
    @file_put_contents($cache, json_encode(['sig' => $sig, 'pages' => $out]));
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
    $ctx = ['role_id' => null, 'role_name' => null, 'full' => false, 'perms' => [], 'enforced' => false];

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
 *  GUARD  (called from config.php on every request)
 * ================================================================ */

/** Guess what the request is trying to do. Returns view|create|edit|delete|create_or_edit */
function rbac_detect_action() {
    $req = $_GET + $_POST;
    // keys that look like date / status filters, not delete requests
    $notDelete = '/^(deleted|delivery\w*|del_(from|to|date\w*)|delete_(from|to|date\w*)|deleted_\w+)$/i';

    // 1. ?delete=5, ?delete_import=3, ?del_id=7, ?remove_doc=1 ... (any non-empty value)
    foreach ($req as $k => $v) {
        if (!is_string($k) || is_array($v) && !$v) continue;
        if (preg_match('/^(delete|del|remove|trash|destroy|purge)(_[a-z0-9_]+)?$/i', $k) && !preg_match($notDelete, $k)
            && $v !== '' && $v !== '0' && $v !== 0) return 'delete';
    }
    // 2. action / act / op / do / mode = "delete_x" ...
    $act = '';
    foreach (['action', 'act', 'op', 'do', 'mode', 'task', 'type_action'] as $k) {
        if (isset($req[$k]) && is_string($req[$k]) && $req[$k] !== '') { $act = strtolower($req[$k]); break; }
    }
    if ($act !== '') {
        if (preg_match('/delete|remove|trash|destroy|purge|clear|reset_all|truncate/', $act)) return 'delete';
        if (preg_match('/^(get|fetch|load|list|search|lookup|view|print|export|download|preview|validate|template|check|test|show|read|find|count|filter)/', $act)) return 'view';
        if (preg_match('/^(save)/', $act)) return 'create_or_edit';
        if (preg_match('/^(add|create|insert|new|upload|import|quick_add|store|generate|process)|_(add|create|import|upload)$/', $act)) return 'create';
        if (preg_match('/edit|update|modify|toggle|approve|reject|settle|reverse|lock|unlock|bulk|rename|change|assign|set_|mark|cancel|verify|activate|deactivate|transfer|link/', $act)) return 'edit';
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        // submit buttons named like the action: name="update_x", "edit_x", "add_x", "save_x", "delete_x"
        foreach ($_POST as $k => $v) {
            if (!is_string($k)) continue;
            if (preg_match('/^(delete|remove)(_|$)/i', $k) && !preg_match($notDelete, $k)) return 'delete';
        }
        foreach ($_POST as $k => $v) {
            if (!is_string($k)) continue;
            if (preg_match('/^(update|edit|modify|approve|reverse|settle|mark|toggle)(_|$)/i', $k)) return 'edit';
        }
        foreach ($_POST as $k => $v) {
            if (!is_string($k)) continue;
            if (preg_match('/^(add|create|insert|new|import|upload)(_|$)/i', $k)) return 'create';
            if (preg_match('/^(save|submit)(_|$)/i', $k)) return 'create_or_edit';
        }
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

    rbac_guard_buttons($page);

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
    $msg    = "You do not have permission to " . ($labels[$action] ?? $action) . " \"$pname\".";

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
        'deny_btn' => rbac_btn_denied(),
    ]);
}

/**
 * Blocks delete / edit controls on EVERY page for roles without that right: the control is dimmed
 * and clicking it shows "Permission blocked" instead of doing anything. (The server also refuses the
 * request in rbac_guard(), this just gives immediate feedback.) Add data-perm="delete|edit|create" to
 * a control to force it, or data-perm-skip to leave a control alone.
 */
function rbac_client_script() {
    if (rbac_context()['full']) return '';
    return <<<'JS'
<style>.rbac-blocked{opacity:.4!important;cursor:not-allowed!important;filter:grayscale(1)}
#rbacToast{position:fixed;top:18px;right:18px;z-index:2147483647;background:#991b1b;color:#fff;padding:11px 16px;border-radius:9px;font:600 13px/1.3 system-ui,sans-serif;box-shadow:0 6px 20px rgba(0,0,0,.3);display:none;max-width:340px}</style>
<script>
(function(){
  var R = window.RBAC || {}; var can = R.can || {};
  if (R.full) return;
  var SEL = {
    delete: '[data-perm="delete"],a[href*="delete" i],a[href*="remove" i],a[href*="del="],a[href*="del_id"],button[onclick*="delete" i],button[onclick*="remove" i],a[onclick*="delete" i],.btn-delete,.delete-btn,.btn-danger[onclick],.fa-trash,.fa-trash-can,.fa-trash-alt,.fa-xmark-circle',
    edit:   '[data-perm="edit"],a[href*="edit" i],a[href*="update" i],button[onclick*="edit" i],a[onclick*="edit" i],.btn-edit,.edit-btn,.fa-pen,.fa-pen-to-square,.fa-edit,.fa-pencil',
    create: '[data-perm="create"]'
  };
  var MSG = {delete:'delete records here', edit:'edit records here', create:'add records here'};
  var DENY = R.deny_btn || [];
  function slug(t){ return String(t||'').toLowerCase().replace(/\s+/g,' ').trim().replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'').substring(0,40); }
  function btnKey(el){ var t=(el.getAttribute('data-perm-btn')||el.textContent||'').trim(); if(!t) t=el.getAttribute('title')||el.getAttribute('aria-label')||''; if(!t && el.value) t=el.value; return slug(t); }
  function toast(a){
    var t = document.getElementById('rbacToast');
    if(!t){ t = document.createElement('div'); t.id='rbacToast'; document.body.appendChild(t); }
    t.textContent = a==='btn' ? '⛔ Permission blocked: you are not allowed to use this button.' : '⛔ Permission blocked: you are not allowed to ' + MSG[a] + '.';
    t.style.display='block'; clearTimeout(t._h); t._h = setTimeout(function(){ t.style.display='none'; }, 3500);
  }
  function target(el){ return el.closest('a,button,[onclick],.btn') || el; }
  function markBtns(root){
    if(!DENY.length) return;
    root.querySelectorAll('button,a.btn,a[class*="btn"],a[class*="button"],input[type=submit],input[type=button],[role=button],[data-perm-btn]').forEach(function(el){
      if (el.closest('#sidebar,.sidebar,nav,[data-perm-skip]') || el.dataset.rbacBlock) return;
      if (DENY.indexOf(btnKey(el))<0) return;
      el.dataset.rbacBlock='btn'; el.classList.add('rbac-blocked'); if(el.tagName==='BUTTON'||el.tagName==='INPUT') el.setAttribute('aria-disabled','true');
    });
  }
  function mark(root){
    markBtns(root);
    ['delete','edit','create'].forEach(function(a){
      if (can[a] !== false) return;
      root.querySelectorAll(SEL[a]).forEach(function(el){
        var t = target(el);
        if (t.closest('#sidebar,.sidebar,nav,[data-perm-skip]') || t.dataset.rbacBlock) return;
        if (t.closest('form') && /(^|\s)(btn-primary|btn-save)(\s|$)/.test(t.className) && a!=='create') return;
        t.dataset.rbacBlock = a; t.classList.add('rbac-blocked');
      });
    });
  }
  document.addEventListener('click', function(e){
    var t = e.target.closest && e.target.closest('[data-rbac-block]');
    if (!t) return;
    e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
    toast(t.dataset.rbacBlock);
  }, true);
  document.addEventListener('submit', function(e){
    var f = e.target, s = e.submitter;
    if (s && s.dataset && s.dataset.rbacBlock){ e.preventDefault(); e.stopImmediatePropagation(); toast(s.dataset.rbacBlock); }
  }, true);
  function run(){ mark(document); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
  try { new MutationObserver(function(m){ mark(document); }).observe(document.documentElement, {childList:true, subtree:true}); } catch(e){}
})();
</script>
JS;
}


/* ================================================================
 *  BUTTON PERMISSIONS  (per page: Export, Print, Download, Approve ... each one can be given or taken away)
 *  Buttons are discovered from the page's own file, so a new button shows up in the role form by itself.
 *  Stored in role_page_buttons (role_id, page, btn_key, allowed). No row = allowed (old roles keep working).
 * ================================================================ */

/** key used for a button: lower-case words joined with _ (same rule as the browser script) */
function rbac_btn_slug($t) {
    $t = strtolower(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$t), ENT_QUOTES, 'UTF-8'))));
    $t = trim(preg_replace('/[^a-z0-9]+/', '_', $t), '_');
    return substr($t, 0, 40);
}

/** ['export_excel' => 'Export Excel', ...] for one menu page, read from its file */
function rbac_page_buttons($page) {
    static $memo = [];
    $page = strtolower($page);
    if (isset($memo[$page])) return $memo[$page];
    $file = __DIR__ . '/' . $page . '.php';
    if (!preg_match('/^[a-z0-9_\-]+$/', $page) || !is_file($file)) return $memo[$page] = [];
    $cache = rtrim(sys_get_temp_dir(), '/\\') . '/yms_rbac_btn_' . md5(__DIR__ . $page) . '.json';
    $mt = @filemtime($file);
    if (is_file($cache) && ($c = json_decode((string)@file_get_contents($cache), true)) && ($c['m'] ?? 0) === $mt) return $memo[$page] = $c['b'];

    $src = (string)@file_get_contents($file);
    $src = preg_replace('~<script\b.*?</script>~is', '', $src);                      // only markup, not JS strings
    $src = preg_replace('~<\?(?:php|=).*?\?>~is', ' ', $src);                         // PHP output is not a fixed label
    $src = preg_replace('~<!--.*?-->~s', '', $src);
    $found = [];
    $add = function ($label) use (&$found) {
        $label = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($label), ENT_QUOTES, 'UTF-8')));
        $label = trim($label, " \t\n\r\0\x0B:·•-–—|");
        if ($label === '' || mb_strlen($label) < 3 || mb_strlen($label) > 40 || !preg_match('/[A-Za-z]{3}/', $label)) return;
        if (preg_match('/^(close|cancel|back|no|yes|ok|okay|search|reset|clear|filter|apply|go|next|prev|previous|submit|login|logout|menu|toggle|home|dashboard|select|all|none|today)$/i', $label)) return;
        $k = rbac_btn_slug($label);
        if ($k !== '' && !isset($found[$k])) $found[$k] = $label;
    };
    if (preg_match_all('~<button\b[^>]*>(.*?)</button>~is', $src, $m)) foreach ($m[1] as $i => $inner) {
        $t = trim(strip_tags($inner));
        if ($t === '' && preg_match('~\b(?:title|aria-label)\s*=\s*"([^"]+)"~i', $m[0][$i], $tt)) $t = $tt[1];
        $add($t);
    }
    if (preg_match_all('~<a\b([^>]*)>(.*?)</a>~is', $src, $m)) foreach ($m[2] as $i => $inner) {
        if (!preg_match('~class\s*=\s*"[^"]*(?:btn|button)[^"]*"~i', $m[1][$i])) continue;
        $t = trim(strip_tags($inner));
        if ($t === '' && preg_match('~\b(?:title|aria-label)\s*=\s*"([^"]+)"~i', $m[1][$i], $tt)) $t = $tt[1];
        $add($t);
    }
    if (preg_match_all('~<input\b[^>]*type\s*=\s*"(?:submit|button)"[^>]*value\s*=\s*"([^"]+)"~i', $src, $m)) foreach ($m[1] as $v) $add($v);
    ksort($found);
    @file_put_contents($cache, json_encode(['m' => $mt, 'b' => $found]));
    return $memo[$page] = $found;
}

/** [page => [key => label]] for every menu page that has buttons */
function rbac_all_buttons() {
    $out = [];
    foreach (rbac_menu_pages() as $page => $info) {
        $b = rbac_page_buttons($page);
        if ($b) $out[strtolower($page)] = $b;
    }
    return $out;
}

/** page => [key => 0/1] explicitly saved for one role */
function rbac_role_buttons($role_id) {
    $out = [];
    $res = @rbac_q("SELECT page, btn_key, allowed FROM role_page_buttons WHERE role_id = " . intval($role_id));
    while ($res && ($r = mysqli_fetch_assoc($res))) $out[strtolower($r['page'])][$r['btn_key']] = (int)$r['allowed'];
    return $out;
}

/** can the signed-in user use this button on this page?  (full access / no row = yes) */
function rbac_btn_can($key, $page = null) {
    $ctx = rbac_context();
    if ($ctx['full'] || !$ctx['enforced'] || !$ctx['role_id']) return true;
    static $mine = null;
    if ($mine === null) $mine = rbac_role_buttons($ctx['role_id']);
    $target = rbac_resolve($page ?: rbac_current_page())[0] ?? null;
    if ($target === null) return true;
    return !isset($mine[strtolower($target)][$key]) || $mine[strtolower($target)][$key] === 1;
}

/** keys the signed-in user may NOT use on the current page (sent to the browser script) */
function rbac_btn_denied() {
    $ctx = rbac_context();
    if ($ctx['full'] || !$ctx['enforced'] || !$ctx['role_id']) return [];
    $target = rbac_resolve(rbac_current_page())[0] ?? null;
    if ($target === null) return [];
    $mine = rbac_role_buttons($ctx['role_id']);
    $d = [];
    foreach ($mine[strtolower($target)] ?? [] as $k => $a) if (!$a) $d[] = $k;
    return $d;
}

/** server side: a denied Export / Print / Download button also stops the request itself, not just the button */
function rbac_guard_buttons($page) {
    $denied = rbac_btn_denied();
    if (!$denied) return;
    $req = $_GET + $_POST;
    $kind = '';
    foreach ($req as $k => $v) {
        if (!is_string($k)) continue;
        $val = is_string($v) ? strtolower($v) : '';
        if (preg_match('/^(export|download|excel|csv|pdf|print)(_|$)/i', $k) && $v !== '' && $v !== '0') { $kind = strtolower($k); break; }
        if (in_array($k, ['action', 'act', 'do', 'op', 'mode', 'task'], true) && preg_match('/^(export|download|excel|csv|pdf|print)/', $val)) { $kind = $val; break; }
    }
    if ($kind === '') return;
    $group = preg_match('/print/', $kind) ? 'print' : (preg_match('/pdf/', $kind) ? 'pdf' : (preg_match('/csv/', $kind) ? 'csv' : (preg_match('/excel|xls/', $kind) ? 'excel' : 'export')));
    foreach ($denied as $key) {
        if (strpos($key, $group) !== false || ($group !== 'print' && strpos($key, 'export') !== false) || ($group !== 'export' && strpos($key, 'download') !== false)) {
            $pages = rbac_menu_pages(); $target = rbac_resolve($page)[0] ?? $page;
            $pname = $pages[$target]['label'] ?? ucwords(str_replace('_', ' ', $page));
            $msg = 'Permission blocked: you are not allowed to use "' . ($group === 'print' ? 'Print' : 'Export / Download') . '" on ' . $pname . '.';
            if (rbac_is_ajax()) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => $msg, 'message' => $msg]); exit; }
            http_response_code(403); echo '<!doctype html><meta charset="utf-8"><body style="font-family:system-ui;padding:40px;color:#991b1b"><h3>⛔ ' . htmlspecialchars($msg) . '</h3><p><a href="javascript:history.back()">Go back</a></p>'; exit;
        }
    }
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
