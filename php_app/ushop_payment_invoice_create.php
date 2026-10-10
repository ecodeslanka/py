<?php
include 'config.php';

/* ═══════════════════════════════════════════════════════
   AUTO-CREATE TABLE: ushop_payment_invoices (with invoice_no)
═══════════════════════════════════════════════════════ */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `ushop_payment_invoices` (
      `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `invoice_no`     VARCHAR(50)  NOT NULL UNIQUE COMMENT 'Auto-generated invoice number',
      `invoice_date`   DATE         NOT NULL,
      `subject`        VARCHAR(500) NOT NULL DEFAULT '',
      `category_id`    INT UNSIGNED NULL,
      `subcategory_id` INT UNSIGNED NULL,
      `invoice_ids`    TEXT         NOT NULL COMMENT 'comma-separated ushop_invoices.id list',
      `total_amount`   DECIMAL(15,2) NOT NULL DEFAULT 0,
      `total_discount` DECIMAL(15,2) NOT NULL DEFAULT 0,
      `invoice_count`  INT UNSIGNED  NOT NULL DEFAULT 0,
      `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_invoice_no` (`invoice_no`),
      KEY `idx_cat`    (`category_id`),
      KEY `idx_subcat` (`subcategory_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* Migration: add transferred_to if missing */
$col_tr = mysqli_query($conn, "SHOW COLUMNS FROM ushop_payment_invoices LIKE 'transferred_to'");
if (mysqli_num_rows($col_tr) === 0) {
    mysqli_query($conn, "ALTER TABLE ushop_payment_invoices ADD COLUMN `transferred_to` INT UNSIGNED NULL DEFAULT NULL COMMENT 'new PI id this was consolidated into' AFTER `invoice_count`");
}

/* Migration: add payment_month if missing */
$col_pm = mysqli_query($conn, "SHOW COLUMNS FROM ushop_payment_invoices LIKE 'payment_month'");
if (mysqli_num_rows($col_pm) === 0) {
    mysqli_query($conn, "ALTER TABLE ushop_payment_invoices ADD COLUMN `payment_month` VARCHAR(7) NULL DEFAULT NULL COMMENT 'YYYY-MM format' AFTER `invoice_date`");
}

/* Migration: add mgmt_fee_amount if missing */
$col_mf = mysqli_query($conn, "SHOW COLUMNS FROM ushop_payment_invoices LIKE 'mgmt_fee_amount'");
if (mysqli_num_rows($col_mf) === 0) {
    mysqli_query($conn, "ALTER TABLE ushop_payment_invoices ADD COLUMN `mgmt_fee_amount` DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'Management fee amount' AFTER `total_discount`");
}

/* Migration: add mgmt_fee_description if missing */
$col_mfd = mysqli_query($conn, "SHOW COLUMNS FROM ushop_payment_invoices LIKE 'mgmt_fee_description'");
if (mysqli_num_rows($col_mfd) === 0) {
    mysqli_query($conn, "ALTER TABLE ushop_payment_invoices ADD COLUMN `mgmt_fee_description` VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'Management fee description' AFTER `mgmt_fee_amount`");
}

/* Migration: add mgmt_fee_invoice_no if missing */
$col_mfn = mysqli_query($conn, "SHOW COLUMNS FROM ushop_payment_invoices LIKE 'mgmt_fee_invoice_no'");
if (mysqli_num_rows($col_mfn) === 0) {
    mysqli_query($conn, "ALTER TABLE ushop_payment_invoices ADD COLUMN `mgmt_fee_invoice_no` VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'Auto-generated management fee invoice number' AFTER `mgmt_fee_description`");
}

/* ═══════════════════════════════════════════════════════
   FUNCTION: GENERATE UNIQUE INVOICE NUMBER
═══════════════════════════════════════════════════════ */
function generatePaymentInvoiceNo($conn) {
    $date_part = date('Y-m');
    $max_res = mysqli_query($conn, "
        SELECT COALESCE(MAX(CAST(SUBSTRING(invoice_no, -6) AS UNSIGNED)), 0) as max_seq
        FROM ushop_payment_invoices
        WHERE invoice_no LIKE 'PI-$date_part-%'
    ");
    $max_row = mysqli_fetch_assoc($max_res);
    $next_seq = ($max_row['max_seq'] ?? 0) + 1;
    return 'PI-' . $date_part . '-' . str_pad($next_seq, 6, '0', STR_PAD_LEFT);
}

function generateMgmtFeeInvoiceNo($conn) {
    $date_part = date('Y-m');
    $max_res = mysqli_query($conn, "
        SELECT COALESCE(MAX(CAST(SUBSTRING(mgmt_fee_invoice_no, -6) AS UNSIGNED)), 0) as max_seq
        FROM ushop_payment_invoices
        WHERE mgmt_fee_invoice_no LIKE 'MF-$date_part-%'
    ");
    $max_row = mysqli_fetch_assoc($max_res);
    $next_seq = ($max_row['max_seq'] ?? 0) + 1;
    return 'MF-' . $date_part . '-' . str_pad($next_seq, 6, '0', STR_PAD_LEFT);
}

/* ═══════════════════════════════════════════════════════
   AJAX: LOAD SUBCATEGORIES for a category
═══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'subcats') {
    $cat_id = intval($_GET['cat_id'] ?? 0);
    $res = mysqli_query($conn, "SELECT id, name FROM ushop_letter_subcategories WHERE category_id=$cat_id ORDER BY name");
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: LOAD INVOICES based on filters (CREDIT INVOICES ONLY)
═══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_invoices') {
    $date_from   = trim($_GET['date_from']   ?? '');
    $date_to     = trim($_GET['date_to']     ?? '');
    $category_id = intval($_GET['category_id']    ?? 0);
    $subcat_id   = intval($_GET['subcategory_id'] ?? 0);
    $customer    = trim($_GET['customer']         ?? '');

    $where = ['1=1'];
    $where[] = "inv.id IN (SELECT invoice_id FROM ushop_invoice_payments WHERE pay_type = 'CREDIT')";

    if ($date_from) $where[] = "inv.invoice_date >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
    if ($date_to)   $where[] = "inv.invoice_date <= '" . mysqli_real_escape_string($conn, $date_to) . "'";

    if ($subcat_id > 0) {
        $codes_res = mysqli_query($conn, "SELECT customer_code FROM ushop_letter_subcategory_customers WHERE subcategory_id=$subcat_id");
        $codes = [];
        while ($cr = mysqli_fetch_assoc($codes_res)) $codes[] = "'" . mysqli_real_escape_string($conn, $cr['customer_code']) . "'";
        if ($codes) $where[] = "inv.customer_code IN (" . implode(',', $codes) . ")";
        else        $where[] = "0=1";
    } elseif ($category_id > 0) {
        $codes_res = mysqli_query($conn, "SELECT customer_code FROM ushop_letter_category_customers WHERE category_id=$category_id");
        $codes = [];
        while ($cr = mysqli_fetch_assoc($codes_res)) $codes[] = "'" . mysqli_real_escape_string($conn, $cr['customer_code']) . "'";
        if ($codes) $where[] = "inv.customer_code IN (" . implode(',', $codes) . ")";
        else        $where[] = "0=1";
    }

    if ($customer !== '') {
        $ce = mysqli_real_escape_string($conn, $customer);
        $where[] = "(inv.customer_name LIKE '%$ce%' OR inv.customer_code LIKE '%$ce%')";
    }

    /* HIDE ALREADY USED INVOICES */
    $used_res = mysqli_query($conn, "SELECT invoice_ids FROM ushop_payment_invoices WHERE invoice_ids != ''");
    $used_ids = [];
    while ($ur = mysqli_fetch_assoc($used_res)) {
        foreach (explode(',', $ur['invoice_ids']) as $uid) {
            $uid = intval(trim($uid));
            if ($uid > 0) $used_ids[$uid] = true;
        }
    }
    if (!empty($used_ids)) {
        $where[] = "inv.id NOT IN (" . implode(',', array_keys($used_ids)) . ")";
    }

    $wsql = implode(' AND ', $where);

    $res = mysqli_query($conn, "
        SELECT inv.id, inv.invoice_date, inv.doc_no, inv.unique_inv_no,
               inv.customer_name, inv.customer_code,
               inv.total_amount, inv.total_discount
        FROM ushop_invoices inv
        WHERE $wsql
        ORDER BY inv.invoice_date DESC, inv.id DESC
        LIMIT 500
    ");
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $r['type'] = 'credit';
        $rows[] = $r;
    }

    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: CATEGORY CUSTOMERS
═══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'cat_customers') {
    $cat_id = intval($_GET['cat_id'] ?? 0);
    $sub_id = intval($_GET['sub_id'] ?? 0);
    if ($sub_id > 0) {
        $res = mysqli_query($conn, "SELECT customer_code, customer_name FROM ushop_letter_subcategory_customers WHERE subcategory_id=$sub_id ORDER BY customer_name");
    } else {
        $res = mysqli_query($conn, "SELECT customer_code, customer_name FROM ushop_letter_category_customers WHERE category_id=$cat_id ORDER BY customer_name");
    }
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    header('Content-Type: application/json');
    echo json_encode(['customers' => $rows]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: SAVE PAYMENT INVOICE
═══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    $inv_date          = trim($_POST['pi_date']             ?? '');
    $payment_month     = trim($_POST['pi_month_year']       ?? '');
    $subject           = trim($_POST['pi_subject']          ?? '');
    $cat_id            = intval($_POST['pi_category_id']    ?? 0) ?: null;
    $subcat_id         = intval($_POST['pi_subcategory_id'] ?? 0) ?: null;
    $ids_raw           = $_POST['selected_ids'] ?? [];
    $mgmt_fee_amount   = floatval($_POST['mgmt_fee_amount']       ?? 0);
    $mgmt_fee_desc     = trim($_POST['mgmt_fee_description']      ?? '');
    $include_mgmt_fee  = ($_POST['include_mgmt_fee'] ?? '0') === '1';

    $ids = array_values(array_filter(array_map('intval', $ids_raw)));

    if (!$inv_date) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invoice date is required.']);
        exit;
    }

    if (empty($ids) && !$include_mgmt_fee) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Select at least one invoice or add a management fee.']);
        exit;
    }

    if ($include_mgmt_fee && $mgmt_fee_amount <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Management fee amount must be greater than zero.']);
        exit;
    }

    $invoice_no = generatePaymentInvoiceNo($conn);

    /* ── Totals from credit invoices ── */
    $total_amt  = 0;
    $total_disc = 0;

    if (!empty($ids)) {
        $ids_sql = implode(',', $ids);
        $agg = mysqli_fetch_assoc(mysqli_query($conn, "
            SELECT COALESCE(SUM(total_amount),0)   sum_amt,
                   COALESCE(SUM(total_discount),0) sum_disc
            FROM ushop_invoices WHERE id IN ($ids_sql)
        "));
        $total_amt  = floatval($agg['sum_amt']);
        $total_disc = floatval($agg['sum_disc']);
    }

    /* Include mgmt fee in total */
    if ($include_mgmt_fee) {
        $total_amt += $mgmt_fee_amount;
    }

    $all_ids_flat = array_values(array_unique(array_filter($ids)));
    $all_ids_str  = implode(',', $all_ids_flat);
    $count        = count($all_ids_flat);

    /* Generate management fee invoice number */
    $mgmt_fee_invoice_no = '';
    if ($include_mgmt_fee) {
        $mgmt_fee_invoice_no = generateMgmtFeeInvoiceNo($conn);
    }

    $date_e      = mysqli_real_escape_string($conn, $inv_date);
    $subj_e      = mysqli_real_escape_string($conn, $subject);
    $inv_no_e    = mysqli_real_escape_string($conn, $invoice_no);
    $mf_desc_e   = mysqli_real_escape_string($conn, $mgmt_fee_desc);
    $mf_inv_no_e = mysqli_real_escape_string($conn, $mgmt_fee_invoice_no);
    $cat_val     = $cat_id    ? $cat_id    : 'NULL';
    $sub_val     = $subcat_id ? $subcat_id : 'NULL';
    $pm_val      = $payment_month ? "'" . mysqli_real_escape_string($conn, $payment_month) . "'" : 'NULL';
    $mf_amt      = $include_mgmt_fee ? $mgmt_fee_amount : 0;

    /* ── Insert new PI ── */
    $ok = mysqli_query($conn, "
        INSERT INTO ushop_payment_invoices
            (invoice_no, invoice_date, payment_month, subject, category_id, subcategory_id,
             invoice_ids, total_amount, total_discount, invoice_count,
             mgmt_fee_amount, mgmt_fee_description, mgmt_fee_invoice_no, created_at)
        VALUES
            ('$inv_no_e', '$date_e', $pm_val, '$subj_e', $cat_val, $sub_val,
             '$all_ids_str', $total_amt, $total_disc, $count,
             $mf_amt, '$mf_desc_e', '$mf_inv_no_e', NOW())
    ");

    if (!$ok) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit;
    }

    $new_pi_id = mysqli_insert_id($conn);

    $msg = "Payment invoice <strong>$invoice_no</strong> created successfully.";
    if ($include_mgmt_fee && $mgmt_fee_invoice_no) {
        $msg .= " Management fee invoice <strong>$mgmt_fee_invoice_no</strong> also generated.";
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success'               => true,
        'payment_invoice_id'    => $new_pi_id,
        'invoice_no'            => $invoice_no,
        'mgmt_fee_invoice_no'   => $mgmt_fee_invoice_no,
        'message'               => $msg
    ]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   LOAD CATEGORIES & CUSTOMERS for dropdowns
═══════════════════════════════════════════════════════ */
$categories = [];
$cats_res = mysqli_query($conn, "SELECT id, name FROM ushop_letter_categories ORDER BY name");
while ($r = mysqli_fetch_assoc($cats_res)) $categories[] = $r;

$customers = [];
$cust_res = mysqli_query($conn, "SELECT DISTINCT customer_code, customer_name FROM ushop_invoices WHERE id IN (SELECT invoice_id FROM ushop_invoice_payments WHERE pay_type = 'CREDIT') AND customer_code IS NOT NULL AND customer_code != '' ORDER BY customer_name LIMIT 2000");
while ($r = mysqli_fetch_assoc($cust_res)) $customers[] = $r;

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.page-wrap{max-width:1500px;margin:0 auto;padding:0 8px 40px;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:14px;flex-wrap:wrap;}
.breadcrumb a{color:#0e7490;text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}
.ph-row{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-teal{background:#0e7490;color:#fff;}.btn-teal:hover{background:#155e75;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-green{background:#15803d;color:#fff;}.btn-green:hover{background:#166534;}
.btn-purple{background:#7c3aed;color:#fff;}.btn-purple:hover{background:#6d28d9;}
.btn-orange{background:#ea580c;color:#fff;}.btn-orange:hover{background:#c2410c;}
.btn-sm{padding:5px 11px;font-size:12px;}
.btn-xs{padding:3px 8px;font-size:11px;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;display:none;align-items:center;gap:10px;font-size:13px;font-weight:500;}
.alert-success{background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;}
.alert-error{background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;}
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
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.badge-teal{background:#cffafe;color:#0e7490;}
.badge-blue{background:#dbeafe;color:#1e40af;}
.badge-green{background:#dcfce7;color:#15803d;}
.badge-orange{background:#fef3c7;color:#92400e;}
.badge-gray{background:#f3f4f6;color:#6b7280;}
.badge-purple{background:#ede9fe;color:#7c3aed;}
.badge-red{background:#fee2e2;color:#dc2626;}
.checkbox-col{width:35px;text-align:center;}
.sel-chk{width:18px;height:18px;cursor:pointer;accent-color:#0e7490;}
.empty-state{color:#9ca3af;font-size:12px;text-align:center;padding:40px!important;}
.empty-state i{font-size:28px;color:#d1d5db;display:block;margin-bottom:10px;}
.sel-summary{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;display:none;margin-bottom:18px;}
.sel-summary-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;margin-bottom:10px;}
.sel-summary-row{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;}
.sel-summary-item{flex:1;min-width:150px;text-align:center;}
.sel-summary-val{font-size:18px;font-weight:700;color:#0e7490;}

/* Modal */
.modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;opacity:0;visibility:hidden;transition:all .2s;z-index:1000;}
.modal-overlay.open{opacity:1;visibility:visible;}
.modal-box{background:#fff;border-radius:12px;box-shadow:0 20px 25px rgba(0,0,0,.15);width:90%;max-width:540px;max-height:90vh;overflow-y:auto;}
.modal-head{display:flex;align-items:center;gap:10px;padding:20px;border-bottom:1px solid #f3f4f6;font-weight:600;color:#111827;position:sticky;top:0;background:#fff;z-index:2;}
.modal-body{padding:20px;}
.modal-foot{display:flex;gap:10px;padding:14px 20px;border-top:1px solid #f3f4f6;justify-content:flex-end;position:sticky;bottom:0;background:#fff;}
.form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:16px;}
.form-group label{font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;}
.form-group input,.form-group select{padding:9px 11px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;color:#111827;outline:none;}
.form-group input:focus,.form-group select:focus{border-color:#0e7490;box-shadow:0 0 0 3px rgba(14,116,144,.1);}
.form-group textarea{padding:9px 11px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;color:#111827;resize:vertical;min-height:80px;outline:none;}
.form-group textarea:focus{border-color:#0e7490;}
.modal-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
@media (max-width:700px){.modal-grid-2{grid-template-columns:1fr;}}

/* Management Fee Panel */
.mgmt-fee-toggle{display:flex;align-items:center;gap:10px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:12px 14px;margin-bottom:16px;cursor:pointer;user-select:none;transition:background .15s;}
.mgmt-fee-toggle:hover{background:#ffedd5;}
.mgmt-fee-toggle input[type=checkbox]{width:18px;height:18px;cursor:pointer;accent-color:#ea580c;flex-shrink:0;}
.mgmt-fee-toggle-label{font-size:13px;font-weight:600;color:#9a3412;flex:1;}
.mgmt-fee-toggle-sub{font-size:11px;color:#c2410c;margin-top:1px;}
.mgmt-fee-panel{background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:16px;margin-bottom:16px;display:none;}
.mgmt-fee-panel.open{display:block;}
.mgmt-fee-panel .form-group{margin-bottom:12px;}
.mgmt-fee-panel .form-group:last-child{margin-bottom:0;}
.mgmt-fee-preview{background:#ea580c;color:#fff;border-radius:8px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;font-size:13px;font-weight:600;margin-top:10px;}
.mgmt-fee-preview-num{font-size:18px;font-weight:700;}

/* Tab bar inside modal */
.modal-tab-bar{display:flex;gap:2px;border-bottom:2px solid #f3f4f6;margin-bottom:18px;}
.modal-tab{padding:9px 16px;font-size:12px;font-weight:600;color:#6b7280;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s;border-radius:6px 6px 0 0;font-family:inherit;}
.modal-tab.active{color:#0e7490;border-bottom-color:#0e7490;background:#f0f9ff;}
.modal-tab:hover:not(.active){color:#374151;background:#f9fafb;}
.tab-panel{display:none;}.tab-panel.active{display:block;}

/* Totals strip */
.totals-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:18px;}
.totals-strip-item{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;text-align:center;}
.totals-strip-label{font-size:10px;color:#9ca3af;font-weight:600;text-transform:uppercase;margin-bottom:4px;}
.totals-strip-val{font-size:16px;font-weight:700;color:#0e7490;}
.totals-strip-val.orange{color:#ea580c;}

.section-label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.section-label::after{content:'';flex:1;height:1px;background:#f3f4f6;}

/* Mini invoice chips */
.mini-chip{display:inline-block;background:#dcfce7;color:#15803d;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.mini-chip-orange{background:#ffedd5;color:#ea580c;}

/* Grand total bar */
.grand-total-bar{display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,#0e7490,#0369a1);color:#fff;border-radius:8px;padding:14px 18px;margin-top:16px;}
.grand-total-bar-label{font-size:12px;font-weight:600;opacity:.85;}
.grand-total-bar-val{font-size:22px;font-weight:700;}
</style>

<div class="page-wrap">
  <div class="breadcrumb">
    <a href="index.php">Home</a> <span class="sep">/</span>
    <span>Payment Invoices</span> <span class="sep">/</span>
    <span>Create</span>
  </div>

  <div id="alertSuccess" class="alert alert-success"></div>
  <div id="alertError" class="alert alert-error"></div>

  <div class="ph-row">
    <h1 style="font-size:24px;font-weight:700;color:#111827;margin:0;">Create Payment Invoice</h1>
  </div>

  <!-- SELECTION SUMMARY -->
  <div class="sel-summary" id="selSummary">
    <div class="sel-summary-label"><i class="fa-solid fa-check-circle" style="margin-right:6px;"></i>Selection Summary</div>
    <div class="sel-summary-row">
      <div class="sel-summary-item">
        <div class="sel-summary-label" style="margin:0;font-size:10px;text-transform:uppercase;color:#9ca3af;">Count</div>
        <div class="sel-summary-val" id="selCount">0</div>
      </div>
      <div class="sel-summary-item">
        <div class="sel-summary-label" style="margin:0;font-size:10px;text-transform:uppercase;color:#9ca3af;">Total Amount</div>
        <div class="sel-summary-val" id="selTotal">0.00</div>
      </div>
      <div class="sel-summary-item">
        <div class="sel-summary-label" style="margin:0;font-size:10px;text-transform:uppercase;color:#9ca3af;">Discount</div>
        <div class="sel-summary-val" id="selDisc">0.00</div>
      </div>
    </div>
  </div>

  <!-- FILTER BAR -->
  <div class="filter-bar">
    <div class="fg">
      <label>Date From</label>
      <input type="date" id="fDateFrom" />
    </div>
    <div class="fg">
      <label>Date To</label>
      <input type="date" id="fDateTo" />
    </div>
    <div class="fg">
      <label>Category</label>
      <select id="fCategory" onchange="onCategoryChange()">
        <option value="">— All Categories —</option>
        <?php foreach ($categories as $cat): ?>
        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fg" id="subCatWrap" style="display:none;">
      <label>Sub-Category</label>
      <select id="fSubCategory" onchange="loadAllInvoices()">
        <option value="">— All Sub-categories —</option>
      </select>
    </div>
    <div class="fg">
      <label>Customer</label>
      <input type="text" id="fCustomer" placeholder="Search name or code..." />
    </div>
    <div class="fg">
      <button class="btn btn-teal" onclick="loadAllInvoices()"><i class="fa-solid fa-magnifying-glass"></i> Load Invoices</button>
    </div>
    <div class="fg">
      <button class="btn btn-secondary" onclick="resetFilters()"><i class="fa-solid fa-rotate-left"></i> Reset</button>
    </div>
  </div>

  <!-- CAT CUSTOMERS CHIPS -->
  <div id="catCustomersWrap" style="display:none;margin-bottom:16px;">
    <div style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;margin-bottom:8px;">Category Customers:</div>
    <div id="catCustomerChips" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
  </div>

  <!-- INVOICE TABLE -->
  <div class="table-card">
    <div class="table-toolbar">
      <div class="tbl-title">
        <i class="fa-solid fa-list"></i> Credit Invoices
        <span id="invoiceCountLabel" style="color:#9ca3af;font-size:12px;margin-left:8px;"></span>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button class="btn btn-orange btn-sm" onclick="openMgmtFeeModal()">
          <i class="fa-solid fa-percent"></i> Management Fee Invoice
        </button>
        <button class="btn btn-green btn-sm" onclick="openPaymentModal()">
          <i class="fa-solid fa-file-invoice"></i> Create Payment Invoice
        </button>
      </div>
    </div>
    <div class="dt-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th class="checkbox-col"><input type="checkbox" id="selectAllChk" onchange="selectAll(this.checked)" class="sel-chk" /></th>
            <th>Date</th>
            <th>Doc No</th>
            <th>Customer</th>
            <th>Code</th>
            <th class="tr">Discount</th>
            <th class="tr">Amount</th>
          </tr>
        </thead>
        <tbody id="invoiceTbody">
          <tr><td colspan="7" class="empty-state"><i class="fa-solid fa-filter"></i> Set filters above and click "Load Invoices"</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════
     PAYMENT INVOICE MODAL  (Credit invoices + optional mgmt fee)
════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="paymentModal">
  <div class="modal-box">
    <div class="modal-head">
      <i class="fa-solid fa-file-invoice" style="color:#0e7490;font-size:18px;"></i>
      <span>Create Payment Invoice</span>
      <button type="button" onclick="closePaymentModal()" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:16px;margin-left:auto;"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">

      <!-- Totals strip -->
      <div class="totals-strip">
        <div class="totals-strip-item">
          <div class="totals-strip-label">Invoices</div>
          <div class="totals-strip-val" id="mSelCount">0</div>
        </div>
        <div class="totals-strip-item">
          <div class="totals-strip-label">Invoice Total</div>
          <div class="totals-strip-val" id="mSelTotal">0.00</div>
        </div>
        <div class="totals-strip-item">
          <div class="totals-strip-label">Discount</div>
          <div class="totals-strip-val" id="mSelDisc">0.00</div>
        </div>
      </div>

      <!-- Credit invoices recap -->
      <div class="section-label"><i class="fa-solid fa-receipt"></i> Selected Credit Invoices (<span id="mCreditCount">0</span>)</div>
      <div id="mCreditChips" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:18px;">
        <span style="color:#9ca3af;font-size:12px;">None selected — use the table above to select invoices.</span>
      </div>

      <!-- ── Management Fee Toggle ── -->
      <label class="mgmt-fee-toggle" onclick="toggleMgmtFeeInPayment()">
        <input type="checkbox" id="pmIncludeMgmtFee" onclick="event.stopPropagation();toggleMgmtFeeInPayment()" />
        <div>
          <div class="mgmt-fee-toggle-label"><i class="fa-solid fa-percent"></i> Include Management Fee</div>
          <div class="mgmt-fee-toggle-sub">Add a management fee entry to this payment invoice</div>
        </div>
      </label>

      <div class="mgmt-fee-panel" id="pmMgmtFeePanel">
        <div class="section-label"><i class="fa-solid fa-percent"></i> Management Fee Details</div>
        <div class="modal-grid-2">
          <div class="form-group">
            <label>Fee Amount *</label>
            <input type="number" id="pmMgmtFeeAmount" placeholder="0.00" min="0" step="0.01" oninput="updatePaymentGrandTotal()" style="border-color:#fed7aa;" />
          </div>
          <div class="form-group">
            <label>Fee Description</label>
            <input type="text" id="pmMgmtFeeDesc" placeholder="e.g., Monthly management fee" style="border-color:#fed7aa;" />
          </div>
        </div>
        <div class="mgmt-fee-preview">
          <span>Management Fee</span>
          <span class="mgmt-fee-preview-num" id="pmMgmtFeePreview">0.00</span>
        </div>
      </div>

      <!-- Grand Total -->
      <div class="grand-total-bar" id="pmGrandTotalBar" style="display:none;">
        <div>
          <div class="grand-total-bar-label">Grand Total (Invoices + Fee)</div>
        </div>
        <div class="grand-total-bar-val" id="pmGrandTotal">0.00</div>
      </div>

      <!-- Form fields -->
      <div style="margin-top:18px;">
        <div class="section-label"><i class="fa-solid fa-pen-to-square"></i> Invoice Details</div>
        <div class="modal-grid-2">
          <div class="form-group">
            <label>Month & Year *</label>
            <input type="month" id="piMonthYear" required />
          </div>
          <div class="form-group">
            <label>Invoice Date *</label>
            <input type="date" id="piDate" required />
          </div>
          <div class="form-group">
            <label>Subject / Memo</label>
            <input type="text" id="piSubject" placeholder="e.g., Monthly Payment" />
          </div>
          <div class="form-group">
            <label>Category</label>
            <select id="piCategory" onchange="onModalCategoryChange()">
              <option value="">— Select Category —</option>
              <?php foreach ($categories as $cat): ?>
              <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" id="piSubCatWrap" style="display:none;">
            <label>Sub-Category</label>
            <select id="piSubCategory">
              <option value="">— Select Sub-category —</option>
            </select>
          </div>
        </div>
      </div>

    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-secondary" onclick="closePaymentModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button type="button" class="btn btn-green" id="savePaymentBtn" onclick="savePaymentInvoice()">
        <i class="fa-solid fa-floppy-disk"></i> Save Payment Invoice
      </button>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════
     MANAGEMENT FEE INVOICE MODAL  (standalone)
════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="mgmtFeeModal">
  <div class="modal-box">
    <div class="modal-head" style="border-top:4px solid #ea580c;border-radius:12px 12px 0 0;">
      <i class="fa-solid fa-percent" style="color:#ea580c;font-size:18px;"></i>
      <span>Management Fee Invoice</span>
      <button type="button" onclick="closeMgmtFeeModal()" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:16px;margin-left:auto;"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">

      <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:12px 14px;margin-bottom:20px;font-size:12.5px;color:#9a3412;">
        <i class="fa-solid fa-circle-info" style="margin-right:6px;"></i>
        This creates a <strong>standalone management fee invoice</strong> without linking any credit invoices. A unique <strong>MF-YYYY-MM-xxxxxx</strong> number will be generated automatically.
      </div>

      <!-- Fee amount block -->
      <div class="form-group">
        <label>Fee Amount *</label>
        <div style="display:flex;align-items:center;gap:8px;">
          <input type="number" id="mfAmount" placeholder="0.00" min="0" step="0.01"
                 oninput="updateMgmtFeePreview()"
                 style="font-size:22px;font-weight:700;padding:12px 14px;border-color:#fed7aa;flex:1;" />
        </div>
      </div>

      <div style="background:linear-gradient(135deg,#ea580c,#dc2626);color:#fff;border-radius:10px;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <div>
          <div style="font-size:11px;opacity:.8;font-weight:600;text-transform:uppercase;margin-bottom:4px;">Management Fee Amount</div>
          <div style="font-size:28px;font-weight:700;" id="mfPreviewAmount">0.00</div>
        </div>
        <i class="fa-solid fa-percent" style="font-size:36px;opacity:.25;"></i>
      </div>

      <div class="form-group">
        <label>Fee Description</label>
        <input type="text" id="mfDescription" placeholder="e.g., Monthly management fee for June 2025" />
      </div>

      <div class="modal-grid-2">
        <div class="form-group">
          <label>Month & Year *</label>
          <input type="month" id="mfMonthYear" required />
        </div>
        <div class="form-group">
          <label>Invoice Date *</label>
          <input type="date" id="mfDate" required />
        </div>
        <div class="form-group">
          <label>Subject / Memo</label>
          <input type="text" id="mfSubject" placeholder="e.g., Management Fee Invoice" />
        </div>
        <div class="form-group">
          <label>Category</label>
          <select id="mfCategory" onchange="onMfModalCategoryChange()">
            <option value="">— Select Category —</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" id="mfSubCatWrap" style="display:none;">
          <label>Sub-Category</label>
          <select id="mfSubCategory">
            <option value="">— Select Sub-category —</option>
          </select>
        </div>
      </div>

    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-secondary" onclick="closeMgmtFeeModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button type="button" class="btn btn-orange" id="saveMgmtFeeBtn" onclick="saveMgmtFeeInvoice()">
        <i class="fa-solid fa-floppy-disk"></i> Save Management Fee Invoice
      </button>
    </div>
  </div>
</div>

<script>
/* ════════════════════════════════
   STATE
════════════════════════════════ */
let allInvoices = [];
const selectedIds = new Set();

/* ════════════════════════════════
   FILTER PERSISTENCE (URL / GET)
   – keeps filters in the URL so a page
     refresh reloads the same filtered data
════════════════════════════════ */
function persistFiltersToUrl(dateFrom, dateTo, catId, subId, customer) {
  const params = new URLSearchParams();
  params.set('loaded', '1');                       // flag: auto-load on refresh
  if (dateFrom) params.set('date_from', dateFrom);
  if (dateTo)   params.set('date_to', dateTo);
  if (catId)    params.set('category_id', catId);
  if (subId)    params.set('subcategory_id', subId);
  if (customer) params.set('customer', customer);
  history.replaceState(null, '', location.pathname + '?' + params.toString());
}

function clearFiltersFromUrl() {
  history.replaceState(null, '', location.pathname);
}

async function restoreFiltersFromUrl() {
  const p = new URLSearchParams(location.search);
  if (p.get('loaded') !== '1') return;   // nothing was loaded before

  document.getElementById('fDateFrom').value = p.get('date_from') || '';
  document.getElementById('fDateTo').value   = p.get('date_to')   || '';
  document.getElementById('fCustomer').value = p.get('customer')  || '';

  const catId = p.get('category_id')    || '';
  const subId = p.get('subcategory_id') || '';

  if (catId) {
    document.getElementById('fCategory').value = catId;
    await onCategoryChange();                       // loads sub-categories + chips
    if (subId) {
      document.getElementById('fSubCategory').value = subId;
      await loadCatCustomers(catId, subId);
    }
  }

  await loadAllInvoices();                          // re-load the filtered data
}

document.addEventListener('DOMContentLoaded', restoreFiltersFromUrl);

/* ════════════════════════════════
   MAIN PAGE FILTERS / TABLE
════════════════════════════════ */
async function onCategoryChange() {
  const catId = document.getElementById('fCategory').value;
  const wrap  = document.getElementById('subCatWrap');
  const sel   = document.getElementById('fSubCategory');
  sel.innerHTML = '<option value="">— All Sub-categories —</option>';
  wrap.style.display = 'none';
  if (!catId) return;
  const res  = await fetch('ushop_payment_invoice_create.php?ajax=subcats&cat_id=' + catId);
  const subs = await res.json();
  if (subs.length > 0) {
    subs.forEach(s => {
      const o = document.createElement('option');
      o.value = s.id; o.textContent = s.name;
      sel.appendChild(o);
    });
    wrap.style.display = '';
  }
  await loadCatCustomers(catId, 0);
}

async function loadCatCustomers(catId, subId) {
  const wrap = document.getElementById('catCustomersWrap');
  const chip = document.getElementById('catCustomerChips');
  chip.innerHTML = '';
  wrap.style.display = 'none';
  if (!catId || catId === '0') return;
  const res  = await fetch('ushop_payment_invoice_create.php?ajax=cat_customers&cat_id=' + catId + '&sub_id=' + subId);
  const data = await res.json();
  if (data.customers && data.customers.length > 0) {
    data.customers.forEach(c => {
      const el = document.createElement('span');
      el.className = 'badge badge-blue';
      el.textContent = c.customer_code + ' - ' + c.customer_name;
      chip.appendChild(el);
    });
    wrap.style.display = '';
  }
}

async function loadAllInvoices() {
  const dateFrom = document.getElementById('fDateFrom').value;
  const dateTo   = document.getElementById('fDateTo').value;
  const catId    = document.getElementById('fCategory').value;
  const subId    = document.getElementById('fSubCategory').value;
  const customer = document.getElementById('fCustomer').value;

  const res = await fetch('ushop_payment_invoice_create.php?ajax=load_invoices' +
    '&date_from=' + encodeURIComponent(dateFrom) +
    '&date_to='   + encodeURIComponent(dateTo) +
    '&category_id=' + encodeURIComponent(catId) +
    '&subcategory_id=' + encodeURIComponent(subId) +
    '&customer=' + encodeURIComponent(customer)
  );
  allInvoices = await res.json();
  renderInvoiceTable();
  updateSummary();
  document.getElementById('invoiceCountLabel').textContent = '(' + allInvoices.length + ' invoices)';

  /* Save filters into the URL so refresh keeps the same data */
  persistFiltersToUrl(dateFrom, dateTo, catId, subId, customer);
}

function renderInvoiceTable() {
  const tbody = document.getElementById('invoiceTbody');
  if (allInvoices.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="empty-state"><i class="fa-solid fa-inbox"></i> No invoices found</td></tr>';
    return;
  }
  tbody.innerHTML = allInvoices.map(r => {
    const chk = selectedIds.has(String(r.id)) ? 'checked' : '';
    return `<tr>
      <td class="checkbox-col"><input type="checkbox" ${chk} data-id="${r.id}" onchange="onRowCheckChange(this)" class="sel-chk" /></td>
      <td><span class="badge badge-teal">${formatDate(r.invoice_date)}</span></td>
      <td style="font-weight:700;color:#0e7490;">${escHtml(r.doc_no || '—')}</td>
      <td>${r.customer_name ? escHtml(r.customer_name) : '<span style="color:#9ca3af;font-size:11px;">Walk-in</span>'}</td>
      <td>${r.customer_code ? '<span class="badge badge-blue">#' + escHtml(r.customer_code) + '</span>' : '<span style="color:#d1d5db;">—</span>'}</td>
      <td class="tr" style="color:#92400e;">${fmtNum(r.total_discount)}</td>
      <td class="tr" style="font-weight:700;color:#15803d;">${fmtNum(r.total_amount)}</td>
    </tr>`;
  }).join('');
}

function onRowCheckChange(chk) {
  const id = chk.dataset.id;
  if (chk.checked) selectedIds.add(id);
  else selectedIds.delete(id);
  updateSelectAllState();
  updateSummary();
}

function selectAll(checked) {
  if (checked) allInvoices.forEach(r => selectedIds.add(String(r.id)));
  else selectedIds.clear();
  renderInvoiceTable();
  updateSummary();
}

function updateSelectAllState() {
  const chk = document.getElementById('selectAllChk');
  if (allInvoices.length === 0) { chk.checked = false; return; }
  chk.checked = allInvoices.every(r => selectedIds.has(String(r.id)));
}

function resetFilters() {
  document.getElementById('fDateFrom').value = '';
  document.getElementById('fDateTo').value   = '';
  document.getElementById('fCategory').value = '';
  document.getElementById('fSubCategory').innerHTML = '<option value="">— All Sub-categories —</option>';
  document.getElementById('subCatWrap').style.display = 'none';
  document.getElementById('fCustomer').value = '';
  document.getElementById('catCustomersWrap').style.display = 'none';
  document.getElementById('catCustomerChips').innerHTML = '';
  allInvoices = [];
  selectedIds.clear();
  document.getElementById('invoiceTbody').innerHTML = '<tr><td colspan="7" class="empty-state"><i class="fa-solid fa-filter"></i> Set filters above and click "Load Invoices"</td></tr>';
  document.getElementById('invoiceCountLabel').textContent = '';
  document.getElementById('selSummary').style.display = 'none';
  document.getElementById('selectAllChk').checked = false;

  /* Clear saved filters from the URL */
  clearFiltersFromUrl();
}

function updateSummary() {
  const sel  = allInvoices.filter(r => selectedIds.has(String(r.id)));
  const cnt  = sel.length;
  const tot  = sel.reduce((s, r) => s + parseFloat(r.total_amount  || 0), 0);
  const disc = sel.reduce((s, r) => s + parseFloat(r.total_discount || 0), 0);
  document.getElementById('selCount').textContent = cnt;
  document.getElementById('selTotal').textContent = fmtNum(tot);
  document.getElementById('selDisc').textContent  = fmtNum(disc);
  document.getElementById('selSummary').style.display = cnt > 0 ? '' : 'none';
}

/* ════════════════════════════════
   PAYMENT INVOICE MODAL
════════════════════════════════ */
function openPaymentModal() {
  const today     = new Date().toISOString().slice(0, 10);
  const monthYear = new Date().toISOString().slice(0, 7);

  document.getElementById('piMonthYear').value = monthYear;
  document.getElementById('piDate').value      = today;
  document.getElementById('piSubject').value   = '';
  document.getElementById('piCategory').value  = '';
  document.getElementById('piSubCatWrap').style.display = 'none';
  document.getElementById('piSubCategory').innerHTML = '<option value="">— Select Sub-category —</option>';

  // Reset mgmt fee section
  document.getElementById('pmIncludeMgmtFee').checked = false;
  document.getElementById('pmMgmtFeePanel').classList.remove('open');
  document.getElementById('pmMgmtFeeAmount').value = '';
  document.getElementById('pmMgmtFeeDesc').value   = '';
  document.getElementById('pmMgmtFeePreview').textContent = '0.00';
  document.getElementById('pmGrandTotalBar').style.display = 'none';

  renderCreditRecap();
  updateModalTotals();

  document.getElementById('paymentModal').classList.add('open');
}

function closePaymentModal() {
  document.getElementById('paymentModal').classList.remove('open');
}

function toggleMgmtFeeInPayment() {
  const chk   = document.getElementById('pmIncludeMgmtFee');
  const panel = document.getElementById('pmMgmtFeePanel');
  // The label click toggles the checkbox, so read after toggle
  setTimeout(() => {
    panel.classList.toggle('open', chk.checked);
    updatePaymentGrandTotal();
  }, 0);
}

function updatePaymentGrandTotal() {
  const chk      = document.getElementById('pmIncludeMgmtFee');
  const feeAmt   = parseFloat(document.getElementById('pmMgmtFeeAmount').value || 0);
  document.getElementById('pmMgmtFeePreview').textContent = fmtNum(feeAmt);

  const sel     = allInvoices.filter(r => selectedIds.has(String(r.id)));
  const invTot  = sel.reduce((s, r) => s + parseFloat(r.total_amount || 0), 0);
  const grandTot = invTot + (chk.checked ? feeAmt : 0);

  const bar = document.getElementById('pmGrandTotalBar');
  if (chk.checked && (invTot > 0 || feeAmt > 0)) {
    bar.style.display = '';
    document.getElementById('pmGrandTotal').textContent = fmtNum(grandTot);
  } else {
    bar.style.display = 'none';
  }
}

function renderCreditRecap() {
  const sel = allInvoices.filter(r => selectedIds.has(String(r.id)));
  document.getElementById('mCreditCount').textContent = sel.length;
  const chipWrap = document.getElementById('mCreditChips');
  if (sel.length === 0) {
    chipWrap.innerHTML = '<span style="color:#9ca3af;font-size:12px;">None selected — use the table above to select invoices.</span>';
    return;
  }
  chipWrap.innerHTML = sel.map(r =>
    `<span class="mini-chip">${escHtml(r.doc_no || ('#' + r.id))} – ${fmtNum(r.total_amount)}</span>`
  ).join('');
}

async function onModalCategoryChange() {
  const catId = document.getElementById('piCategory').value;
  const wrap  = document.getElementById('piSubCatWrap');
  const sel   = document.getElementById('piSubCategory');
  sel.innerHTML = '<option value="">— Select Sub-category —</option>';
  wrap.style.display = 'none';
  if (!catId) return;
  const res  = await fetch('ushop_payment_invoice_create.php?ajax=subcats&cat_id=' + catId);
  const subs = await res.json();
  if (subs.length > 0) {
    subs.forEach(s => {
      const o = document.createElement('option');
      o.value = s.id; o.textContent = s.name;
      sel.appendChild(o);
    });
    wrap.style.display = '';
  }
}

function updateModalTotals() {
  const selCredit = allInvoices.filter(r => selectedIds.has(String(r.id)));
  const cnt  = selCredit.length;
  const tot  = selCredit.reduce((s, r) => s + parseFloat(r.total_amount  || 0), 0);
  const disc = selCredit.reduce((s, r) => s + parseFloat(r.total_discount || 0), 0);
  document.getElementById('mSelCount').textContent = cnt;
  document.getElementById('mSelTotal').textContent = fmtNum(tot);
  document.getElementById('mSelDisc').textContent  = fmtNum(disc);
}

async function savePaymentInvoice() {
  const piMonthYear = document.getElementById('piMonthYear').value;
  const piDate      = document.getElementById('piDate').value;
  const piSubject   = document.getElementById('piSubject').value.trim();
  const piCatId     = document.getElementById('piCategory').value;
  const piSubId     = document.getElementById('piSubCategory').value;
  const includeFee  = document.getElementById('pmIncludeMgmtFee').checked;
  const feeAmt      = parseFloat(document.getElementById('pmMgmtFeeAmount').value || 0);
  const feeDesc     = document.getElementById('pmMgmtFeeDesc').value.trim();

  if (!piMonthYear) { showAlert('error', 'Please select a month and year.'); return; }
  if (!piDate)      { showAlert('error', 'Please select an invoice date.'); return; }
  if (selectedIds.size === 0 && !includeFee) {
    showAlert('error', 'Select at least one credit invoice or include a management fee.'); return;
  }
  if (includeFee && feeAmt <= 0) {
    showAlert('error', 'Management fee amount must be greater than zero.'); return;
  }

  const btn = document.getElementById('savePaymentBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

  const fd = new FormData();
  fd.append('ajax_save',            '1');
  fd.append('pi_month_year',        piMonthYear);
  fd.append('pi_date',              piDate);
  fd.append('pi_subject',           piSubject);
  fd.append('pi_category_id',       piCatId);
  fd.append('pi_subcategory_id',    piSubId);
  fd.append('include_mgmt_fee',     includeFee ? '1' : '0');
  fd.append('mgmt_fee_amount',      feeAmt);
  fd.append('mgmt_fee_description', feeDesc);
  selectedIds.forEach(id => fd.append('selected_ids[]', id));

  const res  = await fetch('ushop_payment_invoice_create.php', { method: 'POST', body: fd });
  const data = await res.json();

  btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Payment Invoice';

  if (data.success) {
    closePaymentModal();
    showAlert('success', '<i class="fa-solid fa-check-circle"></i> ' + data.message);
    selectedIds.clear();
    renderInvoiceTable();
    updateSummary();
    document.getElementById('selectAllChk').checked = false;
    setTimeout(() => location.reload(), 2500);   // URL still has filters → data reloads automatically
  } else {
    showAlert('error', data.message || 'An error occurred.');
  }
}

/* ════════════════════════════════
   MANAGEMENT FEE MODAL (standalone)
════════════════════════════════ */
function openMgmtFeeModal() {
  const today     = new Date().toISOString().slice(0, 10);
  const monthYear = new Date().toISOString().slice(0, 7);

  document.getElementById('mfAmount').value      = '';
  document.getElementById('mfDescription').value = '';
  document.getElementById('mfMonthYear').value   = monthYear;
  document.getElementById('mfDate').value        = today;
  document.getElementById('mfSubject').value     = 'Management Fee Invoice';
  document.getElementById('mfCategory').value    = '';
  document.getElementById('mfSubCatWrap').style.display = 'none';
  document.getElementById('mfSubCategory').innerHTML = '<option value="">— Select Sub-category —</option>';
  document.getElementById('mfPreviewAmount').textContent = '0.00';

  document.getElementById('mgmtFeeModal').classList.add('open');
}

function closeMgmtFeeModal() {
  document.getElementById('mgmtFeeModal').classList.remove('open');
}

function updateMgmtFeePreview() {
  const amt = parseFloat(document.getElementById('mfAmount').value || 0);
  document.getElementById('mfPreviewAmount').textContent = fmtNum(amt);
}

async function onMfModalCategoryChange() {
  const catId = document.getElementById('mfCategory').value;
  const wrap  = document.getElementById('mfSubCatWrap');
  const sel   = document.getElementById('mfSubCategory');
  sel.innerHTML = '<option value="">— Select Sub-category —</option>';
  wrap.style.display = 'none';
  if (!catId) return;
  const res  = await fetch('ushop_payment_invoice_create.php?ajax=subcats&cat_id=' + catId);
  const subs = await res.json();
  if (subs.length > 0) {
    subs.forEach(s => {
      const o = document.createElement('option');
      o.value = s.id; o.textContent = s.name;
      sel.appendChild(o);
    });
    wrap.style.display = '';
  }
}

async function saveMgmtFeeInvoice() {
  const mfMonthYear = document.getElementById('mfMonthYear').value;
  const mfDate      = document.getElementById('mfDate').value;
  const mfAmount    = parseFloat(document.getElementById('mfAmount').value || 0);
  const mfDesc      = document.getElementById('mfDescription').value.trim();
  const mfSubject   = document.getElementById('mfSubject').value.trim();
  const mfCatId     = document.getElementById('mfCategory').value;
  const mfSubId     = document.getElementById('mfSubCategory').value;

  if (!mfMonthYear)  { showAlert('error', 'Please select a month and year.'); return; }
  if (!mfDate)       { showAlert('error', 'Please select an invoice date.'); return; }
  if (mfAmount <= 0) { showAlert('error', 'Fee amount must be greater than zero.'); return; }

  const btn = document.getElementById('saveMgmtFeeBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

  const fd = new FormData();
  fd.append('ajax_save',            '1');
  fd.append('pi_month_year',        mfMonthYear);
  fd.append('pi_date',              mfDate);
  fd.append('pi_subject',           mfSubject || 'Management Fee Invoice');
  fd.append('pi_category_id',       mfCatId);
  fd.append('pi_subcategory_id',    mfSubId);
  fd.append('include_mgmt_fee',     '1');
  fd.append('mgmt_fee_amount',      mfAmount);
  fd.append('mgmt_fee_description', mfDesc);
  // No credit invoices selected — standalone fee invoice

  const res  = await fetch('ushop_payment_invoice_create.php', { method: 'POST', body: fd });
  const data = await res.json();

  btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Management Fee Invoice';

  if (data.success) {
    closeMgmtFeeModal();
    showAlert('success', '<i class="fa-solid fa-check-circle"></i> ' + data.message);
    setTimeout(() => location.reload(), 2500);
  } else {
    showAlert('error', data.message || 'An error occurred.');
  }
}

/* ════════════════════════════════
   HELPERS
════════════════════════════════ */
function formatDate(d) {
  if (!d) return '—';
  return new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' });
}
function fmtNum(n) {
  return parseFloat(n || 0).toLocaleString('en-US', { minimumFractionDigits:2, maximumFractionDigits:2 });
}
function escHtml(str) {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}
function showAlert(type, msg) {
  const el = document.getElementById(type === 'success' ? 'alertSuccess' : 'alertError');
  el.innerHTML = msg;
  el.style.display = 'flex';
  setTimeout(() => el.style.display = 'none', 5000);
}

/* Close modals on backdrop click */
document.getElementById('paymentModal').addEventListener('click', function(e){ if (e.target === this) closePaymentModal(); });
document.getElementById('mgmtFeeModal').addEventListener('click', function(e){ if (e.target === this) closeMgmtFeeModal(); });
</script>

<?php include 'footer.php'; ?>