<?php
// ============================================================
//  bulk_damage_import2.php  –  DAMAGE STOCK SYNC (Current Stock Excel)   (Sampath Operation)
//
//  Upload the LeverEDGE "Current Stock Report" of the DAMAGE GODOWN. Units are summed per SKU7 (all batches) and compared with
//  OUR Damaged Stock as at the chosen date (confirmed Sampath Damage Entry lines + applied imported damages, i.e. the Damaged
//  Stock column of the Sampath Inventory Report). The DIFFERENCE (Excel − ours) is posted as a damage line so that our Damaged
//  Stock equals the Excel:  Excel higher -> + damage units;  Excel lower -> − damage units.
//      SKU not in our system -> the item is CREATED and its damaged stock is set to the Excel units
//      item with damaged stock of ours but NOT in the Excel -> taken to 0 (tick-box, ON by default)
//  TUR: damage lines carry the Excel TUR (or the item's system TUR when the Excel has none), so every damage has a value. Tick-box
//  "Update TUR prices": where the Excel TUR differs from our current TUR it becomes the new TUR (item_tur_overrides, source 'dmg');
//  the old TUR is kept and comes back when the sync (or a TUR line) is deleted.
//  Only DAMAGED stock changes (the MAIN GODOWN / Good stock is handled by Stock Sync). Lines are saved in
//  sampath_damage_import_items (outlet_code = 'DMGSYNC'), so the Sampath Inventory Report (Damaged Stock column, "-D" rows, damaged
//  ledger) and the Inventory Reconciliation Report pick them up. A whole sync or single lines can be deleted.
// ============================================================
include 'config.php';
include_once 'auth.php';
requireLogin();
require_once 'item_match_lib.php';     // im_load_masters(), im_create_missing_items()
if (session_status() === PHP_SESSION_NONE) session_start();
mysqli_report(MYSQLI_REPORT_OFF);
$who = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';

/* a half-uploaded deployment (new page + old item_match_lib.php) used to give an EMPTY response -> "Unexpected end of JSON input".
   Answer with a readable JSON message instead. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['ajax'])) {
    $__need = array_filter(['im_price_fill', 'im_create_missing_items', 'im_load_tur', 'im_load_masters'], function ($fn) { return !function_exists($fn); });
    if ($__need) { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'msg' => 'item_match_lib.php on the server is out of date (missing ' . implode(', ', $__need) . '). Upload the latest item_match_lib.php together with this page.']); exit; }
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => 'Server error: ' . $e['message'] . ' (line ' . $e['line'] . ')']);
        }
    });
}

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_damage_imports (
    id INT AUTO_INCREMENT PRIMARY KEY, damage_date DATE NOT NULL, remark VARCHAR(500) NULL, filename VARCHAR(255) NULL,
    total_rows INT NOT NULL DEFAULT 0, total_qty DECIMAL(14,2) NOT NULL DEFAULT 0, total_value DECIMAL(16,2) NOT NULL DEFAULT 0,
    created_by VARCHAR(100) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_date (damage_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_damage_import_items (
    id INT AUTO_INCREMENT PRIMARY KEY, import_id INT NOT NULL, damage_date DATE NOT NULL, bill_number VARCHAR(80) NULL, bill_date DATE NULL,
    delivery_date DATE NULL, outlet_code VARCHAR(100) NULL, outlet_name VARCHAR(255) NULL, salesperson_name VARCHAR(255) NULL,
    basepack_code VARCHAR(100) NULL, product_code VARCHAR(100) NULL, product_description VARCHAR(500) NULL, batch_code VARCHAR(100) NULL,
    tur DECIMAL(15,4) NULL, damage_qty DECIMAL(14,2) NOT NULL DEFAULT 0, damage_value DECIMAL(16,4) NOT NULL DEFAULT 0,
    INDEX idx_import (import_id), INDEX idx_prod (product_code), INDEX idx_dup (bill_number, product_code, batch_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
foreach (['adjustment_id' => 'INT NULL', 'reason' => 'VARCHAR(255) NULL', 'applied' => 'TINYINT(1) NOT NULL DEFAULT 1', 'system_qty' => 'DECIMAL(14,2) NULL', 'excel_qty' => 'DECIMAL(14,2) NULL'] as $col => $def) {
    $c = mysqli_query($conn, "SHOW COLUMNS FROM sampath_damage_import_items LIKE '$col'");
    if ($c && mysqli_num_rows($c) === 0) mysqli_query($conn, "ALTER TABLE sampath_damage_import_items ADD COLUMN $col $def");
}

function bds_json($a) { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

/** OUR Damaged Stock per item as at $date (inclusive) = confirmed Sampath Damage Entry lines + applied imported damages (as the Inventory Report). */
function bds_damaged($conn, $date) {
    $d = mysqli_real_escape_string($conn, $date); $out = [];
    $q = @mysqli_query($conn, "SELECT m.item_code, MAX(m.item_name) AS nm, SUM(i.qty) AS q FROM sampath_damage_item_map m
        JOIN sampath_damage_grn_items i ON i.id = m.line_id JOIN sampath_damage_grn_headers h ON h.id = i.grn_header_id
        WHERE h.grn_date <= '$d' GROUP BY m.item_code");
    while ($q && $r = mysqli_fetch_assoc($q)) { $c = trim($r['item_code']); $out[$c] = ['q' => (float)$r['q'], 'name' => (string)$r['nm']]; }
    $q = mysqli_query($conn, "SELECT product_code, MAX(product_description) AS nm, SUM(damage_qty) AS q FROM sampath_damage_import_items
        WHERE product_code <> '' AND damage_date <= '$d' AND applied = 1 GROUP BY product_code");
    while ($q && $r = mysqli_fetch_assoc($q)) { $c = trim($r['product_code']); if (!isset($out[$c])) $out[$c] = ['q' => 0.0, 'name' => (string)$r['nm']]; $out[$c]['q'] += (float)$r['q']; }
    foreach ($out as $c => &$o) $o['q'] = round($o['q'], 2);
    return $out;
}

/** Reads the LeverEDGE Current Stock Excel (1st sheet). Returns [rows, error]; rows = one entry per SKU7 (Units summed over batches). */
function bds_read($tmp) {
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) return [null, 'Cannot open the file. Please upload a valid .xlsx file.'];
    $wbx = $zip->getFromName('xl/workbook.xml'); $relx = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $targets = []; $details = null;
    libxml_use_internal_errors(true);
    $wb = $wbx ? simplexml_load_string($wbx) : null; $rx = $relx ? simplexml_load_string($relx) : null;
    $rels = [];
    if ($rx) foreach ($rx->Relationship as $rel) { $t = (string)$rel['Target']; $rels[(string)$rel['Id']] = ltrim($t !== '' && $t[0] === '/' ? substr($t, 1) : 'xl/' . $t, '/'); }
    if ($wb) foreach ($wb->sheets->sheet as $sh) {
        $rid = (string)$sh->attributes('r', true)['id']; $tg = $rels[$rid] ?? null; $targets[] = $tg;
        if ($details === null && $tg && stripos((string)$sh['name'], 'Details') !== false) $details = $tg;
    }
    $sheet = $targets[0] ?? null;      // 1st tab (Stock Adjustments)
    if (!$sheet) { $zip->close(); return [null, 'No sheet was found in this Excel.']; }
    $sst = $zip->getFromName('xl/sharedStrings.xml'); $sty = $zip->getFromName('xl/styles.xml'); $sx = $zip->getFromName($sheet);
    $zip->close();
    if (!$sx) return [null, 'Cannot read the sheet of this Excel.'];

    $shared = [];
    if ($sst && ($x = simplexml_load_string($sst))) foreach ($x->si as $si) {
        $t = '';
        if (isset($si->t)) $t = (string)$si->t; elseif (isset($si->r)) foreach ($si->r as $r) if (isset($r->t)) $t .= (string)$r->t;
        $shared[] = $t;
    }
    $date_ids = [];
    if ($sty && ($x = simplexml_load_string($sty)) && isset($x->cellXfs)) {
        $builtin = [14,15,16,17,18,19,20,21,22,45,46,47]; $custom = [];
        if (isset($x->numFmts)) foreach ($x->numFmts->numFmt as $nf) { $f = strtolower((string)$nf['formatCode']); if (strpos($f, 'y') !== false || strpos($f, 'd') !== false) $custom[] = (int)$nf['numFmtId']; }
        $i = 0; foreach ($x->cellXfs->xf as $xf) { $n = (int)$xf['numFmtId']; if (in_array($n, $builtin) || in_array($n, $custom)) $date_ids[] = $i; $i++; }
    }
    $sd = simplexml_load_string($sx);
    if (!$sd) return [null, 'Failed to read the sheet data.'];
    $colidx = function ($l) { $n = 0; for ($i = 0; $i < strlen($l); $i++) $n = $n * 26 + (ord($l[$i]) - 64); return $n - 1; };
    $matrix = [];
    foreach ($sd->sheetData->row as $row) {
        $rn = (int)$row['r']; $matrix[$rn] = [];
        foreach ($row->c as $c) {
            if (!preg_match('/^([A-Z]+)(\d+)$/', (string)$c['r'], $m)) continue;
            $t = (string)$c['t']; $si = isset($c['s']) ? (int)$c['s'] : -1; $raw = isset($c->v) ? (string)$c->v : '';
            if ($t === 'inlineStr') { $v = isset($c->is->t) ? (string)$c->is->t : ''; if ($v === '' && isset($c->is->r)) foreach ($c->is->r as $r) $v .= (string)$r->t; }
            elseif ($t === 's') $v = $shared[(int)$raw] ?? '';
            elseif (in_array($si, $date_ids) && is_numeric($raw) && $raw > 0) $v = date('Y-m-d', (int)(((float)$raw - 25569) * 86400));
            else $v = $raw;
            $matrix[$rn][$colidx($m[1])] = $v;
        }
    }
    $hr = null; $map = [];
    foreach ($matrix as $rn => $row) {
        foreach ($row as $v) if (strtolower(trim((string)$v)) === 'sku7') { $hr = $rn; break 2; }
    }
    if ($hr === null) return [null, 'Column "SKU7" was not found. Please upload the LeverEDGE Current Stock Report Excel.', null];
    foreach ($matrix[$hr] as $i => $h) $map[strtolower(trim((string)$h))] = $i;
    if (!isset($map['units'])) return [null, 'Column "Units" was not found in the header row.', null];
    $g = function ($row, $name) use ($map) { return isset($map[$name]) ? ($row[$map[$name]] ?? null) : null; };
    /* report date: the "Date:" cell in the header block */
    $fdate = null;
    foreach ($matrix as $rn => $row) {
        if ($rn >= $hr) break;
        foreach ($row as $ci => $v) if (trim((string)$v) === 'Date:' && isset($row[$ci + 1])) {
            $x = trim((string)$row[$ci + 1]);
            if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $x, $m)) $fdate = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            elseif (preg_match('/^\d{4}-\d{2}-\d{2}/', $x)) $fdate = substr($x, 0, 10);
        }
    }
    $floc = '';
    foreach ($matrix as $rn => $row) {
        if ($rn >= $hr) break;
        foreach ($row as $ci => $v) if (trim((string)$v) === 'Location:' && isset($row[$ci + 1])) $floc = trim((string)$row[$ci + 1]);
    }
    $by = [];
    foreach ($matrix as $rn => $row) {
        if ($rn <= $hr) continue;
        $sku = trim((string)$g($row, 'sku7'));
        if ($sku === '' || !ctype_digit($sku)) continue;                  // skips the Grand Total row
        $u = str_replace(',', '', trim((string)$g($row, 'units')));
        $u = is_numeric($u) ? (float)$u : 0.0;
        $tur = str_replace(',', '', trim((string)$g($row, 'tur')));
        if (!isset($by[$sku])) $by[$sku] = ['sku_code' => $sku, 'basepack_code' => trim((string)$g($row, 'basepack code')), 'item_name' => trim((string)$g($row, 'product name')), 'units' => 0.0, 'tur' => is_numeric($tur) ? (float)$tur : 0.0];
        $by[$sku]['units'] += $u;
    }
    return [array_values($by), '', $fdate, $floc];
}

/** Compare the Excel damage stock with ours as at $date */
function bds_compare($conn, $rows, $date, $include_missing) {
    $turmap = im_load_tur($conn);
    $masters = [];
    foreach (im_load_masters($conn) as $m) $masters[(string)$m['code']] = $m['name'];
    $our = bds_damaged($conn, $date);
    $cmp = []; $seen = [];
    foreach ($rows as $r) {
        $c = $r['sku_code']; $seen[$c] = 1;
        $sys = isset($our[$c]) ? $our[$c]['q'] : 0.0;
        $cmp[] = ['sku' => $c, 'name' => $r['item_name'], 'basepack' => $r['basepack_code'], 'excel' => $r['units'], 'system' => $sys, 'diff' => round($r['units'] - $sys, 2),
                  'tur' => im_price_fill($r['tur'], $turmap, $c), 'tur_old' => (float)($turmap[$c] ?? 0), 'tur_new' => (float)$r['tur'],
                  'tur_changed' => $r['tur'] > 0 && abs($r['tur'] - (float)($turmap[$c] ?? 0)) > 0.004, 'new_item' => !isset($masters[$c]), 'missing' => false];
    }
    if ($include_missing) foreach ($our as $c => $o) {
        if (isset($seen[$c]) || round($o['q'], 2) == 0) continue;
        $cmp[] = ['sku' => $c, 'name' => $o['name'] ?: ($masters[$c] ?? ''), 'basepack' => '', 'excel' => 0.0, 'system' => $o['q'], 'diff' => round(-$o['q'], 2), 'tur' => (float)($turmap[$c] ?? 0), 'tur_old' => (float)($turmap[$c] ?? 0), 'tur_new' => 0.0, 'tur_changed' => false, 'new_item' => false, 'missing' => true];
    }
    return $cmp;
}

/* ── JSON endpoints ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    if ($act === 'preview' || $act === 'import') {
        if (!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) bds_json(['ok' => false, 'msg' => 'File upload failed. Please try again.']);
        $f = $_FILES['xlsx_file'];
        if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') bds_json(['ok' => false, 'msg' => 'Please upload an .xlsx file.']);
        $res_ = bds_read($f['tmp_name']); $rows = $res_[0]; $err = $res_[1] ?? ''; $fdate = $res_[2] ?? null; $floc = $res_[3] ?? '';
        if ($rows === null) bds_json(['ok' => false, 'msg' => $err]);
        if (!$rows) bds_json(['ok' => false, 'msg' => 'No stock lines were found in this Excel.']);
        $date = trim($_POST['adj_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) $date = $fdate ?: date('Y-m-d');
        $inc_missing = !empty($_POST['include_missing']) || $act === 'preview' && ($_POST['include_missing'] ?? '1') !== '0';
        $cmp = bds_compare($conn, $rows, $date, $inc_missing);

        if ($act === 'preview') {
            $upd = !empty($_POST['update_tur']);
            $in = $out = 0; $same = 0; $new = 0; $miss = 0; $list = []; $turc = 0;
            foreach ($cmp as $c) {
                if ($c['tur_changed']) $turc++;
                if (round($c['diff'], 2) == 0 && !($upd && $c['tur_changed'])) { $same++; continue; }
                if ($c['diff'] > 0) $in += $c['diff']; else $out += -$c['diff'];
                if ($c['new_item']) $new++;
                if ($c['missing']) $miss++;
                $list[] = $c;
            }
            usort($list, function ($a, $b) { return strcmp($a['sku'], $b['sku']); });
            bds_json(['ok' => true, 'date' => $date, 'file_loc' => $floc, 'excel_items' => count($rows), 'excel_units' => array_sum(array_column($rows, 'units')), 'same' => $same,
                      'new' => $new, 'missing' => $miss, 'in' => $in, 'out' => $out, 'tur_changes' => $turc, 'rows' => $list]);
        }
        $reason = trim($_POST['reason'] ?? '');
        $pick = json_decode($_POST['skus'] ?? '[]', true); $pick = is_array($pick) ? array_flip(array_map('strval', $pick)) : [];
        $todo = [];
        $upd = !empty($_POST['update_tur']);
        foreach ($cmp as $c) if (isset($pick[$c['sku']]) && (round($c['diff'], 2) != 0 || ($upd && $c['tur_changed']))) $todo[] = $c;
        if (!$todo) bds_json(['ok' => false, 'msg' => 'Nothing to post — the damaged stock (and TUR) already tally, or no line is selected.']);
        $new_items = [];
        foreach ($todo as $c) if ($c['new_item']) $new_items[$c['sku']] = ['name' => $c['name'], 'basepack' => $c['basepack']];
        $created = im_create_missing_items($conn, $new_items, $who);
        $name = mb_substr($f['name'], 0, 255);
        $dl = array_values(array_filter($todo, function ($c) { return round($c['diff'], 2) != 0; }));      // lines that change the damaged stock
        $n = count($dl);
        $tq = 0; $tv = 0; foreach ($dl as $c) { $tq += $c['diff']; $tv += $c['diff'] * $c['tur']; }
        $st = mysqli_prepare($conn, "INSERT INTO sampath_damage_imports (damage_date, remark, filename, total_rows, total_qty, total_value, created_by) VALUES (?,?,?,?,?,?,?)");
        mysqli_stmt_bind_param($st, 'sssidds', $date, $reason, $name, $n, $tq, $tv, $who); mysqli_stmt_execute($st);
        $iid = mysqli_insert_id($conn);
        $ii = mysqli_prepare($conn, "INSERT INTO sampath_damage_import_items (import_id, damage_date, bill_number, bill_date, delivery_date, outlet_code, outlet_name, basepack_code, product_code, product_description, batch_code, tur, damage_qty, damage_value, reason, applied, system_qty, excel_qty) VALUES (?,?,?,?,?,'DMGSYNC','Damage Stock Sync',?,?,?,'',?,?,?,?,1,?,?)");
        $ref = 'SYNC-' . $iid;
        $to = mysqli_prepare($conn, "INSERT INTO item_tur_overrides (item_code, item_name, tur, old_tur, import_id, created_by, source) VALUES (?,?,?,?,?,?,'dmg')");
        $turn = 0;
        foreach ($todo as $c) {
            if ($upd && $c['tur_changed']) { mysqli_stmt_bind_param($to, 'ssddis', $c['sku'], $c['name'], $c['tur_new'], $c['tur_old'], $iid, $who); mysqli_stmt_execute($to); $turn++; }
            if (round($c['diff'], 2) == 0) continue;          // TUR-only row: no damage line
            $d = $c['diff']; $val = $d * $c['tur'];
            $rs = mb_substr('Damage stock sync · ours ' . round($c['system'], 2) . ' → Excel ' . round($c['excel'], 2) . ($c['new_item'] ? ' · new item' : '') . ($c['missing'] ? ' · not in Excel' : '') . ($reason !== '' ? ' · ' . $reason : ''), 0, 255);
            mysqli_stmt_bind_param($ii, 'isssssssdddsdd', $iid, $date, $ref, $date, $date, $c['basepack'], $c['sku'], $c['name'], $c['tur'], $d, $val, $rs, $c['system'], $c['excel']);
            mysqli_stmt_execute($ii);
        }
        bds_json(['ok' => true, 'id' => $iid, 'rows' => $n, 'tur' => $turn, 'created' => $created]);
    }
    if ($act === 'delete_import') {
        $id = (int)($_POST['id'] ?? 0);
        mysqli_query($conn, "DELETE FROM sampath_damage_import_items WHERE import_id=$id AND outlet_code='DMGSYNC'");
        mysqli_query($conn, "DELETE FROM item_tur_overrides WHERE import_id=$id AND source='dmg'");      // TUR goes back to the old price
        mysqli_query($conn, "DELETE FROM sampath_damage_imports WHERE id=$id");
        bds_json(['ok' => true]);
    }
    if ($act === 'delete_items') {
        $id = (int)($_POST['id'] ?? 0);
        $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
        if (!$ids) bds_json(['ok' => false, 'msg' => 'Nothing selected.']);
        $inl = implode(',', $ids);
        mysqli_query($conn, "DELETE o FROM item_tur_overrides o JOIN sampath_damage_import_items x ON x.product_code = o.item_code AND x.import_id = o.import_id WHERE o.source='dmg' AND x.import_id=$id AND x.outlet_code='DMGSYNC' AND x.id IN ($inl)");
        mysqli_query($conn, "DELETE FROM sampath_damage_import_items WHERE import_id=$id AND outlet_code='DMGSYNC' AND id IN ($inl)");
        $t = mysqli_fetch_assoc(mysqli_query($conn, "SELECT (SELECT COUNT(*) FROM item_tur_overrides WHERE import_id=$id AND source='dmg') AS tc, COUNT(*) c, COALESCE(SUM(damage_qty),0) q, COALESCE(SUM(damage_value),0) v FROM sampath_damage_import_items WHERE import_id=$id"));
        if ((int)$t['c'] === 0 && (int)$t['tc'] === 0) mysqli_query($conn, "DELETE FROM sampath_damage_imports WHERE id=$id");
        else mysqli_query($conn, "UPDATE sampath_damage_imports SET total_rows=" . (int)$t['c'] . ", total_qty=" . (float)$t['q'] . ", total_value=" . (float)$t['v'] . " WHERE id=$id");
        bds_json(['ok' => true]);
    }
    if ($act === 'revert_tur') {
        $oid = (int)($_POST['id'] ?? 0); $iid = (int)($_POST['import_id'] ?? 0);
        mysqli_query($conn, "DELETE FROM item_tur_overrides WHERE id=$oid AND import_id=$iid AND source='dmg'");
        bds_json(['ok' => true]);
    }
    bds_json(['ok' => false, 'msg' => 'Unknown action.']);
}
if (($_GET['ajax'] ?? '') === 'items') {
    $id = (int)($_GET['id'] ?? 0); $items = [];
    $q = mysqli_query($conn, "SELECT * FROM sampath_damage_import_items WHERE import_id=$id AND outlet_code='DMGSYNC' ORDER BY product_code");
    if ($q) while ($r = mysqli_fetch_assoc($q)) $items[] = $r;
    $turs = [];
    $q = mysqli_query($conn, "SELECT id, item_code, item_name, tur, old_tur FROM item_tur_overrides WHERE import_id=$id AND source='dmg' ORDER BY item_code");
    if ($q) while ($r = mysqli_fetch_assoc($q)) $turs[] = $r;
    bds_json(['ok' => true, 'items' => $items, 'turs' => $turs]);
}

$imports = [];
$q = mysqli_query($conn, "SELECT i.*, COALESCE((SELECT SUM(CASE WHEN x.damage_qty > 0 THEN x.damage_qty ELSE 0 END) FROM sampath_damage_import_items x WHERE x.import_id = i.id),0) AS q_in,
        COALESCE((SELECT SUM(CASE WHEN x.damage_qty < 0 THEN -x.damage_qty ELSE 0 END) FROM sampath_damage_import_items x WHERE x.import_id = i.id),0) AS q_out
    , (SELECT COUNT(*) FROM item_tur_overrides t WHERE t.import_id = i.id AND t.source='dmg') AS n_tur
    FROM sampath_damage_imports i WHERE EXISTS (SELECT 1 FROM sampath_damage_import_items y WHERE y.import_id = i.id AND y.outlet_code='DMGSYNC')
       OR EXISTS (SELECT 1 FROM item_tur_overrides t2 WHERE t2.import_id = i.id AND t2.source='dmg') ORDER BY i.damage_date DESC, i.id DESC");
if ($q) while ($r = mysqli_fetch_assoc($q)) $imports[] = $r;
$sum = ['n' => count($imports), 'rows' => 0, 'in' => 0, 'out' => 0];
foreach ($imports as $r) { $sum['rows'] += $r['total_rows']; $sum['in'] += $r['q_in']; $sum['out'] += $r['q_out']; }
include 'header.php';
?>
<style>
.idm{padding:0 4px;} .idm h2{font-size:22px;font-weight:800;margin:0 0 4px;} .idm .sub{font-size:12px;color:#8892a4;margin-bottom:14px;}
.idm-card{background:#fff;border:1.5px solid #e2e6f0;border-radius:14px;padding:16px 18px;margin-bottom:16px;}
.idm-kp{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px;}
.idm-kp div{background:#fff;border:1.5px solid #e2e6f0;border-radius:12px;padding:10px 14px;} .idm-kp small{display:block;font-size:10.5px;color:#6b7280;text-transform:uppercase;font-weight:700;} .idm-kp b{font-size:18px;font-variant-numeric:tabular-nums;}
.idm-drop{border:2px dashed #c7cde0;border-radius:12px;background:#fafbff;padding:26px;text-align:center;cursor:pointer;color:#64748b;}
.idm-drop.has{border-color:#16a34a;background:#f0fdf4;color:#166534;} .idm-drop i{font-size:26px;display:block;margin-bottom:6px;}
.idm-b{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;background:#0a0c14;color:#fff;text-decoration:none;}
.idm-b.g{background:#14532d;} .idm-b.r{background:#b91c1c;} .idm-b.l{background:#f7f8fc;color:#475569;border:1.5px solid #e2e6f0;} .idm-b.s{padding:5px 10px;font-size:11.5px;} .idm-b[disabled]{opacity:.5;cursor:not-allowed;}
.idm-wrap{overflow:auto;border:1.5px solid #e2e6f0;border-radius:12px;}
table.idm-t{width:100%;border-collapse:collapse;font-size:12.5px;}
table.idm-t th{background:#0a0c14;color:#fff;font-size:10.5px;text-transform:uppercase;padding:8px 10px;text-align:left;white-space:nowrap;position:sticky;top:0;}
table.idm-t td{padding:6px 10px;border-bottom:1px solid #eef0f6;white-space:nowrap;} table.idm-t .n{text-align:right;font-variant-numeric:tabular-nums;}
.pos{color:#15803d;font-weight:800;} .negq{color:#dc2626;font-weight:800;}
.idm-in{border:1.5px solid #e2e6f0;border-radius:8px;padding:8px 10px;font-size:13px;width:100%;box-sizing:border-box;}
.idm-modal{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;z-index:9999;padding:16px;} .idm-modal.open{display:flex;}
.idm-mb{background:#fff;border-radius:12px;width:100%;max-width:520px;padding:20px;box-shadow:0 20px 50px rgba(0,0,0,.25);max-height:92vh;overflow:auto;}
.idm-mb h3{margin:0 0 12px;font-size:16px;} .idm-mb .fld{margin-bottom:12px;} .idm-mb .fld label{display:block;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:4px;}
.idm-msg{border-radius:8px;padding:10px 14px;font-size:13px;margin-top:10px;display:none;} .idm-msg.err{display:block;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;} .idm-msg.ok{display:block;background:#dcfce7;border:1px solid #86efac;color:#166534;}
</style>
<div class="idm">
  <h2><i class="fa-solid fa-scale-balanced"></i> Damage Stock Sync — Current Stock Excel</h2>
  <div class="sub">Upload the LeverEDGE <b>Current Stock Report of the DAMAGE GODOWN</b>. Units are summed per SKU7 and compared with <b>our Damaged Stock</b> as at the date; the <b>difference is imported as damage stock</b> so our Damaged Stock becomes the <b>same units as the Excel</b>. A SKU that is not in our system is <b>created</b>. It shows in the Sampath Inventory Report (Damaged Stock) and the Inventory Reconciliation Report.</div>

  <div class="idm-kp">
    <div><small>Syncs</small><b><?php echo number_format($sum['n']); ?></b></div>
    <div><small>Damage lines</small><b><?php echo number_format($sum['rows']); ?></b></div>
    <div><small>Damage units added (+)</small><b class="pos">+<?php echo number_format($sum['in'], 0); ?></b></div>
    <div><small>Damage units removed (−)</small><b class="negq">−<?php echo number_format($sum['out'], 0); ?></b></div>
  </div>

  <div class="idm-card">
    <div class="idm-drop" id="idmDrop" onclick="document.getElementById('idmFile').click()">
      <i class="fa-solid fa-cloud-arrow-up"></i><div id="idmDropTxt">Drop the DAMAGE GODOWN Current Stock Excel here or click to choose (.xlsx)</div>
    </div>
    <input type="file" id="idmFile" accept=".xlsx" style="display:none">
    <div class="idm-msg" id="idmMsg"></div>
  </div>

  <h3 style="font-size:15px;margin:6px 0 8px;">Imported damage stock syncs</h3>
  <div class="idm-wrap">
  <table class="idm-t">
    <thead><tr><th>#</th><th>As at / damage date</th><th>Reason / remark</th><th>File</th><th class="n">Items</th><th class="n">Units +</th><th class="n">Units −</th><th class="n">TUR updated</th><th>Imported by</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($imports as $r): $id = (int)$r['id']; ?>
      <tr>
        <td>#<?php echo $id; ?></td><td><b><?php echo date('d/m/Y', strtotime($r['damage_date'])); ?></b></td>
        <td style="white-space:normal;max-width:260px;"><?php echo htmlspecialchars($r['remark'] ?: '—'); ?></td>
        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['filename']); ?>"><?php echo htmlspecialchars($r['filename']); ?></td>
        <td class="n"><?php echo (int)$r['total_rows']; ?></td><td class="n pos">+<?php echo number_format($r['q_in'], 0); ?></td><td class="n negq">−<?php echo number_format($r['q_out'], 0); ?></td>
        <td class="n"><?php echo (int)$r['n_tur'] > 0 ? '<span style="color:#6d28d9;font-weight:700;">' . (int)$r['n_tur'] . '</span>' : '—'; ?></td>
        <td><?php echo htmlspecialchars($r['created_by'] ?? ''); ?><div style="font-size:10.5px;color:#94a3b8;"><?php echo htmlspecialchars(substr((string)$r['created_at'], 0, 16)); ?></div></td>
        <td style="white-space:nowrap;">
          <button class="idm-b l s" onclick="idmView(<?php echo $id; ?>)"><i class="fa-solid fa-eye"></i> View</button>
          <button class="idm-b r s" onclick="idmDel(<?php echo $id; ?>)"><i class="fa-solid fa-trash"></i> Delete</button>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$imports): ?><tr><td colspan="10" style="text-align:center;padding:30px;color:#94a3b8;">Nothing imported yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="idm-modal" id="idmModal" onclick="if(event.target===this)idmClose('idmModal')">
  <div class="idm-mb" style="max-width:980px;">
    <h3><i class="fa-solid fa-scale-balanced"></i> Tally with our Damaged Stock</h3>
    <div id="idmSummary" style="font-size:13px;color:#334155;background:#f8fafc;border:1px solid #e2e6f0;border-radius:8px;padding:10px 12px;margin-bottom:12px;"></div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px;">
      <div class="fld" style="margin:0"><label>As at / damage date</label><input class="idm-in" type="date" id="idmDate" onchange="idmPreview()" style="width:170px"></div>
      <div class="fld" style="margin:0;flex:1;min-width:220px"><label>Reason / remark</label><input class="idm-in" type="text" id="idmReason" maxlength="400" placeholder="e.g. Damage godown stock sync with LeverEDGE"></div>
    </div>
    <label style="display:flex;gap:8px;align-items:flex-start;font-size:13px;margin-bottom:8px;background:#faf5ff;border:1px solid #d8b4fe;border-radius:8px;padding:8px 10px;"><input type="checkbox" id="idmTur" checked onchange="idmPreview()" style="margin-top:3px;"> <span><b>Update TUR prices</b> from this Excel where the TUR has changed. The old TUR is kept — deleting this sync (or a TUR line) puts it back.</span></label>
    <label style="display:flex;gap:8px;font-size:13px;margin-bottom:8px;"><input type="checkbox" id="idmMissing" checked onchange="idmPreview()"> <span>Also take our damaged items that are <b>not in the Excel</b> to 0</span></label>
    <div class="idm-wrap" style="max-height:44vh;"><table class="idm-t"><thead><tr><th><input type="checkbox" id="idmAll" checked onclick="document.querySelectorAll('.idm-pc').forEach(c=>c.checked=this.checked);idmCount()"></th><th>SKU7</th><th>Item</th><th class="n">Our damaged stock</th><th class="n">Excel units</th><th class="n">Difference (imported)</th><th class="n">TUR old → new</th><th>Note</th></tr></thead><tbody id="idmPB"></tbody></table></div>
    <div class="idm-msg" id="idmMMsg"></div>
    <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center;margin-top:12px;">
      <span id="idmSel" style="margin-right:auto;font-size:12.5px;color:#475569;"></span>
      <button class="idm-b l" onclick="idmClose('idmModal')">Cancel</button>
      <button class="idm-b g" id="idmGo" onclick="idmImport()"><i class="fa-solid fa-scale-balanced"></i> Import damage stock</button>
    </div>
  </div>
</div>

<div class="idm-modal" id="idmView" onclick="if(event.target===this)idmClose('idmView')">
  <div class="idm-mb" style="max-width:1100px;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;"><h3 style="margin:0;" id="idmVT">Damage lines</h3>
      <button class="idm-b r s" style="margin-left:auto;" onclick="idmDelLines()"><i class="fa-solid fa-trash"></i> Delete selected</button>
      <button class="idm-b l s" onclick="idmClose('idmView')">Close</button></div>
    <div class="idm-wrap" style="max-height:62vh;"><table class="idm-t"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.idm-ic').forEach(c=>c.checked=this.checked)"></th><th>SKU7</th><th>Item</th><th class="n">Our damaged (then)</th><th class="n">Excel units</th><th class="n">Imported</th><th class="n">TUR</th><th class="n">Value</th></tr></thead><tbody id="idmVB"></tbody></table></div>
    <div id="idmTurBox" style="display:none;margin-top:12px;"><div style="font-weight:800;font-size:13px;margin-bottom:6px;color:#6d28d9;"><i class="fa-solid fa-tags"></i> TUR price updates (old TUR comes back when reverted)</div>
      <div class="idm-wrap" style="max-height:26vh;"><table class="idm-t"><thead><tr><th>SKU7</th><th>Item</th><th class="n">Old TUR</th><th class="n">New TUR</th><th></th></tr></thead><tbody id="idmTB"></tbody></table></div></div>
  </div>
</div>

<script>
const $i = id => document.getElementById(id);
const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmt = (n, d) => (+n || 0).toLocaleString('en-US', {minimumFractionDigits:d || 0, maximumFractionDigits:d || 0});
let idmFile = null, idmViewId = 0;
function idmClose(id){ $i(id).classList.remove('open'); }
function idmMsg(el, cls, txt){ el.className = 'idm-msg ' + cls; el.textContent = txt; }
$i('idmDrop').addEventListener('dragover', e => e.preventDefault());
$i('idmDrop').addEventListener('drop', e => { e.preventDefault(); if (e.dataTransfer.files[0]) idmPick(e.dataTransfer.files[0]); });
$i('idmFile').addEventListener('change', e => { if (e.target.files[0]) idmPick(e.target.files[0]); });
function idmPick(f){
    if (!/\.xlsx$/i.test(f.name)) { idmMsg($i('idmMsg'), 'err', 'Please choose an .xlsx file.'); return; }
    idmFile = f; $i('idmDrop').classList.add('has'); $i('idmDropTxt').textContent = f.name + ' — reading and tallying…'; $i('idmDate').value = '';
    idmPreview(true);
}
function idmPreview(first){
    const fd_ = new FormData(); fd_.append('action', 'preview'); fd_.append('xlsx_file', idmFile); fd_.append('adj_date', $i('idmDate').value); fd_.append('include_missing', $i('idmMissing').checked ? '1' : '0'); if ($i('idmTur').checked) fd_.append('update_tur', '1');
    $i('idmPB').innerHTML = '<tr><td colspan="8" style="padding:16px;color:#94a3b8;">Tallying…</td></tr>';
    if (!first) $i('idmGo').disabled = true;
    fetch('bulk_damage_import2.php', {method:'POST', body:fd_, credentials:'same-origin'}).then(r => r.json()).then(d => {
        $i('idmDropTxt').textContent = idmFile.name;
        if (!d.ok) { if (first) idmMsg($i('idmMsg'), 'err', d.msg); else idmMsg($i('idmMMsg'), 'err', d.msg); return; }
        $i('idmMsg').className = 'idm-msg'; $i('idmMMsg').className = 'idm-msg';
        $i('idmDate').value = d.date;
        $i('idmSummary').innerHTML = (d.file_loc && !/damage/i.test(d.file_loc) ? '<div style="color:#991b1b;font-weight:700;margin-bottom:4px;">⚠ This Excel is for location "' + esc(d.file_loc) + '", not the DAMAGE GODOWN. Use Stock Sync for the main godown.</div>' : '')
            + '<b>' + d.excel_items + '</b> SKU(s) in the Excel · <b>' + fmt(d.excel_units) + '</b> damaged units · <b>' + d.same + '</b> already tally'
            + '<br><b>' + d.rows.length + '</b> difference(s): <span class="pos">+' + fmt(d.in) + ' units to add</span> · <span class="negq">−' + fmt(d.out) + ' units to remove</span>'
            + (d.tur_changes ? '<br><span style="color:#6d28d9;"><b>' + d.tur_changes + '</b> item(s) have a changed TUR' + ($i('idmTur').checked ? ' — will be updated.' : ' — not updated (tick the option).') + '</span>' : '')
            + (d.new ? '<br><span style="color:#991b1b;"><b>' + d.new + '</b> SKU(s) are not in our system and will be <b>created as new items</b>.</span>' : '')
            + (d.missing ? '<br><span style="color:#92400e;"><b>' + d.missing + '</b> of our damaged items are not in the Excel and will be taken to 0.</span>' : '');
        $i('idmPB').innerHTML = d.rows.map(r => '<tr><td><input type="checkbox" class="idm-pc" value="' + esc(r.sku) + '" data-d="' + r.diff + '" checked onchange="idmCount()"></td><td><b>' + esc(r.sku) + '</b></td><td>' + esc(r.name) + '</td><td class="n">' + fmt(r.system, 2) + '</td><td class="n">' + fmt(r.excel, 0) + '</td><td class="n ' + (r.diff < 0 ? 'negq' : 'pos') + '">' + (r.diff < 0 ? '−' : '+') + fmt(Math.abs(r.diff), 2) + '</td><td class="n">' + (r.tur_changed ? '<span style="color:#6d28d9;font-weight:700;">' + (r.tur_old ? fmt(r.tur_old, 2) : '—') + ' → ' + fmt(r.tur_new, 2) + '</span>' : '<span style="color:#94a3b8">' + (r.tur ? fmt(r.tur, 2) : '<span style="color:#991b1b">no TUR</span>') + '</span>') + '</td><td>' + (r.new_item ? '<span style="color:#991b1b;font-weight:700;">new item</span>' : (r.missing ? '<span style="color:#92400e;font-weight:700;">not in Excel</span>' : (+r.diff === 0 ? '<span style="color:#6d28d9;font-weight:700;">TUR only</span>' : ''))) + '</td></tr>').join('') || '<tr><td colspan="8" style="padding:18px;color:#166534;font-weight:700;">Everything already tallies — nothing to import or update.</td></tr>';
        $i('idmAll').checked = true; idmCount();
        $i('idmGo').innerHTML = '<i class="fa-solid fa-scale-balanced"></i> Import damage stock'; $i('idmModal').classList.add('open');
    }).catch(e => idmMsg($i('idmMsg'), 'err', 'Upload failed: ' + e.message));
}
function idmCount(){
    const cs = Array.from(document.querySelectorAll('.idm-pc:checked'));
    let a = 0, b = 0; cs.forEach(c => { const v = +c.dataset.d; if (v > 0) a += v; else b -= v; });
    $i('idmSel').innerHTML = '<b>' + cs.length + '</b> selected · <span class="pos">+' + fmt(a, 2) + '</span> · <span class="negq">−' + fmt(b, 2) + '</span>';
    $i('idmGo').disabled = cs.length === 0;
}
function idmImport(){
    const skus = Array.from(document.querySelectorAll('.idm-pc:checked')).map(c => c.value);
    if (!skus.length) return;
    if (!confirm('Import ' + skus.length + ' damage line(s) so our Damaged Stock matches the Excel?')) return;
    $i('idmGo').disabled = true; $i('idmGo').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Importing…';
    const f = new FormData(); f.append('action', 'import'); f.append('xlsx_file', idmFile); f.append('adj_date', $i('idmDate').value); f.append('reason', $i('idmReason').value);
    f.append('skus', JSON.stringify(skus)); if ($i('idmMissing').checked) f.append('include_missing', '1'); if ($i('idmTur').checked) f.append('update_tur', '1');
    fetch('bulk_damage_import2.php', {method:'POST', body:f, credentials:'same-origin'}).then(r => r.json()).then(d => {
        if (!d.ok) { idmMsg($i('idmMMsg'), 'err', d.msg); $i('idmGo').disabled = false; $i('idmGo').innerHTML = '<i class="fa-solid fa-scale-balanced"></i> Import damage stock'; return; }
        idmMsg($i('idmMMsg'), 'ok', d.rows + ' damage line(s) imported' + (d.tur ? ', ' + d.tur + ' TUR price(s) updated' : '') + (d.created ? ', ' + d.created + ' new item(s) created' : '') + '.'); setTimeout(() => location.reload(), 800);
    }).catch(e => { idmMsg($i('idmMMsg'), 'err', 'Import failed: ' + e.message); $i('idmGo').disabled = false; });
}
function idmPost(data){ const f = new FormData(); Object.keys(data).forEach(k => f.append(k, data[k])); return fetch('bulk_damage_import2.php', {method:'POST', body:f, credentials:'same-origin'}).then(r => r.json()); }
function idmDel(id){
    if (!confirm('Delete damage stock sync #' + id + '? Its damage lines are removed and the Damaged Stock goes back.')) return;
    idmPost({action:'delete_import', id:id}).then(d => { if (d.ok) location.reload(); else alert(d.msg); });
}
function idmView(id){
    idmViewId = id; $i('idmVT').textContent = 'Damage lines — sync #' + id; $i('idmVB').innerHTML = '<tr><td colspan="6" style="padding:18px;color:#94a3b8;">Loading…</td></tr>'; $i('idmView').classList.add('open');
    fetch('bulk_damage_import2.php?ajax=items&id=' + id, {credentials:'same-origin'}).then(r => r.json()).then(d => {
        $i('idmVB').innerHTML = d.items.map(i => { const s = +i.damage_qty; return '<tr><td><input type="checkbox" class="idm-ic" value="' + i.id + '"></td><td><b>' + esc(i.product_code) + '</b></td><td>' + esc(i.product_description) + '</td><td class="n">' + (i.system_qty == null ? '—' : fmt(i.system_qty, 2)) + '</td><td class="n">' + (i.excel_qty == null ? '—' : fmt(i.excel_qty, 0)) + '</td><td class="n ' + (s < 0 ? 'negq' : 'pos') + '">' + (s < 0 ? '−' : '+') + fmt(Math.abs(s), 2) + '</td><td class="n">' + (+i.tur ? fmt(i.tur, 2) : '<span style="color:#991b1b">no TUR</span>') + '</td><td class="n">' + (+i.damage_value ? fmt(i.damage_value, 2) : '—') + '</td></tr>'; }).join('') || '<tr><td colspan="8" style="padding:18px;color:#94a3b8;">No damage lines (TUR update only).</td></tr>';
        $i('idmTurBox').style.display = d.turs.length ? 'block' : 'none';
        $i('idmTB').innerHTML = d.turs.map(t => '<tr><td><b>' + esc(t.item_code) + '</b></td><td>' + esc(t.item_name) + '</td><td class="n">' + (t.old_tur == null || +t.old_tur === 0 ? '—' : fmt(t.old_tur, 2)) + '</td><td class="n" style="color:#6d28d9;font-weight:700;">' + fmt(t.tur, 2) + '</td><td><button class="idm-b l s" onclick="idmRevertTur(' + t.id + ')"><i class="fa-solid fa-rotate-left"></i> Revert to old</button></td></tr>').join('');
    });
}
function idmRevertTur(oid){
    if (!confirm('Put the old TUR back for this item?')) return;
    idmPost({action:'revert_tur', id:oid, import_id:idmViewId}).then(d => { if (d.ok) idmView(idmViewId); else alert(d.msg); });
}
function idmDelLines(){
    const ids = Array.from(document.querySelectorAll('.idm-ic:checked')).map(c => c.value);
    if (!ids.length) { alert('Tick the lines to delete.'); return; }
    if (!confirm('Delete ' + ids.length + ' line(s)?')) return;
    idmPost({action:'delete_items', id:idmViewId, ids:ids.join(',')}).then(d => { if (d.ok) location.reload(); else alert(d.msg); });
}
</script>
