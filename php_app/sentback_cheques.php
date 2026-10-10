<?php
/**
 * sentback_cheques.php — Sent Back Cheques Register
 * Issue system uses: sentback_issues + sentback_issue_items (save_sentback_issue.php)
 */

if (session_status() === PHP_SESSION_NONE) session_start();
function get_current_user_label() {
    return $_SESSION['username']   ??
           $_SESSION['user_name']  ??
           $_SESSION['name']       ??
           $_SESSION['full_name']  ??
           $_SESSION['email']      ??
           (isset($_SESSION['user_id']) ? 'User #'.$_SESSION['user_id'] : 'system');
}

/* ══ AJAX — issue_persons ══ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'issue_persons') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');
    $cc_r = mysqli_query($conn, "SELECT DISTINCT delivery_person AS code, delivery_person AS label
        FROM loading_summary_import_details
        WHERE delivery_person IS NOT NULL AND delivery_person <> '' ORDER BY delivery_person");
    $cc = []; if ($cc_r) while ($r = mysqli_fetch_assoc($cc_r)) $cc[] = $r;
    $sr_r = mysqli_query($conn, "SELECT DISTINCT sr_code AS code, sr_code AS label FROM field_summary ORDER BY sr_code");
    $sr = []; if ($sr_r) while ($r = mysqli_fetch_assoc($sr_r)) $sr[] = $r;
    $emp_r = mysqli_query($conn, "SELECT e.id, e.employee_id, e.employee_full_name,
        COALESCE(d.designation_name,'') AS designation_name
        FROM employees e LEFT JOIN designations d ON d.id=e.designation_id
        WHERE e.active=1 ORDER BY e.employee_full_name");
    $emp = []; if ($emp_r) while ($r = mysqli_fetch_assoc($emp_r)) $emp[] = $r;
    echo json_encode(['success'=>true,'cc_persons'=>$cc,'sr_persons'=>$sr,'employees'=>$emp]);
    exit;
}

/* ══ AJAX — sentback_cheque_rows ══ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'sentback_cheque_rows') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');

    $page     = max(1, intval($_GET['page']  ?? 1));
    $per_page = max(1, intval($_GET['per']   ?? 50));
    $search   = trim($_GET['q']              ?? '');
    $f_bank   = trim($_GET['bank_code']      ?? '');
    $f_tcode  = trim($_GET['t_code']         ?? '');
    $f_from   = trim($_GET['date_from']      ?? '');
    $f_to     = trim($_GET['date_to']        ?? '');
    $f_settled= trim($_GET['settled']        ?? '');

    $where = ["ch.status='sent_back'"];
    if ($f_bank)   $where[] = "ch.bank_code='".mysqli_real_escape_string($conn,$f_bank)."'";
    if ($f_tcode)  $where[] = "ch.t_code LIKE '%".mysqli_real_escape_string($conn,$f_tcode)."%'";
    if ($f_from)   $where[] = "ch.cheque_date>='".mysqli_real_escape_string($conn,$f_from)."'";
    if ($f_to)     $where[] = "ch.cheque_date<='".mysqli_real_escape_string($conn,$f_to)."'";
    if ($f_settled === '1') $where[] = "COALESCE(ch.sb_settled,0)=1";
    if ($f_settled === '0') $where[] = "(ch.sb_settled IS NULL OR ch.sb_settled=0)";

    if ($search !== '') {
        $s = '%'.mysqli_real_escape_string($conn, $search).'%';
        $where[] = "(ch.cheque_no LIKE '$s' OR ch.t_code LIKE '$s' OR ch.bank_code LIKE '$s'
            OR ch.bank_name LIKE '$s' OR ch.branch_name LIKE '$s'
            OR ch.sent_back_reason LIKE '$s'
            OR COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) LIKE '$s'
            OR CAST(ch.total_amount AS CHAR) LIKE '$s')";
    }

    $where_sql = implode(' AND ', $where);
    $base_sql  = "
        FROM cheques ch
        INNER JOIN invoice_payments      ip  ON ip.id  = ch.invoice_payment_id
        INNER JOIN field_summary         fs  ON fs.id  = ip.field_summary_id
        LEFT  JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        LEFT  JOIN customers             c   ON c.t_code = ch.t_code
        WHERE $where_sql";

    $cnt_r = mysqli_query($conn, "SELECT COUNT(*) AS cnt $base_sql");
    $total = $cnt_r ? (int)mysqli_fetch_assoc($cnt_r)['cnt'] : 0;
    $offset = ($page - 1) * $per_page;

    $data_sql = "
        SELECT ch.id, ch.cheque_no, ch.cheque_date, ch.total_amount,
               ch.bank_code, ch.bank_name, ch.branch_code, ch.branch_name,
               ch.status, ch.t_code, ch.cheque_mode, ch.sent_back_reason,
               COALESCE(ch.received_date, ip.payment_date)  AS received_date,
               COALESCE(ch.sb_settled, 0)                   AS sb_settled,
               COALESCE(ch.sb_settlement_date, '')           AS sb_settlement_date,
               COALESCE(ch.sb_settlement_amount, 0)          AS sb_settlement_amount,
               COALESCE(ch.sb_settlement_note, '')           AS sb_settlement_note,
               fs.sr_code, fs.delivery_date,
               ip.field_summary_id, ip.field_summary_detail_id, ip.invoice_num,
               COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) AS customer_name,
               COALESCE(fsd.adjust_net_value, 0) AS invoice_amt,
               COALESCE((SELECT SUM(p2.amount) FROM invoice_payments p2
                         WHERE p2.field_summary_detail_id=ip.field_summary_detail_id
                         AND p2.is_reversed=0), 0) AS total_paid,
               (SELECT cl.created_at FROM cheque_logs cl
                WHERE cl.cheque_id=ch.id AND cl.action='sent_back'
                ORDER BY cl.created_at DESC LIMIT 1) AS sent_back_date,
               COALESCE((SELECT sii.status
                         FROM sentback_issue_items sii
                         WHERE sii.cheque_id=ch.id
                         ORDER BY sii.id DESC LIMIT 1), '') AS issue_item_status,
               (SELECT sii3.id
                         FROM sentback_issue_items sii3
                         WHERE sii3.cheque_id=ch.id
                         ORDER BY sii3.id DESC LIMIT 1) AS issue_item_id,
               COALESCE((SELECT si.issue_code
                         FROM sentback_issues si
                         INNER JOIN sentback_issue_items sii2 ON sii2.issue_id=si.id
                         WHERE sii2.cheque_id=ch.id
                         ORDER BY sii2.id DESC LIMIT 1), '') AS issue_code
        $base_sql
        ORDER BY ch.cheque_date DESC, ch.cheque_no ASC
        LIMIT $per_page OFFSET $offset";

    $res  = mysqli_query($conn, $data_sql);
    $rows = [];
    if ($res) while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;

    $amt_r = mysqli_query($conn, "SELECT COALESCE(SUM(ch.total_amount),0) AS tot $base_sql");
    $g_amt = $amt_r ? (float)mysqli_fetch_assoc($amt_r)['tot'] : 0;

    $settled_r = mysqli_query($conn, "SELECT
        COALESCE(SUM(CASE WHEN COALESCE(ch.sb_settled,0)=1 THEN ch.total_amount ELSE 0 END),0) AS s_amt,
        COUNT(CASE WHEN COALESCE(ch.sb_settled,0)=1 THEN 1 END) AS s_cnt,
        COUNT(CASE WHEN COALESCE(ch.sb_settled,0)=0 THEN 1 END) AS u_cnt,
        COALESCE(SUM(CASE WHEN COALESCE(ch.sb_settled,0)=0 THEN total_amount ELSE 0 END),0) AS u_amt
        $base_sql");
    $s_data = $settled_r ? mysqli_fetch_assoc($settled_r) : ['s_amt'=>0,'s_cnt'=>0,'u_cnt'=>0,'u_amt'=>0];

    echo json_encode([
        'success'       => true,
        'rows'          => $rows,
        'total'         => $total,
        'grand_total'   => $g_amt,
        'settled_amt'   => (float)$s_data['s_amt'],
        'settled_cnt'   => (int)$s_data['s_cnt'],
        'unsettled_amt' => (float)$s_data['u_amt'],
        'unsettled_cnt' => (int)$s_data['u_cnt'],
        'page'          => $page,
        'per_page'      => $per_page,
        'pages'         => max(1, (int)ceil($total / $per_page)),
    ]);
    exit;
}

/* ══ AJAX — sentback_cheque_detail ══ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'sentback_cheque_detail') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');
    $cid = intval($_GET['cheque_id'] ?? 0);
    if (!$cid) { echo json_encode(['success'=>false]); exit; }
    $r = mysqli_query($conn, "
        SELECT ch.id, ch.cheque_no, ch.total_amount, ch.t_code, ch.cheque_date,
               ch.bank_code, ch.bank_name, ch.sent_back_reason,
               ip.field_summary_id, ip.field_summary_detail_id, ip.invoice_num,
               COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) AS customer_name,
               COALESCE(fsd.adjust_net_value, 0) AS invoice_amt,
               COALESCE(c.payment_mode,'') AS payment_mode,
               COALESCE(c.credit_limit, 0) AS credit_limit,
               COALESCE(c.credit_days, 0) AS credit_days,
               COALESCE(c.special_credit_policy_days,'') AS special_credit_policy_days,
               COALESCE(ip.payment_date, ch.received_date, NOW()) AS delivery_date,
               COALESCE(ch.sb_settled, 0) AS sb_settled,
               COALESCE((SELECT sii.status FROM sentback_issue_items sii
                         WHERE sii.cheque_id=ch.id ORDER BY sii.id DESC LIMIT 1), '') AS issue_item_status,
               (SELECT sii.id FROM sentback_issue_items sii
                         WHERE sii.cheque_id=ch.id ORDER BY sii.id DESC LIMIT 1) AS issue_item_id,
               COALESCE((SELECT si.issue_code FROM sentback_issues si
                         INNER JOIN sentback_issue_items sii2 ON sii2.issue_id=si.id
                         WHERE sii2.cheque_id=ch.id ORDER BY sii2.id DESC LIMIT 1), '') AS issue_code
        FROM cheques ch
        INNER JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id
        LEFT  JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        LEFT  JOIN customers c ON c.t_code = ch.t_code
        WHERE ch.id = $cid LIMIT 1");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    if (!$row) { echo json_encode(['success'=>false,'error'=>'Not found']); exit; }
    $pr   = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS p FROM invoice_payments WHERE field_summary_detail_id=".intval($row['field_summary_detail_id'])." AND is_reversed=0");
    $paid = (float)($pr ? mysqli_fetch_assoc($pr)['p'] : 0);
    $row['total_paid'] = $paid;
    $row['balance']    = max(0, (float)$row['invoice_amt'] - $paid);
    echo json_encode(['success'=>true,'cheque'=>$row]);
    exit;
}

/* ══ AJAX — sb_settlement_payments (list) ══ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'sb_settlement_payments') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');
    $cid = intval($_GET['cheque_id'] ?? 0);
    if (!$cid) { echo json_encode(['success'=>false,'payments'=>[]]); exit; }
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_sb_settlement_payments (
        id INT AUTO_INCREMENT PRIMARY KEY, cheque_id INT NOT NULL,
        payment_method VARCHAR(20) NOT NULL DEFAULT 'cash', payment_date DATE NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, reference_no VARCHAR(100) DEFAULT NULL,
        remarks TEXT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(100) DEFAULT 'system', INDEX idx_sbsp_cid (cheque_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['cheque_no'=>"VARCHAR(100) DEFAULT NULL",'bank_name'=>"VARCHAR(100) DEFAULT NULL",'bank_code'=>"VARCHAR(50) DEFAULT NULL",'branch_name'=>"VARCHAR(100) DEFAULT NULL",'cheque_date'=>"DATE NULL"] as $_col=>$_def) {
        $chk=mysqli_query($conn,"SHOW COLUMNS FROM cheque_sb_settlement_payments LIKE '$_col'");
        if(!$chk||mysqli_num_rows($chk)===0) mysqli_query($conn,"ALTER TABLE cheque_sb_settlement_payments ADD COLUMN $_col $_def");
    }
    $rows = [];
    $r = mysqli_query($conn, "SELECT * FROM cheque_sb_settlement_payments WHERE cheque_id=$cid ORDER BY created_at ASC");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    echo json_encode(['success'=>true,'payments'=>$rows,'total_settled'=>array_sum(array_column($rows,'amount'))]);
    exit;
}

/* ══ AJAX — add_sb_settlement_payment ══ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_sb_settlement_payment') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');
    $cid        = intval($_POST['cheque_id'] ?? 0);
    $method     = mysqli_real_escape_string($conn, trim($_POST['payment_method'] ?? 'cash'));
    $date       = mysqli_real_escape_string($conn, trim($_POST['payment_date']   ?? date('Y-m-d')));
    $amt        = round(abs(floatval($_POST['amount'] ?? 0)), 2);
    $ref        = mysqli_real_escape_string($conn, trim($_POST['reference_no']   ?? ''));
    $rem        = mysqli_real_escape_string($conn, trim($_POST['remarks']        ?? ''));
    $chq_no     = mysqli_real_escape_string($conn, trim($_POST['cheque_no']      ?? ''));
    $bank_name  = mysqli_real_escape_string($conn, trim($_POST['bank_name']      ?? ''));
    $bank_code  = mysqli_real_escape_string($conn, trim($_POST['bank_code']      ?? ''));
    $branch_name= mysqli_real_escape_string($conn, trim($_POST['branch_name']    ?? ''));
    $chq_date   = mysqli_real_escape_string($conn, trim($_POST['cheque_date']    ?? ''));
    if (!$cid || $amt <= 0) { echo json_encode(['success'=>false,'error'=>'Invalid cheque or zero amount']); exit; }
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_sb_settlement_payments (
        id INT AUTO_INCREMENT PRIMARY KEY, cheque_id INT NOT NULL,
        payment_method VARCHAR(20) NOT NULL DEFAULT 'cash', payment_date DATE NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, reference_no VARCHAR(100) DEFAULT NULL,
        remarks TEXT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(100) DEFAULT 'system', INDEX idx_sbsp_cid (cheque_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['cheque_no'=>"VARCHAR(100) DEFAULT NULL",'bank_name'=>"VARCHAR(100) DEFAULT NULL",'bank_code'=>"VARCHAR(50) DEFAULT NULL",'branch_name'=>"VARCHAR(100) DEFAULT NULL",'cheque_date'=>"DATE NULL"] as $_col=>$_def) {
        $chk=mysqli_query($conn,"SHOW COLUMNS FROM cheque_sb_settlement_payments LIKE '$_col'");
        if(!$chk||mysqli_num_rows($chk)===0) mysqli_query($conn,"ALTER TABLE cheque_sb_settlement_payments ADD COLUMN $_col $_def");
    }
    foreach (['sb_settlement_date'=>'DATE NULL','sb_settlement_amount'=>'DECIMAL(12,2) DEFAULT 0.00','sb_settlement_note'=>'TEXT NULL','sb_settled'=>'TINYINT(1) DEFAULT 0'] as $_col=>$_def) {
        $chk=mysqli_query($conn,"SHOW COLUMNS FROM cheques LIKE '$_col'");
        if(!$chk||mysqli_num_rows($chk)===0) mysqli_query($conn,"ALTER TABLE cheques ADD COLUMN $_col $_def");
    }
    $cu           = mysqli_real_escape_string($conn, get_current_user_label());
    $chq_no_sql   = $chq_no     ? "'$chq_no'"     : 'NULL';
    $bank_name_sql= $bank_name  ? "'$bank_name'"  : 'NULL';
    $bank_code_sql= $bank_code  ? "'$bank_code'"  : 'NULL';
    $branch_sql   = $branch_name? "'$branch_name'": 'NULL';
    $chq_date_sql = $chq_date   ? "'$chq_date'"   : 'NULL';
    mysqli_query($conn, "INSERT INTO cheque_sb_settlement_payments
        (cheque_id,payment_method,cheque_no,cheque_date,payment_date,amount,reference_no,remarks,bank_name,bank_code,branch_name,created_by)
        VALUES ($cid,'$method',$chq_no_sql,$chq_date_sql,'$date',$amt,'$ref','$rem',$bank_name_sql,$bank_code_sql,$branch_sql,'$cu')");
    $new_id = mysqli_insert_id($conn);
    $tr = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS tot FROM cheque_sb_settlement_payments WHERE cheque_id=$cid");
    $new_total = $tr ? (float)mysqli_fetch_assoc($tr)['tot'] : 0;
    $chq_r = mysqli_query($conn, "SELECT total_amount FROM cheques WHERE id=$cid LIMIT 1");
    $chq_total = $chq_r ? (float)mysqli_fetch_assoc($chq_r)['total_amount'] : 0;
    $fully = ($new_total >= $chq_total && $chq_total > 0) ? 1 : 0;
    mysqli_query($conn, "UPDATE cheques SET sb_settlement_amount=$new_total, sb_settled=$fully, sb_settlement_date='$date' WHERE id=$cid");
    echo json_encode(['success'=>true,'payment_id'=>(int)$new_id,'new_total'=>$new_total,'fully_settled'=>$fully,'cheque_total'=>$chq_total]);
    exit;
}

/* ══ AJAX — delete_sb_settlement_payment ══ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_sb_settlement_payment') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');
    $pid = intval($_POST['payment_id'] ?? 0);
    $cid = intval($_POST['cheque_id']  ?? 0);
    if (!$pid || !$cid) { echo json_encode(['success'=>false,'error'=>'Invalid']); exit; }
    $pr = mysqli_query($conn, "SELECT id FROM cheque_sb_settlement_payments WHERE id=$pid AND cheque_id=$cid LIMIT 1");
    if (!$pr || mysqli_num_rows($pr) === 0) { echo json_encode(['success'=>false,'error'=>'Not found']); exit; }
    mysqli_query($conn, "DELETE FROM cheque_sb_settlement_payments WHERE id=$pid AND cheque_id=$cid");
    $tr = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS tot FROM cheque_sb_settlement_payments WHERE cheque_id=$cid");
    $new_total = $tr ? (float)mysqli_fetch_assoc($tr)['tot'] : 0;
    $chq_r = mysqli_query($conn, "SELECT total_amount FROM cheques WHERE id=$cid LIMIT 1");
    $chq_total = $chq_r ? (float)mysqli_fetch_assoc($chq_r)['total_amount'] : 0;
    $fully = ($new_total >= $chq_total && $chq_total > 0) ? 1 : 0;
    mysqli_query($conn, "UPDATE cheques SET sb_settlement_amount=$new_total, sb_settled=$fully WHERE id=$cid");
    echo json_encode(['success'=>true,'new_total'=>$new_total,'fully_settled'=>$fully,'cheque_total'=>$chq_total]);
    exit;
}

/* ══ AJAX — mark_to_be_deposit ══ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'mark_to_be_deposit') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');
    $cid           = intval($_POST['cheque_id'] ?? 0);
    $received_date = mysqli_real_escape_string($conn, trim($_POST['received_date'] ?? ''));
    if (!$cid)           { echo json_encode(['success'=>false,'error'=>'Invalid cheque ID']); exit; }
    if (!$received_date) { echo json_encode(['success'=>false,'error'=>'Received date is required']); exit; }
    $chk = mysqli_query($conn, "SELECT id, cheque_no, status FROM cheques WHERE id=$cid LIMIT 1");
    if (!$chk || mysqli_num_rows($chk) === 0) { echo json_encode(['success'=>false,'error'=>'Cheque not found']); exit; }
    $chq_row = mysqli_fetch_assoc($chk);
    if ($chq_row['status'] !== 'sent_back') { echo json_encode(['success'=>false,'error'=>'Not in sent_back status (current: '.$chq_row['status'].')']); exit; }
    $ok = mysqli_query($conn, "UPDATE cheques SET status='pending', received_date='$received_date' WHERE id=$cid");
    if (!$ok) { echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]); exit; }
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
        id INT AUTO_INCREMENT PRIMARY KEY, cheque_id INT NOT NULL,
        action VARCHAR(100) NOT NULL, old_value TEXT, new_value TEXT, note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_cid (cheque_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $cu = mysqli_real_escape_string($conn, get_current_user_label());
    $note = mysqli_real_escape_string($conn, "Marked pending | Received date: $received_date");
    mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id,action,old_value,new_value,note,created_by)
        VALUES ($cid,'pending','sent_back','pending','$note','$cu')");
    echo json_encode(['success'=>true,'cheque_id'=>$cid,'cheque_no'=>$chq_row['cheque_no'],'new_status'=>'pending','received_date'=>$received_date]);
    exit;
}

/* ══ NORMAL PAGE ══ */
include 'config.php';
include 'header.php';

foreach (['sb_settled'=>'TINYINT(1) DEFAULT 0','sb_settlement_date'=>'DATE NULL','sb_settlement_amount'=>'DECIMAL(12,2) DEFAULT 0.00','sb_settlement_note'=>'TEXT NULL'] as $col=>$def) {
    $chk=mysqli_query($conn,"SHOW COLUMNS FROM cheques LIKE '$col'");
    if(!$chk||mysqli_num_rows($chk)===0) @mysqli_query($conn,"ALTER TABLE cheques ADD COLUMN $col $def");
}

$bank_res  = mysqli_query($conn, "SELECT DISTINCT bank_code FROM cheques WHERE status='sent_back' AND bank_code IS NOT NULL AND bank_code!='' ORDER BY bank_code");
$all_banks = [];
if ($bank_res) while ($r = mysqli_fetch_assoc($bank_res)) $all_banks[] = $r['bank_code'];

$banks_list = [];
$br = mysqli_query($conn, "SELECT id,bank_code,bank_name FROM banks WHERE active=1 ORDER BY bank_name");
if ($br) while ($b = mysqli_fetch_assoc($br)) $banks_list[] = $b;

$summary_r = mysqli_query($conn, "SELECT COUNT(*) AS total_cnt, COALESCE(SUM(total_amount),0) AS total_amt,
    COUNT(CASE WHEN COALESCE(sb_settled,0)=1 THEN 1 END) AS settled_cnt,
    COALESCE(SUM(CASE WHEN COALESCE(sb_settled,0)=1 THEN total_amount ELSE 0 END),0) AS settled_amt,
    COUNT(CASE WHEN COALESCE(sb_settled,0)=0 THEN 1 END) AS unsettled_cnt,
    COALESCE(SUM(CASE WHEN COALESCE(sb_settled,0)=0 THEN total_amount ELSE 0 END),0) AS unsettled_amt
    FROM cheques WHERE status='sent_back'");
$summary = $summary_r ? mysqli_fetch_assoc($summary_r) : ['total_cnt'=>0,'total_amt'=>0,'settled_cnt'=>0,'settled_amt'=>0,'unsettled_cnt'=>0,'unsettled_amt'=>0];
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
.filter-row{display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1fr auto;gap:10px;align-items:end}
.ffg{display:flex;flex-direction:column;gap:5px}
.ffg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em}
.ffg input,.ffg select{border:1px solid #e5e5e5;border-radius:7px;padding:8px 11px;font-size:13px;font-family:inherit;color:#1f2937;width:100%;transition:border .2s}
.ffg input:focus,.ffg select:focus{outline:none;border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.1)}
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .2s;white-space:nowrap}
.btn-primary{background:#7c3aed;color:#fff}.btn-primary:hover{background:#5b21b6}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover{background:#e8e8e8}
.btn-success{background:#16a34a;color:#fff}.btn-success:hover{background:#15803d}
.btn-sm{padding:5px 12px;font-size:11px}
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.04);cursor:pointer;transition:all .15s}
.stat-card:hover{border-color:#7c3aed;box-shadow:0 2px 8px rgba(124,58,237,.15)}
.stat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
.stat-value{font-size:19px;font-weight:800;color:#1f2937;line-height:1}
.stat-card-amt{font-size:10px;color:#9ca3af;margin-top:3px}
.sv-violet{color:#7c3aed}.sv-green{color:#16a34a}.sv-amber{color:#d97706}
.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #f0f0f0;background:#fafafa;flex-wrap:wrap;gap:8px}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap}
.p-violet{background:#ede9fe;color:#5b21b6}
.search-bar-wrap{display:flex;align-items:center;gap:8px}
.chq-search-box{position:relative;display:flex;align-items:center}
.chq-search-box input{border:1.5px solid #ddd6fe;border-radius:8px;padding:7px 12px 7px 34px;font-size:12.5px;font-family:inherit;color:#1f2937;width:260px;transition:all .2s;background:#fff;outline:none}
.chq-search-box input:focus{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.1);width:310px}
.chq-search-box .si{position:absolute;left:10px;color:#9ca3af;font-size:12px;pointer-events:none}
.chq-search-box .clr-btn{position:absolute;right:8px;color:#9ca3af;font-size:11px;background:none;border:none;cursor:pointer;padding:2px;display:none}
.chq-search-box .clr-btn.show{display:block}
.pager{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.pager-btn{background:#fff;border:1.5px solid #ddd6fe;border-radius:6px;padding:4px 10px;font-size:11.5px;font-weight:600;color:#7c3aed;cursor:pointer;transition:all .15s;white-space:nowrap;font-family:inherit}
.pager-btn:hover{background:#ede9fe;border-color:#a78bfa}
.pager-btn.active{background:#7c3aed;color:#fff;border-color:#7c3aed}
.pager-btn:disabled{opacity:.4;cursor:not-allowed}
.pager-info{font-size:11px;color:#6b7280;white-space:nowrap}
.dt-outer{overflow-x:auto;max-height:70vh;overflow-y:auto}
.data-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1400px}
.data-table thead th{padding:9px 8px;text-align:left;font-weight:700;font-size:10.5px;color:#fff;background:#3b0764;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);position:sticky;top:0;z-index:10}
.data-table thead th:last-child{border-right:none}
.data-table thead th.tr{text-align:right}.data-table thead th.tc{text-align:center}
.data-table tbody tr{border-bottom:1px solid #f0f2f5;transition:background .12s}
.data-table tbody tr:hover td{background:#faf5ff!important}
.data-table td{padding:7px 8px;color:#374151;vertical-align:middle;background:#fff}
.tr{text-align:right}.tc{text-align:center}
.data-table tfoot td{padding:10px 8px;font-weight:800;font-size:12px;background:#1e0042;color:#d8b4fe;border-top:2px solid #3b0764;position:sticky;bottom:0}
.data-table tfoot td.tr{text-align:right}
.mono{font-family:'Courier New',monospace;font-weight:700;letter-spacing:.02em}
.sr-pill{background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700;white-space:nowrap}
.date-txt{font-size:11.5px;color:#374151;white-space:nowrap}
.date-txt.empty{color:#d1d5db}
.amt-cell{font-weight:700;color:#7c3aed;white-space:nowrap}
.cust-sub{font-size:10px;color:#6b7280;margin-top:2px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.reason-chip{display:inline-block;background:#fdf4ff;border:1px solid #e879f9;color:#701a75;padding:2px 8px;border-radius:5px;font-size:10px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.settled-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:700;white-space:nowrap}
.settled-badge.yes{background:#dcfce7;color:#166534;border:1px solid #86efac}
.settled-badge.no{background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd}
.settle-meta{font-size:10px;margin-top:3px;line-height:1.4}
.sentback-date-chip{display:inline-flex;align-items:center;gap:4px;background:#fdf4ff;border:1px solid #d8b4fe;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:600;color:#5b21b6;white-space:nowrap}
.sentback-date-chip.empty{background:#f9fafb;border-color:#e5e7eb;color:#9ca3af}
.aging-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:700;white-space:nowrap;letter-spacing:.01em}
.aging-chip.fresh{background:#dcfce7;color:#166534;border:1px solid #86efac}
.aging-chip.warn {background:#fef9c3;color:#854d0e;border:1px solid #fde047}
.aging-chip.aged {background:#ffedd5;color:#9a3412;border:1px solid #fdba74}
.aging-chip.old  {background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.aging-chip.crit {background:#3b0764;color:#e9d5ff;border:1px solid #7c3aed}
.aging-chip.none {background:#f3f4f6;color:#9ca3af;border:1px solid #e5e7eb}
.sbi-code{font-family:'Courier New',monospace;font-size:10px;font-weight:700;color:#4338ca;background:#ede9fe;border:1px solid #c4b5fd;border-radius:5px;padding:1px 6px;display:inline-block;margin-top:2px}
.btn-loadpay{display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;border:none;border-radius:6px;padding:5px 11px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;transition:filter .2s}
.btn-loadpay:hover{filter:brightness(1.1)}
.btn-loadpay.settled{background:linear-gradient(135deg,#6b7280,#9ca3af);cursor:default}
.btn-loadpay.settled:hover{filter:none}
.btn-tbd{display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#0891b2,#06b6d4);color:#fff;border:none;border-radius:6px;padding:5px 11px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;transition:filter .2s;margin-top:4px}
.btn-tbd:hover{filter:brightness(1.1)}
.tbl-loading{display:none;position:absolute;inset:0;background:rgba(255,255,255,.8);z-index:50;align-items:center;justify-content:center;flex-direction:column;gap:10px;font-size:13px;color:#7c3aed;font-weight:600;border-radius:10px}
.tbl-loading.show{display:flex}
.tbl-wrap{position:relative}
.tbl-spinner{width:36px;height:36px;border:4px solid #ede9fe;border-top-color:#7c3aed;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.state-box{text-align:center;padding:80px 20px;color:#9ca3af}
.state-box i{font-size:48px;display:block;margin-bottom:16px;opacity:.3}
.state-box p{font-size:14px;font-weight:500}
/* Modals */
.modal-backdrop{position:fixed!important;inset:0!important;z-index:2147483647!important;background:rgba(0,0,0,.6);display:none;align-items:center;justify-content:center;padding:16px}
.modal-backdrop.open{display:flex!important}
body.modal-sb-open{overflow:hidden!important}
.modal-dialog{background:#fff;border-radius:14px;width:100%;max-width:1000px;max-height:94vh;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.3);overflow:hidden}
.modal-header{display:flex;align-items:flex-start;justify-content:space-between;padding:16px 22px;border-bottom:1px solid #e5e5e5;background:#faf5ff;flex-shrink:0}
.modal-header-left{display:flex;flex-direction:column;gap:4px;flex:1}
.modal-header-left h3{font-size:17px;font-weight:700;color:#5b21b6;margin:0}
.inv-summary-strip{display:flex;gap:0;flex-wrap:wrap;margin-top:10px;border:1px solid #ddd6fe;border-radius:8px;overflow:hidden}
.inv-sum-item{flex:1;display:flex;flex-direction:column;padding:10px 16px;border-right:1px solid #ddd6fe;min-width:110px}
.inv-sum-item:last-child{border-right:none}
.inv-sum-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
.inv-sum-value{font-size:16px;font-weight:800;color:#1f2937}
.inv-sum-value.green{color:#166534}.inv-sum-value.violet{color:#5b21b6}
.modal-close{width:32px;height:32px;border-radius:7px;border:1px solid #ddd6fe;background:#fff;color:#7c3aed;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0;margin-left:12px}
.modal-close:hover{background:#ede9fe}
.modal-body{overflow-y:auto;flex:1;padding:0}
.pay-section-wrap{padding:20px 22px}
.pay-block{margin-bottom:20px}
.pay-block-title{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;padding:10px 14px;border-radius:7px;margin-bottom:14px;display:flex;align-items:center;gap:7px}
.pay-block-title.cash{background:#dcfce7;color:#166534;border-left:4px solid #22c55e}
.pay-block-title.cheque{background:#dbeafe;color:#1e40af;border-left:4px solid #3b82f6}
.pay-block-title.sentback{background:#fdf4ff;color:#701a75;border-left:4px solid #c026d3}
.pay-block-title.collector{background:#f0f9ff;color:#0369a1;border-left:4px solid #0ea5e9}
.fg{display:flex;flex-direction:column;gap:5px}
.fg label{font-size:12px;font-weight:600;color:#374151}
.fg label .req{color:#ef4444;margin-left:2px}
.fctrl{padding:8px 10px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;font-family:inherit;color:#333;background:#fff;width:100%;box-sizing:border-box;outline:none;transition:border-color .2s}
.fctrl:focus{border-color:#7c3aed}
select.fctrl{cursor:pointer}
.gr{display:grid;gap:12px;margin-bottom:12px}
.gr2{grid-template-columns:1fr 1fr}.gr3{grid-template-columns:1fr 1fr 1fr}.gr4{grid-template-columns:1fr 1fr 1fr 1fr}
.pay-divider{text-align:center;position:relative;margin:18px 0}
.pay-divider::before{content:'';position:absolute;top:50%;left:0;right:0;height:1px;background:#ddd6fe}
.pay-divider span{position:relative;background:#fff;padding:0 12px;font-size:11px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.07em}
.cheque-card{background:#f8f7ff;border:1px solid #ddd6fe;border-radius:8px;padding:14px;margin-bottom:12px}
.cheque-card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.cheque-card-title{font-size:12px;font-weight:700;color:#5b21b6;display:flex;align-items:center;gap:6px}
.btn-remove-cheque{background:#ef4444;color:#fff;border:none;padding:3px 9px;border-radius:4px;font-size:11px;cursor:pointer;display:inline-flex;align-items:center;gap:3px;font-family:inherit}
.btn-remove-cheque:hover{background:#dc2626}
.btn-add-cheque{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:1.5px dashed #7c3aed;border-radius:6px;background:#faf5ff;color:#7c3aed;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .2s}
.btn-add-cheque:hover{background:#f3e8ff}
.dup-cheque-warn{background:#fef2f2;border:1px solid #fecaca;border-radius:5px;padding:5px 9px;font-size:11px;color:#991b1b;display:none;margin-top:4px}
.dup-cheque-warn.show{display:block}
.cust-info-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;background:#faf5ff;border:1px solid #ddd6fe;border-radius:8px;padding:12px;margin-bottom:14px}
.ci-item{text-align:center}
.ci-label{font-size:10px;font-weight:600;color:#7c3aed;text-transform:uppercase;letter-spacing:.05em}
.ci-value{font-size:15px;font-weight:700;color:#5b21b6;margin-top:2px}
.sentback-banner{background:linear-gradient(135deg,#fdf4ff,#f3e8ff);border:1.5px solid #d8b4fe;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.sb-item{display:flex;flex-direction:column;gap:2px}
.sb-lbl{font-size:10px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.05em}
.sb-val{font-size:13px;font-weight:700;color:#3b0764}
.sb-reason-box{grid-column:span 4;background:#fff;border:1px solid #e9d5ff;border-radius:6px;padding:7px 12px;font-size:12px;color:#5b21b6;font-style:italic}
.no-charge-notice{background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1.5px solid #86efac;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#166534;font-weight:600;display:flex;align-items:center;gap:8px}
.spm-block{background:#fff;border:1.5px solid #e0e7ef;border-radius:10px;margin-bottom:14px;overflow:hidden}
.spm-header{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#f5f3ff;border-bottom:1px solid #e0e7ef}
.spm-title{font-size:13px;font-weight:700;color:#5b21b6;display:flex;align-items:center;gap:6px}
.spm-table{width:100%;border-collapse:collapse;font-size:12px}
.spm-table th{background:#f8fafc;color:#6b7280;font-weight:600;padding:7px 10px;text-align:left;border-bottom:1px solid #e5e7eb;white-space:nowrap}
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
.rchq-card{background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:8px;padding:10px 12px;min-width:200px}
.rchq-no{font-family:'Courier New',monospace;font-weight:800;font-size:13px;color:#1e40af;letter-spacing:.04em;display:flex;align-items:center;gap:5px;margin-bottom:6px}
.rchq-grid{display:grid;grid-template-columns:1fr 1fr;gap:4px 10px}
.rchq-row{display:flex;flex-direction:column;gap:1px}
.rchq-lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#3b82f6}
.rchq-val{font-size:11px;font-weight:600;color:#1e3a5f}
.bal-summary{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;margin-top:18px}
.bal-sum-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;font-size:13px;color:#374151;border-bottom:1px solid #f0f0f0}
.bal-sum-row:last-child{border-bottom:none}
.bal-sum-row.total{font-weight:700;color:#1f2937;padding-top:8px;margin-top:4px;border-top:2px solid #e2e8f0;border-bottom:none}
.bal-sum-row.balance strong{color:#7c3aed;font-size:15px}
.modal-footer{padding:13px 22px;border-top:1px solid #ddd6fe;background:#faf5ff;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-shrink:0}
.modal-footer-right{display:flex;gap:8px}
.btn-modal-cancel{padding:8px 16px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#555;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer}
.btn-modal-cancel:hover{background:#f5f5f5}
/* ── Return box inside the settle/pay modal (issued-cheque rule) ── */
.modal-return-box{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:10px 14px;border-radius:9px;margin-bottom:14px;}
.modal-return-box.mrb-issued{background:#fff7ed;border:1px solid #fed7aa;}
.modal-return-box.mrb-auto{background:#f0fdf4;border:1px solid #86efac;}
.modal-return-box.mrb-done{background:#dcfce7;border:1px solid #86efac;}
.modal-return-box .mrb-label{font-size:12px;font-weight:700;color:#92400e;display:flex;align-items:center;gap:6px;}
.modal-return-box.mrb-auto .mrb-label,.modal-return-box.mrb-done .mrb-label{color:#166534;}
.modal-return-box .mrb-check-label{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:#92400e;cursor:pointer;background:#fff;border:1.5px solid #fdba74;padding:6px 12px;border-radius:7px;}
.modal-return-box .mrb-check-label input{width:15px;height:15px;cursor:pointer;accent-color:#d97706;}
.modal-return-box .mrb-check-label.checked{background:#fef3c7;border-color:#d97706;}
.btn-modal-settle{padding:9px 20px;border-radius:6px;border:none;background:#7c3aed;color:#fff;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;display:flex;align-items:center;gap:6px}
.btn-modal-settle:hover{background:#5b21b6}
.btn-modal-settle:disabled{opacity:.55;cursor:not-allowed}
.spinner{border:3px solid rgba(255,255,255,.3);border-top:3px solid #fff;border-radius:50%;width:16px;height:16px;animation:spin 1s linear infinite;display:inline-block;vertical-align:middle}
#sbToast{position:fixed;bottom:28px;right:28px;z-index:99999;padding:12px 22px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 6px 24px rgba(0,0,0,.2);color:#fff;transform:translateY(80px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none}
#sbToast.show{transform:translateY(0);opacity:1}
.select2-container--default .select2-selection--single{height:37px!important;border:1px solid #e0e0e0!important;border-radius:6px!important}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:35px!important;padding-left:10px!important;font-size:13px!important;color:#333!important;font-family:inherit!important}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:35px!important}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:#7c3aed!important}
.select2-dropdown{border:1px solid #e0e0e0!important;border-radius:6px!important;font-size:13px!important}
.select2-results__option--highlighted{background:#7c3aed!important}
.tbd-modal-dialog{background:#fff;border-radius:14px;width:100%;max-width:480px;box-shadow:0 24px 80px rgba(0,0,0,.3);overflow:hidden}
.tbd-modal-header{display:flex;align-items:center;justify-content:space-between;padding:16px 22px;border-bottom:1px solid #e5e5e5;background:linear-gradient(135deg,#ecfeff,#cffafe)}
.tbd-modal-header h3{font-size:16px;font-weight:700;color:#0e7490;margin:0;display:flex;align-items:center;gap:8px}
.tbd-modal-body{padding:20px 22px}
.tbd-cheque-info{background:#f0fdfa;border:1.5px solid #99f6e4;border-radius:10px;padding:14px 16px;margin-bottom:18px;display:grid;grid-template-columns:1fr 1fr;gap:10px}
.tbd-info-item{display:flex;flex-direction:column;gap:2px}
.tbd-info-label{font-size:10px;font-weight:700;color:#0d9488;text-transform:uppercase;letter-spacing:.05em}
.tbd-info-value{font-size:13px;font-weight:700;color:#134e4a}
.tbd-modal-footer{display:flex;align-items:center;justify-content:flex-end;gap:8px;padding:14px 22px;border-top:1px solid #e5e5e5;background:#f8fdfc}
.btn-tbd-confirm{padding:9px 20px;border-radius:6px;border:none;background:linear-gradient(135deg,#0891b2,#06b6d4);color:#fff;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;display:flex;align-items:center;gap:6px;transition:filter .2s}
.btn-tbd-confirm:hover{filter:brightness(1.1)}
.btn-tbd-confirm:disabled{opacity:.55;cursor:not-allowed}
/* ── Collector type toggle ── */
.collector-type-row{display:flex;align-items:center;gap:10px;margin-bottom:14px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;}
.collector-type-label{font-size:12px;font-weight:700;color:#374151;white-space:nowrap;}
.collector-type-btns{display:flex;gap:6px;}
.ctype-btn{padding:6px 18px;border-radius:6px;border:1.5px solid #e0e0e0;background:#fff;font-size:12px;font-weight:700;font-family:inherit;cursor:pointer;color:#6b7280;transition:all .2s;}
.ctype-btn:hover{border-color:#0ea5e9;color:#0369a1;}
.ctype-btn.active-cc{border-color:#0ea5e9;background:#e0f2fe;color:#0369a1;}
.ctype-btn.active-sr{border-color:#7c3aed;background:#ede9fe;color:#5b21b6;}
.dp-modal-status{font-size:10px;color:#9ca3af;min-height:14px;display:block;margin-top:2px;line-height:1.3;}
.dp-modal-status.ok{color:#22c55e;font-weight:700;}
.dp-modal-status.err{color:#e53935;}
/* ── Return Issue modal option cards ── */
.ri-opt-label{transition:all .15s;}
.ri-opt-label:hover{border-color:#c4c4c4!important;}
@media(max-width:1000px){.stat-grid{grid-template-columns:1fr 1fr}}
@media(max-width:640px){.filter-row{grid-template-columns:1fr 1fr}.gr2,.gr3,.gr4{grid-template-columns:1fr}}
@media print{.no-print{display:none!important}.data-table thead th{background:#3b0764!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}.data-table tfoot td{background:#1e0042!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>

<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:12px;" class="no-print">
  <div>
    <h2 class="page-title"><i class="fa-solid fa-rotate-left" style="color:#7c3aed;"></i> Sent Back Cheques</h2>
    <p class="page-subtitle">All cheques sent back to the customer — use <strong>Load Pay</strong> to collect replacement payment. <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:700;"><i class="fa-solid fa-circle-check"></i> No return charge applies</span></p>
  </div>
  <div style="display:flex;gap:8px;" class="no-print">
    <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</button>
    <button onclick="exportCSV()" class="btn btn-success btn-sm"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
  </div>
</div>

<!-- ISSUE TOOLBAR -->
<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:10px 16px;background:#f5f3ff;border:1.5px solid #c4b5fd;border-radius:10px;flex-wrap:wrap;" class="no-print" id="issueToolbar">
  <div style="display:flex;align-items:center;gap:6px;flex:1;">
    <i class="fa-solid fa-paper-plane" style="color:#6366f1;font-size:15px;"></i>
    <span style="font-size:13px;font-weight:700;color:#3730a3;">Cheque Issue Management</span>
    <span id="issueToolbarBadge" style="background:#6366f1;color:#fff;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;display:none;">0 selected</span>
  </div>
  <button onclick="openIssueDrawer()" class="btn btn-sm" style="background:#6366f1;color:#fff;display:inline-flex;align-items:center;gap:5px;">
    <i class="fa-solid fa-paper-plane"></i> Issue Cheques
  </button>
  <button onclick="openHistoryDrawer()" class="btn btn-sm" style="background:#1e1b4b;color:#fff;display:inline-flex;align-items:center;gap:5px;">
    <i class="fa-solid fa-clock-rotate-left"></i> Issue History
  </button>
</div>

<!-- FILTERS -->
<div class="filter-card no-print">
  <div class="filter-header" id="filterHeader" onclick="toggleFilter()">
    <div class="filter-title"><i class="fa-solid fa-sliders" style="color:#7c3aed;"></i> Filters</div>
    <i class="fa-solid fa-chevron-down filter-toggle-icon" id="filterIcon"></i>
  </div>
  <div class="filter-body" id="filterBody">
    <div class="filter-row">
      <div class="ffg"><label><i class="fa-solid fa-building-columns"></i> Bank Code</label>
        <select id="selBank" style="width:100%;"><option value="">— All Banks —</option>
          <?php foreach($all_banks as $bk): ?><option value="<?=htmlspecialchars($bk)?>"><?=htmlspecialchars($bk)?></option><?php endforeach; ?>
        </select></div>
      <div class="ffg"><label><i class="fa-solid fa-user-tag"></i> T-Code</label><input type="text" id="fTcode" placeholder="Search T-Code..."></div>
      <div class="ffg"><label><i class="fa-solid fa-calendar-day"></i> Cheque Date From</label><input type="date" id="fFrom"></div>
      <div class="ffg"><label><i class="fa-solid fa-calendar-day"></i> Cheque Date To</label><input type="date" id="fTo"></div>
      <div class="ffg"><label><i class="fa-solid fa-circle-half-stroke"></i> Settlement</label>
        <select id="selSettled" style="width:100%;"><option value="">— All —</option><option value="0">Unsettled Only</option><option value="1">Settled Only</option></select></div>
      <div class="ffg" style="flex-direction:row;gap:8px;align-items:flex-end;">
        <button type="button" class="btn btn-primary" onclick="applyFilters()" style="flex:1;"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
        <button type="button" class="btn btn-secondary" onclick="clearFilters()" title="Clear"><i class="fa-solid fa-rotate-left"></i></button>
      </div>
    </div>
  </div>
</div>

<!-- STAT CARDS -->
<div class="stat-grid">
  <div class="stat-card" onclick="clearFilters()">
    <div class="stat-label"><i class="fa-solid fa-rotate-left" style="color:#7c3aed;"></i> Total Sent Back</div>
    <div class="stat-value sv-violet"><?=$summary['total_cnt']?></div>
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
  <div class="stat-card" style="border-color:#7c3aed;background:#faf5ff;">
    <div class="stat-label" style="color:#5b21b6;">Filtered Results</div>
    <div class="stat-value sv-violet" id="filteredCount">0</div>
    <div class="stat-card-amt" id="filteredAmt">Rs. 0</div>
  </div>
</div>

<!-- TABLE CARD -->
<div class="table-card tbl-wrap">
  <div class="tbl-loading" id="tblLoading"><div class="tbl-spinner"></div><span>Loading sent back cheques…</span></div>
  <div class="table-toolbar no-print" style="gap:10px;">
    <div class="tbl-title"><i class="fa-solid fa-table-list"></i> Sent Back Cheques <span class="pill p-violet" id="visCount">0 records</span></div>
    <div class="search-bar-wrap">
      <div class="chq-search-box">
        <i class="fa-solid fa-magnifying-glass si"></i>
        <input type="text" id="chqSearch" placeholder="Search cheque no, customer, reason…" autocomplete="off" spellcheck="false">
        <button class="clr-btn" id="chqClr" onclick="clearSearch()"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>
  </div>
  <div class="table-toolbar no-print" id="pagerWrap" style="justify-content:space-between;padding:7px 14px;display:none;">
    <div class="pager" id="pager"></div><span class="pager-info" id="pagerInfo"></span>
  </div>
  <div class="dt-outer">
  <table class="data-table" id="mainTable">
    <thead>
      <tr>
        <th style="width:32px;" class="tc no-print"><input type="checkbox" id="sbSelAll" onchange="sbToggleAll()" style="accent-color:#6366f1;"></th>
        <th style="width:32px;">#</th>
        <th class="tc">SR Code</th>
        <th class="tc">Cheque Date</th>
        <th class="tc">Received Date</th>
        <th class="tc">Sent Back Date</th>
        <th class="tc">Aging Days</th>
        <th>Cheque No.</th>
        <th>Bank / Branch</th>
        <th class="tc">Bank Code</th>
        <th class="tr">Amount</th>
        <th>T-Code / Customer</th>
        <th>Invoice</th>
        <th>Sent Back Reason</th>
        <th class="tc">Settlement</th>
        <th class="tc">Issue Status</th>
        <th class="tc no-print">Action</th>
      </tr>
    </thead>
    <tbody id="mainTbody">
      <tr><td colspan="17" style="text-align:center;padding:60px 20px;color:#9ca3af;">
        <i class="fa-solid fa-spinner fa-spin" style="font-size:32px;display:block;margin-bottom:12px;opacity:.5;"></i>
        <p>Loading sent back cheques…</p>
      </td></tr>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="10" class="no-print"></td>
        <td class="tr">Rs.&nbsp;<span id="footerTotal">0.00</span></td>
        <td colspan="6" style="font-size:11px;opacity:.65;">TOTAL — <span id="footerCount">0</span> CHEQUES</td>
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
      <h3><i class="fa-solid fa-rotate-left" style="color:#7c3aed;margin-right:4px;"></i> Settle Sent Back Cheque</h3>
      <p id="modalSubtitle" style="font-size:12px;color:#5b21b6;margin:0;">—</p>
      <div class="inv-summary-strip">
        <div class="inv-sum-item"><span class="inv-sum-label">Invoice Amt</span><span class="inv-sum-value" id="hdrInv">—</span></div>
        <div class="inv-sum-item"><span class="inv-sum-label">Total Paid</span><span class="inv-sum-value green" id="hdrPaid">—</span></div>
        <div class="inv-sum-item"><span class="inv-sum-label">Balance</span><span class="inv-sum-value violet" id="hdrBal">—</span></div>
        <div class="inv-sum-item"><span class="inv-sum-label">Sent Back Amt</span><span class="inv-sum-value violet" id="hdrSentBack">—</span></div>
      </div>
    </div>
    <button class="modal-close" onclick="closeSettleModal()"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="modal-body">
    <div class="pay-section-wrap">
      <div class="no-charge-notice"><i class="fa-solid fa-circle-check"></i><span>No return charge applies. Collect only the original cheque amount.</span></div>
      <div class="sentback-banner">
        <div class="sb-item"><span class="sb-lbl">Cheque No.</span><span class="sb-val" id="bnrChequeNo">—</span></div>
        <div class="sb-item"><span class="sb-lbl">Cheque Date</span><span class="sb-val" id="bnrChequeDate">—</span></div>
        <div class="sb-item"><span class="sb-lbl">Bank</span><span class="sb-val" id="bnrBank">—</span></div>
        <div class="sb-item"><span class="sb-lbl">Sent Back Amount</span><span class="sb-val" id="bnrAmt">—</span></div>
        <div class="sb-reason-box" id="bnrReason" style="display:none;"></div>
      </div>

      <!-- ── ISSUED-CHEQUE RETURN BOX ── -->
      <div class="modal-return-box no-print" id="modalReturnBox" style="display:none;"></div>

      <!-- ── COLLECTOR DETAILS BLOCK (new) ── -->
      <div class="pay-block">
        <div class="pay-block-title collector"><i class="fa-solid fa-person-biking"></i> Collector Details</div>
        <div class="collector-type-row">
          <span class="collector-type-label"><i class="fa-solid fa-user-tag"></i> Collected By:</span>
          <div class="collector-type-btns">
            <button type="button" class="ctype-btn active-cc" id="sbCtypeCC" onclick="sbSetCollectorType('cc')">
              <i class="fa-solid fa-person-biking"></i> CC — Cash Collector
            </button>
            <button type="button" class="ctype-btn" id="sbCtypeSR" onclick="sbSetCollectorType('sr')">
              <i class="fa-solid fa-id-badge"></i> SR — Sales Rep
            </button>
          </div>
        </div>
        <!-- CC mode -->
        <div id="sbDpWrap" class="gr gr2" style="margin-bottom:0;">
          <div class="fg">
            <label>Delivery Person <span class="req">*</span></label>
            <select class="fctrl" id="sbDpSelect" style="width:100%;">
              <option value="">-- Select Delivery Person --</option>
            </select>
            <span class="dp-modal-status" id="sbDpStatus"></span>
          </div>
          <div class="fg">
            <label>Employee <span style="font-size:10px;font-weight:400;color:#9ca3af;">(optional)</span></label>
            <select id="sbEmpSelect" style="width:100%;">
              <option value="">-- Select Employee --</option>
            </select>
          </div>
        </div>
        <!-- SR mode -->
        <div id="sbSrWrap" style="display:none;" class="gr gr2" style="margin-bottom:0;">
          <div class="fg">
            <label>SR Code <span class="req">*</span></label>
            <select class="fctrl" id="sbSrSelect" style="width:100%;">
              <option value="">-- Select SR Code --</option>
            </select>
          </div>
          <div class="fg">
            <label>Employee <span style="font-size:10px;font-weight:400;color:#9ca3af;">(optional)</span></label>
            <select id="sbSrEmpSelect" style="width:100%;">
              <option value="">-- Select Employee --</option>
            </select>
          </div>
        </div>
      </div>
      <!-- ── END COLLECTOR DETAILS BLOCK ── -->

      <div class="pay-block">
        <div class="pay-block-title cash"><i class="fa-solid fa-coins"></i> Cash Settlement</div>
        <div class="gr gr4">
          <div class="fg"><label>Payment Date</label><input type="date" class="fctrl" id="cashDate"></div>
          <div class="fg"><label>Cash Amount (Rs.)</label><input type="number" class="fctrl" id="cashAmount" step="0.01" min="0" placeholder="0.00" oninput="syncPreviews()"></div>
          <div class="fg"><label>Amount to Bank (Rs.)</label><input type="number" class="fctrl" id="cashToBank" step="0.01" min="0" placeholder="0.00"></div>
          <div class="fg"><label>Reference No.</label><input type="text" class="fctrl" id="cashRef" placeholder="Optional"></div>
        </div>
        <div class="gr gr2">
          <div class="fg"><label>Collected By</label>
            <select class="fctrl" id="cashCollectedBy"><option value="cc" selected>CC — Cash Collector</option><option value="sr">SR — Sales Rep</option><option value="area_manager">Area Manager</option><option value="office">Office</option><option value="other">Other</option></select></div>
          <div class="fg"><label>Remarks</label><input type="text" class="fctrl" id="cashRemarks" placeholder="Notes..."></div>
        </div>
      </div>
      <div class="pay-divider"><span>+ Replacement Cheque (optional)</span></div>
      <div class="pay-block">
        <div class="pay-block-title cheque"><i class="fa-solid fa-money-check"></i> New Replacement Cheque</div>
        <div class="cust-info-strip">
          <div class="ci-item"><div class="ci-label">Credit Limit</div><div class="ci-value" id="chqLimit">—</div></div>
          <div class="ci-item"><div class="ci-label">Policy Days</div><div class="ci-value" id="chqDays">—</div></div>
          <div class="ci-item"><div class="ci-label">Special Days</div><div class="ci-value" id="chqSpecial">—</div></div>
        </div>
        <div class="gr gr3">
          <div class="fg"><label>Cheque Received Date</label><input type="date" class="fctrl" id="chqPayDate"></div>
          <div class="fg"><label>Reference No.</label><input type="text" class="fctrl" id="chqRef" placeholder="Optional"></div>
          <div class="fg"><label>Cheque Mode</label>
            <select class="fctrl" id="chqModeSelect"><option value="payee_only">Payee Only</option><option value="cash">Cash</option><option value="third_party_cash">Third Party Cash</option></select></div>
        </div>
        <div id="chequesContainer"></div>
        <button type="button" class="btn-add-cheque" onclick="addCheque()"><i class="fa-solid fa-plus"></i> Add Cheque</button>
        <div class="fg" style="margin-top:10px;"><label>Remarks</label><input type="text" class="fctrl" id="chqRemarks" placeholder="Notes..."></div>
      </div>
      <div class="pay-block" style="margin-bottom:10px;">
        <div class="pay-block-title sentback"><i class="fa-solid fa-rotate-left"></i> Settlement Note</div>
        <div class="fg"><label>Settlement Note (optional)</label><input type="text" class="fctrl" id="settlementNote" placeholder="e.g. Replaced with cash, customer handed new cheque..."></div>
      </div>
      <div class="spm-block">
        <div class="spm-header"><span class="spm-title"><i class="fa-solid fa-list-check"></i> Settlement Payments</span><span id="spmTotalBadge" style="font-size:12px;color:#166534;font-weight:700;"></span></div>
        <div id="spmTableWrap"><div class="spm-empty"><i class="fa-solid fa-circle-info"></i> No payments recorded yet.</div></div>
      </div>
      <div class="bal-summary">
        <div class="bal-sum-row"><span><i class="fa-solid fa-coins" style="color:#22c55e;"></i> Cash entering</span><strong id="sumCash">Rs. 0.00</strong></div>
        <div class="bal-sum-row"><span><i class="fa-solid fa-money-check" style="color:#3b82f6;"></i> Cheque total</span><strong id="sumCheque">Rs. 0.00</strong></div>
        <div class="bal-sum-row total"><span><i class="fa-solid fa-sigma"></i> Total payment</span><strong id="sumTotal">Rs. 0.00</strong></div>
        <div class="bal-sum-row balance"><span><i class="fa-solid fa-hourglass-half"></i> Remaining balance after this</span><strong id="sumBalance">Rs. —</strong></div>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <div style="font-size:11px;color:#9ca3af;"><i class="fa-solid fa-info-circle"></i> No return charge — original amount only.</div>
    <div class="modal-footer-right">
      <button class="btn-modal-cancel" onclick="closeSettleModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="btn-modal-settle" id="submitSettleBtn" onclick="submitSettlement()"><i class="fa-solid fa-circle-check"></i> Save Payment</button>
    </div>
  </div>
</div>
</div>

<!-- PENDING MODAL -->
<div class="modal-backdrop" id="tbdModal">
<div class="tbd-modal-dialog">
  <div class="tbd-modal-header"><h3><i class="fa-solid fa-clock"></i> Change Status to Pending</h3><button class="modal-close" onclick="closeTbdModal()"><i class="fa-solid fa-xmark"></i></button></div>
  <div class="tbd-modal-body">
    <div class="tbd-cheque-info">
      <div class="tbd-info-item"><span class="tbd-info-label">Cheque No.</span><span class="tbd-info-value" id="tbdChequeNo">—</span></div>
      <div class="tbd-info-item"><span class="tbd-info-label">Amount</span><span class="tbd-info-value" id="tbdAmount">—</span></div>
      <div class="tbd-info-item"><span class="tbd-info-label">T-Code</span><span class="tbd-info-value" id="tbdTcode">—</span></div>
      <div class="tbd-info-item"><span class="tbd-info-label">Customer</span><span class="tbd-info-value" id="tbdCustomer">—</span></div>
      <div class="tbd-info-item"><span class="tbd-info-label">Cheque Date</span><span class="tbd-info-value" id="tbdChequeDate">—</span></div>
      <div class="tbd-info-item"><span class="tbd-info-label">Bank</span><span class="tbd-info-value" id="tbdBank">—</span></div>
    </div>
    <div class="fg">
      <label style="font-weight:700;color:#0e7490;">Cheque Received Date <span class="req">*</span></label>
      <input type="date" class="fctrl" id="tbdReceivedDate" style="border-color:#99f6e4;">
      <div style="font-size:11px;color:#6b7280;margin-top:4px;"><i class="fa-solid fa-info-circle"></i> Status will change to <strong style="color:#0891b2;">Pending</strong>.</div>
    </div>
    <input type="hidden" id="tbdChequeId" value="">
  </div>
  <div class="tbd-modal-footer">
    <button class="btn-modal-cancel" onclick="closeTbdModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
    <button class="btn-tbd-confirm" id="tbdConfirmBtn" onclick="submitToBeDeposit()"><i class="fa-solid fa-clock"></i> Confirm Pending</button>
  </div>
</div>
</div>

<div id="sbToast"></div>

<!-- ══ ISSUE DRAWER ══ -->
<div id="issueDrawerBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:10010;" onclick="closeIssueDrawer()"></div>
<div id="issueDrawer" style="position:fixed;right:0;top:0;bottom:0;width:480px;max-width:96vw;background:#fff;z-index:10011;display:flex;flex-direction:column;box-shadow:-6px 0 40px rgba(0,0,0,.2);transform:translateX(110%);transition:transform .32s cubic-bezier(.4,0,.2,1);">
    <div style="padding:16px 20px;background:#1e1b4b;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <div style="font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px;"><i class="fa-solid fa-paper-plane" style="color:#a5b4fc;"></i> Issue Sent Back Cheques <span id="issDrawerCount" style="background:rgba(255,255,255,.15);padding:1px 10px;border-radius:10px;font-size:12px;">0 selected</span></div>
        <button onclick="closeIssueDrawer()" style="background:none;border:none;color:#a5b4fc;font-size:20px;cursor:pointer;padding:2px;">&#x2715;</button>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #f0f0f0;flex-shrink:0;">
        <div style="padding:12px 16px;text-align:center;border-right:1px solid #f0f0f0;"><div id="issDrawerStatCount" style="font-size:20px;font-weight:800;color:#6366f1;">0</div><div style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-top:2px;">Cheques</div></div>
        <div style="padding:12px 16px;text-align:center;"><div id="issDrawerStatAmt" style="font-size:20px;font-weight:800;color:#7c3aed;">Rs. 0.00</div><div style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-top:2px;">Total Amount</div></div>
    </div>
    <div id="issDrawerList" style="flex:1;overflow-y:auto;padding:8px 0;">
        <div id="issDrawerEmpty" style="text-align:center;padding:50px 20px;color:#9ca3af;"><i class="fa-solid fa-inbox" style="font-size:36px;display:block;margin-bottom:12px;opacity:.3;"></i><p style="font-size:13px;color:#6b7280;margin:0 0 6px;">No cheques selected</p><small>Check rows then click "Issue Cheques"</small></div>
    </div>
    <div style="padding:14px 16px;border-top:2px solid #f0f0f0;display:flex;gap:8px;background:#fafafa;flex-shrink:0;">
        <button onclick="clearIssueSelection()" class="btn btn-secondary btn-sm" style="flex:1;justify-content:center;"><i class="fa-solid fa-trash"></i> Clear</button>
        <button id="issDrawerProceedBtn" onclick="openIssueConfirmModal()" disabled style="flex:2;background:#6366f1;color:#fff;border:none;border-radius:7px;padding:9px 16px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;opacity:.5;">
            <i class="fa-solid fa-paper-plane"></i> Process &amp; Issue (<span id="issDrawerProceedCount">0</span>)
        </button>
    </div>
</div>

<!-- ══ ISSUE CONFIRM MODAL ══ -->
<div id="issueConfirmBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:10020;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:14px;width:680px;max-width:96vw;max-height:92vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.35);">
    <div style="padding:16px 22px;border-bottom:1px solid #e5e5e5;display:flex;align-items:center;justify-content:space-between;background:#f5f3ff;">
        <div style="font-size:16px;font-weight:800;color:#3730a3;display:flex;align-items:center;gap:8px;"><i class="fa-solid fa-paper-plane" style="color:#6366f1;"></i> Confirm &amp; Issue Sent Back Cheques</div>
        <button onclick="closeIssueConfirmModal()" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:22px;line-height:1;">&#x2715;</button>
    </div>
    <div style="padding:22px;">
        <div style="background:#f8fafc;border:1px solid #e5e5e5;border-radius:9px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
            <div><div style="font-size:16px;font-weight:800;color:#6366f1;" id="icmCount">0</div><div style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Cheques</div></div>
            <div><div style="font-size:16px;font-weight:800;color:#7c3aed;" id="icmTotal">Rs. 0.00</div><div style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Total Amount</div></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
            <div style="display:flex;flex-direction:column;gap:5px;"><label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;"><i class="fa-solid fa-calendar-day"></i> Issue Date *</label><input type="date" id="icmDate" style="border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;outline:none;" required></div>
            <div style="display:flex;flex-direction:column;gap:5px;">
                <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;"><i class="fa-solid fa-user-tie"></i> Issue To Type *</label>
                <div style="display:flex;border:1px solid #e5e5e5;border-radius:7px;overflow:hidden;">
                    <button type="button" class="icm-type-btn" data-type="SR" onclick="icmSetType('SR')" style="flex:1;padding:9px 12px;font-size:13px;font-weight:700;border:none;cursor:pointer;background:#6366f1;color:#fff;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .2s;"><i class="fa-solid fa-id-badge"></i> SR</button>
                    <button type="button" class="icm-type-btn" data-type="CC" onclick="icmSetType('CC')" style="flex:1;padding:9px 12px;font-size:13px;font-weight:700;border:none;cursor:pointer;background:#f9fafb;color:#6b7280;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .2s;"><i class="fa-solid fa-wallet"></i> CC</button>
                </div>
            </div>
        </div>
        <div id="icmSrSection" style="border:1px solid #6366f1;border-radius:9px;padding:16px;margin-bottom:14px;background:#faf5ff;">
            <div style="font-size:12px;font-weight:800;color:#374151;text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;display:flex;align-items:center;gap:8px;"><span style="background:#6366f1;color:#fff;padding:2px 8px;border-radius:6px;font-size:10px;">SR</span> Select Sales Representative</div>
            <select id="icmSrSelect" style="width:100%;border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;"></select>
            <div id="icmSrCard" style="display:none;margin-top:10px;padding:10px 14px;border-radius:8px;background:#fff;border:1px solid #e5e5e5;font-size:12px;color:#374151;"></div>
            <div style="margin-top:12px;padding-top:12px;border-top:1px dashed #e5e5e5;">
                <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:5px;"><i class="fa-solid fa-user-check"></i> Link Employee (optional)</label>
                <select id="icmSrEmpSelect" style="width:100%;border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;"></select>
            </div>
        </div>
        <div id="icmCcSection" style="display:none;border:1px solid #d97706;border-radius:9px;padding:16px;margin-bottom:14px;background:#fffbeb;">
            <div style="font-size:12px;font-weight:800;color:#374151;text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;display:flex;align-items:center;gap:8px;"><span style="background:#d97706;color:#fff;padding:2px 8px;border-radius:6px;font-size:10px;">CC</span> Select Delivery Person</div>
            <select id="icmCcSelect" style="width:100%;border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;"></select>
            <div id="icmCcCard" style="display:none;margin-top:10px;padding:10px 14px;border-radius:8px;background:#fff;border:1px solid #e5e5e5;font-size:12px;color:#374151;"></div>
            <div style="margin-top:12px;padding-top:12px;border-top:1px dashed #e5e5e5;">
                <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:5px;"><i class="fa-solid fa-user-check"></i> Link Employee (optional)</label>
                <select id="icmEmpSelect" style="width:100%;border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;"></select>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:5px;"><label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;"><i class="fa-solid fa-note-sticky"></i> Notes (optional)</label><textarea id="icmNotes" rows="2" style="border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;width:100%;resize:vertical;" placeholder="Any notes…"></textarea></div>
    </div>
    <div style="padding:14px 22px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:8px;background:#fafafa;">
        <button onclick="closeIssueConfirmModal()" style="padding:8px 16px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#555;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">Cancel</button>
        <button id="icmSaveBtn" onclick="saveChequeIssue()" style="padding:9px 22px;border-radius:6px;border:none;background:#6366f1;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px;"><i class="fa-solid fa-floppy-disk"></i> Save &amp; Issue</button>
    </div>
</div>
</div>

<!-- ══ HISTORY DRAWER ══ -->
<div id="histDrawerBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:10010;" onclick="closeHistoryDrawer()"></div>
<div id="histDrawer" style="position:fixed;right:0;top:0;bottom:0;width:780px;max-width:98vw;background:#fff;z-index:10011;display:flex;flex-direction:column;box-shadow:-6px 0 40px rgba(0,0,0,.2);transform:translateX(110%);transition:transform .32s cubic-bezier(.4,0,.2,1);">
    <div style="padding:14px 20px;background:#1e1b4b;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <div style="font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px;"><i class="fa-solid fa-clock-rotate-left" style="color:#a5b4fc;"></i> Sent Back Cheque Issue History <span style="font-size:10px;background:rgba(99,102,241,.4);padding:2px 8px;border-radius:8px;color:#c7d2fe;">SBI codes</span></div>
        <button onclick="closeHistoryDrawer()" style="background:none;border:none;color:#a5b4fc;font-size:20px;cursor:pointer;padding:2px;">&#x2715;</button>
    </div>
    <div style="padding:10px 16px;border-bottom:1px solid #f0f0f0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;flex-shrink:0;background:#fafafa;">
        <input type="text" id="histSearch" placeholder="Search SBI code, person…" style="border:1px solid #e5e5e5;border-radius:7px;padding:6px 10px;font-size:12px;font-family:inherit;color:#1f2937;width:180px;" oninput="histFetchDebounced()">
        <select id="histTypeFilter" style="border:1px solid #e5e5e5;border-radius:7px;padding:6px 10px;font-size:12px;font-family:inherit;color:#1f2937;" onchange="histFetch()"><option value="">All Types</option><option value="SR">SR</option><option value="CC">CC</option></select>
        <input type="date" id="histDateFilter" style="border:1px solid #e5e5e5;border-radius:7px;padding:6px 10px;font-size:12px;font-family:inherit;color:#1f2937;" onchange="histFetch()">
        <button onclick="histClearFilters()" style="border:1px solid #e5e5e5;border-radius:7px;padding:6px 10px;font-size:11px;background:#fff;color:#6b7280;cursor:pointer;font-family:inherit;"><i class="fa-solid fa-rotate-left"></i> Reset</button>
    </div>
    <div id="histBody" style="flex:1;overflow-y:auto;padding:0;"><div style="text-align:center;padding:50px 20px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px;opacity:.5;"></i><p style="font-size:13px;margin:0;">Loading…</p></div></div>
    <div id="histPager" style="padding:8px 16px;border-top:1px solid #f0f0f0;display:flex;justify-content:center;gap:6px;flex-shrink:0;background:#fafafa;"></div>
</div>

<!-- ══ ISSUE DETAIL MODAL ══ -->
<div id="issDetailBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:10030;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:14px;width:860px;max-width:98vw;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.3);overflow:hidden;">
    <div style="padding:14px 20px;background:#1e1b4b;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <div style="font-size:14px;font-weight:800;"><i class="fa-solid fa-file-invoice"></i> Issue Details</div>
            <span id="issDetailCode" style="font-family:monospace;background:rgba(255,255,255,.15);padding:3px 12px;border-radius:8px;font-size:13px;color:#e0e7ff;"></span>
            <span id="issDetailPerson" style="font-size:11px;color:#c7d2fe;"></span>
            <span id="issDetailDate" style="font-size:11px;color:#a5b4fc;"></span>
        </div>
        <button onclick="closeIssDetail()" style="background:none;border:none;color:#a5b4fc;font-size:20px;cursor:pointer;padding:2px;">&#x2715;</button>
    </div>
    <div id="issDetailBody" style="flex:1;overflow-y:auto;padding:16px 20px;"><div style="text-align:center;padding:40px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px;"></i></div></div>
    <div style="padding:12px 20px;border-top:2px solid #f0f0f0;display:flex;justify-content:flex-end;background:#fafafa;flex-shrink:0;">
        <button onclick="closeIssDetail()" style="padding:8px 16px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#555;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>
</div>
</div>

<!-- DELETE ISSUE CONFIRM -->
<div id="issDeleteBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:10040;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:14px;width:420px;max-width:95vw;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.4);">
    <div style="background:#dc2626;padding:16px 20px;display:flex;align-items:center;gap:10px;"><i class="fa-solid fa-triangle-exclamation" style="color:#fff;font-size:20px;"></i><span style="color:#fff;font-size:15px;font-weight:800;">Delete Issue</span></div>
    <div style="padding:22px 20px;">
        <p style="font-size:13px;color:#374151;margin:0 0 8px;">You are about to permanently delete:</p>
        <div id="issDeleteCode" style="font-family:monospace;font-size:14px;font-weight:800;color:#dc2626;background:#fee2e2;padding:6px 12px;border-radius:7px;display:inline-block;margin:6px 0;"></div>
        <p style="margin-top:8px;font-size:13px;color:#374151;">This removes the issue and all <strong id="issDeleteCount"></strong> cheque(s).</p>
        <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:7px;padding:10px 12px;font-size:12px;color:#92400e;display:flex;align-items:center;gap:8px;margin-top:10px;"><i class="fa-solid fa-triangle-exclamation"></i><span>This action <strong>cannot be undone</strong>.</span></div>
    </div>
    <div style="padding:14px 20px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:8px;">
        <button onclick="closeDeleteIssue()" style="padding:8px 16px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#555;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;"><i class="fa-solid fa-xmark"></i> Cancel</button>
        <button id="issDeleteConfirmBtn" onclick="executeDeleteIssue()" style="padding:8px 16px;border-radius:6px;border:none;background:#dc2626;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:5px;"><i class="fa-solid fa-trash"></i> Yes, Delete</button>
    </div>
</div>
</div>

<!-- ══ RETURN ISSUED CHEQUE MODAL ══ -->
<div id="returnIssueBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:10050;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:14px;width:480px;max-width:96vw;overflow:hidden;box-shadow:0 24px 80px rgba(0,0,0,.35);">
    <div style="padding:16px 22px;border-bottom:1px solid #e5e5e5;display:flex;align-items:center;justify-content:space-between;background:#fff7ed;">
        <h3 style="font-size:16px;font-weight:800;color:#9a3412;margin:0;display:flex;align-items:center;gap:8px;"><i class="fa-solid fa-rotate-left"></i> Return Issued Cheque</h3>
        <button onclick="closeReturnIssueModal()" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:22px;line-height:1;">&#x2715;</button>
    </div>
    <div style="padding:20px 22px;">
        <div id="riChequeInfo" style="font-size:12px;color:#6b7280;margin-bottom:14px;"></div>
        <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px;">
            <label class="ri-opt-label" id="riLabelWith" style="display:flex;align-items:flex-start;gap:10px;border:1.5px solid #e5e5e5;border-radius:9px;padding:12px 14px;cursor:pointer;background:#fff;">
                <input type="radio" name="riReturnType" id="riWith" value="with_corrections" style="margin-top:2px;accent-color:#d97706;" onchange="riOnTypeChange()">
                <span><strong style="display:block;font-size:13px;color:#1f2937;">Return with Corrections</strong><span style="font-size:11.5px;color:#6b7280;">Cheque / issue details need to be corrected before it can be re-issued or settled.</span></span>
            </label>
            <label class="ri-opt-label" id="riLabelWithout" style="display:flex;align-items:flex-start;gap:10px;border:1.5px solid #e5e5e5;border-radius:9px;padding:12px 14px;cursor:pointer;background:#fff;">
                <input type="radio" name="riReturnType" id="riWithout" value="without_corrections" style="margin-top:2px;accent-color:#16a34a;" onchange="riOnTypeChange()">
                <span><strong style="display:block;font-size:13px;color:#1f2937;">Return without Corrections</strong><span style="font-size:11.5px;color:#6b7280;">Cheque is returned as-is — no corrections required.</span></span>
            </label>
        </div>
        <div class="fg">
            <label>Remarks</label>
            <textarea id="riRemarks" rows="3" class="fctrl" placeholder="Add remarks about this return..." style="resize:vertical;"></textarea>
        </div>
    </div>
    <div style="padding:14px 22px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:8px;background:#fafafa;">
        <button onclick="closeReturnIssueModal()" style="padding:8px 16px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#555;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">Cancel</button>
        <button id="riSaveBtn" onclick="submitReturnIssueModal()" style="padding:9px 20px;border-radius:6px;border:none;background:#d97706;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px;"><i class="fa-solid fa-rotate-left"></i> Confirm Return</button>
    </div>
</div>
</div>

<script>
const BANKS = <?php echo json_encode($banks_list); ?>;

const ISSUE_HANDLER = 'save_sentback_issue.php';

let SB_SELECTED = {};
let ICM_TYPE    = 'SR';
let ICM_PERSONS = {cc:[],sr:[],emp:[]};
let HIST_PAGE   = 1, HIST_TIMER = null;
let ISS_DEL_ID  = null, ISS_DEL_CODE = null;

$(function(){
    $('#selBank').select2({placeholder:'— All Banks —',allowClear:true,width:'100%'});
    $('#selSettled').select2({placeholder:'— All —',allowClear:true,width:'100%'});
});

function sbFetch(url,opts){return fetch(url,opts).then(r=>r.text()).then(t=>{try{return JSON.parse(t);}catch(e){throw new Error('Server error: '+t.replace(/<[^>]*>/g,'').substring(0,200));}});}
function esc(s){if(s===null||s===undefined)return '';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}

/* ══ Checkbox via MutationObserver ══ */
const _sbObs=new MutationObserver(()=>{
    document.querySelectorAll('#mainTbody tr[data-id]:not([data-sb-cb])').forEach(tr=>{
        tr.setAttribute('data-sb-cb','1');
        const cid=tr.dataset.id,amt=parseFloat(tr.querySelector('.amt-cell')?.textContent?.replace(/Rs\.?\s*/gi,'').replace(/,/g,'')||0);
        const chqNo=tr.querySelector('.mono')?.textContent?.trim()||'',cust=tr.querySelector('.cust-sub')?.textContent?.trim()||'';
        const bankEl=tr.querySelectorAll('td')[8],bank=bankEl?.querySelector('div')?.textContent?.trim()||'';
        const _sbStatCell=tr.querySelector('[id^="sb-iss-stat-"]')?.closest('td');
        const isIssued=_sbStatCell && (_sbStatCell.querySelector('.fa-paper-plane')!==null || _sbStatCell.querySelector('.fa-user-check')!==null);
        const cbTd=document.createElement('td');cbTd.className='tc no-print';cbTd.style.cssText='width:32px;vertical-align:middle;';
        if(isIssued){cbTd.innerHTML=`<span title="Currently issued" style="color:#bfdbfe;font-size:13px;cursor:not-allowed;"><i class="fa-solid fa-lock"></i></span>`;}
        else{cbTd.innerHTML=`<input type="checkbox" class="sb-cb" data-id="${cid}" data-chqno="${esc(chqNo)}" data-cust="${esc(cust)}" data-amt="${amt}" data-bank="${esc(bank)}" style="accent-color:#6366f1;width:15px;height:15px;cursor:pointer;" onchange="sbOnCheck(this)">`;if(SB_SELECTED[cid]) cbTd.querySelector('input').checked=true;}
        tr.insertBefore(cbTd,tr.firstChild);
    });
});
_sbObs.observe(document.getElementById('mainTbody'),{childList:true,subtree:false});

function sbOnCheck(cb){const cid=cb.dataset.id;if(cb.checked){SB_SELECTED[cid]={cheque_no:cb.dataset.chqno,customer_name:cb.dataset.cust,amount:parseFloat(cb.dataset.amt),bank_name:cb.dataset.bank};}else{delete SB_SELECTED[cid];}refreshIssueDrawerUI();}
function sbToggleAll(){const all=document.getElementById('sbSelAll')?.checked;document.querySelectorAll('#mainTbody .sb-cb').forEach(cb=>{cb.checked=!!all;sbOnCheck(cb);});}

function openIssueDrawer(){refreshIssueDrawerUI();document.getElementById('issueDrawer').style.transform='translateX(0)';document.getElementById('issueDrawerBackdrop').style.display='block';document.body.style.overflow='hidden';}
function closeIssueDrawer(){document.getElementById('issueDrawer').style.transform='translateX(110%)';document.getElementById('issueDrawerBackdrop').style.display='none';document.body.style.overflow='';}

function refreshIssueDrawerUI(){
    const ids=Object.keys(SB_SELECTED),count=ids.length,total=ids.reduce((s,k)=>s+(SB_SELECTED[k].amount||0),0);
    document.getElementById('issDrawerCount').textContent=count+' selected';
    const tb=document.getElementById('issueToolbarBadge');if(tb){tb.textContent=count+' selected';tb.style.display=count>0?'':'none';}
    document.getElementById('issDrawerStatCount').textContent=count;document.getElementById('issDrawerStatAmt').textContent='Rs. '+total.toFixed(2);
    document.getElementById('issDrawerProceedCount').textContent=count;
    const btn=document.getElementById('issDrawerProceedBtn');btn.disabled=count===0;btn.style.opacity=count>0?'1':'.5';btn.style.cursor=count>0?'pointer':'not-allowed';
    const list=document.getElementById('issDrawerList');document.getElementById('issDrawerEmpty').style.display=count?'none':'';
    list.querySelectorAll('.iss-dl-item').forEach(el=>el.remove());
    ids.forEach(cid=>{const item=SB_SELECTED[cid];const div=document.createElement('div');div.className='iss-dl-item';div.style.cssText='display:flex;align-items:center;gap:10px;padding:9px 16px;border-bottom:1px solid #f9fafb;';
    div.innerHTML=`<span style="font-family:monospace;font-size:12px;font-weight:700;color:#5b21b6;min-width:90px;">${esc(item.cheque_no)}</span><span style="flex:1;font-size:12px;color:#374151;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(item.customer_name)}</span><span style="font-size:12px;font-weight:800;color:#7c3aed;min-width:80px;text-align:right;">Rs.&nbsp;${item.amount.toFixed(2)}</span><button onclick="sbRemove('${cid}')" style="background:none;border:none;color:#d1d5db;cursor:pointer;padding:3px;font-size:12px;"><i class="fa-solid fa-xmark"></i></button>`;
    list.appendChild(div);});
}
function sbRemove(cid){delete SB_SELECTED[cid];const cb=document.querySelector(`.sb-cb[data-id="${cid}"]`);if(cb)cb.checked=false;refreshIssueDrawerUI();if(!Object.keys(SB_SELECTED).length)closeIssueDrawer();}
function clearIssueSelection(){SB_SELECTED={};document.querySelectorAll('.sb-cb').forEach(cb=>cb.checked=false);const sa=document.getElementById('sbSelAll');if(sa)sa.checked=false;refreshIssueDrawerUI();closeIssueDrawer();}

async function openIssueConfirmModal(){
    const ids=Object.keys(SB_SELECTED);if(!ids.length){showToast('No cheques selected','err');return;}
    if(!ICM_PERSONS.cc.length&&!ICM_PERSONS.sr.length){
        try{const d=await sbFetch('sentback_cheques.php?ajax=issue_persons');if(d.success){ICM_PERSONS.cc=d.cc_persons;ICM_PERSONS.sr=d.sr_persons;ICM_PERSONS.emp=d.employees;}}
        catch(e){showToast('Could not load persons: '+e.message,'err');return;}
    }
    function mkOpts(items,ph){let o=`<option value="">— ${ph} —</option>`;items.forEach(i=>{o+=`<option value="${esc(i.code)}">${esc(i.code)}${i.label&&i.label!==i.code?' — '+esc(i.label):''}</option>`;});return o;}
    ['icmSrSelect','icmCcSelect','icmSrEmpSelect','icmEmpSelect'].forEach(id=>{const el=document.getElementById(id);if(el&&window.$&&$(el).hasClass('select2-hidden-accessible'))$(el).select2('destroy');});
    document.getElementById('icmSrSelect').innerHTML=mkOpts(ICM_PERSONS.sr,'Select SR Code');
    document.getElementById('icmCcSelect').innerHTML=mkOpts(ICM_PERSONS.cc,'Select Delivery Person');
    const eOpts=`<option value="">— Select Employee (optional) —</option>`+ICM_PERSONS.emp.map(e=>`<option value="${e.id}">${esc(e.employee_id)} — ${esc(e.employee_full_name)}${e.designation_name?' ('+esc(e.designation_name)+')':''}</option>`).join('');
    document.getElementById('icmSrEmpSelect').innerHTML=eOpts;document.getElementById('icmEmpSelect').innerHTML=eOpts;
    if(window.$){
        const bd=$('#issueConfirmBackdrop');
        ['icmSrSelect','icmCcSelect'].forEach(id=>{$(`#${id}`).select2({width:'100%',allowClear:true,dropdownParent:bd}).on('change',function(){const cId=id==='icmSrSelect'?'icmSrCard':'icmCcCard';const card=document.getElementById(cId);if(this.value){card.style.display='flex';const isSr=id==='icmSrSelect';card.innerHTML=`<span style="width:32px;height:32px;border-radius:50%;background:${isSr?'#ede9fe':'#fef3c7'};color:${isSr?'#6366f1':'#d97706'};display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;"><i class="fa-solid ${isSr?'fa-id-badge':'fa-wallet'}"></i></span><div><div style="font-weight:700;color:#1f2937;font-size:13px;">${esc(this.value)}</div><div style="font-size:11px;color:#9ca3af;">${isSr?'Sales Representative':'Delivery Person (CC)'}</div></div>`;}else{card.style.display='none';}});});
        ['icmSrEmpSelect','icmEmpSelect'].forEach(id=>{$(`#${id}`).select2({width:'100%',placeholder:'— Select Employee (optional) —',allowClear:true,dropdownParent:bd});});
    }
    const total=ids.reduce((s,k)=>s+(SB_SELECTED[k].amount||0),0);
    document.getElementById('icmCount').textContent=ids.length;document.getElementById('icmTotal').textContent='Rs. '+total.toFixed(2);
    document.getElementById('icmDate').value=new Date().toISOString().split('T')[0];document.getElementById('icmNotes').value='';
    document.getElementById('icmSrCard').style.display='none';document.getElementById('icmCcCard').style.display='none';
    icmSetType('SR');document.getElementById('issueConfirmBackdrop').style.display='flex';document.body.style.overflow='hidden';
}
function closeIssueConfirmModal(){
    if(window.$){['icmSrSelect','icmCcSelect','icmSrEmpSelect','icmEmpSelect'].forEach(id=>{const el=document.getElementById(id);if(el&&$(el).hasClass('select2-hidden-accessible'))$(el).select2('destroy');});}
    document.getElementById('issueConfirmBackdrop').style.display='none';document.body.style.overflow='';
}
function icmSetType(type){ICM_TYPE=type;document.querySelectorAll('.icm-type-btn').forEach(b=>{const a=b.dataset.type===type;b.style.background=a?(type==='SR'?'#6366f1':'#d97706'):'#f9fafb';b.style.color=a?'#fff':'#6b7280';});document.getElementById('icmSrSection').style.display=type==='SR'?'':'none';document.getElementById('icmCcSection').style.display=type==='CC'?'':'none';}

async function saveChequeIssue(){
    const ids=Object.keys(SB_SELECTED),date=document.getElementById('icmDate').value,notes=document.getElementById('icmNotes').value.trim();
    if(!date){showToast('Please select issue date','err');return;}if(!ids.length){showToast('No cheques selected','err');return;}
    let pCode='',pName='',empId='';
    if(ICM_TYPE==='SR'){pCode=window.$?($('#icmSrSelect').val()||''):document.getElementById('icmSrSelect').value;if(!pCode){showToast('Please select an SR','err');return;}pName=pCode;empId=window.$?($('#icmSrEmpSelect').val()||''):(document.getElementById('icmSrEmpSelect').value||'');}
    else{pCode=window.$?($('#icmCcSelect').val()||''):document.getElementById('icmCcSelect').value;if(!pCode){showToast('Please select a Delivery Person (CC)','err');return;}pName=pCode;empId=window.$?($('#icmEmpSelect').val()||''):(document.getElementById('icmEmpSelect').value||'');}
    const btn=document.getElementById('icmSaveBtn');btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    const fd=new FormData();fd.append('action','save_issue');fd.append('issue_date',date);fd.append('person_type',ICM_TYPE);fd.append('person_code',pCode);fd.append('person_name',pName);if(empId) fd.append('employee_id',empId);fd.append('notes',notes);ids.forEach(id=>fd.append('cheque_ids[]',id));
    try{
        const data=await sbFetch(ISSUE_HANDLER,{method:'POST',body:fd});
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save &amp; Issue';
        if(data.success){showToast('✓ Issue saved: '+data.issue_code,'ok');closeIssueConfirmModal();closeIssueDrawer();clearIssueSelection();}
        else{showToast(data.error||'Save failed','err');}
    }catch(e){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save &amp; Issue';showToast('Network error: '+e.message,'err');}
}

/* ══ History Drawer ══ */
function openHistoryDrawer(){document.getElementById('histDrawer').style.transform='translateX(0)';document.getElementById('histDrawerBackdrop').style.display='block';document.body.style.overflow='hidden';HIST_PAGE=1;histFetch();}
function closeHistoryDrawer(){document.getElementById('histDrawer').style.transform='translateX(110%)';document.getElementById('histDrawerBackdrop').style.display='none';document.body.style.overflow='';}
function histClearFilters(){document.getElementById('histSearch').value='';document.getElementById('histTypeFilter').value='';document.getElementById('histDateFilter').value='';HIST_PAGE=1;histFetch();}
function histFetchDebounced(){clearTimeout(HIST_TIMER);HIST_TIMER=setTimeout(()=>{HIST_PAGE=1;histFetch();},350);}

async function histFetch(){
    document.getElementById('histBody').innerHTML='<div style="text-align:center;padding:50px 20px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px;opacity:.5;"></i><p style="font-size:13px;margin:0;">Loading…</p></div>';
    const q=document.getElementById('histSearch').value.trim(),type=document.getElementById('histTypeFilter').value,date=document.getElementById('histDateFilter').value;
    const params=new URLSearchParams({action:'load_history',page:HIST_PAGE,q,person_type:type,issue_date:date});
    try{const data=await sbFetch(ISSUE_HANDLER+'?'+params.toString());if(!data.success) throw new Error(data.error||'Server error');histRender(data);}
    catch(e){document.getElementById('histBody').innerHTML=`<div style="text-align:center;padding:40px;color:#dc2626;font-size:13px;"><i class="fa-solid fa-triangle-exclamation"></i> ${esc(e.message)}</div>`;}
}

function histRender(data){
    const rows=data.rows||[];const body=document.getElementById('histBody');const pager=document.getElementById('histPager');
    if(!rows.length){body.innerHTML='<div style="text-align:center;padding:60px 20px;color:#9ca3af;"><i class="fa-solid fa-inbox" style="font-size:36px;display:block;margin-bottom:12px;opacity:.3;"></i><p style="font-size:13px;">No issue records found.</p></div>';pager.innerHTML='';return;}
    let html='<table style="width:100%;border-collapse:collapse;font-size:12px;"><thead><tr style="background:#1e1b4b;color:#e0e7ff;">'
        +'<th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;">SBI Code</th>'
        +'<th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;">Date</th>'
        +'<th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;">Type</th>'
        +'<th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;">Issued To</th>'
        +'<th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;">Cheques</th>'
        +'<th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;">Issued</th>'
        +'<th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;">Returned</th>'
        +'<th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;">To Customer</th>'
        +'<th style="padding:8px 10px;text-align:right;font-size:10px;font-weight:700;">Amount</th>'
        +'<th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;">Actions</th>'
        +'</tr></thead><tbody>';
    rows.forEach(r=>{
        const isCC=r.person_type==='CC';const pillCls=isCC?'background:#fef3c7;color:#92400e;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;':'background:#ede9fe;color:#5b21b6;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;';
        html+=`<tr id="hist-row-${r.id}" style="border-bottom:1px solid #f3f4f6;" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background=''">
            <td style="padding:8px 10px;"><span style="font-family:monospace;font-size:11px;font-weight:700;color:#4338ca;background:#ede9fe;padding:2px 7px;border-radius:5px;">${esc(r.issue_code)}</span></td>
            <td style="padding:8px 10px;font-size:11px;white-space:nowrap;">${esc(r.issue_date)}</td>
            <td style="padding:8px 10px;text-align:center;"><span style="${pillCls}">${esc(r.person_type)}</span></td>
            <td style="padding:8px 10px;"><div style="font-size:12px;font-weight:700;color:#1f2937;">${esc(r.person_code)}</div>
                ${r.emp_name?`<div style="font-size:10px;color:#0891b2;"><i class="fa-solid fa-user-check" style="font-size:9px;"></i> ${esc(r.emp_name)}${r.emp_desig?' ('+esc(r.emp_desig)+')':''}</div>`:''}
            </td>
            <td style="padding:8px 10px;text-align:center;font-weight:700;">${parseInt(r.total_items)||0}</td>
            <td style="padding:8px 10px;text-align:center;">${parseInt(r.issued_cnt)>0?`<span style="background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-paper-plane"></i> ${r.issued_cnt}</span>`:'<span style="color:#d1d5db;font-size:11px;">—</span>'}</td>
            <td style="padding:8px 10px;text-align:center;">${parseInt(r.returned_cnt)>0?`<span style="background:#dcfce7;color:#166534;border:1px solid #86efac;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-rotate-left"></i> ${r.returned_cnt}</span>`:'<span style="color:#d1d5db;font-size:11px;">—</span>'}</td>
            <td style="padding:8px 10px;text-align:center;">${parseInt(r.cust_issued_cnt)>0?`<span style="background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-user-check"></i> ${r.cust_issued_cnt}</span>`:'<span style="color:#d1d5db;font-size:11px;">—</span>'}</td>
            <td style="padding:8px 10px;text-align:right;font-weight:700;color:#7c3aed;font-size:11px;">Rs.&nbsp;${parseFloat(r.total_amount||0).toFixed(2)}</td>
            <td style="padding:8px 10px;text-align:center;">
                <div style="display:flex;align-items:center;gap:4px;justify-content:center;">
                    <button style="background:#0ea5e9;color:#fff;border:none;border-radius:5px;padding:4px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;"
                        onclick="openIssDetail(${r.id},'${esc(r.issue_code)}','${esc(r.person_type)}','${esc(r.issue_date)}','${esc(r.person_code)}','${esc(r.person_name)}','${esc(r.emp_name)}','${esc(r.emp_desig)}')">
                        <i class="fa-solid fa-eye"></i> View
                    </button>
                    <button style="background:#dc2626;color:#fff;border:none;border-radius:5px;padding:4px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;"
                        onclick="confirmDeleteIssue(${r.id},'${esc(r.issue_code)}',${parseInt(r.total_items)||0})">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>`;
    });
    html+='</tbody></table>';body.innerHTML=html;
    if(data.pages<=1){pager.innerHTML='';return;}
    let ph='';for(let p=1;p<=data.pages;p++){const a=p===data.page;ph+=`<button onclick="histGoPage(${p})" style="border:1.5px solid ${a?'#1e1b4b':'#e5e5e5'};background:${a?'#1e1b4b':'#fff'};color:${a?'#fff':'#374151'};border-radius:6px;padding:4px 10px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;">${p}</button>`;}
    pager.innerHTML=ph;
}
function histGoPage(p){HIST_PAGE=p;histFetch();}

/* ══ Issue Detail Modal ══ */
function openIssDetail(issId,code,type,date,pCode,pName,empName,empDesig){
    document.getElementById('issDetailCode').textContent=code;
    document.getElementById('issDetailPerson').textContent=(type==='CC'?'CC: ':'SR: ')+pCode;
    document.getElementById('issDetailDate').innerHTML='<i class="fa-solid fa-calendar-day" style="font-size:10px;"></i> '+date;
    document.getElementById('issDetailBody').innerHTML='<div style="text-align:center;padding:40px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px;"></i></div>';
    document.getElementById('issDetailBackdrop').style.display='flex';document.body.style.overflow='hidden';
    sbFetch(ISSUE_HANDLER+'?action=load_items&issue_id='+issId)
    .then(data=>{if(!data.success) throw new Error(data.error||'Server error');renderIssDetailItems(issId,data.items);})
    .catch(e=>{document.getElementById('issDetailBody').innerHTML=`<div style="text-align:center;padding:40px;color:#dc2626;font-size:13px;"><i class="fa-solid fa-triangle-exclamation"></i> ${esc(e.message)}</div>`;});
}
function closeIssDetail(){document.getElementById('issDetailBackdrop').style.display='none';document.body.style.overflow='';}

function renderIssDetailItems(issId,items){
    if(!items.length){document.getElementById('issDetailBody').innerHTML='<div style="text-align:center;padding:50px;color:#9ca3af;"><i class="fa-solid fa-inbox" style="font-size:32px;display:block;margin-bottom:12px;opacity:.3;"></i> No items.</div>';return;}
    let totAmt=0,issuedCnt=0,returnedCnt=0,custCnt=0;items.forEach(i=>{totAmt+=parseFloat(i.amount||0);if(i.status==='issued')issuedCnt++;else if(i.status==='issued_to_customer')custCnt++;else returnedCnt++;});
    let html=`<div style="display:flex;gap:12px;flex-wrap:wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:10px 16px;margin-bottom:14px;">
        <div><div style="font-size:15px;font-weight:800;color:#1f2937;">Rs.&nbsp;${totAmt.toFixed(2)}</div><div style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Total Amount</div></div>
        <div><div style="font-size:15px;font-weight:800;color:#d97706;">${issuedCnt}</div><div style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Issued</div></div>
        <div><div style="font-size:15px;font-weight:800;color:#16a34a;">${returnedCnt}</div><div style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Returned</div></div>
        <div><div style="font-size:15px;font-weight:800;color:#0f766e;">${custCnt}</div><div style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Issued to Customer</div></div>
    </div>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;">
    <thead><tr style="background:#1e1b4b;color:#e0e7ff;">
        <th style="padding:8px;">#</th><th style="padding:8px;text-align:left;">Cheque No.</th><th style="padding:8px;text-align:left;">Customer</th>
        <th style="padding:8px;text-align:left;">Bank</th><th style="padding:8px;text-align:left;">Cheque Date</th>
        <th style="padding:8px;text-align:right;">Amount</th><th style="padding:8px;text-align:center;">Status</th>
        <th style="padding:8px;text-align:center;">Updated</th><th style="padding:8px;text-align:center;">Actions</th>
    </tr></thead><tbody>`;
    items.forEach((item,i)=>{
        const isIssued=item.status==='issued';
        const isCust=item.status==='issued_to_customer';
        const statusBadge=isIssued?`<span style="background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-paper-plane"></i> Issued</span>`
            :isCust?`<span style="background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-user-check"></i> Issued to Customer</span>`
            :`<span style="background:#dcfce7;color:#166534;border:1px solid #86efac;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-rotate-left"></i> Returned</span>`;
        let actionCell=isIssued?`<button onclick="ciMarkReturned(${item.id},this)" style="background:#d97706;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;"><i class="fa-solid fa-rotate-left"></i> Return</button>
            <button onclick="ciRemoveItem(${item.id},this)" style="background:#dc2626;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;margin-left:4px;"><i class="fa-solid fa-trash"></i></button>`
            :`<button onclick="ciReissueItem(${item.id},this)" style="background:#0d9488;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;"><i class="fa-solid fa-rotate-right"></i> Re-issue</button>`;
        html+=`<tr id="iss-item-row-${item.id}" style="border-bottom:1px solid #f3f4f6;background:${!isIssued?'#f9fafb':''};">
            <td style="padding:7px 8px;color:#9ca3af;font-size:11px;">${i+1}</td>
            <td style="padding:7px 8px;"><span style="font-family:monospace;font-weight:700;color:#5b21b6;font-size:12px;">${esc(item.cheque_no)}</span></td>
            <td style="padding:7px 8px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;">${esc(item.customer_name)}</td>
            <td style="padding:7px 8px;font-size:11px;"><div style="font-weight:600;">${esc(item.bank_name||'')}</div>${item.branch_name?`<div style="color:#6b7280;font-size:10px;">${esc(item.branch_name)}</div>`:''}</td>
            <td style="padding:7px 8px;font-size:11px;white-space:nowrap;">${esc(item.cheque_date||'—')}</td>
            <td style="padding:7px 8px;text-align:right;font-weight:700;color:#7c3aed;">Rs.&nbsp;${parseFloat(item.amount).toFixed(2)}</td>
            <td style="padding:7px 8px;text-align:center;" id="iss-item-status-${item.id}">${statusBadge}</td>
            <td style="padding:7px 8px;text-align:center;font-size:10px;color:#6b7280;" id="iss-item-upd-${item.id}">${item.customer_issued_at||item.returned_at||'—'}</td>
            <td style="padding:7px 8px;text-align:center;" id="iss-item-action-${item.id}">${actionCell}</td>
        </tr>`;
    });
    html+='</tbody></table></div>';document.getElementById('issDetailBody').innerHTML=html;
}

function ciMarkReturned(itemId,btn){
    /* opens the Return dialog (with-corrections / without-corrections + remarks) instead of a plain confirm() */
    openReturnIssueModal(itemId, 'detail', {});
}
function ciReissueItem(itemId,btn){
    if(!confirm('Re-issue?')) return;btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>';
    const fd=new FormData();fd.append('action','reissue_item');fd.append('item_id',itemId);
    sbFetch(ISSUE_HANDLER,{method:'POST',body:fd}).then(data=>{btn.disabled=false;
        if(data.success){showToast('Re-issued ✓','ok');
            const tr=document.getElementById('iss-item-row-'+itemId);if(tr)tr.style.background='';
            const ss=document.getElementById('iss-item-status-'+itemId);if(ss)ss.innerHTML=`<span style="background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-paper-plane"></i> Issued</span>`;
            const ua=document.getElementById('iss-item-upd-'+itemId);if(ua)ua.textContent='—';
            const ac=document.getElementById('iss-item-action-'+itemId);if(ac)ac.innerHTML=`<button onclick="ciMarkReturned(${itemId},this)" style="background:#d97706;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;"><i class="fa-solid fa-rotate-left"></i> Return</button><button onclick="ciRemoveItem(${itemId},this)" style="background:#dc2626;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;margin-left:4px;display:inline-flex;align-items:center;gap:3px;"><i class="fa-solid fa-trash"></i></button>`;
        }else{btn.innerHTML='<i class="fa-solid fa-rotate-right"></i> Re-issue';showToast(data.error||'Failed','err');}
    }).catch(e=>{btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-rotate-right"></i> Re-issue';showToast(e.message,'err');});
}
function ciRemoveItem(itemId,btn){
    if(!confirm('Remove from issue?')) return;btn.disabled=true;
    const fd=new FormData();fd.append('action','remove_item');fd.append('item_id',itemId);
    sbFetch(ISSUE_HANDLER,{method:'POST',body:fd}).then(data=>{
        if(data.success){showToast('Removed ✓','ok');const tr=document.getElementById('iss-item-row-'+itemId);if(tr){tr.style.opacity='0';tr.style.transition='opacity .3s';setTimeout(()=>tr.remove(),320);}}
        else{btn.disabled=false;showToast(data.error||'Failed','err');}
    }).catch(e=>{btn.disabled=false;showToast(e.message,'err');});
}

/* ══ Delete Issue ══ */
function confirmDeleteIssue(issId,issCode,cnt){ISS_DEL_ID=issId;ISS_DEL_CODE=issCode;document.getElementById('issDeleteCode').textContent=issCode;document.getElementById('issDeleteCount').textContent=cnt;document.getElementById('issDeleteBackdrop').style.display='flex';document.body.style.overflow='hidden';}
function closeDeleteIssue(){document.getElementById('issDeleteBackdrop').style.display='none';document.body.style.overflow='';ISS_DEL_ID=null;}
function executeDeleteIssue(){
    if(!ISS_DEL_ID) return;const btn=document.getElementById('issDeleteConfirmBtn');btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';
    const fd=new FormData();fd.append('action','delete_issue');fd.append('issue_id',ISS_DEL_ID);
    sbFetch(ISSUE_HANDLER,{method:'POST',body:fd}).then(data=>{btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-trash"></i> Yes, Delete';
        if(data.success){showToast('Issue '+ISS_DEL_CODE+' deleted','ok');closeDeleteIssue();const row=document.getElementById('hist-row-'+ISS_DEL_ID);if(row){row.style.opacity='0';row.style.transition='opacity .3s';setTimeout(()=>row.remove(),320);}ISS_DEL_ID=null;}
        else{showToast(data.error||'Delete failed','err');}
    }).catch(e=>{btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-trash"></i> Yes, Delete';showToast(e.message,'err');});
}

/* ══════════════════════════════════════════════════
   RETURN ISSUED CHEQUE MODAL
   (with corrections / without corrections + remarks)
══════════════════════════════════════════════════ */
let RETURN_ISSUE_CTX = null;

function openReturnIssueModal(itemId, source, extra){
    RETURN_ISSUE_CTX = Object.assign({itemId, source}, extra || {});
    const withEl = document.getElementById('riWith'), withoutEl = document.getElementById('riWithout');
    withEl.checked = false; withoutEl.checked = false;
    document.getElementById('riRemarks').value = '';
    riOnTypeChange();
    const info = (extra && extra.chequeNo) ? `Cheque No: <strong>${esc(extra.chequeNo)}</strong>` : '';
    document.getElementById('riChequeInfo').innerHTML = info;
    const modal = document.getElementById('returnIssueBackdrop');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeReturnIssueModal(){
    document.getElementById('returnIssueBackdrop').style.display = 'none';
    document.body.style.overflow = '';
    RETURN_ISSUE_CTX = null;
}
function riOnTypeChange(){
    const withEl = document.getElementById('riWith'), withoutEl = document.getElementById('riWithout');
    const withLbl = document.getElementById('riLabelWith'), withoutLbl = document.getElementById('riLabelWithout');
    withLbl.style.borderColor    = withEl.checked    ? '#d97706' : '#e5e5e5';
    withLbl.style.background     = withEl.checked    ? '#fffbeb' : '#fff';
    withoutLbl.style.borderColor = withoutEl.checked ? '#16a34a' : '#e5e5e5';
    withoutLbl.style.background  = withoutEl.checked ? '#f0fdf4' : '#fff';
}
async function submitReturnIssueModal(){
    if(!RETURN_ISSUE_CTX || !RETURN_ISSUE_CTX.itemId){ showToast('No cheque selected','err'); return; }
    const withEl = document.getElementById('riWith'), withoutEl = document.getElementById('riWithout');
    let returnType = '';
    if(withEl.checked) returnType = 'with_corrections';
    else if(withoutEl.checked) returnType = 'without_corrections';
    if(!returnType){ showToast('Please select a return type','err'); return; }
    const remarks = document.getElementById('riRemarks').value.trim();
    const itemId  = RETURN_ISSUE_CTX.itemId;
    const btn = document.getElementById('riSaveBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    const fd = new FormData();
    fd.append('action', 'mark_returned');
    fd.append('item_id', itemId);
    fd.append('return_type', returnType);
    fd.append('remarks', remarks);
    try{
        const data = await sbFetch(ISSUE_HANDLER, {method:'POST', body:fd});
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Confirm Return';
        if(!data.success){ showToast(data.error || 'Failed to save return','err'); return; }
        showToast('Cheque marked as returned ✓','ok');

        if(RETURN_ISSUE_CTX.source === 'row'){
            const rowEl = document.getElementById('row-' + RETURN_ISSUE_CTX.rowId);
            applyIssueStatus(rowEl, itemId, 'returned');
            if(ARD && String(ARD.issueItemId) === String(itemId)){
                ARD.issueItemStatus = 'returned';
                renderModalReturnBox();
            }
        } else if(RETURN_ISSUE_CTX.source === 'detail'){
            const tr = document.getElementById('iss-item-row-' + itemId);
            if(tr) tr.style.background = '#f9fafb';
            const ss = document.getElementById('iss-item-status-' + itemId);
            if(ss) ss.innerHTML = `<span style="background:#dcfce7;color:#166534;border:1px solid #86efac;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-rotate-left"></i> Returned</span>`;
            const ua = document.getElementById('iss-item-upd-' + itemId);
            if(ua) ua.textContent = new Date().toLocaleString('en-GB');
            const ac = document.getElementById('iss-item-action-' + itemId);
            if(ac) ac.innerHTML = `<button onclick="ciReissueItem(${itemId},this)" style="background:#0d9488;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;"><i class="fa-solid fa-rotate-right"></i> Re-issue</button>`;
            /* also keep the main table row in sync if it's currently rendered */
            const mainRow = document.querySelector(`tr[data-issue-item-id="${itemId}"]`);
            if(mainRow) applyIssueStatus(mainRow, itemId, 'returned');
        }
        closeReturnIssueModal();
    }catch(e){
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Confirm Return';
        showToast('Network error: ' + e.message, 'err');
    }
}

/* ══ Table rendering ══ */
function toggleFilter(){const h=document.getElementById('filterHeader'),b=document.getElementById('filterBody'),i=document.getElementById('filterIcon');const o=b.classList.contains('open');if(o){b.classList.remove('open');h.classList.remove('open');i.classList.remove('open');}else{b.classList.add('open');h.classList.add('open');i.classList.add('open');}}
function filterBySettled(val){document.getElementById('selSettled').value=val;if(window.$)$(document.getElementById('selSettled')).val(val).trigger('change');currentPage=1;fetchRows();}
const PAGE_SIZE=50;let currentPage=1,currentSearch='',fetchTimer=null,lastController=null;
function getFilters(){return{bank_code:document.getElementById('selBank')?.value||'',t_code:document.getElementById('fTcode')?.value||'',date_from:document.getElementById('fFrom')?.value||'',date_to:document.getElementById('fTo')?.value||'',settled:document.getElementById('selSettled')?.value||'',q:currentSearch,page:currentPage,per:PAGE_SIZE};}
function applyFilters(){currentPage=1;fetchRows();}
function clearFilters(){document.getElementById('selBank').value='';document.getElementById('selSettled').value='';document.getElementById('fTcode').value='';document.getElementById('fFrom').value='';document.getElementById('fTo').value='';if(window.$){$('#selBank').val('').trigger('change');$('#selSettled').val('').trigger('change');}currentPage=1;currentSearch='';const s=document.getElementById('chqSearch');if(s)s.value='';document.getElementById('chqClr')?.classList.remove('show');fetchRows();}

async function fetchRows(){
    if(lastController) lastController.abort();lastController=new AbortController();showLoading(true);
    const f=getFilters();const params=new URLSearchParams({ajax:'sentback_cheque_rows',...f});
    try{const res=await fetch('sentback_cheques.php?'+params.toString(),{signal:lastController.signal});if(!res.ok)throw new Error('HTTP '+res.status);const data=await res.json();if(!data.success)throw new Error(data.error||'Server error');renderRows(data);}
    catch(e){if(e.name==='AbortError')return;document.getElementById('mainTbody').innerHTML=`<tr><td colspan="17" style="text-align:center;padding:40px;color:#7c3aed;"><i class="fa-solid fa-triangle-exclamation"></i> Error: ${esc(e.message)}</td></tr>`;}
    finally{showLoading(false);}
}
function showLoading(on){document.getElementById('tblLoading')?.classList.toggle('show',on);}

function fmtDate(d){if(!d||d==='0000-00-00'||d===null)return '<span class="date-txt empty">—</span>';const dt=new Date(d.replace(' ','T'));if(isNaN(dt.getTime()))return '<span class="date-txt empty">—</span>';const m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];return `<span class="date-txt">${String(dt.getDate()).padStart(2,'0')} ${m[dt.getMonth()]} ${dt.getFullYear()}</span>`;}
function fmtSentBackDate(d){if(!d||d==='0000-00-00'||d===null)return `<span class="sentback-date-chip empty"><i class="fa-solid fa-circle-xmark"></i> No log</span>`;const dt=new Date(d.replace(' ','T'));if(isNaN(dt.getTime()))return `<span class="sentback-date-chip empty"><i class="fa-solid fa-circle-xmark"></i> No log</span>`;const m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];const ds=`${String(dt.getDate()).padStart(2,'0')} ${m[dt.getMonth()]} ${dt.getFullYear()}`;const ts=`${String(dt.getHours()).padStart(2,'0')}:${String(dt.getMinutes()).padStart(2,'0')}`;return `<span class="sentback-date-chip" title="${esc(d)}"><i class="fa-solid fa-rotate-left"></i> ${ds}<br><span style="font-size:10px;opacity:.75;">${ts}</span></span>`;}
function fmtAgingDays(d){if(!d||d==='0000-00-00'||d===null)return `<span class="aging-chip none"><i class="fa-solid fa-minus"></i> —</span>`;const dt=new Date(d.replace(' ','T'));if(isNaN(dt.getTime()))return `<span class="aging-chip none">—</span>`;const today=new Date();today.setHours(0,0,0,0);dt.setHours(0,0,0,0);const days=Math.round((today-dt)/(1000*60*60*24));let cls,icon;if(days<=7){cls='fresh';icon='fa-seedling';}else if(days<=30){cls='warn';icon='fa-clock';}else if(days<=60){cls='aged';icon='fa-triangle-exclamation';}else if(days<=90){cls='old';icon='fa-fire';}else{cls='crit';icon='fa-skull-crossbones';}return `<span class="aging-chip ${cls}" title="Sent back: ${d}"><i class="fa-solid ${icon}"></i> ${days}d</span>`;}

function buildIssueStatusBadge(row){
    const status=row.issue_item_status||'',code=row.issue_code||'';
    if(status==='issued') return `<div><span id="sb-iss-stat-${row.id}" style="display:inline-flex;align-items:center;gap:4px;background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;padding:3px 9px;border-radius:9px;font-size:10px;font-weight:700;white-space:nowrap;"><i class="fa-solid fa-paper-plane"></i> Issued</span>${code?`<div class="sbi-code">${esc(code)}</div>`:''}</div>`;
    if(status==='returned') return `<div><span id="sb-iss-stat-${row.id}" style="display:inline-flex;align-items:center;gap:4px;background:#dcfce7;color:#166534;border:1px solid #86efac;padding:3px 9px;border-radius:9px;font-size:10px;font-weight:700;white-space:nowrap;"><i class="fa-solid fa-rotate-left"></i> Returned</span>${code?`<div class="sbi-code">${esc(code)}</div>`:''}</div>`;
    if(status==='issued_to_customer') return `<div><span id="sb-iss-stat-${row.id}" style="display:inline-flex;align-items:center;gap:4px;background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;padding:3px 9px;border-radius:9px;font-size:10px;font-weight:700;white-space:nowrap;"><i class="fa-solid fa-user-check"></i> Issued to Customer</span>${code?`<div class="sbi-code">${esc(code)}</div>`:''}</div>`;
    return `<span id="sb-iss-stat-${row.id}" style="color:#d1d5db;font-size:11px;">—</span>`;
}

/* ═══ Issued-cheque settlement rules (same as Return Cheques page) ═══ */
function renderModalReturnBox(){
    const box=document.getElementById('modalReturnBox');
    if(!box) return;
    if(!ARD.issueItemId || !ARD.issueItemStatus){
        box.style.display='none';box.className='modal-return-box no-print';box.innerHTML='';return;
    }
    box.style.display='flex';
    if(ARD.issueItemStatus==='issued_to_customer'){
        box.className='modal-return-box no-print mrb-done';
        box.innerHTML=`<span class="mrb-label"><i class="fa-solid fa-user-check"></i> Issued to Customer${ARD.issueCode?` (${esc(ARD.issueCode)})`:''}</span>`;
        return;
    }
    if(ARD.issueItemStatus==='returned'){
        box.className='modal-return-box no-print mrb-done';
        box.innerHTML=`<span class="mrb-label"><i class="fa-solid fa-rotate-left"></i> Returned${ARD.issueCode?` (${esc(ARD.issueCode)})`:''}</span>`;
        return;
    }
    /* status === 'issued' */
    if(ARD.sbSettled){
        box.className='modal-return-box no-print mrb-auto';
        box.innerHTML=`<span class="mrb-label"><i class="fa-solid fa-rotate"></i> Fully settled — updating status to Issued to Customer…</span>`;
        modalAutoIssueToCustomer();
    }else{
        box.className='modal-return-box no-print mrb-issued';
        box.innerHTML=`
            <span class="mrb-label"><i class="fa-solid fa-paper-plane"></i> Issued${ARD.issueCode?` (${esc(ARD.issueCode)})`:''} — for a part payment you must tick <u>Return</u></span>
            <label class="mrb-check-label" id="modalReturnCbLabel">
                <input type="checkbox" id="modalReturnCb" data-item-id="${ARD.issueItemId}" onchange="toggleModalReturnSelect(this)">
                Return <span style="color:#dc2626;font-weight:800;">*</span>
            </label>`;
    }
}
function toggleModalReturnSelect(cb){
    const label=document.getElementById('modalReturnCbLabel');
    if(label){label.classList.toggle('checked',cb.checked);label.style.outline='';}
}
async function modalAutoIssueToCustomer(){
    if(!ARD.issueItemId || ARD.issueItemStatus!=='issued') return;
    window._issMarking=window._issMarking||{};
    if(window._issMarking[ARD.issueItemId]) return;
    window._issMarking[ARD.issueItemId]=1;
    try{
        const fd=new FormData();fd.append('action','mark_issued_to_customer');fd.append('item_id',ARD.issueItemId);
        const data=await sbFetch(ISSUE_HANDLER,{method:'POST',body:fd});
        if(data.success){
            ARD.issueItemStatus='issued_to_customer';
            renderModalReturnBox();
            applyIssueStatus(document.getElementById('row-'+ARD.chequeId),ARD.issueItemId,'issued_to_customer');
        }else{
            delete window._issMarking[ARD.issueItemId];
            console.error('modalAutoIssueToCustomer failed',data.error);
            const box=document.getElementById('modalReturnBox');
            if(box){box.className='modal-return-box no-print mrb-issued';box.innerHTML=`<span class="mrb-label" style="color:#b91c1c;"><i class="fa-solid fa-triangle-exclamation"></i> Status update failed: ${esc(data.error||'unknown error')}</span>`;}
        }
    }catch(e){delete window._issMarking[ARD.issueItemId];console.error('modalAutoIssueToCustomer error',e);}
}
async function markIssueStatusAfterPayment(action,newStatus,okMsg){
    if(!ARD.issueItemId) return;
    if(ARD.issueItemStatus!=='issued'){renderModalReturnBox();return;}
    window._issMarking=window._issMarking||{};
    if(window._issMarking[ARD.issueItemId]){if(okMsg)showToast(okMsg,'ok');return;}
    window._issMarking[ARD.issueItemId]=1;
    try{
        const fd=new FormData();fd.append('action',action);fd.append('item_id',ARD.issueItemId);
        const data=await sbFetch(ISSUE_HANDLER,{method:'POST',body:fd});
        if(data.success){
            ARD.issueItemStatus=newStatus;
            applyIssueStatus(document.getElementById('row-'+ARD.chequeId),ARD.issueItemId,newStatus);
            renderModalReturnBox();
            if(okMsg)showToast(okMsg,'ok');
        }else{
            delete window._issMarking[ARD.issueItemId];
            showToast(data.error||'Could not update issue status','err');
        }
    }catch(e){delete window._issMarking[ARD.issueItemId];showToast('Issue status update failed: '+e.message,'err');}
}
function applyIssueStatus(rowEl,itemId,status){
    if(!rowEl) rowEl=document.querySelector(`tr[data-issue-item-id="${itemId}"]`);
    if(!rowEl) return;
    rowEl.dataset.issueStatus=status;
    const code=rowEl.dataset.issueCode||'';
    const badge=rowEl.querySelector('[id^="sb-iss-stat-"]');
    if(!badge) return;
    const cell=badge.closest('td');
    if(!cell) return;
    if(status==='issued_to_customer'){
        cell.innerHTML=`<div><span id="sb-iss-stat-${rowEl.dataset.id}" style="display:inline-flex;align-items:center;gap:4px;background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;padding:3px 9px;border-radius:9px;font-size:10px;font-weight:700;white-space:nowrap;"><i class="fa-solid fa-user-check"></i> Issued to Customer</span>${code?`<div class="sbi-code">${esc(code)}</div>`:''}</div>`;
    }else{
        cell.innerHTML=`<div><span id="sb-iss-stat-${rowEl.dataset.id}" style="display:inline-flex;align-items:center;gap:4px;background:#dcfce7;color:#166534;border:1px solid #86efac;padding:3px 9px;border-radius:9px;font-size:10px;font-weight:700;white-space:nowrap;"><i class="fa-solid fa-rotate-left"></i> Returned</span>${code?`<div class="sbi-code">${esc(code)}</div>`:''}</div>`;
    }
    /* the cheque is no longer 'issued', so remove the Return button from the action cell */
    const retBtn = rowEl.querySelector('.btn-return-issue');
    if(retBtn) retBtn.remove();
}
async function autoIssueToCustomerIfFullySettled(rowEl){
    if(!rowEl) return;
    const itemId=rowEl.dataset.issueItemId;
    const status=rowEl.dataset.issueStatus;
    if(!itemId || status!=='issued') return;
    window._issMarking=window._issMarking||{};
    if(window._issMarking[itemId]) return;
    window._issMarking[itemId]=1;
    try{
        const fd=new FormData();fd.append('action','mark_issued_to_customer');fd.append('item_id',itemId);
        const data=await sbFetch(ISSUE_HANDLER,{method:'POST',body:fd});
        if(data.success){
            applyIssueStatus(rowEl,itemId,'issued_to_customer');
            if(ARD && String(ARD.issueItemId)===String(itemId)){ARD.issueItemStatus='issued_to_customer';renderModalReturnBox();}
        }else{
            delete window._issMarking[itemId];
            console.error('auto Issued-to-Customer failed for item',itemId,data.error);
        }
    }catch(e){delete window._issMarking[itemId];console.error('auto Issued-to-Customer error',e);}
}

function renderRows(data){
    const tbody=document.getElementById('mainTbody');const rows=data.rows||[],total=data.total||0,pages=data.pages||1,g_amt=data.grand_total||0;const offset=(currentPage-1)*PAGE_SIZE;
    document.getElementById('visCount').textContent=total+' records';document.getElementById('footerCount').textContent=total;
    document.getElementById('footerTotal').textContent=g_amt.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('filteredCount').textContent=total;document.getElementById('filteredAmt').textContent='Rs. '+g_amt.toLocaleString('en-US',{minimumFractionDigits:0,maximumFractionDigits:0});
    if(!rows.length){tbody.innerHTML=`<tr><td colspan="17"><div class="state-box"><i class="fa-solid fa-inbox"></i><p>No sent back cheques found.</p></div></td></tr>`;document.getElementById('pagerWrap').style.display='none';return;}
    let html='';
    rows.forEach((row,idx)=>{
        const rn=offset+idx+1,settled=parseInt(row.sb_settled||0),cheqAmt=parseFloat(row.total_amount||0),settlePaid=parseFloat(row.sb_settlement_amount||0),settleRemain=Math.max(0,cheqAmt-settlePaid),id=row.id,reason=row.sent_back_reason||'';
        let settleBadge,actionBtn;
        if(settled){settleBadge=`<span class="settled-badge yes"><i class="fa-solid fa-circle-check"></i> Fully Settled</span><div class="settle-meta" style="color:#166534;">Paid: Rs.${settlePaid.toFixed(2)} | Bal: Rs.0.00</div>`;actionBtn=`<button class="btn-loadpay settled" onclick="openSettleModal(${id})"><i class="fa-solid fa-circle-check"></i> View</button>`;}
        else if(settlePaid>0){settleBadge=`<span class="settled-badge no" style="background:#fff7ed;color:#c2410c;border-color:#fed7aa;"><i class="fa-solid fa-circle-half-stroke"></i> Partially Settled</span><div class="settle-meta" style="color:#92400e;">Paid: Rs.${settlePaid.toFixed(2)} | Bal: Rs.${settleRemain.toFixed(2)}</div>`;actionBtn=`<button class="btn-loadpay" onclick="openSettleModal(${id})"><i class="fa-solid fa-money-bill-transfer"></i> Load Pay</button>`;}
        else{settleBadge=`<span class="settled-badge no"><i class="fa-solid fa-clock"></i> Unsettled</span><div class="settle-meta" style="color:#9ca3af;">Paid: Rs.0.00 | Bal: Rs.${cheqAmt.toFixed(2)}</div>`;actionBtn=`<button class="btn-loadpay" onclick="openSettleModal(${id})"><i class="fa-solid fa-money-bill-transfer"></i> Load Pay</button>`;}
        const tbdBtn=settled
            ?`<button class="btn-tbd" disabled style="opacity:.4;cursor:not-allowed;filter:grayscale(1);" title="Already settled"><i class="fa-solid fa-clock"></i> Pending</button>`
            :`<button class="btn-tbd btn-pending-action" data-id="${id}" data-cheque-no="${esc(row.cheque_no||'')}" data-amount="${cheqAmt.toFixed(2)}" data-tcode="${esc(row.t_code||'')}" data-customer="${esc(row.customer_name||'')}" data-cheque-date="${esc(row.cheque_date||'')}" data-bank="${esc(row.bank_name||row.bank_code||'')}" data-received="${esc(row.received_date||'')}"><i class="fa-solid fa-clock"></i> Pending</button>`;
        /* ── Return button — shown only while this cheque's latest issue item is still 'issued' ── */
        const returnBtn=(row.issue_item_status==='issued' && row.issue_item_id)
            ?`<button class="btn-tbd btn-return-issue" style="background:linear-gradient(135deg,#d97706,#f59e0b);" onclick="openReturnIssueModal(${row.issue_item_id}, 'row', {rowId:${id}, chequeNo:'${esc(row.cheque_no||'')}'})"><i class="fa-solid fa-rotate-left"></i> Return</button>`
            :'';
        html+=`<tr id="row-${id}" data-id="${id}" data-settled="${settled}"
            data-issue-item-id="${row.issue_item_id||''}" data-issue-status="${row.issue_item_status||''}" data-issue-code="${esc(row.issue_code||'')}">
          <td style="color:#9ca3af;font-size:11px;font-weight:600;">${rn}</td>
          <td class="tc"><span class="sr-pill">${esc(row.sr_code||'—')}</span></td>
          <td class="tc">${fmtDate(row.cheque_date)}</td>
          <td class="tc">${fmtDate(row.received_date)}</td>
          <td class="tc">${fmtSentBackDate(row.sent_back_date||null)}</td>
          <td class="tc">${fmtAgingDays(row.sent_back_date||null)}</td>
          <td><span class="mono" style="color:#5b21b6;font-size:12.5px;">${esc(row.cheque_no||'—')}</span></td>
          <td><div style="font-size:12px;font-weight:600;color:#1f2937;white-space:nowrap;">${esc(row.bank_name||row.bank_code||'—')}</div>${row.branch_name?`<div class="cust-sub">${esc(row.branch_name)}</div>`:''}</td>
          <td class="tc"><span class="mono" style="font-size:11px;">${esc(row.bank_code||'—')}</span></td>
          <td class="tr"><span class="amt-cell">Rs.&nbsp;${cheqAmt.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</span></td>
          <td><span class="mono" style="font-size:11.5px;color:#4338ca;">${esc(row.t_code||'—')}</span>${row.customer_name?`<div class="cust-sub">${esc(row.customer_name)}</div>`:''}</td>
          <td><span class="mono" style="font-size:11.5px;">${esc(row.invoice_num||'—')}</span></td>
          <td>${reason?`<span class="reason-chip" title="${esc(reason)}">${esc(reason)}</span>`:'<span style="color:#d1d5db;font-size:11px;">—</span>'}</td>
          <td class="tc">${settleBadge}</td>
          <td class="tc">${buildIssueStatusBadge(row)}</td>
          <td class="tc no-print"><div style="display:flex;flex-direction:column;gap:4px;align-items:center;">${actionBtn}${tbdBtn}${returnBtn}</div></td>
        </tr>`;
    });
    tbody.innerHTML=html;buildPager(pages,total,offset,Math.min(offset+PAGE_SIZE,total));

    /* fully-settled cheques still marked 'issued' get auto-updated to Issued to Customer */
    rows.forEach(row=>{
        const settled=parseInt(row.sb_settled||0);
        if(settled && row.issue_item_status==='issued'){
            autoIssueToCustomerIfFullySettled(document.getElementById('row-'+row.id));
        }
    });
}

function buildPager(pages,total,start,end){const pager=document.getElementById('pager'),info=document.getElementById('pagerInfo'),wrap=document.getElementById('pagerWrap');if(!pager)return;if(pages<=1){wrap.style.display='none';return;}wrap.style.display='flex';if(info)info.textContent=`Showing ${start+1}–${end} of ${total}`;let html=`<button class="pager-btn" onclick="goPage(${currentPage-1})" ${currentPage===1?'disabled':''}>‹ Prev</button>`;let lo=Math.max(1,currentPage-3),hi=Math.min(pages,currentPage+3);if(lo>1)html+=`<button class="pager-btn" onclick="goPage(1)">1</button>${lo>2?'<span style="color:#9ca3af;padding:0 3px;">…</span>':''}`;for(let p=lo;p<=hi;p++)html+=`<button class="pager-btn ${p===currentPage?'active':''}" onclick="goPage(${p})">${p}</button>`;if(hi<pages)html+=`${hi<pages-1?'<span style="color:#9ca3af;padding:0 3px;">…</span>':''}<button class="pager-btn" onclick="goPage(${pages})">${pages}</button>`;html+=`<button class="pager-btn" onclick="goPage(${currentPage+1})" ${currentPage===pages?'disabled':''}>Next ›</button>`;pager.innerHTML=html;}
function goPage(p){currentPage=p;fetchRows();document.querySelector('.dt-outer')?.scrollTo(0,0);}

const chqInput=document.getElementById('chqSearch'),chqClr=document.getElementById('chqClr');
if(chqInput){chqInput.addEventListener('input',function(){currentSearch=this.value.trim();chqClr?.classList.toggle('show',currentSearch.length>0);clearTimeout(fetchTimer);fetchTimer=setTimeout(()=>{currentPage=1;fetchRows();},350);});}
function clearSearch(){if(chqInput)chqInput.value='';chqClr?.classList.remove('show');currentSearch='';currentPage=1;fetchRows();}
document.addEventListener('DOMContentLoaded',()=>fetchRows());

document.getElementById('mainTbody').addEventListener('click',function(e){
    const btn=e.target.closest('.btn-pending-action');if(!btn||btn.disabled)return;
    openTbdModal(btn.dataset.id,btn.dataset.chequeNo,btn.dataset.amount,btn.dataset.tcode,btn.dataset.customer,btn.dataset.chequeDate,btn.dataset.bank,btn.dataset.received);
});

document.addEventListener('keydown',e=>{if(e.key==='Escape'){if(document.getElementById('tbdModal').classList.contains('open')){closeTbdModal();return;}closeIssueConfirmModal();closeIssDetail();closeDeleteIssue();closeHistoryDrawer();closeIssueDrawer();closeSettleModal();closeReturnIssueModal();}});

/* ══════════════════════════════════════════════════
   COLLECTOR DETAILS — Settlement Modal
   (mirrors return_cheques.php implementation)
══════════════════════════════════════════════════ */
let _sbCollectorType = 'cc';
let _sbPersonsLoaded = false;

function sbSetCollectorType(type) {
    _sbCollectorType = type;
    document.getElementById('sbDpWrap').style.display  = type === 'cc' ? '' : 'none';
    document.getElementById('sbSrWrap').style.display  = type === 'sr' ? '' : 'none';
    document.getElementById('sbCtypeCC').className = 'ctype-btn' + (type === 'cc' ? ' active-cc' : '');
    document.getElementById('sbCtypeSR').className = 'ctype-btn' + (type === 'sr' ? ' active-sr' : '');
}

async function sbLoadCollectorPersons() {
    if (_sbPersonsLoaded) return;
    /* Load delivery persons */
    const dpSt = document.getElementById('sbDpStatus');
    dpSt.textContent = 'Loading…'; dpSt.className = 'dp-modal-status';
    try {
        const r = await fetch('get_delivery_persons.php');
        const d = await r.json();
        const dpSel = document.getElementById('sbDpSelect');
        if (d.success && d.persons && d.persons.length) {
            d.persons.forEach(name => {
                const o = document.createElement('option'); o.value = name; o.textContent = name;
                dpSel.appendChild(o);
            });
            dpSt.textContent = d.persons.length + ' person(s) loaded';
            dpSt.className = 'dp-modal-status ok';
        } else {
            dpSt.textContent = 'No delivery persons found';
            dpSt.className = 'dp-modal-status err';
        }
        if (window.$) $('#sbDpSelect').select2({ placeholder: '-- Select Delivery Person --', allowClear: true, width: '100%', dropdownParent: $('#settleModal') });
    } catch(e) {
        dpSt.textContent = 'Error loading'; dpSt.className = 'dp-modal-status err';
    }
    /* Load SR codes and employees via issue_persons endpoint */
    try {
        const r2 = await fetch('sentback_cheques.php?ajax=issue_persons');
        const d2 = await r2.json();
        if (d2.success) {
            const srSel   = document.getElementById('sbSrSelect');
            const empSel  = document.getElementById('sbEmpSelect');
            const empSel2 = document.getElementById('sbSrEmpSelect');
            d2.sr_persons.forEach(p => {
                const o = document.createElement('option'); o.value = p.code; o.textContent = p.code; srSel.appendChild(o);
            });
            d2.employees.forEach(e => {
                const label = (e.employee_id || '') + ' — ' + e.employee_full_name + (e.designation_name ? ' (' + e.designation_name + ')' : '');
                [empSel, empSel2].forEach(sel => {
                    const o = document.createElement('option'); o.value = e.id; o.textContent = label; sel.appendChild(o);
                });
            });
            if (window.$) {
                const parent = $('#settleModal');
                $('#sbSrSelect').select2({ placeholder: '-- Select SR Code --', allowClear: true, width: '100%', dropdownParent: parent });
                $('#sbEmpSelect').select2({ placeholder: '-- Select Employee --', allowClear: true, width: '100%', dropdownParent: parent });
                $('#sbSrEmpSelect').select2({ placeholder: '-- Select Employee --', allowClear: true, width: '100%', dropdownParent: parent });
            }
        }
    } catch(e) {}
    _sbPersonsLoaded = true;
}

function sbGetCollectorValues() {
    if (_sbCollectorType === 'cc') {
        return {
            collected_by:    'cc',
            delivery_person: (window.$ ? $('#sbDpSelect').val() : document.getElementById('sbDpSelect').value) || '',
            sr_code:         '',
            employee_id:     (window.$ ? $('#sbEmpSelect').val() : document.getElementById('sbEmpSelect').value) || '',
        };
    } else {
        return {
            collected_by:    'sr',
            delivery_person: '',
            sr_code:         (window.$ ? $('#sbSrSelect').val() : document.getElementById('sbSrSelect').value) || '',
            employee_id:     (window.$ ? $('#sbSrEmpSelect').val() : document.getElementById('sbSrEmpSelect').value) || '',
        };
    }
}

/* ══ Settlement Modal ══ */
let ARD={},chequeCounter=0;const dupCache={};

async function openSettleModal(chequeId){
    try{const res=await fetch(`sentback_cheques.php?ajax=sentback_cheque_detail&cheque_id=${encodeURIComponent(chequeId)}`);const data=await res.json();if(!data.success){showToast(data.error||'Could not load','err');return;}
        const ch=data.cheque;ARD={chequeId,id:ch.field_summary_detail_id,fsid:ch.field_summary_id,tcode:ch.t_code,invoice:ch.invoice_num,customer:ch.customer_name,adjust:parseFloat(ch.invoice_amt||0),paid:parseFloat(ch.total_paid||0),balance:parseFloat(ch.balance||0),payMode:ch.payment_mode||'',creditLimit:ch.credit_limit||'',creditDays:ch.credit_days||'',specialDays:ch.special_credit_policy_days||'',delivDate:(ch.delivery_date||new Date().toISOString()).substring(0,10),chequeNo:ch.cheque_no,chequeDate:ch.cheque_date,chequeAmt:parseFloat(ch.total_amount||0),bankName:ch.bank_name||ch.bank_code||'—',sentBackReason:ch.sent_back_reason||'',
             issueItemId:ch.issue_item_id||'',issueItemStatus:ch.issue_item_status||'',issueCode:ch.issue_code||'',sbSettled:parseInt(ch.sb_settled||0)};
        renderModalReturnBox();
        document.getElementById('modalSubtitle').textContent='Invoice: '+ARD.invoice+' | '+ARD.customer;
        document.getElementById('hdrInv').textContent='Rs. '+ARD.adjust.toFixed(2);document.getElementById('hdrPaid').textContent='Rs. '+ARD.paid.toFixed(2);document.getElementById('hdrBal').textContent='Rs. '+ARD.balance.toFixed(2);document.getElementById('hdrSentBack').textContent='Rs. '+ARD.chequeAmt.toFixed(2);
        document.getElementById('bnrChequeNo').textContent=ARD.chequeNo||'—';document.getElementById('bnrChequeDate').textContent=ARD.chequeDate||'—';document.getElementById('bnrBank').textContent=ARD.bankName;document.getElementById('bnrAmt').textContent='Rs. '+ARD.chequeAmt.toFixed(2);
        const bnrReason=document.getElementById('bnrReason');if(ARD.sentBackReason){bnrReason.textContent='Send Back Reason: '+ARD.sentBackReason;bnrReason.style.display='';}else{bnrReason.style.display='none';}
        document.getElementById('cashDate').value=ARD.delivDate;document.getElementById('chqPayDate').value=ARD.delivDate;
        ['cashAmount','cashToBank','cashRef','cashRemarks','chqRef','chqRemarks','settlementNote'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
        document.getElementById('cashCollectedBy').value='cc';document.getElementById('chqModeSelect').value='payee_only';
        setCI('chqLimit',ARD.creditLimit?'Rs. '+parseFloat(ARD.creditLimit).toLocaleString():'—');setCI('chqDays',ARD.creditDays||'—');setCI('chqSpecial',ARD.specialDays||'—');
        document.getElementById('chequesContainer').innerHTML='';chequeCounter=0;addCheque();syncPreviews();
        /* Reset collector section */
        _sbCollectorType = 'cc';
        sbSetCollectorType('cc');
        sbLoadCollectorPersons();
        const modal=document.getElementById('settleModal');if(modal.parentElement!==document.body)document.body.appendChild(modal);modal.classList.add('open');document.body.classList.add('modal-sb-open');loadSettlementPayments();
    }catch(e){showToast('Error: '+e.message,'err');}
}
function closeSettleModal(){document.getElementById('settleModal').classList.remove('open');document.body.classList.remove('modal-sb-open');ARD={};}
document.getElementById('settleModal').addEventListener('click',function(e){if(e.target===this)closeSettleModal();});
document.getElementById('tbdModal').addEventListener('click',function(e){if(e.target===this)closeTbdModal();});
function setCI(id,v){const el=document.getElementById(id);if(el)el.textContent=v||'—';}

function syncPreviews(){const remaining=parseFloat(ARD.balance)||0,cashAmt=Math.max(0,parseFloat(document.getElementById('cashAmount').value)||0);let chqTotal=0;document.querySelectorAll('#chequesContainer .chq-amt').forEach(i=>{chqTotal+=parseFloat(i.value)||0;});const totalPaying=cashAmt+chqTotal,newBal=Math.max(0,remaining-totalPaying);document.getElementById('sumCash').textContent='Rs. '+cashAmt.toFixed(2);document.getElementById('sumCheque').textContent='Rs. '+chqTotal.toFixed(2);document.getElementById('sumTotal').textContent='Rs. '+totalPaying.toFixed(2);document.getElementById('sumBalance').textContent='Rs. '+newBal.toFixed(2);document.getElementById('hdrBal').textContent='Rs. '+newBal.toFixed(2);}

function addCheque(){chequeCounter++;const idx=chequeCounter;let bankOpts='<option value="">— Select Bank —</option>';BANKS.forEach(b=>{bankOpts+=`<option value="${b.bank_code}" data-name="${b.bank_name}">${b.bank_code} – ${b.bank_name}</option>`;});
    const card=document.createElement('div');card.className='cheque-card';card.id='cheque-'+idx;
    card.innerHTML=`<div class="cheque-card-header"><span class="cheque-card-title"><i class="fa-solid fa-money-check"></i> Replacement Cheque #${idx}</span>${idx>1?`<button type="button" class="btn-remove-cheque" onclick="document.getElementById('cheque-${idx}').remove();syncPreviews()"><i class="fa-solid fa-trash"></i> Remove</button>`:''}</div>
      <div class="gr gr3"><div class="fg"><label>Cheque No. <span class="req">*</span></label><input type="text" class="fctrl" id="chqno-${idx}" placeholder="e.g. 001234" oninput="checkDupCheque(${idx})"><div class="dup-cheque-warn" id="dup-warn-${idx}"></div></div>
      <div class="fg"><label>Cheque Date</label><input type="date" class="fctrl" id="chqdate-${idx}" value="${ARD.delivDate||''}"></div>
      <div class="fg"><label>Amount (Rs.) <span class="req">*</span></label><input type="number" class="fctrl chq-amt" id="chqamt-${idx}" step="0.01" min="0" placeholder="0.00" oninput="syncPreviews();checkDupCheque(${idx})"></div></div>
      <div class="gr gr2"><div class="fg"><label>Bank <span class="req">*</span></label><select class="fctrl" id="chq-bank-${idx}">${bankOpts}</select></div>
      <div class="fg"><label>Branch</label><select class="fctrl" id="chq-branch-${idx}"><option value="">— Select Branch —</option></select></div></div>`;
    document.getElementById('chequesContainer').appendChild(card);
    $(`#chq-bank-${idx}`).select2({width:'100%',dropdownParent:$('#settleModal')}).on('change',function(){loadBranches(this.value,idx);});
    $(`#chq-branch-${idx}`).select2({width:'100%',dropdownParent:$('#settleModal')});
}
function loadBranches(bankCode,idx){const sel=document.getElementById('chq-branch-'+idx);sel.innerHTML='<option value="">Loading…</option>';$(sel).select2('destroy');if(!bankCode){sel.innerHTML='<option value="">— Select Branch —</option>';$(sel).select2({width:'100%',dropdownParent:$('#settleModal')});return;}fetch('get_bank_branches.php?bank_code='+encodeURIComponent(bankCode)).then(r=>r.json()).then(data=>{let opts='<option value="">— Select Branch —</option>';data.forEach(b=>{opts+=`<option value="${b.branch_code}" data-name="${b.branch_name}">${b.branch_code} – ${b.branch_name}</option>`;});sel.innerHTML=opts;$(sel).select2({width:'100%',dropdownParent:$('#settleModal')});}).catch(()=>{sel.innerHTML='<option value="">Error</option>';$(sel).select2({width:'100%',dropdownParent:$('#settleModal')});});}
function checkDupCheque(idx){const no=(document.getElementById('chqno-'+idx)?.value||'').trim(),amt=parseFloat(document.getElementById('chqamt-'+idx)?.value||0),warn=document.getElementById('dup-warn-'+idx);if(!no||!warn)return;if(dupCache[no]!==undefined){showDupWarning(warn,no,dupCache[no],amt);return;}fetch('get_cheque_info.php?cheque_no='+encodeURIComponent(no)).then(r=>r.json()).then(data=>{dupCache[no]=data.exists?data.total_amount:null;showDupWarning(warn,no,dupCache[no],amt);}).catch(()=>{});}
function showDupWarning(warn,no,existingTotal,newAmt){if(existingTotal!==null&&existingTotal!==undefined){const newTotal=(parseFloat(existingTotal)||0)+(parseFloat(newAmt)||0);warn.innerHTML=`<i class="fa-solid fa-triangle-exclamation"></i> Cheque #${no} already exists (Rs. ${parseFloat(existingTotal).toFixed(2)}). Total: Rs. ${newTotal.toFixed(2)}.`;warn.classList.add('show');}else{warn.classList.remove('show');warn.innerHTML='';}}

async function loadSettlementPayments(){const wrap=document.getElementById('spmTableWrap');if(!wrap||!ARD.chequeId)return;wrap.innerHTML='<div class="spm-empty"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';try{const res=await fetch(`sentback_cheques.php?ajax=sb_settlement_payments&cheque_id=${ARD.chequeId}`);const data=await res.json();ARD.settledTotal=parseFloat(data.total_settled||0);renderSettlementPayments(data.payments||[],data.total_settled||0);}catch(e){wrap.innerHTML='<div class="spm-empty" style="color:#7c3aed;">Error loading</div>';}}

function renderSettlementPayments(payments,totalSettled){
    const wrap=document.getElementById('spmTableWrap'),badge=document.getElementById('spmTotalBadge');if(badge)badge.textContent=totalSettled>0?'Total: Rs. '+parseFloat(totalSettled).toFixed(2):'';
    if(!payments.length){wrap.innerHTML='<div class="spm-empty"><i class="fa-solid fa-circle-info"></i> No payments recorded yet.</div>';return;}
    let rows='';
    payments.forEach(p=>{const isCash=p.payment_method==='cash',isCheque=p.payment_method==='cheque',mc=isCash?'spm-method-cash':isCheque?'spm-method-cheque':'spm-method-other';
    let detailCell='';if(isCheque&&p.cheque_no){detailCell=`<div class="rchq-card"><div class="rchq-no"><i class="fa-solid fa-money-check"></i>${esc(p.cheque_no)}</div><div class="rchq-grid"><div class="rchq-row"><span class="rchq-lbl">Bank</span><span class="rchq-val">${esc([p.bank_code,p.bank_name].filter(Boolean).join(' · ')||'—')}</span></div><div class="rchq-row"><span class="rchq-lbl">Branch</span><span class="rchq-val">${esc(p.branch_name||'—')}</span></div><div class="rchq-row"><span class="rchq-lbl">Date</span><span class="rchq-val">${esc(p.cheque_date||'—')}</span></div><div class="rchq-row"><span class="rchq-lbl">Amount</span><span class="rchq-val" style="color:#1e40af;font-weight:800;">Rs. ${parseFloat(p.amount).toFixed(2)}</span></div></div></div>`;}
    else if(isCheque){detailCell=`<span style="font-size:11px;color:#6b7280;font-style:italic;">Cheque — no details</span>`;}
    else if(isCash){detailCell=`<span style="font-size:11px;color:#16a34a;font-weight:600;display:flex;align-items:center;gap:4px;"><i class="fa-solid fa-coins"></i> Cash Payment</span>`;}
    else{detailCell=`<span style="font-size:11px;color:#6b7280;">—</span>`;}
    rows+=`<tr><td>${esc(p.payment_date||'—')}</td><td><span class="${mc}">${esc(p.payment_method)}</span></td><td style="font-weight:700;color:#166534;white-space:nowrap;">Rs. ${parseFloat(p.amount).toFixed(2)}</td><td>${detailCell}</td><td style="font-size:11px;">${esc(p.reference_no||'—')}</td><td style="font-size:11px;">${esc(p.remarks||'—')}</td><td><button class="btn-del-pay" onclick="deleteSettlementPayment(${p.id})"><i class="fa-solid fa-trash"></i></button></td></tr>`;});
    const total=payments.reduce((s,p)=>s+parseFloat(p.amount),0);
    ARD.settledTotal=total;
    wrap.innerHTML=`<table class="spm-table"><thead><tr><th>Date</th><th>Method</th><th>Amount</th><th>Details</th><th>Reference</th><th>Remarks</th><th></th></tr></thead><tbody>${rows}</tbody><tfoot><tr class="spm-total-row"><td colspan="2" style="padding:7px 10px;">Total Settled</td><td style="padding:7px 10px;">Rs. ${total.toFixed(2)}</td><td colspan="4"></td></tr></tfoot></table>`;
    document.getElementById('hdrPaid').textContent='Rs. '+total.toFixed(2);document.getElementById('hdrBal').textContent='Rs. '+Math.max(0,ARD.chequeAmt-total).toFixed(2);
}
async function deleteSettlementPayment(pid){if(!confirm('Delete this payment?'))return;const fd=new FormData();fd.append('ajax_action','delete_sb_settlement_payment');fd.append('payment_id',pid);fd.append('cheque_id',ARD.chequeId);try{const res=await fetch('sentback_cheques.php',{method:'POST',body:fd});const data=await res.json();if(!data.success){showToast(data.error||'Delete failed','err');return;}showToast('Payment deleted','ok');await loadSettlementPayments();updateRowBadge(ARD.chequeId,data.new_total,data.fully_settled,data.cheque_total||ARD.chequeAmt);}catch(e){showToast('Error: '+e.message,'err');}}

function updateRowBadge(chequeId,newTotal,fullSettled,chequeAmt){const rowEl=document.getElementById('row-'+chequeId);if(!rowEl)return;const settleCell=rowEl.querySelector('td:nth-child(15)'),actionCell=rowEl.querySelector('td:last-child'),paid=parseFloat(newTotal)||0,total=parseFloat(chequeAmt)||0,remain=Math.max(0,total-paid);
    const pendBtn=fullSettled?`<button class="btn-tbd" disabled style="opacity:.4;cursor:not-allowed;filter:grayscale(1);"><i class="fa-solid fa-clock"></i> Pending</button>`:`<button class="btn-tbd btn-pending-action" data-id="${chequeId}"><i class="fa-solid fa-clock"></i> Pending</button>`;
    const retBtnEl = rowEl.querySelector('.btn-return-issue');
    const retBtnHtml = retBtnEl ? retBtnEl.outerHTML : '';
    if(paid<=0){if(settleCell)settleCell.innerHTML=`<span class="settled-badge no"><i class="fa-solid fa-clock"></i> Unsettled</span><div class="settle-meta" style="color:#9ca3af;">Paid: Rs.0.00 | Bal: Rs.${total.toFixed(2)}</div>`;if(actionCell)actionCell.innerHTML=`<div style="display:flex;flex-direction:column;gap:4px;align-items:center;"><button class="btn-loadpay" onclick="openSettleModal(${chequeId})"><i class="fa-solid fa-money-bill-transfer"></i> Load Pay</button>${pendBtn}${retBtnHtml}</div>`;rowEl.dataset.settled='0';}
    else if(fullSettled){if(settleCell)settleCell.innerHTML=`<span class="settled-badge yes"><i class="fa-solid fa-circle-check"></i> Fully Settled</span><div class="settle-meta" style="color:#166534;">Paid: Rs.${paid.toFixed(2)} | Bal: Rs.0.00</div>`;if(actionCell)actionCell.innerHTML=`<div style="display:flex;flex-direction:column;gap:4px;align-items:center;"><button class="btn-loadpay settled" onclick="openSettleModal(${chequeId})"><i class="fa-solid fa-circle-check"></i> View</button>${pendBtn}${retBtnHtml}</div>`;rowEl.dataset.settled='1';}
    else{if(settleCell)settleCell.innerHTML=`<span class="settled-badge no" style="background:#fff7ed;color:#c2410c;border-color:#fed7aa;"><i class="fa-solid fa-circle-half-stroke"></i> Partially</span><div class="settle-meta" style="color:#92400e;">Paid: Rs.${paid.toFixed(2)} | Bal: Rs.${remain.toFixed(2)}</div>`;if(actionCell)actionCell.innerHTML=`<div style="display:flex;flex-direction:column;gap:4px;align-items:center;"><button class="btn-loadpay" onclick="openSettleModal(${chequeId})"><i class="fa-solid fa-money-bill-transfer"></i> Load Pay</button>${pendBtn}${retBtnHtml}</div>`;rowEl.dataset.settled='0';}
    if(fullSettled) autoIssueToCustomerIfFullySettled(rowEl);
    if(ARD && ARD.chequeId == chequeId){ARD.sbSettled = fullSettled ? 1 : 0; renderModalReturnBox();}}

async function submitSettlement(){
    const btn=document.getElementById('submitSettleBtn'),cashAmt=parseFloat(document.getElementById('cashAmount').value)||0,cashDate=document.getElementById('cashDate').value,settlementNote=document.getElementById('settlementNote').value||'';
    const chqCards=document.querySelectorAll('#chequesContainer .cheque-card');let chqTotal=0,chqValid=true,cheques=[];
    chqCards.forEach(card=>{const idx=parseInt(card.id.replace('cheque-',''));const no=(document.getElementById('chqno-'+idx)?.value||'').trim(),amt=parseFloat(document.getElementById('chqamt-'+idx)?.value||0),dt=document.getElementById('chqdate-'+idx)?.value||'';const bkCode=$(`#chq-bank-${idx}`).val()||'',bkSel=document.getElementById('chq-bank-'+idx),bkName=bkSel?.selectedOptions[0]?.dataset.name||bkSel?.selectedOptions[0]?.text||'';const brCode=$(`#chq-branch-${idx}`).val()||'',brSel=document.getElementById('chq-branch-'+idx),brName=brSel?.selectedOptions[0]?.dataset.name||brSel?.selectedOptions[0]?.text||'';if(amt>0){if(!no)chqValid=false;chqTotal+=amt;cheques.push({cheque_no:no,cheque_date:dt,amount:amt,bank_code:bkCode,bank_name:bkName,branch_code:brCode,branch_name:brName});}});
    if(cashAmt<=0&&chqTotal<=0){showToast('Enter a cash amount or at least one cheque amount.','err');return;}
    if(cashAmt>0&&!cashDate){showToast('Select a Payment Date for cash.','err');return;}
    if(chqTotal>0&&!chqValid){showToast('Fill in all Cheque Numbers.','err');return;}

    /* ── Issued-cheque rule ──────────────────────────────
       part payment  → the Return box MUST be ticked
                       (cheque comes back — status → Returned)
       full payment  → status auto-updates to Issued to Customer */
    const _payNow     = cashAmt + chqTotal;
    const _alreadyStl = parseFloat(ARD.settledTotal||0);
    const _willBeFull = (_alreadyStl + _payNow) >= (parseFloat(ARD.chequeAmt||0) - 0.009);
    if(ARD.issueItemId && ARD.issueItemStatus === 'issued' && !_willBeFull){
        const rcb = document.getElementById('modalReturnCb');
        if(!rcb || !rcb.checked){
            showToast('You cannot save — this is a part payment for an ISSUED cheque. Please tick the Return box first.','err');
            const lbl = document.getElementById('modalReturnCbLabel');
            if(lbl){ lbl.style.outline='2px solid #dc2626'; lbl.style.outlineOffset='2px'; setTimeout(()=>{lbl.style.outline='';},3000); }
            const rbx = document.getElementById('modalReturnBox');
            if(rbx) rbx.scrollIntoView({behavior:'smooth',block:'center'});
            return;
        }
    }

    /* Build base payload with collector details */
    function basePayload(){
        const fd = new FormData();
        const cv = sbGetCollectorValues();
        fd.append('field_summary_id',        ARD.fsid);
        fd.append('field_summary_detail_id', ARD.id);
        fd.append('t_code',                  ARD.tcode);
        fd.append('invoice_num',             ARD.invoice);
        fd.append('has_emergency_credit',    '0');
        fd.append('collected_by',            cv.collected_by);
        fd.append('delivery_person',         cv.delivery_person);
        fd.append('sr_code',                 cv.sr_code);
        fd.append('employee_id',             cv.employee_id);
        return fd;
    }

    btn.disabled=true;btn.innerHTML='<span class="spinner"></span> Saving…';let saved=[];
    try{
        if(cashAmt>0){const fd=basePayload();fd.append('payment_method','cash');fd.append('payment_date',cashDate);fd.append('amount',cashAmt);fd.append('amount_to_bank',document.getElementById('cashToBank').value||0);fd.append('reference_no',document.getElementById('cashRef').value||'');fd.append('collected_by',document.getElementById('cashCollectedBy').value);fd.append('remarks',document.getElementById('cashRemarks').value||'');fd.append('payment_source','sentback_cheque_settlement');
            const res=await fetch('save_payment.php',{method:'POST',body:fd});const data=await res.json();if(!data.success){showToast('Cash error: '+(data.error||'Unknown'),'err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Save Payment';return;}
            const fds=new FormData();fds.append('ajax_action','add_sb_settlement_payment');fds.append('cheque_id',ARD.chequeId);fds.append('payment_method','cash');fds.append('payment_date',cashDate);fds.append('amount',cashAmt.toFixed(2));fds.append('reference_no',document.getElementById('cashRef').value||'');fds.append('remarks',document.getElementById('cashRemarks').value||settlementNote);
            const sr=await fetch('sentback_cheques.php',{method:'POST',body:fds});const sd=await sr.json();if(sd.new_total!==undefined)updateRowBadge(ARD.chequeId,sd.new_total,sd.fully_settled,sd.cheque_total||ARD.chequeAmt);saved.push('💵 Cash Rs.'+cashAmt.toFixed(2));}
        if(chqTotal>0){const fd=basePayload();fd.append('payment_method','cheque');fd.append('payment_date',document.getElementById('chqPayDate').value||new Date().toISOString().slice(0,10));fd.append('amount',chqTotal);fd.append('reference_no',document.getElementById('chqRef').value||'');fd.append('cheque_mode',document.getElementById('chqModeSelect').value||'payee_only');fd.append('collected_by',document.getElementById('cashCollectedBy').value);fd.append('remarks',document.getElementById('chqRemarks').value||'');fd.append('payment_source','sentback_cheque_settlement');
            cheques.forEach((q,i)=>{fd.append(`cheques[${i}][cheque_no]`,q.cheque_no);fd.append(`cheques[${i}][cheque_date]`,q.cheque_date);fd.append(`cheques[${i}][amount]`,q.amount);fd.append(`cheques[${i}][bank_code]`,q.bank_code);fd.append(`cheques[${i}][bank_name]`,q.bank_name);fd.append(`cheques[${i}][branch_code]`,q.branch_code);fd.append(`cheques[${i}][branch_name]`,q.branch_name);});
            const res=await fetch('save_payment.php',{method:'POST',body:fd});const data=await res.json();if(!data.success){showToast('Cheque error: '+(data.error||'Unknown'),'err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Save Payment';return;}
            for(const q of cheques){const fds=new FormData();fds.append('ajax_action','add_sb_settlement_payment');fds.append('cheque_id',ARD.chequeId);fds.append('payment_method','cheque');fds.append('payment_date',document.getElementById('chqPayDate').value||new Date().toISOString().slice(0,10));fds.append('amount',q.amount.toFixed(2));fds.append('cheque_no',q.cheque_no);fds.append('cheque_date',q.cheque_date);fds.append('bank_name',q.bank_name);fds.append('bank_code',q.bank_code);fds.append('branch_name',q.branch_name);fds.append('reference_no',document.getElementById('chqRef').value||'');fds.append('remarks',document.getElementById('chqRemarks').value||settlementNote);const sr=await fetch('sentback_cheques.php',{method:'POST',body:fds});const sd=await sr.json();if(sd.new_total!==undefined)updateRowBadge(ARD.chequeId,sd.new_total,sd.fully_settled,sd.cheque_total||ARD.chequeAmt);}
            saved.push('🏦 Cheque Rs.'+chqTotal.toFixed(2));}
        /* ── update Cheque Issue status after saving the payment ── */
        if(ARD.issueItemId && ARD.issueItemStatus === 'issued'){
            if(_willBeFull){
                await markIssueStatusAfterPayment('mark_issued_to_customer','issued_to_customer','Fully paid — cheque status updated to Issued to Customer ✓');
            } else {
                await markIssueStatusAfterPayment('mark_returned','returned','Part payment saved — cheque status updated to Return ✓');
            }
        }
        ['cashAmount','cashToBank','cashRef','cashRemarks','chqRef','chqRemarks','settlementNote'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
        document.getElementById('chequesContainer').innerHTML='';chequeCounter=0;addCheque();await loadSettlementPayments();showToast(saved.join(' + ')+' ✓ Saved','ok');
    }catch(err){showToast('Network error: '+err.message,'err');}
    btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Save Payment';
}

/* ══ Pending Modal ══ */
function openTbdModal(chequeId,chequeNo,amount,tcode,customer,chequeDate,bank,receivedDate){document.getElementById('tbdChequeId').value=chequeId;document.getElementById('tbdChequeNo').textContent=chequeNo||'—';document.getElementById('tbdAmount').textContent='Rs. '+parseFloat(amount||0).toFixed(2);document.getElementById('tbdTcode').textContent=tcode||'—';document.getElementById('tbdCustomer').textContent=customer||'—';document.getElementById('tbdChequeDate').textContent=chequeDate||'—';document.getElementById('tbdBank').textContent=bank||'—';const today=new Date().toISOString().slice(0,10);document.getElementById('tbdReceivedDate').value=(receivedDate&&receivedDate!=='—'&&receivedDate!=='0000-00-00')?receivedDate.substring(0,10):today;const modal=document.getElementById('tbdModal');if(modal.parentElement!==document.body)document.body.appendChild(modal);modal.classList.add('open');document.body.classList.add('modal-sb-open');}
function closeTbdModal(){document.getElementById('tbdModal').classList.remove('open');document.body.classList.remove('modal-sb-open');}

async function submitToBeDeposit(){const btn=document.getElementById('tbdConfirmBtn'),chequeId=document.getElementById('tbdChequeId').value,receivedDate=document.getElementById('tbdReceivedDate').value;if(!chequeId){showToast('Invalid cheque','err');return;}if(!receivedDate){showToast('Please select a received date','err');return;}btn.disabled=true;btn.innerHTML='<span class="spinner"></span> Updating…';try{const fd=new FormData();fd.append('ajax_action','mark_to_be_deposit');fd.append('cheque_id',chequeId);fd.append('received_date',receivedDate);const res=await fetch('sentback_cheques.php',{method:'POST',body:fd});const data=await res.json();if(!data.success){showToast(data.error||'Update failed','err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-clock"></i> Confirm Pending';return;}showToast('Cheque #'+(data.cheque_no||chequeId)+' → Pending ✓','ok');closeTbdModal();const rowEl=document.getElementById('row-'+chequeId);if(rowEl){rowEl.style.transition='opacity .4s';rowEl.style.background='#ecfeff';rowEl.style.opacity='0';setTimeout(()=>rowEl.remove(),450);}setTimeout(()=>fetchRows(),600);}catch(e){showToast('Network error: '+e.message,'err');}btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-clock"></i> Confirm Pending';}

function showToast(msg,type){const t=document.getElementById('sbToast');t.style.background=type==='ok'?'#166534':'#7c3aed';t.textContent=msg;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),3200);}

function exportCSV(){const rows=document.querySelectorAll('#mainTable tbody tr');if(!rows.length){alert('No data.');return;}const headers=['No','SR Code','Cheque Date','Received Date','Sent Back Date','Aging Days','Cheque No','Bank','Bank Code','Amount','T-Code','Customer','Invoice','Reason','Settlement'];const lines=[headers.join(',')];const q=v=>'"'+(v||'').toString().replace(/"/g,'""').replace(/\s+/g,' ').trim()+'"';rows.forEach((tr,i)=>{const tds=tr.querySelectorAll('td');lines.push([i+1,q(tds[2]?.textContent),q(tds[3]?.textContent),q(tds[4]?.textContent),q(tds[5]?.querySelector('.sentback-date-chip')?.textContent||''),q(tds[6]?.querySelector('.aging-chip')?.textContent||''),q(tds[7]?.querySelector('.mono')?.textContent||''),q(tds[8]?.querySelector('div')?.textContent||''),q(tds[9]?.textContent),q(tds[10]?.textContent),q(tds[11]?.querySelector('.mono')?.textContent||''),q(tds[11]?.querySelector('.cust-sub')?.textContent||''),q(tds[12]?.textContent),q(tds[13]?.querySelector('.reason-chip')?.textContent||''),q(tr.dataset.settled==='1'?'Fully Settled':'Unsettled')].join(','));});const blob=new Blob([lines.join('\n')],{type:'text/csv'});const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download='sentback_cheques_<?php echo date("Ymd_Hi"); ?>.csv';a.click();URL.revokeObjectURL(url);}
</script>

<?php include 'footer.php'; ?>