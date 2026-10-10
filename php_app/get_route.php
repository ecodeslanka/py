<?php
include 'config.php';

header('Content-Type: application/json');

if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    // Get route data
    $route_sql = "SELECT * FROM routes WHERE id = $id";
    $route_result = mysqli_query($conn, $route_sql);
    
    if ($route_result && mysqli_num_rows($route_result) > 0) {
        $route = mysqli_fetch_assoc($route_result);
        echo json_encode($route);
    } else {
        echo json_encode(['error' => 'Route not found']);
    }
} else {
    echo json_encode(['error' => 'No ID provided']);
}
?>