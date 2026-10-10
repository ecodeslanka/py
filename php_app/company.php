<?php
include 'config.php';

// Handle Delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $sql = "DELETE FROM companies WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        $success_message = "Company deleted successfully!";
    } else {
        $error_message = "Error deleting company: " . mysqli_error($conn);
    }
}

// Handle Edit - Get company data
$edit_mode = false;
$edit_company = null;
if (isset($_GET['edit'])) {
    $edit_mode = true;
    $id = intval($_GET['edit']);
    $sql = "SELECT * FROM companies WHERE id = $id";
    $result = mysqli_query($conn, $sql);
    $edit_company = mysqli_fetch_assoc($result);
}

// Handle form submission (Create or Update)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $company_code = mysqli_real_escape_string($conn, $_POST['company_code']);
    $company_name = mysqli_real_escape_string($conn, $_POST['company_name']);
    $active = isset($_POST['active']) ? 1 : 0;
    
    if (isset($_POST['company_id']) && !empty($_POST['company_id'])) {
        // Update existing company
        $id = intval($_POST['company_id']);
        $sql = "UPDATE companies SET company_code = '$company_code', company_name = '$company_name', active = '$active' WHERE id = $id";
        
        if (mysqli_query($conn, $sql)) {
            $success_message = "Company updated successfully!";
            $edit_mode = false;
            $edit_company = null;
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    } else {
        // Check if company code already exists
        $check_sql = "SELECT id FROM companies WHERE company_code = '$company_code'";
        $check_result = mysqli_query($conn, $check_sql);
        
        if (mysqli_num_rows($check_result) > 0) {
            $error_message = "Company code already exists. Please use a different code.";
        } else {
            // Insert new company
            $sql = "INSERT INTO companies (company_code, company_name, active) VALUES ('$company_code', '$company_name', '$active')";
            
            if (mysqli_query($conn, $sql)) {
                $success_message = "Company created successfully!";
            } else {
                $error_message = "Error: " . mysqli_error($conn);
            }
        }
    }
}

// Get all companies
$companies_sql = "SELECT * FROM companies ORDER BY created_at DESC";
$companies_result = mysqli_query($conn, $companies_sql);

include 'header.php';
?>

<!-- Company Creation Page -->
<div class="page-header">
    <h2 class="page-title"><?php echo $edit_mode ? 'Edit Company' : 'Create New Company'; ?></h2>
    <p class="page-subtitle"><?php echo $edit_mode ? 'Update company information' : 'Add a new company to the system'; ?></p>
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

<div class="content-card">
    <h3 class="card-title">Company Information</h3>
    
    <form method="POST" action="" class="form">
        <?php if ($edit_mode && $edit_company): ?>
            <input type="hidden" name="company_id" value="<?php echo $edit_company['id']; ?>">
        <?php endif; ?>
        
        <div class="form-group">
            <label for="company_code" class="form-label">
                Company Code <span class="required">*</span>
            </label>
            <input 
                type="text" 
                id="company_code" 
                name="company_code" 
                class="form-input" 
                placeholder="Enter company code (e.g., YG001)"
                value="<?php echo $edit_mode && $edit_company ? htmlspecialchars($edit_company['company_code']) : ''; ?>"
                pattern="[A-Za-z0-9]+"
                title="Only letters and numbers allowed"
                required
            >
            <small class="form-hint">Unique code for the company (letters and numbers only)</small>
        </div>
        
        <div class="form-group">
            <label for="company_name" class="form-label">
                Company Name <span class="required">*</span>
            </label>
            <input 
                type="text" 
                id="company_name" 
                name="company_name" 
                class="form-input" 
                placeholder="Enter company name"
                value="<?php echo $edit_mode && $edit_company ? htmlspecialchars($edit_company['company_name']) : ''; ?>"
                required
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
                        <?php echo ($edit_mode && $edit_company && $edit_company['active']) || !$edit_mode ? 'checked' : ''; ?>
                    >
                    <span class="checkbox-text">Active</span>
                </label>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-<?php echo $edit_mode ? 'check' : 'plus'; ?>"></i>
                <?php echo $edit_mode ? 'Update Company' : 'Create Company'; ?>
            </button>
            <a href="create_company.php" class="btn btn-secondary">
                <i class="fa-solid fa-xmark"></i>
                Cancel
            </a>
        </div>
    </form>
</div>


<!-- Companies List -->
<div class="content-card">
    <h3 class="card-title">All Companies</h3>
    
    <?php if (mysqli_num_rows($companies_result) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Company Code</th>
                    <th>Company Name</th>
                    <th>Status</th>
                    <th>Created Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($company = mysqli_fetch_assoc($companies_result)): ?>
                <tr>
                    <td><?php echo $company['id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($company['company_code']); ?></strong></td>
                    <td><?php echo htmlspecialchars($company['company_name']); ?></td>
                    <td>
                        <?php if ($company['active']): ?>
                            <span class="badge badge-success">
                                <i class="fa-solid fa-circle-check"></i> Active
                            </span>
                        <?php else: ?>
                            <span class="badge badge-inactive">
                                <i class="fa-solid fa-circle-xmark"></i> Inactive
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('M d, Y', strtotime($company['created_at'])); ?></td>
                    <td>
                        <div class="action-buttons">
                            <a href="?edit=<?php echo $company['id']; ?>" class="btn-action btn-edit" title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <a href="?delete=<?php echo $company['id']; ?>" 
                               class="btn-action btn-delete" 
                               title="Delete"
                               onclick="return confirm('Are you sure you want to delete this company?')">
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
    <p style="color: #666; font-size: 13px; text-align: center; padding: 20px;">No companies found. Create your first company above.</p>
    <?php endif; ?>
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

/* Form Styles */
.form {
    max-width: 600px;
}

.form-group {
    margin-bottom: 24px;
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

.form-hint {
    display: block;
    font-size: 11px;
    color: #666666;
    margin-top: 6px;
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
.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 32px;
}

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

/* Responsive */
@media (max-width: 768px) {
    .form {
        max-width: 100%;
    }

    .form-actions {
        flex-direction: column;
    }

    .btn {
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
</style>

<?php include 'footer.php'; ?>