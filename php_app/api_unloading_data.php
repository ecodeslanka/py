<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
include 'config.php';
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

try {
    if (!$conn) die(json_encode(['success' => false, 'message' => 'DB connection failed']));

    // ── Ensure unloading_data table ─────────────────────────────────
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
        is_prev_day         TINYINT(1) NOT NULL DEFAULT 0,
        status              VARCHAR(20) DEFAULT 'imported',
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_import    (import_id),
        INDEX idx_person    (delivery_person_name),
        INDEX idx_sku       (sku_code)
    )");

    // ── Add is_prev_day to existing table if column is missing ──────
    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM unloading_data LIKE 'is_prev_day'");
    if (!$col_check || mysqli_num_rows($col_check) === 0) {
        mysqli_query($conn, "ALTER TABLE unloading_data
            ADD COLUMN `is_prev_day` TINYINT(1) NOT NULL DEFAULT 0 AFTER delivery_date");
    }

    // ── Route action ────────────────────────────────────────────────
    $raw    = file_get_contents('php://input');
    $body   = $raw ? json_decode($raw, true) : [];
    $action = $_GET['action'] ?? ($body['action'] ?? '');

    switch ($action) {

        // ─── INIT: copy import_details → unloading_data ─────────────
        case 'init':
            $import_id = intval($body['import_id'] ?? ($_GET['import_id'] ?? 0));
            if (!$import_id) die(json_encode(['success' => false, 'message' => 'No import_id']));

            $cnt = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) as c FROM unloading_data WHERE import_id = $import_id"));
            if ($cnt && $cnt['c'] > 0) {
                echo json_encode(['success' => true, 'already' => true, 'count' => intval($cnt['c'])]);
                exit;
            }

            $q = "INSERT INTO unloading_data
                    (import_id, import_detail_id, record_date,
                     delivery_person_code, delivery_person_name, vehicle,
                     sku_code, sku_desc, tur, mrp,
                     adj_qty_good_units, adj_qty_damage,
                     actual_qty, actual_damage_qty, short_excess,
                     charge_to_employee, absorb_by_company, pay_variance,
                     delivery_date, is_prev_day, status)
                  SELECT
                     import_id, id, record_date,
                     delivery_person_code, delivery_person_name, vehicle,
                     sku_code, sku_desc, tur, mrp,
                     ABS(difference_units), ABS(adj_qty_damage),
                     actual_qty, NULL, short_excess,
                     charge_to_employee, absorb_by_company, pay_variance,
                     delivery_date, 0, status
                  FROM unloading_summary_import_details
                  WHERE import_id = $import_id
                    AND NOT (COALESCE(difference_units,0) = 0
                         AND COALESCE(adj_qty_damage,0) = 0)";

            if (mysqli_query($conn, $q)) {
                echo json_encode(['success' => true, 'count' => mysqli_affected_rows($conn)]);
            } else {
                echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
            }
            break;

        // ─── CHECK ───────────────────────────────────────────────────
        case 'check':
            $import_id = intval($_GET['import_id'] ?? 0);
            $cnt = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) as c FROM unloading_data WHERE import_id = $import_id"));
            echo json_encode([
                'success'     => true,
                'initialized' => ($cnt && $cnt['c'] > 0),
                'count'       => intval($cnt['c'] ?? 0)
            ]);
            break;

        // ─── SAVE: single or bulk ────────────────────────────────────
        case 'save':
            if (isset($body['bulk']) && is_array($body['bulk'])) {
                $updated = 0; $errors = 0;
                foreach ($body['bulk'] as $row) {
                    $id                = intval($row['id'] ?? 0);
                    $actual_qty        = floatval($row['actual_qty']        ?? 0);
                    $actual_damage_qty = floatval($row['actual_damage_qty'] ?? 0);
                    $short_excess      = floatval($row['short_excess']      ?? 0);
                    if ($id <= 0) { $errors++; continue; }
                    $q = "UPDATE unloading_data
                          SET actual_qty        = $actual_qty,
                              actual_damage_qty  = $actual_damage_qty,
                              short_excess       = $short_excess
                          WHERE id = $id";
                    if (mysqli_query($conn, $q)) $updated++; else $errors++;
                }
                echo json_encode(['success' => true, 'updated' => $updated, 'errors' => $errors]);
            } else {
                $id                = intval($body['id']                ?? 0);
                $actual_qty        = floatval($body['actual_qty']        ?? 0);
                $actual_damage_qty = floatval($body['actual_damage_qty'] ?? 0);
                $short_excess      = floatval($body['short_excess']      ?? 0);
                if ($id <= 0) die(json_encode(['success' => false, 'message' => 'Invalid ID']));
                $q = "UPDATE unloading_data
                      SET actual_qty        = $actual_qty,
                          actual_damage_qty  = $actual_damage_qty,
                          short_excess       = $short_excess
                      WHERE id = $id";
                echo json_encode(mysqli_query($conn, $q)
                    ? ['success' => true,  'id' => $id]
                    : ['success' => false, 'message' => mysqli_error($conn)]);
            }
            break;

        // ─── ADD ROW ─────────────────────────────────────────────────
        case 'add_row':
            $import_id  = intval($body['import_id'] ?? 0);
            $sku_code   = mysqli_real_escape_string($conn, trim($body['sku_code']             ?? ''));
            $sku_desc   = mysqli_real_escape_string($conn, trim($body['sku_desc']             ?? ''));
            $dp_name    = mysqli_real_escape_string($conn, trim($body['delivery_person_name'] ?? ''));
            $dp_code    = mysqli_real_escape_string($conn, trim($body['delivery_person_code'] ?? ''));
            $vehicle    = mysqli_real_escape_string($conn, trim($body['vehicle']              ?? ''));
            $tur        = floatval($body['tur']               ?? 0);
            $mrp        = floatval($body['mrp']               ?? 0);
            $actual_qty = floatval($body['actual_qty']        ?? 0);
            $actual_dmg = floatval($body['actual_damage_qty'] ?? 0);

            $is_prev_day = !empty($body['is_prev_day']) ? 1 : 0;

            if (empty($sku_code)) die(json_encode(['success' => false, 'message' => 'SKU code required']));
            if (!$import_id)      die(json_encode(['success' => false, 'message' => 'import_id required']));

            // Delivery date
            $dd_raw = trim($body['delivery_date'] ?? '');
            $dd = 'NULL';
            if (!empty($dd_raw)) {
                $ts = strtotime($dd_raw);
                if ($ts) $dd = "'" . date('Y-m-d', $ts) . "'";
            }

            // Record date
            $rd_raw = trim($body['record_date'] ?? '');
            if (!empty($rd_raw)) {
                $ts2 = strtotime($rd_raw);
                $rd  = $ts2 ? "'" . date('Y-m-d', $ts2) . "'" : ($dd !== 'NULL' ? $dd : "'" . date('Y-m-d') . "'");
            } else {
                $rd = $is_prev_day ? ($dd !== 'NULL' ? $dd : "'" . date('Y-m-d') . "'") : "'" . date('Y-m-d') . "'";
            }

            $q = "INSERT INTO unloading_data
                    (import_id, record_date,
                     delivery_person_code, delivery_person_name, vehicle,
                     sku_code, sku_desc, tur, mrp,
                     adj_qty_good_units, adj_qty_damage,
                     actual_qty, actual_damage_qty,
                     delivery_date, is_prev_day, status)
                  VALUES
                    ($import_id, $rd,
                     '$dp_code', '$dp_name', '$vehicle',
                     '$sku_code', '$sku_desc', $tur, $mrp,
                     0, 0,
                     $actual_qty, $actual_dmg,
                     $dd, $is_prev_day, 'imported')";

            if (mysqli_query($conn, $q)) {
                $new_id = mysqli_insert_id($conn);
                echo json_encode(['success' => true, 'id' => $new_id, 'is_prev_day' => $is_prev_day]);
            } else {
                echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
            }
            break;

        // ─── DELETE ROW ──────────────────────────────────────────────
        case 'delete_row':
            $id = intval($body['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                exit;
            }
            if (mysqli_query($conn, "DELETE FROM unloading_data WHERE id = $id")) {
                echo json_encode(['success' => true, 'id' => $id]);
            } else {
                echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
            }
            break;

        // ─── GET ITEMS ───────────────────────────────────────────────
        case 'get_items':
            $import_id = intval($_GET['import_id'] ?? 0);
            $search    = mysqli_real_escape_string($conn, trim($_GET['q'] ?? ''));
            $items     = [];
            $prices    = [];

            // Priority 1 — current import's own prices
            if ($import_id) {
                $pr = mysqli_query($conn,
                    "SELECT sku_code, tur, mrp
                     FROM unloading_summary_import_details
                     WHERE import_id = $import_id AND (tur > 0 OR mrp > 0)
                     ORDER BY id DESC");
                if ($pr) {
                    while ($row = mysqli_fetch_assoc($pr)) {
                        if (!isset($prices[$row['sku_code']])) {
                            $prices[$row['sku_code']] = [
                                'tur' => floatval($row['tur']),
                                'mrp' => floatval($row['mrp'])
                            ];
                        }
                    }
                }
            }

            // Priority 2 — any other import as fallback
            $pr2 = mysqli_query($conn,
                "SELECT sku_code, tur, mrp FROM unloading_summary_import_details
                 WHERE (tur > 0 OR mrp > 0) ORDER BY id DESC LIMIT 2000");
            if ($pr2) {
                while ($row = mysqli_fetch_assoc($pr2)) {
                    if (!isset($prices[$row['sku_code']])) {
                        $prices[$row['sku_code']] = [
                            'tur' => floatval($row['tur']),
                            'mrp' => floatval($row['mrp'])
                        ];
                    }
                }
            }

            // All items from items table
            $where = $search
                ? "WHERE sku_code LIKE '%$search%' OR sku_desc LIKE '%$search%'"
                : '';
            $ir = mysqli_query($conn,
                "SELECT sku_code, sku_desc FROM items $where ORDER BY sku_code ASC LIMIT 9999");
            if ($ir) {
                while ($row = mysqli_fetch_assoc($ir)) {
                    $sku        = $row['sku_code'];
                    $row['tur'] = isset($prices[$sku]) ? $prices[$sku]['tur'] : 0;
                    $row['mrp'] = isset($prices[$sku]) ? $prices[$sku]['mrp'] : 0;
                    $items[]    = $row;
                }
            }

            echo json_encode(['success' => true, 'items' => $items]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
} catch (Error $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
exit;
?>