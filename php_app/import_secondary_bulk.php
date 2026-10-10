<?php
include 'config.php';
include 'header.php';
?>

<style>
/* ── Layout & Cards ─────────────────────────────────────────────────────── */
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:16px;color:#1f2937;display:flex;align-items:center;gap:8px}

/* ── Buttons ────────────────────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all .3s;font-family:'Inter',sans-serif;text-decoration:none}
.btn:disabled{opacity:.5;cursor:not-allowed}
.btn-primary{background:#7c3aed;color:#fff}
.btn-primary:hover:not(:disabled){background:#6d28d9}
.btn-success{background:#16a34a;color:#fff}
.btn-success:hover:not(:disabled){background:#15803d}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}
.btn-danger{background:#ef4444;color:#fff}
.btn-danger:hover:not(:disabled){background:#dc2626}
.btn-sm{padding:6px 12px;font-size:12px}

/* ── Upload Zone ────────────────────────────────────────────────────────── */
.upload-zone{border:2px dashed #d1d5db;border-radius:8px;padding:40px;text-align:center;cursor:pointer;transition:all .3s;background:#fafafa}
.upload-zone:hover,.upload-zone.dragover{border-color:#7c3aed;background:#f5f3ff}
.upload-zone i{font-size:48px;color:#7c3aed;margin-bottom:12px;display:block}
.upload-zone p{color:#6b7280;margin:0}
.upload-zone strong{color:#1f2937}

/* ── Summary Groups ─────────────────────────────────────────────────────── */
.date-group{border:1px solid #e5e5e5;border-radius:8px;margin-bottom:20px;overflow:hidden}
.date-group-header{background:linear-gradient(135deg,#f5f3ff,#ede9fe);padding:14px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e5e5e5;flex-wrap:wrap;gap:10px}
.date-group-title{font-size:15px;font-weight:700;color:#5b21b6;display:flex;align-items:center;gap:8px}
.date-group-body{padding:16px 20px}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:14px}
.summary-box{padding:12px 16px;background:#f9fafb;border-radius:6px;border:1px solid #e5e5e5}
.summary-label{font-size:11px;color:#6b7280;margin-bottom:4px}
.summary-value{font-size:18px;font-weight:700;color:#1f2937}
.summary-value.green{color:#16a34a}
.summary-value.red{color:#ef4444}
.summary-value.purple{color:#7c3aed}
.existing-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.new-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}

/* ── Collapsible Records Table ──────────────────────────────────────────── */
.records-toggle{background:none;border:none;cursor:pointer;color:#7c3aed;font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;padding:0;margin-bottom:12px}
.records-section{display:none}
.records-section.open{display:block}
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:12px}
.data-table thead{background:#fafafa;border-bottom:2px solid #e5e5e5}
.data-table th{padding:10px;text-align:left;font-weight:600;color:#333;font-size:11px;white-space:nowrap}
.data-table tbody tr{border-bottom:1px solid #f0f0f0}
.data-table tbody tr:hover{background:#fafafa}
.data-table td{padding:10px;color:#333;white-space:nowrap}
.data-table tr.row-duplicate{background:#fef3c7}
.data-table tr.row-new{background:#f0fdf4}

/* ── Badges ─────────────────────────────────────────────────────────────── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:12px;font-size:10px;font-weight:600}
.badge-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.badge-warning{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.badge-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.badge-inactive{background:#fafafa;color:#666;border:1px solid #e5e5e5}
.badge-purple{background:#f5f3ff;color:#5b21b6;border:1px solid #ddd6fe}
.validation-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 6px;border-radius:10px;font-size:10px;font-weight:600;margin-left:4px;background:#f0fdf4;color:#166534}

/* ── Alerts ─────────────────────────────────────────────────────────────── */
.alert{padding:14px 18px;border-radius:6px;margin-bottom:16px;font-size:13px;display:flex;align-items:flex-start;gap:10px}
.alert-info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.alert-warning{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.alert-danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}

/* ── Progress ───────────────────────────────────────────────────────────── */
.progress-wrap{background:#f3f4f6;border-radius:99px;height:8px;overflow:hidden;margin-top:8px}
.progress-bar{height:100%;background:#7c3aed;border-radius:99px;transition:width .4s ease}

/* ── Steps ──────────────────────────────────────────────────────────────── */
.step-indicator{display:flex;align-items:center;gap:0;margin-bottom:24px}
.step{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#9ca3af}
.step.active{color:#7c3aed}
.step.done{color:#16a34a}
.step-dot{width:28px;height:28px;border-radius:50%;border:2px solid currentColor;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0}
.step-line{flex:1;height:2px;background:#e5e5e5;margin:0 8px}
.step.done .step-line{background:#16a34a}

/* ── Loading overlay ────────────────────────────────────────────────────── */
#loading-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:9999;align-items:center;justify-content:center}
#loading-overlay.show{display:flex}
.loading-box{background:#fff;border-radius:12px;padding:32px 40px;text-align:center;min-width:260px}
.spinner{width:48px;height:48px;border:4px solid #e5e5e5;border-top-color:#7c3aed;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 16px}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Import result summary ──────────────────────────────────────────────── */
.result-card{background:linear-gradient(135deg,#f5f3ff,#ede9fe);border:1px solid #ddd6fe;border-radius:8px;padding:20px;margin-bottom:16px}
</style>

<!-- Loading Overlay -->
<div id="loading-overlay">
    <div class="loading-box">
        <div class="spinner"></div>
        <div id="loading-text" style="font-weight:600;color:#1f2937;font-size:15px;">Processing…</div>
        <div style="margin-top:12px;">
            <div class="progress-wrap" style="width:200px;margin:0 auto;">
                <div class="progress-bar" id="progress-bar" style="width:0%"></div>
            </div>
        </div>
    </div>
</div>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-file-import" style="color:#7c3aed;"></i> Bulk Secondary Invoice Import
            </h2>
            <p class="page-subtitle">Upload Excel — delivery dates are read directly from the file. Multiple dates supported in one upload.</p>
        </div>
        <a href="secondary_import_history.php" class="btn btn-secondary">
            <i class="fa-solid fa-history"></i> Import History
        </a>
    </div>
</div>

<!-- Step Indicator -->
<div class="step-indicator">
    <div class="step active" id="step1-ind">
        <div class="step-dot">1</div>
        <span>Upload</span>
        <div class="step-line"></div>
    </div>
    <div class="step" id="step2-ind">
        <div class="step-dot">2</div>
        <span>Review</span>
        <div class="step-line"></div>
    </div>
    <div class="step" id="step3-ind">
        <div class="step-dot">3</div>
        <span>Import</span>
    </div>
</div>

<!-- ── STEP 1: Upload ──────────────────────────────────────────────────────── -->
<div id="step-upload">
    <div class="content-card">
        <h3 class="card-title"><i class="fa-solid fa-upload" style="color:#7c3aed;"></i> Upload Excel File</h3>

        <div class="alert alert-info">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <strong>Auto Date Detection:</strong> No need to select a delivery date manually.
                The delivery date is read from <strong>Column 8 (Bill Date)</strong> of each row in the Excel file.
                Rows with different dates are grouped and handled separately — existing date groups get new rows added, new dates create a fresh import summary.
            </div>
        </div>

        <div class="upload-zone" id="upload-zone" onclick="document.getElementById('file-input').click()">
            <i class="fa-solid fa-file-excel"></i>
            <p><strong>Click to browse</strong> or drag & drop your Excel file here</p>
            <p style="font-size:12px;margin-top:6px;">Supported: .xlsx, .xls</p>
        </div>
        <input type="file" id="file-input" accept=".xlsx,.xls" style="display:none">

        <div id="file-info" style="display:none;margin-top:16px;" class="alert alert-success">
            <i class="fa-solid fa-file-circle-check"></i>
            <div>
                <strong id="file-name"></strong>
                <span id="file-size" style="color:#6b7280;margin-left:8px;"></span>
            </div>
        </div>

        <div style="margin-top:20px;display:flex;gap:12px;">
            <button class="btn btn-primary" id="btn-validate" disabled onclick="validateFile()">
                <i class="fa-solid fa-magnifying-glass"></i> Validate & Preview
            </button>
            <button class="btn btn-secondary" id="btn-reset-upload" style="display:none" onclick="resetAll()">
                <i class="fa-solid fa-rotate-left"></i> Reset
            </button>
        </div>
    </div>
</div>

<!-- ── STEP 2: Review ──────────────────────────────────────────────────────── -->
<div id="step-review" style="display:none;">

    <div id="review-alerts"></div>

    <!-- Overall totals bar -->
    <div class="content-card">
        <h3 class="card-title"><i class="fa-solid fa-chart-bar" style="color:#7c3aed;"></i> Overall Summary</h3>
        <div class="summary-grid" id="overall-summary-grid"></div>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:4px;">
            <button class="btn btn-success" id="btn-import-all" onclick="importAll()">
                <i class="fa-solid fa-file-import"></i> Import All Date Groups
            </button>
            <button class="btn btn-secondary" onclick="resetAll()">
                <i class="fa-solid fa-rotate-left"></i> Upload Different File
            </button>
        </div>
    </div>

    <!-- Per-date group cards -->
    <div id="date-groups-container"></div>
</div>

<!-- ── STEP 3: Results ─────────────────────────────────────────────────────── -->
<div id="step-results" style="display:none;">
    <div class="content-card">
        <h3 class="card-title"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Import Complete</h3>
        <div id="results-container"></div>
        <div style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap;">
            <a href="secondary_import_history.php" class="btn btn-primary">
                <i class="fa-solid fa-history"></i> View Import History
            </a>
            <button class="btn btn-secondary" onclick="resetAll()">
                <i class="fa-solid fa-plus"></i> Import Another File
            </button>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
/* ═══════════════════════════════════════════════════════════════════════════
   STATE
═══════════════════════════════════════════════════════════════════════════ */
let parsedRows    = [];  // raw rows from Excel
let groupedData   = {};  // { "YYYY-MM-DD": { rows[], validated[], existing_import_id, ... } }
let validatedGroups = {}; // after server validation per date

/* ═══════════════════════════════════════════════════════════════════════════
   UPLOAD & PARSE
═══════════════════════════════════════════════════════════════════════════ */
const uploadZone = document.getElementById('upload-zone');
const fileInput  = document.getElementById('file-input');

uploadZone.addEventListener('dragover', e => { e.preventDefault(); uploadZone.classList.add('dragover'); });
uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('dragover'));
uploadZone.addEventListener('drop', e => {
    e.preventDefault();
    uploadZone.classList.remove('dragover');
    if (e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]);
});
fileInput.addEventListener('change', () => { if (fileInput.files[0]) handleFile(fileInput.files[0]); });

function handleFile(file) {
    if (!/\.(xlsx|xls)$/i.test(file.name)) {
        alert('Please upload an Excel file (.xlsx or .xls)');
        return;
    }
    document.getElementById('file-name').textContent = file.name;
    document.getElementById('file-size').textContent = formatBytes(file.size);
    document.getElementById('file-info').style.display = 'flex';
    document.getElementById('btn-validate').disabled = false;
    document.getElementById('btn-reset-upload').style.display = 'inline-flex';

    const reader = new FileReader();
    reader.onload = e => {
        try {
            const wb   = XLSX.read(e.target.result, { type: 'array', cellDates: false });
            const ws   = wb.Sheets[wb.SheetNames[0]];
            const data = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
            parsedRows = data.filter(r => r.some(c => c !== ''));
        } catch(err) {
            alert('Error reading Excel: ' + err.message);
        }
    };
    reader.readAsArrayBuffer(file);
}

/* ═══════════════════════════════════════════════════════════════════════════
   EXCEL DATE HELPER (mirrors PHP convertExcelDate)
═══════════════════════════════════════════════════════════════════════════ */
function excelDateToYMD(val) {
    if (!val && val !== 0) return null;
    if (!isNaN(val)) {
        const epoch = (parseInt(val) - 25569) * 86400 * 1000;
        const d = new Date(epoch);
        if (isNaN(d)) return null;
        return d.toISOString().slice(0,10);
    }
    // Already a date string
    const d = new Date(val);
    if (isNaN(d)) return null;
    return d.toISOString().slice(0,10);
}

/* ═══════════════════════════════════════════════════════════════════════════
   COLUMN MAPPING
   Based on existing code: 0-indexed columns from the Excel sheet.
   Col 2 (idx 1)=Sales Code, Col 4 (idx 3)=T-Code, Col 5 (idx 4)=Route,
   Col 6 (idx 5)=Bill No, Col 8 (idx 7)=Bill Date, Col 9 (idx 8)=Outlet,
   Col 10 (idx 9)=Party Name, Col 12 (idx 11)=Free Qty, Col 13 (idx 12)=Gross,
   Col 14 (idx 13)=Scheme Disc, Col 15 (idx 14)=RS Disc, Col 16 (idx 15)=TOT,
   Col 17 (idx 16)=Total Disc, Col 20 (idx 19)=Good Returns,
   Col 21 (idx 20)=Dmg/Expiry, Col 22 (idx 21)=Final Bill Amt,
   Col 24 (idx 23)=Delivery Person
═══════════════════════════════════════════════════════════════════════════ */
const COL = {
    sales_person_code: 1,
    t_code:            3,
    route_code:        4,
    bill_no:           5,
    bill_date:         7,
    outlet_code:       8,
    party_name:        9,
    free_qty:          11,
    gross_sales:       12,
    scheme_disc:       13,
    rs_discount:       14,
    tot_disc:          15,
    total_discount:    16,
    good_returns_value: 19,
    damage_expiry_shortage_value: 20,
    final_bill_amount: 21,
    delivery_person:   23,
};

function mapRowToRecord(row) {
    const get = idx => (row[idx] !== undefined && row[idx] !== null) ? String(row[idx]).trim() : '';
    return {
        'Sales Person Code':            get(COL.sales_person_code),
        'T-Code':                       get(COL.t_code),
        'Route':                        get(COL.route_code),
        'Bill No':                      get(COL.bill_no),
        'Bill Date':                    row[COL.bill_date] ?? '',
        'Outlet Code':                  get(COL.outlet_code),
        'Party Name':                   get(COL.party_name),
        'Free Qty':                     parseFloat(row[COL.free_qty])          || 0,
        'Gross Sales':                  parseFloat(row[COL.gross_sales])        || 0,
        'Scheme Disc':                  parseFloat(row[COL.scheme_disc])        || 0,
        'RS Discount':                  parseFloat(row[COL.rs_discount])        || 0,
        'TOT Disc':                     parseFloat(row[COL.tot_disc])           || 0,
        'Total Discount':               parseFloat(row[COL.total_discount])     || 0,
        'Good Returns Value':           parseFloat(row[COL.good_returns_value]) || 0,
        'Damage-Expiry Shortage Value': parseFloat(row[COL.damage_expiry_shortage_value]) || 0,
        'Final Bill Amount':            parseFloat(row[COL.final_bill_amount])  || 0,
        'Delivery Person':              get(COL.delivery_person),
    };
}

/* ═══════════════════════════════════════════════════════════════════════════
   STEP 2: VALIDATE
═══════════════════════════════════════════════════════════════════════════ */
async function validateFile() {
    if (!parsedRows.length) { alert('File not loaded yet.'); return; }

    // Skip header rows — skip any row where the bill_date cell is non-numeric/non-date
    // and bill_no looks like a heading
    const dataRows = parsedRows.filter(row => {
        const billDate = row[COL.bill_date];
        const billNo   = row[COL.bill_no];
        if (!billNo && !billDate) return false;
        if (String(billDate).toLowerCase().includes('date')) return false;
        if (String(billNo).toLowerCase().includes('bill')) return false;
        return true;
    });

    if (!dataRows.length) { alert('No valid data rows found in the file.'); return; }

    // Group by delivery date
    groupedData = {};
    for (const row of dataRows) {
        const rawDate = row[COL.bill_date];
        const ymd     = excelDateToYMD(rawDate);
        if (!ymd) continue; // skip rows without valid date
        if (!groupedData[ymd]) groupedData[ymd] = [];
        groupedData[ymd].push(mapRowToRecord(row));
    }

    const dates = Object.keys(groupedData).sort();
    if (!dates.length) {
        alert('No rows with valid Bill Date found. Please check Column 8 contains dates.');
        return;
    }

    showLoading('Validating ' + dates.length + ' date group(s)…', 10);
    document.getElementById('btn-validate').disabled = true;

    validatedGroups = {};
    const total = dates.length;
    let done    = 0;

    for (const date of dates) {
        setLoadingText(`Validating ${date} (${done+1}/${total})…`);
        setProgress(Math.round((done / total) * 80) + 10);

        try {
            const fd = new FormData();
            fd.append('data', JSON.stringify(groupedData[date]));
            fd.append('delivery_date', date);
            const res = await fetch('validate_import_data.php', { method:'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                validatedGroups[date] = json;
            } else {
                validatedGroups[date] = { success:false, error: json.message, data:[], total:0, valid:0, invalid:0 };
            }
        } catch(err) {
            validatedGroups[date] = { success:false, error: err.message, data:[], total:0, valid:0, invalid:0 };
        }
        done++;
    }

    setProgress(100);
    hideLoading();
    renderReview(dates);
}

/* ═══════════════════════════════════════════════════════════════════════════
   RENDER REVIEW (Step 2)
═══════════════════════════════════════════════════════════════════════════ */
function renderReview(dates) {
    // Step indicators
    setStep(2);

    document.getElementById('step-upload').style.display  = 'none';
    document.getElementById('step-review').style.display  = 'block';
    document.getElementById('step-results').style.display = 'none';

    // Overall totals
    let totalRows = 0, totalValid = 0, totalInvalid = 0, totalDupes = 0, totalDates = dates.length;
    let existingDates = 0, newDates = 0;
    for (const d of dates) {
        const vg = validatedGroups[d];
        totalRows   += vg.total   || 0;
        totalValid  += vg.valid   || 0;
        totalInvalid+= vg.invalid || 0;
        totalDupes  += vg.duplicate_count || 0;
        if (vg.has_same_date_import) existingDates++; else newDates++;
    }
    document.getElementById('overall-summary-grid').innerHTML = `
        <div class="summary-box"><div class="summary-label">Date Groups</div><div class="summary-value purple">${totalDates}</div></div>
        <div class="summary-box"><div class="summary-label">Existing Groups</div><div class="summary-value" style="color:#92400e;">${existingDates}</div></div>
        <div class="summary-box"><div class="summary-label">New Groups</div><div class="summary-value green">${newDates}</div></div>
        <div class="summary-box"><div class="summary-label">Total Rows</div><div class="summary-value">${totalRows}</div></div>
        <div class="summary-box"><div class="summary-label">Valid</div><div class="summary-value green">${totalValid}</div></div>
        <div class="summary-box"><div class="summary-label">Issues</div><div class="summary-value red">${totalInvalid}</div></div>
        <div class="summary-box"><div class="summary-label">Cross-Date Dupes</div><div class="summary-value" style="color:#d97706;">${totalDupes}</div></div>
    `;

    // Per-date groups
    const container = document.getElementById('date-groups-container');
    container.innerHTML = '';
    for (const date of dates) {
        container.appendChild(buildDateGroupCard(date));
    }

    // Alerts
    let alertHtml = '';
    if (totalDupes > 0) {
        alertHtml += `<div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation"></i><div><strong>${totalDupes} cross-date duplicate bill number(s)</strong> found — these rows will be flagged but can still be imported.</div></div>`;
    }
    if (existingDates > 0) {
        alertHtml += `<div class="alert alert-warning"><i class="fa-solid fa-rotate"></i><div><strong>${existingDates} date group(s)</strong> already have an existing completed import. Importing will <strong>replace</strong> those records with the new data.</div></div>`;
    }
    document.getElementById('review-alerts').innerHTML = alertHtml;
}

function buildDateGroupCard(date) {
    const vg = validatedGroups[date];
    const isExisting = vg.has_same_date_import;
    const displayDate = formatDateDisplay(date);

    const div = document.createElement('div');
    div.className = 'date-group';
    div.id = `group-${date}`;

    const dupeCount  = vg.duplicate_count  || 0;
    const autoCount  = vg.auto_created_count || 0;

    div.innerHTML = `
        <div class="date-group-header">
            <div class="date-group-title">
                <i class="fa-solid fa-calendar-day"></i>
                ${displayDate}
                <code style="font-size:12px;background:#ede9fe;padding:2px 6px;border-radius:4px;font-weight:600;">
                    creditbill_${date.replace(/-/g,'')}
                </code>
                ${isExisting
                    ? `<span class="existing-badge"><i class="fa-solid fa-arrows-rotate"></i> Existing — will replace</span>`
                    : `<span class="new-badge"><i class="fa-solid fa-plus"></i> New Import</span>`
                }
            </div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                ${isExisting ? `<a href="secondary_invoice_view.php?id=${vg.existing_import_id}" target="_blank" class="btn btn-sm btn-secondary"><i class="fa-solid fa-eye"></i> View Existing</a>` : ''}
                <button class="btn btn-sm btn-success" onclick="importSingleDate('${date}')" id="btn-import-${date.replace(/-/g,'')}">
                    <i class="fa-solid fa-file-import"></i> Import This Date
                </button>
            </div>
        </div>
        <div class="date-group-body">
            <div class="summary-grid">
                <div class="summary-box"><div class="summary-label">Total Rows</div><div class="summary-value">${vg.total}</div></div>
                <div class="summary-box"><div class="summary-label">Valid</div><div class="summary-value green">${vg.valid}</div></div>
                <div class="summary-box"><div class="summary-label">Issues</div><div class="summary-value red">${vg.invalid}</div></div>
                <div class="summary-box"><div class="summary-label">Cross-Date Dupes</div><div class="summary-value" style="color:#d97706;">${dupeCount}</div></div>
                <div class="summary-box"><div class="summary-label">Auto-Created Customers</div><div class="summary-value purple">${autoCount}</div></div>
            </div>
            ${autoCount > 0 ? `<div class="alert alert-info" style="margin-bottom:12px;font-size:12px;"><i class="fa-solid fa-user-plus"></i><div>Auto-created T-Codes: <strong>${(vg.auto_created_tcodes||[]).join(', ')}</strong></div></div>` : ''}
            <button class="records-toggle" onclick="toggleRecords('${date}')">
                <i class="fa-solid fa-table-list"></i> Show / Hide Row Details
            </button>
            <div class="records-section" id="records-${date}">
                ${buildRecordsTable(vg.data || [])}
            </div>
            <div id="import-result-${date.replace(/-/g,'')}"></div>
        </div>
    `;
    return div;
}

function buildRecordsTable(rows) {
    if (!rows.length) return '<p style="color:#999;font-size:13px;">No records.</p>';
    let html = `
    <div class="table-responsive">
    <table class="data-table">
        <thead><tr>
            <th>#</th><th>Sales Code</th><th>T-Code</th><th>Route</th>
            <th>Bill No</th><th>Bill Date</th><th>Outlet</th><th>Party Name</th>
            <th>Gross Sales</th><th>Total Disc</th><th>Final Bill</th><th>Delivery Person</th><th>Flag</th>
        </tr></thead>
        <tbody>
    `;
    rows.forEach((r, i) => {
        const isDupe = r.is_duplicate;
        const isAuto = r.auto_created;
        const rowClass = isDupe ? 'row-duplicate' : (isAuto ? 'row-new' : '');
        html += `<tr class="${rowClass}">
            <td>${i+1}</td>
            <td>${esc(r.sales_person_code)}</td>
            <td>${esc(r.t_code)}${r.t_code_valid ? `<span class="validation-badge"><i class="fa-solid fa-check"></i> ${esc(r.customer_name)}</span>` : ''}</td>
            <td>${esc(r.route_code)}${r.route_valid ? `<span class="validation-badge"><i class="fa-solid fa-check"></i></span>` : ''}</td>
            <td>${esc(r.bill_no)}</td>
            <td>${r.bill_date ? formatDateDisplay(r.bill_date) : '-'}</td>
            <td>${esc(r.outlet_code)}</td>
            <td>${esc(r.party_name)}</td>
            <td style="text-align:right;">${fmt(r.gross_sales)}</td>
            <td style="text-align:right;">${fmt(r.total_discount)}</td>
            <td style="text-align:right;font-weight:700;color:#7c3aed;">${fmt(r.final_bill_amount)}</td>
            <td>${esc(r.delivery_person)}</td>
            <td>${isDupe ? '<span class="badge badge-warning">Dupe</span>' : (isAuto ? '<span class="badge badge-purple">New Cust</span>' : '<span class="badge badge-success">OK</span>')}</td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    return html;
}

function toggleRecords(date) {
    const el = document.getElementById(`records-${date}`);
    el.classList.toggle('open');
}

/* ═══════════════════════════════════════════════════════════════════════════
   IMPORT
═══════════════════════════════════════════════════════════════════════════ */
async function importAll() {
    const dates = Object.keys(validatedGroups).sort();
    showLoading('Importing all date groups…', 5);
    const results = [];
    let i = 0;
    for (const date of dates) {
        setLoadingText(`Importing ${formatDateDisplay(date)} (${i+1}/${dates.length})…`);
        setProgress(Math.round((i / dates.length) * 90) + 5);
        const r = await importDate(date);
        results.push({ date, ...r });
        updateGroupImportResult(date, r);
        i++;
    }
    setProgress(100);
    hideLoading();
    renderResults(results);
}

async function importSingleDate(date) {
    const safeId = date.replace(/-/g,'');
    document.getElementById(`btn-import-${safeId}`).disabled = true;
    showLoading(`Importing ${formatDateDisplay(date)}…`, 30);
    const r = await importDate(date);
    setProgress(100);
    hideLoading();
    updateGroupImportResult(date, r);
    renderResults([{ date, ...r }]);
}

async function importDate(date) {
    try {
        const vg = validatedGroups[date];
        const fd = new FormData();
        fd.append('validated_data',    JSON.stringify(vg.data));
        fd.append('delivery_date',     date);
        fd.append('filename',          document.getElementById('file-name').textContent);
        fd.append('existing_import_id', vg.existing_import_id || '');
        const res  = await fetch('process_secondary_import.php', { method:'POST', body: fd });
        const json = await res.json();
        return json;
    } catch(err) {
        return { success: false, message: err.message };
    }
}

function updateGroupImportResult(date, result) {
    const safeId = date.replace(/-/g,'');
    const el     = document.getElementById(`import-result-${safeId}`);
    if (!el) return;
    if (result.success) {
        el.innerHTML = `<div class="alert alert-success" style="margin-top:12px;">
            <i class="fa-solid fa-circle-check"></i>
            <div>Imported successfully — <strong>${result.imported_records || 0}</strong> records saved.
            ${result.import_id ? `<a href="secondary_invoice_view.php?id=${result.import_id}" target="_blank" style="margin-left:8px;color:#15803d;font-weight:600;"><i class="fa-solid fa-eye"></i> View</a>` : ''}
            </div></div>`;
    } else {
        el.innerHTML = `<div class="alert alert-danger" style="margin-top:12px;">
            <i class="fa-solid fa-circle-xmark"></i>
            <div>Import failed: ${esc(result.message || 'Unknown error')}</div></div>`;
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   STEP 3: RESULTS
═══════════════════════════════════════════════════════════════════════════ */
function renderResults(results) {
    setStep(3);
    document.getElementById('step-results').style.display = 'block';
    document.getElementById('step-results').scrollIntoView({ behavior:'smooth', block:'start' });

    const totalImported = results.reduce((s,r) => s + (r.imported_records||0), 0);
    const totalFailed   = results.reduce((s,r) => s + (r.failed_records||0),   0);
    const successCount  = results.filter(r => r.success).length;

    let html = `
    <div class="result-card">
        <div class="summary-grid">
            <div class="summary-box"><div class="summary-label">Date Groups Processed</div><div class="summary-value purple">${results.length}</div></div>
            <div class="summary-box"><div class="summary-label">Successful</div><div class="summary-value green">${successCount}</div></div>
            <div class="summary-box"><div class="summary-label">Failed</div><div class="summary-value red">${results.length - successCount}</div></div>
            <div class="summary-box"><div class="summary-label">Records Imported</div><div class="summary-value green">${totalImported}</div></div>
            <div class="summary-box"><div class="summary-label">Records Failed</div><div class="summary-value red">${totalFailed}</div></div>
        </div>
    </div>
    <table class="data-table" style="margin-top:8px;">
        <thead><tr>
            <th>Delivery Date</th>
            <th>Import ID / Code</th>
            <th>Imported</th>
            <th>Failed</th>
            <th>Status</th>
            <th>Action</th>
        </tr></thead>
        <tbody>`;

    for (const r of results) {
        const safeCode = `creditbill_${r.date.replace(/-/g,'')}`;
        html += `<tr>
            <td><strong>${formatDateDisplay(r.date)}</strong></td>
            <td><code style="background:#f5f3ff;padding:2px 6px;border-radius:4px;font-size:11px;">${safeCode}</code></td>
            <td class="text-success" style="color:#16a34a;font-weight:700;">${r.imported_records || 0}</td>
            <td style="color:#ef4444;font-weight:700;">${r.failed_records || 0}</td>
            <td>${r.success
                ? '<span class="badge badge-success">Completed</span>'
                : `<span class="badge badge-error">Failed</span>`}
            </td>
            <td>${r.import_id
                ? `<a href="secondary_invoice_view.php?id=${r.import_id}" class="btn btn-sm btn-secondary"><i class="fa-solid fa-eye"></i> View</a>`
                : (r.success ? '' : `<span style="font-size:11px;color:#991b1b;">${esc(r.message||'')}</span>`)
            }</td>
        </tr>`;
    }
    html += '</tbody></table>';
    document.getElementById('results-container').innerHTML = html;
}

/* ═══════════════════════════════════════════════════════════════════════════
   RESET
═══════════════════════════════════════════════════════════════════════════ */
function resetAll() {
    parsedRows     = [];
    groupedData    = {};
    validatedGroups = {};
    fileInput.value = '';
    document.getElementById('file-info').style.display      = 'none';
    document.getElementById('btn-validate').disabled        = true;
    document.getElementById('btn-reset-upload').style.display = 'none';
    document.getElementById('step-upload').style.display   = 'block';
    document.getElementById('step-review').style.display   = 'none';
    document.getElementById('step-results').style.display  = 'none';
    document.getElementById('review-alerts').innerHTML      = '';
    document.getElementById('date-groups-container').innerHTML = '';
    setStep(1);
    window.scrollTo({ top: 0, behavior:'smooth' });
}

/* ═══════════════════════════════════════════════════════════════════════════
   HELPERS
═══════════════════════════════════════════════════════════════════════════ */
function setStep(n) {
    [1,2,3].forEach(i => {
        const el = document.getElementById(`step${i}-ind`);
        el.classList.remove('active','done');
        if (i < n)  el.classList.add('done');
        if (i === n) el.classList.add('active');
    });
}

function showLoading(text, progress) {
    document.getElementById('loading-text').textContent = text;
    document.getElementById('progress-bar').style.width = progress + '%';
    document.getElementById('loading-overlay').classList.add('show');
}
function setLoadingText(t) { document.getElementById('loading-text').textContent = t; }
function setProgress(p)    { document.getElementById('progress-bar').style.width = p + '%'; }
function hideLoading()     { document.getElementById('loading-overlay').classList.remove('show'); }

function formatBytes(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
    return (b/1048576).toFixed(1) + ' MB';
}
function formatDateDisplay(ymd) {
    if (!ymd) return '-';
    const [y,m,d] = ymd.split('-');
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return `${months[parseInt(m)-1]} ${parseInt(d)}, ${y}`;
}
function fmt(v)  { return parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s)  {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}
</script>

<?php include 'footer.php'; ?>