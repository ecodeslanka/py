<?php
include 'config.php';

/**
 * Ensures newer columns used by the customer form actually exist in the
 * `customers` table. If any are missing, they are added automatically.
 * This prevents "Unknown column" fatal errors when new fields are
 * introduced in the form but the DB schema hasn't been migrated yet.
 */
function ensureCustomerColumns($conn) {
    $columns = [
        'is_sampath_customer'         => "TINYINT(1) NOT NULL DEFAULT 0",
        'sampath_outlet_code'         => "VARCHAR(50) NULL",
        'sampath_outlet_name'         => "VARCHAR(150) NULL",
        'cheques_will_delay'          => "TINYINT(1) NOT NULL DEFAULT 0",
        'special_credit_policy_days'  => "VARCHAR(100) NULL",
        'payee_name'                  => "VARCHAR(150) NULL",
    ];

    $existing = [];
    $result = mysqli_query($conn, "SHOW COLUMNS FROM customers");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $existing[] = $row['Field'];
        }
    }

    foreach ($columns as $col => $definition) {
        if (!in_array($col, $existing, true)) {
            $alter = "ALTER TABLE customers ADD COLUMN `$col` $definition";
            if (!mysqli_query($conn, $alter)) {
                // Log rather than die, so a failed ALTER on one column
                // doesn't block the rest of the migration/page load.
                error_log("Failed to add column `$col` to customers: " . mysqli_error($conn));
            }
        }
    }
}

// Run the migration check on every load of this page.
ensureCustomerColumns($conn);

// Create uploads directory
$upload_dir = 'uploads/customers/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Handle file upload
function uploadFile($file, $upload_dir, $prefix = '') {
    if ($file['error'] === UPLOAD_ERR_OK) {
        $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $file_name = $prefix . '_' . time() . '_' . uniqid() . '.' . $file_ext;
        $file_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($file['tmp_name'], $file_path)) {
            return $file_path;
        }
    }
    return '';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $t_code = mysqli_real_escape_string($conn, $_POST['t_code']);
    $shop_name = mysqli_real_escape_string($conn, $_POST['shop_name']);
    $company_id = isset($_POST['company_id']) && !empty($_POST['company_id']) ? intval($_POST['company_id']) : NULL;
    $branch_id = isset($_POST['branch_id']) && !empty($_POST['branch_id']) ? intval($_POST['branch_id']) : NULL;
    $route = mysqli_real_escape_string($conn, $_POST['route'] ?? '');
    $address = mysqli_real_escape_string($conn, $_POST['address']);
    $telephone_number = mysqli_real_escape_string($conn, $_POST['telephone_number']);
    $primary_channel = mysqli_real_escape_string($conn, $_POST['primary_channel']);
    $channel = mysqli_real_escape_string($conn, $_POST['channel']);
    $payment_mode = mysqli_real_escape_string($conn, $_POST['payment_mode']);
    $credit_limit = mysqli_real_escape_string($conn, $_POST['credit_limit']);
    $credit_days = isset($_POST['credit_days']) ? intval($_POST['credit_days']) : 0;
    $special_credit_policy_days = mysqli_real_escape_string($conn, $_POST['special_credit_policy_days'] ?? '');
    $payee_name = mysqli_real_escape_string($conn, $_POST['payee_name'] ?? '');

    // --- Sampath fields ---
    $is_sampath_customer = isset($_POST['is_sampath_customer']) ? 1 : 0;
    $sampath_outlet_code = mysqli_real_escape_string($conn, $_POST['sampath_outlet_code'] ?? '');
    $sampath_outlet_name = mysqli_real_escape_string($conn, $_POST['sampath_outlet_name'] ?? '');

    // --- Cheques Will Delay ---
    $cheques_will_delay = isset($_POST['cheques_will_delay']) ? 1 : 0;

    // Handle file uploads
    $customer_seal = uploadFile($_FILES['customer_seal'], $upload_dir, 'seal');
    $customer_signature = uploadFile($_FILES['customer_signature'], $upload_dir, 'signature');
    
    // Build SQL with proper NULL handling
    $company_sql = $company_id !== NULL ? $company_id : 'NULL';
    $branch_sql = $branch_id !== NULL ? $branch_id : 'NULL';
    
    $sql = "INSERT INTO customers (
        t_code, shop_name, company_id, branch_id, route, address, telephone_number,
        primary_channel, channel, payment_mode, credit_limit,
        credit_days, customer_seal, customer_signature, payee_name,
        is_sampath_customer, sampath_outlet_code, sampath_outlet_name,
        cheques_will_delay, special_credit_policy_days
    ) VALUES (
        '$t_code', '$shop_name', $company_sql, $branch_sql, '$route', '$address', '$telephone_number',
        '$primary_channel', '$channel', '$payment_mode', '$credit_limit',
        '$credit_days', '$customer_seal', '$customer_signature', '$payee_name',
        $is_sampath_customer, '$sampath_outlet_code', '$sampath_outlet_name',
        $cheques_will_delay, '$special_credit_policy_days'
    )";
    
    if (mysqli_query($conn, $sql)) {
        $customer_id = mysqli_insert_id($conn);
        
        // Insert bank accounts
        if (isset($_POST['bank_accounts']) && is_array($_POST['bank_accounts'])) {
            foreach ($_POST['bank_accounts'] as $account) {
                if (!empty($account['account_holder_name']) && !empty($account['account_number'])) {
                    $holder_name = mysqli_real_escape_string($conn, $account['account_holder_name']);
                    $bank_code = mysqli_real_escape_string($conn, $account['bank_code']);
                    $branch_code = mysqli_real_escape_string($conn, $account['branch_code']);
                    $account_no = mysqli_real_escape_string($conn, $account['account_number']);
                    
                    $account_sql = "INSERT INTO customer_bank_accounts (
                        customer_id, account_holder_name, bank_code, branch_code, account_number
                    ) VALUES (
                        $customer_id, '$holder_name', '$bank_code', '$branch_code', '$account_no'
                    )";
                    mysqli_query($conn, $account_sql);
                }
            }
        }
        
        header('Location: customers.php?success=1');
        exit;
    } else {
        $error_message = "Error: " . mysqli_error($conn);
    }
}

// Get banks for dropdown
$banks_sql = "SELECT id, bank_code, bank_name FROM banks WHERE active = 1 ORDER BY bank_name";
$banks_result = mysqli_query($conn, $banks_sql);

include 'header.php';
?>

<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<div class="page-header">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 class="page-title">Add New Customer</h2>
            <p class="page-subtitle">Fill in customer details</p>
        </div>
        <a href="customers.php" class="btn btn-secondary">
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

<form method="POST" action="" id="customerForm" enctype="multipart/form-data">
    
    <!-- Customer Details -->
    <div class="content-card">
        <h3 class="card-title">
            <i class="fa-solid fa-store"></i> Customer Details
        </h3>
        
        <div class="form-row">
            <div class="form-group required-field">
                <label class="form-label">T-Code <span class="required">*</span></label>
                <input type="text" name="t_code" class="form-input" required placeholder="Enter T-Code">
            </div>

            <div class="form-group required-field">
                <label class="form-label">Shop Name <span class="required">*</span></label>
                <input type="text" name="shop_name" class="form-input" required placeholder="Enter shop name">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Company</label>
                <select name="company_id" id="company_id" class="form-input select2" onchange="loadCompanyBranches()">
                    <option value="">Select Company</option>
                    <?php
                    $companies_sql = "SELECT id, company_code, company_name FROM companies WHERE active = 1 ORDER BY company_name";
                    $companies_result = mysqli_query($conn, $companies_sql);
                    while ($company = mysqli_fetch_assoc($companies_result)):
                    ?>
                        <option value="<?php echo $company['id']; ?>">
                            <?php echo htmlspecialchars($company['company_code'] . ' - ' . $company['company_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Branch</label>
                <select name="branch_id" id="branch_id" class="form-input select2">
                    <option value="">Select Branch</option>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Route</label>
                <select name="route" id="route" class="form-input select2">
                    <option value="">Select Route</option>
                    <?php
                    $routes_sql = "SELECT id, route_code, route_name FROM routes WHERE active = 1 ORDER BY route_name";
                    $routes_result = mysqli_query($conn, $routes_sql);
                    while ($route_row = mysqli_fetch_assoc($routes_result)):
                    ?>
                        <option value="<?php echo htmlspecialchars($route_row['route_name']); ?>">
                            <?php echo htmlspecialchars($route_row['route_code'] . ' - ' . $route_row['route_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Telephone Number</label>
                <input type="text" name="telephone_number" class="form-input" placeholder="Enter telephone number">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Address</label>
            <textarea name="address" class="form-input" rows="2" placeholder="Enter full address"></textarea>
        </div>

        <div class="form-row">
            <div class="form-group required-field">
                <label class="form-label">Primary Channel <span class="required">*</span></label>
                <select name="primary_channel" class="form-input select2" required>
                    <option value="">Select Primary Channel</option>
                    <option value="Distributive Grocery">Distributive Grocery</option>
                    <option value="Distributive Other Channels">Distributive Other Channels</option>
                    <option value="Out of Home">Out of Home</option>
                </select>
            </div>

            <div class="form-group required-field">
                <label class="form-label">Channel <span class="required">*</span></label>
                <select name="channel" class="form-input select2" required>
                    <option value="">Select Channel</option>
                    <option value="Book shops and Hardware">Book shops and Hardware</option>
                    <option value="Cosmetic Standard Large">Cosmetic Standard Large</option>
                    <option value="Cosmetic Standard Small">Cosmetic Standard Small</option>
                    <option value="DUMMY">DUMMY</option>
                    <option value="Emergency Top up Upper">Emergency Top up Upper</option>
                    <option value="Estate outlets">Estate outlets</option>
                    <option value="ETUP LARGE">ETUP LARGE</option>
                    <option value="ETUP LITE">ETUP LITE</option>
                    <option value="ETUP MEDIUM">ETUP MEDIUM</option>
                    <option value="ETUP SMALL">ETUP SMALL</option>
                    <option value="Family Grocery Large">Family Grocery Large</option>
                    <option value="Family Grocery Small">Family Grocery Small</option>
                    <option value="INDEPENDENT SUPER MARKER LARGE">INDEPENDENT SUPER MARKER LARGE</option>
                    <option value="INDEPENDENT SUPER MARKER MEDIUM">INDEPENDENT SUPER MARKER MEDIUM</option>
                    <option value="INDEPENDENT SUPER MARKER SMALL">INDEPENDENT SUPER MARKER SMALL</option>
                    <option value="Institutions Forces">Institutions Forces</option>
                    <option value="Institutions Private">Institutions Private</option>
                    <option value="Out of home Others">Out of home Others</option>
                    <option value="Pharmacy General Large">Pharmacy General Large</option>
                    <option value="Pharmacy General Small">Pharmacy General Small</option>
                    <option value="Saubhagya Entrepreneurs">Saubhagya Entrepreneurs</option>
                    <option value="Textile Premium">Textile Premium</option>
                    <option value="Textile Standard">Textile Standard</option>
                    <option value="Wholesale Dominant">Wholesale Dominant</option>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Payee Name</label>
                <input type="text" name="payee_name" class="form-input" placeholder="Enter payee name">
            </div>
        </div>

        <!-- ── Sampath Customer ── -->
        <div class="form-group" style="margin-top:4px;">
            <div class="sampath-checkbox-wrap">
                <label class="sampath-toggle-label">
                    <input type="checkbox" name="is_sampath_customer" id="is_sampath_customer" value="1"
                           onchange="toggleSampathFields(this)">
                    <span class="sampath-checkmark"></span>
                    <span class="sampath-label-text">
                        <img src="https://upload.wikimedia.org/wikipedia/en/thumb/5/52/Sampath_Bank_logo.svg/200px-Sampath_Bank_logo.svg.png"
                             alt="Sampath" class="sampath-bank-logo" onerror="this.style.display='none'">
                        Sampath Customer
                    </span>
                </label>
            </div>
        </div>

        <div id="sampath_fields" class="sampath-fields-panel" style="display:none;">
            <div class="sampath-fields-inner">
                <div class="sampath-fields-header">
                    <i class="fa-solid fa-building-columns"></i> Sampath Outlet Details
                </div>
                <div class="form-row">
                    <div class="form-group required-field">
                        <label class="form-label">Sampath Outlet Code <span class="required">*</span></label>
                        <input type="text" name="sampath_outlet_code" id="sampath_outlet_code"
                               class="form-input" placeholder="Enter Sampath outlet code">
                    </div>
                    <div class="form-group required-field">
                        <label class="form-label">Sampath Outlet Name <span class="required">*</span></label>
                        <input type="text" name="sampath_outlet_name" id="sampath_outlet_name"
                               class="form-input" placeholder="Enter Sampath outlet name">
                    </div>
                </div>
            </div>
        </div>
        <!-- ── /Sampath Customer ── -->

    </div>

    <!-- Payment & Credit Settings -->
    <div class="content-card">
        <h3 class="card-title">
            <i class="fa-solid fa-credit-card"></i> Payment & Credit Settings
        </h3>
        
        <div class="form-row">
            <div class="form-group required-field">
                <label class="form-label">Payment Mode <span class="required">*</span></label>
                <select name="payment_mode" id="payment_mode" class="form-input select2" required>
                    <option value="">Select Payment Mode</option>
                    <option value="cash">Cash</option>
                    <option value="credit">Credit</option>
                    <option value="cheque">Cheque</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Credit Limit (Rs.)</label>
                <input type="number" name="credit_limit" class="form-input" step="0.01" value="0" placeholder="0.00">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Credit Policy Days</label>
                <input type="number" name="credit_days" id="credit_days" class="form-input" min="0" value="0" placeholder="Enter credit policy days">
            </div>

            <div class="form-group">
                <label class="form-label">Special Credit Policy Days</label>
                <input type="text" name="special_credit_policy_days" id="special_credit_policy_days" class="form-input" placeholder="Enter special credit policy days">
            </div>
        </div>

        <!-- ── Cheques Will Delay ── -->
        <div class="form-group" style="margin-top:4px;">
            <div class="sampath-checkbox-wrap">
                <label class="sampath-toggle-label">
                    <input type="checkbox" name="cheques_will_delay" id="cheques_will_delay" value="1">
                    <span class="sampath-checkmark"></span>
                    <span class="sampath-label-text">
                        <i class="fa-solid fa-clock" style="color:#b45309;"></i>
                        Cheques Will Delay
                    </span>
                </label>
            </div>
        </div>
        <!-- ── /Cheques Will Delay ── -->
    </div>

    <!-- Bank Accounts -->
    <div class="content-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 class="card-title" style="margin: 0;">
                <i class="fa-solid fa-building-columns"></i> Bank Accounts
            </h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="addBankAccount()">
                <i class="fa-solid fa-plus"></i>
                Add Bank Account
            </button>
        </div>
        
        <div id="bankAccountsContainer">
            <!-- Bank accounts will be added here dynamically -->
        </div>
    </div>

    <!-- Documents & Signatures -->
    <div class="content-card">
        <h3 class="card-title">
            <i class="fa-solid fa-file-signature"></i> Documents & Signatures
        </h3>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Customer Seal</label>
                <div class="file-upload-wrapper">
                    <input type="file" name="customer_seal" id="customer_seal" class="file-input" accept="image/*" onchange="previewFile(this, 'seal_preview')">
                    <label for="customer_seal" class="file-label">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <span>Click to upload seal image</span>
                        <small>PNG, JPG, GIF up to 10MB</small>
                    </label>
                    <div id="seal_preview" class="file-preview"></div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Customer Signature</label>
                <div class="file-upload-wrapper">
                    <input type="file" name="customer_signature" id="customer_signature" class="file-input" accept="image/*" onchange="previewFile(this, 'signature_preview')">
                    <label for="customer_signature" class="file-label">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <span>Click to upload signature image</span>
                        <small>PNG, JPG, GIF up to 10MB</small>
                    </label>
                    <div id="signature_preview" class="file-preview"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="form-actions">
        <a href="customers.php" class="btn btn-secondary">
            <i class="fa-solid fa-xmark"></i>
            Cancel
        </a>
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-check"></i>
            Save Customer
        </button>
    </div>
</form>

<style>
/* Required field highlight */
.required-field .form-input,
.required-field .select2-container--default .select2-selection--single {
    background-color: #fffbeb !important;
}

.required {
    color: #ef4444;
}

/* Content Card */
.content-card {
    background: #ffffff;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
}

.card-title {
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 12px;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 8px;
}

.card-title i {
    color: #6b7280;
    font-size: 14px;
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

textarea.form-input {
    resize: vertical;
    min-height: 60px;
}

.form-hint {
    display: block;
    font-size: 10px;
    color: #6b7280;
    margin-top: 4px;
}

/* ── Sampath Customer Checkbox (also reused for Cheques Will Delay) ── */
.sampath-checkbox-wrap {
    display: inline-block;
}

.sampath-toggle-label {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    user-select: none;
    padding: 8px 14px;
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    transition: all 0.2s;
}

.sampath-toggle-label:hover {
    border-color: #7c3aed;
    background: #faf5ff;
}

.sampath-toggle-label input[type="checkbox"] {
    display: none;
}

.sampath-checkmark {
    width: 18px;
    height: 18px;
    border: 2px solid #d1d5db;
    border-radius: 4px;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    flex-shrink: 0;
}

.sampath-toggle-label input[type="checkbox"]:checked ~ .sampath-checkmark {
    background: #7c3aed;
    border-color: #7c3aed;
}

.sampath-toggle-label input[type="checkbox"]:checked ~ .sampath-checkmark::after {
    content: '';
    display: block;
    width: 5px;
    height: 9px;
    border: 2px solid #fff;
    border-top: none;
    border-left: none;
    transform: rotate(45deg) translate(-1px, -1px);
}

.sampath-toggle-label input[type="checkbox"]:checked ~ .sampath-label-text {
    color: #5b21b6;
    font-weight: 700;
}

.sampath-label-text {
    font-size: 13px;
    font-weight: 600;
    color: #374151;
    display: flex;
    align-items: center;
    gap: 7px;
    transition: color 0.2s;
}

.sampath-bank-logo {
    height: 18px;
    width: auto;
    object-fit: contain;
}

/* Sampath Fields Panel */
.sampath-fields-panel {
    margin-top: 4px;
    margin-bottom: 4px;
    border-radius: 8px;
    overflow: hidden;
    border: 1.5px solid #ddd6fe;
    animation: sampathSlideDown 0.22s ease;
}

@keyframes sampathSlideDown {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}

.sampath-fields-inner {
    background: #faf5ff;
    padding: 14px 16px 4px 16px;
}

.sampath-fields-header {
    font-size: 12px;
    font-weight: 700;
    color: #6d28d9;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.sampath-fields-panel .form-input {
    background: #fff;
    border-color: #ddd6fe;
}

.sampath-fields-panel .form-input:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 2px rgba(124,58,237,0.08);
}
/* ── /Sampath ── */

/* File Upload */
.file-upload-wrapper {
    position: relative;
}

.file-input {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}

.file-label {
    display: block;
    padding: 24px 16px;
    background: #f9fafb;
    border: 2px dashed #d1d5db;
    border-radius: 6px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
}

.file-label:hover {
    border-color: #000000;
    background: #ffffff;
}

.file-label i {
    display: block;
    font-size: 24px;
    color: #6b7280;
    margin-bottom: 8px;
}

.file-label span {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 2px;
}

.file-label small {
    display: block;
    font-size: 10px;
    color: #9ca3af;
}

.file-preview {
    margin-top: 8px;
    text-align: center;
}

.file-preview img {
    max-width: 150px;
    max-height: 100px;
    border-radius: 6px;
    border: 1px solid #e5e5e5;
}

/* Bank Account Item */
.bank-account-item {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 12px;
    margin-bottom: 12px;
    position: relative;
}

.bank-account-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.bank-account-title {
    font-size: 12px;
    font-weight: 600;
    color: #374151;
}

.btn-remove-account {
    background: #ef4444;
    color: #ffffff;
    border: none;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 11px;
    cursor: pointer;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.btn-remove-account:hover {
    background: #dc2626;
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

.btn-sm {
    padding: 6px 12px;
    font-size: 12px;
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
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
let bankAccountCounter = 0;

$(document).ready(function() {
    // Initialize Select2 for single select
    $('.select2').select2({
        width: '100%'
    });
    
    // Initialize Select2 for route single select
    $('#route').select2({
        width: '100%',
        placeholder: 'Select Route'
    });
});

// ── Sampath toggle ──
function toggleSampathFields(checkbox) {
    const panel = document.getElementById('sampath_fields');
    const codeInput = document.getElementById('sampath_outlet_code');
    const nameInput = document.getElementById('sampath_outlet_name');

    if (checkbox.checked) {
        panel.style.display = 'block';
        codeInput.required = true;
        nameInput.required = true;
    } else {
        panel.style.display = 'none';
        codeInput.required = false;
        nameInput.required = false;
        codeInput.value = '';
        nameInput.value = '';
    }
}

function loadCompanyBranches() {
    const companyId = document.getElementById('company_id').value;
    const branchSelect = document.getElementById('branch_id');
    
    // Clear existing options
    branchSelect.innerHTML = '<option value="">Select Branch</option>';
    
    if (companyId) {
        fetch('get_branches.php?company_id=' + companyId)
            .then(response => response.json())
            .then(data => {
                data.forEach(function(branch) {
                    const option = document.createElement('option');
                    option.value = branch.id;
                    option.textContent = branch.branch_code + ' - ' + branch.branch_name;
                    branchSelect.appendChild(option);
                });
                
                // Reinitialize Select2 on branch select
                $('#branch_id').select2('destroy').select2({ width: '100%' });
            })
            .catch(error => console.error('Error:', error));
    }
}

function addBankAccount() {
    bankAccountCounter++;
    
    const container = document.getElementById('bankAccountsContainer');
    const accountDiv = document.createElement('div');
    accountDiv.className = 'bank-account-item';
    accountDiv.id = 'bank-account-' + bankAccountCounter;
    
    accountDiv.innerHTML = `
        <div class="bank-account-header">
            <span class="bank-account-title"><i class="fa-solid fa-building-columns"></i> Bank Account #${bankAccountCounter}</span>
            <button type="button" class="btn-remove-account" onclick="removeBankAccount(${bankAccountCounter})">
                <i class="fa-solid fa-trash"></i> Remove
            </button>
        </div>
        
        <div class="form-group">
            <label class="form-label">Account Holder Name</label>
            <input type="text" name="bank_accounts[${bankAccountCounter}][account_holder_name]" class="form-input" placeholder="Enter account holder name">
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Bank</label>
                <select name="bank_accounts[${bankAccountCounter}][bank_code]" class="form-input bank-select" data-counter="${bankAccountCounter}" onchange="loadBankBranches(this)">
                    <option value="">Select Bank</option>
                    <?php 
                    mysqli_data_seek($banks_result, 0);
                    while ($bank = mysqli_fetch_assoc($banks_result)): 
                    ?>
                        <option value="<?php echo $bank['bank_code']; ?>">
                            <?php echo htmlspecialchars($bank['bank_code'] . ' - ' . $bank['bank_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Branch</label>
                <select name="bank_accounts[${bankAccountCounter}][branch_code]" id="branch-select-${bankAccountCounter}" class="form-input">
                    <option value="">Select Bank Branch</option>
                </select>
            </div>
        </div>
        
        <div class="form-group">
            <label class="form-label">Account Number</label>
            <input type="text" name="bank_accounts[${bankAccountCounter}][account_number]" class="form-input" placeholder="Enter account number">
        </div>
    `;
    
    container.appendChild(accountDiv);
    
    // Initialize Select2 on new selects
    $(accountDiv).find('.bank-select').select2({ width: '100%' });
}

function removeBankAccount(id) {
    const element = document.getElementById('bank-account-' + id);
    if (element) {
        element.remove();
    }
}

function loadBankBranches(selectElement) {
    const bankCode = selectElement.value;
    const counter = selectElement.getAttribute('data-counter');
    const branchSelect = document.getElementById('branch-select-' + counter);
    
    branchSelect.innerHTML = '<option value="">Select Bank Branch</option>';
    
    if (bankCode) {
        fetch('get_bank_branches.php?bank_code=' + bankCode)
            .then(response => response.json())
            .then(data => {
                data.forEach(function(branch) {
                    const option = document.createElement('option');
                    option.value = branch.branch_code;
                    option.textContent = branch.branch_code + ' - ' + branch.branch_name;
                    branchSelect.appendChild(option);
                });
            })
            .catch(error => console.error('Error:', error));
    }
}

function previewFile(input, previewId) {
    const preview = document.getElementById(previewId);
    const file = input.files[0];
    
    if (file) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
        }
        
        reader.readAsDataURL(file);
    }
}
</script>

<?php include 'footer.php'; ?>