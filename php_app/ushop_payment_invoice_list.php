<?php
include 'config.php';

/* ═══════════════════════════════════════════════════════
   AUTO-CREATE TABLES
═══════════════════════════════════════════════════════ */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `ushop_payment_invoices` (
      `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `invoice_no`     VARCHAR(50)  NOT NULL DEFAULT '',
      `invoice_date`   DATE         NOT NULL,
      `subject`        VARCHAR(500) NOT NULL DEFAULT '',
      `category_id`    INT UNSIGNED NULL,
      `subcategory_id` INT UNSIGNED NULL,
      `invoice_ids`    TEXT         NOT NULL,
      `total_amount`   DECIMAL(15,2) NOT NULL DEFAULT 0,
      `total_discount` DECIMAL(15,2) NOT NULL DEFAULT 0,
      `invoice_count`  INT UNSIGNED  NOT NULL DEFAULT 0,
      `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_cat`    (`category_id`),
      KEY `idx_subcat` (`subcategory_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$col_check = mysqli_query($conn, "SHOW COLUMNS FROM ushop_payment_invoices LIKE 'invoice_no'");
if (mysqli_num_rows($col_check) === 0) {
    mysqli_query($conn, "ALTER TABLE ushop_payment_invoices ADD COLUMN `invoice_no` VARCHAR(50) NOT NULL DEFAULT '' AFTER `id`");
}

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `ushop_payment_invoice_payments` (
      `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `payment_invoice_id`  INT UNSIGNED NOT NULL,
      `invoice_id`          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ushop_invoices.id that is paid (0 for non-payroll)',
      `paid_date`           DATE         NOT NULL,
      `amount`              DECIMAL(15,2) NOT NULL DEFAULT 0,
      `remark`              VARCHAR(500) NOT NULL DEFAULT '',
      `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_pi`  (`payment_invoice_id`),
      KEY `idx_inv` (`invoice_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$col2 = mysqli_query($conn, "SHOW COLUMNS FROM ushop_payment_invoice_payments LIKE 'invoice_id'");
if (mysqli_num_rows($col2) === 0) {
    mysqli_query($conn, "ALTER TABLE ushop_payment_invoice_payments ADD COLUMN `invoice_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `payment_invoice_id`");
}

/* ═══════════════════════════════════════════════════════
   HELPER: Check if a category is Payroll
═══════════════════════════════════════════════════════ */
function isPayrollCategory($conn, $category_id) {
    if (!$category_id) return false;
    $r = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT name FROM ushop_letter_categories WHERE id=" . intval($category_id)
    ));
    return $r && strtolower(trim($r['name'])) === 'payroll';
}

/* ═══════════════════════════════════════════════════════
   AJAX: GET INVOICES INSIDE A PAYMENT INVOICE + paid status
═══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_invoices') {
    $pi_id = intval($_GET['pi_id'] ?? 0);
    $pirow = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT invoice_ids, total_amount, total_discount, category_id FROM ushop_payment_invoices WHERE id=$pi_id"
    ));
    $ids = [];
    if ($pirow && $pirow['invoice_ids']) {
        $ids = array_values(array_filter(array_map('intval', explode(',', $pirow['invoice_ids']))));
    }

    $is_payroll = isPayrollCategory($conn, $pirow['category_id'] ?? 0);

    $paid_inv_ids = [];
    if ($ids) {
        $ids_sql = implode(',', $ids);
        $pr = mysqli_query($conn,
            "SELECT DISTINCT invoice_id FROM ushop_payment_invoice_payments
             WHERE payment_invoice_id=$pi_id AND invoice_id IN ($ids_sql)"
        );
        while ($r = mysqli_fetch_assoc($pr)) $paid_inv_ids[] = intval($r['invoice_id']);
    }

    $paid_agg = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(amount),0) total_paid FROM ushop_payment_invoice_payments WHERE payment_invoice_id=$pi_id"
    ));
    $total_paid   = floatval($paid_agg['total_paid']);
    $total_amount = floatval($pirow['total_amount'] ?? 0);
    $balance      = $total_amount - $total_paid;

    $invoices = [];
    if ($ids) {
        $ids_sql = implode(',', $ids);
        $res = mysqli_query($conn, "
            SELECT id, invoice_date, doc_no, unique_inv_no,
                   customer_name, customer_code,
                   total_amount, total_discount
            FROM ushop_invoices
            WHERE id IN ($ids_sql)
            ORDER BY FIELD(id, $ids_sql)
        ");
        while ($r = mysqli_fetch_assoc($res)) {
            $r['is_paid'] = in_array(intval($r['id']), $paid_inv_ids) ? 1 : 0;
            $invoices[] = $r;
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'invoices'     => $invoices,
        'paid_inv_ids' => $paid_inv_ids,
        'total_paid'   => $total_paid,
        'total_amount' => $total_amount,
        'balance'      => $balance,
        'is_payroll'   => $is_payroll,
        'category_id'  => $pirow['category_id'] ?? 0,
    ]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: GET PAYMENT HISTORY FOR A PAYMENT INVOICE
═══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_payments') {
    $pi_id = intval($_GET['pi_id'] ?? 0);
    $res   = mysqli_query($conn, "
        SELECT pip.id, pip.invoice_id, pip.paid_date, pip.amount, pip.remark,
               inv.doc_no, inv.customer_name, inv.customer_code
        FROM ushop_payment_invoice_payments pip
        LEFT JOIN ushop_invoices inv ON inv.id = pip.invoice_id
        WHERE pip.payment_invoice_id=$pi_id
        ORDER BY pip.paid_date ASC, pip.id ASC
    ");
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: MARK PAYROLL INVOICES AS PAID (invoice-wise)
═══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_mark_paid'])) {
    $pi_id      = intval($_POST['pi_id']    ?? 0);
    $paid_date  = trim($_POST['paid_date']  ?? '');
    $remark     = trim($_POST['remark']     ?? '');
    $inv_ids_raw = $_POST['invoice_ids']    ?? [];
    $inv_ids    = array_values(array_filter(array_map('intval', $inv_ids_raw)));

    if (!$pi_id || !$paid_date || empty($inv_ids)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Payment invoice, date, and at least one invoice are required.']);
        exit;
    }

    $pirow   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT invoice_ids FROM ushop_payment_invoices WHERE id=$pi_id"));
    $allowed = array_filter(array_map('intval', explode(',', $pirow['invoice_ids'] ?? '')));
    $inv_ids = array_values(array_intersect($inv_ids, $allowed));

    if (empty($inv_ids)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'None of the selected invoices belong to this payment invoice.']);
        exit;
    }

    $ids_sql = implode(',', $inv_ids);
    $amt_res = mysqli_query($conn, "SELECT id, total_amount FROM ushop_invoices WHERE id IN ($ids_sql)");
    $amt_map = [];
    while ($ar = mysqli_fetch_assoc($amt_res)) $amt_map[intval($ar['id'])] = floatval($ar['total_amount']);

    $pd_e   = mysqli_real_escape_string($conn, $paid_date);
    $rem_e  = mysqli_real_escape_string($conn, $remark);
    $inserted = 0;

    foreach ($inv_ids as $inv_id) {
        $exists = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM ushop_payment_invoice_payments WHERE payment_invoice_id=$pi_id AND invoice_id=$inv_id LIMIT 1"
        ));
        if ($exists) continue;
        $amt = $amt_map[$inv_id] ?? 0;
        $ok  = mysqli_query($conn, "
            INSERT INTO ushop_payment_invoice_payments
                (payment_invoice_id, invoice_id, paid_date, amount, remark, created_at)
            VALUES ($pi_id, $inv_id, '$pd_e', $amt, '$rem_e', NOW())
        ");
        if ($ok) $inserted++;
    }

    $agg = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(amount),0) total_paid FROM ushop_payment_invoice_payments WHERE payment_invoice_id=$pi_id"
    ));
    $pi2  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT total_amount FROM ushop_payment_invoices WHERE id=$pi_id"));
    $total_paid   = floatval($agg['total_paid']);
    $total_amount = floatval($pi2['total_amount'] ?? 0);
    $balance      = $total_amount - $total_paid;

    $pr2 = mysqli_query($conn,
        "SELECT DISTINCT invoice_id FROM ushop_payment_invoice_payments WHERE payment_invoice_id=$pi_id"
    );
    $paid_now = [];
    while ($r = mysqli_fetch_assoc($pr2)) $paid_now[] = intval($r['invoice_id']);

    header('Content-Type: application/json');
    echo json_encode([
        'success'      => $inserted > 0,
        'inserted'     => $inserted,
        'paid_inv_ids' => $paid_now,
        'total_paid'   => $total_paid,
        'total_amount' => $total_amount,
        'balance'      => $balance,
        'message'      => $inserted > 0
            ? "$inserted invoice(s) marked as paid successfully."
            : 'Selected invoices were already marked as paid.',
    ]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: ADD GENERAL PAYMENT (non-payroll, amount only)
═══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_add_payment'])) {
    $pi_id     = intval($_POST['pi_id']    ?? 0);
    $paid_date = trim($_POST['paid_date']  ?? '');
    $amount    = floatval($_POST['amount'] ?? 0);
    $remark    = trim($_POST['remark']     ?? '');

    if (!$pi_id || !$paid_date || $amount <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Payment invoice, date, and a valid amount are required.']);
        exit;
    }

    $pd_e  = mysqli_real_escape_string($conn, $paid_date);
    $rem_e = mysqli_real_escape_string($conn, $remark);
    $ok    = mysqli_query($conn, "
        INSERT INTO ushop_payment_invoice_payments
            (payment_invoice_id, invoice_id, paid_date, amount, remark, created_at)
        VALUES ($pi_id, 0, '$pd_e', $amount, '$rem_e', NOW())
    ");

    $agg  = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(amount),0) total_paid FROM ushop_payment_invoice_payments WHERE payment_invoice_id=$pi_id"
    ));
    $pi2  = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT total_amount FROM ushop_payment_invoices WHERE id=$pi_id"
    ));
    $total_paid   = floatval($agg['total_paid']);
    $total_amount = floatval($pi2['total_amount'] ?? 0);
    $balance      = $total_amount - $total_paid;

    header('Content-Type: application/json');
    echo json_encode([
        'success'      => (bool)$ok,
        'total_paid'   => $total_paid,
        'total_amount' => $total_amount,
        'balance'      => $balance,
        'new_id'       => (int)mysqli_insert_id($conn),
        'message'      => $ok ? 'Payment added successfully.' : 'Failed to add payment.',
    ]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: GET SINGLE PAYMENT INVOICE (for Edit modal prefill)
═══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_pi') {
    $pi_id = intval($_GET['pi_id'] ?? 0);
    $r = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT id, invoice_date, payment_month, subject, category_id, subcategory_id
        FROM ushop_payment_invoices WHERE id=$pi_id
    "));
    header('Content-Type: application/json');
    if (!$r) {
        echo json_encode(['success' => false, 'message' => 'Payment invoice not found.']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $r]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: SAVE EDITED PAYMENT INVOICE DETAILS
   (Month & Year, Invoice Date, Subject, Category, Sub-category)
═══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_edit_save'])) {
    $pi_id         = intval($_POST['pi_id']              ?? 0);
    $inv_date      = trim($_POST['pi_date']               ?? '');
    $payment_month = trim($_POST['pi_month_year']         ?? '');
    $subject       = trim($_POST['pi_subject']            ?? '');
    $cat_id        = intval($_POST['pi_category_id']      ?? 0) ?: null;
    $subcat_id     = intval($_POST['pi_subcategory_id']   ?? 0) ?: null;

    header('Content-Type: application/json');

    if (!$pi_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment invoice.']);
        exit;
    }
    if (!$inv_date) {
        echo json_encode(['success' => false, 'message' => 'Invoice date is required.']);
        exit;
    }
    if (!$payment_month) {
        echo json_encode(['success' => false, 'message' => 'Month & Year is required.']);
        exit;
    }

    $chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM ushop_payment_invoices WHERE id=$pi_id"));
    if (!$chk) {
        echo json_encode(['success' => false, 'message' => 'Payment invoice not found.']);
        exit;
    }

    $date_e = mysqli_real_escape_string($conn, $inv_date);
    $pm_e   = mysqli_real_escape_string($conn, $payment_month);
    $subj_e = mysqli_real_escape_string($conn, $subject);
    $cat_val    = $cat_id    ? $cat_id    : 'NULL';
    $subcat_val = $subcat_id ? $subcat_id : 'NULL';

    $ok = mysqli_query($conn, "
        UPDATE ushop_payment_invoices
        SET invoice_date = '$date_e',
            payment_month = '$pm_e',
            subject = '$subj_e',
            category_id = $cat_val,
            subcategory_id = $subcat_val
        WHERE id = $pi_id
    ");

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit;
    }

    /* Return refreshed display data so the row can update without a full reload */
    $updated = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT pi.id, pi.invoice_date, pi.payment_month, pi.subject,
               pi.category_id, pi.subcategory_id,
               c.name AS cat_name, s.name AS sub_name
        FROM ushop_payment_invoices pi
        LEFT JOIN ushop_letter_categories    c ON c.id = pi.category_id
        LEFT JOIN ushop_letter_subcategories s ON s.id = pi.subcategory_id
        WHERE pi.id = $pi_id
    "));

    echo json_encode([
        'success' => true,
        'message' => 'Payment invoice updated successfully.',
        'data'    => $updated,
    ]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX DELETE PAYMENT INVOICE
═══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete'])) {
    $pi_id = intval($_POST['pi_id'] ?? 0);
    $ok    = false;
    if ($pi_id > 0) {
        mysqli_query($conn, "DELETE FROM ushop_payment_invoice_payments WHERE payment_invoice_id=$pi_id");
        $ok = mysqli_query($conn, "DELETE FROM ushop_payment_invoices WHERE id=$pi_id");
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => (bool)$ok]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX DELETE SINGLE PAYMENT ROW
═══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete_payment'])) {
    $pay_id = intval($_POST['pay_id'] ?? 0);
    $pi_id  = intval($_POST['pi_id']  ?? 0);
    $ok     = false;
    if ($pay_id > 0 && $pi_id > 0) {
        $chk = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM ushop_payment_invoice_payments WHERE id=$pay_id AND payment_invoice_id=$pi_id LIMIT 1"
        ));
        if ($chk) {
            $ok = mysqli_query($conn, "DELETE FROM ushop_payment_invoice_payments WHERE id=$pay_id");
        }
    }

    $agg  = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(amount),0) total_paid FROM ushop_payment_invoice_payments WHERE payment_invoice_id=$pi_id"
    ));
    $pi2  = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT total_amount FROM ushop_payment_invoices WHERE id=$pi_id"
    ));
    $total_paid   = floatval($agg['total_paid'] ?? 0);
    $total_amount = floatval($pi2['total_amount'] ?? 0);
    $balance      = $total_amount - $total_paid;

    $pr2 = mysqli_query($conn,
        "SELECT DISTINCT invoice_id FROM ushop_payment_invoice_payments WHERE payment_invoice_id=$pi_id"
    );
    $paid_now = [];
    while ($r = mysqli_fetch_assoc($pr2)) $paid_now[] = intval($r['invoice_id']);

    header('Content-Type: application/json');
    echo json_encode([
        'success'      => (bool)$ok,
        'total_paid'   => $total_paid,
        'total_amount' => $total_amount,
        'balance'      => $balance,
        'paid_inv_ids' => $paid_now,
    ]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX BULK DELETE PAYMENTS
═══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_bulk_delete_payments'])) {
    $pi_id   = intval($_POST['pi_id'] ?? 0);
    $pay_ids = array_values(array_filter(array_map('intval', $_POST['pay_ids'] ?? [])));

    $deleted = 0;
    if ($pi_id > 0 && !empty($pay_ids)) {
        $ids_sql = implode(',', $pay_ids);
        $ok = mysqli_query($conn,
            "DELETE FROM ushop_payment_invoice_payments WHERE payment_invoice_id=$pi_id AND id IN ($ids_sql)"
        );
        if ($ok) $deleted = mysqli_affected_rows($conn);
    }

    $agg  = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(amount),0) total_paid FROM ushop_payment_invoice_payments WHERE payment_invoice_id=$pi_id"
    ));
    $pi2  = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT total_amount FROM ushop_payment_invoices WHERE id=$pi_id"
    ));
    $total_paid   = floatval($agg['total_paid'] ?? 0);
    $total_amount = floatval($pi2['total_amount'] ?? 0);
    $balance      = $total_amount - $total_paid;

    $pr2 = mysqli_query($conn,
        "SELECT DISTINCT invoice_id FROM ushop_payment_invoice_payments WHERE payment_invoice_id=$pi_id"
    );
    $paid_now = [];
    while ($r = mysqli_fetch_assoc($pr2)) $paid_now[] = intval($r['invoice_id']);

    header('Content-Type: application/json');
    echo json_encode([
        'success'      => $deleted > 0,
        'deleted'      => $deleted,
        'total_paid'   => $total_paid,
        'total_amount' => $total_amount,
        'balance'      => $balance,
        'paid_inv_ids' => $paid_now,
        'message'      => "$deleted payment(s) deleted.",
    ]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   FILTERS
═══════════════════════════════════════════════════════ */
$f_date_from = trim($_GET['from']     ?? '');
$f_date_to   = trim($_GET['to']       ?? '');
$f_cat       = intval($_GET['cat']    ?? 0);
$f_subcat    = intval($_GET['subcat'] ?? 0);
$f_subject   = trim($_GET['subject']  ?? '');

$where = ['1=1'];
if ($f_date_from) $where[] = "pi.invoice_date >= '" . mysqli_real_escape_string($conn, $f_date_from) . "'";
if ($f_date_to)   $where[] = "pi.invoice_date <= '" . mysqli_real_escape_string($conn, $f_date_to) . "'";
if ($f_cat)       $where[] = "pi.category_id = $f_cat";
if ($f_subcat)    $where[] = "pi.subcategory_id = $f_subcat";
if ($f_subject)   $where[] = "pi.subject LIKE '%" . mysqli_real_escape_string($conn, $f_subject) . "%'";
$wsql = implode(' AND ', $where);

$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 25;
$offset = ($page - 1) * $per;
$total  = (int)mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM ushop_payment_invoices pi WHERE $wsql"
))['c'];
$pages  = max(1, ceil($total / $per));

$rows_res = mysqli_query($conn, "
    SELECT pi.*,
           c.name AS cat_name,
           s.name AS sub_name,
           COALESCE(paid_sub.total_paid, 0) AS total_paid
    FROM ushop_payment_invoices pi
    LEFT JOIN ushop_letter_categories    c ON c.id = pi.category_id
    LEFT JOIN ushop_letter_subcategories s ON s.id = pi.subcategory_id
    LEFT JOIN (
        SELECT payment_invoice_id, SUM(amount) AS total_paid
        FROM ushop_payment_invoice_payments
        GROUP BY payment_invoice_id
    ) paid_sub ON paid_sub.payment_invoice_id = pi.id
    WHERE $wsql
    ORDER BY pi.created_at DESC
    LIMIT $per OFFSET $offset
");

$agg = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) cnt,
           COALESCE(SUM(invoice_count),0)  sum_invs,
           COALESCE(SUM(total_discount),0) sum_disc,
           COALESCE(SUM(total_amount),0)   sum_amt
    FROM ushop_payment_invoices pi WHERE $wsql
")) ?: [];

$paid_agg = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(pip.amount),0) sum_paid
    FROM ushop_payment_invoice_payments pip
    JOIN ushop_payment_invoices pi ON pi.id = pip.payment_invoice_id
    WHERE $wsql
")) ?: [];

$cats_res   = mysqli_query($conn, "SELECT id, name FROM ushop_letter_categories ORDER BY name");
$categories = [];
while ($r = mysqli_fetch_assoc($cats_res)) $categories[] = $r;

$subcats = [];
if ($f_cat) {
    $sr = mysqli_query($conn, "SELECT id, name FROM ushop_letter_subcategories WHERE category_id=$f_cat ORDER BY name");
    while ($r = mysqli_fetch_assoc($sr)) $subcats[] = $r;
}

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.page-wrap{max-width:1500px;margin:0 auto;padding:0 8px 40px;}

.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:14px;flex-wrap:wrap;}
.breadcrumb a{color:#0e7490;text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}

.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-teal{background:#0e7490;color:#fff;}.btn-teal:hover{background:#155e75;}
.btn-green{background:#15803d;color:#fff;}.btn-green:hover{background:#166534;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-danger-soft{background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;}.btn-danger-soft:hover{background:#fecaca;}
.btn-payment{background:#7c3aed;color:#fff;}.btn-payment:hover{background:#6d28d9;}
.btn-sm{padding:5px 11px;font-size:12px;}
.btn-xs{padding:3px 8px;font-size:11px;}

.sum-cards{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:18px;}
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 20px;flex:1;min-width:130px;box-shadow:0 1px 4px rgba(0,0,0,.04);border-top:3px solid #0e7490;}
.sum-card-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.sum-card-val{font-size:20px;font-weight:700;color:#0e7490;}

.filter-bar{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;}
.fg{display:flex;flex-direction:column;gap:4px;}
.fg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;}
.fg input,.fg select{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:inherit;color:#111827;outline:none;min-width:130px;}
.fg input:focus,.fg select:focus{border-color:#0e7490;}

.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table th{padding:10px 12px;text-align:left;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:11px;text-transform:uppercase;white-space:nowrap;}
.data-table td{padding:9px 12px;border-bottom:1px solid #f3f4f6;color:#111827;vertical-align:middle;}
.data-table tbody tr:hover td{background:#f9fafb;}
.tr{text-align:right!important;}.tc{text-align:center!important;}

.sub-row{display:none;}
.sub-row.open{display:table-row;}
.sub-cell{padding:0!important;background:#f8fafc!important;}
.sub-inner{padding:14px 20px 18px 36px;border-top:2px dashed #e2e8f0;}

.mini-tbl{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:4px;}
.mini-tbl th{padding:8px 10px;background:#f1f5f9;border-bottom:1px solid #e2e8f0;color:#374151;font-size:10.5px;text-transform:uppercase;}
.mini-tbl td{padding:7px 10px;border-bottom:1px solid #f0f4f8;vertical-align:middle;}
.mini-tbl tbody tr:last-child td{border-bottom:none;}

.pay-mini-tbl{width:100%;border-collapse:collapse;font-size:11.5px;}
.pay-mini-tbl th{padding:7px 10px;background:#f5f3ff;border-bottom:1px solid #ede9fe;color:#7c3aed;font-size:10.5px;text-transform:uppercase;}
.pay-mini-tbl td{padding:6px 10px;border-bottom:1px solid #f5f3ff;vertical-align:middle;}
.pay-mini-tbl tbody tr:last-child td{border-bottom:none;}

.bal-banner{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
.bal-box{border-radius:8px;padding:9px 16px;font-size:12px;font-weight:600;min-width:120px;}
.bal-box-gray  {background:#f3f4f6;border:1px solid #e5e7eb;color:#374151;}
.bal-box-purple{background:#f5f3ff;border:1px solid #ede9fe;color:#7c3aed;}
.bal-box-green {background:#dcfce7;border:1px solid #bbf7d0;color:#15803d;}
.bal-box-red   {background:#fee2e2;border:1px solid #fca5a5;color:#dc2626;}

.section-head{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.5px;margin:0 0 8px;display:flex;align-items:center;gap:6px;}

.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.badge-teal  {background:#cffafe;color:#0e7490;}
.badge-blue  {background:#dbeafe;color:#1e40af;}
.badge-green {background:#dcfce7;color:#15803d;}
.badge-red   {background:#fee2e2;color:#dc2626;}
.badge-purple{background:#ede9fe;color:#7c3aed;}
.badge-gray  {background:#f3f4f6;color:#6b7280;}
.badge-orange{background:#fef3c7;color:#92400e;}
.badge-payroll{background:#fdf4ff;color:#9333ea;border:1px solid #e9d5ff;}

.pagination{display:flex;align-items:center;gap:6px;padding:14px 18px;justify-content:flex-end;flex-wrap:wrap;}
.pagination a,.pagination span{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;}
.pagination a{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;}.pagination a:hover{background:#e5e7eb;}
.pagination .active{background:#0e7490;color:#fff;border-color:#0e7490;}

.alert{padding:11px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:16px;}
.alert-success{background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;}
.alert-error  {background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;}

/* Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-overlay.open{opacity:1;pointer-events:all;}
.modal-box{background:#fff;border-radius:14px;width:95%;box-shadow:0 8px 40px rgba(0,0,0,.2);transform:translateY(16px);transition:transform .2s;overflow:hidden;display:flex;flex-direction:column;max-height:92vh;}
.modal-overlay.open .modal-box{transform:none;}
.modal-head{display:flex;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid #e5e7eb;flex-shrink:0;}
.modal-head-title{font-size:15px;font-weight:700;color:#111827;flex:1;}
.modal-body{padding:20px;overflow-y:auto;flex:1;}
.modal-foot{display:flex;justify-content:flex-end;gap:10px;padding:14px 20px;border-top:1px solid #f3f4f6;background:#f9fafb;flex-shrink:0;}

.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:14px;}
.form-group label{font-size:11.5px;font-weight:700;color:#374151;text-transform:uppercase;}
.form-group input,.form-group select,.form-group textarea{padding:9px 11px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;color:#111827;outline:none;}
.form-group input:focus,.form-group textarea:focus{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.1);}

/* Payroll invoice check table */
.inv-check-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.inv-check-tbl th{padding:7px 10px;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:10.5px;text-transform:uppercase;white-space:nowrap;}
.inv-check-tbl td{padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.inv-check-tbl tbody tr:hover td{background:#f9fafb;}
.inv-check-tbl .chk{width:36px;text-align:center;}
.sel-chk{width:16px;height:16px;cursor:pointer;accent-color:#7c3aed;}
.row-paid td{background:#f0fdf4!important;opacity:.75;}

/* General payment add row */
.pay-add-row{background:#f5f3ff;border:1px solid #ede9fe;border-radius:8px;padding:12px 14px;margin-bottom:14px;}
.pay-add-row .row-inner{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
.pay-add-row .fg2{display:flex;flex-direction:column;gap:4px;}
.pay-add-row .fg2 label{font-size:10.5px;font-weight:700;color:#7c3aed;text-transform:uppercase;}
.pay-add-row .fg2 input{padding:8px 10px;border:1px solid #c4b5fd;border-radius:6px;font-size:13px;font-family:inherit;color:#111827;outline:none;background:#fff;}
.pay-add-row .fg2 input:focus{border-color:#7c3aed;box-shadow:0 0 0 2px rgba(124,58,237,.15);}

/* Total paid textbox */
.total-paid-box{background:#f5f3ff;border:2px solid #c4b5fd;border-radius:8px;padding:10px 16px;font-size:18px;font-weight:800;color:#7c3aed;text-align:right;font-family:monospace;cursor:default;width:100%;}

/* Bulk delete bar */
.bulk-del-bar{display:none;background:#fee2e2;border:1px solid #fca5a5;border-radius:7px;padding:8px 14px;margin-bottom:10px;font-size:12px;color:#dc2626;align-items:center;gap:10px;flex-wrap:wrap;}
.bulk-del-bar.show{display:flex;}

/* Payment delete btn */
.btn-pay-del{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border:none;border-radius:5px;font-size:10.5px;font-weight:600;cursor:pointer;font-family:inherit;background:#fee2e2;color:#dc2626;transition:all .15s;}
.btn-pay-del:hover{background:#fca5a5;}

/* Search box */
.search-box{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #d1d5db;border-radius:7px;padding:6px 10px;margin-bottom:12px;}
.search-box i{color:#9ca3af;}
.search-box input{border:none;outline:none;font-size:12.5px;font-family:inherit;color:#111827;width:100%;background:transparent;}

/* Del confirm modals */
.del-pay-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1100;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
.del-pay-overlay.open{opacity:1;pointer-events:all;}
.del-pay-box{background:#fff;border-radius:12px;width:92%;max-width:380px;box-shadow:0 6px 30px rgba(0,0,0,.2);overflow:hidden;}
.del-pay-head{display:flex;align-items:center;gap:9px;padding:14px 18px;border-bottom:1px solid #e5e7eb;border-left:4px solid #dc2626;background:#fff5f5;}
.del-pay-body{padding:16px 18px;}
.del-pay-foot{display:flex;justify-content:flex-end;gap:8px;padding:12px 18px;border-top:1px solid #f3f4f6;background:#f9fafb;}

.del-modal-head{border-left:4px solid #dc2626;background:#fff5f5;}
.del-meta{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;}
.del-meta strong{display:block;font-size:13px;color:#111827;margin-bottom:6px;}
.del-meta-row{display:flex;justify-content:space-between;margin-top:3px;color:#6b7280;font-size:12.5px;}
.del-meta-row span:last-child{font-weight:700;color:#374151;}

/* Misc */
.spin{animation:spin .9s linear infinite;}
@keyframes spin{to{transform:rotate(360deg)}}
.expand-btn{cursor:pointer;background:none;border:none;padding:0 4px;color:#0e7490;font-size:11px;transition:transform .2s;}
.expand-btn.open{transform:rotate(90deg);}
.balance-paid{color:#15803d;font-weight:700;}
.balance-due {color:#dc2626;font-weight:700;}
.sel-strip{background:#f5f3ff;border:1px solid #ede9fe;border-radius:7px;padding:8px 14px;margin-bottom:14px;font-size:12px;color:#7c3aed;display:flex;gap:14px;flex-wrap:wrap;align-items:center;}
.pay-chk-bulk{width:15px;height:15px;cursor:pointer;accent-color:#dc2626;}
</style>

<div class="page-wrap">

<div class="breadcrumb">
  <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
  <span class="sep">›</span>
  <a href="ushop_invoice_list.php"><i class="fa-solid fa-receipt"></i> Invoices</a>
  <span class="sep">›</span>
  <a href="ushop_payment_invoice_create.php"><i class="fa-solid fa-file-invoice-dollar"></i> Create Payment Invoice</a>
  <span class="sep">›</span>
  <span style="color:#0e7490;font-weight:700;"><i class="fa-solid fa-list"></i> Payment Invoices</span>
</div>

<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;">
  <div>
    <h2 style="margin:0;font-size:19px;font-weight:700;color:#111827;"><i class="fa-solid fa-list" style="color:#0e7490;margin-right:8px;"></i>Payment Invoices</h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Click a row to expand linked invoices &amp; payment status. Use "Add Payment" to record payments.</p>
  </div>
  <a href="ushop_payment_invoice_create.php" class="btn btn-teal btn-sm"><i class="fa-solid fa-plus"></i> Create Payment Invoice</a>
</div>

<?php if (!empty($_GET['deleted'])): ?>
<div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> Payment invoice deleted successfully.</div>
<?php endif; ?>
<div class="alert alert-success" id="jsAlert" style="display:none;"></div>
<div class="alert alert-error"   id="jsError"  style="display:none;"></div>

<?php
$sum_paid    = floatval($paid_agg['sum_paid'] ?? 0);
$sum_amt     = floatval($agg['sum_amt'] ?? 0);
$sum_balance = $sum_amt - $sum_paid;
?>
<div class="sum-cards">
  <div class="sum-card"><div class="sum-card-label">Payment Invoices</div><div class="sum-card-val"><?= number_format($agg['cnt'] ?? 0) ?></div></div>
  <div class="sum-card"><div class="sum-card-label">Linked Invoices</div><div class="sum-card-val"><?= number_format($agg['sum_invs'] ?? 0) ?></div></div>
  <div class="sum-card"><div class="sum-card-label">Total Discount</div><div class="sum-card-val" style="color:#92400e;"><?= number_format($agg['sum_disc'] ?? 0, 2) ?></div></div>
  <div class="sum-card"><div class="sum-card-label">Total Amount</div><div class="sum-card-val" style="color:#15803d;"><?= number_format($sum_amt, 2) ?></div></div>
  <div class="sum-card" style="border-top-color:#7c3aed;"><div class="sum-card-label">Total Paid</div><div class="sum-card-val" style="color:#7c3aed;"><?= number_format($sum_paid, 2) ?></div></div>
  <div class="sum-card" style="border-top-color:<?= $sum_balance > 0 ? '#dc2626' : '#15803d' ?>;"><div class="sum-card-label">Balance Due</div><div class="sum-card-val" style="color:<?= $sum_balance > 0 ? '#dc2626' : '#15803d' ?>;"><?= number_format($sum_balance, 2) ?></div></div>
</div>

<form method="GET" class="filter-bar">
  <div class="fg"><label>Date From</label><input type="date" name="from" value="<?= htmlspecialchars($f_date_from) ?>"></div>
  <div class="fg"><label>Date To</label><input type="date" name="to" value="<?= htmlspecialchars($f_date_to) ?>"></div>
  <div class="fg"><label>Subject</label><input type="text" name="subject" value="<?= htmlspecialchars($f_subject) ?>" placeholder="Search subject…"></div>
  <div class="fg">
    <label>Category</label>
    <select name="cat" onchange="this.form.submit()">
      <option value="">— All —</option>
      <?php foreach($categories as $cat): ?>
      <option value="<?= $cat['id'] ?>" <?= $f_cat == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if($f_cat && $subcats): ?>
  <div class="fg">
    <label>Sub-category</label>
    <select name="subcat">
      <option value="">— All —</option>
      <?php foreach($subcats as $s): ?>
      <option value="<?= $s['id'] ?>" <?= $f_subcat == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <input type="hidden" name="page" value="1">
  <div style="display:flex;gap:6px;align-items:flex-end;">
    <button type="submit" class="btn btn-teal btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
    <a href="ushop_payment_invoice_list.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
  </div>
</form>

<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title">
      <i class="fa-solid fa-file-invoice-dollar" style="margin-right:6px;color:#0e7490;"></i>Payment Invoices
      <span style="font-size:11px;color:#9ca3af;font-weight:400;margin-left:8px;" id="totalLabel"><?= number_format($total) ?> record<?= $total != 1 ? 's' : '' ?></span>
    </div>
    <span style="font-size:11.5px;color:#6b7280;"><i class="fa-solid fa-circle-info"></i> Click row to expand</span>
  </div>

  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th style="width:32px;"></th>
        <th>#</th>
        <th>Invoice No</th>
        <th>Invoice Date</th>
        <th>Subject</th>
        <th>Category</th>
        <th>Sub-category</th>
        <th class="tc">Inv. Count</th>
        <th class="tr">Total Discount</th>
        <th class="tr">Total Amount</th>
        <th class="tr">Total Paid</th>
        <th class="tr">Balance</th>
        <th>Created At</th>
        <th class="tc">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php $i = $offset + 1; while ($r = mysqli_fetch_assoc($rows_res)):
      $tpaid = floatval($r['total_paid']);
      $tamt  = floatval($r['total_amount']);
      $tbal  = $tamt - $tpaid;
      $full  = ($tbal <= 0.001);
      $is_payroll_row = strtolower(trim($r['cat_name'] ?? '')) === 'payroll';
    ?>
    <tr style="cursor:pointer;" onclick="toggleRow(<?= $r['id'] ?>)" id="mainRow<?= $r['id'] ?>">
      <td class="tc">
        <button class="expand-btn" id="expBtn<?= $r['id'] ?>" onclick="event.stopPropagation();toggleRow(<?= $r['id'] ?>)">
          <i class="fa-solid fa-chevron-right"></i>
        </button>
      </td>
      <td style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
      <td>
        <?= !empty($r['invoice_no'])
            ? '<span class="badge badge-purple" style="font-family:monospace;font-size:11px;">'.htmlspecialchars($r['invoice_no']).'</span>'
            : '<span style="color:#d1d5db;">—</span>' ?>
      </td>
      <td><span class="badge badge-teal"><?= date('d M Y', strtotime($r['invoice_date'])) ?></span></td>
      <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($r['subject']) ?>">
        <?= $r['subject'] ? htmlspecialchars($r['subject']) : '<span style="color:#d1d5db;font-size:11px;">—</span>' ?>
      </td>
      <td>
        <?php if ($r['cat_name']): ?>
          <?php if ($is_payroll_row): ?>
            <span class="badge badge-payroll"><i class="fa-solid fa-users" style="margin-right:3px;font-size:10px;"></i><?= htmlspecialchars($r['cat_name']) ?></span>
          <?php else: ?>
            <span class="badge badge-blue"><?= htmlspecialchars($r['cat_name']) ?></span>
          <?php endif; ?>
        <?php else: ?>
          <span style="color:#d1d5db;">—</span>
        <?php endif; ?>
      </td>
      <td><?= $r['sub_name'] ? '<span class="badge badge-purple">'.htmlspecialchars($r['sub_name']).'</span>' : '<span style="color:#d1d5db;">—</span>' ?></td>
      <td class="tc"><span class="badge badge-teal"><?= number_format($r['invoice_count']) ?></span></td>
      <td class="tr" style="color:#92400e;font-weight:600;"><?= number_format($r['total_discount'], 2) ?></td>
      <td class="tr" style="color:#15803d;font-weight:700;"><?= number_format($tamt, 2) ?></td>
      <td class="tr" id="paidCell<?= $r['id'] ?>"><span class="balance-paid"><?= number_format($tpaid, 2) ?></span></td>
      <td class="tr" id="balCell<?= $r['id'] ?>">
        <?php if ($full): ?>
          <span class="badge badge-green"><i class="fa-solid fa-check"></i> Paid</span>
        <?php else: ?>
          <span class="balance-due"><?= number_format($tbal, 2) ?></span>
        <?php endif; ?>
      </td>
      <td style="color:#6b7280;font-size:11px;"><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td>
      <td class="tc" onclick="event.stopPropagation();" style="white-space:nowrap;">
        <button class="btn btn-payment btn-xs" style="margin-bottom:3px;"
                onclick="openPaymentModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['invoice_no'] ?: 'PI-'.$r['id'])) ?>', <?= $is_payroll_row ? 'true' : 'false' ?>)">
          <i class="fa-solid fa-circle-dollar-to-slot"></i> Add Payment
        </button><br>
        <button class="btn btn-teal btn-xs" style="margin-bottom:3px;"
                onclick="openEditModal(<?= $r['id'] ?>, '<?= date('Y-m-d', strtotime($r['invoice_date'])) ?>', '<?= htmlspecialchars(addslashes($r['payment_month'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($r['subject'] ?? '')) ?>', <?= $r['category_id'] ? intval($r['category_id']) : 0 ?>, <?= $r['subcategory_id'] ? intval($r['subcategory_id']) : 0 ?>)">
          <i class="fa-solid fa-pen-to-square"></i> Edit
        </button><br>
        <button class="btn btn-danger-soft btn-xs"
                onclick="openDeleteModal(<?= $r['id'] ?>, '<?= date('d M Y', strtotime($r['invoice_date'])) ?>',
                '<?= addslashes(htmlspecialchars($r['subject'] ?: '(no subject)')) ?>',
                <?= $r['invoice_count'] ?>, '<?= number_format($tamt, 2) ?>')">
          <i class="fa-solid fa-trash"></i> Delete
        </button>
      </td>
    </tr>
    <tr class="sub-row" id="subRow<?= $r['id'] ?>">
      <td class="sub-cell" colspan="14">
        <div class="sub-inner" id="subContent<?= $r['id'] ?>">
          <span style="color:#9ca3af;font-size:12px;"><i class="fa-solid fa-spinner spin"></i> Loading…</span>
        </div>
      </td>
    </tr>
    <?php endwhile; ?>
    <?php if ($total === 0): ?>
    <tr>
      <td colspan="14" style="text-align:center;padding:48px;color:#9ca3af;font-size:13px;">
        <i class="fa-solid fa-inbox" style="font-size:28px;display:block;margin-bottom:10px;opacity:.35;"></i>
        No payment invoices found.
      </td>
    </tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pagination">
    <span style="font-size:12px;color:#6b7280;margin-right:6px;">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page > 1): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">&laquo;</a>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&lsaquo;</a>
    <?php endif; ?>
    <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?>
      <?php if ($p === $page): ?><span class="active"><?= $p ?></span>
      <?php else: ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $pages): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">&rsaquo;</a>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $pages])) ?>">&raquo;</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
</div><!-- /page-wrap -->

<!-- ════════════════════════════════════════════════════
     ADD PAYMENT MODAL (UNIFIED — Payroll vs General)
════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="paymentModal">
  <div class="modal-box" style="max-width:820px;">
    <div class="modal-head" style="background:#f5f3ff;border-bottom-color:#ede9fe;">
      <i class="fa-solid fa-circle-dollar-to-slot" style="color:#7c3aed;font-size:18px;"></i>
      <span class="modal-head-title" id="pmTitle">Add Payment</span>
      <span id="pmInvNoLabel" style="font-size:11px;color:#9ca3af;font-family:monospace;margin-left:6px;"></span>
      <button type="button" onclick="closePaymentModal()" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:18px;margin-left:auto;"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">

      <!-- Balance Banner -->
      <div class="bal-banner" id="pmBanner">
        <div class="bal-box bal-box-gray"><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;margin-bottom:2px;">Invoice Total</div><strong id="pmTotal">0.00</strong></div>
        <div class="bal-box bal-box-purple"><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;margin-bottom:2px;">Already Paid</div><strong id="pmPaidSoFar">0.00</strong></div>
        <div class="bal-box bal-box-red" id="pmBalBox"><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;margin-bottom:2px;">Balance Due</div><strong id="pmBalance">0.00</strong></div>
      </div>

      <!-- ══ PAYROLL SECTION ══ -->
      <div id="pmPayrollSection" style="display:none;">
        <div class="section-head" style="margin-bottom:8px;">
          <i class="fa-solid fa-users" style="color:#9333ea;"></i>
          <span style="color:#9333ea;">Payroll — Select Invoices to Mark as Paid</span>
          <span class="badge badge-payroll" style="margin-left:4px;">Payroll Mode</span>
        </div>

        <!-- Search -->
        <div class="search-box" style="margin-bottom:10px;">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" id="pmInvSearch" placeholder="Search by employee name, doc no, code…" oninput="filterPmInvoices(this.value)">
          <button type="button" onclick="document.getElementById('pmInvSearch').value='';filterPmInvoices('');" style="background:none;border:none;cursor:pointer;color:#9ca3af;padding:0;"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div style="overflow-x:auto;margin-bottom:14px;">
          <table class="inv-check-tbl">
            <thead>
              <tr>
                <th class="chk"><input type="checkbox" id="pmSelectAll" class="sel-chk" onchange="pmToggleAll(this.checked)" title="Select all unpaid"></th>
                <th>#</th>
                <th>Doc No</th>
                <th>Employee / Customer</th>
                <th>Date</th>
                <th class="tr" style="text-align:right;">Amount</th>
                <th class="tc">Status</th>
              </tr>
            </thead>
            <tbody id="pmInvBody">
              <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:20px;"><i class="fa-solid fa-spinner spin"></i> Loading…</td></tr>
            </tbody>
            <tfoot id="pmInvFoot"></tfoot>
          </table>
        </div>

        <!-- Selection strip -->
        <div class="sel-strip" id="pmSelStrip" style="display:none;">
          <span><i class="fa-solid fa-check-square"></i> <strong id="pmSelCount">0</strong> invoice(s) selected</span>
          <span>|</span>
          <span>Selected Amount: <strong id="pmSelAmt">0.00</strong></span>
        </div>

        <!-- Total paid display box -->
        <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin-bottom:4px;">
          <div style="flex:1;min-width:160px;">
            <label style="font-size:10.5px;font-weight:700;color:#374151;text-transform:uppercase;display:block;margin-bottom:4px;">Paid Date <span style="color:#dc2626;">*</span></label>
            <input type="date" id="pmPaidDate" style="padding:9px 11px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;color:#111827;outline:none;width:100%;" required>
          </div>
          <div style="flex:2;min-width:200px;">
            <label style="font-size:10.5px;font-weight:700;color:#374151;text-transform:uppercase;display:block;margin-bottom:4px;">Remark <span style="color:#9ca3af;font-size:10px;text-transform:none;">(optional)</span></label>
            <input type="text" id="pmRemark" placeholder="Optional note…" style="padding:9px 11px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;color:#111827;outline:none;width:100%;">
          </div>
          <div style="flex:1;min-width:160px;">
            <label style="font-size:10.5px;font-weight:700;color:#7c3aed;text-transform:uppercase;display:block;margin-bottom:4px;"><i class="fa-solid fa-sigma"></i> Total Paid (Selected)</label>
            <div class="total-paid-box" id="pmTotalPaidBox">0.00</div>
          </div>
        </div>
      </div>

      <!-- ══ GENERAL (NON-PAYROLL) SECTION ══ -->
      <div id="pmGeneralSection" style="display:none;">
        <div class="section-head" style="margin-bottom:12px;">
          <i class="fa-solid fa-circle-dollar-to-slot" style="color:#7c3aed;"></i> Add Payment Entry
        </div>

        <!-- Add payment row -->
        <div class="pay-add-row">
          <div class="row-inner">
            <div class="fg2" style="flex:1;min-width:140px;">
              <label>Paid Date <span style="color:#dc2626;">*</span></label>
              <input type="date" id="genPaidDate">
            </div>
            <div class="fg2" style="flex:1;min-width:130px;">
              <label>Amount <span style="color:#dc2626;">*</span></label>
              <input type="number" id="genAmount" min="0.01" step="0.01" placeholder="0.00">
            </div>
            <div class="fg2" style="flex:2;min-width:180px;">
              <label>Remark <span style="color:#9ca3af;font-size:10px;text-transform:none;">(optional)</span></label>
              <input type="text" id="genRemark" placeholder="Optional note…">
            </div>
            <div class="fg2" style="min-width:100px;">
              <label style="visibility:hidden;">Add</label>
              <button type="button" class="btn btn-payment btn-sm" onclick="addGeneralPayment()">
                <i class="fa-solid fa-plus"></i> Add
              </button>
            </div>
          </div>
        </div>

        <!-- Balance display -->
        <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
          <div style="flex:1;min-width:130px;">
            <label style="font-size:10.5px;font-weight:700;color:#374151;text-transform:uppercase;display:block;margin-bottom:4px;"><i class="fa-solid fa-sigma"></i> Total Paid</label>
            <div class="total-paid-box" id="genTotalPaidBox" style="font-size:15px;background:#f5f3ff;">0.00</div>
          </div>
          <div style="flex:1;min-width:130px;" id="genBalanceWrap">
            <label style="font-size:10.5px;font-weight:700;color:#374151;text-transform:uppercase;display:block;margin-bottom:4px;"><i class="fa-solid fa-scale-balanced"></i> Balance</label>
            <div id="genBalanceBox" style="border-radius:8px;padding:10px 16px;font-size:15px;font-weight:800;font-family:monospace;background:#fee2e2;border:2px solid #fca5a5;color:#dc2626;">0.00</div>
          </div>
        </div>
      </div>

      <!-- ══ PAYMENT HISTORY (shared) ══ -->
      <div id="pmPayHistWrap" style="display:none;margin-top:8px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px;">
          <div class="section-head" style="color:#7c3aed;margin:0;">
            <i class="fa-solid fa-clock-rotate-left"></i> Payment History
          </div>
          <!-- Bulk delete bar -->
          <div id="pmBulkDelBar" class="bulk-del-bar" style="margin-bottom:0;padding:5px 10px;">
            <span><strong id="pmBulkSelCount">0</strong> selected</span>
            <button type="button" class="btn btn-danger btn-xs" onclick="bulkDeletePayments()">
              <i class="fa-solid fa-trash"></i> Delete Selected
            </button>
            <button type="button" class="btn btn-secondary btn-xs" onclick="clearPayBulkSel()">Cancel</button>
          </div>
        </div>
        <table class="pay-mini-tbl">
          <thead>
            <tr>
              <th style="width:32px;"><input type="checkbox" class="pay-chk-bulk" id="pmPaySelAll" onchange="togglePayBulkAll(this.checked)" title="Select all"></th>
              <th>#</th>
              <th>Paid Date</th>
              <th>Doc No / Ref</th>
              <th>Customer</th>
              <th style="text-align:right;">Amount</th>
              <th>Remark</th>
              <th class="tc">Del</th>
            </tr>
          </thead>
          <tbody id="pmPayHistBody"></tbody>
          <tfoot id="pmPayHistFoot"></tfoot>
        </table>
      </div>

    </div><!-- /modal-body -->
    <div class="modal-foot">
      <button type="button" class="btn btn-secondary" onclick="closePaymentModal()"><i class="fa-solid fa-xmark"></i> Close</button>
      <!-- Payroll save btn -->
      <button type="button" class="btn btn-payment" id="pmSaveBtn" onclick="savePayrollPayment()" style="display:none;">
        <i class="fa-solid fa-floppy-disk"></i> Mark as Paid
      </button>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════
     DELETE SINGLE PAYMENT CONFIRM
════════════════════════════════════════════════════ -->
<div class="del-pay-overlay" id="delPayModal">
  <div class="del-pay-box">
    <div class="del-pay-head">
      <i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;font-size:16px;"></i>
      <span style="font-size:14px;font-weight:700;color:#111827;">Delete Payment</span>
      <button type="button" onclick="closeDelPayModal()" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:16px;margin-left:auto;"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="del-pay-body">
      <p style="font-size:12.5px;color:#374151;margin:0 0 10px;">Remove this payment record? This cannot be undone.</p>
      <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:7px;padding:10px 12px;font-size:12px;color:#374151;">
        <div style="display:flex;justify-content:space-between;margin-bottom:3px;"><span style="color:#6b7280;">Ref:</span> <strong id="dpDocNo">—</strong></div>
        <div style="display:flex;justify-content:space-between;margin-bottom:3px;"><span style="color:#6b7280;">Paid Date:</span> <strong id="dpPaidDate">—</strong></div>
        <div style="display:flex;justify-content:space-between;"><span style="color:#6b7280;">Amount:</span> <strong id="dpAmount" style="color:#7c3aed;">—</strong></div>
      </div>
    </div>
    <div class="del-pay-foot">
      <button type="button" class="btn btn-secondary btn-sm" onclick="closeDelPayModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button type="button" class="btn btn-danger btn-sm" id="dpConfirmBtn" onclick="confirmDelPayment()"><i class="fa-solid fa-trash"></i> Delete</button>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════
     DELETE PAYMENT INVOICE MODAL
════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal-box" style="max-width:430px;">
    <div class="modal-head del-modal-head">
      <i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;font-size:18px;"></i>
      <span class="modal-head-title">Delete Payment Invoice</span>
      <button type="button" onclick="closeDeleteModal()" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:18px;margin-left:auto;"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:13px;color:#374151;margin:0 0 12px;">Are you sure? All payment records will also be removed. This cannot be undone.</p>
      <div class="del-meta">
        <strong><i class="fa-solid fa-file-invoice-dollar" style="color:#0e7490;margin-right:5px;"></i> Details</strong>
        <div class="del-meta-row"><span>Date:</span><span id="delDate"></span></div>
        <div class="del-meta-row"><span>Subject:</span><span id="delSubject"></span></div>
        <div class="del-meta-row"><span>Linked Invoices:</span><span id="delInvCount"></span></div>
        <div class="del-meta-row"><span>Total Amount:</span><span id="delAmount"></span></div>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button type="button" class="btn btn-danger" id="confirmDeleteBtn" onclick="confirmDelete()"><i class="fa-solid fa-trash"></i> Yes, Delete</button>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════
     EDIT PAYMENT INVOICE MODAL
     (Month & Year, Invoice Date, Subject, Category, Sub-category)
════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box" style="max-width:520px;">
    <div class="modal-head">
      <i class="fa-solid fa-pen-to-square" style="color:#0e7490;font-size:18px;"></i>
      <span class="modal-head-title">Edit Payment Invoice</span>
      <button type="button" onclick="closeEditModal()" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:18px;margin-left:auto;"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="modal-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div class="form-group">
          <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;display:block;margin-bottom:5px;">Month &amp; Year *</label>
          <input type="month" id="editMonthYear" required style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:inherit;" />
        </div>
        <div class="form-group">
          <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;display:block;margin-bottom:5px;">Invoice Date *</label>
          <input type="date" id="editDate" required style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:inherit;" />
        </div>
        <div class="form-group" style="grid-column:1 / -1;">
          <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;display:block;margin-bottom:5px;">Subject / Memo</label>
          <input type="text" id="editSubject" placeholder="e.g., Monthly Payment" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:inherit;" />
        </div>
        <div class="form-group">
          <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;display:block;margin-bottom:5px;">Category</label>
          <select id="editCategory" onchange="onEditCategoryChange()" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:inherit;">
            <option value="">— Select Category —</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" id="editSubCatWrap" style="display:none;">
          <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;display:block;margin-bottom:5px;">Sub-Category</label>
          <select id="editSubCategory" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:inherit;">
            <option value="">— Select Sub-category —</option>
          </select>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-secondary" onclick="closeEditModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button type="button" class="btn btn-teal" id="saveEditBtn" onclick="saveEditInvoice()"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
    </div>
  </div>
</div>

<script>
/* ═══════════════════ STATE ═══════════════════ */
const openRows   = {};
let deletePiId   = null;
let editPiId     = null;
let pmPiId       = null;
let pmIsPayroll  = false;
let pmInvoices   = [];
const pmSelected = new Set();

let dpPayId   = null;
let dpPiIdCtx = null;
let dpSubPiId = null;

/* bulk delete state */
const pmPayBulkSel = new Set();

/* ═══════════════════ EXPAND ROW ═══════════════════ */
async function toggleRow(id) {
  const sub  = document.getElementById('subRow'     + id);
  const btn  = document.getElementById('expBtn'     + id);
  const cont = document.getElementById('subContent' + id);
  if (openRows[id]) {
    sub.classList.remove('open');
    btn.classList.remove('open');
    openRows[id] = false;
    return;
  }
  sub.classList.add('open');
  btn.classList.add('open');
  openRows[id] = true;
  if (cont.dataset.loaded) return;
  cont.dataset.loaded = '1';
  const res  = await fetch('ushop_payment_invoice_list.php?ajax=get_invoices&pi_id=' + id);
  const data = await res.json();
  cont.innerHTML = buildSubContent(id, data);
}

function buildSubContent(piId, data) {
  const invoices  = data.invoices    || [];
  const paidIds   = new Set((data.paid_inv_ids || []).map(Number));
  const totalPaid = parseFloat(data.total_paid   || 0);
  const totalAmt  = parseFloat(data.total_amount || 0);
  const balance   = parseFloat(data.balance      || 0);
  const isFull    = balance <= 0.001;
  const isPayroll = data.is_payroll;

  const banner = `<div class="bal-banner">
    <div class="bal-box bal-box-gray"><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;margin-bottom:1px;">Invoice Total</div><strong>${fmtNum(totalAmt)}</strong></div>
    <div class="bal-box bal-box-purple"><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;margin-bottom:1px;">Total Paid</div><strong>${fmtNum(totalPaid)}</strong></div>
    <div class="bal-box ${isFull ? 'bal-box-green' : 'bal-box-red'}"><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;margin-bottom:1px;">Balance</div><strong>${isFull ? '✓ Fully Paid' : fmtNum(balance)}</strong></div>
  </div>`;

  let invTable = '';
  if (isPayroll && invoices.length) {
    let invRows = '', sumAmt = 0, sumDisc = 0;
    invoices.forEach((r, i) => {
      const amt  = parseFloat(r.total_amount  || 0);
      const disc = parseFloat(r.total_discount || 0);
      sumAmt += amt; sumDisc += disc;
      const paid = paidIds.has(Number(r.id));
      const statusBadge = paid
        ? `<span class="badge badge-green"><i class="fa-solid fa-circle-check"></i> Paid</span>`
        : `<span class="badge badge-red"><i class="fa-solid fa-circle-xmark"></i> Unpaid</span>`;
      invRows += `<tr ${paid ? 'style="background:#f0fdf4;"' : ''}>
        <td style="color:#9ca3af;font-size:11px;">${i+1}</td>
        <td>${statusBadge}</td>
        <td><span class="badge badge-teal">${fmtDate(r.invoice_date)}</span></td>
        <td style="font-weight:700;color:#0e7490;">${escHtml(r.doc_no || '—')}</td>
        <td style="font-size:11px;font-family:monospace;color:#6b7280;">${escHtml(r.unique_inv_no || '—')}</td>
        <td>${r.customer_name ? escHtml(r.customer_name) : '<span style="color:#9ca3af;font-size:11px;">Walk-in</span>'}</td>
        <td>${r.customer_code ? '<span class="badge badge-blue">#'+escHtml(r.customer_code)+'</span>' : '<span style="color:#d1d5db;">—</span>'}</td>
        <td style="text-align:right;color:#92400e;">${fmtNum(disc)}</td>
        <td style="text-align:right;font-weight:700;color:#15803d;">${fmtNum(amt)}</td>
      </tr>`;
    });
    invTable = `<div class="section-head" style="margin-bottom:8px;color:#9333ea;"><i class="fa-solid fa-users"></i> Payroll Invoices</div>
      <table class="mini-tbl">
        <thead><tr><th>#</th><th>Status</th><th>Date</th><th>Doc No</th><th>Unique Inv No</th><th>Employee</th><th>Code</th><th style="text-align:right;">Discount</th><th style="text-align:right;">Amount</th></tr></thead>
        <tbody>${invRows}</tbody>
        <tfoot><tr style="background:#f1f5f9;font-weight:700;">
          <td colspan="7" style="padding:7px 10px;font-size:11.5px;color:#374151;"><i class="fa-solid fa-sigma" style="color:#0e7490;margin-right:4px;"></i> Totals (${invoices.length})</td>
          <td style="text-align:right;padding:7px 10px;color:#92400e;">${fmtNum(sumDisc)}</td>
          <td style="text-align:right;padding:7px 10px;color:#15803d;">${fmtNum(sumAmt)}</td>
        </tr></tfoot>
      </table>`;
  }

  const paySection = `<div style="margin-top:16px;">
    <div class="section-head" style="color:#7c3aed;margin-bottom:8px;"><i class="fa-solid fa-clock-rotate-left"></i> Payment History</div>
    <div id="payHist_${piId}"><span style="color:#9ca3af;font-size:12px;"><i class="fa-solid fa-spinner spin"></i> Loading…</span></div>
  </div>`;

  setTimeout(() => loadSubPayHistory(piId), 60);
  return banner + invTable + paySection;
}

async function loadSubPayHistory(piId) {
  const el = document.getElementById('payHist_' + piId);
  if (!el) return;
  const res  = await fetch('ushop_payment_invoice_list.php?ajax=get_payments&pi_id=' + piId);
  const pays = await res.json();
  if (!pays.length) {
    el.innerHTML = '<p style="color:#9ca3af;font-size:12px;margin:2px 0;"><i class="fa-solid fa-ban" style="margin-right:5px;"></i>No payments recorded yet.</p>';
    return;
  }
  let total = 0;
  const rows = pays.map((p, i) => {
    total += parseFloat(p.amount || 0);
    return `<tr id="subPayRow_${p.id}">
      <td style="color:#9ca3af;font-size:11px;">${i+1}</td>
      <td><span class="badge badge-teal">${fmtDate(p.paid_date)}</span></td>
      <td style="font-weight:700;color:#0e7490;">${escHtml(p.doc_no || '—')}</td>
      <td>${p.customer_name ? escHtml(p.customer_name) : '<span style="color:#9ca3af;font-size:11px;">—</span>'}</td>
      <td style="text-align:right;font-weight:700;color:#7c3aed;">${fmtNum(p.amount)}</td>
      <td style="color:#6b7280;font-size:11.5px;">${p.remark ? escHtml(p.remark) : '<span style="color:#d1d5db;">—</span>'}</td>
      <td class="tc"><button class="btn-pay-del" onclick="openDelPayModal(${p.id},${piId},'${escJs(p.doc_no||'—')}','${escJs(p.paid_date)}','${fmtNum(p.amount)}','sub')"><i class="fa-solid fa-trash"></i></button></td>
    </tr>`;
  }).join('');
  el.innerHTML = `<table class="pay-mini-tbl">
    <thead><tr><th>#</th><th>Paid Date</th><th>Doc No</th><th>Customer</th><th style="text-align:right;">Amount</th><th>Remark</th><th class="tc">Del</th></tr></thead>
    <tbody id="subPayBody_${piId}">${rows}</tbody>
    <tfoot id="subPayFoot_${piId}"><tr style="background:#f5f3ff;font-weight:700;">
      <td colspan="4" style="padding:6px 10px;color:#7c3aed;font-size:11.5px;"><i class="fa-solid fa-sigma" style="margin-right:4px;"></i> Total Paid</td>
      <td style="text-align:right;padding:6px 10px;color:#7c3aed;" id="subPayTotal_${piId}">${fmtNum(total)}</td>
      <td></td><td></td>
    </tr></tfoot>
  </table>`;
}

/* ═══════════════════ PAYMENT MODAL ═══════════════════ */
async function openPaymentModal(piId, invoiceNo, isPayroll) {
  pmPiId      = piId;
  pmIsPayroll = isPayroll;
  pmSelected.clear();
  pmInvoices  = [];
  pmPayBulkSel.clear();

  document.getElementById('pmTitle').textContent     = isPayroll ? 'Add Payment — Payroll' : 'Add Payment';
  document.getElementById('pmInvNoLabel').textContent = invoiceNo ? '(' + invoiceNo + ')' : '';
  document.getElementById('pmPayHistWrap').style.display = 'none';
  document.getElementById('pmPayrollSection').style.display = isPayroll ? '' : 'none';
  document.getElementById('pmGeneralSection').style.display = isPayroll ? 'none' : '';
  document.getElementById('pmSaveBtn').style.display = isPayroll ? '' : 'none';

  /* Reset payroll fields */
  if (isPayroll) {
    document.getElementById('pmPaidDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('pmRemark').value   = '';
    document.getElementById('pmSelectAll').checked = false;
    document.getElementById('pmSelStrip').style.display = 'none';
    document.getElementById('pmTotalPaidBox').textContent = '0.00';
    document.getElementById('pmInvSearch').value = '';
    document.getElementById('pmInvBody').innerHTML =
      '<tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:20px;"><i class="fa-solid fa-spinner spin"></i> Loading…</td></tr>';
  } else {
    document.getElementById('genPaidDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('genAmount').value   = '';
    document.getElementById('genRemark').value   = '';
  }

  document.getElementById('paymentModal').classList.add('open');

  /* Load data */
  const res  = await fetch('ushop_payment_invoice_list.php?ajax=get_invoices&pi_id=' + piId);
  const data = await res.json();

  pmInvoices = data.invoices || [];
  const paidIds   = new Set((data.paid_inv_ids || []).map(Number));
  const totalPaid = parseFloat(data.total_paid   || 0);
  const totalAmt  = parseFloat(data.total_amount || 0);
  const balance   = parseFloat(data.balance      || 0);

  /* Update banner */
  document.getElementById('pmTotal').textContent      = fmtNum(totalAmt);
  document.getElementById('pmPaidSoFar').textContent  = fmtNum(totalPaid);
  document.getElementById('pmBalance').textContent    = fmtNum(balance);
  document.getElementById('pmBalBox').className = 'bal-box ' + (balance <= 0.001 ? 'bal-box-green' : 'bal-box-red');

  if (isPayroll) {
    renderPmInvRows(paidIds);
  } else {
    document.getElementById('genTotalPaidBox').textContent = fmtNum(totalPaid);
    updateGenBalanceBox(balance);
  }

  loadPmPayHistory(piId);
}

/* ── Payroll: render invoice rows ── */
function renderPmInvRows(paidIdsSet, filterText) {
  const tbody = document.getElementById('pmInvBody');
  const tfoot = document.getElementById('pmInvFoot');
  filterText = (filterText || '').toLowerCase().trim();

  if (!pmInvoices.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:16px;">No linked invoices found.</td></tr>';
    tfoot.innerHTML = '';
    return;
  }

  let sumAmt = 0, visCount = 0;
  let rows = pmInvoices.map((r, i) => {
    const amt    = parseFloat(r.total_amount || 0);
    const isPaid = paidIdsSet ? paidIdsSet.has(Number(r.id)) : (r.is_paid == 1);
    sumAmt += amt;

    /* Filter */
    if (filterText) {
      const haystack = ((r.doc_no||'')+(r.customer_name||'')+(r.customer_code||'')+(r.unique_inv_no||'')).toLowerCase();
      if (!haystack.includes(filterText)) return '';
    }
    visCount++;

    const statusBadge = isPaid
      ? `<span class="badge badge-green"><i class="fa-solid fa-circle-check"></i> Paid</span>`
      : `<span class="badge badge-red"><i class="fa-solid fa-circle-xmark"></i> Unpaid</span>`;
    const chkChecked  = pmSelected.has(Number(r.id)) && !isPaid ? 'checked' : '';
    const rowCls      = isPaid ? 'class="row-paid"' : '';

    return `<tr ${rowCls} id="pmrow_${r.id}">
      <td class="chk"><input type="checkbox" class="sel-chk pm-inv-chk" data-id="${r.id}" data-amt="${amt}"
        ${chkChecked} ${isPaid ? 'disabled' : ''} onchange="pmOnCheck(this)"></td>
      <td style="color:#9ca3af;font-size:11px;">${i+1}</td>
      <td style="font-weight:700;color:#0e7490;">${escHtml(r.doc_no || '—')}</td>
      <td>${r.customer_name ? escHtml(r.customer_name) : '<span style="color:#9ca3af;font-size:11px;">Walk-in</span>'}</td>
      <td><span class="badge badge-teal">${fmtDate(r.invoice_date)}</span></td>
      <td style="text-align:right;font-weight:700;color:#15803d;">${fmtNum(amt)}</td>
      <td class="tc">${statusBadge}</td>
    </tr>`;
  }).filter(Boolean).join('');

  if (!visCount && filterText) {
    rows = `<tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:14px;"><i class="fa-solid fa-search" style="margin-right:6px;"></i>No results for "${escHtml(filterText)}"</td></tr>`;
  }

  tbody.innerHTML = rows;
  tfoot.innerHTML = `<tr style="background:#f9fafb;font-weight:700;">
    <td></td><td colspan="4" style="padding:7px 10px;font-size:11.5px;color:#374151;">
      <i class="fa-solid fa-sigma" style="color:#0e7490;margin-right:4px;"></i> Total (${pmInvoices.length} invoice${pmInvoices.length!==1?'s':''})
    </td>
    <td style="text-align:right;padding:7px 10px;color:#15803d;">${fmtNum(sumAmt)}</td>
    <td></td>
  </tr>`;

  pmUpdateSelStrip();
}

function filterPmInvoices(val) {
  const paidSet = new Set(pmInvoices.filter(r => r.is_paid == 1).map(r => Number(r.id)));
  renderPmInvRows(paidSet, val);
}

function pmOnCheck(chk) {
  const id = Number(chk.dataset.id);
  if (chk.checked) pmSelected.add(id);
  else             pmSelected.delete(id);
  pmUpdateSelectAll();
  pmUpdateSelStrip();
}

function pmToggleAll(checked) {
  const filterText = (document.getElementById('pmInvSearch').value || '').toLowerCase().trim();
  pmInvoices.forEach(r => {
    if (r.is_paid == 1) return;
    /* only affect visible rows when filtering */
    if (filterText) {
      const hay = ((r.doc_no||'')+(r.customer_name||'')+(r.customer_code||'')+(r.unique_inv_no||'')).toLowerCase();
      if (!hay.includes(filterText)) return;
    }
    const id  = Number(r.id);
    if (checked) pmSelected.add(id);
    else         pmSelected.delete(id);
    const chk = document.querySelector(`.pm-inv-chk[data-id="${id}"]`);
    if (chk) chk.checked = checked;
  });
  pmUpdateSelStrip();
}

function pmUpdateSelectAll() {
  const unpaid = pmInvoices.filter(r => r.is_paid != 1);
  const allChk = unpaid.length > 0 && unpaid.every(r => pmSelected.has(Number(r.id)));
  document.getElementById('pmSelectAll').checked = allChk;
}

function pmUpdateSelStrip() {
  const strip   = document.getElementById('pmSelStrip');
  const box     = document.getElementById('pmTotalPaidBox');
  const selInvs = pmInvoices.filter(r => pmSelected.has(Number(r.id)));
  if (selInvs.length === 0) {
    strip.style.display = 'none';
    box.textContent = '0.00';
    return;
  }
  strip.style.display = '';
  document.getElementById('pmSelCount').textContent = selInvs.length;
  const selAmt = selInvs.reduce((s, r) => s + parseFloat(r.total_amount || 0), 0);
  document.getElementById('pmSelAmt').textContent = fmtNum(selAmt);
  box.textContent = fmtNum(selAmt);
}

/* ── General: add payment ── */
async function addGeneralPayment() {
  const paidDate = document.getElementById('genPaidDate').value;
  const amount   = parseFloat(document.getElementById('genAmount').value || 0);
  const remark   = document.getElementById('genRemark').value.trim();

  if (!paidDate)    { showAlert('error', 'Please select a paid date.'); return; }
  if (amount <= 0)  { showAlert('error', 'Please enter a valid payment amount.'); return; }

  const fd = new FormData();
  fd.append('ajax_add_payment', '1');
  fd.append('pi_id',    pmPiId);
  fd.append('paid_date', paidDate);
  fd.append('amount',   amount);
  fd.append('remark',   remark);

  const res  = await fetch('ushop_payment_invoice_list.php', { method:'POST', body:fd });
  const data = await res.json();

  if (data.success) {
    showAlert('success', '<i class="fa-solid fa-check-circle"></i> ' + data.message);
    document.getElementById('genAmount').value = '';
    document.getElementById('genRemark').value = '';

    /* Update totals */
    document.getElementById('pmPaidSoFar').textContent      = fmtNum(data.total_paid);
    document.getElementById('pmBalance').textContent        = fmtNum(data.balance);
    document.getElementById('pmBalBox').className           = 'bal-box ' + (data.balance <= 0.001 ? 'bal-box-green' : 'bal-box-red');
    document.getElementById('genTotalPaidBox').textContent  = fmtNum(data.total_paid);
    updateGenBalanceBox(data.balance);

    /* Update main row */
    updateMainRowCells(pmPiId, data.total_paid, data.balance);

    /* Reload payment history */
    loadPmPayHistory(pmPiId);

    /* Refresh sub-row */
    refreshSubRow(pmPiId);
  } else {
    showAlert('error', data.message || 'Failed to add payment.');
  }
}

function updateGenBalanceBox(balance) {
  const box = document.getElementById('genBalanceBox');
  box.textContent = fmtNum(balance);
  if (balance <= 0.001) {
    box.style.background = '#dcfce7';
    box.style.borderColor = '#bbf7d0';
    box.style.color = '#15803d';
  } else {
    box.style.background = '#fee2e2';
    box.style.borderColor = '#fca5a5';
    box.style.color = '#dc2626';
  }
}

/* ── Payroll: save ── */
async function savePayrollPayment() {
  const paidDate = document.getElementById('pmPaidDate').value;
  const remark   = document.getElementById('pmRemark').value.trim();

  if (!paidDate)           { showAlert('error', 'Please select a paid date.'); return; }
  if (pmSelected.size === 0){ showAlert('error', 'Please select at least one invoice to mark as paid.'); return; }

  const btn = document.getElementById('pmSaveBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

  const fd = new FormData();
  fd.append('ajax_mark_paid', '1');
  fd.append('pi_id',    pmPiId);
  fd.append('paid_date', paidDate);
  fd.append('remark',   remark);
  pmSelected.forEach(id => fd.append('invoice_ids[]', id));

  const res  = await fetch('ushop_payment_invoice_list.php', { method:'POST', body:fd });
  const data = await res.json();

  btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Mark as Paid';

  if (data.success) {
    showAlert('success', '<i class="fa-solid fa-check-circle"></i> ' + data.message);

    updateMainRowCells(pmPiId, data.total_paid, data.balance);

    document.getElementById('pmPaidSoFar').textContent = fmtNum(data.total_paid);
    document.getElementById('pmBalance').textContent   = fmtNum(data.balance);
    document.getElementById('pmBalBox').className = 'bal-box ' + (data.balance <= 0.001 ? 'bal-box-green' : 'bal-box-red');

    const newPaidSet = new Set((data.paid_inv_ids || []).map(Number));
    pmInvoices.forEach(r => { r.is_paid = newPaidSet.has(Number(r.id)) ? 1 : 0; });
    pmSelected.clear();
    document.getElementById('pmSelectAll').checked = false;
    renderPmInvRows(newPaidSet, document.getElementById('pmInvSearch').value);

    loadPmPayHistory(pmPiId);
    refreshSubRow(pmPiId);
  } else {
    showAlert('error', data.message || 'An error occurred.');
  }
}

/* ── Load payment history in modal ── */
async function loadPmPayHistory(piId) {
  const wrap = document.getElementById('pmPayHistWrap');
  const body = document.getElementById('pmPayHistBody');
  const foot = document.getElementById('pmPayHistFoot');
  pmPayBulkSel.clear();
  updateBulkDelBar();

  const res  = await fetch('ushop_payment_invoice_list.php?ajax=get_payments&pi_id=' + piId);
  const pays = await res.json();

  if (!pays.length) { wrap.style.display = 'none'; return; }
  wrap.style.display = '';

  let total = 0;
  body.innerHTML = pays.map((p, i) => {
    total += parseFloat(p.amount || 0);
    return `<tr id="pmPayRow_${p.id}">
      <td><input type="checkbox" class="pay-chk-bulk" data-pay-id="${p.id}" onchange="onPayBulkChk(this)"></td>
      <td style="color:#9ca3af;font-size:11px;">${i+1}</td>
      <td><span class="badge badge-teal">${fmtDate(p.paid_date)}</span></td>
      <td style="font-weight:700;color:#0e7490;">${escHtml(p.doc_no || '—')}</td>
      <td>${p.customer_name ? escHtml(p.customer_name) : '<span style="color:#9ca3af;font-size:11px;">—</span>'}</td>
      <td style="text-align:right;font-weight:700;color:#7c3aed;">${fmtNum(p.amount)}</td>
      <td style="color:#6b7280;font-size:11.5px;">${p.remark ? escHtml(p.remark) : '<span style="color:#d1d5db;">—</span>'}</td>
      <td class="tc"><button class="btn-pay-del" onclick="openDelPayModal(${p.id},${piId},'${escJs(p.doc_no||'—')}','${escJs(p.paid_date)}','${fmtNum(p.amount)}','modal')"><i class="fa-solid fa-trash"></i></button></td>
    </tr>`;
  }).join('');

  foot.innerHTML = `<tr style="background:#f5f3ff;font-weight:700;">
    <td></td><td colspan="3" style="padding:6px 10px;color:#7c3aed;font-size:11.5px;"><i class="fa-solid fa-sigma" style="margin-right:4px;"></i> Total Paid</td>
    <td style="text-align:right;padding:6px 10px;color:#7c3aed;" id="pmPayTotal">${fmtNum(total)}</td>
    <td></td><td></td><td></td>
  </tr>`;

  document.getElementById('pmPaySelAll').checked = false;
}

/* ── Bulk delete payments ── */
function onPayBulkChk(chk) {
  const id = Number(chk.dataset.payId);
  if (chk.checked) pmPayBulkSel.add(id);
  else             pmPayBulkSel.delete(id);
  updateBulkDelBar();
  /* Update select-all state */
  const allChks = document.querySelectorAll('#pmPayHistBody .pay-chk-bulk');
  document.getElementById('pmPaySelAll').checked = allChks.length > 0 && [...allChks].every(c => c.checked);
}

function togglePayBulkAll(checked) {
  document.querySelectorAll('#pmPayHistBody .pay-chk-bulk').forEach(chk => {
    chk.checked = checked;
    const id = Number(chk.dataset.payId);
    if (checked) pmPayBulkSel.add(id);
    else         pmPayBulkSel.delete(id);
  });
  updateBulkDelBar();
}

function updateBulkDelBar() {
  const bar   = document.getElementById('pmBulkDelBar');
  const count = document.getElementById('pmBulkSelCount');
  if (pmPayBulkSel.size > 0) {
    bar.classList.add('show');
    count.textContent = pmPayBulkSel.size;
  } else {
    bar.classList.remove('show');
  }
}

function clearPayBulkSel() {
  pmPayBulkSel.clear();
  document.querySelectorAll('#pmPayHistBody .pay-chk-bulk').forEach(c => c.checked = false);
  document.getElementById('pmPaySelAll').checked = false;
  updateBulkDelBar();
}

async function bulkDeletePayments() {
  if (pmPayBulkSel.size === 0) return;
  if (!confirm(`Delete ${pmPayBulkSel.size} payment(s)? This cannot be undone.`)) return;

  const fd = new FormData();
  fd.append('ajax_bulk_delete_payments', '1');
  fd.append('pi_id', pmPiId);
  pmPayBulkSel.forEach(id => fd.append('pay_ids[]', id));

  const res  = await fetch('ushop_payment_invoice_list.php', { method:'POST', body:fd });
  const data = await res.json();

  if (data.success) {
    showAlert('success', `<i class="fa-solid fa-check-circle"></i> ${data.message}`);

    /* Update banner + main row */
    document.getElementById('pmPaidSoFar').textContent = fmtNum(data.total_paid);
    document.getElementById('pmBalance').textContent   = fmtNum(data.balance);
    document.getElementById('pmBalBox').className = 'bal-box ' + (data.balance <= 0.001 ? 'bal-box-green' : 'bal-box-red');
    updateMainRowCells(pmPiId, data.total_paid, data.balance);

    if (!pmIsPayroll) {
      document.getElementById('genTotalPaidBox').textContent = fmtNum(data.total_paid);
      updateGenBalanceBox(data.balance);
    } else {
      const newPaidSet = new Set((data.paid_inv_ids || []).map(Number));
      pmInvoices.forEach(r => { r.is_paid = newPaidSet.has(Number(r.id)) ? 1 : 0; });
      renderPmInvRows(newPaidSet, document.getElementById('pmInvSearch').value);
    }

    pmPayBulkSel.clear();
    loadPmPayHistory(pmPiId);
    refreshSubRow(pmPiId);
  } else {
    showAlert('error', 'Bulk delete failed. Please try again.');
  }
}

function closePaymentModal() {
  document.getElementById('paymentModal').classList.remove('open');
  pmPiId = null;
  pmSelected.clear();
  pmInvoices = [];
  pmPayBulkSel.clear();
}

/* ═══════════════════ DEL SINGLE PAYMENT ═══════════════════ */
function openDelPayModal(payId, piId, docNo, paidDate, amount, context) {
  dpPayId   = payId;
  dpPiIdCtx = piId;
  dpSubPiId = context === 'sub' ? piId : null;
  document.getElementById('dpDocNo').textContent    = docNo;
  document.getElementById('dpPaidDate').textContent = fmtDate(paidDate);
  document.getElementById('dpAmount').textContent   = amount;
  document.getElementById('delPayModal').classList.add('open');
}
function closeDelPayModal() {
  document.getElementById('delPayModal').classList.remove('open');
  dpPayId = dpPiIdCtx = dpSubPiId = null;
}

async function confirmDelPayment() {
  if (!dpPayId) return;
  const btn = document.getElementById('dpConfirmBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

  const fd = new FormData();
  fd.append('ajax_delete_payment', '1');
  fd.append('pay_id', dpPayId);
  fd.append('pi_id',  dpPiIdCtx);

  const res  = await fetch('ushop_payment_invoice_list.php', { method:'POST', body:fd });
  const data = await res.json();

  btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete';

  if (data.success) {
    closeDelPayModal();

    document.getElementById('pmPayRow_' + dpPayId)?.remove();
    document.getElementById('subPayRow_' + dpPayId)?.remove();

    /* Banner */
    document.getElementById('pmPaidSoFar').textContent = fmtNum(data.total_paid);
    document.getElementById('pmBalance').textContent   = fmtNum(data.balance);
    document.getElementById('pmBalBox').className = 'bal-box ' + (data.balance <= 0.001 ? 'bal-box-green' : 'bal-box-red');

    /* Pay total in footer */
    const pmPayTot  = document.getElementById('pmPayTotal');
    if (pmPayTot)  pmPayTot.textContent = fmtNum(data.total_paid);
    const subPayTot = document.getElementById('subPayTotal_' + dpPiIdCtx);
    if (subPayTot) subPayTot.textContent = fmtNum(data.total_paid);

    /* Main row */
    updateMainRowCells(dpPiIdCtx, data.total_paid, data.balance);

    /* General box */
    if (!pmIsPayroll && pmPiId === dpPiIdCtx) {
      document.getElementById('genTotalPaidBox').textContent = fmtNum(data.total_paid);
      updateGenBalanceBox(data.balance);
    }

    /* Payroll: re-render checkboxes */
    if (pmIsPayroll && pmPiId === dpPiIdCtx && pmInvoices.length) {
      const newPaidSet = new Set((data.paid_inv_ids || []).map(Number));
      pmInvoices.forEach(r => { r.is_paid = newPaidSet.has(Number(r.id)) ? 1 : 0; });
      renderPmInvRows(newPaidSet, document.getElementById('pmInvSearch')?.value || '');
    }

    /* Sub-row refresh */
    if (dpSubPiId && openRows[dpSubPiId]) refreshSubRow(dpSubPiId);

    showAlert('success', '<i class="fa-solid fa-check-circle"></i> Payment deleted successfully.');
  } else {
    showAlert('error', 'Failed to delete payment. Please try again.');
  }
}

/* ═══════════════════ EDIT MODAL ═══════════════════ */
async function openEditModal(id, invDate, monthYear, subject, catId, subId) {
  editPiId = id;

  document.getElementById('editDate').value      = invDate || '';
  document.getElementById('editMonthYear').value = monthYear || '';
  document.getElementById('editSubject').value   = subject || '';
  document.getElementById('editCategory').value  = catId ? String(catId) : '';

  const wrap = document.getElementById('editSubCatWrap');
  const sel  = document.getElementById('editSubCategory');
  sel.innerHTML = '<option value="">— Select Sub-category —</option>';
  wrap.style.display = 'none';

  if (catId) {
    await loadEditSubcats(catId, subId);
  }

  document.getElementById('editModal').classList.add('open');
}

function closeEditModal() {
  document.getElementById('editModal').classList.remove('open');
  editPiId = null;
}

async function onEditCategoryChange() {
  const catId = document.getElementById('editCategory').value;
  const wrap  = document.getElementById('editSubCatWrap');
  const sel   = document.getElementById('editSubCategory');
  sel.innerHTML = '<option value="">— Select Sub-category —</option>';
  wrap.style.display = 'none';
  if (!catId) return;
  await loadEditSubcats(catId, null);
}

async function loadEditSubcats(catId, selectSubId) {
  const wrap = document.getElementById('editSubCatWrap');
  const sel  = document.getElementById('editSubCategory');
  try {
    const res  = await fetch('ushop_payment_invoice_create.php?ajax=subcats&cat_id=' + catId);
    const subs = await res.json();
    sel.innerHTML = '<option value="">— Select Sub-category —</option>';
    if (subs.length > 0) {
      subs.forEach(s => {
        const o = document.createElement('option');
        o.value = s.id; o.textContent = s.name;
        if (selectSubId && String(s.id) === String(selectSubId)) o.selected = true;
        sel.appendChild(o);
      });
      wrap.style.display = '';
    }
  } catch (e) {
    console.error('Failed to load sub-categories', e);
  }
}

async function saveEditInvoice() {
  if (!editPiId) return;

  const monthYear = document.getElementById('editMonthYear').value;
  const invDate   = document.getElementById('editDate').value;
  const subject   = document.getElementById('editSubject').value.trim();
  const catId     = document.getElementById('editCategory').value;
  const subId     = document.getElementById('editSubCategory').value;

  if (!monthYear) { showAlert('error', 'Please select a month and year.'); return; }
  if (!invDate)   { showAlert('error', 'Please select an invoice date.'); return; }

  const btn = document.getElementById('saveEditBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

  const fd = new FormData();
  fd.append('ajax_edit_save',    '1');
  fd.append('pi_id',             editPiId);
  fd.append('pi_month_year',     monthYear);
  fd.append('pi_date',           invDate);
  fd.append('pi_subject',        subject);
  fd.append('pi_category_id',    catId);
  fd.append('pi_subcategory_id', subId);

  const res  = await fetch('ushop_payment_invoice_list.php', { method: 'POST', body: fd });
  const data = await res.json();

  btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';

  if (data.success) {
    closeEditModal();
    showAlert('success', '<i class="fa-solid fa-check-circle"></i> ' + data.message);
    setTimeout(() => location.reload(), 1500);
  } else {
    showAlert('error', data.message || 'Failed to update payment invoice.');
  }
}

/* ═══════════════════ DELETE MODAL ═══════════════════ */
function openDeleteModal(id, date, subject, invCount, amount) {
  deletePiId = id;
  document.getElementById('delDate').textContent     = date;
  document.getElementById('delSubject').textContent  = subject;
  document.getElementById('delInvCount').textContent = invCount + ' invoice' + (invCount!=1?'s':'');
  document.getElementById('delAmount').textContent   = amount;
  document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() {
  document.getElementById('deleteModal').classList.remove('open');
  deletePiId = null;
}
async function confirmDelete() {
  if (!deletePiId) return;
  const btn = document.getElementById('confirmDeleteBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';
  const fd = new FormData();
  fd.append('ajax_delete', '1');
  fd.append('pi_id', deletePiId);
  const res  = await fetch('ushop_payment_invoice_list.php', { method:'POST', body:fd });
  const data = await res.json();
  btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-trash"></i> Yes, Delete';
  if (data.success) {
    closeDeleteModal();
    document.getElementById('mainRow' + deletePiId)?.remove();
    document.getElementById('subRow'  + deletePiId)?.remove();
    showAlert('success', '<i class="fa-solid fa-check-circle"></i> Payment invoice deleted. Linked invoices are now available again.');
    const lbl = document.getElementById('totalLabel');
    if (lbl) { const n = Math.max(0,(parseInt(lbl.textContent)||0)-1); lbl.textContent = n+' record'+(n!==1?'s':''); }
  } else {
    showAlert('error', 'Delete failed. Please try again.');
  }
}

/* ═══════════════════ HELPERS ═══════════════════ */
function updateMainRowCells(piId, totalPaid, balance) {
  const paidCell = document.getElementById('paidCell' + piId);
  const balCell  = document.getElementById('balCell'  + piId);
  if (paidCell) paidCell.innerHTML = `<span class="balance-paid">${fmtNum(totalPaid)}</span>`;
  if (balCell)  balCell.innerHTML  = balance <= 0.001
    ? `<span class="badge badge-green"><i class="fa-solid fa-check"></i> Paid</span>`
    : `<span class="balance-due">${fmtNum(balance)}</span>`;
}

function refreshSubRow(piId) {
  const cont = document.getElementById('subContent' + piId);
  if (cont && openRows[piId]) {
    delete cont.dataset.loaded;
    openRows[piId] = false;
    document.getElementById('subRow' + piId)?.classList.remove('open');
    document.getElementById('expBtn' + piId)?.classList.remove('open');
    setTimeout(() => toggleRow(piId), 150);
  }
}

function fmtDate(d) {
  if (!d) return '—';
  return new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' });
}
function fmtNum(n) {
  return parseFloat(n||0).toLocaleString('en-US', { minimumFractionDigits:2, maximumFractionDigits:2 });
}
function escHtml(s) {
  const d = document.createElement('div'); d.textContent = s; return d.innerHTML;
}
function escJs(s) {
  return String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'");
}
function showAlert(type, msg) {
  const el = document.getElementById(type==='success' ? 'jsAlert' : 'jsError');
  el.innerHTML = msg; el.style.display = 'block';
  window.scrollTo({ top:0, behavior:'smooth' });
  setTimeout(() => el.style.display = 'none', 5000);
}

/* Close modals on overlay click */
document.getElementById('paymentModal').addEventListener('click', e => { if(e.target===e.currentTarget) closePaymentModal(); });
document.getElementById('deleteModal' ).addEventListener('click', e => { if(e.target===e.currentTarget) closeDeleteModal();  });
document.getElementById('editModal'   ).addEventListener('click', e => { if(e.target===e.currentTarget) closeEditModal();     });
document.getElementById('delPayModal' ).addEventListener('click', e => { if(e.target===e.currentTarget) closeDelPayModal();  });
</script>

<?php include 'footer.php'; ?>