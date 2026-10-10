<?php
/**
 * customer_claim_ajax.php
 * AJAX handler for customer claim certificate actions
 *
 * POST actions:
 *   delete_import — import_id  (deletes batch + all detail rows)
 */
error_reporting(0);
ini_set('display_errors', 0);

ob_start();
include 'config.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit(json_encode(['success' => false, 'message' => 'Invalid request method']));
}
if (!isset($conn) || !$conn) {
    exit(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

$action = trim($_POST['action'] ?? '');

/* ── Delete import batch ─────────────────────────────────────────────────── */
if ($action === 'delete_import') {
    $import_id = intval($_POST['import_id'] ?? 0);
    if ($import_id <= 0) {
        exit(json_encode(['success' => false, 'message' => 'Invalid import_id']));
    }

    // Verify exists
    $chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM customer_claim_imports WHERE id = $import_id"));
    if (!$chk) {
        exit(json_encode(['success' => false, 'message' => 'Import batch not found']));
    }

    // Delete detail rows first
    if (!mysqli_query($conn, "DELETE FROM customer_claim_certificates WHERE import_id = $import_id")) {
        exit(json_encode(['success' => false, 'message' => 'Failed to delete records: ' . mysqli_error($conn)]));
    }

    // Delete parent
    if (!mysqli_query($conn, "DELETE FROM customer_claim_imports WHERE id = $import_id")) {
        exit(json_encode(['success' => false, 'message' => 'Failed to delete import batch: ' . mysqli_error($conn)]));
    }

    exit(json_encode(['success' => true, 'message' => 'Import batch #' . $import_id . ' deleted successfully']));
}

exit(json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]));
?>
