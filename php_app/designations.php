<?php
include 'config.php';

// Create designations table if not exists
$createDesignationsTable = "CREATE TABLE IF NOT EXISTS designations (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    designation_code VARCHAR(50) NULL,
    designation_name VARCHAR(255) NOT NULL,
    staff_category_id INT(11) NULL,
    rank_no INT(11) NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_category_id) REFERENCES staff_categories(id) ON DELETE SET NULL,
    INDEX idx_designation_code (designation_code),
    INDEX idx_staff_category (staff_category_id)
)";
mysqli_query($conn, $createDesignationsTable);

// Add rank_no column if it doesn't exist (for existing tables)
$alterTable = "ALTER TABLE designations ADD COLUMN IF NOT EXISTS rank_no INT(11) NULL AFTER staff_category_id";
mysqli_query($conn, $alterTable);

// Handle Delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $sql = "DELETE FROM designations WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        $success_message = "Designation deleted successfully!";
    } else {
        $error_message = "Error deleting designation: " . mysqli_error($conn);
    }
}

// Handle form submission (Create or Update)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $designation_code   = !empty($_POST['designation_code'])   ? mysqli_real_escape_string($conn, $_POST['designation_code'])   : NULL;
    $designation_name   = mysqli_real_escape_string($conn, $_POST['designation_name']);
    $staff_category_id  = !empty($_POST['staff_category_id'])  ? intval($_POST['staff_category_id'])  : NULL;
    $rank_no            = !empty($_POST['rank_no'])            ? intval($_POST['rank_no'])            : NULL;
    $active             = isset($_POST['active']) ? 1 : 0;

    $category_sql = $staff_category_id ? $staff_category_id : "NULL";
    $code_sql     = $designation_code  ? "'$designation_code'" : "NULL";
    $rank_sql     = $rank_no !== NULL  ? $rank_no             : "NULL";

    if (isset($_POST['designation_id']) && !empty($_POST['designation_id'])) {
        // Update existing designation
        $id = intval($_POST['designation_id']);

        if ($designation_code) {
            $check_sql    = "SELECT id FROM designations WHERE designation_code = '$designation_code' AND id != $id";
            $check_result = mysqli_query($conn, $check_sql);

            if (mysqli_num_rows($check_result) > 0) {
                $error_message = "Designation code already exists. Please use a different code.";
            } else {
                $sql = "UPDATE designations SET 
                        designation_code = $code_sql,
                        designation_name = '$designation_name',
                        staff_category_id = $category_sql,
                        rank_no = $rank_sql,
                        active = '$active' 
                        WHERE id = $id";
                if (mysqli_query($conn, $sql)) {
                    $success_message = "Designation updated successfully!";
                } else {
                    $error_message = "Error: " . mysqli_error($conn);
                }
            }
        } else {
            $sql = "UPDATE designations SET 
                    designation_code = NULL,
                    designation_name = '$designation_name',
                    staff_category_id = $category_sql,
                    rank_no = $rank_sql,
                    active = '$active' 
                    WHERE id = $id";
            if (mysqli_query($conn, $sql)) {
                $success_message = "Designation updated successfully!";
            } else {
                $error_message = "Error: " . mysqli_error($conn);
            }
        }
    } else {
        // Insert new designation
        if ($designation_code) {
            $check_sql    = "SELECT id FROM designations WHERE designation_code = '$designation_code'";
            $check_result = mysqli_query($conn, $check_sql);

            if (mysqli_num_rows($check_result) > 0) {
                $error_message = "Designation code already exists. Please use a different code.";
            } else {
                $sql = "INSERT INTO designations (designation_code, designation_name, staff_category_id, rank_no, active) 
                        VALUES ($code_sql, '$designation_name', $category_sql, $rank_sql, '$active')";
                if (mysqli_query($conn, $sql)) {
                    $success_message = "Designation created successfully!";
                } else {
                    $error_message = "Error: " . mysqli_error($conn);
                }
            }
        } else {
            $sql = "INSERT INTO designations (designation_code, designation_name, staff_category_id, rank_no, active) 
                    VALUES (NULL, '$designation_name', $category_sql, $rank_sql, '$active')";
            if (mysqli_query($conn, $sql)) {
                $success_message = "Designation created successfully!";
            } else {
                $error_message = "Error: " . mysqli_error($conn);
            }
        }
    }
}

// Get all designations with staff category
$designations_sql = "SELECT d.*, sc.category_name 
                     FROM designations d
                     LEFT JOIN staff_categories sc ON d.staff_category_id = sc.id
                     ORDER BY d.created_at DESC";
$designations_result = mysqli_query($conn, $designations_sql);

// Get staff categories for dropdown
$categories_sql    = "SELECT id, category_code, category_name FROM staff_categories WHERE active = 1 ORDER BY category_name";
$categories_result = mysqli_query($conn, $categories_sql);

include 'header.php';
?>

<!-- Designations Page -->
<div class="page-header">
    <h2 class="page-title">Designation Management</h2>
    <p class="page-subtitle">Manage common designations for all companies</p>
</div>

<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

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

<!-- Add Designation Button -->
<div style="margin-bottom: 20px;">
    <button onclick="openModal()" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i>
        Add New Designation
    </button>
</div>

<!-- Designations List -->
<div class="content-card">
    <h3 class="card-title">All Designations</h3>
    
    <?php if (mysqli_num_rows($designations_result) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Designation Code</th>
                    <th>Designation Name</th>
                    <th>Staff Category</th>
                    <th>Rank No</th>
                    <th>Status</th>
                    <th>Created Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($designation = mysqli_fetch_assoc($designations_result)): ?>
                <tr>
                    <td><?php echo $designation['id']; ?></td>
                    <td><?php echo $designation['designation_code'] ? '<strong>' . htmlspecialchars($designation['designation_code']) . '</strong>' : '<span style="color: #999;">-</span>'; ?></td>
                    <td><?php echo htmlspecialchars($designation['designation_name']); ?></td>
                    <td><?php echo $designation['category_name'] ? htmlspecialchars($designation['category_name']) : '<span style="color: #999;">-</span>'; ?></td>
                    <td>
                        <?php if ($designation['rank_no'] !== NULL && $designation['rank_no'] !== ''): ?>
                            <span class="rank-badge"><?php echo intval($designation['rank_no']); ?></span>
                        <?php else: ?>
                            <span style="color: #999;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($designation['active']): ?>
                            <span class="badge badge-success">
                                <i class="fa-solid fa-circle-check"></i> Active
                            </span>
                        <?php else: ?>
                            <span class="badge badge-inactive">
                                <i class="fa-solid fa-circle-xmark"></i> Inactive
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('M d, Y', strtotime($designation['created_at'])); ?></td>
                    <td>
                        <div class="action-buttons">
                            <a href="#" 
                               onclick="editDesignation(<?php echo htmlspecialchars(json_encode($designation)); ?>)" 
                               class="btn-action btn-edit" 
                               title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <a href="?delete=<?php echo $designation['id']; ?>" 
                               class="btn-action btn-delete" 
                               title="Delete"
                               onclick="return confirm('Are you sure you want to delete this designation?')">
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
    <p style="color: #666; font-size: 13px; text-align: center; padding: 20px;">No designations found. Create your first designation using the button above.</p>
    <?php endif; ?>
</div>

<!-- Modal Dialog -->
<div id="designationModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add New Designation</h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        
        <form method="POST" action="" id="designationForm">
            <input type="hidden" name="designation_id" id="designation_id">
            
            <div class="modal-body">
                <div class="form-group">
                    <label for="designation_name" class="form-label">
                        Designation Name <span class="required">*</span>
                    </label>
                    <input 
                        type="text" 
                        id="designation_name" 
                        name="designation_name" 
                        class="form-input" 
                        placeholder="Enter designation name (e.g., Manager, Assistant Manager)"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="designation_code" class="form-label">
                        Designation Code <span style="color: #6b7280;">(Optional)</span>
                    </label>
                    <input 
                        type="text" 
                        id="designation_code" 
                        name="designation_code" 
                        class="form-input" 
                        placeholder="Enter designation code (e.g., MGR, ASM)"
                        pattern="[A-Za-z0-9]*"
                        title="Only letters and numbers allowed"
                    >
                    <small class="form-hint">Unique code for the designation (leave blank if not needed)</small>
                </div>

                <div class="form-group">
                    <label for="staff_category_id" class="form-label">
                        Staff Category <span style="color: #6b7280;">(Optional)</span>
                    </label>
                    <select 
                        id="staff_category_id" 
                        name="staff_category_id" 
                        class="form-input select2"
                    >
                        <option value="">Select Staff Category</option>
                        <?php 
                        mysqli_data_seek($categories_result, 0);
                        while ($category = mysqli_fetch_assoc($categories_result)): 
                        ?>
                            <option value="<?php echo $category['id']; ?>">
                                <?php echo htmlspecialchars($category['category_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <small class="form-hint">Select the category this designation belongs to</small>
                </div>

                <div class="form-group">
                    <label for="rank_no" class="form-label">
                        Rank No <span style="color: #6b7280;">(Optional)</span>
                    </label>
                    <input 
                        type="number" 
                        id="rank_no" 
                        name="rank_no" 
                        class="form-input" 
                        placeholder="Enter rank number (e.g., 1, 2, 3)"
                        min="1"
                        step="1"
                    >
                    <small class="form-hint">Numeric rank to define seniority or ordering of this designation</small>
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
                    <span id="submitBtnText">Save Designation</span>
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

.form-input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    transition: all 0.3s;
    background: #ffffff;
    box-sizing: border-box;
}

.form-input:focus {
    outline: none;
    border-color: #000000;
    box-shadow: 0 0 0 3px rgba(0,0,0,0.05);
}

.form-input::placeholder {
    color: #999999;
}

/* Remove number input spinners for cleaner look */
input[type=number].form-input::-webkit-inner-spin-button,
input[type=number].form-input::-webkit-outer-spin-button {
    opacity: 1;
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

/* Rank Badge */
.rank-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    height: 28px;
    padding: 0 8px;
    background: #f0f4ff;
    color: #2563eb;
    border: 1px solid #bfdbfe;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
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
}

/* Select2 Custom Styling */
.select2-container--default .select2-selection--single {
    height: 46px !important;
    border: 1px solid #e5e5e5 !important;
    border-radius: 8px !important;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 44px !important;
    padding-left: 16px !important;
    font-size: 14px !important;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 44px !important;
}

.select2-container--default.select2-container--focus .select2-selection--single {
    border-color: #000000 !important;
}

.select2-dropdown {
    border: 1px solid #e5e5e5 !important;
    border-radius: 8px !important;
}

.select2-container--default .select2-results__option--highlighted[aria-selected] {
    background-color: #000000 !important;
}
</style>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2({
        width: '100%',
        dropdownParent: $('#designationModal')
    });
});

function openModal() {
    document.getElementById('designationModal').classList.add('active');
    document.getElementById('designationForm').reset();
    document.getElementById('designation_id').value = '';
    document.getElementById('modalTitle').textContent = 'Add New Designation';
    document.getElementById('submitBtnText').textContent = 'Save Designation';
    
    // Reset Select2
    $('#staff_category_id').val('').trigger('change');
}

function closeModal() {
    document.getElementById('designationModal').classList.remove('active');
}

function editDesignation(designation) {
    document.getElementById('designationModal').classList.add('active');
    document.getElementById('designation_id').value      = designation.id;
    document.getElementById('designation_code').value    = designation.designation_code || '';
    document.getElementById('designation_name').value    = designation.designation_name;
    document.getElementById('rank_no').value             = designation.rank_no || '';
    document.getElementById('active').checked            = designation.active == 1;
    document.getElementById('modalTitle').textContent    = 'Edit Designation';
    document.getElementById('submitBtnText').textContent = 'Update Designation';
    
    // Set Select2 value
    $('#staff_category_id').val(designation.staff_category_id || '').trigger('change');
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('designationModal');
    if (event.target == modal) {
        closeModal();
    }
}
</script>

<?php include 'footer.php'; ?>