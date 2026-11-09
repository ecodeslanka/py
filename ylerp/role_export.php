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

/* ── tiny dependency-free .xlsx writer (ZipArchive only) ───────────────── */
class MiniSheet {
    public $name; public $cells = []; public $merges = []; public $widths = []; public $heights = [];
    public $freeze = null; public $filter = null; public $maxR = 0; public $maxC = 0;
    function __construct($n) { $this->name = $n; }
    function set($r, $c, $v, $st = []) { $this->cells[$r][$c] = [$v, $st]; $this->maxR = max($this->maxR, $r); $this->maxC = max($this->maxC, $c); }
    function style($r, $c, $st) { if (!isset($this->cells[$r][$c])) $this->set($r, $c, '', $st); else $this->cells[$r][$c][1] = $st; }
    function merge($r1, $c1, $r2, $c2) { $this->merges[] = [$r1, $c1, $r2, $c2]; }
}
class MiniXlsx {
    public $sheets = []; private $xf = ['[]' => 0]; private $xfList = [[]];
    function sheet($n) { return $this->sheets[] = new MiniSheet($n); }
    static function col($i) { $s = ''; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; }
    static function esc($t) { return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$t), ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
    private function sid($st) { ksort($st); $k = json_encode($st); if (!isset($this->xf[$k])) { $this->xf[$k] = count($this->xfList); $this->xfList[] = $st; } return $this->xf[$k]; }
    private function stylesXml() {
        $fonts = ['<font><sz val="10"/><name val="Calibri"/></font>']; $fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];
        $fi = []; $fl = []; $xfs = [];
        foreach ($this->xfList as $st) {
            $f = 0; $fill = 0; $b = 0;
            if (array_intersect_key($st, ['b' => 1, 'i' => 1, 'sz' => 1, 'color' => 1])) {
                $k = json_encode([$st['b'] ?? 0, $st['i'] ?? 0, $st['sz'] ?? 10, $st['color'] ?? '']);
                if (!isset($fi[$k])) { $fi[$k] = count($fonts);
                    $fonts[] = '<font>' . (!empty($st['b']) ? '<b/>' : '') . (!empty($st['i']) ? '<i/>' : '') . '<sz val="' . ($st['sz'] ?? 10) . '"/>'
                        . (!empty($st['color']) ? '<color rgb="FF' . $st['color'] . '"/>' : '') . '<name val="Calibri"/></font>'; }
                $f = $fi[$k];
            }
            if (!empty($st['bg'])) { if (!isset($fl[$st['bg']])) { $fl[$st['bg']] = count($fills);
                $fills[] = '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $st['bg'] . '"/><bgColor indexed="64"/></patternFill></fill>'; } $fill = $fl[$st['bg']]; }
            if (!empty($st['border'])) $b = 1;
            $al = '';
            if (isset($st['h']) || isset($st['v']) || !empty($st['wrap']) || !empty($st['indent']))
                $al = '<alignment' . (isset($st['h']) ? ' horizontal="' . $st['h'] . '"' : '') . ' vertical="' . ($st['v'] ?? 'center') . '"'
                    . (!empty($st['wrap']) ? ' wrapText="1"' : '') . (!empty($st['indent']) ? ' indent="' . $st['indent'] . '"' : '') . '/>';
            $xfs[] = '<xf numFmtId="' . (!empty($st['pct']) ? 9 : 0) . '" fontId="' . $f . '" fillId="' . $fill . '" borderId="' . $b . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1"'
                . ($st['pct'] ?? 0 ? ' applyNumberFormat="1"' : '') . ($al ? ' applyAlignment="1">' . $al . '</xf>' : '/>');
        }
        $bd = '<left style="thin"><color rgb="FFE5E7EB"/></left><right style="thin"><color rgb="FFE5E7EB"/></right><top style="thin"><color rgb="FFE5E7EB"/></top><bottom style="thin"><color rgb="FFE5E7EB"/></bottom><diagonal/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts><fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border>' . $bd . '</border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
    private function sheetXml(MiniSheet $sh) {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $x .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0"' . ($sh === $this->sheets[0] ? ' tabSelected="1"' : '') . '>';
        if ($sh->freeze) { [$fr, $fc] = $sh->freeze;
            $x .= '<pane' . ($fc ? ' xSplit="' . $fc . '"' : '') . ($fr ? ' ySplit="' . $fr . '"' : '') . ' topLeftCell="' . self::col($fc + 1) . ($fr + 1) . '" state="frozen" activePane="'
                . ($fr && $fc ? 'bottomRight' : ($fr ? 'bottomLeft' : 'topRight')) . '"/>'; }
        $x .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';
        if ($sh->widths) { $x .= '<cols>'; ksort($sh->widths); foreach ($sh->widths as $c => $w) $x .= '<col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"/>'; $x .= '</cols>'; }
        $x .= '<sheetData>'; ksort($sh->cells);
        foreach ($sh->cells as $r => $cols) {
            ksort($cols);
            $x .= '<row r="' . $r . '"' . (isset($sh->heights[$r]) ? ' ht="' . $sh->heights[$r] . '" customHeight="1"' : '') . '>';
            foreach ($cols as $c => [$v, $st]) {
                $ref = self::col($c) . $r; $sid = $this->sid($st);
                if (is_int($v) || is_float($v)) $x .= '<c r="' . $ref . '" s="' . $sid . '"><v>' . $v . '</v></c>';
                elseif ($v === '' || $v === null) $x .= '<c r="' . $ref . '" s="' . $sid . '"/>';
                else $x .= '<c r="' . $ref . '" s="' . $sid . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc($v) . '</t></is></c>';
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($sh->filter) $x .= '<autoFilter ref="' . $sh->filter . '"/>';
        if ($sh->merges) { $x .= '<mergeCells count="' . count($sh->merges) . '">';
            foreach ($sh->merges as [$r1, $c1, $r2, $c2]) $x .= '<mergeCell ref="' . self::col($c1) . $r1 . ':' . self::col($c2) . $r2 . '"/>'; $x .= '</mergeCells>'; }
        return $x . '</worksheet>';
    }
    function save($path) {
        $sheetsX = []; foreach ($this->sheets as $i => $sh) $sheetsX[$i] = $this->sheetXml($sh);   // builds style table first
        $z = new ZipArchive(); $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rel = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $sh) { $n = $i + 1;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wb .= '<sheet name="' . self::esc($sh->name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rel .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $z->addFromString('xl/worksheets/sheet' . $n . '.xml', $sheetsX[$i]); }
        $sn = count($this->sheets) + 1;
        $rel .= '<Relationship Id="rId' . $sn . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $z->addFromString('[Content_Types].xml', $ct . '</Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', $rel);
        $z->addFromString('xl/styles.xml', $this->stylesXml());
        $z->close();
    }
}

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
foreach ([3, 24, 26, 38, 9, 9, 9, 9] as $i => $w) $sc->widths[$i + 1] = $w;
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
        foreach ([0, 1, 2, 3] as $k)
            $sc->set($cr, $k + 5, $x[$k] ? '✔' : '', ['b' => 1, 'color' => '15803D', 'h' => 'center', 'border' => 1] + ($x[$k] ? ['bg' => $C['full']] : []));
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
