<?php
/**
 * return_cheques.php — Returned Cheques Register
 * - Shows only cheques with status = 'returned'
 * - Settlement modal to settle returned cheque debt
 * - CRN button → upload PDF → Gemini AI scans → extracts CRN details → DB update
 * - Remove CRN option to clear uploaded CRN details
 * UPDATED:
 *  - Enlarged stat cards with amount as primary value + count sub-label
 *  - Return Date column added to main table
 *  - Settlement payments table now shows full cheque detail card (bank, branch, date)
 *  - cheque_settlement_payments table extended with cheque detail columns
 *  - CRN upload now accepts multiple images (JPG/PNG) instead of just PDF
 *  - Return Date is now editable (click to update) via update_return_date AJAX action
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

/* ══════════════════════════════════════════════════════
   AJAX — get Gemini API key
══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_api_key') {
    ob_start(); include_once 'config.php'; ob_end_clean();
    header('Content-Type: application/json');
    $r   = mysqli_query($conn, "SELECT `value` FROM ai_settings WHERE `key`='gemini_api_key' LIMIT 1");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    $key = trim($row['value'] ?? '');
    echo json_encode(['success' => true, 'has_key' => ($key !== ''), 'key' => $key]);
    exit;
}
if (isset($_GET['ajax']) && $_GET['ajax'] === 'issue_persons') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');
    $cc_r = mysqli_query($conn, "SELECT DISTINCT delivery_person AS code, delivery_person AS label
        FROM loading_summary_import_details
        WHERE delivery_person IS NOT NULL AND delivery_person <> ''
        ORDER BY delivery_person");
    $cc = [];
    if ($cc_r) while ($r = mysqli_fetch_assoc($cc_r)) $cc[] = $r;
    $sr_r = mysqli_query($conn, "SELECT DISTINCT sr_code AS code, sr_code AS label FROM field_summary ORDER BY sr_code");
    $sr = [];
    if ($sr_r) while ($r = mysqli_fetch_assoc($sr_r)) $sr[] = $r;
    $emp_r = mysqli_query($conn, "SELECT e.id, e.employee_id, e.employee_full_name,
        COALESCE(d.designation_name,'') AS designation_name
        FROM employees e LEFT JOIN designations d ON d.id=e.designation_id
        WHERE e.active=1 ORDER BY e.employee_full_name");
    $emp = [];
    if ($emp_r) while ($r = mysqli_fetch_assoc($emp_r)) $emp[] = $r;
    echo json_encode(['success'=>true,'cc_persons'=>$cc,'sr_persons'=>$sr,'employees'=>$emp]);
    exit;
}
 
/* ══════════════════════════════════════════════════════
   AJAX — save CRN details to DB
══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_crn_details') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');

    $cheque_id       = intval($_POST['cheque_id']       ?? 0);
    $crn_no          = trim($_POST['crn_no']            ?? '');
    $return_reason   = trim($_POST['return_reason']     ?? '');
    $return_code     = trim($_POST['return_code']       ?? '');
    $cheque_status   = trim($_POST['cheque_status']     ?? '');
    $return_remark   = trim($_POST['return_remark']     ?? '');
    $collecting_bank = trim($_POST['collecting_bank']   ?? '');
    $collecting_branch=trim($_POST['collecting_branch'] ?? '');
    $date_of_return  = trim($_POST['date_of_return']    ?? '');
    $crn_file_path   = trim($_POST['crn_file_path']     ?? '');

    if (!$cheque_id) { echo json_encode(['success'=>false,'error'=>'Invalid cheque ID']); exit; }

    $cols_to_add = [
        'crn_no'           => "VARCHAR(100) DEFAULT NULL",
        'crn_return_reason'=> "VARCHAR(255) DEFAULT NULL",
        'crn_return_code'  => "VARCHAR(20)  DEFAULT NULL",
        'crn_cheque_status'=> "VARCHAR(50)  DEFAULT NULL",
        'crn_return_remark'=> "VARCHAR(255) DEFAULT NULL",
        'crn_collecting_bank'  => "VARCHAR(200) DEFAULT NULL",
        'crn_collecting_branch'=> "VARCHAR(200) DEFAULT NULL",
        'crn_date_of_return'   => "DATE         DEFAULT NULL",
        'crn_file_path'    => "TEXT          DEFAULT NULL",
        'crn_uploaded_at'  => "DATETIME     DEFAULT NULL",
        'crn_uploaded_by'  => "VARCHAR(100) DEFAULT NULL",
        'is_representable' => "TINYINT(1)   DEFAULT NULL",
    ];
    foreach ($cols_to_add as $col => $def) {
        $chk = mysqli_query($conn, "SHOW COLUMNS FROM cheques LIKE '$col'");
        if (!$chk || mysqli_num_rows($chk) === 0) {
            mysqli_query($conn, "ALTER TABLE cheques ADD COLUMN $col $def");
        }
    }

    // Ensure crn_file_path is TEXT type for JSON storage
    mysqli_query($conn, "ALTER TABLE cheques MODIFY COLUMN crn_file_path TEXT DEFAULT NULL");

    $is_rep = ($cheque_status === 'Re-presentable') ? 1 : 0;

    $crn_no_esc          = mysqli_real_escape_string($conn, $crn_no);
    $return_reason_esc   = mysqli_real_escape_string($conn, $return_reason);
    $return_code_esc     = mysqli_real_escape_string($conn, $return_code);
    $cheque_status_esc   = mysqli_real_escape_string($conn, $cheque_status);
    $return_remark_esc   = mysqli_real_escape_string($conn, $return_remark);
    $collecting_bank_esc = mysqli_real_escape_string($conn, $collecting_bank);
    $collecting_branch_esc=mysqli_real_escape_string($conn, $collecting_branch);
    $crn_file_path_esc   = mysqli_real_escape_string($conn, $crn_file_path);
    $cu                  = mysqli_real_escape_string($conn, get_current_user_label());
    $date_sql = $date_of_return ? "'".mysqli_real_escape_string($conn,$date_of_return)."'" : 'NULL';

    $upd = mysqli_query($conn, "UPDATE cheques SET
        crn_no             = '$crn_no_esc',
        crn_return_reason  = '$return_reason_esc',
        crn_return_code    = '$return_code_esc',
        crn_cheque_status  = '$cheque_status_esc',
        crn_return_remark  = '$return_remark_esc',
        crn_collecting_bank= '$collecting_bank_esc',
        crn_collecting_branch='$collecting_branch_esc',
        crn_date_of_return = $date_sql,
        crn_file_path      = '$crn_file_path_esc',
        crn_uploaded_at    = NOW(),
        crn_uploaded_by    = '$cu',
        is_representable   = $is_rep
        WHERE id = $cheque_id");

    if ($upd) {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cheque_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            old_value TEXT, new_value TEXT, note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(100) DEFAULT 'system',
            INDEX idx_cid (cheque_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id, action, new_value, note, created_by)
            VALUES ($cheque_id, 'crn_upload', '$crn_no_esc',
            'CRN uploaded: $cheque_status_esc | Code: $return_code_esc | $return_reason_esc', '$cu')");
        echo json_encode(['success' => true, 'is_representable' => $is_rep]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════
   AJAX — remove CRN details from DB
══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'remove_crn_details') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');

    $cheque_id = intval($_POST['cheque_id'] ?? 0);
    if (!$cheque_id) { echo json_encode(['success'=>false,'error'=>'Invalid cheque ID']); exit; }

    $upd = mysqli_query($conn, "UPDATE cheques SET
        crn_no                = NULL,
        crn_return_reason     = NULL,
        crn_return_code       = NULL,
        crn_cheque_status     = NULL,
        crn_return_remark     = NULL,
        crn_collecting_bank   = NULL,
        crn_collecting_branch = NULL,
        crn_date_of_return    = NULL,
        crn_file_path         = NULL,
        crn_uploaded_at       = NULL,
        crn_uploaded_by       = NULL,
        is_representable      = NULL
        WHERE id = $cheque_id");

    if ($upd) {
        $cu = mysqli_real_escape_string($conn, get_current_user_label());
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cheque_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            old_value TEXT, new_value TEXT, note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(100) DEFAULT 'system',
            INDEX idx_cid (cheque_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id, action, note, created_by)
            VALUES ($cheque_id, 'crn_removed', 'CRN details cleared by user', '$cu')");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════
   AJAX — upload CRN images (multiple)
══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_crn_images') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');

    $cheque_id = intval($_POST['cheque_id'] ?? 0);
    if (!$cheque_id) { echo json_encode(['success'=>false,'error'=>'No cheque ID']); exit; }

    $allowed_ext = ['jpg','jpeg','png','gif','bmp','webp','pdf'];
    $allowed_mime = ['image/jpeg','image/png','image/gif','image/bmp','image/webp','application/pdf'];

    $dir = 'uploads/crn_docs/';
    if (!file_exists($dir)) mkdir($dir, 0777, true);

    $saved_files = [];

    // Handle multiple file uploads
    if (!isset($_FILES['crn_files'])) {
        echo json_encode(['success'=>false,'error'=>'No files uploaded']); exit;
    }

    $file_count = is_array($_FILES['crn_files']['name']) ? count($_FILES['crn_files']['name']) : 1;

    for ($i = 0; $i < $file_count; $i++) {
        // Get file info for this index
        if (is_array($_FILES['crn_files']['name'])) {
            $name     = $_FILES['crn_files']['name'][$i];
            $tmp      = $_FILES['crn_files']['tmp_name'][$i];
            $error    = $_FILES['crn_files']['error'][$i];
            $size     = $_FILES['crn_files']['size'][$i];
        } else {
            $name     = $_FILES['crn_files']['name'];
            $tmp      = $_FILES['crn_files']['tmp_name'];
            $error    = $_FILES['crn_files']['error'];
            $size     = $_FILES['crn_files']['size'];
        }

        if ($error !== UPLOAD_ERR_OK) {
            continue; // skip failed uploads
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext)) {
            continue; // skip invalid extensions
        }

        $stored = 'crn_' . $cheque_id . '_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
        $dest   = $dir . $stored;

        if (move_uploaded_file($tmp, $dest)) {
            $saved_files[] = [
                'path' => $dest,
                'name' => $name,
                'ext'  => $ext,
                'size' => $size,
            ];
        }
    }

    if (empty($saved_files)) {
        echo json_encode(['success'=>false,'error'=>'No valid files could be saved. Allowed: JPG, PNG, GIF, WebP, PDF']);
        exit;
    }

    // Return JSON array of file paths
    $paths = array_map(function($f){ return $f['path']; }, $saved_files);

    echo json_encode([
        'success'     => true,
        'file_paths'  => $paths,
        'files'       => $saved_files,
        'file_path'   => json_encode($paths), // JSON string for DB storage
    ]);
    exit;
}

/* ══════════════════════════════════════════════════════
   AJAX — update return date (manual override, editable)
══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_return_date') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');

    $cheque_id   = intval($_POST['cheque_id']   ?? 0);
    $return_date = trim($_POST['return_date']   ?? '');

    if (!$cheque_id) { echo json_encode(['success'=>false,'error'=>'Invalid cheque ID']); exit; }

    /* Ensure column exists */
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM cheques LIKE 'return_date'");
    if (!$chk || mysqli_num_rows($chk) === 0) {
        mysqli_query($conn, "ALTER TABLE cheques ADD COLUMN return_date DATE DEFAULT NULL");
    }

    $date_sql = $return_date !== '' ? "'".mysqli_real_escape_string($conn,$return_date)."'" : 'NULL';

    $upd = mysqli_query($conn, "UPDATE cheques SET return_date = $date_sql WHERE id = $cheque_id");

    if ($upd) {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cheque_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            old_value TEXT, new_value TEXT, note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(100) DEFAULT 'system',
            INDEX idx_cid (cheque_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cu = mysqli_real_escape_string($conn, get_current_user_label());
        $rd_esc = mysqli_real_escape_string($conn, $return_date);
        mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id, action, new_value, note, created_by)
            VALUES ($cheque_id, 'return_date_updated', '$rd_esc',
            'Return date manually updated to $rd_esc', '$cu')");
        echo json_encode(['success' => true, 'return_date' => $return_date]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════
   AJAX — get returned cheque rows
══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'returned_cheque_rows') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');

    $crn_cols = [
        'crn_no'             => "VARCHAR(100) DEFAULT NULL",
        'crn_return_reason'  => "VARCHAR(255) DEFAULT NULL",
        'crn_return_code'    => "VARCHAR(20)  DEFAULT NULL",
        'crn_cheque_status'  => "VARCHAR(50)  DEFAULT NULL",
        'crn_return_remark'  => "VARCHAR(255) DEFAULT NULL",
        'crn_collecting_bank'   => "VARCHAR(200) DEFAULT NULL",
        'crn_collecting_branch' => "VARCHAR(200) DEFAULT NULL",
        'crn_date_of_return'    => "DATE         DEFAULT NULL",
        'crn_file_path'      => "TEXT          DEFAULT NULL",
        'crn_uploaded_at'    => "DATETIME     DEFAULT NULL",
        'crn_uploaded_by'    => "VARCHAR(100) DEFAULT NULL",
        'is_representable'   => "TINYINT(1)   DEFAULT NULL",
        'return_settled'     => "TINYINT(1)   DEFAULT 0",
        'settlement_date'    => "DATE         DEFAULT NULL",
        'settlement_amount'  => "DECIMAL(12,2) DEFAULT 0.00",
        'settlement_note'    => "TEXT         DEFAULT NULL",
        'return_date'        => "DATE         DEFAULT NULL",
    ];
    foreach ($crn_cols as $_col => $_def) {
        $chk = mysqli_query($conn, "SHOW COLUMNS FROM cheques LIKE '$_col'");
        if (!$chk || mysqli_num_rows($chk) === 0) {
            mysqli_query($conn, "ALTER TABLE cheques ADD COLUMN $_col $_def");
        }
    }

    $page      = max(1, intval($_GET['page']  ?? 1));
    $per_page  = max(1, intval($_GET['per']   ?? 50));
    $search    = trim($_GET['q']              ?? '');
    $f_bank    = trim($_GET['bank_code']      ?? '');
    $f_tcode   = trim($_GET['t_code']         ?? '');
    $f_from    = trim($_GET['date_from']      ?? '');
    $f_to      = trim($_GET['date_to']        ?? '');
    $f_settled = trim($_GET['settled']        ?? '');

    $where = ["(ch.status='returned' OR ch.status='bounced')"];
    if ($f_bank)    $where[] = "ch.bank_code='".mysqli_real_escape_string($conn,$f_bank)."'";
    if ($f_tcode)   $where[] = "ch.t_code LIKE '%".mysqli_real_escape_string($conn,$f_tcode)."%'";
    if ($f_from)    $where[] = "ch.cheque_date>='".mysqli_real_escape_string($conn,$f_from)."'";
    if ($f_to)      $where[] = "ch.cheque_date<='".mysqli_real_escape_string($conn,$f_to)."'";
    if ($f_settled === '1') $where[] = "COALESCE(ch.return_settled,0)=1";
    if ($f_settled === '0') $where[] = "(ch.return_settled IS NULL OR ch.return_settled=0)";

    if ($search !== '') {
        $s = '%'.mysqli_real_escape_string($conn, $search).'%';
        $where[] = "(
            ch.cheque_no       LIKE '$s'
            OR ch.t_code       LIKE '$s'
            OR ch.bank_code    LIKE '$s'
            OR ch.bank_name    LIKE '$s'
            OR ch.branch_name  LIKE '$s'
            OR COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) LIKE '$s'
            OR CAST(ch.total_amount AS CHAR) LIKE '$s'
        )";
    }

    $where_sql = implode(' AND ', $where);

    $base_sql = "
        FROM cheques ch
        INNER JOIN invoice_payments      ip  ON ip.id  = ch.invoice_payment_id
        INNER JOIN field_summary         fs  ON fs.id  = ip.field_summary_id
        LEFT  JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        LEFT  JOIN customers             c   ON c.t_code = ch.t_code
        WHERE $where_sql";

    $cnt_r = mysqli_query($conn, "SELECT COUNT(*) AS cnt $base_sql");
    $total = $cnt_r ? (int)mysqli_fetch_assoc($cnt_r)['cnt'] : 0;

    $offset   = ($page - 1) * $per_page;
    $data_sql = "
        SELECT ch.id, ch.cheque_no, ch.cheque_date, ch.total_amount,
               ch.bank_code, ch.bank_name, ch.branch_code, ch.branch_name,
               ch.status, ch.t_code, ch.cheque_mode,
               COALESCE(ch.received_date, ip.payment_date) AS received_date,
               COALESCE(ch.return_settled, 0) AS return_settled,
               COALESCE(ch.settlement_date, '') AS settlement_date,
               COALESCE(ch.settlement_amount, 0) AS settlement_amount,
               COALESCE(ch.settlement_note, '') AS settlement_note,
               COALESCE(ch.return_date, '') AS return_date,
               fs.sr_code, fs.delivery_date,
               ip.field_summary_id, ip.field_summary_detail_id,
               ip.invoice_num,
               COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) AS customer_name,
               COALESCE(fsd.adjust_net_value, 0) AS invoice_amt,
               COALESCE((SELECT SUM(p2.amount) FROM invoice_payments p2
                         WHERE p2.field_summary_detail_id=ip.field_summary_detail_id
                         AND p2.is_reversed=0), 0) AS total_paid,
               COALESCE(ch.crn_no,'')             AS crn_no,
               COALESCE(ch.crn_return_reason,'')  AS crn_return_reason,
               COALESCE(ch.crn_return_code,'')    AS crn_return_code,
               COALESCE(ch.crn_cheque_status,'')  AS crn_cheque_status,
               COALESCE(ch.crn_return_remark,'')  AS crn_return_remark,
               COALESCE(ch.crn_collecting_bank,'') AS crn_collecting_bank,
               COALESCE(ch.crn_collecting_branch,'') AS crn_collecting_branch,
               COALESCE(ch.crn_date_of_return,'') AS crn_date_of_return,
               COALESCE(ch.crn_file_path,'')      AS crn_file_path,
               COALESCE(ch.crn_uploaded_by,'')    AS crn_uploaded_by,
               COALESCE(ch.crn_uploaded_at,'')    AS crn_uploaded_at,
            COALESCE(ch.is_representable, -1)  AS is_representable,
               (SELECT cl.created_at FROM cheque_logs cl
                WHERE cl.cheque_id=ch.id AND cl.action='returned'
                ORDER BY cl.created_at DESC LIMIT 1) AS returned_date,
               COALESCE((SELECT ii.status FROM cheque_issue_items ii
                WHERE ii.cheque_id=ch.id ORDER BY ii.id DESC LIMIT 1), '') AS issue_item_status,
               (SELECT ii.id FROM cheque_issue_items ii
                WHERE ii.cheque_id=ch.id ORDER BY ii.id DESC LIMIT 1) AS issue_item_id,
               COALESCE((SELECT ci.issue_code FROM cheque_issues ci
                INNER JOIN cheque_issue_items ii2 ON ii2.issue_id=ci.id
                WHERE ii2.cheque_id=ch.id ORDER BY ii2.id DESC LIMIT 1), '') AS issue_code
        $base_sql
      ORDER BY COALESCE(ch.return_settled, 0) ASC, ch.cheque_date DESC, ch.cheque_no ASC
        LIMIT $per_page OFFSET $offset";

    $res  = mysqli_query($conn, $data_sql);
    $rows = [];
    if ($res) while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;

    $amt_r  = mysqli_query($conn, "SELECT COALESCE(SUM(ch.total_amount),0) AS tot $base_sql");
    $g_amt  = $amt_r ? (float)mysqli_fetch_assoc($amt_r)['tot'] : 0;

    $settled_r = mysqli_query($conn, "SELECT
        COALESCE(SUM(CASE WHEN COALESCE(ch.return_settled,0)=1 THEN ch.total_amount ELSE 0 END),0) AS s_amt,
        COUNT(CASE WHEN COALESCE(ch.return_settled,0)=1 THEN 1 END) AS s_cnt,
        COUNT(CASE WHEN COALESCE(ch.return_settled,0)=0 THEN 1 END) AS u_cnt,
        COALESCE(SUM(CASE WHEN COALESCE(ch.return_settled,0)=0 THEN ch.total_amount ELSE 0 END),0) AS u_amt
        $base_sql");
    $s_data = $settled_r ? mysqli_fetch_assoc($settled_r) : ['s_amt'=>0,'s_cnt'=>0,'u_cnt'=>0,'u_amt'=>0];

    echo json_encode([
        'success'      => true,
        'rows'         => $rows,
        'total'        => $total,
        'grand_total'  => $g_amt,
        'settled_amt'  => (float)$s_data['s_amt'],
        'settled_cnt'  => (int)$s_data['s_cnt'],
        'unsettled_amt'=> (float)$s_data['u_amt'],
        'unsettled_cnt'=> (int)$s_data['u_cnt'],
        'page'         => $page,
        'per_page'     => $per_page,
        'pages'        => max(1, (int)ceil($total / $per_page)),
    ]);
    exit;
}

/* ── AJAX: mark cheque as settled ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'settle_return_cheque') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');

    $cid   = intval($_POST['cheque_id']         ?? 0);
    $amt   = round(abs(floatval($_POST['settlement_amount'] ?? 0)), 2);
    $note  = mysqli_real_escape_string($conn, trim($_POST['settlement_note'] ?? ''));
    $sdate = trim($_POST['settlement_date'] ?? date('Y-m-d'));
    if (!$cid) { echo json_encode(['success'=>false,'error'=>'Invalid cheque ID']); exit; }

    $cols = mysqli_query($conn, "SHOW COLUMNS FROM cheques LIKE 'return_settled'");
    if (!$cols || mysqli_num_rows($cols) === 0) {
        mysqli_query($conn, "ALTER TABLE cheques
            ADD COLUMN return_settled    TINYINT(1)    DEFAULT 0,
            ADD COLUMN settlement_date   DATE          NULL,
            ADD COLUMN settlement_amount DECIMAL(12,2) DEFAULT 0.00,
            ADD COLUMN settlement_note   TEXT          NULL");
    }

    $sdate_esc = mysqli_real_escape_string($conn, $sdate);
    $upd = mysqli_query($conn, "UPDATE cheques SET
        return_settled=1,
        settlement_date='$sdate_esc',
        settlement_amount=$amt,
        settlement_note='$note'
        WHERE id=$cid");

    if ($upd) {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cheque_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            old_value TEXT, new_value TEXT, note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(100) DEFAULT 'system',
            INDEX idx_cid (cheque_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cu = mysqli_real_escape_string($conn, get_current_user_label());
        mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id, action, old_value, new_value, note, created_by)
            VALUES ($cid, 'return_settled', 'returned', 'settled',
            'Return settled on $sdate_esc | Amount: $amt | $note', '$cu')");
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]);
    }
    exit;
}

/* ── AJAX: get invoice detail for settlement modal ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'return_cheque_detail') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');

    $cid = intval($_GET['cheque_id'] ?? 0);
    if (!$cid) { echo json_encode(['success'=>false]); exit; }

    $r = mysqli_query($conn, "
        SELECT ch.id, ch.cheque_no, ch.total_amount, ch.t_code, ch.cheque_date,
               ch.bank_code, ch.bank_name,
               COALESCE(ch.return_settled, 0) AS return_settled,
               COALESCE(ch.settlement_amount, 0) AS settlement_amount,
               ip.field_summary_id, ip.field_summary_detail_id, ip.invoice_num,
               COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) AS customer_name,
               COALESCE(fsd.adjust_net_value, 0) AS invoice_amt,
               COALESCE(c.payment_mode,'') AS payment_mode,
               COALESCE(c.credit_limit, 0) AS credit_limit,
               COALESCE(c.credit_days, 0) AS credit_days,
               COALESCE(c.special_credit_policy_days,'') AS special_credit_policy_days,
               COALESCE(ip.payment_date, ch.received_date, NOW()) AS delivery_date,
               COALESCE((SELECT ii.status FROM cheque_issue_items ii
                WHERE ii.cheque_id=ch.id ORDER BY ii.id DESC LIMIT 1), '') AS issue_item_status,
               (SELECT ii.id FROM cheque_issue_items ii
                WHERE ii.cheque_id=ch.id ORDER BY ii.id DESC LIMIT 1) AS issue_item_id,
               COALESCE((SELECT ci.issue_code FROM cheque_issues ci
                INNER JOIN cheque_issue_items ii2 ON ii2.issue_id=ci.id
                WHERE ii2.cheque_id=ch.id ORDER BY ii2.id DESC LIMIT 1), '') AS issue_code
        FROM cheques ch
        INNER JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id
        LEFT  JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        LEFT  JOIN customers c ON c.t_code = ch.t_code
        WHERE ch.id = $cid LIMIT 1");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    if (!$row) { echo json_encode(['success'=>false,'error'=>'Not found']); exit; }

    $pr   = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS p FROM invoice_payments
                                  WHERE field_summary_detail_id=".intval($row['field_summary_detail_id'])."
                                  AND is_reversed=0");
    $paid = (float)($pr ? mysqli_fetch_assoc($pr)['p'] : 0);
    $row['total_paid'] = $paid;
    $row['balance']    = max(0, (float)$row['invoice_amt'] - $paid);

    echo json_encode(['success'=>true,'cheque'=>$row]);
    exit;
}

/* ── AJAX: list settlement payments ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'settlement_payments') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');
    $cid = intval($_GET['cheque_id'] ?? 0);
    if (!$cid) { echo json_encode(['success'=>false,'payments'=>[]]); exit; }

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_settlement_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cheque_id INT NOT NULL,
        payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
        payment_date DATE NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        reference_no VARCHAR(100) DEFAULT NULL,
        remarks TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_csp_cid (cheque_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* Ensure cheque detail columns exist */
    foreach ([
        'cheque_no'   => "VARCHAR(100) DEFAULT NULL",
        'bank_name'   => "VARCHAR(100) DEFAULT NULL",
        'bank_code'   => "VARCHAR(50)  DEFAULT NULL",
        'branch_name' => "VARCHAR(100) DEFAULT NULL",
        'cheque_date' => "DATE NULL",
    ] as $_col => $_def) {
        $chk2 = mysqli_query($conn, "SHOW COLUMNS FROM cheque_settlement_payments LIKE '$_col'");
        if (!$chk2 || mysqli_num_rows($chk2) === 0)
            mysqli_query($conn, "ALTER TABLE cheque_settlement_payments ADD COLUMN $_col $_def");
    }

    $rows = [];
    $r = mysqli_query($conn, "SELECT * FROM cheque_settlement_payments WHERE cheque_id=$cid ORDER BY created_at ASC");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    echo json_encode(['success'=>true,'payments'=>$rows,'total_settled'=>array_sum(array_column($rows,'amount'))]);
    exit;
}

/* ── AJAX: add settlement payment ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_settlement_payment') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');

    $cid        = intval($_POST['cheque_id']     ?? 0);
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

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_settlement_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cheque_id INT NOT NULL,
        payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
        payment_date DATE NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        reference_no VARCHAR(100) DEFAULT NULL,
        remarks TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_csp_cid (cheque_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* Ensure cheque detail columns exist */
    foreach ([
        'cheque_no'   => "VARCHAR(100) DEFAULT NULL",
        'bank_name'   => "VARCHAR(100) DEFAULT NULL",
        'bank_code'   => "VARCHAR(50)  DEFAULT NULL",
        'branch_name' => "VARCHAR(100) DEFAULT NULL",
        'cheque_date' => "DATE NULL",
    ] as $_col => $_def) {
        $chk2 = mysqli_query($conn, "SHOW COLUMNS FROM cheque_settlement_payments LIKE '$_col'");
        if (!$chk2 || mysqli_num_rows($chk2) === 0)
            mysqli_query($conn, "ALTER TABLE cheque_settlement_payments ADD COLUMN $_col $_def");
    }

    foreach (['settlement_date'=>'DATE NULL','settlement_amount'=>'DECIMAL(12,2) DEFAULT 0.00','settlement_note'=>'TEXT NULL','return_settled'=>'TINYINT(1) DEFAULT 0'] as $_col=>$_def) {
        $chk = mysqli_query($conn, "SHOW COLUMNS FROM cheques LIKE '$_col'");
        if (!$chk || mysqli_num_rows($chk) === 0) mysqli_query($conn, "ALTER TABLE cheques ADD COLUMN $_col $_def");
    }

    $cu = mysqli_real_escape_string($conn, get_current_user_label());

    $chq_no_sql    = $chq_no     ? "'$chq_no'"     : 'NULL';
    $bank_name_sql = $bank_name  ? "'$bank_name'"  : 'NULL';
    $bank_code_sql = $bank_code  ? "'$bank_code'"  : 'NULL';
    $branch_sql    = $branch_name? "'$branch_name'": 'NULL';
    $chq_date_sql  = $chq_date   ? "'$chq_date'"   : 'NULL';

    mysqli_query($conn, "INSERT INTO cheque_settlement_payments
        (cheque_id, payment_method, payment_date, amount, reference_no, remarks,
         cheque_no, bank_name, bank_code, branch_name, cheque_date, created_by)
        VALUES ($cid,'$method','$date',$amt,'$ref','$rem',
         $chq_no_sql,$bank_name_sql,$bank_code_sql,$branch_sql,$chq_date_sql,'$cu')");
    $new_id = mysqli_insert_id($conn);

    $tr = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS tot FROM cheque_settlement_payments WHERE cheque_id=$cid");
    $new_total = $tr ? (float)mysqli_fetch_assoc($tr)['tot'] : 0;
    $chq_r = mysqli_query($conn, "SELECT total_amount FROM cheques WHERE id=$cid LIMIT 1");
    $chq_total = $chq_r ? (float)mysqli_fetch_assoc($chq_r)['total_amount'] : 0;
    $fully = ($new_total >= $chq_total && $chq_total > 0) ? 1 : 0;
    mysqli_query($conn, "UPDATE cheques SET settlement_amount=$new_total, return_settled=$fully, settlement_date='$date' WHERE id=$cid");

    echo json_encode(['success'=>true,'payment_id'=>(int)$new_id,'new_total'=>$new_total,'fully_settled'=>$fully,'cheque_total'=>$chq_total]);
    exit;
}

/* ── AJAX: delete settlement payment ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_settlement_payment') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');
    $pid = intval($_POST['payment_id'] ?? 0);
    $cid = intval($_POST['cheque_id']  ?? 0);
    if (!$pid || !$cid) { echo json_encode(['success'=>false,'error'=>'Invalid']); exit; }
    $pr = mysqli_query($conn, "SELECT id FROM cheque_settlement_payments WHERE id=$pid AND cheque_id=$cid LIMIT 1");
    if (!$pr || mysqli_num_rows($pr) === 0) { echo json_encode(['success'=>false,'error'=>'Payment not found']); exit; }
    mysqli_query($conn, "DELETE FROM cheque_settlement_payments WHERE id=$pid AND cheque_id=$cid");
    $tr = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS tot FROM cheque_settlement_payments WHERE cheque_id=$cid");
    $new_total = $tr ? (float)mysqli_fetch_assoc($tr)['tot'] : 0;
    $chq_r = mysqli_query($conn, "SELECT total_amount FROM cheques WHERE id=$cid LIMIT 1");
    $chq_total = $chq_r ? (float)mysqli_fetch_assoc($chq_r)['total_amount'] : 0;
    $fully = ($new_total >= $chq_total && $chq_total > 0) ? 1 : 0;
    mysqli_query($conn, "UPDATE cheques SET settlement_amount=$new_total, return_settled=$fully WHERE id=$cid");
    echo json_encode(['success'=>true,'new_total'=>$new_total,'fully_settled'=>$fully,'cheque_total'=>$chq_total]);
    exit;
}



 
/* ══════════════════════════════════════════════════════
   AJAX — represent cheque (set status back to pending)
══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'represent_cheque') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');

    $cheque_id      = intval($_POST['cheque_id']      ?? 0);
    $represent_date = trim($_POST['represent_date']   ?? '');

    if (!$cheque_id)      { echo json_encode(['success'=>false,'error'=>'Invalid cheque ID']); exit; }
    if (!$represent_date) { echo json_encode(['success'=>false,'error'=>'Re-presentation date is required']); exit; }

    /* Ensure column exists */
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM cheques LIKE 'represented_date'");
    if (!$chk || mysqli_num_rows($chk) === 0) {
        mysqli_query($conn, "ALTER TABLE cheques ADD COLUMN represented_date DATE DEFAULT NULL");
    }

    $date_esc = mysqli_real_escape_string($conn, $represent_date);
    $cu       = mysqli_real_escape_string($conn, get_current_user_label());

    $upd = mysqli_query($conn, "UPDATE cheques SET
        status           = 'pending',
        represented_date = '$date_esc',
        return_settled   = 0
        WHERE id = $cheque_id AND (status='returned' OR status='bounced')");

    if ($upd && mysqli_affected_rows($conn) > 0) {
        /* log it */
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cheque_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            old_value TEXT, new_value TEXT, note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(100) DEFAULT 'system',
            INDEX idx_cid (cheque_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id, action, old_value, new_value, note, created_by)
            VALUES ($cheque_id, 'represented', 'returned', 'pending',
            'Re-presented on $date_esc', '$cu')");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn) ?: 'No rows updated']);
    }
    exit;
}
/* ══════════════════════════════════════════════════════
   NORMAL PAGE
══════════════════════════════════════════════════════ */
include 'config.php';
include 'header.php';

$bank_res  = mysqli_query($conn, "SELECT DISTINCT bank_code FROM cheques WHERE (status='returned' OR status='bounced') AND bank_code IS NOT NULL AND bank_code!='' ORDER BY bank_code");
$all_banks = [];
if ($bank_res) while ($r = mysqli_fetch_assoc($bank_res)) $all_banks[] = $r['bank_code'];

$banks_list = [];
$br = mysqli_query($conn, "SELECT id,bank_code,bank_name FROM banks WHERE active=1 ORDER BY bank_name");
if ($br) while ($b = mysqli_fetch_assoc($br)) $banks_list[] = $b;

$summary_r = mysqli_query($conn, "SELECT
    COUNT(*) AS total_cnt,
    COALESCE(SUM(total_amount),0) AS total_amt,
    COUNT(CASE WHEN COALESCE(return_settled,0)=1 THEN 1 END) AS settled_cnt,
    COALESCE(SUM(CASE WHEN COALESCE(return_settled,0)=1 THEN total_amount ELSE 0 END),0) AS settled_amt,
    COUNT(CASE WHEN COALESCE(return_settled,0)=0 THEN 1 END) AS unsettled_cnt,
    COALESCE(SUM(CASE WHEN COALESCE(return_settled,0)=0 THEN total_amount ELSE 0 END),0) AS unsettled_amt
    FROM cheques WHERE status='returned' OR status='bounced'");
if (!$summary_r) {
    mysqli_query($conn, "ALTER TABLE cheques
        ADD COLUMN IF NOT EXISTS return_settled    TINYINT(1)    DEFAULT 0,
        ADD COLUMN IF NOT EXISTS settlement_date   DATE          NULL,
        ADD COLUMN IF NOT EXISTS settlement_amount DECIMAL(12,2) DEFAULT 0.00,
        ADD COLUMN IF NOT EXISTS settlement_note   TEXT          NULL");
    $summary_r = mysqli_query($conn, "SELECT
        COUNT(*) AS total_cnt, COALESCE(SUM(total_amount),0) AS total_amt,
        0 AS settled_cnt, 0 AS settled_amt,
        COUNT(*) AS unsettled_cnt, COALESCE(SUM(total_amount),0) AS unsettled_amt
        FROM cheques WHERE status='returned' OR status='bounced'");
}
$summary = $summary_r ? mysqli_fetch_assoc($summary_r) :
    ['total_cnt'=>0,'total_amt'=>0,'settled_cnt'=>0,'settled_amt'=>0,'unsettled_cnt'=>0,'unsettled_amt'=>0];
   
    
    
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
.ffg input:focus,.ffg select:focus{outline:none;border-color:#dc2626;box-shadow:0 0 0 3px rgba(220,38,38,.1)}
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s;white-space:nowrap}
.btn-primary{background:#dc2626;color:#fff}.btn-primary:hover{background:#b91c1c}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover{background:#e8e8e8}
.btn-success{background:#16a34a;color:#fff}.btn-success:hover{background:#15803d}
.btn-sm{padding:5px 12px;font-size:11px}

/* ── Stat cards — enlarged ── */
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:12px;padding:20px 22px;box-shadow:0 1px 4px rgba(0,0,0,.05);cursor:pointer;transition:all .18s}
.stat-card:hover{border-color:#dc2626;box-shadow:0 3px 12px rgba(220,38,38,.13);transform:translateY(-1px)}
.stat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;display:flex;align-items:center;gap:5px}
.stat-value{font-size:28px;font-weight:800;line-height:1;margin:0 0 4px}
.stat-card-amt{font-size:12px;font-weight:600;color:#6b7280;margin-top:1px}
.stat-card-sub{font-size:10px;color:#d1d5db;margin-top:7px;padding-top:7px;border-top:1px solid #f3f4f6;display:flex;align-items:center;gap:4px}
.sv-red{color:#dc2626}.sv-green{color:#16a34a}.sv-amber{color:#d97706}

.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #f0f0f0;background:#fafafa;flex-wrap:wrap;gap:8px}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap}
.p-red{background:#fee2e2;color:#991b1b}
.search-bar-wrap{display:flex;align-items:center;gap:8px}
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
.data-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1400px}
.data-table thead th{padding:9px 8px;text-align:left;font-weight:700;font-size:10.5px;color:#fff;background:#7f1d1d;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);position:sticky;top:0;z-index:10}
.data-table thead th:last-child{border-right:none}
.data-table thead th.tr{text-align:right}.data-table thead th.tc{text-align:center}
.data-table tbody tr{border-bottom:1px solid #f0f2f5;transition:background .12s}
.data-table tbody tr:hover td{background:#fff5f5!important}
.data-table td{padding:7px 8px;color:#374151;vertical-align:middle;background:#fff}
.tr{text-align:right}.tc{text-align:center}
.data-table tfoot td{padding:10px 8px;font-weight:800;font-size:12px;background:#450a0a;color:#fca5a5;border-top:2px solid #7f1d1d;position:sticky;bottom:0}
.data-table tfoot td.tr{text-align:right}
.mono{font-family:'Courier New',monospace;font-weight:700;letter-spacing:.02em}
.sr-pill{background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700;white-space:nowrap}
.date-txt{font-size:11.5px;color:#374151;white-space:nowrap}
.date-txt.empty{color:#d1d5db}
.amt-cell{font-weight:700;color:#dc2626;white-space:nowrap}
.cust-sub{font-size:10px;color:#6b7280;margin-top:2px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.settled-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:700;white-space:nowrap}
.settled-badge.yes{background:#dcfce7;color:#166534;border:1px solid #86efac}
.settled-badge.no{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.settle-meta{font-size:10px;margin-top:3px;line-height:1.4}

/* Return date chip */
.return-date-chip{display:inline-flex;align-items:center;gap:4px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:600;color:#991b1b;white-space:nowrap}
.return-date-chip.empty{background:#f9fafb;border-color:#e5e7eb;color:#9ca3af}
.return-date-chip:hover{filter:brightness(0.97);box-shadow:0 0 0 2px rgba(220,38,38,.15)}

.btn-loadpay{display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;border:none;border-radius:6px;padding:5px 11px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;transition:filter .2s}
.btn-loadpay:hover{filter:brightness(1.1)}
.btn-loadpay.settled{background:linear-gradient(135deg,#6b7280,#9ca3af);cursor:default}
.btn-loadpay.settled:hover{filter:none}
/* CRN button */
.btn-crn{display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#0369a1,#0ea5e9);color:#fff;border:none;border-radius:6px;padding:5px 11px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;transition:filter .2s;margin-top:4px}
.btn-crn:hover{filter:brightness(1.1)}
.btn-crn.has-crn{background:linear-gradient(135deg,#0f766e,#14b8a6)}
.crn-badge-cell{display:flex;flex-direction:column;gap:3px;align-items:flex-start}
.crn-status-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap}
.crn-rep{background:#dcfce7;color:#166534;border:1px solid #86efac}
.crn-nonrep{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.crn-pending{background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb}
.crn-no-txt{font-family:'Courier New',monospace;font-size:10px;font-weight:700;color:#0369a1}
.tbl-loading{display:none;position:absolute;inset:0;background:rgba(255,255,255,.8);z-index:50;align-items:center;justify-content:center;flex-direction:column;gap:10px;font-size:13px;color:#dc2626;font-weight:600;border-radius:10px}
.tbl-loading.show{display:flex}
.tbl-wrap{position:relative}
.tbl-spinner{width:36px;height:36px;border:4px solid #fecaca;border-top-color:#dc2626;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.state-box{text-align:center;padding:80px 20px;color:#9ca3af}
.state-box i{font-size:48px;display:block;margin-bottom:16px;opacity:.3}
.state-box p{font-size:14px;font-weight:500}

/* ── Replacement cheque detail card in settlement payments table ── */
.rchq-card{background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:8px;padding:10px 12px;min-width:200px}
.rchq-no{font-family:'Courier New',monospace;font-weight:800;font-size:13px;color:#1e40af;letter-spacing:.04em;display:flex;align-items:center;gap:5px;margin-bottom:6px}
.rchq-grid{display:grid;grid-template-columns:1fr 1fr;gap:4px 10px}
.rchq-row{display:flex;flex-direction:column;gap:1px}
.rchq-lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#3b82f6}
.rchq-val{font-size:11px;font-weight:600;color:#1e3a5f}

/* ══ CRN MODAL ══ */
.crn-tab-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border:1.5px solid #bae6fd;border-radius:7px;background:#fff;color:#0369a1;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .15s}
.crn-tab-btn:hover{background:#e0f2fe}
.crn-tab-btn.active{background:linear-gradient(135deg,#0369a1,#0ea5e9);color:#fff;border-color:#0369a1}
.crn-view-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px}
.crn-view-lbl{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
.crn-view-val{font-size:13px;font-weight:700;color:#1e293b;font-family:'Courier New',monospace;word-break:break-word}
.crn-modal-backdrop{position:fixed!important;inset:0!important;z-index:2147483647!important;background:rgba(0,0,0,.65);display:none;align-items:center;justify-content:center;padding:16px}
.crn-modal-backdrop.open{display:flex!important}
body.crn-modal-open{overflow:hidden!important}
.crn-dialog{background:#fff;border-radius:14px;width:100%;max-width:780px;max-height:94vh;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.3);overflow:hidden}
.crn-header{display:flex;align-items:flex-start;justify-content:space-between;padding:16px 22px;border-bottom:1px solid #e0f2fe;background:linear-gradient(135deg,#f0f9ff,#e0f2fe);flex-shrink:0}
.crn-header h3{font-size:16px;font-weight:700;color:#0c4a6e;margin:0;display:flex;align-items:center;gap:8px}
.crn-header p{font-size:12px;color:#0369a1;margin:4px 0 0}
.crn-close{width:32px;height:32px;border-radius:7px;border:1px solid #bae6fd;background:#fff;color:#0369a1;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0}
.crn-close:hover{background:#e0f2fe}
.crn-body{overflow-y:auto;flex:1;padding:20px 22px}
.crn-drop-zone{border:2.5px dashed #7dd3fc;border-radius:10px;padding:32px 20px;text-align:center;cursor:pointer;background:linear-gradient(135deg,#f0f9ff,#e0f2fe);transition:all .2s;margin-bottom:18px}
.crn-drop-zone:hover,.crn-drop-zone.over{border-color:#0ea5e9;background:#bae6fd20}
.crn-drop-zone h4{font-size:14px;font-weight:700;color:#0c4a6e;margin:10px 0 4px}
.crn-drop-zone p{font-size:12px;color:#64748b;margin:0}
.crn-browse-btn{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#0369a1,#0ea5e9);color:#fff;border:none;padding:9px 22px;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;margin-top:12px;font-family:inherit;transition:opacity .2s}
.crn-browse-btn:hover{opacity:.88}
.crn-prog-box{background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:none}
.crn-prog-box.show{display:block}
.crn-prog-label{font-size:12px;font-weight:700;color:#0369a1;margin-bottom:6px;display:flex;justify-content:space-between}
.crn-prog-bg{background:#e0f2fe;border-radius:4px;height:6px;overflow:hidden}
.crn-prog-fill{height:100%;background:linear-gradient(90deg,#0369a1,#38bdf8);border-radius:4px;transition:width .3s}
.crn-prog-file{font-size:10px;color:#64748b;margin-top:5px;font-family:monospace}
.crn-result-card{background:#f8fafc;border:1.5px solid #cbd5e1;border-radius:10px;overflow:hidden;margin-bottom:18px;display:none}
.crn-result-card.show{display:block}
.crn-result-head{padding:10px 16px;background:linear-gradient(135deg,#1e3a5f,#0f172a);color:#e2e8f0;font-size:12px;font-weight:700;display:flex;align-items:center;gap:8px}
.crn-result-grid{display:grid;grid-template-columns:1fr 1fr;gap:0}
.crn-field{padding:10px 16px;border-bottom:1px solid #f1f5f9;display:flex;flex-direction:column;gap:3px}
.crn-field:nth-child(odd){border-right:1px solid #f1f5f9}
.crn-field-lbl{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em}
.crn-field-val{font-size:13px;font-weight:700;color:#1e293b;font-family:'Courier New',monospace}
.crn-field-input{border:1.5px solid #e2e8f0;border-radius:6px;padding:6px 10px;font-size:13px;font-family:'Courier New',monospace;font-weight:600;color:#1e293b;width:100%;outline:none;transition:border .2s}
.crn-field-input:focus{border-color:#0ea5e9}
.crn-status-display{padding:12px 16px;display:flex;align-items:center;gap:10px;border-top:1px solid #f1f5f9}
.crn-status-badge-big{display:inline-flex;align-items:center;gap:6px;padding:6px 16px;border-radius:20px;font-size:13px;font-weight:800}
.csb-rep{background:#dcfce7;color:#166534;border:2px solid #86efac}
.csb-nonrep{background:#fee2e2;color:#991b1b;border:2px solid #fca5a5}
.crn-existing{background:#f0fdf4;border:1.5px solid #86efac;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:none}
.crn-existing.show{display:block}
.crn-existing-title{font-size:11px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.crn-existing-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.crn-ei{display:flex;flex-direction:column;gap:2px}
.crn-ei-lbl{font-size:10px;color:#6b7280;font-weight:600}
.crn-ei-val{font-size:12px;font-weight:700;color:#166534;font-family:monospace}
.crn-footer{padding:13px 22px;border-top:1px solid #e0f2fe;background:#f0f9ff;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-shrink:0}
.btn-crn-cancel{padding:8px 16px;border-radius:6px;border:1px solid #cbd5e1;background:#fff;color:#555;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer}
.btn-crn-cancel:hover{background:#f5f5f5}
.btn-crn-save{padding:9px 22px;border-radius:6px;border:none;background:linear-gradient(135deg,#0369a1,#0ea5e9);color:#fff;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;display:flex;align-items:center;gap:6px}
.btn-crn-save:hover{filter:brightness(1.08)}
.btn-crn-save:disabled{opacity:.5;cursor:not-allowed}
.btn-crn-remove{padding:9px 18px;border-radius:6px;border:none;background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;display:flex;align-items:center;gap:6px}
.btn-crn-remove:hover{filter:brightness(1.08)}
.btn-crn-remove:disabled{opacity:.5;cursor:not-allowed}
.crn-api-banner{display:flex;align-items:center;gap:10px;padding:8px 14px;border-radius:8px;font-size:12px;font-weight:600;margin-bottom:14px}
.crn-api-ok{background:#f0fdf4;border:1px solid #86efac;color:#166534}
.crn-api-warn{background:#fffbeb;border:1px solid #fcd34d;color:#92400e}

/* ── CRN Image Gallery ── */
.crn-img-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-bottom:14px}
.crn-img-thumb{position:relative;border:2px solid #e0f2fe;border-radius:8px;overflow:hidden;cursor:pointer;transition:all .2s;background:#f8fafc;aspect-ratio:auto}
.crn-img-thumb:hover{border-color:#0ea5e9;box-shadow:0 4px 16px rgba(14,165,233,.2);transform:scale(1.02)}
.crn-img-thumb img{width:100%;height:auto;display:block;min-height:100px;object-fit:contain;background:#fff}
.crn-img-thumb .crn-img-label{position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(0,0,0,.7));color:#fff;padding:6px 10px 8px;font-size:10px;font-weight:600}
.crn-img-thumb .crn-img-remove{position:absolute;top:6px;right:6px;background:rgba(220,38,38,.9);color:#fff;border:none;width:22px;height:22px;border-radius:50%;font-size:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s}
.crn-img-thumb:hover .crn-img-remove{opacity:1}
.crn-img-preview-list{display:flex;flex-wrap:wrap;gap:6px;margin:10px 0}
.crn-img-preview-item{position:relative;width:80px;height:80px;border-radius:6px;overflow:hidden;border:2px solid #bae6fd;background:#f0f9ff}
.crn-img-preview-item img{width:100%;height:100%;object-fit:cover}
.crn-img-preview-item .crn-pip-remove{position:absolute;top:2px;right:2px;background:rgba(220,38,38,.85);color:#fff;border:none;width:18px;height:18px;border-radius:50%;font-size:9px;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1}
/* CRN Image Lightbox */
.crn-lightbox{position:fixed;inset:0;z-index:2147483647;background:rgba(0,0,0,.88);display:none;align-items:center;justify-content:center;padding:20px;cursor:zoom-out}
.crn-lightbox.open{display:flex}
.crn-lightbox img{max-width:95vw;max-height:92vh;border-radius:8px;box-shadow:0 8px 40px rgba(0,0,0,.5);object-fit:contain}
.crn-lightbox-nav{position:absolute;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.2);color:#fff;border:none;width:44px;height:44px;border-radius:50%;font-size:20px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .2s}
.crn-lightbox-nav:hover{background:rgba(255,255,255,.4)}
.crn-lightbox-prev{left:16px}
.crn-lightbox-next{right:16px}
.crn-lightbox-close{position:absolute;top:16px;right:16px;background:rgba(255,255,255,.2);color:#fff;border:none;width:36px;height:36px;border-radius:50%;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center}
.crn-lightbox-close:hover{background:rgba(255,255,255,.4)}
.crn-lightbox-counter{position:absolute;bottom:20px;left:50%;transform:translateX(-50%);color:#fff;font-size:13px;font-weight:600;background:rgba(0,0,0,.5);padding:5px 14px;border-radius:20px}

/* Settlement modal */
.modal-backdrop{position:fixed!important;inset:0!important;z-index:2147483646!important;background:rgba(0,0,0,.6);display:none;align-items:center;justify-content:center;padding:16px}
.modal-backdrop.open{display:flex!important}
body.modal-settle-open{overflow:hidden!important}
.modal-dialog{background:#fff;border-radius:14px;width:100%;max-width:1000px;max-height:94vh;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.3);overflow:hidden}
.modal-header{display:flex;align-items:flex-start;justify-content:space-between;padding:16px 22px;border-bottom:1px solid #e5e5e5;background:#fff5f5;flex-shrink:0}
.modal-header-left{display:flex;flex-direction:column;gap:4px;flex:1}
.modal-header-left h3{font-size:17px;font-weight:700;color:#991b1b;margin:0}
.inv-summary-strip{display:flex;gap:0;flex-wrap:wrap;margin-top:10px;border:1px solid #fecaca;border-radius:8px;overflow:hidden}
.inv-sum-item{flex:1;display:flex;flex-direction:column;padding:10px 16px;border-right:1px solid #fecaca;min-width:110px}
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
.pay-block-title.returned{background:#fee2e2;color:#991b1b;border-left:4px solid #dc2626}
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
.bal-summary{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;margin-top:18px}
.bal-sum-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;font-size:13px;color:#374151;border-bottom:1px solid #f0f0f0}
.bal-sum-row:last-child{border-bottom:none}
.bal-sum-row.total{font-weight:700;color:#1f2937;padding-top:8px;margin-top:4px;border-top:2px solid #e2e8f0;border-bottom:none}
.bal-sum-row.balance strong{color:#dc2626;font-size:15px}
.returned-banner{background:linear-gradient(135deg,#fee2e2,#fef2f2);border:1.5px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.rb-item{display:flex;flex-direction:column;gap:2px}
.rb-lbl{font-size:10px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.05em}
.rb-val{font-size:13px;font-weight:700;color:#7f1d1d}
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







/* ── Aging badges ── */
.aging-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;border:1px solid;}
.aging-green{background:#f0fdf4;color:#16a34a;border-color:#bbf7d0;}
.aging-yellow{background:#fefce8;color:#d97706;border-color:#fde68a;}
.aging-orange{background:#fff7ed;color:#ea580c;border-color:#fed7aa;}
.aging-red{background:#fef2f2;color:#dc2626;border-color:#fecaca;}
/* ── Collector type toggle (reused from credit_payments) ── */
.collector-type-row{display:flex;align-items:center;gap:10px;margin-bottom:14px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;}
.collector-type-label{font-size:12px;font-weight:700;color:#374151;white-space:nowrap;}
.collector-type-btns{display:flex;gap:6px;}
.ctype-btn{padding:6px 18px;border-radius:6px;border:1.5px solid #e0e0e0;background:#fff;font-size:12px;font-weight:700;font-family:inherit;cursor:pointer;color:#6b7280;transition:all .2s;}
.ctype-btn:hover{border-color:#dc2626;color:#991b1b;}
.ctype-btn.active-cc{border-color:#0ea5e9;background:#e0f2fe;color:#0369a1;}
.ctype-btn.active-sr{border-color:#7c3aed;background:#ede9fe;color:#5b21b6;}
.dp-modal-status{font-size:10px;color:#9ca3af;min-height:14px;display:block;margin-top:2px;line-height:1.3;}
.dp-modal-status.ok{color:#22c55e;font-weight:700;}
.dp-modal-status.err{color:#e53935;}
/* ── Return box inside the settle/pay modal ── */
.modal-return-box{margin-top:10px;padding:10px 14px;border-radius:9px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;}
.modal-return-box.mrb-issued{background:#fff7ed;border:1px solid #fed7aa;}
.modal-return-box.mrb-auto{background:#f0fdf4;border:1px solid #86efac;}
.modal-return-box.mrb-done{background:#dcfce7;border:1px solid #86efac;}
.modal-return-box .mrb-label{font-size:12px;font-weight:700;color:#92400e;display:flex;align-items:center;gap:6px;}
.modal-return-box.mrb-auto .mrb-label,.modal-return-box.mrb-done .mrb-label{color:#166534;}
.modal-return-box .mrb-check-label{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:#92400e;cursor:pointer;background:#fff;border:1.5px solid #fdba74;padding:6px 12px;border-radius:7px;}
.modal-return-box .mrb-check-label input{width:15px;height:15px;cursor:pointer;accent-color:#d97706;}
.modal-return-box .mrb-check-label.checked{background:#fef3c7;border-color:#d97706;}
</style>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<!-- PAGE HEADER -->
<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:12px;" class="no-print">
  <div>
    <h2 class="page-title"><i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i> Returned Cheques</h2>
    <p class="page-subtitle">All returned / bounced cheques — use <strong>Load Pay</strong> to settle or <strong>CRN</strong> to upload return notification.</p>
  </div>
  <div style="display:flex;gap:8px;" class="no-print">
    <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</button>
    <button onclick="exportExcel()" class="btn btn-success btn-sm">
      <i class="fa-solid fa-file-excel"></i> Export Excel
    </button>
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
    <div class="filter-title"><i class="fa-solid fa-sliders" style="color:#dc2626;"></i> Filters</div>
    <i class="fa-solid fa-chevron-down filter-toggle-icon" id="filterIcon"></i>
  </div>
  <div class="filter-body" id="filterBody">
    <div class="filter-row">
      <div class="ffg">
        <label><i class="fa-solid fa-building-columns"></i> Bank Code</label>
        <select id="selBank" style="width:100%;">
          <option value="">— All Banks —</option>
          <?php foreach($all_banks as $bk): ?>
          <option value="<?=htmlspecialchars($bk)?>"><?=htmlspecialchars($bk)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ffg">
        <label><i class="fa-solid fa-user-tag"></i> T-Code</label>
        <input type="text" id="fTcode" placeholder="Search T-Code...">
      </div>
      <div class="ffg">
        <label><i class="fa-solid fa-calendar-day"></i> Cheque Date From</label>
        <input type="date" id="fFrom">
      </div>
      <div class="ffg">
        <label><i class="fa-solid fa-calendar-day"></i> Cheque Date To</label>
        <input type="date" id="fTo">
      </div>
      <div class="ffg">
        <label><i class="fa-solid fa-circle-half-stroke"></i> Settlement</label>
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
    <div class="stat-label"><i class="fa-solid fa-circle-xmark" style="color:#dc2626;font-size:11px;"></i> Total Returned</div>
    <div class="stat-value sv-red">Rs.&nbsp;<?=number_format($summary['total_amt'],0)?></div>
    <div class="stat-card-amt"><?=number_format($summary['total_amt'],2)?> total value</div>
    <div class="stat-card-sub"><i class="fa-solid fa-money-check" style="font-size:10px;color:#dc2626;"></i> <?=$summary['total_cnt']?> cheques &nbsp;·&nbsp; Click to reset filters</div>
  </div>
  <div class="stat-card" onclick="filterBySettled('0')">
    <div class="stat-label"><i class="fa-solid fa-hourglass-half" style="color:#d97706;font-size:11px;"></i> Unsettled</div>
    <div class="stat-value sv-amber">Rs.&nbsp;<?=number_format($summary['unsettled_amt'],0)?></div>
    <div class="stat-card-amt"><?=number_format($summary['unsettled_amt'],2)?> outstanding</div>
    <div class="stat-card-sub"><i class="fa-solid fa-money-check" style="font-size:10px;color:#d97706;"></i> <?=$summary['unsettled_cnt']?> cheques pending &nbsp;·&nbsp; Click to filter</div>
  </div>
  <div class="stat-card" onclick="filterBySettled('1')">
    <div class="stat-label"><i class="fa-solid fa-circle-check" style="color:#16a34a;font-size:11px;"></i> Settled</div>
    <div class="stat-value sv-green">Rs.&nbsp;<?=number_format($summary['settled_amt'],0)?></div>
    <div class="stat-card-amt"><?=number_format($summary['settled_amt'],2)?> recovered</div>
    <div class="stat-card-sub"><i class="fa-solid fa-money-check" style="font-size:10px;color:#16a34a;"></i> <?=$summary['settled_cnt']?> cheques settled &nbsp;·&nbsp; Click to filter</div>
  </div>
  <div class="stat-card" style="border-color:#dc2626;background:#fff5f5;cursor:default;">
    <div class="stat-label" style="color:#991b1b;"><i class="fa-solid fa-filter" style="font-size:11px;"></i> Filtered Results</div>
    <div class="stat-value sv-red" id="filteredCount">0</div>
    <div class="stat-card-amt" id="filteredAmt">Rs. 0.00</div>
    <div class="stat-card-sub" id="filteredSub"><i class="fa-solid fa-table-list" style="font-size:10px;color:#dc2626;"></i> matching records</div>
  </div>
</div>

<!-- TABLE CARD -->
<div class="table-card tbl-wrap">
  <div class="tbl-loading" id="tblLoading">
    <div class="tbl-spinner"></div>
    <span>Loading returned cheques…</span>
  </div>
  <div class="table-toolbar no-print" style="gap:10px;">
    <div class="tbl-title">
      <i class="fa-solid fa-table-list"></i> Returned Cheques
      <span class="pill p-red" id="visCount">0 records</span>
    </div>
    <div class="search-bar-wrap">
      <div class="chq-search-box">
        <i class="fa-solid fa-magnifying-glass si"></i>
        <input type="text" id="chqSearch" placeholder="Search cheque no, customer, bank…" autocomplete="off" spellcheck="false">
        <button class="clr-btn" id="chqClr" onclick="clearSearch()" title="Clear"><i class="fa-solid fa-xmark"></i></button>
      </div>
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
   
        <th class="tc">SR Code</th>
        <th class="tc">Cheque Date</th>
        <th class="tc">Received Date</th>
       <th class="tc">Return Date</th>
<th class="tc">Aging (Return)</th>
<th class="tc">Aging (Received)</th>
        <th>Cheque No.</th>
        <th>Bank / Branch</th>
        <th class="tc">Bank Code</th>
       <th class="tr">Amount</th>
        <th class="tr">Paid</th>
        <th class="tr">Balance</th>
        <th>T-Code / Customer</th>
        <th>Invoice</th>
        <th class="tc">Settlement</th>
       <th class="tc">CRN Status</th>
        <th class="tc">Issue Status</th>
        <th class="tc no-print">Actions</th>
      </tr>
    </thead>
    <tbody id="mainTbody">
        
      <tr><td colspan="14" style="text-align:center;padding:60px 20px;color:#9ca3af;">
        <i class="fa-solid fa-spinner fa-spin" style="font-size:32px;display:block;margin-bottom:12px;opacity:.5;"></i>
        <p>Loading returned cheques…</p>
      </td></tr>
    </tbody>
    <tfoot>
      <tr>
   <td colspan="8" class="no-print"></td>
        <td class="tr">Rs.&nbsp;<span id="footerTotal">0.00</span></td>
        <td class="tr" colspan="2"></td>
<td colspan="6" style="font-size:11px;opacity:.65;">TOTAL — <span id="footerCount">0</span> CHEQUES</td>      </tr>
    </tfoot>
  </table>
  </div>
</div>

<!-- CRN MODAL (Updated for multi-image upload) -->
<div class="crn-modal-backdrop" id="crnModal">
<div class="crn-dialog" style="max-width:900px;">
  <div class="crn-header">
    <div style="flex:1;min-width:0;">
      <h3><i class="fa-solid fa-file-invoice" style="color:#0ea5e9;"></i> Cheque Return Notification (CRN)</h3>
      <p id="crnModalSubtitle" style="font-size:12px;color:#0369a1;margin:4px 0 0;">—</p>
    </div>
    <div style="display:flex;gap:4px;align-items:center;margin:0 14px;">
      <button class="crn-tab-btn active" id="tabBtnView"    onclick="switchCrnTab('view')"><i class="fa-solid fa-eye"></i> View</button>
      <button class="crn-tab-btn"        id="tabBtnReplace" onclick="switchCrnTab('replace')"><i class="fa-solid fa-arrow-up-from-bracket"></i> Upload</button>
    </div>
    <button class="crn-close" onclick="closeCrnModal()"><i class="fa-solid fa-xmark"></i></button>
  </div>

  <!-- TAB: VIEW -->
  <div class="crn-body" id="crnTabView">
    <div id="crnNoDocState" style="text-align:center;padding:50px 20px;color:#64748b;display:none;">
      <i class="fa-solid fa-file-circle-question" style="font-size:48px;display:block;margin-bottom:14px;opacity:.3;"></i>
      <p style="font-size:14px;font-weight:600;margin:0 0 12px;">No CRN images uploaded yet.</p>
      <button class="crn-browse-btn" onclick="switchCrnTab('replace')" style="font-size:12px;padding:8px 18px;">
        <i class="fa-solid fa-arrow-up-from-bracket"></i> Upload CRN Images
      </button>
    </div>
    <div id="crnDocView" style="display:none;">
      <div id="crnViewBanner" style="display:flex;align-items:center;gap:14px;padding:12px 16px;border-radius:10px;margin-bottom:14px;border:1.5px solid;">
        <div id="crnViewStatusBadge" class="crn-status-badge-big" style="flex-shrink:0;font-size:14px;padding:8px 18px;">—</div>
        <div style="flex:1;min-width:0;">
          <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px;">Return Reason / Code</div>
          <div id="crnViewReason" style="font-size:14px;font-weight:700;color:#1e293b;">—</div>
        </div>
        <div style="text-align:right;flex-shrink:0;">
          <div style="font-size:10px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px;">Date of Return</div>
          <div id="crnViewDate" style="font-size:13px;font-weight:700;color:#1e293b;">—</div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px;">
        <div class="crn-view-item"><div class="crn-view-lbl"><i class="fa-solid fa-hashtag"></i> CRN Reference No.</div><div class="crn-view-val" id="crnViewNo">—</div></div>
        <div class="crn-view-item"><div class="crn-view-lbl"><i class="fa-solid fa-building-columns"></i> Collecting Bank</div><div class="crn-view-val" id="crnViewBank">—</div></div>
        <div class="crn-view-item"><div class="crn-view-lbl"><i class="fa-solid fa-code-branch"></i> Collecting Branch</div><div class="crn-view-val" id="crnViewBranch">—</div></div>
        <div class="crn-view-item"><div class="crn-view-lbl"><i class="fa-solid fa-comment-dots"></i> Return Remark</div><div class="crn-view-val" id="crnViewRemark">—</div></div>
        <div class="crn-view-item"><div class="crn-view-lbl"><i class="fa-solid fa-user-clock"></i> Uploaded By</div><div class="crn-view-val" id="crnViewUpBy">—</div></div>
        <div class="crn-view-item"><div class="crn-view-lbl"><i class="fa-solid fa-clock"></i> Uploaded At</div><div class="crn-view-val" id="crnViewUpAt">—</div></div>
      </div>
      <!-- Image Gallery for View -->
      <div style="border:1.5px solid #e0f2fe;border-radius:10px;overflow:hidden;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 14px;background:linear-gradient(135deg,#1e3a5f,#0f172a);">
          <span style="color:#e2e8f0;font-size:12px;font-weight:700;display:flex;align-items:center;gap:7px;">
            <i class="fa-solid fa-images" style="color:#7dd3fc;"></i>
            <span id="crnViewFileCount">CRN Images</span>
          </span>
        </div>
        <div id="crnViewGallery" class="crn-img-gallery" style="padding:12px;"></div>
      </div>
    </div>
  </div>

  <!-- TAB: UPLOAD/REPLACE -->
  <div class="crn-body" id="crnTabReplace" style="display:none;">
    <div class="crn-api-banner crn-api-warn" id="crnApiBanner"><i class="fa-solid fa-key"></i> Checking Gemini API key…</div>
    <div class="crn-drop-zone" id="crnDropZone"
         ondragover="event.preventDefault();this.classList.add('over');"
         ondragleave="this.classList.remove('over');"
         ondrop="crnHandleDrop(event)">
      <i class="fa-solid fa-images" style="font-size:40px;color:#0ea5e9;display:block;margin-bottom:8px;"></i>
      <h4 id="crnDropTitle">Drop CRN Images here</h4>
      <p id="crnDropSub">Upload multiple images (JPG, PNG) — AI will scan all images to extract CRN details</p>
      <button class="crn-browse-btn" type="button" onclick="document.getElementById('crnFileInput').click()">
        <i class="fa-solid fa-folder-open"></i> Select CRN Images
      </button>
      <input type="file" id="crnFileInput" accept="image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp" multiple style="display:none;" onchange="crnHandleFiles(this.files)">
    </div>
    <!-- Preview thumbnails of selected files -->
    <div id="crnImgPreviewArea" class="crn-img-preview-list" style="display:none;"></div>
    <div id="crnFileName" style="font-size:11px;color:#64748b;margin-bottom:10px;text-align:center;display:none;"></div>
    <div class="crn-prog-box" id="crnProgBox">
      <div class="crn-prog-label"><span id="crnProgLabel">Uploading images…</span><span id="crnProgPct">0%</span></div>
      <div class="crn-prog-bg"><div class="crn-prog-fill" id="crnProgFill" style="width:0%"></div></div>
      <div class="crn-prog-file" id="crnProgFile"></div>
    </div>
    <div class="crn-result-card" id="crnResultCard">
      <div class="crn-result-head"><i class="fa-solid fa-robot"></i> AI Scan Results — Review &amp; Edit Before Saving</div>
      <div class="crn-result-grid">
        <div class="crn-field"><div class="crn-field-lbl">CRN Number</div><input class="crn-field-input" id="crnResNo" placeholder="e.g. 7214 00000079"></div>
        <div class="crn-field"><div class="crn-field-lbl">Date of Return</div><input class="crn-field-input" id="crnResDate" type="date"></div>
        <div class="crn-field"><div class="crn-field-lbl">Return Reason</div><input class="crn-field-input" id="crnResReason" placeholder="e.g. Post-dated cheque"></div>
        <div class="crn-field"><div class="crn-field-lbl">Return Code</div><input class="crn-field-input" id="crnResCode" placeholder="e.g. 13"></div>
        <div class="crn-field"><div class="crn-field-lbl">Collecting Bank</div><input class="crn-field-input" id="crnResBank" placeholder="e.g. National Development Bank PLC"></div>
        <div class="crn-field"><div class="crn-field-lbl">Collecting Branch</div><input class="crn-field-input" id="crnResBranch" placeholder="e.g. Head Office (Co-op) 900"></div>
      </div>
      <div class="crn-status-display">
        <div style="flex:1;">
          <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;">Cheque Status &amp; Return Remark</div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <div id="crnStatusBigBadge" class="crn-status-badge-big csb-nonrep"><i class="fa-solid fa-question"></i> —</div>
            <select id="crnStatusSelect" style="border:1.5px solid #e2e8f0;border-radius:7px;padding:7px 12px;font-size:13px;font-family:inherit;color:#1e293b;outline:none;cursor:pointer;">
              <option value="">— Select Status —</option>
              <option value="Re-presentable">Re-presentable</option>
              <option value="Non-representable">Non-representable</option>
            </select>
            <input class="crn-field-input" id="crnResRemark" placeholder="Return Remark (e.g. Post-dated 1)" style="flex:1;min-width:160px;">
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- FOOTER: View -->
  <div class="crn-footer" id="crnFooterView">
    <div style="font-size:11px;color:#64748b;"><i class="fa-solid fa-info-circle"></i> Images stored in uploads/crn_docs/ on the server.</div>
    <div style="display:flex;gap:8px;">
      <button class="btn-crn-cancel" onclick="closeCrnModal()"><i class="fa-solid fa-xmark"></i> Close</button>
      <button class="btn-crn-remove" id="crnRemoveBtn" style="display:none;" onclick="removeCrnDetails()"><i class="fa-solid fa-trash"></i> Remove CRN</button>
      <button class="btn-crn-save" style="background:linear-gradient(135deg,#0369a1,#0ea5e9);" onclick="switchCrnTab('replace')"><i class="fa-solid fa-arrow-up-from-bracket"></i> Upload New Images</button>
    </div>
  </div>
  <!-- FOOTER: Replace -->
  <div class="crn-footer" id="crnFooterReplace" style="display:none;">
    <div style="font-size:11px;color:#64748b;"><i class="fa-solid fa-info-circle"></i> Uploading will overwrite the existing CRN images.</div>
    <div style="display:flex;gap:8px;">
      <button class="btn-crn-cancel" onclick="closeCrnModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="btn-crn-save" id="crnSaveBtn" onclick="saveCrnDetails()" disabled><i class="fa-solid fa-cloud-arrow-up"></i> Update &amp; Save CRN</button>
    </div>
  </div>
</div>
</div>

<!-- CRN Image Lightbox -->
<div class="crn-lightbox" id="crnLightbox" onclick="if(event.target===this)closeLightbox()">
  <button class="crn-lightbox-close" onclick="closeLightbox()"><i class="fa-solid fa-xmark"></i></button>
  <button class="crn-lightbox-nav crn-lightbox-prev" onclick="event.stopPropagation();lightboxNav(-1)"><i class="fa-solid fa-chevron-left"></i></button>
  <img id="crnLightboxImg" src="" alt="CRN Image">
  <button class="crn-lightbox-nav crn-lightbox-next" onclick="event.stopPropagation();lightboxNav(1)"><i class="fa-solid fa-chevron-right"></i></button>
  <div class="crn-lightbox-counter" id="crnLightboxCounter"></div>
</div>

<!-- SETTLEMENT MODAL -->
<div class="modal-backdrop" id="settleModal">
<div class="modal-dialog">
  <div class="modal-header">
    <div class="modal-header-left">
      <h3><i class="fa-solid fa-circle-xmark" style="color:#dc2626;margin-right:4px;"></i> Settle Returned Cheque</h3>
      <p id="modalSubtitle" style="font-size:12px;color:#991b1b;margin:0;">—</p>
      <div class="inv-summary-strip">
        <div class="inv-sum-item"><span class="inv-sum-label"><i class="fa-solid fa-file-invoice"></i> Invoice Amt</span><span class="inv-sum-value" id="hdrInv">—</span></div>
        <div class="inv-sum-item"><span class="inv-sum-label"><i class="fa-solid fa-circle-check"></i> Total Paid</span><span class="inv-sum-value green" id="hdrPaid">—</span></div>
        <div class="inv-sum-item"><span class="inv-sum-label"><i class="fa-solid fa-hourglass-half"></i> Balance</span><span class="inv-sum-value red" id="hdrBal">—</span></div>
        <div class="inv-sum-item"><span class="inv-sum-label"><i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i> Returned Amt</span><span class="inv-sum-value red" id="hdrReturned">—</span></div>
      </div>
    </div>
    <button class="modal-close" onclick="closeSettleModal()"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="modal-body">
    <div class="pay-section-wrap">
      <div class="returned-banner" id="returnedBanner">
        <div class="rb-item"><span class="rb-lbl">Cheque No.</span><span class="rb-val" id="bnrChequeNo">—</span></div>
        <div class="rb-item"><span class="rb-lbl">Cheque Date</span><span class="rb-val" id="bnrChequeDate">—</span></div>
        <div class="rb-item"><span class="rb-lbl">Bank</span><span class="rb-val" id="bnrBank">—</span></div>
        <div class="rb-item"><span class="rb-lbl">Returned Amount</span><span class="rb-val" id="bnrAmt">—</span></div>
      </div>
      <div class="modal-return-box no-print" id="modalReturnBox" style="display:none;"></div>
      <div class="pay-block">
        <div class="pay-block-title cash"><i class="fa-solid fa-coins"></i> Cash Settlement</div>
        
        
        
        
        <!-- ── COLLECTOR DETAILS ── -->
<div class="pay-block">
  <div class="pay-block-title cash" style="background:#f0f9ff;color:#0369a1;border-left-color:#0ea5e9;">
    <i class="fa-solid fa-person-biking"></i> Collector Details
  </div>
  <div class="collector-type-row">
    <span class="collector-type-label"><i class="fa-solid fa-user-tag"></i> Collected By:</span>
    <div class="collector-type-btns">
      <button type="button" class="ctype-btn active-cc" id="sCtypeCC" onclick="sSetCollectorType('cc')">
        <i class="fa-solid fa-person-biking"></i> CC — Cash Collector
      </button>
      <button type="button" class="ctype-btn" id="sCtypeSR" onclick="sSetCollectorType('sr')">
        <i class="fa-solid fa-id-badge"></i> SR — Sales Rep
      </button>
    </div>
  </div>
  <!-- CC mode -->
  <div id="sDpWrap" class="gr gr2" style="margin-bottom:0;">
    <div class="fg">
      <label>Delivery Person <span class="req">*</span></label>
      <select class="fctrl" id="sDpSelect" style="width:100%;">
        <option value="">-- Select Delivery Person --</option>
      </select>
      <span class="dp-modal-status" id="sDpStatus"></span>
    </div>
    <div class="fg">
      <label>Employee <span style="font-size:10px;font-weight:400;color:#9ca3af;">(optional)</span></label>
      <select id="sEmpSelect" style="width:100%;">
        <option value="">-- Select Employee --</option>
      </select>
    </div>
  </div>
  <!-- SR mode -->
  <div id="sSrWrap" style="display:none;" class="gr gr2" style="margin-bottom:0;">
    <div class="fg">
      <label>SR Code <span class="req">*</span></label>
      <select class="fctrl" id="sSrSelect" style="width:100%;">
        <option value="">-- Select SR Code --</option>
      </select>
    </div>
    <div class="fg">
      <label>Employee <span style="font-size:10px;font-weight:400;color:#9ca3af;">(optional)</span></label>
      <select id="sSrEmpSelect" style="width:100%;">
        <option value="">-- Select Employee --</option>
      </select>
    </div>
  </div>
</div>
        
        
        
        
        
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
      <div class="pay-block">
        <div class="pay-block-title cheque"><i class="fa-solid fa-money-check"></i> New Cheque Payment</div>
        <div class="cust-info-strip">
          <div class="ci-item"><div class="ci-label">Credit Limit</div><div class="ci-value" id="chqLimit">—</div></div>
          <div class="ci-item"><div class="ci-label">Policy Days</div><div class="ci-value" id="chqDays">—</div></div>
          <div class="ci-item"><div class="ci-label">Special Days</div><div class="ci-value" id="chqSpecial">—</div></div>
        </div>
        <div class="gr gr3">
          <div class="fg"><label>Cheque Received Date</label><input type="date" class="fctrl" id="chqPayDate"></div>
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
      <div class="pay-block" style="margin-bottom:10px;">
        <div class="pay-block-title returned"><i class="fa-solid fa-circle-xmark"></i> Settlement Note</div>
        <div class="fg">
          <label>Settlement Note (optional)</label>
          <input type="text" class="fctrl" id="settlementNote" placeholder="e.g. Replaced with cash, customer agreed...">
        </div>
      </div>
      <div class="spm-block" id="spmBlock">
        <div class="spm-header">
          <span class="spm-title"><i class="fa-solid fa-list-check"></i> Settlement Payments</span>
          <span id="spmTotalBadge" style="font-size:12px;color:#166534;font-weight:700;"></span>
        </div>
        <div id="spmTableWrap">
          <div class="spm-empty"><i class="fa-solid fa-circle-info"></i> No payments recorded yet.</div>
        </div>
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
    <div style="font-size:11px;color:#9ca3af;"><i class="fa-solid fa-info-circle"></i> Payment will be recorded against the original invoice.</div>
    <div class="modal-footer-right">
      <button class="btn-modal-cancel" onclick="closeSettleModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="btn-modal-settle" id="submitSettleBtn" onclick="submitSettlement()"><i class="fa-solid fa-circle-check"></i> Save Payment</button>
    </div>
  </div>
</div>
</div>



<!-- RE-PRESENT MODAL -->
<div id="representModal" style="display:none;position:fixed;inset:0;z-index:2147483647;background:rgba(0,0,0,.6);align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:420px;box-shadow:0 24px 80px rgba(0,0,0,.3);overflow:hidden;">
    <div style="padding:16px 22px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-bottom:1px solid #86efac;display:flex;align-items:center;justify-content:space-between;">
      <div>
        <div style="font-size:15px;font-weight:800;color:#166534;display:flex;align-items:center;gap:8px;">
          <i class="fa-solid fa-rotate-right" style="color:#16a34a;"></i> Re-present Cheque
        </div>
        <div id="representModalSub" style="font-size:11px;color:#15803d;margin-top:3px;">—</div>
      </div>
      <button onclick="closeRepresentModal()" style="background:none;border:1px solid #86efac;border-radius:7px;width:30px;height:30px;color:#16a34a;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
    <div style="padding:22px;">
      <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 14px;margin-bottom:18px;font-size:12px;color:#166534;display:flex;align-items:flex-start;gap:8px;">
        <i class="fa-solid fa-circle-info" style="margin-top:1px;flex-shrink:0;"></i>
        <span>This will change the cheque status back to <strong>Pending</strong> and record the re-presentation date. The cheque will be removed from the Returned list.</span>
      </div>
      <div style="display:flex;flex-direction:column;gap:5px;">
        <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">
          <i class="fa-solid fa-calendar-day"></i> Re-presentation Date <span style="color:#ef4444;">*</span>
        </label>
        <input type="date" id="representDate"
          style="border:1.5px solid #e5e5e5;border-radius:8px;padding:10px 12px;font-size:14px;font-family:inherit;color:#1f2937;width:100%;outline:none;transition:border .2s;"
          onfocus="this.style.borderColor='#16a34a'" onblur="this.style.borderColor='#e5e5e5'">
      </div>
    </div>
    <div style="padding:14px 22px;border-top:1px solid #dcfce7;background:#f0fdf4;display:flex;justify-content:flex-end;gap:8px;">
      <button onclick="closeRepresentModal()"
        style="padding:8px 16px;border-radius:7px;border:1px solid #e5e5e5;background:#fff;color:#555;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">
        Cancel
      </button>
      <button id="representSaveBtn" onclick="submitRepresent()"
        style="padding:9px 20px;border-radius:7px;border:none;background:linear-gradient(135deg,#16a34a,#22c55e);color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px;">
        <i class="fa-solid fa-rotate-right"></i> Confirm Re-present
      </button>
    </div>
  </div>
</div>



<div id="rcToast"></div>

<div id="issueDrawerBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:10010;" onclick="closeIssueDrawer()"></div>
<div id="issueDrawer" style="position:fixed;right:0;top:0;bottom:0;width:480px;max-width:96vw;background:#fff;z-index:10011;display:flex;flex-direction:column;box-shadow:-6px 0 40px rgba(0,0,0,.2);transform:translateX(110%);transition:transform .32s cubic-bezier(.4,0,.2,1);">
    <div style="padding:16px 20px;background:#1e1b4b;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <div style="font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-paper-plane" style="color:#a5b4fc;"></i>
            Issue Returned Cheques
            <span id="issDrawerCount" style="background:rgba(255,255,255,.15);padding:1px 10px;border-radius:10px;font-size:12px;">0 selected</span>
        </div>
        <button onclick="closeIssueDrawer()" style="background:none;border:none;color:#a5b4fc;font-size:20px;cursor:pointer;padding:2px;">&#x2715;</button>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #f0f0f0;flex-shrink:0;">
        <div style="padding:12px 16px;text-align:center;border-right:1px solid #f0f0f0;">
            <div id="issDrawerStatCount" style="font-size:20px;font-weight:800;color:#6366f1;">0</div>
            <div style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-top:2px;">Cheques</div>
        </div>
        <div style="padding:12px 16px;text-align:center;">
            <div id="issDrawerStatAmt" style="font-size:20px;font-weight:800;color:#dc2626;">Rs. 0.00</div>
            <div style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-top:2px;">Total Amount</div>
        </div>
    </div>
    <div id="issDrawerList" style="flex:1;overflow-y:auto;padding:8px 0;">
        <div id="issDrawerEmpty" style="text-align:center;padding:50px 20px;color:#9ca3af;">
            <i class="fa-solid fa-inbox" style="font-size:36px;display:block;margin-bottom:12px;opacity:.3;"></i>
            <p style="font-size:13px;color:#6b7280;margin:0 0 6px;">No cheques selected</p>
            <small>Check rows in the table then click "Issue Cheques"</small>
        </div>
    </div>
    <div style="padding:14px 16px;border-top:2px solid #f0f0f0;display:flex;gap:8px;background:#fafafa;flex-shrink:0;">
        <button onclick="clearIssueSelection()" class="btn btn-secondary btn-sm" style="flex:1;justify-content:center;">
            <i class="fa-solid fa-trash"></i> Clear
        </button>
        <button id="issDrawerProceedBtn" onclick="openIssueConfirmModal()" disabled
                style="flex:2;background:#6366f1;color:#fff;border:none;border-radius:7px;padding:9px 16px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;opacity:.5;">
            <i class="fa-solid fa-paper-plane"></i> Process &amp; Issue (<span id="issDrawerProceedCount">0</span>)
        </button>
    </div>
</div>
 
<!-- ══════════════════════════════════════════════════════════════
     ISSUE CONFIRM MODAL
══════════════════════════════════════════════════════════════════ -->
<div id="issueConfirmBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:10020;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:14px;width:680px;max-width:96vw;max-height:92vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.35);">
    <div style="padding:16px 22px;border-bottom:1px solid #e5e5e5;display:flex;align-items:center;justify-content:space-between;background:#f5f3ff;">
        <div style="font-size:16px;font-weight:800;color:#3730a3;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-paper-plane" style="color:#6366f1;"></i> Confirm &amp; Issue Cheques
        </div>
        <button onclick="closeIssueConfirmModal()" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:22px;line-height:1;">&#x2715;</button>
    </div>
    <div style="padding:22px;">
        <!-- Summary strip -->
        <div style="background:#f8fafc;border:1px solid #e5e5e5;border-radius:9px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
            <div style="display:flex;flex-direction:column;gap:2px;">
                <div style="font-size:16px;font-weight:800;color:#6366f1;" id="icmCount">0</div>
                <div style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Cheques</div>
            </div>
            <div style="display:flex;flex-direction:column;gap:2px;">
                <div style="font-size:16px;font-weight:800;color:#dc2626;" id="icmTotal">Rs. 0.00</div>
                <div style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Total Amount</div>
            </div>
        </div>
        <!-- Row 1: date + type toggle -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
            <div style="display:flex;flex-direction:column;gap:5px;">
                <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;"><i class="fa-solid fa-calendar-day"></i> Issue Date *</label>
                <input type="date" id="icmDate" style="border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;outline:none;" required>
            </div>
            <div style="display:flex;flex-direction:column;gap:5px;">
                <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;"><i class="fa-solid fa-user-tie"></i> Issue To Type *</label>
                <div style="display:flex;border:1px solid #e5e5e5;border-radius:7px;overflow:hidden;">
                    <button type="button" class="icm-type-btn sr" data-type="SR" onclick="icmSetType('SR')"
                        style="flex:1;padding:9px 12px;font-size:13px;font-weight:700;border:none;cursor:pointer;background:#6366f1;color:#fff;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .2s;">
                        <i class="fa-solid fa-id-badge"></i> SR
                    </button>
                    <button type="button" class="icm-type-btn cc" data-type="CC" onclick="icmSetType('CC')"
                        style="flex:1;padding:9px 12px;font-size:13px;font-weight:700;border:none;cursor:pointer;background:#f9fafb;color:#6b7280;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .2s;">
                        <i class="fa-solid fa-wallet"></i> CC
                    </button>
                </div>
            </div>
        </div>
        <!-- SR section -->
        <div id="icmSrSection" style="border:1px solid #6366f1;border-radius:9px;padding:16px;margin-bottom:14px;background:#faf5ff;">
            <div style="font-size:12px;font-weight:800;color:#374151;text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                <span style="background:#6366f1;color:#fff;padding:2px 8px;border-radius:6px;font-size:10px;">SR</span>
                Select Sales Representative
            </div>
            <select id="icmSrSelect" style="width:100%;border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;"></select>
            <div id="icmSrCard" style="display:none;margin-top:10px;padding:10px 14px;border-radius:8px;background:#fff;border:1px solid #e5e5e5;font-size:12px;color:#374151;"></div>
            <div style="margin-top:12px;padding-top:12px;border-top:1px dashed #e5e5e5;">
                <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:5px;"><i class="fa-solid fa-user-check"></i> Link Employee (optional)</label>
                <select id="icmSrEmpSelect" style="width:100%;border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;"></select>
            </div>
        </div>
        <!-- CC section -->
        <div id="icmCcSection" style="display:none;border:1px solid #d97706;border-radius:9px;padding:16px;margin-bottom:14px;background:#fffbeb;">
            <div style="font-size:12px;font-weight:800;color:#374151;text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                <span style="background:#d97706;color:#fff;padding:2px 8px;border-radius:6px;font-size:10px;">CC</span>
                Select Delivery Person
            </div>
            <select id="icmCcSelect" style="width:100%;border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;"></select>
            <div id="icmCcCard" style="display:none;margin-top:10px;padding:10px 14px;border-radius:8px;background:#fff;border:1px solid #e5e5e5;font-size:12px;color:#374151;"></div>
            <div style="margin-top:12px;padding-top:12px;border-top:1px dashed #e5e5e5;">
                <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:5px;"><i class="fa-solid fa-user-check"></i> Link Employee (optional)</label>
                <select id="icmEmpSelect" style="width:100%;border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;"></select>
            </div>
        </div>
        <!-- Notes -->
        <div style="display:flex;flex-direction:column;gap:5px;">
            <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;"><i class="fa-solid fa-note-sticky"></i> Notes (optional)</label>
            <textarea id="icmNotes" rows="2" style="border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;width:100%;resize:vertical;" placeholder="Any notes…"></textarea>
        </div>
    </div>
    <div style="padding:14px 22px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:8px;background:#fafafa;">
        <button onclick="closeIssueConfirmModal()" style="padding:8px 16px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#555;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">Cancel</button>
        <button id="icmSaveBtn" onclick="saveChequelssue()" style="padding:9px 22px;border-radius:6px;border:none;background:#6366f1;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px;">
            <i class="fa-solid fa-floppy-disk"></i> Save &amp; Issue
        </button>
    </div>
</div>
</div>
 
<!-- ══════════════════════════════════════════════════════════════
     ISSUE HISTORY DRAWER
══════════════════════════════════════════════════════════════════ -->
<div id="histDrawerBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:10010;" onclick="closeHistoryDrawer()"></div>
<div id="histDrawer" style="position:fixed;right:0;top:0;bottom:0;width:780px;max-width:98vw;background:#fff;z-index:10011;display:flex;flex-direction:column;box-shadow:-6px 0 40px rgba(0,0,0,.2);transform:translateX(110%);transition:transform .32s cubic-bezier(.4,0,.2,1);">
    <div style="padding:14px 20px;background:#1e1b4b;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <div style="font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-clock-rotate-left" style="color:#a5b4fc;"></i>
            Cheque Issue History
        </div>
        <button onclick="closeHistoryDrawer()" style="background:none;border:none;color:#a5b4fc;font-size:20px;cursor:pointer;padding:2px;">&#x2715;</button>
    </div>
    <!-- Filters -->
    <div style="padding:10px 16px;border-bottom:1px solid #f0f0f0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;flex-shrink:0;background:#fafafa;">
        <input type="text" id="histSearch" placeholder="Search code, person…"
            style="border:1px solid #e5e5e5;border-radius:7px;padding:6px 10px;font-size:12px;font-family:inherit;color:#1f2937;width:180px;"
            oninput="histFetchDebounced()">
        <select id="histTypeFilter"
            style="border:1px solid #e5e5e5;border-radius:7px;padding:6px 10px;font-size:12px;font-family:inherit;color:#1f2937;"
            onchange="histFetch()">
            <option value="">All Types</option>
            <option value="SR">SR</option>
            <option value="CC">CC</option>
        </select>
        <input type="date" id="histDateFilter"
            style="border:1px solid #e5e5e5;border-radius:7px;padding:6px 10px;font-size:12px;font-family:inherit;color:#1f2937;"
            onchange="histFetch()">
        <button onclick="histClearFilters()" style="border:1px solid #e5e5e5;border-radius:7px;padding:6px 10px;font-size:11px;background:#fff;color:#6b7280;cursor:pointer;font-family:inherit;">
            <i class="fa-solid fa-rotate-left"></i> Reset
        </button>
    </div>
    <div id="histBody" style="flex:1;overflow-y:auto;padding:0;">
        <div id="histLoading" style="text-align:center;padding:50px 20px;color:#9ca3af;">
            <i class="fa-solid fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px;opacity:.5;"></i>
            <p style="font-size:13px;margin:0;">Loading…</p>
        </div>
    </div>
    <div id="histPager" style="padding:8px 16px;border-top:1px solid #f0f0f0;display:flex;justify-content:center;gap:6px;flex-shrink:0;background:#fafafa;"></div>
</div>
 
<!-- ══════════════════════════════════════════════════════════════
     ISSUE DETAIL MODAL  (view items of an issue)
══════════════════════════════════════════════════════════════════ -->
<div id="issDetailBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:10030;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:14px;width:860px;max-width:98vw;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.3);overflow:hidden;">
    <div id="issDetailHeader" style="padding:14px 20px;background:#1e1b4b;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <div style="font-size:14px;font-weight:800;color:#fff;display:flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-file-invoice"></i> Issue Details
            </div>
            <span id="issDetailCode" style="font-family:monospace;background:rgba(255,255,255,.15);padding:3px 12px;border-radius:8px;font-size:13px;color:#e0e7ff;"></span>
            <span id="issDetailPerson" style="font-size:11px;color:#c7d2fe;"></span>
            <span id="issDetailDate" style="font-size:11px;color:#a5b4fc;"></span>
        </div>
        <button onclick="closeIssDetail()" style="background:none;border:none;color:#a5b4fc;font-size:20px;cursor:pointer;padding:2px;">&#x2715;</button>
    </div>
    <div id="issDetailBody" style="flex:1;overflow-y:auto;padding:16px 20px;">
        <div style="text-align:center;padding:40px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px;"></i></div>
    </div>
    <div style="padding:12px 20px;border-top:2px solid #f0f0f0;display:flex;justify-content:flex-end;gap:8px;background:#fafafa;flex-shrink:0;">
        <button onclick="closeIssDetail()" style="padding:8px 16px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#555;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">
            <i class="fa-solid fa-xmark"></i> Close
        </button>
    </div>
</div>
</div>
 
<!-- DELETE ISSUE CONFIRM -->
<div id="issDeleteBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:10040;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:14px;width:420px;max-width:95vw;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.4);">
    <div style="background:#dc2626;padding:16px 20px;display:flex;align-items:center;gap:10px;">
        <i class="fa-solid fa-triangle-exclamation" style="color:#fff;font-size:20px;"></i>
        <span style="color:#fff;font-size:15px;font-weight:800;">Delete Issue</span>
    </div>
    <div style="padding:22px 20px;">
        <p style="font-size:13px;color:#374151;margin:0 0 8px;">You are about to permanently delete:</p>
        <div id="issDeleteCode" style="font-family:monospace;font-size:14px;font-weight:800;color:#dc2626;background:#fee2e2;padding:6px 12px;border-radius:7px;display:inline-block;margin:6px 0;"></div>
        <p style="margin-top:8px;font-size:13px;color:#374151;">This will remove the issue and all <strong id="issDeleteCount"></strong> associated cheque(s).</p>
        <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:7px;padding:10px 12px;font-size:12px;color:#92400e;display:flex;align-items:center;gap:8px;margin-top:10px;">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>This action <strong>cannot be undone</strong>.</span>
        </div>
    </div>
    <div style="padding:14px 20px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:8px;">
        <button onclick="closeDeleteIssue()" style="padding:8px 16px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#555;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">
            <i class="fa-solid fa-xmark"></i> Cancel
        </button>
        <button id="issDeleteConfirmBtn" onclick="executeDeleteIssue()"
            style="padding:8px 16px;border-radius:6px;border:none;background:#dc2626;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:5px;">
            <i class="fa-solid fa-trash"></i> Yes, Delete
        </button>
    </div>
</div>
</div>
 
<!-- ══════════════════════════════════════════════════════════════
     JAVASCRIPT — Cheque Issue System
══════════════════════════════════════════════════════════════════ -->
<script>
/* ── state ── */
let CHQ_SELECTED = {};     /* chequeId → {cheque_no, customer_name, amount, bank_name, cheque_date} */
let ICM_TYPE     = 'SR';
let ICM_PERSONS  = {cc:[], sr:[], emp:[]};
let HIST_PAGE    = 1;
let HIST_TIMER   = null;
let ISS_DEL_ID   = null;
let ISS_DEL_CODE = null;
 
/* ── safe fetch JSON ── */
function ciFetch(url, opts) {
    return fetch(url, opts).then(r => r.text()).then(text => {
        try { return JSON.parse(text); }
        catch(e) { throw new Error('Server error: ' + text.replace(/<[^>]*>/g,'').substring(0,200)); }
    });
}
 
/* ── HTML escape ── */
function ciEsc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
 
/* ════════════════════════════════════
   CHECKBOX COLUMN IN MAIN TABLE
   Add this to renderRows() in the existing JS,
   inserting a checkbox td at the start of each row.
   ════════════════════════════════════ */
 
/* Patch renderRows to inject checkboxes */
(function patchRenderRows() {
    const origBuildHtml = window._origBuildHtml;  /* no-op if already patched */
})();
 
/* Override the data-table row rendering to add checkboxes.
   We hook into tbody innerHTML setter via MutationObserver. */
const _ciObserver = new MutationObserver(() => {
    document.querySelectorAll('#mainTbody tr[data-id]:not([data-ci-cb])').forEach(tr => {
     tr.setAttribute('data-ci-cb', '1');
        const cid   = tr.dataset.id;
        const amt   = parseFloat(tr.querySelector('.amt-cell')?.textContent?.replace(/Rs\.?\s*/gi,'').replace(/,/g,'') || 0);
        const chqNo = tr.querySelector('.mono')?.textContent?.trim() || '';
        const cust  = tr.querySelector('.cust-sub')?.textContent?.trim() || '';
        const bankEl= tr.querySelectorAll('td')[6];
        const bank  = bankEl?.querySelector('div')?.textContent?.trim() || '';
        /* check issue status — issued cheques cannot be re-selected */
        const issStatCell = tr.querySelector('[id^="iss-stat-cell-"]');
        const isCurrentlyIssued = issStatCell && (issStatCell.querySelector('.fa-paper-plane') !== null || issStatCell.querySelector('.fa-user-check') !== null);
        /* insert checkbox td at start */
        const cbTd = document.createElement('td');
        cbTd.className = 'tc no-print';
        cbTd.style.cssText = 'width:32px;vertical-align:middle;';
        if(isCurrentlyIssued){
            cbTd.innerHTML = `<span title="Currently issued — return first" style="color:#bfdbfe;font-size:13px;cursor:not-allowed;"><i class="fa-solid fa-lock"></i></span>`;
        } else {
            cbTd.innerHTML = `<input type="checkbox" class="ci-cb" data-id="${cid}" data-chqno="${ciEsc(chqNo)}" data-cust="${ciEsc(cust)}" data-amt="${amt}" data-bank="${ciEsc(bank)}" style="accent-color:#6366f1;width:15px;height:15px;cursor:pointer;" onchange="ciOnCheck(this)">`;
            /* restore checked state if already in CHQ_SELECTED */
            if (CHQ_SELECTED[cid]) cbTd.querySelector('input').checked = true;
        }
        tr.insertBefore(cbTd, tr.firstChild);
    });
});
_ciObserver.observe(document.getElementById('mainTbody'), {childList:true, subtree:false});
 
/* Also patch the header to add cb column */
document.addEventListener('DOMContentLoaded', () => {
    const thead = document.querySelector('#mainTable thead tr');
    if (thead && !thead.querySelector('[data-ci-hdr]')) {
        const th = document.createElement('th');
        th.setAttribute('data-ci-hdr','1');
        th.className = 'tc no-print';
        th.style.width = '32px';
        th.innerHTML = `<input type="checkbox" id="ciSelAll" style="accent-color:#6366f1;width:15px;height:15px;cursor:pointer;" onchange="ciToggleAll()" title="Select all visible">`;
        thead.insertBefore(th, thead.firstChild);
    }
});
 
function ciOnCheck(cb) {
    const cid = cb.dataset.id;
    if (cb.checked) {
        CHQ_SELECTED[cid] = {
            cheque_no:     cb.dataset.chqno,
            customer_name: cb.dataset.cust,
            amount:        parseFloat(cb.dataset.amt),
            bank_name:     cb.dataset.bank,
        };
    } else {
        delete CHQ_SELECTED[cid];
    }
    refreshIssueDrawerUI();
}
 
function ciToggleAll() {
    const all = document.getElementById('ciSelAll')?.checked;
    document.querySelectorAll('#mainTbody .ci-cb').forEach(cb => {
        cb.checked = !!all;
        ciOnCheck(cb);
    });
}
 
/* ════════════════════════════════════
   ISSUE DRAWER
   ════════════════════════════════════ */
function openIssueDrawer() {
    refreshIssueDrawerUI();
    const dr = document.getElementById('issueDrawer');
    dr.style.transform = 'translateX(0)';
    document.getElementById('issueDrawerBackdrop').style.display = 'block';
    document.body.style.overflow = 'hidden';
}
function closeIssueDrawer() {
    document.getElementById('issueDrawer').style.transform = 'translateX(110%)';
    document.getElementById('issueDrawerBackdrop').style.display = 'none';
    document.body.style.overflow = '';
}
 
function refreshIssueDrawerUI() {
    const ids  = Object.keys(CHQ_SELECTED);
    const count= ids.length;
    const total= ids.reduce((s,k) => s + (CHQ_SELECTED[k].amount || 0), 0);
 
    document.getElementById('issDrawerCount').textContent      = count + ' selected';
    const tb = document.getElementById('issueToolbarBadge');
    if(tb){ tb.textContent = count + ' selected'; tb.style.display = count > 0 ? '' : 'none'; }
    document.getElementById('issDrawerStatCount').textContent  = count;
    document.getElementById('issDrawerStatAmt').textContent    = 'Rs. ' + total.toFixed(2);
    document.getElementById('issDrawerProceedCount').textContent = count;
    const btn = document.getElementById('issDrawerProceedBtn');
    btn.disabled = count === 0;
    btn.style.opacity = count > 0 ? '1' : '.5';
    btn.style.cursor  = count > 0 ? 'pointer' : 'not-allowed';
 
    const list  = document.getElementById('issDrawerList');
    const empty = document.getElementById('issDrawerEmpty');
    list.querySelectorAll('.iss-dl-item').forEach(el => el.remove());
    if (!count) { empty.style.display = ''; return; }
    empty.style.display = 'none';
    ids.forEach(cid => {
        const item = CHQ_SELECTED[cid];
        const div  = document.createElement('div');
        div.className = 'iss-dl-item';
        div.style.cssText = 'display:flex;align-items:center;gap:10px;padding:9px 16px;border-bottom:1px solid #f9fafb;';
        div.innerHTML = `
            <span style="font-family:monospace;font-size:12px;font-weight:700;color:#991b1b;min-width:90px;">${ciEsc(item.cheque_no)}</span>
            <span style="flex:1;font-size:12px;color:#374151;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${ciEsc(item.customer_name)}">${ciEsc(item.customer_name)}</span>
            <span style="font-size:10px;color:#6b7280;max-width:90px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${ciEsc(item.bank_name)}</span>
            <span style="font-size:12px;font-weight:800;color:#dc2626;min-width:80px;text-align:right;">Rs.&nbsp;${item.amount.toFixed(2)}</span>
            <button onclick="ciRemove('${cid}')" style="background:none;border:none;color:#d1d5db;cursor:pointer;padding:3px;border-radius:4px;font-size:12px;" title="Remove">
                <i class="fa-solid fa-xmark"></i>
            </button>`;
        list.appendChild(div);
    });
}
 
function ciRemove(cid) {
    delete CHQ_SELECTED[cid];
    const cb = document.querySelector(`.ci-cb[data-id="${cid}"]`);
    if (cb) cb.checked = false;
    refreshIssueDrawerUI();
    if (!Object.keys(CHQ_SELECTED).length) closeIssueDrawer();
}
 
function clearIssueSelection() {
    CHQ_SELECTED = {};
    document.querySelectorAll('.ci-cb').forEach(cb => cb.checked = false);
    const sa = document.getElementById('ciSelAll');
    if (sa) sa.checked = false;
    refreshIssueDrawerUI();
    closeIssueDrawer();
}
 
/* ════════════════════════════════════
   ISSUE CONFIRM MODAL
   ════════════════════════════════════ */
async function openIssueConfirmModal() {
    const ids = Object.keys(CHQ_SELECTED);
    if (!ids.length) { showToast('No cheques selected','err'); return; }
 
    /* load persons if not yet loaded */
    if (!ICM_PERSONS.cc.length && !ICM_PERSONS.sr.length) {
        try {
            const d = await ciFetch('return_cheques.php?ajax=issue_persons');
            if (d.success) { ICM_PERSONS.cc = d.cc_persons; ICM_PERSONS.sr = d.sr_persons; ICM_PERSONS.emp = d.employees; }
        } catch(e) { showToast('Could not load persons: '+e.message,'err'); return; }
    }
 
    /* populate selects */
    function mkOpts(items, placeholder) {
        let o = `<option value="">— ${placeholder} —</option>`;
        items.forEach(item => { o += `<option value="${ciEsc(item.code)}">${ciEsc(item.code)}${item.label && item.label !== item.code ? ' — '+ciEsc(item.label) : ''}</option>`; });
        return o;
    }
  // Destroy existing Select2 instances first
    ['icmSrSelect','icmCcSelect','icmSrEmpSelect','icmEmpSelect'].forEach(id => {
        const el = document.getElementById(id);
        if(el && window.$ && $(el).hasClass('select2-hidden-accessible')) $(el).select2('destroy');
    });

    document.getElementById('icmSrSelect').innerHTML  = mkOpts(ICM_PERSONS.sr,  'Select SR Code');
    document.getElementById('icmCcSelect').innerHTML  = mkOpts(ICM_PERSONS.cc,  'Select Delivery Person');
    const empPlaceholder = '— Select Employee (optional) —';
    const empOpts = `<option value="">${empPlaceholder}</option>` +
        ICM_PERSONS.emp.map(e => `<option value="${e.id}">${ciEsc(e.employee_id)} — ${ciEsc(e.employee_full_name)}${e.designation_name?' ('+ciEsc(e.designation_name)+')':''}</option>`).join('');
    document.getElementById('icmSrEmpSelect').innerHTML = empOpts;
    document.getElementById('icmEmpSelect').innerHTML   = empOpts;

    // Init Select2 on all four selects inside the confirm modal
    if(window.$){
        const icmBackdrop = $('#issueConfirmBackdrop');
        ['icmSrSelect','icmCcSelect'].forEach(id => {
            $(`#${id}`).select2({
                width:'100%',
                placeholder: id==='icmSrSelect' ? '— Select SR Code —' : '— Select Delivery Person —',
                allowClear: true,
                dropdownParent: icmBackdrop
            }).on('change', function(){
                const cardId = id === 'icmSrSelect' ? 'icmSrCard' : 'icmCcCard';
                const card   = document.getElementById(cardId);
                if(this.value){
                    card.style.display = 'flex';
                    const isSr = id === 'icmSrSelect';
                    card.innerHTML = `<span style="width:32px;height:32px;border-radius:50%;background:${isSr?'#ede9fe':'#fef3c7'};color:${isSr?'#6366f1':'#d97706'};display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;"><i class="fa-solid ${isSr?'fa-id-badge':'fa-wallet'}"></i></span><div><div style="font-weight:700;color:#1f2937;font-size:13px;">${ciEsc(this.value)}</div><div style="font-size:11px;color:#9ca3af;">${isSr?'Sales Representative':'Delivery Person (CC)'}</div></div>`;
                } else { card.style.display = 'none'; }
            });
        });
        ['icmSrEmpSelect','icmEmpSelect'].forEach(id => {
            $(`#${id}`).select2({
                width:'100%',
                placeholder:'— Select Employee (optional) —',
                allowClear: true,
                dropdownParent: icmBackdrop
            });
        });
    }
 
    /* bind person card preview */

 
    const total = ids.reduce((s,k) => s + (CHQ_SELECTED[k].amount || 0), 0);
    document.getElementById('icmCount').textContent = ids.length;
    document.getElementById('icmTotal').textContent = 'Rs. ' + total.toFixed(2);
    document.getElementById('icmDate').value  = new Date().toISOString().split('T')[0];
    document.getElementById('icmNotes').value = '';
    document.getElementById('icmSrCard').style.display = 'none';
    document.getElementById('icmCcCard').style.display = 'none';
 
    icmSetType('SR');  /* default SR */
 
    const bd = document.getElementById('issueConfirmBackdrop');
    bd.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
 
function closeIssueConfirmModal() {
    if(window.$){
        ['icmSrSelect','icmCcSelect','icmSrEmpSelect','icmEmpSelect'].forEach(id => {
            const el = document.getElementById(id);
            if(el && $(el).hasClass('select2-hidden-accessible')) $(el).select2('destroy');
        });
    }
    document.getElementById('issueConfirmBackdrop').style.display = 'none';
    document.body.style.overflow = '';
}
 
function icmSetType(type) {
    ICM_TYPE = type;
    document.querySelectorAll('.icm-type-btn').forEach(b => {
        const isActive = b.dataset.type === type;
        b.style.background = isActive ? (type==='SR'?'#6366f1':'#d97706') : '#f9fafb';
        b.style.color       = isActive ? '#fff' : '#6b7280';
    });
    document.getElementById('icmSrSection').style.display = type==='SR' ? '' : 'none';
    document.getElementById('icmCcSection').style.display = type==='CC' ? '' : 'none';
}
 
async function saveChequelssue() {
    const ids  = Object.keys(CHQ_SELECTED);
    const date = document.getElementById('icmDate').value;
    const notes= document.getElementById('icmNotes').value.trim();
    if (!date)      { showToast('Please select issue date','err'); return; }
    if (!ids.length){ showToast('No cheques selected','err'); return; }
 
    let personCode = '', personName = '', employeeId = '';
  if (ICM_TYPE === 'SR') {
        personCode = window.$ ? ($('#icmSrSelect').val()||'') : document.getElementById('icmSrSelect').value;
        if (!personCode) { showToast('Please select an SR','err'); return; }
        personName = personCode;
      employeeId = window.$ ? ($('#icmSrEmpSelect').val()||'') : (document.getElementById('icmSrEmpSelect').value||'');
    } else {
        personCode = window.$ ? ($('#icmCcSelect').val()||'') : document.getElementById('icmCcSelect').value;
        if (!personCode) { showToast('Please select a Delivery Person (CC)','err'); return; }
        personName = personCode;
        employeeId = window.$ ? ($('#icmEmpSelect').val()||'') : (document.getElementById('icmEmpSelect').value||'');
    }
 
    const btn = document.getElementById('icmSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
 
    const fd = new FormData();
    fd.append('action',      'save_issue');
    fd.append('issue_date',  date);
    fd.append('person_type', ICM_TYPE);
    fd.append('person_code', personCode);
    fd.append('person_name', personName);
    if (employeeId) fd.append('employee_id', employeeId);
    fd.append('notes', notes);
    ids.forEach(id => fd.append('cheque_ids[]', id));
 
    try {
        const data = await ciFetch('save_cheque_issue.php', {method:'POST', body:fd});
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save &amp; Issue';
        if (data.success) {
            showToast('✓ Issue saved: ' + data.issue_code, 'ok');
            closeIssueConfirmModal();
            closeIssueDrawer();
            clearIssueSelection();
        } else {
            showToast(data.error || 'Save failed', 'err');
        }
    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save &amp; Issue';
        showToast('Network error: ' + e.message, 'err');
    }
}
 
/* ════════════════════════════════════
   HISTORY DRAWER
   ════════════════════════════════════ */
function openHistoryDrawer() {
    const dr = document.getElementById('histDrawer');
    dr.style.transform = 'translateX(0)';
    document.getElementById('histDrawerBackdrop').style.display = 'block';
    document.body.style.overflow = 'hidden';
    HIST_PAGE = 1;
    histFetch();
}
function closeHistoryDrawer() {
    document.getElementById('histDrawer').style.transform = 'translateX(110%)';
    document.getElementById('histDrawerBackdrop').style.display = 'none';
    document.body.style.overflow = '';
}
 
function histClearFilters() {
    document.getElementById('histSearch').value    = '';
    document.getElementById('histTypeFilter').value = '';
    document.getElementById('histDateFilter').value = '';
    HIST_PAGE = 1;
    histFetch();
}
 
function histFetchDebounced() {
    clearTimeout(HIST_TIMER);
    HIST_TIMER = setTimeout(() => { HIST_PAGE=1; histFetch(); }, 350);
}
 
async function histFetch() {
    document.getElementById('histBody').innerHTML = '<div id="histLoading" style="text-align:center;padding:50px 20px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px;opacity:.5;"></i><p style="font-size:13px;margin:0;">Loading…</p></div>';
    const q     = document.getElementById('histSearch').value.trim();
    const type  = document.getElementById('histTypeFilter').value;
    const date  = document.getElementById('histDateFilter').value;
    const params= new URLSearchParams({action:'load_history', page:HIST_PAGE, q, person_type:type, issue_date:date});
    try {
        const data = await ciFetch('save_cheque_issue.php?' + params.toString());
        if (!data.success) throw new Error(data.error||'Server error');
        histRender(data);
    } catch(e) {
        document.getElementById('histBody').innerHTML = `<div style="text-align:center;padding:40px;color:#dc2626;font-size:13px;"><i class="fa-solid fa-triangle-exclamation"></i> ${ciEsc(e.message)}</div>`;
    }
}
 
function histRender(data) {
    const rows  = data.rows || [];
    const body  = document.getElementById('histBody');
    const pager = document.getElementById('histPager');
    if (!rows.length) {
        body.innerHTML = '<div style="text-align:center;padding:60px 20px;color:#9ca3af;"><i class="fa-solid fa-inbox" style="font-size:36px;display:block;margin-bottom:12px;opacity:.3;"></i><p style="font-size:13px;">No issue records found.</p></div>';
        pager.innerHTML = ''; return;
    }
    let html = '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
    html += '<thead><tr style="background:#1e1b4b;color:#e0e7ff;">'
        + '<th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;">Code</th>'
        + '<th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;">Date</th>'
        + '<th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;">Type</th>'
        + '<th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;">Issued To</th>'
        + '<th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;">Cheques</th>'
        + '<th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;">Issued</th>'
        + '<th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;">Returned</th>'
        + '<th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;">To Customer</th>'
        + '<th style="padding:8px 10px;text-align:right;font-size:10px;font-weight:700;">Amount</th>'
        + '<th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;">Actions</th>'
        + '</tr></thead><tbody>';
 
    rows.forEach(r => {
        const isCC     = r.person_type === 'CC';
        const pillCls  = isCC
            ? 'background:#fef3c7;color:#92400e;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;'
            : 'background:#ede9fe;color:#5b21b6;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;';
        const total_items   = parseInt(r.total_items)||0;
        const issued_cnt    = parseInt(r.issued_cnt)||0;
        const returned_cnt  = parseInt(r.returned_cnt)||0;
        const cust_cnt      = parseInt(r.cust_issued_cnt)||0;
        const total_amt     = parseFloat(r.total_amount||0);
 
        html += `<tr id="hist-row-${r.id}" style="border-bottom:1px solid #f3f4f6;" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background=''">
            <td style="padding:8px 10px;"><span style="font-family:monospace;font-size:11px;font-weight:700;color:#4338ca;">${ciEsc(r.issue_code)}</span></td>
            <td style="padding:8px 10px;font-size:11px;color:#374151;white-space:nowrap;">${ciEsc(r.issue_date)}</td>
            <td style="padding:8px 10px;text-align:center;">
                <span style="${pillCls}">${ciEsc(r.person_type)}</span>
            </td>
            <td style="padding:8px 10px;">
                <div style="font-size:12px;font-weight:700;color:#1f2937;">${ciEsc(r.person_code)}</div>
                ${r.person_name && r.person_name !== r.person_code ? `<div style="font-size:10px;color:#6b7280;">${ciEsc(r.person_name)}</div>` : ''}
                ${r.emp_name ? `<div style="font-size:10px;color:#0891b2;"><i class="fa-solid fa-user-check" style="font-size:9px;"></i> ${ciEsc(r.emp_name)}${r.emp_desig?' ('+ciEsc(r.emp_desig)+')':''}</div>` : ''}
            </td>
            <td style="padding:8px 10px;text-align:center;font-weight:700;">${total_items}</td>
            <td style="padding:8px 10px;text-align:center;">
                ${issued_cnt > 0 ? `<span style="background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-paper-plane"></i> ${issued_cnt}</span>` : '<span style="color:#d1d5db;font-size:11px;">—</span>'}
            </td>
            <td style="padding:8px 10px;text-align:center;">
                ${returned_cnt > 0 ? `<span style="background:#dcfce7;color:#166534;border:1px solid #86efac;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-rotate-left"></i> ${returned_cnt}</span>` : '<span style="color:#d1d5db;font-size:11px;">—</span>'}
            </td>
            <td style="padding:8px 10px;text-align:center;">
                ${cust_cnt > 0 ? `<span style="background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-user-check"></i> ${cust_cnt}</span>` : '<span style="color:#d1d5db;font-size:11px;">—</span>'}
            </td>
            <td style="padding:8px 10px;text-align:right;font-weight:700;color:#dc2626;font-size:11px;">Rs.&nbsp;${total_amt.toFixed(2)}</td>
            <td style="padding:8px 10px;text-align:center;">
                <div style="display:flex;align-items:center;gap:4px;justify-content:center;">
                    <button style="background:#0ea5e9;color:#fff;border:none;border-radius:5px;padding:4px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;white-space:nowrap;"
                        onclick="openIssDetail(${r.id},'${ciEsc(r.issue_code)}','${ciEsc(r.person_type)}','${ciEsc(r.issue_date)}','${ciEsc(r.person_code)}','${ciEsc(r.person_name)}','${ciEsc(r.emp_name)}','${ciEsc(r.emp_desig)}')">
                        <i class="fa-solid fa-eye"></i> View
                    </button>
                    <button style="background:#dc2626;color:#fff;border:none;border-radius:5px;padding:4px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;"
                        onclick="confirmDeleteIssue(${r.id},'${ciEsc(r.issue_code)}',${total_items})">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>`;
    });
    html += '</tbody></table>';
    body.innerHTML = html;
 
    /* pager */
    if (data.pages <= 1) { pager.innerHTML = ''; return; }
    let ph = '';
    for (let p = 1; p <= data.pages; p++) {
        const active = p === data.page;
        ph += `<button onclick="histGoPage(${p})" style="border:1.5px solid ${active?'#1e1b4b':'#e5e5e5'};background:${active?'#1e1b4b':'#fff'};color:${active?'#fff':'#374151'};border-radius:6px;padding:4px 10px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;">${p}</button>`;
    }
    pager.innerHTML = ph;
}
 
function histGoPage(p) { HIST_PAGE = p; histFetch(); }
 
/* ════════════════════════════════════
   ISSUE DETAIL MODAL
   ════════════════════════════════════ */
function openIssDetail(issId, code, type, date, personCode, personName, empName, empDesig) {
    document.getElementById('issDetailCode').textContent   = code;
    document.getElementById('issDetailPerson').textContent = (type==='CC'?'CC:':'SR:') + ' ' + personCode + (personName&&personName!==personCode?' ('+personName+')':'');
    document.getElementById('issDetailDate').innerHTML     = '<i class="fa-solid fa-calendar-day" style="font-size:10px;"></i> ' + date;
    document.getElementById('issDetailBody').innerHTML     = '<div style="text-align:center;padding:40px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px;"></i></div>';
 
    const bd = document.getElementById('issDetailBackdrop');
    bd.style.display = 'flex';
    document.body.style.overflow = 'hidden';
 
    ciFetch('save_cheque_issue.php?action=load_items&issue_id=' + issId)
    .then(data => {
        if (!data.success) throw new Error(data.error||'Server error');
        renderIssDetailItems(issId, data.items);
    })
    .catch(e => {
        document.getElementById('issDetailBody').innerHTML = `<div style="text-align:center;padding:40px;color:#dc2626;font-size:13px;"><i class="fa-solid fa-triangle-exclamation"></i> ${ciEsc(e.message)}</div>`;
    });
}
 
function closeIssDetail() {
    document.getElementById('issDetailBackdrop').style.display = 'none';
    document.body.style.overflow = '';
}
 
function renderIssDetailItems(issId, items) {
    if (!items.length) {
        document.getElementById('issDetailBody').innerHTML = '<div style="text-align:center;padding:50px;color:#9ca3af;font-size:13px;"><i class="fa-solid fa-inbox" style="font-size:32px;display:block;margin-bottom:12px;opacity:.3;"></i> No items found.</div>';
        return;
    }
    let totAmt = 0, issuedCnt = 0, returnedCnt = 0, custCnt = 0;
    items.forEach(i => { totAmt += parseFloat(i.amount||0); if(i.status==='issued') issuedCnt++; else if(i.status==='issued_to_customer') custCnt++; else returnedCnt++; });
 
    let html = `<div style="display:flex;gap:12px;flex-wrap:wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:10px 16px;margin-bottom:14px;">
        <div style="display:flex;flex-direction:column;gap:2px;"><div style="font-size:15px;font-weight:800;color:#1f2937;">Rs.&nbsp;${totAmt.toFixed(2)}</div><div style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Total Amount</div></div>
        <div style="display:flex;flex-direction:column;gap:2px;"><div style="font-size:15px;font-weight:800;color:#d97706;">${issuedCnt}</div><div style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Issued</div></div>
        <div style="display:flex;flex-direction:column;gap:2px;"><div style="font-size:15px;font-weight:800;color:#16a34a;">${returnedCnt}</div><div style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Returned</div></div>
        <div style="display:flex;flex-direction:column;gap:2px;"><div style="font-size:15px;font-weight:800;color:#0f766e;">${custCnt}</div><div style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Issued to Customer</div></div>
    </div>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;">
    <thead><tr style="background:#1e1b4b;color:#e0e7ff;">
        <th style="padding:8px;text-align:left;font-size:10px;font-weight:700;">#</th>
        <th style="padding:8px;text-align:left;font-size:10px;font-weight:700;">Cheque No.</th>
        <th style="padding:8px;text-align:left;font-size:10px;font-weight:700;">Customer</th>
        <th style="padding:8px;text-align:left;font-size:10px;font-weight:700;">Bank</th>
        <th style="padding:8px;text-align:left;font-size:10px;font-weight:700;">Cheque Date</th>
        <th style="padding:8px;text-align:right;font-size:10px;font-weight:700;">Amount</th>
        <th style="padding:8px;text-align:center;font-size:10px;font-weight:700;">Status</th>
        <th style="padding:8px;text-align:center;font-size:10px;font-weight:700;">Updated</th>
        <th style="padding:8px;text-align:center;font-size:10px;font-weight:700;">Actions</th>
    </tr></thead><tbody>`;
 
    items.forEach((item, i) => {
        const isIssued   = item.status === 'issued';
        const isReturned = item.status === 'returned';
        const isCust     = item.status === 'issued_to_customer';
        const settled    = parseInt(item.return_settled||0);
        const rowBg      = (isReturned || isCust) ? '#f9fafb' : '';
 
        let statusBadge = '';
        if (isIssued)      statusBadge = `<span style="background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-paper-plane"></i> Issued</span>`;
        else if (isCust)   statusBadge = `<span style="background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-user-check"></i> Issued to Customer</span>`;
        else               statusBadge = `<span style="background:#dcfce7;color:#166534;border:1px solid #86efac;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-rotate-left"></i> Returned</span>`;
 
        let actionCell = '';
        if (isIssued) {
            actionCell = `<button onclick="ciMarkReturned(${item.id}, this)" style="background:#d97706;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;">
                <i class="fa-solid fa-rotate-left"></i> Return
            </button>
            <button onclick="ciRemoveItem(${item.id}, ${issId}, this)" style="background:#dc2626;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;margin-left:4px;">
                <i class="fa-solid fa-trash"></i>
            </button>`;
        } else {
            actionCell = `<button onclick="ciReissueItem(${item.id}, this)" style="background:#0d9488;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;">
                <i class="fa-solid fa-rotate-right"></i> Re-issue
            </button>`;
        }
 
        html += `<tr id="iss-item-row-${item.id}" style="border-bottom:1px solid #f3f4f6;background:${rowBg};">
            <td style="padding:7px 8px;color:#9ca3af;font-size:11px;">${i+1}</td>
            <td style="padding:7px 8px;"><span style="font-family:monospace;font-weight:700;color:#991b1b;font-size:12px;">${ciEsc(item.cheque_no)}</span></td>
            <td style="padding:7px 8px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;">${ciEsc(item.customer_name)}</td>
            <td style="padding:7px 8px;font-size:11px;"><div style="font-weight:600;">${ciEsc(item.bank_name||'')}</div>${item.branch_name?`<div style="color:#6b7280;font-size:10px;">${ciEsc(item.branch_name)}</div>`:''}</td>
            <td style="padding:7px 8px;font-size:11px;color:#374151;white-space:nowrap;">${ciEsc(item.cheque_date||'—')}</td>
            <td style="padding:7px 8px;text-align:right;font-weight:700;color:#dc2626;">Rs.&nbsp;${parseFloat(item.amount).toFixed(2)}</td>
            <td style="padding:7px 8px;text-align:center;" id="iss-item-status-${item.id}">${statusBadge}</td>
            <td style="padding:7px 8px;text-align:center;font-size:10px;color:#6b7280;" id="iss-item-upd-${item.id}">${item.customer_issued_at||item.returned_at||'—'}</td>
            <td style="padding:7px 8px;text-align:center;" id="iss-item-action-${item.id}">${actionCell}</td>
        </tr>`;
    });
 
    html += '</tbody></table></div>';
    document.getElementById('issDetailBody').innerHTML = html;
}
 
/* ── item actions in detail modal ── */
function ciMarkReturned(itemId, btn) {
    if (!confirm('Mark this cheque as returned?')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    const fd = new FormData();
    fd.append('action','mark_returned'); fd.append('item_id', itemId);
    ciFetch('save_cheque_issue.php', {method:'POST', body:fd})
    .then(data => {
        btn.disabled = false;
        if (data.success) {
            showToast('Marked as returned ✓', 'ok');
            const tr = document.getElementById('iss-item-row-'+itemId);
            if (tr) tr.style.background = '#f9fafb';
            const ss = document.getElementById('iss-item-status-'+itemId);
            if (ss) ss.innerHTML = `<span style="background:#dcfce7;color:#166534;border:1px solid #86efac;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-rotate-left"></i> Returned</span>`;
            const ua = document.getElementById('iss-item-upd-'+itemId);
            if (ua) ua.textContent = new Date().toLocaleString('en-GB');
            const ac = document.getElementById('iss-item-action-'+itemId);
            if (ac) ac.innerHTML = `<button onclick="ciReissueItem(${itemId}, this)" style="background:#0d9488;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;"><i class="fa-solid fa-rotate-right"></i> Re-issue</button>`;
        } else {
            btn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Return';
            showToast(data.error||'Failed', 'err');
        }
    })
    .catch(e => { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-rotate-left"></i> Return'; showToast(e.message,'err'); });
}
 
function ciReissueItem(itemId, btn) {
    if (!confirm('Re-issue this cheque?')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    const fd = new FormData();
    fd.append('action','reissue_item'); fd.append('item_id', itemId);
    ciFetch('save_cheque_issue.php', {method:'POST', body:fd})
    .then(data => {
        btn.disabled = false;
        if (data.success) {
            showToast('Re-issued ✓', 'ok');
            const tr = document.getElementById('iss-item-row-'+itemId);
            if (tr) tr.style.background = '';
            const ss = document.getElementById('iss-item-status-'+itemId);
            if (ss) ss.innerHTML = `<span style="background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-paper-plane"></i> Issued</span>`;
            const ua = document.getElementById('iss-item-upd-'+itemId);
            if (ua) ua.textContent = '—';
            const ac = document.getElementById('iss-item-action-'+itemId);
            if (ac) ac.innerHTML = `<button onclick="ciMarkReturned(${itemId}, this)" style="background:#d97706;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;"><i class="fa-solid fa-rotate-left"></i> Return</button><button onclick="ciRemoveItem(${itemId}, 0, this)" style="background:#dc2626;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;margin-left:4px;"><i class="fa-solid fa-trash"></i></button>`;
        } else {
            btn.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Re-issue';
            showToast(data.error||'Failed', 'err');
        }
    })
    .catch(e => { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-rotate-right"></i> Re-issue'; showToast(e.message,'err'); });
}
 
function ciRemoveItem(itemId, issId, btn) {
    if (!confirm('Remove this cheque from the issue?')) return;
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action','remove_item'); fd.append('item_id', itemId);
    ciFetch('save_cheque_issue.php', {method:'POST', body:fd})
    .then(data => {
        if (data.success) {
            showToast('Removed ✓', 'ok');
            const tr = document.getElementById('iss-item-row-'+itemId);
            if (tr) { tr.style.opacity='0'; tr.style.transition='opacity .3s'; setTimeout(()=>tr.remove(), 320); }
        } else {
            btn.disabled = false;
            showToast(data.error||'Failed', 'err');
        }
    })
    .catch(e => { btn.disabled=false; showToast(e.message,'err'); });
}
 
/* ════════════════════════════════════
   DELETE ISSUE
   ════════════════════════════════════ */
function confirmDeleteIssue(issId, issCode, billCount) {
    ISS_DEL_ID   = issId;
    ISS_DEL_CODE = issCode;
    document.getElementById('issDeleteCode').textContent  = issCode;
    document.getElementById('issDeleteCount').textContent = billCount;
    const bd = document.getElementById('issDeleteBackdrop');
    bd.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeDeleteIssue() {
    document.getElementById('issDeleteBackdrop').style.display = 'none';
    document.body.style.overflow = '';
    ISS_DEL_ID = null;
}
function executeDeleteIssue() {
    if (!ISS_DEL_ID) return;
    const btn = document.getElementById('issDeleteConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';
    const fd = new FormData();
    fd.append('action','delete_issue'); fd.append('issue_id', ISS_DEL_ID);
    ciFetch('save_cheque_issue.php', {method:'POST', body:fd})
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-trash"></i> Yes, Delete';
        if (data.success) {
            showToast('Issue ' + ISS_DEL_CODE + ' deleted', 'ok');
            closeDeleteIssue();
            /* remove from history list */
            const row = document.getElementById('hist-row-' + ISS_DEL_ID);
            if (row) { row.style.opacity='0'; row.style.transition='opacity .3s'; setTimeout(()=>row.remove(),320); }
            ISS_DEL_ID = null;
        } else {
            showToast(data.error||'Delete failed', 'err');
        }
    })
    .catch(e => {
        btn.disabled=false;
        btn.innerHTML='<i class="fa-solid fa-trash"></i> Yes, Delete';
        showToast(e.message,'err');
    });
}
 
/* Keyboard close */
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeIssueConfirmModal();
        closeIssDetail();
        closeDeleteIssue();
        closeHistoryDrawer();
        closeIssueDrawer();
    }
});
</script>


<script>
const BANKS = <?php echo json_encode($banks_list); ?>;

$(function(){
    $('#selBank').select2({placeholder:'— All Banks —',allowClear:true,width:'100%'});
    $('#selSettled').select2({placeholder:'— All —',allowClear:true,width:'100%'});
});

function toggleFilter(){
    const header=document.getElementById('filterHeader'),body=document.getElementById('filterBody'),icon=document.getElementById('filterIcon');
    const isOpen=body.classList.contains('open');
    if(isOpen){body.classList.remove('open');header.classList.remove('open');icon.classList.remove('open');}
    else{body.classList.add('open');header.classList.add('open');icon.classList.add('open');}
}
function filterBySettled(val){
    document.getElementById('selSettled').value=val;
    if(window.$) $(document.getElementById('selSettled')).val(val).trigger('change');
    currentPage=1;fetchRows();
}

const PAGE_SIZE=50;
let currentPage=1,currentSearch='',fetchTimer=null,lastController=null;

function getFilters(){
    return {
        bank_code: document.getElementById('selBank')?.value||'',
        t_code:    document.getElementById('fTcode')?.value||'',
        date_from: document.getElementById('fFrom')?.value||'',
        date_to:   document.getElementById('fTo')?.value||'',
        settled:   document.getElementById('selSettled')?.value||'',
        q:         currentSearch,
        page:      currentPage,
        per:       PAGE_SIZE,
    };
}
function applyFilters(){currentPage=1;fetchRows();}
function clearFilters(){
    document.getElementById('selBank').value='';
    document.getElementById('selSettled').value='';
    document.getElementById('fTcode').value='';
    document.getElementById('fFrom').value='';
    document.getElementById('fTo').value='';
    if(window.$){$('#selBank').val('').trigger('change');$('#selSettled').val('').trigger('change');}
    currentPage=1;currentSearch='';
    const s=document.getElementById('chqSearch');if(s)s.value='';
    document.getElementById('chqClr')?.classList.remove('show');
    fetchRows();
}

async function fetchRows(){
    if(lastController) lastController.abort();
    lastController=new AbortController();
    showLoading(true);
    const f=getFilters();
    const params=new URLSearchParams({ajax:'returned_cheque_rows',...f});
    try{
        const res=await fetch('return_cheques.php?'+params.toString(),{signal:lastController.signal});
        if(!res.ok) throw new Error('HTTP '+res.status);
        const data=await res.json();
        if(!data.success) throw new Error(data.error||'Server error');
        renderRows(data);
    }catch(e){
        if(e.name==='AbortError') return;
        document.getElementById('mainTbody').innerHTML=`<tr><td colspan="14" style="text-align:center;padding:40px;color:#dc2626;"><i class="fa-solid fa-triangle-exclamation"></i> Error: ${esc(e.message)}</td></tr>`;
    }finally{showLoading(false);}
}
function showLoading(on){document.getElementById('tblLoading')?.classList.toggle('show',on);}

function fmtDate(d){
    if(!d||d==='0000-00-00'||d==='0000-00-00 00:00:00') return '<span class="date-txt empty">—</span>';
    const dt=new Date(d.replace(' ','T'));
    if(isNaN(dt.getTime())) return '<span class="date-txt empty">—</span>';
    const m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return `<span class="date-txt">${String(dt.getDate()).padStart(2,'0')} ${m[dt.getMonth()]} ${dt.getFullYear()}</span>`;
}

/* ══ Return Date — now click-to-edit ══ */
function toIsoDate(d){
    if(!d) return '';
    return String(d).substring(0,10);
}

function fmtReturnDate(d, chequeId){
    if(!d||d==='0000-00-00'||d==='0000-00-00 00:00:00'||d===null){
        return `<span class="return-date-chip empty" style="cursor:pointer;" title="Click to set return date" onclick="openReturnDateEdit(${chequeId})">
            <i class="fa-solid fa-circle-xmark"></i> — <i class="fa-solid fa-pen" style="font-size:9px;margin-left:4px;opacity:.6;"></i></span>`;
    }
    const dt=new Date(d.replace(' ','T'));
    if(isNaN(dt.getTime())) return `<span class="return-date-chip empty" style="cursor:pointer;" onclick="openReturnDateEdit(${chequeId})">—</span>`;
    const m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const dateStr=`${String(dt.getDate()).padStart(2,'0')} ${m[dt.getMonth()]} ${dt.getFullYear()}`;
    return `<span class="return-date-chip" style="cursor:pointer;" title="Click to edit return date" onclick="openReturnDateEdit(${chequeId})">
        <i class="fa-solid fa-calendar-xmark"></i> ${dateStr} <i class="fa-solid fa-pen" style="font-size:9px;margin-left:4px;opacity:.6;"></i></span>`;
}

function openReturnDateEdit(chequeId){
    const cell = document.getElementById(`retdate-cell-${chequeId}`);
    if(!cell) return;
    const row = document.getElementById(`row-${chequeId}`);
    const currentRaw = row?.dataset.returnDate || '';
    const iso = toIsoDate(currentRaw);
    cell.innerHTML = `
      <div style="display:flex;align-items:center;gap:4px;justify-content:center;">
        <input type="date" id="retdate-input-${chequeId}" value="${iso}"
               style="border:1.5px solid #fca5a5;border-radius:6px;padding:4px 6px;font-size:11px;font-family:inherit;width:128px;">
        <button onclick="saveReturnDate(${chequeId})" title="Save"
                style="background:#16a34a;color:#fff;border:none;border-radius:5px;width:24px;height:24px;cursor:pointer;font-size:11px;">
          <i class="fa-solid fa-check"></i></button>
        <button onclick="cancelReturnDateEdit(${chequeId})" title="Cancel"
                style="background:#9ca3af;color:#fff;border:none;border-radius:5px;width:24px;height:24px;cursor:pointer;font-size:11px;">
          <i class="fa-solid fa-xmark"></i></button>
      </div>`;
    document.getElementById(`retdate-input-${chequeId}`)?.focus();
}

function cancelReturnDateEdit(chequeId){
    const row = document.getElementById(`row-${chequeId}`);
    const cell = document.getElementById(`retdate-cell-${chequeId}`);
    if(!row||!cell) return;
    cell.innerHTML = fmtReturnDate(row.dataset.returnDate||'', chequeId);
}

async function saveReturnDate(chequeId){
    const input = document.getElementById(`retdate-input-${chequeId}`);
    const newDate = input ? input.value : '';
    const fd = new FormData();
    fd.append('ajax_action','update_return_date');
    fd.append('cheque_id', chequeId);
    fd.append('return_date', newDate);
    try{
        const res = await fetch('return_cheques.php', {method:'POST', body:fd});
        const data = await res.json();
        if(!data.success){ showToast(data.error||'Update failed','err'); return; }
        const row = document.getElementById(`row-${chequeId}`);
        if(row) row.dataset.returnDate = newDate;
        const cell = document.getElementById(`retdate-cell-${chequeId}`);
        if(cell) cell.innerHTML = fmtReturnDate(newDate, chequeId);
        /* keep the Aging (Return) column in sync since it depends on the same date */
        const agingCell = document.getElementById(`aging-return-cell-${chequeId}`);
        if(agingCell) agingCell.innerHTML = fmtAging(calcAgingDays(newDate));
        showToast('Return date updated ✓','ok');
    }catch(e){
        showToast('Network error: '+e.message,'err');
    }
}

function fmtAging(days) {
    if (days === null || days === undefined || isNaN(days) || days < 0)
        return '<span class="aging-badge aging-green" style="color:#9ca3af;border-color:#e5e7eb;background:#f9fafb;"><i class="fa-solid fa-minus" style="font-size:9px;"></i> —</span>';
    let cls, icon;
    if      (days >= 90) { cls = 'aging-red';    icon = 'fa-fire'; }
    else if (days >= 60) { cls = 'aging-orange'; icon = 'fa-triangle-exclamation'; }
    else if (days >= 30) { cls = 'aging-yellow'; icon = 'fa-clock'; }
    else                 { cls = 'aging-green';  icon = 'fa-circle-check'; }
    return `<span class="aging-badge ${cls}" title="${days} days"><i class="fa-solid ${icon}" style="font-size:9px;"></i> ${days}d</span>`;
}

function calcAgingDays(dateStr) {
    if (!dateStr || dateStr === '0000-00-00' || dateStr === '0000-00-00 00:00:00') return null;
    const dt = new Date(dateStr.replace(' ', 'T'));
    if (isNaN(dt.getTime())) return null;
    const today = new Date();
    today.setHours(0,0,0,0);
    return Math.floor((today - dt) / 86400000);
}

function renderRows(data){
    const tbody=document.getElementById('mainTbody');
    const rows=data.rows||[],total=data.total||0,pages=data.pages||1,g_amt=data.grand_total||0;
    window._lastFetchedRows = rows;   /* cache for Excel export */
    const offset=(currentPage-1)*PAGE_SIZE;
    document.getElementById('visCount').textContent=total+' records';
    document.getElementById('footerCount').textContent=total;
    document.getElementById('footerTotal').textContent=g_amt.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('filteredCount').textContent=total;
    document.getElementById('filteredAmt').textContent='Rs. '+g_amt.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('filteredSub').innerHTML=`<i class="fa-solid fa-table-list" style="font-size:10px;color:#dc2626;"></i> ${total} matching records`;
    if(!rows.length){
        tbody.innerHTML=`<tr><td colspan="14"><div class="state-box"><i class="fa-solid fa-inbox"></i><p>No returned cheques found.</p></div></td></tr>`;
        document.getElementById('pagerWrap').style.display='none';
        return;
    }
    let html='';
    rows.forEach((row,idx)=>{
        const rn=offset+idx+1;
        const settled=parseInt(row.return_settled||0);
        const cheqAmt=parseFloat(row.total_amount||0);
        const settlePaid=parseFloat(row.settlement_amount||0);
        const settleRemain=Math.max(0,cheqAmt-settlePaid);
        const id=row.id;

        let settleBadge, settleBtn;
        if(settled){
            settleBadge=`<span class="settled-badge yes"><i class="fa-solid fa-circle-check"></i> Fully Settled</span>`
                       +`<div class="settle-meta" style="color:#166534;">Paid: Rs.${settlePaid.toFixed(2)} | Bal: Rs.0.00</div>`;
            settleBtn=`<button class="btn-loadpay settled" onclick="openSettleModal(${id})"><i class="fa-solid fa-circle-check"></i> View</button>`;
        } else if(settlePaid>0){
            settleBadge=`<span class="settled-badge no" style="background:#fff7ed;color:#c2410c;border-color:#fed7aa;"><i class="fa-solid fa-circle-half-stroke"></i> Partially</span>`
                       +`<div class="settle-meta" style="color:#92400e;">Paid: Rs.${settlePaid.toFixed(2)} | Bal: Rs.${settleRemain.toFixed(2)}</div>`;
            settleBtn=`<button class="btn-loadpay" onclick="openSettleModal(${id})"><i class="fa-solid fa-money-bill-transfer"></i> Load Pay</button>`;
        } else {
            settleBadge=`<span class="settled-badge no"><i class="fa-solid fa-clock"></i> Unsettled</span>`
                       +`<div class="settle-meta" style="color:#9ca3af;">Paid: Rs.0.00 | Bal: Rs.${cheqAmt.toFixed(2)}</div>`;
            settleBtn=`<button class="btn-loadpay" onclick="openSettleModal(${id})"><i class="fa-solid fa-money-bill-transfer"></i> Load Pay</button>`;
        }

        const hasCrn=(row.crn_no||'').trim()!=='';
        const isRep=parseInt(row.is_representable);
        let crnCell='';
        if(hasCrn){
            const repClass=isRep===1?'crn-rep':'crn-nonrep';
            const repIcon =isRep===1?'fa-rotate-right':'fa-ban';
            const repLabel=isRep===1?'Re-presentable':'Non-representable';
            crnCell=`<div class="crn-badge-cell">
                <span class="crn-status-pill ${repClass}"><i class="fa-solid ${repIcon}"></i> ${repLabel}</span>
                <div class="crn-no-txt">${esc(row.crn_no)}</div>
                ${row.crn_return_code?`<div style="font-size:10px;color:#64748b;">Code: ${esc(row.crn_return_code)} — ${esc(row.crn_return_reason||'')}</div>`:''}
            </div>`;
        } else {
            crnCell=`<span class="crn-status-pill crn-pending"><i class="fa-solid fa-clock"></i> No CRN</span>`;
        }

        const crnBtnClass=hasCrn?'btn-crn has-crn':'btn-crn';
        const crnBtnLabel=hasCrn?'<i class="fa-solid fa-file-invoice"></i> CRN ✓':'<i class="fa-solid fa-file-invoice"></i> CRN';

        const crnData=JSON.stringify({
            crn_no:row.crn_no||'',crn_return_reason:row.crn_return_reason||'',
            crn_return_code:row.crn_return_code||'',crn_cheque_status:row.crn_cheque_status||'',
            crn_return_remark:row.crn_return_remark||'',crn_collecting_bank:row.crn_collecting_bank||'',
            crn_collecting_branch:row.crn_collecting_branch||'',crn_date_of_return:row.crn_date_of_return||'',
            crn_file_path:row.crn_file_path||'',crn_uploaded_by:row.crn_uploaded_by||'',
            crn_uploaded_at:row.crn_uploaded_at||'',
        });

        /* Return Date priority: manual override (return_date) → CRN date → cheque_logs 'returned' entry */
        const returnDateRaw = row.return_date || row.crn_date_of_return || row.returned_date || null;

        html+=`
        <tr id="row-${id}" data-id="${id}" data-settled="${settled}"
            data-issue-item-id="${row.issue_item_id||''}"
            data-issue-status="${row.issue_item_status||''}"
            data-issue-code="${esc(row.issue_code||'')}"
            data-return-date="${esc(returnDateRaw||'')}">
          <td style="color:#9ca3af;font-size:11px;font-weight:600;">${rn}</td>
          <td class="tc"><span class="sr-pill">${esc(row.sr_code||'—')}</span></td>
          <td class="tc">${fmtDate(row.cheque_date)}</td>
          <td class="tc">${fmtDate(row.received_date)}</td>
         <td class="tc" id="retdate-cell-${id}">${fmtReturnDate(returnDateRaw, id)}</td>
<td class="tc" id="aging-return-cell-${id}">${fmtAging(calcAgingDays(returnDateRaw))}</td>
<td class="tc">${fmtAging(calcAgingDays(row.received_date || null))}</td>
          <td><span class="mono" style="color:#991b1b;font-size:12.5px;">${esc(row.cheque_no||'—')}</span></td>
          <td><div style="font-size:12px;font-weight:600;color:#1f2937;white-space:nowrap;">${esc(row.bank_name||row.bank_code||'—')}</div>${row.branch_name?`<div class="cust-sub">${esc(row.branch_name)}</div>`:''}</td>
          <td class="tc"><span class="mono" style="font-size:11px;">${esc(row.bank_code||'—')}</span></td>
       <td class="tr"><span class="amt-cell">Rs.&nbsp;${cheqAmt.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</span></td>
          <td class="tr"><span style="font-weight:700;color:#16a34a;white-space:nowrap;">Rs.&nbsp;${settlePaid.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</span></td>
          <td class="tr"><span style="font-weight:700;color:${settleRemain>0?'#d97706':'#16a34a'};white-space:nowrap;">Rs.&nbsp;${settleRemain.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</span></td>
       <td><span class="mono" style="font-size:11.5px;color:#4338ca;">${esc(row.t_code||'—')}</span>${row.customer_name?`<div class="cust-sub">${esc(row.customer_name)}</div>`:''}</td>
          <td><span class="mono" style="font-size:11.5px;">${esc(row.invoice_num||'—')}</span></td>
          <td class="tc" id="sc-${id}">${settleBadge}</td>
          <td class="tc" id="crn-cell-${id}">${crnCell}</td>
   <td class="tc" id="iss-stat-cell-${id}">${buildIssueStatusBadge(row)}</td>
          <td class="tc no-print">
        <div style="display:flex;flex-direction:column;gap:4px;align-items:center;">
  ${settleBtn}
  <button class="${crnBtnClass}" id="crn-btn-${id}"
    onclick='openCrnModal(${id},"${esc(row.cheque_no||'')}","${esc(row.bank_name||row.bank_code||'')}",${crnData.replace(/'/g,"&#39;")})'>${crnBtnLabel}</button>
  ${isRep === 1 ? `<button
    style="display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#16a34a,#22c55e);color:#fff;border:none;border-radius:6px;padding:5px 11px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;transition:filter .2s;"
    onmouseover="this.style.filter='brightness(1.1)'" onmouseout="this.style.filter=''"
    onclick="openRepresentModal(${id},'${esc(row.cheque_no||'')}','${esc(row.bank_name||row.bank_code||'')}')">
    <i class="fa-solid fa-rotate-right"></i> Re-present
  </button>` : ''}
</div>
          </td>
        </tr>`;
    });
    tbody.innerHTML=html;
    buildPager(pages,total,offset,Math.min(offset+PAGE_SIZE,total));

    /* fully-settled cheques still marked 'issued' get auto-returned */
    rows.forEach(row=>{
        const settled = parseInt(row.return_settled||0);
        if(settled && row.issue_item_status === 'issued'){
            autoReturnIfFullySettled(document.getElementById('row-'+row.id));
        }
    });
}



function buildIssueStatusBadge(row){
    const status  = row.issue_item_status || '';
    const code    = row.issue_code || '';
    const itemId  = row.issue_item_id || '';
    const settled = parseInt(row.return_settled||0);

    if(status === 'issued'){
        const badge = `<span style="display:inline-flex;align-items:center;gap:4px;background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;padding:3px 9px;border-radius:9px;font-size:10px;font-weight:700;white-space:nowrap;">
            <i class="fa-solid fa-paper-plane"></i> Issued</span>
            ${code ? `<div style="font-size:9px;color:#6b7280;margin-top:2px;">${esc(code)}</div>` : ''}`;

        if(settled){
            /* fully settled — auto-updates to Issued to Customer on load, no button needed */
            return badge + `<div style="font-size:9px;color:#0f766e;margin-top:3px;display:flex;align-items:center;gap:3px;justify-content:center;">
                <i class="fa-solid fa-rotate"></i> Issuing to customer…</div>`;
        }
        /* partially settled or unsettled — just a check-to-mark control, no status change yet */
        return badge + `<div style="margin-top:4px;">
            <label style="display:inline-flex;align-items:center;gap:4px;font-size:10px;color:#92400e;cursor:pointer;">
                <input type="checkbox" class="return-select-cb" data-item-id="${itemId}"
                       onchange="toggleReturnSelect(this)">
                Mark for Return
            </label>
        </div>`;
    } else if(status === 'returned'){
        return `<span style="display:inline-flex;align-items:center;gap:4px;background:#dcfce7;color:#166534;border:1px solid #86efac;padding:3px 9px;border-radius:9px;font-size:10px;font-weight:700;white-space:nowrap;">
            <i class="fa-solid fa-rotate-left"></i> Returned</span>
            ${code ? `<div style="font-size:9px;color:#6b7280;margin-top:2px;">${esc(code)}</div>` : ''}`;
    } else if(status === 'issued_to_customer'){
        return `<span style="display:inline-flex;align-items:center;gap:4px;background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;padding:3px 9px;border-radius:9px;font-size:10px;font-weight:700;white-space:nowrap;">
            <i class="fa-solid fa-user-check"></i> Issued to Customer</span>
            ${code ? `<div style="font-size:9px;color:#6b7280;margin-top:2px;">${esc(code)}</div>` : ''}`;
    }
    return `<span style="color:#d1d5db;font-size:11px;">—</span>`;
}

/* ═══════════════════════════════════════════════
   RETURN CHECKBOX — ticking it saves immediately
   (same as the credit-bill "Return" button — no
   separate confirm step needed beyond the browser
   confirm() prompt).
═══════════════════════════════════════════════ */
async function toggleReturnSelect(cb){
    const itemId = cb.dataset.itemId;
    if(!itemId){
        showToast('No issue reference found for this cheque — cannot mark returned.','err');
        cb.checked = false;
        return;
    }
    if(!cb.checked) return; /* unchecking does nothing — return is a one-way action */

    if(!confirm('Mark this cheque as returned to customer?')){
        cb.checked = false;
        return;
    }
    cb.disabled = true;
    const label = cb.closest('label');
    if(label) label.style.opacity = '0.6';

    try{
        const fd = new FormData();
        fd.append('action','mark_returned');
        fd.append('item_id', itemId);
        const res = await fetch('save_cheque_issue.php', {method:'POST', body:fd});
        let data;
        try{
            data = await res.json();
        }catch(parseErr){
            showToast('Server did not return a valid response (HTTP '+res.status+'). Check save_cheque_issue.php for errors.','err');
            cb.checked = false; cb.disabled = false; if(label) label.style.opacity = '';
            return;
        }
        if(data.success){
            showToast('Cheque marked as returned ✓','ok');
            const rowEl = cb.closest('tr');
            applyReturnedIssueStatus(rowEl, itemId);
            /* keep the settle-modal in sync if it's open on the same item */
            if(ARD && String(ARD.issueItemId) === String(itemId)){
                ARD.issueItemStatus = 'returned';
                const box = document.getElementById('modalReturnBox');
                if(box){
                    box.className = 'modal-return-box no-print mrb-done';
                    box.innerHTML = `<span class="mrb-label"><i class="fa-solid fa-circle-check"></i> Returned to customer${ARD.issueCode ? ` (${esc(ARD.issueCode)})` : ''}</span>`;
                }
            }
        } else {
            showToast(data.error||'Could not mark as returned','err');
            cb.checked = false;
            cb.disabled = false;
            if(label) label.style.opacity = '';
        }
    }catch(e){
        showToast('Network error: '+e.message,'err');
        cb.checked = false;
        cb.disabled = false;
        if(label) label.style.opacity = '';
    }
}

/* ── apply an issue-status badge to a table row after a status change ── */
function applyIssueStatus(rowEl, itemId, status){
    if(!rowEl) rowEl = document.querySelector(`.return-select-cb[data-item-id="${itemId}"]`)?.closest('tr');
    if(!rowEl) rowEl = document.querySelector(`tr[data-issue-item-id="${itemId}"]`);
    if(!rowEl) return;
    rowEl.dataset.issueStatus = status;
    const code = rowEl.dataset.issueCode || '';
    const cell = rowEl.querySelector('[id^="iss-stat-cell-"]');
    if(!cell) return;
    if(status === 'issued_to_customer'){
        cell.innerHTML = `<span style="display:inline-flex;align-items:center;gap:4px;background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;padding:3px 9px;border-radius:9px;font-size:10px;font-weight:700;white-space:nowrap;">
            <i class="fa-solid fa-user-check"></i> Issued to Customer</span>
            ${code ? `<div style="font-size:9px;color:#6b7280;margin-top:2px;">${esc(code)}</div>` : ''}`;
    } else {
        cell.innerHTML = `<span style="display:inline-flex;align-items:center;gap:4px;background:#dcfce7;color:#166534;border:1px solid #86efac;padding:3px 9px;border-radius:9px;font-size:10px;font-weight:700;white-space:nowrap;">
            <i class="fa-solid fa-rotate-left"></i> Returned</span>
            ${code ? `<div style="font-size:9px;color:#6b7280;margin-top:2px;">${esc(code)}</div>` : ''}`;
    }
}
/* backwards-compatible wrapper */
function applyReturnedIssueStatus(rowEl, itemId){ applyIssueStatus(rowEl, itemId, 'returned'); }

/* ── Auto: whenever a row becomes fully settled while still 'issued',
     the cheque goes back to the customer — status → Issued to Customer ── */
async function autoReturnIfFullySettled(rowEl){
    if(!rowEl) return;
    const itemId = rowEl.dataset.issueItemId;
    const status = rowEl.dataset.issueStatus;
    if(!itemId || status !== 'issued') return;
    window._issMarking = window._issMarking || {};
    if(window._issMarking[itemId]) return;
    window._issMarking[itemId] = 1;
    try{
        const fd = new FormData();
        fd.append('action','mark_issued_to_customer');
        fd.append('item_id', itemId);
        const res = await fetch('save_cheque_issue.php', {method:'POST', body:fd});
        const data = await res.json();
        if(data.success){
            applyIssueStatus(rowEl, itemId, 'issued_to_customer');
            if(ARD && String(ARD.issueItemId) === String(itemId)){
                ARD.issueItemStatus = 'issued_to_customer';
                renderModalReturnBox();
            }
        } else {
            delete window._issMarking[itemId];
            console.error('auto Issued-to-Customer failed for item', itemId, data.error);
        }
    }catch(e){ delete window._issMarking[itemId]; console.error('auto Issued-to-Customer network error', e); }
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

const chqInput=document.getElementById('chqSearch'),chqClr=document.getElementById('chqClr');
if(chqInput){
    chqInput.addEventListener('input',function(){
        currentSearch=this.value.trim();
        chqClr?.classList.toggle('show',currentSearch.length>0);
        clearTimeout(fetchTimer);
        fetchTimer=setTimeout(()=>{currentPage=1;fetchRows();},350);
    });
}
function clearSearch(){if(chqInput)chqInput.value='';chqClr?.classList.remove('show');currentSearch='';currentPage=1;fetchRows();}
document.addEventListener('DOMContentLoaded',()=>fetchRows());
</script>

<script>
/* ══════════════════════════════════════════════════════
   CRN MODAL — Multi-Image Upload
══════════════════════════════════════════════════════ */
let CRN_API_KEY='',CRN_CHEQUE_ID=0,CRN_FILE_PATHS=[],CRN_IMAGE_FILES=[];
let lightboxImages=[],lightboxIndex=0;

(async()=>{
    try{
        const r=await fetch('return_cheques.php?ajax=get_api_key');
        const d=await r.json();
        const banner=document.getElementById('crnApiBanner');
        if(d.has_key&&d.key){
            CRN_API_KEY=d.key;
            banner.className='crn-api-banner crn-api-ok';
            banner.innerHTML='<i class="fa-solid fa-circle-check"></i> Gemini API key loaded — AI scan ready.';
        }else{
            banner.className='crn-api-banner crn-api-warn';
            banner.innerHTML='<i class="fa-solid fa-triangle-exclamation"></i> No Gemini API key. <a href="ai_settings.php" style="color:inherit;font-weight:800;">Configure →</a>';
        }
    }catch(e){}
})();

document.getElementById('crnStatusSelect').addEventListener('change',function(){updateStatusBadge(this.value);});
function updateStatusBadge(status){
    const badge=document.getElementById('crnStatusBigBadge');
    if(!status){badge.className='crn-status-badge-big csb-nonrep';badge.innerHTML='<i class="fa-solid fa-question"></i> —';return;}
    const isRep=(status==='Re-presentable');
    badge.className='crn-status-badge-big '+(isRep?'csb-rep':'csb-nonrep');
    badge.innerHTML=isRep?'<i class="fa-solid fa-rotate-right"></i> Re-presentable':'<i class="fa-solid fa-ban"></i> Non-representable';
}

function switchCrnTab(tab){
    const isView=(tab==='view');
    document.getElementById('crnTabView').style.display    =isView?'':'none';
    document.getElementById('crnTabReplace').style.display =isView?'none':'';
    document.getElementById('crnFooterView').style.display    =isView?'':'none';
    document.getElementById('crnFooterReplace').style.display =isView?'none':'';
    document.getElementById('tabBtnView').classList.toggle('active',isView);
    document.getElementById('tabBtnReplace').classList.toggle('active',!isView);
    if(!isView){
        document.getElementById('crnFileInput').value='';
        document.getElementById('crnFileName').style.display='none';
        document.getElementById('crnFileName').textContent='';
        document.getElementById('crnImgPreviewArea').style.display='none';
        document.getElementById('crnImgPreviewArea').innerHTML='';
        document.getElementById('crnProgBox').classList.remove('show');
        document.getElementById('crnResultCard').classList.remove('show');
        document.getElementById('crnSaveBtn').disabled=true;
        CRN_IMAGE_FILES=[];
    }
}

/* Parse file paths — handles JSON array or single string */
function parseCrnFilePaths(raw){
    if(!raw) return [];
    try{
        const arr=JSON.parse(raw);
        if(Array.isArray(arr)) return arr.filter(Boolean);
    }catch(e){}
    // Legacy: single path string
    if(typeof raw==='string'&&raw.trim()) return [raw.trim()];
    return [];
}

function openCrnModal(chequeId,chequeNo,bankName,existingData){
    CRN_CHEQUE_ID=chequeId;CRN_FILE_PATHS=[];CRN_IMAGE_FILES=[];
    document.getElementById('crnModalSubtitle').textContent='Cheque: '+chequeNo+' — '+bankName;
    const hasCrn=existingData&&existingData.crn_no;
    if(hasCrn){
        const isRep=(existingData.crn_cheque_status==='Re-presentable');
        const viewBanner=document.getElementById('crnViewBanner');
        viewBanner.style.background    =isRep?'linear-gradient(135deg,#f0fdf4,#dcfce7)':'linear-gradient(135deg,#fef2f2,#fee2e2)';
        viewBanner.style.borderColor   =isRep?'#86efac':'#fca5a5';
        const statusBadge=document.getElementById('crnViewStatusBadge');
        statusBadge.className='crn-status-badge-big '+(isRep?'csb-rep':'csb-nonrep');
        statusBadge.innerHTML=isRep?'<i class="fa-solid fa-rotate-right"></i> Re-presentable':'<i class="fa-solid fa-ban"></i> Non-representable';
        const reasonTxt=existingData.crn_return_reason||existingData.crn_return_remark||'—';
        const codeTxt=existingData.crn_return_code?' ('+existingData.crn_return_code+')':'';
        document.getElementById('crnViewReason').textContent =reasonTxt+codeTxt;
        document.getElementById('crnViewNo').textContent     =existingData.crn_no||'—';
        document.getElementById('crnViewBank').textContent   =existingData.crn_collecting_bank||'—';
        document.getElementById('crnViewBranch').textContent =existingData.crn_collecting_branch||'—';
        document.getElementById('crnViewRemark').textContent =existingData.crn_return_remark||'—';
        document.getElementById('crnViewUpBy').textContent   =existingData.crn_uploaded_by||'—';
        document.getElementById('crnViewUpAt').textContent   =existingData.crn_uploaded_at||'—';
        const dv=existingData.crn_date_of_return;
        if(dv&&dv!=='0000-00-00'){
            const dt=new Date(dv+'T00:00:00');
            const mo=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            document.getElementById('crnViewDate').textContent=String(dt.getDate()).padStart(2,'0')+' '+mo[dt.getMonth()]+' '+dt.getFullYear();
        }else{document.getElementById('crnViewDate').textContent='—';}

        // Build image gallery from stored paths
        const filePaths=parseCrnFilePaths(existingData.crn_file_path);
        const gallery=document.getElementById('crnViewGallery');
        gallery.innerHTML='';
        lightboxImages=[];
        if(filePaths.length>0){
            document.getElementById('crnViewFileCount').textContent=filePaths.length+' CRN Image(s)';
            filePaths.forEach((fp,i)=>{
                const isImg=/\.(jpg|jpeg|png|gif|bmp|webp)$/i.test(fp);
                if(isImg){
                    lightboxImages.push(fp);
                    const div=document.createElement('div');div.className='crn-img-thumb';
                    div.onclick=()=>openLightbox(lightboxImages.indexOf(fp));
                    div.innerHTML=`<img src="${esc(fp)}" alt="CRN Image ${i+1}" loading="lazy"><div class="crn-img-label">Image ${i+1} — ${fp.split('/').pop()}</div>`;
                    gallery.appendChild(div);
                } else {
                    // PDF fallback — show link
                    const div=document.createElement('div');div.className='crn-img-thumb';div.style.padding='20px';div.style.textAlign='center';
                    div.innerHTML=`<i class="fa-solid fa-file-pdf" style="font-size:40px;color:#ef4444;"></i><div class="crn-img-label"><a href="${esc(fp)}" target="_blank" style="color:#fff;">Open PDF</a></div>`;
                    gallery.appendChild(div);
                }
            });
        } else {
            gallery.innerHTML='<div style="padding:20px;text-align:center;color:#9ca3af;font-size:12px;">No image files stored.</div>';
            document.getElementById('crnViewFileCount').textContent='No files';
        }

        document.getElementById('crnNoDocState').style.display='none';
        document.getElementById('crnDocView').style.display='';
        document.getElementById('crnRemoveBtn').style.display='';
        switchCrnTab('view');
    }else{
        document.getElementById('crnNoDocState').style.display='';
        document.getElementById('crnDocView').style.display='none';
        document.getElementById('crnRemoveBtn').style.display='none';
        switchCrnTab('view');
    }
    const modal=document.getElementById('crnModal');
    if(modal.parentElement!==document.body) document.body.appendChild(modal);
    modal.classList.add('open');
    document.body.classList.add('crn-modal-open');
}

function closeCrnModal(){
    document.getElementById('crnModal').classList.remove('open');
    document.body.classList.remove('crn-modal-open');
    CRN_CHEQUE_ID=0;CRN_FILE_PATHS=[];CRN_IMAGE_FILES=[];
}
document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){
        if(document.getElementById('crnLightbox').classList.contains('open')){closeLightbox();return;}
        closeCrnModal();
    }
    if(document.getElementById('crnLightbox').classList.contains('open')){
        if(e.key==='ArrowLeft') lightboxNav(-1);
        if(e.key==='ArrowRight') lightboxNav(1);
    }
});
document.getElementById('crnModal').addEventListener('click',function(e){if(e.target===this)closeCrnModal();});

/* Lightbox for image viewing */
function openLightbox(idx){
    if(!lightboxImages.length) return;
    lightboxIndex=idx;
    const lb=document.getElementById('crnLightbox');
    document.getElementById('crnLightboxImg').src=lightboxImages[idx];
    document.getElementById('crnLightboxCounter').textContent=(idx+1)+' / '+lightboxImages.length;
    // Move to body so it's outside the modal stacking context
    if(lb.parentElement !== document.body) document.body.appendChild(lb);
    lb.classList.add('open');
}
function closeLightbox(){document.getElementById('crnLightbox').classList.remove('open');}
function lightboxNav(dir){
    lightboxIndex=(lightboxIndex+dir+lightboxImages.length)%lightboxImages.length;
    document.getElementById('crnLightboxImg').src=lightboxImages[lightboxIndex];
    document.getElementById('crnLightboxCounter').textContent=(lightboxIndex+1)+' / '+lightboxImages.length;
}

async function removeCrnDetails(){
    if(!confirm('Remove all CRN details for this cheque? This cannot be undone.')) return;
    const btn=document.getElementById('crnRemoveBtn');
    btn.disabled=true;btn.innerHTML='<span class="spinner"></span> Removing…';
    try{
        const fd=new FormData();fd.append('ajax_action','remove_crn_details');fd.append('cheque_id',CRN_CHEQUE_ID);
        const res=await fetch('return_cheques.php',{method:'POST',body:fd});
        const d=await res.json();
        if(!d.success){showToast('Remove failed: '+(d.error||'Unknown'),'err');return;}
        const cell=document.getElementById('crn-cell-'+CRN_CHEQUE_ID);
        if(cell) cell.innerHTML=`<span class="crn-status-pill crn-pending"><i class="fa-solid fa-clock"></i> No CRN</span>`;
        const crnBtn=document.getElementById('crn-btn-'+CRN_CHEQUE_ID);
        if(crnBtn){crnBtn.className='btn-crn';crnBtn.innerHTML='<i class="fa-solid fa-file-invoice"></i> CRN';}
        showToast('CRN details removed ✓','ok');
        closeCrnModal();
    }catch(e){showToast('Error: '+e.message,'err');}
    finally{btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-trash"></i> Remove CRN';}
}

/* Handle drag & drop */
function crnHandleDrop(e){
    e.preventDefault();
    document.getElementById('crnDropZone').classList.remove('over');
    const files=Array.from(e.dataTransfer.files).filter(f=>f.type.startsWith('image/')||f.type==='application/pdf');
    if(files.length) crnHandleFiles(files);
    else showToast('Please drop image files (JPG, PNG).','err');
}

/* Handle multiple file selection */
function crnHandleFiles(fileList){
    if(!fileList||!fileList.length) return;
    const validFiles=Array.from(fileList).filter(f=>f.type.startsWith('image/')||f.name.toLowerCase().match(/\.(jpg|jpeg|png|gif|bmp|webp)$/));
    if(!validFiles.length){showToast('Only image files (JPG, PNG, etc.) are allowed.','err');return;}
    CRN_IMAGE_FILES=validFiles;
    // Show preview thumbnails
    const previewArea=document.getElementById('crnImgPreviewArea');
    previewArea.innerHTML='';previewArea.style.display='flex';
    const fn=document.getElementById('crnFileName');
    fn.textContent='📷 '+validFiles.length+' image(s) selected ('+validFiles.map(f=>f.name).join(', ')+')';fn.style.display='block';
    validFiles.forEach((file,i)=>{
        const reader=new FileReader();
        reader.onload=function(e){
            const div=document.createElement('div');div.className='crn-img-preview-item';
            div.innerHTML=`<img src="${e.target.result}" alt="${esc(file.name)}" title="${esc(file.name)}">
                <button class="crn-pip-remove" onclick="removePendingImage(${i})" title="Remove"><i class="fa-solid fa-xmark"></i></button>`;
            previewArea.appendChild(div);
        };
        reader.readAsDataURL(file);
    });
    // Auto start upload & scan
    crnUploadAndScan();
}

function removePendingImage(idx){
    CRN_IMAGE_FILES.splice(idx,1);
    if(!CRN_IMAGE_FILES.length){
        document.getElementById('crnImgPreviewArea').style.display='none';
        document.getElementById('crnImgPreviewArea').innerHTML='';
        document.getElementById('crnFileName').style.display='none';
        document.getElementById('crnSaveBtn').disabled=true;
        return;
    }
    // Rebuild preview
    const previewArea=document.getElementById('crnImgPreviewArea');
    previewArea.innerHTML='';
    CRN_IMAGE_FILES.forEach((file,i)=>{
        const reader=new FileReader();
        reader.onload=function(e){
            const div=document.createElement('div');div.className='crn-img-preview-item';
            div.innerHTML=`<img src="${e.target.result}" alt="${esc(file.name)}">
                <button class="crn-pip-remove" onclick="removePendingImage(${i})" title="Remove"><i class="fa-solid fa-xmark"></i></button>`;
            previewArea.appendChild(div);
        };
        reader.readAsDataURL(file);
    });
    const fn=document.getElementById('crnFileName');
    fn.textContent='📷 '+CRN_IMAGE_FILES.length+' image(s) selected';
}

async function crnUploadAndScan(){
    if(!CRN_IMAGE_FILES.length||!CRN_CHEQUE_ID){showToast('No files or cheque selected.','err');return;}
    const prog=document.getElementById('crnProgBox'),fill=document.getElementById('crnProgFill');
    const label=document.getElementById('crnProgLabel'),pct=document.getElementById('crnProgPct'),pfile=document.getElementById('crnProgFile');
    const resultCard=document.getElementById('crnResultCard');
    resultCard.classList.remove('show');document.getElementById('crnSaveBtn').disabled=true;
    prog.classList.add('show');label.textContent='Step 1/2 — Uploading '+CRN_IMAGE_FILES.length+' image(s)…';fill.style.width='10%';pct.textContent='10%';pfile.textContent=CRN_IMAGE_FILES.map(f=>f.name).join(', ');

    // Upload all images
    const fd=new FormData();fd.append('ajax_action','upload_crn_images');fd.append('cheque_id',CRN_CHEQUE_ID);
    CRN_IMAGE_FILES.forEach(f=>fd.append('crn_files[]',f,f.name));
    try{
        fill.style.width='30%';pct.textContent='30%';
        const r=await fetch('return_cheques.php',{method:'POST',body:fd});
        const d=await r.json();
        if(!d.success){showToast('Upload failed: '+(d.error||'Unknown'),'err');prog.classList.remove('show');return;}
        CRN_FILE_PATHS=d.file_paths||[];
        fill.style.width='50%';pct.textContent='50%';
        label.textContent='Step 2/2 — AI scanning '+CRN_IMAGE_FILES.length+' image(s)…';pfile.textContent='Sending to Gemini AI…';
    }catch(e){showToast('Upload error: '+e.message,'err');prog.classList.remove('show');return;}

    if(!CRN_API_KEY){
        showToast('No Gemini API key — images uploaded but AI scan skipped.','err');
        prog.classList.remove('show');
        resultCard.classList.add('show');document.getElementById('crnSaveBtn').disabled=false;
        return;
    }

    // Convert images to base64 for Gemini
    try{
        const imagePages=[];
        for(const file of CRN_IMAGE_FILES){
            const b64=await fileToBase64(file);
            imagePages.push({b64,mime:file.type||'image/jpeg'});
        }
        fill.style.width='70%';pct.textContent='70%';pfile.textContent='Scanning '+imagePages.length+' image(s)…';
        const scanResult=await scanCrnWithGemini(imagePages);
        fill.style.width='100%';pct.textContent='100%';label.textContent='✓ Scan complete!';
        document.getElementById('crnResNo').value     =scanResult.crn_no||'';
        document.getElementById('crnResReason').value =scanResult.return_reason||'';
        document.getElementById('crnResCode').value   =scanResult.return_code||'';
        document.getElementById('crnResBank').value   =scanResult.collecting_bank||'';
        document.getElementById('crnResBranch').value =scanResult.collecting_branch||'';
        document.getElementById('crnResRemark').value =scanResult.return_remark||'';
        if(scanResult.date_of_return){const dp=parseDateStr(scanResult.date_of_return);if(dp)document.getElementById('crnResDate').value=dp;}
        const selStatus=document.getElementById('crnStatusSelect');
        const statusRaw=(scanResult.cheque_status||'').trim(),remarkRaw=(scanResult.return_remark||'').trim();
        const checkStr=(statusRaw+' '+remarkRaw).toLowerCase();
        let resolvedStatus='';
        if(statusRaw==='Re-presentable'||statusRaw==='Non-representable'){resolvedStatus=statusRaw;}
        else if(checkStr.includes('non')&&checkStr.includes('representable')){resolvedStatus='Non-representable';}
        else if(checkStr.includes('representable')){resolvedStatus='Re-presentable';}
        selStatus.value=resolvedStatus;updateStatusBadge(resolvedStatus);
        if(!scanResult.return_code&&remarkRaw){const cm=remarkRaw.match(/\((\d+)\)/);if(cm)document.getElementById('crnResCode').value=cm[1];}
        if(!scanResult.return_reason&&remarkRaw){document.getElementById('crnResReason').value=remarkRaw.replace(/\s*\(\d+\)\s*/g,'').trim();}
        resultCard.classList.add('show');document.getElementById('crnSaveBtn').disabled=false;
        showToast('AI scan complete — review and save.','ok');
    }catch(e){showToast('AI scan error: '+e.message,'err');console.error(e);resultCard.classList.add('show');document.getElementById('crnSaveBtn').disabled=false;}
    setTimeout(()=>prog.classList.remove('show'),1500);
}

/* Convert File to base64 string */
function fileToBase64(file){
    return new Promise((resolve,reject)=>{
        const reader=new FileReader();
        reader.onload=()=>resolve(reader.result.split(',')[1]);
        reader.onerror=()=>reject(new Error('Failed to read file'));
        reader.readAsDataURL(file);
    });
}

async function scanCrnWithGemini(imagePages){
    const prompt=['This is a Sri Lankan bank Cheque Return Notification (CRN) document. Multiple images may show different parts of the same CRN.','','Extract ONLY these fields. Respond with ONLY valid JSON on ONE line, no markdown, no explanation:','{"crn_no":"","return_reason":"","return_code":"","cheque_status":"","return_remark":"","collecting_bank":"","collecting_branch":"","date_of_return":""}','','FIELD-BY-FIELD RULES:','','crn_no:','  - The CRN REFERENCE NUMBER in the PART-B or PART-C sections, typically in the top-right corner.','  - Format: bank code (4 digits) then spaces then more digits. Example: "7214 00000079".','  - It may be in a box labeled "PART-B Bank\'s Copy" at the top right.','  - Use the FULL number exactly as printed including spaces.','  - Do NOT use the UI Number (starts with 2026...) — that is NOT the CRN number.','','cheque_status:','  - Find the box/table labeled "Cheque Status & Return Remark". It has TWO lines inside.','  - The FIRST line is the presentability status — it will be EXACTLY one of these two values:','      Re-presentable','      Non-representable','  - Output cheque_status as EXACTLY "Re-presentable" or "Non-representable" — no other text.','','return_remark:','  - The SECOND line inside the "Cheque Status & Return Remark" box.','  - Example: "Post-dated 1" or "Refer to drawer(01)".','','return_reason:','  - Text labeled "Return Reason/Code" or "Return Reason (Code)" in the clearing information section.','  - Extract just the reason TEXT without the code number. Example: "Refer to drawer".','  - If not separately labeled, derive from return_remark by removing the code in brackets.','','return_code:','  - The NUMBER in parentheses after the return reason. Example: "01" from "Refer to drawer(01)".','  - Also check return_remark for a code in brackets. Output ONLY the digits.','','collecting_bank: Text after "Collecting Bank:" label.','collecting_branch: Text after "Collecting Branch" label.','date_of_return: Text after "Date of Return". Format as YYYY-MM-DD.','','Respond ONLY with the JSON object, single line, no extra text.',].join('\n');

    const parts=imagePages.map(p=>({inline_data:{mime_type:p.mime,data:p.b64}}));
    parts.push({text:prompt});
    const res=await fetch('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',{method:'POST',headers:{'Content-Type':'application/json','x-goog-api-key':CRN_API_KEY},body:JSON.stringify({contents:[{parts}],generationConfig:{maxOutputTokens:300,temperature:0,thinkingConfig:{thinkingBudget:0}}})});
    if(res.status===429){await new Promise(r=>setTimeout(r,3500));return scanCrnWithGemini(imagePages);}
    if(!res.ok){const e=await res.json().catch(()=>({}));throw new Error(e?.error?.message||'HTTP '+res.status);}
    const data=await res.json();
    const raw=(data?.candidates?.[0]?.content?.parts?.[0]?.text||'').trim();
    try{return JSON.parse(raw.replace(/```json|```/g,'').trim());}
    catch(e){
        console.warn('Gemini raw response:',raw);
        const extract=(key)=>{const m=raw.match(new RegExp('"'+key+'"\\s*:\\s*"([^"]*)"'));return m?m[1]:'';};
        return {crn_no:extract('crn_no'),return_reason:extract('return_reason'),return_code:extract('return_code'),cheque_status:extract('cheque_status'),return_remark:extract('return_remark'),collecting_bank:extract('collecting_bank'),collecting_branch:extract('collecting_branch'),date_of_return:extract('date_of_return')};
    }
}

function parseDateStr(s){
    if(!s) return '';
    if(/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
    const months={jan:'01',feb:'02',mar:'03',apr:'04',may:'05',jun:'06',jul:'07',aug:'08',sep:'09',oct:'10',nov:'11',dec:'12'};
    const m=s.match(/(\d{1,2})[-\/\s]([A-Za-z]{3})[-\/\s](\d{4})/);
    if(m) return m[3]+'-'+(months[m[2].toLowerCase()]||'01')+'-'+String(m[1]).padStart(2,'0');
    const d=new Date(s);if(!isNaN(d)) return d.toISOString().slice(0,10);return '';
}

async function saveCrnDetails(){
    const saveBtn=document.getElementById('crnSaveBtn');
    const crn_no=document.getElementById('crnResNo').value.trim();
    const return_reason=document.getElementById('crnResReason').value.trim();
    const return_code=document.getElementById('crnResCode').value.trim();
    const cheque_status=document.getElementById('crnStatusSelect').value;
    const return_remark=document.getElementById('crnResRemark').value.trim();
    const collecting_bank=document.getElementById('crnResBank').value.trim();
    const collecting_branch=document.getElementById('crnResBranch').value.trim();
    const date_of_return=document.getElementById('crnResDate').value;
    if(!crn_no){showToast('CRN Number is required.','err');return;}
    if(!cheque_status){showToast('Please select Cheque Status.','err');return;}
    saveBtn.disabled=true;saveBtn.innerHTML='<span class="spinner"></span> Saving…';

    // Store file paths as JSON array
    const filePathJson=JSON.stringify(CRN_FILE_PATHS);

    const fd=new FormData();
    fd.append('ajax_action','save_crn_details');fd.append('cheque_id',CRN_CHEQUE_ID);
    fd.append('crn_no',crn_no);fd.append('return_reason',return_reason);fd.append('return_code',return_code);
    fd.append('cheque_status',cheque_status);fd.append('return_remark',return_remark);
    fd.append('collecting_bank',collecting_bank);fd.append('collecting_branch',collecting_branch);
    fd.append('date_of_return',date_of_return);fd.append('crn_file_path',filePathJson);
    try{
        const r=await fetch('return_cheques.php',{method:'POST',body:fd});
        const d=await r.json();
        if(!d.success){showToast('Save failed: '+(d.error||'Unknown'),'err');return;}
        updateCrnCell(CRN_CHEQUE_ID,crn_no,d.is_representable,return_code,return_reason,cheque_status);
        showToast('CRN details saved ✓','ok');
        const now=new Date(),nowStr=now.toLocaleString('en-GB');
        document.getElementById('crnNoDocState').style.display='none';document.getElementById('crnDocView').style.display='';document.getElementById('crnRemoveBtn').style.display='';
        const isRep=(cheque_status==='Re-presentable');
        const viewBanner=document.getElementById('crnViewBanner');
        viewBanner.style.background=isRep?'linear-gradient(135deg,#f0fdf4,#dcfce7)':'linear-gradient(135deg,#fef2f2,#fee2e2)';
        viewBanner.style.borderColor=isRep?'#86efac':'#fca5a5';
        const statusBadge=document.getElementById('crnViewStatusBadge');
        statusBadge.className='crn-status-badge-big '+(isRep?'csb-rep':'csb-nonrep');
        statusBadge.innerHTML=isRep?'<i class="fa-solid fa-rotate-right"></i> Re-presentable':'<i class="fa-solid fa-ban"></i> Non-representable';
        const codeTxt=return_code?' ('+return_code+')':'';
        document.getElementById('crnViewReason').textContent=(return_reason||return_remark||'—')+codeTxt;
        document.getElementById('crnViewNo').textContent=crn_no;
        document.getElementById('crnViewBank').textContent=collecting_bank||'—';
        document.getElementById('crnViewBranch').textContent=collecting_branch||'—';
        document.getElementById('crnViewRemark').textContent=return_remark||'—';
        document.getElementById('crnViewUpBy').textContent='You (just now)';
        document.getElementById('crnViewUpAt').textContent=nowStr;
        if(date_of_return){const dt=new Date(date_of_return+'T00:00:00'),mo=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];document.getElementById('crnViewDate').textContent=String(dt.getDate()).padStart(2,'0')+' '+mo[dt.getMonth()]+' '+dt.getFullYear();}

        // Rebuild gallery with new images
        const gallery=document.getElementById('crnViewGallery');
        gallery.innerHTML='';lightboxImages=[];
        if(CRN_FILE_PATHS.length>0){
            document.getElementById('crnViewFileCount').textContent=CRN_FILE_PATHS.length+' CRN Image(s)';
            CRN_FILE_PATHS.forEach((fp,i)=>{
                lightboxImages.push(fp);
                const div=document.createElement('div');div.className='crn-img-thumb';
                div.onclick=()=>openLightbox(i);
                div.innerHTML=`<img src="${esc(fp)}" alt="CRN Image ${i+1}" loading="lazy"><div class="crn-img-label">Image ${i+1} — ${fp.split('/').pop()}</div>`;
                gallery.appendChild(div);
            });
        }
        switchCrnTab('view');
    }catch(e){showToast('Error: '+e.message,'err');}
    finally{saveBtn.disabled=false;saveBtn.innerHTML='<i class="fa-solid fa-cloud-arrow-up"></i> Update & Save CRN';}
}

function updateCrnCell(chequeId,crnNo,isRep,code,reason,status){
    const cell=document.getElementById('crn-cell-'+chequeId),btn=document.getElementById('crn-btn-'+chequeId);
    if(cell){
        const repClass=isRep===1?'crn-rep':'crn-nonrep',repIcon=isRep===1?'fa-rotate-right':'fa-ban',repLabel=isRep===1?'Re-presentable':'Non-representable';
        cell.innerHTML=`<div class="crn-badge-cell"><span class="crn-status-pill ${repClass}"><i class="fa-solid ${repIcon}"></i> ${repLabel}</span><div class="crn-no-txt">${esc(crnNo)}</div>${code?`<div style="font-size:10px;color:#64748b;">Code: ${esc(code)} — ${esc(reason||'')}</div>`:''}</div>`;
    }
    if(btn){btn.className='btn-crn has-crn';btn.innerHTML='<i class="fa-solid fa-file-invoice"></i> CRN ✓';}
}
</script>

<script>
/* ══ Settlement Modal ══ */
let ARD={},chequeCounter=0;
const dupCache={};

async function openSettleModal(chequeId){
    try{
        const res=await fetch(`return_cheques.php?ajax=return_cheque_detail&cheque_id=${encodeURIComponent(chequeId)}`);
        const data=await res.json();
        if(!data.success){showToast(data.error||'Could not load cheque details','err');return;}
        const ch=data.cheque;
        ARD={chequeId,id:ch.field_summary_detail_id,fsid:ch.field_summary_id,tcode:ch.t_code,invoice:ch.invoice_num,customer:ch.customer_name,
             adjust:parseFloat(ch.invoice_amt||0),paid:parseFloat(ch.total_paid||0),balance:parseFloat(ch.balance||0),
             payMode:ch.payment_mode||'',creditLimit:ch.credit_limit||'',creditDays:ch.credit_days||'',
             specialDays:ch.special_credit_policy_days||'',delivDate:(ch.delivery_date||new Date().toISOString()).substring(0,10),
             chequeNo:ch.cheque_no,chequeDate:ch.cheque_date,chequeAmt:parseFloat(ch.total_amount||0),bankName:ch.bank_name||ch.bank_code||'—',
             issueItemId:ch.issue_item_id||'',issueItemStatus:ch.issue_item_status||'',issueCode:ch.issue_code||'',
             returnSettled:parseInt(ch.return_settled||0)};
        document.getElementById('modalSubtitle').textContent='Invoice: '+ARD.invoice+' | '+ARD.customer;
        document.getElementById('hdrInv').textContent='Rs. '+ARD.adjust.toFixed(2);
        document.getElementById('hdrPaid').textContent='Rs. '+ARD.paid.toFixed(2);
        document.getElementById('hdrBal').textContent='Rs. '+ARD.balance.toFixed(2);
        document.getElementById('hdrReturned').textContent='Rs. '+ARD.chequeAmt.toFixed(2);
        document.getElementById('bnrChequeNo').textContent=ARD.chequeNo||'—';
        document.getElementById('bnrChequeDate').textContent=ARD.chequeDate||'—';
        document.getElementById('bnrBank').textContent=ARD.bankName;
        document.getElementById('bnrAmt').textContent='Rs. '+ARD.chequeAmt.toFixed(2);
        renderModalReturnBox();
        document.getElementById('cashDate').value=ARD.delivDate;
        document.getElementById('chqPayDate').value=ARD.delivDate;
        ['cashAmount','cashToBank','cashRef','cashRemarks','chqRef','chqRemarks','settlementNote'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
        document.getElementById('cashCollectedBy').value='cc';
        document.getElementById('chqModeSelect').value='payee_only';
        setCI('chqLimit',ARD.creditLimit?'Rs. '+parseFloat(ARD.creditLimit).toLocaleString():'—');
        setCI('chqDays',ARD.creditDays||'—');setCI('chqSpecial',ARD.specialDays||'—');
        document.getElementById('chequesContainer').innerHTML='';chequeCounter=0;addCheque();syncPreviews();
        const modal=document.getElementById('settleModal');
        if(modal.parentElement!==document.body) document.body.appendChild(modal);
        _sCollectorType = 'cc';
sSetCollectorType('cc');
sLoadCollectorPersons();
        modal.classList.add('open');document.body.classList.add('modal-settle-open');
        loadSettlementPayments();
    }catch(e){showToast('Error: '+e.message,'err');}
}

function closeSettleModal(){
    document.getElementById('settleModal').classList.remove('open');
    document.body.classList.remove('modal-settle-open');
    ARD={};
}
document.getElementById('settleModal').addEventListener('click',function(e){if(e.target===this)closeSettleModal();});
function setCI(id,v){const el=document.getElementById(id);if(el)el.textContent=v||'—';}

/* ═══════════════════════════════════════════════
   RETURN BOX inside the settle/pay modal
   - no issue record                 → hidden
   - status issued_to_customer       → green "Issued to Customer" chip
   - status returned                 → green "Returned" chip
   - issued + fully settled          → auto → Issued to Customer
   - issued + partial/unsettled      → REQUIRED "Return" checkbox;
                                       payment cannot be saved unless ticked
═══════════════════════════════════════════════ */
function renderModalReturnBox(){
    const box = document.getElementById('modalReturnBox');
    if(!box) return;
    if(!ARD.issueItemId || !ARD.issueItemStatus){
        box.style.display = 'none';
        box.className = 'modal-return-box no-print';
        box.innerHTML = '';
        return;
    }
    box.style.display = 'flex';
    if(ARD.issueItemStatus === 'issued_to_customer'){
        box.className = 'modal-return-box no-print mrb-done';
        box.innerHTML = `<span class="mrb-label"><i class="fa-solid fa-user-check"></i> Issued to Customer${ARD.issueCode ? ` (${esc(ARD.issueCode)})` : ''}</span>`;
        return;
    }
    if(ARD.issueItemStatus === 'returned'){
        box.className = 'modal-return-box no-print mrb-done';
        box.innerHTML = `<span class="mrb-label"><i class="fa-solid fa-rotate-left"></i> Returned${ARD.issueCode ? ` (${esc(ARD.issueCode)})` : ''}</span>`;
        return;
    }
    /* status === 'issued' */
    if(ARD.returnSettled){
        box.className = 'modal-return-box no-print mrb-auto';
        box.innerHTML = `<span class="mrb-label"><i class="fa-solid fa-rotate"></i> Fully settled — updating status to Issued to Customer…</span>`;
        modalAutoIssueToCustomer();
    } else {
        box.className = 'modal-return-box no-print mrb-issued';
        box.innerHTML = `
            <span class="mrb-label"><i class="fa-solid fa-paper-plane"></i> Issued${ARD.issueCode ? ` (${esc(ARD.issueCode)})` : ''} — for a part payment you must tick <u>Return</u></span>
            <label class="mrb-check-label" id="modalReturnCbLabel">
                <input type="checkbox" id="modalReturnCb" data-item-id="${ARD.issueItemId}" onchange="toggleModalReturnSelect(this)">
                Return <span style="color:#dc2626;font-weight:800;">*</span>
            </label>`;
    }
}

async function modalAutoIssueToCustomer(){
    if(!ARD.issueItemId || ARD.issueItemStatus !== 'issued') return;
    window._issMarking = window._issMarking || {};
    if(window._issMarking[ARD.issueItemId]) return;
    window._issMarking[ARD.issueItemId] = 1;
    try{
        const fd = new FormData();
        fd.append('action','mark_issued_to_customer');
        fd.append('item_id', ARD.issueItemId);
        const res = await fetch('save_cheque_issue.php', {method:'POST', body:fd});
        const data = await res.json();
        if(data.success){
            ARD.issueItemStatus = 'issued_to_customer';
            const box = document.getElementById('modalReturnBox');
            if(box){
                box.className = 'modal-return-box no-print mrb-done';
                box.innerHTML = `<span class="mrb-label"><i class="fa-solid fa-user-check"></i> Issued to Customer${ARD.issueCode ? ` (${esc(ARD.issueCode)})` : ''}</span>`;
            }
            applyIssueStatus(document.getElementById('row-'+ARD.chequeId), ARD.issueItemId, 'issued_to_customer');
        } else {
            delete window._issMarking[ARD.issueItemId];
            console.error('modalAutoIssueToCustomer failed', data.error);
            const box = document.getElementById('modalReturnBox');
            if(box){
                box.className = 'modal-return-box no-print mrb-issued';
                box.innerHTML = `<span class="mrb-label" style="color:#b91c1c;"><i class="fa-solid fa-triangle-exclamation"></i> Status update failed: ${esc(data.error||'unknown error')}</span>`;
            }
        }
    }catch(e){
        delete window._issMarking[ARD.issueItemId];
        console.error('modalAutoIssueToCustomer network error', e);
    }
}

/* ── modal Return checkbox — only records intent; the actual status
     change happens when the payment is SAVED (submitSettlement).
     Part payment cannot be saved unless this box is ticked. ── */
function toggleModalReturnSelect(cb){
    const label = document.getElementById('modalReturnCbLabel');
    if(label){
        label.classList.toggle('checked', cb.checked);
        label.style.outline = '';
    }
}

/* kept for reference — old immediate-save behaviour (now unused) */
async function _legacyToggleModalReturnSelect(cb){
    const itemId = cb.dataset.itemId;
    if(!itemId){
        showToast('No issue reference found for this cheque — cannot mark returned.','err');
        cb.checked = false;
        return;
    }
    if(!cb.checked) return; /* unchecking does nothing — return is a one-way action */

    if(!confirm('Mark this cheque as returned to Company?')){
        cb.checked = false;
        return;
    }
    cb.disabled = true;
    const label = document.getElementById('modalReturnCbLabel');
    if(label) label.style.opacity = '0.6';

    try{
        const fd = new FormData();
        fd.append('action','mark_returned');
        fd.append('item_id', itemId);
        const res = await fetch('save_cheque_issue.php', {method:'POST', body:fd});
        let data;
        try{
            data = await res.json();
        }catch(parseErr){
            showToast('Server did not return a valid response (HTTP '+res.status+'). Check save_cheque_issue.php for errors.','err');
            cb.checked = false; cb.disabled = false; if(label) label.style.opacity = '';
            return;
        }
        if(data.success){
            showToast('Cheque marked as returned ✓','ok');
            ARD.issueItemStatus = 'returned';
            const box = document.getElementById('modalReturnBox');
            if(box){
                box.className = 'modal-return-box no-print mrb-done';
                box.innerHTML = `<span class="mrb-label"><i class="fa-solid fa-circle-check"></i> Returned to customer${ARD.issueCode ? ` (${esc(ARD.issueCode)})` : ''}</span>`;
            }
            applyReturnedIssueStatus(document.getElementById('row-'+ARD.chequeId), itemId);
        } else {
            showToast(data.error||'Could not mark as returned','err');
            cb.checked = false;
            cb.disabled = false;
            if(label) label.style.opacity = '';
        }
    }catch(e){
        showToast('Network error: '+e.message,'err');
        cb.checked = false;
        cb.disabled = false;
        if(label) label.style.opacity = '';
    }
}



//new ad by supun

/* ── Settle modal collector state ── */
let _sCollectorType = 'cc';
let _sPersonsLoaded = false;

function sSetCollectorType(type) {
    _sCollectorType = type;
    document.getElementById('sDpWrap').style.display  = type === 'cc' ? '' : 'none';
    document.getElementById('sSrWrap').style.display  = type === 'sr' ? '' : 'none';
    document.getElementById('sCtypeCC').className = 'ctype-btn' + (type === 'cc' ? ' active-cc' : '');
    document.getElementById('sCtypeSR').className = 'ctype-btn' + (type === 'sr' ? ' active-sr' : '');
}

async function sLoadCollectorPersons() {
    if (_sPersonsLoaded) return;
    // Load delivery persons
    const dpSt = document.getElementById('sDpStatus');
    dpSt.textContent = 'Loading…'; dpSt.className = 'dp-modal-status';
    try {
        const r = await fetch('get_delivery_persons.php');
        const d = await r.json();
        const dpSel = document.getElementById('sDpSelect');
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
        if (window.$) $('#sDpSelect').select2({ placeholder: '-- Select Delivery Person --', allowClear: true, width: '100%', dropdownParent: $('#settleModal') });
    } catch(e) {
        dpSt.textContent = 'Error loading'; dpSt.className = 'dp-modal-status err';
    }
    // Load SR codes from issue_persons endpoint
    try {
        const r2 = await fetch('return_cheques.php?ajax=issue_persons');
        const d2 = await r2.json();
        if (d2.success) {
            const srSel  = document.getElementById('sSrSelect');
            const empSel = document.getElementById('sEmpSelect');
            const empSel2= document.getElementById('sSrEmpSelect');
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
                $('#sSrSelect').select2({ placeholder: '-- Select SR Code --', allowClear: true, width: '100%', dropdownParent: parent });
                $('#sEmpSelect').select2({ placeholder: '-- Select Employee --', allowClear: true, width: '100%', dropdownParent: parent });
                $('#sSrEmpSelect').select2({ placeholder: '-- Select Employee --', allowClear: true, width: '100%', dropdownParent: parent });
            }
        }
    } catch(e) {}
    _sPersonsLoaded = true;
}

function sGetCollectorValues() {
    if (_sCollectorType === 'cc') {
        return {
            collected_by:    'cc',
            delivery_person: (window.$ ? $('#sDpSelect').val() : document.getElementById('sDpSelect').value) || '',
            sr_code:         '',
            employee_id:     (window.$ ? $('#sEmpSelect').val() : document.getElementById('sEmpSelect').value) || '',
        };
    } else {
        return {
            collected_by:    'sr',
            delivery_person: '',
            sr_code:         (window.$ ? $('#sSrSelect').val() : document.getElementById('sSrSelect').value) || '',
            employee_id:     (window.$ ? $('#sSrEmpSelect').val() : document.getElementById('sSrEmpSelect').value) || '',
        };
    }
}


//end ad by supun

function syncPreviews(){
    const remaining=parseFloat(ARD.balance)||0;
    const cashAmt=Math.max(0,parseFloat(document.getElementById('cashAmount').value)||0);
    let chqTotal=0;
    document.querySelectorAll('#chequesContainer .chq-amt').forEach(i=>{chqTotal+=parseFloat(i.value)||0;});
    const totalPaying=cashAmt+chqTotal;
    const newBal=Math.max(0,remaining-totalPaying);
    document.getElementById('sumCash').textContent='Rs. '+cashAmt.toFixed(2);
    document.getElementById('sumCheque').textContent='Rs. '+chqTotal.toFixed(2);
    document.getElementById('sumTotal').textContent='Rs. '+totalPaying.toFixed(2);
    document.getElementById('sumBalance').textContent='Rs. '+newBal.toFixed(2);
    document.getElementById('hdrBal').textContent='Rs. '+newBal.toFixed(2);
}

function addCheque(){
    chequeCounter++;const idx=chequeCounter;
    let bankOpts='<option value="">— Select Bank —</option>';
    BANKS.forEach(b=>{bankOpts+=`<option value="${b.bank_code}" data-name="${b.bank_name}">${b.bank_code} – ${b.bank_name}</option>`;});
    const card=document.createElement('div');card.className='cheque-card';card.id='cheque-'+idx;
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
    $(`#chq-bank-${idx}`).select2({width:'100%',dropdownParent:$('#settleModal')}).on('change',function(){loadBranches(this.value,idx);});
    $(`#chq-branch-${idx}`).select2({width:'100%',dropdownParent:$('#settleModal')});
}

function loadBranches(bankCode,idx){
    const sel=document.getElementById('chq-branch-'+idx);sel.innerHTML='<option value="">Loading…</option>';$(sel).select2('destroy');
    if(!bankCode){sel.innerHTML='<option value="">— Select Branch —</option>';$(sel).select2({width:'100%',dropdownParent:$('#settleModal')});return;}
    fetch('get_bank_branches.php?bank_code='+encodeURIComponent(bankCode))
        .then(r=>r.json())
        .then(data=>{let opts='<option value="">— Select Branch —</option>';data.forEach(b=>{opts+=`<option value="${b.branch_code}" data-name="${b.branch_name}">${b.branch_code} – ${b.branch_name}</option>`;});sel.innerHTML=opts;$(sel).select2({width:'100%',dropdownParent:$('#settleModal')});})
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
        .then(data=>{dupCache[no]=data.exists?data.total_amount:null;showDupWarning(warn,no,dupCache[no],amt);})
        .catch(()=>{});
}
function showDupWarning(warn,no,existingTotal,newAmt){
    if(existingTotal!==null&&existingTotal!==undefined){
        const newTotal=(parseFloat(existingTotal)||0)+(parseFloat(newAmt)||0);
        warn.innerHTML=`<i class="fa-solid fa-triangle-exclamation"></i> Cheque #${no} already exists (Rs. ${parseFloat(existingTotal).toFixed(2)}). Total will be Rs. ${newTotal.toFixed(2)}.`;
        warn.classList.add('show');
    }else{warn.classList.remove('show');warn.innerHTML='';}
}

async function loadSettlementPayments(){
    const wrap=document.getElementById('spmTableWrap');
    if(!wrap||!ARD.chequeId) return;
    wrap.innerHTML='<div class="spm-empty"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    try{
        const res=await fetch(`return_cheques.php?ajax=settlement_payments&cheque_id=${ARD.chequeId}`);
        const data=await res.json();
        ARD.settledTotal=parseFloat(data.total_settled||0);
        renderSettlementPayments(data.payments||[],data.total_settled||0);
    }catch(e){wrap.innerHTML='<div class="spm-empty" style="color:#dc2626;">Error loading payments</div>';}
}

function renderSettlementPayments(payments,totalSettled){
    const wrap=document.getElementById('spmTableWrap');
    const badge=document.getElementById('spmTotalBadge');
    if(badge) badge.textContent=totalSettled>0?'Total: Rs. '+parseFloat(totalSettled).toFixed(2):'';
    if(!payments.length){wrap.innerHTML='<div class="spm-empty"><i class="fa-solid fa-circle-info"></i> No payments recorded yet.</div>';return;}
    let rows='';
    payments.forEach(p=>{
        const isCash  = p.payment_method==='cash';
        const isCheque= p.payment_method==='cheque';
        const mc = isCash?'spm-method-cash':isCheque?'spm-method-cheque':'spm-method-other';

        let detailCell='';
        if(isCheque){
            const bankDisplay=[p.bank_code,p.bank_name].filter(Boolean).join(' · ')||'—';
            detailCell = p.cheque_no ? `
            <div class="rchq-card">
                <div class="rchq-no"><i class="fa-solid fa-money-check"></i>${esc(p.cheque_no)}</div>
                <div class="rchq-grid">
                    <div class="rchq-row"><span class="rchq-lbl"><i class="fa-solid fa-building-columns"></i> Bank</span><span class="rchq-val">${esc(bankDisplay)}</span></div>
                    <div class="rchq-row"><span class="rchq-lbl"><i class="fa-solid fa-code-branch"></i> Branch</span><span class="rchq-val">${esc(p.branch_name||'—')}</span></div>
                    <div class="rchq-row"><span class="rchq-lbl"><i class="fa-regular fa-calendar"></i> Cheque Date</span><span class="rchq-val">${esc(p.cheque_date||'—')}</span></div>
                    <div class="rchq-row"><span class="rchq-lbl"><i class="fa-solid fa-coins"></i> Amount</span><span class="rchq-val" style="color:#1e40af;font-weight:800;">Rs. ${parseFloat(p.amount).toFixed(2)}</span></div>
                </div>
            </div>`
            : `<span style="font-size:11px;color:#6b7280;font-style:italic;">Cheque — no details saved</span>`;
        } else if(isCash){
            detailCell=`<span style="font-size:11px;color:#16a34a;font-weight:600;display:flex;align-items:center;gap:4px;"><i class="fa-solid fa-coins"></i> Cash Payment</span>`;
        } else {
            detailCell=`<span style="font-size:11px;color:#6b7280;">—</span>`;
        }

        rows+=`<tr>
            <td>${esc(p.payment_date||'—')}</td>
            <td><span class="${mc}">${esc(p.payment_method)}</span></td>
            <td style="font-weight:700;color:#166534;white-space:nowrap;">Rs. ${parseFloat(p.amount).toFixed(2)}</td>
            <td>${detailCell}</td>
            <td style="font-size:11px;">${esc(p.reference_no||'—')}</td>
            <td style="font-size:11px;">${esc(p.remarks||'—')}</td>
            <td><button class="btn-del-pay" onclick="deleteSettlementPayment(${p.id})"><i class="fa-solid fa-trash"></i></button></td>
        </tr>`;
    });
    const total=payments.reduce((s,p)=>s+parseFloat(p.amount),0);
    ARD.settledTotal=total;
    wrap.innerHTML=`<table class="spm-table">
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
    document.getElementById('hdrPaid').textContent='Rs. '+total.toFixed(2);
    document.getElementById('hdrBal').textContent='Rs. '+Math.max(0,ARD.chequeAmt-total).toFixed(2);
}

async function deleteSettlementPayment(pid){
    if(!confirm('Delete this payment?')) return;
    const fd=new FormData();fd.append('ajax_action','delete_settlement_payment');fd.append('payment_id',pid);fd.append('cheque_id',ARD.chequeId);
    try{
        const res=await fetch('return_cheques.php',{method:'POST',body:fd});
        const data=await res.json();
        if(!data.success){showToast(data.error||'Delete failed','err');return;}
        showToast('Payment deleted','ok');
        await loadSettlementPayments();
        updateRowBadge(ARD.chequeId,data.new_total,data.fully_settled,data.cheque_total||ARD.chequeAmt);
    }catch(e){showToast('Error: '+e.message,'err');}
}

function updateRowBadge(chequeId,newTotal,fullSettled,chequeAmt){
    const rowEl=document.getElementById('row-'+chequeId);if(!rowEl) return;
    const settleCell=rowEl.querySelector('#sc-'+chequeId);
    const actionCell=rowEl.querySelector('td:last-child');
    const paid=parseFloat(newTotal)||0,total=parseFloat(chequeAmt)||0,remain=Math.max(0,total-paid);
    let settleBadge,settleBtn;
    if(paid<=0){
        settleBadge=`<span class="settled-badge no"><i class="fa-solid fa-clock"></i> Unsettled</span><div class="settle-meta" style="color:#9ca3af;">Paid: Rs.0.00 | Bal: Rs.${total.toFixed(2)}</div>`;
        settleBtn=`<button class="btn-loadpay" onclick="openSettleModal(${chequeId})"><i class="fa-solid fa-money-bill-transfer"></i> Load Pay</button>`;
    }else if(fullSettled){
        settleBadge=`<span class="settled-badge yes"><i class="fa-solid fa-circle-check"></i> Fully Settled</span><div class="settle-meta" style="color:#166534;">Paid: Rs.${paid.toFixed(2)} | Bal: Rs.0.00</div>`;
        settleBtn=`<button class="btn-loadpay settled" onclick="openSettleModal(${chequeId})"><i class="fa-solid fa-circle-check"></i> View</button>`;
    }else{
        settleBadge=`<span class="settled-badge no" style="background:#fff7ed;color:#c2410c;border-color:#fed7aa;"><i class="fa-solid fa-circle-half-stroke"></i> Partially</span><div class="settle-meta" style="color:#92400e;">Paid: Rs.${paid.toFixed(2)} | Bal: Rs.${remain.toFixed(2)}</div>`;
        settleBtn=`<button class="btn-loadpay" onclick="openSettleModal(${chequeId})"><i class="fa-solid fa-money-bill-transfer"></i> Load Pay</button>`;
    }
    if(settleCell) settleCell.innerHTML=settleBadge;
    if(actionCell){const existing=actionCell.querySelector('.btn-loadpay');if(existing) existing.outerHTML=settleBtn;}
    rowEl.dataset.settled=fullSettled?'1':'0';
    if(fullSettled) autoReturnIfFullySettled(rowEl);
    if(ARD && ARD.chequeId == chequeId){
        ARD.returnSettled = fullSettled ? 1 : 0;
        renderModalReturnBox();
    }
}

/* ── change the cheque-issue item status right after a payment is saved ── */
async function markIssueStatusAfterPayment(action,newStatus,okMsg){
    if(!ARD.issueItemId) return;
    if(ARD.issueItemStatus !== 'issued'){ renderModalReturnBox(); return; }
    window._issMarking = window._issMarking || {};
    if(window._issMarking[ARD.issueItemId]){
        /* the auto path already handled it (full settlement) */
        if(okMsg) showToast(okMsg,'ok');
        return;
    }
    window._issMarking[ARD.issueItemId] = 1;
    try{
        const fd=new FormData();
        fd.append('action',action);
        fd.append('item_id',ARD.issueItemId);
        const res=await fetch('save_cheque_issue.php',{method:'POST',body:fd});
        const data=await res.json();
        if(data.success){
            ARD.issueItemStatus=newStatus;
            applyIssueStatus(document.getElementById('row-'+ARD.chequeId), ARD.issueItemId, newStatus);
            renderModalReturnBox();
            if(okMsg) showToast(okMsg,'ok');
        } else {
            delete window._issMarking[ARD.issueItemId];
            showToast(data.error||'Could not update issue status','err');
        }
    }catch(e){
        delete window._issMarking[ARD.issueItemId];
        showToast('Issue status update failed: '+e.message,'err');
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
  function basePayload(){
    const fd = new FormData();
    const cv = sGetCollectorValues();
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
    btn.disabled=true;btn.innerHTML='<span class="spinner"></span> Saving…';
    let saved=[];
    try{
        if(cashAmt>0){
            const fd=basePayload();fd.append('payment_method','cash');fd.append('payment_date',cashDate);fd.append('amount',cashAmt);
            fd.append('amount_to_bank',document.getElementById('cashToBank').value||0);fd.append('reference_no',document.getElementById('cashRef').value||'');
            fd.append('collected_by',document.getElementById('cashCollectedBy').value);fd.append('remarks',document.getElementById('cashRemarks').value||'');
            fd.append('payment_source','return_cheque_settlement');
            const res=await fetch('save_payment.php',{method:'POST',body:fd});const data=await res.json();
            if(!data.success){showToast('Cash error: '+(data.error||'Unknown'),'err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Save Payment';return;}
            const fds=new FormData();fds.append('ajax_action','add_settlement_payment');fds.append('cheque_id',ARD.chequeId);
            fds.append('payment_method','cash');fds.append('payment_date',cashDate);fds.append('amount',cashAmt.toFixed(2));
            fds.append('reference_no',document.getElementById('cashRef').value||'');fds.append('remarks',document.getElementById('cashRemarks').value||settlementNote);
            const sr=await fetch('return_cheques.php',{method:'POST',body:fds});const sd=await sr.json();
            if(sd.new_total!==undefined) updateRowBadge(ARD.chequeId,sd.new_total,sd.fully_settled,sd.cheque_total||ARD.chequeAmt);
            saved.push('💵 Cash Rs.'+cashAmt.toFixed(2));
        }
        if(chqTotal>0){
            const fd=basePayload();fd.append('payment_method','cheque');
            fd.append('payment_date',document.getElementById('chqPayDate').value||new Date().toISOString().slice(0,10));
            fd.append('amount',chqTotal);fd.append('reference_no',document.getElementById('chqRef').value||'');
            fd.append('cheque_mode',document.getElementById('chqModeSelect').value||'payee_only');
            fd.append('collected_by',document.getElementById('cashCollectedBy').value);fd.append('remarks',document.getElementById('chqRemarks').value||'');
            fd.append('payment_source','return_cheque_settlement');
            cheques.forEach((q,i)=>{fd.append(`cheques[${i}][cheque_no]`,q.cheque_no);fd.append(`cheques[${i}][cheque_date]`,q.cheque_date);fd.append(`cheques[${i}][amount]`,q.amount);fd.append(`cheques[${i}][bank_code]`,q.bank_code);fd.append(`cheques[${i}][bank_name]`,q.bank_name);fd.append(`cheques[${i}][branch_code]`,q.branch_code);fd.append(`cheques[${i}][branch_name]`,q.branch_name);});
            const res=await fetch('save_payment.php',{method:'POST',body:fd});const data=await res.json();
            if(!data.success){showToast('Cheque error: '+(data.error||'Unknown'),'err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Save Payment';return;}
            for(const q of cheques){
                const fds=new FormData();fds.append('ajax_action','add_settlement_payment');fds.append('cheque_id',ARD.chequeId);
                fds.append('payment_method','cheque');
                fds.append('payment_date',document.getElementById('chqPayDate').value||new Date().toISOString().slice(0,10));
                fds.append('amount',q.amount.toFixed(2));
                fds.append('cheque_no',q.cheque_no);fds.append('cheque_date',q.cheque_date);
                fds.append('bank_name',q.bank_name);fds.append('bank_code',q.bank_code);fds.append('branch_name',q.branch_name);
                fds.append('reference_no',document.getElementById('chqRef').value||'');
                fds.append('remarks',document.getElementById('chqRemarks').value||settlementNote);
                const sr=await fetch('return_cheques.php',{method:'POST',body:fds});const sd=await sr.json();
                if(sd.new_total!==undefined) updateRowBadge(ARD.chequeId,sd.new_total,sd.fully_settled,sd.cheque_total||ARD.chequeAmt);
            }
            saved.push('🏦 Cheque Rs.'+chqTotal.toFixed(2));
        }
        /* ── update Cheque Issue status after saving the payment ── */
        if(ARD.issueItemId && ARD.issueItemStatus === 'issued'){
            if(_willBeFull){
                await markIssueStatusAfterPayment('mark_issued_to_customer','issued_to_customer','Fully paid — cheque status updated to Issued to Customer ✓');
            } else {
                await markIssueStatusAfterPayment('mark_returned','returned','Part payment saved — cheque status updated to Return ✓');
            }
        }
        ['cashAmount','cashToBank','cashRef','cashRemarks','chqRef','chqRemarks','settlementNote'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
        document.getElementById('chequesContainer').innerHTML='';chequeCounter=0;addCheque();
        await loadSettlementPayments();
        showToast(saved.join(' + ')+' ✓ Saved','ok');
    }catch(err){showToast('Network error: '+err.message,'err');}
    btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Save Payment';
}

function showToast(msg,type){
    const t=document.getElementById('rcToast');
    t.style.background=type==='ok'?'#166534':'#dc2626';
    t.textContent=msg;t.classList.add('show');
    clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),3200);
}

function esc(s){
    if(s===null||s===undefined) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

function exportCSV(){
    const rows=document.querySelectorAll('#mainTable tbody tr');
    if(!rows.length){alert('No data to export.');return;}
    const headers=['No','SR Code','Cheque Date','Received Date','Return Date','Cheque No','Bank','Bank Code','Amount','T-Code','Customer','Invoice','Settlement','CRN No','CRN Status'];
    const lines=[headers.join(',')];
    const q=v=>'"'+(v||'').toString().replace(/"/g,'""').replace(/\s+/g,' ').trim()+'"';
    rows.forEach((tr,i)=>{
        const tds=tr.querySelectorAll('td');
        lines.push([i+1,q(tds[1]?.textContent),q(tds[2]?.textContent),q(tds[3]?.textContent),
            q(tds[4]?.querySelector('.return-date-chip')?.textContent?.trim()||''),
            q(tds[5]?.querySelector('.mono')?.textContent||''),q(tds[6]?.querySelector('div')?.textContent||''),
            q(tds[7]?.textContent),q(tds[8]?.textContent),q(tds[9]?.querySelector('.mono')?.textContent||''),
            q(tds[9]?.querySelector('.cust-sub')?.textContent||''),q(tds[10]?.textContent),
            q(tds[11]?.querySelector('.settled-badge')?.textContent?.trim()||''),
            q(tds[12]?.querySelector('.crn-no-txt')?.textContent||''),
            q(tds[12]?.querySelector('.crn-status-pill')?.textContent?.trim()||''),
        ].join(','));
    });
    const blob=new Blob([lines.join('\n')],{type:'text/csv'});
    const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;
    a.download='return_cheques_<?php echo date("Ymd_Hi"); ?>.csv';a.click();URL.revokeObjectURL(url);
}
function exportExcel(){
    /* ── Build data from the last fetched rows cache ── */
    if(!window._lastFetchedRows || !window._lastFetchedRows.length){
        showToast('No data to export — run a search first.','err'); return;
    }

    const today = new Date(); today.setHours(0,0,0,0);
    function agingDays(dateStr){
        if(!dateStr||dateStr==='0000-00-00'||dateStr==='0000-00-00 00:00:00') return '';
        const dt=new Date(dateStr.replace(' ','T')); if(isNaN(dt.getTime())) return '';
        return Math.floor((today-dt)/86400000);
    }
    function fmtD(d){
        if(!d||d==='0000-00-00'||d==='0000-00-00 00:00:00') return '';
        const dt=new Date(d.replace(' ','T')); if(isNaN(dt.getTime())) return '';
        const m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return String(dt.getDate()).padStart(2,'0')+' '+m[dt.getMonth()]+' '+dt.getFullYear();
    }

    const headers = [
        '#',
        'SR Code',
        'Cheque Date',
        'Received Date',
        'Return Date',
        'Aging - Return (days)',
        'Aging - Received (days)',
        'Cheque No.',
        'Bank Name',
        'Branch Name',
        'Bank Code',
        'Cheque Mode',
        'Amount (Rs.)',
        'Settled Paid (Rs.)',
        'Balance (Rs.)',
        'T-Code',
        'Customer Name',
        'Invoice No.',
        'Settlement Status',
        'Settlement Paid (Rs.)',
        'Delivery Date',
        'CRN No.',
        'CRN Status',
        'CRN Return Code',
        'CRN Return Reason',
        'CRN Return Remark',
        'CRN Collecting Bank',
        'CRN Collecting Branch',
        'CRN Date of Return',
        'CRN Uploaded By',
        'CRN Uploaded At',
        'Issue Code',
        'Issue Status',
    ];

    const data = [headers];

    window._lastFetchedRows.forEach((row, i) => {
        const cheqAmt   = parseFloat(row.total_amount   || 0);
        const settlePaid= parseFloat(row.settlement_amount || 0);
        const balance   = Math.max(0, cheqAmt - settlePaid);
        const settled   = parseInt(row.return_settled   || 0);

        const returnDateRaw = row.return_date || row.crn_date_of_return || row.returned_date || '';
        const agingReturn   = agingDays(returnDateRaw);
        const agingReceived = agingDays(row.received_date || '');

        let settlementStatus = 'Unsettled';
        if(settled)          settlementStatus = 'Fully Settled';
        else if(settlePaid>0)settlementStatus = 'Partially Settled';

        const isRep = parseInt(row.is_representable);
        let crnStatus = 'No CRN';
        if((row.crn_no||'').trim()){
            crnStatus = isRep===1 ? 'Re-presentable' : 'Non-representable';
        }

        data.push([
            i + 1,
            row.sr_code           || '',
            fmtD(row.cheque_date),
            fmtD(row.received_date),
            fmtD(returnDateRaw),
            agingReturn   === '' ? '' : agingReturn,
            agingReceived === '' ? '' : agingReceived,
            row.cheque_no         || '',
            row.bank_name         || row.bank_code || '',
            row.branch_name       || '',
            row.bank_code         || '',
            row.cheque_mode       || '',
            cheqAmt,
            settlePaid,
            balance,
            row.t_code            || '',
            row.customer_name     || '',
            row.invoice_num       || '',
            settlementStatus,
            settlePaid,
            fmtD(row.delivery_date),
            row.crn_no            || '',
            crnStatus,
            row.crn_return_code   || '',
            row.crn_return_reason || '',
            row.crn_return_remark || '',
            row.crn_collecting_bank   || '',
            row.crn_collecting_branch || '',
            fmtD(row.crn_date_of_return),
            row.crn_uploaded_by   || '',
            row.crn_uploaded_at   || '',
            row.issue_code        || '',
            row.issue_item_status || '',
        ]);
    });

    const ws = XLSX.utils.aoa_to_sheet(data);

    /* Column widths */
    ws['!cols'] = [
        {wch:5},  {wch:10}, {wch:14}, {wch:14}, {wch:14},
        {wch:14}, {wch:16}, {wch:16}, {wch:24}, {wch:20},
        {wch:10}, {wch:14}, {wch:14}, {wch:14}, {wch:14},
        {wch:12}, {wch:24}, {wch:14}, {wch:16}, {wch:14},
        {wch:14}, {wch:20}, {wch:18}, {wch:12}, {wch:24},
        {wch:24}, {wch:22}, {wch:22}, {wch:14}, {wch:20},
        {wch:18}, {wch:14}, {wch:14},
    ];

    /* Freeze header row */
    ws['!freeze'] = {xSplit:0, ySplit:1, topLeftCell:'A2', activePane:'bottomLeft'};

    const range = XLSX.utils.decode_range(ws['!ref']);

    /* Header style — dark red */
    const hdrStyle = {
        font:      {bold:true, color:{rgb:'FFFFFF'}, sz:10},
        fill:      {fgColor:{rgb:'7F1D1D'}},
        alignment: {horizontal:'center', vertical:'center', wrapText:true},
        border:    {bottom:{style:'medium',color:{rgb:'FECACA'}}}
    };
    for(let C = range.s.c; C <= range.e.c; C++){
        const cell = XLSX.utils.encode_cell({r:0, c:C});
        if(ws[cell]) ws[cell].s = hdrStyle;
    }

    /* Number columns: Amount=12, Paid=13, Balance=14, SettlePaid=19 (0-indexed) */
    const numCols  = [12, 13, 14, 19];
    /* Aging columns: 5, 6 */
    const agingCols = [5, 6];

    for(let R = 1; R <= range.e.r; R++){
        const isEven = R % 2 === 0;
        for(let C = range.s.c; C <= range.e.c; C++){
            const cell = XLSX.utils.encode_cell({r:R, c:C});
            if(!ws[cell]) ws[cell] = {t:'s', v:''};
            const s = {};
            if(isEven) s.fill = {fgColor:{rgb:'FFF5F5'}};
            if(numCols.includes(C)){
                s.numFmt    = '#,##0.00';
                s.alignment = {horizontal:'right'};
            }
            if(agingCols.includes(C)){
                const v = ws[cell].v;
                if(typeof v === 'number' && v >= 0){
                    const rgb = v>=90?'FECACA':v>=60?'FED7AA':v>=30?'FDE68A':'BBF7D0';
                    s.fill      = {fgColor:{rgb}};
                    s.font      = {bold:true, color:{rgb: v>=90?'991B1B':v>=60?'9A3412':v>=30?'92400E':'166534'}};
                    s.alignment = {horizontal:'center'};
                }
            }
            ws[cell].s = s;
        }
    }

    /* Totals row */
    const lastR = range.e.r + 1;
    const totalRow = new Array(headers.length).fill('');
    totalRow[0]  = 'TOTAL';
    totalRow[11] = {f:`SUM(M2:M${range.e.r+1})`};   /* Amount col M = index 12 */
    totalRow[12] = {f:`SUM(N2:N${range.e.r+1})`};   /* Settled Paid */
    totalRow[13] = {f:`SUM(O2:O${range.e.r+1})`};   /* Balance */
    XLSX.utils.sheet_add_aoa(ws, [totalRow], {origin:{r:lastR, c:0}});

    const totStyle = {
        font:      {bold:true, color:{rgb:'FFFFFF'}, sz:11},
        fill:      {fgColor:{rgb:'450A0A'}},
        numFmt:    '#,##0.00',
        alignment: {horizontal:'right'},
    };
    for(let C = 0; C <= range.e.c; C++){
        const cell = XLSX.utils.encode_cell({r:lastR, c:C});
        if(!ws[cell]) ws[cell] = {t:'s', v:''};
        ws[cell].s = totStyle;
    }
    /* Label cell left-aligned */
    ws[XLSX.utils.encode_cell({r:lastR,c:0})].s = {...totStyle, alignment:{horizontal:'left'}};

    /* Write file */
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Returned Cheques');

    const now = new Date();
    const stamp = now.getFullYear()
        + String(now.getMonth()+1).padStart(2,'0')
        + String(now.getDate()).padStart(2,'0')
        + '_' + String(now.getHours()).padStart(2,'0')
        + String(now.getMinutes()).padStart(2,'0');

    XLSX.writeFile(wb, 'return_cheques_' + stamp + '.xlsx');
}

/* ══ Re-present Modal ══ */
let REPRESENT_CHEQUE_ID = 0;

function openRepresentModal(chequeId, chequeNo, bankName) {
    REPRESENT_CHEQUE_ID = chequeId;
    document.getElementById('representModalSub').textContent =
        'Cheque: ' + chequeNo + ' — ' + bankName;
    document.getElementById('representDate').value =
        new Date().toISOString().split('T')[0];
    const modal = document.getElementById('representModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeRepresentModal() {
    document.getElementById('representModal').style.display = 'none';
    document.body.style.overflow = '';
    REPRESENT_CHEQUE_ID = 0;
}

async function submitRepresent() {
    const date = document.getElementById('representDate').value;
    if (!date) { showToast('Please select a re-presentation date.', 'err'); return; }
    if (!REPRESENT_CHEQUE_ID) { showToast('No cheque selected.', 'err'); return; }

    const btn = document.getElementById('representSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Saving…';

    const fd = new FormData();
    fd.append('ajax_action', 'represent_cheque');
    fd.append('cheque_id', REPRESENT_CHEQUE_ID);
    fd.append('represent_date', date);

    try {
        const res = await fetch('return_cheques.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (!data.success) {
            showToast('Failed: ' + (data.error || 'Unknown error'), 'err');
            return;
        }

        showToast('✓ Cheque re-presented — status set to Pending', 'ok');
        closeRepresentModal();

        /* Fade out and remove the row — cheque is no longer "returned" */
        const row = document.getElementById('row-' + REPRESENT_CHEQUE_ID);
        if (row) {
            row.style.transition = 'opacity .5s, transform .5s';
            row.style.opacity = '0';
            row.style.transform = 'translateX(20px)';
            setTimeout(() => {
                row.remove();
                /* Update visible count */
                const vc = document.getElementById('visCount');
                if (vc) {
                    const cur = parseInt(vc.textContent) || 0;
                    vc.textContent = Math.max(0, cur - 1) + ' records';
                }
            }, 520);
        }
    } catch (e) {
        showToast('Network error: ' + e.message, 'err');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Confirm Re-present';
    }
}

/* Close on backdrop click / Escape */
document.getElementById('representModal').addEventListener('click', function(e) {
    if (e.target === this) closeRepresentModal();
});
</script>

<?php include 'footer.php'; ?>