<?php
include 'config.php';
header('Content-Type: application/json');

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing id']);
    exit;
}

$ok = mysqli_query($conn, "DELETE FROM se_charges WHERE id = $id");

if (!$ok) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}

echo json_encode(['success' => true]);
