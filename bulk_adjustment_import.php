<?php
// ============================================================
//  bulk_adjustment_import.php  –  BULK ADJUSTMENT IMPORT   (Sampath Operation)
//
//  Upload the LeverEDGE "Stock Adjustments" Excel. Every line is turned into a stock adjustment (units) on the
//  SKU Code (= our item code), exactly like the Item Adjustment page:
//      Transaction "UDB-Sales Returns"   -> units come IN to the inventory   (+ qty)
//      Transaction "Stock Subtraction"   -> units go OUT of the inventory    (− qty)
//  After the file is read a modal asks for the ADJUSTMENT DATE and a REASON / REMARK, then Import.
//  The adjustments are saved in stock_manual_adjustments, so the Sampath Inventory Report, Inventory Reconciliation
//  Report and Sampath Stock Reconciliation pick them up straight away. A whole import, or single lines, can be deleted
//  and the reports follow.
//
//  Tables: stock_adjustment_imports (one row per import), stock_adjustment_import_lines (the Excel lines, linked to the
//  stock_manual_adjustments row they created).
// ============================================================
include 'config.php';
include_once 'auth.php';
requireLogin();
require_once 'item_match_lib.php';     // im_load_masters()
if (session_status() === PHP_SESSION_NONE) session_start();
mysqli_report(MYSQLI_REPORT_OFF);
$who = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_manual_adjustments (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(100) NOT NULL,
    entry_date DATE NOT NULL,
    rejected_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
    adjustment_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
    note VARCHAR(255) DEFAULT NULL,
    created_by VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_item_code (item_code),
    INDEX idx_entry_date (entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_adjustment_imports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    adj_date DATE NOT NULL,
    reason VARCHAR(500) NULL,
    filename VARCHAR(255) NULL,
    created_by VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (adj_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_adjustment_import_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_id INT NOT NULL,
    adjustment_id INT NOT NULL,
    sku_code VARCHAR(100) NOT NULL,
    basepack_code VARCHAR(100) NULL,
    item_name VARCHAR(500) NULL,
    transaction_type VARCHAR(100) NULL,
    txn_ref VARCHAR(100) NULL,
    txn_date DATE NULL,
    party_name VARCHAR(255) NULL,
    qty_units DECIMAL(14,2) NOT NULL DEFAULT 0,
    signed_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
    tur DECIMAL(15,4) NULL,
    line_value DECIMAL(16,4) NULL,
    INDEX idx_import (import_id),
    INDEX idx_adj (adjustment_id),
    INDEX idx_dup (txn_ref, sku_code, transaction_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function bai_json($a) { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

/** +1 = units in (returns / additions), -1 = units out (subtraction), 0 = unknown */
function bai_sign($t) {
    $t = strtolower($t);
    if (strpos($t, 'subtract') !== false || strpos($t, 'write off') !== false || strpos($t, 'writeoff') !== false) return -1;
    if (strpos($t, 'return') !== false || strpos($t, 'addition') !== false || strpos($t, 'stock add') !== false) return 1;
    return 0;
}

/** Reads the "Stock Adjustments" Excel (LeverEDGE). Returns [rows, error]; rows = valid adjustment lines. */
function bai_read($tmp) {
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
        foreach ($row as $v) if (strtolower(trim((string)$v)) === 'sku code') { $hr = $rn; break 2; }
    }
    if ($hr === null) return [null, 'Column "SKU Code" was not found. Please upload the LeverEDGE Stock Adjustments Excel.'];
    foreach ($matrix[$hr] as $i => $h) $map[strtolower(trim((string)$h))] = $i;
    foreach (['transaction', 'qty units'] as $need) if (!isset($map[$need])) return [null, 'Column "' . $need . '" was not found in the header row.'];
    $g = function ($row, $name) use ($map) { return isset($map[$name]) ? ($row[$map[$name]] ?? null) : null; };
    $num = function ($v) { $v = str_replace(',', '', trim((string)$v)); return is_numeric($v) ? (float)$v : 0.0; };
    $dt  = function ($v) { $v = trim((string)$v); if ($v === '') return null; if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10); if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]); $t = strtotime($v); return $t ? date('Y-m-d', $t) : null; };
    $out = [];
    foreach ($matrix as $rn => $row) {
        if ($rn <= $hr) continue;
        $sku = trim((string)$g($row, 'sku code'));
        if ($sku === '' || strtolower($sku) === 'grand total') continue;
        $txn = trim((string)$g($row, 'transaction'));
        $q = $num($g($row, 'qty units'));
        if ($q == 0) continue;
        $out[] = [
            'sku_code' => $sku, 'basepack_code' => trim((string)$g($row, 'basepack code')), 'item_name' => trim((string)$g($row, 'item name')),
            'transaction_type' => $txn, 'txn_ref' => trim((string)$g($row, 'transaction reference number')), 'txn_date' => $dt($g($row, 'transaction date')),
            'party_name' => trim((string)$g($row, 'party name')), 'qty_units' => $q, 'sign' => bai_sign($txn),
            'tur' => $num($g($row, 'tur')), 'line_value' => $num($g($row, 'value')),
        ];
    }
    return [$out, ''];
}

/* ── JSON endpoints ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    if ($act === 'preview' || $act === 'import') {
        if (!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) bai_json(['ok' => false, 'msg' => 'File upload failed. Please try again.']);
        $f = $_FILES['xlsx_file'];
        if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') bai_json(['ok' => false, 'msg' => 'Please upload an .xlsx file.']);
        [$rows, $err] = bai_read($f['tmp_name']);
        if ($rows === null) bai_json(['ok' => false, 'msg' => $err]);
        if (!$rows) bai_json(['ok' => false, 'msg' => 'No adjustment lines were found in this Excel.']);

        $masters = [];
        foreach (im_load_masters($conn) as $m) $masters[(string)$m['code']] = $m['name'];
        $turmap = im_load_tur($conn);
        $ok = []; $unknown = []; $dups = 0;
        foreach ($rows as $r) {
            $r['tur'] = im_price_fill($r['tur'], $turmap, $r['sku_code']);       // price missing in the file -> system TUR
            if ((float)$r['line_value'] == 0 && $r['tur'] > 0) $r['line_value'] = round($r['qty_units'] * $r['tur'], 4);
            if ($r['sign'] === 0) { $unknown[$r['transaction_type'] ?: '(blank)'] = 1; continue; }
            $rf = mysqli_real_escape_string($conn, $r['txn_ref']); $sk = mysqli_real_escape_string($conn, $r['sku_code']); $tt = mysqli_real_escape_string($conn, $r['transaction_type']);
            $d = mysqli_query($conn, "SELECT 1 FROM stock_adjustment_import_lines l JOIN stock_manual_adjustments a ON a.id = l.adjustment_id
                WHERE l.txn_ref='$rf' AND l.sku_code='$sk' AND l.transaction_type='$tt' AND l.qty_units=" . (float)$r['qty_units'] . " LIMIT 1");
            if ($d && mysqli_num_rows($d) > 0) { $dups++; continue; }
            $ok[] = $r;
        }
        $in = 0; $out = 0; $nf = [];
        foreach ($ok as $r) {
            if ($r['sign'] > 0) $in += $r['qty_units']; else $out += $r['qty_units'];
            if (!isset($masters[$r['sku_code']])) $nf[$r['sku_code']] = $r['item_name'];
        }
        $dates = array_values(array_unique(array_filter(array_column($ok, 'txn_date')))); sort($dates);

        if ($act === 'preview') {
            bai_json(['ok' => true, 'rows' => count($ok), 'dups' => $dups, 'unknown' => array_keys($unknown), 'in' => $in, 'out' => $out,
                      'notfound' => count($nf), 'notfound_list' => array_slice(array_map(function ($c, $n) { return $c . ' ' . $n; }, array_keys($nf), $nf), 0, 6),
                      'date' => $dates ? $dates[0] : date('Y-m-d')]);
        }
        $dd = trim($_POST['adj_date'] ?? ''); $reason = trim($_POST['reason'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dd) || !checkdate((int)substr($dd, 5, 2), (int)substr($dd, 8, 2), (int)substr($dd, 0, 4))) bai_json(['ok' => false, 'msg' => 'Please select a valid adjustment date.']);
        if (!$ok) bai_json(['ok' => false, 'msg' => 'There is nothing new to import in this file (already imported or unknown transaction types).']);
        $new_items = [];
        foreach ($ok as $r) if (!isset($masters[$r['sku_code']])) $new_items[$r['sku_code']] = ['name' => $r['item_name'], 'basepack' => $r['basepack_code']];
        $created = im_create_missing_items($conn, $new_items, $who);      // SKU not in the system -> new item
        $name = mb_substr($f['name'], 0, 255);
        $st = mysqli_prepare($conn, "INSERT INTO stock_adjustment_imports (adj_date, reason, filename, created_by) VALUES (?,?,?,?)");
        mysqli_stmt_bind_param($st, 'ssss', $dd, $reason, $name, $who); mysqli_stmt_execute($st);
        $iid = mysqli_insert_id($conn);
        $ia = mysqli_prepare($conn, "INSERT INTO stock_manual_adjustments (item_code, entry_date, adjustment_qty, note, created_by) VALUES (?,?,?,?,?)");
        $il = mysqli_prepare($conn, "INSERT INTO stock_adjustment_import_lines (import_id, adjustment_id, sku_code, basepack_code, item_name, transaction_type, txn_ref, txn_date, party_name, qty_units, signed_qty, tur, line_value) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($ok as $r) {
            $signed = $r['sign'] * $r['qty_units'];
            $note = mb_substr('Bulk import #' . $iid . ' · ' . $r['transaction_type'] . ($r['txn_ref'] !== '' ? ' · ' . $r['txn_ref'] : '') . ($r['party_name'] !== '' ? ' · ' . $r['party_name'] : '') . ($reason !== '' ? ' · ' . $reason : ''), 0, 255);
            mysqli_stmt_bind_param($ia, 'ssdss', $r['sku_code'], $dd, $signed, $note, $who);
            mysqli_stmt_execute($ia);
            $aid = mysqli_insert_id($conn);
            mysqli_stmt_bind_param($il, 'iisssssssdddd', $iid, $aid, $r['sku_code'], $r['basepack_code'], $r['item_name'], $r['transaction_type'], $r['txn_ref'], $r['txn_date'], $r['party_name'], $r['qty_units'], $signed, $r['tur'], $r['line_value']);
            mysqli_stmt_execute($il);
        }
        bai_json(['ok' => true, 'id' => $iid, 'rows' => count($ok), 'dups' => $dups, 'created' => $created]);
    }
    if ($act === 'delete_import') {
        $id = (int)($_POST['id'] ?? 0);
        mysqli_query($conn, "DELETE a FROM stock_manual_adjustments a JOIN stock_adjustment_import_lines l ON l.adjustment_id = a.id WHERE l.import_id=$id");
        mysqli_query($conn, "DELETE FROM stock_adjustment_import_lines WHERE import_id=$id");
        mysqli_query($conn, "DELETE FROM stock_adjustment_imports WHERE id=$id");
        bai_json(['ok' => true]);
    }
    if ($act === 'delete_items') {
        $id = (int)($_POST['id'] ?? 0);
        $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
        if (!$ids) bai_json(['ok' => false, 'msg' => 'Nothing selected.']);
        $in = implode(',', $ids);
        mysqli_query($conn, "DELETE a FROM stock_manual_adjustments a JOIN stock_adjustment_import_lines l ON l.adjustment_id = a.id WHERE l.import_id=$id AND l.id IN ($in)");
        mysqli_query($conn, "DELETE FROM stock_adjustment_import_lines WHERE import_id=$id AND id IN ($in)");
        $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM stock_adjustment_import_lines WHERE import_id=$id"));
        if ((int)$c['c'] === 0) mysqli_query($conn, "DELETE FROM stock_adjustment_imports WHERE id=$id");
        bai_json(['ok' => true]);
    }
    bai_json(['ok' => false, 'msg' => 'Unknown action.']);
}
if (($_GET['ajax'] ?? '') === 'items') {
    $id = (int)($_GET['id'] ?? 0); $items = [];
    $q = mysqli_query($conn, "SELECT l.* FROM stock_adjustment_import_lines l JOIN stock_manual_adjustments a ON a.id = l.adjustment_id WHERE l.import_id=$id ORDER BY l.txn_ref, l.sku_code");
    if ($q) while ($r = mysqli_fetch_assoc($q)) $items[] = $r;
    $masters = [];
    foreach (im_load_masters($conn) as $m) $masters[(string)$m['code']] = 1;
    foreach ($items as &$it) $it['in_report'] = isset($masters[trim($it['sku_code'])]) ? 1 : 0;
    unset($it);
    bai_json(['ok' => true, 'items' => $items]);
}

/* import list (totals come from the lines whose adjustment still exists) */
$imports = [];
$q = mysqli_query($conn, "SELECT i.*, COUNT(a.id) AS n, COALESCE(SUM(CASE WHEN l.signed_qty > 0 THEN l.signed_qty ELSE 0 END),0) AS q_in,
        COALESCE(SUM(CASE WHEN l.signed_qty < 0 THEN -l.signed_qty ELSE 0 END),0) AS q_out
    FROM stock_adjustment_imports i
    LEFT JOIN stock_adjustment_import_lines l ON l.import_id = i.id
    LEFT JOIN stock_manual_adjustments a ON a.id = l.adjustment_id
    WHERE NOT EXISTS (SELECT 1 FROM stock_adjustment_import_lines v WHERE v.import_id = i.id AND (v.transaction_type LIKE 'Variance%' OR v.transaction_type LIKE 'Stock Sync%'))
      AND NOT EXISTS (SELECT 1 FROM item_tur_overrides t WHERE t.import_id = i.id AND t.source = 'adj')
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
  <h2><i class="fa-solid fa-boxes-packing"></i> Bulk Adjustment Import</h2>
  <div class="sub">Upload the LeverEDGE <b>Stock Adjustments</b> Excel. <b>UDB-Sales Returns</b> lines are added <b>into</b> the inventory and <b>Stock Subtraction</b> lines are taken <b>out</b> of it, in units on the SKU Code. They show in <a href="item_adjustment.php">Item Adjustment</a> and in all stock reports.</div>

  <div class="idm-kp">
    <div><small>Imports</small><b><?php echo number_format($sum['n']); ?></b></div>
    <div><small>Adjustment lines</small><b><?php echo number_format($sum['rows']); ?></b></div>
    <div><small>Units in (returns)</small><b class="pos">+<?php echo number_format($sum['in'], 0); ?></b></div>
    <div><small>Units out (subtraction)</small><b class="negq">−<?php echo number_format($sum['out'], 0); ?></b></div>
  </div>

  <div class="idm-card">
    <div class="idm-drop" id="idmDrop" onclick="document.getElementById('idmFile').click()">
      <i class="fa-solid fa-cloud-arrow-up"></i><div id="idmDropTxt">Drop the Stock Adjustments Excel here or click to choose (.xlsx)</div>
    </div>
    <input type="file" id="idmFile" accept=".xlsx" style="display:none">
    <div class="idm-msg" id="idmMsg"></div>
  </div>

  <h3 style="font-size:15px;margin:6px 0 8px;">Imported adjustments</h3>
  <div class="idm-wrap">
  <table class="idm-t">
    <thead><tr><th>#</th><th>Adjustment date</th><th>Reason / remark</th><th>File</th><th class="n">Lines</th><th class="n">Units in</th><th class="n">Units out</th><th>Imported by</th><th></th></tr></thead>
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
    <?php if (!$imports): ?><tr><td colspan="9" style="text-align:center;padding:30px;color:#94a3b8;">Nothing imported yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="idm-modal" id="idmModal" onclick="if(event.target===this)idmClose('idmModal')">
  <div class="idm-mb">
    <h3><i class="fa-solid fa-boxes-packing"></i> Import adjustments</h3>
    <div id="idmSummary" style="font-size:13px;color:#334155;background:#f8fafc;border:1px solid #e2e6f0;border-radius:8px;padding:10px 12px;margin-bottom:12px;"></div>
    <div class="fld"><label>Adjustment date</label><input class="idm-in" type="date" id="idmDate"></div>
    <div class="fld"><label>Reason / remark</label><textarea class="idm-in" id="idmReason" rows="3" maxlength="400" placeholder="e.g. Sales returns received, stock subtraction for damages"></textarea></div>
    <div class="idm-msg" id="idmMMsg"></div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;">
      <button class="idm-b l" onclick="idmClose('idmModal')">Cancel</button>
      <button class="idm-b g" id="idmGo" onclick="idmImport()"><i class="fa-solid fa-file-import"></i> Import</button>
    </div>
  </div>
</div>

<div class="idm-modal" id="idmView" onclick="if(event.target===this)idmClose('idmView')">
  <div class="idm-mb" style="max-width:1150px;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;"><h3 style="margin:0;" id="idmVT">Adjustment lines</h3>
      <button class="idm-b r s" style="margin-left:auto;" onclick="idmDelLines()"><i class="fa-solid fa-trash"></i> Delete selected</button>
      <button class="idm-b l s" onclick="idmClose('idmView')">Close</button></div>
    <div class="idm-wrap" style="max-height:62vh;"><table class="idm-t"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.idm-ic').forEach(c=>c.checked=this.checked)"></th><th>Transaction</th><th>Reference</th><th>SKU code</th><th>Item</th><th>Party</th><th class="n">Qty (in / out)</th><th>Inventory item</th></tr></thead><tbody id="idmVB"></tbody></table></div>
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
    idmFile = f; $i('idmDrop').classList.add('has'); $i('idmDropTxt').textContent = f.name + ' — reading…';
    const fd_ = new FormData(); fd_.append('action', 'preview'); fd_.append('xlsx_file', f);
    fetch('bulk_adjustment_import.php', {method:'POST', body:fd_, credentials:'same-origin'}).then(r => r.json()).then(d => {
        $i('idmDropTxt').textContent = f.name;
        if (!d.ok) { idmMsg($i('idmMsg'), 'err', d.msg); return; }
        $i('idmMsg').className = 'idm-msg';
        let h = '<b>' + d.rows + '</b> adjustment line(s) · <span class="pos">+' + fmt(d.in) + ' units in</span> · <span class="negq">−' + fmt(d.out) + ' units out</span>';
        if (d.dups) h += '<div style="color:#92400e;margin-top:4px;">' + d.dups + ' line(s) were already imported and will be skipped.</div>';
        if (d.unknown.length) h += '<div style="color:#991b1b;margin-top:4px;">Skipped — unknown transaction type: ' + esc(d.unknown.join(', ')) + '</div>';
        h += d.notfound ? '<div style="color:#991b1b;margin-top:4px;">' + d.notfound + ' SKU code(s) are not in the system yet — <b>they will be created as new items</b>: ' + esc(d.notfound_list.join(', ')) + '</div>' : '<div style="color:#166534;margin-top:4px;">All SKU codes match the inventory items.</div>';
        $i('idmSummary').innerHTML = h; $i('idmDate').value = d.date; $i('idmReason').value = ''; $i('idmMMsg').className = 'idm-msg';
        $i('idmGo').disabled = false; $i('idmGo').innerHTML = '<i class="fa-solid fa-file-import"></i> Import'; $i('idmModal').classList.add('open');
    }).catch(e => idmMsg($i('idmMsg'), 'err', 'Upload failed: ' + e.message));
}
function idmImport(){
    if (!$i('idmDate').value) { idmMsg($i('idmMMsg'), 'err', 'Select the adjustment date.'); return; }
    $i('idmGo').disabled = true; $i('idmGo').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Importing…';
    const f = new FormData(); f.append('action', 'import'); f.append('xlsx_file', idmFile); f.append('adj_date', $i('idmDate').value); f.append('reason', $i('idmReason').value);
    fetch('bulk_adjustment_import.php', {method:'POST', body:f, credentials:'same-origin'}).then(r => r.json()).then(d => {
        if (!d.ok) { idmMsg($i('idmMMsg'), 'err', d.msg); $i('idmGo').disabled = false; $i('idmGo').innerHTML = '<i class="fa-solid fa-file-import"></i> Import'; return; }
        idmMsg($i('idmMMsg'), 'ok', d.rows + ' adjustment line(s) imported' + (d.created ? ', ' + d.created + ' new item(s) created' : '') + '.'); setTimeout(() => location.reload(), 700);
    }).catch(e => { idmMsg($i('idmMMsg'), 'err', 'Import failed: ' + e.message); $i('idmGo').disabled = false; });
}
function idmPost(data){ const f = new FormData(); Object.keys(data).forEach(k => f.append(k, data[k])); return fetch('bulk_adjustment_import.php', {method:'POST', body:f, credentials:'same-origin'}).then(r => r.json()); }
function idmDel(id){
    if (!confirm('Delete import #' + id + '? Its adjustments are removed from the inventory too (returns go back out, subtractions come back in).')) return;
    idmPost({action:'delete_import', id:id}).then(d => { if (d.ok) location.reload(); else alert(d.msg); });
}
function idmView(id){
    idmViewId = id; $i('idmVT').textContent = 'Adjustment lines — import #' + id; $i('idmVB').innerHTML = '<tr><td colspan="8" style="padding:18px;color:#94a3b8;">Loading…</td></tr>'; $i('idmView').classList.add('open');
    fetch('bulk_adjustment_import.php?ajax=items&id=' + id, {credentials:'same-origin'}).then(r => r.json()).then(d => {
        $i('idmVB').innerHTML = d.items.map(i => { const s = +i.signed_qty; return '<tr><td><input type="checkbox" class="idm-ic" value="' + i.id + '"></td><td>' + esc(i.transaction_type) + '</td><td>' + esc(i.txn_ref) + '</td><td><b>' + esc(i.sku_code) + '</b></td><td>' + esc(i.item_name) + '</td><td>' + esc(i.party_name) + '</td><td class="n ' + (s < 0 ? 'negq' : 'pos') + '">' + (s < 0 ? '−' : '+') + fmt(Math.abs(s)) + '</td><td>' + (i.in_report == 1 ? '<span style="color:#166534;font-weight:700;">&#10003; matched</span>' : '<span style="color:#991b1b;font-weight:700;">&#10007; not in items</span>') + '</td></tr>'; }).join('') || '<tr><td colspan="8" style="padding:18px;color:#94a3b8;">No lines.</td></tr>';
    });
}
function idmDelLines(){
    const ids = Array.from(document.querySelectorAll('.idm-ic:checked')).map(c => c.value);
    if (!ids.length) { alert('Tick the lines to delete.'); return; }
    if (!confirm('Delete ' + ids.length + ' line(s)? Their stock adjustments are removed too.')) return;
    idmPost({action:'delete_items', id:idmViewId, ids:ids.join(',')}).then(d => { if (d.ok) location.reload(); else alert(d.msg); });
}
</script>
