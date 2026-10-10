<?php
include 'config.php';
mysqli_report(MYSQLI_REPORT_OFF);

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_tyres (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT(11) NOT NULL,
    tyre_date DATE NOT NULL,
    km INT NULL,
    invoice_no VARCHAR(100) NULL,
    attachment VARCHAR(255) NULL,
    service_charge DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    direct_expense_payment_id INT(11) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vehicle (vehicle_id),
    INDEX idx_date (tyre_date)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_tyre_items (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    tyre_id INT(11) NOT NULL,
    position ENUM('F','RL','RR') NOT NULL,
    item_condition ENUM('new','new_stock_in','new_stock_out','dag') NOT NULL DEFAULT 'new',
    qty INT NOT NULL DEFAULT 0,
    unit_price DECIMAL(10,2) NULL,
    line_total DECIMAL(10,2) NULL,
    dag_flag TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_tyre (tyre_id)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_tyre_other_charges (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    tyre_id INT(11) NOT NULL,
    description VARCHAR(255) NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    INDEX idx_tyre (tyre_id)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_tyre_sizes (
    vehicle_id INT(11) NOT NULL PRIMARY KEY,
    tyre_size VARCHAR(50) NULL
)");

// Safety-net migrations in case these tables already existed (from an earlier version of this
// page) before the columns below were introduced — CREATE TABLE IF NOT EXISTS alone won't add
// columns to a table that already exists.
function tyAddColumnIfMissing($conn, $table, $col, $definitionSql) {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM $table LIKE '$col'");
    if ($r && mysqli_num_rows($r) === 0) { mysqli_query($conn, "ALTER TABLE $table ADD COLUMN $definitionSql"); }
}
tyAddColumnIfMissing($conn, 'vehicle_tyres', 'attachment', 'attachment VARCHAR(255) NULL');
tyAddColumnIfMissing($conn, 'vehicle_tyres', 'service_charge', 'service_charge DECIMAL(10,2) NOT NULL DEFAULT 0');
tyAddColumnIfMissing($conn, 'vehicle_tyres', 'total_amount', 'total_amount DECIMAL(10,2) NOT NULL DEFAULT 0');
tyAddColumnIfMissing($conn, 'vehicle_tyres', 'direct_expense_payment_id', 'direct_expense_payment_id INT(11) NULL');
tyAddColumnIfMissing($conn, 'vehicle_tyre_items', 'item_condition', "item_condition ENUM('new','new_stock_in','new_stock_out','dag') NOT NULL DEFAULT 'new'");
tyAddColumnIfMissing($conn, 'vehicle_tyre_items', 'unit_price', 'unit_price DECIMAL(10,2) NULL');
tyAddColumnIfMissing($conn, 'vehicle_tyre_items', 'line_total', 'line_total DECIMAL(10,2) NULL');
tyAddColumnIfMissing($conn, 'vehicle_tyre_items', 'dag_flag', 'dag_flag TINYINT(1) NOT NULL DEFAULT 0');
// Widen the enum in case it already existed with the old 'new'/'new_return'/'dag' values
mysqli_query($conn, "ALTER TABLE vehicle_tyre_items MODIFY COLUMN item_condition ENUM('new','new_stock_in','new_stock_out','dag') NOT NULL DEFAULT 'new'");
mysqli_query($conn, "UPDATE vehicle_tyre_items SET item_condition = 'new_stock_in' WHERE item_condition = 'new_return'");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS tyre_dag_stock (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    tyre_size VARCHAR(50) NOT NULL UNIQUE,
    available_qty INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS tyre_new_stock (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    tyre_size VARCHAR(50) NOT NULL UNIQUE,
    available_qty INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$upload_dir = 'uploads/vehicle_tyres/';
if (!file_exists($upload_dir)) { mkdir($upload_dir, 0777, true); }

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

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_tyres_expense_settings (
    id                  INT(11) NOT NULL PRIMARY KEY DEFAULT 1,
    direct_expense_id   INT(11) NULL,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
mysqli_query($conn, "INSERT IGNORE INTO vehicle_tyres_expense_settings (id, direct_expense_id) VALUES (1, NULL)");

function tyDirectExpensesList($conn) {
    $list = [];
    $r = mysqli_query($conn, "SELECT id, expense_name, budget_id FROM expenses WHERE enable_float = 0 AND parent_cash_float_id IS NULL ORDER BY expense_name ASC");
    if ($r) { while ($row = mysqli_fetch_assoc($r)) { $list[] = $row; } }
    return $list;
}

function tyJsonIds($str) {
    if (!$str) return [];
    $arr = json_decode($str, true);
    return is_array($arr) ? array_map('intval', $arr) : [];
}

function tyEpAutoProgressStatus($conn, $paymentId) {
    $paymentId = intval($paymentId);
    if (!$paymentId) return null;
    $r = mysqli_query($conn, "SELECT ep.status, e.authorizer_ids, e.approver_ids FROM expense_payments ep LEFT JOIN expenses e ON e.id = ep.expense_id WHERE ep.id = $paymentId LIMIT 1");
    if (!$r || mysqli_num_rows($r) === 0) return null;
    $row = mysqli_fetch_assoc($r);
    $authorizerIds = tyJsonIds($row['authorizer_ids']);
    $approverIds   = tyJsonIds($row['approver_ids']);
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

function tyCurrentUserId() {
    global $current_user;
    if (isset($current_user) && is_array($current_user) && !empty($current_user['id'])) { return intval($current_user['id']); }
    if (!empty($_SESSION['user_id'])) { return intval($_SESSION['user_id']); }
    return 0;
}

function tyVehicleNumber($conn, $vehicleId) {
    $vehicleId = intval($vehicleId);
    if (!$vehicleId) return '#' . $vehicleId;
    $r = mysqli_query($conn, "SELECT vehicle_number FROM vehicles WHERE id = $vehicleId");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return ($row && !empty($row['vehicle_number'])) ? $row['vehicle_number'] : ('#' . $vehicleId);
}

function tyVehicleTyreSize($conn, $vehicleId) {
    $vehicleId = intval($vehicleId);
    $r = mysqli_query($conn, "SELECT tyre_size FROM vehicle_tyre_sizes WHERE vehicle_id = $vehicleId");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return ($row && !empty($row['tyre_size'])) ? $row['tyre_size'] : null;
}

function tyDagAvailable($conn, $tyreSize) {
    if (!$tyreSize) return 0;
    $esc = mysqli_real_escape_string($conn, $tyreSize);
    $r = mysqli_query($conn, "SELECT available_qty FROM tyre_dag_stock WHERE tyre_size = '$esc'");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return $row ? intval($row['available_qty']) : 0;
}

function tyDagAdjust($conn, $tyreSize, $delta) {
    if (!$tyreSize || !$delta) return;
    $esc = mysqli_real_escape_string($conn, $tyreSize);
    mysqli_query($conn, "INSERT INTO tyre_dag_stock (tyre_size, available_qty) VALUES ('$esc', 0) ON DUPLICATE KEY UPDATE tyre_size = tyre_size");
    mysqli_query($conn, "UPDATE tyre_dag_stock SET available_qty = available_qty + ($delta) WHERE tyre_size = '$esc'");
}

function tyNewStockAvailable($conn, $tyreSize) {
    if (!$tyreSize) return 0;
    $esc = mysqli_real_escape_string($conn, $tyreSize);
    $r = mysqli_query($conn, "SELECT available_qty FROM tyre_new_stock WHERE tyre_size = '$esc'");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return $row ? intval($row['available_qty']) : 0;
}

function tyNewStockAdjust($conn, $tyreSize, $delta) {
    if (!$tyreSize || !$delta) return;
    $esc = mysqli_real_escape_string($conn, $tyreSize);
    mysqli_query($conn, "INSERT INTO tyre_new_stock (tyre_size, available_qty) VALUES ('$esc', 0) ON DUPLICATE KEY UPDATE tyre_size = tyre_size");
    mysqli_query($conn, "UPDATE tyre_new_stock SET available_qty = available_qty + ($delta) WHERE tyre_size = '$esc'");
}

// ---------- AJAX: available Dag + New Stock qty for a vehicle (by its configured tyre size) ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'dag_available' && isset($_GET['vehicle_id'])) {
    header('Content-Type: application/json');
    $vehicle_id = intval($_GET['vehicle_id']);
    $size = tyVehicleTyreSize($conn, $vehicle_id);
    echo json_encode([
        'tyre_size' => $size,
        'available' => tyDagAvailable($conn, $size),
        'new_stock_available' => tyNewStockAvailable($conn, $size),
    ]);
    exit;
}

// ---------- AJAX: get single record for edit ----------
try {
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'get' && isset($_GET['id'])) {
        header('Content-Type: application/json');
        $id = intval($_GET['id']);
        $res = mysqli_query($conn, "SELECT * FROM vehicle_tyres WHERE id = $id");
        $data = mysqli_fetch_assoc($res);
        if ($data) {
            $items_res = mysqli_query($conn, "SELECT * FROM vehicle_tyre_items WHERE tyre_id = $id");
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

// ---------- Save Tyre Size Settings ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_tyre_sizes') {
    $veh_ids = $_POST['size_vehicle_id'] ?? [];
    $sizes   = $_POST['size_value'] ?? [];
    foreach ($veh_ids as $i => $vid) {
        $vid = intval($vid);
        $size = mysqli_real_escape_string($conn, trim($sizes[$i] ?? ''));
        if (!$vid) continue;
        mysqli_query($conn, "INSERT INTO vehicle_tyre_sizes (vehicle_id, tyre_size) VALUES ($vid, " . ($size ? "'$size'" : "NULL") . ")
                              ON DUPLICATE KEY UPDATE tyre_size = " . ($size ? "'$size'" : "NULL"));
    }
    $success_message = "Tyre sizes saved successfully!";
}

// ---------- Save Expense Settings (Direct Expense only) ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_de_settings') {
    $set_direct_exp_id = intval($_POST['de_direct_expense_id'] ?? 0);
    if (!$set_direct_exp_id) {
        $error_message = "Please select a Direct Expense.";
    } else {
        $validIds = array_map(function($e) { return (int)$e['id']; }, tyDirectExpensesList($conn));
        if (!in_array($set_direct_exp_id, $validIds, true)) {
            $error_message = "That expense is not a valid Direct Expense.";
        } else {
            mysqli_query($conn, "INSERT INTO vehicle_tyres_expense_settings (id, direct_expense_id) VALUES (1, $set_direct_exp_id)
                                  ON DUPLICATE KEY UPDATE direct_expense_id = $set_direct_exp_id");
            $success_message = "Expense settings saved as default successfully!";
        }
    }
}

// ---------- Load settings ----------
$de_settings_res = mysqli_query($conn, "SELECT * FROM vehicle_tyres_expense_settings WHERE id = 1");
$de_settings = mysqli_fetch_assoc($de_settings_res) ?: ['direct_expense_id' => null];
$de_expense_id = intval($de_settings['direct_expense_id'] ?? 0);

$de_configured = false;
$de_expense_row = null;
if ($de_expense_id) {
    $der = mysqli_query($conn, "SELECT * FROM expenses WHERE id = " . intval($de_expense_id) . " LIMIT 1");
    $de_expense_row = $der ? mysqli_fetch_assoc($der) : null;
    if ($de_expense_row) { $de_configured = true; }
}
$direct_expenses_list = tyDirectExpensesList($conn);

// ---------- Delete (reverses dag stock movements + linked expense payment) ----------
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $res = mysqli_query($conn, "SELECT vehicle_id, attachment, direct_expense_payment_id FROM vehicle_tyres WHERE id = $id");
    $row = mysqli_fetch_assoc($res);
    if ($row) {
        $tyreSize = tyVehicleTyreSize($conn, $row['vehicle_id']);
        $items_res = mysqli_query($conn, "SELECT * FROM vehicle_tyre_items WHERE tyre_id = $id");
        while ($it = mysqli_fetch_assoc($items_res)) {
            $qty = intval($it['qty']);
            if ($it['item_condition'] === 'new' && intval($it['dag_flag']) === 1) {
                tyDagAdjust($conn, $tyreSize, -$qty); // undo: old tyre had been added to Dag stock
            } elseif ($it['item_condition'] === 'dag') {
                tyDagAdjust($conn, $tyreSize, $qty); // undo: qty had been taken out of Dag stock
                if (intval($it['dag_flag']) === 1) {
                    tyDagAdjust($conn, $tyreSize, -$qty); // undo: removed old tyre had also been added back to Dag stock
                }
            } elseif ($it['item_condition'] === 'new_stock_in') {
                tyNewStockAdjust($conn, $tyreSize, -$qty); // undo: qty had been added to New Stock
            } elseif ($it['item_condition'] === 'new_stock_out') {
                tyNewStockAdjust($conn, $tyreSize, $qty); // undo: qty had been taken out of New Stock
            }
        }
    }
    mysqli_query($conn, "DELETE FROM vehicle_tyre_items WHERE tyre_id = $id");
    mysqli_query($conn, "DELETE FROM vehicle_tyre_other_charges WHERE tyre_id = $id");
    if (mysqli_query($conn, "DELETE FROM vehicle_tyres WHERE id = $id")) {
        if ($row && !empty($row['attachment']) && file_exists($row['attachment'])) { @unlink($row['attachment']); }
        if ($row && !empty($row['direct_expense_payment_id'])) {
            $dep_id = intval($row['direct_expense_payment_id']);
            mysqli_query($conn, "DELETE FROM expense_payment_attachments WHERE payment_id = $dep_id");
            mysqli_query($conn, "DELETE FROM expense_payments WHERE id = $dep_id");
        }
        $success_message = "Tyre record deleted successfully! Dag stock reverted.";
    } else {
        $error_message = "Error deleting record: " . mysqli_error($conn);
    }
}

// ---------- Save (Create) — editing a tyre record with dag stock movements is not supported; delete + recreate instead ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['action'])) {
    $vehicle_id  = intval($_POST['vehicle_id']);
    $tyre_date   = mysqli_real_escape_string($conn, $_POST['tyre_date']);
    $km          = ($_POST['km'] !== '') ? intval($_POST['km']) : null;
    $invoice_no  = mysqli_real_escape_string($conn, $_POST['invoice_no']);
    $service_charge = floatval($_POST['service_charge']);

    $other_desc = $_POST['other_desc'] ?? [];
    $other_amt  = $_POST['other_amount'] ?? [];
    $other_charges = [];
    $other_total = 0;
    foreach ($other_desc as $i => $desc) {
        $desc = trim($desc);
        $amt = floatval($other_amt[$i] ?? 0);
        if ($desc === '' && $amt == 0) continue;
        $other_charges[] = ['description' => $desc, 'amount' => $amt];
        $other_total += $amt;
    }

    $positions  = $_POST['item_position'] ?? [];
    $conditions = $_POST['item_condition'] ?? [];
    $qtysNew    = $_POST['item_qty_new'] ?? [];
    $qtysStockIn  = $_POST['item_qty_stock_in'] ?? [];
    $qtysStockOut = $_POST['item_qty_stock_out'] ?? [];
    $qtysDag    = $_POST['item_qty_dag'] ?? [];
    $prices       = $_POST['item_unit_price'] ?? [];        // used by New
    $pricesStockIn = $_POST['item_unit_price_stock_in'] ?? []; // used by New Stock In
    $dagFlags   = $_POST['item_dag_flag'] ?? []; // hidden input mirrored per row, "1"/"0" — always present, same index as item_position[]

    $tyreSize = tyVehicleTyreSize($conn, $vehicle_id);

    $lines = [];
    $total_amount = $service_charge + $other_total;
    $dag_error = null;

    foreach ($positions as $i => $pos) {
        if (!in_array($pos, ['F','RL','RR'], true)) continue;
        $cond = in_array($conditions[$i] ?? '', ['new','new_stock_in','new_stock_out','dag'], true) ? $conditions[$i] : 'new';

        if ($cond === 'new') { $qty = max(0, intval($qtysNew[$i] ?? 0)); }
        elseif ($cond === 'new_stock_in') { $qty = max(0, intval($qtysStockIn[$i] ?? 0)); }
        elseif ($cond === 'new_stock_out') { $qty = max(0, intval($qtysStockOut[$i] ?? 0)); }
        else { $qty = max(0, intval($qtysDag[$i] ?? 0)); }
        if ($qty <= 0) continue;

        $price = 0; $lineTotal = null;
        $dagFlag = (isset($dagFlags[$i]) && $dagFlags[$i] == '1') ? 1 : 0;

        if ($cond === 'new') {
            $price = floatval($prices[$i] ?? 0);
            $lineTotal = $qty * $price;
            $total_amount += $lineTotal;
            // dagFlag applies as entered
        } elseif ($cond === 'new_stock_in') {
            $price = floatval($pricesStockIn[$i] ?? 0);
            $lineTotal = $qty * $price;
            $total_amount += $lineTotal;
            $dagFlag = 0; // dag checkbox not offered for this condition
        } elseif ($cond === 'new_stock_out') {
            $dagFlag = 0; // dag checkbox not offered for this condition
            if (!$tyreSize) {
                $dag_error = 'This lorry has no Tyre Size configured — set one via "Tyre Size Settings" before using New Stock Out.';
                break;
            }
            $available = tyNewStockAvailable($conn, $tyreSize);
            if ($qty > $available) {
                $dag_error = 'Not enough New Stock for tyre size "' . $tyreSize . '" — available: ' . $available . ', requested: ' . $qty . '.';
                break;
            }
        } elseif ($cond === 'dag') {
            if (!$tyreSize) {
                $dag_error = 'This lorry has no Tyre Size configured — set one via "Tyre Size Settings" before using Dag condition.';
                break;
            }
            $available = tyDagAvailable($conn, $tyreSize);
            if ($qty > $available) {
                $dag_error = 'Not enough Dag stock for tyre size "' . $tyreSize . '" — available: ' . $available . ', requested: ' . $qty . '.';
                break;
            }
        }

        $lines[] = ['position' => $pos, 'condition' => $cond, 'qty' => $qty, 'unit_price' => $price, 'line_total' => $lineTotal, 'dag_flag' => $dagFlag];
    }

    if ($dag_error) {
        $error_message = $dag_error;
    } elseif (empty($lines)) {
        $error_message = "Please add at least one tyre line item with a valid quantity.";
    } else {
        $attachment = null;
        if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
            $dest = $upload_dir . 'tyre_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) { $attachment = $dest; }
        }

        $sql = "INSERT INTO vehicle_tyres (vehicle_id, tyre_date, km, invoice_no, attachment, service_charge, total_amount)
                VALUES ($vehicle_id, '$tyre_date', " . ($km !== null ? $km : "NULL") . ", '$invoice_no', " . ($attachment ? "'$attachment'" : "NULL") . ", $service_charge, $total_amount)";
        if (mysqli_query($conn, $sql)) {
            $id = mysqli_insert_id($conn);

            foreach ($lines as $li) {
                $priceSql = ($li['condition'] === 'new' || $li['condition'] === 'new_stock_in') ? $li['unit_price'] : "NULL";
                $totalSql = ($li['line_total'] !== null) ? $li['line_total'] : "NULL";
                mysqli_query($conn, "INSERT INTO vehicle_tyre_items (tyre_id, position, item_condition, qty, unit_price, line_total, dag_flag)
                    VALUES ($id, '{$li['position']}', '{$li['condition']}', {$li['qty']}, $priceSql, $totalSql, {$li['dag_flag']})");

                if ($li['condition'] === 'new' && $li['dag_flag'] === 1) {
                    tyDagAdjust($conn, $tyreSize, $li['qty']); // old tyre removed -> goes to Dag stock
                } elseif ($li['condition'] === 'dag') {
                    tyDagAdjust($conn, $tyreSize, -$li['qty']); // take a tyre out of Dag stock
                    if ($li['dag_flag'] === 1) {
                        tyDagAdjust($conn, $tyreSize, $li['qty']); // old tyre removed -> also goes back to Dag stock
                    }
                } elseif ($li['condition'] === 'new_stock_in') {
                    tyNewStockAdjust($conn, $tyreSize, $li['qty']); // stock in a batch of new tyres
                } elseif ($li['condition'] === 'new_stock_out') {
                    tyNewStockAdjust($conn, $tyreSize, -$li['qty']); // take new tyres out of stock to install
                }
            }

            foreach ($other_charges as $oc) {
                $od = mysqli_real_escape_string($conn, $oc['description']);
                mysqli_query($conn, "INSERT INTO vehicle_tyre_other_charges (tyre_id, description, amount) VALUES ($id, '$od', {$oc['amount']})");
            }

            // Create the matching Direct Expense payment for the record total, if configured
            if ($de_configured && $total_amount > 0) {
                $budget_id = $de_expense_row['budget_id'] ? intval($de_expense_row['budget_id']) : 0;
                $budgetSql = $budget_id ? $budget_id : 'NULL';
                $remarks = mysqli_real_escape_string($conn, 'Vehicle Tyres — Lorry ' . tyVehicleNumber($conn, $vehicle_id) . ' on ' . $tyre_date);
                $uidv = tyCurrentUserId();
                $initSql = $uidv ? $uidv : 'NULL';

                mysqli_query($conn, "INSERT INTO expense_payments (payment_date, expense_id, budget_id, amount, remarks, initiated_by, status)
                    VALUES ('$tyre_date', $de_expense_id, $budgetSql, $total_amount, '$remarks', $initSql, 'initiated')");
                $dep_id = mysqli_insert_id($conn);
                tyEpAutoProgressStatus($conn, $dep_id);
                mysqli_query($conn, "UPDATE vehicle_tyres SET direct_expense_payment_id = $dep_id WHERE id = $id");
            }

            $success_message = "Tyre record added successfully!";
            if ($de_configured && $total_amount > 0) { $success_message .= " Expense payment created and linked."; }
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    }
}

$list_sql = "SELECT t.*, v.vehicle_number FROM vehicle_tyres t
             LEFT JOIN vehicles v ON t.vehicle_id = v.id
             ORDER BY t.tyre_date DESC, t.id DESC";
$list_result = mysqli_query($conn, $list_sql);

$rows = [];
while ($row = mysqli_fetch_assoc($list_result)) {
    $items_res = mysqli_query($conn, "SELECT * FROM vehicle_tyre_items WHERE tyre_id = " . $row['id']);
    $items = [];
    while ($it = mysqli_fetch_assoc($items_res)) { $items[] = $it; }
    $row['items'] = $items;

    $oc_res = mysqli_query($conn, "SELECT * FROM vehicle_tyre_other_charges WHERE tyre_id = " . $row['id']);
    $other_charges_list = [];
    while ($oc = mysqli_fetch_assoc($oc_res)) { $other_charges_list[] = $oc; }
    $row['other_charges'] = $other_charges_list;

    $rows[] = $row;
}

$vehicles_result = mysqli_query($conn, "SELECT id, vehicle_number FROM vehicles ORDER BY vehicle_number");
$vehicles_list = [];
while ($v = mysqli_fetch_assoc($vehicles_result)) { $vehicles_list[] = $v; }

$vehicle_sizes = [];
$vs_res = mysqli_query($conn, "SELECT vehicle_id, tyre_size FROM vehicle_tyre_sizes");
while ($vs = mysqli_fetch_assoc($vs_res)) { $vehicle_sizes[$vs['vehicle_id']] = $vs['tyre_size']; }

$posLabels = ['F' => 'Front', 'RL' => 'Rear Left', 'RR' => 'Rear Right'];
$condLabels = ['new' => 'New', 'new_stock_in' => 'New Stock In', 'new_stock_out' => 'New Stock Out', 'dag' => 'Dag'];
$condBadge = ['new' => 'badge-success', 'new_stock_in' => 'badge-info', 'new_stock_out' => 'badge-warning', 'dag' => 'badge-neutral'];

include 'header.php';
?>

<div class="page-header">
    <h2 class="page-title">Tyre Management System</h2>
    <p class="page-subtitle">Log tyre changes per position, condition (New / New Return / Dag) and track the reusable Dag stock pool</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success_message; ?></div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?></div>
<?php endif; ?>

<div style="margin-bottom: 20px; display:flex; gap:10px; flex-wrap:wrap;">
    <button onclick="openModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Tyre Record</button>
    <a href="vehicle_dag_report.php" class="btn btn-secondary"><i class="fa-solid fa-chart-column"></i> View Dag Report</a>
    <button onclick="openSizeModal()" class="btn btn-secondary"><i class="fa-solid fa-ruler"></i> Tyre Size Settings</button>
    <button onclick="openDeSettingsModal()" class="btn btn-secondary"><i class="fa-solid fa-gear"></i> Expense Settings</button>
</div>

<!-- Expense Settings Widget -->
<?php if ($de_configured): ?>
<div class="content-card" style="margin-bottom:20px;">
    <h3 class="card-title"><i class="fa-solid fa-file-invoice-dollar"></i> Linked Direct Expense</h3>
    <span class="badge badge-info"><i class="fa-solid fa-receipt"></i> Expense: <?php echo htmlspecialchars($de_expense_row['expense_name']); ?></span>
    <p style="font-size:12px; color:#666; margin:12px 0 0;"><i class="fa-solid fa-circle-info"></i> Every tyre record's Total Amount (New line items + Service Charge) creates a direct Expense Payment against this expense.</p>
</div>
<?php else: ?>
<div class="alert" style="background:#fffbeb; color:#92400e; border:1px solid #fde68a;">
    <i class="fa-solid fa-triangle-exclamation"></i> No Direct Expense is linked yet — tyre records will be saved without an expense payment until you configure one via "Expense Settings".
</div>
<?php endif; ?>

<div class="content-card">
    <h3 class="card-title">Tyre Records</h3>
    <?php if (count($rows) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th></th>
                    <th>Date</th>
                    <th>Lorry</th>
                    <th>KM</th>
                    <th>Invoice No</th>
                    <th>Attachment</th>
                    <th># Items</th>
                    <th>Service Charge</th>
                    <th>Total Amount</th>
                    <th>Expense</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><button type="button" class="btn-action" onclick="toggleExpand(<?php echo $row['id']; ?>)" title="Expand"><i class="fa-solid fa-chevron-down"></i></button></td>
                    <td><?php echo date('d-M-Y', strtotime($row['tyre_date'])); ?></td>
                    <td><strong><?php echo htmlspecialchars($row['vehicle_number'] ?? '-'); ?></strong></td>
                    <td><?php echo $row['km'] !== null ? number_format($row['km']) : '-'; ?></td>
                    <td><?php echo htmlspecialchars($row['invoice_no']) ?: '-'; ?></td>
                    <td>
                        <?php if (!empty($row['attachment'])): ?>
                            <a href="<?php echo htmlspecialchars($row['attachment']); ?>" target="_blank" class="btn-action btn-view" title="View Attachment"><i class="fa-solid fa-paperclip"></i></a>
                        <?php else: ?><span style="color:#999;">-</span><?php endif; ?>
                    </td>
                    <td><?php echo count($row['items']); ?></td>
                    <td><?php echo number_format($row['service_charge'], 2); ?></td>
                    <td><strong><?php echo number_format($row['total_amount'], 2); ?></strong></td>
                    <td>
                        <?php if (!empty($row['direct_expense_payment_id'])): ?>
                            <span class="badge badge-info" title="Expense payment #<?php echo (int)$row['direct_expense_payment_id']; ?>"><i class="fa-solid fa-link"></i> Linked</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="?delete=<?php echo $row['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Delete this tyre record? Dag stock movements will be reverted and its linked Expense Payment removed.')"><i class="fa-solid fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <tr id="expand-<?php echo $row['id']; ?>" style="display:none;">
                    <td colspan="11" style="background:#fafafa;">
                        <table class="data-table" style="margin:0;">
                            <thead><tr><th>Position</th><th>Condition</th><th>Qty</th><th>Unit Price</th><th>Line Total</th><th>Dag</th></tr></thead>
                            <tbody>
                                <?php foreach ($row['items'] as $it): ?>
                                <tr>
                                    <td><?php echo $posLabels[$it['position']] ?? $it['position']; ?></td>
                                    <td><span class="badge <?php echo $condBadge[$it['item_condition']] ?? 'badge-neutral'; ?>"><?php echo $condLabels[$it['item_condition']] ?? $it['item_condition']; ?></span></td>
                                    <td><?php echo number_format($it['qty']); ?></td>
                                    <td><?php echo $it['unit_price'] !== null ? number_format($it['unit_price'], 2) : '-'; ?></td>
                                    <td><?php echo $it['line_total'] !== null ? number_format($it['line_total'], 2) : '-'; ?></td>
                                    <td>
                                        <?php if ($it['item_condition'] === 'new_stock_in'): ?>
                                            <span class="badge badge-info"><i class="fa-solid fa-arrow-up"></i> To New Stock</span>
                                        <?php elseif ($it['item_condition'] === 'new_stock_out'): ?>
                                            <span class="badge badge-warning"><i class="fa-solid fa-arrow-down"></i> From New Stock</span>
                                        <?php elseif ($it['item_condition'] === 'dag'): ?>
                                            <span class="badge badge-warning"><i class="fa-solid fa-arrow-down"></i> From Dag Stock<?php echo intval($it['dag_flag']) === 1 ? ' + Old to Dag' : ''; ?></span>
                                        <?php elseif ($it['item_condition'] === 'new' && intval($it['dag_flag']) === 1): ?>
                                            <span class="badge badge-success"><i class="fa-solid fa-arrow-up"></i> Old to Dag Stock</span>
                                        <?php else: ?>
                                            <span style="color:#999;">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($row['items'])): ?>
                                <tr><td colspan="6" style="text-align:center; color:#999;">No line items.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <?php if (!empty($row['other_charges'])): ?>
                        <table class="data-table" style="margin:12px 0 0;">
                            <thead><tr><th>Other Charge</th><th>Amount</th></tr></thead>
                            <tbody>
                                <?php foreach ($row['other_charges'] as $oc): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($oc['description']) ?: '-'; ?></td>
                                    <td><?php echo number_format($oc['amount'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="color: #666; font-size: 13px; text-align: center; padding: 20px;">No tyre records found.</p>
    <?php endif; ?>
</div>

<!-- Tyre Size Settings Modal -->
<div id="sizeModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Tyre Size Settings</h3>
            <button class="modal-close" onclick="closeSizeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_tyre_sizes">
            <div class="modal-body">
                <p style="font-size:13px; color:#666; margin-top:0;">Set each lorry's tyre size — this determines which Dag stock pool it draws from / contributes to.</p>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead><tr><th>Lorry</th><th>Tyre Size</th></tr></thead>
                        <tbody>
                            <?php foreach ($vehicles_list as $v): ?>
                            <tr>
                                <td><input type="hidden" name="size_vehicle_id[]" value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['vehicle_number']); ?></td>
                                <td><input type="text" name="size_value[]" class="form-input" placeholder="e.g. 195R15" value="<?php echo htmlspecialchars($vehicle_sizes[$v['id']] ?? ''); ?>"></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeSizeModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save Sizes</button>
            </div>
        </form>
    </div>
</div>

<!-- Expense Settings Modal -->
<div id="deSettingsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Expense Settings</h3>
            <button class="modal-close" onclick="closeDeSettingsModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_de_settings">
            <div class="modal-body">
                <p style="font-size:13px; color:#666; margin-top:0;">Choose which Expense every Tyre record's Total Amount should be recorded against as a direct Expense Payment (saved as the default).</p>
                <div class="form-group">
                    <label class="form-label">Direct Expense <span class="required">*</span></label>
                    <select id="de_direct_expense_id" name="de_direct_expense_id" class="form-input select2-de-direct" required>
                        <option value="">Select Expense</option>
                        <?php foreach ($direct_expenses_list as $de): ?>
                            <option value="<?php echo $de['id']; ?>" <?php echo ($de_expense_id == $de['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($de['expense_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeSettingsModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save as Default</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Tyre Record Modal (full large) -->
<div id="tyreModal" class="modal">
    <div class="modal-content modal-xl">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Tyre Record</h3>
            <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="" id="tyreForm" enctype="multipart/form-data">
            <div class="modal-body">
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label">Lorry Number <span class="required">*</span></label>
                        <select id="vehicle_id" name="vehicle_id" class="form-input select2-vehicle" required onchange="onVehicleChange()">
                            <option value="">Select Lorry</option>
                            <?php foreach ($vehicles_list as $v): ?>
                                <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['vehicle_number']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date <span class="required">*</span></label>
                        <input type="date" id="tyre_date" name="tyre_date" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">KM</label>
                        <input type="number" id="km" name="km" class="form-input" placeholder="Enter odometer KM">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Invoice No</label>
                        <input type="text" id="invoice_no" name="invoice_no" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Attachment</label>
                        <input type="file" id="attachment" name="attachment" class="form-input file-input">
                    </div>
                </div>

                <div class="balance-bar" id="vehicleDagBar" style="display:none;">
                    <span><i class="fa-solid fa-circle-dot"></i> Tyre Size / Available Stock</span>
                    <span id="vehicleDagDisplay">-</span>
                </div>

                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-circle-dot"></i> Tyre Line Items</h4>
                    <div id="tyreItemRows"></div>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="addTyreRow()"><i class="fa-solid fa-plus"></i> Add Item Row</button>
                </div>

                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-list"></i> Other Charges</h4>
                    <div id="otherChargesRows"></div>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="addOtherChargeRow()"><i class="fa-solid fa-plus"></i> Add Charge Row</button>
                </div>

                <div class="form-group" style="max-width:240px;">
                    <label class="form-label">Service Charge</label>
                    <input type="number" step="0.01" id="service_charge" name="service_charge" class="form-input calc-total" value="0">
                </div>

                <div class="balance-bar ok" id="totalBar">
                    <span>Total Amount</span>
                    <span id="totalDisplay">0.00</span>
                </div>

                <?php if ($de_configured): ?>
                <p style="font-size:12px; color:#666; margin:14px 0 0;"><i class="fa-solid fa-circle-info"></i> Saving will automatically create an Expense Payment (for the Total Amount) against "<?php echo htmlspecialchars($de_expense_row['expense_name']); ?>".</p>
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
.checkbox-label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 12px; color: #333333; }
.form-checkbox { width: 16px; height: 16px; cursor: pointer; accent-color: #000000; }
.checkbox-text { font-weight: 500; }

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

/* Balance bar */
.balance-bar { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-radius: 8px; font-size: 13px; font-weight: 600; margin: 16px 0; border: 1px solid #e5e5e5; background: #fafafa; }
.balance-bar.ok { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
.balance-bar.bad { background: #fef2f2; color: #991b1b; border-color: #fecaca; }

/* Tyre item row */
/* Other Charges repeatable rows */
.repeat-row { display: grid; grid-template-columns: 2fr 1fr auto; gap: 10px; align-items: end; margin-bottom: 10px; padding: 12px; background: #fafafa; border-radius: 8px; border: 1px solid #f0f0f0; }

.tyre-row { padding: 14px; background: #fafafa; border-radius: 8px; border: 1px solid #f0f0f0; margin-bottom: 12px; }
.tyre-row-top { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; align-items: end; margin-bottom: 10px; }
.tyre-row-fields { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.tyre-row-fields.single { grid-template-columns: 1fr; max-width: 200px; }
.repeat-remove { width: 34px; height: 34px; border-radius: 6px; border: 1px solid #fecaca; background: #fef2f2; color: #991b1b; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.repeat-remove:hover { background: #fecaca; }

/* Responsive */
@media (max-width: 768px) {
    .modal-content { width: 95%; }
    .form-row, .form-row-3, .tyre-row-top, .tyre-row-fields, .repeat-row { grid-template-columns: 1fr; }
    .modal-footer { flex-direction: column; }
    .modal-footer .btn { width: 100%; justify-content: center; }
}
</style>

<script>
let tyreRowIndex = 0;
let currentVehicleDag = { tyre_size: null, available: 0, new_stock_available: 0 };

function toggleExpand(id) {
    const row = document.getElementById('expand-' + id);
    row.style.display = (row.style.display === 'none') ? 'table-row' : 'none';
}

function openSizeModal() { document.getElementById('sizeModal').classList.add('active'); }
function closeSizeModal() { document.getElementById('sizeModal').classList.remove('active'); }
function openDeSettingsModal() { document.getElementById('deSettingsModal').classList.add('active'); }
function closeDeSettingsModal() { document.getElementById('deSettingsModal').classList.remove('active'); }

function onVehicleChange() {
    const vehicleId = document.getElementById('vehicle_id').value;
    const bar = document.getElementById('vehicleDagBar');
    if (!vehicleId) { bar.style.display = 'none'; currentVehicleDag = { tyre_size: null, available: 0, new_stock_available: 0 }; return; }
    fetch('vehicle_tyres.php?ajax=dag_available&vehicle_id=' + vehicleId)
        .then(r => r.json())
        .then(data => {
            currentVehicleDag = data;
            bar.style.display = 'flex';
            if (data.tyre_size) {
                document.getElementById('vehicleDagDisplay').textContent = data.tyre_size + ' — Dag: ' + data.available + ' | New Stock: ' + data.new_stock_available;
                bar.className = 'balance-bar ok';
            } else {
                document.getElementById('vehicleDagDisplay').textContent = 'No tyre size configured for this lorry';
                bar.className = 'balance-bar bad';
            }
            document.querySelectorAll('.tyre-dag-available').forEach(el => { el.textContent = data.available; });
            document.querySelectorAll('.tyre-new-stock-available').forEach(el => { el.textContent = data.new_stock_available; });
        })
        .catch(() => { bar.style.display = 'none'; });
}

function addTyreRow() {
    const wrap = document.getElementById('tyreItemRows');
    const row = document.createElement('div');
    row.className = 'tyre-row';
    row.innerHTML = `
        <div class="tyre-row-top">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Position</label>
                <select class="form-input" name="item_position[]">
                    <option value="F">Front (F)</option>
                    <option value="RL">Rear Left (RL)</option>
                    <option value="RR">Rear Right (RR)</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Condition</label>
                <select class="form-input tyre-cond-select" name="item_condition[]" onchange="toggleTyreCondition(this)">
                    <option value="new">New</option>
                    <option value="new_stock_in">New Stock In</option>
                    <option value="new_stock_out">New Stock Out</option>
                    <option value="dag">Dag</option>
                </select>
            </div>
            <button type="button" class="repeat-remove" onclick="this.closest('.tyre-row').remove(); recalcTotal();"><i class="fa-solid fa-trash"></i></button>
        </div>

        <input type="hidden" name="item_dag_flag[]" class="tyre-dag-flag-hidden" value="0">

        <div class="tyre-fields-new tyre-row-fields">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Unit Price</label>
                <input type="number" step="0.01" min="0" name="item_unit_price[]" class="form-input calc-total" value="0">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Qty</label>
                <input type="number" min="1" name="item_qty_new[]" class="form-input calc-total" value="1">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Line Total</label>
                <input type="text" class="form-input tyre-line-total-new" readonly style="background:#fff;">
            </div>
            <label class="checkbox-label tyre-dag-checkbox-wrap" style="grid-column: 1 / -1; margin-top:2px;">
                <input type="checkbox" class="form-checkbox tyre-dag-checkbox" onchange="this.closest('.tyre-row').querySelector('.tyre-dag-flag-hidden').value = this.checked ? '1' : '0';">
                <span class="checkbox-text">Send old tyre to Dag stock (adds to available Dag qty)</span>
            </label>
        </div>

        <div class="tyre-fields-stock-in tyre-row-fields" style="display:none;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Unit Price</label>
                <input type="number" step="0.01" min="0" name="item_unit_price_stock_in[]" class="form-input calc-total" value="0">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Qty</label>
                <input type="number" min="1" name="item_qty_stock_in[]" class="form-input calc-total" value="1">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Line Total</label>
                <input type="text" class="form-input tyre-line-total-stockin" readonly style="background:#fff;">
            </div>
        </div>

        <div class="tyre-fields-stock-out tyre-row-fields single" style="display:none;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Qty (New Stock Available: <span class="tyre-new-stock-available">${currentVehicleDag.new_stock_available || 0}</span>)</label>
                <input type="number" min="1" name="item_qty_stock_out[]" class="form-input" value="1">
            </div>
        </div>

        <div class="tyre-fields-dag tyre-row-fields single" style="display:none;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Qty (Dag Available: <span class="tyre-dag-available">${currentVehicleDag.available || 0}</span>)</label>
                <input type="number" min="1" name="item_qty_dag[]" class="form-input" value="1">
            </div>
            <label class="checkbox-label tyre-dag-checkbox-wrap" style="grid-column: 1 / -1; margin-top:8px;">
                <input type="checkbox" class="form-checkbox tyre-dag-checkbox-2" onchange="this.closest('.tyre-row').querySelector('.tyre-dag-flag-hidden').value = this.checked ? '1' : '0';">
                <span class="checkbox-text">Send removed old tyre to Dag stock too</span>
            </label>
        </div>
    `;
    // Every possible qty/price input always exists on every row now (values just get ignored
    // server-side for whichever condition isn't active), so all arrays stay the same length as
    // item_position[] — no index drift between rows regardless of which condition is picked.
    wrap.appendChild(row);

    row.querySelectorAll('.calc-total').forEach(inp => inp.addEventListener('input', () => { recalcRowTotal(row); recalcTotal(); }));
    recalcRowTotal(row);
}

function toggleTyreCondition(selectEl) {
    const row = selectEl.closest('.tyre-row');
    const cond = selectEl.value;
    row.querySelector('.tyre-fields-new').style.display = (cond === 'new') ? 'grid' : 'none';
    row.querySelector('.tyre-fields-stock-in').style.display = (cond === 'new_stock_in') ? 'grid' : 'none';
    row.querySelector('.tyre-fields-stock-out').style.display = (cond === 'new_stock_out') ? 'grid' : 'none';
    row.querySelector('.tyre-fields-dag').style.display = (cond === 'dag') ? 'grid' : 'none';

    // The dag checkbox only applies to New and Dag conditions — reset it for the other two
    if (cond !== 'new' && cond !== 'dag') {
        const cb1 = row.querySelector('.tyre-dag-checkbox');
        const cb2 = row.querySelector('.tyre-dag-checkbox-2');
        if (cb1) cb1.checked = false;
        if (cb2) cb2.checked = false;
        row.querySelector('.tyre-dag-flag-hidden').value = '0';
    }
    recalcTotal();
}

function recalcRowTotal(row) {
    const priceInp = row.querySelector('.tyre-fields-new input[name="item_unit_price[]"]');
    const qtyInp = row.querySelector('.tyre-fields-new input[name="item_qty_new[]"]');
    if (priceInp && qtyInp) {
        const price = parseFloat(priceInp.value) || 0;
        const qty = parseFloat(qtyInp.value) || 0;
        row.querySelector('.tyre-line-total-new').value = (price * qty).toFixed(2);
    }
    const priceInp2 = row.querySelector('.tyre-fields-stock-in input[name="item_unit_price_stock_in[]"]');
    const qtyInp2 = row.querySelector('.tyre-fields-stock-in input[name="item_qty_stock_in[]"]');
    if (priceInp2 && qtyInp2) {
        const price2 = parseFloat(priceInp2.value) || 0;
        const qty2 = parseFloat(qtyInp2.value) || 0;
        row.querySelector('.tyre-line-total-stockin').value = (price2 * qty2).toFixed(2);
    }
}

function addOtherChargeRow(desc, amount) {
    desc = desc || '';
    amount = amount !== undefined ? amount : '';
    const wrap = document.getElementById('otherChargesRows');
    const row = document.createElement('div');
    row.className = 'repeat-row';
    row.innerHTML = `
        <div class="form-group" style="margin-bottom:0;">
            <input type="text" name="other_desc[]" class="form-input" placeholder="Description" value="${desc.replace(/"/g,'&quot;')}">
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <input type="number" step="0.01" name="other_amount[]" class="form-input calc-other-total" placeholder="Amount" value="${amount}">
        </div>
        <button type="button" class="repeat-remove" onclick="this.closest('.repeat-row').remove(); recalcTotal();"><i class="fa-solid fa-trash"></i></button>
    `;
    wrap.appendChild(row);
    row.querySelector('.calc-other-total').addEventListener('input', recalcTotal);
}

function recalcTotal() {
    let total = parseFloat(document.getElementById('service_charge').value) || 0;
    document.querySelectorAll('.tyre-row').forEach(row => {
        const cond = row.querySelector('.tyre-cond-select').value;
        if (cond === 'new') {
            const price = parseFloat(row.querySelector('.tyre-fields-new input[name="item_unit_price[]"]').value) || 0;
            const qty = parseFloat(row.querySelector('.tyre-fields-new input[name="item_qty_new[]"]').value) || 0;
            total += price * qty;
        } else if (cond === 'new_stock_in') {
            const price = parseFloat(row.querySelector('.tyre-fields-stock-in input[name="item_unit_price_stock_in[]"]').value) || 0;
            const qty = parseFloat(row.querySelector('.tyre-fields-stock-in input[name="item_qty_stock_in[]"]').value) || 0;
            total += price * qty;
        }
    });
    document.querySelectorAll('.calc-other-total').forEach(inp => { total += parseFloat(inp.value) || 0; });
    document.getElementById('totalDisplay').textContent = total.toFixed(2);
}

function openModal() {
    document.getElementById('tyreModal').classList.add('active');
    document.getElementById('tyreForm').reset();
    document.getElementById('tyreItemRows').innerHTML = '';
    document.getElementById('otherChargesRows').innerHTML = '';
    document.getElementById('vehicleDagBar').style.display = 'none';
    currentVehicleDag = { tyre_size: null, available: 0 };
    tyreRowIndex = 0;
    addTyreRow();
    document.getElementById('modalTitle').textContent = 'Add Tyre Record';
    if (window.jQuery) { $('.select2-vehicle').val('').trigger('change'); }
    recalcTotal();
}

function closeModal() {
    document.getElementById('tyreModal').classList.remove('active');
}

document.addEventListener('DOMContentLoaded', function() {
    if (window.jQuery && $.fn.select2) {
        $('.select2-vehicle').select2({ width: '100%', placeholder: 'Select Lorry' });
        $('.select2-de-direct').select2({ width: '100%', placeholder: 'Select Direct Expense', dropdownParent: $('#deSettingsModal') });
    }
    document.getElementById('service_charge').addEventListener('input', recalcTotal);
});
</script>

<?php include 'footer.php'; ?>