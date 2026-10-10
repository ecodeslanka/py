<?php
/**
 * save_cash_report.php
 * Saves a snapshot of a Cash Collection report to the DB.
 * Called via POST (JSON) from cash_collection.php or cc_collection_summary.php
 */
include 'config.php';

header('Content-Type: application/json');

/* ── ensure the saved-reports table exists ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cash_collection_saved_reports (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    report_name     VARCHAR(255)  NOT NULL,
    report_type     VARCHAR(50)   NOT NULL DEFAULT 'daily',
    date_from       DATE          NOT NULL,
    date_to         DATE          NOT NULL,
    sr_code         VARCHAR(50)   NULL,
    saved_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    saved_by        VARCHAR(100)  NULL,
    total_sinv      DECIMAL(14,2) DEFAULT 0.00,
    total_cc        DECIMAL(14,2) DEFAULT 0.00,
    total_sr        DECIMAL(14,2) DEFAULT 0.00,
    total_coll      DECIMAL(14,2) DEFAULT 0.00,
    total_banked    DECIMAL(14,2) DEFAULT 0.00,
    total_handed    DECIMAL(14,2) DEFAULT 0.00,
    total_short     DECIMAL(14,2) DEFAULT 0.00,
    row_count       INT           DEFAULT 0,
    report_json     LONGTEXT      NOT NULL,
    INDEX idx_dates (date_from, date_to),
    INDEX idx_saved (saved_at),
    INDEX idx_type  (report_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* Add report_type column if it doesn't exist on older installs */
$col = mysqli_query($conn, "SHOW COLUMNS FROM cash_collection_saved_reports LIKE 'report_type'");
if ($col && mysqli_num_rows($col) === 0) {
    mysqli_query($conn, "ALTER TABLE cash_collection_saved_reports
        ADD COLUMN report_type VARCHAR(50) NOT NULL DEFAULT 'daily' AFTER report_name,
        ADD INDEX idx_type (report_type)");
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
    exit;
}

$report_type = mysqli_real_escape_string($conn, $data['report_type'] ?? 'daily');
$date_from   = mysqli_real_escape_string($conn, $data['date_from']   ?? '');
$date_to     = mysqli_real_escape_string($conn, $data['date_to']     ?? '');
$sr_code     = isset($data['sr_code']) && $data['sr_code'] !== ''
               ? "'" . mysqli_real_escape_string($conn, $data['sr_code']) . "'"
               : 'NULL';
$report_name = mysqli_real_escape_string($conn, $data['report_name'] ?? ('Cash Report ' . date('d M Y H:i')));
$saved_by    = mysqli_real_escape_string($conn, $data['saved_by'] ?? '');

$totals    = $data['totals'] ?? [];
$rows      = $data['rows']   ?? [];
$row_count = count($rows);

$t_sinv   = floatval($totals['sinv']             ?? 0);
$t_cc     = floatval($totals['cc_total']         ?? 0);
$t_sr     = floatval($totals['sr_total']         ?? 0);
$t_coll   = floatval($totals['total_coll']       ?? 0);
$t_banked = floatval(($totals['banked_cc'] ?? 0) + ($totals['banked_sr'] ?? 0));
$t_handed = floatval(($totals['handed_cc'] ?? 0) + ($totals['handed_sr'] ?? 0));
$t_short  = floatval($totals['new_short_excess'] ?? 0);

$report_json = mysqli_real_escape_string($conn, json_encode([
    'meta' => [
        'report_type' => $data['report_type'] ?? 'daily',
        'date_from'   => $data['date_from'],
        'date_to'     => $data['date_to'],
        'sr_code'     => $data['sr_code'] ?? null,
        'saved_at'    => date('Y-m-d H:i:s'),
        'saved_by'    => $data['saved_by'] ?? null,
    ],
    'totals' => $totals,
    'rows'   => $rows,
]));

$saved_by_sql = $saved_by ? "'$saved_by'" : 'NULL';

$sql = "INSERT INTO cash_collection_saved_reports
    (report_name, report_type, date_from, date_to, sr_code, saved_by,
     total_sinv, total_cc, total_sr, total_coll, total_banked, total_handed, total_short,
     row_count, report_json)
    VALUES
    ('$report_name', '$report_type', '$date_from', '$date_to', $sr_code, $saved_by_sql,
     $t_sinv, $t_cc, $t_sr, $t_coll, $t_banked, $t_handed, $t_short,
     $row_count, '$report_json')";

if (mysqli_query($conn, $sql)) {
    echo json_encode(['success' => true, 'report_id' => mysqli_insert_id($conn), 'message' => 'Report saved successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)]);
}