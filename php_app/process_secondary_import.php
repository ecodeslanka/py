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
        die(json_encode(['success' => false, 'message' => 'POST data empty – post_max_size exceeded']));
    }
    if (!$conn) {
        die(json_encode(['success' => false, 'message' => 'Database connection failed']));
    }

    // ── Ensure core tables exist ─────────────────────────────────────────────
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS secondary_invoice_imports (
        id              INT(11)      AUTO_INCREMENT PRIMARY KEY,
        delivery_date   DATE         NOT NULL,
        filename        VARCHAR(255) NOT NULL,
        total_records   INT(11)      DEFAULT 0,
        imported_records INT(11)     DEFAULT 0,
        failed_records  INT(11)      DEFAULT 0,
        status          ENUM('pending','processing','completed','failed') DEFAULT 'pending',
        imported_by     INT(11)      NULL,
        imported_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_delivery_date (delivery_date),
        INDEX idx_status (status)
    )");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS secondary_invoice_import_details (
        id                           INT(11)        AUTO_INCREMENT PRIMARY KEY,
        import_id                    INT(11)        NOT NULL,
        sales_person_code            VARCHAR(100)   NULL,
        t_code                       VARCHAR(50)    NULL,
        route_code                   VARCHAR(50)    NULL,
        bill_no                      VARCHAR(100)   NULL,
        bill_date                    DATE           NULL,
        outlet_code                  VARCHAR(100)   NULL,
        party_name                   VARCHAR(255)   NULL,
        free_qty                     DECIMAL(12,2)  DEFAULT 0,
        gross_sales                  DECIMAL(12,2)  DEFAULT 0,
        scheme_disc                  DECIMAL(12,2)  DEFAULT 0,
        rs_discount                  DECIMAL(12,2)  DEFAULT 0,
        tot_disc                     DECIMAL(12,2)  DEFAULT 0,
        total_discount               DECIMAL(12,2)  DEFAULT 0,
        bill_value                   DECIMAL(12,2)  DEFAULT 0,
        good_returns_value           DECIMAL(12,2)  DEFAULT 0,
        damage_expiry_shortage_value DECIMAL(12,2)  DEFAULT 0,
        final_bill_amount            DECIMAL(12,2)  DEFAULT 0,
        delivery_person              VARCHAR(255)   NULL,
        delivery_date                DATE           NULL,
        t_code_valid                 TINYINT(1)     DEFAULT 0,
        route_valid                  TINYINT(1)     DEFAULT 0,
        customer_name                VARCHAR(255)   NULL,
        route_name                   VARCHAR(255)   NULL,
        status                       ENUM('pending','imported','failed') DEFAULT 'pending',
        error_message                TEXT           NULL,
        created_at                   TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_import_id (import_id),
        INDEX idx_status    (status)
    )");

    // ── Migrate: add bill_value column if it doesn't exist ───────────────────
    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM secondary_invoice_import_details LIKE 'bill_value'");
    if (!$col_check || mysqli_num_rows($col_check) === 0) {
        mysqli_query($conn, "ALTER TABLE secondary_invoice_import_details
                             ADD COLUMN bill_value DECIMAL(12,2) DEFAULT 0
                             AFTER total_discount");
    }

    // ── History / archive tables ─────────────────────────────────────────────
    // Stores the header row of each superseded import
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS secondary_invoice_import_history (
        id                INT(11)      AUTO_INCREMENT PRIMARY KEY,
        original_import_id INT(11)     NOT NULL,          -- ID in secondary_invoice_imports (now deleted)
        delivery_date     DATE         NOT NULL,
        filename          VARCHAR(255) NOT NULL,
        total_records     INT(11)      DEFAULT 0,
        imported_records  INT(11)      DEFAULT 0,
        failed_records    INT(11)      DEFAULT 0,
        replaced_by_import_id INT(11) NULL,               -- ID of the new import that replaced this one
        archived_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_delivery_date       (delivery_date),
        INDEX idx_original_import_id  (original_import_id)
    )");

    // Stores every detail row of each superseded import
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS secondary_invoice_import_history_details (
        id                           INT(11)        AUTO_INCREMENT PRIMARY KEY,
        history_id                   INT(11)        NOT NULL,   -- FK → secondary_invoice_import_history.id
        original_import_id           INT(11)        NOT NULL,
        delivery_date                DATE           NULL,
        sales_person_code            VARCHAR(100)   NULL,
        t_code                       VARCHAR(50)    NULL,
        route_code                   VARCHAR(50)    NULL,
        bill_no                      VARCHAR(100)   NULL,
        bill_date                    DATE           NULL,
        outlet_code                  VARCHAR(100)   NULL,
        party_name                   VARCHAR(255)   NULL,
        free_qty                     DECIMAL(12,2)  DEFAULT 0,
        gross_sales                  DECIMAL(12,2)  DEFAULT 0,
        scheme_disc                  DECIMAL(12,2)  DEFAULT 0,
        rs_discount                  DECIMAL(12,2)  DEFAULT 0,
        tot_disc                     DECIMAL(12,2)  DEFAULT 0,
        total_discount               DECIMAL(12,2)  DEFAULT 0,
        bill_value                   DECIMAL(12,2)  DEFAULT 0,
        good_returns_value           DECIMAL(12,2)  DEFAULT 0,
        damage_expiry_shortage_value DECIMAL(12,2)  DEFAULT 0,
        final_bill_amount            DECIMAL(12,2)  DEFAULT 0,
        delivery_person              VARCHAR(255)   NULL,
        customer_name                VARCHAR(255)   NULL,
        route_name                   VARCHAR(255)   NULL,
        status                       VARCHAR(20)    NULL,
        archived_at                  TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_history_id         (history_id),
        INDEX idx_original_import_id (original_import_id),
        INDEX idx_delivery_date      (delivery_date)
    )");

    // ── Migrate: add bill_value to history_details if missing ────────────────
    $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM secondary_invoice_import_history_details LIKE 'bill_value'");
    if (!$col_check2 || mysqli_num_rows($col_check2) === 0) {
        mysqli_query($conn, "ALTER TABLE secondary_invoice_import_history_details
                             ADD COLUMN bill_value DECIMAL(12,2) DEFAULT 0
                             AFTER total_discount");
    }

    // ── Parse POST ───────────────────────────────────────────────────────────
    $raw = $_POST['data'] ?? '';
    if (empty($raw)) die(json_encode(['success' => false, 'message' => 'No data field in POST']));

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        die(json_encode(['success' => false, 'message' => 'JSON error: ' . json_last_error_msg()]));
    }
    if (!is_array($data) || count($data) === 0) {
        die(json_encode(['success' => false, 'message' => 'Empty data array']));
    }

    $delivery_date = $_POST['delivery_date'] ?? '';
    $filename      = $_POST['filename']      ?? 'unknown.xlsx';

    if (empty($delivery_date)) die(json_encode(['success' => false, 'message' => 'Missing delivery date']));

    // Keep only valid records
    $valid_data = array_values(array_filter($data, function($r) {
        if (is_object($r)) $r = (array)$r;
        return !empty($r['t_code_valid']) && !empty($r['route_valid']);
    }));

    $total_records = count($valid_data);
    if ($total_records === 0) die(json_encode(['success' => false, 'message' => 'No valid records to import']));

    $dd = mysqli_real_escape_string($conn, $delivery_date);
    $fn = mysqli_real_escape_string($conn, $filename);

    mysqli_begin_transaction($conn);

    // ── Step 1: Check for existing completed import(s) for same delivery date ─
    $archived_count = 0;
    $existing_res = mysqli_query($conn,
        "SELECT * FROM secondary_invoice_imports
         WHERE  delivery_date = '$dd' AND status = 'completed'
         ORDER BY id ASC");

    $existing_imports = [];
    if ($existing_res) {
        while ($erow = mysqli_fetch_assoc($existing_res)) {
            $existing_imports[] = $erow;
        }
    }

    foreach ($existing_imports as $old_import) {
        $old_id = intval($old_import['id']);

        // ── Step 2a: Archive the import header row ───────────────────────────
        $arch_ins = mysqli_query($conn,
            "INSERT INTO secondary_invoice_import_history
                (original_import_id, delivery_date, filename,
                 total_records, imported_records, failed_records,
                 replaced_by_import_id)
             VALUES
                ($old_id,
                 '" . mysqli_real_escape_string($conn, $old_import['delivery_date']) . "',
                 '" . mysqli_real_escape_string($conn, $old_import['filename'])       . "',
                 " . intval($old_import['total_records'])    . ",
                 " . intval($old_import['imported_records']) . ",
                 " . intval($old_import['failed_records'])   . ",
                 NULL)");   /* replaced_by_import_id filled in after new import created */

        if (!$arch_ins) {
            mysqli_rollback($conn);
            die(json_encode(['success' => false, 'message' => 'Failed to archive import header: ' . mysqli_error($conn)]));
        }
        $history_id = mysqli_insert_id($conn);

        // ── Step 2b: Archive every detail row ───────────────────────────────
        $det_res = mysqli_query($conn,
            "SELECT * FROM secondary_invoice_import_details WHERE import_id = $old_id");
        if ($det_res) {
            while ($det = mysqli_fetch_assoc($det_res)) {
                $bill_date_arch = !empty($det['bill_date']) ? "'" . mysqli_real_escape_string($conn, $det['bill_date']) . "'" : 'NULL';
                $del_date_arch  = !empty($det['delivery_date']) ? "'" . mysqli_real_escape_string($conn, $det['delivery_date']) . "'" : 'NULL';

                mysqli_query($conn,
                    "INSERT INTO secondary_invoice_import_history_details
                        (history_id, original_import_id, delivery_date,
                         sales_person_code, t_code, route_code, bill_no,
                         bill_date, outlet_code, party_name,
                         free_qty, gross_sales, scheme_disc, rs_discount,
                         tot_disc, total_discount, bill_value,
                         good_returns_value, damage_expiry_shortage_value, final_bill_amount,
                         delivery_person, customer_name, route_name, status)
                     VALUES
                        ($history_id, $old_id, $del_date_arch,
                         '" . mysqli_real_escape_string($conn, $det['sales_person_code'] ?? '') . "',
                         '" . mysqli_real_escape_string($conn, $det['t_code']            ?? '') . "',
                         '" . mysqli_real_escape_string($conn, $det['route_code']        ?? '') . "',
                         '" . mysqli_real_escape_string($conn, $det['bill_no']           ?? '') . "',
                         $bill_date_arch,
                         '" . mysqli_real_escape_string($conn, $det['outlet_code']       ?? '') . "',
                         '" . mysqli_real_escape_string($conn, $det['party_name']        ?? '') . "',
                         " . floatval($det['free_qty']                     ?? 0) . ",
                         " . floatval($det['gross_sales']                  ?? 0) . ",
                         " . floatval($det['scheme_disc']                  ?? 0) . ",
                         " . floatval($det['rs_discount']                  ?? 0) . ",
                         " . floatval($det['tot_disc']                     ?? 0) . ",
                         " . floatval($det['total_discount']               ?? 0) . ",
                         " . floatval($det['bill_value']                   ?? 0) . ",
                         " . floatval($det['good_returns_value']           ?? 0) . ",
                         " . floatval($det['damage_expiry_shortage_value'] ?? 0) . ",
                         " . floatval($det['final_bill_amount']            ?? 0) . ",
                         '" . mysqli_real_escape_string($conn, $det['delivery_person'] ?? '') . "',
                         '" . mysqli_real_escape_string($conn, $det['customer_name']   ?? '') . "',
                         '" . mysqli_real_escape_string($conn, $det['route_name']      ?? '') . "',
                         '" . mysqli_real_escape_string($conn, $det['status']          ?? '') . "')");

                $archived_count++;
            }
        }

        // ── Step 2c: Delete detail rows, then the import header ──────────────
        mysqli_query($conn, "DELETE FROM secondary_invoice_import_details WHERE import_id = $old_id");
        mysqli_query($conn, "DELETE FROM secondary_invoice_imports WHERE id = $old_id");
    }

    // ── Step 3: Create new import header ────────────────────────────────────
    $new_ins = mysqli_query($conn,
        "INSERT INTO secondary_invoice_imports
            (delivery_date, filename, total_records, imported_records, failed_records, status)
         VALUES
            ('$dd', '$fn', $total_records, 0, 0, 'processing')");

    if (!$new_ins) {
        mysqli_rollback($conn);
        die(json_encode(['success' => false, 'message' => 'DB error creating import: ' . mysqli_error($conn)]));
    }
    $import_id = mysqli_insert_id($conn);

    // Update replaced_by_import_id in all history rows for this delivery date
    if (!empty($existing_imports)) {
        mysqli_query($conn,
            "UPDATE secondary_invoice_import_history
             SET    replaced_by_import_id = $import_id
             WHERE  delivery_date = '$dd'
               AND  replaced_by_import_id IS NULL");
    }

    // ── Step 4: Insert new detail rows ───────────────────────────────────────
    $imported = 0;
    $failed   = 0;

    foreach ($valid_data as $rec) {
        if (is_object($rec)) $rec = (array)$rec;

        $sales_code      = mysqli_real_escape_string($conn, $rec['sales_person_code']           ?? '');
        $t_code          = mysqli_real_escape_string($conn, $rec['t_code']                      ?? '');
        $route_code      = mysqli_real_escape_string($conn, $rec['route_code']                  ?? '');
        $bill_no         = mysqli_real_escape_string($conn, $rec['bill_no']                     ?? '');
        $bill_date       = mysqli_real_escape_string($conn, $rec['bill_date']                   ?? '');
        $outlet_code     = mysqli_real_escape_string($conn, $rec['outlet_code']                 ?? '');
        $party_name      = mysqli_real_escape_string($conn, $rec['party_name']                  ?? '');
        $free_qty        = floatval($rec['free_qty']                                            ?? 0);
        $gross_sales     = floatval($rec['gross_sales']                                         ?? 0);
        $scheme_disc     = floatval($rec['scheme_disc']                                         ?? 0);
        $rs_discount     = floatval($rec['rs_discount']                                         ?? 0);
        $tot_disc        = floatval($rec['tot_disc']                                            ?? 0);
        $total_discount  = floatval($rec['total_discount']                                      ?? 0);
        $bill_value      = floatval($rec['bill_value']                                          ?? 0);
        $good_returns    = floatval($rec['good_returns_value']                                  ?? 0);
        $dmg_expiry      = floatval($rec['damage_expiry_shortage_value']                        ?? 0);
        $amount          = floatval($rec['final_bill_amount']                                   ?? 0);
        $delivery_person = mysqli_real_escape_string($conn, $rec['delivery_person']             ?? '');
        $cust_name       = mysqli_real_escape_string($conn, $rec['customer_name']               ?? '');
        $route_name      = mysqli_real_escape_string($conn, $rec['route_name']                  ?? '');
        $bill_date_val   = !empty($bill_date) ? "'$bill_date'" : 'NULL';

        $ins = mysqli_query($conn,
            "INSERT INTO secondary_invoice_import_details
                (import_id,
                 sales_person_code, t_code, route_code, bill_no, bill_date,
                 outlet_code, party_name,
                 free_qty, gross_sales, scheme_disc, rs_discount,
                 tot_disc, total_discount, bill_value,
                 good_returns_value, damage_expiry_shortage_value, final_bill_amount,
                 delivery_person,
                 delivery_date, t_code_valid, route_valid,
                 customer_name, route_name, status)
             VALUES
                ($import_id,
                 '$sales_code', '$t_code', '$route_code', '$bill_no', $bill_date_val,
                 '$outlet_code', '$party_name',
                 $free_qty, $gross_sales, $scheme_disc, $rs_discount,
                 $tot_disc, $total_discount, $bill_value,
                 $good_returns, $dmg_expiry, $amount,
                 '$delivery_person',
                 '$dd', 1, 1,
                 '$cust_name', '$route_name', 'imported')");

        if ($ins) { $imported++; } else { $failed++; }
    }

    // ── Step 5: Finalise import header ───────────────────────────────────────
    mysqli_query($conn,
        "UPDATE secondary_invoice_imports
         SET    imported_records = $imported,
                failed_records   = $failed,
                status           = 'completed'
         WHERE  id               = $import_id");

    mysqli_commit($conn);

    echo json_encode([
        'success'         => true,
        'import_id'       => $import_id,
        'total_records'   => $total_records,
        'imported'        => $imported,
        'failed'          => $failed,
        'replaced_count'  => count($existing_imports),
        'archived_rows'   => $archived_count,
    ]);

} catch (Exception $e) {
    if (isset($conn)) mysqli_rollback($conn);
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
} catch (Error $e) {
    if (isset($conn)) mysqli_rollback($conn);
    echo json_encode(['success' => false, 'message' => 'PHP Error: ' . $e->getMessage()]);
}
exit;
?>