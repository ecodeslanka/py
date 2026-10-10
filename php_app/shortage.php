<?php
include 'config.php';
include 'header.php';

$import_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$person_filter = isset($_GET['person']) ? trim($_GET['person']) : '';

if (!$import_id) {
    header('Location: unloading_import_history.php');
    exit;
}

// Get import header
$import_result = mysqli_query($conn, "SELECT * FROM unloading_summary_imports WHERE id = $import_id");
if (!$import_result || mysqli_num_rows($import_result) === 0) {
    header('Location: unloading_import_history.php');
    exit;
}
$import = mysqli_fetch_assoc($import_result);

// Ensure unloading_data table exists
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS unloading_data (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    import_id           INT NOT NULL,
    import_detail_id    INT NULL,
    record_date         DATE NULL,
    delivery_person_code VARCHAR(100) NULL,
    delivery_person_name VARCHAR(255) NULL,
    vehicle             VARCHAR(255) NULL,
    sku_code            VARCHAR(100) NULL,
    sku_desc            VARCHAR(500) NULL,
    tur                 DECIMAL(12,2) DEFAULT 0,
    mrp                 DECIMAL(12,2) DEFAULT 0,
    adj_qty_good_units  DECIMAL(12,2) DEFAULT 0,
    adj_qty_damage      DECIMAL(12,2) DEFAULT 0,
    actual_qty          DECIMAL(12,2) DEFAULT NULL,
    actual_damage_qty   DECIMAL(12,2) DEFAULT NULL,
    short_excess        DECIMAL(12,2) DEFAULT NULL,
    charge_to_employee  DECIMAL(12,2) DEFAULT NULL,
    absorb_by_company   DECIMAL(12,2) DEFAULT NULL,
    pay_variance        DECIMAL(12,2) DEFAULT NULL,
    delivery_date       DATE NULL,
    is_prev_day         TINYINT(1) DEFAULT 0,
    status              VARCHAR(20) DEFAULT 'imported',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_import    (import_id),
    INDEX idx_person    (delivery_person_name),
    INDEX idx_sku       (sku_code)
)");

/* Add is_prev_day to existing table if missing */
$_ud_cols = [];
$_ud_qr = mysqli_query($conn, "SHOW COLUMNS FROM unloading_data");
if ($_ud_qr) while ($_uc = mysqli_fetch_assoc($_ud_qr)) $_ud_cols[] = $_uc['Field'];
if (!in_array('is_prev_day', $_ud_cols))
    mysqli_query($conn, "ALTER TABLE unloading_data ADD COLUMN `is_prev_day` TINYINT(1) DEFAULT 0 AFTER delivery_date");

// Check if data is already initialized in unloading_data
$cnt_row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as c FROM unloading_data WHERE import_id = $import_id"));
$data_initialized = ($cnt_row && $cnt_row['c'] > 0);

$persons_result = mysqli_query($conn,
    "SELECT DISTINCT delivery_person_name
     FROM unloading_summary_import_details
     WHERE import_id = $import_id AND delivery_person_name != ''
     ORDER BY delivery_person_name ASC");
$delivery_persons = [];
if ($persons_result) {
    while ($pr = mysqli_fetch_assoc($persons_result)) {
        $delivery_persons[] = $pr['delivery_person_name'];
    }
}

// Build WHERE for data query
$details_result = null;
if ($data_initialized) {
    $where = "import_id = $import_id";
    if (!empty($person_filter)) {
        $pf_safe = mysqli_real_escape_string($conn, $person_filter);
        $where .= " AND delivery_person_name = '$pf_safe'";
    }
    $details_result = mysqli_query($conn,
        "SELECT id, record_date, delivery_person_name, delivery_person_code, vehicle,
                sku_code, sku_desc, tur, mrp,
                adj_qty_good_units, adj_qty_damage,
                actual_qty, actual_damage_qty, short_excess,
                charge_to_employee, absorb_by_company, pay_variance,
                delivery_date, is_prev_day, status
         FROM unloading_data
         WHERE $where
         ORDER BY is_prev_day ASC, id ASC");
}

// Aggregated stats
$agg = ['persons'=>0,'skus'=>0,'total_good'=>0,'total_damage'=>0];
if ($data_initialized) {
    $agg_r = mysqli_query($conn,
        "SELECT COUNT(DISTINCT delivery_person_name) as persons,
                COUNT(DISTINCT sku_code) as skus,
                SUM(adj_qty_good_units) as total_good,
                SUM(adj_qty_damage) as total_damage
         FROM unloading_data
         WHERE import_id = $import_id");
    if ($agg_r) $agg = mysqli_fetch_assoc($agg_r);
}

// Fetch active employees for the pay modal
$emp_res = mysqli_query($conn,
    "SELECT id, employee_id, employee_full_name
     FROM employees WHERE active = 1 AND status NOT IN ('Resigned','Terminated')
     ORDER BY employee_full_name ASC");
$employees_list = [];
if ($emp_res) while ($e = mysqli_fetch_assoc($emp_res)) $employees_list[] = $e;

// Ensure pay transactions table
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS unloading_pay_transactions (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    import_detail_id INT NOT NULL,
    import_id        INT NOT NULL,
    transaction_date DATE NOT NULL,
    entry_type       VARCHAR(20) NOT NULL,
    employee_id      INT NULL,
    employee_name    VARCHAR(200) NULL,
    amount           DECIMAL(12,2) DEFAULT 0,
    se_value         DECIMAL(12,2) DEFAULT 0,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_detail (import_detail_id),
    INDEX idx_import (import_id)
)");

// Fetch last MRP/TUR per SKU from unloading_data (for Add Item auto-fill)
$sku_prices = [];
$sp_res = mysqli_query($conn,
    "SELECT sku_code, tur, mrp
     FROM unloading_data
     WHERE tur > 0 OR mrp > 0
     ORDER BY id ASC");
if ($sp_res) {
    while ($sp = mysqli_fetch_assoc($sp_res)) {
        $sku_prices[$sp['sku_code']] = [
            'tur' => floatval($sp['tur']),
            'mrp' => floatval($sp['mrp'])
        ];
    }
}
$sp2_res = mysqli_query($conn,
    "SELECT sku_code, tur, mrp
     FROM unloading_summary_import_details
     WHERE import_id = $import_id AND (tur > 0 OR mrp > 0)
     ORDER BY id ASC");
if ($sp2_res) {
    while ($sp2 = mysqli_fetch_assoc($sp2_res)) {
        if (!isset($sku_prices[$sp2['sku_code']])) {
            $sku_prices[$sp2['sku_code']] = [
                'tur' => floatval($sp2['tur']),
                'mrp' => floatval($sp2['mrp'])
            ];
        }
    }
}

$delivery_date_display = !empty($import['delivery_date']) ? date('Y-m-d', strtotime($import['delivery_date'])) : date('Y-m-d');

/* Payroll periods */
$MN = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$payroll_periods_all = [];
$active_period       = null;
$pp_qr = mysqli_query($conn, "SELECT id,year,month,open_date,close_date,status FROM payroll_periods ORDER BY year DESC, month DESC");
if ($pp_qr) {
    while ($pp = mysqli_fetch_assoc($pp_qr)) {
        $pp['label'] = $pp['year'].' — '.$MN[$pp['month']].' ['.$pp['status'].']';
        $payroll_periods_all[] = $pp;
        if (!$active_period && $pp['status'] === 'Open') $active_period = $pp;
    }
}
$ap_js = $active_period ? [
    'id'         => intval($active_period['id']),
    'year'       => intval($active_period['year']),
    'month'      => intval($active_period['month']),
    'open_date'  => $active_period['open_date'],
    'close_date' => $active_period['close_date'],
] : null;
?>
<!-- Select2 -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet">
<!-- SheetJS for Excel export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<style>
.select2-container--default .select2-selection--single{height:36px;border:1px solid #e5e5e5;border-radius:6px;font-family:'Inter',sans-serif;font-size:13px}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:36px;padding-left:10px;color:#1f2937}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:34px}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open .select2-selection--single{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.08);outline:none}
.select2-container{width:100%!important;flex:1;min-width:0}
.select2-dropdown{border:1px solid #e5e5e5;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.1);font-size:13px;font-family:'Inter',sans-serif}
.select2-container--default .select2-results__option--highlighted[aria-selected]{background:#7c3aed}
.select2-search--dropdown .select2-search__field{border:1px solid #e5e5e5;border-radius:4px;padding:6px 10px;font-size:13px;font-family:'Inter',sans-serif}

#personFilterWrap{display:inline-block;min-width:220px;max-width:280px;}
#personFilterWrap .select2-container--default .select2-selection--single{height:36px;}
#personFilterWrap .select2-container--default .select2-selection--single .select2-selection__rendered{line-height:36px;color:#1f2937;}
#personFilterWrap .select2-container--default .select2-selection--single .select2-selection__placeholder{color:#9ca3af;}

.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:0;color:#1f2937;display:flex;align-items:center;gap:8px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}

.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;font-family:'Inter',sans-serif;text-decoration:none;white-space:nowrap}
.btn-sm{padding:7px 14px;font-size:13px}
.btn-xs{padding:4px 9px;font-size:12px}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}
.btn-success{background:#16a34a;color:#fff}
.btn-success:hover{background:#15803d}
.btn-primary{background:#2563eb;color:#fff}
.btn-primary:hover{background:#1d4ed8}
.btn-autofill{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.btn-autofill:hover{background:#dcfce7;border-color:#86efac}
.btn-save-row{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.btn-save-row:hover{background:#1e40af;color:#fff}
.btn-save-row:disabled{opacity:.5;cursor:not-allowed}
.btn-pay{background:#7c3aed;color:#fff}
.btn-pay:hover{background:#6d28d9}
.btn-pay-row{background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe}
.btn-pay-row:hover{background:#7c3aed;color:#fff}
.btn-pay-row.paid-short{background:#fef2f2;color:#dc2626;border-color:#fca5a5}
.btn-pay-row.paid-excess{background:#f0fdf4;color:#16a34a;border-color:#86efac}
.btn-pay-row.paid-short:hover{background:#dc2626;color:#fff}
.btn-pay-row.paid-excess:hover{background:#16a34a;color:#fff}
.btn-add{background:#7c3aed;color:#fff}
.btn-add:hover{background:#6d28d9}
.btn-danger{background:#dc2626;color:#fff}
.btn-danger:hover{background:#b91c1c}
.btn-del-row{background:#fff0f0;color:#dc2626;border:1px solid #fecaca}
.btn-del-row:hover{background:#dc2626;color:#fff}
.btn-excel{background:#166534;color:#fff;border:none}
.btn-excel:hover{background:#15803d}
.btn-reimport{background:#b45309;color:#fff;border:none}
.btn-reimport:hover{background:#92400e}
.btn-prevday{background:#854d0e;color:#fef9c3;border:1px solid #713f12}
.btn-prevday:hover{background:#713f12;color:#fef08a}

.import-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:14px;margin-top:12px}
.summary-box{padding:14px;background:#f9fafb;border-radius:8px;border:1px solid #e5e5e5}
.summary-label{font-size:11px;color:#6b7280;margin-bottom:5px}
.summary-value{font-size:18px;font-weight:700;color:#1f2937}
.text-success{color:#22c55e;font-weight:600}
.text-error{color:#ef4444;font-weight:600}

.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600}
.badge-success{background:#f0fdf4;color:#166634;border:1px solid #bbf7d0}
.badge-warning{background:#fef3c7;color:#92400e;border:1px solid #fde68a}

.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.search-wrap{position:relative;flex:1;min-width:180px;max-width:280px}
.search-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;pointer-events:none}
.search-input{width:100%;padding:8px 12px 8px 32px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s;box-sizing:border-box}
.search-input:focus{border-color:#000}
.filter-btns{display:flex;gap:6px;flex-wrap:wrap}
.flt{padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid transparent;transition:all .2s;font-family:'Inter',sans-serif}
.flt-all{background:#f5f5f5;color:#333;border-color:#e5e5e5}
.flt-all.active{background:#000;color:#fff;border-color:#000}
.flt-short{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.flt-short.active{background:#dc2626;color:#fff;border-color:#dc2626}
.flt-excess{background:#f0fdf4;color:#166634;border-color:#bbf7d0}
.flt-excess.active{background:#16a34a;color:#fff;border-color:#16a34a}
.flt-both{background:#eff6ff;color:#1e40af;border-color:#bfdbfe}
.flt-both.active{background:#1e40af;color:#fff;border-color:#1e40af}
#rowCount{font-size:12px;color:#6b7280;margin-left:4px}

.totals-strip{display:grid;grid-template-columns:repeat(7,1fr);border:1px solid #e5e5e5;border-radius:8px;overflow:hidden;margin-bottom:14px;background:#fff;}
.ts-cell{padding:10px 12px;border-right:1px solid #f0f0f0;text-align:center;background:#fff;}
.ts-cell:last-child{border-right:none;}
.ts-cell.ts-goodcell{background:#f0fdf4;}
.ts-cell.ts-dmgcell{background:#fef2f2;}
.ts-lbl{font-size:10.5px;font-weight:700;color:#6b7280;margin-bottom:4px;text-transform:none;white-space:nowrap;}
.ts-sub{display:block;font-size:9px;font-weight:400;color:#9ca3af;}
.ts-val{font-size:15px;font-weight:800;color:#1f2937;}
.ts-green{color:#16a34a;}
.ts-red{color:#dc2626;}
.ts-muted{color:#d1d5db;}

.table-wrap{max-height:65vh;overflow:auto;border:1px solid #e5e5e5;border-radius:8px}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead tr{position:sticky;top:0;z-index:10}
.data-table thead th{background:#f0f0f0;padding:10px 12px;text-align:left;font-weight:600;color:#222;font-size:12px;white-space:nowrap;border-bottom:2px solid #d5d5d5;box-shadow:0 2px 0 #d5d5d5}
.data-table th.num{text-align:right}
.data-table tbody tr{border-bottom:1px solid #f0f0f0;transition:background .1s}
.data-table tbody tr:hover{background:#fafafa}
.data-table tbody tr.hidden-row{display:none}
.data-table td{padding:8px 12px;color:#333;white-space:nowrap;vertical-align:middle}
.data-table td.num{text-align:right}

.data-table thead tr.totals-row{position:sticky;top:37px;z-index:9;}
.data-table thead tr.totals-row th{background:#fafaf9;border-bottom:2px solid #d5d5d5;font-size:12px;font-weight:700;color:#1f2937;padding:8px 12px;white-space:nowrap;}
.data-table thead tr.totals-row th.num{text-align:right;}
.data-table thead tr.totals-row th.label{color:#6b7280;font-weight:700;text-transform:uppercase;font-size:10.5px;letter-spacing:.4px;}
.data-table thead tr.totals-row th.th-green{color:#16a34a;}
.data-table thead tr.totals-row th.th-red{color:#dc2626;}

.data-table th.col-goodval{background:#f0fdf4;border-top:2px solid #16a34a;}
.data-table th.col-dmgval{background:#fef2f2;border-top:2px solid #dc2626;}
.val-good{color:#166634;font-weight:700;font-size:12.5px;}
.val-dmg-amount{color:#dc2626;font-weight:700;font-size:12.5px;}

.data-table tbody tr.prevday-row td{background:#fefce8 !important;}
.data-table tbody tr.prevday-row:hover td{background:#fef9c3 !important;}
.prevday-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;background:#fef08a;color:#854d0e;border:1px solid #fcd34d;white-space:nowrap;}

.qty-input{width:90px;padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;font-family:'Inter',sans-serif;text-align:right;background:#fffbeb;transition:border-color .2s,background .2s}
.qty-input:focus{outline:none;border-color:#000;background:#fff;box-shadow:0 0 0 2px rgba(0,0,0,.06)}
.qty-input.saved{border-color:#16a34a;background:#f0fdf4}
.qty-input.autofilled{border-color:#7c3aed;background:#f5f3ff}
.qty-dmg{background:#fff0f0}
.qty-dmg:focus{border-color:#dc2626;background:#fff;box-shadow:0 0 0 2px rgba(220,38,38,.08)}

.short{color:#dc2626;font-weight:700}
.excess{color:#16a34a;font-weight:700}
.zero{color:#6b7280;font-weight:600}
.val-charge{color:#dc2626;font-weight:600}
.val-absorb{color:#0369a1;font-weight:600}
.val-pay-excess{color:#16a34a;font-weight:600}

.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:none;align-items:center;justify-content:center;padding:16px}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:12px;padding:28px 32px;width:94%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,.2);max-height:90vh;overflow-y:auto}
.modal-title{font-size:18px;font-weight:700;color:#1f2937;margin-bottom:6px;display:flex;align-items:center;gap:8px}
.modal-sub{font-size:13px;color:#6b7280;margin-bottom:20px}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;padding-top:16px;border-top:1px solid #e5e5e5}

.init-modal-body{text-align:center;padding:10px 0}
.init-icon{font-size:48px;color:#7c3aed;margin-bottom:16px}
.init-title{font-size:20px;font-weight:700;color:#1f2937;margin-bottom:8px}
.init-desc{font-size:14px;color:#6b7280;margin-bottom:24px;line-height:1.6}

.form-group{margin-bottom:14px}
.form-label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;text-transform:uppercase;letter-spacing:.3px}
.form-input{width:100%;padding:9px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;outline:none;box-sizing:border-box;transition:border-color .2s}
.form-input:focus{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.08)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.form-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}

.price-hint{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:#7c3aed;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:4px;padding:3px 8px;margin-top:4px;display:none}
.price-hint.show{display:inline-flex}

.pay-modal-box{background:#fff;border-radius:12px;width:98%;max-width:960px;max-height:88vh;box-shadow:0 24px 64px rgba(0,0,0,.22);display:flex;flex-direction:column;overflow:hidden}
.pmo-header{padding:12px 18px 10px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:flex-start;flex-shrink:0;background:#fff}
.pmo-title{font-size:15px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px}
.pmo-close{background:none;border:none;cursor:pointer;color:#9ca3af;font-size:22px;line-height:1;padding:0;transition:color .2s}
.pmo-close:hover{color:#1f2937}

.pmo-sku-strip{display:flex;flex-wrap:wrap;border-bottom:1px solid #f0f0f0;flex-shrink:0;background:#fafafa;}
.pmo-sku-cell{padding:7px 14px;border-right:1px solid #f0f0f0;}
.pmo-sku-cell:last-child{border-right:none;}
.pmo-sku-lbl{font-size:9px;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;font-weight:700;margin-bottom:2px;}
.pmo-sku-val{font-size:12px;font-weight:700;color:#1f2937;}
.pmo-sku-val.mono{font-family:'JetBrains Mono',monospace;font-size:11px;}

.pmo-info{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:8px 18px;background:#f9fafb;border-bottom:1px solid #f0f0f0;flex-shrink:0}
.pmo-info-cell{background:#fff;border:1px solid #e5e5e5;border-radius:7px;padding:7px 10px}
.pmo-info-cell.hi-short{background:#fef2f2;border-color:#fca5a5}
.pmo-info-cell.hi-excess{background:#f0fdf4;border-color:#86efac}
.pmo-info-lbl{font-size:9.5px;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px}
.pmo-info-val{font-size:13px;font-weight:700;color:#1f2937}
.pmo-info-cell.hi-short .pmo-info-lbl{color:#991b1b}
.pmo-info-cell.hi-short .pmo-info-val{color:#dc2626;font-size:16px}
.pmo-info-cell.hi-excess .pmo-info-lbl{color:#166634}
.pmo-info-cell.hi-excess .pmo-info-val{color:#16a34a;font-size:16px}
.pmo-body{padding:12px 16px;overflow-y:auto;flex:1}
.pmo-section{border:1px solid #e5e5e5;border-radius:8px;margin-bottom:14px;overflow:hidden}
.pmo-sec-head{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
.pmo-sec-head.red{background:#fef2f2;color:#991b1b;border-bottom:1px solid #fecaca}
.pmo-sec-head.green{background:#f0fdf4;color:#166634;border-bottom:1px solid #bbf7d0}
.pmo-sec-head.blue{background:#eff6ff;color:#1e40af;border-bottom:1px solid #bfdbfe}
.pmo-sec-head.purple{background:#f5f3ff;color:#6d28d9;border-bottom:1px solid #ddd6fe}
.pmo-sec-total{font-size:15px;font-weight:800;letter-spacing:0}
.pmo-sec-body{padding:12px 14px;background:#fff}

.se-type-banner{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;font-weight:600;}
.se-type-banner.short-banner{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;}
.se-type-banner.excess-banner{background:#f0fdf4;border:1px solid #86efac;color:#166634;}
.se-type-banner i{font-size:18px;}

.charge-table-wrap{overflow-x:auto;border:1px solid #fecaca;border-radius:8px;margin-bottom:10px;}
.charge-table-wrap.excess-wrap{border-color:#86efac;}
.charge-tbl{width:100%;border-collapse:collapse;font-size:12px;min-width:700px;}
.charge-tbl thead tr{background:#fef2f2;}
.charge-tbl.excess-tbl thead tr{background:#f0fdf4;}
.charge-tbl thead th{padding:8px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#991b1b;white-space:nowrap;border-bottom:1px solid #fecaca;text-align:left;}
.charge-tbl.excess-tbl thead th{color:#166634;border-bottom-color:#86efac;}
.charge-tbl thead th.cnum{text-align:right;}
.charge-tbl tbody td{padding:6px 8px;border-bottom:1px solid #fef2f2;vertical-align:middle;}
.charge-tbl.excess-tbl tbody td{border-bottom-color:#f0fdf4;}
.charge-tbl tbody tr:last-child td{border-bottom:none;}
.charge-tbl tbody tr:hover td{background:#fff5f5;}
.charge-tbl.excess-tbl tbody tr:hover td{background:#f7fef7;}
.ct-inp{width:100%;padding:5px 7px;border:1px solid #e5e5e5;border-radius:5px;font-size:12px;font-family:'Inter',sans-serif;background:#fff;outline:none;transition:border-color .15s;box-sizing:border-box;}
.ct-inp:focus{border-color:#7c3aed;box-shadow:0 0 0 2px rgba(124,58,237,.08);}
.ct-inp.cnum{text-align:right;}
.ct-inp[readonly]{background:#f9fafb;color:#6b7280;cursor:default;}
.ct-rm{background:none;border:none;cursor:pointer;color:#dc2626;font-size:14px;padding:3px 6px;line-height:1;border-radius:4px;transition:background .15s;white-space:nowrap;}
.ct-rm:hover{background:#fef2f2;}
.add-emp-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 13px;border:1px dashed #fca5a5;border-radius:6px;background:#fff;color:#dc2626;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;margin-top:8px;transition:all .2s;}
.add-emp-btn:hover{background:#fef2f2;border-color:#dc2626;}
.add-emp-btn.excess-add{border-color:#86efac;color:#166534;}
.add-emp-btn.excess-add:hover{background:#f0fdf4;border-color:#16a34a;}
.absorb-row{display:flex;align-items:center;gap:10px}
.absorb-lbl{font-size:13px;color:#1e40af;font-weight:600;flex:1;display:flex;align-items:center;gap:6px}
.absorb-inp{width:130px;flex-shrink:0;border:1px solid #bfdbfe;border-radius:6px;padding:7px 10px;font-size:13px;font-family:'Inter',sans-serif;text-align:right;outline:none;transition:border-color .2s}
.absorb-inp:focus{border-color:#1e40af;box-shadow:0 0 0 3px rgba(30,64,175,.08)}
.pmo-totals{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:14px;padding:14px;background:#f9fafb;border:1px solid #e5e5e5;border-radius:8px}
.ptb-cell{text-align:center}
.ptb-lbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px}
.ptb-val{font-size:17px;font-weight:800}
.ptb-val.red{color:#dc2626}.ptb-val.blue{color:#0369a1}.ptb-val.green{color:#16a34a}.ptb-val.orange{color:#d97706}
.pmo-save{width:100%;padding:13px;background:#7c3aed;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;margin-top:14px;display:flex;align-items:center;justify-content:center;gap:8px;transition:background .2s}
.pmo-save:hover{background:#6d28d9}
.pmo-save:disabled{opacity:.6;cursor:not-allowed}
.pmo-save.save-short{background:#dc2626;}
.pmo-save.save-short:hover{background:#b91c1c;}
.pmo-save.save-excess{background:#16a34a;}
.pmo-save.save-excess:hover{background:#15803d;}

.pmo-payroll-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;}
.pmo-fg{display:flex;flex-direction:column;gap:4px;}
.pmo-lbl{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;}
.pmo-inp{padding:8px 10px;border:1.5px solid #e5e5e5;border-radius:7px;font-size:12.5px;font-family:'Inter',sans-serif;color:#1f2937;background:#f9fafb;width:100%;transition:border-color .15s;}
.pmo-inp:focus{outline:none;border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.09);}
.pmo-optional-tag{font-size:9px;color:#9ca3af;font-weight:400;text-transform:none;margin-left:4px;}

#toast{position:fixed;bottom:28px;right:28px;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;color:#fff;z-index:9999;display:none;box-shadow:0 4px 16px rgba(0,0,0,.18)}
#toast.success{background:#16a34a}
#toast.error{background:#dc2626}

.row-cb{width:16px;height:16px;accent-color:#7c3aed;cursor:pointer;flex-shrink:0;}
.th-cb{text-align:center !important;width:36px;}
.td-cb{text-align:center;width:36px;}
#bulkBar{display:none;align-items:center;gap:10px;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;padding:8px 14px;margin-bottom:10px;flex-wrap:wrap;}
#bulkBar.show{display:flex;}
#bulkCount{font-size:13px;font-weight:600;color:#6d28d9;}
.btn-bulk-pay{background:#7c3aed;color:#fff;border:none;}
.btn-bulk-pay:hover{background:#6d28d9;}
.btn-bulk-clear{background:#fff;color:#6b7280;border:1px solid #e5e5e5;}
.btn-bulk-clear:hover{background:#f5f5f5;}

.bulk-pay-modal-box{background:#fff;border-radius:12px;width:98%;max-width:1000px;max-height:90vh;box-shadow:0 24px 64px rgba(0,0,0,.22);display:flex;flex-direction:column;overflow:hidden;}
.bulk-rows-summary{overflow-x:auto;border:1px solid #ddd6fe;border-radius:8px;margin-bottom:12px;}
.bulk-rows-tbl{width:100%;border-collapse:collapse;font-size:12px;min-width:600px;}
.bulk-rows-tbl thead th{background:#f5f3ff;padding:7px 10px;font-size:10px;font-weight:700;text-transform:uppercase;color:#6d28d9;border-bottom:1px solid #ddd6fe;text-align:left;white-space:nowrap;}
.bulk-rows-tbl thead th.rnum{text-align:right;}
.bulk-rows-tbl tbody td{padding:6px 10px;border-bottom:1px solid #f5f3ff;font-size:12px;}
.bulk-rows-tbl tbody tr:last-child td{border-bottom:none;}
.bulk-rows-tbl tbody tr:hover td{background:#faf5ff;}
.bulk-total-bar{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;padding:12px 16px;margin-bottom:12px;}
.btb-cell{text-align:center;}
.btb-lbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}
.btb-val{font-size:16px;font-weight:800;color:#7c3aed;}

.del-modal-box{background:#fff;border-radius:12px;padding:28px 32px;width:94%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.2);text-align:center}
.del-icon{font-size:44px;color:#dc2626;margin-bottom:14px}
.del-title{font-size:18px;font-weight:700;color:#1f2937;margin-bottom:8px}
.del-desc{font-size:13px;color:#6b7280;margin-bottom:24px;line-height:1.6}

@media(max-width:640px){
    .import-summary-grid{grid-template-columns:1fr 1fr}
    .toolbar{flex-direction:column;align-items:stretch}
    .pmo-info{grid-template-columns:1fr}
    .pmo-totals{grid-template-columns:1fr}
    .pmo-sku-strip{grid-template-columns:1fr 1fr;}
    .pmo-payroll-grid{grid-template-columns:1fr;}
    #personFilterWrap{max-width:100%;min-width:unset;width:100%}
    .search-wrap{max-width:100%}
    .form-row,.form-row-3{grid-template-columns:1fr}
    .totals-strip{grid-template-columns:repeat(2,1fr);}
    .ts-cell{border-bottom:1px solid #f0f0f0;}
}
</style>
<!-- Page Header -->
<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title"><i class="fa-solid fa-truck-ramp-box"></i> Unloading Import Details</h2>
            <p class="page-subtitle">Import #<?php echo $import_id; ?> &mdash; <?php echo htmlspecialchars($import['filename']); ?>
                <?php if (!empty($person_filter)): ?>
                    &mdash; <span class="badge badge-warning"><i class="fa-solid fa-user"></i> <?php echo htmlspecialchars($person_filter); ?></span>
                <?php endif; ?>
            </p>
        </div>
        <div style="display:flex;gap:8px;">
            <?php if (!empty($person_filter)): ?>
                <a href="?id=<?php echo $import_id; ?>" class="btn btn-secondary btn-sm">
                    <i class="fa-solid fa-users"></i> Show All Persons
                </a>
            <?php endif; ?>
            <a href="gse_list.php" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
        </div>
    </div>
</div>

<!-- Records Table -->
<div class="content-card" id="mainCard" style="<?php echo $data_initialized ? '' : 'display:none;'; ?>">
    <div class="card-header-row">
        <h3 class="card-title"><i class="fa-solid fa-table"></i> Records <span id="rowCount"></span></h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn btn-excel btn-sm" onclick="exportToExcel()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
            <button class="btn btn-reimport btn-sm" onclick="openReImportModal()"><i class="fa-solid fa-rotate"></i> Re-Import Excel</button>
            <button class="btn btn-add btn-sm" onclick="openAddItemModal()"><i class="fa-solid fa-plus"></i> Add Item</button>
            <button class="btn btn-prevday btn-sm" onclick="openPrevDayModal()"><i class="fa-solid fa-calendar-minus"></i> Add Previous Day Item</button>
            <button class="btn btn-autofill btn-sm" onclick="autoFillActual()"><i class="fa-solid fa-wand-magic-sparkles"></i> Auto-fill Actual</button>
            <button class="btn btn-success btn-sm" onclick="saveAll()"><i class="fa-solid fa-floppy-disk"></i> Save All</button>
        </div>
    </div>

    <div id="bulkBar">
        <span id="bulkCount">0 rows selected</span>
        <button class="btn btn-bulk-pay btn-sm" onclick="openBulkPayModal()"><i class="fa-solid fa-file-invoice-dollar"></i> Bulk Pay Allocation</button>
        <button class="btn btn-bulk-clear btn-sm" onclick="clearBulkSelection()"><i class="fa-solid fa-xmark"></i> Clear Selection</button>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="search-input" id="searchBox" placeholder="Search SKU, description..." oninput="applyFilters()">
        </div>
        <div id="personFilterWrap">
            <select id="personFilter">
                <option value="">All Delivery Persons</option>
                <?php foreach ($delivery_persons as $dp): ?>
                    <option value="<?php echo htmlspecialchars($dp); ?>" <?php echo ($person_filter === $dp) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dp); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-btns">
            <button class="flt flt-all active" onclick="setFilter('all',this)">All</button>
            <button class="flt flt-short" onclick="setFilter('short',this)"><i class="fa-solid fa-arrow-down"></i> Short</button>
            <button class="flt flt-excess" onclick="setFilter('excess',this)"><i class="fa-solid fa-arrow-up"></i> Excess</button>
            <button class="flt flt-both" onclick="setFilter('both',this)"><i class="fa-solid fa-arrows-up-down"></i> Both</button>
        </div>
    </div>

    <div class="totals-strip" id="totalsStrip">
        <div class="ts-cell ts-goodcell"><div class="ts-lbl">Good Value<span class="ts-sub">(Actual × TUR)</span></div><div class="ts-val ts-green" id="ts_good">0.00</div></div>
        <div class="ts-cell ts-dmgcell"><div class="ts-lbl">Damage Value<span class="ts-sub">(Dmg Actual × TUR)</span></div><div class="ts-val ts-red" id="ts_dmg">0.00</div></div>
        <div class="ts-cell"><div class="ts-lbl">Short / Excess</div><div class="ts-val" id="ts_se">0.00</div></div>
        <div class="ts-cell"><div class="ts-lbl">S/E Value<span class="ts-sub">(TUR × S/E)</span></div><div class="ts-val" id="ts_seval">0.00</div></div>
        <div class="ts-cell"><div class="ts-lbl">Charge to Employee</div><div class="ts-val" id="ts_charge">—</div></div>
        <div class="ts-cell"><div class="ts-lbl">Absorb by Company</div><div class="ts-val" id="ts_absorb">—</div></div>
        <div class="ts-cell"><div class="ts-lbl">Variance</div><div class="ts-val" id="ts_var">0.00</div></div>
    </div>

    <div class="table-wrap">
        <table class="data-table" id="recordsTable">
            <thead>
                <tr>
                    <th class="th-cb"><input type="checkbox" class="row-cb" id="selectAllCb" onchange="toggleSelectAll(this)" title="Select All"></th>
                    <th>#</th>
                    <th>Date</th>
                    <th>Delivery Person</th>
                    <th>SKU Code</th>
                    <th>SKU Description</th>
                    <th class="num">TUR</th>
                    <th class="num">MRP</th>
                    <th class="num">Adj Qty<br>(Good)</th>
                    <th class="num">Adj Qty<br>(Damage)</th>
                    <th class="num">Total Qty</th>
                    <th class="num">Actual Qty <span style="color:#ef4444;font-size:10px;">✎</span></th>
                    <th class="num">Actual Dmg Qty <span style="color:#ef4444;font-size:10px;">✎</span></th>
                    <th class="num col-goodval">Good Value<br><span style="font-size:10px;font-weight:400;">(Actual × TUR)</span></th>
                    <th class="num col-dmgval">Damage Value<br><span style="font-size:10px;font-weight:400;">(Dmg Actual × TUR)</span></th>
                    <th class="num">Short / Excess</th>
                    <th class="num">S/E Value<br><span style="font-size:10px;font-weight:400;">(TUR × S/E)</span></th>
                    <th class="num">Charge to<br>Employee</th>
                    <th class="num">Absorb by<br>Company</th>
                    <th class="num">Variance</th>
                    <th style="text-align:center;">Action</th>
                </tr>
                <tr class="totals-row">
                    <th></th><th class="label">Totals</th><th></th><th></th><th></th><th></th>
                    <th class="num"></th><th class="num"></th>
                    <th class="num" id="th_adjgood">0.00</th>
                    <th class="num" id="th_adjdmg">0.00</th>
                    <th class="num" id="th_totalqty">0.00</th>
                    <th class="num" id="th_actual">0.00</th>
                    <th class="num" id="th_actualdmg">0.00</th>
                    <th class="num th-green" id="th_good">0.00</th>
                    <th class="num th-red" id="th_dmg">0.00</th>
                    <th class="num" id="th_se">0.00</th>
                    <th class="num" id="th_seval">0.00</th>
                    <th class="num" id="th_charge">—</th>
                    <th class="num" id="th_absorb">—</th>
                    <th class="num" id="th_var">0.00</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php if (!$data_initialized || !$details_result || mysqli_num_rows($details_result) === 0): ?>
                <tr><td colspan="21" style="text-align:center;color:#999;padding:40px;">No records found</td></tr>
            <?php else: ?>
            <?php $rownum = 1; while ($d = mysqli_fetch_assoc($details_result)):
                $is_prev_day = !empty($d['is_prev_day']) ? intval($d['is_prev_day']) : 0;
                $tur        = floatval($d['tur'] ?? 0);
                $mrp        = floatval($d['mrp'] ?? 0);
                $adj_good   = floatval($d['adj_qty_good_units'] ?? 0);
                $adj_damage = floatval($d['adj_qty_damage'] ?? 0);
                $total_qty  = $adj_good + $adj_damage;
                $actual_val     = ($d['actual_qty'] !== null) ? floatval($d['actual_qty']) : '';
                $actual_dmg_val = ($d['actual_damage_qty'] !== null) ? floatval($d['actual_damage_qty']) : '';

                $good_value = ($actual_val !== '' && $tur > 0) ? floatval($actual_val) * $tur : null;
                $dmg_value  = ($actual_dmg_val !== '' && $tur > 0) ? floatval($actual_dmg_val) * $tur : null;

                $sv = ($d['short_excess'] !== null) ? floatval($d['short_excess']) : null;
                $se_class = ''; $se_display = '-';
                if ($sv !== null) {
                    if      ($sv < 0) { $se_class = 'short';  $se_display = number_format($sv, 2); }
                    elseif  ($sv > 0) { $se_class = 'excess'; $se_display = '+'.number_format($sv, 2); }
                    else              { $se_class = 'zero';   $se_display = '0.00'; }
                }
                $se_val_amt = ($sv !== null && $tur > 0) ? $tur * abs($sv) : null;

                $s_charge = ($d['charge_to_employee'] !== null) ? floatval($d['charge_to_employee']) : null;
                $s_absorb = ($d['absorb_by_company']  !== null) ? floatval($d['absorb_by_company'])  : null;
                $s_var    = ($d['pay_variance']        !== null) ? floatval($d['pay_variance'])        : null;
                $is_paid  = ($s_charge !== null || $s_absorb !== null);

                $row_is_short  = ($sv !== null && $sv < 0);
                $row_is_excess = ($sv !== null && $sv > 0);

                $vd = '—'; $vc = '';
                if ($s_var !== null) {
                    $vd = ($s_var > 0 ? '+' : '') . number_format($s_var, 2);
                    $vc = $s_var < 0 ? 'short' : ($s_var > 0 ? 'excess' : 'zero');
                } elseif ($sv !== null && $tur > 0) {
                    $raw = $tur * $sv;
                    $vd  = ($raw > 0 ? '+' : '') . number_format($raw, 2);
                    $vc  = $raw < 0 ? 'short' : ($raw > 0 ? 'excess' : 'zero');
                }

                if ($is_paid) {
                    if ($row_is_short) {
                        $paybtn_class = 'btn btn-pay-row btn-xs paid-short';
                        $paybtn_label = '<i class="fa-solid fa-check"></i> Charged';
                    } elseif ($row_is_excess) {
                        $paybtn_class = 'btn btn-pay-row btn-xs paid-excess';
                        $paybtn_label = '<i class="fa-solid fa-check"></i> Paid Out';
                    } else {
                        $paybtn_class = 'btn btn-pay-row btn-xs paid-short';
                        $paybtn_label = '<i class="fa-solid fa-check"></i> Paid';
                    }
                } else {
                    $paybtn_class = 'btn btn-pay-row btn-xs';
                    $paybtn_label = '<i class="fa-solid fa-file-invoice-dollar"></i> Pay';
                }
            ?>
                <tr id="row-<?php echo $d['id']; ?>"
                    class="<?php echo $is_prev_day ? 'prevday-row' : ''; ?>"
                    data-person="<?php echo strtolower(htmlspecialchars($d['delivery_person_name'] ?? '')); ?>"
                    data-sku="<?php echo strtolower(htmlspecialchars($d['sku_code'] ?? '')); ?>"
                    data-desc="<?php echo strtolower(htmlspecialchars($d['sku_desc'] ?? '')); ?>"
                    data-se="<?php echo $sv !== null ? $sv : ''; ?>"
                    data-sku-desc="<?php echo htmlspecialchars($d['sku_desc'] ?? ''); ?>"
                    data-mrp="<?php echo $mrp; ?>"
                    data-adj-good="<?php echo $adj_good; ?>"
                    data-adj-damage="<?php echo $adj_damage; ?>"
                    data-total-qty="<?php echo $total_qty; ?>"
                    data-is-prev-day="<?php echo $is_prev_day; ?>">
                    <td class="td-cb">
                        <input type="checkbox" class="row-cb row-select-cb"
                               data-id="<?php echo $d['id']; ?>"
                               data-person="<?php echo htmlspecialchars($d['delivery_person_name'] ?? ''); ?>"
                               data-sku="<?php echo htmlspecialchars($d['sku_code'] ?? ''); ?>"
                               data-sku-desc="<?php echo htmlspecialchars($d['sku_desc'] ?? ''); ?>"
                               data-tur="<?php echo $tur; ?>"
                               data-se="<?php echo $sv !== null ? $sv : ''; ?>"
                               onchange="onRowCbChange()">
                    </td>
                    <td><?php echo $rownum++; ?>
                        <?php if ($is_prev_day): ?><span class="prevday-badge"><i class="fa-solid fa-calendar-minus" style="font-size:8px;"></i> Prev</span><?php endif; ?>
                    </td>
                    <td data-val="<?php echo !empty($d['record_date']) ? date('Y-m-d', strtotime($d['record_date'])) : ''; ?>">
                        <?php echo !empty($d['record_date']) ? date('d M Y', strtotime($d['record_date'])) : '-'; ?>
                    </td>
                    <td><?php echo htmlspecialchars($d['delivery_person_name'] ?? ''); ?></td>
                    <td><strong><?php echo htmlspecialchars($d['sku_code'] ?? ''); ?></strong></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($d['sku_desc'] ?? ''); ?>">
                        <?php echo htmlspecialchars($d['sku_desc'] ?? ''); ?>
                    </td>
                    <td class="num" data-val="<?php echo $tur; ?>"><?php echo number_format($tur, 2); ?></td>
                    <td class="num" data-val="<?php echo $mrp; ?>"><?php echo number_format($mrp, 2); ?></td>
                    <td class="num" data-val="<?php echo $adj_good; ?>" style="color:#166634;font-weight:600;"><?php echo number_format($adj_good, 2); ?></td>
                    <td class="num" data-val="<?php echo $adj_damage; ?>" style="color:#991b1b;font-weight:600;"><?php echo number_format($adj_damage, 2); ?></td>
                    <td class="num" data-val="<?php echo $total_qty; ?>" style="font-weight:700;"><?php echo number_format($total_qty, 2); ?></td>

                    <td class="num">
                        <input type="number" step="0.01" min="0"
                            class="qty-input qty-actual<?php echo ($actual_val !== '') ? ' saved' : ''; ?>"
                            id="actual-<?php echo $d['id']; ?>"
                            data-id="<?php echo $d['id']; ?>"
                            data-total="<?php echo $total_qty; ?>"
                            data-adj-good="<?php echo $adj_good; ?>"
                            data-adj-damage="<?php echo $adj_damage; ?>"
                            data-tur="<?php echo $tur; ?>"
                            value="<?php echo $actual_val; ?>"
                            placeholder="0.00"
                            oninput="recalcRow(<?php echo $d['id']; ?>)">
                    </td>

                    <td class="num">
                        <input type="number" step="0.01" min="0"
                            class="qty-input qty-dmg qty-damage<?php echo ($actual_dmg_val !== '') ? ' saved' : ''; ?>"
                            id="actdmg-<?php echo $d['id']; ?>"
                            data-id="<?php echo $d['id']; ?>"
                            value="<?php echo $actual_dmg_val; ?>"
                            placeholder="0.00"
                            oninput="recalcRow(<?php echo $d['id']; ?>)">
                    </td>

                    <td class="num" id="goodval-<?php echo $d['id']; ?>">
                        <?php if ($good_value !== null): ?><span class="val-good"><?php echo number_format($good_value, 2); ?></span>
                        <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
                    </td>

                    <td class="num" id="dmgval-<?php echo $d['id']; ?>">
                        <?php if ($dmg_value !== null): ?><span class="val-dmg-amount"><?php echo number_format($dmg_value, 2); ?></span>
                        <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
                    </td>

                    <td class="num"><span class="se-value <?php echo $se_class; ?>" id="se-<?php echo $d['id']; ?>"><?php echo $se_display; ?></span></td>

                    <td class="num" id="seval-<?php echo $d['id']; ?>">
                        <?php if ($se_val_amt !== null): ?><span style="color:#374151;font-weight:600;"><?php echo number_format($se_val_amt, 2); ?></span>
                        <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
                    </td>

                    <td class="num" id="charge-<?php echo $d['id']; ?>">
                        <?php if ($s_charge !== null && $s_charge > 0): ?>
                            <span class="val-charge"><?php echo number_format($s_charge, 2); ?></span>
                        <?php elseif ($sv !== null && $sv < 0 && $tur > 0): ?>
                            <span class="val-charge"><?php echo number_format($tur * abs($sv), 2); ?></span>
                        <?php else: ?>
                            <span style="color:#d1d5db;">—</span>
                        <?php endif; ?>
                    </td>

                    <?php /* FIX: Absorb column now shows a value ONLY when a real absorb amount was saved.
                             The old code previewed the excess value here, which wrongly reported money as
                             "Absorbed by Company" even when nothing was absorbed. */ ?>
                    <td class="num" id="absorb-<?php echo $d['id']; ?>">
                        <?php if ($s_absorb !== null && $s_absorb > 0): ?>
                            <span class="val-absorb"><?php echo number_format($s_absorb, 2); ?></span>
                        <?php else: ?>
                            <span style="color:#d1d5db;">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="num" id="variance-<?php echo $d['id']; ?>">
                        <?php if ($vc): ?><span class="<?php echo $vc; ?>"><?php echo $vd; ?></span>
                        <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
                    </td>
                    <td style="text-align:center;white-space:nowrap;">
                        <div style="display:flex;gap:4px;justify-content:center;">
                            <button class="btn btn-save-row btn-xs" id="btn-<?php echo $d['id']; ?>" onclick="saveRow(<?php echo $d['id']; ?>)"><i class="fa-solid fa-floppy-disk"></i> Save</button>
                            <button class="<?php echo $paybtn_class; ?>" id="paybtn-<?php echo $d['id']; ?>" onclick="openRowPayModal(<?php echo $d['id']; ?>)"><?php echo $paybtn_label; ?></button>
                            <button class="btn btn-del-row btn-xs" onclick="confirmDeleteRow(<?php echo $d['id']; ?>, '<?php echo addslashes($d['sku_code'] ?? ''); ?>')" title="Delete Row"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </td>
                </tr>
            <?php endwhile; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px;padding-top:16px;border-top:1px solid #e5e5e5;">
        <span style="font-size:13px;color:#6b7280;" id="bottomCount"></span>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-excel" onclick="exportToExcel()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
            <button class="btn btn-reimport" onclick="openReImportModal()"><i class="fa-solid fa-rotate"></i> Re-Import Excel</button>
            <button class="btn btn-add" onclick="openAddItemModal()"><i class="fa-solid fa-plus"></i> Add Item</button>
            <button class="btn btn-prevday" onclick="openPrevDayModal()"><i class="fa-solid fa-calendar-minus"></i> Add Previous Day Item</button>
            <button class="btn btn-autofill" onclick="autoFillActual()"><i class="fa-solid fa-wand-magic-sparkles"></i> Auto-fill Actual</button>
            <button class="btn btn-success" onclick="saveAll()"><i class="fa-solid fa-floppy-disk"></i> Save All</button>
        </div>
    </div>
</div>
<!-- INIT MODAL -->
<div class="modal-overlay" id="initModal">
    <div class="modal-box">
        <div class="init-modal-body">
            <div class="init-icon"><i class="fa-solid fa-database"></i></div>
            <div class="init-title">Initialize Unloading Data</div>
            <div class="init-desc">
                Do you want to copy the imported data to the Unloading Data table?<br>
                This will create a working copy for editing (rows with zero quantities will be skipped).
            </div>
            <div style="display:flex;gap:12px;justify-content:center;">
                <button class="btn btn-success" onclick="initData()" id="initBtn"><i class="fa-solid fa-check"></i> Yes, Initialize Data</button>
                <a href="gse_list.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Go Back</a>
            </div>
        </div>
    </div>
</div>

<!-- ADD ITEM MODAL -->
<div class="modal-overlay" id="addItemModal" onclick="if(event.target===this)closeAddItemModal()">
    <div class="modal-box" style="max-width:560px;">
        <div class="modal-title"><i class="fa-solid fa-plus-circle" style="color:#7c3aed;"></i> Add New Item</div>
        <div class="modal-sub">Add a new line item to this import</div>
        <div class="form-group">
            <label class="form-label">SKU Code</label>
            <select id="addSku" class="form-input" style="width:100%;"><option value="">— Search & Select SKU —</option></select>
            <div class="price-hint" id="skuPriceHint"><i class="fa-solid fa-tag"></i><span id="skuPriceHintText"></span></div>
        </div>
        <div class="form-group">
            <label class="form-label">SKU Description</label>
            <input type="text" class="form-input" id="addSkuDesc" placeholder="Auto-filled from SKU" readonly style="background:#f9fafb;">
        </div>
        <div class="form-group">
            <label class="form-label">Delivery Person</label>
            <select id="addPerson" class="form-input">
                <option value="">— Select —</option>
                <?php foreach ($delivery_persons as $dp): ?>
                    <option value="<?php echo htmlspecialchars($dp); ?>" <?php echo ($person_filter === $dp) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dp); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Delivery Date</label>
            <input type="date" class="form-input" id="addDeliveryDate" value="<?php echo $delivery_date_display; ?>">
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">TUR</label><input type="number" step="0.01" min="0" class="form-input" id="addTur" placeholder="0.00"></div>
            <div class="form-group"><label class="form-label">MRP</label><input type="number" step="0.01" min="0" class="form-input" id="addMrp" placeholder="0.00"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Actual Qty</label><input type="number" step="0.01" min="0" class="form-input" id="addActualQty" placeholder="0.00"></div>
            <div class="form-group"><label class="form-label">Actual Damage Qty</label><input type="number" step="0.01" min="0" class="form-input" id="addActualDmgQty" placeholder="0.00" value="0"></div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeAddItemModal()">Cancel</button>
            <button class="btn btn-success" onclick="saveNewItem()" id="addItemBtn"><i class="fa-solid fa-plus"></i> Add Item</button>
        </div>
    </div>
</div>

<!-- ADD PREVIOUS DAY ITEM MODAL -->
<div class="modal-overlay" id="prevDayModal" onclick="if(event.target===this)closePrevDayModal()">
    <div class="modal-box" style="max-width:560px;">
        <div class="modal-title"><i class="fa-solid fa-calendar-minus" style="color:#854d0e;"></i> Add Previous Day Item</div>
        <div class="modal-sub" style="background:#fefce8;border:1px solid #fcd34d;border-radius:6px;padding:8px 12px;color:#854d0e;font-size:12px;">
            <i class="fa-solid fa-circle-info"></i>&nbsp;This row will be saved and shown at the <strong>bottom of the table</strong> with a pale yellow background to distinguish it from current-day items.
        </div>
        <div class="form-group" style="margin-top:14px;">
            <label class="form-label">SKU Code</label>
            <select id="prevSku" class="form-input" style="width:100%;"><option value="">— Search & Select SKU —</option></select>
            <div class="price-hint" id="prevSkuPriceHint"><i class="fa-solid fa-tag"></i><span id="prevSkuPriceHintText"></span></div>
        </div>
        <div class="form-group">
            <label class="form-label">SKU Description</label>
            <input type="text" class="form-input" id="prevSkuDesc" placeholder="Auto-filled from SKU" readonly style="background:#f9fafb;">
        </div>
        <div class="form-group">
            <label class="form-label">Delivery Person</label>
            <select id="prevPerson" class="form-input">
                <option value="">— Select —</option>
                <?php foreach ($delivery_persons as $dp): ?>
                    <option value="<?php echo htmlspecialchars($dp); ?>" <?php echo ($person_filter === $dp) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dp); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Previous Day Date</label><input type="date" class="form-input" id="prevDeliveryDate" value="<?php echo date('Y-m-d', strtotime('-1 day')); ?>"></div>
            <div class="form-group"><label class="form-label">Record Date</label><input type="date" class="form-input" id="prevRecordDate" value="<?php echo date('Y-m-d', strtotime('-1 day')); ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">TUR</label><input type="number" step="0.01" min="0" class="form-input" id="prevTur" placeholder="0.00"></div>
            <div class="form-group"><label class="form-label">MRP</label><input type="number" step="0.01" min="0" class="form-input" id="prevMrp" placeholder="0.00"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Actual Qty</label><input type="number" step="0.01" min="0" class="form-input" id="prevActualQty" placeholder="0.00"></div>
            <div class="form-group"><label class="form-label">Actual Damage Qty</label><input type="number" step="0.01" min="0" class="form-input" id="prevActualDmgQty" placeholder="0.00" value="0"></div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closePrevDayModal()">Cancel</button>
            <button class="btn" style="background:#854d0e;color:#fef9c3;" onclick="savePrevDayItem()" id="prevDayBtn"><i class="fa-solid fa-calendar-plus"></i> Add Previous Day Item</button>
        </div>
    </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div class="modal-overlay" id="deleteModal" onclick="if(event.target===this)closeDeleteModal()">
    <div class="del-modal-box">
        <div class="del-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div class="del-title">Delete Row?</div>
        <div class="del-desc" id="delDesc">Are you sure you want to delete this row? This action cannot be undone.</div>
        <div style="display:flex;gap:10px;justify-content:center;">
            <button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
            <button class="btn btn-danger" id="confirmDelBtn" onclick="executeDeleteRow()"><i class="fa-solid fa-trash"></i> Delete</button>
        </div>
    </div>
</div>

<!-- RE-IMPORT EXCEL MODAL -->
<div class="modal-overlay" id="reImportModal" onclick="if(event.target===this)closeReImportModal()">
    <div class="modal-box" style="max-width:560px;">
        <div class="modal-title" style="color:#b45309;"><i class="fa-solid fa-rotate" style="color:#b45309;"></i> Re-Import Excel</div>
        <div class="modal-sub">Upload a new Excel file to replace the import data for this record.<br><strong style="color:#dc2626;">What will happen:</strong></div>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;margin-bottom:16px;font-size:13px;line-height:2;">
            <div><i class="fa-solid fa-archive" style="color:#b45309;width:18px;"></i> Old import detail rows are <strong>archived as a log</strong></div>
            <div><i class="fa-solid fa-trash" style="color:#dc2626;width:18px;"></i> Old <code>import_details</code> are <strong>deleted</strong> and replaced</div>
            <div><i class="fa-solid fa-pen-to-square" style="color:#2563eb;width:18px;"></i> Existing <code>unloading_data</code> rows are <strong>updated</strong> — actual qty / pay entries are preserved</div>
            <div><i class="fa-solid fa-plus-circle" style="color:#16a34a;width:18px;"></i> Brand-new SKU/person rows are <strong>added</strong></div>
            <div><i class="fa-solid fa-lock" style="color:#7c3aed;width:18px;"></i> Rows removed from Excel <strong>remain</strong> unchanged</div>
        </div>
        <div class="form-group">
            <label class="form-label">Select Excel File (.xlsx / .xls)</label>
            <input type="file" id="reImportFile" accept=".xlsx,.xls" class="form-input" style="padding:7px 10px;" onchange="onReImportFileChange()">
            <div id="reImportFileInfo" style="margin-top:6px;font-size:12px;color:#6b7280;"></div>
        </div>
        <div class="form-group">
            <label class="form-label">Delivery Date (optional override)</label>
            <input type="date" id="reImportDeliveryDate" class="form-input" value="<?php echo $delivery_date_display; ?>">
        </div>
        <div id="reImportProgress" style="display:none;margin-bottom:12px;">
            <div style="font-size:12px;color:#6b7280;margin-bottom:4px;" id="reImportProgressLabel">Reading file…</div>
            <div style="background:#e5e5e5;border-radius:4px;height:8px;overflow:hidden;"><div id="reImportProgressBar" style="height:100%;background:#b45309;width:0%;transition:width .3s;border-radius:4px;"></div></div>
        </div>
        <div id="reImportResult" style="display:none;"></div>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeReImportModal()" id="reImportCancelBtn">Cancel</button>
            <button class="btn btn-reimport" onclick="runReImport()" id="reImportRunBtn" disabled><i class="fa-solid fa-rotate"></i> Re-Import Now</button>
        </div>
    </div>
</div>

<!-- PER-ROW PAY MODAL -->
<div class="modal-overlay" id="rowPayModal" onclick="if(event.target===this)closeRowPayModal()">
    <div class="pay-modal-box">
        <div class="pmo-header">
            <div>
                <div class="pmo-title" id="rp_modal_title"><i class="fa-solid fa-file-invoice-dollar" style="color:#7c3aed;"></i> Pay Allocation</div>
                <div style="font-size:12px;color:#9ca3af;margin-top:3px;" id="rp_sub">—</div>
            </div>
            <button class="pmo-close" onclick="closeRowPayModal()">×</button>
        </div>

        <div class="pmo-sku-strip">
            <div class="pmo-sku-cell"><div class="pmo-sku-lbl">SKU Code</div><div class="pmo-sku-val mono" id="rp_sku">—</div></div>
            <div class="pmo-sku-cell" style="grid-column:span 2 / span 2;min-width:0;"><div class="pmo-sku-lbl">Description</div><div class="pmo-sku-val" id="rp_sku_desc" style="white-space:normal;font-size:12px;line-height:1.4;">—</div></div>
            <div class="pmo-sku-cell"><div class="pmo-sku-lbl">TUR Price</div><div class="pmo-sku-val mono" id="rp_tur">—</div></div>
        </div>

        <div style="display:flex;flex-wrap:wrap;background:#fffbeb;border-bottom:1px solid #fde68a;flex-shrink:0;">
            <div style="padding:6px 14px;border-right:1px solid #fde68a;"><div class="pmo-sku-lbl" style="color:#92400e;">Adj Good</div><div class="pmo-sku-val mono" id="rp_adj_good">—</div></div>
            <div style="padding:6px 14px;border-right:1px solid #fde68a;"><div class="pmo-sku-lbl" style="color:#92400e;">Adj Dmg</div><div class="pmo-sku-val mono" id="rp_adj_damage">—</div></div>
            <div style="padding:6px 14px;border-right:1px solid #fde68a;"><div class="pmo-sku-lbl" style="color:#92400e;">Total Exp.</div><div class="pmo-sku-val mono" id="rp_total_qty">—</div></div>
            <div style="padding:6px 14px;"><div class="pmo-sku-lbl" id="rp_seval_strip_lbl" style="color:#991b1b;">S/E Value</div><div class="pmo-sku-val mono" id="rp_seval_strip">—</div></div>
        </div>

        <div class="pmo-info">
            <div class="pmo-info-cell"><div class="pmo-info-lbl">Delivery Person</div><div class="pmo-info-val" id="rp_person">—</div></div>
            <div class="pmo-info-cell hi-short" id="rp_seval_card"><div class="pmo-info-lbl" id="rp_seval_lbl">S/E Value (TUR × |S/E Qty|)</div><div class="pmo-info-val" id="rp_seval">—</div></div>
        </div>

        <div class="pmo-body">
            <div id="rp_se_banner" class="se-type-banner" style="display:none;"></div>

            <div style="display:none;">
                <select id="rp_payroll_period">
                    <option value="">— No Period —</option>
                    <?php foreach ($payroll_periods_all as $pp): ?>
                    <option value="<?php echo $pp['id']; ?>" data-year="<?php echo $pp['year']; ?>" data-month="<?php echo $pp['month']; ?>" data-status="<?php echo $pp['status']; ?>" data-open="<?php echo $pp['open_date']; ?>" data-close="<?php echo $pp['close_date']; ?>" <?php echo ($active_period && $pp['id'] == $active_period['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($pp['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" id="rp_charge_date" value="<?php echo date('Y-m-d'); ?>">
            </div>

            <div class="pmo-section">
                <div class="pmo-sec-head red" id="rp_charge_sec_head">
                    <span id="rp_charge_sec_label"><i class="fa-solid fa-user-minus"></i>&nbsp; Charge to Employee</span>
                    <span class="pmo-sec-total" id="rp_charge_total">0.00</span>
                </div>
                <div class="pmo-sec-body" style="padding:10px 12px;">
                    <div class="charge-table-wrap" id="rp_charge_table_wrap">
                        <table class="charge-tbl" id="chargeTable">
                            <thead>
                                <tr>
                                    <th style="min-width:160px;">Employee Name</th>
                                    <th style="min-width:90px;">SKU Code</th>
                                    <th style="min-width:130px;">SKU Description</th>
                                    <th class="cnum" style="min-width:70px;">Qty</th>
                                    <th class="cnum" style="min-width:75px;">Price (TUR)</th>
                                    <th style="min-width:120px;">Payroll Month</th>
                                    <th style="min-width:110px;">Pay Date</th>
                                    <th class="cnum" style="min-width:100px;" id="rp_charge_amt_col_hdr">Charge Amount</th>
                                    <th style="width:36px;"></th>
                                </tr>
                            </thead>
                            <tbody id="chargeRows"></tbody>
                        </table>
                    </div>
                    <button class="add-emp-btn" id="rp_add_emp_btn" onclick="addEmpRow()"><i class="fa-solid fa-plus"></i> Add Employee Row</button>
                </div>
            </div>

            <div class="pmo-section">
                <div class="pmo-sec-head blue" id="rp_absorb_sec_head">
                    <span id="rp_absorb_sec_label"><i class="fa-solid fa-building"></i>&nbsp; Absorb by Company</span>
                    <span class="pmo-sec-total" id="rp_absorb_total">0.00</span>
                </div>
                <div class="pmo-sec-body">
                    <div class="absorb-row">
                        <label class="absorb-lbl" id="rp_absorb_row_lbl"><i class="fa-solid fa-building" style="color:#1e40af;"></i> Company Absorption Amount</label>
                        <input type="number" step="0.01" min="0" class="absorb-inp" id="rp_absorb_amt" placeholder="0.00" oninput="recalcPay()">
                    </div>
                </div>
            </div>

            <div class="pmo-totals">
                <div class="ptb-cell"><div class="ptb-lbl" id="ptb_seval_lbl">S/E Value</div><div class="ptb-val red" id="ptb_seval">0.00</div></div>
                <div class="ptb-cell"><div class="ptb-lbl">Charged + Absorbed</div><div class="ptb-val blue" id="ptb_alloc">0.00</div></div>
                <div class="ptb-cell"><div class="ptb-lbl">Variance</div><div class="ptb-val orange" id="ptb_var">0.00</div></div>
            </div>
            <div style="font-size:11px;color:#9ca3af;text-align:center;margin-top:6px;" id="rp_variance_note">Variance = S/E Value − (Charge + Absorb)</div>

            <button class="pmo-save" id="savePayBtn" onclick="savePayAllocation()"><i class="fa-solid fa-floppy-disk"></i> Save Pay Allocation</button>
        </div>
    </div>
</div>

<!-- BULK PAY MODAL -->
<div class="modal-overlay" id="bulkPayModal" onclick="if(event.target===this)closeBulkPayModal()">
    <div class="bulk-pay-modal-box">
        <div class="pmo-header">
            <div>
                <div class="pmo-title"><i class="fa-solid fa-layer-group" style="color:#7c3aed;"></i> Bulk Pay Allocation</div>
                <div style="font-size:12px;color:#9ca3af;margin-top:3px;" id="bp_sub">— rows selected —</div>
            </div>
            <button class="pmo-close" onclick="closeBulkPayModal()">×</button>
        </div>
        <div style="padding:12px 16px 0;flex-shrink:0;">
            <div style="font-size:11px;font-weight:700;color:#6d28d9;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;"><i class="fa-solid fa-list-check"></i> Selected Rows</div>
            <div class="bulk-rows-summary">
                <table class="bulk-rows-tbl">
                    <thead><tr><th>Person</th><th>SKU</th><th>Description</th><th class="rnum">TUR</th><th class="rnum">S/E Qty</th><th class="rnum">S/E Value</th></tr></thead>
                    <tbody id="bp_rows_body"></tbody>
                </table>
            </div>
            <div class="bulk-total-bar">
                <div class="btb-cell"><div class="btb-lbl">Rows Selected</div><div class="btb-val" id="bp_row_count">0</div></div>
                <div class="btb-cell"><div class="btb-lbl">Total S/E Value</div><div class="btb-val" style="color:#dc2626;" id="bp_total_seval">0.00</div></div>
                <div class="btb-cell"><div class="btb-lbl">Total S/E Qty</div><div class="btb-val" id="bp_total_seqty">0.00</div></div>
            </div>
        </div>
        <div class="pmo-body" style="padding-top:8px;">
            <div style="display:none;">
                <select id="bp_payroll_period">
                    <option value="">— No Period —</option>
                    <?php foreach ($payroll_periods_all as $pp): ?>
                    <option value="<?php echo $pp['id']; ?>" data-year="<?php echo $pp['year']; ?>" data-month="<?php echo $pp['month']; ?>" data-status="<?php echo $pp['status']; ?>" data-open="<?php echo $pp['open_date']; ?>" data-close="<?php echo $pp['close_date']; ?>" <?php echo ($active_period && $pp['id'] == $active_period['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($pp['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" id="bp_charge_date" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="pmo-section">
                <div class="pmo-sec-head red">
                    <span><i class="fa-solid fa-user-minus"></i>&nbsp; Charge to Employee</span>
                    <span class="pmo-sec-total" id="bp_charge_total">0.00</span>
                </div>
                <div class="pmo-sec-body" style="padding:10px 12px;">
                    <div class="charge-table-wrap">
                        <table class="charge-tbl" id="bp_chargeTable">
                            <thead><tr>
                                <th style="min-width:160px;">Employee Name</th>
                                <th class="cnum" style="min-width:70px;">Qty</th>
                                <th class="cnum" style="min-width:75px;">Price (TUR)</th>
                                <th style="min-width:120px;">Payroll Month</th>
                                <th style="min-width:110px;">Pay Date</th>
                                <th class="cnum" style="min-width:100px;">Charge Amount</th>
                                <th style="width:36px;"></th>
                            </tr></thead>
                            <tbody id="bp_chargeRows"></tbody>
                        </table>
                    </div>
                    <button class="add-emp-btn" onclick="bpAddEmpRow()"><i class="fa-solid fa-plus"></i> Add Employee Row</button>
                </div>
            </div>
            <div class="pmo-section">
                <div class="pmo-sec-head blue">
                    <span><i class="fa-solid fa-building"></i>&nbsp; Absorb by Company</span>
                    <span class="pmo-sec-total" id="bp_absorb_total">0.00</span>
                </div>
                <div class="pmo-sec-body">
                    <div class="absorb-row">
                        <label class="absorb-lbl"><i class="fa-solid fa-building" style="color:#1e40af;"></i> Company Absorption Amount</label>
                        <input type="number" step="0.01" min="0" class="absorb-inp" id="bp_absorb_amt" placeholder="0.00" oninput="bpRecalcPay()">
                    </div>
                </div>
            </div>
            <div class="pmo-totals">
                <div class="ptb-cell"><div class="ptb-lbl">Total S/E Value</div><div class="ptb-val red" id="bp_ptb_seval">0.00</div></div>
                <div class="ptb-cell"><div class="ptb-lbl">Charged + Absorbed</div><div class="ptb-val blue" id="bp_ptb_alloc">0.00</div></div>
                <div class="ptb-cell"><div class="ptb-lbl">Variance</div><div class="ptb-val orange" id="bp_ptb_var">0.00</div></div>
            </div>
            <div style="font-size:11px;color:#9ca3af;text-align:center;margin-top:6px;">Variance = Total S/E Value − (Charge + Absorb) · Allocation is split proportionally per row by S/E value</div>
            <button class="pmo-save" id="bp_saveBtn" onclick="saveBulkPayAllocation()"><i class="fa-solid fa-floppy-disk"></i> Save Bulk Pay Allocation</button>
        </div>
    </div>
</div>

<div id="toast"></div>
<script>
const IMPORT_ID     = <?php echo $import_id; ?>;
const DATA_INIT     = <?php echo $data_initialized ? 'true' : 'false'; ?>;
const DELIVERY_DATE = '<?php echo $delivery_date_display; ?>';
const PERSON_FILTER = '<?php echo addslashes($person_filter); ?>';

const EMPLOYEES = <?php echo json_encode(array_map(function($e){
    return ['id'=>$e['id'],'employee_id'=>$e['employee_id'],'label'=>$e['employee_id'].' – '.$e['employee_full_name'],'name'=>$e['employee_full_name']];
}, $employees_list)); ?>;

const PAYROLL_PERIODS = <?php echo json_encode(array_map(function($pp) use ($MN){
    return ['id'=>intval($pp['id']),'label'=>$MN[$pp['month']].' '.$pp['year'].' ['.$pp['status'].']','year'=>intval($pp['year']),'month'=>intval($pp['month']),'month_name'=>$MN[$pp['month']],'status'=>$pp['status'],'open_date'=>$pp['open_date'],'close_date'=>$pp['close_date']];
}, $payroll_periods_all)); ?>;

const SKU_PRICES    = <?php echo json_encode($sku_prices); ?>;
const ACTIVE_PERIOD = <?php echo json_encode($ap_js); ?>;
const MN = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

let activeFilter      = 'all';
let currentPayRowId   = null;
let empRowCount       = 0;
let itemsList         = [];
let deleteRowId        = null;
let currentSeValue    = 0;
let currentPaySku     = '';
let currentPaySkuDesc = '';
let currentPayTur     = 0;
let currentSeQty      = 0;
let currentSeDirection = 0;

let bpEmpRowCount  = 0;
let bpTotalSeValue = 0;
let bpTotalSeQty   = 0;
let bpSelectedRows = [];

$(document).ready(function() {
    $('#personFilter').select2({placeholder:'All Delivery Persons',allowClear:true,width:'100%'})
    .on('change', function() {
        const val = $(this).val() || '';
        window.location.href = val ? '?id='+IMPORT_ID+'&person='+encodeURIComponent(val) : '?id='+IMPORT_ID;
    });
    $('#rp_payroll_period').on('change', function() {
        const opt = $(this).find(':selected');
        const od=opt.data('open'), cd=opt.data('close');
        const di=document.getElementById('rp_charge_date');
        if (od&&cd){di.min=od;di.max=cd;if(!di.value||di.value<od||di.value>cd)di.value=cd;}
        else{di.min='';di.max='';}
    });
});

document.addEventListener('DOMContentLoaded', function() {
    if (!DATA_INIT) { document.getElementById('initModal').classList.add('open'); }
    else { applyFilters(); }
    loadItemsList();
});

function initData() {
    const btn = document.getElementById('initBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Initializing...';
    fetch('api_unloading_data.php?action=init',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({import_id:IMPORT_ID})})
    .then(r=>r.json()).then(res=>{
        if(res.success){showToast('Data initialized! '+(res.count||0)+' records copied.','success');setTimeout(()=>location.reload(),800);}
        else{btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-check"></i> Yes, Initialize Data';showToast('Error: '+res.message,'error');}
    }).catch(()=>{btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-check"></i> Yes, Initialize Data';showToast('Network error','error');});
}

function calculateTotals() {
    let totalGood = 0, totalDmg = 0, totalSE = 0, totalSEVal = 0;
    let totalCharge = 0, totalAbsorb = 0, totalVar = 0;
    let hasCharge = false, hasAbsorb = false, hasVar = false;
    let sumAdjGood = 0, sumAdjDmg = 0, sumTotalQty = 0, sumActual = 0, sumActualDmg = 0;

    document.querySelectorAll('#tableBody tr:not(.hidden-row)').forEach(tr => {
        if (!tr.id || !tr.id.startsWith('row-')) return;
        const rid = tr.id.replace('row-', '');
        sumAdjGood  += parseFloat(tr.dataset.adjGood)   || 0;
        sumAdjDmg   += parseFloat(tr.dataset.adjDamage) || 0;
        sumTotalQty += parseFloat(tr.dataset.totalQty)  || 0;
        const actIn = document.getElementById('actual-' + rid);
        const dmgIn = document.getElementById('actdmg-' + rid);
        if (actIn && actIn.value !== '') sumActual    += parseFloat(actIn.value) || 0;
        if (dmgIn && dmgIn.value !== '') sumActualDmg += parseFloat(dmgIn.value) || 0;
        const goodSpan = document.querySelector('#goodval-' + rid + ' .val-good');
        if (goodSpan) totalGood += parseFloat(String(goodSpan.textContent).replace(/,/g, '')) || 0;
        const dmgSpan = document.querySelector('#dmgval-' + rid + ' .val-dmg-amount');
        if (dmgSpan) totalDmg += parseFloat(String(dmgSpan.textContent).replace(/,/g, '')) || 0;
        const se = (tr.dataset.se !== undefined && tr.dataset.se !== '') ? parseFloat(tr.dataset.se) : null;
        if (se !== null && !isNaN(se)) totalSE += se;
        const sevalCell = document.getElementById('seval-' + rid);
        const sevalSpan = sevalCell ? sevalCell.querySelector('span') : null;
        if (sevalSpan) totalSEVal += parseFloat(String(sevalSpan.textContent).replace(/,/g, '')) || 0;
        const chargeSpan = document.querySelector('#charge-' + rid + ' .val-charge');
        if (chargeSpan) { totalCharge += parseFloat(String(chargeSpan.textContent).replace(/,/g, '')) || 0; hasCharge = true; }
        const absorbSpan = document.querySelector('#absorb-' + rid + ' .val-absorb');
        if (absorbSpan) { totalAbsorb += parseFloat(String(absorbSpan.textContent).replace(/,/g, '')) || 0; hasAbsorb = true; }
        const varCell = document.getElementById('variance-' + rid);
        const varSpan = varCell ? varCell.querySelector('span') : null;
        if (varSpan) { totalVar += parseFloat(String(varSpan.textContent).replace(/[+,]/g, '')) || 0; hasVar = true; }
    });

    const setVal = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    setVal('ts_good', totalGood.toFixed(2));
    setVal('ts_dmg', totalDmg.toFixed(2));
    const seEl = document.getElementById('ts_se');
    if (seEl) { seEl.textContent = (totalSE > 0 ? '+' : '') + totalSE.toFixed(2); seEl.className = 'ts-val ' + (totalSE < 0 ? 'ts-red' : totalSE > 0 ? 'ts-green' : ''); }
    setVal('ts_seval', totalSEVal.toFixed(2));
    setVal('ts_charge', hasCharge ? totalCharge.toFixed(2) : '—');
    setVal('ts_absorb', hasAbsorb ? totalAbsorb.toFixed(2) : '—');
    const varEl = document.getElementById('ts_var');
    if (varEl) {
        if (hasVar) { varEl.textContent = (totalVar > 0 ? '+' : '') + totalVar.toFixed(2); varEl.className = 'ts-val ' + (totalVar < 0 ? 'ts-red' : totalVar > 0 ? 'ts-green' : ''); }
        else { varEl.textContent = '0.00'; varEl.className = 'ts-val'; }
    }
    setVal('th_adjgood', sumAdjGood.toFixed(2));
    setVal('th_adjdmg', sumAdjDmg.toFixed(2));
    setVal('th_totalqty', sumTotalQty.toFixed(2));
    setVal('th_actual', sumActual.toFixed(2));
    setVal('th_actualdmg', sumActualDmg.toFixed(2));
    setVal('th_good', totalGood.toFixed(2));
    setVal('th_dmg', totalDmg.toFixed(2));
    const thSe = document.getElementById('th_se');
    if (thSe) { thSe.textContent = (totalSE > 0 ? '+' : '') + totalSE.toFixed(2); thSe.className = 'num ' + (totalSE < 0 ? 'th-red' : totalSE > 0 ? 'th-green' : ''); }
    setVal('th_seval', totalSEVal.toFixed(2));
    setVal('th_charge', hasCharge ? totalCharge.toFixed(2) : '—');
    setVal('th_absorb', hasAbsorb ? totalAbsorb.toFixed(2) : '—');
    const thVar = document.getElementById('th_var');
    if (thVar) {
        if (hasVar) { thVar.textContent = (totalVar > 0 ? '+' : '') + totalVar.toFixed(2); thVar.className = 'num ' + (totalVar < 0 ? 'th-red' : totalVar > 0 ? 'th-green' : ''); }
        else { thVar.textContent = '0.00'; thVar.className = 'num'; }
    }
}

function recalcRow(id) {
    const actInput = document.getElementById('actual-'+id);
    const dmgInput = document.getElementById('actdmg-'+id);
    const adjGood  = parseFloat(actInput.dataset.adjGood)   || 0;
    const adjDmg   = parseFloat(actInput.dataset.adjDamage) || 0;
    const tur      = parseFloat(actInput.dataset.tur)       || 0;
    const row      = document.getElementById('row-'+id);
    const dash     = '<span style="color:#d1d5db;">—</span>';

    const actVal = parseFloat(actInput.value);
    const dmgVal = parseFloat(dmgInput.value) || 0;

    const goodValCell = document.getElementById('goodval-'+id);
    const dmgValCell  = document.getElementById('dmgval-'+id);

    if (!isNaN(actVal) && actVal !== '' && tur > 0) { goodValCell.innerHTML = `<span class="val-good">${f2(actVal * tur)}</span>`; }
    else { goodValCell.innerHTML = dash; }

    const dmgActVal = parseFloat(dmgInput.value);
    if (!isNaN(dmgActVal) && dmgInput.value !== '' && tur > 0) { dmgValCell.innerHTML = `<span class="val-dmg-amount">${f2(dmgActVal * tur)}</span>`; }
    else { dmgValCell.innerHTML = dash; }

    if (isNaN(actVal) || actInput.value === '') {
        document.getElementById('se-'+id).textContent = '-';
        document.getElementById('se-'+id).className   = 'se-value';
        ['seval-','charge-','absorb-','variance-'].forEach(p=>document.getElementById(p+id).innerHTML=dash);
        row.dataset.se = '';
        calculateTotals();
        return;
    }

    const totalExpected = adjGood + adjDmg;
    const totalActual   = actVal + dmgVal;
    const diff          = totalActual - totalExpected;
    const seAbs         = Math.abs(diff);
    const seAmt         = tur > 0 ? tur * seAbs : 0;
    const varAt         = tur > 0 ? tur * diff  : 0;

    const seEl = document.getElementById('se-'+id);
    seEl.className   = 'se-value '+(diff<0?'short':diff>0?'excess':'zero');
    seEl.textContent = (diff>0?'+':'')+diff.toFixed(2);
    row.dataset.se   = diff;

    document.getElementById('seval-'+id).innerHTML   = tur>0?`<span style="color:#374151;font-weight:600;">${f2(seAmt)}</span>`:dash;
    document.getElementById('charge-'+id).innerHTML  = (diff<0&&tur>0)?`<span class="val-charge">${f2(seAmt)}</span>`:dash;
    // FIX: never auto-preview Absorb by Company. It is filled only when a pay allocation is saved.
    document.getElementById('absorb-'+id).innerHTML  = dash;
    if (tur>0) {
        const vc=varAt<0?'short':varAt>0?'excess':'zero';
        document.getElementById('variance-'+id).innerHTML=`<span class="${vc}">${varAt>0?'+':''}${f2(varAt)}</span>`;
    } else {
        document.getElementById('variance-'+id).innerHTML=dash;
    }
    calculateTotals();
}

function f2(n){return parseFloat(n).toFixed(2);}

document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter') return;
    const el = document.activeElement;
    if (!el || !el.classList.contains('qty-input')) return;
    e.preventDefault();
    if (el.classList.contains('qty-actual')) {
        const tr=el.closest('tr'), dmg=tr?tr.querySelector('.qty-damage'):null;
        if(dmg)dmg.focus();
    } else if (el.classList.contains('qty-damage')) {
        const visibleRows=Array.from(document.querySelectorAll('#tableBody tr:not(.hidden-row)'));
        const currentRow=el.closest('tr');
        const idx=visibleRows.indexOf(currentRow);
        if(idx>=0&&idx<visibleRows.length-1){const nextAct=visibleRows[idx+1].querySelector('.qty-actual');if(nextAct)nextAct.focus();}
    }
});

function autoFillActual() {
    let count=0;
    document.querySelectorAll('.qty-actual').forEach(input=>{
        if(!input.value.trim()){input.value=(parseFloat(input.dataset.adjGood)||0).toFixed(2);input.classList.add('autofilled');count++;}
    });
    document.querySelectorAll('.qty-damage').forEach(input=>{
        if(!input.value.trim()){const row=input.closest('tr');const actIn=row.querySelector('.qty-actual');const adjDmg=parseFloat(actIn?.dataset.adjDamage)||0;input.value=adjDmg.toFixed(2);input.classList.add('autofilled');}
    });
    document.querySelectorAll('.qty-actual').forEach(input=>{recalcRow(input.dataset.id);});
    showToast(count===0?'All rows already have values.':`Auto-filled ${count} row(s).`,count===0?'error':'success');
}

function setFilter(f,btn){
    activeFilter=f;
    document.querySelectorAll('.flt').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    applyFilters();
}

function applyFilters() {
    const q=document.getElementById('searchBox').value.toLowerCase().trim();
    const rows=document.querySelectorAll('#tableBody tr');
    let vis=0;
    rows.forEach(row=>{
        if(!row.id)return;
        const mS=!q||(row.dataset.sku||'').includes(q)||(row.dataset.desc||'').includes(q)||(row.dataset.person||'').includes(q);
        const se=row.dataset.se!==''?parseFloat(row.dataset.se):null;
        let mF=true;
        if(activeFilter==='short') mF=se!==null&&se<0;
        if(activeFilter==='excess')mF=se!==null&&se>0;
        if(activeFilter==='both')  mF=se!==null&&se!==0;
        const show=mS&&mF;
        row.classList.toggle('hidden-row',!show);
        if(show)vis++;
    });
    document.getElementById('rowCount').textContent='('+vis+' rows)';
    document.getElementById('bottomCount').textContent='Showing '+vis+' of '+rows.length+' rows';
    calculateTotals();
}

function exportToExcel() {
    const headers=['#','Date','Delivery Person','SKU Code','SKU Description','TUR','MRP','Adj Qty (Good)','Adj Qty (Damage)','Total Qty','Actual Qty','Actual Dmg Qty','Good Value (Actual×TUR)','Damage Value (DmgActual×TUR)','Short / Excess','S/E Value','Charge to Employee','Absorb by Company','Variance'];
    const rows=[];
    document.querySelectorAll('#tableBody tr:not(.hidden-row)').forEach(tr=>{
        if(!tr.id || !tr.id.startsWith('row-'))return;
        const tds=tr.querySelectorAll('td');
        if(tds.length<21)return;
        const getNum=(cell)=>{
            const inp=cell.querySelector('input[type="number"]');
            if(inp)return inp.value!==''?parseFloat(inp.value):'';
            const txt=cell.textContent.trim().replace(/[+,]/g,'');
            const n=parseFloat(txt);return isNaN(n)?'':n;
        };
        const getText=(cell)=>cell.textContent.trim().replace(/\s+/g,' ');
        const getDataVal=(cell)=>{
            if(cell.dataset.val!==undefined&&cell.dataset.val!=='')return parseFloat(cell.dataset.val);
            const txt=cell.textContent.trim().replace(/[+,]/g,'');
            const n=parseFloat(txt);return isNaN(n)?'':n;
        };
        rows.push([
            getText(tds[1]).replace(/Prev/g,'').trim(),
            tds[2].dataset.val||getText(tds[2]),
            getText(tds[3]),
            getText(tds[4]),
            tds[5].getAttribute('title')||getText(tds[5]),
            getDataVal(tds[6]),
            getDataVal(tds[7]),
            getDataVal(tds[8]),
            getDataVal(tds[9]),
            getDataVal(tds[10]),
            getNum(tds[11]),
            getNum(tds[12]),
            getNum(tds[13]),
            getNum(tds[14]),
            getText(tds[15]),
            getNum(tds[16]),
            getNum(tds[17]),
            getNum(tds[18]),
            getNum(tds[19]),
        ]);
    });
    if(!rows.length){showToast('No visible rows to export.','error');return;}
    const wsData=[headers,...rows];
    const ws=XLSX.utils.aoa_to_sheet(wsData);
    ws['!cols']=[{wch:4},{wch:12},{wch:22},{wch:14},{wch:36},{wch:10},{wch:10},{wch:12},{wch:14},{wch:10},{wch:12},{wch:14},{wch:22},{wch:24},{wch:14},{wch:12},{wch:18},{wch:18},{wch:12}];
    const wb=XLSX.utils.book_new();
    const personLabel=PERSON_FILTER?`_${PERSON_FILTER.replace(/\s+/g,'_')}`:'';
    XLSX.utils.book_append_sheet(wb,ws,'Unloading Details');
    const filename=`Unloading_Import_${IMPORT_ID}${personLabel}_${new Date().toISOString().slice(0,10)}.xlsx`;
    XLSX.writeFile(wb,filename);
    showToast(`Exported ${rows.length} rows to Excel.`,'success');
}

function saveRow(id) {
    const actInput=document.getElementById('actual-'+id);
    const dmgInput=document.getElementById('actdmg-'+id);
    if(!actInput.value.trim()){showToast('Enter Actual Qty first.','error');actInput.focus();return;}
    const btn=document.getElementById('btn-'+id);
    const adjGood=parseFloat(actInput.dataset.adjGood)||0;
    const adjDmg=parseFloat(actInput.dataset.adjDamage)||0;
    const actual=parseFloat(actInput.value)||0;
    const actDmg=parseFloat(dmgInput.value)||0;
    const se=(actual+actDmg)-(adjGood+adjDmg);
    setBtn(btn,true,'<i class="fa-solid fa-spinner fa-spin"></i>');
    fetch('api_unloading_data.php?action=save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,actual_qty:actual,actual_damage_qty:actDmg,short_excess:se})})
    .then(r=>r.json()).then(res=>{
        setBtn(btn,false,'<i class="fa-solid fa-floppy-disk"></i> Save');
        if(res.success){actInput.classList.remove('autofilled');actInput.classList.add('saved');dmgInput.classList.remove('autofilled');dmgInput.classList.add('saved');showToast('Saved!','success');calculateTotals();}
        else showToast('Error: '+res.message,'error');
    }).catch(()=>{setBtn(btn,false,'<i class="fa-solid fa-floppy-disk"></i> Save');showToast('Network error','error');});
}

function saveAll() {
    const rows=[];
    document.querySelectorAll('.qty-actual').forEach(actIn=>{
        if(actIn.value.trim()){
            const id=parseInt(actIn.dataset.id);
            const adjGood=parseFloat(actIn.dataset.adjGood)||0;
            const adjDmg=parseFloat(actIn.dataset.adjDamage)||0;
            const actual=parseFloat(actIn.value)||0;
            const dmgIn=document.getElementById('actdmg-'+id);
            const actDmg=parseFloat(dmgIn?.value)||0;
            rows.push({id,actual_qty:actual,actual_damage_qty:actDmg,short_excess:(actual+actDmg)-(adjGood+adjDmg)});
        }
    });
    if(!rows.length){showToast('No values to save.','error');return;}
    document.querySelectorAll('.btn-save-row').forEach(b=>setBtn(b,true,'<i class="fa-solid fa-spinner fa-spin"></i>'));
    fetch('api_unloading_data.php?action=save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({bulk:rows})})
    .then(r=>r.json()).then(res=>{
        document.querySelectorAll('.btn-save-row').forEach(b=>setBtn(b,false,'<i class="fa-solid fa-floppy-disk"></i> Save'));
        if(res.success){document.querySelectorAll('.qty-input').forEach(i=>{if(i.value.trim()){i.classList.remove('autofilled');i.classList.add('saved');}});showToast(rows.length+' row(s) saved!','success');calculateTotals();}
        else showToast('Error: '+res.message,'error');
    }).catch(()=>{document.querySelectorAll('.btn-save-row').forEach(b=>setBtn(b,false,'<i class="fa-solid fa-floppy-disk"></i> Save'));showToast('Network error','error');});
}
function confirmDeleteRow(id,skuCode){
    deleteRowId=id;
    document.getElementById('delDesc').textContent=`Are you sure you want to delete SKU "${skuCode}" (ID: ${id})? This action cannot be undone.`;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal(){document.getElementById('deleteModal').classList.remove('open');deleteRowId=null;}
function executeDeleteRow(){
    if(!deleteRowId)return;
    const btn=document.getElementById('confirmDelBtn');
    setBtn(btn,true,'<i class="fa-solid fa-spinner fa-spin"></i> Deleting...');
    fetch('api_unloading_data.php?action=delete_row',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:deleteRowId})})
    .then(r=>r.json()).then(res=>{
        setBtn(btn,false,'<i class="fa-solid fa-trash"></i> Delete');
        if(res.success){const rowEl=document.getElementById('row-'+deleteRowId);if(rowEl)rowEl.remove();closeDeleteModal();applyFilters();showToast('Row deleted.','success');}
        else showToast('Error: '+res.message,'error');
    }).catch(()=>{setBtn(btn,false,'<i class="fa-solid fa-trash"></i> Delete');showToast('Network error','error');});
}

function loadItemsList(){
    fetch('api_unloading_data.php?action=get_items&import_id='+IMPORT_ID).then(r=>r.json()).then(res=>{if(res.success)itemsList=res.items;}).catch(()=>{});
}

function openAddItemModal(){
    document.getElementById('addSkuDesc').value='';document.getElementById('addTur').value='';document.getElementById('addMrp').value='';document.getElementById('addActualQty').value='';document.getElementById('addActualDmgQty').value='0';document.getElementById('skuPriceHint').classList.remove('show');
    if(PERSON_FILTER)document.getElementById('addPerson').value=PERSON_FILTER;
    const $sel=$('#addSku');
    $sel.empty().append('<option value="">— Search & Select SKU —</option>');
    itemsList.forEach(item=>{$sel.append(`<option value="${item.sku_code}" data-desc="${item.sku_desc||''}" data-tur="${item.tur||0}" data-mrp="${item.mrp||0}">${item.sku_code} — ${item.sku_desc||''}</option>`);});
    if(!$sel.data('select2')){$sel.select2({placeholder:'— Search & Select SKU —',allowClear:true,dropdownParent:$('#addItemModal')});}else{$sel.val('').trigger('change');}
    $sel.off('change').on('change',function(){
        const sku=$(this).val(),opt=$(this).find(':selected');
        document.getElementById('addSkuDesc').value=opt.data('desc')||'';
        let tur=parseFloat(opt.data('tur')||0),mrp=parseFloat(opt.data('mrp')||0);
        if(!tur&&!mrp&&sku&&SKU_PRICES[sku]){tur=SKU_PRICES[sku].tur||0;mrp=SKU_PRICES[sku].mrp||0;}
        document.getElementById('addTur').value=tur>0?tur.toFixed(2):'';document.getElementById('addMrp').value=mrp>0?mrp.toFixed(2):'';
        const hint=document.getElementById('skuPriceHint');
        if(tur>0||mrp>0){document.getElementById('skuPriceHintText').textContent=`Auto-filled — TUR: ${tur.toFixed(2)}, MRP: ${mrp.toFixed(2)}`;hint.classList.add('show');}else{hint.classList.remove('show');}
    });
    document.getElementById('addItemModal').classList.add('open');
}
function closeAddItemModal(){document.getElementById('addItemModal').classList.remove('open');}

function saveNewItem(){
    const sku=document.getElementById('addSku').value;
    if(!sku){showToast('Please select a SKU.','error');return;}
    const btn=document.getElementById('addItemBtn');btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Adding...';
    fetch('api_unloading_data.php?action=add_row',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({import_id:IMPORT_ID,sku_code:sku,sku_desc:document.getElementById('addSkuDesc').value,delivery_person_name:document.getElementById('addPerson').value,delivery_date:document.getElementById('addDeliveryDate').value,tur:parseFloat(document.getElementById('addTur').value)||0,mrp:parseFloat(document.getElementById('addMrp').value)||0,actual_qty:parseFloat(document.getElementById('addActualQty').value)||0,actual_damage_qty:parseFloat(document.getElementById('addActualDmgQty').value)||0})})
    .then(r=>r.json()).then(res=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-plus"></i> Add Item';
        if(res.success){showToast('Item added!','success');closeAddItemModal();setTimeout(()=>location.reload(),600);}
        else showToast('Error: '+res.message,'error');
    }).catch(()=>{btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-plus"></i> Add Item';showToast('Network error','error');});
}

function openPrevDayModal(){
    document.getElementById('prevSkuDesc').value='';document.getElementById('prevTur').value='';document.getElementById('prevMrp').value='';document.getElementById('prevActualQty').value='';document.getElementById('prevActualDmgQty').value='0';document.getElementById('prevSkuPriceHint').classList.remove('show');
    const yesterday=new Date();yesterday.setDate(yesterday.getDate()-1);
    const yStr=yesterday.toISOString().slice(0,10);
    document.getElementById('prevDeliveryDate').value=yStr;document.getElementById('prevRecordDate').value=yStr;
    if(PERSON_FILTER)document.getElementById('prevPerson').value=PERSON_FILTER;
    const $sel=$('#prevSku');
    $sel.empty().append('<option value="">— Search & Select SKU —</option>');
    itemsList.forEach(item=>{$sel.append(`<option value="${item.sku_code}" data-desc="${item.sku_desc||''}" data-tur="${item.tur||0}" data-mrp="${item.mrp||0}">${item.sku_code} — ${item.sku_desc||''}</option>`);});
    if(!$sel.data('select2')){$sel.select2({placeholder:'— Search & Select SKU —',allowClear:true,dropdownParent:$('#prevDayModal')});}else{$sel.val('').trigger('change');}
    $sel.off('change').on('change',function(){
        const sku=$(this).val(),opt=$(this).find(':selected');
        document.getElementById('prevSkuDesc').value=opt.data('desc')||'';
        let tur=parseFloat(opt.data('tur')||0),mrp=parseFloat(opt.data('mrp')||0);
        if(!tur&&!mrp&&sku&&SKU_PRICES[sku]){tur=SKU_PRICES[sku].tur||0;mrp=SKU_PRICES[sku].mrp||0;}
        document.getElementById('prevTur').value=tur>0?tur.toFixed(2):'';document.getElementById('prevMrp').value=mrp>0?mrp.toFixed(2):'';
        const hint=document.getElementById('prevSkuPriceHint');
        if(tur>0||mrp>0){document.getElementById('prevSkuPriceHintText').textContent=`Auto-filled — TUR: ${tur.toFixed(2)}, MRP: ${mrp.toFixed(2)}`;hint.classList.add('show');}else{hint.classList.remove('show');}
    });
    document.getElementById('prevDayModal').classList.add('open');
}
function closePrevDayModal(){document.getElementById('prevDayModal').classList.remove('open');}

function savePrevDayItem(){
    const sku=document.getElementById('prevSku').value;
    if(!sku){showToast('Please select a SKU.','error');return;}
    const btn=document.getElementById('prevDayBtn');btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Adding...';
    const tur=parseFloat(document.getElementById('prevTur').value)||0;
    const mrp=parseFloat(document.getElementById('prevMrp').value)||0;
    const actualQty=parseFloat(document.getElementById('prevActualQty').value)||0;
    const actDmgQty=parseFloat(document.getElementById('prevActualDmgQty').value)||0;
    const skuDesc=document.getElementById('prevSkuDesc').value;
    const person=document.getElementById('prevPerson').value;
    const delDate=document.getElementById('prevDeliveryDate').value;
    const recDate=document.getElementById('prevRecordDate').value;
    fetch('api_unloading_data.php?action=add_row',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({import_id:IMPORT_ID,sku_code:sku,sku_desc:skuDesc,delivery_person_name:person,delivery_date:delDate,record_date:recDate,tur,mrp,actual_qty:actualQty,actual_damage_qty:actDmgQty,is_prev_day:1})})
    .then(r=>r.json()).then(res=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-calendar-plus"></i> Add Previous Day Item';
        if(res.success){showToast('Previous day item added!','success');closePrevDayModal();appendPrevDayRow(res.id,{sku_code:sku,sku_desc:skuDesc,person,rec_date:recDate,tur,mrp,actual_qty:actualQty,actual_dmg_qty:actDmgQty});}
        else showToast('Error: '+res.message,'error');
    }).catch(()=>{btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-calendar-plus"></i> Add Previous Day Item';showToast('Network error','error');});
}

function appendPrevDayRow(id,d){
    const tbody=document.getElementById('tableBody');
    const allRows=tbody.querySelectorAll('tr[id^="row-"]');
    const rowNum=allRows.length+1;
    const tur=parseFloat(d.tur)||0,mrp=parseFloat(d.mrp)||0;
    const actQty=parseFloat(d.actual_qty)||0,actDmg=parseFloat(d.actual_dmg_qty)||0;
    const dash='<span style="color:#d1d5db;">—</span>';
    const goodVal=(actQty>0&&tur>0)?actQty*tur:null;
    const dmgVal=(actDmg>0&&tur>0)?actDmg*tur:null;
    const recDateFmt=d.rec_date?new Date(d.rec_date).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}):'—';
    const tr=document.createElement('tr');
    tr.id='row-'+id;tr.className='prevday-row';
    tr.dataset.person=(d.person||'').toLowerCase();tr.dataset.sku=(d.sku_code||'').toLowerCase();tr.dataset.desc=(d.sku_desc||'').toLowerCase();tr.dataset.se='';tr.dataset.skuDesc=d.sku_desc||'';tr.dataset.mrp=mrp;tr.dataset.adjGood=0;tr.dataset.adjDamage=0;tr.dataset.totalQty=0;
    tr.innerHTML=`
        <td class="td-cb"><input type="checkbox" class="row-cb row-select-cb" data-id="${id}" data-person="${escH(d.person||'')}" data-sku="${escH(d.sku_code||'')}" data-sku-desc="${escH(d.sku_desc||'')}" data-tur="${tur}" data-se="" onchange="onRowCbChange()"></td>
        <td>${rowNum} <span class="prevday-badge"><i class="fa-solid fa-calendar-minus" style="font-size:8px;"></i> Prev</span></td>
        <td data-val="${d.rec_date||''}">${recDateFmt}</td>
        <td>${escH(d.person||'')}</td>
        <td><strong>${escH(d.sku_code||'')}</strong></td>
        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escH(d.sku_desc||'')}">${escH(d.sku_desc||'')}</td>
        <td class="num" data-val="${tur}">${tur.toFixed(2)}</td>
        <td class="num" data-val="${mrp}">${mrp.toFixed(2)}</td>
        <td class="num" data-val="0" style="color:#166634;font-weight:600;">0.00</td>
        <td class="num" data-val="0" style="color:#991b1b;font-weight:600;">0.00</td>
        <td class="num" data-val="0" style="font-weight:700;">0.00</td>
        <td class="num"><input type="number" step="0.01" min="0" class="qty-input qty-actual saved" id="actual-${id}" data-id="${id}" data-total="0" data-adj-good="0" data-adj-damage="0" data-tur="${tur}" value="${actQty>0?actQty.toFixed(2):''}" placeholder="0.00" oninput="recalcRow(${id})"></td>
        <td class="num"><input type="number" step="0.01" min="0" class="qty-input qty-dmg qty-damage saved" id="actdmg-${id}" data-id="${id}" value="${actDmg>0?actDmg.toFixed(2):''}" placeholder="0.00" oninput="recalcRow(${id})"></td>
        <td class="num" id="goodval-${id}">${goodVal!==null?`<span class="val-good">${goodVal.toFixed(2)}</span>`:dash}</td>
        <td class="num" id="dmgval-${id}">${dmgVal!==null?`<span class="val-dmg-amount">${dmgVal.toFixed(2)}</span>`:dash}</td>
        <td class="num"><span class="se-value" id="se-${id}">-</span></td>
        <td class="num" id="seval-${id}">${dash}</td>
        <td class="num" id="charge-${id}">${dash}</td>
        <td class="num" id="absorb-${id}">${dash}</td>
        <td class="num" id="variance-${id}">${dash}</td>
        <td style="text-align:center;white-space:nowrap;">
            <div style="display:flex;gap:4px;justify-content:center;">
                <button class="btn btn-save-row btn-xs" id="btn-${id}" onclick="saveRow(${id})"><i class="fa-solid fa-floppy-disk"></i> Save</button>
                <button class="btn btn-pay-row btn-xs" id="paybtn-${id}" onclick="openRowPayModal(${id})"><i class="fa-solid fa-file-invoice-dollar"></i> Pay</button>
                <button class="btn btn-del-row btn-xs" onclick="confirmDeleteRow(${id},'${escH(d.sku_code||'')}')" title="Delete Row"><i class="fa-solid fa-trash"></i></button>
            </div>
        </td>`;
    tbody.appendChild(tr);
    applyFilters();
    tr.scrollIntoView({behavior:'smooth',block:'center'});
    tr.style.outline='2px solid #f59e0b';
    setTimeout(()=>{tr.style.outline='';},2000);
}

function escH(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function applySeDirection(direction) {
    currentSeDirection = direction;
    const chargeHead     = document.getElementById('rp_charge_sec_head');
    const chargeLbl      = document.getElementById('rp_charge_sec_label');
    const absorbHead     = document.getElementById('rp_absorb_sec_head');
    const absorbLbl      = document.getElementById('rp_absorb_sec_label');
    const absorbRowLbl   = document.getElementById('rp_absorb_row_lbl');
    const sevalCard      = document.getElementById('rp_seval_card');
    const sevalLbl       = document.getElementById('rp_seval_lbl');
    const sevalStripLbl  = document.getElementById('rp_seval_strip_lbl');
    const sevalEl        = document.getElementById('rp_seval');
    const ptbSevalLbl    = document.getElementById('ptb_seval_lbl');
    const ptbSevalVal    = document.getElementById('ptb_seval');
    const chargeAmtHdr   = document.getElementById('rp_charge_amt_col_hdr');
    const addEmpBtn      = document.getElementById('rp_add_emp_btn');
    const chargeTableWrap= document.getElementById('rp_charge_table_wrap');
    const chargeTable    = document.getElementById('chargeTable');
    const saveBtn        = document.getElementById('savePayBtn');
    const banner         = document.getElementById('rp_se_banner');
    const varianceNote   = document.getElementById('rp_variance_note');
    const modalTitle     = document.getElementById('rp_modal_title');

    if (direction < 0) {
        banner.style.display = 'flex';
        banner.className     = 'se-type-banner short-banner';
        banner.innerHTML     = '<i class="fa-solid fa-arrow-trend-down"></i> <strong>SHORT</strong> — Actual quantity is less than expected. Employee must be charged for the shortage.';
        modalTitle.innerHTML = '<i class="fa-solid fa-user-minus" style="color:#dc2626;"></i> Pay Allocation — Charge to Employee (Short)';
        chargeHead.className = 'pmo-sec-head red';
        chargeLbl.innerHTML  = '<i class="fa-solid fa-user-minus"></i>&nbsp; Charge to Employee &nbsp;<span style="background:#fca5a5;color:#7f1d1d;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:700;">SHORT</span>';
        absorbHead.className = 'pmo-sec-head blue';
        absorbLbl.innerHTML  = '<i class="fa-solid fa-building"></i>&nbsp; Absorb by Company <span style="font-size:10px;font-weight:400;color:#6b7280;">(optional — if company absorbs part of shortage)</span>';
        absorbRowLbl.innerHTML = '<i class="fa-solid fa-building" style="color:#1e40af;"></i> Company absorbs partial shortage';
        sevalCard.className  = 'pmo-info-cell hi-short';
        sevalLbl.textContent = 'Short Value — Employee Owes (TUR × |Short Qty|)';
        sevalEl.style.color  = '#dc2626';
        sevalStripLbl.style.color = '#991b1b';
        sevalStripLbl.textContent = 'Short Value';
        ptbSevalLbl.textContent = 'Short Value (Employee Owes)';
        ptbSevalVal.className   = 'ptb-val red';
        chargeAmtHdr.textContent = 'Charge Amount';
        addEmpBtn.className      = 'add-emp-btn';
        addEmpBtn.innerHTML      = '<i class="fa-solid fa-plus"></i> Add Employee Row';
        chargeTableWrap.className= 'charge-table-wrap';
        chargeTable.className    = 'charge-tbl';
        saveBtn.className = 'pmo-save save-short';
        saveBtn.innerHTML = '<i class="fa-solid fa-user-minus"></i> Save Charge to Employee';
        varianceNote.textContent = 'Variance = Short Value − (Charged + Absorbed)';
    } else if (direction > 0) {
        banner.style.display = 'flex';
        banner.className     = 'se-type-banner excess-banner';
        banner.innerHTML     = '<i class="fa-solid fa-arrow-trend-up"></i> <strong>EXCESS</strong> — Actual quantity exceeds expected. Company needs to pay employee for the excess.';
        modalTitle.innerHTML = '<i class="fa-solid fa-user-plus" style="color:#16a34a;"></i> Pay Allocation — Pay to Employee (Excess)';
        chargeHead.className = 'pmo-sec-head green';
        chargeLbl.innerHTML  = '<i class="fa-solid fa-user-plus"></i>&nbsp; Pay to Employee &nbsp;<span style="background:#bbf7d0;color:#14532d;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:700;">EXCESS</span>';
        absorbHead.className = 'pmo-sec-head blue';
        absorbLbl.innerHTML  = '<i class="fa-solid fa-building"></i>&nbsp; Absorb by Company <span style="font-size:10px;font-weight:400;color:#6b7280;">(company retains part of excess value)</span>';
        absorbRowLbl.innerHTML = '<i class="fa-solid fa-building" style="color:#1e40af;"></i> Company retains excess amount';
        sevalCard.className  = 'pmo-info-cell hi-excess';
        sevalLbl.textContent = 'Excess Value — Company Owes Employee (TUR × |Excess Qty|)';
        sevalEl.style.color  = '#16a34a';
        sevalStripLbl.style.color = '#166634';
        sevalStripLbl.textContent = 'Excess Value';
        ptbSevalLbl.textContent = 'Excess Value (Company Owes)';
        ptbSevalVal.className   = 'ptb-val green';
        chargeAmtHdr.textContent = 'Pay Amount';
        addEmpBtn.className      = 'add-emp-btn excess-add';
        addEmpBtn.innerHTML      = '<i class="fa-solid fa-plus"></i> Add Employee Row';
        chargeTableWrap.className= 'charge-table-wrap excess-wrap';
        chargeTable.className    = 'charge-tbl excess-tbl';
        saveBtn.className = 'pmo-save save-excess';
        saveBtn.innerHTML = '<i class="fa-solid fa-user-plus"></i> Save Pay to Employee';
        varianceNote.textContent = 'Variance = Excess Value − (Paid Out + Absorbed)';
    } else {
        banner.style.display = 'none';
        modalTitle.innerHTML = '<i class="fa-solid fa-file-invoice-dollar" style="color:#7c3aed;"></i> Pay Allocation';
        chargeHead.className = 'pmo-sec-head red';
        chargeLbl.innerHTML  = '<i class="fa-solid fa-user-minus"></i>&nbsp; Charge to Employee';
        absorbHead.className = 'pmo-sec-head blue';
        absorbLbl.innerHTML  = '<i class="fa-solid fa-building"></i>&nbsp; Absorb by Company';
        absorbRowLbl.innerHTML = '<i class="fa-solid fa-building" style="color:#1e40af;"></i> Company Absorption Amount';
        sevalCard.className  = 'pmo-info-cell hi-short';
        sevalLbl.textContent = 'S/E Value (TUR × |S/E Qty|)';
        sevalEl.style.color  = '';
        sevalStripLbl.style.color = '#991b1b';
        sevalStripLbl.textContent = 'S/E Value';
        ptbSevalLbl.textContent = 'S/E Value';
        ptbSevalVal.className   = 'ptb-val red';
        chargeAmtHdr.textContent = 'Charge Amount';
        addEmpBtn.className      = 'add-emp-btn';
        addEmpBtn.innerHTML      = '<i class="fa-solid fa-plus"></i> Add Employee Row';
        chargeTableWrap.className= 'charge-table-wrap';
        chargeTable.className    = 'charge-tbl';
        saveBtn.className = 'pmo-save';
        saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Pay Allocation';
        varianceNote.textContent = 'Variance = S/E Value − (Charge + Absorb)';
    }
}
function buildEmpOpts(selId){
    return '<option value="">— Select Employee —</option>'+EMPLOYEES.map(e=>`<option value="${e.id}" data-empid="${e.employee_id}" data-name="${e.name}" ${e.id==selId?'selected':''}>${e.label}</option>`).join('');
}

function buildPayrollOpts(selectedLabel){
    let opts='<option value="">— Select Month —</option>';
    PAYROLL_PERIODS.forEach(pp=>{
        const sel=(pp.label===selectedLabel||pp.month_name+' '+pp.year===selectedLabel)?'selected':'';
        const col=pp.status==='Open'?'color:#16a34a;font-weight:700;':'color:#6b7280;';
        opts+=`<option value="${pp.id}" data-label="${escH(pp.label)}" data-close="${pp.close_date}" data-open="${pp.open_date}" style="${col}" ${sel}>${escH(pp.label)}</option>`;
    });
    return opts;
}

function onPayrollMonthChange(rid){
    const sel=document.getElementById('emonth_'+rid);
    const opt=sel.options[sel.selectedIndex];
    const cd=opt.dataset.close;
    const di=document.getElementById('edate_'+rid);
    if(cd&&di)di.value=cd;
    recalcPay();
}

function addEmpRow(empId,amount,payrollMonth,payDate,qty){
    empRowCount++;
    const rid='er'+empRowCount;
    const defMonth=payrollMonth||(ACTIVE_PERIOD?MN[ACTIVE_PERIOD.month]+' '+ACTIVE_PERIOD.year:'');
    const defDate=payDate||(ACTIVE_PERIOD?ACTIVE_PERIOD.close_date:new Date().toISOString().slice(0,10));
    const tr=document.createElement('tr');
    tr.id=rid;
    tr.innerHTML=`
        <td style="min-width:160px;"><select class="ct-inp emp-sel" id="esel_${rid}" style="min-width:150px;">${buildEmpOpts(empId||'')}</select></td>
        <td><input type="text" class="ct-inp" id="esku_${rid}" readonly value="${escH(currentPaySku)}" placeholder="—"></td>
        <td style="max-width:130px;overflow:hidden;"><input type="text" class="ct-inp" id="eskudesc_${rid}" readonly value="${escH(currentPaySkuDesc)}" placeholder="—" title="${escH(currentPaySkuDesc)}" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></td>
        <td><input type="number" step="0.01" min="0" class="ct-inp cnum" id="eqty_${rid}" placeholder="0.00" value="${qty!==undefined&&qty>0?parseFloat(qty).toFixed(2):''}" data-manual="${qty!==undefined&&qty>0?'1':'0'}" oninput="this.dataset.manual='1'; onQtyPriceChange('${rid}')"></td>
        <td><input type="number" step="0.01" min="0" class="ct-inp cnum" id="eprice_${rid}" placeholder="0.00" value="${currentPayTur>0?currentPayTur.toFixed(2):''}" oninput="onQtyPriceChange('${rid}')"></td>
        <td style="min-width:140px;"><select class="ct-inp" id="emonth_${rid}" onchange="onPayrollMonthChange('${rid}')">${buildPayrollOpts(defMonth)}</select></td>
        <td><input type="date" class="ct-inp" id="edate_${rid}" value="${defDate}"></td>
        <td><input type="number" step="0.01" min="0" class="ct-inp cnum" id="eamt_${rid}" placeholder="0.00" value="${amount!==undefined?parseFloat(amount).toFixed(2):''}" oninput="recalcPay()"></td>
        <td><button class="ct-rm" onclick="removeEmpRow('${rid}')" title="Remove row"><i class="fa-solid fa-xmark"></i></button></td>`;
    document.getElementById('chargeRows').appendChild(tr);
    $('#esel_'+rid).select2({placeholder:'— Select Employee —',allowClear:true,dropdownParent:$('#rowPayModal')}).on('change',function(){recalcPay();});
    redistributeQty();
    if(amount===undefined)redistributeCharges();
    const _qInp=document.getElementById('eqty_'+rid);
    const _pInp=document.getElementById('eprice_'+rid);
    const _aInp=document.getElementById('eamt_'+rid);
    if(_qInp&&_pInp&&_aInp){const _q=parseFloat(_qInp.value)||0;const _p=parseFloat(_pInp.value)||0;if(_q>0&&_p>0)_aInp.value=(_q*_p).toFixed(2);}
    recalcPay();
}

function onQtyPriceChange(rid){
    const qty=parseFloat(document.getElementById('eqty_'+rid)?.value||0)||0;
    const price=parseFloat(document.getElementById('eprice_'+rid)?.value||0)||0;
    const amtEl=document.getElementById('eamt_'+rid);
    if(qty>0&&price>0&&amtEl)amtEl.value=(qty*price).toFixed(2);
    recalcPay();
}

function removeEmpRow(rid){
    const el=document.getElementById(rid);if(el)el.remove();
    redistributeQty();redistributeCharges();recalcPay();
}

/*
 * FIX: quantity redistribution.
 * A row is treated as "manual" (fixed, excluded from auto-split) ONLY when the person has
 * actually typed into its qty box (dataset.manual === '1'). Rows created programmatically by
 * addEmpRow() with no qty argument start as data-manual="0", so they always take part in the
 * even split below. This is what makes the S/E quantity (and therefore the charge amount)
 * divide correctly across multiple employees instead of all going to the first row.
 */
function redistributeQty(){
    const allRows=document.querySelectorAll('#chargeRows tr[id^="er"]');
    if(allRows.length===0||currentSeQty<=0)return;
    const autoRows=[];let usedQty=0;
    allRows.forEach(row=>{
        const qtyInp=document.getElementById('eqty_'+row.id);if(!qtyInp)return;
        if(qtyInp.dataset.manual==='1'&&parseFloat(qtyInp.value)>0)usedQty+=parseFloat(qtyInp.value);
        else autoRows.push(row);
    });
    const remaining=Math.max(0,Math.round((currentSeQty-usedQty)*10000)/10000);
    const count=autoRows.length;if(count===0)return;
    const share=Math.round((remaining/count)*10000)/10000;let rem=remaining;
    autoRows.forEach((row,i)=>{
        const qtyInp=document.getElementById('eqty_'+row.id);const priceInp=document.getElementById('eprice_'+row.id);const amtInp=document.getElementById('eamt_'+row.id);if(!qtyInp)return;
        const qShare=(i<count-1)?share:Math.round(rem*10000)/10000;
        if(i<count-1)rem=Math.round((rem-share)*10000)/10000;
        qtyInp.value=qShare.toFixed(2);
        const price=parseFloat(priceInp?.value||0)||0;
        if(price>0&&amtInp)amtInp.value=(qShare*price).toFixed(2);
    });
}

function redistributeCharges(){
    const empRows=document.querySelectorAll('#chargeRows tr[id^="er"]');
    const count=empRows.length;if(count===0||currentSeValue<=0)return;
    const autoAmtRows=[];
    empRows.forEach(row=>{
        const qtyInp=document.getElementById('eqty_'+row.id);const priceInp=document.getElementById('eprice_'+row.id);
        const price=parseFloat(priceInp?.value||0)||0;const qty=parseFloat(qtyInp?.value||0)||0;
        if(qty>0&&price>0)return;
        autoAmtRows.push(row);
    });
    const autoCount=autoAmtRows.length;if(autoCount===0)return;
    const autoShare=Math.round((currentSeValue/autoCount)*100)/100;let autoRem=Math.round(currentSeValue*100)/100;
    autoAmtRows.forEach((row,i)=>{
        const inp=document.getElementById('eamt_'+row.id);if(!inp)return;
        if(i<autoCount-1){inp.value=autoShare.toFixed(2);autoRem=Math.round((autoRem-autoShare)*100)/100;}
        else inp.value=autoRem.toFixed(2);
    });
}

function recalcPay(){
    let tc=0;
    document.querySelectorAll('#chargeRows tr[id^="er"]').forEach(row=>{
        const a=parseFloat(document.getElementById('eamt_'+row.id)?.value||0);if(a>0)tc+=a;
    });
    const absorb=parseFloat(document.getElementById('rp_absorb_amt')?.value||0)||0;
    const seVal=currentSeValue;const alloc=tc+absorb;const vari=seVal-alloc;
    document.getElementById('rp_charge_total').textContent=tc.toFixed(2);
    document.getElementById('rp_absorb_total').textContent=absorb.toFixed(2);
    document.getElementById('ptb_alloc').textContent=alloc.toFixed(2);
    const vEl=document.getElementById('ptb_var');
    vEl.textContent=(vari>0?'+':'')+vari.toFixed(2);
    vEl.className='ptb-val '+(Math.abs(vari)<0.005?'green':vari>0?'orange':'red');
}
function openRowPayModal(id){
    currentPayRowId=id;empRowCount=0;currentSeValue=0;
    document.getElementById('chargeRows').innerHTML='';
    document.getElementById('rp_absorb_amt').value='';

    const row     =document.getElementById('row-'+id);
    const input   =document.getElementById('actual-'+id);
    const person  =row.querySelector('td:nth-child(4)')?.textContent.trim()||'—';
    const sku     =row.querySelector('td:nth-child(5)')?.textContent.trim()||'—';
    const skuDesc =row.dataset.skuDesc||'—';
    const tur     =parseFloat(input?.dataset.tur||0);
    const mrp     =parseFloat(row.dataset.mrp||0);
    const adjGood =parseFloat(row.dataset.adjGood||0);
    const adjDmg  =parseFloat(row.dataset.adjDamage||0);
    const totalQty=parseFloat(row.dataset.totalQty||0);
    const se      =row.dataset.se!==''?parseFloat(row.dataset.se):null;
    const seAmt   =(se!==null&&!isNaN(se)&&tur>0)?tur*Math.abs(se):0;

    currentSeValue    =seAmt;
    currentSeQty      =(se!==null&&!isNaN(se))?Math.abs(se):0;
    currentPaySku     =sku;
    currentPaySkuDesc =skuDesc;
    currentPayTur     =tur;

    const direction = (se!==null&&!isNaN(se)) ? (se<0?-1:se>0?1:0) : 0;

    document.getElementById('rp_sub').textContent       =person+' · '+sku;
    document.getElementById('rp_sku').textContent       =sku;
    document.getElementById('rp_sku_desc').textContent  =skuDesc;
    document.getElementById('rp_tur').textContent       ='Rs. '+tur.toFixed(2);
    document.getElementById('rp_adj_good').textContent  =adjGood.toFixed(2);
    document.getElementById('rp_adj_damage').textContent=adjDmg.toFixed(2);
    document.getElementById('rp_total_qty').textContent =totalQty.toFixed(2);
    document.getElementById('rp_seval_strip').textContent=seAmt.toFixed(2);
    document.getElementById('rp_person').textContent    =person;
    document.getElementById('rp_seval').textContent     =seAmt.toFixed(2);
    document.getElementById('ptb_seval').textContent    =seAmt.toFixed(2);
    document.getElementById('ptb_alloc').textContent    ='0.00';
    document.getElementById('rp_charge_total').textContent='0.00';
    document.getElementById('rp_absorb_total').textContent='0.00';

    const vEl=document.getElementById('ptb_var');
    vEl.textContent=seAmt.toFixed(2);vEl.className='ptb-val orange';

    if(ACTIVE_PERIOD){
        document.getElementById('rp_payroll_period').value=ACTIVE_PERIOD.id;
        const di=document.getElementById('rp_charge_date');
        di.min=ACTIVE_PERIOD.open_date;di.max=ACTIVE_PERIOD.close_date;di.value=ACTIVE_PERIOD.close_date;
    } else {
        document.getElementById('rp_payroll_period').value='';
    }

    applySeDirection(direction);

    fetch('get_unloading_pay.php?detail_id='+id)
    .then(r=>r.json())
    .then(res=>{
        if(res.success&&res.transactions&&res.transactions.length>0){
            res.transactions.forEach(tx=>{
                if(tx.entry_type==='charge')addEmpRow(tx.employee_id,tx.amount,tx.payroll_month_label||'',tx.pay_date||'',tx.qty||0);
                if(tx.entry_type==='absorb'){document.getElementById('rp_absorb_amt').value=tx.amount;recalcPay();}
            });
        } else {
            /* FIX: add the first employee row as an AUTO row (no fixed manual quantity) so that
               when a second/third employee row is added, redistributeQty()/redistributeCharges()
               can split the S/E quantity and charge amount evenly across all employees, instead
               of the whole amount being locked onto the first row. */
            if(se!==null&&seAmt>0){ addEmpRow(); }
        }
    })
    .catch(()=>{
        if(se!==null&&seAmt>0){ addEmpRow(); }
    });

    document.getElementById('rowPayModal').classList.add('open');
}

function closeRowPayModal(){document.getElementById('rowPayModal').classList.remove('open');currentPayRowId=null;}

function savePayAllocation(){
    if(!currentPayRowId)return;
    const charges=[];let valid=true;
    document.querySelectorAll('#chargeRows tr[id^="er"]').forEach(row=>{
        const rid=row.id;
        const $sel=$('#esel_'+rid);
        const empId=$sel.val();
        const selOpt=$sel.find('option:selected');
        const empName=selOpt.data('name')||selOpt.text()||'';
        const amt=parseFloat(document.getElementById('eamt_'+rid)?.value||0);
        const qty=parseFloat(document.getElementById('eqty_'+rid)?.value||0);
        const price=parseFloat(document.getElementById('eprice_'+rid)?.value||0);
        const mSel=document.getElementById('emonth_'+rid);
        const mOpt=mSel?mSel.options[mSel.selectedIndex]:null;
        const mPPId=mSel?(mSel.value||null):null;
        const month=mOpt?(mOpt.dataset.label||mOpt.text||'').trim():'';
        const paydate=document.getElementById('edate_'+rid)?.value||'';
        if(!empId){showToast('Please select an employee.','error');valid=false;return;}
        if(!(amt>0)){showToast('Charge amount must be > 0.','error');valid=false;return;}
        charges.push({employee_id:parseInt(empId),employee_name:empName,amount:amt,qty,price,payroll_period_id:mPPId?parseInt(mPPId):null,payroll_month_label:month,pay_date:paydate});
    });
    if(!valid)return;

    const absorb=parseFloat(document.getElementById('rp_absorb_amt')?.value||0)||0;
    const seVal=currentSeValue;
    const tc=charges.reduce((s,r)=>s+r.amount,0);
    const vari=seVal-(tc+absorb);

    const ppSel=document.getElementById('rp_payroll_period');
    const ppId=ppSel.value||null;
    const ppOpt=ppSel.options[ppSel.selectedIndex];
    const ppYear=ppId?(ppOpt.dataset.year||null):null;
    const ppMonth=ppId?(ppOpt.dataset.month||null):null;
    const chargeDate=document.getElementById('rp_charge_date').value||null;

    const btn=document.getElementById('savePayBtn');
    setBtn(btn,true,'<i class="fa-solid fa-spinner fa-spin"></i> Saving...');

    fetch('save_unloading_pay.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({import_detail_id:currentPayRowId,import_id:IMPORT_ID,charges,absorb_amount:absorb,se_value:seVal,variance:vari,payroll_period_id:ppId?parseInt(ppId):null,payroll_year:ppYear?parseInt(ppYear):null,payroll_month:ppMonth?parseInt(ppMonth):null,charge_date:chargeDate})})
    .then(function(response){return response.text();})
    .then(function(txt){
        setBtn(btn,false,currentSeDirection>0?'<i class="fa-solid fa-user-plus"></i> Save Pay to Employee':'<i class="fa-solid fa-floppy-disk"></i> Save Pay Allocation');
        let res;
        try{const j=txt.indexOf('{');const k=txt.lastIndexOf('}');if(j===-1||k===-1)throw new Error('No JSON');res=JSON.parse(txt.substring(j,k+1));}
        catch(e){const preview=txt.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().substring(0,300);showToast('Server error: '+preview,'error');return;}

        if(res.success){
            const id=currentPayRowId;
            document.getElementById('charge-'+id).innerHTML=tc>0?`<span class="val-charge">${f2(tc)}</span>`:'<span style="color:#d1d5db;">—</span>';
            document.getElementById('absorb-'+id).innerHTML=absorb>0?`<span class="val-absorb">${f2(absorb)}</span>`:'<span style="color:#d1d5db;">—</span>';
            const vc=vari<0?'short':vari>0?'excess':'zero';
            document.getElementById('variance-'+id).innerHTML=`<span class="${vc}">${vari>0?'+':''}${f2(vari)}</span>`;

            const pb=document.getElementById('paybtn-'+id);
            if(pb){
                if(currentSeDirection<0){pb.className='btn btn-pay-row btn-xs paid-short';pb.innerHTML='<i class="fa-solid fa-check"></i> Charged';}
                else if(currentSeDirection>0){pb.className='btn btn-pay-row btn-xs paid-excess';pb.innerHTML='<i class="fa-solid fa-check"></i> Paid Out';}
                else{pb.className='btn btn-pay-row btn-xs paid-short';pb.innerHTML='<i class="fa-solid fa-check"></i> Paid';}
            }

            closeRowPayModal();
            showToast(currentSeDirection<0?'Employee charge saved!':currentSeDirection>0?'Excess payment saved!':'Pay allocation saved!','success');
            calculateTotals();
        } else {
            showToast('Error: '+(res.message||'Unknown error'),'error');
        }
    })
    .catch(function(err){
        setBtn(btn,false,'<i class="fa-solid fa-floppy-disk"></i> Save Pay Allocation');
        showToast('Save failed: '+err.message,'error');
    });
}
let reImportParsedData=null,reImportFileName='';

function openReImportModal(){
    reImportParsedData=null;reImportFileName='';
    document.getElementById('reImportFile').value='';document.getElementById('reImportFileInfo').textContent='';
    document.getElementById('reImportProgress').style.display='none';document.getElementById('reImportResult').style.display='none';
    document.getElementById('reImportRunBtn').disabled=true;document.getElementById('reImportRunBtn').innerHTML='<i class="fa-solid fa-rotate"></i> Re-Import Now';
    document.getElementById('reImportCancelBtn').textContent='Cancel';
    document.getElementById('reImportModal').classList.add('open');
}
function closeReImportModal(){document.getElementById('reImportModal').classList.remove('open');}

function onReImportFileChange(){
    const file=document.getElementById('reImportFile').files[0];if(!file)return;
    reImportFileName=file.name;
    document.getElementById('reImportFileInfo').textContent='Reading: '+file.name+' ('+(file.size/1024).toFixed(1)+' KB)';
    document.getElementById('reImportRunBtn').disabled=true;
    document.getElementById('reImportProgress').style.display='block';
    document.getElementById('reImportProgressLabel').textContent='Parsing Excel file…';
    document.getElementById('reImportProgressBar').style.width='20%';
    const reader=new FileReader();
    reader.onload=function(e){
        try{
            const wb=XLSX.read(e.target.result,{type:'array',cellDates:true});
            const ws=wb.Sheets[wb.SheetNames[0]];
            const rows=XLSX.utils.sheet_to_json(ws,{header:1,defval:''});
            document.getElementById('reImportProgressBar').style.width='60%';
            document.getElementById('reImportProgressLabel').textContent='Mapping columns…';
            const mapped=[];
            for(let i=1;i<rows.length;i++){
                const r=rows[i];if(!r||r.length<7)continue;
                const parseDate=(v)=>{if(!v)return '';if(v instanceof Date){const yr=v.getFullYear(),mo=String(v.getMonth()+1).padStart(2,'0'),dy=String(v.getDate()).padStart(2,'0');return yr+'-'+mo+'-'+dy;}return String(v).trim();};
                const record_date=parseDate(r[1]);
                const delivery_person_code=String(r[2]??'').trim();const delivery_person_name=String(r[3]??'').trim();const vehicle=String(r[4]??'').trim();
                const sku_code=String(r[5]??'').trim();const sku_desc=String(r[6]??'').trim();
                const tur=parseFloat(r[7]??0)||0;const mrp=parseFloat(r[8]??0)||0;
                // Adj Qty (Good)   <- "Difference - Units"      (Excel column index 34)
                // Adj Qty (Damage) <- "Adjustment Qty(Damage)"  (Excel column index 33)
                const adj_qty_good_units=Math.abs(parseFloat(r[34]??0)||0);const adj_qty_damage=Math.abs(parseFloat(r[33]??0)||0);
                if(!sku_code&&!delivery_person_code&&!delivery_person_name)continue;
                mapped.push({record_date,delivery_person_code,delivery_person_name,vehicle,sku_code,sku_desc,tur,mrp,adj_qty_good_units,adj_qty_damage});
            }
            reImportParsedData=mapped;
            document.getElementById('reImportProgressBar').style.width='100%';
            document.getElementById('reImportProgressLabel').textContent='✓ Parsed '+mapped.length+' records from '+file.name;
            document.getElementById('reImportFileInfo').innerHTML='<span style="color:#16a34a;font-weight:600;"><i class="fa-solid fa-circle-check"></i> '+mapped.length+' records ready to re-import</span>';
            document.getElementById('reImportRunBtn').disabled=(mapped.length===0);
        }catch(err){
            document.getElementById('reImportProgressLabel').textContent='✗ Error parsing file: '+err.message;
            document.getElementById('reImportFileInfo').innerHTML='<span style="color:#dc2626;"><i class="fa-solid fa-circle-xmark"></i> Parse failed: '+err.message+'</span>';
        }
    };
    reader.readAsArrayBuffer(file);
}

function runReImport(){
    if(!reImportParsedData||reImportParsedData.length===0){showToast('No data parsed from file.','error');return;}
    const btn=document.getElementById('reImportRunBtn');const cancelBtn=document.getElementById('reImportCancelBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Re-Importing…';cancelBtn.disabled=true;
    document.getElementById('reImportProgress').style.display='block';
    document.getElementById('reImportProgressLabel').textContent='Uploading to server…';document.getElementById('reImportProgressBar').style.width='30%';
    document.getElementById('reImportResult').style.display='none';
    const formData=new FormData();
    formData.append('import_id',IMPORT_ID);formData.append('filename',reImportFileName);
    formData.append('delivery_date',document.getElementById('reImportDeliveryDate').value||'');
    formData.append('data',JSON.stringify(reImportParsedData));
    document.getElementById('reImportProgressBar').style.width='60%';
    fetch('process_reimport_unloading.php',{method:'POST',body:formData})
    .then(r=>r.text()).then(raw=>{
        document.getElementById('reImportProgressBar').style.width='100%';
        let res;
        try{const j=raw.indexOf('{');res=JSON.parse(j>=0?raw.substring(j):raw);}
        catch(e){throw new Error('Invalid server response: '+raw.substring(0,200));}
        cancelBtn.disabled=false;cancelBtn.textContent='Close';
        if(res.success){
            document.getElementById('reImportProgressLabel').textContent='✓ Re-import complete!';
            document.getElementById('reImportResult').style.display='block';
            document.getElementById('reImportResult').innerHTML=`<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:14px 16px;font-size:13px;margin-top:4px;"><div style="font-weight:700;color:#166634;margin-bottom:10px;font-size:14px;"><i class="fa-solid fa-circle-check"></i> Re-import Successful</div><div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;"><div>📥 New rows imported: <strong>${res.imported}</strong></div><div>📂 Old rows archived: <strong>${res.old_archived}</strong></div><div>✏️ Unloading data updated: <strong>${res.updated_ud}</strong></div><div>➕ New rows added to data: <strong>${res.added_ud}</strong></div><div>⏭️ Zero-qty rows skipped: <strong>${res.skipped_ud}</strong></div><div>🆕 New SKUs added: <strong>${res.new_items}</strong></div>${res.failed>0?'<div style="color:#dc2626;">✗ Failed: <strong>'+res.failed+'</strong></div>':''}</div><div style="margin-top:10px;font-size:12px;color:#6b7280;">Log ID: #${res.log_id} — Page will reload in 3 seconds…</div></div>`;
            btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Done!';
            showToast('Re-import complete! '+res.imported+' records updated.','success');
            setTimeout(()=>location.reload(),3000);
        } else {
            document.getElementById('reImportProgressLabel').textContent='✗ Re-import failed';
            document.getElementById('reImportResult').style.display='block';
            document.getElementById('reImportResult').innerHTML='<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px 16px;font-size:13px;color:#991b1b;"><i class="fa-solid fa-circle-xmark"></i> <strong>Error:</strong> '+(res.message||'Unknown error')+'</div>';
            btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-rotate"></i> Retry';
            showToast('Re-import failed: '+res.message,'error');
        }
    })
    .catch(err=>{
        document.getElementById('reImportProgressLabel').textContent='✗ Network error';
        document.getElementById('reImportResult').style.display='block';
        document.getElementById('reImportResult').innerHTML='<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px 16px;font-size:13px;color:#991b1b;"><i class="fa-solid fa-circle-xmark"></i> Network error: '+err.message+'</div>';
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-rotate"></i> Retry';cancelBtn.disabled=false;
        showToast('Network error: '+err.message,'error');
    });
}
function toggleSelectAll(masterCb){
    const checked=masterCb.checked;
    document.querySelectorAll('.row-select-cb').forEach(cb=>{const tr=cb.closest('tr');if(tr&&!tr.classList.contains('hidden-row'))cb.checked=checked;});
    onRowCbChange();
}

function onRowCbChange(){
    const checked=document.querySelectorAll('.row-select-cb:checked');
    const bar=document.getElementById('bulkBar');
    document.getElementById('bulkCount').textContent=checked.length+' row'+(checked.length!==1?'s':'')+' selected';
    bar.classList.toggle('show',checked.length>0);
    const allCbs=document.querySelectorAll('.row-select-cb');
    const masterCb=document.getElementById('selectAllCb');
    if(masterCb){masterCb.indeterminate=checked.length>0&&checked.length<allCbs.length;masterCb.checked=checked.length>0&&checked.length===allCbs.length;}
}

function clearBulkSelection(){
    document.querySelectorAll('.row-select-cb').forEach(cb=>cb.checked=false);
    const masterCb=document.getElementById('selectAllCb');
    if(masterCb){masterCb.checked=false;masterCb.indeterminate=false;}
    onRowCbChange();
}

function openBulkPayModal(){
    const checked=Array.from(document.querySelectorAll('.row-select-cb:checked'));
    if(checked.length===0){showToast('No rows selected.','error');return;}
    bpEmpRowCount=0;bpTotalSeValue=0;bpTotalSeQty=0;bpSelectedRows=[];
    document.getElementById('bp_chargeRows').innerHTML='';document.getElementById('bp_absorb_amt').value='';
    checked.forEach(cb=>{
        const id=parseInt(cb.dataset.id);const se=cb.dataset.se!==''?parseFloat(cb.dataset.se):null;
        const tur=parseFloat(cb.dataset.tur||0);const seQty=(se!==null&&!isNaN(se))?Math.abs(se):0;
        const seValue=(seQty>0&&tur>0)?tur*seQty:0;
        bpSelectedRows.push({id,person:cb.dataset.person,sku:cb.dataset.sku,skuDesc:cb.dataset.skuDesc,tur,se,seQty,seValue});
        bpTotalSeValue+=seValue;bpTotalSeQty+=seQty;
    });
    const tbody=document.getElementById('bp_rows_body');tbody.innerHTML='';
    bpSelectedRows.forEach(r=>{
        const tr=document.createElement('tr');
        tr.innerHTML=`<td>${escH(r.person)}</td><td><strong>${escH(r.sku)}</strong></td><td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escH(r.skuDesc)}">${escH(r.skuDesc)}</td><td class="rnum">${r.tur.toFixed(2)}</td><td class="rnum ${r.se!==null&&r.se<0?'short':r.se>0?'excess':''}">${r.se!==null?(r.se>0?'+':'')+r.se.toFixed(2):'—'}</td><td class="rnum" style="font-weight:700;">${r.seValue.toFixed(2)}</td>`;
        tbody.appendChild(tr);
    });
    document.getElementById('bp_row_count').textContent=bpSelectedRows.length;
    document.getElementById('bp_total_seval').textContent=bpTotalSeValue.toFixed(2);
    document.getElementById('bp_total_seqty').textContent=bpTotalSeQty.toFixed(2);
    document.getElementById('bp_sub').textContent=bpSelectedRows.length+' rows · Total S/E Value: '+bpTotalSeValue.toFixed(2);
    document.getElementById('bp_ptb_seval').textContent=bpTotalSeValue.toFixed(2);
    document.getElementById('bp_ptb_alloc').textContent='0.00';
    document.getElementById('bp_charge_total').textContent='0.00';document.getElementById('bp_absorb_total').textContent='0.00';
    const vEl=document.getElementById('bp_ptb_var');vEl.textContent=bpTotalSeValue.toFixed(2);vEl.className='ptb-val orange';
    if(ACTIVE_PERIOD){document.getElementById('bp_payroll_period').value=ACTIVE_PERIOD.id;const di=document.getElementById('bp_charge_date');di.min=ACTIVE_PERIOD.open_date;di.max=ACTIVE_PERIOD.close_date;di.value=ACTIVE_PERIOD.close_date;}
    const hasShortage=bpSelectedRows.some(r=>r.se!==null&&r.se<0);
    if(hasShortage)bpAddEmpRow();
    document.getElementById('bulkPayModal').classList.add('open');
}
function closeBulkPayModal(){document.getElementById('bulkPayModal').classList.remove('open');}

function bpAddEmpRow(empId,amount,payrollMonth,payDate,qty){
    bpEmpRowCount++;const rid='bper'+bpEmpRowCount;
    const defMonth=payrollMonth||(ACTIVE_PERIOD?MN[ACTIVE_PERIOD.month]+' '+ACTIVE_PERIOD.year:'');
    const defDate=payDate||(ACTIVE_PERIOD?ACTIVE_PERIOD.close_date:new Date().toISOString().slice(0,10));
    const tr=document.createElement('tr');tr.id=rid;
    tr.innerHTML=`
        <td style="min-width:160px;"><select class="ct-inp emp-sel" id="bp_esel_${rid}" style="min-width:150px;">${buildEmpOpts(empId||'')}</select></td>
        <td><input type="number" step="0.01" min="0" class="ct-inp cnum" id="bp_eqty_${rid}" placeholder="0.00" value="${qty!==undefined&&qty>0?parseFloat(qty).toFixed(2):''}" data-manual="${qty!==undefined&&qty>0?'1':'0'}" oninput="this.dataset.manual='1'; bpOnQtyPriceChange('${rid}')"></td>
        <td><input type="number" step="0.01" min="0" class="ct-inp cnum" id="bp_eprice_${rid}" placeholder="0.00" value="" oninput="bpOnQtyPriceChange('${rid}')"></td>
        <td style="min-width:140px;"><select class="ct-inp" id="bp_emonth_${rid}" onchange="bpOnPayrollMonthChange('${rid}')">${buildPayrollOpts(defMonth)}</select></td>
        <td><input type="date" class="ct-inp" id="bp_edate_${rid}" value="${defDate}"></td>
        <td><input type="number" step="0.01" min="0" class="ct-inp cnum" id="bp_eamt_${rid}" placeholder="0.00" value="${amount!==undefined?parseFloat(amount).toFixed(2):''}" oninput="bpRecalcPay()"></td>
        <td><button class="ct-rm" onclick="bpRemoveEmpRow('${rid}')" title="Remove row"><i class="fa-solid fa-xmark"></i></button></td>`;
    document.getElementById('bp_chargeRows').appendChild(tr);
    $('#bp_esel_'+rid).select2({placeholder:'— Select Employee —',allowClear:true,dropdownParent:$('#bulkPayModal')}).on('change',function(){bpRecalcPay();});
    bpRedistributeQty();if(amount===undefined)bpRedistributeCharges();bpRecalcPay();
}

function bpOnPayrollMonthChange(rid){
    const sel=document.getElementById('bp_emonth_'+rid);const opt=sel.options[sel.selectedIndex];const cd=opt.dataset.close;const di=document.getElementById('bp_edate_'+rid);if(cd&&di)di.value=cd;bpRecalcPay();
}
function bpOnQtyPriceChange(rid){
    const qty=parseFloat(document.getElementById('bp_eqty_'+rid)?.value||0)||0;const price=parseFloat(document.getElementById('bp_eprice_'+rid)?.value||0)||0;const amtEl=document.getElementById('bp_eamt_'+rid);if(qty>0&&price>0&&amtEl)amtEl.value=(qty*price).toFixed(2);bpRecalcPay();
}
function bpRemoveEmpRow(rid){const el=document.getElementById(rid);if(el)el.remove();bpRedistributeQty();bpRedistributeCharges();bpRecalcPay();}

function bpRedistributeQty(){
    const allRows=document.querySelectorAll('#bp_chargeRows tr[id^="bper"]');if(allRows.length===0||bpTotalSeQty<=0)return;
    const autoRows=[];let usedQty=0;
    allRows.forEach(row=>{const qtyInp=document.getElementById('bp_eqty_'+row.id);if(!qtyInp)return;if(qtyInp.dataset.manual==='1'&&parseFloat(qtyInp.value)>0)usedQty+=parseFloat(qtyInp.value);else autoRows.push(row);});
    const remaining=Math.max(0,Math.round((bpTotalSeQty-usedQty)*10000)/10000);const count=autoRows.length;if(count===0)return;
    const share=Math.round((remaining/count)*10000)/10000;let rem=remaining;
    autoRows.forEach((row,i)=>{const qtyInp=document.getElementById('bp_eqty_'+row.id);if(!qtyInp)return;const qShare=(i<count-1)?share:Math.round(rem*10000)/10000;if(i<count-1)rem=Math.round((rem-share)*10000)/10000;qtyInp.value=qShare.toFixed(2);});
}

function bpRedistributeCharges(){
    const empRows=document.querySelectorAll('#bp_chargeRows tr[id^="bper"]');const count=empRows.length;if(count===0||bpTotalSeValue<=0)return;
    const autoAmtRows=[];
    empRows.forEach(row=>{const qtyInp=document.getElementById('bp_eqty_'+row.id);const priceInp=document.getElementById('bp_eprice_'+row.id);const price=parseFloat(priceInp?.value||0)||0;const qty=parseFloat(qtyInp?.value||0)||0;if(qty>0&&price>0)return;autoAmtRows.push(row);});
    const autoCount=autoAmtRows.length;if(autoCount===0)return;
    const autoShare=Math.round((bpTotalSeValue/autoCount)*100)/100;let autoRem=Math.round(bpTotalSeValue*100)/100;
    autoAmtRows.forEach((row,i)=>{const inp=document.getElementById('bp_eamt_'+row.id);if(!inp)return;if(i<autoCount-1){inp.value=autoShare.toFixed(2);autoRem=Math.round((autoRem-autoShare)*100)/100;}else inp.value=autoRem.toFixed(2);});
}

function bpRecalcPay(){
    let tc=0;document.querySelectorAll('#bp_chargeRows tr[id^="bper"]').forEach(row=>{const a=parseFloat(document.getElementById('bp_eamt_'+row.id)?.value||0);if(a>0)tc+=a;});
    const absorb=parseFloat(document.getElementById('bp_absorb_amt')?.value||0)||0;const alloc=tc+absorb;const vari=bpTotalSeValue-alloc;
    document.getElementById('bp_charge_total').textContent=tc.toFixed(2);document.getElementById('bp_absorb_total').textContent=absorb.toFixed(2);document.getElementById('bp_ptb_alloc').textContent=alloc.toFixed(2);
    const vEl=document.getElementById('bp_ptb_var');vEl.textContent=(vari>0?'+':'')+vari.toFixed(2);vEl.className='ptb-val '+(Math.abs(vari)<0.005?'green':vari>0?'orange':'red');
}

async function saveBulkPayAllocation(){
    if(bpSelectedRows.length===0)return;
    const charges=[];let valid=true;
    document.querySelectorAll('#bp_chargeRows tr[id^="bper"]').forEach(row=>{
        const rid=row.id;const $sel=$('#bp_esel_'+rid);const empId=$sel.val();const selOpt=$sel.find('option:selected');const empName=selOpt.data('name')||selOpt.text()||'';
        const amt=parseFloat(document.getElementById('bp_eamt_'+rid)?.value||0);const qty=parseFloat(document.getElementById('bp_eqty_'+rid)?.value||0);const price=parseFloat(document.getElementById('bp_eprice_'+rid)?.value||0);
        const mSel=document.getElementById('bp_emonth_'+rid);const mOpt=mSel?mSel.options[mSel.selectedIndex]:null;const mPPId=mSel?(mSel.value||null):null;const month=mOpt?(mOpt.dataset.label||mOpt.text||'').trim():'';const paydate=document.getElementById('bp_edate_'+rid)?.value||'';
        if(!empId){showToast('Please select an employee.','error');valid=false;return;}
        if(!(amt>0)){showToast('Charge amount must be > 0.','error');valid=false;return;}
        charges.push({employee_id:parseInt(empId),employee_name:empName,amount:amt,qty,price,payroll_period_id:mPPId?parseInt(mPPId):null,payroll_month_label:month,pay_date:paydate});
    });
    if(!valid)return;
    const absorb=parseFloat(document.getElementById('bp_absorb_amt')?.value||0)||0;
    const ppSel=document.getElementById('bp_payroll_period');const ppId=ppSel.value||null;const ppOpt=ppSel.options[ppSel.selectedIndex];const ppYear=ppId?(ppOpt.dataset.year||null):null;const ppMonth=ppId?(ppOpt.dataset.month||null):null;const chargeDate=document.getElementById('bp_charge_date').value||null;
    const btn=document.getElementById('bp_saveBtn');
    setBtn(btn,true,'<i class="fa-solid fa-spinner fa-spin"></i> Saving '+bpSelectedRows.length+' rows...');
    let savedCount=0,failCount=0;

    for(const rowData of bpSelectedRows){
        const proportion=bpTotalSeValue>0?rowData.seValue/bpTotalSeValue:(1/bpSelectedRows.length);
        const rowCharges=charges.map(c=>({...c,amount:parseFloat((c.amount*proportion).toFixed(2)),qty:c.qty>0?parseFloat((c.qty*proportion).toFixed(4)):0}));
        const rowAbsorb=parseFloat((absorb*proportion).toFixed(2));
        const rowSeVal=rowData.seValue;const rowTC=rowCharges.reduce((s,c)=>s+c.amount,0);const rowVari=parseFloat((rowSeVal-(rowTC+rowAbsorb)).toFixed(2));
        try{
            const res=await fetch('save_unloading_pay.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({import_detail_id:rowData.id,import_id:IMPORT_ID,charges:rowCharges,absorb_amount:rowAbsorb,se_value:rowSeVal,variance:rowVari,payroll_period_id:ppId?parseInt(ppId):null,payroll_year:ppYear?parseInt(ppYear):null,payroll_month:ppMonth?parseInt(ppMonth):null,charge_date:chargeDate})});
            const txt=await res.text();let parsed;
            try{const j=txt.indexOf('{');parsed=JSON.parse(j>=0?txt.substring(j):txt);}catch(e){failCount++;continue;}
            if(parsed.success){
                savedCount++;
                document.getElementById('charge-'+rowData.id).innerHTML=rowTC>0?`<span class="val-charge">${f2(rowTC)}</span>`:'<span style="color:#d1d5db;">—</span>';
                document.getElementById('absorb-'+rowData.id).innerHTML=rowAbsorb>0?`<span class="val-absorb">${f2(rowAbsorb)}</span>`:'<span style="color:#d1d5db;">—</span>';
                const vc=rowVari<0?'short':rowVari>0?'excess':'zero';
                document.getElementById('variance-'+rowData.id).innerHTML=`<span class="${vc}">${rowVari>0?'+':''}${f2(rowVari)}</span>`;
                const pb=document.getElementById('paybtn-'+rowData.id);
                if(pb){
                    if(rowData.se!==null&&rowData.se<0){pb.className='btn btn-pay-row btn-xs paid-short';pb.innerHTML='<i class="fa-solid fa-check"></i> Charged';}
                    else if(rowData.se!==null&&rowData.se>0){pb.className='btn btn-pay-row btn-xs paid-excess';pb.innerHTML='<i class="fa-solid fa-check"></i> Paid Out';}
                    else{pb.className='btn btn-pay-row btn-xs paid-short';pb.innerHTML='<i class="fa-solid fa-check"></i> Paid';}
                }
            } else failCount++;
        }catch(e){failCount++;}
    }

    setBtn(btn,false,'<i class="fa-solid fa-floppy-disk"></i> Save Bulk Pay Allocation');
    if(failCount===0){showToast('Bulk pay saved for '+savedCount+' row(s)!','success');closeBulkPayModal();clearBulkSelection();calculateTotals();}
    else{showToast(savedCount+' saved, '+failCount+' failed.',failCount===savedCount?'error':'success');calculateTotals();}
}

function setBtn(btn,dis,html){btn.disabled=dis;btn.innerHTML=html;}
function showToast(msg,type){
    const t=document.getElementById('toast');t.textContent=msg;t.className=type;t.style.display='block';
    clearTimeout(t._t);t._t=setTimeout(()=>t.style.display='none',3500);
}
</script>

<?php include 'footer.php'; ?>