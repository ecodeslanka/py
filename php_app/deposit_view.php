<?php
/**
 * deposit_view.php
 * ─────────────────────────────────────────────────────────────
 *  Standalone page opened from deposit.php "View" button.
 *  URL params: dep_date, acc_id, dep_type
 *  • Shows all cheques for that deposit batch in a table
 *  • Manual status change + bank ref per cheque
 *  • Status Change Date picker (cleared/returned date)
 *  • Update Deposit Date (batch-level) with confirmation
 *  • NDB Bank CSV import (Cleared / Returned) with preview
 *  • All changes logged to cheque_logs
 *  • CSV format: col0=Tx Date, col1=Value Date, col2=Description,
 *                col3=Reference (bank_ref), col4=Debit, col5=Credit
 *  • Cleared  → amount = Credit col5
 *  • Returned → amount = Debit  col4 (absolute value)
 *  • Already cleared/returned rows are NOT updated again
 * ─────────────────────────────────────────────────────────────
 */
if (session_status() === PHP_SESSION_NONE) session_start();

function get_current_user_label() {
    return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ??
           $_SESSION['full_name'] ?? $_SESSION['email'] ??
           (isset($_SESSION['user_id']) ? 'User #'.$_SESSION['user_id'] : 'system');
}

include_once 'config.php';

/* ── Helper: ensure status_change_date column exists ── */
function ensure_status_change_date_col($conn) {
    $chk = mysqli_query($conn,"SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cheques' AND COLUMN_NAME='status_change_date' LIMIT 1");
    if (!($chk && mysqli_num_rows($chk)>0)) {
        @mysqli_query($conn,"ALTER TABLE cheques ADD COLUMN status_change_date DATE DEFAULT NULL");
    }
}

/* ══════════════════════════════════════════════════════════════
   AJAX HANDLERS  (before header.php)
══════════════════════════════════════════════════════════════ */

/* ── AJAX: update deposit date for entire batch ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_deposit_date') {
    header('Content-Type: application/json');
    $old_date  = trim(mysqli_real_escape_string($conn, $_POST['old_date']  ?? ''));
    $new_date  = trim(mysqli_real_escape_string($conn, $_POST['new_date']  ?? ''));
    $acc_id_p  = intval($_POST['acc_id']  ?? 0);
    $dep_type_p= trim(mysqli_real_escape_string($conn, $_POST['dep_type'] ?? ''));

    if (!$old_date || !$new_date) {
        echo json_encode(['success'=>false,'error'=>'Missing date values']); exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
        echo json_encode(['success'=>false,'error'=>'Invalid date format']); exit;
    }
    if ($new_date === $old_date) {
        echo json_encode(['success'=>false,'error'=>'New date is the same as current date']); exit;
    }

    /* Build WHERE to match exactly this batch */
    $w = ["deposit_date='$old_date'", "status IN('deposited','cleared','returned','sent_back','pending')"];
    if ($acc_id_p)   $w[] = "deposited_account_id=$acc_id_p";
    if ($dep_type_p) $w[] = "deposit_type='$dep_type_p'";
    $where = implode(' AND ', $w);

    /* Count affected cheques first */
    $cnt_r = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM cheques WHERE $where");
    $cnt   = ($cnt_r && $row = mysqli_fetch_assoc($cnt_r)) ? intval($row['cnt']) : 0;
    if (!$cnt) {
        echo json_encode(['success'=>false,'error'=>'No cheques found for this batch']); exit;
    }

    /* Perform update */
    if (mysqli_query($conn, "UPDATE cheques SET deposit_date='$new_date' WHERE $where")) {
        /* Log each cheque update */
        @mysqli_query($conn,"CREATE TABLE IF NOT EXISTS cheque_logs(id INT AUTO_INCREMENT PRIMARY KEY,
            cheque_id INT NOT NULL,action VARCHAR(100),old_value TEXT,new_value TEXT,note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,created_by VARCHAR(100) DEFAULT 'system',
            INDEX idx_cid(cheque_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cu = mysqli_real_escape_string($conn, get_current_user_label());
        $note_e = mysqli_real_escape_string($conn, "Batch deposit date changed from $old_date to $new_date");
        /* Log for each affected cheque */
        $ids_r = mysqli_query($conn, "SELECT id FROM cheques WHERE deposit_date='$new_date' AND $where"
            /* re-query using new date */ );
        /* Simpler: get all IDs matching new date + acc + type */
        $w2 = ["deposit_date='$new_date'"];
        if ($acc_id_p)   $w2[] = "deposited_account_id=$acc_id_p";
        if ($dep_type_p) $w2[] = "deposit_type='$dep_type_p'";
        $ids_r2 = mysqli_query($conn, "SELECT id FROM cheques WHERE ".implode(' AND ',$w2));
        if ($ids_r2) {
            while ($ir = mysqli_fetch_assoc($ids_r2)) {
                $cid_l = intval($ir['id']);
                mysqli_query($conn,"INSERT INTO cheque_logs(cheque_id,action,old_value,new_value,note,created_by)
                    VALUES($cid_l,'deposit_date_change','$old_date','$new_date','$note_e','$cu')");
            }
        }
        echo json_encode(['success'=>true,'updated'=>$cnt,'new_date'=>$new_date]);
    } else {
        echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]);
    }
    exit;
}

/* ── AJAX: update single cheque status ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_cheque_status') {
    header('Content-Type: application/json');
    $cid           = intval($_POST['cheque_id'] ?? 0);
    $status        = trim(mysqli_real_escape_string($conn, $_POST['status'] ?? ''));
    $ref           = trim(mysqli_real_escape_string($conn, $_POST['bank_ref'] ?? ''));
    $return_reason = trim(mysqli_real_escape_string($conn, $_POST['return_reason'] ?? ''));
    $status_change_date = trim(mysqli_real_escape_string($conn, $_POST['status_change_date'] ?? ''));
    $valid  = ['deposited','cleared','returned','sent_back','pending'];
    if (!$cid || !in_array($status, $valid)) { echo json_encode(['success'=>false,'error'=>'Invalid params']); exit; }

    /* Ensure bank_ref column */
    $col_chk = mysqli_query($conn,"SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cheques' AND COLUMN_NAME='bank_ref' LIMIT 1");
    if (!($col_chk && mysqli_num_rows($col_chk)>0)) @mysqli_query($conn,"ALTER TABLE cheques ADD COLUMN bank_ref VARCHAR(100) DEFAULT NULL");

    /* Ensure return_reason column on cheques */
    $rr_chk = mysqli_query($conn,"SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cheques' AND COLUMN_NAME='return_reason' LIMIT 1");
    if (!($rr_chk && mysqli_num_rows($rr_chk)>0)) @mysqli_query($conn,"ALTER TABLE cheques ADD COLUMN return_reason TEXT DEFAULT NULL");

    /* Ensure status_change_date column */
    ensure_status_change_date_col($conn);

    /* Fetch old status + cheque info */
    $old_r   = mysqli_query($conn,"SELECT status, cheque_no, t_code, total_amount, status_change_date FROM cheques WHERE id=$cid LIMIT 1");
    $old_row = ($old_r) ? mysqli_fetch_assoc($old_r) : null;
    $old_st  = $old_row ? $old_row['status'] : '';
    $cheque_no_val = $old_row['cheque_no'] ?? '';
    $t_code_val    = $old_row['t_code']    ?? '';
    $chq_amount    = floatval($old_row['total_amount'] ?? 0);

    $extra  = $ref ? ", bank_ref='$ref'" : '';
    $rr_set = ($status === 'returned' && $return_reason) ? ", return_reason='$return_reason'" : '';

    /* Status change date logic */
    $date_set = '';
    if ($status_change_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $status_change_date)) {
        $date_set = ", status_change_date='$status_change_date'";
    } elseif (in_array($status, ['cleared','returned'])) {
        $date_set = ", status_change_date=CURDATE()";
    } elseif ($status === 'deposited' || $status === 'pending') {
        $date_set = ", status_change_date=NULL";
    }

    if (mysqli_query($conn,"UPDATE cheques SET status='$status'$extra$rr_set$date_set WHERE id=$cid")) {
        /* cheque_logs table */
        @mysqli_query($conn,"CREATE TABLE IF NOT EXISTS cheque_logs(id INT AUTO_INCREMENT PRIMARY KEY,
            cheque_id INT NOT NULL,action VARCHAR(100),old_value TEXT,new_value TEXT,note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,created_by VARCHAR(100) DEFAULT 'system',
            INDEX idx_cid(cheque_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cu   = mysqli_real_escape_string($conn, get_current_user_label());
        $note_parts = ["Manual status change"];
        if ($ref)           $note_parts[] = "Bank Ref: $ref";
        if ($status_change_date) $note_parts[] = "Status Date: $status_change_date";
        if ($status === 'returned' && $return_reason) $note_parts[] = "Return Reason: $return_reason";
        $note  = mysqli_real_escape_string($conn, implode(' | ', $note_parts));
        $old_e = mysqli_real_escape_string($conn, $old_st);
        mysqli_query($conn,"INSERT INTO cheque_logs(cheque_id,action,old_value,new_value,note,created_by)
            VALUES($cid,'status_change','$old_e','$status','$note','$cu')");

        /* ── If newly set to returned: create cheque_return_charges table and insert charge ── */
        if ($status === 'returned' && $old_st !== 'returned') {
            @mysqli_query($conn,"CREATE TABLE IF NOT EXISTS cheque_return_charges (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                cheque_id     INT NOT NULL,
                cheque_no     VARCHAR(100) NOT NULL,
                t_code        VARCHAR(100) DEFAULT NULL,
                cheque_amount DECIMAL(15,2) DEFAULT 0,
                return_charge DECIMAL(15,2) NOT NULL DEFAULT 250.00,
                return_reason TEXT DEFAULT NULL,
                charged_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
                charged_by    VARCHAR(100) DEFAULT 'system',
                INDEX idx_crc_cid(cheque_id),
                INDEX idx_crc_tcode(t_code),
                INDEX idx_crc_chqno(cheque_no)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $dup = mysqli_query($conn,"SELECT id FROM cheque_return_charges WHERE cheque_id=$cid LIMIT 1");
            if (!($dup && mysqli_num_rows($dup)>0)) {
                $cno_e  = mysqli_real_escape_string($conn, $cheque_no_val);
                $tco_e  = mysqli_real_escape_string($conn, $t_code_val);
                $rr_e   = mysqli_real_escape_string($conn, $return_reason);
                $cu_e   = mysqli_real_escape_string($conn, get_current_user_label());
                mysqli_query($conn,"INSERT INTO cheque_return_charges
                    (cheque_id,cheque_no,t_code,cheque_amount,return_charge,return_reason,charged_by)
                    VALUES($cid,'$cno_e','$tco_e',$chq_amount,250.00,'$rr_e','$cu_e')");
            }
        }

        /* Return the saved date for UI sync */
        $saved_r = mysqli_query($conn,"SELECT status_change_date FROM cheques WHERE id=$cid LIMIT 1");
        $saved_row = $saved_r ? mysqli_fetch_assoc($saved_r) : null;
        echo json_encode(['success'=>true, 'status_change_date'=>$saved_row['status_change_date'] ?? null]);
    } else { echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]); }
    exit;
}

/* ── AJAX: NDB preview ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'ndb_preview') {
    header('Content-Type: application/json');
    $parsed = json_decode($_POST['parsed_rows'] ?? '[]', true);
    if (!is_array($parsed)) { echo json_encode(['success'=>false,'error'=>'Bad data']); exit; }
    $results = [];
    foreach ($parsed as $p) {
        $chq_no  = trim(mysqli_real_escape_string($conn, $p['cheque_no'] ?? ''));
        $amount  = floatval($p['amount'] ?? 0);
        $type    = $p['type'] ?? '';
        $bank_ref= trim(mysqli_real_escape_string($conn, $p['bank_ref'] ?? ''));
        $tx_date = trim(mysqli_real_escape_string($conn, $p['tx_date'] ?? ''));
        if (!$chq_no) continue;
        $r = mysqli_query($conn,"SELECT ch.id, ch.cheque_no, ch.total_amount, ch.status, ch.t_code,
                COALESCE(NULLIF(c.shop_name,''), ch.t_code) AS customer_name
             FROM cheques ch LEFT JOIN customers c ON c.t_code=ch.t_code
             WHERE ch.cheque_no='$chq_no' LIMIT 1");
        $db = ($r && $row=mysqli_fetch_assoc($r)) ? $row : null;
        $target_status = ($type === 'returned') ? 'returned' : 'cleared';
        $already_updated = $db && ($db['status'] === $target_status);
        $results[] = [
            'cheque_no'      => $p['cheque_no'],
            'bank_amount'    => $amount,
            'bank_ref'       => $p['bank_ref'],
            'tx_date'        => $p['tx_date'],
            'description'    => $p['description'] ?? '',
            'type'           => $type,
            'matched'        => $db !== null,
            'already_updated'=> $already_updated,
            'db_id'          => $db['id']           ?? null,
            'db_status'      => $db['status']       ?? null,
            'db_amount'      => $db['total_amount']  ?? null,
            'db_t_code'      => $db['t_code']       ?? null,
            'customer_name'  => $db['customer_name'] ?? null,
            'amt_match'      => $db ? (abs(floatval($db['total_amount']) - $amount) < 0.01) : false,
        ];
    }
    echo json_encode(['success'=>true,'rows'=>$results]);
    exit;
}

/* ── AJAX: NDB apply ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'ndb_apply') {
    header('Content-Type: application/json');
    $items = json_decode($_POST['items'] ?? '[]', true);
    $ndb_status_date = trim(mysqli_real_escape_string($conn, $_POST['ndb_status_date'] ?? ''));
    if (!is_array($items)) { echo json_encode(['success'=>false,'error'=>'Bad data']); exit; }
    $col_chk2 = mysqli_query($conn,"SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cheques' AND COLUMN_NAME='bank_ref' LIMIT 1");
    if (!($col_chk2 && mysqli_num_rows($col_chk2)>0)) @mysqli_query($conn,"ALTER TABLE cheques ADD COLUMN bank_ref VARCHAR(100) DEFAULT NULL");
    ensure_status_change_date_col($conn);
    @mysqli_query($conn,"CREATE TABLE IF NOT EXISTS cheque_logs(id INT AUTO_INCREMENT PRIMARY KEY,
        cheque_id INT NOT NULL,action VARCHAR(100),old_value TEXT,new_value TEXT,note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_cid(cheque_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $updated=0; $skipped=0; $already_done=0; $skip_list=[];
    $cu = mysqli_real_escape_string($conn, get_current_user_label());
    foreach ($items as $item) {
        $db_id        = intval($item['db_id'] ?? 0);
        $new_st       = ($item['type'] === 'returned') ? 'returned' : 'cleared';
        $bank_ref     = mysqli_real_escape_string($conn, trim($item['bank_ref'] ?? ''));
        $return_reason= mysqli_real_escape_string($conn, trim($item['return_reason'] ?? ''));
        if (!$db_id) { $skipped++; $skip_list[]=$item['cheque_no']; continue; }
        $old_r  = mysqli_query($conn,"SELECT status FROM cheques WHERE id=$db_id LIMIT 1");
        $old_row= ($old_r) ? mysqli_fetch_assoc($old_r) : null;
        $old_st = $old_row ? $old_row['status'] : '';
        if ($old_st === $new_st) {
            $already_done++;
            $skip_list[] = $item['cheque_no'].' (already '.$new_st.')';
            continue;
        }
        @mysqli_query($conn,"ALTER TABLE cheques ADD COLUMN IF NOT EXISTS return_reason TEXT DEFAULT NULL");
        $rr_set_ndb = ($new_st === 'returned' && $return_reason) ? ", return_reason='$return_reason'" : '';

        $row_date = '';
        if ($ndb_status_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ndb_status_date)) {
            $row_date = ", status_change_date='$ndb_status_date'";
        } elseif (!empty($item['tx_date'])) {
            $parsed_dt = date('Y-m-d', strtotime(str_replace('/', '-', $item['tx_date'])));
            if ($parsed_dt && $parsed_dt !== '1970-01-01') {
                $parsed_dt_e = mysqli_real_escape_string($conn, $parsed_dt);
                $row_date = ", status_change_date='$parsed_dt_e'";
            } else {
                $row_date = ", status_change_date=CURDATE()";
            }
        } else {
            $row_date = ", status_change_date=CURDATE()";
        }

        $chq_info_r = mysqli_query($conn,"SELECT cheque_no, t_code, total_amount FROM cheques WHERE id=$db_id LIMIT 1");
        $chq_info   = $chq_info_r ? mysqli_fetch_assoc($chq_info_r) : [];

        if (mysqli_query($conn,"UPDATE cheques SET status='$new_st', bank_ref='$bank_ref'$rr_set_ndb$row_date WHERE id=$db_id")) {
            $note_parts = ["NDB Import", "Tx Date: {$item['tx_date']}", "Bank Ref: {$item['bank_ref']}"];
            if ($ndb_status_date) $note_parts[] = "Status Date: $ndb_status_date";
            if ($new_st === 'returned' && $return_reason) $note_parts[] = "Return Reason: $return_reason";
            $note = mysqli_real_escape_string($conn, implode(' | ', $note_parts));
            $old_e= mysqli_real_escape_string($conn, $old_st);
            mysqli_query($conn,"INSERT INTO cheque_logs(cheque_id,action,old_value,new_value,note,created_by)
                VALUES($db_id,'status_change','$old_e','$new_st','$note','$cu')");

            if ($new_st === 'returned' && !empty($chq_info)) {
                @mysqli_query($conn,"CREATE TABLE IF NOT EXISTS cheque_return_charges (
                    id            INT AUTO_INCREMENT PRIMARY KEY,
                    cheque_id     INT NOT NULL,
                    cheque_no     VARCHAR(100) NOT NULL,
                    t_code        VARCHAR(100) DEFAULT NULL,
                    cheque_amount DECIMAL(15,2) DEFAULT 0,
                    return_charge DECIMAL(15,2) NOT NULL DEFAULT 250.00,
                    return_reason TEXT DEFAULT NULL,
                    charged_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
                    charged_by    VARCHAR(100) DEFAULT 'system',
                    INDEX idx_crc_cid(cheque_id),
                    INDEX idx_crc_tcode(t_code),
                    INDEX idx_crc_chqno(cheque_no)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $dup2 = mysqli_query($conn,"SELECT id FROM cheque_return_charges WHERE cheque_id=$db_id LIMIT 1");
                if (!($dup2 && mysqli_num_rows($dup2)>0)) {
                    $cno_e2 = mysqli_real_escape_string($conn, $chq_info['cheque_no'] ?? '');
                    $tco_e2 = mysqli_real_escape_string($conn, $chq_info['t_code']    ?? '');
                    $amt2   = floatval($chq_info['total_amount'] ?? 0);
                    $rr_e2  = $return_reason;
                    mysqli_query($conn,"INSERT INTO cheque_return_charges
                        (cheque_id,cheque_no,t_code,cheque_amount,return_charge,return_reason,charged_by)
                        VALUES($db_id,'$cno_e2','$tco_e2',$amt2,250.00,'$rr_e2','$cu')");
                }
            }
            $updated++;
        } else { $skipped++; $skip_list[]=$item['cheque_no']; }
    }
    echo json_encode(['success'=>true,'updated'=>$updated,'skipped'=>$skipped,'already_done'=>$already_done,'skip_list'=>$skip_list]);
    exit;
}

/* ══════════════════════════════════════════════════════════════
   NORMAL PAGE — Load batch cheques
══════════════════════════════════════════════════════════════ */
include 'header.php';

$dep_date = trim($_GET['dep_date'] ?? '');
$acc_id   = intval($_GET['acc_id'] ?? 0);
$dep_type = trim($_GET['dep_type'] ?? '');

$col_chk3 = mysqli_query($conn,"SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cheques' AND COLUMN_NAME='bank_ref' LIMIT 1");
if (!($col_chk3 && mysqli_num_rows($col_chk3)>0)) @mysqli_query($conn,"ALTER TABLE cheques ADD COLUMN bank_ref VARCHAR(100) DEFAULT NULL");

$rr_chk3 = mysqli_query($conn,"SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cheques' AND COLUMN_NAME='return_reason' LIMIT 1");
if (!($rr_chk3 && mysqli_num_rows($rr_chk3)>0)) @mysqli_query($conn,"ALTER TABLE cheques ADD COLUMN return_reason TEXT DEFAULT NULL");

ensure_status_change_date_col($conn);

if (!$dep_date) {
    echo '<div style="padding:40px;text-align:center;color:#dc2626;font-size:14px;"><i class="fa-solid fa-triangle-exclamation"></i> No deposit date provided. <a href="deposit.php">Go back</a></div>';
    include 'footer.php'; exit;
}

$w = ["ch.status IN('deposited','cleared','returned','sent_back')", "ch.deposit_date='".mysqli_real_escape_string($conn,$dep_date)."'"];
if ($acc_id)   $w[] = "ch.deposited_account_id=$acc_id";
if ($dep_type) $w[] = "ch.deposit_type='".mysqli_real_escape_string($conn,$dep_type)."'";

$sql = "SELECT ch.id, ch.cheque_no, ch.cheque_date, ch.total_amount,
               ch.bank_code, ch.bank_name, ch.branch_code, ch.branch_name,
               ch.status, ch.t_code,
               COALESCE(NULLIF(ch.cheque_mode,''),'') AS cheque_mode,
               COALESCE(ch.bank_ref,'') AS bank_ref,
               COALESCE(ch.return_reason,'') AS return_reason,
               ch.deposit_date, ch.deposit_type,
               ch.status_change_date,
               COALESCE(NULLIF(fsd.customer_name,''), NULLIF(c.shop_name,''), ch.t_code) AS customer_name,
               COALESCE(cba.account_name,'Unknown Account') AS account_name,
               COALESCE(cba.account_no,'') AS account_no,
               COALESCE(cba.bank_code,'') AS acc_bank_code
        FROM cheques ch
        LEFT JOIN invoice_payments      ip  ON ip.id  = ch.invoice_payment_id
        LEFT JOIN field_summary         fs  ON fs.id  = ip.field_summary_id
        LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        LEFT JOIN customers             c   ON c.t_code = ch.t_code
        LEFT JOIN company_bank_accounts cba ON cba.id  = ch.deposited_account_id
        WHERE ".implode(' AND ',$w)."
        ORDER BY ch.cheque_no ASC";

$res  = mysqli_query($conn, $sql);
$cheques = [];
$total_amount = 0;
$cleared_cnt = $returned_cnt = $deposited_cnt = 0;
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $cheques[] = $row;
        $total_amount += floatval($row['total_amount']);
        if ($row['status'] === 'cleared')   $cleared_cnt++;
        elseif ($row['status'] === 'returned') $returned_cnt++;
        else $deposited_cnt++;
    }
}

$batch_title  = $dep_date ? date('d M Y', strtotime($dep_date)) : '—';
$acc_name     = $cheques[0]['account_name'] ?? 'Unknown Account';
$acc_no       = $cheques[0]['account_no']   ?? '';
$acc_bank     = $cheques[0]['acc_bank_code'] ?? '';
$dep_type_lbl = ($dep_type === 'bulk') ? 'Bulk Deposit' : 'Normal Deposit';
$cheque_count = count($cheques);
?>
<style>
:root{--pri:#1e1b4b;--pri2:#312e81;--teal:#0d9488;--teal2:#14b8a6;--orange:#ea580c;--orange2:#f97316}
.dv-wrap{max-width:1200px;margin:0 auto;padding:18px 14px 60px}
/* Header bar */
.dv-hdr{background:linear-gradient(135deg,var(--pri),var(--pri2));border-radius:14px;padding:18px 22px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.dv-hdr-icon{width:48px;height:48px;border-radius:12px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;flex-shrink:0}
.dv-hdr-title{color:#fff;font-size:18px;font-weight:800;line-height:1.2}
.dv-hdr-sub{color:rgba(255,255,255,.7);font-size:12px;margin-top:3px}
.dv-hdr-right{margin-left:auto;display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.btn-back{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:8px;padding:7px 15px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .2s}
.btn-back:hover{background:rgba(255,255,255,.25);color:#fff}
.btn-ndb{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,var(--teal),var(--teal2));color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer;transition:filter .2s}
.btn-ndb:hover{filter:brightness(1.1)}
/* Change deposit date button */
.btn-chdate{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,var(--orange),var(--orange2));color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer;transition:filter .2s}
.btn-chdate:hover{filter:brightness(1.1)}
/* KPI cards */
.kpi-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
@media(max-width:700px){.kpi-row{grid-template-columns:repeat(2,1fr)}}
.kpi-card{background:#fff;border-radius:12px;padding:14px 16px;box-shadow:0 1px 6px rgba(0,0,0,.07);border:1.5px solid #f0f0f0}
.kpi-lbl{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
.kpi-val{font-size:20px;font-weight:800;color:#1f2937}
.kpi-val.teal{color:var(--teal)}
.kpi-val.green{color:#16a34a}
.kpi-val.red{color:#dc2626}
.kpi-val.blue{color:#2563eb}
/* Deposit date banner */
.dep-date-banner{background:#fff;border-radius:12px;border:1.5px solid #e5e5e5;padding:12px 18px;margin-bottom:18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;box-shadow:0 1px 6px rgba(0,0,0,.06)}
.dep-date-banner .ddb-label{font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}
.dep-date-banner .ddb-val{font-size:15px;font-weight:800;color:#1e1b4b;display:flex;align-items:center;gap:7px}
.dep-date-banner .ddb-val .ddb-date{background:#ede9fe;color:#3730a3;border-radius:7px;padding:3px 10px;font-size:14px;font-weight:800;font-family:'Courier New',monospace}
.dep-date-banner .ddb-edit-btn{display:inline-flex;align-items:center;gap:5px;background:linear-gradient(135deg,var(--orange),var(--orange2));color:#fff;border:none;border-radius:7px;padding:6px 13px;font-size:11px;font-weight:700;cursor:pointer;margin-left:auto;transition:filter .2s;font-family:inherit}
.dep-date-banner .ddb-edit-btn:hover{filter:brightness(1.1)}
/* Inline date edit form */
.dep-date-edit-form{background:#fff8f0;border:2px solid #fed7aa;border-radius:12px;padding:14px 18px;margin-bottom:18px;display:none;align-items:center;gap:12px;flex-wrap:wrap;animation:fadeIn .2s ease}
.dep-date-edit-form.open{display:flex}
@keyframes fadeIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.dep-date-edit-form label{font-size:12px;font-weight:700;color:#92400e;white-space:nowrap}
.dep-date-edit-form input[type=date]{border:2px solid #fbbf24;border-radius:7px;padding:6px 11px;font-size:13px;font-weight:700;color:#92400e;background:#fff;outline:none;font-family:inherit;transition:border .2s}
.dep-date-edit-form input[type=date]:focus{border-color:var(--orange)}
.dep-date-edit-reason{border:1.5px solid #fbbf24;border-radius:7px;padding:6px 11px;font-size:12px;color:#92400e;background:#fff;outline:none;font-family:inherit;width:220px;transition:border .2s}
.dep-date-edit-reason:focus{border-color:var(--orange)}
.dep-date-edit-reason::placeholder{color:#f59e0b;font-style:italic}
.dep-date-save-btn{display:inline-flex;align-items:center;gap:5px;background:linear-gradient(135deg,var(--orange),var(--orange2));color:#fff;border:none;border-radius:7px;padding:7px 16px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:filter .2s}
.dep-date-save-btn:hover{filter:brightness(1.1)}.dep-date-save-btn:disabled{opacity:.5;cursor:not-allowed}
.dep-date-cancel-btn{display:inline-flex;align-items:center;gap:5px;background:#f3f4f6;border:1.5px solid #e5e5e5;color:#374151;border-radius:7px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit}
.dep-date-cancel-btn:hover{background:#e5e7eb}
/* Confirm overlay */
#ddConfirmOverlay{display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.55);align-items:center;justify-content:center}
#ddConfirmOverlay.open{display:flex}
.dd-confirm-box{background:#fff;border-radius:16px;width:440px;max-width:96vw;box-shadow:0 24px 80px rgba(0,0,0,.35);overflow:hidden;animation:ndbIn .2s cubic-bezier(.16,1,.3,1)}
.dd-confirm-hdr{background:linear-gradient(135deg,#ea580c,#f97316);padding:14px 20px;display:flex;align-items:center;justify-content:space-between}
.dd-confirm-hdr-title{color:#fff;font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px}
.dd-confirm-x{background:rgba(255,255,255,.18);border:none;border-radius:7px;color:#fff;width:30px;height:30px;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center}
.dd-confirm-x:hover{background:rgba(255,255,255,.3)}
.dd-confirm-body{padding:20px 22px;font-size:13px;color:#374151;line-height:1.7}
.dd-confirm-body .dd-arrow{display:flex;align-items:center;gap:10px;margin:12px 0;font-size:15px;font-weight:800}
.dd-confirm-body .dd-arrow .dd-old{background:#fee2e2;color:#991b1b;border-radius:7px;padding:4px 12px;font-family:'Courier New',monospace}
.dd-confirm-body .dd-arrow .dd-new{background:#dcfce7;color:#166534;border-radius:7px;padding:4px 12px;font-family:'Courier New',monospace}
.dd-confirm-body .dd-count{background:#fef3c7;border:1px solid #fde68a;border-radius:7px;padding:8px 12px;margin-top:10px;font-size:12px;color:#92400e;display:flex;align-items:center;gap:6px}
.dd-confirm-foot{padding:12px 22px 18px;display:flex;gap:8px;justify-content:flex-end}
.dd-confirm-apply{display:inline-flex;align-items:center;gap:5px;background:linear-gradient(135deg,#ea580c,#f97316);color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:filter .2s}
.dd-confirm-apply:hover{filter:brightness(1.1)}.dd-confirm-apply:disabled{opacity:.5;cursor:not-allowed}
.dd-confirm-cancel{background:#f3f4f6;border:1.5px solid #e5e5e5;border-radius:8px;padding:9px 16px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit}
.dd-confirm-cancel:hover{background:#e5e7eb}
/* Table card */
.tbl-card{background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.07);border:1.5px solid #f0f0f0;overflow:hidden;margin-bottom:22px}
.tbl-card-hdr{padding:12px 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:8px}
.tbl-card-title{font-size:13px;font-weight:800;color:#1e1b4b;display:flex;align-items:center;gap:7px}
.tbl-search input{border:1.5px solid #e5e5e5;border-radius:7px;padding:5px 10px;font-size:12px;width:210px;outline:none;transition:border .2s}
.tbl-search input:focus{border-color:#6366f1}
.dt-outer{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;min-width:1060px}
.data-table thead th{padding:9px 11px;text-align:left;font-weight:700;font-size:11px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);position:sticky;top:0;z-index:5}
.data-table thead th.tr{text-align:right}.data-table thead th.tc{text-align:center}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;transition:background .1s}
.data-table tbody tr:hover td{background:#f8faff}
.data-table td{padding:10px 11px;color:#374151;vertical-align:middle;background:#fff}
.data-table tfoot td{padding:10px 11px;font-weight:800;font-size:12px;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155}
.data-table tfoot td.tr{text-align:right}
.tr{text-align:right}.tc{text-align:center}
.chqno-b{background:#ede9fe;color:#3730a3;border-radius:6px;padding:3px 9px;font-family:'Courier New',monospace;font-size:12px;font-weight:800;white-space:nowrap;letter-spacing:.03em}
.pill{display:inline-flex;align-items:center;gap:3px;padding:3px 9px;border-radius:20px;font-size:10.5px;font-weight:700;white-space:nowrap}
.p-blue{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe}
.p-green{background:#dcfce7;color:#166534;border:1px solid #86efac}
.p-red{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.p-violet{background:#fdf4ff;color:#7e22ce;border:1px solid #d8b4fe}
.p-gray{background:#f3f4f6;color:#374151;border:1px solid #e5e5e5}
.pstat-sel{border:1.5px solid #e5e5e5;border-radius:6px;padding:4px 8px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;outline:none;transition:all .2s;min-width:110px}
.pstat-sel.deposited{background:#dbeafe;color:#1e40af;border-color:#bfdbfe}
.pstat-sel.cleared{background:#dcfce7;color:#166534;border-color:#86efac}
.pstat-sel.returned{background:#fee2e2;color:#991b1b;border-color:#fecaca}
.pstat-sel.sent_back{background:#fdf4ff;color:#7e22ce;border-color:#d8b4fe}
.pstat-sel.pending{background:#fef3c7;color:#92400e;border-color:#fde68a}
.pref-inp{border:1px solid #e5e5e5;border-radius:5px;padding:3px 7px;font-size:10px;font-family:'Courier New',monospace;width:110px;color:#374151;outline:none;transition:border .2s}
.pref-inp:focus{border-color:#6366f1}
.psave-btn{display:none;align-items:center;gap:3px;background:#6366f1;color:#fff;border:none;border-radius:5px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .2s;white-space:nowrap}
.psave-btn:hover{background:#4f46e5}.psave-btn.vis{display:inline-flex}.psave-btn:disabled{opacity:.5;cursor:not-allowed}
.mode-b{display:inline-flex;align-items:center;padding:1px 5px;border-radius:4px;font-size:9.5px;font-weight:700;margin-left:3px}
.mode-payee{background:#ede9fe;color:#5b21b6}.mode-bearer{background:#dcfce7;color:#166534}
.scd-inp{border:1.5px solid #e5e5e5;border-radius:5px;padding:3px 6px;font-size:11px;font-family:inherit;width:125px;color:#374151;outline:none;transition:border .2s;background:#fff}
.scd-inp:focus{border-color:#6366f1}
.scd-inp.has-date{border-color:#86efac;background:#f0fdf4;color:#166534;font-weight:700}
/* NDB MODAL */
#ndbModal{display:none;position:fixed;inset:0;z-index:999990;background:rgba(0,0,0,.62);overflow-y:auto;padding:28px 14px 40px}
#ndbModal.open{display:block}
.ndb-box{background:#fff;border-radius:16px;width:100%;max-width:1200px;margin:0 auto;box-shadow:0 32px 100px rgba(0,0,0,.45);overflow:hidden;animation:ndbIn .22s cubic-bezier(.16,1,.3,1)}
@keyframes ndbIn{from{transform:translateY(28px) scale(.98);opacity:0}to{transform:translateY(0) scale(1);opacity:1}}
.ndb-hdr{background:linear-gradient(135deg,#0f766e,#0d9488);padding:16px 22px;display:flex;align-items:center;justify-content:space-between}
.ndb-htitle{color:#fff;font-size:15px;font-weight:800;display:flex;align-items:center;gap:9px}
.ndb-x{background:rgba(255,255,255,.15);border:none;border-radius:8px;color:#fff;width:32px;height:32px;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;transition:background .2s}
.ndb-x:hover{background:rgba(255,255,255,.3)}
.ndb-body{padding:20px 22px}
.ndb-tabs{display:flex;gap:0;border-bottom:2px solid #e5e5e5;margin-bottom:18px}
.ndb-tab{padding:8px 20px;font-size:12px;font-weight:700;color:#9ca3af;border-bottom:2px solid transparent;margin-bottom:-2px;display:flex;align-items:center;gap:6px}
.ndb-tab.active{color:#0d9488;border-bottom-color:#0d9488}.ndb-tab.done{color:#16a34a}
.step-n{width:20px;height:20px;border-radius:50%;font-size:10px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;background:#e5e5e5;color:#6b7280}
.ndb-tab.active .step-n{background:#0d9488;color:#fff}.ndb-tab.done .step-n{background:#16a34a;color:#fff}
.upload-area{border:2.5px dashed #99f6e4;border-radius:12px;padding:36px 24px;text-align:center;background:#f0fdfa;cursor:pointer;transition:all .2s}
.upload-area:hover,.upload-area.drag-over{border-color:#0d9488;background:#ccfbf1}
.ua-icon{font-size:36px;color:#0d9488;margin-bottom:10px}.ua-title{font-size:15px;font-weight:700;color:#0f766e;margin-bottom:5px}.ua-sub{font-size:12px;color:#6b7280}
.stmt-bar{background:linear-gradient(135deg,#f0fdfa,#ccfbf1);border:1px solid #5eead4;border-radius:10px;padding:11px 14px;margin-bottom:14px}
.stmt-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:7px}
@media(max-width:600px){.stmt-grid{grid-template-columns:repeat(2,1fr)}}
.stmt-item .si-l{font-size:10px;color:#0f766e;font-weight:600}.stmt-item .si-v{font-size:13px;font-weight:800;color:#1f2937}
.prev-stats{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.prev-stat{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:8px;font-size:12px;font-weight:700;background:#f3f4f6;border:1.5px solid #e5e5e5;color:#374151}
.prev-stat.err{background:#fee2e2;border-color:#fecaca;color:#991b1b}
.prev-stat.warn{background:#fef3c7;border-color:#fde68a;color:#92400e}
.prev-stat.skip{background:#f3f4f6;border-color:#d1d5db;color:#6b7280}
.prev-ctrl{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.sc-btn{background:#f3f4f6;border:1.5px solid #e5e5e5;border-radius:7px;padding:5px 12px;font-size:11px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-family:inherit;transition:background .15s}
.sc-btn:hover{background:#e5e7eb}
.prev-table{width:100%;border-collapse:collapse;font-size:12px}
.prev-table th{padding:7px 9px;background:#1e1b4b;color:#e0e7ff;font-size:10.5px;font-weight:700;text-align:left;white-space:nowrap;position:sticky;top:0}
.prev-table td{padding:7px 9px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
.prev-table tr.row-cleared td{background:#f0fdf4}
.prev-table tr.row-returned td{background:#fff5f5}
.prev-table tr.row-notfound td{opacity:.55}
.prev-table tr.row-already td{background:#f8f8f8;opacity:.65}
.mb{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700}
.mb-ok{background:#dcfce7;color:#166534}.mb-no{background:#fee2e2;color:#991b1b}
.mb-clr{background:#dcfce7;color:#16a34a}.mb-ret{background:#fee2e2;color:#dc2626}
.mb-amt{background:#dcfce7;color:#166534}.mb-amtd{background:#fef3c7;color:#92400e}
.mb-skip{background:#e5e7eb;color:#6b7280}
.reason-inp{border:1px solid #fecaca;border-radius:5px;padding:3px 7px;font-size:11px;width:140px;color:#991b1b;outline:none;background:#fff5f5;transition:border .2s;font-family:inherit}
.reason-inp:focus{border-color:#dc2626;background:#fff}
.reason-inp::placeholder{color:#fca5a5;font-style:italic}
.ndb-foot{padding:14px 22px;border-top:2px solid #f0f0f0;display:flex;align-items:center;gap:10px;justify-content:flex-end;flex-wrap:wrap}
.ndb-foot-info{flex:1;font-size:12px;color:#6b7280;font-weight:600;min-width:160px}
.ndb-cancel-btn{background:#f3f4f6;border:1.5px solid #e5e5e5;border-radius:8px;padding:8px 18px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit}
.ndb-cancel-btn:hover{background:#e5e7eb}
.ndb-apply-btn{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:filter .2s}
.ndb-apply-btn:hover{filter:brightness(1.1)}.ndb-apply-btn:disabled{opacity:.5;cursor:not-allowed}
.mono{font-family:'Courier New',monospace;font-weight:700}
.ndb-date-bar{background:#f0fdf4;border:1.5px solid #86efac;border-radius:8px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.ndb-date-bar label{font-size:12px;font-weight:700;color:#166534;display:flex;align-items:center;gap:6px}
.ndb-date-bar input[type=date]{border:1.5px solid #86efac;border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;color:#166534;background:#fff;outline:none;font-family:inherit}
.ndb-date-bar input[type=date]:focus{border-color:#0d9488}
.ndb-date-bar .date-hint{font-size:11px;color:#6b7280;font-style:italic}
/* Toast */
#toast{position:fixed;bottom:28px;right:24px;background:#166534;color:#fff;padding:11px 22px;border-radius:10px;font-size:13px;font-weight:700;z-index:9999999;opacity:0;pointer-events:none;transition:opacity .3s;max-width:320px}
#toast.show{opacity:1}
.rr-inp{border:1px solid #fecaca;border-radius:5px;padding:3px 7px;font-size:11px;width:160px;color:#991b1b;outline:none;background:#fff5f5;transition:border .2s;font-family:inherit;display:none}
.rr-inp.show{display:inline-block}
.rr-inp:focus{border-color:#dc2626;background:#fff}
.rr-inp::placeholder{color:#fca5a5;font-style:italic}
.ret-charge-badge{display:inline-flex;align-items:center;gap:3px;background:#fee2e2;border:1px solid #fecaca;border-radius:5px;padding:2px 7px;font-size:10px;font-weight:700;color:#991b1b;white-space:nowrap}
.hidden-row{display:none}
</style>

<div class="dv-wrap">

  <!-- ── Page Header ── -->
  <div class="dv-hdr">
    <div class="dv-hdr-icon"><i class="fa-solid fa-money-check-dollar"></i></div>
    <div>
      <div class="dv-hdr-title">Deposit Batch — <?=htmlspecialchars($batch_title)?></div>
      <div class="dv-hdr-sub">
        <i class="fa-solid fa-building-columns"></i>
        <?=htmlspecialchars($acc_name)?><?=$acc_no?" · $acc_no":""?>
        &nbsp;·&nbsp; <?=htmlspecialchars($dep_type_lbl)?>
      </div>
    </div>
    <div class="dv-hdr-right">
      <button class="btn-chdate" onclick="openDepDateEdit()">
        <i class="fa-solid fa-calendar-pen"></i> Change Deposit Date
      </button>
      <a class="btn-back" href="deposit.php"><i class="fa-solid fa-arrow-left"></i> Back to Deposits</a>
    </div>
  </div>

  <!-- ── Deposit Date Banner ── -->
  <div class="dep-date-banner" id="depDateBanner">
    <div class="ddb-label"><i class="fa-solid fa-calendar-days"></i> Deposit Date</div>
    <div class="ddb-val">
      <span class="ddb-date" id="depDateDisplay"><?=htmlspecialchars($batch_title)?></span>
      <span style="font-size:11px;color:#9ca3af;font-family:'Courier New',monospace;"><?=htmlspecialchars($dep_date)?></span>
    </div>
    <div style="font-size:11px;color:#9ca3af;margin-left:4px;">
      <i class="fa-solid fa-receipt"></i> <?=$cheque_count?> cheque<?=$cheque_count!=1?'s':''?> in batch
    </div>
    <button class="ddb-edit-btn" onclick="openDepDateEdit()">
      <i class="fa-solid fa-pen-to-square"></i> Edit Date
    </button>
  </div>

  <!-- ── Inline Deposit Date Edit Form ── -->
  <div class="dep-date-edit-form" id="depDateEditForm">
    <i class="fa-solid fa-calendar-pen" style="color:var(--orange);font-size:18px;flex-shrink:0;"></i>
    <div>
      <div style="font-size:12px;font-weight:800;color:#92400e;margin-bottom:3px;">Change Deposit Date for Entire Batch</div>
      <div style="font-size:11px;color:#b45309;">This will update the deposit date for all <?=$cheque_count?> cheque(s) in this batch.</div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-left:4px;">
      <label style="font-size:11px;font-weight:700;color:#92400e;">New Date:</label>
      <input type="date" id="newDepDate" value="<?=htmlspecialchars($dep_date)?>"
             min="2000-01-01" max="2099-12-31">
    </div>
    <button class="dep-date-save-btn" id="depDateSaveBtn" onclick="confirmDepDateChange()">
      <i class="fa-solid fa-check"></i> Update Date
    </button>
    <button class="dep-date-cancel-btn" onclick="closeDepDateEdit()">
      <i class="fa-solid fa-xmark"></i> Cancel
    </button>
  </div>

  <!-- ── KPI Cards ── -->
  <div class="kpi-row">
    <div class="kpi-card">
      <div class="kpi-lbl"><i class="fa-solid fa-receipt"></i> Total Cheques</div>
      <div class="kpi-val teal"><?=$cheque_count?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-lbl"><i class="fa-solid fa-circle-check"></i> Cleared</div>
      <div class="kpi-val green"><?=$cleared_cnt?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-lbl"><i class="fa-solid fa-circle-xmark"></i> Returned</div>
      <div class="kpi-val red"><?=$returned_cnt?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-lbl"><i class="fa-solid fa-sack-dollar"></i> Total Amount</div>
      <div class="kpi-val teal" style="font-size:16px;">Rs.&nbsp;<?=number_format($total_amount,2)?></div>
    </div>
  </div>

  <!-- ── Cheques Table ── -->
  <div class="tbl-card">
    <div class="tbl-card-hdr">
      <div class="tbl-card-title"><i class="fa-solid fa-list-check"></i> Cheques in this Deposit</div>
      <div class="tbl-search">
        <input type="text" id="srchBox" placeholder="Search cheque no, customer…" oninput="filterTable(this.value)">
      </div>
    </div>

    <?php if (empty($cheques)): ?>
    <div style="text-align:center;padding:60px 20px;color:#9ca3af;">
      <i class="fa-solid fa-inbox" style="font-size:36px;display:block;margin-bottom:12px;opacity:.4;"></i>
      <p style="font-size:13px;">No cheques found for this deposit batch.</p>
    </div>
    <?php else: ?>
    <div class="dt-outer">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Cheque No.</th>
            <th>Cheque Date</th>
            <th>Customer</th>
            <th>Bank / Branch</th>
            <th class="tr">Amount</th>
            <th class="tc">Status</th>
            <th>Status Date</th>
            <th>Bank Ref</th>
            <th>Return Reason</th>
            <th class="tc">Save</th>
          </tr>
        </thead>
        <tbody id="tableBody">
        <?php
        $opts_map = ['deposited'=>'Deposited','cleared'=>'Cleared','returned'=>'Returned','sent_back'=>'Sent Back','pending'=>'Pending'];
        foreach ($cheques as $i => $ch):
            $st  = $ch['status'] ?? 'deposited';
            $cm  = strtolower($ch['cheque_mode'] ?? '');
            if (in_array($cm,['payee_only','payee only','payee','account payee'])) {
                $modeBadge = '<span class="mode-b mode-payee">Payee</span>';
            } elseif (in_array($cm,['cash','bearer','open'])) {
                $modeBadge = '<span class="mode-b mode-bearer">Bearer</span>';
            } else { $modeBadge = ''; }
            $selOpts = '';
            foreach ($opts_map as $k => $v) $selOpts .= '<option value="'.$k.'"'.($st===$k?' selected':'').'>'.htmlspecialchars($v).'</option>';
            $scd_val = $ch['status_change_date'] ?? '';
        ?>
        <tr class="chq-row" data-search="<?=strtolower(htmlspecialchars($ch['cheque_no'].' '.$ch['customer_name'].' '.$ch['t_code']))?>">
          <td style="color:#9ca3af;font-size:11px;"><?=$i+1?></td>
          <td><span class="chqno-b"><?=htmlspecialchars($ch['cheque_no'])?></span><?=$modeBadge?></td>
          <td style="font-size:11.5px;white-space:nowrap;">
            <?=htmlspecialchars($ch['cheque_date'] ? date('d M Y', strtotime($ch['cheque_date'])) : '—')?>
          </td>
          <td>
            <div style="font-size:12.5px;font-weight:700;color:#1f2937;"><?=htmlspecialchars($ch['customer_name'] ?: '—')?></div>
            <div style="font-size:10px;color:#9ca3af;font-family:'Courier New',monospace;"><?=htmlspecialchars($ch['t_code'] ?: '')?></div>
            <?php if($ch['status']==='returned'): ?>
            <span class="ret-charge-badge"><i class="fa-solid fa-circle-minus"></i> Return Charge: Rs. 250.00</span>
            <?php endif; ?>
          </td>
          <td>
            <div style="font-size:12px;font-weight:600;color:#374151;"><?=htmlspecialchars($ch['bank_name'] ?: $ch['bank_code'] ?: '—')?></div>
            <?php if ($ch['branch_name'] || $ch['branch_code']): ?>
            <div style="font-size:10.5px;color:#9ca3af;"><?=htmlspecialchars($ch['branch_name'] ?: $ch['branch_code'])?></div>
            <?php endif; ?>
          </td>
          <td class="tr" style="font-size:13.5px;font-weight:800;color:#0d9488;white-space:nowrap;">
            Rs.&nbsp;<?=number_format(floatval($ch['total_amount']),2)?>
          </td>
          <td class="tc">
            <select class="pstat-sel <?=htmlspecialchars($st)?>" id="pstat-<?=$ch['id']?>" onchange="pDirty(<?=$ch['id']?>,this)">
              <?=$selOpts?>
            </select>
          </td>
          <td>
            <input type="date" class="scd-inp<?=$scd_val?' has-date':''?>"
                   id="pscd-<?=$ch['id']?>"
                   value="<?=htmlspecialchars($scd_val)?>"
                   oninput="pDirty(<?=$ch['id']?>,null)"
                   title="Date when cheque was cleared or returned">
          </td>
          <td>
            <input type="text" class="pref-inp" id="pref-<?=$ch['id']?>"
                   value="<?=htmlspecialchars($ch['bank_ref'] ?: '')?>"
                   placeholder="Bank ref…"
                   oninput="pDirty(<?=$ch['id']?>,null)">
          </td>
          <td>
            <?php $existingRR = $ch['return_reason'] ?? ''; ?>
            <input type="text" class="rr-inp<?=$st==='returned'?' show':''?>" id="prr-<?=$ch['id']?>"
                   value="<?=htmlspecialchars($existingRR)?>"
                   placeholder="Return reason…"
                   oninput="pDirty(<?=$ch['id']?>,null)"
                   title="Reason for return (saved with charge)">
            <?php if($st!=='returned'): ?>
            <span style="color:#d1d5db;font-size:10px;" id="prr-placeholder-<?=$ch['id']?>">—</span>
            <?php endif; ?>
          </td>
          <td class="tc">
            <button class="psave-btn" id="psave-<?=$ch['id']?>" onclick="pSave(<?=$ch['id']?>)">
              <i class="fa-solid fa-floppy-disk"></i> Save
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="5" style="font-size:11px;opacity:.6;">TOTAL</td>
            <td class="tr">Rs.&nbsp;<?=number_format($total_amount,2)?></td>
            <td colspan="5"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div><!-- /tbl-card -->

</div><!-- /dv-wrap -->

<!-- ══ DEPOSIT DATE CONFIRM OVERLAY ══ -->
<div id="ddConfirmOverlay">
  <div class="dd-confirm-box">
    <div class="dd-confirm-hdr">
      <div class="dd-confirm-hdr-title">
        <i class="fa-solid fa-triangle-exclamation"></i> Confirm Date Change
      </div>
      <button class="dd-confirm-x" onclick="closeDepDateConfirm()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="dd-confirm-body">
      <div style="font-size:13px;color:#374151;margin-bottom:6px;">
        You are about to change the deposit date for this entire batch:
      </div>
      <div class="dd-arrow">
        <span class="dd-old" id="ddOldDate">—</span>
        <i class="fa-solid fa-arrow-right" style="color:#9ca3af;font-size:16px;"></i>
        <span class="dd-new" id="ddNewDate">—</span>
      </div>
      <div class="dd-count" id="ddCountMsg">
        <i class="fa-solid fa-receipt"></i>
        <span id="ddCountText">This will update <strong><?=$cheque_count?></strong> cheque(s). All changes will be logged.</span>
      </div>
      <div style="margin-top:12px;font-size:12px;color:#6b7280;line-height:1.6;">
        <i class="fa-solid fa-circle-info" style="color:#3b82f6;"></i>
        This action updates the <strong>deposit_date</strong> field on all cheques in this batch and logs each change to <code>cheque_logs</code>. The page will reload after success.
      </div>
    </div>
    <div class="dd-confirm-foot">
      <button class="dd-confirm-cancel" onclick="closeDepDateConfirm()">Cancel</button>
      <button class="dd-confirm-apply" id="ddConfirmApplyBtn" onclick="applyDepDateChange()">
        <i class="fa-solid fa-calendar-check"></i> Confirm &amp; Update
      </button>
    </div>
  </div>
</div>

<!-- ══ NDB IMPORT MODAL ══ -->
<div id="ndbModal">
  <div class="ndb-box">
    <div class="ndb-hdr">
      <div class="ndb-htitle"><i class="fa-solid fa-file-import"></i> NDB Bank Statement Import
        <span style="background:rgba(255,255,255,.15);border-radius:6px;padding:2px 10px;font-size:11px;">Auto-clear &amp; Return Cheques</span>
      </div>
      <button class="ndb-x" onclick="closeNdbModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="ndb-body">
      <div class="ndb-tabs">
        <div class="ndb-tab active" id="ndbTab1"><span class="step-n">1</span> Upload File</div>
        <div class="ndb-tab" id="ndbTab2"><span class="step-n">2</span> Review &amp; Match</div>
        <div class="ndb-tab" id="ndbTab3"><span class="step-n">3</span> Apply Updates</div>
      </div>
      <!-- Step 1 -->
      <div id="ndbStep1">
        <div class="upload-area" id="uploadArea"
             onclick="document.getElementById('ndbFileInput').click()"
             ondragover="event.preventDefault();this.classList.add('drag-over')"
             ondragleave="this.classList.remove('drag-over')"
             ondrop="handleFileDrop(event)">
          <div class="ua-icon"><i class="fa-solid fa-file-excel"></i></div>
          <div class="ua-title">Upload NDB Bank Statement</div>
          <div class="ua-sub">CSV file · Drag &amp; drop or click to browse<br><small style="opacity:.7;">(For Excel: File → Save As → CSV first)</small></div>
          <div style="margin-top:14px;"><span style="background:#0d9488;color:#fff;padding:7px 18px;border-radius:7px;font-size:12px;font-weight:700;"><i class="fa-solid fa-folder-open"></i> Browse CSV File</span></div>
        </div>
        <input type="file" id="ndbFileInput" accept=".csv" style="display:none" onchange="handleFileSelect(this)">
        <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-top:14px;font-size:12px;color:#92400e;line-height:1.7;">
          <strong><i class="fa-solid fa-triangle-exclamation"></i> What gets imported:</strong><br>
          &nbsp;• <code style="background:#fff3c4;padding:1px 5px;border-radius:3px;font-size:11px;">Outward Cheque Deposit/CHQ NO - XXXX</code> → cheque marked <strong style="color:#16a34a;">Cleared</strong><br>
          &nbsp;• <code style="background:#fff3c4;padding:1px 5px;border-radius:3px;font-size:11px;">Outward Clg Chq Return/CHQ NO - XXXX</code> → cheque marked <strong style="color:#dc2626;">Returned</strong><br>
          &nbsp;• Cheques already set to Cleared or Returned will <strong>not</strong> be updated again.
        </div>
      </div>
      <!-- Step 2 -->
      <div id="ndbStep2" style="display:none;">
        <div class="stmt-bar" id="stmtBar"></div>
        <div class="ndb-date-bar" id="ndbDateBar">
          <label>
            <i class="fa-solid fa-calendar-check"></i> Status Change Date:
            <input type="date" id="ndbStatusDate" value="">
          </label>
          <span class="date-hint">Date to record for all cleared/returned cheques (defaults to CSV Tx Date if empty)</span>
        </div>
        <div class="prev-stats" id="prevStats"></div>
        <div class="prev-ctrl">
          <button class="sc-btn" onclick="selAll(true)">Select All</button>
          <button class="sc-btn" onclick="selAll(false)">Deselect All</button>
          <button class="sc-btn" onclick="selByType('cleared')"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Cleared Only</button>
          <button class="sc-btn" onclick="selByType('returned')"><i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i> Returned Only</button>
          <button class="sc-btn" onclick="selMatched(true)"><i class="fa-solid fa-check"></i> Matched Only</button>
        </div>
        <div style="max-height:440px;overflow-y:auto;border:1px solid #e5e5e5;border-radius:8px;">
          <table class="prev-table">
            <thead>
              <tr>
                <th style="width:28px;"><input type="checkbox" id="prevSelAll" onchange="selAll(this.checked)"></th>
                <th>Cheque No.</th><th>Action</th><th>Customer / T-Code</th>
                <th class="tr">Bank Amt</th><th class="tr">DB Amt</th>
                <th>Amt Match</th><th>DB Status</th><th>Bank Ref</th><th>Return Reason</th><th>Tx Date</th>
              </tr>
            </thead>
            <tbody id="prevBody"></tbody>
          </table>
        </div>
      </div>
      <!-- Step 3 -->
      <div id="ndbStep3" style="display:none;"><div id="applyResult"></div></div>
    </div>
    <div class="ndb-foot">
      <div class="ndb-foot-info" id="ndbInfo">Upload a NDB bank statement CSV to begin.</div>
      <button class="ndb-cancel-btn" onclick="closeNdbModal()">Cancel</button>
      <button class="ndb-apply-btn" id="ndbApplyBtn" onclick="applyNdb()" style="display:none;"><i class="fa-solid fa-circle-check"></i> Apply Selected Updates</button>
      <button class="ndb-apply-btn" id="ndbDoneBtn" onclick="closeNdbModal();location.reload()" style="display:none;background:linear-gradient(135deg,#16a34a,#22c55e);"><i class="fa-solid fa-rotate-right"></i> Done — Reload</button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
/* ════ STATE ════ */
var _currentDepDate = <?=json_encode($dep_date)?>;
var _accId          = <?=json_encode($acc_id)?>;
var _depType        = <?=json_encode($dep_type)?>;
var _chequeCount    = <?=intval($cheque_count)?>;

/* ════ TABLE SEARCH ════ */
function filterTable(q){
    q=q.toLowerCase().trim();
    document.querySelectorAll('#tableBody .chq-row').forEach(tr=>{
        tr.classList.toggle('hidden-row', q && !tr.dataset.search.includes(q));
    });
}

/* ════ STATUS SAVE ════ */
let _dirty=new Set();
function pDirty(id,sel){
    _dirty.add(id);
    const btn=document.getElementById('psave-'+id);
    if(btn)btn.classList.add('vis');
    if(sel){
        const select=document.getElementById('pstat-'+id);
        if(select)select.className='pstat-sel '+sel.value;
        const rrInp=document.getElementById('prr-'+id);
        const rrPlaceholder=document.getElementById('prr-placeholder-'+id);
        if(rrInp){
            if(sel.value==='returned'){
                rrInp.classList.add('show');
                if(rrPlaceholder) rrPlaceholder.style.display='none';
                rrInp.focus();
            } else {
                rrInp.classList.remove('show');
                if(rrPlaceholder) rrPlaceholder.style.display='';
            }
        }
        const scdInp=document.getElementById('pscd-'+id);
        if(scdInp && !scdInp.value && (sel.value==='cleared' || sel.value==='returned')){
            const today=new Date().toISOString().split('T')[0];
            scdInp.value=today;
            scdInp.classList.add('has-date');
        }
        if(scdInp && (sel.value==='deposited' || sel.value==='pending')){
            scdInp.value='';
            scdInp.classList.remove('has-date');
        }
    }
}
async function pSave(id){
    const stEl=document.getElementById('pstat-'+id);
    const rEl=document.getElementById('pref-'+id);
    const rrEl=document.getElementById('prr-'+id);
    const scdEl=document.getElementById('pscd-'+id);
    const btn=document.getElementById('psave-'+id);
    if(!stEl||!btn)return;
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>';
    const fd=new FormData();
    fd.append('ajax_action','update_cheque_status');
    fd.append('cheque_id',id);
    fd.append('status',stEl.value);
    fd.append('bank_ref',rEl?rEl.value:'');
    fd.append('return_reason',rrEl?rrEl.value:'');
    fd.append('status_change_date',scdEl?scdEl.value:'');
    try{
        const res=await fetch('deposit_view.php',{method:'POST',body:fd});
        const data=await res.json();
        if(data.success){
            _dirty.delete(id);
            btn.classList.remove('vis');
            btn.disabled=false;
            btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save';
            if(scdEl){
                if(data.status_change_date){scdEl.value=data.status_change_date;scdEl.classList.add('has-date');}
                else scdEl.classList.remove('has-date');
            }
            if(stEl.value==='returned'){
                const custCell=stEl.closest('tr')?.querySelectorAll('td')[3];
                if(custCell && !custCell.querySelector('.ret-charge-badge')){
                    const badge=document.createElement('span');
                    badge.className='ret-charge-badge';
                    badge.innerHTML='<i class="fa-solid fa-circle-minus"></i> Return Charge: Rs. 250.00';
                    custCell.appendChild(badge);
                }
                if(rrEl) rrEl.classList.add('show');
                showToast('Saved ✓ — Return charge Rs. 250 recorded','ok');
            } else {
                showToast('Saved ✓','ok');
            }
        } else {
            showToast(data.error||'Save failed','err');
            btn.disabled=false;
            btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save';
        }
    }catch(e){showToast('Network error','err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save';}
}

/* ════ DEPOSIT DATE CHANGE ════ */
function openDepDateEdit(){
    document.getElementById('depDateEditForm').classList.add('open');
    document.getElementById('newDepDate').focus();
}
function closeDepDateEdit(){
    document.getElementById('depDateEditForm').classList.remove('open');
    document.getElementById('newDepDate').value = _currentDepDate;
}
function confirmDepDateChange(){
    const newDate = document.getElementById('newDepDate').value;
    if (!newDate) { showToast('Please select a new deposit date','err'); return; }
    if (newDate === _currentDepDate) { showToast('New date is the same as current date','err'); return; }

    /* Format dates for display */
    const fmt = d => {
        const [y,m,day] = d.split('-');
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return `${parseInt(day)} ${months[parseInt(m)-1]} ${y}`;
    };

    document.getElementById('ddOldDate').textContent = fmt(_currentDepDate) + ' (' + _currentDepDate + ')';
    document.getElementById('ddNewDate').textContent = fmt(newDate) + ' (' + newDate + ')';
    document.getElementById('ddCountText').innerHTML =
        'This will update <strong>' + _chequeCount + '</strong> cheque(s). All changes will be logged to <code>cheque_logs</code>.';
    document.getElementById('ddConfirmOverlay').classList.add('open');
}
function closeDepDateConfirm(){
    document.getElementById('ddConfirmOverlay').classList.remove('open');
}
async function applyDepDateChange(){
    const newDate = document.getElementById('newDepDate').value;
    const applyBtn = document.getElementById('ddConfirmApplyBtn');
    applyBtn.disabled = true;
    applyBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Updating…';
    const fd = new FormData();
    fd.append('ajax_action','update_deposit_date');
    fd.append('old_date', _currentDepDate);
    fd.append('new_date', newDate);
    fd.append('acc_id',   _accId);
    fd.append('dep_type', _depType);
    try {
        const res  = await fetch('deposit_view.php', {method:'POST', body:fd});
        const data = await res.json();
        if (data.success) {
            closeDepDateConfirm();
            closeDepDateEdit();
            showToast('Deposit date updated for ' + data.updated + ' cheque(s) ✓', 'ok');
            /* Update UI immediately */
            const fmt = d => {
                const [y,m,day] = d.split('-');
                const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                return `${parseInt(day)} ${months[parseInt(m)-1]} ${y}`;
            };
            const ddbDate = document.getElementById('depDateDisplay');
            if (ddbDate) ddbDate.textContent = fmt(newDate);
            const ddbSub = ddbDate ? ddbDate.nextElementSibling : null;
            if (ddbSub) ddbSub.textContent = newDate;
            /* Update page title in header */
            const hdrTitle = document.querySelector('.dv-hdr-title');
            if (hdrTitle) hdrTitle.textContent = 'Deposit Batch — ' + fmt(newDate);
            _currentDepDate = newDate;
            /* Reload after short delay so user can see the toast */
            setTimeout(() => {
                const url = new URL(window.location.href);
                url.searchParams.set('dep_date', newDate);
                window.location.href = url.toString();
            }, 1800);
        } else {
            showToast(data.error || 'Update failed', 'err');
            applyBtn.disabled = false;
            applyBtn.innerHTML = '<i class="fa-solid fa-calendar-check"></i> Confirm & Update';
        }
    } catch(e) {
        showToast('Network error: ' + e.message, 'err');
        applyBtn.disabled = false;
        applyBtn.innerHTML = '<i class="fa-solid fa-calendar-check"></i> Confirm & Update';
    }
}

/* ════ NDB MODAL ════ */
let _prevRows=[];
function openNdbModal(){document.getElementById('ndbModal').classList.add('open');document.body.style.overflow='hidden';}
function closeNdbModal(){
    document.getElementById('ndbModal').classList.remove('open');
    document.body.style.overflow='';
    document.getElementById('ndbStep1').style.display='';
    document.getElementById('ndbStep2').style.display='none';
    document.getElementById('ndbStep3').style.display='none';
    document.getElementById('ndbTab1').className='ndb-tab active';
    document.getElementById('ndbTab2').className='ndb-tab';
    document.getElementById('ndbTab3').className='ndb-tab';
    document.getElementById('ndbApplyBtn').style.display='none';
    document.getElementById('ndbDoneBtn').style.display='none';
    document.getElementById('ndbInfo').textContent='Upload a NDB bank statement CSV to begin.';
    document.getElementById('uploadArea').innerHTML=`<div class="ua-icon"><i class="fa-solid fa-file-excel"></i></div><div class="ua-title">Upload NDB Bank Statement</div><div class="ua-sub">CSV file · Drag & drop or click to browse<br><small style="opacity:.7;">(For Excel: File → Save As → CSV first)</small></div><div style="margin-top:14px;"><span style="background:#0d9488;color:#fff;padding:7px 18px;border-radius:7px;font-size:12px;font-weight:700;"><i class="fa-solid fa-folder-open"></i> Browse CSV File</span></div>`;
    document.getElementById('ndbFileInput').value='';
    document.getElementById('ndbStatusDate').value='';
    _prevRows=[];
}
function handleFileDrop(e){e.preventDefault();document.getElementById('uploadArea').classList.remove('drag-over');const f=e.dataTransfer.files[0];if(f)processFile(f);}
function handleFileSelect(inp){const f=inp.files[0];if(f)processFile(f);}
async function processFile(file){
    const ext=file.name.split('.').pop().toLowerCase();
    if(ext!=='csv'){showToast('Please upload a CSV file.','err');return;}
    document.getElementById('ndbInfo').textContent='Parsing file…';
    document.getElementById('uploadArea').innerHTML=`<div class="ua-icon"><i class="fa-solid fa-spinner fa-spin" style="color:#0d9488;"></i></div><div class="ua-title">Parsing ${esc(file.name)}…</div>`;
    const text=await file.text();
    const {rows,meta}=parseNdb(text);
    if(!rows.length){showToast('No recognisable cheque transactions found.','err');return;}
    document.getElementById('ndbInfo').textContent=`Found ${rows.length} transaction(s). Matching…`;
    const fd=new FormData();
    fd.append('ajax_action','ndb_preview');
    fd.append('parsed_rows',JSON.stringify(rows));
    try{
        const res=await fetch('deposit_view.php',{method:'POST',body:fd});
        const data=await res.json();
        if(!data.success)throw new Error(data.error||'Preview failed');
        _prevRows=data.rows||[];
        renderPreview(meta);
    }catch(e){showToast('Error: '+e.message,'err');}
}
function parseNdb(text){
    const lines=text.split(/\r?\n/);
    const rows=[];
    let meta={account:'',period:''};
    lines.forEach(raw=>{
        const line=raw.trim();
        if(/account\s*(no|number)/i.test(line)){const parts=parseCsvLine(line);if(parts[1])meta.account=parts[1].replace(/"/g,'').trim();}
        if(/statement\s*period/i.test(line)){const parts=parseCsvLine(line);if(parts[1])meta.period=parts[1].replace(/"/g,'').trim();}
    });
    lines.forEach(raw=>{
        const line=raw.trim();if(!line)return;
        const parts=parseCsvLine(line);
        const desc=parts[2]||'';
        const bankRef=(parts[3]||'').replace(/\\/g,'\\').trim();
        const debitAmt=parseFloat((parts[4]||'0').replace(/[,\s]/g,''))||0;
        const creditAmt=parseFloat((parts[5]||'0').replace(/[,\s]/g,''))||0;
        const txDate=(parts[0]||'').trim();
        let m=desc.match(/Outward\s+Cheque\s+Deposit\s*\/\s*CHQ\s+NO\s*[-–]\s*(\d+)/i);
        if(m){rows.push({cheque_no:m[1],type:'cleared',amount:creditAmt,bank_ref:bankRef,tx_date:txDate,description:desc});return;}
        m=desc.match(/Outward\s+Cl[gq]\s+Chq\s+Return\s*\/\s*CHQ\s+NO\s*[-–]\s*(\d+)/i);
        if(m)rows.push({cheque_no:m[1],type:'returned',amount:Math.abs(debitAmt),bank_ref:bankRef,tx_date:txDate,description:desc});
    });
    return{rows,meta};
}
function parseCsvLine(line){
    const r=[];let cur='',inQ=false;
    for(let i=0;i<line.length;i++){const c=line[i];if(c==='"'){inQ=!inQ;}else if(c===','&&!inQ){r.push(cur.trim());cur='';}else{cur+=c;}}
    r.push(cur.trim());return r;
}
function renderPreview(meta){
    document.getElementById('ndbStep1').style.display='none';
    document.getElementById('ndbStep2').style.display='';
    document.getElementById('ndbTab1').className='ndb-tab done';
    document.getElementById('ndbTab2').className='ndb-tab active';
    const today=new Date().toISOString().split('T')[0];
    document.getElementById('ndbStatusDate').value=today;
    document.getElementById('stmtBar').innerHTML=`<div style="font-size:13px;font-weight:700;color:#0f766e;margin-bottom:6px;"><i class="fa-solid fa-file-lines"></i> NDB Statement Parsed</div><div class="stmt-grid">${meta.account?`<div class="stmt-item"><div class="si-l">Account No</div><div class="si-v mono">${esc(meta.account)}</div></div>`:''} ${meta.period?`<div class="stmt-item"><div class="si-l">Period</div><div class="si-v">${esc(meta.period)}</div></div>`:''}<div class="stmt-item"><div class="si-l">Transactions</div><div class="si-v">${_prevRows.length}</div></div></div>`;
    let matched=0,toClr=0,toRet=0,notFound=0,amtD=0,alreadyDone=0;
    _prevRows.forEach(r=>{
        if(r.already_updated){alreadyDone++;matched++;return;}
        if(r.matched){matched++;if(r.type==='cleared')toClr++;else toRet++;if(!r.amt_match)amtD++;}
        else notFound++;
    });
    document.getElementById('prevStats').innerHTML=`<span class="prev-stat"><i class="fa-solid fa-check-circle"></i> ${matched} matched</span><span class="prev-stat" style="background:#dcfce7;border-color:#86efac;color:#166534;"><i class="fa-solid fa-circle-check"></i> ${toClr} → Cleared</span><span class="prev-stat" style="background:#fee2e2;border-color:#fecaca;color:#991b1b;"><i class="fa-solid fa-circle-xmark"></i> ${toRet} → Returned</span>${alreadyDone?`<span class="prev-stat skip"><i class="fa-solid fa-ban"></i> ${alreadyDone} already updated</span>`:''}${notFound?`<span class="prev-stat err"><i class="fa-solid fa-triangle-exclamation"></i> ${notFound} not found</span>`:''}${amtD?`<span class="prev-stat warn"><i class="fa-solid fa-scale-unbalanced"></i> ${amtD} amount mismatch</span>`:''}`;
    const slbls={deposited:'<span class="pill p-blue">Deposited</span>',cleared:'<span class="pill p-green">Cleared</span>',returned:'<span class="pill p-red">Returned</span>',sent_back:'<span class="pill p-violet">Sent Back</span>',pending:'<span class="pill p-gray">Pending</span>'};
    let h='';
    _prevRows.forEach((row,idx)=>{
        const isAlready=row.already_updated;
        const rc=isAlready?'row-already':(row.type==='cleared'?'row-cleared':row.type==='returned'?'row-returned':'');
        const nf=!row.matched?'row-notfound':'';
        const matchB=row.matched?'<span class="mb mb-ok"><i class="fa-solid fa-check"></i> Matched</span>':'<span class="mb mb-no"><i class="fa-solid fa-xmark"></i> Not Found</span>';
        const typeB=row.type==='cleared'?'<span class="mb mb-clr"><i class="fa-solid fa-circle-check"></i> Clear</span>':'<span class="mb mb-ret"><i class="fa-solid fa-circle-xmark"></i> Return</span>';
        const amtB=row.matched?(row.amt_match?'<span class="mb mb-amt"><i class="fa-solid fa-check"></i> OK</span>':'<span class="mb mb-amtd"><i class="fa-solid fa-triangle-exclamation"></i> Diff</span>'):'—';
        const dbA=row.db_amount!=null?'Rs. '+parseFloat(row.db_amount).toLocaleString('en-US',{minimumFractionDigits:2}):'—';
        const bnkA='Rs. '+parseFloat(row.bank_amount).toLocaleString('en-US',{minimumFractionDigits:2});
        const dbSt=slbls[row.db_status]||'—';
        const alreadyBadge=isAlready?`<span class="mb mb-skip" style="margin-top:3px;display:inline-flex;"><i class="fa-solid fa-ban"></i> Already ${row.db_status}</span>`:'';
        const reasonCell=(row.type==='returned'&&!isAlready&&row.matched)?`<input type="text" class="reason-inp" id="reason-${idx}" placeholder="e.g. Insufficient funds…">`:'<span style="color:#d1d5db;font-size:10px;">—</span>';
        h+=`<tr class="${rc} ${nf}" data-idx="${idx}"><td><input type="checkbox" class="row-ndb-cb" data-idx="${idx}" ${(row.matched&&!isAlready)?'checked':'disabled'} title="${!row.matched?'Not found in DB':isAlready?'Already '+row.db_status:''}}"></td><td><span class="mono" style="color:#3730a3;font-size:12px;">${esc(row.cheque_no)}</span></td><td>${typeB}</td><td><div style="font-size:11.5px;font-weight:600;color:#1f2937;">${esc(row.customer_name||'—')}</div><div style="font-size:10px;color:#9ca3af;font-family:'Courier New',monospace;">${esc(row.db_t_code||'')}</div>${matchB}${alreadyBadge}</td><td class="tr" style="font-weight:700;">${bnkA}</td><td class="tr" style="color:#6b7280;">${dbA}</td><td class="tc">${amtB}</td><td class="tc">${dbSt}</td><td><span style="font-family:'Courier New',monospace;font-size:10px;color:#0369a1;">${esc(row.bank_ref||'')}</span></td><td>${reasonCell}</td><td style="font-size:11px;white-space:nowrap;">${esc(row.tx_date||'')}</td></tr>`;
    });
    document.getElementById('prevBody').innerHTML=h||'<tr><td colspan="11" style="text-align:center;padding:20px;color:#9ca3af;">No matching rows found</td></tr>';
    const selN=_prevRows.filter(r=>r.matched&&!r.already_updated).length;
    document.getElementById('ndbInfo').textContent=`${selN} cheque(s) ready to update.`;
    document.getElementById('ndbApplyBtn').style.display='flex';
    document.getElementById('prevBody').addEventListener('change',updateNdbCount);
}
function selAll(v){document.querySelectorAll('.row-ndb-cb:not(:disabled)').forEach(cb=>cb.checked=v);document.getElementById('prevSelAll').checked=v;updateNdbCount();}
function selByType(type){document.querySelectorAll('.row-ndb-cb').forEach(cb=>{if(!cb.disabled){const r=_prevRows[parseInt(cb.dataset.idx)];cb.checked=r&&r.type===type;}});updateNdbCount();}
function selMatched(v){document.querySelectorAll('.row-ndb-cb').forEach(cb=>{if(!cb.disabled){const r=_prevRows[parseInt(cb.dataset.idx)];cb.checked=r&&r.matched&&!r.already_updated&&v;}});updateNdbCount();}
function updateNdbCount(){const n=document.querySelectorAll('.row-ndb-cb:checked').length;document.getElementById('ndbInfo').textContent=`${n} cheque(s) selected. Click Apply to update.`;}
async function applyNdb(){
    const cbs=Array.from(document.querySelectorAll('.row-ndb-cb:checked'));
    if(!cbs.length){showToast('No cheques selected','err');return;}
    const items=cbs.map(cb=>{const idx=parseInt(cb.dataset.idx);const row=_prevRows[idx];if(!row||!row.matched||!row.db_id)return null;const reasonEl=document.getElementById('reason-'+idx);return{...row,return_reason:(reasonEl?reasonEl.value.trim():'')};}).filter(Boolean);
    if(!items.length){showToast('No matched cheques selected','err');return;}
    const btn=document.getElementById('ndbApplyBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Applying…';
    const fd=new FormData();
    fd.append('ajax_action','ndb_apply');
    fd.append('items',JSON.stringify(items));
    fd.append('ndb_status_date',document.getElementById('ndbStatusDate').value||'');
    try{
        const res=await fetch('deposit_view.php',{method:'POST',body:fd});
        const data=await res.json();
        if(!data.success)throw new Error(data.error||'Apply failed');
        showStep3(data);
    }catch(e){showToast('Error: '+e.message,'err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Apply Selected Updates';}
}
function showStep3(data){
    document.getElementById('ndbStep2').style.display='none';
    document.getElementById('ndbStep3').style.display='';
    document.getElementById('ndbTab2').className='ndb-tab done';
    document.getElementById('ndbTab3').className='ndb-tab active';
    document.getElementById('ndbApplyBtn').style.display='none';
    document.getElementById('ndbDoneBtn').style.display='flex';
    document.getElementById('ndbInfo').textContent='Update complete!';
    const skip=data.skip_list||[];
    const alreadyMsg=data.already_done?`<div style="background:#f3f4f6;border:1px solid #d1d5db;border-radius:10px;padding:12px 16px;margin-top:10px;"><div style="font-weight:700;color:#6b7280;margin-bottom:4px;"><i class="fa-solid fa-ban"></i> ${data.already_done} skipped (already updated)</div></div>`:'';
    document.getElementById('applyResult').innerHTML=`<div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:2px solid #4ade80;border-radius:12px;padding:24px;text-align:center;margin-bottom:16px;"><i class="fa-solid fa-circle-check" style="font-size:42px;color:#16a34a;margin-bottom:12px;display:block;"></i><div style="font-size:22px;font-weight:800;color:#166534;margin-bottom:6px;">${data.updated} Cheque(s) Updated</div><div style="font-size:13px;color:#4b7c59;">All changes saved and logged.</div></div>${alreadyMsg}${data.skipped?`<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:14px 16px;margin-top:10px;"><div style="font-weight:700;color:#92400e;margin-bottom:5px;"><i class="fa-solid fa-triangle-exclamation"></i> ${data.skipped} skipped</div><div style="font-size:12px;color:#92400e;">${skip.map(s=>esc(s)).join(', ')}</div></div>`:''}`;
}
/* ════ HELPERS ════ */
function esc(s){if(s==null)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function showToast(msg,type){
    const t=document.getElementById('toast');
    t.style.background=type==='ok'?'#166534':'#dc2626';
    t.textContent=msg;t.classList.add('show');
    clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),3500);
}
/* Close confirm on overlay click */
document.getElementById('ddConfirmOverlay').addEventListener('click',function(e){if(e.target===this)closeDepDateConfirm();});
</script>

<?php include 'footer.php'; ?>