<?php
/**
 * oa_ajax.php — AJAX handler for Other Adjustments
 */

// Must be very first — catches fatal/parse errors and returns them as JSON
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200);
        echo json_encode([
            'ok'  => false,
            'msg' => 'PHP Fatal: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line']
        ]);
    }
});

ob_start();
error_reporting(0);

$config_path = __DIR__ . '/config.php';
if (!file_exists($config_path)) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode(['ok' => false, 'msg' => 'config.php not found at: ' . $config_path]);
    exit;
}

include $config_path;
ob_end_clean();
error_reporting(E_ALL);

// Validate $conn
if (!isset($conn)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode(['ok' => false, 'msg' => '$conn is not set after config.php — check your config file.']);
    exit;
}
if (mysqli_connect_errno()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode(['ok' => false, 'msg' => 'DB connect failed: ' . mysqli_connect_error()]);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

// Ensure tables exist
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS oa_transactions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id    INT NOT NULL,
    invoice_no    VARCHAR(100),
    ledger_row_id INT DEFAULT NULL,
    ledger_ref    VARCHAR(255),
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes         TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS oa_transaction_lines (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    oa_txn_id   INT NOT NULL,
    description VARCHAR(500),
    amount      DECIMAL(15,2),
    line_date   DATE,
    FOREIGN KEY (oa_txn_id) REFERENCES oa_transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = trim($_REQUEST['action'] ?? '');

// ── SAVE ─────────────────────────────────────────────────────────
if ($action === 'save_oa_txn' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    $invoice_no = mysqli_real_escape_string($conn, trim($_POST['invoice_no'] ?? ''));
    $ledger_id  = intval($_POST['ledger_row_id'] ?? 0);
    $ledger_ref = mysqli_real_escape_string($conn, trim($_POST['ledger_ref'] ?? ''));
    $notes      = mysqli_real_escape_string($conn, trim($_POST['notes'] ?? ''));
    $lines      = $_POST['lines'] ?? [];

    if (!$invoice_id) { echo json_encode(['ok' => false, 'msg' => 'Missing invoice_id.']); exit; }
    if (empty($lines)) { echo json_encode(['ok' => false, 'msg' => 'No lines supplied.']); exit; }

    $lid_sql = $ledger_id ? $ledger_id : 'NULL';
    mysqli_query($conn,
        "INSERT INTO oa_transactions (invoice_id, invoice_no, ledger_row_id, ledger_ref, notes)
         VALUES ($invoice_id, '$invoice_no', $lid_sql, '$ledger_ref', '$notes')");

    if (mysqli_error($conn)) {
        echo json_encode(['ok' => false, 'msg' => 'DB error on insert: ' . mysqli_error($conn)]); exit;
    }

    $txn_id = (int) mysqli_insert_id($conn);
    if (!$txn_id) {
        echo json_encode(['ok' => false, 'msg' => 'Insert returned no ID.']); exit;
    }

    $saved = 0;
    foreach ($lines as $line) {
        $amt = floatval($line['amount'] ?? 0);
        if ($amt <= 0) continue;
        $desc  = mysqli_real_escape_string($conn, trim($line['description'] ?? ''));
        $ldate = trim($line['date'] ?? '');
        $ds    = $ldate ? "'" . mysqli_real_escape_string($conn, $ldate) . "'" : 'NULL';
        mysqli_query($conn,
            "INSERT INTO oa_transaction_lines (oa_txn_id, description, amount, line_date)
             VALUES ($txn_id, '$desc', $amt, $ds)");
        if (!mysqli_error($conn)) $saved++;
    }

    if ($saved === 0) {
        mysqli_query($conn, "DELETE FROM oa_transactions WHERE id = $txn_id");
        echo json_encode(['ok' => false, 'msg' => 'All line amounts were 0 — nothing saved.']); exit;
    }

    echo json_encode(['ok' => true, 'msg' => "Saved $saved line(s).", 'txn_id' => $txn_id]);
    exit;
}

// ── DELETE ───────────────────────────────────────────────────────
if ($action === 'delete_oa_txn' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $txn_id = intval($_POST['txn_id'] ?? 0);
    if (!$txn_id) { echo json_encode(['ok' => false, 'msg' => 'Bad or missing txn_id.']); exit; }
    mysqli_query($conn, "DELETE FROM oa_transactions WHERE id = $txn_id");
    $ok = mysqli_affected_rows($conn) > 0;
    echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Deleted.' : 'Transaction not found.']);
    exit;
}

// ── GET ──────────────────────────────────────────────────────────
if ($action === 'get_oa_txns' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $inv_id = intval($_GET['invoice_id'] ?? 0);
    if (!$inv_id) {
        echo json_encode(['ok' => false, 'rows' => [], 'msg' => 'Missing invoice_id.']); exit;
    }

    $res = mysqli_query($conn,
        "SELECT
            t.id,
            t.invoice_no,
            t.ledger_row_id,
            t.ledger_ref,
            t.notes,
            t.created_at,
            (SELECT GROUP_CONCAT(
                CONCAT(l.id,'~~',IFNULL(l.description,''),'~~',l.amount,'~~',IFNULL(l.line_date,''))
                ORDER BY l.id SEPARATOR '||')
             FROM oa_transaction_lines l WHERE l.oa_txn_id = t.id) AS `lines`
         FROM oa_transactions t
         WHERE t.invoice_id = $inv_id
         ORDER BY t.created_at DESC");

    if (!$res) {
        echo json_encode(['ok' => false, 'rows' => [], 'msg' => 'Query failed: ' . mysqli_error($conn)]); exit;
    }

    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    echo json_encode(['ok' => true, 'rows' => $rows]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => "Unknown or missing action: '$action'"]);