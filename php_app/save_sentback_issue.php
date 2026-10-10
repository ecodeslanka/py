<?php
/**
 * save_sentback_issue.php
 * AJAX handler for sent-back cheque issue/collect workflow.
 * Uses SEPARATE tables: sentback_issues + sentback_issue_items
 *
 * Actions:
 *   save_issue          — create a new sent-back cheque issue (SR or CC)
 *   load_items          — load items for an issue
 *   mark_returned       — mark a single cheque item as returned
 *   reissue_item        — revert returned → issued
 *   remove_item         — remove item from an issue
 *   delete_issue        — delete entire issue + items
 *   load_history        — paginated issue history list
 */

if (session_status() === PHP_SESSION_NONE) session_start();
include_once 'config.php';
mysqli_report(MYSQLI_REPORT_OFF);
header('Content-Type: application/json');

function sb_esc($conn, $v) { return mysqli_real_escape_string($conn, trim($v ?? '')); }
function sb_user() {
    return $_SESSION['username']   ??
           $_SESSION['user_name']  ??
           $_SESSION['name']       ??
           $_SESSION['full_name']  ??
           (isset($_SESSION['user_id']) ? 'User #'.$_SESSION['user_id'] : 'system');
}

/* ══════════════════════════════════════════════════════
   ENSURE TABLES EXIST
══════════════════════════════════════════════════════ */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `sentback_issues` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `issue_code`  VARCHAR(40)  NOT NULL UNIQUE,
    `issue_date`  DATE         NOT NULL,
    `person_type` ENUM('SR','CC') NOT NULL DEFAULT 'CC',
    `person_code` VARCHAR(100) NOT NULL DEFAULT '',
    `person_name` VARCHAR(200) NOT NULL DEFAULT '',
    `employee_id` INT          DEFAULT NULL,
    `notes`       TEXT,
    `created_by`  VARCHAR(100) DEFAULT NULL,
    `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sb_issue_date (`issue_date`),
    INDEX idx_sb_person     (`person_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `sentback_issue_items` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `issue_id`      INT           NOT NULL,
    `cheque_id`     INT           NOT NULL,
    `cheque_no`     VARCHAR(100)  NOT NULL DEFAULT '',
    `customer_name` VARCHAR(200)  NOT NULL DEFAULT '',
    `amount`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `bank_name`     VARCHAR(200)  NOT NULL DEFAULT '',
    `branch_name`   VARCHAR(200)  NOT NULL DEFAULT '',
    `cheque_date`   DATE          DEFAULT NULL,
    `status`        ENUM('issued','returned','issued_to_customer') NOT NULL DEFAULT 'issued',
    `returned_at`   DATETIME      DEFAULT NULL,
    `customer_issued_at` DATETIME DEFAULT NULL,
    INDEX idx_sb_item_issue  (`issue_id`),
    INDEX idx_sb_item_cheque (`cheque_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── ensure new column / enum exist on pre-existing tables ── */
$_chk = mysqli_query($conn, "SHOW COLUMNS FROM sentback_issue_items LIKE 'customer_issued_at'");
if (!$_chk || mysqli_num_rows($_chk) === 0) {
    mysqli_query($conn, "ALTER TABLE sentback_issue_items ADD COLUMN `customer_issued_at` DATETIME DEFAULT NULL");
}
$_st = mysqli_query($conn, "SHOW COLUMNS FROM sentback_issue_items LIKE 'status'");
if ($_st && ($_srow = mysqli_fetch_assoc($_st))) {
    if (stripos($_srow['Type'] ?? '', 'issued_to_customer') === false) {
        mysqli_query($conn, "ALTER TABLE sentback_issue_items
            MODIFY COLUMN `status` ENUM('issued','returned','issued_to_customer') NOT NULL DEFAULT 'issued'");
    }
}

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

/* ══════════════════════════════════════════════════════
   SAVE_ISSUE
══════════════════════════════════════════════════════ */
if ($action === 'save_issue') {
    $issue_date  = sb_esc($conn, $_POST['issue_date']  ?? '');
    $person_type = sb_esc($conn, $_POST['person_type'] ?? 'CC');
    $person_code = sb_esc($conn, $_POST['person_code'] ?? '');
    $person_name = sb_esc($conn, $_POST['person_name'] ?? '');
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $notes       = sb_esc($conn, $_POST['notes']       ?? '');
    $cheque_ids  = array_map('intval', (array)($_POST['cheque_ids'] ?? []));
    $by          = sb_esc($conn, sb_user());

    if (!$issue_date || !$person_type || !$person_code || empty($cheque_ids)) {
        echo json_encode(['success'=>false,'error'=>'Missing required fields or no cheques selected']);
        exit;
    }

    /* Generate unique issue code — prefix SBI (SentBack Issue) */
    $date_part = date('Ymd', strtotime($issue_date));
    $seq_r = mysqli_query($conn, "SELECT COUNT(*)+1 AS nxt FROM sentback_issues WHERE DATE(issue_date)='$issue_date'");
    $seq   = $seq_r ? (int)mysqli_fetch_assoc($seq_r)['nxt'] : 1;
    $issue_code = 'SBI-'.$date_part.'-'.str_pad($seq, 4, '0', STR_PAD_LEFT);

    /* Ensure uniqueness */
    $attempts = 0;
    while (true) {
        $chk = mysqli_query($conn, "SELECT id FROM sentback_issues WHERE issue_code='$issue_code' LIMIT 1");
        if (!$chk || mysqli_num_rows($chk) === 0) break;
        $seq++;
        $issue_code = 'SBI-'.$date_part.'-'.str_pad($seq, 4, '0', STR_PAD_LEFT);
        if (++$attempts > 100) {
            echo json_encode(['success'=>false,'error'=>'Could not generate unique issue code']);
            exit;
        }
    }

    $emp_sql = $employee_id ? $employee_id : 'NULL';

    mysqli_query($conn, "INSERT INTO sentback_issues
        (issue_code, issue_date, person_type, person_code, person_name, employee_id, notes, created_by)
        VALUES ('$issue_code','$issue_date','$person_type','$person_code','$person_name',$emp_sql,'$notes','$by')");

    $issue_id = (int)mysqli_insert_id($conn);
    if (!$issue_id) {
        echo json_encode(['success'=>false,'error'=>'DB insert failed: '.mysqli_error($conn)]);
        exit;
    }

    $inserted = 0;
    foreach ($cheque_ids as $cid) {
        if (!$cid) continue;

        /* Fetch cheque details */
        $cr = mysqli_query($conn, "
            SELECT ch.id, ch.cheque_no, ch.total_amount,
                   ch.bank_name, ch.bank_code, ch.branch_name, ch.cheque_date,
                   COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) AS customer_name
            FROM cheques ch
            INNER JOIN invoice_payments ip  ON ip.id  = ch.invoice_payment_id
            LEFT  JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
            LEFT  JOIN customers c ON c.t_code = ch.t_code
            WHERE ch.id = $cid LIMIT 1");

        if (!$cr || mysqli_num_rows($cr) === 0) continue;
        $cd = mysqli_fetch_assoc($cr);

        $cno      = sb_esc($conn, $cd['cheque_no']);
        $cust     = sb_esc($conn, $cd['customer_name'] ?? '');
        $amt      = floatval($cd['total_amount']);
        $bname    = sb_esc($conn, $cd['bank_name'] ?? $cd['bank_code'] ?? '');
        $brname   = sb_esc($conn, $cd['branch_name'] ?? '');
        $cdate    = $cd['cheque_date'] ? "'".sb_esc($conn, $cd['cheque_date'])."'" : 'NULL';

        mysqli_query($conn, "INSERT INTO sentback_issue_items
            (issue_id, cheque_id, cheque_no, customer_name, amount, bank_name, branch_name, cheque_date, status)
            VALUES ($issue_id, $cid, '$cno', '$cust', $amt, '$bname', '$brname', $cdate, 'issued')");
        $inserted++;
    }

    if (!$inserted) {
        mysqli_query($conn, "DELETE FROM sentback_issues WHERE id=$issue_id");
        echo json_encode(['success'=>false,'error'=>'No valid cheques could be added']);
        exit;
    }

    /* Log */
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
        id INT AUTO_INCREMENT PRIMARY KEY, cheque_id INT NOT NULL,
        action VARCHAR(100) NOT NULL, old_value TEXT, new_value TEXT, note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_cid (cheque_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ($cheque_ids as $cid) {
        if (!$cid) continue;
        $note = sb_esc($conn, "Issued via SentBack issue $issue_code to $person_type: $person_code");
        mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id,action,new_value,note,created_by)
            VALUES ($cid,'sb_issued','$issue_code','$note','$by')");
    }

    echo json_encode([
        'success'    => true,
        'issue_id'   => $issue_id,
        'issue_code' => $issue_code,
        'inserted'   => $inserted,
    ]);
    exit;
}

/* ══════════════════════════════════════════════════════
   LOAD_ITEMS
══════════════════════════════════════════════════════ */
if ($action === 'load_items') {
    $issue_id = intval($_GET['issue_id'] ?? $_POST['issue_id'] ?? 0);
    if (!$issue_id) { echo json_encode(['success'=>false,'error'=>'No issue_id']); exit; }

    $r = mysqli_query($conn, "
        SELECT i.id, i.cheque_id, i.cheque_no, i.customer_name,
               i.amount, i.status, i.returned_at, i.customer_issued_at,
               i.bank_name, i.branch_name, i.cheque_date,
               ch.total_amount  AS cheque_total,
               COALESCE(ch.sb_settled, 0)           AS return_settled,
               COALESCE(ch.sb_settlement_amount, 0) AS settlement_amount
        FROM sentback_issue_items i
        LEFT JOIN cheques ch ON ch.id = i.cheque_id
        WHERE i.issue_id = $issue_id
        ORDER BY i.id ASC");

    $items = [];
    if ($r) while ($row = mysqli_fetch_assoc($r)) $items[] = $row;

    echo json_encode(['success'=>true,'items'=>$items]);
    exit;
}

/* ══════════════════════════════════════════════════════
   MARK_RETURNED
══════════════════════════════════════════════════════ */
if ($action === 'mark_returned') {
    $item_id = intval($_POST['item_id'] ?? 0);
    if (!$item_id) { echo json_encode(['success'=>false,'error'=>'No item_id']); exit; }

    $r = mysqli_query($conn, "UPDATE sentback_issue_items
        SET status='returned', returned_at=NOW()
        WHERE id=$item_id AND status='issued'");

    if ($r && mysqli_affected_rows($conn) > 0) {
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'error'=>'Item not found or already returned']);
    }
    exit;
}

/* ══════════════════════════════════════════════════════
   MARK_ISSUED_TO_CUSTOMER
   Fully-settled cheque physically handed back to the customer.
══════════════════════════════════════════════════════ */
if ($action === 'mark_issued_to_customer') {
    $item_id = intval($_POST['item_id'] ?? 0);
    if (!$item_id) { echo json_encode(['success'=>false,'error'=>'No item_id']); exit; }

    $r = mysqli_query($conn, "UPDATE sentback_issue_items
        SET status='issued_to_customer', customer_issued_at=NOW()
        WHERE id=$item_id AND status IN ('issued','returned')");

    if ($r && mysqli_affected_rows($conn) > 0) {
        echo json_encode(['success'=>true]);
    } else {
        $chk = mysqli_query($conn, "SELECT status FROM sentback_issue_items WHERE id=$item_id LIMIT 1");
        $row = $chk ? mysqli_fetch_assoc($chk) : null;
        if (!$row) {
            echo json_encode(['success'=>false,'error'=>'Item not found (id='.$item_id.')']);
        } elseif ($row['status'] === 'issued_to_customer') {
            echo json_encode(['success'=>false,'error'=>'Already issued to customer']);
        } else {
            echo json_encode(['success'=>false,'error'=>'Update did not apply (status='.$row['status'].')']);
        }
    }
    exit;
}

/* ══════════════════════════════════════════════════════
   REISSUE_ITEM
══════════════════════════════════════════════════════ */
if ($action === 'reissue_item') {
    $item_id = intval($_POST['item_id'] ?? 0);
    if (!$item_id) { echo json_encode(['success'=>false,'error'=>'No item_id']); exit; }

    $r = mysqli_query($conn, "UPDATE sentback_issue_items
        SET status='issued', returned_at=NULL, customer_issued_at=NULL
        WHERE id=$item_id AND status IN ('returned','issued_to_customer')");

    if ($r && mysqli_affected_rows($conn) > 0) {
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'error'=>'Item not found or already issued']);
    }
    exit;
}

/* ══════════════════════════════════════════════════════
   REMOVE_ITEM
══════════════════════════════════════════════════════ */
if ($action === 'remove_item') {
    $item_id = intval($_POST['item_id'] ?? 0);
    if (!$item_id) { echo json_encode(['success'=>false,'error'=>'No item_id']); exit; }
    mysqli_query($conn, "DELETE FROM sentback_issue_items WHERE id=$item_id");
    echo json_encode(['success'=>true]);
    exit;
}

/* ══════════════════════════════════════════════════════
   DELETE_ISSUE
══════════════════════════════════════════════════════ */
if ($action === 'delete_issue') {
    $issue_id = intval($_POST['issue_id'] ?? 0);
    if (!$issue_id) { echo json_encode(['success'=>false,'error'=>'No issue_id']); exit; }
    mysqli_query($conn, "DELETE FROM sentback_issue_items WHERE issue_id=$issue_id");
    mysqli_query($conn, "DELETE FROM sentback_issues WHERE id=$issue_id");
    echo json_encode(['success'=>true]);
    exit;
}

/* ══════════════════════════════════════════════════════
   LOAD_HISTORY  (GET)
══════════════════════════════════════════════════════ */
if ($action === 'load_history') {
    $page   = max(1, intval($_GET['page'] ?? 1));
    $per    = 20;
    $offset = ($page - 1) * $per;
    $search = sb_esc($conn, $_GET['q']           ?? '');
    $f_type = sb_esc($conn, $_GET['person_type'] ?? '');
    $f_date = sb_esc($conn, $_GET['issue_date']  ?? '');

    $where = ['1=1'];
    if ($search) $where[] = "(bi.issue_code LIKE '%$search%' OR bi.person_code LIKE '%$search%' OR bi.person_name LIKE '%$search%')";
    if ($f_type) $where[] = "bi.person_type='$f_type'";
    if ($f_date) $where[] = "bi.issue_date='$f_date'";
    $w = implode(' AND ', $where);

    $cnt_r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM sentback_issues bi WHERE $w");
    $total = $cnt_r ? (int)mysqli_fetch_assoc($cnt_r)['c'] : 0;

    $r = mysqli_query($conn, "
        SELECT bi.*,
               COUNT(i.id)              AS total_items,
               SUM(i.status='issued')   AS issued_cnt,
               SUM(i.status='returned') AS returned_cnt,
               SUM(i.status='issued_to_customer') AS cust_issued_cnt,
               SUM(i.amount)            AS total_amount,
               COALESCE(emp.employee_id,'')          AS emp_code,
               COALESCE(emp.employee_full_name,'')   AS emp_name,
               COALESCE(d.designation_name,'')       AS emp_desig
        FROM sentback_issues bi
        LEFT JOIN sentback_issue_items i  ON i.issue_id  = bi.id
        LEFT JOIN employees emp           ON emp.id      = bi.employee_id
        LEFT JOIN designations d          ON d.id        = emp.designation_id
        WHERE $w
        GROUP BY bi.id
        ORDER BY bi.issue_date DESC, bi.id DESC
        LIMIT $per OFFSET $offset");

    $rows = [];
    if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;

    echo json_encode([
        'success' => true,
        'rows'    => $rows,
        'total'   => $total,
        'pages'   => max(1, (int)ceil($total / $per)),
        'page'    => $page,
    ]);
    exit;
}

echo json_encode(['success'=>false,'error'=>'Unknown action: '.$action]);
