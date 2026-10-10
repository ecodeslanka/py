<?php
include 'config.php';

header('Content-Type: application/json');

if (isset($_GET['company_ids'])) {
    $company_ids = explode(',', $_GET['company_ids']);
    $company_ids = array_map('intval', $company_ids);
    $company_ids_str = implode(',', $company_ids);
    
    if (!empty($company_ids_str)) {
        $sql = "SELECT b.id, b.branch_code, b.branch_name, c.company_code 
                FROM branches b
                JOIN companies c ON b.company_id = c.id
                WHERE b.company_id IN ($company_ids_str) AND b.active = 1 
                ORDER BY c.company_code, b.branch_name ASC";
        $result = mysqli_query($conn, $sql);
        
        $branches = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $branches[] = $row;
        }
        
        echo json_encode($branches);
    } else {
        echo json_encode([]);
    }
} elseif (isset($_GET['company_id'])) {
    // Backward compatibility for single company
    $company_id = intval($_GET['company_id']);
    
    $sql = "SELECT id, branch_code, branch_name FROM branches WHERE company_id = $company_id AND active = 1 ORDER BY branch_name ASC";
    $result = mysqli_query($conn, $sql);
    
    $branches = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $branches[] = $row;
    }
    
    echo json_encode($branches);
} else {
    echo json_encode([]);
}
?>