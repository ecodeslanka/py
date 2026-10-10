<?php
include 'config.php';
header('Content-Type: application/json');

$field_summary_id = isset($_POST['field_summary_id']) ? intval($_POST['field_summary_id']) : 0;
$detail_ids_raw    = isset($_POST['detail_ids']) ? trim($_POST['detail_ids']) : '';

if (!$field_summary_id || $detail_ids_raw === '') {
    echo json_encode(['success' => false, 'error' => 'Missing field_summary_id or detail_ids.']);
    exit;
}

/* sanitize to a clean list of positive integers */
$ids = array_filter(array_map('intval', explode(',', $detail_ids_raw)), function ($v) {
    return $v > 0;
});
$ids = array_values(array_unique($ids));

if (empty($ids)) {
    echo json_encode(['success' => false, 'error' => 'No valid row ids supplied.']);
    exit;
}

$id_list = implode(',', $ids);

/* only allow deleting rows that actually belong to this field summary */
$check = mysqli_query($conn,
    "SELECT id FROM field_summary_details
     WHERE field_summary_id = $field_summary_id
       AND id IN ($id_list)");

if (!$check) {
    echo json_encode(['success' => false, 'error' => 'Lookup failed: ' . mysqli_error($conn)]);
    exit;
}

$valid_ids = [];
while ($row = mysqli_fetch_assoc($check)) {
    $valid_ids[] = intval($row['id']);
}

if (empty($valid_ids)) {
    echo json_encode(['success' => false, 'error' => 'None of the selected rows belong to this field summary.']);
    exit;
}

$valid_id_list = implode(',', $valid_ids);

mysqli_begin_transaction($conn);
try {
    /* remove dependent records first to avoid orphaned data */
    mysqli_query($conn, "DELETE FROM invoice_payments  WHERE field_summary_detail_id IN ($valid_id_list)");
    mysqli_query($conn, "DELETE FROM credit_requests    WHERE field_summary_detail_id IN ($valid_id_list)");

    $del = mysqli_query($conn, "DELETE FROM field_summary_details WHERE id IN ($valid_id_list)");
    if (!$del) {
        throw new Exception(mysqli_error($conn));
    }

    $deleted_count = mysqli_affected_rows($conn);
    mysqli_commit($conn);

    echo json_encode([
        'success'       => true,
        'deleted_count' => $deleted_count,
        'deleted_ids'   => $valid_ids,
    ]);
} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'error' => 'Delete failed: ' . $e->getMessage()]);
}
