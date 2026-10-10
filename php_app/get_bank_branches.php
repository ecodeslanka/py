<?php
include 'config.php';

header('Content-Type: application/json');

if (isset($_GET['bank_code'])) {
    $bank_code = mysqli_real_escape_string($conn, $_GET['bank_code']);
    
    $sql = "SELECT id, branch_code, branch_name FROM bank_branches 
            WHERE bank_code = '$bank_code' AND active = 1 
            ORDER BY branch_name ASC";
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
