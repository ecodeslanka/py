<?php
/**
 * role_export.php — Export Roles & Permissions to a formatted Excel file
 *   role_export.php              all roles
 *   role_export.php?role_ids=5,7 only the selected roles (role_id=5 = one role)
 * Sheets: Summary · Role Cards · Permission List · Users by Role — ONLY permitted pages / rights are listed
 * Needs the View right on User Role. Falls back to CSV when PhpSpreadsheet is not installed.
 */
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

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
while ($res && ($p = mysqli_fetch_assoc($res))) {
    if (!isset($roles[$p['role_id']])) continue;
    $perm[$p['role_id']][strtolower($p['module_name'])] = [(int)$p['can_access'], (int)$p['can_create'], (int)$p['can_edit'], (int)$p['can_delete']];
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

/* ── CSV fallback ───────────────────────────────────────────── */
$loaded = class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet');
if (!$loaded) {
    foreach ([__DIR__ . '/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php', '/var/www/html/vendor/autoload.php'] as $ap) {
        if (file_exists($ap)) { require_once $ap; $loaded = class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet'); if ($loaded) break; }
    }
}
if (!$loaded) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="roles_permissions_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF"; $o = fopen('php://output', 'w');
    fputcsv($o, array_merge(['Section', 'Main Menu', 'Sub Menu', 'Page'], array_map(fn($r) => $r['role_name'] . ' (V A E D)', $roles)));
    foreach ($rows as $row) {
        $line = [$row[0], $row[1], $row[2], $row[3]];
        foreach ($roles as $r) $line[] = rights_text(rights_of($r, $perm, $row[4]));
        fputcsv($o, $line);
    }
    exit;
}

/* ── workbook ───────────────────────────────────────────────── */
$C = ['teal' => 'FF0F766E', 'dark' => 'FF134E4A', 'light' => 'FFF0FDFA', 'grey' => 'FFF3F4F6', 'line' => 'FFE5E7EB',
      'full' => 'FFD1FAE5', 'part' => 'FFFEF3C7', 'view' => 'FFDBEAFE', 'none' => 'FFF9FAFB', 'gold' => 'FFFDE68A', 'white' => 'FFFFFFFF'];
$secColors = ['FF0F766E', 'FF7C3AED', 'FFD97706', 'FF2563EB', 'FFDB2777', 'FF059669'];
$fill = fn($c) => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $c]];
$thin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => $C['line']]]]];

$ss = new Spreadsheet();
$ss->getProperties()->setTitle('Roles & Permissions')->setCreator($me);

/* Sheet 1 — Summary */
$s1 = $ss->getActiveSheet(); $s1->setTitle('Summary'); $s1->setShowGridlines(false);
$s1->mergeCells('A1:H1'); $s1->setCellValue('A1', 'Roles & Permissions Report');
$s1->getStyle('A1')->applyFromArray(['font' => ['bold' => true, 'size' => 20, 'color' => ['argb' => $C['white']]], 'fill' => $fill($C['teal']),
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1]]);
$s1->getRowDimension(1)->setRowHeight(38);
$s1->mergeCells('A2:H2'); $s1->setCellValue('A2', 'Generated ' . date('d M Y, H:i') . ' by ' . $me . '  ·  Rights: V = View, A = Add, E = Edit, D = Delete');
$s1->getStyle('A2')->applyFromArray(['font' => ['italic' => true, 'color' => ['argb' => 'FF6B7280']], 'alignment' => ['indent' => 1]]);

$totalPages = count($rows);
$kpi = [['Roles', count($roles)], ['Active roles', count(array_filter($roles, fn($r) => $r['active']))],
        ['Full-access roles', count(array_filter($roles, fn($r) => $r['full_access']))], ['Menu pages', $totalPages],
        ['Users with a role', array_sum(array_column($roles, 'user_count'))]];
$col = 1;
foreach ($kpi as [$lab, $val]) {
    $a = Coordinate::stringFromColumnIndex($col);
    $s1->setCellValue($a . '4', $val); $s1->setCellValue($a . '5', $lab);
    $s1->getStyle($a . '4')->applyFromArray(['font' => ['bold' => true, 'size' => 22, 'color' => ['argb' => $C['teal']]], 'alignment' => ['horizontal' => 'center'], 'fill' => $fill($C['light'])]);
    $s1->getStyle($a . '5')->applyFromArray(['font' => ['size' => 9, 'bold' => true, 'color' => ['argb' => 'FF6B7280']], 'alignment' => ['horizontal' => 'center'], 'fill' => $fill($C['light'])]);
    $col++;
}
$s1->getRowDimension(4)->setRowHeight(34);

$hdr = ['Role', 'Status', 'Access level', 'Users', 'Pages: View', 'Pages: Add', 'Pages: Edit', 'Pages: Delete', 'Coverage', 'User names'];
foreach ($hdr as $i => $h) $s1->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '7', $h);
$s1->getStyle('A7:J7')->applyFromArray(['font' => ['bold' => true, 'color' => ['argb' => $C['white']]], 'fill' => $fill($C['dark']),
    'alignment' => ['horizontal' => 'center', 'vertical' => 'center']]);
$s1->getRowDimension(7)->setRowHeight(22);
$r = 8;
foreach ($roles as $role) {
    $cnt = [0, 0, 0, 0];
    foreach ($rows as $row) { $x = rights_of($role, $perm, $row[4]); for ($k = 0; $k < 4; $k++) $cnt[$k] += $x[$k]; }
    $pct = $totalPages ? $cnt[0] / $totalPages : 0;
    $s1->fromArray([$role['role_name'], $role['active'] ? 'Active' : 'Inactive', $role['full_access'] ? 'FULL ACCESS' : 'Page based',
                    (int)$role['user_count'], $cnt[0], $cnt[1], $cnt[2], $cnt[3], $pct,
                    implode(', ', array_column($users_of[$role['id']] ?? [], 'username'))], null, "A$r");
    $s1->getStyle("I$r")->getNumberFormat()->setFormatCode('0%');
    $s1->getStyle("B$r")->getFont()->getColor()->setARGB($role['active'] ? 'FF15803D' : 'FFB91C1C');
    if ($role['full_access']) $s1->getStyle("A$r:J$r")->applyFromArray(['fill' => $fill($C['gold']), 'font' => ['bold' => true]]);
    elseif ($r % 2 == 0) $s1->getStyle("A$r:J$r")->applyFromArray(['fill' => $fill($C['grey'])]);
    $r++;
}
$s1->getStyle("A8:J" . ($r - 1))->applyFromArray($thin);
$s1->getStyle("J8:J" . ($r - 1))->getAlignment()->setWrapText(true);
$s1->getStyle("B8:I" . ($r - 1))->getAlignment()->setHorizontal('center');
if ($r > 8) {   // data bar on coverage
    $cf = new \PhpOffice\PhpSpreadsheet\Style\Conditional();
    $cf->setConditionType(\PhpOffice\PhpSpreadsheet\Style\Conditional::CONDITION_CELLIS)->setOperatorType(\PhpOffice\PhpSpreadsheet\Style\Conditional::OPERATOR_GREATERTHANOREQUAL)->addCondition(0.75);
    $cf->getStyle()->applyFromArray(['font' => ['bold' => true, 'color' => ['argb' => 'FF15803D']]]);
    $s1->getStyle("I8:I" . ($r - 1))->setConditionalStyles([$cf]);
}
foreach (['A' => 28, 'B' => 14, 'C' => 16, 'D' => 12, 'E' => 14, 'F' => 14, 'G' => 14, 'H' => 14, 'I' => 12, 'J' => 46] as $c => $w) $s1->getColumnDimension($c)->setWidth($w);

/* Sheet — Role Cards: one block per role = who has it + only the pages / rights it was given */
$sc = $ss->createSheet(); $sc->setTitle('Role Cards'); $sc->setShowGridlines(false);
foreach (['A' => 3, 'B' => 24, 'C' => 26, 'D' => 38, 'E' => 9, 'F' => 9, 'G' => 9, 'H' => 9] as $c => $w) $sc->getColumnDimension($c)->setWidth($w);
$cr = 1; $ri = 0;
foreach ($roles as $role) {
    $accent = $role['full_access'] ? 'FFB45309' : $secColors[$ri++ % count($secColors)];
    $us = $users_of[$role['id']] ?? [];
    $sc->mergeCells("B$cr:H$cr");
    $sc->setCellValue("B$cr", '👤  ' . $role['role_name'] . ($role['full_access'] ? '   ★ FULL ACCESS' : '') . ($role['active'] ? '' : '   (inactive)'));
    $sc->getStyle("B$cr:H$cr")->applyFromArray(['font' => ['bold' => true, 'size' => 15, 'color' => ['argb' => $C['white']]], 'fill' => $fill($accent),
        'alignment' => ['vertical' => 'center', 'indent' => 1]]);
    $sc->getRowDimension($cr)->setRowHeight(30); $cr++;
    $sc->mergeCells("B$cr:H$cr");
    $sc->setCellValue("B$cr", 'Users (' . count($us) . '):  ' . ($us ? implode('   •   ', array_map(fn($u) => $u['username'] . ($u['active'] ? '' : ' (inactive)'), $us)) : 'no user assigned')
        . '        Pages given: ' . count($granted[$role['id']]));
    $sc->getStyle("B$cr:H$cr")->applyFromArray(['fill' => $fill($C['light']), 'font' => ['italic' => true], 'alignment' => ['wrapText' => true, 'vertical' => 'center', 'indent' => 1]]);
    $sc->getRowDimension($cr)->setRowHeight(max(22, 16 * ceil(strlen($sc->getCell("B$cr")->getValue()) / 105))); $cr++;

    if ($role['full_access']) {
        $sc->mergeCells("B$cr:H$cr"); $sc->setCellValue("B$cr", 'Can open, add, edit and delete on every page — no page list needed.');
        $sc->getStyle("B$cr")->applyFromArray(['fill' => $fill($C['gold']), 'alignment' => ['indent' => 1]]); $cr += 2; continue;
    }
    if (!$granted[$role['id']]) {
        $sc->mergeCells("B$cr:H$cr"); $sc->setCellValue("B$cr", 'No page permission added yet.');
        $sc->getStyle("B$cr")->applyFromArray(['font' => ['color' => ['argb' => 'FFB91C1C']], 'alignment' => ['indent' => 1]]); $cr += 2; continue;
    }
    foreach (['Main Menu', 'Sub Menu', 'Page', 'View', 'Add', 'Edit', 'Delete'] as $i => $h) $sc->setCellValue(Coordinate::stringFromColumnIndex($i + 2) . $cr, $h);
    $sc->getStyle("B$cr:H$cr")->applyFromArray(['font' => ['bold' => true, 'color' => ['argb' => $C['white']]], 'fill' => $fill($C['dark']), 'alignment' => ['horizontal' => 'center']]);
    $cr++; $prev = null;
    foreach ($granted[$role['id']] as $row) {
        $x = rights_of($role, $perm, $row[4]);
        $mk = $row[0] . '|' . $row[1];
        $sc->setCellValue("B$cr", $prev === $mk ? '' : $row[1]);  $prev = $mk;
        $sc->setCellValue("C$cr", $row[2]); $sc->setCellValue("D$cr", $row[3]);
        foreach ([0, 1, 2, 3] as $k) {
            $a = Coordinate::stringFromColumnIndex($k + 5);
            $sc->setCellValue($a . $cr, $x[$k] ? '✔' : '');
            $sc->getStyle($a . $cr)->applyFromArray(['alignment' => ['horizontal' => 'center'],
                'font' => ['bold' => true, 'color' => ['argb' => 'FF15803D']]] + ($x[$k] ? ['fill' => $fill($C['full'])] : []));
        }
        $sc->getStyle("B$cr")->getFont()->setBold(true);
        $sc->getStyle("B$cr:H$cr")->applyFromArray($thin);
        $cr++;
    }
    $cr += 2;
}

/* Sheet 3 — Permission List (flat: one line per role + page, ready to filter / pivot) */
$sp = $ss->createSheet(); $sp->setTitle('Permission List');
$sp->fromArray(['Role', 'Section', 'Main Menu', 'Sub Menu', 'Page', 'File', 'View', 'Add', 'Edit', 'Delete', 'Rights'], null, 'A1');
$sp->getStyle('A1:K1')->applyFromArray(['font' => ['bold' => true, 'color' => ['argb' => $C['white']]], 'fill' => $fill($C['dark']),
    'alignment' => ['horizontal' => 'center']]);
$pr = 2;
foreach ($roles as $role) {
    if ($role['full_access']) {
        $sp->fromArray([$role['role_name'], 'ALL', 'ALL PAGES', '', 'Every page in the system', '', '✔', '✔', '✔', '✔', '4/4'], null, "A$pr"); $pr++;
        continue;
    }
    foreach ($granted[$role['id']] as $row) {
        $x = rights_of($role, $perm, $row[4]);
        $yn = fn($v) => $v ? '✔' : '';
        $sp->fromArray([$role['role_name'], $row[0], $row[1], $row[2], $row[3], $row[4] . '.php', $yn($x[0]), $yn($x[1]), $yn($x[2]), $yn($x[3]), array_sum($x) . '/4'], null, "A$pr");
        $pr++;
    }
}
$plast = max(2, $pr - 1);
$sp->getStyle("A1:K$plast")->applyFromArray($thin);
$sp->getStyle("G2:K$plast")->getAlignment()->setHorizontal('center');
$sp->getStyle("G2:J$plast")->getFont()->setBold(true)->getColor()->setARGB('FF15803D');
foreach (['A' => 24, 'B' => 18, 'C' => 22, 'D' => 22, 'E' => 34, 'F' => 28, 'G' => 8, 'H' => 8, 'I' => 8, 'J' => 8, 'K' => 9] as $c => $w) $sp->getColumnDimension($c)->setWidth($w);
$sp->freezePane('F2');
$sp->setAutoFilter("A1:K$plast");

/* Sheet 4 — Users by Role */
$s3 = $ss->createSheet(); $s3->setTitle('Users by Role');
$s3->fromArray(['Role', 'Username', 'Status'], null, 'A1');
$s3->getStyle('A1:C1')->applyFromArray(['font' => ['bold' => true, 'color' => ['argb' => $C['white']]], 'fill' => $fill($C['dark'])]);
$ids = implode(',', array_map('intval', array_keys($roles)));
$ur = rbac_q("SELECT u.username, u.active, u.role_id FROM users u WHERE u.role_id IN ($ids) ORDER BY u.role_id, u.username");
$k = 2;
while ($ur && ($u = mysqli_fetch_assoc($ur))) {
    $s3->fromArray([$roles[$u['role_id']]['role_name'], $u['username'], $u['active'] ? 'Active' : 'Inactive'], null, "A$k");
    if ($k % 2 == 1) $s3->getStyle("A$k:C$k")->applyFromArray(['fill' => $fill($C['grey'])]);
    $k++;
}
foreach (['A' => 28, 'B' => 28, 'C' => 12] as $c => $w) $s3->getColumnDimension($c)->setWidth($w);
$s3->freezePane('A2');

$ss->setActiveSheetIndex(0);
rbac_audit($only ?: null, $only ? $roles[$only]['role_name'] : ($sel ? count($roles) . ' selected roles' : 'All roles'), 'export', 'Exported roles & permissions to Excel');

while (ob_get_level()) ob_end_clean();
$fn = 'roles_permissions_' . ($only ? preg_replace('/\W+/', '_', $roles[$only]['role_name']) . '_' : ($sel ? count($roles) . 'roles_' : '')) . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fn . '"');
header('Cache-Control: max-age=0');
(new Xlsx($ss))->save('php://output');
exit;
