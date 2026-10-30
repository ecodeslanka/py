<?php
// ============================================================
//  bulk_adjustment_import3.php  –  STOCK SYNC (Current Stock Excel)   (Sampath Operation)
//
//  Upload the LeverEDGE "Current Stock Report" Excel (MAIN GODOWN). Units are summed per SKU7 (all batches) and compared with OUR
//  Good-stock closing balance as at the chosen date (same basis as the Sampath Inventory Report:
//      Primary Purchase − Primary Returned − Secondary − Good Rejected + Adjustment ).
//  The DIFFERENCE (Excel − our closing) is shown item by item, and Import posts one stock adjustment per item so that our
//  closing stock equals the Excel:
//      Excel higher  -> + adjustment (units added)      Excel lower -> − adjustment (units taken out)
//      SKU not in our system -> the item is CREATED and its stock is set to the Excel units
//      item in our system but NOT in the Excel -> its Excel stock is 0, so it is taken to 0 (tick-box, ON by default)
//  TUR prices: tick-box "Update TUR prices" - where the Excel TUR differs from our current TUR it is saved as the new TUR
//  (item_tur_overrides, used by im_load_tur => Inventory Reconciliation Report value, Item Match). The OLD TUR is kept with it;
//  deleting the sync (or a TUR line) puts the old TUR back.
//  After the date is chosen, a re-upload of the same file shows no difference (the stock already tallies).
//  Adjustments are saved in stock_manual_adjustments (visible on Item Adjustment and in every stock report); a whole import or
//  single lines can be deleted. Lines are marked transaction_type "Stock Sync".
// ============================================================
include 'config.php';
include_once 'auth.php';
requireLogin();
require_once 'item_match_lib.php';     // im_load_masters(), im_create_missing_items()
if (session_status() === PHP_SESSION_NONE) session_start();
mysqli_report(MYSQLI_REPORT_OFF);
$who = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';

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

function bsi_json($a) { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

/** Our GOOD stock closing balance per item as at $date (inclusive) — same formula as the Sampath Inventory Report. Returns [code => ['close'=>, 'name'=>]] */
function bsi_closing($conn, $date) {
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

/** Reads the LeverEDGE Current Stock Excel (1st sheet). Returns [rows, error]; rows = one entry per SKU7 (Units summed over batches). */
function bsi_read($tmp) {
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
    return [array_values($by), '', $fdate];
}

/** Compare the Excel with our closing as at $date. Returns the full comparison list (every SKU in the Excel + items of ours with stock not in the Excel). */
function bsi_compare($conn, $rows, $date, $include_missing) {
    $turmap = im_load_tur($conn);
    $masters = [];
    foreach (im_load_masters($conn) as $m) $masters[(string)$m['code']] = $m['name'];
    $our = bsi_closing($conn, $date);
    $cmp = []; $seen = [];
    foreach ($rows as $r) {
        $c = $r['sku_code']; $seen[$c] = 1;
        $sys = isset($our[$c]) ? $our[$c]['close'] : 0.0;
        $cmp[] = ['sku' => $c, 'name' => $r['item_name'], 'basepack' => $r['basepack_code'], 'excel' => $r['units'], 'system' => $sys, 'diff' => round($r['units'] - $sys, 2),
                  'tur' => $r['tur'], 'tur_old' => (float)($turmap[$c] ?? 0), 'tur_new' => (float)$r['tur'],
                  'tur_changed' => $r['tur'] > 0 && abs($r['tur'] - (float)($turmap[$c] ?? 0)) > 0.004, 'new_item' => !isset($masters[$c]), 'missing' => false];
    }
    if ($include_missing) foreach ($our as $c => $o) {
        if (isset($seen[$c]) || round($o['close'], 2) == 0) continue;
        $cmp[] = ['sku' => $c, 'name' => $o['name'] ?: ($masters[$c] ?? ''), 'basepack' => '', 'excel' => 0.0, 'system' => $o['close'], 'diff' => round(-$o['close'], 2), 'tur' => 0.0, 'tur_old' => (float)($turmap[$c] ?? 0), 'tur_new' => 0.0, 'tur_changed' => false, 'new_item' => false, 'missing' => true];
    }
    return $cmp;
}

/* ── JSON endpoints ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    if ($act === 'preview' || $act === 'import') {
        if (!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) bsi_json(['ok' => false, 'msg' => 'File upload failed. Please try again.']);
        $f = $_FILES['xlsx_file'];
        if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') bsi_json(['ok' => false, 'msg' => 'Please upload an .xlsx file.']);
        $res_ = bsi_read($f['tmp_name']); $rows = $res_[0]; $err = $res_[1] ?? ''; $fdate = $res_[2] ?? null;
        if ($rows === null) bsi_json(['ok' => false, 'msg' => $err]);
        if (!$rows) bsi_json(['ok' => false, 'msg' => 'No stock lines were found in this Excel.']);
        $date = trim($_POST['adj_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) $date = $fdate ?: date('Y-m-d');
        $inc_missing = !empty($_POST['include_missing']) || $act === 'preview' && ($_POST['include_missing'] ?? '1') !== '0';
        $cmp = bsi_compare($conn, $rows, $date, $inc_missing);

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
            bsi_json(['ok' => true, 'date' => $date, 'file_date' => $fdate, 'excel_items' => count($rows), 'excel_units' => array_sum(array_column($rows, 'units')), 'same' => $same,
                      'new' => $new, 'missing' => $miss, 'in' => $in, 'out' => $out, 'tur_changes' => $turc, 'rows' => $list]);
        }
        $reason = trim($_POST['reason'] ?? '');
        $pick = json_decode($_POST['skus'] ?? '[]', true); $pick = is_array($pick) ? array_flip(array_map('strval', $pick)) : [];
        $todo = [];
        $upd = !empty($_POST['update_tur']);
        foreach ($cmp as $c) if (isset($pick[$c['sku']]) && (round($c['diff'], 2) != 0 || ($upd && $c['tur_changed']))) $todo[] = $c;
        if (!$todo) bsi_json(['ok' => false, 'msg' => 'Nothing to adjust — the stock (and TUR) already tally, or no line is selected.']);
        $new_items = [];
        foreach ($todo as $c) if ($c['new_item']) $new_items[$c['sku']] = ['name' => $c['name'], 'basepack' => $c['basepack']];
        $created = im_create_missing_items($conn, $new_items, $who);
        $name = mb_substr($f['name'], 0, 255);
        $st = mysqli_prepare($conn, "INSERT INTO stock_adjustment_imports (adj_date, reason, filename, created_by) VALUES (?,?,?,?)");
        mysqli_stmt_bind_param($st, 'ssss', $date, $reason, $name, $who); mysqli_stmt_execute($st);
        $iid = mysqli_insert_id($conn);
        $ia = mysqli_prepare($conn, "INSERT INTO stock_manual_adjustments (item_code, entry_date, adjustment_qty, note, created_by) VALUES (?,?,?,?,?)");
        $il = mysqli_prepare($conn, "INSERT INTO stock_adjustment_import_lines (import_id, adjustment_id, sku_code, basepack_code, item_name, transaction_type, txn_ref, txn_date, party_name, qty_units, signed_qty, tur, line_value, system_qty, excel_qty) VALUES (?,?,?,?,?,'Stock Sync','',?, '',?,?,?,?,?,?)");
        $to = mysqli_prepare($conn, "INSERT INTO item_tur_overrides (item_code, item_name, tur, old_tur, import_id, created_by) VALUES (?,?,?,?,?,?)");
        $turn = 0;
        foreach ($todo as $c) {
            if ($upd && $c['tur_changed']) {
                mysqli_stmt_bind_param($to, 'ssddis', $c['sku'], $c['name'], $c['tur_new'], $c['tur_old'], $iid, $who); mysqli_stmt_execute($to); $turn++;
            }
            if (round($c['diff'], 2) == 0) continue;          // TUR-only row: no stock adjustment
            $d = $c['diff']; $abs = abs($d); $val = $abs * $c['tur'];
            $note = mb_substr('Stock sync #' . $iid . ' · system ' . round($c['system'], 2) . ' → Excel ' . round($c['excel'], 2) . ($c['new_item'] ? ' · new item' : '') . ($c['missing'] ? ' · not in Excel' : '') . ($reason !== '' ? ' · ' . $reason : ''), 0, 255);
            mysqli_stmt_bind_param($ia, 'ssdss', $c['sku'], $date, $d, $note, $who); mysqli_stmt_execute($ia);
            $aid = mysqli_insert_id($conn);
            mysqli_stmt_bind_param($il, 'iissssdddddd', $iid, $aid, $c['sku'], $c['basepack'], $c['name'], $date, $abs, $d, $c['tur'], $val, $c['system'], $c['excel']);
            mysqli_stmt_execute($il);
        }
        bsi_json(['ok' => true, 'id' => $iid, 'rows' => count($todo) - count(array_filter($todo, function ($c) { return round($c['diff'], 2) == 0; })), 'tur' => $turn, 'created' => $created]);
    }
    if ($act === 'delete_import') {
        $id = (int)($_POST['id'] ?? 0);
        mysqli_query($conn, "DELETE a FROM stock_manual_adjustments a JOIN stock_adjustment_import_lines l ON l.adjustment_id = a.id WHERE l.import_id=$id");
        mysqli_query($conn, "DELETE FROM stock_adjustment_import_lines WHERE import_id=$id");
        mysqli_query($conn, "DELETE FROM item_tur_overrides WHERE import_id=$id");      // TUR goes back to the old price
        mysqli_query($conn, "DELETE FROM stock_adjustment_imports WHERE id=$id");
        bsi_json(['ok' => true]);
    }
    if ($act === 'delete_items') {
        $id = (int)($_POST['id'] ?? 0);
        $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
        if (!$ids) bsi_json(['ok' => false, 'msg' => 'Nothing selected.']);
        $in = implode(',', $ids);
        mysqli_query($conn, "DELETE o FROM item_tur_overrides o JOIN stock_adjustment_import_lines l ON l.sku_code = o.item_code AND l.import_id = o.import_id WHERE l.import_id=$id AND l.id IN ($in)");
        mysqli_query($conn, "DELETE a FROM stock_manual_adjustments a JOIN stock_adjustment_import_lines l ON l.adjustment_id = a.id WHERE l.import_id=$id AND l.id IN ($in)");
        mysqli_query($conn, "DELETE FROM stock_adjustment_import_lines WHERE import_id=$id AND id IN ($in)");
        $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT (SELECT COUNT(*) FROM stock_adjustment_import_lines WHERE import_id=$id) + (SELECT COUNT(*) FROM item_tur_overrides WHERE import_id=$id) c"));
        if ((int)$c['c'] === 0) mysqli_query($conn, "DELETE FROM stock_adjustment_imports WHERE id=$id");
        bsi_json(['ok' => true]);
    }
    if ($act === 'revert_tur') {
        $oid = (int)($_POST['id'] ?? 0); $iid = (int)($_POST['import_id'] ?? 0);
        mysqli_query($conn, "DELETE FROM item_tur_overrides WHERE id=$oid AND import_id=$iid");
        bsi_json(['ok' => true]);
    }
    bsi_json(['ok' => false, 'msg' => 'Unknown action.']);
}
if (($_GET['ajax'] ?? '') === 'items') {
    $id = (int)($_GET['id'] ?? 0); $items = [];
    $q = mysqli_query($conn, "SELECT l.* FROM stock_adjustment_import_lines l JOIN stock_manual_adjustments a ON a.id = l.adjustment_id WHERE l.import_id=$id ORDER BY l.sku_code");
    if ($q) while ($r = mysqli_fetch_assoc($q)) $items[] = $r;
    $turs = [];
    $q = mysqli_query($conn, "SELECT id, item_code, item_name, tur, old_tur FROM item_tur_overrides WHERE import_id=$id ORDER BY item_code");
    if ($q) while ($r = mysqli_fetch_assoc($q)) $turs[] = $r;
    bsi_json(['ok' => true, 'items' => $items, 'turs' => $turs]);
}

$imports = [];
$q = mysqli_query($conn, "SELECT i.*, COUNT(a.id) AS n, COALESCE(SUM(CASE WHEN l.signed_qty > 0 THEN l.signed_qty ELSE 0 END),0) AS q_in,
        COALESCE(SUM(CASE WHEN l.signed_qty < 0 THEN -l.signed_qty ELSE 0 END),0) AS q_out,
        (SELECT COUNT(*) FROM item_tur_overrides t WHERE t.import_id = i.id) AS n_tur
    FROM stock_adjustment_imports i
    LEFT JOIN stock_adjustment_import_lines l ON l.import_id = i.id
    LEFT JOIN stock_manual_adjustments a ON a.id = l.adjustment_id
    WHERE EXISTS (SELECT 1 FROM stock_adjustment_import_lines v WHERE v.import_id = i.id AND v.transaction_type LIKE 'Stock Sync%')
       OR EXISTS (SELECT 1 FROM item_tur_overrides t2 WHERE t2.import_id = i.id)
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
<div class="idm">
  <h2><i class="fa-solid fa-scale-balanced"></i> Stock Sync — Current Stock Excel</h2>
  <div class="sub">Upload the LeverEDGE <b>Current Stock Report</b> (MAIN GODOWN). Units are summed per SKU7 and compared with <b>our Good-stock closing balance</b> as at the date. The <b>difference</b> is posted as a stock adjustment so our closing stock becomes the <b>same units as the Excel</b>. A SKU that is not in our system is <b>created</b> and set to the Excel units.</div>

  <div class="idm-kp">
    <div><small>Syncs</small><b><?php echo number_format($sum['n']); ?></b></div>
    <div><small>Adjusted items</small><b><?php echo number_format($sum['rows']); ?></b></div>
    <div><small>Units added (+)</small><b class="pos">+<?php echo number_format($sum['in'], 0); ?></b></div>
    <div><small>Units taken out (−)</small><b class="negq">−<?php echo number_format($sum['out'], 0); ?></b></div>
  </div>

  <div class="idm-card">
    <div class="idm-drop" id="idmDrop" onclick="document.getElementById('idmFile').click()">
      <i class="fa-solid fa-cloud-arrow-up"></i><div id="idmDropTxt">Drop the Current Stock Excel here or click to choose (.xlsx)</div>
    </div>
    <input type="file" id="idmFile" accept=".xlsx" style="display:none">
    <div class="idm-msg" id="idmMsg"></div>
  </div>

  <h3 style="font-size:15px;margin:6px 0 8px;">Imported stock syncs</h3>
  <div class="idm-wrap">
  <table class="idm-t">
    <thead><tr><th>#</th><th>As at / adjustment date</th><th>Reason / remark</th><th>File</th><th class="n">Items</th><th class="n">Units in</th><th class="n">Units out</th><th class="n">TUR updated</th><th>Imported by</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($imports as $r): $id = (int)$r['id']; ?>
      <tr>
        <td>#<?php echo $id; ?></td><td><b><?php echo date('d/m/Y', strtotime($r['adj_date'])); ?></b></td>
        <td style="white-space:normal;max-width:260px;"><?php echo htmlspecialchars($r['reason'] ?: '—'); ?></td>
        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['filename']); ?>"><?php echo htmlspecialchars($r['filename']); ?></td>
        <td class="n"><?php echo (int)$r['n']; ?></td><td class="n pos">+<?php echo number_format($r['q_in'], 0); ?></td><td class="n negq">−<?php echo number_format($r['q_out'], 0); ?></td>
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
    <h3><i class="fa-solid fa-scale-balanced"></i> Tally with our closing stock</h3>
    <div id="idmSummary" style="font-size:13px;color:#334155;background:#f8fafc;border:1px solid #e2e6f0;border-radius:8px;padding:10px 12px;margin-bottom:12px;"></div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px;">
      <div class="fld" style="margin:0"><label>As at / adjustment date</label><input class="idm-in" type="date" id="idmDate" onchange="idmPreview()" style="width:170px"></div>
      <div class="fld" style="margin:0;flex:1;min-width:220px"><label>Reason / remark</label><input class="idm-in" type="text" id="idmReason" maxlength="400" placeholder="e.g. Stock sync with LeverEDGE current stock"></div>
    </div>
    <label style="display:flex;gap:8px;align-items:flex-start;font-size:13px;margin-bottom:8px;background:#faf5ff;border:1px solid #d8b4fe;border-radius:8px;padding:8px 10px;"><input type="checkbox" id="idmTur" checked onchange="idmPreview()" style="margin-top:3px;"> <span><b>Update TUR prices</b> from this Excel where the TUR has changed. The old TUR is kept — deleting this sync (or a TUR line) puts the old TUR back.</span></label>
    <label style="display:flex;gap:8px;font-size:13px;margin-bottom:8px;"><input type="checkbox" id="idmMissing" checked onchange="idmPreview()"> <span>Also take our items that are <b>not in the Excel</b> to 0 (LeverEDGE shows no stock for them)</span></label>
    <div class="idm-wrap" style="max-height:44vh;"><table class="idm-t"><thead><tr><th><input type="checkbox" id="idmAll" checked onclick="document.querySelectorAll('.idm-pc').forEach(c=>c.checked=this.checked);idmCount()"></th><th>SKU7</th><th>Item</th><th class="n">Our closing</th><th class="n">Excel units</th><th class="n">Difference (adjustment)</th><th class="n">TUR old → new</th><th>Note</th></tr></thead><tbody id="idmPB"></tbody></table></div>
    <div class="idm-msg" id="idmMMsg"></div>
    <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center;margin-top:12px;">
      <span id="idmSel" style="margin-right:auto;font-size:12.5px;color:#475569;"></span>
      <button class="idm-b l" onclick="idmClose('idmModal')">Cancel</button>
      <button class="idm-b g" id="idmGo" onclick="idmImport()"><i class="fa-solid fa-scale-balanced"></i> Post adjustments</button>
    </div>
  </div>
</div>

<div class="idm-modal" id="idmView" onclick="if(event.target===this)idmClose('idmView')">
  <div class="idm-mb" style="max-width:1100px;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;"><h3 style="margin:0;" id="idmVT">Adjustment lines</h3>
      <button class="idm-b r s" style="margin-left:auto;" onclick="idmDelLines()"><i class="fa-solid fa-trash"></i> Delete selected</button>
      <button class="idm-b l s" onclick="idmClose('idmView')">Close</button></div>
    <div class="idm-wrap" style="max-height:46vh;"><table class="idm-t"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.idm-ic').forEach(c=>c.checked=this.checked)"></th><th>SKU7</th><th>Item</th><th class="n">Our closing (then)</th><th class="n">Excel units</th><th class="n">Adjustment</th></tr></thead><tbody id="idmVB"></tbody></table></div>
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
    fetch('bulk_adjustment_import3.php', {method:'POST', body:fd_, credentials:'same-origin'}).then(r => r.json()).then(d => {
        $i('idmDropTxt').textContent = idmFile.name;
        if (!d.ok) { if (first) idmMsg($i('idmMsg'), 'err', d.msg); else idmMsg($i('idmMMsg'), 'err', d.msg); return; }
        $i('idmMsg').className = 'idm-msg'; $i('idmMMsg').className = 'idm-msg';
        $i('idmDate').value = d.date;
        $i('idmSummary').innerHTML = '<b>' + d.excel_items + '</b> SKU(s) in the Excel · <b>' + fmt(d.excel_units) + '</b> units · <b>' + d.same + '</b> already tally'
            + '<br><b>' + d.rows.length + '</b> difference(s): <span class="pos">+' + fmt(d.in) + ' units to add</span> · <span class="negq">−' + fmt(d.out) + ' units to take out</span>'
            + (d.tur_changes ? '<br><span style="color:#6d28d9;"><b>' + d.tur_changes + '</b> item(s) have a changed TUR' + ($i('idmTur').checked ? ' — will be updated.' : ' — not updated (tick the option).') + '</span>' : '')
            + (d.new ? '<br><span style="color:#991b1b;"><b>' + d.new + '</b> SKU(s) are not in our system and will be <b>created as new items</b>.</span>' : '')
            + (d.missing ? '<br><span style="color:#92400e;"><b>' + d.missing + '</b> of our items are not in the Excel and will be taken to 0.</span>' : '');
        $i('idmPB').innerHTML = d.rows.map(r => '<tr><td><input type="checkbox" class="idm-pc" value="' + esc(r.sku) + '" data-d="' + r.diff + '" checked onchange="idmCount()"></td><td><b>' + esc(r.sku) + '</b></td><td>' + esc(r.name) + '</td><td class="n">' + fmt(r.system, 2) + '</td><td class="n">' + fmt(r.excel, 0) + '</td><td class="n ' + (r.diff < 0 ? 'negq' : 'pos') + '">' + (r.diff < 0 ? '−' : '+') + fmt(Math.abs(r.diff), 2) + '</td><td class="n">' + (r.tur_changed ? '<span style="color:#6d28d9;font-weight:700;">' + (r.tur_old ? fmt(r.tur_old, 2) : '—') + ' → ' + fmt(r.tur_new, 2) + '</span>' : '<span style="color:#94a3b8">' + (r.tur_old ? fmt(r.tur_old, 2) : '—') + '</span>') + '</td><td>' + (r.new_item ? '<span style="color:#991b1b;font-weight:700;">new item</span>' : (r.missing ? '<span style="color:#92400e;font-weight:700;">not in Excel</span>' : (+r.diff === 0 ? '<span style="color:#6d28d9;font-weight:700;">TUR only</span>' : ''))) + '</td></tr>').join('') || '<tr><td colspan="8" style="padding:18px;color:#166534;font-weight:700;">Everything already tallies — no adjustment or TUR change needed.</td></tr>';
        $i('idmAll').checked = true; idmCount();
        $i('idmGo').innerHTML = '<i class="fa-solid fa-scale-balanced"></i> Post adjustments'; $i('idmModal').classList.add('open');
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
    if (!confirm('Post ' + skus.length + ' stock adjustment(s) so our closing stock matches the Excel?')) return;
    $i('idmGo').disabled = true; $i('idmGo').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Posting…';
    const f = new FormData(); f.append('action', 'import'); f.append('xlsx_file', idmFile); f.append('adj_date', $i('idmDate').value); f.append('reason', $i('idmReason').value);
    f.append('skus', JSON.stringify(skus)); if ($i('idmMissing').checked) f.append('include_missing', '1'); if ($i('idmTur').checked) f.append('update_tur', '1');
    fetch('bulk_adjustment_import3.php', {method:'POST', body:f, credentials:'same-origin'}).then(r => r.json()).then(d => {
        if (!d.ok) { idmMsg($i('idmMMsg'), 'err', d.msg); $i('idmGo').disabled = false; $i('idmGo').innerHTML = '<i class="fa-solid fa-scale-balanced"></i> Post adjustments'; return; }
        idmMsg($i('idmMMsg'), 'ok', d.rows + ' adjustment(s) posted' + (d.tur ? ', ' + d.tur + ' TUR price(s) updated' : '') + (d.created ? ', ' + d.created + ' new item(s) created' : '') + '.'); setTimeout(() => location.reload(), 800);
    }).catch(e => { idmMsg($i('idmMMsg'), 'err', 'Import failed: ' + e.message); $i('idmGo').disabled = false; });
}
function idmPost(data){ const f = new FormData(); Object.keys(data).forEach(k => f.append(k, data[k])); return fetch('bulk_adjustment_import3.php', {method:'POST', body:f, credentials:'same-origin'}).then(r => r.json()); }
function idmDel(id){
    if (!confirm('Delete stock sync #' + id + '? Its adjustments are removed, the closing stock goes back and any TUR prices it changed return to the old ones.')) return;
    idmPost({action:'delete_import', id:id}).then(d => { if (d.ok) location.reload(); else alert(d.msg); });
}
function idmView(id){
    idmViewId = id; $i('idmVT').textContent = 'Adjustment lines — stock sync #' + id; $i('idmVB').innerHTML = '<tr><td colspan="6" style="padding:18px;color:#94a3b8;">Loading…</td></tr>'; $i('idmView').classList.add('open');
    fetch('bulk_adjustment_import3.php?ajax=items&id=' + id, {credentials:'same-origin'}).then(r => r.json()).then(d => {
        $i('idmVB').innerHTML = d.items.map(i => { const s = +i.signed_qty; return '<tr><td><input type="checkbox" class="idm-ic" value="' + i.id + '"></td><td><b>' + esc(i.sku_code) + '</b></td><td>' + esc(i.item_name) + '</td><td class="n">' + (i.system_qty == null ? '—' : fmt(i.system_qty, 2)) + '</td><td class="n">' + (i.excel_qty == null ? '—' : fmt(i.excel_qty, 0)) + '</td><td class="n ' + (s < 0 ? 'negq' : 'pos') + '">' + (s < 0 ? '−' : '+') + fmt(Math.abs(s), 2) + '</td></tr>'; }).join('') || '<tr><td colspan="6" style="padding:18px;color:#94a3b8;">No adjustment lines (TUR update only).</td></tr>';
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
    if (!confirm('Delete ' + ids.length + ' line(s)? Their stock adjustments are removed too.')) return;
    idmPost({action:'delete_items', id:idmViewId, ids:ids.join(',')}).then(d => { if (d.ok) location.reload(); else alert(d.msg); });
}
</script>
