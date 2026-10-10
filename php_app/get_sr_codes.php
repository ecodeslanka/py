<?php
include 'config.php';

$delivery_date = isset($_GET['delivery_date']) ? mysqli_real_escape_string($conn, $_GET['delivery_date']) : '';

if (!$delivery_date) {
    echo json_encode(['success' => false, 'message' => 'No date provided']);
    exit;
}

$query = "SELECT DISTINCT d.sales_person_code
          FROM loading_summary_import_details d
          INNER JOIN loading_summary_imports i ON i.id = d.import_id
          WHERE i.delivery_date = '$delivery_date'
            AND d.sales_person_code IS NOT NULL
            AND d.sales_person_code != ''
            AND d.status = 'imported'
          ORDER BY d.sales_person_code";

$result = mysqli_query($conn, $query);

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Query error']);
    exit;
}

$sr_codes = [];

while ($row = mysqli_fetch_assoc($result)) {
    $sr_code = $row['sales_person_code'];

    // Check if a field summary already exists for this SR code + delivery date
    $checkQuery = "SELECT COUNT(*) AS cnt 
                   FROM field_summary 
                   WHERE sr_code = '$sr_code' 
                     AND delivery_date = '$delivery_date'";
    $checkResult = mysqli_query($conn, $checkQuery);
    $checkRow    = mysqli_fetch_assoc($checkResult);
    $alreadyCreated = ($checkRow['cnt'] > 0);

    $sr_codes[] = [
        'code'            => $sr_code,
        'label'           => $sr_code,
        'already_created' => $alreadyCreated
    ];
}

echo json_encode(['success' => true, 'sr_codes' => $sr_codes]);