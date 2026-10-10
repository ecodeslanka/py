<?php
include 'config.php';

// Create uploads directory
$upload_dir = 'uploads/employees/';
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
    $company_id      = !empty($_POST['company_id'])     ? intval($_POST['company_id'])     : null;
    $branch_id       = !empty($_POST['branch_id'])      ? intval($_POST['branch_id'])      : null;
    $designation_id  = !empty($_POST['designation_id']) ? intval($_POST['designation_id']) : null;
    $staff_category_id = null;

    // Generate Employee ID
    $employee_id = '';
    if ($company_id && $designation_id) {
        $company_sql    = "SELECT company_code FROM companies WHERE id = $company_id";
        $company_result = mysqli_query($conn, $company_sql);
        $company_data   = mysqli_fetch_assoc($company_result);

        $designation_sql    = "SELECT d.designation_code, d.staff_category_id, sc.category_code
                               FROM designations d
                               LEFT JOIN staff_categories sc ON d.staff_category_id = sc.id
                               WHERE d.id = $designation_id";
        $designation_result = mysqli_query($conn, $designation_sql);
        $designation_data   = mysqli_fetch_assoc($designation_result);

        if ($company_data && $designation_data && $designation_data['category_code']) {
            $full_prefix   = $company_data['company_code'] . '/' . $designation_data['category_code'] . '/' . $designation_data['designation_code'];
            $search_prefix = $company_data['company_code'] . '/' . $designation_data['category_code'];

            $existing_numbers = [];
            $existing_sql     = "SELECT employee_id FROM employees WHERE employee_id LIKE '$search_prefix/%' ORDER BY employee_id";
            $existing_result  = mysqli_query($conn, $existing_sql);
            while ($row = mysqli_fetch_assoc($existing_result)) {
                $parts = explode('/', $row['employee_id']);
                if (count($parts) == 4) {
                    $num = intval($parts[3]);
                    if ($num > 0) $existing_numbers[] = $num;
                }
            }
            $next_num    = empty($existing_numbers) ? 1 : max($existing_numbers) + 1;
            $employee_id = $full_prefix . '/' . str_pad($next_num, 2, '0', STR_PAD_LEFT);
            $staff_category_id = $designation_data['staff_category_id'];
        }
    }

    $custom_code           = !empty($_POST['custom_code'])           ? mysqli_real_escape_string($conn, trim($_POST['custom_code']))           : '';
    $employee_full_name    = mysqli_real_escape_string($conn, $_POST['employee_full_name']);
    $name_with_initials    = mysqli_real_escape_string($conn, $_POST['name_with_initials']);
    $tr_code               = mysqli_real_escape_string($conn, $_POST['tr_code']);
    $date_of_birth         = mysqli_real_escape_string($conn, $_POST['date_of_birth']);
    $date_of_join          = mysqli_real_escape_string($conn, $_POST['date_of_join']);
    $id_number             = mysqli_real_escape_string($conn, $_POST['id_number']);
    $gender                = mysqli_real_escape_string($conn, $_POST['gender']);
    $marital_status        = mysqli_real_escape_string($conn, $_POST['marital_status']);
    $driving_licence_number= mysqli_real_escape_string($conn, $_POST['driving_licence_number']);
    $blood_group           = mysqli_real_escape_string($conn, $_POST['blood_group']);
    $telephone_home        = mysqli_real_escape_string($conn, $_POST['telephone_home']);
    $telephone_mobile      = mysqli_real_escape_string($conn, $_POST['telephone_mobile']);
    $telephone_office      = mysqli_real_escape_string($conn, $_POST['telephone_office']);
    $whatsapp_number       = mysqli_real_escape_string($conn, $_POST['whatsapp_number']);
    $email_address         = mysqli_real_escape_string($conn, $_POST['email_address']);
    $address               = mysqli_real_escape_string($conn, $_POST['address'] ?? '');
    $epf_etf_assignee_name = mysqli_real_escape_string($conn, $_POST['epf_etf_assignee_name']);
    $epf_assignee_contact  = mysqli_real_escape_string($conn, $_POST['epf_assignee_contact']);
    $relationship          = mysqli_real_escape_string($conn, $_POST['relationship']);
    $nic_number            = mysqli_real_escape_string($conn, $_POST['nic_number']);
    $bank_code             = mysqli_real_escape_string($conn, $_POST['bank_code']);
    $bank_branch_code      = mysqli_real_escape_string($conn, $_POST['bank_branch_code']);
    $account_number        = mysqli_real_escape_string($conn, $_POST['account_number']);

    // Salary fields (all optional)
    $basic_salary    = !empty($_POST['basic_salary'])    ? floatval($_POST['basic_salary'])    : 'NULL';
    $gratuity_amount = !empty($_POST['gratuity_amount']) ? floatval($_POST['gratuity_amount']) : 'NULL';
    $insurance_amount= !empty($_POST['insurance_amount'])? floatval($_POST['insurance_amount']): 'NULL';
    $welfare_amount  = !empty($_POST['welfare_amount'])  ? floatval($_POST['welfare_amount'])  : 'NULL';

    // File uploads
    $profile_picture     = uploadFile($_FILES['profile_picture'],     $upload_dir, 'profile');
    $application_form    = uploadFile($_FILES['application_form'],    $upload_dir, 'application');
    $id_copy             = uploadFile($_FILES['id_copy'],             $upload_dir, 'id');
    $driver_licence_copy = uploadFile($_FILES['driver_licence_copy'], $upload_dir, 'licence');

    $sql = "INSERT INTO employees (
        employee_id, custom_code, company_id, branch_id, staff_category_id,
        employee_full_name, name_with_initials, designation_id, tr_code,
        date_of_birth, date_of_join, id_number, gender, marital_status,
        driving_licence_number, blood_group, telephone_home, telephone_mobile,
        telephone_office, whatsapp_number, email_address, address,
        epf_etf_assignee_name, epf_assignee_contact, relationship, nic_number,
        bank_code, bank_branch_code, account_number,
        basic_salary, gratuity_amount, insurance_amount, welfare_amount,
        profile_picture, application_form, id_copy, driver_licence_copy, status
    ) VALUES (
        '$employee_id', '$custom_code',
        " . ($company_id        ? $company_id        : "NULL") . ",
        " . ($branch_id         ? $branch_id         : "NULL") . ",
        " . ($staff_category_id ? $staff_category_id : "NULL") . ",
        '$employee_full_name', '$name_with_initials',
        " . ($designation_id ? $designation_id : "NULL") . ",
        '$tr_code', " . (!empty($_POST['date_of_birth']) ? "'$date_of_birth'" : "NULL") . ",
        " . (!empty($_POST['date_of_join']) ? "'$date_of_join'" : "NULL") . ",
        '$id_number', '$gender', '$marital_status',
        '$driving_licence_number', '$blood_group', '$telephone_home', '$telephone_mobile',
        '$telephone_office', '$whatsapp_number', '$email_address', '$address',
        '$epf_etf_assignee_name', '$epf_assignee_contact', '$relationship', '$nic_number',
        '$bank_code', '$bank_branch_code', '$account_number',
        " . ($basic_salary    !== 'NULL' ? $basic_salary    : "NULL") . ",
        " . ($gratuity_amount !== 'NULL' ? $gratuity_amount : "NULL") . ",
        " . ($insurance_amount!== 'NULL' ? $insurance_amount: "NULL") . ",
        " . ($welfare_amount  !== 'NULL' ? $welfare_amount  : "NULL") . ",
        '$profile_picture', '$application_form', '$id_copy', '$driver_licence_copy', 'Probation'
    )";

    if (mysqli_query($conn, $sql)) {
        $new_employee_db_id = mysqli_insert_id($conn);

        // Save dynamic incentives
        if (!empty($_POST['incentive_type_id']) && is_array($_POST['incentive_type_id'])) {
            foreach ($_POST['incentive_type_id'] as $idx => $type_id) {
                $type_id = intval($type_id);
                $amount  = floatval($_POST['incentive_amount'][$idx] ?? 0);
                if ($type_id > 0 && $amount > 0) {
                    $inc_sql = "INSERT INTO employee_incentives (employee_id, incentive_type_id, amount)
                                VALUES ($new_employee_db_id, $type_id, $amount)";
                    mysqli_query($conn, $inc_sql);
                }
            }
        }

        // Log
        $log_sql = "INSERT INTO employee_logs (employee_id, action, description, new_value, created_by)
                    VALUES ($new_employee_db_id, 'created', 'Employee created', '$employee_id', 'System')";
        mysqli_query($conn, $log_sql);

        header('Location: employees.php?success=1');
        exit;
    } else {
        $error_message = "Error: " . mysqli_error($conn);
    }
}

// Dropdowns
$companies_sql    = "SELECT id, company_code, company_name FROM companies WHERE active = 1 ORDER BY company_name";
$companies_result = mysqli_query($conn, $companies_sql);

$designations_sql    = "SELECT id, designation_code, designation_name FROM designations WHERE active = 1 ORDER BY designation_name";
$designations_result = mysqli_query($conn, $designations_sql);

$banks_sql    = "SELECT id, bank_code, bank_name FROM banks WHERE active = 1 ORDER BY bank_name";
$banks_result = mysqli_query($conn, $banks_sql);

$incentive_types_sql    = "SELECT id, type_name FROM incentive_types WHERE active = 1 ORDER BY type_name";
$incentive_types_result = mysqli_query($conn, $incentive_types_sql);
$incentive_types = [];
while ($row = mysqli_fetch_assoc($incentive_types_result)) {
    $incentive_types[] = $row;
}

include 'header.php';
?>

<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <div>
            <h2 class="page-title">Add New Employee</h2>
            <p class="page-subtitle">Fill in employee details</p>
        </div>
        <a href="employees.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i> Back to List
        </a>
    </div>
</div>

<?php if (isset($error_message)): ?>
<div class="alert alert-error">
    <i class="fa-solid fa-circle-exclamation"></i>
    <?php echo $error_message; ?>
</div>
<?php endif; ?>

<form method="POST" action="" id="employeeForm" enctype="multipart/form-data">

    <!-- Tab Navigation -->
    <div class="tabs">
        <button type="button" class="tab-btn active" onclick="switchTab(0)">
            <i class="fa-solid fa-user"></i> Employee Details
        </button>
        <button type="button" class="tab-btn" onclick="switchTab(1)">
            <i class="fa-solid fa-building"></i> Bank Account Details
        </button>
        <button type="button" class="tab-btn" onclick="switchTab(2)">
            <i class="fa-solid fa-coins"></i> Salary
        </button>
        <button type="button" class="tab-btn" onclick="switchTab(3)">
            <i class="fa-solid fa-paperclip"></i> Attachments
        </button>
    </div>

    <!-- ───── TAB 1: Employee Details ───── -->
    <div class="tab-content active" id="tab-0">
        <div class="content-card">
            <h3 class="card-title">Employee Details</h3>

            <div class="form-row">
                <div class="form-group required-field">
                    <label class="form-label">Company <span class="required">*</span></label>
                    <select name="company_id" id="company_id" class="form-input select2" required onchange="loadBranches(); generateEmployeeId();">
                        <option value="">Select Company</option>
                        <?php mysqli_data_seek($companies_result, 0); while ($company = mysqli_fetch_assoc($companies_result)): ?>
                            <option value="<?php echo $company['id']; ?>" data-code="<?php echo $company['company_code']; ?>">
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
                <div class="form-group required-field">
                    <label class="form-label">Employee Full Name <span class="required">*</span></label>
                    <input type="text" name="employee_full_name" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Name With Initials</label>
                    <input type="text" name="name_with_initials" class="form-input">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group required-field">
                    <label class="form-label">Designation <span class="required">*</span></label>
                    <select name="designation_id" id="designation_id" class="form-input select2" required onchange="generateEmployeeId();">
                        <option value="">Select Designation</option>
                        <?php mysqli_data_seek($designations_result, 0); while ($designation = mysqli_fetch_assoc($designations_result)): ?>
                            <option value="<?php echo $designation['id']; ?>" data-code="<?php echo $designation['designation_code']; ?>">
                                <?php echo htmlspecialchars($designation['designation_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Employee ID</label>
                    <input type="text" id="employee_id_display" class="form-input" readonly placeholder="company code/designation code/001">
                    <small class="form-hint">Auto-generated based on Company and Designation</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Custom Code <span style="color:#6b7280;">(Optional)</span></label>
                    <input type="text" name="custom_code" id="custom_code" class="form-input" placeholder="Enter any custom identification code">
                    <small class="form-hint">Additional custom code for internal use</small>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Date of Join</label>
                    <input type="date" name="date_of_join" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">TR Code</label>
                    <input type="text" name="tr_code" class="form-input" placeholder="Manually Create">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group required-field">
                    <label class="form-label">ID Number (NIC) <span class="required">*</span></label>
                    <input type="text" name="id_number" id="id_number" class="form-input" required placeholder="Enter NIC to get male/female and birthday" onblur="parseNIC()">
                    <small class="form-hint">Auto-detects gender and date of birth</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="date_of_birth" id="date_of_birth" class="form-input" readonly>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Gender</label>
                    <select name="gender" id="gender" class="form-input" disabled>
                        <option value="">Auto-detected from NIC</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Marital Status</label>
                    <select name="marital_status" id="marital_status" class="form-input">
                        <option value="">Select Marital Status</option>
                        <option value="Single">Single</option>
                        <option value="Married">Married</option>
                        <option value="Divorced">Divorced</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Driving Licence Number</label>
                    <input type="text" name="driving_licence_number" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Blood Group</label>
                    <input type="text" name="blood_group" class="form-input">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Telephone (Home)</label>
                    <input type="text" name="telephone_home" class="form-input">
                </div>
                <div class="form-group required-field">
                    <label class="form-label">Telephone (Mobile) <span class="required">*</span></label>
                    <input type="text" name="telephone_mobile" class="form-input" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Telephone (Office)</label>
                    <input type="text" name="telephone_office" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Whatsapp Number</label>
                    <input type="text" name="whatsapp_number" class="form-input">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email_address" class="form-input">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Address</label>
                <textarea name="address" class="form-input" rows="3" placeholder="Enter full residential address" style="resize:vertical;"></textarea>
            </div>

            <div class="form-row-4">
                <div class="form-group">
                    <label class="form-label">EPF/ETF Assignee Name</label>
                    <input type="text" name="epf_etf_assignee_name" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">EPF Assignee Contact No</label>
                    <input type="tel" name="epf_assignee_contact" class="form-input" placeholder="Enter assignee contact number">
                </div>
                <div class="form-group">
                    <label class="form-label">Relationship</label>
                    <input type="text" name="relationship" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">NIC Number</label>
                    <input type="text" name="nic_number" class="form-input">
                </div>
            </div>
        </div>
    </div>

    <!-- ───── TAB 2: Bank Account Details ───── -->
    <div class="tab-content" id="tab-1">
        <div class="content-card">
            <h3 class="card-title">Bank Account Details</h3>

            <div class="form-group">
                <label class="form-label">Bank</label>
                <select name="bank_code" id="bank_code" class="form-input select2" onchange="loadBankBranches()">
                    <option value="">Select Bank</option>
                    <?php mysqli_data_seek($banks_result, 0); while ($bank = mysqli_fetch_assoc($banks_result)): ?>
                        <option value="<?php echo $bank['bank_code']; ?>">
                            <?php echo htmlspecialchars($bank['bank_code'] . ' - ' . $bank['bank_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <small class="form-hint">Select bank with bank code</small>
            </div>

            <div class="form-group">
                <label class="form-label">Branch</label>
                <select name="bank_branch_code" id="bank_branch_code" class="form-input select2">
                    <option value="">Select Bank Branch</option>
                </select>
                <small class="form-hint">Branch will load based on selected bank</small>
            </div>

            <div class="form-group">
                <label class="form-label">Acc Number</label>
                <input type="text" name="account_number" class="form-input" placeholder="Enter account number">
            </div>
        </div>
    </div>

    <!-- ───── TAB 3: Salary ───── -->
    <div class="tab-content" id="tab-2">

        <!-- Basic Salary -->
        <div class="content-card">
            <h3 class="card-title">Basic Salary <span class="optional-label">(Optional)</span></h3>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Basic Salary</label>
                    <div class="input-prefix-wrap">
                        <span class="input-prefix">LKR</span>
                        <input type="number" name="basic_salary" class="form-input input-with-prefix" placeholder="0.00" step="0.01" min="0">
                    </div>
                    <small class="form-hint">Monthly basic salary amount (optional)</small>
                </div>
            </div>
        </div>

        <!-- Incentives -->
        <div class="content-card" style="margin-top:24px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                <div>
                    <h3 class="card-title" style="margin-bottom:4px;">Incentives <span class="optional-label">(Optional)</span></h3>
                    <p style="font-size:12px;color:#666;margin:0;">Add one or more incentives for this employee</p>
                </div>
                <button type="button" class="btn btn-add-incentive" onclick="addIncentiveRow()">
                    <i class="fa-solid fa-plus"></i> Add Incentive
                </button>
            </div>

            <!-- Fixed rows: Gratuity, Insurance, Welfare -->
            <div class="incentive-fixed-section">
                <div class="incentive-fixed-row">
                    <div class="incentive-fixed-label">
                        <i class="fa-solid fa-hand-holding-dollar"></i>
                        Gratuity Amount
                    </div>
                    <div class="input-prefix-wrap">
                        <span class="input-prefix">LKR</span>
                        <input type="number" name="gratuity_amount" class="form-input input-with-prefix" placeholder="0.00" step="0.01" min="0">
                    </div>
                </div>
                <div class="incentive-fixed-row">
                    <div class="incentive-fixed-label">
                        <i class="fa-solid fa-shield-halved"></i>
                        Insurance Amount
                    </div>
                    <div class="input-prefix-wrap">
                        <span class="input-prefix">LKR</span>
                        <input type="number" name="insurance_amount" class="form-input input-with-prefix" placeholder="0.00" step="0.01" min="0">
                    </div>
                </div>
                <div class="incentive-fixed-row">
                    <div class="incentive-fixed-label">
                        <i class="fa-solid fa-heart-pulse"></i>
                        Welfare Amount
                    </div>
                    <div class="input-prefix-wrap">
                        <span class="input-prefix">LKR</span>
                        <input type="number" name="welfare_amount" class="form-input input-with-prefix" placeholder="0.00" step="0.01" min="0">
                    </div>
                </div>
            </div>

            <!-- Dynamic incentive rows -->
            <div id="incentive-rows-container" style="margin-top:20px;">
                <!-- Rows added dynamically -->
            </div>

            <p id="no-incentive-msg" style="font-size:13px;color:#999;text-align:center;padding:12px 0;display:none;">
                No additional incentives added. Click "Add Incentive" to add more.
            </p>
        </div>

    </div>

    <!-- ───── TAB 4: Attachments ───── -->
    <div class="tab-content" id="tab-3">
        <div class="content-card">
            <h3 class="card-title">Attachments</h3>

            <div class="form-group">
                <label class="form-label">Profile Picture</label>
                <input type="file" name="profile_picture" id="profile_picture" class="form-input" accept=".jpg,.jpeg,.png,.gif">
                <small class="form-hint">Upload employee profile picture (JPG, PNG, GIF only)</small>
                <div id="profile_preview" style="margin-top:10px;display:none;">
                    <img id="profile_preview_img" src="" alt="Profile Preview" style="max-width:200px;max-height:200px;border-radius:8px;border:1px solid #ddd;">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Application Form</label>
                <input type="file" name="application_form" class="form-input" accept=".pdf,.jpg,.jpeg,.png">
            </div>

            <div class="form-group">
                <label class="form-label">ID Copy</label>
                <input type="file" name="id_copy" class="form-input" accept=".pdf,.jpg,.jpeg,.png">
            </div>

            <div class="form-group">
                <label class="form-label">Driver Licence Copy</label>
                <input type="file" name="driver_licence_copy" class="form-input" accept=".pdf,.jpg,.jpeg,.png">
            </div>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="form-actions">
        <button type="button" class="btn btn-secondary" id="prevBtn" onclick="navigateTabs(-1)" style="display:none;">
            <i class="fa-solid fa-arrow-left"></i> Previous
        </button>
        <button type="button" class="btn btn-primary" id="nextBtn" onclick="navigateTabs(1)">
            Next <i class="fa-solid fa-arrow-right"></i>
        </button>
        <button type="submit" class="btn btn-success" id="submitBtn" style="display:none;">
            <i class="fa-solid fa-check"></i> Submit
        </button>
    </div>

</form>

<!-- Pass incentive types to JS -->
<script>
const incentiveTypes = <?php echo json_encode($incentive_types); ?>;
</script>

<style>
/* ── Required / Alert ── */
.required-field .form-input,
.required-field .select2-container--default .select2-selection--single { background-color: #fffbeb !important; }
.required { color: #ef4444; }
.optional-label { font-size: 12px; font-weight: 400; color: #9ca3af; }

.alert { padding:16px 20px; border-radius:8px; margin-bottom:24px; display:flex; align-items:center; gap:12px; font-size:13px; font-weight:500; }
.alert i { font-size:18px; }
.alert-error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }

/* ── Tabs ── */
.tabs { display:flex; gap:4px; margin-bottom:24px; border-bottom:2px solid #e5e5e5; flex-wrap:wrap; }
.tab-btn { padding:14px 22px; background:#fafafa; border:none; border-bottom:3px solid transparent; cursor:pointer; font-size:13px; font-weight:500; color:#666; transition:all .3s; display:flex; align-items:center; gap:8px; }
.tab-btn:hover { background:#f0f0f0; color:#333; }
.tab-btn.active { background:#fff; color:#000; border-bottom-color:#000; font-weight:600; }
.tab-content { display:none; }
.tab-content.active { display:block; animation:fadeIn .3s; }
@keyframes fadeIn { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }

/* ── Grid helpers ── */
.form-row   { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.form-row-4 { display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:20px; }

/* ── Form inputs ── */
.form-group { margin-bottom:20px; }
.form-label { display:block; font-size:13px; font-weight:600; margin-bottom:8px; color:#333; }
.form-input { width:100%; padding:12px 16px; border:1px solid #e5e5e5; border-radius:8px; font-size:14px; font-family:'Inter',sans-serif; transition:all .3s; box-sizing:border-box; }
.form-input:focus { outline:none; border-color:#000; box-shadow:0 0 0 3px rgba(0,0,0,.05); }
.form-input:disabled, .form-input[readonly] { background:#f5f5f5; cursor:not-allowed; }
.form-hint { display:block; font-size:11px; color:#666; margin-top:6px; }

/* ── Currency input ── */
.input-prefix-wrap { display:flex; align-items:center; border:1px solid #e5e5e5; border-radius:8px; overflow:hidden; transition:border-color .2s; }
.input-prefix-wrap:focus-within { border-color:#000; box-shadow:0 0 0 3px rgba(0,0,0,.05); }
.input-prefix { padding:0 14px; font-size:13px; font-weight:600; color:#888; background:#f5f5f5; height:46px; display:flex; align-items:center; border-right:1px solid #e5e5e5; white-space:nowrap; }
.input-with-prefix { border:none !important; border-radius:0 !important; box-shadow:none !important; flex:1; }

/* ── Fixed incentive rows ── */
.incentive-fixed-section { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; }
.incentive-fixed-row { background:#fafafa; border:1px solid #e5e5e5; border-radius:10px; padding:16px; }
.incentive-fixed-label { display:flex; align-items:center; gap:8px; font-size:12px; font-weight:700; color:#444; text-transform:uppercase; letter-spacing:.4px; margin-bottom:12px; }
.incentive-fixed-label i { font-size:14px; color:#666; }

/* ── Dynamic incentive rows ── */
.incentive-dynamic-row { display:grid; grid-template-columns:1fr 200px 40px; gap:12px; align-items:end; padding:14px 16px; background:#fafafa; border:1px solid #e5e5e5; border-radius:10px; margin-bottom:10px; }
.btn-remove-incentive { width:36px; height:36px; display:flex; align-items:center; justify-content:center; border:none; border-radius:8px; background:#fef2f2; color:#dc2626; cursor:pointer; transition:all .2s; font-size:14px; flex-shrink:0; }
.btn-remove-incentive:hover { background:#dc2626; color:#fff; }

/* ── Add incentive button ── */
.btn-add-incentive { display:inline-flex; align-items:center; gap:8px; padding:10px 18px; background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; transition:all .2s; }
.btn-add-incentive:hover { background:#166534; color:#fff; border-color:#166534; }

/* ── Buttons ── */
.form-actions { display:flex; gap:12px; justify-content:flex-end; margin-top:24px; padding:20px; background:#fafafa; border-radius:8px; }
.btn { display:inline-flex; align-items:center; gap:8px; padding:12px 24px; border:none; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; transition:all .3s; text-decoration:none; font-family:'Inter',sans-serif; }
.btn-primary { background:#000; color:#fff; }
.btn-primary:hover { background:#333; }
.btn-secondary { background:#f5f5f5; color:#333; border:1px solid #e5e5e5; }
.btn-secondary:hover { background:#e5e5e5; }
.btn-success { background:#22c55e; color:#fff; }
.btn-success:hover { background:#16a34a; }

/* ── Select2 ── */
.select2-container--default .select2-selection--single { height:46px !important; border:1px solid #e5e5e5 !important; border-radius:8px !important; padding:8px !important; }
.select2-container--default .select2-selection--single .select2-selection__rendered { line-height:28px !important; padding-left:8px !important; }
.select2-container--default.select2-container--focus .select2-selection--single { border-color:#000 !important; box-shadow:0 0 0 3px rgba(0,0,0,.05) !important; }

/* ── Responsive ── */
@media (max-width:900px) {
    .incentive-fixed-section { grid-template-columns:1fr 1fr; }
    .form-row-4 { grid-template-columns:1fr 1fr; }
}
@media (max-width:640px) {
    .form-row, .form-row-4, .incentive-fixed-section { grid-template-columns:1fr; }
    .tabs { flex-direction:column; }
    .form-actions { flex-direction:column; }
    .form-actions .btn { width:100%; justify-content:center; }
    .incentive-dynamic-row { grid-template-columns:1fr 140px 40px; }
}
</style>

<!-- jQuery + Select2 -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
const TOTAL_TABS = 4;
let currentTab = 0;

$(document).ready(function() {
    $('.select2').select2({ width: '100%' });
});

/* ── Tab navigation ── */
function switchTab(tabIndex) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + tabIndex).classList.add('active');
    document.querySelectorAll('.tab-btn')[tabIndex].classList.add('active');
    currentTab = tabIndex;
    updateButtons();
}

function navigateTabs(dir) {
    const next = currentTab + dir;
    if (next >= 0 && next < TOTAL_TABS) switchTab(next);
}

function updateButtons() {
    document.getElementById('prevBtn').style.display   = currentTab === 0              ? 'none' : 'inline-flex';
    document.getElementById('nextBtn').style.display   = currentTab === TOTAL_TABS - 1 ? 'none' : 'inline-flex';
    document.getElementById('submitBtn').style.display = currentTab === TOTAL_TABS - 1 ? 'inline-flex' : 'none';
}

/* ── Dropdowns ── */
function loadBranches() {
    const id = $('#company_id').val();
    $('#branch_id').html('<option value="">Select Branch</option>');
    if (id) {
        $.get('get_branches.php?company_id=' + id, function(data) {
            data.forEach(b => $('#branch_id').append(new Option(b.branch_code + ' - ' + b.branch_name, b.id)));
        }, 'json');
    }
}

function loadBankBranches() {
    const code = $('#bank_code').val();
    $('#bank_branch_code').html('<option value="">Select Bank Branch</option>');
    if (code) {
        $.get('get_bank_branches.php?bank_code=' + code, function(data) {
            data.forEach(b => $('#bank_branch_code').append(new Option(b.branch_code + ' - ' + b.branch_name, b.branch_code)));
        }, 'json');
    }
}

function generateEmployeeId() {
    const companyId     = $('#company_id').val();
    const designationId = $('#designation_id').val();
    if (companyId && designationId) {
        $.get('get_designation_info.php?designation_id=' + designationId, function(data) {
            const companyCode = $('#company_id option:selected').data('code');
            if (data && data.designation_code && data.category_code && companyCode) {
                $('#employee_id_display').val(companyCode + '/' + data.category_code + '/' + data.designation_code + '/01 (Preview)');
            }
        }, 'json').fail(function() {
            const cc = $('#company_id option:selected').data('code');
            const dc = $('#designation_id option:selected').data('code');
            if (cc && dc) $('#employee_id_display').val(cc + '/[CATEGORY]/' + dc + '/01 (Preview)');
        });
    } else {
        $('#employee_id_display').val('');
    }
}

/* ── NIC Parser ── */
function parseNIC() {
    const nicInput  = document.getElementById('id_number');
    const dobField  = document.getElementById('date_of_birth');
    const genderField = document.getElementById('gender');
    if (!nicInput) return;
    dobField.value  = '';
    genderField.value = '';
    const NICNo = nicInput.value.trim();
    let dayText = 0, year = '', gender = '';

    if (NICNo.length != 10 && NICNo.length != 12) {
        if (NICNo.length > 0) alert('Invalid NIC number. Please enter a valid 10 or 12 digit NIC number.');
        return;
    }
    if (NICNo.length == 10 && !/^\d{9}[VvXx]$/.test(NICNo)) {
        alert('Invalid NIC number. First 9 characters must be numeric followed by V or X.');
        return;
    }

    if (NICNo.length == 10) { year = "19" + NICNo.substr(0, 2); dayText = parseInt(NICNo.substr(2, 3)); }
    else                    { year = NICNo.substr(0, 4);        dayText = parseInt(NICNo.substr(4, 3)); }

    if (dayText > 500) { gender = "female"; dayText -= 500; } else { gender = "male"; }
    if (dayText < 1 || dayText > 366) { alert('Invalid NIC number. Invalid day of year.'); return; }

    const months = [31,28,31,30,31,30,31,31,30,31,30,31];
    const isLeap = (year%4===0 && year%100!==0)||(year%400===0);
    if (isLeap) months[1] = 29;

    let rem = dayText, month = 0, day = 0;
    for (let i = 0; i < months.length; i++) {
        if (rem <= months[i]) { month = i + 1; day = rem; break; }
        rem -= months[i];
    }

    const formattedDate = year + '-' + String(month).padStart(2,'0') + '-' + String(day).padStart(2,'0');
    dobField.value    = formattedDate;
    genderField.value = gender;
    genderField.disabled = false;
}

/* ── Dynamic Incentive Rows ── */
let incentiveRowCount = 0;

function buildTypeOptions(selectedId = '') {
    let opts = '<option value="">Select Incentive Type</option>';
    incentiveTypes.forEach(t => {
        const sel = t.id == selectedId ? ' selected' : '';
        opts += `<option value="${t.id}"${sel}>${t.type_name}</option>`;
    });
    return opts;
}

function addIncentiveRow() {
    const container = document.getElementById('incentive-rows-container');
    document.getElementById('no-incentive-msg').style.display = 'none';
    const idx = incentiveRowCount++;
    const row = document.createElement('div');
    row.className = 'incentive-dynamic-row';
    row.id = 'inc-row-' + idx;
    row.innerHTML = `
        <div class="form-group" style="margin:0;">
            <label class="form-label">Incentive Type</label>
            <select name="incentive_type_id[]" class="form-input inc-type-select">
                ${buildTypeOptions()}
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">Amount</label>
            <div class="input-prefix-wrap">
                <span class="input-prefix">LKR</span>
                <input type="number" name="incentive_amount[]" class="form-input input-with-prefix" placeholder="0.00" step="0.01" min="0">
            </div>
        </div>
        <button type="button" class="btn-remove-incentive" onclick="removeIncentiveRow('inc-row-${idx}')" title="Remove">
            <i class="fa-solid fa-xmark"></i>
        </button>
    `;
    container.appendChild(row);

    // Init select2 on new select
    $(row).find('.inc-type-select').select2({ width: '100%' });
}

function removeIncentiveRow(id) {
    const el = document.getElementById(id);
    if (el) el.remove();
    if (document.querySelectorAll('.incentive-dynamic-row').length === 0) {
        document.getElementById('no-incentive-msg').style.display = 'block';
    }
}

/* ── Profile picture preview ── */
document.getElementById('profile_picture').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('profile_preview');
    const img     = document.getElementById('profile_preview_img');
    if (file) {
        const reader = new FileReader();
        reader.onload = ev => { img.src = ev.target.result; preview.style.display = 'block'; };
        reader.readAsDataURL(file);
    } else {
        preview.style.display = 'none';
    }
});

updateButtons();
</script>

<?php include 'footer.php'; ?>