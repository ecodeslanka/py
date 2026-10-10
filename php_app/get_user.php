<?php
include 'config.php';

header('Content-Type: application/json');

if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    // Get user data
    $user_sql = "SELECT * FROM users WHERE id = $id";
    $user_result = mysqli_query($conn, $user_sql);
    $user = mysqli_fetch_assoc($user_result);
    
    // Get user's companies
    $companies_sql = "SELECT company_id FROM user_companies WHERE user_id = $id";
    $companies_result = mysqli_query($conn, $companies_sql);
    
    $companies = [];
    while ($company = mysqli_fetch_assoc($companies_result)) {
        $companies[] = $company['company_id'];
    }
    
    // Get user's branches
    $branches_sql = "SELECT branch_id FROM user_branches WHERE user_id = $id";
    $branches_result = mysqli_query($conn, $branches_sql);
    
    $branches = [];
    while ($branch = mysqli_fetch_assoc($branches_result)) {
        $branches[] = $branch['branch_id'];
    }
    
    echo json_encode([
        'user' => $user,
        'companies' => $companies,
        'branches' => $branches
    ]);
} else {
    echo json_encode(['error' => 'No ID provided']);
}
?>