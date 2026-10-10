<?php
include 'config.php';

// Create users table if not exists
$createUsersTable = "CREATE TABLE IF NOT EXISTS users (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    description TEXT NULL,
    role_id INT(11) NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL
)";
mysqli_query($conn, $createUsersTable);

// Create user_companies table for many-to-many relationship
$createUserCompaniesTable = "CREATE TABLE IF NOT EXISTS user_companies (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    company_id INT(11) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_company (user_id, company_id)
)";
mysqli_query($conn, $createUserCompaniesTable);

// Create user_branches table for many-to-many relationship
$createUserBranchesTable = "CREATE TABLE IF NOT EXISTS user_branches (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    branch_id INT(11) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_branch (user_id, branch_id)
)";
mysqli_query($conn, $createUserBranchesTable);

// Handle Delete User
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $sql = "DELETE FROM users WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        $success_message = "User deleted successfully!";
    } else {
        $error_message = "Error deleting user: " . mysqli_error($conn);
    }
}

// Handle form submission (Create or Update)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $role_id = !empty($_POST['role_id']) ? intval($_POST['role_id']) : null;
    $active = isset($_POST['active']) ? 1 : 0;
    
    if (isset($_POST['user_id']) && !empty($_POST['user_id'])) {
        // Update existing user
        $id = intval($_POST['user_id']);
        
        // Update password only if provided
        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $sql = "UPDATE users SET 
                    username = '$username', 
                    password = '$password',
                    description = '$description',
                    role_id = " . ($role_id ? "'$role_id'" : "NULL") . ",
                    active = '$active' 
                    WHERE id = $id";
        } else {
            $sql = "UPDATE users SET 
                    username = '$username', 
                    description = '$description',
                    role_id = " . ($role_id ? "'$role_id'" : "NULL") . ",
                    active = '$active' 
                    WHERE id = $id";
        }
        
        if (mysqli_query($conn, $sql)) {
            // Delete existing company assignments
            mysqli_query($conn, "DELETE FROM user_companies WHERE user_id = $id");
            
            // Insert new company assignments
            if (isset($_POST['companies']) && is_array($_POST['companies'])) {
                foreach ($_POST['companies'] as $company_id) {
                    $company_id = intval($company_id);
                    mysqli_query($conn, "INSERT INTO user_companies (user_id, company_id) VALUES ($id, $company_id)");
                }
            }
            
            // Delete existing branch assignments
            mysqli_query($conn, "DELETE FROM user_branches WHERE user_id = $id");
            
            // Insert new branch assignments
            if (isset($_POST['branches']) && is_array($_POST['branches'])) {
                foreach ($_POST['branches'] as $branch_id) {
                    $branch_id = intval($branch_id);
                    mysqli_query($conn, "INSERT INTO user_branches (user_id, branch_id) VALUES ($id, $branch_id)");
                }
            }
            
            $success_message = "User updated successfully!";
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    } else {
        // Validate password
        if (empty($_POST['password']) || empty($_POST['retype_password'])) {
            $error_message = "Password and Retype Password are required!";
        } elseif ($_POST['password'] !== $_POST['retype_password']) {
            $error_message = "Passwords do not match!";
        } else {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            
            // Check if username exists
            $check_sql = "SELECT id FROM users WHERE username = '$username'";
            $check_result = mysqli_query($conn, $check_sql);
            
            if (mysqli_num_rows($check_result) > 0) {
                $error_message = "Username already exists!";
            } else {
                // Insert new user
                $sql = "INSERT INTO users (username, password, description, role_id, active) 
                        VALUES ('$username', '$password', '$description', " . 
                        ($role_id ? "'$role_id'" : "NULL") . ", '$active')";
                
                if (mysqli_query($conn, $sql)) {
                    $user_id = mysqli_insert_id($conn);
                    
                    // Insert company assignments
                    if (isset($_POST['companies']) && is_array($_POST['companies'])) {
                        foreach ($_POST['companies'] as $company_id) {
                            $company_id = intval($company_id);
                            mysqli_query($conn, "INSERT INTO user_companies (user_id, company_id) VALUES ($user_id, $company_id)");
                        }
                    }
                    
                    // Insert branch assignments
                    if (isset($_POST['branches']) && is_array($_POST['branches'])) {
                        foreach ($_POST['branches'] as $branch_id) {
                            $branch_id = intval($branch_id);
                            mysqli_query($conn, "INSERT INTO user_branches (user_id, branch_id) VALUES ($user_id, $branch_id)");
                        }
                    }
                    
                    $success_message = "User created successfully!";
                } else {
                    $error_message = "Error: " . mysqli_error($conn);
                }
            }
        }
    }
}

// Get all users with role info
$users_sql = "SELECT u.*, r.role_name 
              FROM users u 
              LEFT JOIN roles r ON u.role_id = r.id 
              ORDER BY u.created_at DESC";
$users_result = mysqli_query($conn, $users_sql);

// Get all active companies
$companies_sql = "SELECT id, company_code, company_name FROM companies WHERE active = 1 ORDER BY company_name ASC";
$companies_result = mysqli_query($conn, $companies_sql);

// Get all active roles
$roles_sql = "SELECT id, role_name FROM roles WHERE active = 1 ORDER BY role_name ASC";
$roles_result = mysqli_query($conn, $roles_sql);

include 'header.php';
?>

<!-- Users Page -->
<div class="page-header">
    <h2 class="page-title">User Management</h2>
    <p class="page-subtitle">Manage system users and their access</p>
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

<!-- Add User Button -->
<div style="margin-bottom: 20px;">
    <button onclick="openModal()" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i>
        Add New User
    </button>
</div>

<!-- Users List -->
<div class="content-card">
    <h3 class="card-title">All Users</h3>
    
    <?php if (mysqli_num_rows($users_result) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Companies</th>
                    <th>Role</th>
                    <th>Branches</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($user = mysqli_fetch_assoc($users_result)): ?>
                <?php
                    // Get user companies
                    $user_companies_sql = "SELECT c.company_code, c.company_name 
                                          FROM user_companies uc 
                                          JOIN companies c ON uc.company_id = c.id 
                                          WHERE uc.user_id = " . $user['id'];
                    $user_companies_result = mysqli_query($conn, $user_companies_sql);
                    $company_names = [];
                    while ($uc = mysqli_fetch_assoc($user_companies_result)) {
                        $company_names[] = $uc['company_code'] . ' - ' . $uc['company_name'];
                    }
                    
                    // Get user branches
                    $user_branches_sql = "SELECT b.branch_name 
                                         FROM user_branches ub 
                                         JOIN branches b ON ub.branch_id = b.id 
                                         WHERE ub.user_id = " . $user['id'];
                    $user_branches_result = mysqli_query($conn, $user_branches_sql);
                    $branch_names = [];
                    while ($ub = mysqli_fetch_assoc($user_branches_result)) {
                        $branch_names[] = $ub['branch_name'];
                    }
                ?>
                <tr>
                    <td><?php echo $user['id']; ?></td>
                    <td>
                        <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                        <?php if ($user['description']): ?>
                            <br><small style="color: #666;"><?php echo htmlspecialchars($user['description']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (count($company_names) > 0): ?>
                            <small><?php echo implode('<br>', array_map('htmlspecialchars', $company_names)); ?></small>
                        <?php else: ?>
                            <span style="color: #999;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($user['role_name']): ?>
                            <span class="badge badge-role">
                                <i class="fa-solid fa-shield-halved"></i> 
                                <?php echo htmlspecialchars($user['role_name']); ?>
                            </span>
                        <?php else: ?>
                            <span style="color: #999;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (count($branch_names) > 0): ?>
                            <small><?php echo implode(', ', array_map('htmlspecialchars', $branch_names)); ?></small>
                        <?php else: ?>
                            <span style="color: #999;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($user['active']): ?>
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
                               onclick="editUser(<?php echo $user['id']; ?>)" 
                               class="btn-action btn-edit" 
                               title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <a href="?delete=<?php echo $user['id']; ?>" 
                               class="btn-action btn-delete" 
                               title="Delete"
                               onclick="return confirm('Are you sure you want to delete this user?')">
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
    <p style="color: #666; font-size: 13px; text-align: center; padding: 20px;">No users found. Create your first user using the button above.</p>
    <?php endif; ?>
</div>

<!-- Modal Dialog -->
<div id="userModal" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add New User</h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        
        <form method="POST" action="" id="userForm">
            <input type="hidden" name="user_id" id="user_id">
            
            <div class="modal-body">
                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-user"></i> User Information</h4>
                    
                    <div class="form-group">
                        <label for="username" class="form-label">
                            Username <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="username" 
                            name="username" 
                            class="form-input" 
                            placeholder="Enter username"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="description" class="form-label">
                            Description <span class="optional">(Optional)</span>
                        </label>
                        <textarea 
                            id="description" 
                            name="description" 
                            class="form-input form-textarea" 
                            placeholder="Enter user description"
                            rows="2"
                        ></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="password" class="form-label">
                                Password <span class="required" id="password_required">*</span>
                            </label>
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                class="form-input" 
                                placeholder="Enter password"
                            >
                            <small class="form-hint" id="password_hint">Leave blank to keep current password (for editing)</small>
                        </div>

                        <div class="form-group">
                            <label for="retype_password" class="form-label">
                                Retype Password <span class="required" id="retype_required">*</span>
                            </label>
                            <input 
                                type="password" 
                                id="retype_password" 
                                name="retype_password" 
                                class="form-input" 
                                placeholder="Retype password"
                            >
                        </div>
                    </div>
                </div>

                <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e5e5;">

                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-building"></i> Companies & Branches</h4>
                    
                    <div class="form-group">
                        <label class="form-label">
                            Companies <span class="optional">(Select one or more)</span>
                        </label>
                        <div class="checkbox-group" id="companies_list">
                            <?php 
                            mysqli_data_seek($companies_result, 0);
                            while ($company = mysqli_fetch_assoc($companies_result)): 
                            ?>
                                <div class="branch-checkbox">
                                    <label>
                                        <input 
                                            type="checkbox" 
                                            name="companies[]" 
                                            value="<?php echo $company['id']; ?>" 
                                            class="form-checkbox company-checkbox"
                                            onchange="loadBranches()"
                                        >
                                        <span><?php echo htmlspecialchars($company['company_code'] . ' - ' . $company['company_name']); ?></span>
                                    </label>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>

                    <div class="form-group" id="branches_container" style="display: none;">
                        <label class="form-label">
                            Branches <span class="optional">(Select one or more)</span>
                        </label>
                        <div class="checkbox-group" id="branches_list">
                            <!-- Branches will be loaded here -->
                        </div>
                    </div>
                </div>

                <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e5e5;">

                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-shield-halved"></i> Role & Status</h4>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="role_id" class="form-label">
                                User Role <span class="optional">(Optional)</span>
                            </label>
                            <select id="role_id" name="role_id" class="form-input">
                                <option value="">Select Role</option>
                                <?php 
                                mysqli_data_seek($roles_result, 0);
                                while ($role = mysqli_fetch_assoc($roles_result)): 
                                ?>
                                    <option value="<?php echo $role['id']; ?>">
                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
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
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">
                    <i class="fa-solid fa-xmark"></i>
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check"></i>
                    <span id="submitBtnText">Save User</span>
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
    overflow-y: auto;
    padding: 20px;
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
    max-width: 700px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    animation: slideDown 0.3s;
}

.modal-large {
    max-width: 800px;
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

/* Form Section */
.form-section {
    margin-bottom: 24px;
}

.section-title {
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 16px;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

.section-title i {
    color: #666;
}

/* Form Styles */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

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
    min-height: 60px;
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
    height: 46px;
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

/* Checkbox Group for Branches */
.checkbox-group {
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    padding: 12px;
    max-height: 200px;
    overflow-y: auto;
}

.branch-checkbox {
    display: flex;
    align-items: center;
    padding: 8px;
    border-radius: 4px;
    transition: background 0.2s;
}

.branch-checkbox:hover {
    background: #f5f5f5;
}

.branch-checkbox label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    font-size: 13px;
    width: 100%;
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

.badge-role {
    background: #eff6ff;
    color: #1e40af;
    border: 1px solid #bfdbfe;
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
        margin: 10px;
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
    
    .form-row {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
function openModal() {
    document.getElementById('userModal').classList.add('active');
    document.getElementById('userForm').reset();
    document.getElementById('user_id').value = '';
    document.getElementById('modalTitle').textContent = 'Add New User';
    document.getElementById('submitBtnText').textContent = 'Save User';
    document.getElementById('password').required = true;
    document.getElementById('retype_password').required = true;
    document.getElementById('password_required').style.display = 'inline';
    document.getElementById('retype_required').style.display = 'inline';
    document.getElementById('password_hint').style.display = 'none';
    document.getElementById('branches_container').style.display = 'none';
}

function closeModal() {
    document.getElementById('userModal').classList.remove('active');
}

function loadBranches() {
    const companyCheckboxes = document.querySelectorAll('.company-checkbox:checked');
    const companyIds = Array.from(companyCheckboxes).map(cb => cb.value);
    
    const branchesContainer = document.getElementById('branches_container');
    const branchesList = document.getElementById('branches_list');
    
    if (companyIds.length === 0) {
        branchesContainer.style.display = 'none';
        branchesList.innerHTML = '';
        return;
    }
    
    // Fetch branches for selected companies
    fetch('get_branches.php?company_ids=' + companyIds.join(','))
        .then(response => response.json())
        .then(data => {
            if (data.length > 0) {
                branchesContainer.style.display = 'block';
                branchesList.innerHTML = '';
                
                data.forEach(branch => {
                    const div = document.createElement('div');
                    div.className = 'branch-checkbox';
                    div.innerHTML = `
                        <label>
                            <input type="checkbox" name="branches[]" value="${branch.id}" class="form-checkbox">
                            <span><strong>${branch.company_code}</strong> - ${branch.branch_code} - ${branch.branch_name}</span>
                        </label>
                    `;
                    branchesList.appendChild(div);
                });
            } else {
                branchesContainer.style.display = 'block';
                branchesList.innerHTML = '<p style="color: #999; text-align: center; padding: 10px;">No branches available for selected companies</p>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
}

function editUser(userId) {
    // Fetch user data via AJAX
    fetch('get_user.php?id=' + userId)
        .then(response => response.json())
        .then(data => {
            document.getElementById('userModal').classList.add('active');
            document.getElementById('user_id').value = data.user.id;
            document.getElementById('username').value = data.user.username;
            document.getElementById('description').value = data.user.description || '';
            document.getElementById('role_id').value = data.user.role_id || '';
            document.getElementById('active').checked = data.user.active == 1;
            document.getElementById('password').value = '';
            document.getElementById('retype_password').value = '';
            document.getElementById('password').required = false;
            document.getElementById('retype_password').required = false;
            document.getElementById('password_required').style.display = 'none';
            document.getElementById('retype_required').style.display = 'none';
            document.getElementById('password_hint').style.display = 'block';
            document.getElementById('modalTitle').textContent = 'Edit User';
            document.getElementById('submitBtnText').textContent = 'Update User';
            
            // Uncheck all companies first
            document.querySelectorAll('.company-checkbox').forEach(cb => cb.checked = false);
            
            // Check user's companies
            data.companies.forEach(companyId => {
                const checkbox = document.querySelector(`.company-checkbox[value="${companyId}"]`);
                if (checkbox) {
                    checkbox.checked = true;
                }
            });
            
            // Load branches if companies selected
            if (data.companies.length > 0) {
                loadBranches();
                
                // Wait for branches to load then check them
                setTimeout(() => {
                    data.branches.forEach(branchId => {
                        const checkbox = document.querySelector(`input[name="branches[]"][value="${branchId}"]`);
                        if (checkbox) {
                            checkbox.checked = true;
                        }
                    });
                }, 500);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading user data');
        });
}
</script>

<?php include 'footer.php'; ?>