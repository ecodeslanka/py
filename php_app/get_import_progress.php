<?php
// Suppress all error output to prevent HTML in JSON response
error_reporting(0);
ini_set('display_errors', 0);

// Prevent any output before JSON
ob_start();

include 'config.php';

// Clear any output buffer
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Set JSON header
header('Content-Type: application/json');

// Get import ID
$import_id = isset($_GET['import_id']) ? intval($_GET['import_id']) : 0;

if (!$import_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid import ID']);
    exit;
}

// Get import progress
$query = "SELECT total_records, imported_records, failed_records, status 
          FROM loading_summary_imports 
          WHERE id = $import_id";

$result = @mysqli_query($conn, $query);

if (!$result || mysqli_num_rows($result) === 0) {
    echo json_encode(['success' => false, 'message' => 'Import not found']);
    exit;
}

$import = mysqli_fetch_assoc($result);

// Return progress
echo json_encode([
    'success' => true,
    'progress' => [
        'total' => (int)$import['total_records'],
        'imported' => (int)$import['imported_records'],
        'failed' => (int)$import['failed_records'],
        'status' => $import['status']
    ]
], JSON_UNESCAPED_UNICODE);

exit;
?>