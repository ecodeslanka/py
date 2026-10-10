<?php
/**
 * save_scheme_settlement.php
 * AJAX handler for scheme discount settlements.
 *
 * Actions:
 *   action=save   — insert new payment
 *   action=delete — delete a payment by id
 *   action=list   — return payment history for a scheme_no
 */
error_reporting(0);
ini_set('display_errors', 0);

ob_start();
include 'config.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if (!$conn) {
    exit(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

/* ── Ensure settlements table exists ──────────────────────────────────────── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS scheme_discount_settlements (
    id               INT(11)        AUTO_INCREMENT PRIMARY KEY,
    scheme_no        VARCHAR(100)   NOT NULL,
    payment_date     DATE           NOT NULL,
    amount           DECIMAL(14,2)  NOT NULL DEFAULT 0,
    remarks          TEXT           NULL,
    document_path    VARCHAR(500)   NULL,
    document_name    VARCHAR(255)   NULL,
    created_at       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_scheme_no (scheme_no)
)");

/* ── Ensure uploads directory exists ──────────────────────────────────────── */
$upload_dir = __DIR__ . '/uploads/scheme_settlements/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

/* ══════════════════════════════════════════════════════════════════════════ */
/*  LIST                                                                       */
/* ══════════════════════════════════════════════════════════════════════════ */
if ($action === 'list') {
    $scheme_no = mysqli_real_escape_string($conn, trim($_GET['scheme_no'] ?? ''));
    if (empty($scheme_no)) {
        exit(json_encode(['success' => false, 'message' => 'scheme_no required']));
    }
    $res = mysqli_query($conn,
        "SELECT id, scheme_no, payment_date, amount, remarks, document_path, document_name, created_at
         FROM scheme_discount_settlements
         WHERE scheme_no = '$scheme_no'
         ORDER BY payment_date DESC, id DESC");
    $rows = [];
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = $r;
        }
    }
    exit(json_encode(['success' => true, 'data' => $rows]));
}

/* ══════════════════════════════════════════════════════════════════════════ */
/*  SAVE                                                                       */
/* ══════════════════════════════════════════════════════════════════════════ */
if ($action === 'save') {
    $scheme_no   = mysqli_real_escape_string($conn, trim($_POST['scheme_no']   ?? ''));
    $pay_date    = mysqli_real_escape_string($conn, trim($_POST['payment_date']?? ''));
    $amount      = floatval($_POST['amount'] ?? 0);
    $remarks     = mysqli_real_escape_string($conn, trim($_POST['remarks']     ?? ''));

    if (empty($scheme_no))  exit(json_encode(['success' => false, 'message' => 'Scheme No is required']));
    if (empty($pay_date))   exit(json_encode(['success' => false, 'message' => 'Payment date is required']));
    if ($amount <= 0)       exit(json_encode(['success' => false, 'message' => 'Amount must be greater than 0']));

    /* ── Optional document upload ── */
    $doc_path = 'NULL';
    $doc_name = 'NULL';

    if (!empty($_FILES['document']['name'])) {
        $orig     = basename($_FILES['document']['name']);
        $ext      = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $allowed  = ['pdf','jpg','jpeg','png','gif','xlsx','xls','docx','doc'];
        if (!in_array($ext, $allowed)) {
            exit(json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: pdf, jpg, png, xlsx, docx']));
        }
        if ($_FILES['document']['size'] > 10 * 1024 * 1024) {
            exit(json_encode(['success' => false, 'message' => 'File too large (max 10 MB)']));
        }
        $safe_name = time() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $orig);
        $dest      = $upload_dir . $safe_name;
        if (!move_uploaded_file($_FILES['document']['tmp_name'], $dest)) {
            exit(json_encode(['success' => false, 'message' => 'File upload failed']));
        }
        $rel_path = 'uploads/scheme_settlements/' . $safe_name;
        $doc_path = "'" . mysqli_real_escape_string($conn, $rel_path) . "'";
        $doc_name = "'" . mysqli_real_escape_string($conn, $orig) . "'";
    }

    $q = "INSERT INTO scheme_discount_settlements
              (scheme_no, payment_date, amount, remarks, document_path, document_name)
          VALUES
              ('$scheme_no', '$pay_date', $amount, '$remarks', $doc_path, $doc_name)";

    if (!mysqli_query($conn, $q)) {
        exit(json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)]));
    }
    $new_id = mysqli_insert_id($conn);
    exit(json_encode(['success' => true, 'id' => $new_id, 'message' => 'Payment saved successfully']));
}

/* ══════════════════════════════════════════════════════════════════════════ */
/*  DELETE                                                                     */
/* ══════════════════════════════════════════════════════════════════════════ */
if ($action === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) exit(json_encode(['success' => false, 'message' => 'Invalid ID']));

    /* Delete file if exists */
    $res = mysqli_query($conn, "SELECT document_path FROM scheme_discount_settlements WHERE id = $id");
    if ($res && $row = mysqli_fetch_assoc($res)) {
        if (!empty($row['document_path'])) {
            $full = __DIR__ . '/' . $row['document_path'];
            if (file_exists($full)) @unlink($full);
        }
    }

    if (!mysqli_query($conn, "DELETE FROM scheme_discount_settlements WHERE id = $id")) {
        exit(json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)]));
    }
    exit(json_encode(['success' => true, 'message' => 'Payment deleted']));
}

exit(json_encode(['success' => false, 'message' => 'Unknown action']));
?>
