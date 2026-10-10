<?php
include 'config.php';

/* ═══════════════════════════════════════════════════════
   AUTO-CREATE BACK OFFICE PAYMENTS TABLE
   This is SEPARATE from ushop_payment_invoice_payments.
   One payment invoice can have multiple back-office
   payment entries (date + amount + remark).
═══════════════════════════════════════════════════════ */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `ushop_backoffice_payments` (
      `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `payment_invoice_id` INT UNSIGNED NOT NULL COMMENT 'FK → ushop_payment_invoices.id',
      `payment_date`       DATE         NOT NULL,
      `amount`             DECIMAL(15,2) NOT NULL DEFAULT 0,
      `remark`             VARCHAR(500)  NOT NULL DEFAULT '',
      `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_pi` (`payment_invoice_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ═══════════════════════════════════════════════════════
   AJAX — GET LINKED INVOICES FOR A PAYMENT INVOICE
═══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_invoices') {
    $pi_id = intval($_GET['pi_id'] ?? 0);
    $pirow = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT invoice_ids, total_amount, total_discount FROM ushop_payment_invoices WHERE id=$pi_id"
    ));
    $ids = [];
    if ($pirow && $pirow['invoice_ids']) {
        $ids = array_values(array_filter(array_map('intval', explode(',', $pirow['invoice_ids']))));
    }
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
        while ($r = mysqli_fetch_assoc($res)) $invoices[] = $r;
    }
    /* Back-office paid total */
    $bop = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(amount),0) total_paid FROM ushop_backoffice_payments WHERE payment_invoice_id=$pi_id"
    ));
    $total_paid   = floatval($bop['total_paid']);
    $total_amount = floatval($pirow['total_amount'] ?? 0);
    $balance      = $total_amount - $total_paid;

    header('Content-Type: application/json');
    echo json_encode([
        'invoices'     => $invoices,
        'total_paid'   => $total_paid,
        'total_amount' => $total_amount,
        'balance'      => $balance,
    ]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX — GET BACK OFFICE PAYMENTS FOR A PAYMENT INVOICE
═══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_bo_payments') {
    $pi_id = intval($_GET['pi_id'] ?? 0);
    $res   = mysqli_query($conn, "
        SELECT id, payment_date, amount, remark, created_at
        FROM ushop_backoffice_payments
        WHERE payment_invoice_id=$pi_id
        ORDER BY payment_date ASC, id ASC
    ");
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX — ADD BACK OFFICE PAYMENT
═══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_add_payment'])) {
    $pi_id   = intval($_POST['pi_id']        ?? 0);
    $date    = trim($_POST['payment_date']   ?? '');
    $amount  = floatval($_POST['amount']     ?? 0);
    $remark  = trim($_POST['remark']         ?? '');

    if (!$pi_id || !$date || $amount <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Payment invoice, date, and a positive amount are required.']);
        exit;
    }

    /* Verify PI exists */
    $pirow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, total_amount FROM ushop_payment_invoices WHERE id=$pi_id"));
    if (!$pirow) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Payment invoice not found.']);
        exit;
    }

    $date_e   = mysqli_real_escape_string($conn, $date);
    $remark_e = mysqli_real_escape_string($conn, $remark);

    mysqli_query($conn, "
        INSERT INTO ushop_backoffice_payments (payment_invoice_id, payment_date, amount, remark, created_at)
        VALUES ($pi_id, '$date_e', $amount, '$remark_e', NOW())
    ");
    $new_id = mysqli_insert_id($conn);

    /* Recalculate */
    $agg = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(amount),0) total_paid FROM ushop_backoffice_payments WHERE payment_invoice_id=$pi_id"
    ));
    $total_paid   = floatval($agg['total_paid']);
    $total_amount = floatval($pirow['total_amount']);
    $balance      = $total_amount - $total_paid;

    header('Content-Type: application/json');
    echo json_encode([
        'success'      => true,
        'new_id'       => $new_id,
        'total_paid'   => $total_paid,
        'total_amount' => $total_amount,
        'balance'      => $balance,
        'message'      => 'Payment added successfully.',
    ]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX — DELETE BACK OFFICE PAYMENT
═══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete_bo_payment'])) {
    $pay_id = intval($_POST['pay_id'] ?? 0);
    $pi_id  = intval($_POST['pi_id']  ?? 0);
    $ok     = false;

    if ($pay_id > 0 && $pi_id > 0) {
        $chk = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM ushop_backoffice_payments WHERE id=$pay_id AND payment_invoice_id=$pi_id LIMIT 1"
        ));
        if ($chk) $ok = (bool)mysqli_query($conn, "DELETE FROM ushop_backoffice_payments WHERE id=$pay_id");
    }

    /* Recalculate */
    $agg = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(amount),0) total_paid FROM ushop_backoffice_payments WHERE payment_invoice_id=$pi_id"
    ));
    $pi2 = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT total_amount FROM ushop_payment_invoices WHERE id=$pi_id"
    ));
    $total_paid   = floatval($agg['total_paid'] ?? 0);
    $total_amount = floatval($pi2['total_amount'] ?? 0);
    $balance      = $total_amount - $total_paid;

    header('Content-Type: application/json');
    echo json_encode([
        'success'      => $ok,
        'total_paid'   => $total_paid,
        'total_amount' => $total_amount,
        'balance'      => $balance,
    ]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   FILTERS
═══════════════════════════════════════════════════════ */
$f_date_from = trim($_GET['from']    ?? '');
$f_date_to   = trim($_GET['to']      ?? '');
$f_cat       = intval($_GET['cat']   ?? 0);
$f_subcat    = intval($_GET['subcat']?? 0);
$f_subject   = trim($_GET['subject'] ?? '');
$f_status    = trim($_GET['status']  ?? ''); // 'paid' | 'partial' | 'unpaid' | ''

$where = ['1=1'];
if ($f_date_from) $where[] = "pi.invoice_date >= '" . mysqli_real_escape_string($conn, $f_date_from) . "'";
if ($f_date_to)   $where[] = "pi.invoice_date <= '" . mysqli_real_escape_string($conn, $f_date_to) . "'";
if ($f_cat)       $where[] = "pi.category_id = $f_cat";
if ($f_subcat)    $where[] = "pi.subcategory_id = $f_subcat";
if ($f_subject)   $where[] = "pi.subject LIKE '%" . mysqli_real_escape_string($conn, $f_subject) . "%'";

/* Status filter applied in HAVING */
$having = '';
if ($f_status === 'paid')    $having = 'HAVING (pi.total_amount - COALESCE(bop.total_paid,0)) <= 0.001';
if ($f_status === 'partial') $having = 'HAVING (pi.total_amount - COALESCE(bop.total_paid,0)) > 0.001 AND COALESCE(bop.total_paid,0) > 0';
if ($f_status === 'unpaid')  $having = 'HAVING COALESCE(bop.total_paid,0) = 0';

$wsql = implode(' AND ', $where);

/* Pagination */
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 25;

/* Count with having */
$count_q = "SELECT COUNT(*) c FROM (
    SELECT pi.id
    FROM ushop_payment_invoices pi
    LEFT JOIN (SELECT payment_invoice_id, SUM(amount) total_paid FROM ushop_backoffice_payments GROUP BY payment_invoice_id) bop
        ON bop.payment_invoice_id = pi.id
    WHERE $wsql
    $having
) sub";
$total  = (int)(mysqli_fetch_assoc(mysqli_query($conn, $count_q))['c'] ?? 0);
$pages  = max(1, ceil($total / $per));
$offset = ($page - 1) * $per;

$rows_res = mysqli_query($conn, "
    SELECT pi.*,
           c.name  AS cat_name,
           s.name  AS sub_name,
           COALESCE(bop.total_paid, 0) AS bo_total_paid
    FROM ushop_payment_invoices pi
    LEFT JOIN ushop_letter_categories    c ON c.id = pi.category_id
    LEFT JOIN ushop_letter_subcategories s ON s.id = pi.subcategory_id
    LEFT JOIN (
        SELECT payment_invoice_id, SUM(amount) AS total_paid
        FROM ushop_backoffice_payments
        GROUP BY payment_invoice_id
    ) bop ON bop.payment_invoice_id = pi.id
    WHERE $wsql
    $having
    ORDER BY pi.invoice_date DESC, pi.id DESC
    LIMIT $per OFFSET $offset
");

/* Summary totals (filtered) */
$agg = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) cnt,
           COALESCE(SUM(pi.total_amount),0)   sum_amt,
           COALESCE(SUM(bop.total_paid),0)    sum_bopaid
    FROM ushop_payment_invoices pi
    LEFT JOIN (SELECT payment_invoice_id, SUM(amount) total_paid FROM ushop_backoffice_payments GROUP BY payment_invoice_id) bop
        ON bop.payment_invoice_id = pi.id
    WHERE $wsql
")) ?: [];

/* Categories for filter */
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
.page-wrap{max-width:1540px;margin:0 auto;padding:0 8px 48px;}

/* ── Breadcrumb ── */
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:14px;flex-wrap:wrap;}
.breadcrumb a{color:#0e7490;text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-teal      {background:#0e7490;color:#fff;}.btn-teal:hover{background:#155e75;}
.btn-emerald   {background:#059669;color:#fff;}.btn-emerald:hover{background:#047857;}
.btn-secondary {background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-danger    {background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-danger-soft{background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;}.btn-danger-soft:hover{background:#fecaca;}
.btn-indigo    {background:#4f46e5;color:#fff;}.btn-indigo:hover{background:#4338ca;}
.btn-sm{padding:5px 12px;font-size:12px;}
.btn-xs{padding:3px 9px;font-size:11px;}

/* ── Summary cards ── */
.sum-cards{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:18px;}
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 20px;flex:1;min-width:130px;box-shadow:0 1px 4px rgba(0,0,0,.04);border-top:3px solid #0e7490;}
.sum-card-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.sum-card-val{font-size:20px;font-weight:700;color:#0e7490;}

/* ── Filter bar ── */
.filter-bar{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;}
.fg{display:flex;flex-direction:column;gap:4px;}
.fg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;}
.fg input,.fg select{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:inherit;color:#111827;outline:none;min-width:130px;}
.fg input:focus,.fg select:focus{border-color:#0e7490;}

/* ── Table ── */
.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table th{padding:10px 12px;text-align:left;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:11px;text-transform:uppercase;white-space:nowrap;}
.data-table td{padding:9px 12px;border-bottom:1px solid #f3f4f6;color:#111827;vertical-align:middle;}
.data-table tbody tr.main-row:hover td{background:#f9fafb;}
.tr{text-align:right!important;}.tc{text-align:center!important;}

/* ── Expand sub-row ── */
.sub-row{display:none;}
.sub-row.open{display:table-row;}
.sub-cell{padding:0!important;background:#f8fafc!important;}
.sub-inner{padding:16px 24px 22px 40px;border-top:2px dashed #e2e8f0;}

/* ── Section heading ── */
.sec-head{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.5px;margin:0 0 8px;display:flex;align-items:center;gap:6px;}

/* ── Mini table (linked invoices) ── */
.mini-tbl{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:4px;}
.mini-tbl th{padding:8px 10px;background:#f1f5f9;border-bottom:1px solid #e2e8f0;color:#374151;font-size:10.5px;text-transform:uppercase;}
.mini-tbl td{padding:7px 10px;border-bottom:1px solid #f0f4f8;vertical-align:middle;}
.mini-tbl tbody tr:last-child td{border-bottom:none;}

/* ── Back Office payment table ── */
.bop-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.bop-tbl th{padding:8px 10px;background:#ecfdf5;border-bottom:1px solid #a7f3d0;color:#065f46;font-size:10.5px;text-transform:uppercase;}
.bop-tbl td{padding:7px 10px;border-bottom:1px solid #f0fdf4;vertical-align:middle;}
.bop-tbl tbody tr:last-child td{border-bottom:none;}

/* ── Balance strip ── */
.bal-banner{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
.bal-box{border-radius:8px;padding:9px 16px;font-size:12px;font-weight:600;min-width:130px;}
.bal-box-gray  {background:#f3f4f6;border:1px solid #e5e7eb;color:#374151;}
.bal-box-green {background:#dcfce7;border:1px solid #bbf7d0;color:#15803d;}
.bal-box-emerald{background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;}
.bal-box-red   {background:#fee2e2;border:1px solid #fca5a5;color:#dc2626;}
.bal-box-orange{background:#fef3c7;border:1px solid #fde68a;color:#92400e;}

/* ── Badges ── */
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.badge-teal    {background:#cffafe;color:#0e7490;}
.badge-blue    {background:#dbeafe;color:#1e40af;}
.badge-green   {background:#dcfce7;color:#15803d;}
.badge-red     {background:#fee2e2;color:#dc2626;}
.badge-purple  {background:#ede9fe;color:#7c3aed;}
.badge-orange  {background:#fef3c7;color:#92400e;}
.badge-gray    {background:#f3f4f6;color:#6b7280;}
.badge-emerald {background:#d1fae5;color:#065f46;}

/* ── Status badge in row ── */
.status-paid    {background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;}
.status-partial {background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.status-unpaid  {background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;}

/* ── Pagination ── */
.pagination{display:flex;align-items:center;gap:6px;padding:14px 18px;justify-content:flex-end;flex-wrap:wrap;}
.pagination a,.pagination span{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;}
.pagination a{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;}.pagination a:hover{background:#e5e7eb;}
.pagination .active{background:#0e7490;color:#fff;border-color:#0e7490;}

/* ── Alerts ── */
.alert{padding:11px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:16px;}
.alert-success{background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;}
.alert-error  {background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;}

/* ── Modals ── */
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
.form-group input:focus,.form-group textarea:focus{border-color:#059669;box-shadow:0 0 0 3px rgba(5,150,105,.1);}
.form-group textarea{resize:vertical;min-height:60px;}

/* ── Delete mini modal ── */
.del-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1100;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
.del-overlay.open{opacity:1;pointer-events:all;}
.del-box{background:#fff;border-radius:12px;width:92%;max-width:380px;box-shadow:0 6px 30px rgba(0,0,0,.2);overflow:hidden;}
.del-head{display:flex;align-items:center;gap:9px;padding:14px 18px;border-bottom:1px solid #e5e7eb;border-left:4px solid #dc2626;background:#fff5f5;}
.del-body{padding:16px 18px;}
.del-foot{display:flex;justify-content:flex-end;gap:8px;padding:12px 18px;border-top:1px solid #f3f4f6;background:#f9fafb;}

/* ── Add-payment row inside sub ── */
.add-pay-row{background:#f0fdf4;border:1px dashed #6ee7b7;border-radius:8px;padding:12px 16px;margin-top:12px;}
.add-pay-row .row-inputs{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
.add-pay-row .row-inputs .fg{flex:1;min-width:130px;}

/* ── Delete btn (payment row) ── */
.btn-bop-del{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border:none;border-radius:5px;font-size:10.5px;font-weight:600;cursor:pointer;font-family:inherit;background:#fee2e2;color:#dc2626;transition:all .15s;}
.btn-bop-del:hover{background:#fca5a5;}

.spin{animation:spin .9s linear infinite;}
@keyframes spin{to{transform:rotate(360deg)}}
.expand-btn{cursor:pointer;background:none;border:none;padding:0 4px;color:#0e7490;font-size:11px;transition:transform .2s;}
.expand-btn.open{transform:rotate(90deg);}
.balance-paid{color:#15803d;font-weight:700;}
.balance-due {color:#dc2626;font-weight:700;}
.paid-full   {color:#059669;}
</style>

<div class="page-wrap">

<div class="breadcrumb">
  <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
  <span class="sep">›</span>
  <a href="ushop_payment_invoice_list.php"><i class="fa-solid fa-list"></i> Payment Invoices</a>
  <span class="sep">›</span>
  <span style="color:#059669;font-weight:700;"><i class="fa-solid fa-scale-balanced"></i> Back Office Reconciliation</span>
</div>

<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;">
  <div>
    <h2 style="margin:0;font-size:19px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-scale-balanced" style="color:#059669;margin-right:8px;"></i>Back Office Payment Reconciliation
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">
      Record back-office payments against payment invoices. Payments here are <strong>independent</strong> of the Payment Invoice module records.
    </p>
  </div>
  <a href="ushop_payment_invoice_list.php" class="btn btn-secondary btn-sm">
    <i class="fa-solid fa-arrow-left"></i> Payment Invoices
  </a>
</div>

<div class="alert alert-success" id="jsAlert" style="display:none;"></div>
<div class="alert alert-error"   id="jsError"  style="display:none;"></div>

<!-- Summary Cards -->
<?php
$sum_amt    = floatval($agg['sum_amt']    ?? 0);
$sum_bopaid = floatval($agg['sum_bopaid'] ?? 0);
$sum_bal    = $sum_amt - $sum_bopaid;
$cnt        = intval($agg['cnt'] ?? 0);
?>
<div class="sum-cards">
  <div class="sum-card" style="border-top-color:#0e7490;">
    <div class="sum-card-label">Payment Invoices</div>
    <div class="sum-card-val"><?= number_format($cnt) ?></div>
  </div>
  <div class="sum-card" style="border-top-color:#15803d;">
    <div class="sum-card-label">Total Invoice Amount</div>
    <div class="sum-card-val" style="color:#15803d;"><?= number_format($sum_amt, 2) ?></div>
  </div>
  <div class="sum-card" style="border-top-color:#059669;">
    <div class="sum-card-label">Total BO Paid</div>
    <div class="sum-card-val" style="color:#059669;"><?= number_format($sum_bopaid, 2) ?></div>
  </div>
  <div class="sum-card" style="border-top-color:<?= $sum_bal > 0.001 ? '#dc2626' : '#059669' ?>;">
    <div class="sum-card-label">Outstanding Balance</div>
    <div class="sum-card-val" style="color:<?= $sum_bal > 0.001 ? '#dc2626' : '#059669' ?>;"><?= number_format($sum_bal, 2) ?></div>
  </div>
</div>

<!-- Filters -->
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
  <div class="fg">
    <label>Status</label>
    <select name="status">
      <option value="">— All —</option>
      <option value="unpaid"   <?= $f_status==='unpaid'   ? 'selected' : '' ?>>Unpaid</option>
      <option value="partial"  <?= $f_status==='partial'  ? 'selected' : '' ?>>Partial</option>
      <option value="paid"     <?= $f_status==='paid'     ? 'selected' : '' ?>>Fully Paid</option>
    </select>
  </div>
  <input type="hidden" name="page" value="1">
  <div style="display:flex;gap:6px;align-items:flex-end;">
    <button type="submit" class="btn btn-teal btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
    <a href="ushop_payment_reconciliation.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
  </div>
</form>

<!-- Main Table -->
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title">
      <i class="fa-solid fa-scale-balanced" style="margin-right:6px;color:#059669;"></i>Reconciliation List
      <span style="font-size:11px;color:#9ca3af;font-weight:400;margin-left:8px;" id="totalLabel"><?= number_format($total) ?> record<?= $total != 1 ? 's' : '' ?></span>
    </div>
    <span style="font-size:11.5px;color:#6b7280;"><i class="fa-solid fa-circle-info"></i> Click row to expand invoices &amp; add payments</span>
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
        <th class="tr">Invoice Amount</th>
        <th class="tr">BO Paid</th>
        <th class="tr">Balance</th>
        <th class="tc">BO Status</th>
        <th class="tc">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php $i = $offset + 1; while ($r = mysqli_fetch_assoc($rows_res)):
      $tpaid = floatval($r['bo_total_paid']);
      $tamt  = floatval($r['total_amount']);
      $tbal  = $tamt - $tpaid;
      $full  = ($tbal <= 0.001);
      $partial = ($tpaid > 0 && !$full);

      if ($full)         $stBadge = '<span class="badge status-paid"><i class="fa-solid fa-circle-check"></i> Paid</span>';
      elseif ($partial)  $stBadge = '<span class="badge status-partial"><i class="fa-solid fa-circle-half-stroke"></i> Partial</span>';
      else               $stBadge = '<span class="badge status-unpaid"><i class="fa-solid fa-circle-xmark"></i> Unpaid</span>';
    ?>
    <tr class="main-row" style="cursor:pointer;" onclick="toggleRow(<?= $r['id'] ?>)" id="mainRow<?= $r['id'] ?>">
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
      <td><?= $r['cat_name'] ? '<span class="badge badge-blue">'.htmlspecialchars($r['cat_name']).'</span>' : '<span style="color:#d1d5db;">—</span>' ?></td>
      <td><?= $r['sub_name'] ? '<span class="badge badge-purple">'.htmlspecialchars($r['sub_name']).'</span>' : '<span style="color:#d1d5db;">—</span>' ?></td>
      <td class="tc"><span class="badge badge-teal"><?= number_format($r['invoice_count']) ?></span></td>
      <td class="tr" style="font-weight:700;color:#15803d;"><?= number_format($tamt, 2) ?></td>
      <td class="tr" id="boPaidCell<?= $r['id'] ?>">
        <?php if($tpaid > 0): ?>
          <span class="balance-paid"><?= number_format($tpaid, 2) ?></span>
        <?php else: ?>
          <span style="color:#d1d5db;">0.00</span>
        <?php endif; ?>
      </td>
      <td class="tr" id="boBalCell<?= $r['id'] ?>">
        <?php if($full): ?>
          <span class="paid-full"><i class="fa-solid fa-check"></i> 0.00</span>
        <?php else: ?>
          <span class="balance-due"><?= number_format($tbal, 2) ?></span>
        <?php endif; ?>
      </td>
      <td class="tc" id="boStatusCell<?= $r['id'] ?>"><?= $stBadge ?></td>
      <td class="tc" onclick="event.stopPropagation();">
        <button class="btn btn-emerald btn-xs"
                onclick="openAddPayModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['invoice_no'] ?: 'PI-'.$r['id'])) ?>', <?= number_format($tamt, 2, '.', '') ?>, <?= number_format($tpaid, 2, '.', '') ?>)">
          <i class="fa-solid fa-plus"></i> Add Payment
        </button>
      </td>
    </tr>
    <tr class="sub-row" id="subRow<?= $r['id'] ?>">
      <td class="sub-cell" colspan="13">
        <div class="sub-inner" id="subContent<?= $r['id'] ?>">
          <span style="color:#9ca3af;font-size:12px;"><i class="fa-solid fa-spinner spin"></i> Loading…</span>
        </div>
      </td>
    </tr>
    <?php endwhile; ?>
    <?php if ($total === 0): ?>
    <tr>
      <td colspan="13" style="text-align:center;padding:48px;color:#9ca3af;font-size:13px;">
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
     ADD PAYMENT MODAL
════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addPayModal">
  <div class="modal-box" style="max-width:520px;">
    <div class="modal-head" style="background:#ecfdf5;border-bottom-color:#a7f3d0;">
      <i class="fa-solid fa-circle-dollar-to-slot" style="color:#059669;font-size:18px;"></i>
      <span class="modal-head-title">Add Back Office Payment</span>
      <span id="apmLabel" style="font-size:11px;color:#9ca3af;font-family:monospace;"></span>
      <button type="button" onclick="closeAddPayModal()" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:18px;margin-left:auto;"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">

      <!-- Balance summary -->
      <div class="bal-banner" id="apmBanner">
        <div class="bal-box bal-box-gray"><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;margin-bottom:2px;">Invoice Total</div><strong id="apmTotal">0.00</strong></div>
        <div class="bal-box bal-box-emerald"><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;margin-bottom:2px;">BO Paid</div><strong id="apmPaid">0.00</strong></div>
        <div class="bal-box bal-box-red" id="apmBalBox"><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;margin-bottom:2px;">Balance</div><strong id="apmBalance">0.00</strong></div>
      </div>

      <!-- Payment entry form -->
      <div style="background:#f0fdf4;border:1px solid #a7f3d0;border-radius:9px;padding:16px;margin-bottom:16px;">
        <div class="sec-head" style="color:#065f46;margin-bottom:12px;"><i class="fa-solid fa-plus-circle" style="color:#059669;"></i> New Payment Entry</div>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
          <div class="form-group" style="flex:1;min-width:150px;margin-bottom:0;">
            <label>Payment Date <span style="color:#dc2626;">*</span></label>
            <input type="date" id="apmDate" required />
          </div>
          <div class="form-group" style="flex:1;min-width:150px;margin-bottom:0;">
            <label>Amount <span style="color:#dc2626;">*</span></label>
            <input type="number" id="apmAmount" min="0.01" step="0.01" placeholder="0.00" />
          </div>
        </div>
        <div class="form-group" style="margin-top:12px;margin-bottom:0;">
          <label>Remark <span style="color:#9ca3af;font-size:10px;text-transform:none;">(optional)</span></label>
          <input type="text" id="apmRemark" placeholder="e.g. Cheque #12345 deposited…" />
        </div>
      </div>

      <!-- Payment history -->
      <div id="apmHistWrap">
        <div class="sec-head" style="color:#065f46;margin-bottom:8px;"><i class="fa-solid fa-clock-rotate-left" style="color:#059669;"></i> Payment History</div>
        <div id="apmHistBody">
          <span style="color:#9ca3af;font-size:12px;"><i class="fa-solid fa-spinner spin"></i> Loading…</span>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-secondary" onclick="closeAddPayModal()"><i class="fa-solid fa-xmark"></i> Close</button>
      <button type="button" class="btn btn-emerald" id="apmSaveBtn" onclick="saveBoPayment()">
        <i class="fa-solid fa-floppy-disk"></i> Save Payment
      </button>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════
     DELETE BO PAYMENT CONFIRM MODAL
════════════════════════════════════════════════════ -->
<div class="del-overlay" id="delBoModal">
  <div class="del-box">
    <div class="del-head">
      <i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;font-size:16px;"></i>
      <span style="font-size:14px;font-weight:700;color:#111827;">Delete BO Payment</span>
      <button type="button" onclick="closeDelBoModal()" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:16px;margin-left:auto;"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="del-body">
      <p style="font-size:12.5px;color:#374151;margin:0 0 10px;">Remove this back-office payment? This cannot be undone.</p>
      <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:7px;padding:10px 12px;font-size:12px;color:#374151;">
        <div style="display:flex;justify-content:space-between;margin-bottom:3px;"><span style="color:#6b7280;">Date:</span><strong id="dbDate">—</strong></div>
        <div style="display:flex;justify-content:space-between;margin-bottom:3px;"><span style="color:#6b7280;">Amount:</span><strong id="dbAmount" style="color:#059669;">—</strong></div>
        <div style="display:flex;justify-content:space-between;"><span style="color:#6b7280;">Remark:</span><strong id="dbRemark">—</strong></div>
      </div>
    </div>
    <div class="del-foot">
      <button type="button" class="btn btn-secondary btn-sm" onclick="closeDelBoModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button type="button" class="btn btn-danger btn-sm" id="dbConfirmBtn" onclick="confirmDelBo()"><i class="fa-solid fa-trash"></i> Delete</button>
    </div>
  </div>
</div>

<script>
/* ════════════════════════
   STATE
════════════════════════ */
const openRows = {};
let apmPiId    = null;  // current modal PI id
let dbPayId    = null;  // delete target payment id
let dbPiId     = null;  // delete target PI id (for recalc)

/* ════════════════════════
   ROW EXPAND
════════════════════════ */
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

  const res  = await fetch('ushop_payment_reconciliation.php?ajax=get_invoices&pi_id=' + id);
  const data = await res.json();
  cont.innerHTML = buildSubContent(id, data);
}

function buildSubContent(piId, data) {
  const invoices  = data.invoices    || [];
  const totalPaid = parseFloat(data.total_paid   || 0);
  const totalAmt  = parseFloat(data.total_amount || 0);
  const balance   = parseFloat(data.balance      || 0);
  const isFull    = balance <= 0.001;
  const isPartial = totalPaid > 0 && !isFull;

  /* Balance banner */
  const banner = `<div class="bal-banner">
    <div class="bal-box bal-box-gray"><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;margin-bottom:1px;">Invoice Total</div><strong>${fmtNum(totalAmt)}</strong></div>
    <div class="bal-box bal-box-emerald"><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;margin-bottom:1px;">BO Paid</div><strong>${fmtNum(totalPaid)}</strong></div>
    <div class="bal-box ${isFull ? 'bal-box-green' : 'bal-box-red'}"><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;margin-bottom:1px;">Balance</div>
      <strong>${isFull ? '✓ Fully Paid' : fmtNum(balance)}</strong></div>
  </div>`;

  /* Linked invoices table */
  let invRows = '';
  let sumAmt = 0, sumDisc = 0;
  invoices.forEach((r, i) => {
    const amt  = parseFloat(r.total_amount   || 0);
    const disc = parseFloat(r.total_discount || 0);
    sumAmt  += amt;
    sumDisc += disc;
    invRows += `<tr>
      <td style="color:#9ca3af;font-size:11px;">${i+1}</td>
      <td><span class="badge badge-teal">${fmtDate(r.invoice_date)}</span></td>
      <td style="font-weight:700;color:#0e7490;">${escHtml(r.doc_no || '—')}</td>
      <td style="font-size:11px;font-family:monospace;color:#6b7280;">${escHtml(r.unique_inv_no || '—')}</td>
      <td>${r.customer_name ? escHtml(r.customer_name) : '<span style="color:#9ca3af;font-size:11px;">Walk-in</span>'}</td>
      <td>${r.customer_code ? '<span class="badge badge-blue">#'+escHtml(r.customer_code)+'</span>' : '<span style="color:#d1d5db;">—</span>'}</td>
      <td style="text-align:right;color:#92400e;">${fmtNum(disc)}</td>
      <td style="text-align:right;font-weight:700;color:#15803d;">${fmtNum(amt)}</td>
    </tr>`;
  });

  const invTable = invoices.length === 0
    ? '<p style="color:#9ca3af;font-size:12px;margin:4px 0;"><i class="fa-solid fa-inbox"></i> No linked invoices.</p>'
    : `<table class="mini-tbl">
        <thead><tr><th>#</th><th>Date</th><th>Doc No</th><th>Unique Inv No</th><th>Customer</th><th>Code</th><th style="text-align:right;">Discount</th><th style="text-align:right;">Amount</th></tr></thead>
        <tbody>${invRows}</tbody>
        <tfoot><tr style="background:#f1f5f9;font-weight:700;">
          <td colspan="6" style="padding:7px 10px;font-size:11.5px;color:#374151;"><i class="fa-solid fa-sigma" style="color:#0e7490;margin-right:4px;"></i> Totals (${invoices.length})</td>
          <td style="text-align:right;padding:7px 10px;color:#92400e;">${fmtNum(sumDisc)}</td>
          <td style="text-align:right;padding:7px 10px;color:#15803d;">${fmtNum(sumAmt)}</td>
        </tr></tfoot>
      </table>`;

  /* BO Payment history (loaded async) */
  const paySection = `
    <div style="margin-top:18px;">
      <div class="sec-head" style="color:#065f46;margin-bottom:8px;">
        <i class="fa-solid fa-clock-rotate-left" style="color:#059669;"></i> Back Office Payment History
      </div>
      <div id="subBoHist_${piId}"><span style="color:#9ca3af;font-size:12px;"><i class="fa-solid fa-spinner spin"></i> Loading…</span></div>
    </div>`;

  setTimeout(() => loadSubBoHistory(piId), 60);

  return `<div class="sec-head" style="margin-bottom:8px;"><i class="fa-solid fa-receipt" style="color:#0e7490;"></i> Linked Invoices</div>`
       + banner + invTable + paySection;
}

async function loadSubBoHistory(piId) {
  const el = document.getElementById('subBoHist_' + piId);
  if (!el) return;
  const res  = await fetch('ushop_payment_reconciliation.php?ajax=get_bo_payments&pi_id=' + piId);
  const pays = await res.json();
  el.innerHTML = renderBoPayTable(piId, pays);
}

function renderBoPayTable(piId, pays) {
  if (!pays.length) {
    return '<p style="color:#9ca3af;font-size:12px;margin:2px 0;"><i class="fa-solid fa-ban" style="margin-right:5px;"></i>No back-office payments recorded yet.</p>';
  }
  let total = 0;
  const rows = pays.map((p, i) => {
    total += parseFloat(p.amount || 0);
    return `<tr id="subBoRow_${p.id}">
      <td style="color:#9ca3af;font-size:11px;">${i+1}</td>
      <td><span class="badge badge-teal">${fmtDate(p.payment_date)}</span></td>
      <td style="text-align:right;font-weight:700;color:#059669;">${fmtNum(p.amount)}</td>
      <td style="color:#6b7280;font-size:11.5px;">${p.remark ? escHtml(p.remark) : '<span style="color:#d1d5db;">—</span>'}</td>
      <td style="color:#9ca3af;font-size:10.5px;">${fmtDateTime(p.created_at)}</td>
      <td class="tc"><button class="btn-bop-del" onclick="openDelBoModal(${p.id}, ${piId}, '${escJs(p.payment_date)}', '${fmtNum(p.amount)}', '${escJs(p.remark||'')}')"><i class="fa-solid fa-trash"></i></button></td>
    </tr>`;
  }).join('');
  return `<table class="bop-tbl">
    <thead><tr><th>#</th><th>Date</th><th style="text-align:right;">Amount</th><th>Remark</th><th>Recorded At</th><th class="tc">Del</th></tr></thead>
    <tbody id="subBoBody_${piId}">${rows}</tbody>
    <tfoot id="subBoFoot_${piId}"><tr style="background:#d1fae5;font-weight:700;">
      <td colspan="2" style="padding:6px 10px;color:#065f46;font-size:11.5px;"><i class="fa-solid fa-sigma" style="margin-right:4px;"></i> Total BO Paid</td>
      <td style="text-align:right;padding:6px 10px;color:#065f46;" id="subBoTotal_${piId}">${fmtNum(total)}</td>
      <td colspan="3"></td>
    </tr></tfoot>
  </table>`;
}

/* ════════════════════════
   ADD PAYMENT MODAL
════════════════════════ */
async function openAddPayModal(piId, invNo, totalAmt, boPaid) {
  apmPiId = piId;

  document.getElementById('apmLabel').textContent   = invNo ? '(' + invNo + ')' : '';
  document.getElementById('apmDate').value          = new Date().toISOString().slice(0, 10);
  document.getElementById('apmAmount').value        = '';
  document.getElementById('apmRemark').value        = '';
  document.getElementById('apmTotal').textContent   = fmtNum(totalAmt);
  document.getElementById('apmPaid').textContent    = fmtNum(boPaid);
  const bal = totalAmt - boPaid;
  document.getElementById('apmBalance').textContent = fmtNum(bal);
  document.getElementById('apmBalBox').className    = 'bal-box ' + (bal <= 0.001 ? 'bal-box-green' : 'bal-box-red');

  document.getElementById('apmHistBody').innerHTML  =
    '<span style="color:#9ca3af;font-size:12px;"><i class="fa-solid fa-spinner spin"></i> Loading…</span>';
  document.getElementById('addPayModal').classList.add('open');

  await loadApmHistory(piId);
}

async function loadApmHistory(piId) {
  const res  = await fetch('ushop_payment_reconciliation.php?ajax=get_bo_payments&pi_id=' + piId);
  const pays = await res.json();
  const el   = document.getElementById('apmHistBody');

  if (!pays.length) {
    el.innerHTML = '<p style="color:#9ca3af;font-size:12px;margin:2px 0;"><i class="fa-solid fa-ban" style="margin-right:5px;"></i>No payments recorded yet.</p>';
    return;
  }
  let total = 0;
  const rows = pays.map((p, i) => {
    total += parseFloat(p.amount || 0);
    return `<tr id="apmRow_${p.id}">
      <td style="color:#9ca3af;font-size:11px;">${i+1}</td>
      <td><span class="badge badge-teal">${fmtDate(p.payment_date)}</span></td>
      <td style="text-align:right;font-weight:700;color:#059669;">${fmtNum(p.amount)}</td>
      <td style="color:#6b7280;font-size:11.5px;">${p.remark ? escHtml(p.remark) : '<span style="color:#d1d5db;">—</span>'}</td>
      <td style="color:#9ca3af;font-size:10.5px;">${fmtDateTime(p.created_at)}</td>
      <td class="tc"><button class="btn-bop-del" onclick="openDelBoModal(${p.id}, ${piId}, '${escJs(p.payment_date)}', '${fmtNum(p.amount)}', '${escJs(p.remark||'')}')"><i class="fa-solid fa-trash"></i></button></td>
    </tr>`;
  }).join('');
  el.innerHTML = `<table class="bop-tbl">
    <thead><tr><th>#</th><th>Date</th><th style="text-align:right;">Amount</th><th>Remark</th><th>Recorded At</th><th class="tc">Del</th></tr></thead>
    <tbody id="apmHistTbody">${rows}</tbody>
    <tfoot><tr style="background:#d1fae5;font-weight:700;">
      <td colspan="2" style="padding:6px 10px;color:#065f46;font-size:11.5px;"><i class="fa-solid fa-sigma" style="margin-right:4px;"></i> Total Paid</td>
      <td style="text-align:right;padding:6px 10px;color:#065f46;" id="apmHistTotal">${fmtNum(total)}</td>
      <td colspan="3"></td>
    </tr></tfoot>
  </table>`;
}

function closeAddPayModal() {
  document.getElementById('addPayModal').classList.remove('open');
  apmPiId = null;
}

async function saveBoPayment() {
  const date   = document.getElementById('apmDate').value;
  const amount = parseFloat(document.getElementById('apmAmount').value || 0);
  const remark = document.getElementById('apmRemark').value.trim();

  if (!date)         { showAlert('error', 'Please select a payment date.'); return; }
  if (amount <= 0)   { showAlert('error', 'Please enter a positive amount.'); return; }

  const btn = document.getElementById('apmSaveBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

  const fd = new FormData();
  fd.append('ajax_add_payment', '1');
  fd.append('pi_id',        apmPiId);
  fd.append('payment_date', date);
  fd.append('amount',       amount);
  fd.append('remark',       remark);

  const res  = await fetch('ushop_payment_reconciliation.php', { method:'POST', body:fd });
  const data = await res.json();

  btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Payment';

  if (data.success) {
    showAlert('success', '<i class="fa-solid fa-check-circle"></i> ' + data.message);

    /* Reset form */
    document.getElementById('apmAmount').value = '';
    document.getElementById('apmRemark').value = '';

    /* Update modal banner */
    document.getElementById('apmPaid').textContent    = fmtNum(data.total_paid);
    document.getElementById('apmBalance').textContent = fmtNum(data.balance);
    document.getElementById('apmBalBox').className    = 'bal-box ' + (data.balance <= 0.001 ? 'bal-box-green' : 'bal-box-red');

    /* Reload history inside modal */
    await loadApmHistory(apmPiId);

    /* Update main row cells */
    updateMainRowCells(apmPiId, data.total_paid, data.total_amount, data.balance);

    /* Refresh sub-row if open */
    refreshSubRow(apmPiId);
  } else {
    showAlert('error', data.message || 'Failed to save payment.');
  }
}

/* ════════════════════════
   DELETE BO PAYMENT
════════════════════════ */
function openDelBoModal(payId, piId, date, amount, remark) {
  dbPayId = payId;
  dbPiId  = piId;
  document.getElementById('dbDate').textContent   = fmtDate(date);
  document.getElementById('dbAmount').textContent = amount;
  document.getElementById('dbRemark').textContent = remark || '—';
  document.getElementById('delBoModal').classList.add('open');
}
function closeDelBoModal() {
  document.getElementById('delBoModal').classList.remove('open');
  dbPayId = dbPiId = null;
}

async function confirmDelBo() {
  if (!dbPayId) return;
  const btn = document.getElementById('dbConfirmBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

  const fd = new FormData();
  fd.append('ajax_delete_bo_payment', '1');
  fd.append('pay_id', dbPayId);
  fd.append('pi_id',  dbPiId);

  const res  = await fetch('ushop_payment_reconciliation.php', { method:'POST', body:fd });
  const data = await res.json();

  btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete';

  if (data.success) {
    closeDelBoModal();
    showAlert('success', '<i class="fa-solid fa-check-circle"></i> Payment deleted successfully.');

    /* Remove from modal history */
    document.getElementById('apmRow_'    + dbPayId)?.remove();
    /* Remove from sub-row history */
    document.getElementById('subBoRow_'  + dbPayId)?.remove();

    /* Update modal banner */
    const apmPaidEl = document.getElementById('apmPaid');
    const apmBalEl  = document.getElementById('apmBalance');
    const apmBalBox = document.getElementById('apmBalBox');
    if (apmPaidEl) apmPaidEl.textContent = fmtNum(data.total_paid);
    if (apmBalEl)  apmBalEl.textContent  = fmtNum(data.balance);
    if (apmBalBox) apmBalBox.className   = 'bal-box ' + (data.balance <= 0.001 ? 'bal-box-green' : 'bal-box-red');

    /* Update modal history totals */
    const apmHistTot = document.getElementById('apmHistTotal');
    if (apmHistTot) apmHistTot.textContent = fmtNum(data.total_paid);

    /* Update sub-row total */
    const subBoTot = document.getElementById('subBoTotal_' + dbPiId);
    if (subBoTot) subBoTot.textContent = fmtNum(data.total_paid);

    /* Update main row cells */
    updateMainRowCells(dbPiId, data.total_paid, data.total_amount, data.balance);

    /* Refresh sub-row if open */
    refreshSubRow(dbPiId);
  } else {
    showAlert('error', 'Failed to delete. Please try again.');
  }
}

/* ════════════════════════
   HELPERS
════════════════════════ */
function updateMainRowCells(piId, totalPaid, totalAmt, balance) {
  const paidCell   = document.getElementById('boPaidCell'   + piId);
  const balCell    = document.getElementById('boBalCell'    + piId);
  const statusCell = document.getElementById('boStatusCell' + piId);

  if (paidCell) {
    paidCell.innerHTML = totalPaid > 0
      ? `<span class="balance-paid">${fmtNum(totalPaid)}</span>`
      : `<span style="color:#d1d5db;">0.00</span>`;
  }
  if (balCell) {
    balCell.innerHTML = balance <= 0.001
      ? `<span class="paid-full"><i class="fa-solid fa-check"></i> 0.00</span>`
      : `<span class="balance-due">${fmtNum(balance)}</span>`;
  }
  if (statusCell) {
    const isFull    = balance <= 0.001;
    const isPartial = totalPaid > 0 && !isFull;
    statusCell.innerHTML = isFull
      ? `<span class="badge status-paid"><i class="fa-solid fa-circle-check"></i> Paid</span>`
      : isPartial
        ? `<span class="badge status-partial"><i class="fa-solid fa-circle-half-stroke"></i> Partial</span>`
        : `<span class="badge status-unpaid"><i class="fa-solid fa-circle-xmark"></i> Unpaid</span>`;
  }
}

function refreshSubRow(piId) {
  if (!openRows[piId]) return;
  const cont = document.getElementById('subContent' + piId);
  if (!cont) return;
  delete cont.dataset.loaded;
  openRows[piId] = false;
  document.getElementById('subRow' + piId).classList.remove('open');
  document.getElementById('expBtn' + piId).classList.remove('open');
  setTimeout(() => toggleRow(piId), 160);
}

function fmtDate(d) {
  if (!d) return '—';
  return new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' });
}
function fmtDateTime(dt) {
  if (!dt) return '—';
  return new Date(dt).toLocaleString('en-GB', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' });
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
  const el = document.getElementById(type === 'success' ? 'jsAlert' : 'jsError');
  el.innerHTML = msg;
  el.style.display = 'block';
  window.scrollTo({ top:0, behavior:'smooth' });
  setTimeout(() => { el.style.display = 'none'; }, 5000);
}

/* Close modals on backdrop click */
document.getElementById('addPayModal').addEventListener('click', e => { if(e.target===e.currentTarget) closeAddPayModal(); });
document.getElementById('delBoModal' ).addEventListener('click', e => { if(e.target===e.currentTarget) closeDelBoModal();  });
</script>

<?php include 'footer.php'; ?>
