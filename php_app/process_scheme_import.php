<?php
/**
 * process_scheme_import.php
 * Receives validated scheme discount chunks and inserts them into DB.
 * Supports chunked upload to avoid 504 Gateway Timeout on large files.
 *
 * POST params per chunk:
 *   data           JSON array of validated records
 *   bill_date      Y-m-d
 *   filename       original Excel filename
 *   chunk_index    0-based chunk number
 *   total_chunks   total number of chunks
 *   total_records  full dataset size (for creating the parent record on chunk 0)
 *   import_id      0 on first chunk; returned value for subsequent chunks
 */
error_reporting(0);
ini_set('display_errors', 0);
ini_set('max_execution_time', 120);
ini_set('memory_limit', '256M');

ob_start();
include 'config.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        die(json_encode(['success' => false, 'message' => 'Invalid request method']));
    }
    if (empty($_POST) && empty($_FILES)) {
        die(json_encode(['success' => false, 'message' => 'POST data empty — post_max_size exceeded']));
    }
    if (!$conn) {
        die(json_encode(['success' => false, 'message' => 'Database connection failed']));
    }

    /* ── Ensure tables exist ───────────────────────────────────────────────── */
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS scheme_discount_imports (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        bill_date_filter DATE NOT NULL,
        filename VARCHAR(255) NOT NULL,
        total_records INT(11) DEFAULT 0,
        imported_records INT(11) DEFAULT 0,
        failed_records INT(11) DEFAULT 0,
        status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
        imported_by INT(11) NULL,
        imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_bill_date_filter (bill_date_filter),
        INDEX idx_status (status)
    )");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS scheme_discount_import_details (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        import_id INT(11) NOT NULL,
        scheme_no VARCHAR(100) NULL,
        scheme_desc VARCHAR(255) NULL,
        scheme_type VARCHAR(50) NULL,
        bill_no VARCHAR(100) NULL,
        bill_date DATE NULL,
        beat_name VARCHAR(255) NULL,
        hul_code VARCHAR(100) NULL,
        party_name VARCHAR(255) NULL,
        sku7_code VARCHAR(100) NULL,
        product_name VARCHAR(255) NULL,
        sold_qty DECIMAL(12,4) DEFAULT 0,
        free_qty DECIMAL(12,4) DEFAULT 0,
        free_value DECIMAL(12,2) DEFAULT 0,
        sch_disc DECIMAL(12,2) DEFAULT 0,
        gross_sales DECIMAL(12,2) DEFAULT 0,
        salesman_code VARCHAR(100) NULL,
        bill_date_filter DATE NULL,
        t_code_valid TINYINT(1) DEFAULT 0,
        route_valid TINYINT(1) DEFAULT 0,
        customer_name VARCHAR(255) NULL,
        route_name VARCHAR(255) NULL,
        status ENUM('pending','imported','failed') DEFAULT 'pending',
        error_message TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_import_id (import_id),
        INDEX idx_status (status),
        INDEX idx_hul_code (hul_code),
        INDEX idx_bill_no (bill_no)
    )");

    /* ── Read POST params ──────────────────────────────────────────────────── */
    $chunk_index   = intval($_POST['chunk_index']   ?? 0);
    $total_chunks  = intval($_POST['total_chunks']  ?? 1);
    $import_id     = intval($_POST['import_id']     ?? 0);
    $total_records = intval($_POST['total_records'] ?? 0);
    $bill_date     = trim($_POST['bill_date']       ?? '');
    $filename      = trim($_POST['filename']        ?? 'unknown.xlsx');

    if (empty($bill_date)) {
        die(json_encode(['success' => false, 'message' => 'Missing bill_date']));
    }

    $raw = $_POST['data'] ?? '';
    if (empty($raw)) {
        die(json_encode(['success' => false, 'message' => 'No data field in POST']));
    }

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        die(json_encode(['success' => false, 'message' => 'JSON error: ' . json_last_error_msg()]));
    }
    if (!is_array($data) || count($data) === 0) {
        die(json_encode(['success' => false, 'message' => 'Empty data array']));
    }
    if (empty($bill_date)) {
        die(json_encode(['success' => false, 'message' => 'Missing bill_date']));
    }

    $fn = mysqli_real_escape_string($conn, $filename);
    $bd = mysqli_real_escape_string($conn, $bill_date);

    /* ── First chunk: create parent import record ──────────────────────────── */
    if ($chunk_index === 0) {
        $q = "INSERT INTO scheme_discount_imports
                  (bill_date_filter, filename, total_records, imported_records, failed_records, status)
              VALUES ('$bd', '$fn', $total_records, 0, 0, 'processing')";
        if (!mysqli_query($conn, $q)) {
            die(json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)]));
        }
        $import_id = mysqli_insert_id($conn);
    }

    if ($import_id <= 0) {
        die(json_encode(['success' => false, 'message' => 'Invalid import_id']));
    }

    /* ── Insert rows ───────────────────────────────────────────────────────── */
    $chunk_imported = 0;
    $chunk_failed   = 0;

    foreach ($data as $rec) {
        if (is_object($rec)) $rec = (array)$rec;

        $scheme_no    = mysqli_real_escape_string($conn, $rec['scheme_no']    ?? '');
        $scheme_desc  = mysqli_real_escape_string($conn, $rec['scheme_desc']  ?? '');
        $scheme_type  = mysqli_real_escape_string($conn, $rec['scheme_type']  ?? '');
        $bill_no      = mysqli_real_escape_string($conn, $rec['bill_no']      ?? '');
        $bill_date    = mysqli_real_escape_string($conn, $rec['bill_date']    ?? '');
        $beat_name    = mysqli_real_escape_string($conn, $rec['beat_name']    ?? '');
        $hul_code     = mysqli_real_escape_string($conn, $rec['hul_code']     ?? '');
        $party_name   = mysqli_real_escape_string($conn, $rec['party_name']   ?? '');
        $sku7_code    = mysqli_real_escape_string($conn, $rec['sku7_code']    ?? '');
        $product_name = mysqli_real_escape_string($conn, $rec['product_name'] ?? '');
        $sold_qty     = floatval($rec['sold_qty']     ?? 0);
        $free_qty     = floatval($rec['free_qty']     ?? 0);
        $free_value   = floatval($rec['free_value']   ?? 0);
        $sch_disc     = floatval($rec['sch_disc']     ?? 0);
        $gross_sales  = floatval($rec['gross_sales']  ?? 0);
        $sal_code     = mysqli_real_escape_string($conn, $rec['salesman_code']?? '');
        $cust_name    = mysqli_real_escape_string($conn, $rec['customer_name']?? '');
        $route_name   = mysqli_real_escape_string($conn, $rec['route_name']   ?? '');

        $bill_date_val = !empty($bill_date) ? "'$bill_date'" : 'NULL';

        $ins = "INSERT INTO scheme_discount_import_details
            (import_id,
             scheme_no, scheme_desc, scheme_type, bill_no, bill_date, beat_name,
             hul_code, party_name, sku7_code, product_name,
             sold_qty, free_qty, free_value, sch_disc, gross_sales,
             salesman_code, bill_date_filter,
             t_code_valid, route_valid, customer_name, route_name, status)
            VALUES
            ($import_id,
             '$scheme_no', '$scheme_desc', '$scheme_type', '$bill_no', $bill_date_val, '$beat_name',
             '$hul_code', '$party_name', '$sku7_code', '$product_name',
             $sold_qty, $free_qty, $free_value, $sch_disc, $gross_sales,
             '$sal_code', '$bd',
             1, 1, '$cust_name', '$route_name', 'imported')";

        if (mysqli_query($conn, $ins)) {
            $chunk_imported++;
        } else {
            $chunk_failed++;
        }
    }

    /* ── Update parent record ──────────────────────────────────────────────── */
    $is_last  = ($chunk_index === $total_chunks - 1);
    $new_stat = $is_last ? "'completed'" : "'processing'";

    mysqli_query($conn, "UPDATE scheme_discount_imports
                         SET imported_records = imported_records + $chunk_imported,
                             failed_records   = failed_records   + $chunk_failed,
                             status           = $new_stat
                         WHERE id = $import_id");

    echo json_encode([
        'success'        => true,
        'import_id'      => $import_id,
        'chunk_index'    => $chunk_index,
        'total_chunks'   => $total_chunks,
        'chunk_imported' => $chunk_imported,
        'chunk_failed'   => $chunk_failed,
        'is_last_chunk'  => $is_last,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
} catch (Error $e) {
    echo json_encode(['success' => false, 'message' => 'PHP Error: ' . $e->getMessage()]);
}
exit;
?>