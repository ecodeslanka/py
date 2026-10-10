<?php
/**
 * get_sr_codes_by_dp.php
 * Returns distinct SR codes from loading_summary for a given delivery_date + delivery_person.
 * Also checks if a field_summary already exists for each sr_code (already_created flag).
 * GET ?delivery_date=YYYY-MM-DD&delivery_person=NAME
 */
include 'config.php';
header('Content-Type: application/json');

$date = isset($_GET['delivery_date'])  ? trim($_GET['delivery_date'])  : '';
$dp   = isset($_GET['delivery_person'])? trim($_GET['delivery_person']): '';

if (!$date || !$dp) {
    echo json_encode(['success' => false, 'message' => 'delivery_date and delivery_person are required', 'sr_codes' => []]);
    exit;
}

try {
    /* Get all SR codes for this delivery person on this date */
    $stmt = $pdo->prepare("
        SELECT
            ls.sr_code,
            COUNT(ls.id) AS record_count,
            MAX(CASE WHEN fs.id IS NOT NULL THEN 1 ELSE 0 END) AS already_created
        FROM loading_summary ls
        LEFT JOIN field_summary fs
            ON fs.sr_code      = ls.sr_code
            AND fs.delivery_date = ls.delivery_date
        WHERE ls.delivery_date    = ?
          AND ls.delivery_person  = ?
          AND ls.sr_code IS NOT NULL
          AND ls.sr_code != ''
        GROUP BY ls.sr_code
        ORDER BY ls.sr_code ASC
    ");
    $stmt->execute([$date, $dp]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sr_codes = array_map(function($r) {
        return [
            'code'            => $r['sr_code'],
            'label'           => $r['sr_code'],
            'record_count'    => (int)$r['record_count'],
            'already_created' => (bool)$r['already_created'],
        ];
    }, $rows);

    echo json_encode(['success' => true, 'sr_codes' => $sr_codes]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'sr_codes' => []]);
}
