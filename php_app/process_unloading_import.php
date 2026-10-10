<?php
// CRITICAL: Suppress ALL PHP error output
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
        die(json_encode(['success' => false, 'message' => 'POST data empty — post_max_size exceeded']));
    }

    if (!$conn) {
        die(json_encode(['success' => false, 'message' => 'Database connection failed']));
    }

    // ── Create / ensure all required tables ───────────────────────────────────

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS unloading_summary_imports (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        total_records INT(11) DEFAULT 0,
        imported_records INT(11) DEFAULT 0,
        failed_records INT(11) DEFAULT 0,
        status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
        delivery_date DATE NULL,
        imported_by INT(11) NULL,
        imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_delivery_date (delivery_date)
    )");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS unloading_summary_import_details (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        import_id INT(11) NOT NULL,
        delivery_date DATE NULL,

        -- Identification (Col 0-6)
        sr_no                       VARCHAR(50)      NULL,
        record_date                 DATE             NULL,
        delivery_person_code        VARCHAR(100)     NULL,
        delivery_person_name        VARCHAR(255)     NULL,
        vehicle                     VARCHAR(255)     NULL,
        sku_code                    VARCHAR(100)     NULL,
        sku_desc                    VARCHAR(500)     NULL,

        -- Pricing (Col 7-9)
        tur                         DECIMAL(12,2)    DEFAULT 0,
        mrp                         DECIMAL(12,2)    DEFAULT 0,
        upc                         DECIMAL(12,2)    DEFAULT 0,

        -- Physical Qty (Col 10-11)
        physical_qty_good           DECIMAL(12,2)    DEFAULT 0,
        physical_qty_damage         DECIMAL(12,2)    DEFAULT 0,

        -- Bill Counts (Col 12-15)
        bill_count                  INT(11)          DEFAULT 0,
        posted_bill_count           INT(11)          DEFAULT 0,
        return_ref_count            INT(11)          DEFAULT 0,
        confirmed_return_ref_count  INT(11)          DEFAULT 0,

        -- Billed Qty Before Del (Col 16-18)
        billed_qty_before_del_cases DECIMAL(12,2)    DEFAULT 0,
        billed_qty_before_del_units DECIMAL(12,2)    DEFAULT 0,
        billed_qty_before_del_value DECIMAL(12,2)    DEFAULT 0,

        -- Final Bill Modified Qty (Col 19-21)
        final_bill_mod_cases        DECIMAL(12,2)    DEFAULT 0,
        final_bill_mod_units        DECIMAL(12,2)    DEFAULT 0,
        final_bill_mod_value        DECIMAL(12,2)    DEFAULT 0,

        -- Good Return Entry (Col 22-23)
        good_return_entry_cases     DECIMAL(12,2)    DEFAULT 0,
        good_return_entry_units     DECIMAL(12,2)    DEFAULT 0,

        -- Good Return Confirmed (Col 24-25)
        good_return_confirmed_cases DECIMAL(12,2)    DEFAULT 0,
        good_return_confirmed_units DECIMAL(12,2)    DEFAULT 0,

        -- Damage Return Entry (Col 26-27)
        damage_return_entry_cases   DECIMAL(12,2)    DEFAULT 0,
        damage_return_entry_units   DECIMAL(12,2)    DEFAULT 0,

        -- Damage Return Confirmed (Col 28-29)
        damage_return_confirmed_cases DECIMAL(12,2)  DEFAULT 0,
        damage_return_confirmed_units DECIMAL(12,2)  DEFAULT 0,

        -- Adjustment Qty Good (Col 30-32)
        adj_qty_good_cases          DECIMAL(12,2)    DEFAULT 0,
        adj_qty_good_units          DECIMAL(12,2)    DEFAULT 0,
        adj_qty_good_values         DECIMAL(12,2)    DEFAULT 0,

        -- Adjustment Qty Damage (Col 33)
        adj_qty_damage              DECIMAL(12,2)    DEFAULT 0,

        -- Difference (Col 34-35)
        difference_units            DECIMAL(12,2)    DEFAULT 0,
        difference_value            DECIMAL(12,2)    DEFAULT 0,

        -- Net Amount (Col 36)
        net_amount                  DECIMAL(12,2)    DEFAULT 0,

        status        ENUM('pending','imported','failed') DEFAULT 'imported',
        error_message TEXT          NULL,
        created_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,

        INDEX idx_import_id          (import_id),
        INDEX idx_status             (status),
        INDEX idx_delivery_person    (delivery_person_code),
        INDEX idx_sku_code           (sku_code),
        INDEX idx_delivery_date      (delivery_date)
    )");

    // Items master table
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS items (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        sku_code VARCHAR(100) NOT NULL UNIQUE,
        sku_desc VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_sku_code (sku_code)
    )");

    // ── Migrate: add any missing columns to an existing details table ─────────
    $migration_cols = [
        'sr_no'                        => "VARCHAR(50) NULL AFTER import_id",
        'upc'                          => "DECIMAL(12,2) DEFAULT 0 AFTER mrp",
        'physical_qty_good'            => "DECIMAL(12,2) DEFAULT 0 AFTER upc",
        'physical_qty_damage'          => "DECIMAL(12,2) DEFAULT 0 AFTER physical_qty_good",
        'bill_count'                   => "INT(11) DEFAULT 0 AFTER physical_qty_damage",
        'posted_bill_count'            => "INT(11) DEFAULT 0 AFTER bill_count",
        'return_ref_count'             => "INT(11) DEFAULT 0 AFTER posted_bill_count",
        'confirmed_return_ref_count'   => "INT(11) DEFAULT 0 AFTER return_ref_count",
        'billed_qty_before_del_cases'  => "DECIMAL(12,2) DEFAULT 0 AFTER confirmed_return_ref_count",
        'billed_qty_before_del_units'  => "DECIMAL(12,2) DEFAULT 0 AFTER billed_qty_before_del_cases",
        'billed_qty_before_del_value'  => "DECIMAL(12,2) DEFAULT 0 AFTER billed_qty_before_del_units",
        'good_return_entry_cases'      => "DECIMAL(12,2) DEFAULT 0 AFTER final_bill_mod_value",
        'good_return_entry_units'      => "DECIMAL(12,2) DEFAULT 0 AFTER good_return_entry_cases",
        'good_return_confirmed_cases'  => "DECIMAL(12,2) DEFAULT 0 AFTER good_return_entry_units",
        'good_return_confirmed_units'  => "DECIMAL(12,2) DEFAULT 0 AFTER good_return_confirmed_cases",
        'damage_return_entry_cases'    => "DECIMAL(12,2) DEFAULT 0 AFTER good_return_confirmed_units",
        'damage_return_entry_units'    => "DECIMAL(12,2) DEFAULT 0 AFTER damage_return_entry_cases",
        'damage_return_confirmed_cases'=> "DECIMAL(12,2) DEFAULT 0 AFTER damage_return_entry_units",
        'damage_return_confirmed_units'=> "DECIMAL(12,2) DEFAULT 0 AFTER damage_return_confirmed_cases",
        'adj_qty_good_cases'           => "DECIMAL(12,2) DEFAULT 0 AFTER damage_return_confirmed_units",
        'adj_qty_good_values'          => "DECIMAL(12,2) DEFAULT 0 AFTER adj_qty_good_units",
        'difference_units'             => "DECIMAL(12,2) DEFAULT 0 AFTER adj_qty_damage",
        'difference_value'             => "DECIMAL(12,2) DEFAULT 0 AFTER difference_units",
        'net_amount'                   => "DECIMAL(12,2) DEFAULT 0 AFTER difference_value",
    ];
    foreach ($migration_cols as $col => $def) {
        $chk = mysqli_query($conn, "SHOW COLUMNS FROM unloading_summary_import_details LIKE '$col'");
        if (!$chk || mysqli_num_rows($chk) === 0) {
            mysqli_query($conn, "ALTER TABLE unloading_summary_import_details ADD COLUMN $col $def");
        }
    }

    // ── Parse input ───────────────────────────────────────────────────────────
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

    $filename      = isset($_POST['filename'])      ? $_POST['filename']      : 'unknown.xlsx';
    $delivery_date = isset($_POST['delivery_date']) ? $_POST['delivery_date'] : '';

    $delivery_date_val = 'NULL';
    if (!empty($delivery_date)) {
        $ts = strtotime($delivery_date);
        if ($ts !== false) $delivery_date_val = "'" . date('Y-m-d', $ts) . "'";
    }

    // ── UPSERT LOGIC: delete existing records for this delivery_date ──────────
    $overwritten = 0;
    if ($delivery_date_val !== 'NULL') {
        // Find existing imports for this delivery_date
        $existing_res = mysqli_query($conn,
            "SELECT id FROM unloading_summary_imports WHERE delivery_date = " . $delivery_date_val);
        if ($existing_res && mysqli_num_rows($existing_res) > 0) {
            while ($ex_row = mysqli_fetch_assoc($existing_res)) {
                $ex_id = intval($ex_row['id']);
                mysqli_query($conn,
                    "DELETE FROM unloading_summary_import_details WHERE import_id = $ex_id");
                $overwritten++;
            }
            mysqli_query($conn,
                "DELETE FROM unloading_summary_imports WHERE delivery_date = " . $delivery_date_val);
        }
    }

    // ── Load existing SKUs ────────────────────────────────────────────────────
    $existing_skus = [];
    $sku_res = mysqli_query($conn, "SELECT sku_code FROM items");
    if ($sku_res) {
        while ($row = mysqli_fetch_assoc($sku_res)) {
            $existing_skus[$row['sku_code']] = true;
        }
    }

    // ── Create import header ──────────────────────────────────────────────────
    $fn      = mysqli_real_escape_string($conn, $filename);
    $dd_safe = $delivery_date_val;
    $total_records = count($data);

    $q = "INSERT INTO unloading_summary_imports
          (filename, delivery_date, total_records, imported_records, failed_records, status)
          VALUES ('$fn', $dd_safe, $total_records, 0, 0, 'processing')";
    if (!mysqli_query($conn, $q)) {
        die(json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)]));
    }
    $import_id = mysqli_insert_id($conn);
    $imported  = 0;
    $failed    = 0;
    $new_items = 0;

    // ── Helper ────────────────────────────────────────────────────────────────
    function safe_date($val, $conn_unused) {
        if (empty($val)) return 'NULL';
        $ts = strtotime($val);
        return ($ts !== false) ? "'" . date('Y-m-d', $ts) . "'" : 'NULL';
    }

    function fv($v) { return floatval($v ?? 0); }
    function iv($v) { return intval($v ?? 0); }
    function sv($conn, $v) { return mysqli_real_escape_string($conn, trim($v ?? '')); }

    // ── Process each record ───────────────────────────────────────────────────
    foreach ($data as $rec) {
        if (is_object($rec)) $rec = (array)$rec;

        $sku_code_raw = trim($rec['sku_code'] ?? '');
        $dp_code      = trim($rec['delivery_person_code'] ?? '');
        $dp_name      = trim($rec['delivery_person_name'] ?? '');

        // Skip rows with zero identifying data
        if (empty($sku_code_raw) && empty($dp_code) && empty($dp_name)) continue;

        // Save new SKU to items table
        if (!empty($sku_code_raw) && !isset($existing_skus[$sku_code_raw])) {
            $sce = sv($conn, $sku_code_raw);
            $sde = sv($conn, $rec['sku_desc'] ?? '');
            if (mysqli_query($conn,
                "INSERT IGNORE INTO items (sku_code, sku_desc) VALUES ('$sce', '$sde')")
                && mysqli_affected_rows($conn) > 0) {
                $new_items++;
                $existing_skus[$sku_code_raw] = true;
            }
        }

        // Build INSERT
        $ins = "INSERT INTO unloading_summary_import_details (
            import_id, delivery_date,
            sr_no, record_date, delivery_person_code, delivery_person_name, vehicle,
            sku_code, sku_desc,
            tur, mrp, upc,
            physical_qty_good, physical_qty_damage,
            bill_count, posted_bill_count, return_ref_count, confirmed_return_ref_count,
            billed_qty_before_del_cases, billed_qty_before_del_units, billed_qty_before_del_value,
            final_bill_mod_cases, final_bill_mod_units, final_bill_mod_value,
            good_return_entry_cases, good_return_entry_units,
            good_return_confirmed_cases, good_return_confirmed_units,
            damage_return_entry_cases, damage_return_entry_units,
            damage_return_confirmed_cases, damage_return_confirmed_units,
            adj_qty_good_cases, adj_qty_good_units, adj_qty_good_values,
            adj_qty_damage,
            difference_units, difference_value,
            net_amount,
            status
        ) VALUES (
            $import_id, $dd_safe,
            '" . sv($conn, $rec['sr_no'] ?? '') . "',
            " . safe_date($rec['record_date'] ?? '', $conn) . ",
            '" . sv($conn, $dp_code) . "',
            '" . sv($conn, $dp_name) . "',
            '" . sv($conn, $rec['vehicle'] ?? '') . "',
            '" . sv($conn, $sku_code_raw) . "',
            '" . sv($conn, $rec['sku_desc'] ?? '') . "',
            " . fv($rec['tur']) . ",
            " . fv($rec['mrp']) . ",
            " . fv($rec['upc']) . ",
            " . fv($rec['physical_qty_good']) . ",
            " . fv($rec['physical_qty_damage']) . ",
            " . iv($rec['bill_count']) . ",
            " . iv($rec['posted_bill_count']) . ",
            " . iv($rec['return_ref_count']) . ",
            " . iv($rec['confirmed_return_ref_count']) . ",
            " . fv($rec['billed_qty_before_del_cases']) . ",
            " . fv($rec['billed_qty_before_del_units']) . ",
            " . fv($rec['billed_qty_before_del_value']) . ",
            " . fv($rec['final_bill_mod_cases']) . ",
            " . fv($rec['final_bill_mod_units']) . ",
            " . fv($rec['final_bill_mod_value']) . ",
            " . fv($rec['good_return_entry_cases']) . ",
            " . fv($rec['good_return_entry_units']) . ",
            " . fv($rec['good_return_confirmed_cases']) . ",
            " . fv($rec['good_return_confirmed_units']) . ",
            " . fv($rec['damage_return_entry_cases']) . ",
            " . fv($rec['damage_return_entry_units']) . ",
            " . fv($rec['damage_return_confirmed_cases']) . ",
            " . fv($rec['damage_return_confirmed_units']) . ",
            " . fv($rec['adj_qty_good_cases']) . ",
            " . fv($rec['adj_qty_good_units']) . ",
            " . fv($rec['adj_qty_good_values']) . ",
            " . fv($rec['adj_qty_damage']) . ",
            " . fv($rec['difference_units']) . ",
            " . fv($rec['difference_value']) . ",
            " . fv($rec['net_amount']) . ",
            'imported'
        )";

        if (mysqli_query($conn, $ins)) {
            $imported++;
        } else {
            $failed++;
        }
    }

    // ── Finalise import header ────────────────────────────────────────────────
    mysqli_query($conn, "UPDATE unloading_summary_imports
                         SET total_records    = " . ($imported + $failed) . ",
                             imported_records = $imported,
                             failed_records   = $failed,
                             status           = 'completed'
                         WHERE id = $import_id");

    echo json_encode([
        'success'       => true,
        'import_id'     => $import_id,
        'total_records' => $total_records,
        'imported'      => $imported,
        'failed'        => $failed,
        'new_items'     => $new_items,
        'overwritten'   => $overwritten,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
} catch (Error $e) {
    echo json_encode(['success' => false, 'message' => 'PHP Error: ' . $e->getMessage()]);
}
exit;
?>