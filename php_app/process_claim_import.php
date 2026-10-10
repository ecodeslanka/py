<?php
/**
 * process_claim_import.php
 * Receives chunked Customer Claim Certificate records and inserts into DB.
 *
 * POST params per chunk:
 *   data           JSON array of claim records
 *   import_date    Y-m-d
 *   filename       original Excel filename
 *   remarks        optional remarks
 *   chunk_index    0-based
 *   total_chunks   total number of chunks
 *   total_records  full dataset size (used on chunk 0 to create parent record)
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
        die(json_encode(['success'=>false,'message'=>'Invalid request method']));
    }
    if (empty($_POST)) {
        die(json_encode(['success'=>false,'message'=>'POST data empty — post_max_size exceeded?']));
    }
    if (!isset($conn) || !$conn) {
        die(json_encode(['success'=>false,'message'=>'Database connection failed']));
    }

    /* ── Create tables if not exist ──────────────────────────────────────── */
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS customer_claim_imports (
        id              INT(11)        AUTO_INCREMENT PRIMARY KEY,
        import_date     DATE           NOT NULL,
        filename        VARCHAR(255)   NOT NULL,
        remarks         TEXT           NULL,
        total_records   INT(11)        DEFAULT 0,
        imported_records INT(11)       DEFAULT 0,
        failed_records  INT(11)        DEFAULT 0,
        status          ENUM('pending','processing','completed','failed') DEFAULT 'pending',
        imported_by     INT(11)        NULL,
        imported_at     TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_import_date (import_date),
        INDEX idx_status (status)
    )");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS customer_claim_certificates (
        id                  INT(11)        AUTO_INCREMENT PRIMARY KEY,
        import_id           INT(11)        NOT NULL,
        row_no              VARCHAR(20)    NULL,
        ledger_type         VARCHAR(100)   NULL,
        status              VARCHAR(50)    NULL,
        claim_type          VARCHAR(100)   NULL,
        tax_invoice_no      VARCHAR(100)   NULL,
        invoice_date        DATE           NULL,
        banking_date        DATE           NULL,
        entity              VARCHAR(50)    NULL,
        claim_description   TEXT           NULL,
        scheme_code         VARCHAR(50)    NULL,
        actual_amount       DECIMAL(14,2)  DEFAULT 0,
        vat_amount          DECIMAL(14,2)  DEFAULT 0,
        import_date         DATE           NULL,
        created_at          TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_import_id   (import_id),
        INDEX idx_scheme_code (scheme_code),
        INDEX idx_claim_type  (claim_type),
        INDEX idx_entity      (entity),
        INDEX idx_invoice_date (invoice_date),
        INDEX idx_tax_invoice  (tax_invoice_no)
    )");

    /* ── Read POST params ─────────────────────────────────────────────────── */
    $chunk_index   = intval($_POST['chunk_index']   ?? 0);
    $total_chunks  = intval($_POST['total_chunks']  ?? 1);
    $import_id     = intval($_POST['import_id']     ?? 0);
    $total_records = intval($_POST['total_records'] ?? 0);
    $import_date   = trim($_POST['import_date']     ?? '');
    $filename      = trim($_POST['filename']        ?? 'unknown.xlsx');
    $remarks       = trim($_POST['remarks']         ?? '');

    if (empty($import_date)) {
        die(json_encode(['success'=>false,'message'=>'Missing import_date']));
    }

    $raw = $_POST['data'] ?? '';
    if (empty($raw)) {
        die(json_encode(['success'=>false,'message'=>'No data field in POST']));
    }

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        die(json_encode(['success'=>false,'message'=>'JSON error: '.json_last_error_msg()]));
    }
    if (!is_array($data) || count($data) === 0) {
        die(json_encode(['success'=>false,'message'=>'Empty data array']));
    }

    $fn  = mysqli_real_escape_string($conn, $filename);
    $id  = mysqli_real_escape_string($conn, $import_date);
    $rem = mysqli_real_escape_string($conn, $remarks);

    /* ── First chunk: create parent import record ─────────────────────────── */
    if ($chunk_index === 0) {
        $q = "INSERT INTO customer_claim_imports
                  (import_date, filename, remarks, total_records, imported_records, failed_records, status)
              VALUES ('$id', '$fn', '$rem', $total_records, 0, 0, 'processing')";
        if (!mysqli_query($conn, $q)) {
            die(json_encode(['success'=>false,'message'=>'DB error creating import: '.mysqli_error($conn)]));
        }
        $import_id = mysqli_insert_id($conn);
    }

    if ($import_id <= 0) {
        die(json_encode(['success'=>false,'message'=>'Invalid import_id']));
    }

    /* ── Insert rows ─────────────────────────────────────────────────────── */
    $chunk_imported = 0;
    $chunk_failed   = 0;

    foreach ($data as $rec) {
        if (is_object($rec)) $rec = (array)$rec;

        $row_no      = mysqli_real_escape_string($conn, trim($rec['row_no']            ?? ''));
        $ledger_type = mysqli_real_escape_string($conn, trim($rec['ledger_type']       ?? ''));
        $status      = mysqli_real_escape_string($conn, trim($rec['status']            ?? ''));
        $claim_type  = mysqli_real_escape_string($conn, trim($rec['claim_type']        ?? ''));
        $tax_inv     = mysqli_real_escape_string($conn, trim($rec['tax_invoice_no']    ?? ''));
        $inv_date_r  = trim($rec['invoice_date']  ?? '');
        $bank_date_r = trim($rec['banking_date']  ?? '');
        $entity      = mysqli_real_escape_string($conn, trim($rec['entity']            ?? ''));
        $claim_desc  = mysqli_real_escape_string($conn, trim($rec['claim_description'] ?? ''));
        $scheme_code = mysqli_real_escape_string($conn, trim($rec['scheme_code']       ?? ''));
        $actual_amt  = floatval($rec['actual_amount'] ?? 0);
        $vat_amt     = floatval($rec['vat_amount']    ?? 0);

        // Validate / sanitise dates
        $inv_date_val  = validateDate($inv_date_r)  ? "'$inv_date_r'"  : 'NULL';
        $bank_date_val = validateDate($bank_date_r) ? "'$bank_date_r'" : 'NULL';

        $ins = "INSERT INTO customer_claim_certificates
            (import_id, row_no, ledger_type, status, claim_type, tax_invoice_no,
             invoice_date, banking_date, entity, claim_description, scheme_code,
             actual_amount, vat_amount, import_date)
            VALUES
            ($import_id, '$row_no', '$ledger_type', '$status', '$claim_type', '$tax_inv',
             $inv_date_val, $bank_date_val, '$entity', '$claim_desc', '$scheme_code',
             $actual_amt, $vat_amt, '$id')";

        if (mysqli_query($conn, $ins)) {
            $chunk_imported++;
        } else {
            $chunk_failed++;
        }
    }

    /* ── Update parent record ─────────────────────────────────────────────── */
    $is_last  = ($chunk_index === $total_chunks - 1);
    $new_stat = $is_last ? "'completed'" : "'processing'";

    mysqli_query($conn, "UPDATE customer_claim_imports
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
    echo json_encode(['success'=>false,'message'=>'Exception: '.$e->getMessage()]);
} catch (Error $e) {
    echo json_encode(['success'=>false,'message'=>'PHP Error: '.$e->getMessage()]);
}
exit;

/* ── Helper: validate Y-m-d date string ─────────────────────────────────── */
function validateDate(string $date): bool {
    if (empty($date)) return false;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}
?>
