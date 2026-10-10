<?php
// ── Yelo Group HMS — Base Expense Payments (inside a Cash Float) ────────────
// Reached via the "Add Payment" icon button on each Cash Float box on cash_float.php:
//   cash_float_add_payment.php?cash_float_id=123
//
// This page ONLY deals with payments raised directly against a Cash Float's own BASE
// expense (the expense that has enable_float = 1). Those payments live in their own
// table — cash_float_base_expense_payments — scoped by cash_float_id, completely
// separate from ordinary expense_payments used elsewhere in the HMS. Shows a summary
// (Limit / Released / Balance) plus the list of Base Expense payments, and an "Add Base
// Expense Payment" modal to record a new one.
//
// BALANCE ADJUSTMENTS: on top of Released/Spent, this page also supports manual
// Balance Adjustments (cash_float_balance_adjustments) — signed corrections (e.g.
// opening balance, bank fee, reconciliation difference) applied directly on top of
// Balance. They carry no workflow/status of their own and no approval chain; any user
// can add one and any user can delete one (same unconditional-delete policy as Base
// Expense Payments — see DELETE POLICY note below). Balance = Released - Spent +
// SUM(adjustments).
//
// AUTO-PROGRESSION RULE: if the Cash Float's base expense has NO authorizer(s) assigned,
// the new payment automatically skips the Authorized stage and moves to Approved-eligible
// (or straight to Approved, if there is also no approver). "Mark as Paid" is never
// auto-triggered — it always requires an explicit approver action.
//
// IMPORTANT — CHAIN SOURCE OF TRUTH: the Initiater/Authorizer/Approver chain shown and
// edited on cash_float_add_expense.php ("Common Approval Chain") writes to the
// `cash_floats` table (and pushes down to child expenses where parent_cash_float_id =
// the float). It does NOT update the base expense's own row in `expenses`
// (cash_floats.expense_id) — that row is never a "child," so it's skipped by that sync
// and can go stale. Because of that, every permission check and the auto-progression
// logic on THIS page reads the chain from the `cash_floats` row, not from the base
// expense row in `expenses`. Do not switch these back to reading off the base expense
// row — that reintroduces the "payment stuck on Initiated" bug where a stale chain on
// the old `expenses` row keeps blocking the auto-authorize/auto-approve skip even after
// the authorizer/approver has been cleared on the Common Approval Chain panel.
//
// ATTACHMENT URLS: file_path is stored relative to THIS script's folder
// (e.g. "uploads/expense_payments/xxx.jpg"). It must be turned into a URL that is
// absolute from the site root before being sent to the browser — otherwise it only
// resolves correctly when the page is requested from exactly this folder depth, and
// silently 404s (broken/blank image) if requested any other way. See
// capAttachmentUrl() below. Do not send raw $file_path straight to the client again.
//
// DELETE POLICY: any user can delete any Base Expense payment, at any status
// (Initiated/Authorized/Approved/Paid/Rejected/Cancelled). There is no permission
// check and no status restriction on delete — it is a hard, unconditional delete.
// This intentionally does NOT re-check Released/Balance impact; the Released figure
// is sourced from the separate expense_payments tracker, not from this table, so a
// hard delete here does not corrupt that number. Do not reintroduce a status or
// permission gate on this action unless explicitly asked. The same unconditional
// policy applies to Balance Adjustments (cash_float_balance_adjustments) below.

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/cash_float_add_payment_error.log');

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
        ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['ajax_save_base']) || isset($_POST['ajax_save_adjustment']) || isset($_POST['ajax_delete_adjustment']))) ||
        isset($_GET['ajax_load']) || isset($_GET['ajax_get_budget_status'])
    )) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . mysqli_connect_error()]);
        exit;
    }
    die('<h3 style="color:red;font-family:sans-serif;padding:20px;">Database connection failed: ' . htmlspecialchars(mysqli_connect_error()) . '</h3>');
}

mysqli_set_charset($conn, 'utf8mb4');

// ── Ensure tables exist ──────────────────────────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS cash_floats (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS expenses (
        id                     INT AUTO_INCREMENT PRIMARY KEY,
        expense_name           VARCHAR(200) NOT NULL,
        category_id            INT NULL,
        enable_float           TINYINT(1) NOT NULL DEFAULT 0,
        mybos_account_id       INT NULL,
        budget_id               INT NULL,
        roi_id                 INT NULL,
        initiater_ids          TEXT NULL,
        authorizer_ids         TEXT NULL,
        approver_ids           TEXT NULL,
        parent_cash_float_id   INT NULL,
        created_at             DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at             DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Payments raised directly against a Cash Float's own BASE expense — kept in a table of
// their own (not expense_payments) so this spend never mixes with anything else.
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS cash_float_base_expense_payments (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS cash_float_base_expense_payment_attachments (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        payment_id   INT NOT NULL,
        file_name    VARCHAR(255) NOT NULL,
        file_path    VARCHAR(500) NOT NULL,
        file_size    INT NULL,
        uploaded_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_payment (payment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Manual Balance Adjustments for a Cash Float — a signed running correction added on
// top of Released - Spent (e.g. correcting for an opening balance, a bank fee, a
// reconciliation difference, etc). Positive amounts increase the Balance, negative
// amounts decrease it. Kept in its own table, scoped by cash_float_id, independent of
// both expense_payments and cash_float_base_expense_payments, and carries no
// status/approval workflow of its own.
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS cash_float_balance_adjustments (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        cash_float_id    INT NOT NULL,
        adjustment_date  DATE NOT NULL,
        amount           DECIMAL(14,2) NOT NULL DEFAULT 0,
        reason           VARCHAR(500) NULL,
        adjusted_by      INT NULL,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_cash_float (cash_float_id),
        INDEX idx_date (adjustment_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Migration safety net ─────────────────────────────────────────────────────
function capAddColumnIfMissing($conn, $table, $col, $definitionSql) {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM $table LIKE '$col'");
    if ($r && mysqli_num_rows($r) === 0) {
        mysqli_query($conn, "ALTER TABLE $table ADD COLUMN $definitionSql");
    }
}
capAddColumnIfMissing($conn, 'cash_float_base_expense_payments', 'auto_authorized', 'auto_authorized TINYINT(1) NOT NULL DEFAULT 0');
capAddColumnIfMissing($conn, 'cash_float_base_expense_payments', 'auto_approved',   'auto_approved TINYINT(1) NOT NULL DEFAULT 0');

// ── Upload settings ───────────────────────────────────────────────────────────
$UPLOAD_DIR_REL = 'uploads/expense_payments/';
$UPLOAD_DIR_ABS = __DIR__ . '/' . $UPLOAD_DIR_REL;
if (!is_dir($UPLOAD_DIR_ABS)) { @mkdir($UPLOAD_DIR_ABS, 0755, true); }
$ALLOWED_EXT   = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
$IMAGE_EXT     = ['jpg', 'jpeg', 'png'];
$MAX_FILE_SIZE = 8 * 1024 * 1024; // 8MB

// ── Helpers ─────────────────────────────────────────────────────────────────────
function capCurrentUserId() {
    foreach (['user_id', 'uid', 'id', 'emp_id', 'employee_id'] as $key) {
        if (isset($_SESSION[$key]) && intval($_SESSION[$key]) > 0) {
            return (int)$_SESSION[$key];
        }
    }
    return 0;
}

function capJsonIds($str) {
    if (!$str) return [];
    $arr = json_decode($str, true);
    return is_array($arr) ? array_map('intval', $arr) : [];
}

function capFormatBytes($bytes) {
    $bytes = (int)$bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes, 1024));
    $i = max(0, min($i, count($units) - 1));
    return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
}

function capIsImageExt($path) {
    global $IMAGE_EXT;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, $IMAGE_EXT, true);
}

// Site base URL — the uploads folder physically lives at the domain root
// (https://mobile.yelogroup.net/uploads/...), independent of whatever folder depth
// or route this script itself is being served from. Hardcoding this avoids the
// broken-image problem entirely instead of trying to derive it from SCRIPT_NAME.
if (!defined('CAP_SITE_BASE_URL')) {
    define('CAP_SITE_BASE_URL', 'https://mobile.yelogroup.net');
}

// Turns a stored relative path (e.g. "uploads/expense_payments/xxx.jpg") into a
// full, absolute URL rooted at CAP_SITE_BASE_URL, so it always resolves correctly
// in the browser no matter what path/route this page itself was requested through.
function capAttachmentUrl($relPath) {
    if ($relPath === null || $relPath === '') return '';
    // Already absolute — leave as-is.
    if (preg_match('#^(https?:)?//#i', $relPath)) {
        return $relPath;
    }
    return rtrim(CAP_SITE_BASE_URL, '/') . '/' . ltrim($relPath, '/');
}

// Every expense id linked to this Cash Float: its own base expense, plus every
// Sub-Expense created inside it (parent_cash_float_id). Used ONLY for the
// "Released" calculation (matching against the main expense_payments
// tracker) — ported directly from the mobile version of this page.
function capExpenseIdsForFloat($conn, $cashFloatId, $baseExpenseId) {
    $ids = [intval($baseExpenseId)];
    $r = mysqli_query($conn, "SELECT id FROM expenses WHERE parent_cash_float_id = " . intval($cashFloatId));
    if ($r) { while ($row = mysqli_fetch_assoc($r)) { $ids[] = (int)$row['id']; } }
    return array_values(array_unique(array_filter($ids)));
}

// Released = the linked expense's ACTUAL Paid amount on the main company-wide
// Expense Payments tracker (expense_payments, status='paid') — the real
// source of truth for Released/Balance. The Base Expense Payments table on
// THIS page (cash_float_base_expense_payments) is a separate request/tracking
// mechanism and does NOT itself count as "released". Matches the mobile
// version of this page exactly.
function capMainExpenseReleased($conn, $cashFloatId, $baseExpenseId) {
    $tblCheck = mysqli_query($conn, "SHOW TABLES LIKE 'expense_payments'");
    if (!$tblCheck || mysqli_num_rows($tblCheck) === 0) { return ['amount' => 0.0, 'count' => 0]; }
    $ids = capExpenseIdsForFloat($conn, $cashFloatId, $baseExpenseId);
    if (empty($ids)) return ['amount' => 0.0, 'count' => 0];
    $inList = implode(',', $ids);
    $r = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt FROM expense_payments WHERE expense_id IN ($inList) AND status = 'paid'");
    if ($r && $row = mysqli_fetch_assoc($r)) { return ['amount' => (float)$row['total'], 'count' => (int)$row['cnt']]; }
    return ['amount' => 0.0, 'count' => 0];
}

// Balance Adjustments total for a Cash Float — signed sum of every manual adjustment
// row recorded against it. Positive rows increase Balance, negative rows decrease it.
// This total is added on top of Released - Spent to get the final Balance figure.
function capAdjustmentsTotalForFloat($conn, $cashFloatId) {
    $cashFloatId = intval($cashFloatId);
    $r = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt FROM cash_float_balance_adjustments WHERE cash_float_id = $cashFloatId");
    if ($r && $row = mysqli_fetch_assoc($r)) {
        return ['amount' => (float)$row['total'], 'count' => (int)$row['cnt']];
    }
    return ['amount' => 0.0, 'count' => 0];
}

// Returns the Cash Float row + its own BASE expense row (enable_float = 1).
// NOTE: the Cash Float row (`cash_floats`) is the up-to-date source for the approval
// chain — see the header comment. The base expense row is still returned (used for
// non-chain fields like budget_id/category linkage on the expense side) but its own
// initiater_ids/authorizer_ids/approver_ids columns must NOT be used for chain checks.
function capBaseExpenseForFloat($conn, $cashFloatId) {
    $cashFloatId = intval($cashFloatId);
    $fr = mysqli_query($conn, "SELECT * FROM cash_floats WHERE id = $cashFloatId LIMIT 1");
    if (!$fr || mysqli_num_rows($fr) === 0) return ['cash_float' => null, 'base_expense' => null];
    $float = mysqli_fetch_assoc($fr);

    $er = mysqli_query($conn, "SELECT * FROM expenses WHERE id = " . intval($float['expense_id']) . " LIMIT 1");
    $baseExpense = ($er && $erow = mysqli_fetch_assoc($er)) ? $erow : null;

    return ['cash_float' => $float, 'base_expense' => $baseExpense];
}

// Budget status for the base expense's linked budget_id, summed against Base Expense
// payments only (the only payment type this page deals with).
function capBudgetStatus($conn, $expense_id, $payment_date) {
    $result = [
        'has_budget' => false, 'budget_id' => null, 'budget_name' => null, 'duration' => null,
        'limit_amount' => 0, 'period_label' => null, 'spent' => 0, 'remaining' => 0,
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
        $period_start = date('Y-01-01', $ts); $period_end = date('Y-12-31', $ts); $period_label = date('Y', $ts);
    } else {
        $period_start = date('Y-m-01', $ts); $period_end = date('Y-m-t', $ts); $period_label = date('F Y', $ts);
    }

    $sr = mysqli_query($conn, "
        SELECT COALESCE(SUM(amount),0) AS spent FROM cash_float_base_expense_payments
        WHERE budget_id = $budget_id AND status NOT IN ('rejected','cancelled')
          AND payment_date BETWEEN '$period_start' AND '$period_end'
    ");
    $spent = 0;
    if ($sr && $srow = mysqli_fetch_assoc($sr)) { $spent = (float)$srow['spent']; }
    $limit = (float)$brow['limit_amount'];

    return [
        'has_budget' => true, 'budget_id' => (int)$brow['id'], 'budget_name' => $brow['budget_name'],
        'duration' => $brow['duration'], 'limit_amount' => $limit, 'period_label' => $period_label,
        'spent' => $spent, 'remaining' => $limit - $spent,
    ];
}

// ── Auto-progression: skip stages that have nobody assigned to action them ──────
//   - No authorizer(s) on the CASH FLOAT's chain -> payment auto-advances 'initiated' -> 'authorized'.
//   - No approver(s) on the CASH FLOAT's chain    -> payment auto-advances 'authorized' -> 'approved'.
//   - Both missing -> cascades straight from 'initiated' to 'approved'.
//   - Never touches 'paid' — Mark as Paid always needs an explicit approver action.
//
// $chainRow must be the CASH FLOAT row (cash_floats), not the base expense row — the
// cash_floats row is the one kept current by the Common Approval Chain panel on
// cash_float_add_expense.php. See header comment for why.
function capAutoProgressStatus($conn, $paymentId, $chainRow) {
    $paymentId = intval($paymentId);
    if (!$paymentId || !$chainRow) return null;

    $authorizerIds = capJsonIds($chainRow['authorizer_ids']);
    $approverIds   = capJsonIds($chainRow['approver_ids']);

    $status = 'initiated';

    if (empty($authorizerIds)) {
        mysqli_query($conn, "
            UPDATE cash_float_base_expense_payments
            SET status = 'authorized', authorized_by = NULL, authorized_at = NOW(), auto_authorized = 1
            WHERE id = $paymentId
            LIMIT 1
        ");
        $status = 'authorized';
    }

    if ($status === 'authorized' && empty($approverIds)) {
        mysqli_query($conn, "
            UPDATE cash_float_base_expense_payments
            SET status = 'approved', approved_by = NULL, approved_at = NOW(), auto_approved = 1
            WHERE id = $paymentId
            LIMIT 1
        ");
        $status = 'approved';
    }

    return $status;
}

// ── AJAX: Get budget status (live in the modal) ──────────────────────────────────
if (isset($_GET['ajax_get_budget_status'])) {
    header('Content-Type: application/json');
    $expense_id   = intval($_GET['expense_id'] ?? 0);
    $payment_date = trim($_GET['payment_date'] ?? '');
    echo json_encode(['success' => true, 'budget_status' => capBudgetStatus($conn, $expense_id, $payment_date)]);
    exit;
}

// ── AJAX: Load the Cash Float — base expense info, payments list, summary ───────
if (isset($_GET['ajax_load'])) {
    header('Content-Type: application/json');

    $cashFloatId = intval($_GET['cash_float_id'] ?? 0);
    if (!$cashFloatId) {
        echo json_encode(['success' => false, 'message' => 'No Cash Float was specified.']);
        exit;
    }

    $data = capBaseExpenseForFloat($conn, $cashFloatId);
    if (!$data['cash_float']) {
        echo json_encode(['success' => false, 'message' => 'Cash Float not found.']);
        exit;
    }
    $float           = $data['cash_float'];
    $baseExpenseRow  = $data['base_expense'];
    $uid             = capCurrentUserId();

    $baseExpenseId     = $baseExpenseRow ? (int)$baseExpenseRow['id'] : (int)$float['expense_id'];
    $baseExpenseName   = $baseExpenseRow ? $baseExpenseRow['expense_name'] : $float['expense_name'];
    // Chain checks read off the CASH FLOAT row ($float), not the base expense row —
    // $float is what the Common Approval Chain panel actually keeps up to date.
    $baseHasAuthorizer = !empty(capJsonIds($float['authorizer_ids']));
    $baseHasApprover   = !empty(capJsonIds($float['approver_ids']));
    $baseInitIds       = capJsonIds($float['initiater_ids']);
    $baseCanInitiate   = in_array($uid, $baseInitIds, true);

    // ── Summary: Limit (from the float's own budget) / Released / Balance ──────
    $limitAmount = null;
    $budgetName  = null;
    if ($float['budget_id']) {
        $br = mysqli_query($conn, "SELECT budget_name, duration, limit_amount FROM budgets WHERE id = " . intval($float['budget_id']) . " LIMIT 1");
        if ($br && $brow = mysqli_fetch_assoc($br)) {
            $limitAmount = (float)$brow['limit_amount'];
            $budgetName  = $brow['budget_name'] . ' (' . ucfirst($brow['duration']) . ')';
        }
    }

    // MENTAL MODEL (matches the mobile version of this page exactly):
    // "Released" is INCOME released INTO this float — the main company-wide
    // Expense Payments tracker's Paid amount, for every expense linked to
    // this float (its own base expense + any sub-expenses created inside
    // it), matched strictly by expense_id, never by name.
    // "Spent" is what's gone OUT of the float via THIS page's own Base
    // Expense Payments (Approved + Paid here).
    // "Adjustments" is a manual signed correction layered on top (see the
    // Balance Adjustments note near the top of this file).
    // Balance = Released (income) minus Spent, plus/minus Adjustments.
    $mainReleased   = capMainExpenseReleased($conn, $cashFloatId, $baseExpenseId);
    $releasedAmount = $mainReleased['amount'];
    $releasedCount  = $mainReleased['count'];

    $spentAmount = 0.0;
    $spentCount  = 0;
    $sr = mysqli_query($conn, "
        SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
        FROM cash_float_base_expense_payments
        WHERE cash_float_id = $cashFloatId AND status IN ('approved','paid')
    ");
    if ($sr && $srow = mysqli_fetch_assoc($sr)) {
        $spentAmount = (float)$srow['total'];
        $spentCount  = (int)$srow['cnt'];
    }

    $adjTotals         = capAdjustmentsTotalForFloat($conn, $cashFloatId);
    $adjustmentsAmount = $adjTotals['amount'];
    $adjustmentsCount  = $adjTotals['count'];

    // ── Base Expense Payments list ──────────────────────────────────────────────
    $basePayments = [];
    $pr = mysqli_query($conn, "
        SELECT bp.*, u.username AS initiated_by_name, ex.expense_name AS linked_expense_name
        FROM cash_float_base_expense_payments bp
        LEFT JOIN users u ON u.id = bp.initiated_by
        LEFT JOIN expenses ex ON ex.id = bp.expense_id
        WHERE bp.cash_float_id = $cashFloatId
        ORDER BY bp.payment_date DESC, bp.id DESC
    ");
    $paymentIds = [];
    if ($pr) {
        while ($row = mysqli_fetch_assoc($pr)) {
            $paymentIds[] = (int)$row['id'];
            $basePayments[] = [
                'id'                => (int)$row['id'],
                'payment_date'      => $row['payment_date'],
                'amount'            => (float)$row['amount'],
                'remarks'           => $row['remarks'],
                'status'            => $row['status'],
                'initiated_by'      => (int)$row['initiated_by'],
                'initiated_by_name' => $row['initiated_by_name'],
                'expense_id'        => (int)$row['expense_id'],
                'expense_name'      => $row['linked_expense_name'],
                'auto_authorized'   => (int)$row['auto_authorized'] === 1,
                'auto_approved'     => (int)$row['auto_approved'] === 1,
                'attachments'       => [],
            ];
        }
    }
    if (!empty($paymentIds)) {
        $pidList = implode(',', $paymentIds);
        $ar = mysqli_query($conn, "SELECT id, payment_id, file_name, file_path, file_size FROM cash_float_base_expense_payment_attachments WHERE payment_id IN ($pidList) ORDER BY id ASC");
        $attMap = [];
        if ($ar) {
            while ($arow = mysqli_fetch_assoc($ar)) {
                $attMap[(int)$arow['payment_id']][] = [
                    'id'              => (int)$arow['id'],
                    'file_name'       => $arow['file_name'],
                    // Send a browser-resolvable absolute URL, not the raw stored
                    // relative path — this is what fixes attachment images/files
                    // not loading. See capAttachmentUrl() above.
                    'file_path'       => capAttachmentUrl($arow['file_path']),
                    'file_size'       => (int)$arow['file_size'],
                    'file_size_label' => capFormatBytes($arow['file_size']),
                    'is_image'        => capIsImageExt($arow['file_path']),
                ];
            }
        }
        foreach ($basePayments as &$p) { $p['attachments'] = $attMap[$p['id']] ?? []; }
        unset($p);
    }

    // ── Balance Adjustments list ─────────────────────────────────────────────────
    $adjustments = [];
    $adjr = mysqli_query($conn, "
        SELECT a.*, u.username AS adjusted_by_name
        FROM cash_float_balance_adjustments a
        LEFT JOIN users u ON u.id = a.adjusted_by
        WHERE a.cash_float_id = $cashFloatId
        ORDER BY a.adjustment_date DESC, a.id DESC
    ");
    if ($adjr) {
        while ($arow = mysqli_fetch_assoc($adjr)) {
            $adjustments[] = [
                'id'               => (int)$arow['id'],
                'adjustment_date'  => $arow['adjustment_date'],
                'amount'           => (float)$arow['amount'],
                'reason'           => $arow['reason'],
                'adjusted_by'      => (int)$arow['adjusted_by'],
                'adjusted_by_name' => $arow['adjusted_by_name'],
            ];
        }
    }

    $balance = $releasedAmount - $spentAmount + $adjustmentsAmount;

    echo json_encode([
        'success'             => true,
        'cash_float_name'     => $float['expense_name'],
        'current_user_id'     => $uid,
        'base_expense_id'     => $baseExpenseId,
        'base_expense_name'   => $baseExpenseName,
        'base_has_authorizer' => $baseHasAuthorizer,
        'base_has_approver'   => $baseHasApprover,
        'base_can_initiate'   => $baseCanInitiate,
        'base_payments'       => $basePayments,
        'adjustments'         => $adjustments,
        'summary'             => [
            'limit_amount'       => $limitAmount,
            'budget_name'        => $budgetName,
            'released_amount'    => $releasedAmount,
            'released_count'     => $releasedCount,
            'spent_amount'       => $spentAmount,
            'spent_count'        => $spentCount,
            'adjustments_amount' => $adjustmentsAmount,
            'adjustments_count'  => $adjustmentsCount,
            'balance'            => $balance,
        ],
    ]);
    exit;
}

// ── AJAX: Save a Base Expense payment (+ attachments) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save_base'])) {
    header('Content-Type: application/json');

    $cashFloatId  = intval($_POST['cash_float_id'] ?? 0);
    $payment_date = trim($_POST['payment_date'] ?? '');
    $amount       = trim($_POST['amount'] ?? '');
    $remarks      = mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''));
    $uid          = capCurrentUserId();

    if (!$cashFloatId) {
        echo json_encode(['success' => false, 'message' => 'Invalid Cash Float.']);
        exit;
    }
    if ($payment_date === '' || !strtotime($payment_date)) {
        echo json_encode(['success' => false, 'message' => 'Please select a valid payment date.']);
        exit;
    }
    if ($amount === '' || !is_numeric($amount) || floatval($amount) <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid payment amount.']);
        exit;
    }
    $amount = floatval($amount);

    $data = capBaseExpenseForFloat($conn, $cashFloatId);
    if (!$data['cash_float']) {
        echo json_encode(['success' => false, 'message' => 'Cash Float not found.']);
        exit;
    }
    $float          = $data['cash_float'];
    $baseExpenseRow = $data['base_expense'];
    if (!$baseExpenseRow) {
        echo json_encode(['success' => false, 'message' => 'This Cash Float has no base expense configured.']);
        exit;
    }

    // Initiator check reads off the CASH FLOAT row — the chain the Common Approval
    // Chain panel keeps current — not off the (potentially stale) base expense row.
    $initIds = capJsonIds($float['initiater_ids']);
    if (!in_array($uid, $initIds, true)) {
        echo json_encode(['success' => false, 'message' => 'You are not set up as an initiator for this Cash Float\'s base expense.']);
        exit;
    }

    $budget_id        = $baseExpenseRow['budget_id'] ? intval($baseExpenseRow['budget_id']) : 0;
    $payment_date_esc = mysqli_real_escape_string($conn, $payment_date);
    $budgetSql        = $budget_id ? $budget_id : 'NULL';
    $initiatedBySql   = $uid ? $uid : 'NULL';
    $baseExpenseId    = (int)$baseExpenseRow['id'];

    $ok = mysqli_query($conn, "
        INSERT INTO cash_float_base_expense_payments (payment_date, cash_float_id, expense_id, budget_id, amount, remarks, initiated_by, status)
        VALUES ('$payment_date_esc', $cashFloatId, $baseExpenseId, $budgetSql, $amount, '$remarks', $initiatedBySql, 'initiated')
    ");
    if (!$ok) {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
        exit;
    }
    $newId = mysqli_insert_id($conn);

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
            $storedName = 'basepay_' . $newId . '_' . time() . '_' . mt_rand(1000, 9999) . '_' . $safeName;
            $destAbs    = $UPLOAD_DIR_ABS . $storedName;

            if (move_uploaded_file($tmpPath, $destAbs)) {
                $relPath     = mysqli_real_escape_string($conn, $UPLOAD_DIR_REL . $storedName);
                $origNameEsc = mysqli_real_escape_string($conn, $origName);
                mysqli_query($conn, "
                    INSERT INTO cash_float_base_expense_payment_attachments (payment_id, file_name, file_path, file_size)
                    VALUES ($newId, '$origNameEsc', '$relPath', $size)
                ");
            } else {
                $uploadErrors[] = $origName . ' — could not be saved.';
            }
        }
    }

    // Auto-progression must key off the CASH FLOAT's chain ($float), not the base
    // expense row — see header comment / function doc-comment for why.
    $finalStatus = capAutoProgressStatus($conn, $newId, $float);

    $msg = 'Base expense payment added.';
    if ($finalStatus === 'authorized') {
        $msg .= ' No authorizer is assigned to this expense, so it was auto-advanced to Authorized.';
    } elseif ($finalStatus === 'approved') {
        $msg .= ' No authorizer/approver is assigned to this expense, so it was auto-advanced to Approved.';
    }
    if (!empty($uploadErrors)) { $msg .= ' (' . implode(' ', $uploadErrors) . ')'; }

    echo json_encode(['success' => true, 'id' => $newId, 'message' => $msg]);
    exit;
}

// ── AJAX: Delete a Base Expense payment ──────────────────────────────────────────
// UNCONDITIONAL DELETE: any user can delete any Base Expense payment, regardless of
// its status (Initiated/Authorized/Approved/Paid/Rejected/Cancelled). There is no
// permission check and no status gate here — this is intentional per request. The
// float's Released figure is sourced from the separate expense_payments tracker
// (see capMainExpenseReleased), not from this table, so deleting a row here does
// not corrupt Released; it only removes it from the Spent total on this page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete_base'])) {
    header('Content-Type: application/json');

    $paymentId = intval($_POST['payment_id'] ?? 0);

    if (!$paymentId) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment.']);
        exit;
    }

    $pr = mysqli_query($conn, "SELECT * FROM cash_float_base_expense_payments WHERE id = $paymentId LIMIT 1");
    if (!$pr || mysqli_num_rows($pr) === 0) {
        echo json_encode(['success' => false, 'message' => 'Payment not found.']);
        exit;
    }
    $payment = mysqli_fetch_assoc($pr);

    // Remove any uploaded attachment files from disk before deleting their DB rows.
    $ar = mysqli_query($conn, "SELECT file_path FROM cash_float_base_expense_payment_attachments WHERE payment_id = $paymentId");
    if ($ar) {
        while ($arow = mysqli_fetch_assoc($ar)) {
            $absPath = __DIR__ . '/' . ltrim($arow['file_path'], '/');
            if (is_file($absPath)) { @unlink($absPath); }
        }
    }
    mysqli_query($conn, "DELETE FROM cash_float_base_expense_payment_attachments WHERE payment_id = $paymentId");
    mysqli_query($conn, "DELETE FROM cash_float_base_expense_payments WHERE id = $paymentId LIMIT 1");

    echo json_encode(['success' => true, 'message' => 'Payment deleted.']);
    exit;
}

// ── AJAX: Save a Balance Adjustment ──────────────────────────────────────────────
// Any user may add a Balance Adjustment — it's a manual correction, not a workflow
// item, so it carries no status and no approval chain of its own. Positive amounts
// increase the Balance, negative amounts decrease it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save_adjustment'])) {
    header('Content-Type: application/json');

    $cashFloatId     = intval($_POST['cash_float_id'] ?? 0);
    $adjustment_date = trim($_POST['adjustment_date'] ?? '');
    $amount          = trim($_POST['amount'] ?? '');
    $reason          = mysqli_real_escape_string($conn, trim($_POST['reason'] ?? ''));
    $uid             = capCurrentUserId();

    if (!$cashFloatId) {
        echo json_encode(['success' => false, 'message' => 'Invalid Cash Float.']);
        exit;
    }
    if ($adjustment_date === '' || !strtotime($adjustment_date)) {
        echo json_encode(['success' => false, 'message' => 'Please select a valid adjustment date.']);
        exit;
    }
    if ($amount === '' || !is_numeric($amount) || floatval($amount) == 0) {
        echo json_encode(['success' => false, 'message' => 'Please enter a non-zero adjustment amount (positive to increase the Balance, negative to decrease it).']);
        exit;
    }
    $amount = floatval($amount);

    $fr = mysqli_query($conn, "SELECT id FROM cash_floats WHERE id = $cashFloatId LIMIT 1");
    if (!$fr || mysqli_num_rows($fr) === 0) {
        echo json_encode(['success' => false, 'message' => 'Cash Float not found.']);
        exit;
    }

    $dateEsc       = mysqli_real_escape_string($conn, $adjustment_date);
    $adjustedBySql = $uid ? $uid : 'NULL';

    $ok = mysqli_query($conn, "
        INSERT INTO cash_float_balance_adjustments (cash_float_id, adjustment_date, amount, reason, adjusted_by)
        VALUES ($cashFloatId, '$dateEsc', $amount, '$reason', $adjustedBySql)
    ");
    if (!$ok) {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
        exit;
    }

    echo json_encode(['success' => true, 'id' => mysqli_insert_id($conn), 'message' => 'Balance adjustment saved.']);
    exit;
}

// ── AJAX: Delete a Balance Adjustment ─────────────────────────────────────────────
// UNCONDITIONAL DELETE: same policy as Base Expense Payments on this page — any user,
// no status/permission gate. See DELETE POLICY note at the top of this file.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete_adjustment'])) {
    header('Content-Type: application/json');

    $adjustmentId = intval($_POST['adjustment_id'] ?? 0);
    if (!$adjustmentId) {
        echo json_encode(['success' => false, 'message' => 'Invalid adjustment.']);
        exit;
    }

    $r = mysqli_query($conn, "SELECT id FROM cash_float_balance_adjustments WHERE id = $adjustmentId LIMIT 1");
    if (!$r || mysqli_num_rows($r) === 0) {
        echo json_encode(['success' => false, 'message' => 'Adjustment not found.']);
        exit;
    }

    mysqli_query($conn, "DELETE FROM cash_float_balance_adjustments WHERE id = $adjustmentId LIMIT 1");
    echo json_encode(['success' => true, 'message' => 'Adjustment deleted.']);
    exit;
}

$initialCashFloatId = intval($_GET['cash_float_id'] ?? 0);

if (file_exists(__DIR__ . '/header.php')) {
    include __DIR__ . '/header.php';
} else {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Base Expense Payments</title>
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
                <i class="fa-solid fa-building-columns" style="color:#7c3aed;margin-right:8px;"></i>Base Expense Payments
            </h2>
            <p class="page-subtitle" id="pageSub">Inside a Cash Float…</p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="btn btn-primary" id="btnOpenAddBase" onclick="openAddBaseModal()" disabled style="background:#7c3aed;">
                <i class="fa-solid fa-plus"></i> Add Base Expense Payment
            </button>
            <button class="btn btn-primary" id="btnOpenAdjust" onclick="openAdjustModal()" style="background:#0891b2;">
                <i class="fa-solid fa-sliders"></i> Adjust Balance
            </button>
            <button class="btn btn-light" id="btnOpenAdjustHistory" onclick="openAdjustmentHistoryModal()">
                <i class="fa-solid fa-clock-rotate-left"></i> Adjustment History
            </button>
            <a href="cash_float.php" class="btn btn-light">
                <i class="fa-solid fa-arrow-left"></i> Back to Cash Floats
            </a>
        </div>
    </div>
</div>

<div id="loadingNote" class="content-card" style="text-align:center;padding:24px;color:#9ca3af;">
    <i class="fa-solid fa-spinner fa-spin" style="font-size:20px;margin-bottom:8px;display:block;"></i>Loading Cash Float details…
</div>

<div id="errorNote" class="content-card" style="display:none;text-align:center;padding:24px;color:#dc2626;"></div>

<div id="pageArea" style="display:none;">
    <!-- Summary: Limit / Released / Spent / Adjustments / Balance -->
    <div class="content-card" style="margin-bottom:16px;">
        <div class="summary-grid">
            <div class="summary-cell">
                <div class="summary-label"><i class="fa-solid fa-wallet"></i> Limit</div>
                <div class="summary-value" id="sumLimit">—</div>
                <div class="summary-sub" id="sumBudgetName"></div>
            </div>
            <div class="summary-cell released">
                <div class="summary-label"><i class="fa-solid fa-circle-check"></i> Released</div>
                <div class="summary-value" id="sumReleased">Rs. 0.00</div>
                <div class="summary-sub" id="sumReleasedCount">0 payments</div>
            </div>
            <div class="summary-cell paid">
                <div class="summary-label"><i class="fa-solid fa-money-bill-wave"></i> Spent</div>
                <div class="summary-value" id="sumPaid">Rs. 0.00</div>
                <div class="summary-sub" id="sumPaidCount">0 payments</div>
            </div>
            <div class="summary-cell adjustments">
                <div class="summary-label"><i class="fa-solid fa-sliders"></i> Adjustments</div>
                <div class="summary-value" id="sumAdjustments">Rs. 0.00</div>
                <div class="summary-sub"><a href="javascript:void(0)" onclick="openAdjustmentHistoryModal()" id="sumAdjustmentsCount">0 adjustments</a></div>
            </div>
            <div class="summary-cell balance">
                <div class="summary-label"><i class="fa-solid fa-scale-balanced"></i> Balance</div>
                <div class="summary-value" id="sumBalance">—</div>
                <div class="summary-sub">Released − Spent ± Adjustments</div>
            </div>
        </div>
    </div>

    <!-- Base Expense payments table -->
    <div class="content-card">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
            <i class="fa-solid fa-building-columns" style="color:#7c3aed;"></i>
            <div style="font-weight:700;font-size:14px;color:#111827;">Base Expense Payments — <span id="baseExpenseNameLabel">this Cash Float</span></div>
        </div>
        <div class="table-responsive">
            <table class="pay-table">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th style="width:110px;">Date</th>
                        <th style="width:130px;">Amount</th>
                        <th style="min-width:160px;">Expense</th>
                        <th style="min-width:220px;">Remarks</th>
                        <th style="width:100px;">Attachments</th>
                        <th style="width:130px;">Initiated By</th>
                        <th style="width:110px;">Status</th>
                        <th style="width:90px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="basePayBody">
                    <tr><td colspan="9" style="text-align:center;padding:30px;color:#9ca3af;">No base expense payments yet.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Base Expense Payment Modal -->
<div id="addBaseModal" class="modal-overlay">
    <div class="modal-box" id="addBaseModalBox" style="max-width:640px;">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:12px;">
                <div class="modal-icon" style="background:#f5f3ff;"><i class="fa-solid fa-building-columns" style="color:#7c3aed;"></i></div>
                <div>
                    <div class="modal-title">Add Base Expense Payment</div>
                    <div class="modal-sub" id="addBaseModalSub"></div>
                </div>
            </div>
            <button class="modal-close" onclick="closeAddBaseModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="modal-body">
            <div class="form-group" id="baseExpenseTypeRow" style="display:none;">
                <label class="field-label">Expense</label>
                <div class="expense-type-badge" id="baseExpenseTypeValue">—</div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="field-label">Payment Date <span class="req">*</span></label>
                    <input type="date" id="basePaymentDate" class="modal-input" onchange="fetchBudgetStatusBase()">
                    <div class="err-msg" id="errBasePaymentDate"></div>
                </div>
            </div>

            <div id="autoWorkflowNoteBase" class="no-budget-note" style="display:none;">
                <i class="fa-solid fa-circle-info"></i> <span id="autoWorkflowNoteTextBase"></span>
            </div>

            <div id="budgetStatusBoxBase" class="budget-status-box" style="display:none;">
                <div class="bsb-head">
                    <i class="fa-solid fa-wallet"></i>
                    <span id="bsbBudgetNameBase">—</span>
                    <span class="bsb-period" id="bsbPeriodBase"></span>
                </div>
                <div class="bsb-grid">
                    <div class="bsb-cell"><span class="bsb-label">Limit</span><span class="bsb-value" id="bsbLimitBase">0.00</span></div>
                    <div class="bsb-cell"><span class="bsb-label">Already Spent</span><span class="bsb-value" id="bsbSpentBase">0.00</span></div>
                    <div class="bsb-cell"><span class="bsb-label">Remaining</span><span class="bsb-value" id="bsbRemainingBase">0.00</span></div>
                    <div class="bsb-cell"><span class="bsb-label">After This Payment</span><span class="bsb-value" id="bsbAfterBase">0.00</span></div>
                </div>
                <div class="bsb-bar-track"><div class="bsb-bar-fill" id="bsbBarFillBase"></div></div>
                <div class="bsb-warn" id="bsbWarnBase" style="display:none;">
                    <i class="fa-solid fa-triangle-exclamation"></i> This payment will exceed the remaining budget limit.
                </div>
            </div>
            <div id="noBudgetNoteBase" class="no-budget-note" style="display:none;">
                <i class="fa-solid fa-circle-info"></i> This expense has no budget linked, so no limit tracking is shown.
            </div>

            <div class="form-row" style="margin-top:16px;">
                <div class="form-group">
                    <label class="field-label">Payment Amount (Rs.) <span class="req">*</span></label>
                    <input type="number" id="basePaymentAmount" class="modal-input" placeholder="0.00" step="0.01" min="0" oninput="updateAfterAmountPreviewBase()">
                    <div class="err-msg" id="errBaseAmount"></div>
                </div>
            </div>

            <div class="form-group">
                <label class="field-label">Remarks</label>
                <textarea id="basePaymentRemarks" class="modal-input" rows="2" placeholder="Optional notes about this payment…"></textarea>
            </div>

            <hr style="margin:18px 0;border:none;border-top:1px solid #f1f5f9;">

            <div class="form-group">
                <label class="field-label"><i class="fa-solid fa-paperclip" style="color:#7c3aed;margin-right:6px;"></i>Attachments</label>
                <div class="file-drop" onclick="document.getElementById('baseAttachmentInput').click();">
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                    <div>Click to choose files (multiple allowed)</div>
                    <div class="file-drop-sub">JPG, PNG, PDF, DOC, XLS — max 8MB each</div>
                </div>
                <input type="file" id="baseAttachmentInput" multiple style="display:none;" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx" onchange="onFilesChosenBase(this.files)">
                <div id="newBaseAttachmentsList" style="margin-top:10px;"></div>
            </div>
        </div>

        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeAddBaseModal()">Cancel</button>
            <button class="btn btn-primary" id="btnSaveBase" onclick="saveBasePayment()" style="background:#7c3aed;">
                <i class="fa-solid fa-floppy-disk"></i> Save Payment
            </button>
        </div>
    </div>
</div>

<!-- Add Balance Adjustment Modal -->
<div id="addAdjustModal" class="modal-overlay">
    <div class="modal-box" style="max-width:520px;">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:12px;">
                <div class="modal-icon" style="background:#ecfeff;"><i class="fa-solid fa-sliders" style="color:#0891b2;"></i></div>
                <div>
                    <div class="modal-title">Adjust Balance</div>
                    <div class="modal-sub">Add a manual, signed correction to this Cash Float's Balance</div>
                </div>
            </div>
            <button class="modal-close" onclick="closeAdjustModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="field-label">Adjustment Date <span class="req">*</span></label>
                <input type="date" id="adjDate" class="modal-input">
                <div class="err-msg" id="errAdjDate"></div>
            </div>
            <div class="form-group">
                <label class="field-label">Adjustment Amount (Rs.) <span class="req">*</span></label>
                <input type="number" id="adjAmount" class="modal-input" placeholder="e.g. 500 or -500" step="0.01">
                <div class="err-msg" id="errAdjAmount"></div>
                <div style="font-size:11px;color:#6b7280;margin-top:4px;">Use a positive number to increase the Balance, or a negative number to decrease it.</div>
            </div>
            <div class="form-group">
                <label class="field-label">Reason / Remarks</label>
                <textarea id="adjReason" class="modal-input" rows="2" placeholder="Why is this adjustment being made?"></textarea>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeAdjustModal()">Cancel</button>
            <button class="btn btn-primary" id="btnSaveAdjust" onclick="saveAdjustment()" style="background:#0891b2;">
                <i class="fa-solid fa-floppy-disk"></i> Save Adjustment
            </button>
        </div>
    </div>
</div>

<!-- Balance Adjustment History Modal -->
<div id="adjustHistoryModal" class="modal-overlay">
    <div class="modal-box" style="max-width:680px;">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:12px;">
                <div class="modal-icon" style="background:#ecfeff;"><i class="fa-solid fa-clock-rotate-left" style="color:#0891b2;"></i></div>
                <div>
                    <div class="modal-title">Balance Adjustment History</div>
                    <div class="modal-sub">Every manual adjustment recorded for this Cash Float</div>
                </div>
            </div>
            <button class="modal-close" onclick="closeAdjustmentHistoryModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="table-responsive">
                <table class="pay-table">
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th style="width:110px;">Date</th>
                            <th style="width:130px;">Amount</th>
                            <th style="min-width:200px;">Reason</th>
                            <th style="width:130px;">Adjusted By</th>
                            <th style="width:60px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="adjustHistoryBody">
                        <tr><td colspan="6" style="text-align:center;padding:30px;color:#9ca3af;">No balance adjustments recorded yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeAdjustmentHistoryModal()">Close</button>
        </div>
    </div>
</div>

<!-- View Payment Modal — details + attachment preview -->
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
            <button class="btn btn-light" id="viewDeleteBtn" onclick="deleteBasePayment(viewModalPaymentId)" style="display:none;background:#fef2f2;color:#dc2626;">
                <i class="fa-solid fa-trash"></i> Delete
            </button>
            <button class="btn btn-light" onclick="closeViewModal()">Close</button>
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

.summary-grid { display:grid;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));gap:14px; }
.summary-cell { background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px; }
.summary-cell.released { background:#f0fdf4;border-color:#bbf7d0; }
.summary-cell.paid     { background:#eff6ff;border-color:#bfdbfe; }
.summary-cell.adjustments { background:#ecfeff;border-color:#a5f3fc; }
.summary-cell.balance  { background:#fef3c7;border-color:#fde68a; }
.summary-label { font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;display:flex;align-items:center;gap:6px;margin-bottom:6px; }
.summary-cell.released .summary-label { color:#166534; }
.summary-cell.paid     .summary-label { color:#1d4ed8; }
.summary-cell.adjustments .summary-label { color:#0e7490; }
.summary-cell.balance  .summary-label { color:#92400e; }
.summary-value { font-size:20px;font-weight:800;color:#111827; }
.summary-cell.released .summary-value { color:#166534; }
.summary-cell.paid     .summary-value { color:#1d4ed8; }
.summary-cell.adjustments .summary-value { color:#0e7490; }
.summary-cell.balance  .summary-value { color:#92400e; }
.summary-value.negative { color:#dc2626 !important; }
.summary-sub { font-size:11px;color:#9ca3af;margin-top:4px; }
.summary-sub a { color:#0891b2;text-decoration:none;font-weight:600; }
.summary-sub a:hover { text-decoration:underline; }
@media(max-width:900px) { .summary-grid { grid-template-columns:repeat(2, 1fr); } }
@media(max-width:520px) { .summary-grid { grid-template-columns:1fr; } }

.table-responsive { overflow-x:auto; }
.pay-table { width:100%;border-collapse:collapse;font-size:13px; }
.pay-table thead { background:#f8fafc;border-bottom:2px solid #e2e8f0; }
.pay-table th {
    padding:9px 12px;text-align:left;font-weight:600;
    color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;
}
.pay-table tbody tr { border-bottom:1px solid #f1f5f9; }
.pay-table tbody tr:hover { background:#f8fafc; }
.pay-table td { padding:9px 12px;vertical-align:middle;color:#374151; }

/* Remarks column: show the full text, wrapped, instead of being cut off */
.remarks-cell {
    white-space: normal;
    word-break: break-word;
    max-width: 320px;
    line-height: 1.4;
}
.remarks-cell.empty { color:#9ca3af;font-style:italic; }

.att-count { display:inline-flex;align-items:center;gap:6px;background:#f3f4f6;color:#374151;border-radius:14px;padding:3px 10px;font-size:11px;font-weight:600;cursor:pointer; }
.att-count:hover { background:#e5e7eb; }
.att-count.none { color:#9ca3af;cursor:default; }
.att-count.none:hover { background:#f3f4f6; }

.status-badge { display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600; }
.status-badge.initiated  { background:#e0f2fe;color:#0369a1; }
.status-badge.authorized { background:#ede9fe;color:#6d28d9; }
.status-badge.approved   { background:#dcfce7;color:#16a34a; }
.status-badge.paid       { background:#dbeafe;color:#1d4ed8; }
.status-badge.rejected   { background:#fef2f2;color:#dc2626; }
.status-badge.cancelled  { background:#f3f4f6;color:#6b7280; }

.btn-icon {
    display:inline-flex;align-items:center;justify-content:center;
    width:28px;height:28px;border:none;border-radius:6px;
    cursor:pointer;font-size:12px;transition:all .15s;
}
.btn-icon.view { background:#eff6ff;color:#2563eb; }
.btn-icon.view:hover { background:#dbeafe; }
.btn-icon.delete { background:#fef2f2;color:#dc2626;margin-left:4px; }
.btn-icon.delete:hover { background:#fee2e2; }
.action-cell { display:flex;align-items:center;gap:0; }

.field-label { font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;display:block; }
.req { color:#ef4444; }
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
.no-budget-note { background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:10px 14px;font-size:12px;font-weight:600;margin-top:4px;margin-bottom:14px; }
.expense-type-badge {
    display:inline-flex;align-items:center;gap:6px;background:#f5f3ff;color:#6d28d9;
    border:1px solid #ddd6fe;border-radius:8px;padding:8px 12px;font-size:13px;font-weight:700;
}
@media(max-width:640px) { .bsb-grid { grid-template-columns:repeat(2, 1fr); } }

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
.att-item a.att-link { color:#2563eb;text-decoration:none; }
.att-item a.att-link:hover { text-decoration:underline; }

/* Attachment preview thumbnails inside the View modal */
.att-preview-grid { display:grid;grid-template-columns:repeat(auto-fill, minmax(90px, 1fr));gap:10px;margin-top:10px; }
.att-preview-item { border:1px solid #f1f5f9;border-radius:8px;overflow:hidden;background:#fafafa;text-decoration:none;display:block; }
.att-preview-thumb { width:100%;height:70px;object-fit:cover;display:block;background:#eef2f7; }
.att-preview-icon { width:100%;height:70px;display:flex;align-items:center;justify-content:center;background:#eef2f7;color:#2563eb;font-size:24px; }
.att-preview-name { font-size:10px;color:#374151;padding:4px 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }

.log-detail-row { display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:13px; }
.log-detail-row:last-child { border-bottom:none; }
.log-detail-label { color:#6b7280;font-weight:600;flex-shrink:0; }
.log-detail-value { color:#111827;text-align:right; }
.log-detail-value.empty { color:#9ca3af;font-style:italic; }

.modal-overlay {
    display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99998;
    align-items:center;justify-content:center;padding:16px;
}
.modal-overlay.open { display:flex; }
.modal-box {
    background:#fff;border-radius:14px;max-width:560px;width:100%;
    box-shadow:0 12px 40px rgba(0,0,0,.25);max-height:92vh;overflow-y:auto;
}
.modal-head {
    display:flex;align-items:center;justify-content:space-between;
    padding:20px 22px 14px;border-bottom:1px solid #f1f5f9;position:sticky;top:0;background:#fff;z-index:2;
}
.modal-icon {
    width:42px;height:42px;border-radius:50%;background:#eff6ff;
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

@media(max-width:768px) {
    .content-card { padding:14px; }
    .form-row { flex-direction:column; }
}
</style>

<script>
const cashFloatId = <?php echo (int)$initialCashFloatId; ?>;

let currentBasePayments = [];
let currentAdjustments = [];
let selectedNewBaseFiles = [];
let latestBudgetStatusBase = null;
let baseExpenseId = 0;
let baseExpenseName = null;
let baseCanInitiate = false;
let baseHasAuthorizer = false;
let baseHasApprover = false;
let currentUserId = 0;
let viewModalPaymentId = 0;

$(document).ready(function () {
    if (!cashFloatId) {
        showLoadError('No Cash Float was specified. Go back and use the "Add Payment" button on a Cash Float box.');
        return;
    }
    loadAll();
});

function showLoadError(msg) {
    document.getElementById('loadingNote').style.display = 'none';
    document.getElementById('pageArea').style.display = 'none';
    const el = document.getElementById('errorNote');
    el.style.display = 'block';
    el.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="font-size:20px;margin-bottom:8px;display:block;"></i>' + escapeHtml(msg);
}

function loadAll() {
    fetch('cash_float_add_payment.php?ajax_load=1&cash_float_id=' + encodeURIComponent(cashFloatId))
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            if (!data.success) { showLoadError(data.message || 'Failed to load Cash Float.'); return; }

            document.getElementById('pageSub').textContent = 'Inside: ' + data.cash_float_name;

            currentBasePayments = data.base_payments || [];
            currentAdjustments  = data.adjustments || [];
            baseExpenseId       = data.base_expense_id || 0;
            baseExpenseName     = data.base_expense_name || null;
            baseCanInitiate     = !!data.base_can_initiate;
            baseHasAuthorizer   = !!data.base_has_authorizer;
            baseHasApprover     = !!data.base_has_approver;
            currentUserId       = data.current_user_id || 0;

            renderSummary(data.summary || {});
            renderBasePayments(currentBasePayments);
            renderAdjustmentHistory();

            document.getElementById('btnOpenAddBase').disabled = !baseCanInitiate;
            document.getElementById('btnOpenAddBase').title = baseCanInitiate
                ? ''
                : 'You are not set up as an initiator for this Cash Float\'s base expense, so there is nothing to add a payment for.';
            document.getElementById('baseExpenseNameLabel').textContent = data.cash_float_name;

            document.getElementById('loadingNote').style.display = 'none';
            document.getElementById('errorNote').style.display = 'none';
            document.getElementById('pageArea').style.display = 'block';
        })
        .catch(function (err) {
            showLoadError('Failed to load: ' + err.message);
        });
}

function renderSummary(summary) {
    const hasLimit = summary.limit_amount !== null && summary.limit_amount !== undefined;
    document.getElementById('sumLimit').textContent = hasLimit ? 'Rs. ' + formatMoney(summary.limit_amount) : '—';
    document.getElementById('sumBudgetName').textContent = summary.budget_name || (hasLimit ? '' : 'No budget linked');

    document.getElementById('sumReleased').textContent = 'Rs. ' + formatMoney(summary.released_amount || 0);
    document.getElementById('sumReleasedCount').textContent = (summary.released_count || 0) + ' payment' + (summary.released_count === 1 ? '' : 's');

    document.getElementById('sumPaid').textContent = 'Rs. ' + formatMoney(summary.spent_amount || 0);
    document.getElementById('sumPaidCount').textContent = (summary.spent_count || 0) + ' payment' + (summary.spent_count === 1 ? '' : 's');

    const adjAmt = summary.adjustments_amount || 0;
    document.getElementById('sumAdjustments').textContent = (adjAmt < 0 ? '-Rs. ' : 'Rs. ') + formatMoney(Math.abs(adjAmt));
    document.getElementById('sumAdjustmentsCount').textContent = (summary.adjustments_count || 0) + ' adjustment' + (summary.adjustments_count === 1 ? '' : 's') + ' — view history';

    const balEl = document.getElementById('sumBalance');
    if (summary.balance === null || summary.balance === undefined) {
        balEl.textContent = '—';
        balEl.classList.remove('negative');
    } else {
        balEl.textContent = 'Rs. ' + formatMoney(summary.balance);
        balEl.classList.toggle('negative', summary.balance < 0);
    }
}

const STATUS_LABELS = { initiated: 'Initiated', authorized: 'Authorized', approved: 'Approved', paid: 'Paid', rejected: 'Rejected', cancelled: 'Cancelled' };

function renderBasePayments(list) {
    const tbody = document.getElementById('basePayBody');
    if (!list || list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:30px;color:#9ca3af;">No base expense payments recorded yet for this Cash Float.</td></tr>';
        return;
    }

    let html = '';
    list.forEach(function (p, idx) {
        const attCount = (p.attachments || []).length;
        const attHtml = attCount > 0
            ? '<span class="att-count" onclick="openViewModal(' + p.id + ')"><i class="fa-solid fa-paperclip"></i> ' + attCount + '</span>'
            : '<span class="att-count none"><i class="fa-solid fa-paperclip"></i> 0</span>';

        const remarksHtml = p.remarks
            ? '<span class="remarks-cell">' + escapeHtml(p.remarks) + '</span>'
            : '<span class="remarks-cell empty">—</span>';

        const expenseHtml = p.expense_name
            ? escapeHtml(p.expense_name) + ' <span style="color:#9ca3af;font-size:11px;">(ID: ' + p.expense_id + ')</span>'
            : '<span style="color:#9ca3af;">—</span>';

        // Delete button shown on every row for every user, no status/permission
        // check — this action is fully unconditional per current requirements.
        const deleteBtnHtml = '<button class="btn-icon delete" title="Delete" onclick="deleteBasePayment(' + p.id + ')"><i class="fa-solid fa-trash"></i></button>';

        html += '<tr>' +
            '<td>' + (idx + 1) + '</td>' +
            '<td>' + formatDate(p.payment_date) + '</td>' +
            '<td><strong>Rs. ' + formatMoney(p.amount) + '</strong></td>' +
            '<td>' + expenseHtml + '</td>' +
            '<td>' + remarksHtml + '</td>' +
            '<td>' + attHtml + '</td>' +
            '<td>' + (p.initiated_by_name ? escapeHtml(p.initiated_by_name) : '<span style="color:#9ca3af;">—</span>') + '</td>' +
            '<td><span class="status-badge ' + p.status + '">' + STATUS_LABELS[p.status] + '</span></td>' +
            '<td><div class="action-cell">' +
                '<button class="btn-icon view" title="View" onclick="openViewModal(' + p.id + ')"><i class="fa-solid fa-eye"></i></button>' +
                deleteBtnHtml +
            '</div></td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
}

function todayStr() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

// ── Add Base Expense Payment modal ────────────────────────────────────────────────
function openAddBaseModal() {
    if (!baseCanInitiate) return;

    document.getElementById('addBaseModalSub').textContent = document.getElementById('pageSub').textContent;
    clearErrorsBase();
    resetBudgetStatusBoxBase();

    const typeRow = document.getElementById('baseExpenseTypeRow');
    if (baseExpenseName) {
        document.getElementById('baseExpenseTypeValue').textContent = baseExpenseName + ' (ID: ' + baseExpenseId + ')';
        typeRow.style.display = 'block';
    } else {
        typeRow.style.display = 'none';
    }

    document.getElementById('basePaymentAmount').value = '';
    document.getElementById('basePaymentRemarks').value = '';
    selectedNewBaseFiles = [];
    renderNewBaseAttachments();
    document.getElementById('basePaymentDate').value = todayStr();
    renderAutoWorkflowNoteBase();
    fetchBudgetStatusBase();

    document.getElementById('addBaseModal').classList.add('open');
}
function closeAddBaseModal() {
    document.getElementById('addBaseModal').classList.remove('open');
}
document.getElementById('addBaseModal').addEventListener('click', function (e) { if (e.target === this) closeAddBaseModal(); });

function renderAutoWorkflowNoteBase() {
    const noteBox = document.getElementById('autoWorkflowNoteBase');
    const noteText = document.getElementById('autoWorkflowNoteTextBase');

    if (!baseHasAuthorizer && !baseHasApprover) {
        noteText.textContent = 'This base expense has no authorizer or approver assigned — the payment will be auto-approved as soon as it is saved.';
        noteBox.style.display = 'block';
    } else if (!baseHasAuthorizer) {
        noteText.textContent = 'This base expense has no authorizer assigned — the payment will skip straight to Authorized once saved.';
        noteBox.style.display = 'block';
    } else if (!baseHasApprover) {
        noteText.textContent = 'This base expense has no approver assigned — once authorized, the payment will be auto-approved.';
        noteBox.style.display = 'block';
    } else {
        noteBox.style.display = 'none';
    }
}

function clearErrorsBase() {
    ['errBasePaymentDate', 'errBaseAmount'].forEach(function (id) {
        const el = document.getElementById(id);
        el.textContent = ''; el.classList.remove('show');
    });
}
function showFieldErrorBase(id, msg) {
    const el = document.getElementById(id);
    el.textContent = msg; el.classList.add('show');
}

// ── Live budget status ────────────────────────────────────────────────────────
function resetBudgetStatusBoxBase() {
    latestBudgetStatusBase = null;
    document.getElementById('budgetStatusBoxBase').style.display = 'none';
    document.getElementById('noBudgetNoteBase').style.display = 'none';
}

function fetchBudgetStatusBase() {
    if (!baseExpenseId) { resetBudgetStatusBoxBase(); return; }
    const paymentDate = document.getElementById('basePaymentDate').value;

    const params = new URLSearchParams();
    params.append('ajax_get_budget_status', '1');
    params.append('expense_id', baseExpenseId);
    if (paymentDate) params.append('payment_date', paymentDate);

    fetch('cash_float_add_payment.php?' + params.toString())
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            if (!data.success) { resetBudgetStatusBoxBase(); return; }
            latestBudgetStatusBase = data.budget_status;
            renderBudgetStatusBase();
        })
        .catch(function () { resetBudgetStatusBoxBase(); });
}

function renderBudgetStatusBase() {
    const bs = latestBudgetStatusBase;
    if (!bs || !bs.has_budget) {
        document.getElementById('budgetStatusBoxBase').style.display = 'none';
        document.getElementById('noBudgetNoteBase').style.display = 'block';
        return;
    }
    document.getElementById('noBudgetNoteBase').style.display = 'none';
    document.getElementById('budgetStatusBoxBase').style.display = 'block';
    document.getElementById('bsbBudgetNameBase').textContent = bs.budget_name;
    document.getElementById('bsbPeriodBase').textContent = (bs.duration === 'annually' ? 'Annual' : 'Monthly') + ' — ' + bs.period_label;
    document.getElementById('bsbLimitBase').textContent = formatMoney(bs.limit_amount);
    document.getElementById('bsbSpentBase').textContent = formatMoney(bs.spent);
    document.getElementById('bsbRemainingBase').textContent = formatMoney(bs.remaining);
    updateAfterAmountPreviewBase();
}

function updateAfterAmountPreviewBase() {
    const bs = latestBudgetStatusBase;
    const amountVal = parseFloat(document.getElementById('basePaymentAmount').value);
    const amount = isNaN(amountVal) ? 0 : amountVal;
    if (!bs || !bs.has_budget) return;

    const after = bs.remaining - amount;
    document.getElementById('bsbAfterBase').textContent = formatMoney(after);
    const usedAfter = bs.limit_amount > 0 ? Math.min(((bs.spent + amount) / bs.limit_amount) * 100, 100) : 0;
    const fill = document.getElementById('bsbBarFillBase');
    fill.style.width = usedAfter + '%';
    fill.classList.remove('warn', 'over');
    const warnBox = document.getElementById('bsbWarnBase');
    if (after < 0) { fill.classList.add('over'); warnBox.style.display = 'block'; }
    else if (bs.limit_amount > 0 && usedAfter >= 80) { fill.classList.add('warn'); warnBox.style.display = 'none'; }
    else { warnBox.style.display = 'none'; }
}

// ── Attachments ────────────────────────────────────────────────────────────────
function onFilesChosenBase(fileList) {
    Array.from(fileList || []).forEach(function (f) { selectedNewBaseFiles.push(f); });
    document.getElementById('baseAttachmentInput').value = '';
    renderNewBaseAttachments();
}
function removeNewBaseFile(idx) {
    selectedNewBaseFiles.splice(idx, 1);
    renderNewBaseAttachments();
}
function renderNewBaseAttachments() {
    const wrap = document.getElementById('newBaseAttachmentsList');
    if (selectedNewBaseFiles.length === 0) { wrap.innerHTML = ''; return; }
    let html = '';
    selectedNewBaseFiles.forEach(function (f, idx) {
        html += '<div class="att-item">' +
            '<i class="fa-solid fa-file-circle-plus att-file-icon"></i>' +
            '<span class="att-name">' + escapeHtml(f.name) + '</span>' +
            '<span class="att-size">' + formatFileSize(f.size) + '</span>' +
            '<button type="button" class="att-remove" onclick="removeNewBaseFile(' + idx + ')" title="Remove"><i class="fa-solid fa-xmark"></i></button>' +
        '</div>';
    });
    wrap.innerHTML = html;
}

// ── Save ───────────────────────────────────────────────────────────────────────
function saveBasePayment() {
    clearErrorsBase();
    const date = document.getElementById('basePaymentDate').value;
    const amount = document.getElementById('basePaymentAmount').value;
    const remarks = document.getElementById('basePaymentRemarks').value.trim();

    let hasError = false;
    if (!date) { showFieldErrorBase('errBasePaymentDate', 'Please select a payment date.'); hasError = true; }
    if (!amount || isNaN(amount) || parseFloat(amount) <= 0) { showFieldErrorBase('errBaseAmount', 'Enter a valid payment amount.'); hasError = true; }
    if (hasError) return;

    const proceed = function () {
        const btn = document.getElementById('btnSaveBase');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

        const fd = new FormData();
        fd.append('ajax_save_base', '1');
        fd.append('cash_float_id', cashFloatId);
        fd.append('payment_date', date);
        fd.append('amount', amount);
        fd.append('remarks', remarks);
        selectedNewBaseFiles.forEach(function (f) { fd.append('attachments[]', f); });

        fetch('cash_float_add_payment.php', { method: 'POST', body: fd })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Payment';
                if (data.success) {
                    showToast('✅ ' + data.message, 'success');
                    closeAddBaseModal();
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

    if (latestBudgetStatusBase && latestBudgetStatusBase.has_budget) {
        const after = latestBudgetStatusBase.remaining - parseFloat(amount);
        if (after < 0) {
            if (!confirm('This payment exceeds the remaining budget limit by Rs. ' + formatMoney(Math.abs(after)) + '. Continue anyway?')) { return; }
        }
    }
    proceed();
}

// ── Balance Adjustments ──────────────────────────────────────────────────────────
function openAdjustModal() {
    document.getElementById('adjDate').value = todayStr();
    document.getElementById('adjAmount').value = '';
    document.getElementById('adjReason').value = '';
    clearErrorsAdjust();
    document.getElementById('addAdjustModal').classList.add('open');
}
function closeAdjustModal() {
    document.getElementById('addAdjustModal').classList.remove('open');
}
document.getElementById('addAdjustModal').addEventListener('click', function (e) { if (e.target === this) closeAdjustModal(); });

function clearErrorsAdjust() {
    ['errAdjDate', 'errAdjAmount'].forEach(function (id) {
        const el = document.getElementById(id);
        el.textContent = ''; el.classList.remove('show');
    });
}
function showFieldErrorAdjust(id, msg) {
    const el = document.getElementById(id);
    el.textContent = msg; el.classList.add('show');
}

function saveAdjustment() {
    clearErrorsAdjust();
    const date = document.getElementById('adjDate').value;
    const amount = document.getElementById('adjAmount').value;
    const reason = document.getElementById('adjReason').value.trim();

    let hasError = false;
    if (!date) { showFieldErrorAdjust('errAdjDate', 'Please select an adjustment date.'); hasError = true; }
    if (!amount || isNaN(amount) || parseFloat(amount) === 0) { showFieldErrorAdjust('errAdjAmount', 'Enter a non-zero adjustment amount.'); hasError = true; }
    if (hasError) return;

    const btn = document.getElementById('btnSaveAdjust');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('ajax_save_adjustment', '1');
    fd.append('cash_float_id', cashFloatId);
    fd.append('adjustment_date', date);
    fd.append('amount', amount);
    fd.append('reason', reason);

    fetch('cash_float_add_payment.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Adjustment';
            if (data.success) {
                showToast('✅ ' + data.message, 'success');
                closeAdjustModal();
                loadAll();
            } else {
                showToast('❌ ' + (data.message || 'Save failed.'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Adjustment';
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

function openAdjustmentHistoryModal() {
    renderAdjustmentHistory();
    document.getElementById('adjustHistoryModal').classList.add('open');
}
function closeAdjustmentHistoryModal() {
    document.getElementById('adjustHistoryModal').classList.remove('open');
}
document.getElementById('adjustHistoryModal').addEventListener('click', function (e) { if (e.target === this) closeAdjustmentHistoryModal(); });

function renderAdjustmentHistory() {
    const tbody = document.getElementById('adjustHistoryBody');
    if (!currentAdjustments || currentAdjustments.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:30px;color:#9ca3af;">No balance adjustments recorded yet.</td></tr>';
        return;
    }
    let html = '';
    currentAdjustments.forEach(function (a, idx) {
        const amtColor = a.amount >= 0 ? '#16a34a' : '#dc2626';
        const amtLabel = (a.amount >= 0 ? '+Rs. ' : '-Rs. ') + formatMoney(Math.abs(a.amount));
        const reasonHtml = a.reason
            ? '<span class="remarks-cell">' + escapeHtml(a.reason) + '</span>'
            : '<span class="remarks-cell empty">—</span>';
        html += '<tr>' +
            '<td>' + (idx + 1) + '</td>' +
            '<td>' + formatDate(a.adjustment_date) + '</td>' +
            '<td><strong style="color:' + amtColor + ';">' + amtLabel + '</strong></td>' +
            '<td>' + reasonHtml + '</td>' +
            '<td>' + (a.adjusted_by_name ? escapeHtml(a.adjusted_by_name) : '<span style="color:#9ca3af;">—</span>') + '</td>' +
            '<td><button class="btn-icon delete" title="Delete" onclick="deleteAdjustment(' + a.id + ')"><i class="fa-solid fa-trash"></i></button></td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
}

// Delete is unconditional — any user, any adjustment — same policy as Base Expense
// Payment deletion on this page. See DELETE POLICY note at the top of the PHP file.
function deleteAdjustment(id) {
    const a = currentAdjustments.find(function (x) { return x.id === id; });
    if (!a) { showToast('❌ Adjustment not found.', 'error'); return; }

    if (!confirm('Delete this adjustment of Rs. ' + formatMoney(Math.abs(a.amount)) + ' dated ' + formatDate(a.adjustment_date) + '? This cannot be undone.')) {
        return;
    }

    const fd = new FormData();
    fd.append('ajax_delete_adjustment', '1');
    fd.append('adjustment_id', id);

    fetch('cash_float_add_payment.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            if (data.success) {
                showToast('✅ ' + data.message, 'success');
                loadAll();
            } else {
                showToast('❌ ' + (data.message || 'Delete failed.'), 'error');
            }
        })
        .catch(function (err) {
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

// ── View Payment modal — details + attachment preview ────────────────────────────
function openViewModal(id) {
    const p = currentBasePayments.find(function (x) { return x.id === id; });
    if (!p) { showToast('❌ Payment not found.', 'error'); return; }

    viewModalPaymentId = id;
    document.getElementById('viewTitle').textContent = 'Base Expense Payment';
    document.getElementById('viewSub').textContent = formatDate(p.payment_date) + ' — Rs. ' + formatMoney(p.amount);

    // Delete button in the view modal is always visible to everyone, unconditionally.
    document.getElementById('viewDeleteBtn').style.display = 'inline-flex';

    function row(label, value, isHtml) {
        const valHtml = value ? (isHtml ? value : escapeHtml(value)) : '<span class="empty">—</span>';
        return '<div class="log-detail-row"><span class="log-detail-label">' + label + '</span><span class="log-detail-value">' + valHtml + '</span></div>';
    }

    let html = '';
    html += row('Payment Date', formatDate(p.payment_date));
    html += row('Expense', p.expense_name ? (p.expense_name + ' (ID: ' + p.expense_id + ')') : null);
    html += row('Amount', 'Rs. ' + formatMoney(p.amount));
    html += row('Remarks', p.remarks);
    html += row('Status', '<span class="status-badge ' + p.status + '">' + STATUS_LABELS[p.status] + '</span>', true);
    html += row('Initiated By', p.initiated_by_name);
    if (p.auto_authorized) html += row('Authorization', '<span style="color:#6d28d9;">Auto-advanced (no authorizer assigned)</span>', true);
    if (p.auto_approved)   html += row('Approval', '<span style="color:#16a34a;">Auto-advanced (no approver assigned)</span>', true);

    html += '<div style="margin-top:14px;">';
    html += '<div class="field-label" style="margin-bottom:8px;">Attachments (' + (p.attachments || []).length + ')</div>';
    if ((p.attachments || []).length === 0) {
        html += '<div style="font-size:12px;color:#9ca3af;">No attachments uploaded.</div>';
    } else {
        html += '<div class="att-preview-grid">';
        p.attachments.forEach(function (a) {
            const safeName = escapeHtml(a.file_name);
            const safeUrl  = escapeHtml(a.file_path);
            if (a.is_image) {
                // onerror fallback: if the image URL still 404s for any reason
                // (file moved/deleted on disk, permissions, etc.), swap to a
                // generic file icon instead of leaving a broken image box.
                html += '<a class="att-preview-item" href="' + safeUrl + '" target="_blank" title="' + safeName + '">' +
                    '<img class="att-preview-thumb" src="' + safeUrl + '" alt="' + safeName + '" loading="lazy" ' +
                    'onerror="this.onerror=null;this.outerHTML=\'<div class=&quot;att-preview-icon&quot;><i class=&quot;fa-solid fa-image&quot;></i></div>\';">' +
                    '<div class="att-preview-name">' + safeName + '</div>' +
                '</a>';
            } else {
                html += '<a class="att-preview-item" href="' + safeUrl + '" target="_blank" title="' + safeName + '">' +
                    '<div class="att-preview-icon"><i class="fa-solid ' + fileIconClass(a.file_name) + '"></i></div>' +
                    '<div class="att-preview-name">' + safeName + '</div>' +
                '</a>';
            }
        });
        html += '</div>';
    }
    html += '</div>';

    document.getElementById('viewBody').innerHTML = html;
    document.getElementById('viewModal').classList.add('open');
}
function deleteBasePayment(id) {
    const p = currentBasePayments.find(function (x) { return x.id === id; });
    if (!p) { showToast('❌ Payment not found.', 'error'); return; }

    if (!confirm('Delete this Rs. ' + formatMoney(p.amount) + ' payment dated ' + formatDate(p.payment_date) + '? This cannot be undone.')) {
        return;
    }

    const fd = new FormData();
    fd.append('ajax_delete_base', '1');
    fd.append('payment_id', id);

    fetch('cash_float_add_payment.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            if (data.success) {
                showToast('✅ ' + data.message, 'success');
                closeViewModal();
                loadAll();
            } else {
                showToast('❌ ' + (data.message || 'Delete failed.'), 'error');
            }
        })
        .catch(function (err) {
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

function closeViewModal() { document.getElementById('viewModal').classList.remove('open'); }
document.getElementById('viewModal').addEventListener('click', function (e) { if (e.target === this) closeViewModal(); });

function fileIconClass(fileName) {
    const ext = (fileName.split('.').pop() || '').toLowerCase();
    if (ext === 'pdf') return 'fa-file-pdf';
    if (['doc', 'docx'].indexOf(ext) !== -1) return 'fa-file-word';
    if (['xls', 'xlsx'].indexOf(ext) !== -1) return 'fa-file-excel';
    return 'fa-file';
}

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
function showToast(msg, type) {
    const colors = { success: '#16a34a', error: '#dc2626', warn: '#d97706' };
    const t = document.createElement('div');
    t.style.cssText =
        'position:fixed;top:20px;right:20px;z-index:99999;background:#fff;' +
        'border-left:4px solid ' + (colors[type] || '#2563eb') + ';border-radius:8px;' +
        'padding:12px 18px;font-size:13px;font-weight:600;color:#111827;' +
        'box-shadow:0 8px 24px rgba(0,0,0,.15);max-width:380px;';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () {
        t.style.opacity    = '0';
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