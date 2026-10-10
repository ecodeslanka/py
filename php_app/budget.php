<?php
// ── Yelo Group HMS — Budget ───────────────────────────────────────────────────

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/budget_error.log');

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
        ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['ajax_save']) || isset($_POST['ajax_delete']))) ||
        isset($_GET['ajax_load'])
    )) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . mysqli_connect_error()]);
        exit;
    }
    die('<h3 style="color:red;font-family:sans-serif;padding:20px;">Database connection failed: ' . htmlspecialchars(mysqli_connect_error()) . '</h3>');
}

mysqli_set_charset($conn, 'utf8mb4');

// ── Ensure table exists ───────────────────────────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS budgets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        budget_name  VARCHAR(150) NOT NULL,
        limit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        duration     ENUM('daily','weekly','monthly','annually') NOT NULL DEFAULT 'monthly',
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Auto-migration: widen duration ENUM for tables created before Daily/Weekly existed ──
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM budgets LIKE 'duration'");
if ($colCheck && ($colInfo = mysqli_fetch_assoc($colCheck))) {
    if (strpos($colInfo['Type'], 'daily') === false || strpos($colInfo['Type'], 'weekly') === false) {
        mysqli_query($conn, "ALTER TABLE budgets MODIFY COLUMN duration ENUM('daily','weekly','monthly','annually') NOT NULL DEFAULT 'monthly'");
    }
}

$validDurations = ['daily', 'weekly', 'monthly', 'annually'];

// ── AJAX: Delete a budget ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete'])) {
    header('Content-Type: application/json');

    $id = intval($_POST['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Invalid record.']);
        exit;
    }

    $ok = mysqli_query($conn, "DELETE FROM budgets WHERE id = $id LIMIT 1");
    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'Budget deleted.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Save (insert or update) a budget ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');

    $id           = intval($_POST['id'] ?? 0);
    $budget_name  = mysqli_real_escape_string($conn, trim($_POST['budget_name'] ?? ''));
    $limit_amount = trim($_POST['limit_amount'] ?? '');
    $duration     = trim($_POST['duration'] ?? '');

    if ($budget_name === '') {
        echo json_encode(['success' => false, 'message' => 'Budget name is required.']);
        exit;
    }

    if ($limit_amount === '' || !is_numeric($limit_amount) || floatval($limit_amount) < 0) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid limit amount.']);
        exit;
    }
    $limit_amount = floatval($limit_amount);

    if (!in_array($duration, $validDurations, true)) {
        echo json_encode(['success' => false, 'message' => 'Duration must be Daily, Weekly, Monthly or Annually.']);
        exit;
    }

    if ($id > 0) {
        $ok = mysqli_query($conn,
            "UPDATE budgets SET budget_name = '$budget_name', limit_amount = $limit_amount, duration = '$duration' WHERE id = $id"
        );
        $newId = $id;
    } else {
        $ok = mysqli_query($conn,
            "INSERT INTO budgets (budget_name, limit_amount, duration) VALUES ('$budget_name', $limit_amount, '$duration')"
        );
        $newId = $ok ? mysqli_insert_id($conn) : 0;
    }

    if ($ok) {
        echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Budget saved.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Load budgets ──────────────────────────────────────────────────────────
if (isset($_GET['ajax_load'])) {
    header('Content-Type: application/json');

    $q   = mysqli_real_escape_string($conn, trim($_GET['q'] ?? ''));
    $sql = "SELECT id, budget_name, limit_amount, duration FROM budgets";
    if ($q !== '') {
        $sql .= " WHERE budget_name LIKE '%$q%'";
    }
    $sql .= " ORDER BY budget_name ASC";

    $res = mysqli_query($conn, $sql);
    if ($res === false) {
        echo json_encode(['success' => false, 'message' => 'Query failed: ' . mysqli_error($conn)]);
        exit;
    }

    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = [
            'id'           => (int)$r['id'],
            'budget_name'  => $r['budget_name'],
            'limit_amount' => (float)$r['limit_amount'],
            'duration'     => $r['duration'],
        ];
    }

    echo json_encode(['success' => true, 'budgets' => $rows]);
    exit;
}

if (file_exists(__DIR__ . '/header.php')) {
    include __DIR__ . '/header.php';
} else {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Budget</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    </head><body style="font-family:sans-serif;background:#f3f4f6;padding:20px;">';
}
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-wallet" style="color:#2563eb;margin-right:8px;"></i>Budget
            </h2>
            <p class="page-subtitle">Set up budget limits with a daily, weekly, monthly or annual duration. Add, edit and delete budgets below.</p>
        </div>
        <button class="btn btn-primary" onclick="openAddModal()">
            <i class="fa-solid fa-plus"></i> Add Budget
        </button>
    </div>
</div>

<!-- Toolbar -->
<div class="content-card" style="margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div style="display:flex;gap:8px;align-items:center;flex:1;min-width:260px;">
            <div style="position:relative;flex:1;max-width:380px;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;"></i>
                <input type="text" id="searchInput" placeholder="Search by budget name…" class="search-input" onkeydown="if(event.key==='Enter'){doSearch();}">
            </div>
            <button class="btn btn-light" onclick="doSearch()">
                <i class="fa-solid fa-magnifying-glass"></i> Search
            </button>
            <button class="btn btn-light" onclick="clearSearch()" title="Clear search">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;">
            <div class="sum-item"><span class="sum-dot blue"></span><span id="sumTotal">0</span> Budgets</div>
        </div>
    </div>
</div>

<!-- Table -->
<div class="content-card">
    <div class="table-responsive">
        <table class="bud-table" id="budTable">
            <thead>
                <tr>
                    <th style="width:60px;">#</th>
                    <th>Budget Name</th>
                    <th style="width:180px;">Limit Amount</th>
                    <th style="width:140px;">Duration</th>
                    <th style="width:120px;">Actions</th>
                </tr>
            </thead>
            <tbody id="budBody">
                <tr id="emptyRow">
                    <td colspan="5" style="text-align:center;padding:40px;color:#9ca3af;">
                        <i class="fa-solid fa-spinner fa-spin" style="font-size:24px;margin-bottom:10px;display:block;"></i>
                        Loading budgets…
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Add / Edit Budget Modal -->
<div id="budgetModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:12px;">
                <div class="modal-icon" id="modalIconWrap">
                    <i class="fa-solid fa-wallet" id="modalIcon"></i>
                </div>
                <div>
                    <div class="modal-title" id="modalTitle">Add Budget</div>
                    <div class="modal-sub" id="modalSub">Create a new budget limit.</div>
                </div>
            </div>
            <button class="modal-close" onclick="closeBudgetModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="modal-body">
            <input type="hidden" id="budgetId" value="">

            <div class="form-group">
                <label class="field-label">Budget Name <span class="req">*</span></label>
                <input type="text" id="budgetName" class="modal-input" placeholder="e.g. Office Supplies">
                <div class="err-msg" id="errBudgetName"></div>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex:1;">
                    <label class="field-label">Limit Amount <span class="req">*</span></label>
                    <div class="amount-wrap">
                        <span class="amount-prefix">Rs.</span>
                        <input type="number" id="limitAmount" class="modal-input amount-input" placeholder="0.00" min="0" step="0.01">
                    </div>
                    <div class="err-msg" id="errLimitAmount"></div>
                </div>

                <div class="form-group" style="flex:1;">
                    <label class="field-label">Duration <span class="req">*</span></label>
                    <select id="duration" class="modal-input native-select">
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                        <option value="annually">Annually</option>
                    </select>
                    <div class="err-msg" id="errDuration"></div>
                </div>
            </div>
        </div>

        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeBudgetModal()">Cancel</button>
            <button class="btn btn-primary" id="btnModalSave" onclick="saveBudget()">
                <i class="fa-solid fa-floppy-disk"></i> Save Budget
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
                    <div style="font-weight:700;font-size:15px;color:#111827;">Delete Budget</div>
                    <div style="font-size:12px;color:#6b7280;">This action cannot be undone.</div>
                </div>
            </div>
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:13px;color:#7f1d1d;">
                Are you sure you want to delete budget <strong id="deleteBudLabel"></strong>?
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
.field-label { font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;display:block; }
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
.sum-dot.blue { background:#3b82f6; }

.table-responsive { overflow-x:auto; }
.bud-table { width:100%;border-collapse:collapse;font-size:13px; }
.bud-table thead { background:#f8fafc;border-bottom:2px solid #e2e8f0; }
.bud-table th {
    padding:9px 12px;text-align:left;font-weight:600;
    color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;
}
.bud-table tbody tr { border-bottom:1px solid #f1f5f9;transition:background .12s; }
.bud-table tbody tr:hover { background:#f8fafc; }
.bud-table td { padding:10px 12px;vertical-align:middle; }

.dur-badge { display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600; }
.dur-badge.daily    { background:#dcfce7;color:#15803d; }
.dur-badge.weekly   { background:#fef3c7;color:#b45309; }
.dur-badge.monthly  { background:#dbeafe;color:#1d4ed8; }
.dur-badge.annually { background:#ede9fe;color:#6d28d9; }

.amount-val { font-weight:700;color:#111827;font-family:monospace; }

.btn-icon {
    display:inline-flex;align-items:center;justify-content:center;
    width:30px;height:30px;border:none;border-radius:6px;
    cursor:pointer;font-size:13px;transition:all .15s;margin-right:4px;
}
.btn-icon.edit   { background:#dbeafe;color:#2563eb; }
.btn-icon.edit:hover   { background:#bfdbfe; }
.btn-icon.delete { background:#fef2f2;color:#dc2626; }
.btn-icon.delete:hover { background:#fee2e2;transform:scale(1.1); }

/* ── Modal ───────────────────────────────────────────────────────────────── */
.modal-overlay {
    display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99998;
    align-items:center;justify-content:center;padding:16px;
}
.modal-overlay.open { display:flex; }
.modal-box {
    background:#fff;border-radius:14px;max-width:480px;width:100%;
    box-shadow:0 12px 40px rgba(0,0,0,.25);animation:slideInRight .2s ease;
    max-height:90vh;overflow-y:auto;
}
.modal-head {
    display:flex;align-items:center;justify-content:space-between;
    padding:20px 22px 14px;border-bottom:1px solid #f1f5f9;
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
    padding:14px 22px 20px;
}

.form-group { margin-bottom:16px; }
.form-row { display:flex;gap:14px;flex-wrap:wrap; }
.form-row .form-group { min-width:180px; }

.modal-input {
    width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;
    font-size:13px;font-family:inherit;color:#111827;transition:border-color .15s;
}
.modal-input:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12); }
.native-select { appearance:auto;cursor:pointer;background:#fff; }

.amount-wrap { position:relative; }
.amount-prefix {
    position:absolute;left:12px;top:50%;transform:translateY(-50%);
    font-size:13px;color:#6b7280;font-weight:600;pointer-events:none;
}
.amount-input { padding-left:38px !important; }

.err-msg { font-size:11px;color:#dc2626;margin-top:5px;display:none; }
.err-msg.show { display:block; }

@keyframes slideInRight {
    from { opacity:0;transform:translateX(20px) scale(.98); }
    to   { opacity:1;transform:translateX(0) scale(1); }
}

@media(max-width:768px) {
    .content-card { padding:14px; }
    .form-row { flex-direction:column; }
}
</style>

<script>
let currentBudgets = [];
let deleteTarget = null; // { id, label }

const DURATION_LABELS = {
    daily: 'Daily',
    weekly: 'Weekly',
    monthly: 'Monthly',
    annually: 'Annually'
};

$(document).ready(function () {
    loadBudgets();
});

// ── Load budgets ────────────────────────────────────────────────────────────────
function loadBudgets(q) {
    q = q || '';
    const tbody = document.getElementById('budBody');
    tbody.innerHTML =
        '<tr><td colspan="5" style="text-align:center;padding:32px;color:#6b7280;">' +
        '<i class="fa-solid fa-spinner fa-spin" style="margin-right:8px;"></i>Loading…</td></tr>';

    fetch('budget.php?ajax_load=1&q=' + encodeURIComponent(q))
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            if (!data.success) {
                showToast('❌ ' + (data.message || 'Failed to load budgets.'), 'error');
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:32px;color:#ef4444;">Failed to load.</td></tr>';
                return;
            }
            currentBudgets = data.budgets || [];
            renderRows(currentBudgets);
        })
        .catch(function (err) {
            showToast('❌ Failed to load budgets: ' + err.message, 'error');
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:32px;color:#ef4444;">Failed to load. Check your connection.</td></tr>';
        });
}

// ── Render rows ─────────────────────────────────────────────────────────────────
function renderRows(budgets) {
    const tbody = document.getElementById('budBody');
    tbody.innerHTML = '';
    document.getElementById('sumTotal').textContent = budgets.length;

    if (budgets.length === 0) {
        tbody.innerHTML =
            '<tr id="emptyRow"><td colspan="5" style="text-align:center;padding:40px;color:#9ca3af;">' +
            '<i class="fa-solid fa-folder-open" style="font-size:24px;margin-bottom:10px;display:block;"></i>' +
            'No budgets found. Click <strong>Add Budget</strong> to create one.</td></tr>';
        return;
    }

    budgets.forEach(function (b, idx) {
        const durLabel = DURATION_LABELS[b.duration] || b.duration;
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' + (idx + 1) + '</td>' +
            '<td><strong>' + escapeHtml(b.budget_name) + '</strong></td>' +
            '<td><span class="amount-val">Rs. ' + formatAmount(b.limit_amount) + '</span></td>' +
            '<td><span class="dur-badge ' + b.duration + '">' + durLabel + '</span></td>' +
            '<td>' +
                '<button class="btn-icon edit" title="Edit" onclick="openEditModal(' + b.id + ')"><i class="fa-solid fa-pen"></i></button>' +
                '<button class="btn-icon delete" title="Delete" onclick="openDeleteModal(' + b.id + ')"><i class="fa-solid fa-trash-can"></i></button>' +
            '</td>';
        tbody.appendChild(tr);
    });
}

function formatAmount(n) {
    return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

// ── Add / Edit Modal ─────────────────────────────────────────────────────────────
function openAddModal() {
    document.getElementById('budgetId').value = '';
    document.getElementById('budgetName').value = '';
    document.getElementById('limitAmount').value = '';
    document.getElementById('duration').value = 'monthly';
    document.getElementById('modalTitle').textContent = 'Add Budget';
    document.getElementById('modalSub').textContent = 'Create a new budget limit.';
    clearModalErrors();
    document.getElementById('budgetModal').classList.add('open');
    setTimeout(function () { document.getElementById('budgetName').focus(); }, 50);
}

function openEditModal(id) {
    const b = currentBudgets.find(function (x) { return x.id === id; });
    if (!b) { showToast('❌ Budget not found.', 'error'); return; }

    document.getElementById('budgetId').value = b.id;
    document.getElementById('budgetName').value = b.budget_name;
    document.getElementById('limitAmount').value = b.limit_amount;
    document.getElementById('duration').value = b.duration;
    document.getElementById('modalTitle').textContent = 'Edit Budget';
    document.getElementById('modalSub').textContent = 'Update the budget details.';
    clearModalErrors();
    document.getElementById('budgetModal').classList.add('open');
    setTimeout(function () { document.getElementById('budgetName').focus(); }, 50);
}

function closeBudgetModal() {
    document.getElementById('budgetModal').classList.remove('open');
}

function clearModalErrors() {
    ['errBudgetName', 'errLimitAmount', 'errDuration'].forEach(function (id) {
        const el = document.getElementById(id);
        el.textContent = '';
        el.classList.remove('show');
    });
}

function showModalError(fieldErrId, msg) {
    const el = document.getElementById(fieldErrId);
    el.textContent = msg;
    el.classList.add('show');
}

// ── Save budget (add or edit) ────────────────────────────────────────────────────
function saveBudget() {
    clearModalErrors();

    const id     = document.getElementById('budgetId').value;
    const name   = document.getElementById('budgetName').value.trim();
    const amount = document.getElementById('limitAmount').value.trim();
    const dur    = document.getElementById('duration').value;

    let hasError = false;
    if (!name) { showModalError('errBudgetName', 'Budget name is required.'); hasError = true; }
    if (!amount || isNaN(amount) || parseFloat(amount) < 0) {
        showModalError('errLimitAmount', 'Enter a valid limit amount.'); hasError = true;
    }
    if (!dur) { showModalError('errDuration', 'Select a duration.'); hasError = true; }
    if (hasError) return;

    const btn = document.getElementById('btnModalSave');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('ajax_save', '1');
    fd.append('id', id);
    fd.append('budget_name', name);
    fd.append('limit_amount', amount);
    fd.append('duration', dur);

    fetch('budget.php', { method: 'POST', body: fd })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Budget';
            if (data.success) {
                showToast('✅ ' + data.message, 'success');
                closeBudgetModal();
                loadBudgets();
            } else {
                showModalError('errBudgetName', data.message || 'Save failed.');
                showToast('❌ ' + (data.message || 'Save failed.'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Budget';
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

// ── Delete Modal ───────────────────────────────────────────────────────────────
function openDeleteModal(id) {
    const b = currentBudgets.find(function (x) { return x.id === id; });
    deleteTarget = { id: id, label: b ? b.budget_name : id };
    document.getElementById('deleteBudLabel').textContent = deleteTarget.label;
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

    fetch('budget.php', { method: 'POST', body: fd })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete';
            closeDeleteModal();
            if (data.success) {
                showToast('🗑️ ' + data.message, 'success');
                loadBudgets();
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
document.getElementById('budgetModal').addEventListener('click', function (e) {
    if (e.target === this) closeBudgetModal();
});
document.getElementById('deleteModal').addEventListener('click', function (e) {
    if (e.target === this) closeDeleteModal();
});

// Enter key inside modal inputs triggers save
['budgetName', 'limitAmount'].forEach(function (id) {
    document.getElementById(id).addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); saveBudget(); }
    });
});

// ── Search ─────────────────────────────────────────────────────────────────────
function doSearch() {
    const q = document.getElementById('searchInput').value.trim();
    loadBudgets(q);
}
function clearSearch() {
    document.getElementById('searchInput').value = '';
    loadBudgets('');
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