<?php
/**
 * import_credit_bills.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Upload a Credit-Bills Excel (same format as Credit_Bills_Final_01.xlsx).
 * • Delivery date is read from Col 26 of each row (not a manual input).
 * • T-Code = Col 4, Bill No = Col 6, Bill Date = Col 8, Final Bill Amt = Col 22.
 * • For each row:
 *     – Check if a field_summary already exists for that delivery_date.
 *     – If YES  → use that field_summary_id.
 *     – If NO   → create a new field_summary with code DATECREDIT-YYYYMMDD.
 *     – Check if a credit_requests row already exists for this bill_no.
 *       If YES  → skip (never update existing credit bills).
 *       If NO   → insert into field_summary_details + credit_requests.
 *
 * FIXES APPLIED:
 *   1. fetch() reads response as text first, parses JSON manually — so a
 *      corrupt/non-JSON server response shows the real content instead of
 *      a misleading "Network error".
 *   2. Detailed error display: raw server output shown when JSON parse fails.
 *   3. Per-row import errors displayed per-bill in the result summary.
 * ─────────────────────────────────────────────────────────────────────────────
 */
include 'config.php';
include 'header.php';

/* ── ensure columns exist ── */
$_icb = mysqli_query($conn, "SHOW COLUMNS FROM field_summary_details LIKE 'is_credit_bill'");
if (!$_icb || mysqli_num_rows($_icb) === 0) {
    mysqli_query($conn, "ALTER TABLE field_summary_details ADD COLUMN is_credit_bill TINYINT(1) NOT NULL DEFAULT 0");
}
$_cb = mysqli_query($conn, "SHOW COLUMNS FROM credit_requests LIKE 'credit_bill_no'");
if (!$_cb || mysqli_num_rows($_cb) === 0) {
    mysqli_query($conn, "ALTER TABLE credit_requests ADD COLUMN credit_bill_no VARCHAR(100) NOT NULL DEFAULT '' AFTER reason");
}
?>

<style>
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:22px;margin-bottom:20px;}
.card-title{font-size:15px;font-weight:700;margin-bottom:16px;color:#1f2937;display:flex;align-items:center;gap:8px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;font-family:'Inter',sans-serif;text-decoration:none;}
.btn-primary{background:#7c3aed;color:#fff;}.btn-primary:hover{background:#6d28d9;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-success{background:#16a34a;color:#fff;}.btn-success:hover{background:#15803d;}
.btn:disabled{opacity:.5;cursor:not-allowed;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}
@media(max-width:600px){.form-row{grid-template-columns:1fr;}}
.form-group{display:flex;flex-direction:column;gap:6px;}
.form-label{font-size:13px;font-weight:600;color:#374151;}
.form-input{padding:9px 11px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;color:#333;outline:none;transition:border-color .2s;width:100%;box-sizing:border-box;}
.form-input:focus{border-color:#7c3aed;}
.form-hint{font-size:11px;color:#6b7280;margin-top:2px;}
.form-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:6px;}
.info-box{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px;margin-bottom:18px;display:flex;gap:12px;align-items:flex-start;font-size:13px;color:#1e40af;}
.info-box i{margin-top:1px;flex-shrink:0;font-size:15px;}
.error-box{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 18px;margin-bottom:18px;font-size:13px;color:#991b1b;display:none;}
.error-box pre{margin:8px 0 0;font-size:11px;white-space:pre-wrap;word-break:break-all;background:#fff5f5;padding:8px;border-radius:4px;border:1px solid #fecaca;max-height:200px;overflow-y:auto;}
.preview-summary{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;}
.summary-item{background:#f9fafb;border:1px solid #e5e5e5;border-radius:8px;padding:12px 20px;min-width:130px;}
.summary-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;}
.summary-value{font-size:20px;font-weight:800;color:#1f2937;}
.summary-value.valid{color:#16a34a;}.summary-value.skipped{color:#f59e0b;}.summary-value.invalid{color:#dc2626;}
.table-responsive{overflow-x:auto;max-height:55vh;border:1px solid #e5e5e5;border-radius:8px;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table thead{background:#f3e8ff;border-bottom:2px solid #a855f7;position:sticky;top:0;z-index:5;}
.data-table th{padding:10px 8px;font-weight:700;color:#4c1d95;font-size:11px;white-space:nowrap;text-align:left;}
.data-table tbody tr{border-bottom:1px solid #f0f0f0;}
.data-table tbody tr:hover{background:#fafafa;}
.data-table td{padding:9px 8px;color:#333;white-space:nowrap;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;font-size:11px;font-weight:600;}
.badge-new{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;}
.badge-skip{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.badge-fail{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.badge-fs-new{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;font-size:10px;margin-left:4px;}
.badge-fs-exist{background:#faf5ff;color:#6d28d9;border:1px solid #ddd6fe;font-size:10px;margin-left:4px;}
.progress-bar{height:18px;background:#e5e5e5;border-radius:9px;overflow:hidden;margin:10px 0;}
.progress-fill{height:100%;background:linear-gradient(90deg,#7c3aed,#a855f7);border-radius:9px;transition:width .3s;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;min-width:30px;}
.result-box{padding:14px 18px;border-radius:8px;margin-top:12px;font-size:13px;}
.result-box.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;}
.result-box.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
.spinner{border:3px solid #f3f3f3;border-top:3px solid #7c3aed;border-radius:50%;width:18px;height:18px;animation:spin 1s linear infinite;display:inline-block;vertical-align:middle;}
@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}
</style>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-file-invoice-dollar" style="color:#7c3aed;"></i> Import Credit Bills
            </h2>
            <p class="page-subtitle">Upload Credit Bills Excel — delivery date is read from the file (Col 26)</p>
        </div>
        <a href="credit_bill_import_history.php" class="btn btn-secondary">
            <i class="fa-solid fa-clock-rotate-left"></i> Import History
        </a>
    </div>
</div>

<!-- Info box -->
<div class="info-box">
    <i class="fa-solid fa-circle-info"></i>
    <div>
        <strong>How this works:</strong>
        Each row's delivery date is read from <strong>Col 26</strong> of the Excel.
        The system checks if a Field Summary already exists for that date.
        If yes — it is used; if no — a new one is created with code <code>DATECREDIT-YYYYMMDD</code>.
        Bills already present (by bill number) are <strong>skipped</strong> and never overwritten.
        Each new bill is added to <code>field_summary_details</code> and an emergency credit request is created automatically.
        <br><br>
        <strong>Requires:</strong> <code>PhpSpreadsheet</code> installed on the server
        (<code>composer require phpoffice/phpspreadsheet</code>).
    </div>
</div>

<!-- Error display (shown when server returns non-JSON) -->
<div class="error-box" id="errorBox">
    <strong><i class="fa-solid fa-triangle-exclamation"></i> Server Error</strong>
    <div id="errorMsg"></div>
    <pre id="errorRaw" style="display:none;"></pre>
</div>

<!-- Step 1 Upload -->
<div class="content-card" id="uploadCard">
    <h3 class="card-title"><i class="fa-solid fa-upload" style="color:#7c3aed;"></i> Step 1: Upload Excel File</h3>
    <div class="form-group" style="margin-bottom:16px;">
        <label class="form-label">Credit Bills Excel File <span style="color:#dc2626;">*</span></label>
        <input type="file" id="excel_file" name="excel_file" class="form-input" accept=".xlsx,.xls">
        <span class="form-hint">
            <strong>Expected columns:</strong>
            Col 2: Salesperson Code &nbsp;|&nbsp;
            Col 4: T-Code (Outlet HUL Code) &nbsp;|&nbsp;
            Col 6: Bill Number &nbsp;|&nbsp;
            Col 8: Bill Date &nbsp;|&nbsp;
            Col 10: Party Name &nbsp;|&nbsp;
            Col 22: Final Bill Amount &nbsp;|&nbsp;
            Col 26: Delivery Date <em>(used automatically — no manual date entry needed)</em>
        </span>
    </div>
    <div class="form-actions">
        <button type="button" class="btn btn-primary" id="uploadBtn" onclick="startUpload()">
            <i class="fa-solid fa-upload"></i> Upload &amp; Preview
        </button>
    </div>
</div>

<!-- Step 2 Preview -->
<div class="content-card" id="previewSection" style="display:none;">
    <h3 class="card-title"><i class="fa-solid fa-eye" style="color:#7c3aed;"></i> Step 2: Preview &amp; Validate</h3>

    <div class="preview-summary">
        <div class="summary-item"><div class="summary-label">Total Records</div><div class="summary-value" id="totalRecords">0</div></div>
        <div class="summary-item"><div class="summary-label">New Bills</div><div class="summary-value valid" id="newRecords">0</div></div>
        <div class="summary-item"><div class="summary-label">Already Exists (Skip)</div><div class="summary-value skipped" id="skipRecords">0</div></div>
        <div class="summary-item"><div class="summary-label">Errors</div><div class="summary-value invalid" id="failRecords">0</div></div>
        <div class="summary-item"><div class="summary-label">New Field Summaries</div><div class="summary-value" id="newFsCount" style="color:#1e40af;">0</div></div>
    </div>

    <!-- Filter -->
    <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
        <button type="button" class="btn btn-secondary" id="filterAllBtn"  onclick="setFilter('all')"  style="border-color:#7c3aed;color:#7c3aed;"><i class="fa-solid fa-list"></i> All</button>
        <button type="button" class="btn btn-secondary" id="filterNewBtn"  onclick="setFilter('new')"><i class="fa-solid fa-plus-circle"></i> New Only</button>
        <button type="button" class="btn btn-secondary" id="filterSkipBtn" onclick="setFilter('skip')"><i class="fa-solid fa-forward"></i> Skipped Only</button>
        <button type="button" class="btn btn-secondary" id="filterFailBtn" onclick="setFilter('fail')"><i class="fa-solid fa-xmark-circle"></i> Errors Only</button>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Delivery Date</th>
                    <th>T-Code</th>
                    <th>Bill No</th>
                    <th>Bill Date</th>
                    <th>Party Name</th>
                    <th>Final Bill Amt</th>
                    <th>Field Summary</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody id="previewTableBody"></tbody>
        </table>
    </div>

    <div class="form-actions" style="margin-top:16px;">
        <button type="button" class="btn btn-secondary" onclick="resetForm()"><i class="fa-solid fa-xmark"></i> Cancel</button>
        <button type="button" class="btn btn-success" id="importBtn" onclick="startImport()">
            <i class="fa-solid fa-file-import"></i> Import New Bills (<span id="importCount">0</span>)
        </button>
    </div>
</div>

<!-- Step 3 Progress -->
<div class="content-card" id="progressSection" style="display:none;">
    <h3 class="card-title"><i class="fa-solid fa-spinner fa-spin" style="color:#7c3aed;"></i> Importing…</h3>
    <div class="progress-bar"><div class="progress-fill" id="progressFill" style="width:0%;">0%</div></div>
    <p id="progressMsg" style="font-size:13px;color:#6b7280;margin-top:8px;">Preparing…</p>
</div>

<!-- Step 4 Result -->
<div class="content-card" id="resultSection" style="display:none;">
    <h3 class="card-title"><i class="fa-solid fa-check-circle" style="color:#16a34a;"></i> Import Complete</h3>
    <div id="resultBox"></div>
    <div class="form-actions" style="margin-top:16px;">
        <button type="button" class="btn btn-primary" onclick="location.reload()"><i class="fa-solid fa-plus"></i> Import Another File</button>
        <a href="credit_bill_import_history.php" class="btn btn-secondary"><i class="fa-solid fa-clock-rotate-left"></i> View History</a>
    </div>
</div>

<script>
/* ── State ── */
let previewRows   = [];
let currentFilter = 'all';

/* ── Helpers ── */
function escH(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
function fmtNum(n){ return parseFloat(n||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }

function showError(msg, rawText) {
    var box = document.getElementById('errorBox');
    document.getElementById('errorMsg').textContent = msg;
    var pre = document.getElementById('errorRaw');
    if (rawText) { pre.textContent = rawText; pre.style.display = ''; }
    else          { pre.style.display = 'none'; }
    box.style.display = '';
    box.scrollIntoView({behavior:'smooth'});
}
function hideError(){ document.getElementById('errorBox').style.display = 'none'; }

/* ── UPLOAD ── */
function startUpload() {
    hideError();

    const btn      = document.getElementById('uploadBtn');
    const fileInput = document.getElementById('excel_file');
    const file     = fileInput.files[0];

    if (!file) {
        showError('Please select an Excel file (.xlsx or .xls) first.');
        return;
    }

    /* Immediate visible feedback */
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner"></span> Uploading & Reading…';

    const fd = new FormData();
    fd.append('action', 'preview');
    fd.append('excel_file', file);

    fetch('process_credit_bills.php', {method: 'POST', body: fd})
        .then(function(res) {
            return res.text();
        })
        .then(function(text) {
            var data;
            try {
                data = JSON.parse(text);
            } catch(e) {
                showError(
                    'Server returned an invalid response (not JSON). ' +
                    'This usually means a PHP error or missing PhpSpreadsheet library. ' +
                    'Raw server output shown below:',
                    text
                );
                return;
            }
            if (!data.success) {
                showError(data.error || 'Unknown server error');
                return;
            }
            previewRows = data.rows;
            renderPreview(data);
            document.getElementById('previewSection').style.display = '';
            document.getElementById('previewSection').scrollIntoView({behavior:'smooth'});
        })
        .catch(function(err) {
            showError('Network error — could not reach process_credit_bills.php. Error: ' + err.message);
        })
        .finally(function() {
            btn.disabled  = false;
            btn.innerHTML = '<i class="fa-solid fa-upload"></i> Upload &amp; Preview';
        });
}

/* ── RENDER PREVIEW ── */
function renderPreview(data) {
    const rows      = data.rows;
    const newCount  = rows.filter(r=>r.status==='new').length;
    const skipCount = rows.filter(r=>r.status==='skip').length;
    const failCount = rows.filter(r=>r.status==='fail').length;
    const newFsCount= data.new_fs_dates ? data.new_fs_dates.length : 0;

    document.getElementById('totalRecords').textContent = rows.length;
    document.getElementById('newRecords').textContent   = newCount;
    document.getElementById('skipRecords').textContent  = skipCount;
    document.getElementById('failRecords').textContent  = failCount;
    document.getElementById('newFsCount').textContent   = newFsCount;
    document.getElementById('importCount').textContent  = newCount;
    document.getElementById('importBtn').disabled       = (newCount === 0);

    renderTable();
}

function renderTable() {
    const tbody    = document.getElementById('previewTableBody');
    tbody.innerHTML = '';
    const filtered = currentFilter === 'all' ? previewRows
        : previewRows.filter(r => r.status === currentFilter);

    filtered.forEach((r, i) => {
        const tr = document.createElement('tr');

        let statusBadge = '';
        if (r.status === 'new')  statusBadge = `<span class="badge badge-new"><i class="fa-solid fa-plus"></i> New</span>`;
        if (r.status === 'skip') statusBadge = `<span class="badge badge-skip"><i class="fa-solid fa-forward"></i> Skip</span><br><small style="color:#92400e;">${escH(r.skip_reason||'')}</small>`;
        if (r.status === 'fail') statusBadge = `<span class="badge badge-fail"><i class="fa-solid fa-xmark"></i> Error</span><br><small style="color:#991b1b;">${escH(r.error||'')}</small>`;

        let fsBadge = '';
        if (r.fs_exists) {
            fsBadge = `<span class="badge badge-fs-exist"><i class="fa-solid fa-check"></i> Exists</span><br><small>${escH(r.fs_code||'')}</small>`;
        } else if (r.status === 'new') {
            fsBadge = `<span class="badge badge-fs-new"><i class="fa-solid fa-plus"></i> Will Create</span><br><small style="color:#1e40af;">DATECREDIT-${(r.delivery_date||'').replace(/-/g,'')}</small>`;
        }

        tr.innerHTML = `
            <td>${i+1}</td>
            <td>${escH(r.delivery_date||'—')}</td>
            <td>${escH(r.t_code||'')}</td>
            <td style="font-weight:600;">${escH(r.bill_no||'')}</td>
            <td>${escH(r.bill_date||'')}</td>
            <td>${escH(r.party_name||'')}</td>
            <td style="text-align:right;font-weight:700;">${fmtNum(r.final_bill_amount)}</td>
            <td>${fsBadge}</td>
            <td>${statusBadge}</td>`;
        tbody.appendChild(tr);
    });

    if (filtered.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:20px;color:#999;">No records to show.</td></tr>';
    }
}

function setFilter(f) {
    currentFilter = f;
    ['all','new','skip','fail'].forEach(n => {
        const btn = document.getElementById('filter' + n.charAt(0).toUpperCase() + n.slice(1) + 'Btn');
        if (btn) { btn.style.borderColor = n===f?'#7c3aed':''; btn.style.color = n===f?'#7c3aed':''; }
    });
    renderTable();
}

/* ── IMPORT ── */
function startImport() {
    const newRows = previewRows.filter(r => r.status === 'new');
    if (newRows.length === 0) { alert('No new bills to import.'); return; }
    if (!confirm('Import ' + newRows.length + ' new credit bill(s)?\nExisting bills will NOT be modified.')) return;

    hideError();
    document.getElementById('previewSection').style.display  = 'none';
    document.getElementById('progressSection').style.display = '';
    document.getElementById('progressSection').scrollIntoView({behavior:'smooth'});

    const total   = newRows.length;
    const results = [];
    let   done    = 0;

    function importNext() {
        if (done >= total) {
            const failed = results.filter(r => !r.success).length;
            setProgress(100, 'Done!');
            showResult(total, total - failed, failed, results);
            return;
        }

        const row = newRows[done];
        setProgress(Math.round(done / total * 100), 'Importing bill ' + (done+1) + ' of ' + total + ': ' + row.bill_no);

        const fd = new FormData();
        fd.append('action',            'import_row');
        fd.append('delivery_date',     row.delivery_date);
        fd.append('t_code',            row.t_code);
        fd.append('bill_no',           row.bill_no);
        fd.append('bill_date',         row.bill_date);
        fd.append('party_name',        row.party_name);
        fd.append('final_bill_amount', row.final_bill_amount);
        fd.append('salesperson_code',  row.salesperson_code || '');

        fetch('process_credit_bills.php', {method:'POST', body:fd})
            .then(function(res){ return res.text(); })
            .then(function(text){
                var data;
                try { data = JSON.parse(text); }
                catch(e){ data = {success:false, error:'Non-JSON response: ' + text.substring(0,200)}; }
                results.push({bill_no: row.bill_no, success: data.success, error: data.error||''});
            })
            .catch(function(err){
                results.push({bill_no: row.bill_no, success: false, error: 'Network error: ' + err.message});
            })
            .finally(function(){
                done++;
                importNext();
            });
    }

    importNext();
}

function setProgress(pct, msg) {
    document.getElementById('progressFill').style.width = pct + '%';
    document.getElementById('progressFill').textContent = pct + '%';
    document.getElementById('progressMsg').textContent  = msg;
}

function showResult(total, ok, fail, results) {
    document.getElementById('progressSection').style.display = 'none';
    document.getElementById('resultSection').style.display   = '';
    const box = document.getElementById('resultBox');
    const cls = fail === 0 ? 'ok' : 'err';
    let html = `<div class="result-box ${cls}">
        <strong><i class="fa-solid fa-${fail===0?'check-circle':'triangle-exclamation'}"></i>
        ${ok} of ${total} bills imported successfully.
        ${fail > 0 ? `<br>${fail} failed — see details below.` : ''}</strong>
    </div>`;
    if (fail > 0) {
        const failures = results.filter(r => !r.success);
        html += '<ul style="margin-top:10px;font-size:12px;color:#991b1b;">';
        failures.forEach(r => { html += `<li>Bill ${escH(r.bill_no)}: ${escH(r.error || 'Unknown error')}</li>`; });
        html += '</ul>';
    }
    box.innerHTML = html;
    document.getElementById('resultSection').scrollIntoView({behavior:'smooth'});
}

function resetForm() {
    previewRows = [];
    document.getElementById('excel_file').value = '';
    document.getElementById('previewSection').style.display  = 'none';
    document.getElementById('progressSection').style.display = 'none';
    document.getElementById('resultSection').style.display   = 'none';
    document.getElementById('importBtn').disabled = false;
    hideError();
    document.getElementById('uploadCard').scrollIntoView({behavior:'smooth'});
}
</script>

<?php include 'footer.php'; ?>