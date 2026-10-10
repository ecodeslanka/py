<?php
// ══════════════════════════════════════════════════════════════════════════════
//  process_reimport_unloading.php
//  Re-import Excel data for an existing import_id.
//
//  Rules:
//   1. Log the OLD import_details rows → unloading_reimport_logs  (kept forever)
//   2. Delete the OLD unloading_summary_import_details for this import_id
//   3. Insert NEW rows into unloading_summary_import_details
//   4. Update unloading_data rows that already exist (adj_qty*, tur, mrp, vehicle)
//      — PRESERVE: actual_qty, actual_damage_qty, short_excess, charge_to_employee,
//                  absorb_by_company, pay_variance  (user-entered work)
//   5. Insert brand-new rows into unloading_data for SKUs/persons not yet there
//   6. Do NOT touch rows in unloading_data that no longer appear in the new Excel
//      (they stay in place so nothing is lost)
//   7. Update unloading_summary_imports header counts / delivery_date
// ══════════════════════════════════════════════════════════════════════════════

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

    if (!$conn) {
        die(json_encode(['success' => false, 'message' => 'Database connection failed']));
    }

    // ── Validate inputs ──────────────────────────────────────────────────────
    $import_id = intval($_POST['import_id'] ?? 0);
    if ($import_id <= 0) {
        die(json_encode(['success' => false, 'message' => 'Invalid import_id']));
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

    // Check the import record exists
    $imp_res = mysqli_query($conn, "SELECT * FROM unloading_summary_imports WHERE id = $import_id");
    if (!$imp_res || mysqli_num_rows($imp_res) === 0) {
        die(json_encode(['success' => false, 'message' => 'Import #' . $import_id . ' not found']));
    }
    $imp = mysqli_fetch_assoc($imp_res);

    $filename      = $_POST['filename']      ?? $imp['filename'];
    $delivery_date = $_POST['delivery_date'] ?? '';

    $delivery_date_val = 'NULL';
    if (!empty($delivery_date)) {
        $ts = strtotime($delivery_date);
        if ($ts !== false) $delivery_date_val = "'" . date('Y-m-d', $ts) . "'";
    } elseif (!empty($imp['delivery_date'])) {
        $delivery_date_val = "'" . $imp['delivery_date'] . "'";
    }

    // ── Ensure log table exists ───────────────────────────────────────────────
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS unloading_reimport_logs (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        import_id           INT NOT NULL,
        reimport_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        old_filename        VARCHAR(255) NULL,
        new_filename        VARCHAR(255) NULL,
        old_record_count    INT DEFAULT 0,
        new_record_count    INT DEFAULT 0,
        updated_rows        INT DEFAULT 0,
        added_rows          INT DEFAULT 0,
        skipped_rows        INT DEFAULT 0,
        note                TEXT NULL,
        INDEX idx_import_id (import_id)
    )");

    // Archived detail rows table
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS unloading_reimport_detail_logs (
        id                      INT AUTO_INCREMENT PRIMARY KEY,
        log_id                  INT NOT NULL,
        import_id               INT NOT NULL,
        original_detail_id      INT NULL,
        record_date             DATE NULL,
        delivery_person_code    VARCHAR(100) NULL,
        delivery_person_name    VARCHAR(255) NULL,
        vehicle                 VARCHAR(255) NULL,
        sku_code                VARCHAR(100) NULL,
        sku_desc                VARCHAR(500) NULL,
        tur                     DECIMAL(12,2) DEFAULT 0,
        mrp                     DECIMAL(12,2) DEFAULT 0,
        adj_qty_good_units      DECIMAL(12,2) DEFAULT 0,
        adj_qty_damage          DECIMAL(12,2) DEFAULT 0,
        delivery_date           DATE NULL,
        archived_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_log_id (log_id),
        INDEX idx_import_id (import_id)
    )");

    // ── Ensure items table ────────────────────────────────────────────────────
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS items (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        sku_code VARCHAR(100) NOT NULL UNIQUE,
        sku_desc VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_sku_code (sku_code)
    )");

    // ── Ensure unloading_data table ───────────────────────────────────────────
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS unloading_data (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        import_id           INT NOT NULL,
        import_detail_id    INT NULL,
        record_date         DATE NULL,
        delivery_person_code VARCHAR(100) NULL,
        delivery_person_name VARCHAR(255) NULL,
        vehicle             VARCHAR(255) NULL,
        sku_code            VARCHAR(100) NULL,
        sku_desc            VARCHAR(500) NULL,
        tur                 DECIMAL(12,2) DEFAULT 0,
        mrp                 DECIMAL(12,2) DEFAULT 0,
        adj_qty_good_units  DECIMAL(12,2) DEFAULT 0,
        adj_qty_damage      DECIMAL(12,2) DEFAULT 0,
        actual_qty          DECIMAL(12,2) DEFAULT NULL,
        actual_damage_qty   DECIMAL(12,2) DEFAULT NULL,
        short_excess        DECIMAL(12,2) DEFAULT NULL,
        charge_to_employee  DECIMAL(12,2) DEFAULT NULL,
        absorb_by_company   DECIMAL(12,2) DEFAULT NULL,
        pay_variance        DECIMAL(12,2) DEFAULT NULL,
        delivery_date       DATE NULL,
        status              VARCHAR(20) DEFAULT 'imported',
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_import    (import_id),
        INDEX idx_person    (delivery_person_name),
        INDEX idx_sku       (sku_code)
    )");

    // ════════════════════════════════════════════════════════════════════════════
    // STEP 1 — Count old detail rows and create the log header
    // ════════════════════════════════════════════════════════════════════════════
    $old_count_res = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) as c FROM unloading_summary_import_details WHERE import_id = $import_id"));
    $old_count = intval($old_count_res['c'] ?? 0);

    $fn_old  = mysqli_real_escape_string($conn, $imp['filename']);
    $fn_new  = mysqli_real_escape_string($conn, $filename);
    $new_count = count($data);

    $log_ins = "INSERT INTO unloading_reimport_logs
        (import_id, old_filename, new_filename, old_record_count, new_record_count, note)
        VALUES ($import_id, '$fn_old', '$fn_new', $old_count, $new_count, 'Re-import initiated')";
    mysqli_query($conn, $log_ins);
    $log_id = mysqli_insert_id($conn);

    // ════════════════════════════════════════════════════════════════════════════
    // STEP 2 — Archive old import_details into the log table
    // ════════════════════════════════════════════════════════════════════════════
    mysqli_query($conn, "INSERT INTO unloading_reimport_detail_logs
        (log_id, import_id, original_detail_id,
         record_date, delivery_person_code, delivery_person_name, vehicle,
         sku_code, sku_desc, tur, mrp, adj_qty_good_units, adj_qty_damage,
         delivery_date)
        SELECT $log_id, import_id, id,
               record_date, delivery_person_code, delivery_person_name, vehicle,
               sku_code, sku_desc, tur, mrp, adj_qty_good_units, adj_qty_damage,
               delivery_date
        FROM unloading_summary_import_details
        WHERE import_id = $import_id");

    // ════════════════════════════════════════════════════════════════════════════
    // STEP 3 — Delete old import details
    // ════════════════════════════════════════════════════════════════════════════
    mysqli_query($conn, "DELETE FROM unloading_summary_import_details WHERE import_id = $import_id");

    // ════════════════════════════════════════════════════════════════════════════
    // STEP 4 — Load existing SKUs and build existing unloading_data index
    //          Key: "delivery_person_code|sku_code"  → row id
    // ════════════════════════════════════════════════════════════════════════════
    $existing_skus = [];
    $sk_res = mysqli_query($conn, "SELECT sku_code FROM items");
    if ($sk_res) {
        while ($r = mysqli_fetch_assoc($sk_res)) $existing_skus[$r['sku_code']] = true;
    }

    // Build unloading_data lookup: (dp_code, sku_code) → ud.id
    $ud_index = [];
    $ud_res = mysqli_query($conn,
        "SELECT id, delivery_person_code, sku_code
         FROM unloading_data
         WHERE import_id = $import_id");
    if ($ud_res) {
        while ($r = mysqli_fetch_assoc($ud_res)) {
            $key = strtolower(trim($r['delivery_person_code'])) . '|' . strtolower(trim($r['sku_code']));
            $ud_index[$key] = intval($r['id']);
        }
    }

    // ════════════════════════════════════════════════════════════════════════════
    // STEP 5 — Process each new Excel row
    // ════════════════════════════════════════════════════════════════════════════
    $imported    = 0;
    $failed      = 0;
    $updated_ud  = 0;
    $added_ud    = 0;
    $skipped_ud  = 0;
    $new_items   = 0;
    $dd_safe     = $delivery_date_val;

    foreach ($data as $rec) {
        if (is_object($rec)) $rec = (array)$rec;

        // Extract fields
        $record_date_raw = trim($rec['record_date']             ?? '');
        $dp_code_raw     = trim($rec['delivery_person_code']    ?? '');
        $dp_name_raw     = trim($rec['delivery_person_name']    ?? '');
        $vehicle_raw     = trim($rec['vehicle']                 ?? '');
        $sku_code_raw    = trim($rec['sku_code']                ?? '');
        $sku_desc_raw    = trim($rec['sku_desc']                ?? '');
        $tur             = floatval($rec['tur']                 ?? 0);
        $mrp             = floatval($rec['mrp']                 ?? 0);
        $adj_good        = abs(floatval($rec['adj_qty_good_units'] ?? 0));
        $adj_damage      = abs(floatval($rec['adj_qty_damage']     ?? 0));

        // Skip rows with no identifying data
        if (empty($sku_code_raw) && empty($dp_code_raw) && empty($dp_name_raw)) {
            continue;
        }

        $dp_code  = mysqli_real_escape_string($conn, $dp_code_raw);
        $dp_name  = mysqli_real_escape_string($conn, $dp_name_raw);
        $vehicle  = mysqli_real_escape_string($conn, $vehicle_raw);
        $sku_code = mysqli_real_escape_string($conn, $sku_code_raw);
        $sku_desc = mysqli_real_escape_string($conn, $sku_desc_raw);

        // Parse record_date
        $record_date_val = 'NULL';
        if (!empty($record_date_raw)) {
            $ts = strtotime($record_date_raw);
            if ($ts !== false) $record_date_val = "'" . date('Y-m-d', $ts) . "'";
        }

        // ── Insert into import_details (fresh) ──
        $ins_detail = "INSERT INTO unloading_summary_import_details
            (import_id, record_date, delivery_person_code, delivery_person_name, vehicle,
             sku_code, sku_desc, tur, mrp,
             adj_qty_good_units, adj_qty_damage,
             delivery_date, status)
            VALUES
            ($import_id, $record_date_val, '$dp_code', '$dp_name', '$vehicle',
             '$sku_code', '$sku_desc', $tur, $mrp,
             $adj_good, $adj_damage,
             $dd_safe, 'imported')";

        $new_detail_id = 0;
        if (mysqli_query($conn, $ins_detail)) {
            $new_detail_id = mysqli_insert_id($conn);
            $imported++;
        } else {
            $failed++;
            continue; // skip unloading_data sync if detail insert failed
        }

        // ── Update items master ──
        if (!empty($sku_code_raw) && !isset($existing_skus[$sku_code_raw])) {
            $ins_item = "INSERT IGNORE INTO items (sku_code, sku_desc) VALUES ('$sku_code', '$sku_desc')";
            if (mysqli_query($conn, $ins_item) && mysqli_affected_rows($conn) > 0) {
                $new_items++;
                $existing_skus[$sku_code_raw] = true;
            }
        }

        // ── Sync unloading_data ──
        // Only sync rows that have non-zero quantities (mirrors the init logic)
        if ($adj_good == 0 && $adj_damage == 0) {
            $skipped_ud++;
            continue;
        }

        $key = strtolower($dp_code_raw) . '|' . strtolower($sku_code_raw);

        if (isset($ud_index[$key])) {
            // ── Row exists → update base qty fields only (preserve user edits) ──
            $ud_id = $ud_index[$key];
            $upd = "UPDATE unloading_data SET
                        adj_qty_good_units = $adj_good,
                        adj_qty_damage     = $adj_damage,
                        tur                = $tur,
                        mrp                = $mrp,
                        vehicle            = '$vehicle',
                        import_detail_id   = $new_detail_id,
                        delivery_date      = $dd_safe,
                        record_date        = $record_date_val
                    WHERE id = $ud_id AND import_id = $import_id";
            if (mysqli_query($conn, $upd)) $updated_ud++;
        } else {
            // ── New row → insert into unloading_data ──
            $ins_ud = "INSERT INTO unloading_data
                (import_id, import_detail_id, record_date,
                 delivery_person_code, delivery_person_name, vehicle,
                 sku_code, sku_desc, tur, mrp,
                 adj_qty_good_units, adj_qty_damage,
                 delivery_date, status)
                VALUES
                ($import_id, $new_detail_id, $record_date_val,
                 '$dp_code', '$dp_name', '$vehicle',
                 '$sku_code', '$sku_desc', $tur, $mrp,
                 $adj_good, $adj_damage,
                 $dd_safe, 'imported')";
            if (mysqli_query($conn, $ins_ud)) {
                $added_ud++;
                // Add to index so duplicates within same file don't re-insert
                $ud_index[$key] = mysqli_insert_id($conn);
            }
        }
    }

    // ════════════════════════════════════════════════════════════════════════════
    // STEP 6 — Update import header record
    // ════════════════════════════════════════════════════════════════════════════
    $actual_total = $imported + $failed;
    $fn_new_esc   = mysqli_real_escape_string($conn, $filename);
    mysqli_query($conn, "UPDATE unloading_summary_imports
                         SET filename         = '$fn_new_esc',
                             total_records    = $actual_total,
                             imported_records = $imported,
                             failed_records   = $failed,
                             delivery_date    = $dd_safe,
                             status           = 'completed'
                         WHERE id = $import_id");

    // ════════════════════════════════════════════════════════════════════════════
    // STEP 7 — Update log with final counts
    // ════════════════════════════════════════════════════════════════════════════
    mysqli_query($conn, "UPDATE unloading_reimport_logs
                         SET new_record_count = $imported,
                             updated_rows     = $updated_ud,
                             added_rows       = $added_ud,
                             skipped_rows     = $skipped_ud,
                             note = CONCAT(note, ' | Completed: $imported imported, $updated_ud ud-updated, $added_ud ud-added, $skipped_ud ud-skipped, $failed failed')
                         WHERE id = $log_id");

    echo json_encode([
        'success'          => true,
        'import_id'        => $import_id,
        'log_id'           => $log_id,
        'total_records'    => $new_count,
        'imported'         => $imported,
        'failed'           => $failed,
        'updated_ud'       => $updated_ud,    // existing unloading_data rows updated
        'added_ud'         => $added_ud,      // new rows added to unloading_data
        'skipped_ud'       => $skipped_ud,    // rows skipped (both qty=0)
        'new_items'        => $new_items,
        'old_archived'     => $old_count,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
} catch (Error $e) {
    echo json_encode(['success' => false, 'message' => 'PHP Error: ' . $e->getMessage()]);
}
exit;
?>