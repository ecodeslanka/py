<?php
include 'config.php';
include 'header.php';

// Allow deep-linking from gse_list.php (e.g. "View" on a delivery date row) —
// ?delivery_date=YYYY-MM-DD pre-fills the date and auto-loads the same
// short/excess list/theory used on shortage.php, no extra click needed.
$incoming_delivery_date = '';
if (!empty($_GET['delivery_date'])) {
    $ts = strtotime($_GET['delivery_date']);
    if ($ts) $incoming_delivery_date = date('Y-m-d', $ts);
}
?>
<!-- SheetJS for Excel import -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<style>
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:0;color:#1f2937;display:flex;align-items:center;gap:8px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}

.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;font-family:'Inter',sans-serif;text-decoration:none;white-space:nowrap}
.btn-sm{padding:7px 14px;font-size:13px}
.btn-xs{padding:4px 9px;font-size:12px}
.btn:disabled{opacity:.5;cursor:not-allowed}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}
.btn-primary{background:#2563eb;color:#fff}
.btn-primary:hover{background:#1d4ed8}
.btn-success{background:#16a34a;color:#fff}
.btn-success:hover{background:#15803d}
.btn-excel{background:#166534;color:#fff}
.btn-excel:hover{background:#14532d}
.btn-purple{background:#7c3aed;color:#fff}
.btn-purple:hover{background:#6d28d9}

.date-bar{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap}
.form-group{margin-bottom:0}
.form-label{display:block;font-size:11px;font-weight:700;color:#6b7280;margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px}
.form-input{padding:9px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;outline:none;box-sizing:border-box;transition:border-color .2s}
.form-input:focus{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.08)}

.stat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:4px}
@media(max-width:900px){.stat-grid{grid-template-columns:repeat(2,1fr)}}
.stat-box{background:#f9fafb;border:1px solid #e5e5e5;border-radius:8px;padding:12px 14px;text-align:center}
.stat-box.short{background:#fef2f2;border-color:#fca5a5}
.stat-box.excess{background:#f0fdf4;border-color:#86efac}
.stat-box.recon{background:#f0fdf4;border-color:#86efac}
.stat-box.unrecon{background:#fef2f2;border-color:#fca5a5}
.stat-lbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;font-weight:700}
.stat-val{font-size:19px;font-weight:800;color:#1f2937}
.stat-box.short .stat-val{color:#dc2626}
.stat-box.excess .stat-val{color:#16a34a}
.stat-box.recon .stat-val{color:#16a34a}
.stat-box.unrecon .stat-val{color:#dc2626}

.data-table{width:100%;border-collapse:collapse;font-size:12.5px}
.data-table th{background:#fafaf9;border-bottom:1px solid #e5e5e5;padding:9px 12px;text-align:left;font-weight:700;color:#374151;font-size:11px;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
.data-table th.num,.data-table td.num{text-align:right}
.data-table td{padding:8px 12px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
.data-table tbody tr:hover td{background:#fafafa}
.table-wrap{overflow-x:auto;border:1px solid #e5e5e5;border-radius:8px}

.short{color:#dc2626;font-weight:700}
.excess{color:#16a34a;font-weight:700}
.zero{color:#6b7280;font-weight:600}
.mono{font-family:'JetBrains Mono',monospace;font-size:11.5px}

.filter-btns{display:flex;gap:6px;flex-wrap:wrap}
.flt{padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid transparent;transition:all .2s;font-family:'Inter',sans-serif}
.flt-all{background:#f5f5f5;color:#333;border-color:#e5e5e5}
.flt-all.active{background:#000;color:#fff;border-color:#000}
.flt-short{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.flt-short.active{background:#dc2626;color:#fff;border-color:#dc2626}
.flt-excess{background:#f0fdf4;color:#166634;border-color:#bbf7d0}
.flt-excess.active{background:#16a34a;color:#fff;border-color:#16a34a}
.flt-both{background:#eff6ff;color:#1e40af;border-color:#bfdbfe}
.flt-both.active{background:#1e40af;color:#fff;border-color:#1e40af}
.data-table tbody tr.hidden-row{display:none}
#baseRowCount,#resultRowCount{font-size:12px;color:#6b7280;margin-left:4px;font-weight:600}

.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:10px;font-size:10.5px;font-weight:700;white-space:nowrap}
.badge-ok{background:#dcfce7;color:#166534;border:1px solid #86efac}
.badge-bad{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.badge-source{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.badge-source.base_only{background:#fef9c3;color:#854d0e;border-color:#fde047}
.badge-source.excel_only{background:#f5f3ff;color:#6d28d9;border-color:#ddd6fe}

.upload-zone{border:2px dashed #d1d5db;border-radius:10px;padding:22px;text-align:center;background:#fafafa;cursor:pointer;transition:all .2s}
.upload-zone:hover{border-color:#7c3aed;background:#f5f3ff}
.upload-zone i{font-size:26px;color:#7c3aed;margin-bottom:8px;display:block}
.upload-zone .uz-title{font-size:14px;font-weight:600;color:#1f2937;margin-bottom:3px}
.upload-zone .uz-sub{font-size:12px;color:#9ca3af}
.upload-zone.has-file{border-color:#16a34a;background:#f0fdf4}
.upload-zone.has-file i{color:#16a34a}

.empty-state{text-align:center;padding:40px 20px;color:#9ca3af}
.empty-state i{font-size:32px;margin-bottom:10px;display:block;opacity:.5}

#toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;color:#fff;z-index:2000;display:none;box-shadow:0 8px 24px rgba(0,0,0,.18)}
#toast.success{background:#16a34a}
#toast.error{background:#dc2626}

.section-divider{display:flex;align-items:center;gap:10px;margin:22px 0 14px;color:#9ca3af;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
.section-divider::before,.section-divider::after{content:'';flex:1;height:1px;background:#e5e5e5}

.remark-help{font-size:11.5px;color:#6b7280;background:#f9fafb;border:1px solid #e5e5e5;border-radius:6px;padding:8px 12px;margin-top:8px;line-height:1.6}
.remark-help b{color:#374151}

.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:none;align-items:center;justify-content:center;padding:16px}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:12px;padding:24px 26px;width:96%;max-width:720px;box-shadow:0 20px 60px rgba(0,0,0,.2);max-height:90vh;overflow-y:auto}
.modal-title{font-size:17px;font-weight:700;color:#1f2937;margin-bottom:4px;display:flex;align-items:center;gap:8px}
.modal-sub{font-size:13px;color:#6b7280;margin-bottom:18px}
.modal-close{position:absolute;top:18px;right:20px;background:none;border:none;cursor:pointer;color:#9ca3af;font-size:20px;line-height:1;padding:4px;transition:color .2s}
.modal-close:hover{color:#1f2937}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;padding-top:16px;border-top:1px solid #e5e5e5}
</style>

<div class="content-card">
    <div class="card-header-row">
        <div class="card-title"><i class="fa-solid fa-scale-balanced"></i> Delivery Date Reconciliation</div>
        <div id="dateStatusBadge"></div>
    </div>

    <div class="date-bar">
        <div class="form-group">
            <label class="form-label">Delivery Date</label>
            <input type="date" id="deliveryDate" class="form-input">
        </div>
        <button class="btn btn-primary btn-sm" onclick="loadBaseData()">
            <i class="fa-solid fa-magnifying-glass"></i> Load Preview
        </button>
        <button class="btn btn-secondary btn-sm" onclick="loadSavedReconciliation()">
            <i class="fa-solid fa-clock-rotate-left"></i> View Saved Reconciliation
        </button>
        <button class="btn btn-excel btn-sm" onclick="openReconcileModal()">
            <i class="fa-solid fa-file-excel"></i> Reconcile
        </button>
        <div style="flex:1"></div>
        <select id="quickDates" class="form-input" style="min-width:220px" onchange="if(this.value){document.getElementById('deliveryDate').value=this.value; loadBaseData();}">
            <option value="">— Dates with data —</option>
        </select>
    </div>
</div>

<!-- ── BASE (SYSTEM) PREVIEW ─────────────────────────────────────── -->
<div class="content-card" id="basePreviewCard" style="display:none">
    <div class="card-header-row">
        <div class="card-title"><i class="fa-solid fa-database"></i> System Short / Excess — <span id="baseDateLabel"></span> <span id="baseRowCount"></span></div>
        <div class="filter-btns">
            <button class="flt flt-all active" onclick="setBaseFilter('all',this)">All</button>
            <button class="flt flt-short" onclick="setBaseFilter('short',this)"><i class="fa-solid fa-arrow-down"></i> Short</button>
            <button class="flt flt-excess" onclick="setBaseFilter('excess',this)"><i class="fa-solid fa-arrow-up"></i> Excess</button>
            <button class="flt flt-both" onclick="setBaseFilter('both',this)"><i class="fa-solid fa-arrows-up-down"></i> Both</button>
        </div>
    </div>

    <div class="stat-grid" style="margin-bottom:16px">
        <div class="stat-box short"><div class="stat-lbl">Short Items</div><div class="stat-val" id="st_shortItems">0</div></div>
        <div class="stat-box excess"><div class="stat-lbl">Excess Items</div><div class="stat-val" id="st_excessItems">0</div></div>
        <div class="stat-box short"><div class="stat-lbl">Total Short Qty</div><div class="stat-val" id="st_totalShort">0.00</div></div>
        <div class="stat-box excess"><div class="stat-lbl">Total Excess Qty</div><div class="stat-val" id="st_totalExcess">0.00</div></div>
        <div class="stat-box"><div class="stat-lbl">Net</div><div class="stat-val" id="st_net">0.00</div></div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>SKU Code</th><th>SKU Desc</th><th class="num">Lines</th><th class="num">System Short/Excess</th>
            </tr></thead>
            <tbody id="baseTableBody"></tbody>
        </table>
    </div>
</div>

<!-- ── EXCEL UPLOAD & RECONCILE MODAL ───────────────────────────────── -->
<div class="modal-overlay" id="reconcileModal">
    <div class="modal-box" style="position:relative">
        <button class="modal-close" onclick="closeReconcileModal()"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-title"><i class="fa-solid fa-file-excel"></i> Upload Excel &amp; Reconcile</div>
        <div class="modal-sub">Delivery Date: <b id="modalDateLabel">—</b></div>

        <div class="upload-zone" id="uploadZone" onclick="document.getElementById('excelFile').click()">
            <i class="fa-solid fa-cloud-arrow-up"></i>
            <div class="uz-title" id="uzTitle">Click to select excel file (.xlsx / .xls / .csv)</div>
            <div class="uz-sub" id="uzSub">Works with LeverEDGE Stock Adjustment exports or a flat Item Code / Item Name / Remark / Qty sheet</div>
        </div>
        <input type="file" id="excelFile" accept=".xlsx,.xls,.csv" style="display:none" onchange="handleExcelFile(this.files[0])">

        <div class="remark-help">
            <b>How rows are read:</b> the header row is auto-detected anywhere in the sheet (title/filter rows above it are skipped).
            A row whose <b>Transaction</b> value contains <b>"Open"</b> (e.g. "Opening Stock") is treated as an <span class="excess">EXCESS</span> quantity.
            A row containing <b>"Subtract"</b> or <b>"Short"</b> (e.g. "Stock Subtraction") is treated as a <span class="short">SHORT</span> quantity.
            Other transaction types (Market Return, Sales Returns, etc.) are skipped — they aren't part of the short/excess rule.
            The real delivery date is read from the <b>Remarks</b> text (e.g. "2026-07-25 Delivery Shorts") when present, since the report's own Transaction Date column is usually just the processing date — rows for a different date than <b id="modalDateLabel2"></b> are skipped.
            Matching to the system is by <b>SKU / Item Code</b> only.
        </div>

        <div id="dateSuggestWrap" style="display:none;margin-top:12px;background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:10px 14px">
            <div style="font-size:12px;font-weight:700;color:#854d0e;margin-bottom:8px">
                <i class="fa-solid fa-triangle-exclamation"></i> No rows for the selected date — this file actually has rows for:
            </div>
            <div id="dateSuggestButtons" style="display:flex;gap:6px;flex-wrap:wrap"></div>
        </div>

        <div id="excelPreviewWrap" style="display:none;margin-top:16px">
            <div class="card-header-row">
                <div style="font-size:13px;font-weight:700;color:#374151"><i class="fa-solid fa-list"></i> Parsed Excel Rows (<span id="excelRowCount">0</span>)</div>
            </div>
            <div class="table-wrap" style="max-height:260px;overflow-y:auto">
                <table class="data-table">
                    <thead><tr><th>Item Code</th><th>Item Name</th><th>Remark</th><th class="num">Qty</th></tr></thead>
                    <tbody id="excelTableBody"></tbody>
                </table>
            </div>
        </div>

        <div class="modal-actions">
            <button class="btn btn-secondary btn-sm" onclick="closeReconcileModal()">Cancel</button>
            <button class="btn btn-excel btn-sm" id="reconcileBtn" onclick="runReconcile()">
                <i class="fa-solid fa-eye"></i> Preview Reconciliation
            </button>
        </div>
    </div>
</div>

<!-- ── RECONCILIATION RESULTS ───────────────────────────────────── -->
<div class="content-card" id="resultCard" style="display:none">
    <div class="card-header-row">
        <div class="card-title"><i class="fa-solid fa-check-double"></i> Reconciliation Result — <span id="resultDateLabel"></span> <span id="resultModeBadge"></span> <span id="resultRowCount"></span></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <div class="filter-btns">
                <button class="flt flt-all active" onclick="setResultFilter('all',this)">All</button>
                <button class="flt flt-short" onclick="setResultFilter('short',this)"><i class="fa-solid fa-arrow-down"></i> Short</button>
                <button class="flt flt-excess" onclick="setResultFilter('excess',this)"><i class="fa-solid fa-arrow-up"></i> Excess</button>
                <button class="flt flt-both" onclick="setResultFilter('both',this)"><i class="fa-solid fa-arrows-up-down"></i> Both</button>
            </div>
            <button class="btn btn-success btn-sm" id="saveReconBtn" style="display:none" onclick="saveReconciliation()">
                <i class="fa-solid fa-floppy-disk"></i> Save Reconciliation
            </button>
            <button class="btn btn-secondary btn-sm" onclick="exportResults()"><i class="fa-solid fa-file-arrow-down"></i> Export</button>
            <button class="btn btn-secondary btn-sm" id="deleteReconBtn" style="display:none;color:#dc2626" onclick="deleteReconciliation()">
                <i class="fa-solid fa-trash"></i> Delete Reconciliation
            </button>
        </div>
    </div>

    <div class="stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:16px">
        <div class="stat-box"><div class="stat-lbl">Total Items</div><div class="stat-val" id="rs_total">0</div></div>
        <div class="stat-box recon"><div class="stat-lbl">Reconciled</div><div class="stat-val" id="rs_reconciled">0</div></div>
        <div class="stat-box unrecon"><div class="stat-lbl">Unreconciled</div><div class="stat-val" id="rs_unreconciled">0</div></div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>SKU Code</th><th>SKU Desc</th>
                <th class="num">System (Base)</th><th class="num">Excel</th><th class="num">Difference</th>
                <th>Source</th><th>Status</th><th>Remark</th><th></th>
            </tr></thead>
            <tbody id="resultTableBody"></tbody>
        </table>
    </div>
</div>

<div id="toast"></div>

<script>
let EXCEL_ROWS = [];
let CURRENT_DATE = '';
let LAST_EXCEL_FILE = null;
let activeBaseFilter = 'all';
let activeResultFilter = 'all';
let BASE_ROWS_TOTAL = 0;
let RESULT_ROWS_TOTAL = 0;

document.getElementById('deliveryDate').valueAsDate = new Date();
loadDatesList();

<?php if ($incoming_delivery_date): ?>
// Deep-linked from gse_list.php with a specific delivery date — load it immediately.
document.getElementById('deliveryDate').value = <?php echo json_encode($incoming_delivery_date); ?>;
loadBaseData();
<?php endif; ?>

function f2(v){ v = parseFloat(v); if(isNaN(v)) return '—'; return v.toFixed(2); }
function seClass(v){ v = parseFloat(v); if(isNaN(v)) return 'zero'; return v < 0 ? 'short' : (v > 0 ? 'excess' : 'zero'); }

function showToast(msg,type){
    const t=document.getElementById('toast');t.textContent=msg;t.className=type;t.style.display='block';
    clearTimeout(t._t);t._t=setTimeout(()=>t.style.display='none',3500);
}

async function loadDatesList(){
    try{
        const res = await fetch('api_reconcile_unloading.php?action=dates');
        const j = await res.json();
        if(!j.success) return;
        const sel = document.getElementById('quickDates');
        sel.innerHTML = '<option value="">— Dates with data —</option>';
        j.dates.forEach(d=>{
            const opt = document.createElement('option');
            opt.value = d.delivery_date;
            opt.textContent = `${d.delivery_date}  (Short:${d.short_items} / Excess:${d.excess_items})`;
            sel.appendChild(opt);
        });
    }catch(e){}
}

async function loadBaseData(){
    const dd = document.getElementById('deliveryDate').value;
    if(!dd){ showToast('Please select a delivery date.','error'); return; }
    CURRENT_DATE = dd;
    document.getElementById('resultCard').style.display='none';
    document.getElementById('excelPreviewWrap').style.display='none';
    document.getElementById('excelFile').value = '';
    const uz = document.getElementById('uploadZone');
    uz.classList.remove('has-file');
    document.getElementById('uzTitle').textContent = 'Click to select excel file (.xlsx / .xls / .csv)';
    document.getElementById('uzSub').textContent = 'Columns needed: Item Code, Item Name, Remark, Qty';
    EXCEL_ROWS = [];

    try{
        const res = await fetch('api_reconcile_unloading.php?action=base_data&delivery_date='+encodeURIComponent(dd));
        const j = await res.json();
        if(!j.success){ showToast(j.message||'Failed to load data.','error'); return; }

        document.getElementById('basePreviewCard').style.display='block';
        document.getElementById('baseDateLabel').textContent = j.delivery_date;

        document.getElementById('st_shortItems').textContent = j.totals.short_items;
        document.getElementById('st_excessItems').textContent = j.totals.excess_items;
        document.getElementById('st_totalShort').textContent = f2(j.totals.total_short);
        document.getElementById('st_totalExcess').textContent = f2(j.totals.total_excess);
        const netEl = document.getElementById('st_net'); netEl.textContent = f2(j.totals.net); netEl.className='stat-val '+seClass(j.totals.net);

        const tb = document.getElementById('baseTableBody');
        tb.innerHTML = '';
        BASE_ROWS_TOTAL = j.rows.length;
        if(j.rows.length===0){
            tb.innerHTML = '<tr><td colspan="4"><div class="empty-state"><i class="fa-solid fa-inbox"></i>No short/excess records for this date</div></td></tr>';
        } else {
            j.rows.forEach(r=>{
                const se = parseFloat(r.base_short_excess);
                tb.innerHTML += `<tr data-se="${isNaN(se)?'':se}">
                    <td class="mono">${escHtml(r.sku_code)}</td>
                    <td>${escHtml(r.sku_desc||'')}</td>
                    <td class="num">${r.line_count}</td>
                    <td class="num ${seClass(r.base_short_excess)}">${f2(r.base_short_excess)}</td>
                </tr>`;
            });
        }
        activeBaseFilter = 'all';
        document.querySelectorAll('#basePreviewCard .flt').forEach(b=>b.classList.remove('active'));
        document.querySelector('#basePreviewCard .flt-all').classList.add('active');
        applyBaseFilter();

        const badge = document.getElementById('dateStatusBadge');
        badge.innerHTML = j.already_reconciled
            ? '<span class="badge badge-source"><i class="fa-solid fa-circle-check"></i> Previously reconciled</span>'
            : '';
    }catch(e){ showToast('Error loading base data.','error'); }
}

function escHtml(s){
    return (s===null||s===undefined) ? '' : String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

// ── Base preview filter: All / Short / Excess / Both — same theory as shortage.php ──
function setBaseFilter(f, btn){
    activeBaseFilter = f;
    document.querySelectorAll('#basePreviewCard .flt').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    applyBaseFilter();
}
function applyBaseFilter(){
    const rows = document.querySelectorAll('#baseTableBody tr');
    let vis = 0;
    rows.forEach(row=>{
        if(row.dataset.se === undefined) return; // empty-state row, no data-se
        const se = row.dataset.se !== '' ? parseFloat(row.dataset.se) : null;
        let show = true;
        if(activeBaseFilter==='short')  show = se!==null && se<0;
        if(activeBaseFilter==='excess') show = se!==null && se>0;
        if(activeBaseFilter==='both')   show = se!==null && se!==0;
        row.classList.toggle('hidden-row', !show);
        if(show) vis++;
    });
    document.getElementById('baseRowCount').textContent = BASE_ROWS_TOTAL ? `(${vis} of ${BASE_ROWS_TOTAL})` : '';
}

// ── Result table filter: All / Short / Excess / Both — same theory ──────────
function setResultFilter(f, btn){
    activeResultFilter = f;
    document.querySelectorAll('#resultCard .filter-btns .flt').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    applyResultFilter();
}
function applyResultFilter(){
    const rows = document.querySelectorAll('#resultTableBody tr');
    let vis = 0;
    rows.forEach(row=>{
        if(row.dataset.se === undefined) return; // empty-state row
        const se = row.dataset.se !== '' ? parseFloat(row.dataset.se) : null;
        let show = true;
        if(activeResultFilter==='short')  show = se!==null && se<0;
        if(activeResultFilter==='excess') show = se!==null && se>0;
        if(activeResultFilter==='both')   show = se!==null && se!==0;
        row.classList.toggle('hidden-row', !show);
        if(show) vis++;
    });
    document.getElementById('resultRowCount').textContent = RESULT_ROWS_TOTAL ? `(${vis} of ${RESULT_ROWS_TOTAL})` : '';
}

// ── RECONCILE MODAL ──────────────────────────────────────────────
function openReconcileModal(){
    const dd = document.getElementById('deliveryDate').value;
    if(!dd){ showToast('Please select and load a delivery date first.','error'); return; }
    if(!CURRENT_DATE){ showToast('Click "Load Preview" first to load system data for this date.','error'); return; }
    document.getElementById('modalDateLabel').textContent = CURRENT_DATE;
    document.getElementById('modalDateLabel2').textContent = CURRENT_DATE;
    document.getElementById('reconcileModal').classList.add('open');
}
function closeReconcileModal(){
    document.getElementById('reconcileModal').classList.remove('open');
}

// ── EXCEL UPLOAD & PARSE (client-side via SheetJS) ─────────────────
// Header row is auto-detected (LeverEDGE-style exports have a title block
// before the real header row), and columns are matched flexibly.
const HEADER_MAP = {
    code:    ['sku code','item code','code','sku_code','item_code','basepack code'],
    desc:    ['item name','sku desc','sku description','item desc','description','desc','name'],
    qty:     ['qty units','qty','quantity','amount'],
    type:    ['transaction','type'],              // category column: Opening Stock / Stock Subtraction
    remarks: ['remarks','remark','note'],          // free-text column: may embed the real delivery date
    date:    ['transaction date','delivery date','record date','date']
};

function findHeaderRow(aoa){
    let best = {idx:-1, score:-1, cols:null};
    const scanRows = Math.min(aoa.length, 25);
    for(let i=0;i<scanRows;i++){
        const row = aoa[i];
        if(!row || !row.length) continue;
        const cols = {};
        let score = 0;
        row.forEach((cell,ci)=>{
            const c = String(cell||'').trim().toLowerCase();
            if(!c) return;
            for(const field in HEADER_MAP){
                if(cols[field] !== undefined) continue;
                if(HEADER_MAP[field].includes(c)){ cols[field] = ci; score++; break; }
            }
        });
        // need at minimum a code column + a qty column to treat this as the header row
        if(cols.code !== undefined && cols.qty !== undefined && score > best.score){
            best = {idx:i, score, cols};
        }
    }
    return best.idx >= 0 ? best : null;
}

function excelDateToStr(v){
    if(!v) return '';
    if(v instanceof Date) return v.toISOString().slice(0,10);
    const s = String(v).trim();
    // try to pull a yyyy-mm-dd out of free text like "  2026-07-25 Delivery Shorts  "
    const m = s.match(/(\d{4})-(\d{2})-(\d{2})/);
    if(m) return m[0];
    const ts = Date.parse(s);
    return isNaN(ts) ? '' : new Date(ts).toISOString().slice(0,10);
}

// Extracts a yyyy-mm-dd date embedded in free text (e.g. "2026-07-25 Delivery Shorts").
// Returns '' if no date pattern is present.
function extractDateFromText(v){
    if(!v) return '';
    const s = String(v).trim();
    const m = s.match(/(\d{4})-(\d{2})-(\d{2})/);
    return m ? m[0] : '';
}

function handleExcelFile(file){
    if(!file) return;
    if(!CURRENT_DATE){ showToast('Select and load a delivery date first.','error'); return; }
    LAST_EXCEL_FILE = file;

    const reader = new FileReader();
    reader.onload = function(e){
        try{
            const data = new Uint8Array(e.target.result);
            const wb = XLSX.read(data, {type:'array', cellDates:true});
            const ws = wb.Sheets[wb.SheetNames[0]];
            const aoa = XLSX.utils.sheet_to_json(ws, {header:1, defval:'', raw:true});

            const hdr = findHeaderRow(aoa);
            if(!hdr){
                showToast('Could not find a recognizable header row (need at least a SKU/Item Code column and a Qty column).','error');
                return;
            }
            const cols = hdr.cols;

            const parsed = [];
            let excludedType = 0, excludedDate = 0, excludedBlank = 0;
            const datesFound = new Set();

            for(let i = hdr.idx + 1; i < aoa.length; i++){
                const row = aoa[i];
                if(!row || !row.length) continue;

                const rawJoined = row.map(c=>String(c||'').trim().toLowerCase()).join(' ');
                if(rawJoined.includes('grand total')) break; // trailer row - stop

                const code = String(row[cols.code] ?? '').trim();
                if(code === ''){ continue; }

                const desc = cols.desc !== undefined ? String(row[cols.desc] ?? '').trim() : '';
                let qty = cols.qty !== undefined ? row[cols.qty] : 0;
                qty = parseFloat(String(qty).replace(/,/g,'')) || 0;
                if(qty === 0){ excludedBlank++; continue; }

                const typeRaw = cols.type !== undefined ? String(row[cols.type] ?? '').trim()
                              : (cols.remarks !== undefined ? String(row[cols.remarks] ?? '').trim() : '');
                const typeLc = typeRaw.toLowerCase();

                let category;
                if(typeLc.includes('open')) category = 'excess';
                else if(typeLc.includes('subtract') || typeLc.includes('short')) category = 'short';
                else category = 'excluded'; // e.g. Market Return / Sales Returns - not part of short/excess rule

                if(category === 'excluded'){ excludedType++; continue; }

                // Determine the row's real delivery date. Prefer a date embedded in the
                // free-text Remarks column (e.g. "2026-07-25 Delivery Shorts") since the
                // Transaction Date column is usually just the report's processing date.
                let rowDateStr = '';
                if(cols.remarks !== undefined) rowDateStr = extractDateFromText(row[cols.remarks]);
                if(!rowDateStr && cols.date !== undefined) rowDateStr = excelDateToStr(row[cols.date]);
                if(rowDateStr) datesFound.add(rowDateStr);
                if(rowDateStr && rowDateStr !== CURRENT_DATE){ excludedDate++; continue; }

                const remarksText = cols.remarks !== undefined ? String(row[cols.remarks] ?? '').trim() : '';
                parsed.push({
                    sku_code: code,
                    sku_desc: desc,
                    remark: typeRaw || (category==='excess' ? 'Opening Stock' : 'Short'),
                    qty: qty,
                    _category: category,
                    _remarksText: remarksText
                });
            }

            EXCEL_ROWS = parsed;

            document.getElementById('uploadZone').classList.add('has-file');
            document.getElementById('uzTitle').textContent = file.name;
            let subMsg = EXCEL_ROWS.length + ' rows ready to reconcile';
            const excludedTotal = excludedType + excludedDate + excludedBlank;
            if(excludedTotal > 0){
                const parts = [];
                if(excludedType) parts.push(excludedType + ' non short/excess type (Market Return / Sales Return etc.)');
                if(excludedDate) parts.push(excludedDate + ' different date');
                if(excludedBlank) parts.push(excludedBlank + ' zero qty');
                subMsg += ' — ' + excludedTotal + ' rows skipped: ' + parts.join(', ');
            }
            document.getElementById('uzSub').textContent = subMsg;

            if(EXCEL_ROWS.length === 0){
                const sortedDates = Array.from(datesFound).sort();
                if(sortedDates.length > 0){
                    showToast('No rows for '+CURRENT_DATE+'. This file has rows for: '+sortedDates.join(', ')+'. Pick one below.','error');
                    renderDateSuggestions(sortedDates);
                } else if(excludedType > 0 && excludedDate === 0){
                    showToast('This file only has Market Return / Sales Return rows — no Opening Stock or Stock Subtraction rows found.','error');
                } else {
                    showToast('No matching rows found — check the delivery date and that the file has Opening Stock / Stock Subtraction rows.','error');
                }
            } else {
                document.getElementById('dateSuggestWrap').style.display = 'none';
            }
            renderExcelPreview();
        }catch(err){
            showToast('Could not parse excel file: '+err.message,'error');
        }
    };
    reader.readAsArrayBuffer(file);
}

function renderDateSuggestions(dates){
    const wrap = document.getElementById('dateSuggestWrap');
    const box = document.getElementById('dateSuggestButtons');
    box.innerHTML = '';
    dates.forEach(d=>{
        const b = document.createElement('button');
        b.className = 'btn btn-secondary btn-xs';
        b.textContent = d;
        b.onclick = () => pickSuggestedDate(d);
        box.appendChild(b);
    });
    wrap.style.display = 'block';
}

async function pickSuggestedDate(d){
    document.getElementById('deliveryDate').value = d;
    await loadBaseData();
    document.getElementById('reconcileModal').classList.add('open');
    document.getElementById('modalDateLabel').textContent = CURRENT_DATE;
    document.getElementById('modalDateLabel2').textContent = CURRENT_DATE;
    if(LAST_EXCEL_FILE) handleExcelFile(LAST_EXCEL_FILE);
}

function renderExcelPreview(){
    const wrap = document.getElementById('excelPreviewWrap');
    wrap.style.display = EXCEL_ROWS.length ? 'block' : 'none';
    document.getElementById('excelRowCount').textContent = EXCEL_ROWS.length;
    const tb = document.getElementById('excelTableBody');
    tb.innerHTML = '';
    EXCEL_ROWS.forEach(r=>{
        const isOpen = r._category === 'excess';
        const remarkDisplay = r._remarksText ? escHtml(r._remarksText) : escHtml(r.remark||'—');
        tb.innerHTML += `<tr>
            <td class="mono">${escHtml(r.sku_code)}</td>
            <td>${escHtml(r.sku_desc)}</td>
            <td>${remarkDisplay} ${isOpen?'<span class="badge badge-ok">Excess</span>':'<span class="badge badge-bad">Short</span>'}</td>
            <td class="num">${f2(r.qty)}</td>
        </tr>`;
    });
}

// ── RECONCILE (PREVIEW ONLY — nothing saved yet) ──────────────────
async function runReconcile(){
    if(!CURRENT_DATE){ showToast('Select a delivery date first.','error'); return; }
    if(EXCEL_ROWS.length===0){ showToast('Upload an excel file first.','error'); return; }

    const btn = document.getElementById('reconcileBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Comparing...';

    try{
        const res = await fetch('api_reconcile_unloading.php', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'reconcile_preview', delivery_date: CURRENT_DATE, rows: EXCEL_ROWS})
        });
        const j = await res.json();
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-eye"></i> Preview Reconciliation';

        if(!j.success){ showToast(j.message||'Preview failed.','error'); return; }

        closeReconcileModal();
        renderResults(j.delivery_date, j.rows, j.summary, {mode:'preview'});
        showToast(`Preview ready: ${j.summary.reconciled} matched, ${j.summary.unreconciled} unreconciled. Not saved yet — click "Save Reconciliation" to persist.`, j.summary.unreconciled>0 ? 'error' : 'success');
    }catch(e){
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-eye"></i> Preview Reconciliation';
        showToast('Error during reconciliation preview.','error');
    }
}

// ── SAVE the previewed reconciliation to the database ─────────────
async function saveReconciliation(){
    if(!CURRENT_DATE){ showToast('No delivery date selected.','error'); return; }
    if(EXCEL_ROWS.length===0){ showToast('Preview a reconciliation first before saving.','error'); return; }

    const btn = document.getElementById('saveReconBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

    try{
        const res = await fetch('api_reconcile_unloading.php', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'reconcile_save', delivery_date: CURRENT_DATE, rows: EXCEL_ROWS})
        });
        const j = await res.json();
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Reconciliation';

        if(!j.success){ showToast(j.message||'Save failed.','error'); return; }

        renderResults(j.delivery_date, j.rows, j.summary, {mode:'saved'});
        showToast(`Saved ${j.summary.saved} row(s) for ${j.delivery_date}.`, 'success');
        loadDatesList();
    }catch(e){
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Reconciliation';
        showToast('Error saving reconciliation.','error');
    }
}

// ── DELETE the entire saved reconciliation for the current date ───
async function deleteReconciliation(){
    if(!CURRENT_DATE){ showToast('No delivery date selected.','error'); return; }
    if(!confirm(`Delete the saved reconciliation for ${CURRENT_DATE}? This cannot be undone.`)) return;

    const btn = document.getElementById('deleteReconBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting...';

    try{
        const res = await fetch('api_reconcile_unloading.php?action=delete_reconciliation&delivery_date='+encodeURIComponent(CURRENT_DATE), {
            method:'POST'
        });
        const j = await res.json();
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete Reconciliation';

        if(!j.success){ showToast(j.message||'Delete failed.','error'); return; }

        showToast(`Deleted ${j.deleted} saved row(s) for ${j.delivery_date}.`, 'success');
        document.getElementById('resultCard').style.display = 'none';
        document.getElementById('resultTableBody').innerHTML = '';
        loadDatesList();
    }catch(e){
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete Reconciliation';
        showToast('Error deleting reconciliation.','error');
    }
}

async function loadSavedReconciliation(){
    const dd = document.getElementById('deliveryDate').value;
    if(!dd){ showToast('Please select a delivery date.','error'); return; }
    CURRENT_DATE = dd;
    try{
        const res = await fetch('api_reconcile_unloading.php?action=get_reconciled&delivery_date='+encodeURIComponent(dd));
        const j = await res.json();
        if(!j.success){ showToast(j.message||'Failed to load.','error'); return; }
        if(j.rows.length===0){ showToast('No saved reconciliation found for this date.','error'); return; }
        renderResults(j.delivery_date, j.rows, j.summary, {mode:'saved'});
    }catch(e){ showToast('Error loading saved reconciliation.','error'); }
}

function renderResults(dateLabel, rows, summary, opts){
    opts = opts || {};
    const mode = opts.mode || 'saved'; // 'preview' = not yet saved, 'saved' = persisted rows (has ids)

    document.getElementById('resultCard').style.display='block';
    document.getElementById('resultDateLabel').textContent = dateLabel;
    document.getElementById('rs_total').textContent = summary.total;
    document.getElementById('rs_reconciled').textContent = summary.reconciled;
    document.getElementById('rs_unreconciled').textContent = summary.unreconciled;

    const badge = document.getElementById('resultModeBadge');
    const saveBtn = document.getElementById('saveReconBtn');
    const delBtn = document.getElementById('deleteReconBtn');
    if(mode === 'preview'){
        badge.innerHTML = '<span class="badge badge-source excel_only"><i class="fa-solid fa-clock"></i> Preview — not saved</span>';
        saveBtn.style.display = 'inline-flex';
        delBtn.style.display = 'none';
    } else {
        badge.innerHTML = '<span class="badge badge-ok"><i class="fa-solid fa-circle-check"></i> Saved</span>';
        saveBtn.style.display = 'none';
        delBtn.style.display = 'inline-flex';
    }

    const tb = document.getElementById('resultTableBody');
    tb.innerHTML = '';
    RESULT_ROWS_TOTAL = rows.length;
    if(rows.length===0){
        tb.innerHTML = '<tr><td colspan="9"><div class="empty-state"><i class="fa-solid fa-inbox"></i>No reconciliation rows</div></td></tr>';
        document.getElementById('resultRowCount').textContent = '';
        return;
    }
    rows.forEach(r=>{
        const statusBadge = r.status==='reconciled'
            ? '<span class="badge badge-ok"><i class="fa-solid fa-check"></i> Reconciled</span>'
            : '<span class="badge badge-bad"><i class="fa-solid fa-triangle-exclamation"></i> Unreconciled</span>';
        const sourceLabel = {both:'Matched', base_only:'System Only', excel_only:'Excel Only'}[r.match_source] || r.match_source;
        let rowBtns = '';
        if(r.id){
            rowBtns += (r.status==='unreconciled')
                ? `<button class="btn btn-secondary btn-xs" onclick="overrideStatus(${r.id},'reconciled')">Mark OK</button>`
                : `<button class="btn btn-secondary btn-xs" onclick="overrideStatus(${r.id},'unreconciled')">Unmark</button>`;
            rowBtns += ` <button class="btn btn-secondary btn-xs" style="color:#dc2626" onclick="deleteRow(${r.id})" title="Delete this row"><i class="fa-solid fa-trash"></i></button>`;
        }
        // short/excess indicator for filtering: prefer system (base) value, fall back to excel value
        const seRaw = (r.base_short_excess!==null && r.base_short_excess!==undefined) ? r.base_short_excess : r.excel_short_excess;
        const se = parseFloat(seRaw);
        tb.innerHTML += `<tr data-se="${isNaN(se)?'':se}">
            <td class="mono">${escHtml(r.sku_code)}</td>
            <td>${escHtml(r.sku_desc||'')}</td>
            <td class="num ${seClass(r.base_short_excess)}">${f2(r.base_short_excess)}</td>
            <td class="num ${seClass(r.excel_short_excess)}">${f2(r.excel_short_excess)}</td>
            <td class="num ${seClass(r.difference)}">${f2(r.difference)}</td>
            <td><span class="badge badge-source ${r.match_source}">${sourceLabel}</span></td>
            <td>${statusBadge}</td>
            <td style="max-width:220px;font-size:11.5px;color:#6b7280">${escHtml(r.remark||'')}</td>
            <td style="white-space:nowrap">${rowBtns}</td>
        </tr>`;
    });
    activeResultFilter = 'all';
    document.querySelectorAll('#resultCard .filter-btns .flt').forEach(b=>b.classList.remove('active'));
    const allBtn = document.querySelector('#resultCard .filter-btns .flt-all');
    if(allBtn) allBtn.classList.add('active');
    applyResultFilter();
}

async function overrideStatus(id, status){
    try{
        const res = await fetch('api_reconcile_unloading.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'update_status', id, status})
        });
        const j = await res.json();
        if(j.success){ showToast('Status updated.','success'); loadSavedReconciliation(); }
        else showToast(j.message||'Update failed.','error');
    }catch(e){ showToast('Error updating status.','error'); }
}

// ── DELETE a single saved reconciliation row ───────────────────────
async function deleteRow(id){
    if(!confirm('Delete this reconciliation row?')) return;
    try{
        const res = await fetch('api_reconcile_unloading.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'delete_row', id})
        });
        const j = await res.json();
        if(j.success){ showToast('Row deleted.','success'); loadSavedReconciliation(); }
        else showToast(j.message||'Delete failed.','error');
    }catch(e){ showToast('Error deleting row.','error'); }
}

function exportResults(){
    const rows = [];
    document.querySelectorAll('#resultTableBody tr:not(.hidden-row)').forEach(tr=>{
        const cells = tr.querySelectorAll('td');
        if(cells.length < 8) return;
        rows.push({
            'SKU Code': cells[0].innerText,
            'SKU Desc': cells[1].innerText,
            'System (Base)': cells[2].innerText,
            'Excel': cells[3].innerText,
            'Difference': cells[4].innerText,
            'Source': cells[5].innerText,
            'Status': cells[6].innerText,
            'Remark': cells[7].innerText
        });
    });
    if(rows.length===0){ showToast('Nothing to export.','error'); return; }
    const ws = XLSX.utils.json_to_sheet(rows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Reconciliation');
    XLSX.writeFile(wb, 'reconciliation_' + (CURRENT_DATE||'export') + '.xlsx');
}
</script>

<?php include 'footer.php'; ?>