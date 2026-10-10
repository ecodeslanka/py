<?php
include 'config.php';
mysqli_report(MYSQLI_REPORT_OFF);

// ---------- Tables ----------
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_types (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_type_service_items (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    vehicle_type_id INT(11) NOT NULL,
    item_key VARCHAR(50) NOT NULL,
    interval_km INT NULL,
    remarks VARCHAR(500) NULL,
    INDEX idx_vehicle_type (vehicle_type_id)
)");

// Service item types table (kept in sync with vehicle_service.php / vehicle_service_item_types.php)
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_service_item_types (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    item_key VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(150) NOT NULL,
    sort_order INT(11) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// One-time seed (same defaults used elsewhere), only runs if the table is empty
$vs_seed_check = mysqli_query($conn, "SELECT COUNT(*) AS c FROM vehicle_service_item_types");
$vs_seed_row = $vs_seed_check ? mysqli_fetch_assoc($vs_seed_check) : ['c' => 0];
if (intval($vs_seed_row['c']) === 0) {
    $vs_default_items = [
        'engine_oil' => 'Engine Oil',
        'gearbox_oil' => 'Gearbox Oil',
        'power_steering_oil' => 'Power Steering Oil',
        'differential_oil' => 'Differential Oil',
        'brake_fluid' => 'Brake Fluid',
        'clutch_fluid' => 'Clutch Fluid',
        'coolant' => 'Coolant',
        'battery_water' => 'Battery Water',
        'windscreen_washer' => 'Windscreen Washer',
        'oil_filter' => 'Oil Filter',
        'fuel_filter' => 'Fuel Filter',
        'air_filter' => 'Air Filter',
        'grease_nipples' => 'Grease Nipples',
    ];
    $vs_order = 0;
    foreach ($vs_default_items as $vs_key => $vs_label) {
        $vs_order += 10;
        $vs_key_esc = mysqli_real_escape_string($conn, $vs_key);
        $vs_label_esc = mysqli_real_escape_string($conn, $vs_label);
        mysqli_query($conn, "INSERT IGNORE INTO vehicle_service_item_types (item_key, label, sort_order) VALUES ('$vs_key_esc', '$vs_label_esc', $vs_order)");
    }
}

function vtLoadServiceItems($conn) {
    $items = [];
    $r = mysqli_query($conn, "SELECT item_key, label FROM vehicle_service_item_types ORDER BY sort_order ASC, label ASC");
    if ($r) { while ($row = mysqli_fetch_assoc($r)) { $items[$row['item_key']] = $row['label']; } }
    return $items;
}

$SERVICE_ITEMS = vtLoadServiceItems($conn);

// ---------- AJAX: get a vehicle type + its configured service items ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $res = mysqli_query($conn, "SELECT * FROM vehicle_types WHERE id = $id");
    $data = $res ? mysqli_fetch_assoc($res) : null;
    if ($data) {
        $items_res = mysqli_query($conn, "SELECT item_key, interval_km, remarks FROM vehicle_type_service_items WHERE vehicle_type_id = $id");
        $items = [];
        while ($it = mysqli_fetch_assoc($items_res)) {
            $items[$it['item_key']] = ['interval_km' => $it['interval_km'], 'remarks' => $it['remarks']];
        }
        $data['items'] = $items;
    }
    echo json_encode($data ?: []);
    exit;
}

// ---------- Save (create/update) ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_vehicle_type') {
    $name = trim($_POST['name'] ?? '');
    $id = intval($_POST['id'] ?? 0);
    $checked_items = $_POST['items'] ?? [];       // item_key => 1
    $item_km       = $_POST['item_km'] ?? [];      // item_key => km
    $item_remarks  = $_POST['item_remarks'] ?? []; // item_key => remarks

    if ($name === '') {
        $error_message = "Please enter a vehicle type name.";
    } else {
        $nameEsc = mysqli_real_escape_string($conn, $name);

        // Check name uniqueness (excluding self when editing)
        $dupSql = "SELECT id FROM vehicle_types WHERE name = '$nameEsc'" . ($id ? " AND id != $id" : "");
        $dupRes = mysqli_query($conn, $dupSql);
        if ($dupRes && mysqli_num_rows($dupRes) > 0) {
            $error_message = "A vehicle type with that name already exists.";
        } else {
            if ($id) {
                $ok = mysqli_query($conn, "UPDATE vehicle_types SET name = '$nameEsc' WHERE id = $id");
            } else {
                $ok = mysqli_query($conn, "INSERT INTO vehicle_types (name) VALUES ('$nameEsc')");
                if ($ok) { $id = mysqli_insert_id($conn); }
            }

            if ($ok && $id) {
                mysqli_query($conn, "DELETE FROM vehicle_type_service_items WHERE vehicle_type_id = $id");
                foreach ($SERVICE_ITEMS as $key => $label) {
                    if (!empty($checked_items[$key])) {
                        $km = (isset($item_km[$key]) && $item_km[$key] !== '') ? intval($item_km[$key]) : null;
                        $remarks = trim($item_remarks[$key] ?? '');
                        $kmSql = ($km !== null) ? $km : "NULL";
                        $remarksEsc = mysqli_real_escape_string($conn, $remarks);
                        $keyEsc = mysqli_real_escape_string($conn, $key);
                        mysqli_query($conn, "INSERT INTO vehicle_type_service_items (vehicle_type_id, item_key, interval_km, remarks)
                            VALUES ($id, '$keyEsc', $kmSql, '$remarksEsc')");
                    }
                }
                $success_message = $_POST['id'] ? "Vehicle type updated successfully!" : "Vehicle type added successfully!";
            } else {
                $error_message = "Error saving vehicle type: " . mysqli_error($conn);
            }
        }
    }
}

// ---------- Delete ----------
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    mysqli_query($conn, "DELETE FROM vehicle_type_service_items WHERE vehicle_type_id = $id");
    if (mysqli_query($conn, "DELETE FROM vehicle_types WHERE id = $id")) {
        $success_message = "Vehicle type deleted successfully!";
    } else {
        $error_message = "Error deleting vehicle type: " . mysqli_error($conn);
    }
}

// ---------- List ----------
$list_result = mysqli_query($conn, "SELECT * FROM vehicle_types ORDER BY name ASC");
$rows = [];
while ($row = mysqli_fetch_assoc($list_result)) {
    $cnt_res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM vehicle_type_service_items WHERE vehicle_type_id = " . $row['id']);
    $cnt_row = $cnt_res ? mysqli_fetch_assoc($cnt_res) : ['c' => 0];
    $row['item_count'] = intval($cnt_row['c'] ?? 0);
    $rows[] = $row;
}

include 'header.php';
?>

<div class="page-header">
    <h2 class="page-title">Vehicle Types</h2>
    <p class="page-subtitle">Define vehicle types and the service items, KM interval &amp; remarks that apply to each</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success_message; ?></div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?></div>
<?php endif; ?>

<div style="margin-bottom: 20px; display:flex; gap:10px; flex-wrap:wrap;">
    <button onclick="openModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Vehicle Type</button>
    <a href="vehicle_service_item_types.php" class="btn btn-secondary"><i class="fa-solid fa-list-check"></i> Manage Service Items</a>
    <a href="vehicle_service.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Vehicle Service</a>
</div>

<div class="content-card">
    <h3 class="card-title">Vehicle Types</h3>
    <?php if (count($rows) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Vehicle Type</th>
                    <th>Service Items Configured</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                    <td>
                        <?php if ($row['item_count'] > 0): ?>
                            <span class="badge badge-info"><?php echo $row['item_count']; ?> item<?php echo $row['item_count'] == 1 ? '' : 's'; ?></span>
                        <?php else: ?>
                            <span class="badge badge-neutral">None configured</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="#" onclick="editVehicleType(<?php echo $row['id']; ?>); return false;" class="btn-action btn-edit" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <a href="?delete=<?php echo $row['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Delete vehicle type \'<?php echo htmlspecialchars(addslashes($row['name'])); ?>\'? This will remove its service item configuration too.')"><i class="fa-solid fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="color: #666; font-size: 13px; text-align: center; padding: 20px;">No vehicle types yet. Click "Add Vehicle Type" to create one.</p>
    <?php endif; ?>
</div>

<!-- Modal: intentionally has NO background-click-to-close behavior (see JS below) -->
<div id="vehicleTypeModal" class="modal">
    <div class="modal-content modal-xl">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Vehicle Type</h3>
            <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="" id="vehicleTypeForm">
            <input type="hidden" name="action" value="save_vehicle_type">
            <input type="hidden" name="id" id="vt_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Vehicle Type Name <span class="required">*</span></label>
                    <input type="text" id="name" name="name" class="form-input" placeholder="e.g. Lorry - 6 Wheel, Van, Container Truck" required>
                </div>

                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-wrench"></i> Service Items for this Vehicle Type</h4>

                    <?php if (empty($SERVICE_ITEMS)): ?>
                        <p style="font-size:13px; color:#666;">No service item types configured yet. <a href="vehicle_service_item_types.php">Add some here</a> first.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table" id="vtItemsTable">
                            <thead>
                                <tr>
                                    <th style="width:40px;">Select</th>
                                    <th>Service Item</th>
                                    <th style="width:160px;">KM Interval</th>
                                    <th>Remarks / Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($SERVICE_ITEMS as $key => $label): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" class="form-checkbox vt-item-check" name="items[<?php echo $key; ?>]" value="1" id="vt_item_<?php echo $key; ?>" onchange="vtToggleRow('<?php echo $key; ?>')">
                                    </td>
                                    <td><?php echo htmlspecialchars($label); ?></td>
                                    <td>
                                        <input type="number" min="0" step="1" name="item_km[<?php echo $key; ?>]" id="vt_km_<?php echo $key; ?>" class="form-input" placeholder="e.g. 5000" disabled>
                                    </td>
                                    <td>
                                        <input type="text" name="item_remarks[<?php echo $key; ?>]" id="vt_remarks_<?php echo $key; ?>" class="form-input" placeholder="Remarks / description" disabled>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <small class="form-hint">Tick the service items that apply to this vehicle type, and set at what KM interval each should be serviced, with any remarks.</small>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> <span id="submitBtnText">Save Vehicle Type</span></button>
            </div>
        </form>
    </div>
</div>

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
.form-group { margin-bottom: 20px; }
.form-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #333333; }
.required { color: #ef4444; }
.form-input { width: 100%; padding: 10px 12px; border: 1px solid #e5e5e5; border-radius: 8px; font-size: 14px; font-family: 'Inter', sans-serif; transition: all 0.3s; background: #ffffff; box-sizing: border-box; }
.form-input:focus { outline: none; border-color: #000000; box-shadow: 0 0 0 3px rgba(0,0,0,0.05); }
.form-input:disabled { background: #fafafa; color: #aaa; }
.form-hint { display: block; font-size: 11px; color: #666666; margin-top: 10px; }
.form-checkbox { width: 18px; height: 18px; cursor: pointer; accent-color: #000000; }

/* Button Styles */
.btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-decoration: none; font-family: 'Inter', sans-serif; }
.btn i { font-size: 16px; }
.btn-primary { background: #000000; color: #ffffff; }
.btn-primary:hover { background: #333333; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.btn-secondary { background: #f5f5f5; color: #333333; border: 1px solid #e5e5e5; }
.btn-secondary:hover { background: #e5e5e5; }

/* Table Styles */
.table-responsive { overflow-x: auto; margin-top: 10px; }
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table thead { background: #fafafa; border-bottom: 2px solid #e5e5e5; }
.data-table th { padding: 12px 16px; text-align: left; font-weight: 600; color: #333333; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
.data-table tbody tr { border-bottom: 1px solid #f0f0f0; transition: background 0.2s; }
.data-table tbody tr:hover { background: #fafafa; }
.data-table td { padding: 10px 16px; color: #333333; vertical-align: middle; }

/* Badge Styles */
.badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.badge-info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
.badge-neutral { background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }

/* Action Buttons */
.action-buttons { display: flex; gap: 8px; }
.btn-action { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 6px; border: 1px solid #e5e5e5; background: #ffffff; color: #666666; cursor: pointer; transition: all 0.2s; text-decoration: none; }
.btn-action:hover { transform: translateY(-2px); box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.btn-edit:hover { background: #000000; color: #ffffff; border-color: #000000; }
.btn-delete:hover { background: #ef4444; color: #ffffff; border-color: #ef4444; }

@media (max-width: 768px) {
    .modal-content { width: 95%; }
    .modal-footer { flex-direction: column; }
    .modal-footer .btn { width: 100%; justify-content: center; }
}
</style>

<script>
const VT_SERVICE_ITEM_KEYS = <?php echo json_encode(array_keys($SERVICE_ITEMS)); ?>;

// NOTE: by design, this modal only closes via the X button or the Cancel button.
// There is deliberately no click-outside / background overlay handler here.

function vtToggleRow(key) {
    const checked = document.getElementById('vt_item_' + key).checked;
    document.getElementById('vt_km_' + key).disabled = !checked;
    document.getElementById('vt_remarks_' + key).disabled = !checked;
    if (!checked) {
        document.getElementById('vt_km_' + key).value = '';
        document.getElementById('vt_remarks_' + key).value = '';
    }
}

function openModal() {
    document.getElementById('vehicleTypeModal').classList.add('active');
    document.getElementById('vehicleTypeForm').reset();
    document.getElementById('vt_id').value = '';
    document.getElementById('modalTitle').textContent = 'Add Vehicle Type';
    document.getElementById('submitBtnText').textContent = 'Save Vehicle Type';
    VT_SERVICE_ITEM_KEYS.forEach(key => {
        document.getElementById('vt_item_' + key).checked = false;
        document.getElementById('vt_km_' + key).value = '';
        document.getElementById('vt_km_' + key).disabled = true;
        document.getElementById('vt_remarks_' + key).value = '';
        document.getElementById('vt_remarks_' + key).disabled = true;
    });
}

function closeModal() {
    document.getElementById('vehicleTypeModal').classList.remove('active');
}

function editVehicleType(id) {
    fetch('vehicle_types.php?ajax=get&id=' + id)
        .then(response => response.json())
        .then(data => {
            if (!data || !data.id) { return; }
            document.getElementById('vehicleTypeModal').classList.add('active');
            document.getElementById('vt_id').value = data.id;
            document.getElementById('name').value = data.name || '';

            VT_SERVICE_ITEM_KEYS.forEach(key => {
                document.getElementById('vt_item_' + key).checked = false;
                document.getElementById('vt_km_' + key).value = '';
                document.getElementById('vt_km_' + key).disabled = true;
                document.getElementById('vt_remarks_' + key).value = '';
                document.getElementById('vt_remarks_' + key).disabled = true;
            });

            const items = data.items || {};
            Object.keys(items).forEach(key => {
                const chk = document.getElementById('vt_item_' + key);
                if (!chk) return;
                chk.checked = true;
                const kmInput = document.getElementById('vt_km_' + key);
                const remarksInput = document.getElementById('vt_remarks_' + key);
                kmInput.disabled = false;
                remarksInput.disabled = false;
                if (items[key].interval_km !== null && items[key].interval_km !== undefined) { kmInput.value = items[key].interval_km; }
                if (items[key].remarks) { remarksInput.value = items[key].remarks; }
            });

            document.getElementById('modalTitle').textContent = 'Edit Vehicle Type';
            document.getElementById('submitBtnText').textContent = 'Update Vehicle Type';
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading vehicle type data');
        });
}
</script>

<?php include 'footer.php'; ?>
