<?php
include 'config.php';

// Create bank_recon_reasons table if not exists
// (reasons used to mark a bank statement line as "Manual Reconcile")
$createTable = "CREATE TABLE IF NOT EXISTS bank_recon_reasons (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    reason VARCHAR(255) NOT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (active)
)";
mysqli_query($conn, $createTable);

// Insert default reasons if table is empty
$countResult = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM bank_recon_reasons");
$countRow = mysqli_fetch_assoc($countResult);
if ($countRow['cnt'] == 0) {
    $defaultReasons = [
        "Bank charges",
        "Bank interest",
        "Inter-account fund transfer",
        "Direct deposit by customer",
        "Returned cheque",
        "Other"
    ];
    foreach ($defaultReasons as $reason) {
        $r = mysqli_real_escape_string($conn, $reason);
        mysqli_query($conn, "INSERT INTO bank_recon_reasons (reason) VALUES ('$r')");
    }
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $used = 0;
    $u = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM bank_manual_recon WHERE reason_id = $id AND undone_at IS NULL");
    if ($u) $used = (int)mysqli_fetch_assoc($u)['c'];
    $sql = "DELETE FROM bank_recon_reasons WHERE id = $id";
    if ($used > 0) {
        $error_message = "This reason is used on $used manually reconciled bank line(s). Edit it or set it to Inactive instead of deleting.";
    } elseif (mysqli_query($conn, $sql)) {
        $success_message = "Reason deleted successfully!";
    } else {
        $error_message = "Error deleting reason: " . mysqli_error($conn);
    }
}

// Handle form submission (Create or Update)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $reason = mysqli_real_escape_string($conn, trim($_POST['reason']));
    $active = isset($_POST['active']) ? 1 : 0;

    if (empty($reason)) {
        $error_message = "Reason cannot be empty.";
    } elseif (isset($_POST['reason_id']) && !empty($_POST['reason_id'])) {
        // Update
        $id = intval($_POST['reason_id']);
        $sql = "UPDATE bank_recon_reasons SET reason = '$reason', active = '$active' WHERE id = $id";
        if (mysqli_query($conn, $sql)) {
            $success_message = "Reason updated successfully!";
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    } else {
        // Check duplicate
        $check = mysqli_query($conn, "SELECT id FROM bank_recon_reasons WHERE reason = '$reason'");
        if (mysqli_num_rows($check) > 0) {
            $error_message = "This reason already exists. Please use a different reason.";
        } else {
            $sql = "INSERT INTO bank_recon_reasons (reason, active) VALUES ('$reason', '$active')";
            if (mysqli_query($conn, $sql)) {
                $success_message = "Reason added successfully!";
            } else {
                $error_message = "Error: " . mysqli_error($conn);
            }
        }
    }
}

// Get all reasons (with how many bank lines use each one)
$has_log = ($t = @mysqli_query($conn, "SHOW TABLES LIKE 'bank_manual_recon'")) && mysqli_num_rows($t) > 0;
$reasons_result = mysqli_query($conn, $has_log
    ? "SELECT r.*, (SELECT COUNT(*) FROM bank_manual_recon m WHERE m.reason_id = r.id AND m.undone_at IS NULL) AS used_count FROM bank_recon_reasons r ORDER BY r.created_at DESC"
    : "SELECT r.*, 0 AS used_count FROM bank_recon_reasons r ORDER BY r.created_at DESC");

include 'header.php';
?>

<!-- Bank Reconcile Reasons Page -->
<div class="page-header">
    <h2 class="page-title">Bank Reconcile Reasons</h2>
    <p class="page-subtitle">Reasons used to manually reconcile bank statement lines (Bank Statements and Date-wise Transactions pages)</p>
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

<!-- Add Reason Button -->
<div style="margin-bottom: 20px;">
    <button onclick="openModal()" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i>
        Add New Reason
    </button>
</div>

<!-- Reasons List -->
<div class="content-card">
    <h3 class="card-title">All Reasons</h3>

    <?php if (mysqli_num_rows($reasons_result) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Reason</th>
                    <th>Used on</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = mysqli_fetch_assoc($reasons_result)): ?>
                <tr>
                    <td><?php echo $row['id']; ?></td>
                    <td><?php echo htmlspecialchars($row['reason']); ?></td>
                    <td><?php echo (int)$row['used_count']; ?> line<?php echo (int)$row['used_count'] === 1 ? '' : 's'; ?></td>
                    <td>
                        <?php if ($row['active']): ?>
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
                               onclick="editReason(<?php echo htmlspecialchars(json_encode(['id' => $row['id'], 'reason' => $row['reason'], 'active' => $row['active']])); ?>)"
                               class="btn-action btn-edit"
                               title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <a href="?delete=<?php echo $row['id']; ?>"
                               class="btn-action btn-delete"
                               title="Delete"
                               onclick="return confirm('Are you sure you want to delete this reason?')">
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
    <p style="color: #666; font-size: 13px; text-align: center; padding: 20px;">No reasons found. Add your first reason using the button above.</p>
    <?php endif; ?>
</div>

<!-- Modal Dialog -->
<div id="reasonModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add New Reason</h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" id="reasonForm">
            <input type="hidden" name="reason_id" id="reason_id">

            <div class="modal-body">
                <div class="form-group">
                    <label for="reason" class="form-label">
                        Reason <span class="required">*</span>
                    </label>
                    <input
                        type="text"
                        id="reason"
                        name="reason"
                        class="form-input"
                        placeholder="Enter bank reconcile reason (e.g. Bank charges)"
                        required
                    >
                    <small class="form-hint">Provide a clear and concise reason</small>
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
                    <span id="submitBtnText">Save Reason</span>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
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
.alert i { font-size: 18px; }
.alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

.modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0; top: 0;
    width: 100%; height: 100%;
    background-color: rgba(0,0,0,0.5);
    animation: fadeIn 0.3s;
}
.modal.active { display: flex; align-items: center; justify-content: center; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideDown { from { opacity: 0; transform: translateY(-50px); } to { opacity: 1; transform: translateY(0); } }

.modal-content {
    background: #ffffff;
    border-radius: 12px;
    width: 90%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    animation: slideDown 0.3s;
}
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 24px; border-bottom: 1px solid #e5e5e5; }
.modal-title { font-size: 18px; font-weight: 600; margin: 0; }
.modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #666; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 6px; transition: all 0.2s; }
.modal-close:hover { background: #f5f5f5; color: #000; }
.modal-body { padding: 24px; }
.modal-footer { display: flex; justify-content: flex-end; gap: 12px; padding: 20px 24px; border-top: 1px solid #e5e5e5; }

.form-group { margin-bottom: 20px; }
.form-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #333; }
.required { color: #ef4444; }
.optional { color: #999; font-weight: 400; font-size: 12px; }
.form-input { width: 100%; padding: 12px 16px; border: 1px solid #e5e5e5; border-radius: 8px; font-size: 14px; font-family: 'Inter', sans-serif; transition: all 0.3s; background: #fff; box-sizing: border-box; }
.form-input:focus { outline: none; border-color: #000; box-shadow: 0 0 0 3px rgba(0,0,0,0.05); }
.form-input::placeholder { color: #999; }
.form-hint { display: block; font-size: 11px; color: #666; margin-top: 6px; }

.checkbox-wrapper { display: flex; align-items: center; }
.checkbox-label { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 14px; color: #333; }
.form-checkbox { width: 18px; height: 18px; cursor: pointer; accent-color: #000; }
.checkbox-text { font-weight: 500; }

.btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-decoration: none; font-family: 'Inter', sans-serif; }
.btn i { font-size: 16px; }
.btn-primary { background: #000; color: #fff; }
.btn-primary:hover { background: #333; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.btn-secondary { background: #f5f5f5; color: #333; border: 1px solid #e5e5e5; }
.btn-secondary:hover { background: #e5e5e5; }

.table-responsive { overflow-x: auto; margin-top: 20px; }
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table thead { background: #fafafa; border-bottom: 2px solid #e5e5e5; }
.data-table th { padding: 12px 16px; text-align: left; font-weight: 600; color: #333; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
.data-table tbody tr { border-bottom: 1px solid #f0f0f0; transition: background 0.2s; }
.data-table tbody tr:hover { background: #fafafa; }
.data-table td { padding: 14px 16px; color: #333; }

.badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
.badge i { font-size: 10px; }
.badge-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.badge-inactive { background: #fafafa; color: #666; border: 1px solid #e5e5e5; }

.action-buttons { display: flex; gap: 8px; }
.btn-action { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 6px; border: 1px solid #e5e5e5; background: #fff; color: #666; cursor: pointer; transition: all 0.2s; text-decoration: none; }
.btn-action:hover { transform: translateY(-2px); box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.btn-edit:hover { background: #000; color: #fff; border-color: #000; }
.btn-delete:hover { background: #ef4444; color: #fff; border-color: #ef4444; }

@media (max-width: 768px) {
    .modal-content { width: 95%; margin: 20px; }
    .modal-header, .modal-body, .modal-footer { padding: 16px; }
    .modal-footer { flex-direction: column; }
    .modal-footer .btn { width: 100%; justify-content: center; }
    .data-table { font-size: 12px; }
    .data-table th, .data-table td { padding: 10px; }
}
</style>

<script>
function openModal() {
    document.getElementById('reasonModal').classList.add('active');
    document.getElementById('reasonForm').reset();
    document.getElementById('reason_id').value = '';
    document.getElementById('modalTitle').textContent = 'Add New Reason';
    document.getElementById('submitBtnText').textContent = 'Save Reason';
    document.getElementById('active').checked = true;
}

function closeModal() {
    document.getElementById('reasonModal').classList.remove('active');
}

function editReason(row) {
    document.getElementById('reasonModal').classList.add('active');
    document.getElementById('reason_id').value = row.id;
    document.getElementById('reason').value = row.reason;
    document.getElementById('active').checked = row.active == 1;
    document.getElementById('modalTitle').textContent = 'Edit Reason';
    document.getElementById('submitBtnText').textContent = 'Update Reason';
}
</script>

<?php include 'footer.php'; ?>
