<?php
include 'config.php';
header('Content-Type: application/json');

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing charge id']);
    exit;
}

/* ── safety net: ensure the link columns exist ── */
$chk2 = mysqli_query($conn, "SHOW COLUMNS FROM se_charges LIKE 'recreate_linked_fs_id'");
if (!$chk2 || mysqli_num_rows($chk2) === 0) {
    mysqli_query($conn, "ALTER TABLE se_charges ADD COLUMN recreate_linked_fs_id INT NULL DEFAULT NULL AFTER recreate_invoice_settled");
}
$chk3 = mysqli_query($conn, "SHOW COLUMNS FROM se_charges LIKE 'recreate_linked_detail_id'");
if (!$chk3 || mysqli_num_rows($chk3) === 0) {
    mysqli_query($conn, "ALTER TABLE se_charges ADD COLUMN recreate_linked_detail_id INT NULL DEFAULT NULL AFTER recreate_linked_fs_id");
}

/* only allow unlinking charges that are actually marked for recreate-invoice */
$check = mysqli_query($conn, "SELECT id FROM se_charges WHERE id = $id AND mark_recreate_invoice = 1");
if (!$check || mysqli_num_rows($check) === 0) {
    echo json_encode(['success' => false, 'error' => 'Charge not found or not marked for recreate invoice']);
    exit;
}

$res = mysqli_query($conn,
    "UPDATE se_charges
     SET recreate_linked_fs_id = NULL,
         recreate_linked_detail_id = NULL,
         recreate_invoice_settled = 0
     WHERE id = $id");

if ($res) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
}
