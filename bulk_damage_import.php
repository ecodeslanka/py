<?php
// ============================================================
//  bulk_damage_import.php  –  BULK DAMAGE IMPORT   (Sampath Operation)
//
//  Upload the LeverEDGE "Stock Transfer Report" Excel. Lines moved MAIN GODOWN -> DAMAGE GODOWN are damages:
//    • each line is added to DAMAGED STOCK of its SKU Code (units) — saved in sampath_damage_import_items (marked
//      outlet_code = 'TRANSFER'), so the Sampath Inventory Report and the Inventory Reconciliation Report show it in the
//      Damaged Stock column / "-D" rows / damaged ledger on the date you choose;
//    • optionally (tick-box in the modal, ON by default) the same units are taken OUT of GOOD stock, as the transfer says,
//      with a minus entry in stock_manual_adjustments (shown on Item Adjustment too).
//  After the file is read a modal asks for the DATE and a REASON / REMARK. A whole import or single lines can be deleted,
//  which also removes the good-stock deduction.
// ============================================================
include 'config.php';
include_once 'auth.php';
requireLogin();
require_once 'item_match_lib.php';     // im_load_masters()
if (session_status() === PHP_SESSION_NONE) session_start();
mysqli_report(MYSQLI_REPORT_OFF);
$who = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_manual_adjustments (
    id INT(11) AUTO_INCREMENT PRIMARY KEY, item_code VARCHAR(100) NOT NULL, entry_date DATE NOT NULL,
    rejected_qty DECIMAL(14,2) NOT NULL DEFAULT 0, adjustment_qty DECIMAL(14,2) NOT NULL DEFAULT 0, note VARCHAR(255) DEFAULT NULL,
    created_by VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_item_code (item_code), INDEX idx_entry_date (entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
foreach (['adjustment_id' => 'INT NULL', 'reason' => 'VARCHAR(255) NULL'] as $col => $def) {
    $c = mysqli_query($conn, "SHOW COLUMNS FROM sampath_damage_import_items LIKE '$col'");
    if ($c && mysqli_num_rows($c) === 0) mysqli_query($conn, "ALTER TABLE sampath_damage_import_items ADD COLUMN $col $def");
}

function bdi_json($a) { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

/** Reads the LeverEDGE "Stock Transfer Report" Excel (1st tab). Returns [rows, error]. */
function bdi_read($tmp) {
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
    if ($hr === null) return [null, 'Column "SKU Code" was not found. Please upload the LeverEDGE Stock Transfer Report Excel.'];
    foreach ($matrix[$hr] as $i => $h) $map[strtolower(trim((string)$h))] = $i;
    foreach (['qty in units', 'to loc.'] as $need) if (!isset($map[$need])) return [null, 'Column "' . $need . '" was not found in the header row.'];
    $g = function ($row, $name) use ($map) { return isset($map[$name]) ? ($row[$map[$name]] ?? null) : null; };
    $num = function ($v) { $v = str_replace(',', '', trim((string)$v)); return is_numeric($v) ? (float)$v : 0.0; };
    $dt  = function ($v) { $v = trim((string)$v); if ($v === '') return null; if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10); if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]); $t = strtotime($v); return $t ? date('Y-m-d', $t) : null; };
    $out = [];
    foreach ($matrix as $rn => $row) {
        if ($rn <= $hr) continue;
        $sku = trim((string)$g($row, 'sku code'));
        if ($sku === '' || stripos($sku, 'total') !== false) continue;
        $q = $num($g($row, 'qty in units'));
        if ($q == 0) continue;
        $from = trim((string)$g($row, 'from loc.')); $to = trim((string)$g($row, 'to loc.'));
        $out[] = [
            'sku_code' => $sku, 'basepack_code' => trim((string)$g($row, 'basepack code')), 'item_name' => trim((string)$g($row, 'product name')),
            'txn_ref' => trim((string)$g($row, 'ref.no')), 'txn_date' => $dt($g($row, 'date')), 'from_loc' => $from, 'to_loc' => $to,
            'reason' => trim((string)$g($row, 'reason')), 'qty' => $q, 'rate' => $num($g($row, 'sip rate')),
            'is_damage' => (stripos($to, 'damage') !== false && stripos($from, 'damage') === false),
        ];
    }
    return [$out, ''];
}

/* ── JSON endpoints ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    if ($act === 'preview' || $act === 'import') {
        if (!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) bdi_json(['ok' => false, 'msg' => 'File upload failed. Please try again.']);
        $f = $_FILES['xlsx_file'];
        if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') bdi_json(['ok' => false, 'msg' => 'Please upload an .xlsx file.']);
        [$rows, $err] = bdi_read($f['tmp_name']);
        if ($rows === null) bdi_json(['ok' => false, 'msg' => $err]);
        if (!$rows) bdi_json(['ok' => false, 'msg' => 'No transfer lines were found in this Excel.']);

        $masters = [];
        foreach (im_load_masters($conn) as $m) $masters[(string)$m['code']] = $m['name'];
        $ok = []; $skipped = 0; $dups = 0;
        foreach ($rows as $r) {
            if (!$r['is_damage']) { $skipped++; continue; }
            $rf = mysqli_real_escape_string($conn, $r['txn_ref']); $sk = mysqli_real_escape_string($conn, $r['sku_code']);
            $d = mysqli_query($conn, "SELECT 1 FROM sampath_damage_import_items WHERE outlet_code='TRANSFER' AND bill_number='$rf' AND product_code='$sk' AND damage_qty=" . (float)$r['qty'] . " LIMIT 1");
            if ($d && mysqli_num_rows($d) > 0) { $dups++; continue; }
            $ok[] = $r;
        }
        $tq = 0; $tv = 0; $nf = [];
        foreach ($ok as $r) { $tq += $r['qty']; $tv += $r['qty'] * $r['rate']; if (!isset($masters[$r['sku_code']])) $nf[$r['sku_code']] = $r['item_name']; }
        $dates = array_values(array_unique(array_filter(array_column($ok, 'txn_date')))); sort($dates);

        if ($act === 'preview') {
            bdi_json(['ok' => true, 'rows' => count($ok), 'dups' => $dups, 'skipped' => $skipped, 'qty' => $tq, 'value' => $tv,
                      'notfound' => count($nf), 'notfound_list' => array_slice(array_map(function ($c, $n) { return $c . ' ' . $n; }, array_keys($nf), $nf), 0, 6),
                      'date' => $dates ? $dates[0] : date('Y-m-d')]);
        }
        $dd = trim($_POST['damage_date'] ?? ''); $remark = trim($_POST['remark'] ?? ''); $deduct = !empty($_POST['deduct_good']);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dd) || !checkdate((int)substr($dd, 5, 2), (int)substr($dd, 8, 2), (int)substr($dd, 0, 4))) bdi_json(['ok' => false, 'msg' => 'Please select a valid date.']);
        if (!$ok) bdi_json(['ok' => false, 'msg' => 'There is nothing new to import in this file (already imported, or not a transfer to the damage godown).']);
        $n = count($ok); $name = mb_substr($f['name'], 0, 255);
        $st = mysqli_prepare($conn, "INSERT INTO sampath_damage_imports (damage_date, remark, filename, total_rows, total_qty, total_value, created_by) VALUES (?,?,?,?,?,?,?)");
        mysqli_stmt_bind_param($st, 'sssidds', $dd, $remark, $name, $n, $tq, $tv, $who); mysqli_stmt_execute($st);
        $iid = mysqli_insert_id($conn);
        $ia = mysqli_prepare($conn, "INSERT INTO stock_manual_adjustments (item_code, entry_date, adjustment_qty, note, created_by) VALUES (?,?,?,?,?)");
        $ii = mysqli_prepare($conn, "INSERT INTO sampath_damage_import_items (import_id, damage_date, bill_number, bill_date, delivery_date, outlet_code, outlet_name, basepack_code, product_code, product_description, batch_code, tur, damage_qty, damage_value, adjustment_id, reason) VALUES (?,?,?,?,?,'TRANSFER',?,?,?,?,'',?,?,?,?,?)");
        foreach ($ok as $r) {
            $aid = null;
            if ($deduct) {
                $neg = -$r['qty'];
                $note = mb_substr('Damage transfer import #' . $iid . ' · ' . $r['txn_ref'] . ' · ' . $r['from_loc'] . ' → ' . $r['to_loc'] . ($remark !== '' ? ' · ' . $remark : ''), 0, 255);
                mysqli_stmt_bind_param($ia, 'ssdss', $r['sku_code'], $dd, $neg, $note, $who); mysqli_stmt_execute($ia);
                $aid = mysqli_insert_id($conn);
            }
            $loc = $r['from_loc'] . ' → ' . $r['to_loc']; $val = $r['qty'] * $r['rate']; $td = $r['txn_date'];
            mysqli_stmt_bind_param($ii, 'issssssssdddis', $iid, $dd, $r['txn_ref'], $td, $td, $loc, $r['basepack_code'], $r['sku_code'], $r['item_name'], $r['rate'], $r['qty'], $val, $aid, $r['reason']);
            mysqli_stmt_execute($ii);
        }
        bdi_json(['ok' => true, 'id' => $iid, 'rows' => $n, 'dups' => $dups, 'deducted' => $deduct]);
    }
    if ($act === 'delete_import') {
        $id = (int)($_POST['id'] ?? 0);
        mysqli_query($conn, "DELETE a FROM stock_manual_adjustments a JOIN sampath_damage_import_items i ON i.adjustment_id = a.id WHERE i.import_id=$id AND i.outlet_code='TRANSFER'");
        mysqli_query($conn, "DELETE FROM sampath_damage_import_items WHERE import_id=$id AND outlet_code='TRANSFER'");
        mysqli_query($conn, "DELETE FROM sampath_damage_imports WHERE id=$id");
        bdi_json(['ok' => true]);
    }
    if ($act === 'delete_items') {
        $id = (int)($_POST['id'] ?? 0);
        $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
        if (!$ids) bdi_json(['ok' => false, 'msg' => 'Nothing selected.']);
        $in = implode(',', $ids);
        mysqli_query($conn, "DELETE a FROM stock_manual_adjustments a JOIN sampath_damage_import_items i ON i.adjustment_id = a.id WHERE i.import_id=$id AND i.outlet_code='TRANSFER' AND i.id IN ($in)");
        mysqli_query($conn, "DELETE FROM sampath_damage_import_items WHERE import_id=$id AND outlet_code='TRANSFER' AND id IN ($in)");
        $t = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(SUM(damage_qty),0) q, COALESCE(SUM(damage_value),0) v FROM sampath_damage_import_items WHERE import_id=$id"));
        if ((int)$t['c'] === 0) mysqli_query($conn, "DELETE FROM sampath_damage_imports WHERE id=$id");
        else mysqli_query($conn, "UPDATE sampath_damage_imports SET total_rows=" . (int)$t['c'] . ", total_qty=" . (float)$t['q'] . ", total_value=" . (float)$t['v'] . " WHERE id=$id");
        bdi_json(['ok' => true]);
    }
    bdi_json(['ok' => false, 'msg' => 'Unknown action.']);
}
if (($_GET['ajax'] ?? '') === 'items') {
    $id = (int)($_GET['id'] ?? 0); $items = [];
    $q = mysqli_query($conn, "SELECT * FROM sampath_damage_import_items WHERE import_id=$id AND outlet_code='TRANSFER' ORDER BY bill_number, product_code");
    if ($q) while ($r = mysqli_fetch_assoc($q)) $items[] = $r;
    $masters = [];
    foreach (im_load_masters($conn) as $m) $masters[(string)$m['code']] = 1;
    foreach ($items as &$it) $it['in_report'] = isset($masters[trim($it['product_code'])]) ? 1 : 0;
    unset($it);
    bdi_json(['ok' => true, 'items' => $items]);
}

$imports = [];
$q = mysqli_query($conn, "SELECT i.* FROM sampath_damage_imports i WHERE EXISTS (SELECT 1 FROM sampath_damage_import_items x WHERE x.import_id = i.id AND x.outlet_code='TRANSFER') ORDER BY i.damage_date DESC, i.id DESC");
if ($q) while ($r = mysqli_fetch_assoc($q)) $imports[] = $r;
$sum = ['n' => count($imports), 'rows' => 0, 'qty' => 0, 'val' => 0];
foreach ($imports as $r) { $sum['rows'] += $r['total_rows']; $sum['qty'] += $r['total_qty']; $sum['val'] += $r['total_value']; }
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
  <h2><i class="fa-solid fa-triangle-exclamation"></i> Bulk Damage Import</h2>
  <div class="sub">Upload the LeverEDGE <b>Stock Transfer Report</b> Excel. Lines moved <b>MAIN GODOWN → DAMAGE GODOWN</b> are imported as damages: the units go into <b>Damaged Stock</b> of the SKU Code and (optional) out of Good stock. They show in the Sampath Inventory Report and the Inventory Reconciliation Report.</div>

  <div class="idm-kp">
    <div><small>Imports</small><b><?php echo number_format($sum['n']); ?></b></div>
    <div><small>Damage lines</small><b><?php echo number_format($sum['rows']); ?></b></div>
    <div><small>Damage units</small><b class="negq"><?php echo number_format($sum['qty'], 0); ?></b></div>
    <div><small>Value (SIP rate)</small><b><?php echo number_format($sum['val'], 2); ?></b></div>
  </div>

  <div class="idm-card">
    <div class="idm-drop" id="idmDrop" onclick="document.getElementById('idmFile').click()">
      <i class="fa-solid fa-cloud-arrow-up"></i><div id="idmDropTxt">Drop the Stock Transfer Excel here or click to choose (.xlsx)</div>
    </div>
    <input type="file" id="idmFile" accept=".xlsx" style="display:none">
    <div class="idm-msg" id="idmMsg"></div>
  </div>

  <h3 style="font-size:15px;margin:6px 0 8px;">Imported damage transfers</h3>
  <div class="idm-wrap">
  <table class="idm-t">
    <thead><tr><th>#</th><th>Damage date</th><th>Reason / remark</th><th>File</th><th class="n">Lines</th><th class="n">Units</th><th class="n">Value</th><th>Imported by</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($imports as $r): $id = (int)$r['id']; ?>
      <tr>
        <td>#<?php echo $id; ?></td><td><b><?php echo date('d/m/Y', strtotime($r['damage_date'])); ?></b></td>
        <td style="white-space:normal;max-width:260px;"><?php echo htmlspecialchars($r['remark'] ?: '—'); ?></td>
        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['filename']); ?>"><?php echo htmlspecialchars($r['filename']); ?></td>
        <td class="n"><?php echo (int)$r['total_rows']; ?></td><td class="n"><?php echo number_format($r['total_qty'], 0); ?></td><td class="n"><?php echo number_format($r['total_value'], 2); ?></td>
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
    <h3><i class="fa-solid fa-triangle-exclamation"></i> Import damages</h3>
    <div id="idmSummary" style="font-size:13px;color:#334155;background:#f8fafc;border:1px solid #e2e6f0;border-radius:8px;padding:10px 12px;margin-bottom:12px;"></div>
    <div class="fld"><label>Damage date</label><input class="idm-in" type="date" id="idmDate"></div>
    <div class="fld"><label>Reason / remark</label><textarea class="idm-in" id="idmReason" rows="3" maxlength="400" placeholder="e.g. Packaging defects returned to RS"></textarea></div>
    <label style="display:flex;gap:8px;align-items:flex-start;font-size:13px;margin-bottom:6px;"><input type="checkbox" id="idmDeduct" checked style="margin-top:3px;"> <span><b>Also take these units out of Good stock</b> (the transfer moves them from MAIN GODOWN to DAMAGE GODOWN)</span></label>
    <div class="idm-msg" id="idmMMsg"></div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;">
      <button class="idm-b l" onclick="idmClose('idmModal')">Cancel</button>
      <button class="idm-b g" id="idmGo" onclick="idmImport()"><i class="fa-solid fa-file-import"></i> Import</button>
    </div>
  </div>
</div>

<div class="idm-modal" id="idmView" onclick="if(event.target===this)idmClose('idmView')">
  <div class="idm-mb" style="max-width:1150px;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;"><h3 style="margin:0;" id="idmVT">Damage lines</h3>
      <button class="idm-b r s" style="margin-left:auto;" onclick="idmDelLines()"><i class="fa-solid fa-trash"></i> Delete selected</button>
      <button class="idm-b l s" onclick="idmClose('idmView')">Close</button></div>
    <div class="idm-wrap" style="max-height:62vh;"><table class="idm-t"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.idm-ic').forEach(c=>c.checked=this.checked)"></th><th>Ref no</th><th>Date</th><th>SKU code</th><th>Product</th><th>Reason</th><th class="n">Units</th><th class="n">Value</th><th>Good stock</th><th>Inventory item</th></tr></thead><tbody id="idmVB"></tbody></table></div>
  </div>
</div>

<script>
const $i = id => document.getElementById(id);
const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmt = (n, d) => (+n || 0).toLocaleString('en-US', {minimumFractionDigits:d || 0, maximumFractionDigits:d || 0});
const fdt = s => s ? s.substring(8, 10) + '/' + s.substring(5, 7) + '/' + s.substring(0, 4) : '—';
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
    fetch('bulk_damage_import.php', {method:'POST', body:fd_, credentials:'same-origin'}).then(r => r.json()).then(d => {
        $i('idmDropTxt').textContent = f.name;
        if (!d.ok) { idmMsg($i('idmMsg'), 'err', d.msg); return; }
        $i('idmMsg').className = 'idm-msg';
        let h = '<b>' + d.rows + '</b> damage line(s) · <b>' + fmt(d.qty) + '</b> units · value <b>' + fmt(d.value, 2) + '</b>';
        if (d.dups) h += '<div style="color:#92400e;margin-top:4px;">' + d.dups + ' line(s) were already imported and will be skipped.</div>';
        if (d.skipped) h += '<div style="color:#92400e;margin-top:4px;">' + d.skipped + ' line(s) are not transfers into the damage godown and are skipped.</div>';
        h += d.notfound ? '<div style="color:#991b1b;margin-top:4px;">' + d.notfound + ' SKU code(s) are not in the inventory items: ' + esc(d.notfound_list.join(', ')) + '</div>' : '<div style="color:#166534;margin-top:4px;">All SKU codes match the inventory items.</div>';
        $i('idmSummary').innerHTML = h; $i('idmDate').value = d.date; $i('idmReason').value = ''; $i('idmMMsg').className = 'idm-msg';
        $i('idmGo').disabled = false; $i('idmGo').innerHTML = '<i class="fa-solid fa-file-import"></i> Import'; $i('idmModal').classList.add('open');
    }).catch(e => idmMsg($i('idmMsg'), 'err', 'Upload failed: ' + e.message));
}
function idmImport(){
    if (!$i('idmDate').value) { idmMsg($i('idmMMsg'), 'err', 'Select the damage date.'); return; }
    $i('idmGo').disabled = true; $i('idmGo').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Importing…';
    const f = new FormData(); f.append('action', 'import'); f.append('xlsx_file', idmFile); f.append('damage_date', $i('idmDate').value); f.append('remark', $i('idmReason').value);
    if ($i('idmDeduct').checked) f.append('deduct_good', '1');
    fetch('bulk_damage_import.php', {method:'POST', body:f, credentials:'same-origin'}).then(r => r.json()).then(d => {
        if (!d.ok) { idmMsg($i('idmMMsg'), 'err', d.msg); $i('idmGo').disabled = false; $i('idmGo').innerHTML = '<i class="fa-solid fa-file-import"></i> Import'; return; }
        idmMsg($i('idmMMsg'), 'ok', d.rows + ' damage line(s) imported.'); setTimeout(() => location.reload(), 700);
    }).catch(e => { idmMsg($i('idmMMsg'), 'err', 'Import failed: ' + e.message); $i('idmGo').disabled = false; });
}
function idmPost(data){ const f = new FormData(); Object.keys(data).forEach(k => f.append(k, data[k])); return fetch('bulk_damage_import.php', {method:'POST', body:f, credentials:'same-origin'}).then(r => r.json()); }
function idmDel(id){
    if (!confirm('Delete import #' + id + '? The damaged stock is removed and any good-stock deduction is put back.')) return;
    idmPost({action:'delete_import', id:id}).then(d => { if (d.ok) location.reload(); else alert(d.msg); });
}
function idmView(id){
    idmViewId = id; $i('idmVT').textContent = 'Damage lines — import #' + id; $i('idmVB').innerHTML = '<tr><td colspan="10" style="padding:18px;color:#94a3b8;">Loading…</td></tr>'; $i('idmView').classList.add('open');
    fetch('bulk_damage_import.php?ajax=items&id=' + id, {credentials:'same-origin'}).then(r => r.json()).then(d => {
        $i('idmVB').innerHTML = d.items.map(i => '<tr><td><input type="checkbox" class="idm-ic" value="' + i.id + '"></td><td><b>' + esc(i.bill_number) + '</b></td><td>' + fdt(i.bill_date) + '</td><td>' + esc(i.product_code) + '</td><td>' + esc(i.product_description) + '</td><td style="white-space:normal;max-width:220px;">' + esc(i.reason) + '</td><td class="n">' + fmt(i.damage_qty) + '</td><td class="n">' + fmt(i.damage_value, 2) + '</td><td>' + (i.adjustment_id ? '<span class="negq">−' + fmt(i.damage_qty) + '</span>' : '—') + '</td><td>' + (i.in_report == 1 ? '<span style="color:#166534;font-weight:700;">&#10003; matched</span>' : '<span style="color:#991b1b;font-weight:700;">&#10007; not in items</span>') + '</td></tr>').join('') || '<tr><td colspan="10" style="padding:18px;color:#94a3b8;">No lines.</td></tr>';
    });
}
function idmDelLines(){
    const ids = Array.from(document.querySelectorAll('.idm-ic:checked')).map(c => c.value);
    if (!ids.length) { alert('Tick the lines to delete.'); return; }
    if (!confirm('Delete ' + ids.length + ' line(s)? Their damaged stock and good-stock deduction are removed.')) return;
    idmPost({action:'delete_items', id:idmViewId, ids:ids.join(',')}).then(d => { if (d.ok) location.reload(); else alert(d.msg); });
}
</script>
