<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
include 'config.php';
include 'xlsx_lite.php';
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

try {
    if (!$conn) die(json_encode(['success' => false, 'message' => 'DB connection failed']));

    // ── Ensure reconciliation table ─────────────────────────────────
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS unloading_reconciliation (
        id                              INT AUTO_INCREMENT PRIMARY KEY,
        import_id                       INT NOT NULL,
        unloading_data_id               INT NULL,
        delivery_date                   DATE NULL,
        sku_code                        VARCHAR(100) NULL,
        sku_desc                        VARCHAR(500) NULL,
        system_qty                      DECIMAL(12,2) NULL,
        excel_qty                       DECIMAL(12,2) NULL,
        match_status                    VARCHAR(20) NULL,
        manual_matched_sku              VARCHAR(100) NULL,
        manual_matched_desc             VARCHAR(500) NULL,
        manual_matched_qty              DECIMAL(12,2) NULL,
        manual_matched_delivery_date    DATE NULL,
        damage_cleared_ref              VARCHAR(150) NULL,
        excel_filename                  VARCHAR(255) NULL,
        created_at                      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_import (import_id),
        INDEX idx_sku (sku_code),
        INDEX idx_ud (unloading_data_id)
    )");

    // ── Self-heal: make sure every column we need actually exists ────
    // (covers the case where an older/broken version of this table was
    // created before, so CREATE TABLE IF NOT EXISTS above was a no-op)
    function ensure_column($conn, $table, $column, $definition) {
        $check = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
        if (!$check || mysqli_num_rows($check) === 0) {
            mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }

    ensure_column($conn, 'unloading_reconciliation', 'import_id', 'INT NOT NULL DEFAULT 0 AFTER id');
    ensure_column($conn, 'unloading_reconciliation', 'unloading_data_id', 'INT NULL');
    ensure_column($conn, 'unloading_reconciliation', 'delivery_date', 'DATE NULL');
    ensure_column($conn, 'unloading_reconciliation', 'sku_code', 'VARCHAR(100) NULL');
    ensure_column($conn, 'unloading_reconciliation', 'sku_desc', 'VARCHAR(500) NULL');
    ensure_column($conn, 'unloading_reconciliation', 'system_qty', 'DECIMAL(12,2) NULL');
    ensure_column($conn, 'unloading_reconciliation', 'excel_qty', 'DECIMAL(12,2) NULL');
    ensure_column($conn, 'unloading_reconciliation', 'match_status', 'VARCHAR(20) NULL');
    ensure_column($conn, 'unloading_reconciliation', 'manual_matched_sku', 'VARCHAR(100) NULL');
    ensure_column($conn, 'unloading_reconciliation', 'manual_matched_desc', 'VARCHAR(500) NULL');
    ensure_column($conn, 'unloading_reconciliation', 'manual_matched_qty', 'DECIMAL(12,2) NULL');
    ensure_column($conn, 'unloading_reconciliation', 'manual_matched_delivery_date', 'DATE NULL');
    ensure_column($conn, 'unloading_reconciliation', 'damage_cleared_ref', 'VARCHAR(150) NULL');
    ensure_column($conn, 'unloading_reconciliation', 'excel_filename', 'VARCHAR(255) NULL');
    ensure_column($conn, 'unloading_reconciliation', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');

    // ── Ensure unloading_data.reconciled flag exists ──────────────────
    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM unloading_data LIKE 'reconciled'");
    if (!$col_check || mysqli_num_rows($col_check) === 0) {
        mysqli_query($conn, "ALTER TABLE unloading_data
            ADD COLUMN `reconciled` TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }

    $action = $_GET['action'] ?? ($_POST['action'] ?? '');

    switch ($action) {

        // ─── PREVIEW: parse uploaded excel & tally vs system rows ────
        case 'preview':
            $import_id = intval($_POST['import_id'] ?? 0);
            if (!$import_id) die(json_encode(['success' => false, 'message' => 'No import_id']));
            if (empty($_FILES['excel_file']['tmp_name'])) {
                die(json_encode(['success' => false, 'message' => 'No file uploaded']));
            }

            $tmp_path = $_FILES['excel_file']['tmp_name'];
            $orig_name = $_FILES['excel_file']['name'];

            try {
                $excel_rows = parse_stock_adjustment_excel($tmp_path);
            } catch (Exception $ex) {
                die(json_encode(['success' => false, 'message' => 'Excel parse error: ' . $ex->getMessage()]));
            }

            if (empty($excel_rows)) {
                echo json_encode(['success' => true, 'preview' => [], 'filename' => $orig_name,
                    'message' => 'No "Delivery Shorts / Delivery Excess" rows found in this file.']);
                break;
            }

            // System rows for this import that actually have a short/excess
            $sys_map = [];
            $sr = mysqli_query($conn,
                "SELECT id, sku_code, sku_desc, delivery_date, short_excess
                 FROM unloading_data
                 WHERE import_id = $import_id
                   AND short_excess IS NOT NULL AND short_excess <> 0");
            if ($sr) {
                while ($row = mysqli_fetch_assoc($sr)) {
                    $d = !empty($row['delivery_date']) ? date('Y-m-d', strtotime($row['delivery_date'])) : '';
                    $key = $d . '|' . strtoupper(trim($row['sku_code']));
                    $sys_map[$key] = $row;
                }
            }

            // Excel rows keyed the same way. A SKU can appear more than once for
            // the same delivery date (e.g. a "Delivery Shorts" line AND a
            // "Delivery Excess" correction line for the same SKU/date) — net
            // those together rather than letting one overwrite the other.
            $excel_map = [];
            foreach ($excel_rows as $er) {
                $key = $er['delivery_date'] . '|' . strtoupper(trim($er['sku_code']));
                if (isset($excel_map[$key])) {
                    $excel_map[$key]['qty_signed'] += $er['qty_signed'];
                } else {
                    $excel_map[$key] = $er;
                }
            }

            $all_keys = array_unique(array_merge(array_keys($sys_map), array_keys($excel_map)));
            $preview = [];

            foreach ($all_keys as $key) {
                $sys = $sys_map[$key] ?? null;
                $exc = $excel_map[$key] ?? null;

                $system_qty = $sys ? floatval($sys['short_excess']) : null;
                $excel_qty  = $exc ? floatval($exc['qty_signed'])   : null;

                if ($sys && $exc) {
                    $status = (abs($system_qty - $excel_qty) < 0.01) ? 'matched' : 'mismatch';
                } elseif ($sys && !$exc) {
                    $status = 'not_in_excel';
                } else {
                    $status = 'not_in_system';
                }

                $preview[] = [
                    'unloading_data_id' => $sys ? intval($sys['id']) : null,
                    'sku_code'          => $sys ? $sys['sku_code'] : $exc['sku_code'],
                    'sku_desc'          => $sys ? $sys['sku_desc'] : $exc['sku_desc'],
                    'delivery_date'     => $sys ? date('Y-m-d', strtotime($sys['delivery_date'])) : $exc['delivery_date'],
                    'system_qty'        => $system_qty,
                    'excel_qty'         => $excel_qty,
                    'match_status'      => $status,
                ];
            }

            // Sort: mismatches / missing first, matched last
            usort($preview, function($a, $b) {
                $order = ['mismatch' => 0, 'not_in_system' => 1, 'not_in_excel' => 2, 'matched' => 3];
                return $order[$a['match_status']] <=> $order[$b['match_status']];
            });

            echo json_encode(['success' => true, 'preview' => $preview, 'filename' => $orig_name]);
            break;

        // ─── SAVE: persist the reconciliation rows the user kept ─────
        case 'save':
            $raw  = file_get_contents('php://input');
            $body = $raw ? json_decode($raw, true) : [];
            $import_id = intval($body['import_id'] ?? 0);
            $filename  = mysqli_real_escape_string($conn, trim($body['filename'] ?? ''));
            $rows      = is_array($body['rows'] ?? null) ? $body['rows'] : [];

            if (!$import_id) die(json_encode(['success' => false, 'message' => 'No import_id']));
            if (empty($rows))  die(json_encode(['success' => false, 'message' => 'No rows to save']));

            $saved = 0; $errors = 0; $last_error = '';
            foreach ($rows as $r) {
                $ud_id   = isset($r['unloading_data_id']) && $r['unloading_data_id'] !== null
                            ? intval($r['unloading_data_id']) : null;
                $sku     = mysqli_real_escape_string($conn, trim($r['sku_code'] ?? ''));
                $desc    = mysqli_real_escape_string($conn, trim($r['sku_desc'] ?? ''));
                $ddate   = mysqli_real_escape_string($conn, trim($r['delivery_date'] ?? ''));
                $sysQty  = isset($r['system_qty']) && $r['system_qty'] !== null ? floatval($r['system_qty']) : null;
                $excQty  = isset($r['excel_qty']) && $r['excel_qty'] !== null ? floatval($r['excel_qty']) : null;
                $status  = mysqli_real_escape_string($conn, trim($r['match_status'] ?? ''));

                // Manual-match extras (only present when match_status === 'manual')
                $mSku    = mysqli_real_escape_string($conn, trim($r['manual_matched_sku'] ?? ''));
                $mDesc   = mysqli_real_escape_string($conn, trim($r['manual_matched_desc'] ?? ''));
                $mQty    = isset($r['manual_matched_qty']) && $r['manual_matched_qty'] !== null && $r['manual_matched_qty'] !== ''
                            ? floatval($r['manual_matched_qty']) : null;
                $mDate   = mysqli_real_escape_string($conn, trim($r['manual_matched_delivery_date'] ?? ''));

                // Damage-cleared extra (only present when match_status === 'damage_cleared')
                $dcRef   = mysqli_real_escape_string($conn, trim($r['damage_cleared_ref'] ?? ''));

                $ud_sql     = $ud_id ? $ud_id : 'NULL';
                $date_sql   = $ddate ? "'$ddate'" : 'NULL';
                $sysQty_sql = $sysQty !== null ? $sysQty : 'NULL';
                $excQty_sql = $excQty !== null ? $excQty : 'NULL';
                $mSku_sql   = $mSku !== '' ? "'$mSku'" : 'NULL';
                $mDesc_sql  = $mDesc !== '' ? "'$mDesc'" : 'NULL';
                $mQty_sql   = $mQty !== null ? $mQty : 'NULL';
                $mDate_sql  = $mDate !== '' ? "'$mDate'" : 'NULL';
                $dcRef_sql  = $dcRef !== '' ? "'$dcRef'" : 'NULL';

                $q = "INSERT INTO unloading_reconciliation
                        (import_id, unloading_data_id, delivery_date, sku_code, sku_desc,
                         system_qty, excel_qty, match_status,
                         manual_matched_sku, manual_matched_desc, manual_matched_qty, manual_matched_delivery_date,
                         damage_cleared_ref, excel_filename)
                      VALUES
                        ($import_id, $ud_sql, $date_sql, '$sku', '$desc',
                         $sysQty_sql, $excQty_sql, '$status',
                         $mSku_sql, $mDesc_sql, $mQty_sql, $mDate_sql,
                         $dcRef_sql, '$filename')";

                if (mysqli_query($conn, $q)) {
                    $saved++;
                    if ($ud_id) {
                        mysqli_query($conn, "UPDATE unloading_data SET reconciled = 1 WHERE id = $ud_id");
                    }
                } else {
                    $errors++;
                    $last_error = mysqli_error($conn);
                }
            }

            $resp = ['success' => true, 'saved' => $saved, 'errors' => $errors];
            if ($errors > 0) $resp['last_error'] = $last_error;
            echo json_encode($resp);
            break;

        // ─── LIST: saved reconciliations for an import ────────────────
        case 'list':
            $import_id = intval($_GET['import_id'] ?? 0);
            if (!$import_id) die(json_encode(['success' => false, 'message' => 'No import_id']));

            $items = [];
            $r = mysqli_query($conn,
                "SELECT * FROM unloading_reconciliation
                 WHERE import_id = $import_id
                 ORDER BY created_at DESC, id DESC");
            if ($r) while ($row = mysqli_fetch_assoc($r)) $items[] = $row;

            echo json_encode(['success' => true, 'items' => $items]);
            break;

        // ─── DELETE: remove a saved reconciliation row ─────────────────
        case 'delete':
            $raw  = file_get_contents('php://input');
            $body = $raw ? json_decode($raw, true) : [];
            $id = intval($body['id'] ?? ($_GET['id'] ?? 0));
            if ($id <= 0) die(json_encode(['success' => false, 'message' => 'Invalid ID']));

            $row = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT unloading_data_id FROM unloading_reconciliation WHERE id = $id"));

            if (mysqli_query($conn, "DELETE FROM unloading_reconciliation WHERE id = $id")) {
                if ($row && !empty($row['unloading_data_id'])) {
                    $ud_id = intval($row['unloading_data_id']);
                    mysqli_query($conn, "UPDATE unloading_data SET reconciled = 0 WHERE id = $ud_id");
                }
                echo json_encode(['success' => true, 'id' => $id]);
            } else {
                echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
            }
            break;

        // ─── DELETE ALL: wipe every saved reconciliation for an import ─
        case 'delete_all':
            $raw  = file_get_contents('php://input');
            $body = $raw ? json_decode($raw, true) : [];
            $import_id = intval($body['import_id'] ?? ($_GET['import_id'] ?? 0));
            if (!$import_id) die(json_encode(['success' => false, 'message' => 'No import_id']));

            $cnt_row = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) AS c FROM unloading_reconciliation WHERE import_id = $import_id"));
            $count = intval($cnt_row['c'] ?? 0);

            if ($count === 0) {
                echo json_encode(['success' => true, 'deleted' => 0]);
                break;
            }

            if (mysqli_query($conn, "DELETE FROM unloading_reconciliation WHERE import_id = $import_id")) {
                mysqli_query($conn, "UPDATE unloading_data SET reconciled = 0 WHERE import_id = $import_id");
                echo json_encode(['success' => true, 'deleted' => $count]);
            } else {
                echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
            }
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