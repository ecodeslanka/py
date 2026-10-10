<?php
error_reporting(0);
ini_set('display_errors', 0);

ob_start();
include 'config.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

try {
    $delivery_date   = trim($_GET['delivery_date']   ?? '');
    $delivery_person = trim($_GET['delivery_person'] ?? '');

    if (empty($delivery_date) || empty($delivery_person)) {
        echo json_encode(['success' => false, 'message' => 'Date and delivery person are required']);
        exit;
    }

    $dd = mysqli_real_escape_string($conn, $delivery_date);
    $dp = mysqli_real_escape_string($conn, $delivery_person);
$q = "SELECT COUNT(*) AS cnt
      FROM loading_summary_import_details
      WHERE delivery_date   = '$dd'
        AND delivery_person LIKE '%$dp%'
        AND status          = 'imported'";
    $result = mysqli_query($conn, $q);

    if (!$result) {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)]);
        exit;
    }

    $row   = mysqli_fetch_assoc($result);
    $count = (int)($row['cnt'] ?? 0);

    if ($count === 0) {
        echo json_encode(['success' => false, 'message' => 'No imported records found for this delivery person on this date']);
        exit;
    }

    echo json_encode([
        'success'      => true,
        'record_count' => $count
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
}
exit;
?>
