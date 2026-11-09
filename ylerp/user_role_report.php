<?php
/**
 * user_role_report.php — User Role Report
 *   • By User : every user is a header (user · role · status) with that role's permitted pages listed below it
 *   • By Menu : every menu / sub menu / page with the users who can open it (same menu → same users, side by side)
 *   • Excel   : ?export=1  (same filters) → "Users & Permissions", "Menu → Users", "Users" sheets
 * Sidebar link: User Master → User Role Report   (add the link in header.php, it then shows on the Role page)
 */
include_once 'config.php';
include_once 'auth.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/rbac_xlsx.php';
requireLogin();
if (!canView('user_role_report')) rbac_deny('view', 'user_role_report');

/* ── filters ── */
$f_role   = intval($_GET['role_id'] ?? 0);
$f_status = $_GET['status'] ?? '';                       // '', 1, 0
$f_q      = trim($_GET['q'] ?? '');
$f_page   = trim($_GET['page_q'] ?? '');                 // filter By Menu list
$view     = ($_GET['view'] ?? 'user') === 'menu' ? 'menu' : 'user';

/* ── data ── */
$roles = [];
$rr = rbac_q("SELECT id, role_name, full_access, active FROM roles ORDER BY role_name");
while ($rr && ($x = mysqli_fetch_assoc($rr))) $roles[$x['id']] = $x;

$perm = [];
$pr = rbac_q("SELECT role_id, module_name, can_access, can_create, can_edit, can_delete FROM permissions");
while ($pr && ($p = mysqli_fetch_assoc($pr)))
    $perm[$p['role_id']][strtolower($p['module_name'])] = [(int)$p['can_access'], (int)$p['can_create'], (int)$p['can_edit'], (int)$p['can_delete']];

$rows = [];   // page rows from the sidebar tree
foreach (rbac_menu_tree() as $sec) {
    foreach ($sec['menus'] as $m) {
        if ($m['type'] === 'page') { $rows[] = [$sec['title'], $m['label'], '', $m['label'], $m['page']]; continue; }
        foreach ($m['items'] as $it) {
            if ($it['type'] === 'page') $rows[] = [$sec['title'], $m['label'], '', $it['label'], $it['page']];
            else foreach ($it['items'] as $pg) $rows[] = [$sec['title'], $m['label'], $it['label'], $pg['label'], $pg['page']];
        }
    }
}
function urr_rights($role, $perm, $page) {
    if (!$role) return [0, 0, 0, 0];
    if ($role['full_access']) return [1, 1, 1, 1];
    return $perm[$role['id']][$page] ?? [0, 0, 0, 0];
}
function urr_any($r) { return $r[0] || $r[1] || $r[2] || $r[3]; }
function urr_letters($r) { return ($r[0] ? 'V' : '') . ($r[1] ? 'A' : '') . ($r[2] ? 'E' : '') . ($r[3] ? 'D' : ''); }

$where = [];
if ($f_role)            $where[] = "u.role_id = $f_role";
if ($f_status !== '')   $where[] = "u.active = " . ($f_status ? 1 : 0);
if ($f_q !== '')        $where[] = "u.username LIKE '%" . mysqli_real_escape_string($conn, $f_q) . "%'";
$users = [];
$ur = rbac_q("SELECT u.* FROM users u " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . " ORDER BY u.username");
while ($ur && ($u = mysqli_fetch_assoc($ur))) {
    $role = $roles[$u['role_id'] ?? 0] ?? null;
    $u['role'] = $role;
    $u['granted'] = $role ? array_values(array_filter($rows, fn($r) => urr_any(urr_rights($role, $perm, $r[4])))) : [];
    $users[] = $u;
}

$fullUsers = array_map(fn($u) => $u['username'], array_values(array_filter($users, fn($u) => $u['role'] && $u['role']['full_access'])));
/* page => users that can open it (By Menu) */
$menuUsers = [];
foreach ($rows as $row) {
    $list = [];
    foreach ($users as $u) {
        if (!$u['role'] || $u['role']['full_access']) continue;      // Full Access users are noted once, not on every page
        $x = urr_rights($u['role'], $perm, $row[4]);
        if (urr_any($x)) $list[] = ['name' => $u['username'], 'role' => $u['role']['role_name'], 'full' => (bool)$u['role']['full_access'], 'r' => $x, 'active' => (int)($u['active'] ?? 1)];
    }
    if ($list) $menuUsers[$row[4]] = $list;
}
/* page => right(0 view,1 add,2 edit,3 delete) => role name => [usernames] */
$menuRoles = [];
foreach ($menuUsers as $page => $list) foreach ($list as $e) foreach ([0, 1, 2, 3] as $k)
    if ($e['r'][$k]) $menuRoles[$page][$k][$e['role']][] = $e['name'];
function urr_cell($byRole) {          // "Manager (Akila, Manura)" lines
    $o = [];
    foreach ($byRole as $role => $names) $o[] = $role . ' (' . implode(', ', $names) . ')';
    return $o;
}

/* ── Excel export ── */
if (isset($_GET['export'])) {
    $C = ['teal' => '0F766E', 'dark' => '134E4A', 'light' => 'F0FDFA', 'grey' => 'F3F4F6', 'full' => 'D1FAE5', 'gold' => 'FDE68A', 'white' => 'FFFFFF'];
    $accents = ['0F766E', '7C3AED', 'D97706', '2563EB', 'DB2777', '059669'];
    $H = ['b' => 1, 'color' => $C['white'], 'bg' => $C['dark'], 'h' => 'center', 'border' => 1];
    $X = new MiniXlsx();
    $me = $_SESSION['username'] ?? 'user';

    /* Sheet 1: each user = header band, that user's permission list below */
    $s1 = $X->sheet('Users & Permissions');
    foreach ([3, 22, 24, 38, 9, 9, 9, 9] as $i => $w) $s1->widths[$i + 1] = $w;
    $s1->set(1, 2, 'User Role Report — users with their permissions', ['b' => 1, 'sz' => 18, 'color' => $C['white'], 'bg' => $C['teal'], 'indent' => 1]);
    for ($c = 3; $c <= 8; $c++) $s1->set(1, $c, '', ['bg' => $C['teal']]);
    $s1->merge(1, 2, 1, 8); $s1->heights[1] = 34;
    $s1->set(2, 2, 'Generated ' . date('d M Y, H:i') . ' by ' . $me . '  ·  ' . count($users) . ' users  ·  only permitted pages are listed  ·  ✔ = right given', ['i' => 1, 'color' => '6B7280']);
    $s1->merge(2, 2, 2, 8);
    $r = 4; $ri = 0;
    foreach ($users as $u) {
        $role = $u['role']; $full = $role && $role['full_access'];
        $accent = $full ? 'B45309' : $accents[$ri++ % count($accents)];
        $s1->set($r, 2, '👤  ' . $u['username'] . '     ·     Role: ' . ($role['role_name'] ?? 'no role') . ($full ? ' ★' : '') . '     ·     ' . (($u['active'] ?? 1) ? 'Active' : 'Inactive')
            . '     ·     Pages: ' . ($full ? 'ALL' : count($u['granted'])), ['b' => 1, 'sz' => 13, 'color' => $C['white'], 'bg' => $accent, 'indent' => 1]);
        for ($c = 3; $c <= 8; $c++) $s1->set($r, $c, '', ['bg' => $accent]);
        $s1->merge($r, 2, $r, 8); $s1->heights[$r] = 26; $r++;
        if ($full) { $s1->set($r, 2, 'Full Access — can open, add, edit and delete on every page.', ['bg' => $C['gold'], 'indent' => 1]);
            for ($c = 3; $c <= 8; $c++) $s1->set($r, $c, '', ['bg' => $C['gold']]); $s1->merge($r, 2, $r, 8); $r += 3; continue; }
        if (!$role)            { $s1->set($r, 2, 'No role assigned — dashboard only.', ['color' => 'B91C1C', 'indent' => 1]); $s1->merge($r, 2, $r, 8); $r += 3; continue; }
        if (!$u['granted'])    { $s1->set($r, 2, 'Role has no page permission added yet.', ['color' => 'B91C1C', 'indent' => 1]); $s1->merge($r, 2, $r, 8); $r += 3; continue; }
        foreach (['Main Menu', 'Sub Menu', 'Page', 'View', 'Add', 'Edit', 'Delete'] as $i => $h) $s1->set($r, $i + 2, $h, $H);
        $r++; $prev = null;
        foreach ($u['granted'] as $row) {
            $x = urr_rights($role, $perm, $row[4]); $mk = $row[0] . '|' . $row[1];
            $s1->set($r, 2, $prev === $mk ? '' : $row[1], ['b' => 1, 'border' => 1]); $prev = $mk;
            $s1->set($r, 3, $row[2], ['border' => 1]); $s1->set($r, 4, $row[3], ['border' => 1]);
            foreach ([0, 1, 2, 3] as $k)
                $s1->set($r, $k + 5, $x[$k] ? '✔' : '', ['b' => 1, 'color' => '15803D', 'h' => 'center', 'border' => 1] + ($x[$k] ? ['bg' => $C['full']] : []));
            $r++;
        }
        $r += 2;
    }

    /* Sheet 2: per page, which user ROLES (and their users) have View / Add / Edit / Delete */
    $s2 = $X->sheet('Menu → Roles');
    foreach (['Main Menu', 'Sub Menu', 'Page', 'View', 'Add', 'Edit', 'Delete'] as $i => $h) $s2->set(1, $i + 1, $h, $H);
    $k = 2;
    if ($fullUsers) { $s2->set($k, 1, '★ Full Access (every page, every right): ' . implode(', ', $fullUsers), ['b' => 1, 'bg' => $C['gold'], 'border' => 1]);
        for ($c = 2; $c <= 7; $c++) $s2->set($k, $c, '', ['bg' => $C['gold'], 'border' => 1]);
        $s2->merge($k, 1, $k, 7); $k++; }
    foreach ($rows as $row) {
        if (empty($menuRoles[$row[4]])) continue;
        $bg = $k % 2 ? ['bg' => $C['grey']] : []; $lines = 1;
        $s2->set($k, 1, $row[1], ['b' => 1, 'border' => 1, 'v' => 'top'] + $bg);
        $s2->set($k, 2, $row[2], ['border' => 1, 'v' => 'top'] + $bg);
        $s2->set($k, 3, $row[3], ['border' => 1, 'v' => 'top'] + $bg);
        foreach ([0, 1, 2, 3] as $q) {
            $cell = urr_cell($menuRoles[$row[4]][$q] ?? []);
            $lines = max($lines, count($cell));
            $s2->set($k, $q + 4, implode("\n", $cell), ['border' => 1, 'wrap' => 1, 'v' => 'top'] + ($cell ? ['bg' => $C['full']] : $bg));
        }
        $s2->heights[$k] = max(18, 15 * $lines); $k++;
    }
    foreach ([24, 22, 36, 34, 34, 34, 34] as $i => $w) $s2->widths[$i + 1] = $w;
    $s2->freeze = [1, 0]; $s2->filter = 'A1:G' . max(2, $k - 1);

    /* Sheet 3: users */
    $s3 = $X->sheet('Users');
    foreach (['Username', 'Role', 'Access', 'Status', 'Pages permitted'] as $i => $h) $s3->set(1, $i + 1, $h, $H);
    $k = 2;
    foreach ($users as $u) {
        $role = $u['role']; $bg = $k % 2 ? ['bg' => $C['grey']] : [];
        $s3->set($k, 1, $u['username'], ['b' => 1, 'border' => 1] + $bg);
        $s3->set($k, 2, $role['role_name'] ?? '— no role —', ['border' => 1] + $bg);
        $s3->set($k, 3, !$role ? 'Dashboard only' : ($role['full_access'] ? 'FULL ACCESS' : 'Page based'), ['border' => 1, 'h' => 'center'] + $bg);
        $s3->set($k, 4, ($u['active'] ?? 1) ? 'Active' : 'Inactive', ['border' => 1, 'h' => 'center', 'color' => ($u['active'] ?? 1) ? '15803D' : 'B91C1C'] + $bg);
        $s3->set($k, 5, !$role ? 0 : ($role['full_access'] ? 'ALL' : count($u['granted'])), ['border' => 1, 'h' => 'center'] + $bg);
        $k++;
    }
    foreach ([26, 26, 16, 12, 16] as $i => $w) $s3->widths[$i + 1] = $w;
    $s3->freeze = [1, 0]; $s3->filter = 'A1:E' . max(2, $k - 1);

    if (!class_exists('ZipArchive')) { http_response_code(500); exit('The PHP zip extension is required for Excel export.'); }
    $tmp = tempnam(sys_get_temp_dir(), 'ur');
    $X->save($tmp);
    rbac_audit(null, '', 'export', 'Exported User Role Report (' . count($users) . ' users)');
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="user_role_report_' . date('Ymd_His') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp); @unlink($tmp); exit;
}

/* ── page ── */
$qs = $_GET; unset($qs['export']);
$base = fn(array $o = []) => 'user_role_report.php?' . http_build_query(array_filter($o + $qs, fn($v) => $v !== '' && $v !== null && $v !== 0));
$n_full = count(array_filter($users, fn($u) => $u['role'] && $u['role']['full_access']));
$n_norole = count(array_filter($users, fn($u) => !$u['role']));
include 'header.php';
?>
<style>
.urr{max-width:1200px;margin:18px auto;padding:0 14px;font-family:Inter,system-ui,sans-serif;color:#111827}
.urr h2{margin:0;font-size:20px}.urr .sub{color:#6b7280;font-size:13px;margin:3px 0 14px}
.urr-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin-bottom:12px}
.urr-bar label{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;display:block;margin-bottom:3px}
.urr-bar select,.urr-bar input{padding:7px 9px;border:1px solid #d1d5db;border-radius:7px;font-size:13px}
.urr-btn{padding:8px 14px;border-radius:7px;border:1px solid #d1d5db;background:#fff;font-weight:600;font-size:13px;cursor:pointer;text-decoration:none;color:#111827;display:inline-flex;gap:6px;align-items:center}
.urr-btn.pri{background:#0f766e;border-color:#0f766e;color:#fff}.urr-btn.xl{border-color:#15803d;color:#15803d}
.urr-stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.urr-stat{background:#f0fdfa;border-radius:10px;padding:8px 16px;text-align:center}.urr-stat b{display:block;font-size:20px;color:#0f766e}.urr-stat span{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase}
.urr-tabs{display:flex;gap:6px;margin-bottom:12px}.urr-tabs a{padding:8px 16px;border-radius:8px;background:#f3f4f6;font-weight:700;font-size:13px;color:#374151;text-decoration:none}.urr-tabs a.on{background:#0f766e;color:#fff}
.urr-user{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:10px;overflow:hidden}
.urr-user>summary{list-style:none;cursor:pointer;display:flex;gap:12px;align-items:center;padding:11px 14px;background:linear-gradient(90deg,#0f766e,#0d9488);color:#fff}
.urr-user>summary::-webkit-details-marker{display:none}
.urr-user.full>summary{background:linear-gradient(90deg,#b45309,#d97706)}.urr-user.off>summary{background:linear-gradient(90deg,#6b7280,#9ca3af)}
.urr-av{width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;font-weight:800}
.urr-un{font-weight:800;font-size:15px}.urr-chip{background:rgba(255,255,255,.2);border-radius:20px;padding:2px 10px;font-size:11.5px;font-weight:700}
.urr-user table,.urr-menu{width:100%;border-collapse:collapse;font-size:12.5px}
.urr-user th,.urr-menu th{background:#134e4a;color:#fff;padding:6px 8px;text-align:left;font-size:11px;text-transform:uppercase}
.urr-user td,.urr-menu td{padding:6px 8px;border-top:1px solid #f1f5f9;vertical-align:top}
.urr-r{display:inline-block;min-width:20px;text-align:center;border-radius:5px;font-weight:800;font-size:11px;padding:1px 4px;margin-right:2px}
.urr-r.on{background:#d1fae5;color:#15803d}.urr-r.off{background:#f3f4f6;color:#d1d5db}
.urr-note{padding:12px 14px;font-size:13px;color:#b91c1c}.urr-gold{padding:12px 14px;background:#fef3c7;font-size:13px}
.urr-mw{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:auto}
.urr-ub{display:inline-block;background:#f0fdfa;border:1px solid #99f6e4;border-radius:20px;padding:1px 9px;margin:1px 3px 1px 0;font-size:11.5px}
.urr-ub.f{background:#fef3c7;border-color:#fcd34d}
</style>
<div class="urr">
  <h2><i class="fa-solid fa-clipboard-list" style="color:#0f766e"></i> User Role Report</h2>
  <div class="sub">Every user with the menu / sub menu pages their role allows — and, by menu, which users share the same access.</div>

  <form class="urr-bar" method="get">
    <input type="hidden" name="view" value="<?= $view ?>">
    <div><label>Role</label><select name="role_id"><option value="">All roles</option>
      <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $f_role == $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['role_name']) ?></option><?php endforeach; ?></select></div>
    <div><label>User status</label><select name="status"><option value="">All</option><option value="1" <?= $f_status === '1' ? 'selected' : '' ?>>Active</option><option value="0" <?= $f_status === '0' ? 'selected' : '' ?>>Inactive</option></select></div>
    <div><label>User</label><input name="q" value="<?= htmlspecialchars($f_q) ?>" placeholder="username"></div>
    <?php if ($view === 'menu'): ?><div><label>Page / menu</label><input name="page_q" value="<?= htmlspecialchars($f_page) ?>" placeholder="search menu or page"></div><?php endif; ?>
    <button class="urr-btn pri"><i class="fa-solid fa-filter"></i> Apply</button>
    <a class="urr-btn" href="user_role_report.php?view=<?= $view ?>">Reset</a>
    <a class="urr-btn xl" href="<?= htmlspecialchars($base(['export' => 1])) ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
  </form>

  <div class="urr-stats">
    <div class="urr-stat"><b><?= count($users) ?></b><span>Users</span></div>
    <div class="urr-stat"><b><?= $n_full ?></b><span>Full access</span></div>
    <div class="urr-stat"><b><?= $n_norole ?></b><span>No role</span></div>
    <div class="urr-stat"><b><?= count($menuUsers) ?></b><span>Pages in use</span></div>
  </div>
  <?php if ($fullUsers && $view === 'menu'): ?><div class="urr-gold" style="border-radius:10px;margin-bottom:10px">★ Full Access users (can open every page): <b><?= htmlspecialchars(implode(', ', $fullUsers)) ?></b></div><?php endif; ?>

  <div class="urr-tabs">
    <a class="<?= $view === 'user' ? 'on' : '' ?>" href="<?= htmlspecialchars($base(['view' => 'user'])) ?>"><i class="fa-solid fa-user"></i> By User</a>
    <a class="<?= $view === 'menu' ? 'on' : '' ?>" href="<?= htmlspecialchars($base(['view' => 'menu'])) ?>"><i class="fa-solid fa-sitemap"></i> By Menu (View / Add / Edit / Delete → roles)</a>
  </div>

<?php if ($view === 'user'): ?>
  <?php if (!$users): ?><div class="urr-note">No users match this filter.</div><?php endif; ?>
  <?php foreach ($users as $u): $role = $u['role']; $full = $role && $role['full_access']; $active = (int)($u['active'] ?? 1); ?>
    <details class="urr-user <?= $full ? 'full' : '' ?> <?= !$active ? 'off' : '' ?>">
      <summary>
        <span class="urr-av"><?= htmlspecialchars(strtoupper(substr($u['username'], 0, 1))) ?></span>
        <span class="urr-un"><?= htmlspecialchars($u['username']) ?></span>
        <span class="urr-chip"><i class="fa-solid fa-user-shield"></i> <?= htmlspecialchars($role['role_name'] ?? 'No role') ?><?= $full ? ' ★' : '' ?></span>
        <span class="urr-chip"><?= $active ? 'Active' : 'Inactive' ?></span>
        <span class="urr-chip" style="margin-left:auto"><?= $full ? 'ALL pages' : count($u['granted']) . ' pages' ?></span>
      </summary>
      <?php if ($full): ?><div class="urr-gold">★ Full Access — can open, add, edit and delete on every page.</div>
      <?php elseif (!$role): ?><div class="urr-note">No role assigned — this user can only open the Dashboard.</div>
      <?php elseif (!$u['granted']): ?><div class="urr-note">This role has no page permission added yet.</div>
      <?php else: ?>
        <table><thead><tr><th>Main menu</th><th>Sub menu</th><th>Page</th><th style="width:150px">Rights</th></tr></thead><tbody>
        <?php $prev = null; foreach ($u['granted'] as $row): $x = urr_rights($role, $perm, $row[4]); $mk = $row[0] . '|' . $row[1]; ?>
          <tr><td style="font-weight:700"><?= $prev === $mk ? '' : htmlspecialchars($row[1]) ?></td><td><?= htmlspecialchars($row[2]) ?></td><td><?= htmlspecialchars($row[3]) ?></td>
              <td><?php foreach (['V', 'A', 'E', 'D'] as $i => $l): ?><span class="urr-r <?= $x[$i] ? 'on' : 'off' ?>"><?= $l ?></span><?php endforeach; ?></td></tr>
        <?php $prev = $mk; endforeach; ?></tbody></table>
      <?php endif; ?>
    </details>
  <?php endforeach; ?>
  <p style="font-size:12px;color:#6b7280">Click a user to open or close the permission list. V view · A add · E edit · D delete.</p>
<?php else: ?>
  <div class="urr-mw"><table class="urr-menu"><thead><tr><th>Main menu</th><th>Sub menu</th><th>Page</th>
    <?php foreach (['View', 'Add', 'Edit', 'Delete'] as $h): ?><th><?= $h ?> — roles</th><?php endforeach; ?></tr></thead><tbody>
  <?php $shown = 0; foreach ($rows as $row):
      if (empty($menuRoles[$row[4]])) continue;
      if ($f_page !== '' && stripos($row[1] . ' ' . $row[2] . ' ' . $row[3], $f_page) === false) continue; $shown++; ?>
    <tr><td style="font-weight:700"><?= htmlspecialchars($row[1]) ?></td><td><?= htmlspecialchars($row[2]) ?></td><td><?= htmlspecialchars($row[3]) ?></td>
      <?php foreach ([0, 1, 2, 3] as $q): $cell = $menuRoles[$row[4]][$q] ?? []; ?>
        <td style="min-width:150px"><?php foreach ($cell as $role => $names): ?><span class="urr-ub" title="<?= htmlspecialchars(implode(', ', $names)) ?>"><b><?= htmlspecialchars($role) ?></b> <span style="color:#6b7280"><?= htmlspecialchars(implode(', ', $names)) ?></span></span><br><?php endforeach; ?></td>
      <?php endforeach; ?></tr>
  <?php endforeach; if (!$shown): ?><tr><td colspan="7" class="urr-note">No page matches.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?>
</div>
<?php include 'footer.php'; ?>
