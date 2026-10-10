<?php
/* BRANCH_CODE_PATCH_V1_APPLIED */
/* PATCHED_V2 */
/**
 * cheques.php — Master Cheque Register
 */

/* ══════════════════════════════════════════════════════
   AJAX — MUST be before include 'header.php'
══════════════════════════════════════════════════════ */

if (session_status() === PHP_SESSION_NONE) session_start();
function get_current_user_label() {
    return $_SESSION['username']   ??
           $_SESSION['user_name']  ??
           $_SESSION['name']       ??
           $_SESSION['full_name']  ??
           $_SESSION['email']      ??
           (isset($_SESSION['user_id']) ? 'User #'.$_SESSION['user_id'] : 'system');
}

/* ── AJAX: verify cheque ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'verify_cheque') {
    include 'config.php';
    header('Content-Type: application/json');
    $cid = intval($_POST['cheque_id'] ?? 0);
    if (!$cid) { echo json_encode(['success'=>false,'error'=>'Invalid ID']); exit; }

    $upload_dir = 'uploads/cheques/';
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
    $front_path = ''; $back_path = '';
    if (!empty($_FILES['cheque_front']['name'])) {
        $ext = pathinfo($_FILES['cheque_front']['name'], PATHINFO_EXTENSION);
        $fn  = $upload_dir . 'front_'.$cid.'_'.time().'.'.$ext;
        if (move_uploaded_file($_FILES['cheque_front']['tmp_name'], $fn)) $front_path = $fn;
    }
    if (!empty($_FILES['cheque_back']['name'])) {
        $ext = pathinfo($_FILES['cheque_back']['name'], PATHINFO_EXTENSION);
        $fn  = $upload_dir . 'back_'.$cid.'_'.time().'.'.$ext;
        if (move_uploaded_file($_FILES['cheque_back']['tmp_name'], $fn)) $back_path = $fn;
    }

    $sets = ['verified = 1'];
    if ($front_path) $sets[] = "cheque_front_image = '".mysqli_real_escape_string($conn,$front_path)."'";
    if ($back_path)  $sets[] = "cheque_back_image = '".mysqli_real_escape_string($conn,$back_path)."'";
    $sql = "UPDATE cheques SET ".implode(', ',$sets)." WHERE id=$cid";
    if (mysqli_query($conn, $sql)) {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
            id INT AUTO_INCREMENT PRIMARY KEY, cheque_id INT NOT NULL,
            action VARCHAR(100) NOT NULL, old_value TEXT, new_value TEXT, note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(100) DEFAULT 'system',
            INDEX (cheque_id), INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cu = mysqli_real_escape_string($conn, get_current_user_label());
        mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id, action, old_value, new_value, note, created_by)
            VALUES ($cid, 'verified', '0', '1', 'Cheque verified with image upload', '$cu')");
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]);
    }
    exit;
}

/* ── AJAX: mark selected cheques as to_be_bank ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'mark_to_be_bank') {
    include_once 'config.php';
    header('Content-Type: application/json');
    $raw_ids = $_POST['ids'] ?? '[]';
    $decoded = json_decode($raw_ids, true);
    if (!is_array($decoded)) $decoded = [];
    $ids  = array_filter(array_map('intval', $decoded));
    $date = trim($_POST['to_be_bank_date'] ?? '');
    if (empty($ids)) { echo json_encode(['success'=>false,'error'=>'No cheques selected']); exit; }
    if (!$date)      { echo json_encode(['success'=>false,'error'=>'Date is required']); exit; }
    $date_esc = mysqli_real_escape_string($conn, $date);
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
        id INT AUTO_INCREMENT PRIMARY KEY, cheque_id INT NOT NULL,
        action VARCHAR(100) NOT NULL, old_value TEXT, new_value TEXT, note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_cid (cheque_id), INDEX idx_cat (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $updated = 0; $skipped = 0; $skip_names = [];
    foreach ($ids as $cid) {
        $chkr = mysqli_query($conn, "SELECT id, cheque_no, status, verified FROM cheques WHERE id=$cid LIMIT 1");
        $chk  = $chkr ? mysqli_fetch_assoc($chkr) : null;
        if (!$chk) { $skipped++; continue; }
        if (!intval($chk['verified'])) { $skipped++; $skip_names[] = $chk['cheque_no']; continue; }
        $old_st = mysqli_real_escape_string($conn, $chk['status']);
        $cu = mysqli_real_escape_string($conn, get_current_user_label());
        if (mysqli_query($conn, "UPDATE cheques SET status='to_be_bank' WHERE id=$cid")) {
            @mysqli_query($conn, "UPDATE cheques SET to_be_bank_date='$date_esc' WHERE id=$cid");
            mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id, action, old_value, new_value, note, created_by)
                VALUES ($cid, 'status_change', '$old_st', 'to_be_bank', 'Marked To Be Bank on $date_esc', '$cu')");
            $updated++;
        }
    }
    echo json_encode(['success'=>true, 'updated'=>$updated, 'skipped'=>$skipped, 'skipped_nos'=>$skip_names]);
    exit;
}

/* ── AJAX: deposit cheques ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'deposit_cheques') {
    include_once 'config.php';
    header('Content-Type: application/json');
    $raw_ids     = $_POST['ids'] ?? '[]';
    $decoded     = json_decode($raw_ids, true);
    if (!is_array($decoded)) $decoded = [];
    $ids         = array_filter(array_map('intval', $decoded));
    $deposit_date= trim($_POST['deposit_date'] ?? '');
    $account_id  = intval($_POST['company_account_id'] ?? 0);
    $dep_type    = trim($_POST['deposit_type'] ?? 'normal');
    $dep_type    = in_array($dep_type, ['normal','bulk','normal_bulk']) ? $dep_type : 'normal';
    if (empty($ids))       { echo json_encode(['success'=>false,'error'=>'No cheques selected']); exit; }
    if (!$deposit_date)    { echo json_encode(['success'=>false,'error'=>'Deposit date is required']); exit; }
    if (!$account_id)      { echo json_encode(['success'=>false,'error'=>'Please select a company bank account']); exit; }
    $date_esc = mysqli_real_escape_string($conn, $deposit_date);
    $type_esc = mysqli_real_escape_string($conn, $dep_type);
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
        id INT AUTO_INCREMENT PRIMARY KEY, cheque_id INT NOT NULL,
        action VARCHAR(100) NOT NULL, old_value TEXT, new_value TEXT, note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_cid (cheque_id), INDEX idx_cat (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $updated = 0; $skipped = 0; $skip_nos = [];
    foreach ($ids as $cid) {
        $chkr = mysqli_query($conn, "SELECT id, cheque_no, status FROM cheques WHERE id=$cid LIMIT 1");
        $chk  = $chkr ? mysqli_fetch_assoc($chkr) : null;
        if (!$chk) { $skipped++; continue; }
        if (strtolower(trim($chk['status'])) !== 'to_be_bank') { $skipped++; $skip_nos[] = $chk['cheque_no']; continue; }
        $old_st = mysqli_real_escape_string($conn, $chk['status']);
        $upd = mysqli_query($conn, "UPDATE cheques SET status='deposited', deposit_date='$date_esc', deposited_account_id=$account_id, deposit_type='$type_esc' WHERE id=$cid");
        if ($upd) {
            $cu = mysqli_real_escape_string($conn, get_current_user_label());
            mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id, action, old_value, new_value, note, created_by)
                VALUES ($cid, 'deposited', '$old_st', 'deposited', 'Deposited on $date_esc | Account ID: $account_id | Type: $type_esc', '$cu')");
            $updated++;
        }
    }
    echo json_encode(['success'=>true,'updated'=>$updated,'skipped'=>$skipped,'skipped_nos'=>$skip_nos]);
    exit;
}

/* ── AJAX: get company bank accounts for deposit modal ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'company_bank_accounts') {
    include_once 'config.php';
    header('Content-Type: application/json');
    $rows = [];
    $r = mysqli_query($conn,
        "SELECT cba.id, cba.account_name, cba.account_no, cba.account_type,
                cba.bank_code, cba.branch_code,
                COALESCE(NULLIF(b.bank_name,''), cba.bank_code, '') AS bank_name,
                COALESCE(NULLIF(bb.branch_name,''), cba.branch_code, '') AS branch_name,
                COALESCE(c.company_name, '') AS company_name
         FROM company_bank_accounts cba
         LEFT JOIN banks b ON b.bank_code = cba.bank_code
         LEFT JOIN bank_branches bb ON bb.bank_code = cba.bank_code AND bb.branch_code = cba.branch_code
         LEFT JOIN companies c ON c.id = cba.company_id
         WHERE cba.active = 1 ORDER BY cba.account_name ASC");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    echo json_encode(['success'=>true,'accounts'=>$rows]);
    exit;
}

/* ── AJAX: get cheque logs ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'cheque_logs') {
    include_once 'config.php';
    header('Content-Type: application/json');
    $cid = intval($_GET['cheque_id'] ?? 0);
    if (!$cid) { echo json_encode(['success'=>false,'logs'=>[]]); exit; }
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
        id INT AUTO_INCREMENT PRIMARY KEY, cheque_id INT NOT NULL,
        action VARCHAR(100) NOT NULL, old_value TEXT, new_value TEXT, note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_cid (cheque_id), INDEX idx_cat (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $dep_info = [];
    $dr = mysqli_query($conn,
        "SELECT ch.deposit_date, ch.deposit_type, ch.deposited_account_id, ch.to_be_bank_date, ch.sent_back_reason,
                cba.account_name, cba.account_no,
                COALESCE(NULLIF(b.bank_name,''), cba.bank_code, '') AS bank_name,
                COALESCE(NULLIF(bb.branch_name,''), cba.branch_code, '') AS branch_name,
                COALESCE(c.company_name,'') AS company_name
         FROM cheques ch
         LEFT JOIN company_bank_accounts cba ON cba.id = ch.deposited_account_id
         LEFT JOIN banks b ON b.bank_code = cba.bank_code
         LEFT JOIN bank_branches bb ON bb.bank_code = cba.bank_code AND bb.branch_code = cba.branch_code
         LEFT JOIN companies c ON c.id = cba.company_id
         WHERE ch.id = $cid LIMIT 1");
    if ($dr) $dep_info = mysqli_fetch_assoc($dr) ?: [];
$logs = [];
    $lr = mysqli_query($conn, "SELECT * FROM cheque_logs WHERE cheque_id=$cid ORDER BY created_at DESC");
    if ($lr) while ($l = mysqli_fetch_assoc($lr)) $logs[] = $l;

    /* ── Settlement payments for RETURNED cheques ── */
    $settle_payments = [];
    $sp_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'cheque_settlement_payments'");
    if ($sp_tbl && mysqli_num_rows($sp_tbl) > 0) {
        $sp_r = mysqli_query($conn,
            "SELECT *, 'return' AS settle_source
             FROM cheque_settlement_payments
             WHERE cheque_id = $cid
             ORDER BY created_at ASC");
        if ($sp_r) while ($sp = mysqli_fetch_assoc($sp_r)) $settle_payments[] = $sp;
    }

    /* ── Settlement payments for SENT BACK cheques ── */
    $sb_settle_payments = [];
    $sb_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'cheque_sb_settlement_payments'");
    if ($sb_tbl && mysqli_num_rows($sb_tbl) > 0) {
        $sb_r = mysqli_query($conn,
            "SELECT *, 'sentback' AS settle_source
             FROM cheque_sb_settlement_payments
             WHERE cheque_id = $cid
             ORDER BY created_at ASC");
        if ($sb_r) while ($sb = mysqli_fetch_assoc($sb_r)) $sb_settle_payments[] = $sb;
    }

    /* ── Return cheque CRN / charge info ── */
    $return_info = [];
    $ri_r = mysqli_query($conn,
        "SELECT crn_no, crn_return_reason, crn_return_code, crn_cheque_status,
                crn_return_remark, crn_collecting_bank, crn_collecting_branch,
                crn_date_of_return, crn_uploaded_by, crn_uploaded_at,
                is_representable, return_settled,
                COALESCE(settlement_amount, 0) AS settlement_amount,
                settlement_date, settlement_note, status
         FROM cheques WHERE id = $cid LIMIT 1");
    if ($ri_r) $return_info = mysqli_fetch_assoc($ri_r) ?: [];

    echo json_encode([
        'success'          => true,
        'logs'             => $logs,
        'deposit_info'     => $dep_info,
        'settle_payments'  => $settle_payments,
        'sb_payments'      => $sb_settle_payments,
        'return_info'      => $return_info,
    ]);
    exit;
}

/* ── AJAX: send back cheque ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'send_back_cheque') {
    include_once 'config.php';
    header('Content-Type: application/json');
    $cid    = intval($_POST['cheque_id'] ?? 0);
    $reason = mysqli_real_escape_string($conn, trim($_POST['reason'] ?? ''));
    if (!$cid) { echo json_encode(['success'=>false,'error'=>'Invalid ID']); exit; }
    $old_r  = mysqli_query($conn, "SELECT status FROM cheques WHERE id=$cid LIMIT 1");
    $old_st = ($old_r && $old_row = mysqli_fetch_assoc($old_r)) ? mysqli_real_escape_string($conn,$old_row['status']) : 'pending';
    $sql = "UPDATE cheques SET status='sent_back', sent_back_reason='$reason' WHERE id=$cid";
    if (mysqli_query($conn, $sql)) {
        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
            id INT AUTO_INCREMENT PRIMARY KEY, cheque_id INT NOT NULL,
            action VARCHAR(100) NOT NULL, old_value TEXT, new_value TEXT, note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(100) DEFAULT 'system',
            INDEX idx_cid (cheque_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cu = mysqli_real_escape_string($conn, get_current_user_label());
        mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id, action, old_value, new_value, note, created_by)
            VALUES ($cid, 'sent_back', '$old_st', 'sent_back', '$reason', '$cu')");
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]);
    }
    exit;
}

/* ── AJAX: individual status update (with optional reason for deposited→to_be_bank) ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_status_individual') {
    include_once 'config.php';
    header('Content-Type: application/json');
    $cid        = intval($_POST['cheque_id'] ?? 0);
    $new_status = strtolower(trim($_POST['new_status'] ?? ''));
    $reason     = trim($_POST['reason'] ?? '');
    
    $action_date = trim($_POST['action_date'] ?? '');
    if (!$action_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $action_date)) {
        echo json_encode(['success'=>false,'error'=>'Action date is required']); exit;
    }
    $action_date_esc = mysqli_real_escape_string($conn, $action_date);
    if (!$cid) { echo json_encode(['success'=>false,'error'=>'Invalid cheque ID']); exit; }
    $valid_statuses = ['pending','to_be_bank','deposited','sent_back','cleared','returned'];
    if (!in_array($new_status, $valid_statuses)) { echo json_encode(['success'=>false,'error'=>'Invalid status']); exit; }
    $cur_r = mysqli_query($conn, "SELECT status FROM cheques WHERE id=$cid LIMIT 1");
    $cur_row = $cur_r ? mysqli_fetch_assoc($cur_r) : null;
    if (!$cur_row) { echo json_encode(['success'=>false,'error'=>'Cheque not found']); exit; }
    $old_status = strtolower(trim($cur_row['status']));
    if ($old_status === 'deposited' && $new_status === 'to_be_bank' && !$reason) {
        echo json_encode(['success'=>false,'error'=>'Reason is required when reverting Deposited → To Be Bank']); exit;
    }
    if ($new_status === 'sent_back' && !$reason) {
        echo json_encode(['success'=>false,'error'=>'Reason is required when sending back a cheque']); exit;
    }
    $new_esc    = mysqli_real_escape_string($conn, $new_status);
    $old_esc    = mysqli_real_escape_string($conn, $old_status);
    $reason_esc = mysqli_real_escape_string($conn, $reason);
    if ($new_status === 'sent_back') {
        $sql = "UPDATE cheques SET status='$new_esc', sent_back_reason='$reason_esc' WHERE id=$cid";
    } else {
        $sql = "UPDATE cheques SET status='$new_esc' WHERE id=$cid";
    }
    if (mysqli_query($conn, $sql)) {
        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
            id INT AUTO_INCREMENT PRIMARY KEY, cheque_id INT NOT NULL,
            action VARCHAR(100) NOT NULL, old_value TEXT, new_value TEXT, note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(100) DEFAULT 'system',
            INDEX idx_cid (cheque_id), INDEX idx_cat (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cu = mysqli_real_escape_string($conn, get_current_user_label());
        if ($new_status === 'sent_back') {
            $action_type = 'sent_back';
        } elseif ($old_status === 'deposited' && $new_status === 'to_be_bank') {
            $action_type = 'revert_to_be_bank';
        } else {
            $action_type = 'status_change';
        }
        $note_text = $reason ? $reason_esc : "Status changed from $old_esc to $new_esc";
      mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id, action, old_value, new_value, note, created_by, created_at)
            VALUES ($cid, '$action_type', '$old_esc', '$new_esc', '$note_text', '$cu', '$action_date_esc')");
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]);
    }
    exit;
}

/* ── AJAX: get customer images for verify modal ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'customer_images') {
    include 'config.php';
    header('Content-Type: application/json');
    $tcode = trim(mysqli_real_escape_string($conn, $_GET['t_code'] ?? ''));
    $result = ['success'=>true,'seal'=>'','signature'=>''];
    if ($tcode) {
        $r = mysqli_query($conn, "SELECT customer_seal, customer_signature FROM customers WHERE t_code='$tcode' LIMIT 1");
        if ($r && $row = mysqli_fetch_assoc($r)) {
            $result['seal']      = $row['customer_seal']      ?? '';
            $result['signature'] = $row['customer_signature'] ?? '';
        }
    }
    echo json_encode($result);
    exit;
}

/* ── AJAX: get send back reasons ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'sendback_reasons') {
    include_once 'config.php';
    header('Content-Type: application/json');
    $rows = [];
    $r = mysqli_query($conn, "SELECT id, reason FROM send_back_cheque_reasons WHERE active = 1 ORDER BY reason ASC");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    echo json_encode(['success' => true, 'reasons' => $rows]);
    exit;
}

/* ── AJAX: cheque detail (invoices + customer bank accounts) ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'cheque_detail') {
    include 'config.php';
    header('Content-Type: application/json');
    $cid   = intval($_GET['cheque_id'] ?? 0);
    $tcode = trim(mysqli_real_escape_string($conn, $_GET['t_code'] ?? ''));
    $inv_rows = []; $bank_accs = []; $err = '';
    if ($cid) {
        $ch_r = mysqli_query($conn, "SELECT cheque_no, sampath, sampath_payment_id FROM cheques WHERE id=$cid LIMIT 1");
        $ch   = $ch_r ? mysqli_fetch_assoc($ch_r) : null;
        if ($ch) {
            $is_sampath = !empty($ch['sampath']) && !empty($ch['sampath_payment_id']);
            if ($is_sampath) {
                /* Sampath Super Payment cheques settle invoices recorded in
                   sampath_super_payment_invoices, not invoice_payment_cheques. */
                $spid = (int)$ch['sampath_payment_id'];
                $lr = mysqli_query($conn,
                    "SELECT bill_no AS invoice_num, amount, '' AS t_code, party_name AS customer_name
                     FROM sampath_super_payment_invoices
                     WHERE payment_id=$spid ORDER BY id ASC");
                if (!$lr) $err = mysqli_error($conn);
                else while ($r = mysqli_fetch_assoc($lr)) $inv_rows[] = $r;
            } else {
                $cno = mysqli_real_escape_string($conn, $ch['cheque_no']);
                $lr  = mysqli_query($conn,
                    "SELECT ipc.invoice_num, ipc.amount, ipc.t_code,
                            COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ipc.t_code) AS customer_name
                     FROM invoice_payment_cheques ipc
                     LEFT JOIN invoice_payments ip ON ip.id = ipc.invoice_payment_id
                     LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
                     LEFT JOIN customers c ON c.t_code = ipc.t_code
                     WHERE ipc.cheque_no='$cno' AND ipc.is_reversed=0 ORDER BY ipc.id ASC");
                if (!$lr) $err = mysqli_error($conn);
                else while ($r = mysqli_fetch_assoc($lr)) $inv_rows[] = $r;
            }
        }
    }
    if ($tcode) {
        $cr = mysqli_query($conn, "SELECT id FROM customers WHERE t_code='$tcode' LIMIT 1");
        $cu = $cr ? mysqli_fetch_assoc($cr) : null;
        if ($cu) {
            $cid2 = intval($cu['id']);
            $bar  = mysqli_query($conn,
                "SELECT cba.account_holder_name, cba.account_number, cba.bank_code, cba.branch_code,
                        COALESCE(NULLIF(b.bank_name,''),'') AS bank_name,
                        COALESCE(NULLIF(cba.branch,''), NULLIF(bb.branch_name,''), '') AS branch_name
                 FROM customer_bank_accounts cba
                 LEFT JOIN banks b ON b.bank_code = cba.bank_code
                 LEFT JOIN bank_branches bb ON bb.bank_code = cba.bank_code AND bb.branch_code = cba.branch_code
                 WHERE cba.customer_id=$cid2 ORDER BY cba.id ASC");
            if ($bar) while ($ba = mysqli_fetch_assoc($bar)) $bank_accs[] = $ba;
        }
    }
    echo json_encode(['success'=>true,'invoices'=>$inv_rows,'bank_accounts'=>$bank_accs,'error'=>$err]);
    exit;
}

/* ── AJAX: save daily cheque report ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_daily_report') {
    include_once 'config.php';
    ob_start(); ob_end_clean();
    header('Content-Type: application/json');

    $sent_date = trim($_POST['sent_date'] ?? '');
    $remark    = trim($_POST['remark'] ?? '');
    if (!$sent_date) { echo json_encode(['success'=>false,'error'=>'Sent date is required']); exit; }

    /* Create tables if not exist */
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS daily_cheque_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_date DATE NOT NULL,
        remark TEXT,
        total_cheques INT DEFAULT 0,
        total_amount DECIMAL(15,2) DEFAULT 0,
        filter_snapshot TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_date (report_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS daily_cheque_report_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        cheque_id INT NOT NULL,
        cheque_no VARCHAR(100),
        cheque_date DATE,
        t_code VARCHAR(50),
        customer_name VARCHAR(255),
        bank_code VARCHAR(50),
        bank_name VARCHAR(255),
        branch_code VARCHAR(50),
        branch_name VARCHAR(255),
        total_amount DECIMAL(15,2) DEFAULT 0,
        status VARCHAR(50),
        sr_code VARCHAR(50),
        delivery_date DATE,
        cheque_mode VARCHAR(50),
        INDEX idx_rid (report_id),
        INDEX idx_cid (cheque_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* Re-run the same filter query to get matching cheque IDs */
    $f_sr      = trim($_POST['f_sr'] ?? '');
    $f_status  = trim($_POST['f_status'] ?? '');
    $f_bank    = trim($_POST['f_bank'] ?? '');
    $f_tcode   = trim($_POST['f_tcode'] ?? '');
    $f_from    = trim($_POST['f_from'] ?? '');
    $f_to      = trim($_POST['f_to'] ?? '');
    $f_del_from = trim($_POST['f_del_from'] ?? '');
    $f_del_to   = trim($_POST['f_del_to'] ?? '');
    $f_rec_from = trim($_POST['f_rec_from'] ?? '');
    $f_rec_to   = trim($_POST['f_rec_to'] ?? '');
    $search     = trim($_POST['f_search'] ?? '');
    $f_sampath_only = trim($_POST['f_sampath_only'] ?? '') === '1';

    $where = ["1=1"];
    if ($f_sr)     $where[] = "fs.sr_code='".mysqli_real_escape_string($conn,$f_sr)."'";
    if ($f_status) {
        $st_arr2 = array_filter(array_map('trim', explode(',', $f_status)));
        if (count($st_arr2) === 1) {
            $where[] = "ch.status='".mysqli_real_escape_string($conn,$st_arr2[0])."'";
        } elseif (count($st_arr2) > 1) {
            $in2 = implode(',', array_map(fn($sv)=>"'".mysqli_real_escape_string($conn,$sv)."'", $st_arr2));
            $where[] = "ch.status IN ($in2)";
        }
    }
    if ($f_bank)   $where[] = "ch.bank_code='".mysqli_real_escape_string($conn,$f_bank)."'";
    if ($f_tcode)  $where[] = "ch.t_code LIKE '%".mysqli_real_escape_string($conn,$f_tcode)."%'";
    if ($f_from)   $where[] = "ch.cheque_date>='".mysqli_real_escape_string($conn,$f_from)."'";
    if ($f_to)     $where[] = "ch.cheque_date<='".mysqli_real_escape_string($conn,$f_to)."'";
    if ($f_del_from) $where[] = "fs.delivery_date>='".mysqli_real_escape_string($conn,$f_del_from)."'";
    if ($f_del_to)   $where[] = "fs.delivery_date<='".mysqli_real_escape_string($conn,$f_del_to)."'";
    if ($f_rec_from) $where[] = "COALESCE(ch.received_date, ip.payment_date)>='".mysqli_real_escape_string($conn,$f_rec_from)."'";
    if ($f_rec_to)   $where[] = "COALESCE(ch.received_date, ip.payment_date)<='".mysqli_real_escape_string($conn,$f_rec_to)."'";
    if ($f_sampath_only) $where[] = "ch.sampath IS NOT NULL AND ch.sampath != ''";
    if ($search !== '') {
        $s = '%'.mysqli_real_escape_string($conn, $search).'%';
        $where[] = "(ch.cheque_no LIKE '$s' OR ch.t_code LIKE '$s' OR ch.bank_code LIKE '$s' OR ch.bank_name LIKE '$s'
            OR ch.branch_name LIKE '$s' OR ch.branch_code LIKE '$s' OR ch.status LIKE '$s'
            OR fs.sr_code LIKE '$s' OR COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) LIKE '$s'
            OR CAST(ch.total_amount AS CHAR) LIKE '$s')";
    }
    $where_sql = implode(' AND ', $where);

    /* LEFT JOIN — Sampath Super Payment cheques have no invoice_payments row
       (see sampath_super_payment.php); an INNER JOIN here would silently
       drop them from this batch. */
    $data_sql = "SELECT ch.id, ch.cheque_no, ch.cheque_date, ch.total_amount,
                   ch.bank_code, ch.bank_name, ch.branch_code, ch.branch_name,
                   ch.status, ch.t_code, COALESCE(NULLIF(ch.cheque_mode,''),'') AS cheque_mode,
                   fs.sr_code, fs.delivery_date,
                   COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, NULLIF(ch.t_code,''),
                            CASE WHEN ch.sampath IS NOT NULL AND ch.sampath != ''
                                 THEN CONCAT('Sampath Payment #', COALESCE(ch.sampath_payment_id,0))
                                 ELSE NULL END) AS customer_name
            FROM cheques ch
            LEFT JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id
            LEFT JOIN field_summary fs ON fs.id = ip.field_summary_id
            LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
            LEFT JOIN customers c ON c.t_code = ch.t_code
            WHERE $where_sql
            ORDER BY ch.cheque_date ASC, ch.cheque_no ASC";

    $res = mysqli_query($conn, $data_sql);
    if (!$res) { echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]); exit; }

    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    $total_cheques = count($rows);
    if ($total_cheques === 0) { echo json_encode(['success'=>false,'error'=>'No cheques match the current filter. Nothing to save.']); exit; }

    $total_amount = array_sum(array_column($rows, 'total_amount'));
    $date_esc   = mysqli_real_escape_string($conn, $sent_date);
    $remark_esc = mysqli_real_escape_string($conn, $remark);
    $cu = mysqli_real_escape_string($conn, get_current_user_label());
    $filter_snap = json_encode(['sr'=>$f_sr,'status'=>$f_status,'bank'=>$f_bank,'tcode'=>$f_tcode,
        'from'=>$f_from,'to'=>$f_to,'del_from'=>$f_del_from,'del_to'=>$f_del_to,
        'rec_from'=>$f_rec_from,'rec_to'=>$f_rec_to,'search'=>$search,'sampath_only'=>$f_sampath_only?1:0]);
    $snap_esc = mysqli_real_escape_string($conn, $filter_snap);

    $ins = mysqli_query($conn, "INSERT INTO daily_cheque_reports (report_date, remark, total_cheques, total_amount, filter_snapshot, created_by)
        VALUES ('$date_esc', '$remark_esc', $total_cheques, $total_amount, '$snap_esc', '$cu')");
    if (!$ins) { echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]); exit; }
    $report_id = mysqli_insert_id($conn);

    $item_vals = [];
    foreach ($rows as $r) {
        $item_vals[] = "($report_id, ".intval($r['id']).", '".mysqli_real_escape_string($conn,$r['cheque_no']??'')."',
            ".($r['cheque_date']?"'".mysqli_real_escape_string($conn,$r['cheque_date'])."'":"NULL").",
            '".mysqli_real_escape_string($conn,$r['t_code']??'')."',
            '".mysqli_real_escape_string($conn,$r['customer_name']??'')."',
            '".mysqli_real_escape_string($conn,$r['bank_code']??'')."',
            '".mysqli_real_escape_string($conn,$r['bank_name']??'')."',
            '".mysqli_real_escape_string($conn,$r['branch_code']??'')."',
            '".mysqli_real_escape_string($conn,$r['branch_name']??'')."',
            ".floatval($r['total_amount']).",
            '".mysqli_real_escape_string($conn,$r['status']??'')."',
            '".mysqli_real_escape_string($conn,$r['sr_code']??'')."',
            ".($r['delivery_date']?"'".mysqli_real_escape_string($conn,$r['delivery_date'])."'":"NULL").",
            '".mysqli_real_escape_string($conn,$r['cheque_mode']??'')."')";
    }
    /* Insert in chunks */
    $chunks = array_chunk($item_vals, 50);
    foreach ($chunks as $chunk) {
        mysqli_query($conn, "INSERT INTO daily_cheque_report_items
            (report_id, cheque_id, cheque_no, cheque_date, t_code, customer_name, bank_code, bank_name, branch_code, branch_name, total_amount, status, sr_code, delivery_date, cheque_mode)
            VALUES ".implode(',', $chunk));
    }

    echo json_encode(['success'=>true,'report_id'=>$report_id,'total_cheques'=>$total_cheques,'total_amount'=>$total_amount]);
    exit;
}

/* ═══════════════════════════════════════════════════════════
   AJAX: SERVER-SIDE PAGED + SEARCHED CHEQUE DATA
═══════════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'cheque_rows') {
    include_once 'config.php';
    header('Content-Type: application/json');
    $page      = max(1, intval($_GET['page'] ?? 1));
    $per_page  = max(1, intval($_GET['per'] ?? 200));
    $search    = trim($_GET['q'] ?? '');
    $f_sr      = trim($_GET['sr_code'] ?? '');
    $f_status  = trim($_GET['status'] ?? '');
    $f_bank    = trim($_GET['bank_code'] ?? '');
    $f_tcode   = trim($_GET['t_code'] ?? '');
    $f_from    = trim($_GET['date_from'] ?? '');
    $f_to      = trim($_GET['date_to'] ?? '');
    $f_del_from  = trim($_GET['del_date_from'] ?? '');
    $f_del_to    = trim($_GET['del_date_to'] ?? '');
    $f_rec_from  = trim($_GET['rec_date_from'] ?? '');
    $f_rec_to    = trim($_GET['rec_date_to'] ?? '');
    $f_sampath_only = trim($_GET['sampath_only'] ?? '') === '1';
    $where = ["1=1"];
    if ($f_sr)     $where[] = "fs.sr_code='".mysqli_real_escape_string($conn,$f_sr)."'";
    if ($f_status) {
        $st_arr = array_filter(array_map('trim', explode(',', $f_status)));
        if (count($st_arr) === 1) {
            $where[] = "ch.status='".mysqli_real_escape_string($conn,$st_arr[0])."'";
        } elseif (count($st_arr) > 1) {
            $in = implode(',', array_map(fn($s2)=>"'".mysqli_real_escape_string($conn,$s2)."'", $st_arr));
            $where[] = "ch.status IN ($in)";
        }
    }
    if ($f_bank)   $where[] = "ch.bank_code='".mysqli_real_escape_string($conn,$f_bank)."'";
    if ($f_tcode)  $where[] = "ch.t_code LIKE '%".mysqli_real_escape_string($conn,$f_tcode)."%'";
    if ($f_from)   $where[] = "ch.cheque_date>='".mysqli_real_escape_string($conn,$f_from)."'";
    if ($f_to)     $where[] = "ch.cheque_date<='".mysqli_real_escape_string($conn,$f_to)."'";
    if ($f_del_from) $where[] = "fs.delivery_date>='".mysqli_real_escape_string($conn,$f_del_from)."'";
    if ($f_del_to)   $where[] = "fs.delivery_date<='".mysqli_real_escape_string($conn,$f_del_to)."'";
    if ($f_rec_from) $where[] = "COALESCE(ch.received_date, ip.payment_date)>='".mysqli_real_escape_string($conn,$f_rec_from)."'";
    if ($f_rec_to)   $where[] = "COALESCE(ch.received_date, ip.payment_date)<='".mysqli_real_escape_string($conn,$f_rec_to)."'";
    if ($f_sampath_only) $where[] = "ch.sampath IS NOT NULL AND ch.sampath != ''";
    if ($search !== '') {
        $s = '%'.mysqli_real_escape_string($conn, $search).'%';
        $where[] = "(ch.cheque_no LIKE '$s' OR ch.t_code LIKE '$s' OR ch.bank_code LIKE '$s' OR ch.bank_name LIKE '$s'
            OR ch.branch_name LIKE '$s' OR ch.branch_code LIKE '$s' OR ch.status LIKE '$s' OR ch.cheque_mode LIKE '$s'
            OR fs.sr_code LIKE '$s' OR COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) LIKE '$s'
            OR CAST(ch.total_amount AS CHAR) LIKE '$s' OR CAST(ch.amount AS CHAR) LIKE '$s'
            OR FORMAT(ch.total_amount,2) LIKE '$s' OR FORMAT(ch.amount,2) LIKE '$s'
            OR DATE_FORMAT(ch.cheque_date,'%d %b %Y') LIKE '$s' OR DATE_FORMAT(ch.cheque_date,'%Y-%m-%d') LIKE '$s'
            OR DATE_FORMAT(fs.delivery_date,'%d %b %Y') LIKE '$s' OR DATE_FORMAT(fs.delivery_date,'%Y-%m-%d') LIKE '$s'
            OR ch.sampath_description LIKE '$s')";
    }
    $where_sql = implode(' AND ', $where);
    /* LEFT JOIN (not INNER) — Sampath Super Payment cheques have no
       invoice_payments/field_summary row (invoice_payment_id is NULL for
       them, see sampath_super_payment.php), so an INNER JOIN here silently
       excluded them from this list entirely. */
    $base_sql = " FROM cheques ch
        LEFT JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id
        LEFT JOIN field_summary fs ON fs.id = ip.field_summary_id
        LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        LEFT JOIN customers c ON c.t_code = ch.t_code
        WHERE $where_sql";
    $cnt_r = mysqli_query($conn, "SELECT COUNT(*) AS cnt $base_sql");
    $total = $cnt_r ? (int)mysqli_fetch_assoc($cnt_r)['cnt'] : 0;
    $samp_cnt_r = mysqli_query($conn, "SELECT COUNT(*) AS cnt $base_sql AND ch.sampath IS NOT NULL AND ch.sampath != ''");
    $sampath_total = $samp_cnt_r ? (int)mysqli_fetch_assoc($samp_cnt_r)['cnt'] : 0;
    $offset = ($page - 1) * $per_page;
    $data_sql = "SELECT ch.id, ch.cheque_no, ch.cheque_date, ch.amount, ch.total_amount,
               ch.bank_code, ch.bank_name, ch.branch_code, ch.branch_name,
               ch.status, ch.t_code, COALESCE(NULLIF(ch.cheque_mode,''), '') AS cheque_mode, ch.bulk_flag,
               COALESCE(ch.received_date, ip.payment_date) AS received_date,
               fs.sr_code, fs.delivery_date,
               COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, NULLIF(ch.t_code,''),
                        CASE WHEN ch.sampath IS NOT NULL AND ch.sampath != ''
                             THEN CONCAT('Sampath Payment #', COALESCE(ch.sampath_payment_id,0))
                             ELSE NULL END) AS customer_name,
               ch.sampath, ch.sampath_payment_id, ch.sampath_description,
               COALESCE(ch.verified, 0) AS verified,
            ch.cheque_front_image, ch.cheque_back_image,
               COALESCE(ch.return_settled, 0) AS return_settled,
               COALESCE(ch.settlement_amount, 0) AS return_settlement_amount,
               COALESCE(ch.sb_settled, 0) AS sb_settled,
               COALESCE(ch.sb_settlement_amount, 0) AS sb_settlement_amount,
               (SELECT cba.account_holder_name FROM customer_bank_accounts cba
                WHERE cba.customer_id=c.id ORDER BY cba.id LIMIT 1) AS acc_holder_name
        $base_sql ORDER BY 
  CASE 
    WHEN ch.status='sent_back' AND COALESCE(ch.sb_settled,0)=1 THEN 2
    WHEN ch.status='returned'  AND COALESCE(ch.return_settled,0)=1 THEN 2
    ELSE 1
  END ASC,
  ch.cheque_date ASC, ch.cheque_no ASC 
LIMIT $per_page OFFSET $offset";
    $res = mysqli_query($conn, $data_sql);
    $rows = [];
    if ($res) while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    $amt_r  = mysqli_query($conn, "SELECT COALESCE(SUM(ch.total_amount),0) AS tot $base_sql");
    $g_amt  = $amt_r ? (float)mysqli_fetch_assoc($amt_r)['tot'] : 0;
    echo json_encode(['success'=>true,'rows'=>$rows,'total'=>$total,'sampath_total'=>$sampath_total,'grand_total'=>$g_amt,
        'page'=>$page,'per_page'=>$per_page,'pages'=>max(1,(int)ceil($total/$per_page))]);
    exit;
}






/* ── AJAX: update cheque details from verify modal ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_cheque_details') {
    include_once 'config.php';
    header('Content-Type: application/json');

    $cid         = intval($_POST['cheque_id']    ?? 0);
    $cheque_date = trim($_POST['cheque_date']    ?? '');
    $rec_date    = trim($_POST['received_date']  ?? '');
  $amount      = isset($_POST['amount']) ? round(abs(floatval($_POST['amount'])), 2) : 0;
$new_cno     = mysqli_real_escape_string($conn, trim($_POST['cheque_no']   ?? ''));
$bank_code   = mysqli_real_escape_string($conn, trim($_POST['bank_code']   ?? ''));
$bank_name   = mysqli_real_escape_string($conn, trim($_POST['bank_name']   ?? ''));
$branch_code = mysqli_real_escape_string($conn, trim($_POST['branch_code'] ?? ''));
$branch_name = mysqli_real_escape_string($conn, trim($_POST['branch_name'] ?? ''));
$cheque_mode = mysqli_real_escape_string($conn, trim($_POST['cheque_mode'] ?? ''));

if (!$cid)        { echo json_encode(['success'=>false,'error'=>'Invalid cheque ID']); exit; }
if (!$new_cno)    { echo json_encode(['success'=>false,'error'=>'Cheque number is required']); exit; }
    $cdate_v = ($cheque_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $cheque_date)) ? $cheque_date : null;
    $rdate_v = ($rec_date    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rec_date))    ? $rec_date    : null;
    $cdate_s = $cdate_v ? "'$cdate_v'" : 'NULL';
    $rdate_s = $rdate_v ? "'$rdate_v'" : 'NULL';

    $old_r = mysqli_query($conn, "SELECT * FROM cheques WHERE id=$cid LIMIT 1");
    if (!$old_r || !mysqli_num_rows($old_r)) {
        echo json_encode(['success'=>false,'error'=>'Cheque not found']); exit;
    }
    $old     = mysqli_fetch_assoc($old_r);
$old_amt  = round(floatval($old['amount']), 2);
$old_tot  = round(floatval($old['total_amount']), 2);
$amount   = $old_amt;   // always preserve — never recalculate from edit
$new_tot  = $old_tot;   // total_amount is never changed by detail edits
    $pid      = intval($old['invoice_payment_id']);
    $old_cno = mysqli_real_escape_string($conn, $old['cheque_no']   ?? '');
    $old_bk  = mysqli_real_escape_string($conn, $old['bank_code']   ?? '');
    $old_br  = mysqli_real_escape_string($conn, $old['branch_code'] ?? '');

    /* UPDATE cheques master — preserves status / verified / images */
if (!mysqli_query($conn, "UPDATE cheques SET
            cheque_no     = '$new_cno',
            cheque_date   = $cdate_s,
            received_date = $rdate_s,
            amount        = $amount,
            total_amount  = $new_tot,
            bank_code     = '$bank_code',
            bank_name     = '$bank_name',
            branch_code   = '$branch_code',
            branch_name   = '$branch_name',
            cheque_mode   = '$cheque_mode',
            updated_at    = NOW()
         WHERE id = $cid")) {
        echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]); exit;
    }

    /* Sync invoice_payment_cheques leaf using OLD identity */
  mysqli_query($conn, "UPDATE invoice_payment_cheques SET
            cheque_no    = '$new_cno',
            cheque_date  = $cdate_s,
            amount       = $amount,
            total_amount = $amount,
            bank_code    = '$bank_code',
            bank_name    = '$bank_name',
            branch_code  = '$branch_code',
            branch_name  = '$branch_name'
         WHERE invoice_payment_id = $pid
           AND cheque_no   = '$old_cno'
           AND bank_code   = '$old_bk'
           AND branch_code = '$old_br'");

    /* Sync invoice_payments.payment_date with new received_date */
    if ($rdate_v)
        mysqli_query($conn,
            "UPDATE invoice_payments SET payment_date='$rdate_v' WHERE id=$pid AND is_reversed=0");

    /* Log */
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
        id INT AUTO_INCREMENT PRIMARY KEY, cheque_id INT NOT NULL,
        action VARCHAR(100) NOT NULL, old_value TEXT, new_value TEXT, note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_cid (cheque_id), INDEX idx_cat (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $cu      = mysqli_real_escape_string($conn, get_current_user_label());
    $note    = mysqli_real_escape_string($conn,
        "date=$cheque_date|recv=$rec_date|amt=$amount|bank=$bank_code|branch=$branch_code|mode=$cheque_mode");
    $old_snp = mysqli_real_escape_string($conn, json_encode([
        'date'=>$old['cheque_date'],'recv'=>$old['received_date'],
        'amt'=>$old_amt,'bank'=>$old['bank_code'],
        'branch'=>$old['branch_code'],'mode'=>$old['cheque_mode']
    ]));
    $new_snp = mysqli_real_escape_string($conn, json_encode([
        'date'=>$cheque_date,'recv'=>$rec_date,'amt'=>$amount,
        'bank'=>$bank_code,'branch'=>$branch_code,'mode'=>$cheque_mode
    ]));
    mysqli_query($conn, "INSERT INTO cheque_logs
        (cheque_id, action, old_value, new_value, note, created_by)
        VALUES ($cid, 'details_updated', '$old_snp', '$new_snp', '$note', '$cu')");

   echo json_encode(['success'=>true,'new_total'=>$old_tot]);
    exit;
}














/* ══════════════════════════════════════════════════════
   NORMAL PAGE
══════════════════════════════════════════════════════ */
include 'config.php';
include 'header.php';

/* ── Ensure Sampath cheque columns exist (safe/idempotent — see
     sampath_super_payment.php, which is where these columns originate) ── */
foreach ([
    'sampath'             => "VARCHAR(50)  NULL",
    'sampath_description' => "TEXT         NULL",
    'sampath_payment_id'  => "INT          NULL",
] as $col => $def) {
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM cheques LIKE '$col'");
    if (!$chk || mysqli_num_rows($chk) === 0) {
        @mysqli_query($conn, "ALTER TABLE cheques ADD COLUMN $col $def");
    }
}

/* ── Banks for edit modal ── */
$edit_banks_list = [];
$edit_banks_q = mysqli_query($conn,
    "SELECT bank_code, bank_name FROM banks WHERE active=1 ORDER BY bank_name");
if ($edit_banks_q) while ($eb = mysqli_fetch_assoc($edit_banks_q)) $edit_banks_list[] = $eb;

$sr_res  = mysqli_query($conn,
    "SELECT DISTINCT fs.sr_code FROM cheques ch
     INNER JOIN invoice_payments ip ON ip.id=ch.invoice_payment_id
     INNER JOIN field_summary fs ON fs.id=ip.field_summary_id
     WHERE fs.sr_code IS NOT NULL AND fs.sr_code!='' ORDER BY fs.sr_code");
$all_sr  = [];
if ($sr_res) while ($r=mysqli_fetch_assoc($sr_res)) $all_sr[]=$r['sr_code'];

$bank_res = mysqli_query($conn,
    "SELECT DISTINCT bank_code FROM cheques WHERE bank_code IS NOT NULL AND bank_code!='' ORDER BY bank_code");
$all_banks = [];
if ($bank_res) while ($r=mysqli_fetch_assoc($bank_res)) $all_banks[]=$r['bank_code'];

$statuses = ['pending','to_be_bank','deposited','sent_back','cleared','returned'];
$status_labels = ['pending'=>'Pending','to_be_bank'=>'To Be Bank','deposited'=>'Deposited',
                  'sent_back'=>'Sent Back','cleared'=>'Cleared','returned'=>'Returned'];

$f_sr       = trim($_GET['sr_code'] ?? '');
$f_status   = trim($_GET['status'] ?? '');
$f_bank     = trim($_GET['bank_code'] ?? '');
$f_tcode    = trim($_GET['t_code'] ?? '');
$f_from     = trim($_GET['date_from'] ?? '');
$f_to       = trim($_GET['date_to'] ?? '');
$f_del_from  = trim($_GET['del_date_from'] ?? '');
$f_del_to    = trim($_GET['del_date_to'] ?? '');
$f_rec_from  = trim($_GET['rec_date_from'] ?? '');
$f_rec_to    = trim($_GET['rec_date_to'] ?? '');

$statuses_list = ['pending','to_be_bank','deposited','sent_back','cleared','returned'];
$gcnt_res = mysqli_query($conn, "SELECT COALESCE(status,'pending') AS s, COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS tot FROM cheques GROUP BY status");
$g_total_count = 0; $g_total_amount = 0;
$status_totals = array_fill_keys($statuses_list, ['count'=>0,'amount'=>0]);
if ($gcnt_res) while ($gcr = mysqli_fetch_assoc($gcnt_res)) {
    $s = strtolower(trim($gcr['s'])); if($s==='bounced') $s='returned';
    $g_total_count += intval($gcr['c']); $g_total_amount += floatval($gcr['tot']);
    if (isset($status_totals[$s])) { $status_totals[$s]['count'] += intval($gcr['c']); $status_totals[$s]['amount'] += floatval($gcr['tot']); }
}

/* ── Sampath cheques count for the filter/stat badge ── */
$samp_total_r = mysqli_query($conn, "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS amt FROM cheques WHERE sampath IS NOT NULL AND sampath != ''");
$samp_total_row = $samp_total_r ? mysqli_fetch_assoc($samp_total_r) : ['cnt'=>0,'amt'=>0];
$sampath_grand_count  = (int)($samp_total_row['cnt'] ?? 0);
$sampath_grand_amount = (float)($samp_total_row['amt'] ?? 0);
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box}
.page-title{font-size:22px;font-weight:800;color:#1e1b4b;margin:0 0 4px}
.page-subtitle{font-size:13px;color:#6b7280;margin:0}
.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:0;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.05);overflow:hidden;}
.filter-header{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;cursor:pointer;user-select:none;background:#fafafa;border-bottom:1px solid transparent;transition:border-color .2s;}
.filter-header.open{border-bottom-color:#e5e5e5;}
.filter-header:hover{background:#f3f4f6;}
.filter-title{font-size:13px;font-weight:700;color:#374151;display:flex;align-items:center;gap:6px;margin:0;}
.filter-toggle-icon{color:#6b7280;transition:transform .25s;font-size:12px;}
.filter-toggle-icon.open{transform:rotate(180deg);}
.filter-body{display:none;padding:16px 18px 18px;}
.filter-body.open{display:block;}
.filter-row{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;align-items:end}
.filter-row-2{display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1fr 1fr 1fr auto;gap:10px;align-items:end;margin-top:12px;}
.ffg{display:flex;flex-direction:column;gap:5px}
.ffg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em}
.ffg input,.ffg select{border:1px solid #e5e5e5;border-radius:7px;padding:8px 11px;font-size:13px;font-family:inherit;color:#1f2937;width:100%;transition:border .2s}
.ffg input:focus,.ffg select:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1)}
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s;white-space:nowrap}
.btn-primary{background:#6366f1;color:#fff}.btn-primary:hover{background:#4f46e5}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover{background:#e8e8e8}
.btn-success{background:#16a34a;color:#fff}.btn-success:hover{background:#15803d}
.btn-sm{padding:5px 12px;font-size:11px}
.stat-grid{display:grid;grid-template-columns:repeat(8,1fr);gap:10px;margin-bottom:20px}
.stat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
.stat-value{font-size:19px;font-weight:800;color:#1f2937;line-height:1}
.sv-violet{color:#7c3aed}.sv-amber{color:#d97706}.sv-sky{color:#0369a1}.sv-blue{color:#2563eb}.sv-green{color:#16a34a}.sv-red{color:#dc2626}
.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #f0f0f0;background:#fafafa;flex-wrap:wrap;gap:8px}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap}
.p-violet{background:#ede9fe;color:#5b21b6}.p-blue{background:#dbeafe;color:#1e40af}
.p-green{background:#dcfce7;color:#166534}.p-amber{background:#fef3c7;color:#92400e}
.p-red{background:#fee2e2;color:#991b1b}.p-gray{background:#f3f4f6;color:#374151}
.search-bar-wrap{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.chq-search-box{position:relative;display:flex;align-items:center;}
.chq-search-box input{border:1.5px solid #e0e7ff;border-radius:8px;padding:7px 12px 7px 34px;font-size:12.5px;font-family:inherit;color:#1f2937;width:290px;transition:all .2s;background:#fff;outline:none;}
.chq-search-box input:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1);width:340px}
.chq-search-box .si{position:absolute;left:10px;color:#9ca3af;font-size:12px;pointer-events:none;}
.chq-search-box .clr-btn{position:absolute;right:8px;color:#9ca3af;font-size:11px;background:none;border:none;cursor:pointer;padding:2px;display:none;}
.chq-search-box .clr-btn.show{display:block}
.search-count{font-size:11px;color:#6b7280;white-space:nowrap}
.pager{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.pager-btn{background:#fff;border:1.5px solid #e0e7ff;border-radius:6px;padding:4px 10px;font-size:11.5px;font-weight:600;color:#4f46e5;cursor:pointer;transition:all .15s;white-space:nowrap;font-family:inherit;}
.pager-btn:hover{background:#ede9fe;border-color:#a5b4fc}
.pager-btn.active{background:#6366f1;color:#fff;border-color:#6366f1}
.pager-btn:disabled{opacity:.4;cursor:not-allowed}
.pager-info{font-size:11px;color:#6b7280;white-space:nowrap}
.chq-select-cb{width:16px;height:16px;cursor:pointer;accent-color:#6366f1;}
.btn-mark-bank{background:linear-gradient(135deg,#0369a1,#0284c7);color:#fff;border:none;border-radius:7px;padding:7px 16px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:inherit;transition:all .2s;white-space:nowrap;}
.btn-mark-bank:hover{filter:brightness(1.1)}
.btn-mark-bank:disabled{opacity:.5;cursor:not-allowed}
.btn-deposit{background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;border:none;border-radius:7px;padding:7px 16px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:inherit;transition:all .2s;white-space:nowrap;}
.btn-deposit:hover{filter:brightness(1.1)}
.btn-deposit:disabled{opacity:.5;cursor:not-allowed}
.select-count-badge{background:rgba(255,255,255,.25);color:#fff;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700;}
.dep-count-badge{background:rgba(255,255,255,.25);color:#fff;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700;}
.btn-daily-report{background:linear-gradient(135deg,#7c3aed,#8b5cf6);color:#fff;border:none;border-radius:7px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:inherit;transition:all .2s;white-space:nowrap;}
.btn-daily-report:hover{filter:brightness(1.1)}
.btn-view-reports{background:linear-gradient(135deg,#6366f1,#818cf8);color:#fff;border:none;border-radius:7px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:inherit;transition:all .2s;white-space:nowrap;text-decoration:none;}
.btn-view-reports:hover{filter:brightness(1.1);color:#fff;}
/* Daily Report Modal */
#dailyReportModal{display:none;position:fixed;inset:0;z-index:999998;background:rgba(0,0,0,.65);align-items:center;justify-content:center;padding:20px;}
#dailyReportModal.open{display:flex;}
.drm-box{background:#fff;border-radius:14px;width:100%;max-width:480px;box-shadow:0 24px 70px rgba(0,0,0,.4);overflow:hidden;animation:vmodalIn .22s cubic-bezier(.16,1,.3,1);}
.drm-header{background:linear-gradient(135deg,#7c3aed,#6d28d9);padding:14px 20px;display:flex;align-items:center;justify-content:space-between;}
.drm-title{color:#fff;font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px;}
.drm-close{background:rgba(255,255,255,.15);border:none;border-radius:7px;color:#fff;width:30px;height:30px;cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;}
.drm-body{padding:20px;}
.drm-summary{background:#f5f3ff;border:1.5px solid #ddd6fe;border-radius:10px;padding:12px 16px;margin-bottom:18px;display:flex;gap:20px;flex-wrap:wrap;}
.drm-sum-item{display:flex;flex-direction:column;gap:2px;}
.drm-sum-lbl{font-size:10px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.05em;}
.drm-sum-val{font-size:15px;font-weight:800;color:#4c1d95;}
.drm-field{margin-bottom:14px;display:flex;flex-direction:column;gap:6px;}
.drm-field label{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.05em;}
.drm-field input,.drm-field textarea{border:1.5px solid #ddd6fe;border-radius:8px;padding:10px 12px;font-size:14px;font-family:inherit;color:#1f2937;outline:none;transition:border .2s;background:#faf5ff;}
.drm-field input:focus,.drm-field textarea:focus{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.12);background:#fff;}
.drm-field textarea{resize:vertical;min-height:70px;}
.drm-note{font-size:11px;color:#6b7280;margin-bottom:4px;line-height:1.5;}
.drm-foot{display:flex;gap:10px;padding:14px 20px;border-top:1px solid #f0f0f0;background:#faf5ff;}
.drm-btn-save{flex:1;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;border:none;border-radius:8px;padding:11px 20px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:6px;transition:filter .2s;}
.drm-btn-save:hover{filter:brightness(1.08)}
.drm-btn-save:disabled{opacity:.5;cursor:not-allowed}
.drm-btn-cancel{background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;border-radius:8px;padding:11px 18px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;}
.status-badge-clickable{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:20px;font-size:11px;font-weight:700;cursor:pointer;border:1.5px solid;transition:all .2s;white-space:nowrap;user-select:none;}
.status-badge-clickable:hover{filter:brightness(1.06);box-shadow:0 2px 8px rgba(0,0,0,.12);transform:translateY(-1px);}
.status-badge-clickable.pending{background:#fef3c7;color:#92400e;border-color:#fde68a;}
.status-badge-clickable.to_be_bank{background:#e0f2fe;color:#0369a1;border-color:#7dd3fc;}
.status-badge-clickable.deposited{background:#dbeafe;color:#1e40af;border-color:#bfdbfe;}
.status-badge-clickable.sent_back{background:#fdf4ff;color:#7e22ce;border-color:#d8b4fe;}
.status-badge-clickable.cleared{background:#dcfce7;color:#166534;border-color:#86efac;}
.status-badge-clickable.returned{background:#fee2e2;color:#991b1b;border-color:#fecaca;}
#statusUpdateModal{display:none;position:fixed;inset:0;z-index:999998;background:rgba(0,0,0,.65);align-items:center;justify-content:center;padding:20px;}
#statusUpdateModal.open{display:flex;}
.sum-box{background:#fff;border-radius:16px;width:100%;max-width:520px;box-shadow:0 28px 80px rgba(0,0,0,.4);overflow:hidden;animation:vmodalIn .22s cubic-bezier(.16,1,.3,1);}
.sum-header{background:linear-gradient(135deg,#1e1b4b,#312e81);padding:15px 20px;display:flex;align-items:center;justify-content:space-between;}
.sum-title{color:#fff;font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px;}
.sum-close{background:rgba(255,255,255,.15);border:none;border-radius:7px;color:#fff;width:30px;height:30px;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;}
.sum-body{padding:20px;}
.sum-info{background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 14px;margin-bottom:16px;display:grid;grid-template-columns:1fr 1fr;gap:6px;}
.sum-info-item{display:flex;flex-direction:column;gap:2px;}
.sum-info-lbl{font-size:10px;font-weight:700;color:#0369a1;text-transform:uppercase;letter-spacing:.05em;}
.sum-info-val{font-size:13px;font-weight:700;color:#0c4a6e;}
.sum-current-status{margin-bottom:16px;}
.sum-current-status-lbl{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;}
.sum-status-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px;}
.sum-status-option{border:2px solid #e5e5e5;border-radius:10px;padding:10px 12px;cursor:pointer;transition:all .18s;text-align:center;background:#fff;display:flex;flex-direction:column;align-items:center;gap:4px;}
.sum-status-option:hover{border-color:#6366f1;background:#f5f3ff;}
.sum-status-option.selected{border-color:#6366f1;background:#ede9fe;box-shadow:0 0 0 3px rgba(99,102,241,.12);}
.sum-status-option.current{border-color:#9ca3af;background:#f9fafb;opacity:.5;cursor:not-allowed;}
.sum-status-option .sum-st-icon{font-size:18px;}
.sum-status-option .sum-st-label{font-size:11px;font-weight:700;color:#374151;}
.sum-reason-section{display:none;margin-bottom:16px;padding:14px;background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;}
.sum-reason-section.open{display:block;}
.sum-reason-section label{font-size:11px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;display:block;}
#sum-reason-select2-wrap{width:100%;}
#sum-reason-select2-wrap .select2-container{width:100%!important;}
#sum-reason-select2-wrap .select2-container--default .select2-selection--single{height:42px!important;border:1.5px solid #fca5a5!important;border-radius:8px!important;background:#fff!important;display:flex!important;align-items:center!important;}
#sum-reason-select2-wrap .select2-container--default .select2-selection--single .select2-selection__rendered{line-height:42px!important;padding-left:12px!important;color:#991b1b!important;font-size:13px!important;font-weight:600!important;}
#sum-reason-select2-wrap .select2-container--default .select2-selection--single .select2-selection__placeholder{color:#f87171!important;font-weight:500!important;}
#sum-reason-select2-wrap .select2-container--default .select2-selection--single .select2-selection__arrow{height:42px!important;}
.sum-reason-dropdown.select2-dropdown{border:1.5px solid #fca5a5!important;border-radius:8px!important;box-shadow:0 8px 30px rgba(220,38,38,.15)!important;font-size:13px!important;z-index:9999999!important;}
.sum-reason-dropdown .select2-results__option--highlighted{background:#fee2e2!important;color:#991b1b!important;}
.sum-foot{display:flex;gap:10px;padding:14px 20px;border-top:1px solid #f0f0f0;background:#f8faff;}
.sum-btn-update{flex:1;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;border:none;border-radius:8px;padding:11px 20px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:6px;transition:filter .2s;}
.sum-btn-update:hover{filter:brightness(1.08)}
.sum-btn-update:disabled{opacity:.5;cursor:not-allowed}
.sum-btn-cancel{background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;border-radius:8px;padding:11px 18px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;}
#bankDateModal{display:none;position:fixed;inset:0;z-index:999998;background:rgba(0,0,0,.6);align-items:center;justify-content:center;}
#bankDateModal.open{display:flex;}
.bdm-box{background:#fff;border-radius:14px;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.35);overflow:hidden;animation:vmodalIn .2s ease;}
.bdm-header{background:linear-gradient(135deg,#0369a1,#0284c7);padding:14px 20px;display:flex;align-items:center;justify-content:space-between;}
.bdm-title{color:#fff;font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px;}
.bdm-close{background:rgba(255,255,255,.15);border:none;border-radius:7px;color:#fff;width:28px;height:28px;cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;}
.bdm-body{padding:22px;}
.bdm-label{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;display:block;}
.bdm-input{width:100%;border:1.5px solid #e0e7ff;border-radius:8px;padding:10px 12px;font-size:14px;font-family:inherit;color:#1f2937;outline:none;transition:border .2s;}
.bdm-input:focus{border-color:#0369a1;box-shadow:0 0 0 3px rgba(3,105,161,.1);}
.bdm-note{font-size:11px;color:#6b7280;margin-top:8px;}
.bdm-foot{display:flex;gap:10px;padding:14px 20px;border-top:1px solid #f0f0f0;background:#f8faff;}
.bdm-btn-confirm{flex:1;background:#0369a1;color:#fff;border:none;border-radius:8px;padding:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;}
.bdm-btn-cancel{background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;border-radius:8px;padding:10px 18px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;}
#depositModal{display:none;position:fixed;inset:0;z-index:999998;background:rgba(0,0,0,.65);overflow-y:auto;padding:30px 16px 40px;}
#depositModal.open{display:block;}
.depmodal-box{background:#fff;border-radius:16px;width:100%;max-width:820px;margin:0 auto;box-shadow:0 32px 100px rgba(0,0,0,.4);overflow:hidden;animation:vmodalIn .22s cubic-bezier(.16,1,.3,1);}
.depmodal-header{background:linear-gradient(135deg,#0d9488,#0f766e);padding:16px 22px;display:flex;align-items:center;justify-content:space-between;}
.depmodal-title{color:#fff;font-size:15px;font-weight:800;display:flex;align-items:center;gap:9px;}
.depmodal-close{background:rgba(255,255,255,.15);border:none;border-radius:8px;color:#fff;width:32px;height:32px;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;transition:background .2s;}
.depmodal-close:hover{background:rgba(255,255,255,.3)}
.depmodal-body{padding:22px;}
.depmodal-section{margin-bottom:22px;}
.depmodal-section-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#0d9488;margin-bottom:12px;display:flex;align-items:center;gap:7px;}
.dep-fields-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;}
.dep-field{display:flex;flex-direction:column;gap:6px;}
.dep-field label{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.05em;}
.dep-field input,.dep-field select{border:1.5px solid #ccfbf1;border-radius:8px;padding:10px 12px;font-size:14px;font-family:inherit;color:#1f2937;outline:none;transition:border .2s;background:#f0fdfa;}
.dep-field input:focus,.dep-field select:focus{border-color:#0d9488;box-shadow:0 0 0 3px rgba(13,148,136,.12);background:#fff;}
.dep-type-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px;}
.dep-type-card{border:2px solid #ccfbf1;border-radius:10px;padding:14px 16px;cursor:pointer;transition:all .18s;background:#f0fdfa;display:flex;align-items:flex-start;gap:10px;}
.dep-type-card:hover{border-color:#0d9488;background:#fff;}
.dep-type-card.selected{border-color:#0d9488;background:#fff;box-shadow:0 0 0 3px rgba(13,148,136,.12);}
.dep-type-label{font-size:13px;font-weight:700;color:#0f766e;}
.dep-type-desc{font-size:11px;color:#6b7280;margin-top:3px;}
.dep-type-icon{font-size:22px;margin-right:2px;flex-shrink:0;}
.dep-accounts-grid{display:flex;flex-direction:column;gap:8px;max-height:260px;overflow-y:auto;padding-right:4px;}
.dep-account-card{border:2px solid #e5e5e5;border-radius:10px;padding:12px 14px;cursor:pointer;transition:all .15s;background:#fff;display:flex;align-items:center;gap:12px;}
.dep-account-card:hover{border-color:#0d9488;background:#f0fdfa;}
.dep-account-card.selected{border-color:#0d9488;background:#f0fdfa;box-shadow:0 0 0 3px rgba(13,148,136,.1);}
.dep-account-radio{width:18px;height:18px;accent-color:#0d9488;flex-shrink:0;}
.dep-account-avatar{width:38px;height:38px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;}
.dep-account-info{flex:1;min-width:0;}
.dep-account-name{font-size:13px;font-weight:700;color:#0f766e;}
.dep-account-no{font-family:'Courier New',monospace;font-size:12px;font-weight:700;color:#0d9488;margin-top:2px;}
.dep-account-pills{display:flex;gap:5px;flex-wrap:wrap;margin-top:5px;}
.dep-account-pill{background:#f0fdfa;border:1px solid #99f6e4;border-radius:4px;padding:2px 7px;font-size:10px;font-weight:600;color:#0f766e;white-space:nowrap;}
.dep-account-type-badge{font-size:10px;font-weight:700;border-radius:5px;padding:2px 8px;white-space:nowrap;background:#e0f2fe;color:#0369a1;}
.dep-account-type-badge.current{background:#ede9fe;color:#5b21b6;}
.dep-loading{text-align:center;padding:30px;color:#6b7280;font-size:13px;}
.dep-empty{text-align:center;padding:20px;color:#9ca3af;font-size:12px;}
.depmodal-summary{background:linear-gradient(135deg,#f0fdfa,#ccfbf1);border:1.5px solid #99f6e4;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.dep-sum-item{display:flex;flex-direction:column;gap:2px;}
.dep-sum-lbl{font-size:10px;font-weight:700;color:#0d9488;text-transform:uppercase;letter-spacing:.05em;}
.dep-sum-val{font-size:14px;font-weight:800;color:#0f766e;}
.depmodal-foot{display:flex;gap:10px;padding:16px 22px;border-top:1px solid #ccfbf1;background:#f0fdfa;flex-wrap:wrap;}
.dep-btn-confirm{flex:1;background:linear-gradient(135deg,#0d9488,#0f766e);color:#fff;border:none;border-radius:8px;padding:12px 20px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:6px;transition:filter .2s;}
.dep-btn-confirm:hover{filter:brightness(1.08)}
.dep-btn-confirm:disabled{opacity:.5;cursor:not-allowed}
.dep-btn-cancel{background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;border-radius:8px;padding:12px 18px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;}

.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:11px 13px;box-shadow:0 1px 3px rgba(0,0,0,.04);cursor:pointer;transition:all .15s;position:relative;}
.stat-card:hover{border-color:#6366f1;box-shadow:0 2px 8px rgba(99,102,241,.15);}
.stat-card.active-filter{border-color:#6366f1;background:#f5f3ff;}
.stat-card-amt{font-size:13px;font-weight:700;color:#6b7280;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.stat-card-amt .cnt-big{font-size:15px;font-weight:800;color:#374151;}
.stat-card-amt .cnt-bal{font-size:13px;font-weight:800;}
.dt-outer{overflow-x:auto;max-height:70vh;overflow-y:auto}
.data-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1350px}
.data-table thead th{padding:9px 8px;text-align:left;font-weight:700;font-size:10.5px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);position:sticky;top:0;z-index:10;}
.data-table thead th:last-child{border-right:none}
.data-table thead th.tr{text-align:right}
.data-table thead th.tc{text-align:center}
.data-table tbody tr.main-row{border-bottom:1px solid #f0f2f5;transition:background .12s}
.data-table tbody tr.main-row:hover td{background:#f0f9ff!important}
.data-table tbody tr.main-row.sampath-row{background:#fffbeb;}
.data-table tbody tr.main-row.sampath-row td{background:#fffbeb;}
.data-table tbody tr.main-row.sampath-row:hover td{background:#fef3c7!important}
.data-table td{padding:7px 8px;color:#374151;vertical-align:middle;background:#fff}
.tr{text-align:right}.tc{text-align:center}
.data-table tfoot td{padding:10px 8px;font-weight:800;font-size:12px;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155;position:sticky;bottom:0}
.data-table tfoot td.tr{text-align:right}
.mono{font-family:'Courier New',monospace;font-weight:700;letter-spacing:.02em}
.sr-pill{background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700;white-space:nowrap}
.sampath-pill{background:#d97706;color:#fff;padding:2px 9px;border-radius:8px;font-size:10.5px;font-weight:800;white-space:nowrap;display:inline-flex;align-items:center;gap:4px;cursor:help;}
.date-txt{font-size:11.5px;color:#374151;white-space:nowrap}
.date-txt.empty{color:#d1d5db}
.cust-sub{font-size:10px;color:#6b7280;margin-top:2px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.amt-cell{font-weight:700;color:#1f2937;white-space:nowrap}
.acc-ro{font-size:11.5px;font-weight:600;color:#374151}
.acc-ro.none{color:#d1d5db;font-style:italic;font-weight:400}
.mode-badge{display:inline-block;padding:2px 7px;border-radius:5px;font-size:10px;font-weight:700;white-space:nowrap}
.mode-payee{background:#ede9fe;color:#5b21b6}
.mode-cash{background:#dcfce7;color:#166534}
.mode-3party{background:#fef3c7;color:#92400e}
.bulk-wrap{display:flex;align-items:center;gap:7px;white-space:nowrap}
.bulk-check{width:16px;height:16px;accent-color:#6366f1;cursor:pointer;flex-shrink:0}
.bulk-label{font-size:11px;font-weight:600;color:#374151}
.bulk-check:checked + .bulk-label{color:#16a34a}
.expand-btn{background:none;border:1.5px solid #d1d5db;border-radius:6px;width:28px;height:28px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#6b7280;transition:all .2s;flex-shrink:0}
.expand-btn:hover{background:#f0f9ff;border-color:#6366f1;color:#6366f1}
.expand-btn.open{background:#6366f1;border-color:#6366f1;color:#fff;transform:rotate(180deg)}
mark.hl{background:#fef08a;color:#713f12;border-radius:2px;padding:0 1px}
tr.sub-row{display:none}
tr.sub-row.visible{display:table-row}
tr.sub-row td{padding:0}
.sub-loading{padding:16px 16px 16px 48px;background:linear-gradient(135deg,#f8faff,#eef2ff);color:#6366f1;font-size:12px;font-weight:500;display:flex;align-items:center;gap:8px;border-bottom:2px solid #c7d2fe;}
.sub-shell{background:linear-gradient(135deg,#f8faff,#eef2ff);border-bottom:2px solid #c7d2fe;display:grid;grid-template-columns:1fr 1fr;}
.sub-panel{padding:16px 18px 18px 18px}
.sub-panel.inv-panel{padding-left:48px;border-right:1px solid #dde5ff;}
.sub-title{display:flex;align-items:center;gap:8px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px;}
.sub-title-icon{width:24px;height:24px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;}
.sti-inv{background:#ede9fe;color:#4f46e5}.sti-bank{background:#d1fae5;color:#059669}
.sub-title.inv-t{color:#4f46e5}.sub-title.bank-t{color:#059669}
.sub-count{margin-left:auto;border-radius:12px;padding:1px 8px;font-size:10px;font-weight:700;}
.sc-inv{background:#e0e7ff;color:#4338ca}.sc-bank{background:#a7f3d0;color:#065f46}
.inv-list{display:flex;flex-direction:column;gap:6px}
.inv-card{background:#fff;border:1px solid #e0e7ff;border-radius:9px;padding:9px 12px;display:flex;align-items:center;gap:10px;transition:border-color .15s,box-shadow .15s;}
.inv-card:hover{border-color:#a5b4fc;box-shadow:0 2px 10px rgba(99,102,241,.1)}
.inv-num{background:#ede9fe;color:#3730a3;border-radius:6px;padding:4px 9px;font-family:'Courier New',monospace;font-size:11px;font-weight:800;white-space:nowrap;flex-shrink:0;}
.inv-body{flex:1;min-width:0}
.inv-cust{font-size:11.5px;font-weight:600;color:#1f2937;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.inv-tcode{font-size:10px;color:#9ca3af;margin-top:1px}
.inv-amt{font-size:12.5px;font-weight:800;color:#3730a3;white-space:nowrap;flex-shrink:0}
.inv-total{margin-top:10px;background:linear-gradient(135deg,#ede9fe,#ddd6fe);border-radius:8px;padding:8px 14px;display:flex;justify-content:space-between;align-items:center;}
.inv-total-lbl{font-size:10px;font-weight:700;color:#5b21b6;text-transform:uppercase;}
.inv-total-val{font-size:14px;font-weight:800;color:#3730a3}
.bank-list{display:flex;flex-direction:column;gap:8px}
.bank-card{background:#fff;border:1px solid #a7f3d0;border-radius:9px;padding:11px 14px;transition:border-color .15s,box-shadow .15s;}
.bank-card:hover{border-color:#34d399;box-shadow:0 2px 10px rgba(16,185,129,.1)}
.bank-head{display:flex;align-items:center;gap:9px;margin-bottom:8px}
.bank-avatar{width:32px;height:32px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,#059669,#34d399);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;}
.bank-holder{font-size:13px;font-weight:700;color:#064e3b}
.bank-acno{font-family:'Courier New',monospace;font-size:12.5px;font-weight:700;color:#059669;background:#ecfdf5;border:1.5px solid #a7f3d0;border-radius:6px;padding:4px 10px;display:inline-block;margin-bottom:7px;}
.bank-pills{display:flex;gap:6px;flex-wrap:wrap}
.bank-pill{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:600;color:#166534;display:flex;align-items:center;gap:4px;white-space:nowrap;}
.sub-empty{color:#9ca3af;font-size:12px;display:flex;align-items:center;gap:6px;padding:8px 0}
.save-btn{display:none;align-items:center;gap:4px;background:#6366f1;color:#fff;border:none;border-radius:6px;padding:5px 11px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;transition:background .2s}
.save-btn:hover{background:#4f46e5}
.save-btn.visible{display:inline-flex}
.save-btn:disabled{opacity:.55;cursor:not-allowed}
.saved-tick{display:none;align-items:center;gap:4px;font-size:11px;font-weight:700;color:#16a34a;white-space:nowrap}
.saved-tick.visible{display:inline-flex}
.state-box{text-align:center;padding:80px 20px;color:#9ca3af}
.state-box i{font-size:48px;display:block;margin-bottom:16px;opacity:.3}
.state-box p{font-size:14px;font-weight:500}
.state-box small{font-size:12px}
.edit-row-btn{display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;border:none;border-radius:6px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;transition:filter .2s;text-decoration:none;}
.edit-row-btn:hover{filter:brightness(1.1)}
.dep-row-btn{display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;border:none;border-radius:6px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;transition:filter .2s;}
.dep-row-btn:hover{filter:brightness(1.1)}
.verified-badge{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,#16a34a,#4ade80);color:#fff;font-size:11px;box-shadow:0 2px 6px rgba(22,163,74,.4);flex-shrink:0;}
.verify-btn{display:inline-flex;align-items:center;gap:4px;background:#0f766e;color:#fff;border:none;border-radius:6px;padding:5px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;transition:background .2s;}
.verify-btn:hover{background:#0d6460}
.view-verified-btn{display:inline-flex;align-items:center;gap:4px;background:#4f46e5;color:#fff;border:none;border-radius:6px;padding:5px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;transition:background .2s;}
.view-verified-btn:hover{background:#4338ca}
.select2-container--default .select2-selection--single{height:37px!important;border:1px solid #e5e5e5!important;border-radius:7px!important}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:35px!important;padding-left:11px!important;color:#1f2937!important;font-size:13px!important;font-family:inherit!important}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:35px!important}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:#6366f1!important;box-shadow:0 0 0 3px rgba(99,102,241,.1)!important}
.select2-dropdown{border:1px solid #e5e5e5!important;border-radius:8px!important;box-shadow:0 8px 30px rgba(0,0,0,.12)!important;font-size:13px!important;z-index:10000000!important;}
.select2-results__option--highlighted{background:#6366f1!important}
#toast{position:fixed;bottom:28px;right:28px;z-index:99999;padding:12px 22px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 6px 24px rgba(0,0,0,.2);color:#fff;transform:translateY(80px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none}
#toast.show{transform:translateY(0);opacity:1}
/* View modal */
#viewModal{display:none;position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.72);overflow-y:auto;padding:30px 16px 40px;}
#viewModal.open{display:block;}
.viewmodal-box{background:#fff;border-radius:16px;width:100%;max-width:900px;margin:0 auto;box-shadow:0 32px 100px rgba(0,0,0,.45);overflow:hidden;animation:vmodalIn .22s cubic-bezier(.16,1,.3,1);}
.viewmodal-header{background:linear-gradient(135deg,#4f46e5,#6366f1);padding:16px 22px;display:flex;align-items:center;justify-content:space-between;}
.viewmodal-title{color:#fff;font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px;}
.viewmodal-close{background:rgba(255,255,255,.15);border:none;border-radius:8px;color:#fff;width:32px;height:32px;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;}
.viewmodal-body{padding:22px;}
.viewmodal-info{background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 14px;margin-bottom:18px;display:grid;grid-template-columns:repeat(3,1fr);gap:8px;}
.viewmodal-info .vci-item{display:flex;flex-direction:column;gap:2px;}
.viewmodal-info .vci-lbl{font-size:10px;font-weight:700;color:#0369a1;text-transform:uppercase;}
.viewmodal-info .vci-val{font-size:13px;font-weight:700;color:#0c4a6e;}
.viewmodal-section{margin-bottom:20px;}
.viewmodal-section-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.viewmodal-images-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.viewmodal-img-card{border:2px solid #e0e7ff;border-radius:10px;overflow:hidden;background:#fafbff;display:flex;flex-direction:column;}
.viewmodal-img-label{font-size:10px;font-weight:700;color:#6366f1;text-transform:uppercase;padding:8px 12px;background:#ede9fe;text-align:center;border-bottom:1px solid #e0e7ff;}
.viewmodal-img-wrap{min-height:160px;display:flex;align-items:center;justify-content:center;padding:10px;position:relative;}
.viewmodal-img-wrap img{width:100%;max-height:320px;object-fit:contain;border-radius:6px;cursor:pointer;transition:transform .2s;}
.viewmodal-img-wrap img:hover{transform:scale(1.02);}
.viewmodal-img-empty{color:#c7d2fe;font-size:36px;display:flex;flex-direction:column;align-items:center;gap:6px;padding:30px 0;}
.viewmodal-img-empty span{font-size:11px;color:#9ca3af;font-weight:600;}
.viewmodal-cust-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.viewmodal-cust-card{border:2px dashed #e0e7ff;border-radius:10px;overflow:hidden;background:#fafbff;display:flex;flex-direction:column;}
.viewmodal-cust-label{font-size:10px;font-weight:700;color:#059669;text-transform:uppercase;padding:8px 12px;background:#d1fae5;text-align:center;border-bottom:1px solid #a7f3d0;}
.viewmodal-cust-wrap{min-height:100px;display:flex;align-items:center;justify-content:center;padding:10px;}
.viewmodal-cust-wrap img{width:100%;max-height:160px;object-fit:contain;border-radius:6px;}
.viewmodal-cust-empty{color:#c7d2fe;font-size:28px;display:flex;flex-direction:column;align-items:center;gap:4px;padding:20px 0;}
.viewmodal-cust-empty span{font-size:10px;color:#9ca3af;font-weight:600;}
.viewmodal-foot{display:flex;gap:10px;padding:14px 22px;background:#f8faff;border-top:1px solid #e0e7ff;}
/* Fullscreen */
#imgFullscreen{display:none;position:fixed;inset:0;z-index:9999999;background:rgba(0,0,0,.92);align-items:center;justify-content:center;cursor:zoom-out;padding:20px;}
#imgFullscreen.open{display:flex;}
#imgFullscreen img{max-width:95vw;max-height:92vh;object-fit:contain;border-radius:8px;}
#imgFullscreen .fs-close{position:absolute;top:16px;right:20px;background:rgba(255,255,255,.15);border:none;border-radius:8px;color:#fff;width:40px;height:40px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;}
.scroll-nav-btn{background:#fff;border:1.5px solid #e0e7ff;border-radius:6px;padding:4px 12px;font-size:11px;font-weight:600;color:#4f46e5;cursor:pointer;display:inline-flex;align-items:center;gap:5px;font-family:inherit;white-space:nowrap;}
.scroll-nav-btn:hover{background:#ede9fe;border-color:#a5b4fc;}

/* ═══════════════════════════════════════════════════════
   VERIFY MODAL — FULLSCREEN BROWSER-SIZED (UPDATED)
═══════════════════════════════════════════════════════ */
#verifyModal{display:none;position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.82);overflow-y:auto;padding:6px;}
#verifyModal.open{display:block;}
.vmodal-box{background:#fff;border-radius:12px;width:100%;max-width:98vw;max-height:96vh;margin:0 auto;box-shadow:0 32px 100px rgba(0,0,0,.45);overflow:hidden;animation:vmodalIn .22s cubic-bezier(.16,1,.3,1);position:relative;display:flex;flex-direction:column;}
@keyframes vmodalIn{from{transform:translateY(-40px) scale(.97);opacity:0}to{transform:translateY(0) scale(1);opacity:1}}
.vmodal-header{background:linear-gradient(135deg,#1e1b4b,#312e81);padding:10px 20px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.vmodal-title{color:#fff;font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px}
.vmodal-close{background:rgba(255,255,255,.15);border:none;border-radius:8px;color:#fff;width:32px;height:32px;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;}
.vmodal-body{padding:14px 20px;overflow-y:auto;flex:1;}
.vmodal-section{margin-bottom:10px}
.vmodal-section-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.cust-images-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px}
.cust-img-box{border:2px dashed #e0e7ff;border-radius:8px;min-height:60px;max-height:100px;display:flex;align-items:center;justify-content:center;flex-direction:row;gap:8px;background:#fafbff;overflow:hidden;position:relative;padding:6px 10px;}
.cust-img-box img{height:80px;max-width:45%;object-fit:contain;border-radius:6px}
.cust-img-box .img-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;}
.cust-img-placeholder{color:#c7d2fe;font-size:20px}
/* ── EQUAL SIDE-BY-SIDE CHEQUE IMAGE PANELS ── */
.cheque-upload-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:6px}
.upload-box{border:2px dashed #e0e7ff;border-radius:10px;padding:8px 12px;background:#fafbff;display:flex;flex-direction:column;gap:4px;}
.upload-box label{font-size:11px;font-weight:700;color:#374151;cursor:pointer}
.upload-box input[type=file]{font-size:12px;color:#374151}
.upload-preview{width:100%;min-height:280px;border:1.5px solid #e0e7ff;border-radius:8px;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#fff;margin-top:2px;}
.upload-preview img{width:100%;object-fit:contain;max-height:520px}
.upload-preview .prev-empty{color:#c7d2fe;font-size:28px}
.vmodal-actions{display:flex;gap:10px;flex-wrap:wrap;padding:10px 20px;background:#f8faff;border-top:1px solid #e0e7ff;flex-shrink:0;}
.vbtn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .2s;white-space:nowrap;}
.vbtn-verify{background:linear-gradient(135deg,#16a34a,#4ade80);color:#fff;box-shadow:0 4px 12px rgba(22,163,74,.3)}
.vbtn-sendback{background:linear-gradient(135deg,#dc2626,#f87171);color:#fff;box-shadow:0 4px 12px rgba(220,38,38,.3)}
.vbtn-cancel{background:#f3f4f6;color:#374151;border:1px solid #e5e5e5}
.sendback-reason-wrap{display:none;width:100%;margin-top:10px;flex-direction:column;gap:8px;}
.sendback-reason-wrap.open{display:flex}
.sendback-reason-wrap label{font-size:11px;font-weight:700;color:#374151}
#sb-select2-wrap{width:100%;position:relative;}
#sb-select2-wrap .select2-container{width:100%!important;}
#sb-select2-wrap .select2-container--default .select2-selection--single{height:42px!important;border:1.5px solid #fca5a5!important;border-radius:8px!important;background:#fff5f5!important;display:flex!important;align-items:center!important;}
#sb-select2-wrap .select2-container--default .select2-selection--single .select2-selection__rendered{line-height:42px!important;padding-left:12px!important;color:#991b1b!important;font-size:13px!important;font-weight:600!important;}
#sb-select2-wrap .select2-container--default .select2-selection--single .select2-selection__placeholder{color:#f87171!important;}
#sb-select2-wrap .select2-container--default .select2-selection--single .select2-selection__arrow{height:42px!important;}
.sb-sendback-dropdown.select2-dropdown{border:1.5px solid #fca5a5!important;border-radius:8px!important;box-shadow:0 8px 30px rgba(220,38,38,.15)!important;font-size:13px!important;z-index:9999999!important;}
.sb-sendback-dropdown .select2-results__option--highlighted{background:#fee2e2!important;color:#991b1b!important;}
.sendback-confirm-btn{display:inline-flex;align-items:center;gap:5px;background:#dc2626;color:#fff;border:none;border-radius:7px;padding:9px 18px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;align-self:flex-end;}
.vmodal-cheque-info{background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:6px 14px;margin-bottom:8px;display:grid;grid-template-columns:repeat(3,1fr);gap:6px;}
.vci-item{display:flex;flex-direction:column;gap:2px}
.vci-lbl{font-size:10px;font-weight:700;color:#0369a1;text-transform:uppercase;}
.vci-val{font-size:13px;font-weight:700;color:#0c4a6e}
.ai-scan-box{display:none;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:2px solid #4ade80;border-radius:10px;padding:14px 16px;margin-top:12px;}
.ai-scan-box.visible{display:block;}
.ai-scan-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#16a34a;margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.ai-scan-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;}
.ai-field{display:flex;flex-direction:column;gap:3px;}
.ai-field-lbl{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;}
.ai-field-val{font-size:13px;font-weight:800;color:#1f2937;font-family:'Courier New',monospace;}
.ai-field-val.empty{color:#d1d5db;font-style:italic;font-weight:400;font-family:inherit;}
.ai-scan-loading{display:none;align-items:center;gap:8px;font-size:12px;font-weight:600;color:#0369a1;margin-top:10px;}
.ai-scan-loading.visible{display:flex;}
/* Logs modal */
.log-item{display:flex;gap:12px;padding:11px 0;border-bottom:1px solid #f3f4f6;align-items:flex-start;}
.log-item:last-child{border-bottom:none;}
.log-icon{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;}
.log-icon.ic-status{background:#dbeafe;color:#1e40af;}
.log-icon.ic-verify{background:#dcfce7;color:#166534;}
.log-icon.ic-back{background:#fee2e2;color:#991b1b;}
.log-icon.ic-deposit{background:#ccfbf1;color:#0d9488;}
.log-icon.ic-other{background:#f3f4f6;color:#374151;}
.log-body{flex:1;min-width:0;}
.log-action-label{font-size:12px;font-weight:700;color:#1f2937;margin-bottom:4px;}
.log-change{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:4px;}
.log-val{background:#f3f4f6;border-radius:4px;padding:1px 8px;font-size:11px;font-weight:600;color:#374151;font-family:'Courier New',monospace;white-space:nowrap;}
.log-note{font-size:11px;color:#6b7280;margin-bottom:3px;word-break:break-word;}
.log-note-back{background:#f5f3ff;border-left:3px solid #7c3aed;padding:4px 8px;border-radius:0 4px 4px 0;color:#5b21b6;}
.log-time{font-size:10px;color:#9ca3af;display:flex;align-items:center;gap:4px;flex-wrap:wrap;}
.log-empty{text-align:center;padding:40px 20px;color:#9ca3af;}
.log-status-pill{display:inline-block;padding:1px 8px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}
.lp-amber{background:#fef3c7;color:#92400e;border:1px solid #fcd34d;}
.lp-sky{background:#e0f2fe;color:#0369a1;border:1px solid #7dd3fc;}
.lp-teal{background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;}
.lp-violet{background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;}
.lp-green{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.lp-red{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.lp-gray{background:#f3f4f6;color:#374151;border:1px solid #d1d5db;}
.log-user-badge{display:inline-flex;align-items:center;gap:3px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:20px;padding:1px 7px;font-size:10px;font-weight:600;}
.log-user-sys{background:#f9fafb;color:#6b7280;border-color:#e5e7eb;}
.log-pill{display:inline-block;padding:1px 8px;border-radius:20px;font-size:10px;font-weight:700;}
.pill-bulk{background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;}
.pill-normal{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;}
.pill-normal-bulk{background:#fef3c7;color:#92400e;border:1px solid #fcd34d;}
.log-dep-banner{background:linear-gradient(135deg,#f0fdfa 0%,#e0f2fe 100%);border:1px solid #5eead4;border-radius:10px;padding:12px 14px;margin-bottom:14px;}
.log-dep-banner-title{font-size:11px;font-weight:800;color:#0f766e;text-transform:uppercase;margin-bottom:8px;display:flex;align-items:center;gap:6px;}
.log-dep-banner-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;}
.log-dep-field{display:flex;flex-direction:column;gap:2px;}
.log-dep-lbl{font-size:9px;font-weight:700;color:#0d9488;text-transform:uppercase;}
.log-dep-val{font-size:11px;color:#1f2937;font-weight:600;}
.log-dep-note{display:flex;flex-direction:column;gap:2px;margin-bottom:4px;padding:5px 8px;background:#f0fdfa;border-radius:6px;border-left:3px solid #0d9488;}
.log-dep-note-item{font-size:11px;color:#0f766e;}
.tbl-loading{display:none;position:absolute;inset:0;background:rgba(255,255,255,.8);z-index:50;align-items:center;justify-content:center;flex-direction:column;gap:10px;font-size:13px;color:#6366f1;font-weight:600;border-radius:10px;}
.tbl-loading.show{display:flex;}
.tbl-wrap{position:relative;}
.tbl-spinner{width:36px;height:36px;border:4px solid #e0e7ff;border-top-color:#6366f1;border-radius:50%;animation:spin .7s linear infinite;}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:900px){.sub-shell{grid-template-columns:1fr}.sub-panel.inv-panel{border-right:none;border-bottom:1px solid #dde5ff;padding-left:18px}}
@media(max-width:1400px){.stat-grid{grid-template-columns:repeat(4,1fr)}}
@media(max-width:1000px){.filter-row{grid-template-columns:1fr 1fr}.filter-row-2{grid-template-columns:1fr 1fr 1fr 1fr}.stat-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:640px){.filter-row{grid-template-columns:1fr}.filter-row-2{grid-template-columns:1fr 1fr}.stat-grid{grid-template-columns:1fr 1fr}.dep-type-row{grid-template-columns:1fr}.dep-fields-row{grid-template-columns:1fr}.viewmodal-images-grid{grid-template-columns:1fr}.viewmodal-cust-row{grid-template-columns:1fr}.sum-status-grid{grid-template-columns:repeat(2,1fr)}.cheque-upload-row{grid-template-columns:1fr}.cust-images-row{grid-template-columns:1fr}}
@media print{.no-print{display:none!important}.data-table thead th{background:#1e1b4b!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}.data-table tfoot td{background:#0f172a!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}.save-btn,.saved-tick,.expand-btn,.sub-row{display:none!important}}



/* ═══════════════════════════════════════════════════════
   VIEW MODAL — FULLSCREEN (matches verifyModal exactly)
═══════════════════════════════════════════════════════ */
#viewModal{display:none;position:fixed;inset:0;z-index:9999999;background:rgba(0,0,0,.82);overflow-y:auto;padding:6px;}
#viewModal.open{display:block;}
.viewmodal-box{background:#fff;border-radius:12px;width:100%;max-width:98vw;max-height:96vh;margin:0 auto;box-shadow:0 32px 100px rgba(0,0,0,.45);overflow:hidden;animation:vmodalIn .22s cubic-bezier(.16,1,.3,1);position:relative;display:flex;flex-direction:column;}
.viewmodal-header{background:linear-gradient(135deg,#1e1b4b,#312e81);padding:10px 20px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.viewmodal-title{color:#fff;font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px;}
.viewmodal-close{background:rgba(255,255,255,.15);border:none;border-radius:8px;color:#fff;width:32px;height:32px;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;}
.viewmodal-body{padding:14px 20px;overflow-y:auto;flex:1;}
.viewmodal-info{background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:6px 14px;margin-bottom:8px;display:grid;grid-template-columns:repeat(3,1fr);gap:6px;}
.viewmodal-info .vci-item{display:flex;flex-direction:column;gap:2px;}
.viewmodal-info .vci-lbl{font-size:10px;font-weight:700;color:#0369a1;text-transform:uppercase;}
.viewmodal-info .vci-val{font-size:13px;font-weight:700;color:#0c4a6e;}
.viewmodal-section{margin-bottom:10px;}
.viewmodal-section-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;margin-bottom:8px;display:flex;align-items:center;gap:6px;}
.viewmodal-images-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.viewmodal-img-card{border:2px dashed #e0e7ff;border-radius:10px;background:#fafbff;display:flex;flex-direction:column;}
.viewmodal-img-label{font-size:11px;font-weight:700;color:#6366f1;text-transform:uppercase;padding:8px 12px;background:#ede9fe;text-align:center;border-bottom:1px solid #e0e7ff;}
.viewmodal-img-wrap{display:flex;align-items:center;justify-content:center;padding:10px;position:relative;min-height:280px;}
.viewmodal-img-wrap img{width:100%;max-height:520px;object-fit:contain;border-radius:6px;cursor:pointer;transition:transform .2s;}
.viewmodal-img-wrap img:hover{transform:scale(1.02);}
.viewmodal-img-empty{color:#c7d2fe;font-size:28px;display:flex;flex-direction:column;align-items:center;gap:6px;padding:30px 0;}
.viewmodal-img-empty span{font-size:11px;color:#9ca3af;font-weight:600;}
.viewmodal-cust-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.viewmodal-cust-card{border:2px dashed #e0e7ff;border-radius:10px;background:#fafbff;display:flex;flex-direction:column;}
.viewmodal-cust-label{font-size:10px;font-weight:700;color:#059669;text-transform:uppercase;padding:8px 12px;background:#d1fae5;text-align:center;border-bottom:1px solid #a7f3d0;}
.viewmodal-cust-wrap{min-height:100px;display:flex;align-items:center;justify-content:center;padding:10px;}
.viewmodal-cust-wrap img{width:100%;max-height:160px;object-fit:contain;border-radius:6px;cursor:pointer;}
.viewmodal-cust-empty{color:#c7d2fe;font-size:28px;display:flex;flex-direction:column;align-items:center;gap:4px;padding:20px 0;}
.viewmodal-cust-empty span{font-size:10px;color:#9ca3af;font-weight:600;}
.viewmodal-foot{display:flex;gap:10px;padding:10px 20px;background:#f8faff;border-top:1px solid #e0e7ff;flex-shrink:0;}
@media(max-width:640px){.viewmodal-images-grid{grid-template-columns:1fr}.viewmodal-cust-row{grid-template-columns:1fr}}

/* ═══════════════════════════════════════════════════════════════
   CHEQUE INFO STRIP — read-only label bar   [CHQ-PATCH1-CSS]
═══════════════════════════════════════════════════════════════ */
.chq-info-strip{background:linear-gradient(135deg,#1e1b4b 0%,#312e81 100%);border-radius:10px;padding:12px 16px;margin-bottom:12px;display:grid;grid-template-columns:repeat(3,1fr) repeat(3,1fr);gap:0;}
.chq-info-strip-item{display:flex;flex-direction:column;gap:3px;padding:6px 12px;position:relative;}
.chq-info-strip-item:not(:last-child)::after{content:'';position:absolute;right:0;top:10%;height:80%;width:1px;background:rgba(255,255,255,.15);}
.chq-info-strip-lbl{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:rgba(165,180,252,.85);}
.chq-info-strip-val{font-size:17px;font-weight:800;color:#fff;font-family:'Courier New',monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
@media(max-width:700px){.chq-info-strip{grid-template-columns:1fr 1fr;}}
/* ── CHEQUE DETAILS SECTION — blue card below images ── */
.chq-details-section{background:linear-gradient(135deg,#eff6ff 0%,#dbeafe 100%);border:2px solid #bfdbfe;border-radius:12px;padding:18px 20px;margin-top:16px;}
.chq-details-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.09em;color:#1d4ed8;margin-bottom:14px;display:flex;align-items:center;gap:7px;}
.chq-details-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px 18px;}
.chq-details-item{display:flex;flex-direction:column;gap:5px;}
.chq-details-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#3b82f6;}
.chq-details-val{font-size:15px;font-weight:700;color:#1e3a8a;font-family:'Courier New',monospace;line-height:1.3;word-break:break-all;}
.chq-details-val.plain{font-family:inherit;font-size:15px;}
.chq-details-val.status-val{font-family:inherit;}
.chq-details-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:13px;font-weight:700;white-space:nowrap;}
.chq-db-pending{background:#fef3c7;color:#92400e;border:1.5px solid #fde68a;}
.chq-db-to_be_bank{background:#e0f2fe;color:#0369a1;border:1.5px solid #7dd3fc;}
.chq-db-deposited{background:#dbeafe;color:#1e40af;border:1.5px solid #bfdbfe;}
.chq-db-sent_back{background:#fdf4ff;color:#7e22ce;border:1.5px solid #d8b4fe;}
.chq-db-cleared{background:#dcfce7;color:#166534;border:1.5px solid #86efac;}
.chq-db-returned{background:#fee2e2;color:#991b1b;border:1.5px solid #fecaca;}
@media(max-width:700px){.chq-details-grid{grid-template-columns:1fr 1fr;}}
/* ── END CHQ-PATCH1-CSS ── */


/* ── Verify modal edit section ── */
.vei{padding:8px 10px;border:1.5px solid #fde68a;border-radius:7px;font-size:13px;
     font-family:inherit;color:#1f2937;background:#fff;width:100%;box-sizing:border-box;
     outline:none;transition:border-color .2s;}
.vei:focus{border-color:#d97706;box-shadow:0 0 0 3px rgba(217,119,6,.12);}
.vei-select{cursor:pointer;}


.stat-grid{display:grid;grid-template-columns:repeat(9,1fr);gap:10px;margin-bottom:20px}

@media(max-width:1400px){.stat-grid{grid-template-columns:repeat(5,1fr)}}
@media(max-width:1000px){.filter-row{grid-template-columns:1fr 1fr}.filter-row-2{grid-template-columns:1fr 1fr 1fr 1fr}.stat-grid{grid-template-columns:repeat(3,1fr)}}
</style>

<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;" class="no-print">
  <div>
    <h2 class="page-title"><i class="fa-solid fa-money-check" style="color:#6366f1;"></i> Cheque Register</h2>
    <p class="page-subtitle">Master list of all received cheques — click status badge to change, expand for details.</p>
<p class="page-subtitle"><i class="fa-solid fa-circle-info" style="color:#6366f1;"></i> <strong>Cheques In Hand</strong> = Pending + To Be Deposited + Send Back + Deposited</p>
  </div>
  <div style="display:flex;gap:8px;" class="no-print">
    <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</button>
    <button onclick="exportExcel()" class="btn btn-success btn-sm"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
  </div>
</div>

<!-- FILTERS -->
<div class="filter-card no-print">
  <div class="filter-header" id="filterHeader" onclick="toggleFilter()">
    <div class="filter-title"><i class="fa-solid fa-sliders" style="color:#6366f1;"></i> Filters
      <?php
/* BRANCH_CODE_PATCH_V1_APPLIED */
/* PATCHED_V2 */ if($f_sr||$f_status||$f_bank||$f_tcode||$f_from||$f_to||$f_del_from||$f_del_to||$f_rec_from||$f_rec_to): ?><span style="background:#ede9fe;color:#5b21b6;padding:1px 8px;border-radius:8px;font-size:10px;margin-left:4px;">Active</span><?php
/* BRANCH_CODE_PATCH_V1_APPLIED */
/* PATCHED_V2 */ endif; ?>
    </div>
    <i class="fa-solid fa-chevron-down filter-toggle-icon" id="filterIcon"></i>
  </div>
  <div class="filter-body" id="filterBody">
    <div class="filter-row">
      <div class="ffg"><label><i class="fa-solid fa-id-badge"></i> SR Code</label>
        <select id="selSR" style="width:100%;"><option value="">— All SR —</option>
          <?php
/* BRANCH_CODE_PATCH_V1_APPLIED */
/* PATCHED_V2 */ foreach($all_sr as $sr): ?><option value="<?=htmlspecialchars($sr)?>" <?=$f_sr===$sr?'selected':''?>><?=htmlspecialchars($sr)?></option><?php
/* BRANCH_CODE_PATCH_V1_APPLIED */
/* PATCHED_V2 */ endforeach; ?>
        </select></div>
      <div class="ffg"><label><i class="fa-solid fa-circle-half-stroke"></i> Status</label>
        <select id="selStatus" style="width:100%;" multiple><option value="">— All Status —</option>
          <?php
/* BRANCH_CODE_PATCH_V1_APPLIED */
/* PATCHED_V2 */ foreach($statuses as $s): ?><option value="<?=$s?>" <?=$f_status===$s?'selected':''?>><?=htmlspecialchars($status_labels[$s])?></option><?php
/* BRANCH_CODE_PATCH_V1_APPLIED */
/* PATCHED_V2 */ endforeach; ?>
        </select></div>
      <div class="ffg"><label><i class="fa-solid fa-building-columns"></i> Bank Code</label>
        <select id="selBank" style="width:100%;"><option value="">— All Banks —</option>
          <?php
/* BRANCH_CODE_PATCH_V1_APPLIED */
/* PATCHED_V2 */ foreach($all_banks as $bk): ?><option value="<?=htmlspecialchars($bk)?>" <?=$f_bank===$bk?'selected':''?>><?=htmlspecialchars($bk)?></option><?php
/* BRANCH_CODE_PATCH_V1_APPLIED */
/* PATCHED_V2 */ endforeach; ?>
        </select></div>
      <div class="ffg"><label><i class="fa-solid fa-user-tag"></i> T-Code</label>
        <input type="text" id="fTcode" value="<?=htmlspecialchars($f_tcode)?>" placeholder="Search T-Code..."></div>
    </div>
    <div class="filter-row-2">
      <div class="ffg"><label><i class="fa-solid fa-calendar-day"></i> Cheque From</label><input type="date" id="fFrom" value="<?=htmlspecialchars($f_from)?>"></div>
      <div class="ffg"><label><i class="fa-solid fa-calendar-day"></i> Cheque To</label><input type="date" id="fTo" value="<?=htmlspecialchars($f_to)?>"></div>
      <div class="ffg"><label><i class="fa-solid fa-truck"></i> Delivery From</label><input type="date" id="fDelFrom" value="<?=htmlspecialchars($f_del_from)?>"></div>
      <div class="ffg"><label><i class="fa-solid fa-truck"></i> Delivery To</label><input type="date" id="fDelTo" value="<?=htmlspecialchars($f_del_to)?>"></div>
      <div class="ffg"><label><i class="fa-solid fa-calendar-check"></i> Received From</label><input type="date" id="fRecFrom" value="<?=htmlspecialchars($f_rec_from)?>"></div>
      <div class="ffg"><label><i class="fa-solid fa-calendar-check"></i> Received To</label><input type="date" id="fRecTo" value="<?=htmlspecialchars($f_rec_to)?>"></div>
      <div class="ffg">
        <label><i class="fa-solid fa-star" style="color:#d97706;"></i> Sampath</label>
        <label style="display:flex;align-items:center;gap:7px;border:1px solid #fde68a;background:#fffbeb;border-radius:7px;padding:8px 11px;cursor:pointer;user-select:none;">
          <input type="checkbox" id="fSampathOnly" style="width:16px;height:16px;accent-color:#d97706;cursor:pointer;">
          <span style="font-size:12px;font-weight:700;color:#92400e;">Sampath cheques only</span>
        </label>
      </div>
      <div class="ffg" style="flex-direction:row;gap:8px;align-items:flex-end;">
        <button type="button" class="btn btn-primary" onclick="applyFilters()" style="flex:1;"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
        <button type="button" class="btn btn-secondary" onclick="clearFilters()" title="Clear"><i class="fa-solid fa-rotate-left"></i></button>
      </div>
    </div>
  </div>
</div>

<!-- STAT CARDS -->
<?php
/* BRANCH_CODE_PATCH_V1_APPLIED */
/* ── Calculate sent_back and returned unsettled balances ── */
$sb_bal_r = mysqli_query($conn, "SELECT
    COALESCE(SUM(GREATEST(total_amount - COALESCE(sb_settlement_amount,0), 0)),0) AS sb_balance,
    COUNT(CASE WHEN COALESCE(sb_settled,0)=0 THEN 1 END) AS sb_unsettled_cnt,
    COUNT(CASE WHEN COALESCE(sb_settled,0)=1 THEN 1 END) AS sb_settled_cnt
    FROM cheques WHERE status='sent_back'");
$sb_bal_data = $sb_bal_r ? mysqli_fetch_assoc($sb_bal_r) : ['sb_balance'=>0,'sb_unsettled_cnt'=>0,'sb_settled_cnt'=>0];
$sb_unsettled_balance = (float)($sb_bal_data['sb_balance'] ?? 0);

$rtn_bal_r = mysqli_query($conn, "SELECT
    COALESCE(SUM(GREATEST(total_amount - COALESCE(settlement_amount,0), 0)),0) AS rtn_balance,
    COUNT(CASE WHEN COALESCE(return_settled,0)=0 THEN 1 END) AS rtn_unsettled_cnt,
    COUNT(CASE WHEN COALESCE(return_settled,0)=1 THEN 1 END) AS rtn_settled_cnt
    FROM cheques WHERE status='returned' OR status='bounced'");
$rtn_bal_data = $rtn_bal_r ? mysqli_fetch_assoc($rtn_bal_r) : ['rtn_balance'=>0,'rtn_unsettled_cnt'=>0,'rtn_settled_cnt'=>0];
$rtn_unsettled_balance = (float)($rtn_bal_data['rtn_balance'] ?? 0);

$in_hand_amount = $status_totals['pending']['amount'] + $status_totals['to_be_bank']['amount'] + $status_totals['deposited']['amount'] + $sb_unsettled_balance ;
$in_hand_count  = $status_totals['pending']['count']  + $status_totals['to_be_bank']['count']  + $status_totals['deposited']['count'] + (int)($sb_bal_data['sb_unsettled_cnt'] ?? 0) + (int)($rtn_bal_data['rtn_unsettled_cnt'] ?? 0);
?>
<div class="stat-grid">
<?php
/* BRANCH_CODE_PATCH_V1_APPLIED */
$true_total_amount = $status_totals['pending']['amount']
                   + $status_totals['to_be_bank']['amount']
                   + $status_totals['deposited']['amount']
                   + $status_totals['cleared']['amount']
                   + $sb_unsettled_balance
                   + $rtn_unsettled_balance;

$true_total_count  = $status_totals['pending']['count']
                   + $status_totals['to_be_bank']['count']
                   + $status_totals['deposited']['count']
                   + $status_totals['cleared']['count']
                   + (int)($sb_bal_data['sb_unsettled_cnt']??0)
                   + (int)($rtn_bal_data['rtn_unsettled_cnt']??0);
?>
<div class="stat-card" onclick="filterByStatus('')" title="All cheques">
  <div class="stat-label">Total Cheques</div>
  <div class="stat-value sv-violet" style="font-size:15px;">Rs.&nbsp;<?=number_format($true_total_amount,0)?></div>
  <div class="stat-card-amt"><?=$true_total_count?> cheques</div>
</div>
<div class="stat-card" style="border-color:#4f46e5;background:#f0f4ff;cursor:pointer;" onclick="filterByStatusMulti(['pending','to_be_bank','deposited'])" title="Cheques in Hand (Pending + To Be Bank + Deposited)">
  <div class="stat-label"><i class="fa-solid fa-hand-holding" style="color:#4f46e5;"></i> Cheques in Hand</div>
  <div class="stat-value" style="color:#3730a3;font-size:15px;">Rs.&nbsp;<?=number_format($in_hand_amount,0)?></div>
  <div class="stat-card-amt"><?=$in_hand_count?> cheques</div>
</div>
  <div class="stat-card <?=$f_status==='pending'?'active-filter':''?>" onclick="filterByStatus('pending')"><div class="stat-label"><i class="fa-solid fa-clock" style="color:#d97706;"></i> Pending</div><div class="stat-value sv-amber" style="font-size:15px;">Rs.&nbsp;<?=number_format($status_totals['pending']['amount'],0)?></div><div class="stat-card-amt"><?=$status_totals['pending']['count']?> cheques</div></div>
  <div class="stat-card <?=$f_status==='to_be_bank'?'active-filter':''?>" onclick="filterByStatus('to_be_bank')"><div class="stat-label"><i class="fa-solid fa-inbox" style="color:#0369a1;"></i> To Be Bank</div><div class="stat-value sv-sky" style="font-size:15px;">Rs.&nbsp;<?=number_format($status_totals['to_be_bank']['amount'],0)?></div><div class="stat-card-amt"><?=$status_totals['to_be_bank']['count']?> cheques</div></div>
  <div class="stat-card <?=$f_status==='deposited'?'active-filter':''?>" onclick="filterByStatus('deposited')"><div class="stat-label"><i class="fa-solid fa-building-columns" style="color:#2563eb;"></i> Deposited</div><div class="stat-value sv-blue" style="font-size:15px;">Rs.&nbsp;<?=number_format($status_totals['deposited']['amount'],0)?></div><div class="stat-card-amt"><?=$status_totals['deposited']['count']?> cheques</div></div>
<div class="stat-card <?=$f_status==='sent_back'?'active-filter':''?>" onclick="filterByStatus('sent_back')">
  <div class="stat-label"><i class="fa-solid fa-rotate-left" style="color:#7c3aed;"></i> Sent Back</div>
  <div class="stat-value sv-violet" style="font-size:15px;">Rs.&nbsp;<?=number_format($sb_unsettled_balance,0)?></div>
  <div class="stat-card-amt">
    <span class="cnt-big"><?=(int)($sb_bal_data['sb_unsettled_cnt']??0)?></span> cheques
    &nbsp;·&nbsp; </span>
 
  </div>
</div>
  <div class="stat-card <?=$f_status==='cleared'?'active-filter':''?>" onclick="filterByStatus('cleared')"><div class="stat-label"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Cleared</div><div class="stat-value sv-green" style="font-size:15px;">Rs.&nbsp;<?=number_format($status_totals['cleared']['amount'],0)?></div><div class="stat-card-amt"><?=$status_totals['cleared']['count']?> cheques</div></div>
<div class="stat-card <?=$f_status==='returned'?'active-filter':''?>" onclick="filterByStatus('returned')">
  <div class="stat-label"><i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i> Returned</div>
  <div class="stat-value sv-red" style="font-size:15px;">Rs.&nbsp;<?=number_format($rtn_unsettled_balance,0)?></div>
  <div class="stat-card-amt">
    <span class="cnt-big"><?=(int)($rtn_bal_data['rtn_unsettled_cnt']??0)?></span> cheques
    &nbsp;·&nbsp; </span>
   
  </div>
</div>
<div class="stat-card" style="border-color:#d97706;background:#fffbeb;" onclick="toggleSampathOnlyStat()" title="Sampath Super Payment cheques">
  <div class="stat-label"><i class="fa-solid fa-star" style="color:#d97706;"></i> Sampath Cheques</div>
  <div class="stat-value" style="color:#92400e;font-size:15px;">Rs.&nbsp;<?=number_format($sampath_grand_amount,0)?></div>
  <div class="stat-card-amt"><?=$sampath_grand_count?> cheques</div>
</div>
  <div class="stat-card" style="border-color:#6366f1;background:#f5f3ff;" id="filteredStatCard"><div class="stat-label" style="color:#5b21b6;">Filtered Results</div><div class="stat-value sv-violet" id="filteredCount">0</div><div class="stat-card-amt" id="filteredAmt">Rs. 0</div></div>
</div>

<!-- TABLE CARD -->
<div class="table-card tbl-wrap">
  <div class="tbl-loading" id="tblLoading"><div class="tbl-spinner"></div><span>Loading cheques…</span></div>
  <div class="table-toolbar no-print" style="gap:10px;">
    <div class="tbl-title"><i class="fa-solid fa-table-list"></i> Cheque Register <span class="pill p-violet" id="visCount">0 records</span><span class="pill p-violet" id="filterPills"></span></div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <button class="btn-mark-bank" id="markBankBtn" onclick="openBankDateModal()" disabled><i class="fa-solid fa-inbox"></i> Mark as To Be Bank <span class="select-count-badge" id="selCountBadge">0</span></button>
      <button class="btn-deposit" id="depositBtn" onclick="openDepositModal()" disabled><i class="fa-solid fa-arrow-right-to-bracket"></i> Deposit <span class="dep-count-badge" id="depCountBadge">0</span></button>
      <div class="search-bar-wrap">
        <div class="chq-search-box"><i class="fa-solid fa-magnifying-glass si"></i>
          <input type="text" id="chqSearch" placeholder="Search: cheque no, customer, bank, amount…" autocomplete="off" spellcheck="false">
          <button class="clr-btn" id="chqClr" onclick="clearSearch()" title="Clear"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <span class="search-count" id="searchCount"></span>
        <button class="btn-daily-report" onclick="openDailyReportModal()" title="Save current filtered cheques as daily report"><i class="fa-solid fa-clipboard-list"></i> Save Daily Report</button>
        <a href="daily_sent_cheques.php" class="btn-view-reports" title="View saved daily reports"><i class="fa-solid fa-book-open"></i> View Reports</a>
      </div>
    </div>
  </div>
  <div class="table-toolbar no-print" id="pagerWrap" style="justify-content:space-between;padding:7px 14px;display:none;">
    <div class="pager" id="pager"></div>
    <div style="display:flex;align-items:center;gap:10px;"><span class="pager-info" id="pagerInfo"></span>
      <button class="scroll-nav-btn" onclick="scrollToTop()"><i class="fa-solid fa-arrow-up"></i> Top</button>
      <button class="scroll-nav-btn" onclick="scrollToBottom()"><i class="fa-solid fa-arrow-down"></i> Bottom</button>
    </div>
  </div>
  <div class="dt-outer">
  <table class="data-table" id="mainTable">
    <thead><tr>
      <th style="width:50px;" class="no-print tc"><input type="checkbox" id="selectAll" class="chq-select-cb" onchange="toggleSelectAll(this)" title="Select all"></th>
      <th style="width:32px;">#</th>
      <th class="tc">SR Code</th><th class="tc">Delivery Date</th><th class="tc">Received Date</th><th class="tc">Cheque Date</th>
      <th>Cheque No.</th><th class="tc">Mode</th><th>Bank / Branch</th><th class="tc">Bank Code</th><th class="tc">Branch Code</th>
      <th class="tr">Amount</th><th class="tc">Status</th><th class="tc">Aging</th><th class="tc">Bulk Dep.</th><th>T-Code / Customer</th><th>Acc. Holder</th>
      <th class="tc no-print" style="min-width:200px;">Actions</th>
    </tr></thead>
    <tbody id="mainTbody">
      <tr><td colspan="18" class="state-box" style="padding:60px 20px;text-align:center;color:#9ca3af;">
        <i class="fa-solid fa-spinner fa-spin" style="font-size:32px;display:block;margin-bottom:12px;opacity:.5;"></i><p>Loading cheques…</p>
      </td></tr>
    </tbody>
    <tfoot><tr>
      <td colspan="11" class="no-print"></td>
      <td class="tr">Rs.&nbsp;<span id="footerTotal">0.00</span></td>
      <td colspan="6" style="font-size:11px;opacity:.65;">TOTAL — <span id="footerCount">0</span> CHEQUES</td>
    </tr></tfoot>
  </table>
  </div>
  <div class="table-toolbar no-print" id="pagerWrapBottom" style="justify-content:space-between;padding:7px 14px;display:none;border-top:1px solid #f0f0f0;">
    <div class="pager" id="pagerBottom"></div>
    <div style="display:flex;align-items:center;gap:10px;"><span class="pager-info" id="pagerInfoBottom"></span>
      <button class="scroll-nav-btn" onclick="scrollToTop()"><i class="fa-solid fa-arrow-up"></i> Top</button>
      <button class="scroll-nav-btn" onclick="scrollToBottom()"><i class="fa-solid fa-arrow-down"></i> Bottom</button>
    </div>
  </div>
</div>

<!-- MARK AS TO-BE-BANK MODAL -->
<div id="bankDateModal" style="display:none;position:fixed;inset:0;z-index:999998;background:rgba(0,0,0,.6);align-items:center;justify-content:center;">
  <div class="bdm-box">
    <div class="bdm-header"><div class="bdm-title"><i class="fa-solid fa-inbox"></i> Mark as To Be Bank</div><button class="bdm-close" onclick="closeBankDateModal()"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="bdm-body">
      <label class="bdm-label"><i class="fa-solid fa-calendar-day"></i> To Be Bank Date</label>
      <input type="date" class="bdm-input" id="toBeBankDate" value="<?=date('Y-m-d')?>">
      <div class="bdm-note" id="bdmNote">Select verified cheques and choose the date to send to bank.</div>
    </div>
    <div class="bdm-foot"><button class="bdm-btn-cancel" onclick="closeBankDateModal()">Cancel</button>
      <button class="bdm-btn-confirm" id="bdmConfirmBtn" onclick="confirmMarkToBeBank()"><i class="fa-solid fa-circle-check"></i> Confirm Mark as To Be Bank</button>
    </div>
  </div>
</div>

<!-- DEPOSIT MODAL -->
<div id="depositModal">
  <div class="depmodal-box">
    <div class="depmodal-header">
      <div class="depmodal-title"><i class="fa-solid fa-arrow-right-to-bracket"></i> Deposit Cheques <span id="depModalChequeCount" style="background:rgba(255,255,255,.2);border-radius:6px;padding:2px 10px;font-size:12px;font-weight:600;"></span></div>
      <button class="depmodal-close" onclick="closeDepositModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="depmodal-body">
      <div class="depmodal-summary" id="depSummary">
        <div class="dep-sum-item"><span class="dep-sum-lbl"><i class="fa-solid fa-hashtag"></i> Cheques Selected</span><span class="dep-sum-val" id="depSumCount">0</span></div>
        <div class="dep-sum-item"><span class="dep-sum-lbl"><i class="fa-solid fa-info-circle"></i> Status Required</span><span class="dep-sum-val" style="font-size:12px;color:#0f766e;">To Be Bank only</span></div>
      </div>
      <div class="dep-fields-row">
        <div class="dep-field"><label><i class="fa-solid fa-calendar-check"></i> Deposit Date</label><input type="date" id="depDate" value="<?=date('Y-m-d')?>"></div>
        <div class="dep-field"><label><i class="fa-solid fa-layer-group"></i> Deposit Type</label>
          <select id="depTypeSelect" style="border:1.5px solid #ccfbf1;border-radius:8px;padding:10px 12px;font-size:14px;font-family:inherit;color:#1f2937;outline:none;background:#f0fdfa;">
            <option value="normal">Normal Deposit</option><option value="bulk">Bulk Deposit</option><option value="normal_bulk">Normal Bulk Deposit</option>
          </select></div>
      </div>
      <div class="dep-type-row" id="depTypeCards">
        <div class="dep-type-card selected" id="depTypeCard_normal" onclick="selectDepType('normal')"><span class="dep-type-icon">📄</span><div><div class="dep-type-label">Normal Deposit</div><div class="dep-type-desc">Individual cheque deposit</div></div></div>
        <div class="dep-type-card" id="depTypeCard_bulk" onclick="selectDepType('bulk')"><span class="dep-type-icon">📦</span><div><div class="dep-type-label">Bulk Deposit</div><div class="dep-type-desc">Multiple cheques bundled</div></div></div>
        <div class="dep-type-card" id="depTypeCard_normal_bulk" onclick="selectDepType('normal_bulk')"><span class="dep-type-icon">🏦</span><div><div class="dep-type-label">Normal Bulk Deposit</div><div class="dep-type-desc">Normal with multiple cheques on one slip</div></div></div>
      </div>
      <div class="depmodal-section">
        <div class="depmodal-section-title"><i class="fa-solid fa-landmark"></i> Select Company Bank Account</div>
        <div class="dep-accounts-grid" id="depAccountsList"><div class="dep-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading accounts…</div></div>
      </div>
    </div>
    <div class="depmodal-foot">
      <button class="dep-btn-cancel" onclick="closeDepositModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="dep-btn-confirm" id="depConfirmBtn" onclick="confirmDeposit()"><i class="fa-solid fa-circle-check"></i> Confirm Deposit</button>
    </div>
  </div>
</div>

<!-- STATUS UPDATE MODAL -->
<div id="statusUpdateModal">
  <div class="sum-box">
    <div class="sum-header">
      <div class="sum-title"><i class="fa-solid fa-arrow-right-arrow-left"></i> Update Cheque Status <span id="sumChequeNo" style="background:rgba(255,255,255,.15);border-radius:6px;padding:2px 12px;font-size:12px;font-family:'Courier New',monospace;"></span></div>
      <button class="sum-close" onclick="closeStatusUpdateModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="sum-body">
      <div class="sum-info">
        <div class="sum-info-item"><span class="sum-info-lbl">T-Code</span><span class="sum-info-val" id="sum_tcode">—</span></div>
        <div class="sum-info-item"><span class="sum-info-lbl">Customer</span><span class="sum-info-val" id="sum_cust">—</span></div>
      </div>
      <div class="sum-current-status">
        <div class="sum-current-status-lbl"><i class="fa-solid fa-circle-dot"></i> Current Status</div>
        <div id="sumCurrentBadge"></div>
      </div>
      <div id="sumChangeLbl" style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;"><i class="fa-solid fa-arrow-right"></i> Change To</div>
      <div class="sum-status-grid" id="sumStatusGrid"></div>
      <div class="sum-reason-section" id="sumReasonSection">
        <label><i class="fa-solid fa-comment-dots"></i> Reason for reverting Deposited → To Be Bank</label>
        <div id="sum-reason-select2-wrap">
          <select id="sumReasonSelect" style="width:100%;"><option value="">— Select a reason —</option></select>
        </div>
      </div>
      
      <div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:9px;padding:12px 14px;margin-bottom:14px;">
        <label style="font-size:11px;font-weight:700;color:#0369a1;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:6px;">
          <i class="fa-solid fa-calendar-day"></i> Action Date <span style="color:#dc2626;">*</span>
        </label>
        <input type="date" id="sumActionDate" style="border:1.5px solid #bae6fd;border-radius:8px;padding:9px 12px;font-size:14px;font-family:inherit;color:#1f2937;outline:none;width:100%;background:#fff;transition:border .2s;"
          onfocus="this.style.borderColor='#0369a1';this.style.boxShadow='0 0 0 3px rgba(3,105,161,.1)'"
          onblur="this.style.borderColor='#bae6fd';this.style.boxShadow=''">
        <div style="font-size:11px;color:#6b7280;margin-top:5px;"><i class="fa-solid fa-circle-info"></i> This date will be saved as the log timestamp.</div>
      </div>
    </div>
    <div class="sum-foot">
      <button class="sum-btn-cancel" onclick="closeStatusUpdateModal()">Cancel</button>
      <button class="sum-btn-update" id="sumUpdateBtn" onclick="confirmStatusUpdate()" disabled><i class="fa-solid fa-circle-check"></i> Update Status</button>
    </div>
  </div>
  
</div>

<!-- CHEQUE LOGS MODAL -->
<div id="logsModal" style="display:none;position:fixed;inset:0;z-index:999997;background:rgba(0,0,0,.65);align-items:center;justify-content:center;padding:20px;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:680px;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.4);overflow:hidden;animation:vmodalIn .2s ease;">
    <div style="background:linear-gradient(135deg,#1e1b4b,#312e81);padding:14px 20px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
      <div style="color:#fff;font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px;"><i class="fa-solid fa-clock-rotate-left"></i> Cheque Activity Logs <span id="logsModalChequeNo" style="background:rgba(255,255,255,.15);border-radius:6px;padding:2px 12px;font-size:12px;font-family:'Courier New',monospace;"></span></div>
      <button onclick="closeLogsModal()" style="background:rgba(255,255,255,.15);border:none;border-radius:7px;color:#fff;width:28px;height:28px;cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div id="logsBody" style="overflow-y:auto;flex:1;padding:18px;">
      <div id="logsLoading" style="text-align:center;padding:30px;color:#6b7280;font-size:13px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading logs…</div>
      <div id="logsContent" style="display:none;"></div>
    </div>
  </div>
</div>

<!-- DAILY REPORT MODAL -->
<div id="dailyReportModal">
  <div class="drm-box">
    <div class="drm-header">
      <div class="drm-title"><i class="fa-solid fa-clipboard-list"></i> Save Daily Cheque Report</div>
      <button class="drm-close" onclick="closeDailyReportModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="drm-body">
      <div class="drm-summary">
        <div class="drm-sum-item"><span class="drm-sum-lbl"><i class="fa-solid fa-hashtag"></i> Cheques</span><span class="drm-sum-val" id="drmChequeCount">0</span></div>
        <div class="drm-sum-item"><span class="drm-sum-lbl"><i class="fa-solid fa-coins"></i> Total Amount</span><span class="drm-sum-val" id="drmTotalAmt">Rs. 0</span></div>
      </div>
      <div class="drm-note"><i class="fa-solid fa-info-circle" style="color:#7c3aed;"></i> All cheques matching the current filter will be saved as a daily report snapshot.</div>
      <div class="drm-field">
        <label><i class="fa-solid fa-calendar-day"></i> Sent Date</label>
        <input type="date" id="drmSentDate" value="<?=date('Y-m-d')?>">
      </div>
      <div class="drm-field">
        <label><i class="fa-solid fa-pen-nib"></i> Remark (optional)</label>
        <textarea id="drmRemark" placeholder="E.g. Daily cheque batch for bank deposit…"></textarea>
      </div>
    </div>
    <div class="drm-foot">
      <button class="drm-btn-cancel" onclick="closeDailyReportModal()">Cancel</button>
      <button class="drm-btn-save" id="drmSaveBtn" onclick="saveDailyReport()"><i class="fa-solid fa-floppy-disk"></i> Save Report</button>
    </div>
  </div>
</div>

<div id="toast"></div>

<!-- VIEW UPLOADED DETAILS MODAL — fullscreen, matches verifyModal -->
<!-- CHQ-PATCH5-VIEWMODAL -->
<div id="viewModal">
  <div class="viewmodal-box">
    <div class="viewmodal-header">
      <div class="viewmodal-title">
        <i class="fa-solid fa-eye"></i> Verified Cheque Details
        <span id="viewModalChequeNo" style="background:rgba(255,255,255,.15);border-radius:6px;padding:2px 12px;font-size:13px;font-family:'Courier New',monospace;"></span>
        <span class="verified-badge" style="width:22px;height:22px;font-size:10px;"><i class="fa-solid fa-check"></i></span>
      </div>
      <button class="viewmodal-close" onclick="closeViewModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="viewmodal-body">

      <!-- Customer Seal & Signature -->
      <div class="viewmodal-section" style="margin-bottom:8px;">
        <div class="viewmodal-section-title">
          <i class="fa-solid fa-user-check" style="color:#059669;"></i> Customer Seal &amp; Signature
        </div>
        <div class="viewmodal-cust-row">
          <div class="viewmodal-cust-card">
            <div class="viewmodal-cust-label"><i class="fa-solid fa-stamp"></i> Customer Seal</div>
            <div class="viewmodal-cust-wrap" id="vm_seal_wrap">
              <div class="viewmodal-cust-empty"><i class="fa-solid fa-stamp"></i><span>No seal on file</span></div>
            </div>
          </div>
          <div class="viewmodal-cust-card">
            <div class="viewmodal-cust-label"><i class="fa-solid fa-signature"></i> Customer Signature</div>
            <div class="viewmodal-cust-wrap" id="vm_sig_wrap">
              <div class="viewmodal-cust-empty"><i class="fa-solid fa-signature"></i><span>No signature on file</span></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Cheque Front & Back images -->
      <div class="viewmodal-section" style="margin-bottom:0;">
        <div class="viewmodal-section-title">
          <i class="fa-solid fa-money-check" style="color:#0369a1;"></i> Cheque Images — Front &amp; Back
        </div>
        <div class="viewmodal-images-grid">
          <div class="viewmodal-img-card">
            <div class="viewmodal-img-label"><i class="fa-solid fa-image"></i> Cheque Front</div>
            <div class="viewmodal-img-wrap" id="vm_front_wrap">
              <div class="viewmodal-img-empty"><i class="fa-regular fa-image"></i><span>No front image</span></div>
            </div>
          </div>
          <div class="viewmodal-img-card">
            <div class="viewmodal-img-label"><i class="fa-solid fa-image"></i> Cheque Back</div>
            <div class="viewmodal-img-wrap" id="vm_back_wrap">
              <div class="viewmodal-img-empty"><i class="fa-regular fa-image"></i><span>No back image</span></div>
            </div>
          </div>
        </div>
      </div>

      <!-- ★ CHEQUE INFO STRIP -->
      <div class="chq-info-strip" style="margin-bottom:0;margin-top:14px;">
        <div class="chq-info-strip-item">
          <span class="chq-info-strip-lbl"><i class="fa-solid fa-hashtag"></i> Cheque No.</span>
          <span class="chq-info-strip-val" id="vm_cheque_no_val">—</span>
        </div>
        <div class="chq-info-strip-item">
          <span class="chq-info-strip-lbl"><i class="fa-regular fa-calendar-check"></i> Received Date</span>
          <span class="chq-info-strip-val" id="vm_rec_date_val">—</span>
        </div>
        <div class="chq-info-strip-item">
          <span class="chq-info-strip-lbl"><i class="fa-regular fa-calendar"></i> Cheque Date</span>
          <span class="chq-info-strip-val" id="vm_cheque_date_val">—</span>
        </div>
        <div class="chq-info-strip-item">
          <span class="chq-info-strip-lbl"><i class="fa-solid fa-building-columns"></i> Bank</span>
          <span class="chq-info-strip-val" id="vm_bank_val">—</span>
        </div>
        <div class="chq-info-strip-item">
          <span class="chq-info-strip-lbl"><i class="fa-solid fa-code-branch"></i> Branch</span>
          <span class="chq-info-strip-val" id="vm_branch_val">—</span>
        </div>
        <div class="chq-info-strip-item">
          <span class="chq-info-strip-lbl"><i class="fa-solid fa-coins"></i> Amount (Rs.)</span>
          <span class="chq-info-strip-val" id="vm_view_amt">—</span>
        </div>
      </div>

      <!-- ★ DETAILS SECTION — blue card, very bottom -->
      <div class="chq-details-section" style="margin-top:14px;">
        <div class="chq-details-title"><i class="fa-solid fa-circle-info"></i> Cheque Details</div>
        <div class="chq-details-grid">
          <div class="chq-details-item">
            <span class="chq-details-lbl"><i class="fa-solid fa-id-badge"></i> SR Code</span>
            <span class="chq-details-val plain" id="vdv_sr_code">—</span>
          </div>
          <div class="chq-details-item">
            <span class="chq-details-lbl"><i class="fa-solid fa-user-tag"></i> T-Code</span>
            <span class="chq-details-val plain" id="vdv_tcode">—</span>
          </div>
          <div class="chq-details-item">
            <span class="chq-details-lbl"><i class="fa-solid fa-store"></i> Customer</span>
            <span class="chq-details-val plain" id="vdv_customer">—</span>
          </div>
          <div class="chq-details-item">
            <span class="chq-details-lbl"><i class="fa-solid fa-truck"></i> Delivery Date</span>
            <span class="chq-details-val plain" id="vdv_delivery_date">—</span>
          </div>
          <div class="chq-details-item">
            <span class="chq-details-lbl"><i class="fa-solid fa-layer-group"></i> Cheque Mode</span>
            <span class="chq-details-val plain" id="vdv_mode">—</span>
          </div>
          <div class="chq-details-item">
            <span class="chq-details-lbl"><i class="fa-solid fa-circle-half-stroke"></i> Status</span>
            <span class="chq-details-val status-val" id="vdv_status">—</span>
          </div>
        </div>
      </div>

    </div>

    <div class="viewmodal-foot">
      <button class="vbtn vbtn-cancel" onclick="closeViewModal()" style="flex:1;justify-content:center;">
        <i class="fa-solid fa-xmark"></i> Close
      </button>
    </div>
  </div>
</div>

<!-- FULLSCREEN IMAGE OVERLAY -->
<div id="imgFullscreen" onclick="closeFullscreen()">
  <button class="fs-close" onclick="closeFullscreen()"><i class="fa-solid fa-xmark"></i></button>
  <img id="fsImg" src="" alt="Fullscreen">
</div>

<script>
/* ══ Select2 init ══ */
$(function(){
    $('#selSR').select2({placeholder:'— All SR —',allowClear:true,width:'100%'});
    $('#selStatus').select2({placeholder:'— All Status —',allowClear:true,width:'100%',closeOnSelect:false});
    $('#selBank').select2({placeholder:'— All Banks —',allowClear:true,width:'100%'});
});

function toggleFilter(){const h=document.getElementById('filterHeader'),b=document.getElementById('filterBody'),i=document.getElementById('filterIcon');const o=b.classList.contains('open');if(o){b.classList.remove('open');h.classList.remove('open');i.classList.remove('open');}else{b.classList.add('open');h.classList.add('open');i.classList.add('open');}}
function filterByStatus(s){if(window.$){$('#selStatus').val(s===''?null:[s]).trigger('change');}else{document.getElementById('selStatus').value=s;}currentPage=1;fetchRows();}

function exportExcel(){const f=getFilters();delete f.page;delete f.per;const p=new URLSearchParams(f);window.open('export_cheques_excel.php?'+p.toString(),'_blank');}

/* ══════════════════════════════════════════════════════
   SERVER-SIDE PAGING + SEARCH ENGINE
══════════════════════════════════════════════════════ */
const PAGE_SIZE=200;const BANKS = <?php
/* BRANCH_CODE_PATCH_V1_APPLIED */
/* PATCHED_V2 */ echo json_encode($edit_banks_list); ?>;let currentPage=1;let currentSearch='';let fetchTimer=null;let lastFetchController=null;
const selectedToBeBankIds = new Set();
const selectedDepositIds  = new Set();

function getFilters(){const stVals=window.$?($('#selStatus').val()||[]):(document.getElementById('selStatus')?.value?[document.getElementById('selStatus').value]:[]);return{sr_code:document.getElementById('selSR')?.value||'',status:stVals.join(','),bank_code:document.getElementById('selBank')?.value||'',t_code:document.getElementById('fTcode')?.value||'',date_from:document.getElementById('fFrom')?.value||'',date_to:document.getElementById('fTo')?.value||'',del_date_from:document.getElementById('fDelFrom')?.value||'',del_date_to:document.getElementById('fDelTo')?.value||'',rec_date_from:document.getElementById('fRecFrom')?.value||'',rec_date_to:document.getElementById('fRecTo')?.value||'',sampath_only:document.getElementById('fSampathOnly')?.checked?'1':'0',q:currentSearch,page:currentPage,per:PAGE_SIZE};}

function applyFilters(){currentPage=1;fetchRows();}

function toggleSampathOnlyStat(){const cb=document.getElementById('fSampathOnly');if(cb){cb.checked=!cb.checked;}currentPage=1;fetchRows();}

function clearFilters(){['selSR','selStatus','selBank'].forEach(id=>{document.getElementById(id).value='';if(window.$)$('#'+id).val('').trigger('change');});['fTcode','fFrom','fTo','fDelFrom','fDelTo','fRecFrom','fRecTo'].forEach(id=>{document.getElementById(id).value='';});const sOnly=document.getElementById('fSampathOnly');if(sOnly)sOnly.checked=false;currentPage=1;currentSearch='';document.getElementById('chqSearch').value='';document.getElementById('chqClr').classList.remove('show');fetchRows();}

async function fetchRows(){
    if(lastFetchController)lastFetchController.abort();
    lastFetchController=new AbortController();showLoading(true);
    const f=getFilters();const params=new URLSearchParams({ajax:'cheque_rows',...f});
    try{const res=await fetch('cheques.php?'+params.toString(),{signal:lastFetchController.signal});
        if(!res.ok)throw new Error('HTTP '+res.status);const data=await res.json();
        if(!data.success)throw new Error(data.error||'Server error');renderRows(data);
    }catch(e){if(e.name==='AbortError')return;
        document.getElementById('mainTbody').innerHTML=`<tr><td colspan="18" style="text-align:center;padding:40px;color:#dc2626;"><i class="fa-solid fa-triangle-exclamation"></i> Error: ${esc(e.message)}</td></tr>`;
    }finally{showLoading(false);}
}

function showLoading(on){const el=document.getElementById('tblLoading');if(el)el.classList.toggle('show',on);}

const status_labels_js={pending:'Pending',to_be_bank:'To Be Bank',deposited:'Deposited',sent_back:'Sent Back',cleared:'Cleared',returned:'Returned'};
const all_statuses_js=['pending','to_be_bank','deposited','sent_back','cleared','returned'];
const status_icons_js={pending:'fa-clock',to_be_bank:'fa-inbox',deposited:'fa-building-columns',sent_back:'fa-rotate-left',cleared:'fa-circle-check',returned:'fa-circle-xmark'};

function fmtDate(d){if(!d||d==='0000-00-00'||d==='0000-00-00 00:00:00')return '<span class="date-txt empty">—</span>';const dt=new Date(d.replace(' ','T'));const m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];return `<span class="date-txt">${String(dt.getDate()).padStart(2,'0')} ${m[dt.getMonth()]} ${dt.getFullYear()}</span>`;}

function modeBadge(cm_raw){const cm=(cm_raw||'').toLowerCase().trim();if(['payee_only','payee only','payee','account payee','a/c payee','ac payee','order'].includes(cm))return `<span class="mode-badge mode-payee">Payee Only</span>`;if(['cash','bearer','open'].includes(cm))return `<span class="mode-badge mode-cash">Bearer / Cash</span>`;if(['third_party_cash','third party cash','3rd party','third party'].includes(cm))return `<span class="mode-badge mode-3party">3rd Party</span>`;if(cm_raw&&cm_raw!=='')return `<span class="mode-badge mode-payee">${esc(cm_raw)}</span>`;return `<span style="color:#d1d5db;font-size:11px;">—</span>`;}

function statusCell(id, st, row) {
    const lbl = status_labels_js[st] || st;
    const icon = status_icons_js[st] || 'fa-circle-dot';
    const cheqAmt = parseFloat(row.total_amount || 0);

    /* ── Sent Back settlement display ── */
    if (st === 'sent_back') {
        const sbSettled = parseInt(row.sb_settled || 0);
        const sbPaid = parseFloat(row.sb_settlement_amount || 0);
        const sbBal = Math.max(0, cheqAmt - sbPaid);
        if (sbSettled) {
            return `<div style="text-align:center;">
               // REPLACE WITH:
<span class="status-badge-clickable cleared" style="cursor:default;opacity:.75;pointer-events:none;background:#d1fae5;color:#065f46;border-color:#6ee7b7;" title="RTN Settled — status locked">
                  <i class="fa-solid fa-circle-check"></i> SB Settled
                </span>
                <div style="font-size:10px;color:#065f46;margin-top:3px;">Paid: Rs.${sbPaid.toFixed(2)}</div>
            </div>`;
        } else if (sbPaid > 0) {
            return `<div style="text-align:center;">
                <span class="status-badge-clickable sent_back" onclick="openStatusUpdateModal(${id},'${st}')" title="Click to change status">
                  <i class="fa-solid fa-rotate-left"></i> Sent Back
                </span>
                <div style="font-size:10px;color:#92400e;margin-top:3px;">Paid: Rs.${sbPaid.toFixed(2)} | Bal: Rs.${sbBal.toFixed(2)}</div>
            </div>`;
        } else {
            return `<div style="text-align:center;">
                <span class="status-badge-clickable sent_back" onclick="openStatusUpdateModal(${id},'${st}')" title="Click to change status">
                  <i class="fa-solid fa-rotate-left"></i> Sent Back
                </span>
                <div style="font-size:10px;color:#9ca3af;margin-top:3px;">Bal: Rs.${cheqAmt.toFixed(2)}</div>
            </div>`;
        }
    }

    /* ── Returned settlement display ── */
    if (st === 'returned') {
        const rtnSettled = parseInt(row.return_settled || 0);
        const rtnPaid = parseFloat(row.return_settlement_amount || 0);
        const rtnBal = Math.max(0, cheqAmt - rtnPaid);
        if (rtnSettled) {
            return `<div style="text-align:center;">
                <span class="status-badge-clickable cleared" onclick="openStatusUpdateModal(${id},'${st}')" title="Click to view" style="background:#d1fae5;color:#065f46;border-color:#6ee7b7;">
                  <i class="fa-solid fa-circle-check"></i> RTN Settled
                </span>
                <div style="font-size:10px;color:#065f46;margin-top:3px;">Paid: Rs.${rtnPaid.toFixed(2)}</div>
            </div>`;
        } else if (rtnPaid > 0) {
            return `<div style="text-align:center;">
               <span class="status-badge-clickable returned" style="cursor:default;opacity:.75;pointer-events:none;" title="Returned — status locked">
  <i class="fa-solid fa-circle-xmark"></i> Returned
</span>
<div style="font-size:10px;color:#9ca3af;margin-top:3px;">Paid: Rs.${rtnPaid.toFixed(2)} | Bal: Rs.${rtnBal.toFixed(2)}</div>
            </div>`;
        } else {
            return `<div style="text-align:center;">
          <span class="status-badge-clickable returned" style="cursor:default;opacity:.75;pointer-events:none;" title="Returned — status locked">
  <i class="fa-solid fa-circle-xmark"></i> Returned
</span>
<div style="font-size:10px;color:#92400e;margin-top:3px;">Bal: Rs.${cheqAmt.toFixed(2)}</div>
            </div>`;
        }
    }

    
// REPLACE WITH:
if (st === 'cleared') {
    return `<span class="status-badge-clickable cleared" style="cursor:default;opacity:.75;pointer-events:none;" title="Cleared — status locked"><i class="fa-solid ${icon}"></i> ${lbl}</span>`;
}
return `<span class="status-badge-clickable ${st}" onclick="openStatusUpdateModal(${id},'${st}')" title="Click to change status"><i class="fa-solid ${icon}"></i> ${lbl}</span>`;
}

function highlightTerm(text,term){if(!term||!text)return esc(text||'');const re=new RegExp('('+escRe(term)+')','gi');return esc(text).replace(re,'<mark class="hl">$1</mark>');}

function renderRows(data){
    const tbody=document.getElementById('mainTbody');const rows=data.rows||[];const total=data.total||0;
    const pages=data.pages||1;const g_amt=data.grand_total||0;const srch=currentSearch;const offset=(currentPage-1)*PAGE_SIZE;

    document.getElementById('visCount').textContent=total+' records';
    document.getElementById('footerCount').textContent=total;
    document.getElementById('footerTotal').textContent=g_amt.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('searchCount').textContent=srch?`${total} found`:'';
    const fCard=document.getElementById('filteredStatCard');
    if(fCard){fCard.style.display='';document.getElementById('filteredCount').textContent=total;document.getElementById('filteredAmt').textContent='Rs. '+g_amt.toLocaleString('en-US',{minimumFractionDigits:0,maximumFractionDigits:0});}

    if(!rows.length){tbody.innerHTML=`<tr><td colspan="18"><div class="state-box"><i class="fa-solid fa-inbox"></i><p>No cheques found.</p><small>Try adjusting your filters or search.</small></div></td></tr>`;document.getElementById('pagerWrap').style.display='none';document.getElementById('pagerWrapBottom').style.display='none';return;}

    let html='';
    rows.forEach((row,idx)=>{
        const id=row.id;const sid=String(id);let st=(row.status||'pending').toLowerCase().trim();if(st==='bounced')st='returned';
        const verified=parseInt(row.verified||0);const bulk_flag=parseInt(row.bulk_flag||0);
        const is_tbb=(st==='to_be_bank');const tcode_val=row.t_code||'';const cust_name=row.customer_name||'';
        const acc_name=row.acc_holder_name||'';const cheque_no=row.cheque_no||'';const rn=offset+idx+1;
        const frontImg=row.cheque_front_image||'';const backImg=row.cheque_back_image||'';
        const isSampath=!!row.sampath;

        let cbHtml='';
        if(verified&&!is_tbb) cbHtml=`<input type="checkbox" class="chq-select-cb row-select-cb" data-id="${sid}" data-type="to_be_bank" onchange="onToBeBankCb(this)" title="Select for To Be Bank">`;
        else if(is_tbb) cbHtml=`<input type="checkbox" class="chq-select-cb row-dep-cb" data-id="${sid}" data-type="deposit" onchange="onDepositCb(this)" title="Select for Deposit">`;
        else cbHtml=`<span style="width:16px;display:inline-block;"></span>`;

        let actHtml='';
       if(st === 'sent_back' || st === 'cleared' || st === 'returned'){
    const disabledLabel = st === 'sent_back' ? 'Sent Back' : st === 'cleared' ? 'Cleared' : 'Returned';
    actHtml+=`<span class="edit-row-btn" style="opacity:.4;cursor:not-allowed;pointer-events:none;background:#9ca3af;" title="Cannot edit — ${disabledLabel}"><i class="fa-solid fa-pen-to-square"></i> Edit</span>`;
} else {
    actHtml+=`<a href="cheque_edit.php?id=${id}" class="edit-row-btn" title="Edit"><i class="fa-solid fa-pen-to-square"></i> Edit</a>`;
}
        if(verified){
            actHtml+=`<span class="verified-badge" title="Verified"><i class="fa-solid fa-check"></i></span>`;
            actHtml+=`<button class="view-verified-btn" onclick="openViewModal(${id},'${esc(cheque_no).replace(/'/g,"\\'")}','${esc(tcode_val).replace(/'/g,"\\'")}','${esc(cust_name).replace(/'/g,"\\'")}','${parseFloat(row.total_amount||0).toFixed(2)}','${esc(frontImg).replace(/'/g,"\\'")}','${esc(backImg).replace(/'/g,"\\'")}')" title="View details"><i class="fa-solid fa-eye"></i> View</button>`;
        } else {
            actHtml+=`<button class="verify-btn" onclick="openVerifyModal(${id})" title="Verify"><i class="fa-solid fa-shield-halved"></i> Verify</button>`;
        }
        if(is_tbb) actHtml+=`<button class="dep-row-btn" onclick="openDepositModalSingle(${id})" title="Deposit"><i class="fa-solid fa-arrow-right-to-bracket"></i> Deposit</button>`;

let agingHtml='<span style="color:#d1d5db;">—</span>';
if(row.cheque_date&&row.cheque_date!=='0000-00-00'&&['pending','to_be_bank','deposited'].includes(st)){
    const _cd=new Date(row.cheque_date);
    const _days=Math.floor((Date.now()-_cd)/86400000);
    if(_days>0){
        const _ac=_days>90?'#dc2626':_days>30?'#d97706':'#374151';
        agingHtml=`<span style="font-weight:700;color:${_ac};">${_days}d</span>`;
    }
}
        const srCellHtml = isSampath
            ? `<span class="sampath-pill" title="${esc(row.sampath_description||'Sampath Super Payment cheque')}"><i class="fa-solid fa-star"></i> Sampath${row.sampath_payment_id?' #'+esc(row.sampath_payment_id):''}</span>`
            : `<span class="sr-pill">${esc(row.sr_code||'—')}</span>`;
        html+=`
        <tr class="main-row${isSampath?' sampath-row':''}" id="row-${id}" data-id="${id}" data-tcode="${esc(tcode_val)}" data-chequeOrig="${esc(cheque_no)}" data-verified="${verified}" data-status="${st}" data-cheqno="${esc(cheque_no)}" data-cust="${esc(cust_name)}" data-amt="${parseFloat(row.total_amount||0).toFixed(2)}" data-front="${esc(frontImg)}" data-back="${esc(backImg)}"data-chequedate="${esc(row.cheque_date||'')}" data-chequedate="${esc(row.cheque_date||'')}" data-bankcode="${esc(row.bank_code||'')}" data-bankname="${esc(row.bank_name||'')}" data-branchcode="${esc(row.branch_code||'')}" data-branchname="${esc(row.branch_name||'')}" data-recdate="${esc((row.received_date||'').split(' ')[0])}" data-mode="${esc(row.cheque_mode||'')}" data-sb-settled="${row.sb_settled||0}" data-sb-amt="${row.sb_settlement_amount||0}" data-rtn-settled="${row.return_settled||0}" data-rtn-amt="${row.return_settlement_amount||0}">
          <td class="tc no-print" style="padding:7px 6px;"><div style="display:flex;align-items:center;gap:4px;">${cbHtml}
            <button class="expand-btn" id="expbtn-${id}" onclick="toggleSubRow(${id},'${esc(tcode_val).replace(/'/g,"\\'")}' )" title="Show invoices & bank accounts"><i class="fa-solid fa-chevron-down"></i></button></div></td>
          <td style="color:#9ca3af;font-size:11px;font-weight:600;" class="row-num">${rn}</td>
          <td class="tc">${srCellHtml}</td>
          <td class="tc">${fmtDate(row.delivery_date)}</td>
          <td class="tc">${fmtDate(row.received_date)}</td>
          <td class="tc">${fmtDate(row.cheque_date)}</td>
          <td><span class="mono chq-no" style="color:#4338ca;font-size:12.5px;cursor:pointer;text-decoration:underline dotted;" onclick="openLogsModal(${id},'${esc(cheque_no).replace(/'/g,"\\'")}' )" title="Click to view logs">${highlightTerm(cheque_no,srch)}</span></td>
          <td class="tc">${modeBadge(row.cheque_mode)}</td>
          <td><div style="font-size:12px;font-weight:600;color:#1f2937;white-space:nowrap;">${highlightTerm(row.bank_name||'—',srch)}</div>${row.branch_name?`<div class="cust-sub">${highlightTerm(row.branch_name,srch)}</div>`:''}</td>
          <td class="tc"><span class="mono" style="font-size:11px;">${highlightTerm(row.bank_code||'—',srch)}</span></td>
          <td class="tc"><span class="mono" style="font-size:11px;">${esc(row.branch_code||'—')}</span></td>
          <td class="tr"><span class="amt-cell">Rs.&nbsp;${parseFloat(row.total_amount||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</span></td>
<td class="tc">${statusCell(id,st,row)}</td>
          <td class="tc">${agingHtml}</td>
          <td class="tc"><div class="bulk-wrap" style="justify-content:center;"><input type="checkbox" class="bulk-check" id="bulk-${id}" ${bulk_flag?'checked':''} onchange="markDirty(${id})"><label for="bulk-${id}" class="bulk-label">${bulk_flag?'Yes':'No'}</label></div></td>
          <td><span class="mono" style="font-size:11.5px;color:#4338ca;">${highlightTerm(tcode_val||'—',srch)}</span>${cust_name?`<div class="cust-sub" title="${esc(cust_name)}">${highlightTerm(cust_name,srch)}</div>`:''}</td>
          <td>${acc_name?`<span class="acc-ro">${esc(acc_name)}</span>`:`<span class="acc-ro none">expand ↓</span>`}</td>
          <td class="tc no-print"><div style="display:flex;align-items:center;justify-content:center;gap:5px;flex-wrap:wrap;">${actHtml}</div></td>
        </tr>
        <tr class="sub-row" id="sub-${id}"><td colspan="18">
          <div class="sub-loading" id="subldr-${id}" style="display:none;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>
          <div class="sub-shell" id="subcnt-${id}" style="display:none;"></div>
        </td></tr>`;
    });
    tbody.innerHTML=html;
    buildPager(pages,total,offset,Math.min(offset+PAGE_SIZE,total));
    document.querySelectorAll('.row-select-cb').forEach(cb=>{
        if(selectedToBeBankIds.has(cb.dataset.id)) cb.checked=true;
    });
    document.querySelectorAll('.row-dep-cb').forEach(cb=>{
        if(selectedDepositIds.has(cb.dataset.id)) cb.checked=true;
    });
    const sa=document.getElementById('selectAll');if(sa)sa.checked=false;
    updateSelCount();updateDepCount();
}

function buildPager(pages,total,start,end){
    const pT=document.getElementById('pager'),iT=document.getElementById('pagerInfo'),wT=document.getElementById('pagerWrap');
    const pB=document.getElementById('pagerBottom'),iB=document.getElementById('pagerInfoBottom'),wB=document.getElementById('pagerWrapBottom');
    if(pages<=1){if(wT)wT.style.display='none';if(wB)wB.style.display='none';return;}
    if(wT)wT.style.display='flex';if(wB)wB.style.display='flex';
    const infoText=`Showing ${start+1}–${end} of ${total}`;if(iT)iT.textContent=infoText;if(iB)iB.textContent=infoText;
    let h=`<button class="pager-btn" onclick="goPage(${currentPage-1})" ${currentPage===1?'disabled':''}>‹ Prev</button>`;
    let lo=Math.max(1,currentPage-3),hi=Math.min(pages,currentPage+3);
    if(lo>1)h+=`<button class="pager-btn" onclick="goPage(1)">1</button>${lo>2?'<span style="color:#9ca3af;padding:0 3px;">…</span>':''}`;
    for(let p=lo;p<=hi;p++)h+=`<button class="pager-btn ${p===currentPage?'active':''}" onclick="goPage(${p})">${p}</button>`;
    if(hi<pages)h+=`${hi<pages-1?'<span style="color:#9ca3af;padding:0 3px;">…</span>':''}<button class="pager-btn" onclick="goPage(${pages})">${pages}</button>`;
    h+=`<button class="pager-btn" onclick="goPage(${currentPage+1})" ${currentPage===pages?'disabled':''}>Next ›</button>`;
    if(pT)pT.innerHTML=h;if(pB)pB.innerHTML=h;
}
function goPage(p){currentPage=p;fetchRows();const o=document.querySelector('.dt-outer');if(o)o.scrollTop=0;}

const chqInput=document.getElementById('chqSearch'),chqClr=document.getElementById('chqClr');
if(chqInput)chqInput.addEventListener('input',function(){currentSearch=this.value.trim();chqClr.classList.toggle('show',currentSearch.length>0);clearTimeout(fetchTimer);fetchTimer=setTimeout(()=>{currentPage=1;fetchRows();},350);});
function clearSearch(){if(chqInput)chqInput.value='';if(chqClr)chqClr.classList.remove('show');currentSearch='';currentPage=1;fetchRows();}

const fSampathOnlyEl=document.getElementById('fSampathOnly');
if(fSampathOnlyEl)fSampathOnlyEl.addEventListener('change',function(){currentPage=1;fetchRows();});

document.addEventListener('DOMContentLoaded',()=>fetchRows());

function updateSelCount(){const n=selectedToBeBankIds.size;const b=document.getElementById('selCountBadge'),btn=document.getElementById('markBankBtn');if(b)b.textContent=n;if(btn)btn.disabled=(n===0);}
function updateDepCount(){const n=selectedDepositIds.size;const b=document.getElementById('depCountBadge'),btn=document.getElementById('depositBtn');if(b)b.textContent=n;if(btn)btn.disabled=(n===0);}
function toggleSelectAll(cb){
    document.querySelectorAll('.row-select-cb').forEach(c=>{c.checked=cb.checked;if(cb.checked)selectedToBeBankIds.add(c.dataset.id);else selectedToBeBankIds.delete(c.dataset.id);});
    document.querySelectorAll('.row-dep-cb').forEach(c=>{c.checked=cb.checked;if(cb.checked)selectedDepositIds.add(c.dataset.id);else selectedDepositIds.delete(c.dataset.id);});
    updateSelCount();updateDepCount();
}
function onToBeBankCb(cb){const id=cb.dataset.id;if(cb.checked)selectedToBeBankIds.add(id);else selectedToBeBankIds.delete(id);updateSelCount();}
function onDepositCb(cb){const id=cb.dataset.id;if(cb.checked)selectedDepositIds.add(id);else selectedDepositIds.delete(id);updateDepCount();}

function openBankDateModal(){const n=selectedToBeBankIds.size;if(!n){showToast('No verified cheques selected','err');return;}document.getElementById('bdmNote').textContent=`${n} verified cheque(s) selected.`;document.getElementById('bankDateModal').style.display='flex';}
function closeBankDateModal(){document.getElementById('bankDateModal').style.display='none';}
async function confirmMarkToBeBank(){const date=document.getElementById('toBeBankDate').value;if(!date){showToast('Please select a date','err');return;}
    const ids=Array.from(selectedToBeBankIds);if(!ids.length){showToast('No cheques selected','err');return;}
    const btn=document.getElementById('bdmConfirmBtn');btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Updating…';
    const fd=new FormData();fd.append('ajax_action','mark_to_be_bank');fd.append('ids',JSON.stringify(ids));fd.append('to_be_bank_date',date);
    try{const res=await fetch('cheques.php',{method:'POST',body:fd});const t=await res.text();let data;try{data=JSON.parse(t);}catch(e){showToast('Server error','err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Confirm';return;}
        if(data.success){closeBankDateModal();selectedToBeBankIds.clear();let msg='✓ '+data.updated+' cheque(s) marked as To Be Bank';if(data.skipped>0)msg+=' · '+data.skipped+' skipped';showToast(msg,'ok');fetchRows();}
        else showToast(data.error||'Failed','err');
    }catch(e){showToast('Network error: '+e.message,'err');}
    btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Confirm Mark as To Be Bank';
}

let _depIds=[],_depAccId=null,_depAccounts=[],_depType='normal';
document.getElementById('depTypeSelect')?.addEventListener('change',function(){selectDepType(this.value);});
function selectDepType(t){_depType=t;const sel=document.getElementById('depTypeSelect');if(sel)sel.value=t;document.querySelectorAll('.dep-type-card').forEach(c=>c.classList.remove('selected'));const card=document.getElementById('depTypeCard_'+t);if(card)card.classList.add('selected');}
async function loadCompanyAccounts(){const list=document.getElementById('depAccountsList');if(!list)return;if(_depAccounts.length>0){renderDepAccounts();return;}
    list.innerHTML='<div class="dep-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    try{const res=await fetch('cheques.php?ajax=company_bank_accounts');const data=await res.json();if(data.success&&data.accounts){_depAccounts=data.accounts;renderDepAccounts();}else list.innerHTML='<div class="dep-empty">Could not load accounts.</div>';}
    catch(e){list.innerHTML='<div class="dep-empty">Network error</div>';}
}
function renderDepAccounts(){const list=document.getElementById('depAccountsList');if(!list)return;if(!_depAccounts.length){list.innerHTML='<div class="dep-empty">No accounts found.</div>';return;}
    let h='';_depAccounts.forEach(acc=>{const ini=esc(acc.account_name||'?').substring(0,2).toUpperCase();const bk=[acc.bank_name,acc.bank_code?'('+acc.bank_code+')':''].filter(Boolean).join(' ')||'—';const br=[acc.branch_name,acc.branch_code?'('+acc.branch_code+')':''].filter(Boolean).join(' ')||'—';const tl=acc.account_type==='current'?'Current Account':'Savings Account';const tc=acc.account_type==='current'?'current':'';const co=acc.company_name?esc(acc.company_name):'';const sel=(_depAccId!==null&&_depAccId===parseInt(acc.id));
    h+=`<div class="dep-account-card${sel?' selected':''}" id="depAcc-${acc.id}" onclick="selectDepAccount(${acc.id})">
      <input type="radio" class="dep-account-radio" name="depAccRadio" value="${acc.id}" ${sel?'checked':''} onchange="selectDepAccount(${acc.id})">
      <div class="dep-account-avatar">${ini}</div><div class="dep-account-info"><div class="dep-account-name">${esc(acc.account_name)}</div><div class="dep-account-no">${esc(acc.account_no)}</div>
      <div class="dep-account-pills"><span class="dep-account-pill"><i class="fa-solid fa-building-columns" style="font-size:9px;"></i> ${esc(bk)}</span><span class="dep-account-pill"><i class="fa-solid fa-code-branch" style="font-size:9px;"></i> ${esc(br)}</span>${co?`<span class="dep-account-pill"><i class="fa-solid fa-building" style="font-size:9px;"></i> ${co}</span>`:''}</div></div>
      <span class="dep-account-type-badge ${tc}">${esc(tl)}</span></div>`;});list.innerHTML=h;}
function selectDepAccount(id){_depAccId=id;document.querySelectorAll('.dep-account-card').forEach(c=>c.classList.remove('selected'));const card=document.getElementById('depAcc-'+id);if(card){card.classList.add('selected');const r=card.querySelector('input[type=radio]');if(r)r.checked=true;}}
function openDepositModal(){_depIds=Array.from(selectedDepositIds);if(!_depIds.length){showToast('No To Be Bank cheques selected','err');return;}_showDepositModal();}
function openDepositModalSingle(id){_depIds=[String(id)];_showDepositModal();}
function _showDepositModal(){document.getElementById('depModalChequeCount').textContent=_depIds.length+' cheque'+(_depIds.length!==1?'s':'');document.getElementById('depSumCount').textContent=_depIds.length;document.getElementById('depDate').value=new Date().toISOString().split('T')[0];selectDepType('normal');_depAccId=null;document.querySelectorAll('.dep-account-card').forEach(c=>c.classList.remove('selected'));document.getElementById('depositModal').classList.add('open');document.body.style.overflow='hidden';loadCompanyAccounts();}
function closeDepositModal(){document.getElementById('depositModal').classList.remove('open');document.body.style.overflow='';}
async function confirmDeposit(){const date=document.getElementById('depDate').value;if(!date){showToast('Select deposit date','err');return;}if(!_depAccId){showToast('Select bank account','err');return;}if(!_depIds.length){showToast('No cheques','err');return;}
    const btn=document.getElementById('depConfirmBtn');btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Processing…';
    const fd=new FormData();fd.append('ajax_action','deposit_cheques');fd.append('ids',JSON.stringify(_depIds));fd.append('deposit_date',date);fd.append('company_account_id',_depAccId);fd.append('deposit_type',_depType);
    try{const res=await fetch('cheques.php',{method:'POST',body:fd});const t=await res.text();let data;try{data=JSON.parse(t);}catch(e){showToast('Server error','err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Confirm Deposit';return;}
        if(data.success){closeDepositModal();selectedDepositIds.clear();let msg='✓ '+data.updated+' cheque(s) deposited';if(data.skipped>0)msg+=' · '+data.skipped+' skipped';showToast(msg,'ok');fetchRows();}
        else showToast(data.error||'Failed','err');
    }catch(e){showToast('Network error','err');}
    btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Confirm Deposit';
}

/* ══════════════════════════════════════════════════════
   STATUS UPDATE MODAL
══════════════════════════════════════════════════════ */
let _sumChequeId=null, _sumOldStatus='', _sumNewStatus='', _sumReasonsLoaded=false;

function openStatusUpdateModal(id, currentStatus) {
    _sumChequeId = id; _sumOldStatus = currentStatus; _sumNewStatus = '';
    const tr = document.getElementById('row-'+id);
    document.getElementById('sumChequeNo').textContent = tr?.dataset.cheqno || '';
    document.getElementById('sum_tcode').textContent = tr?.dataset.tcode || '—';
    document.getElementById('sum_cust').textContent = tr?.dataset.cust || '—';
    const icon = status_icons_js[currentStatus] || 'fa-circle-dot';
    const lbl = status_labels_js[currentStatus] || currentStatus;
    document.getElementById('sumCurrentBadge').innerHTML = `<span class="status-badge-clickable ${currentStatus}" style="cursor:default;"><i class="fa-solid ${icon}"></i> ${lbl}</span>`;
    const grid = document.getElementById('sumStatusGrid');
    const changeLbl = document.getElementById('sumChangeLbl');
    const updateBtn = document.getElementById('sumUpdateBtn');
    if(currentStatus === 'sent_back') {
        if(changeLbl) changeLbl.style.display = 'none';
        grid.innerHTML = `<div style="grid-column:span 3;text-align:center;padding:20px;color:#7c3aed;font-size:13px;font-weight:600;background:#fdf4ff;border:1.5px dashed #d8b4fe;border-radius:10px;"><i class="fa-solid fa-lock" style="font-size:20px;display:block;margin-bottom:8px;opacity:.5;"></i>This cheque has been <strong>Sent Back</strong>.<br>Status cannot be changed from here.</div>`;
        document.getElementById('sumReasonSection').classList.remove('open');
        updateBtn.disabled = true; updateBtn.style.display = 'none';
        document.getElementById('statusUpdateModal').classList.add('open'); return;
    }
    if(changeLbl) changeLbl.style.display = '';
    updateBtn.style.display = '';
    const allowedStatuses = ['pending','to_be_bank','sent_back'];
    const statusEmoji = {pending:'🕐',to_be_bank:'📥',sent_back:'↩️'};
    let gh = '';
    allowedStatuses.forEach(s => {
        const isCurrent = (s === currentStatus);
        const clickHandler = isCurrent ? '' : `selectNewStatus('${s}')`;
        gh += `<div class="sum-status-option${isCurrent?' current':''}" id="sumOpt_${s}" onclick="${clickHandler}" title="${isCurrent?'Current status':'Change to '+status_labels_js[s]}"><div class="sum-st-icon">${statusEmoji[s]||'⚪'}</div><div class="sum-st-label">${status_labels_js[s]}</div></div>`;
    });
    grid.innerHTML = gh;
    document.getElementById('sumReasonSection').classList.remove('open');
    updateBtn.disabled = true;
    const sel = document.getElementById('sumReasonSelect');
    document.getElementById('sumActionDate').value = new Date().toISOString().split('T')[0];
    if(window.$ && $(sel).data('select2')) $(sel).val(null).trigger('change');
    document.getElementById('statusUpdateModal').classList.add('open');
}

function selectNewStatus(s) {
    _sumNewStatus = s;
    document.querySelectorAll('.sum-status-option').forEach(o => o.classList.remove('selected'));
    const opt = document.getElementById('sumOpt_'+s);
    if(opt && !opt.classList.contains('current')) opt.classList.add('selected');
    const needReason = ((_sumOldStatus === 'deposited' && s === 'to_be_bank') || s === 'sent_back');
    const reasonSec = document.getElementById('sumReasonSection');
    const reasonLabel = reasonSec.querySelector('label');
    if(needReason) {
        if(reasonLabel) reasonLabel.innerHTML = s === 'sent_back' ? '<i class="fa-solid fa-comment-dots"></i> Reason for Sending Back' : '<i class="fa-solid fa-comment-dots"></i> Reason for reverting Deposited → To Be Bank';
        reasonSec.classList.add('open'); loadReasonOptions();
    } else { reasonSec.classList.remove('open'); }
    document.getElementById('sumUpdateBtn').disabled = false;
}

function loadReasonOptions() {
    if(_sumReasonsLoaded) { if(window.$) setTimeout(()=>$('#sumReasonSelect').select2('open'),80); return; }
    const sel = document.getElementById('sumReasonSelect');
    fetch('cheques.php?ajax=sendback_reasons').then(r=>r.json()).then(data=>{
        if(data.success && data.reasons) data.reasons.forEach(item => { const opt = document.createElement('option'); opt.value = item.reason; opt.textContent = item.reason; sel.appendChild(opt); });
        _sumReasonsLoaded = true;
        if(window.$) { $(sel).select2({placeholder:'— Select a reason —',allowClear:true,width:'100%',dropdownParent:$('#statusUpdateModal'),dropdownCssClass:'sum-reason-dropdown'}); setTimeout(()=>$(sel).select2('open'),80); }
    }).catch(()=>{});
}

function closeStatusUpdateModal() { document.getElementById('statusUpdateModal').classList.remove('open'); _sumChequeId = null; _sumNewStatus = ''; }
document.getElementById('statusUpdateModal')?.addEventListener('click',function(e){if(e.target===this)closeStatusUpdateModal();});

async function confirmStatusUpdate() {
    if(!_sumChequeId || !_sumNewStatus) return;
    const needReason = ((_sumOldStatus === 'deposited' && _sumNewStatus === 'to_be_bank') || _sumNewStatus === 'sent_back');
    let reason = '';
    if(needReason) { const sel = document.getElementById('sumReasonSelect'); reason = (window.$ ? $(sel).val() : sel.value) || ''; if(!reason.trim()) { showToast('Please select a reason','err'); return; } }
    const btn = document.getElementById('sumUpdateBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Updating…';
 const actionDate = document.getElementById('sumActionDate').value;
    if(!actionDate) { showToast('Please select an action date','err'); return; }
    const fd = new FormData();
    fd.append('ajax_action','update_status_individual');
    fd.append('cheque_id', _sumChequeId);
    fd.append('new_status', _sumNewStatus);
    fd.append('action_date', actionDate);
    if(reason) fd.append('reason', reason);
    try {
        const res = await fetch('cheques.php',{method:'POST',body:fd});
        const text = await res.text(); let data;
        try { data = JSON.parse(text); } catch(e) { showToast('Server error','err'); btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Update Status'; return; }
        if(data.success) { closeStatusUpdateModal(); showToast('✓ Status updated to '+status_labels_js[_sumNewStatus],'ok'); fetchRows(); }
        else { showToast(data.error||'Update failed','err'); }
    } catch(e) { showToast('Network error','err'); }
    btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Update Status';
}

/* ══ CHEQUE LOGS MODAL ══ */
async function openLogsModal(id, chequeNo) {
    const modal   = document.getElementById('logsModal');
    const loading = document.getElementById('logsLoading');
    const content = document.getElementById('logsContent');
    const title   = document.getElementById('logsModalChequeNo');
    modal.style.display = 'flex';
    loading.style.display = 'block';
    loading.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Loading logs…';
    content.style.display = 'none';
    content.innerHTML = '';
    if (title) title.textContent = chequeNo || '';

    try {
        const res  = await fetch('cheques.php?ajax=cheque_logs&cheque_id=' + encodeURIComponent(id));
        const raw  = await res.text();
        let data;
        try { data = JSON.parse(raw); } catch(e) {
            loading.innerHTML = '<span style="color:#dc2626;">Invalid response</span>'; return;
        }
        loading.style.display = 'none';
        content.style.display = 'block';

        const di  = data.deposit_info     || {};
        const sp  = data.settle_payments  || [];   /* returned cheque settlements */
        const sbp = data.sb_payments      || [];   /* sent-back cheque settlements */
        const ri  = data.return_info      || {};

        /* ═══ DEPOSIT BANNER ═══ */
        let depBanner = '';
        if (di.deposit_date) {
            const typePill = di.deposit_type === 'bulk'
                ? `<span class="log-pill pill-bulk">Bulk</span>`
                : di.deposit_type === 'normal_bulk'
                ? `<span class="log-pill pill-normal-bulk">Normal Bulk</span>`
                : `<span class="log-pill pill-normal">Normal</span>`;
            depBanner = `
            <div class="log-dep-banner">
              <div class="log-dep-banner-title"><i class="fa-solid fa-building-columns"></i> Deposit Details</div>
              <div class="log-dep-banner-grid">
                <div class="log-dep-field"><span class="log-dep-lbl">Deposit Date</span><span class="log-dep-val">${esc(di.deposit_date)}</span></div>
                <div class="log-dep-field"><span class="log-dep-lbl">Type</span><span class="log-dep-val">${typePill}</span></div>
                ${di.account_name ? `<div class="log-dep-field"><span class="log-dep-lbl">Account</span><span class="log-dep-val">${esc(di.account_name)} · ${esc(di.account_no||'')}</span></div>` : ''}
                ${di.bank_name    ? `<div class="log-dep-field"><span class="log-dep-lbl">Bank</span><span class="log-dep-val">${esc(di.bank_name)}</span></div>` : ''}
                ${di.company_name ? `<div class="log-dep-field"><span class="log-dep-lbl">Company</span><span class="log-dep-val">${esc(di.company_name)}</span></div>` : ''}
              </div>
            </div>`;
        }

        /* ═══ CRN / RETURN INFO BANNER (returned cheques) ═══ */
        let crnBanner = '';
        if (ri.status === 'returned' || ri.status === 'bounced') {
            const repBg  = ri.is_representable == 1 ? '#dcfce7' : '#fee2e2';
            const repClr = ri.is_representable == 1 ? '#166534' : '#991b1b';
            const repLbl = ri.is_representable == 1 ? '✓ Re-presentable' : '✗ Non-representable';
            const settledTotal = parseFloat(ri.settlement_amount || 0);
            const settledBadge = parseInt(ri.return_settled || 0)
                ? `<span style="background:#dcfce7;color:#166534;border:1px solid #86efac;border-radius:6px;padding:2px 10px;font-size:11px;font-weight:700;"><i class="fa-solid fa-circle-check"></i> Fully Settled — Rs.${settledTotal.toFixed(2)}</span>`
                : `<span style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:6px;padding:2px 10px;font-size:11px;font-weight:700;"><i class="fa-solid fa-clock"></i> Unsettled — Rs.${settledTotal.toFixed(2)} recovered</span>`;
            if (ri.crn_no) {
                crnBanner = `
                <div style="background:linear-gradient(135deg,#fef2f2,#fee2e2);border:1.5px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:12px;">
                  <div style="font-size:11px;font-weight:800;color:#991b1b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;display:flex;align-items:center;gap:7px;">
                    <i class="fa-solid fa-file-invoice"></i> CRN — Cheque Return Notification
                  </div>
                  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:10px;">
                    <div><div style="font-size:9px;font-weight:700;color:#dc2626;text-transform:uppercase;">CRN No.</div><div style="font-family:'Courier New',monospace;font-size:13px;font-weight:800;color:#7f1d1d;">${esc(ri.crn_no)}</div></div>
                    <div><div style="font-size:9px;font-weight:700;color:#dc2626;text-transform:uppercase;">Return Code</div><div style="font-size:13px;font-weight:700;color:#7f1d1d;">${esc(ri.crn_return_code||'—')}</div></div>
                    <div><div style="font-size:9px;font-weight:700;color:#dc2626;text-transform:uppercase;">Date of Return</div><div style="font-size:13px;font-weight:700;color:#7f1d1d;">${esc(ri.crn_date_of_return||'—')}</div></div>
                    <div><div style="font-size:9px;font-weight:700;color:#dc2626;text-transform:uppercase;">Return Reason</div><div style="font-size:12px;font-weight:600;color:#7f1d1d;">${esc(ri.crn_return_reason||'—')}</div></div>
                    <div><div style="font-size:9px;font-weight:700;color:#dc2626;text-transform:uppercase;">Collecting Bank</div><div style="font-size:12px;font-weight:600;color:#7f1d1d;">${esc(ri.crn_collecting_bank||'—')}</div></div>
                    <div><div style="font-size:9px;font-weight:700;color:#dc2626;text-transform:uppercase;">Collecting Branch</div><div style="font-size:12px;font-weight:600;color:#7f1d1d;">${esc(ri.crn_collecting_branch||'—')}</div></div>
                  </div>
                  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding-top:8px;border-top:1px solid #fecaca;">
                    <span style="background:${repBg};color:${repClr};border-radius:6px;padding:3px 12px;font-size:12px;font-weight:700;border:1px solid ${repClr}40;">${repLbl}</span>
                    ${settledBadge}
                    ${ri.crn_return_remark ? `<span style="font-size:11px;color:#991b1b;font-style:italic;">"${esc(ri.crn_return_remark)}"</span>` : ''}
                  </div>
                </div>`;
            }
        }

        /* ═══ SETTLEMENT PAYMENTS BLOCK — RETURNED CHEQUES ═══ */
        let settleBanner = '';
        if (sp.length > 0) {
            const totalSettled = sp.reduce((s, p) => s + parseFloat(p.amount || 0), 0);
            let spRows = '';
            sp.forEach((p, idx) => {
                const isCash   = p.payment_method === 'cash';
                const isCheque = p.payment_method === 'cheque';
                const methBg   = isCash ? '#dcfce7' : isCheque ? '#dbeafe' : '#f3f4f6';
                const methClr  = isCash ? '#166534' : isCheque ? '#1e40af' : '#374151';
                const methIcon = isCash ? 'fa-coins' : isCheque ? 'fa-money-check' : 'fa-circle-dot';
                const methLbl  = isCash ? 'Cash' : isCheque ? 'Cheque' : esc(p.payment_method);

                let detailHtml = '';
                if (isCash) {
                    detailHtml = `
                    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 14px;margin-top:8px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;">
                      <div><div style="font-size:9px;font-weight:700;color:#16a34a;text-transform:uppercase;">Payment Date</div><div style="font-size:12px;font-weight:700;color:#14532d;">${esc(p.payment_date||'—')}</div></div>
                      <div><div style="font-size:9px;font-weight:700;color:#16a34a;text-transform:uppercase;">Amount</div><div style="font-size:14px;font-weight:800;color:#14532d;font-family:'Courier New',monospace;">Rs. ${parseFloat(p.amount).toFixed(2)}</div></div>
                      <div><div style="font-size:9px;font-weight:700;color:#16a34a;text-transform:uppercase;">Reference</div><div style="font-size:12px;font-weight:600;color:#14532d;">${esc(p.reference_no||'—')}</div></div>
                      ${p.remarks ? `<div style="grid-column:span 3;"><div style="font-size:9px;font-weight:700;color:#16a34a;text-transform:uppercase;">Remarks</div><div style="font-size:11px;color:#14532d;font-style:italic;">${esc(p.remarks)}</div></div>` : ''}
                    </div>`;
                } else if (isCheque) {
                    const bankDisplay = [p.bank_code, p.bank_name].filter(Boolean).join(' · ') || '—';
                    detailHtml = `
                    <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:8px;padding:10px 14px;margin-top:8px;">
                      <div style="font-family:'Courier New',monospace;font-weight:800;font-size:14px;color:#1e40af;letter-spacing:.04em;display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                        <i class="fa-solid fa-money-check" style="font-size:12px;"></i> ${esc(p.cheque_no||'No cheque no.')}
                      </div>
                      <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px 12px;">
                        <div><div style="font-size:9px;font-weight:700;color:#3b82f6;text-transform:uppercase;">Bank</div><div style="font-size:11px;font-weight:700;color:#1e3a8a;">${esc(bankDisplay)}</div></div>
                        <div><div style="font-size:9px;font-weight:700;color:#3b82f6;text-transform:uppercase;">Branch</div><div style="font-size:11px;font-weight:700;color:#1e3a8a;">${esc(p.branch_name||'—')}</div></div>
                        <div><div style="font-size:9px;font-weight:700;color:#3b82f6;text-transform:uppercase;">Cheque Date</div><div style="font-size:11px;font-weight:700;color:#1e3a8a;">${esc(p.cheque_date||'—')}</div></div>
                        <div><div style="font-size:9px;font-weight:700;color:#3b82f6;text-transform:uppercase;">Amount</div><div style="font-size:14px;font-weight:800;color:#1e40af;font-family:'Courier New',monospace;">Rs. ${parseFloat(p.amount).toFixed(2)}</div></div>
                        ${p.payment_date ? `<div><div style="font-size:9px;font-weight:700;color:#3b82f6;text-transform:uppercase;">Received Date</div><div style="font-size:11px;font-weight:700;color:#1e3a8a;">${esc(p.payment_date)}</div></div>` : ''}
                        ${p.remarks ? `<div><div style="font-size:9px;font-weight:700;color:#3b82f6;text-transform:uppercase;">Remarks</div><div style="font-size:11px;color:#1e3a8a;font-style:italic;">${esc(p.remarks)}</div></div>` : ''}
                      </div>
                    </div>`;
                }

                const dt = p.created_at ? new Date(p.created_at.replace(' ','T')) : null;
                const dtStr = dt ? dt.toLocaleString('en-GB',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';
                const userBadge = (p.created_by && p.created_by !== 'system')
                    ? `<span class="log-user-badge"><i class="fa-solid fa-user" style="font-size:9px;"></i> ${esc(p.created_by)}</span>`
                    : `<span class="log-user-badge log-user-sys"><i class="fa-solid fa-robot" style="font-size:9px;"></i> system</span>`;

                spRows += `
                <div class="log-item" style="align-items:flex-start;">
                  <div class="log-icon ic-deposit" style="background:${methBg};color:${methClr};flex-shrink:0;">
                    <i class="fa-solid ${methIcon}"></i>
                  </div>
                  <div class="log-body" style="flex:1;min-width:0;">
                    <div class="log-action-label">
                      <span style="background:${methBg};color:${methClr};border-radius:4px;padding:1px 8px;font-size:11px;font-weight:700;margin-right:6px;">${methLbl}</span>
                      Return Settlement Payment #${idx + 1}
                    </div>
                    ${detailHtml}
                    <div class="log-time" style="margin-top:6px;"><i class="fa-regular fa-clock"></i> ${esc(dtStr)} · ${userBadge}</div>
                  </div>
                </div>`;
            });

            settleBanner = `
            <div style="background:linear-gradient(135deg,#fef2f2,#fff5f5);border:1.5px solid #fca5a5;border-radius:10px;overflow:hidden;margin-bottom:12px;">
              <div style="background:#7f1d1d;padding:9px 16px;display:flex;align-items:center;justify-content:space-between;">
                <span style="color:#fecaca;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;display:flex;align-items:center;gap:7px;">
                  <i class="fa-solid fa-circle-xmark" style="color:#f87171;"></i> Return Cheque — Settlement Payments
                </span>
                <span style="background:#dc2626;color:#fff;border-radius:6px;padding:2px 12px;font-size:12px;font-weight:800;">
                  Total: Rs. ${totalSettled.toFixed(2)}
                </span>
              </div>
              <div style="padding:4px 16px 8px;">
                ${spRows}
              </div>
            </div>`;
        }

        /* ═══ SETTLEMENT PAYMENTS BLOCK — SENT BACK CHEQUES ═══ */
        let sbSettleBanner = '';
        if (sbp.length > 0) {
            const totalSb = sbp.reduce((s, p) => s + parseFloat(p.amount || 0), 0);
            let sbRows = '';
            sbp.forEach((p, idx) => {
                const isCash   = p.payment_method === 'cash';
                const isCheque = p.payment_method === 'cheque';
                const methBg   = isCash ? '#dcfce7' : isCheque ? '#dbeafe' : '#f3f4f6';
                const methClr  = isCash ? '#166534' : isCheque ? '#1e40af' : '#374151';
                const methIcon = isCash ? 'fa-coins' : isCheque ? 'fa-money-check' : 'fa-circle-dot';
                const methLbl  = isCash ? 'Cash' : isCheque ? 'Replacement Cheque' : esc(p.payment_method);

                let detailHtml = '';
                if (isCash) {
                    detailHtml = `
                    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 14px;margin-top:8px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;">
                      <div><div style="font-size:9px;font-weight:700;color:#16a34a;text-transform:uppercase;">Payment Date</div><div style="font-size:12px;font-weight:700;color:#14532d;">${esc(p.payment_date||'—')}</div></div>
                      <div><div style="font-size:9px;font-weight:700;color:#16a34a;text-transform:uppercase;">Amount</div><div style="font-size:14px;font-weight:800;color:#14532d;font-family:'Courier New',monospace;">Rs. ${parseFloat(p.amount).toFixed(2)}</div></div>
                      <div><div style="font-size:9px;font-weight:700;color:#16a34a;text-transform:uppercase;">Reference</div><div style="font-size:12px;font-weight:600;color:#14532d;">${esc(p.reference_no||'—')}</div></div>
                      ${p.remarks ? `<div style="grid-column:span 3;"><div style="font-size:9px;font-weight:700;color:#16a34a;text-transform:uppercase;">Remarks</div><div style="font-size:11px;color:#14532d;font-style:italic;">${esc(p.remarks)}</div></div>` : ''}
                    </div>`;
                } else if (isCheque) {
                    const bankDisplay = [p.bank_code, p.bank_name].filter(Boolean).join(' · ') || '—';
                    detailHtml = `
                    <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:8px;padding:10px 14px;margin-top:8px;">
                      <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:6px;"><i class="fa-solid fa-rotate-right" style="color:#7c3aed;"></i> Replacement Cheque Details</div>
                      <div style="font-family:'Courier New',monospace;font-weight:800;font-size:15px;color:#1e40af;letter-spacing:.04em;display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                        <i class="fa-solid fa-money-check" style="font-size:12px;"></i> ${esc(p.cheque_no||'No cheque no.')}
                      </div>
                      <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px 12px;">
                        <div><div style="font-size:9px;font-weight:700;color:#3b82f6;text-transform:uppercase;">Bank</div><div style="font-size:11px;font-weight:700;color:#1e3a8a;">${esc(bankDisplay)}</div></div>
                        <div><div style="font-size:9px;font-weight:700;color:#3b82f6;text-transform:uppercase;">Branch</div><div style="font-size:11px;font-weight:700;color:#1e3a8a;">${esc(p.branch_name||'—')}</div></div>
                        <div><div style="font-size:9px;font-weight:700;color:#3b82f6;text-transform:uppercase;">Cheque Date</div><div style="font-size:11px;font-weight:700;color:#1e3a8a;">${esc(p.cheque_date||'—')}</div></div>
                        <div><div style="font-size:9px;font-weight:700;color:#3b82f6;text-transform:uppercase;">Amount</div><div style="font-size:14px;font-weight:800;color:#1e40af;font-family:'Courier New',monospace;">Rs. ${parseFloat(p.amount).toFixed(2)}</div></div>
                        ${p.payment_date ? `<div><div style="font-size:9px;font-weight:700;color:#3b82f6;text-transform:uppercase;">Received Date</div><div style="font-size:11px;font-weight:700;color:#1e3a8a;">${esc(p.payment_date)}</div></div>` : ''}
                        ${p.remarks ? `<div><div style="font-size:9px;font-weight:700;color:#3b82f6;text-transform:uppercase;">Remarks</div><div style="font-size:11px;color:#1e3a8a;font-style:italic;">${esc(p.remarks)}</div></div>` : ''}
                      </div>
                    </div>`;
                }

                const dt = p.created_at ? new Date(p.created_at.replace(' ','T')) : null;
                const dtStr = dt ? dt.toLocaleString('en-GB',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';
                const userBadge = (p.created_by && p.created_by !== 'system')
                    ? `<span class="log-user-badge"><i class="fa-solid fa-user" style="font-size:9px;"></i> ${esc(p.created_by)}</span>`
                    : `<span class="log-user-badge log-user-sys"><i class="fa-solid fa-robot" style="font-size:9px;"></i> system</span>`;

                sbRows += `
                <div class="log-item" style="align-items:flex-start;">
                  <div class="log-icon" style="background:${methBg};color:${methClr};flex-shrink:0;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;">
                    <i class="fa-solid ${methIcon}"></i>
                  </div>
                  <div class="log-body" style="flex:1;min-width:0;">
                    <div class="log-action-label">
                      <span style="background:${methBg};color:${methClr};border-radius:4px;padding:1px 8px;font-size:11px;font-weight:700;margin-right:6px;">${methLbl}</span>
                      Sent Back Settlement Payment #${idx + 1}
                    </div>
                    ${detailHtml}
                    <div class="log-time" style="margin-top:6px;"><i class="fa-regular fa-clock"></i> ${esc(dtStr)} · ${userBadge}</div>
                  </div>
                </div>`;
            });

            sbSettleBanner = `
            <div style="background:linear-gradient(135deg,#faf5ff,#f3e8ff);border:1.5px solid #d8b4fe;border-radius:10px;overflow:hidden;margin-bottom:12px;">
              <div style="background:#3b0764;padding:9px 16px;display:flex;align-items:center;justify-content:space-between;">
                <span style="color:#d8b4fe;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;display:flex;align-items:center;gap:7px;">
                  <i class="fa-solid fa-rotate-left" style="color:#c084fc;"></i> Sent Back — Settlement Payments
                </span>
                <span style="background:#7c3aed;color:#fff;border-radius:6px;padding:2px 12px;font-size:12px;font-weight:800;">
                  Total: Rs. ${totalSb.toFixed(2)}
                </span>
              </div>
              <div style="padding:4px 16px 8px;">
                ${sbRows}
              </div>
            </div>`;
        }

        /* ═══ STANDARD ACTIVITY LOGS ═══ */
        if (!data.logs || !data.logs.length) {
            content.innerHTML = depBanner + crnBanner + settleBanner + sbSettleBanner +
                `<div class="log-empty"><i class="fa-solid fa-clock-rotate-left"></i> No activity logs yet.</div>`;
            return;
        }

        const sL  = {pending:'Pending',to_be_bank:'To Be Bank',deposited:'Deposited',sent_back:'Sent Back',cleared:'Cleared',returned:'Returned'};
        const aL  = {status_change:'Status Changed',verified:'Cheque Verified',sent_back:'Sent Back',deposited:'Deposited',revert_to_be_bank:'Reverted to To Be Bank',crn_upload:'CRN Uploaded',crn_removed:'CRN Removed',return_settled:'Return Settled',sb_settled:'Sent Back Settled',details_updated:'Details Updated',pending:'Marked Pending'};
        const aI  = {status_change:'<i class="fa-solid fa-arrow-right-arrow-left"></i>',verified:'<i class="fa-solid fa-shield-check"></i>',sent_back:'<i class="fa-solid fa-rotate-left"></i>',deposited:'<i class="fa-solid fa-building-columns"></i>',revert_to_be_bank:'<i class="fa-solid fa-rotate-left"></i>',crn_upload:'<i class="fa-solid fa-file-invoice"></i>',crn_removed:'<i class="fa-solid fa-trash"></i>',return_settled:'<i class="fa-solid fa-circle-check"></i>',sb_settled:'<i class="fa-solid fa-circle-check"></i>',details_updated:'<i class="fa-solid fa-pen-to-square"></i>',pending:'<i class="fa-solid fa-clock"></i>'};
        const iC  = {status_change:'ic-status',verified:'ic-verify',sent_back:'ic-back',deposited:'ic-deposit',revert_to_be_bank:'ic-back',crn_upload:'ic-deposit',crn_removed:'ic-back',return_settled:'ic-verify',sb_settled:'ic-verify',details_updated:'ic-status',pending:'ic-status'};
        const sPC = {pending:'lp-amber',to_be_bank:'lp-sky',deposited:'lp-teal',sent_back:'lp-violet',cleared:'lp-green',returned:'lp-red'};

        function sPill(v) {
            return `<span class="log-status-pill ${sPC[v]||'lp-gray'}">${esc(sL[v]||v)}</span>`;
        }

        let logsHtml = '';
        data.logs.forEach(log => {
            const act  = log.action || 'other';
            const lbl  = aL[act]   || act.replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase());
            const icon = aI[act]   || '<i class="fa-solid fa-circle-dot"></i>';
            const icls = iC[act]   || 'ic-other';
            const dt   = log.created_at ? new Date(log.created_at.replace(' ','T')) : null;
            const dtStr= dt ? dt.toLocaleString('en-GB',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';

            let changeHtml = '';
            if (log.old_value || log.new_value)
                changeHtml = `<div class="log-change">${sPill(log.old_value||'—')}<i class="fa-solid fa-arrow-right" style="color:#9ca3af;font-size:10px;margin:0 4px;"></i>${sPill(log.new_value||'—')}</div>`;

            let noteHtml = '';
            if (log.note) {
                if ((act==='deposited') && log.note.includes('|')) {
                    const parts = log.note.split('|').map(s => s.trim());
                    noteHtml = `<div class="log-dep-note">` + parts.map(p => `<span class="log-dep-note-item"><i class="fa-solid fa-circle-dot" style="font-size:7px;color:#0d9488;margin-right:4px;"></i>${esc(p)}</span>`).join('') + `</div>`;
                } else if (act==='sent_back' || act==='revert_to_be_bank') {
                    noteHtml = `<div class="log-note log-note-back"><strong>Reason:</strong> ${esc(log.note)}</div>`;
                } else if (act==='crn_upload') {
                    noteHtml = `<div class="log-note" style="background:#f0f9ff;border-left:3px solid #0ea5e9;padding:4px 8px;border-radius:0 4px 4px 0;color:#0369a1;">${esc(log.note)}</div>`;
                } else {
                    noteHtml = `<div class="log-note">${esc(log.note)}</div>`;
                }
            }

            const user = log.created_by && log.created_by !== 'system' ? log.created_by : 'system';
            const userBadge = user === 'system'
                ? `<span class="log-user-badge log-user-sys"><i class="fa-solid fa-robot" style="font-size:9px;"></i> system</span>`
                : `<span class="log-user-badge"><i class="fa-solid fa-user" style="font-size:9px;"></i> ${esc(user)}</span>`;

            logsHtml += `
            <div class="log-item">
              <div class="log-icon ${icls}">${icon}</div>
              <div class="log-body">
                <div class="log-action-label">${esc(lbl)}</div>
                ${changeHtml}${noteHtml}
                <div class="log-time"><i class="fa-regular fa-clock"></i> ${esc(dtStr)} · ${userBadge}</div>
              </div>
            </div>`;
        });

        content.innerHTML = depBanner + crnBanner + settleBanner + sbSettleBanner + logsHtml;

    } catch(e) {
        loading.innerHTML = `<span style="color:#dc2626;">Error: ${esc(e.message)}</span>`;
    }
}
function closeLogsModal(){document.getElementById('logsModal').style.display='none';}
document.getElementById('logsModal')?.addEventListener('click',function(e){if(e.target===this)closeLogsModal();});

/* ══ EXPAND / SUB-ROW ══ */
const loadedRows=new Set();
async function toggleSubRow(id,tcode){const subRow=document.getElementById('sub-'+id),subLdr=document.getElementById('subldr-'+id),subCnt=document.getElementById('subcnt-'+id),expBtn=document.getElementById('expbtn-'+id);if(!subRow)return;
    if(subRow.classList.contains('visible')){subRow.classList.remove('visible');expBtn.classList.remove('open');return;}
    subRow.classList.add('visible');expBtn.classList.add('open');if(loadedRows.has(id)){subLdr.style.display='none';subCnt.style.display='grid';return;}
    subLdr.style.display='flex';subCnt.style.display='none';
    try{const res=await fetch(`cheques.php?ajax=cheque_detail&cheque_id=${encodeURIComponent(id)}&t_code=${encodeURIComponent(tcode)}`);if(!res.ok)throw new Error(`HTTP ${res.status}`);
        const raw=await res.text();let data;try{data=JSON.parse(raw);}catch(e){throw new Error('Bad JSON');}
        if(data.error){subLdr.innerHTML=`<i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;"></i> DB error: ${esc(data.error)}`;return;}
        renderSub(id,data.invoices||[],data.bank_accounts||[]);loadedRows.add(id);
    }catch(e){subLdr.innerHTML=`<i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i> ${esc(e.message)}`;}
}
function renderSub(id,invoices,banks){const subLdr=document.getElementById('subldr-'+id),subCnt=document.getElementById('subcnt-'+id);
    let inv=`<div class="sub-panel inv-panel"><div class="sub-title inv-t"><span class="sub-title-icon sti-inv"><i class="fa-solid fa-file-invoice"></i></span>Invoices<span class="sub-count sc-inv">${invoices.length}</span></div>`;
    if(!invoices.length)inv+=`<div class="sub-empty"><i class="fa-solid fa-inbox"></i> No invoices.</div>`;
    else{let total=0;inv+=`<div class="inv-list">`;invoices.forEach(v=>{const amt=parseFloat(v.amount||0);total+=amt;inv+=`<div class="inv-card"><div class="inv-num">${esc(v.invoice_num)}</div><div class="inv-body"><div class="inv-cust">${esc(v.customer_name||'—')}</div><div class="inv-tcode"><i class="fa-solid fa-tag" style="font-size:9px;"></i> ${esc(v.t_code)}</div></div><div class="inv-amt">Rs.&nbsp;${amt.toFixed(2)}</div></div>`;});inv+=`</div><div class="inv-total"><span class="inv-total-lbl"><i class="fa-solid fa-sigma"></i> Total</span><span class="inv-total-val">Rs.&nbsp;${total.toFixed(2)}</span></div>`;}inv+=`</div>`;
    let bank=`<div class="sub-panel"><div class="sub-title bank-t"><span class="sub-title-icon sti-bank"><i class="fa-solid fa-landmark"></i></span>Customer Bank Accounts<span class="sub-count sc-bank">${banks.length}</span></div>`;
    if(!banks.length)bank+=`<div class="sub-empty"><i class="fa-solid fa-inbox"></i> No bank accounts.</div>`;
    else{bank+=`<div class="bank-list">`;banks.forEach(b=>{const ini=esc(b.account_holder_name||'?').substring(0,2).toUpperCase();const bk=[b.bank_name,b.bank_code?'('+b.bank_code+')':''].filter(Boolean).join(' ')||'—';const br=[b.branch_name,b.branch_code?'('+b.branch_code+')':''].filter(Boolean).join(' ')||'—';
        bank+=`<div class="bank-card"><div class="bank-head"><div class="bank-avatar">${ini}</div><div class="bank-holder">${esc(b.account_holder_name||'—')}</div></div><div class="bank-acno">${esc(b.account_number||'—')}</div><div class="bank-pills"><span class="bank-pill"><i class="fa-solid fa-building-columns" style="font-size:9px;"></i> ${esc(bk)}</span><span class="bank-pill"><i class="fa-solid fa-code-branch" style="font-size:9px;"></i> ${esc(br)}</span></div></div>`;});bank+=`</div>`;}bank+=`</div>`;
    subCnt.innerHTML=inv+bank;subLdr.style.display='none';subCnt.style.display='grid';
}

/* ══ DIRTY + SAVE ══ */
const dirty=new Set();
function markDirty(id){dirty.add(id);const chk=document.getElementById('bulk-'+id),lbl=chk?.nextElementSibling;if(lbl)lbl.textContent=chk.checked?'Yes':'No';document.getElementById('savebtn-'+id)?.classList.add('visible');document.getElementById('savedtick-'+id)?.classList.remove('visible');}
async function saveRow(id){const btn=document.getElementById('savebtn-'+id),tick=document.getElementById('savedtick-'+id);
    const bulk_flag=document.getElementById('bulk-'+id)?.checked?1:0;
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>';
    const fd=new FormData();fd.append('cheque_id',id);fd.append('bulk_flag',bulk_flag);
    const tr=document.getElementById('row-'+id);fd.append('status',tr?.dataset.status||'pending');
    try{const res=await fetch('save_cheque_update.php',{method:'POST',body:fd});const data=await res.json();
        if(data.success){dirty.delete(id);btn.classList.remove('visible');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save';tick.classList.add('visible');setTimeout(()=>tick.classList.remove('visible'),2800);showToast('Saved ✓','ok');}
        else{showToast(data.error||'Save failed','err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save';}
    }catch(e){showToast('Network error','err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save';}
}
window.addEventListener('beforeunload',e=>{if(dirty.size>0){e.preventDefault();e.returnValue='';}});

function showToast(msg,type){const t=document.getElementById('toast');t.style.background=type==='ok'?'#166534':'#dc2626';t.textContent=msg;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),3200);}

function esc(s){if(s===null||s===undefined)return '';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function escRe(s){return s.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');}

function exportCSV(){const rows=document.querySelectorAll('#mainTable tbody tr.main-row');if(!rows.length){alert('No data.');return;}
    const headers=['No','SR Code','Delivery Date','Received Date','Cheque Date','Cheque No','Mode','Bank','Bank Code','Branch Code','Amount','Status','Bulk Dep.','T-Code','Customer','Acc. Holder'];const lines=[headers.join(',')];const q=v=>'"'+(v||'').toString().replace(/"/g,'""').replace(/\s+/g,' ').trim()+'"';
    rows.forEach((tr,i)=>{const id=tr.dataset.id;const tds=tr.querySelectorAll('td');lines.push([i+1,q(tds[2]?.textContent),q(tds[3]?.textContent),q(tds[4]?.textContent),q(tds[5]?.textContent),q(tr.dataset.chequeOrig||''),q(tds[7]?.textContent),q(tds[8]?.querySelector('div')?.textContent||''),q(tds[9]?.textContent),q(tds[10]?.textContent),q(tds[11]?.textContent),q(tr.dataset.status||''),q(document.getElementById('bulk-'+id)?.checked?'Yes':'No'),q(tds[14]?.querySelector('.mono')?.textContent||''),q(tds[14]?.querySelector('.cust-sub')?.textContent||''),q(tds[15]?.querySelector('.acc-ro')?.textContent||'')].join(','));});
    const blob=new Blob([lines.join('\n')],{type:'text/csv'});const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download='cheques_export.csv';a.click();URL.revokeObjectURL(url);}

/* ════════════════════════════════════════════════════
   VERIFY MODAL (FULLSCREEN — UPDATED)
   Equal-sized front/back panels, large image previews
════════════════════════════════════════════════════ */
/* CHQ-PATCH2-VERIFYMODAL */
(function(){
    const el=document.createElement('div');el.id='verifyModal';
    el.innerHTML=`
    <div class="vmodal-box">
      <div class="vmodal-header">
        <div class="vmodal-title"><i class="fa-solid fa-shield-halved"></i> Verify Cheque</div>
        <button class="vmodal-close" onclick="closeVerifyModal()"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="vmodal-body">

        <!-- Customer Seal & Signature -->
        <div class="vmodal-section" style="margin-bottom:8px;">
          <div class="vmodal-section-title" style="margin-bottom:6px;font-size:10px;"><i class="fa-solid fa-user-check" style="color:#6366f1;"></i> Customer Seal &amp; Signature</div>
          <div class="cust-images-row">
            <div class="cust-img-box" id="custSealBox"><i class="fa-solid fa-stamp cust-img-placeholder"></i><span class="img-label">Customer Seal</span></div>
            <div class="cust-img-box" id="custSigBox"><i class="fa-solid fa-signature cust-img-placeholder"></i><span class="img-label">Customer Signature</span></div>
          </div>
        </div>

        <!-- Cheque Front & Back upload -->
        <div class="vmodal-section" style="margin-bottom:0;">
          <div class="vmodal-section-title" style="margin-bottom:8px;"><i class="fa-solid fa-money-check" style="color:#0369a1;"></i> Cheque Images — Front &amp; Back</div>
          <div class="cheque-upload-row">
            <div class="upload-box">
              <label><i class="fa-solid fa-image"></i> Cheque Front <span id="frontRequiredLabel" style="color:#dc2626;">(required)</span></label>
              <input type="file" id="uploadFront" accept="image/*" onchange="handleFrontUpload(this)">
              <div class="upload-preview" id="prevFront"><i class="fa-regular fa-image prev-empty"></i></div>
              <div class="ai-scan-loading" id="aiLoading"><i class="fa-solid fa-spinner fa-spin"></i> AI is reading cheque details…</div>
              <div class="ai-scan-box" id="aiScanBox">
                <div class="ai-scan-title"><i class="fa-solid fa-robot"></i> AI Detected Cheque Details</div>
                <div class="ai-scan-grid">
                  <div class="ai-field"><span class="ai-field-lbl">Cheque No.</span><span class="ai-field-val" id="ai_cheque_no">—</span></div>
                  <div class="ai-field"><span class="ai-field-lbl">Bank Code</span><span class="ai-field-val" id="ai_bank_code">—</span></div>
                  <div class="ai-field"><span class="ai-field-lbl">Branch Code</span><span class="ai-field-val" id="ai_branch_code">—</span></div>
                  <div class="ai-field"><span class="ai-field-lbl">Cheque Date</span><span class="ai-field-val" id="ai_cheque_date">—</span></div>
                  <div class="ai-field"><span class="ai-field-lbl">Amount (Rs.)</span><span class="ai-field-val" id="ai_amount">—</span></div>
                  <div class="ai-field"><span class="ai-field-lbl">Account No.</span><span class="ai-field-val" id="ai_account_no">—</span></div>
                  <div class="ai-field" style="grid-column:span 2"><span class="ai-field-lbl">Payee Name</span><span class="ai-field-val" id="ai_payee" style="font-family:inherit;font-size:13px;">—</span></div>
                </div>
              </div>
            </div>
            <div class="upload-box">
              <label><i class="fa-solid fa-image"></i> Cheque Back</label>
              <input type="file" id="uploadBack" accept="image/*" onchange="previewUpload(this,'prevBack')">
              <div class="upload-preview" id="prevBack"><i class="fa-regular fa-image prev-empty"></i></div>
            </div>
          </div>
        </div>

        <!-- ★ CHEQUE INFO STRIP -->
        <div class="chq-info-strip" id="vstrip" style="margin-bottom:0;margin-top:14px;">
          <div class="chq-info-strip-item">
            <span class="chq-info-strip-lbl"><i class="fa-solid fa-hashtag"></i> Cheque No.</span>
            <span class="chq-info-strip-val" id="vs_cheque_no">—</span>
          </div>
          <div class="chq-info-strip-item">
            <span class="chq-info-strip-lbl"><i class="fa-regular fa-calendar-check"></i> Received Date</span>
            <span class="chq-info-strip-val" id="vs_rec_date">—</span>
          </div>
          <div class="chq-info-strip-item">
            <span class="chq-info-strip-lbl"><i class="fa-regular fa-calendar"></i> Cheque Date</span>
            <span class="chq-info-strip-val" id="vs_cheque_date_txt">—</span>
          </div>
          <div class="chq-info-strip-item">
            <span class="chq-info-strip-lbl"><i class="fa-solid fa-building-columns"></i> Bank</span>
            <span class="chq-info-strip-val" id="vs_bank">—</span>
            <span id="vs_bank_code" style="font-size:11px;color:rgba(165,180,252,.78);font-family:'Courier New',monospace;font-weight:600;margin-top:2px;display:block;"></span>
          </div>
          <div class="chq-info-strip-item">
            <span class="chq-info-strip-lbl"><i class="fa-solid fa-code-branch"></i> Branch</span>
            <span class="chq-info-strip-val" id="vs_branch">—</span>
            <span id="vs_branch_code" style="font-size:11px;color:rgba(165,180,252,.78);font-family:'Courier New',monospace;font-weight:600;margin-top:2px;display:block;"></span>
          </div>
          <div class="chq-info-strip-item">
            <span class="chq-info-strip-lbl"><i class="fa-solid fa-coins"></i> Amount (Rs.)</span>
            <span class="chq-info-strip-val" id="vs_amount_txt">—</span>
          </div>
        </div>

        <!-- ★ DETAILS SECTION — blue card, very bottom -->
        <div class="chq-details-section" style="margin-top:14px;">
          <div class="chq-details-title"><i class="fa-solid fa-circle-info"></i> Cheque Details</div>
          <div class="chq-details-grid">
            <div class="chq-details-item">
              <span class="chq-details-lbl"><i class="fa-solid fa-id-badge"></i> SR Code</span>
              <span class="chq-details-val plain" id="vd_sr_code">—</span>
            </div>
            <div class="chq-details-item">
              <span class="chq-details-lbl"><i class="fa-solid fa-user-tag"></i> T-Code</span>
              <span class="chq-details-val plain" id="vd_tcode">—</span>
            </div>
            <div class="chq-details-item">
              <span class="chq-details-lbl"><i class="fa-solid fa-store"></i> Customer</span>
              <span class="chq-details-val plain" id="vd_customer">—</span>
            </div>
            <div class="chq-details-item">
              <span class="chq-details-lbl"><i class="fa-solid fa-truck"></i> Delivery Date</span>
              <span class="chq-details-val plain" id="vd_delivery_date">—</span>
            </div>
            <div class="chq-details-item">
              <span class="chq-details-lbl"><i class="fa-solid fa-building-columns"></i> Bank Code</span>
              <span class="chq-details-val" id="vd_bank_code" style="color:#1d4ed8;">—</span>
            </div>
            <div class="chq-details-item">
              <span class="chq-details-lbl"><i class="fa-solid fa-code-branch"></i> Branch Code</span>
              <span class="chq-details-val" id="vd_branch_code" style="color:#1d4ed8;">—</span>
            </div>
            <div class="chq-details-item">
              <span class="chq-details-lbl"><i class="fa-solid fa-layer-group"></i> Cheque Mode</span>
              <span class="chq-details-val plain" id="vd_mode">—</span>
            </div>
            <div class="chq-details-item">
              <span class="chq-details-lbl"><i class="fa-solid fa-circle-half-stroke"></i> Status</span>
              <span class="chq-details-val status-val" id="vd_status">—</span>
            </div>
          </div>
       </div>

        <!-- ★ EDIT DETAILS SECTION -->
        <div style="margin-top:14px;">
          <button type="button" onclick="toggleVerifyEdit()" id="verifyEditToggleBtn"
            style="width:100%;display:flex;align-items:center;justify-content:center;gap:8px;
                   background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;
                   border-radius:8px;padding:9px 18px;font-size:12px;font-weight:700;
                   cursor:pointer;font-family:inherit;transition:filter .2s;">
            <i class="fa-solid fa-pen-to-square"></i> Edit Cheque Details
            <i class="fa-solid fa-chevron-down" id="verifyEditIcon"
               style="transition:transform .25s;margin-left:auto;"></i>
          </button>
          <div id="verifyEditSection" style="display:none;background:#fffbeb;border:2px solid #fde68a;
               border-top:none;border-radius:0 0 10px 10px;padding:16px 18px;">
            <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;
                        color:#92400e;margin-bottom:14px;display:flex;align-items:center;gap:6px;">
              <i class="fa-solid fa-pen-ruler"></i> Update Cheque Details
            </div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px;">
              <div style="display:flex;flex-direction:column;gap:5px;">
                <label style="font-size:11px;font-weight:700;color:#374151;">
                  <i class="fa-solid fa-hashtag"></i> Cheque No. <span style="color:#dc2626;">*</span>
                </label>
                <input type="text" id="vEdit_chequeNo" class="vei"
                       placeholder="e.g. 001234"
                       style="font-family:'Courier New',monospace;font-weight:700;letter-spacing:.04em;">
              </div>
              <div style="display:flex;flex-direction:column;gap:5px;">
                <label style="font-size:11px;font-weight:700;color:#374151;">
                  <i class="fa-regular fa-calendar"></i> Cheque Date
                </label>
                <input type="date" id="vEdit_chequeDate" class="vei">
              </div>
              <div style="display:flex;flex-direction:column;gap:5px;">
                <label style="font-size:11px;font-weight:700;color:#374151;">
                  <i class="fa-regular fa-calendar-check"></i> Receive Date
                </label>
                <input type="date" id="vEdit_recDate" class="vei">
              </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:14px;">
              <div style="display:flex;flex-direction:column;gap:5px;">
                <label style="font-size:11px;font-weight:700;color:#374151;">
                  <i class="fa-solid fa-layer-group"></i> Cheque Mode
                </label>
                <select id="vEdit_mode" class="vei vei-select">
                  <option value="payee_only">Payee Only</option>
                  <option value="cash">Cash / Bearer</option>
                  <option value="third_party_cash">Third Party Cash</option>
                </select>
              </div>
              <div style="display:flex;flex-direction:column;gap:5px;">
                <label style="font-size:11px;font-weight:700;color:#374151;">
                  <i class="fa-solid fa-building-columns"></i> Bank
                </label>
                <select id="vEdit_bank" style="width:100%;"></select>
              </div>
              <div style="display:flex;flex-direction:column;gap:5px;">
                <label style="font-size:11px;font-weight:700;color:#374151;">
                  <i class="fa-solid fa-code-branch"></i> Branch
                </label>
                <select id="vEdit_branch" style="width:100%;"></select>
              </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:8px;align-items:center;">
              <span id="vEditMsg" style="font-size:11px;color:#6b7280;flex:1;"></span>
              <button type="button" onclick="toggleVerifyEdit()"
                style="background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;border-radius:7px;
                       padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;">
                <i class="fa-solid fa-xmark"></i> Cancel
              </button>
              <button type="button" id="vEditUpdateBtn" onclick="submitVerifyEdit()"
                style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;
                       border-radius:7px;padding:8px 18px;font-size:12px;font-weight:700;
                       cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:6px;">
                <i class="fa-solid fa-floppy-disk"></i> Update Details
              </button>
            </div>
          </div>
        </div>

      </div>
    <div class="vmodal-actions" style="flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:8px;background:#fffbeb;border:1.5px solid #fde68a;border-radius:8px;padding:6px 12px;flex-shrink:0;">
          <label style="font-size:11px;font-weight:700;color:#92400e;white-space:nowrap;"><i class="fa-solid fa-layer-group"></i> Mode</label>
          <select id="vActionMode" style="border:1.5px solid #fde68a;border-radius:6px;padding:6px 10px;font-size:12px;font-weight:600;font-family:inherit;color:#92400e;background:#fff;outline:none;cursor:pointer;min-width:150px;">
            <option value="payee_only">Payee Only</option>
            <option value="cash">Cash / Bearer</option>
            <option value="third_party_cash">Third Party Cash</option>
          </select>
          <label style="font-size:11px;font-weight:700;color:#92400e;white-space:nowrap;"><i class="fa-solid fa-code-branch"></i> Branch</label>
          <div id="vActionBranchWrap" style="min-width:190px;"></div>
          <button type="button" id="vActionModeBtn" onclick="submitQuickModeUpdate()" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;border-radius:6px;padding:6px 14px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;transition:filter .2s;">
            <i class="fa-solid fa-floppy-disk"></i> Update
          </button>
        </div>
        <button class="vbtn vbtn-verify" onclick="submitVerify()"><i class="fa-solid fa-circle-check"></i> Verified</button>
        <button class="vbtn vbtn-sendback" onclick="toggleSendBackBox()"><i class="fa-solid fa-rotate-left"></i> Send Back</button>
        <button class="vbtn vbtn-cancel" onclick="closeVerifyModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
        <div class="sendback-reason-wrap" id="sendBackReasonWrap">
          <label><i class="fa-solid fa-comment-dots"></i> Reason for Sending Back</label>
          <div id="sb-select2-wrap"><select id="sendBackReason" style="width:100%;"><option value="">— Select a reason —</option></select></div>
          <button class="sendback-confirm-btn" onclick="submitSendBack()"><i class="fa-solid fa-paper-plane"></i> Confirm Send Back</button>
        </div>
      </div>
    </div>`;
    document.body.appendChild(el);
})();

let _vChequeId=null;

/* CHQ-PATCH3-OPENVERIFY */
async function openVerifyModal(id){
    _vChequeId=id;
    const tr=document.getElementById('row-'+id);if(!tr)return;

    /* Reset file inputs */
    document.getElementById('uploadFront').value='';
    document.getElementById('uploadBack').value='';

    /* Read row data */
    const chequeNo   = tr.dataset.cheqno   || '—';
    const tcode      = tr.dataset.tcode    || '';
    const existFront = tr.dataset.front    || '';
    const existBack  = tr.dataset.back     || '';
    const amt        = parseFloat(tr.dataset.amt || '0');
    const status     = tr.dataset.status   || 'pending';
    const custName   = tr.dataset.cust     || '—';

    /* Get display values from rendered cells */
    const cells = tr.querySelectorAll('td');
    const recDateTxt    = cells[4]?.querySelector('.date-txt')?.textContent?.trim() || '—';
    const chequeDateTxt = cells[5]?.querySelector('.date-txt')?.textContent?.trim() || '—';
    const bankName      = cells[8]?.querySelector('div')?.textContent?.trim() || '—';
    const branchName    = cells[8]?.querySelector('.cust-sub')?.textContent?.trim() || '—';
    const srCode        = cells[2]?.querySelector('.sr-pill')?.textContent?.trim() || '—';
    const deliveryDate  = cells[3]?.querySelector('.date-txt')?.textContent?.trim() || '—';
    const modeTxt       = cells[7]?.querySelector('.mode-badge')?.textContent?.trim() || '—';

    /* Fill read-only strip labels */
    document.getElementById('vs_cheque_no').textContent      = chequeNo;
    document.getElementById('vs_rec_date').textContent       = recDateTxt;
    document.getElementById('vs_cheque_date_txt').textContent = chequeDateTxt;
    document.getElementById('vs_bank').textContent           = bankName;
    document.getElementById('vs_branch').textContent         = branchName;
    document.getElementById('vs_amount_txt').textContent     = 'Rs. ' + amt.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    /* BRANCH_CODE_PATCH_V1 — strip code spans */
    const _pBkCode = tr.dataset.bankcode   || '';
    const _pBrCode = tr.dataset.branchcode || '';
    const _pVsBkc  = document.getElementById('vs_bank_code');
    if(_pVsBkc)  _pVsBkc.textContent  = _pBkCode ? '(' + _pBkCode + ')' : '';
    const _pVsBrc  = document.getElementById('vs_branch_code');
    if(_pVsBrc)  _pVsBrc.textContent  = _pBrCode ? '(' + _pBrCode + ')' : '';

    /* Fill details section */
    document.getElementById('vd_sr_code').textContent   = srCode;
    document.getElementById('vd_tcode').textContent     = tcode || '—';
    document.getElementById('vd_customer').textContent  = custName;
    document.getElementById('vd_delivery_date').textContent = deliveryDate;
    document.getElementById('vd_mode').textContent      = modeTxt;
    /* BRANCH_CODE_PATCH_V1 — detail card */
    const _pdBkEl = document.getElementById('vd_bank_code');
    if(_pdBkEl) _pdBkEl.textContent = tr.dataset.bankcode   || '—';
    const _pdBrEl = document.getElementById('vd_branch_code');
    if(_pdBrEl) _pdBrEl.textContent = tr.dataset.branchcode || '—';
    const stLbls = {pending:'Pending',to_be_bank:'To Be Bank',deposited:'Deposited',sent_back:'Sent Back',cleared:'Cleared',returned:'Returned'};
    const stIcons = {pending:'fa-clock',to_be_bank:'fa-inbox',deposited:'fa-building-columns',sent_back:'fa-rotate-left',cleared:'fa-circle-check',returned:'fa-circle-xmark'};
    document.getElementById('vd_status').innerHTML = `<span class="chq-details-badge chq-db-${status}"><i class="fa-solid ${stIcons[status]||'fa-circle-dot'}"></i> ${stLbls[status]||status}</span>`;

    /* Existing images */
    if(existFront){
        document.getElementById('prevFront').innerHTML=`<img src="${esc(existFront)}" alt="Front" style="width:100%;object-fit:contain;max-height:520px;border-radius:7px;">`;
        document.getElementById('frontRequiredLabel').textContent='(already uploaded — replace optional)';
        document.getElementById('frontRequiredLabel').style.color='#16a34a';
    }else{
        document.getElementById('prevFront').innerHTML='<i class="fa-regular fa-image prev-empty"></i>';
        document.getElementById('frontRequiredLabel').textContent='(required)';
        document.getElementById('frontRequiredLabel').style.color='#dc2626';
    }
    if(existBack){
        document.getElementById('prevBack').innerHTML=`<img src="${esc(existBack)}" alt="Back" style="width:100%;object-fit:contain;max-height:520px;border-radius:7px;">`;
    }else{
        document.getElementById('prevBack').innerHTML='<i class="fa-regular fa-image prev-empty"></i>';
    }

    /* Reset send-back reason */
    const sbSel=document.getElementById('sendBackReason');
    if(sbSel){if(window.$&&$(sbSel).data('select2'))$(sbSel).val(null).trigger('change');else sbSel.value='';}
    document.getElementById('sendBackReasonWrap').classList.remove('open');

    /* Reset AI box */
    document.getElementById('aiScanBox').classList.remove('visible');
    document.getElementById('aiLoading').classList.remove('visible');
    ['ai_cheque_no','ai_bank_code','ai_branch_code','ai_cheque_date','ai_amount','ai_account_no','ai_payee'].forEach(fid=>{
        const el2=document.getElementById(fid);if(el2){el2.textContent='—';el2.className='ai-field-val';}
    });

    /* Customer images */
    document.getElementById('custSealBox').innerHTML='<i class="fa-solid fa-stamp cust-img-placeholder"></i><span class="img-label">Customer Seal</span>';
    document.getElementById('custSigBox').innerHTML='<i class="fa-solid fa-signature cust-img-placeholder"></i><span class="img-label">Customer Signature</span>';

    document.getElementById('verifyModal').classList.add('open');
    document.body.style.overflow='hidden';
    
    /* ── Pre-fill edit section fields ── */
    _vEditData = { bank_code: tr.dataset.bankcode||'', branch_code: tr.dataset.branchcode||'' };
    /* BRANCH_CODE_PATCH_V1 — init footer branch select */
    _initActionBarBranch(tr.dataset.bankcode||'', tr.dataset.branchcode||'');
  document.getElementById('vEdit_chequeNo').value   = tr.dataset.cheqno     || '';
    document.getElementById('vEdit_chequeDate').value = tr.dataset.chequedate  || '';
    document.getElementById('vEdit_recDate').value    = tr.dataset.recdate     || '';
    // amount not editable — preserved from row
    const _rawMode = (tr.dataset.mode||'').toLowerCase().replace(/\s+/g,'_');
    const _modeMap = {payee_only:'payee_only',payee:'payee_only',account_payee:'payee_only',
                      cash:'cash',bearer:'cash',third_party_cash:'third_party_cash',third_party:'third_party_cash'};
document.getElementById('vActionMode').value = _modeMap[_rawMode]||'payee_only';
    /* Reset edit section to collapsed */
    const _ves = document.getElementById('verifyEditSection');
    if(_ves) _ves.style.display='none';
    const _vei2 = document.getElementById('verifyEditIcon');
    if(_vei2) _vei2.style.transform='';
    if(window.$){ try{$('#vEdit_bank,#vEdit_branch').each(function(){try{$(this).select2('destroy');}catch(e){}});}catch(e){} }
    document.getElementById('vEdit_bank').innerHTML   = '<option value="">— Select Bank —</option>';
    document.getElementById('vEdit_branch').innerHTML = '<option value="">— Select Branch —</option>';

    if(tcode){
        try{
            const res=await fetch(`cheques.php?ajax=customer_images&t_code=${encodeURIComponent(tcode)}`);
            const data=await res.json();
            if(data.seal)
                document.getElementById('custSealBox').innerHTML=`<img src="${esc(data.seal)}" alt="Seal" style="height:80px;max-width:45%;object-fit:contain;border-radius:6px;cursor:pointer;" onclick="openFullscreen('${esc(data.seal).replace(/'/g,"\\'")}')" ><span class="img-label">Customer Seal</span>`;
            if(data.signature)
                document.getElementById('custSigBox').innerHTML=`<img src="${esc(data.signature)}" alt="Signature" style="height:80px;max-width:45%;object-fit:contain;border-radius:6px;cursor:pointer;" onclick="openFullscreen('${esc(data.signature).replace(/'/g,"\\'")}')" ><span class="img-label">Customer Signature</span>`;
        }catch(e){}
    }
}

function closeVerifyModal(){document.getElementById('verifyModal').classList.remove('open');document.body.style.overflow='';_vChequeId=null;}

function toggleSendBackBox(){
    const wrap=document.getElementById('sendBackReasonWrap');wrap.classList.toggle('open');if(!wrap.classList.contains('open'))return;
    const sel=document.getElementById('sendBackReason');const alreadyInited=window.$&&$(sel).data('select2');
    if(!alreadyInited&&sel.options.length<=1){
        fetch('cheques.php?ajax=sendback_reasons').then(r=>r.json()).then(data=>{
            if(data.success&&data.reasons)data.reasons.forEach(item=>{const opt=document.createElement('option');opt.value=item.reason;opt.textContent=item.reason;sel.appendChild(opt);});
            if(window.$){$(sel).select2({placeholder:'— Select a reason —',allowClear:true,width:'100%',dropdownParent:$(document.body),dropdownCssClass:'sb-sendback-dropdown'});setTimeout(()=>$(sel).select2('open'),80);}
        }).catch(()=>{});
    }else{if(window.$)setTimeout(()=>$(sel).select2('open'),50);}
}

function previewUpload(input,previewId){const prev=document.getElementById(previewId);if(!input.files||!input.files[0])return;const reader=new FileReader();reader.onload=e=>{prev.innerHTML=`<img src="${e.target.result}" alt="Preview" style="width:100%;object-fit:contain;max-height:520px;border-radius:7px;">`;};reader.readAsDataURL(input.files[0]);}

async function handleFrontUpload(input){
    if(!input.files||!input.files[0])return;const file=input.files[0];
    const reader=new FileReader();reader.onload=e=>{document.getElementById('prevFront').innerHTML=`<img src="${e.target.result}" alt="Front" style="width:100%;object-fit:contain;max-height:520px;border-radius:7px;">`;};reader.readAsDataURL(file);
    document.getElementById('frontRequiredLabel').textContent='(new file selected)';
    document.getElementById('frontRequiredLabel').style.color='#16a34a';
    document.getElementById('aiScanBox').classList.remove('visible');document.getElementById('aiLoading').classList.add('visible');document.getElementById('aiLoading').innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> AI is reading cheque details…';
    const allFields=['ai_cheque_no','ai_bank_code','ai_branch_code','ai_cheque_date','ai_amount','ai_account_no','ai_payee'];
    allFields.forEach(fid=>{const el2=document.getElementById(fid);if(el2){el2.textContent='…';el2.className='ai-field-val';}});
    const showError=(msg)=>{document.getElementById('aiLoading').classList.remove('visible');document.getElementById('aiScanBox').classList.add('visible');const titleEl=document.getElementById('aiScanBox').querySelector('.ai-scan-title');if(titleEl)titleEl.innerHTML=`<i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i> <span style="color:#dc2626;">${esc(msg)}</span>`;allFields.forEach(fid=>{const el2=document.getElementById(fid);if(el2){el2.textContent='—';el2.className='ai-field-val empty';}});};
    const setField=(elemId,val)=>{const el2=document.getElementById(elemId);if(!el2)return;if(val!==null&&val!==undefined&&String(val).trim()!==''&&String(val)!=='null'){el2.textContent=String(val).trim();el2.className='ai-field-val';}else{el2.textContent='Not detected';el2.className='ai-field-val empty';}};
    try{if(file.size>3.8*1024*1024){showError('Image too large (max 3.8 MB).');return;}
        const proxyFd=new FormData();proxyFd.append('cheque_front',file);let apiRes;
        try{apiRes=await fetch('analyze_cheque.php',{method:'POST',body:proxyFd});}catch(fetchErr){showError('Cannot reach analyze_cheque.php');return;}
        if(!apiRes.ok){showError('Server error: HTTP '+apiRes.status);return;}
        const rawText=await apiRes.text();let parsed;try{parsed=JSON.parse(rawText);}catch(jsonErr){showError('Invalid response');return;}
        if(!parsed.success){showError(parsed.error||'Unknown error');return;}
        document.getElementById('aiLoading').classList.remove('visible');document.getElementById('aiScanBox').classList.add('visible');
        const titleEl=document.getElementById('aiScanBox').querySelector('.ai-scan-title');if(titleEl)titleEl.innerHTML='<i class="fa-solid fa-robot"></i> AI Detected Cheque Details';
        setField('ai_cheque_no',parsed.cheque_no);setField('ai_bank_code',parsed.bank_code);setField('ai_branch_code',parsed.branch_code);setField('ai_cheque_date',parsed.cheque_date);setField('ai_amount',parsed.amount);setField('ai_account_no',parsed.account_no);setField('ai_payee',parsed.payee);
    }catch(e){showError('Unexpected error: '+e.message);}
}

async function submitVerify(){
    if(!_vChequeId) return;
    const frontFile = document.getElementById('uploadFront').files[0];
    const tr = document.getElementById('row-'+_vChequeId);
    const existingFront = tr ? (tr.dataset.front || '') : '';
    if(!frontFile && !existingFront) { showToast('Please upload the cheque front image first','err'); return; }
    const fd = new FormData();
    fd.append('ajax_action','verify_cheque'); fd.append('cheque_id',_vChequeId);
    if(frontFile) fd.append('cheque_front',frontFile);
    const backFile = document.getElementById('uploadBack').files[0];
    if(backFile) fd.append('cheque_back',backFile);
    try{const res=await fetch('cheques.php',{method:'POST',body:fd});const data=await res.json();
        if(data.success){closeVerifyModal();showToast('Cheque verified ✓','ok');fetchRows();}
        else showToast(data.error||'Verification failed','err');
    }catch(e){showToast('Network error: '+e.message,'err');}
}

async function submitSendBack(){
    if(!_vChequeId)return;const sel=document.getElementById('sendBackReason');const reason=(window.$?$(sel).val():sel.value)||'';
    if(!reason||!reason.trim()){showToast('Please select a reason','err');return;}
    const fd=new FormData();fd.append('ajax_action','send_back_cheque');fd.append('cheque_id',_vChequeId);fd.append('reason',reason.trim());
    try{const res=await fetch('cheques.php',{method:'POST',body:fd});const data=await res.json();
        if(data.success){closeVerifyModal();showToast('Cheque sent back ✓','ok');fetchRows();}
        else showToast(data.error||'Send back failed','err');
    }catch(e){showToast('Network error','err');}
}

/* ══ VIEW UPLOADED DETAILS MODAL ══ */
/* CHQ-PATCH4-OPENVIEW */
async function openViewModal(id,chequeNo,tcode,custName,amt,frontImg,backImg){
    /* Header */
    document.getElementById('viewModalChequeNo').textContent = chequeNo || '';

    /* Info strip */
    const vmno = document.getElementById('vm_cheque_no_val');
    if(vmno) vmno.textContent = chequeNo || '—';

    const vmamt = document.getElementById('vm_view_amt');
    if(vmamt) vmamt.textContent = 'Rs. ' + (amt || '—');

    const vmtc = document.getElementById('vm_tcode'); if(vmtc) vmtc.textContent = tcode || '—';
    const vmcu = document.getElementById('vm_cust');  if(vmcu) vmcu.textContent = custName || '—';

    /* Populate row-sourced strip fields + details */
    const tr = document.getElementById('row-'+id);
    if(tr){
        const cells  = tr.querySelectorAll('td');
        const rdate  = cells[4]?.querySelector('.date-txt')?.textContent?.trim() || '—';
        const cdate  = cells[5]?.querySelector('.date-txt')?.textContent?.trim() || '—';
        const bname  = cells[8]?.querySelector('div')?.textContent?.trim()       || '—';
        const brname = cells[8]?.querySelector('.cust-sub')?.textContent?.trim() || '—';
        const fieldMap = {vm_rec_date_val:rdate, vm_cheque_date_val:cdate, vm_bank_val:bname, vm_branch_val:brname};
        Object.entries(fieldMap).forEach(([elid,val])=>{
            const el=document.getElementById(elid); if(el) el.textContent=val;
        });

        /* Details section */
        const srCode      = cells[2]?.querySelector('.sr-pill')?.textContent?.trim()  || '—';
        const delivDate   = cells[3]?.querySelector('.date-txt')?.textContent?.trim() || '—';
        const modeTxt     = cells[7]?.querySelector('.mode-badge')?.textContent?.trim() || '—';
        const status      = tr.dataset.status || 'pending';
        const stLbls  = {pending:'Pending',to_be_bank:'To Be Bank',deposited:'Deposited',sent_back:'Sent Back',cleared:'Cleared',returned:'Returned'};
        const stIcons = {pending:'fa-clock',to_be_bank:'fa-inbox',deposited:'fa-building-columns',sent_back:'fa-rotate-left',cleared:'fa-circle-check',returned:'fa-circle-xmark'};
        const dmap = {vdv_sr_code:srCode, vdv_tcode:tcode||'—', vdv_customer:custName||'—', vdv_delivery_date:delivDate, vdv_mode:modeTxt};
        Object.entries(dmap).forEach(([elid,val])=>{ const el=document.getElementById(elid); if(el) el.textContent=val; });
        const stEl = document.getElementById('vdv_status');
        if(stEl) stEl.innerHTML=`<span class="chq-details-badge chq-db-${status}"><i class="fa-solid ${stIcons[status]||'fa-circle-dot'}"></i> ${stLbls[status]||status}</span>`;
    }

    /* Front / Back images */
    const fw=document.getElementById('vm_front_wrap');
    fw.innerHTML=frontImg
        ?`<img src="${esc(frontImg)}" alt="Front" onclick="openFullscreen('${esc(frontImg).replace(/'/g,"\\'")}')" title="Click to enlarge">`
        :`<div class="viewmodal-img-empty"><i class="fa-regular fa-image"></i><span>No front image</span></div>`;

    const bw=document.getElementById('vm_back_wrap');
    bw.innerHTML=backImg
        ?`<img src="${esc(backImg)}" alt="Back" onclick="openFullscreen('${esc(backImg).replace(/'/g,"\\'")}')" title="Click to enlarge">`
        :`<div class="viewmodal-img-empty"><i class="fa-regular fa-image"></i><span>No back image</span></div>`;

    /* Customer images */
    document.getElementById('vm_seal_wrap').innerHTML=`<div class="viewmodal-cust-empty"><i class="fa-solid fa-stamp"></i><span>Loading…</span></div>`;
    document.getElementById('vm_sig_wrap').innerHTML=`<div class="viewmodal-cust-empty"><i class="fa-solid fa-signature"></i><span>Loading…</span></div>`;

    document.getElementById('viewModal').classList.add('open');
    document.body.style.overflow='hidden';

    if(tcode){
        try{
            const res=await fetch(`cheques.php?ajax=customer_images&t_code=${encodeURIComponent(tcode)}`);
            const data=await res.json();
            document.getElementById('vm_seal_wrap').innerHTML=data.seal
                ?`<img src="${esc(data.seal)}" alt="Seal" onclick="openFullscreen('${esc(data.seal).replace(/'/g,"\\'")}')" style="cursor:pointer;">`
                :`<div class="viewmodal-cust-empty"><i class="fa-solid fa-stamp"></i><span>No seal</span></div>`;
            document.getElementById('vm_sig_wrap').innerHTML=data.signature
                ?`<img src="${esc(data.signature)}" alt="Sig" onclick="openFullscreen('${esc(data.signature).replace(/'/g,"\\'")}')" style="cursor:pointer;">`
                :`<div class="viewmodal-cust-empty"><i class="fa-solid fa-signature"></i><span>No signature</span></div>`;
        }catch(e){
            document.getElementById('vm_seal_wrap').innerHTML=`<div class="viewmodal-cust-empty"><i class="fa-solid fa-stamp"></i><span>No seal</span></div>`;
            document.getElementById('vm_sig_wrap').innerHTML=`<div class="viewmodal-cust-empty"><i class="fa-solid fa-signature"></i><span>No signature</span></div>`;
        }
    }else{
        document.getElementById('vm_seal_wrap').innerHTML=`<div class="viewmodal-cust-empty"><i class="fa-solid fa-stamp"></i><span>No seal</span></div>`;
        document.getElementById('vm_sig_wrap').innerHTML=`<div class="viewmodal-cust-empty"><i class="fa-solid fa-signature"></i><span>No signature</span></div>`;
    }
}
function closeViewModal(){document.getElementById('viewModal').classList.remove('open');document.body.style.overflow='';}
document.getElementById('viewModal')?.addEventListener('click',function(e){if(e.target===this)closeViewModal();});

function openFullscreen(src){const ov=document.getElementById('imgFullscreen'),img=document.getElementById('fsImg');if(!ov||!img)return;img.src=src;ov.classList.add('open');}
function closeFullscreen(){const ov=document.getElementById('imgFullscreen');if(ov)ov.classList.remove('open');}
document.addEventListener('keydown',function(e){if(e.key==='Escape'){const fs=document.getElementById('imgFullscreen');if(fs&&fs.classList.contains('open')){closeFullscreen();return;}const vm=document.getElementById('viewModal');if(vm&&vm.classList.contains('open')){closeViewModal();return;}const sm=document.getElementById('statusUpdateModal');if(sm&&sm.classList.contains('open')){closeStatusUpdateModal();return;}}});

/* ══ DAILY REPORT MODAL ══ */
let _drmTotal=0, _drmCount=0;
function openDailyReportModal(){
    const cnt=document.getElementById('filteredCount');
    const amt=document.getElementById('filteredAmt');
    _drmCount=parseInt(cnt?.textContent||'0');
    if(!_drmCount){showToast('No cheques in current view to save','err');return;}
    document.getElementById('drmChequeCount').textContent=_drmCount;
    document.getElementById('drmTotalAmt').textContent=amt?.textContent||'Rs. 0';
    document.getElementById('drmSentDate').value=new Date().toISOString().split('T')[0];
    document.getElementById('drmRemark').value='';
    document.getElementById('dailyReportModal').classList.add('open');
}
function closeDailyReportModal(){document.getElementById('dailyReportModal').classList.remove('open');}
document.getElementById('dailyReportModal')?.addEventListener('click',function(e){if(e.target===this)closeDailyReportModal();});

async function saveDailyReport(){
    const sentDate=document.getElementById('drmSentDate').value;
    if(!sentDate){showToast('Please select a sent date','err');return;}
    const remark=document.getElementById('drmRemark').value.trim();
    const btn=document.getElementById('drmSaveBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const f=getFilters();
    const fd=new FormData();
    fd.append('ajax_action','save_daily_report');
    fd.append('sent_date',sentDate);
    fd.append('remark',remark);
    fd.append('f_sr',f.sr_code);
    fd.append('f_status',f.status);
    fd.append('f_bank',f.bank_code);
    fd.append('f_tcode',f.t_code);
    fd.append('f_from',f.date_from);
    fd.append('f_to',f.date_to);
    fd.append('f_del_from',f.del_date_from);
    fd.append('f_del_to',f.del_date_to);
    fd.append('f_rec_from',f.rec_date_from);
    fd.append('f_rec_to',f.rec_date_to);
    fd.append('f_search',f.q);
    fd.append('f_sampath_only',f.sampath_only);

    try{
        const res=await fetch('cheques.php',{method:'POST',body:fd});
        const text=await res.text();
        let data;try{data=JSON.parse(text);}catch(e){showToast('Server error: invalid response','err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Report';return;}
        if(data.success){
            closeDailyReportModal();
            showToast('✓ Daily report saved — '+data.total_cheques+' cheques (Report #'+data.report_id+')','ok');
        }else{
            showToast(data.error||'Save failed','err');
        }
    }catch(e){showToast('Network error: '+e.message,'err');}
    btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Report';
}

function scrollToBottom(){const b=document.getElementById('pagerWrapBottom');if(b)b.scrollIntoView({behavior:'smooth',block:'end'});else window.scrollTo({top:document.body.scrollHeight,behavior:'smooth'});}
function scrollToTop(){window.scrollTo({top:0,behavior:'smooth'});}










/* ══════════════════════════════════════════════════════
   VERIFY MODAL — EDIT DETAILS SECTION
══════════════════════════════════════════════════════ */
let _vEditData = null;

function toggleVerifyEdit() {
    const sec  = document.getElementById('verifyEditSection');
    const icon = document.getElementById('verifyEditIcon');
    const open = sec.style.display !== 'none';
    sec.style.display   = open ? 'none' : 'block';
    if(icon) icon.style.transform = open ? '' : 'rotate(180deg)';
    if(!open) _initVerifyEditSelects();
}

function _initVerifyEditSelects() {
    const bankSel = document.getElementById('vEdit_bank');
    const brSel   = document.getElementById('vEdit_branch');
    if(!bankSel || !brSel) return;

    /* Destroy any existing Select2 on both selects */
    if(window.$){
        try{$(bankSel).off('change.vEdit');$(bankSel).select2('destroy');}catch(e){}
        try{$(brSel).select2('destroy');}catch(e){}
    }

    const preBank   = _vEditData?.bank_code   || '';
    const preBranch = _vEditData?.branch_code || '';

    /* Build bank options with pre-selected value baked into HTML */
    let bOpts = '<option value="">— Select Bank —</option>';
    (BANKS||[]).forEach(b => {
        const sel = (preBank && preBank === b.bank_code) ? ' selected' : '';
        bOpts += `<option value="${b.bank_code}" data-name="${b.bank_name}"${sel}>${b.bank_code} – ${b.bank_name}</option>`;
    });
    bankSel.innerHTML = bOpts;

    /* Init bank Select2 — NO change handler yet.
       Select2 fires a synthetic 'change' on init when a value is pre-selected,
       which would call _loadVerifyBranches with empty selectVal and wipe the
       pre-selected branch before it loads. Handler is attached after branch load. */
    if(window.$){
        $(bankSel).select2({
            placeholder    : '— Select Bank —',
            allowClear     : true,
            width          : '100%',
            dropdownParent : $(document.body)
        });
    }

    /* Init branch placeholder while loading */
    brSel.innerHTML = '<option value="">— Select Branch —</option>';
    if(window.$){
        $(brSel).select2({
            placeholder    : '— Select Branch —',
            allowClear     : true,
            width          : '100%',
            dropdownParent : $(document.body)
        });
    }

    /* Load branches for pre-selected bank, THEN attach bank change handler */
    const loadThen = preBank
        ? _loadVerifyBranches(preBank, preBranch)
        : Promise.resolve();

    loadThen.finally(() => {
        if(window.$){
            $(bankSel).off('change.vEdit').on('change.vEdit', function(){
                _loadVerifyBranches($(this).val() || '', '');
            });
        }
    });
}
async function _loadVerifyBranches(bankCode, selectVal) {
    const sel = document.getElementById('vEdit_branch');
    if(!sel) return;

    /* Must destroy Select2 BEFORE mutating innerHTML, otherwise Select2
       loses track of the element and throws errors */
    if(window.$){ try{$(sel).select2('destroy');}catch(e){} }

    if(!bankCode){
        sel.innerHTML = '<option value="">— Select Branch —</option>';
        if(window.$) $(sel).select2({
            placeholder    : '— Select Branch —',
            allowClear     : true,
            width          : '100%',
            dropdownParent : $(document.body)
        });
        return;
    }

    sel.innerHTML = '<option value="">Loading…</option>';

    try{
        const res  = await fetch('get_bank_branches.php?bank_code=' + encodeURIComponent(bankCode));
        const raw  = await res.json();
        /* Normalise response — endpoint may return bare array or {branches:[...]} */
        const list = Array.isArray(raw) ? raw : (raw.branches || raw.data || []);

        let opts = '<option value="">— Select Branch —</option>';
        list.forEach(b => {
            const picked = (selectVal && selectVal === b.branch_code) ? ' selected' : '';
            opts += `<option value="${b.branch_code}" data-name="${b.branch_name}"${picked}>${b.branch_code} – ${b.branch_name}</option>`;
        });
        sel.innerHTML = opts;
    }catch(e){
        sel.innerHTML = '<option value="">Error loading branches</option>';
    }

    if(window.$) $(sel).select2({
        placeholder    : '— Select Branch —',
        allowClear     : true,
        width          : '100%',
        dropdownParent : $(document.body)
    });
}
async function submitVerifyEdit() {
    if(!_vChequeId) return;
    const chequeNo = (document.getElementById('vEdit_chequeNo').value||'').trim();
    if(!chequeNo) { showToast('Cheque number is required','err'); return; }
    const _tr2b = document.getElementById('row-'+_vChequeId);
    const amount = parseFloat(_tr2b?.dataset.amt||0);

    const bankSel   = document.getElementById('vEdit_bank');
    const brSel     = document.getElementById('vEdit_branch');
    const bankCode  = (window.$ ? $(bankSel).val()  : bankSel.value)  || '';
    const branchCode= (window.$ ? $(brSel).val()    : brSel.value)    || '';
    const bankName  = bankSel.options[bankSel.selectedIndex]?.dataset?.name  || '';
    const branchName= brSel.options[brSel.selectedIndex]?.dataset?.name     || '';
    const chequeDate= document.getElementById('vEdit_chequeDate').value || '';
    const recDate   = document.getElementById('vEdit_recDate').value    || '';
    const mode      = document.getElementById('vEdit_mode').value       || 'payee_only';

    const msg = document.getElementById('vEditMsg');
    const btn = document.getElementById('vEditUpdateBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Updating…';
    if(msg) msg.textContent = '';

    const fd = new FormData();
fd.append('ajax_action',   'update_cheque_details');
    fd.append('cheque_id',     _vChequeId);
    fd.append('cheque_no',     chequeNo);
    fd.append('cheque_date',   chequeDate);
    fd.append('received_date', recDate);
    fd.append('amount',        amount);
    fd.append('bank_code',     bankCode);
    fd.append('bank_name',     bankName);
    fd.append('branch_code',   branchCode);
    fd.append('branch_name',   branchName);
    fd.append('cheque_mode',   mode);

    try {
        const res  = await fetch('cheques.php',{method:'POST',body:fd});
        const data = await res.json();
      if(data.success) {
    showToast('Cheque details updated ✓','ok');
    if(msg) msg.innerHTML = '<i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Saved';

    const tr2 = document.getElementById('row-'+_vChequeId);
    if(tr2){
        tr2.dataset.chequedate = chequeDate;
        tr2.dataset.recdate    = recDate;
        tr2.dataset.cheqno     = chequeNo;
        tr2.dataset.bankcode   = bankCode;
        tr2.dataset.branchcode = branchCode;
        tr2.dataset.bankname   = bankName;
        tr2.dataset.branchname = branchName;
        tr2.dataset.mode       = mode;
        tr2.dataset.amt        = data.new_total.toFixed(2);
    }

    // ★ UPDATE BLUE INFO STRIP
    document.getElementById('vs_cheque_no').textContent       = chequeNo;
    document.getElementById('vs_rec_date').textContent        = recDate    || '—';
    document.getElementById('vs_cheque_date_txt').textContent = chequeDate || '—';
    document.getElementById('vs_bank').textContent            = bankName   || '—';
    document.getElementById('vs_branch').textContent          = branchName || '—';

    // ★ UPDATE DETAILS CARD
    const modeLabels = {payee_only:'Payee Only', cash:'Cash / Bearer', third_party_cash:'Third Party Cash'};
    document.getElementById('vd_mode').textContent = modeLabels[mode] || mode;

    // ★ RE-SYNC EDIT INPUTS
    document.getElementById('vEdit_chequeNo').value   = chequeNo;
    document.getElementById('vEdit_chequeDate').value = chequeDate;
    document.getElementById('vEdit_recDate').value    = recDate;
    document.getElementById('vEdit_mode').value       = mode;
    _vEditData = { bank_code: bankCode, branch_code: branchCode };

    toggleVerifyEdit();
    fetchRows();
}
    } catch(e) {
        showToast('Network error: '+e.message,'err');
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Update Details';
}




/* ══ BRANCH_CODE_PATCH_V1 — ACTION-BAR BRANCH SELECT INIT ══ */
async function _initActionBarBranch(bankCode, selectedBranch) {
    const wrap = document.getElementById('vActionBranchWrap');
    if(!wrap) return;
    const oldSel = document.getElementById('vActionBranchSel');
    if(oldSel && window.$){ try{$(oldSel).select2('destroy');}catch(e){} }
    const sel = document.createElement('select');
    sel.id = 'vActionBranchSel';
    sel.style.cssText = 'width:100%;border:1.5px solid #fde68a;border-radius:6px;font-size:12px;font-weight:600;font-family:inherit;color:#92400e;background:#fff;outline:none;cursor:pointer;';
    sel.innerHTML = '<option value="">— Select Branch —</option>';
    wrap.innerHTML = '';
    wrap.appendChild(sel);
    if(!bankCode){
        if(window.$) $(sel).select2({placeholder:'— Select Branch —',allowClear:true,width:'100%',dropdownParent:$(document.body)});
        return;
    }
    sel.innerHTML = '<option value="">Loading…</option>';
    try{
        const res  = await fetch('get_bank_branches.php?bank_code=' + encodeURIComponent(bankCode));
        const raw  = await res.json();
        const list = Array.isArray(raw) ? raw : (raw.branches || raw.data || []);
        let opts = '<option value="">— Select Branch —</option>';
        list.forEach(b => {
            const picked = (selectedBranch && selectedBranch === b.branch_code) ? ' selected' : '';
            opts += `<option value="${b.branch_code}" data-name="${b.branch_name||''}"${picked}>${b.branch_code} – ${b.branch_name}</option>`;
        });
        sel.innerHTML = opts;
    }catch(e){
        sel.innerHTML = '<option value="">Error loading</option>';
    }
    if(window.$) $(sel).select2({placeholder:'— Select Branch —',allowClear:true,width:'100%',dropdownParent:$(document.body)});
}

/* ══ QUICK MODE + BRANCH UPDATE FROM VERIFY MODAL ══ */
async function submitQuickModeUpdate() {
    if(!_vChequeId) return;
    const mode  = document.getElementById('vActionMode').value || 'payee_only';
    const brSel = document.getElementById('vActionBranchSel');
    const branchCode = brSel ? ((window.$ ? $(brSel).val() : brSel.value) || '') : '';
    const branchName = brSel ? (brSel.options[brSel.selectedIndex]?.dataset?.name || '') : '';
    const tr = document.getElementById('row-'+_vChequeId);
    if(!tr) return;

    const btn = document.getElementById('vActionModeBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Updating…';

    const fd = new FormData();
    fd.append('ajax_action',   'update_cheque_details');
    fd.append('cheque_id',     _vChequeId);
    fd.append('cheque_no',     tr.dataset.cheqno     || '');
    fd.append('cheque_date',   tr.dataset.chequedate  || '');
    fd.append('received_date', tr.dataset.recdate     || '');
    fd.append('bank_code',     tr.dataset.bankcode    || '');
    fd.append('bank_name',     tr.dataset.bankname    || '');
    fd.append('branch_code',   branchCode || tr.dataset.branchcode || '');
    fd.append('branch_name',   branchName || tr.dataset.branchname || '');
    fd.append('cheque_mode',   mode);

    try {
        const res  = await fetch('cheques.php',{method:'POST',body:fd});
        const data = await res.json();
        if(data.success) {
            const updated = [];
            if(mode !== tr.dataset.mode) updated.push('mode');
            if(branchCode && branchCode !== tr.dataset.branchcode) updated.push('branch');
            showToast('✓ Updated: ' + (updated.join(' & ') || 'saved'),'ok');
            tr.dataset.mode = mode;
            if(branchCode){
                tr.dataset.branchcode = branchCode;
                tr.dataset.branchname = branchName;
                const _vsBrc = document.getElementById('vs_branch_code');
                if(_vsBrc) _vsBrc.textContent = '(' + branchCode + ')';
                const _vsBrN = document.getElementById('vs_branch');
                if(_vsBrN && branchName) _vsBrN.textContent = branchName;
                const _vdBrEl = document.getElementById('vd_branch_code');
                if(_vdBrEl) _vdBrEl.textContent = branchCode;
            }
            const modeLabels = {payee_only:'Payee Only',cash:'Cash / Bearer',third_party_cash:'Third Party Cash'};
            document.getElementById('vd_mode').textContent = modeLabels[mode] || mode;
            const editModeSel = document.getElementById('vEdit_mode');
            if(editModeSel) editModeSel.value = mode;
            fetchRows();
        } else {
            showToast(data.error||'Update failed','err');
        }
    } catch(e) {
        showToast('Network error: '+e.message,'err');
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Update';
}


function filterByStatusMulti(statuses) {
    if(window.$) {
        $('#selStatus').val(statuses).trigger('change');
    } else {
        // fallback for no Select2 — set first value only
        const sel = document.getElementById('selStatus');
        if(sel) { Array.from(sel.options).forEach(o => o.selected = statuses.includes(o.value)); }
    }
    currentPage = 1;
    fetchRows();
}


</script>

<?php
/* BRANCH_CODE_PATCH_V1_APPLIED */
/* PATCHED_V2 */ include 'footer.php'; ?>