<?php
/**
 * save_tbd_group.php
 * Marks selected field_summary_details rows as "To Be Delivery"
 * and assigns them a group number + delivery date.
 *
 * If a group already exists for the same field_summary_id + tbd_date,
 * the rows are added to that existing group (no new group created).
 *
 * POST params:
 *   field_summary_id  INT
 *   tbd_date          DATE  (YYYY-MM-DD)
 *   detail_ids        CSV   (comma-separated detail IDs)
 *
 * Response: JSON { success, group_no, moved_count } | { success:false, error }
 */
include 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$field_summary_id = intval($_POST['field_summary_id'] ?? 0);
$tbd_date         = trim($_POST['tbd_date'] ?? '');
$detail_ids_raw   = trim($_POST['detail_ids'] ?? '');

if (!$field_summary_id || !$tbd_date || !$detail_ids_raw) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

/* Validate date */
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tbd_date)) {
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit;
}

/* Sanitise detail IDs — integers only */
$ids = array_filter(array_map('intval', explode(',', $detail_ids_raw)));
if (empty($ids)) {
    echo json_encode(['success' => false, 'error' => 'No valid detail IDs provided']);
    exit;
}

/* ── ensure columns exist ── */
$checks = [
    'to_be_delivery'          => "ALTER TABLE field_summary_details ADD COLUMN to_be_delivery TINYINT(1) NOT NULL DEFAULT 0",
    'to_be_delivery_date'     => "ALTER TABLE field_summary_details ADD COLUMN to_be_delivery_date DATE NULL DEFAULT NULL",
    'to_be_delivery_group_no' => "ALTER TABLE field_summary_details ADD COLUMN to_be_delivery_group_no VARCHAR(50) NULL DEFAULT NULL",
];
foreach ($checks as $col => $sql) {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM field_summary_details LIKE '$col'");
    if ($r && mysqli_num_rows($r) === 0) {
        mysqli_query($conn, $sql);
    }
}

/* ── Verify rows belong to this field_summary ── */
$ids_csv = implode(',', $ids);
$valid_q = mysqli_query($conn,
    "SELECT id FROM field_summary_details
     WHERE id IN ($ids_csv) AND field_summary_id = $field_summary_id"
);
if (!$valid_q || mysqli_num_rows($valid_q) === 0) {
    echo json_encode(['success' => false, 'error' => 'No matching rows found for this field summary']);
    exit;
}
$valid_ids = [];
while ($vr = mysqli_fetch_assoc($valid_q)) $valid_ids[] = intval($vr['id']);
if (empty($valid_ids)) {
    echo json_encode(['success' => false, 'error' => 'No valid rows to update']);
    exit;
}
$valid_ids_csv = implode(',', $valid_ids);
$escaped_date  = mysqli_real_escape_string($conn, $tbd_date);

/* ── Determine group number ──
   If an existing group already exists for this field_summary + tbd_date
   (on rows OTHER than the ones being moved), reuse that group.
   Otherwise generate a new group: TBD-{FS_ID}-{YYYYMMDD}-{N}
*/
$existing_grp_q = mysqli_query($conn,
    "SELECT to_be_delivery_group_no
     FROM field_summary_details
     WHERE field_summary_id = $field_summary_id
       AND to_be_delivery = 1
       AND to_be_delivery_date = '$escaped_date'
       AND to_be_delivery_group_no IS NOT NULL
       AND to_be_delivery_group_no != ''
       AND id NOT IN ($valid_ids_csv)
     ORDER BY id ASC
     LIMIT 1"
);

if ($existing_grp_q && mysqli_num_rows($existing_grp_q) > 0) {
    /* ── Same date already has a group — add rows to it ── */
    $existing_row = mysqli_fetch_assoc($existing_grp_q);
    $group_no     = $existing_row['to_be_delivery_group_no'];
} else {
    /* ── New date — generate a new group number ── */
    $date_compact  = str_replace('-', '', $tbd_date);
    $existing_cnt  = mysqli_query($conn,
        "SELECT COUNT(DISTINCT to_be_delivery_group_no) as cnt
         FROM field_summary_details
         WHERE field_summary_id = $field_summary_id
           AND to_be_delivery = 1
           AND to_be_delivery_date = '$escaped_date'
           AND to_be_delivery_group_no IS NOT NULL
           AND to_be_delivery_group_no != ''"
    );
    $grp_n = 1;
    if ($existing_cnt) {
        $cntrow = mysqli_fetch_assoc($existing_cnt);
        $grp_n  = intval($cntrow['cnt']) + 1;
    }
    $group_no = 'TBD-' . $field_summary_id . '-' . $date_compact . '-' . $grp_n;
}

/* ── Update rows ── */
$update_sql = "UPDATE field_summary_details
               SET to_be_delivery = 1,
                   to_be_delivery_date = '$escaped_date',
                   to_be_delivery_group_no = '" . mysqli_real_escape_string($conn, $group_no) . "'
               WHERE id IN ($valid_ids_csv) AND field_summary_id = $field_summary_id";

if (!mysqli_query($conn, $update_sql)) {
    echo json_encode(['success' => false, 'error' => 'DB update failed: ' . mysqli_error($conn)]);
    exit;
}

$moved_count = mysqli_affected_rows($conn);

echo json_encode([
    'success'     => true,
    'group_no'    => $group_no,
    'moved_count' => $moved_count,
    'tbd_date'    => $tbd_date,
]);