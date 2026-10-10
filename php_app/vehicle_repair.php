<?php
include 'config.php';
mysqli_report(MYSQLI_REPORT_OFF);

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_repair (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT(11) NOT NULL,
    repair_date DATE NOT NULL,
    km INT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vehicle (vehicle_id),
    INDEX idx_date (repair_date)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_repair_items (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    repair_id INT(11) NOT NULL,
    repair_name VARCHAR(255) NULL,
    spare_part VARCHAR(255) NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    repair_charge DECIMAL(10,2) NOT NULL DEFAULT 0,
    cash_float_payment_id INT(11) NULL,
    INDEX idx_repair (repair_id),
    INDEX idx_cf_payment (cash_float_payment_id)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_repair_master (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    repair_name VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Safety-net migration in case the table already existed before this column was added
function vrAddColumnIfMissing($conn, $table, $col, $definitionSql) {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM $table LIKE '$col'");
    if ($r && mysqli_num_rows($r) === 0) { mysqli_query($conn, "ALTER TABLE $table ADD COLUMN $definitionSql"); }
}
vrAddColumnIfMissing($conn, 'vehicle_repair_items', 'cash_float_payment_id', 'cash_float_payment_id INT(11) NULL');

// ---------- Cash Float integration: shared tables (same schema as cash_float.php / cash_float_add_payment.php) ----------
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cash_floats (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    expense_id        INT NOT NULL,
    expense_name      VARCHAR(200) NOT NULL,
    category_id       INT NULL,
    mybos_account_id  INT NULL,
    budget_id         INT NULL,
    roi_id            INT NULL,
    initiater_ids     TEXT NULL,
    authorizer_ids    TEXT NULL,
    approver_ids      TEXT NULL,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_expense_id (expense_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cash_float_base_expense_payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    payment_date    DATE NOT NULL,
    cash_float_id   INT NOT NULL,
    expense_id      INT NOT NULL,
    budget_id       INT NULL,
    amount          DECIMAL(14,2) NOT NULL DEFAULT 0,
    remarks         VARCHAR(500) NULL,
    initiated_by    INT NULL,
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
    status          ENUM('initiated','authorized','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'initiated',
    auto_authorized TINYINT(1) NOT NULL DEFAULT 0,
    auto_approved   TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payment_date (payment_date),
    INDEX idx_cash_float (cash_float_id),
    INDEX idx_expense (expense_id),
    INDEX idx_budget (budget_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cash_float_base_expense_payment_attachments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    payment_id   INT NOT NULL,
    file_name    VARCHAR(255) NOT NULL,
    file_path    VARCHAR(500) NOT NULL,
    file_size    INT NULL,
    uploaded_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Singleton settings row: which Cash Float repair entries should charge against.
// NOTE: unlike the other vehicle pages, there is no manual "Base Expense" select here —
// each repair line item auto-creates/reuses its own sub-expense (named after the Repair
// Name, scoped to this float via parent_cash_float_id) the moment it's saved.
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_repair_cash_float_settings (
    id              INT(11) NOT NULL PRIMARY KEY DEFAULT 1,
    cash_float_id   INT(11) NULL,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
mysqli_query($conn, "INSERT IGNORE INTO vehicle_repair_cash_float_settings (id, cash_float_id) VALUES (1, NULL)");

// ---------- Cash Float helper functions ----------
function vrJsonIds($str) {
    if (!$str) return [];
    $arr = json_decode($str, true);
    return is_array($arr) ? array_map('intval', $arr) : [];
}

function vrCfFloatRow($conn, $cashFloatId) {
    $cashFloatId = intval($cashFloatId);
    if (!$cashFloatId) return null;
    $fr = mysqli_query($conn, "SELECT * FROM cash_floats WHERE id = $cashFloatId LIMIT 1");
    return ($fr && $row = mysqli_fetch_assoc($fr)) ? $row : null;
}

// Every expense id linked to this float: its own base expense, plus every sub-expense
// created inside it (parent_cash_float_id) — used for the Released calculation.
function vrCfExpenseIdsForFloat($conn, $cashFloatId, $baseExpenseId) {
    $ids = [intval($baseExpenseId)];
    $r = mysqli_query($conn, "SELECT id FROM expenses WHERE parent_cash_float_id = " . intval($cashFloatId));
    if ($r) { while ($row = mysqli_fetch_assoc($r)) { $ids[] = (int)$row['id']; } }
    return array_values(array_unique(array_filter($ids)));
}

function vrCfMainExpenseReleased($conn, $cashFloatId, $baseExpenseId) {
    $tblCheck = mysqli_query($conn, "SHOW TABLES LIKE 'expense_payments'");
    if (!$tblCheck || mysqli_num_rows($tblCheck) === 0) { return ['amount' => 0.0, 'count' => 0]; }
    $ids = vrCfExpenseIdsForFloat($conn, $cashFloatId, $baseExpenseId);
    if (empty($ids)) return ['amount' => 0.0, 'count' => 0];
    $inList = implode(',', $ids);
    $r = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt FROM expense_payments WHERE expense_id IN ($inList) AND status = 'paid'");
    if ($r && $row = mysqli_fetch_assoc($r)) { return ['amount' => (float)$row['total'], 'count' => (int)$row['cnt']]; }
    return ['amount' => 0.0, 'count' => 0];
}

// Balance = Released (main expense_payments tracker, paid) minus Spent
// (cash_float_base_expense_payments, approved+paid) — same formula used by
// cash_float_add_payment.php's capCurrentBalance().
function vrCfCurrentBalance($conn, $cashFloatId, $baseExpenseId) {
    $mainReleased = vrCfMainExpenseReleased($conn, $cashFloatId, $baseExpenseId);
    $released = $mainReleased['amount'];

    $spent = 0.0;
    $sr = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM cash_float_base_expense_payments
                                WHERE cash_float_id = " . intval($cashFloatId) . " AND status IN ('approved','paid')");
    if ($sr && $srow = mysqli_fetch_assoc($sr)) { $spent = (float)$srow['total']; }

    return ['released' => $released, 'spent' => $spent, 'balance' => $released - $spent];
}

// $chainRow MUST be the cash_floats row (chain source of truth, same rule as cash_float_add_payment.php)
function vrCfAutoProgressStatus($conn, $paymentId, $chainRow) {
    $paymentId = intval($paymentId);
    if (!$paymentId || !$chainRow) return null;
    $authorizerIds = vrJsonIds($chainRow['authorizer_ids']);
    $approverIds   = vrJsonIds($chainRow['approver_ids']);
    $status = 'initiated';
    if (empty($authorizerIds)) {
        mysqli_query($conn, "UPDATE cash_float_base_expense_payments SET status='authorized', authorized_by=NULL, authorized_at=NOW(), auto_authorized=1 WHERE id=$paymentId LIMIT 1");
        $status = 'authorized';
    }
    if ($status === 'authorized' && empty($approverIds)) {
        mysqli_query($conn, "UPDATE cash_float_base_expense_payments SET status='approved', approved_by=NULL, approved_at=NOW(), auto_approved=1 WHERE id=$paymentId LIMIT 1");
        $status = 'approved';
    }
    return $status;
}

function vrCurrentUserId() {
    global $current_user;
    if (isset($current_user) && is_array($current_user) && !empty($current_user['id'])) { return intval($current_user['id']); }
    if (!empty($_SESSION['user_id'])) { return intval($_SESSION['user_id']); }
    return 0;
}

function vrVehicleNumber($conn, $vehicleId) {
    $vehicleId = intval($vehicleId);
    if (!$vehicleId) return '#' . $vehicleId;
    $r = mysqli_query($conn, "SELECT vehicle_number FROM vehicles WHERE id = $vehicleId");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return ($row && !empty($row['vehicle_number'])) ? $row['vehicle_number'] : ('#' . $vehicleId);
}

// Finds an existing sub-expense (named after the Repair Name) already created *inside* this
// Cash Float, or auto-creates one (parent_cash_float_id = the float, inherits nothing else).
function vrFindOrCreateSubExpense($conn, $cashFloatId, $repairName) {
    $cashFloatId = intval($cashFloatId);
    $nameEsc = mysqli_real_escape_string($conn, $repairName);
    $r = mysqli_query($conn, "SELECT id FROM expenses WHERE parent_cash_float_id = $cashFloatId AND expense_name = '$nameEsc' LIMIT 1");
    if ($r && $row = mysqli_fetch_assoc($r)) { return (int)$row['id']; }
    mysqli_query($conn, "INSERT INTO expenses (expense_name, enable_float, parent_cash_float_id) VALUES ('$nameEsc', 0, $cashFloatId)");
    return mysqli_insert_id($conn);
}

// Creates/updates the Cash Float payment for one repair line item. Returns the
// cash_float_payment_id to store on the vehicle_repair_items row (or null if the line has
// no name / no amount to charge, or no Cash Float is configured).
function vrSyncItemCashFloatPayment($conn, $cashFloatId, $floatRow, $repairName, $lineTotal, $repairDate, $remarks, $initSql, $existingPaymentId) {
    $repairName = trim($repairName);
    if (!$cashFloatId || !$floatRow || $repairName === '' || $lineTotal <= 0) {
        if ($existingPaymentId) {
            mysqli_query($conn, "DELETE FROM cash_float_base_expense_payment_attachments WHERE payment_id = " . intval($existingPaymentId));
            mysqli_query($conn, "DELETE FROM cash_float_base_expense_payments WHERE id = " . intval($existingPaymentId));
        }
        return null;
    }

    $expense_id = vrFindOrCreateSubExpense($conn, $cashFloatId, $repairName);
    $budget_id = $floatRow['budget_id'] ? intval($floatRow['budget_id']) : 0;
    $budgetSql = $budget_id ? $budget_id : 'NULL';

    if ($existingPaymentId) {
        mysqli_query($conn, "UPDATE cash_float_base_expense_payments SET
            payment_date = '$repairDate', cash_float_id = $cashFloatId, expense_id = $expense_id,
            budget_id = $budgetSql, amount = $lineTotal, remarks = '$remarks'
            WHERE id = " . intval($existingPaymentId));
        return intval($existingPaymentId);
    }

    mysqli_query($conn, "INSERT INTO cash_float_base_expense_payments (payment_date, cash_float_id, expense_id, budget_id, amount, remarks, initiated_by, status)
        VALUES ('$repairDate', $cashFloatId, $expense_id, $budgetSql, $lineTotal, '$remarks', $initSql, 'initiated')");
    $newId = mysqli_insert_id($conn);
    vrCfAutoProgressStatus($conn, $newId, $floatRow);
    return $newId;
}

// Seed default repair names once (INSERT IGNORE makes repeat runs harmless)
$default_repair_names = [
    'Engine Repair', 'Gearbox Repair', 'Clutch Repair', 'Brake Repair', 'Suspension Repair',
    'Differential Repair', 'Propeller Shaft Repair', 'Electrical Repair', 'Battery Replacement',
    'AC Repair', 'Radiator Repair', 'Fuel System Repair', 'Fuel Pump Repair', 'Steering Repair',
    'Exhaust Repair', 'Chassis Repair', 'Body Repair', 'Cabin Repair', 'Hydraulic Repair',
    'Wheel Alignment', 'Bearing Replacement', 'Turbo Repair', 'Other',
];
foreach ($default_repair_names as $drn) {
    $drn_esc = mysqli_real_escape_string($conn, $drn);
    mysqli_query($conn, "INSERT IGNORE INTO vehicle_repair_master (repair_name) VALUES ('$drn_esc')");
}

// ---------- Repair Master management ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_repair_master') {
    $rn = mysqli_real_escape_string($conn, trim($_POST['repair_name']));
    if ($rn !== '') {
        if (mysqli_query($conn, "INSERT IGNORE INTO vehicle_repair_master (repair_name) VALUES ('$rn')")) {
            $success_message = "Repair master item added successfully!";
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    }
}

if (isset($_GET['delete_master'])) {
    $mid = intval($_GET['delete_master']);
    if (mysqli_query($conn, "DELETE FROM vehicle_repair_master WHERE id = $mid")) {
        $success_message = "Repair master item removed successfully!";
    } else {
        $error_message = "Error deleting master item: " . mysqli_error($conn);
    }
}

$REPAIR_NAME_OPTIONS = [];
$rnm_result = mysqli_query($conn, "SELECT id, repair_name FROM vehicle_repair_master ORDER BY repair_name ASC");
$repair_master_list = [];
while ($rnm = mysqli_fetch_assoc($rnm_result)) {
    $REPAIR_NAME_OPTIONS[] = $rnm['repair_name'];
    $repair_master_list[] = $rnm;
}

// ---------- Save Cash Float Settings (float only — no base expense picker) ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_cf_settings') {
    $set_cf_id = intval($_POST['cf_cash_float_id'] ?? 0);
    if (!$set_cf_id) {
        $error_message = "Please select a Cash Float.";
    } else {
        mysqli_query($conn, "INSERT INTO vehicle_repair_cash_float_settings (id, cash_float_id) VALUES (1, $set_cf_id)
                              ON DUPLICATE KEY UPDATE cash_float_id = $set_cf_id");
        $success_message = "Cash Float settings saved successfully!";
    }
}

// ---------- Load current Cash Float settings + balance ----------
$cf_settings_res = mysqli_query($conn, "SELECT * FROM vehicle_repair_cash_float_settings WHERE id = 1");
$cf_settings = mysqli_fetch_assoc($cf_settings_res) ?: ['cash_float_id' => null];
$cf_cash_float_id = intval($cf_settings['cash_float_id'] ?? 0);

$cf_configured = false;
$cf_float_row = null;
$cf_balance = ['released' => 0, 'spent' => 0, 'balance' => 0];

if ($cf_cash_float_id) {
    $cf_float_row = vrCfFloatRow($conn, $cf_cash_float_id);
    if ($cf_float_row) {
        $cf_configured = true;
        $cf_balance = vrCfCurrentBalance($conn, $cf_cash_float_id, $cf_float_row['expense_id']);
    }
}

$cash_floats_list = [];
$cfl_res = mysqli_query($conn, "SELECT id, expense_name FROM cash_floats ORDER BY expense_name ASC");
while ($cfl = mysqli_fetch_assoc($cfl_res)) { $cash_floats_list[] = $cfl; }

try {
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'get' && isset($_GET['id'])) {
        header('Content-Type: application/json');
        $id = intval($_GET['id']);
        $res = mysqli_query($conn, "SELECT * FROM vehicle_repair WHERE id = $id");
        $data = mysqli_fetch_assoc($res);
        if ($data) {
            $items_res = mysqli_query($conn, "SELECT * FROM vehicle_repair_items WHERE repair_id = $id");
            $items = [];
            while ($it = mysqli_fetch_assoc($items_res)) { $items[] = $it; }
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

// ---------- Delete ----------
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $items_res = mysqli_query($conn, "SELECT cash_float_payment_id FROM vehicle_repair_items WHERE repair_id = $id");
    $linked_payment_ids = [];
    while ($it = mysqli_fetch_assoc($items_res)) {
        if (!empty($it['cash_float_payment_id'])) { $linked_payment_ids[] = intval($it['cash_float_payment_id']); }
    }
    mysqli_query($conn, "DELETE FROM vehicle_repair_items WHERE repair_id = $id");
    if (mysqli_query($conn, "DELETE FROM vehicle_repair WHERE id = $id")) {
        // Delete every line item's linked Cash Float payment together with the repair record
        foreach ($linked_payment_ids as $pid) {
            mysqli_query($conn, "DELETE FROM cash_float_base_expense_payment_attachments WHERE payment_id = $pid");
            mysqli_query($conn, "DELETE FROM cash_float_base_expense_payments WHERE id = $pid");
        }
        $success_message = "Repair record deleted successfully!";
    } else {
        $error_message = "Error deleting record: " . mysqli_error($conn);
    }
}

// ---------- Save (Create / Update) ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['action'])) {
    $vehicle_id  = intval($_POST['vehicle_id']);
    $repair_date = mysqli_real_escape_string($conn, $_POST['repair_date']);
    $km          = ($_POST['km'] !== '') ? intval($_POST['km']) : null;

    $repair_names = $_POST['repair_name'] ?? [];
    $spare_parts  = $_POST['spare_part'] ?? [];
    $amounts      = $_POST['item_amount'] ?? [];
    $charges      = $_POST['repair_charge'] ?? [];

    $line_items = [];
    $total_amount = 0;
    foreach ($repair_names as $i => $name) {
        $name = trim($name);
        $part = trim($spare_parts[$i] ?? '');
        $amt  = floatval($amounts[$i] ?? 0);
        $chg  = floatval($charges[$i] ?? 0);
        if ($name === '' && $part === '' && $amt == 0 && $chg == 0) continue;
        $line_items[] = ['repair_name' => $name, 'spare_part' => $part, 'amount' => $amt, 'repair_charge' => $chg];
        $total_amount += $amt + $chg;
        if ($name !== '') {
            $name_esc = mysqli_real_escape_string($conn, $name);
            mysqli_query($conn, "INSERT IGNORE INTO vehicle_repair_master (repair_name) VALUES ('$name_esc')");
        }
    }

    $uidv = vrCurrentUserId();
    $initSql = $uidv ? $uidv : 'NULL';
    $cf_error = null;
    $is_edit = isset($_POST['repair_id']) && !empty($_POST['repair_id']);

    // Balance check up front — total of ALL line items together must fit the float's balance.
    if ($cf_configured) {
        $balCheck = vrCfCurrentBalance($conn, $cf_cash_float_id, $cf_float_row['expense_id']);
        $availableForThis = $balCheck['balance'];
        if ($is_edit) {
            $id_for_check = intval($_POST['repair_id']);
            $old_items_res = mysqli_query($conn, "SELECT cash_float_payment_id FROM vehicle_repair_items WHERE repair_id = $id_for_check");
            while ($oi = mysqli_fetch_assoc($old_items_res)) {
                if (!empty($oi['cash_float_payment_id'])) {
                    $pr = mysqli_query($conn, "SELECT amount, status FROM cash_float_base_expense_payments WHERE id = " . intval($oi['cash_float_payment_id']));
                    $prow = mysqli_fetch_assoc($pr);
                    if ($prow && in_array($prow['status'], ['approved','paid'], true)) {
                        $availableForThis += (float)$prow['amount'];
                    }
                }
            }
        }
        if ($total_amount > $availableForThis) {
            $cf_error = 'This total amount of Rs. ' . number_format($total_amount, 2) . ' exceeds the available Cash Float Balance of Rs. ' . number_format($availableForThis, 2) . '.';
        }
    }

    if ($cf_error) {
        $error_message = $cf_error;
    } elseif ($is_edit) {
        $id = intval($_POST['repair_id']);

        $sql = "UPDATE vehicle_repair SET
                vehicle_id = $vehicle_id, repair_date = '$repair_date',
                km = " . ($km !== null ? $km : "NULL") . ", total_amount = $total_amount
                WHERE id = $id";
        if (mysqli_query($conn, $sql)) {
            // Clean up every old line item's linked Cash Float payment before replacing the items
            $old_items_res = mysqli_query($conn, "SELECT cash_float_payment_id FROM vehicle_repair_items WHERE repair_id = $id");
            while ($oi = mysqli_fetch_assoc($old_items_res)) {
                if (!empty($oi['cash_float_payment_id'])) {
                    $opid = intval($oi['cash_float_payment_id']);
                    mysqli_query($conn, "DELETE FROM cash_float_base_expense_payment_attachments WHERE payment_id = $opid");
                    mysqli_query($conn, "DELETE FROM cash_float_base_expense_payments WHERE id = $opid");
                }
            }
            mysqli_query($conn, "DELETE FROM vehicle_repair_items WHERE repair_id = $id");

            foreach ($line_items as $li) {
                $n = mysqli_real_escape_string($conn, $li['repair_name']);
                $p = mysqli_real_escape_string($conn, $li['spare_part']);
                $lineTotal = $li['amount'] + $li['repair_charge'];
                $remarks = mysqli_real_escape_string($conn, 'Vehicle Repair — Lorry ' . vrVehicleNumber($conn, $vehicle_id) . ' — ' . $li['repair_name'] . ' on ' . $repair_date);
                $cfpId = vrSyncItemCashFloatPayment($conn, $cf_cash_float_id, $cf_float_row, $li['repair_name'], $lineTotal, $repair_date, $remarks, $initSql, null);
                $cfpSql = $cfpId ? $cfpId : "NULL";
                mysqli_query($conn, "INSERT INTO vehicle_repair_items (repair_id, repair_name, spare_part, amount, repair_charge, cash_float_payment_id) VALUES ($id, '$n', '$p', {$li['amount']}, {$li['repair_charge']}, $cfpSql)");
            }
            $success_message = "Repair record updated successfully!";
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    } else {
        $sql = "INSERT INTO vehicle_repair (vehicle_id, repair_date, km, total_amount)
                VALUES ($vehicle_id, '$repair_date', " . ($km !== null ? $km : "NULL") . ", $total_amount)";
        if (mysqli_query($conn, $sql)) {
            $id = mysqli_insert_id($conn);
            foreach ($line_items as $li) {
                $n = mysqli_real_escape_string($conn, $li['repair_name']);
                $p = mysqli_real_escape_string($conn, $li['spare_part']);
                $lineTotal = $li['amount'] + $li['repair_charge'];
                $remarks = mysqli_real_escape_string($conn, 'Vehicle Repair — Lorry ' . vrVehicleNumber($conn, $vehicle_id) . ' — ' . $li['repair_name'] . ' on ' . $repair_date);
                $cfpId = vrSyncItemCashFloatPayment($conn, $cf_cash_float_id, $cf_float_row, $li['repair_name'], $lineTotal, $repair_date, $remarks, $initSql, null);
                $cfpSql = $cfpId ? $cfpId : "NULL";
                mysqli_query($conn, "INSERT INTO vehicle_repair_items (repair_id, repair_name, spare_part, amount, repair_charge, cash_float_payment_id) VALUES ($id, '$n', '$p', {$li['amount']}, {$li['repair_charge']}, $cfpSql)");
            }
            $success_message = "Repair record added successfully!";
            if ($cf_configured) { $success_message .= " Cash Float sub-expense &amp; payment created per repair name."; }
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    }

    // Refresh balance snapshot after any save so the widget below reflects the latest numbers
    if ($cf_configured) {
        $cf_balance = vrCfCurrentBalance($conn, $cf_cash_float_id, $cf_float_row['expense_id']);
    }
}

$list_sql = "SELECT r.*, v.vehicle_number FROM vehicle_repair r
             LEFT JOIN vehicles v ON r.vehicle_id = v.id
             ORDER BY r.repair_date DESC, r.id DESC";
$list_result = mysqli_query($conn, $list_sql);

$rows = [];
while ($row = mysqli_fetch_assoc($list_result)) {
    $items_res = mysqli_query($conn, "SELECT * FROM vehicle_repair_items WHERE repair_id = " . $row['id']);
    $items = [];
    while ($it = mysqli_fetch_assoc($items_res)) { $items[] = $it; }
    $row['items'] = $items;
    $rows[] = $row;
}

$vehicles_result = mysqli_query($conn, "SELECT id, vehicle_number FROM vehicles ORDER BY vehicle_number");
$vehicles_list = [];
while ($v = mysqli_fetch_assoc($vehicles_result)) { $vehicles_list[] = $v; }

include 'header.php';
?>

<div class="page-header">
    <h2 class="page-title">Vehicle Repair</h2>
    <p class="page-subtitle">Log repair jobs with multiple spare part / charge line items per lorry</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success_message; ?></div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?></div>
<?php endif; ?>

<div style="margin-bottom: 20px; display:flex; gap:10px; flex-wrap:wrap;">
    <button onclick="openModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Repair Record</button>
    <button onclick="openMasterModal()" class="btn btn-secondary"><i class="fa-solid fa-list-check"></i> Repair Master List</button>
    <button onclick="openCfSettingsModal()" class="btn btn-secondary"><i class="fa-solid fa-gear"></i> Cash Float Settings</button>
</div>

<!-- Cash Float Balance Widget -->
<?php if ($cf_configured): ?>
<div class="content-card" style="margin-bottom:20px;">
    <h3 class="card-title"><i class="fa-solid fa-money-bill-transfer"></i> Linked Cash Float</h3>
    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px;">
        <span class="badge badge-info"><i class="fa-solid fa-wallet"></i> Float: <?php echo htmlspecialchars($cf_float_row['expense_name']); ?></span>
        <span class="badge badge-neutral"><i class="fa-solid fa-circle-info"></i> Base expense per repair name is created automatically — no manual selection needed.</span>
    </div>
    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px;">
        <div class="balance-bar ok" style="margin:0;"><span>Released</span><span>Rs. <?php echo number_format($cf_balance['released'], 2); ?></span></div>
        <div class="balance-bar" style="margin:0;"><span>Spent</span><span>Rs. <?php echo number_format($cf_balance['spent'], 2); ?></span></div>
        <div class="balance-bar <?php echo $cf_balance['balance'] >= 0 ? 'ok' : 'bad'; ?>" style="margin:0;"><span>Balance</span><span>Rs. <?php echo number_format($cf_balance['balance'], 2); ?></span></div>
    </div>
</div>
<?php else: ?>
<div class="alert" style="background:#fffbeb; color:#92400e; border:1px solid #fde68a;">
    <i class="fa-solid fa-triangle-exclamation"></i> No Cash Float is linked yet — repair entries will be saved without a Cash Float payment until you configure one via "Cash Float Settings".
</div>
<?php endif; ?>

<div class="content-card">
    <h3 class="card-title">Repair Records</h3>
    <?php if (count($rows) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th></th>
                    <th>Date</th>
                    <th>Lorry</th>
                    <th>KM</th>
                    <th># Items</th>
                    <th>Total Amount</th>
                    <th>Cash Float</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <button type="button" class="btn-action" onclick="toggleExpand(<?php echo $row['id']; ?>)" title="Expand"><i class="fa-solid fa-chevron-down"></i></button>
                    </td>
                    <td><?php echo date('d-M-Y', strtotime($row['repair_date'])); ?></td>
                    <td><strong><?php echo htmlspecialchars($row['vehicle_number'] ?? '-'); ?></strong></td>
                    <td><?php echo $row['km'] !== null ? number_format($row['km']) : '-'; ?></td>
                    <td><?php echo count($row['items']); ?></td>
                    <td><strong><?php echo number_format($row['total_amount'], 2); ?></strong></td>
                    <td>
                        <?php
                        $linkedCount = 0;
                        foreach ($row['items'] as $it) { if (!empty($it['cash_float_payment_id'])) $linkedCount++; }
                        ?>
                        <?php if ($linkedCount > 0): ?>
                            <span class="badge badge-success" title="<?php echo $linkedCount; ?> line item(s) linked"><i class="fa-solid fa-link"></i> Linked (<?php echo $linkedCount; ?>)</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="#" onclick="editRepair(<?php echo $row['id']; ?>); return false;" class="btn-action btn-edit" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <a href="?delete=<?php echo $row['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Delete this repair record? This will also remove every line item\'s linked Cash Float payment.')"><i class="fa-solid fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <tr id="expand-<?php echo $row['id']; ?>" style="display:none;">
                    <td colspan="8" style="background:#fafafa;">
                        <table class="data-table" style="margin:0;">
                            <thead><tr><th>Repair Name</th><th>Spare Part</th><th>Amount</th><th>Repair Charge</th><th>Cash Float Payment</th></tr></thead>
                            <tbody>
                                <?php foreach ($row['items'] as $it): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($it['repair_name']) ?: '-'; ?></td>
                                    <td><?php echo htmlspecialchars($it['spare_part']) ?: '-'; ?></td>
                                    <td><?php echo number_format($it['amount'], 2); ?></td>
                                    <td><?php echo number_format($it['repair_charge'], 2); ?></td>
                                    <td>
                                        <?php if (!empty($it['cash_float_payment_id'])): ?>
                                            <span class="badge badge-success" title="Cash Float payment #<?php echo (int)$it['cash_float_payment_id']; ?>"><i class="fa-solid fa-link"></i> Linked</span>
                                        <?php else: ?>
                                            <span class="badge badge-neutral">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($row['items'])): ?>
                                <tr><td colspan="5" style="text-align:center; color:#999;">No line items.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="color: #666; font-size: 13px; text-align: center; padding: 20px;">No repair records found.</p>
    <?php endif; ?>
</div>

<!-- Cash Float Settings Modal -->
<div id="cfSettingsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Cash Float Settings</h3>
            <button class="modal-close" onclick="closeCfSettingsModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="" id="cfSettingsForm">
            <input type="hidden" name="action" value="save_cf_settings">
            <div class="modal-body">
                <p style="font-size:13px; color:#666; margin-top:0;">Choose which Cash Float every Repair entry should draw from. You don't need to pick a Base Expense — each repair line item automatically creates/reuses its own sub-expense (named after the Repair Name) inside this float.</p>
                <div class="form-group">
                    <label class="form-label">Cash Float <span class="required">*</span></label>
                    <select id="cf_cash_float_id" name="cf_cash_float_id" class="form-input select2-cf-float" required>
                        <option value="">Select Cash Float</option>
                        <?php foreach ($cash_floats_list as $cfl): ?>
                            <option value="<?php echo $cfl['id']; ?>" <?php echo ($cf_cash_float_id == $cfl['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cfl['expense_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCfSettingsModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save Settings</button>
            </div>
        </form>
    </div>
</div>

<!-- Repair Master List Modal -->
<div id="masterModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Repair Master List</h3>
            <button class="modal-close" onclick="closeMasterModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" action="" style="display:flex; gap:10px; align-items:flex-end; margin-bottom:20px;">
                <input type="hidden" name="action" value="add_repair_master">
                <div class="form-group" style="margin-bottom:0; flex:1;">
                    <label class="form-label">New Repair Name</label>
                    <input type="text" name="repair_name" class="form-input" placeholder="e.g. Wheel Bearing Repair" required>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add</button>
            </form>
            <div class="table-responsive">
                <table class="data-table">
                    <thead><tr><th>Repair Name</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($repair_master_list as $rm): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($rm['repair_name']); ?></td>
                            <td>
                                <a href="?delete_master=<?php echo $rm['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Remove this repair name from the master list?')"><i class="fa-solid fa-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($repair_master_list)): ?>
                        <tr><td colspan="2" style="text-align:center; color:#999;">No repair names yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeMasterModal()">Close</button>
        </div>
    </div>
</div>

<!-- Modal -->
<div id="repairModal" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Repair Record</h3>
            <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="" id="repairForm">
            <input type="hidden" name="repair_id" id="repair_id">
            <div class="modal-body">
                <?php if ($cf_configured): ?>
                <div class="balance-bar <?php echo $cf_balance['balance'] >= 0 ? 'ok' : 'bad'; ?>">
                    <span><i class="fa-solid fa-wallet"></i> Cash Float Balance</span>
                    <span>Rs. <?php echo number_format($cf_balance['balance'], 2); ?></span>
                </div>
                <?php endif; ?>
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
                        <label class="form-label">Repair Date <span class="required">*</span></label>
                        <input type="date" id="repair_date" name="repair_date" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">KM</label>
                        <input type="number" id="km" name="km" class="form-input">
                    </div>
                </div>

                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-screwdriver-wrench"></i> Repair Line Items</h4>
                    <div id="repairRows"></div>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="addRepairRow()"><i class="fa-solid fa-plus"></i> Add Line Item</button>
                    <small class="form-hint" style="display:block; margin-top:10px;">Each named line item automatically creates/updates its own Cash Float sub-expense + payment on save.</small>
                </div>

                <div class="balance-bar ok" id="totalBar">
                    <span>Total Amount</span>
                    <span id="totalDisplay">0.00</span>
                </div>

                <?php if ($cf_configured): ?>
                <p style="font-size:12px; color:#666; margin:14px 0 0;"><i class="fa-solid fa-circle-info"></i> Saving this entry will automatically create/update one Cash Float payment per named line item against "<?php echo htmlspecialchars($cf_float_row['expense_name']); ?>" (sub-expense auto-created per repair name).</p>
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
const REPAIR_NAME_OPTIONS = <?php echo json_encode($REPAIR_NAME_OPTIONS); ?>;

function toggleExpand(id) {
    const row = document.getElementById('expand-' + id);
    row.style.display = (row.style.display === 'none') ? 'table-row' : 'none';
}

function openMasterModal() {
    document.getElementById('masterModal').classList.add('active');
}

function closeMasterModal() {
    document.getElementById('masterModal').classList.remove('active');
}

function openCfSettingsModal() {
    document.getElementById('cfSettingsModal').classList.add('active');
}

function closeCfSettingsModal() {
    document.getElementById('cfSettingsModal').classList.remove('active');
}

function addRepairRow(name, part, amount, charge) {
    name = name || ''; part = part || ''; amount = amount !== undefined ? amount : ''; charge = charge !== undefined ? charge : '';
    const wrap = document.getElementById('repairRows');
    const row = document.createElement('div');
    row.className = 'repeat-row';

    let optionsHtml = '<option value=""></option>';
    REPAIR_NAME_OPTIONS.forEach(opt => {
        const selected = (opt === name) ? 'selected' : '';
        optionsHtml += `<option value="${opt}" ${selected}>${opt}</option>`;
    });

    row.innerHTML = `
        <div class="form-group" style="margin-bottom:0;">
            <select name="repair_name[]" class="form-input select2-repair-name">${optionsHtml}</select>
        </div>
        <div class="form-group" style="margin-bottom:0;"><input type="text" name="spare_part[]" class="form-input" placeholder="Spare Part" value="${part.replace(/"/g,'&quot;')}"></div>
        <div class="form-group" style="margin-bottom:0;"><input type="number" step="0.01" name="item_amount[]" class="form-input calc-total" placeholder="Amount" value="${amount}"></div>
        <div class="form-group" style="margin-bottom:0;"><input type="number" step="0.01" name="repair_charge[]" class="form-input calc-total" placeholder="Repair Charge" value="${charge}"></div>
        <button type="button" class="repeat-remove" onclick="this.closest('.repeat-row').remove(); recalcTotal();"><i class="fa-solid fa-trash"></i></button>
    `;
    wrap.appendChild(row);
    row.querySelectorAll('.calc-total').forEach(inp => inp.addEventListener('input', recalcTotal));

    const selectEl = row.querySelector('.select2-repair-name');
    if (window.jQuery && $.fn.select2) {
        $(selectEl).select2({ width: '100%', tags: true, placeholder: 'Select or type repair name' });
        if (name && $(selectEl).find('option[value="' + name + '"]').length === 0) {
            $(selectEl).append(new Option(name, name, true, true));
            $(selectEl).val(name).trigger('change');
        }
    } else {
        selectEl.value = name;
    }
}

function recalcTotal() {
    let total = 0;
    document.querySelectorAll('.calc-total').forEach(inp => { total += parseFloat(inp.value) || 0; });
    document.getElementById('totalDisplay').textContent = total.toFixed(2);
}

function openModal() {
    document.getElementById('repairModal').classList.add('active');
    document.getElementById('repairForm').reset();
    document.getElementById('repair_id').value = '';
    document.getElementById('repairRows').innerHTML = '';
    addRepairRow();
    document.getElementById('modalTitle').textContent = 'Add Repair Record';
    document.getElementById('submitBtnText').textContent = 'Save Record';
    if (window.jQuery) { $('.select2-vehicle').val('').trigger('change'); }
    recalcTotal();
}

function closeModal() {
    document.getElementById('repairModal').classList.remove('active');
}

function editRepair(id) {
    fetch('vehicle_repair.php?ajax=get&id=' + id)
        .then(response => response.json())
        .then(data => {
            document.getElementById('repairModal').classList.add('active');
            document.getElementById('repair_id').value = data.id;
            document.getElementById('repair_date').value = data.repair_date;
            document.getElementById('km').value = data.km || '';

            if (window.jQuery) { $('#vehicle_id').val(data.vehicle_id).trigger('change'); }
            else { document.getElementById('vehicle_id').value = data.vehicle_id; }

            document.getElementById('repairRows').innerHTML = '';
            (data.items || []).forEach(it => addRepairRow(it.repair_name, it.spare_part, it.amount, it.repair_charge));
            if ((data.items || []).length === 0) addRepairRow();

            recalcTotal();
            document.getElementById('modalTitle').textContent = 'Edit Repair Record';
            document.getElementById('submitBtnText').textContent = 'Update Record';
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading repair record data');
        });
}

document.addEventListener('DOMContentLoaded', function() {
    if (window.jQuery && $.fn.select2) {
        $('.select2-vehicle').select2({ width: '100%', placeholder: 'Select Lorry' });
        $('.select2-cf-float').select2({ width: '100%', placeholder: 'Select Cash Float', dropdownParent: $('#cfSettingsModal') });
    }
});
</script>

<?php include 'footer.php'; ?>