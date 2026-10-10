<?php
include 'config.php';

// ── Ensure loading_summary table exists ─────────────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS loading_summary (
    id                          INT(11) AUTO_INCREMENT PRIMARY KEY,
    delivery_date               DATE NOT NULL,
    sales_person_code           VARCHAR(100) NULL,
    cc_employee_id              INT(11) NULL,
    lorry_id                    INT(11) NULL,
    no_of_ports                 INT(11) DEFAULT 0,
    no_of_bills                 INT(11) DEFAULT 0,
    outlet_count                INT(11) DEFAULT 0,
    gross_invoice_value         DECIMAL(14,2) DEFAULT 0,
    scheme_discount             DECIMAL(14,2) DEFAULT 0,
    cash_discount_rs            DECIMAL(14,2) DEFAULT 0,
    cash_discount_tot           DECIMAL(14,2) DEFAULT 0,
    market_return_value         DECIMAL(14,2) DEFAULT 0,
    damage_expiry_shortage      DECIMAL(14,2) DEFAULT 0,
    net_invoice_value           DECIMAL(14,2) DEFAULT 0,
    cancelled_bill_value        DECIMAL(14,2) DEFAULT 0,
    adj_scheme_discount         DECIMAL(14,2) DEFAULT 0,
    adj_cash_discount_rs        DECIMAL(14,2) DEFAULT 0,
    adj_cash_discount_tot       DECIMAL(14,2) DEFAULT 0,
    adj_market_return           DECIMAL(14,2) DEFAULT 0,
    adj_damage_expiry_shortage  DECIMAL(14,2) DEFAULT 0,
    final_bill_value            DECIMAL(14,2) DEFAULT 0,
    secondary_invoice_value     DECIMAL(14,2) DEFAULT 0,
    over_under_charge           DECIMAL(14,2) DEFAULT 0,
    created_at                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_delivery_date (delivery_date),
    INDEX idx_sales_code (sales_person_code)
)");

// ── Handle GENERATE (POST) ────────────────────────────────────────────────────
$summary      = null;
$error_msg    = '';
$success_msg  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate') {

    $delivery_date     = mysqli_real_escape_string($conn, $_POST['delivery_date']     ?? '');
    $sales_person_code = mysqli_real_escape_string($conn, $_POST['sales_person_code'] ?? '');
    $cc_employee_id    = !empty($_POST['cc_employee_id'])  ? intval($_POST['cc_employee_id'])  : null;
    $lorry_id          = !empty($_POST['lorry_id'])        ? intval($_POST['lorry_id'])        : null;
    $no_of_ports       = intval($_POST['no_of_ports']      ?? 0);

    if (empty($delivery_date) || empty($sales_person_code)) {
        $error_msg = 'Delivery date and Sales Representative are required.';
    } else {

        // ── Aggregate from import details for this date + sales code ───────
        $agg_sql = "
            SELECT
                COUNT(DISTINCT bill_no)                         AS no_of_bills,
                COUNT(DISTINCT outlet_code)                     AS outlet_count,
                COALESCE(SUM(gross_sales),         0)           AS gross_invoice_value,
                COALESCE(SUM(scheme_disc),         0)           AS scheme_discount,
                COALESCE(SUM(rs_discount),         0)           AS cash_discount_rs,
                COALESCE(SUM(tot_disc),            0)           AS cash_discount_tot,
                COALESCE(SUM(good_returns_value),  0)           AS market_return_value,
                COALESCE(SUM(damage_expiry_shortage_value), 0)  AS damage_expiry_shortage,
                COALESCE(SUM(final_bill_amount),   0)           AS final_bill_value
            FROM loading_summary_import_details
            WHERE delivery_date = '$delivery_date'
              AND sales_person_code = '$sales_person_code'
              AND status = 'imported'
        ";
        $agg_result = mysqli_query($conn, $agg_sql);
        $agg = mysqli_fetch_assoc($agg_result);

        if (!$agg || ($agg['no_of_bills'] == 0 && $agg['gross_invoice_value'] == 0)) {
            $error_msg = 'No imported records found for the selected delivery date and sales representative.';
        } else {
            // Compute derived fields
            $gross          = floatval($agg['gross_invoice_value']);
            $scheme         = floatval($agg['scheme_discount']);
            $cash_rs        = floatval($agg['cash_discount_rs']);
            $cash_tot       = floatval($agg['cash_discount_tot']);
            $mkt_return     = floatval($agg['market_return_value']);
            $dmg            = floatval($agg['damage_expiry_shortage']);
            $final_bill     = floatval($agg['final_bill_value']);

            $net_invoice    = $gross - $scheme - $cash_rs - $cash_tot - $mkt_return - $dmg;
            $secondary_inv  = $final_bill; // same as final bill (adjust if business logic differs)
            $over_under     = $final_bill - $net_invoice;

            // Delete existing summary for same date+salescode to allow re-generate
            mysqli_query($conn, "DELETE FROM loading_summary 
                                 WHERE delivery_date='$delivery_date' 
                                   AND sales_person_code='$sales_person_code'");

            $cc_val   = $cc_employee_id ? $cc_employee_id : 'NULL';
            $lorr_val = $lorry_id       ? $lorry_id       : 'NULL';

            $ins = "INSERT INTO loading_summary (
                        delivery_date, sales_person_code, cc_employee_id, lorry_id, no_of_ports,
                        no_of_bills, outlet_count,
                        gross_invoice_value, scheme_discount, cash_discount_rs, cash_discount_tot,
                        market_return_value, damage_expiry_shortage, net_invoice_value,
                        cancelled_bill_value,
                        adj_scheme_discount, adj_cash_discount_rs, adj_cash_discount_tot,
                        adj_market_return, adj_damage_expiry_shortage,
                        final_bill_value, secondary_invoice_value, over_under_charge
                    ) VALUES (
                        '$delivery_date', '$sales_person_code', $cc_val, $lorr_val, $no_of_ports,
                        {$agg['no_of_bills']}, {$agg['outlet_count']},
                        $gross, $scheme, $cash_rs, $cash_tot,
                        $mkt_return, $dmg, $net_invoice,
                        0,
                        0, 0, 0,
                        0, 0,
                        $final_bill, $secondary_inv, $over_under
                    )";

            if (mysqli_query($conn, $ins)) {
                $summary_id  = mysqli_insert_id($conn);
                $success_msg = 'Loading summary generated successfully!';

                // Re-fetch for display
                $fetch = mysqli_query($conn, "SELECT ls.*, 
                                                CONCAT(e.employee_full_name) AS cc_name,
                                                v.vehicle_number
                                             FROM loading_summary ls
                                             LEFT JOIN employees e ON ls.cc_employee_id = e.id
                                             LEFT JOIN vehicles  v ON ls.lorry_id = v.id
                                             WHERE ls.id = $summary_id");
                $summary = mysqli_fetch_assoc($fetch);
            } else {
                $error_msg = 'Database error: ' . mysqli_error($conn);
            }
        }
    }
}

// ── Dropdowns ─────────────────────────────────────────────────────────────────

// Sales reps loaded dynamically via AJAX after delivery date is selected
// On POST, re-query for the posted date so the selected value is retained
$sales_reps_result = null;
if (!empty($_POST['delivery_date'])) {
    $pd = mysqli_real_escape_string($conn, $_POST['delivery_date']);
    $sales_reps_result = mysqli_query($conn, "SELECT DISTINCT sales_person_code 
                                              FROM loading_summary_import_details 
                                              WHERE delivery_date = '$pd'
                                                AND sales_person_code != ''
                                                AND status = 'imported'
                                              ORDER BY sales_person_code");
}

// CC employees — based on staff category linked to designations
// We treat staff_category as "CC" if it contains 'CC' in code/name OR we just load all employees
// Adjust the WHERE clause to match your actual CC category code
$cc_employees_sql    = "SELECT e.id, e.employee_id, e.employee_full_name, sc.category_name
                        FROM employees e
                        LEFT JOIN designations d ON e.designation_id = d.id
                        LEFT JOIN staff_categories sc ON d.staff_category_id = sc.id
                        WHERE e.status != 'Inactive'
                        ORDER BY sc.category_name, e.employee_full_name";
$cc_employees_result = mysqli_query($conn, $cc_employees_sql);

// Lorries
$lorries_sql    = "SELECT id, vehicle_number, owner FROM vehicles WHERE active = 1 ORDER BY vehicle_number";
$lorries_result = mysqli_query($conn, $lorries_sql);

include 'header.php';
?>

<!-- Select2 CSS (minified CDN) -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>

<style>
/* ── Page ── */
.page-header { margin-bottom: 24px; }
.page-title  { font-size: 22px; font-weight: 700; color: #1f2937; margin: 0 0 4px; }
.page-subtitle { font-size: 13px; color: #6b7280; margin: 0; }

/* ── Card ── */
.content-card {
    background: #fff;
    border: 1px solid #e5e5e5;
    border-radius: 10px;
    padding: 24px;
    margin-bottom: 24px;
}
.card-title {
    font-size: 15px;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* ── Alerts ── */
.alert {
    padding: 14px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    font-weight: 500;
}
.alert-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.alert-error   { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }

/* ── Form grid ── */
.form-grid   { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 18px; }
.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
.form-group  { margin-bottom: 0; }
.form-label  { display: block; font-size: 12px; font-weight: 700; color: #374151; margin-bottom: 7px; text-transform: uppercase; letter-spacing: .4px; }
.form-input  { width: 100%; padding: 11px 14px; border: 1px solid #e5e5e5; border-radius: 8px; font-size: 14px; font-family: 'Inter', sans-serif; box-sizing: border-box; transition: border-color .2s; }
.form-input:focus { outline: none; border-color: #000; box-shadow: 0 0 0 3px rgba(0,0,0,.05); }
.form-hint   { font-size: 11px; color: #9ca3af; margin-top: 5px; display: block; }

/* ── Buttons ── */
.btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 11px 22px; border: none; border-radius: 8px;
    font-size: 14px; font-weight: 600; cursor: pointer;
    transition: all .25s; font-family: 'Inter', sans-serif;
    text-decoration: none;
}
.btn-primary   { background: #000; color: #fff; }
.btn-primary:hover { background: #333; }
.btn-secondary { background: #f5f5f5; color: #333; border: 1px solid #e5e5e5; }
.btn-secondary:hover { background: #e5e5e5; }
.btn-generate  { background: #000; color: #fff; padding: 12px 32px; font-size: 15px; }
.btn-generate:hover { background: #222; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,.15); }

/* ── Summary Table ── */
.summary-wrapper { margin-top: 8px; }

.summary-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
}
.summary-table tr { border-bottom: 1px solid #f0f0f0; }
.summary-table tr:last-child { border-bottom: none; }

.summary-table td {
    padding: 11px 16px;
    color: #374151;
}
.summary-table td:first-child {
    font-weight: 500;
    color: #4b5563;
    width: 60%;
}
.summary-table td:last-child {
    text-align: right;
    font-weight: 600;
    color: #1f2937;
    font-variant-numeric: tabular-nums;
}

/* Highlighted rows */
.row-highlight {
    background: #f0fdf4 !important;
    border-top: 1px solid #bbf7d0 !important;
    border-bottom: 1px solid #bbf7d0 !important;
}
.row-highlight td:first-child { color: #166534 !important; font-weight: 700 !important; }
.row-highlight td:last-child  { color: #166534 !important; font-size: 15px !important; }

.row-blue {
    background: #eff6ff !important;
    border-top: 1px solid #bfdbfe !important;
    border-bottom: 1px solid #bfdbfe !important;
}
.row-blue td:first-child { color: #1d4ed8 !important; font-weight: 700 !important; }
.row-blue td:last-child  { color: #1d4ed8 !important; font-size: 15px !important; }

.row-orange {
    background: #fff7ed !important;
    border-top: 1px solid #fed7aa !important;
    border-bottom: 1px solid #fed7aa !important;
}
.row-orange td:first-child { color: #c2410c !important; font-weight: 700 !important; }
.row-orange td:last-child  { color: #c2410c !important; font-size: 15px !important; }

.row-section-header td {
    background: #fafafa;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .6px;
    color: #9ca3af !important;
    padding: 8px 16px !important;
    border-bottom: 2px solid #e5e5e5 !important;
}
.row-section-header td:last-child { text-align: right; }

.dash-value { color: #9ca3af !important; font-weight: 400 !important; }

/* ── Summary meta info bar ── */
.meta-bar {
    display: flex;
    gap: 32px;
    flex-wrap: wrap;
    padding: 14px 0 20px;
    border-bottom: 1px solid #f0f0f0;
    margin-bottom: 18px;
}
.meta-item { display: flex; flex-direction: column; gap: 3px; }
.meta-label { font-size: 11px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: .5px; }
.meta-value { font-size: 14px; font-weight: 600; color: #1f2937; }

/* ── Select2 theme fix ── */
.select2-container--default .select2-selection--single {
    height: 44px !important; border: 1px solid #e5e5e5 !important;
    border-radius: 8px !important;
}
.select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 42px !important; padding-left: 14px !important; font-size: 14px !important; }
.select2-container--default .select2-selection--single .select2-selection__arrow    { height: 42px !important; }
.select2-container--default.select2-container--focus .select2-selection--single     { border-color: #000 !important; box-shadow: 0 0 0 3px rgba(0,0,0,.05) !important; }
.select2-dropdown { border: 1px solid #e5e5e5 !important; border-radius: 8px !important; }
.select2-container--default .select2-results__option--highlighted { background: #000 !important; }

/* ── Responsive ── */
@media (max-width: 900px) {
    .form-grid   { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 600px) {
    .form-grid, .form-grid-2 { grid-template-columns: 1fr; }
    .meta-bar { gap: 16px; }
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title"><i class="fa-solid fa-file-invoice"></i> Generate Loading Summary</h2>
            <p class="page-subtitle">Create a loading summary from imported sales data</p>
        </div>
        <a href="import_loading_summary.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i> Back
        </a>
    </div>
</div>

<!-- Alerts -->
<?php if ($success_msg): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i> <?php echo $success_msg; ?>
</div>
<?php endif; ?>
<?php if ($error_msg): ?>
<div class="alert alert-error">
    <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error_msg); ?>
</div>
<?php endif; ?>

<!-- ── Filter / Generate Form ── -->
<div class="content-card">
    <h3 class="card-title"><i class="fa-solid fa-sliders"></i> Summary Parameters</h3>

    <form method="POST" action="" id="generateForm">
        <input type="hidden" name="action" value="generate">

        <!-- Row 1: Delivery Date + No of Ports -->
        <div class="form-grid-2" style="margin-bottom:18px;">
            <div class="form-group">
                <label class="form-label">Delivery Date <span style="color:#ef4444;">*</span></label>
                <input type="date" name="delivery_date" id="delivery_date" class="form-input" required
                       value="<?php echo isset($_POST['delivery_date']) ? htmlspecialchars($_POST['delivery_date']) : ''; ?>">
                <span class="form-hint">Select date first — sales reps will load automatically</span>
            </div>
            <div class="form-group">
                <label class="form-label">No. of Ports / Routes Number</label>
                <input type="number" name="no_of_ports" class="form-input"
                       min="0" placeholder="0"
                       value="<?php echo isset($_POST['no_of_ports']) ? intval($_POST['no_of_ports']) : ''; ?>">
            </div>
        </div>

        <!-- Row 2: Sales Rep + CC + Lorry -->
        <div class="form-grid" style="margin-bottom:24px;">

            <!-- Sales Representative — populated via AJAX after date is chosen -->
            <div class="form-group">
                <label class="form-label">Sales Representative <span style="color:#ef4444;">*</span></label>
                <select name="sales_person_code" id="sales_person_code" class="form-input sel2" required>
                    <?php if ($sales_reps_result && mysqli_num_rows($sales_reps_result) > 0): ?>
                        <option value="">— Select Sales Rep —</option>
                        <?php while ($sr = mysqli_fetch_assoc($sales_reps_result)): ?>
                        <option value="<?php echo htmlspecialchars($sr['sales_person_code']); ?>"
                            <?php echo (isset($_POST['sales_person_code']) && $_POST['sales_person_code'] === $sr['sales_person_code']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sr['sales_person_code']); ?>
                        </option>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <option value="">— Select Date First —</option>
                    <?php endif; ?>
                </select>
                <span class="form-hint" id="sales_rep_hint">Select a delivery date to load sales reps</span>
            </div>

            <!-- CC Employee -->
            <div class="form-group">
                <label class="form-label">CC (Cashier / Checker)</label>
                <select name="cc_employee_id" id="cc_employee_id" class="form-input sel2">
                    <option value="">— Select CC —</option>
                    <?php
                    $current_cat = '';
                    if ($cc_employees_result && mysqli_num_rows($cc_employees_result) > 0):
                        while ($emp = mysqli_fetch_assoc($cc_employees_result)):
                            if ($emp['category_name'] && $emp['category_name'] !== $current_cat):
                                if ($current_cat !== '') echo '</optgroup>';
                                echo '<optgroup label="' . htmlspecialchars($emp['category_name']) . '">';
                                $current_cat = $emp['category_name'];
                            endif;
                    ?>
                    <option value="<?php echo $emp['id']; ?>"
                        <?php echo (isset($_POST['cc_employee_id']) && $_POST['cc_employee_id'] == $emp['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($emp['employee_id'] . ' — ' . $emp['employee_full_name']); ?>
                    </option>
                    <?php
                        endwhile;
                        if ($current_cat !== '') echo '</optgroup>';
                    endif;
                    ?>
                </select>
                <span class="form-hint">Grouped by staff category</span>
            </div>

            <!-- Lorry -->
            <div class="form-group">
                <label class="form-label">Lorry / Vehicle</label>
                <select name="lorry_id" id="lorry_id" class="form-input sel2">
                    <option value="">— Select Lorry —</option>
                    <?php
                    if ($lorries_result && mysqli_num_rows($lorries_result) > 0):
                        while ($v = mysqli_fetch_assoc($lorries_result)):
                    ?>
                    <option value="<?php echo $v['id']; ?>"
                        <?php echo (isset($_POST['lorry_id']) && $_POST['lorry_id'] == $v['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($v['vehicle_number'] . ($v['owner'] ? ' — ' . $v['owner'] : '')); ?>
                    </option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
        </div>

        <!-- Generate Button -->
        <div style="display:flex;justify-content:flex-end;">
            <button type="submit" class="btn btn-generate">
                <i class="fa-solid fa-bolt"></i> Generate Summary
            </button>
        </div>
    </form>
</div>

<!-- ── Summary Output ── -->
<?php if ($summary): ?>
<div class="content-card" id="summaryOutput">
    <h3 class="card-title"><i class="fa-solid fa-file-invoice-dollar"></i> Loading Summary</h3>

    <!-- Meta bar -->
    <div class="meta-bar">
        <div class="meta-item">
            <span class="meta-label">Delivery Date</span>
            <span class="meta-value"><?php echo date('d M Y', strtotime($summary['delivery_date'])); ?></span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Sales Code</span>
            <span class="meta-value"><?php echo htmlspecialchars($summary['sales_person_code']); ?></span>
        </div>
        <?php if (!empty($summary['cc_name'])): ?>
        <div class="meta-item">
            <span class="meta-label">CC</span>
            <span class="meta-value"><?php echo htmlspecialchars($summary['cc_name']); ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($summary['vehicle_number'])): ?>
        <div class="meta-item">
            <span class="meta-label">Lorry</span>
            <span class="meta-value"><?php echo htmlspecialchars($summary['vehicle_number']); ?></span>
        </div>
        <?php endif; ?>
        <?php if ($summary['no_of_ports'] > 0): ?>
        <div class="meta-item">
            <span class="meta-label">No. of Ports</span>
            <span class="meta-value"><?php echo number_format($summary['no_of_ports']); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Summary table -->
    <div class="summary-wrapper">
        <table class="summary-table">

            <!-- Counts -->
            <tr class="row-section-header">
                <td>Description</td>
                <td>Value</td>
            </tr>
            <tr>
                <td>No of Bills</td>
                <td><?php echo number_format($summary['no_of_bills']); ?></td>
            </tr>
            <tr>
                <td>Outlet Count</td>
                <td><?php echo number_format($summary['outlet_count']); ?></td>
            </tr>

            <!-- Gross & Discounts -->
            <tr class="row-section-header">
                <td colspan="2">Invoice Breakdown</td>
            </tr>
            <tr class="row-highlight">
                <td>Gross Invoice Value</td>
                <td><?php echo number_format($summary['gross_invoice_value'], 2); ?></td>
            </tr>
            <tr>
                <td>Scheme Discount</td>
                <td><?php echo number_format($summary['scheme_discount'], 2); ?></td>
            </tr>
            <tr>
                <td>Cash Discount (RS)</td>
                <td><?php echo $summary['cash_discount_rs'] != 0 ? number_format($summary['cash_discount_rs'], 2) : '<span class="dash-value">-</span>'; ?></td>
            </tr>
            <tr>
                <td>Cash Discount (TOT)</td>
                <td><?php echo $summary['cash_discount_tot'] != 0 ? number_format($summary['cash_discount_tot'], 2) : '<span class="dash-value">-</span>'; ?></td>
            </tr>
            <tr>
                <td>Market Return Value</td>
                <td><?php echo $summary['market_return_value'] != 0 ? number_format($summary['market_return_value'], 2) : '<span class="dash-value">-</span>'; ?></td>
            </tr>
            <tr>
                <td>Damage / Expiry / Shortage</td>
                <td><?php echo number_format($summary['damage_expiry_shortage'], 2); ?></td>
            </tr>
            <tr class="row-blue">
                <td>Net Invoice Value</td>
                <td><?php echo number_format($summary['net_invoice_value'], 2); ?></td>
            </tr>

            <!-- Adjustments -->
            <tr class="row-section-header">
                <td colspan="2">Adjustments</td>
            </tr>
            <tr>
                <td>Cancelled Bill Value</td>
                <td><?php echo number_format($summary['cancelled_bill_value'], 2); ?></td>
            </tr>
            <tr>
                <td>Adjustment — Scheme Discount</td>
                <td><?php echo number_format($summary['adj_scheme_discount'], 2); ?></td>
            </tr>
            <tr>
                <td>Adjustment — Cash Discount (RS)</td>
                <td><?php echo number_format($summary['adj_cash_discount_rs'], 2); ?></td>
            </tr>
            <tr>
                <td>Adjustment — Cash Discount (TOT)</td>
                <td><?php echo number_format($summary['adj_cash_discount_tot'], 2); ?></td>
            </tr>
            <tr>
                <td>Adjustment — Market Return</td>
                <td><?php echo number_format($summary['adj_market_return'], 2); ?></td>
            </tr>
            <tr>
                <td>Adjustment — Damage / Expiry / Shortage</td>
                <td><?php echo number_format($summary['adj_damage_expiry_shortage'], 2); ?></td>
            </tr>

            <!-- Finals -->
            <tr class="row-section-header">
                <td colspan="2">Final Totals</td>
            </tr>
            <tr class="row-highlight">
                <td>Final Bill Value — To be Collected</td>
                <td><?php echo number_format($summary['final_bill_value'], 2); ?></td>
            </tr>
            <tr class="row-blue">
                <td>Secondary Invoice Value</td>
                <td><?php echo number_format($summary['secondary_invoice_value'], 2); ?></td>
            </tr>
            <tr class="row-orange">
                <td>Over / Under Charge</td>
                <td><?php echo number_format($summary['over_under_charge'], 2); ?></td>
            </tr>

        </table>
    </div>

    <!-- Print button -->
    <div style="display:flex;justify-content:flex-end;margin-top:20px;gap:12px;">
        <button onclick="window.print()" class="btn btn-secondary">
            <i class="fa-solid fa-print"></i> Print
        </button>
        <a href="loading_summary_list.php" class="btn btn-primary">
            <i class="fa-solid fa-list"></i> View All Summaries
        </a>
    </div>
</div>
<?php endif; ?>

<!-- jQuery + Select2 JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function () {
    $('.sel2').select2({ width: '100%' });

    // When delivery date changes, load matching sales rep codes via AJAX
    $('#delivery_date').on('change', function () {
        var date = $(this).val();
        var $sel  = $('#sales_person_code');

        if (!date) {
            $sel.html('<option value="">— Select Date First —</option>').trigger('change');
            $('#sales_rep_hint').text('Select a delivery date to load sales reps');
            return;
        }

        $sel.html('<option value="">Loading...</option>').trigger('change');
        $('#sales_rep_hint').text('Loading...');

        $.ajax({
            url: 'get_sales_reps_by_date.php',
            type: 'POST',
            data: { delivery_date: date },
            dataType: 'json',
            success: function (res) {
                $sel.empty().append('<option value="">— Select Sales Rep —</option>');
                if (res.success && res.data.length > 0) {
                    $.each(res.data, function (i, code) {
                        $sel.append('<option value="' + code + '">' + code + '</option>');
                    });
                    $('#sales_rep_hint').text(res.data.length + ' sales rep(s) found for this date');
                } else {
                    $sel.append('<option value="" disabled>No imported data for this date</option>');
                    $('#sales_rep_hint').text('No imported records found for this date');
                }
                $sel.trigger('change');
            },
            error: function () {
                $sel.html('<option value="">— Error loading —</option>').trigger('change');
                $('#sales_rep_hint').text('Error loading sales reps');
            }
        });
    });
});

<?php if ($summary): ?>
// Scroll to summary on page load after generation
document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('summaryOutput').scrollIntoView({ behavior: 'smooth', block: 'start' });
});
<?php endif; ?>
</script>

<!-- Print styles -->
<style>
@media print {
    .page-header, form, .btn, .btn-generate, nav, header, footer { display: none !important; }
    .content-card { border: none; padding: 0; }
    .summary-table { font-size: 12px; }
}
</style>

<?php include 'footer.php'; ?>