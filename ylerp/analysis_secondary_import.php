<?php
/**
 * analysis_secondary_import.php — Analysis → Import Secondary Invoices
 * Imports the FIRST tab ("Summary Report") of the LeverEDGE "Invoice Wise Sales" workbook (one row per bill: salesperson,
 * outlet, beat, bill number, MOC, bill date, discounts, tax, bill value, returns, final amount, delivery …) into its OWN tables
 * (analysis_sec_uploads / analysis_sec_invoices) — separate from the normal secondary-invoice import. Each upload can be viewed and deleted.
 * The workbook is read as a stream (XMLReader), so a 100 000+ row / 20 MB file does not need much memory.
 */
date_default_timezone_set('Asia/Colombo');
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
@set_time_limit(0); @ini_set('memory_limit', '1024M');

function as_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function as_json($a) { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit; }
function as_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function as_user() { return $_SESSION['username'] ?? ($_SESSION['user_name'] ?? ($_SESSION['full_name'] ?? 'system')); }

/* ─────────── tables ─────────── */
as_q($conn, "CREATE TABLE IF NOT EXISTS analysis_sec_uploads (
    id INT AUTO_INCREMENT PRIMARY KEY, original_filename VARCHAR(255), sheet_name VARCHAR(120), report_from DATE NULL, report_to DATE NULL,
    bill_from DATE NULL, bill_to DATE NULL, total_rows INT NOT NULL DEFAULT 0, skipped_dups INT NOT NULL DEFAULT 0,
    sum_gross DECIMAL(18,2) DEFAULT 0, sum_bill_value DECIMAL(18,2) DEFAULT 0, sum_final DECIMAL(18,2) DEFAULT 0,
    uploaded_by VARCHAR(100), uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
as_q($conn, "CREATE TABLE IF NOT EXISTS analysis_sec_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY, upload_id INT NOT NULL, sr_no INT NULL,
    salesperson_code VARCHAR(40), salesperson_name VARCHAR(150), outlet_hul_code VARCHAR(60), beat VARCHAR(150),
    bill_number VARCHAR(60) NOT NULL, moc VARCHAR(10), bill_date DATE NULL, outlet_code VARCHAR(40), party_name VARCHAR(200), outlet_category VARCHAR(80),
    free_qty DECIMAL(15,2) DEFAULT 0, gross_sales DECIMAL(15,2) DEFAULT 0, scheme_disc DECIMAL(15,2) DEFAULT 0, rs_discount DECIMAL(15,2) DEFAULT 0, tot_disc DECIMAL(15,2) DEFAULT 0,
    total_discount DECIMAL(15,2) DEFAULT 0, taxable_amount DECIMAL(15,2) DEFAULT 0, tax_amount DECIMAL(15,2) DEFAULT 0, bill_value DECIMAL(15,2) DEFAULT 0,
    good_returns_value DECIMAL(15,2) DEFAULT 0, damage_expiry_shortage_value DECIMAL(15,2) DEFAULT 0, final_bill_amount DECIMAL(15,2) DEFAULT 0,
    sch_per_unit DECIMAL(15,2) DEFAULT 0, bill_cap VARCHAR(20), delivery_person_name VARCHAR(150), delivery_date DATE NULL, bill_posting_date DATE NULL,
    UNIQUE KEY uq_bill (bill_number, bill_date), KEY k_upload (upload_id), KEY k_date (bill_date), KEY k_outlet (outlet_hul_code), KEY k_sp (salesperson_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ─────────── streaming xlsx reader ─────────── */
function as_col_idx($ref) { $n = 0; for ($i = 0, $l = strlen($ref); $i < $l; $i++) { $c = ord($ref[$i]); if ($c < 65 || $c > 90) break; $n = $n * 26 + ($c - 64); } return $n - 1; }
function as_shared_strings($zipPath) {
    $out = []; $z = new XMLReader();
    if (!@$z->open('zip://' . $zipPath . '#xl/sharedStrings.xml')) return $out;
    while ($z->read()) {
        if ($z->nodeType === XMLReader::ELEMENT && $z->name === 'si') {
            $t = ''; $depth = $z->depth;
            if (!$z->isEmptyElement) while ($z->read() && !($z->nodeType === XMLReader::END_ELEMENT && $z->name === 'si' && $z->depth === $depth)) {
                if ($z->nodeType === XMLReader::ELEMENT && $z->name === 't') $t .= $z->readString();
            }
            $out[] = $t;
        }
    }
    $z->close(); return $out;
}
/** [sheet name, path inside zip] of the FIRST sheet */
function as_first_sheet($zipPath) {
    $za = new ZipArchive(); if ($za->open($zipPath) !== true) return null;
    $wb = $za->getFromName('xl/workbook.xml'); $rels = $za->getFromName('xl/_rels/workbook.xml.rels'); $za->close();
    if (!$wb) return null;
    $name = 'Sheet1'; $rid = ''; $x = @simplexml_load_string($wb);
    if ($x) { $x->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'); $sh = $x->xpath('//m:sheets/m:sheet'); if ($sh) { $name = (string)$sh[0]['name']; $a = $sh[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships'); $rid = (string)$a['id']; } }
    $path = 'xl/worksheets/sheet1.xml';
    if ($rid !== '' && $rels && ($r = @simplexml_load_string($rels))) foreach ($r->Relationship as $rel) if ((string)$rel['Id'] === $rid) { $t = (string)$rel['Target']; $path = ltrim(strpos($t, '/') === 0 ? $t : 'xl/' . $t, '/'); break; }
    return [$name, $path];
}
/** calls $cb($rowNumber, array colIndex => value) for every row of the sheet */
function as_each_row($zipPath, $sheetPath, array $shared, callable $cb) {
    $z = new XMLReader(); if (!@$z->open('zip://' . $zipPath . '#' . $sheetPath)) return false;
    $doc = new DOMDocument();
    while ($z->read()) {
        if ($z->nodeType !== XMLReader::ELEMENT || $z->name !== 'row') continue;
        $rn = (int)$z->getAttribute('r'); $cells = [];
        $node = $z->expand($doc);
        foreach ($node->childNodes as $c) {
            if ($c->nodeName !== 'c') continue;
            $ci = as_col_idx($c->getAttribute('r')); $t = $c->getAttribute('t'); $val = null;
            if ($t === 'inlineStr') { $val = $c->textContent; }
            else { $vn = null; foreach ($c->childNodes as $k) if ($k->nodeName === 'v') { $vn = $k->textContent; break; }
                if ($vn !== null) $val = $t === 's' ? ($shared[(int)$vn] ?? '') : $vn; }
            if ($val !== null && $val !== '') $cells[$ci] = $val;
        }
        if ($cb($rn, $cells) === false) break;
    }
    $z->close(); return true;
}
function as_num($v) { $v = trim(str_replace([',', ' '], '', (string)$v)); return ($v !== '' && is_numeric($v)) ? (float)$v : 0.0; }
function as_date($v) {
    $v = trim((string)$v); if ($v === '') return null;
    if (is_numeric($v) && (float)$v > 20000) return date('Y-m-d', (int)round(((float)$v - 25569) * 86400));
    foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'd-M-Y', 'd M Y'] as $f) { $d = DateTime::createFromFormat($f, $v); if ($d && $d->format($f) === $v) return $d->format('Y-m-d'); }
    $t = strtotime($v); return $t ? date('Y-m-d', $t) : null;
}
function as_norm($s) { return preg_replace('/[^a-z0-9]+/', '', strtolower((string)$s)); }

/* header label (normalised) → [db column, type]  (s = text, n = number, d = date, i = integer) */
$AS_COLS = [
    'srno' => ['sr_no', 'i'], 'salespersoncode' => ['salesperson_code', 's'], 'salespersonname' => ['salesperson_name', 's'], 'outlethulcode' => ['outlet_hul_code', 's'], 'beat' => ['beat', 's'],
    'billnumber' => ['bill_number', 's'], 'moc' => ['moc', 's'], 'billdate' => ['bill_date', 'd'], 'outletcode' => ['outlet_code', 's'], 'partyname' => ['party_name', 's'], 'outletcategory' => ['outlet_category', 's'],
    'freeqty' => ['free_qty', 'n'], 'grosssales' => ['gross_sales', 'n'], 'schemedisc' => ['scheme_disc', 'n'], 'rsdiscount' => ['rs_discount', 'n'], 'totdisc' => ['tot_disc', 'n'], 'totaldiscount' => ['total_discount', 'n'],
    'taxableamount' => ['taxable_amount', 'n'], 'taxamount' => ['tax_amount', 'n'], 'billvalue' => ['bill_value', 'n'], 'goodreturnsvalue' => ['good_returns_value', 'n'],
    'damageexpiryshortagevalue' => ['damage_expiry_shortage_value', 'n'], 'finalbillamounttobecollected' => ['final_bill_amount', 'n'], 'schperunitaftertax' => ['sch_per_unit', 'n'],
    'billcap' => ['bill_cap', 's'], 'deliverypersonname' => ['delivery_person_name', 's'], 'deliverydate' => ['delivery_date', 'd'], 'billpostingdate' => ['bill_posting_date', 'd'],
];

/* ─────────── actions ─────────── */
$act = $_POST['action'] ?? ($_GET['action'] ?? '');
if ($act === 'upload') {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $er = $_FILES['file']['error'] ?? -1;
        as_json(['ok' => false, 'msg' => $er === UPLOAD_ERR_INI_SIZE || $er === UPLOAD_ERR_FORM_SIZE ? 'The file is bigger than the server allows (upload_max_filesize = ' . ini_get('upload_max_filesize') . ', post_max_size = ' . ini_get('post_max_size') . '). Raise both in php.ini and try again.' : 'Upload failed (code ' . $er . ').']);
    }
    $f = $_FILES['file']; $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') as_json(['ok' => false, 'msg' => 'Please upload the .xlsx file.']);
    $sheet = as_first_sheet($f['tmp_name']); if (!$sheet) as_json(['ok' => false, 'msg' => 'Cannot read the workbook.']);
    $shared = as_shared_strings($f['tmp_name']);

    $map = null; $meta = ['report' => null, 'from' => null, 'to' => null];
    $rows = []; $minD = $maxD = null; $n = 0; $dups = 0; $bad = 0; $sg = $sv = $sf = 0.0;
    mysqli_query($conn, "INSERT INTO analysis_sec_uploads (original_filename, sheet_name, uploaded_by) VALUES ('" . mysqli_real_escape_string($conn, $f['name']) . "', '" . mysqli_real_escape_string($conn, $sheet[0]) . "', '" . mysqli_real_escape_string($conn, as_user()) . "')");
    $uid = (int)mysqli_insert_id($conn); if (!$uid) as_json(['ok' => false, 'msg' => 'Could not create the upload record.']);
    $cols = array_values(array_map(fn($c) => $c[0], $AS_COLS));
    $flush = function () use (&$rows, &$n, &$dups, $conn, $cols) {
        if (!$rows) return;
        $sql = "INSERT IGNORE INTO analysis_sec_invoices (upload_id, " . implode(',', $cols) . ") VALUES " . implode(',', $rows);
        mysqli_query($conn, $sql); $ins = max(0, mysqli_affected_rows($conn)); $n += $ins; $dups += count($rows) - $ins; $rows = [];
    };
    $headerErr = true;
    as_each_row($f['tmp_name'], $sheet[1], $shared, function ($rn, $cells) use (&$map, &$meta, &$rows, &$minD, &$maxD, &$bad, &$sg, &$sv, &$sf, &$headerErr, $AS_COLS, $uid, $conn, $flush, $cols) {
        if ($map === null) {
            $vals = array_map('as_norm', $cells);
            if ($rn <= 12) {                                              // report header block: Date / Bill From Date / Bill To Date
                foreach ($cells as $ci => $v) { $k = as_norm($v); $nx = $cells[$ci + 1] ?? '';
                    if ($k === 'date') $meta['report'] = as_date($nx); elseif ($k === 'billfromdate') $meta['from'] = as_date($nx); elseif ($k === 'billtodate') $meta['to'] = as_date($nx); }
            }
            if (in_array('billnumber', $vals, true) && in_array('salespersoncode', $vals, true)) {
                $map = []; foreach ($cells as $ci => $v) { $k = as_norm($v); if (isset($AS_COLS[$k])) $map[$ci] = $AS_COLS[$k]; }
                $headerErr = false;
            }
            return true;
        }
        $row = []; foreach ($map as $ci => $def) $row[$def[0]] = $cells[$ci] ?? '';
        $bn = trim((string)($row['bill_number'] ?? '')); $sr = trim((string)($row['sr_no'] ?? ''));
        if ($bn === '' || !is_numeric($sr)) { if ($bn !== '' || $sr !== '') $bad++; return true; }      // totals / blank rows
        $vals = [$uid];
        foreach ($AS_COLS as $def) {
            [$c, $t] = $def; $v = $row[$c] ?? '';
            if ($t === 'n') $vals[] = round(as_num($v), 2);
            elseif ($t === 'i') $vals[] = (int)$v;
            elseif ($t === 'd') { $d = as_date($v); $vals[] = $d ? "'" . $d . "'" : 'NULL'; if ($c === 'bill_date' && $d) { if ($minD === null || $d < $minD) $minD = $d; if ($maxD === null || $d > $maxD) $maxD = $d; } }
            else $vals[] = "'" . mysqli_real_escape_string($conn, trim((string)$v)) . "'";
        }
        $sg += as_num($row['gross_sales'] ?? 0); $sv += as_num($row['bill_value'] ?? 0); $sf += as_num($row['final_bill_amount'] ?? 0);
        $rows[] = '(' . implode(',', $vals) . ')'; if (count($rows) >= 500) $flush();
        return true;
    });
    $flush();
    if ($headerErr) { mysqli_query($conn, "DELETE FROM analysis_sec_uploads WHERE id = $uid"); as_json(['ok' => false, 'msg' => 'Could not find the header row (Sr No, Salesperson code … Bill Number …) on the first tab.']); }
    if ($n === 0) { mysqli_query($conn, "DELETE FROM analysis_sec_uploads WHERE id = $uid"); as_json(['ok' => false, 'msg' => $dups ? "Nothing new: all $dups bills are already imported." : 'No bill rows found on the first tab.']); }
    $q = mysqli_query($conn, "SELECT COALESCE(SUM(gross_sales),0), COALESCE(SUM(bill_value),0), COALESCE(SUM(final_bill_amount),0), MIN(bill_date), MAX(bill_date) FROM analysis_sec_invoices WHERE upload_id = $uid"); $t = mysqli_fetch_row($q);
    $nz = function ($d) { return $d ? "'" . mysqli_real_escape_string($GLOBALS['conn'], $d) . "'" : 'NULL'; };
    mysqli_query($conn, "UPDATE analysis_sec_uploads SET total_rows = $n, skipped_dups = $dups, sum_gross = " . (float)$t[0] . ", sum_bill_value = " . (float)$t[1] . ", sum_final = " . (float)$t[2] . ", bill_from = {$nz($t[3])}, bill_to = {$nz($t[4])}, report_from = {$nz($meta['from'])}, report_to = {$nz($meta['to'])} WHERE id = $uid");
    as_json(['ok' => true, 'msg' => "Imported $n bills from the first tab (\"{$sheet[0]}\")" . ($dups ? ", $dups already existed and were skipped" : '') . '.', 'id' => $uid]);
}
if ($act === 'delete') {
    $id = (int)($_POST['id'] ?? 0); if (!$id) as_json(['ok' => false, 'msg' => 'Invalid upload.']);
    mysqli_query($conn, "DELETE FROM analysis_sec_invoices WHERE upload_id = $id"); $rows = mysqli_affected_rows($conn);
    mysqli_query($conn, "DELETE FROM analysis_sec_uploads WHERE id = $id");
    as_json(['ok' => true, 'msg' => "Upload deleted ($rows bills removed)."]);
}
if ($act === 'rows') {                                                              // paged bill list of one upload
    $id = (int)($_GET['id'] ?? 0); $page = max(1, (int)($_GET['page'] ?? 1)); $per = 100; $s = trim($_GET['q'] ?? '');
    $w = "upload_id = $id"; if ($s !== '') { $e = mysqli_real_escape_string($conn, $s); $w .= " AND (bill_number LIKE '%$e%' OR party_name LIKE '%$e%' OR outlet_hul_code LIKE '%$e%' OR salesperson_name LIKE '%$e%' OR beat LIKE '%$e%')"; }
    $tot = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM analysis_sec_invoices WHERE $w"))[0];
    $r = mysqli_query($conn, "SELECT * FROM analysis_sec_invoices WHERE $w ORDER BY bill_date, id LIMIT $per OFFSET " . (($page - 1) * $per)); $out = [];
    while ($r && ($x = mysqli_fetch_assoc($r))) $out[] = $x;
    as_json(['ok' => true, 'total' => $tot, 'page' => $page, 'pages' => max(1, (int)ceil($tot / $per)), 'rows' => $out]);
}

/* ─────────── page ─────────── */
$ups = []; $r = as_q($conn, "SELECT * FROM analysis_sec_uploads ORDER BY uploaded_at DESC, id DESC"); while ($r && ($x = mysqli_fetch_assoc($r))) $ups[] = $x;
$tr = as_q($conn, "SELECT COUNT(*), COALESCE(SUM(bill_value),0), COALESCE(SUM(final_bill_amount),0), MIN(bill_date), MAX(bill_date) FROM analysis_sec_invoices"); $tt = $tr ? mysqli_fetch_row($tr) : [0, 0, 0, null, null];
include 'header.php';
?>
<style>
.as{max-width:1400px;margin:0 auto;padding:16px;font-family:'Inter',system-ui,sans-serif;color:#111827}.as h2{margin:0 0 3px;font-size:22px}.as .sub{color:#6b7280;font-size:13px;margin:0 0 14px}
.as .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:14px}.as .btn{padding:8px 16px;border:none;border-radius:8px;background:#1e3a8a;color:#fff;font:600 13px inherit;cursor:pointer;display:inline-flex;gap:6px;align-items:center}.as .btn.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe}.as .btn.r{background:#dc2626}.as .btn[disabled]{opacity:.5;cursor:default}
.as .kp{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,190px),1fr));gap:10px;margin-bottom:14px}.as .k{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px}.as .k span{font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:700;display:block}.as .k b{font-size:19px;white-space:nowrap}
.as .dz{border:2px dashed #c7d2fe;border-radius:12px;padding:22px;text-align:center;background:#f8faff;cursor:pointer}.as .dz.on{background:#eef2ff;border-color:#1e3a8a}
.as .tw{overflow:auto;border:1px solid #e5e7eb;border-radius:10px;background:#fff}.as table{width:100%;border-collapse:collapse;font-size:13px}.as th{background:#1e3a8a;color:#fff;padding:9px 10px;text-align:left;white-space:nowrap}.as td{padding:8px 10px;border-bottom:1px solid #f1f5f9}.as td.r,.as th.r{text-align:right;font-variant-numeric:tabular-nums}
.as .pg{height:8px;background:#e5e7eb;border-radius:99px;overflow:hidden;margin-top:10px;display:none}.as .pg i{display:block;height:100%;width:40%;background:#1e3a8a;animation:asb 1.1s infinite linear}@keyframes asb{0%{margin-left:-40%}100%{margin-left:100%}}
.asm{position:fixed;inset:0;background:rgba(15,23,42,.6);display:none;align-items:center;justify-content:center;z-index:2147483000;padding:14px}.asm.open{display:flex}.asm .bx{background:#fff;border-radius:14px;width:100%;max-width:1500px;height:92vh;display:flex;flex-direction:column;overflow:hidden}.asm .hd{padding:12px 18px;background:#0f172a;color:#fff;display:flex;gap:12px;align-items:center}.asm .bd{overflow:auto;flex:1;padding:0 0 8px}.asm table{font-size:12px}.asm th{position:sticky;top:0}
</style>
<div class="as">
  <h2><i class="fa-solid fa-file-import" style="color:#1e3a8a"></i> Import Secondary Invoices <small style="font-size:13px;color:#6b7280;font-weight:500">· Analysis</small></h2>
  <p class="sub">Upload the LeverEDGE <b>Invoice Wise Sales</b> workbook. The <b>first tab (Summary Report)</b> — one row per bill — is saved into the Analysis tables. A bill already imported (same bill number + bill date) is skipped, so the same file can be uploaded again safely.</p>
  <div class="kp"><div class="k"><span>Bills stored</span><b><?= number_format((int)$tt[0]) ?></b></div><div class="k"><span>Bill value</span><b>Rs. <?= number_format((float)$tt[1], 2) ?></b></div><div class="k"><span>To be collected</span><b>Rs. <?= number_format((float)$tt[2], 2) ?></b></div><div class="k"><span>Bill dates</span><b style="font-size:15px"><?= $tt[3] ? date('d M Y', strtotime($tt[3])) . ' – ' . date('d M Y', strtotime($tt[4])) : '—' ?></b></div></div>
  <div class="card">
    <div class="dz" id="dz" onclick="document.getElementById('fi').click()"><i class="fa-solid fa-cloud-arrow-up" style="font-size:28px;color:#1e3a8a"></i><div style="font-weight:700;margin-top:6px" id="dzT">Click to choose the .xlsx file (or drop it here)</div><div style="color:#6b7280;font-size:12px">Only the first tab is read · large files (100 000+ rows) are fine</div></div>
    <input type="file" id="fi" accept=".xlsx" style="display:none">
    <div style="margin-top:12px;display:flex;gap:10px;align-items:center"><button class="btn" id="goB" disabled onclick="go()"><i class="fa-solid fa-database"></i> Import to database</button><span id="msg" style="font-size:13px"></span></div>
    <div class="pg" id="pg"><i></i></div>
  </div>
  <div class="tw"><table><thead><tr><th>#</th><th>File</th><th>Bill dates</th><th class="r">Bills</th><th class="r">Skipped (already in)</th><th class="r">Gross sales</th><th class="r">Bill value</th><th class="r">To be collected</th><th>Uploaded</th><th></th></tr></thead><tbody>
  <?php foreach ($ups as $i => $u): ?><tr><td><?= $i + 1 ?></td><td><i class="fa-solid fa-file-excel" style="color:#166534"></i> <?= as_h($u['original_filename']) ?></td><td style="white-space:nowrap"><?= $u['bill_from'] ? date('d M Y', strtotime($u['bill_from'])) . ' – ' . date('d M Y', strtotime($u['bill_to'])) : '—' ?></td><td class="r"><b><?= number_format((int)$u['total_rows']) ?></b></td><td class="r"><?= (int)$u['skipped_dups'] ?: '—' ?></td><td class="r"><?= number_format((float)$u['sum_gross'], 2) ?></td><td class="r"><?= number_format((float)$u['sum_bill_value'], 2) ?></td><td class="r"><?= number_format((float)$u['sum_final'], 2) ?></td><td style="font-size:12px"><?= date('d M Y H:i', strtotime($u['uploaded_at'])) ?><br><small style="color:#6b7280"><?= as_h($u['uploaded_by']) ?></small></td>
    <td style="white-space:nowrap"><button class="btn o" onclick="view(<?= (int)$u['id'] ?>,'<?= as_h(addslashes($u['original_filename'])) ?>')"><i class="fa-solid fa-eye"></i> View</button> <button class="btn r" onclick="del(<?= (int)$u['id'] ?>,<?= (int)$u['total_rows'] ?>)"><i class="fa-solid fa-trash"></i> Delete</button></td></tr>
  <?php endforeach; if (!$ups): ?><tr><td colspan="10" style="text-align:center;color:#9ca3af;padding:28px">Nothing imported yet.</td></tr><?php endif; ?></tbody></table></div>
</div>
<div class="asm" id="vm"><div class="bx"><div class="hd"><b id="vT">Bills</b><input id="vQ" type="search" placeholder="bill no, party, outlet code, salesperson, beat" style="padding:6px 10px;border-radius:8px;border:none;width:340px;max-width:50%"><span id="vI" style="opacity:.8;font-size:12px"></span><span style="margin-left:auto"></span><button class="btn o" id="vP">‹</button><button class="btn o" id="vN">›</button><button class="btn o" id="vX">Close</button></div><div class="bd"><table><thead><tr><th>Sr</th><th>Salesperson</th><th>Outlet HUL Code</th><th>Beat</th><th>Bill No</th><th>MOC</th><th>Bill Date</th><th>Party</th><th>Category</th><th class="r">Gross</th><th class="r">Discount</th><th class="r">Taxable</th><th class="r">Tax</th><th class="r">Bill Value</th><th class="r">Returns</th><th class="r">Dmg/Exp</th><th class="r">Final</th><th>Delivery</th></tr></thead><tbody id="vB"></tbody></table></div></div></div>
<script>
(function(){
  var $=function(i){return document.getElementById(i);}, esc=function(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}, m=function(v){return Number(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});};
  var file=null; document.body.appendChild($('vm'));
  function pick(f){ if(!f) return; file=f; $('dzT').textContent=f.name+' ('+(f.size/1048576).toFixed(1)+' MB)'; $('goB').disabled=false; $('msg').textContent=''; }
  $('fi').onchange=function(){ pick(this.files[0]); };
  var dz=$('dz'); dz.ondragover=function(e){e.preventDefault();dz.classList.add('on');}; dz.ondragleave=function(){dz.classList.remove('on');}; dz.ondrop=function(e){e.preventDefault();dz.classList.remove('on');pick(e.dataTransfer.files[0]);};
  window.go=function(){ if(!file) return; var fd=new FormData(); fd.append('action','upload'); fd.append('file',file); $('goB').disabled=true; $('pg').style.display='block'; $('msg').style.color='#374151'; $('msg').textContent='Importing — a big file can take a minute or two, please keep this page open…';
    fetch('analysis_secondary_import.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.text();}).then(function(t){ var j; try{j=JSON.parse(t);}catch(e){ throw new Error('Unexpected server reply'+(t?': '+t.replace(/<[^>]*>/g,' ').slice(0,200):'')); }
      $('pg').style.display='none'; $('msg').style.color=j.ok?'#15803d':'#b91c1c'; $('msg').textContent=j.msg; if(j.ok) setTimeout(function(){location.reload();},1500); else $('goB').disabled=false;
    }).catch(function(e){ $('pg').style.display='none'; $('msg').style.color='#b91c1c'; $('msg').textContent=e.message; $('goB').disabled=false; }); };
  window.del=function(id,n){ if(!confirm('Delete this upload and its '+n.toLocaleString()+' bills? This cannot be undone.')) return; var fd=new FormData(); fd.append('action','delete'); fd.append('id',id);
    fetch('analysis_secondary_import.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){ alert(j.msg); if(j.ok) location.reload(); }).catch(function(){ alert('Could not delete.'); }); };
  var V={id:0,page:1,pages:1,t:null}; function loadV(){ fetch('analysis_secondary_import.php?action=rows&id='+V.id+'&page='+V.page+'&q='+encodeURIComponent($('vQ').value),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
      V.pages=j.pages; $('vI').textContent=j.total.toLocaleString()+' bills · page '+j.page+' / '+j.pages; $('vP').disabled=j.page<=1; $('vN').disabled=j.page>=j.pages;
      $('vB').innerHTML=j.rows.map(function(x){ return '<tr><td>'+esc(x.sr_no)+'</td><td>'+esc(x.salesperson_name)+'<div style="color:#6b7280">'+esc(x.salesperson_code)+'</div></td><td>'+esc(x.outlet_hul_code)+'</td><td>'+esc(x.beat)+'</td><td><b>'+esc(x.bill_number)+'</b></td><td>'+esc(x.moc)+'</td><td style="white-space:nowrap">'+esc(x.bill_date)+'</td><td>'+esc(x.party_name)+'<div style="color:#6b7280">'+esc(x.outlet_code)+'</div></td><td>'+esc(x.outlet_category)+'</td><td class="r">'+m(x.gross_sales)+'</td><td class="r">'+m(x.total_discount)+'</td><td class="r">'+m(x.taxable_amount)+'</td><td class="r">'+m(x.tax_amount)+'</td><td class="r"><b>'+m(x.bill_value)+'</b></td><td class="r">'+m(x.good_returns_value)+'</td><td class="r">'+m(x.damage_expiry_shortage_value)+'</td><td class="r"><b>'+m(x.final_bill_amount)+'</b></td><td style="white-space:nowrap">'+esc(x.delivery_date||'')+'</td></tr>'; }).join('')||'<tr><td colspan="18" style="padding:20px;color:#9ca3af">No bills.</td></tr>'; }); }
  window.view=function(id,name){ V.id=id; V.page=1; $('vT').textContent=name; $('vQ').value=''; $('vm').classList.add('open'); document.body.style.overflow='hidden'; loadV(); };
  $('vX').onclick=function(){ $('vm').classList.remove('open'); document.body.style.overflow=''; }; $('vP').onclick=function(){ if(V.page>1){V.page--;loadV();} }; $('vN').onclick=function(){ if(V.page<V.pages){V.page++;loadV();} };
  $('vQ').oninput=function(){ clearTimeout(V.t); V.t=setTimeout(function(){ V.page=1; loadV(); },300); };
})();
</script>
<?php include 'footer.php'; ?>
