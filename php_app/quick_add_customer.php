<?php
// Suppress errors to keep JSON clean
error_reporting(0);
ini_set('display_errors', 0);

ob_start();
include 'config.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$t_code    = trim($_POST['t_code']    ?? '');
$shop_name = trim($_POST['shop_name'] ?? '');

if (!$t_code || !$shop_name) {
    echo json_encode(['success' => false, 'message' => 'T-Code and Shop Name are required']);
    exit;
}

$t_code_esc    = mysqli_real_escape_string($conn, $t_code);
$shop_name_esc = mysqli_real_escape_string($conn, $shop_name);

// Check if T-Code already exists
$check = mysqli_query($conn, "SELECT id FROM customers WHERE t_code = '$t_code_esc' LIMIT 1");
if ($check && mysqli_num_rows($check) > 0) {
    echo json_encode(['success' => false, 'message' => "T-Code '$t_code' already exists in customers table."]);
    exit;
}

// Insert customer with active = 0 (pending approval)
$sql = "INSERT INTO customers (t_code, shop_name, active) VALUES ('$t_code_esc', '$shop_name_esc', 0)";

if (mysqli_query($conn, $sql)) {
    echo json_encode([
        'success'    => true,
        'message'    => 'Customer added successfully. Pending approval.',
        'customer_id' => mysqli_insert_id($conn),
        't_code'     => $t_code,
        'shop_name'  => $shop_name
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
}