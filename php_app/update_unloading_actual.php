<?php
error_reporting(0);
ini_set('display_errors', 0);

ob_start();
include 'config.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        die(json_encode(['success' => false, 'message' => 'Invalid request method']));
    }

    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        die(json_encode(['success' => false, 'message' => 'No data received']));
    }

    $payload = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        die(json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]));
    }

    // ── Bulk save ──
    if (isset($payload['bulk']) && is_array($payload['bulk'])) {
        $updated = 0;
        $errors  = 0;

        foreach ($payload['bulk'] as $row) {
            $id          = intval($row['id'] ?? 0);
            $actual_qty  = floatval($row['actual_qty']  ?? 0);
            $short_excess = floatval($row['short_excess'] ?? 0);

            if ($id <= 0) { $errors++; continue; }

            $q = "UPDATE unloading_summary_import_details
                  SET actual_qty   = $actual_qty,
                      short_excess = $short_excess
                  WHERE id = $id";

            if (mysqli_query($conn, $q) && mysqli_affected_rows($conn) >= 0) {
                $updated++;
            } else {
                $errors++;
            }
        }

        echo json_encode([
            'success' => true,
            'updated' => $updated,
            'errors'  => $errors
        ]);
        exit;
    }

    // ── Single row save ──
    $id           = intval($payload['id']           ?? 0);
    $actual_qty   = floatval($payload['actual_qty']   ?? 0);
    $short_excess = floatval($payload['short_excess'] ?? 0);

    if ($id <= 0) {
        die(json_encode(['success' => false, 'message' => 'Invalid record ID']));
    }

    $q = "UPDATE unloading_summary_import_details
          SET actual_qty   = $actual_qty,
              short_excess = $short_excess
          WHERE id = $id";

    if (mysqli_query($conn, $q)) {
        echo json_encode(['success' => true, 'id' => $id]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
} catch (Error $e) {
    echo json_encode(['success' => false, 'message' => 'PHP Error: ' . $e->getMessage()]);
}
exit;
?>
