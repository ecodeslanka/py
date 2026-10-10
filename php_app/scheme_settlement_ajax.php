<?php
/**
 * scheme_settlement_ajax.php
 * AJAX endpoint — handles settlement payment add / list / delete
 *
 * POST actions:
 *   list   — scheme_no
 *   add    — scheme_no, payment_date, amount, remarks, [document file]
 *   delete — pay_id
 */
error_reporting(0);
ini_set('display_errors', 0);
ini_set('max_execution_time', 60);
ini_set('memory_limit', '128M');

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

/* ── Ensure table ────────────────────────────────────────────────────────── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS scheme_discount_settlements (
    id              INT(11)        AUTO_INCREMENT PRIMARY KEY,
    scheme_no       VARCHAR(100)   NOT NULL,
    payment_date    DATE           NOT NULL,
    amount          DECIMAL(14,2)  NOT NULL DEFAULT 0,
    remarks         TEXT           NULL,
    document_path   VARCHAR(500)   NULL,
    document_name   VARCHAR(255)   NULL,
    created_by      INT(11)        NULL,
    created_at      TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_scheme_no (scheme_no)
)");

$action = trim($_POST['action'] ?? '');

/* ════════════════════════════════════════════════════════════════════════════
   ACTION: list
   ════════════════════════════════════════════════════════════════════════════ */
if ($action === 'list') {
    $scheme_no = trim($_POST['scheme_no'] ?? '');
    if ($scheme_no === '') exit(json_encode(['success' => false, 'message' => 'scheme_no required']));

    $esc = mysqli_real_escape_string($conn, $scheme_no);
    $res = mysqli_query($conn, "SELECT * FROM scheme_discount_settlements WHERE scheme_no = '$esc' ORDER BY payment_date DESC, id DESC");
    $payments = [];
    while ($row = mysqli_fetch_assoc($res)) $payments[] = $row;

    exit(json_encode(['success' => true, 'payments' => $payments]));
}

/* ════════════════════════════════════════════════════════════════════════════
   ACTION: add
   ════════════════════════════════════════════════════════════════════════════ */
if ($action === 'add') {
    $scheme_no    = trim($_POST['scheme_no']     ?? '');
    $payment_date = trim($_POST['payment_date']  ?? '');
    $amount       = floatval($_POST['amount']    ?? 0);
    $remarks      = trim($_POST['remarks']       ?? '');

    if ($scheme_no === '')    exit(json_encode(['success' => false, 'message' => 'scheme_no required']));
    if ($payment_date === '') exit(json_encode(['success' => false, 'message' => 'payment_date required']));
    if ($amount <= 0)         exit(json_encode(['success' => false, 'message' => 'Amount must be greater than zero']));

    /* Validate date */
    $dt = DateTime::createFromFormat('Y-m-d', $payment_date);
    if (!$dt || $dt->format('Y-m-d') !== $payment_date) {
        exit(json_encode(['success' => false, 'message' => 'Invalid payment_date format (Y-m-d required)']));
    }

    /* Handle document upload */
    $doc_path = null;
    $doc_name = null;
    $upload_dir = __DIR__ . '/uploads/scheme_settlements/';
    if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

    if (!empty($_FILES['document']['name'])) {
        $file     = $_FILES['document'];
        $orig_name= basename($file['name']);
        $ext      = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        $allowed  = ['pdf','jpg','jpeg','png','webp'];

        if (!in_array($ext, $allowed)) {
            exit(json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: PDF, JPG, PNG, WEBP']));
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            exit(json_encode(['success' => false, 'message' => 'File too large. Maximum 10 MB allowed']));
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            exit(json_encode(['success' => false, 'message' => 'File upload error code: ' . $file['error']]));
        }

        $safe_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($orig_name, PATHINFO_FILENAME));
        $doc_name  = $safe_name . '_' . time() . '_' . uniqid() . '.' . $ext;
        $doc_path  = $upload_dir . $doc_name;

        if (!move_uploaded_file($file['tmp_name'], $doc_path)) {
            exit(json_encode(['success' => false, 'message' => 'Failed to save uploaded file']));
        }
    }

    /* Insert record */
    $esc_sn   = mysqli_real_escape_string($conn, $scheme_no);
    $esc_pd   = mysqli_real_escape_string($conn, $payment_date);
    $esc_rem  = mysqli_real_escape_string($conn, $remarks);
    $esc_dp   = $doc_path  ? "'" . mysqli_real_escape_string($conn, $doc_path)  . "'" : 'NULL';
    $esc_dn   = $doc_name  ? "'" . mysqli_real_escape_string($conn, $doc_name)  . "'" : 'NULL';
    $user_id  = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 'NULL';

    $q = "INSERT INTO scheme_discount_settlements
              (scheme_no, payment_date, amount, remarks, document_path, document_name, created_by)
          VALUES
              ('$esc_sn', '$esc_pd', $amount, '$esc_rem', $esc_dp, $esc_dn, $user_id)";

    if (!mysqli_query($conn, $q)) {
        /* Clean up uploaded file on DB failure */
        if ($doc_path && file_exists($doc_path)) @unlink($doc_path);
        exit(json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)]));
    }

    exit(json_encode([
        'success'    => true,
        'message'    => 'Payment added successfully',
        'payment_id' => mysqli_insert_id($conn),
    ]));
}

/* ════════════════════════════════════════════════════════════════════════════
   ACTION: delete
   ════════════════════════════════════════════════════════════════════════════ */
if ($action === 'delete') {
    $pay_id = intval($_POST['pay_id'] ?? 0);
    if ($pay_id <= 0) exit(json_encode(['success' => false, 'message' => 'Invalid pay_id']));

    /* Fetch to get document path before deleting */
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM scheme_discount_settlements WHERE id = $pay_id"));
    if (!$row) exit(json_encode(['success' => false, 'message' => 'Payment record not found']));

    if (!mysqli_query($conn, "DELETE FROM scheme_discount_settlements WHERE id = $pay_id")) {
        exit(json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)]));
    }

    /* Delete the associated document file (if any) */
    if (!empty($row['document_path']) && file_exists($row['document_path'])) {
        @unlink($row['document_path']);
    }

    exit(json_encode(['success' => true, 'message' => 'Payment deleted successfully']));
}

/* ── Unknown action ──────────────────────────────────────────────────────── */
exit(json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]));
?>
