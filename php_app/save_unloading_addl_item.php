<?php
// save_unloading_addl_item.php
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

    if (json_last_error() !== JSON_ERROR_NONE || !$data) {
        die(json_encode(['success' => false, 'message' => 'Invalid JSON']));
    }

    $action = $data['action'] ?? 'update';

    // ── INSERT new additional item ────────────────────────────────────────────
    if ($action === 'new') {
        $import_id   = intval($data['import_id']            ?? 0);
        $dp_code     = mysqli_real_escape_string($conn, $data['delivery_person_code'] ?? '');
        $dp_name     = mysqli_real_escape_string($conn, $data['delivery_person_name'] ?? '');
        $sku_code    = mysqli_real_escape_string($conn, $data['sku_code']             ?? '');
        $sku_desc    = mysqli_real_escape_string($conn, $data['sku_desc']             ?? '');
        $good_qty    = floatval($data['good_qty']           ?? 0);
        $damage_qty  = floatval($data['damage_qty']         ?? 0);
        $tur         = floatval($data['tur']                ?? 0);
        $mrp         = floatval($data['mrp']                ?? 0);

        if (!$import_id || empty($sku_code)) {
            die(json_encode(['success' => false, 'message' => 'Missing import_id or sku_code']));
        }

        $sql = "INSERT INTO unloading_import_additional_items
                    (import_id, delivery_person_code, delivery_person_name,
                     sku_code, sku_desc, good_qty, damage_qty, tur, mrp)
                VALUES
                    ($import_id, '$dp_code', '$dp_name',
                     '$sku_code', '$sku_desc', $good_qty, $damage_qty, $tur, $mrp)";

        if (!mysqli_query($conn, $sql)) {
            die(json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)]));
        }

        $new_id = mysqli_insert_id($conn);

        echo json_encode([
            'success' => true,
            'item'    => [
                'id'                   => $new_id,
                'import_id'            => $import_id,
                'delivery_person_code' => $data['delivery_person_code'] ?? '',
                'delivery_person_name' => $data['delivery_person_name'] ?? '',
                'sku_code'             => $data['sku_code']             ?? '',
                'sku_desc'             => $data['sku_desc']             ?? '',
                'good_qty'             => $good_qty,
                'damage_qty'           => $damage_qty,
                'tur'                  => $tur,
                'mrp'                  => $mrp,
            ]
        ]);

    // ── UPDATE actual qtys on existing additional item ────────────────────────
    } else {
        $id               = intval($data['id']               ?? 0);
        $actual_good_qty  = isset($data['actual_good_qty'])  && $data['actual_good_qty']  !== null ? floatval($data['actual_good_qty'])  : null;
        $actual_damage_qty = isset($data['actual_damage_qty']) && $data['actual_damage_qty'] !== null ? floatval($data['actual_damage_qty']) : null;
        $short_excess     = isset($data['short_excess'])      && $data['short_excess']      !== null ? floatval($data['short_excess'])      : null;

        if (!$id) {
            die(json_encode(['success' => false, 'message' => 'Missing id']));
        }

        $sets = [];
        if ($actual_good_qty   !== null) $sets[] = "actual_good_qty  = $actual_good_qty";
        if ($actual_damage_qty !== null) $sets[] = "actual_damage_qty = $actual_damage_qty";
        if ($short_excess      !== null) $sets[] = "short_excess = $short_excess";

        if (empty($sets)) {
            die(json_encode(['success' => false, 'message' => 'Nothing to update']));
        }

        $sql = "UPDATE unloading_import_additional_items SET " . implode(', ', $sets) . " WHERE id = $id";

        if (!mysqli_query($conn, $sql)) {
            die(json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)]));
        }

        echo json_encode(['success' => true, 'updated' => $id]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
} catch (Error $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
exit;
