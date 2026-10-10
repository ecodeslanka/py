<?php
// ── Yelo Group HMS — ROI ──────────────────────────────────────────────────────

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/roi_error.log');

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
    CREATE TABLE IF NOT EXISTS roi (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        roi_name   VARCHAR(150) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_roi_name (roi_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── AJAX: Delete a single ROI ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete'])) {
    header('Content-Type: application/json');

    $id = intval($_POST['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Invalid record.']);
        exit;
    }

    $ok = mysqli_query($conn, "DELETE FROM roi WHERE id = $id LIMIT 1");
    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'ROI deleted.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Save (insert or update) a ROI ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');

    $id       = intval($_POST['id'] ?? 0);
    $roi_name = mysqli_real_escape_string($conn, trim($_POST['roi_name'] ?? ''));

    if ($roi_name === '') {
        echo json_encode(['success' => false, 'message' => 'ROI name is required.']);
        exit;
    }

    $dupCheck = mysqli_query($conn,
        "SELECT id FROM roi WHERE roi_name = '$roi_name'" . ($id ? " AND id != $id" : "") . " LIMIT 1"
    );
    if ($dupCheck && mysqli_num_rows($dupCheck) > 0) {
        echo json_encode(['success' => false, 'message' => "'$roi_name' already exists."]);
        exit;
    }

    if ($id > 0) {
        $ok    = mysqli_query($conn, "UPDATE roi SET roi_name = '$roi_name' WHERE id = $id");
        $newId = $id;
    } else {
        $ok    = mysqli_query($conn, "INSERT INTO roi (roi_name) VALUES ('$roi_name')");
        $newId = $ok ? mysqli_insert_id($conn) : 0;
    }

    if ($ok) {
        echo json_encode(['success' => true, 'id' => $newId, 'message' => 'ROI saved.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Load ROIs (with optional search) ──────────────────────────────────────
if (isset($_GET['ajax_load'])) {
    header('Content-Type: application/json');

    $q   = mysqli_real_escape_string($conn, trim($_GET['q'] ?? ''));
    $sql = "SELECT id, roi_name FROM roi";
    if ($q !== '') {
        $sql .= " WHERE roi_name LIKE '%$q%'";
    }
    $sql .= " ORDER BY roi_name ASC";

    $res = mysqli_query($conn, $sql);
    if ($res === false) {
        echo json_encode(['success' => false, 'message' => 'Query failed: ' . mysqli_error($conn)]);
        exit;
    }

    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = ['id' => (int)$r['id'], 'roi_name' => $r['roi_name']];
    }

    echo json_encode(['success' => true, 'roi' => $rows]);
    exit;
}

if (file_exists(__DIR__ . '/header.php')) {
    include __DIR__ . '/header.php';
} else {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>ROI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    </head><body style="font-family:sans-serif;background:#f3f4f6;padding:20px;">';
}
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-chart-line" style="color:#2563eb;margin-right:8px;"></i>ROI
            </h2>
            <p class="page-subtitle">Type a name and press Enter (or click Add) to quickly add it to the list.</p>
        </div>
    </div>
</div>

<!-- Quick add bar -->
<div class="content-card" style="margin-bottom:16px;">
    <label class="field-label">ROI Name</label>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <input type="text" id="roiInput" class="modal-input" placeholder="Type ROI name and press Enter…" style="flex:1;min-width:220px;" autofocus>
        <button class="btn btn-primary" onclick="addRoi()">
            <i class="fa-solid fa-plus"></i> Add
        </button>
    </div>
    <div class="err-msg" id="errRoiInput"></div>
</div>

<!-- Toolbar: search -->
<div class="content-card" style="margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div style="display:flex;gap:8px;align-items:center;flex:1;min-width:260px;">
            <div style="position:relative;flex:1;max-width:380px;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;"></i>
                <input type="text" id="searchInput" placeholder="Search ROI name…" class="search-input" onkeydown="if(event.key==='Enter'){doSearch();}">
            </div>
            <button class="btn btn-light" onclick="doSearch()">
                <i class="fa-solid fa-magnifying-glass"></i> Search
            </button>
            <button class="btn btn-light" onclick="clearSearch()" title="Clear search">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="sum-item"><span class="sum-dot blue"></span><span id="sumTotal">0</span> ROI(s)</div>
    </div>
</div>

<!-- Table -->
<div class="content-card">
    <div class="table-responsive">
        <table class="roi-table" id="roiTable">
            <thead>
                <tr>
                    <th style="width:60px;">#</th>
                    <th>ROI Name</th>
                    <th style="width:110px;">Actions</th>
                </tr>
            </thead>
            <tbody id="roiBody">
                <tr id="emptyRow">
                    <td colspan="3" style="text-align:center;padding:40px;color:#9ca3af;">
                        <i class="fa-solid fa-spinner fa-spin" style="font-size:24px;margin-bottom:10px;display:block;"></i>
                        Loading…
                    </td>
                </tr>
            </tbody>
        </table>
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
                    <div style="font-weight:700;font-size:15px;color:#111827;">Delete ROI</div>
                    <div style="font-size:12px;color:#6b7280;">This action cannot be undone.</div>
                </div>
            </div>
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:13px;color:#7f1d1d;">
                Are you sure you want to delete <strong id="deleteRoiLabel"></strong>?
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
.field-label { font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;display:block; }

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

.modal-input, .search-input {
    padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;
    font-size:13px;font-family:inherit;color:#111827;transition:border-color .15s;
}
.modal-input:focus, .search-input:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12); }
.search-input { width:100%;padding-left:34px; }

.sum-item { display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#374151; }
.sum-dot  { width:10px;height:10px;border-radius:50%;flex-shrink:0; }
.sum-dot.blue { background:#3b82f6; }

.err-msg { font-size:11px;color:#dc2626;margin-top:6px;display:none; }
.err-msg.show { display:block; }

.table-responsive { overflow-x:auto; }
.roi-table { width:100%;border-collapse:collapse;font-size:13px; }
.roi-table thead { background:#f8fafc;border-bottom:2px solid #e2e8f0; }
.roi-table th {
    padding:9px 12px;text-align:left;font-weight:600;
    color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;
}
.roi-table tbody tr { border-bottom:1px solid #f1f5f9;transition:background .12s; }
.roi-table tbody tr:hover { background:#f8fafc; }
.roi-table td { padding:8px 12px;vertical-align:middle; }
.roi-table tr.row-dirty { background:#fffbeb !important; }

.roi-input {
    width:100%;padding:7px 10px;border:1px solid transparent;border-radius:6px;
    font-size:13px;font-family:inherit;color:#111827;background:transparent;
    transition:all .15s;
}
.roi-input:hover   { background:#f9fafb;border-color:#e5e7eb; }
.roi-input:focus   { outline:none;background:#fff;border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.1); }

.btn-icon {
    display:inline-flex;align-items:center;justify-content:center;
    width:30px;height:30px;border:none;border-radius:6px;
    cursor:pointer;font-size:13px;transition:all .15s;margin-right:4px;
}
.btn-icon.save   { background:#dbeafe;color:#2563eb; }
.btn-icon.save:hover   { background:#bfdbfe; }
.btn-icon.save:disabled { opacity:.35;cursor:not-allowed; }
.btn-icon.delete { background:#fef2f2;color:#dc2626; }
.btn-icon.delete:hover { background:#fee2e2;transform:scale(1.1); }

/* ── Modal (delete confirm) ─────────────────────────────────────────────── */
.modal-overlay {
    display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99998;
    align-items:center;justify-content:center;padding:16px;
}
.modal-overlay.open { display:flex; }
.modal-box {
    background:#fff;border-radius:14px;max-width:480px;width:100%;
    box-shadow:0 12px 40px rgba(0,0,0,.25);animation:slideInRight .2s ease;
}
.modal-body { padding:20px 22px; }
.modal-foot { display:flex;justify-content:flex-end;gap:10px;padding:0 22px 20px; }

@keyframes slideInRight {
    from { opacity:0;transform:translateX(20px) scale(.98); }
    to   { opacity:1;transform:translateX(0) scale(1); }
}

@media(max-width:768px) {
    .content-card { padding:14px; }
}
</style>

<script>
let currentRoi = [];
let deleteTarget = null; // { id, label }

$(document).ready(function () {
    loadRoi();
    document.getElementById('roiInput').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); addRoi(); }
    });
});

// ── Load ROI list ────────────────────────────────────────────────────────────────
function loadRoi(q) {
    q = q || '';
    const tbody = document.getElementById('roiBody');
    tbody.innerHTML =
        '<tr><td colspan="3" style="text-align:center;padding:32px;color:#6b7280;">' +
        '<i class="fa-solid fa-spinner fa-spin" style="margin-right:8px;"></i>Loading…</td></tr>';

    fetch('roi.php?ajax_load=1&q=' + encodeURIComponent(q))
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            if (!data.success) {
                showToast('❌ ' + (data.message || 'Failed to load ROI list.'), 'error');
                tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:32px;color:#ef4444;">Failed to load.</td></tr>';
                return;
            }
            currentRoi = data.roi || [];
            renderRows(currentRoi);
        })
        .catch(function (err) {
            showToast('❌ Failed to load: ' + err.message, 'error');
            tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:32px;color:#ef4444;">Failed to load. Check your connection.</td></tr>';
        });
}

// ── Render rows ────────────────────────────────────────────────────────────────
function renderRows(list) {
    const tbody = document.getElementById('roiBody');
    tbody.innerHTML = '';
    document.getElementById('sumTotal').textContent = list.length;

    if (list.length === 0) {
        tbody.innerHTML =
            '<tr id="emptyRow"><td colspan="3" style="text-align:center;padding:40px;color:#9ca3af;">' +
            '<i class="fa-solid fa-folder-open" style="font-size:24px;margin-bottom:10px;display:block;"></i>' +
            'No ROI added yet. Use the box above to add one.</td></tr>';
        return;
    }

    list.forEach(function (item, idx) {
        const tr = document.createElement('tr');
        tr.dataset.id = item.id;

        const tdNum = document.createElement('td');
        tdNum.textContent = idx + 1;

        const tdName = document.createElement('td');
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'roi-input';
        inp.value = item.roi_name;
        inp.addEventListener('input', function () {
            tr.classList.add('row-dirty');
            tr.querySelector('.btn-icon.save').disabled = false;
        });
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); saveRow(tr); }
        });
        tdName.appendChild(inp);

        const tdActions = document.createElement('td');
        const btnSave = document.createElement('button');
        btnSave.className = 'btn-icon save';
        btnSave.title = 'Save changes';
        btnSave.disabled = true;
        btnSave.innerHTML = '<i class="fa-solid fa-floppy-disk"></i>';
        btnSave.addEventListener('click', function () { saveRow(tr); });

        const btnDel = document.createElement('button');
        btnDel.className = 'btn-icon delete';
        btnDel.title = 'Delete';
        btnDel.innerHTML = '<i class="fa-solid fa-trash-can"></i>';
        btnDel.addEventListener('click', function () { openDeleteModal(item.id, item.roi_name); });

        tdActions.appendChild(btnSave);
        tdActions.appendChild(btnDel);

        tr.appendChild(tdNum);
        tr.appendChild(tdName);
        tr.appendChild(tdActions);
        tbody.appendChild(tr);
    });
}

// ── Quick add ────────────────────────────────────────────────────────────────────
function addRoi() {
    const input = document.getElementById('roiInput');
    const errEl = document.getElementById('errRoiInput');
    const name  = input.value.trim();

    errEl.classList.remove('show');
    errEl.textContent = '';

    if (!name) {
        errEl.textContent = 'Please type a ROI name.';
        errEl.classList.add('show');
        input.focus();
        return;
    }

    const fd = new FormData();
    fd.append('ajax_save', '1');
    fd.append('id', '');
    fd.append('roi_name', name);

    fetch('roi.php', { method: 'POST', body: fd })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            if (data.success) {
                showToast('✅ ' + data.message, 'success');
                input.value = '';
                input.focus();
                loadRoi();
            } else {
                errEl.textContent = data.message || 'Could not add ROI.';
                errEl.classList.add('show');
                showToast('❌ ' + (data.message || 'Save failed.'), 'error');
            }
        })
        .catch(function (err) {
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

// ── Save inline edit ───────────────────────────────────────────────────────────
function saveRow(tr) {
    const id   = tr.dataset.id;
    const name = tr.querySelector('.roi-input').value.trim();
    if (!name) { showToast('⚠️ ROI name cannot be empty.', 'warn'); return; }

    const btn = tr.querySelector('.btn-icon.save');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

    const fd = new FormData();
    fd.append('ajax_save', '1');
    fd.append('id', id);
    fd.append('roi_name', name);

    fetch('roi.php', { method: 'POST', body: fd })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i>';
            if (data.success) {
                tr.classList.remove('row-dirty');
                btn.disabled = true;
                showToast('✅ ' + data.message, 'success');
            } else {
                btn.disabled = false;
                showToast('❌ ' + (data.message || 'Save failed.'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i>';
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

// ── Delete Modal ───────────────────────────────────────────────────────────────
function openDeleteModal(id, name) {
    deleteTarget = { id: id, label: name };
    document.getElementById('deleteRoiLabel').textContent = name;
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

    fetch('roi.php', { method: 'POST', body: fd })
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
                loadRoi();
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
    loadRoi(q);
}
function clearSearch() {
    document.getElementById('searchInput').value = '';
    loadRoi('');
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
