<?php
include 'config.php';

header('Content-Type: application/json');

if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    $sql = "SELECT * FROM vehicles WHERE id = $id";
    $result = mysqli_query($conn, $sql);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $vehicle = mysqli_fetch_assoc($result);
        echo json_encode($vehicle);
    } else {
        echo json_encode(['error' => 'Vehicle not found']);
    }
} else {
    echo json_encode(['error' => 'No ID provided']);
}
?>
