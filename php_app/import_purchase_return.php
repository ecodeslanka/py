<?php
include 'config.php';
include 'header.php';

$current_year = date('Y');
$month_names  = ['','January','February','March','April','May','June',
                 'July','August','September','October','November','December'];

// ── Ensure tables exist ────────────────────────────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS purchase_return_imports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255),
        stored_filename VARCHAR(255),
        bill_date DATE,
        remarks TEXT,
        status VARCHAR(20) DEFAULT 'processing',
        total_records INT DEFAULT 0,
        imported_records INT DEFAULT 0,
        failed_records INT DEFAULT 0,
        imported_at DATETIME DEFAULT NOW()
    )
");
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS purchase_return_details (
        id INT AUTO_INCREMENT PRIMARY KEY,
        import_id INT,
        purchase_ret_no VARCHAR(100),
        invoice_no VARCHAR(100),
        item_code VARCHAR(50),
        item_name VARCHAR(255),
        mrp DECIMAL(12,2),
        invoice_price_case DECIMAL(12,4),
        invoice_price_unit DECIMAL(12,4),
        pkm VARCHAR(50),
        batch_code VARCHAR(50),
        pack_size DECIMAL(12,4),
        upc VARCHAR(50),
        purchase_ret_qty_cases DECIMAL(12,4),
        purchase_ret_amount DECIMAL(14,2),
        tax_amount DECIMAL(14,2),
        net_amount DECIMAL(14,2),
        tonnage DECIMAL(12,6)
    )
");
?>
<div class="page-header">
    <h2 class="page-title"><i class="fa-solid fa-file-arrow-up"></i> Import Product Wise Purchase Return Report</h2>
    <p class="page-subtitle">Upload the Product Wise Purchase Return Excel report to import records</p>
</div>

<!-- Progress overlay -->
<div id="progressOverlay" style="display:none;">
    <div class="prog-card">
        <div class="prog-header">
            <i class="fa-solid fa-spinner fa-spin prog-spinner"></i>
            <div>
                <div class="prog-title">Importing Excel…</div>
                <div class="prog-subtitle" id="progStepLabel">Uploading file…</div>
            </div>
        </div>
        <div class="prog-bar-wrap">
            <div class="prog-bar-track">
                <div class="prog-bar-fill" id="progBarFill" style="width:0%"></div>
            </div>
            <span class="prog-pct" id="progPct">0%</span>
        </div>
        <div class="prog-steps">
            <div class="prog-step" id="step1"><i class="fa-solid fa-upload"></i> Uploading file</div>
            <div class="prog-step" id="step2"><i class="fa-solid fa-gear"></i> Parsing Excel</div>
            <div class="prog-step" id="step3"><i class="fa-solid fa-database"></i> Inserting records</div>
            <div class="prog-step" id="step4"><i class="fa-solid fa-circle-check"></i> Done</div>
        </div>
    </div>
</div>

<!-- Result banner -->
<div id="resultBanner" style="display:none;"></div>

<!-- Upload form -->
<div class="content-card" id="uploadCard">
    <h3 class="card-title"><i class="fa-solid fa-upload"></i> Upload Report</h3>
    <form id="uploadForm" enctype="multipart/form-data">
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Bill / Import Date <span class="req">*</span></label>
                <input type="date" name="bill_date" id="bill_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                <span class="field-hint">Select the bill date for this purchase return report</span>
            </div>
            <div class="form-group">
                <label class="form-label">Remarks</label>
                <input type="text" name="remarks" class="form-input" placeholder="Optional notes about this upload…">
            </div>
            <div class="form-group" style="grid-column:1/-1;">
                <label class="form-label">Excel File <span class="req">*</span></label>
                <div class="drop-zone" id="dropZone">
                    <input type="file" name="return_file" id="fileInput" accept=".xlsx,.xls" required class="file-input">
                    <div id="dropContent" class="drop-content">
                        <i class="fa-solid fa-cloud-arrow-up drop-icon"></i>
                        <p class="drop-title">Drag &amp; drop your Excel file here</p>
                        <p class="drop-sub">or click to browse &nbsp;·&nbsp; .xlsx &amp; .xls supported</p>
                    </div>
                    <div id="fileChosen" class="file-chosen" style="display:none;">
                        <i class="fa-solid fa-file-excel" style="color:#166534;font-size:28px;flex-shrink:0;"></i>
                        <div style="flex:1;min-width:0;">
                            <div id="fileChosenName" style="font-weight:600;color:#1f2937;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
                            <div id="fileChosenSize" style="font-size:12px;color:#6b7280;margin-top:2px;"></div>
                        </div>
                        <button type="button" class="btn-clear" onclick="clearFile(event)" title="Remove file">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                <i class="fa-solid fa-file-import"></i> Import Now
            </button>
            <a href="purchase_return_history.php" class="btn btn-secondary">
                <i class="fa-solid fa-history"></i> View History
            </a>
        </div>
    </form>
</div>

<!-- What gets imported info card -->
<div class="content-card">
    <h3 class="card-title"><i class="fa-solid fa-circle-info"></i> What Gets Imported</h3>
    <p style="color:#6b7280;font-size:14px;margin:0 0 14px;">
        The <strong>Product_Wise_Purchase_Return</strong> sheet is imported. <strong>Sr No</strong> column is excluded automatically.
        All other columns are imported:
    </p>
    <div class="col-grid">
        <?php foreach ([
            'Purchase Ret No','Invoice No','Item Code','Item Name',
            'MRP','Invoice Price/Case','Invoice Price/Unit','PKM',
            'Batch Code','Pack Size','UPC','Purchase Ret Qty (Cases)',
            'Purchase Ret Amount','Tax Amount','Net Amount','Tonnage'
        ] as $c): ?>
        <div class="col-pill"><?= htmlspecialchars($c) ?></div>
        <?php endforeach; ?>
    </div>
    <div class="info-note">
        <i class="fa-solid fa-lightbulb"></i>
        <span>The <strong>Bill Date</strong> you select above is stored with each import batch. Rows with an empty <em>Purchase Ret No</em> are skipped.</span>
    </div>
</div>

<style>
.page-header{margin-bottom:24px}
.page-title{font-size:22px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:10px;margin:0 0 6px}
.page-subtitle{color:#6b7280;font-size:14px;margin:0}
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:24px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;color:#1f2937;display:flex;align-items:center;gap:8px;margin:0 0 20px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-label{font-size:13px;font-weight:600;color:#374151}
.req{color:#ef4444}
.form-input{width:100%;padding:10px 14px;border:1px solid #e5e5e5;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box;transition:border-color .2s}
.form-input:focus{outline:none;border-color:#000;box-shadow:0 0 0 3px rgba(0,0,0,.06)}
.field-hint{font-size:11px;color:#9ca3af;margin-top:2px}
.form-actions{display:flex;gap:12px;margin-top:20px;padding-top:20px;border-top:1px solid #f0f0f0}
.drop-zone{border:2px dashed #e5e5e5;border-radius:10px;position:relative;cursor:pointer;transition:all .2s;overflow:hidden}
.drop-zone:hover,.drop-zone.drag-over{border-color:#000;background:#fafafa}
.file-input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.drop-content{padding:32px 20px;text-align:center}
.drop-icon{font-size:36px;color:#9ca3af;display:block;margin-bottom:10px}
.drop-title{font-size:15px;font-weight:600;color:#374151;margin:0 0 4px}
.drop-sub{font-size:13px;color:#9ca3af;margin:0}
.file-chosen{display:flex;align-items:center;gap:14px;padding:16px 20px}
.btn-clear{background:none;border:1px solid #e5e5e5;border-radius:6px;padding:6px 8px;cursor:pointer;color:#666;flex-shrink:0}
.btn-clear:hover{background:#fef2f2;border-color:#fecaca;color:#ef4444}
.col-grid{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.col-pill{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:500}
.info-note{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;display:flex;align-items:flex-start;gap:10px;font-size:13px;color:#1e40af}
.info-note i{margin-top:1px;flex-shrink:0}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s}
.btn-lg{padding:12px 28px;font-size:15px}
.btn-primary{background:#000;color:#fff}
.btn-primary:hover{background:#333}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}
/* Progress overlay */
#progressOverlay{position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:9999}
.prog-card{background:#fff;border-radius:14px;padding:32px;width:min(480px,90vw);box-shadow:0 20px 60px rgba(0,0,0,.3)}
.prog-header{display:flex;align-items:center;gap:16px;margin-bottom:24px}
.prog-spinner{font-size:32px;color:#000}
.prog-title{font-size:17px;font-weight:700;color:#1f2937}
.prog-subtitle{font-size:13px;color:#6b7280;margin-top:2px}
.prog-bar-wrap{display:flex;align-items:center;gap:10px;margin-bottom:20px}
.prog-bar-track{flex:1;height:10px;background:#f0f0f0;border-radius:99px;overflow:hidden}
.prog-bar-fill{height:100%;background:#000;border-radius:99px;transition:width .4s ease}
.prog-pct{font-size:13px;font-weight:700;color:#374151;width:36px;text-align:right;flex-shrink:0}
.prog-steps{display:flex;gap:6px;flex-wrap:wrap}
.prog-step{font-size:12px;padding:5px 12px;border-radius:20px;background:#f5f5f5;color:#9ca3af;display:flex;align-items:center;gap:5px;transition:all .3s}
.prog-step.active{background:#000;color:#fff}
.prog-step.done{background:#f0fdf4;color:#166534}
/* Result banners */
.result-card{border-radius:10px;padding:24px;margin-bottom:20px}
.result-success{background:#f0fdf4;border:1px solid #bbf7d0}
.result-error{background:#fef2f2;border:1px solid #fecaca}
.result-title{font-size:17px;font-weight:700;display:flex;align-items:center;gap:10px;color:#1f2937;margin-bottom:16px}
.result-stats{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px}
.res-stat{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:14px 20px;text-align:center;min-width:100px}
.res-stat-val{display:block;font-size:22px;font-weight:700;color:#1f2937}
.res-stat-lbl{display:block;font-size:11px;color:#6b7280;margin-top:2px}
.res-green .res-stat-val{color:#166534}
.res-red .res-stat-val{color:#991b1b}
.res-blue .res-stat-val{color:#1e40af}
.result-actions{display:flex;gap:10px;flex-wrap:wrap}
.err-detail{font-size:13px;color:#991b1b;white-space:pre-wrap;word-break:break-word;background:#fff;border:1px solid #fecaca;border-radius:6px;padding:12px;margin-bottom:14px}
@media(max-width:600px){.form-grid{grid-template-columns:1fr}.form-group[style*="grid-column"]{grid-column:1!important}}
</style>

<script>
const fi = document.getElementById('fileInput');
const dz = document.getElementById('dropZone');
const dc = document.getElementById('dropContent');
const fc = document.getElementById('fileChosen');
const fn = document.getElementById('fileChosenName');
const fs = document.getElementById('fileChosenSize');

fi.addEventListener('change', e => { if(e.target.files[0]) showFile(e.target.files[0]); });
dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('drag-over'); });
dz.addEventListener('dragleave', () => dz.classList.remove('drag-over'));
dz.addEventListener('drop', e => {
    e.preventDefault(); dz.classList.remove('drag-over');
    const f = e.dataTransfer.files[0];
    if(f){ const dt = new DataTransfer(); dt.items.add(f); fi.files = dt.files; showFile(f); }
});
function showFile(f){
    fn.textContent = f.name;
    fs.textContent = (f.size/1024).toFixed(1) + ' KB';
    dc.style.display = 'none'; fc.style.display = 'flex';
}
function clearFile(e){
    e.stopPropagation(); fi.value = '';
    dc.style.display = ''; fc.style.display = 'none';
}

// Progress
function setProgress(pct, stepLabel, activeStep){
    document.getElementById('progBarFill').style.width = pct + '%';
    document.getElementById('progPct').textContent = pct + '%';
    document.getElementById('progStepLabel').textContent = stepLabel;
    ['step1','step2','step3','step4'].forEach((id,i) => {
        const el = document.getElementById(id);
        el.className = 'prog-step';
        if(i+1 < activeStep) el.classList.add('done');
        else if(i+1 === activeStep) el.classList.add('active');
    });
}

// AJAX submit
document.getElementById('uploadForm').addEventListener('submit', function(e){
    e.preventDefault();
    if(!fi.files[0]){
        alert('Please choose an Excel file before importing.');
        return;
    }
    document.getElementById('progressOverlay').style.display = 'flex';
    document.getElementById('uploadCard').style.opacity = '.4';
    document.getElementById('submitBtn').disabled = true;
    setProgress(5, 'Uploading file…', 1);

    const fd = new FormData(this);
    fd.append('ajax_import', '1');

    const xhr = new XMLHttpRequest();
    xhr.upload.addEventListener('progress', ev => {
        if(ev.lengthComputable){
            const pct = Math.round((ev.loaded/ev.total)*35)+5;
            setProgress(pct, 'Uploading… ('+ Math.round((ev.loaded/ev.total)*100) +'%)', 1);
        }
    });
    xhr.upload.addEventListener('load', () => setProgress(42, 'Parsing Excel sheet…', 2));

    xhr.addEventListener('readystatechange', () => {
        if(xhr.readyState === 3) tryParseProgress(xhr.responseText);
    });

    xhr.addEventListener('load', () => {
        document.getElementById('progressOverlay').style.display = 'none';
        document.getElementById('uploadCard').style.opacity = '1';
        document.getElementById('submitBtn').disabled = false;

        let res;
        try {
            const text = xhr.responseText || '';
            const marker = text.match(/@@RESULT@@([\s\S]+?)@@END@@/);
            if(marker){ res = JSON.parse(marker[1].trim()); }
            else {
                const lines = text.split('\n').reverse();
                for(const line of lines){
                    const t = line.trim();
                    if(t.startsWith('{') && t.endsWith('}')){
                        try{ res = JSON.parse(t); break; }catch(e2){}
                    }
                }
            }
            if(!res) throw new Error('No JSON found');
        } catch(err){
            showBanner('error','<i class="fa-solid fa-circle-xmark"></i> Unexpected server response',
                'Raw output:\n'+(xhr.responseText||'(empty)').substring(0,600));
            return;
        }
        if(res.status === 'ok'){
            setProgress(100, 'Done!', 4);
            showBanner('success', '<i class="fa-solid fa-circle-check"></i> Import Complete', null, res);
            fi.value = ''; dc.style.display = ''; fc.style.display = 'none';
        } else {
            showBanner('error','<i class="fa-solid fa-circle-xmark"></i> Import Failed', res.error || 'Unknown error.');
        }
    });

    xhr.addEventListener('error', () => {
        document.getElementById('progressOverlay').style.display = 'none';
        document.getElementById('uploadCard').style.opacity = '1';
        document.getElementById('submitBtn').disabled = false;
        showBanner('error','<i class="fa-solid fa-circle-xmark"></i> Network Error','Could not reach the server.');
    });

    xhr.open('POST', 'import_purchase_return_ajax.php');
    xhr.send(fd);
});

let lastProg = 0;
function tryParseProgress(text){
    const matches = text.match(/\{"progress":(\d+),"stage":"([^"]+)"\}/g);
    if(!matches) return;
    const m = matches[matches.length-1].match(/\{"progress":(\d+),"stage":"([^"]+)"\}/);
    if(!m) return;
    const pct = parseInt(m[1]), stage = m[2];
    if(pct > lastProg){
        lastProg = pct;
        if(pct < 50) setProgress(pct, stage, 2);
        else setProgress(pct, stage, 3);
    }
}

function showBanner(type, title, msg, res){
    const div = document.getElementById('resultBanner');
    if(type === 'success' && res){
        div.innerHTML = `
        <div class="result-card result-success">
            <div class="result-title">${title}</div>
            <div class="result-stats">
                <div class="res-stat res-green"><span class="res-stat-val">${res.ok.toLocaleString()}</span><span class="res-stat-lbl">Records Imported</span></div>
                ${res.fail>0?`<div class="res-stat res-red"><span class="res-stat-val">${res.fail.toLocaleString()}</span><span class="res-stat-lbl">Records Failed</span></div>`:''}
                <div class="res-stat res-blue"><span class="res-stat-val">${(res.ok+res.fail).toLocaleString()}</span><span class="res-stat-lbl">Total Rows</span></div>
            </div>
            <div class="result-actions">
                <a href="purchase_return_history.php" class="btn btn-secondary"><i class="fa-solid fa-history"></i> Import History</a>
                <a href="purchase_return_view.php?id=${res.import_id}" class="btn btn-primary"><i class="fa-solid fa-eye"></i> View This Import</a>
            </div>
        </div>`;
    } else {
        div.innerHTML = `
        <div class="result-card result-error">
            <div class="result-title">${title}</div>
            ${msg?`<div class="err-detail">${escHtml(msg)}</div>`:''}
            <div class="result-actions" style="margin-top:14px;">
                <button onclick="document.getElementById('resultBanner').style.display='none'" class="btn btn-secondary"><i class="fa-solid fa-rotate-left"></i> Try Again</button>
            </div>
        </div>`;
    }
    div.style.display = 'block';
    div.scrollIntoView({behavior:'smooth', block:'start'});
}
function escHtml(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>
<?php include 'footer.php'; ?>
