<?php
/**
 * save_cheque_issue.php
 * AJAX handler for returned-cheque issue/collect workflow.
 *
 * Actions:
 *   save_issue          — create a new cheque issue (SR or CC)
 *   load_items          — load items for an issue
 *   mark_returned       — mark a single cheque item as returned
 *   reissue_item        — revert returned → issued
 *   remove_item         — remove item from an issue (frees cheque)
 *   delete_issue        — delete entire issue + items
 *   update_issue_person — change person_type / person_code / employee on an issue
 */

if (session_status() === PHP_SESSION_NONE) session_start();
include_once 'config.php';
mysqli_report(MYSQLI_REPORT_OFF);
header('Content-Type: application/json');

function rc_esc($conn, $v) { return mysqli_real_escape_string($conn, trim($v)); }
function rc_user() {
    return $_SESSION['username']   ??
           $_SESSION['user_name']  ??
           $_SESSION['name']       ??
           $_SESSION['full_name']  ??
           (isset($_SESSION['user_id']) ? 'User #'.$_SESSION['user_id'] : 'system');
}

/* ── ensure tables exist ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `cheque_issues` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `issue_code`  VARCHAR(40)  NOT NULL UNIQUE,
    `issue_date`  DATE         NOT NULL,
    `person_type` ENUM('SR','CC') NOT NULL,
    `person_code` VARCHAR(100) NOT NULL DEFAULT '',
    `person_name` VARCHAR(200) NOT NULL DEFAULT '',
    `employee_id` INT          DEFAULT NULL,
    `notes`       TEXT,
    `created_by`  VARCHAR(100) DEFAULT NULL,
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `cheque_issue_items` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `issue_id`      INT          NOT NULL,
    `cheque_id`     INT          NOT NULL,
    `cheque_no`     VARCHAR(100) NOT NULL DEFAULT '',
    `customer_name` VARCHAR(200) NOT NULL DEFAULT '',
    `amount`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `status`        ENUM('issued','returned','issued_to_customer') NOT NULL DEFAULT 'issued',
    `returned_at`   DATETIME DEFAULT NULL,
    `customer_issued_at` DATETIME DEFAULT NULL,
    INDEX idx_issue  (`issue_id`),
    INDEX idx_cheque (`cheque_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── CREATE TABLE IF NOT EXISTS won't add columns to an already-existing
   table — if cheque_issue_items pre-dates status/returned_at, mark_returned
   would silently fail (mysqli_report is OFF). Make sure they exist. ── */
foreach ([
    'status'      => "ENUM('issued','returned','issued_to_customer') NOT NULL DEFAULT 'issued'",
    'returned_at' => "DATETIME DEFAULT NULL",
    'customer_issued_at' => "DATETIME DEFAULT NULL",
] as $_col => $_def) {
    $_chk = mysqli_query($conn, "SHOW COLUMNS FROM cheque_issue_items LIKE '$_col'");
    if (!$_chk || mysqli_num_rows($_chk) === 0) {
        mysqli_query($conn, "ALTER TABLE cheque_issue_items ADD COLUMN `$_col` $_def");
    }
}

/* ── upgrade status ENUM if it pre-dates 'issued_to_customer' ── */
$_st = mysqli_query($conn, "SHOW COLUMNS FROM cheque_issue_items LIKE 'status'");
if ($_st && ($_srow = mysqli_fetch_assoc($_st))) {
    if (stripos($_srow['Type'] ?? '', 'issued_to_customer') === false) {
        mysqli_query($conn, "ALTER TABLE cheque_issue_items
            MODIFY COLUMN `status` ENUM('issued','returned','issued_to_customer') NOT NULL DEFAULT 'issued'");
    }
}

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

/* ══════════════════════════════════════
   SAVE_ISSUE
══════════════════════════════════════ */
if ($action === 'save_issue') {
    $issue_date  = rc_esc($conn, $_POST['issue_date']  ?? '');
    $person_type = rc_esc($conn, $_POST['person_type'] ?? 'CC');
    $person_code = rc_esc($conn, $_POST['person_code'] ?? '');
    $person_name = rc_esc($conn, $_POST['person_name'] ?? '');
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $notes       = rc_esc($conn, $_POST['notes']       ?? '');
    $cheque_ids  = array_map('intval', (array)($_POST['cheque_ids'] ?? []));
    $by          = rc_esc($conn, rc_user());

    if (!$issue_date || !$person_type || !$person_code || empty($cheque_ids)) {
        echo json_encode(['success'=>false,'error'=>'Missing required fields or no cheques selected']);
        exit;
    }

    /* generate unique issue code */
    $date_part = date('Ymd', strtotime($issue_date));
    $seq_r = mysqli_query($conn, "SELECT COUNT(*)+1 AS nxt FROM cheque_issues WHERE DATE(issue_date)='$issue_date'");
    $seq   = $seq_r ? (int)mysqli_fetch_assoc($seq_r)['nxt'] : 1;
    $issue_code = 'CHQ-'.$date_part.'-'.str_pad($seq, 4, '0', STR_PAD_LEFT);

    /* ensure uniqueness */
    $attempts = 0;
    while (true) {
        $chk = mysqli_query($conn, "SELECT id FROM cheque_issues WHERE issue_code='$issue_code' LIMIT 1");
        if (!$chk || mysqli_num_rows($chk) === 0) break;
        $seq++;
        $issue_code = 'CHQ-'.$date_part.'-'.str_pad($seq, 4, '0', STR_PAD_LEFT);
        if (++$attempts > 100) { echo json_encode(['success'=>false,'error'=>'Could not generate unique code']); exit; }
    }

    $emp_sql = $employee_id ? $employee_id : 'NULL';

    mysqli_query($conn, "INSERT INTO cheque_issues
        (issue_code, issue_date, person_type, person_code, person_name, employee_id, notes, created_by)
        VALUES ('$issue_code','$issue_date','$person_type','$person_code','$person_name',$emp_sql,'$notes','$by')");
    $issue_id = (int)mysqli_insert_id($conn);
    if (!$issue_id) {
        echo json_encode(['success'=>false,'error'=>'DB error: '.mysqli_error($conn)]);
        exit;
    }

    $inserted = 0;
    foreach ($cheque_ids as $cid) {
        if (!$cid) continue;
        /* get cheque details */
        $cr = mysqli_query($conn, "SELECT ch.id, ch.cheque_no, ch.total_amount,
               COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) AS customer_name
               FROM cheques ch
               INNER JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id
               LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
               LEFT JOIN customers c ON c.t_code = ch.t_code
               WHERE ch.id = $cid LIMIT 1");
        if (!$cr || mysqli_num_rows($cr) === 0) continue;
        $cdata = mysqli_fetch_assoc($cr);
        $cno   = rc_esc($conn, $cdata['cheque_no']);
        $cust  = rc_esc($conn, $cdata['customer_name'] ?? '');
        $amt   = floatval($cdata['total_amount']);
        mysqli_query($conn, "INSERT INTO cheque_issue_items
            (issue_id, cheque_id, cheque_no, customer_name, amount, status)
            VALUES ($issue_id, $cid, '$cno', '$cust', $amt, 'issued')");
        $inserted++;
    }

    if (!$inserted) {
        mysqli_query($conn, "DELETE FROM cheque_issues WHERE id=$issue_id");
        echo json_encode(['success'=>false,'error'=>'No valid cheques could be added']);
        exit;
    }

    echo json_encode(['success'=>true,'issue_id'=>$issue_id,'issue_code'=>$issue_code,'inserted'=>$inserted]);
    exit;
}

/* ══════════════════════════════════════
   LOAD_ITEMS
══════════════════════════════════════ */
if ($action === 'load_items') {
    $issue_id = intval($_GET['issue_id'] ?? $_POST['issue_id'] ?? 0);
    if (!$issue_id) { echo json_encode(['success'=>false,'error'=>'No issue_id']); exit; }

    $r = mysqli_query($conn, "SELECT i.id, i.cheque_id, i.cheque_no, i.customer_name, i.amount, i.status, i.returned_at, i.customer_issued_at,
               ch.bank_name, ch.bank_code, ch.branch_name, ch.cheque_date,
               ch.total_amount AS cheque_total,
               COALESCE(ch.return_settled,0) AS return_settled,
               COALESCE(ch.settlement_amount,0) AS settlement_amount
               FROM cheque_issue_items i
               LEFT JOIN cheques ch ON ch.id = i.cheque_id
               WHERE i.issue_id = $issue_id
               ORDER BY i.id");
    $items = [];
    if ($r) while ($row = mysqli_fetch_assoc($r)) $items[] = $row;

    echo json_encode(['success'=>true,'items'=>$items]);
    exit;
}

/* ══════════════════════════════════════
   MARK_RETURNED
══════════════════════════════════════ */
if ($action === 'mark_returned') {
    $item_id = intval($_POST['item_id'] ?? 0);
    if (!$item_id) { echo json_encode(['success'=>false,'error'=>'No item_id']); exit; }
    $r = mysqli_query($conn, "UPDATE cheque_issue_items SET status='returned', returned_at=NOW() WHERE id=$item_id AND status='issued'");
    if ($r === false) {
        echo json_encode(['success'=>false,'error'=>'DB error: '.mysqli_error($conn)]);
    } elseif (mysqli_affected_rows($conn) > 0) {
        echo json_encode(['success'=>true]);
    } else {
        /* distinguish "doesn't exist" from "already returned" for easier debugging */
        $chk = mysqli_query($conn, "SELECT status FROM cheque_issue_items WHERE id=$item_id LIMIT 1");
        $row = $chk ? mysqli_fetch_assoc($chk) : null;
        if (!$row) {
            echo json_encode(['success'=>false,'error'=>'Item not found (id='.$item_id.')']);
        } elseif ($row['status'] === 'returned') {
            echo json_encode(['success'=>false,'error'=>'Already returned']);
        } else {
            echo json_encode(['success'=>false,'error'=>'Update did not apply (status='.$row['status'].')']);
        }
    }
    exit;
}

/* ══════════════════════════════════════
   MARK_ISSUED_TO_CUSTOMER
   Fully-settled cheque physically handed
   back to the customer.
══════════════════════════════════════ */
if ($action === 'mark_issued_to_customer') {
    $item_id = intval($_POST['item_id'] ?? 0);
    if (!$item_id) { echo json_encode(['success'=>false,'error'=>'No item_id']); exit; }
    $r = mysqli_query($conn, "UPDATE cheque_issue_items
        SET status='issued_to_customer', customer_issued_at=NOW()
        WHERE id=$item_id AND status IN ('issued','returned')");
    if ($r === false) {
        echo json_encode(['success'=>false,'error'=>'DB error: '.mysqli_error($conn)]);
    } elseif (mysqli_affected_rows($conn) > 0) {
        echo json_encode(['success'=>true]);
    } else {
        $chk = mysqli_query($conn, "SELECT status FROM cheque_issue_items WHERE id=$item_id LIMIT 1");
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

/* ══════════════════════════════════════
   REISSUE_ITEM
══════════════════════════════════════ */
if ($action === 'reissue_item') {
    $item_id = intval($_POST['item_id'] ?? 0);
    if (!$item_id) { echo json_encode(['success'=>false,'error'=>'No item_id']); exit; }
    $r = mysqli_query($conn, "UPDATE cheque_issue_items SET status='issued', returned_at=NULL, customer_issued_at=NULL WHERE id=$item_id AND status IN ('returned','issued_to_customer')");
    if ($r && mysqli_affected_rows($conn) > 0) {
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'error'=>'Item not found or already issued']);
    }
    exit;
}

/* ══════════════════════════════════════
   REMOVE_ITEM
══════════════════════════════════════ */
if ($action === 'remove_item') {
    $item_id = intval($_POST['item_id'] ?? 0);
    if (!$item_id) { echo json_encode(['success'=>false,'error'=>'No item_id']); exit; }
    mysqli_query($conn, "DELETE FROM cheque_issue_items WHERE id=$item_id");
    echo json_encode(['success'=>true]);
    exit;
}

/* ══════════════════════════════════════
   DELETE_ISSUE
══════════════════════════════════════ */
if ($action === 'delete_issue') {
    $issue_id = intval($_POST['issue_id'] ?? 0);
    if (!$issue_id) { echo json_encode(['success'=>false,'error'=>'No issue_id']); exit; }
    mysqli_query($conn, "DELETE FROM cheque_issue_items WHERE issue_id=$issue_id");
    mysqli_query($conn, "DELETE FROM cheque_issues WHERE id=$issue_id");
    echo json_encode(['success'=>true]);
    exit;
}

/* ══════════════════════════════════════
   UPDATE_ISSUE_PERSON
══════════════════════════════════════ */
if ($action === 'update_issue_person') {
    $issue_id    = intval($_POST['issue_id']    ?? 0);
    $person_type = rc_esc($conn, $_POST['person_type'] ?? 'CC');
    $person_code = rc_esc($conn, $_POST['person_code'] ?? '');
    $person_name = rc_esc($conn, $_POST['person_name'] ?? '');
    $employee_id = intval($_POST['employee_id'] ?? 0);
    if (!$issue_id || !$person_code) { echo json_encode(['success'=>false,'error'=>'Missing data']); exit; }

    $emp_sql = $employee_id ? $employee_id : 'NULL';
    mysqli_query($conn, "UPDATE cheque_issues SET
        person_type='$person_type', person_code='$person_code', person_name='$person_name', employee_id=$emp_sql
        WHERE id=$issue_id");

    /* fetch employee info for response */
    $emp_code = $emp_name = $emp_desig = '';
    if ($employee_id) {
        $er = mysqli_query($conn, "SELECT e.employee_id, e.employee_full_name, COALESCE(d.designation_name,'') AS designation_name
               FROM employees e LEFT JOIN designations d ON d.id=e.designation_id WHERE e.id=$employee_id LIMIT 1");
        if ($er && ($ed = mysqli_fetch_assoc($er))) {
            $emp_code  = $ed['employee_id'];
            $emp_name  = $ed['employee_full_name'];
            $emp_desig = $ed['designation_name'];
        }
    }

    echo json_encode([
        'success'     => true,
        'person_type' => $person_type,
        'person_code' => $person_code,
        'person_name' => $person_name,
        'employee_id' => $employee_id ?: 0,
        'emp_code'    => $emp_code,
        'emp_name'    => $emp_name,
        'emp_desig'   => $emp_desig,
    ]);
    exit;
}

/* ══════════════════════════════════════
   LOAD_HISTORY  (GET)
══════════════════════════════════════ */
if ($action === 'load_history') {
    $page     = max(1, intval($_GET['page'] ?? 1));
    $per      = 20;
    $offset   = ($page - 1) * $per;
    $search   = rc_esc($conn, $_GET['q'] ?? '');
    $f_type   = rc_esc($conn, $_GET['person_type'] ?? '');
    $f_date   = rc_esc($conn, $_GET['issue_date']  ?? '');

    $where = ['1=1'];
    if ($search)  $where[] = "(bi.issue_code LIKE '%$search%' OR bi.person_code LIKE '%$search%' OR bi.person_name LIKE '%$search%')";
    if ($f_type)  $where[] = "bi.person_type='$f_type'";
    if ($f_date)  $where[] = "bi.issue_date='$f_date'";
    $w = implode(' AND ', $where);

    $cnt_r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM cheque_issues bi WHERE $w");
    $total = $cnt_r ? (int)mysqli_fetch_assoc($cnt_r)['c'] : 0;

    $r = mysqli_query($conn, "SELECT bi.*,
        COUNT(i.id) AS total_items,
        SUM(i.status='issued') AS issued_cnt,
        SUM(i.status='returned') AS returned_cnt,
        SUM(i.status='issued_to_customer') AS cust_issued_cnt,
        SUM(i.amount) AS total_amount,
        COALESCE(emp.employee_id,'') AS emp_code,
        COALESCE(emp.employee_full_name,'') AS emp_name,
        COALESCE(d.designation_name,'') AS emp_desig
        FROM cheque_issues bi
        LEFT JOIN cheque_issue_items i ON i.issue_id = bi.id
        LEFT JOIN employees emp ON emp.id = bi.employee_id
        LEFT JOIN designations d ON d.id = emp.designation_id
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