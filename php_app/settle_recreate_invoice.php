<?php
include 'config.php';
header('Content-Type: application/json');

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing charge id']);
    exit;
}

/* ── safety net: ensure the column exists (in case this endpoint runs
     before edit_field_summary.php has had a chance to create it) ── */
$chk = mysqli_query($conn, "SHOW COLUMNS FROM se_charges LIKE 'recreate_invoice_settled'");
if ($chk && mysqli_num_rows($chk) === 0) {
    mysqli_query($conn, "ALTER TABLE se_charges ADD COLUMN recreate_invoice_settled TINYINT(1) NOT NULL DEFAULT 0 AFTER mark_recreate_invoice");
}

/* only allow settling charges that are actually marked for recreate-invoice */
$check = mysqli_query($conn, "SELECT id FROM se_charges WHERE id = $id AND mark_recreate_invoice = 1");
if (!$check || mysqli_num_rows($check) === 0) {
    echo json_encode(['success' => false, 'error' => 'Charge not found or not marked for recreate invoice']);
    exit;
}

$res = mysqli_query($conn, "UPDATE se_charges SET recreate_invoice_settled = 1 WHERE id = $id");
if ($res) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
}
