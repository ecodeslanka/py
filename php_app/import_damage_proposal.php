<?php
include 'config.php';
include 'header.php';

$current_year   = date('Y');
$selected_month = isset($_POST['proposal_month']) ? intval($_POST['proposal_month']) : intval(date('m'));
$month_names    = ['','January','February','March','April','May','June',
                   'July','August','September','October','November','December'];

// ── Ensure tables exist ───────────────────────────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS damage_proposal_imports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255),
        stored_filename VARCHAR(255),
        proposal_month TINYINT,
        proposal_year SMALLINT,
        remarks TEXT,
        status VARCHAR(20) DEFAULT 'processing',
        total_records INT DEFAULT 0,
        imported_records INT DEFAULT 0,
        failed_records INT DEFAULT 0,
        imported_at DATETIME DEFAULT NOW()
    )
");
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS damage_proposal_transaction_details (
        id INT AUTO_INCREMENT PRIMARY KEY,
        import_id INT,
        tso_plg VARCHAR(50),
        transaction_type VARCHAR(100),
        trans_ref_no VARCHAR(100),
        trans_date DATE,
        retailer_code VARCHAR(50),
        retailer_name VARCHAR(255),
        product_code VARCHAR(50),
        product_name VARCHAR(255),
        return_type VARCHAR(100),
        app_reason VARCHAR(100),
        pkm VARCHAR(50),
        batch_code VARCHAR(50),
        qty_free_qty DECIMAL(12,2),
        aip DECIMAL(12,4),
        mrp DECIMAL(12,2),
        tur DECIMAL(12,4),
        total_tur_value DECIMAL(14,2),
        total_aip DECIMAL(14,2),
        credit_note_no VARCHAR(100),
        credit_note_dt DATE,
        crdr_amt DECIMAL(14,2),
        adj_bill_ref_billing VARCHAR(100),
        adj_bill_ref_collection VARCHAR(100),
        original_bill_no VARCHAR(100)
    )
");
?>
<div class="page-header">
    <h2 class="page-title"><i class="fa-solid fa-file-arrow-up"></i> Import Damage &amp; Shortage — Transaction Details</h2>
    <p class="page-subtitle">Upload the monthly Proposal Status Report Excel to import Transaction Details records</p>
</div>

<!-- ── Progress overlay (shown during upload) ──────────────────────────────── -->
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

<!-- ── Result banner (shown after AJAX completes) ──────────────────────────── -->
<div id="resultBanner" style="display:none;"></div>

<!-- ── Upload form ─────────────────────────────────────────────────────────── -->
<div class="content-card" id="uploadCard">
    <h3 class="card-title"><i class="fa-solid fa-upload"></i> Upload Report</h3>
    <form id="uploadForm" enctype="multipart/form-data">
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Proposal Month <span class="req">*</span></label>
                <select name="proposal_month" class="form-input" required>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m == $selected_month ? 'selected' : '' ?>><?= $month_names[$m] ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Year <span class="req">*</span></label>
                <select name="proposal_year" class="form-input" required>
                    <?php for ($y = $current_year - 2; $y <= $current_year + 1; $y++): ?>
                    <option value="<?= $y ?>" <?= $y == $current_year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group" style="grid-column:1/-1;">
                <label class="form-label">Remarks</label>
                <input type="text" name="remarks" class="form-input" placeholder="Optional notes about this upload…">
            </div>
            <div class="form-group" style="grid-column:1/-1;">
                <label class="form-label">Excel File <span class="req">*</span></label>
                <div class="drop-zone" id="dropZone">
                    <input type="file" name="proposal_file" id="fileInput" accept=".xlsx,.xls" required class="file-input">
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
            <a href="damage_proposal_history.php" class="btn btn-secondary">
                <i class="fa-solid fa-history"></i> View History
            </a>
        </div>
    </form>
</div>

<div class="content-card">
    <h3 class="card-title"><i class="fa-solid fa-circle-info"></i> What Gets Imported</h3>
    <p style="color:#6b7280;font-size:14px;margin:0 0 14px;">Only the <strong>TRANSACTION DETAILS</strong> sheet is imported. Sr No column is excluded.</p>
    <div class="col-grid">
        <?php foreach (['TSO PLG','TRANSACTION TYPE','TRANS REF NO','TRANS DATE','RETAILER CODE','RETAILER NAME','PRODUCT CODE','PRODUCT NAME','RETURN TYPE','APP REASON','PKM','BATCH CODE','QTY/FREE QTY','AIP','MRP','TUR','TOTAL TUR VALUE','Total AIP','CREDIT NOTE NO','CREDIT NOTE DT','CRDR_AMT','ADJ BILL REF NO - BILLING','ADJ BILL REF NO - COLLECTION','Original Bill No'] as $c): ?>
        <div class="col-pill"><?= htmlspecialchars($c) ?></div>
        <?php endforeach; ?>
    </div>
</div>

<style>
.page-header{margin-bottom:24px}
.page-title{font-size:22px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:10px;margin:0 0 6px}
.page-subtitle{color:#6b7280;font-size:14px;margin:0}
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:24px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;color:#1f2937;display:flex;align-items:center;gap:8px;margin:0 0 20px}
.alert{padding:13px 16px;border-radius:8px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:14px}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-label{font-size:13px;font-weight:600;color:#374151}
.req{color:#ef4444}
.form-input{padding:10px 14px;border:1px solid #e5e5e5;border-radius:8px;font-size:14px;font-family:inherit;width:100%;box-sizing:border-box}
.form-input:focus{outline:none;border-color:#000;box-shadow:0 0 0 3px rgba(0,0,0,.06)}
.form-actions{margin-top:20px;display:flex;gap:10px;align-items:center}
.drop-zone{position:relative;border:2px dashed #d1d5db;border-radius:10px;transition:border-color .2s,background .2s}
.drop-zone.drag-over{border-color:#000;background:#f9fafb}
.file-input{position:absolute;inset:0;opacity:0;cursor:pointer;z-index:2;width:100%;height:100%}
.drop-content{padding:38px 20px;text-align:center;pointer-events:none}
.drop-icon{font-size:38px;color:#9ca3af;display:block;margin-bottom:10px}
.drop-title{font-weight:600;color:#374151;margin:0 0 4px;font-size:15px}
.drop-sub{font-size:13px;color:#9ca3af;margin:0}
.file-chosen{padding:18px 22px;display:flex;align-items:center;gap:14px}
.btn-clear{background:none;border:none;color:#ef4444;cursor:pointer;font-size:18px;padding:4px 8px;border-radius:6px;flex-shrink:0}
.btn-clear:hover{background:#fef2f2}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s}
.btn-lg{padding:12px 28px;font-size:15px}
.btn-primary{background:#000;color:#fff}
.btn-primary:hover{background:#333}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}
.col-grid{display:flex;flex-wrap:wrap;gap:8px}
.col-pill{background:#f3f4f6;border:1px solid #e5e7eb;border-radius:6px;padding:5px 11px;font-size:12px;font-weight:500;color:#374151}

/* ── Progress overlay ─────────────────────────────────────────────────── */
#progressOverlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(3px)}
.prog-card{background:#fff;border-radius:16px;padding:36px 40px;width:min(480px,90vw);box-shadow:0 24px 60px rgba(0,0,0,.25)}
.prog-header{display:flex;align-items:center;gap:16px;margin-bottom:28px}
.prog-spinner{font-size:32px;color:#1e40af;flex-shrink:0}
.prog-title{font-size:18px;font-weight:700;color:#1f2937}
.prog-subtitle{font-size:13px;color:#6b7280;margin-top:3px}
.prog-bar-wrap{display:flex;align-items:center;gap:12px;margin-bottom:22px}
.prog-bar-track{flex:1;height:10px;background:#f3f4f6;border-radius:99px;overflow:hidden}
.prog-bar-fill{height:100%;background:linear-gradient(90deg,#1e40af,#3b82f6);border-radius:99px;transition:width .4s ease}
.prog-pct{font-size:13px;font-weight:700;color:#1e40af;min-width:38px;text-align:right}
.prog-steps{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.prog-step{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:8px;font-size:13px;font-weight:500;color:#9ca3af;background:#fafafa;border:1px solid #f0f0f0;transition:all .3s}
.prog-step.active{color:#1e40af;background:#eff6ff;border-color:#bfdbfe;font-weight:700}
.prog-step.done{color:#166534;background:#f0fdf4;border-color:#bbf7d0}

/* ── Result banner ────────────────────────────────────────────────────── */
.result-card{border-radius:12px;padding:24px 28px;margin-bottom:20px}
.result-success{background:#f0fdf4;border:1px solid #bbf7d0;border-left:5px solid #166534}
.result-error{background:#fef2f2;border:1px solid #fecaca;border-left:5px solid #ef4444}
.result-title{font-size:16px;font-weight:700;display:flex;align-items:center;gap:10px;margin:0 0 14px}
.result-stats{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.res-stat{flex:1;min-width:100px;border-radius:8px;padding:14px;text-align:center}
.res-stat-val{font-size:26px;font-weight:800;display:block}
.res-stat-lbl{font-size:11px;font-weight:600;display:block;margin-top:2px}
.res-green{background:#dcfce7;color:#166534}
.res-red{background:#fee2e2;color:#991b1b}
.res-blue{background:#dbeafe;color:#1e40af}
.result-actions{display:flex;gap:10px}
.err-detail{background:#fff;border:1px solid #fecaca;border-radius:8px;padding:14px 16px;margin-top:12px;font-size:13px;color:#991b1b;font-family:monospace;max-height:140px;overflow-y:auto;white-space:pre-wrap;word-break:break-all}
@media(max-width:600px){.form-grid{grid-template-columns:1fr}.prog-steps{grid-template-columns:1fr}}
</style>

<script>
// ── Drop zone ──────────────────────────────────────────────────────────────
const dz=document.getElementById('dropZone'),fi=document.getElementById('fileInput'),
      dc=document.getElementById('dropContent'),fc=document.getElementById('fileChosen'),
      fn=document.getElementById('fileChosenName'),fs=document.getElementById('fileChosenSize');

fi.addEventListener('change',()=>fi.files[0]&&showFile(fi.files[0]));
dz.addEventListener('dragover',e=>{e.preventDefault();dz.classList.add('drag-over');});
dz.addEventListener('dragleave',e=>{if(!dz.contains(e.relatedTarget))dz.classList.remove('drag-over');});
dz.addEventListener('drop',e=>{
    e.preventDefault();dz.classList.remove('drag-over');
    const f=e.dataTransfer.files[0];
    if(f){const dt=new DataTransfer();dt.items.add(f);fi.files=dt.files;showFile(f);}
});
function showFile(f){
    fn.textContent=f.name;
    fs.textContent=(f.size/1024).toFixed(1)+' KB';
    dc.style.display='none';fc.style.display='flex';
}
function clearFile(e){
    e.stopPropagation();fi.value='';
    dc.style.display='';fc.style.display='none';
}

// ── Progress helpers ───────────────────────────────────────────────────────
function setProgress(pct, stepLabel, activeStep){
    document.getElementById('progBarFill').style.width = pct+'%';
    document.getElementById('progPct').textContent = pct+'%';
    document.getElementById('progStepLabel').textContent = stepLabel;
    ['step1','step2','step3','step4'].forEach((id,i)=>{
        const el=document.getElementById(id);
        el.className='prog-step';
        if(i+1<activeStep) el.classList.add('done');
        else if(i+1===activeStep) el.classList.add('active');
    });
}

// ── AJAX submit ────────────────────────────────────────────────────────────
document.getElementById('uploadForm').addEventListener('submit', function(e){
    e.preventDefault();

    if(!fi.files[0]){
        showBanner('error','<i class="fa-solid fa-circle-xmark"></i> No file selected','Please choose an Excel file before importing.');
        return;
    }

    // Show overlay
    document.getElementById('progressOverlay').style.display='flex';
    document.getElementById('uploadCard').style.opacity='.4';
    document.getElementById('submitBtn').disabled=true;
    setProgress(5,'Uploading file…',1);

    const fd = new FormData(this);
    fd.append('ajax_import','1');

    const xhr = new XMLHttpRequest();

    // Upload progress (0→40%)
    xhr.upload.addEventListener('progress', function(ev){
        if(ev.lengthComputable){
            const pct = Math.round((ev.loaded/ev.total)*35)+5;
            setProgress(pct,'Uploading file… ('+Math.round((ev.loaded/ev.total)*100)+'%)',1);
        }
    });

    xhr.upload.addEventListener('load', function(){
        setProgress(42,'Parsing Excel sheet…',2);
    });

    xhr.addEventListener('readystatechange', function(){
        if(xhr.readyState===3){
            // Streaming: parse partial JSON for progress updates
            tryParseProgress(xhr.responseText);
        }
    });

    xhr.addEventListener('load', function(){
        document.getElementById('progressOverlay').style.display='none';
        document.getElementById('uploadCard').style.opacity='1';
        document.getElementById('submitBtn').disabled=false;

        let res;
        try{
            const text = xhr.responseText || '';
            // Server wraps final result as: @@RESULT@@{...json...}@@END@@
            const marker = text.match(/@@RESULT@@([\s\S]+?)@@END@@/);
            if(marker){
                res = JSON.parse(marker[1].trim());
            } else {
                // Fallback: try each line as JSON, take the last valid one
                const lines = text.split('\n').reverse();
                for(const line of lines){
                    const t = line.trim();
                    if(t.startsWith('{') && t.endsWith('}')){
                        try{ res = JSON.parse(t); break; }catch(e){}
                    }
                }
            }
            if(!res) throw new Error('No JSON found in response');
        } catch(err){
            showBanner('error','<i class="fa-solid fa-circle-xmark"></i> Unexpected server response',
                'The server returned an unexpected response. Raw output:\n'+(xhr.responseText||'(empty — possible PHP fatal error or timeout)').substring(0,800));
            return;
        }

        if(res.status==='ok'){
            setProgress(100,'Done!',4);
            showBanner('success',
                '<i class="fa-solid fa-circle-check"></i> Import Complete',
                null, res);
            // Reset form
            fi.value='';dc.style.display='';fc.style.display='none';
        } else {
            showBanner('error','<i class="fa-solid fa-circle-xmark"></i> Import Failed',res.error||'Unknown error.');
        }
    });

    xhr.addEventListener('error', function(){
        document.getElementById('progressOverlay').style.display='none';
        document.getElementById('uploadCard').style.opacity='1';
        document.getElementById('submitBtn').disabled=false;
        showBanner('error','<i class="fa-solid fa-circle-xmark"></i> Network Error','Could not reach the server. Check your connection and try again.');
    });

    xhr.open('POST','import_damage_proposal_ajax.php');
    xhr.send(fd);
});

let lastProgressStage = 0;
function tryParseProgress(text){
    // Look for {"progress":N} tokens streamed by the server
    const matches = text.match(/\{"progress":(\d+),"stage":"([^"]+)"\}/g);
    if(!matches) return;
    const last = matches[matches.length-1];
    const m = last.match(/\{"progress":(\d+),"stage":"([^"]+)"\}/);
    if(!m) return;
    const pct = parseInt(m[1]), stage = m[2];
    if(pct > lastProgressStage){
        lastProgressStage = pct;
        if(pct < 50)      setProgress(pct, stage, 2);
        else if(pct < 90) setProgress(pct, stage, 3);
        else               setProgress(pct, stage, 3);
    }
}

function showBanner(type, title, msg, res){
    const div = document.getElementById('resultBanner');
    if(type==='success' && res){
        div.innerHTML = `
        <div class="result-card result-success">
            <div class="result-title">${title}</div>
            <div class="result-stats">
                <div class="res-stat res-green"><span class="res-stat-val">${res.ok.toLocaleString()}</span><span class="res-stat-lbl">Records Imported</span></div>
                ${res.fail>0?`<div class="res-stat res-red"><span class="res-stat-val">${res.fail.toLocaleString()}</span><span class="res-stat-lbl">Records Failed</span></div>`:''}
                <div class="res-stat res-blue"><span class="res-stat-val">${(res.ok+res.fail).toLocaleString()}</span><span class="res-stat-lbl">Total Rows</span></div>
            </div>
            <div class="result-actions">
                <a href="damage_proposal_history.php" class="btn btn-secondary"><i class="fa-solid fa-history"></i> Import History</a>
                <a href="damage_proposal_view.php?id=${res.import_id}" class="btn btn-primary"><i class="fa-solid fa-eye"></i> View This Import</a>
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
    div.style.display='block';
    div.scrollIntoView({behavior:'smooth',block:'start'});
}
function escHtml(s){return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
</script>
<?php include 'footer.php'; ?>
