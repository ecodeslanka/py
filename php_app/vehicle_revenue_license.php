<?php
include 'config.php';
mysqli_report(MYSQLI_REPORT_OFF);

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_revenue_license (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT(11) NOT NULL,
    renewal_date DATE NOT NULL,
    year_range VARCHAR(20) NULL,
    fitness_cert_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    ecotest_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    revenue_license_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    cash_float_payment_id INT(11) NULL,
    direct_expense_payment_id INT(11) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vehicle (vehicle_id),
    INDEX idx_renewal (renewal_date),
    INDEX idx_cf_payment (cash_float_payment_id),
    INDEX idx_de_payment (direct_expense_payment_id)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_revenue_license_other_charges (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    license_id INT(11) NOT NULL,
    description VARCHAR(255) NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    INDEX idx_license (license_id)
)");

// Safety-net migration in case the table already existed before this column was added
function rlAddColumnIfMissing($conn, $table, $col, $definitionSql) {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM $table LIKE '$col'");
    if ($r && mysqli_num_rows($r) === 0) { mysqli_query($conn, "ALTER TABLE $table ADD COLUMN $definitionSql"); }
}
rlAddColumnIfMissing($conn, 'vehicle_revenue_license', 'cash_float_payment_id', 'cash_float_payment_id INT(11) NULL');
rlAddColumnIfMissing($conn, 'vehicle_revenue_license', 'direct_expense_payment_id', 'direct_expense_payment_id INT(11) NULL');

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

// Direct (non-Cash-Float) expense payments — same schema as expense_payments.php
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

// Singleton settings row: which Cash Float + which Base (sub) Expense — OR — which Direct Expense —
// revenue license entries should charge against. "mode" decides which pair is active.
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_revenue_license_cash_float_settings (
    id                  INT(11) NOT NULL PRIMARY KEY DEFAULT 1,
    mode                ENUM('cash_float','direct_expense') NOT NULL DEFAULT 'cash_float',
    cash_float_id       INT(11) NULL,
    expense_id          INT(11) NULL,
    direct_expense_id   INT(11) NULL,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
mysqli_query($conn, "INSERT IGNORE INTO vehicle_revenue_license_cash_float_settings (id, mode, cash_float_id, expense_id, direct_expense_id) VALUES (1, 'cash_float', NULL, NULL, NULL)");
rlAddColumnIfMissing($conn, 'vehicle_revenue_license_cash_float_settings', 'mode', "mode ENUM('cash_float','direct_expense') NOT NULL DEFAULT 'cash_float'");
rlAddColumnIfMissing($conn, 'vehicle_revenue_license_cash_float_settings', 'direct_expense_id', 'direct_expense_id INT(11) NULL');

// ---------- Cash Float helper functions ----------
function rlCfJsonIds($str) {
    if (!$str) return [];
    $arr = json_decode($str, true);
    return is_array($arr) ? array_map('intval', $arr) : [];
}

// Sub-expenses added *inside* a Cash Float (parent_cash_float_id = the float) —
// same set cash_float_add_payment.php offers on its own "Expense" select.
function rlCfSubExpensesForFloat($conn, $cashFloatId) {
    $cashFloatId = intval($cashFloatId);
    $subs = [];
    if (!$cashFloatId) return $subs;
    $r = mysqli_query($conn, "SELECT id, expense_name FROM expenses WHERE parent_cash_float_id = $cashFloatId ORDER BY expense_name ASC");
    if ($r) { while ($row = mysqli_fetch_assoc($r)) { $subs[] = $row; } }
    return $subs;
}

function rlCfBaseExpenseForFloat($conn, $cashFloatId) {
    $cashFloatId = intval($cashFloatId);
    $fr = mysqli_query($conn, "SELECT * FROM cash_floats WHERE id = $cashFloatId LIMIT 1");
    if (!$fr || mysqli_num_rows($fr) === 0) return ['cash_float' => null, 'base_expense' => null];
    $float = mysqli_fetch_assoc($fr);
    $er = mysqli_query($conn, "SELECT * FROM expenses WHERE id = " . intval($float['expense_id']) . " LIMIT 1");
    $baseExpense = ($er && $erow = mysqli_fetch_assoc($er)) ? $erow : null;
    return ['cash_float' => $float, 'base_expense' => $baseExpense];
}

function rlCfExpenseIdsForFloat($conn, $cashFloatId, $baseExpenseId) {
    $ids = [intval($baseExpenseId)];
    $r = mysqli_query($conn, "SELECT id FROM expenses WHERE parent_cash_float_id = " . intval($cashFloatId));
    if ($r) { while ($row = mysqli_fetch_assoc($r)) { $ids[] = (int)$row['id']; } }
    return array_values(array_unique(array_filter($ids)));
}

function rlCfMainExpenseReleased($conn, $cashFloatId, $baseExpenseId) {
    $tblCheck = mysqli_query($conn, "SHOW TABLES LIKE 'expense_payments'");
    if (!$tblCheck || mysqli_num_rows($tblCheck) === 0) { return ['amount' => 0.0, 'count' => 0]; }
    $ids = rlCfExpenseIdsForFloat($conn, $cashFloatId, $baseExpenseId);
    if (empty($ids)) return ['amount' => 0.0, 'count' => 0];
    $inList = implode(',', $ids);
    $r = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt FROM expense_payments WHERE expense_id IN ($inList) AND status = 'paid'");
    if ($r && $row = mysqli_fetch_assoc($r)) { return ['amount' => (float)$row['total'], 'count' => (int)$row['cnt']]; }
    return ['amount' => 0.0, 'count' => 0];
}

// Balance = Released (main expense_payments tracker, paid) minus Spent
// (cash_float_base_expense_payments, approved+paid) — same formula used by
// cash_float_add_payment.php's capCurrentBalance().
function rlCfCurrentBalance($conn, $cashFloatId, $baseExpenseId) {
    $mainReleased = rlCfMainExpenseReleased($conn, $cashFloatId, $baseExpenseId);
    $released = $mainReleased['amount'];

    $spent = 0.0;
    $sr = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM cash_float_base_expense_payments
                                WHERE cash_float_id = " . intval($cashFloatId) . " AND status IN ('approved','paid')");
    if ($sr && $srow = mysqli_fetch_assoc($sr)) { $spent = (float)$srow['total']; }

    return ['released' => $released, 'spent' => $spent, 'balance' => $released - $spent];
}

// $chainRow MUST be the cash_floats row (chain source of truth, same rule as cash_float_add_payment.php)
function rlCfAutoProgressStatus($conn, $paymentId, $chainRow) {
    $paymentId = intval($paymentId);
    if (!$paymentId || !$chainRow) return null;
    $authorizerIds = rlCfJsonIds($chainRow['authorizer_ids']);
    $approverIds   = rlCfJsonIds($chainRow['approver_ids']);
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

function rlCfCurrentUserId() {
    global $current_user;
    if (isset($current_user) && is_array($current_user) && !empty($current_user['id'])) { return intval($current_user['id']); }
    if (!empty($_SESSION['user_id'])) { return intval($_SESSION['user_id']); }
    return 0;
}

function rlVehicleNumber($conn, $vehicleId) {
    $vehicleId = intval($vehicleId);
    if (!$vehicleId) return '#' . $vehicleId;
    $r = mysqli_query($conn, "SELECT vehicle_number FROM vehicles WHERE id = $vehicleId");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return ($row && !empty($row['vehicle_number'])) ? $row['vehicle_number'] : ('#' . $vehicleId);
}

// ---------- Direct Expense helpers (non-Cash-Float path, mirrors expense_payments.php) ----------
// "Direct" expenses = ordinary expenses NOT tied to any Cash Float (never a float's own base
// expense, never a sub-expense added inside one).
function rlDirectExpensesList($conn) {
    $list = [];
    $r = mysqli_query($conn, "SELECT id, expense_name, budget_id FROM expenses WHERE enable_float = 0 AND parent_cash_float_id IS NULL ORDER BY expense_name ASC");
    if ($r) { while ($row = mysqli_fetch_assoc($r)) { $list[] = $row; } }
    return $list;
}

// Same auto-progression rule as expense_payments.php's epAutoProgressStatus(), but reads the
// expense's own initiater/authorizer/approver ids directly off `expenses` (a direct expense has
// no Cash Float "chain source of truth" to defer to).
function rlEpAutoProgressStatus($conn, $paymentId) {
    $paymentId = intval($paymentId);
    if (!$paymentId) return null;
    $r = mysqli_query($conn, "SELECT ep.status, e.authorizer_ids, e.approver_ids FROM expense_payments ep LEFT JOIN expenses e ON e.id = ep.expense_id WHERE ep.id = $paymentId LIMIT 1");
    if (!$r || mysqli_num_rows($r) === 0) return null;
    $row = mysqli_fetch_assoc($r);
    $authorizerIds = rlCfJsonIds($row['authorizer_ids']);
    $approverIds   = rlCfJsonIds($row['approver_ids']);
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

try {
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'get' && isset($_GET['id'])) {
        header('Content-Type: application/json');
        $id = intval($_GET['id']);
        $res = mysqli_query($conn, "SELECT * FROM vehicle_revenue_license WHERE id = $id");
        $data = mysqli_fetch_assoc($res);
        if ($data) {
            $charges_res = mysqli_query($conn, "SELECT * FROM vehicle_revenue_license_other_charges WHERE license_id = $id");
            $charges = [];
            while ($c = mysqli_fetch_assoc($charges_res)) { $charges[] = $c; }
            $data['other_charges'] = $charges;
        }
        echo json_encode($data ?: []);
        exit;
    }
} catch (\Throwable $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// ---------- AJAX: sub-expenses for a given cash float (used by the Cash Float Settings modal) ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_sub_expenses') {
    header('Content-Type: application/json');
    $cfId = intval($_GET['cash_float_id'] ?? 0);
    echo json_encode(rlCfSubExpensesForFloat($conn, $cfId));
    exit;
}

// ---------- Save Expense Settings (Direct Expense only, matching expense_payments.php) ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_cf_settings') {
    $set_direct_exp_id = intval($_POST['cf_direct_expense_id'] ?? 0);
    if (!$set_direct_exp_id) {
        $error_message = "Please select a Direct Expense.";
    } else {
        $validIds = array_map(function($e) { return (int)$e['id']; }, rlDirectExpensesList($conn));
        if (!in_array($set_direct_exp_id, $validIds, true)) {
            $error_message = "That expense is not a valid Direct Expense.";
        } else {
            mysqli_query($conn, "INSERT INTO vehicle_revenue_license_cash_float_settings (id, mode, cash_float_id, expense_id, direct_expense_id) VALUES (1, 'direct_expense', NULL, NULL, $set_direct_exp_id)
                                  ON DUPLICATE KEY UPDATE mode = 'direct_expense', direct_expense_id = $set_direct_exp_id");
            $success_message = "Expense settings saved as default successfully!";
        }
    }
}

// ---------- Load current settings ----------
$cf_settings_res = mysqli_query($conn, "SELECT * FROM vehicle_revenue_license_cash_float_settings WHERE id = 1");
$cf_settings = mysqli_fetch_assoc($cf_settings_res) ?: ['direct_expense_id' => null];
$de_expense_id = intval($cf_settings['direct_expense_id'] ?? 0);

$de_configured = false;
$de_expense_row = null;

if ($de_expense_id) {
    $der = mysqli_query($conn, "SELECT * FROM expenses WHERE id = " . intval($de_expense_id) . " LIMIT 1");
    $de_expense_row = $der ? mysqli_fetch_assoc($der) : null;
    if ($de_expense_row) { $de_configured = true; }
}

$direct_expenses_list = rlDirectExpensesList($conn);

// ---------- Delete ----------
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $res = mysqli_query($conn, "SELECT cash_float_payment_id, direct_expense_payment_id FROM vehicle_revenue_license WHERE id = $id");
    $row = mysqli_fetch_assoc($res);
    mysqli_query($conn, "DELETE FROM vehicle_revenue_license_other_charges WHERE license_id = $id");
    if (mysqli_query($conn, "DELETE FROM vehicle_revenue_license WHERE id = $id")) {
        // Delete the linked Cash Float payment together with the license entry
        if ($row && !empty($row['cash_float_payment_id'])) {
            $cfp_id = intval($row['cash_float_payment_id']);
            mysqli_query($conn, "DELETE FROM cash_float_base_expense_payment_attachments WHERE payment_id = $cfp_id");
            mysqli_query($conn, "DELETE FROM cash_float_base_expense_payments WHERE id = $cfp_id");
        }
        // Delete the linked Direct Expense payment together with the license entry
        if ($row && !empty($row['direct_expense_payment_id'])) {
            $dep_id = intval($row['direct_expense_payment_id']);
            mysqli_query($conn, "DELETE FROM expense_payment_attachments WHERE payment_id = $dep_id");
            mysqli_query($conn, "DELETE FROM expense_payments WHERE id = $dep_id");
        }
        $success_message = "Revenue license record deleted successfully!";
    } else {
        $error_message = "Error deleting record: " . mysqli_error($conn);
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['action'])) {
    $vehicle_id   = intval($_POST['vehicle_id']);
    $renewal_date = mysqli_real_escape_string($conn, $_POST['renewal_date']);
    $year_range   = mysqli_real_escape_string($conn, $_POST['year_range']);
    $fitness      = floatval($_POST['fitness_cert_amount']);
    $ecotest      = floatval($_POST['ecotest_amount']);
    $revlic       = floatval($_POST['revenue_license_amount']);

    $other_desc = $_POST['other_desc'] ?? [];
    $other_amt  = $_POST['other_amount'] ?? [];
    $others_total = 0;
    $other_rows = [];
    foreach ($other_desc as $i => $desc) {
        $amt = floatval($other_amt[$i] ?? 0);
        $desc = trim($desc);
        if ($desc === '' && $amt == 0) continue;
        $others_total += $amt;
        $other_rows[] = ['description' => $desc, 'amount' => $amt];
    }

    $total_amount = $fitness + $ecotest + $revlic + $others_total;
    $cf_error = null;
    $is_edit = isset($_POST['license_id']) && !empty($_POST['license_id']);
    $remarks = mysqli_real_escape_string($conn, 'Revenue License — Lorry ' . rlVehicleNumber($conn, $vehicle_id) . ' renewal ' . $renewal_date);
    $uidv = rlCfCurrentUserId();
    $initSql = $uidv ? $uidv : 'NULL';

    if ($is_edit) {
        $id = intval($_POST['license_id']);
        $existing_res = mysqli_query($conn, "SELECT direct_expense_payment_id FROM vehicle_revenue_license WHERE id = $id");
        $existing = mysqli_fetch_assoc($existing_res);
        $existing_dep_id = intval($existing['direct_expense_payment_id'] ?? 0);
        $new_dep_id = $existing_dep_id;

        if ($de_configured) {
            $budget_id = $de_expense_row['budget_id'] ? intval($de_expense_row['budget_id']) : 0;
            $budgetSql = $budget_id ? $budget_id : 'NULL';

            if ($existing_dep_id) {
                mysqli_query($conn, "UPDATE expense_payments SET
                    payment_date = '$renewal_date', expense_id = $de_expense_id,
                    budget_id = $budgetSql, amount = $total_amount, remarks = '$remarks'
                    WHERE id = $existing_dep_id");
            } else {
                mysqli_query($conn, "INSERT INTO expense_payments (payment_date, expense_id, budget_id, amount, remarks, initiated_by, status)
                    VALUES ('$renewal_date', $de_expense_id, $budgetSql, $total_amount, '$remarks', $initSql, 'initiated')");
                $new_dep_id = mysqli_insert_id($conn);
                rlEpAutoProgressStatus($conn, $new_dep_id);
            }
        }

        if (!$cf_error) {
            $sql = "UPDATE vehicle_revenue_license SET
                    vehicle_id = $vehicle_id,
                    renewal_date = '$renewal_date',
                    year_range = '$year_range',
                    fitness_cert_amount = $fitness,
                    ecotest_amount = $ecotest,
                    revenue_license_amount = $revlic,
                    total_amount = $total_amount,
                    direct_expense_payment_id = " . ($new_dep_id ? $new_dep_id : "NULL") . "
                    WHERE id = $id";
            if (mysqli_query($conn, $sql)) {
                mysqli_query($conn, "DELETE FROM vehicle_revenue_license_other_charges WHERE license_id = $id");
                foreach ($other_rows as $r) {
                    $d = mysqli_real_escape_string($conn, $r['description']);
                    mysqli_query($conn, "INSERT INTO vehicle_revenue_license_other_charges (license_id, description, amount) VALUES ($id, '$d', {$r['amount']})");
                }
                $success_message = "Revenue license record updated successfully!";
            } else {
                $error_message = "Error: " . mysqli_error($conn);
            }
        } else {
            $error_message = $cf_error;
        }
    } else {
        $new_dep_id = null;

        if ($de_configured) {
            $budget_id = $de_expense_row['budget_id'] ? intval($de_expense_row['budget_id']) : 0;
            $budgetSql = $budget_id ? $budget_id : 'NULL';

            $ok = mysqli_query($conn, "INSERT INTO expense_payments (payment_date, expense_id, budget_id, amount, remarks, initiated_by, status)
                VALUES ('$renewal_date', $de_expense_id, $budgetSql, $total_amount, '$remarks', $initSql, 'initiated')");
            if ($ok) {
                $new_dep_id = mysqli_insert_id($conn);
                rlEpAutoProgressStatus($conn, $new_dep_id);
            } else {
                $cf_error = "Error creating Direct Expense payment: " . mysqli_error($conn);
            }
        }

        if (!$cf_error) {
            $sql = "INSERT INTO vehicle_revenue_license (vehicle_id, renewal_date, year_range, fitness_cert_amount, ecotest_amount, revenue_license_amount, total_amount, direct_expense_payment_id)
                    VALUES ($vehicle_id, '$renewal_date', '$year_range', $fitness, $ecotest, $revlic, $total_amount, " . ($new_dep_id ? $new_dep_id : "NULL") . ")";
            if (mysqli_query($conn, $sql)) {
                $id = mysqli_insert_id($conn);
                foreach ($other_rows as $r) {
                    $d = mysqli_real_escape_string($conn, $r['description']);
                    mysqli_query($conn, "INSERT INTO vehicle_revenue_license_other_charges (license_id, description, amount) VALUES ($id, '$d', {$r['amount']})");
                }
                $success_message = "Revenue license record added successfully!";
                if ($de_configured) { $success_message .= " Expense payment created and linked."; }
            } else {
                $error_message = "Error: " . mysqli_error($conn);
                if ($new_dep_id) { mysqli_query($conn, "DELETE FROM expense_payments WHERE id = $new_dep_id"); }
            }
        } else {
            $error_message = $cf_error;
        }
    }
}

$list_sql = "SELECT rl.*, v.vehicle_number, DATEDIFF(rl.renewal_date, CURDATE()) days_to_expiry
             FROM vehicle_revenue_license rl
             LEFT JOIN vehicles v ON rl.vehicle_id = v.id
             ORDER BY rl.renewal_date ASC";
$list_result = mysqli_query($conn, $list_sql);

$vehicles_result = mysqli_query($conn, "SELECT id, vehicle_number FROM vehicles ORDER BY vehicle_number");
$vehicles_list = [];
while ($v = mysqli_fetch_assoc($vehicles_result)) { $vehicles_list[] = $v; }

$year_ranges_result = mysqli_query($conn, "SELECT DISTINCT year_range FROM vehicle_revenue_license WHERE year_range IS NOT NULL AND year_range != '' ORDER BY year_range DESC");
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

function rlExpiryBadge($days) {
    if ($days === null) return '<span class="badge badge-neutral">-</span>';
    if ($days < 0) return '<span class="badge badge-danger"><i class="fa-solid fa-circle-exclamation"></i> Expired '.abs($days).'d ago</span>';
    if ($days < 30) return '<span class="badge badge-danger"><i class="fa-solid fa-triangle-exclamation"></i> '.$days.' days left</span>';
    if ($days < 90) return '<span class="badge badge-warning"><i class="fa-solid fa-clock"></i> '.$days.' days left</span>';
    return '<span class="badge badge-success"><i class="fa-solid fa-circle-check"></i> '.$days.' days left</span>';
}

include 'header.php';
?>

<div class="page-header">
    <h2 class="page-title">Vehicle Revenue License</h2>
    <p class="page-subtitle">Fitness certificate, eco test and revenue license renewals per lorry</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success_message; ?></div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?></div>
<?php endif; ?>

<div style="margin-bottom: 20px; display:flex; gap:10px; flex-wrap:wrap;">
    <button onclick="openModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add License Record</button>
    <button onclick="openCfSettingsModal()" class="btn btn-secondary"><i class="fa-solid fa-gear"></i> Expense Settings</button>
</div>

<!-- Expense Settings Widget -->
<?php if ($de_configured): ?>
<div class="content-card" style="margin-bottom:20px;">
    <h3 class="card-title"><i class="fa-solid fa-file-invoice-dollar"></i> Linked Direct Expense</h3>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <span class="badge badge-info"><i class="fa-solid fa-receipt"></i> Expense: <?php echo htmlspecialchars($de_expense_row['expense_name']); ?></span>
    </div>
    <p style="font-size:12px; color:#666; margin:12px 0 0;"><i class="fa-solid fa-circle-info"></i> Every Revenue License entry will create/update a direct Expense Payment against this expense (same workflow as expense_payments.php: Initiated → Authorized → Approved → Paid).</p>
</div>
<?php else: ?>
<div class="alert" style="background:#fffbeb; color:#92400e; border:1px solid #fde68a;">
    <i class="fa-solid fa-triangle-exclamation"></i> No Direct Expense is linked yet — license entries will be saved without an expense payment until you configure one via "Expense Settings".
</div>
<?php endif; ?>


<div class="content-card">
    <h3 class="card-title">Revenue License Records (sorted by renewal date)</h3>
    <?php if (mysqli_num_rows($list_result) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Lorry</th>
                    <th>Renewal Date</th>
                    <th>Expiry Status</th>
                    <th>Year Range</th>
                    <th>Fitness Cert</th>
                    <th>Eco Test</th>
                    <th>Rev. License</th>
                    <th>Total Amount</th>
                    <th>Expense Link</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = mysqli_fetch_assoc($list_result)): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['vehicle_number'] ?? '-'); ?></strong></td>
                    <td><?php echo date('d-M-Y', strtotime($row['renewal_date'])); ?></td>
                    <td><?php echo rlExpiryBadge($row['days_to_expiry']); ?></td>
                    <td><?php echo htmlspecialchars($row['year_range']) ?: '-'; ?></td>
                    <td><?php echo number_format($row['fitness_cert_amount'], 2); ?></td>
                    <td><?php echo number_format($row['ecotest_amount'], 2); ?></td>
                    <td><?php echo number_format($row['revenue_license_amount'], 2); ?></td>
                    <td><strong><?php echo number_format($row['total_amount'], 2); ?></strong></td>
                    <td>
                        <?php if (!empty($row['cash_float_payment_id'])): ?>
                            <span class="badge badge-success" title="Cash Float payment #<?php echo (int)$row['cash_float_payment_id']; ?>"><i class="fa-solid fa-link"></i> Cash Float</span>
                        <?php elseif (!empty($row['direct_expense_payment_id'])): ?>
                            <span class="badge badge-info" title="Direct Expense payment #<?php echo (int)$row['direct_expense_payment_id']; ?>"><i class="fa-solid fa-link"></i> Direct Expense</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="#" onclick="editLicense(<?php echo $row['id']; ?>); return false;" class="btn-action btn-edit" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <a href="?delete=<?php echo $row['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Delete this license record? This will also remove its linked Cash Float payment, if any.')"><i class="fa-solid fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="color: #666; font-size: 13px; text-align: center; padding: 20px;">No revenue license records found.</p>
    <?php endif; ?>
</div>

<!-- Expense Settings Modal -->
<div id="cfSettingsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Expense Settings</h3>
            <button class="modal-close" onclick="closeCfSettingsModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="" id="cfSettingsForm">
            <input type="hidden" name="action" value="save_cf_settings">
            <div class="modal-body">
                <p style="font-size:13px; color:#666; margin-top:0;">Choose which Expense every Revenue License entry's Total Amount should be recorded against as a direct Expense Payment (saved as the default for this page).</p>

                <div class="form-group">
                    <label class="form-label">Direct Expense <span class="required">*</span></label>
                    <select id="cf_direct_expense_id" name="cf_direct_expense_id" class="form-input select2-cf-direct" required>
                        <option value="">Select Expense</option>
                        <?php foreach ($direct_expenses_list as $de): ?>
                            <option value="<?php echo $de['id']; ?>" <?php echo ($de_expense_id == $de['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($de['expense_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Ordinary expenses (built on expenses.php / expense_payments.php). Creates a regular Expense Payment (Initiated → Authorized → Approved → Paid) per license entry.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCfSettingsModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save as Default</button>
            </div>
        </form>
    </div>
</div>


<!-- Modal -->
<div id="licenseModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add License Record</h3>
            <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="" id="licenseForm">
            <input type="hidden" name="license_id" id="license_id">
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
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label">Fitness Cert Amount</label>
                        <input type="number" step="0.01" id="fitness_cert_amount" name="fitness_cert_amount" class="form-input calc-total" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Eco Test Amount</label>
                        <input type="number" step="0.01" id="ecotest_amount" name="ecotest_amount" class="form-input calc-total" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Revenue License Amount</label>
                        <input type="number" step="0.01" id="revenue_license_amount" name="revenue_license_amount" class="form-input calc-total" value="0">
                    </div>
                </div>

                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-list"></i> Other Charges</h4>
                    <div id="otherChargesRows"></div>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="addOtherChargeRow()"><i class="fa-solid fa-plus"></i> Add Charge Row</button>
                </div>

                <div class="balance-bar ok" id="totalBar">
                    <span>Total Amount</span>
                    <span id="totalDisplay">0.00</span>
                </div>

                <?php if ($de_configured): ?>
                <p style="font-size:12px; color:#666; margin:14px 0 0;"><i class="fa-solid fa-circle-info"></i> Saving this entry will automatically create/update a Direct Expense payment (for the Total Amount) against "<?php echo htmlspecialchars($de_expense_row['expense_name']); ?>".</p>
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
let otherChargeIndex = 0;

function addOtherChargeRow(desc, amount) {
    desc = desc || '';
    amount = amount !== undefined ? amount : '';
    const wrap = document.getElementById('otherChargesRows');
    const row = document.createElement('div');
    row.className = 'repeat-row';
    row.style.gridTemplateColumns = '2fr 1fr auto';
    row.innerHTML = `
        <div class="form-group" style="margin-bottom:0;">
            <input type="text" name="other_desc[]" class="form-input" placeholder="Description" value="${desc.replace(/"/g,'&quot;')}">
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <input type="number" step="0.01" name="other_amount[]" class="form-input calc-total" placeholder="Amount" value="${amount}">
        </div>
        <button type="button" class="repeat-remove" onclick="this.closest('.repeat-row').remove(); recalcTotal();"><i class="fa-solid fa-trash"></i></button>
    `;
    wrap.appendChild(row);
    row.querySelector('.calc-total').addEventListener('input', recalcTotal);
    otherChargeIndex++;
}

function recalcTotal() {
    let total = 0;
    document.querySelectorAll('.calc-total').forEach(inp => {
        total += parseFloat(inp.value) || 0;
    });
    document.getElementById('totalDisplay').textContent = total.toFixed(2);
}

function getDefaultYearRange() {
    const y = new Date().getFullYear();
    return y + '-' + (y + 1);
}

function openModal() {
    document.getElementById('licenseModal').classList.add('active');
    document.getElementById('licenseForm').reset();
    document.getElementById('license_id').value = '';
    document.getElementById('otherChargesRows').innerHTML = '';
    document.getElementById('modalTitle').textContent = 'Add License Record';
    document.getElementById('submitBtnText').textContent = 'Save Record';
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
    recalcTotal();
}

function closeModal() {
    document.getElementById('licenseModal').classList.remove('active');
}

function editLicense(id) {
    fetch('vehicle_revenue_license.php?ajax=get&id=' + id)
        .then(response => response.json())
        .then(data => {
            document.getElementById('licenseModal').classList.add('active');
            document.getElementById('license_id').value = data.id;
            document.getElementById('renewal_date').value = data.renewal_date;
            document.getElementById('fitness_cert_amount').value = data.fitness_cert_amount;
            document.getElementById('ecotest_amount').value = data.ecotest_amount;
            document.getElementById('revenue_license_amount').value = data.revenue_license_amount;

            document.getElementById('otherChargesRows').innerHTML = '';
            (data.other_charges || []).forEach(c => addOtherChargeRow(c.description, c.amount));

            if (window.jQuery) {
                $('#vehicle_id').val(data.vehicle_id).trigger('change');
                if (data.year_range) {
                    if ($('#year_range option[value="' + data.year_range + '"]').length === 0) {
                        $('#year_range').append(new Option(data.year_range, data.year_range, true, true));
                    }
                    $('#year_range').val(data.year_range).trigger('change');
                }
            } else {
                document.getElementById('vehicle_id').value = data.vehicle_id;
                document.getElementById('year_range').value = data.year_range || '';
            }

            recalcTotal();
            document.getElementById('modalTitle').textContent = 'Edit License Record';
            document.getElementById('submitBtnText').textContent = 'Update Record';
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading license data');
        });
}

// ---------- Expense Settings Modal ----------
function openCfSettingsModal() {
    document.getElementById('cfSettingsModal').classList.add('active');
}

function closeCfSettingsModal() {
    document.getElementById('cfSettingsModal').classList.remove('active');
}

document.addEventListener('DOMContentLoaded', function() {
    if (window.jQuery && $.fn.select2) {
        $('.select2-vehicle').select2({ width: '100%', placeholder: 'Select Lorry' });
        $('.select2-tags').select2({ width: '100%', tags: true, placeholder: 'Select or type a year range' });
        $('.select2-cf-direct').select2({ width: '100%', placeholder: 'Select Direct Expense', dropdownParent: $('#cfSettingsModal') });
    }
    document.querySelectorAll('.calc-total').forEach(inp => inp.addEventListener('input', recalcTotal));
    recalcTotal();
});
</script>


<?php include 'footer.php'; ?>