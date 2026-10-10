<?php
/**
 * get_cheque_info.php
 * Returns existing cheque info for real-time duplicate detection in the payment modal.
 * Queries cheques table for total_amount.
 */
include 'config.php';
header('Content-Type: application/json');

$cheque_no   = trim($_GET['cheque_no']   ?? '');
$bank_code   = trim($_GET['bank_code']   ?? '');
$branch_code = trim($_GET['branch_code'] ?? '');
if (!$cheque_no) { echo json_encode(['exists'=>false]); exit; }

$esc        = mysqli_real_escape_string($conn, $cheque_no);
$esc_bank   = mysqli_real_escape_string($conn, $bank_code);
$esc_branch = mysqli_real_escape_string($conn, $branch_code);

$where = "cheque_no='$esc'";
if ($esc_bank)   $where .= " AND bank_code='$esc_bank'";
if ($esc_branch) $where .= " AND branch_code='$esc_branch'";

$r = mysqli_query($conn,
    "SELECT cheque_no, total_amount, amount, status, bank_name, cheque_date
     FROM cheques WHERE $where LIMIT 1");

if (!$r || mysqli_num_rows($r) === 0) { echo json_encode(['exists'=>false]); exit; }

$row = mysqli_fetch_assoc($r);
echo json_encode([
    'exists'       => true,
    'total_amount' => floatval($row['total_amount'] ?: $row['amount']),
    'bank_name'    => $row['bank_name'],
    'cheque_date'  => $row['cheque_date'],
    'status'       => $row['status'],
    'cheque_no'    => $row['cheque_no'],
]);