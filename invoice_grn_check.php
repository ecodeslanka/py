<?php
// ============================================================
//  invoice_grn_check.php
//  INVOICE GRN CHECK — scan a LeverEDGE tax invoice PDF with Gemini, check it against the Sampath Age Analysis data
//  and produce a GRN Adjustment Note.
//
//  1. Upload the tax invoice PDF. Gemini (key from AI Settings) reads the Tax Invoice No., date, purchaser and the totals
//     (taxable value, VAT, bill value). The PDF itself is NOT stored on the server and is NOT edited.
//  2. The invoice is looked up in the Age Analysis data (secondary_invoice_import_details by bill no) and shows the same fields
//     as the Sampath Age Analysis report: secondary amount, GRN value / date (sampath_grn_summary), credit note, cheque.
//  2b. "Download invoice as Excel" converts the whole PDF (header, every invoice line, the bill statement lines, totals) to an Excel
//     file EXACTLY as printed - it is a read-only conversion, nothing is changed.
//  3. "GRN Adjustment Note" opens a black & white printable note (Save as PDF) that REFERS to the original tax invoice and shows
//     the original taxable / VAT / bill value, the GRN accepted value with its tax split, and the difference. It is a separate
//     document - it is not a tax invoice and does not replace the original.
// ============================================================
include 'config.php';
include_once 'auth.php';
requireLogin();
require_once 'item_match_lib.php';     // im_gemini_key(), im_table()
if (session_status() === PHP_SESSION_NONE) session_start();
$who = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';
$e = function ($v) use ($conn) { return mysqli_real_escape_string($conn, (string)$v); };

/* ── Gemini: read the tax invoice PDF ── */
function ig_num($v) { return is_numeric($v) ? (float)$v : (float)str_replace(',', '', preg_replace('/[^0-9.,\-]/', '', (string)$v)); }
function ig_scan($key, $pdf_bytes, &$err) {
    $prompt = "This PDF is a Sri Lankan 'TAX INVOICE' printed from LeverEDGE (page 1 header, item pages, and a bill statement at the end). "
        . "Read ONLY these fields and reply with JSON: "
        . "{\"tax_invoice_no\": string (the value after 'Tax Invoice No.'), \"invoice_no\": string (the 'Invoice No' of the bill statement, '' if absent), "
        . "\"invoice_date\": string (Date of Invoice, as printed), \"supplier_name\": string, \"purchaser_name\": string, \"purchaser_tin\": string, "
        . "\"taxable_value\": number (Total Taxable Value), \"vat_rate\": number (the % in 'VAT Value 18%'), \"vat_value\": number, "
        . "\"gross_amount\": number, \"std_discount\": number, \"bill_value\": number (Bill Value), \"net_amount\": number (Net Amount To Be Collected), "
        . "\"sku_count\": number}. Numbers are plain numbers without commas. If a field is not found use null.";
    $body = json_encode([
        'contents' => [['parts' => [['inline_data' => ['mime_type' => 'application/pdf', 'data' => base64_encode($pdf_bytes)]], ['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 1024, 'responseMimeType' => 'application/json', 'thinkingConfig' => ['thinkingBudget' => 0]],
    ]);
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(GEMINI_MODEL) . ':generateContent';
    $res = false; $http = 0; $cerr = '';
    for ($try = 0; $try < 2 && $res === false; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 70,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key]]);
        $res = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res === false) $cerr = curl_error($ch);
        curl_close($ch);
    }
    if ($res === false) { $err = 'Gemini request failed: ' . $cerr; return null; }
    $j = json_decode($res, true);
    if ($http >= 400) { $err = 'Gemini error ' . $http . ': ' . ($j['error']['message'] ?? 'unknown'); return null; }
    $txt = '';
    foreach (($j['candidates'][0]['content']['parts'] ?? []) as $p) $txt .= $p['text'] ?? '';
    $d = json_decode(trim($txt), true);
    if (is_array($d) && isset($d[0]) && is_array($d[0])) $d = $d[0];
    if (!is_array($d)) { $err = 'Gemini returned an unreadable answer.'; return null; }
    foreach (['taxable_value','vat_rate','vat_value','gross_amount','std_discount','bill_value','net_amount','sku_count'] as $k) $d[$k] = isset($d[$k]) && $d[$k] !== null ? ig_num($d[$k]) : null;
    foreach (['tax_invoice_no','invoice_no','invoice_date','supplier_name','purchaser_name','purchaser_tin'] as $k) $d[$k] = trim((string)($d[$k] ?? ''));
    return $d;
}

/* ── Gemini: read EVERY line of the invoice (for the as-printed Excel export) ── */
function ig_scan_full($key, $pdf_bytes, &$err) {
    $prompt = "This PDF is a Sri Lankan 'TAX INVOICE' printed from LeverEDGE. Read it completely and reply with JSON only: "
        . "{\"header\": {\"tax_invoice_no\":str,\"invoice_no\":str,\"date_of_invoice\":str,\"date_of_supply\":str,\"supplier_name\":str,\"supplier_tin\":str,\"purchaser_name\":str,\"purchaser_tin\":str}, "
        . "\"lines\": [[ref(int), sku_code_and_description(str), quantity(int), unit_price(num), amount_excl_vat(num)], ...] (every row of the 'Reference / Description of Goods or Services' table on all pages), "
        . "\"statement\": [[sl_no(int), sku_code_and_description(str), sale_cs(int), sale_pcs(int), free_cs(int), free_pcs(int), case_config(int), price_per_unit_tur(num), gross_amount(num), scheme_disc(num), mrp(num)], ...] (every row of the bill statement table), "
        . "\"totals\": {\"total_taxable_value\":num,\"vat_rate\":num,\"vat_value\":num,\"gross_amount\":num,\"scheme_discount\":num,\"std_discount\":num,\"bill_value\":num,\"return_damages\":num,\"net_amount\":num,\"no_of_skus\":int}}. "
        . "Copy the numbers exactly as printed (plain numbers, no commas). Do not skip, merge or invent rows. Descriptions that wrap onto a second line belong to the same row.";
    $body = json_encode([
        'contents' => [['parts' => [['inline_data' => ['mime_type' => 'application/pdf', 'data' => base64_encode($pdf_bytes)]], ['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 60000, 'responseMimeType' => 'application/json', 'thinkingConfig' => ['thinkingBudget' => 0]],
    ]);
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(GEMINI_MODEL) . ':generateContent';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 170,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key]]);
    $res = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($res === false) { $err = 'Gemini request failed: ' . curl_error($ch); curl_close($ch); return null; }
    curl_close($ch);
    $j = json_decode($res, true);
    if ($http >= 400) { $err = 'Gemini error ' . $http . ': ' . ($j['error']['message'] ?? 'unknown'); return null; }
    $txt = '';
    foreach (($j['candidates'][0]['content']['parts'] ?? []) as $p) $txt .= $p['text'] ?? '';
    $d = json_decode(trim($txt), true);
    if (!is_array($d) || !isset($d['lines'])) { $err = 'Gemini returned an unreadable or cut-off answer. Try again.'; return null; }
    return $d;
}

/* ── Age Analysis lookup (same sources as sampath_age_analysis_report.php) ── */
function ig_grn_date_col($conn) {
    $by_name = []; $by_type = [];
    $q = mysqli_query($conn, "SHOW COLUMNS FROM sampath_grn_summary");
    while ($q && $c = mysqli_fetch_assoc($q)) {
        $n = $c['Field']; $t = strtolower($c['Type']);
        if (in_array($n, ['created_at', 'updated_at'], true)) continue;
        if (stripos($n, 'date') !== false) $by_name[] = $n;
        if (strpos($t, 'date') !== false || strpos($t, 'timestamp') !== false) $by_type[] = $n;
    }
    foreach (['grn_date', 'grn_dt', 'date'] as $p) if (in_array($p, $by_name, true)) return $p;
    return $by_name[0] ?? ($by_type[0] ?? null);
}
function ig_lookup($conn, $cands) {
    $out = ['found' => [], 'by' => ''];
    if (!im_table($conn, 'secondary_invoice_import_details')) return $out;
    $e = function ($v) use ($conn) { return mysqli_real_escape_string($conn, (string)$v); };
    $cands = array_values(array_unique(array_filter(array_map('trim', $cands))));
    if (!$cands) return $out;
    $have_grn = im_table($conn, 'sampath_grn_summary');
    $gcol = $have_grn ? ig_grn_date_col($conn) : null;
    $grn_sel  = $have_grn ? "SELECT ref_no, SUM(grn_value) AS grn_value" . ($gcol ? ", MIN(`$gcol`) AS grn_date" : ", NULL AS grn_date") . " FROM sampath_grn_summary GROUP BY ref_no" : "SELECT NULL AS ref_no, 0 AS grn_value, NULL AS grn_date";
    $cn_sel   = im_table($conn, 'sampath_invoice_credit_notes') ? "SELECT bill_no, SUM(amount) AS cn FROM sampath_invoice_credit_notes GROUP BY bill_no" : "SELECT NULL AS bill_no, 0 AS cn";
    $pay_sel  = (im_table($conn, 'sampath_super_payment_invoices') && im_table($conn, 'sampath_super_payments'))
        ? "SELECT spi.bill_no, SUM(spi.amount) AS cheque_value, GROUP_CONCAT(DISTINCT sp.cheque_no SEPARATOR ', ') AS cheque_no, MAX(sp.cheque_date) AS cheque_date
           FROM sampath_super_payment_invoices spi JOIN sampath_super_payments sp ON sp.id = spi.payment_id GROUP BY spi.bill_no"
        : "SELECT NULL AS bill_no, 0 AS cheque_value, NULL AS cheque_no, NULL AS cheque_date";
    $base = "SELECT d.t_code, d.bill_no, d.bill_date, COALESCE(d.party_name, d.customer_name) AS party, d.final_bill_amount AS secondary_value,
                COALESCE(sg.grn_value, 0) AS grn_value, sg.grn_date, COALESCE(cd.cn, 0) AS credit_note,
                COALESCE(pay.cheque_value, 0) AS cheque_value, pay.cheque_no, pay.cheque_date
        FROM secondary_invoice_import_details d
        JOIN (SELECT bill_no, MAX(id) AS max_id FROM secondary_invoice_import_details GROUP BY bill_no) latest ON latest.max_id = d.id
        LEFT JOIN ($grn_sel) sg ON sg.ref_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci
        LEFT JOIN ($cn_sel) cd ON cd.bill_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci
        LEFT JOIN ($pay_sel) pay ON pay.bill_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci";
    $tries = [];
    foreach ($cands as $c) $tries[] = ['exact', "d.bill_no = '" . $e($c) . "'", $c];
    foreach ($cands as $c) $tries[] = ['ends with', "d.bill_no LIKE '%" . $e(addcslashes($c, '%_')) . "'", $c];
    foreach ($cands as $c) if (strlen($c) >= 6) $tries[] = ['contains', "d.bill_no LIKE '%" . $e(addcslashes($c, '%_')) . "%'", $c];
    foreach ($tries as $t) {
        $q = mysqli_query($conn, "$base WHERE {$t[1]} LIMIT 10");
        $rows = [];
        while ($q && $r = mysqli_fetch_assoc($q)) $rows[] = $r;
        if ($rows) { $out['found'] = $rows; $out['by'] = $t[0] . ' "' . $t[2] . '"'; return $out; }
    }
    return $out;
}

/* ── AJAX ── */
$ajax = $_GET['ajax'] ?? '';
if ($ajax === 'scan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(150);
    $key = im_gemini_key();
    if ($key === '') { echo json_encode(['ok' => false, 'error' => 'No Gemini API key is saved in AI Settings.']); exit; }
    $f = $_FILES['pdf'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok' => false, 'error' => 'Upload failed (code ' . (int)($f['error'] ?? -1) . ').']); exit; }
    if ($f['size'] > 12 * 1024 * 1024) { echo json_encode(['ok' => false, 'error' => 'The PDF is larger than 12 MB.']); exit; }
    $bytes = file_get_contents($f['tmp_name']);
    if (substr($bytes, 0, 4) !== '%PDF') { echo json_encode(['ok' => false, 'error' => 'That file is not a PDF.']); exit; }
    $err = '';
    $d = ig_scan($key, $bytes, $err);
    echo json_encode($d ? ['ok' => true, 'data' => $d] : ['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($ajax === 'excel' && $_SERVER['REQUEST_METHOD'] === 'POST') {      // read-only conversion of the invoice to rows for Excel
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(220);
    $key = im_gemini_key();
    if ($key === '') { echo json_encode(['ok' => false, 'error' => 'No Gemini API key is saved in AI Settings.']); exit; }
    $f = $_FILES['pdf'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok' => false, 'error' => 'Upload failed (code ' . (int)($f['error'] ?? -1) . ').']); exit; }
    $bytes = file_get_contents($f['tmp_name']);
    if (substr($bytes, 0, 4) !== '%PDF' || $f['size'] > 12 * 1024 * 1024) { echo json_encode(['ok' => false, 'error' => 'Upload a PDF under 12 MB.']); exit; }
    $err = '';
    $d = ig_scan_full($key, $bytes, $err);
    echo json_encode($d ? ['ok' => true, 'data' => $d] : ['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($ajax === 'lookup') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(ig_lookup($conn, [(string)($_GET['a'] ?? ''), (string)($_GET['b'] ?? '')]), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ── GRN Adjustment Note (black & white print view; the original invoice is not edited) ── */
function ig_f($v) { return number_format((float)$v, 2); }
if (isset($_GET['note']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode((string)($_POST['payload'] ?? ''), true) ?: [];
    $sc = $in['scan'] ?? [];
    $bill = trim((string)($in['bill_no'] ?? ''));
    $lk = $bill !== '' ? ig_lookup($conn, [$bill]) : ['found' => []];       // GRN figures come from the database, not from the browser
    $row = $lk['found'][0] ?? null;
    $rate = (float)($sc['vat_rate'] ?? 18) ?: 18.0;
    $o_tax = (float)($sc['taxable_value'] ?? 0); $o_vat = (float)($sc['vat_value'] ?? 0);
    $o_total = (float)($sc['bill_value'] ?? $sc['net_amount'] ?? 0);
    $grn = $row ? (float)$row['grn_value'] : 0.0;
    $a_total = round($grn, 2);
    $a_tax = round($a_total / (1 + $rate / 100), 2); $a_vat = round($a_total - $a_tax, 2);
    $d_total = round($o_total - $a_total, 2); $d_tax = round($o_tax - $a_tax, 2); $d_vat = round($o_vat - $a_vat, 2);
    $note_no = 'GAN-' . preg_replace('/[^A-Za-z0-9]/', '', $sc['invoice_no'] ?? ($row['bill_no'] ?? $bill)) . '-' . date('ymd');
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><title>GRN Adjustment Note <?php echo htmlspecialchars($note_no); ?></title>
<style>
@page{size:A4 portrait;margin:12mm 12mm 16mm;@bottom-right{content:"Page " counter(page) " of " counter(pages);font:7pt Georgia,serif}@bottom-left{content:"GRN Adjustment Note — not a tax invoice";font:7pt Georgia,serif}}
*{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{margin:0;font-family:"Helvetica Neue",Arial,sans-serif;color:#000;font-size:9.5pt;background:#fff}
.bar{position:sticky;top:0;background:#000;color:#fff;padding:8px 14px;display:flex;gap:10px;align-items:center;font-size:12px}
.bar button{background:#fff;color:#000;border:0;padding:6px 14px;font-weight:700;cursor:pointer}
@media print{.bar{display:none}}
.wrap{padding:6mm 2mm}
.mast{border:2.5pt solid #000;display:flex}
.mast .l{background:#000;color:#fff;padding:8mm 7mm;flex:1;position:relative}
.mast .l:after{content:"";position:absolute;right:0;top:0;bottom:0;width:18mm;background:repeating-linear-gradient(135deg,#fff 0 1.2pt,#000 1.2pt 4pt)}
.mast h1{margin:0;font:900 21pt/1.05 "Arial Black",Impact,Arial,sans-serif;letter-spacing:1.5pt;text-transform:uppercase}
.mast .tag{margin-top:2.5mm;font-size:8pt;letter-spacing:2.5pt;text-transform:uppercase}
.mast .r{width:62mm;padding:4mm;font-size:8pt;line-height:1.6}
.mast .r b{display:inline-block;min-width:19mm;text-transform:uppercase;font-size:6.6pt;letter-spacing:1pt}
.warn{border:1.4pt solid #000;margin:4mm 0;padding:2.5mm 3mm;font-size:8pt;font-weight:700;text-transform:uppercase;letter-spacing:.6pt;background:repeating-linear-gradient(135deg,#fff 0 2pt,#ececec 2pt 4pt)}
h2{font:900 9.5pt Arial,sans-serif;letter-spacing:2pt;text-transform:uppercase;margin:5mm 0 2mm;display:flex;align-items:center;gap:3mm}
h2:after{content:"";flex:1;border-top:1.2pt solid #000}
table{border-collapse:collapse;width:100%}
.kv td{padding:1.4mm 2mm;border-bottom:.5pt solid #000;font-size:9pt} .kv td:first-child{width:38%;font-size:7.5pt;letter-spacing:1pt;text-transform:uppercase}
.cmp th{background:#000;color:#fff;padding:2mm 3mm;font-size:7.5pt;letter-spacing:1pt;text-transform:uppercase;text-align:right} .cmp th:first-child{text-align:left}
.cmp td{padding:2mm 3mm;border-bottom:.5pt solid #000;text-align:right;font-variant-numeric:tabular-nums} .cmp td:first-child{text-align:left}
.cmp tr.tot td{border-top:2.2pt double #000;border-bottom:0;font-weight:900;background:repeating-linear-gradient(135deg,#fff 0 1.5pt,#e0e0e0 1.5pt 3pt);font-size:10.5pt}
.cmp tr.sub td{background:#e6e6e6;font-weight:700}
.glance{display:grid;grid-template-columns:repeat(3,1fr);border:1.2pt solid #000;margin:4mm 0}
.glance div{padding:3mm 3.5mm;border-right:1.2pt solid #000}.glance div:last-child{border-right:0}
.glance small{display:block;font-size:6.6pt;letter-spacing:1.2pt;text-transform:uppercase;border-bottom:1.2pt solid #000;padding-bottom:1mm;margin-bottom:1.5mm}
.glance strong{font:900 14pt/1.1 "Arial Black",Arial,sans-serif}
.note{border:1pt solid #000;padding:3mm;font-size:8pt;line-height:1.5;margin-top:4mm}
.sign{display:flex;gap:10mm;margin-top:16mm}.sign div{flex:1;border-top:1.2pt solid #000;padding-top:1.5mm;font-size:7pt;letter-spacing:1.5pt;text-transform:uppercase;text-align:center}
</style></head><body>
<div class="bar"><b>GRN Adjustment Note — print / PDF preview</b><button onclick="window.print()">Print / Save as PDF</button><button onclick="window.close()">Close</button><span style="opacity:.7">Choose “Save as PDF” as the destination.</span></div>
<div class="wrap">
<div class="mast"><div class="l"><h1>GRN<br>Adjustment Note</h1><div class="tag">Invoice value vs goods accepted (GRN)</div></div>
  <div class="r"><b>Note no</b> <?php echo htmlspecialchars($note_no); ?><br><b>Date</b> <?php echo date('Y-m-d'); ?><br><b>Prepared by</b> <?php echo htmlspecialchars($who); ?><br><b>Refers to</b> Tax Invoice<br><?php echo htmlspecialchars($sc['tax_invoice_no'] ?? $bill); ?></div></div>
<div class="warn">This is a reconciliation / adjustment note that refers to the original tax invoice. It is NOT a tax invoice and does not replace or amend the original invoice.</div>

<h2>Referenced invoice</h2>
<table class="kv">
  <tr><td>Tax invoice no</td><td><b><?php echo htmlspecialchars($sc['tax_invoice_no'] ?? ''); ?></b></td></tr>
  <tr><td>Invoice no (bill statement)</td><td><?php echo htmlspecialchars($sc['invoice_no'] ?? ''); ?></td></tr>
  <tr><td>Date of invoice</td><td><?php echo htmlspecialchars($sc['invoice_date'] ?? ''); ?></td></tr>
  <tr><td>Supplier</td><td><?php echo htmlspecialchars($sc['supplier_name'] ?? ''); ?></td></tr>
  <tr><td>Purchaser</td><td><?php echo htmlspecialchars(($sc['purchaser_name'] ?? '') . (!empty($sc['purchaser_tin']) ? '  (TIN ' . $sc['purchaser_tin'] . ')' : '')); ?></td></tr>
  <tr><td>Age Analysis bill no</td><td><?php echo $row ? htmlspecialchars($row['bill_no'] . ' · ' . $row['party']) : '— not found in the Age Analysis data'; ?></td></tr>
  <tr><td>GRN</td><td><?php echo $row ? 'GRN value Rs. ' . ig_f($grn) . ($row['grn_date'] ? ' · GRN date ' . htmlspecialchars(substr((string)$row['grn_date'], 0, 10)) : '') : '—'; ?></td></tr>
</table>

<div class="glance">
  <div><small>Original bill value</small><strong>Rs. <?php echo ig_f($o_total); ?></strong></div>
  <div><small>GRN accepted value</small><strong>Rs. <?php echo ig_f($a_total); ?></strong></div>
  <div><small>Difference (not accepted)</small><strong>Rs. <?php echo ig_f($d_total); ?></strong></div>
</div>

<h2>Value and tax split</h2>
<table class="cmp">
  <thead><tr><th>Particulars</th><th>Original invoice</th><th>GRN accepted</th><th>Difference</th></tr></thead>
  <tbody>
    <tr><td>Taxable value</td><td><?php echo ig_f($o_tax); ?></td><td><?php echo ig_f($a_tax); ?></td><td><?php echo ig_f($d_tax); ?></td></tr>
    <tr><td>VAT @ <?php echo rtrim(rtrim(number_format($rate, 2), '0'), '.'); ?>%</td><td><?php echo ig_f($o_vat); ?></td><td><?php echo ig_f($a_vat); ?></td><td><?php echo ig_f($d_vat); ?></td></tr>
    <tr class="tot"><td>Total (bill value)</td><td><?php echo ig_f($o_total); ?></td><td><?php echo ig_f($a_total); ?></td><td><?php echo ig_f($d_total); ?></td></tr>
  </tbody>
</table>
<?php if ($row): ?>
<h2>Age Analysis position</h2>
<table class="cmp">
  <tbody>
    <tr><td>Secondary final bill amount (system)</td><td><?php echo ig_f($row['secondary_value']); ?></td></tr>
    <tr><td>GRN value</td><td><?php echo ig_f($row['grn_value']); ?></td></tr>
    <tr><td>Credit notes applied</td><td><?php echo ig_f($row['credit_note']); ?></td></tr>
    <tr><td>Cheque paid<?php echo $row['cheque_no'] ? ' (' . htmlspecialchars($row['cheque_no']) . ($row['cheque_date'] ? ', ' . htmlspecialchars($row['cheque_date']) : '') . ')' : ''; ?></td><td><?php echo ig_f($row['cheque_value']); ?></td></tr>
    <tr class="sub"><td>GRN − cheque − credit note</td><td><?php echo ig_f($row['grn_value'] - $row['cheque_value'] - $row['credit_note']); ?></td></tr>
  </tbody>
</table>
<?php endif; ?>
<div class="note"><b>Basis.</b> The GRN accepted value is taken as the amount payable. Its taxable value and VAT are worked out at the invoice's VAT rate (accepted ÷ (1 + rate)); the difference column is the original invoice minus the GRN accepted value. Item lines of the invoice are not changed or listed here.</div>
<div class="sign"><div>Prepared by</div><div>Checked by</div><div>Approved by</div></div>
</div>
<script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 500); });</script>
</body></html><?php
    exit;
}

$has_key = im_gemini_key() !== '';
include 'header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
<style>
.ig{padding:0 4px;}
.ig h2{font-size:22px;font-weight:800;margin:0 0 4px;}
.ig .sub{font-size:12px;color:#8892a4;margin-bottom:16px;}
.ig-card{background:#fff;border:1.5px solid #e2e6f0;border-radius:14px;padding:16px 20px;margin-bottom:18px;}
.ig-f{display:flex;gap:12px;flex-wrap:wrap;align-items:center;}
.ig-f input[type=file]{border:1.5px dashed #c7cde0;border-radius:10px;padding:12px;background:#fafbff;min-width:280px;max-width:100%;}
.ig-b{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;background:#0a0c14;color:#fff;text-decoration:none;}
.ig-b.g{background:#14532d;} .ig-b.l{background:#f7f8fc;color:#475569;border:1.5px solid #e2e6f0;} .ig-b[disabled]{opacity:.5;cursor:not-allowed;}
.ig-grid{display:grid;grid-template-columns:minmax(0,5fr) minmax(0,7fr);gap:18px;align-items:start;}
.ig-pdf{width:100%;height:78vh;border:1.5px solid #e2e6f0;border-radius:12px;background:#f3f4f6;}
table.ig-t{width:100%;border-collapse:collapse;font-size:13px;}
table.ig-t td,table.ig-t th{padding:7px 10px;border-bottom:1px solid #eef0f6;text-align:left;vertical-align:top;}
table.ig-t th{background:#0a0c14;color:#fff;font-size:11px;text-transform:uppercase;} table.ig-t td.n,table.ig-t th.n{text-align:right;font-variant-numeric:tabular-nums;}
table.ig-t td.k{color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.5px;width:42%;}
.pill{display:inline-block;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700;} .pill.ok{background:#dcfce7;color:#166534;} .pill.bad{background:#fee2e2;color:#991b1b;} .pill.warn{background:#fef3c7;color:#92400e;}
.ig-msg{border-radius:8px;padding:10px 16px;margin-bottom:12px;font-size:13px;} .ig-msg.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;} .ig-msg.warn{background:#fffbeb;border:1px solid #fcd34d;color:#92400e;} .ig-msg.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.ig-in{border:1.5px solid #e2e6f0;border-radius:8px;padding:8px 12px;font-size:13px;}
@media (max-width:900px){ .ig-grid{grid-template-columns:1fr;} .ig-pdf{height:60vh;} .ig-f{flex-direction:column;align-items:stretch;} .ig-b{justify-content:center;} }
</style>

<div class="ig">
    <h2><i class="fa-solid fa-file-circle-check"></i> Invoice GRN Check</h2>
    <div class="sub">Upload a LeverEDGE <strong>tax invoice PDF</strong>. Gemini reads the tax invoice number and totals, the invoice is checked against the <strong>Sampath Age Analysis</strong> data, and the GRN value is shown. The <strong>GRN Adjustment Note</strong> (printable / PDF) refers to the original invoice — the invoice PDF itself is never edited or stored.</div>
    <?php if (!$has_key): ?><div class="ig-msg warn">No Gemini API key was found. Save it on the <strong>AI Settings</strong> page — until then you can still type the invoice number below to check it against the Age Analysis.</div><?php endif; ?>
    <div id="igMsgs"></div>

    <div class="ig-card">
        <div class="ig-f">
            <input type="file" id="igFile" accept="application/pdf,.pdf">
            <button class="ig-b" id="igScan" type="button" onclick="igScan()" disabled><i class="fa-solid fa-wand-magic-sparkles"></i> Scan &amp; Check</button>
            <button class="ig-b g" id="igXls" type="button" onclick="igExcel()" disabled title="Converts the whole PDF to Excel exactly as printed (nothing is changed)"><i class="fa-solid fa-file-excel"></i> Invoice to Excel (as printed)</button>
            <span style="color:#8892a4;font-size:12px">or</span>
            <input class="ig-in" id="igManual" type="text" placeholder="type a tax invoice / bill no" style="min-width:230px">
            <button class="ig-b l" type="button" onclick="igManual()"><i class="fa-solid fa-magnifying-glass"></i> Check number</button>
        </div>
    </div>

    <div class="ig-grid" id="igGrid" style="display:none">
        <div><div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:6px;text-transform:uppercase">Uploaded invoice</div><iframe class="ig-pdf" id="igPdf"></iframe></div>
        <div id="igRes"></div>
    </div>
</div>

<form id="igNoteForm" method="POST" action="invoice_grn_check.php?note=1" target="_blank" style="display:none"><input type="hidden" name="payload" id="igPayload"></form>

<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fm = v => v === null || v === undefined || v === '' || isNaN(v) ? '—' : Number(v).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
let igScanData = null, igRows = [], igSel = 0;
function msg(h, c){ document.getElementById('igMsgs').innerHTML = h ? `<div class="ig-msg ${c || 'ok'}">${h}</div>` : ''; }

document.getElementById('igFile').addEventListener('change', e => {
    const f = e.target.files[0]; document.getElementById('igScan').disabled = !f; document.getElementById('igXls').disabled = !f;
    if (f) { document.getElementById('igPdf').src = URL.createObjectURL(f); document.getElementById('igGrid').style.display = ''; document.getElementById('igRes').innerHTML = ''; }
});

async function igScan(){
    const f = document.getElementById('igFile').files[0]; if (!f) return;
    const btn = document.getElementById('igScan'), orig = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Gemini is reading the invoice…'; msg('');
    try {
        const fd = new FormData(); fd.append('pdf', f);
        const r = await fetch('invoice_grn_check.php?ajax=scan', {method:'POST', body: fd}); const d = await r.json();
        if (!d.ok) throw new Error(d.error || 'Scan failed.');
        igScanData = d.data;
        await igLookup(igScanData.tax_invoice_no, igScanData.invoice_no);
    } catch (e) { msg(esc(e.message), 'err'); }
    btn.disabled = false; btn.innerHTML = orig;
}
async function igExcel(){
    const f = document.getElementById('igFile').files[0]; if (!f) return;
    const btn = document.getElementById('igXls'), orig = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Reading every line (can take a minute)…'; msg('');
    try {
        const fd = new FormData(); fd.append('pdf', f);
        const r = await fetch('invoice_grn_check.php?ajax=excel', {method:'POST', body: fd}); const d = await r.json();
        if (!d.ok) throw new Error(d.error || 'Conversion failed.');
        const x = d.data, h = x.header || {}, t = x.totals || {};
        const wb = new ExcelJS.Workbook(), bold = c => { c.font = {bold:true}; c.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FFFBE5D6'}}; };
        const w1 = wb.addWorksheet('Header & totals');
        [['Tax Invoice No', h.tax_invoice_no],['Invoice No (statement)', h.invoice_no],['Date of Invoice', h.date_of_invoice],['Date of Supply', h.date_of_supply],['Supplier', h.supplier_name],['Supplier TIN', h.supplier_tin],
         ['Purchaser', h.purchaser_name],['Purchaser TIN', h.purchaser_tin],[],['Total Taxable Value', t.total_taxable_value],['VAT %', t.vat_rate],['VAT Value', t.vat_value],['Gross Amount', t.gross_amount],
         ['Scheme Discount', t.scheme_discount],['Std Discount', t.std_discount],['Bill Value', t.bill_value],['Return / Damages', t.return_damages],['Net Amount (To Be Collected)', t.net_amount],[],
         ['No of SKUs (printed)', t.no_of_skus],['Invoice lines read', (x.lines || []).length],['Statement lines read', (x.statement || []).length],['Sum of invoice line amounts (check)', Math.round((x.lines || []).reduce((a, r) => a + (+r[4] || 0), 0) * 100) / 100]]
            .forEach(r => { const row = w1.addRow(r); if (r.length) row.getCell(1).font = {bold:true}; });
        w1.getColumn(1).width = 38; w1.getColumn(2).width = 46;
        const w2 = wb.addWorksheet('Invoice lines'); w2.addRow(['Ref','SKU code - Description','Quantity','Unit Price','Amount Excl. VAT (Rs.)']).eachCell(bold);
        (x.lines || []).forEach(r => w2.addRow(r)); w2.getColumn(2).width = 52; [4,5].forEach(n => { w2.getColumn(n).numFmt = '#,##0.00'; w2.getColumn(n).width = 18; }); w2.views = [{state:'frozen', ySplit:1}];
        const w3 = wb.addWorksheet('Bill statement'); w3.addRow(['Sl No','SKU Code + Description','Sale Qty CS','Sale Qty Pcs','Free Qty CS','Free Qty Pcs','Case Config','Price Per Unit (TUR)','Gross Amount','Scheme Disc','MRP']).eachCell(bold);
        (x.statement || []).forEach(r => w3.addRow(r)); w3.getColumn(2).width = 52; [8,9,10,11].forEach(n => { w3.getColumn(n).numFmt = '#,##0.00'; w3.getColumn(n).width = 16; }); w3.views = [{state:'frozen', ySplit:1}];
        const buf = await wb.xlsx.writeBuffer(), a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([buf], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));
        a.download = 'invoice_' + String(h.tax_invoice_no || 'scan').replace(/[^A-Za-z0-9_-]/g, '') + '_as_printed.xlsx'; a.click(); URL.revokeObjectURL(a.href);
        const n1 = (x.lines || []).length, n2 = (x.statement || []).length;
        msg('Excel downloaded — ' + n1 + ' invoice lines, ' + n2 + ' statement lines' + (t.no_of_skus && (+t.no_of_skus !== n1 || +t.no_of_skus !== n2) ? ' (the invoice prints ' + t.no_of_skus + ' SKUs — check the counts)' : ' (matches the printed SKU count)') + '. The file is exactly as printed.', (t.no_of_skus && (+t.no_of_skus !== n1 || +t.no_of_skus !== n2)) ? 'warn' : 'ok');
    } catch (e) { msg(esc(e.message), 'err'); }
    btn.disabled = false; btn.innerHTML = orig;
}
async function igManual(){
    const v = document.getElementById('igManual').value.trim(); if (!v) return;
    igScanData = igScanData || {tax_invoice_no: v, invoice_no: '', purchaser_name: '', taxable_value: null, vat_rate: 18, vat_value: null, bill_value: null};
    document.getElementById('igGrid').style.display = ''; msg('');
    await igLookup(v, '');
}
async function igLookup(a, b){
    const r = await fetch('invoice_grn_check.php?ajax=lookup&a=' + encodeURIComponent(a || '') + '&b=' + encodeURIComponent(b || ''));
    const d = await r.json(); igRows = d.found || []; igSel = 0; igRender(d.by);
}
function igRender(by){
    const s = igScanData || {}, row = igRows[igSel] || null;
    const invTotal = s.bill_value ?? s.net_amount;
    let h = '';
    h += `<div class="ig-card"><div style="font-size:14px;font-weight:800;margin-bottom:8px"><i class="fa-solid fa-robot"></i> Scanned from the PDF (Gemini)</div><table class="ig-t">
        <tr><td class="k">Tax invoice no</td><td><strong>${esc(s.tax_invoice_no) || '—'}</strong></td></tr>
        <tr><td class="k">Invoice no (statement)</td><td>${esc(s.invoice_no) || '—'}</td></tr>
        <tr><td class="k">Date of invoice</td><td>${esc(s.invoice_date) || '—'}</td></tr>
        <tr><td class="k">Purchaser</td><td>${esc(s.purchaser_name) || '—'}</td></tr>
        <tr><td class="k">Taxable value</td><td class="n">${fm(s.taxable_value)}</td></tr>
        <tr><td class="k">VAT ${s.vat_rate ? s.vat_rate + '%' : ''}</td><td class="n">${fm(s.vat_value)}</td></tr>
        <tr><td class="k">Bill value</td><td class="n"><strong>${fm(invTotal)}</strong></td></tr></table></div>`;
    if (!row) {
        h += `<div class="ig-msg warn">This invoice was <strong>not found</strong> in the Age Analysis data (secondary invoice import). Check the number, or type the bill no used in the Age Analysis in the box above.</div>`;
    } else {
        const grn = +row.grn_value, sec = +row.secondary_value, diff = invTotal != null ? invTotal - grn : null;
        const sameAsSys = invTotal != null && Math.abs(invTotal - sec) < 1;
        h += `<div class="ig-card"><div style="font-size:14px;font-weight:800;margin-bottom:8px"><i class="fa-solid fa-chart-gantt"></i> Sampath Age Analysis <span style="font-weight:500;color:#8892a4;font-size:12px">· matched by ${esc(by)}</span></div>`;
        if (igRows.length > 1) h += `<div style="margin-bottom:8px;font-size:12px">Several bills matched — choose: <select class="ig-in" onchange="igSel=+this.value;igRender('${esc(by)}')">${igRows.map((x, i) => `<option value="${i}" ${i === igSel ? 'selected' : ''}>${esc(x.bill_no)} · ${esc(x.party)}</option>`).join('')}</select></div>`;
        h += `<table class="ig-t">
            <tr><td class="k">Bill no</td><td><strong>${esc(row.bill_no)}</strong> <span style="color:#8892a4">${esc(row.t_code || '')}</span></td></tr>
            <tr><td class="k">Party</td><td>${esc(row.party)}</td></tr>
            <tr><td class="k">Bill date</td><td>${esc((row.bill_date || '').slice(0, 10))}</td></tr>
            <tr><td class="k">Secondary final bill amount</td><td class="n">${fm(sec)} ${invTotal != null ? (sameAsSys ? '<span class="pill ok">= invoice</span>' : '<span class="pill warn">differs from invoice</span>') : ''}</td></tr>
            <tr><td class="k">GRN value</td><td class="n"><strong style="font-size:15px">${fm(grn)}</strong> ${grn > 0 ? '' : '<span class="pill warn">no GRN yet</span>'}</td></tr>
            <tr><td class="k">GRN date</td><td>${esc((row.grn_date || '').slice(0, 10)) || '—'}</td></tr>
            <tr><td class="k">Credit notes applied</td><td class="n">${fm(row.credit_note)}</td></tr>
            <tr><td class="k">Cheque paid</td><td class="n">${fm(row.cheque_value)} <span style="color:#8892a4">${esc(row.cheque_no || '')} ${esc((row.cheque_date || '').slice(0, 10))}</span></td></tr>
            <tr><td class="k">GRN − cheque − credit note</td><td class="n">${fm(grn - row.cheque_value - row.credit_note)}</td></tr></table></div>`;
        const rate = +s.vat_rate || 18, aTax = Math.round(grn / (1 + rate / 100) * 100) / 100, aVat = Math.round((grn - aTax) * 100) / 100;
        h += `<div class="ig-card"><div style="font-size:14px;font-weight:800;margin-bottom:8px"><i class="fa-solid fa-scale-balanced"></i> Invoice vs GRN accepted</div><table class="ig-t">
            <thead><tr><th></th><th class="n">Original invoice</th><th class="n">GRN accepted</th><th class="n">Difference</th></tr></thead>
            <tr><td>Taxable value</td><td class="n">${fm(s.taxable_value)}</td><td class="n">${fm(aTax)}</td><td class="n">${s.taxable_value != null ? fm(s.taxable_value - aTax) : '—'}</td></tr>
            <tr><td>VAT ${rate}%</td><td class="n">${fm(s.vat_value)}</td><td class="n">${fm(aVat)}</td><td class="n">${s.vat_value != null ? fm(s.vat_value - aVat) : '—'}</td></tr>
            <tr style="font-weight:800"><td>Total</td><td class="n">${fm(invTotal)}</td><td class="n">${fm(grn)}</td><td class="n" style="color:${diff > 0 ? '#dc2626' : '#15803d'}">${diff != null ? fm(diff) : '—'}</td></tr></table>
            <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap">
                <button class="ig-b g" type="button" onclick="igNote()"><i class="fa-solid fa-file-pdf"></i> GRN Adjustment Note (PDF)</button>
            </div>
            <div style="font-size:11px;color:#8892a4;margin-top:8px">The note refers to the original tax invoice and is a separate document — the invoice itself is not changed.</div></div>`;
    }
    document.getElementById('igRes').innerHTML = h;
}
function igNote(){
    const row = igRows[igSel]; if (!row) return;
    document.getElementById('igPayload').value = JSON.stringify({scan: igScanData, bill_no: row.bill_no});
    document.getElementById('igNoteForm').submit();
}
</script>
<?php include 'footer.php'; ?>
