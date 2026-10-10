<?php
/**
 * import_purchase_return_ajax.php
 * Pure-PHP xlsx parser (ZipArchive + SimpleXML).
 * Streams {"progress":N,"stage":"..."} lines, then:
 *   @@RESULT@@{"status":"ok"|"error",...}@@END@@
 *
 * FIX: cells can store their text three different ways in real-world xlsx
 * exports:
 *   - t="s"         -> <v> holds an index into sharedStrings.xml
 *   - t="inlineStr" -> text lives in <is><t>...</t></is>, NOT in <v> at all
 *   - t="str" / no t (numeric) -> value is directly in <v>
 * The previous version only ever read <v>, so every inlineStr cell
 * (which is how THIS report stores all its text, including the header
 * row itself) was silently dropped -> header row could never be found.
 */

error_reporting(0);
@ini_set('display_errors',         '0');
@ini_set('display_startup_errors', '0');

while (ob_get_level()) ob_end_clean();

@ini_set('output_buffering',       'off');
@ini_set('zlib.output_compression','off');
@ini_set('implicit_flush',         '1');
ob_implicit_flush(true);

@set_time_limit(300);
@ini_set('memory_limit','512M');

header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache, no-store');

if (empty($_POST['ajax_import'])) {
    http_response_code(403);
    echo '@@RESULT@@' . json_encode(['status'=>'error','error'=>'Direct access not allowed.']) . '@@END@@';
    exit;
}

include 'config.php';

// ── Helpers ──────────────────────────────────────────────────────────────────
function prog($pct, $stage) {
    echo json_encode(['progress'=>(int)$pct, 'stage'=>$stage]) . "\n";
    flush();
}
function done_ok($data) {
    echo "\n@@RESULT@@" . json_encode($data) . "@@END@@\n";
    flush(); exit;
}
function done_err($msg) {
    echo "\n@@RESULT@@" . json_encode(['status'=>'error','error'=>$msg]) . "@@END@@\n";
    flush(); exit;
}

// Column letter(s) → 0-based index
function col_to_idx($col) {
    $col = strtoupper(trim($col)); $idx = 0;
    for ($i = 0; $i < strlen($col); $i++) $idx = $idx*26 + (ord($col[$i])-64);
    return $idx - 1;
}
function cell_col($ref) {
    preg_match('/^([A-Za-z]+)/', $ref, $m);
    return $m[1] ?? 'A';
}

/**
 * Read the real value out of a <c> element, handling every storage form
 * OOXML allows for a cell's content:
 *   s         shared string  -> <v> is an index into $shared
 *   inlineStr inline string  -> <is><t>text</t></is> (or <is><r><t>..</t></r>...> for rich text runs)
 *   str       formula string result -> value directly in <v>
 *   b         boolean        -> "0"/"1" in <v>
 *   (none)/n  numeric        -> value directly in <v>
 * Returns null only when the cell truly has no content at all.
 */
function get_cell_value($c, $shared) {
    $type = (string)$c['t'];

    if ($type === 'inlineStr') {
        if (isset($c->is)) {
            if (isset($c->is->t)) {
                return (string)$c->is->t;
            }
            if (isset($c->is->r)) {
                $txt = '';
                foreach ($c->is->r as $run) {
                    $txt .= isset($run->t) ? (string)$run->t : '';
                }
                return $txt;
            }
        }
        return ''; // <is/> with no <t> at all -> treat as empty string, not absent
    }

    if ($type === 's') {
        if (!isset($c->v)) return null;
        $idx = (int)$c->v;
        return $shared[$idx] ?? '';
    }

    // 'str' (formula string), 'b' (boolean), '' or 'n' (numeric) all keep
    // their literal value in <v>.
    return isset($c->v) ? (string)$c->v : null;
}

// ── Validate POST ─────────────────────────────────────────────────────────────
$bill_date_raw = trim($_POST['bill_date'] ?? '');
$remarks       = trim($_POST['remarks']   ?? '');

if (empty($bill_date_raw)) {
    done_err('Bill date is required.');
}
$bill_date_ts = strtotime($bill_date_raw);
if (!$bill_date_ts) {
    done_err('Invalid bill date format. Please use YYYY-MM-DD.');
}
$bill_date = date('Y-m-d', $bill_date_ts);

$upload_err_labels = [
    UPLOAD_ERR_INI_SIZE   => 'File too large — exceeds upload_max_filesize in php.ini.',
    UPLOAD_ERR_FORM_SIZE  => 'File too large — exceeds MAX_FILE_SIZE in the form.',
    UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded — please try again.',
    UPLOAD_ERR_NO_FILE    => 'No file was received by the server.',
    UPLOAD_ERR_NO_TMP_DIR => 'Server has no temporary folder — contact your host.',
    UPLOAD_ERR_CANT_WRITE => 'Server cannot write to disk — contact your host.',
    UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension.',
];

$file_err = $_FILES['return_file']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($file_err !== UPLOAD_ERR_OK) {
    done_err($upload_err_labels[$file_err] ?? "PHP upload error code: $file_err");
}

$file = $_FILES['return_file'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx','xls'])) {
    done_err('Only .xlsx or .xls files are accepted.');
}

prog(8, 'File received. Saving…');

// ── Save uploaded file ────────────────────────────────────────────────────────
$upload_dir = __DIR__ . '/uploads/purchase_returns/';
if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);
if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
    $upload_dir = sys_get_temp_dir() . '/purchase_returns/';
    @mkdir($upload_dir, 0755, true);
}

$safe_name = date('Ymd', $bill_date_ts) . '_' . time() . '_'
           . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
$dest = $upload_dir . $safe_name;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    done_err("Could not save uploaded file. Path: $dest — check folder permissions.");
}

prog(15, 'File saved. Creating import record…');

// ── Ensure tables exist ───────────────────────────────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS purchase_return_imports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255), stored_filename VARCHAR(255),
    bill_date DATE,
    remarks TEXT, status VARCHAR(20) DEFAULT 'processing',
    total_records INT DEFAULT 0, imported_records INT DEFAULT 0,
    failed_records INT DEFAULT 0, imported_at DATETIME DEFAULT NOW()
)");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS purchase_return_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_id INT,
    purchase_ret_no VARCHAR(100),
    invoice_no VARCHAR(100),
    item_code VARCHAR(50),
    item_name VARCHAR(255),
    mrp DECIMAL(12,2),
    invoice_price_case DECIMAL(12,4),
    invoice_price_unit DECIMAL(12,4),
    pkm VARCHAR(50),
    batch_code VARCHAR(50),
    pack_size DECIMAL(12,4),
    upc VARCHAR(50),
    purchase_ret_qty_cases DECIMAL(12,4),
    purchase_ret_amount DECIMAL(14,2),
    tax_amount DECIMAL(14,2),
    net_amount DECIMAL(14,2),
    tonnage DECIMAL(12,6)
)");

// ── Insert import header record ───────────────────────────────────────────────
$fn  = mysqli_real_escape_string($conn, $file['name']);
$sf  = mysqli_real_escape_string($conn, $safe_name);
$rem = mysqli_real_escape_string($conn, $remarks);
$bd  = mysqli_real_escape_string($conn, $bill_date);

mysqli_query($conn, "INSERT INTO purchase_return_imports
    (filename, stored_filename, bill_date, remarks, status)
    VALUES ('$fn','$sf','$bd','$rem','processing')");

if (mysqli_errno($conn)) {
    @unlink($dest);
    done_err('DB error creating import record: ' . mysqli_error($conn));
}
$import_id = (int)mysqli_insert_id($conn);

prog(22, 'Parsing Excel file…');

// ── Pure-PHP XLSX Parser ──────────────────────────────────────────────────────
if (!class_exists('ZipArchive')) {
    mysqli_query($conn, "UPDATE purchase_return_imports SET status='failed' WHERE id=$import_id");
    done_err('PHP ZipArchive extension is not available. Please enable it in php.ini.');
}

$zip = new ZipArchive();
if ($zip->open($dest) !== true) {
    mysqli_query($conn, "UPDATE purchase_return_imports SET status='failed' WHERE id=$import_id");
    done_err('Cannot open the Excel file. It may be corrupted or in an unsupported format.');
}

// Read workbook.xml to find sheet names
$wb_xml = $zip->getFromName('xl/workbook.xml');
if ($wb_xml === false) {
    $zip->close();
    mysqli_query($conn, "UPDATE purchase_return_imports SET status='failed' WHERE id=$import_id");
    done_err('Invalid Excel file: xl/workbook.xml missing.');
}
$wb = simplexml_load_string($wb_xml);

// Find the target sheet — prefer "Product_Wise_Purchase_Return", else use first sheet
$sheet_names = [];
foreach ($wb->sheets->sheet as $sh) {
    $sheet_names[] = (string)$sh['name'];
}

// Read relationships to get sheet file paths
$rels_xml = $zip->getFromName('xl/_rels/workbook.xml.rels');
$sheet_files = [];
if ($rels_xml !== false) {
    $rels = simplexml_load_string($rels_xml);
    foreach ($rels->Relationship as $rel) {
        $type = (string)$rel['Type'];
        if (strpos($type, 'worksheet') !== false) {
            $sheet_files[] = 'xl/' . ltrim((string)$rel['Target'], '/');
        }
    }
}

// Match sheet name to file (default to first)
$target_sheet_idx = 0;
foreach ($sheet_names as $i => $sn) {
    if (stripos($sn, 'Product_Wise_Purchase_Return') !== false ||
        stripos($sn, 'Purchase Return') !== false) {
        $target_sheet_idx = $i; break;
    }
}
$sheet_file = $sheet_files[$target_sheet_idx] ?? ($sheet_files[0] ?? null);

if ($sheet_file === null) {
    $zip->close();
    mysqli_query($conn, "UPDATE purchase_return_imports SET status='failed' WHERE id=$import_id");
    done_err('Could not locate the worksheet in the Excel file.');
}

// Shared strings (may be empty/absent — this report type uses inlineStr instead,
// but we still support shared strings for other report exports that use them)
$shared = [];
$ss_xml = $zip->getFromName('xl/sharedStrings.xml');
if ($ss_xml !== false) {
    $ss = simplexml_load_string($ss_xml);
    if ($ss !== false && isset($ss->si)) {
        foreach ($ss->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string)$si->t;
            } else {
                $txt = '';
                foreach ($si->r as $run) $txt .= (string)$run->t;
                $shared[] = $txt;
            }
        }
    }
}

prog(40, 'Reading worksheet…');

$ws_xml = $zip->getFromName($sheet_file);
$zip->close();
if ($ws_xml === false) {
    mysqli_query($conn, "UPDATE purchase_return_imports SET status='failed' WHERE id=$import_id");
    done_err("Cannot read sheet file: $sheet_file");
}

// Build $all_rows: [ row_number => [ col_index => value ] ]
$all_rows = [];
$ws = simplexml_load_string($ws_xml);
unset($ws_xml);

foreach ($ws->sheetData->row as $row_el) {
    $rnum  = (int)$row_el['r'];
    $cells = [];
    foreach ($row_el->c as $c) {
        $ref = (string)$c['r'];
        $val = get_cell_value($c, $shared);
        if ($val === null) continue; // truly empty cell, nothing to store
        $ci = col_to_idx(cell_col($ref));
        $cells[$ci] = $val;
    }
    $all_rows[$rnum] = $cells;
}
unset($ws, $shared);

prog(52, 'Finding header row…');

// ── Find header row (must contain "Purchase Ret No") ─────────────────────────
$header_row_num = null;

foreach ($all_rows as $rnum => $cells) {
    foreach ($cells as $idx => $val) {
        if (is_string($val) && stripos(trim($val), 'Purchase Ret No') !== false) {
            $header_row_num = $rnum; break 2;
        }
    }
}

if ($header_row_num === null) {
    mysqli_query($conn, "UPDATE purchase_return_imports SET status='failed' WHERE id=$import_id");
    done_err('Header row with "Purchase Ret No" not found. Please ensure you are uploading the correct Product Wise Purchase Return Report.');
}

// Build col_map: header_name => col_index
// We skip "Sr No" (Sr No / Sr. No etc.) intentionally
$col_map = [];
foreach ($all_rows[$header_row_num] as $idx => $val) {
    $trimmed = trim((string)$val);
    if ($trimmed === '') continue;
    if (preg_match('/^sr\.?\s*no\.?$/i', $trimmed)) continue; // skip Sr No
    $col_map[$trimmed] = $idx;
}

if (empty($col_map)) {
    mysqli_query($conn, "UPDATE purchase_return_imports SET status='failed' WHERE id=$import_id");
    done_err('Header row was located but no usable columns could be read from it.');
}

// Resolve the Purchase Ret No column index once (used for every data row)
$ret_no_idx = null;
foreach ($col_map as $name => $cidx) {
    if (stripos($name, 'Purchase Ret No') !== false) { $ret_no_idx = $cidx; break; }
}
if ($ret_no_idx === null) {
    mysqli_query($conn, "UPDATE purchase_return_imports SET status='failed' WHERE id=$import_id");
    done_err('Could not resolve the Purchase Ret No column.');
}

// ── Extract data rows ─────────────────────────────────────────────────────────
$data_rows = [];
foreach ($all_rows as $rnum => $cells) {
    if ($rnum <= $header_row_num) continue;

    $ref_val = trim((string)($cells[$ret_no_idx] ?? ''));
    if ($ref_val === '') continue; // skips blank rows AND the Grand Total row

    $row = [];
    foreach ($col_map as $name => $idx) {
        $raw = $cells[$idx] ?? null;
        $row[$name] = ($raw === null || trim((string)$raw) === '') ? null : trim((string)$raw);
    }
    $data_rows[] = $row;
}
unset($all_rows);

$total = count($data_rows);
if ($total === 0) {
    mysqli_query($conn, "UPDATE purchase_return_imports SET
        status='completed',total_records=0,imported_records=0,failed_records=0
        WHERE id=$import_id");
    done_ok(['status'=>'ok','ok'=>0,'fail'=>0,'import_id'=>$import_id]);
}

prog(58, "Parsed $total rows. Inserting into database…");

// ── Batch insert (250 rows per query) ─────────────────────────────────────────
$ok = 0; $fail = 0;
$BATCH = 250;
$chunks = array_chunk($data_rows, $BATCH);
$n_chunks = count($chunks);
unset($data_rows);

// Helper closures
$f = function($row, $key) use ($conn) {
    $v = null;
    foreach ($row as $k => $val) {
        if (strcasecmp(trim($k), trim($key)) === 0) { $v = $val; break; }
    }
    if ($v === null || $v === '') return 'NULL';
    return "'" . mysqli_real_escape_string($conn, $v) . "'";
};
$num = function($row, $key) {
    $v = null;
    foreach ($row as $k => $val) {
        if (strcasecmp(trim($k), trim($key)) === 0) { $v = $val; break; }
    }
    if ($v === null || $v === '') return 'NULL';
    $c = preg_replace('/[^0-9.\-]/', '', (string)$v);
    return ($c !== '' && is_numeric($c)) ? $c : 'NULL';
};

$col_list = "(import_id, purchase_ret_no, invoice_no, item_code, item_name,
    mrp, invoice_price_case, invoice_price_unit, pkm, batch_code,
    pack_size, upc, purchase_ret_qty_cases, purchase_ret_amount,
    tax_amount, net_amount, tonnage)";

$insert_row = function($r) use ($import_id, $f, $num) {
    return "($import_id,"
        . $f($r,   'Purchase Ret No')          . ','
        . $f($r,   'Invoice No')               . ','
        . $f($r,   'Item Code')                . ','
        . $f($r,   'Item Name')                . ','
        . $num($r, 'MRP')                      . ','
        . $num($r, 'Invoice Price/Case')        . ','
        . $num($r, 'Invoice Price/Unit')        . ','
        . $f($r,   'PKM')                      . ','
        . $f($r,   'Batch Code')               . ','
        . $num($r, 'Pack Size')                . ','
        . $f($r,   'UPC')                      . ','
        . $num($r, 'Purchase Ret Qty (Cases)')  . ','
        . $num($r, 'Purchase Ret Amount')       . ','
        . $num($r, 'Tax Amount')               . ','
        . $num($r, 'Net Amount')               . ','
        . $num($r, 'Tonnage')                  . ')';
};

foreach ($chunks as $ci => $chunk) {
    $vals = array_map($insert_row, $chunk);
    $sql  = "INSERT INTO purchase_return_details $col_list VALUES " . implode(',', $vals);

    if (mysqli_query($conn, $sql)) {
        $ok += count($chunk);
    } else {
        // Batch failed → row-by-row fallback
        foreach ($chunk as $r) {
            $s2 = "INSERT INTO purchase_return_details $col_list VALUES " . $insert_row($r);
            mysqli_query($conn, $s2) ? $ok++ : $fail++;
        }
    }

    $pct    = 58 + (int)(($ci + 1) / $n_chunks * 36);
    $done_n = min(($ci + 1) * $BATCH, $total);
    prog($pct, "Inserted $done_n / $total rows…");
}

prog(96, 'Finalising…');

$status = ($ok === 0 && $fail > 0) ? 'failed' : 'completed';
mysqli_query($conn, "UPDATE purchase_return_imports SET
    status='$status',
    total_records="    . ($ok+$fail) . ",
    imported_records=" . $ok         . ",
    failed_records="   . $fail       . "
    WHERE id=$import_id");

prog(100, 'Done!');

done_ok([
    'status'    => 'ok',
    'ok'        => $ok,
    'fail'      => $fail,
    'import_id' => $import_id,
]);