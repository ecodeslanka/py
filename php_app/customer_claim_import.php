<?php
/**
 * customer_claim_import.php
 * Import Customer Claim Certificates from Excel
 * Columns: No, Ledger Type, Status, Claim Type, Tax Invoice Number,
 *          Invoice Date, Banking Date, Entity, Claim Description,
 *          Actual Amount, VAT Amount
 * Extra:   scheme_code extracted from Claim Description (first segment before '-' if numeric)
 */
ob_start();
include 'config.php';
ob_end_clean();
include 'header.php';
?>

<style>
:root{--teal:#1a7f8e;--teal2:#155f6c;--green:#16a34a;--red:#dc2626;--amber:#d97706;--gray1:#f8fafc;--gray2:#e2e8f0;--gray3:#94a3b8;--shadow:0 2px 8px rgba(0,0,0,.10);}

.cci-header{background:linear-gradient(135deg,var(--teal),var(--teal2));color:#fff;padding:22px 28px;border-radius:10px;margin-bottom:22px;}
.cci-header h2{margin:0 0 4px;font-size:1.5rem;}
.cci-header p{margin:0;opacity:.85;font-size:.9rem;}

/* Upload zone */
.upload-card{background:#fff;border:1px solid var(--gray2);border-radius:10px;padding:28px 32px;margin-bottom:20px;box-shadow:var(--shadow);}
.upload-card h3{margin:0 0 18px;font-size:1rem;color:#1e293b;display:flex;align-items:center;gap:8px;}
.drop-zone{border:2px dashed var(--gray2);border-radius:10px;padding:36px;text-align:center;cursor:pointer;transition:all .2s;background:#f8fafc;position:relative;}
.drop-zone:hover,.drop-zone.drag-over{border-color:var(--teal);background:#f0fdfa;}
.drop-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;}
.drop-icon{font-size:2.5rem;color:var(--gray3);margin-bottom:10px;}
.drop-text{font-size:.9rem;color:#64748b;}
.drop-text strong{color:var(--teal);}
.file-chosen{margin-top:10px;font-size:.82rem;color:var(--green);font-weight:600;}

.form-row{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:18px;}
.form-group label{font-size:.78rem;font-weight:600;color:#475569;display:block;margin-bottom:4px;}
.form-group input,.form-group select{height:38px;padding:0 12px;border:1px solid var(--gray2);border-radius:6px;font-size:.85rem;background:#f8fafc;width:100%;box-sizing:border-box;}
.req{color:var(--red);}

/* Progress */
#progress-section{display:none;margin-top:20px;}
.prog-bar-wrap{background:#e2e8f0;border-radius:8px;height:14px;overflow:hidden;}
.prog-bar-fill{height:14px;background:linear-gradient(90deg,var(--teal),var(--green));border-radius:8px;transition:width .3s;}
.prog-label{display:flex;justify-content:space-between;font-size:.78rem;color:#64748b;margin-top:4px;}
.step-log{max-height:160px;overflow-y:auto;background:#f8fafc;border:1px solid var(--gray2);border-radius:6px;padding:10px 14px;margin-top:12px;font-size:.78rem;color:#475569;font-family:monospace;}
.step-log .log-ok{color:var(--green);}
.step-log .log-err{color:var(--red);}
.step-log .log-info{color:var(--teal);}

/* Stats row */
.stat-pills{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px;}
.stat-pill{padding:6px 16px;border-radius:20px;font-size:.8rem;font-weight:600;}
.sp-blue{background:#dbeafe;color:#1d4ed8;}
.sp-green{background:#dcfce7;color:#166534;}
.sp-red{background:#fee2e2;color:#991b1b;}
.sp-amber{background:#fef3c7;color:#92400e;}

/* Preview table */
#preview-section{display:none;margin-top:24px;}
.preview-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:10px;}
.preview-head h3{margin:0;font-size:.95rem;color:#1e293b;}
.tbl-scroll{overflow-x:auto;border-radius:8px;border:1px solid var(--gray2);box-shadow:var(--shadow);}
.prev-table{width:100%;border-collapse:collapse;font-size:.78rem;white-space:nowrap;}
.prev-table thead th{background:var(--teal);color:#fff;padding:9px 12px;font-weight:600;text-align:left;}
.prev-table thead th.num{text-align:right;}
.prev-table tbody tr:nth-child(even){background:#f8fafc;}
.prev-table tbody tr:hover{background:#e0f2f1;}
.prev-table tbody td{padding:7px 12px;border-bottom:1px solid #f1f5f9;}
.prev-table tbody td.num{text-align:right;}
.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:.70rem;font-weight:600;}
.bg-green{background:#dcfce7;color:#166534;}
.bg-amber{background:#fef3c7;color:#92400e;}
.bg-teal{background:#ccfbf1;color:#0f766e;}
.bg-gray{background:#f1f5f9;color:#64748b;}

/* Buttons */
.btn-primary{background:var(--teal);color:#fff;border:none;border-radius:7px;padding:10px 24px;font-size:.88rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;}
.btn-primary:hover{background:var(--teal2);}
.btn-primary:disabled{background:#94a3b8;cursor:not-allowed;}
.btn-secondary{background:#fff;color:#475569;border:1px solid var(--gray2);border-radius:7px;padding:10px 20px;font-size:.88rem;cursor:pointer;}
.btn-danger{background:var(--red);color:#fff;border:none;border-radius:7px;padding:10px 20px;font-size:.88rem;cursor:pointer;}

.alert{padding:12px 16px;border-radius:7px;font-size:.85rem;margin-bottom:14px;}
.alert-success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.alert-info{background:#dbeafe;color:#1d4ed8;border:1px solid #bfdbfe;}

.scheme-tag{font-size:.7rem;background:#e0f2fe;color:#0369a1;padding:1px 7px;border-radius:10px;font-weight:600;}
</style>

<!-- ─── Header ─── -->
<div class="cci-header">
    <h2><i class="fa-solid fa-file-certificate"></i> &nbsp;Customer Claim Certificates Import</h2>
    <p>Upload Excel file — all columns imported; scheme code auto-extracted from Claim Description</p>
</div>

<div id="main-alert"></div>

<!-- ─── Upload Card ─── -->
<div class="upload-card">
    <h3><i class="fa-solid fa-upload" style="color:var(--teal);"></i> Upload Excel File</h3>

    <div class="drop-zone" id="dropZone">
        <input type="file" id="fileInput" accept=".xlsx,.xls">
        <div class="drop-icon"><i class="fa-solid fa-file-excel" style="color:#22c55e;"></i></div>
        <div class="drop-text">
            <strong>Click to browse</strong> or drag & drop your Excel file here<br>
            <small style="color:#94a3b8;">Supported: .xlsx / .xls &nbsp;|&nbsp; Max 20 MB</small>
        </div>
        <div class="file-chosen" id="fileChosen"></div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label>Banking / Import Date <span class="req">*</span></label>
            <input type="date" id="importDate" value="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="form-group">
            <label>Remarks <small style="color:#94a3b8;font-weight:400;">(optional)</small></label>
            <input type="text" id="importRemarks" placeholder="e.g. Feb 2026 batch upload">
        </div>
    </div>

    <div style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap;">
        <button class="btn-primary" id="btnParse" onclick="parseFile()" disabled>
            <i class="fa-solid fa-magnifying-glass"></i> Preview Data
        </button>
        <button class="btn-primary" id="btnImport" onclick="startImport()" disabled style="background:var(--green);">
            <i class="fa-solid fa-database"></i> Import to Database
        </button>
        <button class="btn-secondary" onclick="resetAll()">
            <i class="fa-solid fa-rotate-left"></i> Reset
        </button>
    </div>

    <!-- Progress -->
    <div id="progress-section">
        <div style="font-size:.83rem;font-weight:600;color:#475569;margin-bottom:6px;" id="prog-status">Processing…</div>
        <div class="prog-bar-wrap"><div class="prog-bar-fill" id="prog-bar" style="width:0%"></div></div>
        <div class="prog-label"><span id="prog-left">0 / 0 records</span><span id="prog-pct">0%</span></div>
        <div class="step-log" id="step-log"></div>
        <div class="stat-pills" id="stat-pills" style="display:none;"></div>
    </div>
</div>

<!-- ─── Preview Table ─── -->
<div id="preview-section">
    <div class="preview-head">
        <h3><i class="fa-solid fa-table" style="color:var(--teal);"></i> &nbsp;Preview — <span id="preview-count"></span> rows</h3>
        <div style="display:flex;gap:8px;">
            <span class="stat-pill sp-green" id="prev-valid-pill"></span>
            <span class="stat-pill sp-amber" id="prev-empty-pill"></span>
        </div>
    </div>
    <div class="tbl-scroll">
        <table class="prev-table" id="previewTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>No</th>
                    <th>Ledger Type</th>
                    <th>Status</th>
                    <th>Claim Type</th>
                    <th>Tax Invoice Number</th>
                    <th>Invoice Date</th>
                    <th>Banking Date</th>
                    <th>Entity</th>
                    <th>Claim Description</th>
                    <th>Scheme Code</th>
                    <th class="num">Actual Amount</th>
                    <th class="num">VAT Amount</th>
                </tr>
            </thead>
            <tbody id="previewBody"></tbody>
        </table>
    </div>
    <p style="font-size:.75rem;color:#94a3b8;margin-top:6px;">Showing first 100 rows preview. All rows will be imported.</p>
</div>

<!-- SheetJS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
let parsedData   = [];   // all cleaned rows
let currentFile  = null;
const CHUNK_SIZE = 200;

/* ── File input ─────────────────────────────────────────────────────────── */
document.getElementById('fileInput').addEventListener('change', function(){
    if (this.files[0]) { currentFile = this.files[0]; onFileSelected(); }
});
const dz = document.getElementById('dropZone');
dz.addEventListener('dragover', e=>{ e.preventDefault(); dz.classList.add('drag-over'); });
dz.addEventListener('dragleave', ()=> dz.classList.remove('drag-over'));
dz.addEventListener('drop', e=>{ e.preventDefault(); dz.classList.remove('drag-over'); const f=e.dataTransfer.files[0]; if(f){ currentFile=f; document.getElementById('fileInput').files=e.dataTransfer.files; onFileSelected(); } });

function onFileSelected(){
    document.getElementById('fileChosen').textContent = '📎 '+currentFile.name+' ('+(currentFile.size/1024).toFixed(1)+' KB)';
    document.getElementById('btnParse').disabled = false;
    document.getElementById('btnImport').disabled = true;
    parsedData = [];
    document.getElementById('preview-section').style.display = 'none';
}

/* ── Extract scheme code from claim description ─────────────────────────── */
function extractSchemeCode(desc){
    if (!desc) return '';
    const part = String(desc).split('-')[0].trim();
    return /^\d+$/.test(part) ? part : '';
}

/* ── Date formatter ─────────────────────────────────────────────────────── */
function fmtDate(val){
    if (!val && val !== 0) return '';
    if (typeof val === 'number') {
        // Excel serial date
        const d = new Date(Math.round((val - 25569) * 86400 * 1000));
        return d.toISOString().split('T')[0];
    }
    if (val instanceof Date) return val.toISOString().split('T')[0];
    const s = String(val).trim();
    if (/^\d{4}-\d{2}-\d{2}/.test(s)) return s.substring(0,10);
    // try parse
    const d = new Date(s);
    if (!isNaN(d)) return d.toISOString().split('T')[0];
    return s;
}

/* ── Parse Excel ────────────────────────────────────────────────────────── */
function parseFile(){
    if (!currentFile){ showAlert('error','Please select an Excel file first.'); return; }
    const btn = document.getElementById('btnParse');
    btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Parsing…';

    const reader = new FileReader();
    reader.onload = function(e){
        try {
            const wb  = XLSX.read(e.target.result, {type:'array', cellDates:true});
            const ws  = wb.Sheets[wb.SheetNames[0]];
            const raw = XLSX.utils.sheet_to_json(ws, {header:1, defval:''});

            // Find header row (contains 'Claim Description' or 'No')
            let headerRow = -1;
            for (let i=0; i<Math.min(5,raw.length); i++){
                const r = raw[i].map(c=>String(c).trim());
                if (r.some(c=>c==='Claim Description'||c==='No')) { headerRow=i; break; }
            }
            if (headerRow === -1){ showAlert('error','Cannot find header row. Expected columns: No, Claim Description, etc.'); resetBtn(); return; }

            const headers = raw[headerRow].map(c=>String(c).trim());
            const col = name => headers.indexOf(name);

            const iNo    = col('No');
            const iLT    = col('Ledger Type');
            const iStat  = col('Status');
            const iCT    = col('Claim Type');
            const iTIN   = col('Tax Invoice Number');
            const iInvD  = col('Invoice Date');
            const iBankD = col('Banking Date');
            const iEnt   = col('Entity');
            const iDesc  = col('Claim Description');
            const iAmt   = col('Actual Amount');
            const iVAT   = col('VAT Amount');

            parsedData = [];
            for (let i = headerRow+1; i < raw.length; i++){
                const r = raw[i];
                // skip fully empty rows
                if (r.every(c=> c==='' || c===null || c===undefined)) continue;

                const desc    = String(r[iDesc]  ?? '').trim();
                const tinRaw  = r[iTIN];
                // Tax Invoice Number may be scientific notation from Excel
                let tin = '';
                if (tinRaw !== '' && tinRaw !== null && tinRaw !== undefined){
                    tin = typeof tinRaw === 'number' ? tinRaw.toFixed(0) : String(tinRaw).trim();
                }

                parsedData.push({
                    row_no:          iNo   >= 0 ? String(r[iNo]  ?? '').trim() : '',
                    ledger_type:     iLT   >= 0 ? String(r[iLT]  ?? '').trim() : '',
                    status:          iStat >= 0 ? String(r[iStat]?? '').trim() : '',
                    claim_type:      iCT   >= 0 ? String(r[iCT]  ?? '').trim() : '',
                    tax_invoice_no:  tin,
                    invoice_date:    iInvD  >= 0 ? fmtDate(r[iInvD])  : '',
                    banking_date:    iBankD >= 0 ? fmtDate(r[iBankD]) : '',
                    entity:          iEnt  >= 0 ? String(r[iEnt] ?? '').trim() : '',
                    claim_description: desc,
                    scheme_code:     extractSchemeCode(desc),
                    actual_amount:   iAmt  >= 0 ? (parseFloat(r[iAmt])  || 0) : 0,
                    vat_amount:      iVAT  >= 0 ? (parseFloat(r[iVAT])  || 0) : 0,
                });
            }

            renderPreview();
            document.getElementById('btnImport').disabled = (parsedData.length === 0);
            showAlert('info', `Parsed <strong>${parsedData.length}</strong> records from <strong>${currentFile.name}</strong>. Review below then click <strong>Import to Database</strong>.`);
        } catch(ex){
            showAlert('error','Parse error: '+ex.message);
        }
        resetBtn();
    };
    reader.readAsArrayBuffer(currentFile);
}

function resetBtn(){
    const btn = document.getElementById('btnParse');
    btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Preview Data';
}

/* ── Render Preview ─────────────────────────────────────────────────────── */
function renderPreview(){
    const tbody  = document.getElementById('previewBody');
    const maxPrev = 100;
    const rows    = parsedData.slice(0, maxPrev);
    document.getElementById('preview-count').textContent = parsedData.length.toLocaleString();

    const withScheme = parsedData.filter(r=>r.scheme_code!=='').length;
    const noScheme   = parsedData.length - withScheme;
    document.getElementById('prev-valid-pill').textContent  = `${withScheme} with scheme code`;
    document.getElementById('prev-empty-pill').textContent  = `${noScheme} no scheme code`;

    let html = '';
    rows.forEach((r, i)=>{
        const scBadge = r.scheme_code
            ? `<span class="scheme-tag">${esc(r.scheme_code)}</span>`
            : '<span style="color:#cbd5e1;font-size:.72rem;">—</span>';
        const ltBadge = r.ledger_type
            ? `<span class="badge ${r.ledger_type.toLowerCase().includes('vendor')||r.ledger_type.toLowerCase().includes('vender') ? 'bg-amber' : 'bg-teal'}">${esc(r.ledger_type)}</span>`
            : '—';
        html += `<tr>
            <td style="color:#94a3b8;">${i+1}</td>
            <td>${esc(r.row_no)}</td>
            <td>${ltBadge}</td>
            <td>${r.status ? `<span class="badge bg-green">${esc(r.status)}</span>` : '—'}</td>
            <td>${esc(r.claim_type)||'—'}</td>
            <td style="font-family:monospace;font-size:.75rem;">${esc(r.tax_invoice_no)||'—'}</td>
            <td>${esc(r.invoice_date)||'—'}</td>
            <td>${esc(r.banking_date)||'—'}</td>
            <td>${r.entity?`<span class="badge bg-gray">${esc(r.entity)}</span>`:'—'}</td>
            <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;" title="${esc(r.claim_description)}">${esc(r.claim_description)||'—'}</td>
            <td>${scBadge}</td>
            <td class="num">${r.actual_amount.toLocaleString('en-IN',{minimumFractionDigits:2})}</td>
            <td class="num">${r.vat_amount.toLocaleString('en-IN',{minimumFractionDigits:2})}</td>
        </tr>`;
    });
    tbody.innerHTML = html;
    document.getElementById('preview-section').style.display = 'block';
}

/* ── Import ─────────────────────────────────────────────────────────────── */
async function startImport(){
    if (parsedData.length === 0){ showAlert('error','No data to import. Please parse a file first.'); return; }
    const importDate = document.getElementById('importDate').value;
    if (!importDate){ showAlert('error','Please select an import date.'); return; }

    if (!confirm(`Import ${parsedData.length} records into the database?`)) return;

    document.getElementById('btnParse').disabled  = true;
    document.getElementById('btnImport').disabled = true;
    document.getElementById('progress-section').style.display = 'block';
    document.getElementById('stat-pills').style.display = 'none';
    document.getElementById('step-log').innerHTML = '';

    const totalChunks  = Math.ceil(parsedData.length / CHUNK_SIZE);
    const totalRecords = parsedData.length;
    let import_id      = 0;
    let totalImported  = 0;
    let totalFailed    = 0;

    for (let ci = 0; ci < totalChunks; ci++){
        const chunk = parsedData.slice(ci * CHUNK_SIZE, (ci+1) * CHUNK_SIZE);
        log(`info`, `Chunk ${ci+1}/${totalChunks} — sending ${chunk.length} records…`);

        const fd = new FormData();
        fd.append('chunk_index',   ci);
        fd.append('total_chunks',  totalChunks);
        fd.append('import_id',     import_id);
        fd.append('total_records', totalRecords);
        fd.append('import_date',   importDate);
        fd.append('filename',      currentFile.name);
        fd.append('remarks',       document.getElementById('importRemarks').value);
        fd.append('data',          JSON.stringify(chunk));

        try {
            const res  = await fetch('process_claim_import.php', {method:'POST', body:fd});
            const json = await res.json();

            if (!json.success){
                log('err', `Chunk ${ci+1} failed: ${json.message}`);
                showAlert('error', 'Import error on chunk '+(ci+1)+': '+json.message);
                break;
            }

            import_id     = json.import_id;
            totalImported += json.chunk_imported;
            totalFailed   += json.chunk_failed;

            const done = ((ci+1) / totalChunks * 100).toFixed(0);
            setProgress(done, totalImported + totalFailed, totalRecords);
            log('ok', `Chunk ${ci+1}/${totalChunks} — ✓ ${json.chunk_imported} saved${json.chunk_failed ? ', ✗ '+json.chunk_failed+' failed' : ''}`);

        } catch(ex){
            log('err', `Network error on chunk ${ci+1}: `+ex.message);
            showAlert('error','Network error: '+ex.message);
            break;
        }
    }

    /* Final */
    setProgress(100, totalImported + totalFailed, totalRecords);
    document.getElementById('prog-status').textContent = 'Import Complete';
    document.getElementById('stat-pills').style.display = 'flex';
    document.getElementById('stat-pills').innerHTML = `
        <span class="stat-pill sp-blue">${totalRecords} Total</span>
        <span class="stat-pill sp-green">${totalImported} Imported</span>
        ${totalFailed ? `<span class="stat-pill sp-red">${totalFailed} Failed</span>` : ''}
        <a href="customer_claim_list.php?import_id=${import_id}" class="stat-pill sp-blue" style="text-decoration:none;">
            <i class="fa fa-eye"></i> View Import
        </a>
    `;
    showAlert('success', `Import complete — <strong>${totalImported}</strong> records saved${totalFailed ? ', <strong>'+totalFailed+'</strong> failed' : ''}.`);
    document.getElementById('btnImport').disabled = false;
}

/* ── Helpers ─────────────────────────────────────────────────────────────── */
function setProgress(pct, done, total){
    document.getElementById('prog-bar').style.width = pct+'%';
    document.getElementById('prog-left').textContent = done.toLocaleString()+' / '+total.toLocaleString()+' records';
    document.getElementById('prog-pct').textContent  = pct+'%';
}
function log(type, msg){
    const el  = document.getElementById('step-log');
    const cls = type==='ok'?'log-ok':type==='err'?'log-err':'log-info';
    el.innerHTML += `<div class="${cls}">[${new Date().toLocaleTimeString()}] ${msg}</div>`;
    el.scrollTop = el.scrollHeight;
}
function showAlert(type, msg){
    document.getElementById('main-alert').innerHTML = `<div class="alert alert-${type}">${msg}</div>`;
}
function esc(s){
    return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function resetAll(){
    parsedData = [];
    currentFile = null;
    document.getElementById('fileInput').value = '';
    document.getElementById('fileChosen').textContent = '';
    document.getElementById('btnParse').disabled  = true;
    document.getElementById('btnImport').disabled = true;
    document.getElementById('preview-section').style.display  = 'none';
    document.getElementById('progress-section').style.display = 'none';
    document.getElementById('main-alert').innerHTML = '';
    document.getElementById('step-log').innerHTML   = '';
    document.getElementById('prog-bar').style.width = '0%';
}
</script>

<?php include 'footer.php'; ?>
