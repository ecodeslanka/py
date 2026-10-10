<?php
/**
 * save_credit_bill_verify.php
 * Handles:
 *   action=upload_bill  → saves credit bill image to credit_bill_images table
 *   action=set_status   → sets verified/not_verified on a credit_request row
 *   action=load         → returns all data for a detail_id (JSON)
 */
error_reporting(0); ini_set('display_errors', 0);
ob_start(); include 'config.php'; ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

function esc($c,$v){ return mysqli_real_escape_string($c, trim((string)($v??''))); }

/* ── ensure credit_bill_images table ── */
mysqli_query($conn,"CREATE TABLE IF NOT EXISTS credit_bill_images (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  field_summary_detail_id INT          NOT NULL,
  credit_request_id       INT          NULL,
  original_name           VARCHAR(255) NOT NULL,
  stored_name             VARCHAR(255) NOT NULL,
  file_path               VARCHAR(500) NOT NULL,
  file_type               VARCHAR(100) NULL,
  file_size               INT          NULL,
  uploaded_at             TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_det (field_summary_detail_id),
  INDEX idx_cr  (credit_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── ensure bill_verified column on credit_requests ── */
$cv = mysqli_query($conn,"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credit_requests' AND COLUMN_NAME='bill_verified' LIMIT 1");
if($cv && mysqli_num_rows($cv)===0)
    mysqli_query($conn,"ALTER TABLE credit_requests ADD COLUMN `bill_verified` TINYINT(1) NOT NULL DEFAULT 0");

/* ── ensure bill_verified column on field_summary_details ── */
$cvd = mysqli_query($conn,"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='field_summary_details' AND COLUMN_NAME='bill_verified' LIMIT 1");
if($cvd && mysqli_num_rows($cvd)===0)
    mysqli_query($conn,"ALTER TABLE field_summary_details ADD COLUMN `bill_verified` TINYINT(1) NULL DEFAULT NULL");

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$detid  = intval($_POST['detail_id'] ?? $_GET['detail_id'] ?? 0);

/* ════════════════════════════════
   ACTION: load — return all info
════════════════════════════════ */
if($action === 'load' && $detid){
    /* detail row */
    $dr = mysqli_query($conn,"SELECT fsd.*,
        COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS display_name,
        c.customer_signature, c.customer_seal,
        fsd.bill_verified AS detail_bill_verified
        FROM field_summary_details fsd
        LEFT JOIN customers c ON c.t_code = fsd.t_code
        WHERE fsd.id = $detid LIMIT 1");
    $detail = $dr ? mysqli_fetch_assoc($dr) : null;
    if(!$detail){ echo json_encode(['success'=>false,'error'=>'Detail not found']); exit; }

    /* credit requests for this detail */
    $crr = mysqli_query($conn,"SELECT cr.*, GROUP_CONCAT(cd.file_path ORDER BY cd.id SEPARATOR '||') AS doc_paths
        FROM credit_requests cr
        LEFT JOIN credit_documents cd ON cd.credit_request_id = cr.id
        WHERE cr.field_summary_detail_id = $detid
        GROUP BY cr.id ORDER BY cr.created_at DESC");
    $credit_requests = [];
    while($r = mysqli_fetch_assoc($crr)) $credit_requests[] = $r;

    /* uploaded bill images */
    $bir = mysqli_query($conn,"SELECT * FROM credit_bill_images WHERE field_summary_detail_id=$detid ORDER BY uploaded_at DESC");
    $bill_images = [];
    while($r = mysqli_fetch_assoc($bir)) $bill_images[] = $r;

    echo json_encode([
        'success'        => true,
        'detail'         => $detail,
        'credit_requests'=> $credit_requests,
        'bill_images'    => $bill_images,
        'is_emergency'   => count($credit_requests) > 0,
        'cr_count'       => count($credit_requests),
    ]);
    exit;
}

/* ════════════════════════════════
   ACTION: upload_bill
════════════════════════════════ */
if($action === 'upload_bill'){
    if(!$detid){ echo json_encode(['success'=>false,'error'=>'detail_id required']); exit; }
    if(empty($_FILES['bill_image']) || $_FILES['bill_image']['error'] !== UPLOAD_ERR_OK){
        echo json_encode(['success'=>false,'error'=>'No file uploaded']); exit;
    }
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    $ftype   = $_FILES['bill_image']['type'];
    $fsize   = $_FILES['bill_image']['size'];
    if(!in_array($ftype,$allowed)){ echo json_encode(['success'=>false,'error'=>'Only image files allowed']); exit; }
    if($fsize > 10*1024*1024){ echo json_encode(['success'=>false,'error'=>'File too large (max 10MB)']); exit; }

    $dir = 'uploads/credit_bills/';
    if(!is_dir($dir)) mkdir($dir,0755,true);

    $ext    = strtolower(pathinfo($_FILES['bill_image']['name'], PATHINFO_EXTENSION));
    $stored = 'bill_'.$detid.'_'.time().'_'.uniqid().'.'.$ext;
    $dest   = $dir.$stored;
    $crid   = intval($_POST['credit_request_id'] ?? 0);

    if(!move_uploaded_file($_FILES['bill_image']['tmp_name'], $dest)){
        echo json_encode(['success'=>false,'error'=>'File move failed']); exit;
    }
    $orig = esc($conn, basename($_FILES['bill_image']['name']));
    $sn   = esc($conn, $stored);
    $fp   = esc($conn, $dest);
    $ft   = esc($conn, $ftype);
    $crid_sql = $crid ? $crid : 'NULL';
    mysqli_query($conn,"INSERT INTO credit_bill_images
        (field_summary_detail_id,credit_request_id,original_name,stored_name,file_path,file_type,file_size)
        VALUES ($detid,$crid_sql,'$orig','$sn','$fp','$ft',$fsize)");
    $img_id = mysqli_insert_id($conn);
    echo json_encode(['success'=>true,'image_id'=>$img_id,'file_path'=>$dest,'stored_name'=>$stored]);
    exit;
}

/* ════════════════════════════════
   ACTION: set_status (verify / not verify)
════════════════════════════════ */
if($action === 'set_status'){
    $crid   = intval($_POST['credit_request_id'] ?? 0);
    $status = intval($_POST['verified'] ?? 0); // 1 or 0
    if(!$crid){ echo json_encode(['success'=>false,'error'=>'credit_request_id required']); exit; }
    if(mysqli_query($conn,"UPDATE credit_requests SET bill_verified=$status WHERE id=$crid")){
        echo json_encode(['success'=>true,'verified'=>$status]);
    } else {
        echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]);
    }
    exit;
}

/* ════════════════════════════════
   ACTION: delete_bill_image
════════════════════════════════ */
if($action === 'delete_bill_image'){
    $img_id = intval($_POST['image_id'] ?? 0);
    if(!$img_id){ echo json_encode(['success'=>false,'error'=>'image_id required']); exit; }
    $fr = mysqli_query($conn,"SELECT file_path FROM credit_bill_images WHERE id=$img_id LIMIT 1");
    $fr_row = $fr ? mysqli_fetch_assoc($fr) : null;
    if($fr_row && file_exists($fr_row['file_path'])) @unlink($fr_row['file_path']);
    mysqli_query($conn,"DELETE FROM credit_bill_images WHERE id=$img_id");
    echo json_encode(['success'=>true]);
    exit;
}

/* ══════════════════════════════════════════════════════
   ACTION: set_detail_verify — update field_summary_details.bill_verified
══════════════════════════════════════════════════════ */
if($action === 'set_detail_verify'){
    $status = intval($_POST['verified'] ?? -1); // 1=verified, 0=not verified, -1=clear
    if(!$detid){ echo json_encode(['success'=>false,'error'=>'detail_id required']); exit; }
    $val = ($status === -1) ? 'NULL' : $status;
    if(mysqli_query($conn,"UPDATE field_summary_details SET bill_verified=$val WHERE id=$detid")){
        echo json_encode(['success'=>true,'verified'=>$status,'detail_id'=>$detid]);
    } else {
        echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]);
    }
    exit;
}

echo json_encode(['success'=>false,'error'=>'Unknown action']);