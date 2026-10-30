<?php
// ============================================================
//  import_sampath_damages.php  –  IMPORT SAMPATH DAMAGES   (Sampath Uploads)
//
//  Upload the Invoice Wise Sales Excel. The SECOND tab ("Details Report") is read and every row that has a
//  "Damage-Expiry Shortage Qty" is imported as a damage line (with its Damage-Expiry Shortage Value, bill,
//  outlet, product, batch). After the file is read a modal asks for the DAMAGE DATE and a REMARK, then Import.
//  Imported damages are listed below; a whole import or a single line can be deleted.
//
//  Tables: sampath_damage_imports (one row per import), sampath_damage_import_items (the damage lines).
//  Rows already imported (same bill + product + batch + qty) are skipped, so a file cannot be imported twice.
// ============================================================
include 'config.php';
include_once 'auth.php';
requireLogin();
if (session_status() === PHP_SESSION_NONE) session_start();
mysqli_report(MYSQLI_REPORT_OFF);
$who = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_damage_imports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    damage_date DATE NOT NULL,
    remark VARCHAR(500) NULL,
    filename VARCHAR(255) NULL,
    total_rows INT NOT NULL DEFAULT 0,
    total_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_value DECIMAL(16,2) NOT NULL DEFAULT 0,
    created_by VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (damage_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_damage_import_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_id INT NOT NULL,
    damage_date DATE NOT NULL,
    bill_number VARCHAR(80) NULL,
    bill_date DATE NULL,
    delivery_date DATE NULL,
    outlet_code VARCHAR(100) NULL,
    outlet_name VARCHAR(255) NULL,
    salesperson_name VARCHAR(255) NULL,
    basepack_code VARCHAR(100) NULL,
    product_code VARCHAR(100) NULL,
    product_description VARCHAR(500) NULL,
    batch_code VARCHAR(100) NULL,
    tur DECIMAL(15,4) NULL,
    damage_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
    damage_value DECIMAL(16,4) NOT NULL DEFAULT 0,
    INDEX idx_import (import_id),
    INDEX idx_prod (product_code),
    INDEX idx_dup (bill_number, product_code, batch_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function idm_json($a) { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

/** Reads the 2nd tab ("Details Report") of the Invoice Wise Sales Excel; returns [rows, error]. Only rows with a Damage-Expiry Shortage Qty <> 0. */
function idm_read($tmp) {
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
    $sheet = $details ?: ($targets[1] ?? null);      // 2nd tab
    if (!$sheet) { $zip->close(); return [null, 'The second tab (Details Report) was not found in this Excel.']; }
    $sst = $zip->getFromName('xl/sharedStrings.xml'); $sty = $zip->getFromName('xl/styles.xml'); $sx = $zip->getFromName($sheet);
    $zip->close();
    if (!$sx) return [null, 'Cannot read the second tab of this Excel.'];

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
        foreach ($row as $v) if (strtolower(trim((string)$v)) === 'damage-expiry shortage qty') { $hr = $rn; break 2; }
    }
    if ($hr === null) return [null, 'Column "Damage-Expiry Shortage Qty" was not found in the second tab.'];
    foreach ($matrix[$hr] as $i => $h) $map[strtolower(trim((string)$h))] = $i;
    $g = function ($row, $name) use ($map) { return isset($map[$name]) ? ($row[$map[$name]] ?? null) : null; };
    $num = function ($v) { $v = str_replace(',', '', trim((string)$v)); return is_numeric($v) ? (float)$v : 0.0; };
    $dt  = function ($v) { $v = trim((string)$v); if ($v === '') return null; if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10); if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]); $t = strtotime($v); return $t ? date('Y-m-d', $t) : null; };
    $out = [];
    foreach ($matrix as $rn => $row) {
        if ($rn <= $hr) continue;
        $bill = trim((string)$g($row, 'bill number'));
        if ($bill === '') continue;                                // skips the GRAND TOTAL row
        $q = $num($g($row, 'damage-expiry shortage qty'));
        if ($q == 0) continue;
        $out[] = [
            'bill_number' => $bill, 'bill_date' => $dt($g($row, 'bill date')), 'delivery_date' => $dt($g($row, 'delivery date')),
            'outlet_code' => trim((string)$g($row, 'outlet code')), 'outlet_name' => trim((string)$g($row, 'outlet name')),
            'salesperson_name' => trim((string)$g($row, 'salesperson name')), 'basepack_code' => trim((string)$g($row, 'basepack code')),
            'product_code' => trim((string)$g($row, 'product code')), 'product_description' => trim((string)$g($row, 'product description')),
            'batch_code' => trim((string)$g($row, 'batch code')), 'tur' => $num($g($row, 'tur')),
            'damage_qty' => $q, 'damage_value' => $num($g($row, 'damage-expiry shortage value')),
        ];
    }
    return [$out, ''];
}

/** Which product codes exist in the inventory report (purchase items master / sales data)? returns [code => true] */
function idm_known($conn, $codes) {
    $codes = array_values(array_unique(array_filter(array_map('strval', $codes), 'strlen')));
    $known = [];
    foreach (array_chunk($codes, 400) as $ch) {
        $in = implode(',', array_map(function ($c) use ($conn) { return "'" . mysqli_real_escape_string($conn, $c) . "'"; }, $ch));
        foreach (["SELECT DISTINCT item_code AS c FROM unilever_purchase_items WHERE item_code IN ($in)",
                  "SELECT DISTINCT product_code AS c FROM invoice_wise_sales_data WHERE product_code IN ($in)"] as $sql) {
            $q = @mysqli_query($conn, $sql);
            if ($q) while ($r = mysqli_fetch_assoc($q)) $known[trim($r['c'])] = true;
        }
    }
    return $known;
}

/* ── JSON endpoints ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    if ($act === 'preview' || $act === 'import') {
        if (!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) idm_json(['ok' => false, 'msg' => 'File upload failed. Please try again.']);
        $f = $_FILES['xlsx_file'];
        if (!in_array(strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)), ['xlsx'], true)) idm_json(['ok' => false, 'msg' => 'Please upload an .xlsx file.']);
        [$rows, $err] = idm_read($f['tmp_name']);
        if ($rows === null) idm_json(['ok' => false, 'msg' => $err]);
        if (!$rows) idm_json(['ok' => false, 'msg' => 'No rows with a Damage-Expiry Shortage Qty were found in the second tab.']);

        /* rows already imported (bill + product + batch + qty) */
        $dupe = function ($r) use ($conn) {
            $b = mysqli_real_escape_string($conn, $r['bill_number']); $p = mysqli_real_escape_string($conn, $r['product_code']); $bt = mysqli_real_escape_string($conn, $r['batch_code']);
            $q = mysqli_query($conn, "SELECT 1 FROM sampath_damage_import_items WHERE bill_number='$b' AND product_code='$p' AND batch_code='$bt' AND damage_qty=" . (float)$r['damage_qty'] . " LIMIT 1");
            return $q && mysqli_num_rows($q) > 0;
        };
        $new = []; $dups = 0;
        foreach ($rows as $r) { if ($dupe($r)) $dups++; else $new[] = $r; }
        $tq = 0; $tv = 0; $dates = [];
        foreach ($new as $r) { $tq += $r['damage_qty']; $tv += $r['damage_value']; if ($r['delivery_date']) $dates[$r['delivery_date']] = 1; }
        ksort($dates);

        if ($act === 'preview') {
            $kn = idm_known($conn, array_column($new, 'product_code')); $unk = [];
            foreach ($new as $r) if (!isset($kn[$r['product_code']])) $unk[$r['product_code']] = $r['product_description'];
            idm_json(['ok' => true, 'rows' => count($new), 'dups' => $dups, 'qty' => $tq, 'value' => $tv, 'unmatched' => count($unk), 'unmatched_list' => array_slice(array_map(function ($c, $n) { return $c . ' ' . $n; }, array_keys($unk), $unk), 0, 6),
                      'date' => $dates ? array_key_first($dates) : date('Y-m-d'), 'sample' => array_slice($new, 0, 8)]);
        }
        $dd = trim($_POST['damage_date'] ?? ''); $remark = trim($_POST['remark'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dd) || !checkdate((int)substr($dd, 5, 2), (int)substr($dd, 8, 2), (int)substr($dd, 0, 4))) idm_json(['ok' => false, 'msg' => 'Please select a valid damage date.']);
        if (!$new) idm_json(['ok' => false, 'msg' => 'Every row in this file was already imported.']);
        $n = count($new); $name = mb_substr($f['name'], 0, 255);
        $st = mysqli_prepare($conn, "INSERT INTO sampath_damage_imports (damage_date, remark, filename, total_rows, total_qty, total_value, created_by) VALUES (?,?,?,?,?,?,?)");
        mysqli_stmt_bind_param($st, 'sssidds', $dd, $remark, $name, $n, $tq, $tv, $who);
        mysqli_stmt_execute($st);
        $iid = mysqli_insert_id($conn);
        $ins = mysqli_prepare($conn, "INSERT INTO sampath_damage_import_items (import_id, damage_date, bill_number, bill_date, delivery_date, outlet_code, outlet_name, salesperson_name, basepack_code, product_code, product_description, batch_code, tur, damage_qty, damage_value) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($new as $r) {
            mysqli_stmt_bind_param($ins, 'isssssssssssddd', $iid, $dd, $r['bill_number'], $r['bill_date'], $r['delivery_date'], $r['outlet_code'], $r['outlet_name'], $r['salesperson_name'], $r['basepack_code'], $r['product_code'], $r['product_description'], $r['batch_code'], $r['tur'], $r['damage_qty'], $r['damage_value']);
            mysqli_stmt_execute($ins);
        }
        idm_json(['ok' => true, 'id' => $iid, 'rows' => $n, 'dups' => $dups]);
    }
    if ($act === 'delete_import') {
        $id = (int)($_POST['id'] ?? 0);
        mysqli_query($conn, "DELETE FROM sampath_damage_import_items WHERE import_id=$id");
        mysqli_query($conn, "DELETE FROM sampath_damage_imports WHERE id=$id");
        idm_json(['ok' => true]);
    }
    if ($act === 'delete_items') {
        $id = (int)($_POST['id'] ?? 0);
        $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
        if (!$ids) idm_json(['ok' => false, 'msg' => 'Nothing selected.']);
        mysqli_query($conn, "DELETE FROM sampath_damage_import_items WHERE import_id=$id AND id IN (" . implode(',', $ids) . ")");
        $t = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(SUM(damage_qty),0) q, COALESCE(SUM(damage_value),0) v FROM sampath_damage_import_items WHERE import_id=$id"));
        if ((int)$t['c'] === 0) mysqli_query($conn, "DELETE FROM sampath_damage_imports WHERE id=$id");
        else mysqli_query($conn, "UPDATE sampath_damage_imports SET total_rows=" . (int)$t['c'] . ", total_qty=" . (float)$t['q'] . ", total_value=" . (float)$t['v'] . " WHERE id=$id");
        idm_json(['ok' => true]);
    }
    idm_json(['ok' => false, 'msg' => 'Unknown action.']);
}
if (($_GET['ajax'] ?? '') === 'items') {
    $id = (int)($_GET['id'] ?? 0); $items = [];
    $q = mysqli_query($conn, "SELECT * FROM sampath_damage_import_items WHERE import_id=$id ORDER BY bill_number, product_code");
    if ($q) while ($r = mysqli_fetch_assoc($q)) $items[] = $r;
    $kn = idm_known($conn, array_column($items, 'product_code'));
    foreach ($items as &$it) $it['in_report'] = isset($kn[trim($it['product_code'])]) ? 1 : 0;
    unset($it);
    idm_json(['ok' => true, 'items' => $items]);
}

$imports = [];
$q = mysqli_query($conn, "SELECT * FROM sampath_damage_imports ORDER BY damage_date DESC, id DESC");
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
.idm-in{border:1.5px solid #e2e6f0;border-radius:8px;padding:8px 10px;font-size:13px;width:100%;box-sizing:border-box;}
.idm-modal{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;z-index:9999;padding:16px;} .idm-modal.open{display:flex;}
.idm-mb{background:#fff;border-radius:12px;width:100%;max-width:520px;padding:20px;box-shadow:0 20px 50px rgba(0,0,0,.25);max-height:92vh;overflow:auto;}
.idm-mb h3{margin:0 0 12px;font-size:16px;} .idm-mb .fld{margin-bottom:12px;} .idm-mb .fld label{display:block;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:4px;}
.idm-msg{border-radius:8px;padding:10px 14px;font-size:13px;margin-top:10px;display:none;} .idm-msg.err{display:block;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;} .idm-msg.ok{display:block;background:#dcfce7;border:1px solid #86efac;color:#166534;}
</style>
<div class="idm">
  <h2><i class="fa-solid fa-file-import"></i> Import Sampath Damages</h2>
  <div class="sub">Upload the Invoice Wise Sales Excel. The <b>2nd tab (Details Report)</b> is read and every row with a <b>Damage-Expiry Shortage Qty</b> is imported as a damage.</div>

  <div class="idm-kp">
    <div><small>Imports</small><b><?php echo number_format($sum['n']); ?></b></div>
    <div><small>Damage lines</small><b><?php echo number_format($sum['rows']); ?></b></div>
    <div><small>Damage qty</small><b><?php echo number_format($sum['qty'], 0); ?></b></div>
    <div><small>Damage value</small><b><?php echo number_format($sum['val'], 2); ?></b></div>
  </div>

  <div class="idm-card">
    <div class="idm-drop" id="idmDrop" onclick="document.getElementById('idmFile').click()">
      <i class="fa-solid fa-cloud-arrow-up"></i><div id="idmDropTxt">Drop the Excel here or click to choose (.xlsx)</div>
    </div>
    <input type="file" id="idmFile" accept=".xlsx" style="display:none">
    <div class="idm-msg" id="idmMsg"></div>
  </div>

  <h3 style="font-size:15px;margin:6px 0 8px;">Imported damages</h3>
  <div class="idm-wrap">
  <table class="idm-t">
    <thead><tr><th>#</th><th>Damage date</th><th>Remark</th><th>File</th><th class="n">Lines</th><th class="n">Qty</th><th class="n">Value</th><th>Imported by</th><th></th></tr></thead>
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

<!-- Import modal: date + remark -->
<div class="idm-modal" id="idmModal" onclick="if(event.target===this)idmClose('idmModal')">
  <div class="idm-mb">
    <h3><i class="fa-solid fa-file-import"></i> Import damages</h3>
    <div id="idmSummary" style="font-size:13px;color:#334155;background:#f8fafc;border:1px solid #e2e6f0;border-radius:8px;padding:10px 12px;margin-bottom:12px;"></div>
    <div class="fld"><label>Damage date</label><input class="idm-in" type="date" id="idmDate"></div>
    <div class="fld"><label>Remark</label><textarea class="idm-in" id="idmRemark" rows="3" maxlength="500" placeholder="Optional remark"></textarea></div>
    <div class="idm-msg" id="idmMMsg"></div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;">
      <button class="idm-b l" onclick="idmClose('idmModal')">Cancel</button>
      <button class="idm-b g" id="idmGo" onclick="idmImport()"><i class="fa-solid fa-file-import"></i> Import</button>
    </div>
  </div>
</div>

<!-- View lines modal -->
<div class="idm-modal" id="idmView" onclick="if(event.target===this)idmClose('idmView')">
  <div class="idm-mb" style="max-width:1100px;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;"><h3 style="margin:0;" id="idmVT">Damage lines</h3>
      <button class="idm-b r s" style="margin-left:auto;" onclick="idmDelLines()"><i class="fa-solid fa-trash"></i> Delete selected</button>
      <button class="idm-b l s" onclick="idmClose('idmView')">Close</button></div>
    <div class="idm-wrap" style="max-height:62vh;"><table class="idm-t"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.idm-ic').forEach(c=>c.checked=this.checked)"></th><th>Bill no</th><th>Bill date</th><th>Outlet</th><th>Product code</th><th>Product</th><th>Batch</th><th class="n">Qty</th><th class="n">Value</th><th>Inventory report</th></tr></thead><tbody id="idmVB"></tbody></table></div>
  </div>
</div>

<script>
const $i = id => document.getElementById(id);
const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmt = (n, d) => (+n || 0).toLocaleString('en-US', {minimumFractionDigits:d == null ? 2 : d, maximumFractionDigits:d == null ? 2 : d});
const fd = s => s ? s.substring(8, 10) + '/' + s.substring(5, 7) + '/' + s.substring(0, 4) : '—';
let idmFile = null, idmViewId = 0;
function idmClose(id){ $i(id).classList.remove('open'); }
function idmMsg(el, cls, txt){ el.className = 'idm-msg ' + cls; el.textContent = txt; }

$i('idmDrop').addEventListener('dragover', e => { e.preventDefault(); });
$i('idmDrop').addEventListener('drop', e => { e.preventDefault(); if (e.dataTransfer.files[0]) idmPick(e.dataTransfer.files[0]); });
$i('idmFile').addEventListener('change', e => { if (e.target.files[0]) idmPick(e.target.files[0]); });

function idmPick(f){
    if (!/\.xlsx$/i.test(f.name)) { idmMsg($i('idmMsg'), 'err', 'Please choose an .xlsx file.'); return; }
    idmFile = f;
    $i('idmDrop').classList.add('has'); $i('idmDropTxt').textContent = f.name + ' — reading…';
    const fd_ = new FormData(); fd_.append('action', 'preview'); fd_.append('xlsx_file', f);
    fetch('import_sampath_damages.php', {method:'POST', body:fd_, credentials:'same-origin'}).then(r => r.json()).then(d => {
        $i('idmDropTxt').textContent = f.name;
        if (!d.ok) { idmMsg($i('idmMsg'), 'err', d.msg); return; }
        $i('idmMsg').className = 'idm-msg';
        $i('idmSummary').innerHTML = '<b>' + d.rows + '</b> damage line(s) found · qty <b>' + fmt(d.qty, 0) + '</b> · value <b>' + fmt(d.value) + '</b>'
            + (d.dups ? '<div style="color:#92400e;margin-top:4px;">' + d.dups + ' line(s) were already imported and will be skipped.</div>' : '')
            + (d.unmatched ? '<div style="color:#991b1b;margin-top:4px;">' + d.unmatched + ' product code(s) are not in the Sampath inventory report items: ' + esc(d.unmatched_list.join(', ')) + '</div>' : '<div style="color:#166534;margin-top:4px;">All products match the Sampath inventory report items.</div>');
        $i('idmDate').value = d.date; $i('idmRemark').value = ''; $i('idmMMsg').className = 'idm-msg';
        $i('idmGo').disabled = false; $i('idmModal').classList.add('open');
    }).catch(e => idmMsg($i('idmMsg'), 'err', 'Upload failed: ' + e.message));
}
function idmImport(){
    if (!$i('idmDate').value) { idmMsg($i('idmMMsg'), 'err', 'Select the damage date.'); return; }
    $i('idmGo').disabled = true; $i('idmGo').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Importing…';
    const f = new FormData(); f.append('action', 'import'); f.append('xlsx_file', idmFile); f.append('damage_date', $i('idmDate').value); f.append('remark', $i('idmRemark').value);
    fetch('import_sampath_damages.php', {method:'POST', body:f, credentials:'same-origin'}).then(r => r.json()).then(d => {
        if (!d.ok) { idmMsg($i('idmMMsg'), 'err', d.msg); $i('idmGo').disabled = false; $i('idmGo').innerHTML = '<i class="fa-solid fa-file-import"></i> Import'; return; }
        idmMsg($i('idmMMsg'), 'ok', d.rows + ' damage line(s) imported.'); setTimeout(() => location.reload(), 700);
    }).catch(e => { idmMsg($i('idmMMsg'), 'err', 'Import failed: ' + e.message); $i('idmGo').disabled = false; });
}
function idmPost(data){ const f = new FormData(); Object.keys(data).forEach(k => f.append(k, data[k])); return fetch('import_sampath_damages.php', {method:'POST', body:f, credentials:'same-origin'}).then(r => r.json()); }
function idmDel(id){
    if (!confirm('Delete import #' + id + ' and all its damage lines?')) return;
    idmPost({action:'delete_import', id:id}).then(d => { if (d.ok) location.reload(); else alert(d.msg); });
}
function idmView(id){
    idmViewId = id; $i('idmVT').textContent = 'Damage lines — import #' + id; $i('idmVB').innerHTML = '<tr><td colspan="9" style="padding:18px;color:#94a3b8;">Loading…</td></tr>'; $i('idmView').classList.add('open');
    fetch('import_sampath_damages.php?ajax=items&id=' + id, {credentials:'same-origin'}).then(r => r.json()).then(d => {
        $i('idmVB').innerHTML = d.items.map(i => '<tr><td><input type="checkbox" class="idm-ic" value="' + i.id + '"></td><td><b>' + esc(i.bill_number) + '</b></td><td>' + fd(i.bill_date) + '</td><td>' + esc(i.outlet_name) + '</td><td>' + esc(i.product_code) + '</td><td>' + esc(i.product_description) + '</td><td>' + esc(i.batch_code) + '</td><td class="n">' + fmt(i.damage_qty, 0) + '</td><td class="n">' + fmt(i.damage_value) + '</td><td>' + (i.in_report == 1 ? '<span style="color:#166534;font-weight:700;">&#10003; matched</span>' : '<span style="color:#991b1b;font-weight:700;">&#10007; not in report</span>') + '</td></tr>').join('') || '<tr><td colspan="10" style="padding:18px;color:#94a3b8;">No lines.</td></tr>';
    });
}
function idmDelLines(){
    const ids = Array.from(document.querySelectorAll('.idm-ic:checked')).map(c => c.value);
    if (!ids.length) { alert('Tick the lines to delete.'); return; }
    if (!confirm('Delete ' + ids.length + ' damage line(s)?')) return;
    idmPost({action:'delete_items', id:idmViewId, ids:ids.join(',')}).then(d => { if (d.ok) location.reload(); else alert(d.msg); });
}
</script>
