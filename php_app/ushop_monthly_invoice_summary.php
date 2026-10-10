<?php
include 'config.php';

/* ═══════════════════════════════════════════════════
   AUTO-CREATE TABLES
═══════════════════════════════════════════════════ */
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS ushop_monthly_summary_imports (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  year            INT NOT NULL,
  month           INT NOT NULL,
  filename        VARCHAR(255) NOT NULL,
  note            TEXT,
  location        VARCHAR(255),
  payment_types   JSON NOT NULL,
  total_days      INT DEFAULT 0,
  total_amount    DECIMAL(15,2) DEFAULT 0,
  imported_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_period (year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS ushop_monthly_summary_details (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  import_id       INT NOT NULL,
  summary_date    DATE NOT NULL,
  payments        JSON NOT NULL,
  day_total       DECIMAL(12,2) DEFAULT 0,
  FOREIGN KEY (import_id) REFERENCES ushop_monthly_summary_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.page-wrap{max-width:1300px;margin:0 auto;padding:0 8px;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:14px;flex-wrap:wrap;}
.breadcrumb a{color:#0e7490;text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-teal{background:#0e7490;color:#fff;}.btn-teal:hover{background:#155e75;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-green{background:#15803d;color:#fff;}.btn-green:hover{background:#166534;}
.btn-red{background:#dc2626;color:#fff;}.btn-red:hover{background:#b91c1c;}
.btn-sm{padding:5px 11px;font-size:12px;}
.btn:disabled{opacity:.5;cursor:not-allowed;}

/* Period selector */
.period-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 22px;margin-bottom:22px;display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;}
.period-card .pgroup{display:flex;flex-direction:column;gap:5px;}
.period-card label{font-size:11.5px;font-weight:700;color:#374151;text-transform:uppercase;}
.period-card input[type=month]{padding:9px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:14px;font-family:inherit;color:#111827;outline:none;transition:.15s;min-width:180px;}
.period-card input[type=month]:focus{border-color:#0e7490;box-shadow:0 0 0 3px rgba(14,116,144,.1);}
.period-hint{font-size:11.5px;color:#9ca3af;}
#periodStatus{font-size:12.5px;font-weight:700;}
#periodStatus.st-found{color:#15803d;}
#periodStatus.st-empty{color:#9ca3af;}
#periodStatus.st-loading{color:#0e7490;}

.upload-card{background:#fff;border:2px dashed #a5f3fc;border-radius:14px;padding:36px;text-align:center;cursor:pointer;transition:.2s;margin-bottom:22px;}
.upload-card:hover,.upload-card.drag{border-color:#0e7490;background:#f0fdff;}
.upload-card .icon{font-size:44px;color:#0e7490;margin-bottom:10px;}
.upload-card h3{margin:0 0 6px;font-size:16px;font-weight:700;color:#111827;}
.upload-card p{margin:0;font-size:12px;color:#6b7280;}
#fileInput{display:none;}

.form-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px 24px;margin-bottom:22px;}
.form-row{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;}
.form-group{display:flex;flex-direction:column;gap:5px;flex:1;min-width:200px;}
.form-group label{font-size:11.5px;font-weight:700;color:#374151;text-transform:uppercase;}
.form-group input,.form-group textarea,.form-group select{padding:9px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;font-family:inherit;color:#111827;outline:none;transition:.15s;}
.form-group input:focus,.form-group textarea:focus{border-color:#0e7490;box-shadow:0 0 0 3px rgba(14,116,144,.1);}

.preview-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px;}
.stat-pills{display:flex;flex-wrap:wrap;gap:8px;}
.stat-pill{background:#f0fdff;border:1px solid #a5f3fc;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;color:#0e7490;}
.stat-pill span{color:#374151;font-weight:400;}

.summary-table-wrap{border:1px solid #e5e7eb;border-radius:10px;overflow:auto;max-height:520px;margin-bottom:16px;background:#fff;}
.summary-table{width:100%;border-collapse:collapse;font-size:12px;}
.summary-table thead th{position:sticky;top:0;background:#f0fdff;padding:9px 12px;text-align:right;font-size:10.5px;text-transform:uppercase;color:#0e7490;border-bottom:1px solid #a5f3fc;white-space:nowrap;z-index:1;}
.summary-table thead th:first-child{text-align:left;}
.summary-table td{padding:7px 12px;border-bottom:1px solid #f3f4f6;text-align:right;white-space:nowrap;}
.summary-table td:first-child{text-align:left;font-weight:600;color:#111827;}
.summary-table tbody tr:hover{background:#fafafa;}
.summary-table tbody tr.mismatch{background:#fef9c3;}
.summary-table tfoot td{padding:9px 12px;font-weight:700;background:#f9fafb;border-top:2px solid #e5e7eb;color:#0e7490;}
.summary-table tfoot td:first-child{color:#374151;}

.alert{padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:16px;}
.alert-ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.alert-err{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;}
.parse-warning{background:#fef9c3;color:#854d0e;border:1px solid #fde047;border-radius:8px;padding:8px 14px;font-size:12px;margin-bottom:12px;}

#progressSection{display:none;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:22px;text-align:center;}
.progress-bar-wrap{background:#f3f4f6;border-radius:20px;height:12px;margin:16px 0 8px;overflow:hidden;}
.progress-bar-fill{height:100%;background:linear-gradient(90deg,#0e7490,#06b6d4);border-radius:20px;transition:width .3s;}
#progressMsg{font-size:13px;color:#6b7280;}

#emptyState{display:none;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:40px;text-align:center;color:#9ca3af;}
#emptyState i{font-size:32px;margin-bottom:10px;display:block;color:#cbd5e1;}

.meta-row{font-size:12px;color:#6b7280;margin-bottom:14px;display:flex;gap:18px;flex-wrap:wrap;}
.meta-row b{color:#374151;}
</style>

<div class="page-wrap">
<div class="breadcrumb">
  <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
  <span class="sep">›</span>
  <a href="ushop_items_import.php"><i class="fa-solid fa-boxes-stacked"></i> UShop Items Import</a>
  <span class="sep">›</span>
  <span style="color:#0e7490;font-weight:700;"><i class="fa-solid fa-calendar-days"></i> Monthly Invoice Summary</span>
</div>

<div id="mainMsg"></div>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
  <div>
    <h2 style="margin:0;font-size:19px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-calendar-days" style="color:#0e7490;margin-right:8px;"></i>UShop — Monthly Invoice Summary
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Import the Daily Sales Summary Excel for a month (Cash / Credit / Card breakdown). One import per month.</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="ushop_invoice_import.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-file-invoice"></i> Invoice Import</a>
    <a href="ushop_items_view.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-cubes"></i> Items</a>
    <a href="ushop_stock_history.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-layer-group"></i> Stock History</a>
  </div>
</div>

<!-- Period selector — single control for month + year -->
<div class="period-card">
  <div class="pgroup">
    <label><i class="fa-solid fa-calendar"></i> Select Month / Year</label>
    <input type="month" id="periodInput">
  </div>
  <div class="pgroup" style="flex:1;min-width:160px;">
    <span id="periodStatus">&nbsp;</span>
  </div>
</div>

<div id="workArea"></div>

<!-- Empty / no period chosen -->
<div id="emptyState">
  <i class="fa-solid fa-calendar-days"></i>
  Select a month above to view or import its sales summary.
</div>

<!-- Progress -->
<div id="progressSection">
  <i class="fa-solid fa-circle-notch fa-spin" style="color:#0e7490;font-size:24px;"></i>
  <div style="font-size:14px;font-weight:700;color:#111827;margin-top:10px;">Processing…</div>
  <div class="progress-bar-wrap"><div class="progress-bar-fill" id="progressBar" style="width:0%"></div></div>
  <div id="progressMsg">Preparing…</div>
</div>

</div><!-- .page-wrap -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
const API = 'api_ushop_monthly_summary.php';

let selectedYear = null, selectedMonth = null;
let parsedSummary = null;   // { location, paymentTypes, days, warnings }
let pendingFile = null;

const monthNames = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

/* ── Period change ── */
document.getElementById('periodInput').addEventListener('change', e => {
  const val = e.target.value; // "YYYY-MM"
  if (!val) { resetWork(); return; }
  const [y, m] = val.split('-');
  selectedYear = parseInt(y, 10);
  selectedMonth = parseInt(m, 10);
  loadPeriod();
});

// default to current month
(function initDefault(){
  const now = new Date();
  const val = now.getFullYear() + '-' + String(now.getMonth()+1).padStart(2,'0');
  document.getElementById('periodInput').value = val;
  document.getElementById('periodInput').dispatchEvent(new Event('change'));
})();

function resetWork(){
  document.getElementById('workArea').innerHTML = '';
  document.getElementById('emptyState').style.display = 'block';
  setStatus('', '');
  parsedSummary = null; pendingFile = null;
}

function setStatus(cls, text){
  const el = document.getElementById('periodStatus');
  el.className = cls;
  el.innerHTML = text || '&nbsp;';
}

/* ─────────────────────────────────────────────────
   Load period — checks if an import already exists
───────────────────────────────────────────────── */
function loadPeriod(){
  document.getElementById('emptyState').style.display = 'none';
  document.getElementById('workArea').innerHTML = '';
  setStatus('st-loading', '<i class="fa-solid fa-circle-notch fa-spin"></i> Checking…');

  fetch(`${API}?action=get_period&year=${selectedYear}&month=${selectedMonth}`)
    .then(r => r.json())
    .then(res => {
      if (!res.success) { showMsg('err', res.message || 'Failed to load period'); return; }
      if (res.exists) {
        setStatus('st-found', `<i class="fa-solid fa-circle-check"></i> Import found for ${monthNames[selectedMonth]} ${selectedYear}`);
        renderViewMode(res.import, res.days);
      } else {
        setStatus('st-empty', `<i class="fa-regular fa-circle"></i> No import yet for ${monthNames[selectedMonth]} ${selectedYear}`);
        renderImportMode();
      }
    })
    .catch(err => showMsg('err', 'Error: ' + err.message));
}

/* ─────────────────────────────────────────────────
   VIEW MODE — already imported
───────────────────────────────────────────────── */
function renderViewMode(imp, days){
  const paymentTypes = imp.payment_types || [];
  const stats = computeStats(paymentTypes, days);

  let pillsHtml = `<div class="stat-pill">Days: <span>${imp.total_days}</span></div>`;
  paymentTypes.forEach(pt => {
    pillsHtml += `<div class="stat-pill">${pt}: <span>Rs.${(stats.perType[pt]||0).toLocaleString('en',{minimumFractionDigits:2})}</span></div>`;
  });
  pillsHtml += `<div class="stat-pill">Grand Total: <span>Rs.${stats.grandTotal.toLocaleString('en',{minimumFractionDigits:2})}</span></div>`;

  const wrap = document.createElement('div');
  wrap.innerHTML = `
    <div class="preview-header">
      <h3 style="margin:0;font-size:15px;font-weight:700;color:#111827;">
        <i class="fa-solid fa-table-list" style="color:#0e7490;margin-right:6px;"></i>Imported Summary — ${monthNames[imp.month]} ${imp.year}
      </h3>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-secondary btn-sm" onclick="exportView()"><i class="fa-solid fa-file-excel"></i> Export</button>
        <button class="btn btn-red btn-sm" onclick="deleteImport(${imp.id})"><i class="fa-solid fa-trash"></i> Delete Import</button>
      </div>
    </div>
    <div class="meta-row">
      ${imp.location ? `<span><b>Location:</b> ${imp.location}</span>` : ''}
      <span><b>File:</b> ${imp.filename}</span>
      <span><b>Imported:</b> ${imp.imported_at}</span>
      ${imp.note ? `<span><b>Note:</b> ${imp.note}</span>` : ''}
    </div>
    <div class="stat-pills" style="margin-bottom:16px;">${pillsHtml}</div>
    <div id="viewTableHolder"></div>
  `;
  const area = document.getElementById('workArea');
  area.innerHTML = '';
  area.appendChild(wrap);
  document.getElementById('viewTableHolder').innerHTML = buildSummaryTable(paymentTypes, days, stats);

  // stash for export
  window.__currentView = { imp, days, paymentTypes };
}

function deleteImport(id){
  if (!confirm('Delete this month\'s imported summary? This cannot be undone.')) return;

  document.getElementById('progressSection').style.display = 'block';
  setProgress(40, 'Deleting import…');

  fetch(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'delete_import', id })
  })
  .then(r => r.json())
  .then(res => {
    document.getElementById('progressSection').style.display = 'none';
    if (res.success) {
      showMsg('ok', '<i class="fa-solid fa-circle-check"></i> Import deleted.');
      loadPeriod();
    } else {
      showMsg('err', 'Delete failed: ' + (res.message || 'Unknown error'));
    }
  })
  .catch(err => {
    document.getElementById('progressSection').style.display = 'none';
    showMsg('err', 'Error: ' + err.message);
  });
}

function exportView(){
  const v = window.__currentView;
  if (!v) return;
  const { paymentTypes, days, imp } = v;
  const header = ['Date', ...paymentTypes, 'Total'];
  const rows = days.map(d => [d.date, ...paymentTypes.map(pt => d.payments[pt] || 0), d.total]);
  const ws = XLSX.utils.aoa_to_sheet([header, ...rows]);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Summary');
  XLSX.writeFile(wb, `monthly_summary_${imp.year}_${String(imp.month).padStart(2,'0')}.xlsx`);
}

/* ─────────────────────────────────────────────────
   IMPORT MODE — no import yet for this period
───────────────────────────────────────────────── */
function renderImportMode(){
  const area = document.getElementById('workArea');
  area.innerHTML = `
    <div class="upload-card" id="uploadZone" onclick="document.getElementById('fileInput').click()">
      <div class="icon"><i class="fa-solid fa-file-excel"></i></div>
      <h3>Drop or Click to Select Excel File</h3>
      <p>Daily Sales Summary (.xlsx) for ${monthNames[selectedMonth]} ${selectedYear}</p>
      <input type="file" id="fileInput" accept=".xlsx,.xls">
    </div>
    <div class="form-card" id="optionsCard" style="display:none;">
      <div class="form-row">
        <div class="form-group">
          <label><i class="fa-solid fa-note-sticky"></i> Import Note (Optional)</label>
          <input type="text" id="importNote" placeholder="e.g. July 2025 Daily Sales Summary">
        </div>
      </div>
      <button class="btn btn-teal" id="previewBtn" onclick="runPreview()">
        <i class="fa-solid fa-eye"></i> Parse & Preview
      </button>
    </div>
    <div id="previewSection" style="display:none;"></div>
  `;

  const zone = document.getElementById('uploadZone');
  zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('drag'));
  zone.addEventListener('drop', e => { e.preventDefault(); zone.classList.remove('drag'); handleFile(e.dataTransfer.files[0]); });
  document.getElementById('fileInput').addEventListener('change', e => handleFile(e.target.files[0]));
}

function handleFile(file){
  if (!file) return;
  pendingFile = file;
  const zone = document.getElementById('uploadZone');
  zone.innerHTML = `<div class="icon"><i class="fa-solid fa-file-excel" style="color:#22c55e;"></i></div>
    <h3>${file.name}</h3>
    <p>File selected — click Parse & Preview</p>
    <input type="file" id="fileInput" accept=".xlsx,.xls">`;
  document.getElementById('fileInput').addEventListener('change', e => handleFile(e.target.files[0]));
  document.getElementById('optionsCard').style.display = 'block';
  document.getElementById('previewSection').style.display = 'none';
  parsedSummary = null;
  readExcel(file);
}

function readExcel(file){
  const reader = new FileReader();
  reader.onload = e => {
    try {
      const wb = XLSX.read(e.target.result, { type: 'array', cellDates: false });
      const ws = wb.Sheets[wb.SheetNames[0]];
      const raw = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '', raw: true });
      parsedSummary = parseMonthlySummary(raw);
    } catch (err) {
      showMsg('err', '<i class="fa-solid fa-triangle-exclamation"></i> Failed to read file: ' + err.message);
    }
  };
  reader.readAsArrayBuffer(file);
}

/* ─────────────────────────────────────────────────
   Excel serial → YYYY-MM-DD
───────────────────────────────────────────────── */
function excelSerialToDate(serial){
  if (!serial && serial !== 0) return '';
  const n = Number(serial);
  if (isNaN(n) || n < 1) return '';
  const ms = (n - 25569) * 86400000;
  const d = new Date(ms);
  if (isNaN(d)) return '';
  const y = d.getUTCFullYear();
  const mo = String(d.getUTCMonth() + 1).padStart(2, '0');
  const dy = String(d.getUTCDate()).padStart(2, '0');
  return `${y}-${mo}-${dy}`;
}

function parseAnyDate(v){
  if (v === null || v === undefined || v === '') return '';
  if (typeof v === 'number') {
    if (v < 1) return '';
    return excelSerialToDate(v);
  }
  const s = String(v).trim();
  let m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
  if (m) {
    const dd = m[1].padStart(2, '0'), mo = m[2].padStart(2, '0'), yy = m[3];
    return `${yy}-${mo}-${dd}`;
  }
  m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (m) return s.slice(0, 10);
  return '';
}

/* ─────────────────────────────────────────────────
   Parser — Daily Sales Summary format:
     Header block: company / "Daily Sales Summary" / Location row
     Header row: blank, blank, <PaymentType1>, <PaymentType2>... , Total
     Data rows:  date, location, amt1, amt2, ..., dayTotal
     Footer row: blank, blank, sumAmt1, sumAmt2, ..., grandTotal
───────────────────────────────────────────────── */
function parseMonthlySummary(rows){
  const str = v => (v === null || v === undefined) ? '' : String(v).trim();
  let location = '';
  let headerIdx = -1, headerRow = null, totalColIdx = -1;

  for (let i = 0; i < rows.length; i++) {
    const row = rows[i];
    const c0 = str(row[0]);

    if (!location && c0.toLowerCase() === 'location') {
      location = str(row[1]);
      continue;
    }

    if (headerIdx === -1 && c0 === '' && str(row[1]) === '') {
      let tIdx = -1;
      for (let k = 2; k < row.length; k++) {
        if (str(row[k]).toLowerCase() === 'total') { tIdx = k; break; }
      }
      if (tIdx > 1) {
        let hasLabel = false;
        for (let k = 2; k < tIdx; k++) { if (str(row[k]) !== '') hasLabel = true; }
        if (hasLabel) { headerIdx = i; headerRow = row; totalColIdx = tIdx; }
      }
    }
  }

  if (headerIdx === -1) {
    return { error: 'Could not detect the payment-type header row (looking for columns ending in "Total").', location, paymentTypes: [], days: [], warnings: [] };
  }

  const paymentTypes = [];
  for (let k = 2; k < totalColIdx; k++) {
    const label = str(headerRow[k]);
    if (label) paymentTypes.push(label);
  }

  const days = [];
  const warnings = [];
  let mismatchCount = 0;

  for (let i = headerIdx + 1; i < rows.length; i++) {
    const row = rows[i];
    const dstr = str(row[0]);
    if (dstr === '') continue; // blank / footer total row

    const idate = parseAnyDate(row[0]);
    if (!idate) continue; // not a parseable date — skip defensively

    const payments = {};
    let sumCheck = 0;
    paymentTypes.forEach((pt, k) => {
      const v = parseFloat(row[2 + k]) || 0;
      payments[pt] = v;
      sumCheck += v;
    });

    const totalRaw = parseFloat(row[totalColIdx]);
    const total = isNaN(totalRaw) ? sumCheck : totalRaw;

    days.push({ date: idate, payments, total });
  }

  if (days.length === 0) warnings.push('No daily rows could be parsed from this file.');

  return { location, paymentTypes, days, warnings };
}

/* ─────────────────────────────────────────────────
   Stats
───────────────────────────────────────────────── */
function computeStats(paymentTypes, days){
  const perType = {};
  paymentTypes.forEach(pt => perType[pt] = 0);
  let grandTotal = 0;
  days.forEach(d => {
    paymentTypes.forEach(pt => perType[pt] += (d.payments[pt] || 0));
    grandTotal += d.total;
  });
  return { perType, grandTotal };
}

function buildSummaryTable(paymentTypes, days, stats){
  const fmt = n => Number(n || 0).toLocaleString('en', { minimumFractionDigits: 2 });
  const expectedYM = (selectedYear && selectedMonth) ? `${selectedYear}-${String(selectedMonth).padStart(2,'0')}` : null;

  const rows = days.map(d => {
    const mismatch = expectedYM && d.date.slice(0,7) !== expectedYM;
    const tds = paymentTypes.map(pt => `<td>${fmt(d.payments[pt])}</td>`).join('');
    return `<tr class="${mismatch ? 'mismatch' : ''}">
      <td>${d.date}${mismatch ? ' <i class="fa-solid fa-triangle-exclamation" style="color:#ca8a04;" title="Date outside selected month"></i>' : ''}</td>
      ${tds}
      <td style="font-weight:700;color:#0e7490;">${fmt(d.total)}</td>
    </tr>`;
  }).join('');

  const footTds = paymentTypes.map(pt => `<td>${fmt(stats.perType[pt])}</td>`).join('');

  return `
    <div class="summary-table-wrap">
      <table class="summary-table">
        <thead><tr>
          <th>Date</th>
          ${paymentTypes.map(pt => `<th>${pt}</th>`).join('')}
          <th>Total</th>
        </tr></thead>
        <tbody>${rows}</tbody>
        <tfoot><tr>
          <td>TOTALS (${days.length} days)</td>
          ${footTds}
          <td>${fmt(stats.grandTotal)}</td>
        </tr></tfoot>
      </table>
    </div>
  `;
}

/* ─────────────────────────────────────────────────
   Preview renderer
───────────────────────────────────────────────── */
function runPreview(){
  if (!parsedSummary) { alert('Please select a file first.'); return; }
  if (parsedSummary.error) {
    showMsg('err', '<i class="fa-solid fa-triangle-exclamation"></i> ' + parsedSummary.error);
    return;
  }

  const { location, paymentTypes, days, warnings } = parsedSummary;
  const stats = computeStats(paymentTypes, days);

  const mismatchCount = days.filter(d => d.date.slice(0,7) !== `${selectedYear}-${String(selectedMonth).padStart(2,'0')}`).length;
  const allWarnings = [...warnings];
  if (mismatchCount > 0) allWarnings.push(`${mismatchCount} row(s) have a date outside ${monthNames[selectedMonth]} ${selectedYear} — they're highlighted below.`);

  let pillsHtml = `<div class="stat-pill">Days: <span>${days.length}</span></div>`;
  paymentTypes.forEach(pt => {
    pillsHtml += `<div class="stat-pill">${pt}: <span>Rs.${stats.perType[pt].toLocaleString('en',{minimumFractionDigits:2})}</span></div>`;
  });
  pillsHtml += `<div class="stat-pill">Grand Total: <span>Rs.${stats.grandTotal.toLocaleString('en',{minimumFractionDigits:2})}</span></div>`;

  const section = document.getElementById('previewSection');
  section.innerHTML = `
    <div class="preview-header">
      <h3 style="margin:0;font-size:15px;font-weight:700;color:#111827;">
        <i class="fa-solid fa-eye" style="color:#0e7490;margin-right:6px;"></i>Import Preview — ${monthNames[selectedMonth]} ${selectedYear}
      </h3>
      <div class="stat-pills">${pillsHtml}</div>
    </div>
    ${allWarnings.length ? `<div class="parse-warning"><i class="fa-solid fa-triangle-exclamation"></i> ${allWarnings.join(' &nbsp;|&nbsp; ')}</div>` : ''}
    ${location ? `<div class="meta-row"><span><b>Location:</b> ${location}</span></div>` : ''}
    ${buildSummaryTable(paymentTypes, days, stats)}
    <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap;">
      <button class="btn btn-green" id="importBtn" onclick="doImport()"><i class="fa-solid fa-upload"></i> Confirm & Import</button>
      <button class="btn btn-secondary" onclick="cancelPreview()"><i class="fa-solid fa-xmark"></i> Cancel</button>
    </div>
  `;
  section.style.display = 'block';
  section.scrollIntoView({ behavior: 'smooth' });
}

function cancelPreview(){
  document.getElementById('previewSection').style.display = 'none';
  document.getElementById('previewSection').innerHTML = '';
  renderImportMode();
}

/* ─────────────────────────────────────────────────
   Import
───────────────────────────────────────────────── */
function doImport(){
  if (!parsedSummary || !pendingFile) return;
  document.getElementById('previewSection').style.display = 'none';
  document.getElementById('progressSection').style.display = 'block';
  document.getElementById('progressSection').scrollIntoView({ behavior: 'smooth' });

  const note = document.getElementById('importNote').value.trim();

  const payload = {
    action:         'save_import',
    year:           selectedYear,
    month:          selectedMonth,
    filename:       pendingFile.name,
    note,
    location:       parsedSummary.location,
    payment_types:  parsedSummary.paymentTypes,
    days:           parsedSummary.days
  };

  setProgress(15, 'Sending data to server…');

  fetch(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  .then(r => r.json())
  .then(res => {
    setProgress(100, 'Done!');
    setTimeout(() => {
      document.getElementById('progressSection').style.display = 'none';
      if (res.success) {
        showMsg('ok', `<i class="fa-solid fa-circle-check"></i> Imported <strong>${res.days}</strong> day(s), total <strong>Rs.${Number(res.total_amount).toLocaleString('en',{minimumFractionDigits:2})}</strong> for ${monthNames[selectedMonth]} ${selectedYear}.`);
        parsedSummary = null; pendingFile = null;
        loadPeriod();
      } else {
        showMsg('err', `<i class="fa-solid fa-triangle-exclamation"></i> Import failed: ${res.message || 'Unknown error'}`);
        renderImportMode();
      }
    }, 500);
  })
  .catch(err => {
    document.getElementById('progressSection').style.display = 'none';
    showMsg('err', `<i class="fa-solid fa-triangle-exclamation"></i> Error: ${err.message}`);
    renderImportMode();
  });
}

function setProgress(pct, msg){
  document.getElementById('progressBar').style.width = pct + '%';
  document.getElementById('progressMsg').textContent = msg;
}

function showMsg(type, html){
  const el = document.getElementById('mainMsg');
  el.innerHTML = `<div class="alert alert-${type === 'ok' ? 'ok' : 'err'}">${html}</div>`;
  el.scrollIntoView({ behavior: 'smooth' });
}
</script>
<?php include 'footer.php'; ?>
