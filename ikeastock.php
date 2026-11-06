<?php
// ============================================================
//  ikeastock.php  –  IKEA vs YMS STOCK RECONCILIATION   (Sampath Operation)
//
//  Upload the IKEA (Sampath) stock Excel. It is reconciled, item by item, with OUR (YMS) Good stock closing balance as at the date
//  (same basis as the Sampath Inventory Report: Primary Purchase − Primary Returned − Secondary − Good Rejected + Adjustment).
//  Columns are found by their header names (SKU / item code, quantity, product name); the modal lets you pick the sheet, the header
//  row and the SKU / quantity / name columns if the automatic choice is wrong.
//
//  The result is shown in three separate lists:
//    1. MATCHED items  – in both: Tally / SHORT (YMS below IKEA) / EXCESS (YMS above IKEA) with the difference in units
//    2. NEW in IKEA    – SKU in the IKEA Excel but not in YMS (can be created as a new item and set to the IKEA qty)
//    3. ONLY in YMS    – items YMS holds stock of that are not in the IKEA Excel (not adjusted unless you tick them)
//  Tick the lines to adjust, enter the REASON (required) and post: one stock adjustment per line (Short = + units, Excess = − units,
//  New = + IKEA qty, Only in YMS = − YMS qty) so YMS equals IKEA. Saved in stock_manual_adjustments (visible on Item Adjustment and
//  in every stock report). A whole import or single lines can be deleted and the stock goes back.
//  The reconciliation can also be exported to Excel without adjusting anything.
// ============================================================
include 'config.php';
include_once 'auth.php';
requireLogin();
require_once 'item_match_lib.php';     // im_load_masters(), im_create_missing_items()
if (session_status() === PHP_SESSION_NONE) session_start();
mysqli_report(MYSQLI_REPORT_OFF);
$who = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['ajax'])) {
    $__need = array_filter(['im_create_missing_items', 'im_load_masters'], function ($fn) { return !function_exists($fn); });
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

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_manual_adjustments (
    id INT(11) AUTO_INCREMENT PRIMARY KEY, item_code VARCHAR(100) NOT NULL, entry_date DATE NOT NULL,
    rejected_qty DECIMAL(14,2) NOT NULL DEFAULT 0, adjustment_qty DECIMAL(14,2) NOT NULL DEFAULT 0, note VARCHAR(255) DEFAULT NULL,
    created_by VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_item_code (item_code), INDEX idx_entry_date (entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_adjustment_imports (
    id INT AUTO_INCREMENT PRIMARY KEY, adj_date DATE NOT NULL, reason VARCHAR(500) NULL, filename VARCHAR(255) NULL,
    created_by VARCHAR(100) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_date (adj_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_adjustment_import_lines (
    id INT AUTO_INCREMENT PRIMARY KEY, import_id INT NOT NULL, adjustment_id INT NOT NULL, sku_code VARCHAR(100) NOT NULL, basepack_code VARCHAR(100) NULL,
    item_name VARCHAR(500) NULL, transaction_type VARCHAR(100) NULL, txn_ref VARCHAR(100) NULL, txn_date DATE NULL, party_name VARCHAR(255) NULL,
    qty_units DECIMAL(14,2) NOT NULL DEFAULT 0, signed_qty DECIMAL(14,2) NOT NULL DEFAULT 0, tur DECIMAL(15,4) NULL, line_value DECIMAL(16,4) NULL,
    INDEX idx_import (import_id), INDEX idx_adj (adjustment_id), INDEX idx_dup (txn_ref, sku_code, transaction_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
foreach (['system_qty' => 'DECIMAL(14,2) NULL', 'excel_qty' => 'DECIMAL(14,2) NULL'] as $col => $def) {
    $c = mysqli_query($conn, "SHOW COLUMNS FROM stock_adjustment_import_lines LIKE '$col'");
    if ($c && mysqli_num_rows($c) === 0) mysqli_query($conn, "ALTER TABLE stock_adjustment_import_lines ADD COLUMN $col $def");
}

function bik_json($a) { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

/** Our GOOD stock closing balance per item as at $date (inclusive) — same formula as the Sampath Inventory Report. Returns [code => ['close'=>, 'name'=>]] */
function bik_closing($conn, $date) {
    $d = mysqli_real_escape_string($conn, $date);
    $upc = []; $name = [];
    $q = mysqli_query($conn, "SELECT p.item_code, p.item_name, p.upc FROM unilever_purchase_items p
        INNER JOIN (SELECT item_code, MAX(id) AS max_id FROM unilever_purchase_items WHERE item_code IS NOT NULL AND item_code <> '' GROUP BY item_code) l ON l.max_id = p.id");
    while ($q && $r = mysqli_fetch_assoc($q)) { $c = trim($r['item_code']); $upc[$c] = (float)preg_replace('/[^0-9.]/', '', (string)$r['upc']); $name[$c] = $r['item_name']; }
    $acc = [];
    $t = function ($c) use (&$acc) { if (!isset($acc[$c])) $acc[$c] = 0.0; };
    $q = mysqli_query($conn, "SELECT p.item_code, SUM(CASE WHEN p.purchase_qty_cases > 0 THEN p.purchase_qty_cases ELSE 0 END) AS q, SUM(CASE WHEN p.purchase_qty_cases < 0 THEN -p.purchase_qty_cases ELSE 0 END) AS rq
        FROM unilever_purchase_items p JOIN unilever_purchase_batches b ON b.id = p.batch_id
        WHERE p.item_code <> '' AND COALESCE(p.invoice_date, b.received_date) <= '$d' GROUP BY p.item_code");
    while ($q && $r = mysqli_fetch_assoc($q)) { $c = trim($r['item_code']); $t($c); $acc[$c] += ((float)$r['q'] - (float)$r['rq']) * ($upc[$c] ?? 0); }
    $q = @mysqli_query($conn, "SELECT ri.item_code, SUM(ri.qty) AS q FROM unilever_item_return_items ri JOIN unilever_item_returns r ON r.id = ri.return_id
        WHERE ri.item_code <> '' AND r.return_date <= '$d' GROUP BY ri.item_code");
    while ($q && $r = mysqli_fetch_assoc($q)) { $c = trim($r['item_code']); $t($c); $acc[$c] -= (float)$r['q'] * ($upc[$c] ?? 0); }
    $q = @mysqli_query($conn, "SELECT product_code AS item_code, MAX(product_description) AS nm, SUM(units) AS q FROM invoice_wise_sales_data
        WHERE product_code <> '' AND delivery_date <= '$d' GROUP BY product_code");
    while ($q && $r = mysqli_fetch_assoc($q)) { $c = trim($r['item_code']); $t($c); $acc[$c] -= (float)$r['q']; if (!isset($name[$c])) $name[$c] = $r['nm']; }
    $q = mysqli_query($conn, "SELECT item_code, SUM(rejected_qty) AS rq, SUM(adjustment_qty) AS aq FROM stock_manual_adjustments WHERE item_code <> '' AND entry_date <= '$d' GROUP BY item_code");
    while ($q && $r = mysqli_fetch_assoc($q)) { $c = trim($r['item_code']); $t($c); $acc[$c] += -(float)$r['rq'] + (float)$r['aq']; }
    $out = [];
    foreach ($acc as $c => $v) $out[$c] = ['close' => round($v, 2), 'name' => $name[$c] ?? ''];
    return $out;
}

/** Read one sheet of the xlsx into a matrix [row => [col => value]]. Returns [matrix|null, error, sheetNames] */
function bik_matrix($tmp, $sheet_idx) {
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) return [null, 'Cannot open the file. Please upload a valid .xlsx file.', []];
    $wbx = $zip->getFromName('xl/workbook.xml'); $relx = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $targets = []; $names = [];
    libxml_use_internal_errors(true);
    $wb = $wbx ? simplexml_load_string($wbx) : null; $rx = $relx ? simplexml_load_string($relx) : null;
    $rels = [];
    if ($rx) foreach ($rx->Relationship as $rel) { $t = (string)$rel['Target']; $rels[(string)$rel['Id']] = ltrim($t !== '' && $t[0] === '/' ? substr($t, 1) : 'xl/' . $t, '/'); }
    if ($wb) foreach ($wb->sheets->sheet as $sh) {
        $rid = (string)$sh->attributes('r', true)['id']; $tg = $rels[$rid] ?? null; $targets[] = $tg; $names[] = (string)$sh['name'];
    }
    $sheet = $targets[$sheet_idx] ?? ($targets[0] ?? null);
    if (!$sheet) { $zip->close(); return [null, 'No sheet was found in this Excel.', []]; }
    $sst = $zip->getFromName('xl/sharedStrings.xml'); $sty = $zip->getFromName('xl/styles.xml'); $sx = $zip->getFromName($sheet);
    $zip->close();
    if (!$sx) return [null, 'Cannot read the sheet of this Excel.', $names];

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
    if (!$sd) return [null, 'Failed to read the sheet data.', $names];
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
    return [$matrix, '', $names];
}

/** normalise a code: "3879002.0" -> "3879002", trims */
function bik_code($v) {
    $v = trim((string)$v);
    if ($v !== '' && is_numeric($v) && strpos($v, 'e') === false && strpos($v, 'E') === false && floor((float)$v) == (float)$v && (float)$v < 1e15) return (string)(int)(float)$v;
    return $v;
}

/** find the header row + sku / qty / name columns by header text */
function bik_detect($matrix) {
    $syn = [
        'sku'  => ['sku7', 'sku code', 'sku', 'item code', 'product code', 'material code', 'material', 'item no', 'itemcode', 'article', 'code'],
        'qty'  => ['stock qty', 'closing stock', 'closing qty', 'current stock', 'on hand', 'available qty', 'qty in units', 'qty', 'quantity', 'units', 'stock', 'balance', 'closing'],
        'name' => ['product name', 'item name', 'product description', 'description', 'item description', 'name'],
    ];
    $best = null; $n = 0;
    foreach ($matrix as $rn => $row) {
        if (++$n > 80) break;
        $found = [];
        foreach ($syn as $k => $words) {
            foreach ($words as $w) {                                  // first synonym (in priority order) found in this row
                foreach ($row as $ci => $v) if (strtolower(trim((string)$v)) === $w) { $found[$k] = $ci; break 2; }
            }
        }
        if (isset($found['sku']) && isset($found['qty'])) { $best = ['row' => $rn] + $found; break; }
    }
    return $best;
}

function bik_num($v) { $v = str_replace(',', '', trim((string)$v)); return is_numeric($v) ? (float)$v : null; }

/** Reconcile the matrix (with the chosen mapping) with YMS as at $date. */
function bik_compare($conn, $matrix, $hr, $ci_sku, $ci_qty, $ci_name, $date) {
    $by = []; $skipped = 0;
    foreach ($matrix as $rn => $row) {
        if ($rn <= $hr) continue;
        $sku = bik_code($row[$ci_sku] ?? '');
        if ($sku === '' || stripos($sku, 'total') !== false) { continue; }
        $q = bik_num($row[$ci_qty] ?? '');
        if ($q === null) { $skipped++; continue; }
        if (!isset($by[$sku])) $by[$sku] = ['sku' => $sku, 'name' => $ci_name !== null ? trim((string)($row[$ci_name] ?? '')) : '', 'ikea' => 0.0];
        $by[$sku]['ikea'] += $q;
    }
    $masters = [];
    foreach (im_load_masters($conn) as $m) $masters[(string)$m['code']] = $m['name'];
    $our = bik_closing($conn, $date);
    $matched = []; $new = []; $only = [];
    foreach ($by as $sku => $r) {
        $in_sys = isset($masters[$sku]) || isset($our[$sku]);
        $ymsq = isset($our[$sku]) ? $our[$sku]['close'] : 0.0;
        $name = $r['name'] !== '' ? $r['name'] : ($masters[$sku] ?? '');
        $diff = round($r['ikea'] - $ymsq, 2);                 // + = YMS short (add units), − = YMS excess (take units out)
        $row = ['sku' => $sku, 'name' => $name, 'ikea' => $r['ikea'], 'yms' => $ymsq, 'diff' => $diff];
        if ($in_sys) $matched[] = $row + ['kind' => $diff == 0 ? 'tally' : ($diff > 0 ? 'short' : 'excess')];
        else $new[] = $row + ['kind' => 'new'];
    }
    foreach ($our as $sku => $o) {
        if (isset($by[$sku]) || round($o['close'], 2) == 0) continue;
        $only[] = ['sku' => $sku, 'name' => $o['name'] ?: ($masters[$sku] ?? ''), 'ikea' => 0.0, 'yms' => $o['close'], 'diff' => round(-$o['close'], 2), 'kind' => 'only'];
    }
    $cmp = function ($a, $b) { return strcmp($a['sku'], $b['sku']); };
    usort($matched, $cmp); usort($new, $cmp); usort($only, $cmp);
    return [$matched, $new, $only, $skipped, count($by)];
}

/* ── JSON endpoints ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    if ($act === 'preview' || $act === 'import') {
        if (!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) bik_json(['ok' => false, 'msg' => 'File upload failed. Please try again.']);
        $f = $_FILES['xlsx_file'];
        if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') bik_json(['ok' => false, 'msg' => 'Please upload an .xlsx file.']);
        $sheet_idx = max(0, (int)($_POST['sheet'] ?? 0));
        [$matrix, $err, $names] = bik_matrix($f['tmp_name'], $sheet_idx);
        if ($matrix === null) bik_json(['ok' => false, 'msg' => $err]);
        if (!$matrix) bik_json(['ok' => false, 'msg' => 'The selected sheet is empty.']);
        $det = bik_detect($matrix);
        $hr = isset($_POST['header_row']) && $_POST['header_row'] !== '' ? (int)$_POST['header_row'] : ($det['row'] ?? null);
        if ($hr === null) bik_json(['ok' => false, 'msg' => 'Could not find the header row (SKU / item code and quantity columns). Choose the sheet and header row below.', 'names' => $names, 'sheet' => $sheet_idx, 'need_map' => 1,
                                    'preview' => array_slice(array_map(function ($r) { return array_values($r); }, array_slice($matrix, 0, 12, true)), 0, 12)]);
        $hdr = $matrix[$hr] ?? [];
        $cols = [];
        foreach ($hdr as $ci => $h) if (trim((string)$h) !== '') $cols[] = ['i' => $ci, 'h' => trim((string)$h)];
        $ci_sku = isset($_POST['col_sku']) && $_POST['col_sku'] !== '' ? (int)$_POST['col_sku'] : ($det && $det['row'] == $hr ? $det['sku'] : null);
        $ci_qty = isset($_POST['col_qty']) && $_POST['col_qty'] !== '' ? (int)$_POST['col_qty'] : ($det && $det['row'] == $hr ? $det['qty'] : null);
        $ci_name = isset($_POST['col_name']) && $_POST['col_name'] !== '' ? ((int)$_POST['col_name'] >= 0 ? (int)$_POST['col_name'] : null) : ($det && $det['row'] == $hr ? ($det['name'] ?? null) : null);
        $meta = ['sheets' => $names, 'sheet' => $sheet_idx, 'header_row' => $hr, 'cols' => $cols, 'col_sku' => $ci_sku, 'col_qty' => $ci_qty, 'col_name' => $ci_name];
        if ($ci_sku === null || $ci_qty === null) bik_json(['ok' => false, 'msg' => 'Choose the SKU column and the quantity column.', 'need_map' => 1] + $meta);
        $date = trim($_POST['adj_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) $date = date('Y-m-d');
        [$matched, $new, $only, $skipped, $n_ikea] = bik_compare($conn, $matrix, $hr, $ci_sku, $ci_qty, $ci_name, $date);

        if ($act === 'preview') {
            $c = ['tally' => 0, 'short' => 0, 'excess' => 0]; $us = $ue = 0;
            foreach ($matched as $r) { $c[$r['kind']]++; if ($r['diff'] > 0) $us += $r['diff']; elseif ($r['diff'] < 0) $ue += -$r['diff']; }
            bik_json(['ok' => true, 'date' => $date, 'meta' => $meta, 'ikea_items' => $n_ikea, 'skipped' => $skipped, 'counts' => $c, 'short_units' => $us, 'excess_units' => $ue,
                      'matched' => $matched, 'new' => $new, 'only' => $only]);
        }
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') bik_json(['ok' => false, 'msg' => 'Please enter the reason for the adjustment.']);
        $pick = json_decode($_POST['skus'] ?? '[]', true); $pick = is_array($pick) ? array_flip(array_map('strval', $pick)) : [];
        $todo = [];
        foreach (array_merge($matched, $new, $only) as $r) if (isset($pick[$r['sku']]) && round($r['diff'], 2) != 0) $todo[] = $r;
        if (!$todo) bik_json(['ok' => false, 'msg' => 'Nothing to adjust — no line with a difference is selected.']);
        $new_items = [];
        foreach ($todo as $r) if ($r['kind'] === 'new') $new_items[$r['sku']] = ['name' => $r['name'], 'basepack' => ''];
        $created = im_create_missing_items($conn, $new_items, $who);
        $name = mb_substr($f['name'], 0, 255);
        $st = mysqli_prepare($conn, "INSERT INTO stock_adjustment_imports (adj_date, reason, filename, created_by) VALUES (?,?,?,?)");
        mysqli_stmt_bind_param($st, 'ssss', $date, $reason, $name, $who); mysqli_stmt_execute($st);
        $iid = mysqli_insert_id($conn);
        $ia = mysqli_prepare($conn, "INSERT INTO stock_manual_adjustments (item_code, entry_date, adjustment_qty, note, created_by) VALUES (?,?,?,?,?)");
        $il = mysqli_prepare($conn, "INSERT INTO stock_adjustment_import_lines (import_id, adjustment_id, sku_code, basepack_code, item_name, transaction_type, txn_ref, txn_date, party_name, qty_units, signed_qty, tur, line_value, system_qty, excel_qty) VALUES (?,?,?,'',?,?,'',?, '',?,?,0,0,?,?)");
        $label = ['short' => 'IKEA Recon Short', 'excess' => 'IKEA Recon Excess', 'new' => 'IKEA Recon New Item', 'only' => 'IKEA Recon Only in YMS', 'tally' => 'IKEA Recon'];
        foreach ($todo as $r) {
            $d = $r['diff']; $abs = abs($d); $tt = $label[$r['kind']] ?? 'IKEA Recon';
            $note = mb_substr('IKEA recon #' . $iid . ' · ' . strtoupper($r['kind']) . ' · YMS ' . round($r['yms'], 2) . ' → IKEA ' . round($r['ikea'], 2) . ' · ' . $reason, 0, 255);
            mysqli_stmt_bind_param($ia, 'ssdss', $r['sku'], $date, $d, $note, $who); mysqli_stmt_execute($ia);
            $aid = mysqli_insert_id($conn);
            mysqli_stmt_bind_param($il, 'iissssddd' . 'd', $iid, $aid, $r['sku'], $r['name'], $tt, $date, $abs, $d, $r['yms'], $r['ikea']);
            mysqli_stmt_execute($il);
        }
        bik_json(['ok' => true, 'id' => $iid, 'rows' => count($todo), 'created' => $created]);
    }
    if ($act === 'delete_import') {
        $id = (int)($_POST['id'] ?? 0);
        mysqli_query($conn, "DELETE a FROM stock_manual_adjustments a JOIN stock_adjustment_import_lines l ON l.adjustment_id = a.id WHERE l.import_id=$id");
        mysqli_query($conn, "DELETE FROM stock_adjustment_import_lines WHERE import_id=$id");
        mysqli_query($conn, "DELETE FROM stock_adjustment_imports WHERE id=$id");
        bik_json(['ok' => true]);
    }
    if ($act === 'delete_items') {
        $id = (int)($_POST['id'] ?? 0);
        $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
        if (!$ids) bik_json(['ok' => false, 'msg' => 'Nothing selected.']);
        $in = implode(',', $ids);
        mysqli_query($conn, "DELETE a FROM stock_manual_adjustments a JOIN stock_adjustment_import_lines l ON l.adjustment_id = a.id WHERE l.import_id=$id AND l.id IN ($in)");
        mysqli_query($conn, "DELETE FROM stock_adjustment_import_lines WHERE import_id=$id AND id IN ($in)");
        $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM stock_adjustment_import_lines WHERE import_id=$id"));
        if ((int)$c['c'] === 0) mysqli_query($conn, "DELETE FROM stock_adjustment_imports WHERE id=$id");
        bik_json(['ok' => true]);
    }
    bik_json(['ok' => false, 'msg' => 'Unknown action.']);
}
if (($_GET['ajax'] ?? '') === 'items') {
    $id = (int)($_GET['id'] ?? 0); $items = [];
    $q = mysqli_query($conn, "SELECT l.* FROM stock_adjustment_import_lines l JOIN stock_manual_adjustments a ON a.id = l.adjustment_id WHERE l.import_id=$id ORDER BY l.transaction_type, l.sku_code");
    if ($q) while ($r = mysqli_fetch_assoc($q)) $items[] = $r;
    bik_json(['ok' => true, 'items' => $items]);
}

$imports = [];
$q = mysqli_query($conn, "SELECT i.*, COUNT(a.id) AS n, COALESCE(SUM(CASE WHEN l.signed_qty > 0 THEN l.signed_qty ELSE 0 END),0) AS q_in,
        COALESCE(SUM(CASE WHEN l.signed_qty < 0 THEN -l.signed_qty ELSE 0 END),0) AS q_out
    FROM stock_adjustment_imports i
    LEFT JOIN stock_adjustment_import_lines l ON l.import_id = i.id
    LEFT JOIN stock_manual_adjustments a ON a.id = l.adjustment_id
    WHERE EXISTS (SELECT 1 FROM stock_adjustment_import_lines v WHERE v.import_id = i.id AND v.transaction_type LIKE 'IKEA Recon%')
    GROUP BY i.id ORDER BY i.adj_date DESC, i.id DESC");
if ($q) while ($r = mysqli_fetch_assoc($q)) $imports[] = $r;
$sum = ['n' => count($imports), 'rows' => 0, 'in' => 0, 'out' => 0];
foreach ($imports as $r) { $sum['rows'] += $r['n']; $sum['in'] += $r['q_in']; $sum['out'] += $r['q_out']; }
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
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
<style>
.bk-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:10px 0 8px;} .bk-tab{border:1.5px solid #e2e6f0;background:#f7f8fc;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:700;cursor:pointer;color:#475569;}
.bk-tab.on{background:#0a0c14;color:#fff;border-color:#0a0c14;} .bk-pill{display:inline-block;padding:1px 8px;border-radius:9px;font-size:10.5px;font-weight:700;}
.k-tally{background:#dcfce7;color:#166534;} .k-short{background:#fee2e2;color:#991b1b;} .k-excess{background:#fef3c7;color:#92400e;} .k-new{background:#e0e7ff;color:#3730a3;} .k-only{background:#f1f5f9;color:#475569;}
.bk-map{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;} .bk-map .fld{margin:0;} .bk-map select,.bk-map input{padding:6px 8px;border:1.5px solid #e2e6f0;border-radius:8px;font-size:12.5px;max-width:200px;}
</style>
<div class="idm">
  <h2><i class="fa-solid fa-code-compare"></i> IKEA vs YMS Stock Reconciliation</h2>
  <div class="sub">Upload the <b>IKEA stock Excel</b>. It is reconciled with <b>YMS Good stock</b> item by item: <b>Short</b> (YMS below IKEA), <b>Excess</b> (YMS above IKEA), <b>items new in IKEA</b> (not in YMS) and <b>items only in YMS</b> (not in the Excel) are listed separately. Tick the lines, enter the <b>reason</b> and post — YMS stock is adjusted to the IKEA quantity. Or just export the reconciliation.</div>

  <div class="idm-kp">
    <div><small>Reconciliations posted</small><b><?php echo number_format($sum['n']); ?></b></div>
    <div><small>Adjusted items</small><b><?php echo number_format($sum['rows']); ?></b></div>
    <div><small>Units added (+)</small><b class="pos">+<?php echo number_format($sum['in'], 0); ?></b></div>
    <div><small>Units taken out (−)</small><b class="negq">−<?php echo number_format($sum['out'], 0); ?></b></div>
  </div>

  <div class="idm-card">
    <div class="idm-drop" id="idmDrop" onclick="document.getElementById('idmFile').click()">
      <i class="fa-solid fa-cloud-arrow-up"></i><div id="idmDropTxt">Drop the IKEA stock Excel here or click to choose (.xlsx)</div>
    </div>
    <input type="file" id="idmFile" accept=".xlsx" style="display:none">
    <div class="idm-msg" id="idmMsg"></div>
  </div>

  <h3 style="font-size:15px;margin:6px 0 8px;">Posted reconciliations</h3>
  <div class="idm-wrap">
  <table class="idm-t">
    <thead><tr><th>#</th><th>Date</th><th>Reason</th><th>File</th><th class="n">Items</th><th class="n">Units in</th><th class="n">Units out</th><th>By</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($imports as $r): $id = (int)$r['id']; ?>
      <tr>
        <td>#<?php echo $id; ?></td><td><b><?php echo date('d/m/Y', strtotime($r['adj_date'])); ?></b></td>
        <td style="white-space:normal;max-width:260px;"><?php echo htmlspecialchars($r['reason'] ?: '—'); ?></td>
        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['filename']); ?>"><?php echo htmlspecialchars($r['filename']); ?></td>
        <td class="n"><?php echo (int)$r['n']; ?></td><td class="n pos">+<?php echo number_format($r['q_in'], 0); ?></td><td class="n negq">−<?php echo number_format($r['q_out'], 0); ?></td>
        <td><?php echo htmlspecialchars($r['created_by'] ?? ''); ?><div style="font-size:10.5px;color:#94a3b8;"><?php echo htmlspecialchars(substr((string)$r['created_at'], 0, 16)); ?></div></td>
        <td style="white-space:nowrap;">
          <button class="idm-b l s" onclick="idmView(<?php echo $id; ?>)"><i class="fa-solid fa-eye"></i> View</button>
          <button class="idm-b r s" onclick="idmDel(<?php echo $id; ?>)"><i class="fa-solid fa-trash"></i> Delete</button>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$imports): ?><tr><td colspan="9" style="text-align:center;padding:30px;color:#94a3b8;">Nothing posted yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="idm-modal" id="idmModal" onclick="if(event.target===this)idmClose('idmModal')">
  <div class="idm-mb" style="max-width:1050px;">
    <h3><i class="fa-solid fa-code-compare"></i> IKEA vs YMS Stock Reconciliation</h3>
    <div class="bk-map" id="bkMap"></div>
    <div id="idmSummary" style="font-size:13px;color:#334155;background:#f8fafc;border:1px solid #e2e6f0;border-radius:8px;padding:10px 12px;margin-bottom:10px;"></div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:8px;">
      <div class="fld" style="margin:0"><label>As at / adjustment date</label><input class="idm-in" type="date" id="idmDate" onchange="idmPreview()" style="width:170px"></div>
      <div class="fld" style="margin:0;flex:1;min-width:240px"><label>Reason <span style="color:#dc2626">*</span></label><input class="idm-in" type="text" id="idmReason" maxlength="400" placeholder="Required — e.g. Stock variance found in IKEA vs YMS reconciliation"></div>
    </div>
    <div class="bk-tabs" id="bkTabs"></div>
    <div class="idm-wrap" style="max-height:40vh;"><table class="idm-t"><thead><tr><th><input type="checkbox" id="idmAll" onclick="bkAll(this.checked)"></th><th>SKU</th><th>Item</th><th class="n">IKEA qty</th><th class="n">YMS qty</th><th class="n">Difference</th><th>Status</th></tr></thead><tbody id="idmPB"></tbody></table></div>
    <div class="idm-msg" id="idmMMsg"></div>
    <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center;margin-top:12px;flex-wrap:wrap;">
      <span id="idmSel" style="margin-right:auto;font-size:12.5px;color:#475569;"></span>
      <button class="idm-b l" onclick="idmClose('idmModal')">Close</button>
      <button class="idm-b l" onclick="bkExport()"><i class="fa-solid fa-file-excel"></i> Export reconciliation</button>
      <button class="idm-b g" id="idmGo" onclick="idmImport()"><i class="fa-solid fa-scale-balanced"></i> Post adjustments</button>
    </div>
  </div>
</div>

<div class="idm-modal" id="idmView" onclick="if(event.target===this)idmClose('idmView')">
  <div class="idm-mb" style="max-width:1100px;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;"><h3 style="margin:0;" id="idmVT">Adjustment lines</h3>
      <button class="idm-b r s" style="margin-left:auto;" onclick="idmDelLines()"><i class="fa-solid fa-trash"></i> Delete selected</button>
      <button class="idm-b l s" onclick="idmClose('idmView')">Close</button></div>
    <div class="idm-wrap" style="max-height:62vh;"><table class="idm-t"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.idm-ic').forEach(c=>c.checked=this.checked)"></th><th>Type</th><th>SKU</th><th>Item</th><th class="n">YMS (then)</th><th class="n">IKEA</th><th class="n">Adjustment</th></tr></thead><tbody id="idmVB"></tbody></table></div>
  </div>
</div>

<script>
const $i = id => document.getElementById(id);
const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmt = (n, d) => (+n || 0).toLocaleString('en-US', {minimumFractionDigits:d || 0, maximumFractionDigits:d || 0});
let idmFile = null, idmViewId = 0, bk = null, bkTab = 'matched', bkSel = new Set();
function idmClose(id){ $i(id).classList.remove('open'); }
function idmMsg(el, cls, txt){ el.className = 'idm-msg ' + cls; el.textContent = txt; }
$i('idmDrop').addEventListener('dragover', e => e.preventDefault());
$i('idmDrop').addEventListener('drop', e => { e.preventDefault(); if (e.dataTransfer.files[0]) idmPick(e.dataTransfer.files[0]); });
$i('idmFile').addEventListener('change', e => { if (e.target.files[0]) idmPick(e.target.files[0]); });
function idmPick(f){
    if (!/\.xlsx$/i.test(f.name)) { idmMsg($i('idmMsg'), 'err', 'Please choose an .xlsx file.'); return; }
    idmFile = f; $i('idmDrop').classList.add('has'); $i('idmDropTxt').textContent = f.name + ' — reading and reconciling…';
    bk = null; bkSel = new Set(); $i('idmDate').value = ''; idmPreview(true, {});
}
function bkMapVals(){ return {sheet: $i('bkSheet') ? $i('bkSheet').value : '', header_row: $i('bkHdr') ? $i('bkHdr').value : '', col_sku: $i('bkSku') ? $i('bkSku').value : '', col_qty: $i('bkQty') ? $i('bkQty').value : '', col_name: $i('bkName') ? $i('bkName').value : ''}; }
function idmPreview(first, map){
    const m = map || bkMapVals();
    const fd_ = new FormData(); fd_.append('action', 'preview'); fd_.append('xlsx_file', idmFile); fd_.append('adj_date', $i('idmDate').value);
    Object.keys(m).forEach(k => fd_.append(k, m[k]));
    $i('idmPB').innerHTML = '<tr><td colspan="7" style="padding:16px;color:#94a3b8;">Reconciling…</td></tr>';
    fetch('ikeastock.php', {method:'POST', body:fd_, credentials:'same-origin'}).then(r => r.json()).then(d => {
        $i('idmDropTxt').textContent = idmFile.name;
        if (!d.ok) {
            if (d.need_map || d.meta) { bkRenderMap(d.meta || d, d); $i('idmSummary').innerHTML = '<span style="color:#991b1b">' + esc(d.msg) + '</span>'; $i('idmPB').innerHTML = ''; $i('idmTabs'); $i('idmModal').classList.add('open'); return; }
            if (first) idmMsg($i('idmMsg'), 'err', d.msg); else idmMsg($i('idmMMsg'), 'err', d.msg); return;
        }
        $i('idmMsg').className = 'idm-msg'; $i('idmMMsg').className = 'idm-msg';
        bk = d; $i('idmDate').value = d.date; bkRenderMap(d.meta, d);
        const c = d.counts;
        $i('idmSummary').innerHTML = '<b>' + d.ikea_items + '</b> item(s) in the IKEA Excel · <span class="bk-pill k-tally">' + c.tally + ' tally</span> <span class="bk-pill k-short">' + c.short + ' short (+' + fmt(d.short_units) + ')</span> <span class="bk-pill k-excess">' + c.excess + ' excess (−' + fmt(d.excess_units) + ')</span> · <span class="bk-pill k-new">' + d.new.length + ' new in IKEA</span> <span class="bk-pill k-only">' + d.only.length + ' only in YMS</span>' + (d.skipped ? ' · ' + d.skipped + ' row(s) without a numeric qty skipped' : '');
        // default selection: matched short/excess ticked; new in IKEA ticked; only in YMS NOT ticked
        bkSel = new Set(); d.matched.filter(r => r.diff != 0).forEach(r => bkSel.add(r.sku)); d.new.forEach(r => bkSel.add(r.sku));
        bkRender(); $i('idmModal').classList.add('open');
    }).catch(e => idmMsg($i('idmMsg'), 'err', 'Upload failed: ' + e.message));
}
function bkRenderMap(meta, d){
    if (!meta) { $i('bkMap').innerHTML = ''; return; }
    const sheets = meta.sheets || [], cols = meta.cols || [];
    const opt = (v, t, sel) => '<option value="' + esc(v) + '"' + (String(sel) === String(v) ? ' selected' : '') + '>' + esc(t) + '</option>';
    let h = '<div class="fld"><label>Sheet</label><select id="bkSheet" onchange="idmPreview(false,{sheet:this.value})">' + sheets.map((n, i) => opt(i, n, meta.sheet)).join('') + '</select></div>'
          + '<div class="fld"><label>Header row</label><input id="bkHdr" type="number" min="1" value="' + (meta.header_row == null ? '' : meta.header_row) + '" style="width:80px" onchange="idmPreview(false,{sheet:$i(\'bkSheet\').value,header_row:this.value})"></div>';
    if (cols.length) {
        h += '<div class="fld"><label>SKU column</label><select id="bkSku" onchange="idmPreview(false)">' + opt('', '— choose —', meta.col_sku) + cols.map(c => opt(c.i, c.h, meta.col_sku)).join('') + '</select></div>'
           + '<div class="fld"><label>Quantity column</label><select id="bkQty" onchange="idmPreview(false)">' + opt('', '— choose —', meta.col_qty) + cols.map(c => opt(c.i, c.h, meta.col_qty)).join('') + '</select></div>'
           + '<div class="fld"><label>Name column</label><select id="bkName" onchange="idmPreview(false)">' + opt(-1, '— none —', meta.col_name == null ? -1 : meta.col_name) + cols.map(c => opt(c.i, c.h, meta.col_name)).join('') + '</select></div>';
    }
    $i('bkMap').innerHTML = h;
}
function bkRender(){
    if (!bk) return;
    const tabs = [['matched', 'Matched (' + bk.matched.length + ')'], ['new', 'New in IKEA (' + bk.new.length + ')'], ['only', 'Only in YMS (' + bk.only.length + ')']];
    $i('bkTabs').innerHTML = tabs.map(t => '<span class="bk-tab' + (bkTab === t[0] ? ' on' : '') + '" onclick="bkTab=\'' + t[0] + '\';bkRender()">' + t[1] + '</span>').join('');
    const rows = bk[bkTab];
    const lab = {tally:'Tally', short:'Short', excess:'Excess', new:'New in IKEA', only:'Only in YMS'};
    $i('idmPB').innerHTML = rows.map(r => '<tr><td>' + (r.diff != 0 ? '<input type="checkbox" class="idm-pc" value="' + esc(r.sku) + '"' + (bkSel.has(r.sku) ? ' checked' : '') + ' onchange="bkTog(this)">' : '') + '</td><td><b>' + esc(r.sku) + '</b></td><td>' + esc(r.name) + '</td><td class="n">' + fmt(r.ikea, 2) + '</td><td class="n">' + fmt(r.yms, 2) + '</td><td class="n ' + (r.diff < 0 ? 'negq' : (r.diff > 0 ? 'pos' : '')) + '">' + (r.diff == 0 ? '—' : (r.diff < 0 ? '−' : '+') + fmt(Math.abs(r.diff), 2)) + '</td><td><span class="bk-pill k-' + r.kind + '">' + lab[r.kind] + '</span></td></tr>').join('') || '<tr><td colspan="7" style="padding:18px;color:#94a3b8;">Nothing in this list.</td></tr>';
    $i('idmAll').checked = false; bkCount();
}
function bkTog(c){ if (c.checked) bkSel.add(c.value); else bkSel.delete(c.value); bkCount(); }
function bkAll(on){ document.querySelectorAll('.idm-pc').forEach(c => { c.checked = on; if (on) bkSel.add(c.value); else bkSel.delete(c.value); }); bkCount(); }
function bkCount(){
    if (!bk) return;
    const all = [].concat(bk.matched, bk.new, bk.only).filter(r => bkSel.has(r.sku) && r.diff != 0);
    let a = 0, b = 0; all.forEach(r => { if (r.diff > 0) a += r.diff; else b -= r.diff; });
    $i('idmSel').innerHTML = '<b>' + all.length + '</b> selected · <span class="pos">+' + fmt(a, 2) + '</span> · <span class="negq">−' + fmt(b, 2) + '</span>';
    $i('idmGo').disabled = all.length === 0;
}
function idmImport(){
    const reason = $i('idmReason').value.trim();
    if (!reason) { idmMsg($i('idmMMsg'), 'err', 'Please enter the reason for the adjustment.'); $i('idmReason').focus(); return; }
    const skus = Array.from(bkSel);
    if (!confirm('Post the selected adjustments so YMS stock matches IKEA?')) return;
    $i('idmGo').disabled = true; $i('idmGo').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Posting…';
    const f = new FormData(); f.append('action', 'import'); f.append('xlsx_file', idmFile); f.append('adj_date', $i('idmDate').value); f.append('reason', reason); f.append('skus', JSON.stringify(skus));
    const m = bkMapVals(); Object.keys(m).forEach(k => f.append(k, m[k]));
    fetch('ikeastock.php', {method:'POST', body:f, credentials:'same-origin'}).then(r => r.json()).then(d => {
        if (!d.ok) { idmMsg($i('idmMMsg'), 'err', d.msg); $i('idmGo').disabled = false; $i('idmGo').innerHTML = '<i class="fa-solid fa-scale-balanced"></i> Post adjustments'; return; }
        idmMsg($i('idmMMsg'), 'ok', d.rows + ' adjustment(s) posted' + (d.created ? ', ' + d.created + ' new item(s) created' : '') + '.'); setTimeout(() => location.reload(), 800);
    }).catch(e => { idmMsg($i('idmMMsg'), 'err', 'Import failed: ' + e.message); $i('idmGo').disabled = false; });
}
async function bkExport(){
    if (!bk || typeof ExcelJS === 'undefined') { alert('Nothing to export yet (or the Excel library is still loading).'); return; }
    const wb = new ExcelJS.Workbook();
    const add = (title, rows) => {
        const ws = wb.addWorksheet(title);
        ws.addRow(['SKU', 'Item', 'IKEA qty', 'YMS qty', 'Difference (adjustment)', 'Status']).font = {bold:true};
        rows.forEach(r => ws.addRow([r.sku, r.name, r.ikea, r.yms, r.diff, r.kind]));
        [12, 44, 12, 12, 20, 14].forEach((w, i) => ws.getColumn(i + 1).width = w);
    };
    add('Matched', bk.matched); add('New in IKEA', bk.new); add('Only in YMS', bk.only);
    const buf = await wb.xlsx.writeBuffer();
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([buf], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));
    a.download = 'IKEA_vs_YMS_Stock_Reconciliation_' + $i('idmDate').value + '.xlsx'; document.body.appendChild(a); a.click(); a.remove();
}
function idmPost(data){ const f = new FormData(); Object.keys(data).forEach(k => f.append(k, data[k])); return fetch('ikeastock.php', {method:'POST', body:f, credentials:'same-origin'}).then(r => r.json()); }
function idmDel(id){
    if (!confirm('Delete reconciliation #' + id + '? Its adjustments are removed and the YMS stock goes back.')) return;
    idmPost({action:'delete_import', id:id}).then(d => { if (d.ok) location.reload(); else alert(d.msg); });
}
function idmView(id){
    idmViewId = id; $i('idmVT').textContent = 'Adjustment lines — reconciliation #' + id; $i('idmVB').innerHTML = '<tr><td colspan="7" style="padding:18px;color:#94a3b8;">Loading…</td></tr>'; $i('idmView').classList.add('open');
    fetch('ikeastock.php?ajax=items&id=' + id, {credentials:'same-origin'}).then(r => r.json()).then(d => {
        $i('idmVB').innerHTML = d.items.map(i => { const s = +i.signed_qty; return '<tr><td><input type="checkbox" class="idm-ic" value="' + i.id + '"></td><td>' + esc(String(i.transaction_type).replace('IKEA Recon ', '')) + '</td><td><b>' + esc(i.sku_code) + '</b></td><td>' + esc(i.item_name) + '</td><td class="n">' + (i.system_qty == null ? '—' : fmt(i.system_qty, 2)) + '</td><td class="n">' + (i.excel_qty == null ? '—' : fmt(i.excel_qty, 2)) + '</td><td class="n ' + (s < 0 ? 'negq' : 'pos') + '">' + (s < 0 ? '−' : '+') + fmt(Math.abs(s), 2) + '</td></tr>'; }).join('') || '<tr><td colspan="7" style="padding:18px;color:#94a3b8;">No lines.</td></tr>';
    });
}
function idmDelLines(){
    const ids = Array.from(document.querySelectorAll('.idm-ic:checked')).map(c => c.value);
    if (!ids.length) { alert('Tick the lines to delete.'); return; }
    if (!confirm('Delete ' + ids.length + ' line(s)? Their stock adjustments are removed too.')) return;
    idmPost({action:'delete_items', id:idmViewId, ids:ids.join(',')}).then(d => { if (d.ok) location.reload(); else alert(d.msg); });
}
</script>
