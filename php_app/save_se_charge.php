<?php
include 'config.php';
header('Content-Type: application/json');

/* ── ensure table exists (safety net, in case this endpoint is hit before fs_se.php ever ran) ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS se_charges (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  field_summary_id        INT           NOT NULL,
  field_summary_detail_id INT           NOT NULL,
  reason                  VARCHAR(100)  NOT NULL,
  employee_id             INT           NULL,
  employee_code           VARCHAR(50)   NULL,
  employee_name           VARCHAR(255)  NULL,
  employee_role           VARCHAR(150)  NULL,
  amount_employee         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount_company          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  remarks                 TEXT          NULL,
  mark_recreate_invoice   TINYINT(1)    NOT NULL DEFAULT 0,
  created_at              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_fs  (field_summary_id),
  INDEX idx_det (field_summary_detail_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── safety net: add mark_recreate_invoice to an already-existing older table ── */
$_mri_check = mysqli_query($conn, "SHOW COLUMNS FROM se_charges LIKE 'mark_recreate_invoice'");
if ($_mri_check && mysqli_num_rows($_mri_check) === 0) {
    mysqli_query($conn, "ALTER TABLE se_charges ADD COLUMN mark_recreate_invoice TINYINT(1) NOT NULL DEFAULT 0 AFTER remarks");
}

$id                      = isset($_POST['id']) ? intval($_POST['id']) : 0;
$field_summary_id        = intval($_POST['field_summary_id'] ?? 0);
$field_summary_detail_id = intval($_POST['field_summary_detail_id'] ?? 0);
$reason                  = trim($_POST['reason'] ?? '');
$employee_id_raw         = trim($_POST['employee_id'] ?? '');
$employee_code           = trim($_POST['employee_code'] ?? '');
$employee_name           = trim($_POST['employee_name'] ?? '');
$employee_role           = trim($_POST['employee_role'] ?? '');
$amount_employee         = isset($_POST['amount_employee']) ? floatval($_POST['amount_employee']) : 0.0;
$amount_company          = isset($_POST['amount_company'])  ? floatval($_POST['amount_company'])  : 0.0;
$remarks                 = trim($_POST['remarks'] ?? '');
$mark_recreate_invoice   = (isset($_POST['mark_recreate_invoice']) && intval($_POST['mark_recreate_invoice']) === 1) ? 1 : 0;

/* ── validation ── */
if (!$field_summary_detail_id) {
    echo json_encode(['success' => false, 'error' => 'Missing field_summary_detail_id']);
    exit;
}
if ($reason === '') {
    echo json_encode(['success' => false, 'error' => 'Reason is required']);
    exit;
}
if ($amount_employee <= 0 && $amount_company <= 0) {
    $is_co_mistake_recreate_only = ($reason === 'CO Mistake' && $mark_recreate_invoice === 1);
    if (!$is_co_mistake_recreate_only) {
        echo json_encode(['success' => false, 'error' => 'Enter at least one amount (charged to employee or absorbed by company), or tick "Mark to Recreate Invoice" for a CO Mistake']);
        exit;
    }
}
$needs_employee = in_array($reason, ['Permanent posting', 'Discount Adjustment'], true);
if ($needs_employee && $employee_id_raw === '') {
    echo json_encode(['success' => false, 'error' => 'Please select an employee for this reason']);
    exit;
}

$employee_id  = $employee_id_raw !== '' ? intval($employee_id_raw) : null;
$reason_esc   = mysqli_real_escape_string($conn, $reason);
$emp_code_esc = mysqli_real_escape_string($conn, $employee_code);
$emp_name_esc = mysqli_real_escape_string($conn, $employee_name);
$emp_role_esc = mysqli_real_escape_string($conn, $employee_role);
$remarks_esc  = mysqli_real_escape_string($conn, $remarks);
$emp_id_sql   = $employee_id !== null ? intval($employee_id) : 'NULL';

if ($id > 0) {
    /* ── UPDATE existing charge ── */
    $sql = "UPDATE se_charges SET
                reason          = '$reason_esc',
                employee_id     = $emp_id_sql,
                employee_code   = '$emp_code_esc',
                employee_name   = '$emp_name_esc',
                employee_role   = '$emp_role_esc',
                amount_employee = $amount_employee,
                amount_company  = $amount_company,
                remarks         = '$remarks_esc',
                mark_recreate_invoice = $mark_recreate_invoice
            WHERE id = $id";
    $ok = mysqli_query($conn, $sql);
    if (!$ok) {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        exit;
    }
    echo json_encode(['success' => true, 'id' => $id, 'action' => 'updated']);
} else {
    /* ── INSERT new charge ── */
    if (!$field_summary_id) {
        echo json_encode(['success' => false, 'error' => 'Missing field_summary_id']);
        exit;
    }
    $sql = "INSERT INTO se_charges
              (field_summary_id, field_summary_detail_id, reason, employee_id, employee_code, employee_name, employee_role, amount_employee, amount_company, remarks, mark_recreate_invoice)
            VALUES
              ($field_summary_id, $field_summary_detail_id, '$reason_esc', $emp_id_sql, '$emp_code_esc', '$emp_name_esc', '$emp_role_esc', $amount_employee, $amount_company, '$remarks_esc', $mark_recreate_invoice)";
    $ok = mysqli_query($conn, $sql);
    if (!$ok) {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        exit;
    }
    echo json_encode(['success' => true, 'id' => mysqli_insert_id($conn), 'action' => 'created']);
}
