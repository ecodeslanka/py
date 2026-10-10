<?php
include 'config.php';

header('Content-Type: application/json');

if (!isset($_GET['designation_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Designation ID is required']);
    exit;
}

$designation_id = intval($_GET['designation_id']);

$sql = "SELECT d.designation_code, d.designation_name, sc.category_code, sc.category_name 
        FROM designations d 
        LEFT JOIN staff_categories sc ON d.staff_category_id = sc.id 
        WHERE d.id = $designation_id";

$result = mysqli_query($conn, $sql);

if ($result && mysqli_num_rows($result) > 0) {
    $data = mysqli_fetch_assoc($result);
    echo json_encode($data);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Designation not found']);
}
?>
