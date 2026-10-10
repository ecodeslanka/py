<?php
/**
 * get_delivery_persons_by_sr.php
 *
 * Returns delivery persons who handled a specific SR code on a specific date.
 *
 * GET params:
 *   delivery_date  — YYYY-MM-DD
 *   sr_code        — e.g. "SR001"
 *
 * Response:
 *   { success: true,  persons: [ { name, record_count }, … ] }
 *   { success: false, message: "…" }
 */

include 'config.php';
header('Content-Type: application/json');

$delivery_date = isset($_GET['delivery_date']) ? trim($_GET['delivery_date']) : '';
$sr_code       = isset($_GET['sr_code'])       ? trim($_GET['sr_code'])       : '';

if (!$delivery_date || !$sr_code) {
    echo json_encode(['success' => false, 'message' => 'delivery_date and sr_code are required']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery_date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

$safe_date = mysqli_real_escape_string($conn, $delivery_date);
$safe_sr   = mysqli_real_escape_string($conn, $sr_code);

/*
 * Adjust column names to match your schema:
 *   - delivery_person  : column holding the delivery person name
 *   - sales_person_code: column holding the SR/sales code
 *
 * Option A — delivery_person is in loading_summary_import_details
 */
$query = "
    SELECT
        d.delivery_person        AS name,
        COUNT(d.id)              AS record_count
    FROM loading_summary_import_details d
    INNER JOIN loading_summary_imports i ON i.id = d.import_id
    WHERE i.delivery_date      = '$safe_date'
      AND d.sales_person_code  = '$safe_sr'
      AND d.delivery_person IS NOT NULL
      AND d.delivery_person <> ''
      AND d.status = 'imported'
    GROUP BY d.delivery_person
    ORDER BY d.delivery_person ASC
";

/*
 * Option B — delivery_person is in loading_summary_imports
 *
$query = "
    SELECT
        i.delivery_person        AS name,
        COUNT(d.id)              AS record_count
    FROM loading_summary_imports i
    INNER JOIN loading_summary_import_details d ON d.import_id = i.id
    WHERE i.delivery_date      = '$safe_date'
      AND d.sales_person_code  = '$safe_sr'
      AND i.delivery_person IS NOT NULL
      AND i.delivery_person <> ''
      AND d.status = 'imported'
    GROUP BY i.delivery_person
    ORDER BY i.delivery_person ASC
";
*/

$result = mysqli_query($conn, $query);

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)]);
    exit;
}

$persons = [];
while ($row = mysqli_fetch_assoc($result)) {
    $persons[] = [
        'name'         => $row['name'],
        'record_count' => (int) $row['record_count']
    ];
}

echo json_encode(['success' => true, 'persons' => $persons]);
