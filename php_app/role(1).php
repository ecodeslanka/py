<?php
include 'config.php';

// Create roles table if not exists
$createRolesTable = "CREATE TABLE IF NOT EXISTS roles (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
mysqli_query($conn, $createRolesTable);

// Create permissions table if not exists
$createPermissionsTable = "CREATE TABLE IF NOT EXISTS permissions (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    role_id INT(11) NOT NULL,
    module_name VARCHAR(50) NOT NULL,
    can_access TINYINT(1) DEFAULT 0,
    can_create TINYINT(1) DEFAULT 0,
    can_edit TINYINT(1) DEFAULT 0,
    can_delete TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    UNIQUE KEY unique_role_module (role_id, module_name)
)";
mysqli_query($conn, $createPermissionsTable);

// Available modules
$modules = [
    'companies' => 'Companies',
    'branches' => 'Branches',
    'dashboard' => 'Dashboard',
    'reports' => 'Reports',
    'settings' => 'Settings'
];

// Handle Delete Role
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $sql = "DELETE FROM roles WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        $success_message = "Role deleted successfully!";
    } else {
        $error_message = "Error deleting role: " . mysqli_error($conn);
    }
}

// Handle form submission (Create or Update)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $role_name = mysqli_real_escape_string($conn, $_POST['role_name']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $active = isset($_POST['active']) ? 1 : 0;
    
    if (isset($_POST['role_id']) && !empty($_POST['role_id'])) {
        // Update existing role
        $id = intval($_POST['role_id']);
        $sql = "UPDATE roles SET 
                role_name = '$role_name', 
                description = '$description',
                active = '$active' 
                WHERE id = $id";
        
        if (mysqli_query($conn, $sql)) {
            // Update permissions
            foreach ($modules as $module_key => $module_name) {
                $can_access = isset($_POST['permission'][$module_key]['access']) ? 1 : 0;
                $can_create = isset($_POST['permission'][$module_key]['create']) ? 1 : 0;
                $can_edit = isset($_POST['permission'][$module_key]['edit']) ? 1 : 0;
                $can_delete = isset($_POST['permission'][$module_key]['delete']) ? 1 : 0;
                
                $perm_sql = "INSERT INTO permissions (role_id, module_name, can_access, can_create, can_edit, can_delete) 
                            VALUES ('$id', '$module_key', '$can_access', '$can_create', '$can_edit', '$can_delete')
                            ON DUPLICATE KEY UPDATE 
                            can_access = '$can_access',
                            can_create = '$can_create',
                            can_edit = '$can_edit',
                            can_delete = '$can_delete'";
                mysqli_query($conn, $perm_sql);
            }
            
            $success_message = "Role updated successfully!";
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    } else {
        // Insert new role
        $sql = "INSERT INTO roles (role_name, description, active) VALUES ('$role_name', '$description', '$active')";
        
        if (mysqli_query($conn, $sql)) {
            $role_id = mysqli_insert_id($conn);
            
            // Insert permissions
            foreach ($modules as $module_key => $module_name) {
                $can_access = isset($_POST['permission'][$module_key]['access']) ? 1 : 0;
                $can_create = isset($_POST['permission'][$module_key]['create']) ? 1 : 0;
                $can_edit = isset($_POST['permission'][$module_key]['edit']) ? 1 : 0;
                $can_delete = isset($_POST['permission'][$module_key]['delete']) ? 1 : 0;
                
                $perm_sql = "INSERT INTO permissions (role_id, module_name, can_access, can_create, can_edit, can_delete) 
                            VALUES ('$role_id', '$module_key', '$can_access', '$can_create', '$can_edit', '$can_delete')";
                mysqli_query($conn, $perm_sql);
            }
            
            $success_message = "Role created successfully!";
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    }
}

// Get all roles
$roles_sql = "SELECT * FROM roles ORDER BY created_at DESC";
$roles_result = mysqli_query($conn, $roles_sql);

include 'header.php';
?>

<!-- Roles Page -->
<div class="page-header">
    <h2 class="page-title">User Roles & Permissions</h2>
    <p class="page-subtitle">Manage user roles and their permissions</p>
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

<!-- Add Role Button -->
<div style="margin-bottom: 20px;">
    <button onclick="openModal()" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i>
        Add New Role
    </button>
</div>

<!-- Roles List -->
<div class="content-card">
    <h3 class="card-title">All Roles</h3>
    
    <?php if (mysqli_num_rows($roles_result) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Role Name</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Created Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($role = mysqli_fetch_assoc($roles_result)): ?>
                <tr>
                    <td><?php echo $role['id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($role['role_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($role['description']) ?: '<span style="color: #999;">-</span>'; ?></td>
                    <td>
                        <?php if ($role['active']): ?>
                            <span class="badge badge-success">
                                <i class="fa-solid fa-circle-check"></i> Active
                            </span>
                        <?php else: ?>
                            <span class="badge badge-inactive">
                                <i class="fa-solid fa-circle-xmark"></i> Inactive
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('M d, Y', strtotime($role['created_at'])); ?></td>
                    <td>
                        <div class="action-buttons">
                            <a href="#" 
                               onclick="editRole(<?php echo $role['id']; ?>)" 
                               class="btn-action btn-edit" 
                               title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <a href="?delete=<?php echo $role['id']; ?>" 
                               class="btn-action btn-delete" 
                               title="Delete"
                               onclick="return confirm('Are you sure you want to delete this role?')">
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
    <p style="color: #666; font-size: 13px; text-align: center; padding: 20px;">No roles found. Create your first role using the button above.</p>
    <?php endif; ?>
</div>

<!-- Modal Dialog -->
<div id="roleModal" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add New Role</h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        
        <form method="POST" action="" id="roleForm">
            <input type="hidden" name="role_id" id="role_id">
            
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="role_name" class="form-label">
                            Role Name <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="role_name" 
                            name="role_name" 
                            class="form-input" 
                            placeholder="Enter role name (e.g., Administrator)"
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
                                    checked
                                >
                                <span class="checkbox-text">Active</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="description" class="form-label">
                        Description <span class="optional">(Optional)</span>
                    </label>
                    <textarea 
                        id="description" 
                        name="description" 
                        class="form-input form-textarea" 
                        placeholder="Enter role description"
                        rows="2"
                    ></textarea>
                </div>

                <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e5e5;">

                <h4 style="font-size: 15px; font-weight: 600; margin-bottom: 16px; color: #333;">
                    <i class="fa-solid fa-shield-halved"></i> Permissions
                </h4>

                <div class="permissions-grid">
                    <div class="permissions-header">
                        <div class="perm-module">Module</div>
                        <div class="perm-action">Access</div>
                        <div class="perm-action">Create</div>
                        <div class="perm-action">Edit</div>
                        <div class="perm-action">Delete</div>
                    </div>

                    <?php foreach ($modules as $module_key => $module_name): ?>
                    <div class="permissions-row">
                        <div class="perm-module">
                            <i class="fa-solid fa-folder"></i>
                            <strong><?php echo $module_name; ?></strong>
                        </div>
                        <div class="perm-action">
                            <label class="perm-checkbox">
                                <input 
                                    type="checkbox" 
                                    name="permission[<?php echo $module_key; ?>][access]" 
                                    class="form-checkbox"
                                    onchange="togglePermissions(this, '<?php echo $module_key; ?>')"
                                >
                            </label>
                        </div>
                        <div class="perm-action">
                            <label class="perm-checkbox">
                                <input 
                                    type="checkbox" 
                                    name="permission[<?php echo $module_key; ?>][create]" 
                                    class="form-checkbox perm-<?php echo $module_key; ?>"
                                    disabled
                                >
                            </label>
                        </div>
                        <div class="perm-action">
                            <label class="perm-checkbox">
                                <input 
                                    type="checkbox" 
                                    name="permission[<?php echo $module_key; ?>][edit]" 
                                    class="form-checkbox perm-<?php echo $module_key; ?>"
                                    disabled
                                >
                            </label>
                        </div>
                        <div class="perm-action">
                            <label class="perm-checkbox">
                                <input 
                                    type="checkbox" 
                                    name="permission[<?php echo $module_key; ?>][delete]" 
                                    class="form-checkbox perm-<?php echo $module_key; ?>"
                                    disabled
                                >
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">
                    <i class="fa-solid fa-xmark"></i>
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check"></i>
                    <span id="submitBtnText">Save Role</span>
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
    max-width: 600px;
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

/* Form Styles */
.form-row {
    display: grid;
    grid-template-columns: 1fr 200px;
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

/* Permissions Grid */
.permissions-grid {
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    overflow: hidden;
}

.permissions-header {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
    background: #fafafa;
    border-bottom: 2px solid #e5e5e5;
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #666666;
}

.permissions-header > div {
    padding: 12px 16px;
    text-align: center;
}

.permissions-header .perm-module {
    text-align: left;
}

.permissions-row {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.2s;
}

.permissions-row:hover {
    background: #fafafa;
}

.permissions-row:last-child {
    border-bottom: none;
}

.perm-module {
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
}

.perm-module i {
    color: #666666;
    font-size: 14px;
}

.perm-action {
    padding: 14px 16px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.perm-checkbox {
    cursor: pointer;
}

.perm-checkbox input:disabled {
    opacity: 0.3;
    cursor: not-allowed;
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
    
    .permissions-header,
    .permissions-row {
        grid-template-columns: 1.5fr 0.7fr 0.7fr 0.7fr 0.7fr;
        font-size: 11px;
    }
    
    .perm-module {
        font-size: 12px;
    }
}
</style>

<script>
function openModal() {
    document.getElementById('roleModal').classList.add('active');
    document.getElementById('roleForm').reset();
    document.getElementById('role_id').value = '';
    document.getElementById('modalTitle').textContent = 'Add New Role';
    document.getElementById('submitBtnText').textContent = 'Save Role';
    
    // Reset all permission checkboxes
    document.querySelectorAll('[name^="permission"]').forEach(checkbox => {
        checkbox.checked = false;
        if (!checkbox.name.includes('[access]')) {
            checkbox.disabled = true;
        }
    });
}

function closeModal() {
    document.getElementById('roleModal').classList.remove('active');
}

function togglePermissions(accessCheckbox, moduleKey) {
    const checkboxes = document.querySelectorAll('.perm-' + moduleKey);
    checkboxes.forEach(checkbox => {
        checkbox.disabled = !accessCheckbox.checked;
        if (!accessCheckbox.checked) {
            checkbox.checked = false;
        }
    });
}

function editRole(roleId) {
    // Fetch role data via AJAX
    fetch('get_role.php?id=' + roleId)
        .then(response => response.json())
        .then(data => {
            document.getElementById('roleModal').classList.add('active');
            document.getElementById('role_id').value = data.role.id;
            document.getElementById('role_name').value = data.role.role_name;
            document.getElementById('description').value = data.role.description || '';
            document.getElementById('active').checked = data.role.active == 1;
            document.getElementById('modalTitle').textContent = 'Edit Role';
            document.getElementById('submitBtnText').textContent = 'Update Role';
            
            // Set permissions
            data.permissions.forEach(perm => {
                const moduleKey = perm.module_name;
                
                // Access checkbox
                const accessCheckbox = document.querySelector(`[name="permission[${moduleKey}][access]"]`);
                if (accessCheckbox) {
                    accessCheckbox.checked = perm.can_access == 1;
                    togglePermissions(accessCheckbox, moduleKey);
                }
                
                // Other permissions
                if (perm.can_create == 1) {
                    const createCheckbox = document.querySelector(`[name="permission[${moduleKey}][create]"]`);
                    if (createCheckbox) createCheckbox.checked = true;
                }
                if (perm.can_edit == 1) {
                    const editCheckbox = document.querySelector(`[name="permission[${moduleKey}][edit]"]`);
                    if (editCheckbox) editCheckbox.checked = true;
                }
                if (perm.can_delete == 1) {
                    const deleteCheckbox = document.querySelector(`[name="permission[${moduleKey}][delete]"]`);
                    if (deleteCheckbox) deleteCheckbox.checked = true;
                }
            });
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading role data');
        });
}
</script>

<?php include 'footer.php'; ?>