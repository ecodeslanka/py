<?php
include 'config.php';

// Check if employee ID is provided
if (!isset($_GET['id'])) {
    header('Location: employees.php');
    exit;
}

$employee_id = intval($_GET['id']);

// Get employee details first
$employee_sql = "SELECT * FROM employees WHERE id = $employee_id";
$employee_result = mysqli_query($conn, $employee_sql);

if (!$employee_result || mysqli_num_rows($employee_result) == 0) {
    header('Location: employees.php?error=not_found');
    exit;
}

$employee = mysqli_fetch_assoc($employee_result);

// Handle deletion confirmation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_delete'])) {
    // Delete the employee record
    $delete_sql = "DELETE FROM employees WHERE id = $employee_id";
    
    if (mysqli_query($conn, $delete_sql)) {
        // Delete associated files if they exist
        $files_to_delete = [
            $employee['application_form'],
            $employee['id_copy'], 
            $employee['driver_licence_copy']
        ];
        
        foreach ($files_to_delete as $file_path) {
            if ($file_path && file_exists($file_path)) {
                unlink($file_path);
            }
        }
        
        header('Location: employees.php?deleted=1&name=' . urlencode($employee['employee_full_name']));
        exit;
    } else {
        $error_message = "Error deleting employee: " . mysqli_error($conn);
    }
}

include 'header.php';
?>

<!-- Delete Employee Page -->
<div class="page-header">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 class="page-title">Delete Employee</h2>
            <p class="page-subtitle">Confirm employee deletion</p>
        </div>
        <a href="employees.php" class="btn btn-secondary">
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

<!-- Employee Details -->
<div class="content-card">
    <h3>Employee to Delete</h3>
    
    <div class="employee-info">
        <p><strong>Employee ID:</strong> <?php echo htmlspecialchars($employee['employee_id']); ?></p>
        <p><strong>Name:</strong> <?php echo htmlspecialchars($employee['employee_full_name']); ?></p>
        <p><strong>Mobile:</strong> <?php echo htmlspecialchars($employee['telephone_mobile']); ?></p>
        <p><strong>Status:</strong> <?php echo htmlspecialchars($employee['status']); ?></p>
    </div>
</div>

<!-- Delete Confirmation -->
<div class="content-card delete-section">
    <h3 class="text-danger">
        <i class="fa-solid fa-warning"></i> 
        Confirm Deletion
    </h3>
    
    <div class="warning">
        <p><strong>Warning:</strong> This will permanently delete the employee record and cannot be undone.</p>
    </div>
    
    <form method="POST" action="">
        <div class="form-actions">
            <a href="employees.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" name="confirm_delete" class="btn btn-danger" 
                    onclick="return confirm('Are you sure you want to delete this employee?')">
                <i class="fa-solid fa-trash"></i>
                Delete Employee
            </button>
        </div>
    </form>
</div>

<style>
.content-card {
    background: #ffffff;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.content-card h3 {
    margin-top: 0;
    margin-bottom: 16px;
    font-size: 18px;
    font-weight: 600;
}

.employee-info p {
    margin-bottom: 8px;
    font-size: 14px;
}

.delete-section {
    border-left: 4px solid #dc2626;
}

.text-danger {
    color: #dc2626;
}

.warning {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 6px;
    padding: 16px;
    margin-bottom: 20px;
}

.warning p {
    margin: 0;
    color: #991b1b;
}

.form-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    border: 1px solid transparent;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.3s;
}

.btn-secondary {
    background: #f9fafb;
    color: #374151;
    border-color: #d1d5db;
}

.btn-secondary:hover {
    background: #f3f4f6;
}

.btn-danger {
    background: #dc2626;
    color: white;
}

.btn-danger:hover {
    background: #b91c1c;
}

.alert {
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.alert-error {
    background: #fee2e2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.page-header {
    margin-bottom: 24px;
}

.page-title {
    font-size: 28px;
    font-weight: 700;
    color: #111827;
    margin: 0;
}

.page-subtitle {
    color: #6b7280;
    margin: 4px 0 0 0;
    font-size: 16px;
}
</style>

<?php include 'footer.php'; ?>