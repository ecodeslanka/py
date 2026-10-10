<?php
include 'config.php';
mysqli_report(MYSQLI_REPORT_OFF);

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_insurance (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT(11) NOT NULL,
    renewal_date DATE NOT NULL,
    sum_insured DECIMAL(10,2) NULL,
    year_range VARCHAR(20) NULL,
    premium DECIMAL(10,2) NOT NULL DEFAULT 0,
    monthly_rental DECIMAL(10,2) NOT NULL DEFAULT 0,
    document_upload VARCHAR(255) NULL,
    direct_expense_payment_id INT(11) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vehicle (vehicle_id),
    INDEX idx_renewal (renewal_date),
    INDEX idx_de_payment (direct_expense_payment_id)
)");

// Safety-net migration in case the table already existed before this column was added
function insAddColumnIfMissing($conn, $table, $col, $definitionSql) {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM $table LIKE '$col'");
    if ($r && mysqli_num_rows($r) === 0) { mysqli_query($conn, "ALTER TABLE $table ADD COLUMN $definitionSql"); }
}
insAddColumnIfMissing($conn, 'vehicle_insurance', 'direct_expense_payment_id', 'direct_expense_payment_id INT(11) NULL');

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

// Singleton settings row: which Direct Expense insurance entries should charge against
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_insurance_expense_settings (
    id                  INT(11) NOT NULL PRIMARY KEY DEFAULT 1,
    direct_expense_id   INT(11) NULL,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
mysqli_query($conn, "INSERT IGNORE INTO vehicle_insurance_expense_settings (id, direct_expense_id) VALUES (1, NULL)");

// "Direct" expenses = ordinary expenses NOT tied to any Cash Float
function insDirectExpensesList($conn) {
    $list = [];
    $r = mysqli_query($conn, "SELECT id, expense_name, budget_id FROM expenses WHERE enable_float = 0 AND parent_cash_float_id IS NULL ORDER BY expense_name ASC");
    if ($r) { while ($row = mysqli_fetch_assoc($r)) { $list[] = $row; } }
    return $list;
}

function insJsonIds($str) {
    if (!$str) return [];
    $arr = json_decode($str, true);
    return is_array($arr) ? array_map('intval', $arr) : [];
}

// Same auto-progression rule as expense_payments.php's epAutoProgressStatus()
function insEpAutoProgressStatus($conn, $paymentId) {
    $paymentId = intval($paymentId);
    if (!$paymentId) return null;
    $r = mysqli_query($conn, "SELECT ep.status, e.authorizer_ids, e.approver_ids FROM expense_payments ep LEFT JOIN expenses e ON e.id = ep.expense_id WHERE ep.id = $paymentId LIMIT 1");
    if (!$r || mysqli_num_rows($r) === 0) return null;
    $row = mysqli_fetch_assoc($r);
    $authorizerIds = insJsonIds($row['authorizer_ids']);
    $approverIds   = insJsonIds($row['approver_ids']);
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

function insCurrentUserId() {
    global $current_user;
    if (isset($current_user) && is_array($current_user) && !empty($current_user['id'])) { return intval($current_user['id']); }
    if (!empty($_SESSION['user_id'])) { return intval($_SESSION['user_id']); }
    return 0;
}

function insVehicleNumber($conn, $vehicleId) {
    $vehicleId = intval($vehicleId);
    if (!$vehicleId) return '#' . $vehicleId;
    $r = mysqli_query($conn, "SELECT vehicle_number FROM vehicles WHERE id = $vehicleId");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return ($row && !empty($row['vehicle_number'])) ? $row['vehicle_number'] : ('#' . $vehicleId);
}

$upload_dir = 'uploads/vehicle_insurance/';
if (!file_exists($upload_dir)) { mkdir($upload_dir, 0777, true); }

function insUpload($file, $upload_dir, $prefix='doc') {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $name = $prefix.'_'.time().'_'.uniqid().'.'.$ext;
    $dest = $upload_dir.$name;
    return move_uploaded_file($file['tmp_name'], $dest) ? $dest : null;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'get' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $res = mysqli_query($conn, "SELECT * FROM vehicle_insurance WHERE id = $id");
    echo json_encode(mysqli_fetch_assoc($res) ?: []);
    exit;
}

// ---------- Save Expense Settings (Direct Expense only, matching expense_payments.php) ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_de_settings') {
    $set_direct_exp_id = intval($_POST['de_direct_expense_id'] ?? 0);
    if (!$set_direct_exp_id) {
        $error_message = "Please select a Direct Expense.";
    } else {
        $validIds = array_map(function($e) { return (int)$e['id']; }, insDirectExpensesList($conn));
        if (!in_array($set_direct_exp_id, $validIds, true)) {
            $error_message = "That expense is not a valid Direct Expense.";
        } else {
            mysqli_query($conn, "INSERT INTO vehicle_insurance_expense_settings (id, direct_expense_id) VALUES (1, $set_direct_exp_id)
                                  ON DUPLICATE KEY UPDATE direct_expense_id = $set_direct_exp_id");
            $success_message = "Expense settings saved as default successfully!";
        }
    }
}

// ---------- Load current settings ----------
$de_settings_res = mysqli_query($conn, "SELECT * FROM vehicle_insurance_expense_settings WHERE id = 1");
$de_settings = mysqli_fetch_assoc($de_settings_res) ?: ['direct_expense_id' => null];
$de_expense_id = intval($de_settings['direct_expense_id'] ?? 0);

$de_configured = false;
$de_expense_row = null;
if ($de_expense_id) {
    $der = mysqli_query($conn, "SELECT * FROM expenses WHERE id = " . intval($de_expense_id) . " LIMIT 1");
    $de_expense_row = $der ? mysqli_fetch_assoc($der) : null;
    if ($de_expense_row) { $de_configured = true; }
}

$direct_expenses_list = insDirectExpensesList($conn);

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $res = mysqli_query($conn, "SELECT document_upload, direct_expense_payment_id FROM vehicle_insurance WHERE id = $id");
    $row = mysqli_fetch_assoc($res);
    if (mysqli_query($conn, "DELETE FROM vehicle_insurance WHERE id = $id")) {
        if ($row && !empty($row['document_upload']) && file_exists($row['document_upload'])) @unlink($row['document_upload']);
        // Delete the linked Direct Expense payment together with the insurance record
        if ($row && !empty($row['direct_expense_payment_id'])) {
            $dep_id = intval($row['direct_expense_payment_id']);
            mysqli_query($conn, "DELETE FROM expense_payment_attachments WHERE payment_id = $dep_id");
            mysqli_query($conn, "DELETE FROM expense_payments WHERE id = $dep_id");
        }
        $success_message = "Insurance record deleted successfully!";
    } else {
        $error_message = "Error deleting record: " . mysqli_error($conn);
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['action'])) {
    $vehicle_id   = intval($_POST['vehicle_id']);
    $renewal_date = mysqli_real_escape_string($conn, $_POST['renewal_date']);
    $sum_insured  = ($_POST['sum_insured'] !== '') ? floatval($_POST['sum_insured']) : null;
    $year_range   = mysqli_real_escape_string($conn, $_POST['year_range']);
    $premium      = floatval($_POST['premium']);
    $monthly_rental = round($premium / 12, 2);
    $remarks = mysqli_real_escape_string($conn, 'Vehicle Insurance — Lorry ' . insVehicleNumber($conn, $vehicle_id) . ' renewal ' . $renewal_date);
    $uidv = insCurrentUserId();
    $initSql = $uidv ? $uidv : 'NULL';

    if (isset($_POST['insurance_id']) && !empty($_POST['insurance_id'])) {
        $id = intval($_POST['insurance_id']);
        $existing_res = mysqli_query($conn, "SELECT document_upload, direct_expense_payment_id FROM vehicle_insurance WHERE id = $id");
        $existing = mysqli_fetch_assoc($existing_res);
        $document_upload = $existing['document_upload'];
        $existing_dep_id = intval($existing['direct_expense_payment_id'] ?? 0);
        $new_dep_id = $existing_dep_id;
        if (!empty($_FILES['document_upload']['name'])) {
            $new_doc = insUpload($_FILES['document_upload'], $upload_dir, 'insurance');
            if ($new_doc) {
                if (!empty($existing['document_upload']) && file_exists($existing['document_upload'])) @unlink($existing['document_upload']);
                $document_upload = $new_doc;
            }
        }

        if ($de_configured) {
            $budget_id = $de_expense_row['budget_id'] ? intval($de_expense_row['budget_id']) : 0;
            $budgetSql = $budget_id ? $budget_id : 'NULL';
            if ($existing_dep_id) {
                mysqli_query($conn, "UPDATE expense_payments SET
                    payment_date = '$renewal_date', expense_id = $de_expense_id,
                    budget_id = $budgetSql, amount = $premium, remarks = '$remarks'
                    WHERE id = $existing_dep_id");
            } else {
                mysqli_query($conn, "INSERT INTO expense_payments (payment_date, expense_id, budget_id, amount, remarks, initiated_by, status)
                    VALUES ('$renewal_date', $de_expense_id, $budgetSql, $premium, '$remarks', $initSql, 'initiated')");
                $new_dep_id = mysqli_insert_id($conn);
                insEpAutoProgressStatus($conn, $new_dep_id);
            }
        }

        $sql = "UPDATE vehicle_insurance SET
                vehicle_id = $vehicle_id,
                renewal_date = '$renewal_date',
                sum_insured = " . ($sum_insured !== null ? $sum_insured : "NULL") . ",
                year_range = '$year_range',
                premium = $premium,
                monthly_rental = $monthly_rental,
                document_upload = " . ($document_upload ? "'$document_upload'" : "NULL") . ",
                direct_expense_payment_id = " . ($new_dep_id ? $new_dep_id : "NULL") . "
                WHERE id = $id";
        if (mysqli_query($conn, $sql)) { $success_message = "Insurance record updated successfully!"; }
        else { $error_message = "Error: " . mysqli_error($conn); }
    } else {
        $document_upload = !empty($_FILES['document_upload']['name']) ? insUpload($_FILES['document_upload'], $upload_dir, 'insurance') : null;
        $new_dep_id = null;

        if ($de_configured) {
            $budget_id = $de_expense_row['budget_id'] ? intval($de_expense_row['budget_id']) : 0;
            $budgetSql = $budget_id ? $budget_id : 'NULL';
            $ok = mysqli_query($conn, "INSERT INTO expense_payments (payment_date, expense_id, budget_id, amount, remarks, initiated_by, status)
                VALUES ('$renewal_date', $de_expense_id, $budgetSql, $premium, '$remarks', $initSql, 'initiated')");
            if ($ok) {
                $new_dep_id = mysqli_insert_id($conn);
                insEpAutoProgressStatus($conn, $new_dep_id);
            }
        }

        $sql = "INSERT INTO vehicle_insurance (vehicle_id, renewal_date, sum_insured, year_range, premium, monthly_rental, document_upload, direct_expense_payment_id)
                VALUES ($vehicle_id, '$renewal_date', " . ($sum_insured !== null ? $sum_insured : "NULL") . ", '$year_range', $premium, $monthly_rental, " .
                ($document_upload ? "'$document_upload'" : "NULL") . ", " . ($new_dep_id ? $new_dep_id : "NULL") . ")";
        if (mysqli_query($conn, $sql)) {
            $success_message = "Insurance record added successfully!";
            if ($de_configured) { $success_message .= " Expense payment created and linked."; }
        }
        else { $error_message = "Error: " . mysqli_error($conn); }
    }
}

$list_sql = "SELECT vi.*, v.vehicle_number,
             DATEDIFF(vi.renewal_date, CURDATE()) days_to_expiry
             FROM vehicle_insurance vi
             LEFT JOIN vehicles v ON vi.vehicle_id = v.id
             ORDER BY vi.renewal_date ASC";
$list_result = mysqli_query($conn, $list_sql);

$vehicles_result = mysqli_query($conn, "SELECT id, vehicle_number FROM vehicles ORDER BY vehicle_number");
$vehicles_list = [];
while ($v = mysqli_fetch_assoc($vehicles_result)) { $vehicles_list[] = $v; }

$year_ranges_result = mysqli_query($conn, "SELECT DISTINCT year_range FROM vehicle_insurance WHERE year_range IS NOT NULL AND year_range != '' ORDER BY year_range DESC");
$year_ranges = [];
while ($yr = mysqli_fetch_assoc($year_ranges_result)) { $year_ranges[] = $yr['year_range']; }

// Always show 5 year-range sets (2 previous, current, 2 upcoming) even if never saved before
$curY = intval(date('Y'));
for ($i = -2; $i <= 2; $i++) {
    $y = $curY + $i;
    $year_ranges[] = $y . '-' . ($y + 1);
}
$year_ranges = array_values(array_unique($year_ranges));
rsort($year_ranges);

function insuranceExpiryBadge($days) {
    if ($days === null) return '<span class="badge badge-neutral">-</span>';
    if ($days < 0) return '<span class="badge badge-danger"><i class="fa-solid fa-circle-exclamation"></i> Expired '.abs($days).'d ago</span>';
    if ($days < 30) return '<span class="badge badge-danger"><i class="fa-solid fa-triangle-exclamation"></i> '.$days.' days left</span>';
    if ($days < 90) return '<span class="badge badge-warning"><i class="fa-solid fa-clock"></i> '.$days.' days left</span>';
    return '<span class="badge badge-success"><i class="fa-solid fa-circle-check"></i> '.$days.' days left</span>';
}

include 'header.php';
?>

<div class="page-header">
    <h2 class="page-title">Vehicle Insurance</h2>
    <p class="page-subtitle">Track insurance renewals, premiums and monthly rentals per lorry</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success_message; ?></div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?></div>
<?php endif; ?>

<div style="margin-bottom: 20px; display:flex; gap:10px; flex-wrap:wrap;">
    <button onclick="openModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Insurance Record</button>
    <button onclick="openDeSettingsModal()" class="btn btn-secondary"><i class="fa-solid fa-gear"></i> Expense Settings</button>
</div>

<!-- Expense Settings Widget -->
<?php if ($de_configured): ?>
<div class="content-card" style="margin-bottom:20px;">
    <h3 class="card-title"><i class="fa-solid fa-file-invoice-dollar"></i> Linked Direct Expense</h3>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <span class="badge badge-info"><i class="fa-solid fa-receipt"></i> Expense: <?php echo htmlspecialchars($de_expense_row['expense_name']); ?></span>
    </div>
    <p style="font-size:12px; color:#666; margin:12px 0 0;"><i class="fa-solid fa-circle-info"></i> Every Insurance entry will create/update a direct Expense Payment against this expense for the Premium amount (same workflow as expense_payments.php: Initiated → Authorized → Approved → Paid).</p>
</div>
<?php else: ?>
<div class="alert" style="background:#fffbeb; color:#92400e; border:1px solid #fde68a;">
    <i class="fa-solid fa-triangle-exclamation"></i> No Direct Expense is linked yet — insurance entries will be saved without an expense payment until you configure one via "Expense Settings".
</div>
<?php endif; ?>

<div class="content-card">
    <h3 class="card-title">Insurance Records (sorted by renewal date)</h3>
    <?php if (mysqli_num_rows($list_result) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Lorry</th>
                    <th>Renewal Date</th>
                    <th>Expiry Status</th>
                    <th>Year Range</th>
                    <th>Sum Insured</th>
                    <th>Premium</th>
                    <th>Monthly Rental</th>
                    <th>Document</th>
                    <th>Expense Link</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = mysqli_fetch_assoc($list_result)): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['vehicle_number'] ?? '-'); ?></strong></td>
                    <td><?php echo date('d-M-Y', strtotime($row['renewal_date'])); ?></td>
                    <td><?php echo insuranceExpiryBadge($row['days_to_expiry']); ?></td>
                    <td><?php echo htmlspecialchars($row['year_range']) ?: '-'; ?></td>
                    <td><?php echo $row['sum_insured'] !== null ? number_format($row['sum_insured'], 2) : '-'; ?></td>
                    <td><?php echo number_format($row['premium'], 2); ?></td>
                    <td><?php echo number_format($row['monthly_rental'], 2); ?></td>
                    <td>
                        <?php if (!empty($row['document_upload'])): ?>
                            <a href="<?php echo htmlspecialchars($row['document_upload']); ?>" target="_blank" class="btn-action btn-view" title="View Document"><i class="fa-solid fa-file"></i></a>
                        <?php else: ?><span style="color:#999;">-</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($row['direct_expense_payment_id'])): ?>
                            <span class="badge badge-info" title="Direct Expense payment #<?php echo (int)$row['direct_expense_payment_id']; ?>"><i class="fa-solid fa-link"></i> Direct Expense</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="#" onclick="editInsurance(<?php echo $row['id']; ?>); return false;" class="btn-action btn-edit" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <a href="?delete=<?php echo $row['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Delete this insurance record? This will also remove its linked Expense payment, if any.')"><i class="fa-solid fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="color: #666; font-size: 13px; text-align: center; padding: 20px;">No insurance records found.</p>
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
                <p style="font-size:13px; color:#666; margin-top:0;">Choose which Expense every Insurance entry's Premium should be recorded against as a direct Expense Payment (saved as the default for this page).</p>
                <div class="form-group">
                    <label class="form-label">Direct Expense <span class="required">*</span></label>
                    <select id="de_direct_expense_id" name="de_direct_expense_id" class="form-input select2-de-direct" required>
                        <option value="">Select Expense</option>
                        <?php foreach ($direct_expenses_list as $de): ?>
                            <option value="<?php echo $de['id']; ?>" <?php echo ($de_expense_id == $de['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($de['expense_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Ordinary expenses (built on expenses.php / expense_payments.php). Creates a regular Expense Payment (Initiated → Authorized → Approved → Paid) per insurance entry.</small>
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
<div id="insuranceModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Insurance Record</h3>
            <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="" id="insuranceForm" enctype="multipart/form-data">
            <input type="hidden" name="insurance_id" id="insurance_id">
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
                        <label class="form-label">Renewal Date <span class="required">*</span></label>
                        <input type="date" id="renewal_date" name="renewal_date" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Year Range</label>
                        <select id="year_range" name="year_range" class="form-input select2-tags">
                            <?php foreach ($year_ranges as $yr): ?>
                                <option value="<?php echo htmlspecialchars($yr); ?>"><?php echo htmlspecialchars($yr); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-hint">Type a new range e.g. 2026-2027 and press Enter to add it.</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Sum Insured</label>
                        <input type="number" step="0.01" id="sum_insured" name="sum_insured" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Premium <span class="required">*</span></label>
                        <input type="number" step="0.01" id="premium" name="premium" class="form-input" required oninput="calcMonthlyRental()">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Monthly Rental (auto-calculated)</label>
                    <input type="text" id="monthly_rental_display" class="form-input" readonly style="background:#fafafa;">
                    <small class="form-hint">Premium ÷ 12</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Document Upload</label>
                    <input type="file" id="document_upload" name="document_upload" class="form-input file-input" accept=".pdf,.jpg,.jpeg,.png">
                    <div id="existingDocWrap" style="display:none; margin-top:8px;">
                        <a href="#" id="existingDocLink" target="_blank" style="font-size:12px;">View current document</a>
                    </div>
                </div>

                <?php if ($de_configured): ?>
                <p style="font-size:12px; color:#666; margin:14px 0 0;"><i class="fa-solid fa-circle-info"></i> Saving this entry will automatically create/update a Direct Expense payment (for the Premium) against "<?php echo htmlspecialchars($de_expense_row['expense_name']); ?>".</p>
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
function calcMonthlyRental() {
    const premium = parseFloat(document.getElementById('premium').value) || 0;
    document.getElementById('monthly_rental_display').value = (premium / 12).toFixed(2);
}

function getDefaultYearRange() {
    const y = new Date().getFullYear();
    return y + '-' + (y + 1);
}

function openModal() {
    document.getElementById('insuranceModal').classList.add('active');
    document.getElementById('insuranceForm').reset();
    document.getElementById('insurance_id').value = '';
    document.getElementById('modalTitle').textContent = 'Add Insurance Record';
    document.getElementById('submitBtnText').textContent = 'Save Record';
    document.getElementById('existingDocWrap').style.display = 'none';
    document.getElementById('monthly_rental_display').value = '';
    if (window.jQuery && $.fn.select2) {
        $('.select2-vehicle').val('').trigger('change');
        $('.select2-tags').val(null).trigger('change');
        // Auto-fill the current year range so it doesn't have to be typed every time
        const defaultYR = getDefaultYearRange();
        if ($('#year_range option[value="' + defaultYR + '"]').length === 0) {
            $('#year_range').append(new Option(defaultYR, defaultYR, true, true));
        }
        $('#year_range').val(defaultYR).trigger('change');
    } else {
        document.getElementById('year_range').value = getDefaultYearRange();
    }
}

function closeModal() {
    document.getElementById('insuranceModal').classList.remove('active');
}

function editInsurance(id) {
    fetch('vehicle_insurance.php?ajax=get&id=' + id)
        .then(response => response.json())
        .then(data => {
            document.getElementById('insuranceModal').classList.add('active');
            document.getElementById('insurance_id').value = data.id;
            document.getElementById('renewal_date').value = data.renewal_date;
            document.getElementById('sum_insured').value = data.sum_insured || '';
            document.getElementById('premium').value = data.premium;
            calcMonthlyRental();

            if (window.jQuery) {
                $('#vehicle_id').val(data.vehicle_id).trigger('change');
                if (data.year_range) {
                    if ($('#year_range option[value="' + data.year_range + '"]').length === 0) {
                        const newOpt = new Option(data.year_range, data.year_range, true, true);
                        $('#year_range').append(newOpt);
                    }
                    $('#year_range').val(data.year_range).trigger('change');
                }
            } else {
                document.getElementById('vehicle_id').value = data.vehicle_id;
                document.getElementById('year_range').value = data.year_range || '';
            }

            if (data.document_upload) {
                document.getElementById('existingDocWrap').style.display = 'block';
                document.getElementById('existingDocLink').href = data.document_upload;
            } else {
                document.getElementById('existingDocWrap').style.display = 'none';
            }

            document.getElementById('modalTitle').textContent = 'Edit Insurance Record';
            document.getElementById('submitBtnText').textContent = 'Update Record';
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading insurance data');
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
        $('.select2-tags').select2({ width: '100%', tags: true, placeholder: 'Select or type a year range' });
        $('.select2-de-direct').select2({ width: '100%', placeholder: 'Select Direct Expense', dropdownParent: $('#deSettingsModal') });
    }
});
</script>

<?php include 'footer.php'; ?>