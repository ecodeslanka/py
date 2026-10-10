<?php
error_reporting(0);
ini_set('display_errors', 0);
ini_set('max_execution_time', 300);
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
        die(json_encode(['success' => false, 'message' => 'POST data empty - post_max_size exceeded.']));
    }

    if (!$conn) {
        die(json_encode(['success' => false, 'message' => 'Database connection failed']));
    }

    // Create tables
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS loading_summary_imports (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        delivery_date DATE NOT NULL,
        filename VARCHAR(255) NOT NULL,
        total_records INT(11) DEFAULT 0,
        imported_records INT(11) DEFAULT 0,
        failed_records INT(11) DEFAULT 0,
        status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
        imported_by INT(11) NULL,
        imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_delivery_date (delivery_date),
        INDEX idx_status (status)
    )");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS loading_summary_import_details (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        import_id INT(11) NOT NULL,
        sales_person_code VARCHAR(100) NULL,
        t_code VARCHAR(50) NULL,
        route_code VARCHAR(50) NULL,
        bill_no VARCHAR(100) NULL,
        bill_date DATE NULL,
        outlet_code VARCHAR(100) NULL,
        party_name VARCHAR(255) NULL,
        free_qty DECIMAL(12,2) DEFAULT 0,
        gross_sales DECIMAL(12,2) DEFAULT 0,
        scheme_disc DECIMAL(12,2) DEFAULT 0,
        rs_discount DECIMAL(12,2) DEFAULT 0,
        tot_disc DECIMAL(12,2) DEFAULT 0,
        total_discount DECIMAL(12,2) DEFAULT 0,
        taxable_amount DECIMAL(12,2) DEFAULT 0,
        tax_amount DECIMAL(12,2) DEFAULT 0,
        bill_value DECIMAL(12,2) DEFAULT 0,
        good_returns_value DECIMAL(12,2) DEFAULT 0,
        damage_expiry_shortage_value DECIMAL(12,2) DEFAULT 0,
        final_bill_amount DECIMAL(12,2) DEFAULT 0,
        delivery_person VARCHAR(255) NULL,
        delivery_date DATE NULL,
        t_code_valid TINYINT(1) DEFAULT 0,
        route_valid TINYINT(1) DEFAULT 0,
        customer_name VARCHAR(255) NULL,
        route_name VARCHAR(255) NULL,
        status ENUM('pending', 'imported', 'failed', 'cancelled') DEFAULT 'pending',
        error_message TEXT NULL,
        loading_summary_id INT(11) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_import_id (import_id),
        INDEX idx_status (status)
    )");

    // ── Migrate: add tax columns to loading_summary_import_details if missing ──
    foreach ([
        'taxable_amount'    => "ALTER TABLE loading_summary_import_details ADD COLUMN taxable_amount DECIMAL(12,2) DEFAULT 0 AFTER total_discount",
        'tax_amount'        => "ALTER TABLE loading_summary_import_details ADD COLUMN tax_amount DECIMAL(12,2) DEFAULT 0 AFTER taxable_amount",
        'bill_value'        => "ALTER TABLE loading_summary_import_details ADD COLUMN bill_value DECIMAL(12,2) DEFAULT 0 AFTER tax_amount",
        // Blacklisted rows now stay in THIS table (status='cancelled') instead of
        // a separate holding table, so we keep the blacklist context alongside them.
        'blacklist_reason'  => "ALTER TABLE loading_summary_import_details ADD COLUMN blacklist_reason TEXT NULL AFTER route_name",
        'blacklist_date'    => "ALTER TABLE loading_summary_import_details ADD COLUMN blacklist_date DATETIME NULL AFTER blacklist_reason",
    ] as $col => $alterSql) {
        $chk = mysqli_query($conn, "SHOW COLUMNS FROM loading_summary_import_details LIKE '$col'");
        if ($chk && mysqli_num_rows($chk) === 0) {
            mysqli_query($conn, $alterSql);
        }
    }

    // ── Migrate: widen status ENUM to include 'cancelled' if an older table pre-dates it ──
    $statusCol = mysqli_query($conn, "SHOW COLUMNS FROM loading_summary_import_details LIKE 'status'");
    if ($statusCol && $scRow = mysqli_fetch_assoc($statusCol)) {
        if (strpos($scRow['Type'], "'cancelled'") === false) {
            mysqli_query($conn, "ALTER TABLE loading_summary_import_details
                MODIFY COLUMN status ENUM('pending','imported','failed','cancelled') DEFAULT 'pending'");
        }
    }

    $raw = isset($_POST['data']) ? $_POST['data'] : '';
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

    $delivery_date = isset($_POST['delivery_date']) ? $_POST['delivery_date'] : '';
    $filename      = isset($_POST['filename'])      ? $_POST['filename']      : 'unknown.xlsx';

    if (empty($delivery_date)) {
        die(json_encode(['success' => false, 'message' => 'Missing delivery date']));
    }

    // Filter valid records only
    $valid_data = [];
    foreach ($data as $record) {
        if (is_object($record)) $record = (array)$record;
        if (!empty($record['t_code_valid']) && !empty($record['route_valid'])) {
            $valid_data[] = $record;
        }
    }

    $total_records = count($valid_data);
    if ($total_records === 0) {
        die(json_encode(['success' => false, 'message' => 'No valid records to import']));
    }

    $fn = mysqli_real_escape_string($conn, $filename);
    $dd = mysqli_real_escape_string($conn, $delivery_date);

    $existing_import_id = isset($_POST['import_id']) ? intval($_POST['import_id']) : 0;
    $is_first_chunk     = isset($_POST['is_first_chunk']) ? ($_POST['is_first_chunk'] === 'true') : true;
    $chunk_total        = isset($_POST['chunk_total'])    ? intval($_POST['chunk_total'])          : $total_records;
    // Check if frontend says to update an existing same-date import
    $update_existing_id = isset($_POST['update_existing_import_id']) ? intval($_POST['update_existing_import_id']) : 0;

    if ($existing_import_id > 0 && !$is_first_chunk) {
        // Subsequent chunk
        $import_id = $existing_import_id;
    } elseif ($update_existing_id > 0 && $is_first_chunk) {
        // Same-date re-import: reuse the existing import record
        $import_id = $update_existing_id;
        // Update filename and set status back to processing; add to total_records
        mysqli_query($conn, "UPDATE loading_summary_imports 
                             SET filename = CONCAT(filename, ' + ', '$fn'),
                                 total_records = total_records + $chunk_total,
                                 status = 'processing',
                                 imported_at = NOW()
                             WHERE id = $import_id");
    } else {
        // Brand new import
        $q = "INSERT INTO loading_summary_imports (delivery_date, filename, total_records, imported_records, failed_records, status) 
              VALUES ('$dd', '$fn', $chunk_total, 0, 0, 'processing')";
        if (!mysqli_query($conn, $q)) {
            die(json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)]));
        }
        $import_id = mysqli_insert_id($conn);
    }

    $imported  = 0;
    $failed    = 0;
    $cancelled = 0;

    foreach ($valid_data as $rec) {
        if (is_object($rec)) $rec = (array)$rec;

        $sales_code  = mysqli_real_escape_string($conn, $rec['sales_person_code']           ?? '');
        $t_code      = mysqli_real_escape_string($conn, $rec['t_code']                      ?? '');
        $route_code  = mysqli_real_escape_string($conn, $rec['route_code']                  ?? '');
        $bill_no     = mysqli_real_escape_string($conn, $rec['bill_no']                     ?? '');
        $bill_date   = mysqli_real_escape_string($conn, $rec['bill_date']                   ?? '');
        $outlet_code = mysqli_real_escape_string($conn, $rec['outlet_code']                 ?? '');
        $party_name  = mysqli_real_escape_string($conn, $rec['party_name']                  ?? '');
        $free_qty    = floatval($rec['free_qty']                                            ?? 0);
        $gross_sales = floatval($rec['gross_sales']                                         ?? 0);
        $scheme_disc = floatval($rec['scheme_disc']                                         ?? 0);
        $rs_discount = floatval($rec['rs_discount']                                         ?? 0);
        $tot_disc    = floatval($rec['tot_disc']                                            ?? 0);
        $total_discount = floatval($rec['total_discount']                                   ?? 0);
        $taxable_amount = floatval($rec['taxable_amount']                                   ?? 0);
        $tax_amount  = floatval($rec['tax_amount']                                          ?? 0);
        $bill_value  = floatval($rec['bill_value']                                          ?? 0);
        $good_returns = floatval($rec['good_returns_value']                                 ?? 0);
        $dmg_expiry  = floatval($rec['damage_expiry_shortage_value']                        ?? 0);
        $amount      = floatval($rec['final_bill_amount']                                   ?? 0);
        $delivery_person = mysqli_real_escape_string($conn, $rec['delivery_person']         ?? '');
        $cust_name   = mysqli_real_escape_string($conn, $rec['customer_name']               ?? '');
        $route_name  = mysqli_real_escape_string($conn, $rec['route_name']                  ?? '');

        $bill_date_val = !empty($bill_date) ? "'$bill_date'" : 'NULL';

        // ── Blacklisted customers stay in THIS SAME list — they are just ──────
        // marked as 'cancelled' instead of 'imported'. Everyone else is fine.
        $is_bl = !empty($rec['is_blacklisted']);
        $row_status = $is_bl ? 'cancelled' : 'imported';

        $bl_reason = mysqli_real_escape_string($conn, $rec['blacklist_reason'] ?? '');
        $bl_date   = !empty($rec['blacklist_date'])
            ? "'" . mysqli_real_escape_string($conn, $rec['blacklist_date']) . "'"
            : 'NULL';

        $ins = "INSERT INTO loading_summary_import_details
            (import_id,
             sales_person_code, t_code, route_code, bill_no, bill_date, outlet_code, party_name,
             free_qty, gross_sales, scheme_disc, rs_discount, tot_disc, total_discount,
             taxable_amount, tax_amount, bill_value,
             good_returns_value, damage_expiry_shortage_value, final_bill_amount,
             delivery_person,
             delivery_date, t_code_valid, route_valid, customer_name, route_name,
             blacklist_reason, blacklist_date, status)
            VALUES
            ($import_id,
             '$sales_code', '$t_code', '$route_code', '$bill_no', $bill_date_val, '$outlet_code', '$party_name',
             $free_qty, $gross_sales, $scheme_disc, $rs_discount, $tot_disc, $total_discount,
             $taxable_amount, $tax_amount, $bill_value,
             $good_returns, $dmg_expiry, $amount,
             '$delivery_person',
             '$dd', 1, 1, '$cust_name', '$route_name',
             " . ($is_bl ? "'$bl_reason', $bl_date" : "NULL, NULL") . ", '$row_status')";

        if (mysqli_query($conn, $ins)) {
            $imported++;
            if ($is_bl) $cancelled++;
        } else {
            $failed++;
        }
    }

    $is_last_chunk = isset($_POST['is_last_chunk']) ? ($_POST['is_last_chunk'] === 'true') : true;
    $final_status  = $is_last_chunk ? "'completed'" : "'processing'";

    mysqli_query($conn, "UPDATE loading_summary_imports 
                         SET imported_records = imported_records + $imported,
                             failed_records   = failed_records   + $failed,
                             status           = $final_status
                         WHERE id = $import_id");

    echo json_encode([
        'success'       => true,
        'import_id'     => $import_id,
        'total_records' => $total_records,
        'imported'      => $imported,
        'cancelled'     => $cancelled,
        'failed'        => $failed,
        'is_last_chunk' => $is_last_chunk,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
} catch (Error $e) {
    echo json_encode(['success' => false, 'message' => 'PHP Error: ' . $e->getMessage()]);
}
exit;
?>