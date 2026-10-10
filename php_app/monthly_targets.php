<?php
include 'config.php';

// ─── AJAX — must run before header.php outputs any HTML ───────────────────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        echo json_encode(['success' => false, 'message' => "PHP Error: $errstr in $errfile:$errline"]);
        exit;
    });

    // ── GET: ALL distinct rep codes from the entire table (no month/year filter)
    if (isset($_GET['action']) && $_GET['action'] === 'get_reps') {
        $sql = "SELECT DISTINCT d.sales_person_code
                FROM   loading_summary_import_details d
                INNER JOIN loading_summary_imports i ON i.id = d.import_id
                WHERE  d.sales_person_code IS NOT NULL
                  AND  TRIM(d.sales_person_code) <> ''
                ORDER BY d.sales_person_code";

        $res = mysqli_query($conn, $sql);
        if (!$res) {
            echo json_encode(['success' => false, 'message' => 'Query error: ' . mysqli_error($conn)]);
            exit;
        }
        $reps = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $code = trim($row['sales_person_code']);
            if ($code !== '' && !in_array($code, $reps)) {
                $reps[] = $code;
            }
        }
        echo json_encode(['success' => true, 'reps' => $reps]);
        exit;
    }

    // ── GET: saved targets for a month/year ────────────────────────────────────
    if (isset($_GET['action']) && $_GET['action'] === 'get_targets') {
        $month = intval($_GET['month']);
        $year  = intval($_GET['year']);
        $sql   = "SELECT id, rep_code, target_amount, updated_at
                  FROM   monthly_targets
                  WHERE  month = $month AND year = $year
                  ORDER BY rep_code";
        $res = mysqli_query($conn, $sql);
        if (!$res) {
            echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
            exit;
        }
        $rows = [];
        while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
        echo json_encode(['success' => true, 'targets' => $rows]);
        exit;
    }

    // ── POST: upsert all targets ───────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_targets') {
        $month   = intval($_POST['month']);
        $year    = intval($_POST['year']);
        $targets = $_POST['targets'] ?? [];
        $errors  = [];
        foreach ($targets as $rep_code => $amount) {
            $rep_code = mysqli_real_escape_string($conn, trim($rep_code));
            $amount   = floatval($amount);
            if ($rep_code === '') continue;
            $sql = "INSERT INTO monthly_targets (rep_code, month, year, target_amount, created_at, updated_at)
                    VALUES ('$rep_code', $month, $year, $amount, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE target_amount = $amount, updated_at = NOW()";
            if (!mysqli_query($conn, $sql)) $errors[] = "Rep $rep_code: " . mysqli_error($conn);
        }
        echo $errors
            ? json_encode(['success' => false, 'message' => implode('; ', $errors)])
            : json_encode(['success' => true, 'message' => 'Targets saved successfully.']);
        exit;
    }

    // ── POST: update single target ─────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_target') {
        $id     = intval($_POST['id']);
        $amount = floatval($_POST['target_amount']);
        $sql    = "UPDATE monthly_targets SET target_amount = $amount, updated_at = NOW() WHERE id = $id";
        echo mysqli_query($conn, $sql)
            ? json_encode(['success' => true])
            : json_encode(['success' => false, 'message' => mysqli_error($conn)]);
        exit;
    }

    // ── POST: delete target ────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_target') {
        $id  = intval($_POST['id']);
        $sql = "DELETE FROM monthly_targets WHERE id = $id";
        echo mysqli_query($conn, $sql)
            ? json_encode(['success' => true])
            : json_encode(['success' => false, 'message' => mysqli_error($conn)]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

include 'header.php';
?>

<style>
/* ── Cards ── */
.content-card {
    background: #ffffff;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}
.card-title {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 16px;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* ── Filter bar ── */
.filter-bar { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.filter-group { display: flex; flex-direction: column; gap: 4px; }
.filter-group label { font-size: 12px; font-weight: 600; color: #555; }
.filter-group select {
    padding: 8px 12px;
    border: 1px solid #e5e5e5;
    border-radius: 6px;
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    color: #1f2937;
    background: #fafafa;
    outline: none;
    transition: border-color 0.2s;
    min-width: 140px;
}
.filter-group select:focus { border-color: #000; background: #fff; }

/* ── Buttons ── */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    font-family: 'Inter', sans-serif;
    text-decoration: none;
}
.btn-primary   { background: #000; color: #fff; }
.btn-primary:hover   { background: #333; }
.btn-secondary { background: #f5f5f5; color: #333; border: 1px solid #e5e5e5; }
.btn-secondary:hover { background: #e5e5e5; }
.btn-success   { background: #166534; color: #fff; }
.btn-success:hover   { background: #15803d; }
.btn-danger    { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.btn-danger:hover    { background: #fecaca; }
.btn-sm { padding: 5px 10px; font-size: 12px; }

/* ── Table ── */
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table thead { background: #fafafa; border-bottom: 2px solid #e5e5e5; }
.data-table th { padding: 12px; text-align: left; font-weight: 600; color: #333; font-size: 12px; white-space: nowrap; }
.data-table tbody tr { border-bottom: 1px solid #f0f0f0; }
.data-table tbody tr:hover { background: #fafafa; }
.data-table td { padding: 10px 12px; color: #333; }

/* ── Amount input ── */
.amount-input {
    padding: 6px 10px;
    border: 1px solid #e5e5e5;
    border-radius: 6px;
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    width: 150px;
    text-align: right;
    transition: border-color 0.2s;
}
.amount-input:focus { outline: none; border-color: #000; }

/* ── Alerts ── */
.alert {
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 16px;
    display: none;
    align-items: center;
    gap: 8px;
    font-size: 13px;
}
.alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

/* ── States ── */
.empty-state { text-align: center; padding: 32px 20px; color: #999; font-size: 13px; }
.spinner {
    display: inline-block;
    width: 16px; height: 16px;
    border: 2px solid #e5e5e5;
    border-top-color: #000;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
    vertical-align: middle;
    margin-right: 6px;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Modal overlay ── */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}
.modal-overlay.active { display: flex; }

.modal-box {
    background: #fff;
    border-radius: 10px;
    width: 560px;
    max-width: 95vw;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}
.modal-header {
    padding: 18px 20px;
    border-bottom: 1px solid #e5e5e5;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}
.modal-title {
    font-size: 15px;
    font-weight: 600;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 8px;
}
.modal-close {
    background: none;
    border: none;
    font-size: 18px;
    color: #999;
    cursor: pointer;
    padding: 4px;
    line-height: 1;
    border-radius: 4px;
    transition: color 0.2s;
}
.modal-close:hover { color: #333; }
.modal-body {
    padding: 0;
    overflow-y: auto;
    flex: 1;
}
.modal-footer {
    padding: 14px 20px;
    border-top: 1px solid #e5e5e5;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    flex-shrink: 0;
}

/* ── Saved section ── */
#saved-section { display: none; }
</style>

<!-- Page Header -->
<div class="page-header">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <div>
            <h2 class="page-title"><i class="fa-solid fa-bullseye"></i> Monthly Sales Targets</h2>
            <p class="page-subtitle">Set and manage monthly sales targets by representative</p>
        </div>
    </div>
</div>

<!-- Alerts -->
<div class="alert alert-success" id="alert-success">
    <i class="fa-solid fa-circle-check"></i> <span id="alert-msg"></span>
</div>
<div class="alert alert-error" id="alert-error">
    <i class="fa-solid fa-circle-xmark"></i> <span id="alert-err-msg"></span>
</div>

<!-- Period Selector -->
<div class="content-card">
    <h3 class="card-title"><i class="fa-solid fa-calendar-days"></i> Select Period</h3>
    <div class="filter-bar">
        <div class="filter-group">
            <label>Month</label>
            <select id="sel-month">
                <?php
                $months   = ['January','February','March','April','May','June',
                             'July','August','September','October','November','December'];
                $curMonth = (int)date('n');
                foreach ($months as $i => $name) {
                    $n   = $i + 1;
                    $sel = ($n === $curMonth) ? 'selected' : '';
                    echo "<option value=\"$n\" $sel>$name</option>";
                }
                ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Year</label>
            <select id="sel-year">
                <?php
                $curYear = (int)date('Y');
                for ($y = $curYear - 2; $y <= $curYear + 1; $y++) {
                    $sel = ($y === $curYear) ? 'selected' : '';
                    echo "<option value=\"$y\" $sel>$y</option>";
                }
                ?>
            </select>
        </div>
        <button class="btn btn-primary" id="btn-open-modal">
            <i class="fa-solid fa-pencil"></i> Set Targets
        </button>
    </div>
</div>

<!-- Saved Targets Card -->
<div class="content-card" id="saved-section">
    <h3 class="card-title">
        <i class="fa-solid fa-list-check"></i> Saved Targets
        <span id="saved-period-label" style="font-weight:400;font-size:13px;color:#6b7280;margin-left:4px;"></span>
    </h3>
    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Rep Code</th>
                    <th style="text-align:right;">Target Amount (LKR)</th>
                    <th>Last Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="saved-body">
                <tr><td colspan="5" class="empty-state">No targets saved yet.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Modal ── -->
<div class="modal-overlay" id="target-modal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fa-solid fa-bullseye"></i>
                Enter Targets —
                <span id="modal-period-label" style="font-weight:400;color:#6b7280;"></span>
            </div>
            <button class="modal-close" id="btn-modal-close" title="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="modal-body">
            <table class="data-table" id="modal-table">
                <thead>
                    <tr>
                        <th style="padding-left:20px;">#</th>
                        <th>Rep Code</th>
                        <th style="text-align:right;padding-right:20px;">Target Amount (LKR)</th>
                    </tr>
                </thead>
                <tbody id="modal-body">
                    <tr><td colspan="3" class="empty-state"><span class="spinner"></span>Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="btn-modal-cancel">
                <i class="fa-solid fa-xmark"></i> Cancel
            </button>
            <button class="btn btn-success" id="btn-modal-save">
                <i class="fa-solid fa-floppy-disk"></i> Save All Targets
            </button>
        </div>
    </div>
</div>

<script>
var MONTH_NAMES = ['','January','February','March','April','May','June',
                   'July','August','September','October','November','December'];
var BASE_URL = window.location.href.split('?')[0];

function showAlert(type, msg) {
    document.getElementById('alert-success').style.display = 'none';
    document.getElementById('alert-error').style.display   = 'none';
    if (type === 'success') {
        document.getElementById('alert-msg').textContent = msg;
        document.getElementById('alert-success').style.display = 'flex';
        setTimeout(function() { document.getElementById('alert-success').style.display = 'none'; }, 4000);
    } else {
        document.getElementById('alert-err-msg').textContent = msg;
        document.getElementById('alert-error').style.display = 'flex';
        setTimeout(function() { document.getElementById('alert-error').style.display = 'none'; }, 5000);
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function getPeriod() {
    return {
        month: parseInt(document.getElementById('sel-month').value),
        year:  parseInt(document.getElementById('sel-year').value)
    };
}

function fmtAmount(v) {
    return parseFloat(v || 0).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function ajaxGet(params) {
    var qs = Object.keys(params).map(function(k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
    }).join('&');
    return fetch(BASE_URL + '?' + qs, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(function(txt) {
            try { return JSON.parse(txt); }
            catch(e) { console.error('Non-JSON:', txt); throw new Error('Server error. Check PHP logs.'); }
        });
}

function ajaxPost(fd) {
    return fetch(BASE_URL, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(function(txt) {
            try { return JSON.parse(txt); }
            catch(e) { console.error('Non-JSON:', txt); throw new Error('Server error. Check PHP logs.'); }
        });
}

// ── Modal open ────────────────────────────────────────────────────────────────
document.getElementById('btn-open-modal').addEventListener('click', function() {
    var p     = getPeriod();
    var label = MONTH_NAMES[p.month] + ' ' + p.year;

    document.getElementById('modal-period-label').textContent = label;
    document.getElementById('modal-body').innerHTML =
        '<tr><td colspan="3" class="empty-state"><span class="spinner"></span>Loading reps…</td></tr>';
    document.getElementById('target-modal').classList.add('active');

    // Load ALL reps (all-time) + this month's existing targets in parallel
    Promise.all([
        ajaxGet({ action: 'get_reps' }),
        ajaxGet({ action: 'get_targets', month: p.month, year: p.year })
    ]).then(function(results) {
        var repData = results[0];
        var tgtData = results[1];

        var existingMap = {};
        if (tgtData.success && tgtData.targets) {
            tgtData.targets.forEach(function(t) { existingMap[t.rep_code] = t.target_amount; });
        }

        var tbody = document.getElementById('modal-body');

        if (!repData.success) {
            tbody.innerHTML = '<tr><td colspan="3" class="empty-state" style="color:#ef4444;">' +
                'Error: ' + (repData.message || 'Unknown error') + '</td></tr>';
            return;
        }

        if (!repData.reps || repData.reps.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" class="empty-state">No rep codes found in Loading Summary.</td></tr>';
            return;
        }

        tbody.innerHTML = repData.reps.map(function(rep, i) {
            var val = existingMap[rep] !== undefined ? existingMap[rep] : '';
            return '<tr>' +
                '<td style="padding-left:20px;">' + (i + 1) + '</td>' +
                '<td><strong>' + rep + '</strong></td>' +
                '<td style="text-align:right;padding-right:20px;">' +
                    '<input type="number" class="amount-input modal-amount" data-rep="' + rep + '"' +
                    ' value="' + val + '" placeholder="0.00" step="0.01" min="0">' +
                '</td>' +
            '</tr>';
        }).join('');

        // Enter key moves to next input instead of submitting
        var inputs = tbody.querySelectorAll('.modal-amount');
        inputs.forEach(function(inp, idx) {
            inp.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var next = inputs[idx + 1];
                    if (next) { next.focus(); next.select(); }
                    else { document.getElementById('btn-modal-save').focus(); }
                }
            });
        });

    }).catch(function(err) {
        document.getElementById('modal-body').innerHTML =
            '<tr><td colspan="3" class="empty-state" style="color:#ef4444;">' +
            (err.message || 'Failed to load.') + '</td></tr>';
    });
});

// ── Modal close — only X button and Cancel, NOT backdrop click ────────────────
document.getElementById('btn-modal-close').addEventListener('click', closeModal);
document.getElementById('btn-modal-cancel').addEventListener('click', closeModal);
// Backdrop: intentionally NO click handler — modal stays open

function closeModal() {
    document.getElementById('target-modal').classList.remove('active');
}

// ── Save ──────────────────────────────────────────────────────────────────────
document.getElementById('btn-modal-save').addEventListener('click', function() {
    var p   = getPeriod();
    var btn = document.getElementById('btn-modal-save');
    var fd  = new FormData();
    fd.append('action', 'save_targets');
    fd.append('month', p.month);
    fd.append('year', p.year);

    document.querySelectorAll('#modal-body .modal-amount').forEach(function(inp) {
        if (inp.dataset.rep) fd.append('targets[' + inp.dataset.rep + ']', inp.value || '0');
    });

    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner"></span>Saving…';

    ajaxPost(fd).then(function(data) {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save All Targets';
        if (data.success) {
            closeModal();
            showAlert('success', data.message);
            var p2 = getPeriod();
            loadSavedTargets(p2.month, p2.year);
        } else {
            showAlert('error', data.message || 'Save failed.');
        }
    }).catch(function(err) {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save All Targets';
        showAlert('error', err.message || 'Save failed.');
    });
});

// ── period dropdowns: reload saved targets on change ──────────────────────────
document.getElementById('sel-month').addEventListener('change', function() {
    var p = getPeriod(); loadSavedTargets(p.month, p.year);
});
document.getElementById('sel-year').addEventListener('change', function() {
    var p = getPeriod(); loadSavedTargets(p.month, p.year);
});

// ── Load saved targets table ──────────────────────────────────────────────────
function loadSavedTargets(month, year) {
    var label = MONTH_NAMES[month] + ' ' + year;
    document.getElementById('saved-section').style.display    = 'block';
    document.getElementById('saved-period-label').textContent = '— ' + label;
    document.getElementById('saved-body').innerHTML =
        '<tr><td colspan="5" class="empty-state"><span class="spinner"></span>Loading…</td></tr>';

    ajaxGet({ action: 'get_targets', month: month, year: year }).then(function(data) {
        var tbody = document.getElementById('saved-body');
        if (!data.success || !data.targets || data.targets.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="empty-state">No saved targets for ' + label + '.</td></tr>';
            return;
        }
        tbody.innerHTML = data.targets.map(function(t, i) {
            return '<tr id="saved-row-' + t.id + '">' +
                '<td>' + (i + 1) + '</td>' +
                '<td><strong>' + t.rep_code + '</strong></td>' +
                '<td style="text-align:right;" id="td-amount-' + t.id + '">' +
                    '<span class="display-amount">' + fmtAmount(t.target_amount) + '</span>' +
                    '<input type="number" class="amount-input edit-input" style="display:none;"' +
                    ' value="' + t.target_amount + '" step="0.01" min="0">' +
                '</td>' +
                '<td style="color:#6b7280;font-size:12px;">' + t.updated_at + '</td>' +
                '<td>' +
                    '<div style="display:flex;gap:6px;" id="actions-' + t.id + '">' +
                        '<button class="btn btn-secondary btn-sm" onclick="startEdit(' + t.id + ')">' +
                            '<i class="fa-solid fa-pencil"></i> Edit</button>' +
                        '<button class="btn btn-danger btn-sm" onclick="deleteTarget(' + t.id + ')">' +
                            '<i class="fa-solid fa-trash"></i> Delete</button>' +
                    '</div>' +
                    '<div style="display:none;gap:6px;" id="edit-actions-' + t.id + '">' +
                        '<button class="btn btn-success btn-sm" onclick="saveEdit(' + t.id + ')">' +
                            '<i class="fa-solid fa-check"></i> Update</button>' +
                        '<button class="btn btn-secondary btn-sm" onclick="cancelEdit(' + t.id + ',' + t.target_amount + ')">' +
                            '<i class="fa-solid fa-xmark"></i> Cancel</button>' +
                    '</div>' +
                '</td>' +
            '</tr>';
        }).join('');
    }).catch(function(err) {
        document.getElementById('saved-body').innerHTML =
            '<tr><td colspan="5" class="empty-state" style="color:#ef4444;">' + err.message + '</td></tr>';
    });
}

// ── Inline edit ───────────────────────────────────────────────────────────────
function startEdit(id) {
    var td = document.getElementById('td-amount-' + id);
    td.querySelector('.display-amount').style.display = 'none';
    td.querySelector('.edit-input').style.display     = 'inline-block';
    td.querySelector('.edit-input').focus();
    document.getElementById('actions-' + id).style.display      = 'none';
    document.getElementById('edit-actions-' + id).style.display = 'flex';
}

function cancelEdit(id, originalAmount) {
    var td = document.getElementById('td-amount-' + id);
    td.querySelector('.display-amount').style.display = '';
    td.querySelector('.edit-input').style.display     = 'none';
    td.querySelector('.edit-input').value             = originalAmount;
    document.getElementById('actions-' + id).style.display      = 'flex';
    document.getElementById('edit-actions-' + id).style.display = 'none';
}

function saveEdit(id) {
    var td  = document.getElementById('td-amount-' + id);
    var inp = td.querySelector('.edit-input');
    var amt = parseFloat(inp.value) || 0;
    var fd  = new FormData();
    fd.append('action', 'update_target');
    fd.append('id', id);
    fd.append('target_amount', amt);
    ajaxPost(fd).then(function(data) {
        if (data.success) {
            td.querySelector('.display-amount').textContent   = fmtAmount(amt);
            td.querySelector('.display-amount').style.display = '';
            inp.style.display = 'none';
            document.getElementById('actions-' + id).style.display      = 'flex';
            document.getElementById('edit-actions-' + id).style.display = 'none';
            showAlert('success', 'Target updated.');
        } else {
            showAlert('error', data.message || 'Update failed.');
        }
    });
}

function deleteTarget(id) {
    if (!confirm('Delete this target? This cannot be undone.')) return;
    var fd = new FormData();
    fd.append('action', 'delete_target');
    fd.append('id', id);
    ajaxPost(fd).then(function(data) {
        if (data.success) {
            var row = document.getElementById('saved-row-' + id);
            if (row) row.remove();
            showAlert('success', 'Target deleted.');
            if (document.getElementById('saved-body').querySelectorAll('tr[id]').length === 0) {
                document.getElementById('saved-body').innerHTML =
                    '<tr><td colspan="5" class="empty-state">No saved targets.</td></tr>';
            }
        } else {
            showAlert('error', data.message || 'Delete failed.');
        }
    });
}

// ── Auto-load on page open ────────────────────────────────────────────────────
(function() {
    var p = getPeriod();
    loadSavedTargets(p.month, p.year);
}());
</script>

<?php include 'footer.php'; ?>