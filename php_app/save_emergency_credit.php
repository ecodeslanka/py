<?php
/**
 * save_emergency_credit.php
 * Emergency credit is NOT a payment — never touches invoice_payments.
 * Saves to credit_requests (credit_amount = current balance owed).
 * Documents saved to credit_documents.
 * Returns JSON: {success, credit_request_id, credit_amount, new_balance, invoice_amt}
 */
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
include 'config.php';
ob_end_clean();   /* kill any BOM / warning output from config before JSON header */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'error'=>'POST only']); exit;
}

/* ═══════════ ENSURE TABLES ═══════════ */

/* invoice_payments must exist so we can read paid totals for balance calc */
mysqli_query($conn,"CREATE TABLE IF NOT EXISTS invoice_payments (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  field_summary_id        INT           NOT NULL,
  field_summary_detail_id INT           NOT NULL,
  t_code                  VARCHAR(50)   NULL,
  invoice_num             VARCHAR(100)  NULL,
  payment_method          VARCHAR(20)   NOT NULL DEFAULT 'cash',
  payment_date            DATE          NULL,
  amount                  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount_to_bank          DECIMAL(12,2) DEFAULT 0.00,
  reference_no            VARCHAR(100)  NULL,
  collected_by            VARCHAR(50)   NULL,
  cheque_mode             VARCHAR(50)   NULL,
  remarks                 TEXT          NULL,
  created_at              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_fs  (field_summary_id),
  INDEX idx_det (field_summary_detail_id),
  INDEX idx_inv (invoice_num)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn,"CREATE TABLE IF NOT EXISTS credit_requests (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  field_summary_id        INT           NOT NULL,
  field_summary_detail_id INT           NOT NULL,
  t_code                  VARCHAR(50)   NULL,
  invoice_num             VARCHAR(100)  NULL,
  credit_amount           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  reason                  TEXT          NULL,
  status                  VARCHAR(30)   DEFAULT 'pending',
  approved_by             VARCHAR(100)  NULL,
  approved_at             DATETIME      NULL,
  created_at              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_fs  (field_summary_id),
  INDEX idx_det (field_summary_detail_id),
  INDEX idx_inv (invoice_num)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn,"CREATE TABLE IF NOT EXISTS credit_documents (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  credit_request_id INT           NOT NULL,
  original_name     VARCHAR(255)  NOT NULL,
  stored_name       VARCHAR(255)  NOT NULL,
  file_path         VARCHAR(500)  NOT NULL,
  file_type         VARCHAR(100)  NULL,
  file_size         INT           NULL,
  uploaded_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (credit_request_id) REFERENCES credit_requests(id) ON DELETE CASCADE,
  INDEX idx_crid (credit_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ═══════════ HELPERS ═══════════ */
function esc($c,$v){ return mysqli_real_escape_string($c, trim((string)($v??''))); }

/* ═══════════ INPUTS ═══════════ */
$fsid   = intval($_POST['field_summary_id']        ?? 0);
$detid  = intval($_POST['field_summary_detail_id'] ?? 0);
$tcode  = esc($conn, $_POST['t_code']              ?? '');
$invnum = esc($conn, $_POST['invoice_num']         ?? '');
$reason = esc($conn, $_POST['reason']              ?? '');
$credit_bill_no = esc($conn, $_POST['credit_bill_no'] ?? '');



/* ═══════════ VALIDATE ═══════════ */
if (!$fsid || !$detid) {
    echo json_encode(['success'=>false,'error'=>'field_summary_id or detail_id missing']); exit;
}
if (!$reason) {
    echo json_encode(['success'=>false,'error'=>'Reason is required for emergency credit']); exit;
}


/* ═══════════ COMPUTE CREDIT AMOUNT = CURRENT BALANCE ═══════════ */
$dr      = mysqli_query($conn,"SELECT adjust_net_value FROM field_summary_details WHERE id=$detid LIMIT 1");
$drow    = $dr ? mysqli_fetch_assoc($dr) : null;
$inv_amt = $drow ? round(floatval($drow['adjust_net_value']),2) : 0.00;

$pr        = mysqli_query($conn,"SELECT COALESCE(SUM(amount),0) AS p FROM invoice_payments WHERE field_summary_detail_id=$detid");
$pr_row    = $pr ? mysqli_fetch_assoc($pr) : null;
$prev_paid = round(floatval($pr_row['p'] ?? 0), 2);
$balance   = max(0.00, $inv_amt - $prev_paid);  /* credit_amount = what is still owed */

/* ═══════════ INSERT CREDIT REQUEST ═══════════ */
$sql = "INSERT INTO credit_requests
  (field_summary_id, field_summary_detail_id, t_code, invoice_num, credit_amount, reason, credit_bill_no, status)
  VALUES ($fsid, $detid, '$tcode', '$invnum', $balance, '$reason', '$credit_bill_no', 'pending')";

if (!mysqli_query($conn,$sql)) {
    echo json_encode(['success'=>false,'error'=>'DB error: '.mysqli_error($conn)]); exit;
}
$credit_request_id = mysqli_insert_id($conn);

/* ═══════════ FILE UPLOADS → credit_documents ═══════════ */
$uploaded_files = 0;
if (!empty($_FILES['documents']['name'][0]) && $credit_request_id) {
    $uploadDir = 'uploads/credit_docs/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $allowed = ['image/jpeg','image/png','image/gif','application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

    $names = (array)($_FILES['documents']['name']     ?? []);
    $tmps  = (array)($_FILES['documents']['tmp_name'] ?? []);
    $types = (array)($_FILES['documents']['type']     ?? []);
    $sizes = (array)($_FILES['documents']['size']     ?? []);
    $errs  = (array)($_FILES['documents']['error']    ?? []);

    foreach ($names as $fi => $origName) {
        if (($errs[$fi] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        if (!in_array($types[$fi] ?? '', $allowed))               continue;
        if (($sizes[$fi] ?? 0) > 10 * 1024 * 1024)               continue;

        $ext    = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $stored = 'cr_'.$credit_request_id.'_'.time().'_'.$fi.'.'.$ext;
        $dest   = $uploadDir.$stored;

        if (move_uploaded_file($tmps[$fi], $dest)) {
            $oN = esc($conn, basename($origName));
            $sN = esc($conn, $stored);
            $fP = esc($conn, $dest);
            $fT = esc($conn, $types[$fi]);
            $fS = intval($sizes[$fi]);
            mysqli_query($conn,"INSERT INTO credit_documents
              (credit_request_id, original_name, stored_name, file_path, file_type, file_size)
              VALUES ($credit_request_id, '$oN', '$sN', '$fP', '$fT', $fS)");
            $uploaded_files++;
        }
    }
}

echo json_encode([
    'success'           => true,
    'credit_request_id' => $credit_request_id,
    'credit_amount'     => $balance,
    'invoice_amt'       => $inv_amt,
    'prev_paid'         => $prev_paid,
    'new_balance'       => $balance,   /* unchanged — not a payment */
    'uploaded_files'    => $uploaded_files,
]);