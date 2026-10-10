<?php
/**
 * update_cheque_details.php
 * Updates cheque_no, received_date, cheque_date, bank, branch
 * in BOTH cheques (master) and invoice_payment_cheques (leaf rows).
 * Preserves: status, bill_verified, acc_holder_name, due_date, images.
 * Returns JSON: { success, error? }
 */
include 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'error'=>'POST only']); exit;
}

function ucd_esc($c,$v){ return mysqli_real_escape_string($c,trim((string)($v??''))); }
function ucd_date($v){ $s=trim((string)($v??'')); return preg_match('/^\d{4}-\d{2}-\d{2}$/',$s)?$s:null; }

$cheque_id  = intval($_POST['cheque_id']   ?? 0);
$new_cno    = ucd_esc($conn,$_POST['cheque_no']     ?? '');
$new_rec    = ucd_date($_POST['received_date']       ?? '');
$new_cdate  = ucd_date($_POST['cheque_date']         ?? '');
$new_bkcode = ucd_esc($conn,$_POST['bank_code']      ?? '');
$new_bkname = ucd_esc($conn,$_POST['bank_name']      ?? '');
$new_brcode = ucd_esc($conn,$_POST['branch_code']    ?? '');
$new_brname = ucd_esc($conn,$_POST['branch_name']    ?? '');

if (!$cheque_id) { echo json_encode(['success'=>false,'error'=>'cheque_id required']); exit; }
if ($new_cno==='') { echo json_encode(['success'=>false,'error'=>'Cheque number cannot be empty']); exit; }

/* Load master for old identity */
$mr = mysqli_query($conn,"SELECT * FROM cheques WHERE id=$cheque_id LIMIT 1");
if (!$mr||mysqli_num_rows($mr)===0){ echo json_encode(['success'=>false,'error'=>'Cheque not found']); exit; }
$master     = mysqli_fetch_assoc($mr);
$old_cno    = ucd_esc($conn,$master['cheque_no']);
$old_bkc    = ucd_esc($conn,$master['bank_code']);
$old_brc    = ucd_esc($conn,$master['branch_code']);
$payment_id = intval($master['invoice_payment_id']??0);

/* Update cheques master — only editable columns, never touch status/images/verified */
$sets=["cheque_no='$new_cno'","bank_code='$new_bkcode'","bank_name='$new_bkname'",
       "branch_code='$new_brcode'","branch_name='$new_brname'","updated_at=NOW()"];
if ($new_cdate!==null) $sets[]="cheque_date='$new_cdate'";
if ($new_rec!==null)   $sets[]="received_date='$new_rec'";

if (!mysqli_query($conn,"UPDATE cheques SET ".implode(',',$sets)." WHERE id=$cheque_id")){
    echo json_encode(['success'=>false,'error'=>'Master: '.mysqli_error($conn)]); exit;
}

/* Update invoice_payment_cheques leaf rows matched by old identity */
if ($payment_id>0){
    $lsets=["cheque_no='$new_cno'","bank_code='$new_bkcode'","bank_name='$new_bkname'",
            "branch_code='$new_brcode'","branch_name='$new_brname'"];
    if ($new_cdate!==null) $lsets[]="cheque_date='$new_cdate'";
    mysqli_query($conn,"UPDATE invoice_payment_cheques SET ".implode(',',$lsets)."
        WHERE invoice_payment_id=$payment_id
          AND cheque_no='$old_cno' AND bank_code='$old_bkc' AND branch_code='$old_brc'");
}

/* Log */
if (session_status()===PHP_SESSION_NONE) session_start();
$by=ucd_esc($conn,$_SESSION['username']??$_SESSION['user_name']??$_SESSION['name']??'system');
@mysqli_query($conn,"CREATE TABLE IF NOT EXISTS cheque_logs(
    id INT AUTO_INCREMENT PRIMARY KEY,cheque_id INT NOT NULL,
    action VARCHAR(100),old_value TEXT,new_value TEXT,note TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,created_by VARCHAR(100) DEFAULT 'system',
    INDEX idx_cid(cheque_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$note=ucd_esc($conn,"cheque_no:$old_cno→$new_cno | bank:$new_bkcode | branch:$new_brcode".
    ($new_cdate?" | cheque_date:$new_cdate":'').($new_rec?" | received_date:$new_rec":''));
mysqli_query($conn,"INSERT INTO cheque_logs(cheque_id,action,old_value,new_value,note,created_by)
    VALUES($cheque_id,'details_updated','$old_cno','$new_cno','$note','$by')");

echo json_encode(['success'=>true]);