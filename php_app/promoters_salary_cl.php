<?php
include 'config.php';

// ══════════════════════════════════════════════════════════════════
//  TABLE SETUP
// ══════════════════════════════════════════════════════════════════
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS promoters_salary_cl (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    month               TINYINT NULL,
    year                INT NULL,
    entry_date          DATE NULL,
    banking_date        DATE NULL,
    entity              VARCHAR(255) NULL,
    description         VARCHAR(500) NULL,
    bank_deposit_ref    VARCHAR(255) NULL,
    amount              DECIMAL(14,2) NULL,
    vat                 DECIMAL(14,2) NULL,
    amount_with_vat     DECIMAL(14,2) NULL,
    document_path       VARCHAR(500) NULL,
    document_name       VARCHAR(255) NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS pscl_settlements (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    pscl_id             INT NOT NULL,
    tax_invoice_no      VARCHAR(255) NULL,
    banking_date        DATE NULL,
    bank_ref            VARCHAR(255) NULL,
    amount              DECIMAL(14,2) NULL DEFAULT 0,
    vat_amount          DECIMAL(14,2) NULL DEFAULT 0,
    total_amount        DECIMAL(14,2) NULL DEFAULT 0,
    claim_description   VARCHAR(500) NULL,
    entity              VARCHAR(255) NULL,
    claim_cert_item_id  INT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pscl (pscl_id)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS dl_cheque_acknowledgments (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    confirmed_payment_id INT NULL,
    claim_item_id        INT NOT NULL,
    tax_invoice_no       VARCHAR(100) NULL,
    invoice_date          DATE NULL,
    banking_date          DATE NULL,
    entity                VARCHAR(50) NULL,
    claim_description     TEXT NULL,
    actual_amount          DECIMAL(18,4) DEFAULT 0,
    vat_amount             DECIMAL(18,4) DEFAULT 0,
    total_amount           DECIMAL(18,4) DEFAULT 0,
    ledger_type            VARCHAR(100) NULL,
    status                 VARCHAR(100) NULL,
    claim_type             VARCHAR(100) NULL,
    customer_code          VARCHAR(50) NULL,
    customer_name          VARCHAR(255) NULL,
    ack_sent_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
    ack_sent_by            VARCHAR(100) DEFAULT 'system',
    source                 VARCHAR(50) NULL DEFAULT 'dal',
    source_settlement_id   INT NULL,
    INDEX idx_cp (confirmed_payment_id),
    INDEX idx_ci (claim_item_id)
)");

$chk = mysqli_query($conn, "SHOW COLUMNS FROM dl_cheque_acknowledgments LIKE 'source'");
if ($chk && mysqli_num_rows($chk) === 0) {
    @mysqli_query($conn, "ALTER TABLE dl_cheque_acknowledgments ADD COLUMN source VARCHAR(50) NULL DEFAULT 'dal' AFTER ack_sent_by");
    @mysqli_query($conn, "ALTER TABLE dl_cheque_acknowledgments ADD COLUMN source_settlement_id INT NULL AFTER source");
}

foreach (['vat_amount','total_amount','claim_description','entity','claim_cert_item_id'] as $col) {
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM pscl_settlements LIKE '$col'");
    if ($chk && mysqli_num_rows($chk) === 0) {
        $defs = [
            'vat_amount'         => "DECIMAL(14,2) NULL DEFAULT 0 AFTER amount",
            'total_amount'       => "DECIMAL(14,2) NULL DEFAULT 0 AFTER vat_amount",
            'claim_description'  => "VARCHAR(500) NULL AFTER total_amount",
            'entity'             => "VARCHAR(255) NULL AFTER claim_description",
            'claim_cert_item_id' => "INT NULL AFTER entity",
        ];
        @mysqli_query($conn, "ALTER TABLE pscl_settlements ADD COLUMN $col {$defs[$col]}");
        if ($col === 'total_amount') {
            @mysqli_query($conn, "UPDATE pscl_settlements SET total_amount = COALESCE(amount,0) WHERE total_amount IS NULL OR total_amount = 0");
        }
    }
}

// ══════════════════════════════════════════════════════════════════
//  AJAX HANDLERS
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id               = intval($_POST['id'] ?? 0);
        $month            = is_numeric($_POST['month'] ?? '')  ? intval($_POST['month'])  : null;
        $year             = is_numeric($_POST['year']  ?? '')  ? intval($_POST['year'])   : null;
        $entry_date       = mysqli_real_escape_string($conn, trim($_POST['entry_date']       ?? ''));
        $banking_date     = mysqli_real_escape_string($conn, trim($_POST['banking_date']     ?? ''));
        $entity           = mysqli_real_escape_string($conn, trim($_POST['entity']           ?? ''));
        $description      = mysqli_real_escape_string($conn, trim($_POST['description']      ?? ''));
        $bank_deposit_ref = mysqli_real_escape_string($conn, trim($_POST['bank_deposit_ref'] ?? ''));
        $amount           = is_numeric($_POST['amount']          ?? '') ? floatval($_POST['amount'])          : null;
        $vat              = is_numeric($_POST['vat']             ?? '') ? floatval($_POST['vat'])             : null;
        $amount_with_vat  = is_numeric($_POST['amount_with_vat'] ?? '') ? floatval($_POST['amount_with_vat']) : null;

        $doc_path = null; $doc_name = null;
        if (!empty($_FILES['document']['name']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/promoters_salary/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext     = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png'];
            if (!in_array($ext, $allowed)) { echo json_encode(['success'=>false,'message'=>'Invalid file type.']); exit; }
            $safe = uniqid('pscl_',true).'.'.$ext;
            if (move_uploaded_file($_FILES['document']['tmp_name'], $upload_dir.$safe)) {
                $doc_path = mysqli_real_escape_string($conn, $upload_dir.$safe);
                $doc_name = mysqli_real_escape_string($conn, $_FILES['document']['name']);
            }
        }

        $mo = $month           !== null ? $month           : 'NULL';
        $yr = $year            !== null ? $year            : 'NULL';
        $ed = $entry_date   ? "'$entry_date'"              : 'NULL';
        $bd = $banking_date ? "'$banking_date'"            : 'NULL';
        $am = $amount          !== null ? $amount          : 'NULL';
        $vt = $vat             !== null ? $vat             : 'NULL';
        $aw = $amount_with_vat !== null ? $amount_with_vat : 'NULL';

        if ($id > 0) {
            $dp = $doc_path !== null ? ", document_path='$doc_path', document_name='$doc_name'" : '';
            $sql = "UPDATE promoters_salary_cl SET month=$mo, year=$yr, entry_date=$ed, banking_date=$bd, entity='$entity', description='$description', bank_deposit_ref='$bank_deposit_ref', amount=$am, vat=$vt, amount_with_vat=$aw $dp WHERE id=$id";
        } else {
            $dp = $doc_path !== null ? "'$doc_path'" : 'NULL';
            $dn = $doc_name !== null ? "'$doc_name'" : 'NULL';
            $sql = "INSERT INTO promoters_salary_cl (month,year,entry_date,banking_date,entity,description,bank_deposit_ref,amount,vat,amount_with_vat,document_path,document_name) VALUES ($mo,$yr,$ed,$bd,'$entity','$description','$bank_deposit_ref',$am,$vt,$aw,$dp,$dn)";
        }
        $ok = mysqli_query($conn, $sql);
        echo json_encode(['success'=>(bool)$ok,'id'=>($ok&&!$id)?mysqli_insert_id($conn):$id,'message'=>$ok?'':mysqli_error($conn)]);
        exit;
    }

    if ($action === 'get') {
        $id  = intval($_GET['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM promoters_salary_cl WHERE id=$id"));
        echo json_encode(['success'=>(bool)$row,'data'=>$row]);
        exit;
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id  = intval($_POST['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT document_path FROM promoters_salary_cl WHERE id=$id"));
        if ($row && !empty($row['document_path']) && file_exists($row['document_path'])) @unlink($row['document_path']);
        $res_sids = mysqli_query($conn, "SELECT id FROM pscl_settlements WHERE pscl_id=$id");
        while ($sr = mysqli_fetch_assoc($res_sids)) {
            $sid = intval($sr['id']);
            mysqli_query($conn, "DELETE FROM dl_cheque_acknowledgments WHERE source='pscl' AND source_settlement_id=$sid");
        }
        mysqli_query($conn, "DELETE FROM dl_cheque_acknowledgments WHERE source='pscl_claim' AND confirmed_payment_id=$id");
        mysqli_query($conn, "DELETE FROM pscl_settlements WHERE pscl_id=$id");
        $ok = mysqli_query($conn, "DELETE FROM promoters_salary_cl WHERE id=$id");
        echo json_encode(['success'=>(bool)$ok]);
        exit;
    }

    // IMPROVED: Get matching claim certificates
    if ($action === 'get_claim_matches') {
        $pscl_id = intval($_GET['pscl_id'] ?? 0);
        $rec     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM promoters_salary_cl WHERE id=$pscl_id"));

        $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'claim_cert_items'");
        if (!$tbl || mysqli_num_rows($tbl) === 0) {
            echo json_encode(['success'=>true,'claims'=>[],'settled_ids'=>[],'ack_settlement_ids'=>[],'ack_claim_ids'=>[],'record'=>$rec]);
            exit;
        }

        // IMPROVED: More lenient base matching
        $base_where = "(ci.claim_description LIKE '%promot%' 
                       OR ci.claim_type LIKE '%promot%' 
                       OR ci.ledger_type LIKE '%promot%'
                       OR ci.claim_description LIKE '%salary%'
                       OR ci.claim_type LIKE '%salary%')";

        $entity_sql  = '';
        $entity_trim = trim($rec['entity'] ?? '');
        if ($entity_trim !== '') {
            $ent_parts = preg_split('/\s+/', $entity_trim);
            $ent       = mysqli_real_escape_string($conn, $entity_trim);
            $ent_first = mysqli_real_escape_string($conn, $ent_parts[0]);
            $entity_sql = "AND (ci.entity LIKE '%$ent%' OR ci.entity LIKE '%$ent_first%' OR ci.claim_description LIKE '%$ent%' OR ci.claim_description LIKE '%$ent_first%')";
        }

        $res = mysqli_query($conn, "SELECT ci.* FROM claim_cert_items ci WHERE $base_where $entity_sql ORDER BY ci.invoice_date DESC, ci.id DESC LIMIT 500");
        
        $claims = [];
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $claims[] = $r;
            }
        }

        // FALLBACK: If entity filter gave NO results
        if (empty($claims) && $entity_sql !== '') {
            $res = mysqli_query($conn, "SELECT ci.* FROM claim_cert_items ci WHERE $base_where ORDER BY ci.invoice_date DESC, ci.id DESC LIMIT 500");
            if ($res) {
                while ($r = mysqli_fetch_assoc($res)) {
                    $claims[] = $r;
                }
            }
        }

        $res2 = mysqli_query($conn, "SELECT claim_cert_item_id FROM pscl_settlements WHERE pscl_id=$pscl_id AND claim_cert_item_id IS NOT NULL AND claim_cert_item_id > 0");
        $settled_ids = [];
        while ($r2 = mysqli_fetch_assoc($res2)) $settled_ids[] = intval($r2['claim_cert_item_id']);

        $res3 = mysqli_query($conn, "SELECT source_settlement_id FROM dl_cheque_acknowledgments WHERE source='pscl' AND source_settlement_id IN (SELECT id FROM pscl_settlements WHERE pscl_id=$pscl_id)");
        $ack_settlement_ids = [];
        while ($r3 = mysqli_fetch_assoc($res3)) $ack_settlement_ids[] = intval($r3['source_settlement_id']);

        $res4 = mysqli_query($conn, "SELECT claim_item_id FROM dl_cheque_acknowledgments WHERE source='pscl_claim' AND confirmed_payment_id=$pscl_id");
        $ack_claim_ids = [];
        while ($r4 = mysqli_fetch_assoc($res4)) $ack_claim_ids[] = intval($r4['claim_item_id']);

        $other_used_ids = [];
        $resO1 = mysqli_query($conn, "SELECT claim_cert_item_id FROM pscl_settlements WHERE pscl_id != $pscl_id AND claim_cert_item_id IS NOT NULL AND claim_cert_item_id > 0");
        while ($ro = mysqli_fetch_assoc($resO1)) $other_used_ids[] = intval($ro['claim_cert_item_id']);
        
        $resO2 = mysqli_query($conn, "SELECT claim_item_id FROM dl_cheque_acknowledgments WHERE source='pscl_claim' AND confirmed_payment_id != $pscl_id");
        while ($ro = mysqli_fetch_assoc($resO2)) $other_used_ids[] = intval($ro['claim_item_id']);
        $other_used_ids = array_unique($other_used_ids);

        if (!empty($other_used_ids) && !empty($claims)) {
            $claims = array_values(array_filter($claims, function($c) use ($other_used_ids) {
                return !in_array(intval($c['id']), $other_used_ids);
            }));
        }

        echo json_encode(['success'=>true,'claims'=>$claims,'settled_ids'=>$settled_ids,'ack_settlement_ids'=>$ack_settlement_ids,'ack_claim_ids'=>$ack_claim_ids,'record'=>$rec]);
        exit;
    }

    if ($action === 'add_settlement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pscl_id       = intval($_POST['pscl_id']            ?? 0);
        $claim_item_id = intval($_POST['claim_cert_item_id'] ?? 0);
        $tax_inv       = mysqli_real_escape_string($conn, trim($_POST['tax_invoice_no']    ?? ''));
        $bank_date     = mysqli_real_escape_string($conn, trim($_POST['banking_date']      ?? ''));
        $bank_ref      = mysqli_real_escape_string($conn, trim($_POST['bank_ref']          ?? ''));
        $amount        = is_numeric($_POST['amount']     ?? '') ? floatval($_POST['amount'])     : 0;
        $vat_amount    = is_numeric($_POST['vat_amount'] ?? '') ? floatval($_POST['vat_amount']) : 0;
        $total_amount  = $amount + $vat_amount;
        $claim_desc    = mysqli_real_escape_string($conn, trim($_POST['claim_description'] ?? ''));
        $entity        = mysqli_real_escape_string($conn, trim($_POST['entity']            ?? ''));

        if (!$pscl_id) { echo json_encode(['success'=>false,'message'=>'Invalid record.']); exit; }

        $bd_sql = $bank_date ? "'$bank_date'" : 'NULL';
        $ci_sql = $claim_item_id > 0 ? $claim_item_id : 'NULL';

        $ok = mysqli_query($conn, "INSERT INTO pscl_settlements (pscl_id,tax_invoice_no,banking_date,bank_ref,amount,vat_amount,total_amount,claim_description,entity,claim_cert_item_id) VALUES($pscl_id,'$tax_inv',$bd_sql,'$bank_ref',$amount,$vat_amount,$total_amount,'$claim_desc','$entity',$ci_sql)");
        $new_id = $ok ? mysqli_insert_id($conn) : 0;
        $totals = _get_settle_totals($conn, $pscl_id);
        echo json_encode(['success'=>(bool)$ok,'id'=>$new_id,'totals'=>$totals,'message'=>$ok?'':mysqli_error($conn)]);
        exit;
    }

    if ($action === 'delete_settlement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $sid     = intval($_POST['settlement_id'] ?? 0);
        $pscl_id = intval($_POST['pscl_id']       ?? 0);
        mysqli_query($conn, "DELETE FROM dl_cheque_acknowledgments WHERE source='pscl' AND source_settlement_id=$sid");
        $ok = mysqli_query($conn, "DELETE FROM pscl_settlements WHERE id=$sid AND pscl_id=$pscl_id");
        $totals = _get_settle_totals($conn, $pscl_id);
        echo json_encode(['success'=>(bool)$ok,'totals'=>$totals]);
        exit;
    }

    if ($action === 'get_settlements') {
        $pscl_id = intval($_GET['pscl_id'] ?? 0);
        $res  = mysqli_query($conn, "SELECT * FROM pscl_settlements WHERE pscl_id=$pscl_id ORDER BY id");
        $data = [];
        while ($r = mysqli_fetch_assoc($res)) $data[] = $r;
        $totals = _get_settle_totals($conn, $pscl_id);

        $ack_ids = [];
        if ($data) {
            $all_sids = implode(',', array_map('intval', array_column($data, 'id')));
            $res_ack  = mysqli_query($conn, "SELECT source_settlement_id FROM dl_cheque_acknowledgments WHERE source='pscl' AND source_settlement_id IN ($all_sids)");
            while ($ra = mysqli_fetch_assoc($res_ack)) $ack_ids[] = intval($ra['source_settlement_id']);
        }

        echo json_encode(['success'=>true,'data'=>$data,'totals'=>$totals,'ack_ids'=>$ack_ids]);
        exit;
    }

    if ($action === 'send_to_ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $ids     = json_decode($_POST['ids']     ?? '[]', true);
        $pscl_id = intval($_POST['pscl_id']      ?? 0);
        if (!is_array($ids) || empty($ids) || !$pscl_id) { echo json_encode(['success'=>false,'message'=>'Invalid data.']); exit; }
        $sent_by = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'system';
        $sb_e    = mysqli_real_escape_string($conn, $sent_by);
        $sent = 0; $skipped = 0;

        foreach ($ids as $sid) {
            $sid = intval($sid);
            $chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM dl_cheque_acknowledgments WHERE source='pscl' AND source_settlement_id=$sid LIMIT 1"));
            if ($chk) { $skipped++; continue; }

            $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM pscl_settlements WHERE id=$sid AND pscl_id=$pscl_id LIMIT 1"));
            if (!$row) { $skipped++; continue; }

            $prec = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM promoters_salary_cl WHERE id=$pscl_id LIMIT 1"));

            $bd_sql = !empty($row['banking_date']) ? "'{$row['banking_date']}'" : 'NULL';
            $inv_d  = !empty($prec['entry_date'])  ? "'{$prec['entry_date']}'"  : 'NULL';

            $ci_sql = intval($row['claim_cert_item_id'] ?? 0) > 0 ? intval($row['claim_cert_item_id']) : 0;
            $ti_e   = mysqli_real_escape_string($conn, $row['tax_invoice_no']    ?? '');
            $br_e   = mysqli_real_escape_string($conn, $row['bank_ref']          ?? '');
            $cd_e   = mysqli_real_escape_string($conn, $row['claim_description'] ?? '');
            $en_e   = mysqli_real_escape_string($conn, $row['entity']            ?? ($prec['entity'] ?? ''));
            $aa     = floatval($row['amount']     ?? 0);
            $va     = floatval($row['vat_amount'] ?? 0);
            $ta     = floatval($row['total_amount']?? 0);

            $lt_e = 'Promoter Salary';
            $st_e = 'Confirmed';
            $ct_e = 'Promoters Salary';
            $cc_e = '';
            $cn_e = '';
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

            $ins = mysqli_query($conn, "INSERT INTO dl_cheque_acknowledgments (confirmed_payment_id,claim_item_id,tax_invoice_no,invoice_date,banking_date,entity,claim_description,actual_amount,vat_amount,total_amount,ledger_type,status,claim_type,customer_code,customer_name,ack_sent_by,source,source_settlement_id) VALUES (NULL,$ci_sql,'$ti_e',$inv_d,$bd_sql,'$en_e','$cd_e',$aa,$va,$ta,'$lt_e','$st_e','$ct_e','$cc_e','$cn_e','$sb_e','pscl',$sid)");
            if ($ins) $sent++; else $skipped++;
        }

        $res_all = mysqli_query($conn, "SELECT id FROM pscl_settlements WHERE pscl_id=$pscl_id");
        $all_sids_arr = [];
        while ($ra = mysqli_fetch_assoc($res_all)) $all_sids_arr[] = intval($ra['id']);
        $ack_ids = [];
        if ($all_sids_arr) {
            $all_sids_str = implode(',', $all_sids_arr);
            $res_ack = mysqli_query($conn, "SELECT source_settlement_id FROM dl_cheque_acknowledgments WHERE source='pscl' AND source_settlement_id IN ($all_sids_str)");
            while ($ra2 = mysqli_fetch_assoc($res_ack)) $ack_ids[] = intval($ra2['source_settlement_id']);
        }

        echo json_encode(['success'=>true,'sent'=>$sent,'skipped'=>$skipped,'ack_ids'=>$ack_ids]);
        exit;
    }

    if ($action === 'reverse_ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $sid     = intval($_POST['settlement_id'] ?? 0);
        $pscl_id = intval($_POST['pscl_id']       ?? 0);
        $ok = mysqli_query($conn, "DELETE FROM dl_cheque_acknowledgments WHERE source='pscl' AND source_settlement_id=$sid");
        $totals = _get_settle_totals($conn, $pscl_id);

        $res_all = mysqli_query($conn, "SELECT id FROM pscl_settlements WHERE pscl_id=$pscl_id");
        $all_sids_arr = [];
        while ($ra = mysqli_fetch_assoc($res_all)) $all_sids_arr[] = intval($ra['id']);
        $ack_ids = [];
        if ($all_sids_arr) {
            $all_sids_str = implode(',', $all_sids_arr);
            $res_ack = mysqli_query($conn, "SELECT source_settlement_id FROM dl_cheque_acknowledgments WHERE source='pscl' AND source_settlement_id IN ($all_sids_str)");
            while ($ra2 = mysqli_fetch_assoc($res_ack)) $ack_ids[] = intval($ra2['source_settlement_id']);
        }

        echo json_encode(['success'=>(bool)$ok,'totals'=>$totals,'ack_ids'=>$ack_ids]);
        exit;
    }

    if ($action === 'send_claim_to_ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pscl_id       = intval($_POST['pscl_id']       ?? 0);
        $claim_item_id = intval($_POST['claim_item_id'] ?? 0);

        if (!$pscl_id || !$claim_item_id) { echo json_encode(['success'=>false,'message'=>'Invalid data.']); exit; }

        $chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM dl_cheque_acknowledgments WHERE source='pscl_claim' AND confirmed_payment_id=$pscl_id AND claim_item_id=$claim_item_id LIMIT 1"));
        if ($chk) { echo json_encode(['success'=>false,'message'=>'Already sent to acknowledgment.']); exit; }

        $prec = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM promoters_salary_cl WHERE id=$pscl_id LIMIT 1"));
        $ci_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM claim_cert_items WHERE id=$claim_item_id LIMIT 1"));

        if (!$prec || !$ci_row) { echo json_encode(['success'=>false,'message'=>'Record not found.']); exit; }

        $sent_by = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'system';
        $sb_e    = mysqli_real_escape_string($conn, $sent_by);

        $inv_d  = !empty($prec['entry_date'])       ? "'{$prec['entry_date']}'"       : 'NULL';
        $bd_sql = !empty($ci_row['banking_date'])    ? "'{$ci_row['banking_date']}'"   : 'NULL';
        $ti_e   = mysqli_real_escape_string($conn, $ci_row['tax_invoice_no']    ?? '');
        $en_e   = mysqli_real_escape_string($conn, $ci_row['entity']            ?? ($prec['entity'] ?? ''));
        $cd_e   = mysqli_real_escape_string($conn, $ci_row['claim_description'] ?? '');
        $aa     = floatval($ci_row['actual_amount']  ?? 0);
        $va     = floatval($ci_row['vat_amount']     ?? 0);
        $ta     = floatval($ci_row['total_amount']   ?? 0);
        $lt_e   = mysqli_real_escape_string($conn, $ci_row['ledger_type']   ?? 'Promoter Salary');
        $st_e   = mysqli_real_escape_string($conn, $ci_row['status']        ?? 'Confirmed');
        $ct_e   = mysqli_real_escape_string($conn, $ci_row['claim_type']    ?? 'Promoters Salary');
        $cc_e   = mysqli_real_escape_string($conn, $ci_row['customer_code'] ?? '');
        $cn_e   = mysqli_real_escape_string($conn, $ci_row['customer_name'] ?? '');

        $ins = mysqli_query($conn, "INSERT INTO dl_cheque_acknowledgments (confirmed_payment_id,claim_item_id,tax_invoice_no,invoice_date,banking_date,entity,claim_description,actual_amount,vat_amount,total_amount,ledger_type,status,claim_type,customer_code,customer_name,ack_sent_by,source,source_settlement_id) VALUES ($pscl_id,$claim_item_id,'$ti_e',$inv_d,$bd_sql,'$en_e','$cd_e',$aa,$va,$ta,'$lt_e','$st_e','$ct_e','$cc_e','$cn_e','$sb_e','pscl_claim',NULL)");

        if (!$ins) { echo json_encode(['success'=>false,'message'=>mysqli_error($conn)]); exit; }

        $res4 = mysqli_query($conn, "SELECT claim_item_id FROM dl_cheque_acknowledgments WHERE source='pscl_claim' AND confirmed_payment_id=$pscl_id");
        $ack_claim_ids = [];
        while ($r4 = mysqli_fetch_assoc($res4)) $ack_claim_ids[] = intval($r4['claim_item_id']);

        echo json_encode(['success'=>true,'ack_claim_ids'=>$ack_claim_ids]);
        exit;
    }

    if ($action === 'reverse_claim_ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pscl_id       = intval($_POST['pscl_id']       ?? 0);
        $claim_item_id = intval($_POST['claim_item_id'] ?? 0);

        if (!$pscl_id || !$claim_item_id) { echo json_encode(['success'=>false,'message'=>'Invalid data.']); exit; }

        $ok = mysqli_query($conn, "DELETE FROM dl_cheque_acknowledgments WHERE source='pscl_claim' AND confirmed_payment_id=$pscl_id AND claim_item_id=$claim_item_id");

        $res4 = mysqli_query($conn, "SELECT claim_item_id FROM dl_cheque_acknowledgments WHERE source='pscl_claim' AND confirmed_payment_id=$pscl_id");
        $ack_claim_ids = [];
        while ($r4 = mysqli_fetch_assoc($res4)) $ack_claim_ids[] = intval($r4['claim_item_id']);

        echo json_encode(['success'=>(bool)$ok,'ack_claim_ids'=>$ack_claim_ids]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']);
    exit;
}

function _get_settle_totals($conn, $pscl_id) {
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) as settled_amount, COALESCE(SUM(vat_amount),0) as settled_vat, COALESCE(SUM(total_amount),0) as settled_total, COUNT(*) as settle_count FROM pscl_settlements WHERE pscl_id=$pscl_id"));
    return $row;
}

// ══════════════════════════════════════════════════════════════════
//  PAGE INITIALIZATION
// ══════════════════════════════════════════════════════════════════
$months_list      = ['January','February','March','April','May','June','July','August','September','October','November','December'];
$current_year     = (int)date('Y');
$year_range_start = $current_year - 5;
$year_range_end   = $current_year + 2;

$filter_year   = isset($_GET['filter_year'])   ? intval($_GET['filter_year'])                               : '';
$filter_month  = isset($_GET['filter_month'])  ? intval($_GET['filter_month'])                              : '';
$filter_entity = isset($_GET['filter_entity']) ? mysqli_real_escape_string($conn, $_GET['filter_entity'])  : '';

$where = [];
if ($filter_year)   $where[] = "p.year  = $filter_year";
if ($filter_month)  $where[] = "p.month = $filter_month";
if ($filter_entity) $where[] = "p.entity LIKE '%$filter_entity%'";
$where_sql = $where ? 'WHERE '.implode(' AND ',$where) : '';

$rows_result = mysqli_query($conn, "SELECT p.*, COALESCE((SELECT SUM(s.total_amount) FROM pscl_settlements s WHERE s.pscl_id=p.id),0) AS total_settled, COALESCE((SELECT SUM(s.amount) FROM pscl_settlements s WHERE s.pscl_id=p.id),0) AS settled_amount, COALESCE((SELECT SUM(s.vat_amount) FROM pscl_settlements s WHERE s.pscl_id=p.id),0) AS settled_vat, (SELECT MAX(s.banking_date) FROM pscl_settlements s WHERE s.pscl_id=p.id) AS last_banking_date, (SELECT GROUP_CONCAT(s.tax_invoice_no ORDER BY s.id SEPARATOR ', ') FROM pscl_settlements s WHERE s.pscl_id=p.id) AS tax_inv_refs, (SELECT s.bank_ref FROM pscl_settlements s WHERE s.pscl_id=p.id ORDER BY s.id DESC LIMIT 1) AS last_bank_ref FROM promoters_salary_cl p $where_sql ORDER BY p.year DESC, p.month DESC, p.id DESC");
if (!$rows_result) $rows_result = null;

$w2 = $where ? str_replace('p.','p2.',$where_sql) : '';
$totals = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt, SUM(p.amount) as total_amount, SUM(p.vat) as total_vat, SUM(p.amount_with_vat) as total_with_vat, COALESCE((SELECT SUM(s.total_amount) FROM pscl_settlements s INNER JOIN promoters_salary_cl p2 ON s.pscl_id=p2.id $w2),0) as total_settled, COALESCE((SELECT SUM(s.amount) FROM pscl_settlements s INNER JOIN promoters_salary_cl p2 ON s.pscl_id=p2.id $w2),0) as settled_amount, COALESCE((SELECT SUM(s.vat_amount) FROM pscl_settlements s INNER JOIN promoters_salary_cl p2 ON s.pscl_id=p2.id $w2),0) as settled_vat FROM promoters_salary_cl p $where_sql"));
if (!$totals) $totals = ['cnt'=>0,'total_amount'=>0,'total_vat'=>0,'total_with_vat'=>0,'total_settled'=>0,'settled_amount'=>0,'settled_vat'=>0];

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
.data-table thead th{background:#f0f0f0;padding:10px 12px;text-align:left;font-weight:600;color:#222;font-size:12px;white-space:nowrap;border-bottom:2px solid #d5d5d5}
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
.month-badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:12px;font-size:11px;font-weight:700;background:#fef3c7;color:#92400e}
.vat-pill{font-size:11px;font-weight:700;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;border-radius:10px;padding:2px 8px}
.bank-ref-pill{font-size:11px;font-family:monospace;background:#f8fafc;color:#475569;border:1px solid #e2e8f0;border-radius:5px;padding:2px 7px}
.empty-state{text-align:center;padding:60px 20px;color:#9ca3af}
.empty-state i{font-size:40px;margin-bottom:12px;display:block}
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
.vat-hint{font-size:11px;color:#9ca3af;margin-top:2px}
input[type=number]::-webkit-inner-spin-button,input[type=number]::-webkit-outer-spin-button{-webkit-appearance:none}
input[type=number]{-moz-appearance:textfield}
.file-drop{border:2px dashed #d1d5db;border-radius:8px;padding:16px;text-align:center;cursor:pointer;background:#fafafa;position:relative}
.file-drop:hover{border-color:#000;background:#f5f5f5}
.file-drop input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.file-preview{display:none;align-items:center;gap:10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:10px 14px;margin-top:8px}
.file-preview.show{display:flex}
.file-preview-name{font-size:13px;color:#166534;font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.file-preview-rm{background:none;border:none;cursor:pointer;color:#dc2626;font-size:15px;padding:2px 5px}
.existing-doc{display:flex;align-items:center;gap:8px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:8px 12px;margin-bottom:8px;font-size:13px}
.existing-doc a{color:#1e40af;font-weight:600;text-decoration:none;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.year-current-badge{font-size:10px;background:#dbeafe;color:#1e40af;border-radius:4px;padding:1px 5px;font-weight:700;margin-left:3px}
.sm-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:2000;display:none;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto}
.sm-overlay.open{display:flex}
.sm-box{background:#fff;border-radius:14px;width:100%;max-width:1080px;box-shadow:0 24px 64px rgba(0,0,0,.25);overflow:hidden;margin:auto}
.sm-hdr{background:linear-gradient(135deg,#4c1d95,#7c3aed);padding:16px 22px;display:flex;align-items:center;justify-content:space-between}
.sm-hdr-title{color:#fff;font-size:16px;font-weight:700;display:flex;align-items:center;gap:9px}
.sm-hdr-close{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:8px;padding:5px 14px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}
.sm-hdr-close:hover{background:rgba(255,255,255,.25)}
.sm-strip{display:flex;flex-wrap:wrap;background:#f9fafb;border-bottom:2px solid #e5e5e5}
.sm-strip-cell{display:flex;flex-direction:column;gap:2px;padding:12px 20px;border-right:1px solid #e5e5e5;min-width:110px}
.sm-strip-cell:last-child{border-right:none}
.sm-lbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;font-weight:600}
.sm-val{font-size:15px;font-weight:800;color:#1f2937}
.sm-val.sky{color:#0369a1}.sm-val.green{color:#16a34a}.sm-val.purple{color:#7c3aed}
.sm-val.orange{color:#d97706}.sm-val.red{color:#dc2626}
.sm-body{display:grid;grid-template-columns:1fr 1fr;gap:0;border-bottom:1px solid #e5e5e5}
@media(max-width:680px){.sm-body{grid-template-columns:1fr}}
.sm-col{display:flex;flex-direction:column;overflow:hidden}
.sm-col-left{border-right:2px solid #e5e5e5}
.sm-col-hdr{padding:11px 16px;background:#fff;border-bottom:1px solid #f0f0f0;font-size:13px;font-weight:700;color:#1f2937;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-shrink:0}
.sm-ack-bar{padding:8px 12px;background:linear-gradient(90deg,#faf5ff,#f5f3ff);border-bottom:1px solid #ddd6fe;display:none;flex-direction:column;gap:6px;flex-shrink:0}
.sm-ack-bar.visible{display:flex}
.sm-ack-bar-top{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.sm-ack-bar-info{font-size:11px;color:#6d28d9;font-weight:700;flex:1;min-width:0}
.sm-ack-bar-btns{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.btn-send-ack{display:inline-flex;align-items:center;gap:5px;padding:5px 14px;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;border:none;border-radius:7px;font-size:11px;font-weight:800;cursor:pointer;font-family:inherit;box-shadow:0 2px 6px rgba(124,58,237,.3)}
.btn-send-ack:hover{filter:brightness(1.08)}
.btn-send-ack:disabled{opacity:.5;cursor:not-allowed}
.btn-sel-all-sp{background:#fff;border:1px solid #ddd6fe;color:#7c3aed;border-radius:5px;padding:4px 9px;font-size:10px;font-weight:700;cursor:pointer;font-family:inherit}
.btn-sel-all-sp:hover{background:#f5f3ff}
.sm-ack-hint{font-size:10px;color:#9ca3af;padding:0 2px}
.sm-claims-scroll{height:420px;overflow-y:auto;padding:10px 12px;background:#fff}
.claim-card{border:1px solid #e5e5e5;border-radius:8px;padding:10px 12px;margin-bottom:7px;cursor:pointer;transition:all .18s;background:#fff;position:relative}
.claim-card:hover:not(.done):not(.claim-acked){border-color:#7c3aed;background:#faf5ff}
.claim-card.done{display:none}
.claim-card.claim-acked{cursor:default;background:#fdf4ff;border-color:#e9d5ff;opacity:.75}
.claim-card.claim-acked:hover{background:#fdf4ff;border-color:#e9d5ff}
.cc-row1{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:4px;flex-wrap:wrap}
.cc-inv{font-size:12px;font-weight:700;font-family:monospace;color:#312e81}
.cc-ent{font-size:10px;font-weight:700;background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;border-radius:4px;padding:1px 6px}
.cc-tot{font-size:13px;font-weight:800;color:#0369a1}
.cc-desc{font-size:11px;color:#6b7280;line-height:1.4;margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cc-meta{display:flex;gap:10px;font-size:11px;color:#9ca3af;flex-wrap:wrap}
.cc-actions{display:flex;align-items:center;gap:6px;margin-top:7px;padding-top:7px;border-top:1px solid #f3f4f6;flex-wrap:wrap}
.cc-ack-btn{display:inline-flex;align-items:center;gap:4px;padding:4px 11px;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 1px 4px rgba(124,58,237,.25);transition:all .15s}
.cc-ack-btn:hover{filter:brightness(1.1);box-shadow:0 2px 8px rgba(124,58,237,.35)}
.cc-ack-btn:disabled{opacity:.5;cursor:not-allowed}
.cc-acked-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:10px;font-size:10px;font-weight:700;color:#6d28d9}
.cc-rev-btn{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;background:none;border:1px solid #fca5a5;border-radius:5px;color:#dc2626;font-size:10px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s}
.cc-rev-btn:hover{background:#fef2f2}
.cc-pick-hint{font-size:10px;color:#9ca3af;margin-left:auto}
.claim-card.selected-pick{outline:2px solid #7c3aed;background:#faf5ff}
.no-claims{text-align:center;padding:40px 16px;color:#9ca3af}
.no-claims i{font-size:30px;margin-bottom:8px;display:block}
.sm-search{width:100%;padding:7px 10px;border:1px solid #e5e5e5;border-radius:6px;font-size:12px;outline:none;font-family:inherit;margin-bottom:8px}
.sm-search:focus{border-color:#7c3aed}
.sm-show-done-wrap{display:flex;align-items:center;gap:6px;padding:5px 12px 5px;background:#fff;border-bottom:1px solid #f0f0f0;font-size:11px;color:#6b7280;flex-shrink:0}
.sm-show-done-wrap label{display:flex;align-items:center;gap:5px;cursor:pointer;font-weight:600;color:#7c3aed}
.sm-claim-count{margin-left:auto;font-size:10px;background:#f3f4f6;color:#6b7280;border-radius:10px;padding:2px 8px}
.sm-right-scroll{height:420px;overflow-y:auto;padding:10px 12px;background:#fafafa}
.apf-box{background:#fff;border:2px solid #ddd6fe;border-radius:10px;padding:14px;margin-bottom:10px}
.apf-box.hidden{display:none}
.apf-title{font-size:13px;font-weight:700;color:#7c3aed;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.apf-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.apf-grid .span2{grid-column:span 2}
.apf-lbl{font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;display:block}
.apf-inp{width:100%;padding:7px 10px;border:1px solid #ddd6fe;border-radius:6px;font-size:13px;font-family:inherit;outline:none}
.apf-inp:focus{border-color:#7c3aed;box-shadow:0 0 0 2px rgba(124,58,237,.1)}
.apf-inp[readonly]{background:#f5f3ff;color:#7c3aed;font-weight:700}
.apf-inp.green-ro{background:#f0fdf4;color:#16a34a;font-weight:800}
.apf-btns{display:flex;gap:8px;justify-content:flex-end;margin-top:10px}
.sp-card{border:1px solid #e5e5e5;border-radius:8px;padding:10px 12px;margin-bottom:7px;background:#fff;transition:opacity .2s}
.sp-card.acked{opacity:.65;background:#fdf4ff;border-color:#e9d5ff}
.sp-row1{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:4px;flex-wrap:wrap}
.sp-inv{font-size:12px;font-weight:700;font-family:monospace;color:#312e81}
.sp-tot{font-size:13px;font-weight:800;color:#16a34a}
.sp-meta{display:flex;gap:10px;font-size:11px;color:#6b7280;flex-wrap:wrap;margin-bottom:3px}
.sp-desc{font-size:11px;color:#9ca3af;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sp-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:6px}
.sp-del{background:none;border:1px solid #fca5a5;border-radius:5px;color:#dc2626;cursor:pointer;padding:3px 8px;font-size:11px;display:inline-flex;align-items:center;gap:4px;transition:all .15s;font-family:inherit}
.sp-del:hover{background:#fef2f2}
.sp-ack-btn{background:#f5f3ff;border:1px solid #ddd6fe;border-radius:5px;color:#7c3aed;cursor:pointer;padding:3px 8px;font-size:11px;display:inline-flex;align-items:center;gap:4px;transition:all .15s;font-family:inherit;font-weight:600}
.sp-ack-btn:hover{background:#7c3aed;color:#fff;border-color:#7c3aed}
.sp-ack-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe}
.sp-rev-btn{background:none;border:1px solid #fca5a5;border-radius:5px;color:#dc2626;cursor:pointer;padding:3px 7px;font-size:10px;display:inline-flex;align-items:center;gap:3px;font-family:inherit;transition:all .15s}
.sp-rev-btn:hover{background:#fef2f2}
.sp-cb-wrap{display:inline-flex;align-items:center;gap:5px;cursor:pointer}
.sp-cb-wrap input[type=checkbox]{accent-color:#7c3aed;width:14px;height:14px;cursor:pointer}
.sp-cb-wrap input[type=checkbox]:disabled{cursor:default;opacity:.6}
.sp-empty{text-align:center;padding:30px 16px;color:#9ca3af;font-size:13px}
.sm-footer{padding:12px 22px;display:flex;align-items:center;justify-content:flex-end;gap:10px;background:#fff;border-top:1px solid #f0f0f0}
#toast{position:fixed;bottom:26px;right:26px;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;color:#fff;z-index:9999;display:none;box-shadow:0 4px 16px rgba(0,0,0,.18)}
#toast.success{background:#16a34a}#toast.error{background:#dc2626}#toast.info{background:#1e3a5f}#toast.warn{background:#d97706}
@media(max-width:600px){.form-grid{grid-template-columns:1fr 1fr}.form-grid .span3,.form-divider{grid-column:span 2}.sm-body{grid-template-columns:1fr}.sm-col-left{border-right:none;border-bottom:2px solid #e5e5e5}}
@media(max-width:420px){.form-grid{grid-template-columns:1fr}.form-grid .span2,.form-grid .span3,.form-divider{grid-column:span 1}}
</style>

<!-- ── Page Header ────────────────────────────────────────────── -->
<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h2 class="page-title"><i class="fa-solid fa-users"></i> Promoters Salary (CC)</h2>
      <p class="page-subtitle">Manage CL promoter salary payments, VAT and settlements</p>
    </div>
    <button class="btn btn-primary" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> Add New Entry</button>
  </div>
</div>

<!-- ── Summary ────────────────────────────────────────────────── -->
<div class="summary-grid">
  <div class="summary-box">
    <div class="summary-label">Total Records</div>
    <div class="summary-value"><?php echo number_format($totals['cnt']); ?></div>
    <div class="summary-sub">Filtered results</div>
  </div>
  <div class="summary-box">
    <div class="summary-label">Total Amount</div>
    <div class="summary-value"><?php echo $totals['total_amount']!==null?number_format($totals['total_amount'],2):'—'; ?></div>
    <div class="summary-sub">Before VAT</div>
  </div>
  <div class="summary-box">
    <div class="summary-label">Total VAT</div>
    <div class="summary-value" style="color:#7c3aed"><?php echo $totals['total_vat']!==null?number_format($totals['total_vat'],2):'—'; ?></div>
    <div class="summary-sub">18% VAT sum</div>
  </div>
  <div class="summary-box">
    <div class="summary-label">Total with VAT</div>
    <div class="summary-value" style="color:#0369a1"><?php echo $totals['total_with_vat']!==null?number_format($totals['total_with_vat'],2):'—'; ?></div>
    <div class="summary-sub">Amount + VAT</div>
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
    <div class="summary-value" style="color:#16a34a"><?php echo number_format($totals['total_settled']??0,2); ?></div>
    <div class="summary-sub">Amount+VAT settled</div>
  </div>
  <div class="summary-box">
    <?php
      $diff    = floatval($totals['total_settled']??0)-floatval($totals['total_with_vat']??0);
      $diffSgn = $diff>0?'+':'';
    ?>
    <div class="summary-label">Balance</div>
    <div class="summary-value" style="color:<?php echo $diff>0?'#16a34a':($diff<0?'#dc2626':'#16a34a'); ?>">
      <?php echo $diffSgn.number_format($diff,2); ?>
    </div>
    <div class="summary-sub">Settled vs With VAT</div>
  </div>
</div>

<!-- ── Records Table ──────────────────────────────────────────── -->
<div class="content-card">
  <div class="card-header-row">
    <h3 class="card-title"><i class="fa-solid fa-table"></i> Salary Records</h3>
    <form method="GET" action="" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
      <div class="fbar-group">
        <label class="fbar-label">Year</label>
        <input type="number" name="filter_year" class="fbar-input" placeholder="<?php echo $current_year; ?>" value="<?php echo htmlspecialchars($filter_year); ?>" style="width:90px">
      </div>
      <div class="fbar-group">
        <label class="fbar-label">Month</label>
        <select name="filter_month" class="fbar-select" style="width:120px">
          <option value="">All Months</option>
          <?php foreach($months_list as $mi=>$mn): ?>
            <option value="<?php echo $mi+1; ?>" <?php echo $filter_month==$mi+1?'selected':''; ?>><?php echo $mn; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fbar-group">
        <label class="fbar-label">Entity</label>
        <input type="text" name="filter_entity" class="fbar-input" placeholder="Filter entity…" value="<?php echo htmlspecialchars($filter_entity); ?>" style="width:130px">
      </div>
      <button type="submit" class="btn btn-dark btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
      <a href="promoters_salary_cl.php" class="btn btn-light btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
    </form>
  </div>

  <div class="toolbar">
    <div class="search-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" class="search-input" id="searchBox" placeholder="Search entity, description…" oninput="doSearch()">
    </div>
    <span id="rowCount" style="font-size:12px;color:#6b7280"></span>
  </div>

  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th style="text-align:center;width:36px"><i class="fa-solid fa-paperclip"></i></th>
          <th>#</th>
          <th>Month / Year</th>
          <th>Date</th>
          <th>Entity</th>
          <th>Description</th>
          <th class="num">Amount</th>
          <th class="num">VAT 18%</th>
          <th class="num">Amt+VAT</th>
          <th class="num">Settled Amt</th>
          <th class="num">Settled VAT</th>
          <th class="num">Settled Total</th>
          <th class="num">Balance</th>
          <th>Last Bank Date</th>
          <th>Last Bank Ref</th>
          <th>Tax Inv Refs</th>
          <th style="text-align:center">Actions</th>
        </tr>
      </thead>
      <tbody id="tableBody">
      <?php
      if (!$rows_result || mysqli_num_rows($rows_result) === 0):
      ?>
        <tr><td colspan="17"><div class="empty-state"><i class="fa-solid fa-users"></i><p>No records found. Click <strong>Add New Entry</strong> to get started.</p></div></td></tr>
      <?php
      else:
        $rn = 1;
        while ($r = mysqli_fetch_assoc($rows_result)):
          $awv     = floatval($r['amount_with_vat'] ?? 0);
          $sAmt    = floatval($r['settled_amount']  ?? 0);
          $sVat    = floatval($r['settled_vat']     ?? 0);
          $sTot    = floatval($r['total_settled']   ?? 0);
          $bal     = $sTot - $awv;
          $balCl   = $bal>0?'bal-pos':($bal<0?'bal-neg':'bal-zero');
          $balSign = $bal>0?'+':'';
          $mname   = $r['month'] ? $months_list[intval($r['month'])-1] : '—';
      ?>
        <tr id="tr-<?php echo $r['id']; ?>"
            data-awv="<?php echo $awv; ?>"
            data-search="<?php echo strtolower(htmlspecialchars(($r['entity']??'').' '.($r['description']??'').' '.($r['bank_deposit_ref']??'').' '.($r['last_bank_ref']??''))); ?>">
          <td style="text-align:center">
            <?php if (!empty($r['document_path']) && file_exists($r['document_path'])): ?>
              <a href="<?php echo htmlspecialchars($r['document_path']); ?>" target="_blank" class="doc-icon-link"
                 title="<?php echo htmlspecialchars($r['document_name']??'Document'); ?>"><i class="fa-solid fa-paperclip"></i></a>
            <?php else: ?><span class="no-doc"><i class="fa-solid fa-minus"></i></span><?php endif; ?>
          </td>
          <td><?php echo $rn++; ?></td>
          <td><?php if($r['month']||$r['year']): ?>
            <span class="month-badge"><?php echo $mname.($r['year']?' '.$r['year']:''); ?></span>
          <?php else: ?>—<?php endif; ?></td>
          <td><?php echo !empty($r['entry_date'])?date('d M Y',strtotime($r['entry_date'])):'—'; ?></td>
          <td><?php echo htmlspecialchars($r['entity']??'—'); ?></td>
          <td style="max-width:170px;overflow:hidden;text-overflow:ellipsis"
              title="<?php echo htmlspecialchars($r['description']??''); ?>"><?php echo htmlspecialchars($r['description']??'—'); ?></td>
          <td class="num"><?php echo $r['amount']!==null?number_format($r['amount'],2):'—'; ?></td>
          <td class="num"><span class="vat-pill"><?php echo $r['vat']!==null?number_format($r['vat'],2):'—'; ?></span></td>
          <td class="num" style="font-weight:700;color:#0369a1"><?php echo $awv?number_format($awv,2):'—'; ?></td>
          <td class="num" id="td-sAmt-<?php echo $r['id']; ?>" style="color:#16a34a"><?php echo $sAmt>0?number_format($sAmt,2):'—'; ?></td>
          <td class="num" id="td-sVat-<?php echo $r['id']; ?>" style="color:#7c3aed"><?php echo $sVat>0?number_format($sVat,2):'—'; ?></td>
          <td class="num" id="td-sTot-<?php echo $r['id']; ?>" style="color:#16a34a;font-weight:700"><?php echo $sTot>0?number_format($sTot,2):'—'; ?></td>
          <td class="num" id="td-bal-<?php echo $r['id']; ?>"><span class="<?php echo $balCl; ?>"><?php echo $balSign.number_format($bal,2); ?></span></td>
          <td id="td-lbd-<?php echo $r['id']; ?>" style="font-size:12px;color:#6b7280"><?php echo !empty($r['last_banking_date'])?date('d M Y',strtotime($r['last_banking_date'])):'—'; ?></td>
          <td id="td-lbr-<?php echo $r['id']; ?>">
            <?php if(!empty($r['last_bank_ref'])): ?>
              <span class="bank-ref-pill"><?php echo htmlspecialchars($r['last_bank_ref']); ?></span>
            <?php else: ?><span style="color:#d1d5db">—</span><?php endif; ?>
          </td>
          <td id="td-tinv-<?php echo $r['id']; ?>" style="font-size:12px;color:#6b7280;max-width:130px;overflow:hidden;text-overflow:ellipsis"
              title="<?php echo htmlspecialchars($r['tax_inv_refs']??''); ?>"><?php echo htmlspecialchars($r['tax_inv_refs']??'—'); ?></td>
          <td style="text-align:center">
            <div class="action-buttons">
              <button class="btn btn-settle btn-xs<?php echo $sTot>0?' settled':''; ?>"
                      id="settlebtn-<?php echo $r['id']; ?>"
                      onclick="openSettle(<?php echo $r['id']; ?>,<?php echo $awv; ?>)">
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
          <td class="num"><?php echo $totals['total_amount']!==null?number_format($totals['total_amount'],2):'—'; ?></td>
          <td class="num" style="color:#7c3aed"><?php echo $totals['total_vat']!==null?number_format($totals['total_vat'],2):'—'; ?></td>
          <td class="num" style="color:#0369a1"><?php echo $totals['total_with_vat']!==null?number_format($totals['total_with_vat'],2):'—'; ?></td>
          <td class="num" style="color:#16a34a"><?php echo number_format($totals['settled_amount']??0,2); ?></td>
          <td class="num" style="color:#7c3aed"><?php echo number_format($totals['settled_vat']??0,2); ?></td>
          <td class="num" style="color:#16a34a"><?php echo number_format($totals['total_settled']??0,2); ?></td>
          <?php $ftDiff = ($totals['total_settled']??0)-($totals['total_with_vat']??0); ?>
          <td class="num" style="color:<?php echo $ftDiff>0?'#16a34a':($ftDiff<0?'#dc2626':'#16a34a'); ?>"><?php echo ($ftDiff>0?'+':'').number_format($ftDiff,2); ?></td>
          <td colspan="5"></td>
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
      <div class="modal-title"><i class="fa-solid fa-users"></i><span id="modalTitleText">Add New Entry</span></div>
      <button class="modal-close" onclick="closeAddModal()">×</button>
    </div>
    <div class="modal-body">
      <form id="entryForm" enctype="multipart/form-data" onsubmit="return false">
        <input type="hidden" id="fid" name="id" value="0">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Month</label>
            <select class="form-select" id="f_month" name="month">
              <option value="">— Select Month —</option>
              <?php foreach($months_list as $mi=>$mn): ?>
                <option value="<?php echo $mi+1; ?>"><?php echo $mn; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Year <span class="year-current-badge"><?php echo $current_year; ?></span></label>
            <select class="form-select" id="f_year" name="year">
              <option value="">— Select Year —</option>
              <?php for($y=$year_range_end;$y>=$year_range_start;$y--): ?>
                <option value="<?php echo $y; ?>" <?php echo $y===$current_year?'selected':''; ?>>
                  <?php echo $y.($y===$current_year?' ★':''); ?>
                </option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Date</label>
            <input type="date" class="form-input" id="f_entry_date" name="entry_date">
          </div>
          <div class="form-group span2">
            <label class="form-label">Entity</label>
            <input type="text" class="form-input" id="f_entity" name="entity" placeholder="e.g. IKEA Sri Lanka">
          </div>
          <div class="form-group span3">
            <label class="form-label">Description</label>
            <input type="text" class="form-input" id="f_description" name="description" placeholder="e.g. Promoter salary payment – March 2024">
          </div>
          <hr class="form-divider">
          <div class="form-group">
            <label class="form-label">Amount</label>
            <input type="number" step="0.01" min="0" class="form-input" id="f_amount" name="amount" placeholder="0.00" oninput="calcVAT()">
          </div>
          <div class="form-group">
            <label class="form-label">VAT <span class="auto">AUTO 18%</span></label>
            <input type="number" step="0.01" class="form-input auto-fill" id="f_vat" name="vat" placeholder="0.00" readonly>
            <div class="vat-hint">Amount × 18%</div>
          </div>
          <div class="form-group">
            <label class="form-label">Amt with VAT <span class="auto">AUTO</span></label>
            <input type="number" step="0.01" class="form-input auto-fill" id="f_amount_with_vat" name="amount_with_vat" placeholder="0.00" readonly>
            <div class="vat-hint">Amount + VAT</div>
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
      <div class="sm-strip-cell"><div class="sm-lbl">Invoice Total</div><div class="sm-val sky" id="ss-inv">0.00</div></div>
      <div class="sm-strip-cell"><div class="sm-lbl">Settled Amount</div><div class="sm-val green" id="ss-amt">0.00</div></div>
      <div class="sm-strip-cell"><div class="sm-lbl">Settled VAT</div><div class="sm-val purple" id="ss-vat">0.00</div></div>
      <div class="sm-strip-cell"><div class="sm-lbl">Settled Total</div><div class="sm-val green" id="ss-tot">0.00</div></div>
      <div class="sm-strip-cell"><div class="sm-lbl">Balance</div><div class="sm-val orange" id="ss-bal">0.00</div></div>
      <div class="sm-strip-cell"><div class="sm-lbl">Payments</div><div class="sm-val" id="ss-cnt">0</div></div>
    </div>

    <div class="sm-body">
      <!-- ══ LEFT: Matching claims + ACK BAR ══ -->
      <div class="sm-col sm-col-left">
        <div class="sm-col-hdr">
          <span><i class="fa-solid fa-file-invoice" style="color:#7c3aed"></i> Matching Claim Certificates</span>
          <span class="sm-claim-count" id="smClaimCount">0</span>
        </div>

        <!-- Payment-path bulk ack bar -->
        <div class="sm-ack-bar" id="smAckBar">
          <div class="sm-ack-bar-top">
            <span class="sm-ack-bar-info" id="smAckBarInfo">Select payments on the right → send to Ack</span>
            <div class="sm-ack-bar-btns">
              <button class="btn-sel-all-sp" onclick="selAllPayments(true)">Select All</button>
              <button class="btn-sel-all-sp" onclick="selAllPayments(false)">Deselect</button>
              <button class="btn-send-ack" id="btnSendAck" onclick="sendToAck()" disabled>
                <i class="fa-solid fa-envelope-open-text"></i> Send to Acknowledgment
              </button>
            </div>
          </div>
          <div class="sm-ack-hint"><i class="fa-solid fa-circle-info"></i> Check payments on the right panel, then click Send to Acknowledgment</div>
        </div>

        <!-- Search + show-settled toggle -->
        <div style="padding:8px 12px 0;background:#fff;flex-shrink:0">
          <input type="text" class="sm-search" id="claimSearch" placeholder="Search invoice / description / entity…" oninput="filterClaims(this.value)">
        </div>
        <div class="sm-show-done-wrap">
          <label>
            <input type="checkbox" id="showSettledClaims" onchange="toggleShowSettled()">
            Show settled claims
          </label>
          <span id="hiddenCount" style="font-size:10px;color:#9ca3af"></span>
        </div>

        <div class="sm-claims-scroll" id="claimsList">
          <div class="no-claims"><i class="fa-solid fa-spinner fa-spin"></i><p>Loading…</p></div>
        </div>
      </div>

      <!-- ══ RIGHT: Payment form + payments list ══ -->
      <div class="sm-col">
        <div class="sm-col-hdr">
          <span><i class="fa-solid fa-receipt" style="color:#16a34a"></i> Payment Settlements</span>
          <button class="btn btn-xs btn-primary" style="padding:5px 12px;font-size:11px" onclick="showAPF()">
            <i class="fa-solid fa-plus"></i> Add Payment
          </button>
        </div>

        <div class="sm-right-scroll" id="smRightScroll">
          <!-- Add-payment form -->
          <div class="apf-box hidden" id="apf">
            <div class="apf-title"><i class="fa-solid fa-circle-plus"></i> <span id="apf-head">New Payment</span></div>
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
                <label class="apf-lbl">Amount (excl. VAT)</label>
                <input type="number" step="0.01" min="0" class="apf-inp" id="apf-amt" placeholder="0.00" oninput="apfCalc()">
              </div>
              <div>
                <label class="apf-lbl">VAT <span style="font-size:9px;background:#dbeafe;color:#1e40af;border-radius:3px;padding:1px 4px">AUTO</span></label>
                <input type="number" step="0.01" class="apf-inp" id="apf-vat" placeholder="0.00" readonly>
              </div>
              <div>
                <label class="apf-lbl">Total Amount</label>
                <input type="number" step="0.01" class="apf-inp green-ro" id="apf-tot" placeholder="0.00" readonly>
              </div>
              <div>
                <label class="apf-lbl">Description</label>
                <input type="text" class="apf-inp" id="apf-desc" placeholder="Auto-filled from claim">
              </div>
            </div>
            <div class="apf-btns">
              <button class="btn btn-light btn-sm" style="padding:7px 14px;font-size:12px" onclick="hideAPF()"><i class="fa-solid fa-xmark"></i> Cancel</button>
              <button class="btn btn-primary btn-sm" style="padding:7px 16px;font-size:12px" id="apf-save" onclick="savePayment()"><i class="fa-solid fa-floppy-disk"></i> Save Payment</button>
            </div>
          </div>

          <!-- Settled payments list -->
          <div id="spList">
            <div class="sp-empty"><i class="fa-solid fa-receipt" style="font-size:22px;display:block;margin-bottom:8px;color:#d1d5db"></i>No payments yet. Select a claim or click Add Payment.</div>
          </div>
        </div>
      </div>
    </div><!-- /.sm-body -->

    <div class="sm-footer">
      <button class="btn btn-light" onclick="closeSettle()">Close</button>
    </div>
    <input type="hidden" id="sm-pid" value="0">
    <input type="hidden" id="sm-inv" value="0">
  </div>
</div>

<div id="toast"></div>

<script>
const CY = <?php echo $current_year; ?>;
const $ = id => document.getElementById(id);
const fN = v => parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
const eh = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

function toast(msg, type){
    const t=$('toast'); t.textContent=msg; t.className=type; t.style.display='block';
    clearTimeout(t._t); t._t=setTimeout(()=>t.style.display='none',3800);
}

function calcVAT(){
    const a=parseFloat($('f_amount').value||0)||0, v=Math.round(a*.18*100)/100;
    $('f_vat').value=a>0?v.toFixed(2):'';
    $('f_amount_with_vat').value=a>0?(a+v).toFixed(2):'';
}

function apfCalc(){
    const a=parseFloat($('apf-amt').value||0)||0, v=Math.round(a*.18*100)/100;
    $('apf-vat').value=a>0?v.toFixed(2):'';
    $('apf-tot').value=a>0?(a+v).toFixed(2):'';
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
    if(rows.length) $('rowCount').textContent=`(${rows.length})`;
});

function openAddModal(title='Add New Entry'){$('modalTitleText').textContent=title;$('entryModal').classList.add('open');}
function closeAddModal(){$('entryModal').classList.remove('open');resetForm();}
function resetForm(){
    $('fid').value='0';
    ['f_entry_date','f_entity','f_description','f_amount','f_vat','f_amount_with_vat'].forEach(id=>$(id).value='');
    $('f_month').value='';$('f_year').value=CY;
    $('existingDocWrap').style.display='none';clearFile();
}

function handleFileSelect(inp){if(inp.files[0])showFP(inp.files[0].name);}
function handleDrop(e){
    e.preventDefault();$('fileDrop').classList.remove('drag');
    const f=e.dataTransfer.files[0];if(!f)return;
    const dt=new DataTransfer();dt.items.add(f);$('f_document').files=dt.files;showFP(f.name);
}
function showFP(name){$('filePreviewName').textContent=name;$('filePreview').classList.add('show');$('fileDrop').style.display='none';}
function clearFile(){$('f_document').value='';$('filePreview').classList.remove('show');$('fileDrop').style.display='';}

function saveEntry(){
    const id=$('fid').value,btn=$('saveBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    fetch('promoters_salary_cl.php?action=save',{method:'POST',body:new FormData($('entryForm'))})
        .then(r=>r.json()).then(res=>{
            btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Entry';
            if(res.success){toast(parseInt(id)>0?'Entry updated!':'Entry added!','success');closeAddModal();setTimeout(()=>location.reload(),800);}
            else toast('Error: '+(res.message||'Unknown'),'error');
        }).catch(e=>{btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Entry';toast('Network error: '+e.message,'error');});
}

function editRow(id){
    fetch('promoters_salary_cl.php?action=get&id='+id).then(r=>r.json()).then(res=>{
        if(!res.success||!res.data){toast('Could not load record.','error');return;}
        const d=res.data;
        $('fid').value=''+d.id;
        $('f_month').value=d.month||'';
        $('f_year').value=d.year||CY;
        $('f_entry_date').value=d.entry_date||'';
        $('f_entity').value=d.entity||'';
        $('f_description').value=d.description||'';
        $('f_amount').value=d.amount||'';
        $('f_vat').value=d.vat||'';
        $('f_amount_with_vat').value=d.amount_with_vat||'';
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
    fetch('promoters_salary_cl.php?action=delete',{method:'POST',body:fd})
        .then(r=>r.json()).then(res=>{
            if(res.success){document.getElementById('tr-'+id)?.remove();toast('Entry deleted.','success');}
            else toast('Delete failed.','error');
        }).catch(()=>toast('Network error.','error'));
}

let _pid=0, _invTotal=0, _allClaims=[], _settledCIDs=new Set(), _ackSIDs=new Set(), _ackCIDs=new Set();

async function openSettle(psclId, invTotal){
    _pid=psclId; _invTotal=invTotal;
    $('sm-pid').value=psclId; $('sm-inv').value=invTotal;
    $('sm-title').textContent='Settlements — Record #'+psclId;
    $('ss-inv').textContent=fN(invTotal);
    $('settleModal').classList.add('open');

    $('claimsList').innerHTML='<div class="no-claims"><i class="fa-solid fa-spinner fa-spin"></i><p>Loading claims…</p></div>';
    $('spList').innerHTML='<div class="sp-empty"><i class="fa-solid fa-spinner fa-spin" style="font-size:20px;display:block;margin-bottom:8px;color:#d1d5db"></i></div>';
    $('smAckBar').classList.remove('visible');
    $('showSettledClaims').checked=false;
    hideAPF();

    try {
        const [cR,sR] = await Promise.all([
            fetch('promoters_salary_cl.php?action=get_claim_matches&pscl_id='+psclId).then(r=>r.json()),
            fetch('promoters_salary_cl.php?action=get_settlements&pscl_id='+psclId).then(r=>r.json())
        ]);
        _allClaims=cR.claims||[];
        _settledCIDs=new Set((cR.settled_ids||[]).map(Number));
        _ackSIDs=new Set((sR.ack_ids||[]).map(Number));
        _ackCIDs=new Set((cR.ack_claim_ids||[]).map(Number));
        renderClaims(_allClaims);
        renderPayments(sR.data||[]);
        updateStrip(sR.totals||{});
    } catch(e){
        $('claimsList').innerHTML='<div class="no-claims"><i class="fa-solid fa-exclamation-circle"></i><p>Failed to load.</p></div>';
        toast('Load error: '+e.message,'error');
    }
}

function closeSettle(){$('settleModal').classList.remove('open');_allClaims=[];_settledCIDs=new Set();_ackSIDs=new Set();_ackCIDs=new Set();}
$('settleModal').addEventListener('click',e=>{if(e.target===$('settleModal'))closeSettle();});

function renderClaims(list){
    if(!list||!list.length){
        $('claimsList').innerHTML='<div class="no-claims"><i class="fa-solid fa-file-invoice"></i><p>No matching claims found.</p></div>';
        $('smClaimCount').textContent='0';
        return;
    }
    let h='';
    list.forEach(c=>{
        const cid=parseInt(c.id);
        const done=_settledCIDs.has(cid);
        const isAcked=_ackCIDs.has(cid);
        const tot=parseFloat(c.total_amount||0),act=parseFloat(c.actual_amount||0),vat=parseFloat(c.vat_amount||0);

        let cardClass='claim-card';
        if(done)cardClass+=' done';
        if(isAcked)cardClass+=' claim-acked';

        let actionHTML='';
        if(!done){
            const pickHint=!isAcked?`<span class="cc-pick-hint"><i class="fa-solid fa-hand-pointer"></i> Click to fill form</span>`:'';
            const ackSection=isAcked
                ?`<span class="cc-acked-badge"><i class="fa-solid fa-check-circle"></i> Sent to Acknowledgment</span>
                  <button class="cc-rev-btn" onclick="event.stopPropagation();reverseClaimAck(${cid})"><i class="fa-solid fa-rotate-left"></i> Reverse</button>`
                :`<button class="cc-ack-btn" onclick="event.stopPropagation();sendClaimToAck(${cid})"><i class="fa-solid fa-envelope-open-text"></i> Send to Acknowledgment</button>`;
            actionHTML=`<div class="cc-actions">${ackSection}${pickHint}</div>`;
        }

        h+=`<div class="${cardClass}" data-id="${c.id}" data-done="${done?1:0}" data-s="${eh((c.tax_invoice_no+' '+c.claim_description+' '+c.entity).toLowerCase())}" onclick="${!isAcked&&!done?`pickClaim(this)`:''}">
          <div class="cc-row1">
            <span class="cc-inv"><i class="fa-solid fa-file-invoice" style="color:#7c3aed;font-size:10px"></i> ${eh(c.tax_invoice_no||'—')}</span>
            <span class="cc-ent">${eh(c.entity||'—')}</span>
            <span class="cc-tot">${fN(tot)}</span>
          </div>
          <div class="cc-desc" title="${eh(c.claim_description||'')}">${eh((c.claim_description||'').slice(0,72))}</div>
          <div class="cc-meta">
            <span>Actual: ${fN(act)}</span><span>VAT: ${fN(vat)}</span>
            ${c.banking_date?`<span>${eh(c.banking_date)}</span>`:''}
            ${c.invoice_date?`<span>Inv: ${eh(c.invoice_date)}</span>`:''}
          </div>
          ${actionHTML}
        </div>`;
    });
    $('claimsList').innerHTML=h;
    updateClaimCount();
}

function updateClaimCount(){
    const total=_allClaims.length;
    const hidden=_settledCIDs.size;
    const visible=total-hidden;
    $('smClaimCount').textContent=`${visible} available`;
    const hc=$('hiddenCount');
    if(hc)hc.textContent=hidden>0?`(${hidden} payment-linked, hidden)`:'';
}

function toggleShowSettled(){
    const show=$('showSettledClaims').checked;
    document.querySelectorAll('.claim-card.done').forEach(el=>{el.style.display=show?'block':'none';});
}

function filterClaims(q){
    q=(q||'').toLowerCase().trim();
    const showSettled=$('showSettledClaims').checked;
    document.querySelectorAll('#claimsList .claim-card').forEach(el=>{
        const isDone=el.dataset.done==='1';
        if(isDone&&!showSettled){el.style.display='none';return;}
        el.style.display=(!q||(el.dataset.s||'').includes(q))?'':'none';
    });
}

function pickClaim(el){
    if(el.dataset.done==='1')return;
    if(el.classList.contains('claim-acked'))return;
    document.querySelectorAll('.claim-card').forEach(c=>c.classList.remove('selected-pick'));
    el.classList.add('selected-pick');
    const c=_allClaims.find(x=>x.id==el.dataset.id)||{};
    $('apf-cid').value=c.id||'';
    $('apf-ti').value=c.tax_invoice_no||'';
    $('apf-ent').value=c.entity||'';
    $('apf-bd').value=c.banking_date||'';
    $('apf-amt').value=parseFloat(c.actual_amount||0)>0?parseFloat(c.actual_amount).toFixed(2):'';
    $('apf-vat').value=parseFloat(c.vat_amount||0)>0?parseFloat(c.vat_amount).toFixed(2):'';
    $('apf-tot').value=parseFloat(c.total_amount||0)>0?parseFloat(c.total_amount).toFixed(2):'';
    $('apf-desc').value=c.claim_description||'';
    $('apf-head').textContent='Payment: '+(c.tax_invoice_no||'New');
    showAPF();
}

async function sendClaimToAck(claimItemId){
    if(!confirm('Send this claim certificate directly to Acknowledgment?\n\nNo payment record will be created — only the acknowledgment entry.'))return;
    const card=document.querySelector(`.claim-card[data-id="${claimItemId}"]`);
    const btn=card?card.querySelector('.cc-ack-btn'):null;
    if(btn){btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Sending…';}
    const fd=new FormData();
    fd.append('pscl_id',_pid);
    fd.append('claim_item_id',claimItemId);
    try{
        const res=await fetch('promoters_salary_cl.php?action=send_claim_to_ack',{method:'POST',body:fd});
        const d=await res.json();
        if(!d.success){
            toast('Error: '+(d.message||'Failed'),'error');
            if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-envelope-open-text"></i> Send to Acknowledgment';}
            return;
        }
        _ackCIDs=new Set((d.ack_claim_ids||[]).map(Number));
        renderClaims(_allClaims);
        toast('Claim sent to Cheque Acknowledgment.','info');
    }catch(e){
        toast('Network error: '+e.message,'error');
        if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-envelope-open-text"></i> Send to Acknowledgment';}
    }
}

async function reverseClaimAck(claimItemId){
    if(!confirm('Reverse this acknowledgment? The claim will return to available status.'))return;
    const fd=new FormData();
    fd.append('pscl_id',_pid);
    fd.append('claim_item_id',claimItemId);
    try{
        const res=await fetch('promoters_salary_cl.php?action=reverse_claim_ack',{method:'POST',body:fd});
        const d=await res.json();
        if(!d.success){toast('Reverse failed.','error');return;}
        _ackCIDs=new Set((d.ack_claim_ids||[]).map(Number));
        renderClaims(_allClaims);
        toast('Claim acknowledgment reversed.','warn');
    }catch(e){toast('Network error: '+e.message,'error');}
}

function showAPF(){$('apf').classList.remove('hidden');$('smRightScroll').scrollTop=0;}
function hideAPF(){
    $('apf').classList.add('hidden');
    ['apf-cid','apf-ti','apf-ent','apf-bd','apf-br','apf-amt','apf-vat','apf-tot','apf-desc'].forEach(id=>$(id).value='');
    $('apf-head').textContent='New Payment';
    document.querySelectorAll('.claim-card').forEach(c=>c.classList.remove('selected-pick'));
}

async function savePayment(){
    const pid=parseInt($('sm-pid').value||0);
    const amt=parseFloat($('apf-amt').value||0);
    if(!pid){toast('No record.','error');return;}
    if(!(amt>0)){toast('Amount must be > 0.','error');return;}
    const btn=$('apf-save');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    const fd=new FormData();
    fd.append('pscl_id',pid);
    fd.append('claim_cert_item_id',$('apf-cid').value||0);
    fd.append('tax_invoice_no',$('apf-ti').value||'');
    fd.append('entity',$('apf-ent').value||'');
    fd.append('banking_date',$('apf-bd').value||'');
    fd.append('bank_ref',$('apf-br').value||'');
    fd.append('amount',$('apf-amt').value||0);
    fd.append('vat_amount',$('apf-vat').value||0);
    fd.append('claim_description',$('apf-desc').value||'');
    try{
        const res=await fetch('promoters_salary_cl.php?action=add_settlement',{method:'POST',body:fd});
        const d=await res.json();
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Payment';
        if(!d.success){toast('Error: '+(d.message||'Failed'),'error');return;}
        toast('Payment saved!','success');
        const cid=parseInt($('apf-cid').value||0);
        if(cid>0){
            _settledCIDs.add(cid);
            const card=document.querySelector(`.claim-card[data-id="${cid}"]`);
            if(card){
                card.classList.add('done');
                card.dataset.done='1';
                card.classList.remove('selected-pick');
                if(!$('showSettledClaims').checked)card.style.display='none';
            }
            updateClaimCount();
        }
        hideAPF();
        const sr=await fetch('promoters_salary_cl.php?action=get_settlements&pscl_id='+pid).then(r=>r.json());
        _ackSIDs=new Set((sr.ack_ids||[]).map(Number));
        renderPayments(sr.data||[]);
        updateStrip(sr.totals||{});
        updateTableRow(pid,sr.totals||{});
    }catch(e){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Payment';toast('Network error: '+e.message,'error');}
}

function renderPayments(list){
    if(!list||!list.length){
        $('spList').innerHTML='<div class="sp-empty"><i class="fa-solid fa-receipt" style="font-size:22px;display:block;margin-bottom:8px;color:#d1d5db"></i>No payments recorded yet.</div>';
        $('smAckBar').classList.remove('visible');
        return;
    }
    $('smAckBar').classList.add('visible');
    let h='';
    list.forEach(p=>{
        const isAcked=_ackSIDs.has(parseInt(p.id));
        const tot=parseFloat(p.total_amount||p.amount||0);
        const amt=parseFloat(p.amount||0),vat=parseFloat(p.vat_amount||0);
        h+=`<div class="sp-card${isAcked?' acked':''}" id="sp-${p.id}" data-id="${p.id}" data-acked="${isAcked?1:0}">
          <div class="sp-row1">
            <label class="sp-cb-wrap">
              <input type="checkbox" class="sp-cb" data-id="${p.id}" onchange="spCbChange()" ${isAcked?'disabled checked':''}>
              <span class="sp-inv"><i class="fa-solid fa-file-invoice" style="color:#312e81"></i> ${eh(p.tax_invoice_no||'—')}</span>
            </label>
            <span class="sp-tot">${fN(tot)}</span>
          </div>
          <div class="sp-meta">
            ${p.entity?`<span><i class="fa-solid fa-building" style="color:#7c3aed"></i> ${eh(p.entity)}</span>`:''}
            <span>Amt: ${fN(amt)}</span>
            <span style="color:#7c3aed">VAT: ${fN(vat)}</span>
            ${p.banking_date?`<span><i class="fa-regular fa-calendar"></i> ${eh(p.banking_date)}</span>`:''}
            ${p.bank_ref?`<span style="font-family:monospace;background:#f8fafc;border:1px solid #e2e8f0;border-radius:3px;padding:1px 5px">${eh(p.bank_ref)}</span>`:''}
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
    const checked=Array.from(document.querySelectorAll('.sp-cb:not([disabled]):checked'));
    const n=checked.length;
    $('btnSendAck').disabled=n===0;
    $('smAckBarInfo').textContent=n>0?`${n} payment(s) selected — ready to send`:'Select payments on the right → send to Ack';
}

function selAllPayments(v){
    document.querySelectorAll('.sp-cb:not([disabled])').forEach(cb=>cb.checked=v);
    spCbChange();
}

async function sendToAck(){
    const cbs=Array.from(document.querySelectorAll('.sp-cb:not([disabled]):checked'));
    const ids=cbs.map(cb=>cb.dataset.id).filter(Boolean);
    if(!ids.length){toast('Select at least one payment.','error');return;}
    const btn=$('btnSendAck');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Sending…';
    const fd=new FormData();
    fd.append('ids',JSON.stringify(ids));
    fd.append('pscl_id',_pid);
    try{
        const res=await fetch('promoters_salary_cl.php?action=send_to_ack',{method:'POST',body:fd});
        const d=await res.json();
        if(!d.success){toast('Error: '+(d.message||'Failed'),'error');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-envelope-open-text"></i> Send to Acknowledgment';return;}
        _ackSIDs=new Set((d.ack_ids||[]).map(Number));
        let msg=`✓ ${d.sent} payment(s) sent to Cheque Acknowledgment.`;
        if(d.skipped)msg+=` ${d.skipped} skipped.`;
        toast(msg,'info');
        const sr=await fetch('promoters_salary_cl.php?action=get_settlements&pscl_id='+_pid).then(r=>r.json());
        _ackSIDs=new Set((sr.ack_ids||[]).map(Number));
        renderPayments(sr.data||[]);
        updateStrip(sr.totals||{});
    }catch(e){toast('Network error: '+e.message,'error');}
    finally{btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-envelope-open-text"></i> Send to Acknowledgment';}
}

async function sendSingleToAck(sid){
    const fd=new FormData();
    fd.append('ids',JSON.stringify([sid]));
    fd.append('pscl_id',_pid);
    try{
        const res=await fetch('promoters_salary_cl.php?action=send_to_ack',{method:'POST',body:fd});
        const d=await res.json();
        if(!d.success){toast('Error: '+(d.message||'Failed'),'error');return;}
        _ackSIDs=new Set((d.ack_ids||[]).map(Number));
        toast('Payment sent to Cheque Acknowledgment.','info');
        const sr=await fetch('promoters_salary_cl.php?action=get_settlements&pscl_id='+_pid).then(r=>r.json());
        _ackSIDs=new Set((sr.ack_ids||[]).map(Number));
        renderPayments(sr.data||[]);
        updateStrip(sr.totals||{});
    }catch(e){toast('Network error: '+e.message,'error');}
}

async function reverseAck(sid,pid){
    if(!confirm('Reverse this acknowledgment? The payment will return to selectable status.'))return;
    const fd=new FormData();
    fd.append('settlement_id',sid);
    fd.append('pscl_id',pid);
    try{
        const res=await fetch('promoters_salary_cl.php?action=reverse_ack',{method:'POST',body:fd});
        const d=await res.json();
        if(!d.success){toast('Reverse failed.','error');return;}
        _ackSIDs=new Set((d.ack_ids||[]).map(Number));
        toast('Acknowledgment reversed. Payment returned to list.','warn');
        const sr=await fetch('promoters_salary_cl.php?action=get_settlements&pscl_id='+pid).then(r=>r.json());
        _ackSIDs=new Set((sr.ack_ids||[]).map(Number));
        renderPayments(sr.data||[]);
        updateStrip(sr.totals||{});
    }catch(e){toast('Network error.','error');}
}

async function delPayment(sid,pid){
    if(!confirm('Delete this payment? This will also remove it from Acknowledgment if sent.'))return;
    const fd=new FormData();
    fd.append('settlement_id',sid);
    fd.append('pscl_id',pid);
    try{
        const res=await fetch('promoters_salary_cl.php?action=delete_settlement',{method:'POST',body:fd});
        const d=await res.json();
        if(d.success){
            _ackSIDs.delete(parseInt(sid));
            document.getElementById('sp-'+sid)?.remove();
            const remaining=document.querySelectorAll('[id^="sp-"]');
            if(!remaining.length){
                $('spList').innerHTML='<div class="sp-empty"><i class="fa-solid fa-receipt" style="font-size:22px;display:block;margin-bottom:8px;color:#d1d5db"></i>No payments recorded yet.</div>';
                $('smAckBar').classList.remove('visible');
            }
            updateStrip(d.totals||{});
            updateTableRow(pid,d.totals||{});
            toast('Payment deleted.','success');
            const cr=await fetch('promoters_salary_cl.php?action=get_claim_matches&pscl_id='+pid).then(r=>r.json());
            _settledCIDs=new Set((cr.settled_ids||[]).map(Number));
            _ackCIDs=new Set((cr.ack_claim_ids||[]).map(Number));
            renderClaims(cr.claims||[]);
        }else toast('Delete failed.','error');
    }catch(e){toast('Network error.','error');}
}
function updateStrip(t){
    const inv=parseFloat($('sm-inv').value||0);
    const sAmt=parseFloat(t.settled_amount||0),sVat=parseFloat(t.settled_vat||0),sTot=parseFloat(t.settled_total||0);
    const bal=sTot-inv;
    $('ss-inv').textContent=fN(inv);
    $('ss-amt').textContent=fN(sAmt);
    $('ss-vat').textContent=fN(sVat);
    $('ss-tot').textContent=fN(sTot);
    $('ss-bal').textContent=(bal>0?'+':'')+fN(bal);
    $('ss-bal').className='sm-val '+(bal>0?'green':(bal<0?'red':'green'));
    $('ss-cnt').textContent=document.querySelectorAll('[id^="sp-"]').length;
}

function updateTableRow(pid,t){
    const sAmt=parseFloat(t.settled_amount||0),sVat=parseFloat(t.settled_vat||0),sTot=parseFloat(t.settled_total||0);
    const tr=document.getElementById('tr-'+pid);
    const inv=tr?parseFloat(tr.dataset.awv||0):0;
    const bal=sTot-inv;
    const balCl=bal>0?'bal-pos':(bal<0?'bal-neg':'bal-zero');
    const balSign=bal>0?'+':'';
    const s=(id,v)=>{const el=document.getElementById(id);if(el)el.innerHTML=v;};
    s('td-sAmt-'+pid,sAmt>0?`<span style="color:#16a34a">${fN(sAmt)}</span>`:'—');
    s('td-sVat-'+pid,sVat>0?`<span style="color:#7c3aed">${fN(sVat)}</span>`:'—');
    s('td-sTot-'+pid,sTot>0?`<span style="color:#16a34a;font-weight:700">${fN(sTot)}</span>`:'—');
    s('td-bal-'+pid,`<span class="${balCl}">${balSign}${fN(bal)}</span>`);
    const btn=document.getElementById('settlebtn-'+pid);
    if(btn){
        btn.className='btn btn-settle btn-xs'+(sTot>0?' settled':'');
        btn.innerHTML=sTot>0?'<i class="fa-solid fa-check-circle"></i> Settled':'<i class="fa-solid fa-hand-holding-dollar"></i> Settle';
    }
}
</script>

<?php include 'footer.php'; ?>