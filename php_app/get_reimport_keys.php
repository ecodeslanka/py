<?php
/**
 * get_reimport_keys.php
 * Returns existing delivery_person_code|sku_code keys from unloading_data
 * for a given import_id, so the re-import preview can classify rows as
 * "new" or "update" accurately.
 */
error_reporting(0);
ini_set('display_errors', 0);

ob_start();
include 'config.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$import_id = intval($_GET['import_id'] ?? 0);

if (!$import_id || !$conn) {
    echo json_encode(['keys' => []]);
    exit;
}

$keys = [];
$res = mysqli_query($conn,
    "SELECT delivery_person_code, sku_code
     FROM unloading_data
     WHERE import_id = $import_id");

if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $keys[] = ($row['delivery_person_code'] ?? '') . '|' . ($row['sku_code'] ?? '');
    }
}

echo json_encode(['keys' => $keys]);
exit;
?>
