<?php
/**
 * role.php — User Roles list  (View / Add / Edit / Delete / Duplicate / Activate)
 * Permissions are edited on role_form.php
 */
include_once 'config.php';
include_once 'auth.php';
require_once __DIR__ . '/rbac.php';
requireLogin();
rbac_ensure_schema();

$me_role_id = (function () {
    $r = rbac_q("SELECT role_id FROM users WHERE id = " . intval($_SESSION['user_id']));
    $r = $r ? mysqli_fetch_assoc($r) : null;
    return $r ? intval($r['role_id']) : 0;
})();

$flash = $_SESSION['rbac_flash'] ?? null;
unset($_SESSION['rbac_flash']);
function role_flash($type, $msg) { $_SESSION['rbac_flash'] = ['type' => $type, 'msg' => $msg]; header('Location: role.php'); exit; }

/* ─────────────────────────── POST actions ─────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rbac_csrf_check()) role_flash('bad', 'Your session expired. Please try again.');
    $action = $_POST['action'] ?? '';
    $id     = intval($_POST['role_id'] ?? 0);
    $role   = $id ? mysqli_fetch_assoc(rbac_q("SELECT * FROM roles WHERE id = $id")) : null;
    if (!$role) role_flash('bad', 'Role not found.');
    $rname = $role['role_name'];

    // ── Delete ───────────────────────────────────────────────
    if ($action === 'delete') {
        if (!canDelete('role'))            role_flash('bad', 'You do not have permission to delete roles.');
        if ($id === $me_role_id)           role_flash('bad', 'You cannot delete your own role.');

        $users = intval(mysqli_fetch_row(rbac_q("SELECT COUNT(*) FROM users WHERE role_id = $id"))[0]);
        $move_to = intval($_POST['move_to'] ?? 0);
        if ($users > 0) {
            if ($move_to === $id) role_flash('bad', 'Choose a different role to move the users to.');
            if ($move_to > 0 && !mysqli_fetch_row(rbac_q("SELECT id FROM roles WHERE id = $move_to"))) role_flash('bad', 'Target role not found.');
        }
        mysqli_begin_transaction($conn);
        try {
            if ($users > 0) {
                rbac_q("UPDATE users SET role_id = " . ($move_to > 0 ? $move_to : 'NULL') . " WHERE role_id = $id");
            }
            rbac_q("DELETE FROM permissions WHERE role_id = $id");
            if (!rbac_q("DELETE FROM roles WHERE id = $id")) throw new Exception(mysqli_error($conn));
            mysqli_commit($conn);
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            role_flash('bad', 'Could not delete role: ' . htmlspecialchars($e->getMessage()));
        }
        $note = $users ? " $users user(s) " . ($move_to ? 'moved to the selected role.' : 'now have no role.') : '';
        rbac_audit($id, $rname, 'delete', $note);
        role_flash('ok', "Role <b>" . htmlspecialchars($rname) . "</b> deleted.$note");
    }

    // ── Activate / deactivate ────────────────────────────────
    if ($action === 'toggle') {
        if (!canEdit('role'))    role_flash('bad', 'You do not have permission to edit roles.');
        if ($id === $me_role_id) role_flash('bad', 'You cannot deactivate your own role.');
        $new = $role['active'] ? 0 : 1;
        rbac_q("UPDATE roles SET active = $new, updated_by = " . intval($_SESSION['user_id']) . " WHERE id = $id");
        rbac_audit($id, $rname, $new ? 'activate' : 'deactivate');
        role_flash('ok', "Role <b>" . htmlspecialchars($rname) . "</b> " . ($new ? 'activated' : 'deactivated') . '.');
    }

    // ── Duplicate ────────────────────────────────────────────
    if ($action === 'duplicate') {
        if (!canCreate('role')) role_flash('bad', 'You do not have permission to add roles.');
        $base = $rname . ' (Copy)'; $new_name = $base; $n = 2;
        while (mysqli_num_rows(rbac_q("SELECT id FROM roles WHERE role_name = '" . mysqli_real_escape_string($conn, $new_name) . "'"))) {
            $new_name = $base . ' ' . $n++;
        }
        $nn  = mysqli_real_escape_string($conn, $new_name);
        $dd  = mysqli_real_escape_string($conn, (string)$role['description']);
        $fa  = intval($role['full_access']);
        $uid = intval($_SESSION['user_id']);
        mysqli_begin_transaction($conn);
        rbac_q("INSERT INTO roles (role_name, description, full_access, active, created_by) VALUES ('$nn', '$dd', $fa, 1, $uid)");
        $nid = mysqli_insert_id($conn);
        rbac_q("INSERT INTO permissions (role_id, module_name, can_access, can_create, can_edit, can_delete)
                SELECT $nid, module_name, can_access, can_create, can_edit, can_delete FROM permissions WHERE role_id = $id");
        mysqli_commit($conn);
        rbac_audit($nid, $new_name, 'duplicate', "Copied from $rname");
        $_SESSION['rbac_flash'] = ['type' => 'ok', 'msg' => "Role copied as <b>" . htmlspecialchars($new_name) . "</b>. Adjust its permissions below."];
        header("Location: role_form.php?id=$nid"); exit;
    }
    role_flash('bad', 'Unknown action.');
}

/* ─────────────────────────── Data ─────────────────────────── */
$menu_pages  = array_keys(rbac_menu_pages());
$total_pages = count($menu_pages);
$in_list     = $menu_pages ? "'" . implode("','", array_map(fn($p) => mysqli_real_escape_string($conn, $p), $menu_pages)) . "'" : "''";

$roles = [];
$res = rbac_q("SELECT r.*,
              (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
              (SELECT COUNT(*) FROM permissions p WHERE p.role_id = r.id AND p.can_access = 1 AND p.module_name IN ($in_list)) AS page_count
              FROM roles r ORDER BY r.full_access DESC, r.role_name ASC");
while ($res && ($r = mysqli_fetch_assoc($res))) $roles[] = $r;

$users_by_role = [];
$ur = rbac_q("SELECT id, username, role_id, active FROM users WHERE role_id IS NOT NULL ORDER BY username");
while ($ur && ($u = mysqli_fetch_assoc($ur))) $users_by_role[$u['role_id']][] = $u;
$no_role_users = intval(mysqli_fetch_row(rbac_q("SELECT COUNT(*) FROM users WHERE role_id IS NULL"))[0] ?? 0);

$stat_total  = count($roles);
$stat_active = count(array_filter($roles, fn($r) => $r['active']));
$stat_full   = count(array_filter($roles, fn($r) => $r['full_access']));
$stat_users  = array_sum(array_column($roles, 'user_count'));

$can_add = canCreate('role'); $can_edit = canEdit('role'); $can_del = canDelete('role');
$csrf = rbac_csrf_token();

include 'header.php';
?>
<link rel="stylesheet" href="assets/css/rbac.css?v=1">
<div class="rb">

    <div class="rb-head">
        <div>
            <div class="rb-crumb"><a href="index.php">Dashboard</a> / User Master / User Role</div>
            <h2>User Roles &amp; Permissions</h2>
            <p>Create roles and choose which main menu and sub menu pages each role can view, add, edit and delete.</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="role_export.php" id="rxAllBtn" class="rb-btn rb-btn-out" title="All roles: summary, permission matrix, permission list and users"><i class="fa-solid fa-file-excel" style="color:#15803d"></i> Export Excel</a>
            <a href="role_export.php" id="rxSelBtn" class="rb-btn rb-btn-out" style="display:none" title="Export only the ticked roles"><i class="fa-solid fa-file-excel" style="color:#15803d"></i> Export selected (<span id="rxCnt">0</span>)</a>
            <?php if ($can_add): ?>
                <a href="role_form.php" class="rb-btn rb-btn-pri"><i class="fa-solid fa-plus"></i> Add New Role</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="rb-alert rb-alert-<?php echo $flash['type'] === 'ok' ? 'ok' : 'bad'; ?>">
            <i class="fa-solid <?php echo $flash['type'] === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>" style="margin-top:2px"></i>
            <div><?php echo $flash['msg']; ?></div>
        </div>
    <?php endif; ?>

    <?php if ($no_role_users > 0): ?>
        <div class="rb-alert rb-alert-warn">
            <i class="fa-solid fa-triangle-exclamation" style="margin-top:2px"></i>
            <div><b><?php echo $no_role_users; ?> user(s)</b> have no role and can only open the Dashboard.
                Assign them a role on the <a href="users.php" style="color:inherit;font-weight:600">Users</a> page.</div>
        </div>
    <?php endif; ?>

    <div class="rb-stats">
        <div class="rb-stat"><div class="i" style="background:#eff6ff;color:#2563eb"><i class="fa-solid fa-user-shield"></i></div><div><b><?php echo $stat_total; ?></b><span>Total roles</span></div></div>
        <div class="rb-stat"><div class="i" style="background:#f0fdf4;color:#16a34a"><i class="fa-solid fa-circle-check"></i></div><div><b><?php echo $stat_active; ?></b><span>Active roles</span></div></div>
        <div class="rb-stat"><div class="i" style="background:#f5f3ff;color:#7c3aed"><i class="fa-solid fa-crown"></i></div><div><b><?php echo $stat_full; ?></b><span>Full access roles</span></div></div>
        <div class="rb-stat"><div class="i" style="background:#fffbeb;color:#d97706"><i class="fa-solid fa-users"></i></div><div><b><?php echo $stat_users; ?></b><span>Users with a role</span></div></div>
        <div class="rb-stat"><div class="i" style="background:#f8fafc;color:#475569"><i class="fa-solid fa-sitemap"></i></div><div><b><?php echo $total_pages; ?></b><span>Menu pages</span></div></div>
    </div>

    <div class="rb-card">
        <div class="rb-card-h">
            <h3><i class="fa-solid fa-list" style="color:#2563eb"></i> All Roles</h3>
            <div class="rb-search"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="roleSearch" placeholder="Search roles..." oninput="filterRoles(this.value)"></div>
        </div>
        <?php if (!$roles): ?>
            <div class="rb-empty"><i class="fa-solid fa-user-shield" style="font-size:32px;margin-bottom:10px;display:block"></i>
                No roles yet. <?php if ($can_add): ?><a href="role_form.php">Create the first role</a>.<?php endif; ?></div>
        <?php else: ?>
        <div class="rb-wrap">
            <table class="rb-table" id="roleTable">
                <thead><tr>
                    <th style="width:34px"><input type="checkbox" id="rxAll" title="Select all roles" aria-label="Select all roles"></th><th style="width:50px">#</th><th>Role</th><th>Access</th><th>Users</th><th>Status</th><th>Updated</th><th style="width:150px">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($roles as $i => $r):
                    $rid = intval($r['id']); $mine = $rid === $me_role_id;
                    $pc = intval($r['page_count']);
                    $users = $users_by_role[$rid] ?? []; ?>
                    <tr data-search="<?php echo htmlspecialchars(strtolower($r['role_name'] . ' ' . $r['description'])); ?>">
                        <td><input type="checkbox" class="rx-pick" value="<?php echo (int)$r['id']; ?>" aria-label="Select role"></td>
                        <td style="color:var(--rb-muted)"><?php echo $i + 1; ?></td>
                        <td>
                            <div class="rb-name"><?php echo htmlspecialchars($r['role_name']); ?>
                                <?php if ($mine): ?><span class="rb-badge rb-b-pri" style="margin-left:6px">Your role</span><?php endif; ?></div>
                            <?php if ($r['description']): ?><div class="rb-desc"><?php echo htmlspecialchars($r['description']); ?></div><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['full_access']): ?>
                                <span class="rb-badge rb-b-vio"><i class="fa-solid fa-crown"></i> Full access</span>
                            <?php elseif ($pc === 0): ?>
                                <span class="rb-badge rb-b-mut">No pages</span>
                            <?php else: ?>
                                <span class="rb-badge rb-b-pri"><i class="fa-solid fa-file-lines"></i> <?php echo $pc; ?> / <?php echo $total_pages; ?> pages</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($users): ?>
                                <span class="rb-badge rb-b-mut" title="<?php echo htmlspecialchars(implode(', ', array_column($users, 'username'))); ?>">
                                    <i class="fa-solid fa-users"></i> <?php echo count($users); ?></span>
                            <?php else: ?><span style="color:var(--rb-muted)">—</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($can_edit && !$mine): ?>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="action" value="toggle"><input type="hidden" name="role_id" value="<?php echo $rid; ?>">
                                    <label class="rb-sw" title="<?php echo $r['active'] ? 'Click to deactivate' : 'Click to activate'; ?>">
                                        <input type="checkbox" <?php echo $r['active'] ? 'checked' : ''; ?> onchange="this.form.submit()"><span></span></label>
                                </form>
                            <?php else: ?>
                                <span class="rb-badge <?php echo $r['active'] ? 'rb-b-ok' : 'rb-b-bad'; ?>"><?php echo $r['active'] ? 'Active' : 'Inactive'; ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="color:var(--rb-muted);font-size:12.5px;white-space:nowrap"><?php echo date('d M Y', strtotime($r['updated_at'] ?: $r['created_at'])); ?></td>
                        <td>
                            <div class="rb-acts">
                                <a href="role_form.php?id=<?php echo $rid; ?>" class="rb-ic" title="<?php echo $can_edit ? 'Edit role & permissions' : 'View permissions'; ?>">
                                    <i class="fa-solid <?php echo $can_edit ? 'fa-pen' : 'fa-eye'; ?>"></i></a>
                                <?php if ($can_add): ?>
                                    <form method="post" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="action" value="duplicate"><input type="hidden" name="role_id" value="<?php echo $rid; ?>">
                                        <button type="submit" class="rb-ic" title="Duplicate role"><i class="fa-solid fa-copy"></i></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($can_del): ?>
                                    <button type="button" class="rb-ic del" title="<?php echo $mine ? 'You cannot delete your own role' : 'Delete role'; ?>" <?php echo $mine ? 'disabled' : ''; ?>
                                            onclick='openDelete(<?php echo json_encode(['id' => $rid, 'name' => $r['role_name'], 'users' => array_column($users, 'username')], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                        <i class="fa-solid fa-trash"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="rb-empty rb-hide" id="noMatch">No roles match your search.</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($can_del): ?>
<!-- Delete modal -->
<div class="rb rb-modal" id="delModal" onclick="if(event.target===this)closeDelete()">
    <form method="post" class="rb-modal-box">
        <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="role_id" id="delId">
        <div class="rb-modal-h"><i class="fa-solid fa-trash" style="color:var(--rb-bad)"></i><h3>Delete role</h3></div>
        <div class="rb-modal-b">
            <p style="margin:0">Delete <b id="delName"></b> and all its page permissions? This cannot be undone.</p>
            <div id="delUsersBox" style="display:none;margin-top:14px">
                <div class="rb-alert rb-alert-warn" style="margin-bottom:10px"><i class="fa-solid fa-users"></i>
                    <div><b id="delUserCount"></b> user(s) have this role:<div class="rb-users" id="delUsers"></div></div></div>
                <div class="rb-field">
                    <label for="moveTo">Move these users to</label>
                    <select name="move_to" id="moveTo">
                        <option value="0">— No role (Dashboard only) —</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?php echo intval($r['id']); ?>" data-id="<?php echo intval($r['id']); ?>"><?php echo htmlspecialchars($r['role_name']); ?><?php echo $r['active'] ? '' : ' (inactive)'; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="rb-modal-f">
            <button type="button" class="rb-btn rb-btn-out" onclick="closeDelete()">Cancel</button>
            <button type="submit" class="rb-btn rb-btn-bad"><i class="fa-solid fa-trash"></i> Delete role</button>
        </div>
    </form>
</div>
<?php endif; ?>

<script>
function filterRoles(q) {
    q = q.trim().toLowerCase(); let shown = 0;
    document.querySelectorAll('#roleTable tbody tr').forEach(tr => {
        const ok = !q || tr.dataset.search.includes(q);
        tr.classList.toggle('rb-hide', !ok); if (ok) shown++;
    });
    const nm = document.getElementById('noMatch'); if (nm) nm.classList.toggle('rb-hide', shown > 0);
}
function openDelete(r) {
    document.getElementById('delId').value = r.id;
    document.getElementById('delName').textContent = r.name;
    const box = document.getElementById('delUsersBox');
    box.style.display = r.users.length ? 'block' : 'none';
    document.getElementById('delUserCount').textContent = r.users.length;
    document.getElementById('delUsers').innerHTML = r.users.map(u => '<span class="rb-badge rb-b-mut"></span>').join('');
    document.querySelectorAll('#delUsers span').forEach((s, i) => s.textContent = r.users[i]);
    document.querySelectorAll('#moveTo option[data-id]').forEach(o => o.hidden = o.dataset.id == r.id);
    document.getElementById('moveTo').value = '0';
    document.getElementById('delModal').classList.add('open');
}
function closeDelete() { document.getElementById('delModal').classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDelete && document.getElementById('delModal') && closeDelete(); });

/* select roles -> export only those (with their permission list) */
(function(){
    var picks = document.querySelectorAll('.rx-pick'), all = document.getElementById('rxAll'),
        btn = document.getElementById('rxSelBtn'), cnt = document.getElementById('rxCnt');
    function upd(){
        var ids = [].filter.call(picks, function(c){ return c.checked; }).map(function(c){ return c.value; });
        cnt.textContent = ids.length;
        btn.style.display = ids.length ? '' : 'none';
        btn.href = 'role_export.php?role_ids=' + ids.join(',');
        if (all) all.checked = ids.length && ids.length === picks.length;
    }
    picks.forEach(function(c){ c.addEventListener('change', upd); });
    if (all) all.addEventListener('change', function(){ picks.forEach(function(c){ if (!c.closest('tr').classList.contains('rb-hide')) c.checked = all.checked; }); upd(); });
})();
</script>
<?php include 'footer.php'; ?>
