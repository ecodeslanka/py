<?php
include 'config.php';
header('Content-Type: application/json');

$t_code = isset($_GET['t_code']) ? mysqli_real_escape_string($conn, $_GET['t_code']) : '';

if (!$t_code) {
    echo json_encode(['error' => 'No t_code provided']);
    exit;
}

$result = mysqli_query($conn,
    "SELECT credit_limit, credit_days, special_credit_policy_days, payment_mode
     FROM customers
     WHERE t_code = '$t_code'
     LIMIT 1"
);

if (!$result || mysqli_num_rows($result) === 0) {
    echo json_encode(['error' => 'Customer not found']);
    exit;
}

echo json_encode(mysqli_fetch_assoc($result));
