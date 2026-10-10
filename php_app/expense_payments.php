<?php
// ── Yelo Group HMS — Expense Payments ────────────────────────────────────────
// Workflow: Initiator creates (status=initiated) -> Authorizer authorizes (status=authorized)
//           -> Approver approves (status=approved) -> Approver can mark Paid (status=paid).
// Authorizer/Approver can reject at initiated/authorized stage. Initiator can cancel their
// own request while it is still initiated/authorized. A Manual Status Override is available
// to anyone who is an authorizer or approver on the linked expense.
//
// AUTO-PROGRESSION RULE: if the linked expense has NO authorizer(s) assigned, the payment
// automatically skips the Authorized stage and moves straight to Approved-eligible (or
// straight to Approved, if there is also no approver). Likewise, if the expense has NO
// approver(s) assigned, the payment automatically skips the Approve stage as soon as it
// reaches "authorized". This is evaluated every time a payment is saved (created/edited),
// since the expense's role lists can change over time. "Mark as Paid" is never
// auto-triggered — it always requires an explicit approver action.
//
// DELETE POLICY: any user can delete any Expense Payment row, at any status, with no
// permission check. The Delete button is shown unconditionally on every row and in the
// View modal. Do not reintroduce an initiator/approver or status gate on delete unless
// explicitly asked.

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/expense_payments_error.log');

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (file_exists(__DIR__ . '/config.php')) {
    include __DIR__ . '/config.php';
} else {
    $host = '127.0.0.1';
    $db   = 'u645685294_ylerp';
    $user = 'your_db_user';
    $pass = 'your_db_pass';
    $conn = mysqli_connect($host, $user, $pass, $db);
}

if (!$conn || mysqli_connect_errno()) {
    if (isset($_SERVER['REQUEST_METHOD']) && (
        ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['ajax_save']) || isset($_POST['ajax_delete']) || isset($_POST['ajax_update_status']) ||
            isset($_POST['ajax_authorize']) || isset($_POST['ajax_unauthorize']) || isset($_POST['ajax_approve']) ||
            isset($_POST['ajax_reject']) || isset($_POST['ajax_cancel']) || isset($_POST['ajax_mark_paid']))) ||
        isset($_GET['ajax_load']) || isset($_GET['ajax_get_budget_status'])
    )) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . mysqli_connect_error()]);
        exit;
    }
    die('<h3 style="color:red;font-family:sans-serif;padding:20px;">Database connection failed: ' . htmlspecialchars(mysqli_connect_error()) . '</h3>');
}

mysqli_set_charset($conn, 'utf8mb4');

// ── Ensure tables exist ────────────────────────────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS expense_payments (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        payment_date  DATE NOT NULL,
        expense_id    INT NOT NULL,
        budget_id     INT NULL,
        amount        DECIMAL(14,2) NOT NULL DEFAULT 0,
        remarks       VARCHAR(500) NULL,
        initiated_by  INT NULL,
        status        ENUM('initiated','authorized','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'initiated',
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_payment_date (payment_date),
        INDEX idx_expense (expense_id),
        INDEX idx_budget (budget_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS expense_payment_attachments (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        payment_id   INT NOT NULL,
        file_name    VARCHAR(255) NOT NULL,
        file_path    VARCHAR(500) NOT NULL,
        file_size    INT NULL,
        uploaded_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_payment (payment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Migration: rename legacy created_by -> initiated_by (if upgrading an older install) ─
$colCheckInit = mysqli_query($conn, "SHOW COLUMNS FROM expense_payments LIKE 'initiated_by'");
if ($colCheckInit && mysqli_num_rows($colCheckInit) === 0) {
    $colCheckCreated = mysqli_query($conn, "SHOW COLUMNS FROM expense_payments LIKE 'created_by'");
    if ($colCheckCreated && mysqli_num_rows($colCheckCreated) > 0) {
        mysqli_query($conn, "ALTER TABLE expense_payments CHANGE COLUMN created_by initiated_by INT NULL");
    } else {
        mysqli_query($conn, "ALTER TABLE expense_payments ADD COLUMN initiated_by INT NULL AFTER remarks");
    }
}

// ── Migration: widen/narrow status enum (created -> initiated) + add workflow audit cols ─
function epAddColumnIfMissing($conn, $table, $col, $definitionSql) {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM $table LIKE '$col'");
    if ($r && mysqli_num_rows($r) === 0) {
        mysqli_query($conn, "ALTER TABLE $table ADD COLUMN $definitionSql");
    }
}

$statusColInfo = mysqli_query($conn, "SHOW COLUMNS FROM expense_payments LIKE 'status'");
$needsStatusMigration = true;
if ($statusColInfo && ($sci = mysqli_fetch_assoc($statusColInfo))) {
    if (strpos($sci['Type'], 'initiated') !== false) { $needsStatusMigration = false; }
}
if ($needsStatusMigration) {
    // Step 1: widen enum so old 'created' values remain valid alongside the new set
    mysqli_query($conn, "ALTER TABLE expense_payments MODIFY status ENUM('created','initiated','authorized','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'created'");
    // Step 2: migrate old data
    mysqli_query($conn, "UPDATE expense_payments SET status = 'initiated' WHERE status = 'created'");
    // Step 3: narrow enum to the final workflow states
    mysqli_query($conn, "ALTER TABLE expense_payments MODIFY status ENUM('initiated','authorized','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'initiated'");
}

epAddColumnIfMissing($conn, 'expense_payments', 'authorized_by',  'authorized_by INT NULL AFTER initiated_by');
epAddColumnIfMissing($conn, 'expense_payments', 'authorized_at',  'authorized_at DATETIME NULL AFTER authorized_by');
epAddColumnIfMissing($conn, 'expense_payments', 'approved_by',    'approved_by INT NULL AFTER authorized_at');
epAddColumnIfMissing($conn, 'expense_payments', 'approved_at',    'approved_at DATETIME NULL AFTER approved_by');
epAddColumnIfMissing($conn, 'expense_payments', 'paid_by',        'paid_by INT NULL AFTER approved_at');
epAddColumnIfMissing($conn, 'expense_payments', 'paid_at',        'paid_at DATETIME NULL AFTER paid_by');
epAddColumnIfMissing($conn, 'expense_payments', 'rejected_by',    'rejected_by INT NULL AFTER paid_at');
epAddColumnIfMissing($conn, 'expense_payments', 'rejected_at',    'rejected_at DATETIME NULL AFTER rejected_by');
epAddColumnIfMissing($conn, 'expense_payments', 'reject_remarks', 'reject_remarks VARCHAR(500) NULL AFTER rejected_at');
epAddColumnIfMissing($conn, 'expense_payments', 'cancelled_by',   'cancelled_by INT NULL AFTER reject_remarks');
epAddColumnIfMissing($conn, 'expense_payments', 'cancelled_at',   'cancelled_at DATETIME NULL AFTER cancelled_by');
epAddColumnIfMissing($conn, 'expense_payments', 'cancel_remarks', 'cancel_remarks VARCHAR(500) NULL AFTER cancelled_at');
epAddColumnIfMissing($conn, 'expense_payments', 'auto_authorized', 'auto_authorized TINYINT(1) NOT NULL DEFAULT 0 AFTER cancel_remarks');
epAddColumnIfMissing($conn, 'expense_payments', 'auto_approved',   'auto_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER auto_authorized');

// ── System reference: EXP-PAY-000123 (built from the system ID, never changes) ──
function epSystemRef($id) { return 'EXP-PAY-' . str_pad((string)(int)$id, 6, '0', STR_PAD_LEFT); }
epAddColumnIfMissing($conn, 'expense_payments', 'system_ref', 'system_ref VARCHAR(30) NULL AFTER id');
$srIdx = mysqli_query($conn, "SHOW INDEX FROM expense_payments WHERE Key_name = 'uq_system_ref'");
if ($srIdx && mysqli_num_rows($srIdx) === 0) {
    @mysqli_query($conn, "ALTER TABLE expense_payments ADD UNIQUE KEY uq_system_ref (system_ref)");
}
// payments saved before this column existed get their ref once
mysqli_query($conn, "UPDATE expense_payments SET system_ref = CONCAT('EXP-PAY-', LPAD(id, 6, '0')) WHERE system_ref IS NULL OR system_ref = ''");

// ── Upload settings ───────────────────────────────────────────────────────────
$UPLOAD_DIR_REL = 'uploads/expense_payments/';
$UPLOAD_DIR_ABS = __DIR__ . '/' . $UPLOAD_DIR_REL;
if (!is_dir($UPLOAD_DIR_ABS)) { @mkdir($UPLOAD_DIR_ABS, 0755, true); }

$ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
$MAX_FILE_SIZE = 8 * 1024 * 1024; // 8MB
$STATUS_LIST = ['initiated', 'authorized', 'approved', 'paid', 'rejected', 'cancelled'];

// ── Helpers ─────────────────────────────────────────────────────────────────────
function epFormatBytes($bytes) {
    $bytes = (int)$bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes, 1024));
    $i = max(0, min($i, count($units) - 1));
    return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
}

function epStatusLabel($s) {
    $m = ['initiated' => 'Initiated', 'authorized' => 'Authorized', 'approved' => 'Approved', 'paid' => 'Paid', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
    return $m[$s] ?? ucfirst($s);
}

// Current logged-in user id, tolerant of whichever session key this HMS install uses.
function epCurrentUserId() {
    foreach (['user_id', 'uid', 'id', 'emp_id', 'employee_id'] as $key) {
        if (isset($_SESSION[$key]) && intval($_SESSION[$key]) > 0) {
            return (int)$_SESSION[$key];
        }
    }
    return 0;
}

function epJsonIds($str) {
    if (!$str) return [];
    $arr = json_decode($str, true);
    return is_array($arr) ? array_map('intval', $arr) : [];
}

// Role lists (initiater/authorizer/approver ids) per expense, keyed by expense id.
function epGetExpenseRoleMap($conn) {
    $map = [];
    $r = mysqli_query($conn, "SELECT id, initiater_ids, authorizer_ids, approver_ids FROM expenses");
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            $map[(int)$row['id']] = [
                'initiater_ids'  => epJsonIds($row['initiater_ids']),
                'authorizer_ids' => epJsonIds($row['authorizer_ids']),
                'approver_ids'   => epJsonIds($row['approver_ids']),
            ];
        }
    }
    return $map;
}

// Fetch a single payment + its linked expense's role lists (used by all workflow endpoints).
function epGetPaymentWithRoles($conn, $id) {
    $id = intval($id);
    if (!$id) return null;
    $r = mysqli_query($conn, "
        SELECT ep.*, e.initiater_ids, e.authorizer_ids, e.approver_ids
        FROM expense_payments ep
        LEFT JOIN expenses e ON e.id = ep.expense_id
        WHERE ep.id = $id
        LIMIT 1
    ");
    if (!$r || mysqli_num_rows($r) === 0) return null;
    $row = mysqli_fetch_assoc($r);
    $row['initiater_ids']  = epJsonIds($row['initiater_ids']);
    $row['authorizer_ids'] = epJsonIds($row['authorizer_ids']);
    $row['approver_ids']   = epJsonIds($row['approver_ids']);
    return $row;
}

// ── Auto-progression: skip stages that have nobody assigned to action them ──────
// Rules:
//   - While status is 'initiated' and the expense has NO authorizer(s) assigned,
//     auto-advance to 'authorized' (flagged auto_authorized = 1, authorized_by = NULL).
//   - While status is 'authorized' and the expense has NO approver(s) assigned,
//     auto-advance to 'approved' (flagged auto_approved = 1, approved_by = NULL).
//   - This can cascade: no authorizer AND no approver -> goes straight to 'approved'.
//   - Never touches 'paid', 'rejected', or 'cancelled' payments.
//   - Returns the final status string after any auto-progression.
function epAutoProgressStatus($conn, $paymentId) {
    $paymentId = intval($paymentId);
    if (!$paymentId) return null;

    $p = epGetPaymentWithRoles($conn, $paymentId);
    if (!$p) return null;

    $status = $p['status'];

    if ($status === 'initiated' && empty($p['authorizer_ids'])) {
        mysqli_query($conn, "
            UPDATE expense_payments
            SET status = 'authorized', authorized_by = NULL, authorized_at = NOW(), auto_authorized = 1
            WHERE id = $paymentId
            LIMIT 1
        ");
        $status = 'authorized';
    }

    if ($status === 'authorized' && empty($p['approver_ids'])) {
        mysqli_query($conn, "
            UPDATE expense_payments
            SET status = 'approved', approved_by = NULL, approved_at = NOW(), auto_approved = 1
            WHERE id = $paymentId
            LIMIT 1
        ");
        $status = 'approved';
    }

    return $status;
}

// Compute a budget's limit / spent / remaining for the period that covers $payment_date,
// based on the expense's linked budget (monthly or annually recurring window).
function getBudgetStatusForExpense($conn, $expense_id, $payment_date, $exclude_payment_id = 0) {
    $result = [
        'has_budget'   => false,
        'budget_id'    => null,
        'budget_name'  => null,
        'duration'     => null,
        'limit_amount' => 0,
        'period_label' => null,
        'period_start' => null,
        'period_end'   => null,
        'spent'        => 0,
        'remaining'    => 0,
    ];

    $expense_id = intval($expense_id);
    if (!$expense_id) return $result;

    $er = mysqli_query($conn, "SELECT budget_id FROM expenses WHERE id = $expense_id LIMIT 1");
    if (!$er || mysqli_num_rows($er) === 0) return $result;
    $erow = mysqli_fetch_assoc($er);
    $budget_id = $erow['budget_id'] ? intval($erow['budget_id']) : 0;
    if (!$budget_id) return $result;

    $br = mysqli_query($conn, "SELECT id, budget_name, limit_amount, duration FROM budgets WHERE id = $budget_id LIMIT 1");
    if (!$br || mysqli_num_rows($br) === 0) return $result;
    $brow = mysqli_fetch_assoc($br);

    $date = ($payment_date && strtotime($payment_date)) ? $payment_date : date('Y-m-d');
    $ts   = strtotime($date);

    if ($brow['duration'] === 'annually') {
        $period_start = date('Y-01-01', $ts);
        $period_end   = date('Y-12-31', $ts);
        $period_label = date('Y', $ts);
    } else {
        $period_start = date('Y-m-01', $ts);
        $period_end   = date('Y-m-t', $ts);
        $period_label = date('F Y', $ts);
    }

    $exclude_payment_id = intval($exclude_payment_id);
    $excludeSql = $exclude_payment_id ? " AND id != $exclude_payment_id" : "";

    $sr = mysqli_query($conn, "
        SELECT COALESCE(SUM(amount),0) AS spent
        FROM expense_payments
        WHERE budget_id = $budget_id
          AND status NOT IN ('rejected','cancelled')
          AND payment_date BETWEEN '$period_start' AND '$period_end'
          $excludeSql
    ");
    $spent = 0;
    if ($sr && $srow = mysqli_fetch_assoc($sr)) { $spent = (float)$srow['spent']; }

    $limit = (float)$brow['limit_amount'];

    $result = [
        'has_budget'   => true,
        'budget_id'    => (int)$brow['id'],
        'budget_name'  => $brow['budget_name'],
        'duration'     => $brow['duration'],
        'limit_amount' => $limit,
        'period_label' => $period_label,
        'period_start' => $period_start,
        'period_end'   => $period_end,
        'spent'        => $spent,
        'remaining'    => $limit - $spent,
    ];
    return $result;
}

// ── AJAX: Get budget status for an expense (used live in the Add/Edit modal) ────
if (isset($_GET['ajax_get_budget_status'])) {
    header('Content-Type: application/json');
    $expense_id   = intval($_GET['expense_id'] ?? 0);
    $payment_date = trim($_GET['payment_date'] ?? '');
    $exclude_id   = intval($_GET['exclude_id'] ?? 0);
    $status = getBudgetStatusForExpense($conn, $expense_id, $payment_date, $exclude_id);
    echo json_encode(['success' => true, 'budget_status' => $status]);
    exit;
}

// ── AJAX: Manual status override (authorizer/approver only) ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update_status'])) {
    header('Content-Type: application/json');

    $id     = intval($_POST['id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $uid    = epCurrentUserId();

    if (!$id || !in_array($status, $STATUS_LIST, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    $p = epGetPaymentWithRoles($conn, $id);
    if (!$p) { echo json_encode(['success' => false, 'message' => 'Payment not found.']); exit; }

    $isAuthorizer = in_array($uid, $p['authorizer_ids'], true);
    $isApprover   = in_array($uid, $p['approver_ids'], true);
    if (!$isAuthorizer && !$isApprover) {
        echo json_encode(['success' => false, 'message' => 'Only an authorizer or approver of this expense can manually override the status.']);
        exit;
    }

    // Reset downstream audit fields, then stamp whichever stage was just set.
    // A manual override always counts as a deliberate human action, so clear the auto_* flags too.
    $set = "status = '$status', authorized_by = NULL, authorized_at = NULL, approved_by = NULL, approved_at = NULL,
            paid_by = NULL, paid_at = NULL, rejected_by = NULL, rejected_at = NULL, cancelled_by = NULL, cancelled_at = NULL,
            auto_authorized = 0, auto_approved = 0";
    if ($status === 'authorized') { $set .= ", authorized_by = $uid, authorized_at = NOW()"; }
    if ($status === 'approved')   { $set .= ", approved_by = $uid, approved_at = NOW()"; }
    if ($status === 'paid')       { $set .= ", paid_by = $uid, paid_at = NOW()"; }
    if ($status === 'rejected')   { $set .= ", rejected_by = $uid, rejected_at = NOW()"; }
    if ($status === 'cancelled')  { $set .= ", cancelled_by = $uid, cancelled_at = NOW()"; }

    $ok = mysqli_query($conn, "UPDATE expense_payments SET $set WHERE id = $id LIMIT 1");
    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'Status manually set to ' . epStatusLabel($status) . '.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Authorize (authorizer only, initiated -> authorized) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_authorize'])) {
    header('Content-Type: application/json');
    $id  = intval($_POST['id'] ?? 0);
    $uid = epCurrentUserId();
    $p = epGetPaymentWithRoles($conn, $id);
    if (!$p) { echo json_encode(['success' => false, 'message' => 'Payment not found.']); exit; }
    if (!in_array($uid, $p['authorizer_ids'], true)) {
        echo json_encode(['success' => false, 'message' => 'You are not an authorizer for this expense.']); exit;
    }
    if ($p['status'] !== 'initiated') {
        echo json_encode(['success' => false, 'message' => 'Only Initiated payments can be authorized.']); exit;
    }
    $ok = mysqli_query($conn, "UPDATE expense_payments SET status = 'authorized', authorized_by = $uid, authorized_at = NOW(), auto_authorized = 0 WHERE id = $id LIMIT 1");
    echo json_encode($ok ? ['success' => true, 'message' => 'Payment authorized.'] : ['success' => false, 'message' => mysqli_error($conn)]);
    exit;
}

// ── AJAX: Unauthorize (authorizer only, authorized -> initiated) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_unauthorize'])) {
    header('Content-Type: application/json');
    $id  = intval($_POST['id'] ?? 0);
    $uid = epCurrentUserId();
    $p = epGetPaymentWithRoles($conn, $id);
    if (!$p) { echo json_encode(['success' => false, 'message' => 'Payment not found.']); exit; }
    if (!in_array($uid, $p['authorizer_ids'], true)) {
        echo json_encode(['success' => false, 'message' => 'You are not an authorizer for this expense.']); exit;
    }
    if ($p['status'] !== 'authorized') {
        echo json_encode(['success' => false, 'message' => 'Only Authorized payments can be reverted back to Initiated.']); exit;
    }
    $ok = mysqli_query($conn, "UPDATE expense_payments SET status = 'initiated', authorized_by = NULL, authorized_at = NULL, auto_authorized = 0 WHERE id = $id LIMIT 1");
    echo json_encode($ok ? ['success' => true, 'message' => 'Authorization reverted — payment is back to Initiated.'] : ['success' => false, 'message' => mysqli_error($conn)]);
    exit;
}

// ── AJAX: Approve (approver only, authorized -> approved) ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_approve'])) {
    header('Content-Type: application/json');
    $id  = intval($_POST['id'] ?? 0);
    $uid = epCurrentUserId();
    $p = epGetPaymentWithRoles($conn, $id);
    if (!$p) { echo json_encode(['success' => false, 'message' => 'Payment not found.']); exit; }
    if (!in_array($uid, $p['approver_ids'], true)) {
        echo json_encode(['success' => false, 'message' => 'You are not an approver for this expense.']); exit;
    }
    if ($p['status'] !== 'authorized') {
        echo json_encode(['success' => false, 'message' => 'Only Authorized payments can be approved.']); exit;
    }
    $ok = mysqli_query($conn, "UPDATE expense_payments SET status = 'approved', approved_by = $uid, approved_at = NOW(), auto_approved = 0 WHERE id = $id LIMIT 1");
    echo json_encode($ok ? ['success' => true, 'message' => 'Payment approved.'] : ['success' => false, 'message' => mysqli_error($conn)]);
    exit;
}

// ── AJAX: Mark Paid (approver only, approved -> paid) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_mark_paid'])) {
    header('Content-Type: application/json');
    $id  = intval($_POST['id'] ?? 0);
    $uid = epCurrentUserId();
    $p = epGetPaymentWithRoles($conn, $id);
    if (!$p) { echo json_encode(['success' => false, 'message' => 'Payment not found.']); exit; }
    if (!in_array($uid, $p['approver_ids'], true)) {
        echo json_encode(['success' => false, 'message' => 'You are not an approver for this expense.']); exit;
    }
    if ($p['status'] !== 'approved') {
        echo json_encode(['success' => false, 'message' => 'Only Approved payments can be marked as Paid.']); exit;
    }
    $ok = mysqli_query($conn, "UPDATE expense_payments SET status = 'paid', paid_by = $uid, paid_at = NOW() WHERE id = $id LIMIT 1");
    echo json_encode($ok ? ['success' => true, 'message' => 'Payment marked as Paid.'] : ['success' => false, 'message' => mysqli_error($conn)]);
    exit;
}

// ── AJAX: Reject (authorizer or approver, initiated/authorized -> rejected) ─────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_reject'])) {
    header('Content-Type: application/json');
    $id      = intval($_POST['id'] ?? 0);
    $uid     = epCurrentUserId();
    $remarks = mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''));
    $p = epGetPaymentWithRoles($conn, $id);
    if (!$p) { echo json_encode(['success' => false, 'message' => 'Payment not found.']); exit; }
    $isAuthorizer = in_array($uid, $p['authorizer_ids'], true);
    $isApprover   = in_array($uid, $p['approver_ids'], true);
    if (!$isAuthorizer && !$isApprover) {
        echo json_encode(['success' => false, 'message' => 'You are not an authorizer or approver for this expense.']); exit;
    }
    if (!in_array($p['status'], ['initiated', 'authorized'], true)) {
        echo json_encode(['success' => false, 'message' => 'Only Initiated or Authorized payments can be rejected.']); exit;
    }
    $ok = mysqli_query($conn, "UPDATE expense_payments SET status = 'rejected', rejected_by = $uid, rejected_at = NOW(), reject_remarks = '$remarks' WHERE id = $id LIMIT 1");
    echo json_encode($ok ? ['success' => true, 'message' => 'Payment rejected.'] : ['success' => false, 'message' => mysqli_error($conn)]);
    exit;
}

// ── AJAX: Cancel (initiator only, initiated/authorized -> cancelled) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_cancel'])) {
    header('Content-Type: application/json');
    $id      = intval($_POST['id'] ?? 0);
    $uid     = epCurrentUserId();
    $remarks = mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''));
    $p = epGetPaymentWithRoles($conn, $id);
    if (!$p) { echo json_encode(['success' => false, 'message' => 'Payment not found.']); exit; }
    if (!in_array($uid, $p['initiater_ids'], true)) {
        echo json_encode(['success' => false, 'message' => 'You are not an initiator for this expense.']); exit;
    }
    if (!in_array($p['status'], ['initiated', 'authorized'], true)) {
        echo json_encode(['success' => false, 'message' => 'This payment can no longer be cancelled.']); exit;
    }
    $ok = mysqli_query($conn, "UPDATE expense_payments SET status = 'cancelled', cancelled_by = $uid, cancelled_at = NOW(), cancel_remarks = '$remarks' WHERE id = $id LIMIT 1");
    echo json_encode($ok ? ['success' => true, 'message' => 'Payment cancelled.'] : ['success' => false, 'message' => mysqli_error($conn)]);
    exit;
}

// ── AJAX: Delete a payment (and its attachment files) ────────────────────────────
// UNCONDITIONAL DELETE: any user can delete any Expense Payment row, at any status
// (Initiated/Authorized/Approved/Paid/Rejected/Cancelled). No permission or status
// check is performed. Intentional per current requirements — do not reintroduce a
// gate here without being asked.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete'])) {
    header('Content-Type: application/json');

    $id = intval($_POST['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Invalid record.']);
        exit;
    }

    $p = epGetPaymentWithRoles($conn, $id);
    if (!$p) { echo json_encode(['success' => false, 'message' => 'Payment not found.']); exit; }

    $ar = mysqli_query($conn, "SELECT file_path FROM expense_payment_attachments WHERE payment_id = $id");
    if ($ar) {
        while ($arow = mysqli_fetch_assoc($ar)) {
            $fp = __DIR__ . '/' . $arow['file_path'];
            if (is_file($fp)) { @unlink($fp); }
        }
    }
    mysqli_query($conn, "DELETE FROM expense_payment_attachments WHERE payment_id = $id");

    $ok = mysqli_query($conn, "DELETE FROM expense_payments WHERE id = $id LIMIT 1");
    if ($ok) {
        // Reconciled with the bank (expense_payment_bank_recon.php)? → clear that bank line and the link.
        $tb = mysqli_query($conn, "SHOW TABLES LIKE 'epr_recon'");
        if ($tb && mysqli_num_rows($tb) > 0) {
            $rr = mysqli_query($conn, "SELECT id FROM epr_recon WHERE payment_id = $id");
            while ($rr && ($rrow = mysqli_fetch_assoc($rr))) {
                $rid = (int)$rrow['id'];
                mysqli_query($conn, "UPDATE bank_statement_transactions
                                        SET recon_status = NULL, recon_source = NULL, recon_category = NULL, recon_ref_id = NULL,
                                            recon_remark = NULL, recon_by = NULL, recon_at = NULL
                                      WHERE recon_source = 'expense_pay_recon' AND recon_ref_id = $rid");
                mysqli_query($conn, "DELETE FROM epr_recon_bank WHERE recon_id = $rid");
                mysqli_query($conn, "DELETE FROM epr_recon WHERE id = $rid");
            }
        }
        echo json_encode(['success' => true, 'message' => 'Expense payment deleted.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Save (insert or update) an expense payment + attachments ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');

    $id           = intval($_POST['id'] ?? 0);
    $payment_date = trim($_POST['payment_date'] ?? '');
    $expense_id   = intval($_POST['expense_id'] ?? 0);
    $amount       = trim($_POST['amount'] ?? '');
    $remarks      = mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''));
    $removedIds   = array_map('intval', $_POST['removed_attachment_ids'] ?? []);
    $uid          = epCurrentUserId();

    if ($payment_date === '' || !strtotime($payment_date)) {
        echo json_encode(['success' => false, 'message' => 'Please select a valid payment date.']);
        exit;
    }
    if (!$expense_id) {
        echo json_encode(['success' => false, 'message' => 'Please select an expense.']);
        exit;
    }

    $er = mysqli_query($conn, "SELECT id, budget_id, initiater_ids FROM expenses WHERE id = $expense_id LIMIT 1");
    if (!$er || mysqli_num_rows($er) === 0) {
        echo json_encode(['success' => false, 'message' => 'Selected expense was not found.']);
        exit;
    }
    $erow          = mysqli_fetch_assoc($er);
    $budget_id     = $erow['budget_id'] ? intval($erow['budget_id']) : 0;
    $initiaterIds  = epJsonIds($erow['initiater_ids']);

    if (!in_array($uid, $initiaterIds, true)) {
        echo json_encode(['success' => false, 'message' => 'You are not set up as an initiator for this expense, so you cannot add a payment against it.']);
        exit;
    }

    if ($amount === '' || !is_numeric($amount) || floatval($amount) <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid payment amount.']);
        exit;
    }
    $amount = floatval($amount);

    $payment_date_esc = mysqli_real_escape_string($conn, $payment_date);
    $budgetSql = $budget_id ? intval($budget_id) : 'NULL';

    if ($id > 0) {
        // Editing — only the record's own initiator(s), while still Initiated, may edit.
        $existing = epGetPaymentWithRoles($conn, $id);
        if (!$existing) {
            echo json_encode(['success' => false, 'message' => 'Payment not found.']);
            exit;
        }
        if ($existing['status'] !== 'initiated') {
            echo json_encode(['success' => false, 'message' => 'This payment can no longer be edited — it has already moved past the Initiated stage.']);
            exit;
        }
        if (!in_array($uid, $existing['initiater_ids'], true)) {
            echo json_encode(['success' => false, 'message' => 'You do not have permission to edit this payment.']);
            exit;
        }

        $ok = mysqli_query($conn, "
            UPDATE expense_payments SET
                payment_date = '$payment_date_esc',
                expense_id   = $expense_id,
                budget_id    = $budgetSql,
                amount       = $amount,
                remarks      = '$remarks'
            WHERE id = $id
        ");
        $newId = $id;
        $isNew = false;
    } else {
        $initiatedBySql = $uid ? $uid : 'NULL';
        $ok = mysqli_query($conn, "
            INSERT INTO expense_payments (payment_date, expense_id, budget_id, amount, remarks, initiated_by, status)
            VALUES ('$payment_date_esc', $expense_id, $budgetSql, $amount, '$remarks', $initiatedBySql, 'initiated')
        ");
        $newId = $ok ? mysqli_insert_id($conn) : 0;
        if ($newId) {
            mysqli_query($conn, "UPDATE expense_payments SET system_ref = '" . epSystemRef($newId) . "' WHERE id = " . (int)$newId);
        }
        $isNew = true;
    }

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
        exit;
    }

    // Remove attachments the user unchecked during edit
    if (!$isNew && !empty($removedIds)) {
        $idsList = implode(',', array_filter($removedIds));
        if ($idsList !== '') {
            $ar = mysqli_query($conn, "SELECT id, file_path FROM expense_payment_attachments WHERE id IN ($idsList) AND payment_id = $newId");
            if ($ar) {
                while ($arow = mysqli_fetch_assoc($ar)) {
                    $fp = __DIR__ . '/' . $arow['file_path'];
                    if (is_file($fp)) { @unlink($fp); }
                }
            }
            mysqli_query($conn, "DELETE FROM expense_payment_attachments WHERE id IN ($idsList) AND payment_id = $newId");
        }
    }

    // Handle new attachment uploads (multiple)
    global $UPLOAD_DIR_REL, $UPLOAD_DIR_ABS, $ALLOWED_EXT, $MAX_FILE_SIZE;
    $uploadErrors = [];

    if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
        $count = count($_FILES['attachments']['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($_FILES['attachments']['name'][$i] === '') continue;
            if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) {
                $uploadErrors[] = $_FILES['attachments']['name'][$i] . ' failed to upload.';
                continue;
            }
            $origName = $_FILES['attachments']['name'][$i];
            $tmpPath  = $_FILES['attachments']['tmp_name'][$i];
            $size     = (int)$_FILES['attachments']['size'][$i];
            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, $ALLOWED_EXT, true)) {
                $uploadErrors[] = $origName . ' — file type not allowed.';
                continue;
            }
            if ($size > $MAX_FILE_SIZE) {
                $uploadErrors[] = $origName . ' — exceeds 8MB limit.';
                continue;
            }

            $safeName   = preg_replace('/[^A-Za-z0-9._-]/', '_', $origName);
            $storedName = 'exppay_' . $newId . '_' . time() . '_' . mt_rand(1000, 9999) . '_' . $safeName;
            $destAbs    = $UPLOAD_DIR_ABS . $storedName;

            if (move_uploaded_file($tmpPath, $destAbs)) {
                $relPath     = mysqli_real_escape_string($conn, $UPLOAD_DIR_REL . $storedName);
                $origNameEsc = mysqli_real_escape_string($conn, $origName);
                mysqli_query($conn, "
                    INSERT INTO expense_payment_attachments (payment_id, file_name, file_path, file_size)
                    VALUES ($newId, '$origNameEsc', '$relPath', $size)
                ");
            } else {
                $uploadErrors[] = $origName . ' — could not be saved.';
            }
        }
    }

    // ── Auto-progress the newly saved/edited payment past any stage that has
    //    nobody assigned to action it (no authorizer -> auto Authorized,
    //    no approver -> auto Approved). Runs on both create and edit, since
    //    the expense's role lists may have changed since the payment was made.
    $finalStatus = epAutoProgressStatus($conn, $newId);

    $msg = 'Expense payment saved.';
    if ($finalStatus === 'authorized') {
        $msg .= ' No authorizer is assigned to this expense, so it was auto-advanced to Authorized.';
    } elseif ($finalStatus === 'approved') {
        $msg .= ' No authorizer/approver is assigned to this expense, so it was auto-advanced to Approved.';
    }
    if (!empty($uploadErrors)) {
        $msg .= ' (' . implode(' ', $uploadErrors) . ')';
    }

    echo json_encode(['success' => true, 'id' => $newId, 'message' => $msg]);
    exit;
}

// ── AJAX: Load payments (with filters) + lookup data ─────────────────────────────
if (isset($_GET['ajax_load'])) {
    header('Content-Type: application/json');

    $q         = mysqli_real_escape_string($conn, trim($_GET['q'] ?? ''));
    $dateFrom  = trim($_GET['date_from'] ?? '');
    $dateTo    = trim($_GET['date_to'] ?? '');
    $expenseId = intval($_GET['expense_id'] ?? 0);
    $budgetId  = intval($_GET['budget_id'] ?? 0);
    $status    = trim($_GET['status'] ?? '');

    // Filters shared by both the main list and the status-summary cards.
    // (Status itself is applied only to the main list — the summary always
    // shows the breakdown across every status for the other active filters.)
    $where = [];
    if ($dateFrom !== '' && strtotime($dateFrom)) { $where[] = "ep.payment_date >= '" . mysqli_real_escape_string($conn, $dateFrom) . "'"; }
    if ($dateTo   !== '' && strtotime($dateTo))   { $where[] = "ep.payment_date <= '" . mysqli_real_escape_string($conn, $dateTo) . "'"; }
    if ($expenseId) { $where[] = "ep.expense_id = $expenseId"; }
    if ($budgetId)  { $where[] = "ep.budget_id = $budgetId"; }
    if ($q !== '') {
        // also find by system ref (EXP-PAY-000123) or system ID (123 / #123)
        $qId = ctype_digit(ltrim($q, '#')) ? (int)ltrim($q, '#') : 0;
        $where[] = "(e.expense_name LIKE '%$q%' OR ep.remarks LIKE '%$q%' OR ep.system_ref LIKE '%$q%'" . ($qId ? " OR ep.id = $qId" : "") . ")";
    }

    $whereForSummary = $where;

    if ($status !== '' && in_array($status, $STATUS_LIST, true)) { $where[] = "ep.status = '$status'"; }

    $sql = "
        SELECT ep.*, e.expense_name, b.budget_name, b.limit_amount, b.duration,
               ui.username AS initiated_by_name,
               ua.username AS authorized_by_name,
               up.username AS approved_by_name,
               upd.username AS paid_by_name,
               ur.username AS rejected_by_name,
               uc.username AS cancelled_by_name
        FROM expense_payments ep
        LEFT JOIN expenses e   ON e.id = ep.expense_id
        LEFT JOIN budgets  b   ON b.id = ep.budget_id
        LEFT JOIN users    ui  ON ui.id = ep.initiated_by
        LEFT JOIN users    ua  ON ua.id = ep.authorized_by
        LEFT JOIN users    up  ON up.id = ep.approved_by
        LEFT JOIN users    upd ON upd.id = ep.paid_by
        LEFT JOIN users    ur  ON ur.id = ep.rejected_by
        LEFT JOIN users    uc  ON uc.id = ep.cancelled_by
    ";
    if (!empty($where)) { $sql .= " WHERE " . implode(' AND ', $where); }
    $sql .= " ORDER BY ep.payment_date DESC, ep.id DESC";

    $res = mysqli_query($conn, $sql);
    if ($res === false) {
        echo json_encode(['success' => false, 'message' => 'Query failed: ' . mysqli_error($conn)]);
        exit;
    }

    $uid = epCurrentUserId();
    $roleMap = epGetExpenseRoleMap($conn);

    $payments = [];
    $ids = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $ids[] = (int)$r['id'];

        $eid   = (int)$r['expense_id'];
        $roles = $roleMap[$eid] ?? ['initiater_ids' => [], 'authorizer_ids' => [], 'approver_ids' => []];
        $isInitiator  = in_array($uid, $roles['initiater_ids'], true);
        $isAuthorizer = in_array($uid, $roles['authorizer_ids'], true);
        $isApprover   = in_array($uid, $roles['approver_ids'], true);
        $st = $r['status'];

        // Label "auto" stamps so the UI can distinguish a system auto-skip from a human action.
        $isAutoAuthorized = !empty($r['auto_authorized']);
        $isAutoApproved   = !empty($r['auto_approved']);

        $authorizedByLabel = $r['authorized_by_name'];
        if (!$authorizedByLabel && $isAutoAuthorized) {
            $authorizedByLabel = 'Auto (no authorizer assigned)';
        }
        $approvedByLabel = $r['approved_by_name'];
        if (!$approvedByLabel && $isAutoApproved) {
            $approvedByLabel = 'Auto (no approver assigned)';
        }

        $payments[] = [
            'id'                 => (int)$r['id'],
            'system_ref'         => $r['system_ref'] ?: epSystemRef($r['id']),
            'payment_date'       => $r['payment_date'],
            'expense_id'         => $eid,
            'expense_name'       => $r['expense_name'],
            'budget_id'          => $r['budget_id'] ? (int)$r['budget_id'] : null,
            'budget_name'        => $r['budget_name'],
            'budget_limit'       => $r['limit_amount'] !== null ? (float)$r['limit_amount'] : null,
            'budget_duration'    => $r['duration'],
            'amount'             => (float)$r['amount'],
            'remarks'            => $r['remarks'],
            'initiated_by'       => $r['initiated_by'] ? (int)$r['initiated_by'] : null,
            'initiated_by_name'  => $r['initiated_by_name'],
            'authorized_by_name' => $authorizedByLabel,
            'authorized_at'      => $r['authorized_at'],
            'approved_by_name'   => $approvedByLabel,
            'approved_at'        => $r['approved_at'],
            'paid_by_name'       => $r['paid_by_name'],
            'paid_at'            => $r['paid_at'],
            'rejected_by_name'   => $r['rejected_by_name'],
            'rejected_at'        => $r['rejected_at'],
            'reject_remarks'     => $r['reject_remarks'],
            'cancelled_by_name'  => $r['cancelled_by_name'],
            'cancelled_at'       => $r['cancelled_at'],
            'cancel_remarks'     => $r['cancel_remarks'],
            'status'             => $st,
            'auto_authorized'    => $isAutoAuthorized,
            'auto_approved'      => $isAutoApproved,
            'created_at'         => $r['created_at'],
            'attachments'        => [],
            // Permission flags — computed server-side, UI just reflects these.
            'is_initiator'       => $isInitiator,
            'is_authorizer'      => $isAuthorizer,
            'is_approver'        => $isApprover,
            'can_edit'           => $isInitiator && $st === 'initiated',
            // Delete is unconditional — every user can delete every payment
            // regardless of role or status. See header comment / delete endpoint.
            'can_delete'         => true,
            'can_do_authorize'   => $isAuthorizer && $st === 'initiated',
            'can_do_unauthorize' => $isAuthorizer && $st === 'authorized',
            'can_do_approve'     => $isApprover && $st === 'authorized',
            'can_do_mark_paid'   => $isApprover && $st === 'approved',
            'can_do_reject'      => ($isAuthorizer || $isApprover) && in_array($st, ['initiated', 'authorized'], true),
            'can_do_cancel'      => $isInitiator && in_array($st, ['initiated', 'authorized'], true),
            'can_manual_override' => $isAuthorizer || $isApprover,
        ];
    }

    if (!empty($ids)) {
        $idsList = implode(',', $ids);
        $ar = mysqli_query($conn, "SELECT id, payment_id, file_name, file_path, file_size FROM expense_payment_attachments WHERE payment_id IN ($idsList) ORDER BY id ASC");
        $attMap = [];
        if ($ar) {
            while ($arow = mysqli_fetch_assoc($ar)) {
                $attMap[(int)$arow['payment_id']][] = [
                    'id'        => (int)$arow['id'],
                    'file_name' => $arow['file_name'],
                    'file_path' => $arow['file_path'],
                    'file_size' => (int)$arow['file_size'],
                    'file_size_label' => epFormatBytes($arow['file_size']),
                ];
            }
        }
        foreach ($payments as &$p) {
            $p['attachments'] = $attMap[$p['id']] ?? [];
        }
        unset($p);

        // Bank reconcile status (expense_payment_bank_recon.php) — only if that page has been used
        $bankMap = [];
        $tb = mysqli_query($conn, "SHOW TABLES LIKE 'epr_recon'");
        if ($tb && mysqli_num_rows($tb) > 0) {
            $br = mysqli_query($conn, "SELECT r.payment_id, r.txn_date, r.bank_amount, r.status, b.id AS batch_id, b.batch_no
                                         FROM epr_recon r LEFT JOIN epr_batches b ON b.id = r.batch_id
                                        WHERE r.payment_id IN ($idsList)");
            while ($br && ($brow = mysqli_fetch_assoc($br))) $bankMap[(int)$brow['payment_id']] = $brow;
        }
        foreach ($payments as &$p) {
            $b = $bankMap[$p['id']] ?? null;
            $p['bank_recon'] = $b ? [
                'status'   => $b['status'],
                'txn_date' => $b['txn_date'],
                'amount'   => (float)$b['bank_amount'],
                'batch_id' => (int)$b['batch_id'],
                'batch_no' => $b['batch_no'],
            ] : null;
        }
        unset($p);
    }

    // ── Status summary (respects date/expense/budget/search filters, but NOT
    //    the status filter itself, so the cards always show the full breakdown). ─
    $statusSummary = [];
    foreach ($STATUS_LIST as $sKey) { $statusSummary[$sKey] = ['count' => 0, 'amount' => 0.0]; }

    $summarySql = "
        SELECT ep.status, COUNT(*) AS cnt, COALESCE(SUM(ep.amount),0) AS total_amount
        FROM expense_payments ep
        LEFT JOIN expenses e ON e.id = ep.expense_id
    ";
    if (!empty($whereForSummary)) { $summarySql .= " WHERE " . implode(' AND ', $whereForSummary); }
    $summarySql .= " GROUP BY ep.status";

    $sumRes = mysqli_query($conn, $summarySql);
    if ($sumRes) {
        while ($srow = mysqli_fetch_assoc($sumRes)) {
            $sKey = $srow['status'];
            if (isset($statusSummary[$sKey])) {
                $statusSummary[$sKey] = ['count' => (int)$srow['cnt'], 'amount' => (float)$srow['total_amount']];
            }
        }
    }

    $expenses = [];
    $er = mysqli_query($conn, "SELECT id, expense_name, budget_id, initiater_ids FROM expenses ORDER BY expense_name ASC");
    if ($er) {
        while ($e = mysqli_fetch_assoc($er)) {
            $expenses[] = [
                'id'            => (int)$e['id'],
                'label'         => $e['expense_name'],
                'budget_id'     => $e['budget_id'] ? (int)$e['budget_id'] : null,
                'i_am_initiator' => in_array($uid, epJsonIds($e['initiater_ids']), true),
            ];
        }
    }

    $budgets = [];
    $br = mysqli_query($conn, "SELECT id, budget_name, duration, limit_amount FROM budgets ORDER BY budget_name ASC");
    if ($br) { while ($b = mysqli_fetch_assoc($br)) { $budgets[] = ['id' => (int)$b['id'], 'label' => $b['budget_name'] . ' (' . ucfirst($b['duration']) . ')']; } }

    echo json_encode([
        'success'         => true,
        'payments'        => $payments,
        'expenses'        => $expenses,
        'budgets'         => $budgets,
        'status_summary'  => $statusSummary,
        'current_user_id' => $uid,
    ]);
    exit;
}

if (file_exists(__DIR__ . '/header.php')) {
    include __DIR__ . '/header.php';
} else {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Expense Payments</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    </head><body style="font-family:sans-serif;background:#f3f4f6;padding:20px;">';
}
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-money-check-dollar" style="color:#2563eb;margin-right:8px;"></i>Expense Payments
            </h2>
            <p class="page-subtitle">Record payments against expenses, route them through Initiate → Authorize → Approve, and track them live against the linked budget limit.</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a class="btn btn-light" href="expense_payment_bank_recon.php">
                <i class="fa-solid fa-scale-balanced"></i> Bank Recon
            </a>
            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fa-solid fa-plus"></i> Add Expense Payment
            </button>
        </div>
    </div>
</div>

<!-- Status Summary -->
<div class="content-card" style="margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:6px;">
        <div class="card-section-title">
            <i class="fa-solid fa-chart-pie" style="margin-right:8px;color:#2563eb;"></i>Status Summary
        </div>
        <div style="font-size:11px;color:#9ca3af;">Click a card to filter the table by that status</div>
    </div>
    <div class="status-summary-grid" id="statusSummaryGrid">
        <div style="grid-column:1/-1;text-align:center;padding:16px;color:#9ca3af;font-size:12px;">
            <i class="fa-solid fa-spinner fa-spin" style="margin-right:8px;"></i>Loading summary…
        </div>
    </div>
</div>

<!-- Filters -->
<div class="content-card" style="margin-bottom:16px;">
    <div class="card-section-title" style="margin-bottom:12px;">
        <i class="fa-solid fa-filter" style="margin-right:8px;color:#2563eb;"></i>Filters
    </div>
    <div class="filter-grid">
        <div class="form-group">
            <label class="field-label">Date From</label>
            <input type="date" id="fltDateFrom" class="modal-input">
        </div>
        <div class="form-group">
            <label class="field-label">Date To</label>
            <input type="date" id="fltDateTo" class="modal-input">
        </div>
        <div class="form-group">
            <label class="field-label">Expense</label>
            <select id="fltExpense" class="sel2" style="width:100%;"></select>
        </div>
        <div class="form-group">
            <label class="field-label">Budget</label>
            <select id="fltBudget" class="sel2" style="width:100%;"></select>
        </div>
        <div class="form-group">
            <label class="field-label">Status</label>
            <select id="fltStatus" class="sel2" style="width:100%;"></select>
        </div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:6px;">
        <div style="display:flex;gap:8px;align-items:center;flex:1;min-width:260px;">
            <div style="position:relative;flex:1;max-width:380px;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;"></i>
                <input type="text" id="searchInput" placeholder="Search expense, remarks or ID (#12)…" class="search-input" onkeydown="if(event.key==='Enter'){applyFilters();}">
            </div>
            <button class="btn btn-primary" onclick="applyFilters()"><i class="fa-solid fa-magnifying-glass"></i> Apply</button>
            <button class="btn btn-light" onclick="clearFilters()"><i class="fa-solid fa-xmark"></i> Clear</button>
        </div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;">
            <div class="sum-item"><span class="sum-dot blue"></span><span id="sumTotal">0</span> Payment(s)</div>
            <div class="sum-item"><span class="sum-dot green"></span>Rs. <span id="sumAmount">0.00</span></div>
        </div>
    </div>
</div>

<!-- Table -->
<div class="content-card">
    <div class="table-responsive">
        <table class="exp-table" id="payTable">
            <thead>
                <tr>
                    <th style="width:50px;">#</th>
                    <th style="width:120px;">ID</th>
                    <th style="width:110px;">Date</th>
                    <th>Expense</th>
                    <th>Budget</th>
                    <th style="width:130px;">Amount</th>
                    <th style="width:110px;">Attachments</th>
                    <th style="width:130px;">Initiated By</th>
                    <th style="width:110px;">Status</th>
                    <th style="width:230px;">Actions</th>
                </tr>
            </thead>
            <tbody id="payBody">
                <tr><td colspan="10" style="text-align:center;padding:40px;color:#9ca3af;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size:24px;margin-bottom:10px;display:block;"></i>Loading expense payments…
                </td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Add / Edit Payment Modal -->
<div id="paymentModal" class="modal-overlay">
    <div class="modal-box" id="paymentModalBox">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:12px;">
                <div class="modal-icon"><i class="fa-solid fa-money-check-dollar"></i></div>
                <div>
                    <div class="modal-title" id="modalTitle">Add Expense Payment</div>
                    <div class="modal-sub" id="modalSub">Fill in the payment details below.</div>
                </div>
            </div>
            <button class="modal-close" onclick="closePaymentModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="modal-body">
            <input type="hidden" id="paymentId" value="">

            <div id="noInitiatorNote" class="no-budget-note" style="display:none;margin-bottom:14px;">
                <i class="fa-solid fa-circle-info"></i> You are not set up as an initiator on any expense, so there is nothing available to select. Contact an administrator to be added as an initiator.
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="field-label">Payment Date <span class="req">*</span></label>
                    <input type="date" id="paymentDate" class="modal-input" onchange="onDateOrExpenseChange()">
                    <div class="err-msg" id="errPaymentDate"></div>
                </div>
                <div class="form-group">
                    <label class="field-label">Expense <span class="req">*</span></label>
                    <select id="expenseSelect" class="sel2" style="width:100%;"></select>
                    <div class="err-msg" id="errExpense"></div>
                </div>
            </div>

            <!-- Live Budget Status Panel -->
            <div id="budgetStatusBox" class="budget-status-box" style="display:none;">
                <div class="bsb-head">
                    <i class="fa-solid fa-wallet"></i>
                    <span id="bsbBudgetName">—</span>
                    <span class="bsb-period" id="bsbPeriod"></span>
                </div>
                <div class="bsb-grid">
                    <div class="bsb-cell"><span class="bsb-label">Limit</span><span class="bsb-value" id="bsbLimit">0.00</span></div>
                    <div class="bsb-cell"><span class="bsb-label">Already Spent</span><span class="bsb-value" id="bsbSpent">0.00</span></div>
                    <div class="bsb-cell"><span class="bsb-label">Remaining</span><span class="bsb-value" id="bsbRemaining">0.00</span></div>
                    <div class="bsb-cell"><span class="bsb-label">After This Payment</span><span class="bsb-value" id="bsbAfter">0.00</span></div>
                </div>
                <div class="bsb-bar-track"><div class="bsb-bar-fill" id="bsbBarFill"></div></div>
                <div class="bsb-warn" id="bsbWarn" style="display:none;">
                    <i class="fa-solid fa-triangle-exclamation"></i> This payment will exceed the remaining budget limit.
                </div>
            </div>
            <div id="noBudgetNote" class="no-budget-note" style="display:none;">
                <i class="fa-solid fa-circle-info"></i> This expense has no budget linked, so no limit tracking is shown.
            </div>

            <div class="form-row" style="margin-top:16px;">
                <div class="form-group">
                    <label class="field-label">Payment Amount (Rs.) <span class="req">*</span></label>
                    <input type="number" id="paymentAmount" class="modal-input" placeholder="0.00" step="0.01" min="0" oninput="onAmountChange()">
                    <div class="err-msg" id="errAmount"></div>
                </div>
            </div>

            <div class="form-group">
                <label class="field-label">Remarks</label>
                <textarea id="paymentRemarks" class="modal-input" rows="2" placeholder="Optional notes about this payment…"></textarea>
            </div>

            <hr style="margin:18px 0;border:none;border-top:1px solid #f1f5f9;">

            <div class="form-group">
                <label class="field-label"><i class="fa-solid fa-paperclip" style="color:#2563eb;margin-right:6px;"></i>Attachments</label>
                <div class="file-drop" onclick="document.getElementById('attachmentInput').click();">
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                    <div>Click to choose files (multiple allowed)</div>
                    <div class="file-drop-sub">JPG, PNG, PDF, DOC, XLS — max 8MB each</div>
                </div>
                <input type="file" id="attachmentInput" multiple style="display:none;" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx" onchange="onFilesChosen(this.files)">

                <div id="existingAttachmentsWrap" style="display:none;margin-top:10px;">
                    <div class="field-label" style="font-size:11px;margin-bottom:6px;">Existing Attachments</div>
                    <div id="existingAttachmentsList"></div>
                </div>

                <div id="newAttachmentsWrap" style="margin-top:10px;">
                    <div id="newAttachmentsList"></div>
                </div>
            </div>
        </div>

        <div class="modal-foot">
            <button class="btn btn-light" onclick="closePaymentModal()">Cancel</button>
            <button class="btn btn-primary" id="btnModalSave" onclick="savePayment()">
                <i class="fa-solid fa-floppy-disk"></i> Save Payment
            </button>
        </div>
    </div>
</div>

<!-- View Payment Modal -->
<div id="viewModal" class="modal-overlay">
    <div class="modal-box" style="max-width:560px;">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:12px;">
                <div class="modal-icon"><i class="fa-solid fa-eye"></i></div>
                <div>
                    <div class="modal-title" id="viewTitle">Payment Details</div>
                    <div class="modal-sub" id="viewSub"></div>
                </div>
            </div>
            <button class="modal-close" onclick="closeViewModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="viewBody"></div>
        <div class="modal-foot">
            <button class="btn btn-light" id="viewDeleteBtn" onclick="openDeleteModal(viewModalPaymentId)" style="display:none;background:#fef2f2;color:#dc2626;">
                <i class="fa-solid fa-trash"></i> Delete
            </button>
            <button class="btn btn-light" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<!-- Manual Status Override Modal (authorizer/approver only) -->
<div id="statusModal" class="modal-overlay">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:12px;">
                <div class="modal-icon"><i class="fa-solid fa-flag"></i></div>
                <div>
                    <div class="modal-title">Manual Status Override</div>
                    <div class="modal-sub" id="statusModalSub"></div>
                </div>
            </div>
            <button class="modal-close" onclick="closeStatusModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:10px 14px;font-size:12px;font-weight:600;margin-bottom:14px;">
                <i class="fa-solid fa-triangle-exclamation"></i> This forces the status directly, bypassing the normal Authorize → Approve flow. Use only when correcting a mistake.
            </div>
            <div class="form-group">
                <label class="field-label">New Status</label>
                <select id="statusUpdateSelect" class="sel2" style="width:100%;"></select>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeStatusModal()">Cancel</button>
            <button class="btn btn-primary" id="btnStatusSave" onclick="confirmStatusUpdate()">
                <i class="fa-solid fa-check"></i> Update
            </button>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal-overlay">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:12px;">
                <div class="modal-icon"><i class="fa-solid fa-ban"></i></div>
                <div>
                    <div class="modal-title">Reject Payment</div>
                    <div class="modal-sub" id="rejectModalSub"></div>
                </div>
            </div>
            <button class="modal-close" onclick="closeRejectModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="field-label">Reason (optional)</label>
                <textarea id="rejectRemarks" class="modal-input" rows="2" placeholder="Why is this payment being rejected?"></textarea>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeRejectModal()">Cancel</button>
            <button class="btn btn-danger" id="btnRejectConfirm" onclick="confirmReject()">
                <i class="fa-solid fa-ban"></i> Reject
            </button>
        </div>
    </div>
</div>

<!-- Cancel Modal -->
<div id="cancelModal" class="modal-overlay">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:12px;">
                <div class="modal-icon"><i class="fa-solid fa-circle-xmark"></i></div>
                <div>
                    <div class="modal-title">Cancel Payment</div>
                    <div class="modal-sub" id="cancelModalSub"></div>
                </div>
            </div>
            <button class="modal-close" onclick="closeCancelModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="field-label">Reason (optional)</label>
                <textarea id="cancelRemarks" class="modal-input" rows="2" placeholder="Why is this payment being cancelled?"></textarea>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeCancelModal()">Back</button>
            <button class="btn btn-danger" id="btnCancelConfirm" onclick="confirmCancel()">
                <i class="fa-solid fa-circle-xmark"></i> Cancel Payment
            </button>
        </div>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal-box" style="max-width:380px;">
        <div class="modal-body" style="padding-top:24px;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                <div style="width:42px;height:42px;background:#fef2f2;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fa-solid fa-trash" style="color:#dc2626;font-size:18px;"></i>
                </div>
                <div>
                    <div style="font-weight:700;font-size:15px;color:#111827;">Delete Expense Payment</div>
                    <div style="font-size:12px;color:#6b7280;">This action cannot be undone and removes any attached files.</div>
                </div>
            </div>
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:13px;color:#7f1d1d;">
                Are you sure you want to delete <strong id="deletePayLabel"></strong>?
            </div>
        </div>
        <div class="modal-foot">
            <button onclick="closeDeleteModal()" class="btn btn-light">Cancel</button>
            <button onclick="confirmDelete()" class="btn btn-danger" id="btnConfirmDelete">
                <i class="fa-solid fa-trash"></i> Delete
            </button>
        </div>
    </div>
</div>

<style>
.page-header   { margin-bottom:20px; }
.page-title    { font-size:24px;font-weight:700;color:#111827;margin:0 0 3px; }
.page-subtitle { font-size:13px;color:#6b7280;margin:0; }

.content-card {
    background:#fff;border-radius:12px;
    box-shadow:0 1px 4px rgba(0,0,0,.08);padding:20px 22px;
}
.card-section-title { font-size:14px;font-weight:700;color:#111827;display:flex;align-items:center; }
.field-label { font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;display:block; }
.req { color:#ef4444; }

.btn {
    display:inline-flex;align-items:center;gap:6px;padding:9px 16px;
    border:none;border-radius:8px;font-size:13px;font-weight:600;
    cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap;
}
.btn-primary { background:#2563eb;color:#fff; }
.btn-primary:hover { background:#1d4ed8;transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,99,235,.35); }
.btn-primary:disabled { background:#93c5fd;cursor:not-allowed;transform:none;box-shadow:none; }
.btn-light { background:#f9fafb;color:#374151;border:1px solid #d1d5db; }
.btn-light:hover { background:#f3f4f6; }
.btn-danger { background:#dc2626;color:#fff; }
.btn-danger:hover { background:#b91c1c; }
.btn-danger:disabled { background:#fca5a5;cursor:not-allowed; }

.search-input {
    width:100%;padding:9px 12px 9px 34px;border:1px solid #d1d5db;border-radius:8px;
    font-size:13px;font-family:inherit;color:#111827;transition:border-color .15s;
}
.search-input:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12); }

.sum-item { display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#374151; }
.sum-dot  { width:10px;height:10px;border-radius:50%;flex-shrink:0; }
.sum-dot.blue  { background:#3b82f6; }
.sum-dot.green { background:#16a34a; }

/* ── Status Summary cards ──────────────────────────────────────────────── */
.status-summary-grid { display:grid;grid-template-columns:repeat(6, 1fr);gap:12px; }
@media(max-width:1100px) { .status-summary-grid { grid-template-columns:repeat(3, 1fr); } }
@media(max-width:640px)  { .status-summary-grid { grid-template-columns:repeat(2, 1fr); } }
.status-summary-card {
    border:1px solid #f1f5f9;border-radius:10px;padding:12px 14px;cursor:pointer;
    transition:all .15s;background:#fafafa;
}
.status-summary-card:hover { transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.08);background:#fff; }
.status-summary-card.active { border-color:#2563eb;background:#eff6ff;box-shadow:0 2px 8px rgba(37,99,235,.15); }
.status-summary-card .ssc-label {
    font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;
    margin-bottom:8px;display:flex;align-items:center;gap:6px;
}
.status-summary-card .ssc-dot { width:8px;height:8px;border-radius:50%;flex-shrink:0; }
.status-summary-card .ssc-count { font-size:20px;font-weight:700;color:#111827;line-height:1;margin-bottom:4px; }
.status-summary-card .ssc-amount { font-size:12px;color:#6b7280;font-weight:600; }

.filter-grid { display:grid;grid-template-columns:repeat(5, 1fr);gap:12px;margin-bottom:10px; }
.filter-grid .form-group { margin-bottom:0; }
@media(max-width:1100px) { .filter-grid { grid-template-columns:repeat(3, 1fr); } }
@media(max-width:640px)  { .filter-grid { grid-template-columns:repeat(2, 1fr); } }

.table-responsive { overflow-x:auto; }
.exp-table { width:100%;border-collapse:collapse;font-size:13px; }
.sys-ref { display:inline-block;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;font-weight:700;color:#1e40af;background:#eff6ff;border:1px solid #bfdbfe;border-radius:5px;padding:1px 6px;white-space:nowrap; }
.sys-id  { font-size:11px;color:#6b7280;margin-top:2px;white-space:nowrap; }
.bank-badge { display:inline-flex;align-items:center;gap:4px;margin-top:4px;padding:1px 7px;border-radius:8px;font-size:10.5px;font-weight:700;white-space:nowrap; }
.bank-badge i { font-size:9px; }
.bank-badge.ok   { background:#dcfce7;color:#166534;border:1px solid #86efac; }
.bank-badge.diff { background:#fef3c7;color:#92400e;border:1px solid #fde68a; }
.bank-badge.open { background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;font-weight:600; }
.exp-table thead { background:#f8fafc;border-bottom:2px solid #e2e8f0; }
.exp-table th {
    padding:9px 12px;text-align:left;font-weight:600;
    color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;
}
.exp-table tbody tr { border-bottom:1px solid #f1f5f9; }
.exp-table tbody tr:hover { background:#f8fafc; }
.exp-table td { padding:9px 12px;vertical-align:middle;color:#374151; }

.btn-icon {
    display:inline-flex;align-items:center;justify-content:center;
    width:28px;height:28px;border:none;border-radius:6px;
    cursor:pointer;font-size:12px;transition:all .15s;margin-right:4px;margin-bottom:4px;
}
.btn-icon.edit        { background:#dbeafe;color:#2563eb; }
.btn-icon.edit:hover        { background:#bfdbfe; }
.btn-icon.delete      { background:#fef2f2;color:#dc2626; }
.btn-icon.delete:hover      { background:#fee2e2;transform:scale(1.1); }
.btn-icon.view        { background:#eff6ff;color:#2563eb; }
.btn-icon.view:hover        { background:#dbeafe; }
.btn-icon.flag        { background:#fef9c3;color:#a16207; }
.btn-icon.flag:hover        { background:#fef08a; }
.btn-icon.authorize   { background:#e0e7ff;color:#4338ca; }
.btn-icon.authorize:hover   { background:#c7d2fe; }
.btn-icon.unauthorize { background:#fff7ed;color:#c2410c; }
.btn-icon.unauthorize:hover { background:#ffedd5; }
.btn-icon.approve     { background:#dcfce7;color:#15803d; }
.btn-icon.approve:hover     { background:#bbf7d0; }
.btn-icon.markpaid    { background:#dbeafe;color:#1d4ed8; }
.btn-icon.markpaid:hover    { background:#bfdbfe; }
.btn-icon.reject      { background:#fef2f2;color:#dc2626; }
.btn-icon.reject:hover      { background:#fee2e2; }
.btn-icon.cancel      { background:#f3f4f6;color:#4b5563; }
.btn-icon.cancel:hover      { background:#e5e7eb; }

.status-badge { display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600; }
.status-badge.clickable { cursor:pointer; }
.status-badge.initiated  { background:#e0f2fe;color:#0369a1; }
.status-badge.authorized { background:#ede9fe;color:#6d28d9; }
.status-badge.approved   { background:#dcfce7;color:#16a34a; }
.status-badge.paid       { background:#dbeafe;color:#1d4ed8; }
.status-badge.rejected   { background:#fef2f2;color:#dc2626; }
.status-badge.cancelled  { background:#f3f4f6;color:#6b7280; }

.auto-tag { display:inline-block;margin-left:6px;font-size:10px;font-weight:700;color:#a16207;background:#fef9c3;border-radius:10px;padding:1px 7px; }

.att-count { display:inline-flex;align-items:center;gap:6px;background:#f3f4f6;color:#374151;border-radius:14px;padding:3px 10px;font-size:11px;font-weight:600;cursor:pointer; }
.att-count:hover { background:#e5e7eb; }
.att-count.none { color:#9ca3af;cursor:default; }
.att-count.none:hover { background:#f3f4f6; }

/* ── Modal ──────────────────────────────────────────────────────────────── */
.modal-overlay {
    display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99998;
    align-items:center;justify-content:center;padding:16px;
}
.modal-overlay.open { display:flex; }
.modal-box {
    background:#fff;border-radius:14px;max-width:560px;width:100%;
    box-shadow:0 12px 40px rgba(0,0,0,.25);animation:slideInRight .2s ease;
    max-height:92vh;overflow-y:auto;
}
#paymentModalBox { max-width:720px; }

.modal-head {
    display:flex;align-items:center;justify-content:space-between;
    padding:20px 22px 14px;border-bottom:1px solid #f1f5f9;position:sticky;top:0;background:#fff;z-index:2;
}
.modal-icon {
    width:42px;height:42px;background:#eff6ff;border-radius:50%;
    display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.modal-icon i { color:#2563eb;font-size:18px; }
.modal-title { font-weight:700;font-size:15px;color:#111827; }
.modal-sub   { font-size:12px;color:#6b7280;margin-top:2px; }
.modal-close {
    width:32px;height:32px;border:none;border-radius:8px;background:#f9fafb;
    color:#6b7280;cursor:pointer;font-size:14px;transition:all .15s;
}
.modal-close:hover { background:#f3f4f6;color:#111827; }
.modal-body { padding:20px 22px; }
.modal-foot {
    display:flex;justify-content:flex-end;gap:10px;
    padding:14px 22px 20px;position:sticky;bottom:0;background:#fff;
}

.form-group { margin-bottom:16px; }
.form-row { display:flex;flex-direction:column;gap:14px; }
.form-row .form-group { flex:1 1 100%;min-width:0;width:100%; }
@media(min-width:640px) { .form-row { flex-direction:row; } }

.modal-input {
    width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;
    font-size:13px;font-family:inherit;color:#111827;transition:border-color .15s;box-sizing:border-box;
}
.modal-input:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12); }
textarea.modal-input { resize:vertical; }

.err-msg { font-size:11px;color:#dc2626;margin-top:5px;display:none; }
.err-msg.show { display:block; }

/* Minimal select2 styling to match the rest of the design system */
.select2-container--default .select2-selection--single {
    height:auto;min-height:38px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;
    display:flex;align-items:center;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height:1.4;padding:8px 30px 8px 12px;color:#111827;
    white-space:normal;word-break:break-word;width:100%;
}
.select2-container--default .select2-selection--single .select2-selection__placeholder { color:#9ca3af; }
.select2-container--default .select2-selection--single .select2-selection__arrow { height:100%;right:6px;top:0; }
.select2-container--default.select2-container--focus .select2-selection--single {
    border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12);
}
.select2-container--open .select2-dropdown { z-index:100000 !important; }
.select2-dropdown { border-color:#d1d5db;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);font-size:13px; }
.select2-results__option { white-space:normal;word-break:break-word; }

/* ── Budget status panel ───────────────────────────────────────────────── */
.budget-status-box { background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-top:4px; }
.bsb-head { display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;color:#111827;margin-bottom:10px; }
.bsb-head i { color:#2563eb; }
.bsb-period { margin-left:auto;font-size:11px;font-weight:600;color:#6b7280;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:2px 10px; }
.bsb-grid { display:grid;grid-template-columns:repeat(4, 1fr);gap:10px;margin-bottom:10px; }
.bsb-cell { background:#fff;border:1px solid #f1f5f9;border-radius:8px;padding:8px 10px; }
.bsb-label { display:block;font-size:10px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px; }
.bsb-value { font-size:14px;font-weight:700;color:#111827; }
.bsb-bar-track { height:8px;background:#e5e7eb;border-radius:6px;overflow:hidden; }
.bsb-bar-fill { height:100%;background:#16a34a;border-radius:6px;transition:width .25s ease,background .25s ease;width:0%; }
.bsb-bar-fill.warn { background:#d97706; }
.bsb-bar-fill.over { background:#dc2626; }
.bsb-warn { margin-top:10px;background:#fef2f2;border:1px solid #fca5a5;color:#7f1d1d;border-radius:8px;padding:8px 12px;font-size:12px;font-weight:600; }
.no-budget-note { background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:10px 14px;font-size:12px;font-weight:600;margin-top:4px; }
@media(max-width:640px) { .bsb-grid { grid-template-columns:repeat(2, 1fr); } }

/* ── Attachments ────────────────────────────────────────────────────────── */
.file-drop {
    border:2px dashed #d1d5db;border-radius:10px;padding:18px;text-align:center;
    color:#6b7280;cursor:pointer;transition:all .15s;font-size:13px;
}
.file-drop:hover { border-color:#2563eb;background:#eff6ff;color:#2563eb; }
.file-drop i { font-size:22px;margin-bottom:6px;display:block; }
.file-drop-sub { font-size:11px;color:#9ca3af;margin-top:2px; }

.att-item {
    display:flex;align-items:center;gap:10px;padding:8px 10px;
    border:1px solid #f1f5f9;border-radius:8px;margin-bottom:6px;font-size:12.5px;background:#fafafa;
}
.att-item i.att-file-icon { color:#2563eb;font-size:15px;width:18px;text-align:center;flex-shrink:0; }
.att-item .att-name { flex:1;color:#111827;font-weight:600;word-break:break-all; }
.att-item .att-size { color:#9ca3af;font-size:11px;white-space:nowrap; }
.att-item .att-remove {
    border:none;background:#fef2f2;color:#dc2626;width:24px;height:24px;border-radius:6px;
    cursor:pointer;flex-shrink:0;font-size:11px;
}
.att-item .att-remove:hover { background:#fee2e2; }
.att-item.marked-removed { opacity:.45;text-decoration:line-through; }
.att-item a.att-link { color:#2563eb;text-decoration:none; }
.att-item a.att-link:hover { text-decoration:underline; }

/* ── View modal detail rows ─────────────────────────────────────────────── */
.log-detail-row { display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:13px; }
.log-detail-row:last-child { border-bottom:none; }
.log-detail-label { color:#6b7280;font-weight:600;flex-shrink:0; }
.log-detail-value { color:#111827;text-align:right; }
.log-detail-value.empty { color:#9ca3af;font-style:italic; }

@keyframes slideInRight {
    from { opacity:0;transform:translateX(20px) scale(.98); }
    to   { opacity:1;transform:translateX(0) scale(1); }
}

@media(max-width:768px) {
    .content-card { padding:14px; }
    .form-row { flex-direction:column; }
    #paymentModalBox { max-width:560px; }
}
</style>

<script>
let currentPayments = [];
let allExpenses = [], allBudgets = [];
let selectedNewFiles = [];      // File objects staged for upload
let removedAttachmentIds = [];  // existing attachment IDs marked for removal (edit mode)
let existingAttachments = [];   // existing attachments currently shown (edit mode)
let deleteTarget = null;
let statusTarget = null;
let rejectTarget = null;
let cancelTarget = null;
let latestBudgetStatus = null;
let currentUserId = 0;
let latestStatusSummary = null;
let viewModalPaymentId = 0;

const STATUS_LABELS = { initiated: 'Initiated', authorized: 'Authorized', approved: 'Approved', paid: 'Paid', rejected: 'Rejected', cancelled: 'Cancelled' };
const STATUS_COLORS = {
    initiated:  '#0369a1',
    authorized: '#6d28d9',
    approved:   '#16a34a',
    paid:       '#1d4ed8',
    rejected:   '#dc2626',
    cancelled:  '#6b7280',
};

$(document).ready(function () {
    loadAll();
});

// ── Load payments + lookup data ──────────────────────────────────────────────────
function loadAll() {
    const tbody = document.getElementById('payBody');
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:32px;color:#6b7280;"><i class="fa-solid fa-spinner fa-spin" style="margin-right:8px;"></i>Loading…</td></tr>';

    const params = buildFilterParams();

    fetch('expense_payments.php?ajax_load=1&' + params.toString())
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            if (!data.success) { showToast('❌ ' + (data.message || 'Failed to load.'), 'error'); return; }
            currentPayments = data.payments || [];
            allExpenses = data.expenses || [];
            allBudgets  = data.budgets || [];
            currentUserId = data.current_user_id || 0;
            latestStatusSummary = data.status_summary || null;
            populateFilterSelects();
            renderPayments(currentPayments);
            renderStatusSummary(latestStatusSummary);
            maybeAutoOpenAddFromUrl();
        })
        .catch(function (err) {
            showToast('❌ Failed to load: ' + err.message, 'error');
            tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:32px;color:#ef4444;">Failed to load. Check your connection.</td></tr>';
        });
}

// Deep-link support: cash_float.php's "Add Payment" button links here as
// expense_payments.php?add_expense_id=123 — auto-open the Add modal pre-selected to that expense.
function maybeAutoOpenAddFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const addExpenseId = parseInt(params.get('add_expense_id') || '0', 10);
    if (!addExpenseId) return;

    openAddModal();
    const match = allExpenses.find(function (e) { return e.id === addExpenseId && e.i_am_initiator; });
    if (match) {
        $('#expenseSelect').val(addExpenseId).trigger('change');
    } else {
        showToast('⚠️ You are not set up as an initiator for that expense.', 'warn');
    }

    // Strip the param so a refresh/re-load doesn't reopen the modal.
    params.delete('add_expense_id');
    const qs = params.toString();
    window.history.replaceState({}, '', window.location.pathname + (qs ? '?' + qs : ''));
}

function buildFilterParams() {
    const params = new URLSearchParams();
    const dFrom = document.getElementById('fltDateFrom').value;
    const dTo   = document.getElementById('fltDateTo').value;
    const exp   = document.getElementById('fltExpense').value;
    const bud   = document.getElementById('fltBudget').value;
    const st    = document.getElementById('fltStatus').value;
    const q     = document.getElementById('searchInput').value.trim();

    if (dFrom) params.append('date_from', dFrom);
    if (dTo)   params.append('date_to', dTo);
    if (exp)   params.append('expense_id', exp);
    if (bud)   params.append('budget_id', bud);
    if (st)    params.append('status', st);
    if (q)     params.append('q', q);
    return params;
}

function applyFilters() { loadAll(); }

function clearFilters() {
    document.getElementById('fltDateFrom').value = '';
    document.getElementById('fltDateTo').value = '';
    document.getElementById('searchInput').value = '';
    $('#fltExpense, #fltBudget, #fltStatus').val('').trigger('change.select2');
    loadAll();
}

function populateFilterSelects() {
    const keepFilterVals = {
        expense: document.getElementById('fltExpense').value,
        budget:  document.getElementById('fltBudget').value,
        status:  document.getElementById('fltStatus').value,
    };

    const expSel = document.getElementById('fltExpense');
    expSel.innerHTML = '<option value="">All Expenses</option>';
    allExpenses.forEach(function (e) {
        const o = document.createElement('option'); o.value = e.id; o.textContent = e.label; expSel.appendChild(o);
    });

    const budSel = document.getElementById('fltBudget');
    budSel.innerHTML = '<option value="">All Budgets</option>';
    allBudgets.forEach(function (b) {
        const o = document.createElement('option'); o.value = b.id; o.textContent = b.label; budSel.appendChild(o);
    });

    const stSel = document.getElementById('fltStatus');
    stSel.innerHTML = '<option value="">All Statuses</option>';
    Object.keys(STATUS_LABELS).forEach(function (k) {
        const o = document.createElement('option'); o.value = k; o.textContent = STATUS_LABELS[k]; stSel.appendChild(o);
    });

    if (!$('#fltExpense').hasClass('select2-hidden-accessible')) {
        $('#fltExpense, #fltBudget, #fltStatus').select2({ width: '100%', allowClear: true });
    }
    document.getElementById('fltExpense').value = keepFilterVals.expense;
    document.getElementById('fltBudget').value  = keepFilterVals.budget;
    document.getElementById('fltStatus').value  = keepFilterVals.status;
    $('#fltExpense, #fltBudget, #fltStatus').trigger('change.select2');
}

// ── Status Summary cards ──────────────────────────────────────────────────────
function renderStatusSummary(summary) {
    const grid = document.getElementById('statusSummaryGrid');
    if (!summary) { grid.innerHTML = ''; return; }

    const currentStatus = document.getElementById('fltStatus').value;
    let html = '';
    Object.keys(STATUS_LABELS).forEach(function (k) {
        const s = summary[k] || { count: 0, amount: 0 };
        const active = currentStatus === k;
        const color = STATUS_COLORS[k];
        html += '<div class="status-summary-card' + (active ? ' active' : '') + '" onclick="filterByStatusCard(\'' + k + '\')" title="Click to ' + (active ? 'clear this filter' : 'filter by ' + STATUS_LABELS[k]) + '">' +
            '<div class="ssc-label" style="color:' + color + ';"><span class="ssc-dot" style="background:' + color + ';"></span>' + STATUS_LABELS[k] + '</div>' +
            '<div class="ssc-count">' + s.count + '</div>' +
            '<div class="ssc-amount">Rs. ' + formatMoney(s.amount) + '</div>' +
        '</div>';
    });
    grid.innerHTML = html;
}

function filterByStatusCard(status) {
    const sel = document.getElementById('fltStatus');
    const newVal = ($(sel).val() === status) ? '' : status; // click again to clear
    $(sel).val(newVal).trigger('change.select2');
    loadAll();
}

// ── Render table ──────────────────────────────────────────────────────────────
// Small bank-reconcile badge under the ID (Expense Payment ↔ Bank page)
function bankBadge(p) {
    if (!p.bank_recon) {
        return p.status === 'paid' ? '<div class="bank-badge open" title="Not reconciled with the bank statement yet"><i class="fa-solid fa-hourglass-half"></i> Bank open</div>' : '';
    }
    const ok = p.bank_recon.status === 'reconciled';
    return '<div class="bank-badge ' + (ok ? 'ok' : 'diff') + '" title="Reconciled with the bank statement' + (p.bank_recon.batch_no ? ' (' + escapeHtml(p.bank_recon.batch_no) + ')' : '') + '">'
         + '<i class="fa-solid fa-building-columns"></i> Bank ' + formatDate(p.bank_recon.txn_date) + (ok ? '' : ' (diff)') + '</div>';
}

function renderPayments(list) {
    const tbody = document.getElementById('payBody');
    document.getElementById('sumTotal').textContent = list.length;
    let totalAmount = 0;
    list.forEach(function (p) { totalAmount += (p.amount || 0); });
    document.getElementById('sumAmount').textContent = formatMoney(totalAmount);

    if (list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:40px;color:#9ca3af;"><i class="fa-solid fa-folder-open" style="font-size:24px;margin-bottom:10px;display:block;"></i>No expense payments found. Click <strong>Add Expense Payment</strong> to create one.</td></tr>';
        return;
    }

    let html = '';
    list.forEach(function (p, idx) {
        const attCount = (p.attachments || []).length;
        const attHtml = attCount > 0
            ? '<span class="att-count" onclick="openViewModal(' + p.id + ')"><i class="fa-solid fa-paperclip"></i> ' + attCount + '</span>'
            : '<span class="att-count none"><i class="fa-solid fa-paperclip"></i> 0</span>';

        const badgeCls = p.can_manual_override ? 'status-badge clickable ' + p.status : 'status-badge ' + p.status;
        const badgeClick = p.can_manual_override ? ' onclick="openStatusModal(' + p.id + ')" title="Click to manually override status"' : '';
        const autoTag = (p.auto_authorized || p.auto_approved) ? '<span class="auto-tag" title="Auto-advanced — no authorizer/approver assigned">AUTO</span>' : '';

        let actions = '<button class="btn-icon view" title="View" onclick="openViewModal(' + p.id + ')"><i class="fa-solid fa-eye"></i></button>';
        if (p.can_edit) {
            actions += '<button class="btn-icon edit" title="Edit" onclick="openEditModal(' + p.id + ')"><i class="fa-solid fa-pen"></i></button>';
        }
        if (p.can_do_authorize) {
            actions += '<button class="btn-icon authorize" title="Authorize" onclick="doWorkflowAction(' + p.id + ", 'authorize')\"><i class=\"fa-solid fa-check\"></i></button>";
        }
        if (p.can_do_unauthorize) {
            actions += '<button class="btn-icon unauthorize" title="Revert to Initiated" onclick="doWorkflowAction(' + p.id + ", 'unauthorize')\"><i class=\"fa-solid fa-rotate-left\"></i></button>";
        }
        if (p.can_do_approve) {
            actions += '<button class="btn-icon approve" title="Approve" onclick="doWorkflowAction(' + p.id + ", 'approve')\"><i class=\"fa-solid fa-check-double\"></i></button>";
        }
        if (p.can_do_mark_paid) {
            actions += '<button class="btn-icon markpaid" title="Mark as Paid" onclick="doWorkflowAction(' + p.id + ", 'mark_paid')\"><i class=\"fa-solid fa-money-bill-wave\"></i></button>";
        }
        if (p.can_do_reject) {
            actions += '<button class="btn-icon reject" title="Reject" onclick="openRejectModal(' + p.id + ')"><i class="fa-solid fa-ban"></i></button>';
        }
        if (p.can_do_cancel) {
            actions += '<button class="btn-icon cancel" title="Cancel" onclick="openCancelModal(' + p.id + ')"><i class="fa-solid fa-circle-xmark"></i></button>';
        }
        if (p.can_manual_override) {
            actions += '<button class="btn-icon flag" title="Manual Status Override" onclick="openStatusModal(' + p.id + ')"><i class="fa-solid fa-flag"></i></button>';
        }
        // Delete button shown on every row for every user — no permission or status check.
        actions += '<button class="btn-icon delete" title="Delete" onclick="openDeleteModal(' + p.id + ')"><i class="fa-solid fa-trash-can"></i></button>';

        html += '<tr>' +
            '<td>' + (idx + 1) + '</td>' +
            '<td><span class="sys-ref" title="System ID">#' + p.id + '</span>' + bankBadge(p) + '</td>' +
            '<td>' + formatDate(p.payment_date) + '</td>' +
            '<td><strong>' + escapeHtml(p.expense_name || '—') + '</strong></td>' +
            '<td>' + (p.budget_name ? escapeHtml(p.budget_name) : '<span style="color:#9ca3af;">—</span>') + '</td>' +
            '<td><strong>' + formatMoney(p.amount) + '</strong></td>' +
            '<td>' + attHtml + '</td>' +
            '<td>' + (p.initiated_by_name ? escapeHtml(p.initiated_by_name) : '<span style="color:#9ca3af;">—</span>') + '</td>' +
            '<td><span class="' + badgeCls + '"' + badgeClick + '>' + STATUS_LABELS[p.status] + '</span>' + autoTag + '</td>' +
            '<td>' + actions + '</td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
}

// ── Select2 helpers for the Add/Edit modal ───────────────────────────────────────
function buildSingleSelect(elId, options, selectedId, placeholder) {
    const sel = document.getElementById(elId);
    sel.innerHTML = '<option value="">' + (placeholder || '— Select —') + '</option>';
    options.forEach(function (o) {
        const opt = document.createElement('option');
        opt.value = o.id;
        opt.textContent = o.label !== undefined ? o.label : o.username;
        if (selectedId && parseInt(selectedId) === o.id) opt.selected = true;
        sel.appendChild(opt);
    });
}

function initModalSelect2s() {
    const dp = $('#paymentModalBox');
    $('#expenseSelect').select2({ width: '100%', dropdownParent: dp, placeholder: '🔍 Search & select expense…' });
    $('#expenseSelect').on('change', onDateOrExpenseChange);
}

function destroyModalSelect2s() {
    ['expenseSelect'].forEach(function (id) {
        const $el = $('#' + id);
        if ($el.hasClass('select2-hidden-accessible')) $el.select2('destroy');
    });
}

// Only expenses where the current user is listed as an initiator are selectable.
function myInitiatorExpenses() {
    return allExpenses.filter(function (e) { return e.i_am_initiator; });
}

// ── Add / Edit Modal ─────────────────────────────────────────────────────────────
function openAddModal() {
    document.getElementById('paymentId').value = '';
    document.getElementById('modalTitle').textContent = 'Add Expense Payment';
    document.getElementById('modalSub').textContent = 'Record a new payment against an expense you initiate.';
    document.getElementById('paymentDate').value = todayStr();
    document.getElementById('paymentAmount').value = '';
    document.getElementById('paymentRemarks').value = '';
    clearModalErrors();
    resetBudgetStatusBox();

    selectedNewFiles = [];
    removedAttachmentIds = [];
    existingAttachments = [];
    document.getElementById('existingAttachmentsWrap').style.display = 'none';
    renderNewAttachments();

    const myExpenses = myInitiatorExpenses();
    document.getElementById('noInitiatorNote').style.display = myExpenses.length === 0 ? 'block' : 'none';

    destroyModalSelect2s();
    buildSingleSelect('expenseSelect', myExpenses, null, '🔍 Search & select expense…');

    document.getElementById('paymentModal').classList.add('open');
    initModalSelect2s();
    document.getElementById('btnModalSave').disabled = myExpenses.length === 0;
}

function openEditModal(id) {
    const p = currentPayments.find(function (x) { return x.id === id; });
    if (!p) { showToast('❌ Payment not found.', 'error'); return; }
    if (!p.can_edit) { showToast('❌ You do not have permission to edit this payment.', 'error'); return; }

    document.getElementById('paymentId').value = p.id;
    document.getElementById('modalTitle').textContent = 'Edit Expense Payment';
    document.getElementById('modalSub').textContent = 'ID #' + p.id + ' — update the payment details (only possible while still Initiated).';
    document.getElementById('paymentDate').value = p.payment_date;
    document.getElementById('paymentAmount').value = p.amount;
    document.getElementById('paymentRemarks').value = p.remarks || '';
    clearModalErrors();

    selectedNewFiles = [];
    removedAttachmentIds = [];
    existingAttachments = (p.attachments || []).slice();
    renderExistingAttachments();
    renderNewAttachments();

    const myExpenses = myInitiatorExpenses();
    document.getElementById('noInitiatorNote').style.display = 'none';

    destroyModalSelect2s();
    buildSingleSelect('expenseSelect', myExpenses, p.expense_id, '🔍 Search & select expense…');

    document.getElementById('paymentModal').classList.add('open');
    initModalSelect2s();
    document.getElementById('btnModalSave').disabled = false;
    fetchBudgetStatus();
}

function closePaymentModal() {
    document.getElementById('paymentModal').classList.remove('open');
}

function clearModalErrors() {
    ['errPaymentDate', 'errExpense', 'errAmount'].forEach(function (id) {
        const el = document.getElementById(id);
        el.textContent = ''; el.classList.remove('show');
    });
}
function showModalError(id, msg) {
    const el = document.getElementById(id);
    el.textContent = msg; el.classList.add('show');
}

function todayStr() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

// ── Live budget status ────────────────────────────────────────────────────────
function onDateOrExpenseChange() { fetchBudgetStatus(); }

function resetBudgetStatusBox() {
    latestBudgetStatus = null;
    document.getElementById('budgetStatusBox').style.display = 'none';
    document.getElementById('noBudgetNote').style.display = 'none';
}

function fetchBudgetStatus() {
    const expenseId = document.getElementById('expenseSelect').value;
    const paymentDate = document.getElementById('paymentDate').value;
    const paymentId = document.getElementById('paymentId').value;

    if (!expenseId) { resetBudgetStatusBox(); return; }

    const params = new URLSearchParams();
    params.append('ajax_get_budget_status', '1');
    params.append('expense_id', expenseId);
    if (paymentDate) params.append('payment_date', paymentDate);
    if (paymentId) params.append('exclude_id', paymentId);

    fetch('expense_payments.php?' + params.toString())
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            if (!data.success) { resetBudgetStatusBox(); return; }
            latestBudgetStatus = data.budget_status;
            renderBudgetStatus();
        })
        .catch(function () { resetBudgetStatusBox(); });
}

function renderBudgetStatus() {
    const bs = latestBudgetStatus;
    if (!bs || !bs.has_budget) {
        document.getElementById('budgetStatusBox').style.display = 'none';
        document.getElementById('noBudgetNote').style.display = 'block';
        return;
    }
    document.getElementById('noBudgetNote').style.display = 'none';
    document.getElementById('budgetStatusBox').style.display = 'block';

    document.getElementById('bsbBudgetName').textContent = bs.budget_name;
    document.getElementById('bsbPeriod').textContent = (bs.duration === 'annually' ? 'Annual' : 'Monthly') + ' — ' + bs.period_label;
    document.getElementById('bsbLimit').textContent = formatMoney(bs.limit_amount);
    document.getElementById('bsbSpent').textContent = formatMoney(bs.spent);
    document.getElementById('bsbRemaining').textContent = formatMoney(bs.remaining);

    updateAfterAmountPreview();
}

function onAmountChange() { updateAfterAmountPreview(); }

function updateAfterAmountPreview() {
    const bs = latestBudgetStatus;
    const amountVal = parseFloat(document.getElementById('paymentAmount').value);
    const amount = isNaN(amountVal) ? 0 : amountVal;

    if (!bs || !bs.has_budget) return;

    const after = bs.remaining - amount;
    document.getElementById('bsbAfter').textContent = formatMoney(after);

    const usedAfter = bs.limit_amount > 0 ? Math.min(((bs.spent + amount) / bs.limit_amount) * 100, 100) : 0;
    const fill = document.getElementById('bsbBarFill');
    fill.style.width = usedAfter + '%';
    fill.classList.remove('warn', 'over');

    const warnBox = document.getElementById('bsbWarn');
    if (after < 0) {
        fill.classList.add('over');
        warnBox.style.display = 'block';
    } else if (bs.limit_amount > 0 && usedAfter >= 80) {
        fill.classList.add('warn');
        warnBox.style.display = 'none';
    } else {
        warnBox.style.display = 'none';
    }
}

// ── Attachments: new files ───────────────────────────────────────────────────────
function onFilesChosen(fileList) {
    const files = Array.from(fileList || []);
    files.forEach(function (f) { selectedNewFiles.push(f); });
    document.getElementById('attachmentInput').value = '';
    renderNewAttachments();
}

function removeNewFile(idx) {
    selectedNewFiles.splice(idx, 1);
    renderNewAttachments();
}

function renderNewAttachments() {
    const wrap = document.getElementById('newAttachmentsList');
    if (selectedNewFiles.length === 0) { wrap.innerHTML = ''; return; }

    let html = '';
    selectedNewFiles.forEach(function (f, idx) {
        html += '<div class="att-item">' +
            '<i class="fa-solid fa-file-circle-plus att-file-icon"></i>' +
            '<span class="att-name">' + escapeHtml(f.name) + '</span>' +
            '<span class="att-size">' + formatFileSize(f.size) + '</span>' +
            '<button type="button" class="att-remove" onclick="removeNewFile(' + idx + ')" title="Remove"><i class="fa-solid fa-xmark"></i></button>' +
        '</div>';
    });
    wrap.innerHTML = html;
}

// ── Attachments: existing (edit mode) ────────────────────────────────────────────
function renderExistingAttachments() {
    const showWrap = existingAttachments.length > 0;
    document.getElementById('existingAttachmentsWrap').style.display = showWrap ? 'block' : 'none';
    const wrap = document.getElementById('existingAttachmentsList');
    if (!showWrap) { wrap.innerHTML = ''; return; }

    let html = '';
    existingAttachments.forEach(function (a) {
        const marked = removedAttachmentIds.indexOf(a.id) !== -1;
        html += '<div class="att-item' + (marked ? ' marked-removed' : '') + '" id="existingAtt_' + a.id + '">' +
            '<i class="fa-solid fa-file att-file-icon"></i>' +
            '<a class="att-name att-link" href="' + escapeHtml(a.file_path) + '" target="_blank">' + escapeHtml(a.file_name) + '</a>' +
            '<span class="att-size">' + (a.file_size_label || '') + '</span>' +
            '<button type="button" class="att-remove" onclick="toggleRemoveExisting(' + a.id + ')" title="Remove"><i class="fa-solid fa-xmark"></i></button>' +
        '</div>';
    });
    wrap.innerHTML = html;
}

function toggleRemoveExisting(attId) {
    const i = removedAttachmentIds.indexOf(attId);
    if (i === -1) { removedAttachmentIds.push(attId); } else { removedAttachmentIds.splice(i, 1); }
    renderExistingAttachments();
}

// ── Save payment ───────────────────────────────────────────────────────────────
function savePayment() {
    clearModalErrors();

    const id = document.getElementById('paymentId').value;
    const date = document.getElementById('paymentDate').value;
    const expenseId = document.getElementById('expenseSelect').value;
    const amount = document.getElementById('paymentAmount').value;
    const remarks = document.getElementById('paymentRemarks').value.trim();

    let hasError = false;
    if (!date) { showModalError('errPaymentDate', 'Please select a payment date.'); hasError = true; }
    if (!expenseId) { showModalError('errExpense', 'Please select an expense.'); hasError = true; }
    if (!amount || isNaN(amount) || parseFloat(amount) <= 0) { showModalError('errAmount', 'Enter a valid payment amount.'); hasError = true; }
    if (hasError) return;

    const proceed = function () {
        const btn = document.getElementById('btnModalSave');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

        const fd = new FormData();
        fd.append('ajax_save', '1');
        fd.append('id', id);
        fd.append('payment_date', date);
        fd.append('expense_id', expenseId);
        fd.append('amount', amount);
        fd.append('remarks', remarks);
        removedAttachmentIds.forEach(function (rid) { fd.append('removed_attachment_ids[]', rid); });
        selectedNewFiles.forEach(function (f) { fd.append('attachments[]', f); });

        fetch('expense_payments.php', { method: 'POST', body: fd })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Payment';
                if (data.success) {
                    showToast('✅ ' + data.message, 'success');
                    closePaymentModal();
                    loadAll();
                } else {
                    showToast('❌ ' + (data.message || 'Save failed.'), 'error');
                }
            })
            .catch(function (err) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Payment';
                showToast('❌ Network error: ' + err.message, 'error');
            });
    };

    // Soft warning if this payment pushes the budget over its remaining limit
    if (latestBudgetStatus && latestBudgetStatus.has_budget) {
        const after = latestBudgetStatus.remaining - parseFloat(amount);
        if (after < 0) {
            if (!confirm('This payment exceeds the remaining budget limit by Rs. ' + formatMoney(Math.abs(after)) + '. Continue anyway?')) {
                return;
            }
        }
    }

    proceed();
}

// ── View Modal ────────────────────────────────────────────────────────────────
function openViewModal(id) {
    const p = currentPayments.find(function (x) { return x.id === id; });
    if (!p) { showToast('❌ Payment not found.', 'error'); return; }

    viewModalPaymentId = id;
    document.getElementById('viewTitle').textContent = escapeHtml(p.expense_name || 'Expense Payment');
    document.getElementById('viewSub').textContent = 'ID #' + p.id + ' · ' + formatDate(p.payment_date) + ' — Rs. ' + formatMoney(p.amount);

    // Delete button in the view modal is always visible to everyone, unconditionally.
    document.getElementById('viewDeleteBtn').style.display = 'inline-flex';

    function row(label, value, isHtml) {
        const valHtml = value ? (isHtml ? value : escapeHtml(value)) : '<span class="empty">—</span>';
        return '<div class="log-detail-row"><span class="log-detail-label">' + label + '</span><span class="log-detail-value">' + valHtml + '</span></div>';
    }

    let html = '';
    html += row('ID', '<span class="sys-ref">#' + p.id + '</span>', true);
    html += row('Payment Date', formatDate(p.payment_date));
    html += row('Expense', p.expense_name);
    html += row('Budget', p.budget_name);
    html += row('Amount', 'Rs. ' + formatMoney(p.amount));
    html += row('Remarks', p.remarks);
    html += row('Status', '<span class="status-badge ' + p.status + '">' + STATUS_LABELS[p.status] + '</span>', true);
    html += row('Bank reconcile', p.bank_recon
        ? (p.bank_recon.status === 'reconciled' ? 'Reconciled' : 'Reconciled (difference)') + ' — bank ' + formatDate(p.bank_recon.txn_date)
          + ' Rs. ' + formatMoney(p.bank_recon.amount) + ' · <a href="expense_payment_bank_recon.php?tab=saved&batch=' + p.bank_recon.batch_id + '">' + escapeHtml(p.bank_recon.batch_no || 'batch') + '</a>'
        : '<span class="empty">Not reconciled yet</span>', true);
    html += row('Initiated By', p.initiated_by_name);
    if (p.authorized_by_name) html += row('Authorized By', p.authorized_by_name + (p.authorized_at ? ' — ' + formatDate(p.authorized_at) : ''));
    if (p.approved_by_name)   html += row('Approved By', p.approved_by_name + (p.approved_at ? ' — ' + formatDate(p.approved_at) : ''));
    if (p.paid_by_name)       html += row('Paid By', p.paid_by_name + (p.paid_at ? ' — ' + formatDate(p.paid_at) : ''));
    if (p.rejected_by_name)   html += row('Rejected By', p.rejected_by_name + (p.rejected_at ? ' — ' + formatDate(p.rejected_at) : '') + (p.reject_remarks ? ' (' + escapeHtml(p.reject_remarks) + ')' : ''));
    if (p.cancelled_by_name)  html += row('Cancelled By', p.cancelled_by_name + (p.cancelled_at ? ' — ' + formatDate(p.cancelled_at) : '') + (p.cancel_remarks ? ' (' + escapeHtml(p.cancel_remarks) + ')' : ''));

    html += '<div style="margin-top:14px;">';
    html += '<div class="field-label" style="margin-bottom:8px;">Attachments (' + (p.attachments || []).length + ')</div>';
    if ((p.attachments || []).length === 0) {
        html += '<div style="font-size:12px;color:#9ca3af;">No attachments uploaded.</div>';
    } else {
        p.attachments.forEach(function (a) {
            html += '<div class="att-item">' +
                '<i class="fa-solid fa-file att-file-icon"></i>' +
                '<a class="att-name att-link" href="' + escapeHtml(a.file_path) + '" target="_blank">' + escapeHtml(a.file_name) + '</a>' +
                '<span class="att-size">' + (a.file_size_label || '') + '</span>' +
                '<a class="att-size att-link" href="' + escapeHtml(a.file_path) + '" download title="Download"><i class="fa-solid fa-download"></i></a>' +
            '</div>';
        });
    }
    html += '</div>';

    document.getElementById('viewBody').innerHTML = html;
    document.getElementById('viewModal').classList.add('open');
}
function closeViewModal() { document.getElementById('viewModal').classList.remove('open'); }

// ── Workflow actions: Authorize / Unauthorize / Approve / Mark Paid ──────────────
function doWorkflowAction(id, action) {
    const p = currentPayments.find(function (x) { return x.id === id; });
    if (!p) return;

    const labels = {
        authorize:   { verb: 'Authorizing…', done: 'Payment authorized.' },
        unauthorize: { verb: 'Reverting…',   done: 'Reverted to Initiated.' },
        approve:     { verb: 'Approving…',   done: 'Payment approved.' },
        mark_paid:   { verb: 'Marking Paid…', done: 'Marked as Paid.' },
    };
    const l = labels[action];

    const fd = new FormData();
    fd.append('ajax_' + action, '1');
    fd.append('id', id);

    showToast(l.verb, 'success');
    fetch('expense_payments.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            if (data.success) {
                showToast('✅ ' + data.message, 'success');
                loadAll();
            } else {
                showToast('❌ ' + (data.message || 'Action failed.'), 'error');
            }
        })
        .catch(function (err) {
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

// ── Reject Modal ──────────────────────────────────────────────────────────────
function openRejectModal(id) {
    const p = currentPayments.find(function (x) { return x.id === id; });
    if (!p) return;
    rejectTarget = { id: id };
    document.getElementById('rejectModalSub').textContent = 'ID #' + p.id + ' · ' + (p.expense_name || '') + ' — Rs. ' + formatMoney(p.amount);
    document.getElementById('rejectRemarks').value = '';
    document.getElementById('rejectModal').classList.add('open');
}
function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('open');
    rejectTarget = null;
}
function confirmReject() {
    if (!rejectTarget) return;
    const btn = document.getElementById('btnRejectConfirm');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Rejecting…';

    const fd = new FormData();
    fd.append('ajax_reject', '1');
    fd.append('id', rejectTarget.id);
    fd.append('remarks', document.getElementById('rejectRemarks').value.trim());

    fetch('expense_payments.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-ban"></i> Reject';
            closeRejectModal();
            if (data.success) { showToast('✅ ' + data.message, 'success'); loadAll(); }
            else { showToast('❌ ' + (data.message || 'Reject failed.'), 'error'); }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-ban"></i> Reject';
            closeRejectModal();
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

// ── Cancel Modal ───────────────────────────────────────────────────────────────
function openCancelModal(id) {
    const p = currentPayments.find(function (x) { return x.id === id; });
    if (!p) return;
    cancelTarget = { id: id };
    document.getElementById('cancelModalSub').textContent = 'ID #' + p.id + ' · ' + (p.expense_name || '') + ' — Rs. ' + formatMoney(p.amount);
    document.getElementById('cancelRemarks').value = '';
    document.getElementById('cancelModal').classList.add('open');
}
function closeCancelModal() {
    document.getElementById('cancelModal').classList.remove('open');
    cancelTarget = null;
}
function confirmCancel() {
    if (!cancelTarget) return;
    const btn = document.getElementById('btnCancelConfirm');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Cancelling…';

    const fd = new FormData();
    fd.append('ajax_cancel', '1');
    fd.append('id', cancelTarget.id);
    fd.append('remarks', document.getElementById('cancelRemarks').value.trim());

    fetch('expense_payments.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Cancel Payment';
            closeCancelModal();
            if (data.success) { showToast('✅ ' + data.message, 'success'); loadAll(); }
            else { showToast('❌ ' + (data.message || 'Cancel failed.'), 'error'); }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Cancel Payment';
            closeCancelModal();
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

// ── Manual Status Override Modal (authorizer/approver only) ─────────────────────
function openStatusModal(id) {
    const p = currentPayments.find(function (x) { return x.id === id; });
    if (!p) return;
    if (!p.can_manual_override) { showToast('❌ Only an authorizer or approver can manually override the status.', 'error'); return; }
    statusTarget = { id: id, label: p.expense_name };

    document.getElementById('statusModalSub').textContent = 'ID #' + p.id + ' · ' + (p.expense_name || '') + ' — Rs. ' + formatMoney(p.amount);
    const sel = document.getElementById('statusUpdateSelect');
    sel.innerHTML = '';
    Object.keys(STATUS_LABELS).forEach(function (k) {
        const opt = document.createElement('option');
        opt.value = k; opt.textContent = STATUS_LABELS[k];
        if (p.status === k) opt.selected = true;
        sel.appendChild(opt);
    });
    if ($(sel).hasClass('select2-hidden-accessible')) { $(sel).select2('destroy'); }
    document.getElementById('statusModal').classList.add('open');
    $(sel).select2({ width: '100%', dropdownParent: $('#statusModal .modal-box'), minimumResultsForSearch: -1 });
}
function closeStatusModal() {
    document.getElementById('statusModal').classList.remove('open');
    statusTarget = null;
}
function confirmStatusUpdate() {
    if (!statusTarget) return;
    const newStatus = document.getElementById('statusUpdateSelect').value;

    const btn = document.getElementById('btnStatusSave');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Updating…';

    const fd = new FormData();
    fd.append('ajax_update_status', '1');
    fd.append('id', statusTarget.id);
    fd.append('status', newStatus);

    fetch('expense_payments.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Update';
            closeStatusModal();
            if (data.success) {
                showToast('✅ ' + data.message, 'success');
                loadAll();
            } else {
                showToast('❌ ' + (data.message || 'Update failed.'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Update';
            closeStatusModal();
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

// ── Delete Modal ───────────────────────────────────────────────────────────────
function openDeleteModal(id) {
    const p = currentPayments.find(function (x) { return x.id === id; });
    if (!p) return;
    deleteTarget = { id: id, label: 'ID #' + p.id + ' · ' + (p.expense_name || 'this payment') + ' — Rs. ' + formatMoney(p.amount) };
    document.getElementById('deletePayLabel').textContent = deleteTarget.label;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('open');
    deleteTarget = null;
}
function confirmDelete() {
    if (!deleteTarget) return;
    const target = deleteTarget;

    const btn = document.getElementById('btnConfirmDelete');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';

    const fd = new FormData();
    fd.append('ajax_delete', '1');
    fd.append('id', target.id);

    fetch('expense_payments.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete';
            closeDeleteModal();
            if (data.success) {
                showToast('🗑️ ' + data.message, 'success');
                closeViewModal();
                loadAll();
            } else {
                showToast('❌ ' + (data.message || 'Delete failed.'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete';
            closeDeleteModal();
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

// Close modals on backdrop click
document.getElementById('paymentModal').addEventListener('click', function (e) { if (e.target === this) closePaymentModal(); });
document.getElementById('viewModal').addEventListener('click', function (e) { if (e.target === this) closeViewModal(); });
document.getElementById('statusModal').addEventListener('click', function (e) { if (e.target === this) closeStatusModal(); });
document.getElementById('rejectModal').addEventListener('click', function (e) { if (e.target === this) closeRejectModal(); });
document.getElementById('cancelModal').addEventListener('click', function (e) { if (e.target === this) closeCancelModal(); });
document.getElementById('deleteModal').addEventListener('click', function (e) { if (e.target === this) closeDeleteModal(); });

// ── Formatting helpers ────────────────────────────────────────────────────────
function formatMoney(n) {
    n = Number(n) || 0;
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function formatFileSize(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
}
function formatDate(dStr) {
    if (!dStr) return '';
    const d = new Date(dStr.replace(' ', 'T'));
    if (isNaN(d.getTime())) return dStr;
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}
function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : s;
    return d.innerHTML;
}

// ── Toast ──────────────────────────────────────────────────────────────────────
function showToast(msg, type) {
    const colors = { success: '#16a34a', error: '#dc2626', warn: '#d97706' };
    const t = document.createElement('div');
    t.style.cssText =
        'position:fixed;top:20px;right:20px;z-index:99999;background:#fff;' +
        'border-left:4px solid ' + (colors[type] || '#2563eb') + ';border-radius:8px;' +
        'padding:12px 18px;font-size:13px;font-weight:600;color:#111827;' +
        'box-shadow:0 8px 24px rgba(0,0,0,.15);animation:slideInRight .25s ease;max-width:380px;';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () {
        t.style.opacity = '0';
        t.style.transition = 'opacity .3s';
        setTimeout(function () { t.remove(); }, 300);
    }, 3500);
}
</script>

<?php
if (file_exists(__DIR__ . '/footer.php')) {
    include __DIR__ . '/footer.php';
} else {
    echo '</body></html>';
}
?>