<?php
include 'config.php';

// ---------- Schema ----------
mysqli_report(MYSQLI_REPORT_OFF);
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_fuel_log (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT(11) NOT NULL,
    fuel_date DATE NOT NULL,
    route VARCHAR(255) NULL,
    receipt_no VARCHAR(100) NULL,
    litres DECIMAL(8,2) NOT NULL DEFAULT 0,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    km INT(11) NULL,
    receipt_image VARCHAR(255) NULL,
    cash_float_payment_id INT(11) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vehicle (vehicle_id),
    INDEX idx_date (fuel_date),
    INDEX idx_cf_payment (cash_float_payment_id)
)");

// Safety-net migration in case the table already existed before this column was added
function fuelAddColumnIfMissing($conn, $table, $col, $definitionSql) {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM $table LIKE '$col'");
    if ($r && mysqli_num_rows($r) === 0) { mysqli_query($conn, "ALTER TABLE $table ADD COLUMN $definitionSql"); }
}
fuelAddColumnIfMissing($conn, 'vehicle_fuel_log', 'cash_float_payment_id', 'cash_float_payment_id INT(11) NULL');

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

// Singleton settings row: which Cash Float + which Base (sub) Expense fuel entries should charge against
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_fuel_cash_float_settings (
    id              INT(11) NOT NULL PRIMARY KEY DEFAULT 1,
    cash_float_id   INT(11) NULL,
    expense_id      INT(11) NULL,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
mysqli_query($conn, "INSERT IGNORE INTO vehicle_fuel_cash_float_settings (id, cash_float_id, expense_id) VALUES (1, NULL, NULL)");

// ---------- Cash Float helper functions ----------
function fuelCfJsonIds($str) {
    if (!$str) return [];
    $arr = json_decode($str, true);
    return is_array($arr) ? array_map('intval', $arr) : [];
}

// Sub-expenses added *inside* a Cash Float (parent_cash_float_id = the float) —
// same set cash_float_add_payment.php offers on its own "Expense" select.
function fuelCfSubExpensesForFloat($conn, $cashFloatId) {
    $cashFloatId = intval($cashFloatId);
    $subs = [];
    if (!$cashFloatId) return $subs;
    $r = mysqli_query($conn, "SELECT id, expense_name FROM expenses WHERE parent_cash_float_id = $cashFloatId ORDER BY expense_name ASC");
    if ($r) { while ($row = mysqli_fetch_assoc($r)) { $subs[] = $row; } }
    return $subs;
}

function fuelCfBaseExpenseForFloat($conn, $cashFloatId) {
    $cashFloatId = intval($cashFloatId);
    $fr = mysqli_query($conn, "SELECT * FROM cash_floats WHERE id = $cashFloatId LIMIT 1");
    if (!$fr || mysqli_num_rows($fr) === 0) return ['cash_float' => null, 'base_expense' => null];
    $float = mysqli_fetch_assoc($fr);
    $er = mysqli_query($conn, "SELECT * FROM expenses WHERE id = " . intval($float['expense_id']) . " LIMIT 1");
    $baseExpense = ($er && $erow = mysqli_fetch_assoc($er)) ? $erow : null;
    return ['cash_float' => $float, 'base_expense' => $baseExpense];
}

function fuelCfExpenseIdsForFloat($conn, $cashFloatId, $baseExpenseId) {
    $ids = [intval($baseExpenseId)];
    $r = mysqli_query($conn, "SELECT id FROM expenses WHERE parent_cash_float_id = " . intval($cashFloatId));
    if ($r) { while ($row = mysqli_fetch_assoc($r)) { $ids[] = (int)$row['id']; } }
    return array_values(array_unique(array_filter($ids)));
}

function fuelCfMainExpenseReleased($conn, $cashFloatId, $baseExpenseId) {
    $tblCheck = mysqli_query($conn, "SHOW TABLES LIKE 'expense_payments'");
    if (!$tblCheck || mysqli_num_rows($tblCheck) === 0) { return ['amount' => 0.0, 'count' => 0]; }
    $ids = fuelCfExpenseIdsForFloat($conn, $cashFloatId, $baseExpenseId);
    if (empty($ids)) return ['amount' => 0.0, 'count' => 0];
    $inList = implode(',', $ids);
    $r = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt FROM expense_payments WHERE expense_id IN ($inList) AND status = 'paid'");
    if ($r && $row = mysqli_fetch_assoc($r)) { return ['amount' => (float)$row['total'], 'count' => (int)$row['cnt']]; }
    return ['amount' => 0.0, 'count' => 0];
}

// Balance = Released (main expense_payments tracker, paid) minus Spent
// (cash_float_base_expense_payments, approved+paid) — same formula used by
// cash_float_add_payment.php's capCurrentBalance().
function fuelCfCurrentBalance($conn, $cashFloatId, $baseExpenseId) {
    $mainReleased = fuelCfMainExpenseReleased($conn, $cashFloatId, $baseExpenseId);
    $released = $mainReleased['amount'];

    $spent = 0.0;
    $sr = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM cash_float_base_expense_payments
                                WHERE cash_float_id = " . intval($cashFloatId) . " AND status IN ('approved','paid')");
    if ($sr && $srow = mysqli_fetch_assoc($sr)) { $spent = (float)$srow['total']; }

    return ['released' => $released, 'spent' => $spent, 'balance' => $released - $spent];
}

// $chainRow MUST be the cash_floats row (chain source of truth, same rule as cash_float_add_payment.php)
function fuelCfAutoProgressStatus($conn, $paymentId, $chainRow) {
    $paymentId = intval($paymentId);
    if (!$paymentId || !$chainRow) return null;
    $authorizerIds = fuelCfJsonIds($chainRow['authorizer_ids']);
    $approverIds   = fuelCfJsonIds($chainRow['approver_ids']);
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

function fuelCfCurrentUserId() {
    global $current_user;
    if (isset($current_user) && is_array($current_user) && !empty($current_user['id'])) { return intval($current_user['id']); }
    if (!empty($_SESSION['user_id'])) { return intval($_SESSION['user_id']); }
    return 0;
}

function fuelVehicleNumber($conn, $vehicleId) {
    $vehicleId = intval($vehicleId);
    if (!$vehicleId) return '#' . $vehicleId;
    $r = mysqli_query($conn, "SELECT vehicle_number FROM vehicles WHERE id = $vehicleId");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return ($row && !empty($row['vehicle_number'])) ? $row['vehicle_number'] : ('#' . $vehicleId);
}

$upload_dir = 'uploads/vehicle_fuel_log/';
if (!file_exists($upload_dir)) { mkdir($upload_dir, 0777, true); }

// ---------- Helpers ----------
function fuelCompressReceipt($file, $upload_dir) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $file_name = 'receipt_' . time() . '_' . uniqid() . '.jpg';
    $dest = $upload_dir . $file_name;

    // Non-image files (e.g. accidental PDF) - just move as-is
    if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
        $dest = $upload_dir . 'receipt_' . time() . '_' . uniqid() . '.' . $ext;
        return move_uploaded_file($file['tmp_name'], $dest) ? $dest : null;
    }

    try {
        $src = null;
        if ($ext === 'png') $src = @imagecreatefrompng($file['tmp_name']);
        elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($file['tmp_name']);
        else $src = @imagecreatefromjpeg($file['tmp_name']);

        if (!$src) {
            return move_uploaded_file($file['tmp_name'], $dest) ? $dest : null;
        }

        $w = imagesx($src); $h = imagesy($src);
        $max_w = 800;
        if ($w > $max_w) {
            $new_w = $max_w;
            $new_h = intval($h * ($max_w / $w));
            $resized = imagecreatetruecolor($new_w, $new_h);
            imagecopyresampled($resized, $src, 0, 0, 0, 0, $new_w, $new_h, $w, $h);
            imagedestroy($src);
            $src = $resized;
        }
        imagejpeg($src, $dest, 60);
        imagedestroy($src);
        return $dest;
    } catch (\Throwable $e) {
        return move_uploaded_file($file['tmp_name'], $dest) ? $dest : null;
    }
}

// ---------- AJAX: get single record ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $res = mysqli_query($conn, "SELECT * FROM vehicle_fuel_log WHERE id = $id");
    echo json_encode(mysqli_fetch_assoc($res) ?: []);
    exit;
}

// ---------- AJAX: sub-expenses for a given cash float (used by the Cash Float Settings modal) ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_sub_expenses') {
    header('Content-Type: application/json');
    $cfId = intval($_GET['cash_float_id'] ?? 0);
    echo json_encode(fuelCfSubExpensesForFloat($conn, $cfId));
    exit;
}

// ---------- Save Cash Float Settings ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_cf_settings') {
    $set_cf_id = intval($_POST['cf_cash_float_id'] ?? 0);
    $set_exp_id = intval($_POST['cf_expense_id'] ?? 0);

    if (!$set_cf_id || !$set_exp_id) {
        $error_message = "Please select both a Cash Float and a Base Expense.";
    } else {
        // Validate the expense actually belongs to (is a sub-expense of) the selected float
        $validIds = array_map(function($e) { return (int)$e['id']; }, fuelCfSubExpensesForFloat($conn, $set_cf_id));
        if (!in_array($set_exp_id, $validIds, true)) {
            $error_message = "That expense does not belong to the selected Cash Float.";
        } else {
            mysqli_query($conn, "INSERT INTO vehicle_fuel_cash_float_settings (id, cash_float_id, expense_id) VALUES (1, $set_cf_id, $set_exp_id)
                                  ON DUPLICATE KEY UPDATE cash_float_id = $set_cf_id, expense_id = $set_exp_id");
            $success_message = "Cash Float settings saved successfully!";
        }
    }
}

// ---------- Load current Cash Float settings + balance ----------
$cf_settings_res = mysqli_query($conn, "SELECT * FROM vehicle_fuel_cash_float_settings WHERE id = 1");
$cf_settings = mysqli_fetch_assoc($cf_settings_res) ?: ['cash_float_id' => null, 'expense_id' => null];
$cf_cash_float_id = intval($cf_settings['cash_float_id'] ?? 0);
$cf_expense_id    = intval($cf_settings['expense_id'] ?? 0);

$cf_configured = false;
$cf_float_row = null;
$cf_base_expense_row = null;
$cf_sub_expense_name = null;
$cf_balance = ['released' => 0, 'spent' => 0, 'balance' => 0];

if ($cf_cash_float_id && $cf_expense_id) {
    $cf_data = fuelCfBaseExpenseForFloat($conn, $cf_cash_float_id);
    $cf_float_row = $cf_data['cash_float'];
    $cf_base_expense_row = $cf_data['base_expense'];
    if ($cf_float_row) {
        $cf_configured = true;
        $cf_balance = fuelCfCurrentBalance($conn, $cf_cash_float_id, $cf_float_row['expense_id']);
        $exp_row_res = mysqli_query($conn, "SELECT expense_name FROM expenses WHERE id = " . intval($cf_expense_id));
        $exp_row = mysqli_fetch_assoc($exp_row_res);
        $cf_sub_expense_name = $exp_row['expense_name'] ?? null;
    }
}

$cash_floats_list = [];
$cfl_res = mysqli_query($conn, "SELECT id, expense_name FROM cash_floats ORDER BY expense_name ASC");
while ($cfl = mysqli_fetch_assoc($cfl_res)) { $cash_floats_list[] = $cfl; }

$cf_initial_sub_expenses = $cf_cash_float_id ? fuelCfSubExpensesForFloat($conn, $cf_cash_float_id) : [];

// ---------- Delete ----------
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $res = mysqli_query($conn, "SELECT receipt_image, cash_float_payment_id FROM vehicle_fuel_log WHERE id = $id");
    $row = mysqli_fetch_assoc($res);
    if (mysqli_query($conn, "DELETE FROM vehicle_fuel_log WHERE id = $id")) {
        if ($row && !empty($row['receipt_image']) && file_exists($row['receipt_image'])) {
            @unlink($row['receipt_image']);
        }
        // Delete the linked Cash Float payment together with the fuel entry
        if ($row && !empty($row['cash_float_payment_id'])) {
            $cfp_id = intval($row['cash_float_payment_id']);
            mysqli_query($conn, "DELETE FROM cash_float_base_expense_payment_attachments WHERE payment_id = $cfp_id");
            mysqli_query($conn, "DELETE FROM cash_float_base_expense_payments WHERE id = $cfp_id");
        }
        $success_message = "Fuel log entry deleted successfully!";
    } else {
        $error_message = "Error deleting entry: " . mysqli_error($conn);
    }
}

// ---------- Save (Create / Update) ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['action'])) {
    $vehicle_id  = intval($_POST['vehicle_id']);
    $fuel_date   = mysqli_real_escape_string($conn, $_POST['fuel_date']);
    $route       = mysqli_real_escape_string($conn, trim($_POST['route'] ?? ''));
    $receipt_no  = mysqli_real_escape_string($conn, trim($_POST['receipt_no'] ?? ''));
    $litres      = floatval($_POST['litres']);
    $amount      = floatval($_POST['amount']);
    $km          = ($_POST['km'] !== '') ? intval($_POST['km']) : null;

    $is_edit = isset($_POST['fuel_id']) && !empty($_POST['fuel_id']);
    $req_error = null;
    if (!$vehicle_id) { $req_error = 'Please select a Lorry.'; }
    elseif ($fuel_date === '' || !strtotime($fuel_date)) { $req_error = 'Please select a valid Fuel Date.'; }
    elseif ($route === '') { $req_error = 'Route is required.'; }
    elseif ($receipt_no === '') { $req_error = 'Receipt No is required.'; }
    elseif ($km === null) { $req_error = 'KM Reading is required.'; }
    elseif ($litres <= 0) { $req_error = 'Please enter a valid Litres amount.'; }
    elseif ($amount <= 0) { $req_error = 'Please enter a valid Amount.'; }
    elseif (!$is_edit && empty($_FILES['receipt_image']['name'])) { $req_error = 'Receipt Photo is required.'; }

    if ($req_error) {
        $error_message = $req_error;
    } else {

    $cf_error = null;

    if ($is_edit) {
        // ---------- UPDATE ----------
        $id = intval($_POST['fuel_id']);
        $existing_res = mysqli_query($conn, "SELECT receipt_image, cash_float_payment_id FROM vehicle_fuel_log WHERE id = $id");
        $existing = mysqli_fetch_assoc($existing_res);
        $receipt_image = $existing['receipt_image'];
        if (!empty($_FILES['receipt_image']['name'])) {
            $new_img = fuelCompressReceipt($_FILES['receipt_image'], $upload_dir);
            if ($new_img) {
                if (!empty($existing['receipt_image']) && file_exists($existing['receipt_image'])) @unlink($existing['receipt_image']);
                $receipt_image = $new_img;
            }
        }

        $existing_cfp_id = intval($existing['cash_float_payment_id'] ?? 0);
        $new_cfp_id = $existing_cfp_id;

        if ($cf_configured) {
            // Balance check factoring OUT this record's own previous amount (since we're replacing it)
            $balCheck = fuelCfCurrentBalance($conn, $cf_cash_float_id, $cf_float_row['expense_id']);
            $availableForThis = $balCheck['balance'];
            if ($existing_cfp_id) {
                $pr = mysqli_query($conn, "SELECT amount, status FROM cash_float_base_expense_payments WHERE id = $existing_cfp_id");
                $prow = mysqli_fetch_assoc($pr);
                if ($prow && in_array($prow['status'], ['approved','paid'], true)) {
                    $availableForThis += (float)$prow['amount'];
                }
            }
            if ($amount > $availableForThis) {
                $cf_error = 'This fuel amount of Rs. ' . number_format($amount, 2) . ' exceeds the available Cash Float Balance of Rs. ' . number_format($availableForThis, 2) . '.';
            } else {
                $budget_id = $cf_float_row['budget_id'] ? intval($cf_float_row['budget_id']) : 0;
                $budgetSql = $budget_id ? $budget_id : 'NULL';
                $remarks = mysqli_real_escape_string($conn, 'Fuel entry — Lorry ' . fuelVehicleNumber($conn, $vehicle_id) . ' on ' . $fuel_date);
                $uidv = fuelCfCurrentUserId();
                $initSql = $uidv ? $uidv : 'NULL';

                if ($existing_cfp_id) {
                    mysqli_query($conn, "UPDATE cash_float_base_expense_payments SET
                        payment_date = '$fuel_date', cash_float_id = $cf_cash_float_id, expense_id = $cf_expense_id,
                        budget_id = $budgetSql, amount = $amount, remarks = '$remarks'
                        WHERE id = $existing_cfp_id");
                } else {
                    mysqli_query($conn, "INSERT INTO cash_float_base_expense_payments (payment_date, cash_float_id, expense_id, budget_id, amount, remarks, initiated_by, status)
                        VALUES ('$fuel_date', $cf_cash_float_id, $cf_expense_id, $budgetSql, $amount, '$remarks', $initSql, 'initiated')");
                    $new_cfp_id = mysqli_insert_id($conn);
                    fuelCfAutoProgressStatus($conn, $new_cfp_id, $cf_float_row);
                }
            }
        }

        if (!$cf_error) {
            $sql = "UPDATE vehicle_fuel_log SET
                    vehicle_id = $vehicle_id,
                    fuel_date = '$fuel_date',
                    route = '$route',
                    receipt_no = '$receipt_no',
                    litres = $litres,
                    amount = $amount,
                    km = " . ($km !== null ? $km : "NULL") . ",
                    receipt_image = " . ($receipt_image ? "'$receipt_image'" : "NULL") . ",
                    cash_float_payment_id = " . ($new_cfp_id ? $new_cfp_id : "NULL") . "
                    WHERE id = $id";
            if (mysqli_query($conn, $sql)) {
                $success_message = "Fuel log entry updated successfully!";
            } else {
                $error_message = "Error: " . mysqli_error($conn);
            }
        } else {
            $error_message = $cf_error;
        }
    } else {
        // ---------- CREATE ----------
        $receipt_image = !empty($_FILES['receipt_image']['name']) ? fuelCompressReceipt($_FILES['receipt_image'], $upload_dir) : null;
        $new_cfp_id = null;

        if ($cf_configured) {
            $balCheck = fuelCfCurrentBalance($conn, $cf_cash_float_id, $cf_float_row['expense_id']);
            if ($amount > $balCheck['balance']) {
                $cf_error = 'This fuel amount of Rs. ' . number_format($amount, 2) . ' exceeds the available Cash Float Balance of Rs. ' . number_format($balCheck['balance'], 2)
                    . ' (Rs. ' . number_format($balCheck['released'], 2) . ' released minus Rs. ' . number_format($balCheck['spent'], 2) . ' already spent).';
            } else {
                $budget_id = $cf_float_row['budget_id'] ? intval($cf_float_row['budget_id']) : 0;
                $budgetSql = $budget_id ? $budget_id : 'NULL';
                $remarks = mysqli_real_escape_string($conn, 'Fuel entry — Lorry ' . fuelVehicleNumber($conn, $vehicle_id) . ' on ' . $fuel_date);
                $uidv = fuelCfCurrentUserId();
                $initSql = $uidv ? $uidv : 'NULL';

                $ok = mysqli_query($conn, "INSERT INTO cash_float_base_expense_payments (payment_date, cash_float_id, expense_id, budget_id, amount, remarks, initiated_by, status)
                    VALUES ('$fuel_date', $cf_cash_float_id, $cf_expense_id, $budgetSql, $amount, '$remarks', $initSql, 'initiated')");
                if ($ok) {
                    $new_cfp_id = mysqli_insert_id($conn);
                    fuelCfAutoProgressStatus($conn, $new_cfp_id, $cf_float_row);
                } else {
                    $cf_error = "Error creating Cash Float payment: " . mysqli_error($conn);
                }
            }
        }

        if (!$cf_error) {
            $sql = "INSERT INTO vehicle_fuel_log (vehicle_id, fuel_date, route, receipt_no, litres, amount, km, receipt_image, cash_float_payment_id)
                    VALUES ($vehicle_id, '$fuel_date', '$route', '$receipt_no', $litres, $amount, " .
                    ($km !== null ? $km : "NULL") . ", " . ($receipt_image ? "'$receipt_image'" : "NULL") . ", " .
                    ($new_cfp_id ? $new_cfp_id : "NULL") . ")";
            if (mysqli_query($conn, $sql)) {
                $success_message = "Fuel log entry added successfully!";
                if ($cf_configured) { $success_message .= " Cash Float payment created and linked."; }
            } else {
                $error_message = "Error: " . mysqli_error($conn);
                // Roll back the orphaned cash float payment if the fuel row failed to save
                if ($new_cfp_id) { mysqli_query($conn, "DELETE FROM cash_float_base_expense_payments WHERE id = $new_cfp_id"); }
            }
        } else {
            $error_message = $cf_error;
        }
    }

    } // end !$req_error

    // Refresh balance snapshot after any save so the widget below reflects the latest numbers
    if ($cf_configured) {
        $cf_balance = fuelCfCurrentBalance($conn, $cf_cash_float_id, $cf_float_row['expense_id']);
    }
}

// ---------- Filters ----------
$f_vehicle = isset($_GET['f_vehicle']) ? intval($_GET['f_vehicle']) : 0;
$f_from    = isset($_GET['f_from']) ? mysqli_real_escape_string($conn, $_GET['f_from']) : '';
$f_to      = isset($_GET['f_to']) ? mysqli_real_escape_string($conn, $_GET['f_to']) : '';

$where = [];
if ($f_vehicle) $where[] = "fl.vehicle_id = $f_vehicle";
if ($f_from)    $where[] = "fl.fuel_date >= '$f_from'";
if ($f_to)      $where[] = "fl.fuel_date <= '$f_to'";
$where_sql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

$list_sql = "SELECT fl.*, v.vehicle_number
             FROM vehicle_fuel_log fl
             LEFT JOIN vehicles v ON fl.vehicle_id = v.id
             $where_sql
             ORDER BY fl.fuel_date DESC, fl.id DESC";
$list_result = mysqli_query($conn, $list_sql);

$totals_sql = "SELECT COALESCE(SUM(litres),0) t_litres, COALESCE(SUM(amount),0) t_amount, COUNT(*) t_count
               FROM vehicle_fuel_log fl $where_sql";
$totals = mysqli_fetch_assoc(mysqli_query($conn, $totals_sql));

$vehicles_result = mysqli_query($conn, "SELECT id, vehicle_number FROM vehicles ORDER BY vehicle_number");
$vehicles_list = [];
while ($v = mysqli_fetch_assoc($vehicles_result)) { $vehicles_list[] = $v; }

include 'header.php';
?>

<div class="page-header">
    <h2 class="page-title">Vehicle Fuel Log (KM Sheet)</h2>
    <p class="page-subtitle">Track fuel purchases, litres, amounts and mileage per lorry</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success_message; ?></div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?></div>
<?php endif; ?>

<div style="margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button onclick="openModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Fuel Entry</button>
        <button onclick="openCfSettingsModal()" class="btn btn-secondary"><i class="fa-solid fa-gear"></i> Cash Float Settings</button>
    </div>
</div>

<!-- Cash Float Balance Widget -->
<?php if ($cf_configured): ?>
<div class="content-card" style="margin-bottom:20px;">
    <h3 class="card-title"><i class="fa-solid fa-money-bill-transfer"></i> Linked Cash Float</h3>
    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px;">
        <span class="badge badge-info"><i class="fa-solid fa-wallet"></i> Float: <?php echo htmlspecialchars($cf_float_row['expense_name']); ?></span>
        <span class="badge badge-neutral"><i class="fa-solid fa-gas-pump"></i> Base Expense: <?php echo htmlspecialchars($cf_sub_expense_name ?? '-'); ?></span>
    </div>
    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px;">
        <div class="balance-bar ok" style="margin:0;"><span>Released</span><span>Rs. <?php echo number_format($cf_balance['released'], 2); ?></span></div>
        <div class="balance-bar" style="margin:0;"><span>Spent</span><span>Rs. <?php echo number_format($cf_balance['spent'], 2); ?></span></div>
        <div class="balance-bar <?php echo $cf_balance['balance'] >= 0 ? 'ok' : 'bad'; ?>" style="margin:0;"><span>Balance</span><span>Rs. <?php echo number_format($cf_balance['balance'], 2); ?></span></div>
    </div>
</div>
<?php else: ?>
<div class="alert" style="background:#fffbeb; color:#92400e; border:1px solid #fde68a;">
    <i class="fa-solid fa-triangle-exclamation"></i> No Cash Float is linked yet — fuel entries will be saved without a Cash Float payment until you configure one via "Cash Float Settings".
</div>
<?php endif; ?>

<!-- Filters -->
<form method="GET" class="filter-bar">
    <div class="filter-group">
        <label>Lorry</label>
        <select name="f_vehicle" class="form-input select2-vehicle">
            <option value="">All Vehicles</option>
            <?php foreach ($vehicles_list as $v): ?>
                <option value="<?php echo $v['id']; ?>" <?php echo ($f_vehicle == $v['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($v['vehicle_number']); ?></option>
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
    <?php if ($f_vehicle || $f_from || $f_to): ?>
    <div class="filter-group">
        <a href="vehicle_fuel_log.php" class="btn btn-secondary"><i class="fa-solid fa-xmark"></i> Clear</a>
    </div>
    <?php endif; ?>
</form>

<div class="content-card">
    <h3 class="card-title">Fuel Log Entries</h3>
    <?php if (mysqli_num_rows($list_result) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Lorry</th>
                    <th>Route</th>
                    <th>Receipt No</th>
                    <th>Litres</th>
                    <th>Amount (Rs.)</th>
                    <th>KM</th>
                    <th>Receipt</th>
                    <th>Cash Float</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = mysqli_fetch_assoc($list_result)): ?>
                <tr>
                    <td><?php echo date('d-M-Y', strtotime($row['fuel_date'])); ?></td>
                    <td><strong><?php echo htmlspecialchars($row['vehicle_number'] ?? '-'); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['route']) ?: '<span style="color:#999;">-</span>'; ?></td>
                    <td><?php echo htmlspecialchars($row['receipt_no']) ?: '<span style="color:#999;">-</span>'; ?></td>
                    <td><?php echo number_format($row['litres'], 2); ?></td>
                    <td><?php echo number_format($row['amount'], 2); ?></td>
                    <td><?php echo $row['km'] !== null ? number_format($row['km']) : '<span style="color:#999;">-</span>'; ?></td>
                    <td>
                        <?php if (!empty($row['receipt_image'])): ?>
                            <a href="<?php echo htmlspecialchars($row['receipt_image']); ?>" target="_blank" class="btn-action btn-view" title="View Receipt"><i class="fa-solid fa-image"></i></a>
                        <?php else: ?>
                            <span style="color:#999;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($row['cash_float_payment_id'])): ?>
                            <span class="badge badge-success" title="Cash Float payment #<?php echo (int)$row['cash_float_payment_id']; ?>"><i class="fa-solid fa-link"></i> Linked</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="#" onclick="editFuel(<?php echo $row['id']; ?>); return false;" class="btn-action btn-edit" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <a href="?delete=<?php echo $row['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Delete this fuel log entry? This will also remove its linked Cash Float payment, if any.')"><i class="fa-solid fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4">TOTALS (<?php echo $totals['t_count']; ?> entries)</td>
                    <td><?php echo number_format($totals['t_litres'], 2); ?> L</td>
                    <td>Rs. <?php echo number_format($totals['t_amount'], 2); ?></td>
                    <td colspan="4"></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php else: ?>
    <p style="color: #666; font-size: 13px; text-align: center; padding: 20px;">No fuel log entries found.</p>
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
                <p style="font-size:13px; color:#666; margin-top:0;">Choose which Cash Float and Base Expense every fuel entry should be recorded against as a Cash Float payment.</p>
                <div class="form-group">
                    <label class="form-label">Cash Float <span class="required">*</span></label>
                    <select id="cf_cash_float_id" name="cf_cash_float_id" class="form-input select2-cf-float" required>
                        <option value="">Select Cash Float</option>
                        <?php foreach ($cash_floats_list as $cfl): ?>
                            <option value="<?php echo $cfl['id']; ?>" <?php echo ($cf_cash_float_id == $cfl['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cfl['expense_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Base Expense (inside the selected Cash Float) <span class="required">*</span></label>
                    <select id="cf_expense_id" name="cf_expense_id" class="form-input select2-cf-expense" required>
                        <option value="">Select Cash Float first</option>
                        <?php foreach ($cf_initial_sub_expenses as $se): ?>
                            <option value="<?php echo $se['id']; ?>" <?php echo ($cf_expense_id == $se['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($se['expense_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Only expenses already added inside that Cash Float (via the desktop "Add Expense" screen) are shown here.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCfSettingsModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save Settings</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal -->
<div id="fuelModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Fuel Entry</h3>
            <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="" id="fuelForm" enctype="multipart/form-data">
            <input type="hidden" name="fuel_id" id="fuel_id">
            <div class="modal-body">
                <?php if ($cf_configured): ?>
                <div class="balance-bar <?php echo $cf_balance['balance'] >= 0 ? 'ok' : 'bad'; ?>">
                    <span><i class="fa-solid fa-wallet"></i> Cash Float Balance</span>
                    <span>Rs. <?php echo number_format($cf_balance['balance'], 2); ?></span>
                </div>
                <?php endif; ?>

                <div class="fuel-section fuel-section-trip">
                    <div class="fuel-section-title"><i class="fa-solid fa-route"></i> Trip Details</div>
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
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Fuel Date <span class="required">*</span></label>
                            <input type="date" id="fuel_date" name="fuel_date" class="form-input" required>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Route <span class="required">*</span></label>
                            <input type="text" id="route" name="route" class="form-input" placeholder="Enter route" required>
                        </div>
                    </div>
                </div>

                <div class="fuel-section fuel-section-receipt">
                    <div class="fuel-section-title"><i class="fa-solid fa-receipt"></i> Receipt Details</div>
                    <div class="form-row">
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Receipt No <span class="required">*</span></label>
                            <input type="text" id="receipt_no" name="receipt_no" class="form-input" placeholder="Enter receipt number" required>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">KM Reading <span class="required">*</span></label>
                            <input type="number" id="km" name="km" class="form-input" placeholder="Enter odometer KM" required>
                        </div>
                    </div>
                    <div class="form-group" style="margin-top:16px; margin-bottom:0;">
                        <label class="form-label">Receipt Photo <span class="required">*</span></label>
                        <input type="file" id="receipt_image" name="receipt_image" class="form-input file-input" accept=".jpg,.jpeg,.png,.webp" required>
                        <small class="form-hint">Photo will be compressed automatically for storage.</small>
                        <div id="existingReceiptWrap" style="display:none; margin-top:8px;">
                            <a href="#" id="existingReceiptLink" target="_blank" style="font-size:12px;">View current receipt photo</a>
                        </div>
                    </div>
                </div>

                <div class="fuel-section fuel-section-cost">
                    <div class="fuel-section-title"><i class="fa-solid fa-gas-pump"></i> Fuel &amp; Cost</div>
                    <div class="form-row">
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Litres <span class="required">*</span></label>
                            <input type="number" step="0.01" id="litres" name="litres" class="form-input" required>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Amount (Rs.) <span class="required">*</span></label>
                            <input type="number" step="0.01" id="amount" name="amount" class="form-input" required>
                        </div>
                    </div>
                </div>

                <?php if ($cf_configured): ?>
                <p style="font-size:12px; color:#666; margin:14px 0 0;"><i class="fa-solid fa-circle-info"></i> Saving this entry will automatically create/update a matching Cash Float payment against "<?php echo htmlspecialchars($cf_float_row['expense_name']); ?>" → "<?php echo htmlspecialchars($cf_sub_expense_name ?? ''); ?>".</p>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> <span id="submitBtnText">Save Entry</span></button>
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

/* Fuel Log form sections — compact accent-bordered cards, no extra whitespace beyond existing form-group rhythm */
.fuel-section { border: 1px solid #eee; border-left: 3px solid #000; border-radius: 8px; padding: 14px 16px; margin-bottom: 16px; background: #fcfcfc; }
.fuel-section:last-of-type { margin-bottom: 0; }
.fuel-section-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #333; display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
.fuel-section-title i { font-size: 13px; color: #000; }
.fuel-section-trip { border-left-color: #3b82f6; background: #f7faff; }
.fuel-section-trip .fuel-section-title i { color: #3b82f6; }
.fuel-section-receipt { border-left-color: #d97706; background: #fffdf7; }
.fuel-section-receipt .fuel-section-title i { color: #d97706; }
.fuel-section-cost { border-left-color: #16a34a; background: #f7fdf9; }
.fuel-section-cost .fuel-section-title i { color: #16a34a; }

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
function openModal() {
    document.getElementById('fuelModal').classList.add('active');
    document.getElementById('fuelForm').reset();
    document.getElementById('fuel_id').value = '';
    document.getElementById('modalTitle').textContent = 'Add Fuel Entry';
    document.getElementById('submitBtnText').textContent = 'Save Entry';
    document.getElementById('existingReceiptWrap').style.display = 'none';
    document.getElementById('receipt_image').required = true;
    if (window.jQuery) { $('.select2-vehicle').val('').trigger('change'); }
}

function closeModal() {
    document.getElementById('fuelModal').classList.remove('active');
}

function editFuel(id) {
    fetch('vehicle_fuel_log.php?ajax=get&id=' + id)
        .then(response => response.json())
        .then(data => {
            document.getElementById('fuelModal').classList.add('active');
            document.getElementById('fuel_id').value = data.id;
            document.getElementById('fuel_date').value = data.fuel_date;
            document.getElementById('route').value = data.route || '';
            document.getElementById('receipt_no').value = data.receipt_no || '';
            document.getElementById('km').value = data.km || '';
            document.getElementById('litres').value = data.litres;
            document.getElementById('amount').value = data.amount;
            if (window.jQuery) { $('#vehicle_id').val(data.vehicle_id).trigger('change'); }
            else { document.getElementById('vehicle_id').value = data.vehicle_id; }

            if (data.receipt_image) {
                document.getElementById('existingReceiptWrap').style.display = 'block';
                document.getElementById('existingReceiptLink').href = data.receipt_image;
                document.getElementById('receipt_image').required = false;
            } else {
                document.getElementById('existingReceiptWrap').style.display = 'none';
                document.getElementById('receipt_image').required = true;
            }

            document.getElementById('modalTitle').textContent = 'Edit Fuel Entry';
            document.getElementById('submitBtnText').textContent = 'Update Entry';
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading fuel entry data');
        });
}

// ---------- Cash Float Settings Modal ----------
function openCfSettingsModal() {
    document.getElementById('cfSettingsModal').classList.add('active');
}

function closeCfSettingsModal() {
    document.getElementById('cfSettingsModal').classList.remove('active');
}

function loadSubExpensesForFloat(cashFloatId, preselectExpenseId) {
    const $expenseSelect = window.jQuery ? $('#cf_expense_id') : null;
    if (!cashFloatId) {
        if ($expenseSelect) { $expenseSelect.empty().append('<option value="">Select Cash Float first</option>').trigger('change'); }
        return;
    }
    fetch('vehicle_fuel_log.php?ajax=get_sub_expenses&cash_float_id=' + cashFloatId)
        .then(r => r.json())
        .then(list => {
            if ($expenseSelect) {
                $expenseSelect.empty();
                if (!list.length) {
                    $expenseSelect.append('<option value="">No expenses added inside this float yet</option>');
                } else {
                    $expenseSelect.append('<option value="">Select Base Expense</option>');
                    list.forEach(e => {
                        const opt = new Option(e.expense_name, e.id, false, String(e.id) === String(preselectExpenseId || ''));
                        $expenseSelect.append(opt);
                    });
                }
                $expenseSelect.trigger('change');
            }
        })
        .catch(() => {
            if ($expenseSelect) { $expenseSelect.empty().append('<option value="">Error loading expenses</option>').trigger('change'); }
        });
}

document.addEventListener('DOMContentLoaded', function() {
    if (window.jQuery && $.fn.select2) {
        $('.select2-vehicle').select2({ width: '100%', placeholder: 'Select Lorry' });
        $('.select2-cf-float').select2({ width: '100%', placeholder: 'Select Cash Float', dropdownParent: $('#cfSettingsModal') });
        $('.select2-cf-expense').select2({ width: '100%', placeholder: 'Select Base Expense', dropdownParent: $('#cfSettingsModal') });

        $('.select2-cf-float').on('change', function() {
            loadSubExpensesForFloat($(this).val(), null);
        });
    }
});
</script>

<?php include 'footer.php'; ?>