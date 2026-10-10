<?php
include 'config.php';
mysqli_report(MYSQLI_REPORT_OFF);

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_employee_damage (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT(11) NULL,
    employee_id INT(11) NOT NULL,
    damage_date DATE NOT NULL,
    damage_description TEXT NULL,
    damage_value DECIMAL(10,2) NOT NULL DEFAULT 0,
    insurance_claim_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    driver_claim_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    company_claim_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vehicle (vehicle_id),
    INDEX idx_employee (employee_id),
    INDEX idx_date (damage_date)
)");

if (isset($_GET['ajax']) && $_GET['ajax'] === 'get' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $res = mysqli_query($conn, "SELECT * FROM vehicle_employee_damage WHERE id = $id");
    echo json_encode(mysqli_fetch_assoc($res) ?: []);
    exit;
}

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    if (mysqli_query($conn, "DELETE FROM vehicle_employee_damage WHERE id = $id")) {
        $success_message = "Damage record deleted successfully!";
    } else {
        $error_message = "Error deleting record: " . mysqli_error($conn);
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $vehicle_id  = !empty($_POST['vehicle_id']) ? intval($_POST['vehicle_id']) : null;
    $employee_id = intval($_POST['employee_id']);
    $damage_date = mysqli_real_escape_string($conn, $_POST['damage_date']);
    $damage_description = mysqli_real_escape_string($conn, $_POST['damage_description']);
    $damage_value = floatval($_POST['damage_value']);
    $insurance_claim = floatval($_POST['insurance_claim_amount']);
    $driver_claim    = floatval($_POST['driver_claim_amount']);
    $company_claim   = floatval($_POST['company_claim_amount']);

    // Reconciliation validation (server-side safety net; JS blocks submit client-side)
    $sum = round($insurance_claim + $driver_claim + $company_claim, 2);
    if (round($sum, 2) != round($damage_value, 2)) {
        $error_message = "Claim amounts (Rs. " . number_format($sum, 2) . ") do not equal damage value (Rs. " . number_format($damage_value, 2) . "). Please correct and resubmit.";
    } else {
        if (isset($_POST['damage_id']) && !empty($_POST['damage_id'])) {
            $id = intval($_POST['damage_id']);
            $sql = "UPDATE vehicle_employee_damage SET
                    vehicle_id = " . ($vehicle_id ? $vehicle_id : "NULL") . ",
                    employee_id = $employee_id,
                    damage_date = '$damage_date',
                    damage_description = '$damage_description',
                    damage_value = $damage_value,
                    insurance_claim_amount = $insurance_claim,
                    driver_claim_amount = $driver_claim,
                    company_claim_amount = $company_claim
                    WHERE id = $id";
            if (mysqli_query($conn, $sql)) { $success_message = "Damage record updated successfully!"; }
            else { $error_message = "Error: " . mysqli_error($conn); }
        } else {
            $sql = "INSERT INTO vehicle_employee_damage
                    (vehicle_id, employee_id, damage_date, damage_description, damage_value, insurance_claim_amount, driver_claim_amount, company_claim_amount)
                    VALUES (" . ($vehicle_id ? $vehicle_id : "NULL") . ", $employee_id, '$damage_date', '$damage_description', $damage_value, $insurance_claim, $driver_claim, $company_claim)";
            if (mysqli_query($conn, $sql)) { $success_message = "Damage record added successfully!"; }
            else { $error_message = "Error: " . mysqli_error($conn); }
        }
    }
}

// ---------- Filters ----------
$f_employee = isset($_GET['f_employee']) ? intval($_GET['f_employee']) : 0;
$f_from = isset($_GET['f_from']) ? mysqli_real_escape_string($conn, $_GET['f_from']) : '';
$f_to   = isset($_GET['f_to']) ? mysqli_real_escape_string($conn, $_GET['f_to']) : '';

$where = [];
if ($f_employee) $where[] = "d.employee_id = $f_employee";
if ($f_from) $where[] = "d.damage_date >= '$f_from'";
if ($f_to)   $where[] = "d.damage_date <= '$f_to'";
$where_sql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

$list_sql = "SELECT d.*, v.vehicle_number, e.employee_id as emp_code, e.employee_full_name
             FROM vehicle_employee_damage d
             LEFT JOIN vehicles v ON d.vehicle_id = v.id
             LEFT JOIN employees e ON d.employee_id = e.id
             $where_sql
             ORDER BY d.damage_date DESC, d.id DESC";
$list_result = mysqli_query($conn, $list_sql);

$vehicles_result = mysqli_query($conn, "SELECT id, vehicle_number FROM vehicles ORDER BY vehicle_number");
$vehicles_list = [];
while ($v = mysqli_fetch_assoc($vehicles_result)) { $vehicles_list[] = $v; }

$employees_result = mysqli_query($conn, "SELECT id, employee_id, employee_full_name FROM employees ORDER BY employee_full_name");
$employees_list = [];
while ($e = mysqli_fetch_assoc($employees_result)) { $employees_list[] = $e; }

include 'header.php';
?>

<div class="page-header">
    <h2 class="page-title">Employee Damage Claims</h2>
    <p class="page-subtitle">Track vehicle damage caused by drivers/employees and how each claim is settled</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success_message; ?></div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?></div>
<?php endif; ?>

<div style="margin-bottom: 20px;">
    <button onclick="openModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Damage Record</button>
</div>

<form method="GET" class="filter-bar">
    <div class="filter-group">
        <label>Driver</label>
        <select name="f_employee" class="form-input select2-employee">
            <option value="">All Drivers</option>
            <?php foreach ($employees_list as $e): ?>
                <option value="<?php echo $e['id']; ?>" <?php echo ($f_employee == $e['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($e['employee_id'] . ' - ' . $e['employee_full_name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>From</label>
        <input type="date" name="f_from" class="form-input" value="<?php echo htmlspecialchars($f_from); ?>">
    </div>
    <div class="filter-group">
        <label>To</label>
        <input type="date" name="f_to" class="form-input" value="<?php echo htmlspecialchars($f_to); ?>">
    </div>
    <div class="filter-group">
        <button type="submit" class="btn btn-secondary"><i class="fa-solid fa-filter"></i> Filter</button>
    </div>
    <?php if ($f_employee || $f_from || $f_to): ?>
    <div class="filter-group"><a href="vehicle_employee_damage.php" class="btn btn-secondary"><i class="fa-solid fa-xmark"></i> Clear</a></div>
    <?php endif; ?>
</form>

<div class="content-card">
    <h3 class="card-title">Damage Records</h3>
    <?php if (mysqli_num_rows($list_result) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Lorry</th>
                    <th>Driver</th>
                    <th>Description</th>
                    <th>Damage Value</th>
                    <th>Insurance</th>
                    <th>Driver</th>
                    <th>Company</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = mysqli_fetch_assoc($list_result)): ?>
                <tr>
                    <td><?php echo date('d-M-Y', strtotime($row['damage_date'])); ?></td>
                    <td><?php echo htmlspecialchars($row['vehicle_number']) ?: '<span style="color:#999;">-</span>'; ?></td>
                    <td>
                        <span class="badge badge-neutral"><?php echo htmlspecialchars($row['emp_code'] ?? '-'); ?></span>
                        <?php echo htmlspecialchars($row['employee_full_name'] ?? '-'); ?>
                    </td>
                    <td><?php echo htmlspecialchars(mb_strimwidth($row['damage_description'] ?? '', 0, 60, '...')); ?></td>
                    <td><strong><?php echo number_format($row['damage_value'], 2); ?></strong></td>
                    <td><?php echo number_format($row['insurance_claim_amount'], 2); ?></td>
                    <td><?php echo number_format($row['driver_claim_amount'], 2); ?></td>
                    <td><?php echo number_format($row['company_claim_amount'], 2); ?></td>
                    <td>
                        <div class="action-buttons">
                            <a href="#" onclick="editDamage(<?php echo $row['id']; ?>); return false;" class="btn-action btn-edit" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <a href="?delete=<?php echo $row['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Delete this damage record?')"><i class="fa-solid fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="color: #666; font-size: 13px; text-align: center; padding: 20px;">No damage records found.</p>
    <?php endif; ?>
</div>

<!-- Modal -->
<div id="damageModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Damage Record</h3>
            <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="" id="damageForm" onsubmit="return validateBalance();">
            <input type="hidden" name="damage_id" id="damage_id">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Lorry</label>
                        <select id="vehicle_id" name="vehicle_id" class="form-input select2-vehicle">
                            <option value="">Select Lorry (optional)</option>
                            <?php foreach ($vehicles_list as $v): ?>
                                <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['vehicle_number']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Driver / Employee <span class="required">*</span></label>
                        <select id="employee_id" name="employee_id" class="form-input select2-employee" required>
                            <option value="">Select Driver</option>
                            <?php foreach ($employees_list as $e): ?>
                                <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['employee_id'] . ' - ' . $e['employee_full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Damage Date <span class="required">*</span></label>
                    <input type="date" id="damage_date" name="damage_date" class="form-input" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Damage Description</label>
                    <textarea id="damage_description" name="damage_description" class="form-input" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Damage Value <span class="required">*</span></label>
                    <input type="number" step="0.01" id="damage_value" name="damage_value" class="form-input calc-balance" required value="0">
                </div>

                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-scale-balanced"></i> Claim Split</h4>
                    <div class="form-row-3">
                        <div class="form-group">
                            <label class="form-label">Insurance Claim</label>
                            <input type="number" step="0.01" id="insurance_claim_amount" name="insurance_claim_amount" class="form-input calc-balance" value="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Driver Claim</label>
                            <input type="number" step="0.01" id="driver_claim_amount" name="driver_claim_amount" class="form-input calc-balance" value="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Company Claim</label>
                            <input type="number" step="0.01" id="company_claim_amount" name="company_claim_amount" class="form-input calc-balance" value="0">
                        </div>
                    </div>
                </div>

                <div class="balance-bar" id="balanceBar">
                    <span>Remaining Balance</span>
                    <span id="balanceDisplay">0.00</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-primary" id="submitBtn"><i class="fa-solid fa-check"></i> <span id="submitBtnText">Save Record</span></button>
            </div>
        </form>
    </div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<style>
/* Alert Styles */
.alert { padding: 16px 20px; border-radius: 8px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; font-size: 13px; font-weight: 500; }
.alert i { font-size: 18px; }
.alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

/* Modal Styles */
.modal { display: none; position: fixed; z-index: 10001; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); animation: fadeIn 0.3s; }
.modal.active { display: flex; align-items: flex-start; justify-content: center; overflow-y: auto; padding: 80px 20px 60px; box-sizing: border-box; }
.modal-content { background: #ffffff; border-radius: 12px; width: 90%; max-width: 800px; max-height: none; overflow-y: visible; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); animation: slideDown 0.3s; margin-bottom: 20px; }
.modal-large { max-width: 900px; }
.modal-xl { max-width: 1100px; }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 24px; border-bottom: 1px solid #e5e5e5; }
.modal-title { font-size: 18px; font-weight: 600; margin: 0; }
.modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #666666; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 6px; transition: all 0.2s; }
.modal-close:hover { background: #f5f5f5; color: #000000; }
.modal-body { padding: 24px; }
.modal-footer { display: flex; justify-content: flex-end; gap: 12px; padding: 20px 24px; border-top: 1px solid #e5e5e5; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

/* Form Section */
.form-section { margin-bottom: 24px; }
.section-title { font-size: 14px; font-weight: 600; margin-bottom: 16px; color: #333; display: flex; align-items: center; gap: 8px; }

/* Form Styles */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
.form-group { margin-bottom: 20px; }
.form-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #333333; }
.required { color: #ef4444; }
.form-input { width: 100%; padding: 12px 16px; border: 1px solid #e5e5e5; border-radius: 8px; font-size: 14px; font-family: 'Inter', sans-serif; transition: all 0.3s; background: #ffffff; box-sizing: border-box; }
.form-input:focus { outline: none; border-color: #000000; box-shadow: 0 0 0 3px rgba(0,0,0,0.05); }
.file-input { padding: 10px 12px; }
.form-hint { display: block; font-size: 11px; color: #666666; margin-top: 6px; }
select.form-input { cursor: pointer; }
textarea.form-input { resize: vertical; min-height: 70px; font-family: 'Inter', sans-serif; }

/* Checkbox Styles */
.checkbox-wrapper { display: flex; align-items: center; flex-wrap: wrap; gap: 16px; }
.checkbox-label { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 14px; color: #333333; }
.form-checkbox { width: 18px; height: 18px; cursor: pointer; accent-color: #000000; }
.checkbox-text { font-weight: 500; }
.checkbox-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
@media (max-width: 768px) { .checkbox-grid { grid-template-columns: 1fr 1fr; } }

/* Button Styles */
.btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-decoration: none; font-family: 'Inter', sans-serif; }
.btn i { font-size: 16px; }
.btn-primary { background: #000000; color: #ffffff; }
.btn-primary:hover { background: #333333; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.btn-secondary { background: #f5f5f5; color: #333333; border: 1px solid #e5e5e5; }
.btn-secondary:hover { background: #e5e5e5; }
.btn-sm { padding: 8px 14px; font-size: 12px; }
.btn-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.btn-danger:hover { background: #fecaca; }

/* Table Styles */
.table-responsive { overflow-x: auto; margin-top: 20px; }
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table thead { background: #fafafa; border-bottom: 2px solid #e5e5e5; }
.data-table th { padding: 12px 16px; text-align: left; font-weight: 600; color: #333333; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
.data-table tbody tr { border-bottom: 1px solid #f0f0f0; transition: background 0.2s; }
.data-table tbody tr:hover { background: #fafafa; }
.data-table td { padding: 14px 16px; color: #333333; }
.data-table tfoot td { padding: 12px 16px; font-weight: 700; background: #fafafa; border-top: 2px solid #e5e5e5; }

/* Badge Styles */
.badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.badge i { font-size: 10px; }
.badge-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.badge-inactive { background: #fafafa; color: #666666; border: 1px solid #e5e5e5; }
.badge-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.badge-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.badge-info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
.badge-neutral { background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }

/* Action Buttons */
.action-buttons { display: flex; gap: 8px; }
.btn-action { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 6px; border: 1px solid #e5e5e5; background: #ffffff; color: #666666; cursor: pointer; transition: all 0.2s; text-decoration: none; }
.btn-action:hover { transform: translateY(-2px); box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.btn-view:hover { background: #3b82f6; color: #ffffff; border-color: #3b82f6; }
.btn-edit:hover { background: #000000; color: #ffffff; border-color: #000000; }
.btn-delete:hover { background: #ef4444; color: #ffffff; border-color: #ef4444; }

/* Filter bar */
.filter-bar { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 20px; background: #fafafa; padding: 16px; border-radius: 8px; border: 1px solid #f0f0f0; }
.filter-group { display: flex; flex-direction: column; gap: 6px; min-width: 160px; }
.filter-group label { font-size: 11px; font-weight: 600; text-transform: uppercase; color: #666; letter-spacing: 0.5px; }

/* Balance bar */
.balance-bar { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-radius: 8px; font-size: 13px; font-weight: 600; margin: 16px 0; border: 1px solid #e5e5e5; background: #fafafa; }
.balance-bar.ok { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
.balance-bar.bad { background: #fef2f2; color: #991b1b; border-color: #fecaca; }

/* Repeatable rows */
.repeat-row { display: grid; grid-template-columns: 2fr 2fr 1fr 1fr auto; gap: 10px; align-items: end; margin-bottom: 10px; padding: 12px; background: #fafafa; border-radius: 8px; border: 1px solid #f0f0f0; }
.repeat-remove { width: 34px; height: 34px; border-radius: 6px; border: 1px solid #fecaca; background: #fef2f2; color: #991b1b; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.repeat-remove:hover { background: #fecaca; }

/* Responsive */
@media (max-width: 768px) {
    .modal-content { width: 95%; }
    .form-row, .form-row-3, .checkbox-grid, .repeat-row { grid-template-columns: 1fr; }
    .modal-footer { flex-direction: column; }
    .modal-footer .btn { width: 100%; justify-content: center; }
}
</style>

<script>
function recalcBalance() {
    const damageValue = parseFloat(document.getElementById('damage_value').value) || 0;
    const ins = parseFloat(document.getElementById('insurance_claim_amount').value) || 0;
    const drv = parseFloat(document.getElementById('driver_claim_amount').value) || 0;
    const comp = parseFloat(document.getElementById('company_claim_amount').value) || 0;
    const remaining = Math.round((damageValue - ins - drv - comp) * 100) / 100;

    const bar = document.getElementById('balanceBar');
    const display = document.getElementById('balanceDisplay');
    display.textContent = remaining.toFixed(2);

    if (Math.abs(remaining) < 0.01) {
        bar.className = 'balance-bar ok';
        document.getElementById('submitBtn').disabled = false;
    } else {
        bar.className = 'balance-bar bad';
        document.getElementById('submitBtn').disabled = true;
    }
    return remaining;
}

function validateBalance() {
    const remaining = recalcBalance();
    if (Math.abs(remaining) >= 0.01) {
        alert('Claim amounts must add up exactly to the damage value before saving. Remaining balance: Rs. ' + remaining.toFixed(2));
        return false;
    }
    return true;
}

function openModal() {
    document.getElementById('damageModal').classList.add('active');
    document.getElementById('damageForm').reset();
    document.getElementById('damage_id').value = '';
    document.getElementById('modalTitle').textContent = 'Add Damage Record';
    document.getElementById('submitBtnText').textContent = 'Save Record';
    if (window.jQuery) {
        $('.select2-vehicle').val('').trigger('change');
        $('.select2-employee').val('').trigger('change');
    }
    recalcBalance();
}

function closeModal() {
    document.getElementById('damageModal').classList.remove('active');
}

function editDamage(id) {
    fetch('vehicle_employee_damage.php?ajax=get&id=' + id)
        .then(response => response.json())
        .then(data => {
            document.getElementById('damageModal').classList.add('active');
            document.getElementById('damage_id').value = data.id;
            document.getElementById('damage_date').value = data.damage_date;
            document.getElementById('damage_description').value = data.damage_description || '';
            document.getElementById('damage_value').value = data.damage_value;
            document.getElementById('insurance_claim_amount').value = data.insurance_claim_amount;
            document.getElementById('driver_claim_amount').value = data.driver_claim_amount;
            document.getElementById('company_claim_amount').value = data.company_claim_amount;

            if (window.jQuery) {
                $('#vehicle_id').val(data.vehicle_id || '').trigger('change');
                $('#employee_id').val(data.employee_id).trigger('change');
            } else {
                document.getElementById('vehicle_id').value = data.vehicle_id || '';
                document.getElementById('employee_id').value = data.employee_id;
            }

            recalcBalance();
            document.getElementById('modalTitle').textContent = 'Edit Damage Record';
            document.getElementById('submitBtnText').textContent = 'Update Record';
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading damage record data');
        });
}

document.addEventListener('DOMContentLoaded', function() {
    if (window.jQuery && $.fn.select2) {
        $('.select2-vehicle').select2({ width: '100%', placeholder: 'Select Lorry' });
        $('.select2-employee').select2({ width: '100%', placeholder: 'Select Driver' });
    }
    document.querySelectorAll('.calc-balance').forEach(inp => inp.addEventListener('input', recalcBalance));
    recalcBalance();
});
</script>

<?php include 'footer.php'; ?>
