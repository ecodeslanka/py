<?php
/**
 * return_charges.php — Cheque Return Charges Register
 * ──────────────────────────────────────────────────────
 *  Shows all records from cheque_return_charges table.
 *  Each row has a "Load Pay" button that opens the same
 *  settlement modal used in return_cheques.php.
 *  Supports: Cash + Cheque settlement payments,
 *  per-charge settlement payments table, partial / fully
 *  settled three-state badge.
 */

if (session_status() === PHP_SESSION_NONE) session_start();

function get_current_user_label_rc() {
    return $_SESSION['username']   ??
           $_SESSION['user_name']  ??
           $_SESSION['name']       ??
           $_SESSION['full_name']  ??
           $_SESSION['email']      ??
           (isset($_SESSION['user_id']) ? 'User #'.$_SESSION['user_id'] : 'system');
}

/* ══════════════════════════════════════════════════════
   AJAX — MUST be before include 'header.php'
══════════════════════════════════════════════════════ */

/* ── AJAX: get rows ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'rc_rows') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');

    /* ensure settlement columns on cheque_return_charges */
    foreach (['rc_settled'=>'TINYINT(1) DEFAULT 0','rc_settlement_amount'=>'DECIMAL(12,2) DEFAULT 0.00','rc_settlement_date'=>'DATE NULL','rc_settlement_note'=>'TEXT NULL'] as $_ec=>$_ed) {
        $_er = mysqli_query($conn,"SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cheque_return_charges' AND COLUMN_NAME='$_ec'");
        if (!$_er || (int)mysqli_fetch_assoc($_er)['n']===0) mysqli_query($conn,"ALTER TABLE cheque_return_charges ADD COLUMN $_ec $_ed");
    }
    unset($_ec,$_ed,$_er);

    $page     = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(1, intval($_GET['per']  ?? 50));
    $search   = trim($_GET['q']             ?? '');
    $f_tcode  = trim($_GET['t_code']        ?? '');
    $f_from   = trim($_GET['date_from']     ?? '');
    $f_to     = trim($_GET['date_to']       ?? '');
    $f_settled= trim($_GET['settled']       ?? '');

    $where = ['1=1'];
    if ($f_tcode)  $where[] = "rc.t_code LIKE '%".mysqli_real_escape_string($conn,$f_tcode)."%'";
    if ($f_from)   $where[] = "rc.charged_at >= '".mysqli_real_escape_string($conn,$f_from)." 00:00:00'";
    if ($f_to)     $where[] = "rc.charged_at <= '".mysqli_real_escape_string($conn,$f_to)." 23:59:59'";
    if ($f_settled === '1') $where[] = "COALESCE(rc.rc_settled,0)=1";
    if ($f_settled === '0') $where[] = "(rc.rc_settled IS NULL OR rc.rc_settled=0)";

    if ($search !== '') {
        $s = '%'.mysqli_real_escape_string($conn,$search).'%';
        $where[] = "(rc.cheque_no LIKE '$s' OR rc.t_code LIKE '$s'
                     OR COALESCE(c.shop_name,rc.t_code) LIKE '$s'
                     OR CAST(rc.return_charge AS CHAR) LIKE '$s'
                     OR CAST(rc.cheque_amount AS CHAR) LIKE '$s')";
    }

    $where_sql = implode(' AND ', $where);

    $base_sql = "
        FROM cheque_return_charges rc
        LEFT JOIN customers c ON c.t_code = rc.t_code
        LEFT JOIN cheques   ch ON ch.id   = rc.cheque_id
        WHERE $where_sql";

    $cnt_r = mysqli_query($conn,"SELECT COUNT(*) AS cnt $base_sql");
    $total = $cnt_r ? (int)mysqli_fetch_assoc($cnt_r)['cnt'] : 0;

    $offset = ($page-1)*$per_page;
    $data_sql = "
        SELECT rc.id, rc.cheque_id, rc.cheque_no, rc.t_code,
               rc.cheque_amount, rc.return_charge, rc.return_reason,
               rc.charged_at, rc.charged_by,
               COALESCE(rc.rc_settled,0)              AS rc_settled,
               COALESCE(rc.rc_settlement_amount,0)    AS rc_settlement_amount,
               COALESCE(rc.rc_settlement_date,'')     AS rc_settlement_date,
               COALESCE(rc.rc_settlement_note,'')     AS rc_settlement_note,
               COALESCE(c.shop_name, rc.t_code)       AS customer_name,
               COALESCE(ch.bank_name,'')              AS bank_name,
               COALESCE(ch.cheque_date,'')            AS cheque_date,
               COALESCE(ch.status,'')                 AS cheque_status,
               COALESCE(ch.return_reason,'')          AS cheque_return_reason
        $base_sql
        ORDER BY 
    CASE 
        WHEN COALESCE(rc.rc_settled, 0) = 0 AND COALESCE(rc.rc_settlement_amount, 0) = 0 THEN 0
        WHEN COALESCE(rc.rc_settled, 0) = 0 AND COALESCE(rc.rc_settlement_amount, 0) > 0 THEN 1
        ELSE 2
    END ASC,
    rc.charged_at DESC,
    rc.id DESC
        LIMIT $per_page OFFSET $offset";

    $res  = mysqli_query($conn,$data_sql);
    $rows = [];
    if ($res) while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;

    $agg_r = mysqli_query($conn,"SELECT
        COALESCE(SUM(rc.return_charge),0) AS tot,
        COALESCE(SUM(CASE WHEN COALESCE(rc.rc_settled,0)=1 THEN rc.return_charge ELSE 0 END),0) AS s_amt,
        COUNT(CASE WHEN COALESCE(rc.rc_settled,0)=1 THEN 1 END) AS s_cnt,
        COALESCE(SUM(CASE WHEN COALESCE(rc.rc_settled,0)=0 THEN rc.return_charge ELSE 0 END),0) AS u_amt,
        COUNT(CASE WHEN COALESCE(rc.rc_settled,0)=0 THEN 1 END) AS u_cnt
        $base_sql");
    $agg = $agg_r ? mysqli_fetch_assoc($agg_r) : ['tot'=>0,'s_amt'=>0,'s_cnt'=>0,'u_amt'=>0,'u_cnt'=>0];

    echo json_encode([
        'success'      => true,
        'rows'         => $rows,
        'total'        => $total,
        'grand_total'  => (float)$agg['tot'],
        'settled_amt'  => (float)$agg['s_amt'],
        'settled_cnt'  => (int)$agg['s_cnt'],
        'unsettled_amt'=> (float)$agg['u_amt'],
        'unsettled_cnt'=> (int)$agg['u_cnt'],
        'page'         => $page,
        'per_page'     => $per_page,
        'pages'        => max(1,(int)ceil($total/$per_page)),
    ]);
    exit;
}

/* ── AJAX: get SR codes and employees ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'issue_persons') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');

    /* Load SR Codes from field_summary */
    $sr_persons = [];
    $sr_r = mysqli_query($conn, "SELECT DISTINCT sr_code AS code FROM field_summary WHERE sr_code != '' ORDER BY sr_code");
    if ($sr_r) {
        while ($sr_row = mysqli_fetch_assoc($sr_r)) {
            $sr_persons[] = ['code' => $sr_row['code']];
        }
    }

    /* Load Employees with designations */
    $employees = [];
    $emp_r = mysqli_query($conn, "
        SELECT 
            e.id,
            e.employee_id,
            e.employee_full_name,
            COALESCE(d.designation_name, '') AS designation_name
        FROM employees e
        LEFT JOIN designations d ON d.id = e.designation_id
        WHERE e.status = 'active'
        ORDER BY e.employee_full_name");
    if ($emp_r) {
        while ($emp_row = mysqli_fetch_assoc($emp_r)) {
            $employees[] = $emp_row;
        }
    }

    echo json_encode([
        'success' => true,
        'sr_persons' => $sr_persons,
        'employees' => $employees
    ]);
    exit;
}

/* ── AJAX: delete settlement payment ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'rc_delete_payment') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');

    $pid = intval($_POST['payment_id'] ?? 0);
    $rid = intval($_POST['rc_id']      ?? 0);
    if (!$pid || !$rid) { echo json_encode(['success'=>false,'error'=>'Invalid']); exit; }

    $pr = mysqli_query($conn, "SELECT id FROM rc_settlement_payments WHERE id=$pid AND rc_id=$rid LIMIT 1");
    if (!$pr || mysqli_num_rows($pr) === 0) {
        echo json_encode(['success'=>false,'error'=>'Payment not found']); exit;
    }

    mysqli_query($conn, "DELETE FROM rc_settlement_payments WHERE id=$pid AND rc_id=$rid");

    $tr = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS tot FROM rc_settlement_payments WHERE rc_id=$rid");
    $new_total = $tr ? (float)mysqli_fetch_assoc($tr)['tot'] : 0;

    $cr = mysqli_query($conn, "SELECT return_charge FROM cheque_return_charges WHERE id=$rid LIMIT 1");
    $charge_total = $cr ? (float)mysqli_fetch_assoc($cr)['return_charge'] : 0;
    $fully = ($new_total >= $charge_total && $charge_total > 0) ? 1 : 0;

    mysqli_query($conn, "UPDATE cheque_return_charges SET rc_settlement_amount=$new_total, rc_settled=$fully WHERE id=$rid");

    echo json_encode(['success'=>true,'new_total'=>$new_total,'fully_settled'=>$fully,'charge_total'=>$charge_total]);
    exit;
}

/* ── AJAX: get charge detail for modal ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'rc_detail') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');

    $rid = intval($_GET['rc_id'] ?? 0);
    if (!$rid) { echo json_encode(['success'=>false,'error'=>'Invalid ID']); exit; }

    $r = mysqli_query($conn,"
        SELECT rc.id, rc.cheque_id, rc.cheque_no, rc.t_code,
               rc.cheque_amount, rc.return_charge, rc.return_reason,
               rc.charged_at,
               COALESCE(rc.rc_settled,0)           AS rc_settled,
               COALESCE(rc.rc_settlement_amount,0) AS rc_settlement_amount,
               COALESCE(c.shop_name, rc.t_code)    AS customer_name,
               COALESCE(c.payment_mode,'')         AS payment_mode,
               COALESCE(c.credit_limit,0)          AS credit_limit,
               COALESCE(c.credit_days,0)           AS credit_days,
               COALESCE(c.special_credit_policy_days,'') AS special_credit_policy_days,
               COALESCE(ch.bank_name,'')           AS bank_name,
               COALESCE(ch.bank_code,'')           AS bank_code,
               COALESCE(ch.cheque_date,'')         AS cheque_date,
               COALESCE(ip.field_summary_id,0)     AS field_summary_id,
               COALESCE(ip.field_summary_detail_id,0) AS field_summary_detail_id,
               COALESCE(ip.invoice_num,'')         AS invoice_num
        FROM cheque_return_charges rc
        LEFT JOIN customers c ON c.t_code = rc.t_code
        LEFT JOIN cheques   ch ON ch.id = rc.cheque_id
        LEFT JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id
        WHERE rc.id = $rid LIMIT 1");

    $row = $r ? mysqli_fetch_assoc($r) : null;
    if (!$row) { echo json_encode(['success'=>false,'error'=>'Not found']); exit; }

    echo json_encode(['success'=>true,'rc'=>$row]);
    exit;
}

/* ── AJAX: list settlement payments for a charge ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'rc_settlement_payments') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');

    $rid = intval($_GET['rc_id'] ?? 0);
    if (!$rid) { echo json_encode(['success'=>false,'payments'=>[]]); exit; }

    mysqli_query($conn,"CREATE TABLE IF NOT EXISTS rc_settlement_payments (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        rc_id          INT NOT NULL,
        payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
        payment_date   DATE NULL,
        amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        reference_no   VARCHAR(100) DEFAULT NULL,
        remarks        TEXT DEFAULT NULL,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by     VARCHAR(100) DEFAULT 'system',
        INDEX idx_rcsp_rid (rc_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    /* Ensure cheque detail columns exist */
    foreach ([
        'cheque_no'   => "VARCHAR(100) DEFAULT NULL",
        'bank_name'   => "VARCHAR(100) DEFAULT NULL",
        'bank_code'   => "VARCHAR(50)  DEFAULT NULL",
        'branch_name' => "VARCHAR(100) DEFAULT NULL",
        'cheque_date' => "DATE NULL",
    ] as $_col => $_def) {
        $chk2 = mysqli_query($conn, "SHOW COLUMNS FROM rc_settlement_payments LIKE '$_col'");
        if (!$chk2 || mysqli_num_rows($chk2) === 0)
            mysqli_query($conn, "ALTER TABLE rc_settlement_payments ADD COLUMN $_col $_def");
    }

    $rows = [];
    $r = mysqli_query($conn,"SELECT * FROM rc_settlement_payments WHERE rc_id=$rid ORDER BY created_at ASC");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;

    echo json_encode(['success'=>true,'payments'=>$rows,'total_settled'=>array_sum(array_column($rows,'amount'))]);
    exit;
}

/* ── AJAX: add settlement payment ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'rc_add_payment') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');

    $rid    = intval($_POST['rc_id'] ?? 0);
    $method = mysqli_real_escape_string($conn, trim($_POST['payment_method'] ?? 'cash'));
    $date   = mysqli_real_escape_string($conn, trim($_POST['payment_date']   ?? date('Y-m-d')));
    $amt    = round(abs(floatval($_POST['amount'] ?? 0)), 2);
    $ref    = mysqli_real_escape_string($conn, trim($_POST['reference_no']   ?? ''));
    $rem    = mysqli_real_escape_string($conn, trim($_POST['remarks']        ?? ''));

    /* Collector fields */
    $collected_by    = mysqli_real_escape_string($conn, trim($_POST['collected_by']    ?? ''));
    $delivery_person = mysqli_real_escape_string($conn, trim($_POST['delivery_person'] ?? ''));
    $sr_code         = mysqli_real_escape_string($conn, trim($_POST['sr_code']         ?? ''));
    $employee_id     = intval($_POST['employee_id'] ?? 0);

    /* Cheque detail fields for cheques table upsert */
    $chq_no      = mysqli_real_escape_string($conn, trim($_POST['cheque_no']       ?? ''));
    $bank_name   = mysqli_real_escape_string($conn, trim($_POST['bank_name']        ?? ''));
    $bank_code   = mysqli_real_escape_string($conn, trim($_POST['bank_code']        ?? ''));
    $branch_name = mysqli_real_escape_string($conn, trim($_POST['branch_name']      ?? ''));
    $branch_code = mysqli_real_escape_string($conn, trim($_POST['branch_code']      ?? ''));
    $chq_date    = mysqli_real_escape_string($conn, trim($_POST['cheque_date']      ?? ''));
    $fsid_ins    = intval($_POST['field_summary_id']   ?? 0);
    $tcode_ins   = mysqli_real_escape_string($conn, trim($_POST['t_code']           ?? ''));
    $inv_pay_id  = intval($_POST['invoice_payment_id'] ?? 0);

    if (!$rid || $amt <= 0) { echo json_encode(['success'=>false,'error'=>'Invalid charge or zero amount']); exit; }

    /* ensure table */
    mysqli_query($conn,"CREATE TABLE IF NOT EXISTS rc_settlement_payments (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        rc_id          INT NOT NULL,
        payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
        payment_date   DATE NULL,
        amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        reference_no   VARCHAR(100) DEFAULT NULL,
        remarks        TEXT DEFAULT NULL,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by     VARCHAR(100) DEFAULT 'system',
        INDEX idx_rcsp_rid (rc_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* ensure settlement cols */
    foreach (['rc_settled'=>'TINYINT(1) DEFAULT 0','rc_settlement_amount'=>'DECIMAL(12,2) DEFAULT 0.00','rc_settlement_date'=>'DATE NULL','rc_settlement_note'=>'TEXT NULL'] as $_ec2=>$_ed2) {
        $_er2 = mysqli_query($conn,"SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cheque_return_charges' AND COLUMN_NAME='$_ec2'");
        if (!$_er2||(int)mysqli_fetch_assoc($_er2)['n']===0) mysqli_query($conn,"ALTER TABLE cheque_return_charges ADD COLUMN $_ec2 $_ed2");
    }
    unset($_ec2,$_ed2,$_er2);
    
    /* Ensure cheque detail columns exist */
    foreach ([
        'cheque_no'   => "VARCHAR(100) DEFAULT NULL",
        'bank_name'   => "VARCHAR(100) DEFAULT NULL",
        'bank_code'   => "VARCHAR(50)  DEFAULT NULL",
        'branch_name' => "VARCHAR(100) DEFAULT NULL",
        'cheque_date' => "DATE NULL",
    ] as $_col => $_def) {
        $chk2 = mysqli_query($conn, "SHOW COLUMNS FROM rc_settlement_payments LIKE '$_col'");
        if (!$chk2 || mysqli_num_rows($chk2) === 0)
            mysqli_query($conn, "ALTER TABLE rc_settlement_payments ADD COLUMN $_col $_def");
    }

    /* Ensure collector / rep columns exist (delivery person & SR rep) */
    foreach ([
        'collected_by'    => "VARCHAR(20)  DEFAULT NULL",
        'delivery_person' => "VARCHAR(150) DEFAULT NULL",
        'sr_code'         => "VARCHAR(50)  DEFAULT NULL",
        'employee_id'     => "INT          DEFAULT NULL",
    ] as $_ccol => $_cdef) {
        $chk3 = mysqli_query($conn, "SHOW COLUMNS FROM rc_settlement_payments LIKE '$_ccol'");
        if (!$chk3 || mysqli_num_rows($chk3) === 0)
            mysqli_query($conn, "ALTER TABLE rc_settlement_payments ADD COLUMN $_ccol $_cdef");
    }
    unset($_ccol,$_cdef,$chk3);

    $cu = mysqli_real_escape_string($conn, get_current_user_label_rc());
    
    $chq_no_sql      = $chq_no    ? "'$chq_no'"    : 'NULL';
    $bank_name_sql   = $bank_name ? "'$bank_name'" : 'NULL';
    $bank_code_sql   = $bank_code ? "'$bank_code'" : 'NULL';
    $branch_name_sql = $branch_name? "'$branch_name'": 'NULL';
    $chq_date_sql    = $chq_date  ? "'$chq_date'"  : 'NULL';

    /* Collector / rep values for settlement record */
    $collected_by_sql    = $collected_by    !== '' ? "'$collected_by'"    : 'NULL';
    $delivery_person_sql = $delivery_person !== '' ? "'$delivery_person'" : 'NULL';
    $sr_code_sql         = $sr_code         !== '' ? "'$sr_code'"         : 'NULL';
    $employee_id_sql     = $employee_id > 0        ? $employee_id         : 'NULL';

    mysqli_query($conn,"INSERT INTO rc_settlement_payments
        (rc_id, payment_method, payment_date, amount, reference_no, remarks,
         cheque_no, bank_name, bank_code, branch_name, cheque_date,
         collected_by, delivery_person, sr_code, employee_id, created_by)
        VALUES
        ($rid, '$method', '$date', $amt, '$ref', '$rem',
         $chq_no_sql, $bank_name_sql, $bank_code_sql, $branch_name_sql, $chq_date_sql,
         $collected_by_sql, $delivery_person_sql, $sr_code_sql, $employee_id_sql, '$cu')");
    $new_id = mysqli_insert_id($conn);

    /* recalculate total */
    $tr = mysqli_query($conn,"SELECT COALESCE(SUM(amount),0) AS tot FROM rc_settlement_payments WHERE rc_id=$rid");
    $new_total = $tr ? (float)mysqli_fetch_assoc($tr)['tot'] : 0;

    $cr = mysqli_query($conn,"SELECT return_charge FROM cheque_return_charges WHERE id=$rid LIMIT 1");
    $charge_total = $cr ? (float)mysqli_fetch_assoc($cr)['return_charge'] : 0;
    $fully = ($new_total >= $charge_total && $charge_total > 0) ? 1 : 0;

    mysqli_query($conn,"UPDATE cheque_return_charges SET rc_settlement_amount=$new_total, rc_settled=$fully, rc_settlement_date='$date' WHERE id=$rid");

    /* upsert settlement cheque into cheques master table */
    if ($method === 'cheque' && $chq_no) {
        $uc_bank_code   = mysqli_real_escape_string($conn, trim($_POST['bank_code']   ?? ''));
        $uc_branch_code = mysqli_real_escape_string($conn, trim($_POST['branch_code'] ?? ''));
        $uc_bank_name   = mysqli_real_escape_string($conn, trim($_POST['bank_name']   ?? ''));
        $uc_branch_name = mysqli_real_escape_string($conn, trim($_POST['branch_name'] ?? ''));
        $uc_chq_date    = mysqli_real_escape_string($conn, trim($_POST['cheque_date'] ?? ''));

        $sql_bank_code   = $uc_bank_code   !== '' ? "'$uc_bank_code'"   : "''";
        $sql_branch_code = $uc_branch_code !== '' ? "'$uc_branch_code'" : "''";
        $sql_bank_name   = $uc_bank_name   !== '' ? "'$uc_bank_name'"   : 'NULL';
        $sql_branch_name = $uc_branch_name !== '' ? "'$uc_branch_name'" : 'NULL';
        $sql_chq_date    = $uc_chq_date    !== '' ? "'$uc_chq_date'"    : 'NULL';
        $sql_fsid        = $fsid_ins > 0          ? $fsid_ins           : 'NULL';
        $sql_inv_pay_id  = $inv_pay_id > 0        ? $inv_pay_id         : 'NULL';

        $lookup = mysqli_query($conn,
            "SELECT id, total_amount
             FROM cheques
             WHERE cheque_no   = '$chq_no'
               AND bank_code   = $sql_bank_code
               AND branch_code = $sql_branch_code
             LIMIT 1");
        $existing = $lookup ? mysqli_fetch_assoc($lookup) : null;

        if ($existing) {
            $new_chq_total = round((float)$existing['total_amount'] + $amt, 2);
            mysqli_query($conn,
                "UPDATE cheques SET
                    total_amount = $new_chq_total,
                    bank_name    = $sql_bank_name,
                    branch_name  = $sql_branch_name,
                    cheque_date  = $sql_chq_date,
                    updated_at   = NOW()
                 WHERE id = " . (int)$existing['id']);
        } else {
            mysqli_query($conn,
                "INSERT INTO cheques
                    (invoice_payment_id, field_summary_id, t_code,
                     cheque_no, cheque_date, amount, total_amount,
                     bank_code, bank_name, branch_code, branch_name,
                     received_date, status)
                 VALUES
                    ($sql_inv_pay_id, $sql_fsid, '$tcode_ins',
                     '$chq_no', $sql_chq_date, $amt, $amt,
                     $sql_bank_code, $sql_bank_name, $sql_branch_code, $sql_branch_name,
                     '$date', 'pending')");
        }
    }

    echo json_encode(['success'=>true,'payment_id'=>(int)$new_id,'new_total'=>$new_total,'fully_settled'=>$fully,'charge_total'=>$charge_total]);
    exit;
}

/* ══════════════════════════════════════════════════════
   NORMAL PAGE
══════════════════════════════════════════════════════ */
include 'config.php';
include 'header.php';

/* Banks list for payment modal */
$banks_list = [];
$br = mysqli_query($conn,"SELECT id,bank_code,bank_name FROM banks WHERE active=1 ORDER BY bank_name");
if ($br) while ($b = mysqli_fetch_assoc($br)) $banks_list[] = $b;

/* ── Ensure table exists (original schema, without new cols) ── */
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

/* ── Add settlement columns one-by-one (safe if already exist) ── */
$_settle_cols = [
    'rc_settled'          => 'TINYINT(1) DEFAULT 0',
    'rc_settlement_amount'=> 'DECIMAL(12,2) DEFAULT 0.00',
    'rc_settlement_date'  => 'DATE NULL',
    'rc_settlement_note'  => 'TEXT NULL',
];
foreach ($_settle_cols as $_col => $_def) {
    $__c = mysqli_query($conn, "SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cheque_return_charges' AND COLUMN_NAME = '$_col'");
    $__row = $__c ? mysqli_fetch_assoc($__c) : null;
    if (!$__row || (int)$__row['n'] === 0) {
        mysqli_query($conn, "ALTER TABLE cheque_return_charges ADD COLUMN $_col $_def");
    }
}
unset($_settle_cols, $_col, $_def, $__c, $__row);

/* ── Summary (columns guaranteed to exist now) ── */
$summary_r = @mysqli_query($conn,"SELECT
    COUNT(*) AS total_cnt,
    COALESCE(SUM(return_charge),0) AS total_amt,
    COUNT(CASE WHEN COALESCE(rc_settled,0)=1 THEN 1 END) AS settled_cnt,
    COALESCE(SUM(CASE WHEN COALESCE(rc_settled,0)=1 THEN return_charge ELSE 0 END),0) AS settled_amt,
    COUNT(CASE WHEN COALESCE(rc_settled,0)=0 THEN 1 END) AS unsettled_cnt,
    COALESCE(SUM(CASE WHEN COALESCE(rc_settled,0)=0 THEN return_charge ELSE 0 END),0) AS unsettled_amt
    FROM cheque_return_charges");
$summary = $summary_r ? mysqli_fetch_assoc($summary_r)
    : ['total_cnt'=>0,'total_amt'=>0,'settled_cnt'=>0,'settled_amt'=>0,'unsettled_cnt'=>0,'unsettled_amt'=>0];
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box}
.page-title{font-size:22px;font-weight:800;color:#1e1b4b;margin:0 0 4px}
.page-subtitle{font-size:13px;color:#6b7280;margin:0}
.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:0;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.05);overflow:hidden}
.filter-header{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;cursor:pointer;user-select:none;background:#fafafa;border-bottom:1px solid transparent;transition:border-color .2s}
.filter-header.open{border-bottom-color:#e5e5e5}
.filter-header:hover{background:#f3f4f6}
.filter-title{font-size:13px;font-weight:700;color:#374151;display:flex;align-items:center;gap:6px;margin:0}
.filter-toggle-icon{color:#6b7280;transition:transform .25s;font-size:12px}
.filter-toggle-icon.open{transform:rotate(180deg)}
.filter-body{display:none;padding:16px 18px 18px}
.filter-body.open{display:block}
.filter-row{display:grid;grid-template-columns:1fr 1fr 1fr 1fr auto;gap:10px;align-items:end}
.ffg{display:flex;flex-direction:column;gap:5px}
.ffg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em}
.ffg input,.ffg select{border:1px solid #e5e5e5;border-radius:7px;padding:8px 11px;font-size:13px;font-family:inherit;color:#1f2937;width:100%;transition:border .2s}
.ffg input:focus,.ffg select:focus{outline:none;border-color:#dc2626;box-shadow:0 0 0 3px rgba(220,38,38,.1)}
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s;white-space:nowrap}
.btn-primary{background:#dc2626;color:#fff}.btn-primary:hover{background:#b91c1c}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover{background:#e8e8e8}
.btn-success{background:#16a34a;color:#fff}.btn-success:hover{background:#15803d}
.btn-sm{padding:5px 12px;font-size:11px}
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.04);cursor:pointer;transition:all .15s}
.stat-card:hover{border-color:#dc2626;box-shadow:0 2px 8px rgba(220,38,38,.15)}
.stat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
.stat-value{font-size:19px;font-weight:800;color:#1f2937;line-height:1}
.stat-card-amt{font-size:10px;color:#9ca3af;margin-top:3px}
.sv-red{color:#dc2626}.sv-green{color:#16a34a}.sv-amber{color:#d97706}
.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #f0f0f0;background:#fafafa;flex-wrap:wrap;gap:8px}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap}
.p-red{background:#fee2e2;color:#991b1b}
.chq-search-box{position:relative;display:flex;align-items:center}
.chq-search-box input{border:1.5px solid #fecaca;border-radius:8px;padding:7px 12px 7px 34px;font-size:12.5px;font-family:inherit;color:#1f2937;width:260px;transition:all .2s;background:#fff;outline:none}
.chq-search-box input:focus{border-color:#dc2626;box-shadow:0 0 0 3px rgba(220,38,38,.1);width:310px}
.chq-search-box .si{position:absolute;left:10px;color:#9ca3af;font-size:12px;pointer-events:none}
.chq-search-box .clr-btn{position:absolute;right:8px;color:#9ca3af;font-size:11px;background:none;border:none;cursor:pointer;padding:2px;display:none}
.chq-search-box .clr-btn.show{display:block}
.pager{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.pager-btn{background:#fff;border:1.5px solid #fecaca;border-radius:6px;padding:4px 10px;font-size:11.5px;font-weight:600;color:#dc2626;cursor:pointer;transition:all .15s;white-space:nowrap;font-family:inherit}
.pager-btn:hover{background:#fee2e2;border-color:#f87171}
.pager-btn.active{background:#dc2626;color:#fff;border-color:#dc2626}
.pager-btn:disabled{opacity:.4;cursor:not-allowed}
.pager-info{font-size:11px;color:#6b7280;white-space:nowrap}
.dt-outer{overflow-x:auto;max-height:70vh;overflow-y:auto}
.data-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1000px}
.data-table thead th{padding:9px 8px;text-align:left;font-weight:700;font-size:10.5px;color:#fff;background:#7f1d1d;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);position:sticky;top:0;z-index:10}
.data-table thead th:last-child{border-right:none}
.data-table thead th.tr{text-align:right}.data-table thead th.tc{text-align:center}
.data-table tbody tr{border-bottom:1px solid #f0f2f5;transition:background .12s}
.data-table tbody tr:hover td{background:#fff5f5!important}
.data-table td{padding:7px 8px;color:#374151;vertical-align:middle;background:#fff}
.data-table tfoot td{padding:10px 8px;font-weight:800;font-size:12px;background:#450a0a;color:#fca5a5;border-top:2px solid #7f1d1d;position:sticky;bottom:0}
.data-table tfoot td.tr{text-align:right}
.tr{text-align:right}.tc{text-align:center}
.mono{font-family:'Courier New',monospace;font-weight:700;letter-spacing:.02em}
.amt-cell{font-weight:700;color:#dc2626;white-space:nowrap}
.charge-cell{font-weight:800;color:#dc2626;font-size:13px;white-space:nowrap}
.cust-sub{font-size:10px;color:#6b7280;margin-top:2px}
.date-txt{font-size:11.5px;color:#374151;white-space:nowrap}
.date-txt.empty{color:#d1d5db}
.settled-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:700;white-space:nowrap}
.settled-badge.yes{background:#dcfce7;color:#166534;border:1px solid #86efac}
.settled-badge.no{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.settle-meta{font-size:10px;margin-top:3px;line-height:1.4}
.reason-badge{display:inline-block;background:#fff1f2;border:1px solid #fecaca;color:#991b1b;border-radius:4px;padding:2px 7px;font-size:10px;margin-top:3px;font-style:italic;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.btn-loadpay{display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;border:none;border-radius:6px;padding:5px 11px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;transition:filter .2s}
.btn-loadpay:hover{filter:brightness(1.1)}
.btn-loadpay.settled{background:linear-gradient(135deg,#16a34a,#22c55e)}
.btn-loadpay.settled:hover{filter:brightness(1.05)}
.tbl-loading{display:none;position:absolute;inset:0;background:rgba(255,255,255,.8);z-index:50;align-items:center;justify-content:center;flex-direction:column;gap:10px;font-size:13px;color:#dc2626;font-weight:600;border-radius:10px}
.tbl-loading.show{display:flex}
.tbl-wrap{position:relative}
.tbl-spinner{width:36px;height:36px;border:4px solid #fecaca;border-top-color:#dc2626;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.state-box{text-align:center;padding:80px 20px;color:#9ca3af}
.state-box i{font-size:48px;display:block;margin-bottom:16px;opacity:.3}
.state-box p{font-size:14px;font-weight:500}
.modal-backdrop{position:fixed!important;inset:0!important;z-index:2147483647!important;background:rgba(0,0,0,.6);display:none;align-items:center;justify-content:center;padding:16px}
.modal-backdrop.open{display:flex!important}
body.modal-rc-open{overflow:hidden!important}
.modal-dialog{background:#fff;border-radius:14px;width:100%;max-width:1000px;max-height:94vh;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.3);overflow:hidden}
.modal-header{display:flex;align-items:flex-start;justify-content:space-between;padding:16px 22px;border-bottom:1px solid #e5e5e5;background:#fff5f5;flex-shrink:0}
.modal-header-left{display:flex;flex-direction:column;gap:4px;flex:1}
.modal-header-left h3{font-size:17px;font-weight:700;color:#991b1b;margin:0}
.inv-summary-strip{display:flex;gap:0;flex-wrap:wrap;margin-top:10px;border:1px solid #fecaca;border-radius:8px;overflow:hidden}
.inv-sum-item{flex:1;display:flex;flex-direction:column;padding:10px 16px;border-right:1px solid #fecaca;min-width:100px}
.inv-sum-item:last-child{border-right:none}
.inv-sum-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
.inv-sum-value{font-size:16px;font-weight:800;color:#1f2937}
.inv-sum-value.green{color:#166534}.inv-sum-value.red{color:#dc2626}
.modal-close{width:32px;height:32px;border-radius:7px;border:1px solid #fecaca;background:#fff;color:#dc2626;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0;margin-left:12px}
.modal-close:hover{background:#fee2e2}
.modal-body{overflow-y:auto;flex:1;padding:0}
.pay-section-wrap{padding:20px 22px}
.pay-block{margin-bottom:20px}
.pay-block-title{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;padding:10px 14px;border-radius:7px;margin-bottom:14px;display:flex;align-items:center;gap:7px}
.pay-block-title.cash{background:#dcfce7;color:#166534;border-left:4px solid #22c55e}
.pay-block-title.cheque{background:#dbeafe;color:#1e40af;border-left:4px solid #3b82f6}
.pay-block-title.info{background:#fee2e2;color:#991b1b;border-left:4px solid #dc2626}
.fg{display:flex;flex-direction:column;gap:5px}
.fg label{font-size:12px;font-weight:600;color:#374151}
.fg label .req{color:#ef4444;margin-left:2px}
.fctrl{padding:8px 10px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;font-family:inherit;color:#333;background:#fff;width:100%;box-sizing:border-box;outline:none;transition:border-color .2s}
.fctrl:focus{border-color:#dc2626}
select.fctrl{cursor:pointer}
.gr{display:grid;gap:12px;margin-bottom:12px}
.gr2{grid-template-columns:1fr 1fr}.gr3{grid-template-columns:1fr 1fr 1fr}.gr4{grid-template-columns:1fr 1fr 1fr 1fr}
.pay-divider{text-align:center;position:relative;margin:18px 0}
.pay-divider::before{content:'';position:absolute;top:50%;left:0;right:0;height:1px;background:#fecaca}
.pay-divider span{position:relative;background:#fff;padding:0 12px;font-size:11px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.07em}
.cheque-card{background:#f8f7ff;border:1px solid #ddd6fe;border-radius:8px;padding:14px;margin-bottom:12px}
.cheque-card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.cheque-card-title{font-size:12px;font-weight:700;color:#5b21b6;display:flex;align-items:center;gap:6px}
.btn-remove-cheque{background:#ef4444;color:#fff;border:none;padding:3px 9px;border-radius:4px;font-size:11px;cursor:pointer;display:inline-flex;align-items:center;gap:3px;font-family:inherit}
.btn-remove-cheque:hover{background:#dc2626}
.btn-add-cheque{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:1.5px dashed #7c3aed;border-radius:6px;background:#faf5ff;color:#7c3aed;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .2s}
.btn-add-cheque:hover{background:#f3e8ff}
.dup-cheque-warn{background:#fef2f2;border:1px solid #fecaca;border-radius:5px;padding:5px 9px;font-size:11px;color:#991b1b;display:none;margin-top:4px}
.dup-cheque-warn.show{display:block}
.cust-info-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;background:#fff5f5;border:1px solid #fecaca;border-radius:8px;padding:12px;margin-bottom:14px}
.ci-item{text-align:center}
.ci-label{font-size:10px;font-weight:600;color:#dc2626;text-transform:uppercase;letter-spacing:.05em}
.ci-value{font-size:15px;font-weight:700;color:#991b1b;margin-top:2px}
.spm-block{background:#fff;border:1.5px solid #e0e7ef;border-radius:10px;margin-bottom:14px;overflow:hidden}
.spm-header{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#f0f4ff;border-bottom:1px solid #e0e7ef}
.spm-title{font-size:13px;font-weight:700;color:#1e3a8a;display:flex;align-items:center;gap:6px}
.spm-table{width:100%;border-collapse:collapse;font-size:12px}
.spm-table th{background:#f8fafc;color:#6b7280;font-weight:600;padding:7px 10px;text-align:left;border-bottom:1px solid #e5e7eb}
.spm-table td{padding:7px 10px;border-bottom:1px solid #f1f5f9;color:#374151;vertical-align:middle}
.spm-table tr:last-child td{border-bottom:none}
.spm-table tr:hover td{background:#f8fafc}
.spm-empty{padding:14px;text-align:center;color:#9ca3af;font-size:12px}
.spm-total-row td{font-weight:700;background:#f0fdf4;color:#166534;border-top:2px solid #bbf7d0}
.btn-del-pay{background:none;border:1px solid #fca5a5;color:#dc2626;border-radius:5px;padding:3px 8px;font-size:11px;cursor:pointer;transition:.15s}
.btn-del-pay:hover{background:#fee2e2}
.spm-method-cash{background:#dcfce7;color:#166534;border-radius:4px;padding:2px 7px;font-size:10px;font-weight:700}
.spm-method-cheque{background:#dbeafe;color:#1d4ed8;border-radius:4px;padding:2px 7px;font-size:10px;font-weight:700}
.spm-method-other{background:#f3f4f6;color:#374151;border-radius:4px;padding:2px 7px;font-size:10px;font-weight:700}
.bal-summary{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;margin-top:18px}
.bal-sum-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;font-size:13px;color:#374151;border-bottom:1px solid #f0f0f0}
.bal-sum-row:last-child{border-bottom:none}
.bal-sum-row.total{font-weight:700;color:#1f2937;padding-top:8px;margin-top:4px;border-top:2px solid #e2e8f0;border-bottom:none}
.bal-sum-row.balance strong{color:#dc2626;font-size:15px}
.charge-banner{background:linear-gradient(135deg,#fee2e2,#fef2f2);border:1.5px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.cb-item{display:flex;flex-direction:column;gap:2px}
.cb-lbl{font-size:10px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.05em}
.cb-val{font-size:13px;font-weight:700;color:#7f1d1d}
.modal-footer{padding:13px 22px;border-top:1px solid #fecaca;background:#fff5f5;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-shrink:0}
.modal-footer-right{display:flex;gap:8px}
.btn-modal-cancel{padding:8px 16px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#555;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer}
.btn-modal-cancel:hover{background:#f5f5f5}
.btn-modal-settle{padding:9px 20px;border-radius:6px;border:none;background:#dc2626;color:#fff;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;display:flex;align-items:center;gap:6px}
.btn-modal-settle:hover{background:#b91c1c}
.btn-modal-settle:disabled{opacity:.55;cursor:not-allowed}
.spinner{border:3px solid rgba(255,255,255,.3);border-top:3px solid #fff;border-radius:50%;width:16px;height:16px;animation:spin 1s linear infinite;display:inline-block;vertical-align:middle}
#rcToast{position:fixed;bottom:28px;right:28px;z-index:99999;padding:12px 22px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 6px 24px rgba(0,0,0,.2);color:#fff;transform:translateY(80px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none}
#rcToast.show{transform:translateY(0);opacity:1}
.select2-container--default .select2-selection--single{height:37px!important;border:1px solid #e0e0e0!important;border-radius:6px!important}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:35px!important;padding-left:10px!important;font-size:13px!important;color:#333!important;font-family:inherit!important}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:35px!important}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:#dc2626!important}
.select2-dropdown{border:1px solid #e0e0e0!important;border-radius:6px!important;font-size:13px!important}
.select2-results__option--highlighted{background:#dc2626!important}
@media(max-width:1000px){.stat-grid{grid-template-columns:1fr 1fr}}
@media(max-width:640px){.filter-row{grid-template-columns:1fr 1fr}.gr2,.gr3,.gr4{grid-template-columns:1fr}}
@media print{.no-print{display:none!important}.data-table thead th{background:#7f1d1d!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}.data-table tfoot td{background:#450a0a!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}}

.rchq-card{background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:8px;padding:10px 12px;min-width:200px}
.rchq-no{font-family:'Courier New',monospace;font-weight:800;font-size:13px;color:#1e40af;letter-spacing:.04em;display:flex;align-items:center;gap:5px;margin-bottom:6px}
.rchq-grid{display:grid;grid-template-columns:1fr 1fr;gap:4px 10px}
.rchq-row{display:flex;flex-direction:column;gap:1px}
.rchq-lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#3b82f6}
.rchq-val{font-size:11px;font-weight:600;color:#1e3a5f}

.collector-type-row{display:flex;align-items:center;gap:10px;margin-bottom:14px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px}
.collector-type-label{font-size:12px;font-weight:700;color:#374151;white-space:nowrap}
.collector-type-btns{display:flex;gap:6px}
.ctype-btn{padding:6px 18px;border-radius:6px;border:1.5px solid #e0e0e0;background:#fff;font-size:12px;font-weight:700;font-family:inherit;cursor:pointer;color:#6b7280;transition:all .2s}
.ctype-btn:hover{border-color:#dc2626;color:#991b1b}
.ctype-btn.active-cc{border-color:#0ea5e9;background:#e0f2fe;color:#0369a1}
.ctype-btn.active-sr{border-color:#7c3aed;background:#ede9fe;color:#5b21b6}
.dp-modal-status{font-size:10px;color:#9ca3af;min-height:14px;display:block;margin-top:2px;line-height:1.3}
.dp-modal-status.ok{color:#22c55e;font-weight:700}
.dp-modal-status.err{color:#e53935}
</style>

<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;" class="no-print">
  <div>
    <?php
      $url_from = trim($_GET['date_from'] ?? '');
      $url_to   = trim($_GET['date_to']   ?? '');
      $url_sr   = trim($_GET['sr_code']   ?? '');
      if($url_from || $url_to):
    ?>
    <a href="daily_cash_summary.php?date_from=<?=htmlspecialchars($url_from)?>&date_to=<?=htmlspecialchars($url_to)?><?=$url_sr?'&sr_code='.htmlspecialchars($url_sr):''?>&search=1" 
       style="font-size:12px;color:#dc2626;text-decoration:none;display:inline-flex;align-items:center;gap:4px;margin-bottom:6px;">
      <i class="fa-solid fa-arrow-left"></i> Back to Daily Cash Summary
    </a>
    <?php endif; ?>
    <h2 class="page-title"><i class="fa-solid fa-circle-minus" style="color:#dc2626;"></i> Return Charges</h2>
    <p class="page-subtitle">All cheque return charges (Rs. 250 per returned cheque) — use <strong>Load Pay</strong> to settle.</p>
  </div>
  <div style="display:flex;gap:8px;" class="no-print">
    <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</button>
    <button onclick="exportCSV()" class="btn btn-success btn-sm"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
  </div>
</div>

<!-- FILTERS -->
<div class="filter-card no-print">
  <div class="filter-header" id="filterHeader" onclick="toggleFilter()">
    <div class="filter-title"><i class="fa-solid fa-sliders" style="color:#dc2626;"></i> Filters</div>
    <i class="fa-solid fa-chevron-down filter-toggle-icon" id="filterIcon"></i>
  </div>
  <div class="filter-body" id="filterBody">
    <div class="filter-row">
      <div class="ffg">
        <label><i class="fa-solid fa-user-tag"></i> T-Code</label>
        <input type="text" id="fTcode" placeholder="Search T-Code...">
      </div>
      <div class="ffg">
        <label><i class="fa-solid fa-calendar-day"></i> Charged Date From</label>
        <input type="date" id="fFrom">
      </div>
      <div class="ffg">
        <label><i class="fa-solid fa-calendar-day"></i> Charged Date To</label>
        <input type="date" id="fTo">
      </div>
      <div class="ffg">
        <label><i class="fa-solid fa-circle-half-stroke"></i> Settlement Status</label>
        <select id="selSettled" style="width:100%;">
          <option value="">— All —</option>
          <option value="0">Unsettled Only</option>
          <option value="1">Settled Only</option>
        </select>
      </div>
      <div class="ffg" style="flex-direction:row;gap:8px;align-items:flex-end;">
        <button type="button" class="btn btn-primary" onclick="applyFilters()" style="flex:1;">
          <i class="fa-solid fa-magnifying-glass"></i> Search
        </button>
        <button type="button" class="btn btn-secondary" onclick="clearFilters()" title="Clear"><i class="fa-solid fa-rotate-left"></i></button>
      </div>
    </div>
  </div>
</div>

<!-- STAT CARDS -->
<div class="stat-grid">
  <div class="stat-card" onclick="clearFilters()">
    <div class="stat-label"><i class="fa-solid fa-circle-minus" style="color:#dc2626;"></i> Total Charges</div>
    <div class="stat-value sv-red"><?=$summary['total_cnt']?></div>
    <div class="stat-card-amt">Rs.&nbsp;<?=number_format($summary['total_amt'],0)?></div>
  </div>
  <div class="stat-card" onclick="filterBySettled('0')">
    <div class="stat-label"><i class="fa-solid fa-hourglass-half" style="color:#d97706;"></i> Unsettled</div>
    <div class="stat-value sv-amber"><?=$summary['unsettled_cnt']?></div>
    <div class="stat-card-amt">Rs.&nbsp;<?=number_format($summary['unsettled_amt'],0)?></div>
  </div>
  <div class="stat-card" onclick="filterBySettled('1')">
    <div class="stat-label"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Settled</div>
    <div class="stat-value sv-green"><?=$summary['settled_cnt']?></div>
    <div class="stat-card-amt">Rs.&nbsp;<?=number_format($summary['settled_amt'],0)?></div>
  </div>
  <div class="stat-card" style="border-color:#dc2626;background:#fff5f5;">
    <div class="stat-label" style="color:#991b1b;">Filtered Results</div>
    <div class="stat-value sv-red" id="filteredCount">0</div>
    <div class="stat-card-amt" id="filteredAmt">Rs. 0</div>
  </div>
</div>

<!-- TABLE CARD -->
<div class="table-card tbl-wrap">
  <div class="tbl-loading" id="tblLoading">
    <div class="tbl-spinner"></div>
    <span>Loading return charges…</span>
  </div>
  <div class="table-toolbar no-print">
    <div class="tbl-title">
      <i class="fa-solid fa-table-list"></i> Return Charges
      <span class="pill p-red" id="visCount">0 records</span>
    </div>
    <div class="chq-search-box">
      <i class="fa-solid fa-magnifying-glass si"></i>
      <input type="text" id="rcSearch" placeholder="Search cheque no, customer, t-code…" autocomplete="off" spellcheck="false">
      <button class="clr-btn" id="rcClr" onclick="clearSearch()" title="Clear"><i class="fa-solid fa-xmark"></i></button>
    </div>
  </div>
  <div class="table-toolbar no-print" id="pagerWrap" style="justify-content:space-between;padding:7px 14px;display:none;">
    <div class="pager" id="pager"></div>
    <span class="pager-info" id="pagerInfo"></span>
  </div>
  <div class="dt-outer">
    <table class="data-table" id="mainTable">
      <thead>
        <tr>
          <th style="width:32px;">#</th>
          <th>Cheque No.</th>
          <th class="tc">Cheque Date</th>
          <th>Bank</th>
          <th>T-Code / Customer</th>
          <th class="tr">Cheque Amt</th>
          <th class="tr">Return Charge</th>
          <th>Return Reason</th>
          <th class="tc">Charged At</th>
          <th class="tc">Settlement</th>
          <th class="tc no-print">Action</th>
        </tr>
      </thead>
      <tbody id="mainTbody">
        <tr><td colspan="11" style="text-align:center;padding:60px 20px;color:#9ca3af;">
          <i class="fa-solid fa-spinner fa-spin" style="font-size:32px;display:block;margin-bottom:12px;opacity:.5;"></i>
          <p>Loading return charges…</p>
        </td></tr>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="6" class="no-print"></td>
          <td class="tr">Rs.&nbsp;<span id="footerTotal">0.00</span></td>
          <td colspan="4" style="font-size:11px;opacity:.65;">TOTAL CHARGES — <span id="footerCount">0</span> RECORDS</td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<!-- SETTLEMENT MODAL -->
<div class="modal-backdrop" id="settleModal">
<div class="modal-dialog">
  <div class="modal-header">
    <div class="modal-header-left">
      <h3><i class="fa-solid fa-circle-minus" style="color:#dc2626;margin-right:4px;"></i> Settle Return Charge</h3>
      <p id="modalSubtitle" style="font-size:12px;color:#991b1b;margin:0;">—</p>
      <div class="inv-summary-strip">
        <div class="inv-sum-item"><span class="inv-sum-label"><i class="fa-solid fa-circle-minus"></i> Return Charge</span><span class="inv-sum-value red" id="hdrCharge">—</span></div>
        <div class="inv-sum-item"><span class="inv-sum-label"><i class="fa-solid fa-circle-check"></i> Total Paid</span><span class="inv-sum-value green" id="hdrPaid">—</span></div>
        <div class="inv-sum-item"><span class="inv-sum-label"><i class="fa-solid fa-hourglass-half"></i> Remaining</span><span class="inv-sum-value red" id="hdrBal">—</span></div>
        <div class="inv-sum-item"><span class="inv-sum-label"><i class="fa-solid fa-money-check"></i> Cheque Amount</span><span class="inv-sum-value" id="hdrChequeAmt">—</span></div>
      </div>
    </div>
    <button class="modal-close" onclick="closeSettleModal()"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="modal-body">
    <div class="pay-section-wrap">

      <!-- Info banner -->
      <div class="charge-banner">
        <div class="cb-item"><span class="cb-lbl">Cheque No.</span><span class="cb-val" id="bnrChequeNo">—</span></div>
        <div class="cb-item"><span class="cb-lbl">Cheque Date</span><span class="cb-val" id="bnrChequeDate">—</span></div>
        <div class="cb-item"><span class="cb-lbl">Bank</span><span class="cb-val" id="bnrBank">—</span></div>
        <div class="cb-item"><span class="cb-lbl">Return Charge</span><span class="cb-val" id="bnrCharge">—</span></div>
      </div>

      <!-- COLLECTOR DETAILS -->
      <div class="pay-block">
        <div class="pay-block-title cash" style="background:#f0f9ff;color:#0369a1;border-left-color:#0ea5e9;">
          <i class="fa-solid fa-person-biking"></i> Collector Details
        </div>
        <div class="collector-type-row">
          <span class="collector-type-label"><i class="fa-solid fa-user-tag"></i> Collected By:</span>
          <div class="collector-type-btns">
            <button type="button" class="ctype-btn active-cc" id="rcCtypeCC" onclick="rcSetCollectorType('cc')">
              <i class="fa-solid fa-person-biking"></i> CC — Cash Collector
            </button>
            <button type="button" class="ctype-btn" id="rcCtypeSR" onclick="rcSetCollectorType('sr')">
              <i class="fa-solid fa-id-badge"></i> SR — Sales Rep
            </button>
          </div>
        </div>
        <!-- CC mode -->
        <div id="rcDpWrap" class="gr gr2" style="margin-bottom:0;">
          <div class="fg">
            <label>Delivery Person <span class="req">*</span></label>
            <select class="fctrl" id="rcDpSelect">
              <option value="">-- Select Delivery Person --</option>
            </select>
            <span class="dp-modal-status" id="rcDpStatus"></span>
          </div>
          <div class="fg">
            <label>Employee <span style="font-size:10px;font-weight:400;color:#9ca3af;">(optional)</span></label>
            <select id="rcEmpSelect">
              <option value="">-- Select Employee --</option>
            </select>
          </div>
        </div>
        <!-- SR mode -->
        <div id="rcSrWrap" style="display:none;" class="gr gr2">
          <div class="fg">
            <label>SR Code <span class="req">*</span></label>
            <select class="fctrl" id="rcSrSelect">
              <option value="">-- Select SR Code --</option>
            </select>
          </div>
          <div class="fg">
            <label>Employee <span style="font-size:10px;font-weight:400;color:#9ca3af;">(optional)</span></label>
            <select id="rcSrEmpSelect">
              <option value="">-- Select Employee --</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Cash block -->
      <div class="pay-block">
        <div class="pay-block-title cash"><i class="fa-solid fa-coins"></i> Cash Payment</div>
        <div class="gr gr4">
          <div class="fg"><label>Payment Date</label><input type="date" class="fctrl" id="cashDate"></div>
          <div class="fg"><label>Cash Amount (Rs.)</label><input type="number" class="fctrl" id="cashAmount" step="0.01" min="0" placeholder="0.00" oninput="syncPreviews()"></div>
          <div class="fg"><label>Amount to Bank (Rs.)</label><input type="number" class="fctrl" id="cashToBank" step="0.01" min="0" placeholder="0.00"></div>
          <div class="fg"><label>Reference No.</label><input type="text" class="fctrl" id="cashRef" placeholder="Optional"></div>
        </div>
        <div class="gr gr2">
          <div class="fg">
            <label>Collected By</label>
            <select class="fctrl" id="cashCollectedBy">
              <option value="cc" selected>CC — Cash Collector</option>
              <option value="sr">SR — Sales Rep</option>
              <option value="area_manager">Area Manager</option>
              <option value="office">Office</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="fg"><label>Remarks</label><input type="text" class="fctrl" id="cashRemarks" placeholder="Notes..."></div>
        </div>
      </div>

      <div class="pay-divider"><span>+ Cheque Payment (optional)</span></div>

      <!-- Cheque block -->
      <div class="pay-block">
        <div class="pay-block-title cheque"><i class="fa-solid fa-money-check"></i> New Cheque Payment</div>
        <div class="cust-info-strip">
          <div class="ci-item"><div class="ci-label">Credit Limit</div><div class="ci-value" id="chqLimit">—</div></div>
          <div class="ci-item"><div class="ci-label">Policy Days</div><div class="ci-value" id="chqDays">—</div></div>
          <div class="ci-item"><div class="ci-label">Special Days</div><div class="ci-value" id="chqSpecial">—</div></div>
        </div>
        <div class="gr gr3">
          <div class="fg"><label>Cheque Payment Date</label><input type="date" class="fctrl" id="chqPayDate"></div>
          <div class="fg"><label>Reference No.</label><input type="text" class="fctrl" id="chqRef" placeholder="Optional"></div>
          <div class="fg"><label>Cheque Mode</label>
            <select class="fctrl" id="chqModeSelect">
              <option value="payee_only">Payee Only</option>
              <option value="cash">Cash</option>
              <option value="third_party_cash">Third Party Cash</option>
            </select>
          </div>
        </div>
        <div id="chequesContainer"></div>
        <button type="button" class="btn-add-cheque" onclick="addCheque()"><i class="fa-solid fa-plus"></i> Add Cheque</button>
        <div class="fg" style="margin-top:10px;"><label>Remarks</label><input type="text" class="fctrl" id="chqRemarks" placeholder="Notes..."></div>
      </div>

      <!-- Settlement note -->
      <div class="pay-block" style="margin-bottom:10px;">
        <div class="pay-block-title info"><i class="fa-solid fa-circle-minus"></i> Settlement Note</div>
        <div class="fg">
          <label>Note (optional)</label>
          <input type="text" class="fctrl" id="settlementNote" placeholder="e.g. Customer paid charge at office…">
        </div>
      </div>

      <!-- Payments list -->
      <div class="spm-block" id="spmBlock">
        <div class="spm-header">
          <span class="spm-title"><i class="fa-solid fa-list-check"></i> Settlement Payments</span>
          <span id="spmTotalBadge" style="font-size:12px;color:#166534;font-weight:700;"></span>
        </div>
        <div id="spmTableWrap">
          <div class="spm-empty"><i class="fa-solid fa-circle-info"></i> No payments recorded yet.</div>
        </div>
      </div>

      <!-- Balance summary -->
      <div class="bal-summary">
        <div class="bal-sum-row"><span><i class="fa-solid fa-coins" style="color:#22c55e;"></i> Cash entering</span><strong id="sumCash">Rs. 0.00</strong></div>
        <div class="bal-sum-row"><span><i class="fa-solid fa-money-check" style="color:#3b82f6;"></i> Cheque total</span><strong id="sumCheque">Rs. 0.00</strong></div>
        <div class="bal-sum-row total"><span><i class="fa-solid fa-sigma"></i> Total payment</span><strong id="sumTotal">Rs. 0.00</strong></div>
        <div class="bal-sum-row balance"><span><i class="fa-solid fa-hourglass-half"></i> Remaining after this</span><strong id="sumBalance">Rs. —</strong></div>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <div style="font-size:11px;color:#9ca3af;"><i class="fa-solid fa-info-circle"></i> Payment recorded against return charge.</div>
    <div class="modal-footer-right">
      <button class="btn-modal-cancel" onclick="closeSettleModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="btn-modal-settle" id="submitSettleBtn" onclick="submitSettlement()"><i class="fa-solid fa-circle-check"></i> Save Payment</button>
    </div>
  </div>
</div>
</div>

<div id="rcToast"></div>

<script>
const BANKS = <?php echo json_encode($banks_list); ?>;

$(function(){
    $('#selSettled').select2({placeholder:'— All —',allowClear:true,width:'100%'});
});

/* ── filters ── */
function toggleFilter(){
    const h=document.getElementById('filterHeader'),b=document.getElementById('filterBody'),i=document.getElementById('filterIcon');
    const open=b.classList.contains('open');
    b.classList.toggle('open',!open);h.classList.toggle('open',!open);i.classList.toggle('open',!open);
}
function filterBySettled(val){
    $('#selSettled').val(val).trigger('change');
    currentPage=1;fetchRows();
}
function applyFilters(){currentPage=1;fetchRows();}
function clearFilters(){
    document.getElementById('fTcode').value='';
    document.getElementById('fFrom').value='';
    document.getElementById('fTo').value='';
    $('#selSettled').val('').trigger('change');
    currentPage=1;currentSearch='';
    const s=document.getElementById('rcSearch');if(s)s.value='';
    document.getElementById('rcClr')?.classList.remove('show');
    fetchRows();
}

const PAGE_SIZE=50;
let currentPage=1,currentSearch='',fetchTimer=null,lastController=null;

function getFilters(){
    return{
        t_code:   document.getElementById('fTcode')?.value    ||'',
        date_from:document.getElementById('fFrom')?.value     ||'',
        date_to:  document.getElementById('fTo')?.value       ||'',
        settled:  document.getElementById('selSettled')?.value||'',
        q:        currentSearch,
        page:     currentPage,
        per:      PAGE_SIZE,
    };
}

async function fetchRows(){
    if(lastController) lastController.abort();
    lastController=new AbortController();
    showLoading(true);
    const params=new URLSearchParams({ajax:'rc_rows',...getFilters()});
    try{
        const res=await fetch('return_charges.php?'+params,{signal:lastController.signal});
        if(!res.ok) throw new Error('HTTP '+res.status);
        const data=await res.json();
        if(!data.success) throw new Error(data.error||'Server error');
        renderRows(data);
    }catch(e){
        if(e.name==='AbortError') return;
        document.getElementById('mainTbody').innerHTML=`<tr><td colspan="11" style="text-align:center;padding:40px;color:#dc2626;"><i class="fa-solid fa-triangle-exclamation"></i> Error: ${esc(e.message)}</td></tr>`;
    }finally{showLoading(false);}
}

function showLoading(on){document.getElementById('tblLoading')?.classList.toggle('show',on);}

function fmtDate(d){
    if(!d||d==='0000-00-00'||d==='0000-00-00 00:00:00') return '<span class="date-txt empty">—</span>';
    const dt=new Date(d.replace(' ','T'));
    const m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return `<span class="date-txt">${String(dt.getDate()).padStart(2,'0')} ${m[dt.getMonth()]} ${dt.getFullYear()}</span>`;
}

function renderRows(data){
    const tbody=document.getElementById('mainTbody');
    const rows=data.rows||[],total=data.total||0,pages=data.pages||1,g_amt=data.grand_total||0;
    const offset=(currentPage-1)*PAGE_SIZE;

    document.getElementById('visCount').textContent=total+' records';
    document.getElementById('footerCount').textContent=total;
    document.getElementById('footerTotal').textContent=g_amt.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('filteredCount').textContent=total;
    document.getElementById('filteredAmt').textContent='Rs. '+g_amt.toLocaleString('en-US',{minimumFractionDigits:0,maximumFractionDigits:0});

    if(!rows.length){
        tbody.innerHTML=`<tr><td colspan="11"><div class="state-box"><i class="fa-solid fa-inbox"></i><p>No return charges found.</p></div></td></tr>`;
        document.getElementById('pagerWrap').style.display='none';
        return;
    }

    let html='';
    rows.forEach((row,idx)=>{
        const rn=offset+idx+1;
        const settled=parseInt(row.rc_settled||0);
        const charge=parseFloat(row.return_charge||0);
        const paid=parseFloat(row.rc_settlement_amount||0);
        const remain=Math.max(0,charge-paid);
        const id=row.id;

        let settleBadge,actionBtn;
        if(settled){
            settleBadge=`<span class="settled-badge yes"><i class="fa-solid fa-circle-check"></i> Fully Settled</span>`
                       +`<div class="settle-meta" style="color:#166534;">Paid: Rs.${paid.toFixed(2)} &nbsp;|&nbsp; Bal: Rs.0.00</div>`;
            actionBtn=`<button class="btn-loadpay settled" onclick="openSettleModal(${id})"><i class="fa-solid fa-circle-check"></i> View</button>`;
        } else if(paid>0){
            settleBadge=`<span class="settled-badge no" style="background:#fff7ed;color:#c2410c;border-color:#fed7aa;"><i class="fa-solid fa-circle-half-stroke"></i> Partially Settled</span>`
                       +`<div class="settle-meta" style="color:#92400e;">Paid: Rs.${paid.toFixed(2)} &nbsp;|&nbsp; Bal: Rs.${remain.toFixed(2)}</div>`;
            actionBtn=`<button class="btn-loadpay" onclick="openSettleModal(${id})"><i class="fa-solid fa-money-bill-transfer"></i> Load Pay</button>`;
        } else {
            settleBadge=`<span class="settled-badge no"><i class="fa-solid fa-clock"></i> Unsettled</span>`
                       +`<div class="settle-meta" style="color:#9ca3af;">Paid: Rs.0.00 &nbsp;|&nbsp; Bal: Rs.${charge.toFixed(2)}</div>`;
            actionBtn=`<button class="btn-loadpay" onclick="openSettleModal(${id})"><i class="fa-solid fa-money-bill-transfer"></i> Load Pay</button>`;
        }

        const reasonHtml = row.return_reason
            ? `<span class="reason-badge" title="${esc(row.return_reason)}">${esc(row.return_reason)}</span>`
            : '<span style="color:#d1d5db;font-size:10px;">—</span>';

        html+=`
        <tr id="row-${id}" data-id="${id}" data-settled="${settled}">
          <td style="color:#9ca3af;font-size:11px;font-weight:600;">${rn}</td>
          <td><span class="mono" style="color:#991b1b;font-size:12.5px;">${esc(row.cheque_no||'—')}</span></td>
          <td class="tc">${fmtDate(row.cheque_date)}</td>
          <td><div style="font-size:12px;font-weight:600;color:#374151;">${esc(row.bank_name||'—')}</div></td>
          <td>
            <span class="mono" style="font-size:11.5px;color:#4338ca;">${esc(row.t_code||'—')}</span>
            ${row.customer_name?`<div class="cust-sub" title="${esc(row.customer_name)}">${esc(row.customer_name)}</div>`:''}
          </td>
          <td class="tr"><span class="amt-cell">Rs.&nbsp;${parseFloat(row.cheque_amount||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</span></td>
          <td class="tr"><span class="charge-cell">Rs.&nbsp;${charge.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</span></td>
          <td>${reasonHtml}</td>
          <td class="tc">${fmtDate(row.charged_at)}</td>
          <td class="tc">${settleBadge}</td>
          <td class="tc no-print">${actionBtn}</td>
        </tr>`;
    });
    tbody.innerHTML=html;
    buildPager(pages,total,offset,Math.min(offset+PAGE_SIZE,total));
}

function buildPager(pages,total,start,end){
    const pager=document.getElementById('pager'),info=document.getElementById('pagerInfo'),wrap=document.getElementById('pagerWrap');
    if(!pager) return;
    if(pages<=1){wrap.style.display='none';return;}
    wrap.style.display='flex';
    if(info) info.textContent=`Showing ${start+1}–${end} of ${total}`;
    let html=`<button class="pager-btn" onclick="goPage(${currentPage-1})" ${currentPage===1?'disabled':''}>‹ Prev</button>`;
    let lo=Math.max(1,currentPage-3),hi=Math.min(pages,currentPage+3);
    if(lo>1) html+=`<button class="pager-btn" onclick="goPage(1)">1</button>${lo>2?'<span style="color:#9ca3af;padding:0 3px;">…</span>':''}`;
    for(let p=lo;p<=hi;p++) html+=`<button class="pager-btn ${p===currentPage?'active':''}" onclick="goPage(${p})">${p}</button>`;
    if(hi<pages) html+=`${hi<pages-1?'<span style="color:#9ca3af;padding:0 3px;">…</span>':''}<button class="pager-btn" onclick="goPage(${pages})">${pages}</button>`;
    html+=`<button class="pager-btn" onclick="goPage(${currentPage+1})" ${currentPage===pages?'disabled':''}>Next ›</button>`;
    pager.innerHTML=html;
}
function goPage(p){currentPage=p;fetchRows();document.querySelector('.dt-outer')?.scrollTo(0,0);}

const rcInput=document.getElementById('rcSearch');
const rcClr=document.getElementById('rcClr');
if(rcInput){
    rcInput.addEventListener('input',function(){
        currentSearch=this.value.trim();
        rcClr?.classList.toggle('show',currentSearch.length>0);
        clearTimeout(fetchTimer);
        fetchTimer=setTimeout(()=>{currentPage=1;fetchRows();},350);
    });
}
function clearSearch(){if(rcInput)rcInput.value='';rcClr?.classList.remove('show');currentSearch='';currentPage=1;fetchRows();}

document.addEventListener('DOMContentLoaded',()=>{
    const urlP = new URLSearchParams(window.location.search);
    if(urlP.get('date_from')){ document.getElementById('fFrom').value = urlP.get('date_from'); }
    if(urlP.get('date_to'))  { document.getElementById('fTo').value   = urlP.get('date_to');   }
    if(urlP.get('t_code'))   { document.getElementById('fTcode').value = urlP.get('t_code'); }
    if(urlP.get('settled')){
        const sVal = urlP.get('settled');
        if(window.$) $('#selSettled').val(sVal).trigger('change');
        else document.getElementById('selSettled').value = sVal;
    }
    if(urlP.get('date_from')||urlP.get('date_to')||urlP.get('t_code')||urlP.get('settled')){
        const h=document.getElementById('filterHeader'),b=document.getElementById('filterBody'),ic=document.getElementById('filterIcon');
        if(b && !b.classList.contains('open')){ b.classList.add('open'); h?.classList.add('open'); ic?.classList.add('open'); }
    }
    fetchRows();
});

/* ════════════════════════════════════════════
   SETTLEMENT MODAL
════════════════════════════════════════════ */
let ARD={},chequeCounter=0;
const dupCache={};

async function openSettleModal(rcId){
    try{
        const res=await fetch(`return_charges.php?ajax=rc_detail&rc_id=${encodeURIComponent(rcId)}`);
        const data=await res.json();
        if(!data.success){showToast(data.error||'Could not load charge details','err');return;}
        const rc=data.rc;
        const today=new Date().toISOString().slice(0,10);
        ARD={
            rcId        : rcId,
            chequeId    : rc.cheque_id,
            tcode       : rc.t_code,
            customer    : rc.customer_name,
            chargeAmt   : parseFloat(rc.return_charge||0),
            chequeAmt   : parseFloat(rc.cheque_amount||0),
            chequeNo    : rc.cheque_no,
            chequeDate  : rc.cheque_date,
            bankName    : rc.bank_name||rc.bank_code||'—',
            returnReason: rc.return_reason||'',
            creditLimit : rc.credit_limit||'',
            creditDays  : rc.credit_days||'',
            specialDays : rc.special_credit_policy_days||'',
            delivDate   : today,
            fsid        : rc.field_summary_id,
            fsdetail    : rc.field_summary_detail_id,
            invoice     : rc.invoice_num,
            invoicePaymentId : parseInt(rc.invoice_payment_id||0),
        };

        document.getElementById('modalSubtitle').textContent='Cheque: '+ARD.chequeNo+' | '+ARD.customer;
        document.getElementById('hdrCharge').textContent='Rs. '+ARD.chargeAmt.toFixed(2);
        document.getElementById('hdrPaid').textContent='Rs. 0.00';
        document.getElementById('hdrBal').textContent='Rs. '+ARD.chargeAmt.toFixed(2);
        document.getElementById('hdrChequeAmt').textContent='Rs. '+ARD.chequeAmt.toFixed(2);
        document.getElementById('bnrChequeNo').textContent=ARD.chequeNo||'—';
        document.getElementById('bnrChequeDate').textContent=ARD.chequeDate||'—';
        document.getElementById('bnrBank').textContent=ARD.bankName;
        document.getElementById('bnrCharge').textContent='Rs. '+ARD.chargeAmt.toFixed(2);
        document.getElementById('cashDate').value=today;
        document.getElementById('chqPayDate').value=today;
        ['cashAmount','cashToBank','cashRef','cashRemarks','chqRef','chqRemarks','settlementNote'].forEach(id=>{
            const el=document.getElementById(id);if(el)el.value='';
        });
        document.getElementById('cashCollectedBy').value='cc';
        document.getElementById('chqModeSelect').value='payee_only';
        
        setCI('chqLimit',ARD.creditLimit?'Rs. '+parseFloat(ARD.creditLimit).toLocaleString():'—');
        setCI('chqDays',ARD.creditDays||'—');
        setCI('chqSpecial',ARD.specialDays||'—');
        document.getElementById('chequesContainer').innerHTML='';
        chequeCounter=0;addCheque();syncPreviews();

        const modal=document.getElementById('settleModal');
        if(modal.parentElement!==document.body) document.body.appendChild(modal);
        modal.classList.add('open');
        document.body.classList.add('modal-rc-open');
        
        _rcCollectorType = 'cc';
        rcSetCollectorType('cc');
        rcLoadCollectorPersons();
        loadSettlementPayments();
    }catch(e){showToast('Error: '+e.message,'err');}
}

function closeSettleModal(){
    document.getElementById('settleModal').classList.remove('open');
    document.body.classList.remove('modal-rc-open');
    ARD={};
}
document.addEventListener('keydown',e=>{if(e.key==='Escape') closeSettleModal();});
document.getElementById('settleModal').addEventListener('click',function(e){if(e.target===this) closeSettleModal();});

function setCI(id,v){const el=document.getElementById(id);if(el)el.textContent=v||'—';}

function syncPreviews(){
    const chargeAmt=ARD.chargeAmt||0;
    const cashAmt=Math.max(0,parseFloat(document.getElementById('cashAmount').value)||0);
    let chqTotal=0;
    document.querySelectorAll('#chequesContainer .chq-amt').forEach(i=>{chqTotal+=parseFloat(i.value)||0;});
    const totalPaying=cashAmt+chqTotal;
    const newBal=Math.max(0,chargeAmt-totalPaying);
    document.getElementById('sumCash').textContent='Rs. '+cashAmt.toFixed(2);
    document.getElementById('sumCheque').textContent='Rs. '+chqTotal.toFixed(2);
    document.getElementById('sumTotal').textContent='Rs. '+totalPaying.toFixed(2);
    document.getElementById('sumBalance').textContent='Rs. '+newBal.toFixed(2);
    document.getElementById('hdrBal').textContent='Rs. '+newBal.toFixed(2);
}

function addCheque(){
    chequeCounter++;
    const idx=chequeCounter;
    let bankOpts='<option value="">— Select Bank —</option>';
    BANKS.forEach(b=>{bankOpts+=`<option value="${b.bank_code}" data-name="${b.bank_name}">${b.bank_code} – ${b.bank_name}</option>`;});
    const card=document.createElement('div');
    card.className='cheque-card';card.id='cheque-'+idx;
    card.innerHTML=`
      <div class="cheque-card-header">
        <span class="cheque-card-title"><i class="fa-solid fa-money-check"></i> Cheque #${idx}</span>
        ${idx>1?`<button type="button" class="btn-remove-cheque" onclick="document.getElementById('cheque-${idx}').remove();syncPreviews()"><i class="fa-solid fa-trash"></i> Remove</button>`:''}
      </div>
      <div class="gr gr3">
        <div class="fg"><label>Cheque No. <span class="req">*</span></label>
          <input type="text" class="fctrl" id="chqno-${idx}" placeholder="e.g. 001234" oninput="checkDupCheque(${idx})">
          <div class="dup-cheque-warn" id="dup-warn-${idx}"></div></div>
        <div class="fg"><label>Cheque Date</label>
          <input type="date" class="fctrl" id="chqdate-${idx}" value="${ARD.delivDate||''}"></div>
        <div class="fg"><label>Amount (Rs.) <span class="req">*</span></label>
          <input type="number" class="fctrl chq-amt" id="chqamt-${idx}" step="0.01" min="0" placeholder="0.00" oninput="syncPreviews();checkDupCheque(${idx})"></div>
      </div>
      <div class="gr gr2">
        <div class="fg"><label>Bank <span class="req">*</span></label>
          <select class="fctrl" id="chq-bank-${idx}">${bankOpts}</select></div>
        <div class="fg"><label>Branch</label>
          <select class="fctrl" id="chq-branch-${idx}"><option value="">— Select Branch —</option></select></div>
      </div>`;
    document.getElementById('chequesContainer').appendChild(card);
    $(`#chq-bank-${idx}`).select2({width:'100%',dropdownParent:$('#settleModal')})
        .on('change',function(){loadBranches(this.value,idx);});
    $(`#chq-branch-${idx}`).select2({width:'100%',dropdownParent:$('#settleModal')});
}

function loadBranches(bankCode,idx){
    const sel=document.getElementById('chq-branch-'+idx);
    sel.innerHTML='<option value="">Loading…</option>';
    $(sel).select2('destroy');
    if(!bankCode){sel.innerHTML='<option value="">— Select Branch —</option>';$(sel).select2({width:'100%',dropdownParent:$('#settleModal')});return;}
    fetch('get_bank_branches.php?bank_code='+encodeURIComponent(bankCode))
        .then(r=>r.json())
        .then(d=>{
            let opts='<option value="">— Select Branch —</option>';
            d.forEach(b=>{opts+=`<option value="${b.branch_code}" data-name="${b.branch_name}">${b.branch_code} – ${b.branch_name}</option>`;});
            sel.innerHTML=opts;$(sel).select2({width:'100%',dropdownParent:$('#settleModal')});
        })
        .catch(()=>{sel.innerHTML='<option value="">Error loading</option>';$(sel).select2({width:'100%',dropdownParent:$('#settleModal')});});
}

function checkDupCheque(idx){
    const no=(document.getElementById('chqno-'+idx)?.value||'').trim();
    const amt=parseFloat(document.getElementById('chqamt-'+idx)?.value||0);
    const warn=document.getElementById('dup-warn-'+idx);
    if(!no||!warn) return;
    if(dupCache[no]!==undefined){showDupWarning(warn,no,dupCache[no],amt);return;}
    fetch('get_cheque_info.php?cheque_no='+encodeURIComponent(no))
        .then(r=>r.json())
        .then(d=>{dupCache[no]=d.exists?d.total_amount:null;showDupWarning(warn,no,dupCache[no],amt);})
        .catch(()=>{});
}
function showDupWarning(warn,no,existingTotal,newAmt){
    if(existingTotal!==null&&existingTotal!==undefined){
        const newTotal=(parseFloat(existingTotal)||0)+(parseFloat(newAmt)||0);
        warn.innerHTML=`<i class="fa-solid fa-triangle-exclamation"></i> Cheque #${no} already exists (Rs. ${parseFloat(existingTotal).toFixed(2)}). New total: Rs. ${newTotal.toFixed(2)}.`;
        warn.classList.add('show');
    }else{warn.classList.remove('show');warn.innerHTML='';}
}

async function loadSettlementPayments(){
    const wrap=document.getElementById('spmTableWrap');
    if(!wrap||!ARD.rcId) return;
    wrap.innerHTML='<div class="spm-empty"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    try{
        const res=await fetch(`return_charges.php?ajax=rc_settlement_payments&rc_id=${ARD.rcId}`);
        const data=await res.json();
        renderSettlementPayments(data.payments||[],data.total_settled||0);
    }catch(e){wrap.innerHTML='<div class="spm-empty" style="color:#dc2626;">Error loading</div>';}
}

function renderSettlementPayments(payments, totalSettled) {
    const wrap  = document.getElementById('spmTableWrap');
    const badge = document.getElementById('spmTotalBadge');
    if (badge) badge.textContent = totalSettled > 0 ? 'Total: Rs. ' + parseFloat(totalSettled).toFixed(2) : '';
    if (!payments.length) {
        wrap.innerHTML = '<div class="spm-empty"><i class="fa-solid fa-circle-info"></i> No payments recorded yet.</div>';
        return;
    }
    let rows = '';
    payments.forEach(p => {
        const isCash   = p.payment_method === 'cash';
        const isCheque = p.payment_method === 'cheque';
        const mc = isCash ? 'spm-method-cash' : isCheque ? 'spm-method-cheque' : 'spm-method-other';

        let detailCell = '';
        if (isCheque) {
            const bankDisplay = [p.bank_code, p.bank_name].filter(Boolean).join(' · ') || '—';
            detailCell = p.cheque_no ? `
            <div class="rchq-card">
                <div class="rchq-no"><i class="fa-solid fa-money-check"></i> ${esc(p.cheque_no)}</div>
                <div class="rchq-grid">
                    <div class="rchq-row"><span class="rchq-lbl"><i class="fa-solid fa-building-columns"></i> Bank</span><span class="rchq-val">${esc(bankDisplay)}</span></div>
                    <div class="rchq-row"><span class="rchq-lbl"><i class="fa-solid fa-code-branch"></i> Branch</span><span class="rchq-val">${esc(p.branch_name || '—')}</span></div>
                    <div class="rchq-row"><span class="rchq-lbl"><i class="fa-regular fa-calendar"></i> Cheque Date</span><span class="rchq-val">${esc(p.cheque_date || '—')}</span></div>
                    <div class="rchq-row"><span class="rchq-lbl"><i class="fa-solid fa-coins"></i> Amount</span><span class="rchq-val" style="color:#1e40af;font-weight:800;">Rs. ${parseFloat(p.amount).toFixed(2)}</span></div>
                </div>
            </div>` : `<span style="font-size:11px;color:#6b7280;font-style:italic;">Cheque — no details saved</span>`;
        } else if (isCash) {
            detailCell = `<span style="font-size:11px;color:#16a34a;font-weight:600;display:flex;align-items:center;gap:4px;"><i class="fa-solid fa-coins"></i> Cash Payment</span>`;
        } else {
            detailCell = `<span style="font-size:11px;color:#6b7280;">—</span>`;
        }

        rows += `<tr>
            <td>${esc(p.payment_date || '—')}</td>
            <td><span class="${mc}">${esc(p.payment_method)}</span></td>
            <td style="font-weight:700;color:#166534;white-space:nowrap;">Rs. ${parseFloat(p.amount).toFixed(2)}</td>
            <td>${detailCell}</td>
            <td style="font-size:11px;">${esc(p.reference_no || '—')}</td>
            <td style="font-size:11px;">${esc(p.remarks || '—')}</td>
            <td><button class="btn-del-pay" onclick="deleteSettlementPayment(${p.id})"><i class="fa-solid fa-trash"></i></button></td>
        </tr>`;
    });
    const total = payments.reduce((s, p) => s + parseFloat(p.amount), 0);
    wrap.innerHTML = `<table class="spm-table">
        <thead><tr>
            <th>Date</th><th>Method</th><th>Amount</th>
            <th>Cheque / Cash Details</th><th>Reference</th><th>Remarks</th><th></th>
        </tr></thead>
        <tbody>${rows}</tbody>
        <tfoot><tr class="spm-total-row">
            <td colspan="2" style="padding:7px 10px;">Total Settled</td>
            <td style="padding:7px 10px;">Rs. ${total.toFixed(2)}</td>
            <td colspan="4"></td>
        </tr></tfoot>
    </table>`;
    document.getElementById('hdrPaid').textContent = 'Rs. ' + total.toFixed(2);
    document.getElementById('hdrBal').textContent  = 'Rs. ' + Math.max(0, ARD.chargeAmt - total).toFixed(2);
}

async function deleteSettlementPayment(pid){
    if(!confirm('Delete this payment?')) return;
    const fd=new FormData();
    fd.append('ajax_action','rc_delete_payment');
    fd.append('payment_id',pid);
    fd.append('rc_id',ARD.rcId);
    try{
        const res=await fetch('return_charges.php',{method:'POST',body:fd});
        const data=await res.json();
        if(!data.success){showToast(data.error||'Delete failed','err');return;}
        showToast('Payment deleted','ok');
        await loadSettlementPayments();
        updateRowBadge(ARD.rcId,data.new_total,data.fully_settled,data.charge_total||ARD.chargeAmt);
    }catch(e){showToast('Error: '+e.message,'err');}
}

function updateRowBadge(rcId,newTotal,fullSettled,chargeAmt){
    const rowEl=document.getElementById('row-'+rcId);
    if(!rowEl) return;
    const settleCell=rowEl.querySelector('td:nth-child(10)');
    const actionCell=rowEl.querySelector('td:last-child');
    const paid=parseFloat(newTotal)||0;
    const total=parseFloat(chargeAmt)||0;
    const remain=Math.max(0,total-paid);

    if(paid<=0){
        if(settleCell) settleCell.innerHTML=
            `<span class="settled-badge no"><i class="fa-solid fa-clock"></i> Unsettled</span>`
           +`<div class="settle-meta" style="color:#9ca3af;">Paid: Rs.0.00 &nbsp;|&nbsp; Bal: Rs.${total.toFixed(2)}</div>`;
        if(actionCell) actionCell.innerHTML=
            `<button class="btn-loadpay" onclick="openSettleModal(${rcId})"><i class="fa-solid fa-money-bill-transfer"></i> Load Pay</button>`;
        rowEl.dataset.settled='0';
    } else if(fullSettled){
        if(settleCell) settleCell.innerHTML=
            `<span class="settled-badge yes"><i class="fa-solid fa-circle-check"></i> Fully Settled</span>`
           +`<div class="settle-meta" style="color:#166534;">Paid: Rs.${paid.toFixed(2)} &nbsp;|&nbsp; Bal: Rs.0.00</div>`;
        if(actionCell) actionCell.innerHTML=
            `<button class="btn-loadpay settled" onclick="openSettleModal(${rcId})"><i class="fa-solid fa-circle-check"></i> View</button>`;
        rowEl.dataset.settled='1';
    } else {
        if(settleCell) settleCell.innerHTML=
            `<span class="settled-badge no" style="background:#fff7ed;color:#c2410c;border-color:#fed7aa;"><i class="fa-solid fa-circle-half-stroke"></i> Partially Settled</span>`
           +`<div class="settle-meta" style="color:#92400e;">Paid: Rs.${paid.toFixed(2)} &nbsp;|&nbsp; Bal: Rs.${remain.toFixed(2)}</div>`;
        if(actionCell) actionCell.innerHTML=
            `<button class="btn-loadpay" onclick="openSettleModal(${rcId})"><i class="fa-solid fa-money-bill-transfer"></i> Load Pay</button>`;
        rowEl.dataset.settled='0';
    }
}

async function submitSettlement(){
    const btn=document.getElementById('submitSettleBtn');
    const cashAmt=parseFloat(document.getElementById('cashAmount').value)||0;
    const cashDate=document.getElementById('cashDate').value;
    const settlementNote=document.getElementById('settlementNote').value||'';
    const chqCards=document.querySelectorAll('#chequesContainer .cheque-card');
    let chqTotal=0,chqValid=true,cheques=[];

    chqCards.forEach(card=>{
        const idx=parseInt(card.id.replace('cheque-',''));
        const no=(document.getElementById('chqno-'+idx)?.value||'').trim();
        const amt=parseFloat(document.getElementById('chqamt-'+idx)?.value||0);
        const dt=document.getElementById('chqdate-'+idx)?.value||'';
        const bkCode=$(`#chq-bank-${idx}`).val()||'';
        const bkSel=document.getElementById('chq-bank-'+idx);
        const bkName=bkSel?.selectedOptions[0]?.dataset.name||bkSel?.selectedOptions[0]?.text||'';
        const brCode=$(`#chq-branch-${idx}`).val()||'';
        const brSel=document.getElementById('chq-branch-'+idx);
        const brName=brSel?.selectedOptions[0]?.dataset.name||brSel?.selectedOptions[0]?.text||'';
        if(amt>0){if(!no) chqValid=false;chqTotal+=amt;cheques.push({cheque_no:no,cheque_date:dt,amount:amt,bank_code:bkCode,bank_name:bkName,branch_code:brCode,branch_name:brName});}
    });

    if(cashAmt<=0&&chqTotal<=0){showToast('Enter a cash amount or at least one cheque amount.','err');return;}
    if(cashAmt>0&&!cashDate){showToast('Select a Payment Date for cash.','err');return;}
    if(chqTotal>0&&!chqValid){showToast('Fill in all Cheque Numbers.','err');return;}

    btn.disabled=true;btn.innerHTML='<span class="spinner"></span> Saving…';
    let saved=[];

    try{
        if(cashAmt>0){
            const fds=new FormData();
            fds.append('ajax_action','rc_add_payment');
            fds.append('rc_id',ARD.rcId);
            fds.append('payment_method','cash');
            fds.append('payment_date',cashDate);
            fds.append('amount',cashAmt.toFixed(2));
            fds.append('reference_no',document.getElementById('cashRef').value||'');
            fds.append('remarks',document.getElementById('cashRemarks').value||settlementNote);
            const cv = rcGetCollectorValues();
            fds.append('collected_by',    cv.collected_by);
            fds.append('delivery_person', cv.delivery_person);
            fds.append('sr_code',         cv.sr_code);
            fds.append('employee_id',     cv.employee_id);
            const sr=await fetch('return_charges.php',{method:'POST',body:fds});
            const sd=await sr.json();
            if(!sd.success){showToast('Cash error: '+(sd.error||'Unknown'),'err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Save Payment';return;}
            if(sd.new_total!==undefined) updateRowBadge(ARD.rcId,sd.new_total,sd.fully_settled,sd.charge_total||ARD.chargeAmt);
            saved.push('💵 Cash Rs.'+cashAmt.toFixed(2));
        }

        if(chqTotal>0){
            for(const q of cheques){
                const fds=new FormData();
                fds.append('ajax_action','rc_add_payment');
                fds.append('rc_id',ARD.rcId);
                fds.append('payment_method','cheque');
                fds.append('payment_date',document.getElementById('chqPayDate').value||new Date().toISOString().slice(0,10));
                fds.append('amount',q.amount.toFixed(2));
                fds.append('reference_no',document.getElementById('chqRef').value||'');
                fds.append('remarks',document.getElementById('chqRemarks').value||settlementNote);
                const cv = rcGetCollectorValues();
                fds.append('collected_by',    cv.collected_by);
                fds.append('delivery_person', cv.delivery_person);
                fds.append('sr_code',         cv.sr_code);
                fds.append('employee_id',     cv.employee_id);
                fds.append('cheque_no',q.cheque_no);
                fds.append('cheque_date',q.cheque_date);
                fds.append('bank_name',q.bank_name);
                fds.append('bank_code',q.bank_code);
                fds.append('branch_name',q.branch_name);
                fds.append('branch_code',q.branch_code);
                fds.append('field_summary_id',ARD.fsid);
                fds.append('t_code',ARD.tcode);
                fds.append('invoice_payment_id',ARD.invoicePaymentId);
                const sr=await fetch('return_charges.php',{method:'POST',body:fds});
                const sd=await sr.json();
                if(!sd.success){showToast('Cheque error: '+(sd.error||'Unknown'),'err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Save Payment';return;}
                if(sd.new_total!==undefined) updateRowBadge(ARD.rcId,sd.new_total,sd.fully_settled,sd.charge_total||ARD.chargeAmt);
            }
            saved.push('🏦 Cheque Rs.'+chqTotal.toFixed(2));
        }

        ['cashAmount','cashToBank','cashRef','cashRemarks','chqRef','chqRemarks','settlementNote'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
        document.getElementById('chequesContainer').innerHTML='';chequeCounter=0;addCheque();syncPreviews();
        await loadSettlementPayments();
        showToast(saved.join(' + ')+' ✓ Saved','ok');
    }catch(err){showToast('Network error: '+err.message,'err');}

    btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Save Payment';
}

/* ════════════════════════════════════
   COLLECTOR DETAILS
════════════════════════════════════ */
let _rcCollectorType = 'cc';
let _rcPersonsLoaded = false;

function rcSetCollectorType(type) {
    _rcCollectorType = type;
    document.getElementById('rcDpWrap').style.display  = type === 'cc' ? '' : 'none';
    document.getElementById('rcSrWrap').style.display  = type === 'sr' ? '' : 'none';
    document.getElementById('rcCtypeCC').className = 'ctype-btn' + (type === 'cc' ? ' active-cc' : '');
    document.getElementById('rcCtypeSR').className = 'ctype-btn' + (type === 'sr' ? ' active-sr' : '');
}

async function rcLoadCollectorPersons() {
    if (_rcPersonsLoaded) return;
    
    try {
        // Load delivery persons
        const dpSt = document.getElementById('rcDpStatus');
        const dpSel = document.getElementById('rcDpSelect');
        
        dpSt.textContent = 'Loading…'; 
        dpSt.className = 'dp-modal-status';
        
        const dpRes = await fetch('get_delivery_persons.php');
        const dpData = await dpRes.json();
        
        if (dpData.success && dpData.persons && dpData.persons.length) {
            dpData.persons.forEach(name => {
                const opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                dpSel.appendChild(opt);
            });
            dpSt.textContent = dpData.persons.length + ' person(s) loaded';
            dpSt.className = 'dp-modal-status ok';
        } else {
            dpSt.textContent = 'No delivery persons found';
            dpSt.className = 'dp-modal-status err';
        }
        
        if (window.$) {
            $('#rcDpSelect').select2({
                placeholder: '-- Select Delivery Person --',
                allowClear: true,
                width: '100%',
                dropdownParent: $('#settleModal')
            });
        }
        
        // Load SR codes & employees
        const isRes = await fetch('return_charges.php?ajax=issue_persons');
        const isData = await isRes.json();
        
        if (isData.success) {
            const srSel   = document.getElementById('rcSrSelect');
            const empSel1 = document.getElementById('rcEmpSelect');
            const empSel2 = document.getElementById('rcSrEmpSelect');
            
            if (isData.sr_persons && isData.sr_persons.length) {
                isData.sr_persons.forEach(sr => {
                    const opt = document.createElement('option');
                    opt.value = sr.code;
                    opt.textContent = sr.code;
                    srSel.appendChild(opt);
                });
            }
            
            if (isData.employees && isData.employees.length) {
                isData.employees.forEach(emp => {
                    const label = (emp.employee_id || 'N/A') + ' — ' + emp.employee_full_name +
                                 (emp.designation_name ? ' (' + emp.designation_name + ')' : '');
                    
                    const opt1 = document.createElement('option');
                    opt1.value = emp.id;
                    opt1.textContent = label;
                    empSel1.appendChild(opt1);
                    
                    const opt2 = document.createElement('option');
                    opt2.value = emp.id;
                    opt2.textContent = label;
                    empSel2.appendChild(opt2);
                });
            }
            
            if (window.$) {
                const parent = $('#settleModal');
                
                $('#rcSrSelect').select2({
                    placeholder: '-- Select SR Code --',
                    allowClear: true,
                    width: '100%',
                    dropdownParent: parent
                });
                
                $('#rcEmpSelect').select2({
                    placeholder: '-- Select Employee --',
                    allowClear: true,
                    width: '100%',
                    dropdownParent: parent
                });
                
                $('#rcSrEmpSelect').select2({
                    placeholder: '-- Select Employee --',
                    allowClear: true,
                    width: '100%',
                    dropdownParent: parent
                });
            }
        }
        
        _rcPersonsLoaded = true;
        
    } catch(e) {
        console.error('Error loading collector persons:', e);
        const dpSt = document.getElementById('rcDpStatus');
        if (dpSt) {
            dpSt.textContent = 'Error loading';
            dpSt.className = 'dp-modal-status err';
        }
    }
}

function rcGetCollectorValues() {
    if (_rcCollectorType === 'cc') {
        return {
            collected_by:    'cc',
            delivery_person: (window.$ ? $('#rcDpSelect').val() : document.getElementById('rcDpSelect').value) || '',
            sr_code:         '',
            employee_id:     (window.$ ? $('#rcEmpSelect').val() : document.getElementById('rcEmpSelect').value) || '',
        };
    } else {
        return {
            collected_by:    'sr',
            delivery_person: '',
            sr_code:         (window.$ ? $('#rcSrSelect').val() : document.getElementById('rcSrSelect').value) || '',
            employee_id:     (window.$ ? $('#rcSrEmpSelect').val() : document.getElementById('rcSrEmpSelect').value) || '',
        };
    }
}

function showToast(msg,type){
    const t=document.getElementById('rcToast');
    t.style.background=type==='ok'?'#166534':'#dc2626';
    t.textContent=msg;t.classList.add('show');
    clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),3200);
}

function esc(s){if(s===null||s===undefined)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}

function exportCSV(){
    const rows=document.querySelectorAll('#mainTable tbody tr');
    if(!rows.length){alert('No data to export.');return;}
    const headers=['No','Cheque No','Cheque Date','Bank','T-Code','Customer','Cheque Amt','Return Charge','Return Reason','Charged At','Settlement'];
    const lines=[headers.join(',')];
    const q=v=>'"'+(v||'').toString().replace(/"/g,'""').replace(/\s+/g,' ').trim()+'"';
    rows.forEach((tr,i)=>{
        const tds=tr.querySelectorAll('td');
        const settled=tr.dataset.settled==='1'?'Fully Settled':
            (parseFloat(tds[9]?.querySelector('.settle-meta')?.textContent?.match(/Paid: Rs\.([\d.]+)/)?.[1]||0)>0?'Partially Settled':'Unsettled');
        lines.push([i+1,
            q(tds[1]?.querySelector('.mono')?.textContent||''),
            q(tds[2]?.textContent||''),
            q(tds[3]?.querySelector('div')?.textContent||''),
            q(tds[4]?.querySelector('.mono')?.textContent||''),
            q(tds[4]?.querySelector('.cust-sub')?.textContent||''),
            q(tds[5]?.textContent||''),
            q(tds[6]?.textContent||''),
            q(tds[7]?.textContent||''),
            q(tds[8]?.textContent||''),
            q(settled),
        ].join(','));
    });
    const blob=new Blob([lines.join('\n')],{type:'text/csv'});
    const url=URL.createObjectURL(blob);
    const a=document.createElement('a');a.href=url;
    a.download='return_charges_<?php echo date("Ymd_Hi"); ?>.csv';
    a.click();URL.revokeObjectURL(url);
}
</script>

<?php include 'footer.php'; ?>