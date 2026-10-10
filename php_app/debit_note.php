<?php
include 'config.php';

function ensure_dn_tables($conn) {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS debit_note (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        paid_date           DATE NULL,
        category            VARCHAR(255) NULL,
        description         VARCHAR(500) NULL,
        submitted_to_portal TINYINT(1) DEFAULT 0,
        paid_amount         DECIMAL(14,2) NULL,
        vat                 DECIMAL(14,2) NULL,
        total_amount        DECIMAL(14,2) NULL,
        document_path       VARCHAR(500) NULL,
        document_name       VARCHAR(255) NULL,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP
    )");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS dn_settlements (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        dn_id               INT NOT NULL,
        claim_cert_item_id  INT NULL,
        tax_invoice_no      VARCHAR(255) NULL,
        entity              VARCHAR(255) NULL,
        banking_date        DATE NULL,
        bank_ref            VARCHAR(255) NULL,
        amount              DECIMAL(14,2) NULL DEFAULT 0,
        vat_amount          DECIMAL(14,2) NULL DEFAULT 0,
        total_amount        DECIMAL(14,2) NULL DEFAULT 0,
        claim_description   VARCHAR(500) NULL,
        claim_reference     VARCHAR(255) NULL,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dn (dn_id)
    )");

    $cols = ['claim_cert_item_id'=>'INT NULL AFTER dn_id',
             'entity'=>"VARCHAR(255) NULL AFTER tax_invoice_no",
             'bank_ref'=>"VARCHAR(255) NULL AFTER banking_date",
             'amount'=>"DECIMAL(14,2) NULL DEFAULT 0 AFTER bank_ref",
             'vat_amount'=>"DECIMAL(14,2) NULL DEFAULT 0 AFTER amount",
             'total_amount'=>"DECIMAL(14,2) NULL DEFAULT 0 AFTER vat_amount",
             'claim_description'=>"VARCHAR(500) NULL AFTER total_amount",
             'claim_reference'=>"VARCHAR(255) NULL AFTER claim_description"];
    foreach ($cols as $col => $def) {
        $chk = mysqli_query($conn, "SHOW COLUMNS FROM dn_settlements LIKE '$col'");
        if ($chk && mysqli_num_rows($chk) === 0) {
            @mysqli_query($conn, "ALTER TABLE dn_settlements ADD COLUMN $col $def");
        }
    }
    $chk2 = mysqli_query($conn, "SHOW COLUMNS FROM dn_settlements LIKE 'received_amount'");
    if ($chk2 && mysqli_num_rows($chk2) > 0) {
        @mysqli_query($conn, "UPDATE dn_settlements SET amount=received_amount, total_amount=received_amount WHERE amount=0 OR amount IS NULL");
    }

    // Ensure dl_cheque_acknowledgments has source/source_settlement_id columns
    $chk3 = mysqli_query($conn, "SHOW TABLES LIKE 'dl_cheque_acknowledgments'");
    if ($chk3 && mysqli_num_rows($chk3) > 0) {
        $c1 = mysqli_query($conn, "SHOW COLUMNS FROM dl_cheque_acknowledgments LIKE 'source'");
        if ($c1 && mysqli_num_rows($c1) === 0)
            @mysqli_query($conn, "ALTER TABLE dl_cheque_acknowledgments ADD COLUMN source VARCHAR(50) NULL DEFAULT 'dal' AFTER ack_sent_by");
        $c2 = mysqli_query($conn, "SHOW COLUMNS FROM dl_cheque_acknowledgments LIKE 'source_settlement_id'");
        if ($c2 && mysqli_num_rows($c2) === 0)
            @mysqli_query($conn, "ALTER TABLE dl_cheque_acknowledgments ADD COLUMN source_settlement_id INT NULL AFTER source");
    }
}

function _dn_settle_totals($conn, $dn_id) {
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(amount),0)       as settled_amount,
                COALESCE(SUM(vat_amount),0)   as settled_vat,
                COALESCE(SUM(total_amount),0) as settled_total,
                COUNT(*)                      as settle_count
         FROM dn_settlements WHERE dn_id=$dn_id"
    ));
    return $row;
}

function _get_dn_ack_claim_ids($conn, $dn_id) {
    $dn_id = intval($dn_id);
    $res = mysqli_query($conn,
        "SELECT claim_item_id FROM dl_cheque_acknowledgments WHERE source='dn_claim' AND confirmed_payment_id=$dn_id");
    $ids = [];
    while ($r = mysqli_fetch_assoc($res)) $ids[] = intval($r['claim_item_id']);
    return $ids;
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    ensure_dn_tables($conn);
    $action = $_GET['action'];

    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id           = intval($_POST['id'] ?? 0);
        $paid_date    = mysqli_real_escape_string($conn, trim($_POST['paid_date']    ?? ''));
        $category     = mysqli_real_escape_string($conn, trim($_POST['category']     ?? ''));
        $description  = mysqli_real_escape_string($conn, trim($_POST['description']  ?? ''));
        $submitted    = isset($_POST['submitted_to_portal']) && $_POST['submitted_to_portal'] == '1' ? 1 : 0;
        $paid_amount  = is_numeric($_POST['paid_amount']  ?? '') ? floatval($_POST['paid_amount'])  : null;
        $vat          = is_numeric($_POST['vat']          ?? '') ? floatval($_POST['vat'])          : null;
        $total_amount = is_numeric($_POST['total_amount'] ?? '') ? floatval($_POST['total_amount']) : null;

        $doc_path = null; $doc_name = null;
        if (!empty($_FILES['document']['name']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/debit_note/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext     = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png'];
            if (!in_array($ext, $allowed)) { echo json_encode(['success'=>false,'message'=>'Invalid file type.']); exit; }
            $safe = uniqid('dn_',true).'.'.$ext;
            if (move_uploaded_file($_FILES['document']['tmp_name'], $upload_dir.$safe)) {
                $doc_path = mysqli_real_escape_string($conn, $upload_dir.$safe);
                $doc_name = mysqli_real_escape_string($conn, $_FILES['document']['name']);
            }
        }

        $pd_sql = $paid_date    ? "'$paid_date'" : 'NULL';
        $pa_sql = $paid_amount  !== null ? $paid_amount  : 'NULL';
        $vt_sql = $vat          !== null ? $vat          : 'NULL';
        $ta_sql = $total_amount !== null ? $total_amount : 'NULL';

        if ($id > 0) {
            $doc_part = $doc_path !== null ? ", document_path='$doc_path', document_name='$doc_name'" : '';
            $sql = "UPDATE debit_note SET
                        paid_date=$pd_sql, category='$category', description='$description',
                        submitted_to_portal=$submitted,
                        paid_amount=$pa_sql, vat=$vt_sql, total_amount=$ta_sql $doc_part
                    WHERE id=$id";
        } else {
            $dp = $doc_path !== null ? "'$doc_path'" : 'NULL';
            $dn_val = $doc_name !== null ? "'$doc_name'" : 'NULL';
            $sql = "INSERT INTO debit_note
                        (paid_date,category,description,submitted_to_portal,paid_amount,vat,total_amount,document_path,document_name)
                    VALUES ($pd_sql,'$category','$description',$submitted,$pa_sql,$vt_sql,$ta_sql,$dp,$dn_val)";
        }
        $ok = mysqli_query($conn, $sql);
        echo json_encode(['success'=>(bool)$ok,'id'=>($ok&&!$id)?mysqli_insert_id($conn):$id,'message'=>$ok?'':mysqli_error($conn)]);
        exit;
    }

    if ($action === 'get') {
        $id  = intval($_GET['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM debit_note WHERE id=$id"));
        echo json_encode(['success'=>(bool)$row,'data'=>$row]);
        exit;
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id  = intval($_POST['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT document_path FROM debit_note WHERE id=$id"));
        if ($row && $row['document_path'] && file_exists($row['document_path'])) @unlink($row['document_path']);
        // Delete ack records for all settlements of this debit note
        $res_sids = mysqli_query($conn, "SELECT id FROM dn_settlements WHERE dn_id=$id");
        while ($sr = mysqli_fetch_assoc($res_sids)) {
            $sid = intval($sr['id']);
            mysqli_query($conn, "DELETE FROM dl_cheque_acknowledgments WHERE source='dn' AND source_settlement_id=$sid");
        }
        // Delete direct claim-level ack records tied to this debit note
        mysqli_query($conn, "DELETE FROM dl_cheque_acknowledgments WHERE source='dn_claim' AND confirmed_payment_id=$id");
        mysqli_query($conn, "DELETE FROM dn_settlements WHERE dn_id=$id");
        $ok = mysqli_query($conn, "DELETE FROM debit_note WHERE id=$id");
        echo json_encode(['success'=>(bool)$ok]);
        exit;
    }

    if ($action === 'get_claim_matches') {
        $dn_id = intval($_GET['dn_id'] ?? 0);
        $rec   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM debit_note WHERE id=$dn_id LIMIT 1"));

        $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'claim_cert_items'");
        if (!$tbl || mysqli_num_rows($tbl) === 0) {
            echo json_encode(['success'=>true,'claims'=>[],'settled_ids'=>[],'record'=>$rec,'claim_settled_map'=>[],'ack_claim_ids'=>[]]);
            exit;
        }

        $res = mysqli_query($conn,
            "SELECT ci.*,
                    COALESCE((
                        SELECT SUM(ds.amount) FROM dn_settlements ds
                        WHERE ds.claim_cert_item_id = ci.id
                    ),0) AS globally_settled_amount,
                    COALESCE((
                        SELECT SUM(ds.total_amount) FROM dn_settlements ds
                        WHERE ds.claim_cert_item_id = ci.id
                    ),0) AS globally_settled_total,
                    COALESCE((
                        SELECT SUM(ds.amount) FROM dn_settlements ds
                        WHERE ds.claim_cert_item_id = ci.id AND ds.dn_id = $dn_id
                    ),0) AS this_dn_settled_amount,
                    COALESCE((
                        SELECT SUM(ds.total_amount) FROM dn_settlements ds
                        WHERE ds.claim_cert_item_id = ci.id AND ds.dn_id = $dn_id
                    ),0) AS this_dn_settled_total
             FROM claim_cert_items ci
             WHERE ci.claim_type = 'Muthupalasa BP Claims'
             ORDER BY ci.invoice_date DESC, ci.id DESC
             LIMIT 500"
        );
        $claims = [];
        while ($r = mysqli_fetch_assoc($res)) $claims[] = $r;

        $res2 = mysqli_query($conn,
            "SELECT claim_cert_item_id FROM dn_settlements
             WHERE dn_id=$dn_id AND claim_cert_item_id IS NOT NULL AND claim_cert_item_id > 0"
        );
        $settled_ids = [];
        while ($r2 = mysqli_fetch_assoc($res2)) $settled_ids[] = intval($r2['claim_cert_item_id']);

        $ack_claim_ids = _get_dn_ack_claim_ids($conn, $dn_id);

        echo json_encode([
            'success'       => true,
            'claims'        => $claims,
            'settled_ids'   => $settled_ids,
            'record'        => $rec,
            'ack_claim_ids' => $ack_claim_ids
        ]);
        exit;
    }

    if ($action === 'add_settlement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $dn_id         = intval($_POST['dn_id']               ?? 0);
        $claim_item_id = intval($_POST['claim_cert_item_id']  ?? 0);
        $tax_inv       = mysqli_real_escape_string($conn, trim($_POST['tax_invoice_no']    ?? ''));
        $entity        = mysqli_real_escape_string($conn, trim($_POST['entity']            ?? ''));
        $bank_date     = mysqli_real_escape_string($conn, trim($_POST['banking_date']      ?? ''));
        $bank_ref      = mysqli_real_escape_string($conn, trim($_POST['bank_ref']          ?? ''));
        $amount        = is_numeric($_POST['amount']     ?? '') ? floatval($_POST['amount'])     : 0;
        $vat_amount    = is_numeric($_POST['vat_amount'] ?? '') ? floatval($_POST['vat_amount']) : 0;
        $total_amount  = $amount + $vat_amount;
        $claim_desc    = mysqli_real_escape_string($conn, trim($_POST['claim_description'] ?? ''));
        $claim_ref     = mysqli_real_escape_string($conn, trim($_POST['claim_reference']   ?? ''));

        if (!$dn_id) { echo json_encode(['success'=>false,'message'=>'Invalid record.']); exit; }

        $bd_sql = $bank_date     ? "'$bank_date'"  : 'NULL';
        $ci_sql = $claim_item_id > 0 ? $claim_item_id : 'NULL';

        $ok = mysqli_query($conn,
            "INSERT INTO dn_settlements
                (dn_id, claim_cert_item_id, tax_invoice_no, entity, banking_date, bank_ref,
                 amount, vat_amount, total_amount, claim_description, claim_reference)
             VALUES($dn_id,$ci_sql,'$tax_inv','$entity',$bd_sql,'$bank_ref',
                    $amount,$vat_amount,$total_amount,'$claim_desc','$claim_ref')"
        );
        $new_id = $ok ? mysqli_insert_id($conn) : 0;
        $totals = _dn_settle_totals($conn, $dn_id);

        // Return ack_ids for this dn
        $ack_ids = _get_dn_ack_ids($conn, $dn_id);
        echo json_encode(['success'=>(bool)$ok,'id'=>$new_id,'totals'=>$totals,'ack_ids'=>$ack_ids,'message'=>$ok?'':mysqli_error($conn)]);
        exit;
    }

    if ($action === 'delete_settlement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $sid   = intval($_POST['settlement_id'] ?? 0);
        $dn_id = intval($_POST['dn_id']         ?? 0);
        // Remove from ack if sent
        mysqli_query($conn, "DELETE FROM dl_cheque_acknowledgments WHERE source='dn' AND source_settlement_id=$sid");
        $ok    = mysqli_query($conn, "DELETE FROM dn_settlements WHERE id=$sid AND dn_id=$dn_id");
        $totals= _dn_settle_totals($conn, $dn_id);
        $ack_ids = _get_dn_ack_ids($conn, $dn_id);
        echo json_encode(['success'=>(bool)$ok,'totals'=>$totals,'ack_ids'=>$ack_ids]);
        exit;
    }

    if ($action === 'get_settlements') {
        $dn_id = intval($_GET['dn_id'] ?? 0);
        $res   = mysqli_query($conn, "SELECT * FROM dn_settlements WHERE dn_id=$dn_id ORDER BY id");
        $data  = [];
        while ($r = mysqli_fetch_assoc($res)) $data[] = $r;
        $totals  = _dn_settle_totals($conn, $dn_id);
        $ack_ids = _get_dn_ack_ids($conn, $dn_id);
        echo json_encode(['success'=>true,'data'=>$data,'totals'=>$totals,'ack_ids'=>$ack_ids]);
        exit;
    }

    // ── Send settlement(s) to Cheque Acknowledgment ──
    if ($action === 'send_to_ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $ids   = json_decode($_POST['ids'] ?? '[]', true);
        $dn_id = intval($_POST['dn_id']    ?? 0);
        if (!is_array($ids) || empty($ids) || !$dn_id) { echo json_encode(['success'=>false,'message'=>'Invalid data.']); exit; }

        $sent_by = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'system';
        $sb_e    = mysqli_real_escape_string($conn, $sent_by);
        $sent = 0; $skipped = 0;

        foreach ($ids as $sid) {
            $sid = intval($sid);
            // Skip if already acked
            $chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM dl_cheque_acknowledgments WHERE source='dn' AND source_settlement_id=$sid LIMIT 1"));
            if ($chk) { $skipped++; continue; }

            $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM dn_settlements WHERE id=$sid AND dn_id=$dn_id LIMIT 1"));
            if (!$row) { $skipped++; continue; }

            $prec = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM debit_note WHERE id=$dn_id LIMIT 1"));

            $bd_sql  = !empty($row['banking_date'])  ? "'{$row['banking_date']}'"  : 'NULL';
            $inv_d   = !empty($prec['paid_date'])    ? "'{$prec['paid_date']}'"    : 'NULL';
            $ci_sql  = intval($row['claim_cert_item_id'] ?? 0);
            $ti_e    = mysqli_real_escape_string($conn, $row['tax_invoice_no']    ?? '');
            $br_e    = mysqli_real_escape_string($conn, $row['bank_ref']          ?? '');
            $cd_e    = mysqli_real_escape_string($conn, $row['claim_description'] ?? '');
            $en_e    = mysqli_real_escape_string($conn, $row['entity']            ?? ($prec['entity'] ?? ''));
            $aa      = floatval($row['amount']      ?? 0);
            $va      = floatval($row['vat_amount']  ?? 0);
            $ta      = floatval($row['total_amount']?? 0);
            $lt_e    = 'Muthupalasa BP Claims';
            $st_e    = 'Confirmed';
            $ct_e    = 'Muthupalasa BP Claims';
            $cc_e    = '';
            $cn_e    = '';

            // Pull from claim_cert_items if linked
            if ($ci_sql > 0) {
                $ci_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM claim_cert_items WHERE id=$ci_sql LIMIT 1"));
                if ($ci_row) {
                    $lt_e = mysqli_real_escape_string($conn, $ci_row['ledger_type']   ?? $lt_e);
                    $st_e = mysqli_real_escape_string($conn, $ci_row['status']        ?? $st_e);
                    $ct_e = mysqli_real_escape_string($conn, $ci_row['claim_type']    ?? $ct_e);
                    $cc_e = mysqli_real_escape_string($conn, $ci_row['customer_code'] ?? '');
                    $cn_e = mysqli_real_escape_string($conn, $ci_row['customer_name'] ?? '');
                    if (!$ti_e) $ti_e = mysqli_real_escape_string($conn, $ci_row['tax_invoice_no'] ?? '');
                }
            }

            $ins = mysqli_query($conn,
                "INSERT INTO dl_cheque_acknowledgments
                    (confirmed_payment_id,claim_item_id,tax_invoice_no,invoice_date,banking_date,
                     entity,claim_description,actual_amount,vat_amount,total_amount,
                     ledger_type,status,claim_type,customer_code,customer_name,
                     ack_sent_by,source,source_settlement_id)
                 VALUES (NULL,$ci_sql,'$ti_e',$inv_d,$bd_sql,
                         '$en_e','$cd_e',$aa,$va,$ta,
                         '$lt_e','$st_e','$ct_e','$cc_e','$cn_e',
                         '$sb_e','dn',$sid)"
            );
            if ($ins) $sent++; else $skipped++;
        }

        $ack_ids = _get_dn_ack_ids($conn, $dn_id);
        echo json_encode(['success'=>true,'sent'=>$sent,'skipped'=>$skipped,'ack_ids'=>$ack_ids]);
        exit;
    }

    // ── Reverse Acknowledgment (settlement-level) ──
    if ($action === 'reverse_ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $sid   = intval($_POST['settlement_id'] ?? 0);
        $dn_id = intval($_POST['dn_id']         ?? 0);
        $ok    = mysqli_query($conn, "DELETE FROM dl_cheque_acknowledgments WHERE source='dn' AND source_settlement_id=$sid");
        $totals  = _dn_settle_totals($conn, $dn_id);
        $ack_ids = _get_dn_ack_ids($conn, $dn_id);
        echo json_encode(['success'=>(bool)$ok,'totals'=>$totals,'ack_ids'=>$ack_ids]);
        exit;
    }

    // ── Direct claim-to-ack (no settlement/payment record needed) ──
    if ($action === 'direct_claim_ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $dn_id         = intval($_POST['dn_id']         ?? 0);
        $claim_item_id = intval($_POST['claim_item_id'] ?? 0);

        if (!$dn_id || !$claim_item_id) { echo json_encode(['success'=>false,'message'=>'Invalid data.']); exit; }

        $chk = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM dl_cheque_acknowledgments
             WHERE source='dn_claim' AND confirmed_payment_id=$dn_id AND claim_item_id=$claim_item_id LIMIT 1"));
        if ($chk) { echo json_encode(['success'=>false,'message'=>'Already sent to acknowledgment.']); exit; }

        $prec   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM debit_note WHERE id=$dn_id LIMIT 1"));
        $ci_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM claim_cert_items WHERE id=$claim_item_id LIMIT 1"));

        if (!$prec || !$ci_row) { echo json_encode(['success'=>false,'message'=>'Record not found.']); exit; }

        $sent_by = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'system';
        $sb_e    = mysqli_real_escape_string($conn, $sent_by);

        $inv_d  = !empty($prec['paid_date'])      ? "'{$prec['paid_date']}'"      : 'NULL';
        $bd_sql = !empty($ci_row['banking_date']) ? "'{$ci_row['banking_date']}'" : 'NULL';
        $ti_e   = mysqli_real_escape_string($conn, $ci_row['tax_invoice_no']    ?? '');
        $en_e   = mysqli_real_escape_string($conn, $ci_row['entity']            ?? '');
        $cd_e   = mysqli_real_escape_string($conn, $ci_row['claim_description'] ?? '');
        $aa     = floatval($ci_row['actual_amount'] ?? 0);
        $va     = floatval($ci_row['vat_amount']    ?? 0);
        $ta     = floatval($ci_row['total_amount']  ?? 0);
        $lt_e   = mysqli_real_escape_string($conn, $ci_row['ledger_type']   ?? 'Muthupalasa BP Claims');
        $st_e   = mysqli_real_escape_string($conn, $ci_row['status']        ?? 'Confirmed');
        $ct_e   = mysqli_real_escape_string($conn, $ci_row['claim_type']    ?? 'Muthupalasa BP Claims');
        $cc_e   = mysqli_real_escape_string($conn, $ci_row['customer_code'] ?? '');
        $cn_e   = mysqli_real_escape_string($conn, $ci_row['customer_name'] ?? '');

        $ins = mysqli_query($conn,
            "INSERT INTO dl_cheque_acknowledgments
                (confirmed_payment_id,claim_item_id,tax_invoice_no,invoice_date,banking_date,entity,claim_description,actual_amount,vat_amount,total_amount,ledger_type,status,claim_type,customer_code,customer_name,ack_sent_by,source,source_settlement_id)
             VALUES
                ($dn_id,$claim_item_id,'$ti_e',$inv_d,$bd_sql,'$en_e','$cd_e',$aa,$va,$ta,'$lt_e','$st_e','$ct_e','$cc_e','$cn_e','$sb_e','dn_claim',NULL)");

        if (!$ins) { echo json_encode(['success'=>false,'message'=>mysqli_error($conn)]); exit; }

        $ack_claim_ids = _get_dn_ack_claim_ids($conn, $dn_id);
        echo json_encode(['success'=>true,'ack_claim_ids'=>$ack_claim_ids]);
        exit;
    }

    // ── Reverse direct claim-to-ack ──
    if ($action === 'reverse_direct_claim_ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $dn_id         = intval($_POST['dn_id']         ?? 0);
        $claim_item_id = intval($_POST['claim_item_id'] ?? 0);

        if (!$dn_id || !$claim_item_id) { echo json_encode(['success'=>false,'message'=>'Invalid data.']); exit; }

        $ok = mysqli_query($conn,
            "DELETE FROM dl_cheque_acknowledgments
             WHERE source='dn_claim' AND confirmed_payment_id=$dn_id AND claim_item_id=$claim_item_id");

        $ack_claim_ids = _get_dn_ack_claim_ids($conn, $dn_id);
        echo json_encode(['success'=>(bool)$ok,'ack_claim_ids'=>$ack_claim_ids]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']);
    exit;
}

function _get_dn_ack_ids($conn, $dn_id) {
    $dn_id = intval($dn_id);
    $res_all = mysqli_query($conn, "SELECT id FROM dn_settlements WHERE dn_id=$dn_id");
    $all_sids = [];
    while ($r = mysqli_fetch_assoc($res_all)) $all_sids[] = intval($r['id']);
    if (empty($all_sids)) return [];
    $in = implode(',', $all_sids);
    $res_ack = mysqli_query($conn, "SELECT source_settlement_id FROM dl_cheque_acknowledgments WHERE source='dn' AND source_settlement_id IN ($in)");
    $ack_ids = [];
    while ($r = mysqli_fetch_assoc($res_ack)) $ack_ids[] = intval($r['source_settlement_id']);
    return $ack_ids;
}

ensure_dn_tables($conn);

$filter_date_from = isset($_GET['filter_date_from']) ? mysqli_real_escape_string($conn,$_GET['filter_date_from']) : '';
$filter_date_to   = isset($_GET['filter_date_to'])   ? mysqli_real_escape_string($conn,$_GET['filter_date_to'])   : '';
$filter_category  = isset($_GET['filter_category'])  ? mysqli_real_escape_string($conn,$_GET['filter_category'])  : '';
$filter_submitted = isset($_GET['filter_submitted']) && $_GET['filter_submitted'] !== '' ? intval($_GET['filter_submitted']) : '';

$where = [];
if ($filter_date_from) $where[] = "p.paid_date >= '$filter_date_from'";
if ($filter_date_to)   $where[] = "p.paid_date <= '$filter_date_to'";
if ($filter_category)  $where[] = "p.category LIKE '%$filter_category%'";
if ($filter_submitted !== '') $where[] = "p.submitted_to_portal = $filter_submitted";
$where_sql = $where ? 'WHERE '.implode(' AND ',$where) : '';

$rows_result = mysqli_query($conn,
    "SELECT p.*,
            COALESCE((SELECT SUM(s.amount)       FROM dn_settlements s WHERE s.dn_id=p.id),0) AS settled_amount,
            COALESCE((SELECT SUM(s.vat_amount)   FROM dn_settlements s WHERE s.dn_id=p.id),0) AS settled_vat,
            COALESCE((SELECT SUM(s.total_amount) FROM dn_settlements s WHERE s.dn_id=p.id),0) AS settled_total,
            (SELECT MAX(s.banking_date) FROM dn_settlements s WHERE s.dn_id=p.id) AS last_banking_date,
            (SELECT s.claim_reference   FROM dn_settlements s WHERE s.dn_id=p.id ORDER BY s.id DESC LIMIT 1) AS last_claim_ref,
            (SELECT GROUP_CONCAT(s.tax_invoice_no ORDER BY s.id SEPARATOR ', ') FROM dn_settlements s WHERE s.dn_id=p.id) AS tax_inv_refs
     FROM debit_note p $where_sql
     ORDER BY p.paid_date DESC, p.id DESC"
);

$w2 = $where ? str_replace('p.', 'p2.', $where_sql) : '';
$totals = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as cnt,
            SUM(p.paid_amount)  as total_paid,
            SUM(p.vat)          as total_vat,
            SUM(p.total_amount) as total_amount,
            COALESCE((SELECT SUM(s.amount)       FROM dn_settlements s INNER JOIN debit_note p2 ON s.dn_id=p2.id $w2),0) as settled_amount,
            COALESCE((SELECT SUM(s.vat_amount)   FROM dn_settlements s INNER JOIN debit_note p2 ON s.dn_id=p2.id $w2),0) as settled_vat,
            COALESCE((SELECT SUM(s.total_amount) FROM dn_settlements s INNER JOIN debit_note p2 ON s.dn_id=p2.id $w2),0) as settled_total
     FROM debit_note p $where_sql"
));
if (!$totals) $totals = ['cnt'=>0,'total_paid'=>0,'total_vat'=>0,'total_amount'=>0,'settled_amount'=>0,'settled_vat'=>0,'settled_total'=>0];

include 'header.php';
?>
<style>
*,*::before,*::after{box-sizing:border-box}
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:0;color:#1f2937;display:flex;align-items:center;gap:8px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;font-family:inherit;text-decoration:none;white-space:nowrap}
.btn-sm{padding:7px 14px;font-size:13px}.btn-xs{padding:5px 10px;font-size:12px}
.btn-primary{background:#000;color:#fff}.btn-primary:hover{background:#1f2937}
.btn-dark{background:#000;color:#fff}.btn-dark:hover{background:#1f2937}
.btn-light{background:#f9fafb;color:#374151;border:1px solid #d1d5db}.btn-light:hover{background:#f3f4f6}
.btn-settle{background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe}.btn-settle:hover{background:#7c3aed;color:#fff}
.btn-settle.settled{background:#f0fdf4;color:#16a34a;border-color:#86efac}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.summary-box{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:14px}
.summary-label{font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px}
.summary-value{font-size:18px;font-weight:700;color:#1f2937}
.summary-sub{font-size:11px;color:#9ca3af;margin-top:2px}
.fbar-group{display:flex;flex-direction:column;gap:4px}
.fbar-label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px}
.fbar-input,.fbar-select{padding:8px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;outline:none;height:36px;background:#fff;font-family:inherit}
.fbar-input:focus,.fbar-select:focus{border-color:#000}
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.search-wrap{position:relative;flex:1;min-width:180px;max-width:320px}
.search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;pointer-events:none}
.search-input{width:100%;padding:8px 12px 8px 30px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;outline:none;font-family:inherit}
.search-input:focus{border-color:#000}
.table-wrap{max-height:65vh;overflow:auto;border:1px solid #e5e5e5;border-radius:8px}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead tr{position:sticky;top:0;z-index:10}
.data-table thead th{background:#f0f0f0;padding:10px 12px;text-align:left;font-weight:600;color:#222;font-size:12px;white-space:nowrap;border-bottom:2px solid #d5d5d5;box-shadow:0 2px 0 #d5d5d5}
.data-table th.num,.data-table td.num{text-align:right}
.data-table tbody tr{border-bottom:1px solid #f0f0f0;transition:background .1s}
.data-table tbody tr:hover{background:#fafafa}
.data-table td{padding:9px 12px;color:#333;white-space:nowrap;vertical-align:middle}
.data-table tfoot td{padding:10px 12px;font-weight:700;color:#1f2937;background:#f9fafb;border-top:2px solid #e5e5e5;font-size:13px}
.action-buttons{display:flex;gap:5px;justify-content:center;align-items:center}
.btn-action{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;color:#6b7280;cursor:pointer;transition:all .2s;font-size:12px}
.btn-action:hover{transform:translateY(-1px);box-shadow:0 2px 4px rgba(0,0,0,.1)}
.btn-edit-r:hover{background:#000;color:#fff;border-color:#000}
.btn-del-r:hover{background:#ef4444;color:#fff;border-color:#ef4444}
.doc-icon-link{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;font-size:13px;transition:all .2s;text-decoration:none}
.doc-icon-link:hover{background:#1e40af;color:#fff;border-color:#1e40af}
.no-doc{color:#d1d5db;font-size:16px}
.bal-pos{color:#16a34a;font-weight:700}.bal-neg{color:#dc2626;font-weight:700}.bal-zero{color:#6b7280;font-weight:600}
.vat-pill{font-size:11px;font-weight:700;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;border-radius:10px;padding:2px 8px}
.cat-badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:12px;font-size:11px;font-weight:700;background:#fef3c7;color:#92400e;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.claim-pill{font-size:11px;font-family:monospace;background:#fdf4ff;color:#7e22ce;border:1px solid #e9d5ff;border-radius:5px;padding:2px 7px}
.taxref-pill{font-size:11px;font-family:monospace;background:#f8fafc;color:#475569;border:1px solid #e2e8f0;border-radius:5px;padding:2px 7px}
.portal-yes{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;background:#dcfce7;color:#16a34a;border-radius:10px;padding:2px 9px;border:1px solid #bbf7d0}
.portal-no{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;background:#f3f4f6;color:#9ca3af;border-radius:10px;padding:2px 9px;border:1px solid #e5e7eb}
.empty-state{text-align:center;padding:60px 20px;color:#9ca3af}
.empty-state i{font-size:40px;margin-bottom:12px;display:block}
/* ── Entry Modal ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;display:none;align-items:center;justify-content:center;padding:16px}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:12px;width:96%;max-width:760px;max-height:93vh;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden}
.modal-header{padding:18px 24px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;flex-shrink:0}
.modal-title{font-size:17px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px}
.modal-close{background:none;border:none;cursor:pointer;color:#9ca3af;font-size:26px;line-height:1;padding:0}
.modal-close:hover{color:#1f2937}
.modal-body{padding:22px 24px;overflow-y:auto;flex:1}
.modal-footer{padding:14px 24px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0}
.form-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.form-grid .span2{grid-column:span 2}.form-grid .span3{grid-column:span 3}
.form-group{display:flex;flex-direction:column;gap:4px}
.form-label{font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:.4px}
.form-label .auto{font-size:10px;background:#dbeafe;color:#1e40af;border-radius:4px;padding:1px 5px;font-weight:700;text-transform:none;letter-spacing:0;margin-left:3px}
.form-input,.form-select{padding:9px 11px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;font-family:inherit;color:#1f2937;outline:none;width:100%}
.form-input:focus,.form-select:focus{border-color:#000;box-shadow:0 0 0 3px rgba(0,0,0,.05)}
.form-input.auto-fill{background:#f0fdf4;border-color:#bbf7d0;color:#166534;font-weight:700}
.form-divider{grid-column:span 3;border:none;border-top:1px solid #f0f0f0;margin:2px 0}
.calc-hint{font-size:11px;color:#9ca3af;margin-top:2px}
input[type=number]::-webkit-inner-spin-button,input[type=number]::-webkit-outer-spin-button{-webkit-appearance:none}
input[type=number]{-moz-appearance:textfield;appearance:textfield}
.file-drop{border:2px dashed #d1d5db;border-radius:8px;padding:16px;text-align:center;cursor:pointer;background:#fafafa;position:relative}
.file-drop:hover{border-color:#000;background:#f5f5f5}
.file-drop input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.file-preview{display:none;align-items:center;gap:10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:10px 14px;margin-top:8px}
.file-preview.show{display:flex}
.file-preview-name{font-size:13px;color:#166534;font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.file-preview-rm{background:none;border:none;cursor:pointer;color:#dc2626;font-size:15px;padding:2px 5px}
.existing-doc{display:flex;align-items:center;gap:8px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:8px 12px;margin-bottom:8px;font-size:13px}
.existing-doc a{color:#1e40af;font-weight:600;text-decoration:none;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.portal-toggle{display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;transition:all .2s;background:#fff;user-select:none}
.portal-toggle:hover{border-color:#000}
.portal-toggle.active{background:#f0fdf4;border-color:#bbf7d0}
.portal-toggle .toggle-knob{width:36px;height:20px;border-radius:10px;background:#e5e7eb;position:relative;transition:background .2s;flex-shrink:0}
.portal-toggle.active .toggle-knob{background:#16a34a}
.portal-toggle .toggle-knob::after{content:'';position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;top:3px;left:3px;transition:left .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.portal-toggle.active .toggle-knob::after{left:19px}
.portal-toggle-label{font-size:13px;font-weight:600;color:#374151}
.portal-toggle.active .portal-toggle-label{color:#16a34a}
/* ── Settlement Modal ── */
.sm-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:2000;display:none;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto}
.sm-overlay.open{display:flex}
.sm-box{background:#fff;border-radius:14px;width:100%;max-width:1100px;box-shadow:0 24px 64px rgba(0,0,0,.25);overflow:hidden;margin:auto}
.sm-hdr{background:linear-gradient(135deg,#1e3a5f,#2563eb);padding:16px 22px;display:flex;align-items:center;justify-content:space-between}
.sm-hdr-title{color:#fff;font-size:16px;font-weight:700;display:flex;align-items:center;gap:9px}
.sm-hdr-close{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:8px;padding:5px 14px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}
.sm-hdr-close:hover{background:rgba(255,255,255,.25)}
.sm-strip{display:flex;flex-wrap:wrap;background:#f9fafb;border-bottom:2px solid #e5e5e5}
.sm-strip-cell{display:flex;flex-direction:column;gap:2px;padding:12px 18px;border-right:1px solid #e5e5e5;min-width:110px}
.sm-strip-cell:last-child{border-right:none}
.sm-lbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;font-weight:600}
.sm-val{font-size:15px;font-weight:800;color:#1f2937}
.sm-val.sky{color:#0369a1}.sm-val.green{color:#16a34a}.sm-val.purple{color:#7c3aed}
.sm-val.orange{color:#d97706}.sm-val.red{color:#dc2626}
.sm-body{display:grid;grid-template-columns:1fr 1fr;gap:0;border-bottom:1px solid #e5e5e5}
@media(max-width:700px){.sm-body{grid-template-columns:1fr}}
.sm-col{display:flex;flex-direction:column;overflow:hidden}
.sm-col-left{border-right:2px solid #e5e5e5}
.sm-col-hdr{padding:11px 16px;background:#fff;border-bottom:1px solid #f0f0f0;font-size:13px;font-weight:700;color:#1f2937;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-shrink:0}
/* Ack bar */
.sm-ack-bar{padding:8px 12px;background:linear-gradient(90deg,#eff6ff,#dbeafe);border-bottom:1px solid #bfdbfe;display:none;flex-direction:column;gap:6px;flex-shrink:0}
.sm-ack-bar.visible{display:flex}
.sm-ack-bar-top{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.sm-ack-bar-info{font-size:11px;color:#1e40af;font-weight:700;flex:1;min-width:0}
.sm-ack-bar-btns{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.btn-send-ack{display:inline-flex;align-items:center;gap:5px;padding:5px 14px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border:none;border-radius:7px;font-size:11px;font-weight:800;cursor:pointer;font-family:inherit;box-shadow:0 2px 6px rgba(37,99,235,.3)}
.btn-send-ack:hover{filter:brightness(1.08)}
.btn-send-ack:disabled{opacity:.5;cursor:not-allowed}
.btn-sel-all-sp{background:#fff;border:1px solid #bfdbfe;color:#2563eb;border-radius:5px;padding:4px 9px;font-size:10px;font-weight:700;cursor:pointer;font-family:inherit}
.btn-sel-all-sp:hover{background:#eff6ff}
.sm-ack-hint{font-size:10px;color:#9ca3af;padding:0 2px}
/* Claims list */
.sm-claims-scroll{height:430px;overflow-y:auto;padding:10px 12px;background:#fff}
.claim-card{border:1px solid #e5e5e5;border-radius:8px;padding:10px 12px;margin-bottom:7px;cursor:pointer;transition:all .18s;background:#fff;position:relative}
.claim-card:hover:not(.claim-acked){border-color:#2563eb;background:#eff6ff}
.claim-card.fully-settled{display:none}
.claim-card.partial{border-left:3px solid #f59e0b;background:#fffbeb}
.claim-card.partial:hover:not(.claim-acked){border-color:#f59e0b;background:#fef3c7}
.claim-card.selected{outline:2px solid #2563eb;outline-offset:1px}
.claim-card.claim-acked{cursor:default;background:#eff6ff;border-color:#bfdbfe;opacity:.85}
.claim-card.claim-acked:hover{background:#eff6ff;border-color:#bfdbfe}
.cc-row1{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:4px;flex-wrap:wrap}
.cc-inv{font-size:12px;font-weight:700;font-family:monospace;color:#312e81}
.cc-ent{font-size:10px;font-weight:700;background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;border-radius:4px;padding:1px 6px}
.cc-tot{font-size:13px;font-weight:800;color:#0369a1}
.cc-desc{font-size:11px;color:#6b7280;line-height:1.4;margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cc-meta{display:flex;gap:10px;font-size:11px;color:#9ca3af;flex-wrap:wrap}
.cc-balance-bar{margin-top:6px;border-top:1px solid #f0f0f0;padding-top:5px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.cc-bal-item{display:flex;flex-direction:column;gap:1px}
.cc-bal-lbl{font-size:9px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;font-weight:600}
.cc-bal-val{font-size:12px;font-weight:800}
.cc-bal-val.green{color:#16a34a}.cc-bal-val.orange{color:#d97706}.cc-bal-val.sky{color:#0369a1}
.cc-partial-badge{font-size:10px;font-weight:700;background:#fef3c7;color:#92400e;border:1px solid #fcd34d;border-radius:4px;padding:1px 7px;margin-left:auto}
.cc-settled-badge{font-size:10px;font-weight:700;background:#dcfce7;color:#166534;border:1px solid #86efac;border-radius:4px;padding:1px 7px}
.cc-ack-btn{
  background:linear-gradient(135deg,#2563eb,#1d4ed8);
  border:none;
  color:#fff;
  border-radius:6px;
  padding:6px 12px;
  font-size:11px;
  font-weight:700;
  cursor:pointer;
  display:inline-flex;
  align-items:center;
  gap:5px;
  transition:all .2s;
  font-family:inherit;
  box-shadow:0 2px 6px rgba(37,99,235,.3);
  margin-right:8px;
}
.cc-ack-btn:hover{
  filter:brightness(1.08);
}
.cc-ack-btn:disabled{opacity:.5;cursor:not-allowed}
.cc-actions{display:flex;align-items:center;gap:6px;margin-top:7px;padding-top:7px;border-top:1px solid #f3f4f6;flex-wrap:wrap}
.cc-acked-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;font-size:10px;font-weight:700;color:#1e40af}
.cc-rev-btn{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;background:none;border:1px solid #fca5a5;border-radius:5px;color:#dc2626;font-size:10px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s}
.cc-rev-btn:hover{background:#fef2f2}
.cc-pick-hint{font-size:10px;color:#9ca3af;margin-left:auto}
.no-claims{text-align:center;padding:40px 16px;color:#9ca3af}
.no-claims i{font-size:30px;margin-bottom:8px;display:block}
.sm-search{width:100%;padding:7px 10px;border:1px solid #e5e5e5;border-radius:6px;font-size:12px;outline:none;font-family:inherit;margin-bottom:8px}
.sm-search:focus{border-color:#2563eb}
/* Right panel */
.sm-right-scroll{height:430px;overflow-y:auto;padding:10px 12px;background:#fafafa}
.apf-box{background:#fff;border:2px solid #bfdbfe;border-radius:10px;padding:14px;margin-bottom:10px}
.apf-box.hidden{display:none}
.apf-title{font-size:13px;font-weight:700;color:#1e40af;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.apf-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.apf-grid .span2{grid-column:span 2}
.apf-lbl{font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;display:block}
.apf-inp{width:100%;padding:7px 10px;border:1px solid #bfdbfe;border-radius:6px;font-size:13px;font-family:inherit;outline:none}
.apf-inp:focus{border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.1)}
.apf-inp.amt-editable{background:#fff;border-color:#2563eb;color:#1e40af;font-weight:700}
.apf-inp.amt-editable:focus{background:#eff6ff}
.apf-hint-bar{background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:7px 10px;margin-bottom:8px;font-size:12px;color:#0369a1;display:none}
.apf-hint-bar.show{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.apf-hint-bar b{font-weight:700}
.apf-btns{display:flex;gap:8px;justify-content:flex-end;margin-top:10px}
/* Payment cards */
.sp-card{border:1px solid #e5e5e5;border-radius:8px;padding:10px 12px;margin-bottom:7px;background:#fff;transition:opacity .2s}
.sp-card.acked{opacity:.65;background:#eff6ff;border-color:#bfdbfe}
.sp-row1{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:4px;flex-wrap:wrap}
.sp-inv{font-size:12px;font-weight:700;font-family:monospace;color:#312e81}
.sp-tot{font-size:13px;font-weight:800;color:#16a34a}
.sp-meta{display:flex;gap:10px;font-size:11px;color:#6b7280;flex-wrap:wrap;margin-bottom:3px}
.sp-desc{font-size:11px;color:#9ca3af;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sp-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:6px}
.sp-del{background:none;border:1px solid #fca5a5;border-radius:5px;color:#dc2626;cursor:pointer;padding:3px 8px;font-size:11px;display:inline-flex;align-items:center;gap:4px;transition:all .15s;font-family:inherit}
.sp-del:hover{background:#fef2f2}
.sp-ack-btn{background:#eff6ff;border:1px solid #bfdbfe;border-radius:5px;color:#2563eb;cursor:pointer;padding:3px 8px;font-size:11px;display:inline-flex;align-items:center;gap:4px;transition:all .15s;font-family:inherit;font-weight:600}
.sp-ack-btn:hover{background:#2563eb;color:#fff;border-color:#2563eb}
.sp-ack-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.sp-rev-btn{background:none;border:1px solid #fca5a5;border-radius:5px;color:#dc2626;cursor:pointer;padding:3px 7px;font-size:10px;display:inline-flex;align-items:center;gap:3px;font-family:inherit;transition:all .15s}
.sp-rev-btn:hover{background:#fef2f2}
.sp-cb-wrap{display:inline-flex;align-items:center;gap:5px;cursor:pointer}
.sp-cb-wrap input[type=checkbox]{accent-color:#2563eb;width:14px;height:14px;cursor:pointer}
.sp-cb-wrap input[type=checkbox]:disabled{cursor:default;opacity:.6}
.sp-empty{text-align:center;padding:30px 16px;color:#9ca3af;font-size:13px}
.sm-footer{padding:12px 22px;display:flex;align-items:center;justify-content:flex-end;gap:10px;background:#fff;border-top:1px solid #f0f0f0}
#toast{position:fixed;bottom:26px;right:26px;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;color:#fff;z-index:9999;display:none;box-shadow:0 4px 16px rgba(0,0,0,.18)}
#toast.success{background:#16a34a}#toast.error{background:#dc2626}#toast.info{background:#1e3a5f}#toast.warn{background:#d97706}
@media(max-width:600px){
  .form-grid{grid-template-columns:1fr 1fr}
  .form-grid .span3,.form-divider{grid-column:span 2}
  .sm-body{grid-template-columns:1fr}
  .sm-col-left{border-right:none;border-bottom:2px solid #e5e5e5}
  .cc-balance-bar{flex-direction:column;align-items:flex-start}
  .cc-ack-btn{margin-right:0;margin-bottom:6px}
  .cc-partial-badge{margin-left:0}
}
</style>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h2 class="page-title"><i class="fa-solid fa-file-invoice"></i> Muthupalasa BP Claims</h2>
      <p class="page-subtitle">Manage debit note claims and settlements against Muthupalasa BP Claims</p>
    </div>
    <button class="btn btn-primary" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> Add New Entry</button>
  </div>
</div>

<div class="summary-grid">
  <div class="summary-box">
    <div class="summary-label">Total Records</div>
    <div class="summary-value"><?php echo number_format($totals['cnt']); ?></div>
    <div class="summary-sub">Filtered results</div>
  </div>
  <div class="summary-box">
    <div class="summary-label">Total Paid Amount</div>
    <div class="summary-value"><?php echo $totals['total_paid']!==null?number_format($totals['total_paid'],2):'—'; ?></div>
    <div class="summary-sub">Before VAT</div>
  </div>
  <div class="summary-box">
    <div class="summary-label">Total VAT</div>
    <div class="summary-value" style="color:#7c3aed"><?php echo $totals['total_vat']!==null?number_format($totals['total_vat'],2):'—'; ?></div>
    <div class="summary-sub">18% VAT sum</div>
  </div>
  <div class="summary-box">
    <div class="summary-label">Total to be Received</div>
    <div class="summary-value" style="color:#0369a1"><?php echo $totals['total_amount']!==null?number_format($totals['total_amount'],2):'—'; ?></div>
    <div class="summary-sub">Paid Amount + VAT</div>
  </div>
  <div class="summary-box">
    <div class="summary-label">Settled Amount</div>
    <div class="summary-value" style="color:#16a34a"><?php echo number_format($totals['settled_amount']??0,2); ?></div>
    <div class="summary-sub">Excl. VAT settled</div>
  </div>
  <div class="summary-box">
    <div class="summary-label">Settled VAT</div>
    <div class="summary-value" style="color:#7c3aed"><?php echo number_format($totals['settled_vat']??0,2); ?></div>
    <div class="summary-sub">VAT on settled</div>
  </div>
  <div class="summary-box">
    <div class="summary-label">Total Settled</div>
    <div class="summary-value" style="color:#16a34a"><?php echo number_format($totals['settled_total']??0,2); ?></div>
    <div class="summary-sub">Amount+VAT settled</div>
  </div>
  <div class="summary-box">
    <?php $diff=floatval($totals['total_amount']??0)-floatval($totals['settled_total']??0); ?>
    <div class="summary-label">Balance</div>
    <div class="summary-value" style="color:<?php echo $diff>0?'#d97706':($diff<0?'#dc2626':'#16a34a'); ?>">
      <?php echo number_format(abs($diff),2); ?>
    </div>
    <div class="summary-sub">Total vs Settled</div>
  </div>
</div>

<div class="content-card">
  <div class="card-header-row">
    <h3 class="card-title"><i class="fa-solid fa-table"></i> Debit Note Records</h3>
    <form method="GET" action="" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
      <div class="fbar-group">
        <label class="fbar-label">Date From</label>
        <input type="date" name="filter_date_from" class="fbar-input" value="<?php echo htmlspecialchars($filter_date_from); ?>" style="width:135px">
      </div>
      <div class="fbar-group">
        <label class="fbar-label">To</label>
        <input type="date" name="filter_date_to" class="fbar-input" value="<?php echo htmlspecialchars($filter_date_to); ?>" style="width:135px">
      </div>
      <div class="fbar-group">
        <label class="fbar-label">Category</label>
        <input type="text" name="filter_category" class="fbar-input" placeholder="Filter category…" value="<?php echo htmlspecialchars($filter_category); ?>" style="width:130px">
      </div>
      <div class="fbar-group">
        <label class="fbar-label">Portal</label>
        <select name="filter_submitted" class="fbar-select" style="width:110px">
          <option value="">All</option>
          <option value="1" <?php echo $filter_submitted===1?'selected':''; ?>>Submitted</option>
          <option value="0" <?php echo $filter_submitted===0&&$filter_submitted!==''?'selected':''; ?>>Not Submitted</option>
        </select>
      </div>
      <button type="submit" class="btn btn-dark btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
      <a href="debit_note.php" class="btn btn-light btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
    </form>
  </div>

  <div class="toolbar">
    <div class="search-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" class="search-input" id="searchBox" placeholder="Search category, description, claim ref…" oninput="doSearch()">
    </div>
    <span id="rowCount" style="font-size:12px;color:#6b7280"></span>
  </div>

  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th style="text-align:center;width:36px"><i class="fa-solid fa-paperclip"></i></th>
          <th>#</th>
          <th>Paid Date by Yelo</th>
          <th>Category</th>
          <th>Description</th>
          <th style="text-align:center">Submitted to Portal</th>
          <th class="num">Paid Amount</th>
          <th class="num">VAT 18%</th>
          <th class="num">Total to Receive</th>
          <th class="num">Settled Amt</th>
          <th class="num">Settled VAT</th>
          <th class="num">Total Settled</th>
          <th class="num">Balance</th>
          <th>Last Banking Date</th>
          <th>Claim Reference</th>
          <th>Tax Invoice Refs</th>
          <th style="text-align:center">Actions</th>
        </tr>
      </thead>
      <tbody id="tableBody">
      <?php if (!$rows_result || mysqli_num_rows($rows_result) === 0): ?>
        <tr><td colspan="17"><div class="empty-state"><i class="fa-solid fa-file-invoice"></i><p>No records found. Click <strong>Add New Entry</strong> to get started.</p></div></td></tr>
      <?php else: $rn=1; while ($r=mysqli_fetch_assoc($rows_result)):
        $total   = floatval($r['total_amount']   ?? 0);
        $sAmt    = floatval($r['settled_amount'] ?? 0);
        $sVat    = floatval($r['settled_vat']    ?? 0);
        $sTot    = floatval($r['settled_total']  ?? 0);
        $balance = $total - $sTot;
        $balCl   = $balance>0?'bal-pos':($balance<0?'bal-neg':'bal-zero');
      ?>
        <tr id="tr-<?php echo $r['id']; ?>"
            data-total="<?php echo $total; ?>"
            data-search="<?php echo strtolower(htmlspecialchars(($r['category']??'').' '.($r['description']??'').' '.($r['last_claim_ref']??'').' '.($r['tax_inv_refs']??''))); ?>">
          <td style="text-align:center">
            <?php if ($r['document_path']&&file_exists($r['document_path'])): ?>
              <a href="<?php echo htmlspecialchars($r['document_path']); ?>" target="_blank" class="doc-icon-link" title="<?php echo htmlspecialchars($r['document_name']??'Document'); ?>"><i class="fa-solid fa-paperclip"></i></a>
            <?php else: ?><span class="no-doc"><i class="fa-solid fa-minus"></i></span><?php endif; ?>
          </td>
          <td><?php echo $rn++; ?></td>
          <td><?php echo !empty($r['paid_date'])?date('d M Y',strtotime($r['paid_date'])):'—'; ?></td>
          <td>
            <?php if(!empty($r['category'])): ?>
              <span class="cat-badge" title="<?php echo htmlspecialchars($r['category']); ?>"><?php echo htmlspecialchars($r['category']); ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis" title="<?php echo htmlspecialchars($r['description']??''); ?>"><?php echo htmlspecialchars($r['description']??'—'); ?></td>
          <td style="text-align:center">
            <?php if($r['submitted_to_portal']): ?>
              <span class="portal-yes"><i class="fa-solid fa-check"></i> Yes</span>
            <?php else: ?>
              <span class="portal-no"><i class="fa-solid fa-minus"></i> No</span>
            <?php endif; ?>
          </td>
          <td class="num"><?php echo $r['paid_amount']!==null?number_format($r['paid_amount'],2):'—'; ?></td>
          <td class="num"><span class="vat-pill"><?php echo $r['vat']!==null?number_format($r['vat'],2):'—'; ?></span></td>
          <td class="num" style="font-weight:700;color:#0369a1"><?php echo number_format($total,2); ?></td>
          <td class="num" id="td-sAmt-<?php echo $r['id']; ?>" style="color:#16a34a"><?php echo $sAmt>0?number_format($sAmt,2):'—'; ?></td>
          <td class="num" id="td-sVat-<?php echo $r['id']; ?>" style="color:#7c3aed"><?php echo $sVat>0?number_format($sVat,2):'—'; ?></td>
          <td class="num" id="td-sTot-<?php echo $r['id']; ?>" style="font-weight:700;color:#16a34a"><?php echo $sTot>0?number_format($sTot,2):'—'; ?></td>
          <td class="num" id="td-bal-<?php echo $r['id']; ?>"><span class="<?php echo $balCl; ?>"><?php echo number_format($balance,2); ?></span></td>
          <td id="td-lbd-<?php echo $r['id']; ?>" style="font-size:12px;color:#6b7280"><?php echo !empty($r['last_banking_date'])?date('d M Y',strtotime($r['last_banking_date'])):'—'; ?></td>
          <td id="td-claimref-<?php echo $r['id']; ?>">
            <?php if(!empty($r['last_claim_ref'])): ?>
              <span class="claim-pill"><?php echo htmlspecialchars($r['last_claim_ref']); ?></span>
            <?php else: ?><span style="color:#d1d5db">—</span><?php endif; ?>
          </td>
          <td id="td-taxrefs-<?php echo $r['id']; ?>" style="font-size:12px;max-width:130px;overflow:hidden;text-overflow:ellipsis" title="<?php echo htmlspecialchars($r['tax_inv_refs']??''); ?>">
            <?php if(!empty($r['tax_inv_refs'])): ?>
              <span class="taxref-pill"><?php echo htmlspecialchars($r['tax_inv_refs']); ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td style="text-align:center">
            <div class="action-buttons">
              <button class="btn btn-settle btn-xs<?php echo $sTot>0?' settled':''; ?>"
                      id="settlebtn-<?php echo $r['id']; ?>"
                      onclick="openSettle(<?php echo $r['id']; ?>,<?php echo $total; ?>)">
                <i class="fa-solid fa-<?php echo $sTot>0?'check-circle':'hand-holding-dollar'; ?>"></i>
                <?php echo $sTot>0?'Settled':'Settle'; ?>
              </button>
              <button class="btn-action btn-edit-r" onclick="editRow(<?php echo $r['id']; ?>)" title="Edit"><i class="fa-solid fa-pen"></i></button>
              <button class="btn-action btn-del-r"  onclick="deleteRow(<?php echo $r['id']; ?>)" title="Delete"><i class="fa-solid fa-trash"></i></button>
            </div>
          </td>
        </tr>
      <?php endwhile; endif; ?>
      </tbody>
      <?php if ($rows_result && mysqli_num_rows($rows_result) > 0): ?>
      <tfoot>
        <tr>
          <td colspan="6" style="text-align:right;font-size:11px;color:#6b7280">Page Totals</td>
          <td class="num"><?php echo $totals['total_paid']!==null?number_format($totals['total_paid'],2):'—'; ?></td>
          <td class="num" style="color:#7c3aed"><?php echo $totals['total_vat']!==null?number_format($totals['total_vat'],2):'—'; ?></td>
          <td class="num" style="color:#0369a1;font-weight:700"><?php echo $totals['total_amount']!==null?number_format($totals['total_amount'],2):'—'; ?></td>
          <td class="num" style="color:#16a34a"><?php echo number_format($totals['settled_amount']??0,2); ?></td>
          <td class="num" style="color:#7c3aed"><?php echo number_format($totals['settled_vat']??0,2); ?></td>
          <td class="num" style="color:#16a34a;font-weight:700"><?php echo number_format($totals['settled_total']??0,2); ?></td>
          <td class="num" style="color:#d97706"><?php echo number_format(($totals['total_amount']??0)-($totals['settled_total']??0),2); ?></td>
          <td colspan="4"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<!-- ═══ ADD / EDIT MODAL ═══════════════════════════════════════ -->
<div class="modal-overlay" id="entryModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title"><i class="fa-solid fa-file-invoice"></i><span id="modalTitleText">Add New Entry</span></div>
      <button class="modal-close" onclick="closeAddModal()">×</button>
    </div>
    <div class="modal-body">
      <form id="entryForm" enctype="multipart/form-data" onsubmit="return false">
        <input type="hidden" id="fid" name="id" value="0">
        <input type="hidden" id="f_submitted_val" name="submitted_to_portal" value="0">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Paid Date by Yelo</label>
            <input type="date" class="form-input" id="f_paid_date" name="paid_date">
          </div>
          <div class="form-group span2">
            <label class="form-label">Category</label>
            <input type="text" class="form-input" id="f_category" name="category" placeholder="e.g. Promotional, Logistics…">
          </div>
          <div class="form-group span3">
            <label class="form-label">Description</label>
            <input type="text" class="form-input" id="f_description" name="description" placeholder="e.g. Debit note for March 2024">
          </div>
          <div class="form-group span3">
            <label class="form-label">Submitted to Portal</label>
            <div class="portal-toggle" id="portalToggle" onclick="togglePortal()">
              <div class="toggle-knob"></div>
              <span class="portal-toggle-label" id="toggleLabel">Not Submitted</span>
            </div>
          </div>
          <hr class="form-divider">
          <div class="form-group">
            <label class="form-label">Paid Amount</label>
            <input type="number" step="0.01" min="0" class="form-input" id="f_paid_amount" name="paid_amount" placeholder="0.00" oninput="calcVAT()">
          </div>
          <div class="form-group">
            <label class="form-label">VAT 18% <span class="auto">AUTO</span></label>
            <input type="number" step="0.01" class="form-input auto-fill" id="f_vat" name="vat" placeholder="0.00" readonly>
            <div class="calc-hint">Paid Amount × 18%</div>
          </div>
          <div class="form-group">
            <label class="form-label">Total to be Received <span class="auto">AUTO</span></label>
            <input type="number" step="0.01" class="form-input auto-fill" id="f_total_amount" name="total_amount" placeholder="0.00" readonly>
            <div class="calc-hint">Paid Amount + VAT</div>
          </div>
          <hr class="form-divider">
          <div class="form-group span3">
            <label class="form-label">Supporting Document <span style="color:#9ca3af;font-weight:400;text-transform:none">(Optional)</span></label>
            <div id="existingDocWrap" style="display:none" class="existing-doc">
              <i class="fa-solid fa-paperclip" style="color:#1e40af"></i>
              <a id="existingDocLink" href="#" target="_blank">Current file</a>
              <span style="font-size:11px;color:#9ca3af">Upload new to replace</span>
            </div>
            <div class="file-drop" id="fileDrop"
                 ondragover="event.preventDefault();this.classList.add('drag')"
                 ondragleave="this.classList.remove('drag')"
                 ondrop="handleDrop(event)">
              <input type="file" name="document" id="f_document" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" onchange="handleFileSelect(this)">
              <div style="font-size:22px;color:#9ca3af;margin-bottom:4px"><i class="fa-solid fa-cloud-arrow-up"></i></div>
              <div style="font-size:13px;color:#6b7280;font-weight:500">Click or drag &amp; drop</div>
              <div style="font-size:11px;color:#9ca3af;margin-top:2px">PDF, DOC, DOCX, XLS, XLSX, JPG, PNG</div>
            </div>
            <div class="file-preview" id="filePreview">
              <i class="fa-solid fa-file" style="color:#16a34a"></i>
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
<div class="sm-overlay" id="settleModal">
  <div class="sm-box">
    <div class="sm-hdr">
      <div class="sm-hdr-title"><i class="fa-solid fa-hand-holding-dollar"></i> <span id="sm-title">Settlements</span></div>
      <button class="sm-hdr-close" onclick="closeSettle()">✕ Close</button>
    </div>
    <div class="sm-strip">
      <div class="sm-strip-cell"><div class="sm-lbl">Total to Receive</div><div class="sm-val sky" id="ss-inv">0.00</div></div>
      <div class="sm-strip-cell"><div class="sm-lbl">Settled Amount</div><div class="sm-val green" id="ss-amt">0.00</div></div>
      <div class="sm-strip-cell"><div class="sm-lbl">Settled VAT</div><div class="sm-val purple" id="ss-vat">0.00</div></div>
      <div class="sm-strip-cell"><div class="sm-lbl">Total Settled</div><div class="sm-val green" id="ss-tot">0.00</div></div>
      <div class="sm-strip-cell"><div class="sm-lbl">Balance</div><div class="sm-val orange" id="ss-bal">0.00</div></div>
      <div class="sm-strip-cell"><div class="sm-lbl">Payments</div><div class="sm-val" id="ss-cnt">0</div></div>
    </div>

    <div class="sm-body">
      <!-- ══ LEFT: Claim certificates ══ -->
      <div class="sm-col sm-col-left">
        <div class="sm-col-hdr">
          <span><i class="fa-solid fa-file-invoice" style="color:#2563eb"></i> Muthupalasa BP Claims</span>
          <span id="sm-claims-count" style="font-size:11px;color:#9ca3af;font-weight:400"></span>
        </div>
        <div style="padding:8px 12px 0;background:#fff">
          <input type="text" class="sm-search" id="claimSearch" placeholder="Search invoice / description / entity…" oninput="filterClaims(this.value)">
        </div>
        <div class="sm-claims-scroll" id="claimsList">
          <div class="no-claims"><i class="fa-solid fa-spinner fa-spin"></i><p>Loading…</p></div>
        </div>
      </div>

      <!-- ══ RIGHT: Ack bar + payment form + payments list ══ -->
      <div class="sm-col">
        <div class="sm-col-hdr">
          <span><i class="fa-solid fa-receipt" style="color:#16a34a"></i> Payment Settlements</span>
          <button class="btn btn-xs btn-primary" style="padding:5px 12px;font-size:11px" onclick="showAPF()">
            <i class="fa-solid fa-plus"></i> Add Manual
          </button>
        </div>

        <!-- Bulk ack bar -->
        <div class="sm-ack-bar" id="smAckBar">
          <div class="sm-ack-bar-top">
            <span class="sm-ack-bar-info" id="smAckBarInfo">Select payments below → send to Acknowledgment</span>
            <div class="sm-ack-bar-btns">
              <button class="btn-sel-all-sp" onclick="selAllPayments(true)">Select All</button>
              <button class="btn-sel-all-sp" onclick="selAllPayments(false)">Deselect</button>
              <button class="btn-send-ack" id="btnSendAck" onclick="sendToAck()" disabled>
                <i class="fa-solid fa-envelope-open-text"></i> Send to Acknowledgment
              </button>
            </div>
          </div>
          <div class="sm-ack-hint"><i class="fa-solid fa-circle-info"></i> Check payments below, then click Send to Acknowledgment</div>
        </div>

        <div class="sm-right-scroll" id="smRightScroll">
          <!-- Add payment form -->
          <div class="apf-box hidden" id="apf">
            <div class="apf-title"><i class="fa-solid fa-circle-plus"></i> <span id="apf-head">New Payment</span></div>
            <div class="apf-hint-bar" id="apf-hint">
              <i class="fa-solid fa-circle-info"></i>
              <span>Invoice Amount: <b id="apf-hint-inv">—</b></span>
              <span style="color:#64748b">|</span>
              <span>Globally Settled: <b id="apf-hint-gsettled">—</b></span>
              <span style="color:#64748b">|</span>
              <span>Remaining: <b id="apf-hint-rem" style="color:#d97706">—</b></span>
            </div>
            <input type="hidden" id="apf-cid" value="">
            <div class="apf-grid">
              <div>
                <label class="apf-lbl">Tax Invoice No</label>
                <input type="text" class="apf-inp" id="apf-ti" placeholder="TAX-INV-001">
              </div>
              <div>
                <label class="apf-lbl">Entity</label>
                <input type="text" class="apf-inp" id="apf-ent" placeholder="Entity">
              </div>
              <div>
                <label class="apf-lbl">Banking Date</label>
                <input type="date" class="apf-inp" id="apf-bd">
              </div>
              <div>
                <label class="apf-lbl">Bank Reference</label>
                <input type="text" class="apf-inp" id="apf-br" placeholder="Bank REF">
              </div>
              <div>
                <label class="apf-lbl">Payment Amount</label>
                <input type="number" step="0.01" min="0" class="apf-inp amt-editable" id="apf-amt" placeholder="0.00">
              </div>
              <div>
                <label class="apf-lbl">Claim Reference</label>
                <input type="text" class="apf-inp" id="apf-ref" placeholder="e.g. DN-2024-001">
              </div>
              <div class="span2">
                <label class="apf-lbl">Description</label>
                <input type="text" class="apf-inp" id="apf-desc" placeholder="Auto-filled from claim or enter manually">
              </div>
            </div>
            <div class="apf-btns">
              <button class="btn btn-light btn-sm" style="padding:7px 14px;font-size:12px" onclick="hideAPF()"><i class="fa-solid fa-xmark"></i> Cancel</button>
              <button class="btn btn-primary btn-sm" style="padding:7px 16px;font-size:12px" id="apf-save" onclick="savePayment()"><i class="fa-solid fa-floppy-disk"></i> Save Payment</button>
            </div>
          </div>

          <!-- Payments list -->
          <div id="spList">
            <div class="sp-empty"><i class="fa-solid fa-receipt" style="font-size:22px;display:block;margin-bottom:8px;color:#d1d5db"></i>No payments yet. Select a claim on the left or click Add Manual.</div>
          </div>
        </div>
      </div>
    </div>

    <div class="sm-footer">
      <button class="btn btn-light" onclick="closeSettle()">Close</button>
    </div>
    <input type="hidden" id="sm-pid" value="0">
    <input type="hidden" id="sm-inv" value="0">
  </div>
</div>

<div id="toast"></div>

<script>
const $  = id => document.getElementById(id);
const fN = v  => parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
const eh = s  => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

function toast(msg,type){
  const t=$('toast');t.textContent=msg;t.className=type;t.style.display='block';
  clearTimeout(t._t);t._t=setTimeout(()=>t.style.display='none',3800);
}

function calcVAT(){
  const a=parseFloat($('f_paid_amount').value||0)||0,v=Math.round(a*.18*100)/100;
  $('f_vat').value         = a>0?v.toFixed(2):'';
  $('f_total_amount').value= a>0?(a+v).toFixed(2):'';
}

function togglePortal(){
  const tog=$('portalToggle'),active=tog.classList.toggle('active');
  $('toggleLabel').textContent=active?'Submitted to Portal':'Not Submitted';
  $('f_submitted_val').value=active?'1':'0';
}
function setPortal(v){
  const tog=$('portalToggle');
  if(v){tog.classList.add('active');$('toggleLabel').textContent='Submitted to Portal';$('f_submitted_val').value='1';}
  else{tog.classList.remove('active');$('toggleLabel').textContent='Not Submitted';$('f_submitted_val').value='0';}
}

function doSearch(){
  const q=$('searchBox').value.toLowerCase().trim();
  const rows=document.querySelectorAll('#tableBody tr[id^="tr-"]');
  let vis=0;
  rows.forEach(r=>{const show=!q||(r.dataset.search||'').includes(q);r.style.display=show?'':'none';if(show)vis++;});
  $('rowCount').textContent=`(${vis} of ${rows.length})`;
}
document.addEventListener('DOMContentLoaded',()=>{
  const rows=document.querySelectorAll('#tableBody tr[id^="tr-"]');
  if(rows.length)$('rowCount').textContent=`(${rows.length})`;
});

function openAddModal(title='Add New Entry'){$('modalTitleText').textContent=title;$('entryModal').classList.add('open');}
function closeAddModal(){$('entryModal').classList.remove('open');resetForm();}
function resetForm(){
  $('fid').value='0';
  ['f_paid_date','f_category','f_description','f_paid_amount','f_vat','f_total_amount'].forEach(id=>$(id).value='');
  setPortal(false);$('existingDocWrap').style.display='none';clearFile();
}
function handleFileSelect(inp){if(inp.files[0])showFP(inp.files[0].name);}
function handleDrop(e){
  e.preventDefault();$('fileDrop').classList.remove('drag');
  const f=e.dataTransfer.files[0];if(!f)return;
  const dt=new DataTransfer();dt.items.add(f);$('f_document').files=dt.files;showFP(f.name);
}
function showFP(n){$('filePreviewName').textContent=n;$('filePreview').classList.add('show');$('fileDrop').style.display='none';}
function clearFile(){$('f_document').value='';$('filePreview').classList.remove('show');$('fileDrop').style.display='';}

function saveEntry(){
  const id=$('fid').value,btn=$('saveBtn');
  btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
  fetch('debit_note.php?action=save',{method:'POST',body:new FormData($('entryForm'))})
    .then(r=>r.json()).then(res=>{
      btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Entry';
      if(res.success){toast(parseInt(id)>0?'Entry updated!':'Entry added!','success');closeAddModal();setTimeout(()=>location.reload(),800);}
      else toast('Error: '+(res.message||'Unknown'),'error');
    }).catch(e=>{btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Entry';toast('Network error: '+e.message,'error');});
}

function editRow(id){
  fetch('debit_note.php?action=get&id='+id).then(r=>r.json()).then(res=>{
    if(!res.success||!res.data){toast('Could not load record.','error');return;}
    const d=res.data;
    $('fid').value            =''+d.id;
    $('f_paid_date').value    =d.paid_date   ||'';
    $('f_category').value     =d.category    ||'';
    $('f_description').value  =d.description ||'';
    $('f_paid_amount').value  =d.paid_amount ||'';
    $('f_vat').value          =d.vat         ||'';
    $('f_total_amount').value =d.total_amount||'';
    setPortal(d.submitted_to_portal=='1');
    if(d.document_path&&d.document_name){
      $('existingDocWrap').style.display='flex';
      $('existingDocLink').href=d.document_path;
      $('existingDocLink').textContent=d.document_name;
    }
    openAddModal('Edit Entry');
  }).catch(()=>toast('Failed to load record.','error'));
}

function deleteRow(id){
  if(!confirm('Delete this entry and all its settlements? This cannot be undone.'))return;
  const fd=new FormData();fd.append('id',id);
  fetch('debit_note.php?action=delete',{method:'POST',body:fd})
    .then(r=>r.json()).then(res=>{
      if(res.success){document.getElementById('tr-'+id)?.remove();toast('Entry deleted.','success');}
      else toast('Delete failed.','error');
    }).catch(()=>toast('Network error.','error'));
}

/* ═══════════════════════════════════════════════════════════
   SETTLEMENT MODAL
═══════════════════════════════════════════════════════════ */
let _pid=0, _invTotal=0, _allClaims=[], _settledCIDs=new Set(), _ackSIDs=new Set(), _ackClaimIds=new Set();

async function openSettle(dnId,invTotal){
  _pid=dnId; _invTotal=invTotal;
  $('sm-pid').value=dnId; $('sm-inv').value=invTotal;
  $('sm-title').textContent='Settlements — Record #'+dnId;
  $('ss-inv').textContent=fN(invTotal);
  $('settleModal').classList.add('open');

  $('claimsList').innerHTML='<div class="no-claims"><i class="fa-solid fa-spinner fa-spin"></i><p>Loading Muthupalasa BP Claims…</p></div>';
  $('spList').innerHTML='<div class="sp-empty"><i class="fa-solid fa-spinner fa-spin" style="font-size:20px;display:block;margin-bottom:8px;color:#d1d5db"></i></div>';
  $('smAckBar').classList.remove('visible');
  hideAPF();

  try {
    const [cR,sR] = await Promise.all([
      fetch('debit_note.php?action=get_claim_matches&dn_id='+dnId).then(r=>r.json()),
      fetch('debit_note.php?action=get_settlements&dn_id='+dnId).then(r=>r.json())
    ]);
    _allClaims   = cR.claims    || [];
    _settledCIDs = new Set((cR.settled_ids||[]).map(Number));
    _ackClaimIds = new Set((cR.ack_claim_ids||[]).map(Number));
    _ackSIDs     = new Set((sR.ack_ids||[]).map(Number));
    renderClaims(_allClaims);
    renderPayments(sR.data||[]);
    updateStrip(sR.totals||{});
  } catch(e){
    $('claimsList').innerHTML='<div class="no-claims"><i class="fa-solid fa-exclamation-circle"></i><p>Failed to load.</p></div>';
    toast('Load error: '+e.message,'error');
  }
}

function closeSettle(){$('settleModal').classList.remove('open');_allClaims=[];_settledCIDs=new Set();_ackSIDs=new Set();_ackClaimIds=new Set();}
$('settleModal').addEventListener('click',e=>{if(e.target===$('settleModal'))closeSettle();});

/* ── Claims ── */
function renderClaims(list){
  if(!list||!list.length){
    $('claimsList').innerHTML='<div class="no-claims"><i class="fa-solid fa-file-invoice"></i><p>No Muthupalasa BP Claims found.</p></div>';
    $('sm-claims-count').textContent='';
    return;
  }
  let h='',visible=0;
  list.forEach(c=>{
    const cid         = parseInt(c.id);
    const claimTot    = parseFloat(c.total_amount||0);
    const claimAmt    = parseFloat(c.actual_amount||0);
    const claimVat    = parseFloat(c.vat_amount||0);
    const gSettledAmt = parseFloat(c.globally_settled_amount||0);
    const gSettledTot = parseFloat(c.globally_settled_total||0);
    const thisSettledAmt = parseFloat(c.this_dn_settled_amount||0);
    const thisSettledTot = parseFloat(c.this_dn_settled_total||0);
    const remaining   = claimTot - gSettledTot;
    const isFullyDone = remaining <= 0.004;
    const isPartial   = !isFullyDone && gSettledTot > 0;
    const isDirectAcked = _ackClaimIds.has(cid);
    if(!isFullyDone) visible++;
    let cardClass   = isFullyDone?'claim-card fully-settled':(isPartial?'claim-card partial':'claim-card');
    if(isDirectAcked) cardClass += ' claim-acked';
    const searchStr   = eh(((c.tax_invoice_no||'')+' '+(c.claim_description||'')+' '+(c.entity||'')).toLowerCase());
    h+=`<div class="${cardClass}"
          id="cc-${c.id}"
          data-id="${c.id}"
          data-fully="${isFullyDone?1:0}"
          data-s="${searchStr}"
          data-inv-tot="${claimTot}"
          data-inv-amt="${claimAmt}"
          data-inv-vat="${claimVat}"
          data-g-settled-tot="${gSettledTot}"
          data-g-settled-amt="${gSettledAmt}"
          data-banking="${eh(c.banking_date||'')}"
          data-entity="${eh(c.entity||'')}"
          data-ti="${eh(c.tax_invoice_no||'')}"
          data-desc="${eh(c.claim_description||'')}"
          onclick="${isDirectAcked?'':'pickClaim(this)'}">
      <div class="cc-row1">
        <span class="cc-inv">${eh(c.tax_invoice_no||'—')}</span>
        <span class="cc-ent">${eh(c.entity||'—')}</span>
        <span class="cc-tot">${fN(claimTot)}</span>
      </div>
      <div class="cc-desc" title="${eh(c.claim_description||'')}">${eh((c.claim_description||'').slice(0,72))}</div>
      <div class="cc-meta">
        <span>Actual: ${fN(claimAmt)}</span>
        <span>VAT: ${fN(claimVat)}</span>
        ${c.banking_date?`<span>${eh(c.banking_date)}</span>`:''}
        ${c.invoice_date?`<span>Inv: ${eh(c.invoice_date)}</span>`:''}
      </div>
      ${buildBalanceBar(c.id, thisSettledAmt, gSettledAmt, gSettledTot, claimTot, claimAmt, isFullyDone, isPartial)}
      ${thisSettledAmt<=0.004 ? buildDirectAckSection(cid, isDirectAcked) : ''}
    </div>`;
  });
  $('claimsList').innerHTML = h || '<div class="no-claims"><i class="fa-solid fa-file-invoice"></i><p>No available claims.</p></div>';
  $('sm-claims-count').textContent = `${visible} available`;
}

function buildDirectAckSection(claimId, isDirectAcked){
  if(isDirectAcked){
    return `<div class="cc-actions">
      <span class="cc-acked-badge"><i class="fa-solid fa-check-circle"></i> Sent to Acknowledgment</span>
      <button class="cc-rev-btn" onclick="event.stopPropagation();reverseDirectClaimAck(${claimId})"><i class="fa-solid fa-rotate-left"></i> Reverse</button>
    </div>`;
  }
  return `<div class="cc-actions">
    <button class="cc-ack-btn" onclick="event.stopPropagation();sendDirectClaimAck(${claimId})"><i class="fa-solid fa-envelope-open-text"></i> Send to Acknowledgment</button>
    <span class="cc-pick-hint"><i class="fa-solid fa-hand-pointer"></i> Or click to add payment</span>
  </div>`;
}

function buildBalanceBar(claimId, thisSettledAmt, gSettledAmt, gSettledTot, claimTot, claimAmt, isFullyDone, isPartial){
  if(!isPartial && !isFullyDone && thisSettledAmt <= 0) return '';
  const remaining = Math.max(0, claimTot - gSettledTot);
  const hasThisDNSettlements = thisSettledAmt > 0;
  
  if(isFullyDone){
    return `<div class="cc-balance-bar">
      <div class="cc-bal-item"><div class="cc-bal-lbl">Invoice</div><div class="cc-bal-val sky">${fN(claimTot)}</div></div>
      <div class="cc-bal-item"><div class="cc-bal-lbl">Settled</div><div class="cc-bal-val green">${fN(gSettledTot)}</div></div>
      <div class="cc-bal-item"><div class="cc-bal-lbl">Balance</div><div class="cc-bal-val green">${fN(0)}</div></div>
      ${hasThisDNSettlements?`<button class="cc-ack-btn" onclick="sendClaimToAck(${claimId})" title="Send settled amounts to Acknowledgment"><i class="fa-solid fa-envelope-open-text"></i> Send to Ack</button>`:''}
      <span class="cc-settled-badge" style="margin-left:auto"><i class="fa-solid fa-check"></i> Fully Settled</span>
    </div>`;
  }
  return `<div class="cc-balance-bar">
    <div class="cc-bal-item"><div class="cc-bal-lbl">Invoice</div><div class="cc-bal-val sky">${fN(claimTot)}</div></div>
    <div class="cc-bal-item"><div class="cc-bal-lbl">Settled</div><div class="cc-bal-val green">${fN(gSettledTot)}</div></div>
    <div class="cc-bal-item"><div class="cc-bal-lbl">Balance</div><div class="cc-bal-val orange">${fN(remaining)}</div></div>
    <button class="cc-ack-btn" onclick="sendClaimToAck(${claimId})" title="Send settled amounts to Acknowledgment">
      <i class="fa-solid fa-envelope-open-text"></i> Send to Ack
    </button>
    <span class="cc-partial-badge" style="margin-left:auto"><i class="fa-solid fa-clock-rotate-left"></i> Partial</span>
  </div>`;
}

function filterClaims(q){
  q=(q||'').toLowerCase().trim();
  document.querySelectorAll('#claimsList .claim-card').forEach(el=>{
    if(el.dataset.fully==='1'){el.style.display='none';return;}
    el.style.display=(!q||(el.dataset.s||'').includes(q))?'':'none';
  });
}

function pickClaim(el){
  if(el.dataset.fully==='1') return;
  document.querySelectorAll('.claim-card.selected').forEach(c=>c.classList.remove('selected'));
  el.classList.add('selected');

  const claimTot    = parseFloat(el.dataset.invTot||0);
  const claimAmt    = parseFloat(el.dataset.invAmt||0);
  const gSettledTot = parseFloat(el.dataset.gSettledTot||0);
  const gSettledAmt = parseFloat(el.dataset.gSettledAmt||0);
  const remaining   = Math.max(0, claimTot - gSettledTot);

  $('apf-hint-inv').textContent      = fN(claimTot);
  $('apf-hint-gsettled').textContent = fN(gSettledTot);
  $('apf-hint-rem').textContent      = fN(remaining);
  $('apf-hint').classList.add('show');

  $('apf-cid').value  = el.dataset.id||'';
  $('apf-ti').value   = el.dataset.ti||'';
  $('apf-ent').value  = el.dataset.entity||'';
  $('apf-bd').value   = el.dataset.banking||'';
  $('apf-desc').value = el.dataset.desc||'';
  $('apf-ref').value  = '';

  const remainingBase = Math.max(0, claimAmt - gSettledAmt);
  const recBal = Math.max(0, parseFloat($('sm-inv').value||0) - parseFloat(($('ss-tot').textContent||'0').replace(/,/g,'')));
  if(recBal > 0){
    $('apf-amt').value = (remainingBase>0 ? Math.min(remainingBase,recBal) : recBal).toFixed(2);
  } else if(remainingBase > 0){
    $('apf-amt').value = remainingBase.toFixed(2);
  } else {
    $('apf-amt').value = claimAmt>0 ? claimAmt.toFixed(2) : '';
  }

  $('apf-head').textContent = 'From Claim: '+(el.dataset.ti||'Selected');
  showAPF();
}

function showAPF(){
  $('apf').classList.remove('hidden');
  $('smRightScroll').scrollTop=0;
  if(!$('apf-cid').value){
    const inv = parseFloat($('sm-inv').value||0);
    const settled = parseFloat(($('ss-tot').textContent||'0').replace(/,/g,''));
    const bal = Math.max(0, inv - settled);
    if(bal > 0) $('apf-amt').value = bal.toFixed(2);
  }
}
function hideAPF(){
  $('apf').classList.add('hidden');
  $('apf-hint').classList.remove('show');
  ['apf-cid','apf-ti','apf-ent','apf-bd','apf-br','apf-amt','apf-desc','apf-ref'].forEach(id=>$(id).value='');
  $('apf-head').textContent='New Payment';
  document.querySelectorAll('.claim-card.selected').forEach(c=>c.classList.remove('selected'));
}

/* ── Save payment ── */
async function savePayment(){
  const pid = parseInt($('sm-pid').value||0);
  const amt = parseFloat($('apf-amt').value||0);
  if(!pid){toast('No record.','error');return;}
  if(!(amt>0)){toast('Amount must be > 0.','error');return;}

  const btn=$('apf-save');
  btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

  const fd=new FormData();
  fd.append('dn_id',             pid);
  fd.append('claim_cert_item_id',$('apf-cid').value||0);
  fd.append('tax_invoice_no',    $('apf-ti').value||'');
  fd.append('entity',            $('apf-ent').value||'');
  fd.append('banking_date',      $('apf-bd').value||'');
  fd.append('bank_ref',          $('apf-br').value||'');
  fd.append('amount',            $('apf-amt').value||0);
  fd.append('vat_amount',        0);
  fd.append('claim_description', $('apf-desc').value||'');
  fd.append('claim_reference',   $('apf-ref').value||'');

  try {
    const res = await fetch('debit_note.php?action=add_settlement',{method:'POST',body:fd});
    const d   = await res.json();
    btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Payment';
    if(!d.success){toast('Error: '+(d.message||'Failed'),'error');return;}
    toast('Payment saved!','success');
    _ackSIDs = new Set((d.ack_ids||[]).map(Number));
    hideAPF();
    const sr = await fetch('debit_note.php?action=get_settlements&dn_id='+pid).then(r=>r.json());
    _ackSIDs = new Set((sr.ack_ids||[]).map(Number));
    renderPayments(sr.data||[]);
    updateStrip(sr.totals||{});
    updateTableRow(pid, sr.totals||{});
    const cr = await fetch('debit_note.php?action=get_claim_matches&dn_id='+pid).then(r=>r.json());
    _allClaims   = cr.claims||[];
    _settledCIDs = new Set((cr.settled_ids||[]).map(Number));
    _ackClaimIds = new Set((cr.ack_claim_ids||[]).map(Number));
    renderClaims(_allClaims);
    const q = $('claimSearch').value;
    if(q) filterClaims(q);
  } catch(e){
    btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Payment';
    toast('Network error: '+e.message,'error');
  }
}

/* ── Render payments with checkboxes + ack UI ── */
function renderPayments(list){
  if(!list||!list.length){
    $('spList').innerHTML='<div class="sp-empty"><i class="fa-solid fa-receipt" style="font-size:22px;display:block;margin-bottom:8px;color:#d1d5db"></i>No payments recorded yet.</div>';
    $('smAckBar').classList.remove('visible');
    return;
  }
  $('smAckBar').classList.add('visible');
  let h='';
  list.forEach(p=>{
    const isAcked = _ackSIDs.has(parseInt(p.id));
    const tot = parseFloat(p.total_amount||p.amount||0);
    const amt = parseFloat(p.amount||0), vat = parseFloat(p.vat_amount||0);
    h+=`<div class="sp-card${isAcked?' acked':''}" id="sp-${p.id}" data-id="${p.id}">
      <div class="sp-row1">
        <label class="sp-cb-wrap">
          <input type="checkbox" class="sp-cb" data-id="${p.id}" onchange="spCbChange()" ${isAcked?'disabled checked':''}>
          <span class="sp-inv"><i class="fa-solid fa-file-invoice" style="color:#312e81"></i> ${eh(p.tax_invoice_no||'Manual')}</span>
        </label>
        <span class="sp-tot">${fN(tot)}</span>
      </div>
      <div class="sp-meta">
        ${p.entity?`<span><i class="fa-solid fa-building" style="color:#2563eb"></i> ${eh(p.entity)}</span>`:''}
        <span>Amt: ${fN(amt)}</span>
        <span style="color:#7c3aed">VAT: ${fN(vat)}</span>
        ${p.banking_date?`<span><i class="fa-regular fa-calendar"></i> ${eh(p.banking_date)}</span>`:''}
        ${p.bank_ref?`<span style="font-family:monospace;background:#f8fafc;border:1px solid #e2e8f0;border-radius:3px;padding:1px 5px">${eh(p.bank_ref)}</span>`:''}
        ${p.claim_reference?`<span class="claim-pill">${eh(p.claim_reference)}</span>`:''}
      </div>
      ${p.claim_description?`<div class="sp-desc" title="${eh(p.claim_description)}">${eh(p.claim_description.slice(0,70))}</div>`:''}
      <div class="sp-actions">
        ${isAcked
          ?`<span class="sp-ack-badge"><i class="fa-solid fa-envelope-open-text"></i> Sent to Acknowledgment</span>
            <button class="sp-rev-btn" onclick="reverseAck(${p.id},${_pid})"><i class="fa-solid fa-rotate-left"></i> Reverse</button>`
          :`<button class="sp-ack-btn" onclick="sendSingleToAck(${p.id})"><i class="fa-solid fa-envelope-open-text"></i> Send to Ack</button>`
        }
        <button class="sp-del" onclick="delPayment(${p.id},${_pid})"><i class="fa-solid fa-trash"></i> Delete</button>
      </div>
    </div>`;
  });
  $('spList').innerHTML=h;
  spCbChange();
}

function spCbChange(){
  const checked = Array.from(document.querySelectorAll('.sp-cb:not([disabled]):checked'));
  const n = checked.length;
  $('btnSendAck').disabled = n===0;
  $('smAckBarInfo').textContent = n>0?`${n} payment(s) selected — ready to send`:'Select payments below → send to Acknowledgment';
}

function selAllPayments(v){
  document.querySelectorAll('.sp-cb:not([disabled])').forEach(cb=>cb.checked=v);
  spCbChange();
}

/* ── Send claim to ack (via existing settlements for this claim) ── */
async function sendClaimToAck(claimId){
  if(!_pid){
    toast('No record selected.','error');
    return;
  }
  
  const claim = _allClaims.find(c => c.id === claimId);
  if(!claim){
    toast('Claim not found.','error');
    return;
  }
  
  try {
    const settlementsRes = await fetch('debit_note.php?action=get_settlements&dn_id='+_pid);
    const settlementsData = await settlementsRes.json();
    const relevantSettlements = (settlementsData.data || []).filter(s => parseInt(s.claim_cert_item_id) === claimId);
    
    if(!relevantSettlements.length){
      toast('No payments found for this claim in this record.','warn');
      return;
    }
    
    const ids = relevantSettlements.map(s => s.id);
    const fd = new FormData();
    fd.append('ids', JSON.stringify(ids));
    fd.append('dn_id', _pid);
    
    const res = await fetch('debit_note.php?action=send_to_ack',{method:'POST',body:fd});
    const d = await res.json();
    
    if(!d.success){
      toast('Error: '+(d.message||'Failed'),'error');
      return;
    }
    
    _ackSIDs = new Set((d.ack_ids||[]).map(Number));
    let msg = `✓ ${d.sent} payment(s) sent to Cheque Acknowledgment.`;
    if(d.skipped) msg += ` ${d.skipped} already sent.`;
    toast(msg,'info');
    
    const sr = await fetch('debit_note.php?action=get_settlements&dn_id='+_pid).then(r=>r.json());
    _ackSIDs = new Set((sr.ack_ids||[]).map(Number));
    renderPayments(sr.data||[]);
    updateStrip(sr.totals||{});
    
    const cr = await fetch('debit_note.php?action=get_claim_matches&dn_id='+_pid).then(r=>r.json());
    _allClaims = cr.claims||[];
    _settledCIDs = new Set((cr.settled_ids||[]).map(Number));
    _ackClaimIds = new Set((cr.ack_claim_ids||[]).map(Number));
    renderClaims(_allClaims);
    const q = $('claimSearch').value;
    if(q) filterClaims(q);
    
  } catch(e){
    toast('Network error: '+e.message,'error');
  }
}

/* ── Direct claim-to-ack (no settlement/payment needed) ── */
async function sendDirectClaimAck(claimId){
  if(!confirm('Send this claim certificate directly to Acknowledgment?\n\nNo payment/settlement record will be created — only the acknowledgment entry.'))return;
  const card=document.querySelector(`#cc-${claimId}`);
  const btn=card?card.querySelector('.cc-ack-btn'):null;
  if(btn){btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Sending…';}
  const fd=new FormData();
  fd.append('dn_id',_pid);
  fd.append('claim_item_id',claimId);
  try{
    const res=await fetch('debit_note.php?action=direct_claim_ack',{method:'POST',body:fd});
    const d=await res.json();
    if(!d.success){
      toast('Error: '+(d.message||'Failed'),'error');
      if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-envelope-open-text"></i> Send to Acknowledgment';}
      return;
    }
    _ackClaimIds=new Set((d.ack_claim_ids||[]).map(Number));
    renderClaims(_allClaims);
    const q=$('claimSearch').value; if(q) filterClaims(q);
    toast('Claim sent to Cheque Acknowledgment.','info');
  }catch(e){
    toast('Network error: '+e.message,'error');
    if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-envelope-open-text"></i> Send to Acknowledgment';}
  }
}

async function reverseDirectClaimAck(claimId){
  if(!confirm('Reverse this acknowledgment? The claim will return to available status.'))return;
  const fd=new FormData();
  fd.append('dn_id',_pid);
  fd.append('claim_item_id',claimId);
  try{
    const res=await fetch('debit_note.php?action=reverse_direct_claim_ack',{method:'POST',body:fd});
    const d=await res.json();
    if(!d.success){toast('Reverse failed.','error');return;}
    _ackClaimIds=new Set((d.ack_claim_ids||[]).map(Number));
    renderClaims(_allClaims);
    const q=$('claimSearch').value; if(q) filterClaims(q);
    toast('Claim acknowledgment reversed.','warn');
  }catch(e){toast('Network error: '+e.message,'error');}
}

/* ── Bulk send to ack ── */
async function sendToAck(){
  const cbs = Array.from(document.querySelectorAll('.sp-cb:not([disabled]):checked'));
  const ids = cbs.map(cb=>cb.dataset.id).filter(Boolean);
  if(!ids.length){toast('Select at least one payment.','warn');return;}
  const btn = $('btnSendAck');
  btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Sending…';
  const fd=new FormData();
  fd.append('ids', JSON.stringify(ids));
  fd.append('dn_id', _pid);
  try {
    const res = await fetch('debit_note.php?action=send_to_ack',{method:'POST',body:fd});
    const d   = await res.json();
    if(!d.success){toast('Error: '+(d.message||'Failed'),'error');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-envelope-open-text"></i> Send to Acknowledgment';return;}
    _ackSIDs = new Set((d.ack_ids||[]).map(Number));
    let msg = `✓ ${d.sent} payment(s) sent to Cheque Acknowledgment.`;
    if(d.skipped) msg += ` ${d.skipped} skipped (already sent).`;
    toast(msg,'info');
    const sr = await fetch('debit_note.php?action=get_settlements&dn_id='+_pid).then(r=>r.json());
    _ackSIDs = new Set((sr.ack_ids||[]).map(Number));
    renderPayments(sr.data||[]);
    updateStrip(sr.totals||{});
  } catch(e){toast('Network error: '+e.message,'error');}
  finally{btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-envelope-open-text"></i> Send to Acknowledgment';}
}

/* ── Single send to ack ── */
async function sendSingleToAck(sid){
  const fd=new FormData();
  fd.append('ids', JSON.stringify([sid]));
  fd.append('dn_id', _pid);
  try {
    const res = await fetch('debit_note.php?action=send_to_ack',{method:'POST',body:fd});
    const d   = await res.json();
    if(!d.success){toast('Error: '+(d.message||'Failed'),'error');return;}
    _ackSIDs = new Set((d.ack_ids||[]).map(Number));
    toast('Payment sent to Cheque Acknowledgment.','info');
    const sr = await fetch('debit_note.php?action=get_settlements&dn_id='+_pid).then(r=>r.json());
    _ackSIDs = new Set((sr.ack_ids||[]).map(Number));
    renderPayments(sr.data||[]);
    updateStrip(sr.totals||{});
  } catch(e){toast('Network error: '+e.message,'error');}
}

/* ── Reverse ack (settlement-level) ── */
async function reverseAck(sid,pid){
  if(!confirm('Reverse this acknowledgment? The payment will return to selectable status.'))return;
  const fd=new FormData();
  fd.append('settlement_id', sid);
  fd.append('dn_id', pid);
  try {
    const res = await fetch('debit_note.php?action=reverse_ack',{method:'POST',body:fd});
    const d   = await res.json();
    if(!d.success){toast('Reverse failed.','error');return;}
    _ackSIDs = new Set((d.ack_ids||[]).map(Number));
    toast('Acknowledgment reversed. Payment returned to list.','warn');
    const sr = await fetch('debit_note.php?action=get_settlements&dn_id='+pid).then(r=>r.json());
    _ackSIDs = new Set((sr.ack_ids||[]).map(Number));
    renderPayments(sr.data||[]);
    updateStrip(sr.totals||{});
  } catch(e){toast('Network error.','error');}
}

/* ── Delete payment ── */
async function delPayment(sid,pid){
  if(!confirm('Delete this payment? Cannot be undone.'))return;
  const fd=new FormData();fd.append('settlement_id',sid);fd.append('dn_id',pid);
  try {
    const res=await fetch('debit_note.php?action=delete_settlement',{method:'POST',body:fd});
    const d=await res.json();
    if(d.success){
      _ackSIDs.delete(parseInt(sid));
      document.getElementById('sp-'+sid)?.remove();
      if(!document.querySelector('[id^="sp-"]')){
        $('spList').innerHTML='<div class="sp-empty"><i class="fa-solid fa-receipt" style="font-size:22px;display:block;margin-bottom:8px;color:#d1d5db"></i>No payments recorded yet.</div>';
        $('smAckBar').classList.remove('visible');
      }
      updateStrip(d.totals||{});
      updateTableRow(pid,d.totals||{});
      toast('Payment deleted.','success');
      const cr=await fetch('debit_note.php?action=get_claim_matches&dn_id='+pid).then(r=>r.json());
      _allClaims   = cr.claims||[];
      _settledCIDs = new Set((cr.settled_ids||[]).map(Number));
      _ackClaimIds = new Set((cr.ack_claim_ids||[]).map(Number));
      renderClaims(_allClaims);
      const q=$('claimSearch').value;
      if(q) filterClaims(q);
    } else toast('Delete failed.','error');
  } catch(e){toast('Network error.','error');}
}

function updateStrip(t){
  const inv   = parseFloat($('sm-inv').value||0);
  const sAmt  = parseFloat(t.settled_amount||0);
  const sVat  = parseFloat(t.settled_vat||0);
  const sTot  = parseFloat(t.settled_total||0);
  const bal   = inv - sTot;
  $('ss-inv').textContent = fN(inv);
  $('ss-amt').textContent = fN(sAmt);
  $('ss-vat').textContent = fN(sVat);
  $('ss-tot').textContent = fN(sTot);
  $('ss-bal').textContent = fN(bal);
  $('ss-bal').className   = 'sm-val '+(bal>0?'orange':(bal<0?'red':'green'));
  $('ss-cnt').textContent = document.querySelectorAll('[id^="sp-"]').length;
}

function updateTableRow(pid,t){
  const sAmt=parseFloat(t.settled_amount||0),sVat=parseFloat(t.settled_vat||0),sTot=parseFloat(t.settled_total||0);
  const tr=document.getElementById('tr-'+pid);
  const inv=tr?parseFloat(tr.dataset.total||0):0,bal=inv-sTot;
  const balCl=bal>0?'bal-pos':(bal<0?'bal-neg':'bal-zero');
  const s=(id,v)=>{const el=document.getElementById(id);if(el)el.innerHTML=v;};
  s('td-sAmt-'+pid,`<span style="color:#16a34a">${sAmt>0?fN(sAmt):'—'}</span>`);
  s('td-sVat-'+pid,`<span style="color:#7c3aed">${sVat>0?fN(sVat):'—'}</span>`);
  s('td-sTot-'+pid,`<span style="font-weight:700;color:#16a34a">${sTot>0?fN(sTot):'—'}</span>`);
  s('td-bal-'+pid,`<span class="${balCl}">${fN(bal)}</span>`);
  const btn=document.getElementById('settlebtn-'+pid);
  if(btn){
    btn.className='btn btn-settle btn-xs'+(sTot>0?' settled':'');
    btn.innerHTML=sTot>0?'<i class="fa-solid fa-check-circle"></i> Settled':'<i class="fa-solid fa-hand-holding-dollar"></i> Settle';
  }
}
</script>
<?php include 'footer.php'; ?>