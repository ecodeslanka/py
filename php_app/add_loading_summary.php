<?php
include 'config.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $sales_rep_id = !empty($_POST['sales_rep_id']) ? intval($_POST['sales_rep_id']) : NULL;
    $rep_code = mysqli_real_escape_string($conn, $_POST['rep_code']);
    $cc_id = !empty($_POST['cc_id']) ? intval($_POST['cc_id']) : NULL;
    $number_of_porters = intval($_POST['number_of_porters']);
    $lorry_id = !empty($_POST['lorry_id']) ? intval($_POST['lorry_id']) : NULL;
    $delivery_date = mysqli_real_escape_string($conn, $_POST['delivery_date']);
    $no_of_bills = intval($_POST['no_of_bills']);
    $no_of_lines = intval($_POST['no_of_lines']);
    $outlet_count = intval($_POST['outlet_count']);
    
    // Quantity fields
    $billed_qty_cs = floatval($_POST['billed_qty_cs']);
    $billed_qty_pcs = floatval($_POST['billed_qty_pcs']);
    $free_units = floatval($_POST['free_units']);
    $total_qty_cs = floatval($_POST['total_qty_cs']);
    $total_qty_pcs = floatval($_POST['total_qty_pcs']);
    
    // Amount fields
    $grand_total = floatval($_POST['grand_total']);
    $scheme_discount = floatval($_POST['scheme_discount']);
    $retail_discount = floatval($_POST['retail_discount']);
    $market_return = floatval($_POST['market_return']);
    $damage = floatval($_POST['damage']);
    $net_value = floatval($_POST['net_value']);
    
    // Adjustment fields
    $scheme_discount_adj = floatval($_POST['scheme_discount_adj']);
    $retail_discount_adj = floatval($_POST['retail_discount_adj']);
    $market_return_adj = floatval($_POST['market_return_adj']);
    $damage_adj = floatval($_POST['damage_adj']);
    $cancel_bill_value = floatval($_POST['cancel_bill_value']);
    $adjust_net_value = floatval($_POST['adjust_net_value']);
    
    // Calculated fields
    $ikea_value = floatval($_POST['ikea_value']);
    $cash_short = floatval($_POST['cash_short']);
    
    $created_by = 1; // Replace with actual user ID from session
    
    $sql = "INSERT INTO loading_summaries (
        sales_rep_id, rep_code, cc_id, number_of_porters, lorry_id, delivery_date,
        no_of_bills, no_of_lines, outlet_count, billed_qty_cs, billed_qty_pcs,
        free_units, total_qty_cs, total_qty_pcs, grand_total, scheme_discount,
        retail_discount, market_return, damage, net_value, scheme_discount_adj,
        retail_discount_adj, market_return_adj, damage_adj, cancel_bill_value,
        adjust_net_value, ikea_value, cash_short, created_by
    ) VALUES (
        " . ($sales_rep_id ? $sales_rep_id : "NULL") . ", '$rep_code', 
        " . ($cc_id ? $cc_id : "NULL") . ", $number_of_porters, 
        " . ($lorry_id ? $lorry_id : "NULL") . ", '$delivery_date',
        $no_of_bills, $no_of_lines, $outlet_count, $billed_qty_cs, $billed_qty_pcs,
        $free_units, $total_qty_cs, $total_qty_pcs, $grand_total, $scheme_discount,
        $retail_discount, $market_return, $damage, $net_value, $scheme_discount_adj,
        $retail_discount_adj, $market_return_adj, $damage_adj, $cancel_bill_value,
        $adjust_net_value, $ikea_value, $cash_short, $created_by
    )";
    
    if (mysqli_query($conn, $sql)) {
        header('Location: loading_summaries.php?success=1');
        exit;
    } else {
        $error_message = "Error: " . mysqli_error($conn);
    }
}

// Get Sales Reps (employees with designation code 'SR')
$sales_reps_sql = "SELECT e.id, e.employee_id, e.employee_full_name, e.custom_code 
                   FROM employees e
                   JOIN designations d ON e.designation_id = d.id
                   WHERE d.designation_code = 'SR' AND e.status != 'Resigned'
                   ORDER BY e.employee_full_name";
$sales_reps_result = mysqli_query($conn, $sales_reps_sql);

// Get CC employees (employees with designation code 'CC')
$cc_sql = "SELECT e.id, e.employee_id, e.employee_full_name, e.custom_code 
           FROM employees e
           JOIN designations d ON e.designation_id = d.id
           WHERE d.designation_code = 'CC' AND e.status != 'Resigned'
           ORDER BY e.employee_full_name";
$cc_result = mysqli_query($conn, $cc_sql);

// Get Vehicles/Lorries
$vehicles_sql = "SELECT id, vehicle_number FROM vehicles WHERE active = 1 ORDER BY vehicle_number";
$vehicles_result = mysqli_query($conn, $vehicles_sql);

include 'header.php';
?>

<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<div class="page-header">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 class="page-title">Add Loading Summary</h2>
            <p class="page-subtitle">Enter loading summary details</p>
        </div>
        <a href="loading_summaries.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i>
            Back to List
        </a>
    </div>
</div>

<?php if (isset($error_message)): ?>
<div class="alert alert-error">
    <i class="fa-solid fa-circle-exclamation"></i>
    <?php echo $error_message; ?>
</div>
<?php endif; ?>

<form method="POST" action="" id="loadingForm">
    
    <!-- Selection Fields -->
    <div class="content-card">
        <h3 class="card-title">
            <i class="fa-solid fa-user-tie"></i> Selection Details
        </h3>
        
        <div class="form-row">
            <div class="form-group required-field">
                <label class="form-label">Sales Rep <span class="required">*</span></label>
                <select name="sales_rep_id" id="sales_rep_id" class="form-input select2" required onchange="updateRepCode()">
                    <option value="">Select Sales Rep</option>
                    <?php while ($rep = mysqli_fetch_assoc($sales_reps_result)): ?>
                        <option value="<?php echo $rep['id']; ?>" 
                                data-code="<?php echo htmlspecialchars($rep['custom_code']); ?>">
                            <?php echo htmlspecialchars($rep['employee_full_name']) . ' (' . $rep['employee_id'] . ')'; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Rep Code</label>
                <input type="text" name="rep_code" id="rep_code" class="form-input" readonly 
                       placeholder="Auto-filled from custom code">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group required-field">
                <label class="form-label">CC <span class="required">*</span></label>
                <select name="cc_id" id="cc_id" class="form-input select2" required>
                    <option value="">Select CC</option>
                    <?php while ($cc = mysqli_fetch_assoc($cc_result)): ?>
                        <option value="<?php echo $cc['id']; ?>">
                            <?php echo htmlspecialchars($cc['employee_full_name']) . ' (' . ($cc['custom_code'] ?: $cc['employee_id']) . ')'; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="form-group required-field">
                <label class="form-label">Number of Porters <span class="required">*</span></label>
                <input type="number" name="number_of_porters" class="form-input" value="0" min="0" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group required-field">
                <label class="form-label">Lorry <span class="required">*</span></label>
                <select name="lorry_id" id="lorry_id" class="form-input select2" required>
                    <option value="">Select Lorry</option>
                    <?php while ($vehicle = mysqli_fetch_assoc($vehicles_result)): ?>
                        <option value="<?php echo $vehicle['id']; ?>">
                            <?php echo htmlspecialchars($vehicle['vehicle_number']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="form-group required-field">
                <label class="form-label">Delivery Date <span class="required">*</span></label>
                <input type="date" name="delivery_date" class="form-input" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">No of Bills</label>
                <input type="number" name="no_of_bills" class="form-input" value="0" min="0">
            </div>

            <div class="form-group">
                <label class="form-label">No of Lines</label>
                <input type="number" name="no_of_lines" class="form-input" value="0" min="0">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Outlet Count</label>
            <input type="number" name="outlet_count" class="form-input" value="0" min="0">
        </div>
    </div>

    <!-- Billed Qty -->
    <div class="content-card">
        <h3 class="card-title">
            <i class="fa-solid fa-box"></i> Billed Qty
        </h3>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">CS</label>
                <input type="number" name="billed_qty_cs" id="billed_qty_cs" class="form-input calc-field" 
                       value="0" step="0.01" oninput="calculateTotals()">
            </div>

            <div class="form-group">
                <label class="form-label">PCS</label>
                <input type="number" name="billed_qty_pcs" id="billed_qty_pcs" class="form-input calc-field" 
                       value="0" step="0.01" oninput="calculateTotals()">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Free Units</label>
            <input type="number" name="free_units" id="free_units" class="form-input calc-field" 
                   value="0" step="0.01" oninput="calculateTotals()">
        </div>
    </div>

    <!-- Total Qty -->
    <div class="content-card highlight-green">
        <h3 class="card-title">
            <i class="fa-solid fa-calculator"></i> Total Qty
        </h3>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">CS</label>
                <input type="number" name="total_qty_cs" id="total_qty_cs" class="form-input" 
                       value="0" step="0.01" readonly>
            </div>

            <div class="form-group">
                <label class="form-label">PCS</label>
                <input type="number" name="total_qty_pcs" id="total_qty_pcs" class="form-input" 
                       value="0" step="0.01" readonly>
            </div>
        </div>
    </div>

    <!-- Amounts -->
    <div class="content-card highlight-green">
        <h3 class="card-title">
            <i class="fa-solid fa-money-bill"></i> Grand Total/Gross Amt
        </h3>
        
        <div class="form-group">
            <input type="number" name="grand_total" id="grand_total" class="form-input amount-field" 
                   value="0" step="0.01" oninput="calculateNetValue()">
        </div>
    </div>

    <div class="content-card">
        <h3 class="card-title">Scheme Discount</h3>
        <div class="form-group">
            <input type="number" name="scheme_discount" id="scheme_discount" class="form-input amount-field" 
                   value="0" step="0.01" oninput="calculateNetValue()">
        </div>
    </div>

    <div class="content-card">
        <h3 class="card-title">Retail/RS/Cash Discount</h3>
        <div class="form-group">
            <input type="number" name="retail_discount" id="retail_discount" class="form-input amount-field" 
                   value="0" step="0.01" oninput="calculateNetValue()">
        </div>
    </div>

    <div class="content-card">
        <h3 class="card-title">Market Return</h3>
        <div class="form-group">
            <input type="number" name="market_return" id="market_return" class="form-input amount-field" 
                   value="0" step="0.01" oninput="calculateNetValue()">
        </div>
    </div>

    <div class="content-card">
        <h3 class="card-title">Damage</h3>
        <div class="form-group">
            <input type="number" name="damage" id="damage" class="form-input amount-field" 
                   value="0" step="0.01" oninput="calculateNetValue()">
        </div>
    </div>

    <div class="content-card highlight-green">
        <h3 class="card-title">
            <i class="fa-solid fa-sack-dollar"></i> Net Value
        </h3>
        <div class="form-group">
            <input type="number" name="net_value" id="net_value" class="form-input amount-field" 
                   value="0" step="0.01" readonly>
        </div>
    </div>

    <!-- Adjustments -->
    <div class="content-card">
        <h3 class="card-title">
            <i class="fa-solid fa-sliders"></i> Adjustments
        </h3>
        
        <div class="form-group">
            <label class="form-label">Scheme Discount Adjsement</label>
            <input type="number" name="scheme_discount_adj" id="scheme_discount_adj" class="form-input" 
                   value="0" step="0.01" oninput="calculateAdjustNetValue()">
        </div>

        <div class="form-group">
            <label class="form-label">Retail/RS/Cash Discost Adjsement</label>
            <input type="number" name="retail_discount_adj" id="retail_discount_adj" class="form-input" 
                   value="0" step="0.01" oninput="calculateAdjustNetValue()">
        </div>

        <div class="form-group">
            <label class="form-label">Market Return</label>
            <input type="number" name="market_return_adj" id="market_return_adj" class="form-input" 
                   value="0" step="0.01" oninput="calculateAdjustNetValue()">
        </div>

        <div class="form-group">
            <label class="form-label">Damage Adjsement</label>
            <input type="number" name="damage_adj" id="damage_adj" class="form-input" 
                   value="0" step="0.01" oninput="calculateAdjustNetValue()">
        </div>

        <div class="form-group">
            <label class="form-label">Cancel Bill Value</label>
            <input type="number" name="cancel_bill_value" id="cancel_bill_value" class="form-input" 
                   value="0" step="0.01" oninput="calculateAdjustNetValue()">
        </div>
    </div>

    <div class="content-card highlight-green">
        <h3 class="card-title">Adjest Net Value</h3>
        <div class="form-group">
            <input type="number" name="adjust_net_value" id="adjust_net_value" class="form-input amount-field" 
                   value="0" step="0.01" readonly>
        </div>
    </div>

    <div class="content-card highlight-gray">
        <h3 class="card-title">Ikea Value</h3>
        <div class="form-group">
            <input type="number" name="ikea_value" id="ikea_value" class="form-input amount-field" 
                   value="0" step="0.01" oninput="calculateCashShort()">
        </div>
    </div>

    <div class="content-card highlight-orange">
        <h3 class="card-title">Cash Short</h3>
        <div class="form-group">
            <input type="number" name="cash_short" id="cash_short" class="form-input amount-field" 
                   value="0" step="0.01" readonly>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="form-actions">
        <a href="loading_summaries.php" class="btn btn-secondary">
            <i class="fa-solid fa-xmark"></i>
            Cancel
        </a>
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-check"></i>
            Save Loading Summary
        </button>
    </div>
</form>

<style>
/* Content Card */
.content-card {
    background: #ffffff;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
}

.card-title {
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 12px;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 8px;
}

.card-title i {
    color: #6b7280;
    font-size: 12px;
}

/* Highlight Cards */
.highlight-green {
    background: #f0fdf4;
    border-color: #86efac;
}

.highlight-green .card-title {
    color: #166534;
}

.highlight-gray {
    background: #f9fafb;
    border-color: #d1d5db;
}

.highlight-orange {
    background: #fff7ed;
    border-color: #fed7aa;
}

.highlight-orange .card-title {
    color: #9a3412;
}

/* Form Styles */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 12px;
}

.form-group {
    margin-bottom: 12px;
}

.form-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    margin-bottom: 6px;
    color: #374151;
}

.form-input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #e5e5e5;
    border-radius: 6px;
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    transition: all 0.3s;
}

.form-input:focus {
    outline: none;
    border-color: #000000;
    box-shadow: 0 0 0 2px rgba(0,0,0,0.05);
}

.form-input[readonly] {
    background-color: #f9fafb;
    color: #6b7280;
}

.required-field .form-input,
.required-field .select2-container--default .select2-selection--single {
    background-color: #fffbeb !important;
}

.required {
    color: #ef4444;
}

/* Amount Fields */
.amount-field {
    font-size: 16px;
    font-weight: 600;
    text-align: right;
}

.calc-field {
    background-color: #eff6ff !important;
}

/* Form Actions */
.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 16px;
    padding: 12px;
    background: #f9fafb;
    border-radius: 6px;
}

/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
    font-family: 'Inter', sans-serif;
}

.btn-primary {
    background: #000000;
    color: #ffffff;
}

.btn-primary:hover {
    background: #333333;
}

.btn-secondary {
    background: #f5f5f5;
    color: #333333;
    border: 1px solid #e5e5e5;
}

.btn-secondary:hover {
    background: #e5e5e5;
}

/* Select2 Custom Styling */
.select2-container--default .select2-selection--single {
    height: 34px !important;
    border: 1px solid #e5e5e5 !important;
    border-radius: 6px !important;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 32px !important;
    padding-left: 12px !important;
    font-size: 13px !important;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 32px !important;
}

.select2-container--default.select2-container--focus .select2-selection--single {
    border-color: #000000 !important;
}

/* Alert */
.alert {
    padding: 10px 14px;
    border-radius: 6px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 500;
}

.alert-error {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    $('.select2').select2({
        width: '100%'
    });
});

function updateRepCode() {
    const selectedOption = $('#sales_rep_id option:selected');
    const customCode = selectedOption.data('code');
    $('#rep_code').val(customCode || '');
}

function calculateTotals() {
    // Calculate Total Qty
    const billedCS = parseFloat($('#billed_qty_cs').val()) || 0;
    const billedPCS = parseFloat($('#billed_qty_pcs').val()) || 0;
    const freeUnits = parseFloat($('#free_units').val()) || 0;
    
    $('#total_qty_cs').val(billedCS.toFixed(2));
    $('#total_qty_pcs').val((billedPCS + freeUnits).toFixed(2));
}

function calculateNetValue() {
    const grandTotal = parseFloat($('#grand_total').val()) || 0;
    const schemeDiscount = parseFloat($('#scheme_discount').val()) || 0;
    const retailDiscount = parseFloat($('#retail_discount').val()) || 0;
    const marketReturn = parseFloat($('#market_return').val()) || 0;
    const damage = parseFloat($('#damage').val()) || 0;
    
    const netValue = grandTotal - schemeDiscount - retailDiscount - marketReturn - damage;
    $('#net_value').val(netValue.toFixed(2));
    
    // Also recalculate adjust net value
    calculateAdjustNetValue();
}

function calculateAdjustNetValue() {
    const netValue = parseFloat($('#net_value').val()) || 0;
    const schemeDiscountAdj = parseFloat($('#scheme_discount_adj').val()) || 0;
    const retailDiscountAdj = parseFloat($('#retail_discount_adj').val()) || 0;
    const marketReturnAdj = parseFloat($('#market_return_adj').val()) || 0;
    const damageAdj = parseFloat($('#damage_adj').val()) || 0;
    const cancelBillValue = parseFloat($('#cancel_bill_value').val()) || 0;
    
    const adjustNetValue = netValue - schemeDiscountAdj - retailDiscountAdj - marketReturnAdj - damageAdj - cancelBillValue;
    $('#adjust_net_value').val(adjustNetValue.toFixed(2));
    
    // Also recalculate cash short
    calculateCashShort();
}

function calculateCashShort() {
    const adjustNetValue = parseFloat($('#adjust_net_value').val()) || 0;
    const ikeaValue = parseFloat($('#ikea_value').val()) || 0;
    
    const cashShort = adjustNetValue - ikeaValue;
    $('#cash_short').val(cashShort.toFixed(2));
}
</script>

<?php include 'footer.php'; ?>