<?php
/**
 * import_damage_proposal_ajax.php
 * Pure-PHP xlsx parser — NO exec(), NO python, NO external libraries.
 * Uses only PHP built-ins: ZipArchive + SimpleXML (enabled on every standard PHP install).
 *
 * Streams {"progress":N,"stage":"..."} lines, then:
 *   @@RESULT@@{"status":"ok"|"error",...}@@END@@
 */

// ── 1. Suppress all notices/warnings so they never corrupt JSON output ─────────
error_reporting(0);
@ini_set('display_errors',         '0');
@ini_set('display_startup_errors', '0');

// ── 2. Kill every output buffer (header.php / config.php may open them) ───────
while (ob_get_level()) ob_end_clean();

// ── 3. Disable further buffering for streaming ─────────────────────────────────
@ini_set('output_buffering',       'off');
@ini_set('zlib.output_compression','off');
@ini_set('implicit_flush',         '1');
ob_implicit_flush(true);

// ── 4. Generous resource limits ────────────────────────────────────────────────
@set_time_limit(300);
@ini_set('memory_limit','512M');

// ── 5. Response headers ────────────────────────────────────────────────────────
header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache, no-store');

// ── 6. Only accept POST from our form ─────────────────────────────────────────
if (empty($_POST['ajax_import'])) {
    http_response_code(403);
    echo '@@RESULT@@' . json_encode(['status'=>'error','error'=>'Direct access not allowed.']) . '@@END@@';
    exit;
}

// ── 7. Load DB config only (NEVER include header.php — it outputs HTML) ───────
include 'config.php';

// ─────────────────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────────────────
function prog($pct, $stage) {
    echo json_encode(['progress'=>(int)$pct,'stage'=>$stage]) . "\n";
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

// Convert Excel serial date (numeric) to 'Y-m-d'
// Returns null safely for "NA", "N/A", empty, or any non-numeric value
function excel_date($serial) {
    if ($serial === null || $serial === '') return null;
    if (!is_numeric($serial)) return null;
    $s = (float)$serial;
    if ($s <= 0) return null;
    // Excel incorrectly treats 1900 as leap year; adjust for dates >= 60
    $epoch = ($s >= 60) ? $s - 1 : $s;
    $ts    = mktime(0, 0, 0, 1, 1, 1900) + (int)(($epoch - 1) * 86400);
    return date('Y-m-d', $ts);
}

// Convert column letter(s) to 0-based index  A=0, B=1, Z=25, AA=26 …
function col_to_idx($col) {
    $col = strtoupper(trim($col));
    $idx = 0;
    for ($i = 0; $i < strlen($col); $i++) {
        $idx = $idx * 26 + (ord($col[$i]) - 64);
    }
    return $idx - 1;
}

// Extract the column letters from a cell ref like "AB12" → "AB"
function cell_col($ref) {
    preg_match('/^([A-Za-z]+)/', $ref, $m);
    return $m[1] ?? 'A';
}

// ─────────────────────────────────────────────────────────────────────────────
// VALIDATE UPLOAD
// ─────────────────────────────────────────────────────────────────────────────
$month   = intval($_POST['proposal_month']  ?? 0);
$year    = intval($_POST['proposal_year']   ?? 0);
$remarks = trim($_POST['remarks']           ?? '');

if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
    done_err('Invalid month or year selected.');
}

$upload_err_labels = [
    UPLOAD_ERR_INI_SIZE   => 'File too large — exceeds upload_max_filesize in php.ini.',
    UPLOAD_ERR_FORM_SIZE  => 'File too large — exceeds MAX_FILE_SIZE in the form.',
    UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded — please try again.',
    UPLOAD_ERR_NO_FILE    => 'No file was received by the server.',
    UPLOAD_ERR_NO_TMP_DIR => 'Server has no temporary folder — contact your host.',
    UPLOAD_ERR_CANT_WRITE => 'Server cannot write to disk — contact your host.',
    UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension.',
];

$file_err = $_FILES['proposal_file']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($file_err !== UPLOAD_ERR_OK) {
    done_err($upload_err_labels[$file_err] ?? "PHP upload error code: $file_err");
}

$file = $_FILES['proposal_file'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx','xls'])) {
    done_err('Only .xlsx or .xls files are accepted.');
}

prog(8, 'File received. Saving…');

// ─────────────────────────────────────────────────────────────────────────────
// SAVE FILE
// ─────────────────────────────────────────────────────────────────────────────
$upload_dir = __DIR__ . '/uploads/damage_proposals/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}
// Last resort: system temp dir
if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
    $upload_dir = sys_get_temp_dir() . '/damage_proposals/';
    @mkdir($upload_dir, 0755, true);
}

$safe_name = $year . sprintf('%02d',$month) . '_' . time() . '_'
           . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
$dest = $upload_dir . $safe_name;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    done_err("Could not save uploaded file. Tried: $dest — please check folder permissions on /uploads/damage_proposals/");
}

prog(15, 'File saved. Creating import record…');

// ─────────────────────────────────────────────────────────────────────────────
// CREATE IMPORT RECORD
// ─────────────────────────────────────────────────────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS damage_proposal_imports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255), stored_filename VARCHAR(255),
    proposal_month TINYINT, proposal_year SMALLINT,
    remarks TEXT, status VARCHAR(20) DEFAULT 'processing',
    total_records INT DEFAULT 0, imported_records INT DEFAULT 0,
    failed_records INT DEFAULT 0, imported_at DATETIME DEFAULT NOW()
)");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS damage_proposal_transaction_details (
    id INT AUTO_INCREMENT PRIMARY KEY, import_id INT,
    tso_plg VARCHAR(50), transaction_type VARCHAR(100),
    trans_ref_no VARCHAR(100), trans_date DATE,
    retailer_code VARCHAR(50), retailer_name VARCHAR(255),
    product_code VARCHAR(50), product_name VARCHAR(255),
    return_type VARCHAR(100), app_reason VARCHAR(100),
    pkm VARCHAR(50), batch_code VARCHAR(50),
    qty_free_qty DECIMAL(12,2), aip DECIMAL(12,4),
    mrp DECIMAL(12,2), tur DECIMAL(12,4),
    total_tur_value DECIMAL(14,2), total_aip DECIMAL(14,2),
    credit_note_no VARCHAR(100), credit_note_dt DATE,
    crdr_amt DECIMAL(14,2), adj_bill_ref_billing VARCHAR(100),
    adj_bill_ref_collection VARCHAR(100), original_bill_no VARCHAR(100)
)");

$fn  = mysqli_real_escape_string($conn, $file['name']);
$sf  = mysqli_real_escape_string($conn, $safe_name);
$rem = mysqli_real_escape_string($conn, $remarks);
mysqli_query($conn, "INSERT INTO damage_proposal_imports
    (filename,stored_filename,proposal_month,proposal_year,remarks,status)
    VALUES ('$fn','$sf',$month,$year,'$rem','processing')");

if (mysqli_errno($conn)) {
    @unlink($dest);
    done_err('DB error creating import record: ' . mysqli_error($conn));
}
$import_id = (int)mysqli_insert_id($conn);

prog(22, 'Parsing Excel file…');

// ─────────────────────────────────────────────────────────────────────────────
// PURE-PHP XLSX PARSER  (ZipArchive + SimpleXML — both standard in PHP 5.2+)
// ─────────────────────────────────────────────────────────────────────────────
if (!class_exists('ZipArchive')) {
    mysqli_query($conn,"UPDATE damage_proposal_imports SET status='failed' WHERE id=$import_id");
    done_err('PHP ZipArchive extension is not available on this server. Please enable it in php.ini.');
}

$zip = new ZipArchive();
if ($zip->open($dest) !== true) {
    mysqli_query($conn,"UPDATE damage_proposal_imports SET status='failed' WHERE id=$import_id");
    done_err('Cannot open the Excel file as a ZIP archive. The file may be corrupted or invalid.');
}

// ── Read workbook.xml ─────────────────────────────────────────────────────────
$wb_xml = $zip->getFromName('xl/workbook.xml');
if ($wb_xml === false) {
    $zip->close(); mysqli_query($conn,"UPDATE damage_proposal_imports SET status='failed' WHERE id=$import_id");
    done_err('Invalid Excel file: xl/workbook.xml missing.');
}
$wb = simplexml_load_string($wb_xml);

// ── Read relationships ────────────────────────────────────────────────────────
$rels_xml = $zip->getFromName('xl/_rels/workbook.xml.rels');
if ($rels_xml === false) {
    $zip->close(); mysqli_query($conn,"UPDATE damage_proposal_imports SET status='failed' WHERE id=$import_id");
    done_err('Invalid Excel file: relationships file missing.');
}
$rels_doc = simplexml_load_string($rels_xml);
$rid_map  = [];
foreach ($rels_doc->children() as $rel) {
    $rid_map[(string)$rel['Id']] = (string)$rel['Target'];
}

// ── Find TRANSACTION DETAILS sheet ───────────────────────────────────────────
$sheet_file  = null;
$sheet_names = [];
foreach ($wb->sheets->sheet as $sh) {
    $name = (string)$sh['name'];
    $sheet_names[] = $name;
    if (stripos($name, 'TRANSACTION DETAILS') !== false) {
        // Get rId via the r: namespace attribute
        $r_ns = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $attrs = $sh->attributes($r_ns);
        $rid   = (string)($attrs['id'] ?? '');
        if ($rid && isset($rid_map[$rid])) {
            $target     = $rid_map[$rid];
            // Target is like "worksheets/sheet2.xml" — prepend xl/
            $sheet_file = 'xl/' . ltrim($target, '/');
        }
    }
}

if (!$sheet_file) {
    $zip->close();
    mysqli_query($conn,"UPDATE damage_proposal_imports SET status='failed' WHERE id=$import_id");
    done_err('Sheet "TRANSACTION DETAILS" not found. Sheets in this file: ' . implode(' | ', $sheet_names));
}

prog(30, 'Reading shared strings…');

// ── Shared strings ────────────────────────────────────────────────────────────
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

// ── Worksheet XML ─────────────────────────────────────────────────────────────
$ws_xml = $zip->getFromName($sheet_file);
$zip->close();
if ($ws_xml === false) {
    mysqli_query($conn,"UPDATE damage_proposal_imports SET status='failed' WHERE id=$import_id");
    done_err("Cannot read sheet file: $sheet_file");
}

// Build $all_rows: [ row_number => [ col_index => value ] ]
$all_rows = [];
$ws = simplexml_load_string($ws_xml);
unset($ws_xml); // free memory

foreach ($ws->sheetData->row as $row_el) {
    $rnum  = (int)$row_el['r'];
    $cells = [];
    foreach ($row_el->c as $c) {
        $ref     = (string)$c['r'];
        $type    = (string)$c['t'];
        $col_idx = col_to_idx(cell_col($ref));

        if ($type === 's') {
            // Shared string — value is an index into $shared[]
            $v = isset($c->v) ? (string)$c->v : null;
            if ($v === null) continue;
            $cells[$col_idx] = $shared[(int)$v] ?? '';
        } elseif ($type === 'inlineStr') {
            // Inline string — value is in <is><t>...</t></is>
            // Used by LibreOffice / some Excel exporters instead of shared strings
            $v = isset($c->is->t) ? (string)$c->is->t : null;
            if ($v === null) continue;
            $cells[$col_idx] = $v;
        } else {
            // Numeric / date / boolean / formula result — raw value in <v>
            $v = isset($c->v) ? (string)$c->v : null;
            if ($v === null) continue;
            $cells[$col_idx] = $v;
        }
    }
    $all_rows[$rnum] = $cells;
}
unset($ws, $shared);

prog(52, 'Finding header row…');

// ─────────────────────────────────────────────────────────────────────────────
// FIND HEADER ROW (contains "TRANS REF NO")
// ─────────────────────────────────────────────────────────────────────────────
$header_row_num = null;
$col_map        = [];

foreach ($all_rows as $rnum => $cells) {
    foreach ($cells as $idx => $val) {
        if (is_string($val) && stripos(trim($val), 'TRANS REF NO') !== false) {
            $header_row_num = $rnum;
            break 2;
        }
    }
}

if ($header_row_num === null) {
    mysqli_query($conn,"UPDATE damage_proposal_imports SET status='failed' WHERE id=$import_id");
    done_err('Header row with "TRANS REF NO" not found in the TRANSACTION DETAILS sheet.');
}

foreach ($all_rows[$header_row_num] as $idx => $val) {
    $trimmed = trim((string)$val);
    if ($trimmed !== '') $col_map[$trimmed] = $idx;
}

// ─────────────────────────────────────────────────────────────────────────────
// EXTRACT DATA ROWS
// ─────────────────────────────────────────────────────────────────────────────
$date_cols = ['TRANS DATE', 'CREDIT NOTE DT'];
$data_rows = [];

foreach ($all_rows as $rnum => $cells) {
    if ($rnum <= $header_row_num) continue;
    $trans_idx = $col_map['TRANS REF NO'] ?? null;
    if ($trans_idx === null) continue;
    $ref_val = trim((string)($cells[$trans_idx] ?? ''));
    if ($ref_val === '') continue;

    $row = [];
    foreach ($col_map as $name => $idx) {
        $raw = $cells[$idx] ?? null;
        if ($raw === null || trim((string)$raw) === '') {
            $row[$name] = null;
        } elseif (in_array($name, $date_cols)) {
            $row[$name] = excel_date($raw);
        } else {
            $row[$name] = trim((string)$raw);
        }
    }
    $data_rows[] = $row;
}
unset($all_rows);

$total = count($data_rows);
if ($total === 0) {
    mysqli_query($conn,"UPDATE damage_proposal_imports SET status='completed',total_records=0,imported_records=0,failed_records=0 WHERE id=$import_id");
    done_ok(['status'=>'ok','ok'=>0,'fail'=>0,'import_id'=>$import_id]);
}

prog(58, "Parsed $total rows. Inserting into database…");

// ─────────────────────────────────────────────────────────────────────────────
// BATCH INSERT (250 rows per query)
// ─────────────────────────────────────────────────────────────────────────────
$ok = 0; $fail = 0;
$BATCH    = 250;
$chunks   = array_chunk($data_rows, $BATCH);
$n_chunks = count($chunks);
unset($data_rows);

$f = function($row, $key) use ($conn) {
    $v = $row[$key] ?? null;
    if ($v === null || $v === '') return 'NULL';
    return "'" . mysqli_real_escape_string($conn, $v) . "'";
};
$num = function($row, $key) {
    $v = $row[$key] ?? null;
    if ($v === null || $v === '') return 'NULL';
    $c = preg_replace('/[^0-9.\-]/', '', (string)$v);
    return is_numeric($c) ? $c : 'NULL';
};
$dt = function($row, $key) {
    $v = $row[$key] ?? null;
    if ($v === null || $v === '') return 'NULL';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return "'$v'";
    $ts = strtotime($v);
    return $ts ? "'" . date('Y-m-d', $ts) . "'" : 'NULL';
};

$insert_row = function($r) use ($import_id, $f, $num, $dt) {
    return "($import_id,"
        . $f($r,'TSO PLG')                     . ','
        . $f($r,'TRANSACTION TYPE')             . ','
        . $f($r,'TRANS REF NO')                 . ','
        . $dt($r,'TRANS DATE')                  . ','
        . $f($r,'RETAILER CODE')                . ','
        . $f($r,'RETAILER NAME')                . ','
        . $f($r,'PRODUCT CODE')                 . ','
        . $f($r,'PRODUCT NAME')                 . ','
        . $f($r,'RETURN TYPE')                  . ','
        . $f($r,'APP REASON')                   . ','
        . $f($r,'PKM')                          . ','
        . $f($r,'BATCH CODE')                   . ','
        . $num($r,'QTY/FREE QTY')               . ','
        . $num($r,'AIP')                        . ','
        . $num($r,'MRP')                        . ','
        . $num($r,'TUR')                        . ','
        . $num($r,'TOTAL TUR VALUE')             . ','
        . $num($r,'Total AIP')                  . ','
        . $f($r,'CREDIT NOTE NO')               . ','
        . $dt($r,'CREDIT NOTE DT')              . ','
        . $num($r,'CRDR_AMT')                   . ','
        . $f($r,'ADJ BILL REF NO - BILLING')    . ','
        . $f($r,'ADJ BILL REF NO - COLLECTION') . ','
        . $f($r,'Original Bill No')             . ')';
};

$col_list = "(import_id,tso_plg,transaction_type,trans_ref_no,trans_date,
     retailer_code,retailer_name,product_code,product_name,
     return_type,app_reason,pkm,batch_code,qty_free_qty,aip,
     mrp,tur,total_tur_value,total_aip,credit_note_no,
     credit_note_dt,crdr_amt,adj_bill_ref_billing,
     adj_bill_ref_collection,original_bill_no)";

foreach ($chunks as $ci => $chunk) {
    $vals = array_map($insert_row, $chunk);
    $sql  = "INSERT INTO damage_proposal_transaction_details $col_list VALUES " . implode(',', $vals);

    if (mysqli_query($conn, $sql)) {
        $ok += count($chunk);
    } else {
        // Batch failed → row-by-row fallback
        foreach ($chunk as $r) {
            $s2 = "INSERT INTO damage_proposal_transaction_details $col_list VALUES " . $insert_row($r);
            mysqli_query($conn, $s2) ? $ok++ : $fail++;
        }
    }

    $pct     = 58 + (int)(($ci + 1) / $n_chunks * 36);
    $done_n  = min(($ci + 1) * $BATCH, $total);
    prog($pct, "Inserted $done_n / $total rows…");
}

prog(96, 'Finalising…');

$status = ($ok === 0 && $fail > 0) ? 'failed' : 'completed';
mysqli_query($conn, "UPDATE damage_proposal_imports SET
    status='$status',
    total_records="    . ($ok+$fail) . ",
    imported_records=" . $ok . ",
    failed_records="   . $fail . "
    WHERE id=$import_id");

prog(100, 'Done!');

done_ok([
    'status'    => 'ok',
    'ok'        => $ok,
    'fail'      => $fail,
    'import_id' => $import_id,
]);