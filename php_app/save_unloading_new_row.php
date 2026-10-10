<?php
// save_unloading_new_row.php
// Saves a manually added item row into unloading_summary_import_details
error_reporting(0);
ini_set('display_errors', 0);

ob_start();
include 'config.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        die(json_encode(['success' => false, 'message' => 'Invalid method']));
    }

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data) {
        die(json_encode(['success' => false, 'message' => 'No data']));
    }

    $import_id   = intval($data['import_id']   ?? 0);
    $sku_code    = mysqli_real_escape_string($conn, $data['sku_code']  ?? '');
    $sku_desc    = mysqli_real_escape_string($conn, $data['sku_desc']  ?? '');
    $person_name = mysqli_real_escape_string($conn, $data['delivery_person_name'] ?? '');
    $good_qty    = floatval($data['adj_qty_good_units'] ?? 0);
    $dmg_qty     = floatval($data['adj_qty_damage']     ?? 0);
    $actual_qty  = isset($data['actual_qty'])     && $data['actual_qty']     !== null ? floatval($data['actual_qty'])     : null;
    $actual_dmg  = isset($data['actual_dmg_qty']) && $data['actual_dmg_qty'] !== null ? floatval($data['actual_dmg_qty']) : null;

    if (!$import_id || !$sku_code || !$person_name) {
        die(json_encode(['success' => false, 'message' => 'Missing required fields']));
    }

    // Reject if both qtys are 0
    if ($good_qty == 0 && $dmg_qty == 0) {
        die(json_encode(['success' => false, 'message' => 'At least one quantity must be > 0']));
    }

    $aq_sql  = $actual_qty  !== null ? $actual_qty  : 'NULL';
    $adq_sql = $actual_dmg  !== null ? $actual_dmg  : 'NULL';

    // Look up delivery_person_code and vehicle from existing rows in same import
    $dp_res = mysqli_query($conn,
        "SELECT delivery_person_code, vehicle FROM unloading_summary_import_details
         WHERE import_id = $import_id AND delivery_person_name = '$person_name'
         LIMIT 1");
    $dp_code = ''; $vehicle = '';
    if ($dp_res && $row = mysqli_fetch_assoc($dp_res)) {
        $dp_code = $row['delivery_person_code'];
        $vehicle = $row['vehicle'];
    }
    $dp_code_esc = mysqli_real_escape_string($conn, $dp_code);
    $vehicle_esc = mysqli_real_escape_string($conn, $vehicle);

    // Get MRP from items table
    $mrp = 0;
    $it_res = mysqli_query($conn, "SELECT mrp FROM items WHERE sku_code = '$sku_code' LIMIT 1");
    if ($it_res && $it_row = mysqli_fetch_assoc($it_res)) {
        $mrp = floatval($it_row['mrp']);
    }

    $sql = "INSERT INTO unloading_summary_import_details
        (import_id, delivery_person_code, delivery_person_name, vehicle,
         sku_code, sku_desc, mrp,
         adj_qty_good_units, adj_qty_damage,
         actual_qty, actual_dmg_qty,
         status)
        VALUES
        ($import_id, '$dp_code_esc', '$person_name', '$vehicle_esc',
         '$sku_code', '$sku_desc', $mrp,
         $good_qty, $dmg_qty,
         $aq_sql, $adq_sql,
         'imported')";

    if (!mysqli_query($conn, $sql)) {
        die(json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)]));
    }

    $new_id = mysqli_insert_id($conn);

    // Also update total_records count in the import header
    mysqli_query($conn,
        "UPDATE unloading_summary_imports
         SET total_records    = total_records + 1,
             imported_records = imported_records + 1
         WHERE id = $import_id");

    echo json_encode(['success' => true, 'new_id' => $new_id]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
?>
