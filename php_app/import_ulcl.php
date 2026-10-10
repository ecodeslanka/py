<?php
include 'config.php';
include 'header.php';
?>

<div class="page-header">
    <div>
        <h2 class="page-title"><i class="fa-solid fa-file-invoice-dollar"></i> Unilever Customer Ledger — Import</h2>
        <p class="page-subtitle">Upload Customer Ledger Summary Report (ULCL / USLL)</p>
    </div>
    <a href="ulcl_history.php" class="btn btn-secondary"><i class="fa-solid fa-history"></i> Import History</a>
</div>

<!-- Upload Form Card -->
<div class="content-card">
    <h3 class="card-title" style="margin-bottom:24px;"><i class="fa-solid fa-upload"></i> Upload Ledger File</h3>

    <form id="uploadForm" enctype="multipart/form-data">
        <div class="form-grid">
            <!-- Company -->
            <div class="form-group">
                <label class="form-label required">Company</label>
                <div class="select-wrap">
                    <select name="company" id="company" class="form-input" required>
                        <option value="">— Select Company —</option>
                        <option value="ULCL">ULCL — Unilever Customer Ledger</option>
                        <option value="USLL">USLL — Unilever SL Ledger</option>
                    </select>
                    <i class="fa-solid fa-chevron-down select-icon"></i>
                </div>
            </div>

            <!-- Entry Date -->
            <div class="form-group">
                <label class="form-label required">Entry Date</label>
                <input type="date" name="entry_date" id="entry_date" class="form-input"
                       value="<?= date('Y-m-d') ?>" required>
            </div>

            <!-- File Upload -->
            <div class="form-group full-width">
                <label class="form-label required">Excel File (.xlsx)</label>
                <div class="file-drop-zone" id="dropZone">
                    <input type="file" name="ledger_file" id="ledger_file"
                           accept=".xlsx,.xls" class="file-input" required>
                    <div class="drop-inner" id="dropInner">
                        <i class="fa-solid fa-file-excel" style="font-size:36px;color:#166534;"></i>
                        <p class="drop-title">Drag & drop your Excel file here</p>
                        <p class="drop-sub">or <span class="drop-link">browse file</span></p>
                        <p class="drop-hint">Supported: .xlsx, .xls — Customer Ledger Summary Report</p>
                    </div>
                    <div class="file-selected" id="fileSelected" style="display:none;">
                        <i class="fa-solid fa-file-excel" style="color:#166534;font-size:28px;"></i>
                        <div>
                            <div class="file-name" id="fileName"></div>
                            <div class="file-size" id="fileSize"></div>
                        </div>
                        <button type="button" class="btn-remove-file" id="removeFile" title="Remove">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Preview / column info -->
        <div id="previewSection" style="display:none;margin-top:20px;">
            <div class="preview-header">
                <i class="fa-solid fa-table"></i> File Preview
                <span id="previewCount" class="record-count"></span>
            </div>
            <div class="table-responsive">
                <table class="data-table" id="previewTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Transaction Type</th>
                            <th>Customer Reference</th>
                            <th>Debit</th>
                            <th>Credit</th>
                            <th>Balance</th>
                        </tr>
                    </thead>
                    <tbody id="previewBody"></tbody>
                </table>
            </div>
        </div>

        <!-- Submit -->
        <div class="form-actions">
            <button type="button" class="btn btn-secondary" onclick="resetForm()">
                <i class="fa-solid fa-rotate-left"></i> Reset
            </button>
            <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                <i class="fa-solid fa-cloud-upload-alt"></i> Import to Database
            </button>
        </div>
    </form>
</div>

<!-- Progress Card -->
<div class="content-card" id="progressCard" style="display:none;">
    <h3 class="card-title" style="margin-bottom:20px;"><i class="fa-solid fa-spinner fa-spin"></i> Importing…</h3>
    <div class="progress-bar-wrap">
        <div class="progress-bar" id="progressBar" style="width:0%"></div>
    </div>
    <div id="progressText" style="font-size:13px;color:#6b7280;margin-top:10px;text-align:center;">Starting…</div>
</div>

<!-- Result Card -->
<div class="content-card" id="resultCard" style="display:none;">
    <h3 class="card-title" style="margin-bottom:20px;"><i class="fa-solid fa-circle-check"></i> Import Result</h3>
    <div id="resultContent"></div>
</div>

<!-- How-to card -->
<div class="content-card info-card">
    <h3 class="card-title"><i class="fa-solid fa-circle-info"></i> Expected Excel Format</h3>
    <p style="font-size:13px;color:#6b7280;margin:12px 0 0;">The file must have the sheet <strong>"SAP Document Export"</strong> with these columns starting from row 1:</p>
    <div class="col-pills">
        <span class="col-pill"><i class="fa-solid fa-calendar-day"></i> Date</span>
        <span class="col-pill"><i class="fa-solid fa-tag"></i> Transaction Type</span>
        <span class="col-pill"><i class="fa-solid fa-hashtag"></i> Customer Reference</span>
        <span class="col-pill c-debit"><i class="fa-solid fa-arrow-up-right"></i> Debit</span>
        <span class="col-pill c-credit"><i class="fa-solid fa-arrow-down-left"></i> Credit</span>
        <span class="col-pill c-bal"><i class="fa-solid fa-scale-balanced"></i> Balance</span>
    </div>
    <p style="font-size:12px;color:#9ca3af;margin-top:12px;">
        <i class="fa-solid fa-shield-halved" style="color:#22c55e;"></i>
        Duplicate rows (same company + entry date + txn date + type + reference + debit + credit) are automatically skipped.
    </p>
</div>

<style>
.page-header{margin-bottom:24px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px}
.page-title{font-size:22px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:10px;margin:0 0 6px}
.page-subtitle{color:#6b7280;font-size:14px;margin:0}
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:24px;margin-bottom:20px}
.info-card{border-left:4px solid #3b82f6;background:#f0f7ff}
.card-title{font-size:16px;font-weight:600;color:#1f2937;display:flex;align-items:center;gap:8px;margin:0}
.record-count{background:#eff6ff;color:#1e40af;font-size:11px;padding:2px 8px;border-radius:99px;font-weight:700;margin-left:6px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.full-width{grid-column:1/-1}
.form-group{display:flex;flex-direction:column}
.form-label{font-size:13px;font-weight:600;color:#374151;margin-bottom:7px}
.form-label.required::after{content:" *";color:#ef4444}
.form-input{padding:10px 14px;border:1px solid #e5e5e5;border-radius:8px;font-size:14px;font-family:inherit;transition:border-color .2s;background:#fff}
.form-input:focus{outline:none;border-color:#000}
.select-wrap{position:relative}
.select-wrap select{appearance:none;width:100%;padding-right:36px}
.select-icon{position:absolute;right:12px;top:50%;transform:translateY(-50%);pointer-events:none;color:#9ca3af;font-size:12px}
/* File drop zone */
.file-drop-zone{border:2px dashed #d1d5db;border-radius:10px;position:relative;transition:all .2s;background:#fafafa}
.file-drop-zone.drag-over{border-color:#000;background:#f5f5f5}
.file-input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.drop-inner{display:flex;flex-direction:column;align-items:center;padding:36px 20px;gap:8px;pointer-events:none}
.drop-title{font-size:15px;font-weight:600;color:#374151;margin:8px 0 0}
.drop-sub{font-size:13px;color:#6b7280;margin:0}
.drop-link{color:#000;font-weight:600;text-decoration:underline}
.drop-hint{font-size:11px;color:#9ca3af;margin:0}
.file-selected{display:flex;align-items:center;gap:14px;padding:20px 24px}
.file-name{font-size:14px;font-weight:600;color:#1f2937}
.file-size{font-size:12px;color:#6b7280;margin-top:3px}
.btn-remove-file{margin-left:auto;background:none;border:1px solid #e5e5e5;border-radius:6px;width:28px;height:28px;cursor:pointer;color:#ef4444;display:flex;align-items:center;justify-content:center;font-size:13px}
/* Form actions */
.form-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:24px;padding-top:20px;border-top:1px solid #f0f0f0}
/* Preview */
.preview-header{font-size:14px;font-weight:600;color:#374151;display:flex;align-items:center;gap:8px;margin-bottom:12px}
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead th{background:#fafafa;padding:10px 12px;text-align:left;font-size:12px;font-weight:700;color:#374151;border-bottom:2px solid #e5e5e5;white-space:nowrap}
.data-table tbody tr{border-bottom:1px solid #f0f0f0}
.data-table tbody tr:hover{background:#fafafa}
.data-table td{padding:10px 12px;color:#333;vertical-align:middle;white-space:nowrap}
.td-num{text-align:right;font-variant-numeric:tabular-nums;font-weight:600}
.td-debit{color:#991b1b}
.td-credit{color:#166534}
.td-bal{color:#1e40af}
/* Progress */
.progress-bar-wrap{background:#f0f0f0;border-radius:99px;height:10px;overflow:hidden}
.progress-bar{height:100%;background:#000;border-radius:99px;transition:width .3s}
/* Result stats */
.result-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}
.rs-card{border-radius:8px;padding:14px 16px;text-align:center}
.rs-total{background:#eff6ff;border:1px solid #bfdbfe}
.rs-imported{background:#f0fdf4;border:1px solid #bbf7d0}
.rs-dup{background:#fef3c7;border:1px solid #fde68a}
.rs-failed{background:#fef2f2;border:1px solid #fecaca}
.rs-num{font-size:24px;font-weight:700;color:#1f2937}
.rs-label{font-size:11px;color:#6b7280;margin-top:4px}
/* Column pills */
.col-pills{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.col-pill{background:#f5f5f5;border:1px solid #e5e5e5;border-radius:6px;padding:5px 12px;font-size:12px;font-weight:600;color:#374151;display:flex;align-items:center;gap:6px}
.c-debit{background:#fef2f2;border-color:#fecaca;color:#991b1b}
.c-credit{background:#f0fdf4;border-color:#bbf7d0;color:#166534}
.c-bal{background:#eff6ff;border-color:#bfdbfe;color:#1e40af}
/* Buttons */
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s}
.btn:disabled{opacity:.5;cursor:not-allowed}
.btn-primary{background:#000;color:#fff}
.btn-primary:hover:not(:disabled){background:#333}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}
@media(max-width:768px){.form-grid{grid-template-columns:1fr}.result-stats{grid-template-columns:1fr 1fr}.page-header{flex-direction:column}}
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
const dropZone   = document.getElementById('dropZone');
const fileInput  = document.getElementById('ledger_file');
const dropInner  = document.getElementById('dropInner');
const fileSelected = document.getElementById('fileSelected');
const fileName   = document.getElementById('fileName');
const fileSize   = document.getElementById('fileSize');
const removeFile = document.getElementById('removeFile');
const submitBtn  = document.getElementById('submitBtn');
const previewSection = document.getElementById('previewSection');
const previewBody    = document.getElementById('previewBody');
const previewCount   = document.getElementById('previewCount');
let parsedRows = [];

// Drag & Drop
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
    e.preventDefault(); dropZone.classList.remove('drag-over');
    if (e.dataTransfer.files.length) handleFile(e.dataTransfer.files[0]);
});
fileInput.addEventListener('change', () => { if (fileInput.files.length) handleFile(fileInput.files[0]); });
removeFile.addEventListener('click', resetForm);

function fmtBytes(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
    return (b/1048576).toFixed(2) + ' MB';
}

function handleFile(file) {
    if (!file.name.match(/\.(xlsx|xls)$/i)) {
        alert('Please upload an Excel file (.xlsx or .xls)');
        return;
    }
    fileName.textContent = file.name;
    fileSize.textContent = fmtBytes(file.size);
    dropInner.style.display = 'none';
    fileSelected.style.display = 'flex';

    // Parse preview with SheetJS
    const reader = new FileReader();
    reader.onload = e => {
        const wb = XLSX.read(e.target.result, { type: 'array', cellDates: true });
        const sheetName = wb.SheetNames[0];
        const ws = wb.Sheets[sheetName];
        const raw = XLSX.utils.sheet_to_json(ws, { header: 1, raw: false, dateNF: 'dd/mm/yy' });

        // Skip header row (row 0 = headers)
        parsedRows = [];
        for (let i = 1; i < raw.length; i++) {
            const r = raw[i];
            if (!r || r.length < 4) continue;
            parsedRows.push({
                txn_date:   r[0] || '',
                txn_type:   r[1] || '',
                cust_ref:   r[2] || '',
                debit:      r[3] || '0',
                credit:     r[4] || '0',
                balance:    r[5] || '0'
            });
        }

        // Build preview (max 10 rows)
        previewBody.innerHTML = '';
        const previewRows = parsedRows.slice(0, 10);
        previewRows.forEach((row, idx) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="color:#9ca3af;font-size:11px;">${idx + 1}</td>
                <td>${esc(row.txn_date)}</td>
                <td><span style="font-size:12px;">${esc(row.txn_type)}</span></td>
                <td style="font-family:monospace;font-size:12px;">${esc(row.cust_ref)}</td>
                <td class="td-num td-debit">${fmtNum(row.debit)}</td>
                <td class="td-num td-credit">${fmtNum(row.credit)}</td>
                <td class="td-num td-bal">${fmtNum(row.balance)}</td>`;
            previewBody.appendChild(tr);
        });

        if (parsedRows.length > 10) {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td colspan="7" style="text-align:center;color:#9ca3af;font-size:12px;padding:10px;">
                … and ${parsedRows.length - 10} more rows</td>`;
            previewBody.appendChild(tr);
        }

        previewCount.textContent = parsedRows.length + ' rows';
        previewSection.style.display = 'block';
        submitBtn.disabled = false;
    };
    reader.readAsArrayBuffer(file);
}

function esc(s) {
    if (!s) return '<span style="color:#ccc">—</span>';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function fmtNum(v) {
    const n = parseFloat(String(v).replace(/,/g,''));
    if (isNaN(n) || n === 0) return '<span style="color:#ccc">0</span>';
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function resetForm() {
    fileInput.value = '';
    dropInner.style.display = 'flex';
    fileSelected.style.display = 'none';
    previewSection.style.display = 'none';
    previewBody.innerHTML = '';
    parsedRows = [];
    submitBtn.disabled = true;
    document.getElementById('resultCard').style.display = 'none';
}

// Submit
document.getElementById('uploadForm').addEventListener('submit', async e => {
    e.preventDefault();
    const company    = document.getElementById('company').value;
    const entry_date = document.getElementById('entry_date').value;
    if (!company)    { alert('Please select a company.'); return; }
    if (!entry_date) { alert('Please select an entry date.'); return; }
    if (!fileInput.files.length) { alert('Please select a file.'); return; }

    // Show progress
    submitBtn.disabled = true;
    document.getElementById('progressCard').style.display = 'block';
    document.getElementById('resultCard').style.display   = 'none';
    setProgress(10, 'Preparing data…');

    const formData = new FormData();
    formData.append('company',    company);
    formData.append('entry_date', entry_date);
    formData.append('ledger_file', fileInput.files[0]);

    setProgress(30, 'Uploading file…');

    try {
        const resp = await fetch('ulcl_import_ajax.php', { method: 'POST', body: formData });
        setProgress(80, 'Processing rows…');
        const data = await resp.json();
        setProgress(100, 'Done!');

        setTimeout(() => {
            document.getElementById('progressCard').style.display = 'none';
            showResult(data);
            submitBtn.disabled = false;
        }, 500);
    } catch (err) {
        setProgress(0, 'Error!');
        alert('Import failed: ' + err.message);
        submitBtn.disabled = false;
    }
});

function setProgress(pct, msg) {
    document.getElementById('progressBar').style.width = pct + '%';
    document.getElementById('progressText').textContent = msg;
}

function showResult(data) {
    const rc = document.getElementById('resultCard');
    const ct = document.getElementById('resultContent');
    rc.style.display = 'block';

    if (!data.success) {
        ct.innerHTML = `<div class="alert-error" style="padding:14px;border-radius:8px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;">
            <i class="fa-solid fa-circle-xmark"></i> ${esc(data.message || 'Import failed.')}
        </div>`;
        return;
    }

    ct.innerHTML = `
        <div class="result-stats">
            <div class="rs-card rs-total">
                <div class="rs-num">${data.total}</div>
                <div class="rs-label"><i class="fa-solid fa-list"></i> Total Rows</div>
            </div>
            <div class="rs-card rs-imported">
                <div class="rs-num" style="color:#166534;">${data.imported}</div>
                <div class="rs-label"><i class="fa-solid fa-circle-check"></i> Imported</div>
            </div>
            <div class="rs-card rs-dup">
                <div class="rs-num" style="color:#92400e;">${data.duplicates}</div>
                <div class="rs-label"><i class="fa-solid fa-copy"></i> Duplicates Skipped</div>
            </div>
            <div class="rs-card rs-failed">
                <div class="rs-num" style="color:#991b1b;">${data.failed}</div>
                <div class="rs-label"><i class="fa-solid fa-circle-xmark"></i> Failed</div>
            </div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="ulcl_view.php?id=${data.import_id}" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-eye"></i> View Imported Records
            </a>
            <a href="ulcl_history.php" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-history"></i> Import History
            </a>
        </div>
        ${data.duplicates > 0 ? `<p style="margin-top:12px;font-size:12px;color:#92400e;"><i class="fa-solid fa-info-circle"></i> ${data.duplicates} duplicate row(s) were detected and skipped — they already exist in the database for this company and entry date.</p>` : ''}
        ${data.errors && data.errors.length ? `<div style="margin-top:12px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px;">
            <strong style="font-size:12px;color:#991b1b;">Errors:</strong>
            <ul style="margin:8px 0 0;padding-left:18px;font-size:12px;color:#991b1b;">${data.errors.map(e => '<li>'+esc(e)+'</li>').join('')}</ul>
        </div>` : ''}`;
}

// Alert styles inline
const style = document.createElement('style');
style.textContent = `.btn-sm{padding:8px 14px;font-size:13px;}`;
document.head.appendChild(style);
</script>

<?php include 'footer.php'; ?>
