<?php
/**
 * visa_reconciliation.php (UPDATED v2.7)
 * Changes from v2.6:
 *  - NEW: "Bulk" button on each unreconciled row in the View Details modal.
 *    Sometimes one bank-side VISA transaction amount actually equals the
 *    COMBINED total of several invoices (customer paid for multiple
 *    invoices in a single card swipe). Clicking "Bulk" opens a panel
 *    listing all unreconciled VISA invoices on the SAME date as that
 *    transaction, with checkboxes. User checks the invoices whose total
 *    matches the bank amount, sees a live running total + diff, then
 *    clicks "Reconcile Selected" to link ALL of them to that one bank
 *    transaction row in a single action.
 *  - NEW AJAX: bulk_candidates — returns same-date unreconciled VISA
 *    invoices for a row.
 *  - NEW AJAX: bulk_reconcile_row — links multiple invoice IDs to one
 *    visa_reconciliation_rows row (stored in new bulk_invoice_ids column),
 *    splits that row's commission/net proportionally across the selected
 *    invoices by their amount share, marks all of them reconciled=1.
 *  - unreconcile_row AJAX extended: if a row was bulk-linked, un-reconciles
 *    ALL invoices in its bulk_invoice_ids list (not just a single invoice).
 *    Behavior for normal (non-bulk) rows is unchanged.
 *  - view_details AJAX extended: attaches a bulk_invoices[] array to any
 *    matched row that was bulk-linked, so the modal can show all linked
 *    invoices instead of just one.
 *  - reconcile_row AJAX: also clears bulk_invoice_ids when a row is
 *    (re)matched to a single invoice, so stale bulk data can't linger.
 * No other functions changed from v2.5/v2.6.
 */
include 'config.php';
ob_start();

/* ── DB: ensure tables exist ── */
mysqli_query($conn,"CREATE TABLE IF NOT EXISTS visa_reconciliations (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  filename       VARCHAR(255),
  mid            VARCHAR(100),
  location       VARCHAR(100),
  statement_date DATE,
  billing_total  DECIMAL(14,2),
  total_amount   DECIMAL(14,2),
  total_comm     DECIMAL(14,2),
  total_net      DECIMAL(14,2),
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
)");

mysqli_query($conn,"CREATE TABLE IF NOT EXISTS visa_reconciliation_rows (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  reconciliation_id INT NOT NULL,
  trx_date          DATE,
  card_number       VARCHAR(30),
  terminal          VARCHAR(30),
  auth_code         VARCHAR(30),
  amount            DECIMAL(14,2),
  comm              DECIMAL(14,2),
  net               DECIMAL(14,2),
  invoice_id        INT DEFAULT NULL,
  matched           TINYINT(1) DEFAULT 0,
  INDEX idx_recon_id (reconciliation_id)
)");

/* ── Patch missing columns ── */
$vr_cols=[];$r=mysqli_query($conn,"SHOW COLUMNS FROM visa_reconciliations");
while($c=mysqli_fetch_assoc($r))$vr_cols[]=$c['Field'];
foreach(['filename VARCHAR(255)','mid VARCHAR(100)','location VARCHAR(100)',
         'statement_date DATE','billing_total DECIMAL(14,2)','total_amount DECIMAL(14,2)',
         'total_comm DECIMAL(14,2)','total_net DECIMAL(14,2)',
         'created_at DATETIME DEFAULT CURRENT_TIMESTAMP'] as $col){
  $n=explode(' ',$col)[0];
  if(!in_array($n,$vr_cols))mysqli_query($conn,"ALTER TABLE visa_reconciliations ADD COLUMN $col");
}

$inv_cols=[];$r=mysqli_query($conn,"SHOW COLUMNS FROM ushop_invoices");
while($c=mysqli_fetch_assoc($r))$inv_cols[]=$c['Field'];
foreach(['reconciled TINYINT(1) DEFAULT 0','auth_code VARCHAR(30) DEFAULT NULL',
         'visa_comm DECIMAL(14,2) DEFAULT NULL','visa_net DECIMAL(14,2) DEFAULT NULL',
         'reconciliation_id INT DEFAULT NULL'] as $col){
  $n=explode(' ',$col)[0];
  if(!in_array($n,$inv_cols))mysqli_query($conn,"ALTER TABLE ushop_invoices ADD COLUMN $col");
}

/* ── Patch missing columns for bulk-match support (NEW v2.7) ── */
$rr_cols=[];$r=mysqli_query($conn,"SHOW COLUMNS FROM visa_reconciliation_rows");
while($c=mysqli_fetch_assoc($r))$rr_cols[]=$c['Field'];
foreach(['bulk_invoice_ids TEXT DEFAULT NULL'] as $col){
  $n=explode(' ',$col)[0];
  if(!in_array($n,$rr_cols)){
    try{
      mysqli_query($conn,"ALTER TABLE visa_reconciliation_rows ADD COLUMN $col");
    }catch(mysqli_sql_exception $e){
      /* column may already exist from a concurrent request - ignore */
    }
  }
}

/* ── Auto-drop stray unique key 'uq_detail' if present ── */
foreach(['visa_reconciliation_rows','visa_reconciliations','ushop_invoices'] as $tbl){
  $kc=mysqli_query($conn,"SHOW KEYS FROM `$tbl` WHERE Key_name='uq_detail'");
  if($kc && mysqli_num_rows($kc)>0){
    mysqli_query($conn,"ALTER TABLE `$tbl` DROP INDEX `uq_detail`");
  }
}

/* ══ AJAX: get Gemini API key ══ */
if(isset($_GET['ajax'])&&$_GET['ajax']==='get_api_key'){
  ob_clean();header('Content-Type: application/json');
  $r=mysqli_query($conn,"SELECT `value` FROM ai_settings WHERE `key`='gemini_api_key' LIMIT 1");
  $row=$r?mysqli_fetch_assoc($r):null;
  $key=trim($row['value']??'');
  echo json_encode(['success'=>true,'has_key'=>($key!==''),'key'=>$key]);
  exit;
}

/* ══ AJAX: View reconciliation details (UPDATED v2.7 — adds bulk_invoices) ══ */
if(isset($_GET['ajax'])&&$_GET['ajax']==='view_details'){
  ob_clean();header('Content-Type: application/json');
  $rid=intval($_GET['recon_id']??0);
  if(!$rid){echo json_encode(['success'=>false,'error'=>'Invalid ID']);exit;}

  $h=mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM visa_reconciliations WHERE id=$rid"));
  if(!$h){echo json_encode(['success'=>false,'error'=>'Not found']);exit;}

  $rows=[];
  $r=mysqli_query($conn,"
    SELECT rr.*,
           inv.doc_no, inv.unique_inv_no, inv.customer_name, inv.customer_code,
           inv.total_amount AS inv_amount, inv.invoice_date
    FROM visa_reconciliation_rows rr
    LEFT JOIN ushop_invoices inv ON inv.id = rr.invoice_id
    WHERE rr.reconciliation_id=$rid
    ORDER BY rr.trx_date ASC
  ");
  while($row=mysqli_fetch_assoc($r))$rows[]=$row;

  /* For each row: build candidate list (unmatched) or bulk_invoices list (matched via bulk) */
  foreach($rows as &$row){
    $row['candidates']=[];
    $row['bulk_invoices']=[];

    /* NEW v2.7: if this row was bulk-linked, fetch all the invoices it covers */
    if(!empty($row['matched']) && !empty($row['bulk_invoice_ids'])){
      $bid_arr=explode(',', $row['bulk_invoice_ids']);
      $clean=[];
      foreach($bid_arr as $b){ $b=intval($b); if($b>0)$clean[]=$b; }
      if(count($clean)){
        $id_list=implode(',', $clean);
        $br=mysqli_query($conn,"SELECT id,doc_no,customer_name,total_amount,invoice_date FROM ushop_invoices WHERE id IN ($id_list)");
        while($bi=mysqli_fetch_assoc($br)) $row['bulk_invoices'][]=$bi;
      }
    }

    if(!$row['matched']){
      $date=mysqli_real_escape_string($conn,$row['trx_date']??'');
      $amt=(float)($row['amount']??0);
      if($date&&$amt>0){
        $cr=mysqli_query($conn,"
          SELECT inv.id,inv.doc_no,inv.unique_inv_no,inv.customer_name,inv.total_amount,inv.invoice_date,inv.reconciled
          FROM ushop_invoices inv
          INNER JOIN ushop_invoice_payments pay ON pay.invoice_id=inv.id
          WHERE inv.invoice_date='$date'
            AND pay.pay_type LIKE '%VISA%'
            AND ABS(inv.total_amount - $amt) < 0.01
          LIMIT 10
        ");
        while($ci=mysqli_fetch_assoc($cr))$row['candidates'][]=$ci;
      }
    }
  }
  unset($row);

  echo json_encode(['success'=>true,'header'=>$h,'rows'=>$rows]);
  exit;
}

/* ══ AJAX: Reconcile a single row (UPDATED v2.7 — also clears stale bulk_invoice_ids) ══ */
if(isset($_POST['ajax_action'])&&$_POST['ajax_action']==='reconcile_row'){
  ob_clean();header('Content-Type: application/json');
  $row_id   = intval($_POST['row_id']??0);
  $inv_id   = intval($_POST['invoice_id']??0);
  $recon_id = intval($_POST['recon_id']??0);
  if(!$row_id||!$inv_id||!$recon_id){
    echo json_encode(['success'=>false,'error'=>'Missing parameters']);exit;
  }
  /* Fetch row info for auth_code, comm, net */
  $row=mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM visa_reconciliation_rows WHERE id=$row_id AND reconciliation_id=$recon_id LIMIT 1"));
  if(!$row){echo json_encode(['success'=>false,'error'=>'Row not found']);exit;}

  $ac=mysqli_real_escape_string($conn,$row['auth_code']??'');
  $co=(float)($row['comm']??0);
  $nt=(float)($row['net']??0);

  mysqli_begin_transaction($conn);
  try{
    $r1=mysqli_query($conn,"UPDATE visa_reconciliation_rows SET invoice_id=$inv_id, matched=1, bulk_invoice_ids=NULL WHERE id=$row_id");
    if(!$r1)throw new Exception('Row update failed: '.mysqli_error($conn));
    $r2=mysqli_query($conn,"UPDATE ushop_invoices SET reconciled=1,auth_code='$ac',visa_comm=$co,visa_net=$nt,reconciliation_id=$recon_id WHERE id=$inv_id");
    if(!$r2)throw new Exception('Invoice update failed: '.mysqli_error($conn));
    mysqli_commit($conn);
    /* Return updated invoice info */
    $inv=mysqli_fetch_assoc(mysqli_query($conn,"SELECT id,doc_no,customer_name,total_amount,invoice_date FROM ushop_invoices WHERE id=$inv_id"));
    echo json_encode(['success'=>true,'invoice'=>$inv]);
  }catch(Exception $e){
    mysqli_rollback($conn);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

/* ══ AJAX: Un-reconcile a single row (UPDATED v2.7 — handles bulk-linked rows too) ══ */
if(isset($_POST['ajax_action'])&&$_POST['ajax_action']==='unreconcile_row'){
  ob_clean();header('Content-Type: application/json');
  $row_id   = intval($_POST['row_id']??0);
  $recon_id = intval($_POST['recon_id']??0);
  if(!$row_id||!$recon_id){echo json_encode(['success'=>false,'error'=>'Missing parameters']);exit;}

  $row=mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM visa_reconciliation_rows WHERE id=$row_id AND reconciliation_id=$recon_id LIMIT 1"));
  if(!$row){echo json_encode(['success'=>false,'error'=>'Row not found']);exit;}
  $inv_id=intval($row['invoice_id']??0);
  $bulk_ids=trim($row['bulk_invoice_ids']??'');

  mysqli_begin_transaction($conn);
  try{
    $r1=mysqli_query($conn,"UPDATE visa_reconciliation_rows SET invoice_id=NULL, matched=0, bulk_invoice_ids=NULL WHERE id=$row_id");
    if(!$r1)throw new Exception('Row update failed: '.mysqli_error($conn));

    if($bulk_ids!==''){
      /* This row was bulk-linked to multiple invoices - unreconcile all of them */
      $bid_arr=explode(',', $bulk_ids);
      $clean=[];
      foreach($bid_arr as $b){ $b=intval($b); if($b>0)$clean[]=$b; }
      if(count($clean)){
        $id_list=implode(',', $clean);
        $r2=mysqli_query($conn,"UPDATE ushop_invoices SET reconciled=0,auth_code=NULL,visa_comm=NULL,visa_net=NULL,reconciliation_id=NULL WHERE id IN ($id_list)");
        if(!$r2)throw new Exception('Invoice update failed: '.mysqli_error($conn));
      }
    } elseif($inv_id){
      $r2=mysqli_query($conn,"UPDATE ushop_invoices SET reconciled=0,auth_code=NULL,visa_comm=NULL,visa_net=NULL,reconciliation_id=NULL WHERE id=$inv_id");
      if(!$r2)throw new Exception('Invoice update failed: '.mysqli_error($conn));
    }
    mysqli_commit($conn);
    echo json_encode(['success'=>true]);
  }catch(Exception $e){
    mysqli_rollback($conn);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

/* ══ AJAX: Get same-date unreconciled invoices for bulk matching (NEW v2.7) ══ */
if(isset($_POST['ajax_action'])&&$_POST['ajax_action']==='bulk_candidates'){
  ob_clean();header('Content-Type: application/json');
  $row_id   = intval($_POST['row_id']??0);
  $recon_id = intval($_POST['recon_id']??0);
  $trx_date = mysqli_real_escape_string($conn, trim($_POST['trx_date']??''));
  if(!$trx_date){echo json_encode(['success'=>false,'error'=>'Missing transaction date']);exit;}

  /* If this row already has a single invoice linked, exclude it from the picker
     (re-running bulk match on an already-matched row is unusual, but stay safe) */
  $exclude_id=0;
  if($row_id){
    $rw=mysqli_fetch_assoc(mysqli_query($conn,"SELECT invoice_id FROM visa_reconciliation_rows WHERE id=$row_id LIMIT 1"));
    $exclude_id=intval($rw['invoice_id']??0);
  }

  $res=mysqli_query($conn,"
    SELECT DISTINCT inv.id,inv.doc_no,inv.unique_inv_no,inv.customer_name,inv.customer_code,
           inv.total_amount,inv.invoice_date,inv.reconciled
    FROM ushop_invoices inv
    INNER JOIN ushop_invoice_payments pay ON pay.invoice_id=inv.id
    WHERE pay.pay_type LIKE '%VISA%'
      AND inv.invoice_date='$trx_date'
      AND (inv.reconciled=0 OR inv.reconciled IS NULL)
    ORDER BY inv.total_amount ASC
    LIMIT 100
  ");
  $invoices=[];
  while($iv=mysqli_fetch_assoc($res)){
    if($exclude_id && (int)$iv['id']===$exclude_id) continue;
    $invoices[]=$iv;
  }
  echo json_encode(['success'=>true,'invoices'=>$invoices]);
  exit;
}

/* ══ AJAX: Bulk-reconcile MULTIPLE invoices to ONE bank transaction row (NEW v2.7) ══ */
if(isset($_POST['ajax_action'])&&$_POST['ajax_action']==='bulk_reconcile_row'){
  ob_clean();header('Content-Type: application/json');
  $row_id   = intval($_POST['row_id']??0);
  $recon_id = intval($_POST['recon_id']??0);
  $raw_ids  = json_decode($_POST['invoice_ids']??'[]', true);

  if(!$row_id||!$recon_id||!is_array($raw_ids)||!count($raw_ids)){
    echo json_encode(['success'=>false,'error'=>'Missing parameters or no invoices selected']);exit;
  }

  $ids=[];
  foreach($raw_ids as $v){
    $v=intval($v);
    if($v>0 && !in_array($v,$ids)) $ids[]=$v;
  }
  if(!count($ids)){echo json_encode(['success'=>false,'error'=>'No valid invoices selected']);exit;}

  $row=mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM visa_reconciliation_rows WHERE id=$row_id AND reconciliation_id=$recon_id LIMIT 1"));
  if(!$row){echo json_encode(['success'=>false,'error'=>'Row not found']);exit;}

  $ac=mysqli_real_escape_string($conn,$row['auth_code']??'');
  $row_comm=(float)($row['comm']??0);
  $row_net =(float)($row['net']??0);

  $id_list=implode(',', $ids);
  $inv_res=mysqli_query($conn,"SELECT id,total_amount FROM ushop_invoices WHERE id IN ($id_list)");
  $invoices=[];
  $sum_amt=0;
  while($iv=mysqli_fetch_assoc($inv_res)){
    $invoices[]=$iv;
    $sum_amt+=(float)$iv['total_amount'];
  }
  if(!count($invoices)){echo json_encode(['success'=>false,'error'=>'Selected invoices not found']);exit;}
  if($sum_amt<=0){echo json_encode(['success'=>false,'error'=>'Invalid invoice total']);exit;}

  mysqli_begin_transaction($conn);
  try{
    foreach($invoices as $iv){
      $iid=(int)$iv['id'];
      $share=(float)$iv['total_amount']/$sum_amt;
      $comm_share=round($row_comm*$share,2);
      $net_share =round($row_net*$share,2);
      $upd=mysqli_query($conn,"UPDATE ushop_invoices SET reconciled=1,auth_code='$ac',visa_comm=$comm_share,visa_net=$net_share,reconciliation_id=$recon_id WHERE id=$iid");
      if(!$upd) throw new Exception('Failed to update invoice #'.$iid.': '.mysqli_error($conn));
    }
    $primary_id=$ids[0];
    $bulk_ids_esc=mysqli_real_escape_string($conn, implode(',', $ids));
    $r1=mysqli_query($conn,"UPDATE visa_reconciliation_rows SET invoice_id=$primary_id, matched=1, bulk_invoice_ids='$bulk_ids_esc' WHERE id=$row_id");
    if(!$r1) throw new Exception('Row update failed: '.mysqli_error($conn));

    mysqli_commit($conn);
    echo json_encode([
      'success'=>true,
      'matched'=>count($invoices),
      'sum_amount'=>$sum_amt,
      'row_amount'=>(float)($row['amount']??0)
    ]);
  }catch(Exception $e){
    mysqli_rollback($conn);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

/* ══ AJAX: Search invoices for manual re-reconcile (NEW v2.6) ══ */
if(isset($_POST['ajax_action'])&&$_POST['ajax_action']==='search_invoices'){
  ob_clean();header('Content-Type: application/json');
  $q  = mysqli_real_escape_string($conn, trim($_POST['q']??''));
  $amt= (float)($_POST['amount']??0);
  if(strlen($q)<1&&$amt<=0){echo json_encode(['success'=>false,'error'=>'Enter a search term']);exit;}

  $conditions=[];
  if($q!==''){
    $conditions[]="(inv.doc_no LIKE '%$q%' OR inv.unique_inv_no LIKE '%$q%' OR inv.customer_name LIKE '%$q%' OR inv.customer_code LIKE '%$q%')";
  }
  if($amt>0){
    $conditions[]="ABS(inv.total_amount - $amt) < 0.01";
  }
  $where = $conditions ? 'AND '.implode(' AND ',$conditions) : '';

  $res=mysqli_query($conn,"
    SELECT DISTINCT inv.id,inv.doc_no,inv.unique_inv_no,inv.customer_name,
           inv.total_amount,inv.invoice_date,inv.reconciled
    FROM ushop_invoices inv
    INNER JOIN ushop_invoice_payments pay ON pay.invoice_id=inv.id
    WHERE pay.pay_type LIKE '%VISA%'
    $where
    ORDER BY inv.invoice_date DESC
    LIMIT 20
  ");
  $invoices=[];
  while($row=mysqli_fetch_assoc($res))$invoices[]=$row;
  echo json_encode(['success'=>true,'invoices'=>$invoices]);
  exit;
}

/* ══ AJAX: DELETE reconciliation ══ */
if(isset($_POST['ajax_action'])&&$_POST['ajax_action']==='delete_recon'){
  ob_clean();header('Content-Type: application/json');
  $rid=intval($_POST['recon_id']??0);
  if(!$rid){echo json_encode(['success'=>false,'error'=>'Invalid reconciliation ID']);exit;}

  mysqli_begin_transaction($conn);
  try{
    $matched_count=mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as cnt FROM visa_reconciliation_rows WHERE reconciliation_id=$rid AND matched=1"))['cnt']??0;

    $result=mysqli_query($conn,"UPDATE ushop_invoices SET reconciled=0,auth_code=NULL,visa_comm=NULL,visa_net=NULL,reconciliation_id=NULL WHERE reconciliation_id=$rid");
    if(!$result)throw new Exception("Failed to update invoices: ".mysqli_error($conn));

    $result=mysqli_query($conn,"DELETE FROM visa_reconciliation_rows WHERE reconciliation_id=$rid");
    if(!$result)throw new Exception("Failed to delete rows: ".mysqli_error($conn));

    $result=mysqli_query($conn,"DELETE FROM visa_reconciliations WHERE id=$rid");
    if(!$result)throw new Exception("Failed to delete reconciliation: ".mysqli_error($conn));

    mysqli_commit($conn);
    echo json_encode(['success'=>true,'message'=>"Reconciliation deleted. $matched_count invoice(s) unreconciled."]);
  }catch(Exception $e){
    mysqli_rollback($conn);
    echo json_encode(['success'=>false,'error'=>'Delete failed: '.$e->getMessage()]);
  }
  exit;
}

/* ══ AJAX: match transactions with DB invoices (READ-ONLY, no insert) ══ */
if(isset($_POST['ajax_action'])&&$_POST['ajax_action']==='match_transactions'){
  ob_clean();header('Content-Type: application/json');
  $rows=json_decode($_POST['rows']??'[]',true);
  if(!is_array($rows)||!count($rows)){echo json_encode(['success'=>false,'error'=>'No transaction rows received']);exit;}

  $results=[];
  foreach($rows as $row){
    $date=mysqli_real_escape_string($conn,trim($row['trx_date']??''));
    $amt =(float)($row['amount']??0);

    if(!$date||$amt<=0){
      $results[]=['trx'=>$row,'matches'=>[],'error'=>'Invalid date or amount'];
      continue;
    }

    $inv_r=mysqli_query($conn,"
      SELECT inv.id,inv.doc_no,inv.unique_inv_no,inv.customer_name,inv.customer_code,
             inv.total_amount,inv.invoice_date,inv.reconciled
      FROM ushop_invoices inv
      INNER JOIN ushop_invoice_payments pay ON pay.invoice_id=inv.id
      WHERE inv.invoice_date='$date'
        AND pay.pay_type LIKE '%VISA%'
        AND ABS(inv.total_amount - $amt) < 0.01
      LIMIT 5
    ");
    $matches=[];
    while($iv=mysqli_fetch_assoc($inv_r))$matches[]=$iv;
    $results[]=['trx'=>$row,'matches'=>$matches];
  }
  echo json_encode(['success'=>true,'results'=>$results]);
  exit;
}

/* ══ AJAX: SAVE FULL RECONCILIATION ══ */
if(isset($_POST['ajax_action'])&&$_POST['ajax_action']==='save_full_reconciliation'){
  ob_clean();header('Content-Type: application/json');

  $fn =mysqli_real_escape_string($conn,$_POST['filename']??'');
  $mid=mysqli_real_escape_string($conn,$_POST['mid']??'');
  $loc=mysqli_real_escape_string($conn,$_POST['location']??'');
  $sd =mysqli_real_escape_string($conn,$_POST['statement_date']??'');
  $bt =(float)($_POST['billing_total']??0);
  $ta =(float)($_POST['total_amount']??0);
  $tc =(float)($_POST['total_comm']??0);
  $tn =(float)($_POST['total_net']??0);
  $sdVal=$sd!==''?"'$sd'":'NULL';
  $rows=json_decode($_POST['rows']??'[]',true);

  if(!$fn||!$mid||!$loc){
    echo json_encode(['success'=>false,'error'=>'Missing required fields (filename, MID, location)']);
    exit;
  }
  if(!is_array($rows)||!count($rows)){
    echo json_encode(['success'=>false,'error'=>'No transaction rows to save']);
    exit;
  }

  $matchedCount=0;
  foreach($rows as $r) if(!empty($r['invoice_id'])) $matchedCount++;

  mysqli_begin_transaction($conn);
  try{
    mysqli_query($conn,"INSERT INTO visa_reconciliations (filename,mid,location,statement_date,billing_total,total_amount,total_comm,total_net)
      VALUES('$fn','$mid','$loc',$sdVal,$bt,$ta,$tc,$tn)");
    $rid=mysqli_insert_id($conn);
    if(!$rid) throw new Exception('Failed to create reconciliation header: '.mysqli_error($conn));

    $saved=0;
    foreach($rows as $tx){
      $td=mysqli_real_escape_string($conn,$tx['trx_date']??'');
      $cn=mysqli_real_escape_string($conn,$tx['card_number']??'');
      $tm=mysqli_real_escape_string($conn,$tx['terminal']??'');
      $ac=mysqli_real_escape_string($conn,$tx['auth_code']??'');
      $am=(float)($tx['amount']??0);
      $co=(float)($tx['comm']??0);
      $nt=(float)($tx['net']??0);
      $invId=!empty($tx['invoice_id'])?intval($tx['invoice_id']):0;
      $matched=$invId?1:0;
      $invSql=$invId?$invId:'NULL';

      $ins=mysqli_query($conn,"INSERT INTO visa_reconciliation_rows
        (reconciliation_id,trx_date,card_number,terminal,auth_code,amount,comm,net,invoice_id,matched)
        VALUES($rid,'$td','$cn','$tm','$ac',$am,$co,$nt,$invSql,$matched)");
      if(!$ins) throw new Exception('Failed to insert row: '.mysqli_error($conn));

      if($invId){
        $upd=mysqli_query($conn,"UPDATE ushop_invoices SET reconciled=1,auth_code='$ac',visa_comm=$co,visa_net=$nt,reconciliation_id=$rid WHERE id=$invId");
        if(!$upd) throw new Exception('Failed to update invoice #'.$invId.': '.mysqli_error($conn));
        $saved++;
      }
    }

    mysqli_commit($conn);
    echo json_encode(['success'=>true,'recon_id'=>$rid,'saved'=>$saved,'total_rows'=>count($rows)]);
  }catch(Exception $e){
    mysqli_rollback($conn);
    echo json_encode(['success'=>false,'error'=>'Save failed: '.$e->getMessage()]);
  }
  exit;
}

/* ── Pagination setup ── */
$page=max(1,intval($_GET['page']??1));
$per_page=10;
$offset=($page-1)*$per_page;

$total=mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as cnt FROM visa_reconciliations"))['cnt']??0;
$total_pages=max(1,ceil($total/$per_page));
$page=min($page,$total_pages);

/* ── History with pagination ── */
$history=mysqli_query($conn,"
  SELECT r.*,COUNT(rr.id) total_rows,SUM(rr.matched) matched_rows
  FROM visa_reconciliations r
  LEFT JOIN visa_reconciliation_rows rr ON rr.reconciliation_id=r.id
  GROUP BY r.id ORDER BY r.created_at DESC 
  LIMIT $offset,$per_page
");

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
:root{
  --indigo:#4f46e5;--indigo2:#3730a3;--teal:#0e7490;--teal2:#155e75;
  --green:#15803d;--amber:#92400e;--red:#dc2626;
  --border:#e5e7eb;--tx:#111827;--txs:#6b7280;--bg:#f9fafb;
}
.pw{max-width:1400px;margin:0 auto;padding:0 8px;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:14px;flex-wrap:wrap;}
.breadcrumb a{color:var(--teal);text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}
.steps-bar{display:flex;background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:20px;}
.step-item{flex:1;display:flex;align-items:center;gap:10px;padding:13px 16px;border-right:1px solid var(--border);transition:background .2s;}
.step-item:last-child{border-right:none;}
.step-item.active{background:linear-gradient(135deg,#e0f2fe,#cffafe);}
.step-item.done{background:#f0fdf4;}
.step-num{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;background:#e2e8f0;color:#475569;}
.step-item.active .step-num{background:var(--teal);color:#fff;}
.step-item.done .step-num{background:var(--green);color:#fff;}
.step-text{font-size:12px;font-weight:700;color:#475569;}
.step-item.active .step-text{color:var(--teal2);}
.step-item.done .step-text{color:var(--green);}
.step-sub{font-size:10px;color:#9ca3af;margin-top:1px;}
.api-banner{display:flex;align-items:center;gap:10px;padding:10px 16px;border-radius:9px;border:1.5px solid;margin-bottom:16px;font-size:12.5px;font-weight:600;}
.api-banner.ok{background:#f0fdf4;border-color:#86efac;color:#166534;}
.api-banner.warn{background:#fef3c7;border-color:#fde68a;color:#92400e;}
.api-banner a{color:inherit;font-weight:800;text-decoration:underline;}
.card{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:18px;box-shadow:0 1px 5px rgba(0,0,0,.04);}
.card-head{display:flex;align-items:center;gap:12px;padding:13px 18px;border-bottom:1px solid var(--border);background:#fafbfd;}
.ch-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
.ch-title{font-size:13.5px;font-weight:700;color:var(--tx);}
.ch-sub{font-size:11px;color:var(--txs);margin-top:1px;}
.card-body{padding:18px 20px;}
.upload-zone{border:2.5px dashed var(--teal);border-radius:12px;padding:38px 20px;text-align:center;background:linear-gradient(135deg,#f0fdff,#e0f9ff);cursor:pointer;transition:all .2s;}
.upload-zone:hover,.upload-zone.drag{border-color:var(--teal2);background:#cffafe;}
.upload-zone i{font-size:34px;color:var(--teal);margin-bottom:10px;}
.prog-box{background:#fff;border:1px solid var(--border);border-radius:9px;padding:13px 16px;margin-bottom:14px;display:none;}
.prog-box.show{display:block;}
.prog-top{display:flex;justify-content:space-between;font-size:12px;margin-bottom:7px;color:#374151;}
.prog-bg{background:#f1f5f9;border-radius:5px;height:7px;overflow:hidden;}
.prog-fill{height:100%;background:linear-gradient(90deg,var(--teal),#22d3ee);border-radius:5px;transition:width .25s;}
.prog-file{margin-top:5px;font-size:10.5px;color:var(--txs);}
.sum-strip{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;}
.sum-box{background:#fff;border:1px solid var(--border);border-radius:9px;padding:11px 16px;flex:1;min-width:110px;border-top:3px solid var(--teal);}
.sum-box-label{font-size:10px;color:var(--txs);font-weight:700;text-transform:uppercase;letter-spacing:.4px;}
.sum-box-val{font-size:18px;font-weight:700;color:var(--teal);margin-top:3px;}
.tbl-wrap{overflow-x:auto;}
.recon-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.recon-tbl th{padding:9px 10px;background:#0f172a;color:#e2e8f0;font-size:10px;text-transform:uppercase;white-space:nowrap;position:sticky;top:0;}
.recon-tbl td{padding:9px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.recon-tbl tr:hover td{background:#f0f9ff!important;}
.recon-tbl tfoot td{padding:9px 10px;background:#0f172a;color:#e2e8f0;font-weight:700;position:sticky;bottom:0;}
.tr{text-align:right!important;}.tc{text-align:center!important;}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;}
.b-teal{background:#cffafe;color:var(--teal);}
.b-green{background:#dcfce7;color:var(--green);}
.b-amber{background:#fef3c7;color:var(--amber);}
.b-red{background:#fee2e2;color:var(--red);}
.b-blue{background:#dbeafe;color:#1e40af;}
.b-gray{background:#f3f4f6;color:#6b7280;}
.match-select{width:100%;padding:5px 7px;border:1px solid #d1d5db;border-radius:6px;font-size:11px;font-family:inherit;}
.match-select:focus{border-color:var(--teal);outline:none;}
.match-select.matched{border-color:var(--green);background:#f0fdf4;}
.toolbar{display:flex;gap:9px;align-items:center;padding:13px 18px;border-top:1px solid var(--border);background:#fafbfd;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn:disabled{opacity:.45;cursor:not-allowed;}
.btn-teal{background:linear-gradient(135deg,var(--teal),var(--teal2));color:#fff;}
.btn-teal:hover:not(:disabled){filter:brightness(1.1);}
.btn-green{background:linear-gradient(135deg,var(--green),#166534);color:#fff;}
.btn-green:hover:not(:disabled){filter:brightness(1.1);}
.btn-blue{background:linear-gradient(135deg,#3b82f6,#1e40af);color:#fff;}
.btn-blue:hover:not(:disabled){filter:brightness(1.1);}
.btn-red{background:linear-gradient(135deg,var(--red),#991b1b);color:#fff;}
.btn-red:hover:not(:disabled){filter:brightness(1.1);}
.btn-ghost{background:#f1f5f9;color:#374151;border:1px solid var(--border);}
.btn-ghost:hover:not(:disabled){background:#e5e7eb;}
.btn-amber{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;}
.btn-amber:hover:not(:disabled){filter:brightness(1.1);}
.cnt-badge{background:rgba(255,255,255,.25);color:#fff;font-size:10px;font-weight:700;padding:1px 7px;border-radius:10px;}
.hist-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.hist-tbl th{padding:8px 10px;background:#f9fafb;border-bottom:2px solid var(--border);font-size:10.5px;text-transform:uppercase;color:#374151;}
.hist-tbl td{padding:8px 10px;border-bottom:1px solid #f3f4f6;}
.prog-bar{height:6px;background:#e5e7eb;border-radius:4px;overflow:hidden;min-width:80px;}
.prog-fill2{height:100%;background:var(--green);border-radius:4px;}
.pagination{display:flex;justify-content:center;align-items:center;gap:4px;margin-top:20px;flex-wrap:wrap;}
.pag-item{padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;transition:all .2s;}
.pag-item:hover{background:#f1f5f9;}
.pag-item.active{background:var(--teal);color:#fff;border-color:var(--teal);}
.pag-item.disabled{opacity:.5;cursor:not-allowed;}
#toast{position:fixed;bottom:24px;right:24px;padding:11px 20px;border-radius:9px;font-size:13px;font-weight:600;color:#fff;box-shadow:0 4px 16px rgba(0,0,0,.18);z-index:9999;display:none;}
#toast.show{display:flex;align-items:center;gap:8px;}
#toast.ok{background:var(--green);}#toast.err{background:var(--red);}#toast.warn{background:var(--amber);color:#111;}
.spin{animation:spin .7s linear infinite;display:inline-block;}
@keyframes spin{to{transform:rotate(360deg)}}
.ai-raw{background:#0f172a;color:#7dd3fc;border-radius:8px;padding:12px 14px;font-family:monospace;font-size:11px;line-height:1.7;margin-top:10px;max-height:200px;overflow:auto;display:none;}
.ai-raw.show{display:block;}
.error-box{background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:12px 14px;color:#dc2626;font-size:12px;line-height:1.6;display:none;}
.error-box.show{display:block;}
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:10000;align-items:flex-start;justify-content:center;overflow-y:auto;padding:20px;}
.modal.show{display:flex;}
.modal-box{background:#fff;border-radius:12px;padding:24px;width:100%;max-width:900px;box-shadow:0 10px 40px rgba(0,0,0,.2);margin:auto;}
.modal-title{font-size:15px;font-weight:700;color:#111827;margin-bottom:8px;}
.modal-text{font-size:12px;color:#6b7280;margin-bottom:18px;line-height:1.5;}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;flex-wrap:wrap;}
/* ── View details table (v2.5 enhanced) ── */
.details-tbl{width:100%;border-collapse:collapse;font-size:11.5px;margin-top:15px;}
.details-tbl th{padding:8px 10px;background:#0f172a;color:#e2e8f0;text-align:left;font-weight:600;font-size:10.5px;text-transform:uppercase;white-space:nowrap;}
.details-tbl td{padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.details-tbl tr.row-reconciled{background:#f0fdf4;}
.details-tbl tr.row-unreconciled{background:#fff9f9;}
.details-tbl tr:hover td{background:#f0f9ff!important;}
.detail-inv-info{font-size:11px;color:#374151;}
.detail-inv-info strong{color:var(--green);}
.detail-select{width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:5px;font-size:11px;font-family:inherit;}
.detail-select:focus{border-color:var(--teal);outline:none;}
/* Summary bar inside modal */
.modal-sum-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
.modal-sum-item{flex:1;min-width:100px;background:#f9fafb;border:1px solid var(--border);border-radius:8px;padding:8px 12px;text-align:center;}
.modal-sum-item .label{font-size:10px;color:var(--txs);font-weight:700;text-transform:uppercase;}
.modal-sum-item .val{font-size:16px;font-weight:700;margin-top:2px;}
/* Bulk match panel (NEW v2.7) */
.bulk-check-row{display:flex;align-items:center;gap:8px;font-size:11.5px;padding:5px 7px;background:#fff;border:1px solid #fde68a;border-radius:6px;}
.bulk-check-row:hover{background:#fffbeb;}
/* Full report print styles */
@media print{
  .no-print{display:none!important;}
  .modal{position:static;background:none;padding:0;}
  .modal-box{box-shadow:none;max-width:100%;padding:10px;}
}
</style>

<div class="pw">
<div class="breadcrumb">
  <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
  <span class="sep">›</span>
  <a href="ushop_invoice_list.php"><i class="fa-solid fa-receipt"></i> Invoices</a>
  <span class="sep">›</span>
  <span style="color:var(--teal);font-weight:700;"><i class="fa-brands fa-cc-visa"></i> VISA Reconciliation</span>
</div>

<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;">
  <div>
    <h2 style="margin:0;font-size:19px;font-weight:700;color:#111827;">
      <i class="fa-brands fa-cc-visa" style="color:var(--teal);margin-right:8px;"></i>VISA Card Payment Reconciliation
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:var(--txs);">Upload BOC EDC settlement PDF → Gemini AI extracts transactions → match with VISA invoices → save.</p>
  </div>
 <div style="display:flex;gap:8px;">
    <a href="visa_reconciliation_status_report.php" class="btn btn-ghost" style="padding:6px 12px;font-size:11.5px;"><i class="fa-solid fa-chart-column"></i> Status Report</a>
    <a href="ushop_invoice_list.php" class="btn btn-ghost" style="padding:6px 12px;font-size:11.5px;"><i class="fa-solid fa-receipt"></i> Invoices</a>
    <a href="ushop_invoice_history.php" class="btn btn-ghost" style="padding:6px 12px;font-size:11.5px;"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
    <a href="ai_settings.php" class="btn btn-ghost" style="padding:6px 12px;font-size:11.5px;"><i class="fa-solid fa-key"></i> AI Settings</a>
  </div>
  </div>

<!-- Steps -->
<div class="steps-bar">
  <div class="step-item active" id="step1"><div class="step-num">1</div><div><div class="step-text">Upload PDF</div><div class="step-sub">Select BOC statement</div></div></div>
  <div class="step-item" id="step2"><div class="step-num">2</div><div><div class="step-text">AI Extract</div><div class="step-sub">Gemini reads transactions</div></div></div>
  <div class="step-item" id="step3"><div class="step-num">3</div><div><div class="step-text">DB Match</div><div class="step-sub">Match VISA invoices</div></div></div>
  <div class="step-item" id="step4"><div class="step-num">4</div><div><div class="step-text">Save</div><div class="step-sub">Reconcile &amp; update DB</div></div></div>
</div>

<!-- API Key Banner -->
<div class="api-banner warn" id="apiBanner">
  <i class="fa-solid fa-spinner spin"></i>
  <span>Checking Gemini API key… <a href="ai_settings.php">Configure API Key →</a></span>
</div>

<!-- STEP 1: Upload PDF -->
<div class="card" id="uploadCard">
  <div class="card-head">
    <div class="ch-icon" style="background:#cffafe;color:var(--teal);"><i class="fa-solid fa-file-pdf"></i></div>
    <div><div class="ch-title">Step 1 — Upload BOC EDC Settlement PDF</div><div class="ch-sub">Date format: YY-MM-DD (26-03-23 = 2026-03-23) will be auto-converted. PDF is rendered as image → sent to Gemini AI.</div></div>
  </div>
  <div class="card-body">
    <div class="upload-zone" id="dropZone" onclick="document.getElementById('pdfInput').click()">
      <i class="fa-solid fa-cloud-arrow-up"></i>
      <p style="margin:8px 0 4px;font-size:14px;font-weight:700;color:#0e7490;">Click to select BOC EDC Settlement PDF</p>
      <p style="font-size:12px;color:var(--txs);margin:0;">or drag &amp; drop · PDF files only</p>
    </div>
    <input type="file" id="pdfInput" accept=".pdf,application/pdf" style="display:none;">
    <div id="pdfName" style="display:none;margin-top:10px;font-size:12.5px;color:var(--teal);font-weight:600;text-align:center;"></div>

    <div class="prog-box" id="aiProgBox" style="margin-top:14px;">
      <div class="prog-top"><strong id="aiProgLabel">Extracting…</strong><span id="aiProgPct">0%</span></div>
      <div class="prog-bg"><div class="prog-fill" id="aiProgFill" style="width:0%"></div></div>
      <div class="prog-file" id="aiProgFile">Rendering PDF pages…</div>
    </div>

    <div id="errorBox" class="error-box"></div>
    <div class="ai-raw" id="aiRawOut"></div>

    <div style="margin-top:14px;display:flex;gap:8px;justify-content:center;">
      <button class="btn btn-teal" id="extractBtn" onclick="startExtraction()" disabled>
        <i class="fa-solid fa-robot"></i> Extract with Gemini AI
      </button>
      <button class="btn btn-ghost" onclick="resetAll()"><i class="fa-solid fa-rotate-left"></i> Reset</button>
    </div>
  </div>
</div>

<!-- STEP 2+3: Results -->
<div id="resultsSection" style="display:none;">
  <div class="sum-strip" id="sumStrip"></div>
  <div class="card">
    <div class="card-head">
      <div class="ch-icon" style="background:#dcfce7;color:var(--green);"><i class="fa-solid fa-code-compare"></i></div>
      <div><div class="ch-title">Step 3–4 — Transaction Matching &amp; Reconciliation</div><div class="ch-sub">Each BOC transaction is matched to a VISA invoice by date + amount. Adjust if needed, then save. Nothing is written to the database until you click Save.</div></div>
      <div style="margin-left:auto;display:flex;gap:8px;">
        <span id="matchSummaryBadge" style="font-size:12px;color:var(--txs);align-self:center;"></span>
        <button class="btn btn-ghost" style="padding:5px 10px;font-size:11px;" onclick="resetAll()"><i class="fa-solid fa-rotate-left"></i> New Upload</button>
      </div>
    </div>
    <div class="tbl-wrap">
      <table class="recon-tbl">
        <thead>
          <tr>
            <th>#</th><th>TRX Date</th><th>Card Number</th><th>Auth Code</th>
            <th class="tr">Amount (LKR)</th><th class="tr">Comm</th><th class="tr">Net</th>
            <th style="min-width:300px;">Matched Invoice</th><th class="tc">Status</th>
          </tr>
        </thead>
        <tbody id="reconBody"></tbody>
        <tfoot>
          <tr>
            <td colspan="4">TOTAL</td>
            <td class="tr" id="footAmt">0.00</td>
            <td class="tr" id="footComm">0.00</td>
            <td class="tr" id="footNet">0.00</td>
            <td colspan="2" id="footNote"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <div class="toolbar">
      <button class="btn btn-green" id="saveBtn" onclick="saveReconciliation()">
        <i class="fa-solid fa-floppy-disk"></i> Save Reconciliation
        <span class="cnt-badge" id="saveCntBadge">0</span>
      </button>
    </div>
  </div>
</div>

<!-- History -->
<div class="card">
  <div class="card-head">
    <div class="ch-icon" style="background:#f3f4f6;color:#6b7280;"><i class="fa-solid fa-clock-rotate-left"></i></div>
    <div><div class="ch-title">Reconciliation History (<?=$total?> total)</div><div class="ch-sub">Page <?=$page?> of <?=$total_pages?></div></div>
  </div>
  <div class="tbl-wrap">
    <table class="hist-tbl">
      <thead><tr><th>#</th><th>Date</th><th>MID</th><th>Location</th><th>Stmt Date</th><th class="tr">Amount</th><th class="tr">Comm</th><th class="tr">Net</th><th class="tc">Matched</th><th>Progress</th><th class="tc" style="min-width:140px;">Actions</th></tr></thead>
      <tbody>
      <?php $hi=$offset+1;while($h=mysqli_fetch_assoc($history)):
        $pct=$h['total_rows']>0?round(($h['matched_rows']/$h['total_rows'])*100):0;?>
      <tr>
        <td style="color:#9ca3af;font-size:11px;"><?=$hi++?></td>
        <td style="font-size:11.5px;white-space:nowrap;"><?=date('d M Y H:i',strtotime($h['created_at']))?></td>
        <td style="font-family:monospace;font-size:11px;"><span class="badge b-blue"><?=htmlspecialchars($h['mid']??'—')?></span></td>
        <td><span class="badge b-teal"><?=htmlspecialchars($h['location']??'—')?></span></td>
        <td><?=$h['statement_date']?date('d M Y',strtotime($h['statement_date'])):'—'?></td>
        <td class="tr" style="color:var(--green);font-weight:700;"><?=number_format($h['total_amount'],2)?></td>
        <td class="tr" style="color:var(--amber);"><?=number_format($h['total_comm'],2)?></td>
        <td class="tr" style="color:var(--teal);font-weight:700;"><?=number_format($h['total_net'],2)?></td>
        <td class="tc"><span class="badge <?=$pct==100?'b-green':($pct>0?'b-amber':'b-red')?>"><?=$h['matched_rows'].'/'.$h['total_rows']?></span></td>
        <td style="min-width:100px;"><div class="prog-bar"><div class="prog-fill2" style="width:<?=$pct?>%"></div></div><div style="font-size:10px;color:var(--txs);margin-top:2px;"><?=$pct?>%</div></td>
        <td class="tc">
          <button class="btn btn-ghost" style="padding:4px 8px;font-size:10.5px;" onclick="viewDetails(<?=$h['id']?>)"><i class="fa-solid fa-eye"></i> View</button>
          <button class="btn btn-ghost" style="padding:4px 8px;font-size:10.5px;" onclick="deleteRecon(<?=$h['id']?>)"><i class="fa-solid fa-trash"></i> Delete</button>
        </td>
      </tr>
      <?php endwhile;?>
      <?php if(mysqli_num_rows($history)===0):?><tr><td colspan="11" style="text-align:center;padding:30px;color:#9ca3af;">No reconciliations yet.</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
  <?php if($total_pages>1):?>
  <div class="pagination">
    <?php if($page>1):?>
      <a class="pag-item" href="?page=1"><i class="fa-solid fa-angle-double-left"></i></a>
      <a class="pag-item" href="?page=<?=$page-1?>"><i class="fa-solid fa-angle-left"></i></a>
    <?php else:?>
      <span class="pag-item disabled"><i class="fa-solid fa-angle-double-left"></i></span>
      <span class="pag-item disabled"><i class="fa-solid fa-angle-left"></i></span>
    <?php endif;?>
    <?php for($p=max(1,$page-2);$p<=min($total_pages,$page+2);$p++):?>
      <?php if($p==$page):?><span class="pag-item active"><?=$p?></span>
      <?php else:?><a class="pag-item" href="?page=<?=$p?>"><?=$p?></a><?php endif;?>
    <?php endfor;?>
    <?php if($page<$total_pages):?>
      <a class="pag-item" href="?page=<?=$page+1?>"><i class="fa-solid fa-angle-right"></i></a>
      <a class="pag-item" href="?page=<?=$total_pages?>"><i class="fa-solid fa-angle-double-right"></i></a>
    <?php else:?>
      <span class="pag-item disabled"><i class="fa-solid fa-angle-right"></i></span>
      <span class="pag-item disabled"><i class="fa-solid fa-angle-double-right"></i></span>
    <?php endif;?>
  </div>
  <?php endif;?>
</div>
</div><!-- .pw -->

<!-- ══ View Details Modal (v2.7 — adds Bulk Match) ══ -->
<div class="modal" id="viewModal">
  <div class="modal-box">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:4px;">
      <div class="modal-title"><i class="fa-solid fa-magnifying-glass" style="color:var(--teal);margin-right:8px;"></i>Reconciliation Details</div>
      <div style="display:flex;gap:8px;" class="no-print">
        <button class="btn btn-blue" style="padding:5px 12px;font-size:11px;" onclick="printFullReport()"><i class="fa-solid fa-print"></i> Full Report</button>
        <button class="btn btn-ghost" style="padding:5px 12px;font-size:11px;" onclick="closeViewModal()"><i class="fa-solid fa-xmark"></i> Close</button>
      </div>
    </div>
    <div id="viewContent" style="margin-top:12px;"></div>
    <div class="modal-actions no-print">
      <button class="btn btn-ghost" onclick="closeViewModal()">Close</button>
    </div>
  </div>
</div>

<!-- Delete Modal -->
<div class="modal" id="deleteModal">
  <div class="modal-box" style="max-width:500px;">
    <div class="modal-title"><i class="fa-solid fa-triangle-exclamation" style="color:var(--red);margin-right:6px;"></i>Delete Reconciliation?</div>
    <div class="modal-text">This will permanently delete the reconciliation and unreconcile all matched invoices (<span id="deleteCount">0</span> invoices). This cannot be undone.</div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeDeleteModal()">Cancel</button>
      <button class="btn btn-red" onclick="confirmDelete()"><i class="fa-solid fa-trash"></i> Delete</button>
    </div>
  </div>
</div>

<div id="toast"></div>

<!-- PDF.js CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
'use strict';
pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

/* Clean base URL — strips ?page=N so AJAX calls never send page param
   which causes PHP to output full HTML instead of JSON */
const AJAX_URL = window.location.pathname;

/* Safely parse JSON from a fetch Response — throws with raw preview on failure */
async function safeJson(res){
  const txt = await res.text();
  try{ return JSON.parse(txt); }
  catch(e){
    const preview = txt.substring(0,300);
    throw new Error('Server returned non-JSON (possible PHP error):\n'+preview);
  }
}

let API_KEY = '';
let currentPdfFile = null;
let extractedRows  = [];
let matchedResults = [];
let extractedHeader = {};
let deleteReconId = null;
let currentViewReconId = null; // track which reconciliation is open in modal

/* ── Load API key ── */
(async()=>{
  try{
    const d=await fetch(AJAX_URL+'?ajax=get_api_key').then(safeJson);
    const b=document.getElementById('apiBanner');
    if(d.has_key&&d.key){
      API_KEY=d.key;
      b.className='api-banner ok';
      b.innerHTML=`<i class="fa-solid fa-circle-check"></i><span>✓ Gemini API ready to extract</span>`;
    }else{
      b.className='api-banner warn';
      b.innerHTML='<i class="fa-solid fa-triangle-exclamation"></i><span>No Gemini API key. <a href="ai_settings.php">Configure →</a></span>';
    }
  }catch(e){}
})();

/* ── File input ── */
const dropZone=document.getElementById('dropZone');
const pdfInput=document.getElementById('pdfInput');
dropZone.addEventListener('dragover',e=>{e.preventDefault();dropZone.classList.add('drag');});
dropZone.addEventListener('dragleave',()=>dropZone.classList.remove('drag'));
dropZone.addEventListener('drop',e=>{
  e.preventDefault();dropZone.classList.remove('drag');
  const f=e.dataTransfer.files[0];
  if(f&&f.type==='application/pdf')setPdf(f);
  else toast('Please select a PDF file','err');
});
pdfInput.addEventListener('change',function(){if(this.files[0])setPdf(this.files[0]);});

function setPdf(file){
  currentPdfFile=file;
  const nm=document.getElementById('pdfName');
  nm.style.display='block';
  nm.innerHTML=`<i class="fa-solid fa-file-pdf" style="color:#dc2626;"></i> ${esc(file.name)} <span style="color:#9ca3af;font-size:11px;">(${(file.size/1024).toFixed(0)} KB)</span>`;
  document.getElementById('extractBtn').disabled=!API_KEY;
  clearError();
  setStep(1);
}

/* ── Render PDF page to base64 image ── */
async function pdfPageToBase64(file, pageNum=1, scale=2.5){
  const arrayBuffer=await file.arrayBuffer();
  const pdf=await pdfjsLib.getDocument({data:arrayBuffer}).promise;
  const page=await pdf.getPage(pageNum);
  const viewport=page.getViewport({scale});
  const canvas=document.createElement('canvas');
  canvas.width=viewport.width; canvas.height=viewport.height;
  const ctx=canvas.getContext('2d');
  await page.render({canvasContext:ctx,viewport}).promise;
  return{
    b64: canvas.toDataURL('image/jpeg',0.92).split(',')[1],
    mime:'image/jpeg',
    totalPages: pdf.numPages,
  };
}

/* ── Call Gemini with retry logic ── */
async function callGemini(b64, mime, retries=3){
  const prompt=`This is a Bank of Ceylon BOC Card Centre EDC Settlement statement.
Extract ALL transaction rows from the table.
Also extract: MID number (like 3-0001-5411-1408), LOCATION, statement date, and BILLING TOTAL.

Transaction table columns: TRX DATE | CARD NUMBER | TERMINAL | AUTH CODE | AMOUNT | COMM | NET

IMPORTANT: For dates in format YY-MM-DD (like 26-03-23):
- Convert to YYYY-MM-DD (26-03-23 becomes 2026-03-23)
- Do this for trx_date and statement_date
- Assume all 2-digit years starting with 2 = 20xx (so 26 = 2026)

Return ONLY valid JSON, no markdown, no code fences:
{
  "mid": "3-0001-5411-1408",
  "location": "HORANA",
  "statement_date": "2026-03-10",
  "billing_total": 8656.85,
  "transactions": [
    {"trx_date": "2026-03-10", "card_number": "419907******1192", "terminal": "12000280", "auth_code": "916260", "amount": 2360.94, "comm": 59.02, "net": 2301.92}
  ]
}

Return ALL transaction rows found.`;

  try{
    const res=await fetch('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',{
      method:'POST',
      headers:{'Content-Type':'application/json','x-goog-api-key':API_KEY},
      body:JSON.stringify({
        contents:[{parts:[{inline_data:{mime_type:mime,data:b64}},{text:prompt}]}],
        generationConfig:{maxOutputTokens:8000,temperature:0}
      })
    });

    if(res.status===429){
      if(retries>0){
        console.warn(`Rate limited, retrying... (${3-retries+1}/3)`);
        await sleep(3000);
        return callGemini(b64,mime,retries-1);
      }
      throw new Error('API rate limited (max retries exceeded)');
    }

    if(!res.ok){
      const e=await res.json().catch(()=>({}));
      throw new Error(e?.error?.message||'HTTP '+res.status);
    }

    const data=await res.json();
    return (data?.candidates?.[0]?.content?.parts?.[0]?.text||'').trim();
  }catch(e){
    throw new Error('API error: '+e.message);
  }
}

/* ── Robust JSON parser ── */
function parseJsonResponse(rawText) {
  if(!rawText||typeof rawText!=='string')throw new Error('No response text received');
  try {return JSON.parse(rawText);}catch(e){}
  let clean=rawText.replace(/^```json\s*/i,'').replace(/^```\s*/i,'').replace(/```\s*$/,'').trim();
  try {return JSON.parse(clean);}catch(e){}
  const jsonStart=clean.search(/{\s*"/);
  const jsonEnd=clean.lastIndexOf('}');
  if(jsonStart!==-1&&jsonEnd>jsonStart){
    const extracted=clean.substring(jsonStart,jsonEnd+1);
    try {return JSON.parse(extracted);}catch(e){}
  }
  const start=clean.indexOf('{');
  const end=clean.lastIndexOf('}');
  if(start>-1&&end>start){
    const slice=clean.substring(start,end+1);
    try {return JSON.parse(slice);}catch(e){}
  }
  throw new Error('Could not extract valid JSON. Response may be incomplete or malformed.');
}

/* ── Normalize dates ── */
function normalizeDate(dateStr) {
  if(!dateStr)return null;
  const str=String(dateStr).trim();
  if(/^\d{4}-\d{2}-\d{2}$/.test(str))return str;
  if(/^\d{2}-\d{2}-\d{2}$/.test(str)){
    const [yy,mm,dd]=str.split('-');
    return `20${yy}-${mm}-${dd}`;
  }
  try{const d=new Date(str);if(!isNaN(d))return d.toISOString().split('T')[0];}catch(e){}
  return str;
}

function clearError(){document.getElementById('errorBox').classList.remove('show');}
function showError(title,message){
  const errorBox=document.getElementById('errorBox');
  errorBox.innerHTML=`<strong><i class="fa-solid fa-circle-exclamation"></i> ${esc(title)}</strong><br>${message}`;
  errorBox.classList.add('show');
}

/* ── Main extraction flow ── */
async function startExtraction(){
  if(!currentPdfFile){toast('Please select a PDF first','err');return;}
  if(!API_KEY){toast('No Gemini API key configured','err');return;}

  clearError();
  const btn=document.getElementById('extractBtn');
  btn.disabled=true;
  btn.innerHTML='<i class="fa-solid fa-spinner spin"></i> Extracting…';
  setStep(2);

  const progBox=document.getElementById('aiProgBox');
  progBox.classList.add('show');
  document.getElementById('aiProgFill').style.width='10%';
  document.getElementById('aiProgLabel').textContent='Rendering PDF…';
  document.getElementById('aiProgFile').textContent='Converting PDF to image…';

  try{
    const {b64,mime,totalPages}=await pdfPageToBase64(currentPdfFile,1,2.5);
    document.getElementById('aiProgFill').style.width='40%';
    document.getElementById('aiProgLabel').textContent='Sending to Gemini…';
    document.getElementById('aiProgFile').textContent=`PDF page 1/${totalPages} rendered → sending to Gemini…`;

    const rawText=await callGemini(b64,mime);
    document.getElementById('aiProgFill').style.width='75%';
    document.getElementById('aiProgLabel').textContent='Parsing JSON…';

    const rawBox=document.getElementById('aiRawOut');
    rawBox.classList.add('show');
    rawBox.textContent=rawText.substring(0,600)+(rawText.length>600?'\n…(truncated)':'');

    let parsed;
    try {parsed=parseJsonResponse(rawText);}
    catch(e){
      showError('JSON Parse Error',`${e.message}<br><br><strong>Raw Response (first 300 chars):</strong><br><code style="font-size:10px;color:#666;">${esc(rawText.substring(0,300))}</code>`);
      btn.innerHTML='<i class="fa-solid fa-robot"></i> Extract with Gemini AI';
      btn.disabled=false;
      return;
    }

    if(!parsed.transactions||!Array.isArray(parsed.transactions)||!parsed.transactions.length){
      showError('No Transactions Found','The AI could not extract any transaction rows. Ensure the PDF is a valid BOC EDC statement with visible transaction table.');
      btn.innerHTML='<i class="fa-solid fa-robot"></i> Extract with Gemini AI';
      btn.disabled=false;
      return;
    }

    parsed.statement_date=normalizeDate(parsed.statement_date);
    parsed.transactions=parsed.transactions.map(t=>({...t,trx_date:normalizeDate(t.trx_date)}));

    document.getElementById('aiProgFill').style.width='90%';
    document.getElementById('aiProgLabel').textContent='Matching with database…';
    document.getElementById('aiProgFile').textContent=`Found ${parsed.transactions.length} transactions → querying database…`;

    await matchWithDb(parsed);

    document.getElementById('aiProgFill').style.width='100%';
    document.getElementById('aiProgLabel').textContent=`✓ Success — ${parsed.transactions.length} transactions`;
    document.getElementById('aiProgFile').textContent='Review matches below, then click Save Reconciliation';
    btn.innerHTML='<i class="fa-solid fa-robot"></i> Re-Extract';
    btn.disabled=false;
    setStep(3);

  }catch(e){
    console.error('Extraction error:',e);
    document.getElementById('aiProgFill').style.width='0%';
    document.getElementById('aiProgLabel').textContent='Failed';
    document.getElementById('aiProgFile').textContent='Check error message';
    btn.innerHTML='<i class="fa-solid fa-robot"></i> Extract with Gemini AI';
    btn.disabled=false;
  }
}

/* ── Match with database (READ-ONLY) ── */
async function matchWithDb(parsed){
  try {
    const fd=new FormData();
    fd.append('ajax_action','match_transactions');
    fd.append('rows',JSON.stringify(parsed.transactions));

    const res=await fetch(AJAX_URL,{method:'POST',body:fd});
    const text=await res.text();
    let d;
    try{ d=JSON.parse(text); }
    catch(e){ throw new Error('Server returned invalid response (check PHP error log). Raw: '+text.substring(0,200)); }
    if(!d.success)throw new Error(d.error||'DB match failed');

    extractedHeader={
      filename: currentPdfFile.name,
      mid: parsed.mid||'',
      location: parsed.location||'',
      statement_date: parsed.statement_date||'',
      billing_total: parsed.billing_total||0
    };

    matchedResults=d.results.map(res=>({trx:res.trx,matches:res.matches}));

    let ta=0,tc=0,tn=0;
    parsed.transactions.forEach(t=>{ta+=parseFloat(t.amount)||0;tc+=parseFloat(t.comm)||0;tn+=parseFloat(t.net)||0;});

    renderSumStrip(parsed,ta,tc,tn);
    renderReconTable();
    document.getElementById('resultsSection').style.display='block';
    setTimeout(()=>document.getElementById('resultsSection').scrollIntoView({behavior:'smooth'}),200);
  } catch(e) {
    showError('Database Error', e.message);
    throw e;
  }
}

/* ── Render summary ── */
function renderSumStrip(parsed,ta,tc,tn){
  const autoMatched=matchedResults.filter(m=>m.matches.length===1).length;
  document.getElementById('sumStrip').innerHTML=`
    <div class="sum-box"><div class="sum-box-label">MID / Location</div><div class="sum-box-val" style="font-size:12px;">${esc(parsed.mid||'—')} / ${esc(parsed.location||'—')}</div></div>
    <div class="sum-box"><div class="sum-box-label">Statement Date</div><div class="sum-box-val">${fmtDate(parsed.statement_date)}</div></div>
    <div class="sum-box"><div class="sum-box-label">Transactions</div><div class="sum-box-val">${matchedResults.length}</div></div>
    <div class="sum-box"><div class="sum-box-label">Auto Matched</div><div class="sum-box-val" style="color:var(--green);">${autoMatched}</div></div>
    <div class="sum-box"><div class="sum-box-label">Total Amount</div><div class="sum-box-val">${fmtNum(ta)}</div></div>
    <div class="sum-box"><div class="sum-box-label">Commission</div><div class="sum-box-val" style="color:var(--amber);">${fmtNum(tc)}</div></div>
    <div class="sum-box"><div class="sum-box-label">Billing Total</div><div class="sum-box-val">${fmtNum(tn)}</div></div>
  `;
}

/* ── Render table ── */
function renderReconTable(){
  const tbody=document.getElementById('reconBody');
  let totalAmt=0,totalComm=0,totalNet=0;

  tbody.innerHTML=matchedResults.map((mr,idx)=>{
    const t=mr.trx;
    totalAmt+=parseFloat(t.amount)||0;totalComm+=parseFloat(t.comm)||0;totalNet+=parseFloat(t.net)||0;
    const autoMatch=mr.matches.length===1;
    const hasMatches=mr.matches.length>0;

    let opts=`<option value="">— No match —</option>`;
    mr.matches.forEach(inv=>{
      opts+=`<option value="${inv.id}" ${autoMatch?'selected':''}>${esc(inv.doc_no)} | ${esc(inv.customer_name||'Walk-in')} | ${fmtNum(parseFloat(inv.total_amount))} | ${inv.invoice_date}${inv.reconciled=='1'?' ⚠ Reconciled':''}</option>`;
    });

    const statusBadge=autoMatch
      ?'<span class="badge b-green"><i class="fa-solid fa-check"></i> Auto-matched</span>'
      :hasMatches
        ?'<span class="badge b-amber"><i class="fa-solid fa-circle-half-stroke"></i> Multiple</span>'
        :'<span class="badge b-red"><i class="fa-solid fa-xmark"></i> No match</span>';

    return `<tr>
      <td style="color:#9ca3af;font-size:11px;">${idx+1}</td>
      <td><span class="badge b-teal">${fmtDate(t.trx_date)}</span></td>
      <td style="font-family:monospace;font-size:11px;">${esc(t.card_number)}</td>
      <td><span class="badge b-blue">${esc(t.auth_code)}</span></td>
      <td class="tr">${fmtNum(t.amount)}</td>
      <td class="tr">${fmtNum(t.comm)}</td>
      <td class="tr">${fmtNum(t.net)}</td>
      <td><select class="match-select ${hasMatches?'matched':''}" id="sel_${idx}" onchange="onSelChange(${idx},this)">${opts}</select></td>
      <td class="tc" id="st_${idx}">${statusBadge}</td>
    </tr>`;
  });

  document.getElementById('footAmt').textContent=fmtNum(totalAmt);
  document.getElementById('footComm').textContent=fmtNum(totalComm);
  document.getElementById('footNet').textContent=fmtNum(totalNet);
  updateSaveBtn();
}

function onSelChange(idx,sel){
  const el=document.getElementById('st_'+idx);
  const matched=sel.value!=='';
  sel.className='match-select'+(matched?' matched':'');
  if(el)el.innerHTML=matched?'<span class="badge b-green"><i class="fa-solid fa-check"></i> Matched</span>':'<span class="badge b-red"><i class="fa-solid fa-xmark"></i> No match</span>';
  updateSaveBtn();
}

function updateSaveBtn(){
  let cnt=0;
  matchedResults.forEach((_,i)=>{const s=document.getElementById('sel_'+i);if(s&&s.value)cnt++;});
  document.getElementById('saveCntBadge').textContent=cnt+'/'+matchedResults.length;
  document.getElementById('saveBtn').disabled=false;
  document.getElementById('matchSummaryBadge').textContent=`${cnt} / ${matchedResults.length} matched`;
  setStep(cnt>0?4:3);
}

/* ── Save reconciliation ── */
async function saveReconciliation(){
  if(!matchedResults.length){toast('Nothing to save','err');return;}

  let ta=0,tc=0,tn=0;
  const txData=matchedResults.map((mr,i)=>{
    const s=document.getElementById('sel_'+i);
    const invoiceId=(s&&s.value)?parseInt(s.value,10):null;
    ta+=parseFloat(mr.trx.amount)||0;tc+=parseFloat(mr.trx.comm)||0;tn+=parseFloat(mr.trx.net)||0;
    return{
      trx_date:mr.trx.trx_date,card_number:mr.trx.card_number,terminal:mr.trx.terminal,
      auth_code:mr.trx.auth_code,amount:mr.trx.amount,comm:mr.trx.comm,net:mr.trx.net,
      invoice_id:invoiceId
    };
  });

  if(!txData.length){toast('Nothing to save','err');return;}

  const btn=document.getElementById('saveBtn');
  btn.disabled=true;
  btn.innerHTML='<i class="fa-solid fa-spinner spin"></i> Saving…';

  const fd=new FormData();
  fd.append('ajax_action','save_full_reconciliation');
  fd.append('filename',extractedHeader.filename||(currentPdfFile?currentPdfFile.name:''));
  fd.append('mid',extractedHeader.mid||'');
  fd.append('location',extractedHeader.location||'');
  fd.append('statement_date',extractedHeader.statement_date||'');
  fd.append('billing_total',extractedHeader.billing_total||0);
  fd.append('total_amount',ta);
  fd.append('total_comm',tc);
  fd.append('total_net',tn);
  fd.append('rows',JSON.stringify(txData));

  try{
    const res=await fetch(AJAX_URL,{method:'POST',body:fd});
    const text=await res.text();
    let d;
    try{ d=JSON.parse(text); }
    catch(e){ throw new Error('Server returned invalid response. Raw: '+text.substring(0,200)); }

    if(d.success){
      clearError();
      toast(`✓ Saved ${d.total_rows} row(s) — ${d.saved} invoice(s) reconciled${d.total_rows-d.saved>0?' · '+(d.total_rows-d.saved)+' unmatched (can reconcile later)':''}!`,'ok');
      btn.innerHTML=`<i class="fa-solid fa-check"></i> Saved`;
      setStep(4);
      setTimeout(()=>location.reload(),1800);
    }else throw new Error(d.error||'Save failed');
  }catch(e){
    toast('Error: '+e.message,'err');
    btn.disabled=false;
    btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Reconciliation';
  }
}

/* ══════════════════════════════════════════════
   VIEW DETAILS (v2.7 — reconciled / unreconciled / bulk)
══════════════════════════════════════════════ */
function viewDetails(rid){
  currentViewReconId = rid;
  const modal = document.getElementById('viewModal');
  const content = document.getElementById('viewContent');
  content.innerHTML = '<p style="text-align:center;padding:30px;"><i class="fa-solid fa-spinner spin" style="font-size:22px;color:var(--teal);"></i><br><span style="font-size:12px;color:#9ca3af;margin-top:8px;display:block;">Loading reconciliation data…</span></p>';
  modal.classList.add('show');
  loadViewDetails(rid, content);
}

async function loadViewDetails(rid, content){
  try{
    const res = await fetch(AJAX_URL+'?ajax=view_details&recon_id='+rid);
    const rawText = await res.text();
    let d;
    try{
      d = JSON.parse(rawText);
    }catch(e){
      content.innerHTML=`<div style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:14px;color:#dc2626;font-size:12px;">
        <strong><i class="fa-solid fa-circle-exclamation"></i> JSON Parse Error</strong><br>
        The server returned an unexpected response (possible PHP error or page redirect).<br><br>
        <strong>Raw response (first 500 chars):</strong><br>
        <pre style="background:#0f172a;color:#7dd3fc;border-radius:6px;padding:10px;font-size:10.5px;overflow:auto;max-height:180px;white-space:pre-wrap;">${esc(rawText.substring(0,500))}</pre>
      </div>`;
      return;
    }
    if(!d.success){content.innerHTML='<p style="color:var(--red);padding:16px;">Error: '+esc(d.error)+'</p>';return;}

    const h = d.header;
    const rows = d.rows;
    const reconciled = rows.filter(r=>r.matched=='1'||r.matched===1).length;
    const unreconciled = rows.length - reconciled;

    let html = `
    <!-- Header summary -->
    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;padding:14px;background:#f8fafc;border-radius:9px;border:1px solid var(--border);">
      <div style="flex:1;min-width:130px;">
        <div style="font-size:10px;color:var(--txs);font-weight:700;text-transform:uppercase;">MID</div>
        <div style="font-size:13px;font-weight:700;color:var(--teal);margin-top:2px;">${esc(h.mid||'—')}</div>
      </div>
      <div style="flex:1;min-width:130px;">
        <div style="font-size:10px;color:var(--txs);font-weight:700;text-transform:uppercase;">Location</div>
        <div style="font-size:13px;font-weight:700;color:var(--tx);margin-top:2px;">${esc(h.location||'—')}</div>
      </div>
      <div style="flex:1;min-width:130px;">
        <div style="font-size:10px;color:var(--txs);font-weight:700;text-transform:uppercase;">Statement Date</div>
        <div style="font-size:13px;font-weight:700;color:var(--tx);margin-top:2px;">${fmtDate(h.statement_date)}</div>
      </div>
      <div style="flex:1;min-width:130px;">
        <div style="font-size:10px;color:var(--txs);font-weight:700;text-transform:uppercase;">Total Amount</div>
        <div style="font-size:13px;font-weight:700;color:var(--green);margin-top:2px;">${fmtNum(h.total_amount)}</div>
      </div>
      <div style="flex:1;min-width:130px;">
        <div style="font-size:10px;color:var(--txs);font-weight:700;text-transform:uppercase;">Commission</div>
        <div style="font-size:13px;font-weight:700;color:var(--amber);margin-top:2px;">${fmtNum(h.total_comm)}</div>
      </div>
      <div style="flex:1;min-width:130px;">
        <div style="font-size:10px;color:var(--txs);font-weight:700;text-transform:uppercase;">Net</div>
        <div style="font-size:13px;font-weight:700;color:var(--teal);margin-top:2px;">${fmtNum(h.total_net)}</div>
      </div>
    </div>

    <!-- Match summary bar -->
    <div class="modal-sum-bar">
      <div class="modal-sum-item">
        <div class="label">Total Rows</div>
        <div class="val" style="color:var(--teal);">${rows.length}</div>
      </div>
      <div class="modal-sum-item">
        <div class="label">✓ Reconciled</div>
        <div class="val" style="color:var(--green);">${reconciled}</div>
      </div>
      <div class="modal-sum-item">
        <div class="label">✗ Unreconciled</div>
        <div class="val" style="color:var(--red);">${unreconciled}</div>
      </div>
      <div class="modal-sum-item">
        <div class="label">Match Rate</div>
        <div class="val" style="color:${reconciled===rows.length?'var(--green)':unreconciled>0?'var(--amber)':'var(--red)'};">${rows.length?Math.round(reconciled/rows.length*100):0}%</div>
      </div>
    </div>`;

    /* ── Table ── */
    html += `<div style="overflow-x:auto;"><table class="details-tbl">
      <thead>
        <tr>
          <th>#</th>
          <th>TRX Date</th>
          <th>Card Number</th>
          <th>Auth Code</th>
          <th style="text-align:right;">Amount</th>
          <th style="text-align:right;">Comm</th>
          <th style="text-align:right;">Net</th>
          <th>Invoice / Match</th>
          <th style="text-align:center;">Status</th>
          <th style="text-align:center;" class="no-print">Action</th>
        </tr>
      </thead>
      <tbody id="detailsBody">`;

    rows.forEach((row,idx)=>{
      const isRec = row.matched=='1'||row.matched===1;
      const rowClass = isRec ? 'row-reconciled' : 'row-unreconciled';

      /* Invoice info cell */
      let invCell = '';
      if(isRec){
        if(row.bulk_invoices && row.bulk_invoices.length > 1){
          /* NEW v2.7: this row was bulk-linked to several invoices */
          let listHtml = row.bulk_invoices.map(bi=>`<div style="margin-top:2px;">• <strong>${esc(bi.doc_no)}</strong> — ${esc(bi.customer_name||'Walk-in')} — ${fmtNum(bi.total_amount)}</div>`).join('');
          invCell = `<div class="detail-inv-info">
            <strong><i class="fa-solid fa-layer-group" style="color:var(--green);"></i> ${row.bulk_invoices.length} invoices (bulk-linked)</strong>
            ${listHtml}
          </div>`;
        } else {
          invCell = `<div class="detail-inv-info">
            <strong><i class="fa-solid fa-check-circle" style="color:var(--green);"></i> ${esc(row.doc_no||'—')}</strong>
            <div style="color:var(--txs);margin-top:2px;">${esc(row.customer_name||'Walk-in')} · ${fmtNum(row.inv_amount)}</div>
            <div style="color:var(--txs);">${row.invoice_date||''}</div>
          </div>`;
        }
      } else {
        /* Build candidate dropdown */
        let hasCandidates = row.candidates && row.candidates.length > 0;
        let opts = `<option value="">— Select invoice —</option>`;
        if(hasCandidates){
          row.candidates.forEach(c=>{
            opts += `<option value="${c.id}">${esc(c.doc_no)} | ${esc(c.customer_name||'Walk-in')} | ${fmtNum(c.total_amount)}${c.reconciled=='1'?' ⚠':''}</option>`;
          });
        }
        if(hasCandidates){
          invCell = `<select class="detail-select" id="drec_${row.id}" style="margin-bottom:4px;">${opts}</select>`;
        } else {
          /* Manual search box for rows with no auto-candidates */
          invCell = `
            <div id="searchbox_${row.id}">
              <div style="display:flex;gap:4px;margin-bottom:4px;">
                <input type="text" id="sinput_${row.id}" placeholder="Doc no / customer / code…"
                  style="flex:1;padding:4px 7px;border:1px solid #d1d5db;border-radius:5px;font-size:11px;font-family:inherit;"
                  onkeydown="if(event.key==='Enter')searchInvoices(${row.id},${rid},${parseFloat(row.amount)||0})">
                <button class="btn btn-teal no-print" style="padding:3px 9px;font-size:11px;"
                  onclick="searchInvoices(${row.id},${rid},${parseFloat(row.amount)||0})">
                  <i class="fa-solid fa-magnifying-glass"></i>
                </button>
              </div>
              <div id="sresults_${row.id}"></div>
            </div>`;
        }
      }

      const statusBadge = isRec
        ? `<span class="badge b-green"><i class="fa-solid fa-check"></i> Reconciled</span>`
        : `<span class="badge b-red"><i class="fa-solid fa-xmark"></i> Unreconciled</span>`;

      /* Action button */
      let actionCell = '';
      if(isRec){
        actionCell = `<button class="btn btn-ghost no-print" style="padding:3px 8px;font-size:10.5px;color:var(--red);"
          onclick="unreconcileRow(${row.id},${rid},'dtrow_${row.id}')" title="Un-reconcile this row">
          <i class="fa-solid fa-link-slash"></i> Unlink
        </button>`;
      } else {
        const hasCand = row.candidates && row.candidates.length > 0;
        const singleBtn = hasCand
          ? `<button class="btn btn-green no-print" style="padding:3px 8px;font-size:10.5px;"
              onclick="reconcileRow(${row.id},${rid},'dtrow_${row.id}')">
              <i class="fa-solid fa-link"></i> Reconcile
            </button>`
          : `<span id="recbtn_${row.id}"></span>`; /* filled in after manual search selects an invoice */

        /* NEW v2.7: Bulk button — link several same-date invoices to this one bank row */
        const bulkBtn = `<button class="btn btn-amber no-print" style="padding:3px 8px;font-size:10.5px;"
            onclick="toggleBulkPanel(${row.id})" title="Match multiple invoices that together total this amount">
            <i class="fa-solid fa-layer-group"></i> Bulk
          </button>`;

        actionCell = `<div style="display:flex;flex-direction:column;gap:4px;align-items:center;">${singleBtn}${bulkBtn}</div>`;
      }

      html += `<tr class="${rowClass}" id="dtrow_${row.id}">
        <td style="color:#9ca3af;font-size:11px;">${idx+1}</td>
        <td><span class="badge b-teal">${fmtDate(row.trx_date)}</span></td>
        <td style="font-family:monospace;font-size:10.5px;">${esc(row.card_number)}</td>
        <td><span class="badge b-blue">${esc(row.auth_code)}</span></td>
        <td style="text-align:right;">${fmtNum(row.amount)}</td>
        <td style="text-align:right;color:var(--amber);">${fmtNum(row.comm)}</td>
        <td style="text-align:right;color:var(--teal);">${fmtNum(row.net)}</td>
        <td style="min-width:220px;" id="invcell_${row.id}">${invCell}</td>
        <td style="text-align:center;" id="statcell_${row.id}">${statusBadge}</td>
        <td style="text-align:center;">${actionCell}</td>
      </tr>`;

      /* NEW v2.7: hidden Bulk Match panel row, lazy-loaded on first toggle */
      if(!isRec){
        html += `<tr id="bulkrow_${row.id}" style="display:none;" data-recon-id="${rid}" data-trx-date="${row.trx_date}" data-amount="${parseFloat(row.amount)||0}" data-loaded="0">
          <td colspan="10" style="background:#fffbeb;border-bottom:2px solid #fde68a;padding:12px 16px;">
            <div style="font-size:11.5px;color:var(--amber);font-weight:700;margin-bottom:8px;">
              <i class="fa-solid fa-layer-group"></i> Bulk Match — select multiple invoices from ${fmtDate(row.trx_date)} that together total ${fmtNum(row.amount)}
            </div>
            <div id="bulklist_${row.id}" style="font-size:11.5px;color:var(--txs);">Click "Bulk" again to load invoices…</div>
            <div style="margin-top:10px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
              <div style="font-size:12px;">Selected: <strong id="bulkcount_${row.id}">0</strong></div>
              <div style="font-size:12px;">Total: <strong id="bulksum_${row.id}">0.00</strong></div>
              <div style="font-size:12px;">Diff: <strong id="bulkdiff_${row.id}">${fmtNum(row.amount)}</strong></div>
              <button class="btn btn-green no-print" style="padding:4px 12px;font-size:11px;" onclick="confirmBulkReconcile(${row.id})">
                <i class="fa-solid fa-link"></i> Reconcile Selected
              </button>
              <button class="btn btn-ghost no-print" style="padding:4px 12px;font-size:11px;" onclick="toggleBulkPanel(${row.id})">
                <i class="fa-solid fa-xmark"></i> Close
              </button>
            </div>
          </td>
        </tr>`;
      }
    });

    html += `</tbody></table></div>`;
    content.innerHTML = html;

  }catch(e){
    content.innerHTML=`<p style="color:var(--red);padding:16px;">Error loading details: ${esc(e.message)}</p>`;
    console.error(e);
  }
}

/* ── Search invoices manually for re-reconcile (v2.6) ── */
async function searchInvoices(rowId, reconId, amount){
  const input = document.getElementById('sinput_'+rowId);
  const resultsDiv = document.getElementById('sresults_'+rowId);
  if(!input||!resultsDiv) return;

  const q = input.value.trim();
  resultsDiv.innerHTML = '<span style="font-size:11px;color:var(--txs);"><i class="fa-solid fa-spinner spin"></i> Searching…</span>';

  const fd = new FormData();
  fd.append('ajax_action','search_invoices');
  fd.append('q', q);
  fd.append('amount', amount);

  try{
    const d = await fetch(AJAX_URL,{method:'POST',body:fd}).then(safeJson);
    if(!d.success){resultsDiv.innerHTML=`<span style="font-size:11px;color:var(--red);">${esc(d.error)}</span>`;return;}
    if(!d.invoices.length){
      resultsDiv.innerHTML='<span style="font-size:11px;color:var(--txs);">No VISA invoices found.</span>';
      return;
    }

    let opts = `<option value="">— Select invoice —</option>`;
    d.invoices.forEach(inv=>{
      opts+=`<option value="${inv.id}">${esc(inv.doc_no)} | ${esc(inv.customer_name||'Walk-in')} | ${fmtNum(inv.total_amount)} | ${inv.invoice_date}${inv.reconciled=='1'?' ⚠ Already reconciled':''}</option>`;
    });

    resultsDiv.innerHTML = `
      <select class="detail-select" id="drec_${rowId}" style="margin-bottom:4px;">${opts}</select>
      <button class="btn btn-green no-print" style="padding:3px 9px;font-size:11px;margin-top:3px;"
        onclick="reconcileRow(${rowId},${reconId},'dtrow_${rowId}')">
        <i class="fa-solid fa-link"></i> Reconcile
      </button>`;
  }catch(e){
    resultsDiv.innerHTML=`<span style="font-size:11px;color:var(--red);">Error: ${esc(e.message)}</span>`;
  }
}

/* ── Reconcile a single row from modal ── */
async function reconcileRow(rowId, reconId, trRowId){
  const sel = document.getElementById('drec_'+rowId);
  if(!sel||!sel.value){toast('Please select an invoice first','warn');return;}
  const invId = parseInt(sel.value,10);

  const btn = document.querySelector(`#${trRowId} .btn-green`);
  if(btn){btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner spin"></i>';}

  const fd = new FormData();
  fd.append('ajax_action','reconcile_row');
  fd.append('row_id', rowId);
  fd.append('invoice_id', invId);
  fd.append('recon_id', reconId);

  try{
    const d = await fetch(AJAX_URL,{method:'POST',body:fd}).then(safeJson);
    if(!d.success) throw new Error(d.error||'Failed');

    toast('✓ Row reconciled successfully','ok');
    /* Refresh modal content */
    const content = document.getElementById('viewContent');
    content.innerHTML = '<p style="text-align:center;padding:20px;"><i class="fa-solid fa-spinner spin"></i></p>';
    loadViewDetails(reconId, content);
  }catch(e){
    toast('Error: '+e.message,'err');
    if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-link"></i> Reconcile';}
  }
}

/* ── Un-reconcile a single row from modal ── */
async function unreconcileRow(rowId, reconId, trRowId){
  if(!confirm('Un-reconcile this transaction? The linked invoice(s) will be marked as unreconciled.'))return;

  const btn = document.querySelector(`#${trRowId} .btn-ghost`);
  if(btn){btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner spin"></i>';}

  const fd = new FormData();
  fd.append('ajax_action','unreconcile_row');
  fd.append('row_id', rowId);
  fd.append('recon_id', reconId);

  try{
    const d = await fetch(AJAX_URL,{method:'POST',body:fd}).then(safeJson);
    if(!d.success) throw new Error(d.error||'Failed');

    toast('Row unreconciled','warn');
    /* Refresh modal content */
    const content = document.getElementById('viewContent');
    content.innerHTML = '<p style="text-align:center;padding:20px;"><i class="fa-solid fa-spinner spin"></i></p>';
    loadViewDetails(reconId, content);
  }catch(e){
    toast('Error: '+e.message,'err');
    if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-link-slash"></i> Unlink';}
  }
}

/* ══════════════════════════════════════════════
   BULK MATCH (NEW v2.7)
   Link multiple same-date invoices to ONE bank transaction row
   when one card swipe amount equals the combined total of
   several invoices.
══════════════════════════════════════════════ */
function toggleBulkPanel(rowId){
  const panel = document.getElementById('bulkrow_'+rowId);
  if(!panel) return;
  const visible = panel.style.display !== 'none';
  if(visible){
    panel.style.display = 'none';
    return;
  }
  panel.style.display = '';
  if(panel.dataset.loaded !== '1'){
    panel.dataset.loaded = '1';
    loadBulkCandidates(rowId);
  }
}

async function loadBulkCandidates(rowId){
  const panel = document.getElementById('bulkrow_'+rowId);
  const listDiv = document.getElementById('bulklist_'+rowId);
  if(!panel||!listDiv) return;
  const reconId = panel.dataset.reconId;
  const trxDate = panel.dataset.trxDate;

  listDiv.innerHTML = '<span><i class="fa-solid fa-spinner spin"></i> Loading invoices for '+esc(trxDate)+'…</span>';

  const fd = new FormData();
  fd.append('ajax_action','bulk_candidates');
  fd.append('row_id', rowId);
  fd.append('recon_id', reconId);
  fd.append('trx_date', trxDate);

  try{
    const d = await fetch(AJAX_URL,{method:'POST',body:fd}).then(safeJson);
    if(!d.success){listDiv.innerHTML = `<span style="color:var(--red);">${esc(d.error)}</span>`;return;}
    if(!d.invoices.length){
      listDiv.innerHTML = '<span style="color:var(--txs);">No unreconciled VISA invoices found on this date.</span>';
      return;
    }
    let html = '<div style="display:flex;flex-direction:column;gap:5px;max-height:220px;overflow-y:auto;">';
    d.invoices.forEach(inv=>{
      html += `<label class="bulk-check-row">
        <input type="checkbox" value="${inv.id}" data-amount="${inv.total_amount}" onchange="updateBulkTotal(${rowId})">
        <span style="font-weight:700;color:var(--teal);">${esc(inv.doc_no)}</span>
        <span style="color:var(--txs);">${esc(inv.customer_name||'Walk-in')}</span>
        <span style="margin-left:auto;font-weight:700;">${fmtNum(inv.total_amount)}</span>
      </label>`;
    });
    html += '</div>';
    listDiv.innerHTML = html;
    updateBulkTotal(rowId);
  }catch(e){
    listDiv.innerHTML = `<span style="color:var(--red);">Error: ${esc(e.message)}</span>`;
  }
}

function updateBulkTotal(rowId){
  const panel = document.getElementById('bulkrow_'+rowId);
  if(!panel) return;
  const amount = parseFloat(panel.dataset.amount)||0;
  const checks = panel.querySelectorAll('input[type=checkbox]:checked');
  let sum = 0;
  checks.forEach(c=>{ sum += parseFloat(c.dataset.amount)||0; });
  const diff = amount - sum;

  const cntEl = document.getElementById('bulkcount_'+rowId);
  const sumEl = document.getElementById('bulksum_'+rowId);
  const diffEl = document.getElementById('bulkdiff_'+rowId);
  if(cntEl) cntEl.textContent = checks.length;
  if(sumEl) sumEl.textContent = fmtNum(sum);
  if(diffEl){
    diffEl.textContent = fmtNum(diff);
    diffEl.style.color = Math.abs(diff) < 0.01 ? 'var(--green)' : 'var(--red)';
  }
}

async function confirmBulkReconcile(rowId){
  const panel = document.getElementById('bulkrow_'+rowId);
  if(!panel) return;
  const reconId = panel.dataset.reconId;
  const checks = panel.querySelectorAll('input[type=checkbox]:checked');
  if(!checks.length){toast('Select at least one invoice','warn');return;}

  const ids = [];
  let sum = 0;
  checks.forEach(c=>{ ids.push(parseInt(c.value,10)); sum += parseFloat(c.dataset.amount)||0; });
  const amount = parseFloat(panel.dataset.amount)||0;

  if(Math.abs(sum-amount) >= 0.01){
    if(!confirm(`Selected total (${fmtNum(sum)}) does not exactly match the bank amount (${fmtNum(amount)}). Reconcile anyway?`)) return;
  }

  const btn = panel.querySelector('.btn-green');
  if(btn){btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner spin"></i> Linking…';}

  const fd = new FormData();
  fd.append('ajax_action','bulk_reconcile_row');
  fd.append('row_id', rowId);
  fd.append('recon_id', reconId);
  fd.append('invoice_ids', JSON.stringify(ids));

  try{
    const d = await fetch(AJAX_URL,{method:'POST',body:fd}).then(safeJson);
    if(!d.success) throw new Error(d.error||'Bulk reconcile failed');
    toast(`✓ ${d.matched} invoice(s) linked to this transaction`,'ok');
    /* Refresh modal content */
    const content = document.getElementById('viewContent');
    content.innerHTML = '<p style="text-align:center;padding:20px;"><i class="fa-solid fa-spinner spin"></i></p>';
    loadViewDetails(reconId, content);
  }catch(e){
    toast('Error: '+e.message,'err');
    if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-link"></i> Reconcile Selected';}
  }
}

/* ── Print full report ── */
function printFullReport(){
  window.print();
}

function closeViewModal(){
  document.getElementById('viewModal').classList.remove('show');
  currentViewReconId = null;
}

/* ── Delete ── */
function deleteRecon(rid){
  deleteReconId=rid;
  document.getElementById('deleteCount').textContent='?';
  document.getElementById('deleteModal').classList.add('show');
  fetch(AJAX_URL+'?ajax=view_details&recon_id='+rid).then(safeJson).then(d=>{
    if(d.success){
      const matched=d.rows.filter(r=>r.matched=='1'||r.matched===1).length;
      document.getElementById('deleteCount').textContent=matched;
    }
  });
}

function closeDeleteModal(){
  deleteReconId=null;
  document.getElementById('deleteModal').classList.remove('show');
}

async function confirmDelete(){
  if(!deleteReconId)return;
  const modal=document.getElementById('deleteModal');
  const btn=modal.querySelector('.btn-red');
  btn.disabled=true;
  btn.innerHTML='<i class="fa-solid fa-spinner spin"></i> Deleting…';

  const fd=new FormData();
  fd.append('ajax_action','delete_recon');
  fd.append('recon_id',deleteReconId);

  try{
    const d=await fetch(AJAX_URL,{method:'POST',body:fd}).then(safeJson);
    if(d.success){
      toast('✓ '+d.message,'ok');
      setTimeout(()=>location.reload(),1500);
    }else throw new Error(d.error||'Delete failed');
  }catch(e){
    toast('Error: '+e.message,'err');
    btn.disabled=false;
    btn.innerHTML='<i class="fa-solid fa-trash"></i> Delete';
  }
}

/* ── Reset ── */
function resetAll(){
  currentPdfFile=null;extractedRows=[];matchedResults=[];extractedHeader={};
  document.getElementById('pdfInput').value='';
  document.getElementById('pdfName').style.display='none';
  document.getElementById('aiProgBox').classList.remove('show');
  document.getElementById('aiRawOut').classList.remove('show');
  clearError();
  document.getElementById('resultsSection').style.display='none';
  document.getElementById('extractBtn').disabled=true;
  document.getElementById('extractBtn').innerHTML='<i class="fa-solid fa-robot"></i> Extract with Gemini AI';
  setStep(1);
}

/* ── Steps ── */
function setStep(n){[1,2,3,4].forEach(i=>{const el=document.getElementById('step'+i);el.classList.remove('active','done');if(i<n)el.classList.add('done');if(i===n)el.classList.add('active');});}

/* ── Helpers ── */
function fmtNum(n){return parseFloat(n||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});}
function fmtDate(d){if(!d)return'—';const dt=new Date(d);return isNaN(dt)?d:dt.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});}
function esc(s){return(s==null?'':String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function sleep(ms){return new Promise(r=>setTimeout(r,ms));}
function toast(msg,type){const t=document.getElementById('toast');t.className='show '+(type||'ok');t.textContent=msg;clearTimeout(t._t);t._t=setTimeout(()=>t.className='',3500);}
</script>

<?php include 'footer.php'; ?>