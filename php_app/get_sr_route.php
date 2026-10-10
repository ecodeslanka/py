<?php
error_reporting(0);
ini_set('display_errors', 0);

ob_start();
include 'config.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$sr_code      = isset($_GET['sr_code'])      ? trim($_GET['sr_code'])      : '';
$delivery_date = isset($_GET['delivery_date']) ? trim($_GET['delivery_date']) : '';

if (empty($sr_code)) {
    echo json_encode(['success' => false, 'message' => 'SR Code is required']);
    exit;
}

$sr  = mysqli_real_escape_string($conn, $sr_code);
$dd  = mysqli_real_escape_string($conn, $delivery_date);

// Build query — if delivery_date supplied, filter by it; otherwise find latest available
if (!empty($dd)) {
    $q = "SELECT route_name, COUNT(*) as record_count
          FROM loading_summary_import_details
          WHERE sales_person_code = '$sr'
            AND delivery_date = '$dd'
            AND status = 'imported'
          GROUP BY route_name
          ORDER BY record_count DESC
          LIMIT 1";
} else {
    $q = "SELECT route_name, COUNT(*) as record_count
          FROM loading_summary_import_details
          WHERE sales_person_code = '$sr'
            AND status = 'imported'
          GROUP BY route_name
          ORDER BY record_count DESC
          LIMIT 1";
}

$result = mysqli_query($conn, $q);

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    exit;
}

if (mysqli_num_rows($result) === 0) {
    $msg = empty($dd)
        ? "No records found for SR Code '$sr_code'"
        : "No records found for SR Code '$sr_code' on $delivery_date";
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

$row = mysqli_fetch_assoc($result);

echo json_encode([
    'success'      => true,
    'route'        => $row['route_name'],
    'record_count' => (int)$row['record_count'],
    'sr_code'      => $sr_code
]);
exit;
?>