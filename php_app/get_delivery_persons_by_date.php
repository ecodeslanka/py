<?php
error_reporting(0);
ini_set('display_errors', 0);

ob_start();
include 'config.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

try {
    $delivery_date = trim($_GET['delivery_date'] ?? '');

    if (empty($delivery_date)) {
        echo json_encode(['success' => false, 'message' => 'Delivery date is required']);
        exit;
    }

    $dd = mysqli_real_escape_string($conn, $delivery_date);

    // Get all distinct delivery persons with imported records for this date
    $q = "SELECT DISTINCT delivery_person
          FROM loading_summary_import_details
          WHERE delivery_date = '$dd'
            AND status = 'imported'
            AND delivery_person IS NOT NULL
            AND delivery_person != ''
          ORDER BY delivery_person";

    $result = mysqli_query($conn, $q);

    if (!$result) {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)]);
        exit;
    }

    // Get delivery persons that already have a field summary for this date
    $already_q = "SELECT delivery_person_raw_name
                  FROM field_summary
                  WHERE delivery_date = '$dd'
                    AND delivery_person_raw_name IS NOT NULL
                    AND delivery_person_raw_name != ''";

    $already_result = mysqli_query($conn, $already_q);
    $already_created = [];
    if ($already_result) {
        while ($row = mysqli_fetch_assoc($already_result)) {
            $already_created[] = $row['delivery_person_raw_name'];
        }
    }

    // Only return persons who do NOT already have a field summary
    $persons = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $name = $row['delivery_person'];
        if (!in_array($name, $already_created)) {
            $persons[] = ['name' => $name];
        }
    }

    echo json_encode([
        'success' => true,
        'persons' => $persons,
        'count'   => count($persons)
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
}
exit;
?>