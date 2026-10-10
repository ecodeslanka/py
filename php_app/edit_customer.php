<?php
include 'config.php';

$upload_dir = 'uploads/customers/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Validate ID
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    header('Location: customers.php');
    exit;
}

// Fetch customer
$customer_res = mysqli_query($conn, "SELECT * FROM customers WHERE id = $id");
if (!$customer_res || mysqli_num_rows($customer_res) === 0) {
    header('Location: customers.php');
    exit;
}
$customer = mysqli_fetch_assoc($customer_res);

// Fetch bank accounts
$bank_accounts_res = mysqli_query($conn, "SELECT * FROM customer_bank_accounts WHERE customer_id = $id ORDER BY id");
$bank_accounts = [];
while ($ba = mysqli_fetch_assoc($bank_accounts_res)) {
    $bank_accounts[] = $ba;
}

// ── Auto-detect route from loading_summary_import_details if not set ──────────
$auto_route = '';
if (empty($customer['route']) && !empty($customer['t_code'])) {
    $t_code_esc = mysqli_real_escape_string($conn, $customer['t_code']);
    $route_lookup = mysqli_query($conn,
        "SELECT route_name FROM loading_summary_import_details
         WHERE t_code = '$t_code_esc' AND route_name IS NOT NULL AND route_name != ''
         ORDER BY id DESC LIMIT 1"
    );
    if ($route_lookup && mysqli_num_rows($route_lookup) > 0) {
        $rl = mysqli_fetch_assoc($route_lookup);
        $auto_route = $rl['route_name'];
    }
}

// Handle file upload
function uploadFile($file, $upload_dir, $prefix = '') {
    if ($file['error'] === UPLOAD_ERR_OK) {
        $file_ext  = pathinfo($file['name'], PATHINFO_EXTENSION);
        $file_name = $prefix . '_' . time() . '_' . uniqid() . '.' . $file_ext;
        $file_path = $upload_dir . $file_name;
        if (move_uploaded_file($file['tmp_name'], $file_path)) {
            return $file_path;
        }
    }
    return '';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $t_code       = mysqli_real_escape_string($conn, $_POST['t_code']);
    $shop_name    = mysqli_real_escape_string($conn, $_POST['shop_name']);
    $company_id   = isset($_POST['company_id']) && $_POST['company_id'] !== '' ? intval($_POST['company_id']) : NULL;
    $branch_id    = isset($_POST['branch_id'])  && $_POST['branch_id']  !== '' ? intval($_POST['branch_id'])  : NULL;
    $route        = mysqli_real_escape_string($conn, $_POST['route'] ?? '');
    $address      = mysqli_real_escape_string($conn, $_POST['address']);
    $telephone    = mysqli_real_escape_string($conn, $_POST['telephone_number']);
    $p_channel    = mysqli_real_escape_string($conn, $_POST['primary_channel']);
    $channel      = mysqli_real_escape_string($conn, $_POST['channel']);
    $payment_mode = mysqli_real_escape_string($conn, $_POST['payment_mode']);
    $credit_limit = mysqli_real_escape_string($conn, $_POST['credit_limit']);
    $credit_days  = intval($_POST['credit_days'] ?? 0);
    $special_days = mysqli_real_escape_string($conn, $_POST['special_credit_policy_days'] ?? '');
    $payee_name   = mysqli_real_escape_string($conn, $_POST['payee_name'] ?? '');

    // --- Sampath fields ---
    $is_sampath_customer = isset($_POST['is_sampath_customer']) ? 1 : 0;
    $sampath_outlet_code = mysqli_real_escape_string($conn, $_POST['sampath_outlet_code'] ?? '');
    $sampath_outlet_name = mysqli_real_escape_string($conn, $_POST['sampath_outlet_name'] ?? '');

    // --- Cheques Will Delay ---
    $cheques_will_delay = isset($_POST['cheques_will_delay']) ? 1 : 0;

    $company_sql  = $company_id !== NULL ? $company_id : 'NULL';
    $branch_sql   = $branch_id  !== NULL ? $branch_id  : 'NULL';

    // File uploads — keep old if no new file
    $customer_seal      = $customer['customer_seal'];
    $customer_signature = $customer['customer_signature'];

    if (!empty($_FILES['customer_seal']['name'])) {
        $new_seal = uploadFile($_FILES['customer_seal'], $upload_dir, 'seal');
        if ($new_seal) {
            if ($customer_seal && file_exists($customer_seal)) unlink($customer_seal);
            $customer_seal = $new_seal;
        }
    }
    if (!empty($_FILES['customer_signature']['name'])) {
        $new_sig = uploadFile($_FILES['customer_signature'], $upload_dir, 'signature');
        if ($new_sig) {
            if ($customer_signature && file_exists($customer_signature)) unlink($customer_signature);
            $customer_signature = $new_sig;
        }
    }
    $customer_seal_esc      = mysqli_real_escape_string($conn, $customer_seal ?? '');
    $customer_signature_esc = mysqli_real_escape_string($conn, $customer_signature ?? '');

    $sql = "UPDATE customers SET
                t_code = '$t_code',
                shop_name = '$shop_name',
                company_id = $company_sql,
                branch_id = $branch_sql,
                route = " . ($route !== '' ? "'$route'" : 'NULL') . ",
                address = '$address',
                telephone_number = '$telephone',
                primary_channel = '$p_channel',
                channel = '$channel',
                payment_mode = '$payment_mode',
                credit_limit = '$credit_limit',
                credit_days = $credit_days,
                special_credit_policy_days = '$special_days',
                customer_seal = '$customer_seal_esc',
                customer_signature = '$customer_signature_esc',
                payee_name = '$payee_name',
                is_sampath_customer = $is_sampath_customer,
                sampath_outlet_code = '$sampath_outlet_code',
                sampath_outlet_name = '$sampath_outlet_name',
                cheques_will_delay = $cheques_will_delay
            WHERE id = $id";

    if (mysqli_query($conn, $sql)) {
        // Sync bank accounts: delete all then re-insert
        mysqli_query($conn, "DELETE FROM customer_bank_accounts WHERE customer_id = $id");

        if (isset($_POST['bank_accounts']) && is_array($_POST['bank_accounts'])) {
            foreach ($_POST['bank_accounts'] as $account) {
                if (!empty($account['account_holder_name']) && !empty($account['account_number'])) {
                    $holder   = mysqli_real_escape_string($conn, $account['account_holder_name']);
                    $bk_code  = mysqli_real_escape_string($conn, $account['bank_code']);
                    $br_code  = mysqli_real_escape_string($conn, $account['branch_code']);
                    $acc_no   = mysqli_real_escape_string($conn, $account['account_number']);
                    mysqli_query($conn, "INSERT INTO customer_bank_accounts
                        (customer_id, account_holder_name, bank_code, branch_code, account_number)
                        VALUES ($id, '$holder', '$bk_code', '$br_code', '$acc_no')");
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
$banks_sql    = "SELECT id, bank_code, bank_name FROM banks WHERE active = 1 ORDER BY bank_name";
$banks_result = mysqli_query($conn, $banks_sql);

// Determine effective company/branch — default to 2 if not set
$effective_company_id = !empty($customer['company_id']) ? $customer['company_id'] : 2;
$effective_branch_id  = !empty($customer['branch_id'])  ? $customer['branch_id']  : 2;

// Determine effective route — customer's saved route, or auto-detected from loading summary
$effective_route = !empty($customer['route']) ? $customer['route'] : $auto_route;

// Is customer non-approved?
$is_non_approved = ($customer['active'] == 0);

// Sampath current values
$is_sampath_checked  = !empty($customer['is_sampath_customer']);
$sampath_outlet_code = htmlspecialchars($customer['sampath_outlet_code'] ?? '');
$sampath_outlet_name = htmlspecialchars($customer['sampath_outlet_name'] ?? '');

// Cheques Will Delay current value
$cheques_will_delay_checked = !empty($customer['cheques_will_delay']);

include 'header.php';
?>

<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <div>
            <h2 class="page-title">Edit Customer</h2>
            <p class="page-subtitle">
                Update details for <strong><?php echo htmlspecialchars($customer['shop_name']); ?></strong>
                &nbsp;<span class="tcode-badge"><?php echo htmlspecialchars($customer['t_code']); ?></span>
                <?php if ($is_non_approved): ?>
                    &nbsp;<span class="badge badge-non-approved"><i class="fa-solid fa-circle-xmark"></i> Non-Approved</span>
                <?php endif; ?>
                <?php if ($is_sampath_checked): ?>
                    &nbsp;<span class="badge badge-sampath"><i class="fa-solid fa-building-columns"></i> Sampath Customer</span>
                <?php endif; ?>
                <?php if ($cheques_will_delay_checked): ?>
                    &nbsp;<span class="badge badge-cheque-delay"><i class="fa-solid fa-clock"></i> Cheques Will Delay</span>
                <?php endif; ?>
            </p>
        </div>
        <a href="customers.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i> Back to List
        </a>
    </div>
</div>

<?php if ($is_non_approved): ?>
<div class="alert alert-warning">
    <i class="fa-solid fa-triangle-exclamation"></i>
    This customer is <strong>not approved</strong>. Please review and set a valid payment mode and credit policy before saving.
</div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
<div class="alert alert-error">
    <i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?>
</div>
<?php endif; ?>

<?php if (!empty($auto_route) && empty($customer['route'])): ?>
<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    Route auto-detected from loading summary data: <strong><?php echo htmlspecialchars($auto_route); ?></strong>
</div>
<?php endif; ?>

<form method="POST" action="" id="editCustomerForm" enctype="multipart/form-data">

    <!-- Customer Details -->
    <div class="content-card">
        <h3 class="card-title"><i class="fa-solid fa-store"></i> Customer Details</h3>

        <div class="form-row">
            <div class="form-group required-field">
                <label class="form-label">T-Code <span class="required">*</span></label>
                <input type="text" name="t_code" class="form-input" required
                       value="<?php echo htmlspecialchars($customer['t_code']); ?>" placeholder="Enter T-Code">
            </div>
            <div class="form-group required-field">
                <label class="form-label">Shop Name <span class="required">*</span></label>
                <input type="text" name="shop_name" class="form-input" required
                       value="<?php echo htmlspecialchars($customer['shop_name']); ?>" placeholder="Enter shop name">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">
                    Company
                    <?php if (empty($customer['company_id'])): ?>
                        <span class="auto-default-badge"><i class="fa-solid fa-magic-wand-sparkles"></i> Auto-set to default</span>
                    <?php endif; ?>
                </label>
                <select name="company_id" id="company_id" class="form-input select2" onchange="loadCompanyBranches()">
                    <option value="">Select Company</option>
                    <?php
                    $companies_sql = "SELECT id, company_code, company_name FROM companies WHERE active = 1 ORDER BY company_name";
                    $companies_res = mysqli_query($conn, $companies_sql);
                    while ($co = mysqli_fetch_assoc($companies_res)):
                    ?>
                        <option value="<?php echo $co['id']; ?>"
                            <?php echo $effective_company_id == $co['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($co['company_code'] . ' - ' . $co['company_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">
                    Branch
                    <?php if (empty($customer['branch_id'])): ?>
                        <span class="auto-default-badge"><i class="fa-solid fa-magic-wand-sparkles"></i> Auto-set to default</span>
                    <?php endif; ?>
                </label>
                <select name="branch_id" id="branch_id" class="form-input select2">
                    <option value="">Select Branch</option>
                    <?php
                    $br_sql = "SELECT id, branch_code, branch_name FROM branches WHERE company_id = $effective_company_id AND active = 1 ORDER BY branch_name";
                    $br_res = mysqli_query($conn, $br_sql);
                    while ($br = mysqli_fetch_assoc($br_res)):
                    ?>
                        <option value="<?php echo $br['id']; ?>"
                            <?php echo $effective_branch_id == $br['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($br['branch_code'] . ' - ' . $br['branch_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group required-field">
                <label class="form-label">
                    Route <span class="required">*</span>
                    <?php if (!empty($auto_route) && empty($customer['route'])): ?>
                        <span class="auto-default-badge"><i class="fa-solid fa-bolt"></i> Auto-detected</span>
                    <?php endif; ?>
                </label>
                <select name="route" id="route" class="form-input select2" required>
                    <option value="">Select Route</option>
                    <?php
                    $routes_sql = "SELECT id, route_code, route_name FROM routes WHERE active = 1 ORDER BY route_name";
                    $routes_res = mysqli_query($conn, $routes_sql);
                    while ($rt = mysqli_fetch_assoc($routes_res)):
                    ?>
                        <option value="<?php echo htmlspecialchars($rt['route_name']); ?>"
                            <?php echo $effective_route === $rt['route_name'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($rt['route_code'] . ' - ' . $rt['route_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group required-field">
                <label class="form-label">Telephone Number <span class="required">*</span></label>
                <input type="text" name="telephone_number" class="form-input" required
                       value="<?php echo htmlspecialchars($customer['telephone_number'] ?? ''); ?>"
                       placeholder="Enter telephone number">
            </div>
        </div>

        <div class="form-group required-field">
            <label class="form-label">Address <span class="required">*</span></label>
            <textarea name="address" class="form-input" rows="2" placeholder="Enter full address" required><?php echo htmlspecialchars($customer['address'] ?? ''); ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group required-field">
                <label class="form-label">Primary Channel <span class="required">*</span></label>
                <select name="primary_channel" class="form-input select2" required>
                    <option value="">Select Primary Channel</option>
                    <?php
                    $primary_channels = ['Distributive Grocery', 'Distributive Other Channels', 'Out of Home'];
                    foreach ($primary_channels as $pc):
                    ?>
                        <option value="<?php echo $pc; ?>" <?php echo $customer['primary_channel'] === $pc ? 'selected' : ''; ?>><?php echo $pc; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group required-field">
                <label class="form-label">Channel <span class="required">*</span></label>
                <select name="channel" class="form-input select2" required>
                    <option value="">Select Channel</option>
                    <?php
                    $channels = [
                        'Book shops and Hardware','Cosmetic Standard Large','Cosmetic Standard Small',
                        'DUMMY','Emergency Top up Upper','Estate outlets','ETUP LARGE','ETUP LITE',
                        'ETUP MEDIUM','ETUP SMALL','Family Grocery Large','Family Grocery Small',
                        'INDEPENDENT SUPER MARKER LARGE','INDEPENDENT SUPER MARKER MEDIUM','INDEPENDENT SUPER MARKER SMALL',
                        'Institutions Forces','Institutions Private','Out of home Others',
                        'Pharmacy General Large','Pharmacy General Small','Saubhagya Entrepreneurs',
                        'Textile Premium','Textile Standard','Wholesale Dominant'
                    ];
                    foreach ($channels as $ch):
                    ?>
                        <option value="<?php echo $ch; ?>" <?php echo $customer['channel'] === $ch ? 'selected' : ''; ?>><?php echo $ch; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Payee Name</label>
                <input type="text" name="payee_name" class="form-input"
                       value="<?php echo htmlspecialchars($customer['payee_name'] ?? ''); ?>"
                       placeholder="Enter payee name">
            </div>
        </div>

        <!-- ── Sampath Customer ── -->
        <div class="form-group" style="margin-top:4px;">
            <div class="sampath-checkbox-wrap">
                <label class="sampath-toggle-label">
                    <input type="checkbox" name="is_sampath_customer" id="is_sampath_customer" value="1"
                           onchange="toggleSampathFields(this)"
                           <?php echo $is_sampath_checked ? 'checked' : ''; ?>>
                    <span class="sampath-checkmark"></span>
                    <span class="sampath-label-text">
                        <img src="https://upload.wikimedia.org/wikipedia/en/thumb/5/52/Sampath_Bank_logo.svg/200px-Sampath_Bank_logo.svg.png"
                             alt="Sampath" class="sampath-bank-logo" onerror="this.style.display='none'">
                        Sampath Customer
                    </span>
                </label>
            </div>
        </div>

        <div id="sampath_fields" class="sampath-fields-panel"
             style="display:<?php echo $is_sampath_checked ? 'block' : 'none'; ?>;">
            <div class="sampath-fields-inner">
                <div class="sampath-fields-header">
                    <i class="fa-solid fa-building-columns"></i> Sampath Outlet Details
                </div>
                <div class="form-row">
                    <div class="form-group required-field">
                        <label class="form-label">Sampath Outlet Code <span class="required">*</span></label>
                        <input type="text" name="sampath_outlet_code" id="sampath_outlet_code"
                               class="form-input" placeholder="Enter Sampath outlet code"
                               value="<?php echo $sampath_outlet_code; ?>"
                               <?php echo $is_sampath_checked ? 'required' : ''; ?>>
                    </div>
                    <div class="form-group required-field">
                        <label class="form-label">Sampath Outlet Name <span class="required">*</span></label>
                        <input type="text" name="sampath_outlet_name" id="sampath_outlet_name"
                               class="form-input" placeholder="Enter Sampath outlet name"
                               value="<?php echo $sampath_outlet_name; ?>"
                               <?php echo $is_sampath_checked ? 'required' : ''; ?>>
                    </div>
                </div>
            </div>
        </div>
        <!-- ── /Sampath Customer ── -->

    </div>

    <!-- Payment & Credit -->
    <div class="content-card">
        <h3 class="card-title"><i class="fa-solid fa-credit-card"></i> Payment & Credit Settings</h3>

        <?php if ($is_non_approved && empty($customer['payment_mode'])): ?>
        <div class="alert alert-warning" style="margin-bottom:12px;">
            <i class="fa-solid fa-triangle-exclamation"></i>
            Payment mode was not set for this customer. Please select one below.
        </div>
        <?php endif; ?>

        <div class="form-row">
            <div class="form-group required-field">
                <label class="form-label">Payment Mode <span class="required">*</span></label>
                <select name="payment_mode" id="payment_mode" class="form-input select2" required>
                    <option value=""
                        <?php echo ($is_non_approved && empty($customer['payment_mode'])) ? 'selected' : ''; ?>>
                        -- Select Payment Mode --
                    </option>
                    <option value="">-- Select Payment Mode --</option>
                    <?php foreach (['cash','credit','cheque'] as $pm): ?>
                        <option value="<?php echo $pm; ?>"
                            <?php echo (!$is_non_approved && $customer['payment_mode'] === $pm) ? 'selected' : ''; ?>>
                            <?php echo ucfirst($pm); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Credit Limit (Rs.)</label>
                <input type="number" name="credit_limit" class="form-input" step="0.01"
                       value="<?php echo htmlspecialchars($customer['credit_limit'] ?? '0'); ?>" placeholder="0.00">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group required-field">
                <label class="form-label">Credit Policy Days <span class="required">*</span></label>
                <input type="number" name="credit_days" id="credit_days" class="form-input" min="0" required
                       value="<?php echo intval($customer['credit_days'] ?? 0); ?>"
                       placeholder="Enter credit policy days">
            </div>
            <div class="form-group">
                <label class="form-label">Special Credit Policy Days</label>
                <input type="text" name="special_credit_policy_days" class="form-input"
                       value="<?php echo htmlspecialchars($customer['special_credit_policy_days'] ?? ''); ?>"
                       placeholder="Enter special credit policy days">
            </div>
        </div>

        <!-- ── Cheques Will Delay ── -->
        <div class="form-group" style="margin-top:4px;">
            <div class="sampath-checkbox-wrap">
                <label class="sampath-toggle-label">
                    <input type="checkbox" name="cheques_will_delay" id="cheques_will_delay" value="1"
                           <?php echo $cheques_will_delay_checked ? 'checked' : ''; ?>>
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
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 class="card-title" style="margin:0;"><i class="fa-solid fa-building-columns"></i> Bank Accounts</h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="addBankAccount()">
                <i class="fa-solid fa-plus"></i> Add Bank Account
            </button>
        </div>
        <div id="bankAccountsContainer">
            <?php foreach ($bank_accounts as $i => $ba): ?>
            <div class="bank-account-item" id="bank-account-existing-<?php echo $ba['id']; ?>">
                <div class="bank-account-header">
                    <span class="bank-account-title"><i class="fa-solid fa-building-columns"></i> Bank Account #<?php echo $i+1; ?></span>
                    <button type="button" class="btn-remove-account" onclick="this.closest('.bank-account-item').remove()">
                        <i class="fa-solid fa-trash"></i> Remove
                    </button>
                </div>
                <div class="form-group">
                    <label class="form-label">Account Holder Name</label>
                    <input type="text" name="bank_accounts[<?php echo $i; ?>][account_holder_name]" class="form-input"
                           value="<?php echo htmlspecialchars($ba['account_holder_name']); ?>"
                           placeholder="Enter account holder name">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Bank</label>
                        <select name="bank_accounts[<?php echo $i; ?>][bank_code]" class="form-input bank-select"
                                data-counter="e<?php echo $i; ?>"
                                data-selected="<?php echo htmlspecialchars($ba['bank_code']); ?>"
                                onchange="loadBankBranches(this)">
                            <option value="">Select Bank</option>
                            <?php
                            mysqli_data_seek($banks_result, 0);
                            while ($bank = mysqli_fetch_assoc($banks_result)):
                            ?>
                                <option value="<?php echo $bank['bank_code']; ?>"
                                    <?php echo $ba['bank_code'] === $bank['bank_code'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($bank['bank_code'] . ' - ' . $bank['bank_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Branch</label>
                        <select name="bank_accounts[<?php echo $i; ?>][branch_code]"
                                id="branch-select-e<?php echo $i; ?>" class="form-input"
                                data-selected="<?php echo htmlspecialchars($ba['branch_code']); ?>">
                            <option value="">Select Bank Branch</option>
                            <?php
                            if ($ba['bank_code']) {
                                $bb_res = mysqli_query($conn, "SELECT branch_code, branch_name FROM bank_branches WHERE bank_code = '{$ba['bank_code']}' ORDER BY branch_name");
                                while ($bb = mysqli_fetch_assoc($bb_res)):
                            ?>
                                <option value="<?php echo $bb['branch_code']; ?>"
                                    <?php echo $ba['branch_code'] === $bb['branch_code'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($bb['branch_code'] . ' - ' . $bb['branch_name']); ?>
                                </option>
                            <?php
                                endwhile;
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Account Number</label>
                    <input type="text" name="bank_accounts[<?php echo $i; ?>][account_number]" class="form-input"
                           value="<?php echo htmlspecialchars($ba['account_number']); ?>"
                           placeholder="Enter account number">
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Documents & Signatures -->
    <div class="content-card">
        <h3 class="card-title"><i class="fa-solid fa-file-signature"></i> Documents & Signatures</h3>
        <div class="form-row">
            <!-- Seal -->
            <div class="form-group">
                <label class="form-label">Customer Seal</label>
                <?php if (!empty($customer['customer_seal']) && file_exists($customer['customer_seal'])): ?>
                <div class="current-file">
                    <img src="<?php echo htmlspecialchars($customer['customer_seal']); ?>" alt="Current seal">
                    <small>Current seal — upload a new file to replace</small>
                </div>
                <?php endif; ?>
                <div class="file-upload-wrapper">
                    <input type="file" name="customer_seal" id="customer_seal" class="file-input"
                           accept="image/*" onchange="previewFile(this, 'seal_preview')">
                    <label for="customer_seal" class="file-label">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <span><?php echo !empty($customer['customer_seal']) ? 'Upload new seal (replaces current)' : 'Click to upload seal image'; ?></span>
                        <small>PNG, JPG, GIF up to 10MB</small>
                    </label>
                    <div id="seal_preview" class="file-preview"></div>
                </div>
            </div>
            <!-- Signature -->
            <div class="form-group">
                <label class="form-label">Customer Signature</label>
                <?php if (!empty($customer['customer_signature']) && file_exists($customer['customer_signature'])): ?>
                <div class="current-file">
                    <img src="<?php echo htmlspecialchars($customer['customer_signature']); ?>" alt="Current signature">
                    <small>Current signature — upload a new file to replace</small>
                </div>
                <?php endif; ?>
                <div class="file-upload-wrapper">
                    <input type="file" name="customer_signature" id="customer_signature" class="file-input"
                           accept="image/*" onchange="previewFile(this, 'signature_preview')">
                    <label for="customer_signature" class="file-label">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <span><?php echo !empty($customer['customer_signature']) ? 'Upload new signature (replaces current)' : 'Click to upload signature image'; ?></span>
                        <small>PNG, JPG, GIF up to 10MB</small>
                    </label>
                    <div id="signature_preview" class="file-preview"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="form-actions">
        <a href="customers.php" class="btn btn-secondary">
            <i class="fa-solid fa-xmark"></i> Cancel
        </a>
        <a href="view_customer.php?id=<?php echo $id; ?>" class="btn btn-secondary">
            <i class="fa-solid fa-eye"></i> View Customer
        </a>
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-check"></i> Update Customer
        </button>
    </div>
</form>

<style>
.tcode-badge {
    background:#f3f4f6;color:#374151;font-size:12px;font-weight:600;
    padding:2px 10px;border-radius:20px;
}
.auto-default-badge {
    background:#eff6ff;color:#1d4ed8;font-size:10px;font-weight:600;
    padding:1px 7px;border-radius:20px;margin-left:4px;vertical-align:middle;
    display:inline-flex;align-items:center;gap:3px;
}
.required-field .form-input,
.required-field .select2-container--default .select2-selection--single {
    background-color:#fffbeb !important;
}
.required { color:#ef4444; }

.content-card {
    background:#fff;border:1px solid #e5e5e5;border-radius:8px;
    padding:16px;margin-bottom:16px;
}
.card-title {
    font-size:15px;font-weight:600;margin-bottom:12px;color:#1f2937;
    display:flex;align-items:center;gap:8px;
}
.card-title i { color:#6b7280;font-size:14px; }

.form-row { display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px; }
.form-group { margin-bottom:12px; }
.form-label { display:block;font-size:11px;font-weight:600;margin-bottom:6px;color:#374151; }
.form-input {
    width:100%;padding:8px 12px;border:1px solid #e5e5e5;border-radius:6px;
    font-size:13px;font-family:'Inter',sans-serif;transition:all 0.3s;box-sizing:border-box;
}
.form-input:focus { outline:none;border-color:#000;box-shadow:0 0 0 2px rgba(0,0,0,0.05); }
textarea.form-input { resize:vertical;min-height:60px; }

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

.current-file {
    display:flex;align-items:center;gap:10px;background:#f9fafb;
    border:1px solid #e5e7eb;border-radius:6px;padding:8px;margin-bottom:8px;
}
.current-file img { width:48px;height:40px;object-fit:contain;border-radius:4px; }
.current-file small { font-size:11px;color:#6b7280; }

.file-upload-wrapper { position:relative; }
.file-input { position:absolute;opacity:0;width:0;height:0; }
.file-label {
    display:block;padding:20px 16px;background:#f9fafb;border:2px dashed #d1d5db;
    border-radius:6px;text-align:center;cursor:pointer;transition:all 0.3s;
}
.file-label:hover { border-color:#000;background:#fff; }
.file-label i { display:block;font-size:20px;color:#6b7280;margin-bottom:6px; }
.file-label span { display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:2px; }
.file-label small { display:block;font-size:10px;color:#9ca3af; }
.file-preview { margin-top:8px;text-align:center; }
.file-preview img { max-width:150px;max-height:100px;border-radius:6px;border:1px solid #e5e5e5; }

.bank-account-item {
    background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;
    padding:12px;margin-bottom:12px;position:relative;
}
.bank-account-header { display:flex;justify-content:space-between;align-items:center;margin-bottom:12px; }
.bank-account-title { font-size:12px;font-weight:600;color:#374151; }
.btn-remove-account {
    background:#ef4444;color:#fff;border:none;padding:4px 10px;border-radius:4px;
    font-size:11px;cursor:pointer;transition:all 0.3s;
    display:inline-flex;align-items:center;gap:4px;
}
.btn-remove-account:hover { background:#dc2626; }

.form-actions {
    display:flex;gap:10px;justify-content:flex-end;margin-top:16px;
    padding:12px;background:#f9fafb;border-radius:6px;
}
.btn {
    display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;
    border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;
    transition:all 0.3s;text-decoration:none;font-family:'Inter',sans-serif;
}
.btn-primary { background:#000;color:#fff; }
.btn-primary:hover { background:#333; }
.btn-secondary { background:#f5f5f5;color:#333;border:1px solid #e5e5e5; }
.btn-secondary:hover { background:#e5e5e5; }
.btn-sm { padding:6px 12px;font-size:12px; }

.alert { padding:10px 14px;border-radius:6px;margin-bottom:16px;display:flex;align-items:center;gap:8px;font-size:12px;font-weight:500; }
.alert-error   { background:#fef2f2;color:#991b1b;border:1px solid #fecaca; }
.alert-warning { background:#fffbeb;color:#92400e;border:1px solid #fde68a; }
.alert-info    { background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe; }

.badge { display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600; }
.badge-non-approved { background:#fee2e2;color:#991b1b; }
.badge-sampath { background:#f3e8ff;color:#6d28d9; }
.badge-cheque-delay { background:#fef3c7;color:#92400e; }

/* Select2 */
.select2-container--default .select2-selection--single {
    height:34px !important;border:1px solid #e5e5e5 !important;border-radius:6px !important;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height:32px !important;padding-left:12px !important;font-size:13px !important;
}
.select2-container--default .select2-selection--single .select2-selection__arrow { height:32px !important; }
.select2-container--default.select2-container--focus .select2-selection--single { border-color:#000 !important; }
.required-field .select2-container--default .select2-selection--single { background-color:#fffbeb !important; }

@media(max-width:768px){
    .form-row { grid-template-columns:1fr; }
    .form-actions { flex-direction:column; }
    .form-actions .btn { width:100%;justify-content:center; }
}
</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
let bankAccountCounter = 1000; // offset from existing

$(document).ready(function() {
    $('.select2').select2({ width:'100%' });
    $('#route').select2({ width:'100%', placeholder:'Select Route' });

    // Client-side validation before submit
    $('#editCustomerForm').on('submit', function(e) {
        const paymentMode = $('#payment_mode').val();
        const creditDays  = $('#credit_days').val().trim();
        const route       = $('#route').val();
        const telephone   = $('input[name="telephone_number"]').val().trim();
        const address     = $('textarea[name="address"]').val().trim();

        let errors = [];

        if (!route)       errors.push('Route is required.');
        if (!telephone)   errors.push('Telephone Number is required.');
        if (!address)     errors.push('Address is required.');
        if (!paymentMode) errors.push('Payment Mode is required.');
        if (creditDays === '' || isNaN(creditDays)) errors.push('Credit Policy Days is required.');

        // Sampath validation
        const sampathChecked = document.getElementById('is_sampath_customer').checked;
        if (sampathChecked) {
            const outletCode = document.getElementById('sampath_outlet_code').value.trim();
            const outletName = document.getElementById('sampath_outlet_name').value.trim();
            if (!outletCode) errors.push('Sampath Outlet Code is required.');
            if (!outletName) errors.push('Sampath Outlet Name is required.');
        }

        if (errors.length > 0) {
            e.preventDefault();
            alert('Please fix the following:\n\n' + errors.join('\n'));
        }
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
    branchSelect.innerHTML = '<option value="">Select Branch</option>';
    if (companyId) {
        fetch('get_branches.php?company_id=' + companyId)
            .then(r => r.json())
            .then(data => {
                data.forEach(b => {
                    const o = document.createElement('option');
                    o.value = b.id;
                    o.textContent = b.branch_code + ' - ' + b.branch_name;
                    branchSelect.appendChild(o);
                });
                $('#branch_id').select2('destroy').select2({ width:'100%' });
            });
    }
}

function addBankAccount() {
    bankAccountCounter++;
    const container = document.getElementById('bankAccountsContainer');
    const div = document.createElement('div');
    div.className = 'bank-account-item';
    div.id = 'bank-account-' + bankAccountCounter;

    div.innerHTML = `
        <div class="bank-account-header">
            <span class="bank-account-title"><i class="fa-solid fa-building-columns"></i> New Bank Account</span>
            <button type="button" class="btn-remove-account" onclick="this.closest('.bank-account-item').remove()">
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
                <select name="bank_accounts[${bankAccountCounter}][bank_code]" class="form-input bank-select"
                        data-counter="${bankAccountCounter}" onchange="loadBankBranches(this)">
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
                <select name="bank_accounts[${bankAccountCounter}][branch_code]"
                        id="branch-select-${bankAccountCounter}" class="form-input">
                    <option value="">Select Bank Branch</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Account Number</label>
            <input type="text" name="bank_accounts[${bankAccountCounter}][account_number]" class="form-input" placeholder="Enter account number">
        </div>`;

    container.appendChild(div);
    $(div).find('.bank-select').select2({ width:'100%' });
}

function loadBankBranches(sel) {
    const bankCode    = sel.value;
    const counter     = sel.getAttribute('data-counter');
    const branchSel   = document.getElementById('branch-select-' + counter);
    branchSel.innerHTML = '<option value="">Select Bank Branch</option>';
    if (bankCode) {
        fetch('get_bank_branches.php?bank_code=' + bankCode)
            .then(r => r.json())
            .then(data => {
                data.forEach(b => {
                    const o = document.createElement('option');
                    o.value = b.branch_code;
                    o.textContent = b.branch_code + ' - ' + b.branch_name;
                    branchSel.appendChild(o);
                });
            });
    }
}

function previewFile(input, previewId) {
    const preview = document.getElementById(previewId);
    const file = input.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = e => { preview.innerHTML = `<img src="${e.target.result}" alt="Preview">`; };
        reader.readAsDataURL(file);
    }
}
</script>

<?php include 'footer.php'; ?>