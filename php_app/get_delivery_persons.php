<?php
/**
 * get_delivery_persons.php
 * Returns all distinct delivery_person values from loading_summary_import_details
 * for the LATEST TWO delivery_date values.
 * No employee matching. No filtering beyond the two most recent dates.
 *
 * Returns: { success, delivery_dates: [date1, date2], persons: ["Name1", "Name2", ...] }
 */
include 'config.php';
header('Content-Type: application/json');

// Get the two most recent distinct delivery_date values
$datesSql = "
    SELECT DISTINCT delivery_date
    FROM loading_summary_import_details
    WHERE delivery_person IS NOT NULL
      AND delivery_person != ''
      AND delivery_date IS NOT NULL
    ORDER BY delivery_date DESC
    LIMIT 7
";
$datesResult = mysqli_query($conn, $datesSql);
if (!$datesResult) {
    echo json_encode(['success' => false, 'message' => mysqli_error($conn), 'persons' => []]);
    exit;
}

$dates = [];
while ($row = mysqli_fetch_assoc($datesResult)) {
    $dates[] = $row['delivery_date'];
}

if (empty($dates)) {
    echo json_encode(['success' => true, 'delivery_dates' => [], 'persons' => []]);
    exit;
}

// Build IN clause safely using escaped values
$escapedDates = array_map(function($d) use ($conn) {
    return "'" . mysqli_real_escape_string($conn, $d) . "'";
}, $dates);
$datesInClause = implode(',', $escapedDates);

$sql = "
    SELECT DISTINCT delivery_person
    FROM loading_summary_import_details
    WHERE delivery_person IS NOT NULL
      AND delivery_person != ''
      AND delivery_date IN ($datesInClause)
    ORDER BY delivery_person
";
$result = mysqli_query($conn, $sql);
if (!$result) {
    echo json_encode(['success' => false, 'message' => mysqli_error($conn), 'persons' => []]);
    exit;
}

$persons = [];
while ($row = mysqli_fetch_assoc($result)) {
    $persons[] = $row['delivery_person'];
}

echo json_encode([
    'success' => true,
    'delivery_dates' => $dates,
    'persons' => $persons
]);