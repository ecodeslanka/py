<?php
/**
 * get_sr_codes_by_person.php
 *
 * Returns SR codes that belong to a specific delivery_person on a given
 * delivery_date, marking any that already have a field summary created.
 *
 * GET params:
 *   delivery_date    (required)  e.g. 2024-07-15
 *   delivery_person  (required)  e.g. "Alice"
 *
 * Response JSON:
 *   {
 *     success: true,
 *     sr_codes: [
 *       { code: "SR001", label: "SR001 (12 records)", already_created: false },
 *       { code: "SR002", label: "SR002 (5 records)",  already_created: true  },
 *       ...
 *     ]
 *   }
 *   { success: false, message: "..." }
 */

include 'config.php';
header('Content-Type: application/json');

$delivery_date   = isset($_GET['delivery_date'])   ? trim($_GET['delivery_date'])   : '';
$delivery_person = isset($_GET['delivery_person']) ? trim($_GET['delivery_person']) : '';

if (!$delivery_date || !$delivery_person) {
    echo json_encode(['success' => false, 'message' => 'delivery_date and delivery_person are required']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery_date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

$date_esc   = mysqli_real_escape_string($conn, $delivery_date);
$person_esc = mysqli_real_escape_string($conn, $delivery_person);

/*
 * 1. Get all distinct SR codes for this person + date from the import details,
 *    along with a record count.
 * 2. Left-join the field_summaries table to detect which ones are already created.
 *
 * ── Adjust column / table names to match your schema ──
 *    loading_summary_import_details : lsid.sales_person_code  → SR code column
 *    field_summaries                : fs.sr_code, fs.delivery_date
 */
$query = "
    SELECT
        lsid.sales_person_code                          AS sr_code,
        COUNT(lsid.id)                                  AS record_count,
        IF(fs.id IS NOT NULL, 1, 0)                     AS already_created
    FROM loading_summary_import_details AS lsid
    INNER JOIN loading_summary_imports  AS lsi
           ON lsi.id = lsid.import_id
    LEFT JOIN field_summaries           AS fs
           ON  fs.sr_code       = lsid.sales_person_code
           AND fs.delivery_date = '$date_esc'
    WHERE lsi.delivery_date    = '$date_esc'
      AND lsid.delivery_person = '$person_esc'
      AND lsid.sales_person_code IS NOT NULL
      AND lsid.sales_person_code <> ''
      AND lsi.status = 'completed'
    GROUP BY lsid.sales_person_code, fs.id
    ORDER BY lsid.sales_person_code ASC
";

$result = mysqli_query($conn, $query);

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    exit;
}

$sr_codes = [];
while ($row = mysqli_fetch_assoc($result)) {
    $code    = $row['sr_code'];
    $count   = (int)$row['record_count'];
    $created = (bool)$row['already_created'];

    $sr_codes[] = [
        'code'            => $code,
        'label'           => $code . ' (' . $count . ' record' . ($count !== 1 ? 's' : '') . ')',
        'record_count'    => $count,
        'already_created' => $created,
    ];
}

if (count($sr_codes) === 0) {
    echo json_encode([
        'success'  => false,
        'message'  => 'No SR codes found for this delivery person on this date',
        'sr_codes' => []
    ]);
    exit;
}

echo json_encode(['success' => true, 'sr_codes' => $sr_codes]);
