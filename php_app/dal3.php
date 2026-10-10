<?php
include 'config.php';

// ══════════════════════════════════════════════════════════════════
//  TABLE CREATION HELPER
// ══════════════════════════════════════════════════════════════════
function ensure_dal_tables($conn) {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS drivers_loyalty (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        invoice_date        DATE NULL,
        banking_date        DATE NULL,
        entity              VARCHAR(255) NULL,
        claim_reference     VARCHAR(255) NULL,
        description         VARCHAR(500) NULL,
        paid_amount         DECIMAL(14,2) NULL,
        vat                 DECIMAL(14,2) NULL,
        net_amount          DECIMAL(14,2) NULL,
        document_path       VARCHAR(500) NULL,
        document_name       VARCHAR(255) NULL,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP
    )");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS dl_settlements (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        dl_id               INT NOT NULL,
        paid_amount_by_yelo DECIMAL(14,2) NULL,
        outlet_code         VARCHAR(100) NULL,
        outlet_name         VARCHAR(255) NULL,
        cheque_no           VARCHAR(100) NULL,
        cheque_date         DATE NULL,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dl (dl_id)
    )");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS dl_confirmed_payments (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        claim_item_id       INT NOT NULL,
        upload_id           INT NOT NULL,
        customer_code       VARCHAR(50) NULL,
        customer_name       VARCHAR(255) NULL,
        ledger_type         VARCHAR(100) NULL,
        status              VARCHAR(100) NULL,
        claim_type          VARCHAR(100) NULL,
        tax_invoice_no      VARCHAR(100) NULL,
        invoice_date        DATE NULL,
        banking_date        DATE NULL,
        entity              VARCHAR(50) NULL,
        claim_description   TEXT NULL,
        actual_amount       DECIMAL(18,4) DEFAULT 0,
        vat_amount          DECIMAL(18,4) DEFAULT 0,
        total_amount        DECIMAL(18,4) DEFAULT 0,
        dl_record_id        INT NULL,
        confirmed_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        confirmed_by        VARCHAR(100) DEFAULT 'system',
        cheque_ack_status   ENUM('pending','sent') DEFAULT 'pending',
        INDEX idx_claim_item (claim_item_id),
        INDEX idx_dl_record  (dl_record_id)
    )");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS dl_cheque_acknowledgments (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        confirmed_payment_id INT NULL,
        claim_item_id       INT NOT NULL,
        tax_invoice_no      VARCHAR(100) NULL,
        invoice_date        DATE NULL,
        banking_date        DATE NULL,
        entity              VARCHAR(50) NULL,
        claim_description   TEXT NULL,
        actual_amount       DECIMAL(18,4) DEFAULT 0,
        vat_amount          DECIMAL(18,4) DEFAULT 0,
        total_amount        DECIMAL(18,4) DEFAULT 0,
        ledger_type         VARCHAR(100) NULL,
        status              VARCHAR(100) NULL,
        claim_type          VARCHAR(100) NULL,
        customer_code       VARCHAR(50) NULL,
        customer_name       VARCHAR(255) NULL,
        ack_sent_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
        ack_sent_by         VARCHAR(100) DEFAULT 'system',
        INDEX idx_cp (confirmed_payment_id),
        INDEX idx_ci (claim_item_id)
    )");
}

// ══════════════════════════════════════════════════════════════════
//  AJAX — BEFORE header.php
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    ensure_dal_tables($conn);

    $action = $_GET['action'];

    // ── Save main record ─────────────────────────────────────────
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id            = intval($_POST['id'] ?? 0);
        $invoice_date  = mysqli_real_escape_string($conn, trim($_POST['invoice_date']  ?? ''));
        $banking_date  = mysqli_real_escape_string($conn, trim($_POST['banking_date']  ?? ''));
        $entity        = mysqli_real_escape_string($conn, trim($_POST['entity']        ?? ''));
        $claim_ref     = mysqli_real_escape_string($conn, trim($_POST['claim_reference']?? ''));
        $description   = mysqli_real_escape_string($conn, trim($_POST['description']   ?? ''));
        $paid_amount   = is_numeric($_POST['paid_amount'] ?? '') ? floatval($_POST['paid_amount']) : null;
        $vat           = is_numeric($_POST['vat']         ?? '') ? floatval($_POST['vat'])         : null;
        $net_amount    = is_numeric($_POST['net_amount']  ?? '') ? floatval($_POST['net_amount'])  : null;

        $doc_path = null; $doc_name = null;
        if (!empty($_FILES['document']['name']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/drivers_loyalty/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext     = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png'];
            if (!in_array($ext, $allowed)) { echo json_encode(['success'=>false,'message'=>'Invalid file type.']); exit; }
            $safe = uniqid('dl_',true).'.'.$ext;
            if (move_uploaded_file($_FILES['document']['tmp_name'], $upload_dir.$safe)) {
                $doc_path = mysqli_real_escape_string($conn, $upload_dir.$safe);
                $doc_name = mysqli_real_escape_string($conn, $_FILES['document']['name']);
            }
        }

        $id_sql  = $invoice_date ? "'$invoice_date'" : 'NULL';
        $bd_sql  = $banking_date ? "'$banking_date'" : 'NULL';
        $pa_sql  = $paid_amount  !== null ? $paid_amount  : 'NULL';
        $vt_sql  = $vat          !== null ? $vat          : 'NULL';
        $na_sql  = $net_amount   !== null ? $net_amount   : 'NULL';

        if ($id > 0) {
            $doc_part = $doc_path !== null ? ", document_path='$doc_path', document_name='$doc_name'" : '';
            $sql = "UPDATE drivers_loyalty SET
                        invoice_date=$id_sql, banking_date=$bd_sql, entity='$entity',
                        claim_reference='$claim_ref', description='$description',
                        paid_amount=$pa_sql, vat=$vt_sql, net_amount=$na_sql $doc_part
                    WHERE id=$id";
        } else {
            $dp = $doc_path !== null ? "'$doc_path'" : 'NULL';
            $dn = $doc_name !== null ? "'$doc_name'" : 'NULL';
            $sql = "INSERT INTO drivers_loyalty
                        (invoice_date,banking_date,entity,claim_reference,description,paid_amount,vat,net_amount,document_path,document_name)
                    VALUES ($id_sql,$bd_sql,'$entity','$claim_ref','$description',$pa_sql,$vt_sql,$na_sql,$dp,$dn)";
        }
        $ok = mysqli_query($conn, $sql);
        echo json_encode(['success'=>(bool)$ok,'id'=>($ok&&!$id)?mysqli_insert_id($conn):$id,'message'=>$ok?'':mysqli_error($conn)]);
        exit;
    }

    // ── Get single record ─────────────────────────────────────────
    if ($action === 'get') {
        $id  = intval($_GET['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM drivers_loyalty WHERE id=$id"));
        echo json_encode(['success'=>(bool)$row,'data'=>$row]);
        exit;
    }

    // ── Delete ────────────────────────────────────────────────────
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id  = intval($_POST['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn,"SELECT document_path FROM drivers_loyalty WHERE id=$id"));
        if ($row && $row['document_path'] && file_exists($row['document_path'])) @unlink($row['document_path']);
        mysqli_query($conn,"DELETE FROM dl_settlements WHERE dl_id=$id");
        $ok = mysqli_query($conn,"DELETE FROM drivers_loyalty WHERE id=$id");
        echo json_encode(['success'=>(bool)$ok]);
        exit;
    }

    // ── Save settlements ──────────────────────────────────────────
    if ($action === 'save_settlements' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $dl_id     = intval($_POST['dl_id'] ?? 0);
        if (!$dl_id) { echo json_encode(['success'=>false,'message'=>'Invalid record.']); exit; }
        $rows_data = json_decode($_POST['rows'] ?? '[]', true);
        if (!is_array($rows_data)) { echo json_encode(['success'=>false,'message'=>'Invalid data.']); exit; }

        mysqli_query($conn,"DELETE FROM dl_settlements WHERE dl_id=$dl_id");
        foreach ($rows_data as $r) {
            $paby  = is_numeric($r['paid_amount_by_yelo'] ?? '') ? floatval($r['paid_amount_by_yelo']) : 'NULL';
            $oc    = mysqli_real_escape_string($conn, trim($r['outlet_code']  ?? ''));
            $on    = mysqli_real_escape_string($conn, trim($r['outlet_name']  ?? ''));
            $cno   = mysqli_real_escape_string($conn, trim($r['cheque_no']    ?? ''));
            $cdt   = mysqli_real_escape_string($conn, trim($r['cheque_date']  ?? ''));
            $cd_sql= $cdt ? "'$cdt'" : 'NULL';
            mysqli_query($conn,"INSERT INTO dl_settlements (dl_id,paid_amount_by_yelo,outlet_code,outlet_name,cheque_no,cheque_date)
                VALUES ($dl_id,$paby,'$oc','$on','$cno',$cd_sql)");
        }
        echo json_encode(['success'=>true]);
        exit;
    }

    // ── Get settlements ───────────────────────────────────────────
    if ($action === 'get_settlements') {
        $dl_id = intval($_GET['dl_id'] ?? 0);
        $res   = mysqli_query($conn,"SELECT * FROM dl_settlements WHERE dl_id=$dl_id ORDER BY id");
        $data  = [];
        while ($r = mysqli_fetch_assoc($res)) $data[] = $r;
        echo json_encode(['success'=>true,'data'=>$data]);
        exit;
    }

    // ── Get claim_cert_items filtered by Drives & Loyalty Programs
    //    Only return items NOT yet confirmed AND NOT yet in cheque ack
    if ($action === 'get_claim_items') {
        $search    = mysqli_real_escape_string($conn, trim($_GET['search']    ?? ''));
        $entity_f  = mysqli_real_escape_string($conn, trim($_GET['entity']    ?? ''));
        $date_from = mysqli_real_escape_string($conn, trim($_GET['date_from'] ?? ''));
        $date_to   = mysqli_real_escape_string($conn, trim($_GET['date_to']   ?? ''));

        $where = [
            "ci.claim_type = 'Drives & Loyalty Programs'",
            "ci.id NOT IN (SELECT claim_item_id FROM dl_confirmed_payments WHERE claim_item_id IS NOT NULL)",
            "ci.id NOT IN (SELECT claim_item_id FROM dl_cheque_acknowledgments WHERE claim_item_id IS NOT NULL)"
        ];
        if ($search)    $where[] = "(ci.tax_invoice_no LIKE '%$search%' OR ci.claim_description LIKE '%$search%' OR ci.entity LIKE '%$search%')";
        if ($entity_f)  $where[] = "ci.entity LIKE '%$entity_f%'";
        if ($date_from) $where[] = "ci.invoice_date >= '$date_from'";
        if ($date_to)   $where[] = "ci.invoice_date <= '$date_to'";

        $sql = "SELECT ci.*
                FROM claim_cert_items ci
                WHERE " . implode(' AND ', $where) . "
                ORDER BY ci.invoice_date DESC, ci.id DESC";

        $res  = mysqli_query($conn, $sql);
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        echo json_encode(['success'=>true,'rows'=>$rows,'count'=>count($rows)]);
        exit;
    }

    // ── Get confirmed payments (Tab 2) ────────────────────────────
    if ($action === 'get_confirmed_payments') {
        $search    = mysqli_real_escape_string($conn, trim($_GET['search']    ?? ''));
        $entity_f  = mysqli_real_escape_string($conn, trim($_GET['entity']    ?? ''));
        $date_from = mysqli_real_escape_string($conn, trim($_GET['date_from'] ?? ''));
        $date_to   = mysqli_real_escape_string($conn, trim($_GET['date_to']   ?? ''));

        $where = ['1=1'];
        if ($search)    $where[] = "(cp.tax_invoice_no LIKE '%$search%' OR cp.claim_description LIKE '%$search%' OR cp.entity LIKE '%$search%')";
        if ($entity_f)  $where[] = "cp.entity LIKE '%$entity_f%'";
        if ($date_from) $where[] = "cp.invoice_date >= '$date_from'";
        if ($date_to)   $where[] = "cp.invoice_date <= '$date_to'";

        $sql = "SELECT cp.* FROM dl_confirmed_payments cp
                WHERE " . implode(' AND ', $where) . "
                ORDER BY cp.confirmed_at DESC";

        $res  = mysqli_query($conn, $sql);
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        echo json_encode(['success'=>true,'rows'=>$rows,'count'=>count($rows)]);
        exit;
    }

    // ── Get cheque acknowledgment records (Tab 3) ─────────────────
    if ($action === 'get_cheque_acks') {
        $search    = mysqli_real_escape_string($conn, trim($_GET['search']    ?? ''));
        $entity_f  = mysqli_real_escape_string($conn, trim($_GET['entity']    ?? ''));
        $date_from = mysqli_real_escape_string($conn, trim($_GET['date_from'] ?? ''));
        $date_to   = mysqli_real_escape_string($conn, trim($_GET['date_to']   ?? ''));

        $where = ['1=1'];
        if ($search)    $where[] = "(ca.tax_invoice_no LIKE '%$search%' OR ca.claim_description LIKE '%$search%' OR ca.entity LIKE '%$search%')";
        if ($entity_f)  $where[] = "ca.entity LIKE '%$entity_f%'";
        if ($date_from) $where[] = "ca.invoice_date >= '$date_from'";
        if ($date_to)   $where[] = "ca.invoice_date <= '$date_to'";

        $sql = "SELECT ca.* FROM dl_cheque_acknowledgments ca
                WHERE " . implode(' AND ', $where) . "
                ORDER BY ca.ack_sent_at DESC";

        $res  = mysqli_query($conn, $sql);
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        echo json_encode(['success'=>true,'rows'=>$rows,'count'=>count($rows)]);
        exit;
    }

    // ── Confirm selected claim items → insert into drivers_loyalty ─
    //    No longer requires prior confirmation; works directly from claim items
    if ($action === 'confirm_payments' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (!is_array($ids) || empty($ids)) {
            echo json_encode(['success'=>false,'message'=>'No items selected.']); exit;
        }
        $confirmed_by = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'system';
        $inserted = 0; $skipped = 0;

        foreach ($ids as $cid) {
            $cid = intval($cid);
            // Skip if already confirmed OR already in cheque ack
            $chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM dl_confirmed_payments WHERE claim_item_id=$cid LIMIT 1"));
            if ($chk) { $skipped++; continue; }
            $chk2 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM dl_cheque_acknowledgments WHERE claim_item_id=$cid LIMIT 1"));
            if ($chk2) { $skipped++; continue; }

            $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM claim_cert_items WHERE id=$cid LIMIT 1"));
            if (!$row) { $skipped++; continue; }

            $paid   = floatval($row['total_amount']);
            $vat    = floatval($row['vat_amount']);
            $net    = floatval($row['actual_amount']);
            $inv_d  = $row['invoice_date']  ? "'{$row['invoice_date']}'"  : 'NULL';
            $bank_d = $row['banking_date']  ? "'{$row['banking_date']}'"  : 'NULL';
            $entity = mysqli_real_escape_string($conn, $row['entity']           ?? '');
            $clref  = mysqli_real_escape_string($conn, $row['tax_invoice_no']   ?? '');
            $desc   = mysqli_real_escape_string($conn, $row['claim_description']?? '');

            mysqli_query($conn,
                "INSERT INTO drivers_loyalty (invoice_date,banking_date,entity,claim_reference,description,paid_amount,vat,net_amount)
                 VALUES ($inv_d,$bank_d,'$entity','$clref','$desc',$paid,$vat,$net)");
            $dl_id = mysqli_insert_id($conn);

            $cb_e  = mysqli_real_escape_string($conn, $confirmed_by);
            $cc_e  = mysqli_real_escape_string($conn, $row['customer_code']      ?? '');
            $cn_e  = mysqli_real_escape_string($conn, $row['customer_name']      ?? '');
            $lt_e  = mysqli_real_escape_string($conn, $row['ledger_type']        ?? '');
            $st_e  = mysqli_real_escape_string($conn, $row['status']             ?? '');
            $ct_e  = mysqli_real_escape_string($conn, $row['claim_type']         ?? '');
            $ti_e  = mysqli_real_escape_string($conn, $row['tax_invoice_no']     ?? '');
            $cd_e  = mysqli_real_escape_string($conn, $row['claim_description']  ?? '');
            $uid   = intval($row['upload_id'] ?? 0);
            $aa    = floatval($row['actual_amount']);
            $va    = floatval($row['vat_amount']);
            $ta    = floatval($row['total_amount']);

            mysqli_query($conn,
                "INSERT INTO dl_confirmed_payments
                    (claim_item_id,upload_id,customer_code,customer_name,ledger_type,status,claim_type,
                     tax_invoice_no,invoice_date,banking_date,entity,claim_description,
                     actual_amount,vat_amount,total_amount,dl_record_id,confirmed_by)
                 VALUES ($cid,$uid,'$cc_e','$cn_e','$lt_e','$st_e','$ct_e',
                         '$ti_e',$inv_d,$bank_d,'$entity','$cd_e',
                         $aa,$va,$ta,$dl_id,'$cb_e')");
            $inserted++;
        }
        echo json_encode(['success'=>true,'inserted'=>$inserted,'skipped'=>$skipped]);
        exit;
    }

    // ── Reverse confirmed payment ──────────────────────────────────
    if ($action === 'reverse_confirmation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $claim_item_id = intval($_POST['claim_item_id'] ?? 0);
        if (!$claim_item_id) {
            echo json_encode(['success'=>false,'message'=>'Invalid claim item.']); exit;
        }

        $cp = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM dl_confirmed_payments WHERE claim_item_id=$claim_item_id LIMIT 1"));
        if (!$cp) {
            echo json_encode(['success'=>false,'message'=>'Confirmed payment not found.']); exit;
        }

        $cp_id  = intval($cp['id']);
        $dl_id  = intval($cp['dl_record_id']);

        // Remove from confirmed_payments
        mysqli_query($conn, "DELETE FROM dl_confirmed_payments WHERE id=$cp_id");
        // Remove from drivers_loyalty
        if ($dl_id) {
            mysqli_query($conn, "DELETE FROM dl_settlements WHERE dl_id=$dl_id");
            mysqli_query($conn, "DELETE FROM drivers_loyalty WHERE id=$dl_id");
        }

        echo json_encode(['success'=>true,'message'=>'Confirmation reversed successfully.']);
        exit;
    }

    // ── Reverse cheque acknowledgment ──────────────────────────────
    if ($action === 'reverse_cheque_ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $claim_item_id = intval($_POST['claim_item_id'] ?? 0);
        if (!$claim_item_id) {
            echo json_encode(['success'=>false,'message'=>'Invalid claim item.']); exit;
        }

        $ca = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM dl_cheque_acknowledgments WHERE claim_item_id=$claim_item_id LIMIT 1"));
        if (!$ca) {
            echo json_encode(['success'=>false,'message'=>'Cheque acknowledgment not found.']); exit;
        }

        // Remove cheque ack record
        mysqli_query($conn, "DELETE FROM dl_cheque_acknowledgments WHERE claim_item_id=$claim_item_id");
        // If there was a linked confirmed_payment, clean that up too
        if (!empty($ca['confirmed_payment_id'])) {
            $cpid = intval($ca['confirmed_payment_id']);
            mysqli_query($conn, "UPDATE dl_confirmed_payments SET cheque_ack_status='pending' WHERE id=$cpid");
        }

        echo json_encode(['success'=>true,'message'=>'Cheque acknowledgment reversed successfully.']);
        exit;
    }

    // ── Send selected claim items DIRECTLY to cheque acknowledgment ─
    //    No confirmation required — items can go straight here
    if ($action === 'send_to_cheque_ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (!is_array($ids) || empty($ids)) {
            echo json_encode(['success'=>false,'message'=>'No items selected.']); exit;
        }
        $sent_by  = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'system';
        $sb_e     = mysqli_real_escape_string($conn, $sent_by);
        $inserted = 0; $skipped = 0;

        foreach ($ids as $cid) {
            $cid = intval($cid);

            // Skip if already in ack table
            $already_ack = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM dl_cheque_acknowledgments WHERE claim_item_id=$cid LIMIT 1"));
            if ($already_ack) { $skipped++; continue; }

            // Skip if already confirmed
            $already_cp = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM dl_confirmed_payments WHERE claim_item_id=$cid LIMIT 1"));
            if ($already_cp) { $skipped++; continue; }

            // Load claim item directly
            $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM claim_cert_items WHERE id=$cid LIMIT 1"));
            if (!$row) { $skipped++; continue; }

            $inv_d = $row['invoice_date']  ? "'{$row['invoice_date']}'"  : 'NULL';
            $ban_d = $row['banking_date']  ? "'{$row['banking_date']}'"  : 'NULL';
            $en_e  = mysqli_real_escape_string($conn, $row['entity']            ?? '');
            $cd_e  = mysqli_real_escape_string($conn, $row['claim_description'] ?? '');
            $lt_e  = mysqli_real_escape_string($conn, $row['ledger_type']       ?? '');
            $st_e  = mysqli_real_escape_string($conn, $row['status']            ?? '');
            $ct_e  = mysqli_real_escape_string($conn, $row['claim_type']        ?? '');
            $ti_e  = mysqli_real_escape_string($conn, $row['tax_invoice_no']    ?? '');
            $cc_e  = mysqli_real_escape_string($conn, $row['customer_code']     ?? '');
            $cn_e  = mysqli_real_escape_string($conn, $row['customer_name']     ?? '');
            $aa    = floatval($row['actual_amount']);
            $va    = floatval($row['vat_amount']);
            $ta    = floatval($row['total_amount']);

            $ins = mysqli_query($conn,
                "INSERT INTO dl_cheque_acknowledgments
                    (confirmed_payment_id,claim_item_id,tax_invoice_no,invoice_date,banking_date,
                     entity,claim_description,actual_amount,vat_amount,total_amount,
                     ledger_type,status,claim_type,customer_code,customer_name,ack_sent_by)
                 VALUES (NULL,$cid,'$ti_e',$inv_d,$ban_d,'$en_e','$cd_e',$aa,$va,$ta,
                         '$lt_e','$st_e','$ct_e','$cc_e','$cn_e','$sb_e')");

            if ($ins) {
                $inserted++;
            } else {
                $skipped++;
            }
        }
        echo json_encode(['success'=>true,'sent'=>$inserted,'skipped'=>$skipped]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  PAGE LOAD
// ══════════════════════════════════════════════════════════════════
ensure_dal_tables($conn);

$filter_entity    = isset($_GET['filter_entity'])    ? mysqli_real_escape_string($conn,$_GET['filter_entity'])    : '';
$filter_date_from = isset($_GET['filter_date_from']) ? mysqli_real_escape_string($conn,$_GET['filter_date_from']) : '';
$filter_date_to   = isset($_GET['filter_date_to'])   ? mysqli_real_escape_string($conn,$_GET['filter_date_to'])   : '';
$filter_claim     = isset($_GET['filter_claim'])     ? mysqli_real_escape_string($conn,$_GET['filter_claim'])     : '';

$where = [];
if ($filter_entity)    $where[] = "p.entity LIKE '%$filter_entity%'";
if ($filter_date_from) $where[] = "p.invoice_date >= '$filter_date_from'";
if ($filter_date_to)   $where[] = "p.invoice_date <= '$filter_date_to'";
if ($filter_claim)     $where[] = "p.claim_reference LIKE '%$filter_claim%'";
$where_sql = $where ? 'WHERE '.implode(' AND ',$where) : '';

$rows_result = mysqli_query($conn,
    "SELECT p.*,
            COALESCE((SELECT SUM(s.paid_amount_by_yelo) FROM dl_settlements s WHERE s.dl_id=p.id),0) AS total_settled,
            (SELECT MAX(s.cheque_date) FROM dl_settlements s WHERE s.dl_id=p.id) AS last_cheque_date,
            (SELECT s.cheque_no FROM dl_settlements s WHERE s.dl_id=p.id ORDER BY s.id DESC LIMIT 1) AS last_cheque_no,
            (SELECT GROUP_CONCAT(s.outlet_name ORDER BY s.id SEPARATOR ', ') FROM dl_settlements s WHERE s.dl_id=p.id) AS outlet_names
     FROM drivers_loyalty p $where_sql
     ORDER BY p.invoice_date DESC, p.id DESC"
);

$totals = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as cnt,
            SUM(p.paid_amount) as total_paid,
            SUM(p.vat)         as total_vat,
            SUM(p.net_amount)  as total_net,
            COALESCE((SELECT SUM(s.paid_amount_by_yelo) FROM dl_settlements s
                      INNER JOIN drivers_loyalty p2 ON s.dl_id=p2.id
                      ".($where ? str_replace('p.','p2.',$where_sql) : '')."),0) as total_settled
     FROM drivers_loyalty p $where_sql"
));

include 'header.php';
?>
<style>
/* ── Reset & Base ────────────────────────────────────────────── */
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:0;color:#1f2937;display:flex;align-items:center;gap:8px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}

.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;font-family:inherit;text-decoration:none;white-space:nowrap}
.btn-sm{padding:7px 14px;font-size:13px}
.btn-xs{padding:5px 10px;font-size:12px}
.btn-primary{background:#000;color:#fff}.btn-primary:hover{background:#1f2937}
.btn-dark{background:#000;color:#fff}.btn-dark:hover{background:#1f2937}
.btn-light{background:#f9fafb;color:#374151;border:1px solid #d1d5db}.btn-light:hover{background:#f3f4f6}
.btn-settle{background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe}.btn-settle:hover{background:#7c3aed;color:#fff}
.btn-settle.settled{background:#f0fdf4;color:#16a34a;border-color:#86efac}
.btn-confirm-pay{background:linear-gradient(135deg,#1e3a5f,#2563eb);color:#fff;border:none;box-shadow:0 2px 8px rgba(37,99,235,.25);}
.btn-confirm-pay:hover{filter:brightness(1.1);}

/* ── Summary ─────────────────────────────────────────────────── */
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:20px}
.summary-box{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:16px}
.summary-label{font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px}
.summary-value{font-size:19px;font-weight:700;color:#1f2937}
.summary-sub{font-size:11px;color:#9ca3af;margin-top:2px}

/* ── Filter bar ──────────────────────────────────────────────── */
.fbar-group{display:flex;flex-direction:column;gap:4px}
.fbar-label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px}
.fbar-input,.fbar-select{padding:8px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;font-family:inherit;outline:none;transition:border-color .2s;height:36px;box-sizing:border-box;background:#fff}
.fbar-input:focus,.fbar-select:focus{border-color:#000}

/* ── Toolbar ─────────────────────────────────────────────────── */
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.search-wrap{position:relative;flex:1;min-width:200px;max-width:340px}
.search-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;pointer-events:none}
.search-input{width:100%;padding:8px 12px 8px 32px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;font-family:inherit;outline:none;transition:border-color .2s;box-sizing:border-box}
.search-input:focus{border-color:#000}

/* ── Table ───────────────────────────────────────────────────── */
.table-wrap{max-height:65vh;overflow:auto;border:1px solid #e5e5e5;border-radius:8px}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead tr{position:sticky;top:0;z-index:10}
.data-table thead th{background:#f0f0f0;padding:10px 12px;text-align:left;font-weight:600;color:#222;font-size:12px;white-space:nowrap;border-bottom:2px solid #d5d5d5;box-shadow:0 2px 0 #d5d5d5}
.data-table th.num,.data-table td.num{text-align:right}
.data-table tbody tr{border-bottom:1px solid #f0f0f0;transition:background .1s}
.data-table tbody tr:hover{background:#fafafa}
.data-table td{padding:9px 12px;color:#333;white-space:nowrap;vertical-align:middle}
.data-table tfoot td{padding:10px 12px;font-weight:700;color:#1f2937;background:#f9fafb;border-top:2px solid #e5e5e5;font-size:13px}

/* ── Table pills / badges ────────────────────────────────────── */
.action-buttons{display:flex;gap:5px;justify-content:center;align-items:center}
.btn-action{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;color:#6b7280;cursor:pointer;transition:all .2s;font-size:12px}
.btn-action:hover{transform:translateY(-1px);box-shadow:0 2px 4px rgba(0,0,0,.1)}
.btn-edit-r:hover{background:#000;color:#fff;border-color:#000}
.btn-del-r:hover{background:#ef4444;color:#fff;border-color:#ef4444}
.doc-icon-link{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;font-size:13px;transition:all .2s;text-decoration:none}
.doc-icon-link:hover{background:#1e40af;color:#fff;border-color:#1e40af}
.no-doc{color:#d1d5db;font-size:16px}
.bal-pos{color:#16a34a;font-weight:700}
.bal-neg{color:#dc2626;font-weight:700}
.bal-zero{color:#6b7280;font-weight:600}
.vat-pill{font-size:11px;font-weight:700;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;border-radius:10px;padding:2px 8px}
.entity-badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:12px;font-size:11px;font-weight:700;background:#e0e7ff;color:#3730a3;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.claim-pill{font-size:11px;font-family:monospace;background:#fdf4ff;color:#7e22ce;border:1px solid #e9d5ff;border-radius:5px;padding:2px 7px;white-space:nowrap}
.cheque-pill{font-size:11px;font-family:monospace;background:#f8fafc;color:#475569;border:1px solid #e2e8f0;border-radius:5px;padding:2px 7px}
.outlet-pill{font-size:11px;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;border-radius:5px;padding:2px 7px}

/* ══════════════════════════════════════════════════════════════
   MODAL OVERLAY
   ══════════════════════════════════════════════════════════════ */
.modal-overlay{
    position:fixed;
    top:0;left:0;right:0;bottom:0;
    width:100vw;height:100vh;
    background:rgba(0,0,0,.6);
    z-index:99999;
    display:none;
    align-items:flex-start;
    justify-content:center;
    padding:20px;
    box-sizing:border-box;
    overflow-y:auto;
}
.modal-overlay.open{display:flex;}

.modal-box{
    background:#fff;border-radius:12px;
    width:96%;max-width:780px;
    max-height:calc(100vh - 40px);
    display:flex;flex-direction:column;
    box-shadow:0 24px 64px rgba(0,0,0,.28);
    overflow:hidden;
    margin:auto;
    position:relative;
}
.settle-modal-box{
    background:#fff;border-radius:12px;
    width:96%;max-width:980px;
    max-height:calc(100vh - 40px);
    display:flex;flex-direction:column;
    box-shadow:0 24px 64px rgba(0,0,0,.28);
    overflow:hidden;
    margin:auto;
    position:relative;
}

/* ══════════════════════════════════════════════════════════════
   CONFIRM PAYMENTS MODAL — 3 tabs
   ══════════════════════════════════════════════════════════════ */
.cpm-box{
    background:#fff;border-radius:14px;
    width:98%;max-width:1380px;
    max-height:calc(100vh - 40px);
    display:flex;flex-direction:column;
    box-shadow:0 32px 80px rgba(0,0,0,.3);
    overflow:hidden;
    margin:auto;
    position:relative;
}

.cpm-header{
    padding:0 24px;
    border-bottom:2px solid rgba(255,255,255,.12);
    display:flex;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;gap:10px;
    background:linear-gradient(135deg,#0f3460 0%,#1e3a5f 60%,#16213e 100%);
    flex-shrink:0;
    min-height:58px;
}
.cpm-header-title{
    color:#fff;font-size:15px;font-weight:800;
    display:flex;align-items:center;gap:10px;
    letter-spacing:.3px;
}
.cpm-close{
    background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);
    color:#fff;border-radius:7px;padding:7px 16px;font-size:13px;font-weight:700;
    cursor:pointer;font-family:inherit;transition:background .2s;
    display:flex;align-items:center;gap:6px;
}
.cpm-close:hover{background:rgba(255,255,255,.28);}

.cpm-tabs{
    display:flex;
    background:#f8fafc;
    border-bottom:2px solid #e5e7eb;
    flex-shrink:0;
    padding:0 20px;
    gap:4px;
}
.cpm-tab{
    padding:12px 20px;font-size:13px;font-weight:700;cursor:pointer;
    border:none;background:transparent;color:#6b7280;
    border-bottom:3px solid transparent;margin-bottom:-2px;
    display:flex;align-items:center;gap:7px;
    font-family:inherit;transition:color .2s,border-color .2s;
    white-space:nowrap;
}
.cpm-tab:hover{color:#1e3a5f;}
.cpm-tab.active{color:#1e3a5f;border-bottom-color:#1e3a5f;}
.cpm-tab .tab-badge{
    border-radius:10px;padding:1px 7px;font-size:10px;font-weight:800;
    min-width:18px;text-align:center;
}
.cpm-tab .tab-badge-blue{background:#e0e7ff;color:#3730a3;}
.cpm-tab .tab-badge-green{background:#dcfce7;color:#166534;}
.cpm-tab .tab-badge-violet{background:#f5f3ff;color:#6d28d9;}
.cpm-tab.active .tab-badge-blue{background:#1e3a5f;color:#fff;}
.cpm-tab.active .tab-badge-green{background:#16a34a;color:#fff;}
.cpm-tab.active .tab-badge-violet{background:#7c3aed;color:#fff;}

.cpm-panel{display:none;flex-direction:column;flex:1;overflow:hidden;min-height:0;}
.cpm-panel.active{display:flex;}

.cpm-filterbar{
    padding:12px 20px;background:#f8fafc;border-bottom:1px solid #e5e7eb;
    display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;flex-shrink:0;
}
.cpm-fi{display:flex;flex-direction:column;gap:3px;}
.cpm-fi label{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
.cpm-fi input,.cpm-fi select{
    padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;
    font-size:12px;font-family:inherit;outline:none;height:32px;box-sizing:border-box;
    background:#fff;
}
.cpm-fi input:focus,.cpm-fi select:focus{border-color:#1e3a5f;}
.cpm-search-btn{
    padding:6px 14px;background:#1e3a5f;color:#fff;border:none;
    border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;
    font-family:inherit;height:32px;display:flex;align-items:center;gap:5px;
}
.cpm-clear-btn{
    padding:6px 12px;background:#f3f4f6;color:#374151;border:1px solid #d1d5db;
    border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;height:32px;
}

.cpm-stats{
    padding:8px 20px;background:#f0f9ff;border-bottom:1px solid #bae6fd;
    display:flex;align-items:center;gap:20px;flex-wrap:wrap;flex-shrink:0;
}
.cpm-stat{font-size:12px;color:#075985;font-weight:600;}
.cpm-stat b{color:#0f172a;}

.cpm-actionbar{
    padding:10px 20px;border-bottom:1px solid #e5e7eb;
    display:flex;align-items:center;gap:10px;flex-wrap:wrap;
    background:#fafafa;flex-shrink:0;
}
.cpm-sel-all{
    display:inline-flex;align-items:center;gap:6px;padding:6px 14px;
    border:1.5px solid #d1d5db;border-radius:6px;background:#fff;
    font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;color:#374151;
}
.cpm-sel-all:hover{background:#f1f5f9;}
.cpm-spacer{flex:1;}

/* Action buttons */
.btn-cpm-confirm{
    display:inline-flex;align-items:center;gap:7px;padding:8px 20px;
    background:linear-gradient(135deg,#15803d,#16a34a);color:#fff;
    border:none;border-radius:8px;font-size:13px;font-weight:800;
    cursor:pointer;font-family:inherit;box-shadow:0 3px 10px rgba(22,163,74,.3);
}
.btn-cpm-confirm:hover{filter:brightness(1.07);}
.btn-cpm-confirm:disabled{opacity:.5;cursor:not-allowed;}

.btn-cpm-cheque{
    display:inline-flex;align-items:center;gap:7px;padding:8px 20px;
    background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;
    border:none;border-radius:8px;font-size:13px;font-weight:800;
    cursor:pointer;font-family:inherit;box-shadow:0 3px 10px rgba(124,58,237,.3);
}
.btn-cpm-cheque:hover{filter:brightness(1.07);}
.btn-cpm-cheque:disabled{opacity:.5;cursor:not-allowed;}

.cpm-body{flex:1;overflow-y:auto;overflow-x:auto;min-height:0;}

.cpm-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1000px;}
.cpm-table thead th{
    padding:9px 11px;background:#0f172a;color:#e2e8f0;
    font-size:10.5px;font-weight:700;text-align:left;white-space:nowrap;
    border-right:1px solid rgba(255,255,255,.07);
    position:sticky;top:0;z-index:5;
}
.cpm-table thead th.tc{text-align:center;}
.cpm-table thead th.tr{text-align:right;}
.cpm-table tbody tr{border-bottom:1px solid #f1f5f9;}
.cpm-table tbody tr:hover td{background:#f8faff;}
.cpm-table td{padding:9px 11px;background:#fff;vertical-align:middle;}
.cpm-table tbody tr.selected-row td{background:#eff6ff !important;}

.cpm-footer{
    padding:12px 20px;border-top:1.5px solid #e5e7eb;
    display:flex;align-items:center;gap:12px;flex-wrap:wrap;
    justify-content:flex-end;background:#f9fafb;flex-shrink:0;
}
.cpm-footer-info{flex:1;font-size:12px;color:#6b7280;font-weight:600;}

/* ── Tags ─────────────────────────────────────────────────────── */
.tag{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;white-space:nowrap;}
.tag-green{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.tag-amber{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.tag-blue{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;}
.tag-violet{background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe;}
.tag-gray{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;}
.tag-red{background:#fef2f2;color:#991b1b;border:1px solid #fca5a5;}

.btn-rev-sm{
    display:inline-flex;align-items:center;gap:4px;padding:3px 9px;
    background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;
    border-radius:6px;font-size:10px;font-weight:700;cursor:pointer;font-family:inherit;
    transition:all .2s;
}
.btn-rev-sm:hover{background:#dc2626;color:#fff;border-color:#dc2626;}

/* ── Form modal ───────────────────────────────────────────────── */
.modal-header{padding:20px 26px 16px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;flex-shrink:0}
.modal-title{font-size:18px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px}
.modal-close{background:none;border:none;cursor:pointer;color:#9ca3af;font-size:26px;line-height:1;padding:0;transition:color .2s}
.modal-close:hover{color:#1f2937}
.modal-body{padding:24px 26px;overflow-y:auto;flex:1}
.modal-footer{padding:16px 26px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0}

.form-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
.form-grid .span2{grid-column:span 2}
.form-grid .span3{grid-column:span 3}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-label{font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:.4px}
.form-label span.auto{font-size:10px;background:#dbeafe;color:#1e40af;border-radius:4px;padding:1px 6px;font-weight:700;text-transform:none;letter-spacing:0;margin-left:4px}
.form-label span.note{font-size:10px;background:#fef9c3;color:#854d0e;border-radius:4px;padding:1px 6px;font-weight:700;text-transform:none;letter-spacing:0;margin-left:4px}
.form-input,.form-select{padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;font-family:inherit;color:#1f2937;outline:none;transition:border-color .2s,box-shadow .2s;background:#fff;width:100%;box-sizing:border-box}
.form-input:focus,.form-select:focus{border-color:#000;box-shadow:0 0 0 3px rgba(0,0,0,.06)}
.form-input.auto-fill{background:#f0fdf4;border-color:#bbf7d0;color:#166534;font-weight:700}
.form-input.net-fill{background:#fff7ed;border-color:#fed7aa;color:#c2410c;font-weight:700}
.form-divider{grid-column:span 3;border:none;border-top:1px solid #f0f0f0;margin:4px 0}
.calc-hint{font-size:11px;color:#6b7280;margin-top:3px;display:flex;align-items:center;gap:4px}
.calc-hint b{color:#7c3aed}
input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button{-webkit-appearance:none;margin:0}
input[type=number]{-moz-appearance:textfield;appearance:textfield}

.file-drop{border:2px dashed #d1d5db;border-radius:8px;padding:18px;text-align:center;cursor:pointer;transition:all .2s;background:#fafafa;position:relative}
.file-drop:hover,.file-drop.drag{border-color:#000;background:#f5f5f5}
.file-drop input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.file-drop-icon{font-size:22px;color:#9ca3af;margin-bottom:5px}
.file-drop-text{font-size:13px;color:#6b7280;font-weight:500}
.file-drop-sub{font-size:11px;color:#9ca3af;margin-top:2px}
.file-preview{display:none;align-items:center;gap:10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:10px 14px;margin-top:8px}
.file-preview.show{display:flex}
.file-preview-name{font-size:13px;color:#166534;font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.file-preview-rm{background:none;border:none;cursor:pointer;color:#dc2626;font-size:15px;padding:2px 5px;border-radius:4px}
.existing-doc{display:flex;align-items:center;gap:8px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:8px 12px;margin-bottom:8px;font-size:13px}
.existing-doc a{color:#1e40af;font-weight:600;text-decoration:none;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.existing-doc a:hover{text-decoration:underline}

/* ── Settlement modal ─────────────────────────────────────────── */
.settle-info-strip{padding:12px 26px;background:#f9fafb;border-bottom:1px solid #f0f0f0;display:flex;gap:20px;flex-wrap:wrap;flex-shrink:0}
.sinfo-cell{display:flex;flex-direction:column;gap:2px}
.sinfo-lbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px}
.sinfo-val{font-size:14px;font-weight:700;color:#1f2937}
.sinfo-val.green{color:#16a34a}
.settle-table{width:100%;border-collapse:collapse;font-size:13px;min-width:750px}
.settle-table th{background:#f9fafb;padding:8px 10px;text-align:left;font-weight:600;color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #e5e5e5;white-space:nowrap}
.settle-table th.num{text-align:right}
.settle-table td{padding:5px 6px;vertical-align:middle;border-bottom:1px solid #f5f5f5}
.settle-input{width:100%;padding:6px 9px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;font-family:inherit;outline:none;transition:border-color .2s;box-sizing:border-box;background:#fff}
.settle-input:focus{border-color:#7c3aed;box-shadow:0 0 0 2px rgba(124,58,237,.08)}
.settle-input[type=number]{text-align:right}
.settle-input.running-bal{background:#fff7ed;color:#c2410c;font-weight:700;border-color:#fed7aa;cursor:default}
.settle-rm{background:none;border:none;cursor:pointer;color:#dc2626;font-size:15px;padding:4px 7px;border-radius:4px;transition:background .15s;line-height:1}
.settle-rm:hover{background:#fef2f2}
.add-settle-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:1px dashed #c4b5fd;border-radius:6px;background:#fff;color:#7c3aed;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;margin-top:10px;transition:all .2s}
.add-settle-btn:hover{background:#f5f3ff;border-color:#7c3aed}
.settle-note{font-size:11px;color:#9ca3af;margin-top:6px;display:flex;align-items:center;gap:5px}
.settle-totals{display:flex;gap:24px;padding:12px 0;margin-top:12px;border-top:2px solid #e5e5e5;flex-wrap:wrap}
.stotal-cell{display:flex;flex-direction:column;gap:2px}
.stotal-lbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px}
.stotal-val{font-size:17px;font-weight:800;color:#1f2937}
.stotal-val.green{color:#16a34a}.stotal-val.red{color:#dc2626}.stotal-val.orange{color:#d97706}.stotal-val.purple{color:#7c3aed}

.empty-state{text-align:center;padding:60px 20px;color:#9ca3af}
.empty-state i{font-size:40px;margin-bottom:12px;display:block}
.empty-state p{font-size:15px;font-weight:500}

#toast{position:fixed;bottom:28px;right:28px;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;color:#fff;z-index:999999;display:none;box-shadow:0 4px 16px rgba(0,0,0,.18)}
#toast.success{background:#16a34a}
#toast.error{background:#dc2626}
#toast.info{background:#1e3a5f}
#toast.warn{background:#d97706}

/* Info banner in claim items tab */
.cpm-info-banner{
    padding:10px 20px;background:#fef9c3;border-bottom:1px solid #fde68a;
    display:flex;align-items:center;gap:10px;flex-shrink:0;
    font-size:12px;color:#854d0e;font-weight:600;
}

@media(max-width:700px){
    .form-grid{grid-template-columns:1fr 1fr}
    .form-grid .span3,.form-divider{grid-column:span 2}
    .summary-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:480px){
    .form-grid{grid-template-columns:1fr}
    .form-grid .span2,.form-grid .span3,.form-divider{grid-column:span 1}
}
</style>

<!-- ── Page Header ─────────────────────────────────────────────── -->
<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title"><i class="fa-solid fa-car"></i> Drivers &amp; Loyalty</h2>
            <p class="page-subtitle">Manage driver and loyalty claim entries and settlements</p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="btn btn-confirm-pay" onclick="openConfirmPayModal()">
                <i class="fa-solid fa-circle-check"></i> Manage Claim Payments
            </button>
            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fa-solid fa-plus"></i> Add New Entry
            </button>
        </div>
    </div>
</div>

<!-- ── Summary ──────────────────────────────────────────────────── -->
<div class="summary-grid">
    <div class="summary-box">
        <div class="summary-label">Total Records</div>
        <div class="summary-value"><?php echo number_format($totals['cnt']); ?></div>
        <div class="summary-sub">Filtered results</div>
    </div>
    <div class="summary-box">
        <div class="summary-label">Total Paid Amount</div>
        <div class="summary-value"><?php echo $totals['total_paid']!==null?number_format($totals['total_paid'],2):'—'; ?></div>
        <div class="summary-sub">Incl. VAT</div>
    </div>
    <div class="summary-box">
        <div class="summary-label">Total VAT</div>
        <div class="summary-value" style="color:#7c3aed;"><?php echo $totals['total_vat']!==null?number_format($totals['total_vat'],2):'—'; ?></div>
        <div class="summary-sub">18% extracted</div>
    </div>
    <div class="summary-box">
        <div class="summary-label">Total Net Amount</div>
        <div class="summary-value" style="color:#c2410c;"><?php echo $totals['total_net']!==null?number_format($totals['total_net'],2):'—'; ?></div>
        <div class="summary-sub">Excl. VAT</div>
    </div>
    <div class="summary-box">
        <div class="summary-label">Total Settled</div>
        <div class="summary-value" style="color:#16a34a;"><?php echo number_format($totals['total_settled']??0,2); ?></div>
        <div class="summary-sub">Paid by Yelo</div>
    </div>
    <div class="summary-box">
        <?php $diff=($totals['total_paid']??0)-($totals['total_settled']??0); ?>
        <div class="summary-label">Balance</div>
        <div class="summary-value" style="color:<?php echo $diff>0?'#d97706':($diff<0?'#dc2626':'#16a34a'); ?>">
            <?php echo number_format(abs($diff),2); ?>
        </div>
        <div class="summary-sub">Paid Amount vs Settled</div>
    </div>
</div>

<!-- ── Records Table ─────────────────────────────────────────────── -->
<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title"><i class="fa-solid fa-table"></i> Entry Records</h3>
        <form method="GET" action="" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <div class="fbar-group">
                <label class="fbar-label">Invoice Date From</label>
                <input type="date" name="filter_date_from" class="fbar-input" value="<?php echo htmlspecialchars($filter_date_from); ?>" style="width:140px;">
            </div>
            <div class="fbar-group">
                <label class="fbar-label">To</label>
                <input type="date" name="filter_date_to" class="fbar-input" value="<?php echo htmlspecialchars($filter_date_to); ?>" style="width:140px;">
            </div>
            <div class="fbar-group">
                <label class="fbar-label">Entity</label>
                <input type="text" name="filter_entity" class="fbar-input" placeholder="Filter entity…" value="<?php echo htmlspecialchars($filter_entity); ?>" style="width:130px;">
            </div>
            <div class="fbar-group">
                <label class="fbar-label">Claim Reference</label>
                <input type="text" name="filter_claim" class="fbar-input" placeholder="Filter claim…" value="<?php echo htmlspecialchars($filter_claim); ?>" style="width:130px;">
            </div>
            <button type="submit" class="btn btn-dark btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
            <a href="dal.php" class="btn btn-light btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
        </form>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="search-input" id="searchBox" placeholder="Search entity, claim ref, description, outlet…" oninput="doSearch()">
        </div>
        <span id="rowCount" style="font-size:12px;color:#6b7280;"></span>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="text-align:center;width:36px;"><i class="fa-solid fa-paperclip"></i></th>
                    <th>#</th>
                    <th>Invoice Date</th>
                    <th>Banking Date</th>
                    <th>Entity</th>
                    <th>Claim Reference</th>
                    <th>Description</th>
                    <th class="num">Paid Amount</th>
                    <th class="num">VAT 18%</th>
                    <th class="num">Net Amount</th>
                    <th class="num">Settled by Yelo</th>
                    <th class="num">Balance</th>
                    <th>Last Cheque Date</th>
                    <th>Last Cheque No</th>
                    <th>Outlets</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php if (!$rows_result || mysqli_num_rows($rows_result) === 0): ?>
                <tr><td colspan="16"><div class="empty-state"><i class="fa-solid fa-car"></i><p>No records found. Click <strong>Add New Entry</strong> to get started.</p></div></td></tr>
            <?php else: $rn=1; while ($r=mysqli_fetch_assoc($rows_result)):
                $paid    = floatval($r['paid_amount']??0);
                $settled = floatval($r['total_settled']??0);
                $balance = $paid - $settled;
                $balCl   = $balance>0?'bal-pos':($balance<0?'bal-neg':'bal-zero');
            ?>
                <tr id="tr-<?php echo $r['id']; ?>"
                    data-search="<?php echo strtolower(htmlspecialchars(($r['entity']??'').' '.($r['claim_reference']??'').' '.($r['description']??'').' '.($r['outlet_names']??'').' '.($r['last_cheque_no']??''))); ?>">
                    <td style="text-align:center;">
                        <?php if ($r['document_path']&&file_exists($r['document_path'])): ?>
                            <a href="<?php echo htmlspecialchars($r['document_path']); ?>" target="_blank" class="doc-icon-link" title="<?php echo htmlspecialchars($r['document_name']??'Document'); ?>"><i class="fa-solid fa-paperclip"></i></a>
                        <?php else: ?><span class="no-doc"><i class="fa-solid fa-minus"></i></span><?php endif; ?>
                    </td>
                    <td><?php echo $rn++; ?></td>
                    <td><?php echo !empty($r['invoice_date'])?date('d M Y',strtotime($r['invoice_date'])):'—'; ?></td>
                    <td style="font-size:12px;color:#6b7280;"><?php echo !empty($r['banking_date'])?date('d M Y',strtotime($r['banking_date'])):'—'; ?></td>
                    <td>
                        <?php if(!empty($r['entity'])): ?>
                            <span class="entity-badge" title="<?php echo htmlspecialchars($r['entity']); ?>"><?php echo htmlspecialchars($r['entity']); ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <?php if(!empty($r['claim_reference'])): ?>
                            <span class="claim-pill"><?php echo htmlspecialchars($r['claim_reference']); ?></span>
                        <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
                    </td>
                    <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['description']??''); ?>"><?php echo htmlspecialchars($r['description']??'—'); ?></td>
                    <td class="num" style="font-weight:700;"><?php echo $r['paid_amount']!==null?number_format($paid,2):'—'; ?></td>
                    <td class="num"><span class="vat-pill"><?php echo $r['vat']!==null?number_format($r['vat'],2):'—'; ?></span></td>
                    <td class="num" style="font-weight:700;color:#c2410c;"><?php echo $r['net_amount']!==null?number_format($r['net_amount'],2):'—'; ?></td>
                    <td class="num" id="td-settled-<?php echo $r['id']; ?>" style="color:#16a34a;font-weight:700;"><?php echo $settled>0?number_format($settled,2):'—'; ?></td>
                    <td class="num" id="td-balance-<?php echo $r['id']; ?>"><span class="<?php echo $balCl; ?>"><?php echo number_format($balance,2); ?></span></td>
                    <td id="td-lastcd-<?php echo $r['id']; ?>" style="font-size:12px;color:#6b7280;"><?php echo !empty($r['last_cheque_date'])?date('d M Y',strtotime($r['last_cheque_date'])):'—'; ?></td>
                    <td id="td-lastcno-<?php echo $r['id']; ?>">
                        <?php if(!empty($r['last_cheque_no'])): ?>
                            <span class="cheque-pill"><?php echo htmlspecialchars($r['last_cheque_no']); ?></span>
                        <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
                    </td>
                    <td id="td-outlets-<?php echo $r['id']; ?>" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['outlet_names']??''); ?>">
                        <?php if(!empty($r['outlet_names'])): ?>
                            <span class="outlet-pill"><?php echo htmlspecialchars($r['outlet_names']); ?></span>
                        <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <div class="action-buttons">
                            <button class="btn btn-settle btn-xs<?php echo $settled>0?' settled':''; ?>"
                                    id="settlebtn-<?php echo $r['id']; ?>"
                                    onclick="openSettleModal(<?php echo $r['id']; ?>,'<?php echo addslashes(htmlspecialchars($r['entity']??'')); ?>',<?php echo $paid; ?>)">
                                <i class="fa-solid fa-<?php echo $settled>0?'check-circle':'hand-holding-dollar'; ?>"></i>
                                <?php echo $settled>0?'Settled':'Settle'; ?>
                            </button>
                            <button class="btn-action btn-edit-r" onclick="editRow(<?php echo $r['id']; ?>)" title="Edit"><i class="fa-solid fa-pen"></i></button>
                            <button class="btn-action btn-del-r" onclick="deleteRow(<?php echo $r['id']; ?>)" title="Delete"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </td>
                </tr>
            <?php endwhile; endif; ?>
            </tbody>
            <?php if ($rows_result && mysqli_num_rows($rows_result) > 0): ?>
            <tfoot>
                <tr>
                    <td colspan="7" style="text-align:right;">Totals</td>
                    <td class="num"><?php echo $totals['total_paid']!==null?number_format($totals['total_paid'],2):'—'; ?></td>
                    <td class="num" style="color:#7c3aed;"><?php echo $totals['total_vat']!==null?number_format($totals['total_vat'],2):'—'; ?></td>
                    <td class="num" style="color:#c2410c;font-weight:700;"><?php echo $totals['total_net']!==null?number_format($totals['total_net'],2):'—'; ?></td>
                    <td class="num" style="color:#16a34a;"><?php echo number_format($totals['total_settled']??0,2); ?></td>
                    <td class="num" style="color:#d97706;"><?php echo number_format(($totals['total_paid']??0)-($totals['total_settled']??0),2); ?></td>
                    <td colspan="4"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MANAGE CLAIM PAYMENTS MODAL — 3 Tabs
     ════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="confirmPayModal">
    <div class="cpm-box">

        <!-- Header -->
        <div class="cpm-header">
            <div class="cpm-header-title">
                <i class="fa-solid fa-circle-check" style="color:#4ade80;font-size:18px;"></i>
                Manage Claim Payments — Drives &amp; Loyalty Programs
            </div>
            <button class="cpm-close" onclick="closeConfirmPayModal()">
                <i class="fa-solid fa-xmark"></i> Close
            </button>
        </div>

        <!-- Tabs -->
        <div class="cpm-tabs">
            <button class="cpm-tab active" id="tab-btn-claims" onclick="switchCpmTab('claims')">
                <i class="fa-solid fa-list-check"></i>
                Pending Claims
                <span class="tab-badge tab-badge-blue" id="tab-badge-claims">0</span>
            </button>
            <button class="cpm-tab" id="tab-btn-confirmed" onclick="switchCpmTab('confirmed')">
                <i class="fa-solid fa-check-circle"></i>
                Confirmed
                <span class="tab-badge tab-badge-green" id="tab-badge-confirmed">0</span>
            </button>
            <button class="cpm-tab" id="tab-btn-ack" onclick="switchCpmTab('ack')">
                <i class="fa-solid fa-envelope-open-text"></i>
                Cheque Acknowledgments
                <span class="tab-badge tab-badge-violet" id="tab-badge-ack">0</span>
            </button>
        </div>

        <!-- ── TAB 1: Pending Claim Items ─────────────────────── -->
        <div class="cpm-panel active" id="panel-claims">
            <!-- Info banner -->
            <div class="cpm-info-banner">
                <i class="fa-solid fa-circle-info"></i>
                Select items and use <strong>Confirm &amp; Add to D&amp;L</strong> OR <strong>Send to Cheque Ack</strong> — either action removes the item from this list.
            </div>
            <!-- Filter -->
            <div class="cpm-filterbar">
                <div class="cpm-fi">
                    <label>Search</label>
                    <input type="text" id="cpm_search" placeholder="Invoice no, description, entity…" style="width:220px;" onkeydown="if(event.key==='Enter')loadClaimItems()">
                </div>
                <div class="cpm-fi">
                    <label>Entity</label>
                    <input type="text" id="cpm_entity" placeholder="Filter entity…" style="width:140px;">
                </div>
                <div class="cpm-fi">
                    <label>Invoice Date From</label>
                    <input type="date" id="cpm_date_from" style="width:140px;">
                </div>
                <div class="cpm-fi">
                    <label>To</label>
                    <input type="date" id="cpm_date_to" style="width:140px;">
                </div>
                <button class="cpm-search-btn" onclick="loadClaimItems()"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                <button class="cpm-clear-btn" onclick="clearCpmFilters()">Clear</button>
            </div>

            <!-- Stats -->
            <div class="cpm-stats">
                <span class="cpm-stat"><b id="cpmStatTotal">0</b> pending records</span>
                <span class="cpm-stat">Selected: <b id="cpmStatSel" style="color:#d97706;">0</b></span>
            </div>

            <!-- Action bar -->
            <div class="cpm-actionbar">
                <button class="cpm-sel-all" onclick="cpmSelAll(true)"><i class="fa-solid fa-check-double"></i> Select All</button>
                <button class="cpm-sel-all" onclick="cpmSelAll(false)"><i class="fa-regular fa-square"></i> Deselect All</button>
                <div class="cpm-spacer"></div>
                <button class="btn-cpm-cheque" id="btnSendCheque" onclick="sendToChequeAck()" disabled>
                    <i class="fa-solid fa-envelope-open-text"></i> Send to Cheque Ack
                </button>
                <button class="btn-cpm-confirm" id="btnConfirmPay" onclick="confirmSelected()" disabled>
                    <i class="fa-solid fa-circle-check"></i> Confirm &amp; Add to D&amp;L
                </button>
            </div>

            <!-- Table -->
            <div class="cpm-body">
                <table class="cpm-table">
                    <thead>
                        <tr>
                            <th class="tc" style="width:36px;"><input type="checkbox" id="cpmAllCb" onchange="cpmSelAll(this.checked)"></th>
                            <th>#</th>
                            <th>Tax Invoice No.</th>
                            <th>Invoice Date</th>
                            <th>Banking Date</th>
                            <th>Entity</th>
                            <th>Claim Description</th>
                            <th>Ledger Type</th>
                            <th>Status</th>
                            <th class="tr">Actual Amt</th>
                            <th class="tr">VAT</th>
                            <th class="tr">Total</th>
                        </tr>
                    </thead>
                    <tbody id="cpmBody">
                        <tr><td colspan="12" style="padding:60px;text-align:center;color:#9ca3af;">
                            <i class="fa-solid fa-circle-info" style="font-size:28px;display:block;margin-bottom:10px;color:#bae6fd;"></i>
                            Click <strong>Search</strong> to load pending Drives &amp; Loyalty claim records
                        </td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Footer -->
            <div class="cpm-footer">
                <div class="cpm-footer-info" id="cpmFooterInfo">Select records to confirm or send to cheque acknowledgment.</div>
                <button class="btn btn-light btn-sm" onclick="closeConfirmPayModal()">Close</button>
            </div>
        </div>

        <!-- ── TAB 2: Confirmed Payments ──────────────────────── -->
        <div class="cpm-panel" id="panel-confirmed">
            <!-- Filter -->
            <div class="cpm-filterbar">
                <div class="cpm-fi">
                    <label>Search</label>
                    <input type="text" id="conf_search" placeholder="Invoice no, description, entity…" style="width:220px;" onkeydown="if(event.key==='Enter')loadConfirmedItems()">
                </div>
                <div class="cpm-fi">
                    <label>Entity</label>
                    <input type="text" id="conf_entity" placeholder="Filter entity…" style="width:140px;">
                </div>
                <div class="cpm-fi">
                    <label>Invoice Date From</label>
                    <input type="date" id="conf_date_from" style="width:140px;">
                </div>
                <div class="cpm-fi">
                    <label>To</label>
                    <input type="date" id="conf_date_to" style="width:140px;">
                </div>
                <button class="cpm-search-btn" onclick="loadConfirmedItems()"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                <button class="cpm-clear-btn" onclick="clearConfFilters()">Clear</button>
            </div>

            <!-- Stats -->
            <div class="cpm-stats" style="background:#f0fdf4;border-color:#bbf7d0;">
                <span class="cpm-stat" style="color:#166534;"><b id="confStatTotal" style="color:#0f172a;">0</b> confirmed records</span>
            </div>

            <!-- Table -->
            <div class="cpm-body">
                <table class="cpm-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Tax Invoice No.</th>
                            <th>Invoice Date</th>
                            <th>Banking Date</th>
                            <th>Entity</th>
                            <th>Claim Description</th>
                            <th>Ledger Type</th>
                            <th>Status</th>
                            <th>Claim Type</th>
                            <th class="tr">Actual Amt</th>
                            <th class="tr">VAT</th>
                            <th class="tr">Total</th>
                            <th>Confirmed At</th>
                            <th>Confirmed By</th>
                            <th class="tc">Reverse</th>
                        </tr>
                    </thead>
                    <tbody id="confBody">
                        <tr><td colspan="15" style="padding:60px;text-align:center;color:#9ca3af;">
                            <i class="fa-solid fa-check-circle" style="font-size:28px;display:block;margin-bottom:10px;color:#bbf7d0;"></i>
                            Click <strong>Search</strong> to load confirmed payment records
                        </td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Footer -->
            <div class="cpm-footer">
                <div class="cpm-footer-info" id="confFooterInfo">Confirmed payment records appear here.</div>
                <button class="btn btn-light btn-sm" onclick="closeConfirmPayModal()">Close</button>
            </div>
        </div>

        <!-- ── TAB 3: Cheque Acknowledgments ─────────────────── -->
        <div class="cpm-panel" id="panel-ack">
            <!-- Filter -->
            <div class="cpm-filterbar">
                <div class="cpm-fi">
                    <label>Search</label>
                    <input type="text" id="ack_search" placeholder="Invoice no, description, entity…" style="width:220px;" onkeydown="if(event.key==='Enter')loadAckItems()">
                </div>
                <div class="cpm-fi">
                    <label>Entity</label>
                    <input type="text" id="ack_entity" placeholder="Filter entity…" style="width:140px;">
                </div>
                <div class="cpm-fi">
                    <label>Invoice Date From</label>
                    <input type="date" id="ack_date_from" style="width:140px;">
                </div>
                <div class="cpm-fi">
                    <label>To</label>
                    <input type="date" id="ack_date_to" style="width:140px;">
                </div>
                <button class="cpm-search-btn" onclick="loadAckItems()"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                <button class="cpm-clear-btn" onclick="clearAckFilters()">Clear</button>
            </div>

            <!-- Stats -->
            <div class="cpm-stats" style="background:#fdf4ff;border-color:#e9d5ff;">
                <span class="cpm-stat" style="color:#6d28d9;"><b id="ackStatTotal" style="color:#0f172a;">0</b> acknowledgment records</span>
            </div>

            <!-- Table -->
            <div class="cpm-body">
                <table class="cpm-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Tax Invoice No.</th>
                            <th>Invoice Date</th>
                            <th>Banking Date</th>
                            <th>Entity</th>
                            <th>Claim Description</th>
                            <th>Ledger Type</th>
                            <th>Status</th>
                            <th>Claim Type</th>
                            <th class="tr">Actual Amt</th>
                            <th class="tr">VAT</th>
                            <th class="tr">Total</th>
                            <th>Sent At</th>
                            <th>Sent By</th>
                            <th class="tc">Reverse</th>
                        </tr>
                    </thead>
                    <tbody id="ackBody">
                        <tr><td colspan="15" style="padding:60px;text-align:center;color:#9ca3af;">
                            <i class="fa-solid fa-envelope-open-text" style="font-size:28px;display:block;margin-bottom:10px;color:#e9d5ff;"></i>
                            Click <strong>Search</strong> to load cheque acknowledgment records
                        </td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Footer -->
            <div class="cpm-footer">
                <div class="cpm-footer-info" id="ackFooterInfo">Cheque acknowledgment records appear here.</div>
                <button class="btn btn-light btn-sm" onclick="closeConfirmPayModal()">Close</button>
            </div>
        </div>

    </div><!-- /cpm-box -->
</div><!-- /confirmPayModal -->


<!-- ═══ ADD / EDIT MODAL ════════════════════════════════════════ -->
<div class="modal-overlay" id="entryModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-car"></i><span id="modalTitleText">Add New Entry</span></div>
            <button class="modal-close" onclick="closeAddModal()">×</button>
        </div>
        <div class="modal-body">
            <form id="entryForm" enctype="multipart/form-data" onsubmit="return false;">
                <input type="hidden" id="fid" name="id" value="0">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Invoice Date</label>
                        <input type="date" class="form-input" id="f_invoice_date" name="invoice_date">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Banking Date</label>
                        <input type="date" class="form-input" id="f_banking_date" name="banking_date">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Entity</label>
                        <input type="text" class="form-input" id="f_entity" name="entity" placeholder="e.g. IKEA Sri Lanka">
                    </div>
                    <div class="form-group span2">
                        <label class="form-label">Claim Reference</label>
                        <input type="text" class="form-input" id="f_claim_reference" name="claim_reference" placeholder="e.g. DL-2024-001">
                    </div>
                    <div></div>
                    <div class="form-group span3">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-input" id="f_description" name="description" placeholder="e.g. Driver loyalty claim — March 2024">
                    </div>
                    <hr class="form-divider">
                    <div class="form-group">
                        <label class="form-label">Paid Amount <span class="note">INCL. VAT</span></label>
                        <input type="number" step="0.01" min="0" class="form-input" id="f_paid_amount" name="paid_amount" placeholder="0.00" oninput="calcVAT()">
                        <div class="calc-hint"><i class="fa-solid fa-info-circle" style="color:#9ca3af;font-size:10px;"></i> Enter the gross amount (including VAT)</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">VAT 18% <span class="auto">AUTO</span></label>
                        <input type="number" step="0.01" class="form-input auto-fill" id="f_vat" name="vat" placeholder="0.00" readonly>
                        <div class="calc-hint"><i class="fa-solid fa-bolt" style="color:#7c3aed;font-size:10px;"></i> Paid Amount × <b>18/118</b></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Net Amount <span class="auto">AUTO</span></label>
                        <input type="number" step="0.01" class="form-input net-fill" id="f_net_amount" name="net_amount" placeholder="0.00" readonly>
                        <div class="calc-hint"><i class="fa-solid fa-bolt" style="color:#c2410c;font-size:10px;"></i> Paid Amount ÷ 1.18</div>
                    </div>
                    <hr class="form-divider">
                    <div class="form-group span3">
                        <label class="form-label">Supporting Document <span style="color:#9ca3af;font-weight:400;text-transform:none;">(Optional)</span></label>
                        <div id="existingDocWrap" style="display:none;" class="existing-doc">
                            <i class="fa-solid fa-paperclip" style="color:#1e40af;"></i>
                            <a id="existingDocLink" href="#" target="_blank">Current file</a>
                            <span style="font-size:11px;color:#9ca3af;">Upload new to replace</span>
                        </div>
                        <div class="file-drop" id="fileDrop"
                             ondragover="event.preventDefault();this.classList.add('drag')"
                             ondragleave="this.classList.remove('drag')"
                             ondrop="handleDrop(event)">
                            <input type="file" name="document" id="f_document" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" onchange="handleFileSelect(this)">
                            <div class="file-drop-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                            <div class="file-drop-text">Click or drag &amp; drop to upload</div>
                            <div class="file-drop-sub">PDF, DOC, DOCX, XLS, XLSX, JPG, PNG · Max 10 MB</div>
                        </div>
                        <div class="file-preview" id="filePreview">
                            <i class="fa-solid fa-file" style="color:#16a34a;"></i>
                            <span class="file-preview-name" id="filePreviewName"></span>
                            <button type="button" class="file-preview-rm" onclick="clearFile()"><i class="fa-solid fa-xmark"></i></button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-light" onclick="closeAddModal()">Cancel</button>
            <button class="btn btn-primary" id="saveBtn" onclick="saveEntry()"><i class="fa-solid fa-floppy-disk"></i> Save Entry</button>
        </div>
    </div>
</div>

<!-- ═══ SETTLEMENT MODAL ════════════════════════════════════════ -->
<div class="modal-overlay" id="settleModal">
    <div class="settle-modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-hand-holding-dollar" style="color:#7c3aed;"></i><span id="settleTitleText">Settlement</span></div>
            <button class="modal-close" onclick="closeSettleModal()">×</button>
        </div>
        <div class="settle-info-strip">
            <div class="sinfo-cell"><div class="sinfo-lbl">Entity</div><div class="sinfo-val" id="si_entity">—</div></div>
            <div class="sinfo-cell"><div class="sinfo-lbl">Paid Amount</div><div class="sinfo-val" id="si_amount">—</div></div>
            <div class="sinfo-cell"><div class="sinfo-lbl">Total Settled</div><div class="sinfo-val green" id="si_settled">0.00</div></div>
            <div class="sinfo-cell"><div class="sinfo-lbl">Balance</div><div class="sinfo-val" id="si_balance">—</div></div>
        </div>
        <div class="modal-body">
            <input type="hidden" id="s_dl_id" value="0">
            <input type="hidden" id="s_total_amount" value="0">
            <div style="overflow-x:auto;">
                <table class="settle-table">
                    <thead>
                        <tr>
                            <th style="min-width:130px;" class="num">Paid Amt by Yelo</th>
                            <th style="min-width:130px;" class="num">Running Balance</th>
                            <th style="min-width:110px;">Outlet Code</th>
                            <th style="min-width:160px;">Outlet Name</th>
                            <th style="min-width:130px;">Cheque No</th>
                            <th style="min-width:130px;">Cheque Date</th>
                            <th style="width:36px;"></th>
                        </tr>
                    </thead>
                    <tbody id="settleRows"></tbody>
                </table>
            </div>
            <button class="add-settle-btn" onclick="addSettleRow()"><i class="fa-solid fa-plus"></i> Add Row</button>
            <div class="settle-note"><i class="fa-solid fa-info-circle"></i> Running Balance auto-calculated per row.</div>
            <div class="settle-totals">
                <div class="stotal-cell"><div class="stotal-lbl">Paid Amount</div><div class="stotal-val" id="st_total">0.00</div></div>
                <div class="stotal-cell"><div class="stotal-lbl">Total Settled</div><div class="stotal-val purple" id="st_settled">0.00</div></div>
                <div class="stotal-cell"><div class="stotal-lbl">Balance</div><div class="stotal-val orange" id="st_balance">0.00</div></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-light" onclick="closeSettleModal()">Cancel</button>
            <button class="btn btn-primary" id="saveSettleBtn" onclick="saveSettlements()"><i class="fa-solid fa-floppy-disk"></i> Save Settlements</button>
        </div>
    </div>
</div>

<div id="toast"></div>

<script>
/* ════════════════════════════════════════════════════════════════
   HELPERS
   ════════════════════════════════════════════════════════════════ */
function esc(s){
    if(s==null)return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtN(v){
    return parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
}
function showToast(msg, type){
    const t=document.getElementById('toast');
    t.textContent=msg; t.className=type; t.style.display='block';
    clearTimeout(t._t); t._t=setTimeout(()=>t.style.display='none',4500);
}

/* ════ VAT CALC ══════════════════════════════════════════════════ */
function calcVAT(){
    const gross=parseFloat(document.getElementById('f_paid_amount').value||0)||0;
    const vat  =gross>0?Math.round(gross*18/118*100)/100:0;
    const net  =gross>0?Math.round(gross/1.18*100)/100:0;
    document.getElementById('f_vat').value       =gross>0?vat.toFixed(2):'';
    document.getElementById('f_net_amount').value=gross>0?net.toFixed(2):'';
}

/* ════ SEARCH ════════════════════════════════════════════════════ */
function doSearch(){
    const q=document.getElementById('searchBox').value.toLowerCase().trim();
    const rows=document.querySelectorAll('#tableBody tr[id^="tr-"]');
    let vis=0;
    rows.forEach(r=>{
        const show=!q||(r.dataset.search||'').includes(q);
        r.style.display=show?'':'none';
        if(show)vis++;
    });
    document.getElementById('rowCount').textContent=`(${vis} of ${rows.length})`;
}
document.addEventListener('DOMContentLoaded',()=>{
    const rows=document.querySelectorAll('#tableBody tr[id^="tr-"]');
    if(rows.length) document.getElementById('rowCount').textContent=`(${rows.length})`;
});

/* ════ ADD/EDIT MODAL ════════════════════════════════════════════ */
function openAddModal(title='Add New Entry'){
    document.getElementById('modalTitleText').textContent=title;
    document.getElementById('entryModal').classList.add('open');
}
function closeAddModal(){
    document.getElementById('entryModal').classList.remove('open');
    resetForm();
}
function resetForm(){
    document.getElementById('fid').value='0';
    ['f_invoice_date','f_banking_date','f_entity','f_claim_reference',
     'f_description','f_paid_amount','f_vat','f_net_amount'].forEach(id=>{
        document.getElementById(id).value='';
    });
    document.getElementById('existingDocWrap').style.display='none';
    clearFile();
}

/* ════ FILE ══════════════════════════════════════════════════════ */
function handleFileSelect(inp){if(inp.files[0])showFilePreview(inp.files[0].name);}
function handleDrop(e){
    e.preventDefault();
    document.getElementById('fileDrop').classList.remove('drag');
    const f=e.dataTransfer.files[0];if(!f)return;
    const dt=new DataTransfer();dt.items.add(f);
    document.getElementById('f_document').files=dt.files;
    showFilePreview(f.name);
}
function showFilePreview(name){
    document.getElementById('filePreviewName').textContent=name;
    document.getElementById('filePreview').classList.add('show');
    document.getElementById('fileDrop').style.display='none';
}
function clearFile(){
    document.getElementById('f_document').value='';
    document.getElementById('filePreview').classList.remove('show');
    document.getElementById('fileDrop').style.display='';
}

/* ════ SAVE ENTRY ════════════════════════════════════════════════ */
function saveEntry(){
    const id=document.getElementById('fid').value;
    const btn=document.getElementById('saveBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    fetch('dal.php?action=save',{method:'POST',body:new FormData(document.getElementById('entryForm'))})
    .then(r=>r.json())
    .then(res=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Entry';
        if(res.success){
            showToast(parseInt(id)>0?'Entry updated!':'Entry added!','success');
            closeAddModal();
            setTimeout(()=>location.reload(),800);
        } else showToast('Error: '+(res.message||'Unknown error'),'error');
    })
    .catch(err=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Entry';
        showToast('Network error: '+err.message,'error');
    });
}

/* ════ EDIT ROW ══════════════════════════════════════════════════ */
function editRow(id){
    fetch('dal.php?action=get&id='+id)
    .then(r=>r.json())
    .then(res=>{
        if(!res.success||!res.data){showToast('Could not load record.','error');return;}
        const d=res.data;
        document.getElementById('fid').value               =''+d.id;
        document.getElementById('f_invoice_date').value    =d.invoice_date    ||'';
        document.getElementById('f_banking_date').value    =d.banking_date    ||'';
        document.getElementById('f_entity').value          =d.entity          ||'';
        document.getElementById('f_claim_reference').value =d.claim_reference ||'';
        document.getElementById('f_description').value     =d.description     ||'';
        document.getElementById('f_paid_amount').value     =d.paid_amount     ||'';
        document.getElementById('f_vat').value             =d.vat             ||'';
        document.getElementById('f_net_amount').value      =d.net_amount      ||'';
        if(d.document_path&&d.document_name){
            document.getElementById('existingDocWrap').style.display='flex';
            document.getElementById('existingDocLink').href=d.document_path;
            document.getElementById('existingDocLink').textContent=d.document_name;
        }
        openAddModal('Edit Entry');
    })
    .catch(()=>showToast('Failed to load record.','error'));
}

/* ════ DELETE ════════════════════════════════════════════════════ */
function deleteRow(id){
    if(!confirm('Delete this entry and all its settlements? This cannot be undone.'))return;
    const fd=new FormData();fd.append('id',id);
    fetch('dal.php?action=delete',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(res=>{
        if(res.success){document.getElementById('tr-'+id)?.remove();showToast('Entry deleted.','success');}
        else showToast('Delete failed.','error');
    })
    .catch(()=>showToast('Network error.','error'));
}

/* ════ SETTLEMENT MODAL ══════════════════════════════════════════ */
let sRowCount=0;
function openSettleModal(dlId,entity,amount){
    sRowCount=0;
    document.getElementById('s_dl_id').value=dlId;
    document.getElementById('s_total_amount').value=amount;
    document.getElementById('si_entity').textContent=entity;
    document.getElementById('si_amount').textContent=parseFloat(amount).toFixed(2);
    document.getElementById('st_total').textContent=parseFloat(amount).toFixed(2);
    document.getElementById('settleRows').innerHTML='';
    fetch('dal.php?action=get_settlements&dl_id='+dlId)
    .then(r=>r.json())
    .then(res=>{
        if(res.success&&res.data.length) res.data.forEach(s=>addSettleRow(s));
        else addSettleRow();
        recalcSettle();
    })
    .catch(()=>{addSettleRow();recalcSettle();});
    document.getElementById('settleModal').classList.add('open');
}
function closeSettleModal(){document.getElementById('settleModal').classList.remove('open');}
function addSettleRow(data){
    sRowCount++;
    const tr=document.createElement('tr');
    tr.id='sr'+sRowCount;
    const pa=data?.paid_amount_by_yelo||'';
    const oc=ea(data?.outlet_code);
    const on=ea(data?.outlet_name);
    const cn=ea(data?.cheque_no);
    const cd=data?.cheque_date||'';
    tr.innerHTML=`
        <td><input type="number" step="0.01" min="0" class="settle-input" style="text-align:right;" placeholder="0.00" value="${pa}" oninput="recalcSettle()"></td>
        <td><input type="number" step="0.01" class="settle-input running-bal" readonly placeholder="0.00" tabindex="-1"></td>
        <td><input type="text" class="settle-input" placeholder="e.g. OC-001" value="${oc}"></td>
        <td><input type="text" class="settle-input" placeholder="Outlet name" value="${on}"></td>
        <td><input type="text" class="settle-input" placeholder="Cheque number" value="${cn}"></td>
        <td><input type="date" class="settle-input" value="${cd}"></td>
        <td><button class="settle-rm" onclick="this.closest('tr').remove();recalcSettle();" title="Remove"><i class="fa-solid fa-xmark"></i></button></td>`;
    document.getElementById('settleRows').appendChild(tr);
}
function ea(v){return (v||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');}
function recalcSettle(){
    const totalAmount=parseFloat(document.getElementById('s_total_amount').value||0);
    let runningBalance=totalAmount, totalSettled=0;
    document.querySelectorAll('#settleRows tr').forEach(tr=>{
        const inputs=tr.querySelectorAll('input');
        const paby=parseFloat(inputs[0]?.value||0)||0;
        totalSettled+=paby; runningBalance-=paby;
        if(inputs[1]) inputs[1].value=runningBalance.toFixed(2);
    });
    const finalBalance=totalAmount-totalSettled;
    document.getElementById('si_settled').textContent=totalSettled.toFixed(2);
    document.getElementById('si_balance').textContent=finalBalance.toFixed(2);
    document.getElementById('si_balance').style.color=finalBalance>0?'#d97706':(finalBalance<0?'#dc2626':'#16a34a');
    document.getElementById('st_settled').textContent=totalSettled.toFixed(2);
    document.getElementById('st_balance').textContent=finalBalance.toFixed(2);
    document.getElementById('st_balance').className='stotal-val '+(finalBalance>0?'orange':(finalBalance<0?'red':'green'));
}
function saveSettlements(){
    const dlId=document.getElementById('s_dl_id').value;
    const trs=document.querySelectorAll('#settleRows tr');
    if(!trs.length){showToast('Add at least one settlement row.','error');return;}
    const rows=[];let valid=true;
    trs.forEach(tr=>{
        const inputs=tr.querySelectorAll('input');
        const amt=parseFloat(inputs[0]?.value||0);
        if(!(amt>0)) valid=false;
        rows.push({paid_amount_by_yelo:amt,outlet_code:inputs[2]?.value||'',outlet_name:inputs[3]?.value||'',cheque_no:inputs[4]?.value||'',cheque_date:inputs[5]?.value||''});
    });
    if(!valid){showToast('Each row must have a paid amount > 0.','error');return;}
    const btn=document.getElementById('saveSettleBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    const fd=new FormData();fd.append('dl_id',dlId);fd.append('rows',JSON.stringify(rows));
    fetch('dal.php?action=save_settlements',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(res=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Settlements';
        if(res.success){
            showToast('Settlements saved!','success');
            const totalSettled=rows.reduce((s,r)=>s+(r.paid_amount_by_yelo||0),0);
            const totalAmount=parseFloat(document.getElementById('s_total_amount').value||0);
            const balance=totalAmount-totalSettled;
            const balCl=balance>0?'bal-pos':(balance<0?'bal-neg':'bal-zero');
            const ts=document.getElementById('td-settled-'+dlId);
            const tb=document.getElementById('td-balance-'+dlId);
            if(ts) ts.textContent=totalSettled>0?totalSettled.toFixed(2):'—';
            if(tb) tb.innerHTML=`<span class="${balCl}">${balance.toFixed(2)}</span>`;
            const sb=document.getElementById('settlebtn-'+dlId);
            if(sb){sb.className='btn btn-settle btn-xs settled';sb.innerHTML='<i class="fa-solid fa-check-circle"></i> Settled';}
            const lastRow=rows[rows.length-1];
            const tlcno=document.getElementById('td-lastcno-'+dlId);
            if(tlcno) tlcno.innerHTML=lastRow?.cheque_no
                ?`<span class="cheque-pill">${lastRow.cheque_no.replace(/&/g,'&amp;').replace(/</g,'&lt;')}</span>`
                :`<span style="color:#d1d5db;">—</span>`;
            const allOutlets=rows.map(r=>r.outlet_name).filter(Boolean).join(', ');
            const tol=document.getElementById('td-outlets-'+dlId);
            if(tol) tol.innerHTML=allOutlets
                ?`<span class="outlet-pill">${allOutlets.replace(/&/g,'&amp;').replace(/</g,'&lt;')}</span>`
                :`<span style="color:#d1d5db;">—</span>`;
            closeSettleModal();
        } else showToast('Error: '+(res.message||'Failed'),'error');
    })
    .catch(err=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Settlements';
        showToast('Network error: '+err.message,'error');
    });
}

/* ════════════════════════════════════════════════════════════════
   MANAGE CLAIM PAYMENTS MODAL — 3 Tabs
   ════════════════════════════════════════════════════════════════ */
let _cpmRows = [];
let _currentTab = 'claims';

function openConfirmPayModal(){
    document.getElementById('confirmPayModal').classList.add('open');
    if(_currentTab === 'claims') loadClaimItems();
    else if(_currentTab === 'confirmed') loadConfirmedItems();
    else loadAckItems();
}
function closeConfirmPayModal(){
    document.getElementById('confirmPayModal').classList.remove('open');
}

/* ── Tab switching ── */
function switchCpmTab(tab){
    _currentTab = tab;
    document.querySelectorAll('.cpm-tab').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('.cpm-panel').forEach(p=>p.classList.remove('active'));
    document.getElementById('tab-btn-'+tab).classList.add('active');
    document.getElementById('panel-'+tab).classList.add('active');
    if(tab==='claims') loadClaimItems();
    else if(tab==='confirmed') loadConfirmedItems();
    else loadAckItems();
}

/* ══════════════════════════════════════════════════════════════
   TAB 1 — PENDING CLAIM ITEMS
   Only shows items not yet confirmed and not yet in cheque ack
   ══════════════════════════════════════════════════════════════ */
function clearCpmFilters(){
    ['cpm_search','cpm_entity','cpm_date_from','cpm_date_to'].forEach(id=>document.getElementById(id).value='');
    loadClaimItems();
}

async function loadClaimItems(){
    const body=document.getElementById('cpmBody');
    body.innerHTML='<tr><td colspan="12" style="padding:40px;text-align:center;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:8px;"></i>Loading…</td></tr>';
    const params=new URLSearchParams({
        action:   'get_claim_items',
        search:   document.getElementById('cpm_search').value,
        entity:   document.getElementById('cpm_entity').value,
        date_from:document.getElementById('cpm_date_from').value,
        date_to:  document.getElementById('cpm_date_to').value,
    });
    try{
        const res=await fetch('dal.php?'+params.toString());
        const data=await res.json();
        if(!data.success) throw new Error('Load failed');
        _cpmRows=data.rows||[];
        renderCpmTable();
    }catch(e){
        body.innerHTML='<tr><td colspan="12" style="padding:30px;text-align:center;color:#dc2626;">Error: '+esc(e.message)+'</td></tr>';
    }
}

function renderCpmTable(){
    document.getElementById('cpmStatTotal').textContent=_cpmRows.length;
    document.getElementById('tab-badge-claims').textContent=_cpmRows.length;

    if(!_cpmRows.length){
        document.getElementById('cpmBody').innerHTML='<tr><td colspan="12" style="padding:40px;text-align:center;color:#9ca3af;"><i class="fa-solid fa-inbox" style="font-size:28px;display:block;margin-bottom:8px;"></i>No pending records found.</td></tr>';
        cpmUpdateCount(); return;
    }

    let html='';
    _cpmRows.forEach((r,idx)=>{
        const statusBadge=s=>{
            if(!s)return '—';
            if(s==='Completed') return `<span class="tag tag-green">${esc(s)}</span>`;
            if(s==='Pending')   return `<span class="tag tag-amber">${esc(s)}</span>`;
            return `<span class="tag tag-gray">${esc(s)}</span>`;
        };

        const total =parseFloat(r.total_amount ||0);
        const actual=parseFloat(r.actual_amount||0);
        const vat   =parseFloat(r.vat_amount   ||0);

        html+=`<tr id="cpm-tr-${r.id}" data-id="${r.id}">
            <td style="text-align:center;"><input type="checkbox" class="cpm-cb" data-id="${r.id}" onchange="cpmUpdateCount()"></td>
            <td style="font-family:monospace;font-size:11px;color:#6b7280;">${idx+1}</td>
            <td style="font-family:monospace;font-size:12px;font-weight:700;color:#312e81;">${esc(r.tax_invoice_no||'—')}</td>
            <td style="font-size:12px;">${r.invoice_date||'—'}</td>
            <td style="font-size:12px;color:#6b7280;">${r.banking_date||'—'}</td>
            <td><span style="display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#e0e7ff;color:#3730a3;">${esc(r.entity||'—')}</span></td>
            <td style="font-size:11px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(r.claim_description||'')}">${esc((r.claim_description||'').slice(0,50))}</td>
            <td><span class="tag tag-blue" style="font-size:10px;">${esc(r.ledger_type||'—')}</span></td>
            <td>${statusBadge(r.status)}</td>
            <td style="text-align:right;font-weight:700;">${fmtN(actual)}</td>
            <td style="text-align:right;color:#7c3aed;">${fmtN(vat)}</td>
            <td style="text-align:right;font-weight:800;color:#312e81;">${fmtN(total)}</td>
        </tr>`;
    });
    document.getElementById('cpmBody').innerHTML=html;
    cpmUpdateCount();
}

function cpmUpdateCount(){
    const checked=document.querySelectorAll('.cpm-cb:checked');
    const n=checked.length;
    document.getElementById('cpmStatSel').textContent=n;
    document.getElementById('cpmFooterInfo').textContent=n+' row(s) selected.';
    document.getElementById('btnConfirmPay').disabled=n===0;
    document.getElementById('btnSendCheque').disabled=n===0;
    const all=document.querySelectorAll('.cpm-cb');
    document.getElementById('cpmAllCb').checked=all.length>0&&n===all.length;
    document.querySelectorAll('#cpmBody tr[data-id]').forEach(tr=>{
        const cb=tr.querySelector('.cpm-cb');
        if(!cb)return;
        tr.classList.toggle('selected-row',cb.checked);
    });
}

function cpmSelAll(v){
    document.querySelectorAll('.cpm-cb').forEach(cb=>cb.checked=v);
    document.getElementById('cpmAllCb').checked=v;
    cpmUpdateCount();
}

/* ── Confirm & Add to D&L ── */
async function confirmSelected(){
    const cbs =Array.from(document.querySelectorAll('.cpm-cb:checked'));
    const ids =cbs.map(cb=>cb.dataset.id).filter(Boolean);
    if(!ids.length){showToast('Select at least one record.','error');return;}

    const btn=document.getElementById('btnConfirmPay');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Confirming…';
    const fd=new FormData();fd.append('ids',JSON.stringify(ids));
    try{
        const res =await fetch('dal.php?action=confirm_payments',{method:'POST',body:fd});
        const data=await res.json();
        if(!data.success) throw new Error(data.message||'Failed');
        showToast(`✓ ${data.inserted} record(s) confirmed & added to Drivers & Loyalty.${data.skipped?' '+data.skipped+' skipped.':''}`, 'success');
        // Remove confirmed rows from claims tab instantly
        cbs.forEach(cb=>{
            const tr=document.getElementById('cpm-tr-'+cb.dataset.id);
            if(tr) tr.remove();
        });
        _cpmRows=_cpmRows.filter(r=>!ids.includes(String(r.id)));
        document.getElementById('cpmStatTotal').textContent=_cpmRows.length;
        document.getElementById('tab-badge-claims').textContent=_cpmRows.length;
        cpmUpdateCount();
        // Refresh confirmed tab badge
        loadConfirmedBadge();
        setTimeout(()=>location.reload(),1800);
    }catch(e){
        showToast('Error: '+e.message,'error');
    }finally{
        btn.disabled=false;
        btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Confirm &amp; Add to D&amp;L';
    }
}

/* ── Send directly to Cheque Acknowledgment (no confirm required) ── */
async function sendToChequeAck(){
    const cbs=Array.from(document.querySelectorAll('.cpm-cb:checked'));
    const ids=cbs.map(cb=>cb.dataset.id).filter(Boolean);
    if(!ids.length){showToast('Select at least one record.','error');return;}

    const btn=document.getElementById('btnSendCheque');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Sending…';
    const fd=new FormData();fd.append('ids',JSON.stringify(ids));
    try{
        const res =await fetch('dal.php?action=send_to_cheque_ack',{method:'POST',body:fd});
        const data=await res.json();
        if(!data.success) throw new Error(data.message||'Failed');

        let msg=`✓ ${data.sent} record(s) sent to Cheque Acknowledgment.`;
        if(data.skipped) msg+=` ${data.skipped} skipped (already processed).`;
        showToast(msg,'info');

        // Remove sent rows from claims tab instantly
        cbs.forEach(cb=>{
            const tr=document.getElementById('cpm-tr-'+cb.dataset.id);
            if(tr) tr.remove();
        });
        _cpmRows=_cpmRows.filter(r=>!ids.includes(String(r.id)));
        document.getElementById('cpmStatTotal').textContent=_cpmRows.length;
        document.getElementById('tab-badge-claims').textContent=_cpmRows.length;
        cpmUpdateCount();
        // Refresh ack badge
        loadAckBadge();
    }catch(e){
        showToast('Error: '+e.message,'error');
    }finally{
        btn.disabled=false;
        btn.innerHTML='<i class="fa-solid fa-envelope-open-text"></i> Send to Cheque Ack';
    }
}

/* ══════════════════════════════════════════════════════════════
   TAB 2 — CONFIRMED PAYMENTS
   ══════════════════════════════════════════════════════════════ */
let _confRows=[];

function clearConfFilters(){
    ['conf_search','conf_entity','conf_date_from','conf_date_to'].forEach(id=>document.getElementById(id).value='');
    loadConfirmedItems();
}

async function loadConfirmedItems(){
    const body=document.getElementById('confBody');
    body.innerHTML='<tr><td colspan="15" style="padding:40px;text-align:center;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:8px;"></i>Loading…</td></tr>';
    const params=new URLSearchParams({
        action:   'get_confirmed_payments',
        search:   document.getElementById('conf_search').value,
        entity:   document.getElementById('conf_entity').value,
        date_from:document.getElementById('conf_date_from').value,
        date_to:  document.getElementById('conf_date_to').value,
    });
    try{
        const res=await fetch('dal.php?'+params.toString());
        const data=await res.json();
        if(!data.success) throw new Error('Load failed');
        _confRows=data.rows||[];
        renderConfTable();
    }catch(e){
        body.innerHTML='<tr><td colspan="15" style="padding:30px;text-align:center;color:#dc2626;">Error: '+esc(e.message)+'</td></tr>';
    }
}

async function loadConfirmedBadge(){
    try{
        const res=await fetch('dal.php?action=get_confirmed_payments');
        const data=await res.json();
        if(data.success){
            document.getElementById('tab-badge-confirmed').textContent=data.rows?.length||0;
            document.getElementById('confStatTotal').textContent=data.rows?.length||0;
        }
    }catch(e){}
}

function renderConfTable(){
    const cnt=_confRows.length;
    document.getElementById('confStatTotal').textContent=cnt;
    document.getElementById('tab-badge-confirmed').textContent=cnt;
    document.getElementById('confFooterInfo').textContent=cnt+' confirmed record(s).';

    if(!cnt){
        document.getElementById('confBody').innerHTML='<tr><td colspan="15" style="padding:40px;text-align:center;color:#9ca3af;"><i class="fa-solid fa-inbox" style="font-size:28px;display:block;margin-bottom:8px;"></i>No confirmed records found.</td></tr>';
        return;
    }
    let html='';
    _confRows.forEach((r,idx)=>{
        const actual=parseFloat(r.actual_amount||0);
        const vat   =parseFloat(r.vat_amount   ||0);
        const total =parseFloat(r.total_amount ||0);
        const confAt=r.confirmed_at?r.confirmed_at.slice(0,16):'—';
        html+=`<tr style="background:#f0fdf4;" id="conf-tr-${r.id}">
            <td style="font-family:monospace;font-size:11px;color:#6b7280;">${idx+1}</td>
            <td style="font-family:monospace;font-size:12px;font-weight:700;color:#312e81;">${esc(r.tax_invoice_no||'—')}</td>
            <td style="font-size:12px;">${r.invoice_date||'—'}</td>
            <td style="font-size:12px;color:#6b7280;">${r.banking_date||'—'}</td>
            <td><span style="display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#e0e7ff;color:#3730a3;">${esc(r.entity||'—')}</span></td>
            <td style="font-size:11px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(r.claim_description||'')}">${esc((r.claim_description||'').slice(0,50))}</td>
            <td><span class="tag tag-blue" style="font-size:10px;">${esc(r.ledger_type||'—')}</span></td>
            <td><span class="tag tag-gray" style="font-size:10px;">${esc(r.status||'—')}</span></td>
            <td><span class="tag tag-violet" style="font-size:10px;">${esc(r.claim_type||'—')}</span></td>
            <td style="text-align:right;font-weight:700;">${fmtN(actual)}</td>
            <td style="text-align:right;color:#7c3aed;">${fmtN(vat)}</td>
            <td style="text-align:right;font-weight:800;color:#312e81;">${fmtN(total)}</td>
            <td style="font-size:11px;color:#6b7280;">${confAt}</td>
            <td style="font-size:11px;">${esc(r.confirmed_by||'—')}</td>
            <td style="text-align:center;">
                <button class="btn-rev-sm" onclick="reverseConfirmation(${r.claim_item_id},${r.id})" title="Reverse confirmation">
                    <i class="fa-solid fa-rotate-left"></i> Reverse
                </button>
            </td>
        </tr>`;
    });
    document.getElementById('confBody').innerHTML=html;
}

/* ── Reverse Confirmation ── */
async function reverseConfirmation(claimItemId, confRowId){
    if(!confirm('Reverse this confirmation?\n\nThis will:\n• Remove it from Drivers & Loyalty records\n• Remove the confirmed payment record\n• Return it to Pending Claims\n\nThis cannot be undone.')) return;
    const fd=new FormData();fd.append('claim_item_id',claimItemId);
    try{
        const res =await fetch('dal.php?action=reverse_confirmation',{method:'POST',body:fd});
        const data=await res.json();
        if(!data.success) throw new Error(data.message||'Failed');
        showToast('Confirmation reversed. Item returned to Pending Claims.','warn');
        // Remove from confirmed tab
        document.getElementById('conf-tr-'+confRowId)?.remove();
        _confRows=_confRows.filter(r=>r.id!==confRowId);
        document.getElementById('confStatTotal').textContent=_confRows.length;
        document.getElementById('tab-badge-confirmed').textContent=_confRows.length;
        document.getElementById('confFooterInfo').textContent=_confRows.length+' confirmed record(s).';
        setTimeout(()=>location.reload(),1800);
    }catch(e){
        showToast('Error: '+e.message,'error');
    }
}

/* ══════════════════════════════════════════════════════════════
   TAB 3 — CHEQUE ACKNOWLEDGMENTS
   ══════════════════════════════════════════════════════════════ */
let _ackRows=[];

function clearAckFilters(){
    ['ack_search','ack_entity','ack_date_from','ack_date_to'].forEach(id=>document.getElementById(id).value='');
    loadAckItems();
}

async function loadAckBadge(){
    try{
        const res=await fetch('dal.php?action=get_cheque_acks');
        const data=await res.json();
        if(data.success){
            document.getElementById('tab-badge-ack').textContent=data.rows?.length||0;
            document.getElementById('ackStatTotal').textContent=data.rows?.length||0;
        }
    }catch(e){}
}

async function loadAckItems(){
    const body=document.getElementById('ackBody');
    body.innerHTML='<tr><td colspan="15" style="padding:40px;text-align:center;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:8px;"></i>Loading…</td></tr>';
    const params=new URLSearchParams({
        action:   'get_cheque_acks',
        search:   document.getElementById('ack_search')?.value||'',
        entity:   document.getElementById('ack_entity')?.value||'',
        date_from:document.getElementById('ack_date_from')?.value||'',
        date_to:  document.getElementById('ack_date_to')?.value||'',
    });
    try{
        const res=await fetch('dal.php?'+params.toString());
        const data=await res.json();
        if(!data.success) throw new Error('Load failed');
        _ackRows=data.rows||[];
        renderAckTable();
    }catch(e){
        body.innerHTML='<tr><td colspan="15" style="padding:30px;text-align:center;color:#dc2626;">Error: '+esc(e.message)+'</td></tr>';
    }
}

function renderAckTable(){
    const cnt=_ackRows.length;
    document.getElementById('ackStatTotal').textContent=cnt;
    document.getElementById('tab-badge-ack').textContent=cnt;
    document.getElementById('ackFooterInfo').textContent=cnt+' acknowledgment record(s).';

    if(!cnt){
        document.getElementById('ackBody').innerHTML='<tr><td colspan="15" style="padding:40px;text-align:center;color:#9ca3af;"><i class="fa-solid fa-inbox" style="font-size:28px;display:block;margin-bottom:8px;"></i>No cheque acknowledgment records found.</td></tr>';
        return;
    }
    let html='';
    _ackRows.forEach((r,idx)=>{
        const actual=parseFloat(r.actual_amount||0);
        const vat   =parseFloat(r.vat_amount   ||0);
        const total =parseFloat(r.total_amount ||0);
        const sentAt=r.ack_sent_at?r.ack_sent_at.slice(0,16):'—';
        html+=`<tr style="background:#fdf4ff;" id="ack-tr-${r.id}">
            <td style="font-family:monospace;font-size:11px;color:#6b7280;">${idx+1}</td>
            <td style="font-family:monospace;font-size:12px;font-weight:700;color:#312e81;">${esc(r.tax_invoice_no||'—')}</td>
            <td style="font-size:12px;">${r.invoice_date||'—'}</td>
            <td style="font-size:12px;color:#6b7280;">${r.banking_date||'—'}</td>
            <td><span style="display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#e0e7ff;color:#3730a3;">${esc(r.entity||'—')}</span></td>
            <td style="font-size:11px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(r.claim_description||'')}">${esc((r.claim_description||'').slice(0,50))}</td>
            <td><span class="tag tag-blue" style="font-size:10px;">${esc(r.ledger_type||'—')}</span></td>
            <td><span class="tag tag-gray" style="font-size:10px;">${esc(r.status||'—')}</span></td>
            <td><span class="tag tag-violet" style="font-size:10px;">${esc(r.claim_type||'—')}</span></td>
            <td style="text-align:right;font-weight:700;">${fmtN(actual)}</td>
            <td style="text-align:right;color:#7c3aed;">${fmtN(vat)}</td>
            <td style="text-align:right;font-weight:800;color:#312e81;">${fmtN(total)}</td>
            <td style="font-size:11px;color:#6b7280;">${sentAt}</td>
            <td style="font-size:11px;">${esc(r.ack_sent_by||'—')}</td>
            <td style="text-align:center;">
                <button class="btn-rev-sm" onclick="reverseChequeAck(${r.claim_item_id},${r.id})" title="Reverse cheque acknowledgment">
                    <i class="fa-solid fa-rotate-left"></i> Reverse
                </button>
            </td>
        </tr>`;
    });
    document.getElementById('ackBody').innerHTML=html;
}

/* ── Reverse Cheque Acknowledgment ── */
async function reverseChequeAck(claimItemId, ackRowId){
    if(!confirm('Reverse this cheque acknowledgment?\n\nThis will:\n• Remove it from Cheque Acknowledgment records\n• Return it to Pending Claims\n\nContinue?')) return;
    const fd=new FormData();fd.append('claim_item_id',claimItemId);
    try{
        const res =await fetch('dal.php?action=reverse_cheque_ack',{method:'POST',body:fd});
        const data=await res.json();
        if(!data.success) throw new Error(data.message||'Failed');
        showToast('Cheque acknowledgment reversed. Item returned to Pending Claims.','warn');
        // Remove from ack tab instantly
        document.getElementById('ack-tr-'+ackRowId)?.remove();
        _ackRows=_ackRows.filter(r=>r.id!==ackRowId);
        document.getElementById('ackStatTotal').textContent=_ackRows.length;
        document.getElementById('tab-badge-ack').textContent=_ackRows.length;
        document.getElementById('ackFooterInfo').textContent=_ackRows.length+' acknowledgment record(s).';
    }catch(e){
        showToast('Error: '+e.message,'error');
    }
}

/* ── Close on backdrop click ── */
document.getElementById('confirmPayModal').addEventListener('click',function(e){if(e.target===this)closeConfirmPayModal();});
document.getElementById('entryModal').addEventListener('click',function(e){if(e.target===this)closeAddModal();});
document.getElementById('settleModal').addEventListener('click',function(e){if(e.target===this)closeSettleModal();});
</script>
<?php include 'footer.php'; ?>