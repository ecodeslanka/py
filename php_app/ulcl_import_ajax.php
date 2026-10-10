<?php
/**
 * ulcl_import_ajax.php
 * Handles ULCL / USLL Customer Ledger Excel upload → MySQL import.
 * Returns JSON. No exec(), no external libraries required.
 */

ob_start();

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $err['message'] . ' (line ' . $err['line'] . ' in ' . basename($err['file']) . ')'
        ]);
    }
});

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json');
include 'config.php';

// ── Helpers ───────────────────────────────────────────────────────────────────
function jsonOut($arr) {
    ob_clean();
    echo json_encode($arr);
    exit;
}

function parseDate($val) {
    if (!$val || trim($val) === '') return null;
    $val = trim($val);
    foreach (['d/m/y', 'd/m/Y', 'Y-m-d', 'd-m-Y', 'm/d/Y', 'd.m.Y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $val);
        if ($d) return $d->format('Y-m-d');
    }
    $ts = strtotime($val);
    return $ts ? date('Y-m-d', $ts) : null;
}

function safeDecimal($v) {
    if ($v === null || $v === '') return 0.00;
    $cleaned = preg_replace('/[^\d.\-]/', '', str_replace(',', '', (string)$v));
    return is_numeric($cleaned) ? (float)$cleaned : 0.00;
}

function excelSerialToDate($serial) {
    if (!is_numeric($serial) || (int)$serial <= 0) return null;
    return date('Y-m-d', ((int)$serial - 25569) * 86400);
}

/**
 * Convert a column letter string (A, B, … Z, AA, AB …) to a 0-based index.
 */
function colLetterToIndex($letters) {
    $idx = 0;
    foreach (str_split(strtoupper($letters)) as $ch) {
        $idx = $idx * 26 + (ord($ch) - 64);
    }
    return $idx - 1;
}

/**
 * Pure-PHP xlsx reader using ZipArchive + DOMDocument.
 * DOMDocument handles default XML namespaces correctly without
 * the XPath prefix registration issues that affect SimpleXML.
 *
 * Returns ['rows' => [...]]  — row 0 is the header row.
 * Returns ['error' => '...'] on failure.
 */
function parseXlsxNative($path) {
    if (!class_exists('ZipArchive')) {
        return ['error' => 'ZipArchive extension is not available on this server.'];
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['error' => 'Could not open xlsx file as a ZIP archive.'];
    }

    $NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    // ── Helper: load XML from zip entry into DOMDocument ─────────────────────
    $loadDom = function($zip, $entry) {
        $data = $zip->getFromName($entry);
        if ($data === false) return null;
        $dom = new DOMDocument();
        // Suppress warnings for malformed XML
        if (!@$dom->loadXML($data, LIBXML_NOERROR | LIBXML_NOWARNING)) return null;
        return $dom;
    };

    // ── Shared strings ────────────────────────────────────────────────────────
    $sharedStrings = [];
    $ssDom = $loadDom($zip, 'xl/sharedStrings.xml');
    if ($ssDom) {
        $xpath = new DOMXPath($ssDom);
        $xpath->registerNamespace('x', $NS);
        // Each <si> element → concatenate all <t> descendants (handles rich text)
        foreach ($xpath->query('//x:si') as $si) {
            $text = '';
            foreach ($xpath->query('.//x:t', $si) as $t) {
                $text .= $t->nodeValue;
            }
            $sharedStrings[] = $text;
        }
    }

    // ── Find first worksheet path via workbook relationships ──────────────────
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $relsDom   = $loadDom($zip, 'xl/_rels/workbook.xml.rels');
    if ($relsDom) {
        foreach ($relsDom->getElementsByTagName('Relationship') as $rel) {
            if (strpos($rel->getAttribute('Type'), 'worksheet') !== false) {
                $target    = ltrim($rel->getAttribute('Target'), '/');
                $sheetPath = (strpos($target, 'xl/') === 0) ? $target : 'xl/' . $target;
                break;
            }
        }
    }

    // ── Load worksheet ────────────────────────────────────────────────────────
    $wsDom = $loadDom($zip, $sheetPath);
    $zip->close();

    if (!$wsDom) {
        return ['error' => "Could not read worksheet '$sheetPath' from xlsx."];
    }

    $wsXpath = new DOMXPath($wsDom);
    $wsXpath->registerNamespace('x', $NS);

    // ── Parse rows ────────────────────────────────────────────────────────────
    $allRows = [];

    foreach ($wsXpath->query('//x:sheetData/x:row') as $rowEl) {
        $rowData = [];

        foreach ($wsXpath->query('x:c', $rowEl) as $cell) {
            $ref      = $cell->getAttribute('r');           // e.g. "B3"
            $type     = $cell->getAttribute('t');           // "s", "n", "", "inlineStr"
            $vNodes   = $wsXpath->query('x:v', $cell);
            $val      = $vNodes->length > 0 ? $vNodes->item(0)->nodeValue : '';

            // Resolve shared string index → actual text
            if ($type === 's' && $val !== '') {
                $val = $sharedStrings[(int)$val] ?? '';
            } elseif ($type === 'inlineStr') {
                $isNodes = $wsXpath->query('x:is/x:t', $cell);
                $val     = $isNodes->length > 0 ? $isNodes->item(0)->nodeValue : '';
            }

            // Column index from cell reference
            preg_match('/^([A-Z]+)/', strtoupper($ref), $m);
            $colIdx          = colLetterToIndex($m[1] ?? 'A');
            $rowData[$colIdx] = $val;
        }

        if (empty($rowData)) continue;

        // Normalise to a contiguous 6-element array
        $maxIdx = max(array_keys($rowData));
        $out    = [];
        for ($i = 0; $i <= max($maxIdx, 5); $i++) {
            $out[] = isset($rowData[$i]) ? $rowData[$i] : '';
        }
        $allRows[] = $out;
    }

    return ['rows' => $allRows];
}

try {

// ── Validate request ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['success' => false, 'message' => 'Invalid request method.']);
}

$company    = isset($_POST['company'])    ? trim($_POST['company'])    : '';
$entry_date = isset($_POST['entry_date']) ? trim($_POST['entry_date']) : '';

if (!in_array($company, ['ULCL', 'USLL'])) {
    jsonOut(['success' => false, 'message' => 'Invalid company. Must be ULCL or USLL.']);
}
if (!$entry_date || !DateTime::createFromFormat('Y-m-d', $entry_date)) {
    jsonOut(['success' => false, 'message' => 'Invalid entry date.']);
}
if (empty($_FILES['ledger_file']) || $_FILES['ledger_file']['error'] !== UPLOAD_ERR_OK) {
    $err_codes = [1=>'File too large',2=>'File too large',3=>'Partial upload',4=>'No file',6=>'No tmp dir',7=>'Write failed'];
    $code = $_FILES['ledger_file']['error'] ?? 4;
    jsonOut(['success' => false, 'message' => 'File upload error: ' . ($err_codes[$code] ?? 'Unknown')]);
}

$file = $_FILES['ledger_file'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx', 'xls'])) {
    jsonOut(['success' => false, 'message' => 'Only .xlsx and .xls files are accepted.']);
}

$tmp_path = $file['tmp_name'];
$filename = basename($file['name']);

// ── Parse Excel ───────────────────────────────────────────────────────────────
$rows        = [];
$parse_error = null;

// Attempt 1: PhpSpreadsheet (if installed via Composer)
if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp_path);
        $sheet       = $spreadsheet->getActiveSheet();
        $highest_row = $sheet->getHighestDataRow();

        $hdr      = [];
        for ($c = 1; $c <= 6; $c++) {
            $hdr[] = trim(strtolower((string)$sheet->getCellByColumnAndRow($c, 1)->getValue()));
        }
        $expected = ['date','transaction type','customer reference','debit','credit','balance'];
        if (array_filter($hdr) && $hdr !== $expected) {
            jsonOut(['success' => false, 'message' => 'Invalid file. Expected columns: Date, Transaction Type, Customer Reference, Debit, Credit, Balance. Found: [' . implode(', ', array_filter($hdr)) . ']']);
        }

        for ($r = 2; $r <= $highest_row; $r++) {
            $date_cell = $sheet->getCell('A' . $r);
            $raw_date  = \PhpOffice\PhpSpreadsheet\Style\NumberFormat::toFormattedString(
                $date_cell->getValue(), 'DD/MM/YYYY'
            );
            $rows[] = [
                'txn_date' => $raw_date,
                'txn_type' => trim((string)$sheet->getCell('B' . $r)->getValue()),
                'cust_ref' => trim((string)$sheet->getCell('C' . $r)->getValue()),
                'debit'    => $sheet->getCell('D' . $r)->getValue(),
                'credit'   => $sheet->getCell('E' . $r)->getValue(),
                'balance'  => $sheet->getCell('F' . $r)->getValue(),
            ];
        }
    } catch (Exception $e) {
        $parse_error = $e->getMessage();
    }
}

// Attempt 2: SimpleXLSX
if (empty($rows) && class_exists('SimpleXLSX')) {
    if ($xlsx = SimpleXLSX::parse($tmp_path)) {
        $all = $xlsx->rows();
        if (!empty($all[0])) {
            $hdr      = array_values(array_filter(array_map(fn($v) => trim(strtolower((string)$v)), $all[0]), fn($v) => $v !== ''));
            $expected = ['date','transaction type','customer reference','debit','credit','balance'];
            if ($hdr !== $expected) {
                jsonOut(['success' => false, 'message' => 'Invalid file. Expected columns: Date, Transaction Type, Customer Reference, Debit, Credit, Balance. Found: [' . implode(', ', $hdr) . ']']);
            }
        }
        for ($i = 1; $i < count($all); $i++) {
            $r = $all[$i];
            if (count($r) < 4) continue;
            $rows[] = [
                'txn_date' => $r[0] ?? '',
                'txn_type' => trim((string)($r[1] ?? '')),
                'cust_ref' => trim((string)($r[2] ?? '')),
                'debit'    => $r[3] ?? 0,
                'credit'   => $r[4] ?? 0,
                'balance'  => $r[5] ?? 0,
            ];
        }
    }
}

// Attempt 3: Native ZipArchive + DOMDocument (no exec, no external libs)
if (empty($rows)) {
    $native = parseXlsxNative($tmp_path);

    if (isset($native['error'])) {
        jsonOut(['success' => false, 'message' => 'Could not read Excel file: ' . $native['error']]);
    }

    $raw_all  = $native['rows'];
    $expected = ['date','transaction type','customer reference','debit','credit','balance'];

    // Validate header row (index 0)
    if (!empty($raw_all[0])) {
        $hdr = array_values(array_filter(
            array_map(fn($v) => trim(strtolower((string)$v)), $raw_all[0]),
            fn($v) => $v !== ''
        ));
        if ($hdr !== $expected) {
            jsonOut([
                'success' => false,
                'message' => 'Invalid file. This does not appear to be a Customer Ledger Summary Report. '
                           . 'Expected columns: Date, Transaction Type, Customer Reference, Debit, Credit, Balance. '
                           . 'Found: [' . implode(', ', $hdr) . ']'
            ]);
        }
    }

    // Data rows start at index 1
    for ($i = 1; $i < count($raw_all); $i++) {
        $r = $raw_all[$i];
        if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) === 0) continue;

        $rawDate = trim((string)($r[0] ?? ''));
        // Numeric serial date (e.g. 46023) → Y-m-d
        if (is_numeric($rawDate) && (int)$rawDate > 1000) {
            $dateVal = excelSerialToDate((int)$rawDate);
        } else {
            $dateVal = $rawDate;
        }

        $rows[] = [
            'txn_date' => $dateVal,
            'txn_type' => trim((string)($r[1] ?? '')),
            'cust_ref' => trim((string)($r[2] ?? '')),
            'debit'    => $r[3] ?? 0,
            'credit'   => $r[4] ?? 0,
            'balance'  => $r[5] ?? 0,
        ];
    }
}

if (empty($rows)) {
    jsonOut(['success' => false, 'message' => 'No data rows found in the uploaded file.']);
}

// ── Create import record ──────────────────────────────────────────────────────
$esc_company    = mysqli_real_escape_string($conn, $company);
$esc_entry_date = mysqli_real_escape_string($conn, $entry_date);
$esc_filename   = mysqli_real_escape_string($conn, $filename);
$total_rows     = count($rows);

mysqli_query($conn, "INSERT INTO ulcl_imports
    (company, entry_date, filename, total_records, imported_records, duplicate_records, failed_records, status)
    VALUES ('$esc_company', '$esc_entry_date', '$esc_filename', $total_rows, 0, 0, 0, 'processing')");
$import_id = (int)mysqli_insert_id($conn);

if (!$import_id) {
    jsonOut(['success' => false, 'message' => 'Failed to create import record in database.']);
}

// ── Insert rows ───────────────────────────────────────────────────────────────
$imported   = 0;
$duplicates = 0;
$failed     = 0;
$errors     = [];

foreach ($rows as $idx => $row) {
    $txn_type = trim((string)($row['txn_type'] ?? ''));
    if (in_array(strtolower($txn_type), ['transaction type', ''])) continue;

    $txn_date = parseDate($row['txn_date'] ?? '');
    $cust_ref = trim((string)($row['cust_ref'] ?? ''));
    $debit    = safeDecimal($row['debit']   ?? 0);
    $credit   = safeDecimal($row['credit']  ?? 0);
    $balance  = safeDecimal($row['balance'] ?? 0);

    $esc_txn_type = mysqli_real_escape_string($conn, $txn_type);
    $esc_cust_ref = mysqli_real_escape_string($conn, $cust_ref);
    $esc_txn_date = $txn_date ? "'$txn_date'" : 'NULL';
    $cust_ref_val = $cust_ref !== '' ? "'$esc_cust_ref'" : 'NULL';

    $ok = mysqli_query($conn, "INSERT IGNORE INTO ulcl_ledger
        (import_id, company, entry_date, txn_date, transaction_type, customer_reference, debit, credit, balance)
        VALUES
        ($import_id, '$esc_company', '$esc_entry_date', $esc_txn_date, '$esc_txn_type', $cust_ref_val, $debit, $credit, $balance)");

    if ($ok) {
        $affected = mysqli_affected_rows($conn);
        if ($affected === 0) { $duplicates++; } else { $imported++; }
    } else {
        $failed++;
        if (count($errors) < 10) {
            $errors[] = "Row " . ($idx + 2) . ": " . mysqli_error($conn);
        }
    }
}

// ── Update import record ──────────────────────────────────────────────────────
$status      = ($failed > 0 && $imported === 0) ? 'failed' : 'completed';
$remarks_arr = [];
if ($duplicates > 0) $remarks_arr[] = "$duplicates duplicate(s) skipped";
if ($failed > 0)     $remarks_arr[] = "$failed row(s) failed";
$remarks = mysqli_real_escape_string($conn, implode(', ', $remarks_arr));

mysqli_query($conn, "UPDATE ulcl_imports SET
    imported_records  = $imported,
    duplicate_records = $duplicates,
    failed_records    = $failed,
    status            = '$status',
    remarks           = '$remarks'
    WHERE id = $import_id");

jsonOut([
    'success'    => true,
    'import_id'  => $import_id,
    'total'      => $total_rows,
    'imported'   => $imported,
    'duplicates' => $duplicates,
    'failed'     => $failed,
    'errors'     => $errors,
    'message'    => "Import completed. $imported new rows imported, $duplicates duplicates skipped."
]);

} catch (Throwable $e) {
    jsonOut([
        'success' => false,
        'message' => 'Unexpected server error: ' . $e->getMessage() . ' (line ' . $e->getLine() . ' in ' . basename($e->getFile()) . ')'
    ]);
}