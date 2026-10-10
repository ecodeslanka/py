<?php
// ── Yelo Group HMS — MyBOS Accounts Entry ────────────────────────────────────

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/mybos_accounts_error.log');

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
        ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['ajax_save']) || isset($_POST['ajax_save_all']) || isset($_POST['ajax_delete']))) ||
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
    CREATE TABLE IF NOT EXISTS mybos_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_code VARCHAR(50) NOT NULL,
        account_name VARCHAR(255) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_account_code (account_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── AJAX: Delete a single account ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete'])) {
    header('Content-Type: application/json');

    $id = intval($_POST['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Invalid record.']);
        exit;
    }

    $ok = mysqli_query($conn, "DELETE FROM mybos_accounts WHERE id = $id LIMIT 1");
    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'Account deleted.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Save a single account (insert or update) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');

    $id           = intval($_POST['id'] ?? 0);
    $account_code = mysqli_real_escape_string($conn, trim($_POST['account_code'] ?? ''));
    $account_name = mysqli_real_escape_string($conn, trim($_POST['account_name'] ?? ''));

    if ($account_code === '' || $account_name === '') {
        echo json_encode(['success' => false, 'message' => 'Account code and account name are both required.']);
        exit;
    }

    $dupCheck = mysqli_query($conn,
        "SELECT id FROM mybos_accounts WHERE account_code = '$account_code'" . ($id ? " AND id != $id" : "") . " LIMIT 1"
    );
    if ($dupCheck && mysqli_num_rows($dupCheck) > 0) {
        echo json_encode(['success' => false, 'message' => "Account code '$account_code' already exists."]);
        exit;
    }

    if ($id > 0) {
        $ok    = mysqli_query($conn,
            "UPDATE mybos_accounts SET account_code = '$account_code', account_name = '$account_name' WHERE id = $id"
        );
        $newId = $id;
    } else {
        $ok    = mysqli_query($conn,
            "INSERT INTO mybos_accounts (account_code, account_name) VALUES ('$account_code', '$account_name')"
        );
        $newId = $ok ? mysqli_insert_id($conn) : 0;
    }

    if ($ok) {
        echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Account saved.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Save ALL rows in one go ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save_all'])) {
    header('Content-Type: application/json');

    $records = $_POST['records'] ?? [];
    if (!is_array($records) || count($records) === 0) {
        echo json_encode(['success' => false, 'message' => 'No rows to save.']);
        exit;
    }

    $results = [];   // keyed by client row key
    $saved   = 0;
    $failed  = 0;

    // Track codes used earlier in this same batch, to catch in-batch duplicates too
    $batchCodes = [];

    foreach ($records as $rec) {
        $key          = mysqli_real_escape_string($conn, trim($rec['key'] ?? ''));
        $id           = intval($rec['id'] ?? 0);
        $account_code = mysqli_real_escape_string($conn, trim($rec['account_code'] ?? ''));
        $account_name = mysqli_real_escape_string($conn, trim($rec['account_name'] ?? ''));

        if ($account_code === '' || $account_name === '') {
            $results[$key] = ['success' => false, 'message' => 'Code and name are required.'];
            $failed++;
            continue;
        }

        $codeLower = mb_strtolower($account_code);
        if (isset($batchCodes[$codeLower]) && $batchCodes[$codeLower] !== $id) {
            $results[$key] = ['success' => false, 'message' => "Duplicate code '$account_code' in this batch."];
            $failed++;
            continue;
        }

        $dupCheck = mysqli_query($conn,
            "SELECT id FROM mybos_accounts WHERE account_code = '$account_code'" . ($id ? " AND id != $id" : "") . " LIMIT 1"
        );
        if ($dupCheck && mysqli_num_rows($dupCheck) > 0) {
            $results[$key] = ['success' => false, 'message' => "Account code '$account_code' already exists."];
            $failed++;
            continue;
        }

        if ($id > 0) {
            $ok    = mysqli_query($conn,
                "UPDATE mybos_accounts SET account_code = '$account_code', account_name = '$account_name' WHERE id = $id"
            );
            $newId = $id;
        } else {
            $ok    = mysqli_query($conn,
                "INSERT INTO mybos_accounts (account_code, account_name) VALUES ('$account_code', '$account_name')"
            );
            $newId = $ok ? mysqli_insert_id($conn) : 0;
        }

        if ($ok) {
            $batchCodes[$codeLower] = $newId;
            $results[$key] = ['success' => true, 'id' => $newId];
            $saved++;
        } else {
            $results[$key] = ['success' => false, 'message' => mysqli_error($conn)];
            $failed++;
        }
    }

    echo json_encode([
        'success' => true,
        'saved'   => $saved,
        'failed'  => $failed,
        'results' => $results,
        'message' => "$saved saved" . ($failed ? ", $failed failed" : '') . '.'
    ]);
    exit;
}

// ── AJAX: Load accounts (with optional search) ─────────────────────────────────
if (isset($_GET['ajax_load'])) {
    header('Content-Type: application/json');

    $q   = mysqli_real_escape_string($conn, trim($_GET['q'] ?? ''));
    $sql = "SELECT id, account_code, account_name FROM mybos_accounts";
    if ($q !== '') {
        $sql .= " WHERE account_code LIKE '%$q%' OR account_name LIKE '%$q%'";
    }
    $sql .= " ORDER BY account_code ASC";

    $res = mysqli_query($conn, $sql);
    if ($res === false) {
        echo json_encode(['success' => false, 'message' => 'Query failed: ' . mysqli_error($conn)]);
        exit;
    }

    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = [
            'id'           => (int)$r['id'],
            'account_code' => $r['account_code'],
            'account_name' => $r['account_name'],
        ];
    }

    echo json_encode(['success' => true, 'accounts' => $rows]);
    exit;
}

if (file_exists(__DIR__ . '/header.php')) {
    include __DIR__ . '/header.php';
} else {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>MyBOS Accounts Entry</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    </head><body style="font-family:sans-serif;background:#f3f4f6;padding:20px 20px 90px;">';
}
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-book" style="color:#2563eb;margin-right:8px;"></i>MyBOS Accounts Entry
            </h2>
            <p class="page-subtitle">Add, edit, delete and search MyBOS account codes and account names. Type a code &amp; name, then press <strong>Enter</strong> to save and jump to the next row.</p>
        </div>
    </div>
</div>

<!-- Toolbar (search only — Add / Save All live in the sticky bar below) -->
<div class="content-card" style="margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div style="display:flex;gap:8px;align-items:center;flex:1;min-width:260px;">
            <div style="position:relative;flex:1;max-width:380px;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;"></i>
                <input type="text" id="searchInput" placeholder="Search by account code or name…" class="search-input" onkeydown="if(event.key==='Enter'){doSearch();}">
            </div>
            <button class="btn btn-primary" onclick="doSearch()">
                <i class="fa-solid fa-magnifying-glass"></i> Search
            </button>
            <button class="btn btn-light" onclick="clearSearch()" title="Clear search">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div id="dirtyCount" style="font-size:12px;color:#d97706;font-weight:600;"></div>
    </div>
</div>

<!-- Table -->
<div class="content-card" style="margin-bottom:90px;">
    <div class="table-responsive">
        <table class="acc-table" id="accTable">
            <thead>
                <tr>
                    <th style="width:60px;">#</th>
                    <th style="width:220px;">Account Code</th>
                    <th>Account Name</th>
                    <th style="width:120px;">Actions</th>
                </tr>
            </thead>
            <tbody id="accBody">
                <tr id="emptyRow">
                    <td colspan="4" style="text-align:center;padding:40px;color:#9ca3af;">
                        <i class="fa-solid fa-spinner fa-spin" style="font-size:24px;margin-bottom:10px;display:block;"></i>
                        Loading accounts…
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Sticky bottom action bar — always visible, no need to scroll up -->
<div class="action-bar" id="actionBar">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <div id="saveInfo" style="font-size:13px;color:#374151;">Ready.</div>
        <div id="saveMsg" style="font-size:13px;font-weight:600;"></div>
    </div>
    <div style="display:flex;gap:8px;">
        <button class="btn btn-green" onclick="addNewRow()">
            <i class="fa-solid fa-plus"></i> Add Account
        </button>
        <button class="btn btn-primary" id="btnSaveAll" onclick="saveAllRows()">
            <i class="fa-solid fa-floppy-disk"></i> Save All
        </button>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div id="deleteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99998;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:28px 30px;max-width:380px;width:90%;box-shadow:0 12px 40px rgba(0,0,0,.2);animation:slideInRight .2s ease;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <div style="width:42px;height:42px;background:#fef2f2;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fa-solid fa-trash" style="color:#dc2626;font-size:18px;"></i>
            </div>
            <div>
                <div style="font-weight:700;font-size:15px;color:#111827;">Delete Account</div>
                <div style="font-size:12px;color:#6b7280;">This action cannot be undone.</div>
            </div>
        </div>
        <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;margin-bottom:18px;font-size:13px;color:#7f1d1d;">
            Are you sure you want to delete account <strong id="deleteAccLabel"></strong>?
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
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
.btn-green { background:#16a34a;color:#fff; }
.btn-green:hover { background:#15803d;transform:translateY(-1px);box-shadow:0 4px 12px rgba(22,163,74,.3); }
.btn-danger { background:#dc2626;color:#fff; }
.btn-danger:hover { background:#b91c1c; }
.btn-danger:disabled { background:#fca5a5;cursor:not-allowed; }

.search-input {
    width:100%;padding:9px 12px 9px 34px;border:1px solid #d1d5db;border-radius:8px;
    font-size:13px;font-family:inherit;color:#111827;transition:border-color .15s;
}
.search-input:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12); }

.table-responsive { overflow-x:auto; }
.acc-table { width:100%;border-collapse:collapse;font-size:13px; }
.acc-table thead { background:#f8fafc;border-bottom:2px solid #e2e8f0; }
.acc-table th {
    padding:9px 12px;text-align:left;font-weight:600;
    color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;
}
.acc-table tbody tr { border-bottom:1px solid #f1f5f9;transition:background .12s; }
.acc-table tbody tr:hover { background:#f8fafc; }
.acc-table td { padding:7px 12px;vertical-align:middle; }
.acc-table tr.row-new { background:#eff6ff !important; }
.acc-table tr.row-dirty { background:#fffbeb !important; }
.acc-table tr.row-error { background:#fef2f2 !important; }

.acc-input {
    width:100%;padding:7px 10px;border:1px solid #e5e7eb;border-radius:6px;
    font-size:13px;font-family:inherit;color:#111827;background:#f9fafb;
    transition:border-color .15s;
}
.acc-input:focus { outline:none;border-color:#2563eb;background:#fff;box-shadow:0 0 0 2px rgba(37,99,235,.1); }

.row-err-msg { font-size:11px;color:#dc2626;margin-top:3px; }

.btn-icon {
    display:inline-flex;align-items:center;justify-content:center;
    width:30px;height:30px;border:none;border-radius:6px;
    cursor:pointer;font-size:13px;transition:all .15s;margin-right:4px;
}
.btn-icon.save   { background:#dbeafe;color:#2563eb; }
.btn-icon.save:hover   { background:#bfdbfe; }
.btn-icon.delete { background:#fef2f2;color:#dc2626; }
.btn-icon.delete:hover { background:#fee2e2;transform:scale(1.1); }
.btn-icon:disabled { opacity:.4;cursor:not-allowed;transform:none; }

/* Sticky bottom action bar — stays put regardless of how many rows are added */
.action-bar {
    position:sticky;bottom:16px;z-index:100;
    background:#fff;border:1px solid #e2e8f0;border-radius:12px;
    box-shadow:0 4px 20px rgba(0,0,0,.14);
    padding:14px 20px;display:flex;justify-content:space-between;align-items:center;
    flex-wrap:wrap;gap:10px;margin-top:4px;
}

@keyframes slideInRight {
    from { opacity:0;transform:translateX(20px); }
    to   { opacity:1;transform:translateX(0); }
}

@media(max-width:768px) {
    .content-card { padding:14px; }
    .action-bar { flex-direction:column;align-items:stretch; }
    .action-bar > div { justify-content:center; }
}
</style>

<script>
let deleteTarget = null; // { id, tr }
let rowKeyCounter = 0;

$(document).ready(function () {
    loadAccounts();
});

function nextRowKey() {
    rowKeyCounter++;
    return 'r' + Date.now() + '_' + rowKeyCounter;
}

// ── Load accounts ──────────────────────────────────────────────────────────────
function loadAccounts(q) {
    q = q || '';
    const tbody = document.getElementById('accBody');
    tbody.innerHTML =
        '<tr><td colspan="4" style="text-align:center;padding:32px;color:#6b7280;">' +
        '<i class="fa-solid fa-spinner fa-spin" style="margin-right:8px;"></i>Loading…</td></tr>';

    fetch('mybos_accounts.php?ajax_load=1&q=' + encodeURIComponent(q))
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            if (!data.success) {
                showToast('❌ ' + (data.message || 'Failed to load accounts.'), 'error');
                tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:32px;color:#ef4444;">Failed to load.</td></tr>';
                return;
            }
            renderRows(data.accounts || []);
            updateDirtyCount();
        })
        .catch(function (err) {
            showToast('❌ Failed to load accounts: ' + err.message, 'error');
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:32px;color:#ef4444;">Failed to load. Check your connection.</td></tr>';
        });
}

// ── Render rows ────────────────────────────────────────────────────────────────
function renderRows(accounts) {
    const tbody = document.getElementById('accBody');
    tbody.innerHTML = '';

    if (accounts.length === 0) {
        tbody.innerHTML =
            '<tr id="emptyRow"><td colspan="4" style="text-align:center;padding:40px;color:#9ca3af;">' +
            '<i class="fa-solid fa-folder-open" style="font-size:24px;margin-bottom:10px;display:block;"></i>' +
            'No accounts found. Click <strong>Add Account</strong> below to create one.</td></tr>';
        return;
    }

    accounts.forEach(function (acc, idx) {
        const tr = buildRow(acc.id, acc.account_code, acc.account_name, idx + 1);
        tbody.appendChild(tr);
    });
}

// ── Build a single row ──────────────────────────────────────────────────────────
function buildRow(id, code, name, rowNum) {
    const tr = document.createElement('tr');
    tr.dataset.id  = id || '';
    tr.dataset.key = nextRowKey();
    if (!id) tr.classList.add('row-new');

    const tdNum = document.createElement('td');
    tdNum.textContent = rowNum || '';
    tdNum.className = 'row-num';

    const tdCode = document.createElement('td');
    const inCode = document.createElement('input');
    inCode.type = 'text';
    inCode.className = 'acc-input acc-code-input';
    inCode.placeholder = 'e.g. 1001';
    inCode.value = code || '';
    inCode.addEventListener('input', function () { markDirty(tr); });
    inCode.addEventListener('keydown', function (e) { handleRowKeydown(e, tr); });
    tdCode.appendChild(inCode);

    const tdName = document.createElement('td');
    const inName = document.createElement('input');
    inName.type = 'text';
    inName.className = 'acc-input acc-name-input';
    inName.placeholder = 'e.g. Cash in Hand';
    inName.value = name || '';
    inName.addEventListener('input', function () { markDirty(tr); });
    inName.addEventListener('keydown', function (e) { handleRowKeydown(e, tr); });
    tdName.appendChild(inName);

    const errDiv = document.createElement('div');
    errDiv.className = 'row-err-msg';
    errDiv.style.display = 'none';
    tdName.appendChild(errDiv);

    const tdActions = document.createElement('td');

    const btnSave = document.createElement('button');
    btnSave.className = 'btn-icon save';
    btnSave.title = 'Save row';
    btnSave.innerHTML = '<i class="fa-solid fa-floppy-disk"></i>';
    btnSave.addEventListener('click', function () { saveRow(tr); });

    const btnDel = document.createElement('button');
    btnDel.className = 'btn-icon delete';
    btnDel.title = 'Delete row';
    btnDel.innerHTML = '<i class="fa-solid fa-trash-can"></i>';
    btnDel.addEventListener('click', function () { openDeleteModal(tr); });

    tdActions.appendChild(btnSave);
    tdActions.appendChild(btnDel);

    tr.appendChild(tdNum);
    tr.appendChild(tdCode);
    tr.appendChild(tdName);
    tr.appendChild(tdActions);

    return tr;
}

// ── Quick spreadsheet-style entry: Enter saves the row & jumps to a new one ─────
function handleRowKeydown(e, tr) {
    if (e.key !== 'Enter') return;
    e.preventDefault();

    const code = tr.querySelector('.acc-code-input').value.trim();
    const name = tr.querySelector('.acc-name-input').value.trim();

    if (!code || !name) {
        showToast('⚠️ Fill in both code and name before pressing Enter.', 'warn');
        return;
    }

    // If this is the last row in the table, auto-create the next one after saving
    const isLastRow = tr === tr.parentElement.lastElementChild;

    saveRow(tr, function (ok) {
        if (ok && isLastRow) {
            addNewRow();
        }
    });
}

function markDirty(tr) {
    if (!tr.classList.contains('row-new')) {
        tr.classList.add('row-dirty');
    }
    tr.classList.remove('row-error');
    updateDirtyCount();
}

function updateDirtyCount() {
    const dirty = document.querySelectorAll('#accBody tr.row-dirty, #accBody tr.row-new').length;
    const info = document.getElementById('dirtyCount');
    info.textContent = dirty > 0 ? dirty + ' unsaved row(s)' : '';
    document.getElementById('saveInfo').textContent =
        dirty > 0 ? dirty + ' row(s) with changes' : 'All changes saved.';
}

// ── Add new empty row at the bottom ─────────────────────────────────────────────
function addNewRow() {
    const emptyRow = document.getElementById('emptyRow');
    if (emptyRow) emptyRow.remove();

    const tbody = document.getElementById('accBody');
    const tr = buildRow('', '', '', '');
    tbody.appendChild(tr);

    renumberRows();
    updateDirtyCount();
    tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const firstInput = tr.querySelector('.acc-code-input');
    if (firstInput) firstInput.focus();
}

function renumberRows() {
    const rows = document.querySelectorAll('#accBody tr');
    let n = 1;
    rows.forEach(function (tr) {
        const numCell = tr.querySelector('.row-num');
        if (numCell) numCell.textContent = n++;
    });
}

// ── Save a single row (insert or update) ────────────────────────────────────────
function saveRow(tr, callback) {
    const id   = tr.dataset.id || '';
    const code = tr.querySelector('.acc-code-input').value.trim();
    const name = tr.querySelector('.acc-name-input').value.trim();
    const errDiv = tr.querySelector('.row-err-msg');

    if (!code || !name) {
        showToast('⚠️ Please fill in both account code and account name.', 'warn');
        if (callback) callback(false);
        return;
    }

    const btn = tr.querySelector('.btn-icon.save');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

    const fd = new FormData();
    fd.append('ajax_save', '1');
    fd.append('id', id);
    fd.append('account_code', code);
    fd.append('account_name', name);

    fetch('mybos_accounts.php', { method: 'POST', body: fd })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i>';
            if (data.success) {
                tr.dataset.id = data.id;
                tr.classList.remove('row-new', 'row-dirty', 'row-error');
                if (errDiv) errDiv.style.display = 'none';
                showToast('✅ ' + data.message, 'success');
                updateDirtyCount();
                if (callback) callback(true);
            } else {
                tr.classList.add('row-error');
                if (errDiv) { errDiv.textContent = data.message || 'Save failed.'; errDiv.style.display = 'block'; }
                showToast('❌ ' + (data.message || 'Save failed.'), 'error');
                if (callback) callback(false);
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i>';
            showToast('❌ Network error: ' + err.message, 'error');
            if (callback) callback(false);
        });
}

// ── Save ALL rows at once ───────────────────────────────────────────────────────
function saveAllRows() {
    const rows = Array.from(document.querySelectorAll('#accBody tr')).filter(function (tr) {
        return tr.querySelector('.acc-code-input');
    });

    // Only send rows that actually have something worth saving
    const candidates = rows.filter(function (tr) {
        const code = tr.querySelector('.acc-code-input').value.trim();
        const name = tr.querySelector('.acc-name-input').value.trim();
        return code || name;
    });

    if (candidates.length === 0) {
        showToast('⚠️ No rows to save.', 'warn');
        return;
    }

    // Flag rows that are only half-filled
    let hasIncomplete = false;
    candidates.forEach(function (tr) {
        const code = tr.querySelector('.acc-code-input').value.trim();
        const name = tr.querySelector('.acc-name-input').value.trim();
        const errDiv = tr.querySelector('.row-err-msg');
        if (!code || !name) {
            hasIncomplete = true;
            tr.classList.add('row-error');
            if (errDiv) { errDiv.textContent = 'Both code and name are required.'; errDiv.style.display = 'block'; }
        }
    });
    if (hasIncomplete) {
        showToast('⚠️ Some rows are missing a code or name — fix the highlighted rows.', 'warn');
        return;
    }

    const btn = document.getElementById('btnSaveAll');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('ajax_save_all', '1');
    candidates.forEach(function (tr, i) {
        fd.append('records[' + i + '][key]', tr.dataset.key);
        fd.append('records[' + i + '][id]', tr.dataset.id || '');
        fd.append('records[' + i + '][account_code]', tr.querySelector('.acc-code-input').value.trim());
        fd.append('records[' + i + '][account_name]', tr.querySelector('.acc-name-input').value.trim());
    });

    fetch('mybos_accounts.php', { method: 'POST', body: fd })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save All';

            if (!data.success) {
                showToast('❌ ' + (data.message || 'Save all failed.'), 'error');
                return;
            }

            const results = data.results || {};
            candidates.forEach(function (tr) {
                const res = results[tr.dataset.key];
                const errDiv = tr.querySelector('.row-err-msg');
                if (!res) return;
                if (res.success) {
                    tr.dataset.id = res.id;
                    tr.classList.remove('row-new', 'row-dirty', 'row-error');
                    if (errDiv) errDiv.style.display = 'none';
                } else {
                    tr.classList.add('row-error');
                    if (errDiv) { errDiv.textContent = res.message || 'Save failed.'; errDiv.style.display = 'block'; }
                }
            });

            document.getElementById('saveMsg').innerHTML =
                data.failed > 0
                    ? '<span style="color:#dc2626;"><i class="fa-solid fa-triangle-exclamation"></i> ' + data.message + '</span>'
                    : '<span style="color:#16a34a;"><i class="fa-solid fa-circle-check"></i> ' + data.message + '</span>';

            showToast((data.failed > 0 ? '⚠️ ' : '✅ ') + data.message, data.failed > 0 ? 'warn' : 'success');
            updateDirtyCount();
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save All';
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

// ── Delete Modal ───────────────────────────────────────────────────────────────
function openDeleteModal(tr) {
    const id   = tr.dataset.id || '';
    const code = tr.querySelector('.acc-code-input').value.trim();
    const name = tr.querySelector('.acc-name-input').value.trim();

    if (!id) {
        tr.remove();
        renumberRows();
        updateDirtyCount();
        if (!document.querySelector('#accBody tr')) {
            renderRows([]);
        }
        return;
    }

    deleteTarget = { id: id, tr: tr };
    document.getElementById('deleteAccLabel').textContent =
        (code || '(no code)') + ' — ' + (name || '(no name)');
    document.getElementById('deleteModal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
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

    fetch('mybos_accounts.php', { method: 'POST', body: fd })
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
                target.tr.remove();
                renumberRows();
                updateDirtyCount();
                if (!document.querySelector('#accBody tr')) {
                    renderRows([]);
                }
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

document.getElementById('deleteModal').addEventListener('click', function (e) {
    if (e.target === this) closeDeleteModal();
});

// ── Search ─────────────────────────────────────────────────────────────────────
function doSearch() {
    const q = document.getElementById('searchInput').value.trim();
    loadAccounts(q);
}
function clearSearch() {
    document.getElementById('searchInput').value = '';
    loadAccounts('');
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