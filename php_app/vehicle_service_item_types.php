<?php
include 'config.php';
mysqli_report(MYSQLI_REPORT_OFF);

// ---------- Table (kept in sync with the definition in vehicle_service.php) ----------
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_service_item_types (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    item_key VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(150) NOT NULL,
    sort_order INT(11) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_service_items (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    service_id INT(11) NOT NULL,
    item_key VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NULL,
    INDEX idx_service (service_id)
)");

// One-time seed from the original hardcoded list, only runs if the table is empty
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

// ---------- Helpers ----------
function vsitSlugify($label) {
    $slug = strtolower(trim($label));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    if ($slug === '') { $slug = 'item'; }
    return substr($slug, 0, 50);
}

function vsitUniqueKey($conn, $baseKey, $ignoreId = 0) {
    $key = $baseKey;
    $i = 1;
    while (true) {
        $keyEsc = mysqli_real_escape_string($conn, $key);
        $ignoreSql = $ignoreId ? "AND id != " . intval($ignoreId) : "";
        $r = mysqli_query($conn, "SELECT id FROM vehicle_service_item_types WHERE item_key = '$keyEsc' $ignoreSql LIMIT 1");
        if (!$r || mysqli_num_rows($r) === 0) { return $key; }
        $i++;
        $key = substr($baseKey, 0, 45) . '_' . $i;
    }
}

function vsitUsageCount($conn, $itemKey) {
    $keyEsc = mysqli_real_escape_string($conn, $itemKey);
    $r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM vehicle_service_items WHERE item_key = '$keyEsc'");
    $row = $r ? mysqli_fetch_assoc($r) : ['c' => 0];
    return intval($row['c'] ?? 0);
}

// ---------- AJAX: get single item type for editing ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $res = mysqli_query($conn, "SELECT * FROM vehicle_service_item_types WHERE id = $id");
    $data = $res ? mysqli_fetch_assoc($res) : null;
    echo json_encode($data ?: []);
    exit;
}

// ---------- Create / Update ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_item_type') {
    $label = trim($_POST['label'] ?? '');
    $sort_order = ($_POST['sort_order'] !== '' && isset($_POST['sort_order'])) ? intval($_POST['sort_order']) : 0;
    $id = intval($_POST['id'] ?? 0);

    if ($label === '') {
        $error_message = "Please enter a name for the service item.";
    } else {
        $labelEsc = mysqli_real_escape_string($conn, $label);

        if ($id) {
            // Update: item_key stays the same (it's referenced by historical records)
            if (mysqli_query($conn, "UPDATE vehicle_service_item_types SET label = '$labelEsc', sort_order = $sort_order WHERE id = $id")) {
                $success_message = "Service item updated successfully!";
            } else {
                $error_message = "Error updating item: " . mysqli_error($conn);
            }
        } else {
            $baseKey = vsitSlugify($label);
            $key = vsitUniqueKey($conn, $baseKey);
            $keyEsc = mysqli_real_escape_string($conn, $key);
            if (mysqli_query($conn, "INSERT INTO vehicle_service_item_types (item_key, label, sort_order) VALUES ('$keyEsc', '$labelEsc', $sort_order)")) {
                $success_message = "Service item added successfully!";
            } else {
                $error_message = "Error adding item: " . mysqli_error($conn);
            }
        }
    }
}

// ---------- Delete ----------
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $res = mysqli_query($conn, "SELECT item_key, label FROM vehicle_service_item_types WHERE id = $id");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row) {
        $error_message = "Service item not found.";
    } else {
        $usage = vsitUsageCount($conn, $row['item_key']);
        if ($usage > 0) {
            $error_message = 'Cannot delete "' . htmlspecialchars($row['label']) . '" — it is used by ' . $usage . ' existing service record(s). Remove or edit those records first.';
        } else {
            if (mysqli_query($conn, "DELETE FROM vehicle_service_item_types WHERE id = $id")) {
                $success_message = "Service item deleted successfully!";
            } else {
                $error_message = "Error deleting item: " . mysqli_error($conn);
            }
        }
    }
}

// ---------- List ----------
$list_result = mysqli_query($conn, "SELECT * FROM vehicle_service_item_types ORDER BY sort_order ASC, label ASC");
$rows = [];
while ($row = mysqli_fetch_assoc($list_result)) {
    $row['usage_count'] = vsitUsageCount($conn, $row['item_key']);
    $rows[] = $row;
}

include 'header.php';
?>

<div class="page-header">
    <h2 class="page-title">Vehicle Service Items</h2>
    <p class="page-subtitle">Manage the list of service items (oils, filters, fluids) available on the Vehicle Service page</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success_message; ?></div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?></div>
<?php endif; ?>

<div style="margin-bottom: 20px; display:flex; gap:10px; flex-wrap:wrap;">
    <button onclick="openModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Service Item</button>
    <a href="vehicle_service.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Vehicle Service</a>
</div>

<div class="content-card">
    <h3 class="card-title">Service Item Types</h3>
    <?php if (count($rows) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Name</th>
                    <th>Key</th>
                    <th>Used In</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?php echo (int)$row['sort_order']; ?></td>
                    <td><strong><?php echo htmlspecialchars($row['label']); ?></strong></td>
                    <td><code><?php echo htmlspecialchars($row['item_key']); ?></code></td>
                    <td>
                        <?php if ($row['usage_count'] > 0): ?>
                            <span class="badge badge-info"><?php echo $row['usage_count']; ?> record<?php echo $row['usage_count'] == 1 ? '' : 's'; ?></span>
                        <?php else: ?>
                            <span class="badge badge-neutral">Unused</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="#" onclick="editItemType(<?php echo $row['id']; ?>); return false;" class="btn-action btn-edit" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <a href="?delete=<?php echo $row['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Delete <?php echo htmlspecialchars(addslashes($row['label'])); ?>? This cannot be undone.')"><i class="fa-solid fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="color: #666; font-size: 13px; text-align: center; padding: 20px;">No service item types yet. Click "Add Service Item" to create one.</p>
    <?php endif; ?>
</div>

<!-- Modal -->
<div id="itemTypeModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Service Item</h3>
            <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="" id="itemTypeForm">
            <input type="hidden" name="action" value="save_item_type">
            <input type="hidden" name="id" id="item_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Name <span class="required">*</span></label>
                    <input type="text" id="label" name="label" class="form-input" placeholder="e.g. Engine Oil" required>
                    <small class="form-hint" id="keyHint"></small>
                </div>
                <div class="form-group">
                    <label class="form-label">Display Order</label>
                    <input type="number" id="sort_order" name="sort_order" class="form-input" value="0">
                    <small class="form-hint">Lower numbers appear first on the Vehicle Service form.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> <span id="submitBtnText">Save Item</span></button>
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
.modal-content { background: #ffffff; border-radius: 12px; width: 90%; max-width: 500px; max-height: none; overflow-y: visible; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); animation: slideDown 0.3s; margin-bottom: 20px; }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 24px; border-bottom: 1px solid #e5e5e5; }
.modal-title { font-size: 18px; font-weight: 600; margin: 0; }
.modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #666666; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 6px; transition: all 0.2s; }
.modal-close:hover { background: #f5f5f5; color: #000000; }
.modal-body { padding: 24px; }
.modal-footer { display: flex; justify-content: flex-end; gap: 12px; padding: 20px 24px; border-top: 1px solid #e5e5e5; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

/* Form Styles */
.form-group { margin-bottom: 20px; }
.form-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #333333; }
.required { color: #ef4444; }
.form-input { width: 100%; padding: 12px 16px; border: 1px solid #e5e5e5; border-radius: 8px; font-size: 14px; font-family: 'Inter', sans-serif; transition: all 0.3s; background: #ffffff; box-sizing: border-box; }
.form-input:focus { outline: none; border-color: #000000; box-shadow: 0 0 0 3px rgba(0,0,0,0.05); }
.form-hint { display: block; font-size: 11px; color: #666666; margin-top: 6px; }

/* Button Styles */
.btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-decoration: none; font-family: 'Inter', sans-serif; }
.btn i { font-size: 16px; }
.btn-primary { background: #000000; color: #ffffff; }
.btn-primary:hover { background: #333333; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.btn-secondary { background: #f5f5f5; color: #333333; border: 1px solid #e5e5e5; }
.btn-secondary:hover { background: #e5e5e5; }

/* Table Styles */
.table-responsive { overflow-x: auto; margin-top: 20px; }
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table thead { background: #fafafa; border-bottom: 2px solid #e5e5e5; }
.data-table th { padding: 12px 16px; text-align: left; font-weight: 600; color: #333333; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
.data-table tbody tr { border-bottom: 1px solid #f0f0f0; transition: background 0.2s; }
.data-table tbody tr:hover { background: #fafafa; }
.data-table td { padding: 14px 16px; color: #333333; }
.data-table td code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 12px; }

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
function openModal() {
    document.getElementById('itemTypeModal').classList.add('active');
    document.getElementById('itemTypeForm').reset();
    document.getElementById('item_id').value = '';
    document.getElementById('modalTitle').textContent = 'Add Service Item';
    document.getElementById('submitBtnText').textContent = 'Save Item';
    document.getElementById('keyHint').textContent = 'A unique key will be generated automatically from the name.';
}

function closeModal() {
    document.getElementById('itemTypeModal').classList.remove('active');
}

function editItemType(id) {
    fetch('vehicle_service_item_types.php?ajax=get&id=' + id)
        .then(response => response.json())
        .then(data => {
            if (!data || !data.id) { return; }
            document.getElementById('itemTypeModal').classList.add('active');
            document.getElementById('item_id').value = data.id;
            document.getElementById('label').value = data.label || '';
            document.getElementById('sort_order').value = data.sort_order || 0;
            document.getElementById('modalTitle').textContent = 'Edit Service Item';
            document.getElementById('submitBtnText').textContent = 'Update Item';
            document.getElementById('keyHint').textContent = 'Key: ' + data.item_key + ' (fixed, used by existing records)';
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading service item data');
        });
}
</script>

<?php include 'footer.php'; ?>
