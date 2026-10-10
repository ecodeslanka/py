<?php
/**
 * import_purchase_return_register_ajax.php
 * Pure-PHP xlsx parser (ZipArchive + SimpleXML).
 * Handles: Purchase Return Register report.
 * Streams {"progress":N,"stage":"..."} lines, then:
 *   @@RESULT@@{"status":"ok"|"error",...}@@END@@
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

// ── Helpers ───────────────────────────────────────────────────────────────────
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

// Convert Excel serial date OR date string → 'Y-m-d'; returns null for blank
function excel_date($val) {
    if ($val === null || trim((string)$val) === '') return null;
    if (is_numeric($val)) {
        $s = (float)$val;
        if ($s <= 0) return null;
        $epoch = ($s >= 60) ? $s - 1 : $s;
        $ts = mktime(0,0,0,1,1,1900) + (int)(($epoch - 1) * 86400);
        return date('Y-m-d', $ts);
    }
    // Try parsing string dates (e.g. "2025-01-28 00:00:00", "28/01/2025")
    $clean = trim((string)$val);
    $ts = strtotime($clean);
    return $ts ? date('Y-m-d', $ts) : null;
}

// Column letter(s) → 0-based index
function col_to_idx($col) {
    $col = strtoupper(trim($col)); $idx = 0;
    for ($i = 0; $i < strlen($col); $i++) $idx = $idx * 26 + (ord($col[$i]) - 64);
    return $idx - 1;
}
function cell_col($ref) {
    preg_match('/^([A-Za-z]+)/', $ref, $m);
    return $m[1] ?? 'A';
}

// ── Validate POST ─────────────────────────────────────────────────────────────
$bill_date_raw = trim($_POST['bill_date'] ?? '');
$remarks       = trim($_POST['remarks']   ?? '');

if (empty($bill_date_raw)) done_err('Bill date is required.');
$bill_date_ts = strtotime($bill_date_raw);
if (!$bill_date_ts) done_err('Invalid bill date format. Please use YYYY-MM-DD.');
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

$file_err = $_FILES['register_file']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($file_err !== UPLOAD_ERR_OK) {
    done_err($upload_err_labels[$file_err] ?? "PHP upload error code: $file_err");
}

$file = $_FILES['register_file'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx','xls'])) done_err('Only .xlsx or .xls files are accepted.');

prog(8, 'File received. Saving…');

// ── Save uploaded file ────────────────────────────────────────────────────────
$upload_dir = __DIR__ . '/uploads/purchase_return_register/';
if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);
if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
    $upload_dir = sys_get_temp_dir() . '/purchase_return_register/';
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
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS purchase_return_register_imports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255),
    stored_filename VARCHAR(255),
    bill_date DATE,
    remarks TEXT,
    status VARCHAR(20) DEFAULT 'processing',
    total_records INT DEFAULT 0,
    imported_records INT DEFAULT 0,
    failed_records INT DEFAULT 0,
    imported_at DATETIME DEFAULT NOW()
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS purchase_return_register_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_id INT,
    purchase_return_date DATE,
    purchase_return_no VARCHAR(100),
    grn_date DATE,
    grn_no VARCHAR(100),
    company_invoice_date DATE,
    company_invoice_number VARCHAR(100),
    control_amount DECIMAL(16,2),
    total_return_value DECIMAL(16,2),
    discount_amount DECIMAL(14,2),
    other_discount DECIMAL(14,2),
    other_adj DECIMAL(14,2),
    net_return_value DECIMAL(16,2)
)");

// ── Insert import header record ───────────────────────────────────────────────
$fn  = mysqli_real_escape_string($conn, $file['name']);
$sf  = mysqli_real_escape_string($conn, $safe_name);
$rem = mysqli_real_escape_string($conn, $remarks);
$bd  = mysqli_real_escape_string($conn, $bill_date);

mysqli_query($conn, "INSERT INTO purchase_return_register_imports
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
    mysqli_query($conn, "UPDATE purchase_return_register_imports SET status='failed' WHERE id=$import_id");
    done_err('PHP ZipArchive extension is not available. Please enable it in php.ini.');
}

$zip = new ZipArchive();
if ($zip->open($dest) !== true) {
    mysqli_query($conn, "UPDATE purchase_return_register_imports SET status='failed' WHERE id=$import_id");
    done_err('Cannot open the Excel file. It may be corrupted or in an unsupported format.');
}

// Read workbook.xml
$wb_xml = $zip->getFromName('xl/workbook.xml');
if ($wb_xml === false) {
    $zip->close();
    mysqli_query($conn, "UPDATE purchase_return_register_imports SET status='failed' WHERE id=$import_id");
    done_err('Invalid Excel file: xl/workbook.xml missing.');
}
$wb = simplexml_load_string($wb_xml);

// Get sheet names
$sheet_names = [];
foreach ($wb->sheets->sheet as $sh) {
    $sheet_names[] = (string)$sh['name'];
}

// Get sheet file paths from relationships
$rels_xml    = $zip->getFromName('xl/_rels/workbook.xml.rels');
$sheet_files = [];
if ($rels_xml !== false) {
    $rels = simplexml_load_string($rels_xml);
    foreach ($rels->Relationship as $rel) {
        if (strpos((string)$rel['Type'], 'worksheet') !== false) {
            $sheet_files[] = 'xl/' . ltrim((string)$rel['Target'], '/');
        }
    }
}

// Find the Purchase Return Register sheet (prefer by name, fall back to first)
$target_idx = 0;
foreach ($sheet_names as $i => $sn) {
    if (stripos($sn, 'Purchase Return Register') !== false ||
        stripos($sn, 'Purchase Return')          !== false) {
        $target_idx = $i; break;
    }
}
$sheet_file = $sheet_files[$target_idx] ?? ($sheet_files[0] ?? null);

if ($sheet_file === null) {
    $zip->close();
    mysqli_query($conn, "UPDATE purchase_return_register_imports SET status='failed' WHERE id=$import_id");
    done_err('Could not locate the worksheet in the Excel file.');
}

// Shared strings
$shared = [];
$ss_xml = $zip->getFromName('xl/sharedStrings.xml');
if ($ss_xml !== false) {
    $ss = simplexml_load_string($ss_xml);
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

prog(40, 'Reading worksheet…');

$ws_xml = $zip->getFromName($sheet_file);
$zip->close();
if ($ws_xml === false) {
    mysqli_query($conn, "UPDATE purchase_return_register_imports SET status='failed' WHERE id=$import_id");
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
        $ref  = (string)$c['r'];
        $type = (string)$c['t'];
        $ci   = col_to_idx(cell_col($ref));

        // Inline strings store their text in <is><t>...</t></is>, not in <v>,
        // so they must be read separately or they'd be silently dropped.
        if ($type === 'inlineStr') {
            if (isset($c->is)) {
                if (isset($c->is->t)) {
                    $cells[$ci] = (string)$c->is->t;
                } else {
                    $txt = '';
                    foreach ($c->is->r as $run) $txt .= (string)$run->t;
                    $cells[$ci] = $txt;
                }
            }
            continue;
        }

        $v = isset($c->v) ? (string)$c->v : null;
        if ($v === null) continue;
        $cells[$ci] = ($type === 's') ? ($shared[(int)$v] ?? '') : $v;
    }
    $all_rows[$rnum] = $cells;
}
unset($ws, $shared);

prog(52, 'Finding header row…');

// ── Find header row (must contain "Purchase Return No") ───────────────────────
$header_row_num = null;
$col_map        = [];

foreach ($all_rows as $rnum => $cells) {
    foreach ($cells as $idx => $val) {
        if (is_string($val) && stripos(trim($val), 'Purchase Return No') !== false) {
            $header_row_num = $rnum; break 2;
        }
    }
}

if ($header_row_num === null) {
    mysqli_query($conn, "UPDATE purchase_return_register_imports SET status='failed' WHERE id=$import_id");
    done_err('Header row with "Purchase Return No" not found. Please ensure you are uploading the correct Purchase Return Register report.');
}

// Build col_map: header_name => col_index, skipping "Sr No"
foreach ($all_rows[$header_row_num] as $idx => $val) {
    $trimmed = trim((string)$val);
    if ($trimmed === '') continue;
    if (preg_match('/^sr\.?\s*no\.?$/i', $trimmed)) continue; // skip Sr No
    $col_map[$trimmed] = $idx;
}

prog(55, 'Extracting data rows…');

// ── Extract data rows ─────────────────────────────────────────────────────────
// Find the Purchase Return No column index once
$ret_no_idx = null;
foreach ($col_map as $name => $cidx) {
    if (stripos($name, 'Purchase Return No') !== false) { $ret_no_idx = $cidx; break; }
}

$data_rows = [];
foreach ($all_rows as $rnum => $cells) {
    if ($rnum <= $header_row_num) continue;

    // Skip rows with empty Purchase Return No (catches Grand Total, blank rows, etc.)
    if ($ret_no_idx === null) continue;
    $ref_val = trim((string)($cells[$ret_no_idx] ?? ''));
    if ($ref_val === '') continue;

    $row = [];
    foreach ($col_map as $name => $idx) {
        $raw = $cells[$idx] ?? null;
        $row[$name] = ($raw !== null && trim((string)$raw) !== '') ? trim((string)$raw) : null;
    }
    $data_rows[] = $row;
}
unset($all_rows);

$total = count($data_rows);
if ($total === 0) {
    mysqli_query($conn, "UPDATE purchase_return_register_imports SET
        status='completed',total_records=0,imported_records=0,failed_records=0
        WHERE id=$import_id");
    done_ok(['status'=>'ok','ok'=>0,'fail'=>0,'import_id'=>$import_id]);
}

prog(58, "Parsed $total rows. Inserting into database…");

// ── Flexible column value helpers ─────────────────────────────────────────────
// Looks up a key case-insensitively; supports partial match for flexibility
$get = function($row, $key) use ($conn) {
    foreach ($row as $k => $val) {
        if (strcasecmp(trim($k), trim($key)) === 0) {
            if ($val === null || $val === '') return 'NULL';
            return "'" . mysqli_real_escape_string($conn, $val) . "'";
        }
    }
    return 'NULL';
};

$get_date = function($row, $key) use ($conn) {
    foreach ($row as $k => $val) {
        if (strcasecmp(trim($k), trim($key)) === 0) {
            $d = excel_date($val);
            if ($d === null) return 'NULL';
            return "'" . mysqli_real_escape_string($conn, $d) . "'";
        }
    }
    return 'NULL';
};

$get_num = function($row, $key) {
    foreach ($row as $k => $val) {
        if (strcasecmp(trim($k), trim($key)) === 0) {
            if ($val === null || $val === '') return 'NULL';
            $c = preg_replace('/[^0-9.\-]/', '', (string)$val);
            return is_numeric($c) ? $c : 'NULL';
        }
    }
    return 'NULL';
};

// ── Batch insert (250 rows per query) ─────────────────────────────────────────
$ok = 0; $fail = 0;
$BATCH    = 250;
$chunks   = array_chunk($data_rows, $BATCH);
$n_chunks = count($chunks);
unset($data_rows);

$col_list = "(import_id, purchase_return_date, purchase_return_no,
    grn_date, grn_no, company_invoice_date, company_invoice_number,
    control_amount, total_return_value, discount_amount,
    other_discount, other_adj, net_return_value)";

$build_values = function($r) use ($import_id, $get, $get_date, $get_num) {
    return "($import_id,"
        . $get_date($r, 'Purchase Return Date')   . ','
        . $get($r,      'Purchase Return No')     . ','
        . $get_date($r, 'GRN Date')               . ','
        . $get($r,      'GRN No')                 . ','
        . $get_date($r, 'Company Invoice Date')   . ','
        . $get($r,      'Company Invoice Number') . ','
        . $get_num($r,  'Control Amount')         . ','
        . $get_num($r,  'Total Return Value')     . ','
        . $get_num($r,  'Discount Amount')        . ','
        . $get_num($r,  'Other Discount')         . ','
        . $get_num($r,  'Other Adj')              . ','
        . $get_num($r,  'Net Return Value')       . ')';
};

foreach ($chunks as $ci => $chunk) {
    $vals = array_map($build_values, $chunk);
    $sql  = "INSERT INTO purchase_return_register_details $col_list VALUES " . implode(',', $vals);

    if (mysqli_query($conn, $sql)) {
        $ok += count($chunk);
    } else {
        // Batch failed → row-by-row fallback
        foreach ($chunk as $r) {
            $s2 = "INSERT INTO purchase_return_register_details $col_list VALUES " . $build_values($r);
            mysqli_query($conn, $s2) ? $ok++ : $fail++;
        }
    }

    $pct    = 58 + (int)(($ci + 1) / $n_chunks * 36);
    $done_n = min(($ci + 1) * $BATCH, $total);
    prog($pct, "Inserted $done_n / $total rows…");
}

prog(96, 'Finalising…');

$status = ($ok === 0 && $fail > 0) ? 'failed' : 'completed';
mysqli_query($conn, "UPDATE purchase_return_register_imports SET
    status='$status',
    total_records="    . ($ok + $fail) . ",
    imported_records=" . $ok           . ",
    failed_records="   . $fail         . "
    WHERE id=$import_id");

prog(100, 'Done!');

done_ok([
    'status'    => 'ok',
    'ok'        => $ok,
    'fail'      => $fail,
    'import_id' => $import_id,
]);