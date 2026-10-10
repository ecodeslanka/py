<?php
include 'config.php';

header('Content-Type: application/json');

if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    // Get role data
    $role_sql = "SELECT * FROM roles WHERE id = $id";
    $role_result = mysqli_query($conn, $role_sql);
    $role = mysqli_fetch_assoc($role_result);
    
    // Get permissions
    $perm_sql = "SELECT * FROM permissions WHERE role_id = $id";
    $perm_result = mysqli_query($conn, $perm_sql);
    
    $permissions = [];
    while ($perm = mysqli_fetch_assoc($perm_result)) {
        $permissions[] = $perm;
    }
    
    echo json_encode([
        'role' => $role,
        'permissions' => $permissions
    ]);
} else {
    echo json_encode(['error' => 'No ID provided']);
}
?>