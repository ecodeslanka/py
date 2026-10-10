<?php
/**
 * mark_to_be_delivery.php
 * Sets to_be_delivery = 1 on a specific field_summary_details row.
 *
 * POST params:
 *   detail_id       INT  — field_summary_details.id
 *   to_be_delivery  INT  — 1 to flag, 0 to unflag (default 1)
 *
 * Returns: { success: bool, error?: string }
 */
include 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

$detail_id      = intval($_POST['detail_id']      ?? 0);
$to_be_delivery = intval($_POST['to_be_delivery'] ?? 1);

if (!$detail_id) {
    echo json_encode(['success' => false, 'error' => 'detail_id missing']);
    exit;
}

/* Ensure column exists */
$col = mysqli_query($conn, "SHOW COLUMNS FROM field_summary_details LIKE 'to_be_delivery'");
if ($col && mysqli_num_rows($col) === 0) {
    mysqli_query($conn, "ALTER TABLE field_summary_details ADD COLUMN to_be_delivery TINYINT(1) NOT NULL DEFAULT 0");
}

$val = $to_be_delivery ? 1 : 0;
$ok  = mysqli_query($conn, "UPDATE field_summary_details SET to_be_delivery = $val WHERE id = $detail_id");

if (!$ok) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}

echo json_encode(['success' => true, 'detail_id' => $detail_id, 'to_be_delivery' => $val]);
