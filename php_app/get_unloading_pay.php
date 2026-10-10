<?php
error_reporting(0); ini_set('display_errors', 0);
ob_start(); include 'config.php'; ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

$detail_id = intval($_GET['detail_id'] ?? 0);
if ($detail_id <= 0) {
    echo json_encode(['success'=>false,'message'=>'Invalid ID']);
    exit;
}

// Check table exists first
$check = mysqli_query($conn, "SHOW TABLES LIKE 'unloading_pay_transactions'");
if (!$check || mysqli_num_rows($check) === 0) {
    echo json_encode(['success'=>true,'transactions'=>[]]);
    exit;
}

$res = mysqli_query($conn,
    "SELECT entry_type, employee_id, employee_name, amount, se_value
     FROM unloading_pay_transactions
     WHERE import_detail_id = $detail_id
       AND entry_type IN ('charge','absorb')
     ORDER BY id ASC"
);

$rows = [];
if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;

echo json_encode(['success'=>true,'transactions'=>$rows]);
