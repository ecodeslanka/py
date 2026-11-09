<?php
/**
 * role_export.php — Export Roles & Permissions to a formatted Excel file
 *   role_export.php              all roles
 *   role_export.php?role_ids=5,7 only the selected roles (role_id=5 = one role)
 * Sheets: Summary · Role Cards · Permission List · Users by Role — ONLY permitted pages / rights are listed
 * Needs the View right on User Role. Builds a real .xlsx with PHP's built-in ZipArchive (no library needed).
 */
if (session_status() === PHP_SESSION_NONE) session_start();
include_once 'config.php';
include_once 'auth.php';
require_once __DIR__ . '/rbac.php';
requireLogin();
if (!canView('role')) rbac_deny('view', 'role');

/* ── data ───────────────────────────────────────────────────── */
$ids_in = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['role_ids'] ?? ($_GET['role_id'] ?? '')))))));
$only = count($ids_in) === 1 ? $ids_in[0] : 0;          // exactly one role selected
$where = $ids_in ? 'WHERE r.id IN (' . implode(',', $ids_in) . ') ' : '';
$roles = [];
$res = rbac_q("SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count
               FROM roles r " . $where . "ORDER BY r.full_access DESC, r.role_name ASC");
while ($res && ($r = mysqli_fetch_assoc($res))) $roles[$r['id']] = $r;
if (!$roles) { http_response_code(404); exit('Role not found.'); }
$sel = count($ids_in) > 0;

$perm = [];   // role_id => page => [v,c,e,d]
$res = rbac_q("SELECT role_id, module_name, can_access, can_create, can_edit, can_delete FROM permissions");
$permAll = [];   // every role's rights, used to list ALL roles that have a right on a page
while ($res && ($p = mysqli_fetch_assoc($res))) {
    $rights = [(int)$p['can_access'], (int)$p['can_create'], (int)$p['can_edit'], (int)$p['can_delete']];
    $permAll[$p['role_id']][strtolower($p['module_name'])] = $rights;
    if (isset($roles[$p['role_id']])) $perm[$p['role_id']][strtolower($p['module_name'])] = $rights;
}
$allRoles = [];
$ar = rbac_q("SELECT id, role_name, full_access FROM roles WHERE active = 1 ORDER BY full_access DESC, role_name");
while ($ar && ($x = mysqli_fetch_assoc($ar))) $allRoles[] = $x;
/** names of ALL active roles that have right $k (0 view, 1 add, 2 edit, 3 delete) on $page */
function roles_with($k, $page) {
    global $allRoles, $permAll;
    $o = [];
    foreach ($allRoles as $r) if ($r['full_access'] || !empty($permAll[$r['id']][$page][$k])) $o[] = $r['role_name'];
    return $o;
}

/* page rows from the sidebar tree: Section → Main menu → Sub menu → Page */
$rows = [];
foreach (rbac_menu_tree() as $sec) {
    foreach ($sec['menus'] as $m) {
        if ($m['type'] === 'page') { $rows[] = [$sec['title'], $m['label'], '', $m['label'], $m['page']]; continue; }
        foreach ($m['items'] as $it) {
            if ($it['type'] === 'page') $rows[] = [$sec['title'], $m['label'], '', $it['label'], $it['page']];
            else foreach ($it['items'] as $pg) $rows[] = [$sec['title'], $m['label'], $it['label'], $pg['label'], $pg['page']];
        }
    }
}
function rights_of($role, $perm, $page) {
    if ($role['full_access']) return [1, 1, 1, 1];
    return $perm[$role['id']][$page] ?? [0, 0, 0, 0];
}
function rights_text($r) {
    if (!$r[0] && !$r[1] && !$r[2] && !$r[3]) return '–';
    return ($r[0] ? 'V' : '·') . ' ' . ($r[1] ? 'A' : '·') . ' ' . ($r[2] ? 'E' : '·') . ' ' . ($r[3] ? 'D' : '·');
}
/* only pages that were actually given to a role */
function has_any($r) { return $r[0] || $r[1] || $r[2] || $r[3]; }
$granted = [];       // role_id => [rows with at least one right]
foreach ($roles as $role) {
    $granted[$role['id']] = array_values(array_filter($rows, fn($row) => has_any(rights_of($role, $perm, $row[4]))));
}
$users_of = [];
$uq = rbac_q("SELECT u.username, u.active, u.role_id FROM users u WHERE u.role_id IN (" . implode(',', array_map('intval', array_keys($roles))) . ") ORDER BY u.username");
while ($uq && ($u = mysqli_fetch_assoc($uq))) $users_of[$u['role_id']][] = $u;
$me = $_SESSION['username'] ?? ($_SESSION['user_name'] ?? 'user');

require_once __DIR__ . '/rbac_xlsx.php';

/* ── build the workbook: ONLY permitted pages / rights are listed ───────── */
$fn = 'roles_permissions_' . ($only ? preg_replace('/\W+/', '_', $roles[$only]['role_name']) . '_' : ($sel ? count($roles) . 'roles_' : '')) . date('Ymd_His');

if (!class_exists('ZipArchive')) {          // last resort: CSV, still permitted pages only
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fn . '.csv"');
    echo "\xEF\xBB\xBF"; $o = fopen('php://output', 'w');
    fputcsv($o, ['Role', 'Users', 'Section', 'Main Menu', 'Sub Menu', 'Page', 'View', 'Add', 'Edit', 'Delete']);
    foreach ($roles as $role) {
        $un = implode(', ', array_column($users_of[$role['id']] ?? [], 'username'));
        if ($role['full_access']) { fputcsv($o, [$role['role_name'], $un, 'ALL', 'ALL PAGES', '', '', 'Yes', 'Yes', 'Yes', 'Yes']); continue; }
        foreach ($granted[$role['id']] as $row) { $x = rights_of($role, $perm, $row[4]);
            fputcsv($o, [$role['role_name'], $un, $row[0], $row[1], $row[2], $row[3], $x[0] ? 'Yes' : '', $x[1] ? 'Yes' : '', $x[2] ? 'Yes' : '', $x[3] ? 'Yes' : '']); }
    }
    exit;
}

$C = ['teal' => '0F766E', 'dark' => '134E4A', 'light' => 'F0FDFA', 'grey' => 'F3F4F6', 'full' => 'D1FAE5', 'gold' => 'FDE68A', 'white' => 'FFFFFF'];
$accents = ['0F766E', '7C3AED', 'D97706', '2563EB', 'DB2777', '059669'];
$H = ['b' => 1, 'color' => $C['white'], 'bg' => $C['dark'], 'h' => 'center', 'border' => 1];
$X = new MiniXlsx();

/* Sheet 1 — Summary */
$s1 = $X->sheet('Summary');
$s1->set(1, 1, 'Roles & Permissions Report', ['b' => 1, 'sz' => 20, 'color' => $C['white'], 'bg' => $C['teal'], 'indent' => 1]);
for ($c = 2; $c <= 9; $c++) $s1->set(1, $c, '', ['bg' => $C['teal']]);
$s1->merge(1, 1, 1, 9); $s1->heights[1] = 38;
$s1->set(2, 1, 'Generated ' . date('d M Y, H:i') . ' by ' . $me . '  ·  only permitted pages and rights are listed', ['i' => 1, 'color' => '6B7280', 'indent' => 1]);
$s1->merge(2, 1, 2, 9);
$kpi = [['Roles', count($roles)], ['Active roles', count(array_filter($roles, fn($r) => $r['active']))],
        ['Full-access roles', count(array_filter($roles, fn($r) => $r['full_access']))], ['Users with a role', array_sum(array_column($roles, 'user_count'))]];
foreach ($kpi as $i => [$lab, $val]) {
    $s1->set(4, $i + 1, (int)$val, ['b' => 1, 'sz' => 22, 'color' => $C['teal'], 'bg' => $C['light'], 'h' => 'center']);
    $s1->set(5, $i + 1, $lab, ['b' => 1, 'sz' => 9, 'color' => '6B7280', 'bg' => $C['light'], 'h' => 'center']);
}
$s1->heights[4] = 34;
foreach (['Role', 'Status', 'Access level', 'Users', 'Pages permitted', 'With Add', 'With Edit', 'With Delete', 'User names'] as $i => $h) $s1->set(7, $i + 1, $h, $H);
$s1->heights[7] = 22; $r = 8;
foreach ($roles as $role) {
    $cnt = [0, 0, 0, 0];
    foreach ($granted[$role['id']] as $row) { $x = rights_of($role, $perm, $row[4]); for ($k = 0; $k < 4; $k++) $cnt[$k] += $x[$k]; }
    $bg = $role['full_access'] ? $C['gold'] : ($r % 2 ? null : $C['grey']);
    $b = ['border' => 1, 'h' => 'center'] + ($bg ? ['bg' => $bg] : []) + ($role['full_access'] ? ['b' => 1] : []);
    $s1->set($r, 1, $role['role_name'], ['border' => 1, 'b' => 1] + ($bg ? ['bg' => $bg] : []));
    $s1->set($r, 2, $role['active'] ? 'Active' : 'Inactive', $b + ['color' => $role['active'] ? '15803D' : 'B91C1C']);
    $s1->set($r, 3, $role['full_access'] ? 'FULL ACCESS' : 'Page based', $b);
    $s1->set($r, 4, (int)$role['user_count'], $b);
    $s1->set($r, 5, $role['full_access'] ? 'ALL' : $cnt[0], $b);
    foreach ([1, 2, 3] as $k) $s1->set($r, 5 + $k, $role['full_access'] ? 'ALL' : $cnt[$k], $b);
    $s1->set($r, 9, implode(', ', array_column($users_of[$role['id']] ?? [], 'username')), ['border' => 1, 'wrap' => 1] + ($bg ? ['bg' => $bg] : []));
    $r++;
}
foreach ([28, 12, 18, 18, 16, 12, 12, 13, 48] as $i => $w) $s1->widths[$i + 1] = $w;

/* Sheet 2 — Role Cards: who has the role + only the pages / rights it was given */
$sc = $X->sheet('Role Cards');
foreach ([3, 24, 26, 38, 19, 19, 19, 19] as $i => $w) $sc->widths[$i + 1] = $w;
$cr = 1; $ri = 0;
foreach ($roles as $role) {
    $accent = $role['full_access'] ? 'B45309' : $accents[$ri++ % count($accents)];
    $us = $users_of[$role['id']] ?? [];
    $sc->set($cr, 2, '👤  ' . $role['role_name'] . ($role['full_access'] ? '   ★ FULL ACCESS' : '') . ($role['active'] ? '' : '   (inactive)'),
        ['b' => 1, 'sz' => 15, 'color' => $C['white'], 'bg' => $accent, 'indent' => 1]);
    for ($c = 3; $c <= 8; $c++) $sc->set($cr, $c, '', ['bg' => $accent]);
    $sc->merge($cr, 2, $cr, 8); $sc->heights[$cr] = 30; $cr++;
    $line = 'Users (' . count($us) . '):  ' . ($us ? implode('   •   ', array_map(fn($u) => $u['username'] . ($u['active'] ? '' : ' (inactive)'), $us)) : 'no user assigned')
          . '        Pages given: ' . ($role['full_access'] ? 'ALL' : count($granted[$role['id']]));
    $sc->set($cr, 2, $line, ['i' => 1, 'bg' => $C['light'], 'wrap' => 1, 'indent' => 1]);
    for ($c = 3; $c <= 8; $c++) $sc->set($cr, $c, '', ['bg' => $C['light']]);
    $sc->merge($cr, 2, $cr, 8); $sc->heights[$cr] = max(22, 16 * ceil(mb_strlen($line) / 100)); $cr++;
    if ($role['full_access']) {
        $sc->set($cr, 2, 'Can open, add, edit and delete on every page.', ['bg' => $C['gold'], 'indent' => 1]);
        for ($c = 3; $c <= 8; $c++) $sc->set($cr, $c, '', ['bg' => $C['gold']]);
        $sc->merge($cr, 2, $cr, 8); $cr += 3; continue;
    }
    if (!$granted[$role['id']]) {
        $sc->set($cr, 2, 'No page permission added yet.', ['color' => 'B91C1C', 'indent' => 1]); $sc->merge($cr, 2, $cr, 8); $cr += 3; continue;
    }
    foreach (['Main Menu', 'Sub Menu', 'Page', 'View', 'Add', 'Edit', 'Delete'] as $i => $h) $sc->set($cr, $i + 2, $h, $H);
    $cr++; $prev = null;
    foreach ($granted[$role['id']] as $row) {
        $x = rights_of($role, $perm, $row[4]); $mk = $row[0] . '|' . $row[1];
        $sc->set($cr, 2, $prev === $mk ? '' : $row[1], ['b' => 1, 'border' => 1]); $prev = $mk;
        $sc->set($cr, 3, $row[2], ['border' => 1]); $sc->set($cr, 4, $row[3], ['border' => 1]);
        $lines = 1;
        foreach ([0, 1, 2, 3] as $k) {
            $names = $x[$k] ? roles_with($k, $row[4]) : [];          // every role that has this right on this page
            $lines = max($lines, count($names));
            $sc->set($cr, $k + 5, implode("\n", $names), ['b' => 1, 'color' => '15803D', 'h' => 'center', 'wrap' => 1, 'border' => 1] + ($x[$k] ? ['bg' => $C['full']] : []));
        }
        $sc->heights[$cr] = max(18, 15 * $lines);
        $cr++;
    }
    $cr += 2;
}

/* Sheet 3 — Permission List (flat, filterable): granted rows only */
$sp = $X->sheet('Permission List');
foreach (['Role', 'Users', 'Section', 'Main Menu', 'Sub Menu', 'Page', 'File', 'View', 'Add', 'Edit', 'Delete'] as $i => $h) $sp->set(1, $i + 1, $h, $H);
$pr = 2;
foreach ($roles as $role) {
    $un = implode(', ', array_column($users_of[$role['id']] ?? [], 'username'));
    $items = $role['full_access'] ? [[null]] : $granted[$role['id']];
    foreach ($items as $row) {
        $x = $role['full_access'] ? [1, 1, 1, 1] : rights_of($role, $perm, $row[4]);
        $vals = $role['full_access'] ? ['ALL', 'ALL PAGES', '', 'Every page in the system', ''] : [$row[0], $row[1], $row[2], $row[3], $row[4] . '.php'];
        $sp->set($pr, 1, $role['role_name'], ['b' => 1, 'border' => 1]); $sp->set($pr, 2, $un, ['border' => 1]);
        foreach ($vals as $i => $v) $sp->set($pr, $i + 3, $v, ['border' => 1]);
        foreach ($x as $k => $v) $sp->set($pr, $k + 8, $v ? '✔' : '', ['b' => 1, 'color' => '15803D', 'h' => 'center', 'border' => 1] + ($v ? ['bg' => $C['full']] : []));
        $pr++;
    }
}
foreach ([24, 26, 18, 24, 24, 36, 28, 8, 8, 8, 8] as $i => $w) $sp->widths[$i + 1] = $w;
$sp->freeze = [1, 0]; $sp->filter = 'A1:K' . max(2, $pr - 1);

/* Sheet 4 — Users by Role */
$su = $X->sheet('Users by Role');
foreach (['Role', 'Username', 'Status'] as $i => $h) $su->set(1, $i + 1, $h, $H);
$k = 2;
foreach ($roles as $role) foreach ($users_of[$role['id']] ?? [] as $u) {
    $bg = $k % 2 ? ['bg' => $C['grey']] : [];
    $su->set($k, 1, $role['role_name'], ['b' => 1, 'border' => 1] + $bg); $su->set($k, 2, $u['username'], ['border' => 1] + $bg);
    $su->set($k, 3, $u['active'] ? 'Active' : 'Inactive', ['border' => 1, 'color' => $u['active'] ? '15803D' : 'B91C1C'] + $bg); $k++;
}
foreach ([28, 28, 12] as $i => $w) $su->widths[$i + 1] = $w;
$su->freeze = [1, 0];

$tmp = tempnam(sys_get_temp_dir(), 'rx');
$X->save($tmp);
rbac_audit($only ?: null, $only ? $roles[$only]['role_name'] : ($sel ? count($roles) . ' selected roles' : 'All roles'), 'export', 'Exported roles & permissions to Excel');
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fn . '.xlsx"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: max-age=0');
readfile($tmp); @unlink($tmp);
exit;
