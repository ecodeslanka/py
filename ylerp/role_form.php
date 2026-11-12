<?php
/**
 * role_form.php — Add / Edit a role and its page permissions
 *   role_form.php          → new role   (needs role.create)
 *   role_form.php?id=5     → edit role  (needs role.edit, otherwise read-only view)
 */
include_once 'config.php';
include_once 'auth.php';
require_once __DIR__ . '/rbac.php';
requireLogin();
rbac_ensure_schema();

$id      = intval($_GET['id'] ?? $_POST['role_id'] ?? 0);
$is_edit = $id > 0;
$role    = ['role_name' => '', 'description' => '', 'active' => 1, 'full_access' => 0];

if ($is_edit) {
    $role = mysqli_fetch_assoc(rbac_q("SELECT * FROM roles WHERE id = $id"));
    if (!$role) { $_SESSION['rbac_flash'] = ['type' => 'bad', 'msg' => 'Role not found.']; header('Location: role.php'); exit; }
    $can_save = canEdit('role');
} else {
    if (!canCreate('role')) rbac_deny('create', 'role');
    $can_save = true;
}

$me_role_id = intval((mysqli_fetch_assoc(rbac_q("SELECT role_id FROM users WHERE id = " . intval($_SESSION['user_id']))) ?: [])['role_id'] ?? 0);
$is_mine    = $is_edit && $id === $me_role_id;

$tree       = rbac_menu_tree();
$menu_pages = rbac_menu_pages();
$errors     = [];
$flash      = $_SESSION['rbac_flash'] ?? null; unset($_SESSION['rbac_flash']);

/* ─────────────── current permissions ─────────────── */
$perms = [];
if ($is_edit) {
    $res = rbac_q("SELECT * FROM permissions WHERE role_id = $id");
    while ($res && ($p = mysqli_fetch_assoc($res))) {
        $perms[strtolower($p['module_name'])] = ['view' => (int)$p['can_access'], 'create' => (int)$p['can_create'],
                                                 'edit' => (int)$p['can_edit'], 'delete' => (int)$p['can_delete']];
    }
}

/* ─────────────── page buttons (Export, Print, Approve ... one tick each) ─────────────── */
$all_btns  = function_exists('rbac_all_buttons') ? rbac_all_buttons() : [];
$btn_saved = ($is_edit && function_exists('rbac_role_buttons')) ? rbac_role_buttons($id) : [];

/* ─────────────── SAVE ─────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_save)            rbac_deny($is_edit ? 'edit' : 'create', 'role');
    if (!rbac_csrf_check())    $errors[] = 'Your session expired. Please submit again.';

    $name   = trim($_POST['role_name'] ?? '');
    $desc   = trim($_POST['description'] ?? '');
    $active = isset($_POST['active']) ? 1 : 0;
    $full   = isset($_POST['full_access']) ? 1 : 0;

    if ($name === '')                         $errors[] = 'Role name is required.';
    elseif (mb_strlen($name) > 100)           $errors[] = 'Role name must be 100 characters or less.';
    else {
        $en = mysqli_real_escape_string($conn, $name);
        if (mysqli_num_rows(rbac_q("SELECT id FROM roles WHERE role_name = '$en' AND id <> $id")))
            $errors[] = "A role named \"" . htmlspecialchars($name) . "\" already exists.";
    }
    // never lock yourself out
    if ($is_mine) {
        $active = 1;
        if ($role['full_access']) $full = 1;
    }

    // collect matrix
    $posted = $_POST['perm'] ?? [];
    $new_perms = [];
    foreach ($menu_pages as $page => $info) {
        $row = $posted[$page] ?? [];
        $v = !empty($row['view']); $c = !empty($row['create']); $e = !empty($row['edit']); $d = !empty($row['delete']);
        if ($c || $e || $d) $v = true;               // any right implies view
        if ($v) $new_perms[$page] = ['view' => 1, 'create' => (int)$c, 'edit' => (int)$e, 'delete' => (int)$d];
    }
    // button rights: ticked = allowed. Only pages that have buttons are touched
    $new_btns = [];
    $posted_b = $_POST['btn'] ?? [];
    foreach ($all_btns as $bpage => $bl) {
        foreach ($bl as $bk => $blabel) $new_btns[$bpage][$bk] = !empty($posted_b[$bpage][$bk]) ? 1 : 0;
    }
    if ($is_mine && !$full) {                        // keep access to this page
        $new_perms['role'] = ['view' => 1, 'create' => (int)($new_perms['role']['create'] ?? 0), 'edit' => 1, 'delete' => (int)($new_perms['role']['delete'] ?? 0)];
    }

    if (!$errors) {
        $en = mysqli_real_escape_string($conn, $name);
        $ed = mysqli_real_escape_string($conn, $desc);
        $uid = intval($_SESSION['user_id']);
        mysqli_begin_transaction($conn);
        try {
            if ($is_edit) {
                $ok = rbac_q("UPDATE roles SET role_name='$en', description='$ed', active=$active, full_access=$full, updated_by=$uid WHERE id=$id");
            } else {
                $ok = rbac_q("INSERT INTO roles (role_name, description, active, full_access, created_by) VALUES ('$en','$ed',$active,$full,$uid)");
                $id = mysqli_insert_id($conn);
            }
            if (!$ok || !$id) throw new Exception(mysqli_error($conn) ?: 'Could not save role.');

            // replace the rows of menu pages; keep any other (legacy) rows
            $keys = array_map(fn($p) => "'" . mysqli_real_escape_string($conn, $p) . "'", array_keys($menu_pages));
            if ($keys) rbac_q("DELETE FROM permissions WHERE role_id = $id AND module_name IN (" . implode(',', $keys) . ")");
            if ($new_perms) {
                $vals = [];
                foreach ($new_perms as $page => $p) {
                    $vals[] = "($id,'" . mysqli_real_escape_string($conn, $page) . "',1,{$p['create']},{$p['edit']},{$p['delete']})";
                }
                foreach (array_chunk($vals, 200) as $chunk) {
                    if (!rbac_q("INSERT INTO permissions (role_id, module_name, can_access, can_create, can_edit, can_delete) VALUES "
                                . implode(',', $chunk) . " ON DUPLICATE KEY UPDATE can_access=VALUES(can_access), can_create=VALUES(can_create),
                                  can_edit=VALUES(can_edit), can_delete=VALUES(can_delete)"))
                        throw new Exception(mysqli_error($conn));
                }
            }
            if ($new_btns) {
                rbac_q("CREATE TABLE IF NOT EXISTS role_page_buttons (
                    id INT(11) AUTO_INCREMENT PRIMARY KEY, role_id INT(11) NOT NULL, page VARCHAR(150) NOT NULL,
                    btn_key VARCHAR(80) NOT NULL, allowed TINYINT(1) NOT NULL DEFAULT 0, UNIQUE KEY uq_role_page_btn (role_id, page, btn_key))");
                rbac_q("DELETE FROM role_page_buttons WHERE role_id = $id");
                $bv = [];
                foreach ($new_btns as $bpage => $bl) foreach ($bl as $bk => $al)
                    if (!$al || isset($new_perms[$bpage])) $bv[] = "($id,'" . mysqli_real_escape_string($conn, $bpage) . "','" . mysqli_real_escape_string($conn, $bk) . "',$al)";
                foreach (array_chunk($bv, 300) as $chunk)
                    if (!rbac_q("INSERT INTO role_page_buttons (role_id, page, btn_key, allowed) VALUES " . implode(',', $chunk))) throw new Exception(mysqli_error($conn));
            }
            mysqli_commit($conn);
        } catch (Throwable $ex) {
            mysqli_rollback($conn);
            if (!$is_edit) $id = 0;
            $errors[] = 'Database error: ' . htmlspecialchars($ex->getMessage());
        }
        if (!$errors) {
            $cnt = ['c' => 0, 'e' => 0, 'd' => 0];
            foreach ($new_perms as $p) { $cnt['c'] += $p['create']; $cnt['e'] += $p['edit']; $cnt['d'] += $p['delete']; }
            $nb = 0; foreach ($new_btns as $bpage => $bl) if (isset($new_perms[$bpage])) $nb += count(array_filter($bl));
            $summary = $full ? 'Full access' : count($new_perms) . " pages (add {$cnt['c']}, edit {$cnt['e']}, delete {$cnt['d']}, buttons $nb)";
            rbac_audit($id, $name, $is_edit ? 'update' : 'create', $summary);
            $_SESSION['rbac_flash'] = ['type' => 'ok', 'msg' => "Role <b>" . htmlspecialchars($name) . "</b> " . ($is_edit ? 'updated' : 'created') . " — $summary."];
            header('Location: ' . (isset($_POST['save_stay']) ? "role_form.php?id=$id" : 'role.php'));
            exit;
        }
    }
    // re-show posted values
    $role  = array_merge($role, ['role_name' => $name, 'description' => $desc, 'active' => $active, 'full_access' => $full]);
    $perms = $new_perms;
}

/* ─────────────── extra data ─────────────── */
$assigned = [];
if ($is_edit) {
    $res = rbac_q("SELECT id, username, active FROM users WHERE role_id = $id ORDER BY username");
    while ($res && ($u = mysqli_fetch_assoc($res))) $assigned[] = $u;
}
// other roles' permissions for "Copy from role"
$other_roles = []; $other_perms = [];
$res = rbac_q("SELECT id, role_name, full_access FROM roles WHERE id <> $id ORDER BY role_name");
while ($res && ($r = mysqli_fetch_assoc($res))) { $other_roles[] = $r; $other_perms[$r['id']] = ['full' => (int)$r['full_access'], 'p' => []]; }
if ($other_roles) {
    $res = rbac_q("SELECT role_id, module_name, can_access, can_create, can_edit, can_delete FROM permissions WHERE role_id <> $id AND can_access = 1");
    while ($res && ($p = mysqli_fetch_assoc($res))) {
        if (isset($other_perms[$p['role_id']])) $other_perms[$p['role_id']]['p'][strtolower($p['module_name'])] = [1, (int)$p['can_create'], (int)$p['can_edit'], (int)$p['can_delete']];
    }
}

// where a page appears more than once
$occ = [];
foreach ($tree as $sec) foreach ($sec['menus'] as $m) {
    $pages = $m['type'] === 'page' ? [$m] : [];
    if ($m['type'] === 'menu') foreach ($m['items'] as $it) $pages = array_merge($pages, $it['type'] === 'page' ? [$it] : $it['items']);
    foreach ($pages as $p) $occ[$p['page']][] = $m['label'];
}

$readonly = !$can_save;
$csrf = rbac_csrf_token();
$R = ['view' => 'View', 'create' => 'Add', 'edit' => 'Edit', 'delete' => 'Delete'];

function rf_row($p, $perms, $R, $occ, $menu_label, $sub = false, $ro = false) {
    global $all_btns, $btn_saved;
    static $btn_done = [];
    $page = $p['page']; $cur = $perms[$page] ?? [];
    $pbtns = (!isset($btn_done[$page])) ? ($all_btns[strtolower($page)] ?? []) : [];
    if ($pbtns) $btn_done[$page] = 1;
    $others = array_values(array_diff(array_unique($occ[$page] ?? []), [$menu_label]));
    ob_start(); ?>
    <tr class="pr<?php echo $sub ? ' sub' : ''; ?>" data-page="<?php echo htmlspecialchars($page); ?>" data-search="<?php echo htmlspecialchars(strtolower($p['label'] . ' ' . $page . ' ' . $menu_label)); ?>">
        <td><div class="pg"><i class="fa-solid <?php echo htmlspecialchars($p['icon'] ?: 'fa-file'); ?>"></i>
            <span><?php echo htmlspecialchars($p['label']); ?></span><code><?php echo htmlspecialchars($page); ?>.php</code>
            <?php if ($others): ?><span class="dup" title="Same page is also in: <?php echo htmlspecialchars(implode(', ', $others)); ?>"><i class="fa-solid fa-link"></i> also in <?php echo htmlspecialchars(implode(', ', $others)); ?></span><?php endif; ?>
        </div>
        <?php if ($pbtns): $sv = $btn_saved[strtolower($page)] ?? []; ?>
            <details class="rb-btns"><summary><i class="fa-solid fa-computer-mouse"></i> Buttons on this page <span data-bcnt></span></summary>
                <div class="rb-btnlist">
                    <?php foreach ($pbtns as $bk => $bl): ?>
                        <label><input type="checkbox" name="btn[<?php echo htmlspecialchars($page); ?>][<?php echo htmlspecialchars($bk); ?>]" value="1"
                            <?php echo (!isset($sv[$bk]) || $sv[$bk]) ? 'checked' : ''; ?> <?php echo $ro ? 'disabled' : ''; ?>> <?php echo htmlspecialchars($bl); ?></label>
                    <?php endforeach; ?>
                    <?php if (!$ro): ?><span class="rb-btnall"><a href="#" onclick="btnAll(this,1);return false">all</a> · <a href="#" onclick="btnAll(this,0);return false">none</a></span><?php endif; ?>
                </div></details>
        <?php endif; ?>
        </td>
        <?php foreach ($R as $k => $lbl): ?>
            <td><input type="checkbox" class="rb-chk <?php echo $k === 'view' ? 'v' : ($k === 'delete' ? 'd' : ''); ?>" data-r="<?php echo $k; ?>"
                       name="perm[<?php echo htmlspecialchars($page); ?>][<?php echo $k; ?>]" value="1" title="<?php echo $lbl; ?>"
                       <?php echo !empty($cur[$k]) ? 'checked' : ''; ?> <?php echo $ro ? 'disabled' : ''; ?>></td>
        <?php endforeach; ?>
        <td><?php if (!$ro): ?><button type="button" class="rb-rowall" onclick="rowAll(this)">All</button><?php endif; ?></td>
    </tr>
    <?php return ob_get_clean();
}

include 'header.php';
?>
<link rel="stylesheet" href="assets/css/rbac.css?v=1">
<style>
.rb-btns{margin-top:6px;font-size:12px}
.rb-btns summary{cursor:pointer;color:#6d28d9;font-weight:600;list-style:none;display:inline-flex;gap:6px;align-items:center;padding:2px 8px;border-radius:6px;background:#f5f3ff}
.rb-btns summary::-webkit-details-marker{display:none}
.rb-btns[open] summary{background:#ede9fe}
.rb-btnlist{display:flex;flex-wrap:wrap;gap:6px 14px;padding:8px 10px;margin-top:6px;border:1px dashed #c4b5fd;border-radius:8px;background:#faf9ff}
.rb-btnlist label{display:inline-flex;align-items:center;gap:5px;font-weight:500;cursor:pointer;white-space:nowrap}
.rb-btnall{margin-left:auto;color:#6b7280} .rb-btnall a{color:#6d28d9;font-weight:600}
</style>
<div class="rb <?php echo $role['full_access'] ? 'rb-is-full' : ''; ?>" id="rbRoot">

    <div class="rb-head">
        <div>
            <div class="rb-crumb"><a href="index.php">Dashboard</a> / User Master / <a href="role.php">User Role</a> / <?php echo $is_edit ? ($readonly ? 'View' : 'Edit') : 'Add'; ?></div>
            <h2><?php echo $is_edit ? ($readonly ? 'View Role' : 'Edit Role') . ': ' . htmlspecialchars($role['role_name']) : 'Add New Role'; ?></h2>
            <p>Choose which pages this role can open, and whether it can add, edit or delete records on each page.</p>
        </div>
        <a href="role.php" class="rb-btn rb-btn-out"><i class="fa-solid fa-arrow-left"></i> Back to roles</a>
    </div>

    <?php if ($flash): ?>
        <div class="rb-alert rb-alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'bad'; ?>"><i class="fa-solid fa-circle-check" style="margin-top:2px"></i><div><?php echo $flash['msg']; ?></div></div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="rb-alert rb-alert-bad"><i class="fa-solid fa-circle-exclamation" style="margin-top:2px"></i><div><?php echo implode('<br>', $errors); ?></div></div>
    <?php endif; ?>
    <?php if ($readonly): ?>
        <div class="rb-alert rb-alert-warn"><i class="fa-solid fa-eye" style="margin-top:2px"></i><div>You can view this role but you do not have permission to edit it.</div></div>
    <?php endif; ?>
    <?php if ($is_mine): ?>
        <div class="rb-alert rb-alert-warn"><i class="fa-solid fa-user-lock" style="margin-top:2px"></i>
            <div>This is <b>your own role</b>. To stop you locking yourself out, it stays active<?php echo $role['full_access'] ? ' and keeps Full Access' : ' and keeps View + Edit on the User Role page'; ?>.</div></div>
    <?php endif; ?>

    <form method="post" id="roleForm" autocomplete="off">
        <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
        <?php if ($is_edit): ?><input type="hidden" name="role_id" value="<?php echo $id; ?>"><?php endif; ?>

        <!-- Role details -->
        <div class="rb-card">
            <div class="rb-card-h"><h3><i class="fa-solid fa-id-badge" style="color:#2563eb"></i> Role details</h3>
                <?php if ($is_edit): ?><span class="rb-badge rb-b-mut"><i class="fa-solid fa-users"></i> <?php echo count($assigned); ?> user(s)</span><?php endif; ?></div>
            <div class="rb-card-b">
                <div class="rb-grid">
                    <div class="rb-field">
                        <label for="role_name">Role name <span class="rb-req">*</span></label>
                        <input type="text" id="role_name" name="role_name" maxlength="100" required placeholder="e.g. Cashier, HR Officer, Accountant"
                               value="<?php echo htmlspecialchars($role['role_name']); ?>" <?php echo $readonly ? 'disabled' : ''; ?>>
                    </div>
                    <div class="rb-field">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="1" placeholder="What this role is for"
                                  <?php echo $readonly ? 'disabled' : ''; ?>><?php echo htmlspecialchars((string)$role['description']); ?></textarea>
                    </div>
                </div>
                <div class="rb-toggles">
                    <label class="rb-tog">
                        <span class="rb-sw"><input type="checkbox" name="active" value="1" <?php echo $role['active'] ? 'checked' : ''; ?> <?php echo ($readonly || $is_mine) ? 'disabled' : ''; ?>><span></span></span>
                        <span><b>Active</b><small>Inactive roles cannot open any page.</small></span>
                    </label>
                    <label class="rb-tog">
                        <span class="rb-sw"><input type="checkbox" name="full_access" id="fullAccess" value="1" <?php echo $role['full_access'] ? 'checked' : ''; ?>
                               <?php echo ($readonly || ($is_mine && $role['full_access'])) ? 'disabled' : ''; ?>><span></span></span>
                        <span><b><i class="fa-solid fa-crown" style="color:#7c3aed"></i> Full access (Super Admin)</b><small>Every page and every action, including pages added later.</small></span>
                    </label>
                </div>
                <?php if ($is_mine): ?><input type="hidden" name="active" value="1"><?php if ($role['full_access']): ?><input type="hidden" name="full_access" value="1"><?php endif; endif; ?>
                <?php if ($assigned): ?>
                    <div style="margin-top:16px;font-size:12.5px;color:var(--rb-muted)">Users with this role:
                        <div class="rb-users"><?php foreach ($assigned as $u): ?>
                            <span class="rb-badge <?php echo $u['active'] ? 'rb-b-mut' : 'rb-b-bad'; ?>"><i class="fa-solid fa-user"></i> <?php echo htmlspecialchars($u['username']); ?></span>
                        <?php endforeach; ?></div></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Permission matrix -->
        <div class="rb-card">
            <div class="rb-card-h">
                <h3><i class="fa-solid fa-sitemap" style="color:#7c3aed"></i> Page permissions <span class="rb-badge rb-b-mut" style="font-weight:500">Main menu → Sub menu → Page</span></h3>
                <div class="rb-toolbar">
                    <div class="rb-search"><i class="fa-solid fa-magnifying-glass"></i><input type="text" id="pageSearch" placeholder="Search pages..." oninput="searchPages(this.value)"></div>
                    <?php if (!$readonly): ?>
                        <?php if ($other_roles): ?>
                            <select class="rb-btn rb-btn-out rb-btn-sm" id="copyFrom" onchange="copyFrom(this)" style="padding-right:8px">
                                <option value="">Copy from role…</option>
                                <?php foreach ($other_roles as $o): ?><option value="<?php echo intval($o['id']); ?>"><?php echo htmlspecialchars($o['role_name']); ?></option><?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <button type="button" class="rb-btn rb-btn-out rb-btn-sm" onclick="setAll('all')"><i class="fa-solid fa-check-double"></i> Grant all</button>
                        <button type="button" class="rb-btn rb-btn-out rb-btn-sm" onclick="setAll('view')"><i class="fa-solid fa-eye"></i> View only</button>
                        <button type="button" class="rb-btn rb-btn-out rb-btn-sm" onclick="setAll('none')"><i class="fa-solid fa-xmark"></i> Clear</button>
                    <?php endif; ?>
                    <button type="button" class="rb-btn rb-btn-out rb-btn-sm" onclick="toggleAllMenus()" id="expBtn"><i class="fa-solid fa-up-down"></i> Collapse all</button>
                </div>
            </div>
            <div class="rb-card-b">
                <div class="rb-alert rb-alert-ok rb-full-note"><i class="fa-solid fa-crown" style="margin-top:2px"></i>
                    <div><b>Full access is on.</b> This role can open every page and add, edit and delete everywhere. Turn it off to choose pages one by one.</div></div>

                <div id="rbMatrix">
                <?php foreach ($tree as $si => $sec): ?>
                    <div class="rb-sec-title" data-sec><i class="fa-solid <?php echo htmlspecialchars($sec['icon'] ?: 'fa-grip'); ?>"></i> <?php echo htmlspecialchars($sec['title']); ?></div>
                    <?php
                    // top-level pages of a section (e.g. Dashboard) go in one card
                    $loose = array_filter($sec['menus'], fn($m) => $m['type'] === 'page');
                    $menus = array_filter($sec['menus'], fn($m) => $m['type'] === 'menu');
                    if ($loose) array_unshift($menus, ['type' => 'menu', 'label' => $sec['title'] . ' pages', 'icon' => 'fa-house', 'items' => array_values($loose)]);
                    foreach ($menus as $m):
                        $n = 0; foreach ($m['items'] as $it) $n += $it['type'] === 'page' ? 1 : count($it['items']); ?>
                        <div class="rb-menu" data-menu>
                            <div class="rb-menu-h" onclick="toggleMenu(this)">
                                <div class="mi"><i class="fa-solid <?php echo htmlspecialchars($m['icon'] ?: 'fa-folder'); ?>"></i></div>
                                <div class="mt"><b><?php echo htmlspecialchars($m['label']); ?></b><small><?php echo $n; ?> page<?php echo $n === 1 ? '' : 's'; ?></small></div>
                                <span class="rb-cnt" data-cnt>0 / <?php echo $n; ?></span>
                                <i class="fa-solid fa-chevron-down chev"></i>
                            </div>
                            <div class="rb-menu-b rb-wrap">
                                <table class="rb-pt">
                                    <thead><tr><th>Page</th>
                                        <?php foreach ($R as $k => $lbl): ?>
                                            <th><?php echo $lbl; ?><?php if (!$readonly): ?><span class="colall" onclick="colAll(this,'<?php echo $k; ?>')">all</span><?php endif; ?></th>
                                        <?php endforeach; ?><th></th></tr></thead>
                                    <tbody>
                                    <?php foreach ($m['items'] as $it):
                                        if ($it['type'] === 'page') { echo rf_row($it, $perms, $R, $occ, $m['label'], false, $readonly); continue; } ?>
                                        <tr class="grp" data-grp>
                                            <td><i class="fa-solid <?php echo htmlspecialchars($it['icon'] ?: 'fa-folder-tree'); ?>"></i> <?php echo htmlspecialchars($it['label']); ?>
                                                <span style="font-weight:500;color:var(--rb-muted);font-size:11.5px">· sub menu · <?php echo count($it['items']); ?> pages</span></td>
                                            <?php foreach ($R as $k => $lbl): ?>
                                                <td><?php if (!$readonly): ?><input type="checkbox" class="rb-chk" data-grpall="<?php echo $k; ?>" title="<?php echo $lbl; ?> — all pages in this sub menu" onchange="grpAll(this)"><?php endif; ?></td>
                                            <?php endforeach; ?><td></td>
                                        </tr>
                                        <?php foreach ($it['items'] as $p) echo rf_row($p, $perms, $R, $occ, $m['label'], true, $readonly); ?>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <div class="rb-empty rb-hide" id="noPage">No pages match your search.</div>
                </div>
            </div>
        </div>

        <div class="rb-sticky">
            <div class="sum" id="sumText">—</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <a href="role.php" class="rb-btn rb-btn-out">Cancel</a>
                <?php if (!$readonly): ?>
                    <button type="submit" name="save_stay" value="1" class="rb-btn rb-btn-out"><i class="fa-solid fa-floppy-disk"></i> Save &amp; continue</button>
                    <button type="submit" class="rb-btn rb-btn-pri"><i class="fa-solid fa-check"></i> <?php echo $is_edit ? 'Update role' : 'Create role'; ?></button>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    const root = document.getElementById('rbRoot');
    const matrix = document.getElementById('rbMatrix');
    const OTHER = <?php echo json_encode($other_perms); ?>;
    const RIGHTS = ['view', 'create', 'edit', 'delete'];
    const boxes = (page, r) => matrix.querySelectorAll('tr.pr[data-page="' + CSS.escape(page) + '"] input[data-r="' + r + '"]');

    // set one right on a page (keeps duplicate rows in sync + view dependency)
    function setR(page, r, on) {
        boxes(page, r).forEach(b => b.checked = on);
        if (on && r !== 'view') boxes(page, 'view').forEach(b => b.checked = true);
        if (!on && r === 'view') ['create', 'edit', 'delete'].forEach(x => boxes(page, x).forEach(b => b.checked = false));
    }

    matrix.addEventListener('change', e => {
        const b = e.target;
        if (!b.dataset.r) return;
        setR(b.closest('tr').dataset.page, b.dataset.r, b.checked);
        refresh();
    });

    window.rowAll = btn => {
        const tr = btn.closest('tr'), page = tr.dataset.page;
        const allOn = RIGHTS.every(r => tr.querySelector('input[data-r="' + r + '"]').checked);
        RIGHTS.forEach(r => setR(page, r, !allOn)); refresh();
    };
    window.colAll = (el, r) => {
        const rows = [...el.closest('table').querySelectorAll('tbody tr.pr:not(.rb-hide)')];
        const allOn = rows.every(tr => tr.querySelector('input[data-r="' + r + '"]').checked);
        rows.forEach(tr => setR(tr.dataset.page, r, !allOn)); refresh();
    };
    window.grpAll = cb => {
        let tr = cb.closest('tr').nextElementSibling;
        while (tr && tr.classList.contains('sub')) { setR(tr.dataset.page, cb.dataset.grpall, cb.checked); tr = tr.nextElementSibling; }
        refresh();
    };
    window.setAll = mode => {
        const pages = new Set([...matrix.querySelectorAll('tr.pr')].map(t => t.dataset.page));
        pages.forEach(p => RIGHTS.forEach(r => setR(p, r, mode === 'all' || (mode === 'view' && r === 'view'))));
        refresh();
    };
    window.copyFrom = sel => {
        const src = OTHER[sel.value]; if (!src) return;
        const name = sel.options[sel.selectedIndex].text; sel.value = '';
        if (!confirm('Replace the current page permissions with the ones from "' + name + '"?')) return;
        const pages = new Set([...matrix.querySelectorAll('tr.pr')].map(t => t.dataset.page));
        pages.forEach(p => {
            const v = src.full ? [1, 1, 1, 1] : (src.p[p] || [0, 0, 0, 0]);
            RIGHTS.forEach((r, i) => boxes(p, r).forEach(b => b.checked = !!v[i]));
        });
        refresh();
    };

    // button ticks: count on each page row, "all / none" links
    function btnCount(d) { const t = d.querySelectorAll('input[type=checkbox]'), on = d.querySelectorAll('input:checked'); d.querySelector('[data-bcnt]').textContent = '(' + on.length + ' / ' + t.length + ')'; }
    window.btnAll = (a, on) => { const d = a.closest('details'); d.querySelectorAll('input[type=checkbox]').forEach(b => b.checked = !!on); btnCount(d); };
    matrix.querySelectorAll('details.rb-btns').forEach(d => { btnCount(d); d.addEventListener('change', () => btnCount(d)); });
    window.toggleMenu = h => { h.parentNode.classList.toggle('closed'); };
    let collapsed = false;
    window.toggleAllMenus = () => {
        collapsed = !collapsed;
        matrix.querySelectorAll('[data-menu]').forEach(m => m.classList.toggle('closed', collapsed));
        document.getElementById('expBtn').innerHTML = '<i class="fa-solid fa-up-down"></i> ' + (collapsed ? 'Expand all' : 'Collapse all');
    };

    window.searchPages = q => {
        q = q.trim().toLowerCase(); let any = 0;
        matrix.querySelectorAll('[data-menu]').forEach(m => {
            let shown = 0;
            m.querySelectorAll('tr.pr').forEach(tr => { const ok = !q || tr.dataset.search.includes(q); tr.classList.toggle('rb-hide', !ok); if (ok) shown++; });
            m.querySelectorAll('tr[data-grp]').forEach(g => {
                let n = g.nextElementSibling, vis = false;
                while (n && n.classList.contains('sub')) { if (!n.classList.contains('rb-hide')) vis = true; n = n.nextElementSibling; }
                g.classList.toggle('rb-hide', !vis);
            });
            m.classList.toggle('rb-hide', !shown); if (q && shown) m.classList.remove('closed'); any += shown;
        });
        matrix.querySelectorAll('[data-sec]').forEach(s => {
            let n = s.nextElementSibling, vis = false;
            while (n && !n.hasAttribute('data-sec') && n.id !== 'noPage') { if (n.hasAttribute('data-menu') && !n.classList.contains('rb-hide')) vis = true; n = n.nextElementSibling; }
            s.classList.toggle('rb-hide', !vis);
        });
        document.getElementById('noPage').classList.toggle('rb-hide', any > 0);
    };

    function refresh() {
        // per menu counters
        matrix.querySelectorAll('[data-menu]').forEach(m => {
            const rows = m.querySelectorAll('tr.pr'), total = rows.length;
            let on = 0, full = 0;
            rows.forEach(tr => { const c = RIGHTS.filter(r => tr.querySelector('input[data-r="' + r + '"]').checked).length; if (c) on++; if (c === 4) full++; });
            const el = m.querySelector('[data-cnt]');
            el.textContent = on + ' / ' + total + (on ? ' pages' : '');
            el.className = 'rb-cnt' + (on && full === total ? ' full' : on ? ' part' : '');
            // group checkboxes reflect children
            m.querySelectorAll('tr[data-grp]').forEach(g => {
                g.querySelectorAll('input[data-grpall]').forEach(cb => {
                    let n = g.nextElementSibling, all = true, some = false;
                    while (n && n.classList.contains('sub')) { const c = n.querySelector('input[data-r="' + cb.dataset.grpall + '"]').checked; all = all && c; some = some || c; n = n.nextElementSibling; }
                    cb.checked = all; cb.indeterminate = !all && some;
                });
            });
        });
        // totals (unique pages)
        const seen = {}; let v = 0, c = 0, e = 0, d = 0;
        matrix.querySelectorAll('tr.pr').forEach(tr => {
            const p = tr.dataset.page; if (seen[p]) return; seen[p] = 1;
            const g = r => tr.querySelector('input[data-r="' + r + '"]').checked;
            if (g('view')) v++; if (g('create')) c++; if (g('edit')) e++; if (g('delete')) d++;
        });
        const full = document.getElementById('fullAccess');
        document.getElementById('sumText').innerHTML = (full && full.checked)
            ? '<b><i class="fa-solid fa-crown" style="color:#7c3aed"></i> Full access</b> — every page, every action'
            : '<b>' + v + '</b> of ' + Object.keys(seen).length + ' pages · Add <b>' + c + '</b> · Edit <b>' + e + '</b> · Delete <b>' + d + '</b>';
    }

    const fa = document.getElementById('fullAccess');
    if (fa) fa.addEventListener('change', () => { root.classList.toggle('rb-is-full', fa.checked); refresh(); });

    // warn before leaving with unsaved changes
    let dirty = false;
    const form = document.getElementById('roleForm');
    form.addEventListener('change', () => dirty = true);
    form.addEventListener('submit', () => dirty = false);
    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    refresh();
})();
</script>
<?php include 'footer.php'; ?>
