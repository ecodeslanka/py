<?php
include 'config.php';
include 'header.php';

$field_summary_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$field_summary_id) { header('Location: field_summary_list.php'); exit; }

$summary_result = mysqli_query($conn,"SELECT * FROM field_summary WHERE id=$field_summary_id");
if (!$summary_result || mysqli_num_rows($summary_result)===0) { header('Location: field_summary_list.php'); exit; }
$summary = mysqli_fetch_assoc($summary_result);
$delivery_date = $summary['delivery_date'] ?? $summary['visit_date'] ?? $summary['summary_date'] ?? date('Y-m-d');

/* ── ensure tables exist ── */
mysqli_query($conn,"CREATE TABLE IF NOT EXISTS invoice_payments (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  field_summary_id        INT           NOT NULL,
  field_summary_detail_id INT           NOT NULL,
  t_code                  VARCHAR(50)   NULL,
  invoice_num             VARCHAR(100)  NULL,
  payment_method          VARCHAR(20)   NOT NULL DEFAULT 'cash',
  payment_date            DATE          NULL,
  amount                  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount_to_bank          DECIMAL(12,2) DEFAULT 0.00,
  reference_no            VARCHAR(100)  NULL,
  collected_by            VARCHAR(50)   NULL,
  cheque_mode             VARCHAR(50)   NULL,
  remarks                 TEXT          NULL,
  created_at              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_fs  (field_summary_id),
  INDEX idx_det (field_summary_detail_id),
  INDEX idx_inv (invoice_num)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── ensure tot_dis column exists in field_summary_details ── */
$col_check = mysqli_query($conn,"SHOW COLUMNS FROM field_summary_details LIKE 'tot_dis'");
if ($col_check && mysqli_num_rows($col_check) === 0) {
    mysqli_query($conn,"ALTER TABLE field_summary_details ADD COLUMN tot_dis DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER promotion_discount");
}

/* ── ensure to_be_delivery column exists in field_summary_details ── */
$tbd_check = mysqli_query($conn,"SHOW COLUMNS FROM field_summary_details LIKE 'to_be_delivery'");
if ($tbd_check && mysqli_num_rows($tbd_check) === 0) {
    mysqli_query($conn,"ALTER TABLE field_summary_details ADD COLUMN to_be_delivery TINYINT(1) NOT NULL DEFAULT 0");
}

/* ── paid totals per detail row — ONLY payments on this delivery date ── */
$paid_map        = [];
$cash_paid_map   = [];
$cheque_paid_map = [];
$pt = mysqli_query($conn,
    "SELECT ip.field_summary_detail_id, ip.amount, ip.payment_method
     FROM invoice_payments ip
     WHERE ip.field_summary_id=$field_summary_id
       AND ip.payment_date = '" . mysqli_real_escape_string($conn, $delivery_date) . "'");
if ($pt) {
    while ($prow = mysqli_fetch_assoc($pt)) {
        $did = intval($prow['field_summary_detail_id']);
        $amt = floatval($prow['amount']);
        if (!isset($paid_map[$did]))        $paid_map[$did]        = 0.00;
        if (!isset($cash_paid_map[$did]))   $cash_paid_map[$did]   = 0.00;
        if (!isset($cheque_paid_map[$did])) $cheque_paid_map[$did] = 0.00;
        $paid_map[$did] += $amt;
        if ($prow['payment_method'] === 'cash')   $cash_paid_map[$did]   += $amt;
        if ($prow['payment_method'] === 'cheque') $cheque_paid_map[$did] += $amt;
    }
    foreach ($paid_map        as $k => $v) $paid_map[$k]        = round($v, 2);
    foreach ($cash_paid_map   as $k => $v) $cash_paid_map[$k]   = round($v, 2);
    foreach ($cheque_paid_map as $k => $v) $cheque_paid_map[$k] = round($v, 2);
}

/* ── payment count per detail row (for view button badge) — ONLY this delivery date ── */
$pay_count_map = [];
$pcq = mysqli_query($conn,
    "SELECT field_summary_detail_id, COUNT(*) AS cnt
     FROM invoice_payments
     WHERE field_summary_id=$field_summary_id
       AND payment_date = '" . mysqli_real_escape_string($conn, $delivery_date) . "'
     GROUP BY field_summary_detail_id");
if ($pcq) while ($pcr = mysqli_fetch_assoc($pcq))
    $pay_count_map[intval($pcr['field_summary_detail_id'])] = intval($pcr['cnt']);

/* ── credit requests map ── */
$credit_req_map = [];
$crq = mysqli_query($conn,
    "SELECT DISTINCT field_summary_detail_id FROM credit_requests
     WHERE field_summary_id=$field_summary_id");
if ($crq) while ($cr = mysqli_fetch_assoc($crq))
    $credit_req_map[intval($cr['field_summary_detail_id'])] = true;

/* ── secondary invoice map: bill_no => final_bill_amount for ikea value ── */
$sinv_map = [];
$sinv_q = mysqli_query($conn,
    "SELECT bill_no, final_bill_amount
     FROM secondary_invoice_import_details
     WHERE delivery_date = '" . mysqli_real_escape_string($conn, $delivery_date) . "'
       AND status = 'imported'");
if ($sinv_q) {
    while ($sinv_row = mysqli_fetch_assoc($sinv_q)) {
        $sinv_map[trim($sinv_row['bill_no'])] = floatval($sinv_row['final_bill_amount']);
    }
}

/* ── detail rows with customer info ── */
$details_result = mysqli_query($conn,
    "SELECT d.*,
            COALESCE(NULLIF(d.customer_name,''), c.shop_name, d.invoice_num) AS display_customer_name,
            c.payment_mode AS customer_payment_mode,
            c.credit_days, c.special_credit_policy_days, c.credit_limit
     FROM field_summary_details d
     LEFT JOIN customers c ON c.t_code = d.t_code
     WHERE d.field_summary_id=$field_summary_id
     ORDER BY d.invoice_num");

/* ── banks ── */
$banks_list = [];
$br = mysqli_query($conn,"SELECT id,bank_code,bank_name FROM banks WHERE active=1 ORDER BY bank_name");
if ($br) while ($b = mysqli_fetch_assoc($br)) $banks_list[] = $b;

/* ── emergency credit reasons ── */
$emg_reasons = [];
$emg_r = mysqli_query($conn,"SELECT id, reason FROM emergency_credit_reasons WHERE active=1 ORDER BY reason ASC");
if ($emg_r) while ($er = mysqli_fetch_assoc($emg_r)) $emg_reasons[] = $er;

/* helper: zero-to-empty for inputs */
function zv($val) {
    $v = floatval($val);
    return $v == 0 ? '' : $v;
}
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>
/* ─── base ─── */
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px;}
.card-title{font-size:16px;font-weight:600;margin-bottom:12px;color:#1f2937;}
.hint-text{font-size:12px;color:#6b7280;margin-bottom:16px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;font-family:'Inter',sans-serif;text-decoration:none;}
.btn-primary{background:#000;color:#fff;}.btn-primary:hover{background:#333;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-success{background:#22c55e;color:#fff;}.btn-success:hover{background:#16a34a;}
.btn:disabled{opacity:.5;cursor:not-allowed;}
.alert{padding:12px 16px;border-radius:6px;margin-bottom:20px;display:flex;align-items:center;gap:8px;font-size:13px;}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.spinner{border:3px solid #f3f3f3;border-top:3px solid #000;border-radius:50%;width:18px;height:18px;animation:spin 1s linear infinite;display:inline-block;vertical-align:middle;}
@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}
.btn-credit{background:#7c3aed;color:#fff;}.btn-credit:hover{background:#6d28d9;}

/* ─── SEARCH BAR ─── */
.table-toolbar{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-bottom:12px;flex-wrap:wrap;}
.search-wrap{position:relative;min-width:220px;max-width:360px;}
.search-wrap input{width:100%;padding:8px 10px 8px 34px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;color:#333;background:#fff;outline:none;transition:border-color .2s;box-sizing:border-box;}
.search-wrap input:focus{border-color:#000;}
.search-wrap .search-icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;pointer-events:none;}
.search-clear{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#9ca3af;cursor:pointer;font-size:12px;display:none;padding:0 2px;}
.search-clear.visible{display:block;}
.search-result-count{font-size:12px;color:#6b7280;white-space:nowrap;}

/* ─── BULK PANEL ─── */
.bulk-panel{display:none;align-items:center;gap:10px;background:#1e3a5f;border-radius:8px;padding:10px 16px;margin-bottom:12px;flex-wrap:wrap;}
.bulk-panel.active{display:flex;}
.bulk-info{display:flex;align-items:center;gap:8px;color:#fff;font-size:13px;font-weight:600;}
.bulk-badge{background:#3b82f6;color:#fff;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:700;}
.bulk-total{color:#93c5fd;font-size:12px;}
.bulk-sep{width:1px;height:24px;background:rgba(255,255,255,.2);}
.bulk-pay-group{display:flex;align-items:center;gap:8px;flex:1;min-width:260px;}
.bulk-pay-group label{color:#bfdbfe;font-size:12px;font-weight:600;white-space:nowrap;}
.bulk-amount-wrap{position:relative;display:flex;align-items:center;}
.bulk-amount-wrap input{padding:7px 10px;border:1px solid #3b82f6;border-radius:6px;background:#fff;font-size:13px;font-family:'Inter',sans-serif;width:130px;outline:none;-moz-appearance:textfield;}
.bulk-amount-wrap input::-webkit-outer-spin-button,.bulk-amount-wrap input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
.bulk-date-input{padding:7px 10px;border:1px solid #3b82f6;border-radius:6px;background:#fff;font-size:13px;font-family:'Inter',sans-serif;outline:none;color:#333;cursor:pointer;}
.bulk-date-input:focus{border-color:#60a5fa;box-shadow:0 0 0 2px rgba(96,165,250,.25);}
.btn-bulk-pay{background:#22c55e;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:12px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;transition:background .2s;}
.btn-bulk-pay:hover{background:#16a34a;}
.btn-bulk-pay:disabled{opacity:.6;cursor:not-allowed;}
.btn-bulk-clear{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);padding:7px 12px;border-radius:6px;font-size:12px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;transition:all .2s;white-space:nowrap;}
.btn-bulk-clear:hover{background:rgba(255,255,255,.25);}

/* ─── CHECKBOX COLUMN ─── */
.col-check{width:36px;text-align:center;}
.row-check-cell{text-align:center;vertical-align:middle;}
input.row-select{width:15px;height:15px;cursor:pointer;accent-color:#3b82f6;}
input#selectAll{width:15px;height:15px;cursor:pointer;accent-color:#3b82f6;}
tr.row-selected{outline:2px solid #3b82f6;outline-offset:-1px;}

/* ─── table ─── */
.table-responsive{overflow-x:auto;overflow-y:auto;max-height:65vh;border:1px solid #e5e5e5;border-radius:8px;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead{background:#fef3c7;border-bottom:2px solid #fbbf24;}
.data-table th{padding:10px 8px;font-weight:700;color:#78350f;font-size:11px;white-space:nowrap;text-align:left;position:sticky;top:0;z-index:10;background:#fef3c7;box-shadow:0 2px 0 #fbbf24;}
.data-table tbody tr{border-bottom:1px solid #e8e8e8;transition:filter .15s;}
.data-table tbody tr:hover{filter:brightness(.96);}
.data-table tbody td{padding:7px 8px;color:#333;vertical-align:middle;}
.data-table tfoot td{padding:10px 8px;font-weight:700;color:#166534;}
tr.row-other{background:#fff;}
/* ─── Remove number input spinner arrows ─── */
.edit-input{
    width:100%;padding:5px 7px;border:1px solid #e5e5e5;border-radius:4px;
    font-size:12px;font-family:'Inter',sans-serif;text-align:right;
    min-width:80px;box-sizing:border-box;
    -moz-appearance:textfield;
}
.edit-input::-webkit-outer-spin-button,
.edit-input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
.edit-input:focus{outline:none;border-color:#000;background:#fffbeb;}
.edit-input[readonly]{background:#f0fdf4;color:#166534;font-weight:700;border-color:#86efac;cursor:default;}
.paid-cell{font-size:12px;font-weight:700;color:#166534;}
.balance-cell{font-size:12px;font-weight:700;}
.balance-cell.has-balance{color:#dc2626;}
.balance-cell.settled{color:#6b7280;}

/* ─── PAID COLUMNS ─── */
.paid-cell{font-size:12px;font-weight:700;color:#166534;}
.paid-cell.row-cash-paid{color:#166534;}
.paid-cell.row-cheque-paid{color:#1e40af;}
.pb-emg{display:inline-flex;align-items:center;gap:2px;padding:1px 6px;border-radius:3px;font-size:10px;font-weight:700;background:#fdf4ff;color:#7c3aed;border:1px solid #e9d5ff;margin-left:4px;}

.btn-pay{display:inline-flex;align-items:center;padding:5px 12px;border-radius:4px;border:none;font-size:12px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;background:#22c55e;color:#fff;transition:background .2s;white-space:nowrap;}
.btn-pay:hover{background:#16a34a;}
.btn-pay.partial{background:#f59e0b;}.btn-pay.partial:hover{background:#d97706;}
.btn-pay.settled{background:#6b7280;}.btn-pay.settled:hover{background:#4b5563;}
.se-badge{display:inline-block;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:700;white-space:nowrap;}
.se-excess{background:#f0fdf4;color:#166534;}.se-short{background:#fef2f2;color:#991b1b;}.se-neutral{background:#f5f5f5;color:#999;}
/* ─── ROW COLOURS ─── */
tr.row-cash   {background:#c1ffd4;}
tr.row-cheque {background:#99c5ff;}
tr.row-credit {background:#ffadad;}
tr.row-other  {background:#fff;}

/* ─── VIEW PAYMENTS BUTTON & MODAL ─── */
.btn-view-pay{display:inline-flex;align-items:center;padding:5px 8px;border-radius:4px;border:1px solid #e5e5e5;font-size:11px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;background:#fff;color:#6b7280;transition:all .2s;white-space:nowrap;margin-left:4px;gap:3px;}
.btn-view-pay:hover{background:#f3f4f6;color:#374151;border-color:#d1d5db;}
.btn-view-pay.has-payments{color:#7c3aed;border-color:#ddd6fe;background:#faf5ff;}
.btn-view-pay.has-payments:hover{background:#f3e8ff;}
.btn-view-pay .vp-count{background:#7c3aed;color:#fff;border-radius:10px;padding:0 5px;font-size:10px;font-weight:700;min-width:14px;text-align:center;line-height:16px;}
.view-pay-backdrop{position:fixed;inset:0;z-index:999998;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;padding:16px;}
.view-pay-backdrop.open{display:flex;}
.view-pay-dialog{background:#fff;border-radius:12px;width:100%;max-width:720px;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;}
.view-pay-header{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #e5e5e5;background:#fafafa;}
.view-pay-header h3{font-size:15px;font-weight:700;color:#1f2937;margin:0;display:flex;align-items:center;gap:8px;}
.view-pay-body{overflow-y:auto;flex:1;padding:16px 20px;}
.view-pay-empty{text-align:center;padding:30px;color:#9ca3af;font-size:13px;}
.view-pay-card{background:#f9fafb;border:1px solid #e5e5e5;border-radius:8px;padding:12px 14px;margin-bottom:10px;}
.view-pay-card.reversed{opacity:.5;background:#fef2f2;border-color:#fecaca;}
.vpc-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;}
.vpc-method{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:5px;font-size:11px;font-weight:700;text-transform:uppercase;}
.vpc-method.cash{background:#dcfce7;color:#166534;}
.vpc-method.cheque{background:#dbeafe;color:#1e40af;}
.vpc-amt{font-size:15px;font-weight:800;color:#1f2937;}
.vpc-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;font-size:12px;}
.vpc-grid-item{display:flex;flex-direction:column;}
.vpc-grid-item .vl{font-size:10px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em;}
.vpc-grid-item .vv{color:#374151;font-weight:500;margin-top:1px;}
.vpc-cheques{margin-top:8px;padding-top:8px;border-top:1px dashed #e5e5e5;}
.vpc-chq-row{display:flex;align-items:center;justify-content:space-between;padding:4px 8px;font-size:12px;color:#374151;background:#fff;border:1px solid #e5e5e5;border-radius:5px;margin-bottom:4px;}
.vpc-chq-status{display:inline-block;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;}
.vpc-chq-status.pending{background:#fef3c7;color:#92400e;}
.vpc-chq-status.cleared{background:#dcfce7;color:#166534;}
.vpc-chq-status.returned{background:#fef2f2;color:#991b1b;}
.vpc-chq-status.sentback{background:#fce7f3;color:#9d174d;}
.vpc-emg{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-bottom:10px;font-size:12px;color:#78350f;display:flex;align-items:center;gap:8px;}
.vpc-reversed-badge{background:#ef4444;color:#fff;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;margin-left:6px;}
@media(max-width:700px){.vpc-grid{grid-template-columns:1fr 1fr;}}

/* ─── modal ─── */
.modal-backdrop{position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;padding:16px;}
.modal-backdrop.open{display:flex;}
.modal-dialog{background:#fff;border-radius:12px;width:100%;max-width:1000px;max-height:94vh;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.25);overflow:hidden;}
.modal-header{display:flex;align-items:flex-start;justify-content:space-between;padding:16px 22px;border-bottom:1px solid #e5e5e5;background:#fafafa;flex-shrink:0;}
.modal-header-left{display:flex;flex-direction:column;gap:4px;flex:1;}
.modal-header-left h3{font-size:17px;font-weight:700;color:#1f2937;margin:0;}
.inv-summary-strip{display:flex;gap:0;flex-wrap:wrap;margin-top:10px;border:1px solid #e5e5e5;border-radius:8px;overflow:hidden;}
.inv-sum-item{flex:1;display:flex;flex-direction:column;padding:10px 16px;border-right:1px solid #e5e5e5;min-width:110px;}
.inv-sum-item:last-child{border-right:none;}
.inv-sum-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.inv-sum-value{font-size:16px;font-weight:800;color:#1f2937;}
.inv-sum-value.green{color:#166534;}.inv-sum-value.red{color:#dc2626;}.inv-sum-value.amber{color:#92400e;}
.pay-mode-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;margin-top:4px;}
.pay-mode-badge.cash{background:#dcfce7;color:#166534;border-color:#bbf7d0;}
.pay-mode-badge.cheque{background:#dbeafe;color:#1e40af;border-color:#bfdbfe;}
.pay-mode-badge.credit{background:#fef3c7;color:#92400e;border-color:#fde68a;}
.modal-close{width:32px;height:32px;border-radius:7px;border:1px solid #e5e5e5;background:#fff;color:#666;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0;margin-left:12px;}
.modal-close:hover{background:#f5f5f5;color:#000;}
.credit-bypass-notice{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:13px;color:#1e40af;display:flex;align-items:flex-start;gap:8px;}
.credit-bypass-notice i{margin-top:1px;flex-shrink:0;}
.emg-warn{background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:12px;color:#78350f;display:none;}
.emg-warn.show{display:flex;align-items:flex-start;gap:7px;}
.modal-body{overflow-y:auto;flex:1;padding:0;}
.pay-section-wrap{padding:20px 22px;}
.pay-block{margin-bottom:20px;}
.pay-block-title{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;padding:10px 14px;border-radius:7px;margin-bottom:14px;display:flex;align-items:center;gap:7px;}
.pay-block-title.cash{background:#dcfce7;color:#166534;border-left:4px solid #22c55e;}
.pay-block-title.cheque{background:#dbeafe;color:#1e40af;border-left:4px solid #3b82f6;}
.pay-block-title.credit-emg{background:#fdf4ff;color:#7c3aed;border-left:4px solid #a855f7;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:12px;font-weight:600;color:#374151;}
.fg label .req{color:#ef4444;margin-left:2px;}
.fctrl{padding:8px 10px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;color:#333;background:#fff;width:100%;box-sizing:border-box;outline:none;transition:border-color .2s;}
.fctrl:focus{border-color:#000;}
select.fctrl{cursor:pointer;}
textarea.fctrl{resize:vertical;min-height:60px;}
/* Remove spinner from modal number inputs too */
input[type=number].fctrl{-moz-appearance:textfield;}
input[type=number].fctrl::-webkit-outer-spin-button,
input[type=number].fctrl::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
.gr{display:grid;gap:12px;margin-bottom:12px;}
.gr2{grid-template-columns:1fr 1fr;}.gr3{grid-template-columns:1fr 1fr 1fr;}.gr4{grid-template-columns:1fr 1fr 1fr 1fr;}
.pay-divider{text-align:center;position:relative;margin:18px 0;}
.pay-divider::before{content:'';position:absolute;top:50%;left:0;right:0;height:1px;background:#e5e5e5;}
.pay-divider span{position:relative;background:#fff;padding:0 12px;font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.07em;}
.emg-row{display:flex;align-items:center;gap:10px;background:#fffbeb;border:1px solid #fde68a;border-radius:7px;padding:8px 12px;margin-bottom:10px;}
.emg-label{font-size:12px;font-weight:600;color:#78350f;cursor:pointer;flex:1;display:flex;align-items:center;gap:6px;}
.toggle-switch{position:relative;width:38px;height:21px;flex-shrink:0;}
.toggle-switch input{opacity:0;width:0;height:0;}
.toggle-slider{position:absolute;inset:0;background:#d1d5db;border-radius:21px;cursor:pointer;transition:.3s;}
.toggle-slider::before{content:'';position:absolute;height:15px;width:15px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.3s;}
.toggle-switch input:checked+.toggle-slider{background:#f59e0b;}
.toggle-switch input:checked+.toggle-slider::before{transform:translateX(17px);}
#toBeDeliveryChk:checked+.toggle-slider{background:#3b82f6!important;}
.emg-box{background:#fffbeb;border:1px solid #fde68a;border-radius:7px;padding:12px;margin-bottom:10px;}
.upload-zone{border:2px dashed #d1d5db;border-radius:8px;padding:14px;text-align:center;background:#f9fafb;cursor:pointer;transition:all .2s;position:relative;margin-top:10px;}
.upload-zone:hover{border-color:#7c3aed;background:#faf5ff;}
.upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.upload-zone i{font-size:20px;color:#9ca3af;margin-bottom:4px;display:block;}
.upload-zone span{font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:2px;}
.upload-zone small{font-size:11px;color:#9ca3af;}
.file-previews{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.file-chip{display:inline-flex;align-items:center;gap:4px;background:#f3f4f6;border-radius:5px;padding:4px 8px;font-size:11px;border:1px solid #e5e5e5;}
.file-chip-del{background:#ef4444;color:#fff;border:none;border-radius:50%;width:14px;height:14px;cursor:pointer;font-size:9px;display:inline-flex;align-items:center;justify-content:center;}
.cheque-card{background:#f8f7ff;border:1px solid #ddd6fe;border-radius:8px;padding:14px;margin-bottom:12px;}
.cheque-card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;}
.cheque-card-title{font-size:12px;font-weight:700;color:#5b21b6;display:flex;align-items:center;gap:6px;}
.btn-remove-cheque{background:#ef4444;color:#fff;border:none;padding:3px 9px;border-radius:4px;font-size:11px;cursor:pointer;display:inline-flex;align-items:center;gap:3px;font-family:'Inter',sans-serif;}
.btn-remove-cheque:hover{background:#dc2626;}
.btn-add-cheque{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:1.5px dashed #7c3aed;border-radius:6px;background:#faf5ff;color:#7c3aed;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:all .2s;}
.btn-add-cheque:hover{background:#f3e8ff;}
.dup-cheque-warn{background:#fef2f2;border:1px solid #fecaca;border-radius:5px;padding:5px 9px;font-size:11px;color:#991b1b;display:none;margin-top:4px;}
.dup-cheque-warn.show{display:block;}
.cust-info-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px;margin-bottom:14px;}
.ci-item{text-align:center;}
.ci-label{font-size:10px;font-weight:600;color:#3b82f6;text-transform:uppercase;letter-spacing:.05em;}
.ci-value{font-size:15px;font-weight:700;color:#1e40af;margin-top:2px;}
.bal-summary{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;margin-top:18px;}
.bal-sum-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;font-size:13px;color:#374151;border-bottom:1px solid #f0f0f0;}
.bal-sum-row:last-child{border-bottom:none;}
.bal-sum-row.total{font-weight:700;color:#1f2937;padding-top:8px;margin-top:4px;border-top:2px solid #e2e8f0;border-bottom:none;}
.bal-sum-row.balance strong{color:#dc2626;font-size:15px;}
/* ─── OVERPAYMENT ─── */
.bal-sum-row.overpay{display:none;}
.bal-sum-row.overpay strong{color:#dc2626;font-size:15px;}
.overpay-alert{display:none;align-items:center;gap:8px;background:#fef2f2;border:1px solid #fecaca;border-radius:7px;padding:10px 14px;margin-top:10px;font-size:12px;color:#991b1b;font-weight:600;}
.overpay-alert.show{display:flex;}
.overpay-alert i{flex-shrink:0;font-size:14px;}
.modal-footer{padding:13px 22px;border-top:1px solid #e5e5e5;background:#fafafa;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-shrink:0;}
.modal-footer-right{display:flex;gap:8px;}
.btn-modal-cancel{padding:8px 16px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#555;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;}
.btn-modal-cancel:hover{background:#f5f5f5;}
.btn-modal-submit{padding:9px 20px;border-radius:6px;border:none;background:#7c3aed;color:#fff;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;display:flex;align-items:center;gap:6px;}
.btn-modal-submit:hover{background:#6d28d9;}
.btn-mark-credit{padding:9px 20px;border-radius:6px;border:none;background:#7c3aed;color:#fff;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;display:flex;align-items:center;gap:6px;}
.btn-mark-credit:hover{background:#6d28d9;}
.select2-container--default .select2-selection--single{height:37px!important;border:1px solid #e0e0e0!important;border-radius:6px!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:35px!important;padding-left:10px!important;font-size:13px!important;color:#333!important;font-family:'Inter',sans-serif!important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:35px!important;}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open .select2-selection--single{border-color:#000!important;}
.select2-dropdown{border:1px solid #e0e0e0!important;border-radius:6px!important;font-size:13px!important;font-family:'Inter',sans-serif!important;}
.select2-results__option--highlighted{background:#000!important;}
tr.row-locked .edit-input:not([readonly]){background:#f0fdf4!important;color:#6b7280!important;border-color:#d1fae5!important;cursor:not-allowed!important;pointer-events:none;opacity:.75;}
tr.row-locked .btn-pay{background:#6b7280!important;cursor:default!important;pointer-events:none!important;}
tr.row-locked{opacity:.88;}
#payToast{position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:10000000;padding:14px 28px;border-radius:10px;font-size:14px;font-weight:700;font-family:'Inter',sans-serif;box-shadow:0 8px 32px rgba(0,0,0,.25);transition:opacity .35s,transform .35s;white-space:nowrap;pointer-events:none;}
.toast-ok{background:#166534;color:#fff;}.toast-err{background:#dc2626;color:#fff;}
@media(max-width:700px){.gr2,.gr3,.gr4{grid-template-columns:1fr;}.inv-summary-strip{flex-wrap:wrap;}.inv-sum-item{min-width:50%;}.cust-info-strip{grid-template-columns:1fr 1fr;}.bulk-panel{flex-direction:column;align-items:flex-start;}}
</style>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <div>
      <h2 class="page-title"><i class="fa-solid fa-edit"></i> Edit Field Summary</h2>
      <p class="page-subtitle">Code: <strong><?php echo htmlspecialchars($summary['field_summary_code']); ?></strong>
         &nbsp;|&nbsp; Route: <?php echo htmlspecialchars($summary['route']); ?>
         &nbsp;|&nbsp; SR: <?php echo htmlspecialchars($summary['sr_code']); ?>
      </p>
    </div>
    <div style="display:flex;gap:8px;">
      <a href="view_payments.php?id=<?php echo $field_summary_id; ?>" class="btn btn-secondary"><i class="fa-solid fa-receipt"></i> View Payments</a>
      <a href="field_summary_list.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>
  </div>
</div>

<div id="alertContainer"></div>

<form id="editSummaryForm">
<input type="hidden" name="field_summary_id" value="<?php echo $field_summary_id; ?>">
<div class="content-card">
  <h3 class="card-title">Invoice Adjustments</h3>
  <p class="hint-text"><i class="fa-solid fa-info-circle"></i>
    <strong>Final B.V</strong> = Net Inv.Amt + Total Discount + Mkt-Rtn + Dmg − Cancelled.
    Row colour is based on the customer's payment mode (green=cash, blue=cheque, red=credit) and never changes.
    Click <strong>Pay</strong> to record a cash or cheque payment. Use <em>Emergency Credit</em> to log a credit request without payment.
    <strong>Balance = Ikea Value − Total Paid.</strong>
  </p>

  <!-- ═══ TOOLBAR: Search right-aligned ═══ -->
  <div class="table-toolbar">
    <span class="search-result-count" id="searchCount"></span>
    <div class="search-wrap">
      <i class="fa-solid fa-search search-icon"></i>
      <input type="text" id="tableSearch" placeholder="Search invoice, customer, T-code, route…" autocomplete="off">
      <button type="button" class="search-clear" id="searchClearBtn" onclick="clearSearch()" title="Clear"><i class="fa-solid fa-xmark"></i></button>
    </div>
  </div>

  <!-- ═══ BULK PAYMENT PANEL (Cash Only) ═══ -->
  <div class="bulk-panel" id="bulkPanel">
    <div class="bulk-info">
      <i class="fa-solid fa-check-square" style="color:#93c5fd;"></i>
      <span><span id="bulkCount" class="bulk-badge">0</span> selected</span>
      <span class="bulk-total">Balance total: <strong id="bulkTotalBalance" style="color:#fde68a;">Rs. 0.00</strong></span>
    </div>
    <div class="bulk-sep"></div>
    <div class="bulk-pay-group">
      <label><i class="fa-solid fa-calendar-day" style="color:#93c5fd;"></i> Date:</label>
      <input type="date" id="bulkPayDate" class="bulk-date-input">
      <label><i class="fa-solid fa-coins" style="color:#fde68a;"></i> Amount (Rs.):</label>
      <div class="bulk-amount-wrap">
        <input type="number" id="bulkCashAmount" step="0.01" min="0" placeholder="e.g. 5000.00">
      </div>
      <span style="background:#22c55e;color:#fff;padding:5px 10px;border-radius:5px;font-size:11px;font-weight:700;white-space:nowrap;"><i class="fa-solid fa-coins"></i> Cash Only</span>
      <button type="button" class="btn-bulk-pay" id="bulkPayBtn" onclick="submitBulkPayment()">
        <i class="fa-solid fa-paper-plane"></i> Apply to All Selected
      </button>
    </div>
    <div class="bulk-sep"></div>
    <button type="button" class="btn-bulk-clear" onclick="clearBulkSelection()"><i class="fa-solid fa-xmark"></i> Deselect All</button>
  </div>

  <div class="table-responsive">
  <table class="data-table" id="detailsTable">
    <thead><tr>
      <th class="col-check"><input type="checkbox" id="selectAll" title="Select all visible rows"></th>
      <th>#</th><th>Invoice</th><th>T-Code</th><th>Customer</th><th>Route</th>
      <th style="min-width:86px;">Net Inv.Amt</th>
      <th style="min-width:96px;">Total Discount</th>
      <th style="min-width:86px;">Mkt-Rtn</th>
      <th style="min-width:86px;">Dmg/Exp/Sh</th>
      <th style="min-width:86px;">Cancelled Amt.</th>
      <th style="min-width:86px;">Final B.V</th>
      <th style="min-width:86px;">Ikea</th>
      <th style="min-width:96px;">Short/Excess</th>
      <th style="min-width:86px;">Cash Paid</th>
      <th style="min-width:86px;">Cheque Paid</th>
      <th style="min-width:86px;">Total Paid</th>
      <th style="min-width:86px;">Balance</th>
      <th style="min-width:100px;">Actions</th>
    </tr></thead>
    <tbody>
    <?php $rn=1; while($d=mysqli_fetch_assoc($details_result)):
        $row_adj  = floatval($d['adjust_net_value']);
        $row_paid = $paid_map[intval($d['id'])] ?? 0.00;

        /* ── CHANGE 1: balance based on ikea value ── */
        $v_ikea_raw = isset($sinv_map[trim($d['invoice_num'])]) ? floatval($sinv_map[trim($d['invoice_num'])]) : 0.00;
        $v_ikea     = zv($v_ikea_raw);
        $row_bal    = max(0.00, $v_ikea_raw - $row_paid);

        $pay_btn_class = $row_bal <= 0.005 ? 'settled' : ($row_paid > 0 ? 'partial' : '');
        $pay_btn_label = $row_bal <= 0.005 ? 'Paid' : 'Pay';

        $pm_raw    = strtolower(trim($d['customer_payment_mode'] ?? ''));
        $row_class = in_array($pm_raw, ['cash','cheque','credit']) ? 'row-'.$pm_raw : 'row-other';

        $tcode_full    = $d['t_code'];
        $tcode_display = strlen($tcode_full) > 5 ? substr($tcode_full, -5) : $tcode_full;

        $v_net    = zv($d['net_value']);
        $v_totdis = zv((floatval($d['scheme_discount']) + floatval($d['promotion_discount']) + floatval($d['tot_dis'] ?? 0)));
        $v_market = zv($d['market_return']);
        $v_damage = zv($d['damage_adjustment']);
        $v_cancel = zv($d['cancel_value']);
        $v_adj    = floatval($d['adjust_net_value']);

        $row_pay_count  = $pay_count_map[intval($d['id'])] ?? 0;
        $row_cash_paid  = $cash_paid_map[intval($d['id'])]   ?? 0.00;
        $row_cheque_paid= $cheque_paid_map[intval($d['id'])] ?? 0.00;
        $has_credit_req = isset($credit_req_map[intval($d['id'])]);
        $view_btn_class = $row_pay_count > 0 ? 'has-payments' : '';
    ?>
    <tr data-id="<?php echo $d['id']; ?>"
        data-fsid="<?php echo $field_summary_id; ?>"
        data-tcode="<?php echo htmlspecialchars($d['t_code']); ?>"
        data-invoice="<?php echo htmlspecialchars($d['invoice_num']); ?>"
        data-customer="<?php echo htmlspecialchars($d['display_customer_name']); ?>"
        data-route="<?php echo htmlspecialchars($d['route']); ?>"
        data-adjust="<?php echo $row_adj; ?>"
        data-paid="<?php echo $row_paid; ?>"
        data-balance="<?php echo $row_bal; ?>"
        data-cash-paid="<?php echo $row_cash_paid; ?>"
        data-cheque-paid="<?php echo $row_cheque_paid; ?>"
        data-has-credit="<?php echo $has_credit_req ? '1' : '0'; ?>"
        data-paymode="<?php echo htmlspecialchars($d['customer_payment_mode'] ?? ''); ?>"
        data-creditlimit="<?php echo floatval($d['credit_limit'] ?? 0); ?>"
        data-creditdays="<?php echo intval($d['credit_days'] ?? 0); ?>"
        data-specialdays="<?php echo htmlspecialchars($d['special_credit_policy_days'] ?? ''); ?>"
        data-updated="<?php echo intval($d['updated'] ?? 0); ?>"
        data-special="<?php echo intval($d['is_special_credit'] ?? 0); ?>"
        data-paycount="<?php echo $row_pay_count; ?>"
        class="<?php echo trim($row_class); ?>">
      <td class="row-check-cell"><input type="checkbox" class="row-select" onchange="onRowCheckChange()"></td>
      <td><?php echo $rn++; ?></td>
      <td><?php echo htmlspecialchars($d['invoice_num']); ?></td>
      <td title="<?php echo htmlspecialchars($tcode_full); ?>"><?php echo htmlspecialchars($tcode_display); ?></td>
      <td><?php echo htmlspecialchars($d['display_customer_name']); ?></td>
      <td><?php echo htmlspecialchars($d['route']); ?></td>
      <td><input type="number" step="0.01" class="edit-input net-value"
                 name="net_value[]" value="<?php echo $v_net; ?>" placeholder="0.00"></td>
      <td><input type="number" step="0.01" class="edit-input tot-dis"
                 name="tot_dis[]" value="<?php echo $v_totdis; ?>" placeholder="0.00"></td>
      <td><input type="number" step="0.01" class="edit-input market-return"
                 name="market_return[]" value="<?php echo $v_market; ?>" placeholder="0.00"></td>
      <td><input type="number" step="0.01" class="edit-input damage-adjustment"
                 name="damage_adjustment[]" value="<?php echo $v_damage; ?>" placeholder="0.00"></td>
      <td><input type="number" step="0.01" class="edit-input cancel-value"
                 name="cancel_value[]" value="<?php echo $v_cancel; ?>" placeholder="0.00"></td>
      <td><input type="number" step="0.01" class="edit-input adjust-net-value"
                 name="adjust_net_value[]" value="<?php echo $v_adj; ?>" readonly></td>
      <td><input type="number" step="0.01" class="edit-input ikea-value"
                 name="ikea_value[]" value="<?php echo $v_ikea; ?>" placeholder="0.00"></td>
      <td><span class="se-badge se-neutral">&mdash;</span>
          <input type="hidden" class="se-val" name="short_excess[]" value="0"></td>
      <td class="paid-cell row-cash-paid"><?php echo $row_cash_paid > 0.005 ? number_format($row_cash_paid,2) : '—'; ?></td>
      <td class="paid-cell row-cheque-paid"><?php echo $row_cheque_paid > 0.005 ? number_format($row_cheque_paid,2) : '—'; ?></td>
      <td class="paid-cell row-paid"><?php echo number_format($row_paid,2); ?></td>
      <td class="balance-cell row-balance <?php echo $row_bal>0.005?'has-balance':'settled'; ?>">
        <?php echo number_format($row_bal,2); ?>
        <?php if($has_credit_req): ?>
        <span class="pb-emg" title="Emergency Credit logged"><i class="fa-solid fa-bolt"></i> EMG</span>
        <?php endif; ?>
      </td>
      <td style="white-space:nowrap;">
        <button type="button" class="btn-pay <?php echo $pay_btn_class; ?>" onclick="openPayModal(this)"><?php echo $pay_btn_label; ?></button>
        <button type="button" class="btn-view-pay <?php echo $view_btn_class; ?>" onclick="openViewPayments(this)" title="View payments">
          <i class="fa-solid fa-eye"></i><?php if($row_pay_count > 0): ?><span class="vp-count"><?php echo $row_pay_count; ?></span><?php endif; ?>
        </button>
        <input type="hidden" name="detail_id[]" value="<?php echo $d['id']; ?>">
      </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
    <tfoot><tr>
      <td></td>
      <td colspan="5" style="text-align:right;font-weight:700;">Total</td>
      <td id="tNet"     style="text-align:right;">0.00</td>
      <td id="tTotDis"  style="text-align:right;">0.00</td>
      <td id="tMarket"  style="text-align:right;">0.00</td>
      <td id="tDamage"  style="text-align:right;">0.00</td>
      <td id="tCancel"  style="text-align:right;">0.00</td>
      <td id="tAdjust"  style="text-align:right;">0.00</td>
      <td id="tIkea"    style="text-align:right;">0.00</td>
      <td id="tShort"   style="text-align:right;">0.00</td>
      <td id="tCashPaid"   style="text-align:right;color:#166534;">0.00</td>
      <td id="tChequePaid" style="text-align:right;color:#1e40af;">0.00</td>
      <td id="tPaid"       style="text-align:right;color:#166534;">0.00</td>
      <td id="tBalance"    style="text-align:right;color:#dc2626;">0.00</td>
      <td></td>
    </tr></tfoot>
  </table>
  </div>
  <div style="display:flex;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid #e5e5e5;flex-wrap:wrap;">
    <button type="submit" class="btn btn-success" id="saveBtn"><i class="fa-solid fa-save"></i> Save Changes</button>
    <a href="view_field_summary.php?id=<?php echo $field_summary_id; ?>" class="btn btn-secondary"><i class="fa-solid fa-times"></i> Cancel</a>
  </div>
</div>
</form>

<!-- ═══════════════ VIEW PAYMENTS MODAL ═══════════════ -->
<div class="view-pay-backdrop" id="viewPayModal">
<div class="view-pay-dialog">
  <div class="view-pay-header">
    <h3><i class="fa-solid fa-receipt" style="color:#7c3aed;"></i> Payment History</h3>
    <div style="display:flex;align-items:center;gap:10px;">
      <span id="vpInvoiceLabel" style="font-size:12px;color:#6b7280;"></span>
      <button class="modal-close" onclick="closeViewPayments()"><i class="fa-solid fa-xmark"></i></button>
    </div>
  </div>
  <div class="view-pay-body" id="vpBody">
    <div class="view-pay-empty"><span class="spinner"></span> Loading…</div>
  </div>
</div>
</div>

<!-- ═══════════════ PAYMENT MODAL ═══════════════ -->
<div class="modal-backdrop" id="payModal">
<div class="modal-dialog">

  <div class="modal-header">
    <div class="modal-header-left">
      <h3><i class="fa-solid fa-money-bill-transfer" style="color:#7c3aed;margin-right:4px;"></i> Record Payment</h3>
      <p id="modalSubtitle" style="font-size:12px;color:#6b7280;margin:0;">&mdash;</p>
      <div class="inv-summary-strip">
        <div class="inv-sum-item">
          <span class="inv-sum-label"><i class="fa-solid fa-file-invoice"></i> Ikea Amt</span>
          <span class="inv-sum-value" id="hdrInv">&mdash;</span>
        </div>
        <div class="inv-sum-item">
          <span class="inv-sum-label"><i class="fa-solid fa-circle-check"></i> Total Paid</span>
          <span class="inv-sum-value green" id="hdrPaid">&mdash;</span>
        </div>
        <div class="inv-sum-item">
          <span class="inv-sum-label"><i class="fa-solid fa-hourglass-half"></i> Balance</span>
          <span class="inv-sum-value red" id="hdrBal">&mdash;</span>
        </div>
        <div class="inv-sum-item">
          <span class="inv-sum-label"><i class="fa-solid fa-wallet"></i> Customer Mode</span>
          <span id="hdrPayMode"><span class="pay-mode-badge">&mdash;</span></span>
        </div>
      </div>
    </div>
    <button class="modal-close" onclick="closePayModal()"><i class="fa-solid fa-xmark"></i></button>
  </div>

  <div class="modal-body">
    <div class="pay-section-wrap">

      <div class="credit-bypass-notice" id="creditBypassNotice" style="display:none;">
        <i class="fa-solid fa-info-circle"></i>
        <div>
          <strong>Credit / Cheque Customer</strong> — this customer can be processed without immediate payment.
          You may still record a partial cash or cheque payment below, or submit with zero amount using <em>Emergency Credit</em>.
        </div>
      </div>

      <!-- ══ CASH BLOCK ══ -->
      <div class="pay-block" id="block-cash">
        <div class="pay-block-title cash"><i class="fa-solid fa-coins"></i> Cash Payment</div>
        <div class="gr gr4">
          <div class="fg"><label>Payment Date</label><input type="date" class="fctrl" id="cashDate"></div>
          <div class="fg"><label>Cash Amount (Rs.)</label>
            <input type="number" class="fctrl" id="cashAmount" step="0.01" min="0" placeholder="0.00" oninput="syncPreviews()">
          </div>
          <div class="fg"><label>Amount to Bank (Rs.)</label>
            <input type="number" class="fctrl" id="cashToBank" step="0.01" min="0" placeholder="0.00">
          </div>
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

      <!-- ══ CHEQUE BLOCK ══ -->
      <div class="pay-block" id="block-cheque">
        <div class="pay-block-title cheque"><i class="fa-solid fa-money-check"></i> Cheque Payment</div>
        <div class="cust-info-strip">
          <div class="ci-item"><div class="ci-label">Credit Limit</div><div class="ci-value" id="chqLimit">&mdash;</div></div>
          <div class="ci-item"><div class="ci-label">Policy Days</div><div class="ci-value" id="chqDays">&mdash;</div></div>
          <div class="ci-item"><div class="ci-label">Special Days</div><div class="ci-value" id="chqSpecial">&mdash;</div></div>
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

      <!-- ══ EMERGENCY CREDIT ══ -->
      <div class="pay-divider"><span>Emergency Credit</span></div>
      <div class="pay-block">
        <div class="emg-row">
          <label class="emg-label" for="emgToggle"><i class="fa-solid fa-bolt" style="color:#f59e0b;"></i> Mark as Emergency Credit</label>
          <label class="toggle-switch"><input type="checkbox" id="emgToggle" onchange="toggleEmg('emgBox',this.checked)"><span class="toggle-slider"></span></label>
        </div>
        <div id="emgBox" style="display:none;" class="emg-box">
          <div class="fg">
            <label>Reason <span class="req">*</span></label>
            <select class="fctrl" id="emgReason">
              <option value="">— Select a reason —</option>
              <?php foreach ($emg_reasons as $er): ?>
              <option value="<?php echo htmlspecialchars($er['reason']); ?>"><?php echo htmlspecialchars($er['reason']); ?></option>
              <?php endforeach; ?>
              <?php if (empty($emg_reasons)): ?>
              <option value="" disabled>No reasons configured</option>
              <?php endif; ?>
            </select>
          </div>
          <div class="upload-zone" style="margin-top:10px;">
            <input type="file" id="emgFiles" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx" onchange="previewFiles(this,'emgFilePreviews')">
            <i class="fa-solid fa-cloud-arrow-up"></i>
            <span>Upload Supporting Documents</span>
            <small>JPG, PNG, PDF, DOC — max 10MB each</small>
          </div>
          <div class="file-previews" id="emgFilePreviews"></div>
        </div>
      </div>

      <!-- ══ TO BE DELIVERY ══ -->
      <div class="pay-divider"><span>Delivery Options</span></div>
      <div class="pay-block" style="margin-bottom:8px;">
        <div class="emg-row" style="background:#eff6ff;border-color:#bfdbfe;">
          <label class="emg-label" for="toBeDeliveryChk" style="color:#1e40af;">
            <i class="fa-solid fa-truck" style="color:#3b82f6;"></i> Mark as To Be Delivery
          </label>
          <label class="toggle-switch">
            <input type="checkbox" id="toBeDeliveryChk">
            <span class="toggle-slider" style=""></span>
          </label>
        </div>
        <div style="font-size:11px;color:#3b82f6;padding:4px 12px 6px 12px;">
          <i class="fa-solid fa-info-circle"></i> When checked, this invoice will be flagged as <strong>To Be Delivered</strong> after submitting payment or marking as credit.
        </div>
      </div>

      <!-- ══ BALANCE SUMMARY ══ -->
      <div class="bal-summary" id="balSummary">
        <div class="bal-sum-row">
          <span><i class="fa-solid fa-file-invoice" style="color:#6b7280;"></i> Ikea Balance</span>
          <strong id="sumInvoiceBalance" style="color:#374151;">Rs. —</strong>
        </div>
        <div class="bal-sum-row">
          <span><i class="fa-solid fa-coins" style="color:#22c55e;"></i> Cash entering</span>
          <strong id="sumCash">Rs. 0.00</strong>
        </div>
        <div class="bal-sum-row">
          <span><i class="fa-solid fa-money-check" style="color:#3b82f6;"></i> Cheque total</span>
          <strong id="sumCheque">Rs. 0.00</strong>
        </div>
        <div class="bal-sum-row total">
          <span><i class="fa-solid fa-sigma"></i> Total payment</span>
          <strong id="sumTotal">Rs. 0.00</strong>
        </div>
        <div class="bal-sum-row balance" id="sumBalanceRow">
          <span><i class="fa-solid fa-hourglass-half"></i> Remaining after this payment</span>
          <strong id="sumBalance" style="color:#dc2626;">Rs. —</strong>
        </div>
        <!-- ══ OVERPAYMENT ROW — shown only when entering > balance ══ -->
        <div class="bal-sum-row" id="sumOverpayRow" style="display:none;background:#fef2f2;border-radius:6px;padding:8px 10px;margin-top:6px;border:1px solid #fecaca;">
          <span style="color:#991b1b;font-weight:700;display:flex;align-items:center;gap:6px;">
            <i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i> Overpayment (excess)
          </span>
          <strong id="sumOverpay" style="color:#dc2626;font-size:15px;">Rs. 0.00</strong>
        </div>
      </div>

    </div>
  </div>

  <div class="modal-footer">
    <div style="font-size:11px;color:#9ca3af;"><i class="fa-solid fa-info-circle"></i> Cash + cheque both saved per invoice. Emergency credit requires a reason.</div>
    <div class="modal-footer-right">
      <button type="button" id="tbdFooterBtn" onclick="toggleToBeDelivery()"
        style="display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:6px;border:2px solid #bfdbfe;background:#fff;color:#3b82f6;font-size:13px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;transition:all .2s;">
        <i class="fa-solid fa-truck"></i> Mark as To Be Delivery
      </button>
      <button class="btn-modal-cancel" onclick="closePayModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="btn-modal-submit" id="markCreditRowBtn" onclick="markRowAsCredit()" style="display:none;background:#7c3aed;"><i class="fa-solid fa-stamp"></i> Mark as Credit</button>
      <button class="btn-modal-submit" id="submitPayBtn" onclick="submitPayment()"><i class="fa-solid fa-paper-plane"></i> Submit Payment</button>
    </div>
  </div>

</div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
const BANKS         = <?php echo json_encode($banks_list); ?>;
const FS_ID         = <?php echo $field_summary_id; ?>;
const DELIVERY_DATE = '<?php echo htmlspecialchars($delivery_date); ?>';

/* ══════════════════════════════════════════
   TABLE CALCULATIONS
══════════════════════════════════════════ */
function calcRow(row){
    const net    = +row.querySelector('.net-value').value          || 0;
    const totdis = +row.querySelector('.tot-dis').value            || 0;
    const market = +row.querySelector('.market-return').value      || 0;
    const damage = +row.querySelector('.damage-adjustment').value  || 0;
    const cancel = +row.querySelector('.cancel-value').value       || 0;
    const adj    = net + totdis + market + damage - cancel;
    row.querySelector('.adjust-net-value').value = adj.toFixed(2);

    /* CHANGE 2: balance = ikea - paid */
    const paid = parseFloat(row.dataset.paid || 0);
    const ikea = +row.querySelector('.ikea-value').value || 0;
    const bal  = Math.max(0, ikea - paid);

    row.dataset.adjust  = adj;
    row.dataset.balance = bal;
    refreshRowCells(row, paid, bal);
    calcSE(row, adj);
}

function calcSE(row, adjOverride){
    const adj  = adjOverride !== undefined ? adjOverride : (+row.querySelector('.adjust-net-value').value || 0);
    const ikea = +row.querySelector('.ikea-value').value || 0;
    const diff = adj - ikea;
    const badge = row.querySelector('.se-badge');
    const hid   = row.querySelector('.se-val');
    hid.value = diff.toFixed(2);
    if (!ikea && !adj){ badge.textContent='—'; badge.className='se-badge se-neutral'; return; }
    badge.textContent = (diff>=0?'+':'')+diff.toFixed(2);
    badge.className   = 'se-badge ' + (diff>0 ? 'se-excess' : diff<0 ? 'se-short' : 'se-neutral');
}

/* ── refreshRowCells: updates the 4 payment columns ── */
function refreshRowCells(row, paid, balance, cashDelta, chequeDelta){
    /* update dataset accumulators */
    if(cashDelta !== undefined){
        row.dataset.cashPaid   = Math.max(0, parseFloat(row.dataset.cashPaid   || 0) + cashDelta).toFixed(2);
    }
    if(chequeDelta !== undefined){
        row.dataset.chequePaid = Math.max(0, parseFloat(row.dataset.chequePaid || 0) + chequeDelta).toFixed(2);
    }

    const cashPaidVal   = parseFloat(row.dataset.cashPaid   || 0);
    const chequePaidVal = parseFloat(row.dataset.chequePaid || 0);

    const cpCell  = row.querySelector('.row-cash-paid');
    const chpCell = row.querySelector('.row-cheque-paid');
    const pc      = row.querySelector('.row-paid');
    const bc      = row.querySelector('.row-balance');
    const pb      = row.querySelector('.btn-pay');

    if(cpCell)  cpCell.textContent  = cashPaidVal   > 0.005 ? cashPaidVal.toFixed(2)   : '—';
    if(chpCell) chpCell.textContent = chequePaidVal > 0.005 ? chequePaidVal.toFixed(2) : '—';
    if(pc)      pc.textContent      = paid.toFixed(2);
    if(bc){
        const emgBadge = bc.querySelector('.pb-emg');
        bc.textContent = balance.toFixed(2);
        if(emgBadge) bc.appendChild(emgBadge);
        bc.className = 'balance-cell row-balance ' + (balance > 0.005 ? 'has-balance' : 'settled');
    }
    if(pb){
        pb.textContent = balance <= 0.005 ? 'Paid' : 'Pay';
        pb.className   = 'btn-pay ' + (balance<=0.005 ? 'settled' : paid>0 ? 'partial' : '');
    }
}

/* ── Update the view-pay button badge after a payment ── */
function updateViewPayBadge(row){
    const btn = row.querySelector('.btn-view-pay');
    if(!btn) return;
    let cnt = parseInt(row.dataset.paycount || '0') + 1;
    row.dataset.paycount = cnt;
    btn.classList.add('has-payments');
    let badge = btn.querySelector('.vp-count');
    if(badge){ badge.textContent = cnt; }
    else { badge = document.createElement('span'); badge.className='vp-count'; badge.textContent=cnt; btn.appendChild(badge); }
}

function lockPaidRow(row, balance){
    row.querySelectorAll(".edit-input:not([readonly])").forEach(inp=>{
        inp.setAttribute("readonly", "true");
        inp.classList.add("_was-locked");
    });
    row.classList.add("row-locked");
    const pb = row.querySelector(".btn-pay");
    if(pb && balance <= 0.005){
        pb.textContent = "Paid";
        pb.className   = "btn-pay settled";
        pb.setAttribute("onclick", "");
    }
}

function calcTotals(){
    let s = {n:0,td:0,mk:0,dm:0,cn:0,ad:0,ik:0,se:0,pd:0,bl:0,cp:0,chp:0};
    document.querySelectorAll('#detailsTable tbody tr').forEach(row=>{
        s.n   += +row.querySelector('.net-value').value          || 0;
        s.td  += +row.querySelector('.tot-dis').value            || 0;
        s.mk  += +row.querySelector('.market-return').value      || 0;
        s.dm  += +row.querySelector('.damage-adjustment').value  || 0;
        s.cn  += +row.querySelector('.cancel-value').value       || 0;
        s.ad  += +row.querySelector('.adjust-net-value').value   || 0;
        s.ik  += +row.querySelector('.ikea-value').value         || 0;
        s.se  += +row.querySelector('.se-val').value             || 0;
        s.pd  += parseFloat(row.dataset.paid      || 0);
        s.bl  += parseFloat(row.dataset.balance   || 0);
        s.cp  += parseFloat(row.dataset.cashPaid  || 0);
        s.chp += parseFloat(row.dataset.chequePaid|| 0);
    });
    [['tNet',s.n],['tTotDis',s.td],
     ['tMarket',s.mk],['tDamage',s.dm],['tCancel',s.cn],['tAdjust',s.ad],
     ['tIkea',s.ik],['tShort',s.se],['tCashPaid',s.cp],['tChequePaid',s.chp],
     ['tPaid',s.pd],['tBalance',s.bl]].forEach(([id,v])=>{
        const el=document.getElementById(id); if(el) el.textContent=v.toFixed(2);
    });
}

document.querySelectorAll('#detailsTable .edit-input:not([readonly])').forEach(inp=>{
    inp.addEventListener('input',function(){
        const row = this.closest('tr');
        /* CHANGE 2 continued: ikea input change also recalcs balance */
        if(this.classList.contains('ikea-value')){
            const paid = parseFloat(row.dataset.paid || 0);
            const ikea = parseFloat(this.value) || 0;
            const bal  = Math.max(0, ikea - paid);
            row.dataset.balance = bal;
            refreshRowCells(row, paid, bal);
            calcSE(row);
        } else {
            calcRow(row);
        }
        calcTotals();
    });
});

document.querySelectorAll('#detailsTable tbody tr').forEach(row=>{ calcRow(row); });
calcTotals();

/* Lock rows that already have a payment OR are marked updated on page load */
document.querySelectorAll('#detailsTable tbody tr').forEach(row=>{
    if(parseFloat(row.dataset.paid||0) > 0 || row.dataset.updated === '1'){
        lockPaidRow(row, parseFloat(row.dataset.balance||0));
    }
});

/* ══════════════════════════════════════════
   SEARCH
══════════════════════════════════════════ */
function initSearchCount(){
    const total = document.querySelectorAll('#detailsTable tbody tr').length;
    document.getElementById('searchCount').textContent = total + ' rows';
}
initSearchCount();

document.getElementById('bulkPayDate').value = DELIVERY_DATE || new Date().toISOString().slice(0,10);

document.getElementById('tableSearch').addEventListener('input', function(){
    const q = this.value.trim().toLowerCase();
    const clearBtn = document.getElementById('searchClearBtn');
    clearBtn.classList.toggle('visible', q.length > 0);
    let visible = 0;
    document.querySelectorAll('#detailsTable tbody tr').forEach(row=>{
        const invoice  = (row.dataset.invoice  || '').toLowerCase();
        const customer = (row.dataset.customer || '').toLowerCase();
        const tcode    = (row.dataset.tcode    || '').toLowerCase();
        const route    = (row.dataset.route    || '').toLowerCase();
        const match    = !q || invoice.includes(q) || customer.includes(q) || tcode.includes(q) || route.includes(q);
        row.style.display = match ? '' : 'none';
        if(match) visible++;
    });
    const total = document.querySelectorAll('#detailsTable tbody tr').length;
    document.getElementById('searchCount').textContent = q ? visible+' of '+total+' rows' : total+' rows';
    syncSelectAllState();
});

function clearSearch(){
    const inp = document.getElementById('tableSearch');
    inp.value = '';
    inp.dispatchEvent(new Event('input'));
    inp.focus();
}

/* ══════════════════════════════════════════
   BULK SELECT
══════════════════════════════════════════ */
document.getElementById('selectAll').addEventListener('change', function(){
    const checked = this.checked;
    document.querySelectorAll('#detailsTable tbody tr').forEach(row=>{
        if(row.style.display === 'none') return;
        const chk = row.querySelector('.row-select');
        if(chk) chk.checked = checked;
        row.classList.toggle('row-selected', checked);
    });
    updateBulkPanel();
});

function onRowCheckChange(){
    syncSelectAllState();
    updateBulkPanel();
    document.querySelectorAll('#detailsTable tbody tr').forEach(row=>{
        const chk = row.querySelector('.row-select');
        if(chk) row.classList.toggle('row-selected', chk.checked);
    });
}

function syncSelectAllState(){
    const allChks     = Array.from(document.querySelectorAll('#detailsTable tbody tr')).filter(r=>r.style.display!=='none').map(r=>r.querySelector('.row-select')).filter(Boolean);
    const checkedChks = allChks.filter(c=>c.checked);
    const saChk       = document.getElementById('selectAll');
    saChk.checked       = allChks.length > 0 && checkedChks.length === allChks.length;
    saChk.indeterminate = checkedChks.length > 0 && checkedChks.length < allChks.length;
}

function updateBulkPanel(){
    const selected = Array.from(document.querySelectorAll('#detailsTable tbody tr .row-select:checked'));
    const panel    = document.getElementById('bulkPanel');
    if(selected.length > 0){
        panel.classList.add('active');
        document.getElementById('bulkCount').textContent = selected.length;
        let totalBal = 0;
        selected.forEach(chk=>{ totalBal += parseFloat(chk.closest('tr').dataset.balance || 0); });
        document.getElementById('bulkTotalBalance').textContent = 'Rs. '+totalBal.toFixed(2);
        document.getElementById('bulkCashAmount').value = totalBal.toFixed(2);
    } else {
        panel.classList.remove('active');
        document.getElementById('bulkCashAmount').value = '';
    }
}

function clearBulkSelection(){
    document.querySelectorAll('#detailsTable tbody tr').forEach(row=>{
        const chk = row.querySelector('.row-select');
        if(chk) chk.checked = false;
        row.classList.remove('row-selected');
    });
    const saChk = document.getElementById('selectAll');
    saChk.checked = false; saChk.indeterminate = false;
    updateBulkPanel();
}

/* ══════════════════════════════════════════
   BULK PAYMENT SUBMIT
══════════════════════════════════════════ */
async function submitBulkPayment(){
    const selected = Array.from(document.querySelectorAll('#detailsTable tbody tr .row-select:checked'));
    if(selected.length === 0){ showToast('No rows selected.','err'); return; }
    const rawAmt = parseFloat(document.getElementById('bulkCashAmount').value) || 0;
    if(rawAmt <= 0){ showToast('Enter a cash amount (> 0).','err'); return; }
    const bulkDateInput = document.getElementById('bulkPayDate').value;
    const payDate = bulkDateInput || DELIVERY_DATE || new Date().toISOString().slice(0,10);
    if(!payDate){ showToast('Please select a payment date.','err'); return; }
    const btn = document.getElementById('bulkPayBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Processing…';
    try {
        const saveRes  = await fetch('update_field_summary.php', {method:'POST', body: new FormData(document.getElementById('editSummaryForm'))});
        const saveData = await saveRes.json();
        if(!saveData.success) showToast('Auto-save warning: '+(saveData.message||''),'err');
    } catch(e){ }
    let successCount = 0, errorCount = 0, skippedCount = 0;
    let pool = rawAmt;
    for(const chk of selected){
        const row    = chk.closest('tr');
        const rowBal = parseFloat(row.dataset.balance || 0);
        if(rowBal <= 0.005){ skippedCount++; chk.checked = false; row.classList.remove('row-selected'); continue; }
        if(pool <= 0.005) break;
        const payThisRow = parseFloat(Math.min(pool, rowBal).toFixed(2));
        pool = parseFloat((pool - payThisRow).toFixed(2));
        const fd = new FormData();
        fd.append('field_summary_id',        FS_ID);
        fd.append('field_summary_detail_id', row.dataset.id);
        fd.append('t_code',                  row.dataset.tcode);
        fd.append('invoice_num',             row.dataset.invoice);
        fd.append('payment_method',          'cash');
        fd.append('payment_date',            payDate);
        fd.append('amount',                  payThisRow);
        fd.append('collected_by',            'cc');
        fd.append('combined_total',          payThisRow);
        try {
            const res  = await fetch('save_payment.php', {method:'POST', body:fd});
            const data = await res.json();
            if(data.success){
                successCount++;
                /* CHANGE 3b: balance = ikea - new_paid (ignore server new_balance) */
                const newPaid  = parseFloat(data.new_paid || 0);
                const _ik      = parseFloat(row.querySelector('.ikea-value').value) || 0;
                const newBal   = Math.max(0, _ik - newPaid);
                row.dataset.paid    = newPaid;
                row.dataset.balance = newBal;
                refreshRowCells(row, newPaid, newBal, payThisRow, 0);
                updateViewPayBadge(row);
                lockPaidRow(row, newBal);
                chk.checked = false;
                row.classList.remove('row-selected');
                const mfd = new FormData(); mfd.append('detail_id', row.dataset.id);
                fetch('mark_detail_updated.php', {method:'POST', body:mfd}).catch(()=>{});
            } else {
                errorCount++;
                pool = parseFloat((pool + payThisRow).toFixed(2));
            }
        } catch(e){
            errorCount++;
            pool = parseFloat((pool + payThisRow).toFixed(2));
        }
    }
    calcTotals();
    syncSelectAllState();
    updateBulkPanel();
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Apply to All Selected';
    let msg = '';
    if(successCount > 0) msg += '✓ '+successCount+' invoice(s) paid. ';
    if(skippedCount > 0) msg += skippedCount+' already settled skipped. ';
    if(pool > 0.005)     msg += 'Rs. '+pool.toFixed(2)+' unallocated (balance exhausted). ';
    if(errorCount   > 0) msg += errorCount+' failed.';
    showToast(msg.trim() || 'Done.', errorCount > 0 ? 'err' : 'ok');
}

/* ══════════════════════════════════════════
   VIEW PAYMENTS MODAL (NEW)
══════════════════════════════════════════ */
function openViewPayments(btn){
    const row = btn.closest('tr');
    const detailId = row.dataset.id;
    const invoice  = row.dataset.invoice || '';
    const customer = row.dataset.customer || '';

    document.getElementById('vpInvoiceLabel').textContent = invoice + ' — ' + customer;
    document.getElementById('vpBody').innerHTML = '<div class="view-pay-empty"><span class="spinner"></span> Loading…</div>';

    const modal = document.getElementById('viewPayModal');
    if(modal.parentElement !== document.body) document.body.appendChild(modal);
    modal.classList.add('open');

    fetch('get_row_payments.php?detail_id=' + encodeURIComponent(detailId))
        .then(r => r.json())
        .then(data => {
            if(!data.success){
                document.getElementById('vpBody').innerHTML = '<div class="view-pay-empty"><i class="fa-solid fa-exclamation-circle" style="color:#ef4444;"></i> ' + (data.error||'Error loading payments') + '</div>';
                return;
            }
            renderViewPayments(data);
        })
        .catch(err => {
            document.getElementById('vpBody').innerHTML = '<div class="view-pay-empty"><i class="fa-solid fa-exclamation-circle" style="color:#ef4444;"></i> Network error: ' + err.message + '</div>';
        });
}

function renderViewPayments(data){
    const body = document.getElementById('vpBody');
    let html = '';

    /* Emergency credits */
    if(data.emergency_credits && data.emergency_credits.length > 0){
        data.emergency_credits.forEach(ec => {
            html += '<div class="vpc-emg"><i class="fa-solid fa-bolt" style="color:#f59e0b;"></i> <strong>Emergency Credit</strong> — ' + escHtml(ec.reason) + ' <span style="margin-left:auto;font-size:11px;color:#9ca3af;">' + escHtml(ec.created_at) + '</span></div>';
        });
    }

    /* Payments */
    if(!data.payments || data.payments.length === 0){
        if(!data.emergency_credits || data.emergency_credits.length === 0){
            html = '<div class="view-pay-empty"><i class="fa-solid fa-inbox" style="font-size:24px;margin-bottom:8px;display:block;color:#d1d5db;"></i>No payments recorded yet.</div>';
        }
        body.innerHTML = html;
        return;
    }

    data.payments.forEach((p, idx) => {
        const isReversed = parseInt(p.is_reversed) === 1;
        html += '<div class="view-pay-card' + (isReversed ? ' reversed' : '') + '">';
        html += '<div class="vpc-top">';
        html += '<span class="vpc-method ' + escHtml(p.payment_method) + '"><i class="fa-solid fa-' + (p.payment_method==='cash'?'coins':'money-check') + '"></i> ' + escHtml(p.payment_method) + '</span>';
        html += '<span class="vpc-amt">Rs. ' + parseFloat(p.amount).toFixed(2) + '</span>';
        if(isReversed) html += '<span class="vpc-reversed-badge"><i class="fa-solid fa-rotate-left"></i> Reversed</span>';
        html += '</div>';

        html += '<div class="vpc-grid">';
        html += '<div class="vpc-grid-item"><span class="vl">Date</span><span class="vv">' + escHtml(p.payment_date || '—') + '</span></div>';
        html += '<div class="vpc-grid-item"><span class="vl">Reference</span><span class="vv">' + escHtml(p.reference_no || '—') + '</span></div>';
        html += '<div class="vpc-grid-item"><span class="vl">Collected By</span><span class="vv">' + escHtml(p.collected_by || '—') + '</span></div>';
        if(p.payment_method === 'cash' && parseFloat(p.amount_to_bank) > 0){
            html += '<div class="vpc-grid-item"><span class="vl">To Bank</span><span class="vv">Rs. ' + parseFloat(p.amount_to_bank).toFixed(2) + '</span></div>';
        }
        if(p.cheque_mode){
            html += '<div class="vpc-grid-item"><span class="vl">Cheque Mode</span><span class="vv">' + escHtml(p.cheque_mode) + '</span></div>';
        }
        if(p.remarks){
            html += '<div class="vpc-grid-item"><span class="vl">Remarks</span><span class="vv">' + escHtml(p.remarks) + '</span></div>';
        }
        html += '<div class="vpc-grid-item"><span class="vl">Source</span><span class="vv">' + escHtml(p.payment_source || 'invoice') + '</span></div>';
        html += '<div class="vpc-grid-item"><span class="vl">Recorded</span><span class="vv">' + escHtml(p.created_at || '—') + '</span></div>';
        html += '</div>';

        /* Cheque details */
        if(p.cheques && p.cheques.length > 0){
            html += '<div class="vpc-cheques">';
            html += '<div style="font-size:11px;font-weight:700;color:#1e40af;margin-bottom:4px;"><i class="fa-solid fa-money-check"></i> Cheque Details</div>';
            p.cheques.forEach(chq => {
                const statusCls = (chq.cheque_status||'pending').toLowerCase();
                html += '<div class="vpc-chq-row">';
                html += '<span><strong>#' + escHtml(chq.cheque_no) + '</strong> — Rs. ' + parseFloat(chq.amount).toFixed(2) + '</span>';
                html += '<span>';
                if(chq.bank_name) html += '<span style="font-size:11px;color:#6b7280;margin-right:6px;">' + escHtml(chq.bank_name) + (chq.branch_name ? ' / '+escHtml(chq.branch_name) : '') + '</span>';
                html += '<span class="vpc-chq-status ' + statusCls + '">' + escHtml(chq.cheque_status || 'pending') + '</span>';
                html += '</span></div>';
            });
            html += '</div>';
        }

        html += '</div>';
    });

    body.innerHTML = html;
}

function closeViewPayments(){
    document.getElementById('viewPayModal').classList.remove('open');
}

function escHtml(str){
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

/* ══════════════════════════════════════════
   MODAL STATE
══════════════════════════════════════════ */
let activeRow = null;
let ARD = {};

function openPayModal(btn){
    activeRow = btn.closest('tr');
    const origBtnHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';
    const form = document.getElementById('editSummaryForm');
    fetch('update_field_summary.php', {method:'POST', body: new FormData(form)})
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = origBtnHtml;
            if(!data.success){ showToast('Auto-save failed: '+(data.message||'Error'),'err'); return; }
            _doOpenModal(btn);
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = origBtnHtml;
            showToast('Auto-save error: '+err.message,'err');
        });
}

function _doOpenModal(btn){
    activeRow = btn.closest('tr');
    ARD = {
        id          : activeRow.dataset.id,
        fsid        : activeRow.dataset.fsid || FS_ID,
        tcode       : activeRow.dataset.tcode,
        invoice     : activeRow.dataset.invoice,
        customer    : activeRow.dataset.customer,
        route       : activeRow.dataset.route,
        adjust      : parseFloat(activeRow.dataset.adjust   || 0),
        paid        : parseFloat(activeRow.dataset.paid     || 0),
        balance     : parseFloat(activeRow.dataset.balance  || 0),
        payMode     : activeRow.dataset.paymode || '',
        creditLimit : activeRow.dataset.creditlimit,
        creditDays  : activeRow.dataset.creditdays,
        specialDays : activeRow.dataset.specialdays,
        /* store ikea for modal balance preview */
        ikea        : parseFloat(activeRow.querySelector('.ikea-value').value) || 0,
    };

    const creditBtn = document.getElementById('markCreditRowBtn');
    if(creditBtn){ creditBtn.disabled = false; creditBtn.innerHTML = '<i class="fa-solid fa-stamp"></i> Mark as Credit'; }
    const submitBtn = document.getElementById('submitPayBtn');
    if(submitBtn){ submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Payment'; }

    document.getElementById('modalSubtitle').textContent = 'Invoice: '+ARD.invoice+' | '+ARD.customer;
    document.getElementById('hdrInv').textContent  = 'Rs. '+ARD.ikea.toFixed(2);
    document.getElementById('hdrPaid').textContent = 'Rs. '+ARD.paid.toFixed(2);
    document.getElementById('hdrBal').textContent  = 'Rs. '+ARD.balance.toFixed(2);

    const pm = ARD.payMode.toLowerCase();
    const pmIcon  = pm==='cash'?'coins':pm==='cheque'?'money-check':'credit-card';
    const pmLabel = pm ? pm.charAt(0).toUpperCase()+pm.slice(1) : 'N/A';
    document.getElementById('hdrPayMode').innerHTML =
        '<span class="pay-mode-badge '+pm+'"><i class="fa-solid fa-'+pmIcon+'"></i> '+pmLabel+'</span>';

    const bypass = document.getElementById('creditBypassNotice');
    bypass.style.display = (pm === 'credit' || pm === 'cheque') ? 'flex' : 'none';
    document.getElementById('markCreditRowBtn').style.display = (pm === 'credit') ? 'inline-flex' : 'none';

    const defDate = DELIVERY_DATE || new Date().toISOString().slice(0,10);
    document.getElementById('cashDate').value    = defDate;
    document.getElementById('chqPayDate').value  = defDate;
    document.getElementById('cashAmount').value  = '';
    document.getElementById('cashToBank').value  = '';
    document.getElementById('cashRef').value     = '';
    document.getElementById('cashRemarks').value = '';
    document.getElementById('chqRef').value      = '';
    document.getElementById('chqRemarks').value  = '';

    setCI('chqLimit',   ARD.creditLimit ? 'Rs. '+parseFloat(ARD.creditLimit).toLocaleString() : '—');
    setCI('chqDays',    ARD.creditDays  || '—');
    setCI('chqSpecial', ARD.specialDays || '—');

    ['emgToggle'].forEach(id=>{ const el=document.getElementById(id); if(el) el.checked=false; });
    ['emgBox'].forEach(id=>{ const el=document.getElementById(id); if(el) el.style.display='none'; });
    ['emgFilePreviews'].forEach(id=>{ const el=document.getElementById(id); if(el) el.innerHTML=''; });
    ['emgFiles'].forEach(id=>{ const el=document.getElementById(id); if(el) el.value=''; });

    document.getElementById('emgReason').value = '';
    document.getElementById('chqModeSelect').value = 'payee_only';
    document.getElementById('chequesContainer').innerHTML = '';
    chequeCounter = 0;
    addCheque();
    syncPreviews();
    resetToBeDelivery();

    const modal = document.getElementById('payModal');
    if(modal.parentElement !== document.body) document.body.appendChild(modal);
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function setCI(id, v){ const el=document.getElementById(id); if(el){ el.textContent=v||'—'; } }

function closePayModal(){
    document.getElementById('payModal').classList.remove('open');
    document.body.style.overflow = '';
}

/* ══════════════════════════════════════════
   EMERGENCY TOGGLE
══════════════════════════════════════════ */
function toggleEmg(boxId, on){
    document.getElementById(boxId).style.display = on ? 'block' : 'none';
}

function previewFiles(input, previewId){
    const wrap = document.getElementById(previewId);
    wrap.innerHTML = '';
    Array.from(input.files).forEach(file=>{
        const item = document.createElement('div');
        item.className = 'file-chip';
        item.innerHTML = '<i class="fa-solid fa-file" style="color:#6b7280;"></i>'+file.name+
            ' <button type="button" class="file-chip-del" onclick="this.parentElement.remove()">&times;</button>';
        wrap.appendChild(item);
    });
}

/* ══════════════════════════════════════════
   LIVE BALANCE PREVIEW  (+ OVERPAYMENT)
   CHANGE 3a: uses ARD.ikea for balance base
══════════════════════════════════════════ */
function syncPreviews(){
    /* balance is ikea - already paid */
    const remaining   = ARD.ikea !== undefined ? Math.max(0, ARD.ikea - ARD.paid) : (parseFloat(ARD.balance) || 0);
    const cashAmt     = Math.max(0, parseFloat(document.getElementById('cashAmount').value) || 0);
    let   chqTotal    = 0;
    document.querySelectorAll('#chequesContainer .chq-amt').forEach(i=>{ chqTotal += parseFloat(i.value)||0; });
    const totalPaying = cashAmt + chqTotal;
    const newBal      = Math.max(0, remaining - totalPaying);
    const overpayment = parseFloat((totalPaying - remaining).toFixed(2));

    const invBalEl = document.getElementById('sumInvoiceBalance');
    if(invBalEl) invBalEl.textContent = 'Rs. '+remaining.toFixed(2);
    document.getElementById('sumCash').textContent    = 'Rs. '+cashAmt.toFixed(2);
    document.getElementById('sumCheque').textContent  = 'Rs. '+chqTotal.toFixed(2);
    document.getElementById('sumTotal').textContent   = 'Rs. '+totalPaying.toFixed(2);
    document.getElementById('sumBalance').textContent = 'Rs. '+newBal.toFixed(2);
    document.getElementById('hdrBal').textContent     = 'Rs. '+newBal.toFixed(2);

    const overpayRow   = document.getElementById('sumOverpayRow');
    const overpayAmtEl = document.getElementById('sumOverpay');
    if(overpayment > 0.005){
        overpayRow.style.display  = 'flex';
        overpayAmtEl.textContent  = 'Rs. +'+overpayment.toFixed(2);
        document.getElementById('sumBalance').style.color = '#6b7280';
    } else {
        overpayRow.style.display  = 'none';
        document.getElementById('sumBalance').style.color = '#dc2626';
    }
}

/* ══════════════════════════════════════════
   CHEQUE CARD BUILDER
══════════════════════════════════════════ */
let chequeCounter = 0;
const KNOWN_CHEQUES = {};
const dupCache = {};

function addCheque(){
    chequeCounter++;
    const idx = chequeCounter;
    let bankOpts = '<option value="">— Select Bank —</option>';
    BANKS.forEach(b=>{ bankOpts += '<option value="'+b.bank_code+'" data-name="'+b.bank_name+'">'+b.bank_code+' – '+b.bank_name+'</option>'; });
    const card = document.createElement('div');
    card.className = 'cheque-card'; card.id = 'cheque-'+idx;
    card.innerHTML =
        '<div class="cheque-card-header">'+
            '<span class="cheque-card-title"><i class="fa-solid fa-money-check"></i> Cheque #'+idx+'</span>'+
            (idx>1?'<button type="button" class="btn-remove-cheque" onclick="document.getElementById(\'cheque-'+idx+'\').remove();syncPreviews()"><i class="fa-solid fa-trash"></i> Remove</button>':'')+
        '</div>'+
        '<div class="gr gr3">'+
            '<div class="fg"><label>Cheque No. <span class="req">*</span></label>'+
                '<input type="text" class="fctrl" id="chqno-'+idx+'" placeholder="e.g. 001234" oninput="checkDupCheque('+idx+')">'+
                '<div class="dup-cheque-warn" id="dup-warn-'+idx+'"></div></div>'+
            '<div class="fg"><label>Cheque Date</label>'+
                '<input type="date" class="fctrl" id="chqdate-'+idx+'" value="'+(DELIVERY_DATE||'')+'"></div>'+
            '<div class="fg"><label>Amount (Rs.) <span class="req">*</span></label>'+
                '<input type="number" class="fctrl chq-amt" id="chqamt-'+idx+'" step="0.01" min="0" placeholder="0.00" oninput="syncPreviews();checkDupCheque('+idx+')"></div>'+
        '</div>'+
        '<div class="gr gr2">'+
            '<div class="fg"><label>Bank <span class="req">*</span></label>'+
                '<select class="fctrl" id="chq-bank-'+idx+'">'+bankOpts+'</select></div>'+
            '<div class="fg"><label>Branch</label>'+
                '<select class="fctrl" id="chq-branch-'+idx+'"><option value="">— Select Branch —</option></select></div>'+
        '</div>';
    document.getElementById('chequesContainer').appendChild(card);
    $('#chq-bank-'+idx).select2({width:'100%', dropdownParent:$('#payModal')})
        .on('change', function(){ loadBranches(this.value, idx); });
    $('#chq-branch-'+idx).select2({width:'100%', dropdownParent:$('#payModal')});
}

function checkDupCheque(idx){
    const no  = (document.getElementById('chqno-'+idx)?.value||'').trim();
    const amt = parseFloat(document.getElementById('chqamt-'+idx)?.value||0);
    const warn = document.getElementById('dup-warn-'+idx);
    if(!no || !warn) return;
    if(dupCache[no] !== undefined){ showDupWarning(warn, no, dupCache[no], amt); return; }
    fetch('get_cheque_info.php?cheque_no='+encodeURIComponent(no))
        .then(r=>r.json()).then(data=>{
            dupCache[no] = data.exists ? data.total_amount : null;
            showDupWarning(warn, no, dupCache[no], amt);
        }).catch(()=>{});
}

function showDupWarning(warn, no, existingTotal, newAmt){
    if(existingTotal !== null && existingTotal !== undefined){
        const newTotal = (parseFloat(existingTotal)||0) + (parseFloat(newAmt)||0);
        warn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Cheque #'+no+' already exists (total: Rs. '+parseFloat(existingTotal).toFixed(2)+'). New total: Rs. '+newTotal.toFixed(2)+'.';
        warn.classList.add('show');
    } else {
        warn.classList.remove('show');
        warn.innerHTML = '';
    }
}

function loadBranches(bankCode, idx){
    const sel = document.getElementById('chq-branch-'+idx);
    sel.innerHTML = '<option value="">Loading…</option>';
    $(sel).select2('destroy');
    if(!bankCode){ sel.innerHTML='<option value="">— Select Branch —</option>'; $(sel).select2({width:'100%',dropdownParent:$('#payModal')}); return; }
    fetch('get_bank_branches.php?bank_code='+encodeURIComponent(bankCode))
        .then(r=>r.json())
        .then(data=>{
            let opts='<option value="">— Select Branch —</option>';
            data.forEach(b=>{ opts+='<option value="'+b.branch_code+'" data-name="'+b.branch_name+'">'+b.branch_code+' – '+b.branch_name+'</option>'; });
            sel.innerHTML = opts;
            $(sel).select2({width:'100%',dropdownParent:$('#payModal')});
        })
        .catch(()=>{ sel.innerHTML='<option value="">Error</option>'; $(sel).select2({width:'100%',dropdownParent:$('#payModal')}); });
}

/* ══════════════════════════════════════════
   SUBMIT PAYMENT
══════════════════════════════════════════ */
async function submitPayment(){
    const btn      = document.getElementById('submitPayBtn');
    const cashAmt  = parseFloat(document.getElementById('cashAmount').value) || 0;
    const cashDate = document.getElementById('cashDate').value;
    const cashEmg  = document.getElementById('emgToggle').checked;

    const chqCards = document.querySelectorAll('#chequesContainer .cheque-card');
    let chqTotal=0, chqValid=true, cheques=[];
    chqCards.forEach(card=>{
        const idx    = parseInt(card.id.replace('cheque-',''));
        const no     = (document.getElementById('chqno-'+idx)?.value||'').trim();
        const amt    = parseFloat(document.getElementById('chqamt-'+idx)?.value||0);
        const dt     = document.getElementById('chqdate-'+idx)?.value||'';
        const bkCode = $('#chq-bank-'+idx).val()||'';
        const bkSel  = document.getElementById('chq-bank-'+idx);
        const bkName = bkSel?.selectedOptions[0]?.dataset.name||bkSel?.selectedOptions[0]?.text||'';
        const brCode = $('#chq-branch-'+idx).val()||'';
        const brSel  = document.getElementById('chq-branch-'+idx);
        const brName = brSel?.selectedOptions[0]?.dataset.name||brSel?.selectedOptions[0]?.text||'';
        if(amt > 0){
            if(!no) chqValid = false;
            chqTotal += amt;
            cheques.push({cheque_no:no, cheque_date:dt, amount:amt,
                          bank_code:bkCode, bank_name:bkName,
                          branch_code:brCode, branch_name:brName});
        }
    });

    if(cashAmt <= 0 && chqTotal <= 0 && !cashEmg){
        showToast('Enter a cash amount, cheque amount, or tick Emergency Credit.','err'); return;
    }
    if(cashAmt > 0 && !cashDate){ showToast('Select a Payment Date for cash.','err'); return; }
    if(chqTotal > 0 && !chqValid){ showToast('Fill in all Cheque Numbers.','err'); return; }
    if(cashEmg && !document.getElementById('emgReason').value.trim()){
        showToast('Please select a reason for Emergency Credit.','err'); return;
    }

    function basePayload(){
        const fd = new FormData();
        fd.append('field_summary_id',        ARD.fsid || FS_ID);
        fd.append('field_summary_detail_id', ARD.id);
        fd.append('t_code',                  ARD.tcode);
        fd.append('invoice_num',             ARD.invoice);
        return fd;
    }

    btn.disabled=true; btn.innerHTML='<span class="spinner"></span> Saving…';
    let lastPayData = null;
    let saved = [];
    const combinedTotal = cashAmt + chqTotal;

    try {
        if(cashAmt > 0){
            const fd = basePayload();
            fd.append('payment_method',       'cash');
            fd.append('payment_date',         cashDate || new Date().toISOString().slice(0,10));
            fd.append('amount',               cashAmt);
            fd.append('amount_to_bank',       document.getElementById('cashToBank').value||0);
            fd.append('reference_no',         document.getElementById('cashRef').value||'');
            fd.append('collected_by',         document.getElementById('cashCollectedBy').value);
            fd.append('remarks',              document.getElementById('cashRemarks').value||'');
            fd.append('has_emergency_credit', cashEmg ? '1' : '0');
            fd.append('combined_total',       combinedTotal);
            const res  = await fetch('save_payment.php', {method:'POST', body:fd});
            const data = await res.json();
            if(!data.success){ showToast('Cash error: '+(data.error||'Unknown'),'err'); btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Submit Payment'; return; }
            lastPayData = data;
            saved.push('💵 Cash Rs.'+cashAmt.toFixed(2));
        }

        if(chqTotal > 0){
            const fd = basePayload();
            fd.append('payment_method',       'cheque');
            fd.append('payment_date',         document.getElementById('chqPayDate').value||new Date().toISOString().slice(0,10));
            fd.append('amount',               chqTotal);
            fd.append('reference_no',         document.getElementById('chqRef').value||'');
            fd.append('cheque_mode',          document.getElementById('chqModeSelect').value||'payee_only');
            fd.append('collected_by',         'cc');
            fd.append('remarks',              document.getElementById('chqRemarks').value||'');
            fd.append('has_emergency_credit', cashEmg ? '1' : '0');
            fd.append('combined_total',       combinedTotal);
            cheques.forEach((q,i)=>{
                fd.append('cheques['+i+'][cheque_no]',   q.cheque_no);
                fd.append('cheques['+i+'][cheque_date]', q.cheque_date);
                fd.append('cheques['+i+'][amount]',      q.amount);
                fd.append('cheques['+i+'][bank_code]',   q.bank_code);
                fd.append('cheques['+i+'][bank_name]',   q.bank_name);
                fd.append('cheques['+i+'][branch_code]', q.branch_code);
                fd.append('cheques['+i+'][branch_name]', q.branch_name);
            });
            const res  = await fetch('save_payment.php', {method:'POST', body:fd});
            const data = await res.json();
            if(!data.success){ showToast('Cheque error: '+(data.error||'Unknown'),'err'); btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Submit Payment'; return; }
            lastPayData = data;
            saved.push('🏦 Cheque Rs.'+chqTotal.toFixed(2));
        }

        if(cashEmg){
            const fd = basePayload();
            fd.append('reason', document.getElementById('emgReason').value||'');
            const emgFiles = document.getElementById('emgFiles').files;
            for(let i=0;i<emgFiles.length;i++) fd.append('documents[]', emgFiles[i]);
            const res  = await fetch('save_emergency_credit.php', {method:'POST', body:fd});
            const data = await res.json();
            if(!data.success){ showToast('Emergency credit error: '+(data.error||'Unknown'),'err'); btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Submit Payment'; return; }
            saved.push('🔴 Emergency Credit logged');
            /* Show EMG badge on the balance cell immediately */
            if(activeRow){
                activeRow.dataset.hasCredit = '1';
                const bc = activeRow.querySelector('.row-balance');
                if(bc && !bc.querySelector('.pb-emg')){
                    const emgSpan = document.createElement('span');
                    emgSpan.className = 'pb-emg';
                    emgSpan.title = 'Emergency Credit logged';
                    emgSpan.innerHTML = '<i class="fa-solid fa-bolt"></i> EMG';
                    bc.appendChild(emgSpan);
                }
            }
        }

        if(lastPayData){
            /* CHANGE 3a: balance = ikea - new_paid (ignore server new_balance) */
            const newPaid   = parseFloat(lastPayData.new_paid || 0);
            const _ikeaVal  = parseFloat(activeRow.querySelector('.ikea-value').value) || 0;
            const newBal    = Math.max(0, _ikeaVal - newPaid);
            const invAmt    = _ikeaVal;

            activeRow.dataset.paid    = newPaid;
            activeRow.dataset.balance = newBal;
            activeRow.dataset.adjust  = parseFloat(lastPayData.invoice_amt || ARD.adjust || 0);
            refreshRowCells(activeRow, newPaid, newBal, cashAmt, chqTotal);
            updateViewPayBadge(activeRow);
            calcTotals();
            document.getElementById('hdrInv').textContent  = 'Rs. '+invAmt.toFixed(2);
            document.getElementById('hdrPaid').textContent = 'Rs. '+newPaid.toFixed(2);
            document.getElementById('hdrBal').textContent  = 'Rs. '+newBal.toFixed(2);
            ARD.paid = newPaid; ARD.balance = newBal; ARD.ikea = invAmt;
            lockPaidRow(activeRow, newBal);
            const _fd = new FormData(); _fd.append('detail_id', ARD.id);
            fetch('mark_detail_updated.php', {method:'POST', body:_fd}).catch(()=>{});
            activeRow.dataset.updated = '0';
        }
        if(!lastPayData && cashEmg){
            activeRow.style.backgroundColor = '#fef9c3';
            lockPaidRow(activeRow, parseFloat(activeRow.dataset.balance||0));
            const _efd = new FormData(); _efd.append('detail_id', ARD.id);
            fetch('mark_detail_updated.php', {method:'POST', body:_efd}).catch(()=>{});
            activeRow.dataset.updated = '0';
        }

        showToast(saved.join(' + ')+' ✓','ok');
        if(_toBeDeliveryActive){
            updateToBeDelivery(ARD.id, ()=>{ setTimeout(()=>closePayModal(), 900); });
        } else {
            setTimeout(()=>closePayModal(), 900);
        }

    } catch(err){
        showToast('Network error: '+err.message,'err');
    }

    btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Submit Payment';
}

/* ══════════════════════════════════════════
   MARK ROW AS CREDIT
══════════════════════════════════════════ */
function markRowAsCredit(){
    if(!activeRow) return;
    const btn = document.getElementById('markCreditRowBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving…';
    const fd = new FormData();
    fd.append('detail_id', ARD.id);
    fd.append('is_special_credit', '1');
    fetch('mark_detail_updated.php', {method:'POST', body:fd})
        .then(r=>r.json())
        .then(data=>{
            if(data.success){
                activeRow.dataset.updated = '1';
                activeRow.dataset.special = '1';
                activeRow.style.backgroundColor = '#fef9c3';
                lockPaidRow(activeRow, parseFloat(activeRow.dataset.balance||0));
                showToast('Marked as Credit ✓','ok');
                if(_toBeDeliveryActive){
                    updateToBeDelivery(ARD.id, ()=>{ setTimeout(()=>closePayModal(), 800); });
                } else {
                    setTimeout(()=>closePayModal(), 800);
                }
            } else {
                showToast('Error: '+(data.error||'Failed'),'err');
                btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-stamp"></i> Mark as Credit';
            }
        }).catch(e=>{
            showToast('Network error: '+e.message,'err');
            btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-stamp"></i> Mark as Credit';
        });
}

function markAsCredit(){
    if(!confirm('Save current adjustments and mark this field summary as processed on credit?\nThis will redirect to the view page.')) return;
    const btn = document.getElementById('markCreditBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Saving…';
    const form = document.getElementById('editSummaryForm');
    fetch('update_field_summary.php', {method:'POST', body: new FormData(form)})
        .then(r=>r.json())
        .then(data=>{
            if(data.success){
                showToast('Saved. Redirecting…','ok');
                setTimeout(()=>{ window.location.href='view_field_summary.php?id=<?php echo $field_summary_id; ?>'; }, 900);
            } else {
                showToast(data.message||'Save failed','err');
                btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-stamp"></i> Mark as Credit & Close';
            }
        })
        .catch(e=>{
            showToast('Error: '+e.message,'err');
            btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-stamp"></i> Mark as Credit & Close';
        });
}

/* ══════════════════════════════════════════
   TOAST
══════════════════════════════════════════ */
function showToast(msg, type){
    let t = document.getElementById('payToast');
    if(!t){ t = document.createElement('div'); t.id='payToast'; t.style.display='none'; document.body.appendChild(t); }
    t.className = type==='ok' ? 'toast-ok' : 'toast-err';
    t.innerHTML = '<i class="fa-solid fa-'+(type==='ok'?'check-circle':'exclamation-circle')+'" style="margin-right:6px;"></i>'+msg;
    t.style.display = 'block';
    t.style.opacity = '1';
    t.style.transform = 'translateX(-50%) translateY(0)';
    clearTimeout(t._timer);
    t._timer = setTimeout(()=>{
        t.style.opacity='0';
        t.style.transform='translateX(-50%) translateY(-12px)';
        setTimeout(()=>{ t.style.display='none'; }, 380);
    }, 2600);
}

/* ══════════════════════════════════════════
   FORM SAVE
══════════════════════════════════════════ */
document.getElementById('editSummaryForm').addEventListener('submit',function(e){
    e.preventDefault();
    const sb = document.getElementById('saveBtn');
    const al = document.getElementById('alertContainer');
    sb.disabled=true; sb.innerHTML='<span class="spinner"></span> Saving…'; al.innerHTML='';
    fetch('update_field_summary.php',{method:'POST',body:new FormData(this)})
        .then(r=>r.json())
        .then(data=>{
            if(data.success){
                al.innerHTML='<div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> '+data.message+'</div>';
                setTimeout(()=>{ window.location.href='view_field_summary.php?id=<?php echo $field_summary_id; ?>'; },1400);
            } else {
                al.innerHTML='<div class="alert alert-error"><i class="fa-solid fa-exclamation-circle"></i> '+data.message+'</div>';
                sb.disabled=false; sb.innerHTML='<i class="fa-solid fa-save"></i> Save Changes';
            }
        })
        .catch(err=>{
            al.innerHTML='<div class="alert alert-error">Error: '+err.message+'</div>';
            sb.disabled=false; sb.innerHTML='<i class="fa-solid fa-save"></i> Save Changes';
        });
});

document.addEventListener('keydown', e=>{
    if(e.key==='Escape'){
        if(document.getElementById('viewPayModal').classList.contains('open')){
            closeViewPayments();
        } else {
            closePayModal();
        }
    }
});

/* ══════════════════════════════════════════
   TO BE DELIVERY
══════════════════════════════════════════ */
let _toBeDeliveryActive = false;

function toggleToBeDelivery(){
    _toBeDeliveryActive = !_toBeDeliveryActive;
    const btn = document.getElementById('tbdFooterBtn');
    if(_toBeDeliveryActive){
        btn.style.background    = '#3b82f6';
        btn.style.color         = '#fff';
        btn.style.borderColor   = '#3b82f6';
        btn.innerHTML = '<i class="fa-solid fa-truck"></i> To Be Delivery ✓';
    } else {
        btn.style.background    = '#fff';
        btn.style.color         = '#3b82f6';
        btn.style.borderColor   = '#bfdbfe';
        btn.innerHTML = '<i class="fa-solid fa-truck"></i> Mark as To Be Delivery';
    }
    const chk = document.getElementById('toBeDeliveryChk');
    if(chk) chk.checked = _toBeDeliveryActive;
}

function resetToBeDelivery(){
    _toBeDeliveryActive = false;
    const btn = document.getElementById('tbdFooterBtn');
    if(btn){
        btn.style.background  = '#fff';
        btn.style.color       = '#3b82f6';
        btn.style.borderColor = '#bfdbfe';
        btn.innerHTML = '<i class="fa-solid fa-truck"></i> Mark as To Be Delivery';
    }
    const chk = document.getElementById('toBeDeliveryChk');
    if(chk) chk.checked = false;
}

function updateToBeDelivery(detailId, callback){
    const fd = new FormData();
    fd.append('detail_id',      detailId);
    fd.append('to_be_delivery', '1');
    fetch('mark_to_be_delivery.php', {method:'POST', body:fd})
        .then(r=>r.json())
        .then(data=>{
            if(!data.success) showToast('To Be Delivery flag error: '+(data.error||'Unknown'),'err');
            if(callback) callback();
        })
        .catch(e=>{ showToast('To Be Delivery network error: '+e.message,'err'); if(callback) callback(); });
}
</script>
<?php include 'footer.php'; ?>