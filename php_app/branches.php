<?php
include 'config.php';

// Create branches table if not exists
$createBranchTable = "CREATE TABLE IF NOT EXISTS branches (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    company_id INT(11) NOT NULL,
    branch_code VARCHAR(50) NOT NULL UNIQUE,
    branch_name VARCHAR(255) NOT NULL,
    address TEXT NULL,
    contact_no VARCHAR(20) NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_id (company_id),
    INDEX idx_branch_code (branch_code)
)";
mysqli_query($conn, $createBranchTable);

// Handle Delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $sql = "DELETE FROM branches WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        $success_message = "Branch deleted successfully!";
    } else {
        $error_message = "Error deleting branch: " . mysqli_error($conn);
    }
}

// Handle form submission (Create or Update)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $company_id = intval($_POST['company_id']);
    $branch_code = mysqli_real_escape_string($conn, $_POST['branch_code']);
    $branch_name = mysqli_real_escape_string($conn, $_POST['branch_name']);
    $address = mysqli_real_escape_string($conn, $_POST['address']);
    $contact_no = mysqli_real_escape_string($conn, $_POST['contact_no']);
    $active = isset($_POST['active']) ? 1 : 0;
    
    if (isset($_POST['branch_id']) && !empty($_POST['branch_id'])) {
        // Update existing branch
        $id = intval($_POST['branch_id']);
        $sql = "UPDATE branches SET 
                company_id = '$company_id',
                branch_code = '$branch_code', 
                branch_name = '$branch_name',
                address = '$address',
                contact_no = '$contact_no',
                active = '$active' 
                WHERE id = $id";
        
        if (mysqli_query($conn, $sql)) {
            $success_message = "Branch updated successfully!";
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    } else {
        // Check if branch code already exists
        $check_sql = "SELECT id FROM branches WHERE branch_code = '$branch_code'";
        $check_result = mysqli_query($conn, $check_sql);
        
        if (mysqli_num_rows($check_result) > 0) {
            $error_message = "Branch code already exists. Please use a different code.";
        } else {
            // Insert new branch
            $sql = "INSERT INTO branches (company_id, branch_code, branch_name, address, contact_no, active) 
                    VALUES ('$company_id', '$branch_code', '$branch_name', '$address', '$contact_no', '$active')";
            
            if (mysqli_query($conn, $sql)) {
                $success_message = "Branch created successfully!";
            } else {
                $error_message = "Error: " . mysqli_error($conn);
            }
        }
    }
}

// Get all branches with company names
$branches_sql = "SELECT b.*, c.company_name, c.company_code 
                 FROM branches b 
                 LEFT JOIN companies c ON b.company_id = c.id 
                 ORDER BY b.created_at DESC";
$branches_result = mysqli_query($conn, $branches_sql);

// Get all active companies for dropdown
$companies_sql = "SELECT id, company_code, company_name FROM companies WHERE active = 1 ORDER BY company_name ASC";
$companies_result = mysqli_query($conn, $companies_sql);

include 'header.php';
?>

<!-- Branches Page -->
<div class="page-header">
    <h2 class="page-title">Branch Management</h2>
    <p class="page-subtitle">Manage all company branches</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i>
    <?php echo $success_message; ?>
</div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
<div class="alert alert-error">
    <i class="fa-solid fa-circle-exclamation"></i>
    <?php echo $error_message; ?>
</div>
<?php endif; ?>

<!-- Add Branch Button -->
<div style="margin-bottom: 20px;">
    <button onclick="openModal()" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i>
        Add New Branch
    </button>
</div>

<!-- Branches List -->
<div class="content-card">
    <h3 class="card-title">All Branches</h3>
    
    <?php if (mysqli_num_rows($branches_result) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Company</th>
                    <th>Branch Code</th>
                    <th>Branch Name</th>
                    <th>Address</th>
                    <th>Contact No</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($branch = mysqli_fetch_assoc($branches_result)): ?>
                <tr>
                    <td><?php echo $branch['id']; ?></td>
                    <td>
                        <strong><?php echo htmlspecialchars($branch['company_code']); ?></strong><br>
                        <small style="color: #666;"><?php echo htmlspecialchars($branch['company_name']); ?></small>
                    </td>
                    <td><strong><?php echo htmlspecialchars($branch['branch_code']); ?></strong></td>
                    <td><?php echo htmlspecialchars($branch['branch_name']); ?></td>
                    <td><?php echo htmlspecialchars($branch['address']) ?: '<span style="color: #999;">-</span>'; ?></td>
                    <td><?php echo htmlspecialchars($branch['contact_no']) ?: '<span style="color: #999;">-</span>'; ?></td>
                    <td>
                        <?php if ($branch['active']): ?>
                            <span class="badge badge-success">
                                <i class="fa-solid fa-circle-check"></i> Active
                            </span>
                        <?php else: ?>
                            <span class="badge badge-inactive">
                                <i class="fa-solid fa-circle-xmark"></i> Inactive
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="#" 
                               onclick="editBranch(<?php echo htmlspecialchars(json_encode($branch)); ?>)" 
                               class="btn-action btn-edit" 
                               title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <a href="?delete=<?php echo $branch['id']; ?>" 
                               class="btn-action btn-delete" 
                               title="Delete"
                               onclick="return confirm('Are you sure you want to delete this branch?')">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="color: #666; font-size: 13px; text-align: center; padding: 20px;">No branches found. Create your first branch using the button above.</p>
    <?php endif; ?>
</div>

<!-- Modal Dialog -->
<div id="branchModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add New Branch</h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        
        <form method="POST" action="" id="branchForm">
            <input type="hidden" name="branch_id" id="branch_id">
            
            <div class="modal-body">
                <div class="form-group">
                    <label for="company_id" class="form-label">
                        Company <span class="required">*</span>
                    </label>
                    <select id="company_id" name="company_id" class="form-input" required>
                        <option value="">Select Company</option>
                        <?php 
                        mysqli_data_seek($companies_result, 0);
                        while ($company = mysqli_fetch_assoc($companies_result)): 
                        ?>
                            <option value="<?php echo $company['id']; ?>">
                                <?php echo htmlspecialchars($company['company_code'] . ' - ' . $company['company_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="branch_code" class="form-label">
                        Branch Code <span class="required">*</span>
                    </label>
                    <input 
                        type="text" 
                        id="branch_code" 
                        name="branch_code" 
                        class="form-input" 
                        placeholder="Enter branch code (e.g., BR001)"
                        pattern="[A-Za-z0-9]+"
                        title="Only letters and numbers allowed"
                        required
                    >
                    <small class="form-hint">Unique code for the branch</small>
                </div>

                <div class="form-group">
                    <label for="branch_name" class="form-label">
                        Branch Name <span class="required">*</span>
                    </label>
                    <input 
                        type="text" 
                        id="branch_name" 
                        name="branch_name" 
                        class="form-input" 
                        placeholder="Enter branch name"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="address" class="form-label">
                        Address <span class="optional">(Optional)</span>
                    </label>
                    <textarea 
                        id="address" 
                        name="address" 
                        class="form-input form-textarea" 
                        placeholder="Enter branch address"
                        rows="3"
                    ></textarea>
                </div>

                <div class="form-group">
                    <label for="contact_no" class="form-label">
                        Contact Number <span class="optional">(Optional)</span>
                    </label>
                    <input 
                        type="text" 
                        id="contact_no" 
                        name="contact_no" 
                        class="form-input" 
                        placeholder="Enter contact number"
                    >
                </div>

                <div class="form-group">
                    <label class="form-label">Status</label>
                    <div class="checkbox-wrapper">
                        <label class="checkbox-label">
                            <input 
                                type="checkbox" 
                                name="active" 
                                id="active" 
                                class="form-checkbox"
                                checked
                            >
                            <span class="checkbox-text">Active</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">
                    <i class="fa-solid fa-xmark"></i>
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check"></i>
                    <span id="submitBtnText">Save Branch</span>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Alert Styles */
.alert {
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13px;
    font-weight: 500;
}

.alert i {
    font-size: 18px;
}

.alert-success {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.alert-error {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    animation: fadeIn 0.3s;
}

.modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideDown {
    from { 
        opacity: 0;
        transform: translateY(-50px);
    }
    to { 
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-content {
    background: #ffffff;
    border-radius: 12px;
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    animation: slideDown 0.3s;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 24px;
    border-bottom: 1px solid #e5e5e5;
}

.modal-title {
    font-size: 18px;
    font-weight: 600;
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666666;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: all 0.2s;
}

.modal-close:hover {
    background: #f5f5f5;
    color: #000000;
}

.modal-body {
    padding: 24px;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 20px 24px;
    border-top: 1px solid #e5e5e5;
}

/* Form Styles */
.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 8px;
    color: #333333;
}

.required {
    color: #ef4444;
}

.optional {
    color: #999999;
    font-weight: 400;
    font-size: 12px;
}

.form-input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    transition: all 0.3s;
    background: #ffffff;
}

.form-input:focus {
    outline: none;
    border-color: #000000;
    box-shadow: 0 0 0 3px rgba(0,0,0,0.05);
}

.form-input::placeholder {
    color: #999999;
}

.form-textarea {
    resize: vertical;
    min-height: 80px;
}

.form-hint {
    display: block;
    font-size: 11px;
    color: #666666;
    margin-top: 6px;
}

select.form-input {
    cursor: pointer;
}

/* Checkbox Styles */
.checkbox-wrapper {
    display: flex;
    align-items: center;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    font-size: 14px;
    color: #333333;
}

.form-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: #000000;
}

.checkbox-text {
    font-weight: 500;
}

/* Button Styles */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
    font-family: 'Inter', sans-serif;
}

.btn i {
    font-size: 16px;
}

.btn-primary {
    background: #000000;
    color: #ffffff;
}

.btn-primary:hover {
    background: #333333;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.btn-secondary {
    background: #f5f5f5;
    color: #333333;
    border: 1px solid #e5e5e5;
}

.btn-secondary:hover {
    background: #e5e5e5;
}

/* Table Styles */
.table-responsive {
    overflow-x: auto;
    margin-top: 20px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.data-table thead {
    background: #fafafa;
    border-bottom: 2px solid #e5e5e5;
}

.data-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: #333333;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.data-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.2s;
}

.data-table tbody tr:hover {
    background: #fafafa;
}

.data-table td {
    padding: 14px 16px;
    color: #333333;
}

/* Badge Styles */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.badge i {
    font-size: 10px;
}

.badge-success {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.badge-inactive {
    background: #fafafa;
    color: #666666;
    border: 1px solid #e5e5e5;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 8px;
}

.btn-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 6px;
    border: 1px solid #e5e5e5;
    background: #ffffff;
    color: #666666;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}

.btn-action:hover {
    transform: translateY(-2px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.btn-edit:hover {
    background: #000000;
    color: #ffffff;
    border-color: #000000;
}

.btn-delete:hover {
    background: #ef4444;
    color: #ffffff;
    border-color: #ef4444;
}

/* Responsive */
@media (max-width: 768px) {
    .modal-content {
        width: 95%;
        margin: 20px;
    }
    
    .modal-header,
    .modal-body,
    .modal-footer {
        padding: 16px;
    }
    
    .modal-footer {
        flex-direction: column;
    }
    
    .modal-footer .btn {
        width: 100%;
        justify-content: center;
    }
    
    .table-responsive {
        overflow-x: auto;
    }
    
    .data-table {
        font-size: 12px;
    }
    
    .data-table th,
    .data-table td {
        padding: 10px;
    }
}
</style>

<script>
function openModal() {
    document.getElementById('branchModal').classList.add('active');
    document.getElementById('branchForm').reset();
    document.getElementById('branch_id').value = '';
    document.getElementById('modalTitle').textContent = 'Add New Branch';
    document.getElementById('submitBtnText').textContent = 'Save Branch';
}

function closeModal() {
    document.getElementById('branchModal').classList.remove('active');
}

function editBranch(branch) {
    document.getElementById('branchModal').classList.add('active');
    document.getElementById('branch_id').value = branch.id;
    document.getElementById('company_id').value = branch.company_id;
    document.getElementById('branch_code').value = branch.branch_code;
    document.getElementById('branch_name').value = branch.branch_name;
    document.getElementById('address').value = branch.address || '';
    document.getElementById('contact_no').value = branch.contact_no || '';
    document.getElementById('active').checked = branch.active == 1;
    document.getElementById('modalTitle').textContent = 'Edit Branch';
    document.getElementById('submitBtnText').textContent = 'Update Branch';
}
</script>

<?php include 'footer.php'; ?>