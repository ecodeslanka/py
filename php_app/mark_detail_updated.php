<?php
error_reporting(0); ini_set('display_errors', 0);
ob_start(); include 'config.php'; ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'error'=>'POST only']); exit;
}

$detail_id       = intval($_POST['detail_id']       ?? 0);
$is_special      = intval($_POST['is_special_credit'] ?? 0);
if (!$detail_id) {
    echo json_encode(['success'=>false,'error'=>'detail_id missing']); exit;
}

/* Add updated column if missing */
$col = mysqli_query($conn,
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='field_summary_details' AND COLUMN_NAME='updated' LIMIT 1");
if ($col && mysqli_num_rows($col) === 0)
    mysqli_query($conn, "ALTER TABLE field_summary_details ADD COLUMN `updated` TINYINT(1) NOT NULL DEFAULT 0");

/* Add is_special_credit column if missing */
$col2 = mysqli_query($conn,
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='field_summary_details' AND COLUMN_NAME='is_special_credit' LIMIT 1");
if ($col2 && mysqli_num_rows($col2) === 0)
    mysqli_query($conn, "ALTER TABLE field_summary_details ADD COLUMN `is_special_credit` TINYINT(1) NOT NULL DEFAULT 0");

$set = "`updated`=1" . ($is_special ? ", `is_special_credit`=1" : "");
if (mysqli_query($conn, "UPDATE field_summary_details SET $set WHERE id=$detail_id")) {
    echo json_encode(['success'=>true]);
} else {
    echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]);
}