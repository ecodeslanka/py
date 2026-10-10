<?php
include 'config.php';
mysqli_report(MYSQLI_REPORT_OFF);

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_service (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT(11) NOT NULL,
    service_date DATE NOT NULL,
    km INT NULL,
    invoice_no VARCHAR(100) NULL,
    total_amount DECIMAL(10,2) NULL,
    amount_mode ENUM('single','itemized') NOT NULL DEFAULT 'single',
    direct_expense_payment_id INT(11) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vehicle (vehicle_id),
    INDEX idx_date (service_date),
    INDEX idx_de_payment (direct_expense_payment_id)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_service_items (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    service_id INT(11) NOT NULL,
    item_key VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NULL,
    INDEX idx_service (service_id)
)");

// Safety-net migration in case the table already existed before this column was added
function vsAddColumnIfMissing($conn, $table, $col, $definitionSql) {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM $table LIKE '$col'");
    if ($r && mysqli_num_rows($r) === 0) { mysqli_query($conn, "ALTER TABLE $table ADD COLUMN $definitionSql"); }
}
vsAddColumnIfMissing($conn, 'vehicle_service', 'direct_expense_payment_id', 'direct_expense_payment_id INT(11) NULL');

// ---------- Direct Expense integration (matches expense_payments.php exactly) ----------
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS expenses (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    expense_name           VARCHAR(200) NOT NULL,
    category_id            INT NULL,
    enable_float           TINYINT(1) NOT NULL DEFAULT 0,
    mybos_account_id       INT NULL,
    budget_id              INT NULL,
    roi_id                 INT NULL,
    initiater_ids          TEXT NULL,
    authorizer_ids         TEXT NULL,
    approver_ids           TEXT NULL,
    parent_cash_float_id   INT NULL,
    created_at             DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS expense_payments (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    payment_date  DATE NOT NULL,
    expense_id    INT NOT NULL,
    budget_id     INT NULL,
    amount        DECIMAL(14,2) NOT NULL DEFAULT 0,
    remarks       VARCHAR(500) NULL,
    initiated_by  INT NULL,
    authorized_by   INT NULL,
    authorized_at   DATETIME NULL,
    approved_by     INT NULL,
    approved_at     DATETIME NULL,
    paid_by         INT NULL,
    paid_at         DATETIME NULL,
    rejected_by     INT NULL,
    rejected_at     DATETIME NULL,
    reject_remarks  VARCHAR(500) NULL,
    cancelled_by    INT NULL,
    cancelled_at    DATETIME NULL,
    cancel_remarks  VARCHAR(500) NULL,
    status        ENUM('initiated','authorized','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'initiated',
    auto_authorized TINYINT(1) NOT NULL DEFAULT 0,
    auto_approved   TINYINT(1) NOT NULL DEFAULT 0,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payment_date (payment_date),
    INDEX idx_expense (expense_id),
    INDEX idx_budget (budget_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS expense_payment_attachments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    payment_id   INT NOT NULL,
    file_name    VARCHAR(255) NOT NULL,
    file_path    VARCHAR(500) NOT NULL,
    file_size    INT NULL,
    uploaded_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Singleton settings row: which Direct Expense service entries should charge against
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_service_expense_settings (
    id                  INT(11) NOT NULL PRIMARY KEY DEFAULT 1,
    direct_expense_id   INT(11) NULL,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
mysqli_query($conn, "INSERT IGNORE INTO vehicle_service_expense_settings (id, direct_expense_id) VALUES (1, NULL)");

// "Direct" expenses = ordinary expenses NOT tied to any Cash Float
function vsDirectExpensesList($conn) {
    $list = [];
    $r = mysqli_query($conn, "SELECT id, expense_name, budget_id FROM expenses WHERE enable_float = 0 AND parent_cash_float_id IS NULL ORDER BY expense_name ASC");
    if ($r) { while ($row = mysqli_fetch_assoc($r)) { $list[] = $row; } }
    return $list;
}

function vsJsonIds($str) {
    if (!$str) return [];
    $arr = json_decode($str, true);
    return is_array($arr) ? array_map('intval', $arr) : [];
}

// Same auto-progression rule as expense_payments.php's epAutoProgressStatus()
function vsEpAutoProgressStatus($conn, $paymentId) {
    $paymentId = intval($paymentId);
    if (!$paymentId) return null;
    $r = mysqli_query($conn, "SELECT ep.status, e.authorizer_ids, e.approver_ids FROM expense_payments ep LEFT JOIN expenses e ON e.id = ep.expense_id WHERE ep.id = $paymentId LIMIT 1");
    if (!$r || mysqli_num_rows($r) === 0) return null;
    $row = mysqli_fetch_assoc($r);
    $authorizerIds = vsJsonIds($row['authorizer_ids']);
    $approverIds   = vsJsonIds($row['approver_ids']);
    $status = $row['status'];
    if ($status === 'initiated' && empty($authorizerIds)) {
        mysqli_query($conn, "UPDATE expense_payments SET status='authorized', authorized_by=NULL, authorized_at=NOW(), auto_authorized=1 WHERE id=$paymentId LIMIT 1");
        $status = 'authorized';
    }
    if ($status === 'authorized' && empty($approverIds)) {
        mysqli_query($conn, "UPDATE expense_payments SET status='approved', approved_by=NULL, approved_at=NOW(), auto_approved=1 WHERE id=$paymentId LIMIT 1");
        $status = 'approved';
    }
    return $status;
}

function vsCurrentUserId() {
    global $current_user;
    if (isset($current_user) && is_array($current_user) && !empty($current_user['id'])) { return intval($current_user['id']); }
    if (!empty($_SESSION['user_id'])) { return intval($_SESSION['user_id']); }
    return 0;
}

function vsVehicleNumber($conn, $vehicleId) {
    $vehicleId = intval($vehicleId);
    if (!$vehicleId) return '#' . $vehicleId;
    $r = mysqli_query($conn, "SELECT vehicle_number FROM vehicles WHERE id = $vehicleId");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return ($row && !empty($row['vehicle_number'])) ? $row['vehicle_number'] : ('#' . $vehicleId);
}

// ---------- Service Item Types (managed on vehicle_service_item_types.php) ----------
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_service_item_types (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    item_key VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(150) NOT NULL,
    sort_order INT(11) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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

function vsLoadServiceItems($conn) {
    $items = [];
    $r = mysqli_query($conn, "SELECT item_key, label FROM vehicle_service_item_types ORDER BY sort_order ASC, label ASC");
    if ($r) { while ($row = mysqli_fetch_assoc($r)) { $items[$row['item_key']] = $row['label']; } }
    return $items;
}

$SERVICE_ITEMS = vsLoadServiceItems($conn);

try {
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'get' && isset($_GET['id'])) {
        header('Content-Type: application/json');
        $id = intval($_GET['id']);
        $res = mysqli_query($conn, "SELECT * FROM vehicle_service WHERE id = $id");
        $data = mysqli_fetch_assoc($res);
        if ($data) {
            $items_res = mysqli_query($conn, "SELECT item_key, amount FROM vehicle_service_items WHERE service_id = $id");
            $items = [];
            while ($it = mysqli_fetch_assoc($items_res)) { $items[$it['item_key']] = $it['amount']; }
            $data['items'] = $items;
        }
        echo json_encode($data ?: []);
        exit;
    }
} catch (\Throwable $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// ---------- Save Expense Settings (Direct Expense only, matching expense_payments.php) ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_de_settings') {
    $set_direct_exp_id = intval($_POST['de_direct_expense_id'] ?? 0);
    if (!$set_direct_exp_id) {
        $error_message = "Please select a Direct Expense.";
    } else {
        $validIds = array_map(function($e) { return (int)$e['id']; }, vsDirectExpensesList($conn));
        if (!in_array($set_direct_exp_id, $validIds, true)) {
            $error_message = "That expense is not a valid Direct Expense.";
        } else {
            mysqli_query($conn, "INSERT INTO vehicle_service_expense_settings (id, direct_expense_id) VALUES (1, $set_direct_exp_id)
                                  ON DUPLICATE KEY UPDATE direct_expense_id = $set_direct_exp_id");
            $success_message = "Expense settings saved as default successfully!";
        }
    }
}

// ---------- Load current settings ----------
$de_settings_res = mysqli_query($conn, "SELECT * FROM vehicle_service_expense_settings WHERE id = 1");
$de_settings = mysqli_fetch_assoc($de_settings_res) ?: ['direct_expense_id' => null];
$de_expense_id = intval($de_settings['direct_expense_id'] ?? 0);

$de_configured = false;
$de_expense_row = null;
if ($de_expense_id) {
    $der = mysqli_query($conn, "SELECT * FROM expenses WHERE id = " . intval($de_expense_id) . " LIMIT 1");
    $de_expense_row = $der ? mysqli_fetch_assoc($der) : null;
    if ($de_expense_row) { $de_configured = true; }
}

$direct_expenses_list = vsDirectExpensesList($conn);

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $res = mysqli_query($conn, "SELECT direct_expense_payment_id FROM vehicle_service WHERE id = $id");
    $row = mysqli_fetch_assoc($res);
    mysqli_query($conn, "DELETE FROM vehicle_service_items WHERE service_id = $id");
    if (mysqli_query($conn, "DELETE FROM vehicle_service WHERE id = $id")) {
        // Delete the linked Direct Expense payment together with the service record
        if ($row && !empty($row['direct_expense_payment_id'])) {
            $dep_id = intval($row['direct_expense_payment_id']);
            mysqli_query($conn, "DELETE FROM expense_payment_attachments WHERE payment_id = $dep_id");
            mysqli_query($conn, "DELETE FROM expense_payments WHERE id = $dep_id");
        }
        $success_message = "Service record deleted successfully!";
    } else {
        $error_message = "Error deleting record: " . mysqli_error($conn);
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['action'])) {
    $vehicle_id   = intval($_POST['vehicle_id']);
    $service_date = mysqli_real_escape_string($conn, $_POST['service_date']);
    $km           = ($_POST['km'] !== '') ? intval($_POST['km']) : null;
    $invoice_no   = mysqli_real_escape_string($conn, $_POST['invoice_no']);
    $amount_mode  = ($_POST['amount_mode'] === 'itemized') ? 'itemized' : 'single';

    $checked_items = $_POST['items'] ?? []; // array of item_key => 1
    $item_amounts  = $_POST['item_amount'] ?? []; // array item_key => amount

    $selected = [];
    $items_total = 0;
    foreach ($SERVICE_ITEMS as $key => $label) {
        if (!empty($checked_items[$key])) {
            $amt = null;
            if ($amount_mode === 'itemized') {
                $amt = ($item_amounts[$key] !== '' && isset($item_amounts[$key])) ? floatval($item_amounts[$key]) : null;
                $items_total += $amt ?: 0;
            }
            $selected[$key] = $amt;
        }
    }

    if ($amount_mode === 'itemized') {
        $total_amount = $items_total;
    } else {
        $total_amount = ($_POST['total_amount'] !== '') ? floatval($_POST['total_amount']) : null;
    }
    $total_sql = ($total_amount !== null) ? $total_amount : "NULL";
    $expense_amount = $total_amount !== null ? $total_amount : 0;
    $remarks = mysqli_real_escape_string($conn, 'Vehicle Service — Lorry ' . vsVehicleNumber($conn, $vehicle_id) . ' on ' . $service_date);
    $uidv = vsCurrentUserId();
    $initSql = $uidv ? $uidv : 'NULL';

    if (isset($_POST['service_id']) && !empty($_POST['service_id'])) {
        $id = intval($_POST['service_id']);
        $existing_res = mysqli_query($conn, "SELECT direct_expense_payment_id FROM vehicle_service WHERE id = $id");
        $existing = mysqli_fetch_assoc($existing_res);
        $existing_dep_id = intval($existing['direct_expense_payment_id'] ?? 0);
        $new_dep_id = $existing_dep_id;

        if ($de_configured && $expense_amount > 0) {
            $budget_id = $de_expense_row['budget_id'] ? intval($de_expense_row['budget_id']) : 0;
            $budgetSql = $budget_id ? $budget_id : 'NULL';
            if ($existing_dep_id) {
                mysqli_query($conn, "UPDATE expense_payments SET
                    payment_date = '$service_date', expense_id = $de_expense_id,
                    budget_id = $budgetSql, amount = $expense_amount, remarks = '$remarks'
                    WHERE id = $existing_dep_id");
            } else {
                mysqli_query($conn, "INSERT INTO expense_payments (payment_date, expense_id, budget_id, amount, remarks, initiated_by, status)
                    VALUES ('$service_date', $de_expense_id, $budgetSql, $expense_amount, '$remarks', $initSql, 'initiated')");
                $new_dep_id = mysqli_insert_id($conn);
                vsEpAutoProgressStatus($conn, $new_dep_id);
            }
        }

        $sql = "UPDATE vehicle_service SET
                vehicle_id = $vehicle_id, service_date = '$service_date',
                km = " . ($km !== null ? $km : "NULL") . ",
                invoice_no = '$invoice_no', total_amount = $total_sql, amount_mode = '$amount_mode',
                direct_expense_payment_id = " . ($new_dep_id ? $new_dep_id : "NULL") . "
                WHERE id = $id";
        if (mysqli_query($conn, $sql)) {
            mysqli_query($conn, "DELETE FROM vehicle_service_items WHERE service_id = $id");
            foreach ($selected as $key => $amt) {
                $amt_sql = ($amt !== null) ? $amt : "NULL";
                mysqli_query($conn, "INSERT INTO vehicle_service_items (service_id, item_key, amount) VALUES ($id, '$key', $amt_sql)");
            }
            $success_message = "Service record updated successfully!";
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    } else {
        $new_dep_id = null;

        if ($de_configured && $expense_amount > 0) {
            $budget_id = $de_expense_row['budget_id'] ? intval($de_expense_row['budget_id']) : 0;
            $budgetSql = $budget_id ? $budget_id : 'NULL';
            $ok = mysqli_query($conn, "INSERT INTO expense_payments (payment_date, expense_id, budget_id, amount, remarks, initiated_by, status)
                VALUES ('$service_date', $de_expense_id, $budgetSql, $expense_amount, '$remarks', $initSql, 'initiated')");
            if ($ok) {
                $new_dep_id = mysqli_insert_id($conn);
                vsEpAutoProgressStatus($conn, $new_dep_id);
            }
        }

        $sql = "INSERT INTO vehicle_service (vehicle_id, service_date, km, invoice_no, total_amount, amount_mode, direct_expense_payment_id)
                VALUES ($vehicle_id, '$service_date', " . ($km !== null ? $km : "NULL") . ", '$invoice_no', $total_sql, '$amount_mode', " . ($new_dep_id ? $new_dep_id : "NULL") . ")";
        if (mysqli_query($conn, $sql)) {
            $id = mysqli_insert_id($conn);
            foreach ($selected as $key => $amt) {
                $amt_sql = ($amt !== null) ? $amt : "NULL";
                mysqli_query($conn, "INSERT INTO vehicle_service_items (service_id, item_key, amount) VALUES ($id, '$key', $amt_sql)");
            }
            $success_message = "Service record added successfully!";
            if ($de_configured && $expense_amount > 0) { $success_message .= " Expense payment created and linked."; }
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    }
}

$list_sql = "SELECT s.*, v.vehicle_number FROM vehicle_service s
             LEFT JOIN vehicles v ON s.vehicle_id = v.id
             ORDER BY s.service_date DESC, s.id DESC";
$list_result = mysqli_query($conn, $list_sql);

$rows = [];
while ($row = mysqli_fetch_assoc($list_result)) {
    $items_res = mysqli_query($conn, "SELECT item_key FROM vehicle_service_items WHERE service_id = " . $row['id']);
    $items = [];
    while ($it = mysqli_fetch_assoc($items_res)) { $items[] = $it['item_key']; }
    $row['item_keys'] = $items;
    $rows[] = $row;
}

$vehicles_result = mysqli_query($conn, "SELECT id, vehicle_number FROM vehicles ORDER BY vehicle_number");
$vehicles_list = [];
while ($v = mysqli_fetch_assoc($vehicles_result)) { $vehicles_list[] = $v; }

include 'header.php';
?>

<div class="page-header">
    <h2 class="page-title">Vehicle Service</h2>
    <p class="page-subtitle">Log routine service items (oils, filters, fluids) per lorry</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success_message; ?></div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?></div>
<?php endif; ?>

<div style="margin-bottom: 20px; display:flex; gap:10px; flex-wrap:wrap;">
    <button onclick="openModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Service Record</button>
    <button onclick="openDeSettingsModal()" class="btn btn-secondary"><i class="fa-solid fa-gear"></i> Expense Settings</button>
    <a href="vehicle_service_item_types.php" class="btn btn-secondary"><i class="fa-solid fa-list-check"></i> Manage Service Items</a>
</div>

<!-- Expense Settings Widget -->
<?php if ($de_configured): ?>
<div class="content-card" style="margin-bottom:20px;">
    <h3 class="card-title"><i class="fa-solid fa-file-invoice-dollar"></i> Linked Direct Expense</h3>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <span class="badge badge-info"><i class="fa-solid fa-receipt"></i> Expense: <?php echo htmlspecialchars($de_expense_row['expense_name']); ?></span>
    </div>
    <p style="font-size:12px; color:#666; margin:12px 0 0;"><i class="fa-solid fa-circle-info"></i> Every Service entry (with a Total Amount &gt; 0) will create/update a direct Expense Payment against this expense (same workflow as expense_payments.php: Initiated → Authorized → Approved → Paid).</p>
</div>
<?php else: ?>
<div class="alert" style="background:#fffbeb; color:#92400e; border:1px solid #fde68a;">
    <i class="fa-solid fa-triangle-exclamation"></i> No Direct Expense is linked yet — service entries will be saved without an expense payment until you configure one via "Expense Settings".
</div>
<?php endif; ?>

<div class="content-card">
    <h3 class="card-title">Service Records</h3>
    <?php if (count($rows) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Lorry</th>
                    <th>KM</th>
                    <th>Invoice No</th>
                    <th>Items Done</th>
                    <th>Total Amount</th>
                    <th>Expense Link</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?php echo date('d-M-Y', strtotime($row['service_date'])); ?></td>
                    <td><strong><?php echo htmlspecialchars($row['vehicle_number'] ?? '-'); ?></strong></td>
                    <td><?php echo $row['km'] !== null ? number_format($row['km']) : '-'; ?></td>
                    <td><?php echo htmlspecialchars($row['invoice_no']) ?: '-'; ?></td>
                    <td>
                        <?php foreach ($row['item_keys'] as $key): ?>
                            <span class="badge badge-info"><?php echo $SERVICE_ITEMS[$key] ?? $key; ?></span>
                        <?php endforeach; ?>
                        <?php if (empty($row['item_keys'])): ?><span style="color:#999;">-</span><?php endif; ?>
                    </td>
                    <td><?php echo $row['total_amount'] !== null ? number_format($row['total_amount'], 2) : '-'; ?></td>
                    <td>
                        <?php if (!empty($row['direct_expense_payment_id'])): ?>
                            <span class="badge badge-info" title="Direct Expense payment #<?php echo (int)$row['direct_expense_payment_id']; ?>"><i class="fa-solid fa-link"></i> Direct Expense</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="#" onclick="editService(<?php echo $row['id']; ?>); return false;" class="btn-action btn-edit" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <a href="?delete=<?php echo $row['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Delete this service record? This will also remove its linked Expense payment, if any.')"><i class="fa-solid fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="color: #666; font-size: 13px; text-align: center; padding: 20px;">No service records found.</p>
    <?php endif; ?>
</div>

<!-- Expense Settings Modal -->
<div id="deSettingsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Expense Settings</h3>
            <button class="modal-close" onclick="closeDeSettingsModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="" id="deSettingsForm">
            <input type="hidden" name="action" value="save_de_settings">
            <div class="modal-body">
                <p style="font-size:13px; color:#666; margin-top:0;">Choose which Expense every Service entry's Total Amount should be recorded against as a direct Expense Payment (saved as the default for this page).</p>
                <div class="form-group">
                    <label class="form-label">Direct Expense <span class="required">*</span></label>
                    <select id="de_direct_expense_id" name="de_direct_expense_id" class="form-input select2-de-direct" required>
                        <option value="">Select Expense</option>
                        <?php foreach ($direct_expenses_list as $de): ?>
                            <option value="<?php echo $de['id']; ?>" <?php echo ($de_expense_id == $de['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($de['expense_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Ordinary expenses (built on expenses.php / expense_payments.php). Creates a regular Expense Payment (Initiated → Authorized → Approved → Paid) per service entry with a Total Amount &gt; 0.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeSettingsModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save as Default</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal -->
<div id="serviceModal" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Service Record</h3>
            <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="" id="serviceForm">
            <input type="hidden" name="service_id" id="service_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Lorry Number <span class="required">*</span></label>
                    <select id="vehicle_id" name="vehicle_id" class="form-input select2-vehicle" required>
                        <option value="">Select Lorry</option>
                        <?php foreach ($vehicles_list as $v): ?>
                            <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['vehicle_number']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Service Date <span class="required">*</span></label>
                        <input type="date" id="service_date" name="service_date" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">KM</label>
                        <input type="number" id="km" name="km" class="form-input">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Invoice No</label>
                    <input type="text" id="invoice_no" name="invoice_no" class="form-input">
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="amount_mode_toggle" class="form-checkbox" onchange="toggleAmountMode()">
                        <span class="checkbox-text">Enter amount per item (itemized) — otherwise a single total amount is used</span>
                    </label>
                    <input type="hidden" name="amount_mode" id="amount_mode" value="single">
                </div>

                <div class="form-group" id="singleTotalWrap">
                    <label class="form-label">Total Amount</label>
                    <input type="number" step="0.01" id="total_amount" name="total_amount" class="form-input">
                </div>

                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-wrench"></i> Service Items</h4>
                    <?php if (empty($SERVICE_ITEMS)): ?>
                        <p style="font-size:13px; color:#666;">No service item types configured yet. <a href="vehicle_service_item_types.php">Add some here</a>.</p>
                    <?php else: ?>
                    <div class="checkbox-grid" id="itemsGrid">
                        <?php foreach ($SERVICE_ITEMS as $key => $label): ?>
                        <div>
                            <label class="checkbox-label" style="margin-bottom:6px;">
                                <input type="checkbox" class="form-checkbox item-check" name="items[<?php echo $key; ?>]" value="1" id="item_<?php echo $key; ?>" onchange="toggleItemAmount('<?php echo $key; ?>')">
                                <span class="checkbox-text"><?php echo htmlspecialchars($label); ?></span>
                            </label>
                            <input type="number" step="0.01" name="item_amount[<?php echo $key; ?>]" id="item_amount_<?php echo $key; ?>" class="form-input item-amount-input" placeholder="Amount" style="display:none;">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="balance-bar ok" id="itemizedTotalBar" style="display:none;">
                    <span>Items Total</span>
                    <span id="itemizedTotalDisplay">0.00</span>
                </div>

                <?php if ($de_configured): ?>
                <p style="font-size:12px; color:#666; margin:14px 0 0;"><i class="fa-solid fa-circle-info"></i> Saving this entry will automatically create/update a Direct Expense payment (for the Total Amount, if greater than 0) against "<?php echo htmlspecialchars($de_expense_row['expense_name']); ?>".</p>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> <span id="submitBtnText">Save Record</span></button>
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
const SERVICE_ITEM_KEYS = <?php echo json_encode(array_keys($SERVICE_ITEMS)); ?>;

function toggleAmountMode() {
    const itemized = document.getElementById('amount_mode_toggle').checked;
    document.getElementById('amount_mode').value = itemized ? 'itemized' : 'single';
    document.getElementById('singleTotalWrap').style.display = itemized ? 'none' : 'block';
    document.getElementById('itemizedTotalBar').style.display = itemized ? 'flex' : 'none';
    SERVICE_ITEM_KEYS.forEach(key => {
        const inp = document.getElementById('item_amount_' + key);
        const checked = document.getElementById('item_' + key).checked;
        inp.style.display = (itemized && checked) ? 'block' : 'none';
    });
    recalcItemizedTotal();
}

function toggleItemAmount(key) {
    const checked = document.getElementById('item_' + key).checked;
    const itemized = document.getElementById('amount_mode_toggle').checked;
    document.getElementById('item_amount_' + key).style.display = (itemized && checked) ? 'block' : 'none';
    recalcItemizedTotal();
}

function recalcItemizedTotal() {
    let total = 0;
    SERVICE_ITEM_KEYS.forEach(key => {
        if (document.getElementById('item_' + key).checked) {
            total += parseFloat(document.getElementById('item_amount_' + key).value) || 0;
        }
    });
    document.getElementById('itemizedTotalDisplay').textContent = total.toFixed(2);
}

function openModal() {
    document.getElementById('serviceModal').classList.add('active');
    document.getElementById('serviceForm').reset();
    document.getElementById('service_id').value = '';
    document.getElementById('modalTitle').textContent = 'Add Service Record';
    document.getElementById('submitBtnText').textContent = 'Save Record';
    SERVICE_ITEM_KEYS.forEach(key => { document.getElementById('item_amount_' + key).style.display = 'none'; });
    document.getElementById('amount_mode_toggle').checked = false;
    toggleAmountMode();
    if (window.jQuery) { $('.select2-vehicle').val('').trigger('change'); }
}

function closeModal() {
    document.getElementById('serviceModal').classList.remove('active');
}

function editService(id) {
    fetch('vehicle_service.php?ajax=get&id=' + id)
        .then(response => response.json())
        .then(data => {
            document.getElementById('serviceModal').classList.add('active');
            document.getElementById('service_id').value = data.id;
            document.getElementById('service_date').value = data.service_date;
            document.getElementById('km').value = data.km || '';
            document.getElementById('invoice_no').value = data.invoice_no || '';
            document.getElementById('total_amount').value = data.total_amount || '';

            if (window.jQuery) { $('#vehicle_id').val(data.vehicle_id).trigger('change'); }
            else { document.getElementById('vehicle_id').value = data.vehicle_id; }

            SERVICE_ITEM_KEYS.forEach(key => {
                document.getElementById('item_' + key).checked = false;
                document.getElementById('item_amount_' + key).value = '';
                document.getElementById('item_amount_' + key).style.display = 'none';
            });

            const items = data.items || {};
            Object.keys(items).forEach(key => {
                const chk = document.getElementById('item_' + key);
                if (chk) chk.checked = true;
                const amtInput = document.getElementById('item_amount_' + key);
                if (amtInput && items[key] !== null) amtInput.value = items[key];
            });

            document.getElementById('amount_mode_toggle').checked = (data.amount_mode === 'itemized');
            toggleAmountMode();

            document.getElementById('modalTitle').textContent = 'Edit Service Record';
            document.getElementById('submitBtnText').textContent = 'Update Record';
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading service record data');
        });
}

// ---------- Expense Settings Modal ----------
function openDeSettingsModal() {
    document.getElementById('deSettingsModal').classList.add('active');
}

function closeDeSettingsModal() {
    document.getElementById('deSettingsModal').classList.remove('active');
}

document.addEventListener('DOMContentLoaded', function() {
    if (window.jQuery && $.fn.select2) {
        $('.select2-vehicle').select2({ width: '100%', placeholder: 'Select Lorry' });
        $('.select2-de-direct').select2({ width: '100%', placeholder: 'Select Direct Expense', dropdownParent: $('#deSettingsModal') });
    }
    SERVICE_ITEM_KEYS.forEach(key => {
        document.getElementById('item_amount_' + key).addEventListener('input', recalcItemizedTotal);
    });
});
</script>

<?php include 'footer.php'; ?>
